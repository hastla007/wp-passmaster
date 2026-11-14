'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event, CustomEvent } = require('./lib/load');

const T = { free: 'frei', taken: 'belegt', selected: 'ausgewählt', accessible: 'rollstuhlgerecht', added: '%s ausgewählt', removed: '%s entfernt', noType: 'Keine passende Ticketart', countMismatch: '%1$s: %2$d von %3$d Plätzen gewählt', seatTaken: 'Ein Platz wurde inzwischen vergeben', help: 'Hilfe', none: '—', ticketType: 'Ticketart' };

/** Seat map data as returned by the seating REST endpoint. */
function plan() {
	return {
		width: 300, height: 200,
		categories: [{ key: 'kat1', name: 'Kategorie 1', color: '#123456' }, { key: 'kat2', name: 'Kategorie 2', color: '#654321' }],
		zones: [{ key: 'parkett', name: 'Parkett' }],
		types: { 11: ['kat1'], 12: ['kat1', 'kat2'] },
		areas: [{ key: 'steh', label: 'Stehplätze', x: 0, y: 150, w: 100, h: 40, category: 'kat2' }],
		seats: [
			{ id: 1, kind: 'seat', label: 'Reihe 1, Platz 1', row: 'Reihe 1', seat: '1', zone: 'parkett', category: 'kat1', status: 'free', x: 20, y: 40 },
			{ id: 2, kind: 'seat', label: 'Reihe 1, Platz 2', row: 'Reihe 1', seat: '2', zone: 'parkett', category: 'kat1', status: 'taken', x: 50, y: 40 },
			{ id: 3, kind: 'seat', label: 'Reihe 1, Platz 3', row: 'Reihe 1', seat: '3', zone: 'parkett', category: 'kat2', status: 'free', accessible: true, x: 80, y: 40 },
			{ id: 4, kind: 'seat', label: 'Reihe 2, Platz 1', row: 'Reihe 2', seat: '1', zone: 'parkett', category: 'kat1', status: 'free', x: 20, y: 70 },
			{ id: 10, kind: 'standing', group: 'steh', label: 'Steh 1', category: 'kat2', status: 'free' },
			{ id: 11, kind: 'standing', group: 'steh', label: 'Steh 2', category: 'kat2', status: 'free' },
			{ id: 12, kind: 'standing', group: 'steh', label: 'Steh 3', category: 'kat2', status: 'taken' },
		],
	};
}

function picker(opts) {
	opts = opts || {};
	const served = { data: opts.data ? opts.data(plan()) : plan() };
	const fetch = mockFetch(() => ({ json: JSON.parse(JSON.stringify(served.data)) }));
	const env = load('assets/seating/picker.js', {
		fetch,
		setup(doc, h) {
			doc.body.appendChild(h('form.wpcap-checkout-form', [
				h('input.wpcap-qty', { type: 'number', value: String(opts.q11 ?? 1), 'data-type': '11', 'data-name': 'Standard' }),
				h('input.wpcap-qty', { type: 'number', value: String(opts.q12 ?? 0), 'data-type': '12', 'data-name': 'Premium' }),
				h('div.wpcap-seating', { 'data-url': '/seats', 'data-i18n': JSON.stringify(T) }, [h('p.wpcap-seating-status', { role: 'status' })]),
			]));
		},
	});
	env.fetch = fetch;
	env.served = served;
	env.box = env.document.querySelector('.wpcap-seating');
	env.status = () => env.document.querySelector('.wpcap-seating-status').textContent;
	return env;
}
/** The Picker instance is internal; drive it through the DOM and the payload event. */
function payload(env) {
	const detail = { form: env.document.querySelector('form'), items: [], payload: {}, error: '', field: null };
	env.document.dispatchEvent(new CustomEvent('wpcap:checkout-payload', { detail }));
	return detail;
}

test('format fills sequential and positional placeholders', () => {
	const { exports: p } = picker();
	assert.equal(p.format('%s ausgewählt', 'R1'), 'R1 ausgewählt');
	assert.equal(p.format('%1$s: %2$d von %3$d', 'A', 1, 2), 'A: 1 von 2');
});

test('renders an accessible seat map with a checkbox list fallback', async () => {
	const env = picker();
	await flush();
	assert.equal(env.status(), 'Hilfe');
	const seats = env.box.querySelectorAll('g.wpcap-seat');
	assert.equal(seats.length, 4);
	assert.equal(seats[0].getAttribute('role'), 'checkbox');
	assert.equal(seats[0].getAttribute('tabindex'), '0', 'roving tabindex on the first free seat');
	assert.equal(seats[1].getAttribute('aria-disabled'), 'true');
	assert.equal(seats[2].getAttribute('aria-label'), 'Reihe 1, Platz 3, Parkett, Kategorie 2, rollstuhlgerecht, frei');
	const checks = env.box.querySelectorAll('.wpcap-seating-list input[type="checkbox"]');
	assert.equal(checks.length, 4);
	assert.equal(checks[1].disabled, true);
	assert.deepEqual(env.box.querySelectorAll('.wpcap-seating-list legend').map((l) => l.textContent), ['Parkett – Reihe 1', 'Parkett – Reihe 2']);
});

