'use strict';
/*
 * Offline scanner PWA: pairing screen and translations, pairing, signed package download, a scan while
 * the browser is offline (decided locally, queued in IndexedDB) and the upload after reconnecting.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, fixture, helper, launch, context, withPage } = require('./lib');

// Same URL as the admin link (OfflineScanner\AdminPage::app_url()): the REST base is passed as ?api= (the test
// site uses plain permalinks, so it is the ?rest_route= form).
const API = BASE + '/index.php?rest_route=/wp-pass-captain/v1/app/';
const APP = BASE + '/wp-content/plugins/pass-captain/assets/offline-scanner/index.html?api=' + encodeURIComponent(API);
let browser, ctx, door;
test.before(async () => {
	fixture(); // base data (pages) of the test site
	door = helper('today', 2); // occurrence inside the admission window, two tickets
	browser = await launch();
	ctx = await context(browser, { serviceWorkers: 'block' });
});
test.after(async () => { if (browser) await browser.close(); });

/** Reads one IndexedDB store of the app. */
function idbAll(p, store) {
	return p.evaluate((name) => new Promise((resolve, reject) => {
		const req = indexedDB.open('wpcap-offline-scanner', 1);
		req.onsuccess = () => { const all = req.result.transaction(name, 'readonly').objectStore(name).getAll(); all.onsuccess = () => resolve(all.result); all.onerror = () => reject(all.error); };
		req.onerror = () => reject(req.error);
	}), store);
}
function idbGet(p, key) {
	return p.evaluate((k) => new Promise((resolve) => {
		const req = indexedDB.open('wpcap-offline-scanner', 1);
		req.onsuccess = () => { const r = req.result.transaction('kv', 'readonly').objectStore('kv').get(k); r.onsuccess = () => resolve(r.result); };
	}), key);
}

test('pairing screen is translated with the site locale (table from /app/offline/i18n, kept in IndexedDB)', withPage(() => ctx, 'pwa pairing screen', async (p) => {
	const i18nResponse = p.waitForResponse((r) => r.url().includes('offline/i18n'));
	await p.goto(APP);
	const table = await (await i18nResponse).json();
	assert.equal(table.v, 1);
	await p.locator('#pair-view').waitFor({ state: 'visible' });
	assert.ok(await p.locator('#main-view').isHidden());
	const expected = table.strings['Gerät koppeln'];
	await p.waitForFunction((t) => document.querySelector('#pair-view h1').textContent === t, expected);
	assert.equal(await p.evaluate(() => document.documentElement.lang), table.lang);
	assert.equal(await p.locator('#pair-label').getAttribute('placeholder'), table.strings['z. B. Einlass Nord']);
	assert.equal(await p.locator('#api-base').innerText(), API);
	assert.equal(await idbGet(p, 'api'), API, 'API base stored');
	assert.ok(!p.url().includes('api='), 'parameter removed from the address bar');
	assert.deepEqual((await idbGet(p, 'i18n')).strings['Gerät koppeln'], expected, 'table stored for offline starts');

	// Restart without a reachable i18n endpoint: the stored table translates the app.
	let blocked = 0;
	await p.route((url) => url.href.includes('offline/i18n'), (route) => { blocked++; return route.abort(); });
	await p.reload();
	await p.locator('#pair-view').waitFor({ state: 'visible' });
	await p.waitForFunction((t) => document.querySelector('#pair-view h1').textContent === t, expected);
	assert.ok(blocked >= 1, 'the i18n request was attempted and failed');
}));

test('pair, load the signed package, scan offline into the IndexedDB queue, sync after reconnecting', withPage(() => ctx, 'pwa offline queue', async (p) => {
	await p.goto(APP);
	await p.locator('#pair-view').waitFor({ state: 'visible' });
	const pairing = helper('pairing', door.event_id);
	await p.fill('#pair-code', pairing.code);
	await p.fill('#pair-label', 'E2E PWA');
	await p.click('#pair-form button[type="submit"]');
	await p.locator('#main-view').waitFor({ state: 'visible' });
	assert.ok(await p.locator('#pair-view').isHidden());
	assert.ok(await idbGet(p, 'token'), 'device token stored in IndexedDB');
	const keys = await idbGet(p, 'keys');
	assert.ok(Object.keys(keys).length >= 1, 'Ed25519 public keys stored');

	// Admin assigns the device to the occurrence; the app downloads and verifies the package.
	helper('assign', door.event_id, door.occurrence_id);
	await p.click('#refresh');
	await p.waitForFunction(() => /Ed25519/.test(document.getElementById('pkg').textContent), null, { timeout: 20000 });
	assert.match(await p.locator('#pkg').innerText(), /2 /, 'two tickets in the package');
	const packages = await idbAll(p, 'packages');
	assert.equal(packages.length, 1);
	assert.equal(packages[0].occurrence_id, door.occurrence_id);

	// Offline: decided locally against the package, queued with an idempotency key.
	await ctx.setOffline(true);
	await p.waitForFunction(() => document.getElementById('net').classList.contains('offline'));
	await p.fill('#manual-code', door.codes[0]);
	await p.click('#manual button[type="submit"]');
	await p.waitForFunction(() => document.getElementById('result').classList.contains('ok'));
	assert.match(await p.locator('#result-title').innerText(), /offline/i);
	let log = await idbAll(p, 'log');
	assert.equal(log.length, 1);
	assert.equal(log[0].synced, 0);
	assert.equal(log[0].mode, 'offline');
	assert.equal(log[0].code, door.codes[0], 'code kept until uploaded');
	assert.ok(log[0].key);
	assert.match(await p.locator('#pending').innerText(), /1/);
	assert.equal(helper('ticket', door.codes[0]).checkins, 0, 'server does not know the scan yet');

	// Same ticket again while offline: refused locally.
	await p.fill('#manual-code', door.codes[0]);
	await p.click('#manual button[type="submit"]');
	await p.waitForFunction(() => document.getElementById('result').classList.contains('bad'));

	// Back online: the "online" event triggers refresh + sync.
	await ctx.setOffline(false);
	await p.waitForFunction(() => /^0\b/.test(document.getElementById('pending').textContent.trim()), null, { timeout: 20000 });
	log = await idbAll(p, 'log');
	const uploaded = log.find((e) => e.ok);
	assert.equal(uploaded.synced, 1);
	assert.equal(uploaded.code, '', 'code removed after upload');
	assert.equal(uploaded.server.ok, true);
	const ticket = helper('ticket', door.codes[0]);
	assert.equal(ticket.status, 'checked_in');
	assert.equal(ticket.checkins, 1);
}));
