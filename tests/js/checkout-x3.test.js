/**
 * Checkout script (assets/checkout.js), frontend round 4 (fake DOM fake-dom-r2.js):
 * A395 focus after asynchronous waitlist actions; A409 free-ticket labels; A410 waitlist limits;
 * A500 forms added after loading; A504 missing date anchors.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, flush, response, FakeEvent, card } = require('./checkout-harness.js');

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

/* ------------------------------------------------------------------ A410 */

test('A410: the waitlist quantity limit follows the chosen ticket type', async () => {
	const env = loadCheckout({ markup: (h) => [waitlistCard(h)] });
	await flush();
	const form = env.document.querySelector('.wpcap-waitlist-form');
	const qty = form.querySelector('[name="quantity"]');
	const select = form.querySelector('select[name="ticket_type_id"]');
	assert.strictEqual(qty.max, '4', 'first type: its own limit');
	qty.value = '4';
	select.value = '6';
	select.dispatchEvent(new FakeEvent('change', { bubbles: true }));
	assert.strictEqual(qty.max, '2', 'limit of the second type');
	assert.strictEqual(qty.value, '2', 'a larger quantity is lowered to the limit');
	select.value = '5';
	select.dispatchEvent(new FakeEvent('change', { bubbles: true }));
	assert.strictEqual(qty.max, '4');
});

/* ------------------------------------------------------------------ A504 */

test('A504: a date whose anchor is missing on the page gets it on its first card, and the page scrolls to it', async () => {
	// Card of date 7 rendered as "-i2" (the first render was discarded), card of date 8 with its anchor.
	const env = loadCheckout({
		hash: '#wpcap-occ-7',
		markup: (h) => [
			h('article', { class: 'wpcap-event' }, [h('form', { class: 'wpcap-checkout-form', id: 'wpcap-7-i2', 'data-occurrence': '7' }, [h('button', { type: 'submit' }, ['Bestellen'])])]),
			h('article', { class: 'wpcap-event' }, [h('form', { class: 'wpcap-checkout-form', id: 'wpcap-7-i3', 'data-occurrence': '7' }, [h('button', { type: 'submit' }, ['Bestellen'])])]),
			h('article', { class: 'wpcap-event', id: 'wpcap-occ-8' }, [h('form', { class: 'wpcap-checkout-form', id: 'wpcap-8', 'data-occurrence': '8' }, [h('button', { type: 'submit' }, ['Bestellen'])])]),
			h('article', { class: 'wpcap-event wpcap-event-presale', 'data-occurrence': '9' }, []),
		],
	});
	await flush();
	const cards = env.document.querySelectorAll('article');
	assert.strictEqual(cards[0].id, 'wpcap-occ-7', 'first card of the date');
	assert.strictEqual(cards[1].id, '', 'only once');
	assert.strictEqual(env.document.querySelectorAll('#wpcap-occ-8').length, 1, 'an existing anchor stays the only one');
	assert.strictEqual(cards[3].id, 'wpcap-occ-9', 'presale cards too');
	assert.strictEqual(env.document.scrolledTo, cards[0], 'scrolled to the requested date');
});

/* ------------------------------------------------------------------ A500 */

test('A500: a form inserted later is set up by WPCAP.init(root) and by the wpcap:init event, each only once', async () => {
	const env = loadCheckout({ markup: () => [], config: { liveUrl: '/live' }, fetch: (url) => (url.indexOf('/live') === 0 ? response(200, { occurrences: {}, legal: {} }) : response(201, { reference: 'AB12CD', status: 'complete' })) });
	await flush();
	assert.strictEqual(typeof env.window.WPCAP.init, 'function');
	const h = env.h;
	// Markup as delivered by an AJAX filter: submit button still disabled (A450).
	const first = card(h, { occurrence: 21, id: 'wpcap-21' });
	first.querySelector('button[type="submit"]').disabled = true;
	first.querySelector('button[type="submit"]').setAttribute('data-wpcap-requires-js', '');
	env.document.body.appendChild(first);
	assert.strictEqual(env.window.WPCAP.init(first), 1, 'one form set up');
	const form = first.querySelector('form');
	assert.strictEqual(form.querySelectorAll('.wpcap-step').length, 2, 'stepper added');
	assert.strictEqual(form.querySelector('button[type="submit"]').disabled, false, 'submit enabled');
	await flush();
	assert.ok(env.requests.some((r) => /\/live\?occurrences=21(&|$)/.test(r.url)), 'live state requested for the new date');
	assert.strictEqual(env.window.WPCAP.init(first), 0, 'a second call changes nothing');
	assert.strictEqual(form.querySelectorAll('.wpcap-step').length, 2, 'no second stepper');
	const second = card(h, { occurrence: 22, id: 'wpcap-22' });
	env.document.body.appendChild(second);
	env.document.dispatchEvent(new FakeEvent('wpcap:init', { detail: { root: second } }));
	assert.strictEqual(second.querySelectorAll('.wpcap-step').length, 2, 'set up by the event');
	// The delegated handlers work for the new form: quantity, attendee blocks and the order.
	const qty = second.querySelector('.wpcap-qty');
	qty.value = '1';
	qty.dispatchEvent(new FakeEvent('input', { bubbles: true }));
	second.querySelector('[name="email"]').value = 'late@example.com';
	second.querySelector('form').dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	assert.ok(env.requests.some((r) => r.body && r.body.email === 'late@example.com'), 'the order was sent');
	assert.ok(second.querySelector('.wpcap-confirmation') || second.querySelector('form').hidden, 'confirmation shown');
});
