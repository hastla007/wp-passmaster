'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const { load, flush, mockFetch, ROOT } = require('./lib/load');
const { fakeIndexedDB } = require('./lib/idb');

const nacl = require(path.join(ROOT, 'assets/vendor/tweetnacl/nacl-fast.min.js'));
const KEYPAIR = nacl.sign.keyPair.fromSeed(new Uint8Array(32).fill(7));
const OTHER = nacl.sign.keyPair.fromSeed(new Uint8Array(32).fill(9));
const b64 = (bytes) => Buffer.from(bytes).toString('base64');
const API = 'https://example.test/wp-json/wp-pass-captain/v1/app/';
const PAGE = 'https://example.test/wp-content/plugins/pass-captain/assets/offline-scanner/index.html';
const DEVICE = 'dev-1';
const CODE_OK = '1'.repeat(64), CODE_USED = '2'.repeat(64), CODE_REVOKED = '3'.repeat(64), CODE_VIP = '4'.repeat(64), CODE_MULTI = '5'.repeat(64);
const sha = (code) => require('crypto').createHash('sha256').update(code).digest('hex');

function payload(extra) {
	return Object.assign({
		format: 'wpcap-offline-package', v: 1, key_id: 'k1', device_id: DEVICE, kind: 'full', cursor: 'c1',
		occurrence: { id: 7, starts_at: '2026-12-01T19:00:00Z' }, event: { name: 'Konzert' },
		issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 3600e3).toISOString(),
		rules: { duplicate_policy: 'first_sync_wins' }, areas: [{ id: 5, name: 'VIP' }], device_area_id: 0,
		ticket_types: [{ id: 1, name: 'Standard', multi: false, areas: [] }, { id: 2, name: 'VIP', multi: false, areas: [5] }, { id: 3, name: 'Festival', multi: true, areas: [] }],
		tickets: [
			{ h: sha(CODE_OK), s: 'valid', t: 1, n: 'Alex' }, { h: sha(CODE_USED), s: 'checked_in', t: 1 },
			{ h: sha(CODE_REVOKED), s: 'revoked', t: 1 }, { h: sha(CODE_VIP), s: 'valid', t: 2 }, { h: sha(CODE_MULTI), s: 'checked_in', t: 3 },
		],
	}, extra || {});
}
function sign(p, keypair, keyId) {
	const text = JSON.stringify(p);
	return { key_id: keyId || p.key_id, payload: text, signature: b64(nacl.sign.detached(Buffer.from(text), (keypair || KEYPAIR).secretKey)) };
}
const KEYS = [{ alg: 'Ed25519', key_id: 'k1', public_key: b64(KEYPAIR.publicKey), status: 'active' }];

/** index.html elements the app uses. */
function page(doc, h) {
	const i18n = (tag, key) => h(tag, { 'data-i18n': key }, key);
	doc.body.appendChild(h('header', [i18n('strong', 'Offline-Scanner'), h('span#net.pill'), h('span#pending.pill', { 'data-i18n-title': 'Noch nicht hochgeladene Offline-Scans' })]));
	doc.body.appendChild(h('section#pair-view', { hidden: true }, [
		i18n('h1', 'Gerät koppeln'),
		h('form#pair-form', [h('input#pair-code'), h('input#pair-label', { 'data-i18n-placeholder': 'z. B. Einlass Nord' }), h('button', { type: 'submit' }, 'Koppeln')]),
		h('code#api-base'),
	]));
	doc.body.appendChild(h('section#main-view', { hidden: true }, [
		h('select#occurrence'), h('label#area-wrap', { hidden: true }, [h('select#area')]), h('div#pkg'),
		h('button#refresh'), h('button#sync'),
		h('div#result.result.idle', [h('div#result-title', 'Bereit'), h('div#result-detail')]),
		h('button#camera'), h('video#video', { hidden: true }),
		h('form#manual', [h('input#manual-code'), h('button', { type: 'submit' }, 'Prüfen')]),
		h('ul#conflicts'), h('ul#log'), h('code#device-id'), h('button#unpair'),
	]));
}

