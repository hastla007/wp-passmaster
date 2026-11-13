<?php
/**
 * Generic HTTP SMS gateway: POST to a configurable HTTPS endpoint with a configurable auth header and
 * body template. Placeholders {to}, {body}, {reference} are escaped for the content type
 * (JSON string content or URL encoding). The auth header value is a secret:
 * Secrets::get('sms_http_secret') (encrypted store) (constant WPCAP_SMS_HTTP_SECRET).
 */
namespace PassCaptain\Sms;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HttpProvider implements ProviderInterface {
	const DEFAULT_TEMPLATE = '{"to":"{to}","text":"{body}","reference":"{reference}"}';

	public function id() { return 'http'; }

	public function title() { return __( 'Generischer HTTP-Anbieter', 'wp-pass-captain' ); }

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
			return new \WP_Error( 'wpcap_sms_config', __( 'Der HTTP-SMS-Anbieter ist nicht konfiguriert (HTTPS-Endpunkt erforderlich).', 'wp-pass-captain' ) );
		}
		$headers = array( 'Content-Type' => 'form' === $c['content_type'] ? 'application/x-www-form-urlencoded' : 'application/json', 'Idempotency-Key' => (string) $reference );
		$secret = \PassCaptain\Secrets::get( 'sms_http_secret' );
		$header = preg_replace( '/[^A-Za-z0-9\-]/', '', $c['header'] );
		if ( '' !== $secret && '' !== $header ) { $headers[ $header ] = $secret; }
		$args = array( 'timeout' => 15, 'redirection' => 0, 'headers' => $headers, 'body' => self::render_body( $c['template'], $c['content_type'], array( 'to' => $to, 'body' => $body, 'reference' => $reference ) ) );
		$response = function_exists( 'wp_safe_remote_post' ) ? wp_safe_remote_post( $c['endpoint'], $args ) : wp_remote_post( $c['endpoint'], $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wpcap_sms_http', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'wpcap_sms_http_status', 'HTTP ' . $code, array( 'status' => $code, 'permanent' => $code >= 400 && $code < 500 && 429 !== $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ref = is_array( $data ) ? (string) ( $data['id'] ?? ( $data['message_id'] ?? '' ) ) : '';
		return array( 'status' => 'sent', 'provider_ref' => substr( sanitize_text_field( $ref ), 0, 64 ) );
	}
}
