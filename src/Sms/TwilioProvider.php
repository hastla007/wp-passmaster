<?php
/**
 * Twilio Programmable Messaging adapter (Messages API, form-encoded, HTTP Basic auth).
 *
 * Credentials: Settings::secret('twilio_account_sid') / Secrets::get('twilio_auth_token'), i.e.
 * the constants WPCAP_TWILIO_ACCOUNT_SID / WPCAP_TWILIO_AUTH_TOKEN, environment variables or stored
 * settings. Delivery reports arrive at the status callback, signed with X-Twilio-Signature.
 */
namespace PassCaptain\Sms;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TwilioProvider implements ProviderInterface {
	const API = 'https://api.twilio.com/2010-04-01/Accounts/';

	public function id() { return 'twilio'; }

	public function title() { return 'Twilio'; }

	public function is_configured() {
		return 1 === preg_match( '/^AC[0-9a-fA-F]{32}$/', Settings::secret( 'twilio_account_sid' ) )
			&& '' !== \PassCaptain\Secrets::get( 'twilio_auth_token' )
			&& '' !== self::sender();
	}

	/** Sender: phone number (+…), alphanumeric sender id or Messaging Service SID (MG…). */
	public static function sender() {
		$settings = (array) Settings::get( 'sms' );
		return trim( (string) ( $settings['twilio_from'] ?? '' ) );
	}

	/** URL Twilio posts delivery reports to (also the URL the signature is computed over). */
	public static function status_callback_url() {
		return rest_url( 'pass-captain/v1/sms/twilio/status' );
	}

	/** URL configured as "A message comes in" webhook of the Twilio number (opt-out via STOP). */
	public static function inbound_url() {
		return rest_url( 'pass-captain/v1/sms/twilio/inbound' );
	}

	public function send( $to, $body, $reference ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'wpcap_sms_config', __( 'Twilio ist nicht konfiguriert.', 'pass-captain' ) );
		}
		$sid = Settings::secret( 'twilio_account_sid' );
		$params = array( 'To' => (string) $to, 'Body' => (string) $body, 'StatusCallback' => self::status_callback_url() );
		$from = self::sender();
		if ( 0 === strpos( $from, 'MG' ) ) { $params['MessagingServiceSid'] = $from; } else { $params['From'] = $from; }
		$response = \PassCaptain\OutboundHttp::request( self::API . rawurlencode( $sid ) . '/Messages.json', array(
			'method' => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $sid . ':' . \PassCaptain\Secrets::get( 'twilio_auth_token' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body' => http_build_query( $params, '', '&' ),
		), array( 'context' => 'sms_twilio', 'hosts' => array( 'api.twilio.com' ) ) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wpcap_sms_http', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['sid'] ) ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'wpcap_sms_twilio', substr( $message, 0, 200 ), array( 'status' => $code, 'permanent' => $code >= 400 && $code < 500 && 429 !== $code ) );
		}
		$out = array( 'status' => 'sent', 'provider_ref' => (string) $data['sid'] );
		// Twilio usually reports the price only later (null at creation); num_segments is known at once.
		if ( isset( $data['price'] ) && is_numeric( $data['price'] ) ) {
			$out['price'] = (string) $data['price'];
			$out['currency'] = strtoupper( (string) ( $data['price_unit'] ?? '' ) );
		}
		if ( isset( $data['num_segments'] ) && is_numeric( $data['num_segments'] ) ) { $out['segments'] = (int) $data['num_segments']; }
		return $out;
	}

	/**
	 * Validates X-Twilio-Signature: Base64(HMAC-SHA1(auth token, URL + concatenation of all POST
	 * parameters sorted by name, each as name+value)).
	 */
	public static function signature_valid( $url, array $params, $signature, $token ) {
		if ( '' === (string) $token || '' === (string) $signature ) { return false; }
		ksort( $params, SORT_STRING );
		$data = (string) $url;
		foreach ( $params as $key => $value ) {
			$data .= $key . ( is_array( $value ) ? implode( '', $value ) : (string) $value );
		}
		$expected = base64_encode( hash_hmac( 'sha1', $data, (string) $token, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return hash_equals( $expected, (string) $signature );
	}
}
