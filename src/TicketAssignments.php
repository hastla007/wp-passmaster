<?php
/** Buyer-controlled participant assignment for group orders. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketAssignments {
	/** Longest attendee name (ticket_assignments.attendee_name is varchar(190), as in the checkout). */
	const NAME_MAX = 190;

	public static function assign( $public_id, $access_token, $ticket_public_id, $name, $email, $answers = null ) {
		global $wpdb;
		$public_id = sanitize_text_field( Http::str( $public_id ) );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', Http::str( $access_token ) ) );
		$email = Http::email( $email );
		// A635: limited like the checkout (190 characters); the name goes into a mail to a freely chosen
		// address, so it must not carry a link.
		$name = Text::limit( sanitize_text_field( Http::str( $name ) ), self::NAME_MAX );
		if ( self::has_link( $name ) ) {
			return new \WP_Error( 'wpcap_assignment_invalid', __( 'Der Name darf keine Web-Adresse enthalten.', 'pass-captain' ), array( 'status' => 400, 'reason' => 'name_link', 'field' => 'attendee_name' ) );
		}
		if ( Http::email_too_long( $email ) ) {
			return new \WP_Error( 'wpcap_assignment_invalid', Orders::email_too_long_message(), array( 'status' => 400, 'reason' => 'email_too_long', 'field' => 'attendee_email' ) );
		}
		// The attendee e-mail is optional (as in the checkout): without one, only name and answers
		// are corrected and the ticket code stays.
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! $name || ( '' !== $email && ! is_email( $email ) ) ) { return new \WP_Error( 'wpcap_assignment_invalid', __( 'Teilnehmerdaten ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$orders = $wpdb->prefix . 'wpcap_orders';
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) { return new \WP_Error( 'wpcap_assignment_denied', __( 'Bestellzugriff ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_assignment_unpaid', __( 'Teilnehmer können erst nach Zahlung zugewiesen werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		// Order row first (same lock order as refund requests and transfers): a cancellation request
		// cannot slip in between the open-refund check below and the holder change (A496).
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM $orders WHERE order_id=%d FOR UPDATE", (int) $order->order_id ) );
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.status,t.attendee_email,t.transfer_count,e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE", sanitize_text_field( $ticket_public_id ), $order->order_id ) );
		$deadline = ! $ticket ? 0 : strtotime( $ticket->starts_at . ' UTC' ) - max( 0, (int) Settings::get( 'attendee_edit_hours' ) ) * HOUR_IN_SECONDS;
		if ( ! $ticket || 'valid' !== $ticket->status || $deadline <= time() ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_not_allowed', __( 'Dieses Ticket kann nicht mehr zugewiesen werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		// A new holder gets a new code (the previous holder's code stops working); correcting the
		// name or answers for the same person keeps the code that was already mailed.
		$holder = (string) $ticket->attendee_email !== '' ? (string) $ticket->attendee_email : (string) $order->customer_email;
		if ( '' === $email ) { $email = (string) $ticket->attendee_email; } // Keep the current holder.
		$rotate = '' !== $email && 0 !== strcasecmp( $holder, $email );
		// After a transfer the ticket belongs to the recipient: the buyer's order link must not be able
		// to re-target it (that would rotate the code back and silently invalidate the recipient's ticket).
		if ( (int) $ticket->transfer_count > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_assignment_transferred', __( 'Dieses Ticket wurde bereits an eine andere Person übertragen und kann über die Bestellung nicht mehr geändert werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		// Every holder change rotates the code; limit them so assign cannot be used for repeated resale
		// (the transfer itself is limited to one). Name/answer corrections for the same holder stay free.
		if ( $rotate ) {
			// A496: while a refund of the ticket is pending, approved or running, the holder stays – otherwise
			// the new holder loses the ticket when the refund is paid out to the buyer. Name/answer
			// corrections for the same holder stay possible.
			$open_refund = Refunds::ticket_has_open_refund( (int) $order->order_id, (int) $ticket->ticket_id );
			if ( false !== $open_refund ) {
				$wpdb->query( 'ROLLBACK' );
				return null === $open_refund
					? Errors::busy( __( 'Die Teilnehmerdaten konnten gerade nicht gespeichert werden. Bitte erneut versuchen.', 'pass-captain' ) )
					: new \WP_Error( 'wpcap_ticket_refund_open', __( 'Für dieses Ticket läuft eine Erstattung oder sie wartet auf Freigabe. Die Person kann bis zur Entscheidung nicht geändert werden; Namen und Angaben lassen sich weiterhin korrigieren.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			// A593: a new holder gets a new code – not once admission has begun or the code is on offline scanners.
			$locked = Admission\CodeRotationLock::reason( (int) $ticket->ticket_id );
			if ( '' !== $locked ) {
				$wpdb->query( 'ROLLBACK' );
				return Admission\CodeRotationLock::error( $locked );
			}
			$max_changes = max( 0, (int) apply_filters( 'wpcap_max_holder_changes', 1, (int) $ticket->ticket_id ) );
			if ( self::holder_changes( (int) $ticket->ticket_id ) >= $max_changes ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcap_assignment_limit', __( 'Für dieses Ticket wurde der Teilnehmer bereits geändert. Ein weiterer Wechsel der Person ist nicht möglich; Namen und Angaben können weiterhin korrigiert werden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		$code = $rotate ? bin2hex( random_bytes( 32 ) ) : '';
		$update = $rotate ? array( 'attendee_email' => $email, 'token_hash' => hash( 'sha256', $code ) ) : array( 'attendee_email' => $email );
		if ( $rotate ) { CodeHistory::replacing( (int) $ticket->ticket_id, 'assign' ); } // A543
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_tickets', $update, array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid' ), array_fill( 0, count( $update ), '%s' ), array( '%d', '%s' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_failed', __( 'Ticket konnte nicht zugewiesen werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$assignment_table = $wpdb->prefix . 'wpcap_ticket_assignments';
		$wpdb->query( $wpdb->prepare( "INSERT INTO $assignment_table (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE buyer_email=VALUES(buyer_email),attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name),status='assigned'", $ticket->ticket_id, $order->customer_email, $email, $name, current_time( 'mysql', true ) ) );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_failed', __( 'Teilnehmerdaten konnten nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		if ( is_array( $answers ) ) {
			$type_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket->ticket_id ) );
			// Consents are granted/withdrawn separately (Consents, logged); this form corrects the other answers.
			$valid = Orders::validate_attendees( array( $type_id => 1 ), array( array( 'ticket_type_id' => $type_id, 'answers' => $answers ) ), array( 'skip_consent' => true ) );
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
			// Another person holds the ticket now: the previous holder's consents end (logged).
			Consents::end_for_ticket( (int) $ticket->ticket_id, 'holder_change', $holder );
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
				'ticket_extras' => Mail\Blocks::extra( Orders::code_mail_section( (int) $ticket->ticket_id, (string) $order->locale ) ),
			);
			Mail\Mailer::send( 'ticket_assigned_attendee', $email, $vars, array(), (string) $order->locale, (int) $order->order_id );
			if ( 0 !== strcasecmp( (string) $order->customer_email, $email ) ) {
				// Tell the buyer that the previously mailed code for this ticket is no longer valid.
				Mail\Mailer::send( 'ticket_assigned_buyer', $order->customer_email, $vars, array(), (string) $order->locale, (int) $order->order_id );
			}
		}
		if ( ! $rotate ) {
			/**
			 * Attendee name/answers of a ticket were corrected for the same holder (code unchanged), A582.
			 *
			 * @param int    $ticket_id
			 * @param string $source    'assign' (order portal).
			 */
			do_action( 'wpcap_ticket_attendee_updated', (int) $ticket->ticket_id, 'assign' );
		}
		Audit::record( 'ticket_assigned', 'ticket', $ticket->ticket_id, array( 'order_id' => (int) $order->order_id ) );
		return array( 'status' => 'assigned', 'code_rotated' => $rotate );
	}

	/**
	 * Whether an attendee name contains a web address (scheme, "www." or a host with a common path/TLD
	 * pattern such as "example.com/x"). Filter `wpcap_attendee_name_has_link`.
	 *
	 * @param string $name Attendee name.
	 */
	public static function has_link( $name ) {
		$name = (string) $name;
		$found = 1 === preg_match( '~(?:[a-z][a-z0-9+.\-]*://|\bwww\.|\b[a-z0-9\-]+\.[a-z]{2,}/)~i', $name );
		/**
		 * Whether the attendee name of a portal assignment counts as containing a link (refused, A635).
		 *
		 * @param bool   $found Default: scheme://, www. or host/path.
		 * @param string $name  Name.
		 */
		return (bool) apply_filters( 'wpcap_attendee_name_has_link', $found, $name );
	}

	/** Number of holder changes (code rotations) a ticket already had through assign(). */
	public static function holder_changes( $ticket_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE object_type='ticket' AND object_id=%d AND action='ticket_holder_changed'", absint( $ticket_id ) ) );
	}
}
