<?php
/**
 * REST endpoints of the offline scanner for paired devices (device bearer token of the mobile API):
 *
 *   GET /app/offline/keys                            – trusted Ed25519 public keys (packages: keys, signed QR codes: qr_keys)
 *   GET /app/offline/occurrences                     – occurrences this device may hold packages for
 *   GET /app/offline/package?occurrence_id=&since=&format=2 – signed full/delta package (format 2: compact; gzip when accepted)
 *   GET /app/offline/i18n?locale=fr-CH,fr,en         – public: locale + translated UI texts of the PWA (+ brand)
 *   GET /app/offline/manifest                        – public: web app manifest with the brand name (white label)
 *
 * Also extends existing app responses (rest_request_after_callbacks): /app/pair returns the public
 * keys ("offline"), /app/me announces the feature, /app/scan/sync applies the event's offline rules.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Access;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;
use PassCaptain\Security\SignedQr;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest {
	public static function routes() {
		$auth = array( Api::class, 'authenticate' );
		register_rest_route( Api::NS, '/app/offline/keys', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'keys' ), 'permission_callback' => $auth ) );
		register_rest_route( Api::NS, '/app/offline/occurrences', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'occurrences' ), 'permission_callback' => $auth ) );
		register_rest_route( Api::NS, '/app/offline/package', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'package' ), 'permission_callback' => $auth ) );
		// Public on purpose: the pairing screen needs its texts before a device token exists; the
		// table only contains UI strings.
		register_rest_route( Api::NS, '/app/offline/i18n', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'i18n' ), 'permission_callback' => '__return_true' ) );
		// Public on purpose: web app manifest with the brand name (white label); contains no data.
		register_rest_route( Api::NS, '/app/offline/manifest', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'manifest' ), 'permission_callback' => '__return_true' ) );
	}

	/**
	 * Web app manifest of the PWA with the brand name (wp_pass_captain_brand_name_v1, context
	 * offline_scanner). The app links it instead of the static manifest.json only when a brand is set.
	 */
	public static function manifest() {
		$name = \PassCaptain\Brand::name( 'offline_scanner' );
		$base = ( defined( 'WPCAP_URL' ) ? WPCAP_URL : '' ) . 'assets/offline-scanner/';
		$short = \PassCaptain\Brand::DEFAULT_NAME === $name ? 'PC Scanner' : mb_substr( $name, 0, 12 );
		$manifest = array(
			'name' => $name . ' Scanner',
			'short_name' => $short,
			'start_url' => AdminPage::app_url(),
			'scope' => $base,
			'display' => 'standalone',
			'orientation' => 'portrait',
			'background_color' => '#f4f6f8',
			'theme_color' => '#112e51',
			'icons' => array(
				array( 'src' => $base . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png' ),
				array( 'src' => $base . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png' ),
				array( 'src' => $base . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
				array( 'src' => $base . 'icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml' ),
			),
		);
		$response = rest_ensure_response( $manifest );
		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'Content-Type', 'application/manifest+json; charset=utf-8' );
			$response->header( 'Cache-Control', 'no-cache' );
		}
		return $response;
	}

	public static function keys( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'keys' => Keys::public_keys(), 'qr_keys' => SignedQr::public_keys(), 'server_time' => gmdate( 'c', \PassCaptain\Clock::real() ) ) );
	}

	/**
	 * Locale and translated string table of the PWA (see I18n). Optional `locale`: the device's
	 * preferred languages (comma separated BCP 47 tags); the first supported one is served.
	 */
	public static function i18n( \WP_REST_Request $request ) {
		$requested = \PassCaptain\Text::cut( (string) preg_replace( '/[^A-Za-z0-9_,;=.\- ]/', '', (string) $request->get_param( 'locale' ) ), 200 );
		$response = rest_ensure_response( I18n::table( $requested ) );
		if ( method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'no-cache' ); }
		return $response;
	}

	public static function occurrences( \WP_REST_Request $request ) {
		$device = Devices::current();
		$out = array();
		foreach ( Assignments::occurrences_for_device( $device ) as $row ) {
			if ( ! Access::can_access_occurrence( (int) $row->occurrence_id, 'wpcap_checkin', (int) $row->event_id ) ) { continue; }
			$rules = Rules::get( (int) $row->event_id );
			$out[] = array(
				'occurrence_id' => (int) $row->occurrence_id,
				'event_id' => (int) $row->event_id,
				'event' => (string) $row->event_name,
				'starts_at' => gmdate( 'c', strtotime( $row->starts_at . ' UTC' ) ),
				'ends_at' => $row->ends_at ? gmdate( 'c', strtotime( $row->ends_at . ' UTC' ) ) : null,
				'timezone' => (string) $row->timezone,
				'status' => (string) $row->status,
				'rules' => array( 'max_offline_hours' => $rules['max_offline_hours'], 'duplicate_policy' => $rules['duplicate_policy'], 'attendee_names' => Rules::attendee_names( $rules ) ),
			);
		}
		return rest_ensure_response( array( 'occurrences' => $out, 'device_id' => (string) $device->public_id, 'server_time' => gmdate( 'c', \PassCaptain\Clock::real() ) ) );
	}

	/**
	 * Signed package. Errors: 404 unknown occurrence, 403 wpcap_offline_disabled (event not opted in),
	 * 403 wpcap_offline_not_assigned (device not assigned / not paired for the event / no check-in access).
	 */
	public static function package( \WP_REST_Request $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$device = Devices::current();
		$occurrence = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $request->get_param( 'occurrence_id' ) ) ) );
		if ( ! $occurrence ) {
			return new \WP_Error( 'wpcap_offline_occurrence', __( 'Termin nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$event_id = (int) $occurrence->event_id;
		if ( ! Rules::enabled( $event_id ) ) {
			return new \WP_Error( 'wpcap_offline_disabled', __( 'Offline-Scannen ist für dieses Event nicht freigegeben.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$assignment = Assignments::find( (int) $device->device_id, $event_id, (int) $occurrence->occurrence_id );
		if ( ! $assignment || ! in_array( $event_id, Devices::event_ids( $device ), true ) || ! Access::can_access_occurrence( (int) $occurrence->occurrence_id, 'wpcap_checkin', $event_id ) ) {
			return new \WP_Error( 'wpcap_offline_not_assigned', __( 'Dieses Gerät ist für diesen Termin nicht zum Offline-Scannen zugewiesen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$since = $request->get_param( 'since' );
		$body = Package::build( $device, $occurrence, is_string( $since ) ? $since : null, absint( $request->get_param( 'format' ) ) );
		Assignments::touch( $assignment );
		$response = rest_ensure_response( $body );
		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'Vary', 'Accept-Encoding' );
		}
		return $response;
	}

	/** Packages from this size on are sent gzip-compressed when the client accepts it. */
	const GZIP_MIN_BYTES = 1024;

	/**
	 * rest_pre_serve_request: sends the package response gzip-compressed (Content-Encoding: gzip) when
	 * the client accepts gzip, zlib is available and the server does not compress already. 50,000
	 * tickets shrink from ~4.8 MB to ~1.8 MB (v1) – the difference between a timeout and a download on
	 * a weak venue network. The signed payload is untouched (the browser inflates transparently).
	 *
	 * @param bool                   $served  Whether the request was already served.
	 * @param \WP_REST_Response|mixed $result  Response.
	 * @param \WP_REST_Request|mixed  $request Request.
	 * @return bool
	 */
	public static function serve_gzip( $served, $result = null, $request = null ) {
		if ( $served || ! $result instanceof \WP_REST_Response || ! is_object( $request ) ) { return $served; }
		if ( '/' . Api::NS . '/app/offline/package' !== self::route( $request ) || (int) $result->get_status() >= 300 ) { return $served; }
		$accept = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'accept_encoding' ) : '';
		if ( '' === $accept ) { $accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) : ''; }
		if ( ! preg_match( '/\bgzip\b(?!\s*;\s*q=0(?:\.0*)?(?![\d.]))/i', $accept ) || ! self::can_gzip() ) { return $served; }
		$json = (string) wp_json_encode( $result->get_data() );
		if ( strlen( $json ) < self::GZIP_MIN_BYTES ) { return $served; }
		$gz = gzencode( $json, 6 );
		if ( false === $gz ) { return $served; }
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Encoding: gzip' );
			header( 'Vary: Accept-Encoding' );
			header( 'Content-Length: ' . strlen( $gz ) );
		}
		echo $gz; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gzip-compressed JSON body.
		return true;
	}

	/** zlib is available and PHP does not compress the output itself (no double encoding). */
	private static function can_gzip() {
		if ( ! function_exists( 'gzencode' ) ) { return false; }
		if ( filter_var( ini_get( 'zlib.output_compression' ), FILTER_VALIDATE_BOOLEAN ) ) { return false; }
		return ! in_array( 'ob_gzhandler', ob_list_handlers(), true );
	}

	private static function route( $request ) {
		if ( method_exists( $request, 'get_route' ) ) { return (string) $request->get_route(); }
		return (string) ( $request->route ?? '' );
	}

	/** Extends app responses; see class doc. */
	public static function after_callbacks( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! is_object( $request ) ) { return $response; }
		$route = self::route( $request );
		$base = '/' . Api::NS . '/app/';
		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $response; }
		if ( $base . 'pair' === $route && ! empty( $data['token'] ) ) {
			$data['offline'] = array( 'keys' => Keys::public_keys(), 'qr_keys' => SignedQr::public_keys(), 'package_format' => Package::VERSION, 'package_formats' => array( Package::VERSION, Package::VERSION_COMPACT ) );
		} elseif ( $base . 'me' === $route && isset( $data['features'] ) ) {
			$data['features']['offline_packages'] = true;
		} elseif ( $base . 'scan/sync' === $route && isset( $data['results'] ) && Devices::current() ) {
			$scans = $request->get_param( 'scans' );
			$data['results'] = Sync::apply( $data['results'], is_array( $scans ) ? $scans : array(), Devices::current() );
		} else {
			return $response;
		}
		if ( method_exists( $response, 'set_data' ) ) { $response->set_data( $data ); } else { $response->data = $data; }
		return $response;
	}
}
