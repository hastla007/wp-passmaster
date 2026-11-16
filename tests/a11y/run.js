'use strict';
/*
 * axe-core scan of the public pages and the main admin screens of Pass Captain.
 *
 *   WPCAP_A11Y_WP_PATH=/path/to/wp node run.js [--fail-on=critical|serious|moderate|minor|none] [--only=public|admin] [--match=text]
 *
 * Admin: the fixed list below plus every submenu screen of the Pass Captain menu (discovered after login).
 *
 * Writes report/axe-<timestamp>.json and prints violations grouped by impact. Exit code 1 when a
 * violation at or above --fail-on (default: serious) is found on a page.
 */
const fs = require('fs');
const path = require('path');
const { BASE, REPORT_DIR, fixture, launch, login, axe } = require('./lib');

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const FAIL_ON = args['fail-on'] || 'serious';
const ORDER = ['critical', 'serious', 'moderate', 'minor'];

(async () => {
	const fx = fixture();
	const admin = (q) => BASE + '/wp-admin/admin.php?' + q;
	const pages = [
		{ name: 'Events + checkout form', group: 'public', url: fx.events_url, include: '.wpcap-events' },
		{
			name: 'Checkout form with attendee fields + error message', group: 'public', url: fx.events_url, include: '.wpcap-events',
			prepare: async (page) => {
				const qty = page.locator(`.wpcap-qty[data-type="${fx.ticket_types['Freier Eintritt']}"]`);
				const form = page.locator('.wpcap-checkout-form').filter({ has: qty });
				await qty.fill('2');
				await form.locator('[name="email"]').fill('a11y@example.com');
				await form.locator('button[type="submit"]').click();
				await page.waitForTimeout(300);
			},
		},
		{ name: 'Event list (layout list)', group: 'public', url: fx.events_list_url, include: '.wpcap-events' },
		{ name: 'Single occurrence view (?wpcap_occ)', group: 'public', url: fx.events_url && fx.events_url + (fx.events_url.includes('?') ? '&' : '?') + 'wpcap_occ=' + fx.occurrence_id, include: '.wpcap-events-detail' },
		{
			name: 'Waitlist sign-up of a sold-out ticket type', group: 'public', url: fx.events_url, include: '.wpcap-events',
			prepare: async (page) => {
				// Merged card (A456): one waitlist form per card, shown while a type is sold out; submit empty first (error state).
				const card = page.locator('.wpcap-event').filter({ has: page.locator(`.wpcap-qty[data-type="${fx.ticket_types.Ausverkauft}"]`) });
				const form = card.locator('.wpcap-waitlist-form');
				await form.locator('button[type="submit"]').click();
				await form.locator('[name="email"]').fill('a11y-wl@example.com');
				await page.waitForTimeout(200);
			},
		},
		{ name: 'Ticket page', group: 'public', url: fx.ticket_url },
		{ name: 'Order portal', group: 'public', url: fx.portal_url },
		{ name: 'Waitlist offer page', group: 'public', url: fx.waitlist_url },
		{
			name: 'Waitlist offer accepted', group: 'public', url: fx.waitlist_url,
			prepare: async (page) => { await Promise.all([page.waitForNavigation(), page.click('main form button[type="submit"]')]); },
		},
		{ name: 'Service booking (A268)', group: 'public', url: fx.service_url, include: '.wpcap-service-booking' },
		{
			name: 'Service booking: next week + step 2 with errors', group: 'public', url: fx.service_url, include: '.wpcap-service-booking',
			prepare: async (page) => {
				await page.click('.wpcap-sb-next').catch(() => {});
				await page.waitForTimeout(500);
				await page.click('.wpcap-sb-prev').catch(() => {});
				await page.waitForSelector('.wpcap-sb-days input[name="slot_id"]');
				await page.check('.wpcap-sb-days input[name="slot_id"] >> nth=0');
				await page.click('.wpcap-sb-continue');
				await page.click('.wpcap-sb-submit');
				await page.waitForTimeout(300);
			},
		},
		{
			name: 'Service booking: confirmation (free)', group: 'public', url: fx.service_url, include: '.wpcap-service-booking',
			prepare: async (page) => {
				await page.check('.wpcap-service-booking input[name="resource_id"] >> nth=1');
				await page.waitForFunction(() => !document.querySelector('.wpcap-sb-days[aria-busy]'));
				await page.waitForSelector('.wpcap-sb-days input[name="slot_id"]');
				await page.check('.wpcap-sb-days input[name="slot_id"] >> nth=0');
				await page.click('.wpcap-sb-continue');
				await page.fill('.wpcap-sb-step-data [name="email"]', 'a11y-service-' + Date.now() + '@example.com');
				await page.click('.wpcap-sb-submit');
				await page.waitForSelector('.wpcap-sb-confirmation', { timeout: 15000 });
			},
		},
		{
			name: 'Service booking: manage view', group: 'public', url: fx.service_url, include: '.wpcap-service-booking',
			prepare: async (page) => {
				await page.check('.wpcap-service-booking input[name="resource_id"] >> nth=1');
				await page.waitForFunction(() => !document.querySelector('.wpcap-sb-days[aria-busy]'));
				await page.waitForSelector('.wpcap-sb-days input[name="slot_id"]');
				await page.check('.wpcap-sb-days input[name="slot_id"] >> nth=0');
				await page.click('.wpcap-sb-continue');
				await page.fill('.wpcap-sb-step-data [name="email"]', 'a11y-manage-' + Date.now() + '@example.com');
				await page.click('.wpcap-sb-submit');
				await page.waitForSelector('.wpcap-sb-manage a', { timeout: 15000 });
				await page.click('.wpcap-sb-manage a');
				await page.waitForSelector('.wpcap-sb-details', { timeout: 15000 });
			},
		},
		{ name: 'Scanner (logged in)', group: 'public', url: fx.scanner_url, include: '.wpcap-scanner', auth: true },
		{
			name: 'Scanner result (invalid code)', group: 'public', url: fx.scanner_url, include: '.wpcap-scanner', auth: true,
			prepare: async (page) => { await page.fill('.wpcap-scan-form [name="token"]', 'ungueltig'); await page.press('.wpcap-scan-form [name="token"]', 'Enter'); await page.waitForTimeout(800); },
		},
		{ name: 'Admin: Events', group: 'admin', url: admin('page=pass-captain'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Event detail', group: 'admin', url: admin('page=pass-captain&view=event&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Orders', group: 'admin', url: admin('page=wpcap-orders'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Order detail', group: 'admin', url: admin('page=wpcap-orders&order_id=' + fx.order_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Check-ins', group: 'admin', url: admin('page=wpcap-checkins'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Box office', group: 'admin', url: admin('page=wpcap-boxoffice'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Service-Zeitfenster', group: 'admin', url: admin('page=wpcap-service-slots&event_id=' + fx.service_event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Settings', group: 'admin', url: admin('page=wpcap-settings'), include: '#wpbody-content', auth: true },
		// Detail views of the newer admin screens (fixture creates one record each).
		{ name: 'Admin: Campaign detail', group: 'admin', url: fx.campaign_id && admin('page=wpcap-campaigns&campaign_id=' + fx.campaign_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: New campaign', group: 'admin', url: admin('page=wpcap-campaigns&campaign_id=new'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Waitlist of event', group: 'admin', url: admin('page=wpcap-waitlists&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Resource detail', group: 'admin', url: fx.resource_id && admin('page=wpcap-services&resource_id=' + fx.resource_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Admission rules of event', group: 'admin', url: admin('page=wpcap-admission&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Offline scanner of event', group: 'admin', url: admin('page=wpcap-offline-scanner&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Reports of event', group: 'admin', url: admin('page=wpcap-reports&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Recurring dates of event', group: 'admin', url: admin('page=wpcap-series&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Tickets of event', group: 'admin', url: admin('page=wpcap-tickets&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Seat map editor', group: 'admin', url: fx.layout_id && admin('page=wpcap-seating&layout_id=' + fx.layout_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Member pass detail', group: 'admin', url: fx.pass_id && admin('page=wpcap-memberships&pass_id=' + fx.pass_id), include: '#wpbody-content', auth: true },
	];
	// A page without URL (fixture could not create it) is a failure, never silently skipped.
	const missing = pages.filter((p) => !p.url && (!args.only || p.group === args.only)).map((p) => p.name);
	if (missing.length) { console.log('Missing page URL (not checked): ' + missing.join(', ')); }

	const { browser, ctx } = await launch();
	await login(ctx);
	// Every other Pass Captain submenu screen (modules included) is audited too, so new screens are covered automatically.
	const menu = await ctx.newPage();
	await menu.goto(admin('page=pass-captain'), { waitUntil: 'load' }); // merged slug (the old one redirects)
	// Top-level menu slug: pass-captain (merged) or the former wp-pass-captain; both are looked for.
	const links = await menu.$$eval('#toplevel_page_wp-pass-captain .wp-submenu a, #toplevel_page_pass-captain .wp-submenu a', (as) => as.map((a) => ({ text: a.textContent.replace(/\s+/g, ' ').trim(), href: a.href })));
	await menu.close();
	const known = new Set(pages.filter((p) => p.url).map((p) => p.url));
	for (const l of links) {
		if (!l.href.startsWith(BASE + '/wp-admin/admin.php?page=') || known.has(l.href)) continue;
		known.add(l.href);
		pages.push({ name: 'Admin: ' + l.text.replace(/[↗]/g, '').trim(), group: 'admin', url: l.href, include: '#wpbody-content', auth: true });
	}
	const selected = pages.filter((p) => p.url && (!args.only || p.group === args.only) && (!args.match || p.name.toLowerCase().includes(args.match.toLowerCase())));
	const anon = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'de-DE', bypassCSP: true }); // axe injection vs. strict CSP on token pages
	const results = [];
	let failed = false;
	for (const def of selected) {
		const page = await (def.auth ? ctx : anon).newPage();
		const response = await page.goto(def.url, { waitUntil: 'load' });
		const status = response ? response.status() : 0;
		if (status !== 200) {
			// An error page is not the screen we want to audit.
			results.push({ name: def.name, group: def.group, url: def.url, status, violations: [], incomplete: [] });
			failed = true;
			await page.close();
			continue;
		}
		if (def.prepare) await def.prepare(page);
		const r = await axe(page, def.include);
		results.push({ name: def.name, group: def.group, url: def.url, status, ...r });
		await page.close();
	}
	await browser.close();

	fs.mkdirSync(REPORT_DIR, { recursive: true });
	const file = path.join(REPORT_DIR, 'axe-' + new Date().toISOString().replace(/[:.]/g, '-') + '.json');
	fs.writeFileSync(file, JSON.stringify(results, null, 2));

	const totals = Object.fromEntries(ORDER.map((i) => [i, 0]));
	const threshold = FAIL_ON === 'none' ? -1 : ORDER.indexOf(FAIL_ON);
	console.log('\n' + 'Page'.padEnd(52) + ORDER.map((i) => i.padEnd(10)).join('') + 'contrast?');
	for (const r of results) {
		const counts = Object.fromEntries(ORDER.map((i) => [i, 0]));
		r.violations.forEach((v) => { counts[v.impact] += v.nodes.length; totals[v.impact] += v.nodes.length; if (ORDER.indexOf(v.impact) <= threshold) failed = true; });
		const review = r.incomplete.reduce((n, v) => n + v.nodes.length, 0);
		console.log((r.name + (r.status !== 200 ? ` [HTTP ${r.status}, not audited]` : '')).padEnd(52) + ORDER.map((i) => String(counts[i]).padEnd(10)).join('') + review);
	}
	console.log('Total (nodes)'.padEnd(52) + ORDER.map((i) => String(totals[i]).padEnd(10)).join(''));
	for (const r of results) {
		for (const v of r.violations.sort((a, b) => ORDER.indexOf(a.impact) - ORDER.indexOf(b.impact))) {
			console.log(`\n[${v.impact}] ${r.name}: ${v.id} – ${v.help} (${v.tags.join(', ')})`);
			v.nodes.slice(0, 5).forEach((n) => console.log('    ' + n.target + '  ' + n.html.replace(/\s+/g, ' ').slice(0, 160)));
			if (v.nodes.length > 5) console.log(`    … ${v.nodes.length - 5} more`);
		}
	}
	console.log('\nReport: ' + file);
	process.exit(failed || missing.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
