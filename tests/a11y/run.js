'use strict';
/*
 * axe-core scan of the public pages and the main admin screens of WP Pass Captain.
 *
 *   WPCAP_A11Y_WP_PATH=/path/to/wp node run.js [--fail-on=critical|serious|moderate|minor|none] [--only=public|admin]
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
		{ name: 'Ticket page', group: 'public', url: fx.ticket_url },
		{ name: 'Order portal', group: 'public', url: fx.portal_url },
		{ name: 'Waitlist offer page', group: 'public', url: fx.waitlist_url },
		{
			name: 'Waitlist offer accepted', group: 'public', url: fx.waitlist_url,
			prepare: async (page) => { await Promise.all([page.waitForNavigation(), page.click('main form button[type="submit"]')]); },
		},
		{ name: 'Scanner (logged in)', group: 'public', url: fx.scanner_url, include: '.wpcap-scanner', auth: true },
		{
			name: 'Scanner result (invalid code)', group: 'public', url: fx.scanner_url, include: '.wpcap-scanner', auth: true,
			prepare: async (page) => { await page.fill('.wpcap-scan-form [name="token"]', 'ungueltig'); await page.press('.wpcap-scan-form [name="token"]', 'Enter'); await page.waitForTimeout(800); },
		},
		{ name: 'Admin: Events', group: 'admin', url: admin('page=wp-pass-captain'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Event detail', group: 'admin', url: admin('page=wp-pass-captain&view=event&event_id=' + fx.event_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Orders', group: 'admin', url: admin('page=wpcap-orders'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Order detail', group: 'admin', url: admin('page=wpcap-orders&order_id=' + fx.order_id), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Check-ins', group: 'admin', url: admin('page=wpcap-checkins'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Box office', group: 'admin', url: admin('page=wpcap-boxoffice'), include: '#wpbody-content', auth: true },
		{ name: 'Admin: Settings', group: 'admin', url: admin('page=wpcap-settings'), include: '#wpbody-content', auth: true },
	].filter((p) => !args.only || p.group === args.only);
	// A page without URL (fixture could not create it) is a failure, never silently skipped.
	const missing = pages.filter((p) => !p.url).map((p) => p.name);
	if (missing.length) { console.log('Missing page URL (not checked): ' + missing.join(', ')); }

	const { browser, ctx } = await launch();
	await login(ctx);
	const anon = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'de-DE' });
	const results = [];
	let failed = false;
	for (const def of pages.filter((p) => p.url)) {
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