test('selecting seats assigns eligible ticket types and announces it; payload checks counts', async () => {
	const env = picker({ q11: 1, q12: 1 });
	await flush();
	const seats = env.box.querySelectorAll('g.wpcap-seat');
	assert.match(payload(env).error, /Standard: 0 von 1/);
	seats[0].click(); // kat1 -> type 11 (open quantity first)
	assert.equal(env.status(), 'Reihe 1, Platz 1 ausgewählt');
	assert.equal(seats[0].getAttribute('aria-checked'), 'true');
	seats[2].click(); // kat2 -> only type 12 is eligible
	let d = payload(env);
	assert.equal(d.error, '');
	assert.deepEqual(d.payload.seats, { 11: [1], 12: [3] });
	seats[1].click(); // taken: ignored
	assert.equal(seats[1].getAttribute('aria-checked'), 'false');
	seats[0].click(); // deselect
	assert.equal(env.status(), 'Reihe 1, Platz 1 entfernt');
	d = payload(env);
	assert.match(d.error, /Standard: 0 von 1/);
});

test('seat without an eligible ticket type is refused', async () => {
	const env = picker({ data: (d) => Object.assign(d, { types: { 11: ['kat1'] } }) });
	await flush();
	env.box.querySelectorAll('g.wpcap-seat')[2].click();
	assert.equal(env.status(), 'Keine passende Ticketart');
});

test('keyboard: arrows move the roving focus spatially, space toggles', async () => {
	const env = picker();
	await flush();
	const svg = env.box.querySelector('svg');
	const seats = env.box.querySelectorAll('g.wpcap-seat');
	const key = (k) => { const e = new Event('keydown'); e.key = k; svg.dispatchEvent(e); return e; };
	assert.ok(key('ArrowRight').defaultPrevented);
	assert.equal(env.document.activeElement, seats[1]);
	key('ArrowDown');
	assert.equal(env.document.activeElement, seats[3], 'nearest seat below');
	key(' ');
	assert.equal(seats[3].getAttribute('aria-checked'), 'true');
	key('Home');
	assert.equal(env.document.activeElement, seats[0]);
	key('End');
	assert.equal(env.document.activeElement, seats[3]);
});

test('standing areas: number input picks free spots up to availability', async () => {
	const env = picker({ q11: 0, q12: 2 });
	await flush();
	const input = env.box.querySelector('.wpcap-seating-standing input');
	assert.equal(input.max, '2', 'one of three spots is taken');
	input.value = '2';
	input.dispatchEvent(new Event('change'));
	assert.deepEqual(payload(env).payload.seats, { 12: [10, 11] });
	input.value = '1';
	input.dispatchEvent(new Event('change'));
	assert.equal(input.value, '1');
});

test('reload drops seats taken meanwhile and says so', async () => {
	const env = picker();
	await flush();
	const seat = env.box.querySelectorAll('g.wpcap-seat')[0];
	seat.click();
	env.served.data.seats[0].status = 'taken';
	const poll = [...env.timers.pending.values()].find((t) => t.repeat);
	assert.equal(poll.ms, 30000);
	poll.fn();
	await flush();
	assert.equal(env.status(), 'Ein Platz wurde inzwischen vergeben');
	assert.equal(seat.getAttribute('aria-checked'), 'false');
	assert.equal(seat.getAttribute('aria-disabled'), 'true');
});

test('changing a quantity to 0 moves selected seats to another eligible type', async () => {
	const env = picker({ q11: 1, q12: 1 });
	await flush();
	const seats = env.box.querySelectorAll('g.wpcap-seat');
	seats[0].click();
	seats[3].click(); // type 11 is used up, so the second kat1 seat goes to 12
	assert.deepEqual(payload(env).payload.seats, { 11: [1], 12: [4] });
	const q11 = env.document.querySelector('.wpcap-qty[data-type="11"]');
	const q12 = env.document.querySelector('.wpcap-qty[data-type="12"]');
	q12.value = '2';
	q11.value = '0';
	q11.dispatchEvent(new Event('change', { bubbles: true }));
	const d = payload(env);
	assert.equal(d.error, '');
	assert.deepEqual(d.payload.seats, { 12: [4, 1] });
	const selects = env.box.querySelectorAll('.wpcap-seating-selection select');
	assert.equal(selects.length, 2);
	assert.equal(selects[1].getAttribute('aria-label'), 'Ticketart: Reihe 1, Platz 1');
	assert.equal(selects[1].value, '12');
});
