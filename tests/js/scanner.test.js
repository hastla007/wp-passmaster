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

/** Loads scanner.js into a fresh sandbox. fetchImpl(url, options) is called for every request. */
function loadScanner() {
	const el = {
		form: new FakeElement('form'), input: new FakeElement('input'), result: new FakeElement('div'), live: new FakeElement('div'),
		mode: new FakeElement('div'), video: new FakeElement('video'), start: new FakeElement('button'), stop: new FakeElement('button'), torch: new FakeElement('button'),
	};
	const root = new FakeElement('div');
	const map = { '.wpcap-scan-form': el.form, '.wpcap-scan-result': el.result, '.wpcap-scan-live': el.live, '.wpcap-scan-mode': el.mode, '.wpcap-camera': el.video, '.wpcap-camera-start': el.start, '.wpcap-camera-stop': el.stop, '.wpcap-camera-torch': el.torch };
	root.querySelector = (sel) => map[sel] || null;
	el.form.querySelector = () => el.input;
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
		localStorage: storage(),
		crypto: { randomUUID: require('node:crypto').randomUUID },
		AbortController,
		WPCAP_SCANNER: { checkinUrl: '/tickets/check-in', offlineSyncUrl: '/tickets/offline-sync', nonce: 'n', i18n: {} },
		WPCAP_ADMISSION: { checkinUrl: '/admission/check-in', checkoutUrl: '/admission/check-out', offlineSyncUrl: '/admission/offline-sync', areas: [], i18n: {} },
		WPCAP_SCANNER_PRIVACY: { owner: 'u1' },
		setTimeout: (fn, ms) => { timers.push({ fn, at: clock + (ms || 0), done: false }); return timers.length; },
		clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1].done = true; },
		requestAnimationFrame() {},
		addEventListener() {},
		fetch: (url, options) => { env.requests.push({ url, options, body: JSON.parse(options.body) }); return env.fetchImpl(url, options); },
		Date, JSON, Math, Promise, Array, String, Object, RegExp, Error, isNaN, parseInt, console,
	};
	window.window = window;
	vm.runInContext(source, vm.createContext(window));
	return Object.assign(env, { window, el, logic: window.wpcapScannerLogic, queue: () => JSON.parse(window.localStorage.getItem('wpcap-offline-scans-v2-u1') || '[]') });
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
	// The sync button is the only <button> the scanner appends to its root.
	const root = env.window.document.querySelector('.wpcap-scanner');
	const syncButton = root.find((c) => c.tagName === 'BUTTON');
	syncButton.dispatch('click');
	await flush();
	assert.strictEqual(env.queue().length, 1, 'nothing removed');
	assert.strictEqual(env.el.result.dataset.state, 'error');
});
