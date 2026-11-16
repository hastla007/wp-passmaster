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
				h('video.wpcap-camera', { hidden: true }),
				h('div.wpcap-camera-controls', [
					h('button.wpcap-camera-start', { type: 'button' }, 'Kamera starten'),
					h('button.wpcap-camera-stop', { type: 'button', hidden: true }, 'Kamera stoppen'),
					h('button.wpcap-camera-torch', { type: 'button', 'aria-pressed': 'false', hidden: true }, 'Licht'),
					h('button.wpcap-sound-toggle', { type: 'button', 'aria-pressed': 'true' }, 'Ton'),
				]),
				h('form.wpcap-scan-form', [h('label', { for: 'tok' }, 'Ticketcode'), h('input#tok', { name: 'token' }), h('button', { type: 'submit' }, 'Prüfen')]),
				h('div.wpcap-scan-result'),
				h('p.wpcap-scan-live', { role: 'status', 'aria-live': 'assertive' }),
			]));
		},
	});
}
const result = (env) => env.document.querySelector('.wpcap-scan-result');
/** Text of the result banner without its icon (merged banner: span.wpcap-scan-icon + span.wpcap-scan-text). */
const text = (env) => { const m = env.document.querySelector('.wpcap-scan-text'); return m ? m.textContent : ''; };
const icon = (env) => { const m = env.document.querySelector('.wpcap-scan-icon'); return m ? m.textContent : ''; };
test.after(() => { delete globalThis.WPCAP_SCANNER; });

test('online scan: success text, input cleared', async () => {
	const fetch = mockFetch(() => ({ json: { status: 'checked_in', event: 'Konzert', ticket: 'Standard' } }));
	const env = scanner({ fetch });
	env.document.getElementById('tok').value = CODE;
	env.document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls[0].url, '/checkin');
	assert.equal(fetch.calls[0].body.token, CODE);
	assert.match(fetch.calls[0].body.idempotency_key, /^[0-9a-f-]{36}$/, 'one idempotency key per scan (A541)');
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], 'n');
	assert.equal(icon(env), '✓');
	assert.equal(text(env), 'Konzert — Standard');
	assert.equal(result(env).dataset.state, 'success');
	assert.equal(env.document.getElementById('tok').value, '');
});

test('server rejection shows the server message', async () => {
	const env = scanner({ fetch: mockFetch(() => ({ status: 409, json: { code: 'wpcap_already_checked_in', message: 'Bereits eingecheckt' } })) });
	await env.exports.submitToken(CODE);
	assert.equal(text(env), 'Bereits eingecheckt');
	assert.equal(result(env).dataset.state, 'error');
	// Merged (A315): an answer without a plugin error code (proxy/WAF page) is a warning, never "no entry".
	env.timers.run();
	const sys = scanner({ fetch: mockFetch(() => ({ status: 409, json: { message: 'Bereits eingecheckt' } })) });
	await sys.exports.submitToken(CODE);
	assert.equal(result(sys).dataset.state, 'warning');
});

test('offline: never admitted automatically, explicit "admit after visual check" queues the scan', () => {
	const env = scanner({ navigator: { onLine: false } });
	env.exports.offline('kein code');
	assert.equal(text(env), 'Ungültiges Ticket.');
	env.exports.offline('https://example.test/?wpcap_ticket=' + CODE);
	assert.match(text(env), /NICHT GEPRÜFT/);
	assert.equal(env.exports.queue().length, 0, 'nothing queued before the staff decision');
	result(env).querySelector('.wpcap-admit-unchecked').click();
	const q = env.exports.queue();
	assert.equal(q.length, 1);
	assert.match(q[0].idempotency_key, /^[0-9a-f-]{36}$/);
	assert.equal(q[0].direction, 'in');
	assert.equal(text(env), 'Offline gespeichert.');
	const sync = env.document.querySelectorAll('.wpcap-scanner > button').pop();
	assert.equal(sync.hidden, false);
	assert.equal(sync.textContent, '1 synchronisieren');
});

test('offline: a signed QR payload (PC1) is offered for the visual check like a classic code', () => {
	const env = scanner({ navigator: { onLine: false } });
	const signed = 'PC1.' + 'eyJ0IjoiYWJjIn0'.padEnd(40, 'A') + '.' + 'B'.repeat(86);
	env.exports.offline(signed);
	assert.match(text(env), /NICHT GEPRÜFT/);
	result(env).querySelector('.wpcap-admit-unchecked').click();
	assert.equal(env.exports.queue()[0].token, signed, 'the signed payload is queued unchanged for the server check');
	env.exports.offline('PC1.kurz.x');
	assert.equal(text(env), 'Ungültiges Ticket.');
});

