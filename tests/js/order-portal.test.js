'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { load, flush, mockFetch, Event } = require('./lib/load');

const STRINGS = {
	transferConfirm: 'Ticket wirklich an %s übertragen?', transferConfirmButton: 'Übertragen', transferred: 'Übertragen.', transferFailed: 'Fehlgeschlagen.',
	assignedRotated: 'Gespeichert, neuer Code.', assignedKept: 'Gespeichert.', assignFailed: 'Fehler.',
	resendConfirm: 'Tickets erneut senden?', resendConfirmButton: 'Senden', sending: 'Wird gesendet …', resent: 'Gesendet an %s.', resendFailed: 'Fehler.',
	billingSaved: 'Rechnungsdaten gespeichert.', billingCleared: 'Entfernt.', billingFailed: 'Fehler.',
};
const URLS = { transfer: '/transfer', assign: '/assign', resend: '/resend', billing: '/billing' };

/** Order portal markup as rendered by OrderPortal::render(). */
function portal(opts) {
	opts = opts || {};
	return load('assets/order-portal.js', {
		window: Object.assign({ wpcapPortal: { strings: STRINGS, token: 'tok123', urls: URLS } }, opts.window || {}),
		fetch: opts.fetch,
		setup(doc, h) {
			doc.body.appendChild(h('main', [
				h('form.wpcap-resend-form', [h('button', { type: 'submit' }, 'Erneut senden'), h('p.message', { role: 'status' })]),
				h('form.wpcap-assignment-form', [
					h('input', { type: 'hidden', name: 'ticket_public_id', value: 'T1' }),
					h('label', ['Name', h('input', { name: 'attendee_name', value: 'Alex' })]),
					h('label', ['E-Mail', h('input', { name: 'attendee_email', type: 'email', value: 'alex@example.com' })]),
					h('label', ['Ernährung', h('input', { 'data-answer': 'diet', value: 'vegan' })]),
					h('label', ['Rollstuhl', h('input', { type: 'checkbox', value: '1', 'data-answer': 'wheelchair' })]),
					h('button', { type: 'submit' }, 'Zuweisen'), h('p.message'),
				]),
				h('form.wpcap-transfer-form', [
					h('input', { type: 'hidden', name: 'ticket_public_id', value: 'T1' }),
					h('label', ['An E-Mail', h('input', { name: 'recipient_email', type: 'email', required: true })]),
					h('button', { type: 'submit' }, 'Übertragen'), h('p.message'),
				]),
				h('form.wpcap-billing-form', [
					h('label', [h('input', { type: 'checkbox', name: 'enabled' }), 'Rechnung']),
					h('div.billing-fields', [h('input', { 'data-billing': 'company', value: ' ACME ' }), h('input', { 'data-billing': 'vat_id', value: 'ATU1' })]),
					h('button', { type: 'submit' }, 'Speichern'), h('p.message'),
				]),
			]));
			if (!opts.noDialog) {
				doc.body.appendChild(h('dialog#wpcap-confirm', [h('form', { method: 'dialog' }, [h('p#wpcap-confirm-text'), h('button', { value: 'cancel' }, 'Abbrechen'), h('button#wpcap-confirm-ok', { value: 'ok' })])]));
			}
		},
	});
}
const submit = (form) => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

test('transfer: confirm dialog shows recipient, cancel sends nothing, focus returns to the opener', async () => {
	const fetch = mockFetch(() => ({ json: {} }));
	const env = portal({ fetch });
	const form = env.document.querySelector('.wpcap-transfer-form');
	const dialog = env.document.getElementById('wpcap-confirm');
	form.recipient_email.value = 'friend@example.com';
	const opener = form.querySelector('button');
	opener.focus();
	submit(form);
	await flush(2);
	assert.equal(dialog.open, true);
	assert.equal(env.document.getElementById('wpcap-confirm-text').textContent, 'Ticket wirklich an friend@example.com übertragen?');
	assert.equal(env.document.getElementById('wpcap-confirm-ok').textContent, 'Übertragen');
	assert.equal(env.document.activeElement.getAttribute('value'), 'cancel', 'the safe choice has focus');
	dialog.close('cancel');
	await flush();
	assert.equal(fetch.calls.length, 0);
	assert.equal(env.document.activeElement, opener);
});

