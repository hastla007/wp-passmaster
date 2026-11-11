'use strict';
/* Shared helpers for the accessibility checks (Playwright + axe-core). */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

let playwright;
try {
	playwright = require('playwright');
} catch (e) {
	console.error('Playwright is not installed. Run `npm i --no-save playwright && npx playwright install chromium` in tests/a11y (or provide it via NODE_PATH).');
	process.exit(2);
}

const BASE = (process.env.WPCAP_A11Y_BASE || 'http://localhost:8080').replace(/\/$/, '');
const USER = process.env.WPCAP_A11Y_USER || 'admin';
const PASS = process.env.WPCAP_A11Y_PASS || 'admin';
const REPORT_DIR = path.join(__dirname, 'report');
const AXE_PATH = require.resolve('axe-core/axe.min.js');

/** Runs fixture.php against WPCAP_A11Y_WP_PATH, or reads WPCAP_A11Y_FIXTURE (JSON file). */
function fixture() {
	if (process.env.WPCAP_A11Y_FIXTURE) return JSON.parse(fs.readFileSync(process.env.WPCAP_A11Y_FIXTURE, 'utf8'));
	const wp = process.env.WPCAP_A11Y_WP_PATH;
	if (!wp) {
		console.error('Set WPCAP_A11Y_WP_PATH=/path/to/wordpress (fixture.php is run with PHP) or WPCAP_A11Y_FIXTURE=fixture.json.');
		process.exit(2);
	}
	const out = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'fixture.php'), wp], { encoding: 'utf8' });
	return JSON.parse(out.slice(out.indexOf('{')));
}

async function launch() {
	const browser = await playwright.chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'de-DE', reducedMotion: 'reduce' });
	return { browser, ctx };
}

async function login(ctx) {
	const page = await ctx.newPage();
	await page.goto(BASE + '/wp-login.php?redirect_to=' + encodeURIComponent(BASE + '/'));
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await page.click('#wp-submit');
	await page.waitForURL((u) => !u.pathname.includes('wp-login'), { waitUntil: 'commit', timeout: 60000 }).catch(() => {});
	if (page.url().includes('wp-login.php')) throw new Error('Login failed for ' + USER);
	await page.close();
}

/** Runs axe on the page (optionally limited to a CSS context) with WCAG 2.2 A/AA + best-practice rules. */
async function axe(page, include) {
	await page.addScriptTag({ path: AXE_PATH });
	return page.evaluate(async (inc) => {
		const ctx = inc && document.querySelector(inc) ? { include: [[inc]] } : document;
		const r = await window.axe.run(ctx, {
			runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] },
			resultTypes: ['violations', 'incomplete'],
		});
		const pick = (v) => ({ id: v.id, impact: v.impact, help: v.help, helpUrl: v.helpUrl, tags: v.tags.filter((t) => /^wcag/.test(t)), nodes: v.nodes.map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 240), summary: n.failureSummary })) });
		return { violations: r.violations.map(pick), incomplete: r.incomplete.filter((v) => v.id === 'color-contrast').map(pick) };
	}, include || null);
}

module.exports = { playwright, BASE, REPORT_DIR, fixture, launch, login, axe };
