(function () {
	'use strict';
	const config = window.WPCAP_CHECKOUT || {};
	const t = config.i18n || {};
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
	document.addEventListener('input', function (event) {
		if (event.target.classList && event.target.classList.contains('wpcap-qty')) {
			renderAttendees(event.target.closest('.wpcap-checkout-form'));
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
		button.disabled = true;
		message.textContent = t.creating || '…';
		try {
			const response = await fetch(config.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					email: form.querySelector('[name="email"]').value,
					items: items,
					attendees: attendees,
					campaign_codes: code && code.value.trim() ? [code.value.trim()] : [],
					provider: provider ? provider.value : 'manual',
					accept_terms: terms ? terms.checked : false,
					voucher_code: voucher ? voucher.value.trim() : '',
					idempotency_key: form.dataset.key
				})
			});
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || t.failed || 'Failed.');
			delete form.dataset.key;
			if (data.payment && data.payment.redirect_url) {
				message.textContent = t.redirecting || '…';
				window.location.assign(data.payment.redirect_url);
				return;
			}
			if (data.status === 'complete') {
				message.textContent = t.complete || 'Done.';
			} else {
				message.textContent = (t.pending || '%d').replace('%d', data.hold_minutes) + (data.payment && data.payment.instructions ? ' ' + data.payment.instructions : '');
			}
			form.reset();
			renderAttendees(form);
		} catch (error) {
			message.textContent = error.message;
		} finally {
			button.disabled = false;
		}
	});
})();
