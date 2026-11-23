/**
 * A425, owner decision 23.11.2026 (PAngV/PrAG price display): the estimate before the order button
 * names the per-ticket service fee and the per-order processing fee separately and includes both;
 * a changed price (409 price_changed / occurrence_changed) is shown next to the ticket type including
 * its per-ticket fee.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, submit, flush, response, FakeEvent } = require('./checkout-harness.js');

const I18N = {
	estimate: 'Summe: %s',
	estimateFee: 'inkl. %s Servicegebühr',
	estimateOrderFee: 'inkl. %s Bearbeitungsgebühr je Bestellung',
	submitTotal: 'Summe: %s',
	free: 'kostenlos',
};

/** Card with fee data attributes like templates/event-card.php (shown price incl. the per-ticket fee). */
function feeCard(h, { orderFee = 200, types = [{ id: 5, price: 2500, fee: 150, name: 'Standard' }] } = {}) {
	const rows = types.map((type) => h('div', { class: 'wpcap-type wpcap-field' }, [
		h('label', { for: 'wpcap-7-' + type.id }, [type.name + ' — ', h('span', { class: 'wpcap-type-price' }, ['shown'])]),
		h('input', Object.assign({ id: 'wpcap-7-' + type.id, class: 'wpcap-qty', type: 'number', min: '0', max: '10', value: '0', 'data-type': String(type.id), 'data-price': String(type.price), 'data-currency': 'EUR', 'data-name': type.name, 'data-fields': '[]' }, type.fee ? { 'data-fee': String(type.fee) } : {})),
	]));
	const fieldset = h('fieldset', Object.assign({ class: 'wpcap-types' }, orderFee ? { 'data-order-fee': String(orderFee) } : {}), rows);
	const form = h('form', { class: 'wpcap-checkout-form', id: 'wpcap-7', 'data-occurrence': '7', 'data-heading-level': '3' }, [
		fieldset,
		h('p', { class: 'wpcap-qty-live' }),
		h('p', { class: 'wpcap-estimate' }),
		h('div', { class: 'wpcap-field' }, [h('label', { for: 'wpcap-7-email' }, ['E-Mail *']), h('input', { id: 'wpcap-7-email', name: 'email', type: 'email', required: true })]),
		h('div', { class: 'wpcap-attendees' }),
		h('input', { type: 'hidden', name: 'provider', value: 'manual' }),
		h('div', { class: 'wpcap-submit-bar' }, [h('p', { class: 'wpcap-submit-total', 'aria-hidden': 'true' }), h('button', { type: 'submit', class: 'wpcap-submit' }, ['Bestellen'])]),
		h('p', { class: 'wpcap-message' }),
	]);
	return h('article', { class: 'wpcap-event' }, [form]);
}

function setQty(form, type, n) {
	const input = form.querySelector('.wpcap-qty[data-type="' + type + '"]');
	input.value = String(n);
	input.dispatchEvent(new FakeEvent('input', { bubbles: true }));
}

test('A425: the estimate includes both fees and names the per-order processing fee separately', async () => {
	const env = loadCheckout({ markup: (h) => [feeCard(h)], i18n: I18N });
	await flush();
	const form = env.forms[0];
	setQty(form, 5, 2);
	// 2 × 25,00 € + 2 × 1,50 € service fee + 2,00 € processing fee per order.
	assert.strictEqual(form.querySelector('.wpcap-estimate').textContent, 'Summe: 55,00 € inkl. 3,00 € Servicegebühr, inkl. 2,00 € Bearbeitungsgebühr je Bestellung');
	assert.strictEqual(form.querySelector('.wpcap-submit-total').textContent, 'Summe: 55,00 €', 'the sum next to the order button includes every fee');
	setQty(form, 5, 0);
	assert.strictEqual(form.querySelector('.wpcap-estimate').textContent, '', 'no fee without a paid ticket');
});

test('A425: only a per-order fee is named as processing fee, a free selection carries none', async () => {
	const env = loadCheckout({ markup: (h) => [feeCard(h, { types: [{ id: 5, price: 1000, name: 'Standard' }, { id: 6, price: 0, fee: 150, name: 'Frei' }] })], i18n: I18N });
	await flush();
	const form = env.forms[0];
	setQty(form, 6, 2);
	assert.strictEqual(form.querySelector('.wpcap-estimate').textContent, '', 'free tickets: no fee, no sum');
	setQty(form, 5, 1);
	assert.strictEqual(form.querySelector('.wpcap-estimate').textContent, 'Summe: 12,00 € inkl. 2,00 € Bearbeitungsgebühr je Bestellung');
});

test('A425: price_changed without a server text shows the new price including the per-ticket fee', async () => {
	const env = loadCheckout({ markup: (h) => [feeCard(h)], i18n: I18N });
	const form = env.forms[0];
	env.answers.push(response(409, { code: 'wpcap_price_changed', message: 'Preis geändert.', data: { status: 409, field: 'items', prices: { 5: { shown_minor: 2500, unit_minor: 3000 } } } }));
	await submit(env, form, { qty: 1 });
	assert.strictEqual(form.querySelector('.wpcap-type-price').textContent, '31,50 €', '30,00 € ticket + 1,50 € service fee');
	assert.strictEqual(form.querySelector('.wpcap-qty').dataset.price, '3000', 'the ticket price itself is sent as shown price');
	assert.strictEqual(form.querySelector('.wpcap-estimate').textContent, 'Summe: 33,50 € inkl. 1,50 € Servicegebühr, inkl. 2,00 € Bearbeitungsgebühr je Bestellung');
});

test('A425: a changed state (409) updates the price next to the type with the server text incl. fee', async () => {
	const env = loadCheckout({ markup: (h) => [feeCard(h)], i18n: I18N });
	const form = env.forms[0];
	env.answers.push(response(409, { code: 'wpcap_occurrence_changed', message: 'Termin geändert.', data: { status: 409, reason: 'occurrence_changed', state: { revision: 3, prices: { 5: 2800 }, prices_formatted: { 5: '29,50 €' }, label: 'Sa., 1. Mai 2027, 20:00' } } }));
	await submit(env, form, { qty: 1 });
	assert.strictEqual(form.querySelector('.wpcap-type-price').textContent, '29,50 €');
	assert.strictEqual(form.querySelector('.wpcap-qty').dataset.price, '2800');
	assert.strictEqual(form.dataset.revision, '3');
});
