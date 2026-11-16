'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event } = require('./lib/load');

/** Box office form like BoxOffice\Page: rows per ticket type, payment method radios, tendered amount. */
function pos(opts) {
	opts = opts || {};
	return load('assets/boxoffice/pos.js', {
		window: { wpcapPos: { changeLabel: 'Rückgeld:', availabilityUrl: '/avail', heading: 'Teilnehmer', attendee: 'Ticket %1$d · %2$s' } },
		fetch: opts.fetch,
		setup(doc, h) {
			if (opts.empty) return;
			const row = (type, price, name, fields) => h('tr', { 'data-type': type, 'data-price': price, 'data-name': name, 'data-fields': JSON.stringify(fields || []) }, [
				h('td', name), h('td.wpcap-pos-avail', '∞'), h('td', [h('input.wpcap-pos-qty', { type: 'number', min: '0', value: '0' })]),
			]);
			doc.body.appendChild(h('form#wpcap-pos-form', [
				h('table', [h('tbody', [row('1', '2500', 'Standard'), row('2', '1250', 'Kind', [{ key: 'age', label: 'Alter', type: 'text', required: true }])].concat(opts.consent ? [row('3', '900', 'Foto', [{ key: 'photo', label: 'Fotoeinwilligung', type: 'consent', required: true, consent_text: 'Ich willige in Fotos ein.', consent_version: '2' }])] : []))]),
				h('label', [h('input', { type: 'radio', name: 'method', value: 'cash', 'data-default-checked': '' }), 'Bar']),
				h('label', [h('input', { type: 'radio', name: 'method', value: 'card' }), 'Karte']),
				h('label', [h('input', { type: 'radio', name: 'method', value: 'free' }), 'Frei']),
				h('input', { type: 'text', name: 'tendered' }),
				h('strong#wpcap-pos-total'), h('span#wpcap-pos-change'), h('div#wpcap-pos-attendees'),
				h('button', { type: 'submit' }, 'Verkaufen'),
			]));
			doc.querySelector('input[value="cash"]').checked = true;
		},
	});
}
function qty(env, i, n) {
	const input = env.document.querySelectorAll('.wpcap-pos-qty')[i];
	input.value = String(n);
	input.dispatchEvent(new Event('input', { bubbles: true }));
}
function tender(env, text) {
	const input = env.document.querySelector('[name="tendered"]');
	input.value = text;
	input.dispatchEvent(new Event('input', { bubbles: true }));
}

test('money/parseMoney: euro formatting and tolerant parsing', () => {
	const { exports: p } = pos({ empty: true });
	assert.equal(p.money(0), '0,00');
	assert.equal(p.money(1205), '12,05');
	assert.equal(p.money(-50), '-0,50');
	assert.equal(p.parseMoney(''), null);
	assert.equal(p.parseMoney('abc'), null);
	assert.equal(p.parseMoney('50'), 5000);
	assert.equal(p.parseMoney('50,5'), 5050);
	assert.equal(p.parseMoney('50.55'), 5055);
	assert.equal(p.parseMoney('€ 1.234,5'), 123450, 'last separator is the decimal separator');
	assert.equal(p.parseMoney('12,345'), 1234, 'extra decimals are cut, not rounded');
});

test('money/parseMoney follow the currency minor unit and number format (A403)', () => {
	const jpy = load('assets/boxoffice/pos.js', { window: { wpcapPos: { decimals: 0, decimalPoint: ',', thousandsSep: '.' } } }).exports;
	assert.equal(jpy.money(123456), '123.456');
	assert.equal(jpy.parseMoney('1.500'), 1500, 'no minor unit: separators are thousands');
	const kwd = load('assets/boxoffice/pos.js', { window: { wpcapPos: { decimals: 3, decimalPoint: '.', thousandsSep: ',' } } }).exports;
	assert.equal(kwd.money(1234567), '1,234.567');
	assert.equal(kwd.parseMoney('12.5'), 12500);
});

test('no form on the page: script stays inert', () => {
	const env = pos({ empty: true });
	assert.equal(env.timers.pending.size, 0);
});

test('running total and change for cash payments', () => {
	const env = pos();
	const total = env.document.getElementById('wpcap-pos-total');
	const change = env.document.getElementById('wpcap-pos-change');
	assert.equal(total.textContent, '0,00');
	qty(env, 0, 2);
	qty(env, 1, 1);
	assert.equal(total.textContent, '62,50');
	tender(env, '70');
	assert.equal(change.textContent, 'Rückgeld: 7,50');
	tender(env, '60');
	assert.equal(change.textContent, 'Rückgeld: -2,50', 'too little cash shows a negative change');
	env.document.querySelector('input[value="cash"]').checked = false;
	env.document.querySelector('input[value="card"]').checked = true;
	env.document.querySelector('[name="tendered"]').dispatchEvent(new Event('change', { bubbles: true }));
	assert.equal(change.textContent, '', 'no change for card payments');
	env.document.querySelector('input[value="card"]').checked = false;
	env.document.querySelector('input[value="free"]').checked = true;
	env.document.querySelector('[name="tendered"]').dispatchEvent(new Event('change', { bubbles: true }));
	assert.equal(total.textContent, '0,00', 'free admission');
});

