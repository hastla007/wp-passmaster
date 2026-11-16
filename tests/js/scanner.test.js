/**
 * A271, A315, A540, A541, A542: web scanner (assets/scanner.js) in Node with a small fake DOM,
 * fake timers and a scripted fetch(). Covers: code field emptied for every scan, scans during the
 * lock window discarded visibly, concatenated codes never submitted, 5 s request timeout with the
 * offline decision, the online idempotency key reused for the offline queue, a code queued offline
 * refused in red on the next offline scan, and only wpcap_* JSON errors shown as refusal.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'scanner.js'), 'utf8');
const T1 = 'a'.repeat(64);
const T2 = 'b'.repeat(64);

class FakeElement {
	constructor(tag) {
		this.tagName = String(tag || 'div').toUpperCase();
		this.children = [];
		this.dataset = {};
		this.attributes = {};
		this.listeners = {};
		this.hidden = false;
		this.disabled = false;
		this.value = '';
		this.className = '';
		this.ownText = '';
	}
	get textContent() { return this.ownText + this.children.map((c) => c.textContent).join(''); }
	set textContent(value) { this.ownText = String(value); this.children = []; }
	appendChild(child) { this.children.push(child); return child; }
	insertBefore(child) { this.children.push(child); return child; }
	addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
	dispatch(type, event) { (this.listeners[type] || []).forEach((fn) => fn(Object.assign({ preventDefault() {}, currentTarget: this }, event || {}))); }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	getAttribute(name) { return this.attributes[name]; }
	focus() {}
	find(predicate) {
		for (const child of this.children) {
			if (!(child instanceof FakeElement)) continue;
			if (predicate(child)) return child;
			const deeper = child.find(predicate);
			if (deeper) return deeper;
		}
		return null;
	}
}

function storage() {
	const map = new Map();
	return {
		get length() { return map.size; },
		key: (i) => Array.from(map.keys())[i] || null,
		getItem: (k) => (map.has(k) ? map.get(k) : null),
		setItem: (k, v) => { map.set(k, String(v)); },
		removeItem: (k) => { map.delete(k); },
	};
}

function response(status, body) {
	const text = typeof body === 'string' ? body : JSON.stringify(body);
	return { status, ok: status >= 200 && status < 300, text: async () => text, json: async () => JSON.parse(text) };
}

/**
 * Loads scanner.js into a fresh sandbox. fetchImpl(url, options) is called for every request.
 * options.storage: a localStorage shared with another sandbox (same origin, A472);
 * options.privacy: WPCAP_SCANNER_PRIVACY (owner, site).
 */
