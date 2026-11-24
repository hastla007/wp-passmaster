/*
 * Service slot booking (A268): [wpcap_service_booking] / block pass-captain/service-booking.
 *
 * Progressive enhancement of the server-rendered form (Frontend\ServiceBooking): week navigation
 * (GET …/events/{id}/service-slots?resource=&week=), slot and quantity choice, customer data, then
 * POST …/service-slots/{id}/bookings (hold + booking token) and POST …/service-bookings/{id}/confirm
 * (free: confirmed at once; paid: regular checkout → payment redirect or bank transfer details).
 * The confirmation carries a manage link (#wpcap-booking=<id>-<token>; the token stays in the URL
 * fragment) under which the same block shows the booking with rebook and cancel; dearer slots are
 * offered with a supplementary payment for the difference (the booking moves once it is paid).
 * Strings come from the localized catalogue WPCAP_SERVICE_BOOKING.i18n (text domain pass-captain).
 */
(function () {
	'use strict';
	const config = (typeof window !== 'undefined' && window.WPCAP_SERVICE_BOOKING) || {};
	const t = config.i18n || {};

	/* ---------------------------------------------------------------- Pure helpers (tested in tests/js) */

	function fill(text, values) {
		let i = 0;
		return String(text || '').replace(/%(\d\$)?[sd]/g, function (match, pos) {
			const index = pos ? parseInt(pos, 10) - 1 : i++;
			return values[index] !== undefined ? String(values[index]) : match;
		});
	}
	/** "1 Platz" / "3 Plätze": separate catalogue entries for one and many (no plural rules in JS). */
	function plural(n, one, many) {
		return n === 1 && one ? one : fill(many || '%d', [n]);
	}
	/** Calendar arithmetic on Y-m-d strings (no timezone involved). */
	function addDays(ymd, days) {
		const d = new Date(ymd + 'T00:00:00Z');
		d.setUTCDate(d.getUTCDate() + days);
		return d.toISOString().slice(0, 10);
	}
	/** 'Y-m-d H:i:s' (UTC, as the REST API returns it) → Date. */
	function parseUtc(value) {
		return new Date(String(value).replace(' ', 'T') + 'Z');
	}
	/** Local calendar date (Y-m-d) of a UTC time in the IANA zone tz. */
	function localDate(utc, tz) {
		const parts = {};
		new Intl.DateTimeFormat('en-US', { timeZone: tz || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' })
			.formatToParts(utc instanceof Date ? utc : parseUtc(utc))
			.forEach(function (p) { parts[p.type] = p.value; });
		return parts.year + '-' + parts.month + '-' + parts.day;
	}
	/** Monday (Y-m-d) of the local week of a Y-m-d date. */
	function mondayOf(ymd) {
		const weekday = new Date(ymd + 'T00:00:00Z').getUTCDay(); // 0 = Sunday
		return addDays(ymd, -((weekday + 6) % 7));
	}
	/** Monday (Y-m-d, zone tz) of the week containing the UTC time. */
	function weekOf(utc, tz) {
		return mondayOf(localDate(utc, tz));
	}
	/** Slots (REST rows, ordered by start) grouped by local day: [{day, slots}]. */
	function groupByDay(slots, tz) {
		const days = [];
		(slots || []).forEach(function (slot) {
			const day = localDate(slot.starts_at, tz);
			const last = days[days.length - 1];
			if (last && last.day === day) last.slots.push(slot);
			else days.push({ day: day, slots: [slot] });
		});
		return days;
	}
	/** REST URL from the base (pretty or ?rest_route= permalinks), a path and query parameters. */
	function apiUrl(base, path, params) {
		let url = String(base || '') + String(path || '').replace(/^\//, '');
		const query = Object.keys(params || {}).filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
		if (query) url += (url.indexOf('?') === -1 ? '?' : '&') + query;
		return url;
	}
	/** "#wpcap-booking=<id>-<64 hex token>" → {id, token} or null. */
	function parseManageHash(hash) {
		const m = /^#?wpcap-booking=(\d+)-([a-f0-9]{64})$/i.exec(String(hash || ''));
		return m ? { id: parseInt(m[1], 10), token: m[2].toLowerCase() } : null;
	}
	function manageUrl(href, id, token) {
		return String(href || '').split('#')[0] + '#wpcap-booking=' + id + '-' + token;
	}
	/** Upper bound of the quantity field: free places of the slot, the resource limit and 20. */
	function maxQuantity(available, resource) {
		const limit = Math.min(20, Number(resource && resource.maxQty) || 20);
		return Math.max(1, Math.min(Number(available) || 1, limit));
	}
	/** Deadline in hours → "1 Tag" / "2 Tage" / "1 Stunde" / "36 Stunden" (like ServiceBookings::duration_label()). */
	function durationLabel(hours) {
		hours = Math.max(0, parseInt(hours, 10) || 0);
		if (hours && hours % 24 === 0) return plural(hours / 24, t.durationDay, t.durationDays);
		return plural(hours, t.durationHour, t.durationHours);
	}
	/** Whether "hours before start" has passed (0 hours = the start). */
	function deadlinePassed(startsAt, hours, now) {
		return (now || Date.now()) >= parseUtc(startsAt).getTime() - (parseInt(hours, 10) || 0) * 3600000;
	}
	// Same output as PHP Frontend\Format::money() (see checkout.js).
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
	function formatDay(ymd, locale) {
		return new Intl.DateTimeFormat(locale || undefined, { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(ymd + 'T12:00:00Z'));
	}
	function formatDate(ymd, locale) {
		return new Intl.DateTimeFormat(locale || undefined, { timeZone: 'UTC', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(ymd + 'T12:00:00Z'));
	}
	function formatTime(utc, locale, tz) {
		return new Intl.DateTimeFormat(locale || undefined, { timeZone: tz || 'UTC', hour: 'numeric', minute: '2-digit' }).format(parseUtc(utc));
	}
	function formatDateTime(utc, locale, tz) {
		return new Intl.DateTimeFormat(locale || undefined, { timeZone: tz || 'UTC', weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(parseUtc(utc));
	}
	function weekLabel(week, locale) {
		return fill(t.weekRange || '%1$s – %2$s', [formatDate(week, locale), formatDate(addDays(week, 6), locale)]);
	}
	/**
	 * Rebooking targets of the manage view (PASS-FE-17 follow-up): slots at the same price
	 * (rebook_options) and dearer slots with a supplementary payment (supplement_options), by start.
	 * Each choice carries supplement_minor (0 = no supplement) and currency.
	 */
	function rebookChoices(view) {
		const seen = {};
		const out = [];
		const currency = (view && view.currency) || '';
		[[(view && view.rebook_options) || [], false], [(view && view.supplement_options) || [], true]].forEach(function (group) {
			group[0].forEach(function (o) {
				const id = Number(o.slot_id);
				const supplement = group[1] ? Math.max(0, Number(o.supplement_minor) || 0) : 0;
				if (!id || seen[id] || (group[1] && !supplement)) return;
				seen[id] = true;
				out.push({ slot_id: id, resource_name: o.resource_name || '', starts_at: o.starts_at, supplement_minor: supplement, currency: o.currency || currency });
			});
		});
		return out.sort(function (a, b) { return a.starts_at < b.starts_at ? -1 : (a.starts_at > b.starts_at ? 1 : a.slot_id - b.slot_id); });
	}
	/** Option text: "Boot 1 · <date and time>", dearer slots with " (Zuzahlung 20,00 €)". */
	function choiceLabel(choice, locale, tz) {
		const text = (choice.resource_name || '') + ' · ' + formatDateTime(choice.starts_at, locale, tz);
		return choice.supplement_minor ? text + ' (' + fill(t.supplementChoice || '+%s', [money(choice.supplement_minor, choice.currency)]) + ')' : text;
	}
	/** How a started payment continues: 'redirect' (hosted page), 'bank' (transfer details), 'instructions' or ''. */
	function paymentKind(payment) {
		if (!payment) return '';
		if (payment.redirect_url && /^https?:\/\//i.test(String(payment.redirect_url))) return 'redirect';
		if (payment.bank_transfer) return 'bank';
		return payment.instructions ? 'instructions' : '';
	}

	const api = { fill: fill, plural: plural, addDays: addDays, localDate: localDate, mondayOf: mondayOf, weekOf: weekOf, groupByDay: groupByDay, apiUrl: apiUrl, parseManageHash: parseManageHash, manageUrl: manageUrl, maxQuantity: maxQuantity, durationLabel: durationLabel, deadlinePassed: deadlinePassed, money: money, weekLabel: weekLabel, rebookChoices: rebookChoices, choiceLabel: choiceLabel, paymentKind: paymentKind };
	if (typeof window !== 'undefined') window.wpcapServiceBooking = api;
	if (typeof document === 'undefined' || !document.querySelectorAll) return;

	/* ---------------------------------------------------------------- DOM helpers */

	function el(tag, attrs, text) {
		const node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text) node.textContent = text;
		return node;
	}
	function uuid() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2);
	}
	async function request(method, path, body, params, override) {
		const headers = { Accept: 'application/json' };
		if (body) headers['Content-Type'] = 'application/json';
		// The booking token is sent in the JSON body only, never in a URL: reads use POST with a method override.
		if (override) headers['X-HTTP-Method-Override'] = override;
		const response = await fetch(apiUrl(config.restUrl, path, params), { method: method, headers: headers, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' });
		let data = {};
		try { data = await response.json(); } catch (e) { data = {}; }
		return { ok: response.ok, status: response.status, data: data || {} };
	}
	function announce(live, text) {
		if (!live) return;
		live.textContent = '';
		window.setTimeout(function () { live.textContent = text; }, 40);
	}
	function setBusy(button, busy) {
		if (!button) return;
		button.disabled = !!busy;
		if (busy) button.setAttribute('aria-busy', 'true'); else button.removeAttribute('aria-busy');
	}

	/* ---------------------------------------------------------------- Errors */

	function clearErrors(scope) {
		scope.querySelectorAll('.wpcap-error-summary, .wpcap-field-error').forEach(function (node) { node.remove(); });
		scope.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
			input.removeAttribute('aria-invalid');
			const original = input.dataset.wpcapDescribedby;
			if (original) input.setAttribute('aria-describedby', original); else input.removeAttribute('aria-describedby');
			delete input.dataset.wpcapDescribedby;
		});
	}
	function markField(input, text) {
		if (!input.id) input.id = 'wpcap-sb-f-' + Math.random().toString(16).slice(2, 8);
		const errorId = input.id + '-error';
		if (document.getElementById(errorId)) return;
		const note = el('p', { id: errorId, class: 'wpcap-field-error' }, text);
		const anchor = input.type === 'radio' ? (input.closest('fieldset') || input) : (input.closest('.wpcap-field') || input);
		if (anchor === input) input.insertAdjacentElement('afterend', note); else anchor.appendChild(note);
		if (input.getAttribute('aria-describedby')) input.dataset.wpcapDescribedby = input.getAttribute('aria-describedby');
		input.setAttribute('aria-invalid', 'true');
		input.setAttribute('aria-describedby', ((input.dataset.wpcapDescribedby || '') + ' ' + errorId).trim());
	}
	function showErrors(scope, before, errors, level) {
		const summary = el('div', { class: 'wpcap-error-summary', role: 'alert', tabindex: '-1' });
		const headingId = 'wpcap-sb-err-' + Math.random().toString(16).slice(2, 8);
		summary.setAttribute('aria-labelledby', headingId);
		summary.appendChild(el('h' + Math.min(6, level), { id: headingId }, t.errorSummary || 'Please check your details'));
		const list = el('ul');
		errors.forEach(function (error) {
			const item = el('li');
			if (error.input) {
				markField(error.input, error.message);
				const link = el('a', { href: '#' + error.input.id }, error.message);
				link.addEventListener('click', function (event) {
					event.preventDefault();
					const details = error.input.closest('details');
					if (details) details.open = true;
					error.input.focus();
				});
				item.appendChild(link);
			} else {
				item.textContent = error.message;
			}
			list.appendChild(item);
		});
		summary.appendChild(list);
		scope.insertBefore(summary, before || scope.firstChild);
		summary.focus();
	}
	function labelText(input) {
		const label = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
		return (label ? label.textContent : input.name || '').replace(/\*/g, '').replace(/\((optional[^)]*)\)/i, '').replace(/\s+/g, ' ').trim();
	}

	/* ---------------------------------------------------------------- One booking widget */

	function init(root) {
		let cfg;
		try { cfg = JSON.parse(root.getAttribute('data-wpcap-sb') || '{}'); } catch (e) { return; }
		const form = root.querySelector('.wpcap-sb-form');
		if (!form || root.dataset.wpcapSbReady) return;
		root.dataset.wpcapSbReady = '1';
		const q = function (sel) { return root.querySelector(sel); };
		const step1 = q('.wpcap-sb-step-slot');
		const step2 = q('.wpcap-sb-step-data');
		const prev = q('.wpcap-sb-prev');
		const next = q('.wpcap-sb-next');
		const weekText = q('.wpcap-sb-week');
		const days = q('.wpcap-sb-days');
		const qty = q('.wpcap-sb-qty');
		const estimate = q('.wpcap-sb-estimate');
		const summary = q('.wpcap-sb-summary');
		const paid = q('.wpcap-sb-paid');
		const submit = q('.wpcap-sb-submit');
		const message = form.querySelector('.wpcap-message');
		const live = q('.wpcap-sb-live');
		const level = Number(cfg.level) || 2;
		const resources = {};
		(cfg.resources || []).forEach(function (r) { resources[r.id] = r; });
		const state = { resource: cfg.resource, week: cfg.week, seq: 0, pending: null, key: null };
		const uid = root.id || 'wpcap-sb';

		function resource() { return resources[state.resource] || {}; }
		function selectedSlot() { return form.querySelector('input[name="slot_id"]:checked'); }

		function syncNav() {
			const r = resource();
			prev.setAttribute('aria-disabled', !state.week || state.week <= cfg.todayWeek ? 'true' : 'false');
			next.setAttribute('aria-disabled', !state.week || !r.lastWeek || state.week >= r.lastWeek ? 'true' : 'false');
		}
		function syncQuantity() {
			const slot = selectedSlot();
			const max = maxQuantity(slot ? slot.dataset.available : resource().maxQty, resource());
			qty.max = String(max);
			if ((Number(qty.value) || 0) > max) qty.value = String(max);
			const r = resource();
			const n = Math.max(1, Number(qty.value) || 1);
			estimate.textContent = r.paid && r.priceMinor ? fill(t.estimate || '%s', [money(r.priceMinor * n, r.currency)]) : '';
		}
		function syncPaid() {
			const r = resource();
			if (paid) paid.hidden = !r.priced;
			submit.textContent = r.priced ? submit.dataset.labelPaid : submit.dataset.labelFree;
		}
		function syncBilling() {
			const toggle = form.querySelector('[name="billing_enabled"]');
			if (!toggle) return;
			const box = document.getElementById(toggle.getAttribute('aria-controls'));
			if (box) box.hidden = !toggle.checked;
		}

		function renderSlots(list) {
			days.innerHTML = '';
			const groups = groupByDay(list, cfg.tz);
			if (!groups.length) {
				days.appendChild(el('p', { class: 'wpcap-sb-empty' }, resource().firstWeek ? (t.noSlotsWeek || '') : (t.noSlotsResource || '')));
				return 0;
			}
			let count = 0;
			groups.forEach(function (group) {
				const dayId = uid + '-d-' + group.day;
				const section = el('section', { class: 'wpcap-sb-day', 'aria-labelledby': dayId });
				section.appendChild(el('h' + Math.min(6, level + 2), { id: dayId }, formatDay(group.day, cfg.locale)));
				const ul = el('ul', { class: 'wpcap-sb-slot-list' });
				group.slots.forEach(function (slot) {
					count++;
					const id = uid + '-slot-' + slot.slot_id;
					const li = el('li', { class: 'wpcap-sb-slot' });
					const input = el('input', { type: 'radio', name: 'slot_id', id: id, value: String(slot.slot_id), 'data-available': String(slot.available), 'data-starts': slot.starts_at, 'data-ends': slot.ends_at });
					const label = el('label', { for: id });
					label.appendChild(el('span', { class: 'wpcap-sb-time' }, fill(t.timeRange || '%1$s–%2$s', [formatTime(slot.starts_at, cfg.locale, cfg.tz), formatTime(slot.ends_at, cfg.locale, cfg.tz)])));
					label.appendChild(document.createTextNode(' '));
					label.appendChild(el('span', { class: 'wpcap-sb-free' }, plural(Number(slot.available), t.freeOne, t.freeMany)));
					li.appendChild(input);
					li.appendChild(label);
					ul.appendChild(li);
				});
				section.appendChild(ul);
				days.appendChild(section);
			});
			return count;
		}

		async function loadWeek(announceResult) {
			const seq = ++state.seq;
			syncNav();
			if (!state.week) {
				weekText.textContent = '';
				renderSlots([]);
				syncQuantity();
				if (announceResult) announce(live, t.noSlotsResource || '');
				return;
			}
			const label = weekLabel(state.week, cfg.locale);
			weekText.textContent = label;
			days.setAttribute('aria-busy', 'true');
			days.innerHTML = '';
			days.appendChild(el('p', { class: 'wpcap-sb-loading' }, t.loading || '…'));
			let result;
			try {
				result = await request('GET', 'events/' + cfg.event + '/service-slots', null, { resource: state.resource, week: state.week });
			} catch (e) {
				result = { ok: false, data: {} };
			}
			if (seq !== state.seq) return; // A newer navigation won.
			days.removeAttribute('aria-busy');
			if (!result.ok || !Array.isArray(result.data)) {
				days.innerHTML = '';
				days.appendChild(el('p', { class: 'wpcap-sb-empty', role: 'alert' }, t.loadFailed || ''));
				return;
			}
			const count = renderSlots(result.data);
			syncQuantity();
			if (announceResult) announce(live, count ? fill(t.weekAnnounce || '%1$s: %2$d', [label, count]) : fill(t.weekAnnounceNone || '%s', [label]));
		}

		function showStep(which) {
			step1.hidden = which !== 1;
			step2.hidden = which !== 2;
		}
		function slotSummary(slot) {
			const r = resource();
			const n = Math.max(1, Number(qty.value) || 1);
			return fill(t.summary || '%1$s · %2$s · %3$s', [r.name || '', formatDateTime(slot.dataset.starts, cfg.locale, cfg.tz), plural(n, t.placesOne, t.placesMany)]);
		}

		// Resource choice.
		form.addEventListener('change', function (event) {
			const target = event.target;
			if (target.name === 'resource_id' && target.type === 'radio') {
				state.resource = Number(target.value);
				state.week = resource().firstWeek || '';
				syncPaid();
				loadWeek(true);
			} else if (target.name === 'slot_id') {
				syncQuantity();
			} else if (target.name === 'billing_enabled') {
				syncBilling();
			}
		});
		qty.addEventListener('input', syncQuantity);
		[prev, next].forEach(function (button) {
			button.addEventListener('click', function () {
				if (button.getAttribute('aria-disabled') === 'true' || !state.week) return;
				state.week = addDays(state.week, button === next ? 7 : -7);
				loadWeek(true);
			});
		});

		q('.wpcap-sb-continue').addEventListener('click', function () {
			clearErrors(step1);
			const slot = selectedSlot();
			const errors = [];
			if (!slot) {
				const first = form.querySelector('input[name="slot_id"]');
				errors.push({ input: first || null, message: t.selectSlot || 'Select a slot.' });
			}
			const max = Number(qty.max) || 1;
			const n = Number(qty.value);
			if (!Number.isInteger(n) || n < 1 || n > max) errors.push({ input: qty, message: fill(t.errQuantity || '%d', [max]) });
			if (errors.length) { showErrors(step1, step1.querySelector('.wpcap-sb-resources, .wpcap-sb-resource-info, input[type="hidden"]') || null, errors, level + 2); return; }
			summary.textContent = slotSummary(slot);
			showStep(2);
			const heading = step2.querySelector('[tabindex="-1"]');
			if (heading) heading.focus();
		});
		q('.wpcap-sb-back').addEventListener('click', function () {
			clearErrors(step2);
			message.textContent = '';
			showStep(1);
			const slot = selectedSlot();
			(slot || step1.querySelector('[tabindex="-1"]')).focus();
		});

		function validateData() {
			const errors = [];
			step2.querySelectorAll('input, select, textarea').forEach(function (input) {
				if (input.type === 'hidden' || input.type === 'radio' || input.disabled || input.closest('[hidden]')) return;
				const v = input.validity;
				if (!v || v.valid) return;
				let text;
				if (input.name === 'accept_terms') text = t.errTerms || 'Please accept the terms.';
				else if (input.type === 'email' && !v.valueMissing) text = t.errEmail || 'Invalid e-mail address.';
				else text = fill(t.errRequired || '%s', [labelText(input)]);
				errors.push({ input: input, message: text });
			});
			return errors;
		}
		function billingPayload() {
			const toggle = form.querySelector('[name="billing_enabled"]');
			if (!toggle || !toggle.checked || toggle.closest('[hidden]')) return null;
			const billing = { enabled: true };
			form.querySelectorAll('[name^="billing_"]').forEach(function (input) {
				if (input !== toggle) billing[input.name.slice(8)] = input.value.trim();
			});
			return billing;
		}
		function serverField(data) {
			const field = data && data.data && data.data.field;
			if (!field) return null;
			if (field === 'email') return form.querySelector('[name="email"]');
			if (field.indexOf('billing_') === 0) {
				const toggle = form.querySelector('[name="billing_enabled"]');
				if (toggle && !toggle.checked) { toggle.checked = true; syncBilling(); }
			}
			return form.querySelector('[name="' + String(field).replace(/[^a-z0-9_]/g, '') + '"]:not([type="hidden"])');
		}

		async function book(event) {
			event.preventDefault();
			clearErrors(step2);
			const errors = validateData();
			if (errors.length) { showErrors(step2, summary.nextSibling, errors, level + 2); return; }
			const slot = selectedSlot();
			if (!slot) { showStep(1); q('.wpcap-sb-continue').click(); return; }
			const email = form.querySelector('[name="email"]').value.trim();
			const quantity = Number(qty.value) || 1;
			const r = resource();
			const hadFocus = document.activeElement === submit;
			setBusy(submit, true);
			message.textContent = t.submitting || '…';
			let done = false;
			try {
				let pending = state.pending;
				if (pending && (pending.slot !== Number(slot.value) || pending.quantity !== quantity || pending.email !== email)) {
					// Another choice than the open hold: release it (best effort) before reserving anew.
					request('POST', 'service-bookings/' + pending.id + '/cancel', { token: pending.token }).catch(function () {});
					pending = state.pending = null;
				}
				if (!pending) {
					if (!state.key) state.key = uuid();
					const reserved = await request('POST', 'service-slots/' + slot.value + '/bookings', { email: email, quantity: quantity, idempotency_key: state.key });
					state.key = null; // The server answered: a later attempt is a new booking.
					if (!reserved.ok || !reserved.data.token) {
						message.textContent = '';
						const code = reserved.data && reserved.data.code;
						if (code === 'wpcap_slot_full' || code === 'wpcap_slot_unavailable' || code === 'wpcap_slot_resource_busy' || code === 'wpcap_slot_provider_busy') {
							showStep(1);
							await loadWeek(false);
							showErrors(step1, null, [{ input: null, message: t.slotGone || reserved.data.message }], level + 2);
							return;
						}
						showErrors(step2, summary.nextSibling, [{ input: code === 'wpcap_booking_invalid' ? form.querySelector('[name="email"]') : null, message: reserved.data.message || t.failed }], level + 2);
						return;
					}
					pending = state.pending = { id: Number(reserved.data.booking_id), token: String(reserved.data.token), slot: Number(slot.value), quantity: quantity, email: email };
				}
				const body = { token: pending.token, page_id: Number(cfg.pageId) || 0 };
				if (r.priced) {
					const provider = form.querySelector('[name="provider"]:checked') || form.querySelector('input[type="hidden"][name="provider"]');
					const terms = form.querySelector('[name="accept_terms"]');
					const version = form.querySelector('input[type="hidden"][name="terms_version"]');
					const locale = form.querySelector('input[type="hidden"][name="locale"]');
					body.provider = provider ? provider.value : 'manual';
					body.accept_terms = terms ? terms.checked : false;
					if (version) body.terms_version = version.value;
					if (locale) body.locale = locale.value;
					const billing = billingPayload();
					if (billing) body.billing = billing;
				}
				const confirmed = await request('POST', 'service-bookings/' + pending.id + '/confirm', body);
				message.textContent = '';
				if (!confirmed.ok) {
					const code = confirmed.data && confirmed.data.code;
					// The hold lapsed or the booking cannot be confirmed any more: start over next time.
					if (code === 'wpcap_booking_state' || code === 'wpcap_slot_unavailable' || code === 'wpcap_booking_access_denied') state.pending = null;
					showErrors(step2, summary.nextSibling, [{ input: serverField(confirmed.data), message: confirmed.data.message || t.failed }], level + 2);
					return;
				}
				done = true;
				const booked = state.pending;
				state.pending = null;
				showConfirmation(confirmed.data, booked, slot, email);
			} catch (e) {
				message.textContent = '';
				showErrors(step2, summary.nextSibling, [{ input: null, message: t.failed || 'Failed.' }], level + 2);
			} finally {
				setBusy(submit, false);
				if (!done && hadFocus && (!document.activeElement || document.activeElement === document.body)) submit.focus();
			}
		}
		form.addEventListener('submit', book);

		/* ------------------------------------------------------------ Confirmation */

		function manageBlock(panel, booking) {
			const url = manageUrl(window.location.href, booking.id, booking.token);
			const p = el('p', { class: 'wpcap-sb-manage' });
			p.appendChild(el('a', { href: url }, t.manageLink || 'Manage booking'));
			panel.appendChild(p);
			panel.appendChild(el('p', { class: 'wpcap-hint' }, t.manageHint || ''));
		}
		/** Payment continuation (hosted page link, transfer details or instructions); $dueNote: reservation note of a booking. */
		function renderPayment(panel, payment, dueNote) {
			const kind = paymentKind(payment);
			if (kind === 'redirect') {
				if (dueNote) panel.appendChild(el('p', {}, t.payRedirect || ''));
				const pay = el('a', { href: payment.redirect_url, class: 'wp-element-button wpcap-sb-pay' }, t.payButton || 'Pay');
				panel.appendChild(el('p')).appendChild(pay);
			} else if (kind === 'bank') {
				const bank = payment.bank_transfer;
				panel.appendChild(el('p', {}, t.payNow || ''));
				const list = el('dl', { class: 'wpcap-bank' });
				[['amount', bank.amount], ['recipient', bank.recipient], ['iban', bank.iban], ['bic', bank.bic], ['remittance', bank.remittance], ['dueDate', bank.due_date]].forEach(function (row) {
					if (!row[1]) return;
					list.appendChild(el('dt', {}, t[row[0]] || row[0]));
					list.appendChild(el('dd', { class: 'wpcap-bank-' + row[0] }, row[1]));
				});
				panel.appendChild(list);
				if (bank.instructions) panel.appendChild(el('p', { class: 'wpcap-instructions' }, bank.instructions));
				if (dueNote && bank.due_date) panel.appendChild(el('p', {}, fill(t.payUntil || '%s', [bank.due_date])));
			} else if (kind === 'instructions') {
				panel.appendChild(el('p', { class: 'wpcap-instructions' }, payment.instructions));
			}
		}
		function showConfirmation(data, booking, slot, email) {
			const order = data.order || null;
			const confirmed = data.status === 'confirmed';
			const panel = el('section', { class: 'wpcap-confirmation wpcap-sb-confirmation', tabindex: '-1' });
			const headingId = uid + '-done';
			panel.setAttribute('aria-labelledby', headingId);
			panel.appendChild(el('h' + Math.min(6, level + 1), { id: headingId }, confirmed ? (t.confirmedTitle || 'Booked') : (t.pendingTitle || 'Please pay')));
			panel.appendChild(el('p', { class: 'wpcap-sb-summary' }, slotSummary(slot)));
			if (order && order.reference) panel.appendChild(el('p', { class: 'wpcap-order-number' }, fill(t.orderNumber || '%s', [order.reference])));
			const payment = (order && order.payment) || {};
			if (!confirmed && order) {
				if (order.total_formatted && !payment.bank_transfer) panel.appendChild(el('p', {}, fill(t.orderTotal || '%s', [order.total_formatted])));
				renderPayment(panel, payment, true);
				panel.appendChild(el('p', {}, t.afterPayment || ''));
			}
			panel.appendChild(el('p', {}, fill(t.sentTo || '%s', [email])));
			manageBlock(panel, booking);
			const again = el('button', { type: 'button', class: 'wpcap-sb-again' }, t.bookAnother || 'Book another');
			again.addEventListener('click', function () {
				panel.remove();
				resetForm();
			});
			panel.appendChild(again);
			form.hidden = true;
			root.insertBefore(panel, form);
			panel.focus();
			document.dispatchEvent(new CustomEvent('wpcap:service-booking-complete', { detail: { root: root, booking_id: booking.id, status: data.status, order: order } }));
		}
		function resetForm() {
			const email = form.querySelector('[name="email"]').value;
			form.reset();
			form.querySelector('[name="email"]').value = email;
			clearErrors(form);
			message.textContent = '';
			form.hidden = false;
			showStep(1);
			const checked = form.querySelector('input[name="resource_id"]:checked');
			if (checked) state.resource = Number(checked.value);
			state.week = resource().firstWeek || '';
			syncPaid();
			syncBilling();
			loadWeek(false).then(function () {
				const heading = step1.querySelector('[tabindex="-1"]');
				if (heading) heading.focus();
			});
		}

		/* ------------------------------------------------------------ Manage view (#wpcap-booking=…) */

		const statusLabels = { held: t.statusHeld, pending_payment: t.statusPending, confirmed: t.statusConfirmed, cancelled: t.statusCancelled, expired: t.statusExpired, no_show: t.statusNoShow };
		let managePanel = null;
		async function showManage(ref, notice, supplementPayment) {
			if (managePanel) managePanel.remove();
			form.hidden = true;
			const panel = managePanel = el('section', { class: 'wpcap-sb-manage-view', tabindex: '-1', 'aria-busy': 'true' });
			const headingId = uid + '-manage';
			panel.setAttribute('aria-labelledby', headingId);
			panel.appendChild(el('h' + Math.min(6, level + 1), { id: headingId }, t.manageTitle || 'Your booking'));
			const status = el('p', { class: 'wpcap-message', role: 'status', 'aria-live': 'polite' }, t.manageLoading || '…');
			panel.appendChild(status);
			root.insertBefore(panel, form);
			let result;
			try {
				result = await request('POST', 'service-bookings/' + ref.id, { token: ref.token }, null, 'GET');
			} catch (e) {
				result = { ok: false, data: {} };
			}
			if (managePanel !== panel) return;
			panel.removeAttribute('aria-busy');
			if (!result.ok) {
				status.textContent = '';
				panel.appendChild(el('p', { role: 'alert' }, result.status === 403 ? (t.manageDenied || '') : ((result.data && result.data.message) || t.failed || '')));
				addBookAgain(panel);
				panel.focus();
				return;
			}
			status.textContent = notice || '';
			const b = result.data;
			const dl = el('dl', { class: 'wpcap-sb-details' });
			[[t.when, (b.resource_name || '') + ' · ' + formatDateTime(b.starts_at, cfg.locale, cfg.tz)], [t.places, String(b.quantity)], [t.status, statusLabels[b.status] || b.status]].forEach(function (row) {
				dl.appendChild(el('dt', {}, row[0] || ''));
				dl.appendChild(el('dd', {}, row[1]));
			});
			panel.appendChild(dl);
			const rules = b.rules || {};
			const active = ['held', 'pending_payment', 'confirmed'].indexOf(b.status) !== -1;
			const openSupplement = b.open_supplement || null;
			if (openSupplement) {
				// Dearer rebooking waiting for its supplementary payment: the booking stays until it is paid.
				const box = el('div', { class: 'wpcap-sb-supplement' });
				box.appendChild(el('p', {}, fill(t.supplementOpen || '%1$s · %2$s · %3$s', [money(openSupplement.amount_minor, openSupplement.currency), (openSupplement.resource_name || '') + ' · ' + formatDateTime(openSupplement.starts_at, cfg.locale, cfg.tz), formatDateTime(openSupplement.expires_at, cfg.locale, cfg.tz)])));
				if (supplementPayment && paymentKind(supplementPayment)) {
					renderPayment(box, supplementPayment, false);
				} else {
					const payOpen = el('button', { type: 'button', class: 'wpcap-sb-supplement-pay' }, t.supplementPay || 'Pay');
					payOpen.addEventListener('click', async function () {
						setBusy(payOpen, true);
						status.textContent = t.working || '…';
						// Same target again: the open supplement is reused and its payment restarted.
						const r = await request('POST', 'service-bookings/' + ref.id + '/rebook', { token: ref.token, slot_id: Number(openSupplement.slot_id), page_id: Number(cfg.pageId) || 0 }).catch(function () { return { ok: false, data: {} }; });
						setBusy(payOpen, false);
						if (r.ok && r.data.supplement && !r.data.payment_error) { showManage(ref, t.supplementStarted || '', r.data.supplement.payment || null).then(function () { managePanel.focus(); }); return; }
						status.textContent = '';
						showErrors(panel, dl, [{ input: null, message: (r.data && (r.data.payment_error || r.data.message)) || t.failed }], level + 2);
					});
					box.appendChild(el('p')).appendChild(payOpen);
				}
				panel.appendChild(box);
			} else if (active && b.status !== 'pending_payment' && !deadlinePassed(b.starts_at, rules.rebook_hours)) {
				const options = rebookChoices(b);
				if (options.length) {
					const rebook = el('form', { method: 'post', class: 'wpcap-sb-rebook', action: '#' + root.id });
					const selectId = uid + '-rebook';
					const field = el('div', { class: 'wpcap-field' });
					field.appendChild(el('label', { for: selectId }, t.rebookLabel || ''));
					const select = el('select', { id: selectId, name: 'slot_id' });
					options.forEach(function (o) { select.appendChild(el('option', { value: String(o.slot_id), 'data-supplement': String(o.supplement_minor) }, choiceLabel(o, cfg.locale, cfg.tz))); });
					field.appendChild(select);
					rebook.appendChild(field);
					const dearer = options.some(function (o) { return o.supplement_minor > 0; });
					const providers = b.supplement_providers || [];
					let providerSelect = null;
					let providerField = null;
					if (dearer) {
						rebook.appendChild(el('p', { class: 'wpcap-hint' }, t.supplementHint || ''));
						if (providers.length > 1) {
							providerField = el('div', { class: 'wpcap-field wpcap-sb-supplement-provider' });
							const providerId = uid + '-provider';
							providerField.appendChild(el('label', { for: providerId }, t.supplementProvider || ''));
							providerSelect = el('select', { id: providerId, name: 'provider' });
							providers.forEach(function (pr) { providerSelect.appendChild(el('option', { value: String(pr.id) }, pr.title || pr.id)); });
							providerField.appendChild(providerSelect);
							rebook.appendChild(providerField);
						}
					}
					const selectedSupplement = function () { const o = select.options[select.selectedIndex]; return o ? Number(o.getAttribute('data-supplement')) || 0 : 0; };
					const syncProvider = function () { if (providerField) providerField.hidden = !selectedSupplement(); };
					select.addEventListener('change', syncProvider);
					syncProvider();
					const button = el('button', { type: 'submit' }, t.rebookButton || 'Rebook');
					rebook.appendChild(button);
					rebook.addEventListener('submit', async function (event) {
						event.preventDefault();
						setBusy(button, true);
						status.textContent = t.working || '…';
						const body = { token: ref.token, slot_id: Number(select.value), page_id: Number(cfg.pageId) || 0 };
						if (providerSelect && selectedSupplement()) body.provider = providerSelect.value;
						const r = await request('POST', 'service-bookings/' + ref.id + '/rebook', body).catch(function () { return { ok: false, data: {} }; });
						setBusy(button, false);
						if (r.ok && r.data.supplement) {
							// Dearer target: supplementary payment started, the booking moves once it is paid.
							showManage(ref, r.data.payment_error ? r.data.payment_error : (t.supplementStarted || ''), r.data.payment_error ? null : (r.data.supplement.payment || null)).then(function () { managePanel.focus(); });
							return;
						}
						if (r.ok) { showManage(ref, t.rebooked || '').then(function () { managePanel.focus(); }); return; }
						status.textContent = '';
						showErrors(panel, dl, [{ input: select, message: r.data.message || t.failed }], level + 2);
					});
					panel.appendChild(rebook);
				} else {
					panel.appendChild(el('p', {}, t.rebookNone || ''));
				}
				if (rules.rebook_hours) panel.appendChild(el('p', { class: 'wpcap-hint' }, fill(t.rebookUntil || '%s', [durationLabel(rules.rebook_hours)])));
			}
			if (active && (b.status !== 'confirmed' || !deadlinePassed(b.starts_at, rules.cancel_hours))) {
				const cancelBox = el('div', { class: 'wpcap-sb-cancel' });
				const cancel = el('button', { type: 'button', class: 'wpcap-sb-cancel-start', 'aria-expanded': 'false', 'aria-controls': uid + '-cancel-confirm' }, t.cancelButton || 'Cancel');
				const confirmBox = el('div', { id: uid + '-cancel-confirm', class: 'wpcap-sb-cancel-confirm', hidden: '' });
				const question = el('p', {}, t.cancelQuestion || '');
				const yes = el('button', { type: 'button', class: 'wpcap-sb-cancel-yes' }, t.cancelConfirm || 'Yes');
				const no = el('button', { type: 'button', class: 'wpcap-sb-cancel-no' }, t.cancelKeep || 'No');
				confirmBox.appendChild(question);
				confirmBox.appendChild(yes);
				confirmBox.appendChild(document.createTextNode(' '));
				confirmBox.appendChild(no);
				cancel.addEventListener('click', function () { confirmBox.hidden = false; cancel.setAttribute('aria-expanded', 'true'); no.focus(); });
				no.addEventListener('click', function () { confirmBox.hidden = true; cancel.setAttribute('aria-expanded', 'false'); cancel.focus(); });
				yes.addEventListener('click', async function () {
					setBusy(yes, true);
					status.textContent = t.working || '…';
					const r = await request('POST', 'service-bookings/' + ref.id + '/cancel', { token: ref.token }).catch(function () { return { ok: false, data: {} }; });
					setBusy(yes, false);
					if (r.ok) { showManage(ref, t.cancelled || '').then(function () { managePanel.focus(); }); return; }
					status.textContent = '';
					showErrors(panel, dl, [{ input: null, message: r.data.message || t.failed }], level + 2);
				});
				cancelBox.appendChild(cancel);
				cancelBox.appendChild(confirmBox);
				if (b.status === 'confirmed' && rules.cancel_hours) cancelBox.appendChild(el('p', { class: 'wpcap-hint' }, fill(t.cancelUntil || '%s', [durationLabel(rules.cancel_hours)])));
				panel.appendChild(cancelBox);
			}
			addBookAgain(panel);
		}
		function addBookAgain(panel) {
			const again = el('button', { type: 'button', class: 'wpcap-sb-again' }, t.bookAnother || 'Book another');
			again.addEventListener('click', function () {
				if (window.history && history.replaceState) history.replaceState(null, '', window.location.href.split('#')[0]);
				panel.remove();
				managePanel = null;
				resetForm();
			});
			panel.appendChild(again);
		}
		function route() {
			const ref = parseManageHash(window.location.hash);
			if (!ref) return false;
			showManage(ref, '').then(function () { if (managePanel) managePanel.focus(); });
			return true;
		}

		syncPaid();
		syncBilling();
		syncNav();
		syncQuantity();
		// One manage view per page: the first booking widget handles the link.
		if (root === document.querySelector('[data-wpcap-sb]')) {
			route();
			window.addEventListener('hashchange', route);
		}
	}

	document.querySelectorAll('[data-wpcap-sb]').forEach(init);
})();