test('participant fieldsets follow the quantities and keep entered values', () => {
	const env = pos();
	qty(env, 1, 2);
	let sets = env.document.querySelectorAll('.wpcap-pos-attendee');
	assert.equal(sets.length, 2);
	assert.equal(env.document.querySelector('#wpcap-pos-attendees h3').textContent, 'Teilnehmer');
	const age = sets[0].querySelector('[name="attendees[2][0][answers][age]"]');
	assert.ok(age && age.required);
	assert.equal(sets[0].querySelector('label[for="' + age.id + '"]').textContent, 'Alter *');
	sets[0].querySelector('[name="attendees[2][0][name]"]').value = 'Kim';
	qty(env, 0, 1);
	sets = env.document.querySelectorAll('.wpcap-pos-attendee');
	assert.equal(sets.length, 3);
	assert.equal(sets[0].querySelector('legend').textContent, 'Ticket 1 · Standard');
	assert.equal(sets[1].querySelector('legend').textContent, 'Ticket 2 · Kind', 'legend renumbered');
	assert.equal(sets[1].querySelector('[name="attendees[2][0][name]"]').value, 'Kim');
});

test('double submit is blocked client-side', () => {
	const env = pos();
	const form = env.document.getElementById('wpcap-pos-form');
	const first = new Event('submit', { bubbles: true });
	form.dispatchEvent(first);
	const second = new Event('submit', { bubbles: true });
	form.dispatchEvent(second);
	assert.equal(first.defaultPrevented, false);
	assert.equal(second.defaultPrevented, true);
});

test('availability refresh updates the max quantity', async () => {
	const fetch = mockFetch(() => ({ json: { success: true, data: { 1: 3, 2: null } } }));
	const env = pos({ fetch });
	const interval = [...env.timers.pending.values()].find((t) => t.repeat);
	assert.equal(interval.ms, 15000);
	interval.fn();
	await flush();
	const rows = env.document.querySelectorAll('tr[data-type]');
	assert.equal(rows[0].querySelector('.wpcap-pos-avail').textContent, '3');
	assert.equal(rows[0].querySelector('.wpcap-pos-qty').max, '3');
	assert.equal(rows[1].querySelector('.wpcap-pos-avail').textContent, '∞');
});

test('consent fields render as a checkbox with the consent text and version', () => {
	const env = pos({ consent: true });
	qty(env, 2, 1);
	const set = env.document.querySelector('.wpcap-pos-attendee');
	const box = set.querySelector('[name="attendees[3][0][answers][photo]"]');
	assert.ok(box, 'consent input rendered');
	assert.equal(box.type, 'checkbox');
	assert.equal(box.value, '1');
	assert.ok(box.required);
	assert.equal(set.querySelector('label[for="' + box.id + '"]').textContent, 'Fotoeinwilligung *');
	const text = set.querySelector('#' + box.getAttribute('aria-describedby'));
	assert.equal(text.textContent, 'Ich willige in Fotos ein. (Version 2)');
});

/** Terminal status box like BoxOffice\Page::render_device_status (pending card-terminal payment). */
function device(opts) {
	opts = opts || {};
	return load('assets/boxoffice/pos.js', {
		window: { wpcapPosDevice: { saleId: 42, url: '/wp-admin/admin-ajax.php', nonce: 'n0nce', interval: 2000, cancelling: 'Abbruch …', offline: 'Offline …', booked: 'Gebucht …', again: 'Neu erfassen.' } },
		fetch: opts.fetch,
		url: 'https://example.test/wp-admin/admin.php?page=wpcap-boxoffice&device_sale=42',
		setup(doc, h) {
			doc.body.appendChild(h('div#wpcap-pos-device.notice.notice-info', { 'data-status': 'pending' }, [
				h('p#wpcap-pos-device-status', { role: 'status', 'aria-live': 'polite', tabindex: '-1' }, 'Am Terminal bezahlen … 12,00 €'),
				h('p#wpcap-pos-device-hint', 'Bitte Karte vorlegen.'),
				h('form#wpcap-pos-device-cancel-form', [h('button#wpcap-pos-device-cancel', { type: 'submit' }, 'Zahlung am Terminal abbrechen')]),
			]));
		},
	});
}
function oneShot(env) { return [...env.timers.pending.values()].filter((t) => !t.repeat); }

