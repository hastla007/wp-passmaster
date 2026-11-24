'use strict';
/*
 * A124/A142: end-to-end flows across buyer and staff screens, with the plugin's own payment stub (manual
 * payment marked paid by staff; no payment service is called):
 *  - refund: the buyer asks for a cancellation in the order portal, staff approves it on "Erstattungen zur
 *    Freigabe", the refund is booked (manual payment: paid back outside the plugin) and the portal shows
 *    it – the ticket is refunded and can no longer be requested,
 *  - service slot booking with rebooking: book a free slot, open the manage link, rebook to another slot;
 *    the booking keeps its id, moves to the new slot and the manage view shows the new time.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, BROWSER, fixture, helper, launch, context, login, withPage } = require('./lib');

let browser, buyer, staff, fx;
test.before(async () => {
	fx = fixture();
	browser = await launch();
	buyer = await context(browser);
	staff = await context(browser);
	await login(staff);
});
test.after(async () => { if (browser) await browser.close(); });

test('refund: cancellation request in the portal → approval by staff → the portal shows the refund', async () => {
	const order = helper('paid-order', 2);
	let refundId = 0;
	await withPage(() => buyer, 'refund flow buyer request', async (p) => {
		await p.goto(order.portal_url);
		const s = await p.evaluate(() => window.wpcapPortal.strings);
		const form = p.locator('.wpcap-refund-form');
		assert.equal(await form.locator('input[type="checkbox"]').count(), 2, 'both tickets can be requested');
		await form.locator('input[type="checkbox"]').first().check();
		await form.locator('textarea[name="reason"]').fill('Termin passt nicht mehr');
		await form.locator('button[type="submit"]').click();
		await p.locator('#wpcap-confirm').waitFor({ state: 'visible' });
		await p.locator('#wpcap-confirm-ok').click();
		await p.waitForFunction(() => !document.querySelector('.wpcap-refund-form button[type="submit"]'), null, { timeout: 15000 });
		assert.notEqual((await form.locator('.message').innerText()).trim(), s.refundFailed);
		const refunds = helper('refunds', order.public_id);
		assert.equal(refunds.refunds.length, 1);
		assert.equal(refunds.refunds[0].status, 'pending_approval', 'waits for staff');
		assert.equal(refunds.tickets.filter((t) => Number(t.refunded)).length, 0, 'tickets stay valid until approval');
		refundId = Number(refunds.refunds[0].refund_id);
		// The portal lists the open request.
		await p.reload();
		assert.equal(await p.locator('.wpcap-refund-list li').count(), 1);
	})();

	await withPage(() => staff, 'refund flow staff approval', async (p) => {
		await p.goto(BASE + '/wp-admin/admin.php?page=wpcap-refunds');
		const approve = p.locator(`form:has(input[name="refund_id"][value="${refundId}"]) button[type="submit"].button-primary`);
		assert.equal(await approve.count(), 1, 'the request is in the approval queue');
		p.once('dialog', (d) => d.accept()); // "Erstattung freigeben und auszahlen?"
		await Promise.all([p.waitForNavigation(), approve.click()]);
		assert.ok(await p.locator('.notice-success').count() >= 1, 'success notice; notices: ' + (await p.locator('.notice').allInnerTexts()).join(' | '));
		assert.equal(await p.locator(`form:has(input[name="refund_id"][value="${refundId}"]) button.button-primary`).count(), 0, 'gone from the queue');
	})();
	const after = helper('refunds', order.public_id);
	assert.equal(after.refunds[0].status, 'completed', 'manual payment: booked as paid back outside the plugin');
	assert.equal(Number(after.refunds[0].amount_minor), 2500, 'one ticket of 25,00 €');
	assert.deepEqual(after.tickets.map((t) => Number(t.refunded)).sort(), [0, 1], 'one ticket refunded, the other kept');

	await withPage(() => buyer, 'refund flow buyer portal', async (p) => {
		await p.goto(order.portal_url);
		const item = p.locator('.wpcap-refund-list li');
		assert.equal(await item.count(), 1);
		const text = await item.innerText();
		assert.match(text, /25[.,]00/, 'amount shown: ' + text);
		assert.match(text, /Erstattet|Refunded|Rembours|Reembols|Rimbors|Terugbetaald/i, 'status shown as refunded: ' + text);
		assert.equal(await p.locator('.wpcap-refund-form input[type="checkbox"]').count(), 1, 'only the kept ticket can still be requested');
	})();
});

test('service slot booking: book a free slot, rebook it through the manage link', withPage(() => buyer, 'service rebooking', async (p) => {
	await p.goto(fx.service_url);
	const sb = p.locator('.wpcap-service-booking').first();
	// The free resource (second choice of the fixture).
	await sb.locator('input[name="resource_id"]').nth(1).check();
	await p.waitForFunction(() => !document.querySelector('.wpcap-sb-days[aria-busy]'));
	await sb.locator('.wpcap-sb-days input[name="slot_id"]').first().waitFor();
	await sb.locator('.wpcap-sb-days input[name="slot_id"]').first().check();
	await sb.locator('.wpcap-sb-continue').click();
	const email = 'e2e-rebook-' + BROWSER + '-' + Date.now() + '@example.com';
	await sb.locator('.wpcap-sb-step-data [name="email"]').fill(email);
	if (await sb.locator('.wpcap-sb-step-data [name="accept_terms"]').count()) await sb.locator('.wpcap-sb-step-data [name="accept_terms"]').check();
	await sb.locator('.wpcap-sb-submit').click();
	await p.locator('.wpcap-sb-confirmation').waitFor({ timeout: 20000 });
	const booked = helper('booking', email).booking;
	assert.ok(booked, 'booking stored');
	assert.equal(booked.status, 'confirmed', 'a free booking is confirmed at once');

	await Promise.all([p.waitForURL(/#wpcap-booking=/), p.locator('.wpcap-sb-manage a').click()]);
	await p.locator('.wpcap-sb-details').waitFor({ timeout: 15000 });
	const before = await p.locator('.wpcap-sb-details').innerText();
	const select = p.locator('.wpcap-sb-rebook select');
	await select.waitFor();
	// Another slot of the same (free) resource; a more expensive one would need a new booking.
	const resourceName = before.split('·')[0].replace(/^[^\n]*\n/, '').trim();
	const target = await select.evaluate((el, a) => { const o = Array.from(el.options).find((x) => x.value !== String(a.old) && x.textContent.trim().startsWith(a.name)); return o ? o.value : ''; }, { old: booked.slot_id, name: resourceName });
	assert.ok(target, 'another slot of "' + resourceName + '" is offered');
	await select.selectOption(target);
	await p.locator('.wpcap-sb-rebook button[type="submit"]').click();
	// The manage view reloads with the notice and gets the focus.
	await p.waitForFunction(() => { const v = document.querySelector('.wpcap-sb-manage-view'); return v && !v.hasAttribute('aria-busy') && v.querySelector('.wpcap-message').textContent.trim() !== '' && document.activeElement === v; }, null, { timeout: 15000 });
	const rebooked = helper('booking', email).booking;
	assert.equal(rebooked.booking_id, booked.booking_id, 'the same booking');
	assert.equal(String(rebooked.slot_id), String(target), 'moved to the chosen slot');
	assert.equal(rebooked.status, 'confirmed');
	assert.notEqual(await p.locator('.wpcap-sb-details').innerText(), before, 'the manage view shows the new time');
	// A fresh visit of the manage link shows the same.
	await p.reload();
	await p.locator('.wpcap-sb-details').waitFor({ timeout: 15000 });
	assert.notEqual(await p.locator('.wpcap-sb-details').innerText(), before);
}));
