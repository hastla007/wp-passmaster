'use strict';
/*
 * assets/checkout.js – audit R2 (experts #7, #9) against the merged script and card markup
 * (templates/event-card.php): attendee numbering, quantity stepper (A100, built by the script),
 * participant field types and the attendee-name option, the free/paid button label (A409), live
 * state from GET …/checkout/live (A456: limits, sold-out types → waitlist form, polling while
 * visible), waitlist sign-up (double opt-in hint in the answer), REST nonce for signed-in buyers,
 * automatic retry of busy checkouts with the same key, 409 with remaining tickets.
 *
 * Merged design: the per-type countdown, the /availability poll and the per-type waitlist buttons of
 * the audit line were replaced by the live state of the Arbeitsliste line (A456) and the card's
 * waitlist form; the checks below assert that behaviour (see also checkout-live-arbeitsliste.test.js).
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event } = require('./lib/load');

const I18N = {
	attendee: 'Teilnehmer:in %d', name: 'Name', email: 'E-Mail', estimate: 'Summe: %s', selectTickets: 'Bitte Tickets wählen.',
	errRequired: '%s fehlt.', errEmail: '%s ist ungültig.', errorSummary: 'Bitte prüfen', creating: '…', choose: '– bitte wählen –',
	attendeesOne: 'Angaben für 1 Ticket', attendeesMany: 'Angaben für %d Tickets', qtyMany: '%1$s: %2$d', qtyOne: '%s: 1', qtyNone: '%s: 0',
	stepDown: 'Anzahl „%s“ verringern', stepUp: 'Anzahl „%s“ erhöhen',
	waitlistJoining: 'Wird eingetragen …', waitlistJoined: 'Du stehst auf der Warteliste.', waitlistEmail: 'Bitte gib eine gültige E-Mail-Adresse ein.', waitlistFailed: 'Hat nicht geklappt.',
	retrying: 'Neuer Versuch …', thanks: 'Danke!', orderNumber: 'Bestellnummer: %s', sentTo: 'An %s', pending: '%d Minuten',
};
const LIVE_URL = 'https://example.test/wp-json/pass-captain/v1/checkout/live';

/** One ticket type row as rendered by templates/event-card.php. */
function qty(h, id, attrs) {
	const sold = !!attrs.disabled;
	return h('div.wpcap-type.wpcap-field', { 'data-type': attrs['data-type'] }, [
		h('label', { for: id }, [attrs['data-name'] + ' — ', h('span.wpcap-type-price', '0,00 €')]),
		h('span.wpcap-soldout', sold ? {} : { hidden: true }, 'Ausverkauft'),
		h('span.wpcap-qty-box', sold ? { hidden: true } : {}, [
			h('input.wpcap-qty#' + id, Object.assign({ type: 'number', min: '0', value: '0', 'data-currency': 'EUR', 'data-fields': '[]' }, attrs)),
		]),
	]);
}

