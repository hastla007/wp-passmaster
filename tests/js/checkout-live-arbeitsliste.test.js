// MERGE-CHECK: Arbeitsliste version of tests/js/checkout-live.test.js (both development lines tested the same script with
// their own harness). Keep the checks that match the merged script; the other file is the audit-branch version.
/**
 * Checkout script (assets/checkout.js) on a card from a page cache or CDN (fake DOM fake-dom-r2.js):
 * A456 after loading, the live state (GET …/checkout/live) switches quantities, sold-out types, the
 *      order form, the notes, payment methods, header facts/badges and the waitlist form;
 * A457 a 409 wpcap_terms_changed (or a newer version in the live state) reloads the legal texts
 *      from …/checkout/options into the card, unchecks the consent and lets the buyer confirm again;
 * A458 the current (time-dependent) prices replace the cached ones before the buyer orders, and a
 *      409 price_changed also updates the price shown next to the ticket type.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadCheckout, flush, response, FakeEvent } = require('./checkout-harness.js');

const CONFIG = { liveUrl: '/wp-json/pass-captain/v1/checkout/live', optionsUrl: '/wp-json/pass-captain/v1/checkout/options' };
const I18N = { basePrice: 'statt %s', providerLegend: 'Zahlungsart', termsUpdated: 'Bedingungen geändert – bitte erneut bestätigen.' };

/** Markup of templates/event-card.php (A456 structure) for one date with types 5 and 6. */
function liveCard(h, o) {
	o = Object.assign({ occurrence: 7, soldout: [], providers: ['stripe'], terms: 'v1', badges: [['next', 'Nächster Termin']] }, o || {});
	const id = 'wpcap-' + o.occurrence;
	const type = (tid, name, price) => {
		const sold = o.soldout.indexOf(tid) !== -1;
		const attrs = { id: id + '-' + tid, class: 'wpcap-qty', type: 'number', min: '0', max: sold ? '0' : '10', value: '0', 'data-type': String(tid), 'data-price': String(price), 'data-currency': 'EUR', 'data-name': name, 'data-fields': '[]' };
		const input = h('input', attrs);
		if (sold) input.disabled = true;
		return h('div', { class: 'wpcap-type wpcap-field', 'data-type': String(tid) }, [
			h('label', { for: id + '-' + tid }, [name + ' — ', h('span', { class: 'wpcap-type-price' }, [(price / 100).toFixed(2).replace('.', ',') + ' €']), ' ', h('span', { class: 'wpcap-price-label', hidden: true }), ' ', h('small', { class: 'wpcap-base-price', hidden: true })]),
			h('span', { class: 'wpcap-soldout', hidden: !sold }, ['Ausverkauft']),
			h('span', { class: 'wpcap-qty-box', hidden: sold }, [input]),
		]);
	};
	const allSold = o.soldout.length === 2;
	const providers = o.providers.length === 1 ? [h('input', { type: 'hidden', name: 'provider', value: o.providers[0] })] : [];
	const buy = h('div', { class: 'wpcap-buy', hidden: allSold || !o.providers.length }, [
		h('p', { class: 'wpcap-estimate' }),
		h('div', { class: 'wpcap-field' }, [h('label', { for: id + '-email' }, ['E-Mail *']), h('input', { id: id + '-email', name: 'email', type: 'email', required: true })]),
		h('div', { class: 'wpcap-attendees' }),
		h('div', { class: 'wpcap-provider-slot' }, providers),
		h('details', { class: 'wpcap-legal', 'data-legal': 'consumer_info' }, [h('summary', {}, ['Verbraucherinformationen']), h('div', { class: 'wpcap-legal-text' }, ['Alte Infos'])]),
		h('input', { type: 'hidden', name: 'locale', value: 'de_DE' }),
		h('input', { type: 'hidden', name: 'terms_version', value: o.terms }),
		h('details', { class: 'wpcap-legal', 'data-legal': 'terms' }, [h('summary', {}, ['Bedingungen']), h('div', { class: 'wpcap-legal-text' }, ['Alte AGB'])]),
		h('div', { class: 'wpcap-field wpcap-terms' }, [h('input', { id: id + '-terms', type: 'checkbox', name: 'accept_terms', value: '1', required: true }), h('label', { for: id + '-terms' }, ['Ich akzeptiere die Bedingungen.'])]),
		h('button', { type: 'submit', class: 'wpcap-submit' }, ['Bestellen']),
		h('p', { class: 'wpcap-message' }),
	]);
	const form = h('form', { class: 'wpcap-checkout-form', id, 'data-occurrence': String(o.occurrence), 'data-heading-level': '3' }, [
		h('fieldset', { class: 'wpcap-types' }, [type(5, 'Standard', 2500), type(6, 'Ermäßigt', 1500)]),
		h('p', { class: 'wpcap-qty-live' }),
		h('p', { class: 'wpcap-soldout-note', hidden: !allSold }, ['Dieser Termin ist ausverkauft.']),
		h('p', { class: 'wpcap-no-payment', hidden: allSold || !!o.providers.length }, ['Online-Verkauf derzeit nicht möglich.']),
		buy,
	]);
	const select = h('select', { id: id + '-waitlist-type', name: 'ticket_type_id' }, [
		h('option', { value: '5', 'data-max': '4' }, ['Standard']),
		h('option', { value: '6', 'data-max': '2' }, ['Ermäßigt']),
	]);
	[5, 6].forEach((tid, i) => { if (o.soldout.indexOf(tid) === -1) { select.children[i].disabled = true; select.children[i].hidden = true; } });
	select.value = o.soldout.length ? String(o.soldout[0]) : '5';
	const waitlist = h('form', { class: 'wpcap-waitlist-form', id: id + '-waitlist', 'data-event': '3', 'data-occurrence': String(o.occurrence), hidden: !o.soldout.length }, [
		h('fieldset', {}, [
			h('div', { class: 'wpcap-field wpcap-waitlist-type', hidden: o.soldout.length < 2 }, [select]),
			h('div', { class: 'wpcap-field' }, [h('input', { name: 'quantity', type: 'number', min: '1', max: '4', value: '1' })]),
			h('div', { class: 'wpcap-field' }, [h('input', { name: 'email', type: 'email', required: true })]),
			h('button', { type: 'submit' }, ['Auf die Warteliste setzen']),
			h('p', { class: 'wpcap-message' }),
		]),
	]);
	const badges = o.badges.length ? [h('ul', { class: 'wpcap-badges' }, o.badges.map((b) => h('li', { class: 'wpcap-badge wpcap-badge-' + b[0] }, [b[1]])))] : [];
	const header = h('header', { class: 'wpcap-event-header' }, [
		h('h2', { class: 'wpcap-event-title' }, ['Konzert']),
		...badges,
		h('dl', { class: 'wpcap-event-facts' }, [
			h('div', { class: 'wpcap-fact-price' }, [h('dt', {}, ['Preis']), h('dd', {}, ['ab 15,00 €'])]),
			h('div', { class: 'wpcap-fact-availability' }, [h('dt', {}, ['Verfügbarkeit']), h('dd', {}, [allSold ? 'Ausverkauft' : 'Tickets verfügbar'])]),
		]),
	]);
	return h('article', { class: 'wpcap-event' }, [header, form, waitlist]);
}

