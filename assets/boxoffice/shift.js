/* Pass Captain box office: sums denomination counts into the amount field (no dependencies). */
(function () {
	'use strict';
	// A403: minor unit and decimal point of the currency/site (data attributes from ShiftPage).
	function money(minor, digits, point) {
		var unit = Math.pow(10, digits);
		return Math.floor(minor / unit) + (digits ? point + String(minor % unit).padStart(digits, '0') : '');
	}
	document.querySelectorAll('table.wpcap-denoms-table').forEach(function (table) {
		var total = document.getElementById(table.getAttribute('data-total'));
		var digits = parseInt(table.getAttribute('data-decimals') || '2', 10);
		var point = table.getAttribute('data-decimal-point') || ',';
		if (!total) { return; }
		function update() {
			var sum = 0;
			var used = false;
			table.querySelectorAll('input[data-value]').forEach(function (input) {
				var qty = parseInt(input.value, 10);
				if (!isNaN(qty) && qty > 0) {
					used = true;
					sum += qty * parseInt(input.getAttribute('data-value'), 10);
				}
			});
			if (used) {
				total.value = money(sum, isNaN(digits) ? 2 : digits, point);
				total.readOnly = true;
			} else {
				total.readOnly = false;
			}
		}
		table.addEventListener('input', update);
		update();
	});
})();
