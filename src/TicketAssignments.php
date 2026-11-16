<?php
/**
 * Buyer-controlled participant assignment for group orders (order portal) and attendee data of the
 * current holder on the ticket page (A570).
 *
 * Holder changes – another e-mail address (new code) or another person's name (A569) – are limited by
 * `wpcap_max_holder_changes` (default 1, a transfer counts as well); every change of the name, small
 * corrections included, by `wpcap_max_attendee_name_changes` (default 3). Both are counted on the
 * ticket (wpcap_tickets.holder_changes / name_changes, schema 3.10.18) and in the audit log.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketAssignments {
	/** Longest attendee name (ticket_assignments.attendee_name is varchar(190), as in the checkout). */
	const NAME_MAX = 190;

	public static function assign( $public_id, $access_token, $ticket_public_id, $name, $email, $answers = null ) {
		global $wpdb;
		$public_id = sanitize_text_field( Http::str( $public_id ) );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', Http::str( $access_token ) ) );
		$email_raw = $email;
		$email = Http::optional_email( $email_raw );
		// A635: limited like the checkout (190 characters); the name goes into a mail to a freely chosen
		// address, so it must not carry a link.
		$name = Text::limit( sanitize_text_field( Http::str( $name ) ), self::NAME_MAX );
		if ( self::has_link( $name ) ) {
			return new \WP_Error( 'wpcap_assignment_invalid', __( 'Der Name darf keine Web-Adresse enthalten.', 'pass-captain' ), array( 'status' => 400, 'reason' => 'name_link', 'field' => 'attendee_name' ) );
		}
		if ( Http::email_too_long( Http::email( $email_raw ) ) ) {
			return new \WP_Error( 'wpcap_assignment_invalid', Orders::email_too_long_message(), array( 'status' => 400, 'reason' => 'email_too_long', 'field' => 'attendee_email' ) );
		}
		// The attendee e-mail is optional (as in the checkout): without one, only name and answers
		// are corrected and the ticket code stays.
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! $name || false === $email ) { return new \WP_Error( 'wpcap_assignment_invalid', __( 'Teilnehmerdaten ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$orders = $wpdb->prefix . 'wpcap_orders';
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) { return new \WP_Error( 'wpcap_assignment_denied', __( 'Bestellzugriff ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_assignment_unpaid', __( 'Teilnehmer können erst nach Zahlung zugewiesen werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		// Order row first (same lock order as refund requests and transfers): a cancellation request
		// cannot slip in between the open-refund check below and the holder change (A496).
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM $orders WHERE order_id=%d FOR UPDATE", (int) $order->order_id ) );
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.status,t.attendee_email,t.transfer_count,(SELECT a.attendee_name FROM {$wpdb->prefix}wpcap_ticket_assignments a WHERE a.ticket_id=t.ticket_id) AS previous_name,e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE", sanitize_text_field( $ticket_public_id ), $order->order_id ) );
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
		// (the transfer itself is limited to one). A change of the name to another person counts as well
		// (A569, name_change() below); answers and small name corrections for the same holder stay free.
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
		// A569: a change of the name to another person counts like a change of the e-mail address (the
		// code stays, but the ticket is personalised to someone else); small corrections stay free, but
		// every name change is counted on the ticket and limited as well.
		$renamed = self::name_change( (int) $ticket->ticket_id, (string) $ticket->previous_name, $name, $rotate, 0 );
		if ( is_wp_error( $renamed ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $renamed;
		}
		$code = $rotate ? bin2hex( random_bytes( 32 ) ) : '';
		$update = $rotate ? array( 'attendee_email' => $email, 'token_hash' => hash( 'sha256', $code ) ) : array( 'attendee_email' => $email );
		if ( $rotate ) { CodeHistory::replacing( (int) $ticket->ticket_id, 'assign' ); } // A543
		if ( $rotate && TicketCodes::holder_ready() ) {
			// A581: the new code is mailed to $email (ticket_assigned_attendee below).
			$update['code_with_attendee'] = TicketCodes::to_attendee( $email, (string) $order->customer_email );
		}
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
		// Counted inside the transaction (row lock on the ticket), see holder_changes() and name_changes().
		self::count_changes( (int) $ticket->ticket_id, $rotate || $renamed['person'], $renamed['renamed'], array( 'order_id' => (int) $order->order_id, 'source' => 'assign', 'by' => $rotate ? 'email' : 'name' ) );
		self::touch( array( (int) $ticket->ticket_id ) ); // A574: name/answers live outside wpcap_tickets.
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

	/**
	 * Marks tickets as changed (wpcap_tickets.updated_at, schema 3.10.22) after a change of attendee
	 * data that lives outside the ticket row (name, field answers), so incremental readers such as the
	 * partner API's "since" filter see it (A574). Changes of the ticket row itself update the column
	 * on their own (ON UPDATE CURRENT_TIMESTAMP).
	 *
	 * @param int[] $ticket_ids
	 */
	public static function touch( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ids || ! ServiceBookings::has_column( 'wpcap_tickets', 'updated_at' ) ) { return; }
		$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_tickets SET updated_at=CURRENT_TIMESTAMP WHERE ticket_id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** Whether the change counters exist on wpcap_tickets (schema 3.10.18, A569; deferred upgrade). */
	public static function counters_ready() {
		return ServiceBookings::has_column( 'wpcap_tickets', 'holder_changes' ) && ServiceBookings::has_column( 'wpcap_tickets', 'name_changes' );
	}

	/**
	 * Number of holder changes a ticket already had (assignment to another e-mail address or another
	 * person's name, A569). Counted on the ticket (wpcap_tickets.holder_changes, schema 3.10.18), so an
	 * audit-log purge no longer resets it; before that upgrade the audit entries are counted.
	 */
	public static function holder_changes( $ticket_id ) {
		global $wpdb;
		if ( self::counters_ready() ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT holder_changes FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE object_type='ticket' AND object_id=%d AND action='ticket_holder_changed'", absint( $ticket_id ) ) );
	}

	/** Number of changes of the attendee name of a ticket (corrections included, A569). */
	public static function name_changes( $ticket_id ) {
		global $wpdb;
		if ( self::counters_ready() ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT name_changes FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE object_type='ticket' AND object_id=%d AND action='ticket_attendee_renamed'", absint( $ticket_id ) ) );
	}

	/** Name for comparisons: lower case, single spaces, words sorted ("Muster Max" = "max muster"). */
	private static function name_key( $name ) {
		$words = preg_split( '/\s+/u', mb_strtolower( trim( (string) $name ) ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();
		sort( $words );
		return implode( ' ', $words );
	}

	/**
	 * Whether a new attendee name is only a correction of the previous one (A569): the first name of a
	 * ticket, the same name in another spelling (case, spaces, word order) or a small typo fix (at most
	 * `wpcap_attendee_name_correction_distance` edits, default 2). Anything else is another person.
	 */
	public static function is_name_correction( $before, $after ) {
		$a = self::name_key( $before );
		$b = self::name_key( $after );
		if ( '' === $a || $a === $b ) { return true; }
		/**
		 * Edits (Levenshtein distance of the normalised names) up to which a name change counts as a
		 * correction for the same person, not as a change of the holder (A569).
		 *
		 * @param int $distance Default 2.
		 */
		$max = max( 0, (int) apply_filters( 'wpcap_attendee_name_correction_distance', 2 ) );
		return strlen( $a ) <= 255 && strlen( $b ) <= 255 && levenshtein( $a, $b ) <= $max;
	}

	/**
	 * Checks a name change against the limits (A569). Call under the ticket row lock.
	 *
	 * @param int    $ticket_id
	 * @param string $before          Current attendee name ('' = none yet).
	 * @param string $after           New attendee name.
	 * @param bool   $holder_changing Whether the e-mail address (holder) changes in the same step (that
	 *                                change is limited by the caller).
	 * @param int    $extra_changes   Holder changes not counted on the ticket (e.g. a transfer).
	 * @return array|\WP_Error ['renamed' => bool (name differs), 'person' => bool (name of another person)]
	 */
	public static function name_change( $ticket_id, $before, $after, $holder_changing = false, $extra_changes = 0 ) {
		$renamed = '' !== self::name_key( $before ) && self::name_key( $before ) !== self::name_key( $after );
		$person = $renamed && ! self::is_name_correction( $before, $after );
		if ( $renamed ) {
			/**
			 * Maximum number of changes of the attendee name of a ticket (corrections included) through
			 * the order portal and the ticket page (A569). Staff corrections are not limited.
			 *
			 * @param int $max       Default 3.
			 * @param int $ticket_id
			 */
			$max_names = max( 0, (int) apply_filters( 'wpcap_max_attendee_name_changes', 3, (int) $ticket_id ) );
			if ( self::name_changes( $ticket_id ) >= $max_names ) {
				return new \WP_Error( 'wpcap_assignment_name_limit', __( 'Der Teilnehmername dieses Tickets wurde bereits mehrfach geändert. Weitere Änderungen sind nur über den Veranstalter möglich.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		if ( $person && ! $holder_changing ) {
			$max_changes = max( 0, (int) apply_filters( 'wpcap_max_holder_changes', 1, (int) $ticket_id ) );
			if ( self::holder_changes( $ticket_id ) + max( 0, (int) $extra_changes ) >= $max_changes ) {
				return new \WP_Error( 'wpcap_assignment_limit', __( 'Für dieses Ticket wurde der Teilnehmer bereits geändert. Ein weiterer Wechsel der Person ist nicht möglich; kleine Korrekturen des Namens (Tippfehler) und die Angaben bleiben möglich.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		return array(
			'renamed' => $renamed,
			'person' => $person,
		);
	}

	/**
	 * Counts a holder change and/or a name change on the ticket and in the audit log (A569). Call inside
	 * the transaction that holds the ticket row lock.
	 *
	 * @param int   $ticket_id
	 * @param bool  $holder  Holder changed (other e-mail address or another person's name).
	 * @param bool  $renamed Attendee name changed.
	 * @param array $details Audit details.
	 */
	public static function count_changes( $ticket_id, $holder, $renamed, array $details = array() ) {
		global $wpdb;
		if ( ! $holder && ! $renamed ) { return; }
		if ( self::counters_ready() ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET holder_changes=LEAST(holder_changes+%d,255),name_changes=LEAST(name_changes+%d,255) WHERE ticket_id=%d", $holder ? 1 : 0, $renamed ? 1 : 0, absint( $ticket_id ) ) );
		}
		if ( $holder ) { Audit::record( 'ticket_holder_changed', 'ticket', absint( $ticket_id ), $details ); }
		if ( $renamed ) { Audit::record( 'ticket_attendee_renamed', 'ticket', absint( $ticket_id ), $details ); }
	}

	/**
	 * A570: the current holder of a ticket (whoever has its code, also after a transfer, when the
	 * buyer's order link can no longer change it) sets or corrects the attendee name and field answers
	 * on the ticket page. Same deadline as the order portal (`attendee_edit_hours` before the start),
	 * only for valid tickets of paid orders; the e-mail address and the code stay. Name changes follow
	 * the limits of name_change(); a transfer counts as the holder change already made.
	 *
	 * @param string     $ticket_token Plaintext ticket code (the ticket page link).
	 * @param string     $name         Attendee name (required).
	 * @param array|null $answers      field_key => answer (consent fields are handled separately).
	 * @return array|\WP_Error ['status' => 'saved'|'unchanged']
	 */
	public static function update_by_holder( $ticket_token, $name, $answers = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_token = strtolower( (string) preg_replace( '/[^a-f0-9]/', '', (string) $ticket_token ) );
		$name = mb_substr( sanitize_text_field( (string) $name ), 0, 190 );
		if ( 64 !== strlen( $ticket_token ) ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( '' === $name ) { return new \WP_Error( 'wpcap_assignment_invalid', __( 'Bitte den Namen der teilnehmenden Person angeben.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return Errors::busy(); }
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.order_id,t.ticket_type_id,t.status,t.refunded,t.attendee_email,t.transfer_count,o.starts_at FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.token_hash=%s FOR UPDATE", hash( 'sha256', $ticket_token ) ) );
		$order = $ticket ? $wpdb->get_row( $wpdb->prepare( "SELECT order_id,customer_email,payment_status FROM {$p}wpcap_orders WHERE order_id=%d", (int) $ticket->order_id ) ) : null;
		$deadline = $ticket ? strtotime( $ticket->starts_at . ' UTC' ) - max( 0, (int) Settings::get( 'attendee_edit_hours' ) ) * HOUR_IN_SECONDS : 0;
		if ( ! $ticket || ! $order || 'paid' !== $order->payment_status || 'valid' !== $ticket->status || (int) $ticket->refunded || $deadline <= time() ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_assignment_not_allowed', __( 'Die Teilnehmerdaten dieses Tickets können nicht mehr geändert werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$before = (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", (int) $ticket->ticket_id ) );
		$renamed = self::name_change( (int) $ticket->ticket_id, $before, $name, false, (int) $ticket->transfer_count );
		if ( is_wp_error( $renamed ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $renamed;
		}
		$clean = array();
		if ( is_array( $answers ) ) {
			$valid = Orders::validate_attendees( array( (int) $ticket->ticket_type_id => 1 ), array( array( 'ticket_type_id' => (int) $ticket->ticket_type_id, 'answers' => $answers ) ), array( 'skip_consent' => true ) );
			if ( is_wp_error( $valid ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $valid;
			}
			$clean = (array) ( $valid[0]['answers'] ?? array() );
		}
		$current = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_id,answer FROM {$p}wpcap_participant_answers WHERE ticket_id=%d", (int) $ticket->ticket_id ) ) as $row ) {
			$current[ (int) $row->field_id ] = (string) $row->answer;
		}
		$answers_changed = false;
		foreach ( $clean as $field_id => $value ) {
			if ( ( $current[ (int) $field_id ] ?? '' ) !== (string) $value ) { $answers_changed = true; }
		}
		if ( $before === $name && ! $answers_changed ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'status' => 'unchanged' );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_name=VALUES(attendee_name)", (int) $ticket->ticket_id, (string) $order->customer_email, (string) $ticket->attendee_email, $name, $now ) );
		foreach ( $clean as $field_id => $value ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE answer=VALUES(answer),updated_at=VALUES(updated_at)", (int) $ticket->ticket_id, absint( $field_id ), (string) $value, $now, $now ) );
		}
		if ( '' !== $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_assignment_failed', __( 'Teilnehmerdaten konnten nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		self::count_changes( (int) $ticket->ticket_id, $renamed['person'], $renamed['renamed'], array( 'order_id' => (int) $order->order_id, 'source' => 'ticket_page', 'by' => 'name' ) );
		self::touch( array( (int) $ticket->ticket_id ) );
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return Errors::busy();
		}
		Audit::record( 'ticket_attendee_updated', 'ticket', (int) $ticket->ticket_id, array( 'order_id' => (int) $order->order_id, 'source' => 'ticket_page' ) );
		/** Documented at assign(): attendee data corrected for the same holder (code unchanged). */
		do_action( 'wpcap_ticket_attendee_updated', (int) $ticket->ticket_id, 'ticket_page' );
		return array( 'status' => 'saved' );
	}
}
