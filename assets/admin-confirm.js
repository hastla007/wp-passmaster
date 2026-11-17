/*
 * Pass Captain admin behaviours without inline event handlers (strict CSP, audit FE-8):
 *   data-wpcap-confirm="Text"  on a button/link (click) or a form (submit): native window.confirm(), cancel stops the action.
 *   data-wpcap-autosubmit       on a select: submits its form on change.
 *   data-wpcap-select-on-focus  on an input: selects its content on focus (one-time secrets).
 * Delegated on document, so markup added later works too.
 */
(function () {
	'use strict';
	function ask(el) {
		var text = el.getAttribute('data-wpcap-confirm');
		return !text || window.confirm(text);
	}
	document.addEventListener('click', function (event) {
		var target = event.target && event.target.closest ? event.target.closest('button[data-wpcap-confirm],input[data-wpcap-confirm],a[data-wpcap-confirm]') : null;
		if (target && !ask(target)) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true);
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (form && form.matches && form.matches('form[data-wpcap-confirm]') && !ask(form)) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true);
	document.addEventListener('change', function (event) {
		var target = event.target;
		if (target && target.matches && target.matches('select[data-wpcap-autosubmit]') && target.form) {
			target.form.submit();
		}
	});
	document.addEventListener('focusin', function (event) {
		var target = event.target;
		if (target && target.matches && target.matches('[data-wpcap-select-on-focus]') && typeof target.select === 'function') {
			target.select();
		}
	});
	// Test hook for tests/js (node:test); `module` does not exist in the browser.
	if (typeof module === 'object' && module && module.exports) { module.exports = { ask: ask }; }
})();