/**
 * Boots the PWA with a fake IndexedDB (optionally pre-filled kv entries) and a fetch mock routed by
 * API path. Returns the env plus `app` (window.wpcapOffline) and `db` (fake database) once booted.
 */
async function boot(opts) {
	opts = opts || {};
	const indexedDB = opts.indexedDB || fakeIndexedDB();
	const routes = opts.routes || {};
	const fetch = mockFetch((url, init, body) => {
		const route = url.replace(API, '').split('?')[0];
		const handler = routes[route];
		if (!handler) return { status: 404, json: { message: 'no route ' + route } };
		return handler(body, url, init);
	});
	const env = load('assets/offline-scanner/app.js', {
		url: PAGE,
		window: { nacl, indexedDB, wpcapUnused: 1 },
		indexedDB,
		navigator: Object.assign({ onLine: true }, opts.navigator || {}),
		fetch,
		setup: page,
	});
	env.fetch = fetch;
	env.app = env.window.wpcapOffline;
	env.idb = indexedDB;
	await flush(40);
	env.db = indexedDB.dbs['wpcap-offline-scanner'];
	return env;
}
/** Pre-fills the kv store of a fresh fake IndexedDB (a paired device). */
async function pairedDb(extra) {
	const indexedDB = fakeIndexedDB();
	const env = await boot({ indexedDB, navigator: { onLine: false } });
	const kv = env.db.stores.kv.data;
	kv.set('api', API); kv.set('token', 'tok'); kv.set('device_id', DEVICE);
	kv.set('keys', { k1: { public_key: b64(KEYPAIR.publicKey), status: 'active' } });
	Object.entries(extra || {}).forEach(([k, v]) => kv.set(k, v));
	return indexedDB;
}
const text = (env, id) => env.document.getElementById(id).textContent;

test('t(): German source texts as keys, printf placeholders, table lookup', async () => {
	const env = await boot({ navigator: { onLine: false } });
	const { t, state, validI18n } = env.app;
	assert.equal(t('Bereit'), 'Bereit', 'no table: German source text');
	assert.equal(t('%d offen', 3), '3 offen');
	assert.equal(t('%d offen', 2.9), '2 offen', '%d truncates');
	assert.equal(t('%1$d Tickets · %2$s', 5, 'k1'), '5 Tickets · k1');
	assert.equal(t('%2$s vor %1$s', 'a', 'b'), 'b vor a');
	assert.equal(t('100%% sicher'), '100%% sicher', 'no args: text unchanged');
	assert.equal(t('100%% %s', 'x'), '100% x');
	assert.equal(t('fehlt: %s'), 'fehlt: %s');
	assert.equal(t('fehlt: %s', undefined), 'fehlt: ');
	state.i18n = { v: 1, lang: 'en-US', strings: { '%d offen': '%d pending', 'Bereit': 'Ready', toString: 5 } };
	assert.equal(t('%d offen', 4), '4 pending');
	assert.equal(t('Bereit'), 'Ready');
	assert.equal(t('toString'), 'toString', 'non-string entries are ignored');
	assert.equal(t('hasOwnProperty'), 'hasOwnProperty');
	assert.equal(validI18n({ v: 1, lang: 'en', strings: {} }), true);
	assert.equal(validI18n({ v: 2, lang: 'en', strings: {} }), false);
	assert.equal(validI18n({ v: 1, strings: {} }), false);
	assert.equal(validI18n(null), false);
});

