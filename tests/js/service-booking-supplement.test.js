/**
 * PASS-FE-17 follow-up: the manage view of the service booking page (assets/service-booking.js)
 * also offers dearer slots with a supplementary payment. Pure helpers in Node: rebooking choices
 * from rebook_options + supplement_options (GET /service-bookings/{id}), their option labels and
 * how a started supplementary payment continues.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
function load(i18n) {
	const window = { WPCAP_SERVICE_BOOKING: { restUrl: 'https://example.test/wp-json/pass-captain/v1/', money: { decimal: ',', thousands: '.', symbols: { EUR: '€' }, decimals: { EUR: 2 } }, i18n: i18n || {} } };
	window.window = window;
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/service-booking.js'), 'utf8'), vm.createContext(window));
	return window.wpcapServiceBooking;
}
const sb = load({ supplementChoice: 'Zuzahlung %s' });
const json = (value) => JSON.stringify(value);

test('same-price and dearer slots are merged by start time; dearer ones carry the supplement', () => {
	const view = {
		currency: 'EUR',
		rebook_options: [
			{ slot_id: 7, resource_name: 'Boot 1', starts_at: '2026-12-02 09:00:00', ends_at: '2026-12-02 10:00:00', available: 2 },
			{ slot_id: 3, resource_name: 'Boot 1', starts_at: '2026-12-01 09:00:00', ends_at: '2026-12-01 10:00:00', available: 1 },
		],
		supplement_options: [
			{ slot_id: 9, resource_name: 'Premium', starts_at: '2026-12-01 12:00:00', available: 2, supplement_minor: 4000, currency: 'EUR' },
			{ slot_id: 7, resource_name: 'Doppelt', starts_at: '2026-12-02 09:00:00', supplement_minor: 1000 }, // already a same-price target
			{ slot_id: 11, resource_name: 'Ohne', starts_at: '2026-12-01 08:00:00', supplement_minor: 0 }, // not dearer: ignored
		],
	};
	const choices = sb.rebookChoices(view);
	assert.strictEqual(json(choices.map((c) => [c.slot_id, c.supplement_minor])), json([[3, 0], [9, 4000], [7, 0]]));
	assert.strictEqual(choices[1].currency, 'EUR');
	assert.strictEqual(choices[0].currency, 'EUR', 'currency of the booking for same-price choices');
});

test('older responses without the additive fields keep working', () => {
	const choices = sb.rebookChoices({ rebook_options: [{ slot_id: 4, resource_name: 'Boot', starts_at: '2026-12-01 09:00:00' }] });
	assert.strictEqual(json(choices.map((c) => [c.slot_id, c.supplement_minor])), json([[4, 0]]));
	assert.strictEqual(sb.rebookChoices({}).length, 0);
	assert.strictEqual(sb.rebookChoices(null).length, 0);
});

test('option label names the supplement in the booking currency', () => {
	const dear = { slot_id: 9, resource_name: 'Premium', starts_at: '2026-12-01 12:00:00', supplement_minor: 4000, currency: 'EUR' };
	const same = Object.assign({}, dear, { supplement_minor: 0 });
	assert.match(sb.choiceLabel(dear, 'de-DE', 'Europe/Vienna'), /^Premium · .*13:00 \(Zuzahlung 40,00 €\)$/);
	assert.match(sb.choiceLabel(same, 'de-DE', 'Europe/Vienna'), /^Premium · .*13:00$/);
});

test('a started supplementary payment continues on the hosted page, with transfer details or instructions', () => {
	assert.strictEqual(sb.paymentKind({ redirect_url: 'https://pay.example.test/s/abc' }), 'redirect');
	assert.strictEqual(sb.paymentKind({ redirect_url: 'javascript:alert(1)' }), '', 'only http(s) links are followed');
	assert.strictEqual(sb.paymentKind({ bank_transfer: { iban: 'AT00' }, instructions: 'x' }), 'bank');
	assert.strictEqual(sb.paymentKind({ instructions: 'Bitte überweisen' }), 'instructions');
	assert.strictEqual(sb.paymentKind({}), '');
	assert.strictEqual(sb.paymentKind(null), '');
});

test('the supplement strings are in the PHP catalogue', () => {
	const php = fs.readFileSync(path.join(root, 'src/Frontend/ServiceBooking.php'), 'utf8');
	for (const key of ['supplementChoice', 'supplementHint', 'supplementProvider', 'supplementOpen', 'supplementPay', 'supplementStarted']) {
		assert.match(php, new RegExp("'" + key + "'\\s*=>\\s*__\\("), key);
	}
});
