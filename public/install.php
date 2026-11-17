<?php
declare(strict_types=1);

/*
 * ABLET — installatore dal browser, per hosting senza accesso SSH (es. hosting Linux condiviso).
 *  1. senza configurazione: verifica dell'ambiente, dati del database, creazione di config.php, schema, primo professionista;
 *  2. con configurazione ma senza professionisti: richiede la password del database come prova di titolarità;
 *  3. a installazione completata: consente solo di applicare nuove migrazioni dopo un aggiornamento (password del database).
 * Dopo l'installazione il file può essere eliminato; anche se resta, non permette di creare altri account.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Ablet\Support\Database;
use Ablet\Support\Env;
use Ablet\Support\Migrator;
use Ablet\Support\Setup;

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

session_name('ablet_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));

$e = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$msgs = [];      // [tipo, testo]
$result = null;  // HTML del riepilogo finale
$manualConfig = null;

if ($post && !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Richiesta non valida: ricaricare la pagina.');
}

$checks = Setup::checks();
$blocking = array_filter($checks, static fn($c) => $c['blocking'] && !$c['ok']);
$hasConfig = Env::hasConfig();
$pdo = null;
$dbInfo = null;
if ($hasConfig) {
    try {
        $pdo = Database::pdo();
        $dbInfo = Setup::databaseCheck($pdo);
    } catch (Throwable) {
        $msgs[] = ['err', 'La configurazione esiste ma la connessione al database non riesce. Controllare i dati in config.php (o .env).'];
    }
}
$installed = $pdo && Setup::hasProfessional($pdo);

$validLogin = static fn(string $l): bool => (bool)preg_match('/^[A-Za-z0-9._-]{4,60}$/', $l);

/** Schema + professionista (+ eventuali dati dimostrativi). Restituisce il riepilogo HTML. */
$install = static function (PDO $pdo, string $login, string $alias, bool $demo) use ($e): string {
    $r = Migrator::run($pdo, ABLET_ROOT . '/database/migrations');
    $html = '<p>Schema del database: ' . ($r['applied'] ? 'applicate ' . $e(implode(', ', $r['applied'])) : 'già aggiornato') . '.</p>';
    if ($r['skipped_triggers']) {
        $html .= '<p class="warn">L\'utente del database non può creare trigger: l\'immutabilità delle versioni pubblicate è garantita dall\'applicazione e non anche dal database. Il funzionamento non cambia.</p>';
    }
    if ($demo) {
        ob_start();
        require ABLET_ROOT . '/database/seeds/demo.php';
        $out = trim((string)ob_get_clean());
        $html .= '<h3>Dati dimostrativi (sintetici)</h3><pre>' . $e($out) . '</pre>';
    }
    Setup::ensureCodebook($pdo);
    $pw = Setup::createProfessional($pdo, $login, $alias);
    $html .= '<h3>Il tuo account</h3><p>Codice di accesso: <b>' . $e($login) . '</b><br>Password: <b>' . $e($pw) . '</b></p>'
        . '<p class="warn">Annota ora la password: non verrà mostrata di nuovo.</p>';
    if ((string)Env::get('INSTALL_KEY', '') !== '') {
        $html .= '<p>Chiave di installazione: <b>' . $e((string)Env::get('INSTALL_KEY')) . '</b><br><span class="small">Serve per aggiornare il database dopo aver caricato una nuova versione. È salvata anche in config.php (voce INSTALL_KEY).</span></p>';
    }
    return $html;
};