test('pairing screen when not paired; i18n table is fetched, applied and kept for offline starts', async () => {
	const table = { v: 1, locale: 'en_US', lang: 'en-US', strings: { 'Gerät koppeln': 'Pair device', 'Offline-Scanner': 'Offline scanner', 'z. B. Einlass Nord': 'e.g. North gate', 'Noch nicht hochgeladene Offline-Scans': 'Offline scans not uploaded yet', 'Online': 'Online', '%d offen': '%d pending' } };
	const env = await boot({ routes: { 'offline/i18n': () => ({ json: table }) } });
	const doc = env.document;
	assert.equal(doc.getElementById('pair-view').hidden, false);
	assert.equal(doc.getElementById('main-view').hidden, true);
	assert.equal(text(env, 'api-base'), API, 'API derived from the plugin URL');
	assert.equal(env.fetch.calls[0].url, API + 'offline/i18n');
	assert.equal(env.fetch.calls[0].init.credentials, 'omit');
	assert.equal(doc.querySelector('h1').textContent, 'Pair device');
	assert.equal(doc.documentElement.lang, 'en-US');
	assert.equal(doc.title, 'Offline scanner · Pass Captain');
	assert.equal(doc.getElementById('pair-label').getAttribute('placeholder'), 'e.g. North gate');
	assert.equal(doc.getElementById('pending').getAttribute('title'), 'Offline scans not uploaded yet');
	assert.equal(text(env, 'pending'), '0 pending');
	assert.deepEqual(env.db.stores.kv.data.get('i18n'), table);

	// Offline restart with the same database: stored table is used without network.
	const again = await boot({ indexedDB: env.idb, navigator: { onLine: false } });
	assert.equal(again.fetch.calls.length, 0);
	assert.equal(again.document.querySelector('h1').textContent, 'Pair device');
});

test('invalid i18n tables from the server are ignored', async () => {
	const env = await boot({ routes: { 'offline/i18n': () => ({ json: { v: 99, lang: 'xx', strings: { 'Gerät koppeln': 'BAD' } } }) } });
	assert.equal(env.document.querySelector('h1').textContent, 'Gerät koppeln');
	assert.equal(env.db.stores.kv.data.get('i18n'), undefined);
});

test('pair(): QR payload from another origin is rejected; code pairing stores token and keys', async () => {
	const routes = { pair: (body) => ({ json: { token: 'devtoken', device_id: DEVICE, offline: { keys: KEYS } } }), 'offline/i18n': () => ({ status: 404 }) };
	const env = await boot({ routes });
	await assert.rejects(env.app.pair(JSON.stringify({ type: 'wpcap-pair', api: 'https://evil.test/wp-json/x/', code: 'ABCD' })), /anderen Server/);
	assert.equal(env.fetch.calls.filter((c) => c.url.includes('/pair')).length, 0, 'token request never leaves the origin');
	await env.app.pair(JSON.stringify({ type: 'wpcap-pair', api: API, code: 'ABCD-EFGH-IJKL-MNOP' }), 'Einlass Nord');
	const call = env.fetch.calls.find((c) => c.url === API + 'pair');
	assert.deepEqual(call.body, { code: 'ABCD-EFGH-IJKL-MNOP', device_label: 'Einlass Nord', platform: 'pwa', app_version: '1.0.0' });
	await flush();
	const kv = env.db.stores.kv.data;
	assert.equal(kv.get('token'), 'devtoken');
	assert.equal(kv.get('device_id'), DEVICE);
	assert.deepEqual(Object.keys(kv.get('keys')), ['k1']);
});

