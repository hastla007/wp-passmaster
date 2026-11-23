'use strict';
/*
 * A321: buyer pages under conditions closer to real use (headless Chromium/Firefox/WebKit):
 *  - reflow at 320 CSS px (WCAG 1.4.10; the same width as 1280 px at 400 % zoom): event page, two event
 *    shortcodes on one page, order portal, ticket page, service booking – no horizontal scrolling,
 *  - dark colour scheme and right-to-left direction: no overflow, axe colour contrast (when axe-core is
 *    installed in tests/a11y),
 *  - live regions: the estimate (aria-live) and the quantity announcement follow the stepper,
 *  - a non-JSON answer (proxy/WAF HTML page, 502) after the order reached the server: a readable error,
 *    the button usable, and sending again finishes the same order (no second order),
 *  - several shortcodes on one page: unique ids, every quantity field labelled, each form independent,
 *  - back/forward navigation after a confirmed order: no second order, the form is not left busy.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { BASE, BROWSER, installMuPlugin, fixture, helper, launch, context, withPage } = require('./lib');

let axePath = '';
for (const candidate of [path.join(__dirname, '..', 'a11y', 'node_modules', 'axe-core', 'axe.min.js'), path.join(__dirname, 'node_modules', 'axe-core', 'axe.min.js')]) {
	if (fs.existsSync(candidate)) { axePath = candidate; break; }
}
if (!axePath) { try { axePath = require.resolve('axe-core/axe.min.js'); } catch (e) { axePath = ''; } }

let browser, ctx, fx, twoCardsUrl;
/** Context with the e2e cookie: paid ticket types are only on sale with a payment method (the fake one, mu-plugin). */
async function buyerContext(opts) {
	const c = await context(browser, opts);
	await c.addCookies([{ name: 'wpcap_e2e', value: '1', url: BASE }]);
	return c;
}
test.before(async () => {
	installMuPlugin();
	fx = fixture();
	browser = await launch();
	ctx = await buyerContext();
	twoCardsUrl = helper('page', 'pc-a321-two-cards', '[wpcap_events]\n\n[wpcap_events]').url;
});
test.after(async () => { if (browser) await browser.close(); });
const page = (name, fn) => withPage(() => ctx, name, fn);

/** Horizontal overflow at the current viewport: [scrollWidth, clientWidth, widest elements]. */
async function overflow(p) {
	return p.evaluate(() => {
		const width = document.documentElement.clientWidth;
		const rtl = getComputedStyle(document.documentElement).direction === 'rtl';
		const wide = Array.from(document.querySelectorAll('body *')).filter((el) => {
			const r = el.getBoundingClientRect();
			if (!r.width || getComputedStyle(el).position === 'fixed') return false;
			return rtl ? r.left < -1 : r.right > width + 1;
		}).slice(0, 5).map((el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : ''));
		// Elements whose own content (e.g. an unbreakable word) is wider than their box.
		const over = Array.from(document.querySelectorAll('body *')).filter((el) => el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === 'visible').slice(0, 5).map((el) => el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '') + ' (content ' + el.scrollWidth + ' > ' + el.clientWidth + ' px)');
		return { scroll: document.documentElement.scrollWidth, width, wide: wide.concat(over) };
	});
}
async function assertReflow(p, what) {
	const r = await overflow(p);
	assert.ok(r.scroll <= r.width + 1, what + ': scrolls horizontally at ' + r.width + ' px (' + r.scroll + ' px): ' + r.wide.join(', '));
}
/** axe colour contrast (WCAG 1.4.3) of the page or a part of it; null when axe-core is not installed. */
async function contrast(p, include) {
	if (!axePath) return null;
	await p.addScriptTag({ path: axePath });
	return p.evaluate(async (inc) => {
		const r = await window.axe.run(inc && document.querySelector(inc) ? { include: [[inc]] } : document, { runOnly: { type: 'rule', values: ['color-contrast'] } });
		return r.violations.flatMap((v) => v.nodes.map((n) => n.target.join(' ') + ': ' + (n.failureSummary || '').split('\n').slice(-1)[0]));
	}, include || null);
}
function eventForm(p) {
	return p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`) }).first();
}

test('reflow at 320 CSS px (= 1280 px at 400 % zoom): buyer pages do not scroll horizontally', async () => {
	const narrow = await buyerContext({ viewport: { width: 320, height: 640 }, isMobile: false, hasTouch: false, deviceScaleFactor: 1 });
	try {
		const p = await narrow.newPage();
		const pages = [['event page', fx.events_url], ['two event shortcodes', twoCardsUrl], ['order portal', fx.portal_url], ['ticket page', fx.ticket_url], ['service booking', fx.service_url]];
		for (const [what, url] of pages) {
			if (!url) continue;
			await p.goto(url);
			await assertReflow(p, what);
		}
		// With an opened attendee block and an error summary (the longest checkout state).
		await p.goto(fx.events_url);
		const form = eventForm(p);
		await form.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`).fill('2');
		await form.locator('button[type="submit"]').click();
		await form.locator('.wpcap-error-summary').waitFor();
		await assertReflow(p, 'checkout with attendee fields and error summary');
	} finally {
		await narrow.close();
	}
});

