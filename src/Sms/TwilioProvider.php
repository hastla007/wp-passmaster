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

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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
			// A416: Twilio has no idempotency key. After a timeout or a dropped connection the message may
			// have been created: outcome "unknown", the service looks it up (find_sent()) instead of resending.
			return new \WP_Error( 'wpcap_sms_http', \PassCaptain\Redact::text( $response->get_error_message() ), array( 'outcome' => self::transport_outcome( $response ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['sid'] ) ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
			// 5xx or a 2xx without message id: Twilio may have accepted the message.
			$unknown = $code >= 500 || ( $code >= 200 && $code < 300 );
			return new \WP_Error( 'wpcap_sms_twilio', \PassCaptain\Text::cut( \PassCaptain\Redact::text( $message ), 200 ), array( 'status' => $code, 'permanent' => $code >= 400 && $code < 500 && 429 !== $code, 'outcome' => $unknown ? 'unknown' : 'definitive' ) );
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

	/** Sending the same message twice is not safe: the Messages API has no idempotency key. */
	public function idempotent() { return false; }

	/**
	 * Outcome of a transport error: "definitive" when the request certainly never reached Twilio
	 * (refused by the outbound guard, DNS or connect failure), otherwise "unknown" (timeout, reset).
	 */
	public static function transport_outcome( \WP_Error $error ) {
		$refused = array( 'wpcap_http_url_invalid', 'wpcap_http_insecure', 'wpcap_http_host_blocked', 'wpcap_http_host_not_allowed', 'wpcap_http_unpinned' );
		if ( in_array( (string) $error->get_error_code(), $refused, true ) ) { return 'definitive'; }
		return preg_match( '/cURL error (6|7):/', (string) $error->get_error_message() ) ? 'definitive' : 'unknown';
	}

	/**
	 * Looks up a message whose send call ended without a clear answer (A416): the newest messages to
	 * $to (from the configured sender) created since $since with exactly this body.
	 *
	 * @param string $to    E.164 number.
	 * @param string $body  Message text.
	 * @param int    $since Unix time of the send attempt.
	 * @return array|null|\WP_Error ['provider_ref' => sid, 'status' => Twilio status] when found, null when
	 *                              Twilio conclusively has no such message, WP_Error when the lookup failed.
	 */
	public function find_sent( $to, $body, $since ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'wpcap_sms_config', __( 'Twilio ist nicht konfiguriert.', 'pass-captain' ) );
		}
		$sid = Settings::secret( 'twilio_account_sid' );
		$query = array( 'To' => (string) $to, 'PageSize' => 50 );
		$from = self::sender();
		if ( 0 !== strpos( $from, 'MG' ) ) { $query['From'] = $from; }
		$response = \PassCaptain\OutboundHttp::request( self::API . rawurlencode( $sid ) . '/Messages.json?' . http_build_query( $query, '', '&' ), array(
			'method' => 'GET',
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . \PassCaptain\Secrets::get( 'twilio_auth_token' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		), array( 'context' => 'sms_twilio', 'hosts' => array( 'api.twilio.com' ) ) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wpcap_sms_http', \PassCaptain\Redact::text( $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || ! isset( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
			return new \WP_Error( 'wpcap_sms_twilio', 'HTTP ' . $code, array( 'status' => $code ) );
		}
		foreach ( $data['messages'] as $message ) {
			if ( ! is_array( $message ) || empty( $message['sid'] ) || (string) ( $message['body'] ?? '' ) !== (string) $body ) { continue; }
			$created = strtotime( (string) ( $message['date_created'] ?? '' ) );
			// Clock skew between this server and Twilio: five minutes of tolerance.
			if ( false === $created || $created < (int) $since - 300 ) { continue; }
			return array( 'provider_ref' => (string) $message['sid'], 'status' => sanitize_key( (string) ( $message['status'] ?? '' ) ) );
		}
		return null;
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
