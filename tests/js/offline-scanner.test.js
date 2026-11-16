/**
 * A174: logic of the offline scanner PWA (assets/offline-scanner/app.js) in Node: code normalisation,
 * signature/format/device checks of signed packages and the offline admission decision.
 * The app is loaded into a sandbox with minimal browser stand-ins; without IndexedDB it stops after
 * exposing window.wpcapOffline, which is all these tests need.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
function element() {
	return new Proxy(function () {}, {
		get: (target, prop) => (prop === 'classList' ? { add() {}, remove() {}, toggle() {} } : prop === 'style' || prop === 'dataset' ? {} : element()),
		set: () => true,
		apply: () => element(),
	});
}
/** Elements by id keep the values set on them (result box, title, detail), everything else is a stand-in. */
function recordingElement() {
	const values = {};
	return new Proxy(function () {}, {
		get: (target, prop) => (prop in values ? values[prop] : prop === 'classList' ? { add() {}, remove() {}, toggle() {} } : prop === 'style' || prop === 'dataset' ? {} : element()),
		set: (target, prop, value) => { values[prop] = value; return true; },
		apply: () => element(),
	});
}
function loadApp(extra) {
	const byId = new Map();
	const window = {
		document: { getElementById: (id) => { if (!byId.has(id)) byId.set(id, recordingElement()); return byId.get(id); }, querySelector: () => element(), querySelectorAll: () => [], createElement: () => element(), addEventListener() {}, documentElement: {}, title: '' },
		navigator: { onLine: true },
		location: { search: '', pathname: '/', origin: 'https://example.test', href: 'https://example.test/' },
		crypto: require('node:crypto').webcrypto,
		TextEncoder, URL, URLSearchParams, Date, Map, Set, JSON, Promise, Uint8Array, Array, String, Math, Error, Object, RegExp, isNaN,
		atob: (s) => Buffer.from(s, 'base64').toString('binary'),
		setInterval() {}, setTimeout() {}, clearTimeout() {}, console,
	};
	Object.assign(window, extra || {});
	window.window = window;
	window.self = window;
	window.globalThis = window;
	const ctx = vm.createContext(window);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/vendor/tweetnacl/nacl-fast.min.js'), 'utf8'), ctx);
	if (window.withI18n) { vm.runInContext(fs.readFileSync(path.join(root, 'assets/offline-scanner/i18n.js'), 'utf8'), ctx); }
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/offline-scanner/app.js'), 'utf8'), ctx);
	assert.ok(window.wpcapOffline, 'app exposes window.wpcapOffline');
	return window;
}

const w = loadApp();
const nacl = w.nacl;
const app = w.wpcapOffline;
const keyPair = nacl.sign.keyPair();
const b64 = (bytes) => Buffer.from(bytes).toString('base64');
function sign(payload, keyId = 'k1') {
	const text = JSON.stringify(payload);
	return { key_id: keyId, payload: text, signature: b64(nacl.sign.detached(new TextEncoder().encode(text), keyPair.secretKey)) };
}
app.state.keys = { k1: { public_key: b64(keyPair.publicKey), status: 'active' } };
app.state.deviceId = 'dev-1';
const base = { format: 'wpcap-offline-package', v: 1, key_id: 'k1', device_id: 'dev-1', kind: 'full', occurrence: { id: 7 }, cursor: 'c1' };

test('normalizeCode keeps the first 64-hex run, lowercase', () => {
	const code = 'A'.repeat(64);
	assert.strictEqual(app.normalizeCode('https://x.test/?wpcap_ticket=' + code + '&x=1'), 'a'.repeat(64));
	assert.strictEqual(app.normalizeCode('too-short-abc'), '');
});

test('verifyDoc accepts a correctly signed package for this device', () => {
	assert.strictEqual(app.verifyDoc(sign(base)).occurrence.id, 7);
});

test('verifyDoc rejects a tampered payload', () => {
	const doc = sign(base);
	doc.payload = doc.payload.replace('"id":7', '"id":8');
	assert.throws(() => app.verifyDoc(doc), /Signatur ungültig/);
});

