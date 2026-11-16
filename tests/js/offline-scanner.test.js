'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const { load, flush, mockFetch, MemoryStorage, ROOT } = require('./lib/load');
const { fakeIndexedDB } = require('./lib/idb');

const nacl = require(path.join(ROOT, 'assets/vendor/tweetnacl/nacl-fast.min.js'));
const KEYPAIR = nacl.sign.keyPair.fromSeed(new Uint8Array(32).fill(7));
const OTHER = nacl.sign.keyPair.fromSeed(new Uint8Array(32).fill(9));
const b64 = (bytes) => Buffer.from(bytes).toString('base64');
const API = 'https://example.test/wp-json/pass-captain/v1/app/'; // merged REST namespace (wp-pass-captain/v1 stays accepted as legacy)
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
	const fetch = opts.rawFetch || mockFetch((url, init, body) => {
		const route = url.replace(API, '').split('?')[0];
		const handler = routes[route];
		if (!handler) return { status: 404, json: { message: 'no route ' + route } };
		return handler(body, url, init);
	});
	const env = load('assets/offline-scanner/app.js', {
		url: opts.url || PAGE,
		indexedDB,
		navigator: Object.assign({ onLine: true }, opts.navigator || {}),
		localStorage: opts.localStorage,
		window: Object.assign({ nacl, indexedDB, wpcapUnused: 1 }, opts.window || {}),
		fetch,
		setup: (doc, h) => { page(doc, h); if (opts.setup) opts.setup(doc, h); },
	});
	env.fetch = fetch;
	env.app = env.window.wpcapOffline;
	env.idb = indexedDB;
	await flush(40);
	// Merged (A473): every site has its own database (storeName()); the pre-A473 one serves its own site only.
	env.db = indexedDB.dbs[env.app.storeName()] || indexedDB.dbs['wpcap-offline-scanner'];
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

test('white label: brand from the i18n table sets title, manifest and logo; without brand nothing changes', async () => {
	const withManifest = (doc, h) => { doc.head.appendChild(h('link', { rel: 'manifest', href: 'manifest.json' })); };
	const plain = await boot({ setup: withManifest, routes: { 'offline/i18n': () => ({ json: { v: 1, lang: 'de-DE', strings: {}, brand: { name: 'Pass Captain', logo: '', custom: false } } }) } });
	assert.equal(plain.document.title, 'Offline-Scanner · Pass Captain');
	assert.equal(plain.document.querySelector('link[rel="manifest"]').getAttribute('href'), 'manifest.json');
	assert.equal(plain.document.getElementById('brand-logo'), null);

	const table = { v: 1, lang: 'de-DE', strings: {}, brand: { name: 'Tixx', logo: '/wp-content/uploads/tixx.png', custom: true } };
	const env = await boot({ setup: withManifest, routes: { 'offline/i18n': () => ({ json: table }) } });
	assert.equal(env.document.title, 'Offline-Scanner · Tixx');
	assert.equal(env.document.querySelector('link[rel="manifest"]').getAttribute('href'), API + 'offline/manifest');
	const logo = env.document.getElementById('brand-logo');
	assert.ok(logo, 'logo shown in the header');
	assert.equal(logo.getAttribute('src'), '/wp-content/uploads/tixx.png');
	assert.equal(logo.getAttribute('alt'), 'Tixx');

	const evil = await boot({ setup: withManifest, routes: { 'offline/i18n': () => ({ json: { v: 1, lang: 'de-DE', strings: {}, brand: { name: 'Tixx', logo: 'javascript:alert(1)', custom: true } } }) } });
	assert.equal(evil.document.getElementById('brand-logo'), null, 'non-http(s) logos are ignored');
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
	// The signed window is widened to the package's current occurrence dates (QA-2): an expired signed
	// window is refused only when the current occurrence is over as well.
	const past = loadPackage({ occurrence_id: 7, full: sign(payload({ occurrence: { id: 7, starts_at: new Date(Date.now() - 3 * 86400e3).toISOString(), ends_at: null } })), deltas: [] });
	assert.equal(decideSigned(past, qr({ v: [nowS() - 7200, nowS() - 60] })).result, 'out_of_window');
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
	// The classic code of the same ticket is now refused locally too (same package entry): admitted on
	// this device a moment ago, so yellow instead of red (audit R2 #6).
	await env.app.handleScan(CODE_OK);
	await flush();
	assert.equal(text(env, 'result-title'), 'Eben hier eingelassen (vor 0 s)');
	assert.match(text(env, 'result-detail'), /^Bereits verwendet/);
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

	// Same code again within the session: refused locally (admitted on this device just now: yellow).
	await app.handleScan(CODE_OK);
	await flush();
	assert.equal(text(env, 'result-title'), 'Eben hier eingelassen (vor 0 s)');
	assert.ok(env.document.getElementById('result').className.includes('warn'));

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

test('revoked device token (401) asks for re-pairing and wipes token, keys, packages and log (FE-1/PRIV-5)', async () => {
	const indexedDB = await pairedDb({ qr_keys: QR_KEYS, i18n: { v: 1, lang: 'de-DE', strings: {} } });
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(payload()), deltas: [] });
	env0.db.stores.log.data.set('k-pending', { key: 'k-pending', occurrence_id: 7, hash: sha(CODE_OK), code: CODE_OK, scanned_at: new Date().toISOString(), ok: true, synced: 0, mode: 'offline', decision: 'checked_in' });
	const env = await boot({ indexedDB, routes: { 'offline/keys': () => ({ status: 401, json: { code: 'wpcap_device_unauthenticated', message: 'revoked' } }), 'offline/i18n': () => ({ status: 404 }) } });
	await flush(40);
	assert.equal(text(env, 'result-title'), 'Gerät nicht mehr gekoppelt');
	const kv = env.db.stores.kv.data;
	for (const k of ['token', 'device_id', 'keys', 'qr_keys']) assert.equal(kv.get(k), undefined, k + ' removed');
	assert.equal(kv.get('api'), API, 'API base kept for re-pairing');
	assert.ok(kv.get('i18n'), 'translations kept');
	assert.equal(env.db.dump('packages').length, 0, 'packages (attendee names) removed');
	assert.equal(env.db.dump('log').length, 0, 'log (pending ticket codes) removed');
	assert.deepEqual(Object.keys(env.app.state.packages), []);
	assert.deepEqual(env.app.state.keys, {});
	assert.equal(env.document.getElementById('pair-view').hidden, false, 'pairing screen shown');
	assert.equal(env.document.getElementById('main-view').hidden, true);
	// Offline decisions are impossible afterwards.
	await env.app.handleScan(CODE_OK);
	await flush();
	assert.notEqual(text(env, 'result-title'), 'Einlass OK (offline)');
});

