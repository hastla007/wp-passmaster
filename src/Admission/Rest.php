<?php
/**
 * Web scanner endpoints with area selection, check-out and idempotent offline sync
 * (cookie authentication + REST nonce, capability wpcap_checkin; event scope in Checkin).
 *
 *   POST /wp-pass-captain/v1/admission/check-in     {token, area_id?, idempotency_key?}
 *   POST /wp-pass-captain/v1/admission/check-out    {token, area_id?, idempotency_key?}
 *   POST /wp-pass-captain/v1/admission/offline-sync {scans: [{token, idempotency_key, scanned_at, area_id?, direction?}]}
 */
namespace PassCaptain\Admission;

use PassCaptain\Checkin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest {
	const NS = 'wp-pass-captain/v1';

	public static function routes() {
		$perm = array( __CLASS__, 'permission' );
		register_rest_route( self::NS, '/admission/check-in', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/admission/check-out', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_out' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/admission/offline-sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'offline_sync' ), 'permission_callback' => $perm ) );
	}

	public static function permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_checkin' );
	}

	private static function context( \WP_REST_Request $request, $source = 'web' ) {
		$key = sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) );
		return array( 'source' => $source, 'area_id' => absint( $request->get_param( 'area_id' ) ), 'idempotency_key' => '' !== $key ? 'web:' . $key : '', 'occurrence_id' => absint( $request->get_param( 'occurrence_id' ) ) );
	}

	public static function check_in( \WP_REST_Request $request ) {
		$result = Checkin::perform( $request->get_param( 'token' ), self::context( $request ) );
		return is_wp_error( $result ) ? self::client_error( $result ) : rest_ensure_response( $result );
	}

	public static function check_out( \WP_REST_Request $request ) {
		$result = Checkin::check_out( $request->get_param( 'token' ), self::context( $request ) );
		return is_wp_error( $result ) ? self::client_error( $result ) : rest_ensure_response( $result );
	}

	/** Error for the browser: HTTP status, stable result code and message only (no internal ids). */
	public static function client_error( \WP_Error $error ) {
		$data = (array) $error->get_error_data();
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array_filter( array( 'status' => (int) ( $data['status'] ?? 409 ), 'result' => isset( $data['result'] ) ? (string) $data['result'] : null, 'ticket_status' => isset( $data['ticket_status'] ) ? (string) $data['ticket_status'] : null, 'event' => $data['event'] ?? null, 'ticket' => $data['ticket'] ?? null ), static function ( $v ) { return null !== $v; } ) );
	}

	/**
	 * Uploads scans queued by the web scanner while offline (1–100). Rejected check-ins of known
	 * tickets are recorded as admission conflicts; repeated uploads are idempotent per key.
	 */
	public static function offline_sync( \WP_REST_Request $request ) {
		$scans = $request->get_param( 'scans' );
		if ( ! is_array( $scans ) || ! $scans || count( $scans ) > 100 ) {
			return new \WP_Error( 'wpcap_offline_batch_invalid', __( 'Der Offline-Scanbatch muss 1 bis 100 Codes enthalten.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$now = Checkin::now();
		$results = array();
		foreach ( $scans as $scan ) {
			if ( ! is_array( $scan ) ) { $scan = array( 'token' => (string) $scan ); }
			$token = sanitize_text_field( (string) ( $scan['token'] ?? '' ) );
			$key = sanitize_text_field( (string) ( $scan['idempotency_key'] ?? '' ) );
			$context = array( 'source' => 'offline', 'area_id' => absint( $scan['area_id'] ?? 0 ), 'idempotency_key' => '' !== $key ? 'web:' . $key : '', 'occurrence_id' => absint( $scan['occurrence_id'] ?? 0 ) );
			$scanned = strtotime( (string) ( $scan['scanned_at'] ?? '' ) );
			if ( $scanned && $scanned <= $now + 300 ) { $context['scanned_at'] = gmdate( 'Y-m-d H:i:s', $scanned ); }
			$out = 'out' === ( $scan['direction'] ?? 'in' );
			$outcome = $out ? Checkin::check_out( $token, $context ) : Checkin::perform( $token, $context );
			if ( is_wp_error( $outcome ) ) {
				$data = (array) $outcome->get_error_data();
				$results[] = array_filter( array( 'status' => 'conflict', 'result' => (string) ( $data['result'] ?? 'error' ), 'code' => $outcome->get_error_code(), 'message' => $outcome->get_error_message(), 'conflict_id' => isset( $data['conflict_id'] ) ? (int) $data['conflict_id'] : null, 'idempotency_key' => $key ), static function ( $v ) { return null !== $v; } );
			} else {
				$results[] = array_merge( $outcome, array( 'idempotency_key' => $key ), ! empty( $outcome['conflict_id'] ) ? array( 'status' => 'conflict', 'admitted' => true ) : array() );
			}
		}
		return rest_ensure_response( array( 'results' => $results, 'offline_conflicts_are_possible' => true ) );
	}

	/** Adds area selection, check-out and the idempotent sync endpoint to the web scanner. */
	public static function localize_scanner() {
		if ( ! function_exists( 'wp_localize_script' ) || ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) { return; }
		$areas = array();
		foreach ( Areas::selectable() as $area ) {
			$areas[] = array( 'id' => (int) $area->area_id, 'label' => $area->event_name . ' – ' . $area->name );
		}
		wp_localize_script( 'wpcap-scanner', 'WPCAP_ADMISSION', array(
			'checkinUrl' => esc_url_raw( rest_url( self::NS . '/admission/check-in' ) ),
			'checkoutUrl' => esc_url_raw( rest_url( self::NS . '/admission/check-out' ) ),
			'offlineSyncUrl' => esc_url_raw( rest_url( self::NS . '/admission/offline-sync' ) ),
			'areas' => $areas,
			'i18n' => array(
				'area' => __( 'Einlassbereich', 'wp-pass-captain' ),
				'noArea' => __( 'Kein Bereich', 'wp-pass-captain' ),
				'modeIn' => __( 'Einlass', 'wp-pass-captain' ),
				'modeOut' => __( 'Auslass (Check-out)', 'wp-pass-captain' ),
				'checkedOut' => __( 'Ausgecheckt', 'wp-pass-captain' ),
				/* translators: %d: number of discarded offline scans */
				'expired' => __( '%d Offline-Scan(s) älter als 7 Tage wurden ohne Upload verworfen.', 'wp-pass-captain' ),
			),
		) );
	}
}
