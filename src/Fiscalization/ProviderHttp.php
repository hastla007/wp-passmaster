<?php
/**
 * The single HTTP transport of all fiscal provider adapters (fiskaly, efsta, fiskaltrust and
 * third-party adapters registered via `wpcap_fiscal_adapters`).
 *
 * Hardening (Security\OutboundHttp):
 * - https only to public addresses; the host is resolved and loopback, private, link-local/metadata,
 *   CGNAT, reserved and documentation ranges are refused (SSRF). Credentials in the URL are refused.
 * - a local fiscal service (e.g. efsta EFR on http://127.0.0.1:5618) is only reachable when the setting
 *   "fiscal_allow_local_service" (filter `wpcap_fiscal_allow_local_service`) is enabled, and even then
 *   plain http only to loopback/private addresses, never across the internet.
 * - no redirects, timeout 2–15 s, response size limit 512 KiB.
 * - errors never contain the URL query, headers, request or response bodies; transport problems are
 *   logged through PassCaptain\Log (redacted, host and error code only).
 *
 * Amounts: decimal() formats minor units with the decimals of the document currency
 * (Localization::money_decimal: EUR "12.50", JPY "1500", KWD "12.345").
 */
namespace PassCaptain\Fiscalization;

use PassCaptain\Log;
use PassCaptain\Security\OutboundHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class ProviderHttp {
	const MAX_BYTES = 524288;

	/** Whether local (loopback/private, plain http) fiscal services may be called for $url. */
	public static function allow_local( $url ) {
		return (bool) apply_filters( 'wpcap_fiscal_allow_local_service', (bool) \PassCaptain\Settings::get( 'fiscal_allow_local_service' ), (string) $url );
	}

	/**
	 * Validates a provider endpoint without sending a request (used when saving a configuration).
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_endpoint( $url ) {
		$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		$valid = '' !== $url ? OutboundHttp::validate_url( $url, array( 'allow_local' => self::allow_local( $url ) ) ) : false;
		if ( true !== $valid ) {
			return new \WP_Error( 'provider_endpoint_invalid', __( 'Der Fiskal-Anbieter-Endpunkt ist ungültig oder nicht erlaubt (nur öffentliche HTTPS-Endpunkte; lokale Dienste nur nach Freigabe in den Einstellungen).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * JSON request to a fiscal provider.
	 *
	 * @param string            $url     Endpoint (https; local http only after opt-in).
	 * @param string            $method  HTTP method.
	 * @param array             $headers Extra headers.
	 * @param array|string|null $body    Array: sent as JSON. String: pre-encoded body (e.g. an OAuth form
	 *                                   post), sent as is; the caller sets the content type.
	 * @param int               $timeout Seconds (clamped to 2–15).
	 * @return array|\WP_Error ['status' => int, 'data' => array, 'headers' => array]. Non-2xx answers are a
	 *                         WP_Error `provider_http_{status}` with data status and provider_code.
	 */
	public static function request( $url, $method = 'GET', array $headers = array(), $body = null, $timeout = 8 ) {
		$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		$valid = self::validate_endpoint( $url );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$args = array(
			'method' => strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $method ) ),
			'timeout' => max( 2, min( 15, absint( $timeout ) ) ),
			'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( is_string( $body ) ) {
			$args['body'] = $body;
		} elseif ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}
		$response = OutboundHttp::request( $url, $args, array( 'allow_local' => self::allow_local( $url ), 'max_bytes' => self::MAX_BYTES ) );
		if ( is_wp_error( $response ) ) {
			$code = (string) $response->get_error_code();
			$data = (array) $response->get_error_data();
			$status = absint( $data['response_code'] ?? 0 );
			self::log_failure( $url, $code, $status );
			if ( 'wpcap_outbound_too_large' === $code ) {
				return new \WP_Error( 'provider_response_too_large', __( 'Die Antwort des Fiskal-Anbieters ist zu groß.', 'pass-captain' ), array( 'status' => $status ) );
			}
			if ( 'wpcap_outbound_redirect' === $code ) {
				return new \WP_Error( 'provider_redirect', __( 'Der Fiskal-Anbieter hat umgeleitet; Weiterleitungen werden nicht verfolgt.', 'pass-captain' ), array( 'status' => $status ) );
			}
			if ( 'wpcap_outbound_transport' !== $code ) {
				return new \WP_Error( 'provider_endpoint_invalid', __( 'Der Fiskal-Anbieter-Endpunkt ist ungültig oder nicht erlaubt (nur öffentliche HTTPS-Endpunkte; lokale Dienste nur nach Freigabe in den Einstellungen).', 'pass-captain' ), array( 'status' => 0 ) );
			}
			return new \WP_Error( 'provider_transport_error', __( 'Der Fiskal-Anbieter ist nicht erreichbar.', 'pass-captain' ), array( 'status' => 0 ) );
		}
		$status = (int) $response['code'];
		$raw = (string) $response['body'];
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$provider_code = is_array( $data ) ? ( $data['error_code'] ?? $data['code'] ?? '' ) : '';
			if ( $status >= 500 || 429 === $status ) { self::log_failure( $url, 'http_' . $status, $status ); } // 4xx (e.g. 404 on a status lookup) is an expected answer.
			return new \WP_Error(
				'provider_http_' . ( $status ? $status : 'invalid' ),
				__( 'Der Fiskal-Anbieter hat die Anfrage abgelehnt oder eine ungültige Antwort gesendet.', 'pass-captain' ),
				array( 'status' => $status, 'provider_code' => is_scalar( $provider_code ) ? sanitize_key( (string) $provider_code ) : '' )
			);
		}
		return array( 'status' => $status, 'data' => $data, 'headers' => (array) $response['headers'] );
	}

	/** HTTP status of a provider error (0 when unknown or not an error). */
	public static function status( $error ) {
		if ( ! is_wp_error( $error ) ) { return 0; }
		$data = $error->get_error_data();
		return is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
	}

	/** Deterministic UUID v4-formatted identifier from an idempotency key. */
	public static function uuid_from_key( $key ) {
		$hex = substr( hash( 'sha256', (string) $key ), 0, 32 );
		$hex[12] = '4';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 3 ) | 8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Amount in minor units as decimal string with the decimals of the currency ("12.50"; JPY "1500",
	 * KWD "12.345"). Without currency two decimals are assumed (EUR).
	 */
	public static function decimal( $minor, $currency = 'EUR' ) {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		return \PassCaptain\Localization::money_decimal( (int) $minor, '' !== $currency ? $currency : 'EUR' );
	}

	/** Alias of decimal() (earlier transport API). */
	public static function minor_to_major( $amount, $currency = 'EUR' ) {
		return self::decimal( $amount, $currency );
	}

	/** Host and error code only: no path, query, headers or bodies (they may carry taxpayer or auth data). */
	private static function log_failure( $url, $code, $status ) {
		$host = (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url, PHP_URL_HOST ) : parse_url( (string) $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback outside WordPress (CLI tools, tests).
		Log::warning( 'fiscal_provider_http_failed', sprintf( 'Fiscal provider request to %s failed (%s).', $host, sanitize_key( (string) $code ) ), array(
			'object_type' => 'fiscal_provider',
			'details' => array( 'host' => $host, 'error_code' => sanitize_key( (string) $code ), 'status' => absint( $status ) ),
		) );
	}
}
