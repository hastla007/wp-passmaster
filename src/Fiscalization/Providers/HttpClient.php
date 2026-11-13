<?php
/**
 * Small JSON transport for optional fiscal provider adapters.
 *
 * Provider responses are returned only to the calling adapter. Errors never include raw
 * request/response bodies, which may contain taxpayer or authentication data.
 *
 * Every request goes through OutboundHttp (A202): HTTPS to a public address checked after DNS and
 * pinned, no redirects, 512 KB responses. Plain HTTP or a local address only for the exact origins
 * in $options['exceptions'] (the efsta adapter passes its fixed local EFR endpoint) or the filter
 * `wpcap_outbound_http_exceptions` (context "fiscal").
 */
namespace PassCaptain\Fiscalization\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HttpClient {
	const MAX_BYTES = 524288;

	/**
	 * @param array $options exceptions: allowed "scheme://host:port" origins (fixed provider endpoints).
	 */
	public static function request( $url, $method, array $headers = array(), $body = null, array $options = array() ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'provider_endpoint_invalid', __( 'Der Fiskal-Anbieter-Endpunkt ist ungültig.', 'pass-captain' ) );
		}
		$args = array(
			'method' => strtoupper( $method ),
			'timeout' => 15,
			'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = $args['headers']['Content-Type'] ?? 'application/json';
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}
		$response = \PassCaptain\OutboundHttp::request( $url, $args, array( 'context' => 'fiscal', 'exceptions' => (array) ( $options['exceptions'] ?? array() ), 'max_bytes' => self::MAX_BYTES ) );
		if ( is_wp_error( $response ) ) {
			if ( 'wpcap_http_too_large' === $response->get_error_code() ) {
				return new \WP_Error( 'provider_response_too_large', __( 'Die Antwort des Fiskal-Anbieters ist zu groß.', 'pass-captain' ) );
			}
			if ( 'wpcap_http_transport' !== $response->get_error_code() ) {
				// Blocked by the outbound guard (HTTP, private/metadata address, redirect): never sent.
				return new \WP_Error( 'provider_endpoint_blocked', __( 'Der Fiskal-Anbieter-Endpunkt ist nicht erlaubt (nur HTTPS zu öffentlichen Adressen).', 'pass-captain' ), array( 'reason' => $response->get_error_code() ) );
			}
			return new \WP_Error( 'provider_transport_error', __( 'Der Fiskal-Anbieter ist nicht erreichbar.', 'pass-captain' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw = wp_remote_retrieve_body( $response );
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_Error( 'provider_response_too_large', __( 'Die Antwort des Fiskal-Anbieters ist zu groß.', 'pass-captain' ) );
		}
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( ! is_array( $data ) && '' !== $raw ) {
			return new \WP_Error( 'provider_response_invalid', __( 'Die Antwort des Fiskal-Anbieters ist kein gültiges JSON.', 'pass-captain' ) );
		}
		return array( 'status' => $status, 'data' => is_array( $data ) ? $data : array(), 'raw' => $raw );
	}

	public static function endpoint( array $configuration, $fallback, $path ) {
		$base = untrailingslashit( (string) ( $configuration['base_url'] ?? $fallback ) );
		return $base . '/' . ltrim( $path, '/' );
	}

	public static function minor_to_major( $amount, $currency = 'EUR' ) {
		return \PassCaptain\Money::to_decimal( $amount, $currency );
	}
}
