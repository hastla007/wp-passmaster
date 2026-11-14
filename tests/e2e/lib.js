'use strict';
/*
 * Shared helpers for the browser E2E tests (Playwright, node:test). They drive a disposable WordPress
 * site with this plugin (tests/support/wp-env.sh) and reuse the a11y fixture (tests/a11y/fixture.php).
 *
 * Env: WPCAP_A11Y_BASE (site URL), WPCAP_A11Y_WP_PATH (WordPress directory), WPCAP_A11Y_USER/_PASS,
 *      WPCAP_E2E_BROWSER = chromium (default) | firefox | webkit.
 * Screenshots are written to tests/e2e/artifacts/ only when a test fails.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

let playwright;
try { playwright = require('playwright'); } catch (e) {
	console.error('Playwright is not installed (npm i -g playwright or provide it via NODE_PATH; browsers: npx playwright install chromium).');
	process.exit(2);
}

const BASE = (process.env.WPCAP_A11Y_BASE || 'http://127.0.0.1:8890').replace(/\/$/, '');
const WP = process.env.WPCAP_A11Y_WP_PATH || '';
const USER = process.env.WPCAP_A11Y_USER || 'admin';
const PASS = process.env.WPCAP_A11Y_PASS || 'admin';
const BROWSER = process.env.WPCAP_E2E_BROWSER || 'chromium';
const ARTIFACTS = path.join(__dirname, 'artifacts');
const PHP = process.env.PHP_BINARY || 'php';

if (!WP || !fs.existsSync(path.join(WP, 'wp-load.php'))) {
	console.error('Set WPCAP_A11Y_WP_PATH to the test WordPress directory (or run tests/e2e/run.sh).');
	process.exit(2);
}

/** Installs the E2E mu-plugin (fake payment page) into the test site. */
function installMuPlugin() {
	const dir = path.join(WP, 'wp-content', 'mu-plugins');
	fs.mkdirSync(dir, { recursive: true });
	fs.copyFileSync(path.join(__dirname, 'mu-plugin', 'wpcap-e2e.php'), path.join(dir, 'wpcap-e2e.php'));
}

function json(out) { return JSON.parse(out.slice(out.indexOf('{'))); }
/** New event data each call (fresh free order, ticket page and portal link, waitlist offer). */
function fixture() { return json(execFileSync(PHP, [path.join(__dirname, '..', 'a11y', 'fixture.php'), WP], { encoding: 'utf8' })); }
function helper(...args) { return json(execFileSync(PHP, [path.join(__dirname, 'helper.php'), WP, ...args.map(String)], { encoding: 'utf8' })); }
/** 64-hex ticket code from a ticket page URL (?wpcap_ticket=…). */
function codeFrom(ticketUrl) { return (/wpcap_ticket=([a-f0-9]{64})/.exec(ticketUrl) || [])[1]; }

async function launch() {
	const type = playwright[BROWSER];
	if (!type) throw new Error('Unknown browser ' + BROWSER);
	// Chromium: a fake camera, so a denied permission (not a missing device) is what the test sees.
	// The full Chromium build ("new headless", channel "chromium") supports camera capture; the headless shell does not.
	if (BROWSER === 'chromium') {
		const args = ['--use-fake-device-for-media-stream', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-pings'];
		return type.launch({ args, channel: process.env.WPCAP_E2E_CHANNEL || 'chromium' }).catch(() => type.launch({ args }));
	}
	return type.launch();
}
async function context(browser, opts) {
	const ctx = await browser.newContext(Object.assign({ viewport: { width: 1280, height: 900 }, locale: 'de-DE', baseURL: BASE }, opts || {}));
	return ctx;
}
async function login(ctx) {
	const page = await ctx.newPage();
	await page.goto(BASE + '/wp-login.php');
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await Promise.all([page.waitForURL((u) => !u.pathname.includes('wp-login'), { timeout: 60000 }), page.click('#wp-submit')]);
	await page.close();
}

/**
 * Wraps a test body: gives it a fresh page, saves a full-page screenshot to tests/e2e/artifacts/ when the
 * test fails, and closes the page afterwards.
 */
function withPage(getCtx, name, fn) {
	return async (t) => {
		const ctx = await getCtx();
		const page = await ctx.newPage();
		try {
			await fn(page, t);
		} catch (error) {
			fs.mkdirSync(ARTIFACTS, { recursive: true });
			const file = path.join(ARTIFACTS, BROWSER + '-' + name.replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '.png');
			await page.screenshot({ path: file, fullPage: true }).catch(() => {});
			error.message += '\n    screenshot: ' + file;
			throw error;
		} finally {
			await page.close();
		}
	};
}

module.exports = { playwright, BASE, WP, BROWSER, installMuPlugin, fixture, helper, codeFrom, launch, context, login, withPage };