test('verifyDoc: Ed25519 signature, key validity, format and device binding', async () => {
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false } });
	const { verifyDoc, state } = env.app;
	assert.equal(verifyDoc(sign(payload())).event.name, 'Konzert');
	const tampered = sign(payload());
	tampered.payload = tampered.payload.replace('Konzert', 'Konzerz');
	assert.throws(() => verifyDoc(tampered), /Signatur ungültig/);
	assert.throws(() => verifyDoc(sign(payload(), OTHER)), /Signatur ungültig/, 'signed with another key');
	assert.throws(() => verifyDoc(sign(payload({ key_id: 'k9' }))), /Unbekannter Signaturschlüssel/);
	assert.throws(() => verifyDoc(sign(payload({ device_id: 'dev-2' }))), /anderen Gerät/);
	assert.throws(() => verifyDoc(sign(payload({ format: 'other' }))), /Paketformat/);
	assert.throws(() => verifyDoc(sign(payload({ v: 2 }))), /Paketformat/);
	assert.throws(() => verifyDoc(Object.assign(sign(payload()), { signature: '***' })), /Signatur ungültig/);
	const mismatch = sign(payload({ key_id: 'k1' }));
	state.keys.k2 = state.keys.k1;
	assert.throws(() => verifyDoc(Object.assign({}, mismatch, { key_id: 'k2' })), /Schlüssel-ID passt nicht/);
	state.keys.k1 = Object.assign({}, state.keys.k1, { valid_until: new Date(Date.now() - 1000).toISOString() });
	assert.throws(() => verifyDoc(sign(payload())), /Signaturschlüssel abgelaufen/);
});

test('buildIndex: full package plus chained deltas; broken chain is rejected', async () => {
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false } });
	const full = sign(payload());
	const delta = sign(payload({ kind: 'delta', since: 'c1', cursor: 'c2', tickets: [{ h: sha(CODE_OK), s: 'revoked', t: 1 }] }));
	const built = env.app.buildIndex({ occurrence_id: 7, full, deltas: [delta] });
	assert.equal(built.cursor, 'c2');
	assert.equal(built.index.get(sha(CODE_OK)).s, 'revoked', 'delta overrides the full package');
	const broken = sign(payload({ kind: 'delta', since: 'zz', cursor: 'c3', tickets: [] }));
	assert.throws(() => env.app.buildIndex({ occurrence_id: 7, full, deltas: [broken] }), /Delta-Kette unterbrochen/);
	assert.throws(() => env.app.buildIndex({ occurrence_id: 8, full, deltas: [] }), /passt nicht zum Termin/);
	const entry = env.app.loadPackage({ occurrence_id: 7, full: Object.assign({}, full, { signature: b64(new Uint8Array(64)) }), deltas: [] });
	assert.match(entry.error, /Signatur ungültig/);
	assert.match(env.app.packageStatus(entry).text, /Prüfpaket ungültig: Signatur ungültig/);
});

test('decideOffline mirrors the local admission rules', async () => {
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false } });
	const { loadPackage, decideOffline, state } = env.app;
	const entry = loadPackage({ occurrence_id: 7, full: sign(payload()), deltas: [] });
	assert.equal(entry.error, '');
	assert.deepEqual(decideOffline(entry, sha(CODE_OK)).result, 'checked_in');
	assert.equal(decideOffline(entry, sha('9'.repeat(64))).result, 'unknown');
	assert.equal(decideOffline(entry, sha(CODE_REVOKED)).result, 'revoked');
	assert.equal(decideOffline(entry, sha(CODE_USED)).result, 'already_checked_in');
	assert.equal(decideOffline(entry, sha(CODE_VIP)).result, 'wrong_area', 'VIP ticket at a device without area');
	state.areaId = 5;
	assert.equal(decideOffline(entry, sha(CODE_VIP)).result, 'checked_in');
	const multi = decideOffline(entry, sha(CODE_MULTI));
	assert.equal(multi.ok, true, 'multi-entry tickets are not limited locally');
	assert.equal(multi.multi, true);
	state.admitted[7] = new Set([sha(CODE_OK)]);
	assert.equal(decideOffline(entry, sha(CODE_OK)).result, 'already_checked_in', 'admitted earlier on this device');
	assert.equal(decideOffline(null, sha(CODE_OK)).result, 'no_package');
	const expired = loadPackage({ occurrence_id: 7, full: sign(payload({ expires_at: new Date(Date.now() - 1000).toISOString() })), deltas: [] });
	assert.equal(decideOffline(expired, sha(CODE_OK)).result, 'package_expired');
});

/* ---- signed QR codes (PC1) ---- */

