'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, MemoryStorage, Event } = require('./lib/load');

const CODE = 'a'.repeat(64);
const CODE2 = 'b'.repeat(64);
const I18N = { queued: 'Offline gespeichert.', invalid: 'Ungültiges Ticket.', unchecked: 'NICHT GEPRÜFT (offline).', admitUnchecked: 'Nach Sichtprüfung einlassen', syncCount: '%d synchronisieren', synced: '%1$d übertragen, %2$d Konflikte', expired: '%d verworfen', checking: '…', noCamera: 'Keine Kamera.', cameraError: 'Kamerafehler:' };

/** Scanner shortcode markup (PublicEvents::render_scanner_shortcode). */
function scanner(opts) {
	opts = opts || {};
	const cfg = { i18n: I18N, checkinUrl: '/checkin', offlineSyncUrl: '/sync', nonce: 'n', jsqrUrl: '/jsqr.js' };
	globalThis.WPCAP_SCANNER = cfg; // the script reads the bare global (wp_localize_script)
	return load('assets/scanner.js', {
		window: Object.assign({ WPCAP_SCANNER: cfg, WPCAP_SCANNER_PRIVACY: { owner: opts.owner || 'u1hash', maxAgeHours: 24 } }, opts.window || {}),
		navigator: opts.navigator,
		localStorage: opts.localStorage,
		fetch: opts.fetch,
		setup(doc, h) {
			doc.body.appendChild(h('div.wpcap-scanner', [
				h('button.wpcap-camera-start', { type: 'button' }, 'Kamera starten'),
				h('video.wpcap-camera', { hidden: true }),
				h('form.wpcap-scan-form', [h('label', { for: 'tok' }, 'Ticketcode'), h('input#tok', { name: 'token' }), h('button', { type: 'submit' }, 'Prüfen')]),
				h('div.wpcap-scan-result', { role: 'status', 'aria-live': 'polite' }),
			]));
		},
	});
}
const result = (env) => env.document.querySelector('.wpcap-scan-result');
test.after(() => { delete globalThis.WPCAP_SCANNER; });

test('online scan: success text, input cleared', async () => {
	const fetch = mockFetch(() => ({ json: { status: 'checked_in', event: 'Konzert', ticket: 'Standard' } }));
	const env = scanner({ fetch });
	env.document.getElementById('tok').value = CODE;
	env.document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls[0].url, '/checkin');
	assert.deepEqual(fetch.calls[0].body, { token: CODE });
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], 'n');
	assert.equal(result(env).textContent, '✓ Konzert — Standard');
	assert.equal(result(env).dataset.state, 'success');
	assert.equal(env.document.getElementById('tok').value, '');
});

test('server rejection shows the server message', async () => {
	const env = scanner({ fetch: mockFetch(() => ({ status: 409, json: { message: 'Bereits eingecheckt' } })) });
	await env.exports.submitToken(CODE);
	assert.equal(result(env).textContent, 'Bereits eingecheckt');
	assert.equal(result(env).dataset.state, 'error');
});

test('offline: never admitted automatically, explicit "admit after visual check" queues the scan', () => {
	const env = scanner({ navigator: { onLine: false } });
	env.exports.offline('kein code');
	assert.equal(result(env).textContent, 'Ungültiges Ticket.');
	env.exports.offline('https://example.test/?wpcap_ticket=' + CODE);
	assert.match(result(env).textContent, /NICHT GEPRÜFT/);
	assert.equal(env.exports.queue().length, 0, 'nothing queued before the staff decision');
	result(env).querySelector('.wpcap-admit-unchecked').click();
	const q = env.exports.queue();
	assert.equal(q.length, 1);
	assert.match(q[0].idempotency_key, /^[0-9a-f-]{36}$/);
	assert.equal(q[0].direction, 'in');
	assert.equal(result(env).textContent, 'Offline gespeichert.');
	const sync = env.document.querySelectorAll('.wpcap-scanner > button').pop();
	assert.equal(sync.hidden, false);
	assert.equal(sync.textContent, '1 synchronisieren');
});

test('offline: a signed QR payload (PC1) is offered for the visual check like a classic code', () => {
	const env = scanner({ navigator: { onLine: false } });
	const signed = 'PC1.' + 'eyJ0IjoiYWJjIn0'.padEnd(40, 'A') + '.' + 'B'.repeat(86);
	env.exports.offline(signed);
	assert.match(result(env).textContent, /NICHT GEPRÜFT/);
	result(env).querySelector('.wpcap-admit-unchecked').click();
	assert.equal(env.exports.queue()[0].token, signed, 'the signed payload is queued unchanged for the server check');
	env.exports.offline('PC1.kurz.x');
	assert.equal(result(env).textContent, 'Ungültiges Ticket.');
});

test('network failure while "online" falls back to the offline flow', async () => {
	const env = scanner({ fetch: mockFetch(() => { throw new TypeError('Failed to fetch'); }) });
	await env.exports.submitToken(CODE);
	assert.match(result(env).textContent, /NICHT GEPRÜFT/);
});

test('queue is per user: other users\' and legacy queues are removed', () => {
	const storage = new MemoryStorage({
		'wpcap-offline-scans-v2-otheruser': JSON.stringify([{ token: CODE2 }]),
		'wpcap-scanner-owner': 'otheruser',
		'wpcap-offline-scans-v1': JSON.stringify([CODE2]),
	});
	const env = scanner({ owner: 'me', localStorage: storage });
	assert.equal(storage.getItem('wpcap-offline-scans-v2-otheruser'), null);
	assert.equal(storage.getItem('wpcap-offline-scans-v1'), null);
	assert.equal(storage.getItem('wpcap-scanner-owner'), 'me');
	assert.equal(env.exports.queueKey, 'wpcap-offline-scans-v2-me');
});

