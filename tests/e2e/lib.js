'use strict';
/*
 * Shared helpers for the browser E2E tests (Playwright, node:test). They drive a disposable WordPress
 * site with this plugin (tests/support/wp-env.sh) and reuse the a11y fixture (tests/a11y/fixture.php).
 *
 * Env: WPCAP_A11Y_BASE (site URL), WPCAP_A11Y_WP_PATH (WordPress directory), WPCAP_A11Y_USER/_PASS,
 *      WPCAP_E2E_BROWSER = chromium (default) | firefox | webkit,
 *      WPCAP_E2E_DEVICE = Playwright device descriptor for mobile emulation, e.g. "Pixel 7" (chromium) or
 *                         "iPhone 14" (webkit); its browser type must match WPCAP_E2E_BROWSER,
 *      WPCAP_E2E_WS_ENDPOINT = ws:// endpoint of a `playwright run-server` (browser in a container, e.g. the
 *                         official Playwright image); launch options are sent with each connection.
 * Screenshots are written to tests/e2e/artifacts/ only when a test fails.
 *
 * Fake camera per engine (scanner.test.js): Chromium --use-fake-device-for-media-stream + grantPermissions;
 * WebKit has mock capture devices, grantPermissions(['camera']) grants them (without it: NotAllowedError);
 * Firefox has no camera by default (NotFoundError), does not support grantPermissions('camera') and only gets
 * a fake camera from browser-wide prefs that also skip the permission prompt – so the "granted" case runs in
 * a second Firefox instance (launch({ camera: true })).
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
const DEVICE = process.env.WPCAP_E2E_DEVICE || '';
const WS_ENDPOINT = process.env.WPCAP_E2E_WS_ENDPOINT || '';
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

let descriptor = null;
if (DEVICE) {
	descriptor = playwright.devices[DEVICE];
	if (!descriptor) { console.error('Unknown Playwright device "' + DEVICE + '".'); process.exit(2); }
	if (descriptor.defaultBrowserType !== BROWSER) {
		console.error('Device "' + DEVICE + '" needs WPCAP_E2E_BROWSER=' + descriptor.defaultBrowserType + ' (is ' + BROWSER + ').');
		process.exit(2);
	}
}
/** Short name of this run for screenshots and messages, e.g. "webkit-iphone-14". */
const LABEL = BROWSER + (DEVICE ? '-' + DEVICE.toLowerCase().replace(/[^a-z0-9]+/g, '-') : '');

function launchOptions(opts) {
	// Chromium: a fake camera, so a denied permission (not a missing device) is what the test sees.
	if (BROWSER === 'chromium') {
		return { args: ['--use-fake-device-for-media-stream', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-pings'] };
	}
	if (BROWSER === 'firefox' && opts && opts.camera) {
		return { firefoxUserPrefs: { 'media.navigator.streams.fake': true, 'media.navigator.permission.disabled': true } };
	}
	return {};
}

/** Launches the browser; { camera: true } = a browser whose pages get a (fake) camera without asking. */
async function launch(opts) {
	const type = playwright[BROWSER];
	if (!type) throw new Error('Unknown browser ' + BROWSER);
	const options = launchOptions(opts);
	if (WS_ENDPOINT) {
		return type.connect(WS_ENDPOINT, { headers: { 'x-playwright-launch-options': JSON.stringify(options) } });
	}
	if (BROWSER === 'chromium') {
		// The full Chromium build ("new headless", channel "chromium") supports camera capture; the headless shell does not.
		return type.launch(Object.assign({ channel: process.env.WPCAP_E2E_CHANNEL || 'chromium' }, options)).catch(() => type.launch(options));
	}
	return type.launch(options);
}
/** Grants the camera to the site in a context of a browser from launch() (Chromium, WebKit; Firefox: see launch()). */
async function grantCamera(ctx) {
	if (BROWSER !== 'firefox') await ctx.grantPermissions(['camera'], { origin: BASE });
}
async function context(browser, opts) {
	const base = { locale: 'de-DE', baseURL: BASE };
	if (descriptor) {
		const { defaultBrowserType, ...emulation } = descriptor; // eslint-disable-line no-unused-vars
		Object.assign(base, emulation);
	} else {
		base.viewport = { width: 1280, height: 900 };
	}
	return browser.newContext(Object.assign(base, opts || {}));
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
 * Mobile emulation only (WPCAP_E2E_DEVICE): the page must not scroll horizontally at the device width.
 * Elements wider than the viewport are named in the failure message.
 */
async function assertFitsViewport(page, what) {
	if (!DEVICE) return;
	const r = await page.evaluate(() => {
		const width = document.documentElement.clientWidth;
		const wide = Array.from(document.querySelectorAll('body *')).filter((el) => el.getBoundingClientRect().right > width + 1 && getComputedStyle(el).position !== 'fixed')
			.slice(0, 5).map((el) => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''));
		return { width, scroll: document.documentElement.scrollWidth, wide };
	});
	if (r.scroll > r.width + 1) {
		throw new Error(what + ': page scrolls horizontally on ' + DEVICE + ' (' + r.scroll + 'px > ' + r.width + 'px): ' + r.wide.join(', '));
	}
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
			const file = path.join(ARTIFACTS, LABEL + '-' + name.replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '.png');
			await page.screenshot({ path: file, fullPage: true }).catch(() => {});
			error.message += '\n    screenshot: ' + file;
			throw error;
		} finally {
			await page.close();
		}
	};
}

module.exports = { playwright, BASE, WP, BROWSER, DEVICE, LABEL, installMuPlugin, fixture, helper, codeFrom, launch, grantCamera, context, login, withPage, assertFitsViewport };
