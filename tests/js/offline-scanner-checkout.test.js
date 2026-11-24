/**
 * Offline scanner PWA (assets/offline-scanner/app.js): undo only with the undo right
 * (wpcap_undo_checkin: GET /app/me permissions.undo, else the package's permissions.undo) and readable
 * refusals; check-out ("Auslass") online and offline with the rules of Checkin::check_out() – right
 * (checkout_roles), area binding of the user, inside state – queued for /app/scan/sync with
 * direction "out"; re-entry after a local check-out (reentry_after_checkout / max_reentries).
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
function recordingElement() {
	const values = {};
	return new Proxy(function () {}, {
		get: (target, prop) => (prop in values ? values[prop] : prop === 'classList' ? { add() {}, remove() {}, toggle() {} } : prop === 'style' || prop === 'dataset' ? {} : element()),
		set: (target, prop, value) => { values[prop] = value; return true; },
		apply: () => element(),
	});
}
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
const sha = (code) => require('node:crypto').createHash('sha256').update(code).digest('hex');

async function makeApp() {
	const byId = new Map();
	const idb = fakeIndexedDB();
	const window = {
		document: { getElementById: (id) => { if (!byId.has(id)) byId.set(id, recordingElement()); return byId.get(id); }, querySelector: () => element(), querySelectorAll: () => [], createElement: () => element(), addEventListener() {}, documentElement: {}, title: '' },
		navigator: { onLine: true },
		location: { search: '', pathname: '/', origin: 'https://example.test', href: 'https://example.test/', hash: '' },
		crypto: require('node:crypto').webcrypto,
		TextEncoder, TextDecoder, URL, URLSearchParams, Date, Map, Set, JSON, Promise, Uint8Array, Array, String, Math, Error, Object, RegExp, isNaN,
		atob: (s) => Buffer.from(s, 'base64').toString('binary'),
		setInterval() {}, setTimeout() {}, clearTimeout() {}, console,
		indexedDB: idb, addEventListener() {}, history: { replaceState() {} }, localStorage: { getItem: () => null, setItem() {} },
	};
	window.window = window;
	window.self = window;
	window.globalThis = window;
	const ctx = vm.createContext(window);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/vendor/tweetnacl/nacl-fast.min.js'), 'utf8'), ctx);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/offline-scanner/i18n.js'), 'utf8'), ctx);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/offline-scanner/app.js'), 'utf8'), ctx);
	await settle();
	const app = window.wpcapOffline;
	window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ token: 'tok', device_id: 'dev-1' }) });
	await app.pair('ABCD-EFGH-JKLM-NPQR');
	delete window.fetch;
	app.state.deviceId = 'dev-1';
	app.state.selected = 7;
	const el = (id) => window.document.getElementById(id);
	return { w: window, app, idb, el, log: () => Array.from(idb.stores.get('log').data.values()), kv: () => idb.stores.get('kv').data };
}

/** Package entry as loadPackage() keeps it (verified meta, index by hash). */
function entry(tickets, extra = {}) {
	const meta = Object.assign({
		expires_at: new Date(Date.now() + 3600e3).toISOString(),
		ticket_types: [{ id: 1, name: 'Standard', multi: false, own_policy: false, areas: [] }, { id: 2, name: 'Wiedereinlass', multi: true, policy: 'reentry', max_entries: 0, own_policy: true, areas: [] }, { id: 3, name: 'Einmalig', multi: false, own_policy: true, areas: [] }],
		occurrence: { id: 7 }, areas: [{ id: 5, name: 'Nord' }, { id: 6, name: 'Süd' }], device_area_id: 0,
		permissions: { undo: true, check_out: true },
		checkout: { staff_area_ids: [], reentry_after_checkout: false, max_reentries: 0 },
	}, extra);
	return { record: { occurrence_id: 7 }, index: new Map(tickets.map((t) => [t.h, t])), meta, error: '' };
}
const json = (body, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

/* ---------------- undo right ---------------- */

test('undo is not offered when the package says the user lacks the undo right', async () => {
	const { w, app, el, log } = await makeApp();
	const code = 'a'.repeat(64);
	app.state.packages[7] = entry([{ h: sha(code), t: 1, s: 'valid' }], { permissions: { undo: false, check_out: false } });
	w.navigator.onLine = false;
	await app.handleScan(code);
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Einlass OK (offline)');
	assert.strictEqual(app.canUndo(), false);
	assert.strictEqual(el('undo').hidden, true, 'no "Rückgängig" without the right');
	assert.strictEqual(app.state.undo, null);
	// confirmUndo without an offer (or a stale one) changes nothing: the offline admission stays queued.
	await app.confirmUndo('Fehlscan');
	assert.strictEqual(log()[0].synced, 0);
	assert.strictEqual(log()[0].ok, true);
});

test('undo right: /app/me wins over the package; unknown (older server) keeps undo offered', async () => {
	const { app } = await makeApp();
	app.state.packages[7] = entry([], { permissions: undefined });
	assert.strictEqual(app.canUndo(), true, 'no information: offered, the server decides');
	app.state.packages[7] = entry([], { permissions: { undo: false } });
	assert.strictEqual(app.canUndo(), false);
	await app.notePerms({ permissions: { check_in: true, undo: true, check_out: 'yes' } });
	assert.strictEqual(app.canUndo(), true, '/app/me value is used');
	assert.deepStrictEqual(JSON.parse(JSON.stringify(app.state.perms)), { undo: true }, 'only boolean rights are kept');
	await app.notePerms({ permissions: { undo: false } });
	assert.strictEqual(app.canUndo(), false);
	await app.notePerms({ features: {} });
	app.state.packages[7] = entry([], { permissions: undefined });
	assert.strictEqual(app.canUndo(), true);
});

test('undo refused for a missing right (ok:false, wpcap_undo_capability): message shown, undo hidden afterwards', async () => {
	const { w, app, el } = await makeApp();
	app.state.packages[7] = entry([]);
	const msg = 'Du darfst keine Check-ins zurücknehmen. Bitte die Einlassleitung fragen.';
	w.fetch = (url) => json(/scan\/undo/.test(url) ? { ok: false, result: 'forbidden', code: 'wpcap_undo_capability', message: msg } : { ok: true, result: 'checked_in', ticket_public_id: 'b'.repeat(32) });
	await app.handleScan('b'.repeat(64));
	await settle();
	assert.strictEqual(el('undo').hidden, false, 'offered while the right is unknown');
	await app.confirmUndo('Fehlscan');
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Rückgängig nicht möglich');
	assert.strictEqual(el('result-detail').textContent, msg);
	assert.strictEqual(app.canUndo(), false);
	assert.strictEqual(el('undo').hidden, true);
	// The next admission offers no undo any more.
	app.lastScan().at = 0;
	await app.handleScan('c'.repeat(64));
	await settle();
	assert.strictEqual(el('undo').hidden, true);
});

test('undo refused with HTTP 403: the server message, or a readable text without a JSON body', async () => {
	const { w, app, el } = await makeApp();
	app.state.packages[7] = entry([]);
	w.fetch = (url) => (/scan\/undo/.test(url) ? json({ code: 'wpcap_undo_capability', message: 'Keine Rücknahme erlaubt.', data: { status: 403 } }, 403) : json({ ok: true, result: 'checked_in', ticket_public_id: 'd'.repeat(32) }));
	await app.handleScan('d'.repeat(64));
	await settle();
	await app.confirmUndo('Fehlscan');
	await settle();
	assert.strictEqual(el('result-detail').textContent, 'Keine Rücknahme erlaubt.');
	assert.strictEqual(app.canUndo(), false, 'the 403 code also hides undo');

	const second = await makeApp();
	second.app.state.packages[7] = entry([]);
	second.w.fetch = (url) => (/scan\/undo/.test(url) ? Promise.resolve({ ok: false, status: 403, json: () => Promise.reject(new Error('html')) }) : json({ ok: true, result: 'checked_in', ticket_public_id: 'e'.repeat(32) }));
	await second.app.handleScan('e'.repeat(64));
	await settle();
	await second.app.confirmUndo('Fehlscan');
	await settle();
	assert.strictEqual(second.el('result-title').textContent, 'Rückgängig nicht möglich');
	assert.strictEqual(second.el('result-detail').textContent, 'Du darfst keine Check-ins zurücknehmen. Bitte die Einlassleitung fragen.', 'no "HTTP 403"');
});

/* ---------------- check-out ---------------- */

test('check-out is only offered with the package rules and the check-out right', async () => {
	const { app, el } = await makeApp();
	app.state.packages[7] = entry([], { checkout: undefined });
	app.setMode('out');
	assert.strictEqual(app.canCheckOut(), false, 'older server: no check-out rules in the package');
	assert.strictEqual(app.state.mode, 'in');
	app.state.packages[7] = entry([], { permissions: { undo: true, check_out: false } });
	assert.strictEqual(app.canCheckOut(), false, 'role may not check out');
	app.state.packages[7] = entry([]);
	app.setMode('out');
	assert.strictEqual(app.state.mode, 'out');
	assert.strictEqual(el('mode-wrap').hidden, false);
	assert.strictEqual(el('result-title').textContent, 'Bereit (Auslass)');
	// /app/me reports the right is gone: back to admission, switch hidden.
	await app.notePerms({ permissions: { undo: true, check_out: false } });
	assert.strictEqual(app.state.mode, 'in');
	assert.strictEqual(el('mode-wrap').hidden, true);
});

test('decideCheckout: inside on this device, queued for the server, refusals', async () => {
	const { app } = await makeApp();
	const tk = (h, s = 'valid', t = 1) => ({ h, s, t });
	const e = entry([tk('in1'), tk('out1'), tk('rev', 'revoked'), tk('used', 'checked_in')]);
	app.state.packages[7] = e;
	assert.strictEqual(app.decideCheckout(e, 'nope').result, 'unknown');
	// Not seen on this device: queued, the server decides at sync.
	const q = app.decideCheckout(e, 'used');
	assert.deepStrictEqual([q.ok, q.result, q.confirmed], [true, 'checked_out', false]);
	const r = app.decideCheckout(e, 'rev');
	assert.deepStrictEqual([r.ok, r.confirmed], [true, false], 'a revoked ticket that is inside may leave (server decides)');
	// Admitted on this device: checked out at once.
	app.state.local[7] = new Map([['in1', { ins: [Date.now()], outs: [], inside: true }], ['out1', { ins: [Date.now() - 5000], outs: [Date.now()], inside: false }]]);
	const c = app.decideCheckout(e, 'in1');
	assert.deepStrictEqual([c.ok, c.confirmed], [true, true]);
	const twice = app.decideCheckout(e, 'out1');
	assert.deepStrictEqual([twice.ok, twice.result], [false, 'not_checked_in'], 'already checked out here after its last entry here');
	// Area binding of the user (Areas::check() rule 2).
	const bound = entry([tk('in1')], { checkout: { staff_area_ids: [6], reentry_after_checkout: false, max_reentries: 0 } });
	app.state.packages[7] = bound;
	app.state.areaId = 5;
	assert.strictEqual(app.decideCheckout(bound, 'in1').result, 'wrong_area');
	app.state.areaId = 6;
	assert.strictEqual(app.decideCheckout(bound, 'in1').ok, true);
	// Without the right / without a valid package.
	const noRight = entry([tk('in1')], { permissions: { check_out: false } });
	app.state.packages[7] = noRight;
	assert.strictEqual(app.decideCheckout(noRight, 'in1').result, 'checkout_forbidden');
	const expired = entry([tk('in1')], { expires_at: new Date(Date.now() - 1000).toISOString() });
	app.state.packages[7] = expired;
	assert.strictEqual(app.decideCheckout(expired, 'in1').result, 'package_expired');
	app.state.local = {};
	app.state.areaId = 0;
});

test('offline check-out is queued with direction "out", uploaded through the batch sync, refusals shown', async () => {
	const { w, app, el, log } = await makeApp();
	const code = 'f'.repeat(64);
	app.state.packages[7] = entry([{ h: sha(code), t: 1, s: 'valid' }]);
	w.navigator.onLine = false;
	await app.handleScan(code);
	await settle();
	app.setMode('out');
	app.lastScan().at = 0;
	await app.handleScan(code);
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Ausgecheckt (offline)');
	assert.strictEqual(el('undo').hidden, true, 'no undo for a check-out');
	const out = log().find((r) => 'out' === r.direction);
	assert.deepStrictEqual({ code: out.code, synced: out.synced, ok: out.ok, decision: out.decision, mode: out.mode }, { code, synced: 0, ok: true, decision: 'checked_out', mode: 'offline' });
	// A second check-out of the same ticket on this device is refused.
	app.lastScan().at = 0;
	await app.handleScan(code);
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Nicht eingecheckt');
	assert.match(String(el('result-detail').textContent), /bereits ausgecheckt/);
	assert.strictEqual(log().filter((r) => 'out' === r.direction && r.ok).length, 1);
	// Re-entry of a single ticket stays refused without the setting.
	app.setMode('in');
	app.lastScan().at = 0;
	await app.handleScan(code);
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Bereits verwendet', 'red: the guest left, no "Eben hier eingelassen"');
	// Sync: both scans in one batch, the check-out with direction "out" and its code.
	w.navigator.onLine = true;
	const bodies = [];
	w.fetch = (url, opts) => {
		const body = opts.body ? JSON.parse(opts.body) : null;
		if (body && body.scans) { bodies.push(body); }
		const results = body && body.scans ? body.scans.map((s) => ('out' === s.direction ? { idempotency_key: s.idempotency_key, ok: false, result: 'not_checked_in', direction: 'out', conflict: false } : { idempotency_key: s.idempotency_key, ok: true, result: 'checked_in', direction: 'in' })) : [];
		return json({ results });
	};
	await app.syncNow();
	await settle();
	assert.strictEqual(bodies.length, 1);
	const sent = bodies[0].scans.find((s) => 'out' === s.direction);
	assert.ok(sent, 'check-out uploaded');
	assert.strictEqual(sent.code, code);
	const synced = log().find((r) => 'out' === r.direction && r.ok);
	assert.deepStrictEqual({ synced: synced.synced, code: synced.code, ok: synced.server.ok, result: synced.server.result, conflict: synced.server.conflict }, { synced: 1, code: '', ok: false, result: 'not_checked_in', conflict: false });
});

test('a check-out of a ticket not seen on this device is queued as "Check-out vorgemerkt"', async () => {
	const { w, app, el, log } = await makeApp();
	const code = '9'.repeat(64);
	app.state.packages[7] = entry([{ h: sha(code), t: 1, s: 'checked_in', n: 'Alex' }]);
	app.setMode('out');
	w.navigator.onLine = false;
	await app.handleScan(code);
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Check-out vorgemerkt');
	assert.match(String(el('result-detail').textContent), /Prüfung beim Sync/);
	assert.strictEqual(log()[0].synced, 0);
	assert.strictEqual(log()[0].direction, 'out');
});

test('online check-out uses POST /app/scan/check-out; a role refusal hides the switch', async () => {
	const { w, app, el, log } = await makeApp();
	const code = '8'.repeat(64);
	app.state.packages[7] = entry([{ h: sha(code), t: 1, s: 'checked_in' }]);
	app.state.areaId = 5;
	app.setMode('out');
	const calls = [];
	w.fetch = (url, opts) => { calls.push({ url, body: opts.body ? JSON.parse(opts.body) : null }); return json({ ok: true, result: 'checked_out', ticket: 'Standard' }); };
	await app.handleScan(code);
	await settle();
	const call = calls.find((c) => /scan\/check-out$/.test(c.url));
	assert.ok(call, 'check-out endpoint used');
	assert.deepStrictEqual({ code: call.body.code, occ: call.body.occurrence_id, area: call.body.area_id, key: !!call.body.idempotency_key }, { code, occ: 7, area: 5, key: true });
	assert.ok(!calls.some((c) => /scan\/check-in$/.test(c.url)), 'no check-in');
	assert.strictEqual(el('result-title').textContent, 'Ausgecheckt');
	assert.deepStrictEqual({ dir: log()[0].direction, synced: log()[0].synced, mode: log()[0].mode }, { dir: 'out', synced: 1, mode: 'online' });
	// The role lost the right (checkout_roles changed): refusal shown, back to admission.
	w.fetch = () => json({ ok: false, result: 'forbidden', code: 'wpcap_checkout_forbidden', message: 'Check-out ist für deine Rolle nicht freigegeben.' });
	app.lastScan().at = 0;
	await app.handleScan(code);
	await settle();
	assert.strictEqual(el('result-title').textContent, 'Check-out nicht erlaubt');
	assert.strictEqual(el('result-detail').textContent, 'Check-out ist für deine Rolle nicht freigegeben.');
	assert.strictEqual(app.state.mode, 'in');
	assert.strictEqual(app.canCheckOut(), false);
	app.state.areaId = 0;
});

test('help code in check-out mode checks out online', async () => {
	const { w, app } = await makeApp();
	app.state.packages[7] = entry([]);
	app.setMode('out');
	const urls = [];
	w.fetch = (url) => { urls.push(url); return json({ ok: true, result: 'checked_out' }); };
	await app.handleScan('K7QM-4XWA');
	await settle();
	assert.ok(urls.some((u) => /scan\/check-out$/.test(u)));
	assert.ok(!urls.some((u) => /scan\/check-in$/.test(u)));
});

test('re-entry after a check-out on this device (reentry_after_checkout, max_reentries, reentry policy)', async () => {
	const { w, app, el } = await makeApp();
	const single = '7'.repeat(64);
	const own = '6'.repeat(64);
	const re = '5'.repeat(64);
	const rules = { staff_area_ids: [], reentry_after_checkout: true, max_reentries: 1 };
	app.state.packages[7] = entry([{ h: sha(single), t: 1, s: 'valid' }, { h: sha(own), t: 3, s: 'valid' }, { h: sha(re), t: 2, s: 'valid' }], { checkout: rules });
	w.navigator.onLine = false;
	const scan = async (code, mode) => { app.setMode(mode); app.lastScan().at = 0; await app.handleScan(code); await settle(); return el('result-title').textContent; };
	assert.strictEqual(await scan(single, 'in'), 'Einlass OK (offline)');
	assert.strictEqual(await scan(single, 'out'), 'Ausgecheckt (offline)');
	assert.strictEqual(await scan(single, 'in'), 'Einlass OK (offline)', 're-entry after check-out');
	assert.match(String(el('result-detail').textContent), /Wiedereinlass nach Check-out/);
	assert.strictEqual(await scan(single, 'out'), 'Ausgecheckt (offline)');
	assert.strictEqual(await scan(single, 'in'), 'Einlasslimit erreicht', 'max_reentries = 1');
	// A ticket type with its own admission rule does not follow the re-entry setting.
	assert.strictEqual(await scan(own, 'in'), 'Einlass OK (offline)');
	assert.strictEqual(await scan(own, 'out'), 'Ausgecheckt (offline)');
	assert.notStrictEqual(await scan(own, 'in'), 'Einlass OK (offline)');
	// Policy "reentry": inside → refused; after a local check-out → admitted again.
	assert.strictEqual(await scan(re, 'in'), 'Einlass OK (offline)');
	assert.notStrictEqual(await scan(re, 'in'), 'Einlass OK (offline)');
	assert.strictEqual(await scan(re, 'out'), 'Ausgecheckt (offline)');
	assert.strictEqual(await scan(re, 'in'), 'Einlass OK (offline)');
});

test('the rights from /app/me are kept on the device and wiped with the pairing', async () => {
	const { app, kv } = await makeApp();
	await app.notePerms({ permissions: { undo: false, check_out: true } });
	await settle();
	assert.deepStrictEqual(kv().get('perms'), { undo: false, check_out: true });
});

test('every new UI text has an English catalog entry and is listed in the server table', () => {
	const i18n = fs.readFileSync(path.join(root, 'assets/offline-scanner/i18n.js'), 'utf8');
	const table = fs.readFileSync(path.join(root, 'src/OfflineScanner/I18n.php'), 'utf8');
	['Scan-Richtung', 'Einlass', 'Auslass (Check-out)', 'Bereit (Auslass)', 'Ausgecheckt', 'Ausgecheckt (offline)', 'Nicht eingecheckt', 'Check-out nicht erlaubt', 'Check-out vorgemerkt', 'Prüfung beim Sync', 'Auf diesem Gerät bereits ausgecheckt', 'Wiedereinlass nach Check-out – Prüfung beim Sync', 'Check-out abgelehnt: %s', 'Du darfst keine Check-ins zurücknehmen. Bitte die Einlassleitung fragen.'].forEach((key) => {
		assert.ok(i18n.includes("'" + key + "':"), 'en catalog: ' + key);
		assert.ok(table.includes("'" + key + "' =>"), 'server table: ' + key);
	});
});