test('401 during a scan or the sync also wipes the device', async () => {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(payload()), deltas: [] });
	const env = await boot({ indexedDB, navigator: { onLine: false } });
	await env.app.handleScan(CODE_OK); // offline, queued
	await flush();
	assert.equal(env.db.dump('log').length, 1, 'scan queued offline');
	const online = await boot({ indexedDB, routes: { 'offline/keys': () => ({ status: 503 }), 'scan/sync': () => ({ status: 401, json: {} }), 'offline/i18n': () => ({ status: 404 }) } });
	await online.app.syncNow();
	await flush(40);
	assert.equal(online.db.stores.kv.data.get('token'), undefined);
	assert.equal(online.db.dump('log').length, 0);
	assert.equal(online.db.dump('packages').length, 0);
});

/* ---- audit 2026-11-15: QA-2, FE-1…FE-5, FE-8 ---- */

test('QA-2: signed QR of a moved occurrence (+30 days) is accepted offline shortly before the new start; forged stays refused', async () => {
	const env = await boot({ indexedDB: await pairedDb({ qr_keys: QR_KEYS }), navigator: { onLine: false } });
	const { loadPackage, decideSigned, verifySignedQr } = env.app;
	const day = 86400;
	const oldStart = nowS() - 30 * day + 3600; // the original date lies 30 days back
	const newStart = nowS() + 3600; // moved by 30 days: begins in an hour
	const code = qr({ v: [oldStart - 365 * day, oldStart + day] }); // window signed for the old date
	const entry = loadPackage({ occurrence_id: 7, full: sign(payload({ occurrence: { id: 7, starts_at: new Date(newStart * 1000).toISOString(), ends_at: null } })), deltas: [] });
	assert.equal(verifySignedQr(code).result, 'out_of_window', 'signed window alone has expired');
	const d = decideSigned(entry, code);
	assert.equal(d.result, 'checked_in', 'current occurrence dates from the package widen the window');
	assert.equal(d.hash, sha(CODE_OK));
	// A package of another occurrence does not widen the window of this QR.
	assert.equal(verifySignedQr(code, undefined, { id: 8, starts_at: new Date(newStart * 1000).toISOString() }).result, 'out_of_window');
	// Manipulated signature: still refused.
	const parts = code.split('.');
	assert.equal(decideSigned(entry, parts[0] + '.' + parts[1] + '.' + ('A' === parts[2][0] ? 'B' : 'A') + parts[2].slice(1)).result, 'invalid_signature');
	assert.equal(decideSigned(entry, qr({ v: [oldStart - 365 * day, oldStart + day] }, QR_OTHER)).result, 'invalid_signature');
	// Over a day after the (current) end: refused.
	const over = loadPackage({ occurrence_id: 7, full: sign(payload({ occurrence: { id: 7, starts_at: new Date((nowS() - 3 * day) * 1000).toISOString(), ends_at: null } })), deltas: [] });
	assert.equal(decideSigned(over, code).result, 'out_of_window');
});

