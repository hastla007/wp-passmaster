/* SMS opt-in for the Pass Captain checkout: adds sms_phone/sms_consent to the payload only with consent. */
( function () {
	'use strict';
	var config = window.WPCAP_SMS || {};
	document.addEventListener( 'wpcap:checkout-payload', function ( event ) {
		var detail = event.detail || {};
		var form = detail.form;
		if ( ! form || ! detail.payload ) { return; }
		var consent = form.querySelector( '[name="sms_consent"]' );
		var phone = form.querySelector( '[name="sms_phone"]' );
		if ( ! consent || ! consent.checked ) { return; } // No consent: the number is never sent.
		var value = phone ? phone.value.trim() : '';
		if ( '' === value ) {
			detail.error = config.phoneRequired || 'Phone number required.';
			if ( phone ) { phone.focus(); }
			return;
		}
		detail.payload.sms_consent = true;
		detail.payload.sms_phone = value;
	} );
} )();
