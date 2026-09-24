/*
 * Election Shield service worker.
 * - App shell (CSS, JS, icons, offline page): cache first, for an instant start.
 * - Data pages (dashboard, 25% tracker, collation, PU monitoring): network first, falling back
 *   to the last copy seen; the page itself shows how old its data is.
 * - Nothing else is cached (login, admin pages, anything with phone numbers).
 * - The page cache is cleared on logout.
 * - EC8A photos taken offline wait in IndexedDB ('es-queue' → 'photos',
 *   written by app.js) and are sent on the 'es-photos' Background Sync.
 */
const VERSION = 'v3';
const SHELL = `es-shell-${VERSION}`;
const PAGES = 'es-pages';
const SHELL_FILES = [
  '/offline',
  '/css/app.css',
  '/js/app.js',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-32.png',
];
// Never the incident feed: it shows agents' phone numbers.
const DATA_PAGES = [/^\/$/, /^\/spread$/, /^\/collation(\/.*)?$/, /^\/monitor(\/.*)?$/];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(SHELL_FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith('es-shell-') && key !== SHELL).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'clear-data') {
    event.waitUntil(caches.delete(PAGES));
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(page(request, url));
    return;
  }

  if (/^\/(css|js|icons)\//.test(url.pathname) || url.pathname === '/manifest.webmanifest') {
    event.respondWith(shell(request));
  }
});

async function page(request, url) {
  const cacheable = DATA_PAGES.some((pattern) => pattern.test(url.pathname));

  try {
    const response = await fetch(request);

    // A redirect (to /login) means the session is gone: keep nothing.
    if (cacheable && response.ok && !response.redirected) {
      const cache = await caches.open(PAGES);
      await cache.put(url.pathname, response.clone());
    }

    return response;
  } catch (error) {
    const cached = cacheable ? await caches.match(url.pathname, { cacheName: PAGES }) : undefined;

    return cached || (await caches.match('/offline')) || Response.error();
  }
}

async function shell(request) {
  const cached = await caches.match(request, { ignoreSearch: true });

  if (cached) {
    // Refresh in the background so the next start gets the new version.
    fetch(request).then((response) => response.ok && caches.open(SHELL).then((cache) => cache.put(request, response))).catch(() => {});
    return cached;
  }

  const response = await fetch(request);
  if (response.ok) {
    const cache = await caches.open(SHELL);
    cache.put(request, response.clone());
  }
  return response;
}

self.addEventListener('sync', (event) => {
  if (event.tag === 'es-photos') {
    event.waitUntil(sendQueuedPhotos());
  }
});

function queueDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('es-queue', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('photos', { keyPath: 'id' });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function onStore(db, mode, fn) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction('photos', mode);
    const request = fn(tx.objectStore('photos'));
    tx.oncomplete = () => resolve(request ? request.result : undefined);
    tx.onerror = () => reject(tx.error);
  });
}

// A server error or no connection throws, so the browser retries the sync later.
async function sendQueuedPhotos() {
  const db = await queueDb();
  const items = await onStore(db, 'readonly', (store) => store.getAll());

  for (const item of items || []) {
    const data = new FormData();
    item.fields.forEach(([key, value]) => data.append(key, value));
    data.append('photo', item.blob, item.filename);

    const response = await fetch(item.url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: data });

    if (response.status >= 500) {
      throw new Error('Server error; retry later');
    }

    await onStore(db, 'readwrite', (store) => store.delete(item.id));
  }
}
