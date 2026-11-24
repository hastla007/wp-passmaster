'use strict';
/*
 * A124/A142: offline check-out in the scanner PWA (docs/OFFLINE-SCANNER.md, "Check-out (Auslass) offline").
 * One device, three tickets of an occurrence inside the admission window:
 *  - ticket 1 is admitted online, then the connection is lost: switched to "Auslass" it is checked out at
 *    once ("Ausgecheckt (offline)", this device admitted it); a second check-out is refused,
 *  - ticket 2 was never admitted here: its check-out is only queued ("Check-out vorgemerkt"), the server
 *    refuses it at sync and the log says so readably ("Check-out abgelehnt: …", no conflict),
 *  - ticket 3 is admitted offline while another device checks it in on the server: after reconnecting the
 *    sync shows a readable conflict (result text, not an internal code) in "Konflikte nach Synchronisierung".
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, fixture, helper, launch, context, withPage } = require('./lib');

const API = BASE + '/index.php?rest_route=/pass-captain/v1/app/';
const APP = BASE + '/wp-content/plugins/pass-captain/assets/offline-scanner/index.html?api=' + encodeURIComponent(API);
let browser, ctx, door;
test.before(async () => {
	fixture();
	door = helper('today', 3);
	browser = await launch();
	ctx = await context(browser, { serviceWorkers: 'block' });
});
test.after(async () => { if (browser) await browser.close(); });

function idbLog(p) {
	return p.evaluate(() => new Promise((resolve, reject) => {
		const req = indexedDB.open(window.wpcapOffline.storeName(), 1);
		req.onsuccess = () => { const all = req.result.transaction('log', 'readonly').objectStore('log').getAll(); all.onsuccess = () => resolve(all.result); all.onerror = () => reject(all.error); };
		req.onerror = () => reject(req.error);
	}));
}
async function scan(p, code, state) {
	await p.fill('#manual-code', code);
	await p.click('#manual button[type="submit"]');
	await p.waitForFunction((s) => document.getElementById('result').classList.contains(s), state, { timeout: 10000 });
	// The full-screen result closes on a tap (the next scan must reach the form).
	if (await p.locator('#overlay').isVisible()) await p.locator('#overlay').click();
	return (await p.locator('#result-title').innerText()).trim();
}

test('offline check-out: checked out at once when admitted here, queued otherwise; sync shows refusals and conflicts readably', withPage(() => ctx, 'pwa offline checkout', async (p) => {
	await p.goto(APP);
	await p.locator('#pair-view').waitFor({ state: 'visible' });
	const pairing = helper('pairing', door.event_id);
	await p.fill('#pair-code', pairing.code);
	await p.fill('#pair-label', 'E2E Auslass');
	await p.click('#pair-form button[type="submit"]');
	await p.locator('#main-view').waitFor({ state: 'visible' });
	helper('assign', door.event_id, door.occurrence_id);
	await p.click('#refresh');
	await p.waitForFunction(() => /Ed25519/.test(document.getElementById('pkg').textContent), null, { timeout: 20000 });
	// The direction switch needs the check-out right (administrators always) and check-out rules in the package.
	await p.locator('#mode-wrap').waitFor({ state: 'visible', timeout: 10000 });
	const [c1, c2, c3] = door.codes;

	// Ticket 1: admitted online.
	assert.ok((await scan(p, c1, 'ok')).length > 3, 'admitted online');
	assert.equal(helper('ticket', c1).status, 'checked_in');

	// Connection lost.
	await ctx.setOffline(true);
	await p.waitForFunction(() => document.getElementById('net').classList.contains('offline'));
	await p.click('#mode-out');
	assert.equal(await p.locator('#mode-out').getAttribute('aria-pressed'), 'true');
	const outOk = await scan(p, c1, 'ok');
	assert.match(outOk, /offline/i, 'checked out at once: ' + outOk);
	const again = await scan(p, c1, 'bad');
	assert.ok(again.length > 3, 'second check-out refused with a text: ' + again);
	assert.match(await p.locator('#result-detail').innerText(), /.{10,}/, 'the reason is named');
	const queued = await scan(p, c2, 'warn');
	assert.ok(queued.length > 3, 'check-out of a ticket not admitted here is only queued: ' + queued);

	// Ticket 3: admitted offline here while another device admits it on the server.
	await p.click('#mode-in');
	await scan(p, c3, 'ok');
	assert.equal(helper('checkin', c3).ok, true, 'the other device admitted ticket 3');
	let log = await idbLog(p);
	assert.equal(log.filter((e) => !e.synced).length, 3, 'check-out 1, queued check-out 2 and admission 3 wait for the upload');
	await p.waitForFunction(() => /^3\b/.test(document.getElementById('pending').textContent.trim()), null, { timeout: 5000 });

	// Back online: one sync; check-out 1 accepted, check-out 2 refused (no conflict), admission 3 a conflict.
	await ctx.setOffline(false);
	await p.click('#sync').catch(() => {});
	await p.waitForFunction(() => /^0\b/.test(document.getElementById('pending').textContent.trim()), null, { timeout: 30000 });
	log = await idbLog(p);
	const out1 = log.find((e) => e.direction === 'out' && e.ok && e.server && e.server.ok);
	assert.ok(out1, 'the offline check-out of ticket 1 was accepted');
	assert.equal(out1.code, '', 'code removed after upload');
	const out2 = log.find((e) => e.direction === 'out' && e.server && !e.server.ok);
	assert.ok(out2, 'the queued check-out of ticket 2 was refused by the server');
	assert.equal(out2.server.conflict, false, 'a refused check-out is no admission conflict');
	// After the sync nothing waits any more: the only warning tag left is the refused check-out.
	const refusedRow = p.locator('#log li').filter({ has: p.locator('.tag.warn') });
	assert.ok(await refusedRow.count() >= 1, 'the log marks the refused check-out');
	const refusedText = await refusedRow.first().innerText();
	assert.doesNotMatch(refusedText, /not_checked_in|[a-z]+_[a-z]+/, 'readable text, not an internal code: ' + refusedText);

	const conflicts = p.locator('#conflicts li');
	await p.waitForFunction(() => document.querySelectorAll('#conflicts li .tag').length >= 1, null, { timeout: 10000 });
	const conflictText = await conflicts.first().innerText();
	assert.match(conflictText, /…[a-f0-9]{8}/, 'names the ticket by the end of its hash: ' + conflictText);
	assert.doesNotMatch(conflictText, /already_checked_in|double_admission|[a-z]+_[a-z]+_[a-z]+/, 'readable text: ' + conflictText);
	assert.ok(await conflicts.first().locator('.tag').count() === 1, 'marked for review or auto-resolved');

	// The server state matches: ticket 1 left, ticket 2 untouched (never inside).
	assert.equal(helper('ticket', c2).status, 'valid', 'ticket 2 was never admitted (the refused check-out is only logged)');
	assert.equal(helper('ticket', c3).status, 'checked_in');
}));
