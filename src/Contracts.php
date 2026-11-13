<?php
/**
 * Contract versions of the public interfaces (docs/CONTRACTS.md) and the version check of the REST APIs.
 *
 * Every interface has a version "MAJOR.MINOR". Minor versions only add fields, endpoints or optional
 * parameters; a field is never removed, renamed or changes its type within a major version. Clients may
 * send the version they were built against in the header `X-WPCAP-Contract` (e.g. "1.0", "1.4"): a
 * different major version is refused with 400 `wpcap_contract_version` before any handler runs, any minor
 * version of the same major is accepted. Without the header the current version applies. Every answer of
 * the plugin's REST namespace carries `X-WPCAP-Contract: <api>/<version>`.
 *
 * Contract fixtures (request/response shapes) live in tests/contracts/ and are checked by
 * tests/integration/modules/zz_a203.php.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Contracts {
	const HEADER = 'X-WPCAP-Contract';
	const REST_NAMESPACE = 'pass-captain/v1';

	public static function register() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'post_dispatch' ), 10, 3 );
	}

	/**
	 * Current contract version per interface.
	 *
	 * @return array<string,string> public, partner, developer, app, offline (REST), voucher_provider,
	 *                              ticket_service, checkin_service (Voucher Captain bridge), affiliate.
	 */
	public static function versions() {
		return array(
			'public'           => '1.0',
			'partner'          => Partners\Api::VERSION . '.0',
			'developer'        => '1.0',
			'app'              => Mobile\Api::VERSION,
			'offline'          => OfflineScanner\Package::VERSION . '.0',
			'voucher_provider' => Integrations\Vouchers\Bridge::CONTRACT,
			'ticket_service'   => Integrations\Vouchers\TicketService::VERSION . '.0',
			'checkin_service'  => Integrations\Vouchers\CheckinService::VERSION . '.0',
			'affiliate'        => Integrations\AffiliateCaptain\Adapter::CONTRACT,
		);
	}

	public static function version( $api ) {
		return (string) ( self::versions()[ $api ] ?? '' );
	}

	/** Major version of "1", "1.2", "1.2.3" or "v1.2"; null when the value is not a version. */
	public static function major( $version ) {
		return preg_match( '/^\s*v?(\d{1,4})(?:\.\d{1,6}){0,2}\s*$/i', (string) $version, $m ) ? (int) $m[1] : null;
	}

	/** Same major version (minor and patch differences are compatible). */
	public static function compatible( $offered, $supported ) {
		$a = self::major( $offered );
		return null !== $a && self::major( $supported ) === $a;
	}

	/** Interface of a REST request in the plugin namespace, null for other routes. */
	public static function api_for_request( $request ) {
		if ( ! is_object( $request ) ) { return null; }
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : (string) ( $request->route ?? '' );
		$prefix = '/' . self::REST_NAMESPACE;
		if ( 0 !== strpos( $route, $prefix . '/' ) && $route !== $prefix ) { return null; }
		$path = substr( $route, strlen( $prefix ) );
		if ( 0 === strpos( $path, '/partner/' ) || '/partner' === $path ) { return 'partner'; }
		if ( 0 === strpos( $path, '/app/offline/' ) ) { return 'offline'; }
		if ( 0 === strpos( $path, '/app/' ) ) { return 'app'; }
		if ( 0 === strpos( $path, '/developer/events/' ) ) { return 'developer'; }
		// The report summary is shared: with a developer key it belongs to the developer API.
		if ( preg_match( '#^/events/\d+/reports/summary$#', $path ) && method_exists( $request, 'get_header' ) && '' !== (string) $request->get_header( 'X-WPCAP-Key' ) ) { return 'developer'; }
		return 'public';
	}

	/**
	 * Checks the client's contract header.
	 *
	 * @return true|\WP_Error 400 wpcap_contract_version on a different major version.
	 */
	public static function check_request( $request ) {
		$api = self::api_for_request( $request );
		if ( null === $api || ! method_exists( $request, 'get_header' ) ) { return true; }
		$offered = trim( (string) $request->get_header( self::HEADER ) );
		if ( '' === $offered ) { return true; }
		$supported = self::version( $api );
		if ( self::compatible( $offered, $supported ) ) { return true; }
		return new \WP_Error(
			'wpcap_contract_version',
			/* translators: 1: requested contract version, 2: supported major version */
			sprintf( __( 'Die Schnittstellenversion %1$s wird nicht unterstützt. Unterstützt wird Version %2$s.x.', 'pass-captain' ), sanitize_text_field( substr( $offered, 0, 20 ) ), (string) self::major( $supported ) ),
			array( 'status' => 400, 'api' => $api, 'requested' => sanitize_text_field( substr( $offered, 0, 20 ) ), 'supported' => $supported )
		);
	}

	/** rest_pre_dispatch: refuses an incompatible major version before any permission check or handler. */
	public static function pre_dispatch( $result, $server = null, $request = null ) {
		if ( null !== $result ) { return $result; }
		$check = self::check_request( $request );
		return true === $check ? $result : $check;
	}

	/** rest_post_dispatch: announces the served contract version. */
	public static function post_dispatch( $response, $server = null, $request = null ) {
		$api = self::api_for_request( $request );
		if ( null !== $api && is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( self::HEADER, $api . '/' . self::version( $api ) );
		}
		return $response;
	}
}
