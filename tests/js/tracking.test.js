'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, Event } = require('./lib/load');

function storage() {
	const m = new Map();
	return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) };
}

function page(url, referrer) {
	const env = load('assets/tracking.js', {
		url: url || 'https://example.test/tickets/',
		window: { sessionStorage: storage() },
		setup(doc, h) {
			doc.referrer = referrer || '';
			doc.body.appendChild(h('form.wpcap-checkout-form', [
				h('input.wpcap-qty#q1', { type: 'number', value: '0', 'data-type': '11', 'data-price': '2500', 'data-currency': 'EUR', 'data-name': 'Standard' }),
				h('input.wpcap-qty#q2', { type: 'number', value: '0', 'data-type': '12', 'data-price': '1000', 'data-currency': 'EUR', 'data-name': 'Ermäßigt' }),
				h('input', { type: 'email', name: 'email', value: 'anna@example.com' }),
			]));
		},
	});
	const events = [];
	env.document.addEventListener('wpcap:track', (e) => events.push(e.detail));
	env.exports.init(env.window);
	return { env, events };
}

test('view_item lists the ticket types without personal data', () => {
	const { events } = page();
	assert.equal(events.length, 1);
	assert.equal(events[0].event, 'view_item');
	assert.deepEqual(events[0].items.map((i) => i.item_id), ['11', '12']);
	assert.equal(events[0].currency, 'EUR');
	assert.ok(!JSON.stringify(events[0]).includes('anna@'), 'no e-mail in events');
});

test('quantity changes fire add_to_cart / remove_from_cart with the difference', () => {
	const { env, events } = page();
	const q = env.document.getElementById('q1');
	q.value = '3'; q.dispatchEvent(new Event('input', { bubbles: true }));
	q.value = '1'; q.dispatchEvent(new Event('input', { bubbles: true }));
	assert.deepEqual(events.slice(1).map((e) => [e.event, e.items[0].quantity, e.value]), [['add_to_cart', 3, 75], ['remove_from_cart', 2, 50]]);
});

test('begin_checkout adds the session attribution to the payload; purchase uses the short reference only', () => {
	const { env, events } = page('https://example.test/tickets/?utm_source=Newsletter&utm_medium=email&utm_campaign=sommer%2F2027&email=x@y.z', 'https://www.partner.example/blog/post?id=1');
	const form = env.document.querySelector('.wpcap-checkout-form');
	const q = env.document.getElementById('q2');
	q.value = '2'; q.dispatchEvent(new Event('input', { bubbles: true }));
	const payload = {};
	env.document.dispatchEvent(new env.window.CustomEvent('wpcap:checkout-payload', { detail: { form, items: [], payload, error: '' } }));
	assert.deepEqual(payload.attribution, { source: 'newsletter', medium: 'email', campaign: 'sommer2027', ref: 'www.partner.example' });
	env.document.dispatchEvent(new env.window.CustomEvent('wpcap:checkout-complete', { detail: { form, order: { reference: '3F2A-9C1E', total_minor: 1800, currency: 'EUR', public_id: 'secret' } } }));
	const begin = events.find((e) => e.event === 'begin_checkout');
	const purchase = events.find((e) => e.event === 'purchase');
	assert.equal(begin.value, 20);
	assert.equal(purchase.transaction_id, '3F2A-9C1E');
	assert.equal(purchase.value, 18);
	assert.ok(!JSON.stringify(purchase).includes('secret'));
});

test('a blocked checkout (payload error) fires no begin_checkout', () => {
	const { env, events } = page();
	const form = env.document.querySelector('.wpcap-checkout-form');
	env.document.dispatchEvent(new env.window.CustomEvent('wpcap:checkout-payload', { detail: { form, payload: {}, error: 'Sitzplatz fehlt' } }));
	assert.equal(events.filter((e) => e.event === 'begin_checkout').length, 0);
});