function loadScanner(options = {}) {
	const opts = options || {};
	const el = {
		form: new FakeElement('form'), input: new FakeElement('input'), result: new FakeElement('div'), live: new FakeElement('div'),
		counter: new FakeElement('p'), searchForm: new FakeElement('form'), searchInput: new FakeElement('input'), searchList: new FakeElement('ul'), mode: new FakeElement('div'), video: new FakeElement('video'), start: new FakeElement('button'), stop: new FakeElement('button'), torch: new FakeElement('button'),
	};
	el.counter.hidden = true; // <p class="wpcap-scan-counter" hidden> in the page
	const root = new FakeElement('div');
	const map = { '.wpcap-scan-form': el.form, '.wpcap-scan-result': el.result, '.wpcap-scan-live': el.live, '.wpcap-scan-mode': el.mode, '.wpcap-camera': el.video, '.wpcap-camera-start': el.start, '.wpcap-camera-stop': el.stop, '.wpcap-camera-torch': el.torch, '.wpcap-scan-counter': el.counter, '.wpcap-search-form': el.searchForm, '.wpcap-search-results': el.searchList };
	root.querySelector = (sel) => map[sel] || null;
	el.form.querySelector = () => el.input;
	el.searchForm.querySelector = () => el.searchInput;
	const timers = [];
	let clock = 0;
	const env = {
		requests: [],
		fetchImpl: async () => response(200, { status: 'checked_in', event: 'E', ticket: 'T' }),
		advance(ms) {
			clock += ms;
			timers.filter((x) => !x.done && x.at <= clock).sort((a, b) => a.at - b.at).forEach((x) => { x.done = true; x.fn(); });
		},
	};
	const window = {
		document: {
			querySelector: (sel) => (sel === '.wpcap-scanner' ? root : null),
			createElement: (tag) => new FakeElement(tag),
			createTextNode: (text) => { const node = new FakeElement('#text'); node.textContent = text; return node; },
			documentElement: { lang: 'de' },
			addEventListener() {},
			head: new FakeElement('head'),
			activeElement: null,
		},
		navigator: { onLine: true },
		localStorage: options.storage || storage(),
		crypto: { randomUUID: require('node:crypto').randomUUID },
		AbortController,
		WPCAP_SCANNER: { checkinUrl: '/tickets/check-in', offlineSyncUrl: '/tickets/offline-sync', undoUrl: '/scanner/undo', searchUrl: '/scanner/search', checkInTicketUrl: '/scanner/check-in-ticket', undoReasons: ['Fehlscan', 'Doppelt gescannt'], nonce: 'n', i18n: { undo: 'Rückgängig (%d s)' } },
		WPCAP_ADMISSION: { checkinUrl: '/admission/check-in', checkoutUrl: '/admission/check-out', offlineSyncUrl: '/admission/offline-sync', areas: [], i18n: {} },
		WPCAP_SCANNER_PRIVACY: options.privacy || { owner: 'u1' },
		setTimeout: (fn, ms) => { timers.push({ fn, at: clock + (ms || 0), done: false }); return timers.length; },
		clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1].done = true; },
		requestAnimationFrame() {},
		addEventListener() {},
		fetch: (url, options) => { env.requests.push({ url, options, body: options && options.body ? JSON.parse(options.body) : null }); return env.fetchImpl(url, options); },
		Date, JSON, Math, Promise, Array, String, Object, RegExp, Error, isNaN, parseInt, console,
	};
	Object.assign(window, opts.globals || {});
	window.window = window;
	vm.runInContext(source, vm.createContext(window));
	return Object.assign(env, { window, el, logic: window.wpcapScannerLogic, queue: () => JSON.parse(window.localStorage.getItem('wpcap-offline-scans-v3-' + ((options.privacy || {}).site || 'default') + '.' + ((options.privacy || {}).owner || 'u1')) || '[]') });
}
const flush = async () => { for (let i = 0; i < 10; i++) await new Promise((r) => setImmediate(r)); };
async function scan(env, value) {
	env.el.input.value = value;
	env.el.form.dispatch('submit');
	await flush();
}
const admitButton = (env) => env.el.result.find((c) => c.className.indexOf('wpcap-admit-unchecked') !== -1);

test('logic: scanCode accepts one code and refuses concatenated or two different codes', () => {
	const { logic } = loadScanner();
	assert.strictEqual(logic.scanCode('https://x.test/?wpcap_ticket=' + T1.toUpperCase() + '&a=1').code, T1);
	assert.deepStrictEqual(JSON.parse(JSON.stringify(logic.scanCode(T1 + T2))), { code: '', ambiguous: true });
	assert.strictEqual(logic.scanCode(T1 + ' ' + T2).ambiguous, true);
	assert.strictEqual(logic.scanCode(T1 + ' ' + T1).code, T1);
	assert.strictEqual(logic.scanCode('K7QM-4XWA').ambiguous, false);
});

