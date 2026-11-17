// Pulizia limitata a questa installazione: nessun cookie, localStorage o dato del server.
export async function clearAppCache(base = '') {
  const scope = new URL(`${base || ''}/`, location.origin);
  const inside = value => { const url = new URL(value); return url.origin === scope.origin && url.pathname.startsWith(scope.pathname); };
  let removed = 0;
  if ('serviceWorker' in navigator) {
    const registrations = await navigator.serviceWorker.getRegistrations();
    for (const registration of registrations) {
      // Non toccare service worker della radice del dominio o di altre applicazioni.
      const worker = registration.active || registration.waiting || registration.installing;
      if (registration.scope === scope.href && worker?.scriptURL === new URL('sw.js', scope).href) await registration.unregister();
    }
  }
  if ('caches' in window) {
    for (const name of await caches.keys()) {
      if (!name.startsWith('ablet-')) continue;
      const cache = await caches.open(name);
      for (const request of await cache.keys()) {
        if (inside(request.url)) { await cache.delete(request); removed++; }
      }
    }
  }
  return { removed, url: scope.href };
}