const typeState = (max, price, extra) => Object.assign({ max, available: max, price_minor: price, price: (price / 100).toFixed(2).replace('.', ',') + ' €', base_price: '', price_label: '', currency: 'EUR' }, extra || {});
const liveAnswer = (occurrences, legal) => response(200, { occurrences, legal: Object.assign({ require_terms: true, terms_version: 'v1', locale: 'de_DE' }, legal || {}), server_time: '2026-11-16T10:00:00+00:00' });
const state = (extra) => Object.assign({ on_sale: true, types: { 5: typeState(10, 2500), 6: typeState(10, 1500) }, availability: 'Tickets verfügbar', price: 'ab 15,00 €', badges: [], all_soldout: false, providers: [{ id: 'stripe', title: 'Karte' }], waitlist: false, waitlist_types: [], max_total: 0 }, extra || {});

/** Loads one card; live: state of occurrence 7 (or a function url => response); returns env. */
async function load(cardOptions, live, options) {
	options = options || {};
	const env = loadCheckout({
		markup: (h) => [liveCard(h, cardOptions)],
		config: options.noLive ? { optionsUrl: CONFIG.optionsUrl } : CONFIG,
		i18n: I18N,
		fetch: (url, init, e) => {
			if (url.indexOf(CONFIG.liveUrl) === 0) return typeof live === 'function' ? live(url) : liveAnswer({ 7: live }, options.legal);
			if (url.indexOf(CONFIG.optionsUrl) === 0) return response(200, options.legalTexts || { terms_version: 'v2', terms_html: '<p>Neue AGB</p>', consumer_info_html: '<p>Neue Infos</p>', require_terms: true, locale: 'de_DE', providers: [] });
			return e.answers.length ? e.answers.shift() : response(201, { reference: 'AB12CD', status: 'complete', payment_status: 'paid' });
		},
	});
	await flush();
	return env;
}
const liveRequests = (env) => env.requests.filter((r) => r.url.indexOf(CONFIG.liveUrl) === 0);

