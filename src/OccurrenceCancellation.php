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
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OccurrenceCancellation {
	/** Nesting depth of an event cancellation in progress (suppresses per-occurrence mails). */
	private static $event_batch = 0;

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

	/** wpcap_process_job: retries a failed cancellation refund. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
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
		++self::$event_batch;
	}

	public static function end_event_batch() {
		self::$event_batch = max( 0, self::$event_batch - 1 );
	}

	/** Summary counters of the last cancellation handled for an occurrence in this request. */
	public static function last_summary( $occurrence_id ) {
		if ( isset( self::$summaries[ absint( $occurrence_id ) ] ) ) { return self::$summaries[ absint( $occurrence_id ) ]; }
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
		if ( ! $occurrence_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) ) ) { return array(); }
		$run = BulkRuns::start( 'occurrence_cancel', $occurrence_id, 'occurrence_cancel|' . $occurrence_id, array( 'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ), 'event_batch' => self::$event_batch > 0 ) );
		$run = BulkRuns::drive( $run );
		$summary = BulkRuns::summary( $run );
		self::$summaries[ $occurrence_id ] = $summary;
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
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
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
}
