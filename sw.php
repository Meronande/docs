<?php
/**
 * Service worker endpoint (served as /sw.php).
 * Registering base_url() + '/sw.php' keeps the scope limited to the app even
 * when it is installed in a subfolder like http://localhost/cms/.
 */
require_once __DIR__ . '/config/functions.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: ' . base_url() . '/');
header('Cache-Control: no-cache');

$base = base_url();
?>
'use strict';

const VERSION = 'cms-v1';
const BASE = <?= json_encode($base) ?>;
const OFFLINE_URL = BASE + '/offline.html';

const PRECACHE = [
  OFFLINE_URL,
  BASE + '/manifest.php',
  BASE + '/assets/css/style.css',
  BASE + '/assets/js/app.js',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Only handle requests inside our scope.
  if (!url.pathname.startsWith(BASE + '/')) return;

  // Page navigations: network first, offline page as fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // Same-origin static assets: cache-first, refresh in background.
  if (url.origin === self.location.origin) {
    const isStatic = /\.(css|js|png|jpg|jpeg|webp|gif|svg|ico|woff2?|ttf)$/.test(url.pathname);
    if (!isStatic) return; // HTML/AJAX: always fresh from the network
    event.respondWith(
      caches.match(req).then((cached) => {
        const network = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(VERSION).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => cached);
        return cached || network;
      })
    );
    return;
  }

  // CDN assets: cache-first (versioned URLs, safe to keep).
  if (/cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com/.test(url.hostname)) {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
        }
        return res;
      }))
    );
  }
});
