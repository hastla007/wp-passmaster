(function () {
	'use strict';
	const config = window.WPCAP_CHECKOUT || {};
	const t = config.i18n || {};
	const holdText = function (minutes) {
		minutes = parseInt(minutes, 10) || 0;
		if (minutes >= 2880 && minutes % 1440 === 0 && t.pendingDays) { return t.pendingDays.replace('%d', minutes / 1440); }
		if (minutes >= 120 && minutes % 60 === 0 && t.pendingHours) { return t.pendingHours.replace('%d', minutes / 60); }
		if (minutes === 1 && t.pendingOne) { return t.pendingOne; }
		return (t.pending || '%d').replace('%d', minutes);
	};
	function fill(text, values) {
		let i = 0;
		return String(text || '').replace(/%(\d\$)?[sd]/g, function (match, pos) {
			const index = pos ? parseInt(pos, 10) - 1 : i++;
			return values[index] !== undefined ? String(values[index]) : match;
		});
	}
	// Short non-cryptographic hash (cyrb53) of the form content: compares attempts without storing the content.
	function hash(text) {
		let h1 = 0xdeadbeef, h2 = 0x41c6ce57;
		for (let i = 0; i < text.length; i++) {
			const ch = text.charCodeAt(i);
			h1 = Math.imul(h1 ^ ch, 2654435761);
			h2 = Math.imul(h2 ^ ch, 1597334677);
		}
		h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
		h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
		return (h2 >>> 0).toString(16) + (h1 >>> 0).toString(16);
	}
	/**
	 * A309: how a checkout answer is to be read. 'success' only for JSON with order number and status,
	 * 'error' for an error of the plugin's REST API ({code, message, data.status}); anything else (HTML
	 * from a cache, WAF or proxy, a cut-off body) is 'unknown': the order may or may not exist.
	 */
	function classifyAnswer(ok, data) {
		const isObject = data !== null && typeof data === 'object' && !Array.isArray(data);
		if (ok) {
			return isObject && typeof data.reference === 'string' && data.reference !== '' && typeof data.status === 'string' && data.status !== '' ? 'success' : 'unknown';
		}
		if (isObject && typeof data.code === 'string' && typeof data.message === 'string' && (/^(wpcap_|rest_)/.test(data.code) || (data.data && typeof data.data.status === 'number'))) return 'error';
		return 'unknown';
	}
	window.wpcapCheckoutLogic = { classifyAnswer: classifyAnswer, hash: hash };
	function uuid() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
	}
	function el(tag, attrs, text) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text) node.textContent = text;
		return node;
	}
	// Element with this id inside root (A308): never an element of another card on the page.
	function byIdIn(root, id) {
		if (!root || !id) return null;
		return root.querySelector('[id="' + String(id).replace(/["\\]/g, '\\$&') + '"]');
	}
	let uid = 0;
	function ensureId(node, prefix) {
		if (!node.id) node.id = (prefix || 'wpcap-f') + '-' + (++uid) + '-' + Math.random().toString(16).slice(2, 6);
		return node.id;
	}
	// Input for a participant field (A680): text, e-mail, textarea, checkbox, select, multi-select
	// (a group of checkboxes, data-multi), phone, date and number.
	function fieldInput(field, id) {
		let input;
		if (field.type === 'textarea') {
			input = el('textarea', { id: id, 'data-key': field.key });
		} else if (field.type === 'select') {
			input = el('select', { id: id, 'data-key': field.key });
			input.appendChild(el('option', { value: '' }, t.choose || '–'));
			(field.options || []).forEach(function (option) { input.appendChild(el('option', { value: option }, option)); });
		} else if (field.type === 'multiselect') {
			input = el('span', { id: id, role: 'group', 'data-key': field.key, 'data-multi': '1', class: 'wpcap-multi' });
			(field.options || []).forEach(function (option, i) {
				const box = el('input', { id: id + '-o' + i, type: 'checkbox', value: option });
				const label = el('label', { for: id + '-o' + i }, option);
				const row = el('span', { class: 'wpcap-multi-option' });
				row.appendChild(box);
				row.appendChild(label);
				input.appendChild(row);
			});
			return input;
		} else if (field.type === 'radio') {
			// Option buttons (audit R6 #8, one choice): a group of radios, required on the group (data-required).
			input = el('span', { id: id, role: 'radiogroup', 'data-key': field.key, 'data-radio': '1', class: 'wpcap-radio-group' });
			if (Number(field.required)) { input.dataset.required = '1'; input.setAttribute('aria-required', 'true'); }
			(field.options || []).forEach(function (option, i) {
				const radio = el('input', { id: id + '-o' + i, type: 'radio', name: id, value: option });
				const label = el('label', { for: id + '-o' + i }, option);
				const row = el('span', { class: 'wpcap-radio-option' });
				row.appendChild(radio);
				row.appendChild(label);
				input.appendChild(row);
			});
			return input;
		} else {
			const types = { email: 'email', checkbox: 'checkbox', consent: 'checkbox', tel: 'tel', date: 'date' };
			input = el('input', { id: id, type: types[field.type] || 'text', 'data-key': field.key });
			if (field.type === 'tel') input.setAttribute('autocomplete', 'off');
			if (field.type === 'number') input.setAttribute('inputmode', 'decimal');
		}
		if (Number(field.required)) input.required = true;
		if (input.type === 'checkbox') input.value = '1';
		return input;
	}
	// Value of a participant input: checkbox '1'/'', multi-select the list of ticked choices.
	function fieldValue(input) {
		if (input.dataset.multi) {
			return Array.prototype.slice.call(input.querySelectorAll('input[type="checkbox"]:checked')).map(function (box) { return box.value; });
		}
		if (input.dataset.radio) {
			const chosen = input.querySelector('input[type="radio"]:checked');
			return chosen ? chosen.value : '';
		}
		return input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
	}
	// A552: "same as buyer" copies the buyer's e-mail and billing name into the attendee block.
	function copyBuyer(form, fs) {
		const email = form.querySelector('[name="email"]');
		const first = form.querySelector('[name="billing_first_name"]');
		const last = form.querySelector('[name="billing_last_name"]');
		const name = [first ? first.value.trim() : '', last ? last.value.trim() : ''].filter(Boolean).join(' ');
		const nameInput = fs.querySelector('[data-key="__name"]');
		const emailInput = fs.querySelector('[data-key="__email"]');
		if (emailInput && email) emailInput.value = email.value.trim();
		if (nameInput && name) nameInput.value = name;
		(nameInput && !nameInput.value ? nameInput : emailInput || nameInput).focus();
	}
	// Attendee blocks per slot ("<ticket type>-<n>") and form. A block that is no longer needed
	// (quantity lowered, field emptied while typing a new number) is only detached, so its entries
	// come back when the quantity goes up again (A549). Cleared by "Weitere Tickets bestellen".
	const attendeeSlots = new WeakMap();
	function attendeeLegend(number, typeName) {
		return (t.attendee || 'Attendee %d').replace('%d', number) + ' · ' + typeName;
	}
	// A603: every attendee block is an autofill section of its own ("section-att{n} name"), so the
	// browser does not copy the buyer's name into all blocks at once; numbered by position.
	function attendeeAutocomplete(block, number) {
		const name = block.querySelector('[data-key="__name"]');
		if (name) name.setAttribute('autocomplete', 'section-att' + number + ' name');
	}
	function renderAttendees(form) {
		const box = form.querySelector('.wpcap-attendees');
		if (!box) return;
		if (!attendeeSlots.has(form)) attendeeSlots.set(form, {});
		const cache = attendeeSlots.get(form);
		box.querySelectorAll('[data-slot]').forEach(function (fs) { cache[fs.dataset.slot] = fs; });
		Array.prototype.slice.call(box.children).forEach(function (child) { child.remove(); });
		let count = 0;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const n = Math.max(0, Number(qty.value) || 0);
			const fields = JSON.parse(qty.dataset.fields || '[]');
			// Attendee name per ticket type: data-attendee-name off|optional|required (ticket type setting) and
			// data-name-required (A552 participant name switch); "required" from either makes it mandatory.
			const nameMode = qty.dataset.attendeeName || 'optional';
			const nameRequired = qty.dataset.nameRequired === '1' || nameMode === 'required';
			for (let i = 0; i < n; i++) {
				count++;
				const slot = qty.dataset.type + '-' + i;
				if (cache[slot]) {
					// Number by position after every change (A550): "Teilnehmer:in 1, 2, …" without gaps.
					const legend = cache[slot].querySelector('legend');
					if (legend) legend.textContent = attendeeLegend(count, qty.dataset.name);
					attendeeAutocomplete(cache[slot], count);
					box.appendChild(cache[slot]);
					continue;
				}
				const fs = el('fieldset', { 'data-slot': slot, 'data-type': qty.dataset.type, class: 'wpcap-attendee' });
				fs.appendChild(el('legend', {}, attendeeLegend(count, qty.dataset.name)));
				const base = 'wpcap-att-' + slot + '-' + Math.random().toString(16).slice(2, 8);
				if (count === 1) {
					const same = el('button', { type: 'button', class: 'wpcap-same-as-buyer' }, t.sameAsBuyer || 'Same as buyer');
					same.addEventListener('click', function () { copyBuyer(form, fs); });
					fs.appendChild(same);
				}
				if (nameMode !== 'off' || nameRequired) {
					const nameLabel = el('label', { for: base + '-name' }, (t.name || 'Name') + (nameRequired ? ' *' : ''));
					fs.appendChild(nameLabel);
					const nameInput = el('input', { id: base + '-name', type: 'text', 'data-key': '__name', autocomplete: 'section-att' + count + ' name', maxlength: '190' });
					if (nameRequired) nameInput.required = true;
					fs.appendChild(nameInput);
				}
				fs.appendChild(el('label', { for: base + '-email' }, t.email || 'E-mail'));
				fs.appendChild(el('input', { id: base + '-email', type: 'email', 'data-key': '__email', autocomplete: 'off' }));
				fields.forEach(function (field, index) {
					// A680: order-level fields are asked once, in the first block of the ticket type.
					if (field.scope === 'order' && i > 0) return;
					const id = base + '-f' + index;
					const text = field.label + (field.scope === 'order' ? ' ' + (t.perOrder || '') : '') + (Number(field.required) ? ' *' : '');
					const label = el('label', { for: id, id: id + '-label' }, text.replace(/\s+/g, ' ').trim());
					const input = fieldInput(field, id);
					if (input.dataset.multi || input.dataset.radio) { label.removeAttribute('for'); input.setAttribute('aria-labelledby', id + '-label'); }
					fs.appendChild(label);
					fs.appendChild(input);
					const notes = [];
					if (field.help) { fs.appendChild(el('small', { id: id + '-help' }, field.help)); notes.push(id + '-help'); }
					if (field.purpose) { fs.appendChild(el('small', { id: id + '-purpose' }, field.purpose)); notes.push(id + '-purpose'); }
					if (notes.length) input.setAttribute('aria-describedby', notes.join(' '));
				});
				cache[slot] = fs;
				box.appendChild(fs);
			}
		});
	}
	// Short note for .wpcap-qty-live when the number of attendee blocks changed (A311): the block
	// area itself is no live region, so a quantity change no longer reads out every form field.
	const attendeeCounts = new WeakMap();
	function attendeeHint(form) {
		const n = form.querySelectorAll('.wpcap-attendee').length;
		const before = attendeeCounts.has(form) ? attendeeCounts.get(form) : 0;
		attendeeCounts.set(form, n);
		if (n === before || !n) return '';
		return n === 1 ? (t.attendeesOne || '') : fill(t.attendeesMany || '%d', [n]);
	}
	function announce(form, text) {
		const live = form.querySelector('.wpcap-qty-live');
		if (!live || !text) return;
		// Re-set even when unchanged, so a repeated message is announced again.
		live.textContent = '';
		window.setTimeout(function () { live.textContent = text; }, 30);
	}
	// Same output as PHP Frontend\Format::money(): Money::number() (minor unit decimals per currency) . ' ' . symbol.
	function money(minor, currency) {
		const m = config.money || {};
		const configured = (m.decimals || {})[currency];
		const decimals = Number.isInteger(configured) ? configured : 2;
		const value = Math.abs(Number(minor) || 0) / Math.pow(10, decimals);
		const parts = value.toFixed(decimals).split('.');
		const thousands = typeof m.thousands === 'string' ? m.thousands : '.';
		const integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
		const symbol = (m.symbols || {})[currency] || currency || '';
		const fraction = decimals ? (typeof m.decimal === 'string' ? m.decimal : ',') + parts[1] : '';
		return (minor < 0 ? '-' : '') + integer + fraction + (symbol ? ' ' + symbol : '');
	}
	// A409: "kostenlos" for a free type (same as PHP Frontend\Format::price()).
	function priceText(minor, currency) {
		return Number(minor) === 0 && t.free ? t.free : money(minor, currency);
	}
	// A409: the submit button says "Kostenlos anmelden" while the selected types (nothing selected:
	// all types of the card) are free, else "Zahlungspflichtig bestellen" (labels from the template).
	function syncSubmitLabel(form) {
		const button = form.querySelector('.wpcap-submit');
		if (!button || !button.dataset.labelFree || !button.dataset.labelPaid) return;
		const qtys = Array.prototype.slice.call(form.querySelectorAll('.wpcap-qty'));
		const chosen = qtys.filter(function (qty) { return (Number(qty.value) || 0) > 0; });
		const basis = chosen.length ? chosen : qtys;
		const free = basis.length > 0 && basis.every(function (qty) { return String(qty.dataset.price) === '0'; });
		const label = free ? button.dataset.labelFree : button.dataset.labelPaid;
		if (button.textContent !== label) button.textContent = label;
	}
	// Display only: the server recalculates every price at checkout.
	function renderEstimate(form) {
		syncSubmitLabel(form);
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
		// A602: the sum next to the order button (visual only; the estimate above is the live region).
		const near = form.querySelector('.wpcap-submit-total');
		if (near) near.textContent = total > 0 ? fill(t.submitTotal || '%s', [money(total, currency)]) : '';
		previewDiscount(form, total);
	}

	// Discount code preview (non-binding): POST …/campaigns/preview, debounced.
	const previewTimers = new WeakMap();
	function previewDiscount(form, total) {
		// Discount field, or the single code field (a voucher code there is not previewed).
		const single = form.querySelector('[name="code"]');
		const code = form.querySelector('[name="campaign_code"]') || single;
		if (!code || !config.previewUrl) return;
		clearTimeout(previewTimers.get(form));
		const value = code.value.trim();
		if (!value || total <= 0) return;
		previewTimers.set(form, setTimeout(async function () {
			const items = [];
			form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
				const n = Math.max(0, Number(qty.value) || 0);
				if (n) items.push({ ticket_type_id: Number(qty.dataset.type), quantity: n });
			});
			const box = form.querySelector('.wpcap-estimate');
			try {
				const r = await fetch(config.previewUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: value, items: items }) });
				const d = await r.json().catch(function () { return {}; });
				if (code.value.trim() !== value) return; // Changed meanwhile.
				if (r.ok && box) {
					box.textContent = fill(t.estimateDiscount || '%1$s − %2$s = %3$s', [money(d.subtotal_minor, d.currency), money(d.discount_minor, d.currency), money(d.total_minor, d.currency)]);
				} else if (409 === r.status && box && !single) {
					box.textContent = (t.estimate || '%s').replace('%s', money(total, d.currency || '')) + ' ' + (t.codeInvalid || '');
				}
			} catch (e) { /* Estimate only. */ }
		}, 400));
	}

	/* ---------------------------------------------------------------- Quantity stepper (A100) */

	// Progressive enhancement: −/+ buttons around each .wpcap-qty number field. The field stays the
	// source of truth (typing and arrow keys keep working); the buttons respect min/max and the
	// per-person total (data-max-total on .wpcap-types). Buttons at a limit get aria-disabled instead
	// of disabled, so keyboard focus is never lost; .wpcap-qty-live announces the new quantity.
	function qtyBounds(input) {
		const min = Math.max(0, parseInt(input.min, 10) || 0);
		const max = input.max === '' ? Infinity : Math.max(min, parseInt(input.max, 10) || 0);
		return { min: min, max: max };
	}
	function totalRoom(form, input) {
		const box = form.querySelector('.wpcap-types');
		const limit = box ? parseInt(box.dataset.maxTotal, 10) || 0 : 0;
		if (!limit) return Infinity;
		let others = 0;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) { if (qty !== input) others += Math.max(0, Number(qty.value) || 0); });
		return Math.max(0, limit - others);
	}
	function syncSteppers(form) {
		if (!form) return;
		form.querySelectorAll('.wpcap-stepper').forEach(function (wrap) {
			const input = wrap.querySelector('.wpcap-qty');
			const value = Math.max(0, Number(input.value) || 0);
			const b = qtyBounds(input);
			const up = Math.min(b.max, totalRoom(form, input));
			wrap.querySelector('.wpcap-step-down').setAttribute('aria-disabled', value <= b.min ? 'true' : 'false');
			wrap.querySelector('.wpcap-step-up').setAttribute('aria-disabled', value >= up ? 'true' : 'false');
		});
	}
	function announceQty(form, input, limited) {
		const n = Math.max(0, Number(input.value) || 0);
		const name = input.dataset.name || '';
		let text = n === 0 ? fill(t.qtyNone || '%s: 0', [name]) : (n === 1 ? fill(t.qtyOne || '%s: 1', [name]) : fill(t.qtyMany || '%1$s: %2$d', [name, n]));
		if (limited && t.qtyLimit) text += ' ' + t.qtyLimit;
		const hint = attendeeHint(form);
		if (hint) text += ' ' + hint;
		announce(form, text);
	}
	// Typed quantities: the field announces its own value; the attendee note follows once typing pauses.
	const hintTimers = new WeakMap();
	function scheduleAttendeeHint(form) {
		window.clearTimeout(hintTimers.get(form));
		hintTimers.set(form, window.setTimeout(function () { announce(form, attendeeHint(form)); }, 700));
	}
	let stepping = false;
	function stepQty(button) {
		const wrap = button.closest('.wpcap-stepper');
		const input = wrap && wrap.querySelector('.wpcap-qty');
		const form = input && input.closest('.wpcap-checkout-form');
		if (!form) return;
		const b = qtyBounds(input);
		const current = Math.max(0, Number(input.value) || 0);
		const delta = button.classList.contains('wpcap-step-up') ? 1 : -1;
		const ceiling = Math.min(b.max, totalRoom(form, input));
		const next = Math.min(Math.max(current + delta, b.min), Math.max(b.min, ceiling));
		if (next !== current) {
			input.value = String(next);
			stepping = true;
			try {
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
			} finally {
				stepping = false;
			}
		}
		syncSteppers(form);
		announceQty(form, input, delta > 0 && next >= ceiling);
	}
	function enhanceQty(input) {
		if (input.closest('.wpcap-stepper') || !input.closest('.wpcap-checkout-form')) return;
		const id = ensureId(input, 'wpcap-qty');
		const name = input.dataset.name || '';
		const wrap = el('span', { class: 'wpcap-stepper' });
		const down = el('button', { type: 'button', class: 'wpcap-step wpcap-step-down', 'aria-controls': id, 'aria-label': fill(t.stepDown || '%s −', [name]) }, '−');
		const up = el('button', { type: 'button', class: 'wpcap-step wpcap-step-up', 'aria-controls': id, 'aria-label': fill(t.stepUp || '%s +', [name]) }, '+');
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(down);
		wrap.appendChild(input);
		wrap.appendChild(up);
	}
	document.addEventListener('click', function (event) {
		const button = event.target.closest && event.target.closest('.wpcap-step');
		if (!button) return;
		event.preventDefault();
		if (button.getAttribute('aria-disabled') === 'true') {
			const input = button.closest('.wpcap-stepper').querySelector('.wpcap-qty');
			announceQty(input.closest('.wpcap-checkout-form'), input, button.classList.contains('wpcap-step-up'));
			return;
		}
		stepQty(button);
	});

	/* ---------------------------------------------------------------- Buyer e-mail typo hint (A553) */

	// A mistyped domain ("gmial.com", "web.dee") means no tickets arrive. Close to a common domain
	// (config.emailDomains, filter wpcap_checkout_email_domains) or a mistyped top-level domain:
	// "Meintest du …?" with a button to take it, and one soft stop on submit (sending the same
	// address again goes through). Nothing is changed without the buyer.
	const TLD_FIX = { con: 'com', cmo: 'com', ocm: 'com', vom: 'com', xom: 'com', cpm: 'com', comm: 'com', coom: 'com', nte: 'net', ent: 'net', nett: 'net', dee: 'de', ded: 'de' };
	// Edit distance with adjacent transpositions (optimal string alignment).
	function editDistance(a, b) {
		const d = [];
		for (let i = 0; i <= a.length; i++) { d[i] = [i]; }
		for (let j = 0; j <= b.length; j++) { d[0][j] = j; }
		for (let i = 1; i <= a.length; i++) {
			for (let j = 1; j <= b.length; j++) {
				const cost = a[i - 1] === b[j - 1] ? 0 : 1;
				d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
				if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
			}
		}
		return d[a.length][b.length];
	}
	function emailSuggestion(value) {
		value = String(value || '').trim();
		const at = value.lastIndexOf('@');
		if (at < 1 || at === value.length - 1) return '';
		const local = value.slice(0, at);
		const domain = value.slice(at + 1).toLowerCase();
		const known = Array.isArray(config.emailDomains) ? config.emailDomains : [];
		if (!known.length || known.indexOf(domain) !== -1) return '';
		let best = '';
		let bestScore = Infinity;
		known.forEach(function (candidate) {
			const score = editDistance(domain, candidate);
			if (score && score <= (candidate.length > 7 ? 2 : 1) && score < bestScore) { best = candidate; bestScore = score; }
		});
		if (!best) {
			const parts = domain.split('.');
			const tld = parts.pop();
			if (parts.length && parts.join('') && TLD_FIX[tld]) best = parts.join('.') + '.' + TLD_FIX[tld];
		}
		return best ? local + '@' + best : '';
	}
	function emailHintBox(input) {
		const id = ensureId(input, 'wpcap-email') + '-typo';
		let box = document.getElementById(id);
		if (!box) {
			box = el('p', { id: id, class: 'wpcap-email-hint', 'aria-live': 'polite' });
			const wrapper = input.closest('.wpcap-field');
			if (wrapper) wrapper.appendChild(box); else input.insertAdjacentElement('afterend', box);
			const described = input.getAttribute('aria-describedby');
			input.setAttribute('aria-describedby', ((described || '') + ' ' + id).trim());
		}
		return box;
	}
	function showEmailHint(input, suggestion) {
		const box = emailHintBox(input);
		box.textContent = '';
		if (!suggestion) return;
		box.appendChild(document.createTextNode(fill(t.emailSuggest || '%s?', [suggestion]) + ' '));
		const take = el('button', { type: 'button', class: 'wpcap-email-fix' }, fill(t.emailSuggestApply || '%s', [suggestion]));
		take.addEventListener('click', function () {
			input.value = suggestion;
			box.textContent = '';
			input.focus();
		});
		box.appendChild(take);
	}
	const emailChecked = new WeakMap();
	document.addEventListener('change', function (event) {
		const input = event.target;
		if (input.name !== 'email' || !input.closest || !input.closest('.wpcap-checkout-form')) return;
		showEmailHint(input, emailSuggestion(input.value));
	});
	document.addEventListener('input', function (event) {
		const input = event.target;
		if (input.name !== 'email' || !input.closest || !input.closest('.wpcap-checkout-form')) return;
		const box = document.getElementById(input.id + '-typo');
		if (box && box.textContent && !emailSuggestion(input.value)) box.textContent = '';
	});

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
		if (byIdIn(input.form || input.closest('form'), errorId)) return;
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
			// Fields of an attendee block repeat per ticket: name the block ("Teilnehmer:in 2 · Erwachsene", A551).
			const block = error.input && error.input.closest('.wpcap-attendee');
			const legend = block && block.querySelector('legend');
			const text = legend && error.message.indexOf(legend.textContent) !== 0 ? fill(t.attendeeError || '%1$s: %2$s', [legend.textContent, error.message]) : error.message;
			// A field in a collapsed list (e.g. "Weitere Ticketarten") must be visible to be fixed.
			const collapsed = error.input && error.input.closest('details:not([open])');
			if (collapsed) collapsed.open = true;
			const item = el('li');
			if (error.input) {
				markField(error.input, error.message);
				const link = el('a', { href: '#' + error.input.id }, text);
				link.addEventListener('click', function (event) {
					event.preventDefault();
					const details = error.input.closest('details');
					if (details) details.open = true;
					error.input.scrollIntoView({ block: 'center' });
					error.input.focus();
				});
				item.appendChild(link);
			} else {
				item.textContent = text;
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
		const types = form.querySelector('.wpcap-types');
		const maxTotal = types ? parseInt(types.dataset.maxTotal, 10) || 0 : 0;
		if (maxTotal && selected > maxTotal) {
			const firstSet = qtys.filter(function (qty) { return (Number(qty.value) || 0) > 0; })[0] || qtys[0];
			errors.push({ input: firstSet, message: maxTotal === 1 ? (t.errTotalOne || t.tooMany || '') : fill(t.errTotal || '%d', [maxTotal]) });
		}
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
		// Required option groups (radio): one choice needed; the error points at the first option.
		form.querySelectorAll('[data-radio][data-required]').forEach(function (group) {
			if (group.closest('[hidden]') || group.querySelector('input[type="radio"]:checked')) return;
			const first = group.querySelector('input[type="radio"]');
			const label = group.getAttribute('aria-labelledby') ? document.getElementById(group.getAttribute('aria-labelledby')) : null;
			const text = label ? label.textContent.replace(/\*/g, '').replace(/\s+/g, ' ').trim() : '';
			if (first) errors.push({ input: first, message: fill(t.errRequired || '%s', [text]) });
		});
		// Likely typo in the buyer's e-mail domain (A553): stop once per address.
		const buyer = form.querySelector('[name="email"]');
		if (buyer && !errors.some(function (error) { return error.input === buyer; })) {
			const value = buyer.value.trim();
			const suggestion = emailSuggestion(value);
			if (suggestion && emailChecked.get(form) !== value) {
				emailChecked.set(form, value);
				showEmailHint(buyer, suggestion);
				errors.push({ input: buyer, message: fill(t.emailTypo || '%1$s? %2$s', [suggestion, value]) });
			}
		}
		return errors;
	}
	// A560: the checkout refused a changed date or price (409, data.state = current state). The form
	// takes the new state over (revision, prices, date on the card), so the next submit confirms it.
	function applyShownState(form, data) {
		const info = data && data.data;
		const state = info && info.state;
		if (!state || (info.reason !== 'occurrence_changed' && info.reason !== 'price_changed')) return;
		if (typeof state.revision === 'number') form.dataset.revision = String(state.revision);
		const prices = state.prices || {};
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			if (Object.prototype.hasOwnProperty.call(prices, qty.dataset.type)) qty.dataset.price = String(prices[qty.dataset.type]);
		});
		const card = form.closest('.wpcap-event');
		const time = card && card.querySelector('.wpcap-fact-date time');
		if (time && state.label) {
			time.textContent = state.label;
			if (state.starts_at) time.setAttribute('datetime', state.starts_at);
		}
		renderEstimate(form);
	}
	// Server error -> form control (data.field from the REST response).
	function serverErrorField(form, data) {
		const field = data && data.data && data.data.field;
		if (!field) return null;
		if (field === 'items') return form.querySelector('.wpcap-qty');
		if (field === 'provider') return form.querySelector('[name="provider"]:not([type="hidden"])');
		if (field === 'attendees') {
			// The server names the block: data.index (position in the submitted list) or
			// data.ticket_type_id + data.position (n-th ticket of the type), and data.field_key (A551).
			const blocks = Array.prototype.slice.call(form.querySelectorAll('.wpcap-attendee'));
			const info = data.data;
			let block = Number.isInteger(info.index) ? blocks[info.index] : null;
			if (!block && info.ticket_type_id && Number.isInteger(info.position)) {
				block = blocks.filter(function (fs) { return Number(fs.dataset.type) === Number(info.ticket_type_id); })[info.position] || null;
			}
			if (block) {
				const keyed = Array.prototype.slice.call(block.querySelectorAll('[data-key]'));
				return keyed.filter(function (input) { return info.field_key && input.dataset.key === String(info.field_key); })[0] || keyed[0] || null;
			}
			const inputs = Array.prototype.slice.call(form.querySelectorAll('.wpcap-attendee [data-key]'));
			const quoted = /„([^“]+)“/.exec(data.message || '');
			if (quoted) {
				const match = inputs.filter(function (input) { return labelText(input) === quoted[1]; });
				const empty = match.filter(function (input) { const v = fieldValue(input); return Array.isArray(v) ? !v.length : !String(v).trim(); });
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
		// Within this form only (A308): another card of the same date may carry the same ids (theme templates).
		const box = byIdIn(form, toggle.getAttribute('aria-controls')) || form.querySelector('.wpcap-billing-fields');
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
		// A600: the date into the calendar right away (.ics of the ordered date), not only via the mail.
		const occurrence = String(form.dataset.occurrence || '');
		if (config.icsBase && /^\d+$/.test(occurrence)) {
			const ics = String(config.icsBase) + (String(config.icsBase).indexOf('?') === -1 ? '?' : '&') + 'id=' + occurrence;
			const line = el('p', { class: 'wpcap-ics-link' });
			line.appendChild(el('a', { href: ics, download: 'termin-' + occurrence + '.ics' }, t.addToCalendar || 'Add to calendar (.ics)'));
			panel.appendChild(line);
		}
		const total = data.total_formatted || (typeof data.total_minor === 'number' ? money(data.total_minor, data.currency) : '');
		if (total && Number(data.total_minor) > 0 && t.orderTotal && !(data.payment && data.payment.bank_transfer && data.payment.bank_transfer.iban)) panel.appendChild(el('p', {}, t.orderTotal.replace('%s', total)));
		if (complete) {
			panel.appendChild(el('p', {}, fill(t.ticketsSentTo || t.complete || '%s', [email])));
		} else {
			const payment = data.payment || {};
			// A transfer request needs recipient and IBAN (A265); otherwise only the instruction text.
			const bank = payment.bank_transfer && payment.bank_transfer.iban && payment.bank_transfer.recipient ? payment.bank_transfer : null;
			if (!bank && payment.bank_transfer && payment.bank_transfer.instructions && !payment.instructions) payment.instructions = payment.bank_transfer.instructions;
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
		// Typo in the address noticed only now (A553): say what to do instead of waiting for mail.
		if (t.wrongEmail) panel.appendChild(el('p', { class: 'wpcap-wrong-email' }, t.wrongEmail));
		panel.appendChild(el('p', { class: 'wpcap-hint' }, t.spamHint || ''));
		const again = el('button', { type: 'button', class: 'wp-element-button wpcap-order-again' }, t.orderAgain || 'Order more');
		again.addEventListener('click', function () {
			const keepEmail = form.querySelector('[name="email"]').value;
			panel.remove();
			form.reset();
			form.querySelector('[name="email"]').value = keepEmail;
			clearErrors(form);
			syncBilling(form);
			// A new order starts with empty attendee blocks.
			attendeeSlots.set(form, {});
			attendeeCounts.delete(form);
			form.querySelectorAll('.wpcap-attendee').forEach(function (fs) { fs.remove(); });
			renderAttendees(form);
			renderEstimate(form);
			syncSteppers(form);
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
			if (!form) return;
			renderAttendees(form);
			renderEstimate(form);
			syncSteppers(form);
			// The stepper announces quantity and attendee note in one message.
			if (!stepping) scheduleAttendeeHint(form);
		}
	});
	document.addEventListener('input', function (event) {
		if (event.target.name === 'campaign_code' || event.target.name === 'code') {
			const form = event.target.closest('.wpcap-checkout-form');
			if (form) renderEstimate(form);
		}
	});
	document.addEventListener('change', function (event) {
		const form = event.target.name === 'billing_enabled' ? event.target.closest('.wpcap-checkout-form') : null;
		if (form) syncBilling(form);
	});
	/* ---------------------------------------------------------------- Requests */

	// JSON headers; the REST nonce only exists for signed-in buyers (buyer limits per account).
	function headers() {
		const h = { 'Content-Type': 'application/json' };
		if (config.nonce) h['X-WP-Nonce'] = config.nonce;
		return h;
	}
	// Seconds from a Retry-After header (or data.retry_after), capped by the caller.
	function retryAfter(response, data) {
		let value = response && response.headers && typeof response.headers.get === 'function' ? response.headers.get('Retry-After') : null;
		if ((value === null || value === undefined) && data && data.data) value = data.data.retry_after;
		const n = Number(value);
		return isFinite(n) && n > 0 ? n : 0;
	}
	function wait(ms) {
		return new Promise(function (resolve) { setTimeout(resolve, ms); });
	}
	// 409 with data.remaining (not enough tickets left): the quantity limits follow the current stock.
	function applyRemaining(form, remaining) {
		if (!remaining || typeof remaining !== 'object') return null;
		let target = null;
		Object.keys(remaining).forEach(function (id) {
			const max = Math.max(0, Number(remaining[id]) || 0);
			const qty = form.querySelector('.wpcap-qty[data-type="' + id + '"]');
			if (!qty) return;
			qty.max = String(max);
			if ((Number(qty.value) || 0) > max) qty.value = String(max);
			if (!target && max > 0) target = qty;
		});
		renderAttendees(form);
		renderEstimate(form);
		syncSteppers(form);
		return target;
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
			const n = Number(qty.value) || 0;
			if (n <= 0) return;
			// A560: the date revision and unit price shown; the server refuses a changed state (409).
			const item = { ticket_type_id: Number(qty.dataset.type), quantity: n };
			if (form.dataset.revision !== undefined) item.occurrence_revision = Number(form.dataset.revision);
			if (qty.dataset.price !== undefined) item.expected_price_minor = Number(qty.dataset.price);
			items.push(item);
		});
		const attendees = [];
		form.querySelectorAll('.wpcap-attendee').forEach(function (fs) {
			const entry = { ticket_type_id: Number(fs.dataset.type), answers: {} };
			fs.querySelectorAll('[data-key]').forEach(function (input) {
				const value = fieldValue(input);
				if (input.dataset.key === '__name') entry.name = value;
				else if (input.dataset.key === '__email') entry.email = value;
				else entry.answers[input.dataset.key] = value;
			});
			attendees.push(entry);
		});
		const provider = form.querySelector('[name="provider"]:checked') || form.querySelector('input[type="hidden"][name="provider"]');
		const code = form.querySelector('[name="campaign_code"]');
		const terms = form.querySelector('[name="accept_terms"]');
		const termsVersion = form.querySelector('input[type="hidden"][name="terms_version"]');
		const formLocale = form.querySelector('input[type="hidden"][name="locale"]');
		const voucher = form.querySelector('[name="voucher_code"]');
		const single = form.querySelector('[name="code"]');
		const email = form.querySelector('[name="email"]').value.trim();
		const buyerName = form.querySelector('[name="buyer_name"]');
		const extra = {};
		const billing = billingPayload(form);
		if (billing) extra.billing = billing;
		if (buyerName && buyerName.value.trim()) extra.buyer_name = buyerName.value.trim();
		// Modules (e.g. seat selection) add fields here; they may set detail.error (and detail.field) to block submission.
		const hook = new CustomEvent('wpcap:checkout-payload', { detail: { form: form, items: items, payload: extra, error: '', field: null } });
		document.dispatchEvent(hook);
		if (hook.detail.error) {
			const active = document.activeElement;
			const target = hook.detail.field || (active && active !== button && form.contains(active) && active.matches('input, select, textarea') ? active : null);
			showErrors(form, [{ input: target, message: hook.detail.error }]);
			return;
		}
		if (form.dataset.busy) return; // One request per form at a time (double click, Enter).
		const body = Object.assign({
			email: email,
			items: items,
			attendees: attendees,
			campaign_codes: code && code.value.trim() ? [code.value.trim()] : [],
			provider: provider ? provider.value : 'manual',
			accept_terms: terms ? terms.checked : false,
			terms_version: termsVersion ? termsVersion.value : '',
			locale: formLocale ? formLocale.value : '',
			voucher_code: voucher ? voucher.value.trim() : '',
			code: single ? single.value.trim() : '',
			// A576: the unit prices shown on the card; the server refuses an order that would cost more.
			expected_prices: shownPrices(form)
		}, extra);
		// The idempotency key belongs to exactly this content (A309/A310): the same form sent again after
		// an unclear answer or after "back" from the payment page continues that order; a changed form is
		// a new order.
		// The e-mail counts case-insensitively (QA-1): "B@Example.com" after a network error is the same order.
		const fingerprint = hash(JSON.stringify(Object.assign({}, body, { email: email.toLowerCase() })));
		if (form.dataset.key && form.dataset.keyFor !== fingerprint) forgetKey(form);
		if (!form.dataset.key) { form.dataset.key = uuid(); form.dataset.keyFor = fingerprint; }
		body.idempotency_key = form.dataset.key;
		// A554: Stripe/PayPal/Mollie send the buyer back to this page (the server accepts only URLs of this site).
		body.return_url = String(window.location.href || '').split('#')[0];
		body.return_to = body.return_url; // Older servers read return_to.
		// Disabling the focused button drops keyboard focus to <body>; remember it to restore it afterwards.
		const hadFocus = document.activeElement === button;
		button.disabled = true;
		form.dataset.busy = '1';
		message.textContent = t.creating || '…';
		let done = false;
		try {
			const payload = JSON.stringify(body);
			let response;
			let data = null;
			// A busy server (503 / retryable) is retried automatically up to twice with the same idempotency
			// key (never a second order), after Retry-After plus jitter, so not every buyer clicks again at once.
			for (let attempt = 0; ; attempt++) {
				response = await fetch(config.url, { method: 'POST', headers: headers(), body: payload });
				data = null;
				try { data = await response.json(); } catch (e) { data = null; }
				const retryable = response.status === 503 || !!(data && data.data && data.data.retryable);
				if (response.ok || !retryable || attempt >= 2) break;
				message.textContent = t.retrying || '…';
				await wait(Math.min(10, retryAfter(response, data) || 1.5) * 1000 + Math.floor(Math.random() * 1000));
			}
			const answer = classifyAnswer(response.ok, data);
			if (answer === 'error') {
				// Our API answered with an error: this attempt is finished (nothing was booked, or the order
				// was cancelled), so the next submit starts a new one.
				forgetKey(form);
				message.textContent = '';
				if (data.code === 'wpcap_price_changed') applyNewPrices(form, data.data && data.data.prices);
				applyShownState(form, data);
				if (data.code === 'wpcap_terms_changed') {
					// A457: a cached page shows outdated texts; reload them in place instead of "reload the page".
					const updated = await refreshLegal(form);
					showErrors(form, [{ input: serverErrorField(form, data), message: updated && t.termsUpdated ? t.termsUpdated : (data.message || t.failed || 'Failed.') }]);
					return;
				}
				const adjusted = applyRemaining(form, data.data && data.data.remaining);
				showErrors(form, [{ input: serverErrorField(form, data) || adjusted, message: data.message || t.failed || 'Failed.' }]);
				return;
			}
			if (answer === 'unknown') {
				// A309: no usable answer (cache/WAF/proxy page, cut-off response, HTML error page). The order
				// may exist: the key stays, so sending the form again continues it instead of a second order.
				message.textContent = '';
				showErrors(form, [{ input: null, message: t.unclearAnswer || t.failed || 'Failed.' }]);
				return;
			}
			if (data.payment && data.payment.redirect_url) {
				// A310: the key stays until the order is finished; "back" from the payment page shows it.
				rememberPending(form, data);
				message.textContent = t.redirecting || '…';
				done = true;
				window.location.assign(data.payment.redirect_url);
				return;
			}
			forgetKey(form);
			message.textContent = '';
			done = true;
			showConfirmation(form, data, email);
		} catch (error) {
			// Network error: the request may have arrived, so the key stays (a retry continues the same order).
			message.textContent = '';
			showErrors(form, [{ input: null, message: t.failed || (error && error.message) || 'Failed.' }]);
		} finally {
			delete form.dataset.busy;
			button.disabled = false;
			if (!done && hadFocus && (!document.activeElement || document.activeElement === document.body)) button.focus();
		}
	});

	/* ---------------------------------------------------------------- Price snapshot (A576) */

	// Unit prices (minor units) of the selected ticket types as shown on the card (data-price).
	function shownPrices(form) {
		const prices = {};
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			if ((Number(qty.value) || 0) > 0 && /^\d+$/.test(String(qty.dataset.price || ''))) prices[qty.dataset.type] = Number(qty.dataset.price);
		});
		return prices;
	}
	// 409 price_changed: the card takes over the current prices, so the estimate is right and a new
	// submit (after the buyer saw the message) is accepted.
	function applyNewPrices(form, prices) {
		if (!prices || typeof prices !== 'object') return;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const row = prices[qty.dataset.type];
			if (row && Number.isInteger(row.unit_minor)) {
				qty.dataset.price = String(row.unit_minor);
				// A458: the price next to the ticket type is the new one too, not only the estimate.
				setText(typeRow(qty) && typeRow(qty).querySelector('.wpcap-type-price'), row.unit_minor === 0 ? priceText(0, qty.dataset.currency) : (row.formatted || money(row.unit_minor, qty.dataset.currency)));
			}
		});
		renderEstimate(form);
	}

	/* ---------------------------------------------------------------- Live state (A456, A457, A458) */

	// The card may come from a page cache or CDN. After loading (and when the page comes back from the
	// bfcache) the script asks GET …/checkout/live (no-store) for the current state of every date on the
	// page and switches the card: quantity limits, sold-out types, current (time-dependent) prices,
	// seats-left text and badges, payment methods, the order form or the sold-out / not-on-sale note,
	// the waitlist form, and the legal texts when their version changed. Without an answer the card
	// stays as rendered; the server checks everything again at checkout.
	function setText(node, text) { if (node && typeof text === 'string') node.textContent = text; }
	function show(node, visible) { if (node) node.hidden = !visible; }
	function typeRow(qty) { return qty.closest('.wpcap-type'); }
	function cardOf(form) { return form.closest('.wpcap-event') || form.parentNode; }
	function updateTypePrice(row, state) {
		if (!row) return;
		setText(row.querySelector('.wpcap-type-price'), state.price);
		const label = row.querySelector('.wpcap-price-label');
		if (label) { label.textContent = state.price_label || ''; label.hidden = !state.price_label; }
		const base = row.querySelector('.wpcap-base-price');
		if (base) { base.textContent = state.base_price ? fill(t.basePrice || '%s', [state.base_price]) : ''; base.hidden = !state.base_price; }
	}
	function renderProviders(form, list) {
		const slot = form.querySelector('.wpcap-provider-slot');
		if (!slot) return;
		const wanted = list.map(function (p) { return String(p.id); });
		const current = Array.prototype.slice.call(slot.querySelectorAll('[name="provider"]')).map(function (input) { return input.value; });
		if (current.join(',') === wanted.join(',')) return;
		const checked = slot.querySelector('[name="provider"]:checked');
		const keep = checked && wanted.indexOf(checked.value) !== -1 ? checked.value : wanted[0];
		slot.textContent = '';
		if (wanted.length === 1) {
			slot.appendChild(el('input', { type: 'hidden', name: 'provider', value: wanted[0] }));
		} else if (wanted.length > 1) {
			const box = el('fieldset', { class: 'wpcap-providers wpcap-field' });
			box.appendChild(el('legend', {}, t.providerLegend || ''));
			list.forEach(function (p) {
				const label = el('label');
				const input = el('input', { type: 'radio', name: 'provider', value: String(p.id) });
				if (String(p.id) === keep) input.checked = true;
				label.appendChild(input);
				label.appendChild(document.createTextNode(' ' + String(p.title || p.id)));
				box.appendChild(label);
			});
			slot.appendChild(box);
		}
	}
	function renderBadges(card, badges) {
		const header = card && card.querySelector('.wpcap-event-header');
		if (!header || !Array.isArray(badges)) return;
		let list = header.querySelector('.wpcap-badges');
		// "Nächster Termin" depends on the whole listing: kept as rendered.
		const next = list ? list.querySelector('.wpcap-badge-next') : null;
		if (!list) {
			if (!badges.length) return;
			list = el('ul', { class: 'wpcap-badges' });
			const title = header.querySelector('.wpcap-event-title');
			if (title) title.insertAdjacentElement('afterend', list); else header.insertBefore(list, header.firstChild);
		}
		list.textContent = '';
		if (next) list.appendChild(next);
		badges.forEach(function (badge) {
			const key = String(badge.key || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
			list.appendChild(el('li', { class: 'wpcap-badge' + (key ? ' wpcap-badge-' + key : '') }, String(badge.label || '')));
		});
		if (!list.children.length) list.remove();
	}
	function renderWaitlist(form, state) {
		const card = cardOf(form);
		const wl = card && card.querySelector('.wpcap-waitlist-form[data-occurrence="' + String(form.dataset.occurrence || '').replace(/["\\]/g, '') + '"]');
		if (!wl || !Array.isArray(state.waitlist_types)) return;
		const eligible = state.waitlist ? state.waitlist_types.map(String) : [];
		wl.hidden = !eligible.length;
		const select = wl.querySelector('select[name="ticket_type_id"]');
		if (!select) return;
		let first = null;
		let keep = false;
		select.querySelectorAll('option').forEach(function (option) {
			const ok = eligible.indexOf(option.value) !== -1;
			option.disabled = !ok;
			option.hidden = !ok;
			if (ok && !first) first = option;
			if (ok && option.value === String(select.value)) keep = true;
		});
		if (first && !keep) { select.value = first.value; first.selected = true; }
		show(select.closest('.wpcap-field'), eligible.length > 1);
		const chosen = keep ? select.querySelector('option[value="' + String(select.value).replace(/["\\]/g, '') + '"]') : first;
		const qty = wl.querySelector('[name="quantity"]');
		if (qty && chosen && chosen.dataset.max) qty.max = chosen.dataset.max;
	}
	function applyLive(form, state, legal) {
		if (!state || typeof state !== 'object') return;
		const card = cardOf(form);
		const onSale = state.on_sale !== false;
		const types = onSale && state.types && typeof state.types === 'object' ? state.types : {};
		const providers = Array.isArray(state.providers) ? state.providers : [];
		const noPayment = !onSale || !providers.length;
		let open = false;
		form.querySelectorAll('.wpcap-qty').forEach(function (qty) {
			const row = types[qty.dataset.type] || null;
			const max = row ? Math.max(0, parseInt(row.max, 10) || 0) : 0;
			qty.max = String(max);
			if (row && /^\d+$/.test(String(row.price_minor))) qty.dataset.price = String(row.price_minor);
			if ((Number(qty.value) || 0) > max) qty.value = String(max);
			qty.disabled = max < 1 || noPayment;
			if (max > 0) open = true;
			const wrap = typeRow(qty);
			if (wrap) {
				show(wrap.querySelector('.wpcap-qty-box'), max > 0);
				show(wrap.querySelector('.wpcap-soldout'), max < 1);
				if (row) updateTypePrice(wrap, row);
			}
		});
		const typesBox = form.querySelector('.wpcap-types');
		if (typesBox && Number.isInteger(state.max_total)) {
			if (state.max_total > 0) typesBox.dataset.maxTotal = String(state.max_total); else delete typesBox.dataset.maxTotal;
		}
		if (onSale) renderProviders(form, providers);
		const soldOut = onSale && !open;
		show(form.querySelector('.wpcap-soldout-note'), soldOut);
		show(form.querySelector('.wpcap-no-payment'), !soldOut && noPayment);
		show(form.querySelector('.wpcap-buy'), !soldOut && !noPayment);
		if (card) {
			setText(card.querySelector('.wpcap-fact-availability dd'), state.availability);
			setText(card.querySelector('.wpcap-fact-price dd'), state.price);
			renderBadges(card, state.badges);
		}
		renderWaitlist(form, state);
		renderAttendees(form);
		renderEstimate(form);
		syncSteppers(form);
		const version = form.querySelector('input[type="hidden"][name="terms_version"]');
		if (legal && version && typeof legal.terms_version === 'string' && legal.terms_version !== '' && legal.terms_version !== version.value) refreshLegal(form);
	}
	async function refreshLive(only) {
		if (!config.liveUrl) return;
		const forms = Array.prototype.slice.call(only || document.querySelectorAll('.wpcap-checkout-form[data-occurrence]')).filter(function (form) { return form.matches('.wpcap-checkout-form[data-occurrence]'); });
		const ids = [];
		forms.forEach(function (form) { const id = String(form.dataset.occurrence || ''); if (/^\d+$/.test(id) && ids.indexOf(id) === -1) ids.push(id); });
		if (!ids.length) return;
		const localeInput = forms[0].querySelector('input[type="hidden"][name="locale"]');
		// Unlock code of the page (?wpcap_code=): code-only ticket types it unlocks stay in the live state.
		let unlockCode = '';
		try { unlockCode = new URLSearchParams(window.location.search || '').get('wpcap_code') || ''; } catch (e) { unlockCode = ''; }
		for (let i = 0; i < ids.length; i += 50) {
			const chunk = ids.slice(i, i + 50);
			const url = config.liveUrl + (config.liveUrl.indexOf('?') === -1 ? '?' : '&') + 'occurrences=' + chunk.join(',') + (localeInput && localeInput.value ? '&locale=' + encodeURIComponent(localeInput.value) : '') + (unlockCode ? '&code=' + encodeURIComponent(unlockCode) : '');
			try {
				const response = await fetch(url, { method: 'GET', cache: 'no-store', headers: { Accept: 'application/json' } });
				if (!response.ok) continue;
				const data = await response.json();
				const states = data && data.occurrences && typeof data.occurrences === 'object' ? data.occurrences : null;
				if (!states) continue;
				forms.forEach(function (form) {
					if (chunk.indexOf(String(form.dataset.occurrence)) !== -1 && !form.dataset.busy) applyLive(form, states[form.dataset.occurrence], data.legal);
				});
			} catch (e) { /* Cached state stays; the server checks at checkout. */ }
		}
	}
	// A457: current legal texts (GET …/checkout/options, no-store) into the card. True when updated.
	async function refreshLegal(form) {
		if (!config.optionsUrl) return false;
		const localeInput = form.querySelector('input[type="hidden"][name="locale"]');
		const url = config.optionsUrl + (config.optionsUrl.indexOf('?') === -1 ? '?' : '&') + 'locale=' + encodeURIComponent(localeInput ? localeInput.value : '');
		let data = null;
		try {
			const response = await fetch(url, { method: 'GET', cache: 'no-store', headers: { Accept: 'application/json' } });
			if (!response.ok) return false;
			data = await response.json();
		} catch (e) { return false; }
		if (!data || typeof data.terms_version !== 'string') return false;
		const version = form.querySelector('input[type="hidden"][name="terms_version"]');
		if (!version) return false;
		let replaced = false;
		[['terms', data.terms_html], ['consumer_info', data.consumer_info_html]].forEach(function (pair) {
			const box = form.querySelector('.wpcap-legal[data-legal="' + pair[0] + '"]');
			const text = box && box.querySelector('.wpcap-legal-text');
			if (!text || typeof pair[1] !== 'string') return;
			text.innerHTML = pair[1]; // Sanitised on the server (wp_kses_post).
			box.hidden = pair[1] === '';
			if (pair[0] === 'terms') box.open = true;
			replaced = true;
		});
		// Without replaceable text containers (older theme template) the buyer would confirm unseen texts.
		if (!replaced) return false;
		const changed = version.value !== data.terms_version;
		version.value = data.terms_version;
		const terms = form.querySelector('[name="accept_terms"]');
		if (terms && changed) terms.checked = false;
		return true;
	}
	window.addEventListener('pageshow', function (event) { if (event.persisted) refreshLive(); });
	// While the page is visible the live state is refreshed every config.poll seconds (15–60, plus jitter),
	// so a sale start or a sell-out shows without a reload; a hidden tab catches up when shown again.
	let liveMissed = false;
	function scheduleLivePoll() {
		const every = Number(config.poll) || 0;
		if (!every || !config.liveUrl) return;
		setTimeout(function () {
			if (document.hidden) liveMissed = true; else refreshLive();
			scheduleLivePoll();
		}, Math.min(60, Math.max(15, every)) * 1000 + Math.floor(Math.random() * 3000));
	}
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && liveMissed) { liveMissed = false; refreshLive(); }
	});

	/* ---------------------------------------------------------------- Pending order (A310) */

	// After the redirect to a hosted payment page the form keeps its key, and the tab's sessionStorage
	// remembers order number, payment link and key (no personal data; the form content only as a hash).
	// Coming back with "back" (bfcache: pageshow persisted) or after a reload, the card points to the
	// pending order instead of silently offering a second one.
	const PENDING_PREFIX = 'wpcap-pending-';
	function pendingSlot(form) { return PENDING_PREFIX + (form.dataset.occurrence || form.id || 'form'); }
	function readStore(name) { try { return window.sessionStorage.getItem(name); } catch (e) { return null; } }
	function writeStore(name, value) { try { if (value === null) window.sessionStorage.removeItem(name); else window.sessionStorage.setItem(name, value); } catch (e) { /* Private mode: the form still has the key. */ } }
	function rememberPending(form, data) {
		const minutes = Math.max(1, parseInt(data.hold_minutes, 10) || 30);
		const entry = { key: form.dataset.key, keyFor: form.dataset.keyFor || '', reference: String(data.reference || ''), redirect: String(data.payment.redirect_url), until: Date.now() + minutes * 60000 };
		form.dataset.pending = JSON.stringify(entry);
		writeStore(pendingSlot(form), JSON.stringify(entry));
	}
	function pendingEntry(form) {
		let entry = null;
		try { entry = JSON.parse(form.dataset.pending || readStore(pendingSlot(form)) || 'null'); } catch (e) { entry = null; }
		if (!entry || typeof entry !== 'object' || !entry.key || !entry.until || entry.until <= Date.now() || !/^https?:\/\//i.test(String(entry.redirect || ''))) {
			if (entry) clearPending(form);
			return null;
		}
		return entry;
	}
	function clearPending(form) {
		delete form.dataset.pending;
		writeStore(pendingSlot(form), null);
		const notice = form.querySelector('.wpcap-pending-order');
		if (notice) notice.remove();
	}
	function forgetKey(form) {
		delete form.dataset.key;
		delete form.dataset.keyFor;
		clearPending(form);
	}
	function showPending(form) {
		const entry = pendingEntry(form);
		if (!entry) return;
		form.dataset.key = entry.key;
		form.dataset.keyFor = entry.keyFor;
		form.dataset.pending = JSON.stringify(entry);
		// Restored from the bfcache: the page still says "Weiterleitung …" with a disabled button.
		const message = form.querySelector('.wpcap-message');
		if (message) message.textContent = '';
		const button = form.querySelector('button[type="submit"]');
		if (button) button.disabled = false;
		delete form.dataset.busy;
		const old = form.querySelector('.wpcap-pending-order');
		if (old) old.remove();
		const notice = el('div', { class: 'wpcap-pending-order', role: 'status' });
		const p = el('p');
		const parts = (t.pendingOrder || '%s').split('%s');
		p.appendChild(document.createTextNode(parts[0] || ''));
		p.appendChild(el('strong', {}, entry.reference));
		p.appendChild(document.createTextNode(parts[1] || ''));
		notice.appendChild(p);
		notice.appendChild(el('a', { href: entry.redirect, class: 'wp-element-button wpcap-resume-payment' }, t.resumePayment || 'Continue payment'));
		notice.appendChild(el('p', { class: 'wpcap-hint' }, t.pendingHint || ''));
		form.insertBefore(notice, form.firstChild);
	}
	// Back on the page after paying (or after cancelling the order on the return page): nothing is pending any more.
	const returned = /[?&]wpcap_payment=(success|pending|cancelled)(&|$)/.exec(window.location.search || '');
	window.addEventListener('pageshow', function (event) {
		if (!event.persisted) return;
		document.querySelectorAll('.wpcap-checkout-form').forEach(showPending);
	});
	// A504: every date on the page keeps its anchor #wpcap-occ-{id} (calendar links, .ics URLs). The
	// server gives it to the first card of the date; when that render was discarded (a theme or SEO
	// plugin running the_content twice), the first card on the page without an id gets it here –
	// and is scrolled to when the URL asks for it, since the browser could not find it on load.
	function ensureAnchors(root) {
		(root || document).querySelectorAll('.wpcap-checkout-form[data-occurrence], .wpcap-event-presale[data-occurrence]').forEach(function (node) {
			const occurrence = String(node.dataset.occurrence || '');
			if (!/^\d+$/.test(occurrence)) return;
			const id = 'wpcap-occ-' + occurrence;
			if (document.getElementById(id)) return;
			const card = node.classList.contains('wpcap-event') ? node : node.closest('.wpcap-event');
			if (!card || card.id) return;
			card.id = id;
			if (String((window.location && window.location.hash) || '') === '#' + id && card.scrollIntoView) card.scrollIntoView();
		});
	}
	// A410: the quantity limit of the waitlist follows the chosen ticket type (option data-max).
	function syncWaitlistMax(form) {
		const select = form.querySelector('select[name="ticket_type_id"]');
		const qty = form.querySelector('[name="quantity"]');
		const option = select && select.querySelector('option[value="' + String(select.value).replace(/["\\]/g, '') + '"]');
		const max = option ? parseInt(option.dataset.max, 10) || 0 : 0;
		if (!qty || max < 1) return;
		qty.max = String(max);
		if ((Number(qty.value) || 0) > max) qty.value = String(max);
	}
	document.addEventListener('change', function (event) {
		const form = event.target.name === 'ticket_type_id' && event.target.closest ? event.target.closest('.wpcap-waitlist-form') : null;
		if (form) syncWaitlistMax(form);
	});
	// Waitlist on sold-out cards: POST …/events/{event}/waitlist.
	document.addEventListener('submit', async function (event) {
		var form = event.target;
		if (!form || !form.classList || !form.classList.contains('wpcap-waitlist-form')) return;
		event.preventDefault();
		var message = form.querySelector('.wpcap-message');
		var button = form.querySelector('button[type="submit"]');
		var email = form.elements.email;
		if (!email.value || !email.checkValidity()) {
			email.setAttribute('aria-invalid', 'true');
			message.textContent = t.waitlistEmail || '';
			email.focus();
			return;
		}
		email.removeAttribute('aria-invalid');
		button.disabled = true;
		message.textContent = t.waitlistJoining || '…';
		try {
			var response = await fetch((config.eventsUrl || '') + encodeURIComponent(form.dataset.event) + '/waitlist', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ occurrence_id: Number(form.dataset.occurrence), ticket_type_id: Number(form.elements.ticket_type_id.value), quantity: Number(form.elements.quantity.value || 1), email: email.value })
			});
			var data = {};
			try { data = await response.json(); } catch (e) { data = {}; }
			if (!response.ok) throw new Error(data.message || t.waitlistFailed || 'Failed.');
			message.textContent = t.waitlistJoined || '';
			form.querySelectorAll('input,select,button').forEach(function (n) { n.disabled = true; });
			// A395: the focused button is disabled now – keyboard focus goes to the confirmation.
			message.setAttribute('tabindex', '-1');
			message.focus();
		} catch (error) {
			message.textContent = (error && error.message) || t.waitlistFailed || 'Failed.';
			button.disabled = false;
			// A395: disabling the button dropped the focus to <body>; give it back.
			if (!document.activeElement || document.activeElement === document.body) button.focus();
		}
	});

	/* ---------------------------------------------------------------- Initialisation (A500) */

	// Every handler above is delegated to the document; what a form needs once is done here – for the
	// forms in the page and for forms added later (AJAX filters, infinite scroll, popups, page
	// builders): window.WPCAP.init(root), the event "wpcap:init" ({ detail: { root } }) or, without
	// either, a MutationObserver on the page. Each form is set up once.
	const initialised = new WeakMap();
	function within(root, selector) {
		const out = [];
		if (!root) return out;
		if (root.matches && root.matches(selector)) out.push(root);
		if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) { out.push(node); });
		return out;
	}
	function init(root) {
		root = root || document;
		const forms = within(root, '.wpcap-checkout-form').filter(function (form) { return !initialised.has(form); });
		const waitlists = within(root, '.wpcap-waitlist-form').filter(function (form) { return !initialised.has(form); });
		forms.forEach(function (form) {
			initialised.set(form, true);
			form.querySelectorAll('.wpcap-qty').forEach(enhanceQty);
			syncSteppers(form);
			form.querySelectorAll('[name="email"]').forEach(emailHintBox);
			syncBilling(form);
			syncSubmitLabel(form);
			if (returned) clearPending(form); else showPending(form);
		});
		waitlists.forEach(function (form) {
			initialised.set(form, true);
			syncWaitlistMax(form);
		});
		// A450: the order and waitlist forms ship with a disabled submit button (without JavaScript
		// nothing is sent, never as GET with the e-mail address in the URL); the handlers are attached now.
		forms.concat(waitlists).forEach(function (form) {
			form.querySelectorAll('[data-wpcap-requires-js]').forEach(function (button) {
				button.disabled = false;
				button.removeAttribute('data-wpcap-requires-js');
			});
		});
		ensureAnchors(root);
		if (forms.length) refreshLive(forms);
		return forms.length + waitlists.length;
	}
	window.WPCAP = window.WPCAP || {};
	window.WPCAP.init = init;
	document.addEventListener('wpcap:init', function (event) { init((event.detail && event.detail.root) || event.target); });
	init(document);
	if (document.querySelector('.wpcap-checkout-form[data-occurrence]')) scheduleLivePoll();
	if (typeof window.MutationObserver === 'function' && document.body) {
		new window.MutationObserver(function (records) {
			records.forEach(function (record) {
				Array.prototype.forEach.call(record.addedNodes || [], function (node) {
					if (node.nodeType === 1 && (node.matches('.wpcap-checkout-form, .wpcap-waitlist-form') || node.querySelector('.wpcap-checkout-form, .wpcap-waitlist-form'))) init(node);
				});
			});
		}).observe(document.body, { childList: true, subtree: true });
	}
	// Elementor renders widgets (popups, tabs, loop items) after load and announces each one.
	if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', function () {
			if (window.elementorFrontend && window.elementorFrontend.hooks) window.elementorFrontend.hooks.addAction('frontend/element_ready/global', function (scope) { init(scope && scope[0]); });
		});
	}
	// Test hook for tests/js (node:test); `module` does not exist in the browser, so this is a no-op there.
	if (typeof module === 'object' && module && module.exports) { module.exports = { holdText: holdText, fill: fill, money: money, renderAttendees: renderAttendees, renderEstimate: renderEstimate, labelText: labelText, validate: validate, showErrors: showErrors, clearErrors: clearErrors, serverErrorField: serverErrorField, billingPayload: billingPayload, headers: headers, retryAfter: retryAfter, applyRemaining: applyRemaining, refreshLive: refreshLive, classifyAnswer: classifyAnswer, hash: hash }; }
})();
