/* ABLET — service worker.
 * Scopo: rendere l'app installabile e avviabile anche con rete debole.
 * Regole: le chiamate /api/ non passano MAI dalla cache (dati personali, stato della sessione deciso dal server);
 * la pagina principale arriva sempre dalla rete, con una pagina «senza connessione» di riserva; i file statici sono serviti dalla cache e aggiornati in background.
 * Nessun messaggio, materiale o audio viene salvato dal service worker.
 */
const PREFIX = 'ablet-' + encodeURIComponent(new URL(self.registration.scope).pathname) + '-';
const VERSION = PREFIX + 'v4-cache-refresh';
const SCOPE = new URL(self.registration.scope);
const PRECACHE = ['assets/icons/icon-192.png', 'assets/icons/apple-touch-icon.png', 'manifest.webmanifest'].map(p => new URL(p, SCOPE).href);

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith(PREFIX) && k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

const OFFLINE = `<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ABLET — senza rete</title><body style="font:20px/1.5 system-ui,sans-serif;background:#fff7ec;color:#1e3432;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px">
<div><div style="font-size:64px" aria-hidden="true">📶</div><h1 style="font-size:28px">Manca la connessione</h1>
<p>Il tuo lavoro è salvato. Quando torna internet, tocca il pulsante.</p>
<button onclick="location.reload()" style="font:inherit;padding:16px 32px;border-radius:999px;border:0;background:#2f6f6a;color:#fff">Riprova</button></div>`;

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== SCOPE.origin || !url.href.startsWith(SCOPE.href)) return;
  const rel = url.pathname.slice(SCOPE.pathname.length);
  if (rel.startsWith('api/') || rel.startsWith('install.php') || rel.startsWith('refresh.php')) return; // sempre e solo rete

  if (req.mode === 'navigate') {
    // Ogni schermata dipende dal server: senza rete si mostra una pagina che lo dice, invece di una copia vuota.
    e.respondWith(fetch(req).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
    return;
  }
  if (rel.startsWith('assets/') || rel === 'manifest.webmanifest') {
    e.respondWith(caches.open(VERSION).then(async c => {
      // Rete prima della cache: evita di mostrare una versione vecchia dopo un aggiornamento.
      try {
        const res = await fetch(req, /\.(js|css)$/.test(url.pathname) ? { cache: 'no-cache' } : undefined);
        if (res.ok) await c.put(req, res.clone());
        return res;
      } catch { return (await c.match(req)) || Response.error(); }
    }));
  }
});