test('verifyDoc rejects unknown keys, other devices, wrong format and expired retired keys', () => {
	assert.throws(() => app.verifyDoc(sign(base, 'nope')), /Unbekannter Signaturschlüssel/);
	assert.throws(() => app.verifyDoc(sign({ ...base, device_id: 'dev-2' })), /anderen Gerät/);
	assert.throws(() => app.verifyDoc(sign({ ...base, format: 'other' })), /Paketformat/);
	app.state.keys.k2 = { public_key: b64(keyPair.publicKey), status: 'retired', valid_until: '2000-01-01T00:00:00Z' };
	assert.throws(() => app.verifyDoc(sign({ ...base, key_id: 'k2' }, 'k2')), /abgelaufen/);
});

function entry(tickets, extra = {}) {
	const meta = { expires_at: new Date(Date.now() + 3600e3).toISOString(), ticket_types: [ { id: 1, multi: false, areas: [] }, { id: 2, multi: true, areas: [] }, { id: 3, multi: false, areas: [ 5 ] } ], occurrence: { id: 7 }, ...extra };
	return { record: { occurrence_id: 7 }, index: new Map(tickets.map((t) => [ t.h, t ])), meta, error: '' };
}

test('decideOffline: valid ticket is admitted, unknown and revoked are refused', () => {
	const e = entry([ { h: 'a', t: 1, s: 'valid' }, { h: 'r', t: 1, s: 'revoked' } ]);
	assert.strictEqual(app.decideOffline(e, 'a').result, 'checked_in');
	assert.strictEqual(app.decideOffline(e, 'x').result, 'unknown');
	assert.strictEqual(app.decideOffline(e, 'r').result, 'revoked');
});

test('decideOffline: second admission on this device is refused unless the type allows multiple entries', () => {
	const e = entry([ { h: 'once', t: 1, s: 'valid' }, { h: 'multi', t: 2, s: 'valid' }, { h: 'used', t: 1, s: 'checked_in' } ]);
	app.state.admitted[7] = new Set([ 'once', 'multi' ]);
	assert.strictEqual(app.decideOffline(e, 'once').result, 'already_checked_in');
	assert.strictEqual(app.decideOffline(e, 'used').result, 'already_checked_in');
	assert.strictEqual(app.decideOffline(e, 'multi').result, 'checked_in');
	delete app.state.admitted[7];
});

test('decideOffline: area restriction, doors-open time and expired package', () => {
	const area = entry([ { h: 'z', t: 3, s: 'valid' } ]);
	assert.strictEqual(app.decideOffline(area, 'z').result, 'wrong_area');
	const early = entry([ { h: 'a', t: 1, s: 'valid' } ], { occurrence: { id: 7, doors_open_at: new Date(Date.now() + 600e3).toISOString() } });
	assert.strictEqual(app.decideOffline(early, 'a').result, 'outside_entry_window');
	const old = entry([ { h: 'a', t: 1, s: 'valid' } ], { expires_at: new Date(Date.now() - 1000).toISOString() });
	assert.strictEqual(app.decideOffline(old, 'a').result, 'package_expired');
});

/* A271/A315/A539: classification of online answers, codes typed behind each other, sliding window. */

test('scanCode/normalizeCode: concatenated or two different codes give no code (A271)', () => {
	const a = 'a'.repeat(64);
	const b = 'b'.repeat(64);
	assert.strictEqual(app.normalizeCode(a + b), '');
	assert.strictEqual(app.normalizeCode(a + ' ' + b), '');
	assert.strictEqual(app.scanCode(a + b).ambiguous, true);
	assert.strictEqual(app.normalizeCode(a + ' ' + a), a);
	assert.strictEqual(app.scanCode('K7QM-4XWA').ambiguous, false);
});

