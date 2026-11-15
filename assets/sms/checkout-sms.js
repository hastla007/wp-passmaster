/*
 * SMS opt-in for the Pass Captain checkout: adds sms_phone/sms_consent to the payload only with consent.
 * With number verification on (WPCAP_SMS.verify, audit PRIV-16) the buyer requests a 6-digit code,
 * enters it, and the verification token travels with the order as sms_verification; without a
 * confirmed number the order is not submitted with the opt-in.
 */
( function () {
	'use strict';
	var config = window.WPCAP_SMS || {};
	var S = config.strings || {};

	async function post( url, body ) {
		var response = await fetch( url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
		var data = {};
		try { data = await response.json(); } catch ( e ) { data = {}; }
		return { ok: response.ok, data: data };
	}

	function setup( fieldset ) {
		var consent = fieldset.querySelector( '[name="sms_consent"]' );
		var phone = fieldset.querySelector( '[name="sms_phone"]' );
		var box = fieldset.querySelector( '.wpcap-sms-verify' );
		if ( ! config.verify || ! box || ! consent || ! phone ) { return; }
		var tokenInput = box.querySelector( '[name="sms_verification"]' );
		var codeInput = box.querySelector( '[name="sms_code"]' );
		var sendButton = box.querySelector( '.wpcap-sms-send' );
		var confirmButton = box.querySelector( '.wpcap-sms-confirm' );
		var status = box.querySelector( '.wpcap-sms-status' );
		var state = { token: '', phone: '' };

		function say( text ) { if ( status ) { status.textContent = text || ''; } }
		function reset() {
			state.token = '';
			state.phone = '';
			if ( tokenInput ) { tokenInput.value = ''; }
			if ( codeInput ) { codeInput.value = ''; }
			say( '' );
		}
		function sync() { box.hidden = ! consent.checked; }
		consent.addEventListener( 'change', sync );
		sync();
		// A different number needs its own code; a verified token is only valid for the number it was sent to.
		phone.addEventListener( 'input', function () {
			if ( state.phone && phone.value.trim() !== state.phone ) { reset(); }
		} );
		var form = fieldset.closest ? fieldset.closest( 'form' ) : null;
		if ( form ) { form.addEventListener( 'reset', reset ); }

		async function send() {
			var number = phone.value.trim();
			if ( '' === number ) { say( config.phoneRequired || '' ); phone.focus(); return; }
			if ( state.phone !== number ) { reset(); }
			sendButton.disabled = true;
			say( S.sending );
			try {
				var res = await post( config.requestUrl, { phone: number, token: state.token } );
				if ( ! res.ok || ! res.data.token ) { say( res.data.message || S.failed ); return; }
				state.token = res.data.token;
				state.phone = number;
				if ( tokenInput ) { tokenInput.value = ''; }
				say( ( S.sent || '%s' ).replace( '%s', res.data.phone_masked || number ) );
				if ( codeInput ) { codeInput.focus(); }
			} catch ( e ) {
				say( S.failed );
			} finally {
				sendButton.disabled = false;
			}
		}

		async function confirm() {
			var code = codeInput ? codeInput.value.replace( /\D/g, '' ) : '';
			if ( ! state.token ) { say( S.verifyRequired ); sendButton.focus(); return; }
			if ( 6 !== code.length ) { say( S.codeRequired ); if ( codeInput ) { codeInput.focus(); } return; }
			confirmButton.disabled = true;
			say( S.checking );
			try {
				var res = await post( config.confirmUrl, { token: state.token, code: code } );
				if ( ! res.ok || ! res.data.verified ) { say( res.data.message || S.failed ); return; }
				if ( tokenInput ) { tokenInput.value = state.token; }
				say( S.verified );
			} catch ( e ) {
				say( S.failed );
			} finally {
				confirmButton.disabled = false;
			}
		}

		if ( sendButton ) { sendButton.addEventListener( 'click', function ( event ) { event.preventDefault(); send(); } ); }
		if ( confirmButton ) { confirmButton.addEventListener( 'click', function ( event ) { event.preventDefault(); confirm(); } ); }
		// Enter in the code field confirms the code instead of submitting the whole order.
		if ( codeInput ) {
			codeInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) { event.preventDefault(); confirm(); }
			} );
		}
	}

	document.querySelectorAll( 'fieldset.wpcap-sms' ).forEach( setup );

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
		if ( config.verify ) {
			var token = form.querySelector( '.wpcap-sms-verify [name="sms_verification"]' );
			if ( ! token || ! token.value ) {
				detail.error = S.verifyRequired || 'Please confirm your mobile number.';
				var field = form.querySelector( '.wpcap-sms-verify [name="sms_code"]' );
				if ( field ) { detail.field = field; field.focus(); }
				return;
			}
			detail.payload.sms_verification = token.value;
		}
		detail.payload.sms_consent = true;
		detail.payload.sms_phone = value;
	} );
} )();