test('dark colour scheme: event page and portal keep their contrast and layout', async (t) => {
	const dark = await buyerContext({ colorScheme: 'dark', bypassCSP: true });
	try {
		const p = await dark.newPage();
		for (const [what, url, part] of [['event page', fx.events_url, '.wpcap-event'], ['order portal', fx.portal_url, 'main']]) {
			if (!url) continue;
			await p.goto(url);
			assert.equal(await p.evaluate(() => matchMedia('(prefers-color-scheme: dark)').matches), true, 'the page sees the dark scheme');
			await assertReflow(p, what + ' (dark)');
			const issues = await contrast(p, part);
			if (null === issues) { t.diagnostic('axe-core not installed (npm ci in tests/a11y): contrast not checked'); continue; }
			assert.deepEqual(issues, [], what + ': colour contrast in dark mode');
		}
	} finally {
		await dark.close();
	}
});

test('right-to-left direction: checkout fits at 320 px and the stepper still works', async () => {
	const rtl = await buyerContext({ viewport: { width: 320, height: 640 }, locale: 'ar' });
	try {
		await rtl.addInitScript(() => { document.addEventListener('DOMContentLoaded', () => { document.documentElement.setAttribute('dir', 'rtl'); document.documentElement.setAttribute('lang', 'ar'); }); });
		const p = await rtl.newPage();
		await p.goto(fx.events_url);
		assert.equal(await p.evaluate(() => getComputedStyle(document.documentElement).direction), 'rtl');
		await assertReflow(p, 'event page (rtl)');
		const form = eventForm(p);
		const qty = form.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`);
		const up = form.locator(`.wpcap-stepper:has(.wpcap-qty[data-type="${fx.ticket_types.Standard}"]) .wpcap-step-up`);
		assert.equal(await up.count(), 1, 'the quantity field has a stepper');
		await up.click();
		assert.equal(await qty.inputValue(), '1', 'the "+" button raises the quantity in rtl too');
	} finally {
		await rtl.close();
	}
});

test('live regions: estimate and quantity announcement follow the stepper', page('live regions', async (p) => {
	await p.goto(fx.events_url);
	const form = eventForm(p);
	const estimate = form.locator('.wpcap-estimate');
	assert.equal(await estimate.getAttribute('aria-live'), 'polite', 'the estimate is a polite live region');
	const up = form.locator(`.wpcap-stepper:has(.wpcap-qty[data-type="${fx.ticket_types.Standard}"]) .wpcap-step-up`);
	assert.equal(await up.count(), 1, 'the quantity field has a stepper');
	await up.click();
	await assert.doesNotReject(p.waitForFunction((el) => /25[,.]00/.test(el.textContent), await estimate.elementHandle()));
	const live = form.locator('.wpcap-qty-live');
	if (await live.count()) {
		assert.equal(await live.getAttribute('aria-live'), 'polite');
		await assert.doesNotReject(p.waitForFunction((el) => el.textContent.trim() !== '', await live.elementHandle(), { timeout: 5000 }));
	}
	await up.click();
	await assert.doesNotReject(p.waitForFunction((el) => /50[,.]00/.test(el.textContent), await estimate.elementHandle()));
	// Focus stays on the stepper button (keyboard users are not thrown back to the top).
	await up.focus();
	await p.keyboard.press('Enter');
	assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('wpcap-step-up')), true, 'focus stays on "+"');
}));

test('non-JSON answer (proxy HTML 502) after the order reached the server: readable error, retry finishes the same order', page('non-json answer', async (p) => {
	const errors = [];
	p.on('pageerror', (e) => errors.push(e.message));
	await p.goto(fx.events_url);
	const form = p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`) }).first();
	await form.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`).fill('1');
	const email = 'e2e-a321-' + BROWSER + '-' + Date.now() + '@example.com';
	await form.locator('[name="email"]').fill(email);
	if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
	// The request reaches WordPress (the order is created), but a proxy replaces the answer with an HTML page.
	let swallowed = 0;
	await p.route(/checkout(?!\/options)/, async (route) => {
		if ('POST' !== route.request().method()) return route.continue();
		await route.fetch();
		swallowed++;
		return route.fulfill({ status: 502, contentType: 'text/html', body: '<html><body><h1>502 Bad Gateway</h1></body></html>' });
	});
	await form.locator('button[type="submit"]').click();
	const summary = form.locator('.wpcap-error-summary');
	await summary.waitFor();
	assert.equal(swallowed, 1, 'exactly one request was sent');
	assert.ok((await summary.innerText()).trim().length > 10, 'a readable message, not the HTML of the proxy');
	assert.doesNotMatch(await summary.innerText(), /Bad Gateway|<html/i);
	assert.equal(await form.locator('button[type="submit"]').isDisabled(), false, 'the button can be used again');
	assert.equal(helper('orders-count', email).count, 1, 'the order exists on the server');
	await p.unroute(/checkout(?!\/options)/);
	await form.locator('button[type="submit"]').click();
	await assert.doesNotReject(p.locator('.wpcap-confirmation').waitFor());
	assert.equal(helper('orders-count', email).count, 1, 'sending again continued the same order (idempotency key kept)');
	assert.deepEqual(errors, [], 'no script errors');
}));

test('two event shortcodes on one page: unique ids, labelled quantity fields, independent forms', page('two shortcodes', async (p) => {
	await p.goto(twoCardsUrl);
	const dupes = await p.evaluate(() => {
		const seen = {};
		document.querySelectorAll('[id]').forEach((el) => { seen[el.id] = (seen[el.id] || 0) + 1; });
		return Object.keys(seen).filter((id) => seen[id] > 1);
	});
	assert.deepEqual(dupes, [], 'no duplicate ids');
	const unlabelled = await p.evaluate(() => Array.from(document.querySelectorAll('.wpcap-qty')).filter((input) => !(input.labels && input.labels.length) && !input.getAttribute('aria-label') && !input.getAttribute('aria-labelledby')).length);
	assert.equal(unlabelled, 0, 'every quantity field has a label');
	const forms = p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`) });
	assert.ok(await forms.count() >= 2, 'the event appears in both shortcodes');
	await forms.nth(0).locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`).fill('1');
	await forms.nth(0).locator(`.wpcap-qty[data-type="${fx.ticket_types.Standard}"]`).dispatchEvent('input');
	assert.match(await forms.nth(0).locator('.wpcap-estimate').innerText(), /25[,.]00/);
	assert.equal((await forms.nth(1).locator('.wpcap-estimate').innerText()).trim(), '', 'the second form is not affected');
}));

test('back/forward after a confirmed order: no second order, the form is not left busy', page('back forward', async (p) => {
	await p.goto(fx.events_url);
	const form = p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`) }).first();
	await form.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`).fill('1');
	const email = 'e2e-a321-bf-' + BROWSER + '-' + Date.now() + '@example.com';
	await form.locator('[name="email"]').fill(email);
	if (await form.locator('[name="accept_terms"]').count()) await form.locator('[name="accept_terms"]').check();
	await form.locator('button[type="submit"]').click();
	await p.locator('.wpcap-confirmation').waitFor();
	await p.goto(BASE + '/wp-login.php');
	await p.goBack();
	await p.waitForLoadState('load');
	const again = p.locator('.wpcap-checkout-form').filter({ has: p.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`) }).first();
	const confirmation = await p.locator('.wpcap-confirmation').count();
	if (!confirmation) {
		assert.equal(await again.getAttribute('data-busy'), null, 'the restored form is not busy');
	}
	assert.equal(helper('orders-count', email).count, 1, 'coming back created no second order');
}));
