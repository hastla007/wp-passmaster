<?php
/** Safe, bounded JSON transport for built-in fiscal adapters. */
namespace PassCaptain\Fiscalization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ProviderHttp {
	public static function request( $url, $method = 'GET', array $headers = array(), $body = null, $timeout = 8 ) {
		$url = esc_url_raw( $url, array( 'https', 'http' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'provider_endpoint_invalid', __( 'Der Anbieter-Endpunkt ist ungültig.', 'wp-pass-captain' ) );
		}
		$host = strtolower( $parts['host'] );
		$is_loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( 'https' !== strtolower( $parts['scheme'] ?? '' ) && ! ( $is_loopback && 'http' === strtolower( $parts['scheme'] ?? '' ) ) ) {
			return new \WP_Error( 'provider_endpoint_insecure', __( 'Anbieter-Endpunkte müssen HTTPS verwenden; HTTP ist nur für Loopback erlaubt.', 'wp-pass-captain' ) );
		}
		$args = array(
			'method' => strtoupper( sanitize_key( $method ) ),
			'timeout' => max( 2, min( 15, absint( $timeout ) ) ),
			'redirection' => 0,
			'sslverify' => true,
			'headers' => array_merge( array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) { $args['body'] = is_string( $body ) ? $body : wp_json_encode( $body ); }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { return new \WP_Error( 'provider_transport_error', __( 'Der Fiskalanbieter ist derzeit nicht erreichbar.', 'wp-pass-captain' ) ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return new \WP_Error(
				'provider_http_' . ( $status ?: 'invalid' ),
				__( 'Der Fiskalanbieter hat die Anfrage abgelehnt oder eine ungültige Antwort gesendet.', 'wp-pass-captain' ),
				array( 'status' => $status, 'provider_code' => sanitize_key( $data['error_code'] ?? $data['code'] ?? '' ) )
			);
		}
		return array( 'status' => $status, 'data' => $data, 'headers' => wp_remote_retrieve_headers( $response ) );
	}

	public static function uuid_from_key( $key ) {
		$hex = substr( hash( 'sha256', (string) $key ), 0, 32 );
		$hex[12] = '4';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 3 ) | 8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	public static function decimal( $minor ) { return number_format( (int) $minor / 100, 2, '.', '' ); }
}
