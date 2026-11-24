<?php
/**
 * Buyer credentials of public REST routes (order access token, booking/waitlist token).
 *
 * The token is read from the request header X-WPCAP-Access-Token first, then from the parameter
 * (`access_token` or `token`, query string or body). The plugin's own JavaScript sends the header,
 * so the token does not end up in URLs (access logs, proxies, browser history; SEC-7 / PRIV-14).
 * Query parameters stay accepted for compatibility (links and integrations from earlier versions).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Credentials {
	const HEADER = 'X-WPCAP-Access-Token';

	public static function register() {
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
	}

	/** Filter rest_allowed_cors_headers: the header may be sent by browser clients. */
	public static function cors_headers( $headers ) {
		$headers = is_array( $headers ) ? $headers : array();
		$headers[] = self::HEADER;
		return array_values( array_unique( $headers ) );
	}

	/**
	 * Token of a request: header first, then the named parameter ('access_token' or 'token').
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $param   Parameter name used before the header existed.
	 * @return string Raw value ('' when absent or not a string); callers validate it.
	 */
	public static function token( $request, $param = 'access_token' ) {
		$header = is_object( $request ) && method_exists( $request, 'get_header' ) ? $request->get_header( self::HEADER ) : null;
		if ( is_string( $header ) && '' !== trim( $header ) ) { return trim( $header ); }
		$value = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( $param ) : null;
		return is_scalar( $value ) ? (string) $value : '';
	}
}
