'use strict';
/*
 * A321 (part 2): the remaining buyer-facing widgets under conditions closer to real use, in every engine:
 *  - order portal: the page "Tickets/Bestelllink anfordern" (keyboard only, 320 px, slow network, the same
 *    answer for every address, trap field out of the tab order), the page of an expired link (404, request
 *    form, dark colour scheme), the .ics download (keyboard only, no ticket codes in the file) and the
 *    cancellation request (keyboard confirm dialog, lost connection with a readable message, slow network
 *    without a second request),
 *  - waitlist sign-up on a sold-out card: keyboard only at 320 px, lost connection, one entry,
 *  - seat map: arrow keys + Space with the spoken status, an order with the chosen seats; at 320 px no
 *    sideways scrolling and the list view selects the same seats,
 *  - checkout bot check (Security\FormGuard): the trap field is hidden from assistive technology and the
 *    keyboard; a form sent faster than the minimum fill time gets a readable hint and the next submit
 *    goes through (one order),
 *  - web scanner result screen: 320 px, keyboard only, reduced motion, slow network (busy state), lost
 *    connection (readable offline result, "admit after visual check" reachable by keyboard).
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const { BASE, BROWSER, installMuPlugin, fixture, helper, launch, context, login, withPage, assertReflow, contrast, narrowOptions, slowNetwork, focused, tabTo } = require('./lib');

/** Browser texts of a failed fetch: a buyer must never see them. */
const RAW_NETWORK_ERROR = /Failed to fetch|NetworkError|Load failed|fetch|TypeError/i;

let browser, ctx, fx;
test.before(async () => {
	installMuPlugin();
	fx = fixture();
	browser = await launch();
	ctx = await context(browser);
});
test.after(async () => { if (browser) await browser.close(); });
const page = (name, fn) => withPage(() => ctx, name, fn);
/** Runs fn with a page of a fresh context (own options, e.g. 320 px or offline) and closes it. */
async function inContext(opts, name, fn) {
	const c = await context(browser, opts);
	try {
		await withPage(() => c, name, fn)();
	} finally {
		await c.close();
	}
}

/* ------------------------------------------------------------------ Order portal */

test('order link request page: keyboard only at 320 px, slow network, the same answer for every address', () => inContext(narrowOptions(), 'order link request', async (p) => {
	const url = BASE + '/?wpcap_order_link=1';
	await p.goto(url);
	await assertReflow(p, 'order link request page');
	// Trap field (bots): kept out of the accessibility tree and the tab order.
	const trap = p.locator('.wpcap-order-link-request [name="website"]');
	assert.equal(await trap.count(), 1);
	assert.equal(await trap.getAttribute('tabindex'), '-1');
	assert.equal(await p.locator('.wpcap-order-link-request .hp').getAttribute('aria-hidden'), 'true');
	assert.equal(await p.getByRole('textbox', { name: 'Website' }).count(), 0, 'trap field not exposed to assistive technology');
	await tabTo(p, '#wpcap-order-link-email');
	await p.keyboard.type('a11y-buyer@example.com');
	// Slow network: the form posts normally (no script), the browser keeps the page until the answer.
	const fast = await slowNetwork(p, (u) => u.searchParams.get('wpcap_order_link') === '1', 1200);
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
	await fast();
	const notice = p.locator('main .notice[role="status"]');
	await notice.waitFor();
	const known = (await notice.innerText()).trim();
	assert.ok(known.length > 20, 'a readable answer: ' + known);
	assert.equal(await trap.inputValue(), '', 'the trap field stays empty');
	await assertReflow(p, 'order link request answer');
	// An address without orders gets exactly the same answer (no account enumeration).
	await tabTo(p, '#wpcap-order-link-email');
	await p.keyboard.type('nobody-' + Date.now() + '@example.com');
	await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
	assert.equal((await p.locator('main .notice[role="status"]').innerText()).trim(), known);
}));