function checkout(opts) {
	opts = opts || {};
	return load('assets/checkout.js', {
		url: 'https://example.test/konzerte/?wpcap_occurrence=7#tickets',
		window: { WPCAP_CHECKOUT: Object.assign({ url: 'https://example.test/wp-json/pass-captain/v1/checkout', eventsUrl: 'https://example.test/wp-json/pass-captain/v1/events/', money: { decimal: ',', thousands: '.', symbols: { EUR: '€' } }, i18n: I18N }, opts.config || {}) },
		fetch: opts.fetch,
		setup(doc, h) {
			const form = h('form.wpcap-checkout-form', { 'data-heading-level': '3', 'data-event': '5', 'data-occurrence': '7', 'data-waitlist': '1' }, [
				h('fieldset.wpcap-types', [
					qty(h, 'q1', { max: '4', 'data-type': '11', 'data-price': opts.price === undefined ? '2500' : String(opts.price), 'data-name': 'Standard', 'data-attendee-name': opts.nameMode || 'optional', 'data-fields': JSON.stringify(opts.fields || []) }),
					qty(h, 'q2', { max: '2', 'data-type': '12', 'data-price': '0', 'data-name': 'Freier Eintritt' }),
					qty(h, 'q3', Object.assign({ max: '0', 'data-type': '13', 'data-price': '1000', 'data-name': 'Galerie', disabled: true }, opts.q3 || {})),
				]),
				h('p.wpcap-qty-live', { 'aria-live': 'polite' }),
				h('p.wpcap-soldout-note', { hidden: true }, 'Dieser Termin ist ausverkauft.'),
				h('div.wpcap-buy', [
					h('p.wpcap-estimate'),
					h('div.wpcap-field', [h('label', { for: 'em' }, 'E-Mail *'), h('input#em', { type: 'email', name: 'email', required: true })]),
					h('div.wpcap-field', [h('label', { for: 'bn' }, 'Dein Name (optional)'), h('input#bn', { type: 'text', name: 'buyer_name' })]),
					h('div.wpcap-attendees'),
					h('div.wpcap-provider-slot', [h('input', { type: 'hidden', name: 'provider', value: 'manual' })]),
					h('button.wp-element-button.wpcap-submit', { type: 'submit', 'data-label-paid': 'Zahlungspflichtig bestellen', 'data-label-free': 'Kostenlos anmelden' }, 'Zahlungspflichtig bestellen'),
					h('p.wpcap-message', { role: 'status' }),
				]),
			]);
			const waitlist = h('form.wpcap-waitlist-form#wpcap-7-waitlist', { 'data-event': '5', 'data-occurrence': '7' }, [
				h('div.wpcap-field.wpcap-waitlist-type', { hidden: true }, [h('select#wl-type', { name: 'ticket_type_id' }, [h('option', { value: '13', 'data-max': '3' }, 'Galerie')])]),
				h('div.wpcap-field', [h('input', { name: 'quantity', type: 'number', min: '1', max: '3', value: '1' })]),
				h('div.wpcap-field', [h('label', { for: 'wl-em' }, 'E-Mail-Adresse *'), h('input#wl-em', { name: 'email', type: 'email', required: true })]),
				h('button', { type: 'submit' }, 'Auf die Warteliste setzen'),
				h('p.wpcap-message'),
			]);
			doc.body.appendChild(h('article.wpcap-event', [form, waitlist]));
			if (opts.setup) opts.setup(doc, h);
		},
	});
}
function setQty(env, id, n) {
	const input = env.document.getElementById(id);
	input.value = String(n);
	input.dispatchEvent(new Event('input', { bubbles: true }));
}
function legends(env) {
	return env.document.querySelectorAll('.wpcap-attendee legend').map((l) => l.textContent);
}
const live = (types, extra) => ({ status: 200, json: { occurrences: { 7: Object.assign({ on_sale: true, types, providers: [{ id: 'manual', title: 'Überweisung' }], availability: 'Tickets verfügbar', badges: [], waitlist: true, waitlist_types: [], max_total: 0 }, extra || {}) }, legal: { require_terms: false, terms_version: '', locale: 'de_DE' } } });
const typeState = (max, price) => ({ max, available: max, price_minor: price, price: (price / 100).toFixed(2).replace('.', ',') + ' €', base_price: '', price_label: '', currency: 'EUR' });

test('attendee numbering follows the current order after a quantity change (reused fieldsets get new legends)', () => {
	const env = checkout();
	setQty(env, 'q1', 2);
	setQty(env, 'q2', 1);
	setQty(env, 'q1', 3);
	assert.deepEqual(legends(env), ['Teilnehmer:in 1 · Standard', 'Teilnehmer:in 2 · Standard', 'Teilnehmer:in 3 · Standard', 'Teilnehmer:in 4 · Freier Eintritt']);
	setQty(env, 'q1', 1);
	assert.deepEqual(legends(env), ['Teilnehmer:in 1 · Standard', 'Teilnehmer:in 2 · Freier Eintritt']);
	// Short status message (.wpcap-qty-live, A311) instead of a live region around all attendee fields.
	assert.equal(env.document.querySelector('.wpcap-attendees').getAttribute('aria-live'), null);
	env.timers.run();
	env.timers.run();
	assert.equal(env.document.querySelector('.wpcap-qty-live').textContent, 'Angaben für 2 Tickets');
});