test('FE-1/PRIV-5: expired packages are removed at start, valid ones stay', async () => {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(payload()), deltas: [] });
	env0.db.stores.packages.data.set(8, { occurrence_id: 8, full: sign(payload({ occurrence: { id: 8, starts_at: '2026-12-01T19:00:00Z' }, expires_at: new Date(Date.now() - 1000).toISOString() })), deltas: [] });
	const env = await boot({ indexedDB, navigator: { onLine: false } });
	assert.deepEqual(env.db.dump('packages').map(([k]) => k), [7]);
	assert.deepEqual(Object.keys(env.app.state.packages), ['7']);
	// Expiry later on: the periodic pruning removes it as well.
	env.app.state.packages[7].meta.expires_at = new Date(Date.now() - 1).toISOString();
	assert.deepEqual(await env.app.pruneExpired(), [7]);
	await flush();
	assert.equal(env.db.dump('packages').length, 0);
});

test('FE-3: ?api= is used only by an unpaired device and only for the plugin namespace on the same origin', async () => {
	const { validApiBase } = (await boot({ navigator: { onLine: false } })).app;
	assert.equal(validApiBase(API), true);
	assert.equal(validApiBase('https://example.test/index.php?rest_route=/wp-pass-captain/v1/app/'), true);
	// Merged (A649, stricter): only the /wp-json/ prefix or the plain ?rest_route= form; a custom REST prefix is refused.
	assert.equal(validApiBase('https://example.test/custom-rest/wp-pass-captain/v1/app/'), false, 'other REST prefix');
	assert.equal(validApiBase('https://example.test/wp-json/wp-pass-captain/v1/app/'), true, 'legacy namespace');
	assert.equal(validApiBase('https://example.test/index.php?rest_route=/pass-captain/v1/app/'), true);
	assert.equal(validApiBase('https://evil.test/wp-json/wp-pass-captain/v1/app/'), false, 'other origin');
	assert.equal(validApiBase('https://example.test/wp-json/wp/v2/users/'), false, 'other namespace');
	assert.equal(validApiBase('https://example.test/upload.php?x=/wp-pass-captain/v1/app/'), false);
	assert.equal(validApiBase('https://example.test/?rest_route=/wp-pass-captain/v1/app/&x=1'), false);
	assert.equal(validApiBase('https://example.test/wp-json/wp-pass-captain/v1/app/#x'), false);
	assert.equal(validApiBase(''), false);

	const plain = 'https://example.test/index.php?rest_route=/wp-pass-captain/v1/app/';
	const fresh = await boot({ url: PAGE + '?api=' + encodeURIComponent(plain), navigator: { onLine: false } });
	assert.equal(fresh.app.state.api, plain, 'unpaired: parameter adopted');
	assert.equal(fresh.db.stores.kv.data.get('api'), plain);
	const bad = await boot({ url: PAGE + '?api=' + encodeURIComponent('https://example.test/wp-admin/admin-ajax.php?'), navigator: { onLine: false } });
	assert.equal(bad.app.state.api, API, 'unpaired, wrong path: derived API base');

	const indexedDB = await pairedDb();
	// Merged (A473): ?api= names a site, and every site has its own database. A link to another base on a
	// paired device opens that site unpaired – the pairing (token) of the first site never goes there.
	const paired = await boot({ indexedDB, url: PAGE + '?api=' + encodeURIComponent(plain), navigator: { onLine: false } });
	assert.equal(paired.app.state.api, plain, 'the linked site is opened');
	assert.notEqual(paired.app.storeName(), 'wpcap-offline-scanner', 'in its own database');
	assert.equal(paired.db.stores.kv.data.get('token'), undefined, 'unpaired there: no token of the first site');
	assert.equal(paired.document.getElementById('pair-view').hidden, false);
	const first = Object.values(indexedDB.dbs).find((d) => d.stores.kv && 'tok' === d.stores.kv.data.get('token'));
	assert.ok(first && API === first.stores.kv.data.get('api'), 'the paired site keeps its base');
	assert.notEqual(first, paired.db, 'and its pairing, in its own database');
});

