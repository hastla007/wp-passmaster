<?php
/**
 * Generic HTTP SMS gateway: POST to a configurable HTTPS endpoint with a configurable auth header and
 * body template. Placeholders {to}, {body}, {reference} are escaped for the content type
 * (JSON string content or URL encoding). The auth header value is a secret:
 * Secrets::get('sms_http_secret') (encrypted store) (constant WPCAP_SMS_HTTP_SECRET).
 *
 * Delivery reports and inbound SMS (STOP/START) are accepted at /sms/http/status and
 * /sms/http/inbound when they carry an HMAC-SHA256 signature of the raw request body, keyed with
 * Secrets::get('sms_http_webhook_secret'), in the header sms.http_signature_header (default
 * X-Signature; hex or base64, optionally prefixed "sha256="). Without that secret both endpoints
 * reject every request.
 */
namespace PassCaptain\Sms;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HttpProvider implements ProviderInterface {
	const DEFAULT_TEMPLATE = '{"to":"{to}","text":"{body}","reference":"{reference}"}';

	public function id() { return 'http'; }

	public function title() { return __( 'Generischer HTTP-Anbieter', 'pass-captain' ); }

	private static function config() {
		$s = (array) Settings::get( 'sms' );
		return array(
			'endpoint' => (string) ( $s['http_endpoint'] ?? '' ),
			'header' => (string) ( $s['http_auth_header'] ?? 'Authorization' ),
			'content_type' => 'form' === ( $s['http_content_type'] ?? 'json' ) ? 'form' : 'json',
			'template' => (string) ( $s['http_body_template'] ?? '' ) ?: self::DEFAULT_TEMPLATE,
		);
	}

	public function is_configured() {
		return 0 === stripos( self::config()['endpoint'], 'https://' );
	}

	/** Body with escaped placeholders. */
	public static function render_body( $template, $content_type, array $values ) {
		$map = array();
		foreach ( $values as $key => $value ) {
			$map[ '{' . $key . '}' ] = 'form' === $content_type ? rawurlencode( (string) $value ) : substr( (string) wp_json_encode( (string) $value ), 1, -1 );
		}
		return strtr( (string) $template, $map );
	}

	public function send( $to, $body, $reference ) {
		$c = self::config();
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'wpcap_sms_config', __( 'Der HTTP-SMS-Anbieter ist nicht konfiguriert (HTTPS-Endpunkt erforderlich).', 'pass-captain' ) );
		}
		$headers = array( 'Content-Type' => 'form' === $c['content_type'] ? 'application/x-www-form-urlencoded' : 'application/json', 'Idempotency-Key' => (string) $reference );
		$secret = \PassCaptain\Secrets::get( 'sms_http_secret' );
		$header = preg_replace( '/[^A-Za-z0-9\-]/', '', $c['header'] );
		if ( '' !== $secret && '' !== $header ) { $headers[ $header ] = $secret; }
		$args = array( 'method' => 'POST', 'timeout' => 15, 'headers' => $headers, 'body' => self::render_body( $c['template'], $c['content_type'], array( 'to' => $to, 'body' => $body, 'reference' => $reference ) ) );
		// Configurable endpoint: HTTPS, public address after DNS (pinned), no redirects (A202).
		$response = \PassCaptain\OutboundHttp::request( $c['endpoint'], $args, array( 'context' => 'sms_http', 'max_bytes' => 65536 ) );
		if ( is_wp_error( $response ) ) {
			// The guard already redacted the auth header value; refused targets are permanent (DNS/transport errors are retried).
			return new \WP_Error( 'wpcap_sms_http', \PassCaptain\Redact::text( \PassCaptain\OutboundHttp::redact( $response->get_error_message(), array( $secret ) ) ), array( 'permanent' => in_array( $response->get_error_code(), array( 'wpcap_http_url_invalid', 'wpcap_http_insecure', 'wpcap_http_host_blocked', 'wpcap_http_host_not_allowed', 'wpcap_http_unpinned', 'wpcap_http_redirect' ), true ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'wpcap_sms_http_status', 'HTTP ' . $code, array( 'status' => $code, 'permanent' => $code >= 400 && $code < 500 && 429 !== $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ref = is_array( $data ) ? (string) ( $data['id'] ?? ( $data['message_id'] ?? '' ) ) : '';
		$out = array( 'status' => 'sent', 'provider_ref' => substr( sanitize_text_field( $ref ), 0, 64 ) );
		// Optional fields of the gateway response: price, currency, segments.
		if ( is_array( $data ) && isset( $data['price'] ) && is_numeric( $data['price'] ) ) {
			$out['price'] = (string) $data['price'];
			$out['currency'] = strtoupper( substr( sanitize_text_field( (string) ( $data['currency'] ?? '' ) ), 0, 3 ) );
		}
		if ( is_array( $data ) && isset( $data['segments'] ) && is_numeric( $data['segments'] ) ) { $out['segments'] = (int) $data['segments']; }
		return $out;
	}

	/** Header carrying the callback signature (setting sms.http_signature_header). */
	public static function signature_header() {
		$s = (array) Settings::get( 'sms' );
		$header = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $s['http_signature_header'] ?? '' ) );
		return '' !== $header ? $header : 'X-Signature';
	}

	/** URL the gateway posts delivery reports to. */
	public static function status_callback_url() {
		return rest_url( 'pass-captain/v1/sms/http/status' );
	}

	/** URL the gateway posts inbound SMS to (opt-out via STOP). */
	public static function inbound_url() {
		return rest_url( 'pass-captain/v1/sms/http/inbound' );
	}

	/**
	 * Validates the callback signature: HMAC-SHA256(secret, raw body) as hex or base64, optionally
	 * prefixed with "sha256=". An empty secret or signature never validates.
	 */
	public static function callback_signature_valid( $body, $signature, $secret ) {
		$signature = trim( (string) $signature );
		if ( '' === (string) $secret || '' === $signature ) { return false; }
		if ( 0 === stripos( $signature, 'sha256=' ) ) { $signature = substr( $signature, 7 ); }
		$raw = hash_hmac( 'sha256', (string) $body, (string) $secret, true );
		return hash_equals( bin2hex( $raw ), strtolower( $signature ) ) || hash_equals( base64_encode( $raw ), $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