test('classifyScan: only plugin results are ok/bad, everything else a warning or the offline decision (A315)', () => {
	assert.strictEqual(app.classifyScan({ ok: true, result: 'checked_in' }, null), 'ok');
	assert.strictEqual(app.classifyScan({ ok: false, result: 'already_checked_in', code: 'wpcap_ticket_already_checked_in' }, null), 'bad');
	assert.strictEqual(app.classifyScan({ ok: false, result: 'error', code: 'wpcap_checkin_retry' }, null), 'warn');
	assert.strictEqual(app.classifyScan({ ok: false, result: 'error', code: 'rest_no_route' }, null), 'warn');
	assert.strictEqual(app.classifyScan({}, null), 'warn');
	assert.strictEqual(app.classifyScan(null, { network: true }), 'offline');
	assert.strictEqual(app.classifyScan(null, { status: 502, body: {}, invalid: true }), 'offline');
	assert.strictEqual(app.classifyScan(null, { status: 401, body: { code: 'wpcap_device_unauthorized' } }), 'auth');
	assert.strictEqual(app.classifyScan(null, { status: 429, body: {} }), 'rate');
	assert.strictEqual(app.classifyScan(null, { status: 403, body: {}, invalid: true }), 'warn');
	assert.strictEqual(app.classifyScan(null, { status: 403, body: { code: 'rest_forbidden' } }), 'warn');
	assert.strictEqual(app.classifyScan(null, { status: 400, body: { code: 'wpcap_idempotency_required' } }), 'bad');
});

function htmlResponse(status, text) {
	return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve().then(() => JSON.parse(text)) });
}
async function onlineScan(code, fetchImpl) {
	w.fetch = fetchImpl;
	app.state.selected = 7;
	app.state.token = 'tok';
	await app.handleScan(code).catch(() => {});
	return w.document.getElementById('result').className;
}

test('online scan: WAF page or non-plugin error shows a yellow warning, not "no entry" (A315)', async () => {
	assert.strictEqual(await onlineScan('1'.repeat(64), () => htmlResponse(403, '<html>blocked</html>')), 'result warn');
	assert.strictEqual(await onlineScan('2'.repeat(64), () => htmlResponse(200, '<html>portal</html>')), 'result warn');
	assert.strictEqual(await onlineScan('3'.repeat(64), () => htmlResponse(403, JSON.stringify({ code: 'rest_forbidden', message: 'x' }))), 'result warn');
	assert.strictEqual(await onlineScan('4'.repeat(64), () => htmlResponse(200, JSON.stringify({ ok: false, result: 'error', code: 'wpcap_checkin_retry' }))), 'result warn');
	assert.strictEqual(await onlineScan('5'.repeat(64), () => htmlResponse(200, JSON.stringify({ ok: false, result: 'unknown', code: 'wpcap_ticket_not_found' }))), 'result bad');
	assert.strictEqual(await onlineScan('6'.repeat(64), () => htmlResponse(200, JSON.stringify({ ok: true, result: 'checked_in', ticket: 'T' }))), 'result ok');
	app.state.selected = 0;
	app.state.token = '';
});

test('sliding duplicate window: a code that stays in view is never submitted again (A539)', async () => {
	const code = 'c'.repeat(64);
	let requests = 0;
	w.fetch = () => { requests++; return htmlResponse(200, JSON.stringify({ ok: true, result: 'checked_in' })); };
	app.state.selected = 7;
	await app.handleScan(code).catch(() => {});
	const first = app.lastScan();
	assert.strictEqual(requests, 1);
	// Seen again every 2.5 s for 7.5 s: the window slides, so no second check-in.
	for (let i = 0; i < 3; i++) {
		first.at -= 2500;
		await app.handleScan(code).catch(() => {});
	}
	assert.strictEqual(requests, 1);
	assert.strictEqual(app.lastScan(), first);
	assert.ok(Date.now() - first.at < 1000, 'window restarted at the last sighting');
	// Out of view for more than 3 s: submitted again.
	first.at -= 3500;
	await app.handleScan(code).catch(() => {});
	assert.strictEqual(requests, 2);
	app.state.selected = 0;
});

