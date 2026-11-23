'use strict';
/**
 * A090: seat map on mobile – seat size, zoom/pan (pinch, Ctrl+wheel, mouse drag), legend with shapes and
 * state marks, list as a full alternative view (assets/seating/picker.js).
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event } = require('./lib/load');

const T = { free: 'frei', taken: 'belegt', selected: 'ausgewählt', accessible: 'rollstuhlgerecht', added: '%s ausgewählt', removed: '%s entfernt', noType: 'Keine passende Ticketart', countMismatch: '%1$s: %2$d von %3$d Plätzen gewählt', seatTaken: 'vergeben', help: 'Hilfe', none: '—', ticketType: 'Ticketart' };

function plan() {
	return {
		width: 300, height: 200,
		categories: [{ key: 'kat1', name: 'Kategorie 1', color: '#123456' }, { key: 'kat2', name: 'Kategorie 2', color: '#654321' }],
		zones: [{ key: 'parkett', name: 'Parkett' }],
		types: { 11: ['kat1'], 12: ['kat1', 'kat2'] },
		areas: [],
		seats: [
			{ id: 1, kind: 'seat', label: 'Reihe 1, Platz 1', row: 'Reihe 1', seat: '1', zone: 'parkett', category: 'kat1', status: 'free', x: 20, y: 40 },
			{ id: 2, kind: 'seat', label: 'Reihe 1, Platz 2', row: 'Reihe 1', seat: '2', zone: 'parkett', category: 'kat1', status: 'taken', x: 50, y: 40 },
			{ id: 3, kind: 'seat', label: 'Reihe 1, Platz 3', row: 'Reihe 1', seat: '3', zone: 'parkett', category: 'kat2', status: 'free', accessible: true, x: 80, y: 40 },
			{ id: 4, kind: 'seat', label: 'Reihe 2, Platz 1', row: 'Reihe 2', seat: '1', zone: 'parkett', category: 'kat1', status: 'free', x: 20, y: 70 },
		],
	};
}

function picker(opts) {
	opts = opts || {};
	const data = opts.data || plan();
	const env = load('assets/seating/picker.js', {
		fetch: mockFetch(() => ({ json: JSON.parse(JSON.stringify(data)) })),
		window: opts.window || {},
		setup(doc, h) {
			doc.body.appendChild(h('form.wpcap-checkout-form', [
				h('input.wpcap-qty', { type: 'number', value: '1', 'data-type': '11', 'data-name': 'Standard' }),
				h('div.wpcap-seating', { 'data-url': '/seats', 'data-i18n': JSON.stringify(Object.assign({}, T, opts.i18n || {})) }, [h('p.wpcap-seating-status', { role: 'status' })]),
			]));
		},
	});
	env.box = env.document.querySelector('.wpcap-seating');
	return env;
}

function payload(env) {
	const detail = { form: env.document.querySelector('form'), items: [], payload: {}, error: '', field: null };
	const { CustomEvent } = require('./lib/load');
	env.document.dispatchEvent(new CustomEvent('wpcap:checkout-payload', { detail }));
	return detail;
}

function pointer(type, id, x, extra) {
	const e = new Event(type);
	Object.assign(e, { pointerId: id, clientX: x, clientY: 0, pointerType: 'touch', button: 0 }, extra || {});
	return e;
}

test('A090: categories have their own seat shape, the legend shows shapes and state marks', async () => {
	const env = picker();
	await flush();
	const legend = env.box.querySelector('.wpcap-seating-legend');
	assert.deepEqual(legend.querySelectorAll('.wpcap-seating-swatch').map((sw) => sw.getAttribute('data-shape')), ['circle', 'square']);
	assert.deepEqual(legend.querySelectorAll('.wpcap-seating-mark').map((m) => m.textContent), ['✓', '×', '♿'], 'the companion mark only with its text');
	const seats = env.box.querySelectorAll('g.wpcap-seat');
	assert.equal(seats[0].getAttribute('data-shape'), 'circle');
	assert.equal(seats[2].getAttribute('data-shape'), 'square');
	assert.equal(seats[2].querySelector('.wpcap-seat-shape').tagName.toLowerCase(), 'rect');
	const mark = (i) => seats[i].querySelector('.wpcap-seat-icon').textContent;
	assert.equal(mark(1), '×', 'taken seats carry a cross, not only grey');
	assert.equal(mark(2), '♿');
	seats[0].click();
	assert.equal(mark(0), '✓', 'selected seats carry a check mark');
	seats[0].click();
	assert.equal(mark(0), '');
});

test('A090: map and list are two views of the same seats', async () => {
	const env = picker();
	await flush();
	const [mapBtn, listBtn] = env.box.querySelectorAll('.wpcap-seating-view');
	const map = env.box.querySelector('.wpcap-seating-map');
	const list = env.box.querySelector('.wpcap-seating-list');
	assert.equal(map.hidden, false);
	assert.equal(list.hidden, true);
	assert.equal(mapBtn.getAttribute('aria-pressed'), 'true');
	listBtn.click();
	assert.equal(map.hidden, true);
	assert.equal(list.hidden, false);
	assert.equal(listBtn.getAttribute('aria-pressed'), 'true');
	assert.equal(env.box.querySelector('.wpcap-seating-zoom').hidden, true, 'zoom tools only with the map');
	assert.equal(env.document.activeElement, list.querySelector('input'), 'focus moves to the first free seat of the list');
	const first = list.querySelectorAll('input')[0];
	first.checked = true;
	first.dispatchEvent(new Event('change'));
	assert.deepEqual(payload(env).payload.seats, { 11: [1] });
	mapBtn.click();
	assert.equal(map.hidden, false);
	assert.equal(env.box.querySelectorAll('g.wpcap-seat')[0].getAttribute('aria-checked'), 'true');
});

test('A090: narrow screens with a large map start with the list', async () => {
	const data = plan();
	for (let i = 0; i < 45; i++) data.seats.push({ id: 100 + i, kind: 'seat', label: 'R9 P' + i, row: 'R9', seat: String(i), zone: 'parkett', category: 'kat1', status: 'free', x: 10 + i * 6, y: 120 });
	const env = picker({ data, window: { matchMedia: (q) => ({ matches: q === '(max-width: 600px)' }) }, i18n: { listHint: 'Liste zuerst' } });
	await flush();
	assert.equal(env.box.querySelector('.wpcap-seating-list').hidden, false);
	assert.equal(env.box.querySelector('.wpcap-seating-map').hidden, true);
	assert.equal(env.box.querySelector('.wpcap-seating-hint').textContent, 'Liste zuerst');
});

test('A090: large seats (44 px) – switchable, default on touch screens', async () => {
	const env = picker();
	await flush();
	const svg = env.box.querySelector('.wpcap-seating-map svg');
	const size = env.box.querySelector('.wpcap-seating-size');
	assert.equal(svg.style.minWidth, '360px');
	assert.equal(size.getAttribute('aria-pressed'), 'false');
	size.click();
	assert.equal(svg.style.minWidth, '660px', '44 px seats (20 units wide): 44/20 × 300');
	assert.equal(size.getAttribute('aria-pressed'), 'true');
	size.click();
	assert.equal(svg.style.minWidth, '360px');
	assert.equal(env.exports.minMapWidth(300, [{ x: 0, y: 0 }, { x: 30, y: 0 }], 44), 660);
	const touch = picker({ window: { matchMedia: (q) => ({ matches: q === '(pointer: coarse)' }) } });
	await flush();
	assert.equal(touch.box.querySelector('.wpcap-seating-map svg').style.minWidth, '660px');
	assert.equal(touch.box.querySelector('.wpcap-seating-size').getAttribute('aria-pressed'), 'true');
});

test('A090: pinch and Ctrl+wheel zoom, a gesture picks no seat, "Ganzer Saal" resets, mouse drag pans', async () => {
	const env = picker();
	await flush();
	const wrap = env.box.querySelector('.wpcap-seating-map');
	const svg = wrap.querySelector('svg');
	wrap.dispatchEvent(pointer('pointerdown', 1, 0));
	wrap.dispatchEvent(pointer('pointerdown', 2, 100));
	const move = pointer('pointermove', 2, 200);
	wrap.dispatchEvent(move);
	assert.equal(svg.style.width, '200%', 'fingers twice as far apart: zoom 2');
	assert.ok(move.defaultPrevented);
	wrap.dispatchEvent(pointer('pointermove', 2, 900));
	assert.equal(svg.style.width, '300%', 'zoom is capped');
	const seat = env.box.querySelectorAll('g.wpcap-seat')[0];
	seat.click();
	assert.equal(seat.getAttribute('aria-checked'), 'false', 'the end of a pinch selects nothing');
	wrap.dispatchEvent(pointer('pointerup', 2, 900));
	wrap.dispatchEvent(pointer('pointerup', 1, 0));
	env.box.querySelector('.wpcap-seating-fit').click();
	assert.equal(svg.style.width, '100%');
	const wheel = new Event('wheel');
	Object.assign(wheel, { ctrlKey: true, deltaY: -1, clientX: 0, clientY: 0 });
	wrap.dispatchEvent(wheel);
	assert.equal(svg.style.width, '125%');
	assert.ok(wheel.defaultPrevented);
	wrap.dispatchEvent(pointer('pointerdown', 3, 10));
	wrap.dispatchEvent(pointer('pointerup', 3, 10));
	seat.click();
	assert.equal(seat.getAttribute('aria-checked'), 'true', 'a plain tap selects again');
	wrap.scrollLeft = 50;
	wrap.dispatchEvent(pointer('pointerdown', 4, 100, { pointerType: 'mouse' }));
	wrap.dispatchEvent(pointer('pointermove', 4, 60, { pointerType: 'mouse' }));
	assert.equal(wrap.scrollLeft, 90, 'dragged 40 px to the left: the map scrolls 40 px');
});
