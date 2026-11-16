/**
 * Service worker of the offline scanner: caches the app shell (cache-first, refreshed in the
 * background). API requests (wp-json) are never cached – packages (signed) and the translation
 * table (GET /app/offline/i18n) live in IndexedDB, so both are available offline.
 * Bump CACHE when shipping changed shell files.
 */
'use strict';

var CACHE = 'wpcap-offline-scanner-v10';
var SHELL = [
	'./',
	'index.html',
	'i18n.js',
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
var indexUrl = new URL('index.html', self.registration.scope).href;
var scopeUrl = new URL('./', self.registration.scope).href;

/**
 * Cache key of a request, or '' when it is not part of the app shell. Only navigations to the app
 * itself (the scope root or index.html) map to index.html; other navigations inside the scope (e.g. a
 * stray link to another file) stay network-only, so they can never replace the cached app page.
 */
function cacheKey(req, clean) {
	if (req.mode === 'navigate') { return clean === scopeUrl || clean === indexUrl ? indexUrl : ''; }
	return shellUrls.indexOf(clean) >= 0 ? clean : '';
}

/** Only HTML is stored as the app page. */
function cacheable(res, key) {
	if (!res || !res.ok || res.type !== 'basic') { return false; }
	if (key !== indexUrl && key !== scopeUrl) { return true; }
	return /^text\/html\b/i.test(res.headers.get('Content-Type') || '');
}

self.addEventListener('fetch', function (event) {
	var req = event.request;
	if (req.method !== 'GET') { return; }
	var url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }
	var key = cacheKey(req, url.origin + url.pathname);
	if (!key) { return; } // API and everything else: network only.
	event.respondWith(caches.open(CACHE).then(function (cache) {
		return cache.match(key).then(function (cached) {
			var network = fetch(req).then(function (res) {
				if (cacheable(res, key)) { cache.put(key, res.clone()); }
				return res;
			}).catch(function () { return cached; });
			return cached || network;
		});
	}));
});
