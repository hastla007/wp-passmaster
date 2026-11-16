// MERGE-CHECK: Arbeitsliste version of tests/js/seating-picker.test.js (both development lines tested the same script with
// their own harness). Keep the checks that match the merged script; the other file is the audit-branch version.
/**
 * A312: seat picker (assets/seating/picker.js) in Node with the fake DOM. The 30 s refresh must not
 * rebuild the list of chosen seats (keyboard focus would fall to <body>), and "Entfernen" moves
 * focus to the next seat's button, else the previous one, else the heading.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { FakeDocument, FakeEvent, FakeCustomEvent, builder, timers } = require('./fake-dom');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'seating', 'picker.js'), 'utf8');

function seat(id, x) { return { id, kind: 'seat', label: 'Reihe 1, Platz ' + id, seat: String(id), row: '1', zone: 'p', category: 'c', x, y: 40, status: 'free', accessible: false }; }

async function flush() { for (let i = 0; i < 10; i++) await new Promise((r) => setImmediate(r)); }

async function load(opts) {
	opts = opts || {};
	const doc = new FakeDocument();
	const h = builder(doc);
	const clock = timers();
	const qty = h('input', { class: 'wpcap-qty', type: 'number', value: '3', 'data-type': '11', 'data-name': 'Parkett' });
	const box = h('div', { class: 'wpcap-seating', 'data-url': '/seats', 'data-i18n': JSON.stringify({ yourSeats: 'Deine Plätze', none: 'Noch keine Plätze gewählt.', remove: 'Entfernen', removed: '%s entfernt.', added: '%s gewählt.', ticketType: 'Ticketart' }) }, [h('p', { class: 'wpcap-seating-status', role: 'status' })]);
	const submit = h('button', { type: 'submit' }, ['Bestellen']);
	const form = h('form', { class: 'wpcap-checkout-form' }, [qty, box, submit]);
	doc.body.appendChild(form);
	let fetches = 0;
	const data = { width: 300, height: 100, seats: [seat(1, 40), seat(2, 80), seat(3, 120)], types: { 11: ['p'] }, zones: [{ key: 'p', name: 'Parkett' }], categories: [{ key: 'c', name: 'Kat. 1', color: '#135e96' }], areas: [] };
	const sandbox = {
		document: doc, Event: FakeEvent, CustomEvent: FakeCustomEvent, JSON, Map, Math, Number, String, Array, Object, Promise, parseInt,
		setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, setInterval: clock.setInterval, clearInterval: clock.clearInterval,
		fetch: async () => {
			fetches++;
			if (opts.fail && fetches <= opts.fail) return { ok: false, status: 503, json: async () => ({}) };
			const body = opts.answer ? opts.answer(fetches, data) : data;
			return { ok: true, json: async () => JSON.parse(JSON.stringify(body)) };
		},
	};
	sandbox.window = sandbox;
	vm.createContext(sandbox);
	vm.runInContext(source, sandbox);
	await flush();
	const api = {
		doc, box, clock,
		get fetches() { return fetches; },
		pick(id) { box.querySelector('.wpcap-seat[data-id="' + id + '"]').dispatchEvent(new FakeEvent('click')); },
		button(id) { return box.querySelector('li[data-seat="' + id + '"] .wpcap-seating-remove'); },
		async refresh() { clock.run(30000); await flush(); },
		async wait(ms) { clock.run(ms); await flush(); },
		form, qty, submit,
		payload() { const detail = { form, items: [], payload: {}, error: '', field: null }; doc.dispatchEvent(new FakeCustomEvent('wpcap:checkout-payload', { detail })); return detail; },
	};
	return api;
}

test('A312: the 30 s refresh keeps the focused control of the seat list', async () => {
	const c = await load();
	c.pick(1);
	c.pick(2);
	const button = c.button(2);
	button.focus();
	const before = c.fetches;
	await c.refresh();
	assert.ok(c.fetches > before, 'refresh ran');
	assert.strictEqual(c.doc.activeElement, button, 'same button keeps focus');
	assert.ok(c.doc.body.contains(button), 'button still in the document');
	const select = c.box.querySelector('li[data-seat="1"] select');
	select.focus();
	await c.refresh();
	assert.strictEqual(c.doc.activeElement, select);
});

test('A312: "Entfernen" moves focus to the next, else previous seat, else the heading', async () => {
	const c = await load();
	c.pick(1);
	c.pick(2);
	c.pick(3);
	c.button(2).focus();
	c.button(2).click();
	assert.strictEqual(c.doc.activeElement, c.button(3), 'next seat');
	c.button(3).click();
	assert.strictEqual(c.doc.activeElement, c.button(1), 'previous seat when the last one was removed');
	c.button(1).click();
	const heading = c.box.querySelector('h3');
	assert.strictEqual(c.doc.activeElement, heading, 'heading when the list is empty');
	assert.strictEqual(heading.getAttribute('tabindex'), '-1');
	assert.strictEqual(c.box.querySelector('.wpcap-seating-status').textContent, 'Reihe 1, Platz 1 entfernt.');
});

/* ------------------------------------------------------------------ A392 */

