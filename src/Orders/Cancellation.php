<?php
/**
 * Order cancellation, refund marking and event cancellation.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Audit;
use PassCaptain\BulkRuns;
use PassCaptain\Calendar;
use PassCaptain\Campaigns;
use PassCaptain\CapacityCounters;
use PassCaptain\CapacityRelease;
use PassCaptain\Clock;
use PassCaptain\Errors;
use PassCaptain\Jobs;
use PassCaptain\Log;
use PassCaptain\Orders;
use PassCaptain\PageCache;
use PassCaptain\Settings;
use PassCaptain\StateMachine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::cancel_order(), mark_refunded(), cancel_event() and
 * resume_event_cancellation(). Moved out of Orders without behaviour change (TODO "Gott-Klasse Orders").
 * Transactions are started and checked through Orders\Transaction.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Cancellation {
	/**
	 * Cancels an order: releases holds and campaign reservations; for paid orders all still valid
	 * tickets are revoked (checked-in tickets stay as historical record). Idempotent. Every database
	 * step and the commit are checked: on an error the transaction is rolled back (nothing changed, no
	 * side effects fired) and a retryable WP_Error (`wpcap_db_busy`) is returned, so a webhook is
	 * redelivered instead of being recorded as processed.
	 *
	 * The third parameter guards the cancellation by the payment status read under the row lock:
	 * - string[] (A267 style): cancel only while the payment status is one of these; otherwise WP_Error
	 *   `wpcap_order_state` (e.g. a "payment failed" event that lost the race against the confirmation);
	 * - true (only unpaid): cancel only while the order was never paid (payment status `pending` or
	 *   `expired`); otherwise WP_Error `wpcap_order_paid_state` (provider refund events of unpaid orders
	 *   must not cancel a payment that was booked in the meantime).
	 *
	 * @param int           $order_id            Order.
	 * @param string        $reason              Audit reason.
	 * @param string[]|bool $only_payment_status Optional guard (see above).
	 * @return true|\WP_Error
	 */
	public static function cancel_order( $order_id, $reason = '', $only_payment_status = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$now = Clock::mysql();
		$only_unpaid = true === $only_payment_status;
		$only_payment_status = $only_unpaid ? array( 'pending', 'expired' ) : array_map( 'strval', (array) $only_payment_status );
		$busy = static function ( $step, $order = null ) use ( $wpdb, $order_id, $reason ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			Log::error( 'order_state_db_error', 'Order state change hit a database error.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ), 'error' => \PassCaptain\Text::cut( $error, 200 ) ) ) );
			\PassCaptain\Log::error( 'order_cancel_failed', 'order cancel failed', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ) ) ) );
			if ( $order ) {
				// Safety net for callers that cannot retry themselves (hooks, sweeps): a job repeats the
				// cancellation, but only while the payment status is still the one seen here.
				Jobs::enqueue( 'order-cancel-retry|' . $order_id . '|' . $order->payment_status, 'order_cancel_retry', array( 'order_id' => $order_id, 'reason' => (string) $reason, 'payment_status' => (string) $order->payment_status ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
			}
			return Errors::busy( __( 'Die Bestellung konnte gerade nicht storniert werden. Bitte erneut versuchen.', 'pass-captain' ), 'wpcap_db_busy', array( 'step' => (string) $step ) );
		};
		if ( false === Transaction::begin() ) {
			return $busy( 'cancel_start' );
		}
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return $busy( 'cancel_lock' );
		}
		if ( ! $order || in_array( $order->status, array( 'cancelled', 'refunded' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			// A repeated call heals counters whose post-commit sync failed last time (idempotent).
			if ( $order ) { CapacityCounters::sync_order_now( $order_id ); }
			return true;
		}
		if ( $only_payment_status && ! in_array( (string) $order->payment_status, $only_payment_status, true ) ) {
			// E.g. a "payment failed" event that lost the race against the confirmation: nothing to cancel.
			$wpdb->query( 'COMMIT' );
			return new \WP_Error( $only_unpaid ? 'wpcap_order_paid_state' : 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409, 'payment_status' => (string) $order->payment_status ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='released' WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) ) ) {
			return $busy( 'cancel_release', $order );
		}
		$revoked_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' FOR UPDATE", $p . 'wpcap_tickets', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return $busy( 'cancel_tickets', $order ); }
		if ( $revoked_ids && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE order_id=%d AND status='valid'", $p . 'wpcap_tickets', $order_id ) ) ) {
			return $busy( 'cancel_revoke', $order );
		}
		// The whole order stops counting; every ticket records that its unit is released (CapacityRelease).
		if ( ! CapacityRelease::mark_order( $order_id ) ) {
			return $busy( 'cancel_release_units', $order );
		}
		$revoked = count( $revoked_ids );
		$updated = StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'cancelled', array( 'payment_status' => 'cancelled', 'updated_at' => $now ) );
		if ( 1 !== (int) $updated ) {
			return $busy( 'cancel_order', $order );
		}
		if ( false === Transaction::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) {
			return $busy( 'cancel_commit', $order );
		}
		// Released units leave the counters right after the commit (until then they only over-count).
		CapacityCounters::sync_order_now( $order_id );
		Campaigns::release_for_order( $order_id );
		Transaction::capacity_released( $order_id, 'cancelled', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_cancelled', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		Orders::fire_revoked( $revoked_ids, 'order_cancelled' );
		do_action( 'wpcap_order_cancelled', $order_id, (string) $reason, (string) $order->payment_status );
		return true;
	}

	/**
	 * Marks an order as fully refunded and revokes all still valid tickets. Idempotent: an order that
	 * is already refunded is left alone, so repeated provider events change nothing.
	 *
	 * Runs in its own transaction with the order row locked; every write and the commit are checked.
	 * On a database error nothing is changed and a retryable WP_Error (`wpcap_db_busy`) is returned.
	 * Callers must not hold an open transaction (START TRANSACTION would commit it implicitly).
	 *
	 * @return true|\WP_Error
	 */
	public static function mark_refunded( $order_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		// The money is already back with the buyer: a failure is repaired by a job, never dropped.
		$busy = static function ( $step ) use ( $wpdb, $order_id, $reason ) {
			$error = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			Log::error( 'order_state_db_error', 'Order state change hit a database error.', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ), 'error' => \PassCaptain\Text::cut( $error, 200 ) ) ) );
			\PassCaptain\Log::error( 'order_refund_mark_failed', 'order refund mark failed', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'step' => sanitize_key( $step ) ) ) );
			Jobs::enqueue( 'order-refund-mark-retry|' . $order_id, 'order_refund_mark_retry', array( 'order_id' => $order_id, 'reason' => (string) $reason ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
			return Errors::busy( __( 'Die Bestellung konnte gerade nicht als erstattet markiert werden; das wird automatisch nachgeholt.', 'pass-captain' ), 'wpcap_db_busy', array( 'step' => (string) $step ) );
		};
		if ( false === Transaction::begin() ) { return $busy( 'refund_start' ); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy( 'refund_lock' ); }
		if ( ! $order || 'refunded' === $order->status ) { $wpdb->query( 'COMMIT' ); return true; }
		if ( ! StateMachine::allowed( 'order', $order->status, 'refunded' ) ) {
			// Never paid (pending_payment, expired): there is nothing to refund. Final, no retry job.
			$wpdb->query( 'COMMIT' );
			Audit::record( 'order_refund_mark_rejected', 'order', $order_id, array( 'status' => (string) $order->status, 'reason' => sanitize_text_field( $reason ) ) );
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='released' WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) ) ) { return $busy( 'refund_release' ); }
		$revoked_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND status='valid' FOR UPDATE", $p . 'wpcap_tickets', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return $busy( 'refund_tickets' ); }
		if ( $revoked_ids && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE order_id=%d AND status='valid'", $p . 'wpcap_tickets', $order_id ) ) ) { return $busy( 'refund_revoke' ); }
		if ( ! CapacityRelease::mark_order( $order_id ) ) { return $busy( 'refund_release_units' ); }
		$revoked = count( $revoked_ids );
		$updated = StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'refunded', array( 'payment_status' => 'refunded', 'updated_at' => Clock::mysql() ) );
		if ( 1 !== (int) $updated ) { return $busy( 'refund_order' ); }
		if ( false === Transaction::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return $busy( 'refund_commit' ); }
		CapacityCounters::sync_order_now( $order_id );
		Transaction::capacity_released( $order_id, 'refunded', array( 'reason' => (string) $reason ) );
		Audit::record( 'order_refunded', 'order', $order_id, array( 'reason' => sanitize_text_field( $reason ), 'revoked_tickets' => $revoked ) );
		Orders::fire_revoked( $revoked_ids, $reason ?: 'refunded' );
		/** Order fully refunded (native refund, WooCommerce or provider dashboard). */
		do_action( 'wpcap_order_refunded', $order_id, (string) $reason );
		return true;
	}

	/**
	 * Cancels a published event. Every occurrence that is not cancelled yet goes through the regular
	 * occurrence cancellation (`wpcap_occurrence_cancelled` → OccurrenceCancellation): open orders
	 * are cancelled, paid native orders are refunded when `auto_refund_cancelled` is on, remaining
	 * tickets are revoked and module listeners (SMS, wallet passes, webhooks, reminders) run.
	 * Buyers get one "event cancelled" mail instead of one mail per occurrence. Tickets revoked
	 * this way stay refundable (see refundable_tickets()).
	 *
	 * @return array|\WP_Error {status, notified_count, occurrences, cancelled_orders, refunds, refund_errors, revoked, auto_refund}
	 */
	public static function cancel_event( $event_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$started = microtime( true );
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id,name,status FROM %i WHERE event_id=%d', $p . 'wpcap_events', $event_id ) );
		if ( ! $event || 'published' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'pass-captain' ) );
		}
		$order_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", $event_id ) ) );
		$pending_before = $order_ids ? array_map( 'absint', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $order_ids ) . ") AND payment_status IN ('pending','expired')" ) ) : array();

		Transaction::begin();
		$changed = $wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled', 'updated_at' => Clock::mysql() ), array( 'event_id' => $event_id, 'status' => 'published' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $changed ) {
			// A concurrent request cancelled (or changed) the event first.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_event_not_cancellable', __( 'Dieses Event kann nicht abgesagt werden.', 'pass-captain' ) );
		}
		$occurrence_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d AND status<>'cancelled' ORDER BY occurrence_id FOR UPDATE", $event_id ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=%d", $event_id ) );
		Calendar\Revisions::bump_many( $occurrence_ids ); // A140: SEQUENCE + 1 for STATUS:CANCELLED.
		$wpdb->query( 'COMMIT' );
		PageCache::event_changed( $event_id, 'event' ); // A446.

		// A428: the consequences run as a resumable run (BulkRuns): event mails are queued first (while
		// the orders are unchanged), then every occurrence is cancelled in its own run, refunds run as
		// jobs, and the work that does not fit into this request continues in the background.
		$run = BulkRuns::start( 'event_cancel', $event_id, 'event_cancel|' . $event_id, array(
			'event_name' => (string) $event->name,
			'occurrence_ids' => $occurrence_ids,
			'pending_before' => $pending_before,
			'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ),
		) );
		Audit::record( 'event_cancelled', 'event', $event_id, array( 'occurrences' => count( $occurrence_ids ), 'run_id' => (int) $run->run_id ) + ( '' !== (string) $reason ? array( 'reason' => sanitize_text_field( (string) $reason ) ) : array() ) );
		// PC-9: wpcap_event_cancelled fires from the run once the dates are cancelled and the tickets revoked (BulkRuns).
		return self::event_cancellation_result( BulkRuns::drive( $run ) );
	}

	/**
	 * Continues the cancellation of an already cancelled event (A428: "a second click resumes"):
	 * works on its run, or starts the run if the request that cancelled the event died before.
	 *
	 * @return array|\WP_Error Same keys as cancel_event() plus resumed.
	 */
	public static function resume_event_cancellation( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name,status FROM {$p}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event || 'cancelled' !== $event->status ) {
			return new \WP_Error( 'wpcap_event_not_cancelled', __( 'Dieses Event ist nicht abgesagt.', 'pass-captain' ) );
		}
		$run = BulkRuns::event_run( $event_id );
		if ( ! $run ) {
			$pending = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ord.order_id FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.payment_status='pending'", $event_id ) ) );
			$run = BulkRuns::start( 'event_cancel', $event_id, 'event_cancel|' . $event_id, array(
				'event_name' => (string) $event->name,
				'occurrence_ids' => array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d ORDER BY occurrence_id", $event_id ) ) ),
				'pending_before' => $pending,
				'auto_refund' => (bool) Settings::get( 'auto_refund_cancelled' ),
			) );
		}
		return self::event_cancellation_result( BulkRuns::drive( $run ) ) + array( 'resumed' => true );
	}

	/** Result array of an event cancellation run. */
	private static function event_cancellation_result( $run ) {
		$summary = BulkRuns::summary( $run );
		return array(
			'cancelled_orders' => (int) ( $summary['cancelled_orders'] ?? 0 ),
			'refunds' => (int) ( $summary['refunds'] ?? 0 ),
			'refund_errors' => (int) ( $summary['refund_errors'] ?? 0 ),
			'revoked' => (int) ( $summary['revoked'] ?? 0 ),
			'status' => 'cancelled',
			'notified_count' => (int) ( $summary['notified'] ?? 0 ),
			'occurrences' => count( (array) ( $run->data['occurrence_ids'] ?? array() ) ),
			'auto_refund' => ! empty( $run->data['auto_refund'] ),
			'run_id' => (int) $run->run_id,
			'complete' => ! empty( $summary['complete'] ),
		);
	}
}
