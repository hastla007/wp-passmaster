/**
 * Privacy-friendly commerce events for the native checkout (docs/TRACKING.md).
 *
 * Fires `wpcap:track` on document with detail { event, currency, value, items, transaction_id? } for
 * view_item, add_to_cart, remove_from_cart, begin_checkout and purchase – without personal data (no
 * e-mail, names or order ids that open the portal). No tracker is loaded: the site decides (after
 * consent) whether to forward the events, e.g. to its tag manager.
 *
 * Attribution: utm_source / utm_medium / utm_campaign of the landing URL and the referrer host are kept
 * for the browser session (sessionStorage) and sent with the order (payload "attribution"); the server
 * stores them reduced to safe characters and deletes them after the retention period.
 */
(function () {
	'use strict';
	const KEY = 'wpcap_attribution';

	function clean(value) {
		return String(value || '').toLowerCase().replace(/[^a-z0-9_.\-]/g, '').slice(0, 64);
	}

	function captureAttribution(win) {
		try {
			const params = new URLSearchParams(win.location.search || '');
			const fresh = {};
			['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (name) {
				const value = clean(params.get(name));
				if (value) fresh[name.slice(4)] = value;
			});
			let ref = '';
			try { ref = win.document.referrer ? new URL(win.document.referrer).hostname : ''; } catch (e) { ref = ''; }
			if (ref && ref !== win.location.hostname) fresh.ref = clean(ref);
			if (Object.keys(fresh).length) win.sessionStorage.setItem(KEY, JSON.stringify(fresh));
			const stored = JSON.parse(win.sessionStorage.getItem(KEY) || 'null');
			return stored && typeof stored === 'object' ? stored : null;
		} catch (e) {
			return null; // Storage blocked: no attribution.
		}
	}

	/** Items of a checkout form with quantity > 0 (or all ticket types for view_item). */
	function items(form, all) {
		const out = [];
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const quantity = Number(qty.value) || 0;
			if (!all && quantity < 1) return;
			out.push({ item_id: String(qty.dataset.type), item_name: qty.dataset.name || '', price: (Number(qty.dataset.price) || 0) / 100, quantity: all ? 1 : quantity, currency: qty.dataset.currency || '' });
		});
		return out;
	}

	function total(list) {
		return Math.round(list.reduce(function (sum, item) { return sum + item.price * item.quantity; }, 0) * 100) / 100;
	}

	function fire(doc, event, list, extra) {
		const currency = list.length ? list[0].currency : '';
		const detail = Object.assign({ event: event, currency: currency, value: total(list), items: list.map(function (item) { return { item_id: item.item_id, item_name: item.item_name, price: item.price, quantity: item.quantity }; }) }, extra || {});
		doc.dispatchEvent(new CustomEvent('wpcap:track', { detail: detail }));
		return detail;
	}

	function init(win) {
		const doc = win.document;
		const attribution = captureAttribution(win);
		const last = new WeakMap();
		doc.querySelectorAll('.wpcap-checkout-form').forEach(function (form) {
			fire(doc, 'view_item', items(form, true));
			last.set(form, {});
		});
		doc.addEventListener('input', function (event) {
			const qty = event.target;
			if (!qty.classList || !qty.classList.contains('wpcap-qty')) return;
			const form = qty.closest('.wpcap-checkout-form');
			if (!form) return;
			const seen = last.get(form) || {};
			const before = seen[qty.dataset.type] || 0;
			const now = Number(qty.value) || 0;
			seen[qty.dataset.type] = now;
			last.set(form, seen);
			if (now === before) return;
			const item = { item_id: String(qty.dataset.type), item_name: qty.dataset.name || '', price: (Number(qty.dataset.price) || 0) / 100, quantity: Math.abs(now - before), currency: qty.dataset.currency || '' };
			fire(doc, now > before ? 'add_to_cart' : 'remove_from_cart', [item]);
		});
		doc.addEventListener('wpcap:checkout-payload', function (event) {
			const detail = event.detail || {};
			if (!detail.form || detail.error) return;
			if (attribution && detail.payload) detail.payload.attribution = attribution;
			fire(doc, 'begin_checkout', items(detail.form, false));
		});
		doc.addEventListener('wpcap:checkout-complete', function (event) {
			const detail = event.detail || {};
			const order = detail.order || {};
			const list = detail.form ? items(detail.form, false) : [];
			const extra = { transaction_id: String(order.reference || '') };
			if (typeof order.total_minor === 'number') extra.value = order.total_minor / 100;
			if (order.currency) extra.currency = String(order.currency);
			fire(doc, 'purchase', list, extra);
		});
	}

	if (typeof module === 'object' && module && module.exports) {
		module.exports = { init: init, clean: clean, captureAttribution: captureAttribution, items: items };
	} else {
		init(window);
	}
})();
