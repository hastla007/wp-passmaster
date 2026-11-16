'use strict';
/*
 * assets/checkout.js – audit R2 (experts #7, #9): attendee numbering, quantity stepper, participant
 * field types and the attendee-name option, "Kostenlos bestellen", live availability (polling,
 * countdown, sold-out → waitlist), waitlist sign-up with double opt-in hint, REST nonce for
 * signed-in buyers, automatic retry of busy checkouts with the same key, 409 with remaining tickets.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event } = require('./lib/load');

const I18N = {
	attendee: 'Teilnehmer:in %d', name: 'Name', email: 'E-Mail', estimate: 'Summe: %s', selectTickets: 'Bitte Tickets wählen.',
	errRequired: '%s fehlt.', errEmail: '%s ist ungültig.', errorSummary: 'Bitte prüfen', creating: '…', choose: '– bitte wählen –',
	attendeeCount: 'Angaben für %d Ticket(s)', attendeeEmailToggle: 'Eigene E-Mail', payButton: 'Zahlungspflichtig bestellen',
	freeButton: 'Kostenlos bestellen', soldOut: 'Ausverkauft', salesFrom: 'Verkauf ab %s', salesIn: 'Verkaufsstart in %s',
	salesOpen: 'Der Verkauf hat begonnen.', hours: '%d Std.', minutes: '%d Min.', seconds: '%d Sek.', remaining: 'Nur noch %d verfügbar',
	waitlistOpen: 'Auf die Warteliste', waitlistOpenLabel: 'Für „%s“ auf die Warteliste setzen', waitlistEmail: 'E-Mail für die Warteliste',
	waitlistSubmit: 'Eintragen', waitlistConfirm: 'Bitte bestätige den Link in der E-Mail.', waitlistFailed: 'Hat nicht geklappt.',
	retrying: 'Neuer Versuch …', thanks: 'Danke!', orderNumber: 'Bestellnummer: %s', sentTo: 'An %s', pending: '%d Minuten',
};

function qty(h, id, attrs) {
	return h('div.wpcap-type.wpcap-field', { 'data-type-row': attrs['data-type'], 'data-state': attrs.disabled ? 'soldout' : 'available' }, [
		h('div.wpcap-type-info', [h('label', { for: id }, attrs['data-name']), h('span.wpcap-type-status')]),
		h('div.wpcap-qty-control', attrs.disabled ? { hidden: true } : {}, [
			h('button.wpcap-step', { type: 'button', 'data-step': '-1', 'aria-controls': id, 'aria-label': 'Anzahl verringern' }, '−'),
			h('input.wpcap-qty#' + id, Object.assign({ type: 'number', min: '0', value: '0', 'data-currency': 'EUR', 'data-fields': '[]' }, attrs)),
			h('button.wpcap-step', { type: 'button', 'data-step': '1', 'aria-controls': id, 'aria-label': 'Anzahl erhöhen' }, '+'),
		]),
	]);
}

function checkout(opts) {
	opts = opts || {};
	return load('assets/checkout.js', {
		url: 'https://example.test/konzerte/?wpcap_occ=7#tickets',
		window: { WPCAP_CHECKOUT: Object.assign({ url: 'https://example.test/wp-json/wp-pass-captain/v1/checkout', money: { decimal: ',', thousands: '.', symbols: { EUR: '€' } }, i18n: I18N }, opts.config || {}) },
		fetch: opts.fetch,
		setup(doc, h) {
			doc.body.appendChild(h('form.wpcap-checkout-form', { 'data-heading-level': '3', 'data-event': '5', 'data-occurrence': '7', 'data-waitlist': '1' }, [
				h('fieldset.wpcap-types', [
					qty(h, 'q1', { max: '4', 'data-type': '11', 'data-price': opts.price === undefined ? '2500' : String(opts.price), 'data-name': 'Standard', 'data-attendee-name': opts.nameMode || 'optional', 'data-fields': JSON.stringify(opts.fields || []) }),
					qty(h, 'q2', { max: '2', 'data-type': '12', 'data-price': '0', 'data-name': 'Freier Eintritt' }),
					qty(h, 'q3', Object.assign({ max: '0', 'data-type': '13', 'data-price': '1000', 'data-name': 'Galerie', disabled: true }, opts.q3 || {})),
				]),
				h('p.wpcap-estimate'),
				h('div.wpcap-field', [h('label', { for: 'em' }, 'E-Mail *'), h('input#em', { type: 'email', name: 'email', required: true })]),
				h('div.wpcap-field', [h('label', { for: 'bn' }, 'Dein Name (optional)'), h('input#bn', { type: 'text', name: 'buyer_name' })]),
				h('p.wpcap-attendee-status', { role: 'status' }),
				h('div.wpcap-attendees'),
				h('p.wpcap-message', { role: 'status' }),
				h('button', { type: 'submit' }, opts.label || 'Zahlungspflichtig bestellen'),
			]));
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
	return env.document.querySelectorAll('.wpcap-attendee-legend').map((l) => l.textContent);
}

test('attendee numbering follows the current order after a quantity change (reused fieldsets get new legends)', () => {
	const env = checkout();
	setQty(env, 'q1', 2);
	setQty(env, 'q2', 1);
	setQty(env, 'q1', 3);
	assert.deepEqual(legends(env), ['Teilnehmer:in 1 · Standard', 'Teilnehmer:in 2 · Standard', 'Teilnehmer:in 3 · Standard', 'Teilnehmer:in 4 · Freier Eintritt']);
	setQty(env, 'q1', 1);
	assert.deepEqual(legends(env), ['Teilnehmer:in 1 · Standard', 'Teilnehmer:in 2 · Freier Eintritt']);
	// Short status message instead of a live region around all attendee fields.
	assert.equal(env.document.querySelector('.wpcap-attendees').getAttribute('aria-live'), null);
	assert.equal(env.document.querySelector('.wpcap-attendee-status').textContent, 'Angaben für 2 Ticket(s)');
});

test('stepper buttons change the quantity within 0..max and fire input/change', () => {
	const env = checkout();
	const [minus, plus] = env.document.querySelectorAll('.wpcap-step');
	const input = env.document.getElementById('q1');
	let changes = 0;
	input.addEventListener('change', () => { changes++; });
	assert.equal(minus.disabled, true, 'minus disabled at 0');
	for (let i = 0; i < 6; i++) plus.click();
	assert.equal(input.value, '4', 'stops at max');
	assert.equal(plus.disabled, true);
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 4, 'attendee fields follow the stepper');
	minus.click();
	assert.equal(input.value, '3');
	assert.equal(changes, 5);
	assert.equal(plus.getAttribute('aria-label'), 'Anzahl erhöhen');
});

test('attendee name option: required adds a required name field, off leaves it out; e-mail is folded away', () => {
	let env = checkout({ nameMode: 'required' });
	setQty(env, 'q1', 1);
	const name = env.document.querySelector('.wpcap-attendee [data-key="__name"]');
	assert.ok(name.required);
	assert.equal(env.document.querySelector('label[for="' + name.id + '"]').textContent, 'Name *');
	assert.ok(env.document.querySelector('.wpcap-attendee details.wpcap-attendee-email [data-key="__email"]'), 'e-mail inside <details>');
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
	const fetch = mockFetch(() => ({ status: 201, json: { status: 'complete' } }));
	const env = checkout({ fields, fetch });
	setQty(env, 'q1', 1);
	const fs = env.document.querySelector('.wpcap-attendee');
	const select = fs.querySelector('select[data-key="size"]');
	assert.deepEqual(select.querySelectorAll('option').map((o) => o.value), ['', 'S', 'M']);
	assert.equal(fs.querySelectorAll('input[type="radio"][data-key="menu"]').length, 2);
	assert.equal(fs.querySelector('.wpcap-radio-group legend').textContent, 'Menü *');
	assert.equal(fs.querySelector('[data-key="born"]').type, 'date');
	assert.equal(fs.querySelector('[data-key="phone"]').type, 'tel');
	const form = env.document.querySelector('form');
	env.document.getElementById('em').value = 'a@example.com';
	let errors = env.exports.validate(form).map((e) => e.message);
	assert.ok(errors.includes('Größe fehlt.'));
	assert.ok(errors.includes('Menü fehlt.'));
	select.value = 'M';
	fs.querySelectorAll('input[type="radio"]')[1].checked = true;
	assert.deepEqual(env.exports.validate(form), []);
	form.dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.deepEqual(fetch.calls[0].body.attendees[0].answers, { size: 'M', menu: 'Vegan', born: '', phone: '' });
});

test('"Kostenlos bestellen" when only free tickets are selected, "Zahlungspflichtig bestellen" with paid ones', () => {
	const env = checkout();
	const button = env.document.querySelector('button[type="submit"]');
	setQty(env, 'q2', 1);
	assert.equal(button.textContent, 'Kostenlos bestellen');
	setQty(env, 'q1', 1);
	assert.equal(button.textContent, 'Zahlungspflichtig bestellen');
	setQty(env, 'q1', 0);
	setQty(env, 'q2', 0);
	assert.equal(button.textContent, 'Zahlungspflichtig bestellen', 'default label without selection');
});

test('applyAvailability: sold out disables the type and offers the waitlist; available updates max and clamps', () => {
	const env = checkout();
	setQty(env, 'q1', 4);
	env.exports.applyAvailability({ types: { 11: { status: 'available', max: 2, remaining: 2, sales_start: null }, 13: { status: 'available', max: 6, remaining: null, sales_start: null } } });
	const q1 = env.document.getElementById('q1');
	assert.equal(q1.max, '2');
	assert.equal(q1.value, '2', 'selection clamped to the new maximum');
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 2);
	assert.match(q1.closest('.wpcap-type').querySelector('.wpcap-type-status').textContent, /Nur noch 2 verfügbar/);
	const q3 = env.document.getElementById('q3');
	assert.equal(q3.disabled, false, 'type that became available again is enabled');
	assert.equal(q3.closest('.wpcap-qty-control').hidden, false);
	env.exports.applyAvailability({ types: { 11: { status: 'soldout', max: 0, remaining: 0, sales_start: null } } });
	assert.equal(q1.disabled, true);
	assert.equal(q1.value, '0');
	assert.equal(q1.closest('.wpcap-qty-control').hidden, true);
	const row = q1.closest('.wpcap-type');
	assert.equal(row.querySelector('.wpcap-soldout').textContent, 'Ausverkauft');
	const open = row.querySelector('.wpcap-waitlist .wpcap-waitlist-open');
	assert.ok(open, 'waitlist button appears');
	assert.equal(open.getAttribute('aria-label'), 'Für „Standard“ auf die Warteliste setzen');
	assert.equal(env.document.querySelectorAll('.wpcap-attendee').length, 0);
});

test('countdown text for an upcoming sale and the opening state', () => {
	const { exports: c } = checkout();
	assert.equal(c.countdownText(2 * 86400000), null, 'more than a day: server text stays');
	assert.equal(c.countdownText((2 * 3600 + 5 * 60) * 1000), 'Verkaufsstart in 2 Std. 5 Min.');
	assert.equal(c.countdownText(42 * 1000), 'Verkaufsstart in 42 Sek.');
	assert.equal(c.countdownText(0), 'Der Verkauf hat begonnen.');
});

test('polling: asks /availability for the types on the page, applies the answer, backs off on 429, pauses while hidden', async () => {
	let answer = { status: 200, json: { types: { 13: { status: 'available', max: 3, remaining: 3, sales_start: null } } } };
	const fetch = mockFetch(() => answer);
	const env = checkout({ fetch, config: { availabilityUrl: 'https://example.test/wp-json/wp-pass-captain/v1/availability', poll: 20 } });
	assert.equal(fetch.calls.length, 0, 'first check is scheduled, not immediate');
	env.timers.run();
	await flush();
	assert.equal(fetch.calls.length, 1);
	assert.equal(fetch.calls[0].url, 'https://example.test/wp-json/wp-pass-captain/v1/availability?types=11,12,13');
	assert.equal(env.document.getElementById('q3').disabled, false);
	const next = [...env.timers.pending.values()].find((x) => !x.repeat);
	assert.ok(next.ms >= 20000 && next.ms < 23000, 'next poll after the interval plus jitter');
	answer = { status: 429, headers: { 'Retry-After': '90' }, json: {} };
	env.timers.run();
	await flush();
	const backoff = [...env.timers.pending.values()].find((x) => !x.repeat);
	assert.equal(backoff.ms, 90000, 'Retry-After respected');
	env.document.hidden = true;
	env.timers.run();
	await flush();
	assert.equal(fetch.calls.length, 2, 'no request while the page is hidden');
	env.document.hidden = false;
	env.document.dispatchEvent(new Event('visibilitychange'));
	env.timers.run();
	await flush();
	assert.equal(fetch.calls.length, 3, 'resumes when visible again');
});

test('pollDelay: the next sales start comes before the regular interval', () => {
	const env = checkout({ q3: { 'data-sales-start': new Date(Date.now() + 5000).toISOString() }, config: { poll: 30 } });
	const delay = env.exports.pollDelay(Date.now());
	assert.ok(delay >= 4000 && delay <= 7500, 'poll right at the sales start (+ jitter): ' + delay);
});

test('waitlist: opens with the buyer e-mail, posts to the event route and asks to confirm the link (double opt-in)', async () => {
	const fetch = mockFetch(() => ({ status: 200, json: { status: 'received', message: 'Danke!', double_opt_in: true } }));
	const env = checkout({ fetch, config: { waitlistUrl: 'https://example.test/index.php?rest_route=/wp-pass-captain/v1/events/0/waitlist', nonce: 'abc123' }, setup(doc, h) {
		doc.getElementById('q3').closest('.wpcap-type').appendChild(h('div.wpcap-waitlist', { 'data-type': '13' }));
	} });
	env.window.WPCAP_CHECKOUT = env.window.WPCAP_CHECKOUT || {};
	const box = env.document.querySelector('.wpcap-waitlist');
	// Built on load from the server-rendered container.
	const open = box.querySelector('.wpcap-waitlist-open');
	const panel = box.querySelector('.wpcap-waitlist-form');
	assert.equal(panel.hidden, true);
	env.document.getElementById('em').value = 'fan@example.com';
	open.click();
	assert.equal(panel.hidden, false);
	assert.equal(open.getAttribute('aria-expanded'), 'true');
	const email = box.querySelector('.wpcap-waitlist-email');
	assert.equal(email.value, 'fan@example.com');
	assert.equal(env.document.querySelector('label[for="' + email.id + '"]').textContent, 'E-Mail für die Warteliste');
	// The waitlist e-mail is not part of the checkout validation.
	assert.ok(!env.exports.validate(env.document.querySelector('form')).some((e) => e.input === email));
	box.querySelector('.wpcap-waitlist-submit').click();
	await flush();
	assert.equal(fetch.calls.length, 1);
	assert.equal(fetch.calls[0].url, 'https://example.test/index.php?rest_route=/wp-pass-captain/v1/events/5/waitlist');
	assert.equal(fetch.calls[0].init.method, 'POST');
	assert.deepEqual(fetch.calls[0].body, { occurrence_id: 7, ticket_type_id: 13, email: 'fan@example.com' });
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], 'abc123');
	assert.equal(box.querySelector('.wpcap-waitlist-status').textContent, 'Danke! Bitte bestätige den Link in der E-Mail.');
	assert.equal(panel.hidden, true);
});

test('waitlist: invalid e-mail is refused in the browser', async () => {
	const fetch = mockFetch(() => ({ status: 200, json: {} }));
	const env = checkout({ fetch, config: { waitlistUrl: '/wp-json/wp-pass-captain/v1/events/0/waitlist' }, setup(doc, h) { doc.getElementById('q3').closest('.wpcap-type').appendChild(h('div.wpcap-waitlist', { 'data-type': '13' })); } });
	const box = env.document.querySelector('.wpcap-waitlist');
	box.querySelector('.wpcap-waitlist-open').click();
	box.querySelector('.wpcap-waitlist-email').value = 'kaputt';
	box.querySelector('.wpcap-waitlist-submit').click();
	await flush();
	assert.equal(fetch.calls.length, 0);
	assert.equal(box.querySelector('.wpcap-waitlist-email').getAttribute('aria-invalid'), 'true');
});

function submitReady(env) {
	setQty(env, 'q1', 1);
	env.document.getElementById('em').value = 'b@example.com';
	env.document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true }));
}

test('checkout sends the REST nonce only when configured, plus buyer name and the return page', async () => {
	let fetch = mockFetch(() => ({ status: 201, json: { status: 'complete' } }));
	let env = checkout({ fetch, config: { nonce: 'n0nce' } });
	env.document.getElementById('bn').value = ' Alex Muster ';
	submitReady(env);
	await flush();
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], 'n0nce');
	assert.equal(fetch.calls[0].body.buyer_name, 'Alex Muster');
	assert.equal(fetch.calls[0].body.return_to, 'https://example.test/konzerte/?wpcap_occ=7');
	fetch = mockFetch(() => ({ status: 201, json: { status: 'complete' } }));
	env = checkout({ fetch });
	submitReady(env);
	await flush();
	assert.equal(fetch.calls[0].init.headers['X-WP-Nonce'], undefined, 'guests: no nonce header');
	assert.equal(fetch.calls[0].body.buyer_name, undefined);
});

test('busy server (503, retryable): retried automatically with the same idempotency key after Retry-After', async () => {
	let n = 0;
	const fetch = mockFetch(() => (++n === 1 ? { status: 503, headers: { 'Retry-After': '2' }, json: { message: 'Viel los', data: { status: 503, retryable: true } } } : { status: 201, json: { status: 'complete' } }));
	const env = checkout({ fetch });
	submitReady(env);
	await flush();
	assert.equal(fetch.calls.length, 1);
	assert.equal(env.document.querySelector('.wpcap-message').textContent, 'Neuer Versuch …');
	const timer = [...env.timers.pending.values()].find((x) => !x.repeat);
	assert.ok(timer.ms >= 2000 && timer.ms < 3000, 'Retry-After plus jitter: ' + timer.ms);
	env.timers.run();
	await flush();
	assert.equal(fetch.calls.length, 2);
	assert.equal(fetch.calls[0].body.idempotency_key, fetch.calls[1].body.idempotency_key);
	assert.ok(env.document.querySelector('.wpcap-confirmation'));
});

test('busy server: at most two automatic retries, then the error is shown', async () => {
	const fetch = mockFetch(() => ({ status: 503, json: { message: 'Viel los', data: { status: 503, retryable: true } } }));
	const env = checkout({ fetch });
	submitReady(env);
	for (let i = 0; i < 4; i++) { await flush(); env.timers.run(); }
	await flush();
	assert.equal(fetch.calls.length, 3);
	assert.equal(env.document.querySelector('.wpcap-error-summary a, .wpcap-error-summary li').textContent, 'Viel los');
});

test('409 not enough tickets: the remaining quantity updates the form', async () => {
	const fetch = mockFetch(() => ({ status: 409, json: { message: 'Nicht mehr genügend Tickets.', data: { status: 409, remaining: { 11: 1, 12: 0 } } } }));
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