test('terminal payment: status region gets focus, polls admin-ajax with nonce until booked, then opens the receipt view', async () => {
	let n = 0;
	const fetch = mockFetch(() => (++n < 3 ? { json: { success: true, data: { status: 'pending', message: '' } } } : { json: { success: true, data: { status: 'succeeded', redirect: 'https://example.test/wp-admin/admin.php?page=wpcap-boxoffice&sale=42' } } }));
	const env = device({ fetch });
	const status = env.document.getElementById('wpcap-pos-device-status');
	assert.equal(env.document.activeElement, status, 'live region focused for screen readers');
	assert.equal(status.getAttribute('aria-live'), 'polite');
	assert.equal(oneShot(env).length, 1);
	assert.equal(oneShot(env)[0].ms, 2000);
	assert.equal(fetch.calls.length, 0, 'first poll after the interval');
	for (let i = 0; i < 3; i++) { env.timers.run(); await flush(); }
	assert.equal(fetch.calls.length, 3);
	assert.equal(fetch.calls[0].url, '/wp-admin/admin-ajax.php');
	assert.equal(fetch.calls[0].init.method, 'POST');
	assert.deepEqual(fetch.calls[0].body, { action: 'wpcap_pos_device_status', sale_id: '42', _ajax_nonce: 'n0nce' });
	assert.equal(status.textContent, 'Gebucht …');
	assert.equal(env.window.location.href, 'https://example.test/wp-admin/admin.php?page=wpcap-boxoffice&sale=42');
	assert.equal(oneShot(env).length, 0, 'polling stops');
});

test('terminal payment: failure stops polling, hides cancel and announces the reason', async () => {
	const fetch = mockFetch(() => ({ json: { success: true, data: { status: 'failed', message: 'Karte abgelehnt.' } } }));
	const env = device({ fetch });
	env.timers.run();
	await flush();
	const box = env.document.getElementById('wpcap-pos-device');
	assert.equal(env.document.getElementById('wpcap-pos-device-status').textContent, 'Karte abgelehnt.');
	assert.equal(box.getAttribute('data-status'), 'failed');
	assert.ok(box.classList.contains('notice-error'));
	assert.equal(env.document.getElementById('wpcap-pos-device-cancel-form').hidden, true);
	assert.equal(env.document.getElementById('wpcap-pos-device-hint').textContent, 'Neu erfassen.');
	assert.equal(oneShot(env).length, 0);
});

test('terminal payment: cancel button posts the cancel action and shows the cancelled state', async () => {
	const fetch = mockFetch((url, init, body) => (body.action === 'wpcap_pos_device_cancel' ? { json: { success: true, data: { status: 'cancelled', message: 'Abgebrochen.' } } } : { json: { success: true, data: { status: 'pending' } } }));
	const env = device({ fetch });
	const form = env.document.getElementById('wpcap-pos-device-cancel-form');
	const submit = new Event('submit', { bubbles: true });
	form.dispatchEvent(submit);
	assert.equal(submit.defaultPrevented, true, 'no admin-post navigation with JavaScript');
	assert.equal(env.document.getElementById('wpcap-pos-device-status').textContent, 'Abbruch …');
	assert.equal(env.document.getElementById('wpcap-pos-device-cancel').disabled, true);
	assert.equal(oneShot(env).length, 0, 'no poll while cancelling');
	await flush();
	assert.equal(fetch.calls.length, 1);
	assert.equal(fetch.calls[0].body.action, 'wpcap_pos_device_cancel');
	assert.equal(env.document.getElementById('wpcap-pos-device-status').textContent, 'Abgebrochen.');
	assert.equal(env.document.getElementById('wpcap-pos-device').getAttribute('data-status'), 'cancelled');
	assert.equal(env.document.activeElement, env.document.getElementById('wpcap-pos-device-status'));
});

test('terminal payment: refused cancel and network errors keep polling with a message', async () => {
	let fail = true;
	const fetch = mockFetch((url, init, body) => {
		if (body.action === 'wpcap_pos_device_cancel') return { status: 409, json: { success: false, data: { message: 'Karte bereits vorgelegt.' } } };
		if (fail) { fail = false; throw new Error('network'); }
		return { json: { success: true, data: { status: 'pending' } } };
	});
	const env = device({ fetch });
	env.timers.run();
	await flush();
	assert.equal(env.document.getElementById('wpcap-pos-device-status').textContent, 'Offline …');
	assert.equal(oneShot(env).length, 1, 'retries after a network error');
	env.document.getElementById('wpcap-pos-device-cancel-form').dispatchEvent(new Event('submit', { bubbles: true }));
	await flush();
	assert.equal(env.document.getElementById('wpcap-pos-device-status').textContent, 'Karte bereits vorgelegt.');
	assert.equal(env.document.getElementById('wpcap-pos-device-cancel').disabled, false);
	assert.equal(oneShot(env).length, 1, 'keeps polling');
});

test('no terminal payment on the page (default): no polling, the sale form behaves as before', () => {
	const fetch = mockFetch(() => ({ json: {} }));
	const env = pos({ fetch });
	assert.equal(oneShot(env).length, 0);
	assert.equal(env.document.querySelectorAll('input[name="method"]').length, 3);
	assert.equal(fetch.calls.length, 0);
});
