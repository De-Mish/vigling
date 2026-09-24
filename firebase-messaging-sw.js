const CACHE_VERSION = 'v2026-09-24a';
const STATIC_CACHE = `vigling-static-${CACHE_VERSION}`;
const RUNTIME_CACHE = `vigling-runtime-${CACHE_VERSION}`;

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

// Own the push before Firebase. Otherwise an open app gets only the in-app list:
// Firebase skips the banner, and a second reader of the push body can fail the event.
self.addEventListener('push', (event) => {
  event.stopImmediatePropagation();
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = {};
  }
  const notification = payload.notification || {};
  const data = payload.data || {};
  const title = notification.title || data.title || 'Уведомление';
  const body = notification.body || data.body || '';
  const tag = data.notification_tag || ('vigling-' + Date.now());
  const options = {
    body,
    silent: false,
    renotify: true,
    tag,
    vibrate: [200, 100, 200],
    icon: `${self.location.origin}/icons/vigling-pwa-192.png`,
    data: Object.assign({}, data, { url: data.url || `${self.location.origin}/lk` }),
  };
  event.waitUntil((async () => {
    await self.registration.showNotification(title, options);
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    await Promise.all(windows.map((client) => client.postMessage({
      type: 'vigling-push-sound',
      title,
      body,
    })));
  })());
});

importScripts('/index.php?option=com_pushnotify&task=display.sw&v=20260924a');

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((key) => key.startsWith('vigling-') && key !== STATIC_CACHE && key !== RUNTIME_CACHE)
        .map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const isSameOrigin = url.origin === self.location.origin;

  if (!isSameOrigin) {
    return;
  }

  if (
    url.pathname.startsWith('/administrator') ||
    url.pathname.startsWith('/api') ||
    url.pathname.startsWith('/images/') ||
    url.pathname.startsWith('/icons/') ||
    url.pathname === '/manifest.json' ||
    url.searchParams.get('option') === 'com_ajax'
  ) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => undefined);
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
    );
    return;
  }

  const isStaticAsset = ['style', 'script', 'font'].includes(request.destination);

  if (!isStaticAsset) {
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      const networkFetch = fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => undefined);
          return response;
        })
        .catch(() => cached);

      return cached || networkFetch;
    })
  );
});
