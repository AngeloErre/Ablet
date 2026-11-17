<?php
declare(strict_types=1);

namespace Ablet\Support;

use PDO;

/** Operazioni di prima configurazione condivise da riga di comando e installatore web. */
final class Setup
{
    /** Requisiti dell'ambiente. @return array<int,array{label:string,ok:bool,detail:string,blocking:bool}> */
    public static function checks(): array
    {
        $ext = static fn(string $e) => extension_loaded($e);
        $out = [
            ['label' => 'PHP 8.1 o successivo', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => 'versione in uso: ' . PHP_VERSION, 'blocking' => true],
            ['label' => 'Estensione pdo_mysql', 'ok' => $ext('pdo_mysql'), 'detail' => 'accesso al database', 'blocking' => true],
            ['label' => 'Estensione mbstring', 'ok' => $ext('mbstring'), 'detail' => 'testi in italiano', 'blocking' => true],
            ['label' => 'Estensione json', 'ok' => $ext('json'), 'detail' => '', 'blocking' => true],
            ['label' => 'Estensione curl', 'ok' => $ext('curl'), 'detail' => 'chiamate ai provider LLM (senza: solo modalità dimostrativa)', 'blocking' => false],
            ['label' => 'Cifratura (sodium oppure OpenSSL)', 'ok' => Secrets::engine() !== null, 'detail' => 'motore: ' . (Secrets::engine() ?? 'nessuno') . ' — serve per salvare chiavi API', 'blocking' => false],
            ['label' => 'Connessione HTTPS', 'ok' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || PHP_SAPI === 'cli',
                'detail' => 'necessaria per il microfono nel browser e per la sicurezza delle credenziali', 'blocking' => false],
        ];
        return $out;
    }

    /** @return array{ok:bool,version:?string,detail:string} */
    public static function databaseCheck(PDO $pdo): array
    {
        $p = Migrator::profile($pdo);
        $ok = $p['maria'] ? $p['num'] >= 100201 : $p['num'] >= 50605;
        $detail = !$ok ? 'serve MySQL ≥ 5.6.5 o MariaDB ≥ 10.2'
            : ($p['native_json'] && $p['check_constraints'] ? 'compatibile'
            : 'compatibile in modalità ridotta: ' . implode('; ', array_filter([
                $p['native_json'] ? null : 'dati JSON salvati come testo',
                $p['check_constraints'] ? null : 'limiti dei valori controllati solo dall\'applicazione',
            ])));
        return ['ok' => $ok, 'version' => $p['version'], 'detail' => $detail];
    }

    public static function hasProfessional(PDO $pdo): bool
    {
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('psychologist','admin')")->fetchColumn() > 0;
        } catch (\PDOException) {
            return false; // tabelle non ancora create
        }
    }

    /** Crea un professionista con provider dimostrativo, rubriche dei template e impostazioni predefinite. Restituisce la password generata. */
    public static function createProfessional(PDO $pdo, string $login, string $alias, string $role = 'psychologist'): string
    {
        $pw = Ids::pseudonym(16);
        Database::transaction(static function (PDO $pdo) use ($login, $alias, $role, $pw): void {
            $pdo->prepare('INSERT INTO users (pseudonym, role, login, password_hash, display_alias) VALUES (?,?,?,?,?)')
                ->execute([Ids::pseudonym(), $role, $login, password_hash($pw, PASSWORD_DEFAULT), $alias]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO provider_configs (owner_id, provider_key, label, is_default) VALUES (?, 'demo', 'Modalità dimostrativa (risposte simulate)', 1)")->execute([$id]);
            foreach (['pianificazione_autoregolazione' => 'osservazione', 'comprensione_testo' => 'prestazione'] as $tpl => $purpose) {
                $cfg = json_decode((string)file_get_contents(ABLET_ROOT . "/resources/templates/$tpl.json"), true);
                $r = $cfg['observation_rubric'];
                $pdo->prepare('INSERT INTO rubrics (owner_id, title, purpose, scale_min, scale_max, criteria_json) VALUES (?,?,?,?,?,?)')
                    ->execute([$id, $r['title'], $purpose, $r['scale']['min'], $r['scale']['max'], json_encode($r['criteria'], JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->prepare('INSERT INTO professional_settings (owner_id, voice, avatar, retention) VALUES (?,?,?,?)')
                ->execute([$id, json_encode(['engine' => 'browser', 'input_enabled' => true, 'output_enabled' => true, 'autoplay' => true, 'language' => 'it-IT', 'rate' => 1.0, 'pitch' => 1.0, 'voice_name' => '', 'store_audio' => false]),
                    json_encode(['enabled' => true, 'name' => 'Ablet', 'style' => 'volto', 'color' => '#2f6f6a']), json_encode(['messages_days' => 365, 'technical_logs_days' => 30])]);
        });
        self::ensureCodebook($pdo);
        return $pw;
    }

    public static function ensureCodebook(PDO $pdo): void
    {
        $cb = (string)file_get_contents(ABLET_ROOT . '/resources/codebook/codebook_v0.1.json');
        $pdo->prepare('INSERT IGNORE INTO codebook_versions (version, content_json) VALUES (?,?)')->execute(['0.1', $cb]);
    }

    /** Chiave che dimostra la titolarità dell'installazione: INSTALL_KEY, oppure la password del database nelle installazioni precedenti. Mai vuota. */
    public static function ownerKeyMatches(string $given): bool
    {
        $key = (string)(Env::get('INSTALL_KEY', '') ?: Env::get('DB_PASS', ''));
        return $key !== '' && hash_equals($key, trim($given));
    }

    /** Contenuto di config.php. I valori sono esportati con var_export: nessuna interpolazione. */
    public static function configFile(array $v): string
    {
        $cfg = [
            'APP_ENV' => 'production',
            'DB_HOST' => $v['DB_HOST'], 'DB_PORT' => $v['DB_PORT'] ?: '3306', 'DB_NAME' => $v['DB_NAME'], 'DB_USER' => $v['DB_USER'], 'DB_PASS' => $v['DB_PASS'],
            'APP_SECRET_KEY' => base64_encode(random_bytes(32)),
            // Prova di titolarità per completare o aggiornare l'installazione (non dipende dalla password del database, che su alcuni hosting è vuota).
            'INSTALL_KEY' => implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4)),
            'LLM_TURN_BUDGET_S' => '50',
        ];
        return "<?php\n// Configurazione di ABLET generata dall'installatore il " . gmdate('Y-m-d H:i') . " UTC.\n"
            . "// Contiene segreti: non condividerla e non pubblicarla in repository.\nreturn " . var_export($cfg, true) . ";\n";
    }
}
