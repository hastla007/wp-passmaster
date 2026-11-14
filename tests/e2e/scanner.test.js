'use strict';
/*
 * Web scanner shortcode: camera permission denied (Chromium/WebKit) or no camera (Firefox) → manual code entry;
 * camera granted (fake camera, all three engines – see lib.js) → video.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BROWSER, fixture, helper, launch, grantCamera, context, login, withPage } = require('./lib');

let browser, cameraBrowser, denied, granted, fx;
test.before(async () => {
	fx = fixture(); // scanner page URL
	fx.door = helper('today', 1); // ticket for an occurrence inside the admission window
	browser = await launch();
	denied = await context(browser); // no camera permission
	await login(denied);
	// Firefox: a second instance with fake-camera prefs; Chromium/WebKit: same browser, permission granted.
	cameraBrowser = BROWSER === 'firefox' ? await launch({ camera: true }) : browser;
	granted = await context(cameraBrowser);
	await grantCamera(granted);
	await login(granted);
});
test.after(async () => {
	if (cameraBrowser && cameraBrowser !== browser) await cameraBrowser.close();
	if (browser) await browser.close();
});

test('camera permission denied: error is announced, the manual code entry still checks tickets in', withPage(() => denied, 'scanner camera denied', async (p) => {
	await p.goto(fx.scanner_url);
	const i18n = await p.evaluate(() => window.WPCAP_SCANNER.i18n);
	const result = p.locator('.wpcap-scan-result');
	assert.equal(await result.getAttribute('aria-live'), 'polite');
	assert.ok(await p.locator('video.wpcap-camera').isHidden(), 'no empty video box before the camera is started');
	await p.locator('.wpcap-camera-start').click();
	await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').textContent.trim() !== '');
	const text = await result.innerText();
	assert.ok(text.startsWith(i18n.cameraError) || text === i18n.noCamera, 'camera error shown: ' + text);
	assert.ok(await p.evaluate(() => !document.querySelector('video.wpcap-camera').srcObject), 'no camera stream without permission');
	assert.equal(await p.locator('.wpcap-camera-start').isDisabled(), false, 'camera can be retried');
	assert.ok(await p.locator('video.wpcap-camera').isHidden(), 'video stays hidden without a stream');

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

test('camera permission granted (fake camera): video starts, manual input keeps working', withPage(() => granted, 'scanner camera granted', async (p) => {
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
