'use strict';

// Bump this version whenever any offline asset changes. Never cache app pages,
// APIs, media or a visitor's response: Cache Storage does not enforce no-store.
const CACHE_PREFIX = 'vasey-audio-public-offline-';
const CACHE_NAME = `${CACHE_PREFIX}v1`;
const OFFLINE_PAGE = '/offline.html';
const ASSETS = new Map([
  [OFFLINE_PAGE, 'text/html'],
  ['/brand/theme.css', 'text/css'],
  ['/brand/vasey-audio-logo.png', 'image/png'],
  ['/brand/fonts/reddit-sans-latin-wght-normal.woff2', 'font/woff2'],
  ['/brand/fonts/bebas-neue-latin-400-normal.woff2', 'font/woff2'],
]);
const PUBLIC_PATH = /^(?:\/|\/tracks\/[a-z0-9]+(?:-[a-z0-9]+)*|\/(?:about|contact)|\/(?:blog|videos)(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)?)$/;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    // Fetch and validate every anonymous fixed asset before publishing a cache.
    const entries = await Promise.all(Array.from(ASSETS, async ([path, type]) => {
      const url = new URL(path, self.location.origin);
      const request = new Request(url, { credentials: 'omit', cache: 'no-store', redirect: 'error' });
      const response = await fetch(request);
      if (response.status !== 200 || response.redirected || response.type === 'opaque'
        || (response.url && response.url !== url.href)
        || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== type) {
        throw new Error('Offline asset unavailable');
      }
      return [path, response];
    }));
    const cache = await caches.open(CACHE_NAME);
    try {
      for (const [path, response] of entries) await cache.put(path, response);
    } catch (error) {
      await caches.delete(CACHE_NAME);
      throw error;
    }
  })());
  // An update waits for existing clients; it never forces a checkout reload.
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter(name => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
      .map(name => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || request.headers.has('range')) return;

  // Offline assets are exact allowlisted URLs without query, fragment or Range.
  if (ASSETS.has(url.pathname) && !url.search && !url.hash) {
    event.respondWith((async () => {
      try {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(url.pathname);
        if (cached) return cached;
      } catch {
        // Storage denial after installation must not break online brand assets.
      }
      return fetch(request);
    })());
    return;
  }

  if (request.mode !== 'navigate' || request.destination !== 'document' || !PUBLIC_PATH.test(url.pathname)) return;
  event.respondWith((async () => {
    try {
      // Preserve all real responses, including 401/404/429/5xx and redirects.
      return await fetch(request);
    } catch {
      try {
        const cache = await caches.open(CACHE_NAME);
        const fallback = await cache.match(OFFLINE_PAGE);
        if (fallback) return fallback;
      } catch {
        // Cache storage may have been denied or removed since installation.
      }
      return Response.error();
    }
  })());
});
