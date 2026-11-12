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
	function fill(text, values) {
		let i = 0;
		return String(text || '').replace(/%(\d\$)?[sd]/g, function (match, pos) {
			const index = pos ? parseInt(pos, 10) - 1 : i++;
			return values[index] !== undefined ? String(values[index]) : match;
		});
	}
	function uuid() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
	}
	function el(tag, attrs, text) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text) node.textContent = text;
		return node;
	}
	let uid = 0;
	function ensureId(node, prefix) {
		if (!node.id) node.id = (prefix || 'wpcap-f') + '-' + (++uid) + '-' + Math.random().toString(16).slice(2, 6);
		return node.id;
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
				fs.appendChild(el('input', { id: base + '-email', type: 'email', 'data-key': '__email', autocomplete: 'off' }));
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
	// Same output as PHP Frontend\Format::money(): number_format_i18n( amount, 2 ) . ' ' . symbol.
	function money(minor, currency) {
		const m = config.money || {};
		const value = Math.abs(Number(minor) || 0) / 100;
		const parts = value.toFixed(2).split('.');
		const thousands = typeof m.thousands === 'string' ? m.thousands : '.';
		const integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
		const symbol = (m.symbols || {})[currency] || currency || '';
		return (minor < 0 ? '-' : '') + integer + (typeof m.decimal === 'string' ? m.decimal : ',') + parts[1] + (symbol ? ' ' + symbol : '');
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

	/* ---------------------------------------------------------------- Errors (WCAG 3.3.1 / 3.3.3) */

	// Visible label wording of a control, without the "*" and "(optional)" markers.
	function labelText(input) {
		let label = input.id ? input.form && input.form.querySelector('label[for="' + input.id + '"]') : null;
		if (!label) label = input.closest('label');
		let text = label ? label.textContent : (input.getAttribute('aria-label') || input.name || '');
		if (label && label.contains(input) && input.value) text = text.replace(input.value, '');
		return text.replace(/\*/g, '').replace(/\((optional[^)]*)\)/i, '').replace(/\s+/g, ' ').trim();
	}
	function clearErrors(form) {
		form.querySelectorAll('.wpcap-error-summary, .wpcap-field-error').forEach(function (node) { node.remove(); });
		form.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
			input.removeAttribute('aria-invalid');
			const original = input.dataset.wpcapDescribedby;
			if (original) input.setAttribute('aria-describedby', original); else input.removeAttribute('aria-describedby');
			delete input.dataset.wpcapDescribedby;
		});
		const message = form.querySelector('.wpcap-message');
		if (message) message.textContent = '';
	}
	function markField(input, text) {
		const id = ensureId(input, 'wpcap-field');
		const errorId = id + '-error';
		if (document.getElementById(errorId)) return;
		const note = el('p', { id: errorId, class: 'wpcap-field-error' }, text);
		const wrapper = input.closest('.wpcap-field');
		if (wrapper) {
			wrapper.appendChild(note);
		} else {
			let after = input;
			const next = input.nextElementSibling;
			if (next && next.tagName === 'SMALL') after = next;
			after.insertAdjacentElement('afterend', note);
		}
		if (input.getAttribute('aria-describedby')) input.dataset.wpcapDescribedby = input.getAttribute('aria-describedby');
		input.setAttribute('aria-invalid', 'true');
		input.setAttribute('aria-describedby', ((input.dataset.wpcapDescribedby || '') + ' ' + errorId).trim());
	}
	function showErrors(form, errors) {
		errors.sort(function (a, b) {
			if (!a.input || !b.input) return a.input ? -1 : (b.input ? 1 : 0);
			return a.input.compareDocumentPosition(b.input) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
		});
		const level = Math.min(6, Number(form.dataset.headingLevel) || 3);
		const summary = el('div', { class: 'wpcap-error-summary', role: 'alert', tabindex: '-1' });
		const headingId = ensureId(summary, 'wpcap-errors') + '-title';
		summary.setAttribute('aria-labelledby', headingId);
		summary.appendChild(el('h' + level, { id: headingId }, t.errorSummary || 'Please check your details'));
		if (errors.length > 1 && t.errorCount) summary.appendChild(el('p', {}, fill(t.errorCount, [errors.length])));
		const list = el('ul');
		errors.forEach(function (error) {
			const item = el('li');
			if (error.input) {
				markField(error.input, error.message);
				const link = el('a', { href: '#' + error.input.id }, error.message);
				link.addEventListener('click', function (event) {
					event.preventDefault();
					error.input.scrollIntoView({ block: 'center' });
					error.input.focus();
				});
				item.appendChild(link);
			} else {
				item.textContent = error.message;
			}
			list.appendChild(item);
		});
		summary.appendChild(list);
		form.insertBefore(summary, form.firstChild);
		summary.focus();
	}
	// Client-side checks with the field's own label wording.
	function validate(form) {
		const errors = [];
		const qtys = Array.prototype.slice.call(form.querySelectorAll('.wpcap-qty'));
		let selected = 0;
		qtys.forEach(function (qty) {
			const n = Number(qty.value) || 0;
			if (n > Number(qty.max) || n < 0) errors.push({ input: qty, message: fill(t.errTooMany || '%1$s: max %2$d', [qty.dataset.name, Number(qty.max)]) });
			if (n > 0) selected += n;
		});
		if (!selected && qtys.length) errors.push({ input: qtys[0], message: t.selectTickets || 'Select tickets.' });
		form.querySelectorAll('input, select, textarea').forEach(function (input) {
			if (input.classList.contains('wpcap-qty') || input.type === 'hidden' || input.type === 'radio' || input.type === 'submit' || input.disabled || input.closest('[hidden]')) return;
			const v = input.validity;
			if (!v || v.valid) return;
			const label = labelText(input);
			let text;
			if (input.type === 'checkbox') text = input.name === 'accept_terms' ? (t.errTerms || 'Please accept the terms.') : fill(t.errRequired || '%s', [label]);
			else if (v.valueMissing) text = fill(t.errRequired || '%s', [label]);
			else if (v.typeMismatch && input.type === 'email') text = fill(t.errEmail || '%s', [label]);
			else text = fill(t.errInvalid || '%s', [label]);
			errors.push({ input: input, message: text });
		});
		return errors;
	}
	// Server error -> form control (data.field from the REST response).
	function serverErrorField(form, data) {
		const field = data && data.data && data.data.field;
		if (!field) return null;
		if (field === 'items') return form.querySelector('.wpcap-qty');
		if (field === 'provider') return form.querySelector('[name="provider"]:not([type="hidden"])');
		if (field === 'attendees') {
			const inputs = Array.prototype.slice.call(form.querySelectorAll('.wpcap-attendee [data-key]'));
			const quoted = /„([^“]+)“/.exec(data.message || '');
			if (quoted) {
				const match = inputs.filter(function (input) { return labelText(input) === quoted[1]; });
				const empty = match.filter(function (input) { return input.type === 'checkbox' ? !input.checked : !input.value.trim(); });
				if (empty.length || match.length) return (empty.length ? empty : match)[0];
			}
			return inputs[0] || null;
		}
		if (field.indexOf('billing_') === 0 && field !== 'billing_enabled') {
			const toggle = form.querySelector('[name="billing_enabled"]');
			if (toggle && !toggle.checked) { toggle.checked = true; syncBilling(form); }
		}
		const input = form.querySelector('[name="' + field.replace(/[^a-z0-9_]/g, '') + '"]');
		// Discount and voucher errors point at the single code field when the form has one.
		if (!input && (field === 'campaign_code' || field === 'voucher_code')) return form.querySelector('[name="code"]');
		return input;
	}

	/* ---------------------------------------------------------------- Billing data (B2B invoice) */

	// Shows the billing fields while "Ich brauche eine Rechnung auf Firma/Adresse" is checked.
	function syncBilling(form) {
		const toggle = form.querySelector('[name="billing_enabled"]');
		if (!toggle) return;
		const box = document.getElementById(toggle.getAttribute('aria-controls'));
		if (box) box.hidden = !toggle.checked;
	}
	function billingPayload(form) {
		const toggle = form.querySelector('[name="billing_enabled"]');
		if (!toggle || !toggle.checked) return null;
		const billing = { enabled: true };
		form.querySelectorAll('[name^="billing_"]').forEach(function (input) {
			if (input !== toggle) billing[input.name.slice(8)] = input.value.trim();
		});
		return billing;
	}

	/* ---------------------------------------------------------------- Confirmation panel */

	function showConfirmation(form, data, email) {
		const level = Math.min(6, Number(form.dataset.headingLevel) || 3);
		const complete = data.status === 'complete';
		const panel = el('section', { class: 'wpcap-confirmation', tabindex: '-1' });
		const headingId = ensureId(panel, 'wpcap-confirmation') + '-title';
		panel.setAttribute('aria-labelledby', headingId);
		panel.appendChild(el('h' + level, { id: headingId }, complete ? (t.thanksComplete || 'Done') : (t.thanks || 'Thank you!')));
		if (data.reference) {
			const p = el('p', { class: 'wpcap-order-number' });
			const parts = (t.orderNumber || '%s').split('%s');
			p.appendChild(document.createTextNode(parts[0] || ''));
			p.appendChild(el('strong', {}, data.reference));
			p.appendChild(document.createTextNode(parts[1] || ''));
			panel.appendChild(p);
		}
		const total = data.total_formatted || (typeof data.total_minor === 'number' ? money(data.total_minor, data.currency) : '');
		if (total && Number(data.total_minor) > 0 && t.orderTotal && !(data.payment && data.payment.bank_transfer)) panel.appendChild(el('p', {}, t.orderTotal.replace('%s', total)));
		if (complete) {
			panel.appendChild(el('p', {}, fill(t.ticketsSentTo || t.complete || '%s', [email])));
		} else {
			const payment = data.payment || {};
			const bank = payment.bank_transfer;
			if (bank) {
				panel.appendChild(el('p', {}, t.payNow || ''));
				const list = el('dl', { class: 'wpcap-bank' });
				[['amount', bank.amount], ['recipient', bank.recipient], ['iban', bank.iban], ['bic', bank.bic], ['remittance', bank.remittance], ['dueDate', bank.due_date]].forEach(function (row) {
					if (!row[1]) return;
					list.appendChild(el('dt', {}, t[row[0]] || row[0]));
					list.appendChild(el('dd', { class: 'wpcap-bank-' + row[0] }, row[1]));
				});
				panel.appendChild(list);
				if (bank.instructions) panel.appendChild(el('p', { class: 'wpcap-instructions' }, bank.instructions));
				panel.appendChild(el('p', {}, bank.due_date && t.payUntil ? fill(t.payUntil, [bank.due_date]) : holdText(data.hold_minutes)));
				panel.appendChild(el('p', {}, t.afterPayment || ''));
			} else {
				panel.appendChild(el('p', {}, holdText(data.hold_minutes)));
				if (payment.instructions) panel.appendChild(el('p', { class: 'wpcap-instructions' }, payment.instructions));
			}
			panel.appendChild(el('p', {}, fill(t.sentTo || '%s', [email])));
		}
		panel.appendChild(el('p', { class: 'wpcap-hint' }, t.spamHint || ''));
		const again = el('button', { type: 'button', class: 'wp-element-button wpcap-order-again' }, t.orderAgain || 'Order more');
		again.addEventListener('click', function () {
			const keepEmail = form.querySelector('[name="email"]').value;
			panel.remove();
			form.reset();
			form.querySelector('[name="email"]').value = keepEmail;
			clearErrors(form);
			syncBilling(form);
			renderAttendees(form);
			renderEstimate(form);
			form.hidden = false;
			const first = form.querySelector('.wpcap-qty');
			if (first) first.focus();
		});
		panel.appendChild(again);
		form.parentNode.insertBefore(panel, form);
		form.hidden = true;
		panel.focus();
		document.dispatchEvent(new CustomEvent('wpcap:checkout-complete', { detail: { form: form, panel: panel, order: data } }));
	}

	document.addEventListener('input', function (event) {
		if (event.target.classList && event.target.classList.contains('wpcap-qty')) {
			const form = event.target.closest('.wpcap-checkout-form');
			renderAttendees(form);
			renderEstimate(form);
		}
	});
	document.addEventListener('change', function (event) {
		const form = event.target.name === 'billing_enabled' ? event.target.closest('.wpcap-checkout-form') : null;
		if (form) syncBilling(form);
	});
	document.querySelectorAll('.wpcap-checkout-form').forEach(syncBilling);
	document.addEventListener('submit', async function (event) {
		const form = event.target.closest('.wpcap-checkout-form');
		if (!form) return;
		event.preventDefault();
		const button = form.querySelector('button[type="submit"]');
		const message = form.querySelector('.wpcap-message');
		clearErrors(form);
		const errors = validate(form);
		if (errors.length) { showErrors(form, errors); return; }
		const items = [];
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = Number(qty.value) || 0;
			if (n > 0) items.push({ ticket_type_id: Number(qty.dataset.type), quantity: n });
		});
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
		const single = form.querySelector('[name="code"]');
		const email = form.querySelector('[name="email"]').value.trim();
		const extra = {};
		const billing = billingPayload(form);
		if (billing) extra.billing = billing;
		// Modules (e.g. seat selection) add fields here; they may set detail.error (and detail.field) to block submission.
		const hook = new CustomEvent('wpcap:checkout-payload', { detail: { form: form, items: items, payload: extra, error: '', field: null } });
		document.dispatchEvent(hook);
		if (hook.detail.error) {
			const active = document.activeElement;
			const target = hook.detail.field || (active && active !== button && form.contains(active) && active.matches('input, select, textarea') ? active : null);
			showErrors(form, [{ input: target, message: hook.detail.error }]);
			return;
		}
		// Disabling the focused button drops keyboard focus to <body>; remember it to restore it afterwards.
		const hadFocus = document.activeElement === button;
		button.disabled = true;
		message.textContent = t.creating || '…';
		let done = false;
		try {
			const response = await fetch(config.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(Object.assign({
					email: email,
					items: items,
					attendees: attendees,
					campaign_codes: code && code.value.trim() ? [code.value.trim()] : [],
					provider: provider ? provider.value : 'manual',
					accept_terms: terms ? terms.checked : false,
					voucher_code: voucher ? voucher.value.trim() : '',
					code: single ? single.value.trim() : '',
					idempotency_key: form.dataset.key
				}, extra))
			});
			let data = {};
			try { data = await response.json(); } catch (e) { data = {}; }
			if (!response.ok) {
				// The server answered: this attempt is finished, so the next submit starts a new one
				// (only network errors keep the key, so a retry can never create a second order).
				delete form.dataset.key;
				message.textContent = '';
				showErrors(form, [{ input: serverErrorField(form, data), message: data.message || t.failed || 'Failed.' }]);
				return;
			}
			delete form.dataset.key;
			if (data.payment && data.payment.redirect_url) {
				message.textContent = t.redirecting || '…';
				done = true;
				window.location.assign(data.payment.redirect_url);
				return;
			}
			message.textContent = '';
			done = true;
			showConfirmation(form, data, email);
		} catch (error) {
			message.textContent = '';
			showErrors(form, [{ input: null, message: t.failed || (error && error.message) || 'Failed.' }]);
		} finally {
			button.disabled = false;
			if (!done && hadFocus && (!document.activeElement || document.activeElement === document.body)) button.focus();
		}
	});
})();
