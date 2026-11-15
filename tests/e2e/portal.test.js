'use strict';
/* Order portal: attendee assignment, ticket transfer with confirm dialog, resend. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, launch, context, withPage, assertFitsViewport } = require('./lib');

let browser, ctx, fx, fxTransfer;
test.before(async () => {
	fx = fixture(); // fresh free order with one valid ticket (assignment, resend)
	fxTransfer = fixture(); // separate order: an attendee e-mail change already counts as the one holder change
	browser = await launch();
	ctx = await context(browser);
});
test.after(async () => { if (browser) await browser.close(); });
const page = (name, fn) => withPage(() => ctx, name, fn);
const strings = (p) => p.evaluate(() => window.wpcapPortal.strings);

test('assign attendee: a new e-mail rotates the code, a name change keeps it', page('portal assignment', async (p) => {
	await p.goto(fx.portal_url);
	await assertFitsViewport(p, 'order portal');
	const s = await strings(p);
	const form = p.locator('.wpcap-assignment-form').first();
	const message = () => p.evaluate(() => document.querySelector('.wpcap-assignment-form .message').textContent);
	assert.equal(await form.locator('[name="attendee_name"]').inputValue(), 'Alex Muster');
	// The attendee e-mail is required: native validation blocks the submit while it is empty.
	await form.locator('button[type="submit"]').click();
	await p.waitForTimeout(300);
	assert.equal(await message(), '');
	assert.equal(await form.locator('[name="attendee_email"]').evaluate((el) => el.validity.valueMissing), true);
	await form.locator('[name="attendee_email"]').fill('alexandra-' + Date.now() + '@example.com');
	await form.locator('button[type="submit"]').click();
	await p.waitForFunction((t) => document.querySelector('.wpcap-assignment-form .message').textContent === t, s.assignedRotated);
	await form.locator('[name="attendee_name"]').fill('Alexandra Muster');
	await form.locator('button[type="submit"]').click();
	await p.waitForFunction((t) => document.querySelector('.wpcap-assignment-form .message').textContent === t, s.assignedKept);
	await p.reload();
	assert.equal(await p.locator('.wpcap-assignment-form [name="attendee_name"]').first().inputValue(), 'Alexandra Muster', 'saved on the server');
}));

test('resend tickets: confirm dialog, then success message', page('portal resend', async (p) => {
	await p.goto(fx.portal_url);
	const s = await strings(p);
	await p.locator('.wpcap-resend-form button[type="submit"]').click();
	const dialog = p.locator('#wpcap-confirm');
	await dialog.waitFor({ state: 'visible' });
	await assertFitsViewport(p, 'portal confirm dialog');
	// After the attendee test a ticket of this order is passed on: the confirmation then names the warning (R3).
	const passedOn = Number(await p.locator('.wpcap-resend-form').getAttribute('data-passed-on')) || 0;
	const ownBox = await p.locator('.wpcap-resend-form [name="only_own"]').count();
	const expected = passedOn > 0 && !ownBox ? s.resendConfirmAll.replace('%d', String(passedOn)) : s.resendConfirm;
	assert.equal(await p.locator('#wpcap-confirm-text').innerText(), expected);
	await p.locator('#wpcap-confirm-ok').click();
	const message = p.locator('.wpcap-resend-form .message');
	await p.waitForFunction(() => /@/.test(document.querySelector('.wpcap-resend-form .message').textContent));
	assert.match(await message.innerText(), /\*\*\*@example\.com|@example\.com/);
}));

test('transfer: cancel in the dialog changes nothing; confirm transfers once', page('portal transfer', async (p) => {
	await p.goto(fxTransfer.portal_url);
	const s = await strings(p);
	const form = p.locator('.wpcap-transfer-form').first();
	const recipient = 'friend-' + Date.now() + '@example.com';
	await form.locator('[name="recipient_email"]').fill(recipient);
	await form.locator('button[type="submit"]').click();
	const dialog = p.locator('#wpcap-confirm');
	await dialog.waitFor({ state: 'visible' });
	assert.equal(await p.locator('#wpcap-confirm-text').innerText(), s.transferConfirm.replace('%s', recipient));
	assert.ok(await p.evaluate(() => document.activeElement && document.activeElement.value === 'cancel'), 'cancel has focus');
	await p.keyboard.press('Escape');
	await dialog.waitFor({ state: 'hidden' });
	assert.ok(await p.evaluate(() => document.activeElement && document.activeElement.closest('.wpcap-transfer-form')), 'focus returns to the form');
	assert.equal(await form.locator('.message').innerText(), '');

	await form.locator('button[type="submit"]').click();
	await dialog.waitFor({ state: 'visible' });
	await p.locator('#wpcap-confirm-ok').click();
	await p.waitForFunction((t) => document.querySelector('.wpcap-transfer-form .message').textContent === t, s.transferred);
	assert.equal(await form.locator('button').count(), 0, 'transfer controls are removed');
	await p.reload();
	assert.equal(await p.locator('.wpcap-transfer-form').count(), 0, 'a ticket can be transferred only once');
}));
