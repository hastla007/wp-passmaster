'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event } = require('./lib/load');

const I18N = {
	pending: 'Reserviert für %d Minuten.', pendingHours: 'Reserviert für %d Stunden.', pendingDays: 'Reserviert für %d Tage.',
	estimate: 'Summe: %s', errTooMany: '%1$s: höchstens %2$d', selectTickets: 'Bitte Tickets wählen.', errRequired: '%s fehlt.',
	errEmail: '%s ist ungültig.', errTerms: 'Bitte AGB akzeptieren.', errorSummary: 'Bitte prüfen', errorCount: '%d Fehler',
	attendee: 'Teilnehmer:in %d', orderNumber: 'Bestellnummer: %s', thanks: 'Danke!', sentTo: 'Gesendet an %s', creating: '…',
};

/** Checkout form like templates/event-card.php: two ticket types, buyer e-mail, terms, billing toggle. */
function checkout(opts) {
	opts = opts || {};
	return load('assets/checkout.js', {
		window: { WPCAP_CHECKOUT: { url: 'https://example.test/wp-json/wp-pass-captain/v1/checkout', money: opts.money || { decimal: ',', thousands: '.', symbols: { EUR: '€' } }, i18n: I18N } },
		fetch: opts.fetch,
		setup(doc, h) {
			doc.body.appendChild(h('form.wpcap-checkout-form', { 'data-heading-level': '3' }, [
				h('label', { for: 'q1' }, 'Standard'), h('input.wpcap-qty#q1', { type: 'number', min: '0', max: '4', value: '0', 'data-type': '11', 'data-price': '2500', 'data-currency': 'EUR', 'data-name': 'Standard', 'data-fields': '[]' }),
				h('label', { for: 'q2' }, 'Ermäßigt'), h('input.wpcap-qty#q2', { type: 'number', min: '0', max: '2', value: '0', 'data-type': '12', 'data-price': '120050', 'data-currency': 'EUR', 'data-name': 'Ermäßigt', 'data-fields': JSON.stringify([{ key: 'diet', label: 'Ernährung', type: 'text', required: 1, purpose: 'Catering' }]) }),
				h('p.wpcap-estimate'),
				h('div.wpcap-field', [h('label', { for: 'em' }, 'E-Mail *'), h('input#em', { type: 'email', name: 'email', required: true })]),
				h('label', [h('input', { type: 'checkbox', name: 'accept_terms', required: true }), ' Ich akzeptiere die AGB']),
				h('label', [h('input', { type: 'checkbox', name: 'billing_enabled', 'aria-controls': 'bill' }), ' Rechnung auf Firma']),
				h('div#bill', { hidden: true }, [h('label', { for: 'bc' }, 'Firma'), h('input#bc', { name: 'billing_company' }), h('label', { for: 'bv' }, 'USt-ID (optional)'), h('input#bv', { name: 'billing_vat_id' })]),
				h('input', { type: 'text', name: 'campaign_code' }),
				h('div.wpcap-attendees'),
				h('p.wpcap-message', { role: 'status' }),
				h('button', { type: 'submit' }, 'Kaufen'),
			]));
		},
	});
}
function setQty(env, id, n) {
	const input = env.document.getElementById(id);
	input.value = String(n);
	input.dispatchEvent(new Event('input', { bubbles: true }));
}

test('holdText picks minutes, hours or days', () => {
	const { exports: c } = checkout();
	assert.equal(c.holdText(15), 'Reserviert für 15 Minuten.');
	assert.equal(c.holdText(180), 'Reserviert für 3 Stunden.');
	assert.equal(c.holdText(90), 'Reserviert für 90 Minuten.', 'not a whole number of hours');
	assert.equal(c.holdText(4320), 'Reserviert für 3 Tage.');
	assert.equal(c.holdText(1440), 'Reserviert für 24 Stunden.', 'one day is shown in hours');
	assert.equal(c.holdText('x'), 'Reserviert für 0 Minuten.');
});

test('fill supports sequential and positional placeholders', () => {
	const { exports: c } = checkout();
	assert.equal(c.fill('%s und %s', ['a', 'b']), 'a und b');
	assert.equal(c.fill('%2$s vor %1$s', ['a', 'b']), 'b vor a');
	assert.equal(c.fill('%s fehlt %s', ['a']), 'a fehlt %s', 'missing values stay visible');
});

test('money matches Format::money() (locale separators, symbol, negative)', () => {
	const { exports: c } = checkout();
	assert.equal(c.money(2500, 'EUR'), '25,00 €');
	assert.equal(c.money(123456789, 'EUR'), '1.234.567,89 €');
	assert.equal(c.money(-150, 'EUR'), '-1,50 €');
	assert.equal(c.money(5, 'CHF'), '0,05 CHF', 'unknown symbol falls back to the code');
	const en = checkout({ money: { decimal: '.', thousands: ',', symbols: {} } }).exports;
	assert.equal(en.money(100000, 'USD'), '1,000.00 USD');
});

