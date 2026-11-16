<?php
/**
 * Apple PassKit web service under /wp-json/pass-captain/v1/wallet/apple/v1/… plus the signed links
 * used in mails: .pkpass download (/wallet/apple/download/{serial}?sig=…), .pkpasses bundle of an
 * order (/wallet/apple/bundle?o=…&p=…&sig=…) and the short Google save link (/wallet/google/save).
 *
 * Device requests authenticate with "Authorization: ApplePass <authenticationToken>"; tokens are
 * random per pass generation and stored as SHA-256 hashes.
 *
 * Rate limits (A461) answer 429 with Retry-After, never 401/404: a 401 tells the device its pass is
 * no longer valid. Authenticated device requests count per serial number, the serial list per device
 * library id; the client address only counts failed requests (wrong token, unknown device), because
 * many phones share one address behind a venue or carrier NAT. Limits: filter
 * `wpcap_wallet_rate_limits`.
 */
namespace PassCaptain\WalletPasses;

use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WebService {
	const NS = 'pass-captain/v1';

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
		// A580: several Apple passes of an order as one .pkpasses bundle ("add all").
		register_rest_route( self::NS, $base . '/bundle', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bundle' ), 'permission_callback' => '__return_true' ) );
		// A583: short "Save to Google Wallet" link; objects are inserted on click, the JWT only carries IDs.
		// Signed collection page of an order (Apple/Google buttons of the tickets the buyer still holds).
		// Signed collection page of an order (Apple/Google buttons of the tickets the buyer still holds).
		register_rest_route( self::NS, '/wallet/order/(?P<order>[A-Za-z0-9\-]{8,64})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'collection' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/wallet/google/save', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'google_save' ), 'permission_callback' => '__return_true' ) );
	}

	/**
	 * Limits per window (A461): serial (authenticated requests of one pass), device (serial list of one
	 * device library), failures (failed requests per client address), download (signed links per client).
	 *
	 * @return array{serial:int,device:int,failures:int,download:int,google:int,window:int}
	 */
	public static function limits() {
		$defaults = array( 'serial' => 120, 'device' => 120, 'failures' => 60, 'download' => 60, 'google' => 30, 'window' => MINUTE_IN_SECONDS );
		$limits = (array) apply_filters( 'wpcap_wallet_rate_limits', $defaults );
		$out = array();
		foreach ( $defaults as $key => $default ) { $out[ $key ] = max( 1, (int) ( $limits[ $key ] ?? $default ) ); }
		return $out;
	}

	/** 429 with Retry-After (seconds until the current window ends). */
	private static function rate_limited( $window ) {
		$window = max( 1, (int) $window );
		$response = new \WP_REST_Response( array( 'code' => 'wpcap_wallet_rate_limited', 'message' => __( 'Zu viele Anfragen. Bitte versuche es in einer Minute erneut.', 'pass-captain' ), 'data' => array( 'status' => 429 ) ), 429 );
		$response->header( 'Retry-After', (string) max( 1, $window - time() % $window ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Whether the client address used up its budget of failed requests (checked before, counted on failure). */
	private static function too_many_failures( array $limits ) {
		return RateLimit::hits( 'wallet_ws_fail_' . RateLimit::client(), $limits['window'] ) >= $limits['failures'];
	}

	private static function count_failure( array $limits ) {
		RateLimit::allow( 'wallet_ws_fail_' . RateLimit::client(), $limits['failures'], $limits['window'] );
	}

	private static function invalid_link() {
		return new \WP_Error( 'wpcap_wallet_link', __( 'Dieser Wallet-Link ist ungültig oder wurde durch einen neueren ersetzt.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	private static function link_params( \WP_REST_Request $request ) {
		$out = array();
		foreach ( array( 't', 'o', 'p', 'sig' ) as $key ) { $out[ $key ] = (string) $request->get_param( $key ); }
		return $out;
	}

	/** GET /wallet/google/save?t=…&sig=… (one ticket) or ?o=…&p=…&sig=… (batch of an order): 302 to Google. */
	public static function google_save( \WP_REST_Request $request ) {
		$limits = self::limits();
		if ( ! RateLimit::allow( 'wallet_gsave_' . RateLimit::client(), $limits['google'], $limits['window'] ) ) { return self::rate_limited( $limits['window'] ); }
		if ( ! Service::provider( 'google' ) ) { return self::invalid_link(); }
		$tickets = Service::link_tickets( 'google', self::link_params( $request ) );
		if ( ! $tickets ) { return self::invalid_link(); }
		$url = Service::google_save_url( $tickets );
		if ( '' === $url ) {
			return new \WP_Error( 'wpcap_wallet_unavailable', __( 'Google Wallet ist gerade nicht erreichbar. Bitte versuche es in ein paar Minuten erneut.', 'pass-captain' ), array( 'status' => 503 ) );
		}
		return new RawResponse( '', array( 'Location' => $url, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer' ), 302 );
	}

	/** GET /wallet/apple/bundle?o=…&p=…&sig=…: the batch's passes as application/vnd.apple.pkpasses. */
	public static function bundle( \WP_REST_Request $request ) {
		$limits = self::limits();
		if ( ! RateLimit::allow( 'wallet_dl_' . RateLimit::client(), $limits['download'], $limits['window'] ) ) { return self::rate_limited( $limits['window'] ); }
		if ( ! Service::provider( 'apple' ) ) { return self::invalid_link(); }
		$params = self::link_params( $request );
		unset( $params['t'] );
		$tickets = Service::link_tickets( 'apple', $params );
		if ( ! $tickets ) { return self::invalid_link(); }
		$files = array();
		foreach ( $tickets as $i => $ticket ) {
			try {
				$built = Service::apple_bundle( (string) $ticket['public_id'], null );
			} catch ( \Throwable $e ) {
				Service::log( 'build_failed', $e->getMessage() );
				$built = null;
			}
			if ( $built && ! empty( $built['bundle'] ) ) { $files[ sprintf( 'ticket-%02d.pkpass', $i + 1 ) ] = $built['bundle']; }
		}
		if ( ! $files ) { return new \WP_Error( 'wpcap_wallet_build', __( 'Pass konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		return new RawResponse( Archive::zip( $files ), array( 'Content-Type' => 'application/vnd.apple.pkpasses', 'Content-Disposition' => 'attachment; filename="tickets.pkpasses"', 'Cache-Control' => 'no-store' ), 200 );
	}

	/**
	 * Signed collection page of an order (multi-ticket mails link here instead of attaching one pass
	 * per ticket): HTML list of the Apple/Google buttons of the tickets the buyer still holds.
	 */
	public static function collection( \WP_REST_Request $request ) {
		$order = (string) $request->get_param( 'order' );
		$expires = (int) $request->get_param( 'exp' );
		$sig = (string) $request->get_param( 'sig' );
		$limits = self::limits();
		if ( ! RateLimit::allow( 'wallet_dl_' . RateLimit::client(), $limits['download'], $limits['window'] ) ) { return self::rate_limited( $limits['window'] ); }
		if ( $expires < time() || ! hash_equals( Service::collection_sig( $order, $expires ), $sig ) ) {
			return new \WP_Error( 'wpcap_wallet_link', __( 'Dieser Wallet-Link ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$headers = array( 'Content-Type' => 'text/html; charset=utf-8' );
		foreach ( \PassCaptain\Security\PrivatePage::header_lines() as $line ) {
			list( $name, $value ) = explode( ':', $line, 2 );
			$headers[ $name ] = trim( $value );
		}
		return new RawResponse( Service::collection_html( $order ), $headers, 200 );
	}

	private static function unauthorized() {
		return new \WP_Error( 'wpcap_wallet_unauthorized', __( 'Nicht autorisiert.', 'pass-captain' ), array( 'status' => 401 ) );
	}

	private static function token( \WP_REST_Request $request ) {
		return preg_match( '/^ApplePass\s+([A-Za-z0-9]{16,128})$/', trim( (string) $request->get_header( 'authorization' ) ), $m ) ? $m[1] : '';
	}

	/**
	 * Resolves the pass of a request and checks the token.
	 *
	 * Rate limits (A461) give 429 + Retry-After: per serial number for every request, per client address
	 * only for failed ones.
	 *
	 * @param bool $allow_retired Accept tokens of older generations registered by a device.
	 * @return array{pass:object,hash:string,current:bool}|\WP_Error|\WP_REST_Response
	 */
	private static function authorize( \WP_REST_Request $request, $allow_retired ) {
		$apple = Service::provider( 'apple' );
		if ( ! $apple ) { return self::unauthorized(); }
		$limits = self::limits();
		if ( self::too_many_failures( $limits ) ) { return self::rate_limited( $limits['window'] ); }
		$token = self::token( $request );
		$serial = (string) $request->get_param( 'serial' );
		$pass = '' === $token ? null : Store::pass_by_serial( 'apple', $serial );
		if ( ! $pass || (string) $request->get_param( 'pass_type' ) !== $pass->provider_ref ) {
			self::count_failure( $limits );
			return self::unauthorized();
		}
		$state = Store::state( $pass->ticket_id );
		$hash = hash( 'sha256', $token );
		$auth = null;
		if ( $state && hash_equals( (string) $state->auth_token_hash, $hash ) ) {
			$auth = array( 'pass' => $pass, 'hash' => $hash, 'current' => true );
		} elseif ( $allow_retired && Store::registration_by_auth( $serial, $hash ) ) {
			$auth = array( 'pass' => $pass, 'hash' => $hash, 'current' => false );
		}
		if ( ! $auth ) {
			self::count_failure( $limits );
			return self::unauthorized();
		}
		// Only authenticated requests use the serial's budget: wrong tokens cannot lock a pass out.
		if ( ! RateLimit::allow( 'wallet_ws_serial_' . $serial, $limits['serial'], $limits['window'] ) ) { return self::rate_limited( $limits['window'] ); }
		return $auth;
	}

	public static function register_device( \WP_REST_Request $request ) {
		$auth = self::authorize( $request, false );
		if ( ! is_array( $auth ) ) { return $auth; }
		$push = (string) ( $request->get_param( 'pushToken' ) ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9]{8,200}$/', $push ) ) {
			return new \WP_Error( 'wpcap_wallet_push_token', __( 'Ungültiger Push-Token.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$created = Store::register_device( (string) $request->get_param( 'device' ), $auth['pass']->provider_ref, $auth['pass']->serial_number, $push, $auth['hash'] );
		return new \WP_REST_Response( null, $created ? 201 : 200 );
	}

	public static function unregister_device( \WP_REST_Request $request ) {
		$auth = self::authorize( $request, true );
		if ( ! is_array( $auth ) ) { return $auth; }
		Store::unregister_device( (string) $request->get_param( 'device' ), $auth['pass']->serial_number );
		return new \WP_REST_Response( null, 200 );
	}

	/** Serial numbers changed since passesUpdatedSince (no auth by Apple's design; device ids are secret). */
	public static function serials( \WP_REST_Request $request ) {
		$limits = self::limits();
		$device = (string) $request->get_param( 'device' );
		if ( self::too_many_failures( $limits ) || ! RateLimit::allow( 'wallet_ws_device_' . $device, $limits['device'], $limits['window'] ) ) { return self::rate_limited( $limits['window'] ); }
		$pass_type = (string) $request->get_param( 'pass_type' );
		$since = preg_replace( '/[^0-9]/', '', (string) $request->get_param( 'passesUpdatedSince' ) );
		$rows = Store::updated_serials( $device, $pass_type, '' === $since ? 0 : (int) substr( $since, 0, 18 ) );
		if ( ! $rows ) {
			$known = Store::has_registrations_for_device( $device, $pass_type );
			// Unknown device library ids are guesses: they count against the client address.
			if ( ! $known ) { self::count_failure( $limits ); }
			return new \WP_REST_Response( null, $known ? 204 : 404 );
		}
		$last = 0;
		$serials = array();
		foreach ( $rows as $row ) { $serials[] = $row->serial_number; $last = max( $last, (int) $row->update_tag ); }
		return new \WP_REST_Response( array( 'serialNumbers' => array_values( array_unique( $serials ) ), 'lastUpdated' => (string) $last ), 200 );
	}

	private static function pkpass_response( array $built ) {
		$modified = gmdate( 'D, d M Y H:i:s', intdiv( (int) $built['tag'], 1000 ) ) . ' GMT';
		return new RawResponse( $built['bundle'], array( 'Content-Type' => 'application/vnd.apple.pkpass', 'Last-Modified' => $modified, 'ETag' => '"' . (int) $built['tag'] . '"', 'Cache-Control' => 'no-store' ), 200 );
	}

	/**
	 * Conditional GET. An ETag (exact update tag) wins over If-Modified-Since. Update tags always
	 * move to a later second (Store::next_tag), so "tag second <= If-Modified-Since" is exact.
	 */
	private static function not_modified( \WP_REST_Request $request, $tag ) {
		$etag = trim( (string) $request->get_header( 'if_none_match' ) );
		if ( '' !== $etag ) { return trim( $etag, '"W/' ) === (string) $tag; }
		$since = strtotime( (string) $request->get_header( 'if_modified_since' ) );
		return $since && intdiv( $tag, 1000 ) <= $since;
	}

	public static function latest_pass( \WP_REST_Request $request ) {
		$auth = self::authorize( $request, true );
		if ( ! is_array( $auth ) ) { return $auth; }
		$state = Store::state( $auth['pass']->ticket_id );
		if ( $auth['current'] && $state && self::not_modified( $request, (int) $state->update_tag ) ) { return new \WP_REST_Response( null, 304 ); }
		try {
			$built = Service::apple_bundle( $auth['pass']->serial_number, self::token( $request ) );
		} catch ( \Throwable $e ) {
			Service::log( 'build_failed', $e->getMessage() );
			$built = null;
		}
		// A584: the stored code cannot be decrypted (key changed) – the device keeps its last valid pass.
		if ( $built && ! empty( $built['unavailable'] ) ) { return new \WP_REST_Response( null, 304 ); }
		return $built ? self::pkpass_response( $built ) : new \WP_Error( 'wpcap_wallet_build', __( 'Pass konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 500 ) );
	}

	public static function download( \WP_REST_Request $request ) {
		$serial = (string) $request->get_param( 'serial' );
		$pass = Store::pass_by_serial( 'apple', $serial );
		$state = $pass ? Store::state( $pass->ticket_id ) : null;
		$sig = (string) $request->get_param( 'sig' );
		$limits = self::limits();
		if ( ! RateLimit::allow( 'wallet_dl_' . RateLimit::client(), $limits['download'], $limits['window'] ) ) { return self::rate_limited( $limits['window'] ); }
		if ( ! $state || ! Service::download_sig_valid( $serial, $state, $sig ) ) {
			return self::invalid_link();
		}
		try {
			$built = Service::apple_bundle( $serial, null );
		} catch ( \Throwable $e ) {
			Service::log( 'build_failed', $e->getMessage() );
			$built = null;
		}
		if ( $built && ! empty( $built['unavailable'] ) ) {
			return new \WP_Error( 'wpcap_wallet_unavailable', __( 'Dieser Wallet-Pass kann derzeit nicht erstellt werden. Dein Ticket bleibt gültig; bitte nutze das Ticket aus der E-Mail oder wende dich an den Veranstalter.', 'pass-captain' ), array( 'status' => 503 ) );
		}
		if ( ! $built ) { return new \WP_Error( 'wpcap_wallet_build', __( 'Pass konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$response = self::pkpass_response( $built );
		$data = $response->get_data();
		$data['headers']['Content-Disposition'] = 'attachment; filename="ticket.pkpass"';
		return new \WP_REST_Response( $data, 200 );
	}

	/** Device error log; kept (truncated, without identifiers, separate from server diagnostics) for the admin page. */
	public static function log( \WP_REST_Request $request ) {
		// Unauthenticated by Apple's design: own small buffer, tight per-client and global limits.
		if ( ! Service::provider( 'apple' ) || ! RateLimit::allow( 'wallet_log_' . RateLimit::client(), 5, HOUR_IN_SECONDS ) || ! RateLimit::allow( 'wallet_log_global', 30, HOUR_IN_SECONDS ) ) { return new \WP_REST_Response( null, 200 ); }
		$logs = $request->get_param( 'logs' );
		foreach ( array_slice( is_array( $logs ) ? $logs : array(), 0, 3 ) as $line ) {
			if ( is_string( $line ) ) { Service::log( 'device', preg_replace( '/[A-Za-z0-9]{20,}/', '…', $line ), Service::DEVICE_LOG_OPTION, 10 ); }
		}
		return new \WP_REST_Response( null, 200 );
	}

	/**
	 * rest_pre_serve_request: sends binary .pkpass responses unencoded. A648: only responses of this
	 * module (RawResponse) – a data key in some other endpoint's response never switches this on.
	 */
	public static function serve_raw( $served, $result ) {
		if ( $served || ! $result instanceof RawResponse ) { return $served; }
		$raw = $result->raw();
		if ( ! headers_sent() ) {
			foreach ( $result->raw_headers() as $name => $value ) { header( $name . ': ' . $value ); }
			header( 'Content-Length: ' . strlen( $raw ) );
		}
		echo $raw; // phpcs:ignore WordPress.Security.EscapeOutput -- binary pkpass.
		return true;
	}
}