test('logic: only wpcap_* JSON errors are refusals (A315)', () => {
	const { logic } = loadScanner();
	assert.strictEqual(logic.classifyAnswer(404, { code: 'wpcap_ticket_not_found' }), 'refusal');
	assert.strictEqual(logic.classifyAnswer(409, { code: 'wpcap_ticket_already_checked_in', data: { status: 409 } }), 'refusal');
	assert.strictEqual(logic.classifyAnswer(403, null), 'system');
	assert.strictEqual(logic.classifyAnswer(403, { code: 'rest_forbidden' }), 'system');
	assert.strictEqual(logic.classifyAnswer(400, { code: 'rest_invalid_param' }), 'system');
	assert.strictEqual(logic.classifyAnswer(503, { code: 'wpcap_checkin_retry' }), 'system');
	assert.strictEqual(logic.classifyAnswer(409, { code: 'wpcap_db_busy' }), 'system');
	assert.strictEqual(logic.classifyAnswer(403, { code: 'rest_cookie_invalid_nonce' }), 'session');
	assert.strictEqual(logic.classifyAnswer(429, { code: 'wpcap_help_code_rate_limited' }), 'rate');
	assert.strictEqual(logic.classifyAnswer(200, null), 'system');
	assert.strictEqual(logic.classifyAnswer(200, {}), 'system');
	assert.strictEqual(logic.classifyAnswer(200, { status: 'checked_in' }), 'success');
	assert.strictEqual(logic.classifyAnswer(200, { status: 'checked_out' }), 'success');
	assert.strictEqual(logic.REQUEST_TIMEOUT_MS, 5000);
});

test('A271: the code field is emptied for every result, also refusals and system errors', async () => {
	const env = loadScanner();
	env.fetchImpl = async () => response(404, { code: 'wpcap_ticket_not_found', message: 'Ticket nicht gefunden.', data: { status: 404 } });
	await scan(env, T1);
	assert.strictEqual(env.el.result.dataset.state, 'error');
	assert.strictEqual(env.el.input.value, '');
	env.advance(1400);
	env.fetchImpl = async () => response(500, '<html>oops</html>');
	await scan(env, T2);
	assert.strictEqual(env.el.result.dataset.state, 'warning');
	assert.strictEqual(env.el.input.value, '');
});

test('A271: a scan during the lock window is discarded visibly and never appended to the next code', async () => {
	const env = loadScanner();
	await scan(env, T1);
	assert.strictEqual(env.el.result.dataset.state, 'success');
	await scan(env, T2);
	assert.strictEqual(env.requests.length, 1, 'no request for the discarded scan');
	assert.strictEqual(env.el.input.value, '', 'discarded code does not stay in the field');
	assert.ok(env.el.result.find((c) => c.className === 'wpcap-scan-discarded'), 'discard note shown');
	assert.strictEqual(env.el.result.dataset.state, 'success', 'previous result stays visible');
	env.advance(1400);
	await scan(env, T2);
	assert.strictEqual(env.requests.length, 2);
	assert.strictEqual(env.requests[1].body.token, T2);
});

test('A271: concatenated codes from a wedge scanner are never submitted', async () => {
	const env = loadScanner();
	await scan(env, T1 + T2);
	assert.strictEqual(env.requests.length, 0);
	assert.strictEqual(env.el.result.dataset.state, 'error');
	assert.strictEqual(env.el.input.value, '');
});

test('A315: WAF/proxy pages and non-plugin errors are yellow warnings, never "no entry" or a green admission', async () => {
	const cases = [
		[403, '<html><body>Request blocked</body></html>'],
		[403, { code: 'rest_forbidden', message: 'Sorry', data: { status: 403 } }],
		[404, '<!doctype html><title>Not found</title>'],
		[200, '<html>captive portal</html>'],
		[200, { foo: 1 }],
		[502, 'Bad gateway'],
	];
	for (const [status, body] of cases) {
		const env = loadScanner();
		env.fetchImpl = async () => response(status, body);
		await scan(env, T1);
		assert.strictEqual(env.el.result.dataset.state, 'warning', status + ' ' + JSON.stringify(body));
	}
	const env = loadScanner();
	env.fetchImpl = async () => response(409, { code: 'wpcap_ticket_already_checked_in', message: 'Bereits verwendet', data: { status: 409, first_checked_in_at: '2026-11-13T19:42:00Z' } });
	await scan(env, T1);
	assert.strictEqual(env.el.result.dataset.state, 'error');
});