test('quantity input updates the estimate and attendee fieldsets', () => {
	const env = checkout();
	const est = env.document.querySelector('.wpcap-estimate');
	setQty(env, 'q1', 2);
	assert.equal(est.textContent, 'Summe: 50,00 €');
	setQty(env, 'q2', 1);
	assert.equal(est.textContent, 'Summe: 1.250,50 €');
	const sets = env.document.querySelectorAll('.wpcap-attendee');
	assert.equal(sets.length, 3);
	assert.match(sets[2].querySelector('legend').textContent, /Teilnehmer:in 3 · Ermäßigt/);
	const diet = sets[2].querySelector('[data-key="diet"]');
	assert.ok(diet.required, 'required participant field');
	assert.equal(sets[2].querySelector('label[for="' + diet.id + '"]').textContent, 'Ernährung *');
	// Entered values survive a quantity change of another type.
	sets[0].querySelector('[data-key="__name"]').value = 'Alex';
	setQty(env, 'q2', 0);
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 2);
	assert.equal(env.document.querySelector('.wpcap-attendee [data-key="__name"]').value, 'Alex');
	setQty(env, 'q1', 0);
	assert.equal(est.textContent, '', 'no estimate without tickets');
	setQty(env, 'q1', -3);
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 0, 'negative quantities count as 0');
});

test('validate reports missing tickets, too many tickets, e-mail and terms with label wording', () => {
	const env = checkout();
	const form = env.document.querySelector('form');
	let errors = env.exports.validate(form).map((e) => e.message);
	assert.deepEqual(errors, ['Bitte Tickets wählen.', 'E-Mail fehlt.', 'Bitte AGB akzeptieren.']);
	setQty(env, 'q1', 5);
	env.document.getElementById('em').value = 'keine-adresse';
	errors = env.exports.validate(form).map((e) => e.message);
	assert.ok(errors.includes('Standard: höchstens 4'));
	assert.ok(errors.includes('E-Mail ist ungültig.'));
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'a@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	assert.deepEqual(env.exports.validate(form), []);
	// Hidden billing fields are not validated.
	form.querySelector('[name="billing_company"]').required = true;
	assert.deepEqual(env.exports.validate(form), []);
});

test('showErrors builds a focused alert summary and marks fields; clearErrors restores them', () => {
	const env = checkout();
	const form = env.document.querySelector('form');
	const email = env.document.getElementById('em');
	email.setAttribute('aria-describedby', 'hint');
	env.exports.showErrors(form, env.exports.validate(form));
	const summary = form.querySelector('.wpcap-error-summary');
	assert.equal(summary.getAttribute('role'), 'alert');
	assert.equal(env.document.activeElement, summary);
	assert.equal(summary.querySelector('h3').textContent, 'Bitte prüfen');
	assert.equal(summary.querySelector('p').textContent, '3 Fehler');
	assert.equal(summary.querySelectorAll('a').length, 3);
	assert.equal(email.getAttribute('aria-invalid'), 'true');
	assert.match(email.getAttribute('aria-describedby'), /^hint em-error$/);
	assert.equal(env.document.getElementById('em-error').parentNode.className, 'wpcap-field', 'error inside the field wrapper');
	env.exports.clearErrors(form);
	assert.equal(form.querySelector('.wpcap-error-summary'), null);
	assert.equal(email.getAttribute('aria-invalid'), null);
	assert.equal(email.getAttribute('aria-describedby'), 'hint');
});

test('serverErrorField maps REST error fields to controls and opens the billing section', () => {
	const env = checkout();
	const form = env.document.querySelector('form');
	assert.equal(env.exports.serverErrorField(form, { data: { field: 'items' } }), env.document.getElementById('q1'));
	assert.equal(env.exports.serverErrorField(form, { data: { field: 'email' } }), env.document.getElementById('em'));
	assert.equal(env.exports.serverErrorField(form, {}), null);
	const company = env.exports.serverErrorField(form, { data: { field: 'billing_company' } });
	assert.equal(company, env.document.getElementById('bc'));
	assert.equal(form.querySelector('[name="billing_enabled"]').checked, true);
	assert.equal(env.document.getElementById('bill').hidden, false);
	setQty(env, 'q2', 1);
	const diet = form.querySelector('.wpcap-attendee [data-key="diet"]');
	assert.equal(env.exports.serverErrorField(form, { message: 'Bitte „Ernährung“ ausfüllen.', data: { field: 'attendees' } }), diet);
});

