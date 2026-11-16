/**
 * Checkout script (assets/checkout.js), frontend round 4 (fake DOM fake-dom-r2.js):
 * A395 focus after asynchronous waitlist actions.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, flush, response, FakeEvent } = require('./checkout-harness.js');

/** Waitlist form of templates/event-card.php (reduced). */
function waitlistCard(h) {
	const select = h('select', { id: 'wpcap-7-waitlist-type', name: 'ticket_type_id' }, [
		h('option', { value: '5', 'data-max': '4' }, ['Standard']),
		h('option', { value: '6', 'data-max': '2' }, ['Ermäßigt']),
	]);
	select.value = '5';
	const form = h('form', { class: 'wpcap-waitlist-form', id: 'wpcap-7-waitlist', 'data-event': '3', 'data-occurrence': '7' }, [
		h('fieldset', {}, [
			h('div', { class: 'wpcap-field wpcap-waitlist-type' }, [select]),
			h('div', { class: 'wpcap-field' }, [h('input', { id: 'wpcap-7-waitlist-qty', name: 'quantity', type: 'number', min: '1', max: '4', value: '1' })]),
			h('div', { class: 'wpcap-field' }, [h('input', { name: 'email', type: 'email', required: true })]),
			h('button', { type: 'submit' }, ['Auf die Warteliste setzen']),
			h('p', { class: 'wpcap-message', role: 'status' }),
		]),
	]);
	return h('article', { class: 'wpcap-event' }, [form]);
}

async function joinWaitlist(answer) {
	const env = loadCheckout({ markup: (h) => [waitlistCard(h)], config: { eventsUrl: '/wp-json/pass-captain/v1/events/' }, i18n: { waitlistJoined: 'Du stehst auf der Warteliste.' }, fetch: () => answer });
	await flush();
	const form = env.document.querySelector('.wpcap-waitlist-form');
	form.querySelector('[name="email"]').value = 'wl@example.com';
	const button = form.querySelector('button');
	// As in browsers: disabling the focused button drops the focus to <body>.
	let disabled = false;
	Object.defineProperty(button, 'disabled', { get: () => disabled, set: (v) => { disabled = !!v; if (disabled && env.document.activeElement === button) env.document.activeElement = env.document.body; } });
	button.focus();
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	return { env, form, button, message: form.querySelector('.wpcap-message') };
}

test('A395: after joining the waitlist the focus moves to the confirmation, not to <body>', async () => {
	const { env, button, message } = await joinWaitlist(response(201, { status: 'waiting' }));
	assert.strictEqual(message.textContent, 'Du stehst auf der Warteliste.');
	assert.ok(button.disabled, 'the form is done');
	assert.strictEqual(message.getAttribute('tabindex'), '-1');
	assert.strictEqual(env.document.activeElement, message);
});

test('A395: a failed waitlist request gives the focus back to the button', async () => {
	const { env, button, message } = await joinWaitlist(response(409, { code: 'wpcap_waitlist_closed', message: 'Warteliste geschlossen.' }));
	assert.strictEqual(message.textContent, 'Warteliste geschlossen.');
	assert.strictEqual(button.disabled, false);
	assert.strictEqual(env.document.activeElement, button);
});

/* ------------------------------------------------------------------ A409 */

/** Order card with the submit labels of templates/event-card.php; types: [[id, price]]. */
function labelCard(h, types) {
	const rows = types.map(([id, price]) => h('div', { class: 'wpcap-type wpcap-field' }, [
		h('label', { for: 'wpcap-7-' + id }, ['Typ ' + id + ' — ', h('span', { class: 'wpcap-type-price' }, [price ? '10,00 €' : 'kostenlos'])]),
		h('input', { id: 'wpcap-7-' + id, class: 'wpcap-qty', type: 'number', min: '0', max: '5', value: '0', 'data-type': String(id), 'data-price': String(price), 'data-currency': 'EUR', 'data-name': 'Typ ' + id, 'data-fields': '[]' }),
	]));
	const form = h('form', { class: 'wpcap-checkout-form', id: 'wpcap-7', 'data-occurrence': '7' }, [
		h('fieldset', { class: 'wpcap-types' }, rows),
		h('p', { class: 'wpcap-qty-live' }),
		h('p', { class: 'wpcap-estimate' }),
		h('div', { class: 'wpcap-field' }, [h('input', { id: 'wpcap-7-email', name: 'email', type: 'email', required: true })]),
		h('div', { class: 'wpcap-attendees' }),
		h('input', { type: 'hidden', name: 'provider', value: 'manual' }),
		h('button', { type: 'submit', class: 'wpcap-submit', 'data-label-paid': 'Zahlungspflichtig bestellen', 'data-label-free': 'Kostenlos anmelden' }, [types.every((t) => !t[1]) ? 'Kostenlos anmelden' : 'Zahlungspflichtig bestellen']),
		h('p', { class: 'wpcap-message' }),
	]);
	return h('article', { class: 'wpcap-event' }, [form]);
}
function setQty(form, id, n) {
	const input = form.querySelector('.wpcap-qty[data-type="' + id + '"]');
	input.value = String(n);
	input.dispatchEvent(new FakeEvent('input', { bubbles: true }));
}

test('A409: a card with only free tickets says "Kostenlos anmelden"; a paid selection says "Zahlungspflichtig bestellen"', async () => {
	const free = loadCheckout({ markup: (h) => [labelCard(h, [[5, 0]])] });
	await flush();
	setQty(free.forms[0], 5, 2);
	assert.strictEqual(free.forms[0].querySelector('.wpcap-submit').textContent, 'Kostenlos anmelden');
	const mixed = loadCheckout({ markup: (h) => [labelCard(h, [[5, 0], [6, 1000]])] });
	await flush();
	const form = mixed.forms[0];
	const button = form.querySelector('.wpcap-submit');
	assert.strictEqual(button.textContent, 'Zahlungspflichtig bestellen', 'nothing selected, paid types offered');
	setQty(form, 5, 1);
	assert.strictEqual(button.textContent, 'Kostenlos anmelden', 'only the free type selected');
	setQty(form, 6, 1);
	assert.strictEqual(button.textContent, 'Zahlungspflichtig bestellen', 'a paid type selected');
	setQty(form, 6, 0);
	setQty(form, 5, 0);
	assert.strictEqual(button.textContent, 'Zahlungspflichtig bestellen');
});

test('A409: a price that becomes 0 (409 price_changed) shows "kostenlos" next to the type', async () => {
	const env = loadCheckout({ markup: (h) => [labelCard(h, [[6, 1000]])], i18n: { free: 'kostenlos' } });
	await flush();
	const form = env.forms[0];
	setQty(form, 6, 1);
	form.querySelector('[name="email"]').value = 'a@example.com';
	env.answers.push(response(409, { code: 'wpcap_price_changed', message: 'Preis geändert.', data: { status: 409, prices: { 6: { unit_minor: 0, formatted: '0,00 €' } } } }));
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	assert.strictEqual(form.querySelector('.wpcap-type-price').textContent, 'kostenlos');
	assert.strictEqual(form.querySelector('.wpcap-submit').textContent, 'Kostenlos anmelden');
});