test('expired order link: 404 page with explanation and request form, fits 320 px, dark colour scheme', () => inContext(narrowOptions({ colorScheme: 'dark' }), 'expired order link', async (p, t) => {
	assert.ok(fx.portal_url, 'fixture portal link');
	const expired = fx.portal_url.replace(/(wpcap_access=)[a-f0-9]+/, (m, k) => k + '0'.repeat(64));
	const response = await p.goto(expired);
	assert.equal(response.status(), 404, 'an expired link is no page of its own');
	assert.equal(await p.locator('meta[name="robots"][content*="noindex"]').count() >= 1, true, 'not indexed');
	assert.equal(await p.locator('h1').count(), 1);
	assert.ok((await p.locator('main p').first().innerText()).length > 40, 'explains why the link no longer works');
	assert.equal(await p.locator('form.wpcap-order-link-request').count(), 1, 'offers the request form at once');
	assert.equal(await p.locator('body').innerText().then((s) => s.includes(fx.portal_url.split('wpcap_access=')[1])), false, 'the token is not echoed');
	await assertReflow(p, 'expired link page');
	await tabTo(p, '#wpcap-order-link-email');
	const issues = await contrast(p, 'main');
	if (null === issues) t.diagnostic('axe-core not installed (npm ci in tests/a11y): contrast not checked');
	else assert.deepEqual(issues, [], 'colour contrast in dark mode');
}));

test('portal .ics download: keyboard only, a calendar file without ticket codes or the access token', page('portal ics', async (p) => {
	const order = helper('paid-order', 1);
	await p.goto(order.portal_url);
	const button = '.wpcap-ics-form button[type="submit"]';
	assert.equal(await p.locator(button).count(), 1, 'calendar card for a paid order with an upcoming date');
	await tabTo(p, button);
	const [download] = await Promise.all([p.waitForEvent('download'), p.keyboard.press('Enter')]);
	assert.match(download.suggestedFilename(), /\.ics$/);
	const file = await download.path();
	const body = require('fs').readFileSync(file, 'utf8');
	assert.match(body, /^BEGIN:VCALENDAR/);
	assert.match(body, /BEGIN:VEVENT[\s\S]*Barrierefreiheit Testkonzert[\s\S]*END:VEVENT/);
	assert.doesNotMatch(body, /wpcap_ticket=|wpcap_access=/, 'no ticket or portal links in the file');
	assert.ok(!body.includes(order.portal_url.split('wpcap_access=')[1]), 'no access token');
	assert.equal(new URL(p.url()).searchParams.get('wpcap_order'), order.public_id, 'the portal stays open');
}));

