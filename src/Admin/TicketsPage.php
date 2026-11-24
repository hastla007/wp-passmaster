<?php
/**
 * Tickets admin: search, revoke or reissue a valid ticket, change its holder, check a ticket in manually, undo a check-in.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Admission\BulkCheckin;
use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Privacy\AccessLog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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
	 * same person: the ticket code stays valid (a change of person uses "Inhaber ändern", change_holder()). Requires
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

	/**
	 * PASS-FE-03: changes the holder of a valid ticket to another person (Orders::change_ticket_holder(), the
	 * same effect as a buyer transfer: new code, previous link and wallet pass invalid). Requires
	 * wpcap_manage_orders for the ticket's event, unmasked personal data of the order (support staff with
	 * masked data cannot change the holder) and a reason; audited as ticket_transferred (source admin).
	 */
	public static function change_holder( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( ! self::can_change_holder( (int) $ticket->order_id ) ) {
			return Forms::forbidden( __( 'Die personenbezogenen Daten dieser Bestellung sind für dich maskiert; der Inhaber kann deshalb nicht geändert werden.', 'pass-captain' ) );
		}
		$notify = Forms::flag( $in, 'notify' );
		$result = \PassCaptain\Orders::change_ticket_holder( (int) $ticket->ticket_id, $in['attendee_email'] ?? '', $in['attendee_name'] ?? '', array( 'reason' => Forms::text( $in, 'reason', 255 ), 'notify' => $notify ) );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return Forms::error( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 409 ) );
		}
		return $result + array( 'notify' => $notify );
	}

	/** Whether the current user sees the order's personal data unmasked (required for a holder change, PASS-FE-03). */
	private static function can_change_holder( $order_id ) {
		return ! \PassCaptain\Security\DataPolicy::masks_pii( (int) $order_id ) && \PassCaptain\Pii::can_view( 'attendee_email' ) && \PassCaptain\Pii::can_view( 'attendee_name' );
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	/**
	 * List filters from request values (A684): search, event, date, ticket type, status and sort order; ids of
	 * dates/types only together with their event, unknown values dropped. A411: "order" (asc/desc) of a
	 * sorted column header.
	 *
	 * PASS-FE-05: "type_name" filters by the name of a ticket type over all dates of the event (only together
	 * with an event and without a single ticket type).
	 *
	 * @param array $q Raw values (s, event_id, occurrence_id, ticket_type_id, type_name, status, orderby, order).
	 * @return array{search:string,event_id:int,occurrence_id:int,ticket_type_id:int,type_name:string,status:string,orderby:string,order:string}
	 */
	public static function filters( array $q ) {
		$event = absint( $q['event_id'] ?? 0 );
		$status = (string) ( $q['status'] ?? '' );
		$orderby = (string) ( $q['orderby'] ?? '' );
		$order = strtolower( (string) ( $q['order'] ?? '' ) );
		// A411: the sortable ticket column links orderby=ticket with a direction (newest/oldest).
		if ( 'ticket' === $orderby ) { $orderby = 'asc' === $order ? 'oldest' : 'newest'; }
		$orderby = array_key_exists( $orderby, Queries::ticket_orders() ) ? $orderby : 'newest';
		$type_id = $event ? absint( $q['ticket_type_id'] ?? 0 ) : 0;
		return array(
			'search' => trim( sanitize_text_field( (string) ( $q['s'] ?? '' ) ) ),
			'event_id' => $event,
			'occurrence_id' => $event ? absint( $q['occurrence_id'] ?? 0 ) : 0,
			'ticket_type_id' => $type_id,
			'type_name' => $event && ! $type_id ? mb_substr( trim( sanitize_text_field( (string) ( $q['type_name'] ?? '' ) ) ), 0, 190 ) : '',
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'orderby' => $orderby,
			// Only the date order has a second direction (sortable date column); "" = the order's default.
			'order' => 'date' === $orderby && 'desc' === $order ? 'desc' : '',
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

	/**
	 * PASS-FE-05: ticket type names of an event over all its dates (name => label), for the filter across dates.
	 *
	 * @return array<string,string>
	 */
	public static function type_name_options( $event_id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT tt.name FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d ORDER BY tt.name LIMIT 300', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ) ) ) as $name ) {
			/* translators: %s: ticket type name */
			$out[ (string) $name ] = sprintf( __( '%s (alle Termine)', 'pass-captain' ), (string) $name );
		}
		return $out;
	}

	/**
	 * PASS-FE-05: ticket type and status of the list as filter of the attendee CSV (type_name, status). A chosen
	 * ticket type of one date is passed by its name; the attendee list holds valid and checked-in tickets only,
	 * other statuses are not passed.
	 *
	 * @return array<string,string>
	 */
	public static function csv_filter_args( array $filters ) {
		global $wpdb;
		$name = (string) ( $filters['type_name'] ?? '' );
		if ( ! empty( $filters['ticket_type_id'] ) ) {
			$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', absint( $filters['ticket_type_id'] ) ) );
		}
		$status = (string) ( $filters['status'] ?? '' );
		return array_filter( array(
			'type_name' => $name,
			'status' => in_array( $status, array( 'valid', 'checked_in' ), true ) ? $status : '',
		) );
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
			'type_name' => Forms::text( $in, 'return_type_name', 190 ),
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'orderby' => array_key_exists( $orderby, Queries::ticket_orders() ) && 'newest' !== $orderby ? $orderby : '',
			'order' => 'date' === $orderby && 'desc' === Forms::text( $in, 'return_order', 4 ) ? 'desc' : '',
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
		Forms::hidden( 'return_type_name', (string) ( $filters['type_name'] ?? '' ) );
		Forms::hidden( 'return_orderby', (string) ( $filters['orderby'] ?? '' ) );
		Forms::hidden( 'return_order', (string) ( $filters['order'] ?? '' ) );
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

	public static function handle_change_holder() {
		Forms::require_any_cap( array( 'wpcap_manage_orders' ) ); // Capability before the nonce.
		$in = Forms::post();
		Forms::verify( 'wpcap_change_holder_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::change_holder( $in );
		if ( is_wp_error( $result ) || empty( $result['notify'] ) ) {
			$message = __( 'Inhaber geändert. Der bisherige Code ist ungültig; die neue Person wurde nicht benachrichtigt.', 'pass-captain' );
		} else {
			$message = empty( $result['mailed'] )
				? __( 'Inhaber geändert. Die E-Mail an die neue Person konnte nicht zugestellt werden; bitte „Neu ausstellen“ verwenden.', 'pass-captain' )
				: __( 'Inhaber geändert und das Ticket an die neue Person gesendet. Der bisherige Code ist ungültig.', 'pass-captain' );
		}
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

	/* ------------------------------------------------------------------ A411: list table */

	/** Screen option (user meta) with the number of tickets per page. */
	const PER_PAGE_OPTION = 'wpcap_tickets_per_page';
	const PER_PAGE_DEFAULT = 30;

	/** GET form the page-number field of the list table submits (the rows hold their own action forms). */
	const PAGING_FORM = 'wpcap-tickets-paging';

	/** @var array<int,array> Visible values and permissions per ticket of the rendered page. */
	private static $row_context = array();

	/** List filters from the query string (A684 filters plus the header sort direction, A411). */
	public static function list_filters() {
		return self::filters( array(
			's' => Forms::query_arg( 's' ),
			'event_id' => Forms::query_arg( 'event_id', 0 ),
			'status' => Forms::query_arg( 'status' ),
			'occurrence_id' => Forms::query_arg( 'occurrence_id', 0 ),
			'ticket_type_id' => Forms::query_arg( 'ticket_type_id', 0 ),
			'type_name' => Forms::query_arg( 'type_name' ),
			'orderby' => Forms::query_arg( 'orderby' ),
			'order' => Forms::query_arg( 'order' ),
		) );
	}

	/** Query arguments of the list state (views, pagination, page-number form). */
	public static function list_args( array $filters ) {
		return array_filter( array(
			's' => (string) ( $filters['search'] ?? '' ),
			'event_id' => (int) ( $filters['event_id'] ?? 0 ),
			'occurrence_id' => (int) ( $filters['occurrence_id'] ?? 0 ),
			'ticket_type_id' => (int) ( $filters['ticket_type_id'] ?? 0 ),
			'type_name' => (string) ( $filters['type_name'] ?? '' ),
			'status' => (string) ( $filters['status'] ?? '' ),
			'orderby' => 'newest' !== ( $filters['orderby'] ?? 'newest' ) ? (string) $filters['orderby'] : '',
			'order' => (string) ( $filters['order'] ?? '' ),
		) );
	}

	/** Columns of the list (without the checkbox column): key => label. */
	public static function list_columns() {
		return array(
			'ticket'   => __( 'Ticket', 'pass-captain' ),
			'event'    => __( 'Event / Termin', 'pass-captain' ),
			'buyer'    => __( 'Käufer', 'pass-captain' ),
			'attendee' => __( 'Teilnehmer', 'pass-captain' ),
			'status'   => __( 'Status', 'pass-captain' ),
			'actions'  => __( 'Aktion', 'pass-captain' ),
		);
	}

	/** Accessible caption of the list. */
	public static function list_caption() {
		return __( 'Gefundene Tickets mit Event, Käufer, Teilnehmer, Status und Aktionen', 'pass-captain' );
	}

	/**
	 * Sortable column and direction the column headers show for the current sort order (A411): ticket
	 * number (newest/oldest, primary key) and date (indexed start of the date). Other orders of the sort
	 * select have no column.
	 *
	 * @return array{0:string,1:string} [orderby, order] or ['', ''].
	 */
	public static function header_sort( array $filters ) {
		switch ( (string) ( $filters['orderby'] ?? 'newest' ) ) {
			case 'newest':
				return array( 'ticket', 'desc' );
			case 'oldest':
				return array( 'ticket', 'asc' );
			case 'date':
				return array( 'date', 'desc' === ( $filters['order'] ?? '' ) ? 'desc' : 'asc' );
		}
		return array( '', '' );
	}

	/**
	 * Status views above the list (quick filters): key => [label, url, current]. Search, event, date,
	 * ticket type and sort order are kept; the status is replaced.
	 */
	public static function list_views( array $filters ) {
		$keep = self::list_args( $filters );
		unset( $keep['status'] );
		$current = (string) ( $filters['status'] ?? '' );
		$out = array( 'all' => array( 'label' => __( 'Alle', 'pass-captain' ), 'url' => Forms::page_url( self::SLUG, $keep ), 'current' => '' === $current ) );
		foreach ( self::STATUSES as $status ) {
			$out[ $status ] = array( 'label' => Forms::status_label( $status, 'ticket' ), 'url' => Forms::page_url( self::SLUG, $keep + array( 'status' => $status ) ), 'current' => $status === $current );
		}
		return $out;
	}

	/** Whether the list has a filter (without one it asks for a search instead of listing every ticket). */
	public static function has_filter( array $filters ) {
		return '' !== $filters['search'] || $filters['event_id'] || '' !== $filters['status'];
	}

	/**
	 * One page of the list with the data minimisation of the user's role applied and the read logged
	 * once (no search terms in the log).
	 *
	 * @return array{rows: object[], total: int, notice?: string|null}
	 */
	public static function list_data( array $filters, $page, $per_page ) {
		$page = max( 1, (int) $page );
		$data = self::search( $filters, $page, ListTables::clamp_per_page( $per_page ) );
		// Data minimisation per role and read audit (no search terms in the log).
		$data['rows'] = array_map( array( \PassCaptain\Security\DataPolicy::class, 'ticket_view' ), $data['rows'] );
		\PassCaptain\Security\AuditTrail::read( 'ticket_search', 'event', (int) $filters['event_id'], array( 'results' => count( $data['rows'] ), 'page' => $page, 'scope' => md5( (string) $filters['search'] ) . $page ) );
		\PassCaptain\Privacy\AccessLog::list_view( 'ticket_list', $filters + array( 'paged' => $page ), count( $data['rows'] ) );
		self::$row_context = array();
		return $data;
	}

	/** Visible contact data and permissions of a list row (decided once per ticket). */
	private static function row_context( $ticket ) {
		$id = (int) $ticket->ticket_id;
		if ( ! isset( self::$row_context[ $id ] ) ) {
			// Gate staff: DataPolicy::ticket_view() removes contact fields, so read them defensively.
			self::$row_context[ $id ] = array(
				'email' => \PassCaptain\Pii::show( 'attendee_email', '' !== (string) ( $ticket->assigned_email ?? '' ) ? (string) $ticket->assigned_email : (string) ( $ticket->attendee_email ?? '' ) ),
				'buyer_email' => \PassCaptain\Pii::show( 'buyer_email', (string) ( $ticket->customer_email ?? '' ) ),
				'attendee_name' => \PassCaptain\Pii::show( 'attendee_name', (string) ( $ticket->attendee_name ?? '' ) ),
				'can_orders' => Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ),
				'can_holder' => self::can_change_holder( (int) ( $ticket->order_id ?? 0 ) ),
				'can_checkin' => Access::can_access_occurrence( (int) $ticket->occurrence_id, 'wpcap_checkin', (int) $ticket->event_id ),
			);
		}
		return self::$row_context[ $id ];
	}

	/** Checkbox of a row for the bulk check-in/out (A688; belongs to its form through form="…"), or ''. */
	public static function checkbox_cell( $ticket ) {
		$ctx = self::row_context( $ticket );
		if ( ! $ctx['can_checkin'] || ! in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) ) { return ''; }
		/* translators: %s: ticket id */
		return '<input type="checkbox" name="ticket_ids[]" form="wpcap-bulk-checkin" value="' . esc_attr( (string) (int) $ticket->ticket_id ) . '" aria-label="' . esc_attr( sprintf( __( 'Ticket %s auswählen', 'pass-captain' ), $ticket->public_id ) ) . '">';
	}

	/**
	 * HTML of one cell of the list (escaped).
	 *
	 * @param string $column   Column key.
	 * @param object $ticket   Row (after DataPolicy::ticket_view()).
	 * @param array  $filters  List filters (carried into the action forms, A414).
	 * @param int    $page     Current page.
	 * @param bool   $exchange Whether the exchange module offers "Umbuchen".
	 */
	public static function list_cell( $column, $ticket, array $filters, $page, $exchange ) {
		$ctx = self::row_context( $ticket );
		switch ( $column ) {
			case 'ticket':
				return '<code>' . esc_html( $ticket->public_id ) . '</code><br><small>' . esc_html( $ticket->ticket_name ) . '</small>';
			case 'event':
				return esc_html( $ticket->event_name ) . '<br><small>' . esc_html( Forms::datetime( $ticket->starts_at, $ticket->timezone ) ) . '</small>';
			case 'buyer':
				$buyer = $ctx['can_orders'] ? '<a href="' . esc_url( OrdersPage::order_url( $ticket->order_id ) ) . '">' . esc_html( $ctx['buyer_email'] ) . '</a>' : esc_html( $ctx['buyer_email'] );
				/* translators: %s: short order number */
				return $buyer . '<br><small>' . esc_html( sprintf( __( 'Bestellung %s', 'pass-captain' ), \PassCaptain\Frontend\Format::order_reference( $ticket->order_public_id ) ) ) . '</small>';
			case 'attendee':
				return esc_html( trim( $ctx['attendee_name'] . ' ' . ( $ctx['email'] ? '<' . $ctx['email'] . '>' : '' ) ) ?: '—' );
			case 'status':
				$html = esc_html( Forms::status_label( $ticket->status, 'ticket' ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'pass-captain' ) . ')' : '' ) );
				return $html . ( $ticket->last_checkin_at ? '<br><small>' . esc_html( Forms::datetime( $ticket->last_checkin_at, $ticket->timezone, false ) ) . '</small>' : '' );
			case 'actions':
				ob_start();
				self::render_actions( $ticket, $filters, $page, $exchange );
				return (string) ob_get_clean();
		}
		return '';
	}

	/** Action forms of one ticket (revoke, rebook, reissue, holder change, correction, check-in, undo); each carries the list state (A414). */
	private static function render_actions( $ticket, array $filters, $page, $exchange ) {
		$ctx = self::row_context( $ticket );
		$email = $ctx['email'];
		$can_orders = $ctx['can_orders'];
		$can_checkin = $ctx['can_checkin'];
		?>
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
			<?php if ( $ctx['can_holder'] ) : // PASS-FE-03: another person, same effect as a buyer transfer. ?>
				<details><summary><?php esc_html_e( 'Inhaber ändern', 'pass-captain' ); ?></summary>
				<?php Forms::open_form( 'wpcap_change_holder', 'wpcap_change_holder_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); self::return_fields( $filters, $page ); ?>
				<p><label><?php esc_html_e( 'Name der neuen Person', 'pass-captain' ); ?> <input type="text" name="attendee_name" required maxlength="190"></label></p>
				<p><label><?php esc_html_e( 'E-Mail der neuen Person', 'pass-captain' ); ?> <input type="email" name="attendee_email" required maxlength="190"></label></p>
				<p><label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Ticket an die neue Person senden (die Käufer:in erhält einen Hinweis)', 'pass-captain' ); ?></label></p>
				<p><input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund des Inhaberwechsels', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Grund des Inhaberwechsels', 'pass-captain' ); ?>">
				<button class="button" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Inhaber wirklich ändern? Der bisherige Code und Ticketlink werden sofort ungültig; über die Bestellung lässt sich das Ticket danach nicht mehr übertragen.', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Inhaber ändern', 'pass-captain' ); ?></button></p>
				</form></details>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) && $can_orders ) : ?>
			<details><summary><?php esc_html_e( 'Teilnehmerdaten korrigieren', 'pass-captain' ); ?></summary>
			<?php Forms::open_form( 'wpcap_correct_attendee', 'wpcap_correct_attendee_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); self::return_fields( $filters, $page ); ?>
			<p><label><?php esc_html_e( 'Name', 'pass-captain' ); ?> <input type="text" name="attendee_name" maxlength="190" value="<?php echo esc_attr( (string) ( $ticket->attendee_name ?? '' ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'E-Mail', 'pass-captain' ); ?> <input type="email" name="attendee_email" maxlength="190" value="<?php echo esc_attr( (string) $email ); ?>"></label></p>
			<p><input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund der Korrektur', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Grund der Korrektur', 'pass-captain' ); ?>">
			<button class="button" type="submit"><?php esc_html_e( 'Korrektur speichern', 'pass-captain' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Nur für Tippfehler derselben Person; der Ticketcode bleibt gültig. Für eine andere Person „Inhaber ändern“ verwenden.', 'pass-captain' ); ?></p>
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
		<?php
	}

	/** Filter set_screen_option_{option}: stores the tickets per page (1–200). */
	public static function save_per_page( $keep, $option, $value ) {
		return self::PER_PAGE_OPTION === $option ? ListTables::clamp_per_page( $value ) : $keep;
	}

	/** load-{hook} of the tickets page: screen option "Tickets pro Seite" (no bulk action of its own; A688 has its own form). */
	public static function load_screen() {
		ListTables::add_per_page_option( __( 'Tickets pro Seite', 'pass-captain' ), self::PER_PAGE_OPTION, self::PER_PAGE_DEFAULT );
	}

	public static function render() {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		if ( array() === $scope && ! array_filter( self::VIEW_CAPS, 'current_user_can' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 );
		}
		// Events offered in the filter: event-level scope plus events of assigned dates.
		$option_scope = null === $scope ? null : array_values( array_unique( array_merge( $scope, Access::occurrence_event_ids( 'wpcap_checkin' ), Access::occurrence_event_ids( 'wpcap_manage_orders' ) ) ) );
		$filters = self::list_filters();
		$has_filter = self::has_filter( $filters );
		$page = Forms::current_page();
		// A411: WP_List_Table with the page size of the screen option; the plain table keeps the default.
		$table = $has_filter && ListTables::available() ? new TicketsListTable() : null;
		$per_page = $table ? $table->per_page() : self::PER_PAGE_DEFAULT;
		$data = $has_filter ? self::list_data( $filters, $page, $per_page ) : array( 'rows' => array(), 'total' => 0 );
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
				<?php if ( ! $filters['occurrence_id'] ) { Forms::select( 'type_name', array( '' => __( 'Ticketart über alle Termine', 'pass-captain' ) ) + self::type_name_options( (int) $filters['event_id'] ), $filters['type_name'], '', Forms::aria_label( __( 'Ticketart über alle Termine', 'pass-captain' ) ) ); } // PASS-FE-05. ?>
			<?php endif; ?>
			<?php Forms::select( 'status', $status_options, $filters['status'], '', Forms::aria_label( __( 'Status', 'pass-captain' ) ) ); ?>
			<?php Forms::select( 'orderby', Queries::ticket_orders(), $filters['orderby'], '', Forms::aria_label( __( 'Sortierung', 'pass-captain' ) ) ); ?>
			<?php if ( '' !== $filters['order'] ) { Forms::hidden( 'order', $filters['order'] ); } // A411: direction of the sorted column. ?>
			<button class="button" type="submit"><?php esc_html_e( 'Suchen', 'pass-captain' ); ?></button>
			<?php if ( $filters['occurrence_id'] && '' !== \PassCaptain\Printing\AttendeeList::access_level( (int) $filters['occurrence_id'] ) ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_attendee_list', 'occurrence_id' => (int) $filters['occurrence_id'], 'format' => 'csv' ) + self::csv_filter_args( $filters ), admin_url( 'admin-post.php' ) ), 'wpcap_attendee_list' ) ); ?>"><?php esc_html_e( 'Teilnehmerliste als CSV', 'pass-captain' ); ?></a>
			<?php endif; ?>
			<?php if ( $filters['event_id'] && ! $filters['occurrence_id'] && '' !== \PassCaptain\Printing\AttendeeList::event_scope( (int) $filters['event_id'] )['level'] ) : // PASS-FE-05. ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_attendee_list', 'event_id' => (int) $filters['event_id'], 'format' => 'csv' ) + self::csv_filter_args( $filters ), admin_url( 'admin-post.php' ) ), 'wpcap_attendee_list' ) ); ?>"><?php esc_html_e( 'Teilnehmerliste als CSV (alle Termine des Events)', 'pass-captain' ); ?></a>
			<?php endif; ?>
		</form>
		<?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?>
		<?php if ( ! empty( $data['notice'] ) ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( $data['notice'] ); ?></p></div><?php endif; ?>
		<?php if ( ! $has_filter ) : ?>
			<p><?php esc_html_e( 'Bitte nach Ticket, Käufer oder Teilnehmer suchen oder ein Event wählen.', 'pass-captain' ); ?></p>
		<?php else : ?>
			<?php self::render_bulk_selection( $filters, $page ); ?>
			<?php
			if ( $table ) {
				$table->load( $filters, $page, $data, $per_page, $exchange );
				$table->views();
				$table->display();
				ListTables::paging_form( self::PAGING_FORM, self::SLUG, self::list_args( $filters ) );
			} else {
				ListTables::render_views( self::list_views( $filters ) );
				self::render_plain_list( $data, $filters, $page, $exchange );
				Forms::pagination( $data['total'], $page, self::PER_PAGE_DEFAULT, Forms::page_url( self::SLUG, self::list_args( $filters ) ) );
			}
			?>
		<?php endif; ?>
		</div>
		<?php
	}

	/** Ticket list without WP_List_Table: the former plain table with the same cells. */
	private static function render_plain_list( array $data, array $filters, $page, $exchange ) {
		?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php echo esc_html( self::list_caption() ); ?></caption>
			<thead><tr>
				<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Auswahl für Sammelaktion', 'pass-captain' ); ?></span></td>
				<?php foreach ( self::list_columns() as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?><tr><td colspan="<?php echo count( self::list_columns() ) + 1; ?>"><?php esc_html_e( 'Keine Tickets gefunden.', 'pass-captain' ); ?></td></tr><?php endif; ?>
			<?php
			foreach ( $data['rows'] as $ticket ) {
				echo '<tr><td class="check-column">' . self::checkbox_cell( $ticket ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in checkbox_cell().
				foreach ( array_keys( self::list_columns() ) as $column ) {
					echo '<td>' . self::list_cell( $column, $ticket, $filters, $page, $exchange ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in list_cell().
				}
				echo '</tr>';
			}
			?>
			</tbody>
		</table>
		<?php
	}
}
