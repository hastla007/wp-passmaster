/* Pass Captain box office: sums denomination counts into the amount field (no dependencies). */
(function () {
	'use strict';
	function money(minor) {
		return Math.floor(minor / 100) + ',' + String(minor % 100).padStart(2, '0');
	}
	document.querySelectorAll('table.wpcap-denoms-table').forEach(function (table) {
		var total = document.getElementById(table.getAttribute('data-total'));
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
				total.value = money(sum);
				total.readOnly = true;
			} else {
				total.readOnly = false;
			}
		}
		table.addEventListener('input', update);
		update();
	});
})();
