<?php
/**
 * Tickets admin: search, revoke or reissue a valid ticket, check a ticket in manually, undo a check-in.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Admission\BulkCheckin;
use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Privacy\AccessLog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketsPage {
	const SLUG = 'wpcap-tickets';
	const VIEW_CAPS = array( 'wpcap_manage_orders', 'wpcap_checkin' );
	const STATUSES = array( 'valid', 'checked_in', 'revoked' );

	/** Menu capability for the current user (first matching view capability). */
	public static function menu_cap() {
		foreach ( self::VIEW_CAPS as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return self::VIEW_CAPS[0];
	}

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/**
	 * Revokes a valid ticket. Requires wpcap_manage_orders for the ticket's event and a reason.
	 * With release_capacity the seat becomes sellable again (counted as refunded quantity, once per
	 * ticket via CapacityRelease, announced to waitlists and allocations). Refused while a refund of
	 * the ticket is pending or running (A537).
	 */
	public static function revoke_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_revoke_reason', __( 'Bitte einen Grund für den Widerruf angeben.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$release = Forms::flag( $in, 'release_capacity' );
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_db_busy', __( 'Das Ticket konnte gerade nicht widerrufen werden. Bitte erneut versuchen.', 'pass-captain' ), 503 );
		};
		// A532: every step is checked; a failed write rolls back and is reported, never shown as done.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		// Order row first (same lock order as refunds and exchanges), then the ticket.
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $ticket->order_id ) );
		// A537: while a refund of the ticket is pending or running, the refund decides about it.
		$open_refund = '' === $wpdb->last_error ? \PassCaptain\Refunds::ticket_has_open_refund( (int) $ticket->order_id, (int) $ticket->ticket_id ) : null;
		if ( false !== $open_refund ) {
			$wpdb->query( 'ROLLBACK' );
			return null === $open_refund
				? Forms::error( 'wpcap_db_busy', __( 'Das Ticket konnte gerade nicht widerrufen werden. Bitte erneut versuchen.', 'pass-captain' ), 503 )
				: Forms::error( 'wpcap_ticket_refund_open', __( 'Für dieses Ticket läuft eine Erstattung oder sie wartet auf Freigabe. Es kann bis zur Entscheidung nicht übertragen, umgetauscht oder widerrufen werden.', 'pass-captain' ), 409 );
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id=%d AND status='valid'", (int) $ticket->ticket_id ) );
		if ( false === $updated ) { return $busy(); }
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige (nicht eingecheckte) Tickets können widerrufen werden.', 'pass-captain' ), 409 );
		}
		// The unit is released once per ticket and recorded on it (CapacityRelease): a later refund or
		// exchange of the same ticket never releases it again (A538).
		$released = $release ? \PassCaptain\CapacityRelease::release_tickets( (int) $ticket->order_id, array( (int) $ticket->ticket_id ) ) : array();
		if ( false === $released || false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		Audit::record( 'ticket_revoked', 'ticket', (int) $ticket->ticket_id, array( 'order_id' => (int) $ticket->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => $reason, 'capacity_released' => $release ) );
		do_action( 'wpcap_ticket_revoked', (int) $ticket->ticket_id, $reason );
		// Waitlists and allocation holds get the released unit (A578).
		\PassCaptain\CapacityRelease::announce( (int) $ticket->order_id, $released, 'tickets_revoked', array( 'reason' => $reason, 'ticket_id' => (int) $ticket->ticket_id ) );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id );
	}

	/**
	 * Reissues one valid ticket (new code, old QR code invalid) and mails it to the holder.
	 * Requires wpcap_manage_orders for the ticket's event.
	 */
	public static function reissue_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$result = \PassCaptain\Orders::reissue_ticket( (int) $ticket->ticket_id, array( 'reason' => Forms::text( $in, 'reason', 255 ) ) );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return Forms::error( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 409 ) );
		}
		return $result + array( 'public_id' => $ticket->public_id );
	}

	/**
	 * Manual check-in of a valid ticket from the ticket list (A338), e.g. when the guest's code cannot
	 * be read. Requires wpcap_checkin for the ticket's occurrence; runs through Checkin::perform_ticket()
	 * with every rule of a scan (occurrence, area, admission policy, area capacity). The form's
	 * idempotency key makes a double submit harmless.
	 *
	 * @param array $in ticket_id, area_id (entrance area, when the event has areas), idempotency_key.
	 * @return array|\WP_Error
	 */
	public static function checkin_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_checkin' ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) ); }
		$key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $in['idempotency_key'] ?? '' ) );
		$context = array( 'area_id' => Forms::int( $in, 'area_id' ), 'source' => 'admin' );
		if ( strlen( $key ) >= 16 ) { $context['idempotency_key'] = 'admin-checkin|' . $key; }
		$result = \PassCaptain\Checkin::perform_ticket( (int) $ticket->ticket_id, $context );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( (array) $result->get_error_data() )['status'] ?? 409 );
			return Forms::error( $result->get_error_code(), $result->get_error_message(), 403 === $status ? 403 : 409 );
		}
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'replayed' => ! empty( $result['replayed'] ) );
	}

	/** Active entrance areas the current user may admit to for an event (empty without the admission module). */
	private static function checkin_areas( $event_id ) {
		if ( ! \PassCaptain\Modules::enabled( \PassCaptain\Modules\Admission::class ) || ! \PassCaptain\Privacy::has_table( 'wpcap_admission_areas' ) ) { return array(); }
		$areas = array();
		foreach ( \PassCaptain\Admission\Areas::selectable( array( (int) $event_id ) ) as $area ) { $areas[ (int) $area->area_id ] = (string) $area->name; }
		return $areas;
	}

	/**
	 * Undoes a check-in: checked_in → valid, logged as a check-in row with result "undone" (with device
	 * and source; the audit entry also names the area of the undone admission). Requires wpcap_checkin
	 * for the ticket's event and the capability wpcap_undo_checkin (Checkin::undo_denied()); refused for refunded tickets and tickets of closed orders and outside the
	 * area binding of staff and device (Checkin::undo_guard(), A590). A reason is required (A547).
	 *
	 * @param array $in      ticket_id, reason.
	 * @param array $context Scanner context of the caller: device_id, area_id, source (default "admin").
	 */
	public static function undo_checkin( array $in, array $context = array() ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( (int) $ticket->occurrence_id, 'wpcap_checkin', (int) $ticket->event_id ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) ); }
		// A547: every undo names its reason (admin list, web scanner, app).
		if ( '' === Forms::text( $in, 'reason', 255 ) ) { return Forms::error( 'wpcap_undo_reason_required', __( 'Bitte einen Grund für die Rücknahme angeben.', 'pass-captain' ) ); }
		$device_id = absint( $context['device_id'] ?? ( $context['device'] ?? Forms::int( $in, 'device_id' ) ) );
		$context['device_id'] = $device_id;
		// Undo rules of the device/staff (Checkin::undo_denied(): time window, own device, policy).
		$denied = \PassCaptain\Checkin::undo_denied( (int) $ticket->ticket_id, (int) $ticket->event_id, array( 'device_id' => $device_id ) );
		if ( $denied ) { return Forms::error( $denied->get_error_code(), $denied->get_error_message(), 403 ); }
		// Idempotency key from the form or the scanner context (ScannerDesk passes it in $context).
		$key = Forms::text( $in, 'idempotency_key', 190 );
		if ( '' === $key && isset( $context['idempotency_key'] ) && is_scalar( $context['idempotency_key'] ) ) { $key = mb_substr( sanitize_text_field( (string) $context['idempotency_key'] ), 0, 190 ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$now = \PassCaptain\Clock::mysql();
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_db_busy', __( 'Die Änderung konnte gerade nicht gespeichert werden. Bitte erneut versuchen.', 'pass-captain' ), 503 );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT t.ticket_id,t.status,t.refunded,ord.status AS order_status,ord.payment_status FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', $p . 'wpcap_orders', (int) $ticket->ticket_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy(); }
		// A retried undo (same key, e.g. a double submit or an app retry) never removes a second entry.
		$claim = '' !== $key ? \PassCaptain\Checkin::claim_key( $key, (int) $ticket->ticket_id ) : 1;
		if ( $claim < 0 ) { return $busy(); }
		if ( 0 === $claim ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'status' => $row ? (string) $row->status : '', 'replayed' => true );
		}
		if ( ! $row || 'checked_in' !== $row->status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ), 409 );
		}
		// A checked-in ticket keeps its status when it is refunded or its order is cancelled/refunded;
		// undoing the check-in must not turn it back into a valid (admissible) ticket.
		if ( (int) $row->refunded || 'paid' !== $row->payment_status || 'complete' !== $row->order_status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_undo_refunded', __( 'Der Check-in eines erstatteten oder stornierten Tickets kann nicht zurückgenommen werden.', 'pass-captain' ), 409 );
		}
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_day_id FROM %i WHERE ticket_id=%d AND result='checked_in' ORDER BY checkin_id DESC LIMIT 1", $p . 'wpcap_checkins', (int) $ticket->ticket_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy(); }
		// Pending lazy undos are applied first, so the state below is current.
		if ( false === \PassCaptain\Admission\Engine::state_for_update( (int) $ticket->ticket_id ) ) { return $busy(); }
		// Area binding of staff and device, closed orders (A590).
		$guard = \PassCaptain\Checkin::undo_guard( (int) $ticket->ticket_id, $context );
		if ( is_wp_error( $guard ) ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( $guard->get_error_code(), $guard->get_error_message(), (int) ( $guard->get_error_data()['status'] ?? 409 ) );
		}
		$source = sanitize_key( (string) ( $context['source'] ?? '' ) );
		$source = '' !== $source ? $source : 'admin';
		$logged = $wpdb->insert( "{$p}wpcap_checkins", array(
			'ticket_id' => (int) $ticket->ticket_id,
			'event_id' => (int) $ticket->event_id,
			'occurrence_day_id' => $last ? (int) $last->occurrence_day_id : 0,
			'user_id' => get_current_user_id(),
			'result' => 'undone',
			'device_id' => $device_id,
			'source' => $source,
			'created_at' => $now,
		), array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
		$undo_id = false === $logged ? 0 : (int) $wpdb->insert_id;
		if ( ! $undo_id ) { return $busy(); }
		// Only the last entry is taken back; the ticket is valid again only when no entry remains
		// (after a re-entry the first admission still counts and the ticket stays checked in).
		$remaining = \PassCaptain\Admission\Engine::revert_last_entry( (int) $ticket->ticket_id, $undo_id );
		if ( false === $remaining ) { return $busy(); }
		if ( 0 === $remaining && 1 !== (int) $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'checked_in' ), array( '%s' ), array( '%d', '%s' ) ) ) { return $busy(); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		Audit::record( 'checkin_undone', 'ticket', (int) $ticket->ticket_id, array( 'event_id' => (int) $ticket->event_id, 'reason' => Forms::text( $in, 'reason', 255 ), 'remaining_entries' => $remaining, 'device_id' => $device_id, 'area_id' => (int) ( $guard['area_id'] ?? 0 ), 'source' => $source ) );
		/** A667: an admission was taken back (after the commit); context: source. */
		do_action( 'wpcap_checkin_undone', (int) $ticket->ticket_id, array( 'source' => $source ) );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'status' => $remaining ? 'checked_in' : 'valid', 'remaining_entries' => $remaining );
	}

	/**
	 * Corrects the attendee name and e-mail of a ticket (right to rectification, Art. 16 GDPR) for the
	 * same person: the ticket code stays valid (a change of person uses "Neu ausstellen"). Requires
	 * wpcap_manage_orders for the ticket's event and a reason. Audited as record_corrected with a masked
	 * before/after diff (field names, masked values).
	 */
	public static function correct_attendee( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( ! in_array( (string) $ticket->status, array( 'valid', 'checked_in' ), true ) ) {
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige oder eingecheckte Tickets können korrigiert werden.', 'pass-captain' ), 409 );
		}
		$name = Forms::text( $in, 'attendee_name', 190 );
		$email = \PassCaptain\Http::optional_email( $in['attendee_email'] ?? '' );
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_correction_reason', __( 'Bitte einen Grund für die Korrektur angeben.', 'pass-captain' ) ); }
		if ( false === $email ) { return Forms::error( 'wpcap_correction_email', __( 'Die E-Mail-Adresse ist ungültig.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT attendee_name,attendee_email FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", (int) $ticket->ticket_id ) );
		$before = array(
			'attendee_name' => $assignment ? (string) $assignment->attendee_name : '',
			'attendee_email' => $assignment && '' !== (string) $assignment->attendee_email ? (string) $assignment->attendee_email : (string) $ticket->attendee_email,
		);
		$after = array( 'attendee_name' => $name, 'attendee_email' => $email );
		if ( AccessLog::diff( $before, $after ) === array() ) {
			return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'changed' => false );
		}
		$failed = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_correction_failed', __( 'Die Teilnehmerdaten konnten nicht gespeichert werden.', 'pass-captain' ), 500 );
		};
		// A532: each write and the COMMIT are checked; nothing is reported or audited as corrected otherwise.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $failed(); }
		$ticket_saved = $wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $email ), array( 'ticket_id' => (int) $ticket->ticket_id ), array( '%s' ), array( '%d' ) );
		$assignment_saved = false === $ticket_saved ? false : $wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", (int) $ticket->ticket_id, (string) $ticket->customer_email, $email, $name, \PassCaptain\Clock::mysql() ) );
		if ( false === $assignment_saved || $wpdb->last_error ) { return $failed(); }
		\PassCaptain\TicketAssignments::touch( array( (int) $ticket->ticket_id ) ); // A574: partner API "since".
		if ( '' !== $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { return $failed(); }
		$changes = AccessLog::correction( 'ticket', (int) $ticket->ticket_id, $before, $after, array( 'order_id' => (int) $ticket->order_id, 'event_id' => (int) $ticket->event_id, 'source' => 'admin', 'reason' => $reason ) );
		/** Staff corrected personal data of a record (A122); $changes holds field names with masked values. */
		do_action( 'wpcap_record_corrected', 'ticket', (int) $ticket->ticket_id, $changes );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'changed' => true );
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	/**
	 * List filters from request values (A684): search, event, date, ticket type, status and sort order; ids of
	 * dates/types only together with their event, unknown values dropped.
	 *
	 * @param array $q Raw values (s, event_id, occurrence_id, ticket_type_id, status, orderby).
	 * @return array{search:string,event_id:int,occurrence_id:int,ticket_type_id:int,status:string,orderby:string}
	 */
	public static function filters( array $q ) {
		$event = absint( $q['event_id'] ?? 0 );
		$status = (string) ( $q['status'] ?? '' );
		$orderby = (string) ( $q['orderby'] ?? '' );
		return array(
			'search' => trim( sanitize_text_field( (string) ( $q['s'] ?? '' ) ) ),
			'event_id' => $event,
			'occurrence_id' => $event ? absint( $q['occurrence_id'] ?? 0 ) : 0,
			'ticket_type_id' => $event ? absint( $q['ticket_type_id'] ?? 0 ) : 0,
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'orderby' => array_key_exists( $orderby, Queries::ticket_orders() ) ? $orderby : 'newest',
		);
	}

	/** Dates of an event for the filter (id => local start). */
	private static function occurrence_options( $event_id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT occurrence_id,starts_at,timezone FROM %i WHERE event_id=%d ORDER BY starts_at LIMIT 300', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ) ) ) as $o ) {
			$out[ (int) $o->occurrence_id ] = Forms::datetime( $o->starts_at, $o->timezone, false );
		}
		return $out;
	}

	/** Ticket types of an event (or one date) for the filter. */
	private static function type_options( $event_id, $occurrence_id = 0 ) {
		global $wpdb;
		$out = array();
		$rows = $occurrence_id
			? $wpdb->get_results( $wpdb->prepare( 'SELECT tt.ticket_type_id,tt.name FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND o.occurrence_id=%d ORDER BY tt.name LIMIT 300', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ), absint( $occurrence_id ) ) )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT tt.ticket_type_id,tt.name,o.starts_at,o.timezone FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d ORDER BY o.starts_at,tt.name LIMIT 300', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ) ) );
		foreach ( (array) $rows as $t ) {
			$out[ (int) $t->ticket_type_id ] = (string) $t->name . ( isset( $t->starts_at ) ? ' – ' . Forms::datetime( $t->starts_at, $t->timezone, false ) : '' );
		}
		return $out;
	}

	/** A414: back to the list with search, event, status and page of the action's origin. */
	private static function back_url( array $in ) {
		$status = Forms::text( $in, 'return_status', 32 );
		$orderby = Forms::text( $in, 'return_orderby', 16 );
		$args = array(
			's' => Forms::text( $in, 'return_search', 190 ),
			'event_id' => Forms::int( $in, 'return_event' ),
			'occurrence_id' => Forms::int( $in, 'return_occurrence' ),
			'ticket_type_id' => Forms::int( $in, 'return_type' ),
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'orderby' => array_key_exists( $orderby, Queries::ticket_orders() ) && 'newest' !== $orderby ? $orderby : '',
			'paged' => max( 0, Forms::int( $in, 'return_paged' ) ),
		);
		if ( 1 === $args['paged'] ) { $args['paged'] = 0; }
		return Forms::page_url( self::SLUG, array_filter( $args ) );
	}

	/** Hidden fields carrying the list state into an action form (A414). */
	private static function return_fields( array $filters, $page ) {
		Forms::hidden( 'return_search', (string) $filters['search'] );
		Forms::hidden( 'return_event', (int) $filters['event_id'] );
		Forms::hidden( 'return_status', (string) $filters['status'] );
		Forms::hidden( 'return_occurrence', (int) ( $filters['occurrence_id'] ?? 0 ) );
		Forms::hidden( 'return_type', (int) ( $filters['ticket_type_id'] ?? 0 ) );
		Forms::hidden( 'return_orderby', (string) ( $filters['orderby'] ?? '' ) );
		Forms::hidden( 'return_paged', max( 1, (int) $page ) );
	}

	public static function handle_revoke_ticket() {
		$in = Forms::post();
		Forms::verify( 'wpcap_revoke_ticket_' . Forms::int( $in, 'ticket_id' ) );
		Forms::finish( self::revoke_ticket( $in ), __( 'Ticket widerrufen.', 'pass-captain' ), self::back_url( $in ) );
	}

	public static function handle_reissue_ticket() {
		$in = Forms::post();
		Forms::verify( 'wpcap_reissue_ticket_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::reissue_ticket( $in );
		$message = ! is_wp_error( $result ) && empty( $result['mailed'] )
			? __( 'Ticket neu ausgestellt. Die E-Mail an den Inhaber konnte nicht zugestellt werden; bitte erneut versuchen.', 'pass-captain' )
			: __( 'Ticket neu ausgestellt und an den Inhaber gesendet. Der bisherige Code ist ungültig.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	public static function handle_correct_attendee() {
		$in = Forms::post();
		Forms::verify( 'wpcap_correct_attendee_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::correct_attendee( $in );
		$message = ! is_wp_error( $result ) && empty( $result['changed'] ) ? __( 'Keine Änderung – die Teilnehmerdaten waren bereits so gespeichert.', 'pass-captain' ) : __( 'Teilnehmerdaten korrigiert. Der Ticketcode bleibt gültig.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	public static function handle_checkin_ticket() {
		Forms::require_any_cap( array( 'wpcap_checkin' ) );
		$in = Forms::post();
		Forms::verify( 'wpcap_checkin_ticket_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::checkin_ticket( $in );
		Forms::finish( $result, is_wp_error( $result ) || empty( $result['replayed'] ) ? __( 'Ticket eingecheckt.', 'pass-captain' ) : __( 'Das Ticket war bereits mit diesem Formular eingecheckt.', 'pass-captain' ), self::back_url( $in ) );
	}

	public static function handle_undo_checkin() {
		// Check-in plus the own undo capability (split off wpcap_checkin), before the nonce (A145).
		Forms::require_any_cap( array( 'wpcap_checkin' ) );
		Forms::require_any_cap( array( \PassCaptain\Capabilities::UNDO_CHECKIN ) );
		$in = Forms::post();
		Forms::verify( 'wpcap_undo_checkin_' . Forms::int( $in, 'ticket_id' ) );
		// Every form carries its own key: a double submit or a resent POST undoes only once.
		$result = ( '' === Forms::text( $in, 'idempotency_key', 190 )
			? Forms::error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'pass-captain' ), 400 )
			: self::undo_checkin( $in ) );
		$message = ! is_wp_error( $result ) && ! empty( $result['remaining_entries'] )
			? __( 'Letzter Einlass zurückgenommen; der frühere Einlass bleibt gezählt, das Ticket bleibt eingecheckt.', 'pass-captain' )
			: __( 'Check-in zurückgenommen; das Ticket ist wieder gültig.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	/* ------------------------------------------------------------------ A688: bulk check-in / check-out */

	/** Transient of the current user's bulk run: the confirmed selection and its last report. */
	private static function bulk_key() {
		return 'wpcap_bulk_checkin_' . get_current_user_id();
	}

	/** The pending or finished bulk run of the current user (null without one). */
	public static function bulk_state() {
		$state = get_transient( self::bulk_key() );
		return is_array( $state ) && ! empty( $state['batch_key'] ) ? $state : null;
	}

	/**
	 * Step 1: the selected tickets (`ticket_ids[]`, `direction` in|out, optional `area_id`) are checked and
	 * kept for confirmation under a new batch key – nothing is booked yet. Only tickets of dates the user may
	 * admit to; at most BulkCheckin::max().
	 *
	 * @return array|\WP_Error The stored state.
	 */
	public static function bulk_prepare( array $in ) {
		if ( ! current_user_can( 'wpcap_checkin' ) ) { return Forms::forbidden(); }
		$raw = isset( $in['ticket_ids'] ) && is_array( $in['ticket_ids'] ) ? $in['ticket_ids'] : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', array_filter( $raw, 'is_scalar' ) ) ) ) );
		if ( ! $ids ) { return Forms::error( 'wpcap_bulk_empty', __( 'Bitte mindestens ein Ticket auswählen.', 'pass-captain' ), 400 ); }
		if ( count( $ids ) > BulkCheckin::max() ) {
			/* translators: %d: maximum number of tickets */
			return Forms::error( 'wpcap_bulk_size', sprintf( _n( 'Höchstens %d Ticket je Sammelaktion.', 'Höchstens %d Tickets je Sammelaktion.', BulkCheckin::max(), 'pass-captain' ), BulkCheckin::max() ), 400 );
		}
		$tickets = array();
		foreach ( $ids as $id ) {
			$ticket = Queries::ticket_context( $id );
			if ( ! $ticket || ! Access::can_access_occurrence( (int) $ticket->occurrence_id, 'wpcap_checkin', (int) $ticket->event_id ) ) {
				return Forms::forbidden( __( 'Für mindestens ein gewähltes Ticket ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) );
			}
			$tickets[ $id ] = (string) $ticket->public_id;
		}
		$state = array(
			'batch_key' => 'admin-' . wp_generate_uuid4(),
			'direction' => 'out' === Forms::text( $in, 'direction', 8 ) ? 'out' : 'in',
			'area_id'   => Forms::int( $in, 'area_id' ),
			'tickets'   => $tickets,
			'report'    => null,
			'created'   => \PassCaptain\Clock::now(),
		);
		set_transient( self::bulk_key(), $state, HOUR_IN_SECONDS );
		return $state;
	}

	/**
	 * Step 2 (confirmed) and retries: runs the stored selection with its batch key. A retry runs the same
	 * batch key again for the tickets that were not booked because of a temporary error – tickets already
	 * booked by this run are never admitted twice (idempotency key per ticket, BulkCheckin). The report is
	 * kept for the result view.
	 *
	 * @param string $batch_key Key of the confirmation form (must match the stored run).
	 * @param bool   $retry     Only the retryable tickets of the last report.
	 * @return array|\WP_Error Report.
	 */
	public static function bulk_run( $batch_key, $retry = false ) {
		if ( ! current_user_can( 'wpcap_checkin' ) ) { return Forms::forbidden(); }
		$state = self::bulk_state();
		if ( ! $state || ! hash_equals( (string) $state['batch_key'], (string) $batch_key ) ) {
			return Forms::error( 'wpcap_bulk_expired', __( 'Diese Sammelaktion ist abgelaufen oder wurde schon beendet. Bitte die Tickets erneut auswählen.', 'pass-captain' ), 409 );
		}
		$ids = $retry && is_array( $state['report'] ) ? BulkCheckin::retry_ids( $state['report'] ) : array_keys( $state['tickets'] );
		if ( ! $ids ) { return Forms::error( 'wpcap_bulk_nothing', __( 'Es gibt nichts zu wiederholen.', 'pass-captain' ), 409 ); }
		$report = BulkCheckin::run( $ids, $state['direction'], $state['batch_key'], array( 'source' => 'admin', 'key_prefix' => 'admin:', 'area_id' => (int) $state['area_id'] ) );
		if ( is_wp_error( $report ) ) { return $report; }
		if ( $retry && is_array( $state['report'] ) ) {
			// Merge: the retried lines replace their earlier ones.
			$lines = array();
			foreach ( $state['report']['results'] as $row ) { $lines[ (int) $row['ticket_id'] ] = $row; }
			foreach ( $report['results'] as $row ) { $lines[ (int) $row['ticket_id'] ] = $row; }
			$counts = array( 'done' => 0, 'already' => 0, 'refused' => 0, 'retry' => 0 );
			foreach ( $lines as $row ) {
				$bucket = $row['ok'] ? 'done' : ( ! empty( $row['retryable'] ) ? 'retry' : ( in_array( $row['result'], BulkCheckin::ALREADY, true ) ? 'already' : 'refused' ) );
				++$counts[ $bucket ];
			}
			$report = array_merge( $report, array( 'results' => array_values( $lines ), 'counts' => $counts, 'total' => count( $lines ), 'complete' => 0 === $counts['retry'] ) );
		}
		$state['report'] = $report;
		set_transient( self::bulk_key(), $state, HOUR_IN_SECONDS );
		return $report;
	}

	/** admin-post wpcap_bulk_checkin: steps prepare, run, retry, cancel. */
	public static function handle_bulk_checkin() {
		Forms::require_any_cap( array( 'wpcap_checkin' ) );
		$in = Forms::post();
		Forms::verify( 'wpcap_bulk_checkin' );
		$step = Forms::choice( $in, 'step', array( 'prepare', 'run', 'retry', 'cancel' ), 'prepare' );
		$back = self::back_url( $in ) . '#wpcap-bulk-checkin';
		if ( 'cancel' === $step ) {
			delete_transient( self::bulk_key() );
			Forms::finish( true, __( 'Sammelaktion geschlossen.', 'pass-captain' ), $back );
		}
		if ( 'prepare' === $step ) {
			$state = self::bulk_prepare( $in );
			Forms::finish( is_wp_error( $state ) ? $state : true, is_wp_error( $state ) ? '' : __( 'Bitte die Sammelaktion bestätigen.', 'pass-captain' ), $back );
		}
		$report = self::bulk_run( Forms::text( $in, 'batch_key', 80 ), 'retry' === $step );
		if ( is_wp_error( $report ) ) { Forms::finish( $report, '', $back ); }
		/* translators: 1: tickets done, 2: already in that state, 3: refused, 4: to retry */
		Forms::finish( true, sprintf( __( 'Sammelaktion ausgeführt: %1$d erledigt, %2$d bereits im Zielzustand, %3$d abgelehnt, %4$d erneut zu versuchen.', 'pass-captain' ), $report['counts']['done'], $report['counts']['already'], $report['counts']['refused'], $report['counts']['retry'] ), $back );
	}

	/** Confirmation and result report of the current user's bulk run (above the ticket list). */
	private static function render_bulk( array $filters, $page ) {
		$state = self::bulk_state();
		if ( ! $state || ! current_user_can( 'wpcap_checkin' ) ) { return; }
		$in = 'out' === $state['direction'];
		$count = count( $state['tickets'] );
		echo '<div class="notice notice-info inline" id="wpcap-bulk-checkin-state">';
		if ( ! is_array( $state['report'] ) ) {
			echo '<p><strong>' . esc_html( sprintf(
				/* translators: %d: number of tickets */
				$in ? _n( '%d Ticket auschecken?', '%d Tickets auschecken?', $count, 'pass-captain' ) : _n( '%d Ticket einchecken?', '%d Tickets einchecken?', $count, 'pass-captain' ),
				$count
			) ) . '</strong> ' . esc_html__( 'Jedes Ticket wird wie ein Scan am Einlass geprüft (Termin, Bereich, Einlassregel). Bereits verbuchte Tickets werden bei einer Wiederholung nicht doppelt gezählt.', 'pass-captain' ) . '</p>';
			echo '<p><small>' . esc_html( implode( ', ', array_slice( array_values( $state['tickets'] ), 0, 20 ) ) . ( $count > 20 ? ' …' : '' ) ) . '</small></p>';
			self::bulk_form( 'run', $state, $filters, $page, $in ? __( 'Ja, auschecken', 'pass-captain' ) : __( 'Ja, einchecken', 'pass-captain' ), true );
		} else {
			$report = $state['report'];
			echo '<p><strong>' . esc_html__( 'Ergebnis der Sammelaktion', 'pass-captain' ) . '</strong> ';
			/* translators: 1: tickets done, 2: already in that state, 3: refused, 4: to retry */
			echo esc_html( sprintf( __( '%1$d erledigt, %2$d bereits im Zielzustand, %3$d abgelehnt, %4$d erneut zu versuchen.', 'pass-captain' ), $report['counts']['done'], $report['counts']['already'], $report['counts']['refused'], $report['counts']['retry'] ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Ticket', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ergebnis', 'pass-captain' ) . '</th><th>' . esc_html__( 'Hinweis', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $report['results'] as $row ) {
				$label = $row['ok'] ? ( $row['replayed'] ? __( 'erledigt (bereits in diesem Lauf)', 'pass-captain' ) : __( 'erledigt', 'pass-captain' ) ) : ( ! empty( $row['retryable'] ) ? __( 'nicht verbucht – erneut versuchen', 'pass-captain' ) : (string) $row['result'] );
				echo '<tr><td><code>' . esc_html( (string) ( $state['tickets'][ (int) $row['ticket_id'] ] ?? '#' . (int) $row['ticket_id'] ) ) . '</code></td><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $row['message'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $report['counts']['retry'] ) {
				self::bulk_form( 'retry', $state, $filters, $page, __( 'Nicht verbuchte Tickets erneut versuchen', 'pass-captain' ), false );
			}
		}
		self::bulk_form( 'cancel', $state, $filters, $page, is_array( $state['report'] ) ? __( 'Bericht schließen', 'pass-captain' ) : __( 'Abbrechen', 'pass-captain' ), false );
		echo '</div>';
	}

	/** One button form of the bulk run (step run, retry or cancel). */
	private static function bulk_form( $step, array $state, array $filters, $page, $label, $primary ) {
		Forms::open_form( 'wpcap_bulk_checkin', null, 'style="display:inline-block;margin:0 .5em .5em 0"' );
		Forms::hidden( 'step', $step );
		Forms::hidden( 'batch_key', (string) $state['batch_key'] );
		self::return_fields( $filters, $page );
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Selection form of the bulk action (the row checkboxes belong to it through form="…"). */
	private static function render_bulk_selection( array $filters, $page ) {
		if ( ! current_user_can( 'wpcap_checkin' ) ) { return; }
		Forms::open_form( 'wpcap_bulk_checkin', null, 'id="wpcap-bulk-checkin"' );
		Forms::hidden( 'step', 'prepare' );
		self::return_fields( $filters, $page );
		echo '<p><label>' . esc_html__( 'Ausgewählte Tickets', 'pass-captain' ) . ' ';
		Forms::select( 'direction', array( 'in' => __( 'einchecken', 'pass-captain' ), 'out' => __( 'auschecken', 'pass-captain' ) ), 'in' );
		echo '</label> ';
		$areas = $filters['event_id'] ? self::checkin_areas( (int) $filters['event_id'] ) : array();
		if ( $areas ) { Forms::select( 'area_id', array( 0 => __( '– Einlassbereich –', 'pass-captain' ) ) + $areas, 0, '', 'aria-label="' . esc_attr__( 'Einlassbereich', 'pass-captain' ) . '"' ); }
		/* translators: %d: maximum number of tickets */
		echo ' <button type="submit" class="button">' . esc_html__( 'Weiter zur Bestätigung', 'pass-captain' ) . '</button> <span class="description">' . esc_html( sprintf( _n( 'Höchstens %d Ticket je Sammelaktion.', 'Höchstens %d Tickets je Sammelaktion.', BulkCheckin::max(), 'pass-captain' ), BulkCheckin::max() ) ) . '</span></p></form>';
	}

	/**
	 * Ticket search in the current user's scope: events with an event-level assignment plus – for
	 * date-level staff (wpcap_occurrence_staff) – the tickets of their assigned dates only.
	 *
	 * @return array{rows: object[], total: int}
	 */
	public static function search( array $filters, $page = 1, $per_page = 30 ) {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		$dates = null === $scope ? array() : Queries::occurrence_ids_for( self::VIEW_CAPS );
		return Queries::search_tickets( $filters, $scope, $page, $per_page, $dates );
	}

	public static function render() {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		if ( array() === $scope && ! array_filter( self::VIEW_CAPS, 'current_user_can' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 );
		}
		// Events offered in the filter: event-level scope plus events of assigned dates.
		$option_scope = null === $scope ? null : array_values( array_unique( array_merge( $scope, Access::occurrence_event_ids( 'wpcap_checkin' ), Access::occurrence_event_ids( 'wpcap_manage_orders' ) ) ) );
		$filters = self::filters( array(
			's' => Forms::query_arg( 's' ),
			'event_id' => Forms::query_arg( 'event_id', 0 ),
			'status' => Forms::query_arg( 'status' ),
			'occurrence_id' => Forms::query_arg( 'occurrence_id', 0 ),
			'ticket_type_id' => Forms::query_arg( 'ticket_type_id', 0 ),
			'orderby' => Forms::query_arg( 'orderby' ),
		) );
		$has_filter = '' !== $filters['search'] || $filters['event_id'] || '' !== $filters['status'];
		$page = Forms::current_page();
		$per_page = 30;
		$data = $has_filter ? self::search( $filters, $page, $per_page ) : array( 'rows' => array(), 'total' => 0 );
		if ( $has_filter ) {
			// Data minimisation per role and read audit (no search terms in the log).
			$data['rows'] = array_map( array( \PassCaptain\Security\DataPolicy::class, 'ticket_view' ), $data['rows'] );
			\PassCaptain\Security\AuditTrail::read( 'ticket_search', 'event', (int) $filters['event_id'], array( 'results' => count( $data['rows'] ), 'page' => $page, 'scope' => md5( (string) $filters['search'] ) . $page ) );
		}
		if ( $has_filter ) { \PassCaptain\Privacy\AccessLog::list_view( 'ticket_list', $filters + array( 'paged' => $page ), count( $data['rows'] ) ); }
		// A339: rebooking to another date/ticket type straight from the list (exchange module).
		$exchange = class_exists( \PassCaptain\Exchange\AdminPage::class ) && \PassCaptain\Modules::enabled( \PassCaptain\Modules\Exchange::class );
		$status_options = array( '' => __( 'Alle Status', 'pass-captain' ) );
		foreach ( self::STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		?>
		<div class="wrap wpcap-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></h1>
		<?php if ( current_user_can( 'wpcap_checkin' ) ) : ?> <a class="page-title-action" href="<?php echo esc_url( Forms::page_url( ScannerPage::SLUG ) ); ?>"><?php esc_html_e( 'Web-Scanner öffnen', 'pass-captain' ); ?></a><?php endif; ?>
		<hr class="wp-header-end">
		<?php Forms::render_notices(); ?>
		<?php self::render_bulk( $filters, $page ); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="search" name="s" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, Hilfscode, E-Mail oder Name', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, Hilfscode, E-Mail oder Name', 'pass-captain' ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + Queries::event_options( $option_scope, array( (int) $filters['event_id'] ) ), $filters['event_id'], '', Forms::aria_label( __( 'Event', 'pass-captain' ) ) ); ?>
			<?php if ( $filters['event_id'] ) : // A684: date and ticket type of the chosen event. ?>
				<?php Forms::select( 'occurrence_id', array( 0 => __( 'Alle Termine', 'pass-captain' ) ) + self::occurrence_options( (int) $filters['event_id'] ), $filters['occurrence_id'], '', Forms::aria_label( __( 'Termin', 'pass-captain' ) ) ); ?>
				<?php Forms::select( 'ticket_type_id', array( 0 => __( 'Alle Ticketarten', 'pass-captain' ) ) + self::type_options( (int) $filters['event_id'], (int) $filters['occurrence_id'] ), $filters['ticket_type_id'], '', Forms::aria_label( __( 'Ticketart', 'pass-captain' ) ) ); ?>
			<?php endif; ?>
			<?php Forms::select( 'status', $status_options, $filters['status'], '', Forms::aria_label( __( 'Status', 'pass-captain' ) ) ); ?>
			<?php Forms::select( 'orderby', Queries::ticket_orders(), $filters['orderby'], '', Forms::aria_label( __( 'Sortierung', 'pass-captain' ) ) ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Suchen', 'pass-captain' ); ?></button>
			<?php if ( $filters['occurrence_id'] && '' !== \PassCaptain\Printing\AttendeeList::access_level( (int) $filters['occurrence_id'] ) ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_attendee_list', 'occurrence_id' => (int) $filters['occurrence_id'], 'format' => 'csv' ), admin_url( 'admin-post.php' ) ), 'wpcap_attendee_list' ) ); ?>"><?php esc_html_e( 'Teilnehmerliste als CSV', 'pass-captain' ); ?></a>
			<?php endif; ?>
		</form>
		<?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?>
		<?php if ( ! empty( $data['notice'] ) ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( $data['notice'] ); ?></p></div><?php endif; ?>
		<?php if ( ! $has_filter ) : ?>
			<p><?php esc_html_e( 'Bitte nach Ticket, Käufer oder Teilnehmer suchen oder ein Event wählen.', 'pass-captain' ); ?></p>
		<?php else : ?>
			<?php self::render_bulk_selection( $filters, $page ); ?>
			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Auswahl für Sammelaktion', 'pass-captain' ); ?></span></td>
					<th><?php esc_html_e( 'Ticket', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Event / Termin', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Käufer', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Teilnehmer', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $data['rows'] ) : ?><tr><td colspan="7"><?php esc_html_e( 'Keine Tickets gefunden.', 'pass-captain' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $data['rows'] as $ticket ) :
					$email = \PassCaptain\Pii::show( 'attendee_email', '' !== (string) $ticket->assigned_email ? $ticket->assigned_email : $ticket->attendee_email );
					$buyer_email = \PassCaptain\Pii::show( 'buyer_email', $ticket->customer_email );
					$attendee_name = \PassCaptain\Pii::show( 'attendee_name', (string) $ticket->attendee_name );
					$can_orders = Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' );
					$can_checkin = Access::can_access_occurrence( (int) $ticket->occurrence_id, 'wpcap_checkin', (int) $ticket->event_id );
					?>
					<tr>
						<td class="check-column"><?php if ( $can_checkin && in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) ) : // A688: selection for bulk check-in/out. ?><input type="checkbox" name="ticket_ids[]" form="wpcap-bulk-checkin" value="<?php echo esc_attr( (string) (int) $ticket->ticket_id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ticket id */ __( 'Ticket %s auswählen', 'pass-captain' ), $ticket->public_id ) ); ?>"><?php endif; ?></td>
						<td><code><?php echo esc_html( $ticket->public_id ); ?></code><br><small><?php echo esc_html( $ticket->ticket_name ); ?></small></td>
						<td><?php echo esc_html( $ticket->event_name ); ?><br><small><?php echo esc_html( Forms::datetime( $ticket->starts_at, $ticket->timezone ) ); ?></small></td>
						<td>
							<?php if ( $can_orders ) : ?><a href="<?php echo esc_url( OrdersPage::order_url( $ticket->order_id ) ); ?>"><?php echo esc_html( $buyer_email ); ?></a>
							<?php else : ?><?php echo esc_html( $buyer_email ); ?><?php endif; ?>
							<br><small><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'pass-captain' ), \PassCaptain\Frontend\Format::order_reference( $ticket->order_public_id ) ) ); ?></small>
						</td>
						<td><?php echo esc_html( trim( $attendee_name . ' ' . ( $email ? '<' . $email . '>' : '' ) ) ?: '—' ); ?></td>
						<td>
							<?php echo esc_html( Forms::status_label( $ticket->status, 'ticket' ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'pass-captain' ) . ')' : '' ) ); ?>
							<?php if ( $ticket->last_checkin_at ) : ?><br><small><?php echo esc_html( Forms::datetime( $ticket->last_checkin_at, $ticket->timezone, false ) ); ?></small><?php endif; ?>
						</td>
						<td>
							<?php if ( 'valid' === $ticket->status && $can_orders ) : ?>
								<?php Forms::open_form( 'wpcap_revoke_ticket', 'wpcap_revoke_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); self::return_fields( $filters, $page ); Forms::hidden( 'idempotency_key', wp_generate_uuid4() ); ?>
								<input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund', 'pass-captain' ); ?>">
								<label><input type="checkbox" name="release_capacity" value="1"> <?php esc_html_e( 'Platz freigeben', 'pass-captain' ); ?></label>
								<button class="button" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Ticket widerrufen? Es kann danach nicht mehr eingelöst werden. Eine Erstattung erfolgt nicht automatisch.', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Widerrufen', 'pass-captain' ); ?></button>
								</form>
								<?php if ( $exchange ) : ?><p><a class="button" href="<?php echo esc_url( \PassCaptain\Exchange\AdminPage::url( array( 'ticket_id' => (int) $ticket->ticket_id ) ) ); ?>"><?php esc_html_e( 'Umbuchen', 'pass-captain' ); ?></a></p><?php endif; ?>
								<?php Forms::open_form( 'wpcap_reissue_ticket', 'wpcap_reissue_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); self::return_fields( $filters, $page ); ?>
								<button class="button" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Ticket neu ausstellen? Der bisherige Code wird ungültig und der Inhaber erhält einen neuen Ticketlink.', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Neu ausstellen', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) && $can_orders ) : ?>
								<details><summary><?php esc_html_e( 'Teilnehmerdaten korrigieren', 'pass-captain' ); ?></summary>
								<?php Forms::open_form( 'wpcap_correct_attendee', 'wpcap_correct_attendee_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); self::return_fields( $filters, $page ); ?>
								<p><label><?php esc_html_e( 'Name', 'pass-captain' ); ?> <input type="text" name="attendee_name" maxlength="190" value="<?php echo esc_attr( (string) $ticket->attendee_name ); ?>"></label></p>
								<p><label><?php esc_html_e( 'E-Mail', 'pass-captain' ); ?> <input type="email" name="attendee_email" maxlength="190" value="<?php echo esc_attr( (string) $email ); ?>"></label></p>
								<p><input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund der Korrektur', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Grund der Korrektur', 'pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Korrektur speichern', 'pass-captain' ); ?></button></p>
								<p class="description"><?php esc_html_e( 'Nur für Tippfehler derselben Person; der Ticketcode bleibt gültig. Für eine andere Person „Neu ausstellen“ verwenden.', 'pass-captain' ); ?></p>
								</form></details>
							<?php endif; ?>
							<?php if ( 'valid' === $ticket->status && $can_checkin ) : ?>
								<?php
								$areas = self::checkin_areas( (int) $ticket->event_id );
								Forms::open_form( 'wpcap_checkin_ticket', 'wpcap_checkin_ticket_' . $ticket->ticket_id );
								Forms::hidden( 'ticket_id', $ticket->ticket_id );
								self::return_fields( $filters, $page );
								Forms::hidden( 'idempotency_key', wp_generate_uuid4() );
								if ( $areas ) {
									Forms::select( 'area_id', array( 0 => __( '– Einlassbereich –', 'pass-captain' ) ) + $areas, 0, '', 'aria-label="' . esc_attr__( 'Einlassbereich', 'pass-captain' ) . '"' );
								}
								?>
								<button class="button button-primary" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Ticket jetzt einchecken? Das gilt wie ein Scan am Einlass.', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Einchecken', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( 'checked_in' === $ticket->status && $can_checkin && current_user_can( \PassCaptain\Capabilities::UNDO_CHECKIN ) ) : ?>
								<?php Forms::open_form( 'wpcap_undo_checkin', 'wpcap_undo_checkin_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); self::return_fields( $filters, $page ); Forms::hidden( 'idempotency_key', wp_generate_uuid4() ); ?>
								<input type="text" name="reason" maxlength="255" required aria-label="<?php esc_attr_e( 'Grund der Rücknahme', 'pass-captain' ); ?>" placeholder="<?php esc_attr_e( 'Grund (Pflicht)', 'pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Check-in zurücknehmen', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php Forms::pagination( $data['total'], $page, $per_page, Forms::page_url( self::SLUG, array_filter( array( 's' => $filters['search'], 'event_id' => $filters['event_id'], 'occurrence_id' => $filters['occurrence_id'], 'ticket_type_id' => $filters['ticket_type_id'], 'status' => $filters['status'], 'orderby' => 'newest' !== $filters['orderby'] ? $filters['orderby'] : '' ) ) ) ); ?>
		<?php endif; ?>
		</div>
		<?php
	}
}
