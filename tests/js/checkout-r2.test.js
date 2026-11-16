/**
 * Checkout script (assets/checkout.js) in Node with a small fake DOM (fake-dom.js):
 * A308 two cards of the same date: the billing toggle and error links stay in their own card;
 * A309 only a JSON answer with order number and status counts as success, the idempotency key
 *      survives an unclear answer; A310 back from the payment page (bfcache or reload) points to the
 *      pending order and reuses its key; A576 the shown unit prices travel with the order, and a
 *      price_changed answer updates the card.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, submit, FakeEvent } = require('./checkout-harness.js');

test('A308: the billing toggle opens the fields of its own card, also with duplicate ids', () => {
	// A theme template that still renders `wpcap-{occurrence}` twice: both cards carry the same ids.
	const env = loadCheckout({ cards: [{ id: 'wpcap-7' }, { id: 'wpcap-7' }] });
	const [first, second] = env.forms;
	const toggle = second.querySelector('[name="billing_enabled"]');
	toggle.checked = true;
	toggle.dispatchEvent(new FakeEvent('change', { bubbles: true }));
	assert.strictEqual(second.querySelector('.wpcap-billing-fields').hidden, false, 'second card opens its own fields');
	assert.strictEqual(first.querySelector('.wpcap-billing-fields').hidden, true, 'first card stays closed');
});

test('A308: inline errors are added to the field of the submitted card', async () => {
	const env = loadCheckout({ cards: [{ id: 'wpcap-7' }, { id: 'wpcap-7' }] });
	const [first, second] = env.forms;
	// First card already shows an error for its e-mail field.
	await submit(env, first, { qty: 1, email: '' });
	assert.ok(first.querySelector('.wpcap-field-error'), 'first card marks its field');
	await submit(env, second, { qty: 1, email: '' });
	assert.ok(second.querySelector('.wpcap-field-error'), 'second card marks its own field, although an element with the same error id exists elsewhere');
	assert.strictEqual(env.requests.length, 0, 'invalid forms are not sent');
});
