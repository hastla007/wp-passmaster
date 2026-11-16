/**
 * A268: logic of the service slot booking page (assets/service-booking.js) in Node: weeks in the
 * event timezone (DST), grouping by local day, REST URLs for pretty and plain permalinks, the manage
 * link (token only in the fragment), quantity bounds, deadlines and the localized strings.
 * The script is loaded into a sandbox without a DOM; it then only exposes window.wpcapServiceBooking.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
function load(i18n) {
	const window = { WPCAP_SERVICE_BOOKING: { restUrl: 'https://example.test/wp-json/pass-captain/v1/', money: { decimal: ',', thousands: '.', symbols: { EUR: '€' }, decimals: { EUR: 2, JPY: 0 } }, i18n: i18n || {} } };
	window.window = window;
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/service-booking.js'), 'utf8'), vm.createContext(window));
	assert.ok(window.wpcapServiceBooking, 'script exposes window.wpcapServiceBooking');
	return window.wpcapServiceBooking;
}
const sb = load({ freeOne: '1 Platz frei', freeMany: '%d Plätze frei', durationHour: '1 Stunde', durationHours: '%d Stunden', durationDay: '1 Tag', durationDays: '%d Tage', weekRange: 'Woche vom %1$s bis %2$s' });

test('local date and week (Monday) in the event timezone', () => {
	// 23:30 UTC on Sunday 15 Nov 2026 is already Monday 16 Nov in Vienna (UTC+1).
	assert.strictEqual(sb.localDate('2026-11-15 23:30:00', 'Europe/Vienna'), '2026-11-16');
	assert.strictEqual(sb.weekOf('2026-11-15 23:30:00', 'Europe/Vienna'), '2026-11-16');
	assert.strictEqual(sb.weekOf('2026-11-15 23:30:00', 'UTC'), '2026-11-09');
	// Sunday belongs to the week starting the Monday before.
	assert.strictEqual(sb.mondayOf('2026-11-22'), '2026-11-16');
	assert.strictEqual(sb.mondayOf('2026-11-16'), '2026-11-16');
});

test('week arithmetic is calendar based (no DST shift)', () => {
	// The week containing the end of DST in Europe (25 Oct 2026) has 7 calendar days, not 7×24 h.
	assert.strictEqual(sb.addDays('2026-10-19', 7), '2026-10-26');
	assert.strictEqual(sb.addDays('2026-03-23', 7), '2026-03-30');
	assert.strictEqual(sb.addDays('2026-01-05', -7), '2025-12-29');
});

test('slots are grouped by local day, order kept', () => {
	const slots = [
		{ slot_id: 1, starts_at: '2026-11-16 08:00:00' },
		{ slot_id: 2, starts_at: '2026-11-16 22:30:00' }, // 23:30 local, still Monday
		{ slot_id: 3, starts_at: '2026-11-16 23:30:00' }, // 00:30 local, Tuesday
	];
	const days = sb.groupByDay(slots, 'Europe/Vienna');
	// Arrays from the sandbox realm: compare their JSON.
	assert.strictEqual(JSON.stringify(days.map((d) => [d.day, d.slots.map((s) => s.slot_id)])), JSON.stringify([['2026-11-16', [1, 2]], ['2026-11-17', [3]]]));
	assert.strictEqual(sb.groupByDay([], 'UTC').length, 0);
});

test('REST URLs work with pretty and plain permalinks', () => {
	assert.strictEqual(sb.apiUrl('https://x.test/wp-json/pass-captain/v1/', 'events/5/service-slots', { resource: 3, week: '2026-11-16' }), 'https://x.test/wp-json/pass-captain/v1/events/5/service-slots?resource=3&week=2026-11-16');
	assert.strictEqual(sb.apiUrl('https://x.test/?rest_route=/pass-captain/v1/', 'events/5/service-slots', { resource: 3 }), 'https://x.test/?rest_route=/pass-captain/v1/events/5/service-slots&resource=3');
	assert.strictEqual(sb.apiUrl('https://x.test/wp-json/pass-captain/v1/', '/service-bookings/9', { week: '', resource: null }), 'https://x.test/wp-json/pass-captain/v1/service-bookings/9');
});

test('manage link keeps the token in the fragment and round-trips', () => {
	const token = 'ab'.repeat(32);
	const url = sb.manageUrl('https://x.test/termine/?lang=de#old', 42, token);
	assert.strictEqual(url, 'https://x.test/termine/?lang=de#wpcap-booking=42-' + token);
	assert.strictEqual(url.split('#')[0].indexOf(token), -1, 'token never in path or query');
	assert.deepStrictEqual(Object.assign({}, sb.parseManageHash('#' + url.split('#')[1])), { id: 42, token: token });
	assert.strictEqual(sb.parseManageHash('#wpcap-booking=42-' + token.toUpperCase()).token, token);
	assert.strictEqual(sb.parseManageHash('#wpcap-booking=42-abc'), null);
	assert.strictEqual(sb.parseManageHash('#other'), null);
	assert.strictEqual(sb.parseManageHash(''), null);
});

test('quantity is bounded by free places, resource limit and 20', () => {
	assert.strictEqual(sb.maxQuantity(3, { maxQty: 10 }), 3);
	assert.strictEqual(sb.maxQuantity(8, { maxQty: 4 }), 4);
	assert.strictEqual(sb.maxQuantity(50, { maxQty: 100 }), 20);
	assert.strictEqual(sb.maxQuantity(0, {}), 1);
});

test('deadlines and their labels', () => {
	const start = '2026-11-20 10:00:00';
	const at = (s) => Date.parse(s.replace(' ', 'T') + 'Z');
	assert.strictEqual(sb.deadlinePassed(start, 24, at('2026-11-19 09:59:00')), false);
	assert.strictEqual(sb.deadlinePassed(start, 24, at('2026-11-19 10:00:00')), true);
	assert.strictEqual(sb.deadlinePassed(start, 0, at('2026-11-20 09:00:00')), false);
	assert.strictEqual(sb.durationLabel(24), '1 Tag');
	assert.strictEqual(sb.durationLabel(48), '2 Tage');
	assert.strictEqual(sb.durationLabel(1), '1 Stunde');
	assert.strictEqual(sb.durationLabel(36), '36 Stunden');
});

test('placeholders, plurals and money as in the checkout', () => {
	assert.strictEqual(sb.fill('%1$s–%2$s', ['10:00', '11:00']), '10:00–11:00');
	assert.strictEqual(sb.plural(1, '1 Platz frei', '%d Plätze frei'), '1 Platz frei');
	assert.strictEqual(sb.plural(3, '1 Platz frei', '%d Plätze frei'), '3 Plätze frei');
	assert.strictEqual(sb.money(123450, 'EUR'), '1.234,50 €');
	assert.strictEqual(sb.money(1500, 'JPY'), '1.500 JPY');
	assert.match(sb.weekLabel('2026-11-16', 'de-DE'), /^Woche vom 16\. November 2026 bis 22\. November 2026$/);
});

test('every string the script uses is in the PHP catalogue (ServiceBooking::i18n)', () => {
	const js = fs.readFileSync(path.join(root, 'assets/service-booking.js'), 'utf8');
	const php = fs.readFileSync(path.join(root, 'src/Frontend/ServiceBooking.php'), 'utf8');
	const catalogue = php.slice(php.indexOf('public static function i18n()'), php.indexOf('Weeks (Monday'));
	const used = new Set();
	for (const m of js.matchAll(/\bt\.([a-zA-Z]+)/g)) used.add(m[1]);
	for (const m of js.matchAll(/\[\s*'([a-zA-Z]+)'\s*,\s*bank\./g)) used.add(m[1]);
	const missing = [...used].filter((key) => !new RegExp("'" + key + "'\\s*=>").test(catalogue));
	assert.deepStrictEqual(missing, []);
});
