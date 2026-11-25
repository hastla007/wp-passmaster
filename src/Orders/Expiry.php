<?php
/**
 * Expiry of unpaid orders whose holds lapsed.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Campaigns;
use PassCaptain\CapacityCounters;
use PassCaptain\Clock;
use PassCaptain\Jobs;
use PassCaptain\Log;
use PassCaptain\StateMachine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::expire_holds() and expire_holds_worker() (with the batch state of the last
 * run). Moved out of Orders without behaviour change (TODO "Gott-Klasse Orders"). Orders keeps
 * process_expired_holds() as the hook callback. Transactions go through Orders\Transaction, reservation
 * locks through Orders\Holds.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Expiry {
	/**
	 * Expires unpaid orders whose holds have lapsed (cron `wpcap_cleanup_reservations` hourly and the
	 * 5-minute worker `wpcap_process_jobs`, see expire_holds_worker()).
	 *
	 * Each order is expired in its own short transaction with the same lock order as the payment
	 * confirmation: order row FOR UPDATE, then its reservations by primary key FOR UPDATE (no bulk
	 * UPDATE over the reservation indexes, whose gap locks deadlocked checkouts and late payments).
	 * The side effects (campaign release, capacity released, wpcap_order_cancelled) only run for orders
	 * this call expired – an order paid between selection and lock is left alone. Lapsed holds of
	 * orders that are no longer pending are marked by id afterwards. Database errors are logged and the
	 * order is retried by the next run.
	 * At most $limit orders per run (filter `wpcap_expire_holds_batch`); the next run continues.
	 *
	 * @param int   $limit Batch size (cron passes no argument).
	 * @param int[] $only  Restrict the run to these orders (e.g. the lapsed waitlist offers): a backlog
	 *                     of older lapsed orders must not keep them pending beyond one batch.
	 * @return int Number of expired orders.
	 */
	public static function expire_holds( $limit = 200, array $only = array() ) {
		global $wpdb;
		$now = Clock::mysql();
		$reservations = $wpdb->prefix . 'wpcap_reservations';
		$orders = $wpdb->prefix . 'wpcap_orders';
		$limit = max( 1, (int) apply_filters( 'wpcap_expire_holds_batch', absint( $limit ) ?: 200 ) );
		self::$last_expire_batch_full = false;
		$only = array_values( array_unique( array_filter( array_map( 'absint', $only ) ) ) );
		$only_sql = $only ? ' AND ord.order_id IN (' . implode( ',', $only ) . ')' : '';
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT ord.order_id FROM %i ord WHERE ord.payment_status='pending' AND " . StateMachine::source_sql( 'order', 'expired', 'ord.status' ) . $only_sql . " AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>%s) ORDER BY ord.order_id LIMIT %d", $orders, $reservations, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static state list, absint ids.
			$now,
			$limit
		) );
		if ( '' !== $wpdb->last_error ) {
			Log::warning( 'expire_holds_failed', 'Expired holds could not be selected; the next run retries.', array( 'step' => 'select' ) );
			return 0;
		}
		self::$last_expire_batch_full = count( $candidates ) >= $limit;
		$expired = 0;
		$failed = array();
		foreach ( array_map( 'absint', $candidates ) as $order_id ) {
			$result = self::expire_order( $order_id, $now );
			if ( is_string( $result ) ) {
				$failed[ $order_id ] = $result;
				continue;
			}
			if ( ! $result ) { continue; }
			// The lapsed holds leave held_qty (capacity counters) right after the commit.
			CapacityCounters::sync_order_now( $order_id );
			++$expired;
			Campaigns::release_for_order( $order_id );
			Transaction::capacity_released( $order_id, 'expired' );
			do_action( 'wpcap_order_cancelled', $order_id );
		}
		// Lapsed holds of orders that are not pending any more (they no longer count anyway): by id.
		$stale = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT r.reservation_id FROM %i r INNER JOIN %i ord ON ord.order_id=r.order_id WHERE r.status='active' AND r.expires_at<=%s AND ord.payment_status<>'pending' ORDER BY r.reservation_id LIMIT %d", $reservations, $orders, $now, $limit ) ) );
		$stale_orders = ( $stale && CapacityCounters::maintained() ) ? array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $stale ), '%d' ) ) . ') ORDER BY order_id', array_merge( array( $reservations ), $stale ) ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
		if ( $stale && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired' WHERE reservation_id IN (" . implode( ',', array_fill( 0, count( $stale ), '%d' ) ) . ") AND status='active' AND expires_at<=%s", array_merge( array( $reservations ), $stale, array( $now ) ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
			$failed[0] = 'stale_holds';
		} else {
			// Their held units leave the counters (they over-counted until now).
			foreach ( $stale_orders as $stale_order_id ) { CapacityCounters::sync_order_now( $stale_order_id ); }
		}
		if ( $failed ) {
			Log::warning( 'expire_holds_failed', 'Some lapsed holds could not be expired (database busy); the next run retries.', array( 'orders' => array_slice( array_keys( $failed ), 0, 20 ), 'steps' => array_values( array_unique( $failed ) ) ) );
		}
		return $expired;
	}

	/** @var bool Whether the last expire_holds() run selected a full batch (more may be waiting). */
	private static $last_expire_batch_full = false;

	/**
	 * Expires one pending order whose holds lapsed (own transaction: order row, then its reservations).
	 *
	 * @return bool|string True when expired, false when there was nothing to do (paid, still held,
	 *                     gone), or the failed step on a database error (nothing changed).
	 */
	private static function expire_order( $order_id, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$hold_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT reservation_id FROM %i WHERE order_id=%d ORDER BY reservation_id', $p . 'wpcap_reservations', $order_id ) ) );
		if ( '' !== $wpdb->last_error ) { return 'read'; }
		if ( false === Transaction::begin() ) { return 'start'; }
		$fail = static function ( $step ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return $step;
		};
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,payment_status FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== $wpdb->last_error ) { return $fail( 'lock_order' ); }
		if ( ! $order || 'pending' !== $order->payment_status ) {
			$wpdb->query( 'COMMIT' );
			return false;
		}
		$holds = Holds::lock( $hold_ids );
		if ( false === $holds ) { return $fail( 'lock_holds' ); }
		foreach ( $holds as $hold ) {
			if ( 'active' === $hold->status && $hold->expires_at > $now ) {
				$wpdb->query( 'COMMIT' ); // Extended meanwhile (e.g. delayed payment method).
				return false;
			}
		}
		if ( false === Holds::set_status( $hold_ids, 'expired', array( 'active' ) ) ) { return $fail( 'expire_holds' ); }
		// Guarded by the state machine (pending_payment → expired); another state is left alone.
		$moved = StateMachine::transition( 'order', "{$p}wpcap_orders", 'order_id', $order_id, 'expired', array( 'payment_status' => 'expired', 'updated_at' => $now ) );
		if ( false === $moved ) { return $fail( 'expire_order' ); }
		if ( 1 !== (int) $moved ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( false === Transaction::in_transaction() || false === $wpdb->query( 'COMMIT' ) ) { return $fail( 'commit' ); }
		return true;
	}

	/**
	 * 5-minute worker (`wpcap_process_jobs`): expires lapsed holds in batches within half of the shared
	 * cron time budget, so limited discount codes and capacity come back within minutes instead of
	 * only with the hourly cleanup. A full last batch asks for an immediate follow-up run.
	 *
	 * @return int Number of expired orders.
	 */
	public static function expire_holds_worker() {
		$deadline = min( Jobs\TimeBudget::deadline(), microtime( true ) + Jobs\TimeBudget::seconds() / 2 );
		$total = 0;
		do {
			$total += self::expire_holds();
			$more = self::$last_expire_batch_full;
		} while ( $more && ! Jobs\TimeBudget::exhausted( $deadline ) );
		if ( $more ) { Jobs\TimeBudget::continue_later( 'wpcap_process_jobs' ); }
		return $total;
	}
}