test('cancellation request: keyboard confirm dialog, lost connection with a readable message, slow network sends once', page('portal refund request', async (p) => {
	const order = helper('paid-order', 2);
	await p.goto(order.portal_url);
	const s = await p.evaluate(() => window.wpcapPortal.strings);
	const form = p.locator('.wpcap-refund-form');
	const message = form.locator('.message');
	const submit = '.wpcap-refund-form button[type="submit"]';
	// Nothing chosen: a hint, no dialog.
	await tabTo(p, submit);
	await p.keyboard.press('Enter');
	await p.waitForFunction((t) => document.querySelector('.wpcap-refund-form .message').textContent === t, s.refundNone);
	// Keyboard: choose the first ticket, the dialog opens with focus on "cancel", Escape changes nothing.
	await tabTo(p, '.wpcap-refund-form input[type="checkbox"]');
	await p.keyboard.press('Space');
	assert.equal(await form.locator('input[type="checkbox"]').first().isChecked(), true);
	await tabTo(p, submit);
	await p.keyboard.press('Enter');
	const dialog = p.locator('#wpcap-confirm');
	await dialog.waitFor({ state: 'visible' });
	assert.equal(await p.locator('#wpcap-confirm-text').innerText(), s.refundConfirm);
	assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.value), 'cancel', 'the safe choice has the focus');
	await p.keyboard.press('Escape');
	await dialog.waitFor({ state: 'hidden' });
	assert.equal(await focused(p), 'button', 'focus returns to the submit button');
	assert.equal(helper('refunds', order.public_id).refunds.length, 0, 'cancelled in the dialog: nothing sent');

	// Lost connection: the form's own message (not the browser's "Failed to fetch"), the form stays usable.
	await p.context().setOffline(true);
	try {
		await p.keyboard.press('Enter');
		await dialog.waitFor({ state: 'visible' });
		await p.locator('#wpcap-confirm-ok').focus();
		await p.keyboard.press('Enter');
		await p.waitForFunction((none) => { const t = document.querySelector('.wpcap-refund-form .message').textContent.trim(); return t !== '' && t !== none; }, s.refundNone);
		const text = await message.innerText();
		assert.doesNotMatch(text, RAW_NETWORK_ERROR, 'readable message offline: ' + text);
		assert.equal(text, s.refundFailed);
		assert.equal(await p.locator(submit).isDisabled(), false, 'the button can be used again');
		assert.equal(await focused(p), 'button', 'focus back on the button');
	} finally {
		await p.context().setOffline(false);
	}

	// Slow network: the button is disabled while the request runs, a second press sends nothing.
	const fast = await slowNetwork(p, /refund-request/, 1500);
	let sent = 0;
	p.on('request', (r) => { if (/refund-request/.test(r.url()) && r.method() === 'POST') sent++; });
	await p.keyboard.press('Enter');
	await dialog.waitFor({ state: 'visible' });
	await p.locator('#wpcap-confirm-ok').click();
	await p.waitForFunction((sel) => document.querySelector(sel) === null || document.querySelector(sel).disabled, submit);
	await p.locator(submit).click({ timeout: 500 }).catch(() => {}); // disabled (or removed): no effect
	await p.waitForFunction(() => !document.querySelector('.wpcap-refund-form button[type="submit"]') && document.querySelector('.wpcap-refund-form .message').textContent.trim() !== '', null, { timeout: 15000 });
	await fast();
	assert.equal(sent, 1, 'one request');
	assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('message')), true, 'focus on the confirmation (the button is gone)');
	const refunds = helper('refunds', order.public_id).refunds;
	assert.equal(refunds.length, 1, 'one cancellation request');
	assert.equal(refunds[0].status, 'pending_approval');
}));

/* ------------------------------------------------------------------ Waitlist */

test('waitlist on a sold-out card: keyboard only at 320 px, lost connection, joined once', () => inContext(narrowOptions(), 'waitlist conditions', async (p) => {
	await p.goto(fx.events_url);
	await assertReflow(p, 'event page with waitlist');
	const card = p.locator('.wpcap-event').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types.Ausverkauft}"]`) });
	const form = card.locator('.wpcap-waitlist-form');
	await form.waitFor();
	const message = form.locator('.wpcap-message');
	await form.evaluate((el) => el.setAttribute('data-e2e-target', '1')); // several cards may carry a waitlist form
	await tabTo(p, '[data-e2e-target] [name="email"]');
	const email = 'e2e-wl-cond-' + BROWSER + '-' + Date.now() + '@example.com';
	await p.keyboard.type(email);
	await p.context().setOffline(true);
	try {
		await p.keyboard.press('Enter');
		await p.waitForFunction((el) => el.textContent.trim() !== '' && !el.closest('form').querySelector('button[type="submit"]').disabled, await message.elementHandle());
		const text = await message.innerText();
		assert.doesNotMatch(text, RAW_NETWORK_ERROR, 'readable message offline: ' + text);
		assert.notEqual(await focused(p), 'body', 'the keyboard focus is not lost (Enter tries again)');
	} finally {
		await p.context().setOffline(false);
	}
	await assertReflow(p, 'waitlist with error message');
	await p.keyboard.press('Enter');
	// Joined: every control of the form is disabled (while sending, only the button is).
	await p.waitForFunction((el) => el.closest('form').querySelector('[name="email"]').disabled, await message.elementHandle(), { timeout: 15000 });
	assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('wpcap-message')), true, 'focus moves to the confirmation');
	const entry = helper('waitlist', email).entry;
	assert.ok(entry, 'entry stored once the connection is back');
	await assertReflow(p, 'waitlist confirmation');
}));

