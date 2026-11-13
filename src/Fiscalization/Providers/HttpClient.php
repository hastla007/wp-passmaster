<?php
/**
 * Small JSON transport for optional fiscal provider adapters.
 *
 * Provider responses are returned only to the calling adapter. Errors never include raw
 * request/response bodies, which may contain taxpayer or authentication data.
 */
namespace PassCaptain\Fiscalization\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HttpClient {
	public static function request( $url, $method, array $headers = array(), $body = null ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'provider_endpoint_invalid', __( 'Der Fiskal-Anbieter-Endpunkt ist ungültig.', 'pass-captain' ) );
		}
		$args = array(
			'method' => strtoupper( $method ),
			'timeout' => 15,
			'redirection' => 0,
			'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'provider_transport_error', __( 'Der Fiskal-Anbieter ist nicht erreichbar.', 'pass-captain' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw = wp_remote_retrieve_body( $response );
		if ( strlen( $raw ) > 524288 ) {
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
