<?php
/** Buyer-controlled participant assignment for group orders. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketAssignments {
	public static function assign( $public_id, $access_token, $ticket_public_id, $name, $email, $answers = null ) {
		global $wpdb;
		$public_id = sanitize_text_field( $public_id );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $access_token ) );
		$email = sanitize_email( $email );
		$name = sanitize_text_field( $name );
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! $name || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_assignment_invalid', __( 'Teilnehmerdaten ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$orders = $wpdb->prefix . 'wpcap_orders';
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) { return new \WP_Error( 'wpcap_assignment_denied', __( 'Bestellzugriff ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_assignment_unpaid', __( 'Teilnehmer können erst nach Zahlung zugewiesen werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.status,t.attendee_email,t.transfer_count,e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE", sanitize_text_field( $ticket_public_id ), $order->order_id ) );
		$deadline = ! $ticket ? 0 : strtotime( $ticket->starts_at . ' UTC' ) - max( 0, (int) Settings::get( 'attendee_edit_hours' ) ) * HOUR_IN_SECONDS;
		if ( ! $ticket || 'valid' !== $ticket->status || $deadline <= time() ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_not_allowed', __( 'Dieses Ticket kann nicht mehr zugewiesen werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		// A new holder gets a new code (the previous holder's code stops working); correcting the
		// name or answers for the same person keeps the code that was already mailed.
		$holder = (string) $ticket->attendee_email !== '' ? (string) $ticket->attendee_email : (string) $order->customer_email;
		$rotate = 0 !== strcasecmp( $holder, $email );
		// After a transfer the ticket belongs to the recipient: the buyer's order link must not be able
		// to re-target it (that would rotate the code back and silently invalidate the recipient's ticket).
		if ( (int) $ticket->transfer_count > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_assignment_transferred', __( 'Dieses Ticket wurde bereits an eine andere Person übertragen und kann über die Bestellung nicht mehr geändert werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// Every holder change rotates the code; limit them so assign cannot be used for repeated resale
		// (the transfer itself is limited to one). Name/answer corrections for the same holder stay free.
		if ( $rotate ) {
			$max_changes = max( 0, (int) apply_filters( 'wpcap_max_holder_changes', 1, (int) $ticket->ticket_id ) );
			if ( self::holder_changes( (int) $ticket->ticket_id ) >= $max_changes ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcap_assignment_limit', __( 'Für dieses Ticket wurde der Teilnehmer bereits geändert. Ein weiterer Wechsel der Person ist nicht möglich; Namen und Angaben können weiterhin korrigiert werden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		$code = $rotate ? bin2hex( random_bytes( 32 ) ) : '';
		$update = $rotate ? array( 'attendee_email' => $email, 'token_hash' => hash( 'sha256', $code ) ) : array( 'attendee_email' => $email );
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_tickets', $update, array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid' ), array_fill( 0, count( $update ), '%s' ), array( '%d', '%s' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_failed', __( 'Ticket konnte nicht zugewiesen werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$assignment_table = $wpdb->prefix . 'wpcap_ticket_assignments';
		$wpdb->query( $wpdb->prepare( "INSERT INTO $assignment_table (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE buyer_email=VALUES(buyer_email),attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name),status='assigned'", $ticket->ticket_id, $order->customer_email, $email, $name, current_time( 'mysql', true ) ) );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_failed', __( 'Teilnehmerdaten konnten nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		if ( is_array( $answers ) ) {
			$type_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket->ticket_id ) );
			$valid = Orders::validate_attendees( array( $type_id => 1 ), array( array( 'ticket_type_id' => $type_id, 'answers' => $answers ) ) );
			if ( is_wp_error( $valid ) ) { $wpdb->query( 'ROLLBACK' ); return $valid; }
			$now = current_time( 'mysql', true );
			foreach ( (array) ( $valid[0]['answers'] ?? array() ) as $field_id => $value ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE answer=VALUES(answer),updated_at=VALUES(updated_at)", $ticket->ticket_id, absint( $field_id ), (string) $value, $now, $now ) );
			}
		}
		if ( $rotate ) {
			// Counted inside the transaction (row lock on the ticket), see holder_changes().
			Audit::record( 'ticket_holder_changed', 'ticket', $ticket->ticket_id, array( 'order_id' => (int) $order->order_id, 'source' => 'assign' ) );
		}
		$wpdb->query( 'COMMIT' );
		if ( $rotate ) {
			do_action( 'wpcap_ticket_code_rotated', (int) $ticket->ticket_id, $code, 'assign' );
			$url = add_query_arg( 'wpcap_ticket', $code, home_url( '/' ) );
			$when = Orders::format_start( $ticket->starts_at, $ticket->timezone );
			$vars = array(
				'order_id' => (string) $order->public_id,
				'event_name' => (string) $ticket->event_name,
				'ticket_name' => (string) $ticket->ticket_name,
				'event_date' => $when,
				'event_location' => (string) $ticket->location,
				'attendee_name' => $name,
				'attendee_email' => $email,
				'ticket_link' => Mail\Mailer::link( $url ),
				'ticket_extras' => Mail\Blocks::extra( (string) apply_filters( 'wpcap_ticket_code_mail_section', '', (int) $ticket->ticket_id ) ),
			);
			Mail\Mailer::send( 'ticket_assigned_attendee', $email, $vars, array(), (string) $order->locale );
			if ( 0 !== strcasecmp( (string) $order->customer_email, $email ) ) {
				// Tell the buyer that the previously mailed code for this ticket is no longer valid.
				Mail\Mailer::send( 'ticket_assigned_buyer', $order->customer_email, $vars, array(), (string) $order->locale );
			}
		}
		Audit::record( 'ticket_assigned', 'ticket', $ticket->ticket_id, array( 'order_id' => (int) $order->order_id ) );
		return array( 'status' => 'assigned', 'code_rotated' => $rotate );
	}

	/** Number of holder changes (code rotations) a ticket already had through assign(). */
	public static function holder_changes( $ticket_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE object_type='ticket' AND object_id=%d AND action='ticket_holder_changed'", absint( $ticket_id ) ) );
	}
}
