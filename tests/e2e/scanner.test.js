'use strict';
/* Web scanner shortcode: camera permission denied → manual code entry; camera granted → video. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, BROWSER, fixture, helper, launch, context, login, withPage } = require('./lib');

let browser, denied, granted, fx;
test.before(async () => {
	fx = fixture(); // scanner page URL
	fx.door = helper('today', 1); // ticket for an occurrence inside the admission window
	browser = await launch();
	denied = await context(browser); // no camera permission
	await login(denied);
	if (BROWSER === 'chromium') {
		granted = await context(browser);
		await granted.grantPermissions(['camera'], { origin: BASE });
		await login(granted);
	}
});
test.after(async () => { if (browser) await browser.close(); });

test('camera permission denied: error is announced, the manual code entry still checks tickets in', withPage(() => denied, 'scanner camera denied', async (p) => {
	await p.goto(fx.scanner_url);
	const i18n = await p.evaluate(() => window.WPCAP_SCANNER.i18n);
	const result = p.locator('.wpcap-scan-result');
	assert.equal(await result.getAttribute('aria-live'), 'polite');
	await p.locator('.wpcap-camera-start').click();
	await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').textContent.trim() !== '');
	const text = await result.innerText();
	assert.ok(text.startsWith(i18n.cameraError) || text === i18n.noCamera, 'camera error shown: ' + text);
	assert.ok(await p.evaluate(() => !document.querySelector('video.wpcap-camera').srcObject), 'no camera stream without permission');
	assert.equal(await p.locator('.wpcap-camera-start').isDisabled(), false, 'camera can be retried');

	const code = fx.door.codes[0];
	assert.ok(code, 'fixture ticket code');
	const input = p.locator('.wpcap-scan-form [name="token"]');
	await input.fill(code);
	await input.press('Enter');
	await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').dataset.state === 'success');
	assert.match(await result.innerText(), /^✓ .+ — .+/);
	assert.equal(await input.inputValue(), '', 'input cleared for the next guest');
	assert.equal(helper('ticket', code).status, 'checked_in');

	await p.waitForTimeout(1500); // scanner lock after a scan
	await input.fill(code);
	await input.press('Enter');
	await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').dataset.state === 'error');
	assert.notEqual(await result.innerText(), '', 'second scan is rejected with a message');
}));

test('camera permission granted (Chromium fake camera): video starts, manual input keeps working', { skip: BROWSER !== 'chromium' && 'fake camera only in Chromium' }, withPage(() => granted, 'scanner camera granted', async (p) => {
	await p.goto(fx.scanner_url);
	await p.locator('.wpcap-camera-start').click();
	await p.locator('video.wpcap-camera').waitFor({ state: 'visible' });
	await p.waitForFunction(() => document.querySelector('.wpcap-camera-start').disabled);
	assert.ok(await p.evaluate(() => !!document.querySelector('video.wpcap-camera').srcObject));
	const input = p.locator('.wpcap-scan-form [name="token"]');
	await input.fill('ungueltig');
	await input.press('Enter');
	await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').dataset.state === 'error');
}));