test('FE-4: the IndexedDB log keeps pending scans, drops synced entries after a day and above the cap', async () => {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	const log = env0.db.stores.log.data;
	const at = (h) => new Date(Date.now() - h * 3600e3).toISOString();
	const entry = (key, h, synced) => ({ key, occurrence_id: 7, hash: sha(key), code: synced ? '' : CODE_OK, scanned_at: at(h), ok: true, synced, mode: 'offline', decision: 'checked_in' });
	log.set('old-synced', entry('old-synced', 30, 1));
	log.set('old-pending', entry('old-pending', 30, 0));
	log.set('new-synced', entry('new-synced', 1, 1));
	for (let i = 0; i < 520; i++) log.set('bulk-' + i, entry('bulk-' + i, 2 + i / 1000, 1));
	const env = await boot({ indexedDB, navigator: { onLine: false } });
	await flush(20);
	const keys = env.db.dump('log').map(([k]) => k);
	assert.ok(!keys.includes('old-synced'), 'synced entry older than a day removed');
	assert.ok(keys.includes('old-pending'), 'pending scans are never removed');
	assert.ok(keys.includes('new-synced'), 'newest synced entries stay');
	assert.equal(keys.filter((k) => k !== 'old-pending').length, 500, 'synced entries capped');
	assert.ok(!keys.includes('bulk-519'), 'the oldest synced entries go first');
});

test('FE-5: older packages are refused (rollback); expiry uses the last server time as lower bound', async () => {
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false } });
	const { loadPackage, packageStatus, state } = env.app;
	const at = (ms) => new Date(Date.now() + ms).toISOString();
	const newer = loadPackage({ occurrence_id: 7, full: sign(payload({ cursor: 'ab12.5', issued_at: at(-1000) })), deltas: [] });
	assert.equal(newer.error, '');
	await flush();
	assert.deepEqual(env.db.stores.kv.data.get('hw')[7], { cursor: 'ab12.5', issued_at: newer.meta.issued_at }, 'high-water mark stored');
	const older = loadPackage({ occurrence_id: 7, full: sign(payload({ cursor: 'ab12.6', issued_at: at(-60000) })), deltas: [] });
	assert.match(older.error, /Kein gültiges Prüfpaket/, 'older issued_at refused');
	const lowerCursor = loadPackage({ occurrence_id: 7, full: sign(payload({ cursor: 'ab12.3', issued_at: newer.meta.issued_at })), deltas: [] });
	assert.match(lowerCursor.error, /Kein gültiges Prüfpaket/, 'lower cursor of the same epoch refused');
	const rebuilt = loadPackage({ occurrence_id: 7, full: sign(payload({ cursor: 'ffee.1', issued_at: at(0) })), deltas: [] });
	assert.equal(rebuilt.error, '', 'newer package of a new epoch accepted');

	// The device clock is set back by two hours after the last server contact: the server time wins.
	const pkg = loadPackage({ occurrence_id: 7, full: sign(payload({ issued_at: at(1000), expires_at: at(3600e3) })), deltas: [] });
	assert.equal(packageStatus(pkg).ok, true);
	state.clockOffset = -2 * 3600e3;
	state.serverTime = Date.now() + 2 * 3600e3; // last server_time seen
	assert.equal(packageStatus(pkg).code, 'package_expired');
});

test('FE-5: server_time of the keys response is stored as time floor', async () => {
	const indexedDB = await pairedDb();
	const serverTime = new Date(Date.now() + 3 * 3600e3).toISOString();
	const routes = { 'offline/keys': () => ({ json: { keys: KEYS, server_time: serverTime } }), 'offline/occurrences': () => ({ json: { occurrences: [] } }), 'scan/sync': () => ({ json: { results: [] } }), 'offline/i18n': () => ({ status: 404 }) };
	const env = await boot({ indexedDB, routes });
	await flush(40);
	assert.equal(env.db.stores.kv.data.get('server_time'), Date.parse(serverTime));
	const again = await boot({ indexedDB, navigator: { onLine: false } });
	assert.equal(again.app.state.serverTime, Date.parse(serverTime));
});

test('FE-8: the exposed state hides the device token', async () => {
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false } });
	const s = env.app.state;
	assert.equal(s.token, undefined);
	assert.equal('token' in s, false);
	assert.ok(!Object.keys(s).includes('token'));
	assert.ok(!JSON.stringify(s).includes('"token"'));
	s.token = 'evil';
	assert.equal(s.token, undefined, 'not writable through the view');
	assert.equal(s.deviceId, DEVICE, 'other fields readable');
});

