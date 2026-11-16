/**
 * A473/A479: the offline scanner PWA on a subdirectory network. The app is served from the plugin
 * URL, which every site of the network shares (one origin, one IndexedDB): each API base gets its
 * own database (pairing, keys, packages, scan log); a pairing of an earlier version keeps its data.
 * Runs app.js in Node with an in-memory IndexedDB, localStorage and fetch.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const SITE_A = 'https://example.test/a/wp-json/pass-captain/v1/app/';
const SITE_B = 'https://example.test/b/wp-json/pass-captain/v1/app/';

function element() {
	return new Proxy(function () {}, {
		get: (target, prop) => (prop === 'classList' ? { add() {}, remove() {}, toggle() {} } : prop === 'style' || prop === 'dataset' ? {} : element()),
		set: () => true,
		apply: () => element(),
	});
}

/** Minimal IndexedDB: databases → stores → Map; requests answer asynchronously like the browser. */
function fakeIndexedDB() {
	const dbs = new Map();
	const later = (fn) => setImmediate(fn);
	function request() { return { result: undefined, error: null, onsuccess: null, onerror: null, onupgradeneeded: null }; }
	function makeDb(name) {
		const data = dbs.get(name);
		return {
			name,
			close() {},
			createObjectStore(store, opts) { data.stores.set(store, { keyPath: opts && opts.keyPath, map: new Map() }); return { createIndex() {} }; },
			transaction(storeNames) {
				const tx = { oncomplete: null, onerror: null, onabort: null, error: null };
				const pending = { n: 0 };
				const finish = () => later(() => { if (pending.n === 0 && tx.oncomplete) tx.oncomplete(); });
				tx.objectStore = (store) => {
					const s = data.stores.get(store);
					const op = (fn) => { const req = request(); pending.n++; later(() => { req.result = fn(); pending.n--; if (req.onsuccess) req.onsuccess(); finish(); }); return req; };
					return {
						get: (key) => op(() => s.map.get(key)),
						getAll: () => op(() => Array.from(s.map.values())),
						put: (value, key) => op(() => { s.map.set(s.keyPath ? value[s.keyPath] : key, value); return key; }),
						delete: (key) => op(() => s.map.delete(key)),
						clear: () => op(() => s.map.clear()),
						index: (field) => ({ getAll: (value) => op(() => Array.from(s.map.values()).filter((v) => v[field] === value)) }),
					};
				};
				finish();
				return tx;
			},
		};
	}
	return {
		dbs,
		open(name) {
			const req = request();
			later(() => {
				const fresh = !dbs.has(name);
				if (fresh) dbs.set(name, { stores: new Map() });
				req.result = makeDb(name);
				if (fresh && req.onupgradeneeded) req.onupgradeneeded();
				if (req.onsuccess) req.onsuccess();
			});
			return req;
		},
		/** Contents of a store, for assertions and to seed an old pairing. */
		store(name, store) { return dbs.has(name) && dbs.get(name).stores.get(store) ? dbs.get(name).stores.get(store).map : null; },
		seed(name, kv) {
			const db = makeDb((dbs.set(name, { stores: new Map() }), name));
			db.createObjectStore('kv'); db.createObjectStore('packages', { keyPath: 'occurrence_id' }); db.createObjectStore('log', { keyPath: 'key' });
			Object.keys(kv).forEach((k) => dbs.get(name).stores.get('kv').map.set(k, kv[k]));
		},
	};
}

function localStore() {
	const map = new Map();
	return { getItem: (k) => (map.has(k) ? map.get(k) : null), setItem: (k, v) => { map.set(k, String(v)); }, removeItem: (k) => { map.delete(k); } };
}

