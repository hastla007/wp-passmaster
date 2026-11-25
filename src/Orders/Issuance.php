<?php
/**
 * Ticket issuance, the ticket delivery outbox, the order job handler and the issuance sweeps.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Audit;
use PassCaptain\Clock;
use PassCaptain\Consents;
use PassCaptain\DbSession;
use PassCaptain\HelpCode;
use PassCaptain\Jobs;
use PassCaptain\Log;
use PassCaptain\Mail;
use PassCaptain\Orders;
use PassCaptain\ParticipantFields;
use PassCaptain\Sales;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::issue_and_email(), defer_ticket_mail(), delivery_marker(),
 * reconcile_issuance(), handle_job() and sweep_unissued(), with the ticket mail of the first delivery, the
 * outbox retry and the issuance retry state. Moved out of Orders without behaviour change (TODO "Gott-Klasse
 * Orders"). The job types and delays stay constants of Orders (DELIVERY_JOB, ISSUE_JOB, …); Orders::register()
 * keeps Orders::handle_job as the `wpcap_process_job` callback. send_ticket_mail(), with_note() and
 * job_error() are public only for the sibling parts Orders\Resend and Orders\Refunding.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Issuance {
	/** True while a queued issuance retry runs, so its failure does not queue yet another job. */
	private static $issuing_from_job = false;

	/** Depth of defer_ticket_mail() calls (payment webhooks): ticket mails go to the job queue. */
	private static $defer_mail = 0;

	/**
	 * Issues the missing tickets of a paid order and mails them to the buyer. Idempotent.
	 *
	 * Issuance: tickets, attendee assignments/answers and the cleanup of the order-level attendee
	 * copy are written in one transaction with the order row locked, and every write as well as the
	 * transaction start and commit is checked. On a database error everything is rolled back, a
	 * `ticket_issue` job retries the issuance later and a retryable WP_Error (`wpcap_issue_failed`)
	 * is returned (webhooks answer 5xx). The order-level attendee data is only removed once every
	 * collected attendee has been stored on a ticket in this transaction.
	 *
	 * Delivery (outbox): the same transaction queues a `ticket_delivery` job for the new tickets,
	 * due after DELIVERY_GRACE_MINUTES. A successful inline mail completes it; a failed mail (or a
	 * request that dies after the commit) leaves it to the job queue, which retries with backoff and
	 * dead-letters after the last attempt. The job row is the persisted delivery status
	 * (see delivery_status(), shown on the admin order page).
	 *
	 * Retry design: ticket codes are only stored hashed, so a retry cannot send the original codes.
	 * It goes through the resend path instead (rotate_and_send()): the still valid tickets of the
	 * failed delivery get new codes under row locks and the new links are mailed to the buyer before
	 * the rotation commits. The codes of the failed mail never reached anybody, so rotating them is
	 * safe; a mail failure during the retry rolls the rotation back.
	 *
	 * @return true|\WP_Error True when nothing was missing or the issuance committed; a failed mail
	 *                        does not make this fail (the outbox retries it).
	 */
	public static function issue_and_email( $order_id ) {
		// A deadlock or lock wait timeout (e.g. with a seat claim of a parallel checkout) rolls the issuance
		// back; it is retried here up to DB_RETRIES times before the job queue takes over.
		for ( $attempt = 0; ; ++$attempt ) {
			$result = self::issue_and_email_attempt( $order_id );
			$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : array();
			if ( empty( $data['db_busy'] ) || $attempt >= Orders::DB_RETRIES ) { return $result; }
			usleep( random_int( 30, 150 ) * 1000 * ( $attempt + 1 ) );
		}
	}

	/** One issuance attempt (see issue_and_email()). */
	private static function issue_and_email_attempt( $order_id ) {
		// Fiscal modules submit every seller-specific receipt before tickets can be issued. A blocked order is
		// not an issuance failure: the fiscal module retries and calls this again once its receipts are confirmed.
		global $wpdb;
		if ( ! apply_filters( 'wpcap_order_fiscal_ready', true, absint( $order_id ) ) ) {
			// A database error in the fiscal check is not a blocked receipt: it is an issuance failure
			// (retry job), so the order does not stay without tickets after a lost connection (A183).
			return '' !== (string) $wpdb->last_error ? self::issue_failed( absint( $order_id ), 'fiscal_ready' ) : true;
		}
		$order_id = absint( $order_id );
		$orders = $wpdb->prefix . 'wpcap_orders';
		// Serialise issuance per order so concurrent payment callbacks cannot create duplicates.
		if ( false === Transaction::begin() ) { return self::issue_failed( $order_id, 'start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $orders, $order_id ) );
		if ( '' !== $wpdb->last_error ) { return self::issue_failed( $order_id, 'lock' ); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' ); // Nothing written.
			return true;
		}
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT oi.*,tt.name AS ticket_name' . Sales\Addons::column_sql( 'tt' ) . ',tt.occurrence_id,o.event_id,e.name AS event_name,o.starts_at,o.timezone,o.location FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id=%d', $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal column list (Sales\Addons::column_sql()).
		if ( '' !== $wpdb->last_error ) { return self::issue_failed( $order_id, 'lines' ); }
		/**
		 * Order lines that receive tickets. Modules may drop lines that are no admission of their own,
		 * e.g. the supplementary payment order of a ticket exchange (Exchange\Service issues that ticket).
		 *
		 * @param object[] $lines Order item rows with ticket/occurrence/event columns.
		 * @param object   $order Locked order row.
		 */
		$lines = (array) apply_filters( 'wpcap_issue_order_lines', $lines, $order );
		// Add-on products (Sales\Addons) are no admission: their lines stay order lines without tickets.
		$lines = array_filter( $lines, static function ( $line ) { return ! Sales\Addons::is_addon( $line ); } );
		$issued = array();
		// Attendee data collected at checkout, applied once to the issued tickets.
		$pending_attendees = $order->attendee_data ? (array) json_decode( $order->attendee_data, true ) : array();
		foreach ( $lines as $line ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d AND ticket_type_id=%d', $wpdb->prefix . 'wpcap_tickets', $order_id, $line->ticket_type_id ) );
			// An unknown count would issue the tickets a second time.
			if ( '' !== $wpdb->last_error ) { return self::issue_aborted( $order, $issued, 'count' ); }
			for ( $i = $existing; $i < (int) $line->quantity; $i++ ) {
				$token = bin2hex( random_bytes( 32 ) );
				$public_id = bin2hex( random_bytes( 16 ) );
				$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_tickets', array(
					'public_id' => $public_id,
					'order_id' => $order_id,
					'ticket_type_id' => (int) $line->ticket_type_id,
					'token_hash' => hash( 'sha256', $token ),
					'status' => 'valid',
					'issued_at' => Clock::mysql(),
				), array( '%s', '%d', '%d', '%s', '%s', '%s' ) );
				$ticket_id = (int) $wpdb->insert_id;
				if ( ! $inserted || '' !== $wpdb->last_error || ! $ticket_id ) {
					// Nothing of this issuance is kept (the attendee data stays on the order for a retry).
					return self::issue_aborted( $order, $issued, 'ticket_insert' );
				}
				$issued[] = array( 'ticket_id' => $ticket_id, 'ticket_type_id' => (int) $line->ticket_type_id, 'occurrence_id' => (int) ( $line->occurrence_id ?? 0 ), 'token' => $token, 'event' => $line->event_name, 'ticket' => $line->ticket_name, 'starts_at' => $line->starts_at, 'timezone' => $line->timezone, 'location' => $line->location );
				foreach ( $pending_attendees as $index => $attendee ) {
					if ( (int) ( $attendee['ticket_type_id'] ?? 0 ) !== (int) $line->ticket_type_id ) { continue; }
					unset( $pending_attendees[ $index ] );
					if ( ! Attendees::store( $ticket_id, $order, (array) $attendee ) ) { return self::issue_aborted( $order, $issued, 'attendee' ); }
					$issued[ count( $issued ) - 1 ]['attendee'] = sanitize_text_field( $attendee['name'] ?? '' ); // Name on the PDF (A330).
					break;
				}
				/** Inside the issuance transaction, e.g. to assign a seat or area. Do not store $token. */
				do_action( 'wpcap_ticket_created', $ticket_id, $order, $line, $i );
				// A deadlock inside a hook rolls the whole transaction back; further inserts would autocommit
				// and leave a partial issuance behind.
				if ( false === Transaction::in_transaction() ) { return self::issue_aborted( $order, $issued, 'ticket_hook' ); }
			}
		}
		if ( $issued && $order->attendee_data && ! $pending_attendees ) {
			// Every collected attendee is stored with a ticket (each write checked above): drop the
			// order-level copy (data minimisation). Unassigned entries keep the copy for a later run.
			if ( false === $wpdb->update( $orders, array( 'attendee_data' => null ), array( 'order_id' => $order_id ) ) ) { return self::issue_aborted( $order, $issued, 'attendee_cleanup' ); }
		}
		$delivery_key = '';
		$deferred = $issued && self::defers_delivery( $order_id, count( $issued ) );
		if ( $issued ) {
			$delivery_key = 'ticket-delivery-' . $order_id . '-' . bin2hex( random_bytes( 8 ) );
			// order_id first: delivery_status() finds the job by its payload prefix. hashes: the code hash
			// of each ticket at issuance – the outbox only delivers tickets whose code is still this one
			// (a transfer, assignment or resend in between already mailed its own new code).
			$payload = array(
				'order_id' => $order_id,
				'ticket_ids' => array_map( 'intval', array_column( $issued, 'ticket_id' ) ),
				'hashes' => array_combine( array_map( 'strval', array_column( $issued, 'ticket_id' ) ), array_map( static function ( $t ) { return hash( 'sha256', $t['token'] ); }, $issued ) ),
				'initial' => $deferred ? 1 : 0,
			);
			// Deferred delivery: due right away; otherwise only after the grace period (covers a crash after commit).
			$due = $deferred ? Clock::mysql() : gmdate( 'Y-m-d H:i:s', Clock::now() + Orders::DELIVERY_GRACE_MINUTES * MINUTE_IN_SECONDS );
			if ( true !== Jobs::enqueue( $delivery_key, Orders::DELIVERY_JOB, $payload, $due ) ) {
				return self::issue_aborted( $order, $issued, 'outbox' );
			}
		}
		// A failed COMMIT (or a transaction the server rolled back) must not send tickets that do not
		// exist. The first ticket of this run tells whether the issuance is durable (A183): after a
		// lost connection wpdb reconnects and runs the rest with autocommit – if that ticket exists,
		// everything from its insert on was committed; if not, nothing before the loss was, and a
		// COMMIT that wpdb re-ran on the new session committed nothing.
		$commit_ok = false !== Transaction::in_transaction() && false !== $wpdb->query( 'COMMIT' );
		if ( ! $commit_ok ) { $wpdb->query( 'ROLLBACK' ); }
		if ( $issued ) {
			$probe = $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', $issued[0]['ticket_id'] );
			$first = $wpdb->get_var( $probe );
			if ( '' !== $wpdb->last_error ) { $first = $wpdb->get_var( $probe ); } // Once more on the reconnected session.
			$commit_ok = null !== $first && '' === $wpdb->last_error;
		}
		if ( ! $commit_ok ) {
			return self::issue_aborted( $order, $issued, 'commit' );
		}
		// Consent grants recorded with the tickets: hooks only now that they are committed.
		Consents::flush_pending( (int) $order_id );
		if ( $issued ) {
			Orders::flush_availability_cache();
			// Short help code for staff when the QR code cannot be read (stored only as keyed hash).
			foreach ( $issued as $index => $ticket ) { $issued[ $index ]['help_code'] = HelpCode::for_ticket( $ticket['ticket_id'], $ticket['token'] ); }
			do_action( 'wpcap_tickets_issued', (int) $order_id, count( $issued ) );
			// A682: the ticket mail names, per ticket, the attendee data that is still missing.
			$missing_data = ParticipantFields::missing( array_column( $issued, 'ticket_id' ) );
			if ( $missing_data ) {
				Audit::record( 'attendee_data_missing', 'order', (int) $order_id, array( 'tickets' => count( $missing_data ) ) );
				/** A682: tickets were issued with missing attendee data (ticket_id => labels). */
				do_action( 'wpcap_attendee_data_missing', (int) $order_id, $missing_data );
				foreach ( $issued as $index => $ticket ) { $issued[ $index ]['missing'] = $missing_data[ (int) $ticket['ticket_id'] ] ?? array(); }
			}
			if ( $deferred ) {
				// Large order or payment webhook: no PDF/wallet/SMTP work in this request. The outbox job
				// (due now) mails new codes for the still unchanged tickets; the codes created here were
				// never shown to anybody and are never stored in plaintext (not even encrypted).
				Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' );
				Audit::record( 'ticket_delivery_deferred', 'order', $order_id, array( 'ticket_count' => count( $issued ), 'webhook' => self::$defer_mail > 0 ) );
				return true;
			}
			$delivered = self::send_ticket_mail( $order, $issued );
			if ( ! $delivered ) {
				\PassCaptain\Log::error( 'ticket_mail_failed', 'ticket mail failed', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'tickets' => count( $issued ) ) ) );
			}
			self::finish_delivery( $delivery_key, $order_id, (bool) $delivered, array_map( 'intval', array_column( $issued, 'ticket_id' ) ) );
		}
		return true;
	}

	/**
	 * Runs $callback (e.g. applying a payment webhook) with ticket mails deferred to the job queue: the
	 * provider gets its answer without waiting for PDF rendering, wallet signing and SMTP.
	 *
	 * @return mixed The callback's result.
	 */
	public static function defer_ticket_mail( callable $callback ) {
		++self::$defer_mail;
		try {
			return $callback();
		} finally {
			--self::$defer_mail;
		}
	}

	/** Whether the ticket mail of a fresh issuance goes to the outbox job instead of the request. */
	private static function defers_delivery( $order_id, $ticket_count ) {
		if ( self::$defer_mail > 0 ) { return true; }
		$max = (int) apply_filters( 'wp_pass_captain_ticket_mail_inline_max_v1', Orders::INLINE_DELIVERY_MAX, (int) $order_id );
		return $ticket_count > max( 0, $max );
	}

	/**
	 * Renders and sends the ticket mail (type "tickets") to the buyer: ticket list (shortened when the
	 * PDF holds every ticket; per ticket the help code and – A682 – missing attendee data), PDF
	 * attachment (Mail\TicketAttachment), legacy filter wpcap_ticket_mail (seats, calendar file,
	 * invoice, wallet links; A474: run in the buyer's locale). Temporary files are released afterwards.
	 * No mail with plaintext codes goes to a placeholder address (sale without buyer e-mail).
	 *
	 * @param object $order  Order row.
	 * @param array  $issued Items with ticket_id, token (plaintext), event, ticket, starts_at, timezone, location[, help_code, missing].
	 * @return bool Whether the mail was accepted.
	 */
	public static function send_ticket_mail( $order, array $issued ) {
		$entries = array_map( static function ( $ticket ) {
			return array( 'missing' => (array) ( $ticket['missing'] ?? array() ), 'event' => $ticket['event'], 'ticket' => $ticket['ticket'], 'when' => Orders::format_start( $ticket['starts_at'], $ticket['timezone'] ), 'location' => $ticket['location'], 'url' => add_query_arg( 'wpcap_ticket', $ticket['token'], home_url( '/' ) ), 'token' => $ticket['token'], 'help_code' => (string) ( $ticket['help_code'] ?? '' ) );
		}, $issued );
		$placeholder = Mail\Mailer::is_placeholder( (string) $order->customer_email );
		$pdf = $placeholder ? array( 'files' => array(), 'dir' => '', 'notice' => '' ) : Mail\TicketAttachment::create( $order, $issued );
		list( $listed, $omitted ) = Mail\TicketAttachment::shorten( $entries, (int) $order->order_id, $pdf['files'] && '' === (string) ( $pdf['notice'] ?? '' ) ); // A580: a partial PDF set keeps the full list.
		$message = Mail\Mailer::compose( 'tickets', Orders::ticket_mail_vars( $order, $entries, static function () use ( $listed, $omitted, $pdf ) { return self::with_note( Mail\Blocks::issued_tickets( $listed ), $omitted, $pdf['notice'] ); } ), (string) $order->locale );
		if ( ! $message ) {
			Mail\TicketAttachment::remove( $pdf );
			return false;
		}
		$attachments = $pdf['files'];
		$mail = array( 'attachments' => $attachments );
		try {
			// Legacy filter: receives and may change subject/body/attachments of the rendered template.
			// A474: runs in the buyer's locale, so added texts and attachments (wallet, invoice, .ics,
			// seats) match the language of the mail instead of the request's language.
			$filtered = array( 'subject' => $message['subject'], 'body' => $message['body'], 'attachments' => $attachments );
			$mail = Mail\Mailer::with_locale( (string) $order->locale, static function () use ( $filtered, $order, $issued ) {
				return apply_filters( 'wpcap_ticket_mail', $filtered, $order, $issued );
			} );
			$mail = is_array( $mail ) ? $mail : array( 'attachments' => $attachments );
			return (bool) Mail\Mailer::deliver( $order->customer_email, array( 'order_id' => (int) $order->order_id ) + Mail\Mailer::apply_filtered( $message, $mail ) );
		} finally {
			Mail\TicketAttachment::remove( $pdf, (array) ( $mail['attachments'] ?? array() ) );
		}
	}

	/** A ticket list block followed by the "… and N more tickets" note (Mail\TicketAttachment). */
	public static function with_note( array $block, $omitted, $notice = '' ) {
		$note = Mail\TicketAttachment::omitted_note( (int) $omitted );
		$big = Mail\TicketAttachment::notice_block( $notice );
		return Mail\Mailer::block( (string) $block['text'] . $note['text'] . $big['text'], (string) $block['html'] . $note['html'] . $big['html'] );
	}

	/**
	 * Ticket issuance hit a database error: everything is rolled back, nothing was issued, the order
	 * stays paid with its attendee data. A `ticket_issue` job retries it later (unless this already is
	 * a retry; the job queue retries with backoff) and a retryable error is returned (webhooks answer
	 * 5xx, a later call – webhook retry, resend, "Zuteilung erneut versuchen" – issues it as well).
	 * A deadlock/lock wait timeout (or a server rollback inside a creation hook) is flagged db_busy and
	 * retried inline by issue_and_email() first.
	 */
	private static function issue_failed( $order_id, $step ) {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		// Busy: deadlock/lock wait timeout, or the server rolled the transaction back inside a creation hook
		// (detected by in_transaction(); the error text may be gone by then). Retried inline.
		$busy = DbSession::is_busy_error( $error ) || 'ticket_hook' === (string) $step;
		$wpdb->query( 'ROLLBACK' );
		Log::error( 'ticket_issue_failed', 'Ticket issuance failed and was rolled back; a retry is queued.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ), 'error' => \PassCaptain\Text::cut( $error, 200 ) ) ) );
		if ( ! self::$issuing_from_job && $order_id ) {
			// One retry job per order and minute; the job queue retries it with backoff.
			Jobs::enqueue( 'ticket-issue-' . $order_id . '-' . gmdate( 'YmdHi', Clock::now() ), Orders::ISSUE_JOB, array( 'order_id' => $order_id ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
		}
		return new \WP_Error( 'wpcap_issue_failed', __( 'Die Tickets konnten gerade nicht ausgestellt werden. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true, 'step' => (string) $step, 'db_busy' => $busy ) );
	}

	/**
	 * Rolls back a failed issuance. If the connection was lost on the way (wpdb reconnected, later
	 * statements autocommitted), the tickets of this run that were written anyway are removed again
	 * with their attendee rows and consent records (their codes were never mailed), the order's
	 * attendee data is restored and `wpcap_tickets_discarded` lets modules undo their rows (e.g. seat
	 * assignments). A retry then issues the full set once.
	 *
	 * @param object $order  Order row read under the lock (attendee_data as before the run).
	 * @param array  $issued Tickets inserted in this run ({ticket_id, …}).
	 * @param string $step   Failed step (audit).
	 */
	private static function issue_aborted( $order, array $issued, $step ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$error = (string) $wpdb->last_error;
		$lost = $issued && false === Transaction::in_transaction();
		$wpdb->query( 'ROLLBACK' );
		Consents::discard_pending();
		$ids = array_map( static function ( $ticket ) { return (int) $ticket['ticket_id']; }, $issued );
		if ( $lost ) {
			$list = implode( ',', $ids );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_ticket_assignments WHERE ticket_id IN ($list) AND buyer_email=%s", (string) $order->customer_email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$wpdb->query( "DELETE FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_consent_log WHERE ticket_id IN ($list) AND order_id=%d", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_tickets WHERE ticket_id IN ($list) AND order_id=%d", (int) $order->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			if ( $order->attendee_data ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET attendee_data=%s WHERE order_id=%d AND attendee_data IS NULL", (string) $order->attendee_data, (int) $order->order_id ) );
			}
			/**
			 * Tickets of a failed issuance were removed again (the connection was lost mid-way and the
			 * rest of the issuance ran outside its transaction). Undo rows written in wpcap_ticket_created.
			 *
			 * @param int[] $ticket_ids Removed ticket IDs.
			 * @param int   $order_id   Order.
			 */
			do_action( 'wpcap_tickets_discarded', $ids, (int) $order->order_id );
		}
		// issue_failed() classifies the original database error (the cleanup above may have reset it).
		$wpdb->last_error = $error;
		return self::issue_failed( (int) $order->order_id, $step );
	}

	/**
	 * Records the result of the inline ticket mail on its outbox job: completed when sent, otherwise
	 * attempt counted, error noted and due again in five minutes (Jobs::process_due() retries it).
	 *
	 * Double-mail guard: after a successful mail a "delivered" marker (option, separate from the job row)
	 * is written before the job is completed, and the completion is retried briefly. Should the job
	 * still stay queued (e.g. lock timeouts on the queue table), retry_delivery() finds the marker and
	 * completes the job without sending a second mail with rotated codes.
	 *
	 * @param int[] $ticket_ids Tickets of this delivery.
	 */
	private static function finish_delivery( $key, $order_id, $sent, array $ticket_ids ) {
		global $wpdb;
		if ( '' === $key ) { return; }
		$table = $wpdb->prefix . 'wpcap_jobs';
		$now = Clock::mysql();
		if ( $sent ) {
			$marker = self::delivery_marker( $order_id, $ticket_ids );
			update_option( $marker, Clock::now(), false );
			$completed = Jobs::update_with_retry( $table, array( 'status' => 'complete', 'attempts' => 1, 'locked_at' => null, 'last_error' => null, 'updated_at' => $now ), array( 'idempotency_key' => hash( 'sha256', $key ), 'status' => 'queued' ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%s', '%s' ) );
			if ( $completed ) {
				delete_option( $marker );
			}
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts=attempts+1,run_after=%s,last_error=%s,updated_at=%s WHERE idempotency_key=%s AND status='queued'", $table, gmdate( 'Y-m-d H:i:s', Clock::now() + 5 * MINUTE_IN_SECONDS ), 'Ticket mail could not be delivered.', $now, hash( 'sha256', $key ) ) );
		Log::warning( 'ticket_delivery_failed', 'Ticket mail could not be delivered; the outbox retries it.', array( 'object_type' => 'order', 'object_id' => absint( $order_id ), 'details' => array( 'ticket_count' => count( $ticket_ids ), 'retry' => 'scheduled' ) ) );
	}

	/** Option name of the "delivered" marker of one ticket delivery (order + its tickets). */
	public static function delivery_marker( $order_id, array $ticket_ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ticket_ids ) ) );
		sort( $ids );
		return 'wpcap_delivered_' . absint( $order_id ) . '_' . substr( hash( 'sha256', implode( ',', $ids ) ), 0, 16 );
	}

	/**
	 * Outbox job: delivers tickets whose mail failed (or was deferred, see defer_ticket_mail()) by
	 * rotating their codes and mailing new links to the buyer, as the ticket mail with PDF (see
	 * issue_and_email()). Returns false to let the job queue retry.
	 *
	 * Only tickets whose code is still the one of the issuance are delivered (payload "hashes"): a
	 * transfer, a participant assignment or a resend in between already mailed a new code to the
	 * holder, and a retry must not take that ticket back to the buyer. Jobs without hashes (queued by
	 * older versions) skip transferred tickets and tickets with a holder change instead.
	 *
	 * @param array $payload Job payload (ticket_ids, hashes, initial).
	 */
	private static function retry_delivery( $order_id, array $ticket_ids, array $payload = array() ) {
		global $wpdb;
		$marker = self::delivery_marker( $order_id, $ticket_ids );
		if ( $ticket_ids && get_option( $marker ) ) {
			// The inline mail was sent; only completing the job had failed. No second mail.
			delete_option( $marker );
			Audit::record( 'ticket_delivery_retry_skipped', 'order', absint( $order_id ), array( 'ticket_count' => count( $ticket_ids ), 'reason' => 'already_delivered' ) );
			return true;
		}
		$order = $order_id ? Orders::get( $order_id ) : null;
		if ( '' !== $wpdb->last_error ) { return false; }
		if ( ! $order || 'paid' !== $order->payment_status || ! $ticket_ids ) {
			return true; // Nothing deliverable any more (cancelled, refunded).
		}
		$hashes = array();
		foreach ( (array) ( $payload['hashes'] ?? array() ) as $id => $hash ) { $hashes[ absint( $id ) ] = (string) $hash; }
		if ( ! $hashes ) {
			$changed = self::holder_changed_ids( $ticket_ids );
			if ( null === $changed ) { return false; }
			$ticket_ids = array_values( array_diff( $ticket_ids, $changed ) );
			if ( ! $ticket_ids ) { return true; }
		}
		$reason = empty( $payload['initial'] ) ? 'delivery_retry' : 'delivery';
		// A588: a retry after a failed mail delivers the stored codes (TicketCodes) instead of new ones.
		$result = Resend::rotate_and_send( $order, $ticket_ids, $reason, true, array( 'hashes' => $hashes, 'mail' => 'tickets', 'keep_codes' => 'delivery_retry' === $reason ) );
		if ( is_wp_error( $result ) ) {
			// No valid ticket left (revoked, checked in, transferred with its own mail): done.
			return 'wpcap_no_resendable_tickets' === $result->get_error_code();
		}
		Audit::record( 'delivery' === $reason ? 'tickets_delivered' : 'tickets_delivered_retry', 'order', $order_id, array( 'ticket_count' => (int) $result ) );
		return true;
	}

	/**
	 * Tickets that changed hands after issuance (transfer, or a holder change by participant
	 * assignment): their holder got a mail with a new code of their own.
	 *
	 * @return int[]|null Ticket ids; null on a database error.
	 */
	private static function holder_changed_ids( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$transferred = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE transfer_count>0 AND ticket_id IN ($in)", array_merge( array( $wpdb->prefix . 'wpcap_tickets' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== $wpdb->last_error ) { return null; }
		$assigned = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM %i WHERE object_type='ticket' AND action='ticket_holder_changed' AND object_id IN ($in)", array_merge( array( $wpdb->prefix . 'wpcap_audit_logs' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders.
		if ( '' !== $wpdb->last_error ) { return null; }
		return array_values( array_unique( array_map( 'intval', array_merge( (array) $transferred, (array) $assigned ) ) ) );
	}

	/**
	 * Safety net for issuances whose retry job could not be queued (e.g. the database connection was
	 * lost after the payment committed): issues the missing tickets of paid orders right away, without
	 * the grace period of sweep_unissued(). Idempotent (the issuance locks the order row and only creates
	 * what is missing); runs with the job queue (cron).
	 *
	 * Same window and hand-over as the A343 sweep: only orders paid within UNISSUED_LOOKBACK (older ones
	 * are reported by the Doctor check, no automatic mail of old tickets), and an order this sweep could
	 * not complete gets the sweep's retry job (fixed key, backoff, dead letter) and is not picked again -
	 * so a few orders that never complete (e.g. an exchange supplement) cannot hold the batch forever.
	 * While the order still has an open issuance job (`ticket_issue`, `ticket_issue_retry`), that job stays
	 * its only retry: the order is tried again on the next run, no second job is queued.
	 *
	 * @return int Number of orders whose tickets were issued.
	 */
	public static function reconcile_issuance( $limit = 20 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM %i ord WHERE ord.payment_status='paid' AND ord.status='complete' AND ord.updated_at>=%s AND (SELECT COALESCE(SUM(oi.quantity),0) FROM %i oi" . Orders::addon_free_join() . " WHERE oi.order_id=ord.order_id) > (SELECT COUNT(*) FROM %i t WHERE t.order_id=ord.order_id) AND NOT EXISTS (SELECT 1 FROM %i j WHERE j.idempotency_key=SHA2(CONCAT('ticket-issue-sweep|', ord.order_id), 256)) ORDER BY ord.order_id LIMIT %d", $p . 'wpcap_orders', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal join (Orders::addon_free_join()).
			gmdate( 'Y-m-d H:i:s', Clock::now() - Orders::UNISSUED_LOOKBACK ), $p . 'wpcap_order_items', $p . 'wpcap_tickets', $p . 'wpcap_jobs',
			max( 1, absint( $limit ) )
		) );
		$done = 0;
		foreach ( (array) $ids as $order_id ) {
			self::$issuing_from_job = true; // No retry job of the issuance itself: see the hand-over below.
			try {
				$complete = true === self::issue_and_email( (int) $order_id ) && 0 === Orders::missing_tickets( (int) $order_id );
			} finally {
				self::$issuing_from_job = false;
			}
			if ( $complete ) {
				++$done;
			} elseif ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE job_type IN ('ticket_issue','ticket_issue_retry') AND status IN ('queued','running') AND payload=%s", $p . 'wpcap_jobs', wp_json_encode( array( 'order_id' => (int) $order_id ) ) ) ) ) {
				self::queue_sweep_job( (int) $order_id );
			}
		}
		return $done;
	}

	/**
	 * Job handler (filter `wpcap_process_job`, legacy wp_pass_captain_process_job_v1): issuance retries
	 * (`ticket_issue`, `ticket_issue_retry`), the ticket delivery outbox (`ticket_delivery`), repairs
	 * (refund mark, cancellation), the automatic late refund and the retry of a failed resend mail
	 * (`ticket_mail_retry`). All are idempotent: a repeated run after a worker died between the side
	 * effect and the "complete" mark (at-least-once, see Jobs) changes nothing more.
	 */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		$payload = (array) $payload;
		// A failed run returns the WP_Error of the attempt: its code and text become the job's
		// last_error, so a dead letter says why (A325).
		if ( Orders::ISSUE_JOB === $type || 'ticket_issue_retry' === $type ) {
			$order_id = absint( $payload['order_id'] ?? 0 );
			$order = Orders::get( $order_id );
			if ( ! $order || 'paid' !== $order->payment_status ) { return true; } // Nothing (more) to issue.
			self::$issuing_from_job = true;
			try {
				$issued = self::issue_and_email( $order_id );
			} finally {
				self::$issuing_from_job = false;
			}
			return is_wp_error( $issued ) ? $issued : true;
		}
		if ( Orders::DELIVERY_JOB === $type ) {
			return self::retry_delivery( absint( $payload['order_id'] ?? 0 ), array_map( 'absint', (array) ( $payload['ticket_ids'] ?? array() ) ), $payload );
		}
		if ( 'order_refund_mark_retry' === $type ) {
			$marked = Orders::mark_refunded( absint( $payload['order_id'] ?? 0 ), (string) ( $payload['reason'] ?? '' ) );
			// A refused transition is final (never paid): acknowledge instead of retrying into the dead letter.
			if ( true === $marked || ( is_wp_error( $marked ) && 'wpcap_order_state' === $marked->get_error_code() ) ) { return true; }
			return self::job_error( $marked, 'wpcap_refund_mark_failed' );
		}
		if ( 'order_cancel_retry' === $type ) {
			$order = Orders::get( absint( $payload['order_id'] ?? 0 ) );
			// Paid, refunded or cancelled meanwhile: the retry must not override that.
			if ( ! $order || (string) $order->payment_status !== (string) ( $payload['payment_status'] ?? '' ) ) { return true; }
			$cancelled = Orders::cancel_order( (int) $order->order_id, (string) ( $payload['reason'] ?? '' ), array( (string) $order->payment_status ) );
			if ( is_wp_error( $cancelled ) && 'wpcap_order_state' === $cancelled->get_error_code() ) { return true; }
			return true === $cancelled ? true : self::job_error( $cancelled, 'wpcap_order_cancel_failed' );
		}
		if ( 'late_payment_refund' === $type ) {
			return Refunding::run_late_refund( absint( $payload['order_id'] ?? 0 ), (string) ( $payload['reason'] ?? '' ) );
		}
		if ( 'ticket_mail_retry' !== $type ) { return $handled; }
		$order_id = absint( $payload['order_id'] ?? 0 );
		$order = Orders::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status ) { return true; } // Nothing (more) to send.
		if ( $job_id && self::tickets_resent_since_job( $order_id, (int) $job_id ) ) {
			// The tickets went out with working codes after this job was queued (an earlier run of it
			// whose worker died before the "complete" mark, or a manual resend): no second mail.
			return true;
		}
		// A588: the codes of the failed mail are known (stored at issuance/rotation): deliver them as they
		// are instead of invalidating codes that may have reached some recipients.
		$result = Resend::resend_tickets( $order_id, array( 'from_job' => true, 'skip_transferred' => ! empty( $payload['skip_transferred'] ), 'only_unassigned' => ! empty( $payload['only_unassigned'] ), 'keep_codes' => true ) );
		if ( is_wp_error( $result ) && 'wpcap_no_resendable_tickets' === $result->get_error_code() ) { return true; }
		return true === $result ? true : self::job_error( $result, 'wpcap_mail_failed' );
	}

	/** The WP_Error of a failed job step, or a generic one with $code when the step gave no reason. */
	public static function job_error( $result, $code ) {
		if ( $result instanceof \WP_Error ) { return $result; }
		global $wpdb;
		/* translators: %s: database error ('' when none) */
		return new \WP_Error( $code, trim( sprintf( __( 'Der Schritt ist ohne Fehlerangabe fehlgeschlagen. %s', 'pass-captain' ), (string) $wpdb->last_error ) ) );
	}

	/** Whether a resend of the order's tickets succeeded since the job was queued (audit `tickets_resent`). */
	private static function tickets_resent_since_job( $order_id, $job_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$queued = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$p}wpcap_jobs WHERE job_id=%d", absint( $job_id ) ) );
		if ( ! $queued ) { return false; }
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='tickets_resent' AND object_type='order' AND object_id=%d AND created_at>=%s LIMIT 1", absint( $order_id ), $queued ) );
	}

	/**
	 * Cron (wpcap_cleanup_reservations): paid orders that still have no ticket some minutes after the
	 * payment get a `ticket_issue_retry` job (A343). Covers every way a payment is confirmed (webhook,
	 * return page, WooCommerce, partner sale, manual confirmation) when the issuance after the
	 * committed payment never ran – process killed, timeout, failed job enqueue – or its retries ended
	 * as dead letter. One job per order (fixed key): a job that issues nothing (e.g. an exchange
	 * supplement, whose ticket lives on the original order) is not queued again. Orders paid more than
	 * two days ago are only reported (Doctor check `paid_orders_without_tickets`).
	 *
	 * @param int $limit Orders per run.
	 * @return int Jobs queued.
	 */
	public static function sweep_unissued( $limit = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::now();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT o.order_id FROM {$p}wpcap_orders o WHERE o.payment_status='paid' AND o.status='complete' AND o.updated_at < %s AND o.updated_at >= %s AND EXISTS (SELECT 1 FROM {$p}wpcap_order_items oi WHERE oi.order_id=o.order_id AND oi.quantity > oi.refunded_quantity) AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_tickets t WHERE t.order_id=o.order_id) ORDER BY o.order_id LIMIT %d",
			gmdate( 'Y-m-d H:i:s', $now - Orders::UNISSUED_GRACE ),
			gmdate( 'Y-m-d H:i:s', $now - Orders::UNISSUED_LOOKBACK ),
			max( 1, absint( $limit ) )
		) );
		$queued = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $order_id ) {
			if ( self::queue_sweep_job( $order_id ) ) { ++$queued; }
		}
		return $queued;
	}

	/**
	 * Queues the one `ticket_issue_retry` job of the issuance sweeps for an order (fixed key, shared by
	 * sweep_unissued() and reconcile_issuance()). False when the order was swept before - the Doctor check
	 * reports what is still open - or the job could not be queued.
	 */
	private static function queue_sweep_job( $order_id ) {
		global $wpdb;
		$key = 'ticket-issue-sweep|' . absint( $order_id );
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE idempotency_key=%s', $wpdb->prefix . 'wpcap_jobs', hash( 'sha256', $key ) ) );
		if ( $before ) { return false; }
		if ( true !== Jobs::enqueue( $key, 'ticket_issue_retry', array( 'order_id' => absint( $order_id ) ) ) ) { return false; }
		Audit::record( 'ticket_issue_swept', 'order', absint( $order_id ), array() );
		return true;
	}
}