const QR_KEY = nacl.sign.keyPair.fromSeed(new Uint8Array(32).fill(11));
const QR_OTHER = nacl.sign.keyPair.fromSeed(new Uint8Array(32).fill(12));
const QR_KEYS = { q1: { public_key: b64(QR_KEY.publicKey), status: 'active' }, q0: { public_key: b64(QR_OTHER.publicKey), status: 'retired', valid_until: new Date(Date.now() - 1000).toISOString() } };
const b64u = (bytes) => Buffer.from(bytes).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const nowS = () => Math.floor(Date.now() / 1000);
/** Builds a signed QR code like PassCaptain\Security\SignedQr::sign(). */
function qr(claims, keypair) {
	const c = Object.assign({ t: 'a'.repeat(32), o: 7, v: [nowS() - 3600, nowS() + 3600], k: 'q1', c: sha(CODE_OK).slice(0, 16) }, claims || {});
	const body = 'PC1.' + b64u(Buffer.from(JSON.stringify(c)));
	return body + '.' + b64u(nacl.sign.detached(Buffer.from(body), (keypair || QR_KEY).secretKey));
}

test('verifySignedQr: valid, tampered, wrong key id, expired key, window', async () => {
	const env = await boot({ indexedDB: await pairedDb({ qr_keys: QR_KEYS }), navigator: { onLine: false } });
	const { verifySignedQr, signedCode } = env.app;
	const good = qr();
	assert.equal(signedCode('Ticket: ' + good + ' '), good, 'signed payload is found in a scanned value');
	assert.equal(signedCode(CODE_OK), '');
	const ok = verifySignedQr(good);
	assert.equal(ok.ok, true);
	assert.equal(ok.claims.o, 7);
	// Tampered claims (occurrence changed, signature kept).
	const parts = good.split('.');
	const forged = 'PC1.' + b64u(Buffer.from(JSON.stringify(Object.assign(JSON.parse(Buffer.from(parts[1], 'base64').toString()), { o: 8 })))) + '.' + parts[2];
	assert.equal(verifySignedQr(forged).result, 'invalid_signature', 'tampered payload');
	assert.equal(verifySignedQr(parts[0] + '.' + parts[1] + '.' + b64u(new Uint8Array(64))).result, 'invalid_signature', 'zero signature');
	assert.equal(verifySignedQr(qr({ k: 'zz' })).result, 'invalid_signature', 'unknown key id');
	assert.equal(verifySignedQr(qr({}, QR_OTHER)).result, 'invalid_signature', 'signed with another key under key id q1');
	assert.equal(verifySignedQr(qr({ k: 'q0' }, QR_OTHER)).result, 'invalid_signature', 'retired key after its grace period');
	assert.equal(verifySignedQr(qr({ v: [nowS() - 7200, nowS() - 60] })).result, 'out_of_window', 'expired window');
	assert.equal(verifySignedQr(qr({ v: [nowS() + 3600, nowS() + 7200] })).result, 'out_of_window', 'not yet valid');
	assert.equal(verifySignedQr(qr({ c: 'XYZ' })).result, 'invalid_signature', 'malformed code version');
	assert.equal(verifySignedQr('PC1.abc.def').result, 'invalid_signature', 'garbage');
});

test('decideSigned: package lookup by code version, occurrence, revoked and rotated codes', async () => {
	const env = await boot({ indexedDB: await pairedDb({ qr_keys: QR_KEYS }), navigator: { onLine: false } });
	const { loadPackage, decideSigned } = env.app;
	const entry = loadPackage({ occurrence_id: 7, full: sign(payload()), deltas: [] });
	const d = decideSigned(entry, qr());
	assert.equal(d.result, 'checked_in');
	assert.equal(d.hash, sha(CODE_OK), 'the matched package hash is used for the local log');
	assert.equal(decideSigned(entry, qr({ o: 8 })).result, 'wrong_occurrence');
	assert.equal(decideSigned(entry, qr({ c: sha(CODE_REVOKED).slice(0, 16) })).result, 'revoked');
	assert.equal(decideSigned(entry, qr({ c: sha('8'.repeat(64)).slice(0, 16) })).result, 'unknown', 'code version of a reissued (old) code');
	assert.equal(decideSigned(entry, qr({ v: [nowS() - 7200, nowS() - 60] })).result, 'out_of_window');
	assert.equal(decideSigned(null, qr()).result, 'no_package');
});

