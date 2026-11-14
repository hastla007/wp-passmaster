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
	/**
	 * JSON (or form-encoded, when $body is a string) request to a fiscal service.
	 *
	 * Targets must be public https endpoints (SSRF protection, Security\OutboundHttp). A local
	 * service (e.g. efsta EFR on http://127.0.0.1:5618) is only reachable when the setting
	 * "fiscal_allow_local_service" (filter wpcap_fiscal_allow_local_service) is enabled.
	 */
	public static function request( $url, $method, array $headers = array(), $body = null ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		$allow_local = (bool) apply_filters( 'wpcap_fiscal_allow_local_service', (bool) \PassCaptain\Settings::get( 'fiscal_allow_local_service' ), $url );
		if ( ! $url || is_wp_error( \PassCaptain\Security\OutboundHttp::validate_url( $url, array( 'allow_local' => $allow_local ) ) ) ) {
			return new \WP_Error( 'provider_endpoint_invalid', __( 'Der Fiskal-Anbieter-Endpunkt ist ungültig oder nicht erlaubt (nur öffentliche HTTPS-Endpunkte; lokale Dienste nur nach Freigabe in den Einstellungen).', 'pass-captain' ) );
		}
		$args = array(
			'method' => strtoupper( $method ),
			'timeout' => 15,
			'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( is_string( $body ) ) {
			// Pre-encoded body (e.g. OAuth form post); the caller sets the content type.
			$args['body'] = $body;
		} elseif ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}
		$response = \PassCaptain\Security\OutboundHttp::request( $url, $args, array( 'allow_local' => $allow_local, 'max_bytes' => 524288 ) );
		if ( is_wp_error( $response ) ) {
			if ( 'wpcap_outbound_too_large' === $response->get_error_code() ) {
				return new \WP_Error( 'provider_response_too_large', __( 'Die Antwort des Fiskal-Anbieters ist zu groß.', 'pass-captain' ) );
			}
			if ( 'wpcap_outbound_redirect' === $response->get_error_code() ) {
				return new \WP_Error( 'provider_redirect', __( 'Der Fiskal-Anbieter hat umgeleitet; Weiterleitungen werden nicht verfolgt.', 'pass-captain' ) );
			}
			return new \WP_Error( 'provider_transport_error', __( 'Der Fiskal-Anbieter ist nicht erreichbar.', 'pass-captain' ) );
		}
		$status = (int) $response['code'];
		$raw = (string) $response['body'];
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

	public static function minor_to_major( $amount ) {
		return number_format( (int) $amount / 100, 2, '.', '' );
	}
}