/* ------------------------------------------------------------------ Seat map */

/** Waits until the seat picker of the page has loaded its plan. */
async function seatPicker(p) {
	await p.locator('.wpcap-seating .wpcap-seat').first().waitFor();
	return p.evaluate(() => JSON.parse(document.querySelector('.wpcap-seating').dataset.i18n));
}

test('seat map: arrow keys and Space select seats, the status speaks, the order keeps the seats', page('seat map keyboard', async (p) => {
	const seated = helper('seated');
	await p.goto(seated.url);
	const form = p.locator('.wpcap-checkout-form').first();
	await form.locator(`.wpcap-qty[data-type="${seated.ticket_type_id}"]`).fill('2');
	await form.locator(`.wpcap-qty[data-type="${seated.ticket_type_id}"]`).dispatchEvent('input');
	const t = await seatPicker(p);
	const status = p.locator('.wpcap-seating-status');
	assert.equal(await status.getAttribute('role'), 'status');
	await tabTo(p, '.wpcap-seat[tabindex="0"]');
	const first = await p.evaluate(() => document.activeElement.getAttribute('data-id'));
	await p.keyboard.press('Space');
	await p.waitForFunction((id) => document.querySelector('.wpcap-seat[data-id="' + id + '"]').getAttribute('aria-checked') === 'true', first);
	await p.waitForFunction((txt) => document.querySelector('.wpcap-seating-status').textContent.includes(txt), t.added.replace('%s', '').trim());
	await p.keyboard.press('ArrowRight');
	const second = await p.evaluate(() => document.activeElement.getAttribute('data-id'));
	assert.notEqual(second, first, 'ArrowRight moves to the next seat');
	assert.equal(await p.evaluate(() => document.activeElement.getAttribute('tabindex')), '0', 'roving tabindex follows the focus');
	await p.keyboard.press('Enter');
	await p.waitForFunction((id) => document.querySelector('.wpcap-seat[data-id="' + id + '"]').getAttribute('aria-checked') === 'true', second);
	// Space again on the same seat removes it, a third key adds it back (no scrolling of the page by Space).
	const y = await p.evaluate(() => window.scrollY);
	await p.keyboard.press('Space');
	await p.waitForFunction((id) => document.querySelector('.wpcap-seat[data-id="' + id + '"]').getAttribute('aria-checked') === 'false', second);
	await p.waitForFunction((txt) => document.querySelector('.wpcap-seating-status').textContent.includes(txt), t.removed.replace('%s', '').trim());
	assert.equal(await p.evaluate(() => window.scrollY), y, 'Space does not scroll the page');
	await p.keyboard.press('Space');
	assert.doesNotMatch(await p.locator('.wpcap-seating-zoom-level').innerText(), /%%/, 'zoom level shows one percent sign');

	await form.locator('[name="email"]').fill('e2e-seats-' + BROWSER + '-' + Date.now() + '@example.com');
	if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
	await form.locator('button[type="submit"]').click();
	await p.locator('.wpcap-confirmation').waitFor({ timeout: 20000 });
	// The chosen seats are taken now (a new visit shows them as taken).
	await p.goto(seated.url);
	await seatPicker(p);
	for (const id of [first, second]) {
		assert.equal(await p.locator(`.wpcap-seat[data-id="${id}"]`).getAttribute('aria-disabled'), 'true', 'seat ' + id + ' is taken after the order');
	}
}));

