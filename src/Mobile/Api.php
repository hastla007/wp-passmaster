<?php
/**
 * REST API for native scanner apps: /wp-json/pass-captain/v1/app/…
 *
 * Authentication: `Authorization: Bearer wpcapd_<device>_<secret>` obtained via POST /app/pair.
 * Scan outcomes (already used, revoked, wrong event …) are returned as HTTP 200 with ok=false and a
 * stable `result` code so apps can show them without treating them as transport errors.
 * See docs/MOBILE-API.md.
 */
namespace PassCaptain\Mobile;

use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Engine;
use PassCaptain\Checkin;
use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Api {
	const NS = 'pass-captain/v1';
	/** 1.2 (A687): /app/info, /app/scan/batch, version headers. */
	const VERSION = '1.2';

	/** A687: header with the app's version (checked against the minimum app version). */
	const APP_VERSION_HEADER = 'x-wpcap-app-version';

	/** A687: header with the core version the app needs at least. */
	const MIN_CORE_HEADER = 'x-wpcap-min-core';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$auth = array( __CLASS__, 'authenticate' );
		register_rest_route( self::NS, '/app/pair', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pair' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/app/info', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'info' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/app/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/occurrences', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'occurrences' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/occurrences/(?P<occurrence_id>\d+)/stats', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stats' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/validate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'validate' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/check-in', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/check-out', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_out' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/undo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'undo' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sync' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/batch', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'batch' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/search', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'search' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/logout', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'logout' ), 'permission_callback' => $auth ) );
	}

	/** permission_callback for all authenticated routes. */
	public static function authenticate( \WP_REST_Request $request ) {
		$gate = self::version_gate( $request );
		if ( is_wp_error( $gate ) ) { return $gate; }
		$device = Devices::authenticate( $request );
		return is_wp_error( $device ) ? $device : true;
	}

	/** A687: version of this plugin (the "core" an app talks to). */
	public static function core_version() {
		return defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : '';
	}

	/**
	 * A687: oldest app version the site accepts ('' = any). Filter `wpcap_app_min_version`, e.g. after a
	 * security fix in the app; apps that send an older X-WPCAP-App-Version get HTTP 426.
	 */
	public static function min_app_version() {
		$min = apply_filters( 'wpcap_app_min_version', '' );
		return is_string( $min ) && preg_match( '/^\d+(\.\d+){0,3}([.-][0-9A-Za-z.-]+)?$/', trim( $min ) ) ? trim( $min ) : '';
	}

	/**
	 * A687: version checks of a request – the app's version (header X-WPCAP-App-Version, on pairing also the
	 * `app_version` field) against min_app_version() (HTTP 426 wpcap_app_outdated), and the core version the
	 * app needs (header X-WPCAP-Min-Core) against this plugin (HTTP 409 wpcap_core_outdated, so the app
	 * can tell the staff to update the site instead of failing on missing routes). Without headers: true.
	 *
	 * @return true|\WP_Error
	 */
	public static function version_gate( \WP_REST_Request $request ) {
		$app = trim( \PassCaptain\Http::str( $request->get_header( self::APP_VERSION_HEADER ) ) );
		if ( '' === $app ) { $app = trim( \PassCaptain\Http::str( $request->get_param( 'app_version' ) ) ); }
		$min = self::min_app_version();
		if ( self::version_below( $app, $min ) ) {
			/* translators: 1: app version, 2: minimum app version */
			return new \WP_Error( 'wpcap_app_outdated', sprintf( __( 'Diese App-Version (%1$s) wird nicht mehr unterstützt. Bitte mindestens Version %2$s installieren.', 'pass-captain' ), $app, $min ), array( 'status' => 426, 'min_app_version' => $min ) );
		}
		$need = trim( \PassCaptain\Http::str( $request->get_header( self::MIN_CORE_HEADER ) ) );
		$core = self::core_version();
		if ( self::version_below( $core, $need ) ) {
			/* translators: 1: required version, 2: installed version */
			return new \WP_Error( 'wpcap_core_outdated', sprintf( __( 'Die App braucht Pass Captain %1$s oder neuer; auf der Website ist %2$s installiert. Bitte das Plugin aktualisieren.', 'pass-captain' ), $need, $core ), array( 'status' => 409, 'core_version' => $core, 'api_version' => self::VERSION ) );
		}
		return true;
	}

	/** A687: whether version $have is older than $need; false when either is empty or no version number. */
	public static function version_below( $have, $need ) {
		$valid = static function ( $v ) { return 1 === preg_match( '/^\d+(\.\d+){0,3}([.-][0-9A-Za-z.-]+)?$/', (string) $v ); };
		return $valid( $have ) && $valid( $need ) && version_compare( (string) $have, (string) $need, '<' );
	}

	/** A687: feature flags of this site (also in /app/me). */
	public static function features() {
		return array( 'undo' => true, 'undo_reason_required' => true, 'search' => true, 'offline_sync' => true, 'check_out' => true, 'areas' => true, 'batch' => true, 'batch_max' => \PassCaptain\Admission\BulkCheckin::max(), 'attendee_name' => (bool) \PassCaptain\Settings::get( 'scanner_show_attendee' ) );
	}

	/**
	 * A687: GET /app/info (no token): API and core version, the minimum app version and the feature flags,
	 * so an app can check compatibility before pairing. Contains no data of the site's events.
	 */
	public static function info( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		return rest_ensure_response( array( 'api_version' => self::VERSION, 'core_version' => self::core_version(), 'min_app_version' => self::min_app_version(), 'features' => self::features(), 'server_time' => gmdate( 'c', \PassCaptain\Clock::now() ) ) );
	}

	/**
	 * Limits of POST /app/pair per client address (A460): `failures` failed attempts (unknown, used or
	 * expired code) and `requests` attempts in total per `window`. Only failures have a small budget:
	 * at a venue all devices are usually paired from one public address (NAT). Filter
	 * `wpcap_app_pair_rate_limits`.
	 *
	 * @return array{failures:int,requests:int,window:int}
	 */
	public static function pair_limits() {
		$defaults = array( 'failures' => 10, 'requests' => 200, 'window' => HOUR_IN_SECONDS );
		$limits = (array) apply_filters( 'wpcap_app_pair_rate_limits', $defaults );
		$out = array();
		foreach ( $defaults as $key => $default ) { $out[ $key ] = max( 1, (int) ( $limits[ $key ] ?? $default ) ); }
		return $out;
	}

	public static function pair( \WP_REST_Request $request ) {
		// A687: outdated apps are refused before a pairing code is used.
		$gate = self::version_gate( $request );
		if ( is_wp_error( $gate ) ) { return $gate; }
		$limits = self::pair_limits();
		$client = RateLimit::client();
		// Failures are checked before and counted after the attempt; successful pairings only use the large budget.
		if ( RateLimit::hits( 'pair_fail_' . $client, $limits['window'] ) >= $limits['failures'] || ! RateLimit::allow( 'pair_all_' . $client, $limits['requests'], $limits['window'] ) ) {
			return new \WP_Error( 'wpcap_pair_rate', __( 'Zu viele Kopplungsversuche. Bitte später erneut versuchen.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$result = Devices::pair( $request->get_param( 'code' ), \PassCaptain\Security\Input::text( $request->get_param( 'device_label' ) ), \PassCaptain\Security\Input::text( $request->get_param( 'platform' ) ), \PassCaptain\Security\Input::text( $request->get_param( 'app_version' ) ) );
		if ( is_wp_error( $result ) ) {
			RateLimit::allow( 'pair_fail_' . $client, $limits['failures'], $limits['window'] );
			return $result;
		}
		return new \WP_REST_Response( $result, 201 );
	}

	public static function me( \WP_REST_Request $request ) {
		$device = Devices::current();
		$user = wp_get_current_user();
		return rest_ensure_response( array(
			'api_version' => self::VERSION,
			'core_version' => self::core_version(),
			'min_app_version' => self::min_app_version(),
			'user' => array( 'display_name' => $user->display_name ),
			'device' => array( 'id' => $device->public_id, 'label' => $device->label, 'expires_at' => $device->expires_at ? gmdate( 'c', strtotime( $device->expires_at . ' UTC' ) ) : null ),
			'event_ids' => Devices::event_ids( $device ),
			'area' => Devices::area_payload( Devices::area_id( $device ) ),
			'features' => self::features(),
			// What this device's user may do (capabilities: admission, pass redemption, undo are separate).
			'permissions' => array( 'check_in' => current_user_can( 'wpcap_checkin' ), 'redeem_passes' => current_user_can( \PassCaptain\Capabilities::REDEEM_PASSES ), 'undo' => current_user_can( \PassCaptain\Capabilities::UNDO_CHECKIN ) ),
			'server_time' => gmdate( 'c' ),
		) );
	}

	/** Occurrences of the device's events that are running, upcoming or ended less than a day ago (max. 100, A535). */
	public static function occurrences( \WP_REST_Request $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = Devices::event_ids( Devices::current() );
		if ( ! $ids ) { return rest_ensure_response( array( 'occurrences' => array() ) ); }
		$ids = array_values( array_map( 'absint', $ids ) );
		// A535: running, upcoming or ended less than a day ago (effective end of multi-day occurrences).
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT o.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.ends_at,o.timezone,o.location,o.status FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE ' . \PassCaptain\Occurrence::end_sql( 'o' ) . ' >= %s AND o.event_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY o.starts_at LIMIT 100', array_merge( array( $p . 'wpcap_occurrences', $p . 'wpcap_events', \PassCaptain\Clock::mysql( -DAY_IN_SECONDS ) ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Occurrence::end_sql() returns a static SQL expression (sanitized alias, integer duration).
		$out = array();
		$areas = array();
		foreach ( $rows as $row ) {
			if ( ! \PassCaptain\Access::can_access_occurrence( (int) $row->occurrence_id, 'wpcap_checkin', (int) $row->event_id ) ) { continue; }
			if ( ! isset( $areas[ $row->event_id ] ) ) {
				$areas[ $row->event_id ] = array_map( static function ( $a ) { return array( 'id' => (int) $a->area_id, 'name' => $a->name ); }, Areas::for_event( (int) $row->event_id ) );
			}
			$doors = \PassCaptain\DoorsOpen::for_occurrence( (int) $row->occurrence_id );
			$out[] = array( 'occurrence_id' => (int) $row->occurrence_id, 'event_id' => (int) $row->event_id, 'event' => $row->event_name, 'starts_at' => gmdate( 'c', strtotime( $row->starts_at . ' UTC' ) ), 'ends_at' => $row->ends_at ? gmdate( 'c', strtotime( $row->ends_at . ' UTC' ) ) : null, 'timezone' => $row->timezone, 'location' => $row->location, 'status' => $row->status, 'areas' => $areas[ $row->event_id ], 'doors_open_at' => null === $doors ? null : gmdate( 'c', strtotime( $doors . ' UTC' ) ) );
		}
		return rest_ensure_response( array( 'occurrences' => $out ) );
	}

	/** Admission counters for one occurrence. */
	public static function stats( \WP_REST_Request $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_id,event_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', absint( $request['occurrence_id'] ) ) );
		if ( ! $occ || ! in_array( (int) $occ->event_id, Devices::event_ids( Devices::current() ), true ) || ! \PassCaptain\Access::can_access_occurrence( (int) $occ->occurrence_id, 'wpcap_checkin', (int) $occ->event_id ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		// Only this device's own counter is read per call (KEY device_event_result, A440).
		$device_scans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE device_id=%d AND event_id=%d AND result='checked_in'", (int) Devices::current()->device_id, (int) $occ->event_id ) );
		return rest_ensure_response( array_merge( self::occurrence_stats( $occ ), array( 'checked_in_by_this_device' => $device_scans, 'device_area_id' => Devices::area_id( Devices::current() ) ) ) );
	}

	/**
	 * Occurrence-wide counters of the stats endpoint (tickets, admitted, presence, areas). Every
	 * device of an event polls them, so they are kept in the object cache for a few seconds
	 * (A440; filter `wpcap_scanner_stats_cache_ttl`, seconds, default 10, 0 = off, max. 60). Only
	 * shared with a persistent object cache; without one every call counts.
	 *
	 * @param object $occ Occurrence row (occurrence_id, event_id).
	 * @return array
	 */
	private static function occurrence_stats( $occ ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = (int) $occ->occurrence_id;
		$ttl = max( 0, min( 60, (int) apply_filters( 'wpcap_scanner_stats_cache_ttl', 10, $occurrence_id ) ) );
		$key = 'scanner_stats:' . $wpdb->prefix . $occurrence_id;
		if ( $ttl && function_exists( 'wp_cache_get' ) ) {
			$found = false;
			$cached = wp_cache_get( $key, 'wpcap', false, $found );
			if ( $found && is_array( $cached ) ) { return $cached; }
		}
		$counts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT t.status, COUNT(*) AS n FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d GROUP BY t.status', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $occurrence_id ) ) as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}
		$valid = $counts['valid'] ?? 0;
		$checked = $counts['checked_in'] ?? 0;
		$presence = Engine::presence( $occurrence_id );
		$movement = Engine::movement( $occurrence_id );
		$by_area = array();
		foreach ( Areas::for_event( (int) $occ->event_id, true ) as $area ) {
			$id = (int) $area->area_id;
			$by_area[] = array( 'area_id' => $id, 'name' => $area->name, 'present' => $presence['by_area'][ $id ] ?? 0, 'entries' => $movement['by_area'][ $id ]['entries'] ?? 0, 'checked_out' => $movement['by_area'][ $id ]['checked_out'] ?? 0 );
		}
		// A546: admitted at least once (also repeatable tickets, whose status stays valid) – the counter of the PWA.
		$desk = \PassCaptain\ScannerDesk::counts( $occurrence_id );
		$stats = array( 'occurrence_id' => $occurrence_id, 'tickets_total' => $valid + $checked, 'checked_in' => $checked, 'remaining' => $valid, 'admitted' => $desk ? $desk['admitted'] : $checked, 'admission_total' => $desk ? $desk['total'] : $valid + $checked, 'present' => $presence['present'], 'entries' => $movement['entries'], 'checked_out_total' => $movement['checked_out'], 'by_area' => $by_area );
		if ( $ttl && function_exists( 'wp_cache_set' ) ) { wp_cache_set( $key, $stats, 'wpcap', $ttl ); }
		return $stats;
	}

	public static function validate( \WP_REST_Request $request ) {
		$result = Checkin::preview( $request->get_param( 'code' ), self::context( $request ) );
		if ( is_wp_error( $result ) ) { return self::scan_response( $result ); }
		// ok=true only when the ticket would be admitted now.
		return rest_ensure_response( array_merge( $result, array( 'ok' => 'valid' === $result['status'], 'result' => (string) $result['status'] ) ) );
	}

	public static function check_in( \WP_REST_Request $request ) {
		$key = sanitize_text_field( \PassCaptain\Security\Input::text( $request->get_param( 'idempotency_key' ) ) );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$context = self::context( $request );
		$context['idempotency_key'] = 'app:' . $key;
		$context['source'] = 'app';
		// A544: a search result is checked in by its public id (same rules as a scan, `via: manual`).
		if ( '' === \PassCaptain\Http::str( $request->get_param( 'code' ) ) && '' !== \PassCaptain\Http::str( $request->get_param( 'ticket_public_id' ) ) ) {
			return self::scan_response( Checkin::perform_ticket( \PassCaptain\ScannerDesk::ticket_id_of( $request->get_param( 'ticket_public_id' ) ), $context ) );
		}
		return self::scan_response( Checkin::perform( $request->get_param( 'code' ), $context ) );
	}

	/** Manual search at the door (A544): order number, attendee name/e-mail as the Pii rules allow. */
	public static function search( \WP_REST_Request $request ) {
		$context = self::context( $request );
		$result = \PassCaptain\ScannerDesk::search( $request->get_param( 'q' ), $context );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return 429 === (int) ( $data['status'] ?? 0 ) ? $result : rest_ensure_response( array( 'ok' => false, 'result' => (string) ( $data['result'] ?? 'error' ), 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ok' => true ) + $result );
	}

	/** Check-out (person leaves); for presence counting and re-entry tickets. */
	public static function check_out( \WP_REST_Request $request ) {
		$key = sanitize_text_field( \PassCaptain\Security\Input::text( $request->get_param( 'idempotency_key' ) ) );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$context = self::context( $request );
		$context['idempotency_key'] = 'app:' . $key;
		$context['source'] = 'app';
		return self::scan_response( Checkin::check_out( $request->get_param( 'code' ), $context ) );
	}

	/**
	 * Takes back the latest admission of a ticket (by `code` or `ticket_public_id`) with a required
	 * `reason` (A547) and a required idempotency_key (a retried request never removes a second entry).
	 * The device's events, the area binding of device and user and the void check apply
	 * (Checkin::undo_guard(), A590); staff without wpcap_manage_orders may only undo their own scans
	 * (same user or this device) within the undo window (setting undo_window_minutes), roles listed in
	 * undo_roles any admission of their events (Checkin::undo_denied()). The undo is logged with the device.
	 */
	public static function undo( \WP_REST_Request $request ) {
		$key = sanitize_text_field( \PassCaptain\Security\Input::text( $request->get_param( 'idempotency_key' ) ) );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$context = self::context( $request );
		$context['source'] = 'app';
		$context['idempotency_key'] = 'app:' . $key;
		$result = \PassCaptain\ScannerDesk::undo( array( 'code' => $request->get_param( 'code' ), 'ticket_public_id' => $request->get_param( 'ticket_public_id' ), 'reason' => \PassCaptain\Security\Input::text( $request->get_param( 'reason' ) ), 'idempotency_key' => 'app:' . $key, 'device_id' => (int) $context['device_id'] ), $context );
		return is_wp_error( $result ) ? self::scan_response( $result ) : rest_ensure_response( $result );
	}

	/**
	 * Uploads scans made offline (max. 200). Each scan carries its own idempotency key, so a
	 * repeated upload is safe. A ticket admitted elsewhere in the meantime is reported as conflict.
	 */
	public static function sync( \WP_REST_Request $request ) {
		$scans = $request->get_param( 'scans' );
		if ( ! is_array( $scans ) || ! $scans || count( $scans ) > 200 ) {
			return new \WP_Error( 'wpcap_sync_invalid', __( 'Es müssen 1 bis 200 Scans übertragen werden.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// A609: scan times need a UTC offset.
		$invalid_time = Checkin::scanned_at_error( $scans );
		if ( $invalid_time ) { return $invalid_time; }
		$results = array();
		$device = Devices::current();
		Areas::touch_device_sync( (int) $device->device_id, count( $scans ) );
		foreach ( $scans as $scan ) {
			$scan = is_array( $scan ) ? $scan : array();
			$key = sanitize_text_field( (string) ( $scan['idempotency_key'] ?? '' ) );
			if ( '' === $key ) {
				$results[] = array( 'ok' => false, 'result' => 'invalid', 'idempotency_key' => '' );
				continue;
			}
			$context = array( 'event_ids' => Devices::event_ids( $device ), 'device_id' => (int) $device->device_id, 'area_id' => Devices::area_id( $device ) ?: absint( $scan['area_id'] ?? 0 ), 'idempotency_key' => 'app:' . $key, 'source' => 'offline' );
			$scanned = Checkin::parse_scanned_at( $scan['scanned_at'] ?? null );
			if ( $scanned && $scanned <= \PassCaptain\Clock::now() + 300 ) { $context['scanned_at'] = gmdate( 'Y-m-d H:i:s', $scanned ); }
			$out = 'out' === ( $scan['direction'] ?? 'in' );
			// A544: an offline admission from the search of the PWA has no code, only the package hash
			// (full sha256, or the 32-hex prefix of a compact package – 128 bit identify the ticket).
			$hash = strtolower( \PassCaptain\Http::str( $scan['ticket_hash'] ?? '' ) );
			if ( ! $out && '' === \PassCaptain\Http::str( $scan['code'] ?? '' ) && preg_match( '/^(?:[a-f0-9]{64}|[a-f0-9]{32})$/', $hash ) ) {
				global $wpdb;
				$tickets = 64 === strlen( $hash )
					? $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE token_hash=%s', $wpdb->prefix . 'wpcap_tickets', $hash ) )
					: $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE token_hash LIKE %s LIMIT 2', $wpdb->prefix . 'wpcap_tickets', $wpdb->esc_like( $hash ) . '%' ) );
				$outcome = '' !== (string) $wpdb->last_error
					? new \WP_Error( 'wpcap_checkin_retry', __( 'Der Scan konnte gerade nicht gespeichert werden. Bitte erneut scannen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'result' => 'retry' ) )
					: Checkin::perform_ticket( 1 === count( $tickets ) ? (int) $tickets[0] : 0, $context );
			} else {
				$outcome = $out ? Checkin::check_out( $scan['code'] ?? '', $context ) : Checkin::perform( $scan['code'] ?? '', $context );
			}
			$row = self::scan_payload( $outcome );
			$row['idempotency_key'] = $key;
			$row['direction'] = $out ? 'out' : 'in';
			// A345: nothing was booked (database busy, lost connection). The app keeps the scan and
			// uploads it again instead of treating the admission as synchronised.
			if ( is_wp_error( $outcome ) && ( \PassCaptain\Errors::is_busy( $outcome ) || ! empty( ( (array) $outcome->get_error_data() )['retryable'] ) ) ) {
				$row['result'] = 'retry';
				$row['retryable'] = true;
				$row['conflict'] = false;
				$row['double_admission'] = false;
				$results[] = $row;
				continue;
			}
			// Offline the device already let the person in; a rejection here is an admission conflict
			// (recorded for review under "Einlass-Konflikte").
			$data = is_wp_error( $outcome ) ? (array) $outcome->get_error_data() : (array) $outcome;
			// Also ok=true scans can be conflicts: backdated admissions that would be refused now.
			$row['conflict'] = ! empty( $data['conflict_id'] );
			$row['double_admission'] = $row['conflict'] && in_array( $row['result'], Checkin::REJECTED_ADMISSION, true );
			if ( $row['conflict'] ) { $row['conflict_id'] = (int) $data['conflict_id']; }
			$results[] = $row;
		}
		return rest_ensure_response( array( 'results' => $results, 'offline_conflicts_are_possible' => true ) );
	}

	/**
	 * A687: POST /app/scan/batch – checks several tickets in or out at once (e.g. a group at the door):
	 * `tickets` (public ids from the search, 1 to BulkCheckin::max()), `direction` (in|out), `batch_key`
	 * (8–64 characters, the idempotency key of the whole batch) and optional `occurrence_id`/`area_id`. Every
	 * ticket follows the rules of a single scan; repeating the same batch_key never admits a ticket twice
	 * (booked tickets answer `replayed`), so an app retries after a timeout with the same key. Unknown
	 * public ids are reported as `unknown`.
	 */
	public static function batch( \WP_REST_Request $request ) {
		$tickets = $request->get_param( 'tickets' );
		if ( ! is_array( $tickets ) ) {
			return new \WP_Error( 'wpcap_bulk_size', __( 'tickets muss eine Liste von Ticket-IDs (public id) sein.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$tickets = array_slice( array_values( array_unique( array_map( static function ( $t ) { return strtolower( \PassCaptain\Http::str( $t ) ); }, $tickets ) ) ), 0, \PassCaptain\Admission\BulkCheckin::max() + 1 );
		$ids = array();
		$unknown = array();
		foreach ( $tickets as $public ) {
			$id = \PassCaptain\ScannerDesk::ticket_id_of( $public );
			if ( $id ) { $ids[ $id ] = $public; } else { $unknown[] = $public; }
		}
		$context = self::context( $request );
		$context['source'] = 'app';
		$context['key_prefix'] = 'app:';
		$report = $ids ? \PassCaptain\Admission\BulkCheckin::run( array_keys( $ids ), \PassCaptain\Http::str( $request->get_param( 'direction' ) ), $request->get_param( 'batch_key' ), $context ) : null;
		if ( is_wp_error( $report ) ) { return $report; }
		if ( ! $ids && ! \PassCaptain\Admission\BulkCheckin::key( $request->get_param( 'batch_key' ) ) ) {
			return new \WP_Error( 'wpcap_bulk_key', __( 'Für den Sammellauf fehlt ein gültiger Schlüssel (8–64 Zeichen).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$results = array();
		foreach ( $report ? $report['results'] : array() as $row ) {
			$public = $ids[ (int) $row['ticket_id'] ] ?? '';
			unset( $row['ticket_id'] );
			$row['public_id'] = $public;
			$results[] = $row;
		}
		$counts = $report ? $report['counts'] : array( 'done' => 0, 'already' => 0, 'refused' => 0, 'retry' => 0 );
		foreach ( $unknown as $public ) {
			$results[] = array( 'public_id' => $public, 'ok' => false, 'result' => 'unknown', 'message' => __( 'Ticket nicht gefunden.', 'pass-captain' ), 'replayed' => false, 'retryable' => false );
			++$counts['refused'];
		}
		return rest_ensure_response( array( 'ok' => 0 === $counts['refused'] + $counts['retry'], 'batch_key' => (string) \PassCaptain\Admission\BulkCheckin::key( $request->get_param( 'batch_key' ) ), 'direction' => 'out' === \PassCaptain\Http::str( $request->get_param( 'direction' ) ) ? 'out' : 'in', 'total' => count( $results ), 'counts' => $counts, 'complete' => 0 === $counts['retry'], 'results' => $results ) );
	}

	public static function logout( \WP_REST_Request $request ) {
		$result = Devices::revoke( (int) Devices::current()->device_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	private static function context( \WP_REST_Request $request ) {
		$device = Devices::current();
		return array( 'event_ids' => Devices::event_ids( $device ), 'device_id' => (int) $device->device_id, 'occurrence_id' => absint( $request->get_param( 'occurrence_id' ) ), 'area_id' => Devices::area_id( $device ) ?: absint( $request->get_param( 'area_id' ) ) );
	}

	/**
	 * Scan outcomes as 200 responses; authentication/validation problems stay HTTP errors. A transient
	 * database error (retryable, nothing booked) is HTTP 503, so apps treat it like an outage and fall
	 * back to their offline decision instead of showing a rejection.
	 */
	private static function scan_response( $outcome ) {
		if ( is_wp_error( $outcome ) ) {
			$data = (array) $outcome->get_error_data();
			if ( in_array( (int) ( $data['status'] ?? 0 ), array( 401, 429 ), true ) ) { return $outcome; }
			if ( ! empty( $data['retryable'] ) ) {
				return new \WP_Error( $outcome->get_error_code(), $outcome->get_error_message(), array( 'status' => 503, 'retryable' => true, 'result' => 'retry' ) );
			}
		}
		return rest_ensure_response( self::scan_payload( $outcome ) );
	}

	private static function scan_payload( $outcome ) {
		if ( is_wp_error( $outcome ) ) {
			$data = (array) $outcome->get_error_data();
			$retry = \PassCaptain\Errors::is_busy( $outcome );
			return array_filter( array( 'ok' => false, 'result' => (string) ( $data['result'] ?? $data['ticket_status'] ?? ( $retry ? 'retry' : 'error' ) ), 'code' => $outcome->get_error_code(), 'message' => $outcome->get_error_message(), 'event' => $data['event'] ?? null, 'ticket' => $data['ticket'] ?? null, 'retryable' => $retry ? true : null ) + array_intersect_key( $data, array_flip( Checkin::CLIENT_ERROR_KEYS ) ), static function ( $v ) { return null !== $v; } );
		}
		return array_merge( array( 'ok' => true, 'result' => (string) $outcome['status'] ), $outcome );
	}
}
