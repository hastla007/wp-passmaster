'use strict';
/* Public checkout: ticket selection, buyer data, attendee fields, fake hosted payment, order portal. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, installMuPlugin, fixture, helper, launch, context, withPage, assertFitsViewport } = require('./lib');

let browser, ctx, fx;
test.before(async () => {
	installMuPlugin();
	fx = fixture();
	browser = await launch();
	ctx = await context(browser);
	// Only this browser is offered the fake payment provider (see mu-plugin/wpcap-e2e.php).
	await ctx.addCookies([{ name: 'wpcap_e2e', value: '1', url: BASE }]);
});
test.after(async () => { if (browser) await browser.close(); });
const page = (name, fn) => withPage(() => ctx, name, fn);

/**
 * Chooses the payment method: a radio button when several are offered; a single method is rendered as a
 * hidden input (merged checkout) and only needs to be the expected one. Returns false when it is not offered.
 */
async function chooseProvider(form, id) {
	const input = form.locator(`[name="provider"][value="${id}"]`);
	if (!(await input.count())) return false;
	if ((await input.getAttribute('type')) === 'radio') await input.check();
	else assert.equal(await form.locator('[name="provider"]').count(), 1, 'a hidden provider input only for a single method');
	return true;
}

/** The checkout form of the fixture event. */
function eventForm(p) {
	return p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`) });
}

test('paid order: tickets, buyer data and attendees → fake payment page → paid, tickets in the portal', page('paid checkout', async (p) => {
	await p.goto(fx.events_url);
	const form = eventForm(p);
	await form.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`).fill('2');
	await assert.doesNotReject(form.locator('.wpcap-attendee').nth(1).waitFor());
	await assertFitsViewport(p, 'checkout with attendee fields');
	assert.equal(await form.locator('.wpcap-attendee').count(), 2, 'one attendee fieldset per ticket');
	assert.match(await form.locator('.wpcap-estimate').innerText(), /50[,.]00/);
	const sold = form.locator(`.wpcap-qty[data-type="${fx.ticket_types.Ausverkauft}"]`);
	if (await sold.count()) assert.ok(await sold.isDisabled(), 'sold-out type cannot be selected');
	await form.locator('.wpcap-attendee').nth(0).locator('[data-key="__name"]').fill('Erika Muster');
	await form.locator('.wpcap-attendee').nth(1).locator('[data-key="__name"]').fill('Max Muster');
	const email = 'e2e-' + Date.now() + '@example.com';
	await form.locator('[name="email"]').fill(email);
	if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
	assert.ok(await chooseProvider(form, 'e2e_fake'), 'the fake provider is offered to this browser');
	// Still in the form right before submitting (separates a lost input in the browser from a lost value on the server).
	const typed = await form.locator('.wpcap-attendee [data-key="__name"]').evaluateAll((els) => els.map((e) => e.value));
	assert.deepEqual(typed, ['Erika Muster', 'Max Muster'], 'attendee names are still in the form before submitting');
	await Promise.all([p.waitForURL(/wpcap_e2e_pay=/), form.locator('button[type="submit"]').click()]);

	assert.match(await p.locator('#e2e-amount').innerText(), /50,00 EUR/);
	const publicId = new URL(p.url()).searchParams.get('wpcap_e2e_pay');
	assert.equal(helper('order', publicId).order.payment_status, 'pending', 'unpaid until the provider confirms');
	await Promise.all([p.waitForLoadState('load'), p.click('text=Jetzt bezahlen')]);
	assert.equal(await p.locator('#e2e-state').innerText(), 'Zahlung erfolgreich');

	const state = helper('order', publicId);
	assert.equal(state.order.payment_status, 'paid');
	assert.equal(state.order.payment_provider, 'e2e_fake');
	assert.deepEqual(state.tickets.map((t) => t.status), ['valid', 'valid']);

	await p.click('#e2e-portal');
	await p.waitForLoadState('load');
	assert.match(await p.locator('main').innerText(), /Paid|Bezahlt/);
	const names = await p.locator('.wpcap-assignment-form [name="attendee_name"]').evaluateAll((els) => els.map((e) => e.value).sort());
	assert.deepEqual(names, ['Erika Muster', 'Max Muster'], 'attendee names from the checkout are on the tickets');
}));

test('free order: confirmation panel receives focus and names the order number', page('free checkout', async (p) => {
	await p.goto(fx.events_url);
	const form = eventForm(p);
	await form.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`).fill('1');
	await form.locator('.wpcap-attendee [data-key="__name"]').fill('Kim Gast');
	await form.locator('.wpcap-attendee [data-key="diet"]').fill('vegetarisch');
	await form.locator('[name="email"]').fill('e2e-free-' + Date.now() + '@example.com');
	if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
	await chooseProvider(form, 'manual');
	await form.locator('button[type="submit"]').click();
	const panel = p.locator('.wpcap-confirmation');
	await panel.waitFor({ timeout: 15000 });
	assert.ok(await panel.evaluate((el) => el === document.activeElement), 'focus moves to the confirmation');
	assert.match(await panel.locator('.wpcap-order-number').innerText(), /[0-9A-F]{4}-[0-9A-F]{4}/);
	assert.ok(await form.isHidden());
	await panel.locator('.wpcap-order-again').click();
	assert.ok(await form.isVisible(), '"order more" brings the form back');
}));

test('validation: empty submit shows a focused error summary linking to the fields', page('checkout validation', async (p) => {
	await p.goto(fx.events_url);
	const form = eventForm(p);
	await form.locator('[name="email"]').fill('keine-adresse');
	await form.locator('button[type="submit"]').click();
	const summary = form.locator('.wpcap-error-summary');
	await summary.waitFor();
	await assertFitsViewport(p, 'checkout error summary');
	assert.equal(await summary.getAttribute('role'), 'alert');
	assert.ok(await summary.evaluate((el) => el === document.activeElement));
	assert.ok(await summary.locator('a').count() >= 2);
	assert.equal(await form.locator('[name="email"]').getAttribute('aria-invalid'), 'true');
	await summary.locator('a').first().click();
	assert.ok(await form.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`).evaluate((el) => el === document.activeElement), 'link moves focus to the field');
}));
