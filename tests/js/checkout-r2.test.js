/**
 * Checkout script (assets/checkout.js) in Node with a small fake DOM (fake-dom.js):
 * A308 two cards of the same date: the billing toggle and error links stay in their own card;
 * A309 only a JSON answer with order number and status counts as success, the idempotency key
 *      survives an unclear answer; A310 back from the payment page (bfcache or reload) points to the
 *      pending order and reuses its key; A576 the shown unit prices travel with the order, and a
 *      price_changed answer updates the card.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, submit, flush, response, FakeEvent } = require('./checkout-harness.js');

test('A308: the billing toggle opens the fields of its own card, also with duplicate ids', () => {
	// A theme template that still renders `wpcap-{occurrence}` twice: both cards carry the same ids.
	const env = loadCheckout({ cards: [{ id: 'wpcap-7' }, { id: 'wpcap-7' }] });
	const [first, second] = env.forms;
	const toggle = second.querySelector('[name="billing_enabled"]');
	toggle.checked = true;
	toggle.dispatchEvent(new FakeEvent('change', { bubbles: true }));
	assert.strictEqual(second.querySelector('.wpcap-billing-fields').hidden, false, 'second card opens its own fields');
	assert.strictEqual(first.querySelector('.wpcap-billing-fields').hidden, true, 'first card stays closed');
});

test('A308: inline errors are added to the field of the submitted card', async () => {
	const env = loadCheckout({ cards: [{ id: 'wpcap-7' }, { id: 'wpcap-7' }] });
	const [first, second] = env.forms;
	// First card already shows an error for its e-mail field.
	await submit(env, first, { qty: 1, email: '' });
	assert.ok(first.querySelector('.wpcap-field-error'), 'first card marks its field');
	await submit(env, second, { qty: 1, email: '' });
	assert.ok(second.querySelector('.wpcap-field-error'), 'second card marks its own field, although an element with the same error id exists elsewhere');
	assert.strictEqual(env.requests.length, 0, 'invalid forms are not sent');
});

const stripe = (extra) => response(201, Object.assign({ reference: 'K7Q2', status: 'pending_payment', payment_status: 'pending', hold_minutes: 30, payment: { provider: 'stripe', status: 'pending', redirect_url: 'https://checkout.stripe.test/c/pay/cs_1' } }, extra || {}));

test('A309: classifyAnswer accepts only JSON with order number and status as success', () => {
	const { logic } = loadCheckout();
	assert.strictEqual(logic.classifyAnswer(true, { reference: 'AB12', status: 'complete' }), 'success');
	assert.strictEqual(logic.classifyAnswer(true, null), 'unknown', 'HTML page with HTTP 200');
	assert.strictEqual(logic.classifyAnswer(true, {}), 'unknown', 'empty JSON');
	assert.strictEqual(logic.classifyAnswer(true, { reference: 'AB12' }), 'unknown', 'no status');
	assert.strictEqual(logic.classifyAnswer(true, [1]), 'unknown');
	assert.strictEqual(logic.classifyAnswer(false, { code: 'wpcap_checkout_failed', message: 'Ausverkauft', data: { status: 409 } }), 'error');
	assert.strictEqual(logic.classifyAnswer(false, { code: 'rest_invalid_param', message: 'x' }), 'error');
	assert.strictEqual(logic.classifyAnswer(false, null), 'unknown', '502/504 HTML page');
	assert.strictEqual(logic.classifyAnswer(false, { error: 'blocked' }), 'unknown', 'WAF JSON');
});

test('A309: an HTML answer with HTTP 200 shows no "thank you" and keeps the key for the retry', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(response(200, '<html>cached page</html>', { json: false }));
	await submit(env, form);
	assert.strictEqual(env.document.querySelector('.wpcap-confirmation'), null, 'no confirmation panel');
	assert.ok(form.querySelector('.wpcap-error-summary'), 'an error summary is shown');
	assert.strictEqual(form.hidden, false);
	await submit(env, form);
	assert.strictEqual(env.requests.length, 2);
	assert.strictEqual(env.requests[1].body.idempotency_key, env.requests[0].body.idempotency_key, 'the retry continues the same order');
	assert.ok(env.document.querySelector('.wpcap-confirmation'), 'the second (valid) answer is confirmed');
});

test('A309: JSON without order number and HTML error pages keep the key; API errors end the attempt', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(response(200, {}), response(504, '<html>Gateway Timeout</html>', { json: false }), response(409, { code: 'wpcap_checkout_failed', message: 'Ausverkauft.', data: { status: 409 } }));
	await submit(env, form);
	assert.strictEqual(env.document.querySelector('.wpcap-confirmation'), null);
	await submit(env, form);
	await submit(env, form);
	const keys = env.requests.map((r) => r.body.idempotency_key);
	assert.strictEqual(keys[1], keys[0], 'kept after {}');
	assert.strictEqual(keys[2], keys[0], 'kept after a 504 HTML page');
	assert.ok(/Ausverkauft/.test(form.querySelector('.wpcap-error-summary').textContent));
	await submit(env, form);
	assert.notStrictEqual(env.requests[3].body.idempotency_key, keys[0], 'a new attempt after the API error');
});

test('A309/A310: a changed form after an unclear answer is a new order', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(response(200, '<html></html>', { json: false }));
	await submit(env, form, { qty: 1 });
	await submit(env, form, { qty: 2 });
	assert.notStrictEqual(env.requests[1].body.idempotency_key, env.requests[0].body.idempotency_key);
});

test('A310: the redirect to the payment page keeps the key and stores no personal data', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(stripe());
	await submit(env, form, { email: 'privat@example.com' });
	assert.deepStrictEqual(env.assigned, ['https://checkout.stripe.test/c/pay/cs_1']);
	assert.strictEqual(form.dataset.key, env.requests[0].body.idempotency_key, 'key kept after the redirect');
	const stored = env.session.dump();
	assert.deepStrictEqual(Object.keys(stored), ['wpcap-pending-7']);
	assert.ok(!/privat@example\.com/.test(stored['wpcap-pending-7']), 'no e-mail address in sessionStorage');
});

test('A310: back from Stripe (bfcache) shows the pending order; sending again continues it', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(stripe(), stripe({ reused: true }));
	await submit(env, form);
	// The bfcache keeps the DOM as it was when leaving: "Weiterleitung …".
	form.querySelector('.wpcap-message').textContent = 'Weiterleitung';
	form.querySelector('button[type="submit"]').disabled = true;
	env.fire('pageshow', { persisted: true });
	const notice = form.querySelector('.wpcap-pending-order');
	assert.ok(notice, 'pending notice shown');
	assert.ok(/K7Q2/.test(notice.textContent), 'with the order number');
	assert.strictEqual(notice.querySelector('a').getAttribute('href'), 'https://checkout.stripe.test/c/pay/cs_1', 'payment can be continued');
	assert.strictEqual(form.querySelector('.wpcap-message').textContent, '');
	assert.strictEqual(form.querySelector('button[type="submit"]').disabled, false);
	await submit(env, form);
	assert.strictEqual(env.requests[1].body.idempotency_key, env.requests[0].body.idempotency_key, 'no second order');
});

test('A310: a changed selection after "back" starts a new order and drops the notice', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(stripe(), response(201, { reference: 'ZZ99', status: 'complete' }));
	await submit(env, form, { qty: 1 });
	env.fire('pageshow', { persisted: true });
	await submit(env, form, { qty: 3 });
	assert.notStrictEqual(env.requests[1].body.idempotency_key, env.requests[0].body.idempotency_key);
	assert.deepStrictEqual(Object.keys(env.session.dump()), [], 'nothing pending after the new order');
});

test('A310: after a reload (no bfcache) the pending order is shown and its key reused', async () => {
	const first = loadCheckout();
	first.answers.push(stripe());
	await submit(first, first.forms[0]);
	const env = loadCheckout({ session: first.session.dump() });
	const form = env.forms[0];
	assert.ok(form.querySelector('.wpcap-pending-order'), 'notice after reload');
	await submit(env, form);
	assert.strictEqual(env.requests[0].body.idempotency_key, first.requests[0].body.idempotency_key);
});

test('A310: the return page after payment clears the pending order, an aborted payment keeps it', async () => {
	const first = loadCheckout();
	first.answers.push(stripe());
	await submit(first, first.forms[0]);
	const stored = first.session.dump();
	const paid = loadCheckout({ session: stored, search: '?wpcap_payment=success&order=abc' });
	assert.strictEqual(paid.forms[0].querySelector('.wpcap-pending-order'), null);
	assert.deepStrictEqual(Object.keys(paid.session.dump()), []);
	const aborted = loadCheckout({ session: stored, search: '?wpcap_payment=cancel&order=abc' });
	assert.ok(aborted.forms[0].querySelector('.wpcap-pending-order'), 'aborted at the provider: still pending, can be continued');
});

test('A310: an expired hold is not offered any more', async () => {
	const first = loadCheckout();
	first.answers.push(stripe({ hold_minutes: 30 }));
	await submit(first, first.forms[0]);
	first.advance(31 * 60000);
	first.fire('pageshow', { persisted: true });
	assert.strictEqual(first.forms[0].querySelector('.wpcap-pending-order'), null);
	assert.deepStrictEqual(Object.keys(first.session.dump()), [], 'expired entry removed');
});

test('A310: a second submit while the first request runs is ignored', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	let release;
	env.fetchImpl = () => new Promise((resolve) => { release = () => resolve(response(201, { reference: 'AB', status: 'complete' })); });
	form.querySelector('.wpcap-qty').value = '1';
	form.querySelector('[name="email"]').value = 'a@example.com';
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	assert.strictEqual(env.requests.length, 1);
	release();
	await flush();
});

test('A554: the checkout sends its page as return_url (without fragment), outside the key fingerprint', async () => {
	const env = loadCheckout();
	env.window.location.href = 'https://example.test/tickets/?lang=de#wpcap-occ-7';
	env.answers.push(response(200, '<html></html>', { json: false }));
	await submit(env, env.forms[0]);
	env.window.location.href = 'https://example.test/tickets/?lang=de';
	await submit(env, env.forms[0]);
	assert.strictEqual(env.requests[0].body.return_url, 'https://example.test/tickets/?lang=de');
	assert.strictEqual(env.requests[1].body.idempotency_key, env.requests[0].body.idempotency_key);
});

test('A576: the shown unit prices of the selected types travel with the order', async () => {
	const env = loadCheckout({ cards: [{ types: [{ id: 5, price: 2500, name: 'Standard' }, { id: 6, price: 900, name: 'Ermäßigt' }] }] });
	await submit(env, env.forms[0], { qty: 2 });
	assert.deepStrictEqual(env.requests[0].body.expected_prices, { 5: 2500 }, 'only selected types, in minor units');
});

test('A576: price_changed shows the message, takes over the new price and the next submit is a new attempt', async () => {
	const env = loadCheckout();
	const form = env.forms[0];
	env.answers.push(response(409, { code: 'wpcap_price_changed', message: 'Der Preis hat sich seit dem Laden der Seite geändert („Standard“ jetzt 30,00 €).', data: { status: 409, field: 'items', prices: { 5: { shown_minor: 2500, unit_minor: 3000, formatted: '30,00 €' } } } }));
	await submit(env, form, { qty: 2 });
	assert.ok(/30,00/.test(form.querySelector('.wpcap-error-summary').textContent), 'new price in the error summary');
	assert.strictEqual(form.querySelector('.wpcap-qty').dataset.price, '3000');
	assert.ok(/60,00/.test(form.querySelector('.wpcap-estimate').textContent), 'estimate with the new price');
	assert.strictEqual(env.document.querySelector('.wpcap-confirmation'), null);
	await submit(env, form, { qty: 2 });
	assert.deepStrictEqual(env.requests[1].body.expected_prices, { 5: 3000 });
	assert.notStrictEqual(env.requests[1].body.idempotency_key, env.requests[0].body.idempotency_key);
});

