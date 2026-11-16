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

async function load() {
	const doc = new FakeDocument();
	const h = builder(doc);
	const clock = timers();
	const qty = h('input', { class: 'wpcap-qty', type: 'number', value: '3', 'data-type': '11', 'data-name': 'Parkett' });
	const box = h('div', { class: 'wpcap-seating', 'data-url': '/seats', 'data-i18n': JSON.stringify({ yourSeats: 'Deine Plätze', none: 'Noch keine Plätze gewählt.', remove: 'Entfernen', removed: '%s entfernt.', added: '%s gewählt.', ticketType: 'Ticketart' }) }, [h('p', { class: 'wpcap-seating-status', role: 'status' })]);
	const form = h('form', { class: 'wpcap-checkout-form' }, [qty, box]);
	doc.body.appendChild(form);
	let fetches = 0;
	const data = { width: 300, height: 100, seats: [seat(1, 40), seat(2, 80), seat(3, 120)], types: { 11: ['p'] }, zones: [{ key: 'p', name: 'Parkett' }], categories: [{ key: 'c', name: 'Kat. 1', color: '#135e96' }], areas: [] };
	const sandbox = {
		document: doc, Event: FakeEvent, CustomEvent: FakeCustomEvent, JSON, Map, Math, Number, String, Array, Object, Promise, parseInt,
		setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, setInterval: clock.setInterval, clearInterval: clock.clearInterval,
		fetch: async () => { fetches++; return { ok: true, json: async () => JSON.parse(JSON.stringify(data)) }; },
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
