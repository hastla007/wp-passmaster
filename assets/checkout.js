(function () {
	'use strict';
	const config = window.WPCAP_CHECKOUT || {};
	const t = config.i18n || {};
	const holdText = function (minutes) {
		minutes = parseInt(minutes, 10) || 0;
		if (minutes >= 2880 && minutes % 1440 === 0 && t.pendingDays) { return t.pendingDays.replace('%d', minutes / 1440); }
		if (minutes >= 120 && minutes % 60 === 0 && t.pendingHours) { return t.pendingHours.replace('%d', minutes / 60); }
		return (t.pending || '%d').replace('%d', minutes);
	};
	function uuid() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
	}
	function el(tag, attrs, text) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text) node.textContent = text;
		return node;
	}
	function fieldInput(field, id) {
		const type = field.type === 'email' ? 'email' : (field.type === 'checkbox' ? 'checkbox' : 'text');
		const input = field.type === 'textarea' ? el('textarea', { id: id, 'data-key': field.key }) : el('input', { id: id, type: type, 'data-key': field.key });
		if (Number(field.required)) input.required = true;
		if (type === 'checkbox') input.value = '1';
		return input;
	}
	function renderAttendees(form) {
		const box = form.querySelector('.wpcap-attendees');
		const previous = {};
		box.querySelectorAll('[data-slot]').forEach(function (fs) { previous[fs.dataset.slot] = fs; });
		box.innerHTML = '';
		let count = 0;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = Math.max(0, Number(qty.value) || 0);
			const fields = JSON.parse(qty.dataset.fields || '[]');
			for (let i = 0; i < n; i++) {
				count++;
				const slot = qty.dataset.type + '-' + i;
				if (previous[slot]) { box.appendChild(previous[slot]); continue; }
				const fs = el('fieldset', { 'data-slot': slot, 'data-type': qty.dataset.type, class: 'wpcap-attendee' });
				fs.appendChild(el('legend', {}, (t.attendee || 'Attendee %d').replace('%d', count) + ' · ' + qty.dataset.name));
				const base = 'wpcap-att-' + slot + '-' + Math.random().toString(16).slice(2, 8);
				const nameLabel = el('label', { for: base + '-name' }, t.name || 'Name');
				fs.appendChild(nameLabel);
				fs.appendChild(el('input', { id: base + '-name', type: 'text', 'data-key': '__name', autocomplete: 'name', maxlength: '190' }));
				fs.appendChild(el('label', { for: base + '-email' }, t.email || 'E-mail'));
				fs.appendChild(el('input', { id: base + '-email', type: 'email', 'data-key': '__email', autocomplete: 'email' }));
				fields.forEach(function (field, index) {
					const id = base + '-f' + index;
					fs.appendChild(el('label', { for: id }, field.label + (Number(field.required) ? ' *' : '')));
					fs.appendChild(fieldInput(field, id));
					if (field.purpose) fs.appendChild(el('small', {}, field.purpose));
				});
				box.appendChild(fs);
			}
		});
	}
	function money(minor, currency) {
		try { return new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency: currency || 'EUR' }).format(minor / 100); }
		catch (e) { return (minor / 100).toFixed(2) + ' ' + (currency || ''); }
	}
	// Display only: the server recalculates every price at checkout.
	function renderEstimate(form) {
		const box = form.querySelector('.wpcap-estimate');
		if (!box) return;
		let total = 0;
		let currency = '';
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = Math.max(0, Number(qty.value) || 0);
			total += n * (Number(qty.dataset.price) || 0);
			if (n) currency = qty.dataset.currency || currency;
		});
		box.textContent = total > 0 ? (t.estimate || '%s').replace('%s', money(total, currency)) : '';
	}
	document.addEventListener('input', function (event) {
		if (event.target.classList && event.target.classList.contains('wpcap-qty')) {
			const form = event.target.closest('.wpcap-checkout-form');
			renderAttendees(form);
			renderEstimate(form);
		}
	});
	document.addEventListener('submit', async function (event) {
		const form = event.target.closest('.wpcap-checkout-form');
		if (!form) return;
		event.preventDefault();
		const button = form.querySelector('button[type="submit"]');
		const message = form.querySelector('.wpcap-message');
		const items = [];
		let invalid = false;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = Number(qty.value) || 0;
			if (n > Number(qty.max)) invalid = true;
			if (n > 0) items.push({ ticket_type_id: Number(qty.dataset.type), quantity: n });
		});
		if (invalid) { message.textContent = t.tooMany || 'Too many tickets.'; return; }
		if (!items.length) { message.textContent = t.selectTickets || 'Select tickets.'; return; }
		if (!form.reportValidity()) return;
		const attendees = [];
		form.querySelectorAll('.wpcap-attendee').forEach(function (fs) {
			const entry = { ticket_type_id: Number(fs.dataset.type), answers: {} };
			fs.querySelectorAll('[data-key]').forEach(function (input) {
				const value = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
				if (input.dataset.key === '__name') entry.name = value;
				else if (input.dataset.key === '__email') entry.email = value;
				else entry.answers[input.dataset.key] = value;
			});
			attendees.push(entry);
		});
		if (!form.dataset.key) form.dataset.key = uuid();
		const provider = form.querySelector('[name="provider"]:checked') || form.querySelector('input[type="hidden"][name="provider"]');
		const code = form.querySelector('[name="campaign_code"]');
		const terms = form.querySelector('[name="accept_terms"]');
		const voucher = form.querySelector('[name="voucher_code"]');
		const extra = {};
		// Modules (e.g. seat selection) add fields here; they may set detail.error to block submission.
		const hook = new CustomEvent('wpcap:checkout-payload', { detail: { form: form, items: items, payload: extra, error: '' } });
		document.dispatchEvent(hook);
		if (hook.detail.error) { message.textContent = hook.detail.error; return; }
		// Disabling the focused button drops keyboard focus to <body>; remember it to restore it afterwards.
		const hadFocus = document.activeElement === button;
		button.disabled = true;
		message.textContent = t.creating || '…';
		try {
			const response = await fetch(config.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(Object.assign({
					email: form.querySelector('[name="email"]').value,
					items: items,
					attendees: attendees,
					campaign_codes: code && code.value.trim() ? [code.value.trim()] : [],
					provider: provider ? provider.value : 'manual',
					accept_terms: terms ? terms.checked : false,
					voucher_code: voucher ? voucher.value.trim() : '',
					idempotency_key: form.dataset.key
				}, extra))
			});
			const data = await response.json().catch(() => ({}));
			// The server answered: the attempt is finished either way, so the next submit starts a new one.
			// (Only network errors keep the key, so a retry cannot create a second order.)
			if (!response.ok) { delete form.dataset.key; throw new Error(data.message || t.failed || 'Failed.'); }
			delete form.dataset.key;
			if (data.payment && data.payment.redirect_url) {
				message.textContent = t.redirecting || '…';
				window.location.assign(data.payment.redirect_url);
				return;
			}
			const totalText = typeof data.total_minor === 'number' && data.total_minor > 0 && t.orderTotal ? ' ' + t.orderTotal.replace('%s', money(data.total_minor, data.currency)) : '';
			if (data.status === 'complete') {
				message.textContent = t.complete || 'Done.';
			} else {
				message.textContent = holdText(data.hold_minutes) + totalText + (data.payment && data.payment.instructions ? ' ' + data.payment.instructions : '');
			}
			form.reset();
			renderAttendees(form);
			renderEstimate(form);
		} catch (error) {
			message.textContent = error.message;
		} finally {
			button.disabled = false;
			if (hadFocus && (!document.activeElement || document.activeElement === document.body)) button.focus();
		}
	});
})();