test('A540/A541: no answer within 5 s leads to the offline decision with the key of the online attempt', async () => {
	const env = loadScanner();
	let aborted = false;
	env.fetchImpl = (url, options) => new Promise((resolve, reject) => {
		options.signal.addEventListener('abort', () => { aborted = true; reject(new Error('AbortError')); });
	});
	await scan(env, T1);
	assert.strictEqual(env.el.result.dataset.state, 'busy');
	env.advance(4999);
	await flush();
	assert.strictEqual(aborted, false);
	env.advance(1);
	await flush();
	assert.ok(aborted, 'request aborted after 5 s');
	assert.strictEqual(env.el.result.dataset.state, 'warning');
	const button = admitButton(env);
	assert.ok(button, 'admit-after-visual-check offered');
	button.dispatch('click');
	const queued = env.queue();
	assert.strictEqual(queued.length, 1);
	assert.strictEqual(queued[0].idempotency_key, env.requests[0].body.idempotency_key, 'same idempotency key as the online attempt');
});

test('A541: a network error mid-scan also queues with the online key', async () => {
	const env = loadScanner();
	env.fetchImpl = async () => { throw new TypeError('Failed to fetch'); };
	await scan(env, T1);
	admitButton(env).dispatch('click');
	assert.strictEqual(env.queue()[0].idempotency_key, env.requests[0].body.idempotency_key);
});

test('A542: a code queued offline is refused in red with the time on the next offline scan', async () => {
	const env = loadScanner();
	env.window.navigator.onLine = false;
	await scan(env, T1);
	admitButton(env).dispatch('click');
	assert.strictEqual(env.queue().length, 1);
	await scan(env, 'https://x.test/?wpcap_ticket=' + T1.toUpperCase());
	assert.strictEqual(env.el.result.dataset.state, 'error');
	assert.ok(!admitButton(env), 'no second admit button');
	assert.match(env.el.result.textContent, /\d{2}:\d{2}/, 'time of the earlier admission shown');
	// A different ticket is still offered.
	await scan(env, T2);
	assert.ok(admitButton(env));
	// Also via a timeout of the online attempt.
	env.window.navigator.onLine = true;
	env.advance(2000);
	env.fetchImpl = async () => { throw new TypeError('Failed to fetch'); };
	await scan(env, T1);
	assert.strictEqual(env.el.result.dataset.state, 'error');
	assert.strictEqual(env.queue().length, 1);
});

test('A542: double tap on "admit after visual check" queues only once', async () => {
	const env = loadScanner();
	env.window.navigator.onLine = false;
	await scan(env, T1);
	const first = admitButton(env);
	await scan(env, T1);
	const second = admitButton(env);
	first.dispatch('click');
	second.dispatch('click');
	assert.strictEqual(env.queue().length, 1);
	assert.strictEqual(env.el.result.dataset.state, 'error');
});

test('A315: an HTML answer with status 200 to the offline sync keeps the queued scans', async () => {
	const env = loadScanner();
	env.window.navigator.onLine = false;
	await scan(env, T1);
	admitButton(env).dispatch('click');
	env.window.navigator.onLine = true;
	env.fetchImpl = async () => response(200, '<html>login</html>');
	const root = env.window.document.querySelector('.wpcap-scanner');
	const syncButton = root.find((c) => c.className === 'wpcap-sync');
	syncButton.dispatch('click');
	await flush();
	assert.strictEqual(env.queue().length, 1, 'nothing removed');
	assert.strictEqual(env.el.result.dataset.state, 'error');
});

