<?php
/**
 * Door-staff tools of the web scanner and the scanner apps besides scanning (Phase 10, R4 Einlass):
 *
 * - undo():   takes back a mistaken admission with a required reason (A547). The web scanner offers
 *             it for 30 s after the own scan; the server allows it for the user's own latest admission
 *             within `wpcap_scanner_undo_seconds` (default 120 s). The app API keeps its device/area
 *             rules (A590). The guard is Checkin::undo_guard() in both undo paths.
 * - counts(): check-in counter of an occurrence ("Eingecheckt 120 / 300", A546) for the web scanner
 *             and – as `admitted` in /app/occurrences/{id}/stats – for the apps.
 * - search(): manual search at the door (A544) by order number and – only for fields the user may
 *             see in clear text (Pii) – attendee name and e-mail; then check-in by ticket id
 *             (Checkin::perform_ticket(), same rules as a scan, logged `via: manual`).
 *
 * Web routes (cookie + REST nonce, capability wpcap_checkin; event scope in Checkin/Access):
 *   POST /pass-captain/v1/scanner/undo {ticket_public_id, reason, area_id?}
 *   GET  /pass-captain/v1/scanner/stats?occurrence_id=
 *   GET  /pass-captain/v1/scanner/search?q=&occurrence_id=
 *   POST /pass-captain/v1/scanner/check-in-ticket {ticket_public_id, occurrence_id?, area_id?, idempotency_key}
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
		register_rest_route( self::NS, '/scanner/stats', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_stats' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/scanner/search', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_search' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/scanner/check-in-ticket', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_check_in_ticket' ), 'permission_callback' => $perm ) );
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
		if ( '' !== Http::str( $in['ticket_public_id'] ?? '' ) ) {
			$id = self::ticket_id_of( $in['ticket_public_id'] );
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
		$done = array( 'ok' => true, 'status' => 'undone', 'result' => 'undone', 'ticket_public_id' => $found['public_id'] );
		// A retried request with the same idempotency key is answered as done (replayed).
		return is_array( $result ) && ! empty( $result['replayed'] ) ? $done + array( 'replayed' => true ) : $done;
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

	/**
	 * Check-in counter of an occurrence (A546): tickets that admit (`total`: valid or used, not
	 * refunded) and how many of them were admitted at least once (`admitted`: status checked_in, or a
	 * repeatable ticket with more admissions than undos). The caller checks the scope.
	 *
	 * @return array{occurrence_id: int, total: int, admitted: int, remaining: int}|null null on a read error.
	 */
	public static function counts( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total, COALESCE(SUM(t.status='checked_in' OR COALESCE(c.net,0)>0),0) AS admitted FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN (SELECT c.ticket_id, SUM(c.result='checked_in')-SUM(c.result='undone') AS net FROM {$p}wpcap_checkins c INNER JOIN {$p}wpcap_tickets t2 ON t2.ticket_id=c.ticket_id INNER JOIN {$p}wpcap_ticket_types tt2 ON tt2.ticket_type_id=t2.ticket_type_id WHERE tt2.occurrence_id=%d AND c.result IN ('checked_in','undone') GROUP BY c.ticket_id) c ON c.ticket_id=t.ticket_id WHERE tt.occurrence_id=%d AND t.status IN ('valid','checked_in') AND t.refunded=0",
			$occurrence_id,
			$occurrence_id
		) );
		if ( ! $row || '' !== $wpdb->last_error ) { return null; }
		$total = (int) $row->total;
		$admitted = min( $total, (int) $row->admitted );
		return array( 'occurrence_id' => $occurrence_id, 'total' => $total, 'admitted' => $admitted, 'remaining' => max( 0, $total - $admitted ) );
	}

	/** GET /scanner/stats?occurrence_id= (web scanner counter, A546). */
	public static function rest_stats( \WP_REST_Request $request ) {
		$occurrence_id = absint( $request->get_param( 'occurrence_id' ) );
		if ( ! $occurrence_id || ! Access::can_access_occurrence( $occurrence_id, 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$counts = self::counts( $occurrence_id );
		if ( null === $counts ) {
			return new \WP_Error( 'wpcap_stats_retry', __( 'Der Zähler konnte gerade nicht gelesen werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		return rest_ensure_response( $counts + array( 'server_time' => gmdate( 'c' ) ) );
	}

	/** Search results per request (one more is read to tell "more results"). */
	const SEARCH_LIMIT = 10;

	/**
	 * Occurrences a search may cover: the chosen one (when accessible), else those of the web scanner
	 * date list or – for a device – the running/upcoming ones of its events.
	 *
	 * @param array $context occurrence_id, event_ids (device; null for the web scanner).
	 * @return int[]
	 */
	private static function search_occurrences( array $context ) {
		$chosen = absint( $context['occurrence_id'] ?? 0 );
		if ( $chosen ) { return Access::can_access_occurrence( $chosen, 'wpcap_checkin' ) ? array( $chosen ) : array(); }
		if ( ! isset( $context['event_ids'] ) || ! is_array( $context['event_ids'] ) ) {
			return array_map( 'intval', array_column( PublicEvents::scanner_occurrences(), 'id' ) );
		}
		$events = array_filter( array_map( 'absint', $context['event_ids'] ) );
		if ( ! $events ) { return array(); }
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT o.occurrence_id FROM {$wpdb->prefix}wpcap_occurrences o WHERE o.event_id IN (" . implode( ',', $events ) . ") AND o.status<>'cancelled' AND " . Occurrence::end_sql( 'o' ) . ' >= %s AND o.starts_at <= %s ORDER BY o.starts_at LIMIT 100', Clock::mysql( -DAY_IN_SECONDS ), Clock::mysql( 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list, static expression.
		return array_values( array_filter( array_map( 'intval', (array) $ids ), static function ( $id ) { return Access::can_access_occurrence( $id, 'wpcap_checkin' ); } ) );
	}

	/**
	 * Manual search at the door (A544): order number (as on the confirmation, "AB12-CD34", at least 4
	 * characters) and, only where the user may see the field in clear text (Pii, A119), attendee name
	 * and attendee/buyer e-mail (at least 3 characters). At most SEARCH_LIMIT tickets of the covered
	 * occurrences; e-mails are always shown masked, names as Pii allows. Rate-limited per user (30 per
	 * minute) and recorded in the access log (masked term). Names are shown in clear text only with the
	 * setting scanner_show_attendee, like scan results.
	 *
	 * @param string $q       Search term.
	 * @param array  $context occurrence_id, event_ids (device).
	 * @return array|\WP_Error ['results' => array[], 'more' => bool, 'fields' => string[]]
	 */
	public static function search( $q, array $context ) {
		$q = trim( sanitize_text_field( Http::str( $q ) ) );
		$prefix = Admin\Queries::public_id_prefix( $q );
		$can = array( 'attendee_name' => Pii::can_view( 'attendee_name' ), 'attendee_email' => Pii::can_view( 'attendee_email' ), 'buyer_email' => Pii::can_view( 'buyer_email' ) );
		$fields = array_merge( array( 'order' ), array_keys( array_filter( $can ) ) );
		if ( '' === $prefix && mb_strlen( $q ) < 3 ) {
			return new \WP_Error( 'wpcap_search_short', __( 'Bitte mindestens 3 Zeichen eingeben (Bestellnummer mindestens 4).', 'pass-captain' ), array( 'status' => 400, 'result' => 'invalid' ) );
		}
		$per_minute = max( 1, (int) apply_filters( 'wpcap_scanner_search_limit', 30 ) );
		if ( ! RateLimit::allow( 'scanner_search_' . get_current_user_id() . '_' . absint( $context['device_id'] ?? 0 ), $per_minute, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_search_rate_limited', __( 'Zu viele Suchanfragen. Bitte kurz warten.', 'pass-captain' ), array( 'status' => 429, 'result' => 'rate_limited' ) );
		}
		$occurrences = self::search_occurrences( $context );
		if ( ! $occurrences ) { return array( 'results' => array(), 'more' => false, 'fields' => $fields ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$or = array();
		$args = array();
		if ( '' !== $prefix ) { $or[] = 'ord.public_id LIKE %s'; $args[] = $wpdb->esc_like( $prefix ) . '%'; }
		if ( mb_strlen( $q ) >= 3 ) {
			$part = '%' . $wpdb->esc_like( $q ) . '%';
			if ( $can['attendee_name'] ) { $or[] = 'ta.attendee_name LIKE %s'; $args[] = $part; }
			if ( $can['attendee_email'] ) { $or[] = 't.attendee_email LIKE %s'; $or[] = 'ta.attendee_email LIKE %s'; $args[] = $part; $args[] = $part; }
			if ( $can['buyer_email'] ) { $or[] = 'ord.customer_email LIKE %s'; $args[] = $part; }
		}
		if ( ! $or ) { return array( 'results' => array(), 'more' => false, 'fields' => $fields ); }
		$args[] = self::SEARCH_LIMIT + 1;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.public_id,t.status,t.refunded,t.attendee_email,ta.attendee_name,ta.attendee_email AS assigned_email,ord.public_id AS order_public_id,ord.customer_email,tt.name AS ticket_name,o.occurrence_id,o.starts_at,o.timezone,e.name AS event_name FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE tt.occurrence_id IN (" . implode( ',', array_map( 'absint', $occurrences ) ) . ') AND (' . implode( ' OR ', $or ) . ") ORDER BY (t.status='valid' AND t.refunded=0) DESC, o.starts_at, t.ticket_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list, fixed conditions.
			$args
		) );
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'wpcap_search_retry', __( 'Die Suche ist gerade nicht möglich. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'result' => 'retry', 'retryable' => true ) );
		}
		$more = count( $rows ) > self::SEARCH_LIMIT;
		$show_name = Settings::get( 'scanner_show_attendee' ) && $can['attendee_name'];
		$results = array();
		foreach ( array_slice( (array) $rows, 0, self::SEARCH_LIMIT ) as $row ) {
			$email = '' !== (string) $row->assigned_email ? (string) $row->assigned_email : ( '' !== (string) $row->attendee_email ? (string) $row->attendee_email : (string) $row->customer_email );
			$results[] = array(
				'ticket_public_id' => (string) $row->public_id,
				'occurrence_id' => (int) $row->occurrence_id,
				'event' => (string) $row->event_name,
				'ticket' => (string) $row->ticket_name,
				'starts_at' => gmdate( 'c', strtotime( $row->starts_at . ' UTC' ) ),
				'timezone' => (string) $row->timezone,
				'status' => (int) $row->refunded ? 'revoked' : (string) $row->status,
				'order_ref' => Frontend\Format::order_reference( (string) $row->order_public_id ),
				// The scan screen is visible to bystanders: the name in clear text only with the scanner setting (as for scans).
				'attendee_name' => $show_name ? (string) $row->attendee_name : Pii::mask( 'attendee_name', (string) $row->attendee_name ),
				'email' => Pii::mask( 'attendee_email', $email ),
			);
		}
		Privacy\AccessLog::list_view( 'scanner_search', array( 'q' => $q ), count( $results ) );
		return array( 'results' => $results, 'more' => $more, 'fields' => $fields );
	}

	/** GET /scanner/search (web scanner). */
	public static function rest_search( \WP_REST_Request $request ) {
		$result = self::search( $request->get_param( 'q' ), array( 'occurrence_id' => absint( $request->get_param( 'occurrence_id' ) ) ) );
		return is_wp_error( $result ) ? self::client_error( $result ) : rest_ensure_response( $result );
	}

	/** Ticket id of a public id (32 hex), 0 when unknown. */
	public static function ticket_id_of( $public_id ) {
		$public = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', Http::str( $public_id ) ) );
		if ( 32 !== strlen( $public ) ) { return 0; }
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s", $public ) );
	}

	/**
	 * POST /scanner/check-in-ticket: check-in of a search result (A544) – exactly the rules of a scan
	 * (Checkin::perform_ticket(): scope, date, area, policy, idempotency key), logged `via: manual`.
	 */
	public static function rest_check_in_ticket( \WP_REST_Request $request ) {
		$key = sanitize_text_field( Http::str( $request->get_param( 'idempotency_key' ) ) );
		$id = self::ticket_id_of( $request->get_param( 'ticket_public_id' ) );
		$result = Checkin::perform_ticket( $id, array( 'source' => 'web', 'area_id' => absint( $request->get_param( 'area_id' ) ), 'occurrence_id' => absint( $request->get_param( 'occurrence_id' ) ), 'idempotency_key' => '' !== $key ? 'web:' . $key : '' ) );
		return is_wp_error( $result ) ? Admission\Rest::client_error( $result ) : rest_ensure_response( $result );
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