test('network failure while "online" falls back to the offline flow', async () => {
	const env = scanner({ fetch: mockFetch(() => { throw new TypeError('Failed to fetch'); }) });
	await env.exports.submitToken(CODE);
	assert.match(text(env), /NICHT GEPRÜFT/);
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
	assert.equal(env.exports.queueKey, 'wpcap-offline-scans-v3-default.me', 'merged: queue per site and user (A472)');
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
	assert.equal(text(env), '1 verworfen');
	assert.equal(result(env).dataset.state, 'error');
});

test('sync uploads in chunks and removes exactly the confirmed items', async () => {
	const items = Array.from({ length: 150 }, (_, i) => ({ token: CODE, idempotency_key: 'k' + i, stored_at: Date.now() }));
	const storage = new MemoryStorage({ 'wpcap-scanner-owner': 'u1hash', 'wpcap-offline-scans-v2-u1hash': JSON.stringify(items) });
	let env;
	const fetch = mockFetch((url, init, body) => {
		if (body.scans.length === 50) env.exports.enqueue(CODE2); // scanned while the last chunk is in flight
		return { json: { results: body.scans.map((t, i) => ({ status: i === 0 ? 'conflict' : 'ok', idempotency_key: t.idempotency_key })) } };
	});
	env = scanner({ localStorage: storage, fetch });
	await env.exports.syncQueue();
	assert.deepEqual(fetch.calls.map((c) => c.body.scans.length), [100, 50]);
	assert.equal(fetch.calls[0].url, '/sync');
	assert.equal(fetch.calls[0].body.scans[0].idempotency_key, 'k0');
	assert.equal(text(env), '150 übertragen, 2 Konflikte');
	const left = env.exports.queue();
	assert.equal(left.length, 1, 'the scan queued during the sync stays');
	assert.equal(left[0].token, CODE2);
});

test('failed sync keeps the queue', async () => {
	const storage = new MemoryStorage({ 'wpcap-scanner-owner': 'u1hash', 'wpcap-offline-scans-v2-u1hash': JSON.stringify([{ token: CODE, idempotency_key: 'k', stored_at: Date.now() }]) });
	const env = scanner({ localStorage: storage, fetch: mockFetch(() => ({ status: 500, json: { message: 'Serverfehler' } })) });
	await env.exports.syncQueue();
	assert.equal(text(env), 'Serverfehler');
	assert.equal(env.exports.queue().length, 1);
});

test('admission module: area/mode options and idempotent check-out', async () => {
	const fetch = mockFetch(() => ({ json: { status: 'checked_out', event: 'Konzert', ticket: 'Standard' } }));
	const env = scanner({ fetch, window: { WPCAP_ADMISSION: { areas: [{ id: 3, label: 'Nord' }], checkinUrl: '/adm-in', checkoutUrl: '/adm-out', offlineSyncUrl: '/adm-sync', i18n: { checkedOut: 'Ausgecheckt' } } } });
	const select = env.document.querySelector('.wpcap-scanner-options select');
	select.value = '3';
	select.dispatchEvent(new Event('change'));
	assert.equal(JSON.parse(env.localStorage.getItem('wpcap-scanner-prefs-v2-default')).area, 3);
	const out = env.document.querySelector('input[value="out"]');
	out.checked = true;
	out.dispatchEvent(new Event('change'));
	await env.exports.submitToken(CODE);
	assert.equal(fetch.calls[0].url, '/adm-out');
	assert.equal(fetch.calls[0].body.area_id, 3);
	assert.ok(fetch.calls[0].body.idempotency_key);
	assert.equal(text(env), 'Ausgecheckt: Konzert — Standard');
});

test('camera permission denied: error message, manual code entry still works', async () => {
	const denied = Object.assign(new Error('Permission denied'), { name: 'NotAllowedError' });
	const fetch = mockFetch(() => ({ json: { status: 'checked_in', event: 'Konzert', ticket: 'Standard' } }));
	const env = scanner({ fetch, navigator: { onLine: true, mediaDevices: { getUserMedia: () => Promise.reject(denied) } } });
	env.document.querySelector('.wpcap-camera-start').click();
	await flush();
	assert.equal(text(env), 'Kamerafehler: Permission denied');
	assert.equal(env.document.querySelector('.wpcap-camera').hidden, true);
	await env.exports.submitToken(CODE);
	assert.equal(icon(env), '✓');
	assert.equal(text(env), 'Konzert — Standard');
});

