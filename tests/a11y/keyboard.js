'use strict';
/*
 * Keyboard-only walkthrough of the public checkout (and the scanner form):
 * tab order, visible focus, accessible names, validation and live-region announcements.
 *
 *   WPCAP_A11Y_WP_PATH=/path/to/wp node keyboard.js
 *
 * Exit code 1 when a check fails.
 */
const { fixture, launch, login } = require('./lib');

let failures = 0;
function check(name, ok, detail) {
	console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ' (' + detail + ')' : ''}`);
	if (!ok) failures++;
}

/** Describes the focused element: accessible name, visible focus indicator, DOM position inside `scope`. */
async function focused(page, scope) {
	return page.evaluate((sel) => {
		const el = document.activeElement;
		if (!el || el === document.body) return null;
		const root = document.querySelector(sel);
		const inside = !!(root && root.contains(el));
		const cs = getComputedStyle(el);
		const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
		const shadow = cs.boxShadow && cs.boxShadow !== 'none';
		let name = el.getAttribute('aria-label') || '';
		if (!name && el.labels && el.labels.length) name = Array.from(el.labels).map((l) => l.textContent).join(' ');
		if (!name && el.getAttribute('aria-labelledby')) name = el.getAttribute('aria-labelledby').split(' ').map((id) => (document.getElementById(id) || {}).textContent || '').join(' ');
		if (!name && (el.tagName === 'BUTTON' || el.tagName === 'SUMMARY' || el.tagName === 'A')) name = el.textContent;
		const all = root ? Array.from(root.querySelectorAll('*')) : [];
		return {
			inside,
			tag: el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : ''),
			field: el.name || el.dataset.key || el.dataset.type || '',
			name: name.replace(/\s+/g, ' ').trim(),
			focusVisible: outline || shadow,
			matchesFocusVisible: el.matches(':focus-visible'),
			index: all.indexOf(el),
			validation: el.validationMessage || '',
		};
	}, scope);
}

async function tabUntil(page, scope, predicate, max = 300) {
	for (let i = 0; i < max; i++) {
		await page.keyboard.press('Tab');
		const f = await focused(page, scope);
		if (f && f.inside && predicate(f)) return f;
	}
	return null;
}

(async () => {
	const fx = fixture();
	const { browser, ctx } = await launch();
	const page = await ctx.newPage();
	const freeId = String(fx.ticket_types['Freier Eintritt']);
	const stdId = String(fx.ticket_types.Standard);

	console.log('Checkout: tab order and focus visibility');
	await page.goto(fx.events_url, { waitUntil: 'load' });
	// Scope = the article of the test event.
	await page.evaluate((id) => { document.querySelector(`.wpcap-qty[data-type="${id}"]`).closest('article').setAttribute('data-a11y-scope', '1'); }, stdId);
	const scope = '[data-a11y-scope="1"]';
	const first = await tabUntil(page, scope, () => true);
	check('the event form is reachable with Tab', !!first);
	const stops = [first];
	for (let i = 0; i < 40; i++) {
		await page.keyboard.press('Tab');
		const f = await focused(page, scope);
		if (!f || !f.inside) break;
		stops.push(f);
	}
	console.log('    Tab stops: ' + stops.map((s) => `${s.tag}${s.field ? ':' + s.field : ''} "${s.name.slice(0, 40)}"`).join('\n               '));
	check('every tab stop has an accessible name', stops.every((s) => s.name), stops.filter((s) => !s.name).map((s) => s.tag + ':' + s.field).join(', '));
	check('every tab stop shows a visible focus indicator', stops.every((s) => s.focusVisible), stops.filter((s) => !s.focusVisible).map((s) => s.tag + ':' + s.field).join(', '));
	check('tab order follows the visual/DOM order', stops.every((s, i) => i === 0 || s.index > stops[i - 1].index));
	check('sold-out ticket type is not a tab stop', !stops.some((s) => s.field === String(fx.ticket_types.Ausverkauft)));
	const submit = stops.find((s) => s.tag.startsWith('button'));
	check('submit button is reachable', !!submit, submit && submit.name);

	console.log('Checkout: empty submit is announced');
	// Focus is on the element after the form now; go back to the submit button with Shift+Tab.
	await page.keyboard.press('Shift+Tab');
	let f = await focused(page, scope);
	check('Shift+Tab returns to the submit button', f && f.tag.startsWith('button'), f && f.tag);
	await page.keyboard.press('Enter');
	await page.waitForTimeout(200);
	const live = await page.evaluate((sel) => {
		const m = document.querySelector(sel + ' .wpcap-message');
		return { text: m.textContent.trim(), role: m.getAttribute('role'), live: m.getAttribute('aria-live') };
	}, scope);
	check('error text is shown', live.text.length > 0, live.text);
	check('error text is in a polite live region (role=status)', live.role === 'status' && live.live === 'polite');

	console.log('Checkout: dynamic attendee fields and native validation');
	await page.goto(fx.events_url, { waitUntil: 'load' });
	await page.evaluate((id) => { document.querySelector(`.wpcap-qty[data-type="${id}"]`).closest('article').setAttribute('data-a11y-scope', '1'); }, stdId);
	f = await tabUntil(page, scope, (s) => s.field === freeId);
	check('free ticket quantity is reachable', !!f);
	await page.keyboard.press('ArrowUp');
	await page.waitForTimeout(100);
	const attendees = await page.evaluate((sel) => document.querySelectorAll(sel + ' .wpcap-attendee').length, scope);
	check('ArrowUp on the quantity adds an attendee fieldset', attendees === 1, String(attendees));
	await page.keyboard.press('Tab');
	f = await focused(page, scope);
	check('next Tab moves into the new attendee fields', f && f.field === '__name', f && `${f.tag}:${f.field}`);
	check('attendee name field is labelled', f && /Name/.test(f.name), f && f.name);
	const group = await page.evaluate((sel) => { const fs = document.querySelector(sel + ' .wpcap-attendee'); return fs && fs.querySelector('legend') ? fs.querySelector('legend').textContent : ''; }, scope);
	check('attendee fieldset has a legend', !!group, group);
	await page.keyboard.type('Alex Muster');
	f = await tabUntil(page, scope, (s) => s.field === 'email', 20);
	check('order e-mail field is reachable and labelled', f && /E-Mail/.test(f.name), f && f.name);
	await page.keyboard.type('keine-adresse');
	await page.keyboard.press('Enter');
	await page.waitForTimeout(200);
	f = await focused(page, scope);
	check('invalid e-mail: focus moves to the field with a validation message', f && f.field === 'email' && f.validation, f && f.validation);
	await page.keyboard.press('ControlOrMeta+A');
	await page.keyboard.type('keyboard-' + Date.now() + '@example.com');
	const terms = await page.evaluate((sel) => !!document.querySelector(sel + ' [name="accept_terms"]'), scope);
	if (terms) {
		await page.keyboard.press('Enter');
		await page.waitForTimeout(200);
		f = await focused(page, scope);
		check('missing terms consent: focus moves to the checkbox', f && f.field === 'accept_terms', f && `${f.tag}:${f.field} ${f.validation}`);
		check('terms checkbox is labelled', f && f.name.length > 10, f && f.name.slice(0, 60));
		await page.keyboard.press('Space');
	}
	const button = await tabUntil(page, scope, (s) => s.tag.startsWith('button'), 20);
	check('Tab reaches the submit button after the form fields', !!button);
	await page.keyboard.press('Enter');
	await page.waitForFunction((sel) => { const m = document.querySelector(sel + ' .wpcap-message'); return m && m.textContent && !/…/.test(m.textContent); }, scope, { timeout: 15000 }).catch(() => {});
	const done = await page.evaluate((sel) => document.querySelector(sel + ' .wpcap-message').textContent.trim(), scope);
	check('successful order is announced in the live region', /abgeschlossen|reserviert/.test(done), done);
	f = await focused(page, scope);
	check('focus is not lost after submit (stays in the form)', f && f.inside, f ? f.tag : 'body');

	console.log('Scanner: keyboard and announcements');
	await login(ctx);
	await page.goto(fx.scanner_url, { waitUntil: 'load' });
	const sc = '.wpcap-scanner';
	const scanStops = [];
	let s = await tabUntil(page, sc, () => true);
	while (s && s.inside && scanStops.length < 10) { scanStops.push(s); await page.keyboard.press('Tab'); s = await focused(page, sc); }
	console.log('    Tab stops: ' + scanStops.map((x) => `${x.tag}${x.field ? ':' + x.field : ''} "${x.name.slice(0, 40)}"`).join(' → '));
	check('scanner controls have names and visible focus', scanStops.length >= 3 && scanStops.every((x) => x.name && x.focusVisible), scanStops.filter((x) => !x.name || !x.focusVisible).map((x) => x.tag).join(', '));
	await page.focus('.wpcap-scan-form [name="token"]');
	await page.keyboard.type('ungueltig');
	await page.keyboard.press('Enter');
	await page.waitForFunction(() => { const r = document.querySelector('.wpcap-scan-result'); return r && r.textContent && !/…/.test(r.textContent); }, null, { timeout: 15000 }).catch(() => {});
	const result = await page.evaluate(() => { const r = document.querySelector('.wpcap-scan-result'); return { text: r.textContent.trim(), role: r.getAttribute('role'), live: r.getAttribute('aria-live'), state: r.dataset.state }; });
	check('scan result is announced (role=status, aria-live=polite)', result.text && result.role === 'status' && result.live === 'polite', `${result.state}: ${result.text}`);

	await browser.close();
	console.log(failures ? `\n${failures} keyboard check(s) failed.` : '\nAll keyboard checks passed.');
	process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