test('transfer: confirmed request carries the access token in the header; form controls are removed afterwards', async () => {
	const fetch = mockFetch(() => ({ json: { ok: true } }));
	const env = portal({ fetch });
	const form = env.document.querySelector('.wpcap-transfer-form');
	form.recipient_email.value = 'friend@example.com';
	submit(form);
	await flush(2);
	env.document.getElementById('wpcap-confirm').close('ok');
	await flush();
	assert.equal(fetch.calls[0].url, '/transfer');
	assert.deepEqual(fetch.calls[0].body, { ticket_public_id: 'T1', recipient_email: 'friend@example.com' });
	// SEC-7: the access token travels in a header, never in the body or the URL.
	assert.equal(fetch.calls[0].init.headers['X-WPCAP-Access-Token'], 'tok123');
	assert.ok(!fetch.calls[0].url.includes('tok123'));
	assert.equal(form.querySelector('.message').textContent, 'Übertragen.');
	assert.equal(form.querySelectorAll('label, button').length, 0);
});

test('transfer: invalid e-mail is caught by native validation; server errors are shown', async () => {
	const fetch = mockFetch(() => ({ status: 409, json: { message: 'Bereits übertragen' } }));
	const env = portal({ fetch });
	const form = env.document.querySelector('.wpcap-transfer-form');
	submit(form);
	await flush();
	assert.equal(env.document.getElementById('wpcap-confirm').open, undefined, 'no dialog for an empty recipient');
	form.recipient_email.value = 'friend@example.com';
	submit(form);
	await flush(2);
	env.document.getElementById('wpcap-confirm').close('ok');
	await flush();
	assert.equal(form.querySelector('.message').textContent, 'Bereits übertragen');
	assert.equal(form.querySelector('button').disabled, false);
});

test('transfer without <dialog> support falls back to window.confirm', async () => {
	let asked = '';
	const fetch = mockFetch(() => ({ json: {} }));
	const env = portal({ fetch, noDialog: true, window: { confirm: (text) => { asked = text; return false; } } });
	const form = env.document.querySelector('.wpcap-transfer-form');
	form.recipient_email.value = 'x@example.com';
	submit(form);
	await flush();
	assert.equal(asked, 'Ticket wirklich an x@example.com übertragen?');
	assert.equal(fetch.calls.length, 0);
});

test('assign attendee: answers incl. checkboxes; message depends on code rotation', async () => {
	let rotated = true;
	const fetch = mockFetch(() => ({ json: { code_rotated: rotated } }));
	const env = portal({ fetch });
	const form = env.document.querySelector('.wpcap-assignment-form');
	form.querySelector('[data-answer="wheelchair"]').checked = true;
	submit(form);
	await flush();
	assert.deepEqual(fetch.calls[0].body, { ticket_public_id: 'T1', attendee_name: 'Alex', attendee_email: 'alex@example.com', answers: { diet: 'vegan', wheelchair: '1' } });
	assert.equal(form.querySelector('.message').textContent, 'Gespeichert, neuer Code.');
	rotated = false;
	submit(form);
	await flush();
	assert.equal(form.querySelector('.message').textContent, 'Gespeichert.');
});

test('resend: confirmation, progress text and masked target address', async () => {
	const fetch = mockFetch(() => ({ json: { email: 'a***@example.com' } }));
	const env = portal({ fetch });
	const form = env.document.querySelector('.wpcap-resend-form');
	submit(form);
	await flush(2);
	assert.equal(env.document.getElementById('wpcap-confirm-text').textContent, 'Tickets erneut senden?');
	env.document.getElementById('wpcap-confirm').close('ok');
	await flush();
	assert.equal(fetch.calls[0].url, '/resend');
	assert.deepEqual(fetch.calls[0].body, {});
	assert.equal(form.querySelector('.message').textContent, 'Gesendet an a***@example.com.');
	assert.equal(form.querySelector('button').disabled, false);
});

test('billing: toggle shows the fields; server field error marks and focuses the input', async () => {
	const fetch = mockFetch(() => ({ status: 400, json: { message: 'UID ungültig', data: { field: 'billing_vat_id' } } }));
	const env = portal({ fetch });
	const form = env.document.querySelector('.wpcap-billing-form');
	const box = form.querySelector('.billing-fields');
	assert.equal(box.hidden, true);
	form.querySelector('[name="enabled"]').checked = true;
	form.querySelector('[name="enabled"]').dispatchEvent(new Event('change'));
	assert.equal(box.hidden, false);
	submit(form);
	await flush();
	assert.deepEqual(fetch.calls[0].body, { billing: { enabled: true, company: 'ACME', vat_id: 'ATU1' } });
	const vat = form.querySelector('[data-billing="vat_id"]');
	assert.equal(vat.getAttribute('aria-invalid'), 'true');
	assert.equal(env.document.activeElement, vat);
	assert.equal(form.querySelector('.message').textContent, 'UID ungültig');
});
