/*
 * Print buttons of the plugin's standalone pages (ticket, invoice, receipt, lists) without inline
 * JavaScript, so these pages can be served with a strict Content-Security-Policy (script-src 'self').
 *   <button data-wpcap-print>      prints on click
 *   <body data-wpcap-autoprint>    opens the print dialog once the page has loaded (receipts)
 */
(function () {
	'use strict';
	document.addEventListener('click', function (event) {
		var target = event.target && event.target.closest ? event.target.closest('[data-wpcap-print]') : null;
		if (!target) { return; }
		event.preventDefault();
		window.print();
	});
	window.addEventListener('load', function () {
		if (document.body && document.body.hasAttribute('data-wpcap-autoprint')) { window.print(); }
	});
})();