test('stepper buttons change the quantity within 0..max and fire input/change', () => {
	const env = checkout();
	const input = env.document.getElementById('q1');
	const wrap = input.closest('.wpcap-stepper');
	assert.ok(wrap, 'the script wraps the quantity field in a stepper (A100)');
	const minus = wrap.querySelector('.wpcap-step-down');
	const plus = wrap.querySelector('.wpcap-step-up');
	let changes = 0;
	input.addEventListener('change', () => { changes++; });
	assert.equal(minus.getAttribute('aria-disabled'), 'true', 'minus at 0 (aria-disabled keeps the focus)');
	for (let i = 0; i < 6; i++) plus.click();
	assert.equal(input.value, '4', 'stops at max');
	assert.equal(plus.getAttribute('aria-disabled'), 'true');
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 4, 'attendee fields follow the stepper');
	minus.click();
	assert.equal(input.value, '3');
	assert.equal(changes, 5);
	assert.equal(plus.getAttribute('aria-label'), 'Anzahl „Standard“ erhöhen');
	assert.equal(plus.getAttribute('aria-controls'), 'q1');
});

test('attendee name option: required adds a required name field, off leaves it out; e-mail per attendee', () => {
	let env = checkout({ nameMode: 'required' });
	setQty(env, 'q1', 1);
	const name = env.document.querySelector('.wpcap-attendee [data-key="__name"]');
	assert.ok(name.required);
	assert.equal(env.document.querySelector('label[for="' + name.id + '"]').textContent, 'Name *');
	assert.ok(env.document.querySelector('.wpcap-attendee [data-key="__email"]'), 'own e-mail per attendee');
	const errors = env.exports.validate(env.document.querySelector('form')).map((e) => e.message);
	assert.ok(errors.includes('Name fehlt.'));
	env = checkout({ nameMode: 'off' });
	setQty(env, 'q1', 1);
	assert.equal(env.document.querySelector('.wpcap-attendee [data-key="__name"]'), null);
});

test('participant field types select, radio, date and tel; required radio group is validated and sent', async () => {
	const fields = [
		{ key: 'size', label: 'Größe', type: 'select', required: 1, options: ['S', 'M'] },
		{ key: 'menu', label: 'Menü', type: 'radio', required: 1, options: ['Fleisch', 'Vegan'] },
		{ key: 'born', label: 'Geburtsdatum', type: 'date', required: 0 },
		{ key: 'phone', label: 'Telefon', type: 'tel', required: 0 },
	];
	const fetch = mockFetch(() => ({ status: 201, json: { status: 'complete', reference: 'AB12-CD34' } }));
	const env = checkout({ fields, fetch });
	setQty(env, 'q1', 1);
	const fs = env.document.querySelector('.wpcap-attendee');
	const select = fs.querySelector('select[data-key="size"]');
	assert.deepEqual(select.querySelectorAll('option').map((o) => o.value), ['', 'S', 'M']);
	const group = fs.querySelector('.wpcap-radio-group[data-key="menu"]');
	assert.equal(group.querySelectorAll('input[type="radio"]').length, 2);
	assert.equal(env.document.getElementById(group.getAttribute('aria-labelledby')).textContent, 'Menü *');
	assert.equal(fs.querySelector('[data-key="born"]').type, 'date');
	assert.equal(fs.querySelector('[data-key="phone"]').type, 'tel');
	const form = env.document.querySelector('form');
	env.document.getElementById('em').value = 'a@example.com';
	let errors = env.exports.validate(form).map((e) => e.message);
	assert.ok(errors.includes('Größe fehlt.'));
	assert.ok(errors.includes('Menü fehlt.'));
	select.value = 'M';
	group.querySelectorAll('input[type="radio"]')[1].checked = true;
	errors = env.exports.validate(form);
	assert.deepEqual(errors, []);
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.deepEqual(fetch.calls[0].body.attendees[0].answers, { size: 'M', menu: 'Vegan', born: '', phone: '' });
});

test('"Kostenlos anmelden" when only free tickets are selected, "Zahlungspflichtig bestellen" with paid ones', () => {
	const env = checkout();
	const button = env.document.querySelector('button.wpcap-submit');
	setQty(env, 'q2', 1);
	assert.equal(button.textContent, 'Kostenlos anmelden');
	setQty(env, 'q1', 1);
	assert.equal(button.textContent, 'Zahlungspflichtig bestellen');
	setQty(env, 'q1', 0);
	setQty(env, 'q2', 0);
	assert.equal(button.textContent, 'Zahlungspflichtig bestellen', 'without selection the card has paid types');
});

