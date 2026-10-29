/**
 * sw.js : Service Worker de la PWA « Sabre Laser Arbitrage ».
 *
 * Servi à la racine de l'application pour contrôler toutes les pages.
 * Stratégie volontairement prudente (application de score EN DIRECT) :
 *  - Pages PHP et API : TOUJOURS le réseau. Jamais de score, de jeton CSRF ou
 *    de page de compte servi depuis un cache (il serait faux ou périmé).
 *    Si le réseau est coupé pendant une navigation : page « hors connexion ».
 *  - Fichiers statiques (assets/) : cache d'abord, puis réseau. Les URLs sont
 *    versionnées (?v=16, constante ASSETS_VERSION de includes/functions.php) : la changer suffit à forcer
 *    le téléchargement des nouveaux fichiers.
 *
 * Changer VERSION ci-dessous vide les anciens caches au prochain chargement.
 */
const VERSION = 'sabre-v16-1';
const CACHE_STATIQUE = `${VERSION}-statique`;
const SCOPE = self.registration.scope;              // ex. https://sabre.lan/
const OFFLINE_URL = new URL('assets/pwa/offline.html', SCOPE).href;

const PRECACHE = [
    'assets/pwa/offline.html',
    'assets/pwa/offline.js',
    'assets/pwa/icon-192.png',
    'assets/pwa/favicon-32.png',
    'assets/css/style.css?v=16',
    'assets/js/app.js?v=16',
    'assets/js/pwa.js?v=16',
    'assets/fonts/barlow-latin-400-normal.woff2',
    'assets/fonts/barlow-latin-500-normal.woff2',
    'assets/fonts/barlow-latin-600-normal.woff2',
    'assets/fonts/barlow-latin-700-normal.woff2',
    'assets/fonts/barlow-condensed-latin-600-normal.woff2',
    'assets/fonts/barlow-condensed-latin-700-normal.woff2',
    'assets/fonts/barlow-condensed-latin-800-normal.woff2',
].map(p => new URL(p, SCOPE).href);

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_STATIQUE);
        // Un fichier manquant ne doit pas empêcher l'installation
        await Promise.all(PRECACHE.map(url => cache.add(new Request(url, { cache: 'reload' })).catch(() => {})));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const noms = await caches.keys();
        await Promise.all(noms.filter(n => n.startsWith('sabre-') && !n.startsWith(VERSION)).map(n => caches.delete(n)));
        if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();
        await self.clients.claim();
    })());
});

/** Page hors connexion, avec <base> pour que ses liens relatifs pointent vers assets/pwa/. */
async function pageHorsConnexion() {
    const cache = await caches.open(CACHE_STATIQUE);
    const rep = await cache.match(OFFLINE_URL);
    if (!rep) return new Response('Hors connexion', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    const html = (await rep.text()).replace('<head>', `<head><base href="${new URL('assets/pwa/', SCOPE).href}">`);
    return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;                     // POST (votes, formulaires) : jamais interceptés
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Navigation (pages PHP) : réseau uniquement, page de secours si coupure
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                return (await event.preloadResponse) || await fetch(req);
            } catch (e) {
                return pageHorsConnexion();
            }
        })());
        return;
    }

    // Fichiers statiques : cache d'abord
    const assets = new URL('assets/', SCOPE).pathname;
    if (url.pathname.startsWith(assets)) {
        event.respondWith((async () => {
            const cache = await caches.open(CACHE_STATIQUE);
            const enCache = await cache.match(req);
            if (enCache) return enCache;
            const rep = await fetch(req);
            if (rep.ok && rep.type === 'basic') cache.put(req, rep.clone()).catch(() => {});
            return rep;
        })());
    }
    // Tout le reste (api/, *.php appelés en fetch) : comportement normal du navigateur
});
