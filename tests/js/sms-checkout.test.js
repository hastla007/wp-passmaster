'use strict';
/* PRIV-16: SMS opt-in in the checkout with number verification by code (assets/sms/checkout-sms.js). */
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event, CustomEvent } = require('./lib/load');

const STRINGS = { sending: 'Sende …', sent: 'Code an %s gesendet.', checking: 'Prüfe …', verified: 'Bestätigt.', codeRequired: 'Code fehlt.', verifyRequired: 'Bitte Nummer bestätigen.', failed: 'Fehler.' };

function checkout(opts) {
	opts = opts || {};
	return load('assets/sms/checkout-sms.js', {
		window: { WPCAP_SMS: Object.assign({ phoneRequired: 'Nummer fehlt.', verify: true, requestUrl: '/req', confirmUrl: '/conf', strings: STRINGS }, opts.config || {}) },
		fetch: opts.fetch,
		setup(doc, h) {
			const verify = opts.noBox ? [] : [h('div.wpcap-sms-verify', { hidden: true }, [
				h('button.wpcap-sms-send', { type: 'button' }, 'Code senden'),
				h('input', { type: 'text', name: 'sms_code' }),
				h('button.wpcap-sms-confirm', { type: 'button' }, 'Bestätigen'),
				h('input', { type: 'hidden', name: 'sms_verification', value: '' }),
				h('p.wpcap-sms-status', { role: 'status' }),
			])];
			doc.body.appendChild(h('form#f', [h('fieldset.wpcap-sms', [
				h('input', { type: 'checkbox', name: 'sms_consent', value: '1' }),
				h('input', { type: 'tel', name: 'sms_phone' }),
			].concat(verify))]));
		},
	});
}
const click = (el) => el.dispatchEvent(new Event('click', { bubbles: true, cancelable: true }));
function payload(env) {
	const detail = { form: env.document.getElementById('f'), items: [], payload: {}, error: '', field: null };
	env.document.dispatchEvent(new CustomEvent('wpcap:checkout-payload', { detail }));
	return detail;
}
function tick(el, checked) { el.checked = checked; el.dispatchEvent(new Event('change', { bubbles: true })); }

test('verification box only shows with consent ticked', () => {
	const env = checkout();
	const box = env.document.querySelector('.wpcap-sms-verify');
	assert.equal(box.hidden, true);
	tick(env.document.querySelector('[name="sms_consent"]'), true);
	assert.equal(box.hidden, false);
	tick(env.document.querySelector('[name="sms_consent"]'), false);
	assert.equal(box.hidden, true);
});

test('without consent nothing is added to the payload', () => {
	const env = checkout();
	env.document.querySelector('[name="sms_phone"]').value = '+4915112345678';
	const d = payload(env);
	assert.equal(d.error, '');
	assert.deepEqual(d.payload, {});
});

test('consent without confirmed number blocks the order with a message on the code field', () => {
	const env = checkout();
	tick(env.document.querySelector('[name="sms_consent"]'), true);
	env.document.querySelector('[name="sms_phone"]').value = '+4915112345678';
	const d = payload(env);
	assert.equal(d.error, STRINGS.verifyRequired);
	assert.equal(d.field, env.document.querySelector('[name="sms_code"]'));
	assert.equal(d.payload.sms_consent, undefined);
});

