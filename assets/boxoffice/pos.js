/* Pass Captain box office: running total, change, participant fields, live availability, card-terminal status and offline guard (no dependencies). */
(function () {
	'use strict';
	// Test hook for tests/js (node:test); `module` does not exist in the browser, so this is a no-op there.
	if (typeof module === 'object' && module && module.exports) { module.exports = { money: money, parseMoney: parseMoney }; }
	var deviceBox = document.getElementById('wpcap-pos-device');
	if (deviceBox && window.wpcapPosDevice && window.fetch) { initDevice(window.wpcapPosDevice, deviceBox); }
	initOffline(window.wpcapPosOffline || {});
	var form = document.getElementById('wpcap-pos-form');
	if (!form) { return; }
	var totalEl = document.getElementById('wpcap-pos-total');
	var changeEl = document.getElementById('wpcap-pos-change');
	var submitted = false;

	/*
	 * A527 / decision E-13: without network the box office sells nothing. Forms that book money
	 * (BoxOffice\Page::ONLINE_ONLY: sale, "Nachbuchen", storno, refund payout) get their submit buttons
	 * disabled while navigator.onLine is false, a banner (role=alert) explains why, and a submit that still
	 * happens is stopped. Nothing is queued or stored: when the browser is back online the buttons are
	 * enabled again and the cashier submits as usual. Admissions offline: scanner app only.
	 */
	function initOffline(cfg) {
		var forms = document.querySelectorAll('form[data-wpcap-pos-online]');
		if (!forms.length) { return; }
		var banner = null;
		function buttons(f) {
			return Array.prototype.filter.call(f.querySelectorAll('button, input'), function (el) {
				var type = (el.getAttribute('type') || (el.tagName.toLowerCase() === 'button' ? 'submit' : 'text')).toLowerCase();
				return type === 'submit';
			});
		}
		function ensureBanner() {
			if (banner) { return banner; }
			banner = document.createElement('div');
			banner.id = 'wpcap-pos-offline';
			banner.className = 'notice inline wpcap-pos-offline';
			banner.setAttribute('tabindex', '-1');
			banner.hidden = true;
			banner.appendChild(document.createElement('p'));
			var wrap = document.querySelector('.wpcap-pos') || forms[0].parentNode;
			var nav = wrap.querySelector('.nav-tab-wrapper');
			wrap.insertBefore(banner, nav ? nav.nextElementSibling : wrap.firstChild);
			return banner;
		}
		function show(text, offline) {
			var b = ensureBanner();
			b.className = 'notice inline wpcap-pos-offline ' + (offline ? 'notice-error' : 'notice-success');
			b.setAttribute('role', offline ? 'alert' : 'status');
			b.firstChild.textContent = text || '';
			b.hidden = !text;
		}
		function apply(online, announce) {
			forms.forEach(function (f) {
				if (online) { f.removeAttribute('data-wpcap-offline'); } else { f.setAttribute('data-wpcap-offline', ''); }
				buttons(f).forEach(function (btn) {
					if (!online && !btn.disabled) {
						btn.disabled = true;
						btn.setAttribute('data-wpcap-offline-disabled', '');
						btn.setAttribute('aria-describedby', 'wpcap-pos-offline');
						if (cfg.blocked) { btn.setAttribute('title', cfg.blocked); }
					} else if (online && btn.hasAttribute('data-wpcap-offline-disabled')) {
						// Only buttons disabled here; others stay as the page rendered them.
						btn.disabled = false;
						btn.removeAttribute('data-wpcap-offline-disabled');
						btn.removeAttribute('aria-describedby');
						btn.removeAttribute('title');
					}
				});
			});
			if (!online) { show(cfg.offline || 'Offline', true); }
			else if (announce) { show(cfg.online || '', false); }
			else if (banner) { banner.hidden = true; }
		}
		forms.forEach(function (f) {
			f.addEventListener('submit', function (event) {
				if (navigator.onLine !== false) { return; }
				// E.g. Enter in a field before the offline event arrived: stop it, never queue it.
				event.preventDefault();
				apply(false, false);
				if (banner) { banner.focus(); }
			});
		});
		window.addEventListener('offline', function () { apply(false, false); });
		window.addEventListener('online', function () { apply(true, true); });
		if (navigator.onLine === false) { apply(false, false); }
	}

	/*
	 * Card terminal payment (BoxOffice\Page::render_device_status): polls admin-ajax
	 * wpcap_pos_device_status until the payment succeeded (→ booked sale), failed, was cancelled or timed
	 * out; the cancel button calls wpcap_pos_device_cancel (without JavaScript it posts the admin-post form).
	 * The status paragraph is a polite live region and gets the focus when the state changes.
	 */
	function initDevice(cfg, box) {
		var statusEl = document.getElementById('wpcap-pos-device-status');
		var hint = document.getElementById('wpcap-pos-device-hint');
		var cancelForm = document.getElementById('wpcap-pos-device-cancel-form');
		var cancelBtn = document.getElementById('wpcap-pos-device-cancel');
		var timer = null;
		var done = false;
		var busy = false;
		if (!statusEl) { return; }
		function say(text) {
			if (text && statusEl.textContent !== text) { statusEl.textContent = text; }
		}
		function request(action) {
			var body = 'action=' + encodeURIComponent(action) + '&sale_id=' + encodeURIComponent(cfg.saleId) + '&_ajax_nonce=' + encodeURIComponent(cfg.nonce);
			return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
				.then(function (r) { return r.json().catch(function () { return null; }); });
		}
		function schedule() {
			if (!done) { timer = setTimeout(poll, cfg.interval || 2000); }
		}
		function finish(state) {
			done = true;
			if (timer) { clearTimeout(timer); }
			if (cancelForm) { cancelForm.hidden = true; }
			if (hint) { hint.textContent = state.status === 'unbooked' ? '' : (cfg.again || ''); }
			box.setAttribute('data-status', state.status);
			box.classList.remove('notice-info');
			box.classList.add(state.status === 'unbooked' ? 'notice-warning' : 'notice-error');
			say(state.message || state.status);
			statusEl.focus();
		}
		function handle(json) {
			if (!json) { say(cfg.offline); schedule(); return; }
			if (!json.success) {
				// E.g. the terminal refused the cancel (card already presented): show why, keep polling.
				say((json.data && json.data.message) || cfg.offline);
				schedule();
				return;
			}
			var state = json.data || {};
			if (state.status === 'pending') { schedule(); return; }
			if (state.status === 'succeeded') {
				done = true;
				if (cancelForm) { cancelForm.hidden = true; }
				say(cfg.booked);
				if (state.redirect) { window.location.href = state.redirect; }
				return;
			}
			finish(state);
		}
		function run(action) {
			busy = true;
			request(action).then(function (json) { busy = false; handle(json); }, function () { busy = false; say(cfg.offline); schedule(); });
		}
		function poll() {
			timer = null;
			if (!done && !busy) { run('wpcap_pos_device_status'); }
		}
		if (cancelForm) {
			cancelForm.addEventListener('submit', function (event) {
				event.preventDefault();
				if (done || busy) { return; }
				if (timer) { clearTimeout(timer); timer = null; }
				if (cancelBtn) { cancelBtn.disabled = true; }
				say(cfg.cancelling);
				busy = true;
				request('wpcap_pos_device_cancel').then(function (json) {
					busy = false;
					if (cancelBtn) { cancelBtn.disabled = false; }
					handle(json);
				}, function () {
					busy = false;
					if (cancelBtn) { cancelBtn.disabled = false; }
					say(cfg.offline);
					schedule();
				});
			});
		}
		statusEl.focus();
		schedule();
	}

	// A403: the currency's minor unit (0 JPY, 2 EUR, 3 KWD) and the site's number format, like Money::number().
	// Read lazily: the helpers are exported (tests) before the rest of the script runs.
	function fmt() {
		var f = window.wpcapPos || {};
		return { digits: typeof f.decimals === 'number' ? f.decimals : 2, point: f.decimalPoint || ',', sep: typeof f.thousandsSep === 'string' ? f.thousandsSep : '.' };
	}
	function money(minor) {
		var F = fmt(), DIGITS = F.digits, POINT = F.point, SEP = F.sep;
		var sign = minor < 0 ? '-' : '';
		minor = Math.abs(minor);
		var unit = Math.pow(10, DIGITS);
		var whole = String(Math.floor(minor / unit)).replace(/\B(?=(\d{3})+(?!\d))/g, SEP);
		return sign + whole + (DIGITS ? POINT + String(minor % unit).padStart(DIGITS, '0') : '');
	}
	function parseMoney(text) {
		var DIGITS = fmt().digits;
		text = String(text || '').replace(/[^0-9,.]/g, '');
		if (!text) { return null; }
		var parts = text.replace(/\./g, ',').split(',');
		// The last separator is the decimal point (extra digits are cut); a currency without minor unit has none.
		var decimals = parts.length > 1 && DIGITS > 0 ? parts.pop() : '';
		var whole = parts.join('') || '0';
		var pad = new Array(DIGITS + 1).join('0');
		return parseInt(whole, 10) * Math.pow(10, DIGITS) + (DIGITS ? parseInt((decimals + pad).slice(0, DIGITS), 10) : 0);
	}
	function method() {
		var checked = form.querySelector('input[name="method"]:checked');
		return checked ? checked.value : '';
	}
	function update() {
		var total = 0;
		form.querySelectorAll('tr[data-type]').forEach(function (row) {
			var qty = parseInt(row.querySelector('.wpcap-pos-qty').value, 10) || 0;
			total += qty * parseInt(row.getAttribute('data-price'), 10);
		});
		if (method() === 'free') { total = 0; }
		totalEl.textContent = money(total);
		var given = parseMoney(form.elements.tendered ? form.elements.tendered.value : '');
		changeEl.textContent = (method() === 'cash' && given !== null) ? ((window.wpcapPos && window.wpcapPos.changeLabel) || 'Rückgeld:') + ' ' + money(given - total) : '';
	}
	// Participant fields per ticket (same definitions as the public checkout); posted as
	// attendees[type][index][name|email|answers][key]. Entered values survive quantity changes.
	var attendeesBox = document.getElementById('wpcap-pos-attendees');
	var cfg = window.wpcapPos || {};
	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text) { node.textContent = text; }
		return node;
	}
	function labelled(fs, id, text, input) {
		var p = el('p');
		var label = el('label', { for: id }, text);
		if (input.type === 'checkbox') { p.appendChild(input); p.appendChild(document.createTextNode(' ')); p.appendChild(label); }
		else { p.appendChild(label); p.appendChild(el('br')); p.appendChild(input); }
		fs.appendChild(p);
	}
	function renderAttendees() {
		if (!attendeesBox) { return; }
		var previous = {};
		attendeesBox.querySelectorAll('[data-slot]').forEach(function (fs) { previous[fs.getAttribute('data-slot')] = fs; });
		attendeesBox.innerHTML = '';
		var count = 0;
		form.querySelectorAll('tr[data-type]').forEach(function (row) {
			var type = row.getAttribute('data-type');
			var n = Math.max(0, parseInt(row.querySelector('.wpcap-pos-qty').value, 10) || 0);
			var fields = [];
			try { fields = JSON.parse(row.getAttribute('data-fields') || '[]'); } catch (e) { fields = []; }
			for (var i = 0; i < n; i++) {
				count++;
				var slot = type + '-' + i;
				var legendText = (cfg.attendee || 'Ticket %1$d · %2$s').replace('%1$d', count).replace('%2$s', row.getAttribute('data-name') || '');
				if (previous[slot]) {
					previous[slot].querySelector('legend').textContent = legendText;
					attendeesBox.appendChild(previous[slot]);
					continue;
				}
				var base = 'attendees[' + type + '][' + i + ']';
				var idBase = 'wpcap-pos-att-' + slot;
				var fs = el('fieldset', { 'data-slot': slot, 'class': 'wpcap-pos-attendee', style: 'border:1px solid #c3c4c7;padding:.5em 1em;margin:.5em 0' });
				fs.appendChild(el('legend', {}, legendText));
				labelled(fs, idBase + '-name', cfg.name || 'Name', el('input', { id: idBase + '-name', type: 'text', name: base + '[name]', maxlength: '190', size: '30' }));
				labelled(fs, idBase + '-email', cfg.email || 'E-Mail', el('input', { id: idBase + '-email', type: 'email', name: base + '[email]', size: '30' }));
				fields.forEach(function (field, index) {
					var id = idBase + '-f' + index;
					var name = base + '[answers][' + field.key + ']';
					var input;
					if (field.type === 'textarea') { input = el('textarea', { id: id, name: name, rows: '2', cols: '40' }); }
					else if (field.type === 'checkbox' || field.type === 'consent') { input = el('input', { id: id, name: name, type: 'checkbox', value: '1' }); }
					else if (field.type === 'select' || field.type === 'multiselect') {
						// A680: choices; a multi-select sends the list of chosen labels.
						input = el('select', { id: id, name: name + (field.type === 'multiselect' ? '[]' : '') });
						if (field.type === 'multiselect') { input.multiple = true; } else { input.appendChild(el('option', { value: '' }, '–')); }
						(field.options || []).forEach(function (option) { input.appendChild(el('option', { value: option }, option)); });
					}
					else { input = el('input', { id: id, name: name, type: ({ email: 'email', tel: 'tel', date: 'date' })[field.type] || 'text', size: '30' }); }
					if (field.required) { input.required = true; }
					if (field.type === 'consent' && field.consent_text) {
						// Consent: the attendee agrees to this exact text (logged with its version, like the online checkout).
						var textId = id + '-text';
						input.setAttribute('aria-describedby', textId);
						fs.appendChild(el('p', { id: textId, 'class': 'wpcap-pos-consent-text' }, field.consent_text + (field.consent_version ? ' (' + (cfg.consentVersion || 'Version') + ' ' + field.consent_version + ')' : '')));
					}
					labelled(fs, id, field.label + (field.required ? ' *' : ''), input);
					if (field.purpose) { fs.appendChild(el('p', { 'class': 'description' }, field.purpose)); }
				});
				attendeesBox.appendChild(fs);
			}
		});
		if (count) { attendeesBox.insertBefore(el('h3', {}, cfg.heading || 'Teilnehmer'), attendeesBox.firstChild); }
	}
	form.addEventListener('input', function (event) {
		if (event.target.classList && event.target.classList.contains('wpcap-pos-qty')) { renderAttendees(); }
		update();
	});
	form.addEventListener('change', update);
	form.addEventListener('submit', function (event) {
		// Stopped by the offline guard (initOffline): not sent, so a later submit must still work.
		if (event.defaultPrevented) { return; }
		// The form key makes a double submit harmless; this only avoids a second request.
		if (submitted) { event.preventDefault(); return; }
		submitted = true;
	});
	update();
	renderAttendees();

	function refresh() {
		if (!window.wpcapPos || !window.fetch) { return; }
		fetch(window.wpcapPos.availabilityUrl, { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (json) {
				if (!json || !json.success) { return; }
				Object.keys(json.data).forEach(function (id) {
					var row = form.querySelector('tr[data-type="' + id + '"]');
					if (!row) { return; }
					var avail = json.data[id];
					row.querySelector('.wpcap-pos-avail').textContent = avail === null ? '∞' : String(avail);
					if (avail !== null) { row.querySelector('.wpcap-pos-qty').max = String(avail); }
				});
			})
			.catch(function () {});
	}
	setInterval(refresh, 15000);
})();