test('seat map at 320 px: no sideways page scrolling, the list view selects the same seats', () => inContext(narrowOptions(), 'seat map narrow', async (p) => {
	const seated = helper('seated');
	await p.goto(seated.url);
	const form = p.locator('.wpcap-checkout-form').first();
	await form.locator(`.wpcap-qty[data-type="${seated.ticket_type_id}"]`).fill('1');
	await form.locator(`.wpcap-qty[data-type="${seated.ticket_type_id}"]`).dispatchEvent('input');
	await seatPicker(p);
	await assertReflow(p, 'seat map at 320 px');
	// The plan may be wider than the screen: it scrolls inside its own box, not the page.
	const map = await p.evaluate(() => { const m = document.querySelector('.wpcap-seating-map'); return { overflow: getComputedStyle(m).overflowX, scroll: m.scrollWidth, width: m.clientWidth }; });
	if (map.scroll > map.width + 1) assert.ok(['auto', 'scroll'].includes(map.overflow), 'a wide plan scrolls in its box (' + map.overflow + ')');
	await tabTo(p, '.wpcap-seating-view[data-view="list"]');
	await p.keyboard.press('Enter');
	assert.equal(await p.locator('.wpcap-seating-view[data-view="list"]').getAttribute('aria-pressed'), 'true');
	const box = p.locator('.wpcap-seating input[type="checkbox"]:not([disabled])').first();
	await box.waitFor({ state: 'visible' });
	await box.focus();
	await p.keyboard.press('Space');
	const id = await box.getAttribute('value');
	assert.equal(await p.locator(`.wpcap-seat[data-id="${id}"]`).getAttribute('aria-checked'), 'true', 'the plan follows the list');
	await assertReflow(p, 'seat list at 320 px');
}));

/* ------------------------------------------------------------------ Checkout bot check */

test('checkout bot check: trap field hidden from assistive tech and keyboard; too fast → readable hint, the next submit orders once', async () => {
	const guarded = await context(browser);
	try {
		// The real minimum fill time (the mu-plugin switches it off for the other tests).
		await guarded.addCookies([{ name: 'wpcap_e2e_guard', value: '1', url: BASE }]);
		await withPage(() => guarded, 'form guard', async (p) => {
			await p.goto(fx.events_url);
			const loaded = Date.now();
			const type = fx.ticket_types['Freier Eintritt'];
			const form = p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${type}"]`) }).first();
			const trapBox = form.locator('.wpcap-hp');
			assert.equal(await trapBox.count(), 1, 'the form carries the trap field');
			assert.equal(await trapBox.getAttribute('aria-hidden'), 'true');
			assert.equal(await form.locator('[name="wpcap_hp"]').isVisible(), false, 'not displayed');
			assert.equal(await form.locator('[name="wpcap_hp"]').getAttribute('tabindex'), '-1');
			const label = (await trapBox.locator('label').textContent()).trim();
			assert.doesNotMatch(await form.ariaSnapshot(), new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), 'not in the accessibility tree');
			// Keyboard: tabbing through the whole form never lands on the trap field.
			const seen = [];
			await p.locator(`.wpcap-qty[data-type="${type}"]`).focus();
			for (let i = 0; i < 40; i++) {
				await p.keyboard.press('Tab');
				seen.push(await p.evaluate(() => document.activeElement && (document.activeElement.name || document.activeElement.className || document.activeElement.tagName)));
				if (await p.evaluate((sel) => document.activeElement === document.querySelector(sel), '.wpcap-checkout-form button[type="submit"]')) break;
			}
			assert.ok(!seen.includes('wpcap_hp'), 'trap field skipped by Tab: ' + seen.join(', '));

			// Sent within the minimum fill time (3 s): a readable hint, nothing ordered, the button stays usable.
			await form.locator(`.wpcap-qty[data-type="${type}"]`).fill('1');
			const email = 'e2e-guard-' + BROWSER + '-' + Date.now() + '@example.com';
			await form.locator('[name="email"]').fill(email);
			if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
			assert.ok(Date.now() - loaded < 2500, 'the first submit happens within the minimum fill time');
			await form.locator('button[type="submit"]').click();
			const summary = form.locator('.wpcap-error-summary');
			await summary.waitFor();
			const text = await summary.innerText();
			assert.ok(text.trim().length > 20, 'a readable hint: ' + text);
			assert.doesNotMatch(text, /wpcap_|form_too_fast|form_guard/, 'no internal codes');
			assert.equal(await form.locator('button[type="submit"]').isDisabled(), false);
			assert.equal(helper('orders-count', email).count, 0, 'nothing ordered');
			// A person who reads the hint and sends again is let through (the timer kept running).
			const wait = 3300 - (Date.now() - loaded);
			if (wait > 0) await p.waitForTimeout(wait);
			await form.locator('button[type="submit"]').click();
			await p.locator('.wpcap-confirmation').waitFor({ timeout: 20000 });
			assert.equal(helper('orders-count', email).count, 1, 'one order');
		})();
	} finally {
		await guarded.close();
	}
});