test('A316: double tap on the camera button opens one stream; a late stream after stop is stopped', async () => {
	const flushAll = async () => { for (let i = 0; i < 20; i++) await new Promise((r) => setImmediate(r)); };
	const pending = [];
	const streams = [];
	w.navigator.mediaDevices = {
		getUserMedia: () => new Promise((resolve) => {
			const track = { stopped: false, stop() { this.stopped = true; } };
			const stream = { track, getTracks: () => [track] };
			streams.push(stream);
			pending.push(() => resolve(stream));
		}),
	};
	w.jsQR = () => null; // No script loading in the sandbox.
	const video = w.document.getElementById('video');
	video.play = () => Promise.resolve();
	video.readyState = 0;
	const button = w.document.getElementById('camera');
	app.startCamera();
	assert.strictEqual(button.disabled, true, 'locked while getUserMedia() is pending');
	app.startCamera();
	assert.strictEqual(streams.length, 1, 'second tap ignored');
	pending.shift()();
	await flushAll();
	assert.strictEqual(app.camera.stream, streams[0]);
	assert.strictEqual(button.disabled, false);
	app.startCamera(); // Running: the button stops the camera.
	assert.strictEqual(streams[0].track.stopped, true);
	assert.strictEqual(app.camera.stream, null);
	// Start, then stop (e.g. page hidden) before the permission is granted.
	app.startCamera();
	app.stopCamera();
	pending.shift()();
	await flushAll();
	assert.strictEqual(streams[1].track.stopped, true, 'the late stream does not keep the camera on');
	assert.strictEqual(app.camera.stream, null);
	assert.strictEqual(button.disabled, false);
});

test('A543: a hash replaced by a new code is "replaced" offline; online refusals show the server reason', async () => {
	const e = entry([ { h: 'old', s: 'revoked', r: 'replaced' }, { h: 'rv', s: 'revoked' } ]);
	assert.strictEqual(app.decideOffline(e, 'old').result, 'replaced');
	assert.strictEqual(app.decideOffline(e, 'rv').result, 'revoked');
	await onlineScan('7'.repeat(64), () => htmlResponse(200, JSON.stringify({ ok: false, result: 'replaced', code: 'wpcap_ticket_replaced', message: 'Code ersetzt: übertragen' })));
	assert.strictEqual(w.document.getElementById('result-title').textContent, 'Code ersetzt');
	assert.match(w.document.getElementById('result-detail').textContent, /übertragen/);
	app.state.selected = 0;
	app.state.token = '';
});

test('A649: only the app API of this site is accepted as API base (?api=, pairing QR, storage)', () => {
	const ok = (u) => app.apiBaseOk(u);
	assert.strictEqual(ok('https://example.test/wp-json/pass-captain/v1/app/'), true);
	assert.strictEqual(ok('https://example.test/sub/wp-json/pass-captain/v1/app/'), true);
	assert.strictEqual(ok('https://example.test/wp-json/wp-pass-captain/v1/app/'), true, 'legacy namespace');
	assert.strictEqual(ok('https://example.test/?rest_route=/pass-captain/v1/app/'), true);
	assert.strictEqual(ok('https://example.test/index.php?rest_route=/pass-captain/v1/app/'), true);
	assert.strictEqual(ok('https://evil.test/wp-json/pass-captain/v1/app/'), false, 'other host');
	assert.strictEqual(ok('https://example.test/wp-content/uploads/x.php'), false, 'other path of the same host');
	assert.strictEqual(ok('https://example.test/wp-json/other/v1/'), false);
	assert.strictEqual(ok('https://example.test/wp-json/pass-captain/v1/app/x'), false);
	assert.strictEqual(ok('https://example.test/wp-json/pass-captain/v1/app/?log=1'), false, 'extra query');
	assert.strictEqual(ok('https://example.test/?rest_route=/other/v1/'), false);
	assert.strictEqual(ok('https://example.test/?rest_route=/pass-captain/v1/app/&x=1'), false);
	assert.strictEqual(ok('https://u:p@example.test/wp-json/pass-captain/v1/app/'), false);
	assert.strictEqual(ok('https://example.test/x/../wp-json/pass-captain/v1/app/'), true, 'dot segments are resolved');
	assert.strictEqual(ok('javascript:alert(1)'), false);
	assert.strictEqual(ok(''), false);
});