/** Fake camera: getUserMedia() resolves only when release() is called; every stream records stop(). */
function fakeCamera(env) {
	const cam = { calls: 0, pending: [], streams: [] };
	env.window.navigator.mediaDevices = {
		getUserMedia: () => {
			cam.calls++;
			return new Promise((resolve) => {
				const track = { stopped: false, stop() { this.stopped = true; }, getCapabilities: () => ({}) };
				const stream = { track, getTracks: () => [track], getVideoTracks: () => [track] };
				cam.streams.push(stream);
				cam.pending.push(() => resolve(stream));
			});
		},
	};
	env.window.BarcodeDetector = class { static async getSupportedFormats() { return ['qr_code']; } async detect() { return []; } };
	env.el.video.play = async () => {};
	cam.release = async () => { cam.pending.shift()(); await flush(); };
	return cam;
}

test('A316: a double tap on "camera start" opens one stream; the button is locked at once', async () => {
	const env = loadScanner();
	const cam = fakeCamera(env);
	env.el.start.dispatch('click');
	assert.strictEqual(env.el.start.disabled, true, 'locked while the permission prompt is open');
	env.el.start.dispatch('click');
	await flush();
	assert.strictEqual(cam.calls, 1, 'second tap ignored');
	await cam.release();
	assert.strictEqual(env.el.video.srcObject, cam.streams[0]);
	assert.strictEqual(env.el.stop.hidden, false);
	env.el.stop.dispatch('click');
	assert.strictEqual(cam.streams[0].track.stopped, true, 'stop ends the only stream');
	assert.strictEqual(env.el.start.disabled, false);
});

test('A316: a stream that arrives after "stop" is stopped right away', async () => {
	const env = loadScanner();
	const cam = fakeCamera(env);
	env.el.start.dispatch('click');
	await flush();
	env.el.stop.dispatch('click'); // e.g. stop pressed while the prompt was open
	await cam.release();
	assert.strictEqual(cam.streams[0].track.stopped, true);
	assert.strictEqual(env.el.video.srcObject, null);
	assert.strictEqual(env.el.start.disabled, false, 'can be started again');
});

/* A472/A479: two sites of a subdirectory network (example.org/a/, example.org/b/) share one origin. */
async function queueOffline(env, token) {
	env.window.navigator.onLine = false;
	await scan(env, token);
	admitButton(env).dispatch('click');
	env.window.navigator.onLine = true;
}
const syncButtonOf = (env) => env.window.document.querySelector('.wpcap-scanner').find((c) => c.tagName === 'BUTTON');

test('A472: offline queues of two sites on one origin stay apart; the other site never uploads them', async () => {
	const shared = storage();
	const a = loadScanner({ storage: shared, privacy: { owner: 'u1', site: 'siteA' } });
	await queueOffline(a, T1);
	assert.strictEqual(a.queue().length, 1);
	// Same person opens the scanner of site B on the same device.
	const b = loadScanner({ storage: shared, privacy: { owner: 'u1', site: 'siteB' } });
	assert.strictEqual(b.queue().length, 0, 'site B does not see the queue of site A');
	await queueOffline(b, T2);
	syncButtonOf(b).dispatch('click');
	await flush();
	const synced = b.requests.filter((r) => /offline-sync/.test(r.url));
	assert.strictEqual(synced.length, 1);
	assert.deepStrictEqual(synced[0].body.scans.map((x) => x.token), [T2], 'site B uploads only its own scan');
	// Back on site A: its scan is still queued and goes to site A.
	const a2 = loadScanner({ storage: shared, privacy: { owner: 'u1', site: 'siteA' } });
	assert.strictEqual(a2.queue().length, 1);
	assert.strictEqual(a2.queue()[0].token, T1);
});

test('A472: another user on the device still removes foreign queues of every site (privacy)', async () => {
	const shared = storage();
	const a = loadScanner({ storage: shared, privacy: { owner: 'u1', site: 'siteA' } });
	await queueOffline(a, T1);
	loadScanner({ storage: shared, privacy: { owner: 'u2', site: 'siteB' } });
	assert.strictEqual(shared.getItem('wpcap-offline-scans-v3-siteA.u1'), null);
});