/** Starts the app once (like opening the page) with ?api= (or none); shared device storage. */
async function start(device, apiParam) {
	const window = {
		document: { getElementById: () => element(), querySelector: () => element(), querySelectorAll: () => [], createElement: () => element(), addEventListener() {} },
		navigator: { onLine: true },
		location: { search: apiParam ? '?api=' + encodeURIComponent(apiParam) : '', pathname: '/wp-content/plugins/pass-captain/assets/offline-scanner/index.html', origin: 'https://example.test', href: 'https://example.test/wp-content/plugins/pass-captain/assets/offline-scanner/index.html' },
		history: { replaceState() {} },
		addEventListener() {},
		crypto: require('node:crypto').webcrypto,
		indexedDB: device.idb,
		localStorage: device.ls,
		fetch: async (url, options) => {
			device.requests.push({ url, options });
			if (/\/pair(\?|$)/.test(url)) return { ok: true, status: 200, json: async () => ({ token: 'tok-' + device.requests.length, device_id: 'dev-' + device.requests.length, offline: { keys: [] } }) };
			throw new TypeError('offline');
		},
		TextEncoder, URL, URLSearchParams, Date, Map, Set, JSON, Promise, Uint8Array, Array, String, Math, Error, Object, RegExp, isNaN, Number, TypeError,
		atob: (s) => Buffer.from(s, 'base64').toString('binary'),
		setInterval() {}, setTimeout: (fn) => 0, clearTimeout() {}, console,
	};
	window.window = window;
	window.self = window;
	const ctx = vm.createContext(window);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/vendor/tweetnacl/nacl-fast.min.js'), 'utf8'), ctx);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/offline-scanner/app.js'), 'utf8'), ctx);
	for (let i = 0; i < 40; i++) await new Promise((r) => setImmediate(r));
	return window.wpcapOffline;
}
const flush = async () => { for (let i = 0; i < 40; i++) await new Promise((r) => setImmediate(r)); };
const device = () => ({ idb: fakeIndexedDB(), ls: localStore(), requests: [] });

test('A473: each site of the network gets its own store and pairing', async () => {
	const d = device();
	const a = await start(d, SITE_A);
	assert.strictEqual(a.state.api, SITE_A);
	assert.strictEqual(a.storeName(), 'wpcap-offline-scanner@' + SITE_A);
	await a.pair('ABCD-1234', 'Tor 1');
	await flush();
	assert.ok(d.idb.store('wpcap-offline-scanner@' + SITE_A, 'kv').get('token'), 'site A token stored in its own database');
	// The admin link of site B on the same device: not paired there, site A's token is not used.
	const b = await start(d, SITE_B);
	assert.strictEqual(b.state.api, SITE_B);
	assert.strictEqual(b.state.token, '', 'site B starts unpaired');
	assert.strictEqual(d.idb.store('wpcap-offline-scanner@' + SITE_B, 'kv').get('token'), undefined);
	assert.ok(d.idb.store('wpcap-offline-scanner@' + SITE_A, 'kv').get('token'), 'site A pairing untouched');
	// Back to site A: its pairing is still there.
	const a2 = await start(d, SITE_A);
	assert.strictEqual(a2.state.token, d.idb.store('wpcap-offline-scanner@' + SITE_A, 'kv').get('token'));
});

test('A473: a start without ?api= (home screen) opens the site used last', async () => {
	const d = device();
	await start(d, SITE_A);
	await start(d, SITE_B);
	const plain = await start(d, '');
	assert.strictEqual(plain.state.api, SITE_B);
	assert.strictEqual(plain.storeName(), 'wpcap-offline-scanner@' + SITE_B);
});

test('A473: a pairing QR code of another site pairs in that site\'s store', async () => {
	const d = device();
	const a = await start(d, SITE_A);
	await a.pair(JSON.stringify({ type: 'wpcap-pair', api: SITE_B, code: 'WXYZ-9876' }), 'Tor 2');
	await flush();
	assert.strictEqual(a.state.api, SITE_B);
	assert.ok(d.idb.store('wpcap-offline-scanner@' + SITE_B, 'kv').get('token'));
	assert.strictEqual((d.idb.store('wpcap-offline-scanner@' + SITE_A, 'kv') || new Map()).get('token'), undefined);
	assert.ok(d.requests.some((r) => r.url.indexOf(SITE_B + 'pair') === 0), 'pairing request went to site B');
});

test('A473: the store of an earlier version keeps serving its own site only', async () => {
	const d = device();
	d.idb.seed('wpcap-offline-scanner', { api: SITE_A, token: 'old-token', device_id: 'dev-old' });
	const a = await start(d, SITE_A);
	assert.strictEqual(a.storeName(), 'wpcap-offline-scanner');
	assert.strictEqual(a.state.token, 'old-token');
	const b = await start(d, SITE_B);
	assert.strictEqual(b.state.token, '', 'the old pairing of site A is not used for site B');
	assert.strictEqual(d.idb.store('wpcap-offline-scanner', 'kv').get('api'), SITE_A, 'old store still belongs to site A');
	const plain = await start(d, '');
	assert.strictEqual(plain.state.api, SITE_B);
});