/* ---- audit R2 (2026-11-15), Experte #6: scan feedback, retry, compact packages, local entry counters ---- */

/** Paired device with the default package stored; returns the IndexedDB for boot(). */
async function withPackage(p) {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(p || payload()), deltas: [] });
	return indexedDB;
}
const ONLINE_ROUTES = { 'offline/keys': () => ({ status: 503 }), 'offline/i18n': () => ({ status: 404 }) };

test('R2: a scan shows the neutral "Prüfe …" state at once, the result returns to "Bereit" (6 s), the same code is locked for 10 s', async () => {
	let release;
	const gate = new Promise((r) => { release = r; });
	const env = await boot({ indexedDB: await withPackage(), routes: Object.assign({ 'scan/check-in': () => gate.then(() => ({ json: { ok: true, result: 'checked_in', ticket: 'Standard' } })) }, ONLINE_ROUTES) });
	await flush();
	const done = env.app.handleScan(CODE_OK);
	await flush(5);
	assert.equal(text(env, 'result-title'), 'Prüfe …', 'neutral state while the request runs');
	assert.ok(env.document.getElementById('result').className.includes('pending'));
	release();
	await done;
	await flush();
	assert.equal(text(env, 'result-title'), 'Einlass OK');
	// Merged (A548): green stays 6 s in the result box (overlay 2.5 s), then back to "Bereit".
	const hold = [...env.timers.pending.values()].find((tm) => env.app.holdTimes('ok').reset === tm.ms && 6000 === tm.ms);
	assert.ok(hold, 'result is held for 6 s');
	env.timers.run();
	assert.equal(text(env, 'result-title'), 'Bereit', 'back to ready for the next guest');
	assert.ok(env.document.getElementById('result').className.includes('idle'));
	const calls = env.fetch.calls.filter((c) => c.url.endsWith('scan/check-in')).length;
	await env.app.handleScan(CODE_OK);
	await flush();
	assert.equal(env.fetch.calls.filter((c) => c.url.endsWith('scan/check-in')).length, calls, 'same code within 10 s: not sent again');
	assert.equal(text(env, 'result-title'), 'Bereit', 'and no red "already used" over the green result');
});

test('R2: server says "already used" for a code this device admitted a moment ago: yellow, not red', async () => {
	let n = 0;
	const routes = Object.assign({ 'scan/check-in': (body) => ({ json: body.code === CODE_OK && n++ > 0 ? { ok: false, code: 'wpcap_already_checked_in', result: 'already_checked_in', ticket: 'Standard' } : { ok: true, result: 'checked_in', ticket: 'Standard' } }) }, ONLINE_ROUTES);
	const env = await boot({ indexedDB: await withPackage(), routes });
	await env.app.handleScan(CODE_OK);
	await env.app.handleScan(CODE_VIP);
	await env.app.handleScan(CODE_OK);
	await flush();
	assert.equal(text(env, 'result-title'), 'Eben hier eingelassen (vor 0 s)');
	assert.ok(env.document.getElementById('result').className.includes('warn'));
	assert.match(text(env, 'result-detail'), /Bereits verwendet · Standard · online/);
	// A code admitted elsewhere stays red.
	routes['scan/check-in'] = () => ({ json: { ok: false, code: 'wpcap_already_checked_in', result: 'already_checked_in' } });
	await env.app.handleScan(CODE_USED);
	await flush();
	assert.equal(text(env, 'result-title'), 'Bereits verwendet');
	assert.ok(env.document.getElementById('result').className.includes('bad'));
});

test('R2: a database retry (HTTP 503, or HTTP 200 result "retry" of an older server) falls back to the offline decision', async () => {
	for (const reply of [{ status: 503, json: { code: 'wpcap_checkin_retry', data: { status: 503, retryable: true, result: 'retry' } } }, { json: { ok: false, result: 'retry' } }]) {
		const env = await boot({ indexedDB: await withPackage(), routes: Object.assign({ 'scan/check-in': () => reply }, ONLINE_ROUTES) });
		await env.app.handleScan(CODE_OK);
		await flush();
		assert.equal(text(env, 'result-title'), 'Einlass OK (offline)');
		const log = env.db.dump('log').map(([, v]) => v);
		assert.equal(log.length, 1);
		assert.deepEqual({ synced: log[0].synced, mode: log[0].mode, code: log[0].code }, { synced: 0, mode: 'offline', code: CODE_OK }, 'kept for the upload, not logged as synced');
	}
});

