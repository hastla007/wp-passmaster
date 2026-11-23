<?php
/**
 * Web scanner endpoints with area selection, check-out and idempotent offline sync
 * (cookie authentication + REST nonce, capability wpcap_checkin; event scope in Checkin).
 *
 *   POST /pass-captain/v1/admission/check-in     {token, area_id?, idempotency_key?}
 *   POST /pass-captain/v1/admission/check-out    {token, area_id?, idempotency_key?}
 *   POST /pass-captain/v1/admission/offline-sync {scans: [{token, idempotency_key, scanned_at, area_id?, direction?}]}
 */
namespace PassCaptain\Admission;

use PassCaptain\Checkin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest {
	const NS = 'pass-captain/v1';

	public static function routes() {
		$perm = array( __CLASS__, 'permission' );
		register_rest_route( self::NS, '/admission/check-in', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/admission/check-out', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_out' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/admission/offline-sync', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'offline_sync' ), 'permission_callback' => $perm ) );
	}

	public static function permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_checkin' );
	}

	/** Test helper: forget that the scanner data was added. */
	public static function reset_scanner_localized() {
		self::$scanner_localized = false;
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

	/** Error for the browser: HTTP status, stable result code and message only (no internal ids, A365). */
	public static function client_error( \WP_Error $error ) {
		return Checkin::client_error( $error );
	}

	/**
	 * Uploads scans queued by the web scanner while offline (1–100). Rejected check-ins of known
	 * tickets are recorded as admission conflicts; repeated uploads are idempotent per key, so every
	 * scan needs its own idempotency_key (A589; without one: result "invalid"). A scan the server could
	 * not book (database busy) comes back with `retryable: true` and stays in the queue (A345).
	 */
	public static function offline_sync( \WP_REST_Request $request ) {
		$scans = $request->get_param( 'scans' );
		if ( ! is_array( $scans ) || ! $scans || count( $scans ) > 100 ) {
			return new \WP_Error( 'wpcap_offline_batch_invalid', __( 'Der Offline-Scanbatch muss 1 bis 100 Codes enthalten.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// A609: scan times need a UTC offset.
		$invalid_time = Checkin::scanned_at_error( $scans );
		if ( $invalid_time ) { return $invalid_time; }
		$now = Checkin::now();
		$results = array();
		foreach ( $scans as $scan ) {
			if ( ! is_array( $scan ) ) { $scan = array( 'token' => (string) $scan ); }
			$token = sanitize_text_field( (string) ( $scan['token'] ?? '' ) );
			$key = sanitize_text_field( (string) ( $scan['idempotency_key'] ?? '' ) );
			if ( '' === $key ) {
				$results[] = array( 'status' => 'conflict', 'result' => 'invalid', 'code' => 'wpcap_idempotency_required', 'message' => __( 'idempotency_key ist erforderlich.', 'pass-captain' ), 'idempotency_key' => '' );
				continue;
			}
			// The web scanner has no verification package (visual check only): its device time counts for
			// at most an hour; older scans are evaluated at upload time.
			$context = array( 'source' => 'offline', 'area_id' => absint( $scan['area_id'] ?? 0 ), 'idempotency_key' => 'web:' . $key, 'occurrence_id' => absint( $scan['occurrence_id'] ?? 0 ), 'max_age' => Checkin::WEB_UPLOAD_MAX_AGE );
			$scanned = Checkin::parse_scanned_at( $scan['scanned_at'] ?? null );
			if ( $scanned && $scanned <= $now + 300 ) { $context['scanned_at'] = gmdate( 'Y-m-d H:i:s', $scanned ); }
			$out = 'out' === ( $scan['direction'] ?? 'in' );
			$outcome = $out ? Checkin::check_out( $token, $context ) : Checkin::perform( $token, $context );
			if ( is_wp_error( $outcome ) && \PassCaptain\Errors::is_busy( $outcome ) ) {
				$results[] = array( 'status' => 'retry', 'result' => 'retry', 'retryable' => true, 'code' => $outcome->get_error_code(), 'message' => $outcome->get_error_message(), 'idempotency_key' => $key );
			} elseif ( is_wp_error( $outcome ) ) {
				$data = (array) $outcome->get_error_data();
				$results[] = array_filter( array( 'status' => 'conflict', 'result' => (string) ( $data['result'] ?? 'error' ), 'code' => $outcome->get_error_code(), 'message' => $outcome->get_error_message(), 'conflict_id' => isset( $data['conflict_id'] ) ? (int) $data['conflict_id'] : null, 'idempotency_key' => $key ), static function ( $v ) { return null !== $v; } );
			} else {
				$results[] = array_merge( $outcome, array( 'idempotency_key' => $key ), ! empty( $outcome['conflict_id'] ) ? array( 'status' => 'conflict', 'admitted' => true ) : array() );
			}
		}
		return rest_ensure_response( array( 'results' => $results, 'offline_conflicts_are_possible' => true ) );
	}

	/** Whether WPCAP_ADMISSION was added in this request (localize_scanner() runs on two hooks). */
	private static $scanner_localized = false;

	/**
	 * Adds area selection, check-out and the idempotent sync endpoint to the web scanner.
	 *
	 * Only on pages that use the scanner (A368): the shortcode enqueues `wpcap-scanner` while the
	 * content renders – before wp_enqueue_scripts in block themes, after it in classic themes – so
	 * this runs on wp_enqueue_scripts and again before the footer scripts are printed, and does
	 * nothing (no area query) unless the script is enqueued.
	 */
	public static function localize_scanner() {
		if ( self::$scanner_localized || ! function_exists( 'wp_localize_script' ) ) { return; }
		// The areas query runs only where the web scanner is on the page, not on every front-end page:
		// the script is enqueued (A368), or – without the script API – the shortcode rendered (PERF-13).
		$on_page = function_exists( 'wp_script_is' ) ? wp_script_is( 'wpcap-scanner', 'enqueued' ) : \PassCaptain\PublicEvents::scanner_rendered();
		if ( ! $on_page ) { return; }
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) { return; }
		self::$scanner_localized = true;
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
				'area' => __( 'Einlassbereich', 'pass-captain' ),
				'noArea' => __( 'Kein Bereich', 'pass-captain' ),
				'modeIn' => _x( 'Einlass', 'scanner direction: entry (opposite of exit)', 'pass-captain' ),
				'modeOut' => __( 'Auslass (Check-out)', 'pass-captain' ),
				'checkedOut' => __( 'Ausgecheckt', 'pass-captain' ),
				/* translators: %d: number of discarded offline scans (not 1; scanner.js uses expiredOne for 1) */
				'expired' => __( '%d Offline-Scans älter als 7 Tage wurden ohne Upload verworfen.', 'pass-captain' ),
				/* translators: %d: number of discarded offline scans (always 1) */
				'expiredOne' => __( '%d Offline-Scan älter als 7 Tage wurde ohne Upload verworfen.', 'pass-captain' ),
			),
		) );
	}
}
