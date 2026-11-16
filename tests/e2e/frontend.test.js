'use strict';
/*
 * Event page (audit R2, experts #7/#9): list → single-occurrence view, waitlist sign-up of a sold-out
 * ticket type, the public availability endpoint, and a checkout as signed-in user through the real
 * cookie + X-WP-Nonce REST path (customer_user_id for buyer limits per account).
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, installMuPlugin, fixture, helper, launch, context, login, withPage } = require('./lib');

let browser, ctx, fx;
test.before(async () => {
	installMuPlugin();
	fx = fixture();
	browser = await launch();
	ctx = await context(browser);
});
test.after(async () => { if (browser) await browser.close(); });
const page = (name, fn) => withPage(() => ctx, name, fn);

test('event list: compact rows without forms, "Tickets" opens the single-occurrence view with one form', page('list to detail', async (p) => {
	assert.ok(fx.events_list_url, 'fixture creates the list page');
	await p.goto(fx.events_list_url);
	assert.equal(await p.locator('.wpcap-checkout-form').count(), 0, 'no checkout form in the list');
	const link = p.locator(`#wpcap-occ-${fx.occurrence_id} .wpcap-tickets-link`);
	await Promise.all([p.waitForURL(new RegExp('wpcap_occ=' + fx.occurrence_id)), link.click()]);
	assert.equal(await p.locator('.wpcap-checkout-form').count(), 1, 'exactly the chosen date');
	assert.equal(await p.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`).count(), 1);
	await Promise.all([p.waitForURL((u) => !u.searchParams.has('wpcap_occ')), p.click('.wpcap-back a')]);
}));

test('sold-out ticket type: waitlist sign-up asks to confirm the e-mail link (double opt-in)', page('waitlist', async (p) => {
	await p.goto(fx.events_url);
	// Merged card markup (A456): one waitlist form per card below the checkout form; its ticket type
	// select lists only the sold-out types (hidden while there is just one).
	const card = p.locator('.wpcap-event').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types.Ausverkauft}"]`) });
	const form = card.locator('.wpcap-waitlist-form');
	await form.waitFor();
	assert.equal(await form.locator('[name="ticket_type_id"]').inputValue(), String(fx.ticket_types.Ausverkauft), 'the sold-out type is preselected');
	const email = 'e2e-wl-' + Date.now() + '@example.com';
	await form.locator('[name="email"]').fill(email);
	await form.locator('button[type="submit"]').click();
	await assert.doesNotReject(form.locator('.wpcap-message', { hasText: /Bestätigungslink|confirm/i }).waitFor());
	const entry = helper('waitlist', email).entry;
	assert.ok(entry, 'entry stored');
	assert.equal(entry.status, 'pending', 'counts only after the confirmation link (merged status name: pending)');
	assert.equal(Number(entry.ticket_type_id), fx.ticket_types.Ausverkauft);
}));

test('availability endpoint: public, 5 s cacheable, sold-out state per ticket type', async () => {
	// Merged namespace pass-captain/v1; the legacy wp-pass-captain/v1 alias answers the same (cached pages, old scripts).
	for (const ns of ['pass-captain/v1', 'wp-pass-captain/v1']) {
		const res = await ctx.request.get(BASE + '/?rest_route=/' + ns + '/availability&types=' + [fx.ticket_types.Standard, fx.ticket_types.Ausverkauft].join(','));
		assert.equal(res.status(), 200, ns);
		assert.match(res.headers()['cache-control'] || '', /max-age=5/, ns);
		assert.doesNotMatch(res.headers()['cache-control'] || '', /no-store|private/, ns);
		const data = await res.json();
		assert.equal(data.types[String(fx.ticket_types.Ausverkauft)].status, 'soldout');
		assert.equal(data.types[String(fx.ticket_types.Standard)].status, 'available');
	}
});

test('signed-in buyer: the checkout runs as that account (X-WP-Nonce over the cookie REST path)', async () => {
	const member = await context(browser);
	try {
		await login(member);
		const p = await member.newPage();
		await p.goto(fx.events_url);
		const form = p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`) });
		await form.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`).fill('1');
		const email = 'e2e-member-' + Date.now() + '@example.com';
		await form.locator('[name="email"]').fill(email);
		if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
		// The signed-in user's profile language decides the text (catalogs are complete): compare with the localized string.
		// Merged card: the button carries both localized labels (data-label-free / data-label-paid, A409).
		const submit = form.locator('button[type="submit"]');
		assert.equal(await submit.innerText(), await submit.getAttribute('data-label-free'));
		await form.locator('button[type="submit"]').click();
		await assert.doesNotReject(p.locator('.wpcap-confirmation').waitFor());
		const order = helper('buyer', email).order;
		assert.ok(Number(order.customer_user_id) > 0, 'customer_user_id is the signed-in account');
	} finally {
		await member.close();
	}
});