test('no camera API at all', async () => {
	const env = scanner({ navigator: { onLine: true } });
	env.document.querySelector('.wpcap-camera-start').click();
	await flush();
	assert.equal(text(env), 'Keine Kamera.');
});

/** Fake camera stream whose tracks record stop() calls. */
function fakeStream() {
	const tracks = [{ stopped: false, stop() { this.stopped = true; } }, { stopped: false, stop() { this.stopped = true; } }];
	return { tracks, getTracks: () => tracks };
}

test('camera tracks are stopped when the page is hidden or left (FE-6)', async () => {
	for (const how of ['visibilitychange', 'pagehide']) {
		const stream = fakeStream();
		const env = scanner({ navigator: { onLine: true, mediaDevices: { getUserMedia: () => Promise.resolve(stream) } } });
		const video = env.document.querySelector('.wpcap-camera');
		video.play = async () => {};
		env.document.querySelector('.wpcap-camera-start').click();
		await flush();
		assert.equal(video.srcObject, stream, how + ': camera running');
		assert.equal(video.hidden, false);
		assert.ok(stream.tracks.every((t) => !t.stopped));
		if ('visibilitychange' === how) {
			env.document.visibilityState = 'hidden';
			env.document.dispatchEvent(new Event('visibilitychange'));
		} else {
			env.window.dispatchEvent(new Event('pagehide'));
		}
		assert.ok(stream.tracks.every((t) => t.stopped), how + ': all tracks stopped');
		assert.equal(video.srcObject, null);
		assert.equal(video.hidden, true);
		assert.equal(env.document.querySelector('.wpcap-camera-start').disabled, false, how + ': camera can be started again');
	}
});

test('camera error after the stream was obtained stops the tracks (FE-6)', async () => {
	const stream = fakeStream();
	const env = scanner({ navigator: { onLine: true, mediaDevices: { getUserMedia: () => Promise.resolve(stream) } } });
	const video = env.document.querySelector('.wpcap-camera');
	video.play = () => Promise.reject(new Error('play failed'));
	env.document.querySelector('.wpcap-camera-start').click();
	await flush();
	assert.equal(text(env), 'Kamerafehler: play failed');
	assert.ok(stream.tracks.every((t) => t.stopped), 'no camera left running');
	assert.equal(video.hidden, true);
});

/* ---- audit R2 (2026-11-15), Experte #6: timeout, idempotency key of the online attempt, feedback ---- */

const ADM = { areas: [], checkinUrl: '/adm-in', checkoutUrl: '/adm-out', offlineSyncUrl: '/adm-sync', i18n: {} };