/* ------------------------------------------------------------------ Web scanner result screen */

test('web scanner result screen: 320 px, keyboard only, reduced motion, slow and lost network', async () => {
	const door = helper('today', 2);
	const staff = await context(browser, narrowOptions({ reducedMotion: 'reduce' }));
	try {
		await login(staff);
		await withPage(() => staff, 'scanner result conditions', async (p) => {
			await p.goto(fx.scanner_url);
			await p.waitForFunction(() => window.WPCAP_SCANNER && window.WPCAP_SCANNER.i18n, null, { timeout: 15000 });
			const i18n = await p.evaluate(() => window.WPCAP_SCANNER.i18n);
			await assertReflow(p, 'scanner at 320 px');
			const result = p.locator('.wpcap-scan-result');
			const overlay = p.locator('.wpcap-scan-overlay');
			await tabTo(p, '.wpcap-scan-form [name="token"]');

			// Slow network: a visible busy state while the server answers, then the verdict.
			const fast = await slowNetwork(p, /check-?in/, 1500);
			await p.keyboard.type(door.codes[0]);
			await p.keyboard.press('Enter');
			await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').dataset.state === 'busy');
			await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').dataset.state === 'success', null, { timeout: 15000 });
			await fast();
			assert.ok((await p.locator('.wpcap-scan-live').innerText()).trim().length > 0, 'the verdict is announced');
			if (await overlay.count()) {
				await overlay.waitFor({ state: 'visible' });
				assert.equal(await overlay.getAttribute('aria-hidden'), 'true', 'the overlay repeats the live region and is hidden from screen readers');
				const box = await overlay.evaluate((el) => ({ scroll: el.scrollWidth, width: el.clientWidth }));
				assert.ok(box.scroll <= box.width + 1, 'result screen text fits 320 px');
				assert.equal(await p.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running' && a.effect && a.effect.getTiming().duration > 50).length), 0, 'no running animation with reduced motion');
				// Any key closes it; the keyboard user stays in the code field for the next guest.
				await p.keyboard.press('Escape');
				await overlay.waitFor({ state: 'hidden' });
			}
			assert.equal(await focused(p), 'input[name=token]', 'focus stays in the code field');
			assert.equal(helper('ticket', door.codes[0]).status, 'checked_in');
			await assertReflow(p, 'scanner result at 320 px');

			// Lost connection: a readable offline result, "admit after visual check" reachable by keyboard.
			await p.waitForTimeout(1500); // scanner lock after a scan
			await staff.setOffline(true);
			try {
				await p.keyboard.type(door.codes[1]);
				await p.keyboard.press('Enter');
				await p.waitForFunction(() => document.querySelector('.wpcap-scan-result').dataset.state === 'warning');
				const text = await result.locator('.wpcap-scan-text').innerText();
				assert.ok(text.includes(i18n.unchecked), 'offline result says the ticket was not checked: ' + text);
				if (await overlay.isVisible()) { await p.keyboard.press('Escape'); await overlay.waitFor({ state: 'hidden' }); }
				await tabTo(p, '.wpcap-admit-unchecked', 10);
				await p.keyboard.press('Enter');
				await p.waitForFunction(() => !document.querySelector('.wpcap-admit-unchecked'), null, { timeout: 5000 });
				await assertReflow(p, 'scanner offline result at 320 px');
			} finally {
				await staff.setOffline(false);
			}
			// Back online: the queued admission is uploaded.
			for (let i = 0; i < 30 && helper('ticket', door.codes[1]).status !== 'checked_in'; i++) await p.waitForTimeout(1000);
			assert.equal(helper('ticket', door.codes[1]).status, 'checked_in', 'queued offline admission uploaded after reconnecting');
		})();
	} finally {
		await staff.close();
	}
});
