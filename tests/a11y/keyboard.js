'use strict';
/*
 * Keyboard-only walkthrough of the public checkout, the service booking page (A268) and the scanner form:
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
	// Quantity steppers (−/+, A100) add two stops per ticket type: allow enough Tabs to leave the form.
	for (let i = 0; i < 120; i++) {
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
	// Focus is on the element after the card now; Shift+Tab back to the order button (the card may end
	// with further forms, e.g. the waitlist form of a sold-out ticket type).
	let f = null;
	for (let i = 0; i < 12; i++) {
		await page.keyboard.press('Shift+Tab');
		f = await focused(page, scope);
		if (await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('wpcap-submit') && !!document.activeElement.closest('.wpcap-checkout-form'))) { break; }
	}
	check('Shift+Tab returns to the order button', f && f.tag === 'button[submit]' && await page.evaluate(() => document.activeElement.classList.contains('wpcap-submit')), f && f.tag);
	await page.keyboard.press('Enter');
	await page.waitForTimeout(200);
	const live = await page.evaluate((sel) => {
		const m = document.querySelector(sel + ' .wpcap-error-summary');
		return m ? { text: m.textContent.trim(), role: m.getAttribute('role'), focused: document.activeElement === m, links: m.querySelectorAll('a[href^="#"]').length } : { text: '', role: '', focused: false, links: 0 };
	}, scope);
	check('error summary is shown with links to the fields', live.text.length > 0 && live.links > 0, live.text);
	check('error summary is an alert and receives focus', live.role === 'alert' && live.focused);

	console.log('Checkout: dynamic attendee fields and native validation');
	await page.goto(fx.events_url, { waitUntil: 'load' });
	await page.evaluate((id) => { document.querySelector(`.wpcap-qty[data-type="${id}"]`).closest('article').setAttribute('data-a11y-scope', '1'); }, stdId);
	f = await tabUntil(page, scope, (s) => s.field === freeId);
	check('free ticket quantity is reachable', !!f);
	await page.keyboard.press('ArrowUp');
	await page.waitForTimeout(100);
	const attendees = await page.evaluate((sel) => document.querySelectorAll(sel + ' .wpcap-attendee').length, scope);
	check('ArrowUp on the quantity adds an attendee fieldset', attendees === 1, String(attendees));
	// The quantity is followed by its −/+ stepper buttons (named, A100); Tab past them.
	await page.keyboard.press('Tab');
	f = await focused(page, scope);
	const stepperStops = [];
	while (f && /^button/.test(f.tag) && /Ticket (mehr|weniger)/.test(f.name) && stepperStops.length < 4) {
		stepperStops.push(f.name);
		await page.keyboard.press('Tab');
		f = await focused(page, scope);
	}
	// MERGE-CHECK: the quantity stepper buttons come from the Arbeitsliste checkout; drop this check if the merged checkout has none.
	check('stepper buttons after the quantity are named tab stops', stepperStops.length >= 1 && stepperStops.every((n) => n.length > 0), stepperStops.join(' | '));
	if (f && f.tag.startsWith('button') && /Warteliste|waitlist/i.test(f.name)) {
		// "Auf die Warteliste" of the sold-out type (after the free type in the ticket list).
		check('waitlist button of the sold-out type is labelled with the ticket type', /Ausverkauft/.test(f.name), f.name);
		await page.keyboard.press('Tab');
		f = await focused(page, scope);
	}
	check('next Tab reaches the buyer e-mail (placed before the attendee fields)', f && f.field === 'email' && /E-Mail|e-?mail/i.test(f.name), f && `${f.tag}:${f.field} ${f.name}`);
	await page.keyboard.type('keine-adresse');
	await page.keyboard.press('Tab');
	f = await focused(page, scope);
	if (f && f.field === 'buyer_name') {
		// Optional buyer name (between the buyer e-mail and the attendee fields).
		check('optional buyer name is labelled', /Name/i.test(f.name), f.name);
		await page.keyboard.press('Tab');
		f = await focused(page, scope);
	}
	if (f && f.field === 'locale') {
		// Buyer language selector (between the buyer e-mail and the attendee fields).
		check('buyer language selector is labelled', /Sprache|Language|langue|idioma|lingua|taal/i.test(f.name), f.name);
		await page.keyboard.press('Tab');
		f = await focused(page, scope);
	}
	check('then Tab moves into the new attendee fields', f && f.field === '__name', f && `${f.tag}:${f.field}`);
	check('attendee name field is labelled', f && /Name/i.test(f.name), f && f.name);
	const group = await page.evaluate((sel) => { const fs = document.querySelector(sel + ' .wpcap-attendee'); return fs && fs.querySelector('legend') ? fs.querySelector('legend').textContent : ''; }, scope);
	check('attendee fieldset has a legend', !!group, group);
	await page.keyboard.type('Alex Muster');
	await page.keyboard.press('Enter');
	await page.waitForTimeout(200);
	f = await focused(page, scope);
	const emailError = await page.evaluate((sel) => { const i = document.querySelector(sel + ' [name="email"]'); const d = i && document.getElementById(i.getAttribute('aria-describedby') || ''); return { invalid: i && i.getAttribute('aria-invalid'), described: d ? d.textContent : '', summary: document.activeElement && document.activeElement.classList.contains('wpcap-error-summary') }; }, scope);
	check('invalid e-mail: summary focused, field has aria-invalid and a described error', emailError.summary && emailError.invalid === 'true' && /E-Mail|e-?mail/i.test(emailError.described), emailError.described);
	await page.evaluate((sel) => document.querySelector(sel + ' [name="email"]').focus(), scope);
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
	const button = await tabUntil(page, scope, (s) => s.tag === 'button[submit]', 40);
	check('Tab reaches the submit button after the form fields', !!button);
	await page.keyboard.press('Enter');
	await page.waitForFunction(() => !!document.querySelector('.wpcap-confirmation, .wpcap-error-summary'), null, { timeout: 15000 }).catch(() => {});
	const done = await page.evaluate(() => { const p = document.querySelector('.wpcap-confirmation'); const e = document.querySelector('.wpcap-error-summary'); return { text: p ? p.textContent.trim() : (e ? 'ERROR: ' + e.textContent.trim() : ''), focused: !!p && document.activeElement === p }; });
	check('successful order shows the confirmation panel with the order number', /Bestellnummer|Order number/i.test(done.text), done.text.slice(0, 80));
	check('focus moves to the confirmation panel', done.focused);

	console.log('Service booking (A268): keyboard flow, focus and announcements');
	await page.goto(fx.service_url, { waitUntil: 'load' });
	const sb = '.wpcap-service-booking';
	// Switch to the free resource with the arrow key (radio group); the week of that resource loads.
	await page.focus(sb + ' input[name="resource_id"]:checked');
	await page.keyboard.press('ArrowDown');
	await page.waitForFunction((sel) => !document.querySelector(sel + ' .wpcap-sb-days[aria-busy]') && /Schnupper/.test(document.querySelector(sel + ' input[name="resource_id"]:checked').labels[0].textContent), sb, { timeout: 15000 }).catch(() => {});
	await page.waitForTimeout(150);
	const switched = await page.evaluate((sel) => ({ live: document.querySelector(sel + ' .wpcap-sb-live').textContent.trim(), paidHidden: document.querySelector(sel + ' .wpcap-sb-paid').hidden, slots: document.querySelectorAll(sel + ' input[name="slot_id"]').length }), sb);
	check('arrow key switches the resource: slots reloaded and announced, payment fields hidden for the free one', switched.slots > 0 && /Woche/.test(switched.live) && switched.paidHidden, switched.live);
	await page.evaluate(() => document.activeElement.blur());
	const sbStops = [];
	let st = await tabUntil(page, sb, () => true);
	while (st && st.inside && sbStops.length < 40) {
		sbStops.push(st);
		if (/Weiter zu deinen Angaben/.test(st.name)) break;
		await page.keyboard.press('Tab');
		st = await focused(page, sb);
	}
	console.log('    Tab stops: ' + sbStops.map((x) => `${x.tag}${x.field ? ':' + x.field : ''} "${x.name.slice(0, 40)}"`).join('\n               '));
	check('booking step 1: every tab stop is named and shows focus', sbStops.length >= 4 && sbStops.every((x) => x.name && x.focusVisible), sbStops.filter((x) => !x.name || !x.focusVisible).map((x) => x.tag + ':' + x.field).join(', '));
	check('booking step 1: tab order follows the DOM order', sbStops.every((x, i) => i === 0 || x.index > sbStops[i - 1].index));
	await page.focus(sb + ' .wpcap-sb-next');
	const nextDisabled = await page.evaluate((sel) => document.querySelector(sel + ' .wpcap-sb-next').getAttribute('aria-disabled'), sb);
	if (nextDisabled !== 'true') {
		await page.keyboard.press('Enter');
		await page.waitForFunction((sel) => !document.querySelector(sel + ' .wpcap-sb-days[aria-busy]'), sb, { timeout: 15000 }).catch(() => {});
		await page.waitForTimeout(150);
		const nav = await page.evaluate((sel) => ({ live: document.querySelector(sel + ' .wpcap-sb-live').textContent.trim(), focus: document.activeElement.classList.contains('wpcap-sb-next') }), sb);
		check('week navigation is announced in the live region and keeps the focus', /Woche/.test(nav.live) && nav.focus, nav.live);
		await page.focus(sb + ' .wpcap-sb-prev');
		await page.keyboard.press('Enter');
		await page.waitForFunction((sel) => !document.querySelector(sel + ' .wpcap-sb-days[aria-busy]') && document.querySelector(sel + ' input[name="slot_id"]'), sb, { timeout: 15000 }).catch(() => {});
	}
	await page.focus(sb + ' .wpcap-sb-continue');
	await page.keyboard.press('Enter');
	await page.waitForTimeout(150);
	const noSlot = await page.evaluate((sel) => ({ summary: document.activeElement && document.activeElement.classList.contains('wpcap-error-summary'), text: (document.querySelector(sel + ' .wpcap-error-summary') || {}).textContent || '' }), sb);
	check('continue without a slot: error summary focused', noSlot.summary && /Zeitfenster/.test(noSlot.text), noSlot.text.trim());
	await page.focus(sb + ' input[name="slot_id"]');
	await page.keyboard.press('Space');
	const slotChecked = await page.evaluate((sel) => !!document.querySelector(sel + ' input[name="slot_id"]:checked'), sb);
	check('Space selects the focused slot', slotChecked);
	st = await tabUntil(page, sb, (x) => /Weiter zu deinen Angaben/.test(x.name), 20);
	await page.keyboard.press('Enter');
	await page.waitForTimeout(150);
	const step2 = await page.evaluate(() => ({ heading: /^H[2-6]$/.test(document.activeElement.tagName), text: document.activeElement.textContent }));
	check('continue moves the focus to the heading of step 2', step2.heading && /Angaben/.test(step2.text), step2.text);
	st = await tabUntil(page, sb, (x) => x.field === 'email', 10);
	check('e-mail field reachable and labelled', st && /E-Mail/.test(st.name), st && st.name);
	await page.keyboard.type('a11y-keyboard-' + Date.now() + '@example.com');
	await page.keyboard.press('Enter');
	await page.waitForFunction((sel) => !!document.querySelector(sel + ' .wpcap-sb-confirmation, ' + sel + ' .wpcap-error-summary'), sb, { timeout: 15000 }).catch(() => {});
	const booked = await page.evaluate((sel) => { const p = document.querySelector(sel + ' .wpcap-sb-confirmation'); const e = document.querySelector(sel + ' .wpcap-error-summary'); return { text: p ? p.textContent : (e ? 'ERROR: ' + e.textContent : ''), focused: !!p && document.activeElement === p, link: p && p.querySelector('.wpcap-sb-manage a') ? p.querySelector('.wpcap-sb-manage a').getAttribute('href') : '' }; }, sb);
	check('booking confirmed: confirmation panel focused', booked.focused && /gebucht/.test(booked.text), booked.text.trim().slice(0, 100));
	check('confirmation offers the manage link (token only in the fragment)', /#wpcap-booking=\d+-[a-f0-9]{64}$/.test(booked.link) && !/[?&]token=/.test(booked.link), booked.link.replace(/[a-f0-9]{64}/, '…'));
	st = await tabUntil(page, sb, (x) => x.tag === 'a', 10);
	check('manage link is reachable with Tab', !!st, st && st.name);
	await page.keyboard.press('Enter');
	await page.waitForFunction((sel) => !!document.querySelector(sel + ' .wpcap-sb-details'), sb, { timeout: 15000 }).catch(() => {});
	await page.waitForTimeout(150);
	const manage = await page.evaluate((sel) => { const v = document.querySelector(sel + ' .wpcap-sb-manage-view'); return { focused: !!v && document.activeElement === v, cancel: !!(v && v.querySelector('.wpcap-sb-cancel-start')), text: v ? v.textContent : '' }; }, sb);
	check('manage view shows the booking, focus on it, cancel offered', manage.focused && manage.cancel && /Bestätigt/.test(manage.text), manage.text.trim().slice(0, 100));
	await page.focus(sb + ' .wpcap-sb-cancel-start');
	await page.keyboard.press('Enter');
	const dialog = await page.evaluate(() => ({ focusNo: document.activeElement.classList.contains('wpcap-sb-cancel-no'), expanded: document.querySelector('.wpcap-sb-cancel-start').getAttribute('aria-expanded') }));
	check('cancel asks first (focus on "keep", aria-expanded)', dialog.focusNo && dialog.expanded === 'true');
	await page.keyboard.press('Enter');
	check('"keep" returns the focus to the cancel button', await page.evaluate(() => document.activeElement.classList.contains('wpcap-sb-cancel-start')));

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
	const result = await page.evaluate(() => { const r = document.querySelector('.wpcap-scan-result'); const l = document.querySelector('.wpcap-scan-live'); return { text: r.textContent.trim(), icon: (r.querySelector('.wpcap-scan-icon') || {}).textContent || '', state: r.dataset.state, live: l ? l.textContent.trim() : '', role: l ? l.getAttribute('role') : '', buttons: l ? l.querySelectorAll('button').length : -1 }; });
	check('scan result is a banner with an icon, not colour alone', result.text && result.icon && result.state === 'error', `${result.state}: ${result.icon} ${result.text}`);
	check('scan result is announced in a separate short live region (role=status, no buttons)', result.role === 'status' && result.live && result.buttons === 0, result.live);

	await browser.close();
	console.log(failures ? `\n${failures} keyboard check(s) failed.` : '\nAll keyboard checks passed.');
	process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