test('R2: a hanging check-in is aborted after 5 s (state busy meanwhile); the queued scan keeps the key of the online attempt', async () => {
	let signal;
	const calls = [];
	const fetch = (url, init) => {
		calls.push({ url, body: JSON.parse(init.body) });
		signal = init.signal;
		return new Promise((resolve, reject) => { init.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' }))); });
	};
	const env = scanner({ fetch, window: { WPCAP_ADMISSION: ADM, AbortController } /* merged: the timeout aborts through AbortController */ });
	const pending = env.exports.submitToken(CODE);
	await flush(3);
	assert.equal(result(env).dataset.state, 'busy', 'neutral state while the request runs');
	assert.equal(text(env), '…');
	assert.ok(signal, 'request carries an AbortSignal');
	const timeout = [...env.timers.pending.values()].find((tm) => 5000 === tm.ms);
	assert.ok(timeout, 'aborted after 5 s');
	env.timers.run();
	await pending;
	assert.match(text(env), /NICHT GEPRÜFT/, 'falls back to the visual check');
	result(env).querySelector('.wpcap-admit-unchecked').click();
	const q = env.exports.queue();
	assert.equal(q.length, 1);
	assert.equal(q[0].idempotency_key, calls[0].body.idempotency_key, 'same key as the online attempt: a request that reached the server is replayed at sync, no false conflict');
});

test('R2: network failure keeps the key too; offline without any attempt gets a fresh key', async () => {
	const fetch = mockFetch(() => { throw new TypeError('Failed to fetch'); });
	const env = scanner({ fetch, window: { WPCAP_ADMISSION: ADM } });
	await env.exports.submitToken(CODE);
	result(env).querySelector('.wpcap-admit-unchecked').click();
	assert.equal(env.exports.queue()[0].idempotency_key, fetch.calls[0].body.idempotency_key);
	const off = scanner({ navigator: { onLine: false }, window: { WPCAP_ADMISSION: ADM } });
	await off.exports.submitToken(CODE2);
	result(off).querySelector('.wpcap-admit-unchecked').click();
	assert.match(off.exports.queue()[0].idempotency_key, /^[0-9a-f-]{36}$/);
});

test('R2: the attendee name is shown when the server sends it', async () => {
	const env = scanner({ fetch: mockFetch(() => ({ json: { status: 'checked_in', event: 'Konzert', ticket: 'Standard', attendee_name: 'Alex Muster' } })) });
	await env.exports.submitToken(CODE);
	assert.equal(icon(env), '✓');
	assert.equal(text(env), 'Konzert — Standard — Alex Muster');
	assert.equal(result(env).dataset.state, 'success');
});

test('R2: WebAudio tones (high = OK, low = error), switchable and remembered in the browser', async () => {
	const tones = [];
	class FakeAudio {
		constructor() { this.currentTime = 0; this.destination = {}; this.state = 'running'; }
		createOscillator() { const o = { type: '', frequency: { value: 0 }, connect(n) { return n; }, start() { tones.push(o.frequency.value); }, stop() {} }; return o; }
		createGain() { return { gain: { value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect(n) { return n; } }; } // AudioNode.connect() returns its target (chained in the merged scanner)
	}
	let ok = true;
	const fetch = mockFetch(() => (ok ? { json: { status: 'checked_in', event: 'Konzert', ticket: 'Standard' } } : { status: 409, json: { code: 'wpcap_already_checked_in', message: 'Bereits eingecheckt' } }));
	const env = scanner({ fetch, window: { AudioContext: FakeAudio } });
	const toggle = env.document.querySelector('.wpcap-sound-toggle');
	assert.ok(toggle, 'sound switch rendered');
	assert.equal(toggle.getAttribute('aria-pressed'), 'true');
	await env.exports.submitToken(CODE);
	assert.deepEqual(tones, [660, 990], 'success: rising two-tone');
	env.timers.run(); // scanner lock
	ok = false;
	await env.exports.submitToken(CODE2);
	assert.deepEqual(tones, [660, 990, 196, 147], 'error: low buzz');
	toggle.click();
	assert.equal(toggle.getAttribute('aria-pressed'), 'false');
	assert.equal(env.localStorage.getItem('wpcap-scanner-sound-v1'), 'off');
	env.timers.run();
	await env.exports.submitToken(CODE);
	assert.equal(tones.length, 4, 'switched off: silent');
	const again = scanner({ fetch, window: { AudioContext: FakeAudio }, localStorage: env.localStorage });
	assert.equal(again.document.querySelector('.wpcap-sound-toggle').getAttribute('aria-pressed'), 'false', 'remembered');
});

test('R2: torch switch and continuous focus where the camera supports them', async () => {
	const applied = [];
	const track = { stop() {}, getCapabilities: () => ({ torch: true, focusMode: ['continuous'] }), applyConstraints: async (c) => { applied.push(c); } };
	const stream = { getTracks: () => [track], getVideoTracks: () => [track] };
	const env = scanner({ navigator: { onLine: true, mediaDevices: { getUserMedia: () => Promise.resolve(stream) } }, window: { BarcodeDetector: class { static async getSupportedFormats() { return ['qr_code']; } async detect() { return []; } } } });
	const video = env.document.querySelector('.wpcap-camera');
	video.play = async () => {};
	const torch = env.document.querySelector('.wpcap-camera-torch');
	assert.equal(torch.hidden, true, 'hidden before the camera runs');
	env.document.querySelector('.wpcap-camera-start').click();
	await flush();
	assert.equal(torch.hidden, false, 'torch offered');
	assert.deepEqual(applied[0], { advanced: [{ focusMode: 'continuous' }] });
	torch.click();
	await flush();
	assert.deepEqual(applied[1], { advanced: [{ torch: true }] });
	assert.equal(torch.getAttribute('aria-pressed'), 'true');
	env.window.dispatchEvent(new Event('pagehide'));
	assert.equal(torch.hidden, true, 'hidden again when the camera stops');
});