test('request code, confirm it, then the payload carries consent, number and verification token', async () => {
	const fetch = mockFetch((url, init, body) => {
		if (url === '/req') return { status: 200, json: { token: 't'.repeat(64), phone_masked: '+49151••••78', expires_in: 600 } };
		if (url === '/conf') return body.code === '123456' ? { status: 200, json: { verified: true } } : { status: 400, json: { message: 'Code falsch.' } };
		return { status: 404 };
	});
	const env = checkout({ fetch });
	const doc = env.document;
	tick(doc.querySelector('[name="sms_consent"]'), true);
	doc.querySelector('[name="sms_phone"]').value = ' 0151 12345678 ';
	click(doc.querySelector('.wpcap-sms-send'));
	await flush();
	assert.deepEqual(fetch.calls[0].body, { phone: '0151 12345678', token: '' });
	assert.equal(fetch.calls[0].init.method, 'POST');
	assert.equal(doc.querySelector('.wpcap-sms-status').textContent, 'Code an +49151••••78 gesendet.');
	assert.equal(doc.activeElement, doc.querySelector('[name="sms_code"]'));
	assert.equal(payload(env).error, STRINGS.verifyRequired, 'code sent but not yet confirmed');

	doc.querySelector('[name="sms_code"]').value = '12';
	click(doc.querySelector('.wpcap-sms-confirm'));
	await flush();
	assert.equal(fetch.calls.length, 1, 'incomplete code is not sent');
	assert.equal(doc.querySelector('.wpcap-sms-status').textContent, STRINGS.codeRequired);

	doc.querySelector('[name="sms_code"]').value = '654321';
	click(doc.querySelector('.wpcap-sms-confirm'));
	await flush();
	assert.equal(doc.querySelector('.wpcap-sms-status').textContent, 'Code falsch.');
	assert.equal(payload(env).error, STRINGS.verifyRequired);

	doc.querySelector('[name="sms_code"]').value = '123 456';
	click(doc.querySelector('.wpcap-sms-confirm'));
	await flush();
	assert.deepEqual(fetch.calls[2].body, { token: 't'.repeat(64), code: '123456' });
	assert.equal(doc.querySelector('.wpcap-sms-status').textContent, STRINGS.verified);
	const d = payload(env);
	assert.equal(d.error, '');
	assert.deepEqual(d.payload, { sms_verification: 't'.repeat(64), sms_consent: true, sms_phone: '0151 12345678' });
});

test('changing the number after verification drops the token', async () => {
	const fetch = mockFetch((url) => (url === '/req' ? { json: { token: 'a'.repeat(64), phone_masked: 'x' } } : { json: { verified: true } }));
	const env = checkout({ fetch });
	const doc = env.document;
	const phone = doc.querySelector('[name="sms_phone"]');
	tick(doc.querySelector('[name="sms_consent"]'), true);
	phone.value = '+4915112345678';
	click(doc.querySelector('.wpcap-sms-send'));
	await flush();
	doc.querySelector('[name="sms_code"]').value = '111111';
	click(doc.querySelector('.wpcap-sms-confirm'));
	await flush();
	assert.equal(payload(env).error, '');
	phone.value = '+4915199999999';
	phone.dispatchEvent(new Event('input', { bubbles: true }));
	assert.equal(doc.querySelector('[name="sms_verification"]').value, '');
	assert.equal(payload(env).error, STRINGS.verifyRequired);
});

test('server errors on the code request are shown; empty number asks for the number first', async () => {
	const fetch = mockFetch(() => ({ status: 429, json: { message: 'Zu viele Codes.' } }));
	const env = checkout({ fetch });
	const doc = env.document;
	tick(doc.querySelector('[name="sms_consent"]'), true);
	click(doc.querySelector('.wpcap-sms-send'));
	await flush();
	assert.equal(fetch.calls.length, 0);
	assert.equal(doc.querySelector('.wpcap-sms-status').textContent, 'Nummer fehlt.');
	doc.querySelector('[name="sms_phone"]').value = '+4915112345678';
	click(doc.querySelector('.wpcap-sms-send'));
	await flush();
	assert.equal(doc.querySelector('.wpcap-sms-status').textContent, 'Zu viele Codes.');
	assert.equal(doc.querySelector('.wpcap-sms-send').disabled, false);
});

test('Enter in the code field confirms the code instead of submitting the order', async () => {
	const fetch = mockFetch((url) => (url === '/req' ? { json: { token: 'b'.repeat(64), phone_masked: 'x' } } : { json: { verified: true } }));
	const env = checkout({ fetch });
	const doc = env.document;
	tick(doc.querySelector('[name="sms_consent"]'), true);
	doc.querySelector('[name="sms_phone"]').value = '+4915112345678';
	click(doc.querySelector('.wpcap-sms-send'));
	await flush();
	const code = doc.querySelector('[name="sms_code"]');
	code.value = '222222';
	const ev = new Event('keydown', { bubbles: true, cancelable: true, key: 'Enter' });
	code.dispatchEvent(ev);
	await flush();
	assert.equal(ev.defaultPrevented, true);
	assert.equal(fetch.calls[1].url, '/conf');
});

test('verification off (setting): consent and number go into the payload as before', () => {
	const env = checkout({ config: { verify: false }, noBox: true });
	tick(env.document.querySelector('[name="sms_consent"]'), true);
	env.document.querySelector('[name="sms_phone"]').value = '+4915112345678';
	const d = payload(env);
	assert.equal(d.error, '');
	assert.deepEqual(d.payload, { sms_consent: true, sms_phone: '+4915112345678' });
});
