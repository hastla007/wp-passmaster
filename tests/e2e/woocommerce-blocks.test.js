'use strict';
/*
 * WooCommerce Blocks checkout with Pass Captain participant fields (assets/woocommerce/attendees.js).
 * Needs a second test site with WooCommerce (tests/e2e/woo-env.sh, started by run.sh when WPCAP_WOO_ZIP
 * points to a woocommerce.zip); skipped otherwise.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const { execFileSync } = require('child_process');

const WOO_BASE = (process.env.WPCAP_E2E_WOO_BASE || '').replace(/\/$/, '');
const WOO_WP = process.env.WPCAP_E2E_WOO_WP_PATH || '';
const skip = !WOO_BASE || !WOO_WP ? 'WooCommerce test site not configured (WPCAP_WOO_ZIP for run.sh)' : false;

let lib, browser, ctx, setup;
const php = (script, ...args) => { const out = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, script), WOO_WP, ...args.map(String)], { encoding: 'utf8' }); return JSON.parse(out.slice(out.indexOf('{'))); };

test.before(async () => {
	if (skip) return;
	lib = require('./lib');
	const fx = JSON.parse((s => s.slice(s.indexOf('{')))(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, '..', 'a11y', 'fixture.php'), WOO_WP], { encoding: 'utf8' })));
	setup = php('woo-setup.php', fx.ticket_types['Freier Eintritt']);
	if (setup.restart) setup = php('woo-setup.php', fx.ticket_types['Freier Eintritt']);
	browser = await lib.launch();
	ctx = await lib.context(browser);
});
test.after(async () => { if (browser) await browser.close(); });

test('Blocks checkout: participant fieldset per ticket, invalid e-mail blocks the order, answers saved on the order', { skip }, async () => {
	await lib.withPage(() => ctx, 'woo blocks attendees', async (p) => {
		await p.goto(WOO_BASE + '/?add-to-cart=' + setup.product_id);
		await p.goto(setup.checkout_url);
		const box = p.locator('.wpcap-woo-attendees');
		await box.waitFor({ timeout: 30000 });
		assert.equal(await box.locator('fieldset.wpcap-woo-attendee').count(), 1);
		assert.match(await box.locator('legend').innerText(), /1 · .*Freier Eintritt/);
		const typeId = (await box.locator('input').first().getAttribute('id')).split('-')[2];
		const base = `#wpcap-att-${typeId}-0`;
		for (const id of [base + '-name', base + '-email', base + '-diet']) {
			assert.ok(await p.locator(`label[for="${id.slice(1)}"]`).count(), 'label for ' + id);
		}
		await p.fill('#email', 'woo-' + Date.now() + '@example.com');
		await p.fill(base + '-name', 'Woo Gast');
		await p.fill(base + '-email', 'kaputt');
		await p.selectOption('#billing-country', 'AT').catch(() => {});
		await p.fill('#billing-first_name', 'Erika');
		await p.fill('#billing-last_name', 'Muster');
		await p.fill('#billing-address_1', 'Ring 1');
		await p.fill('#billing-postcode', '1010');
		await p.fill('#billing-city', 'Wien');
		await p.click('.wc-block-components-checkout-place-order-button');
		const error = p.locator(base + '-email-error');
		await error.waitFor({ timeout: 10000 });
		assert.equal(await p.locator(base + '-email').getAttribute('aria-invalid'), 'true');
		assert.ok(!p.url().includes('order-received'), 'order not placed with an invalid attendee e-mail');

		await p.fill(base + '-email', 'gast@example.com');
		await p.fill(base + '-diet', 'vegan');
		await Promise.all([p.waitForURL(/order-received=\d+/, { timeout: 30000 }), p.click('.wc-block-components-checkout-place-order-button')]);
		const orderId = new URL(p.url()).searchParams.get('order-received');
		const saved = php('helper.php', 'woo-order', orderId);
		const attendees = typeof saved.attendees === 'string' ? JSON.parse(saved.attendees) : saved.attendees;
		const flat = JSON.stringify(attendees);
		assert.match(flat, /Woo Gast/);
		assert.match(flat, /gast@example\.com/);
		assert.match(flat, /vegan/);
	})();
});