/** Ticket types with admission policies for the local counters. */
function policyPayload(extra) {
	const today = new Date().toISOString().slice(0, 10);
	return payload(Object.assign({
		occurrence: { id: 7, starts_at: new Date().toISOString(), timezone: 'UTC' }, day_cutoff_hour: 0,
		ticket_types: [
			{ id: 1, name: 'Standard', multi: false, areas: [], policy: 'single', max_entries: 1, valid_days: null },
			{ id: 4, name: 'Tagesticket', multi: true, areas: [], policy: 'daily', max_entries: 0, valid_days: [today] },
			{ id: 5, name: '2x', multi: true, areas: [], policy: 'multi_entry', max_entries: 2, valid_days: null },
			{ id: 6, name: 'Re-Entry', multi: true, areas: [], policy: 'reentry', max_entries: 0, valid_days: null },
			{ id: 7, name: 'Gestern', multi: true, areas: [], policy: 'multi_day', max_entries: 0, valid_days: ['2001-01-01'] },
		],
		tickets: [
			{ h: sha(CODE_OK), s: 'valid', t: 1 }, { h: sha('a'.repeat(64)), s: 'valid', t: 4 }, { h: sha('b'.repeat(64)), s: 'valid', t: 5 },
			{ h: sha('c'.repeat(64)), s: 'valid', t: 6 }, { h: sha('d'.repeat(64)), s: 'valid', t: 7 },
		],
	}, extra || {}));
}

test('R2: offline multi-entry tickets are limited by local counters (daily once per day, multi_entry max, reentry inside)', async () => {
	const env = await boot({ indexedDB: await withPackage(policyPayload()), navigator: { onLine: false } });
	const scan = async (code) => { await env.app.handleScan(code); await flush(); return text(env, 'result-title'); };
	const DAILY = 'a'.repeat(64), MULTI = 'b'.repeat(64), RE = 'c'.repeat(64), DAY = 'd'.repeat(64);
	assert.equal(await scan(DAILY), 'Einlass OK (offline)');
	assert.equal(await scan(CODE_OK), 'Einlass OK (offline)');
	assert.equal(await scan(DAILY), 'Eben hier eingelassen (vor 0 s)', 'daily ticket: second entry today refused (yellow: just admitted here)');
	assert.match(text(env, 'result-detail'), /^Tageskontingent erreicht/);
	assert.equal(await scan(MULTI), 'Einlass OK (offline)');
	assert.equal(await scan(DAILY), 'Eben hier eingelassen (vor 0 s)');
	assert.equal(await scan(MULTI), 'Einlass OK (offline)', 'multi_entry: second of two entries');
	assert.equal(await scan(DAILY), 'Eben hier eingelassen (vor 0 s)');
	assert.equal(await scan(MULTI), 'Eben hier eingelassen (vor 0 s)', 'multi_entry: third entry refused');
	assert.match(text(env, 'result-detail'), /^Einlasslimit erreicht/);
	assert.equal(await scan(RE), 'Einlass OK (offline)');
	assert.equal(await scan(MULTI), 'Eben hier eingelassen (vor 0 s)');
	assert.equal(await scan(RE), 'Eben hier eingelassen (vor 0 s)', 'reentry: still inside (no check-out on this device)');
	assert.match(text(env, 'result-detail'), /^Bereits drinnen/);
	assert.equal(await scan(DAY), 'Heute nicht gültig', 'multi_day ticket outside its days: red');
	const pending = env.db.dump('log').map(([, v]) => v).filter((e) => !e.synced);
	assert.equal(pending.length, 5, 'only the five admissions are queued for upload');

	// Counters survive a restart (rebuilt from the scan log); an older admission is red, not yellow.
	const again = await boot({ indexedDB: env.idb, navigator: { onLine: false } });
	const entry = again.app.state.packages[7];
	assert.equal(again.app.decideOffline(entry, sha(DAILY)).result, 'daily_limit_reached');
	assert.equal(again.app.decideOffline(entry, sha(MULTI)).result, 'entry_limit_reached');
	again.app.state.local[7].get(sha(MULTI).slice(0, 32)).ins = [Date.now() - 3600e3, Date.now() - 3500e3];
	const old = again.app.decideOffline(entry, sha(MULTI));
	assert.equal(old.result, 'entry_limit_reached');
	assert.equal(old.recent, undefined, 'admissions older than 5 minutes are not "just now"');
});