test('A392: after a failed first load the map is built by the retry, without a page reload', async () => {
	const c = await load({ fail: 2 });
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 0, 'no map after the failed load');
	assert.strictEqual(c.fetches, 1);
	await c.wait(5000);
	assert.strictEqual(c.fetches, 2, 'retried after 5 s');
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 0, 'second attempt failed too');
	await c.wait(10000);
	assert.strictEqual(c.fetches, 3, 'next retry after 10 s');
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 3, 'map built by the successful retry');
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seating-legend').length, 1, 'built once');
	c.pick(1);
	await c.refresh();
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seating-legend').length, 1, 'the refresh updates instead of building again');
	assert.strictEqual(c.box.querySelector('.wpcap-seat[data-id="1"]').getAttribute('aria-checked'), 'true');
});

test('A392: a failed refresh keeps the map that is shown', async () => {
	const c = await load({ answer: (n, data) => (n === 2 ? { error: true } : data) });
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 3);
	await c.refresh();
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 3);
	assert.notStrictEqual(c.box.querySelector('.wpcap-seating-status').textContent, 'Error');
});

/* ------------------------------------------------------------------ A610 */

const CLOSED = { occurrence_id: 7, occurrence_status: 'cancelled', sales_open: false, message: 'Dieser Termin wurde abgesagt. Es können keine Plätze mehr gebucht werden.' };

test('A610: a cancelled date shows its reason instead of "load failed" and locks the order', async () => {
	const c = await load({ answer: () => CLOSED });
	assert.strictEqual(c.box.querySelector('.wpcap-seating-status').textContent, CLOSED.message);
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 0, 'no map');
	assert.strictEqual(c.box.dataset.closed, 'cancelled');
	assert.ok(c.qty.disabled && c.submit.disabled, 'quantity and submit are disabled');
	assert.strictEqual(c.payload().error, CLOSED.message, 'the payload hook refuses the order');
	const before = c.fetches;
	await c.refresh();
	assert.strictEqual(c.fetches, before, 'no more polling for a closed date');
});

test('A610: a date that closes while the map is shown drops the map and the chosen seats', async () => {
	const c = await load({ answer: (n, data) => (n >= 2 ? Object.assign({}, CLOSED, { occurrence_status: 'ended', message: 'Der Online-Verkauf für diesen Termin ist beendet.' }) : data) });
	c.pick(1);
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 3);
	await c.refresh();
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seat').length, 0);
	assert.strictEqual(c.box.querySelectorAll('.wpcap-seating-selection').length, 0);
	assert.strictEqual(c.box.querySelector('.wpcap-seating-status').textContent, 'Der Online-Verkauf für diesen Termin ist beendet.');
	assert.strictEqual(c.form.dataset.salesClosed, 'ended');
	assert.ok(c.submit.disabled);
	assert.strictEqual(c.payload().error, 'Der Online-Verkauf für diesen Termin ist beendet.');
});