if ($post && !$blocking) {
    $step = (string)($_POST['step'] ?? '');
    $login = trim((string)($_POST['login'] ?? ''));
    $alias = mb_substr(trim((string)($_POST['alias'] ?? '')), 0, 60) ?: $login;
    $demo = !empty($_POST['demo']);
    try {
        if ($step === 'new' && !$hasConfig) {
            $db = ['DB_HOST' => trim((string)$_POST['db_host']), 'DB_PORT' => trim((string)$_POST['db_port']), 'DB_NAME' => trim((string)$_POST['db_name']),
                'DB_USER' => trim((string)$_POST['db_user']), 'DB_PASS' => (string)$_POST['db_pass']];
            if (!$validLogin($login)) {
                throw new DomainException('Codice di accesso non valido: 4–60 caratteri tra lettere, cifre, punto, trattino e trattino basso.');
            }
            try {
                $test = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['DB_HOST'], $db['DB_PORT'] ?: '3306', $db['DB_NAME']), $db['DB_USER'], $db['DB_PASS'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            } catch (PDOException) {
                throw new DomainException('Connessione al database non riuscita: controllare host, nome, utente e password indicati nel pannello di controllo dell\'hosting.');
            }
            $chk = Setup::databaseCheck($test);
            if (!$chk['ok']) {
                throw new DomainException('Database non compatibile (' . $chk['version'] . '): ' . $chk['detail'] . '.');
            }
            if (Setup::hasProfessional($test)) {
                throw new DomainException('Questo database contiene già un\'installazione di ABLET. Ripristinare config.php oppure usare un database vuoto.');
            }
            $content = Setup::configFile($db);
            $target = ABLET_ROOT . '/config.php';
            if (is_file($target) || @file_put_contents($target, $content, LOCK_EX) === false) {
                $manualConfig = $content;
                $msgs[] = ['warn', 'Non è stato possibile scrivere config.php sul server. Crea il file config.php nella cartella principale di ABLET con il contenuto qui sotto (via FTP o File Manager), poi ricarica questa pagina.'];
            } else {
                @chmod($target, 0640);
                Env::loadPhp($target);
                $result = $install(Database::pdo(), $login, $alias, $demo);
            }
        } elseif ($step === 'finish' && $hasConfig && $pdo && !$installed) {
            if (!Setup::ownerKeyMatches((string)($_POST['owner_key'] ?? ''))) {
                sleep(2);
                throw new DomainException('Chiave di installazione non corretta (voce INSTALL_KEY in config.php).');
            }
            if (!$validLogin($login)) {
                throw new DomainException('Codice di accesso non valido: 4–60 caratteri tra lettere, cifre, punto, trattino e trattino basso.');
            }
            $result = $install($pdo, $login, $alias, $demo);
        } elseif ($step === 'upgrade' && $installed) {
            if (!Setup::ownerKeyMatches((string)($_POST['owner_key'] ?? ''))) {
                sleep(2);
                throw new DomainException('Chiave di installazione non corretta (voce INSTALL_KEY in config.php).');
            }
            $r = Migrator::run($pdo, ABLET_ROOT . '/database/migrations');
            $msgs[] = ['ok', $r['applied'] ? 'Migrazioni applicate: ' . implode(', ', $r['applied']) . '.' : 'Il database era già aggiornato.'];
        }
    } catch (DomainException $x) {
        $msgs[] = ['err', $x->getMessage()];
    } catch (Throwable $x) {
        error_log('[ABLET install] ' . $x::class . ' ' . basename($x->getFile()) . ':' . $x->getLine());
        $msgs[] = ['err', 'Installazione non riuscita. Verificare che il database sia vuoto e che l\'utente abbia i permessi di creazione delle tabelle. Dettaglio tecnico nel log degli errori PHP.'];
    }
    $installed = $installed || $result !== null;
}

