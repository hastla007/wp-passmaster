<?php
/**
 * Consequences of cancelling a single occurrence (wpcap_occurrence_cancelled), carried out as a
 * resumable run (A428, BulkRuns: batched steps, refunds as `cancellation_refund` jobs, mails through
 * the message queue): open and expired unpaid orders (A273) are cancelled and their holds released, valid tickets are
 * revoked, ticket holders are informed, and – when `auto_refund_cancelled` is enabled – paid native orders are refunded
 * for the affected tickets. WooCommerce orders get an order note; the refund happens in WooCommerce.
 * Without automatic refund, paid tickets are revoked but stay refundable natively
 * (Orders::refundable_tickets(): revoked + not refunded + occurrence cancelled).
 * Whole-event cancellations (Orders::cancel_event) run this per occurrence inside an "event batch",
 * where the per-occurrence mail is replaced by one event cancellation mail.
 *
 * Order of work: tickets are revoked first with bulk UPDATEs; per-ticket hooks, refunds and mails
 * follow in batches, so an abort at any point never leaves valid tickets with refunded money.
 *
 * Legacy batch jobs (occurrence_cancel_hooks/_refunds/_notify, queued by the former Jobs-based
 * implementation before an upgrade) are still processed by handle_job(); refund_paid() remains as
 * synchronous variant for direct calls.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OccurrenceCancellation {
	/** Legacy job types of the former batched follow-up work (still processed, see handle_job()). */
	const JOB_HOOKS = 'occurrence_cancel_hooks';
	const JOB_REFUNDS = 'occurrence_cancel_refunds';
	const JOB_NOTIFY = 'occurrence_cancel_notify';

	/** Batch sizes of the legacy jobs: tickets per hook job, orders per refund job, recipients per mail job. */
	const HOOK_BATCH = 200;
	const REFUND_BATCH = 5;
	const NOTIFY_BATCH = 100;

	/**
	 * Seconds of follow-up work done inside the request of a whole-event cancellation (shared deadline,
	 * see begin_event_batch(); Orders::cancel_event()).
	 *
	 * @internal Tests set it to 0 to leave everything queued.
	 * @var int
	 */
	public static $inline_seconds = 20;

	/** Nesting depth of an event cancellation in progress (suppresses per-occurrence mails). */
	private static $event_batch = 0;

	/** Shared inline deadline of an event cancellation (all occurrences together). */
	private static $batch_deadline = 0;

	/** Counters of legacy job batches run in this request, per run id. */
	private static $counters = array();

	/** Summary of the last handle() run per occurrence id (request scope). */
	private static $summaries = array();

	/** Job type of a cancellation refund that failed and is retried in the background (A354). */
	const REFUND_JOB = 'cancellation_refund';

	/** Error codes after which a retried cancellation refund has nothing left to do. */
	const REFUND_FINAL_ERRORS = array( 'wpcap_refund_state', 'wpcap_refund_woocommerce', 'wpcap_refund_tickets', 'wpcap_refund_amount' );

	public static function register() {
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'handle' ), 10, 1 );
		// Job handlers (bulk_run, cancellation_refund) and the queued run mails.
		BulkRuns::register();
	}

	/**
	 * Executes one cancellation refund (A354): a system flow – independent of the capabilities and
	 * 2FA of whoever cancelled (or of the cron worker) – that also refunds tickets already used for
	 * admission (allow_used: the buyer paid for a date that did not take place).
	 *
	 * @param array $job mode (tickets|amount), order_id, ticket_ids, amount_minor, tax_minor, reason, key.
	 * @return array|\WP_Error Refund row or error.
	 */
	public static function request_refund( array $job ) {
		$args = array( 'system' => true, 'allow_used' => true );
		if ( 'amount' === ( $job['mode'] ?? 'tickets' ) ) {
			if ( isset( $job['tax_minor'] ) ) { $args['tax_minor'] = absint( $job['tax_minor'] ); }
			return Refunds::request_amount( absint( $job['order_id'] ?? 0 ), absint( $job['amount_minor'] ?? 0 ), (string) ( $job['reason'] ?? '' ), (string) ( $job['key'] ?? '' ), $args );
		}
		return Refunds::request( absint( $job['order_id'] ?? 0 ), array_map( 'absint', (array) ( $job['ticket_ids'] ?? array() ) ), (string) ( $job['reason'] ?? '' ), (string) ( $job['key'] ?? '' ), $args );
	}

	/**
	 * Outcome of a cancellation refund: 'done' (paid out or handed to the provider), 'open' (waits
	 * for the second approval or was rejected there), 'final' (nothing left to refund) or 'retry'.
	 */
	public static function refund_outcome( $result ) {
		if ( is_wp_error( $result ) ) {
			return in_array( $result->get_error_code(), self::REFUND_FINAL_ERRORS, true ) ? 'final' : 'retry';
		}
		$status = (string) ( $result['status'] ?? '' );
		if ( in_array( $status, array( 'completed', 'processing' ), true ) ) { return 'done'; }
		return in_array( $status, array( 'pending_approval', 'rejected' ), true ) ? 'open' : 'retry';
	}

	/**
	 * Queues a failed cancellation refund as a background job (A354): retried with the job
	 * backoff, idempotent through the refund key, dead letter after Jobs::MAX_ATTEMPTS runs.
	 */
	public static function schedule_refund_retry( array $job, $error = '' ) {
		$order_id = absint( $job['order_id'] ?? 0 );
		$queued = Jobs::enqueue( 'cancellation-refund|' . $order_id . '|' . (string) ( $job['key'] ?? '' ), self::REFUND_JOB, $job, gmdate( 'Y-m-d H:i:s', time() + Jobs::backoff_seconds( 1 ) ) );
		Audit::record( 'cancellation_refund_retry_scheduled', 'order', $order_id, array( 'occurrence_id' => absint( $job['occurrence_id'] ?? 0 ), 'error' => (string) $error ) );
		return true === $queued;
	}

	/** wpcap_process_job: retries a failed cancellation refund; also runs legacy batch jobs. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( true !== $handled && in_array( $type, array( self::JOB_HOOKS, self::JOB_REFUNDS, self::JOB_NOTIFY ), true ) ) {
			return self::handle_legacy_job( $type, (array) $payload, $job_id );
		}
		if ( self::REFUND_JOB !== $type || true === $handled ) { return $handled; }
		$payload = (array) $payload;
		$result = self::request_refund( $payload );
		$outcome = self::refund_outcome( $result );
		if ( 'retry' === $outcome ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'wpcap_refund_failed', (string) ( $result['error_message'] ?? 'refund failed' ) );
		}
		// Refund job of a cancellation run (A428): its outcome counts in the run's progress.
		if ( 'done' === $outcome ) { BulkRuns::count( absint( $payload['run_id'] ?? 0 ), 'refunds' ); }
		if ( 'open' === $outcome ) { BulkRuns::count( absint( $payload['run_id'] ?? 0 ), 'refunds_open' ); }
		Audit::record( 'cancellation_refund_retried', 'order', absint( $payload['order_id'] ?? 0 ), array( 'occurrence_id' => absint( $payload['occurrence_id'] ?? 0 ), 'outcome' => $outcome, 'job_id' => absint( $job_id ) ) );
		/**
		 * A cancellation refund retried in the background has finished (A354).
		 *
		 * @param array  $payload Job payload (order_id, occurrence_id, mode, key, …).
		 * @param string $outcome done, open or final.
		 */
		do_action( 'wpcap_cancellation_refund_retried', $payload, $outcome );
		return true;
	}

	/** Marks the start of a whole-event cancellation: holders get the event mail instead. */
	public static function begin_event_batch() {
		if ( 0 === self::$event_batch ) { self::$batch_deadline = time() + max( 0, (int) self::$inline_seconds ); }
		++self::$event_batch;
	}

	public static function end_event_batch() {
		self::$event_batch = max( 0, self::$event_batch - 1 );
		if ( 0 === self::$event_batch ) { self::$batch_deadline = 0; }
	}

	/** Summary counters of the last cancellation handled for an occurrence in this request (per site prefix). */
	public static function last_summary( $occurrence_id ) {
		global $wpdb;
		$key = $wpdb->prefix . '|' . absint( $occurrence_id );
		if ( isset( self::$summaries[ $key ] ) ) { return self::$summaries[ $key ]; }
		$run = BulkRuns::occurrence_run( $occurrence_id );
		return $run ? BulkRuns::summary( $run ) : array();
	}

	/**
	 * wpcap_occurrence_cancelled: starts (or continues) the occurrence's cancellation run (A428,
	 * BulkRuns) and works on it within the time budget; the rest continues in the background.
	 *
	 * @return array Summary counters (cancelled_orders, refunds, refund_errors, revoked, notified, complete, …).
	 */
	public static function handle( $occurrence_id ) {
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		if ( ! $occurrence_id || ! $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) ) ) { return array(); }
		$run = BulkRuns::start( 'occurrence_cancel', $occurrence_id, 'occurrence_cancel|' . $occurrence_id, array( 'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ), 'event_batch' => self::$event_batch > 0 ) );
		$run = BulkRuns::drive( $run );
		$summary = BulkRuns::summary( $run );
		self::$summaries[ $wpdb->prefix . '|' . $occurrence_id ] = $summary;
		return $summary;
	}

	/**
	 * Mail type and placeholders of the cancellation mail of an occurrence (rendered when the queued
	 * mail is sent, in the recipient's locale).
	 *
	 * @return array|null type, vars.
	 */
	public static function mail_spec( $occurrence_id, $auto_refund ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $occurrence_id ) ) );
		if ( ! $occ ) { return null; }
		$tz = Admission\Engine::timezone( $occ->timezone );
		$auto_refund = (bool) $auto_refund;
		return array(
			'type' => 'occurrence_cancelled',
			'vars' => array(
				'event_name' => (string) $occ->event_name,
				'event_date' => \PassCaptain\Frontend\Format::local( $occ->starts_at, $tz ),
				'event_location' => (string) $occ->location,
				'timezone' => (string) $occ->timezone,
				// Translated in the recipient's locale.
				'refund_note' => static function () use ( $auto_refund ) { return $auto_refund ? __( 'Der Ticketpreis wird automatisch erstattet.', 'pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'pass-captain' ); },
			),
		);
	}

	/* ---------------------------------------------------------------- Legacy batch jobs */

	/**
	 * Progress of the follow-up batches of an occurrence cancellation (admin status).
	 *
	 * @return int[] status => number of batches (queued, running, complete, dead_letter).
	 */
	public static function progress( $occurrence_id ) {
		global $wpdb;
		$out = array( 'queued' => 0, 'running' => 0, 'complete' => 0, 'dead_letter' => 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n FROM %i WHERE job_type IN (%s,%s,%s) AND payload LIKE %s GROUP BY status', $wpdb->prefix . 'wpcap_jobs', self::JOB_HOOKS, self::JOB_REFUNDS, self::JOB_NOTIFY, '{"occurrence_id":' . absint( $occurrence_id ) . ',%' ) );
		foreach ( $rows as $row ) { $out[ (string) $row->status ] = (int) $row->n; }
		return $out;
	}

	/** Runs one legacy batch job of a cancellation (see handle_job()). */
	private static function handle_legacy_job( $type, array $payload, $job_id = 0 ) {
		$run = (string) ( $payload['run'] ?? '' );
		$occurrence_id = absint( $payload['occurrence_id'] ?? 0 );
		if ( self::JOB_HOOKS === $type ) {
			// Per-ticket hooks (wallet, webhooks, SMS, seats) after the revoke has been committed.
			Orders::fire_revoked( array_map( 'absint', (array) ( $payload['ticket_ids'] ?? array() ) ), 'occurrence_cancelled' );
			return true;
		}
		if ( self::JOB_REFUNDS === $type ) {
			$orders = array_values( (array) ( $payload['orders'] ?? array() ) );
			$counts = self::refund_orders( $occurrence_id, $orders, (string) ( $payload['key'] ?? 'occurrence-cancelled-' . $occurrence_id ), Jobs\TimeBudget::deadline() );
			$rest = array_slice( $orders, (int) $counts['done'] );
			if ( $rest ) {
				// Time budget used up: the remaining orders become a new job (refunds are idempotent per order and key).
				$requeued = Jobs::enqueue( 'occ-cancel-rest-' . absint( $job_id ) . '-' . count( $rest ) . '-' . substr( hash( 'sha256', (string) wp_json_encode( $rest ) ), 0, 12 ), self::JOB_REFUNDS, array_merge( $payload, array( 'orders' => $rest ) ) );
				if ( is_wp_error( $requeued ) ) { return $requeued; } // Retry this job as a whole (already refunded orders are skipped).
			}
			unset( $counts['done'] );
			self::count( $run, $counts );
			return true;
		}
		$queued = self::notify( $occurrence_id, (array) ( $payload['recipients'] ?? array() ), ! empty( $payload['auto_refund'] ), $run );
		if ( is_wp_error( $queued ) ) { return $queued; }
		self::count( $run, array( 'notified' => $queued ) );
		return true;
	}

	private static function count( $run, array $counts ) {
		if ( ! isset( self::$counters[ $run ] ) ) { return; }
		foreach ( $counts as $key => $n ) { self::$counters[ $run ][ $key ] = ( self::$counters[ $run ][ $key ] ?? 0 ) + (int) $n; }
	}

	/**
	 * Queues the cancellation mail for one batch of holders (one message per recipient, dedupe key
	 * occ-cancel|occurrence|run|e-mail) and sends as many as the time budget allows. A job retry after a
	 * worker crash queues nothing twice and only sends what is still queued; a failed mail is retried
	 * per recipient by the message queue.
	 *
	 * @return int|\WP_Error Number of recipients queued; WP_Error when a message could not be stored (job retry).
	 */
	private static function notify( $occurrence_id, array $recipients, $auto_refund, $run = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $occurrence_id ) ) );
		if ( ! $occ ) { return 0; }
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$vars = array(
			'event_name' => (string) $occ->event_name,
			'event_date' => wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz ),
			'event_location' => (string) $occ->location,
			'timezone' => (string) $occ->timezone,
			// The refund note is derived when the mail is rendered, in the recipient's locale (Mail\Types).
			'auto_refund' => $auto_refund ? 1 : 0,
		);
		$ref = 'occ-cancel|' . absint( $occurrence_id ) . '|' . (string) $run;
		$keys = array();
		$error = null;
		foreach ( $recipients as $recipient ) {
			$email = (string) ( $recipient['email'] ?? '' );
			if ( ! is_email( $email ) ) { continue; }
			$key = $ref . '|' . strtolower( $email );
			$queued = Communications::enqueue_mail( $key, $email, 'occurrence_cancelled', $vars, (string) ( $recipient['locale'] ?? '' ), $ref );
			if ( true === $queued ) { $keys[] = $key; } else { $error = $queued; }
		}
		Communications::run_now( $keys, Jobs\TimeBudget::deadline() );
		return $error ? $error : count( $keys );
	}

	/**
	 * Refunds the still open tickets of an occurrence in paid native orders when `auto_refund_cancelled` is
	 * enabled (WooCommerce orders get the `wpcap_occurrence_cancelled_woocommerce_order` signal instead).
	 * Synchronous variant for direct calls; handle() reads the same plan before revoking and refunds
	 * in job batches afterwards (revoked tickets of a cancelled occurrence stay refundable).
	 *
	 * @return int[] ['refunds' => n, 'refund_errors' => n]
	 */
	public static function refund_paid( $occurrence_id, $key ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		// Ticket ids are read per order (GROUP_CONCAT would be truncated for large orders).
		$paid = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT ord.order_id,ord.payment_provider FROM %i ord INNER JOIN %i t ON t.order_id=ord.order_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in')", $p . 'wpcap_orders', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $occurrence_id ) );
		foreach ( $paid as $row ) {
			$row->ticket_ids = implode( ',', $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND tt.occurrence_id=%d AND t.refunded=0 AND t.status IN ('valid','checked_in')", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', (int) $row->order_id, $occurrence_id ) ) );
		}
		$orders = array();
		foreach ( $paid as $row ) {
			$orders[] = array( 'order_id' => (int) $row->order_id, 'provider' => (string) $row->payment_provider, 'ticket_ids' => array_map( 'absint', explode( ',', $row->ticket_ids ) ) );
		}
		$counts = self::refund_orders( $occurrence_id, $orders, $key );
		unset( $counts['done'] );
		return $counts;
	}

	/**
	 * Refunds one batch of the refund plan (see refund_paid()); tickets may already be revoked by the
	 * cancellation (they stay refundable while the occurrence is cancelled).
	 *
	 * @param array[]    $orders   order_id, provider, ticket_ids.
	 * @param float|null $deadline Time budget (microtime): no further order is started after it.
	 * @return int[] ['refunds' => n, 'refund_errors' => n, 'done' => orders handled (the rest is left for the caller)]
	 */
	private static function refund_orders( $occurrence_id, array $orders, $key, $deadline = null ) {
		$counts = array( 'refunds' => 0, 'refund_errors' => 0, 'done' => 0 );
		foreach ( $orders as $row ) {
			// At least one order per call, then only while the time budget lasts (the caller queues the rest).
			if ( null !== $deadline && $counts['done'] > 0 && Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			++$counts['done'];
			$row = (object) $row;
			if ( 'woocommerce' === $row->provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, $occurrence_id );
				continue;
			}
			if ( ! Settings::get( 'auto_refund_cancelled' ) ) { continue; }
			$ticket_ids = array_values( array_filter( array_map( 'absint', (array) $row->ticket_ids ) ) );
			if ( ! $ticket_ids ) { continue; }
			// Refund rule for tickets already used for admission (setting refund_after_checkin):
			// deny = refund only unused tickets; approval = used tickets wait for a second approval.
			$policy = Refunds::checkin_policy();
			$checked_in = 'allow' === $policy ? array() : Refunds::checked_in_ids( $ticket_ids );
			if ( $checked_in ) {
				$ticket_ids = array_values( array_diff( $ticket_ids, $checked_in ) );
				if ( 'approval' === $policy ) {
					$pending = Refunds::request( (int) $row->order_id, $checked_in, __( 'Termin abgesagt (bereits eingecheckt)', 'pass-captain' ), $key . '-checked-in' );
					Audit::record( 'occurrence_refund_checked_in_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'tickets' => count( $checked_in ), 'status' => is_wp_error( $pending ) ? $pending->get_error_code() : (string) ( $pending['status'] ?? '' ) ) );
				} else {
					Audit::record( 'occurrence_refund_checked_in_skipped', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'tickets' => count( $checked_in ) ) );
				}
				if ( ! $ticket_ids ) { continue; }
			}
			$result = Refunds::request( (int) $row->order_id, $ticket_ids, __( 'Termin abgesagt', 'pass-captain' ), $key );
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) ) {
				++$counts['refund_errors'];
				Audit::record( 'occurrence_auto_refund_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : ( $result['status'] ?? '' ) ) );
			} else {
				++$counts['refunds'];
			}
		}
		return $counts;
	}
}
