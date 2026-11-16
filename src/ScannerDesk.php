<?php
/**
 * Door-staff tools of the web scanner and the scanner apps besides scanning (Phase 10, R4 Einlass):
 *
 * - undo():   takes back a mistaken admission with a required reason (A547). The web scanner offers
 *             it for 30 s after the own scan; the server allows it for the user's own latest admission
 *             within `wpcap_scanner_undo_seconds` (default 120 s). The app API keeps its device/area
 *             rules (A590). The guard is Checkin::undo_guard() in both undo paths.
 *
 * Web routes (cookie + REST nonce, capability wpcap_checkin; event scope in Checkin/Access):
 *   POST /pass-captain/v1/scanner/undo {ticket_public_id, reason, area_id?}
 *
 * @package PassCaptain
 */

namespace PassCaptain;

use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ScannerDesk {
	const NS = 'pass-captain/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$perm = array( __CLASS__, 'permission' );
		register_rest_route( self::NS, '/scanner/undo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_undo' ), 'permission_callback' => $perm ) );
	}

	public static function permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_checkin' );
	}

	/** Seconds after the own scan in which the web scanner may take an admission back. */
	public static function undo_seconds() {
		/**
		 * Seconds after the own admission in which the web scanner may undo it (A547). The scanner
		 * shows the button for 30 s; the server allows a little more for slow connections.
		 *
		 * @param int $seconds Default 120.
		 */
		return max( 10, (int) apply_filters( 'wpcap_scanner_undo_seconds', 120 ) );
	}

	/** Reasons offered by the scanners (stored as text in the audit log). */
	public static function undo_reasons() {
		return array(
			__( 'Fehlscan (falsches Ticket gescannt)', 'pass-captain' ),
			__( 'Doppelt gescannt', 'pass-captain' ),
			__( 'Gast hat den Einlass nicht betreten', 'pass-captain' ),
			__( 'Falscher Termin oder Bereich', 'pass-captain' ),
		);
	}

	/** Ticket by code or by its public id (search results, admissions without code). */
	private static function find( array $in, array $context ) {
		$public = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) ( $in['ticket_public_id'] ?? '' ) ) );
		if ( 32 === strlen( $public ) ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s", $public ) );
			if ( ! $id ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404, 'result' => 'unknown' ) ); }
			return Checkin::lookup( '', null, true, array( 'ticket_ref' => $id ) + $context );
		}
		unset( $context['ticket_ref'] );
		return Checkin::lookup( Http::str( $in['code'] ?? '' ), null, true, $context );
	}

	/**
	 * Takes back the latest admission of a ticket (A547).
	 *
	 * @param array $in      ticket_public_id or code, reason (required).
	 * @param array $context source (web|app), device_id, area_id, event_ids (device), own_recent (web:
	 *                       only the user's own admission within undo_seconds()).
	 * @return array|\WP_Error ['ok' => true, 'result' => 'undone', 'ticket_public_id' => …]; errors carry `result`.
	 */
	public static function undo( array $in, array $context ) {
		$reason = mb_substr( sanitize_text_field( Http::str( $in['reason'] ?? '' ) ), 0, 255 );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpcap_undo_reason_required', __( 'Bitte einen Grund für die Rücknahme angeben.', 'pass-captain' ), array( 'status' => 400, 'result' => 'reason_required' ) );
		}
		$own_recent = ! empty( $context['own_recent'] );
		unset( $context['own_recent'] );
		$found = self::find( $in, $context );
		if ( is_wp_error( $found ) ) { return Checkin::public_error( $found ); }
		if ( isset( $context['event_ids'] ) && is_array( $context['event_ids'] ) && ! in_array( (int) $found['event_id'], array_map( 'intval', $context['event_ids'] ), true ) ) {
			return new \WP_Error( 'wpcap_device_event', __( 'Dieses Gerät ist für ein anderes Event freigegeben.', 'pass-captain' ), array( 'status' => 403, 'result' => 'wrong_event' ) );
		}
		if ( ! Access::can_access_occurrence( $found['occurrence_id'], 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), array( 'status' => 403, 'result' => 'forbidden' ) );
		}
		if ( $own_recent ) {
			$recent = self::own_recent_admission( (int) $found['ticket_id'] );
			if ( is_wp_error( $recent ) ) { return $recent; }
		}
		$context['source'] = sanitize_key( (string) ( $context['source'] ?? 'web' ) );
		$policy = Policies::for_type( $found['ticket_type_id'] );
		$result = $policy && 'single' !== $policy['policy']
			? Engine::undo_last( $found, $reason, $context )
			: TicketsPage::undo_checkin( array( 'ticket_id' => $found['ticket_id'], 'reason' => $reason ), $context );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			$codes = array( 'wpcap_undo_wrong_area' => 'wrong_area', 'wpcap_undo_refunded' => 'revoked', 'wpcap_ticket_not_checked_in' => 'not_checked_in', 'wpcap_admin_forbidden' => 'forbidden', 'wpcap_ticket_missing' => 'unknown', 'wpcap_undo_reason_required' => 'reason_required' );
			if ( empty( $data['result'] ) ) { $data['result'] = $codes[ $result->get_error_code() ] ?? ( 503 === (int) ( $data['status'] ?? 0 ) ? 'retry' : 'error' ); }
			if ( 503 === (int) ( $data['status'] ?? 0 ) ) { $data['retryable'] = true; }
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}
		return array( 'ok' => true, 'status' => 'undone', 'result' => 'undone', 'ticket_public_id' => $found['public_id'] );
	}

	/** Web scanner: only the user's own latest admission, shortly after the scan. */
	private static function own_recent_admission( $ticket_id ) {
		global $wpdb;
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT user_id,result,created_at FROM {$wpdb->prefix}wpcap_checkins WHERE ticket_id=%d AND result IN ('checked_in','checked_out','undone') ORDER BY checkin_id DESC LIMIT 1", absint( $ticket_id ) ) );
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'wpcap_checkin_retry', __( 'Der Einlass konnte gerade nicht zurückgenommen werden. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'result' => 'retry', 'retryable' => true ) );
		}
		if ( ! $last || 'checked_in' !== $last->result ) {
			return new \WP_Error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ), array( 'status' => 409, 'result' => 'not_checked_in' ) );
		}
		$too_late = __( 'Rückgängig ist nur kurz nach dem eigenen Scan möglich. Ältere oder fremde Einlässe nimmt die Einlassleitung unter „Tickets“ zurück.', 'pass-captain' );
		if ( (int) $last->user_id !== (int) get_current_user_id() ) {
			return new \WP_Error( 'wpcap_undo_not_own', $too_late, array( 'status' => 403, 'result' => 'undo_not_allowed' ) );
		}
		if ( strtotime( $last->created_at . ' UTC' ) < Clock::now() - self::undo_seconds() ) {
			return new \WP_Error( 'wpcap_undo_expired', $too_late, array( 'status' => 409, 'result' => 'undo_not_allowed' ) );
		}
		return true;
	}

	/** Error for the browser: status, result and message only. */
	private static function client_error( \WP_Error $error ) {
		$data = (array) $error->get_error_data();
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array_filter( array( 'status' => (int) ( $data['status'] ?? 409 ), 'result' => isset( $data['result'] ) ? (string) $data['result'] : null, 'retryable' => ! empty( $data['retryable'] ) ? true : null ), static function ( $v ) { return null !== $v; } ) );
	}

	/** POST /scanner/undo (web scanner). */
	public static function rest_undo( \WP_REST_Request $request ) {
		$result = self::undo(
			array( 'ticket_public_id' => $request->get_param( 'ticket_public_id' ), 'reason' => $request->get_param( 'reason' ) ),
			array( 'source' => 'web', 'area_id' => absint( $request->get_param( 'area_id' ) ), 'own_recent' => true )
		);
		return is_wp_error( $result ) ? self::client_error( $result ) : rest_ensure_response( $result );
	}
}