test('legacy v1 queue is adopted by the first user and normalized', () => {
	const storage = new MemoryStorage({ 'wpcap-offline-scans-v1': JSON.stringify([CODE, { token: CODE2, scanned_at: new Date().toISOString() }, { nope: 1 }]) });
	const env = scanner({ owner: 'first', localStorage: storage });
	const q = env.exports.queue();
	assert.equal(q.length, 2);
	assert.equal(q[0].token, CODE);
	assert.ok(q[0].idempotency_key && q[0].stored_at);
});

test('expired queue items (older than maxAgeHours) are dropped with a visible warning', () => {
	const old = Date.now() - 25 * 3600 * 1000;
	const storage = new MemoryStorage({ 'wpcap-scanner-owner': 'u1hash', 'wpcap-offline-scans-v2-u1hash': JSON.stringify([{ token: CODE, idempotency_key: 'k1', stored_at: old }, { token: CODE2, idempotency_key: 'k2', stored_at: Date.now() }]) });
	const env = scanner({ localStorage: storage });
	assert.equal(env.exports.queue().length, 1);
	assert.equal(result(env).textContent, '1 verworfen');
	assert.equal(result(env).dataset.state, 'error');
});

test('sync uploads in chunks and removes exactly the confirmed items', async () => {
	const items = Array.from({ length: 150 }, (_, i) => ({ token: CODE, idempotency_key: 'k' + i, stored_at: Date.now() }));
	const storage = new MemoryStorage({ 'wpcap-scanner-owner': 'u1hash', 'wpcap-offline-scans-v2-u1hash': JSON.stringify(items) });
	let env;
	const fetch = mockFetch((url, init, body) => {
		if (body.tokens.length === 50) env.exports.enqueue(CODE2); // scanned while the last chunk is in flight
		return { json: { results: body.tokens.map((t, i) => ({ status: i === 0 ? 'conflict' : 'ok' })) } };
	});
	env = scanner({ localStorage: storage, fetch });
	await env.exports.syncQueue();
	assert.deepEqual(fetch.calls.map((c) => c.body.tokens.length), [100, 50]);
	assert.equal(fetch.calls[0].url, '/sync');
	assert.equal(result(env).textContent, '150 übertragen, 2 Konflikte');
	const left = env.exports.queue();
	assert.equal(left.length, 1, 'the scan queued during the sync stays');
	assert.equal(left[0].token, CODE2);
});

test('failed sync keeps the queue', async () => {
	const storage = new MemoryStorage({ 'wpcap-scanner-owner': 'u1hash', 'wpcap-offline-scans-v2-u1hash': JSON.stringify([{ token: CODE, idempotency_key: 'k', stored_at: Date.now() }]) });
	const env = scanner({ localStorage: storage, fetch: mockFetch(() => ({ status: 500, json: { message: 'Serverfehler' } })) });
	await env.exports.syncQueue();
	assert.equal(result(env).textContent, 'Serverfehler');
	assert.equal(env.exports.queue().length, 1);
});

test('admission module: area/mode options and idempotent check-out', async () => {
	const fetch = mockFetch(() => ({ json: { status: 'checked_out', event: 'Konzert', ticket: 'Standard' } }));
	const env = scanner({ fetch, window: { WPCAP_ADMISSION: { areas: [{ id: 3, label: 'Nord' }], checkinUrl: '/adm-in', checkoutUrl: '/adm-out', offlineSyncUrl: '/adm-sync', i18n: { checkedOut: 'Ausgecheckt' } } } });
	const select = env.document.querySelector('.wpcap-scanner-options select');
	select.value = '3';
	select.dispatchEvent(new Event('change'));
	assert.equal(JSON.parse(env.localStorage.getItem('wpcap-scanner-prefs-v1')).area, 3);
	const out = env.document.querySelector('input[value="out"]');
	out.checked = true;
	out.dispatchEvent(new Event('change'));
	await env.exports.submitToken(CODE);
	assert.equal(fetch.calls[0].url, '/adm-out');
	assert.equal(fetch.calls[0].body.area_id, 3);
	assert.ok(fetch.calls[0].body.idempotency_key);
	assert.equal(result(env).textContent, '↩ Ausgecheckt: Konzert — Standard');
});

test('camera permission denied: error message, manual code entry still works', async () => {
	const denied = Object.assign(new Error('Permission denied'), { name: 'NotAllowedError' });
	const fetch = mockFetch(() => ({ json: { status: 'checked_in', event: 'Konzert', ticket: 'Standard' } }));
	const env = scanner({ fetch, navigator: { onLine: true, mediaDevices: { getUserMedia: () => Promise.reject(denied) } } });
	env.document.querySelector('.wpcap-camera-start').click();
	await flush();
	assert.equal(result(env).textContent, 'Kamerafehler: Permission denied');
	assert.equal(env.document.querySelector('.wpcap-camera').hidden, true);
	await env.exports.submitToken(CODE);
	assert.equal(result(env).textContent, '✓ Konzert — Standard');
});

test('no camera API at all', async () => {
	const env = scanner({ navigator: { onLine: true } });
	env.document.querySelector('.wpcap-camera-start').click();
	await flush();
	assert.equal(result(env).textContent, 'Keine Kamera.');
});