test('live state: sold out disables the type and offers the waitlist; available updates max and clamps', async () => {
	let answer = live({ 11: typeState(2, 2500), 12: typeState(2, 0), 13: typeState(6, 1000) });
	const fetch = mockFetch(() => answer);
	const env = checkout({ fetch, config: { liveUrl: LIVE_URL } });
	setQty(env, 'q1', 4);
	await env.exports.refreshLive();
	await flush();
	assert.match(fetch.calls[0].url, /\/checkout\/live\?occurrences=7$/);
	const q1 = env.document.getElementById('q1');
	assert.equal(q1.max, '2');
	assert.equal(q1.value, '2', 'selection clamped to the new maximum');
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 2);
	const q3 = env.document.getElementById('q3');
	assert.equal(q3.disabled, false, 'type that became available again is enabled');
	assert.equal(q3.closest('.wpcap-type').querySelector('.wpcap-qty-box').hidden, false);
	answer = live({ 11: typeState(0, 2500), 12: typeState(2, 0), 13: typeState(6, 1000) }, { waitlist_types: [11] });
	env.document.getElementById('wl-type').appendChild(env.h('option', { value: '11', 'data-max': '4' }, 'Standard'));
	await env.exports.refreshLive();
	await flush();
	assert.equal(q1.disabled, true);
	assert.equal(q1.value, '0');
	const row = q1.closest('.wpcap-type');
	assert.equal(row.querySelector('.wpcap-qty-box').hidden, true);
	assert.equal(row.querySelector('.wpcap-soldout').hidden, false);
	const waitlist = env.document.querySelector('.wpcap-waitlist-form');
	assert.equal(waitlist.hidden, false, 'waitlist form offered');
	assert.equal(env.document.getElementById('wl-type').value, '11');
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 0);
});

test('not on sale (yet): the live state closes the order form until the sale starts', async () => {
	const fetch = mockFetch(() => live({}, { on_sale: false, providers: [] }));
	const env = checkout({ fetch, config: { liveUrl: LIVE_URL } });
	await env.exports.refreshLive();
	await flush();
	assert.equal(env.document.getElementById('q1').disabled, true);
	assert.equal(env.document.querySelector('.wpcap-buy').hidden, true);
	assert.equal(env.document.querySelector('.wpcap-soldout-note').hidden, true, 'not sold out – just not on sale');
});

test('polling: refreshes the live state while visible, catches up when the tab is shown again', async () => {
	const fetch = mockFetch(() => live({ 11: typeState(4, 2500), 12: typeState(2, 0), 13: typeState(3, 1000) }));
	const env = checkout({ fetch, config: { liveUrl: LIVE_URL, poll: 20 } });
	await flush();
	const before = fetch.calls.length;
	const next = [...env.timers.pending.values()].find((x) => !x.repeat && x.ms >= 15000);
	assert.ok(next && next.ms >= 20000 && next.ms < 23000, 'next refresh after the interval plus jitter');
	env.document.hidden = true;
	next.fn();
	await flush();
	assert.equal(fetch.calls.length, before, 'no request while the page is hidden');
	env.document.hidden = false;
	env.document.dispatchEvent(new Event('visibilitychange'));
	await flush();
	assert.equal(fetch.calls.length, before + 1, 'resumes when visible again');
	assert.equal(env.document.getElementById('q3').disabled, false);
});

test('waitlist: posts to the event route and confirms the sign-up', async () => {
	const fetch = mockFetch(() => ({ status: 200, json: { status: 'received', message: 'Danke! Falls die Adresse noch nicht eingetragen war, bekommst du eine Bestätigung per E-Mail.' } }));
	const env = checkout({ fetch });
	const form = env.document.querySelector('.wpcap-waitlist-form');
	form.querySelector('[name="email"]').value = 'fan@example.com';
	// The waitlist e-mail is not part of the checkout validation.
	assert.ok(!env.exports.validate(env.document.querySelector('form.wpcap-checkout-form')).some((e) => e.input === form.querySelector('[name="email"]')));
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 1);
	assert.equal(fetch.calls[0].url, 'https://example.test/wp-json/pass-captain/v1/events/5/waitlist');
	assert.equal(fetch.calls[0].init.method, 'POST');
	assert.deepEqual(fetch.calls[0].body, { occurrence_id: 7, ticket_type_id: 13, quantity: 1, email: 'fan@example.com' });
	assert.equal(form.querySelector('.wpcap-message').textContent, 'Du stehst auf der Warteliste.');
	assert.equal(form.querySelector('button').disabled, true);
});