test('offline scan of a signed QR is admitted locally and uploaded with the signed code', async () => {
	const indexedDB = await pairedDb({ qr_keys: QR_KEYS });
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(payload()), deltas: [] });
	const env = await boot({ indexedDB, navigator: { onLine: false } });
	const code = qr();
	await env.app.handleScan(code);
	await flush();
	assert.equal(text(env, 'result-title'), 'Einlass OK (offline)');
	const log = env.db.dump('log').map(([, v]) => v);
	assert.equal(log[0].code, code, 'signed code kept for the upload');
	assert.equal(log[0].hash, sha(CODE_OK));
	// The classic code of the same ticket is now refused locally too (same package entry).
	await env.app.handleScan(CODE_OK);
	await flush();
	assert.equal(text(env, 'result-title'), 'Bereits verwendet');
	await env.app.handleScan(qr({}, QR_OTHER));
	await flush();
	assert.equal(text(env, 'result-title'), 'QR-Signatur ungültig');
});

test('keys endpoint: qr_keys are stored for offline verification', async () => {
	const indexedDB = await pairedDb();
	const routes = {
		'offline/keys': () => ({ json: { keys: KEYS, qr_keys: [{ alg: 'Ed25519', key_id: 'q1', public_key: b64(QR_KEY.publicKey), status: 'active' }] } }),
		'offline/occurrences': () => ({ json: { occurrences: [] } }), 'scan/sync': () => ({ json: { results: [] } }), 'offline/i18n': () => ({ status: 404 }),
	};
	const env = await boot({ indexedDB, routes });
	await flush(40);
	assert.deepEqual(Object.keys(env.app.state.qrKeys), ['q1']);
	assert.ok(env.db.stores.kv.data.get('qr_keys').q1);
});