test('R2: service day honours the occurrence timezone and the day cutoff hour', async () => {
	const { serviceDay } = (await boot({ navigator: { onLine: false } })).app;
	const at = Date.parse('2026-12-01T23:30:00Z');
	assert.equal(serviceDay(at, 'UTC', 0), '2026-12-01');
	assert.equal(serviceDay(at, 'Europe/Vienna', 0), '2026-12-02', '00:30 local time is the next day');
	assert.equal(serviceDay(at, 'Europe/Vienna', 6), '2026-12-01', 'before the cutoff (06:00) it still counts to the previous day');
	assert.equal(serviceDay(at, 'Not/AZone', 0), '2026-12-01', 'invalid zone: UTC');
});

/** Format-2 payload: rows of 32-hex hash prefixes per type and status digit. */
function compactPayload(extra) {
	const p = payload(Object.assign({ v: 2, hash_prefix: 32 }, extra || {}));
	delete p.tickets;
	const pre = (code) => sha(code).slice(0, 32);
	p.rows = p.rows || [[1, 1, pre(CODE_OK)], [1, 2, pre(CODE_USED)], [2, 1, pre(CODE_VIP)], [3, 2, pre(CODE_MULTI)], [0, 0, pre(CODE_REVOKED)]];
	p.names = p.names || { [pre(CODE_OK)]: 'Alex' };
	return p;
}

test('R2: compact package format 2 is verified and decided like format 1', async () => {
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false } });
	const { loadPackage, decideOffline, verifyDoc } = env.app;
	const doc = sign(compactPayload());
	const parsed = verifyDoc(doc);
	assert.equal(parsed.tickets.length, 5);
	assert.deepEqual(parsed.tickets[0], { h: sha(CODE_OK).slice(0, 32), s: 'valid', t: 1, n: 'Alex' });
	const entry = loadPackage({ occurrence_id: 7, full: doc, deltas: [] });
	assert.equal(entry.error, '');
	assert.equal(decideOffline(entry, sha(CODE_OK)).result, 'checked_in', 'looked up with the full sha256 of the scanned code');
	assert.equal(decideOffline(entry, sha(CODE_OK)).ticket.n, 'Alex');
	assert.equal(decideOffline(entry, sha(CODE_USED)).result, 'already_checked_in');
	assert.equal(decideOffline(entry, sha(CODE_REVOKED)).result, 'revoked');
	assert.equal(decideOffline(entry, sha('9'.repeat(64))).result, 'unknown');
	// A v1 full package with a v2 delta (device updated meanwhile): the delta overrides by hash prefix.
	const delta = sign(compactPayload({ kind: 'delta', since: 'c1', cursor: 'c2', rows: [[0, 0, sha(CODE_OK).slice(0, 32)]], names: {} }));
	const mixed = loadPackage({ occurrence_id: 7, full: sign(payload()), deltas: [delta] });
	assert.equal(mixed.error, '');
	assert.equal(decideOffline(mixed, sha(CODE_OK)).result, 'revoked');
	assert.throws(() => verifyDoc(sign(compactPayload({ rows: [[1, 1, 'xyz']] }))), /Paketformat/, 'malformed rows are refused');
	assert.throws(() => verifyDoc(sign(compactPayload({ hash_prefix: 16 }))), /Paketformat/);
});

test('R2: package requests ask for format 2 with a 120 s timeout; after MAX_DELTAS one compact delta since the full package replaces the chain', async () => {
	const indexedDB = await pairedDb();
	const env0 = await boot({ indexedDB, navigator: { onLine: false } });
	const full = payload({ cursor: 'ab12.1' });
	const deltas = [];
	let cursor = 'ab12.1';
	for (let i = 2; i <= 31; i++) { const next = 'ab12.' + i; deltas.push(sign(payload({ kind: 'delta', since: cursor, cursor: next, tickets: [] }))); cursor = next; }
	env0.db.stores.packages.data.set(7, { occurrence_id: 7, full: sign(full), deltas });
	const seen = [];
	const routes = {
		'offline/package': (b, url) => { seen.push(url); return { json: sign(payload({ kind: 'delta', since: 'ab12.1', cursor: 'ab12.40', tickets: [{ h: sha(CODE_OK), s: 'checked_in', t: 1 }] })) }; },
		'offline/i18n': () => ({ status: 404 }),
	};
	const env = await boot({ indexedDB, navigator: { onLine: false }, routes, window: { AbortController } });
	assert.equal(env.app.state.packages[7].record.deltas.length, 30);
	const pending = env.app.refreshPackage(7);
	const timeout = [...env.timers.pending.values()].find((tm) => 120000 === tm.ms);
	assert.ok(timeout, 'package download may take 120 s');
	await pending;
	await flush();
	assert.match(seen[0], /format=2/);
	assert.match(seen[0], /since=ab12\.1(&|$)/, 'compact delta since the full package cursor');
	const stored = env.db.stores.packages.data.get(7);
	assert.equal(stored.deltas.length, 1, 'chain replaced by the compact delta');
	assert.equal(env.app.state.packages[7].index.get(sha(CODE_OK)).s, 'checked_in');
	assert.equal(env.app.state.packages[7].meta.cursor, 'ab12.40');
});