test('A456: the card asks the no-store live endpoint once for all dates on the page', async () => {
	const env = await load({}, state());
	const requests = liveRequests(env);
	assert.strictEqual(requests.length, 1);
	assert.match(requests[0].url, /[?&]occurrences=7(&|$)/);
	assert.match(requests[0].url, /[?&]locale=de_DE/);
	assert.strictEqual(requests[0].options.method, 'GET');
	assert.strictEqual(requests[0].options.cache, 'no-store', 'the browser must not answer from its cache');
	const without = await load({}, state(), { noLive: true });
	assert.strictEqual(liveRequests(without).length, 0, 'no live URL configured (older setup): nothing is requested');
});

test('A456: a cached sold-out card becomes bookable when seats are free again', async () => {
	const env = await load({ soldout: [5, 6] }, state({ types: { 5: typeState(4, 2500), 6: typeState(0, 1500) }, availability: 'Nur noch 4 Plätze frei', badges: [{ key: 'few_left', label: 'Wenige Plätze' }] }));
	const form = env.forms[0];
	const [standard, reduced] = form.querySelectorAll('.wpcap-qty');
	assert.strictEqual(standard.max, '4');
	assert.strictEqual(standard.disabled, false);
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="5"] .wpcap-qty-box').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="5"] .wpcap-soldout').hidden, true);
	assert.strictEqual(reduced.disabled, true, 'the still sold-out type stays closed');
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="6"] .wpcap-soldout').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-buy').hidden, false, 'the order form is shown');
	assert.strictEqual(form.querySelector('.wpcap-soldout-note').hidden, true);
	const card = env.document.querySelector('.wpcap-event');
	assert.strictEqual(card.querySelector('.wpcap-fact-availability dd').textContent, 'Nur noch 4 Plätze frei');
	const badges = card.querySelectorAll('.wpcap-badges li').map((li) => li.textContent);
	assert.deepStrictEqual(badges, ['Nächster Termin', 'Wenige Plätze'], 'live badges replace the cached ones; "Nächster Termin" is kept');
	const waitlist = card.querySelector('.wpcap-waitlist-form');
	assert.strictEqual(waitlist.hidden, true, 'no waitlist while seats are free');
});

