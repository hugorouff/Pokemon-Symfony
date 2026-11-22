const cacheName = 'pokemon-install-v1';
const offlinePage = '/offline.html';

self.addEventListener('install', event => {
    event.waitUntil(caches.open(cacheName).then(cache => cache.add(offlinePage)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
    event.waitUntil(Promise.all([
        self.clients.claim(),
        caches.keys().then(keys => Promise.all(keys
            .filter(key => key.startsWith('pokemon-install-') && key !== cacheName)
            .map(key => caches.delete(key))))
    ]));
});
self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.mode !== 'navigate' || request.method !== 'GET'
        || new URL(request.url).origin !== self.location.origin) return;
    // Only the generic offline page goes into Cache Storage.
    event.respondWith(fetch(request).catch(async () => (await caches.open(cacheName)).match(offlinePage) || Response.error()));
});