$base = preg_replace('#/public$#', '', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/')) ?? '';
$csrf = $e($_SESSION['csrf']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installazione di ABLET</title>
<style>
  body { font: 16px/1.55 system-ui, sans-serif; background: #f4f1ea; color: #1c2530; margin: 0; padding: 2rem 1rem; }
  main { max-width: 44rem; margin: 0 auto; }
  section { background: #fffdf8; border: 1px solid #d9d2c2; border-radius: 14px; padding: 1.2rem 1.4rem; margin-bottom: 1rem; }
  h1 { margin: 0 0 .3rem; } h2 { font-size: 1.15rem; margin: 0 0 .6rem; }
  label { display: block; font-weight: 600; margin: .7rem 0 .2rem; font-size: .92rem; }
  input[type=text], input[type=password], input[type=number] { width: 100%; padding: .5rem .6rem; border: 1px solid #c9c1ae; border-radius: 8px; font: inherit; box-sizing: border-box; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
  button { margin-top: 1rem; background: #2f6f6a; color: #fff; border: 0; border-radius: 10px; padding: .65rem 1.2rem; font: inherit; font-weight: 600; cursor: pointer; }
  table { width: 100%; border-collapse: collapse; font-size: .92rem; } td { padding: .35rem .3rem; border-bottom: 1px solid #e6e0d2; vertical-align: top; }
  .ok { color: #1d6b3a; font-weight: 700; } .no { color: #a12a2a; font-weight: 700; } .opt { color: #8a5a00; font-weight: 700; }
  .msg { padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; } .msg.err { background: #fde3e3; } .msg.warn, .warn { background: #fff3cf; padding: .5rem .8rem; border-radius: 8px; } .msg.ok { background: #dcebe8; }
  pre { background: #1c2530; color: #e9edf2; padding: 1rem; border-radius: 10px; overflow: auto; font-size: .85rem; white-space: pre-wrap; }
  .small { font-size: .85rem; color: #4c5866; }
</style>
</head>
<body>
<main>
<h1>Installazione di ABLET</h1>
<p class="small">Per hosting senza accesso SSH. I dati inseriti restano sul tuo server.</p>

<?php foreach ($msgs as [$t, $m]): ?><div class="msg <?= $e($t) ?>" role="alert"><?= $e($m) ?></div><?php endforeach; ?>

<?php if ($result !== null): ?>
<section>
  <h2>Installazione completata</h2>
  <?= $result ?>
  <p><a href="<?= $e($base) ?>/">Vai ad ABLET e accedi</a></p>
  <p class="small">Consigliato: elimina il file <code>public/install.php</code> dal server (non è necessario per il funzionamento). Per collegare un modello linguistico usa la scheda «Provider» dopo l'accesso.</p>
</section>
<?php elseif ($manualConfig !== null): ?>
<section>
  <h2>config.php</h2>
  <pre><?= $e($manualConfig) ?></pre>
</section>
<?php else: ?>

<section>
  <h2>1 · Verifica dell'ambiente</h2>
  <table>
  <?php foreach ($checks as $c): ?>
    <tr><td><?= $e($c['label']) ?></td><td class="<?= $c['ok'] ? 'ok' : ($c['blocking'] ? 'no' : 'opt') ?>"><?= $c['ok'] ? 'sì' : ($c['blocking'] ? 'no — necessario' : 'no — consigliato') ?></td><td class="small"><?= $e($c['detail']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($dbInfo): ?>
    <tr><td>Database</td><td class="<?= $dbInfo['ok'] ? 'ok' : 'no' ?>"><?= $dbInfo['ok'] ? 'sì' : 'no' ?></td><td class="small"><?= $e($dbInfo['version'] . ' — ' . $dbInfo['detail']) ?></td></tr>
  <?php endif; ?>
  </table>
  <?php if ($blocking): ?><p class="warn">Correggi i requisiti mancanti (di solito basta scegliere PHP 8.3 o 8.4 nel pannello dell'hosting) e ricarica la pagina.</p><?php endif; ?>
</section>

<?php if (!$blocking && !$hasConfig): ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="step" value="new">
<section>
  <h2>2 · Database</h2>
  <p class="small">Crea il database dal pannello di controllo dell'hosting e copia qui i dati che il pannello indica.</p>
  <div class="row"><div><label for="h">Host</label><input type="text" id="h" name="db_host" required value="localhost"></div><div><label for="p">Porta</label><input type="number" id="p" name="db_port" value="3306"></div></div>
  <label for="n">Nome del database</label><input type="text" id="n" name="db_name" required>
  <div class="row"><div><label for="u">Utente</label><input type="text" id="u" name="db_user" required></div><div><label for="pw">Password</label><input type="password" id="pw" name="db_pass"></div></div>
  <p class="small">Su AlterVista: host <b>localhost</b>, nome del database <b>my_</b> seguito dal nome del sito, utente = nome del sito, password vuota.</p>
</section>
<section>
  <h2>3 · Il tuo account di professionista</h2>
  <label for="l">Codice di accesso</label><input type="text" id="l" name="login" required pattern="[A-Za-z0-9._\-]{4,60}" placeholder="es. dott.rossi">
  <label for="a">Nome visualizzato</label><input type="text" id="a" name="alias" maxlength="60">
  <label><input type="checkbox" name="demo" value="1"> Carica anche i dati dimostrativi sintetici (studenti, attività e protocolli di prova)</label>
  <p class="small">La password viene generata e mostrata una sola volta alla fine.</p>
  <button type="submit">Installa</button>
</section>
</form>
<?php elseif (!$blocking && $pdo && !$installed): ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="step" value="finish">
<section>
  <h2>2 · Completa l'installazione</h2>
  <p class="small">La configurazione è presente. Per confermare di essere il titolare inserisci la chiave di installazione: è la voce INSTALL_KEY in config.php (nelle installazioni precedenti, la password del database).</p>
  <label for="pw">Chiave di installazione</label><input type="password" id="pw" name="owner_key" required>
  <label for="l">Codice di accesso del tuo account</label><input type="text" id="l" name="login" required pattern="[A-Za-z0-9._\-]{4,60}">
  <label for="a">Nome visualizzato</label><input type="text" id="a" name="alias" maxlength="60">
  <label><input type="checkbox" name="demo" value="1"> Carica anche i dati dimostrativi sintetici</label>
  <button type="submit">Installa</button>
</section>
</form>
<?php elseif ($installed): ?>
<section>
  <h2>ABLET è già installato</h2>
  <p>Per creare altri account usa l'area del professionista. Dopo aver caricato una nuova versione dei file puoi aggiornare il database qui.</p>
  <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="step" value="upgrade">
    <label for="pw">Chiave di installazione (INSTALL_KEY in config.php)</label><input type="password" id="pw" name="owner_key" required>
    <button type="submit">Aggiorna il database</button></form>
  <p><a href="<?= $e($base) ?>/">Vai ad ABLET</a></p>
</section>
<?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