test('R2: package download progress is shown while the body streams in', async () => {
	const body = JSON.stringify(sign(payload()));
	const bytes = Buffer.from(body);
	const seen = [];
	let env;
	const rawFetch = async (url) => {
		const route = url.replace(API, '').split('?')[0];
		if ('offline/package' !== route) return { ok: false, status: 404, json: async () => ({}) };
		let i = 0;
		const size = Math.ceil(bytes.length / 3);
		return { ok: true, status: 200, json: async () => JSON.parse(body), body: { getReader: () => ({ read: async () => {
			if (i > 0) seen.push(text(env, 'pkg'));
			if (i >= bytes.length) return { done: true };
			const chunk = new Uint8Array(bytes.subarray(i, i + size));
			i += size;
			return { done: false, value: chunk };
		} }) } };
	};
	env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false }, rawFetch });
	env.app.state.selected = 7;
	const entry = await env.app.refreshPackage(7);
	assert.equal(entry.error, '');
	assert.ok(seen.some((s) => /^Prüfpaket wird geladen … \d+ kB$/.test(s)), 'progress shown: ' + seen.join(' | '));
});

test('R2: result tones via WebAudio (high = OK, low = rejected), switchable and remembered', async () => {
	const tones = [];
	class FakeAudio {
		constructor() { this.currentTime = 0; this.destination = {}; this.state = 'running'; }
		createOscillator() { const o = { type: '', frequency: { value: 0 }, connect(n) { return n; }, start() { tones.push(o.frequency.value); }, stop() {} }; return o; }
		createGain() { return { gain: { value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect(n) { return n; } }; } // connect() returns its target (chained)
	}
	const localStorage = new MemoryStorage();
	const withSound = (doc, h) => { doc.getElementById('main-view').appendChild(h('button#sound')); doc.getElementById('main-view').appendChild(h('button#torch', { hidden: true })); };
	const indexedDB = await withPackage();
	const env = await boot({ indexedDB, localStorage, navigator: { onLine: false }, window: { AudioContext: FakeAudio }, setup: withSound });
	assert.equal(text(env, 'sound'), 'Ton an');
	await env.app.handleScan(CODE_OK);
	await flush();
	// Merged tones (A548): admitted = rising two-tone, rejected = low buzz.
	assert.deepEqual(tones, [660, 990], 'admitted: rising two-tone');
	await env.app.handleScan(CODE_REVOKED);
	await flush();
	assert.deepEqual(tones, [660, 990, 196, 147], 'rejected: low buzz');
	env.document.getElementById('sound').click();
	await flush();
	assert.equal(text(env, 'sound'), 'Ton aus');
	assert.equal(env.document.getElementById('sound').getAttribute('aria-pressed'), 'false');
	await env.app.handleScan(CODE_VIP);
	await flush();
	assert.equal(tones.length, 4, 'switched off: silent');
	assert.equal(localStorage.getItem('wpcap_scanner_sound'), 'off', 'merged: remembered per browser (localStorage)');
	const again = await boot({ indexedDB, localStorage, navigator: { onLine: false }, window: { AudioContext: FakeAudio }, setup: withSound });
	assert.equal(text(again, 'sound'), 'Ton aus');
});

test('R2: torch switch and continuous focus where the camera supports them', async () => {
	const applied = [];
	const track = { getCapabilities: () => ({ torch: true, focusMode: ['manual', 'continuous'] }), applyConstraints: async (c) => { applied.push(c); } };
	const env = await boot({ indexedDB: await pairedDb(), navigator: { onLine: false }, setup: (doc, h) => { doc.getElementById('main-view').appendChild(h('button#torch', { hidden: true })); } });
	env.app.setupTrack({ getVideoTracks: () => [track] });
	assert.equal(env.document.getElementById('torch').hidden, false, 'torch button shown');
	assert.deepEqual(applied[0], { advanced: [{ focusMode: 'continuous' }] });
	await env.app.toggleTorch();
	assert.deepEqual(applied[1], { advanced: [{ torch: true }] });
	assert.equal(env.document.getElementById('torch').getAttribute('aria-pressed'), 'true');
	env.app.setupTrack({ getVideoTracks: () => [{ getCapabilities: () => ({}) }] });
	assert.equal(env.document.getElementById('torch').hidden, true, 'no torch: hidden');
});
