'use strict';
/*
 * Loads one of the plugin's browser scripts (an IIFE written for the page) in this Node realm with a
 * fake window/document, so tests can drive it. Browser globals are passed as function parameters;
 * everything else (crypto, TextEncoder, atob, URL, Uint8Array, Intl, ...) is Node's own.
 *
 *   const env = load('assets/checkout.js', { window: { WPCAP_CHECKOUT: {...} }, setup(doc, h) {...} });
 *   env.exports   // what the script put on module.exports (test hook), if anything
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { Document, Node, Event, CustomEvent, builder } = require('./dom');

const ROOT = path.resolve(__dirname, '../../..');
const PARAMS = ['window', 'document', 'navigator', 'location', 'history', 'localStorage', 'indexedDB', 'fetch', 'setInterval', 'setTimeout', 'clearTimeout', 'Node', 'Event', 'CustomEvent', 'module', 'console'];
const compiled = new Map();

class MemoryStorage {
	constructor(init) { this._m = new Map(Object.entries(init || {})); }
	get length() { return this._m.size; }
	key(i) { return [...this._m.keys()][i] ?? null; }
	getItem(k) { return this._m.has(k) ? this._m.get(k) : null; }
	setItem(k, v) { this._m.set(k, String(v)); }
	removeItem(k) { this._m.delete(k); }
	clear() { this._m.clear(); }
}

/** Fake timers: nothing runs on its own; tests call env.timers.run() / runAll(). */
function timers() {
	let id = 0;
	const pending = new Map();
	return {
		pending,
		setTimeout(fn, ms) { pending.set(++id, { fn, ms: ms || 0, repeat: false }); return id; },
		setInterval(fn, ms) { pending.set(++id, { fn, ms: ms || 0, repeat: true }); return id; },
		clearTimeout(i) { pending.delete(i); },
		/** Runs the one-shot timers that are due now (repeating ones stay registered). */
		run() { for (const [i, t] of [...pending]) { if (!t.repeat) { pending.delete(i); t.fn(); } } },
	};
}

/** Lets queued promise callbacks (fetch mocks, IndexedDB fakes) settle. */
function flush(rounds) {
	let p = Promise.resolve();
	for (let i = 0; i < (rounds || 20); i++) p = p.then(() => new Promise((r) => setImmediate(r)));
	return p;
}

function load(file, opts) {
	opts = opts || {};
	const abs = path.resolve(ROOT, file);
	if (!compiled.has(abs)) {
		const src = fs.readFileSync(abs, 'utf8');
		compiled.set(abs, vm.runInThisContext('(function (' + PARAMS.join(', ') + ') {' + src + '\n})', { filename: abs }));
	}
	const document = opts.document || new Document();
	const h = builder(document);
	if (opts.setup) opts.setup(document, h);
	const t = timers();
	const location = Object.assign(new URL(opts.url || 'https://example.test/'), {});
	const navigator = Object.assign({ onLine: true, userAgent: 'node' }, opts.navigator || {});
	const localStorage = opts.localStorage || new MemoryStorage(opts.storage);
	const window = Object.assign(new (require('./dom').EventTarget)(), {
		document, navigator, location, localStorage, crypto: globalThis.crypto,
		setTimeout: t.setTimeout, setInterval: t.setInterval, clearTimeout: t.clearTimeout,
		requestAnimationFrame: () => 0, confirm: () => true, CustomEvent, Event,
		history: { replaceState() {} },
	}, opts.window || {});
	window.window = window;
	document.defaultView = window;
	const fetch = opts.fetch || window.fetch || (() => Promise.reject(new Error('fetch not mocked')));
	window.fetch = fetch;
	const mod = { exports: {} };
	compiled.get(abs).call(window, window, document, navigator, location, window.history, localStorage, opts.indexedDB || window.indexedDB, fetch, t.setInterval, t.setTimeout, t.clearTimeout, Node, Event, CustomEvent, mod, opts.console || console);
	return { exports: mod.exports, window, document, h, timers: t, localStorage, navigator };
}

/** fetch mock: records calls; responder(url, init, body) returns {status, json} or throws (network error). */
function mockFetch(responder) {
	const calls = [];
	const fn = async (url, init) => {
		// JSON bodies are parsed; form-encoded bodies (admin-ajax) become an object of their fields.
		const raw = init && init.body ? String(init.body) : '';
		let body = null;
		if (raw) { try { body = JSON.parse(raw); } catch (e) { body = Object.fromEntries(new URLSearchParams(raw)); } }
		calls.push({ url: String(url), init: init || {}, body });
		const r = await responder(String(url), init || {}, body);
		const status = r.status || 200;
		const head = r.headers || {};
		return { ok: status >= 200 && status < 300, status, headers: { get: (k) => head[k] ?? head[String(k).toLowerCase()] ?? null }, json: async () => (r.json === undefined ? {} : r.json) };
	};
	fn.calls = calls;
	return fn;
}

module.exports = { load, flush, mockFetch, MemoryStorage, Event, CustomEvent, ROOT };
