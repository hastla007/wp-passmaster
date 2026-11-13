<?php
/**
 * Consequences of cancelling a single occurrence (wpcap_occurrence_cancelled):
 * open and expired unpaid orders are cancelled and their holds released, valid tickets are revoked, ticket holders
 * are informed, and – when `auto_refund_cancelled` is enabled – paid native orders are refunded
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
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 4 );
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
		return self::$summaries[ absint( $occurrence_id ) ] ?? array();
	}

	/** @return array Summary counters. */
	public static function handle( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
		if ( ! $occ ) { return array(); }
		$summary = array( 'cancelled_orders' => 0, 'refunds' => 0, 'refund_errors' => 0, 'revoked' => 0, 'notified' => 0 );

		// Recipients first, while tickets are still valid.
		$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(t.attendee_email,''),ord.customer_email) AS email,MAX(ord.locale) AS locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid' GROUP BY email", $occurrence_id ) );

		// 1. Unpaid orders touching this occurrence: cancel (releases holds, campaigns, vouchers). Expired
		// orders too (A273): a payment that still arrives for them (hosted page left open, delayed method)
		// then meets a cancelled order and issues no tickets (webhook result `paid_for_closed_order`).
		$pending = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status IN ('pending','expired')", $occurrence_id ) );
		foreach ( $pending as $order_id ) {
			if ( true === Orders::cancel_order( (int) $order_id, 'occurrence_cancelled' ) ) {
				++$summary['cancelled_orders'];
			} // Else a retry job finishes it (Orders::cancel_order).
		}

		// 2. Paid orders: optional automatic refund of the affected tickets (before they are revoked).
		$refunded = self::refund_paid( $occurrence_id, 'occurrence-cancelled-' . $occurrence_id );
		$summary['refunds'] = $refunded['refunds'];
		$summary['refund_errors'] = $refunded['refund_errors'];

		// 3. Remaining valid tickets become invalid for admission.
		$revoked_ids = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", $occurrence_id ) ) );
		$summary['revoked'] = count( $revoked_ids );
		Orders::fire_revoked( $revoked_ids, 'occurrence_cancelled' );

		// 4. Inform holders.
		$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		$when = wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz );
		$auto_refund = (bool) Settings::get( 'auto_refund_cancelled' );
		$vars = array(
			'event_name' => (string) $occ->event_name,
			'event_date' => $when,
			'event_location' => (string) $occ->location,
			'timezone' => (string) $occ->timezone,
			// Translated in the recipient's locale.
			'refund_note' => static function () use ( $auto_refund ) { return $auto_refund ? __( 'Der Ticketpreis wird automatisch erstattet.', 'pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'pass-captain' ); },
		);
		// A140: the cancelled .ics (same UID, higher SEQUENCE, STATUS:CANCELLED) updates imported entries.
		$files = ! self::$event_batch && $recipients ? Notifications::ics_file( $occurrence_id ) : array();
		try {
			foreach ( self::$event_batch ? array() : $recipients as $recipient ) {
				if ( is_email( $recipient->email ) && Mail\Mailer::send( 'occurrence_cancelled', $recipient->email, $vars, $files, (string) $recipient->locale ) ) { ++$summary['notified']; }
			}
		} finally {
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) { wp_delete_file( $file ); }
			}
		}
		Audit::record( 'occurrence_cancellation_processed', 'occurrence', $occurrence_id, $summary );
		self::$summaries[ $occurrence_id ] = $summary;
		return $summary;
	}

	/**
	 * Refunds the still open tickets of an occurrence in paid native orders when `auto_refund_cancelled` is
	 * enabled (WooCommerce orders get the `wpcap_occurrence_cancelled_woocommerce_order` signal instead).
	 * Must run before the tickets are revoked: revoked tickets can no longer be refunded.
	 *
	 * @return int[] ['refunds' => n, 'refund_errors' => n]
	 */
	public static function refund_paid( $occurrence_id, $key ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$counts = array( 'refunds' => 0, 'refund_errors' => 0 );
		// Ticket ids are read per order (GROUP_CONCAT would be truncated for large orders).
		$paid = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT ord.order_id,ord.payment_provider FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_tickets t ON t.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND t.refunded=0 AND t.status IN ('valid','checked_in')", $occurrence_id ) );
		foreach ( $paid as $row ) {
			$row->ticket_ids = implode( ',', $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id=%d AND tt.occurrence_id=%d AND t.refunded=0 AND t.status IN ('valid','checked_in')", (int) $row->order_id, $occurrence_id ) ) );
		}
		foreach ( $paid as $row ) {
			if ( 'woocommerce' === $row->payment_provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, $occurrence_id );
				continue;
			}
			if ( ! Settings::get( 'auto_refund_cancelled' ) ) { continue; }
			$job = array( 'mode' => 'tickets', 'order_id' => (int) $row->order_id, 'occurrence_id' => $occurrence_id, 'ticket_ids' => array_map( 'absint', explode( ',', $row->ticket_ids ) ), 'reason' => __( 'Termin abgesagt', 'pass-captain' ), 'key' => $key );
			$result = self::request_refund( $job );
			$outcome = self::refund_outcome( $result );
			if ( 'done' === $outcome ) {
				++$counts['refunds'];
				continue;
			}
			++$counts['refund_errors'];
			$error = is_wp_error( $result ) ? $result->get_error_code() : ( $result['status'] ?? '' );
			Audit::record( 'occurrence_auto_refund_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'error' => $error ) );
			if ( 'retry' === $outcome ) { self::schedule_refund_retry( $job, $error ); }
		}
		return $counts;
	}
}
