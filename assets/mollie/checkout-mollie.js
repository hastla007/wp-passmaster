/* Mollie payment method choice for the WP Pass Captain checkout. */
( function () {
	'use strict';
	function sync( form ) {
		var box = form.querySelector( '.wpcap-mollie-methods' );
		if ( ! box ) { return; }
		var provider = form.querySelector( '[name="provider"]:checked' ) || form.querySelector( 'input[type="hidden"][name="provider"]' );
		box.hidden = ! provider || 'mollie' !== provider.value;
	}
	document.addEventListener( 'change', function ( event ) {
		if ( event.target && 'provider' === event.target.name && event.target.form ) { sync( event.target.form ); }
	} );
	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.wpcap-checkout-form' ), sync );
	} );
	document.addEventListener( 'wpcap:checkout-payload', function ( event ) {
		var detail = event.detail || {};
		if ( ! detail.form || ! detail.payload ) { return; }
		var box = detail.form.querySelector( '.wpcap-mollie-methods' );
		var choice = detail.form.querySelector( '[name="mollie_method"]:checked' );
		if ( box && ! box.hidden && choice && choice.value ) { detail.payload.mollie_method = choice.value; }
	} );
} )();