/* A547: undo at the device, with a small in-memory IndexedDB so boot() wires the app completely. */
function fakeIndexedDB() {
	const stores = new Map();
	const later = (fn) => setImmediate(fn);
	function storeApi(name) {
		const meta = stores.get(name);
		const keyOf = (v, k) => (undefined !== k ? k : v[meta.keyPath]);
		return {
			get: (k) => ({ result: meta.data.has(k) ? structuredClone(meta.data.get(k)) : undefined }),
			put: (v, k) => { meta.data.set(keyOf(v, k), structuredClone(v)); return {}; },
			getAll: () => ({ result: Array.from(meta.data.values()).map((v) => structuredClone(v)) }),
			delete: (k) => { meta.data.delete(k); return {}; },
			clear: () => { meta.data.clear(); return {}; },
			createIndex() {},
			index: (field) => ({ getAll: (v) => ({ result: Array.from(meta.data.values()).filter((e) => e[field] === v).map((e) => structuredClone(e)) }) }),
		};
	}
	const db = {
		createObjectStore: (name, opts) => { stores.set(name, { keyPath: opts && opts.keyPath, data: new Map() }); return storeApi(name); },
		transaction: (name) => { const tx = { objectStore: () => storeApi(name) }; later(() => tx.oncomplete && tx.oncomplete()); return tx; },
	};
	return {
		stores,
		open: () => { const r = {}; later(() => { r.result = db; if (!stores.size && r.onupgradeneeded) { r.onupgradeneeded(); } r.onsuccess(); }); return r; },
	};
}
const settle = async () => { for (let i = 0; i < 30; i++) { await new Promise((r) => setImmediate(r)); } };
async function undoApp() {
	const idb = fakeIndexedDB();
	const w2 = loadApp({ indexedDB: idb, addEventListener() {}, history: { replaceState() {} }, withI18n: true, localStorage: { getItem: () => null, setItem() {} } });
	await settle();
	const a2 = w2.wpcapOffline;
	a2.state.keys = { k1: { public_key: b64(keyPair.publicKey), status: 'active' } };
	a2.state.deviceId = 'dev-1';
	a2.state.token = 'tok';
	a2.state.selected = 7;
	return { w2, a2, idb, log: () => Array.from(idb.stores.get('log').data.values()) };
}
const sha = (code) => require('node:crypto').createHash('sha256').update(code).digest('hex');

test('A547: the undo countdown of the PWA', () => {
	assert.strictEqual(app.undoSecondsLeft(0, 0), 30);
	assert.strictEqual(app.undoSecondsLeft(0, 29001), 1);
	assert.strictEqual(app.undoSecondsLeft(0, 30000), 0);
});

test('A547: an offline admission not yet uploaded is taken back locally with a required reason', async () => {
	const { w2, a2, log } = await undoApp();
	const code = 'd'.repeat(64);
	a2.state.packages[7] = entry([ { h: sha(code), t: 1, s: 'valid' } ]);
	w2.navigator.onLine = false;
	await a2.handleScan(code);
	await settle();
	assert.strictEqual(w2.document.getElementById('undo').hidden, false, 'undo offered');
	assert.match(w2.document.getElementById('undo-start').textContent, /^Rückgängig/);
	await a2.confirmUndo('');
	assert.match(w2.document.getElementById('undo-note').textContent, /Grund/);
	assert.strictEqual(log()[0].synced, 0, 'nothing changed without a reason');
	await a2.confirmUndo('Fehlscan');
	await settle();
	const row = log()[0];
	assert.deepStrictEqual({ synced: row.synced, code: row.code, decision: row.decision, ok: row.ok, reason: row.undo_reason }, { synced: 1, code: '', decision: 'undone', ok: false, reason: 'Fehlscan' });
	assert.strictEqual(w2.document.getElementById('result-title').textContent, 'Einlass zurückgenommen');
	assert.strictEqual(w2.document.getElementById('undo').hidden, true);
	// The same ticket can be admitted again on this device.
	a2.lastScan().at = 0;
	await a2.handleScan(code);
	await settle();
	assert.strictEqual(w2.document.getElementById('result-title').textContent, 'Einlass OK (offline)');
});

