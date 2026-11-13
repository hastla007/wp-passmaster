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
function loadApp() {
	const byId = new Map();
	const window = {
		document: { getElementById: (id) => { if (!byId.has(id)) byId.set(id, recordingElement()); return byId.get(id); }, querySelector: () => element(), querySelectorAll: () => [], createElement: () => element(), addEventListener() {} },
		navigator: { onLine: true },
		location: { search: '', pathname: '/', origin: 'https://example.test', href: 'https://example.test/' },
		crypto: require('node:crypto').webcrypto,
		TextEncoder, URLSearchParams, Date, Map, Set, JSON, Promise, Uint8Array, Array, String, Math, Error, Object, RegExp, isNaN,
		atob: (s) => Buffer.from(s, 'base64').toString('binary'),
		setInterval() {}, setTimeout() {}, clearTimeout() {}, console,
	};
	window.window = window;
	window.self = window;
	window.globalThis = window;
	const ctx = vm.createContext(window);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/vendor/tweetnacl/nacl-fast.min.js'), 'utf8'), ctx);
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
