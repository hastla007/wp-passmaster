/**
 * Loads assets/checkout.js into a node:vm sandbox with the fake DOM (fake-dom.js), a scripted
 * fetch(), sessionStorage and location. card() builds the markup of templates/event-card.php that
 * the script relies on (class names, name attributes, field wrappers).
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { FakeEvent, createDocument, builder } = require('./fake-dom-r2.js');

// WPCAP_CHECKOUT_SRC: run the tests against another copy (e.g. the previous version, to see them fail).
const source = fs.readFileSync(process.env.WPCAP_CHECKOUT_SRC || path.join(__dirname, '..', '..', 'assets', 'checkout.js'), 'utf8');

function storage() {
	const map = new Map();
	return {
		get length() { return map.size; },
		key: (i) => Array.from(map.keys())[i] || null,
		getItem: (k) => (map.has(k) ? map.get(k) : null),
		setItem: (k, v) => { map.set(k, String(v)); },
		removeItem: (k) => { map.delete(k); },
		dump: () => Object.fromEntries(map),
	};
}

function response(status, body, { json = true } = {}) {
	const text = typeof body === 'string' ? body : JSON.stringify(body);
	return { status, ok: status >= 200 && status < 300, json: async () => { if (!json) throw new SyntaxError('Unexpected token <'); return JSON.parse(text); } };
}

/** Markup of one event card form (templates/event-card.php, reduced to what checkout.js reads). */
function card(h, opts) {
	const o = Object.assign({ occurrence: 7, id: 'wpcap-7', types: [{ id: 5, price: 2500, name: 'Standard' }], provider: 'stripe', billing: true }, opts || {});
	const types = o.types.map((type) => h('div', { class: 'wpcap-type wpcap-field' }, [
		h('label', { for: o.id + '-' + type.id }, [type.name]),
		h('input', { id: o.id + '-' + type.id, class: 'wpcap-qty', type: 'number', min: '0', max: '10', value: '0', 'data-type': String(type.id), 'data-price': String(type.price), 'data-currency': 'EUR', 'data-name': type.name, 'data-fields': '[]' }),
	]));
	const children = [
		h('fieldset', { class: 'wpcap-types' }, types),
		h('p', { class: 'wpcap-qty-live' }),
		h('p', { class: 'wpcap-estimate' }),
		h('div', { class: 'wpcap-field' }, [h('label', { for: o.id + '-email' }, ['E-Mail *']), h('input', { id: o.id + '-email', name: 'email', type: 'email', required: true })]),
		h('div', { class: 'wpcap-attendees' }),
	];
	if (o.billing) {
		children.push(h('fieldset', { class: 'wpcap-billing' }, [
			h('div', { class: 'wpcap-field wpcap-billing-toggle' }, [h('input', { id: o.id + '-billing-enabled', type: 'checkbox', name: 'billing_enabled', value: '1', 'aria-controls': o.id + '-billing-fields' })]),
			h('div', { class: 'wpcap-billing-fields', id: o.id + '-billing-fields', hidden: true }, [
				h('div', { class: 'wpcap-field' }, [h('label', { for: o.id + '-billing-city' }, ['Ort *']), h('input', { id: o.id + '-billing-city', name: 'billing_city', type: 'text', required: true })]),
			]),
		]));
	}
	children.push(h('input', { type: 'hidden', name: 'provider', value: o.provider }));
	children.push(h('button', { type: 'submit', class: 'wpcap-submit' }, ['Bestellen']));
	children.push(h('p', { class: 'wpcap-message' }));
	const form = h('form', { class: 'wpcap-checkout-form', id: o.id, 'data-occurrence': String(o.occurrence), 'data-heading-level': '3' }, children);
	return h('article', { class: 'wpcap-event' }, [form]);
}

/**
 * @param {object} opts cards: list of card() options; markup: (h) => nodes instead of cards; search:
 *                      location.search; session: preset sessionStorage entries; i18n: extra strings;
 *                      config: extra WPCAP_CHECKOUT keys; fetch: (url, options, env) => response
 *                      (also for requests the script sends while loading).
 */
function loadCheckout(opts) {
	const o = Object.assign({ cards: [{}], search: '', session: {}, i18n: {} }, opts || {});
	const document = createDocument();
	const h = builder(document);
	if (o.markup) o.markup(h).forEach((node) => document.body.appendChild(node));
	else o.cards.forEach((c) => document.body.appendChild(card(h, c)));
	const session = storage();
	Object.keys(o.session).forEach((k) => session.setItem(k, o.session[k]));
	const env = {
		requests: [],
		assigned: [],
		answers: [],
		fetchImpl: async (url, options) => (o.fetch ? o.fetch(url, options, env) : (env.answers.length ? env.answers.shift() : response(201, { reference: 'AB12CD', status: 'complete', payment_status: 'paid' }))),
	};
	const listeners = {};
	let now = 1700000000000;
	class SandboxDate extends Date {
		constructor(...args) { if (args.length) super(...args); else super(now); }
		static now() { return now; }
	}
	const window = {
		document,
		Node: { DOCUMENT_POSITION_FOLLOWING: 4 },
		Event: FakeEvent,
		CustomEvent: FakeEvent,
		WeakMap, Map, JSON, Math, Promise, Array, String, Object, RegExp, Error, Number, parseInt, isNaN, console,
		Date: SandboxDate,
		sessionStorage: session,
		location: { search: o.search, hash: o.hash || '', pathname: '/tickets/', href: 'https://example.test/tickets/' + o.search + (o.hash || ''), assign: (url) => env.assigned.push(url) },
		crypto: { randomUUID: require('node:crypto').randomUUID },
		setTimeout: (fn) => { fn(); return 0; },
		clearTimeout() {},
		addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
		fetch: (url, options) => { env.requests.push({ url, options, body: options && typeof options.body === 'string' ? JSON.parse(options.body) : null }); return env.fetchImpl(url, options); },
		encodeURIComponent,
		WPCAP_CHECKOUT: Object.assign({ url: '/checkout', i18n: Object.assign({ redirecting: 'Weiterleitung', creating: 'Anlegen', failed: 'Fehlgeschlagen.' }, o.i18n), money: { decimals: { EUR: 2 }, symbols: { EUR: '€' } } }, o.config || {}),
	};
	window.window = window;
	window.self = window;
	window.Date = SandboxDate;
	vm.runInContext(source, vm.createContext(window));
	const forms = document.querySelectorAll('.wpcap-checkout-form');
	return Object.assign(env, {
		window, document, h, forms, session,
		advance(ms) { now += ms; },
		fire(type, event) { (listeners[type] || []).forEach((fn) => fn(event)); },
		logic: window.wpcapCheckoutLogic,
		response,
	});
}

const flush = async () => { for (let i = 0; i < 20; i++) await new Promise((r) => setImmediate(r)); };

/** Fills quantity and e-mail of a form and submits it; resolves after the request settled. */
async function submit(env, form, { qty = 1, email = 'buyer@example.com' } = {}) {
	const input = form.querySelector('.wpcap-qty');
	input.value = String(qty);
	input.dispatchEvent(new FakeEvent('input', { bubbles: true }));
	form.querySelector('[name="email"]').value = email;
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
}

module.exports = { loadCheckout, submit, flush, response, FakeEvent, card };