test('A547: an online admission is taken back via POST /app/scan/undo with ticket_public_id and reason', async () => {
	const { w2, a2 } = await undoApp();
	const calls = [];
	w2.fetch = (url, opts) => {
		calls.push({ url, body: opts.body ? JSON.parse(opts.body) : null });
		const body = /scan\/undo/.test(url) ? { ok: true, result: 'undone' } : { ok: true, result: 'checked_in', ticket_public_id: 'a'.repeat(32) };
		return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
	};
	await a2.handleScan('e'.repeat(64));
	await settle();
	assert.strictEqual(w2.document.getElementById('undo').hidden, false);
	await a2.confirmUndo('Doppelt gescannt');
	await settle();
	const undo = calls.find((c) => /scan\/undo/.test(c.url));
	assert.ok(undo, 'undo request sent');
	assert.deepStrictEqual({ id: undo.body.ticket_public_id, reason: undo.body.reason }, { id: 'a'.repeat(32), reason: 'Doppelt gescannt' });
	assert.strictEqual(w2.document.getElementById('result-title').textContent, 'Einlass zurückgenommen');
});

test('A547: a refused online undo is shown in red and keeps the admission', async () => {
	const { w2, a2, log } = await undoApp();
	w2.fetch = (url) => {
		const body = /scan\/undo/.test(url) ? { ok: false, result: 'wrong_area', code: 'wpcap_undo_wrong_area', message: 'Anderer Bereich' } : { ok: true, result: 'checked_in', ticket_public_id: 'b'.repeat(32) };
		return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
	};
	await a2.handleScan('f'.repeat(64));
	await settle();
	await a2.confirmUndo('Fehlscan');
	await settle();
	assert.strictEqual(w2.document.getElementById('result-title').textContent, 'Rückgängig nicht möglich');
	assert.strictEqual(log()[0].ok, true);
});

test('A546: counter from the package (admissible tickets, used in the package or on this device)', () => {
	const e = entry([ { h: 'a', t: 1, s: 'valid' }, { h: 'b', t: 1, s: 'checked_in' }, { h: 'c', t: 1, s: 'valid' }, { h: 'r', t: 1, s: 'revoked' } ]);
	const plain = (x) => JSON.parse(JSON.stringify(x));
	assert.deepStrictEqual(plain(app.localCounts(e, new Set([ 'c' ]))), { admitted: 2, total: 3 });
	assert.deepStrictEqual(plain(app.localCounts(e, null)), { admitted: 1, total: 3 });
	assert.strictEqual(app.localCounts(null, null), null);
	const server = { admitted: 40, total: 100, at: 5 };
	assert.deepStrictEqual(plain(app.counterValues(server, { admitted: 1, total: 3 }, 2)), { admitted: 42, total: 100, source: 'server', at: 5 });
	assert.strictEqual(app.counterValues(null, { admitted: 1, total: 3 }, 2).source, 'package');
	assert.strictEqual(app.counterValues(null, null, 0), null);
});

test('A546: online the PWA shows the server counter of the selected date plus unsynced offline admissions', async () => {
	const { w2, a2 } = await undoApp();
	a2.state.packages[7] = entry([ { h: 'x', t: 1, s: 'valid' } ]);
	const urls = [];
	w2.fetch = (url) => { urls.push(url); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ occurrence_id: 7, tickets_total: 300, checked_in: 120, admitted: 125, admission_total: 300 }) }); };
	await a2.refreshCounts(true);
	await settle();
	assert.ok(urls.some((u) => /occurrences\/7\/stats$/.test(u)), 'stats of the selected date');
	assert.strictEqual(w2.document.getElementById('counter').hidden, false);
	assert.match(w2.document.getElementById('counter').textContent, /^Eingecheckt: 125 \/ 300/);
	// Offline without a server value: the package count.
	a2.state.serverCounts = {};
	w2.navigator.onLine = false;
	await a2.refreshCounts(true);
	assert.match(w2.document.getElementById('counter').textContent, /^Eingecheckt: 0 \/ 1 · laut Prüfpaket/);
});
