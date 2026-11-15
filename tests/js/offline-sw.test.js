'use strict';
/*
 * Service worker of the offline scanner (assets/offline-scanner/sw.js): which requests are served from
 * and written to the app-shell cache (audit FE-2: only the app page itself is cached as index.html).
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { flush, ROOT } = require('./lib/load');

const SCOPE = 'https://example.test/wp-content/plugins/pass-captain/assets/offline-scanner/';

/** Runs sw.js with a fake service worker global, Cache Storage and network. */
function worker(network) {
	const store = new Map();
	const cache = {
		match: async (key) => store.get(key),
		put: async (key, res) => { store.set(key, res); },
		addAll: async () => {},
	};
	const listeners = {};
	const self = {
		location: new URL(SCOPE + 'sw.js'),
		registration: { scope: SCOPE },
		addEventListener: (type, fn) => { listeners[type] = fn; },
		skipWaiting: () => Promise.resolve(),
		clients: { claim: () => Promise.resolve() },
	};
	const caches = { open: async () => cache, keys: async () => [], delete: async () => true };
	const fetch = async (req) => network(req.url);
	const src = fs.readFileSync(path.join(ROOT, 'assets/offline-scanner/sw.js'), 'utf8');
	new Function('self', 'caches', 'fetch', src)(self, caches, fetch);
	/** Dispatches a fetch event; resolves to the response or null when the worker did not respond. */
	async function request(url, mode) {
		let responded = null;
		listeners.fetch({ request: { url, method: 'GET', mode: mode || 'no-cors' }, respondWith: (p) => { responded = p; } });
		const res = responded ? await responded : null;
		await flush(5);
		return res;
	}
	return { store, request };
}
function response(body, type) {
	return { ok: true, type: 'basic', body, headers: { get: (h) => ('content-type' === h.toLowerCase() ? type : null) }, clone() { return this; } };
}

test('navigations to other URLs in the scope are not cached as index.html', async () => {
	const w = worker((url) => (url.includes('index.html') ? response('<app>', 'text/html; charset=utf-8') : response('{"x":1}', 'application/json')));
	assert.equal(await w.request(SCOPE + 'other.php', 'navigate'), null, 'network only, not handled by the worker');
	assert.equal(await w.request(SCOPE + 'sub/page', 'navigate'), null);
	assert.equal(w.store.size, 0, 'nothing cached');
	const app = await w.request(SCOPE + 'index.html?api=x', 'navigate');
	assert.equal(app.body, '<app>');
	assert.equal(w.store.get(SCOPE + 'index.html').body, '<app>', 'the app page is cached');
});

test('the app page is only cached when the response is HTML; shell assets stay cached', async () => {
	const w = worker((url) => (url.endsWith('app.js') ? response('js', 'application/javascript') : response('{"error":1}', 'application/json')));
	await w.request(SCOPE, 'navigate');
	assert.equal(w.store.has(SCOPE + 'index.html'), false, 'non-HTML response never becomes the cached app page');
	await w.request(SCOPE + 'app.js');
	assert.equal(w.store.get(SCOPE + 'app.js').body, 'js');
	assert.equal(await w.request('https://example.test/wp-json/wp-pass-captain/v1/app/offline/keys'), null, 'API is network only');
});