test('A472: the per-user queue of the previous version is adopted once, entrance/date choice is kept per site', async () => {
	const shared = storage();
	const item = { token: T1, idempotency_key: 'k1', stored_at: Date.now(), scanned_at: new Date().toISOString() };
	shared.setItem('wpcap-offline-scans-v2-u1', JSON.stringify([item]));
	shared.setItem('wpcap-scanner-owner', 'u1');
	const a = loadScanner({ storage: shared, privacy: { owner: 'u1', site: 'siteA' } });
	assert.strictEqual(a.queue().length, 1);
	assert.strictEqual(shared.getItem('wpcap-offline-scans-v2-u1'), null, 'old key removed');
	const b = loadScanner({ storage: shared, privacy: { owner: 'u1', site: 'siteB' } });
	assert.strictEqual(b.queue().length, 0, 'adopted by one site only');
	shared.setItem('wpcap-scanner-prefs-v2-siteA', JSON.stringify({ area: 3 }));
	assert.strictEqual(shared.getItem('wpcap-scanner-prefs-v2-siteB'), null);
});

const byClass = (env, cls) => env.el.result.find((c) => (c.className || '').split(' ').indexOf(cls) !== -1);

test('A547 logic: undo countdown and reason', () => {
	const env = loadScanner();
	assert.strictEqual(env.logic.UNDO_MS, 30000);
	assert.strictEqual(env.logic.undoSecondsLeft(1000, 1000), 30);
	assert.strictEqual(env.logic.undoSecondsLeft(1000, 30500), 1);
	assert.strictEqual(env.logic.undoSecondsLeft(1000, 31000), 0);
	assert.strictEqual(env.logic.undoReason('', ''), '');
	assert.strictEqual(env.logic.undoReason('__other', '   '), '');
	assert.strictEqual(env.logic.undoReason('__other', ' Gast umgekehrt '), 'Gast umgekehrt');
	assert.strictEqual(env.logic.undoReason('Fehlscan', 'ignored'), 'Fehlscan');
});

test('A547: "Rückgängig" after an admission asks for a required reason and calls the undo route', async () => {
	const env = loadScanner();
	env.fetchImpl = async () => response(200, { status: 'checked_in', event: 'E', ticket: 'T', ticket_public_id: 'f'.repeat(32) });
	await scan(env, T1);
	const button = byClass(env, 'wpcap-undo-button');
	assert.ok(button, 'undo offered');
	assert.match(button.textContent, /Rückgängig \(30 s\)/);
	button.dispatch('click');
	const confirm = byClass(env, 'wpcap-undo-confirm');
	const select = env.el.result.find((c) => c.tagName === 'SELECT');
	assert.ok(confirm && select, 'reason form shown');
	confirm.dispatch('click');
	await flush();
	assert.strictEqual(env.requests.length, 1, 'no undo request without a reason');
	assert.ok(byClass(env, 'wpcap-undo-note').textContent.trim().length > 0, 'asks for the reason');
	select.value = 'Fehlscan';
	env.fetchImpl = async () => response(200, { ok: true, result: 'undone', ticket_public_id: 'f'.repeat(32) });
	confirm.dispatch('click');
	await flush();
	assert.strictEqual(env.requests.length, 2);
	assert.strictEqual(env.requests[1].url, '/scanner/undo');
	assert.deepStrictEqual({ id: env.requests[1].body.ticket_public_id, reason: env.requests[1].body.reason }, { id: 'f'.repeat(32), reason: 'Fehlscan' });
	assert.strictEqual(env.el.result.dataset.state, 'warning');
	assert.ok(!byClass(env, 'wpcap-undo-button'), 'offer gone after the undo');
});

