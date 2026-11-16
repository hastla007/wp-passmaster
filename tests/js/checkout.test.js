/**
 * Buyer checkout form (assets/checkout.js) in Node with the fake DOM from fake-dom.js.
 * A549: attendee entries survive lowering and raising the quantity again.
 * A550: attendee legends are numbered by position after every change.
 * A311: the attendee area is no live region; a short note goes to .wpcap-qty-live instead.
 * A551: error messages for attendee fields name the attendee block; server errors with
 *       data.index / data.ticket_type_id + data.position / data.field_key point at the right field.
 * A553: likely typo in the buyer e-mail domain: hint with a fix button, one soft stop on submit,
 *       note on the confirmation panel.
 * A450: the submit buttons of the order and waitlist forms ship disabled and are enabled by the script.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { FakeDocument, FakeEvent, FakeCustomEvent, builder, timers } = require('./fake-dom');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'checkout.js'), 'utf8');

const I18N = {
	attendee: 'Teilnehmer:in %d', name: 'Name', email: 'E-Mail der Teilnehmer:in (optional, für ein eigenes Ticket)',
	attendeesOne: 'Weiter unten: Angaben für 1 Teilnehmer:in.', attendeesMany: 'Weiter unten: Angaben für %d Teilnehmende.',
	qtyNone: '%s: keine Tickets', qtyOne: '%s: 1 Ticket', qtyMany: '%1$s: %2$d Tickets', stepUp: '%s: ein Ticket mehr', stepDown: '%s: ein Ticket weniger',
	errorSummary: 'Bitte prüfe deine Angaben', errRequired: 'Bitte fülle „%s“ aus.', errEmail: '„%s“: Bitte gib eine gültige E-Mail-Adresse ein.',
	errInvalid: '„%s“ ist ungültig.', selectTickets: 'Bitte wähle mindestens ein Ticket.', failed: 'Bestellung fehlgeschlagen.',
	/* translators-like placeholders as in PublicEvents.php */
	attendeeError: '%1$s: %2$s',
	emailSuggest: 'Meintest du %s?', emailSuggestApply: '%s übernehmen',
	emailTypo: 'Bitte prüfe deine E-Mail-Adresse: Meintest du %1$s? Sende das Formular erneut ab, um %2$s zu verwenden.',
	sentTo: 'Wir haben die Bestätigung an %s gesendet.', wrongEmail: 'Adresse falsch? Melde dich mit der Bestellnummer beim Veranstalter.',
	thanks: 'Vielen Dank für deine Bestellung!', orderNumber: 'Bestellnummer: %s', pending: '%d Minuten reserviert.',
};
const DOMAINS = ['gmail.com', 'gmx.de', 'gmx.at', 'gmx.net', 'web.de', 't-online.de', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com'];

/**
 * Two ticket types; "Erwachsene" (type 11) has one required participant field "Verein".
 * Returns the sandbox, the form and helpers.
 */
function load(options) {
	options = options || {};
	const doc = new FakeDocument();
	const h = builder(doc);
	const clock = timers();
	const qtyA = h('input', { id: 'f-11', class: 'wpcap-qty', type: 'number', min: '0', max: '5', value: '0', 'data-type': '11', 'data-price': '1000', 'data-currency': 'EUR', 'data-name': 'Erwachsene', 'data-fields': JSON.stringify([{ key: 'club', label: 'Verein', type: 'text', required: 1 }]) });
	const qtyB = h('input', { id: 'f-12', class: 'wpcap-qty', type: 'number', min: '0', max: '5', value: '0', 'data-type': '12', 'data-price': '500', 'data-currency': 'EUR', 'data-name': 'Kinder', 'data-fields': '[]' });
	const email = h('input', { id: 'f-email', name: 'email', type: 'email', autocomplete: 'email', required: true });
	const attendees = h('div', { class: 'wpcap-attendees' });
	const form = h('form', { class: 'wpcap-checkout-form', id: 'f', 'data-heading-level': '3', novalidate: true }, [
		h('fieldset', { class: 'wpcap-types' }, [
			h('legend', {}, ['Tickets']),
			h('div', { class: 'wpcap-type wpcap-field' }, [h('label', { for: 'f-11' }, ['Erwachsene — 10,00 €']), qtyA]),
			h('div', { class: 'wpcap-type wpcap-field' }, [h('label', { for: 'f-12' }, ['Kinder — 5,00 €']), qtyB]),
		]),
		h('p', { class: 'wpcap-qty-live wpcap-sr-only', 'aria-live': 'polite', 'aria-atomic': 'true' }),
		h('p', { class: 'wpcap-estimate', 'aria-live': 'polite' }),
		h('div', { class: 'wpcap-field' }, [h('label', { for: 'f-email' }, ['Deine E-Mail-Adresse (für Tickets und Bestätigung) *']), email]),
		attendees,
		h('button', Object.assign({ type: 'submit', class: 'wpcap-submit' }, options.buttonAttrs || {}), ['Zahlungspflichtig bestellen']),
		h('p', { class: 'wpcap-message', role: 'status', 'aria-live': 'polite' }),
	]);
	const article = h('article', { class: 'wpcap-event' }, [form]);
	if (options.waitlist) {
		article.appendChild(h('form', { class: 'wpcap-waitlist-form', method: 'post', action: '#', 'data-event': '5', 'data-occurrence': '7', novalidate: true }, [
			h('input', { type: 'hidden', name: 'ticket_type_id', value: '11' }),
			h('input', { name: 'quantity', type: 'number', value: '1' }),
			h('input', { name: 'email', type: 'email', required: true }),
			h('button', Object.assign({ type: 'submit', class: 'wp-element-button' }, options.buttonAttrs || {}), ['Auf die Warteliste setzen']),
			h('p', { class: 'wpcap-message', role: 'status', 'aria-live': 'polite' }),
		]));
	}
	doc.body.appendChild(article);
	const requests = [];
	const sandbox = {
		document: doc, Node: { DOCUMENT_POSITION_FOLLOWING: 4 }, Event: FakeEvent, CustomEvent: FakeCustomEvent,
		setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, setInterval: clock.setInterval, clearInterval: clock.clearInterval,
		crypto: { randomUUID: () => 'uuid-' + requests.length },
		location: { assign() {}, href: 'https://example.test/tickets/', search: '' },
		addEventListener() {}, removeEventListener() {},
		sessionStorage: (() => { const m = new Map(); return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; })(),
		WeakMap, Map, JSON, Math, Number, String, Array, Object, Promise, Date, parseInt, encodeURIComponent,
		fetch: async (url, init) => {
			const body = JSON.parse(init.body);
			requests.push({ url, body });
			const reply = options.reply ? options.reply(body) : { status: 201, data: { status: 'pending_payment', reference: 'AB12-CD34', hold_minutes: 15, total_minor: 1000, currency: 'EUR' } };
			return { ok: reply.status >= 200 && reply.status < 300, status: reply.status, json: async () => reply.data };
		},
	};
	sandbox.window = sandbox;
	sandbox.WPCAP_CHECKOUT = { url: '/checkout', i18n: I18N, emailDomains: DOMAINS };
	vm.createContext(sandbox);
	vm.runInContext(source, sandbox);

	const live = form.querySelector('.wpcap-qty-live');
	const api = {
		doc, form, qtyA, qtyB, email, attendees, live, clock, requests,
		/** Types a quantity into the number field (input event, like typing). */
		type(input, value) { input.value = String(value); input.dispatchEvent(new FakeEvent('input', { bubbles: true })); },
		change(input) { input.dispatchEvent(new FakeEvent('change', { bubbles: true })); },
		blocks() { return form.querySelectorAll('.wpcap-attendee'); },
		legends() { return api.blocks().map((b) => b.querySelector('legend').textContent); },
		field(block, key) { return block.querySelector('[data-key="' + key + '"]'); },
		step(input, dir) { input.closest('.wpcap-stepper').querySelector(dir > 0 ? '.wpcap-step-up' : '.wpcap-step-down').click(); },
		async submit() {
			form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
			for (let i = 0; i < 10; i++) await new Promise((r) => setImmediate(r));
		},
		summary() { const s = form.querySelector('.wpcap-error-summary'); return s ? s.querySelectorAll('li').map((li) => li.textContent) : []; },
	};
	return api;
}

/* ------------------------------------------------------------------ A549 / A550 */

test('A549: entries survive lowering the quantity and raising it again', () => {
	const c = load();
	c.type(c.qtyA, 3);
	const blocks = c.blocks();
	assert.strictEqual(blocks.length, 3);
	c.field(blocks[0], '__name').value = 'Anna';
	c.field(blocks[1], '__name').value = 'Ben';
	c.field(blocks[2], '__name').value = 'Cleo';
	c.field(blocks[2], 'club').value = 'SV Nord';
	// Correcting the number: field emptied (0) while typing, then 2, then 3 again.
	c.type(c.qtyA, '');
	assert.strictEqual(c.blocks().length, 0);
	c.type(c.qtyA, 2);
	assert.deepStrictEqual(c.blocks().map((b) => c.field(b, '__name').value), ['Anna', 'Ben']);
	c.type(c.qtyA, 3);
	const again = c.blocks();
	assert.deepStrictEqual(again.map((b) => c.field(b, '__name').value), ['Anna', 'Ben', 'Cleo']);
	assert.strictEqual(c.field(again[2], 'club').value, 'SV Nord');
});

test('A549: only attached blocks are submitted', async () => {
	const c = load();
	c.email.value = 'kauf@example.com';
	c.type(c.qtyA, 2);
	c.blocks().forEach((b, i) => { c.field(b, '__name').value = 'P' + i; c.field(b, 'club').value = 'C' + i; });
	c.type(c.qtyA, 1);
	await c.submit();
	assert.strictEqual(c.requests.length, 1);
	assert.deepStrictEqual(c.requests[0].body.attendees.map((a) => a.name), ['P0']);
});

test('A550: legends are renumbered by position after a quantity change', () => {
	const c = load();
	c.type(c.qtyB, 1);
	assert.deepStrictEqual(c.legends(), ['Teilnehmer:in 1 · Kinder']);
	c.type(c.qtyA, 2);
	assert.deepStrictEqual(c.legends(), ['Teilnehmer:in 1 · Erwachsene', 'Teilnehmer:in 2 · Erwachsene', 'Teilnehmer:in 3 · Kinder']);
	c.type(c.qtyA, 0);
	assert.deepStrictEqual(c.legends(), ['Teilnehmer:in 1 · Kinder']);
	c.type(c.qtyA, 1);
	assert.deepStrictEqual(c.legends(), ['Teilnehmer:in 1 · Erwachsene', 'Teilnehmer:in 2 · Kinder']);
});

test('"Weitere Tickets bestellen" starts with empty attendee blocks', async () => {
	const c = load();
	c.email.value = 'kauf@example.com';
	c.type(c.qtyA, 1);
	c.field(c.blocks()[0], '__name').value = 'Anna';
	c.field(c.blocks()[0], 'club').value = 'SV';
	await c.submit();
	const again = c.doc.querySelector('.wpcap-order-again');
	assert.ok(again, 'confirmation panel shown');
	again.click();
	c.type(c.qtyA, 1);
	assert.strictEqual(c.field(c.blocks()[0], '__name').value, '');
});

/* ------------------------------------------------------------------ A311 */

test('A311: stepper announces quantity plus a short attendee note, typing announces the note after a pause', () => {
	const c = load();
	assert.strictEqual(c.attendees.getAttribute('aria-live'), null, 'attendee area must not be a live region');
	c.step(c.qtyA, 1);
	c.clock.run(50);
	assert.strictEqual(c.live.textContent, 'Erwachsene: 1 Ticket Weiter unten: Angaben für 1 Teilnehmer:in.');
	c.clock.run(1000);
	assert.strictEqual(c.live.textContent, 'Erwachsene: 1 Ticket Weiter unten: Angaben für 1 Teilnehmer:in.', 'no second message for the same change');
	c.type(c.qtyB, 2);
	c.clock.run(300);
	assert.strictEqual(c.live.textContent, 'Erwachsene: 1 Ticket Weiter unten: Angaben für 1 Teilnehmer:in.', 'nothing new while typing');
	c.clock.run(500);
	assert.strictEqual(c.live.textContent, 'Weiter unten: Angaben für 3 Teilnehmende.');
	// Unchanged block count (stepper at the limit): only the quantity.
	c.qtyA.setAttribute('max', '1');
	c.step(c.qtyA, 1);
	c.clock.run(50);
	assert.strictEqual(c.live.textContent, 'Erwachsene: 1 Ticket');
});

/* ------------------------------------------------------------------ A551 */

test('A551: client-side errors in attendee blocks name the attendee', async () => {
	const c = load();
	c.email.value = 'kauf@example.com';
	c.type(c.qtyA, 2);
	c.field(c.blocks()[0], 'club').value = 'SV Nord';
	await c.submit();
	assert.strictEqual(c.requests.length, 0);
	assert.deepStrictEqual(c.summary(), ['Teilnehmer:in 2 · Erwachsene: Bitte fülle „Verein“ aus.']);
	const club2 = c.field(c.blocks()[1], 'club');
	assert.strictEqual(club2.getAttribute('aria-invalid'), 'true');
	// The inline note sits inside the block and keeps the short wording.
	assert.strictEqual(c.doc.getElementById(club2.id + '-error').textContent, 'Bitte fülle „Verein“ aus.');
});

test('A551: server error with data.index marks the field of that attendee', async () => {
	const c = load({ reply: () => ({ status: 400, data: { code: 'wpcap_attendee_field_required', message: 'Bitte „Verein“ für jedes Ticket ausfüllen.', data: { status: 400, field: 'attendees', index: 2, ticket_type_id: 11, position: 2, field_key: 'club' } } }) });
	c.email.value = 'kauf@example.com';
	c.type(c.qtyA, 3);
	c.blocks().forEach((b) => { c.field(b, 'club').value = 'x'; });
	await c.submit();
	const club3 = c.field(c.blocks()[2], 'club');
	assert.strictEqual(club3.getAttribute('aria-invalid'), 'true');
	assert.strictEqual(c.field(c.blocks()[0], 'club').getAttribute('aria-invalid'), null);
	assert.deepStrictEqual(c.summary(), ['Teilnehmer:in 3 · Erwachsene: Bitte „Verein“ für jedes Ticket ausfüllen.']);
});

test('A551: server error without index uses ticket type and position', async () => {
	const c = load({ reply: () => ({ status: 400, data: { code: 'wpcap_attendee_field_invalid', message: '„Verein“ ist ungültig.', data: { status: 400, field: 'attendees', ticket_type_id: 11, position: 1, field_key: 'club' } } }) });
	c.email.value = 'kauf@example.com';
	c.type(c.qtyB, 1);
	c.type(c.qtyA, 2);
	c.blocks().forEach((b) => { const f = c.field(b, 'club'); if (f) f.value = 'x'; });
	await c.submit();
	assert.strictEqual(c.field(c.blocks()[1], 'club').getAttribute('aria-invalid'), 'true');
	assert.deepStrictEqual(c.summary(), ['Teilnehmer:in 2 · Erwachsene: „Verein“ ist ungültig.']);
});

/* ------------------------------------------------------------------ A553 */

function typoHint(c) { return c.doc.getElementById(c.email.id + '-typo'); }

test('A553: mistyped mail domain shows "Meintest du …?" with a fix button, linked to the field', () => {
	const c = load();
	const hint = typoHint(c);
	assert.ok(hint, 'hint region exists from the start (live region must exist before it speaks)');
	assert.strictEqual(hint.getAttribute('aria-live'), 'polite');
	assert.ok(c.email.getAttribute('aria-describedby').split(' ').indexOf(hint.id) !== -1);
	const cases = { 'anna@gmial.com': 'anna@gmail.com', 'anna@gmx.dee': 'anna@gmx.de', 'anna@web.d': 'anna@web.de', 'anna@t-onlien.de': 'anna@t-online.de', 'anna@hotmail.con': 'anna@hotmail.com', 'anna@firma.con': 'anna@firma.com' };
	for (const typed of Object.keys(cases)) {
		c.email.value = typed;
		c.change(c.email);
		assert.strictEqual(hint.textContent, 'Meintest du ' + cases[typed] + '? ' + cases[typed] + ' übernehmen', typed);
	}
	for (const fine of ['anna@gmail.com', 'anna@gmx.at', 'anna@firma-xy.at', 'anna@uni-wien.ac.at', 'anna@example.com']) {
		c.email.value = fine;
		c.change(c.email);
		assert.strictEqual(hint.textContent, '', fine);
	}
	c.email.value = 'Max.Muster@GMIAL.com';
	c.change(c.email);
	hint.querySelector('.wpcap-email-fix').click();
	assert.strictEqual(c.email.value, 'Max.Muster@gmail.com');
	assert.strictEqual(hint.textContent, '');
	assert.strictEqual(c.doc.activeElement, c.email);
});

test('A553: one soft stop on submit, the same address sent again goes through', async () => {
	const c = load();
	c.type(c.qtyB, 1);
	c.email.value = 'anna@gmial.com';
	await c.submit();
	assert.strictEqual(c.requests.length, 0);
	assert.deepStrictEqual(c.summary(), ['Bitte prüfe deine E-Mail-Adresse: Meintest du anna@gmail.com? Sende das Formular erneut ab, um anna@gmial.com zu verwenden.']);
	assert.strictEqual(c.email.getAttribute('aria-invalid'), 'true');
	await c.submit();
	assert.strictEqual(c.requests.length, 1);
	assert.strictEqual(c.requests[0].body.email, 'anna@gmial.com');
});

test('A553: confirmation panel says what to do if the address is wrong', async () => {
	const c = load();
	c.type(c.qtyB, 1);
	c.email.value = 'anna@example.com';
	await c.submit();
	const panel = c.doc.querySelector('.wpcap-confirmation');
	assert.ok(panel);
	assert.strictEqual(panel.querySelector('.wpcap-wrong-email').textContent, I18N.wrongEmail);
});


/* ------------------------------------------------------------------ A450 */

test('A450: disabled submit buttons (data-wpcap-requires-js) are enabled once the script runs', async () => {
	const c = load({ waitlist: true, buttonAttrs: { disabled: true, 'data-wpcap-requires-js': '' } });
	const buttons = c.doc.querySelectorAll('button[type="submit"]');
	assert.strictEqual(buttons.length, 2);
	buttons.forEach((b) => {
		assert.strictEqual(b.disabled, false);
		assert.strictEqual(b.hasAttribute('data-wpcap-requires-js'), false);
	});
	c.email.value = 'kauf@example.com';
	c.type(c.qtyB, 1);
	await c.submit();
	assert.strictEqual(c.requests.length, 1);
});

test('A450: other disabled buttons stay disabled', () => {
	const c = load({ buttonAttrs: { disabled: true } });
	assert.strictEqual(c.form.querySelector('button[type="submit"]').disabled, true);
});