test('offline scan goes to the IndexedDB queue with the code; sync uploads it, clears the code and shows conflicts', async () => {
	const indexedDB = await pairedDb();
	const record = { occurrence_id: 7, full: sign(payload()), deltas: [] };
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, record);
	const env = await boot({ indexedDB, navigator: { onLine: false } });
	const app = env.app;
	assert.equal(env.document.getElementById('main-view').hidden, false, 'paired device opens the scan view');
	assert.equal(app.state.selected, 7);
	assert.match(text(env, 'pkg'), /4 Tickets im Paket · signiert \(Ed25519, Schlüssel k1\)/);

	await app.handleScan('https://example.test/?wpcap_ticket=' + CODE_OK.toUpperCase());
	await flush();
	assert.equal(text(env, 'result-title'), 'Einlass OK (offline)');
	assert.equal(text(env, 'result-detail'), 'Standard · Alex');
	await app.handleScan(CODE_REVOKED);
	await flush();
	assert.equal(text(env, 'result-title'), 'Widerrufen');
	await app.handleScan('kein code');
	assert.equal(text(env, 'result-title'), 'Kein Ticketcode erkannt');

	let log = env.db.dump('log').map(([, v]) => v);
	assert.equal(log.length, 2);
	const admitted = log.find((e) => e.ok);
	assert.equal(admitted.synced, 0);
	assert.equal(admitted.code, CODE_OK, 'code kept until the upload');
	assert.equal(admitted.mode, 'offline');
	assert.equal(admitted.hash, sha(CODE_OK));
	assert.equal(log.find((e) => !e.ok).code, '', 'rejected scans keep no code');
	assert.equal(text(env, 'pending'), '1 offen');

	// Same code again within the session: refused locally (admitted on this device).
	await app.handleScan(CODE_OK);
	await flush();
	assert.equal(text(env, 'result-title'), 'Bereits verwendet');

	// Back online: sync uploads only pending scans.
	const routes = { 'scan/sync': (body) => ({ json: { results: body.scans.map((s) => ({ idempotency_key: s.idempotency_key, ok: false, result: 'already_checked_in', conflict: true, conflict_id: 42 })) } }) };
	const online = await boot({ indexedDB, routes: Object.assign({ 'offline/keys': () => ({ json: { keys: KEYS } }), 'offline/occurrences': () => ({ json: { occurrences: [] } }), 'offline/i18n': () => ({ status: 404 }) }, routes) });
	await online.app.syncNow();
	await flush();
	const syncCall = online.fetch.calls.find((c) => c.url === API + 'scan/sync');
	assert.ok(syncCall, 'sync request sent');
	assert.equal(syncCall.init.headers.Authorization, 'Bearer tok');
	assert.equal(syncCall.body.scans.length, 1);
	assert.equal(syncCall.body.scans[0].code, CODE_OK);
	log = online.db.dump('log').map(([, v]) => v);
	const synced = log.find((e) => e.ok);
	assert.equal(synced.synced, 1);
	assert.equal(synced.code, '', 'code removed after the upload');
	assert.deepEqual({ conflict: synced.server.conflict, id: synced.server.conflict_id }, { conflict: true, id: 42 });
	assert.equal(text(online, 'pending'), '0 offen');
	assert.match(online.document.getElementById('conflicts').textContent, /zur Prüfung \(#42\)/);
});

test('online scan: server decides, log entry is marked synced without a code', async () => {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(payload()), deltas: [] });
	const routes = {
		'scan/check-in': (body) => ({ json: { ok: true, result: 'checked_in', ticket: 'Standard', attendee_name: 'Alex' } }),
		'offline/keys': () => ({ json: { keys: KEYS } }), 'offline/occurrences': () => ({ json: { occurrences: [{ occurrence_id: 7, event: 'Konzert', starts_at: '2026-12-01T19:00:00Z' }] } }),
		'offline/package': () => ({ json: sign(payload()) }), 'scan/sync': () => ({ json: { results: [] } }), 'offline/i18n': () => ({ status: 404 }),
	};
	const env = await boot({ indexedDB, routes });
	await env.app.handleScan(CODE_OK);
	await flush();
	const call = env.fetch.calls.find((c) => c.url === API + 'scan/check-in');
	assert.equal(call.body.code, CODE_OK);
	assert.equal(call.body.occurrence_id, 7);
	assert.ok(call.body.idempotency_key);
	assert.equal(text(env, 'result-title'), 'Einlass OK');
	assert.equal(text(env, 'result-detail'), 'Standard · Alex · online');
	const entry = env.db.dump('log').map(([, v]) => v)[0];
	assert.deepEqual({ synced: entry.synced, code: entry.code, mode: entry.mode }, { synced: 1, code: '', mode: 'online' });
});

test('server outage during an online scan falls back to the offline decision', async () => {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(payload()), deltas: [] });
	const env = await boot({ indexedDB, routes: { 'scan/check-in': () => ({ status: 503, json: {} }), 'offline/keys': () => ({ status: 503 }), 'offline/i18n': () => ({ status: 404 }) } });
	await env.app.handleScan(CODE_OK);
	await flush();
	assert.equal(text(env, 'result-title'), 'Einlass OK (offline)');
	assert.equal(env.db.dump('log').map(([, v]) => v)[0].synced, 0);
});

test('revoked device token (401) asks for re-pairing', async () => {
	const indexedDB = await pairedDb();
	const env = await boot({ indexedDB, routes: { 'offline/keys': () => ({ status: 401, json: { message: 'revoked' } }), 'offline/i18n': () => ({ status: 404 }) } });
	assert.equal(text(env, 'result-title'), 'Gerät nicht mehr gekoppelt');
});
