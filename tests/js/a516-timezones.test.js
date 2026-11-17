/**
 * A516: both scanners show times of an occurrence (start, earlier admissions, scans) in the
 * occurrence's time zone instead of the device's, with the zone abbreviation when it differs.
 * Runs with TZ=UTC semantics independent of the machine: the expected strings are computed with Intl.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');

function proxyElement() {
	return new Proxy(function () {}, {
		get: (target, prop) => (prop === 'classList' ? { add() {}, remove() {}, toggle() {} } : prop === 'style' || prop === 'dataset' ? {} : proxyElement()),
		set: () => true,
		apply: () => proxyElement(),
	});
}

function loadOffline() {
	const window = {
		document: { getElementById: () => proxyElement(), querySelector: () => proxyElement(), querySelectorAll: () => [], createElement: () => proxyElement(), addEventListener() {}, documentElement: {}, title: '' },
		navigator: { onLine: true },
		location: { search: '', pathname: '/', origin: 'https://example.test', href: 'https://example.test/' },
		crypto: require('node:crypto').webcrypto,
		TextEncoder, URL, URLSearchParams, Date, Map, Set, JSON, Promise, Uint8Array, Array, String, Math, Error, Object, RegExp, isNaN, Intl, Number,
		atob: (s) => Buffer.from(s, 'base64').toString('binary'),
		setInterval() {}, setTimeout() {}, clearTimeout() {}, console,
	};
	window.window = window;
	window.self = window;
	window.globalThis = window;
	const ctx = vm.createContext(window);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/vendor/tweetnacl/nacl-fast.min.js'), 'utf8'), ctx);
	vm.runInContext(fs.readFileSync(path.join(root, 'assets/offline-scanner/app.js'), 'utf8'), ctx);
	return window.wpcapOffline;
}

const iso = '2026-11-13T19:42:00Z';
const zone = 'America/New_York';
const there = new Date(iso).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short', timeZone: zone });
const differs = new Intl.DateTimeFormat('en-US', { timeZoneName: 'longOffset' }).format(new Date(iso)).split(' ').pop() !== new Intl.DateTimeFormat('en-US', { timeZoneName: 'longOffset', timeZone: zone }).format(new Date(iso)).split(' ').pop();

test('A516: offline app formats in the occurrence time zone, with the zone when it differs from the device', () => {
	const app = loadOffline();
	const text = app.fmtTime(iso, zone);
	assert.ok(text.startsWith(there), text + ' starts with ' + there);
	assert.strictEqual(text.length > there.length, differs, text);
	assert.ok(text.indexOf('14:42') !== -1, text);
});

test('A516: offline app falls back to device time without or with an invalid zone', () => {
	const app = loadOffline();
	const device = new Date(iso).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
	assert.strictEqual(app.fmtTime(iso), device);
	assert.strictEqual(app.fmtTime(iso, 'Not/AZone'), device);
	assert.strictEqual(app.fmtTime('nonsense', zone), '—');
});

test('A516: web scanner shows the first admission of a refused ticket in the occurrence time zone', async () => {
	const source = fs.readFileSync(path.join(root, 'assets/scanner.js'), 'utf8');
	assert.ok(source.indexOf('when(data.first_checked_in_at, data.timezone)') !== -1);
	assert.ok(source.indexOf('when(data.last_checked_in_at, data.timezone)') !== -1);
	assert.ok(/function when\(iso, zone\)/.test(source));
});