test('A547: a refused undo (other person, too late, other area) is shown as error', async () => {
	const env = loadScanner();
	env.fetchImpl = async () => response(200, { status: 'checked_in', event: 'E', ticket: 'T', ticket_public_id: 'e'.repeat(32) });
	await scan(env, T1);
	byClass(env, 'wpcap-undo-button').dispatch('click');
	env.el.result.find((c) => c.tagName === 'SELECT').value = 'Doppelt gescannt';
	env.fetchImpl = async () => response(403, { code: 'wpcap_undo_wrong_area', message: 'Anderer Bereich', data: { status: 403, result: 'wrong_area' } });
	byClass(env, 'wpcap-undo-confirm').dispatch('click');
	await flush();
	assert.strictEqual(env.el.result.dataset.state, 'error');
	assert.match(env.el.result.textContent, /Anderer Bereich/);
});

test('A547: no undo for check-outs, refusals or answers without a ticket id', async () => {
	const env = loadScanner();
	env.fetchImpl = async () => response(404, { code: 'wpcap_ticket_not_found', message: 'x', data: { status: 404 } });
	await scan(env, T1);
	assert.ok(!byClass(env, 'wpcap-undo-button'));
	env.advance(1400);
	env.fetchImpl = async () => response(200, { status: 'checked_in', event: 'E' });
	await scan(env, T2);
	assert.ok(!byClass(env, 'wpcap-undo-button'));
});

test('A547: an offline-queued admission can be taken back locally (nothing was booked)', async () => {
	const env = loadScanner();
	env.window.navigator.onLine = false;
	await scan(env, T1);
	admitButton(env).dispatch('click');
	assert.strictEqual(env.queue().length, 1);
	byClass(env, 'wpcap-undo-button').dispatch('click');
	assert.strictEqual(env.queue().length, 0, 'removed from the queue');
	assert.strictEqual(env.el.result.dataset.state, 'warning');
	assert.strictEqual(env.requests.length, 0);
});

test('A546 logic: counter line', () => {
	const env = loadScanner();
	const i18n = { counter: 'Eingecheckt: %1$d / %2$d', counterQueued: '+%d offline', counterStale: 'Stand %s' };
	assert.strictEqual(env.logic.counterText(i18n, 120, 300, 0, ''), 'Eingecheckt: 120 / 300');
	assert.strictEqual(env.logic.counterText(i18n, 120, 300, 3, '19:42'), 'Eingecheckt: 120 / 300 · +3 offline · Stand 19:42');
});

function counterScanner() {
	const env = loadScanner({ globals: { WPCAP_SCANNER_OCCURRENCES: { label: 'Termin', all: 'Alle', items: [ { id: 5, label: 'Konzert' } ] } } });
	env.window.WPCAP_SCANNER.statsUrl = '/scanner/stats';
	Object.assign(env.window.WPCAP_SCANNER.i18n, { counter: 'Eingecheckt: %1$d / %2$d', counterQueued: '+%d offline', counterStale: 'Stand %s' });
	env.stats = { occurrence_id: 5, admitted: 10, total: 50, remaining: 40 };
	env.fetchImpl = async (url, options) => (options.method === 'GET' ? response(200, env.stats) : response(200, { status: 'checked_in', event: 'E', ticket: 'T', ticket_public_id: 'c'.repeat(32) }));
	return env;
}
async function chooseDate(env, id) {
	const select = env.window.document.querySelector('.wpcap-scanner').find((c) => c.tagName === 'SELECT');
	select.value = String(id);
	select.dispatch('change');
	await flush();
}

