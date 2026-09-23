importScripts('/index.php?option=com_pushnotify&task=display.sw&v=20260923b');

const CACHE_VERSION = 'v2026-09-23b';
const STATIC_CACHE = `vigling-static-${CACHE_VERSION}`;
const RUNTIME_CACHE = `vigling-runtime-${CACHE_VERSION}`;

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

// Firebase does not show a system notification while this app is on screen.
// The in-app list is a separate inbox, so show the banner from the push itself.
self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    return;
  }
  const notification = payload.notification || {};
  const data = payload.data || {};
  const title = notification.title || data.title || '';
  const body = notification.body || data.body || '';
  if (!title && !body) {
    return;
  }
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const visible = windows.some((client) => client.visibilityState === 'visible');
    if (!visible && (notification.title || notification.body)) {
      return;
    }
    const options = {
      body,
      silent: false,
      icon: `${self.location.origin}/icons/vigling-pwa-192.png`,
      data: Object.assign({}, data, { url: data.url || `${self.location.origin}/lk` }),
    };
    if (data.notification_tag) {
      options.tag = data.notification_tag;
      options.renotify = true;
    }
    await self.registration.showNotification(title || 'Уведомление', options);
  })());
});

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
