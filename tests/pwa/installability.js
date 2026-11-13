/**
 * A120: automated part of the PWA acceptance for the offline scanner (Chromium).
 *
 * Serves assets/ with `php -S` on localhost (secure context), then checks in Chromium desktop and in
 * an Android phone emulation (Pixel 7 profile):
 *   - manifest is valid and Chrome reports no installability errors (CDP Page.getInstallabilityErrors),
 *   - the service worker installs, controls the page and caches the whole app shell,
 *   - the app starts offline from the cache (pairing screen, no script errors),
 *   - after a new SW version the old cache is removed.
 * Usage: node tests/pwa/installability.js   (needs php and Playwright with Chromium)
 */
'use strict';
const { chromium, devices } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..', 'assets');
const PORT = 8000 + Math.floor(Math.random() * 900);
const URL = `http://localhost:${PORT}/offline-scanner/index.html`;
let fails = 0;
const expect = (name, cond, extra) => { console.log((cond ? 'ok   ' : 'FAIL ') + name + (cond || !extra ? '' : ' – ' + extra)); if (!cond) fails++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function run(label, contextOptions) {
	const browser = await chromium.launch();
	const context = await browser.newContext(contextOptions);
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e)));
	page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
	await page.goto(URL);
	await page.waitForSelector('#pair-view:not([hidden])', { timeout: 10000 });
	const manifest = await page.evaluate(async () => (await fetch(document.querySelector('link[rel=manifest]').href)).json());
	expect(`${label}: manifest standalone, start_url and scope inside the app`, manifest.display === 'standalone' && manifest.start_url && manifest.scope === './');
	expect(`${label}: manifest has 192px, 512px and maskable icons`, ['192x192', '512x512'].every((s) => manifest.icons.some((i) => i.sizes === s)) && manifest.icons.some((i) => (i.purpose || '').includes('maskable')));
	await page.evaluate(() => navigator.serviceWorker.ready);
	await page.reload();
	await page.waitForSelector('#pair-view:not([hidden])');
	expect(`${label}: service worker controls the page`, await page.evaluate(() => !!navigator.serviceWorker.controller));
	const cdp = await context.newCDPSession(page);
	const inst = await cdp.send('Page.getInstallabilityErrors');
	expect(`${label}: Chrome reports no installability errors`, inst.installabilityErrors.length === 0, JSON.stringify(inst.installabilityErrors));
	const cached = await page.evaluate(async () => {
		const keys = await caches.keys();
		const own = keys.filter((k) => k.indexOf('wpcap-offline-scanner-') === 0);
		const reqs = own.length ? await (await caches.open(own[0])).keys() : [];
		return { caches: own, urls: reqs.map((r) => new URL(r.url).pathname) };
	});
	const needed = ['index.html', 'i18n.js', 'app.js', 'app.css', 'manifest.json', 'icon-192.png', 'icon-512.png', 'nacl-fast.min.js', 'jsQR.js'];
	expect(`${label}: exactly one app cache with the whole shell`, cached.caches.length === 1 && needed.every((n) => cached.urls.some((u) => u.endsWith(n))), JSON.stringify(cached));
	await context.setOffline(true);
	await page.reload();
	await page.waitForSelector('#pair-view:not([hidden])', { timeout: 10000 });
	expect(`${label}: app starts offline from the cache`, await page.isVisible('#pair-view'));
	await context.setOffline(false);
	// Old caches from a previous version are removed on activation.
	await page.evaluate(async () => { const c = await caches.open('wpcap-offline-scanner-v0'); await c.put('/stale', new Response('x')); });
	await page.evaluate(async () => { const r = await navigator.serviceWorker.getRegistration(); await r.unregister(); });
	await page.reload();
	await page.evaluate(() => navigator.serviceWorker.ready);
	await sleep(500);
	const after = await page.evaluate(async () => (await caches.keys()).filter((k) => k.indexOf('wpcap-offline-scanner-') === 0));
	expect(`${label}: outdated caches are deleted on activation`, !after.includes('wpcap-offline-scanner-v0') && after.length === 1, JSON.stringify(after));
	expect(`${label}: no script errors`, errors.length === 0, errors.join(' | '));
	await browser.close();
}

(async () => {
	const server = spawn('php', ['-S', `localhost:${PORT}`, '-t', ROOT], { stdio: 'ignore' });
	await sleep(700);
	try {
		await run('Chromium desktop', {});
		await run('Android phone (Pixel 7 emulation)', { ...devices['Pixel 7'] });
	} catch (e) {
		console.log('FAIL ' + e.message);
		fails++;
	} finally {
		server.kill();
	}
	process.exit(fails ? 1 : 0);
})();
