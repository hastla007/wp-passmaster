<?php
/**
 * Apple PassKit web service under /wp-json/wp-pass-captain/v1/wallet/apple/v1/… plus the signed
 * .pkpass download link used in mails (/wallet/apple/download/{serial}?sig=…).
 *
 * Device requests authenticate with "Authorization: ApplePass <authenticationToken>"; tokens are
 * random per pass generation and stored as SHA-256 hashes.
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WebService {
	const NS = 'wp-pass-captain/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw' ), 10, 2 );
	}

	public static function routes() {
		$base = '/' . AppleProvider::REST_BASE;
		$registration = $base . '/v1/devices/(?P<device>[A-Za-z0-9]{1,128})/registrations/(?P<pass_type>[A-Za-z0-9.\-]{1,190})';
		register_rest_route( self::NS, $registration . '/(?P<serial>[A-Za-z0-9\-]{1,190})', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'register_device' ), 'permission_callback' => '__return_true' ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'unregister_device' ), 'permission_callback' => '__return_true' ),
		) );
		register_rest_route( self::NS, $registration, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'serials' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, $base . '/v1/passes/(?P<pass_type>[A-Za-z0-9.\-]{1,190})/(?P<serial>[A-Za-z0-9\-]{1,190})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'latest_pass' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, $base . '/v1/log', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'log' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, $base . '/download/(?P<serial>[A-Za-z0-9\-]{1,190})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'download' ), 'permission_callback' => '__return_true' ) );
	}

	private static function unauthorized() {
		return new \WP_Error( 'wpcap_wallet_unauthorized', __( 'Nicht autorisiert.', 'wp-pass-captain' ), array( 'status' => 401 ) );
	}

	private static function token( \WP_REST_Request $request ) {
		return preg_match( '/^ApplePass\s+([A-Za-z0-9]{16,128})$/', trim( (string) $request->get_header( 'authorization' ) ), $m ) ? $m[1] : '';
	}

	/**
	 * Resolves the pass of a request and checks the token.
	 *
	 * @param bool $allow_retired Accept tokens of older generations registered by a device.
	 * @return array{pass:object,hash:string,current:bool}|\WP_Error
	 */
	private static function authorize( \WP_REST_Request $request, $allow_retired ) {
		$apple = Service::provider( 'apple' );
		$token = self::token( $request );
		if ( ! $apple || '' === $token || ! RateLimit::allow( 'wallet_ws_' . RateLimit::client(), 600, MINUTE_IN_SECONDS ) ) { return self::unauthorized(); }
		$serial = (string) $request->get_param( 'serial' );
		$pass = Store::pass_by_serial( 'apple', $serial );
		if ( ! $pass || (string) $request->get_param( 'pass_type' ) !== $pass->provider_ref ) { return self::unauthorized(); }
		$state = Store::state( $pass->ticket_id );
		$hash = hash( 'sha256', $token );
		if ( $state && hash_equals( (string) $state->auth_token_hash, $hash ) ) { return array( 'pass' => $pass, 'hash' => $hash, 'current' => true ); }
		if ( $allow_retired && Store::registration_by_auth( $serial, $hash ) ) { return array( 'pass' => $pass, 'hash' => $hash, 'current' => false ); }
		return self::unauthorized();
	}

	public static function register_device( \WP_REST_Request $request ) {
		$auth = self::authorize( $request, false );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$push = (string) ( $request->get_param( 'pushToken' ) ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9]{8,200}$/', $push ) ) {
			return new \WP_Error( 'wpcap_wallet_push_token', __( 'Ungültiger Push-Token.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$created = Store::register_device( (string) $request->get_param( 'device' ), $auth['pass']->provider_ref, $auth['pass']->serial_number, $push, $auth['hash'] );
		return new \WP_REST_Response( null, $created ? 201 : 200 );
	}

	public static function unregister_device( \WP_REST_Request $request ) {
		$auth = self::authorize( $request, true );
		if ( is_wp_error( $auth ) ) { return $auth; }
		Store::unregister_device( (string) $request->get_param( 'device' ), $auth['pass']->serial_number );
		return new \WP_REST_Response( null, 200 );
	}

	/** Serial numbers changed since passesUpdatedSince (no auth by Apple's design; device ids are secret). */
	public static function serials( \WP_REST_Request $request ) {
		if ( ! RateLimit::allow( 'wallet_ws_' . RateLimit::client(), 600, MINUTE_IN_SECONDS ) ) { return new \WP_Error( 'wpcap_wallet_rate', 'rate limited', array( 'status' => 429 ) ); }
		$device = (string) $request->get_param( 'device' );
		$pass_type = (string) $request->get_param( 'pass_type' );
		$since = preg_replace( '/[^0-9]/', '', (string) $request->get_param( 'passesUpdatedSince' ) );
		$rows = Store::updated_serials( $device, $pass_type, '' === $since ? 0 : (int) substr( $since, 0, 18 ) );
		if ( ! $rows ) { return new \WP_REST_Response( null, Store::has_registrations_for_device( $device, $pass_type ) ? 204 : 404 ); }
		$last = 0;
		$serials = array();
		foreach ( $rows as $row ) { $serials[] = $row->serial_number; $last = max( $last, (int) $row->update_tag ); }
		return new \WP_REST_Response( array( 'serialNumbers' => array_values( array_unique( $serials ) ), 'lastUpdated' => (string) $last ), 200 );
	}

	private static function pkpass_response( array $built ) {
		$modified = gmdate( 'D, d M Y H:i:s', (int) floor( $built['tag'] / 1000 ) ) . ' GMT';
		return new \WP_REST_Response( array( '__wpcap_raw' => $built['bundle'], 'headers' => array( 'Content-Type' => 'application/vnd.apple.pkpass', 'Last-Modified' => $modified, 'Cache-Control' => 'no-store' ) ), 200 );
	}

	public static function latest_pass( \WP_REST_Request $request ) {
		$auth = self::authorize( $request, true );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$state = Store::state( $auth['pass']->ticket_id );
		$since = strtotime( (string) $request->get_header( 'if_modified_since' ) );
		if ( $since && $auth['current'] && $state && (int) floor( $state->update_tag / 1000 ) <= $since ) { return new \WP_REST_Response( null, 304 ); }
		try {
			$built = Service::apple_bundle( $auth['pass']->serial_number, self::token( $request ) );
		} catch ( \Throwable $e ) {
			Service::log( 'build_failed', $e->getMessage() );
			$built = null;
		}
		return $built ? self::pkpass_response( $built ) : new \WP_Error( 'wpcap_wallet_build', __( 'Pass konnte nicht erstellt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
	}

	public static function download( \WP_REST_Request $request ) {
		$serial = (string) $request->get_param( 'serial' );
		$pass = Store::pass_by_serial( 'apple', $serial );
		$state = $pass ? Store::state( $pass->ticket_id ) : null;
		$sig = (string) $request->get_param( 'sig' );
		if ( ! RateLimit::allow( 'wallet_dl_' . RateLimit::client(), 60, MINUTE_IN_SECONDS ) || ! $state || ! hash_equals( Service::download_sig( $serial, $state ), $sig ) ) {
			return new \WP_Error( 'wpcap_wallet_link', __( 'Dieser Wallet-Link ist ungültig oder wurde durch einen neueren ersetzt.', 'wp-pass-captain' ), array( 'status' => 404 ) );
		}
		try {
			$built = Service::apple_bundle( $serial, null );
		} catch ( \Throwable $e ) {
			Service::log( 'build_failed', $e->getMessage() );
			$built = null;
		}
		if ( ! $built ) { return new \WP_Error( 'wpcap_wallet_build', __( 'Pass konnte nicht erstellt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$response = self::pkpass_response( $built );
		$data = $response->get_data();
		$data['headers']['Content-Disposition'] = 'attachment; filename="ticket.pkpass"';
		return new \WP_REST_Response( $data, 200 );
	}

	/** Device error log; kept (truncated, without identifiers) for the admin status page. */
	public static function log( \WP_REST_Request $request ) {
		if ( ! RateLimit::allow( 'wallet_log_' . RateLimit::client(), 30, HOUR_IN_SECONDS ) ) { return new \WP_REST_Response( null, 200 ); }
		$logs = $request->get_param( 'logs' );
		foreach ( array_slice( is_array( $logs ) ? $logs : array(), 0, 10 ) as $line ) {
			if ( is_string( $line ) ) { Service::log( 'device', preg_replace( '/[A-Za-z0-9]{20,}/', '…', $line ) ); }
		}
		return new \WP_REST_Response( null, 200 );
	}

	/** rest_pre_serve_request: sends binary .pkpass responses unencoded. */
	public static function serve_raw( $served, $result ) {
		if ( $served || ! is_object( $result ) || ! method_exists( $result, 'get_data' ) ) { return $served; }
		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['__wpcap_raw'] ) ) { return $served; }
		if ( ! headers_sent() ) {
			foreach ( (array) ( $data['headers'] ?? array() ) as $name => $value ) { header( $name . ': ' . $value ); }
			header( 'Content-Length: ' . strlen( $data['__wpcap_raw'] ) );
		}
		echo $data['__wpcap_raw']; // phpcs:ignore WordPress.Security.EscapeOutput -- binary pkpass.
		return true;
	}
}
