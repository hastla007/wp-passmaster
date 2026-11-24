/**
 * Add-on products (Sales\Addons, data-kind="addon" in templates/event-card.php): an add-on alone is no
 * selection of tickets (the server refuses it as well), add-ons do not count towards the per-person
 * ticket total (data-max-total), and their price is part of the order sum.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, flush, FakeEvent } = require('./checkout-harness.js');

function addonCard(h, { maxTotal = 0 } = {}) {
	const qty = (id, price, name, extra) => h('div', { class: 'wpcap-type wpcap-field' }, [
		h('label', { for: 'wpcap-7-' + id }, [name]),
		h('input', Object.assign({ id: 'wpcap-7-' + id, class: 'wpcap-qty', type: 'number', min: '0', max: '10', value: '0', 'data-type': String(id), 'data-price': String(price), 'data-currency': 'EUR', 'data-name': name, 'data-fields': '[]' }, extra || {})),
	]);
	const form = h('form', { class: 'wpcap-checkout-form', id: 'wpcap-7', 'data-occurrence': '7', 'data-heading-level': '3' }, [
		h('fieldset', Object.assign({ class: 'wpcap-types' }, maxTotal ? { 'data-max-total': String(maxTotal) } : {}), [qty(5, 2500, 'Standard')]),
		h('fieldset', { class: 'wpcap-addons' }, [qty(9, 800, 'Parkplatz', { 'data-kind': 'addon', 'data-attendee-name': 'off' })]),
		h('p', { class: 'wpcap-qty-live' }),
		h('p', { class: 'wpcap-estimate' }),
		h('div', { class: 'wpcap-field' }, [h('label', { for: 'wpcap-7-email' }, ['E-Mail *']), h('input', { id: 'wpcap-7-email', name: 'email', type: 'email', required: true })]),
		h('div', { class: 'wpcap-attendees' }),
		h('input', { type: 'hidden', name: 'provider', value: 'manual' }),
		h('button', { type: 'submit', class: 'wpcap-submit' }, ['Bestellen']),
		h('p', { class: 'wpcap-message' }),
	]);
	return h('article', { class: 'wpcap-event' }, [form]);
}

function setQty(form, type, n) {
	const input = form.querySelector('.wpcap-qty[data-type="' + type + '"]');
	input.value = String(n);
	input.dispatchEvent(new FakeEvent('input', { bubbles: true }));
}

async function submitForm(env, form) {
	form.querySelector('[name="email"]').value = 'buyer@example.com';
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
}

test('Add-ons: an add-on alone is no ticket selection and sends no order', async () => {
	const env = loadCheckout({ markup: (h) => [addonCard(h)], i18n: { selectTickets: 'Bitte Tickets wählen.' } });
	await flush();
	const form = env.forms[0];
	setQty(form, 9, 1);
	const before = env.requests.filter((r) => r.options && 'POST' === r.options.method).length;
	await submitForm(env, form);
	assert.strictEqual(env.requests.filter((r) => r.options && 'POST' === r.options.method).length, before, 'no checkout request for an add-on alone');
});

test('Add-ons: ticket plus add-on is sent as two items; add-ons do not count towards the ticket total', async () => {
	const env = loadCheckout({ markup: (h) => [addonCard(h, { maxTotal: 2 })] });
	await flush();
	const form = env.forms[0];
	setQty(form, 5, 2);
	setQty(form, 9, 2);
	await submitForm(env, form);
	const order = env.requests.find((r) => r.body && Array.isArray(r.body.items));
	assert.ok(order, 'checkout request sent although 2 tickets + 2 add-ons exceed a total of 2');
	assert.deepStrictEqual(order.body.items.map((i) => [i.ticket_type_id, i.quantity]).sort(), [[5, 2], [9, 2]]);
});