test('billingPayload only when the toggle is on (trimmed values)', () => {
	const env = checkout();
	const form = env.document.querySelector('form');
	assert.equal(env.exports.billingPayload(form), null);
	form.querySelector('[name="billing_enabled"]').checked = true;
	env.document.getElementById('bc').value = '  ACME GmbH ';
	assert.deepEqual(env.exports.billingPayload(form), { enabled: true, company: 'ACME GmbH', vat_id: '' });
});

test('submit posts items, attendees and an idempotency key; confirmation panel gets focus', async () => {
	const fetch = mockFetch(() => ({ status: 201, json: { status: 'pending', reference: '3F2A-9C1E', hold_minutes: 15, total_minor: 2500, currency: 'EUR' } }));
	const env = checkout({ fetch });
	const form = env.document.querySelector('form');
	setQty(env, 'q1', 1);
	form.querySelector('.wpcap-attendee [data-key="__name"]').value = 'Alex Muster';
	env.document.getElementById('em').value = ' buyer@example.com ';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.querySelector('[name="campaign_code"]').value = ' SPRING ';
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 1);
	const body = fetch.calls[0].body;
	assert.equal(body.email, 'buyer@example.com');
	// Merged checkout.js (A576): each item carries the unit price shown on the card.
	assert.deepEqual(body.items, [{ ticket_type_id: 11, quantity: 1, expected_price_minor: 2500 }]);
	assert.deepEqual(body.attendees, [{ ticket_type_id: 11, answers: {}, name: 'Alex Muster', email: '' }]);
	assert.deepEqual(body.campaign_codes, ['SPRING']);
	assert.equal(body.accept_terms, true);
	assert.equal(body.provider, 'manual');
	assert.match(body.idempotency_key, /^[0-9a-f-]{36}$/);
	const panel = env.document.querySelector('.wpcap-confirmation');
	assert.ok(panel);
	assert.equal(env.document.activeElement, panel);
	assert.match(panel.textContent, /Bestellnummer: 3F2A-9C1E/);
	assert.match(panel.textContent, /Reserviert für 15 Minuten\./);
	assert.equal(form.hidden, true);
});

test('server error: message shown at the field, next attempt uses a new idempotency key', async () => {
	let n = 0;
	// A309: only an answer shaped like an API error (code + message) counts as "nothing was booked".
	const fetch = mockFetch(() => (++n === 1 ? { status: 409, json: { code: 'wpcap_sold_out', message: 'Ausverkauft', data: { status: 409, field: 'items' } } } : { status: 201, json: { status: 'complete' } }));
	const env = checkout({ fetch });
	const form = env.document.querySelector('form');
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'b@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(form.querySelector('.wpcap-error-summary a').textContent, 'Ausverkauft');
	assert.equal(env.document.getElementById('q1').getAttribute('aria-invalid'), 'true');
	assert.equal(form.querySelector('button').disabled, false);
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 2);
	assert.notEqual(fetch.calls[0].body.idempotency_key, fetch.calls[1].body.idempotency_key);
});

test('network error keeps the idempotency key for the retry (no duplicate order)', async () => {
	let n = 0;
	const fetch = mockFetch(() => { if (++n === 1) throw new TypeError('Failed to fetch'); return { status: 201, json: { status: 'complete' } }; });
	const env = checkout({ fetch });
	const form = env.document.querySelector('form');
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'b@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 2);
	assert.equal(fetch.calls[0].body.idempotency_key, fetch.calls[1].body.idempotency_key);
});

test('network error: a changed quantity, e-mail or code discards the kept idempotency key (QA-1)', async () => {
	let n = 0;
	const fetch = mockFetch(() => { if (++n <= 3) throw new TypeError('Failed to fetch'); return { status: 201, json: { status: 'complete' } }; });
	const env = checkout({ fetch });
	const form = env.document.querySelector('form');
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'b@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	setQty(env, 'q1', 2);
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	env.document.getElementById('em').value = 'other@example.com';
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	form.querySelector('[name="campaign_code"]').value = 'SPRING';
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 4);
	const keys = fetch.calls.map((c) => c.body.idempotency_key);
	assert.equal(new Set(keys).size, 4, 'every changed attempt gets its own key');
});

test('network error: an unchanged retry with only different e-mail case keeps the key', async () => {
	let n = 0;
	const fetch = mockFetch(() => { if (++n === 1) throw new TypeError('Failed to fetch'); return { status: 201, json: { status: 'complete' } }; });
	const env = checkout({ fetch });
	const form = env.document.querySelector('form');
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'b@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	env.document.getElementById('em').value = 'B@Example.com';
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls[0].body.idempotency_key, fetch.calls[1].body.idempotency_key);
});

test('client validation errors block the request', async () => {
	const fetch = mockFetch(() => ({ status: 201, json: {} }));
	const env = checkout({ fetch });
	env.document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 0);
	assert.ok(env.document.querySelector('.wpcap-error-summary'));
});
