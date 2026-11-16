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
