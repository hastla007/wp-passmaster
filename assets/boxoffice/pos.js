/* WP Pass Captain box office: running total, change, participant fields and live availability (no dependencies). */
(function () {
	'use strict';
	var form = document.getElementById('wpcap-pos-form');
	if (!form) { return; }
	var totalEl = document.getElementById('wpcap-pos-total');
	var changeEl = document.getElementById('wpcap-pos-change');
	var submitted = false;

	function money(minor) {
		var sign = minor < 0 ? '-' : '';
		minor = Math.abs(minor);
		return sign + Math.floor(minor / 100) + ',' + String(minor % 100).padStart(2, '0');
	}
	function parseMoney(text) {
		text = String(text || '').replace(/[^0-9,.]/g, '');
		if (!text) { return null; }
		var parts = text.replace(/\./g, ',').split(',');
		var decimals = parts.length > 1 ? parts.pop() : '';
		var whole = parts.join('') || '0';
		return parseInt(whole, 10) * 100 + parseInt((decimals + '00').slice(0, 2), 10);
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
					else if (field.type === 'checkbox') { input = el('input', { id: id, name: name, type: 'checkbox', value: '1' }); }
					else { input = el('input', { id: id, name: name, type: field.type === 'email' ? 'email' : 'text', size: '30' }); }
					if (field.required) { input.required = true; }
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
