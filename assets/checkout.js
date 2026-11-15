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
	// Participant field control: text, email, tel, date, textarea, select, checkbox/consent. Radio groups are
	// built by appendField() (fieldset with legend), the others get a <label for>.
	function fieldInput(field, id) {
		if (field.type === 'textarea') return el('textarea', { id: id, 'data-key': field.key });
		let input;
		if (field.type === 'select') {
			input = el('select', { id: id, 'data-key': field.key });
			input.appendChild(el('option', { value: '' }, t.choose || '–'));
			(field.options || []).forEach(function (choice) { input.appendChild(el('option', { value: choice }, choice)); });
		} else {
			const map = { email: 'email', tel: 'tel', date: 'date', checkbox: 'checkbox', consent: 'checkbox' };
			const type = map[field.type] || 'text';
			input = el('input', { id: id, type: type, 'data-key': field.key });
			if (type === 'checkbox') input.value = '1';
			if (type === 'tel') { input.setAttribute('autocomplete', 'tel'); input.setAttribute('inputmode', 'tel'); }
		}
		if (Number(field.required)) input.required = true;
		return input;
	}
	function appendField(fs, field, id) {
		const required = Number(field.required);
		if (field.type === 'radio') {
			const group = el('fieldset', { class: 'wpcap-radio-group', id: id, 'data-radio-key': field.key });
			group.appendChild(el('legend', {}, field.label + (required ? ' *' : '')));
			(field.options || []).forEach(function (choice, i) {
				const label = el('label', { class: 'wpcap-choice' });
				const radio = el('input', { type: 'radio', name: id, value: choice, 'data-key': field.key });
				if (required && i === 0) radio.required = true;
				label.appendChild(radio);
				label.appendChild(document.createTextNode(' ' + choice));
				group.appendChild(label);
			});
			fs.appendChild(group);
		} else {
			fs.appendChild(el('label', { for: id }, field.label + (required ? ' *' : '')));
			fs.appendChild(fieldInput(field, id));
		}
		if (field.type === 'consent' && field.consent_text) fs.appendChild(el('small', { class: 'wpcap-consent-text' }, field.consent_text + (field.consent_version ? ' (' + field.consent_version + ')' : '')));
		if (field.purpose) fs.appendChild(el('small', {}, field.purpose));
	}
	function attendeeLegend(count, name) {
		return (t.attendee || 'Attendee %d').replace('%d', count) + ' · ' + name;
	}
	function renderAttendees(form) {
		const box = form.querySelector('.wpcap-attendees');
		const previous = {};
		box.querySelectorAll('[data-slot]').forEach(function (fs) { previous[fs.dataset.slot] = fs; });
		box.innerHTML = '';
		let count = 0;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = qty.disabled ? 0 : Math.max(0, Number(qty.value) || 0);
			const fields = JSON.parse(qty.dataset.fields || '[]');
			const nameMode = qty.dataset.attendeeName || 'optional';
			for (let i = 0; i < n; i++) {
				count++;
				const slot = qty.dataset.type + '-' + i;
				if (previous[slot]) {
					// Reused fieldset (entered values stay): its number follows the current order of all tickets.
					const legend = previous[slot].querySelector('.wpcap-attendee-legend');
					if (legend) legend.textContent = attendeeLegend(count, qty.dataset.name);
					box.appendChild(previous[slot]);
					continue;
				}
				const fs = el('fieldset', { 'data-slot': slot, 'data-type': qty.dataset.type, class: 'wpcap-attendee' });
				fs.appendChild(el('legend', { class: 'wpcap-attendee-legend' }, attendeeLegend(count, qty.dataset.name)));
				const base = 'wpcap-att-' + slot + '-' + Math.random().toString(16).slice(2, 8);
				if (nameMode !== 'off') {
					const required = nameMode === 'required';
					fs.appendChild(el('label', { for: base + '-name' }, (t.name || 'Name') + (required ? ' *' : '')));
					const name = el('input', { id: base + '-name', type: 'text', 'data-key': '__name', autocomplete: 'name', maxlength: '190' });
					if (required) name.required = true;
					fs.appendChild(name);
				}
				// Own ticket e-mail per attendee: optional, so it is folded away (summary keeps it reachable).
				const more = el('details', { class: 'wpcap-attendee-email' });
				more.appendChild(el('summary', {}, t.attendeeEmailToggle || t.email || 'E-mail'));
				more.appendChild(el('label', { for: base + '-email' }, t.email || 'E-mail'));
				more.appendChild(el('input', { id: base + '-email', type: 'email', 'data-key': '__email', autocomplete: 'off' }));
				fs.appendChild(more);
				fields.forEach(function (field, index) { appendField(fs, field, base + '-f' + index); });
				box.appendChild(fs);
			}
		});
		// Short status instead of a live region around the whole block (screen readers would re-read every field).
		const status = form.querySelector('.wpcap-attendee-status');
		if (status && String(count) !== status.dataset.count) {
			status.dataset.count = String(count);
			status.textContent = count && t.attendeeCount ? fill(t.attendeeCount, [count]) : '';
		}
	}
	// Same output as PHP Frontend\Format::money(): number_format_i18n( amount, decimals ) . ' ' . symbol.
	function money(minor, currency) {
		const m = config.money || {};
		const d = (m.decimals || {})[currency];
		const decimals = typeof d === 'number' ? d : 2;
		const value = Math.abs(Number(minor) || 0) / Math.pow(10, decimals);
		const parts = value.toFixed(decimals).split('.');
		const thousands = typeof m.thousands === 'string' ? m.thousands : '.';
		const integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
		const symbol = (m.symbols || {})[currency] || currency || '';
		const fraction = decimals ? (typeof m.decimal === 'string' ? m.decimal : ',') + parts[1] : '';
		return (minor < 0 ? '-' : '') + integer + fraction + (symbol ? ' ' + symbol : '');
	}
	// Display only: the server recalculates every price at checkout.
	function renderEstimate(form) {
		const box = form.querySelector('.wpcap-estimate');
		let total = 0;
		let selected = 0;
		let currency = '';
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = qty.disabled ? 0 : Math.max(0, Number(qty.value) || 0);
			total += n * (Number(qty.dataset.price) || 0);
			selected += n;
			if (n) currency = qty.dataset.currency || currency;
		});
		if (box) box.textContent = total > 0 ? (t.estimate || '%s').replace('%s', money(total, currency)) : '';
		// "Kostenlos bestellen" when only free tickets are selected; "Zahlungspflichtig bestellen" otherwise.
		const button = form.querySelector('button[type="submit"]');
		if (button && t.payButton && t.freeButton) {
			if (button.dataset.defaultLabel === undefined) button.dataset.defaultLabel = button.textContent;
			button.textContent = !selected ? button.dataset.defaultLabel : (total > 0 ? t.payButton : t.freeButton);
		}
		syncSteppers(form);
	}

	/* ---------------------------------------------------------------- Quantity stepper */

	function syncSteppers(form) {
		form.querySelectorAll('.wpcap-step').forEach(function (button) {
			const qty = document.getElementById(button.getAttribute('aria-controls'));
			if (!qty) return;
			const n = Number(qty.value) || 0;
			button.disabled = qty.disabled || (Number(button.dataset.step) < 0 ? n <= 0 : n >= Number(qty.max));
		});
	}
	function step(button) {
		const qty = document.getElementById(button.getAttribute('aria-controls'));
		if (!qty || qty.disabled) return;
		const next = Math.min(Number(qty.max) || 0, Math.max(0, (Number(qty.value) || 0) + Number(button.dataset.step)));
		if (String(next) === String(qty.value)) return;
		qty.value = String(next);
		qty.dispatchEvent(new Event('input', { bubbles: true }));
		qty.dispatchEvent(new Event('change', { bubbles: true }));
		// Keep focus on the pressed button unless it just became disabled (then on the input).
		if (button.disabled) qty.focus();
	}

	/* ---------------------------------------------------------------- Live availability */

	// Upcoming sale: "Verkaufsstart in 2 Std. 5 Min." within 24 h, otherwise null (the server text stays).
	function countdownText(ms) {
		if (ms <= 0) return t.salesOpen || '';
		if (ms > 86400000) return null;
		const s = Math.ceil(ms / 1000);
		const parts = [];
		const h = Math.floor(s / 3600);
		const m = Math.floor((s % 3600) / 60);
		if (h) parts.push(fill(t.hours || '%d h', [h]));
		if (m || h) parts.push(fill(t.minutes || '%d min', [m]));
		if (!h) parts.push(fill(t.seconds || '%d s', [s % 60]));
		return fill(t.salesIn || '%s', [parts.join(' ')]);
	}
	function setStatus(row, cls, text, attrs) {
		const box = row.querySelector('.wpcap-type-status');
		if (!box) return;
		box.innerHTML = '';
		if (text) box.appendChild(el('span', Object.assign({ class: cls }, attrs || {}), text));
	}
	// Applies one ticket type state {status, max, remaining, sales_start} to every form row of that type.
	function applyTypeState(id, state) {
		document.querySelectorAll('.wpcap-qty[data-type="' + id + '"]').forEach(function (qty) {
			const row = qty.closest('.wpcap-type');
			const form = qty.closest('.wpcap-checkout-form');
			if (!row || !form) return;
			const key = [state.status, state.max, state.remaining, state.sales_start].join('|');
			if (row.dataset.live === key) return;
			row.dataset.live = key;
			const control = row.querySelector('.wpcap-qty-control');
			const before = Number(qty.value) || 0;
			const available = state.status === 'available' && Number(state.max) > 0;
			qty.disabled = !available;
			qty.max = String(available ? Number(state.max) : 0);
			if (control) control.hidden = !available;
			if (!available && before) qty.value = '0';
			if (available && before > Number(state.max)) qty.value = String(state.max);
			row.dataset.state = available ? 'available' : state.status;
			const waitlist = row.querySelector('.wpcap-waitlist');
			if (available) {
				const low = state.remaining !== null && state.remaining !== undefined && Number(state.remaining) <= 10;
				setStatus(row, 'wpcap-remaining', low && t.remaining ? fill(t.remaining, [Number(state.remaining)]) : '');
				const signed = waitlist && waitlist.querySelector('.wpcap-waitlist-status');
				if (waitlist && !(signed && signed.textContent)) waitlist.remove();
			} else if (state.status === 'upcoming') {
				setStatus(row, 'wpcap-upcoming', fill(t.salesFrom || '%s', [state.sales_start ? new Date(state.sales_start).toLocaleString() : '']), { 'data-sales-start': state.sales_start || '' });
				qty.dataset.salesStart = state.sales_start || '';
			} else {
				setStatus(row, 'wpcap-soldout', state.status === 'soldout' ? (t.soldOut || 'Sold out') : (t.salesClosed || t.soldOut || ''));
				if (state.status === 'soldout' && form.dataset.waitlist === '1' && !waitlist) {
					row.appendChild(el('div', { class: 'wpcap-waitlist', 'data-type': String(id) }));
				}
			}
			if (state.status !== 'upcoming') delete qty.dataset.salesStart;
			renderWaitlists(form);
			if (before !== (Number(qty.value) || 0)) {
				renderAttendees(form);
			}
			renderEstimate(form);
		});
	}
	function applyAvailability(data) {
		const types = (data && data.types) || {};
		Object.keys(types).forEach(function (id) { applyTypeState(id, types[id]); });
	}
	function liveTypeIds() {
		const ids = [];
		document.querySelectorAll('.wpcap-checkout-form:not([hidden]) .wpcap-qty[data-type]').forEach(function (qty) {
			const id = Number(qty.dataset.type);
			if (id && ids.indexOf(id) === -1) ids.push(id);
		});
		return ids.sort(function (a, b) { return a - b; });
	}
	// Next poll: at the next sales start (plus up to 2 s jitter, so thousands of pages do not hit the same
	// second), otherwise after the configured interval (15–60 s) plus jitter.
	function pollDelay(now) {
		const every = Math.min(60, Math.max(15, Number(config.poll) || 20)) * 1000;
		let delay = every + Math.floor(Math.random() * 3000);
		document.querySelectorAll('.wpcap-qty[data-sales-start]').forEach(function (qty) {
			const at = Date.parse(qty.dataset.salesStart);
			if (!isNaN(at) && at > now) delay = Math.min(delay, at - now + Math.floor(Math.random() * 2000));
		});
		return Math.max(500, delay);
	}
	let pollTimer = null;
	let pollMissed = false;
	function schedulePoll(ms) {
		if (pollTimer) clearTimeout(pollTimer);
		pollTimer = setTimeout(poll, ms);
	}
	async function poll() {
		pollTimer = null;
		if (document.hidden) { pollMissed = true; return; }
		const ids = liveTypeIds();
		if (!ids.length || !config.availabilityUrl) return;
		let wait = null;
		try {
			const response = await fetch(config.availabilityUrl + (config.availabilityUrl.indexOf('?') === -1 ? '?' : '&') + 'types=' + ids.join(','), { headers: { Accept: 'application/json' } });
			if (response.status === 429) {
				wait = Math.min(300, Math.max(30, retryAfter(response, {}) || 60)) * 1000;
			} else if (response.ok) {
				applyAvailability(await response.json());
			}
		} catch (e) { /* offline: try again later */ }
		schedulePoll(wait || pollDelay(Date.now()));
	}
	function tickCountdowns() {
		const now = Date.now();
		let opened = false;
		document.querySelectorAll('.wpcap-upcoming[data-sales-start]').forEach(function (node) {
			const at = Date.parse(node.dataset.salesStart);
			if (isNaN(at)) return;
			const text = countdownText(at - now);
			if (text !== null && node.textContent !== text) node.textContent = text;
			if (at <= now && !node.dataset.opened) { node.dataset.opened = '1'; opened = true; }
		});
		if (opened) schedulePoll(Math.floor(Math.random() * 2000));
	}

	/* ---------------------------------------------------------------- Waitlist */

	function renderWaitlists(form) {
		form.querySelectorAll('.wpcap-waitlist').forEach(function (box) {
			if (box.firstChild) return;
			const qty = form.querySelector('.wpcap-qty[data-type="' + box.dataset.type + '"]');
			const name = qty ? qty.dataset.name : '';
			const panel = el('div', { class: 'wpcap-waitlist-form', hidden: '' });
			const panelId = ensureId(panel, 'wpcap-waitlist');
			const open = el('button', { type: 'button', class: 'wpcap-waitlist-open', 'aria-expanded': 'false', 'aria-controls': panelId }, t.waitlistOpen || 'Waitlist');
			if (t.waitlistOpenLabel) open.setAttribute('aria-label', fill(t.waitlistOpenLabel, [name]));
			const email = el('input', { type: 'email', class: 'wpcap-waitlist-email', autocomplete: 'email', 'aria-required': 'true' });
			const emailId = ensureId(email, 'wpcap-waitlist-email');
			const label = el('label', { for: emailId }, t.waitlistEmail || 'E-mail');
			panel.appendChild(label);
			panel.appendChild(email);
			panel.appendChild(el('button', { type: 'button', class: 'wpcap-waitlist-submit' }, t.waitlistSubmit || 'Join'));
			box.appendChild(open);
			box.appendChild(panel);
			box.appendChild(el('p', { class: 'wpcap-waitlist-status', role: 'status' }));
		});
	}
	function toggleWaitlist(button) {
		const panel = document.getElementById(button.getAttribute('aria-controls'));
		if (!panel) return;
		const opening = panel.hidden;
		panel.hidden = !opening;
		button.setAttribute('aria-expanded', opening ? 'true' : 'false');
		if (opening) {
			const email = panel.querySelector('.wpcap-waitlist-email');
			const form = button.closest('.wpcap-checkout-form');
			const buyer = form && form.querySelector('[name="email"]');
			if (email && buyer && !email.value) email.value = buyer.value.trim();
			if (email) email.focus();
		}
	}
	async function joinWaitlist(box) {
		const form = box.closest('.wpcap-checkout-form');
		const email = box.querySelector('.wpcap-waitlist-email');
		const status = box.querySelector('.wpcap-waitlist-status');
		const submit = box.querySelector('.wpcap-waitlist-submit');
		const value = email.value.trim();
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
			email.setAttribute('aria-invalid', 'true');
			status.textContent = fill(t.errEmail || '%s', [t.waitlistEmail || 'E-mail']);
			email.focus();
			return;
		}
		email.removeAttribute('aria-invalid');
		submit.disabled = true;
		status.textContent = t.waitlistSending || '…';
		try {
			const response = await fetch(String(config.waitlistUrl || '').replace('/events/0/waitlist', '/events/' + encodeURIComponent(form.dataset.event || '') + '/waitlist'), {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify({ occurrence_id: Number(form.dataset.occurrence) || 0, ticket_type_id: Number(box.dataset.type) || 0, email: value })
			});
			let data = {};
			try { data = await response.json(); } catch (e) { data = {}; }
			if (!response.ok) {
				status.textContent = data.message || t.waitlistFailed || 'Failed.';
				return;
			}
			// Double opt-in: the place only counts after the link in the e-mail was confirmed.
			status.textContent = (data.message || '') + (data.double_opt_in && t.waitlistConfirm ? ' ' + t.waitlistConfirm : '');
			box.querySelector('.wpcap-waitlist-form').hidden = true;
			box.querySelector('.wpcap-waitlist-open').hidden = true;
		} catch (e) {
			status.textContent = t.waitlistFailed || 'Failed.';
		} finally {
			submit.disabled = false;
		}
	}

	/* ---------------------------------------------------------------- Requests */

	// JSON headers; the REST nonce only exists for signed-in buyers (buyer limits per account).
	function headers() {
		const h = { 'Content-Type': 'application/json' };
		if (config.nonce) h['X-WP-Nonce'] = config.nonce;
		return h;
	}
	// Seconds from a Retry-After header (or data.retry_after), capped at 10 s for the automatic retry.
	function retryAfter(response, data) {
		let value = response && response.headers && typeof response.headers.get === 'function' ? response.headers.get('Retry-After') : null;
		if ((value === null || value === undefined) && data && data.data) value = data.data.retry_after;
		const n = Number(value);
		return isFinite(n) && n > 0 ? n : 0;
	}
	function wait(ms) {
		return new Promise(function (resolve) { setTimeout(resolve, ms); });
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
		const qtys = Array.prototype.slice.call(form.querySelectorAll('.wpcap-qty')).filter(function (qty) { return !qty.disabled; });
		let selected = 0;
		qtys.forEach(function (qty) {
			const n = Number(qty.value) || 0;
			if (n > Number(qty.max) || n < 0) errors.push({ input: qty, message: fill(t.errTooMany || '%1$s: max %2$d', [qty.dataset.name, Number(qty.max)]) });
			if (n > 0) selected += n;
		});
		if (!selected && qtys.length) errors.push({ input: qtys[0], message: t.selectTickets || 'Select tickets.' });
		form.querySelectorAll('input, select, textarea').forEach(function (input) {
			if (input.classList.contains('wpcap-qty') || input.type === 'hidden' || input.type === 'radio' || input.type === 'submit' || input.disabled || input.closest('[hidden]') || input.closest('.wpcap-waitlist')) return;
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
		// Required radio groups (participant fields of type "radio").
		form.querySelectorAll('.wpcap-radio-group').forEach(function (group) {
			const radios = Array.prototype.slice.call(group.querySelectorAll('input[type="radio"]'));
			if (!radios.length || !radios.some(function (r) { return r.required; }) || radios.some(function (r) { return r.checked; })) return;
			const legend = group.querySelector('legend');
			errors.push({ input: radios[0], message: fill(t.errRequired || '%s', [legend ? legend.textContent.replace(/\*/g, '').trim() : '']) });
		});
		return errors;
	}
	// Server error -> form control (data.field from the REST response).
	function serverErrorField(form, data) {
		const field = data && data.data && data.data.field;
		if (!field) return null;
		if (field === 'items') return form.querySelector('.wpcap-qty:not([disabled])') || form.querySelector('.wpcap-qty');
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
		return form.querySelector('[name="' + field.replace(/[^a-z0-9_]/g, '') + '"]');
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
	document.addEventListener('click', function (event) {
		const target = event.target && event.target.closest ? event.target : null;
		if (!target) return;
		const stepper = target.closest('.wpcap-step');
		if (stepper) { event.preventDefault(); step(stepper); return; }
		const open = target.closest('.wpcap-waitlist-open');
		if (open) { event.preventDefault(); toggleWaitlist(open); return; }
		const join = target.closest('.wpcap-waitlist-submit');
		if (join) { event.preventDefault(); joinWaitlist(join.closest('.wpcap-waitlist')); }
	});
	// Enter in the waitlist e-mail field signs up for the waitlist instead of submitting the order form.
	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Enter' || !event.target.classList || !event.target.classList.contains('wpcap-waitlist-email')) return;
		event.preventDefault();
		joinWaitlist(event.target.closest('.wpcap-waitlist'));
	});
	document.querySelectorAll('.wpcap-checkout-form').forEach(function (form) {
		syncBilling(form);
		renderWaitlists(form);
		syncSteppers(form);
	});
	// Live availability (cached pages): first check shortly after load, then at sales starts and every
	// 15–60 s while the page is visible; countdowns for upcoming sales tick every second.
	if (document.querySelector('.wpcap-checkout-form .wpcap-qty') && config.availabilityUrl) {
		schedulePoll(1000 + Math.floor(Math.random() * 2000));
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && pollMissed) { pollMissed = false; schedulePoll(Math.floor(Math.random() * 1000)); }
		});
		if (document.querySelector('.wpcap-upcoming[data-sales-start]')) { tickCountdowns(); setInterval(tickCountdowns, 1000); }
	}
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
			const n = qty.disabled ? 0 : Number(qty.value) || 0;
			if (n > 0) items.push({ ticket_type_id: Number(qty.dataset.type), quantity: n });
		});
		const attendees = [];
		form.querySelectorAll('.wpcap-attendee').forEach(function (fs) {
			const entry = { ticket_type_id: Number(fs.dataset.type), answers: {} };
			fs.querySelectorAll('[data-key]').forEach(function (input) {
				if (input.type === 'radio') { if (input.checked) entry.answers[input.dataset.key] = input.value; else if (!(input.dataset.key in entry.answers)) entry.answers[input.dataset.key] = ''; return; }
				const value = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
				if (input.dataset.key === '__name') entry.name = value;
				else if (input.dataset.key === '__email') entry.email = value;
				else entry.answers[input.dataset.key] = value;
			});
			attendees.push(entry);
		});
		const provider = form.querySelector('[name="provider"]:checked') || form.querySelector('input[type="hidden"][name="provider"]');
		const code = form.querySelector('[name="campaign_code"]');
		const terms = form.querySelector('[name="accept_terms"]');
		const voucher = form.querySelector('[name="voucher_code"]');
		const email = form.querySelector('[name="email"]').value.trim();
		const buyerName = form.querySelector('[name="buyer_name"]');
		// The kept key (after a network error) only belongs to the same attempt: a changed provider,
		// quantity, e-mail or code starts a new one (the server answers 409 for a reused key with other data).
		const signature = JSON.stringify([provider ? provider.value : 'manual', items, email.toLowerCase(), code ? code.value.trim() : '', voucher ? voucher.value.trim() : '']);
		if (!form.dataset.key || form.dataset.keySignature !== signature) {
			form.dataset.key = uuid();
			form.dataset.keySignature = signature;
		}
		const extra = {};
		const billing = billingPayload(form);
		if (billing) extra.billing = billing;
		const locale = form.querySelector('[name="locale"]');
		if (locale && locale.value) extra.locale = locale.value;
		if (buyerName && buyerName.value.trim()) extra.buyer_name = buyerName.value.trim();
		// Hosted payment pages return to this page (validated on the server: same site only).
		if (location && location.href) extra.return_to = String(location.href).split('#')[0];
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
			const body = JSON.stringify(Object.assign({
				email: email,
				items: items,
				attendees: attendees,
				campaign_codes: code && code.value.trim() ? [code.value.trim()] : [],
				provider: provider ? provider.value : 'manual',
				accept_terms: terms ? terms.checked : false,
				voucher_code: voucher ? voucher.value.trim() : '',
				idempotency_key: form.dataset.key
			}, extra));
			let response;
			let data = {};
			// A busy server (503 / retryable) is retried automatically up to twice with the same idempotency
			// key (never a second order), after Retry-After plus jitter, so not every buyer clicks again at once.
			for (let attempt = 0; ; attempt++) {
				response = await fetch(config.url, { method: 'POST', headers: headers(), body: body });
				data = {};
				try { data = await response.json(); } catch (e) { data = {}; }
				const retryable = response.status === 503 || (data && data.data && data.data.retryable);
				if (response.ok || !retryable || attempt >= 2) break;
				message.textContent = t.retrying || '…';
				await wait(Math.min(10, retryAfter(response, data) || 1.5) * 1000 + Math.floor(Math.random() * 1000));
			}
			if (!response.ok) {
				// The server answered: this attempt is finished, so the next submit starts a new one
				// (only network errors keep the key, so a retry can never create a second order).
				delete form.dataset.key;
				message.textContent = '';
				let target = serverErrorField(form, data);
				const remaining = data && data.data && data.data.remaining;
				if (remaining && typeof remaining === 'object') {
					// Not enough tickets: show the current maximum per type and adjust the selection.
					Object.keys(remaining).forEach(function (id) {
						const max = Math.max(0, Number(remaining[id]) || 0);
						applyTypeState(id, { status: max > 0 ? 'available' : 'soldout', max: max, remaining: max, sales_start: null });
						const qty = form.querySelector('.wpcap-qty[data-type="' + id + '"]');
						if (qty && !target) target = qty.disabled ? null : qty;
					});
				}
				showErrors(form, [{ input: target, message: data.message || t.failed || 'Failed.' }]);
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
	// Test hook for tests/js (node:test); `module` does not exist in the browser, so this is a no-op there.
	if (typeof module === 'object' && module && module.exports) { module.exports = { holdText: holdText, fill: fill, money: money, renderAttendees: renderAttendees, renderEstimate: renderEstimate, labelText: labelText, validate: validate, showErrors: showErrors, clearErrors: clearErrors, serverErrorField: serverErrorField, billingPayload: billingPayload, applyAvailability: applyAvailability, countdownText: countdownText, pollDelay: pollDelay, poll: poll, liveTypeIds: liveTypeIds, renderWaitlists: renderWaitlists, headers: headers }; }
})();
