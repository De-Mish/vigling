const CACHE_VERSION = 'v2026-10-17a';
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

const PAGE_NETWORK_TIMEOUT_MS = 4000;
const MAX_STATIC_ENTRIES = 150;
const MAX_PAGE_ENTRIES = 30;

// Only public catalogue pages are kept for offline use. Personal pages (cabinet,
// orders, login, registration) are never stored, so they cannot be shown to the
// next person who uses the same device.
const OFFLINE_PAGE_PREFIXES = [
  '/poisk-spetsialistov',
  '/poisk-aktsij',
  '/modeli',
  '/kurs',
  '/kontakty',
  '/privacy-policy',
];

function isOfflinePage(url) {
  if (url.searchParams.has('option') || url.searchParams.has('task') || url.searchParams.has('format')) {
    return false;
  }
  if (url.pathname === '/') {
    return true;
  }
  return OFFLINE_PAGE_PREFIXES.some((prefix) => url.pathname === prefix || url.pathname.startsWith(prefix + '/'));
}

function trimCache(cacheName, maxEntries) {
  return caches.open(cacheName).then((cache) => cache.keys().then((keys) => {
    if (keys.length <= maxEntries) {
      return undefined;
    }
    return Promise.all(keys.slice(0, keys.length - maxEntries).map((key) => cache.delete(key)));
  })).catch(() => undefined);
}

function store(cacheName, request, response, maxEntries) {
  if (!response || !response.ok || response.redirected || response.type !== 'basic') {
    return Promise.resolve();
  }
  const copy = response.clone();
  return caches.open(cacheName)
    .then((cache) => cache.put(request, copy))
    .then(() => trimCache(cacheName, maxEntries))
    .catch(() => undefined);
}

function pageResponse(event, request) {
  const network = fetch(request).then((response) => {
    event.waitUntil(store(RUNTIME_CACHE, request, response, MAX_PAGE_ENTRIES));
    return response;
  });

  return caches.match(request).then((cached) => {
    if (!cached) {
      return network.catch(() => caches.match('/').then((home) => home || Response.error()));
    }
    const timeout = new Promise((resolve) => setTimeout(() => resolve(cached), PAGE_NETWORK_TIMEOUT_MS));
    return Promise.race([network.catch(() => cached), timeout]);
  });
}

function staticResponse(event, request, url) {
  return caches.match(request).then((cached) => {
    const versioned = url.searchParams.has('v') || /\.woff2?$/.test(url.pathname);
    if (cached && versioned) {
      return cached;
    }
    const network = fetch(request).then((response) => {
      event.waitUntil(store(STATIC_CACHE, request, response, MAX_STATIC_ENTRIES));
      return response;
    });
    if (cached) {
      network.catch(() => undefined);
      return cached;
    }
    return network;
  });
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
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
    if (isOfflinePage(url)) {
      event.respondWith(pageResponse(event, request));
    }
    return;
  }

  if (['style', 'script', 'font'].includes(request.destination)) {
    event.respondWith(staticResponse(event, request, url));
  }
});