test('A456: a cached bookable card shows "sold out" and the waitlist for the sold-out type', async () => {
	const env = await load({}, state({ types: { 5: typeState(0, 2500), 6: typeState(3, 1500) }, waitlist: true, waitlist_types: [5], availability: 'Nur noch 3 Plätze frei' }));
	const form = env.forms[0];
	const card = env.document.querySelector('.wpcap-event');
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="5"] .wpcap-qty-box').hidden, true);
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="5"] .wpcap-soldout').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-buy').hidden, false, 'type 6 can still be ordered');
	const waitlist = card.querySelector('.wpcap-waitlist-form');
	assert.strictEqual(waitlist.hidden, false, 'the waitlist form is offered although the cached page had none visible');
	const options = waitlist.querySelectorAll('option');
	assert.deepStrictEqual(options.map((o) => o.disabled), [false, true], 'only the sold-out type can be chosen');
	assert.strictEqual(waitlist.elements.ticket_type_id.value, '5');
	assert.strictEqual(waitlist.querySelector('.wpcap-waitlist-type').hidden, true, 'one type: no choice shown');
	assert.strictEqual(waitlist.elements.quantity.max, '4', 'quantity limit of the chosen type');
});

test('A456: everything sold out → note instead of the form; no payment method or not on sale → "not possible"', async () => {
	const sold = await load({}, state({ types: { 5: typeState(0, 2500), 6: typeState(0, 1500) }, all_soldout: true, waitlist: true, waitlist_types: [5, 6] }));
	let form = sold.forms[0];
	assert.strictEqual(form.querySelector('.wpcap-buy').hidden, true);
	assert.strictEqual(form.querySelector('.wpcap-soldout-note').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-no-payment').hidden, true);
	assert.strictEqual(sold.document.querySelector('.wpcap-waitlist-type').hidden, false, 'two sold-out types: the type can be chosen');
	const none = await load({}, state({ providers: [] }));
	form = none.forms[0];
	assert.strictEqual(form.querySelector('.wpcap-buy').hidden, true);
	assert.strictEqual(form.querySelector('.wpcap-no-payment').hidden, false);
	assert.ok(form.querySelectorAll('.wpcap-qty').every((q) => q.disabled), 'quantities are disabled');
	const closed = await load({}, { on_sale: false });
	form = closed.forms[0];
	assert.strictEqual(form.querySelector('.wpcap-buy').hidden, true);
	assert.strictEqual(form.querySelector('.wpcap-no-payment').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-soldout-note').hidden, true);
});

test('A456: payment methods follow the live state (cached card without any → radio buttons)', async () => {
	const env = await load({ providers: [] }, state({ providers: [{ id: 'stripe', title: 'Karte' }, { id: 'manual', title: 'Überweisung' }] }));
	const form = env.forms[0];
	const radios = form.querySelectorAll('.wpcap-provider-slot [name="provider"]');
	assert.deepStrictEqual(radios.map((r) => r.value), ['stripe', 'manual']);
	assert.strictEqual(radios[0].checked, true);
	assert.strictEqual(form.querySelector('.wpcap-buy').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-no-payment').hidden, true);
	assert.ok(form.querySelector('.wpcap-provider-slot legend'), 'the group is labelled');
});

test('A458: current prices replace the cached ones before ordering; the order sends them as expected prices', async () => {
	const env = await load({}, state({ types: { 5: typeState(10, 2900, { base_price: '25,00 €', price_label: 'Abendkasse' }), 6: typeState(10, 1500) } }));
	const form = env.forms[0];
	const row = form.querySelector('.wpcap-type[data-type="5"]');
	assert.strictEqual(row.querySelector('.wpcap-type-price').textContent, '29,00 €');
	assert.strictEqual(row.querySelector('.wpcap-base-price').textContent, 'statt 25,00 €');
	assert.strictEqual(row.querySelector('.wpcap-base-price').hidden, false);
	assert.strictEqual(row.querySelector('.wpcap-price-label').textContent, 'Abendkasse');
	const qty = row.querySelector('.wpcap-qty');
	assert.strictEqual(qty.dataset.price, '2900');
	qty.value = '2';
	qty.dispatchEvent(new FakeEvent('input', { bubbles: true }));
	assert.match(form.querySelector('.wpcap-estimate').textContent, /58,00 €/, 'the estimate uses the current price');
	form.querySelector('[name="email"]').value = 'buyer@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	const order = env.requests.filter((r) => r.url === '/checkout')[0];
	assert.deepStrictEqual(order.body.expected_prices, { 5: 2900 });
});

test('A458: a 409 price_changed also updates the price shown next to the ticket type', async () => {
	const env = await load({}, state());
	const form = env.forms[0];
	env.answers.push(response(409, { code: 'wpcap_price_changed', message: 'Der Preis hat sich geändert.', data: { status: 409, field: 'items', prices: { 5: { shown_minor: 2500, unit_minor: 3200, formatted: '32,00 €' } } } }));
	const qty = form.querySelector('.wpcap-qty');
	qty.value = '1';
	qty.dispatchEvent(new FakeEvent('input', { bubbles: true }));
	form.querySelector('[name="email"]').value = 'buyer@example.com';
	form.querySelector('[name="accept_terms"]').checked = true;
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="5"] .wpcap-type-price').textContent, '32,00 €');
	assert.strictEqual(qty.dataset.price, '3200');
});

test('A457: 409 terms_changed reloads the legal texts into the card; the buyer confirms again and the order goes through', async () => {
	const env = await load({}, state());
	const form = env.forms[0];
	env.answers.push(response(409, { code: 'wpcap_terms_changed', message: 'Die Bedingungen wurden geändert. Bitte lade die Seite neu.', data: { status: 409, field: 'accept_terms' } }));
	const qty = form.querySelector('.wpcap-qty');
	qty.value = '1';
	qty.dispatchEvent(new FakeEvent('input', { bubbles: true }));
	form.querySelector('[name="email"]').value = 'buyer@example.com';
	const terms = form.querySelector('[name="accept_terms"]');
	terms.checked = true;
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	assert.strictEqual(form.querySelector('[data-legal="terms"] .wpcap-legal-text').textContent, 'Neue AGB');
	assert.strictEqual(form.querySelector('[data-legal="consumer_info"] .wpcap-legal-text').textContent, 'Neue Infos');
	assert.strictEqual(form.querySelector('[data-legal="terms"]').open, true, 'the new terms are opened');
	assert.strictEqual(form.querySelector('input[name="terms_version"]').value, 'v2');
	assert.strictEqual(terms.checked, false, 'consent to the old texts is withdrawn');
	assert.match(form.querySelector('.wpcap-error-summary').textContent, /bitte erneut bestätigen/, 'no "reload the page" (the reload would show the cached page again)');
	const legalRequest = env.requests.filter((r) => r.url.indexOf(CONFIG.optionsUrl) === 0)[0];
	assert.match(legalRequest.url, /locale=de_DE/);
	terms.checked = true;
	form.dispatchEvent(new FakeEvent('submit', { bubbles: true }));
	await flush();
	const orders = env.requests.filter((r) => r.url === '/checkout');
	assert.strictEqual(orders.length, 2);
	assert.strictEqual(orders[1].body.terms_version, 'v2', 'the second attempt names the version now shown');
	assert.ok(env.document.querySelector('.wpcap-confirmation'), 'order confirmed');
});

test('A457: a newer terms version in the live state reloads the texts before the buyer orders', async () => {
	const env = await load({}, state(), { legal: { terms_version: 'v2' } });
	const form = env.forms[0];
	assert.strictEqual(form.querySelector('[data-legal="terms"] .wpcap-legal-text').textContent, 'Neue AGB');
	assert.strictEqual(form.querySelector('input[name="terms_version"]').value, 'v2');
	const same = await load({}, state());
	assert.strictEqual(same.requests.filter((r) => r.url.indexOf(CONFIG.optionsUrl) === 0).length, 0, 'same version: texts are not reloaded');
});

test('A456: without an answer (offline, 429, HTML from a cache) the card stays as rendered', async () => {
	const env = await load({ soldout: [5] }, () => response(429, { code: 'wpcap_rate_limited', message: 'x', data: { status: 429 } }));
	const form = env.forms[0];
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="5"] .wpcap-soldout').hidden, false);
	assert.strictEqual(form.querySelector('.wpcap-type[data-type="6"] .wpcap-qty-box').hidden, false);
	const html = await load({}, () => response(200, '<html>cached</html>', { json: false }));
	assert.strictEqual(html.forms[0].querySelector('.wpcap-buy').hidden, false);
});
