<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; frame-ancestors 'none'; base-uri 'none'");
$dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/refresh.php'));
$base = preg_replace('#/public$#', '', rtrim($dir, '/')) ?? '';
if ($base === '/') $base = '';
$encodedBase = json_encode($base, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
// Stesso codice usato dai test, incorporato senza dipendere da moduli eventualmente in cache.
$cleanup = str_replace('export async function', 'async function', (string)file_get_contents(__DIR__ . '/assets/cache-tools.js'));
?>
<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ABLET — Aggiorna app e svuota cache</title>
<style nonce="<?= $nonce ?>">body{font:18px/1.6 system-ui,sans-serif;background:#fff7ec;color:#1e3432;margin:0;padding:24px}main{max-width:620px;margin:8vh auto}button,a{font:inherit}button{border:0;border-radius:14px;padding:16px 24px;background:#2f6f6a;color:white;cursor:pointer}button:disabled{opacity:.6}#status{min-height:3em}a{color:#215a55}</style>
<main><h1>Aggiorna app e svuota cache</h1>
<p>Scarica di nuovo l’interfaccia di questa installazione di ABLET. Studenti, protocolli, attività e sessioni salvate restano invariati, così come le credenziali di accesso.</p>
<p>Prima di continuare, salva eventuali testi o modifiche ancora non inviati. La pulizia riguarda soltanto questo browser e questo dispositivo.</p>
<button id="refresh" type="button">Aggiorna app e svuota cache</button>
<p id="status" role="status" aria-live="polite"></p>
<p><a href="<?= htmlspecialchars(($base ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">Torna ad ABLET</a></p></main>
<script nonce="<?= $nonce ?>" type="module">
<?= $cleanup ?>
const button = document.getElementById('refresh'), status = document.getElementById('status');
button.addEventListener('click', async () => {
  button.disabled = true; status.textContent = 'Aggiornamento in corso…';
  if (!navigator.onLine) { status.textContent = 'Manca la connessione: collegati a internet e riprova.'; button.disabled = false; return; }
  try {
    const result = await clearAppCache(<?= $encodedBase ?>);
    const url = new URL(result.url); url.searchParams.set('refresh', String(Date.now()));
    status.textContent = 'Cache ripulita. Riapro ABLET…';
    location.replace(url.href);
  } catch {
    status.textContent = 'Pulizia non completata. Chiudi le altre schede di ABLET e riprova. Se il browser blocca la pulizia, elimina manualmente i file nella cache dalle sue impostazioni.';
    button.disabled = false;
  }
});
</script></html>
