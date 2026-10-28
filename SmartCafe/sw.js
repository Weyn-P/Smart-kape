/* BesKape Progressive Web App Service Worker */
const CACHE_NAME = 'beskape-pwa-v6';
const OFFLINE_URL = 'offline.html';

const PRECACHE_ASSETS = [
  './',
  'manifest.json',
  'offline.html',
  'pwa.js',
  'logo.png',
  'style.css',
  'responsive.css',
  'icons/icon-192x192.png',
  'icons/icon-512x512.png',
  'icons/icon-maskable-192x192.png',
  'icons/icon-maskable-512x512.png',
  'icons/apple-touch-icon.png',
  'icons/icon-32x32.png',
  'icons/icon-16x16.png'
];

// Install Event: Precaches core assets
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // Use map with individual error handling so one failed asset doesn't fail install
      return Promise.allSettled(
        PRECACHE_ASSETS.map((url) => {
          return cache.add(url).catch((err) => {
            console.warn('[SmartCafe SW] Failed to precache asset:', url, err);
          });
        })
      );
    })
  );
});

// Activate Event: Cleans up obsolete caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('[SmartCafe SW] Removing outdated cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch Event: Intelligent routing and caching
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // 1. Skip non-GET requests (POST forms, login, order submissions)
  if (request.method !== 'GET') {
    return;
  }

  // 1.5. Skip caching for API endpoints and client script to ensure real-time updates
  if (url.pathname.includes('api_get_orders.php') || url.pathname.includes('pwa.js')) {
    event.respondWith(fetch(request).catch(() => caches.match(request)));
    return;
  }

  // 2. Handle HTML Page Navigation Requests (Network First, fallback to cache, then offline.html)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, responseClone);
            });
          }
          return networkResponse;
        })
        .catch(async () => {
          // Device is offline or server unreachable
          const cachedResponse = await caches.match(request);
          if (cachedResponse) {
            return cachedResponse;
          }
          const offlinePage = await caches.match(OFFLINE_URL);
          if (offlinePage) {
            return offlinePage;
          }
          return new Response('Network offline. Please reconnect to use SmartCafe.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain' }
          });
        })
    );
    return;
  }

  // 3. Handle Static Assets (Images, CSS, JS, Fonts) - Stale While Revalidate
  const isStaticAsset =
    request.destination === 'style' ||
    request.destination === 'script' ||
    request.destination === 'image' ||
    request.destination === 'font' ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.jpg') ||
    url.pathname.endsWith('.jpeg') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.woff2');

  if (isStaticAsset) {
    event.respondWith(
      caches.match(request).then((cachedResponse) => {
        const fetchPromise = fetch(request)
          .then((networkResponse) => {
            if (networkResponse && networkResponse.status === 200) {
              const responseClone = networkResponse.clone();
              caches.open(CACHE_NAME).then((cache) => {
                cache.put(request, responseClone);
              });
            }
            return networkResponse;
          })
          .catch(() => {
            // Return cached response if network fails
            return cachedResponse;
          });

        return cachedResponse || fetchPromise;
      })
    );
    return;
  }

  // 4. Default fetch for all other requests
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      return (
        cachedResponse ||
        fetch(request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, responseClone);
            });
          }
          return networkResponse;
        })
      );
    })
  );
});

// Listen for message from client to trigger skipWaiting
self.addEventListener('message', (event) => {
  if (event.data && event.data.action === 'skipWaiting') {
    self.skipWaiting();
  }
});