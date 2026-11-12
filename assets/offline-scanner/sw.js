/**
 * Service worker of the offline scanner: caches the app shell (cache-first, refreshed in the
 * background). API requests (wp-json) are never cached – packages live in IndexedDB, signed.
 * Bump CACHE when shipping changed shell files.
 */
'use strict';

var CACHE = 'wpcap-offline-scanner-v1';
var SHELL = [
	'./',
	'index.html',
	'app.js',
	'app.css',
	'manifest.json',
	'icon.svg',
	'icon-192.png',
	'icon-512.png',
	'../vendor/tweetnacl/nacl-fast.min.js',
	'../vendor/jsqr/jsQR.js'
];

self.addEventListener('install', function (event) {
	event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
	event.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (k) { return k.indexOf('wpcap-offline-scanner-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});

var shellUrls = SHELL.map(function (p) { return new URL(p, self.registration.scope).href; });

self.addEventListener('fetch', function (event) {
	var req = event.request;
	if (req.method !== 'GET') { return; }
	var url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }
	var clean = url.origin + url.pathname;
	var isShell = shellUrls.indexOf(clean) >= 0 || (req.mode === 'navigate' && clean.indexOf(self.registration.scope) === 0);
	if (!isShell) { return; } // API and everything else: network only.
	var key = req.mode === 'navigate' ? new URL('index.html', self.registration.scope).href : clean;
	event.respondWith(caches.open(CACHE).then(function (cache) {
		return cache.match(key).then(function (cached) {
			var network = fetch(req).then(function (res) {
				if (res && res.ok && res.type === 'basic') { cache.put(key, res.clone()); }
				return res;
			}).catch(function () { return cached; });
			return cached || network;
		});
	}));
});