test('waitlist: invalid e-mail is refused in the browser', async () => {
	const fetch = mockFetch(() => ({ status: 200, json: {} }));
	const env = checkout({ fetch });
	const form = env.document.querySelector('.wpcap-waitlist-form');
	form.querySelector('[name="email"]').value = 'kaputt';
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(fetch.calls.length, 0);
	assert.equal(form.querySelector('[name="email"]').getAttribute('aria-invalid'), 'true');
});

function submitReady(env) {
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'b@example.com';
	env.document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true }));
}

test('checkout sends the REST nonce only when configured, plus buyer name and the return page', async () => {
	let fetch = mockFetch(() => ({ status: 201, json: { status: 'complete', reference: 'AB12-CD34' } }));
	let env = checkout({ fetch, config: { nonce: 'n0nce' } });
	env.document.getElementById('bn').value = ' Alex Muster ';
	submitReady(env);
	await flush();
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], 'n0nce');
	assert.equal(fetch.calls[0].body.buyer_name, 'Alex Muster');
	assert.equal(fetch.calls[0].body.return_to, 'https://example.test/konzerte/?wpcap_occurrence=7');
	fetch = mockFetch(() => ({ status: 201, json: { status: 'complete', reference: 'AB12-CD34' } }));
	env = checkout({ fetch });
	submitReady(env);
	await flush();
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], undefined, 'guests: no nonce header');
	assert.equal(fetch.calls[0].body.buyer_name, undefined);
});

test('busy server (503, retryable): retried automatically with the same idempotency key after Retry-After', async () => {
	let n = 0;
	const fetch = mockFetch(() => (++n === 1 ? { status: 503, headers: { 'Retry-After': '2' }, json: { code: 'wpcap_db_busy', message: 'Viel los', data: { status: 503, retryable: true } } } : { status: 201, json: { status: 'complete', reference: 'AB12-CD34' } }));
	const env = checkout({ fetch });
	submitReady(env);
	await flush();
	assert.equal(fetch.calls.length, 1);
	assert.equal(env.document.querySelector('.wpcap-message').textContent, 'Neuer Versuch …');
	const timer = [...env.timers.pending.values()].find((x) => !x.repeat && x.ms >= 2000);
	assert.ok(timer && timer.ms < 3000, 'Retry-After plus jitter: ' + (timer && timer.ms));
	env.timers.run();
	await flush();
	assert.equal(fetch.calls.length, 2);
	assert.equal(fetch.calls[0].body.idempotency_key, fetch.calls[1].body.idempotency_key);
	assert.ok(env.document.querySelector('.wpcap-confirmation'));
});

test('busy server: at most two automatic retries, then the error is shown', async () => {
	const fetch = mockFetch(() => ({ status: 503, json: { code: 'wpcap_db_busy', message: 'Viel los', data: { status: 503, retryable: true } } }));
	const env = checkout({ fetch });
	submitReady(env);
	for (let i = 0; i < 4; i++) { await flush(); env.timers.run(); }
	await flush();
	assert.equal(fetch.calls.length, 3);
	assert.equal(env.document.querySelector('.wpcap-error-summary a, .wpcap-error-summary li').textContent, 'Viel los');
});

test('409 not enough tickets: the remaining quantity updates the form', async () => {
	const fetch = mockFetch(() => ({ status: 409, json: { code: 'wpcap_insufficient_capacity', message: 'Nicht mehr genügend Tickets.', data: { status: 409, remaining: { 11: 1, 12: 0 } } } }));
	const env = checkout({ fetch });
	setQty(env, 'q1', 3);
	env.document.getElementById('em').value = 'b@example.com';
	env.document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	const q1 = env.document.getElementById('q1');
	assert.equal(q1.max, '1');
	assert.equal(q1.value, '1');
	assert.equal(env.document.getElementById('q2').disabled, true, 'type without tickets left is disabled');
	assert.equal(q1.getAttribute('aria-invalid'), 'true', 'error points at the adjusted type');
});
