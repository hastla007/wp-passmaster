<?php
/**
 * REST API for native scanner apps: /wp-json/wp-pass-captain/v1/app/…
 *
 * Authentication: `Authorization: Bearer wpcapd_<device>_<secret>` obtained via POST /app/pair.
 * Scan outcomes (already used, revoked, wrong event …) are returned as HTTP 200 with ok=false and a
 * stable `result` code so apps can show them without treating them as transport errors.
 * See docs/MOBILE-API.md.
 */
namespace PassCaptain\Mobile;

use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;
use PassCaptain\Checkin;
use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Api {
	const NS = 'wp-pass-captain/v1';
	const VERSION = '1.1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$auth = array( __CLASS__, 'authenticate' );
		register_rest_route( self::NS, '/app/pair', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pair' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/app/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/occurrences', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'occurrences' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/occurrences/(?P<occurrence_id>\d+)/stats', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stats' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/validate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'validate' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/check-in', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/check-out', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_out' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/undo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'undo' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/scan/sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sync' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/app/logout', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'logout' ), 'permission_callback' => $auth ) );
	}

	/** permission_callback for all authenticated routes. */
	public static function authenticate( \WP_REST_Request $request ) {
		$device = Devices::authenticate( $request );
		return is_wp_error( $device ) ? $device : true;
	}

	public static function pair( \WP_REST_Request $request ) {
		if ( ! RateLimit::allow( 'pair_' . RateLimit::client(), 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_pair_rate', __( 'Zu viele Kopplungsversuche. Bitte später erneut versuchen.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$result = Devices::pair( $request->get_param( 'code' ), (string) $request->get_param( 'device_label' ), (string) $request->get_param( 'platform' ), (string) $request->get_param( 'app_version' ) );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	public static function me( \WP_REST_Request $request ) {
		$device = Devices::current();
		$user = wp_get_current_user();
		return rest_ensure_response( array(
			'api_version' => self::VERSION,
			'user' => array( 'display_name' => $user->display_name ),
			'device' => array( 'id' => $device->public_id, 'label' => $device->label, 'expires_at' => $device->expires_at ? gmdate( 'c', strtotime( $device->expires_at . ' UTC' ) ) : null ),
			'event_ids' => Devices::event_ids( $device ),
			'area' => Devices::area_payload( Devices::area_id( $device ) ),
			'features' => array( 'undo' => true, 'offline_sync' => true, 'check_out' => true, 'areas' => true, 'attendee_name' => (bool) \PassCaptain\Settings::get( 'scanner_show_attendee' ) ),
			'server_time' => gmdate( 'c' ),
		) );
	}

	/** Occurrences of the device's events from yesterday on (max. 100). */
	public static function occurrences( \WP_REST_Request $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = Devices::event_ids( Devices::current() );
		if ( ! $ids ) { return rest_ensure_response( array( 'occurrences' => array() ) ); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.ends_at,o.timezone,o.location,o.status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.event_id IN (" . implode( ',', array_map( 'absint', $ids ) ) . ") AND o.starts_at >= %s ORDER BY o.starts_at LIMIT 100", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$out = array();
		$areas = array();
		foreach ( $rows as $row ) {
			if ( ! \PassCaptain\Access::can_access_event( (int) $row->event_id, 'wpcap_checkin' ) ) { continue; }
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
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", absint( $request['occurrence_id'] ) ) );
		if ( ! $occ || ! in_array( (int) $occ->event_id, Devices::event_ids( Devices::current() ), true ) || ! \PassCaptain\Access::can_access_event( (int) $occ->event_id, 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$counts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.status, COUNT(*) AS n FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d GROUP BY t.status", (int) $occ->occurrence_id ) ) as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}
		$valid = $counts['valid'] ?? 0;
		$checked = $counts['checked_in'] ?? 0;
		$device_scans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE device_id=%d AND result='checked_in' AND event_id=%d", (int) Devices::current()->device_id, (int) $occ->event_id ) );
		$presence = Engine::presence( (int) $occ->occurrence_id );
		$movement = Engine::movement( (int) $occ->occurrence_id );
		$by_area = array();
		foreach ( Areas::for_event( (int) $occ->event_id, true ) as $area ) {
			$id = (int) $area->area_id;
			$by_area[] = array( 'area_id' => $id, 'name' => $area->name, 'present' => $presence['by_area'][ $id ] ?? 0, 'entries' => $movement['by_area'][ $id ]['entries'] ?? 0, 'checked_out' => $movement['by_area'][ $id ]['checked_out'] ?? 0 );
		}
		return rest_ensure_response( array( 'occurrence_id' => (int) $occ->occurrence_id, 'tickets_total' => $valid + $checked, 'checked_in' => $checked, 'remaining' => $valid, 'checked_in_by_this_device' => $device_scans, 'present' => $presence['present'], 'entries' => $movement['entries'], 'checked_out_total' => $movement['checked_out'], 'device_area_id' => Devices::area_id( Devices::current() ), 'by_area' => $by_area ) );
	}

	public static function validate( \WP_REST_Request $request ) {
		$result = Checkin::preview( $request->get_param( 'code' ), self::context( $request ) );
		if ( is_wp_error( $result ) ) { return self::scan_response( $result ); }
		// ok=true only when the ticket would be admitted now.
		return rest_ensure_response( array_merge( $result, array( 'ok' => 'valid' === $result['status'], 'result' => (string) $result['status'] ) ) );
	}

	public static function check_in( \WP_REST_Request $request ) {
		$key = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$context = self::context( $request );
		$context['idempotency_key'] = 'app:' . $key;
		$context['source'] = 'app';
		return self::scan_response( Checkin::perform( $request->get_param( 'code' ), $context ) );
	}

	/** Check-out (person leaves); for presence counting and re-entry tickets. */
	public static function check_out( \WP_REST_Request $request ) {
		$key = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$context = self::context( $request );
		$context['idempotency_key'] = 'app:' . $key;
		$context['source'] = 'app';
		return self::scan_response( Checkin::check_out( $request->get_param( 'code' ), $context ) );
	}

	public static function undo( \WP_REST_Request $request ) {
		$found = Checkin::lookup( $request->get_param( 'code' ) );
		if ( is_wp_error( $found ) ) { return self::scan_response( $found ); }
		if ( ! in_array( (int) $found['event_id'], Devices::event_ids( Devices::current() ), true ) ) {
			return self::scan_response( new \WP_Error( 'wpcap_device_event', __( 'Dieses Gerät ist für ein anderes Event freigegeben.', 'wp-pass-captain' ), array( 'result' => 'wrong_event' ) ) );
		}
		$policy = Policies::for_type( $found['ticket_type_id'] );
		if ( $policy && 'single' !== $policy['policy'] ) {
			if ( ! \PassCaptain\Access::can_access_event( $found['event_id'], 'wpcap_checkin' ) ) {
				return self::scan_response( new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'wp-pass-captain' ), array( 'status' => 403, 'result' => 'forbidden' ) ) );
			}
			$result = Engine::undo_last( $found, (string) $request->get_param( 'reason' ) );
		} else {
			$result = TicketsPage::undo_checkin( array( 'ticket_id' => $found['ticket_id'], 'reason' => (string) $request->get_param( 'reason' ) ) );
		}
		if ( is_wp_error( $result ) ) { return self::scan_response( $result ); }
		return rest_ensure_response( array( 'ok' => true, 'result' => 'undone', 'ticket_public_id' => $found['public_id'] ) );
	}

	/**
	 * Uploads scans made offline (max. 200). Each scan carries its own idempotency key, so a
	 * repeated upload is safe. A ticket admitted elsewhere in the meantime is reported as conflict.
	 */
	public static function sync( \WP_REST_Request $request ) {
		$scans = $request->get_param( 'scans' );
		if ( ! is_array( $scans ) || ! $scans || count( $scans ) > 200 ) {
			return new \WP_Error( 'wpcap_sync_invalid', __( 'Es müssen 1 bis 200 Scans übertragen werden.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
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
			$scanned = strtotime( (string) ( $scan['scanned_at'] ?? '' ) );
			if ( $scanned && $scanned <= time() + 300 ) { $context['scanned_at'] = gmdate( 'Y-m-d H:i:s', $scanned ); }
			$out = 'out' === ( $scan['direction'] ?? 'in' );
			$outcome = $out ? Checkin::check_out( $scan['code'] ?? '', $context ) : Checkin::perform( $scan['code'] ?? '', $context );
			$row = self::scan_payload( $outcome );
			$row['idempotency_key'] = $key;
			$row['direction'] = $out ? 'out' : 'in';
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

	public static function logout( \WP_REST_Request $request ) {
		$result = Devices::revoke( (int) Devices::current()->device_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	private static function context( \WP_REST_Request $request ) {
		$device = Devices::current();
		return array( 'event_ids' => Devices::event_ids( $device ), 'device_id' => (int) $device->device_id, 'occurrence_id' => absint( $request->get_param( 'occurrence_id' ) ), 'area_id' => Devices::area_id( $device ) ?: absint( $request->get_param( 'area_id' ) ) );
	}

	/** Scan outcomes as 200 responses; authentication/validation problems stay HTTP errors. */
	private static function scan_response( $outcome ) {
		if ( is_wp_error( $outcome ) ) {
			$data = (array) $outcome->get_error_data();
			if ( in_array( (int) ( $data['status'] ?? 0 ), array( 401, 429 ), true ) ) { return $outcome; }
		}
		return rest_ensure_response( self::scan_payload( $outcome ) );
	}

	private static function scan_payload( $outcome ) {
		if ( is_wp_error( $outcome ) ) {
			$data = (array) $outcome->get_error_data();
			return array_filter( array( 'ok' => false, 'result' => (string) ( $data['result'] ?? $data['ticket_status'] ?? 'error' ), 'code' => $outcome->get_error_code(), 'message' => $outcome->get_error_message(), 'event' => $data['event'] ?? null, 'ticket' => $data['ticket'] ?? null ), static function ( $v ) { return null !== $v; } );
		}
		return array_merge( array( 'ok' => true, 'result' => (string) $outcome['status'] ), $outcome );
	}
}
