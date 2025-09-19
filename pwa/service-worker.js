const CACHE_NAME = 'fmc-payments-cache-v2';
const STATIC_CACHE = 'fmc-static-v2';
const DYNAMIC_CACHE = 'fmc-dynamic-v2';

// Static assets to cache
const staticAssets = [
  './index.html',
  './assets/css/style.css',
  './assets/js/app.js',
  './manifest.json',
  './assets/icons/icon-192x192.png',
  './assets/icons/icon-512x512.png',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// Install event - cache static assets
self.addEventListener('install', event => {
  console.log('Service Worker: Installing...');
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => {
        console.log('Service Worker: Caching static assets');
        return cache.addAll(staticAssets);
      })
      .then(() => {
        console.log('Service Worker: Static assets cached');
        self.skipWaiting();
      })
      .catch(error => {
        console.error('Service Worker: Error caching static assets', error);
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  console.log('Service Worker: Activating...');
  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        return Promise.all(
          cacheNames.map(cacheName => {
            if (cacheName !== STATIC_CACHE && cacheName !== DYNAMIC_CACHE) {
              console.log('Service Worker: Deleting old cache', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => {
        console.log('Service Worker: Activated');
        self.clients.claim();
      })
  );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Handle API requests differently
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      // Network first for API calls
      fetch(request)
        .then(response => {
          // Clone response for caching
          const responseClone = response.clone();
          
          // Cache successful API responses
          if (response.status === 200) {
            caches.open(DYNAMIC_CACHE)
              .then(cache => cache.put(request, responseClone));
          }
          
          return response;
        })
        .catch(() => {
          // Fallback to cache if network fails
          return caches.match(request);
        })
    );
  } else {
    // Cache first for static assets
    event.respondWith(
      caches.match(request)
        .then(cachedResponse => {
          if (cachedResponse) {
            return cachedResponse;
          }
          
          // Not in cache, fetch from network
          return fetch(request)
            .then(response => {
              // Don't cache non-successful responses
              if (!response || response.status !== 200 || response.type !== 'basic') {
                return response;
              }
              
              // Clone response for caching
              const responseToCache = response.clone();
              
              // Add to dynamic cache
              caches.open(DYNAMIC_CACHE)
                .then(cache => cache.put(request, responseToCache));
              
              return response;
            });
        })
        .catch(() => {
          // If both cache and network fail, return offline page
          if (request.destination === 'document') {
            return caches.match('./index.html');
          }
        })
    );
  }
});

// Background sync for offline payments
self.addEventListener('sync', event => {
  if (event.tag === 'background-sync-payment') {
    console.log('Service Worker: Background sync for payments');
    event.waitUntil(syncPayments());
  }
});

// Push notifications
self.addEventListener('push', event => {
  if (event.data) {
    const data = event.data.json();
    const options = {
      body: data.body,
      icon: './assets/icons/icon-192x192.png',
      badge: './assets/icons/icon-72x72.png',
      vibrate: [100, 50, 100],
      data: {
        dateOfArrival: Date.now(),
        primaryKey: data.primaryKey || 1
      },
      actions: [
        {
          action: 'explore',
          title: 'View Details',
          icon: './assets/icons/icon-192x192.png'
        },
        {
          action: 'close',
          title: 'Close',
          icon: './assets/icons/icon-192x192.png'
        }
      ]
    };
    
    event.waitUntil(
      self.registration.showNotification(data.title, options)
    );
  }
});

// Notification click handler
self.addEventListener('notificationclick', event => {
  event.notification.close();
  
  if (event.action === 'explore') {
    // Open the app
    event.waitUntil(
      clients.openWindow('./index.html#history')
    );
  }
});

// Sync payments function
async function syncPayments() {
  try {
    // Get pending payments from IndexedDB or localStorage
    // This would sync any payments that were made offline
    console.log('Service Worker: Syncing offline payments');
    
    // Implementation would depend on your offline storage strategy
    // For now, just log that sync was attempted
    return Promise.resolve();
  } catch (error) {
    console.error('Service Worker: Error syncing payments', error);
    return Promise.reject(error);
  }
}
