<?php
/**
 * Resending and reissuing tickets (code rotation per recipient).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Admission;
use PassCaptain\Audit;
use PassCaptain\Clock;
use PassCaptain\CodeHistory;
use PassCaptain\Errors;
use PassCaptain\HelpCode;
use PassCaptain\Jobs;
use PassCaptain\Log;
use PassCaptain\Mail;
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;
use PassCaptain\TicketCodes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::resend_tickets(), mail_retry_payload(), resend_counts() and
 * reissue_ticket(), with the optimistic code rotation per recipient. Moved out of Orders without behaviour
 * change (TODO "Gott-Klasse Orders"). rotate_and_send() is public only for the outbox retry in
 * Orders\Issuance.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Resend {
	/**
	 * Resends valid tickets of a paid order, one mail per holder (attendee e-mail, else the buyer).
	 * Checked-in tickets are skipped: they are used, and rotating them would only invalidate the
	 * holder's admission history link.
	 *
	 * Rotation and mail run per recipient in rotate_and_send(): the new codes are prepared without
	 * locks, mailed (with the ticket PDF) and only then stored by a short compare-and-set transaction
	 * on each previous hash. A failed mail changes nothing, so no old code is invalidated without the
	 * new one being sent; no row lock is held while SMTP or wallet signing runs. A failed mail
	 * schedules a `ticket_mail_retry` job (A042) that keeps the scope of this resend (A335).
	 *
	 * A588: with keep_codes, a ticket whose current code is known (TicketCodes, the wallet module;
	 * filter wpcap_ticket_known_code, see PortalSelfService::current_code()) is sent again with that
	 * same code – nothing already delivered, printed or added to a wallet becomes invalid; only tickets
	 * without a known code get a new one. The retry job always keeps codes.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $options  only_unassigned (bool): only tickets the buyer holds (no own participant
	 *                        e-mail, not transferred) – the portal offers this so a buyer does not
	 *                        invalidate tickets already passed on to other people;
	 *                        skip_transferred (bool): tickets passed on to someone else (transfer) keep
	 *                        their code (A068, buyer self-service);
	 *                        self_service (bool, A593): tickets whose admission has begun or whose code is
	 *                        on offline scanners (Admission\CodeRotationLock) are left alone;
	 *                        keep_codes (bool, A588, see above);
	 *                        request_key (string, A533): one resend per form submission (double click);
	 *                        from_job (bool, internal: the retry job reschedules itself).
	 * @return true|\WP_Error
	 */
	public static function resend_tickets( $order_id, array $options = array() ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$busy = static function () { return Errors::busy( __( 'Die Tickets konnten gerade nicht erneuert werden. Bitte erneut versuchen.', 'pass-captain' ) ); };
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', $order_id ) );
		if ( ! $order && '' !== $wpdb->last_error ) { return $busy(); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_unpaid', __( 'Tickets können erst nach bestätigter Zahlung erneut gesendet werden.', 'pass-captain' ) );
		}
		$request_marker = '';
		$request_key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $options['request_key'] ?? '' ) );
		if ( '' !== $request_key ) {
			// A533: one resend per form submission. Checked and recorded under the order lock, so a double
			// click (two requests) cannot rotate the codes twice; a failed resend frees the key again.
			// 48 hex characters: a full 64-hex hash would be masked in the audit details (Security\Redact).
			$marker = substr( hash( 'sha256', 'resend|' . $order_id . '|' . $request_key ), 0, 48 );
			if ( false === Transaction::begin() ) { return $busy(); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_orders', $order_id ) );
			$seen = '' === $wpdb->last_error ? $wpdb->get_row( $wpdb->prepare( "SELECT SUM(action='tickets_resend_request') AS requested,SUM(action='tickets_resend_request_failed') AS failed FROM %i WHERE object_type='order' AND object_id=%d AND action IN ('tickets_resend_request','tickets_resend_request_failed') AND details LIKE %s", $wpdb->prefix . 'wpcap_audit_logs', $order_id, '%' . $wpdb->esc_like( $marker ) . '%' ) ) : null;
			if ( null === $seen || '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return $busy();
			}
			if ( (int) $seen->requested > (int) $seen->failed ) {
				$wpdb->query( 'COMMIT' );
				return new \WP_Error( 'wpcap_resend_duplicate', __( 'Dieses Formular wurde bereits abgeschickt; die Tickets wurden schon erneut gesendet. Für einen weiteren Versand die Seite neu laden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			Audit::record( 'tickets_resend_request', 'order', $order_id, array( 'request' => $marker ) );
			if ( '' !== $wpdb->last_error || false === Transaction::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $busy();
			}
			$request_marker = $marker;
		}
		$scope = array(
			'only_unassigned' => ! empty( $options['only_unassigned'] ),
			'skip_transferred' => ! empty( $options['skip_transferred'] ),
			'self_service' => ! empty( $options['self_service'] ),
			'keep_codes' => ! empty( $options['keep_codes'] ),
		);
		$result = self::rotate_and_send( $order, null, 'resend', false, $scope );
		if ( is_wp_error( $result ) ) {
			if ( '' !== $request_marker ) { \PassCaptain\Log::error( 'tickets_resend_request_failed', 'tickets resend request failed', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'request' => $request_marker ) ) ); }
			if ( 'wpcap_mail_failed' === $result->get_error_code() ) {
				\PassCaptain\Log::error( 'ticket_mail_failed', 'ticket mail failed', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'resend' => true, 'sent' => (int) ( $result->get_error_data()['sent'] ?? 0 ) ) ) );
				if ( empty( $options['from_job'] ) ) {
					// A335: the retry keeps the scope of this resend, so it never rotates the codes of tickets
					// passed on to someone else when this resend left them alone. A588: it sends known codes again.
					Jobs::enqueue( 'ticket-mail-retry|' . $order_id . '|' . Clock::now(), 'ticket_mail_retry', self::mail_retry_payload( $order_id, $scope['skip_transferred'], $scope['only_unassigned'] ), gmdate( 'Y-m-d H:i:s', Clock::now() + 5 * MINUTE_IN_SECONDS ) );
				}
			}
			return $result;
		}
		Audit::record( 'tickets_resent', 'order', $order_id, array( 'ticket_count' => (int) $result ) + array_filter( array( 'only_unassigned' => $scope['only_unassigned'], 'skip_transferred' => $scope['skip_transferred'], 'kept_codes' => self::$last_kept_codes ) ) );
		return true;
	}

	/** Tickets of the last rotate_and_send() that were mailed with their known code (A588). */
	private static $last_kept_codes = 0;

	/**
	 * Rotates the codes of valid tickets of an order and mails the new links, per recipient.
	 *
	 * @param object     $order      Order row (paid).
	 * @param int[]|null $ticket_ids Restrict to these tickets; null = all valid tickets of the order.
	 * @param string     $reason     Passed to wpcap_ticket_code_rotated (resend, delivery, delivery_retry).
	 * @param bool       $to_buyer   Mail every ticket to the buyer (initial delivery) instead of its holder.
	 * @param array      $options    hashes (ticket_id => expected current code hash; others are skipped),
	 *                               mail ('tickets' = ticket mail of the first delivery, default
	 *                               'tickets_resend'), only_unassigned, skip_transferred, self_service,
	 *                               keep_codes (bool, see resend_tickets()).
	 * @return int|\WP_Error Number of tickets mailed (rotated or with their kept code). On a partial
	 *                       failure the error carries `sent` (tickets mailed to other recipients); the
	 *                       failed recipient's codes are unchanged.
	 */
	public static function rotate_and_send( $order, $ticket_ids, $reason, $to_buyer, array $options = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		self::$last_kept_codes = 0;
		$filter = '';
		if ( null !== $ticket_ids ) {
			$ticket_ids = array_values( array_filter( array_map( 'absint', (array) $ticket_ids ) ) );
			$filter = $ticket_ids ? ' AND ticket_id IN (' . implode( ',', $ticket_ids ) . ')' : ' AND 1=0';
		}
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,attendee_email,transfer_count,token_hash FROM {$p}wpcap_tickets WHERE order_id=%d AND status='valid'{$filter} ORDER BY ticket_id", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
		}
		$hashes = (array) ( $options['hashes'] ?? array() );
		$candidates = array_values( array_filter( (array) $candidates, static function ( $ticket ) use ( $order, $hashes, $options ) {
			if ( $hashes && ( ! isset( $hashes[ (int) $ticket->ticket_id ] ) || ! hash_equals( (string) $hashes[ (int) $ticket->ticket_id ], (string) $ticket->token_hash ) ) ) {
				return false; // Code changed since issuance: its holder already got a newer one.
			}
			if ( ! empty( $options['skip_transferred'] ) && (int) $ticket->transfer_count > 0 ) {
				return false; // Passed on to someone else: keeps its code (A068).
			}
			if ( ! empty( $options['only_unassigned'] ) ) {
				return 0 === (int) $ticket->transfer_count && ( ! is_email( $ticket->attendee_email ) || 0 === strcasecmp( (string) $ticket->attendee_email, (string) $order->customer_email ) );
			}
			return true;
		} ) );
		if ( $candidates && ! empty( $options['self_service'] ) ) {
			// A593: the buyer's own resend leaves tickets alone whose admission has begun or whose code
			// is on offline scanners (Admission\CodeRotationLock); staff resends are not limited.
			$locked = '';
			$candidates = array_values( array_filter( $candidates, static function ( $ticket ) use ( &$locked ) {
				$why = Admission\CodeRotationLock::reason( (int) $ticket->ticket_id );
				if ( '' !== $why && '' === $locked ) { $locked = $why; }
				return '' === $why;
			} ) );
			if ( ! $candidates && '' !== $locked ) { return Admission\CodeRotationLock::error( $locked ); }
		}
		if ( ! $candidates ) {
			return new \WP_Error( 'wpcap_no_resendable_tickets', __( 'Keine gültigen Tickets für den erneuten Versand gefunden.', 'pass-captain' ) );
		}
		$groups = array();
		foreach ( $candidates as $ticket ) {
			$groups[ self::code_recipient( $order, $ticket, $to_buyer ) ][ (int) $ticket->ticket_id ] = (string) $ticket->token_hash;
		}
		$sent = 0;
		$error = null;
		foreach ( $groups as $recipient => $expected ) {
			$result = self::rotate_and_send_group( $order, (string) $recipient, $expected, $reason, $to_buyer, (string) ( $options['mail'] ?? 'tickets_resend' ), ! empty( $options['keep_codes'] ) );
			if ( is_wp_error( $result ) ) {
				$error = $error ?: $result;
				continue;
			}
			$sent += $result;
		}
		if ( $error ) {
			$data = (array) $error->get_error_data();
			$data['sent'] = $sent;
			return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		}
		return $sent;
	}

	/** Recipient of a ticket's code mail: the holder (attendee e-mail), else the buyer. */
	private static function code_recipient( $order, $ticket, $to_buyer ) {
		return ! $to_buyer && is_email( $ticket->attendee_email ) ? (string) $ticket->attendee_email : (string) $order->customer_email;
	}

	/**
	 * One recipient of rotate_and_send(), optimistic:
	 * 1. prepare: read the tickets (no lock), keep those whose code is still the expected one and whose
	 *    holder is still this recipient, create new codes (with keep_codes: the known current code);
	 * 2. mail the links (ticket PDF attached up to the size limit, A621 attachments of modules) –
	 *    nothing is stored yet, so a failed mail leaves every old code valid;
	 * 3. store: one short transaction with a compare-and-set per ticket
	 *    (UPDATE … WHERE ticket_id=? AND status='valid' AND token_hash=<old>). A ticket checked in or
	 *    rotated by someone else in the meantime is skipped (its old state wins; the link in this
	 *    mail stays dead); a database error rolls back and the old codes stay valid. The replaced code
	 *    is recorded (CodeHistory, A543) and who received the code (code_with_attendee, A581);
	 * 4. after the commit: wpcap_ticket_code_rotated per ticket (wallet passes of the new generation,
	 *    outside any row lock). For resend mails, wallet links produced by it follow in a second
	 *    short mail (the first mail went out before the codes existed in the database).
	 *
	 * @param array  $expected   ticket_id => code hash read by the caller.
	 * @param string $mail       tickets (first delivery: ticket mail incl. wpcap_ticket_mail filter) or tickets_resend.
	 * @param bool   $keep_codes A588: send known current codes again instead of new ones.
	 * @return int|\WP_Error Number of mailed tickets (0 when none is left for this recipient).
	 */
	private static function rotate_and_send_group( $order, $recipient, array $expected, $reason, $to_buyer, $mail = 'tickets_resend', $keep_codes = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rotation_failed = __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' );
		$retry = static function ( $code, $message, $step ) { return new \WP_Error( $code, $message, array( 'status' => 503, 'retryable' => true, 'step' => $step ) ); };
		$ids = array_map( 'absint', array_keys( $expected ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.ticket_type_id,t.token_hash,t.attendee_email,COALESCE(ta.attendee_name,'') AS attendee_name,tt.occurrence_id,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status='valid' AND t.ticket_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY t.ticket_id', array_merge( array( $p . 'wpcap_tickets', $p . 'wpcap_ticket_assignments', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) $order->order_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== $wpdb->last_error ) { return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'read' ); }
		$plan = array();
		foreach ( (array) $rows as $row ) {
			// The code or the holder changed meanwhile (transfer/assignment/resend mailed its own new code): skip.
			if ( ! hash_equals( (string) $expected[ (int) $row->ticket_id ], (string) $row->token_hash ) || self::code_recipient( $order, $row, $to_buyer ) !== $recipient ) { continue; }
			$known = $keep_codes ? PortalSelfService::current_code( (int) $row->ticket_id ) : '';
			$plan[ (int) $row->ticket_id ] = array( 'old' => (string) $row->token_hash, 'token' => '' !== $known ? $known : bin2hex( random_bytes( 32 ) ), 'keep' => '' !== $known, 'row' => $row );
		}
		if ( ! $plan ) { return 0; }
		$items = array();
		foreach ( $plan as $ticket_id => $step ) {
			$row = $step['row'];
			$items[] = array( 'ticket_id' => $ticket_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'occurrence_id' => (int) $row->occurrence_id, 'token' => $step['token'], 'event' => $row->event_name, 'ticket' => $row->ticket_name, 'starts_at' => $row->starts_at, 'timezone' => $row->timezone, 'location' => $row->location, 'attendee' => (string) $row->attendee_name, 'help_code' => $step['keep'] ? HelpCode::for_ticket( $ticket_id, $step['token'] ) : HelpCode::preview( $ticket_id, $step['token'] ) ); // New codes: the help code they get on commit (shown in the first mail).
		}
		if ( 'tickets' === $mail ) {
			$sent = Issuance::send_ticket_mail( $order, $items );
		} else {
			$sent = self::send_code_mail( $order, $recipient, $items, false );
		}
		if ( ! $sent ) {
			return $retry( 'wpcap_mail_failed', __( 'Mindestens eine E-Mail konnte nicht zugestellt werden. Die bisherigen Ticketcodes bleiben gültig; der Versand wird automatisch wiederholt.', 'pass-captain' ), 'mail' );
		}
		// Short compare-and-set transaction: only now do the new codes replace the old ones.
		if ( false === Transaction::begin() ) { return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'start' ); }
		$holder_ready = TicketCodes::holder_ready();
		$holder = TicketCodes::to_attendee( $recipient, (string) $order->customer_email );
		$rotated = array();
		$kept = 0;
		foreach ( $plan as $ticket_id => $step ) {
			$set = $step['keep'] ? '' : $wpdb->prepare( 'token_hash=%s', hash( 'sha256', $step['token'] ) );
			if ( $holder_ready ) { $set .= ( '' !== $set ? ',' : '' ) . $wpdb->prepare( 'code_with_attendee=%d', $holder ); }
			if ( '' === $set ) { ++$kept; continue; }
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET {$set} WHERE ticket_id=%d AND status='valid' AND token_hash=%s", $p . 'wpcap_tickets', $ticket_id, $step['old'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared assignments.
			if ( false === $updated ) {
				// The mail went out but its codes were not stored: the old codes stay valid; a retry sends working ones.
				$wpdb->query( 'ROLLBACK' );
				return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'rotate' );
			}
			if ( $step['keep'] ) { ++$kept; continue; }
			if ( 1 === (int) $updated ) {
				CodeHistory::replaced( $ticket_id, $step['old'], 'resend' === $reason ? 'resend' : 'other' ); // A543.
				$rotated[ $ticket_id ] = $step['token'];
			}
		}
		if ( false === Transaction::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $retry( 'wpcap_ticket_rotation_failed', $rotation_failed, 'commit' );
		}
		self::$last_kept_codes += $kept;
		if ( count( $rotated ) + $kept < count( $plan ) ) {
			Log::warning( 'ticket_rotation_conflict', 'Tickets changed while their new codes were mailed; their previous state was kept.', array( 'object_type' => 'order', 'object_id' => (int) $order->order_id, 'details' => array( 'tickets' => array_values( array_diff( array_keys( $plan ), array_keys( $rotated ) ) ), 'reason' => sanitize_key( $reason ) ) ) );
		}
		foreach ( $rotated as $ticket_id => $token ) {
			/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
			do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, $reason );
		}
		if ( 'tickets' !== $mail && $rotated ) {
			// Wallet links (and other sections) for the new codes exist only now: send them in a short second mail.
			$extra = array_values( array_filter( $items, static function ( $item ) use ( $rotated ) { return isset( $rotated[ $item['ticket_id'] ] ); } ) );
			self::send_code_mail( $order, $recipient, $extra, true );
		}
		return count( $rotated ) + $kept;
	}

	/**
	 * Mail with ticket links (type tickets_resend), ticket PDF attached up to the size limit, plus the
	 * attachments modules add through `wpcap_ticket_resend_attachments` (A621: .ics, .pkpass).
	 *
	 * @param array $items       Items with ticket_id, token, event, ticket, starts_at, timezone, location
	 *                           (and ticket_type_id, occurrence_id, attendee, help_code).
	 * @param bool  $extras_only Only tickets with a section from wpcap_ticket_code_mail_section (wallet
	 *                           links after the rotation); nothing is sent when there is none.
	 * @return bool Whether the mail was accepted (true when $extras_only found nothing to send).
	 */
	private static function send_code_mail( $order, $recipient, array $items, $extras_only ) {
		$entries = array();
		foreach ( $items as $item ) {
			$help = (string) ( $item['help_code'] ?? '' );
			$extra = $extras_only ? Orders::code_mail_section( (int) $item['ticket_id'], (string) $order->locale ) : ( '' !== $help ? HelpCode::label( $help ) . "\n" : '' );
			// The help code is part of the first mail already; the short second mail only carries what exists after the commit (wallet links).
			if ( $extras_only && '' !== $help ) { $extra = str_replace( HelpCode::label( $help ) . "\n", '', $extra ); }
			if ( $extras_only && '' === trim( $extra ) ) { continue; }
			$entries[] = array( 'event' => $item['event'], 'ticket' => $item['ticket'], 'when' => Orders::format_start( $item['starts_at'], $item['timezone'] ), 'location' => $item['location'], 'url' => add_query_arg( 'wpcap_ticket', $item['token'], home_url( '/' ) ), 'extra' => $extra );
		}
		if ( ! $entries ) { return true; }
		$placeholder = Mail\Mailer::is_placeholder( (string) $recipient );
		$pdf = ( $extras_only || $placeholder ) ? array( 'files' => array(), 'dir' => '', 'notice' => '' ) : Mail\TicketAttachment::create( $order, $items );
		$files = $pdf['files'];
		if ( ! $extras_only && ! $placeholder ) {
			$pdf_tickets = array_map( array( Orders::class, 'pdf_ticket_entry' ), $items );
			$files = Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $pdf, $order, $pdf_tickets ) {
				/**
				 * Attachments of a resent ticket mail (A621) besides the ticket PDF: .ics files, .pkpass files.
				 *
				 * @param string[] $files   Attachment paths so far.
				 * @param object   $order   Order row.
				 * @param array[]  $tickets Tickets of this mail (ticket_id, occurrence_id, token, event, ticket …).
				 */
				return apply_filters( 'wpcap_ticket_resend_attachments', $pdf['files'], $order, $pdf_tickets );
			} );
			$files = array_values( array_filter( (array) $files, 'is_string' ) );
		}
		list( $listed, $omitted ) = Mail\TicketAttachment::shorten( $entries, (int) $order->order_id, $pdf['files'] && '' === (string) ( $pdf['notice'] ?? '' ) ); // A580: a partial PDF set keeps the full list.
		$vars = Orders::ticket_mail_vars( $order, $entries, static function () use ( $listed, $omitted, $pdf ) { return Issuance::with_note( Mail\Blocks::code_tickets( $listed ), $omitted, (string) ( $pdf['notice'] ?? '' ) ); } );
		try {
			return (bool) Mail\Mailer::send( 'tickets_resend', $recipient, $vars, $files, (string) $order->locale, (int) $order->order_id );
		} finally {
			Mail\TicketAttachment::remove( $pdf, $files );
		}
	}

	/**
	 * Payload of a ticket_mail_retry job. A resend that left transferred tickets alone (A335) or only
	 * covered the buyer's own tickets keeps that scope in its retry; the plain form {order_id} is the
	 * payload of a full resend.
	 */
	public static function mail_retry_payload( $order_id, $skip_transferred = false, $only_unassigned = false ) {
		return array( 'order_id' => absint( $order_id ) ) + ( $skip_transferred ? array( 'skip_transferred' => true ) : array() ) + ( $only_unassigned ? array( 'only_unassigned' => true ) : array() );
	}

	/**
	 * Valid ticket counts of an order for the admin resend form (A335): all valid tickets and those
	 * passed on to another person (transfer), which keep their code unless the resend includes them.
	 *
	 * @return array{valid:int,transferred:int}
	 */
	public static function resend_counts( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS valid_count,COALESCE(SUM(transfer_count>0),0) AS transferred FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND status='valid'", absint( $order_id ) ) );
		return array( 'valid' => $row ? (int) $row->valid_count : 0, 'transferred' => $row ? (int) $row->transferred : 0 );
	}

	/**
	 * Reissues a single valid ticket: rotates only its code (the old QR code stops working) and mails
	 * the new link to the ticket holder (attendee e-mail, else the buyer), with the ticket PDF.
	 *
	 * @param int   $ticket_id Ticket ID.
	 * @param array $context   reason (string, audit), notify (bool, default true).
	 * @return array|\WP_Error {ticket_id, recipient, mailed}
	 */
	public static function reissue_ticket( $ticket_id, array $context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_id = absint( $ticket_id );
		Transaction::begin();
		$ticket = $wpdb->get_row( $wpdb->prepare(
			'SELECT t.ticket_id,t.order_id,t.status,t.attendee_email,tt.name AS ticket_name,e.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events',
			$ticket_id
		) );
		$order = $ticket ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $p . 'wpcap_orders', (int) $ticket->order_id ) ) : null;
		if ( ! $ticket || ! $order ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		if ( 'valid' !== $ticket->status || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_not_reissuable', __( 'Nur gültige, nicht eingecheckte Tickets bezahlter Bestellungen können neu ausgestellt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		CodeHistory::replacing( $ticket_id, 'reissue' ); // A543
		$recipient = is_email( $ticket->attendee_email ) ? $ticket->attendee_email : $order->customer_email;
		// A581: the holder of the new code is whoever receives it (no mail: the staff hands it out, the
		// attendee address decides as before).
		$set = array( 'token_hash' => hash( 'sha256', $token ) ) + ( TicketCodes::holder_ready() ? array( 'code_with_attendee' => TicketCodes::to_attendee( $recipient, $order->customer_email ) ) : array() );
		$updated = $wpdb->update( "{$p}wpcap_tickets", $set, array( 'ticket_id' => $ticket_id, 'status' => 'valid' ), count( $set ) > 1 ? array( '%s', '%d' ) : array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_ticket_rotation_failed', __( 'Ticketcode konnte nicht erneuert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );
		/** A ticket received a new code; the old one is invalid. $token is plaintext – never store it. */
		do_action( 'wpcap_ticket_code_rotated', $ticket_id, $token, 'reissue' );
		$mailed = false;
		if ( ( $context['notify'] ?? true ) && is_email( $recipient ) ) {
			$list = array( array( 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'when' => Orders::format_start( $ticket->starts_at, $ticket->timezone ), 'location' => $ticket->location, 'url' => add_query_arg( 'wpcap_ticket', $token, home_url( '/' ) ), 'extra' => Orders::code_mail_section( (int) $ticket_id, (string) $order->locale ) ) );
			$vars = Orders::ticket_mail_vars( $order, $list, static function () use ( $list ) { return Mail\Blocks::code_tickets( $list ); } );
			$pdf = Mail\TicketAttachment::create( $order, array( array( 'ticket_id' => $ticket_id, 'token' => $token, 'event' => $ticket->event_name, 'ticket' => $ticket->ticket_name, 'starts_at' => $ticket->starts_at, 'timezone' => $ticket->timezone, 'location' => $ticket->location ) ) );
			try {
				$mailed = (bool) Mail\Mailer::send( 'tickets_resend', $recipient, $vars, $pdf['files'], (string) $order->locale, (int) $order->order_id );
			} finally {
				Mail\TicketAttachment::remove( $pdf );
			}
		}
		Audit::record( 'ticket_reissued', 'ticket', $ticket_id, array( 'order_id' => (int) $order->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => sanitize_text_field( (string) ( $context['reason'] ?? '' ) ), 'mailed' => $mailed ) );
		return array( 'ticket_id' => $ticket_id, 'recipient' => $recipient, 'mailed' => $mailed );
	}
}
