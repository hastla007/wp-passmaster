/* WP Pass Captain box office: running total, change and live availability (no dependencies). */
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
	form.addEventListener('input', update);
	form.addEventListener('change', update);
	form.addEventListener('submit', function (event) {
		// The form key makes a double submit harmless; this only avoids a second request.
		if (submitted) { event.preventDefault(); return; }
		submitted = true;
	});
	update();

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