test('A546: the web scanner shows "checked in / total" for the chosen date and refreshes it after a scan', async () => {
	const env = counterScanner();
	assert.strictEqual(env.el.counter.hidden, true, 'hidden without a date');
	await chooseDate(env, 5);
	const stat = env.requests.find((r) => /\/scanner\/stats\?occurrence_id=5$/.test(r.url));
	assert.ok(stat, 'stats requested for the chosen date');
	assert.strictEqual(env.el.counter.hidden, false);
	assert.strictEqual(env.el.counter.textContent, 'Eingecheckt: 10 / 50');
	env.stats = { occurrence_id: 5, admitted: 11, total: 50, remaining: 39 };
	await scan(env, T1);
	assert.strictEqual(env.el.counter.textContent, 'Eingecheckt: 11 / 50');
	await chooseDate(env, 0);
	assert.strictEqual(env.el.counter.hidden, true, 'hidden for "all dates"');
});

test('A546: offline the counter keeps the last value and adds the admissions queued on this device', async () => {
	const env = counterScanner();
	await chooseDate(env, 5);
	env.window.navigator.onLine = false;
	await scan(env, T1);
	admitButton(env).dispatch('click');
	await flush();
	assert.match(env.el.counter.textContent, /^Eingecheckt: 10 \/ 50 · \+1 offline/);
	byClass(env, 'wpcap-undo-button').dispatch('click');
	assert.strictEqual(env.el.counter.textContent.indexOf('offline'), -1, 'queued scan taken back');
});

test('A544: manual search lists tickets and checks a result in by its id (same answer handling as a scan)', async () => {
	const env = loadScanner();
	const id = '9'.repeat(32);
	env.fetchImpl = async (url, options) => {
		if (options.method === 'GET') return response(200, { results: [ { ticket_public_id: id, event: 'Konzert', ticket: 'Standard', attendee_name: 'Anna Muster', email: 'a***@e***.com', order_ref: 'AB12-CD34', status: 'valid' }, { ticket_public_id: '8'.repeat(32), event: 'Konzert', ticket: 'VIP', status: 'checked_in' } ], more: true });
		return response(200, { status: 'checked_in', event: 'Konzert', ticket: 'Standard', ticket_public_id: id });
	};
	env.el.searchInput.value = 'Muster';
	env.el.searchForm.dispatch('submit');
	await flush();
	assert.match(env.requests[0].url, /^\/scanner\/search\?q=Muster&occurrence_id=0$/);
	const items = env.el.searchList.children;
	assert.strictEqual(items.length, 3, 'two results and the "more" hint');
	assert.match(items[0].textContent, /Anna Muster/);
	assert.match(items[0].textContent, /AB12-CD34/);
	const button = items[0].find((c) => c.className.indexOf('wpcap-search-checkin') !== -1);
	button.dispatch('click');
	await flush();
	assert.strictEqual(env.requests[1].url, '/scanner/check-in-ticket');
	assert.strictEqual(env.requests[1].body.ticket_public_id, id);
	assert.ok(env.requests[1].body.idempotency_key, 'own idempotency key');
	assert.strictEqual(env.el.result.dataset.state, 'success');
	assert.ok(byClass(env, 'wpcap-undo-button'), 'undo offered for the manual check-in, too');
});

test('A544: a refused manual check-in is red; offline the search is not sent', async () => {
	const env = loadScanner();
	env.fetchImpl = async (url, options) => (options.method === 'GET'
		? response(200, { results: [ { ticket_public_id: '7'.repeat(32), event: 'E', status: 'valid' } ], more: false })
		: response(409, { code: 'wpcap_ticket_not_valid', message: 'Ticket wurde bereits verwendet.', data: { status: 409, result: 'already_checked_in' } }));
	env.el.searchInput.value = 'AB12-CD34';
	env.el.searchForm.dispatch('submit');
	await flush();
	env.el.searchList.children[0].find((c) => c.tagName === 'BUTTON').dispatch('click');
	await flush();
	assert.strictEqual(env.el.result.dataset.state, 'error');
	env.advance(1400);
	env.window.navigator.onLine = false;
	env.el.searchInput.value = 'Muster';
	env.el.searchForm.dispatch('submit');
	await flush();
	assert.strictEqual(env.requests.length, 2, 'no request offline');
	assert.strictEqual(env.el.result.dataset.state, 'warning');
});
