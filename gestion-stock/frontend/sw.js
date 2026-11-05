// Service worker : met en cache la coque de l'application (HTML, CSS, JS, Three.js, icônes), rien d'autre.
// - L'API n'est jamais interceptée ni stockée : aucune donnée de compte ou de stock sur l'appareil,
//   et aucune modification ne peut être simulée hors connexion.
// - Chaque déploiement change VERSION (voir docker/caddy/Dockerfile) : le navigateur détecte alors une
//   mise à jour, qui attend l'accord de l'utilisateur (message SKIP_WAITING) pour ne pas couper un formulaire.

const VERSION = '__APP_VERSION__';
const CACHE = `shell-${VERSION}`;
const PRECACHE = [/*__PRECACHE__*/];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(['/', ...PRECACHE])));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith('shell-') && name !== CACHE).map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api/')) return;

  // Toutes les pages sont la même application : coque en cache, donc même version que ses fichiers
  if (request.mode === 'navigate') {
    event.respondWith(caches.match('/').then((cached) => cached ?? fetch(request)));
    return;
  }
  event.respondWith(caches.match(request, { ignoreSearch: true }).then((cached) => cached ?? fetch(request)));
});
