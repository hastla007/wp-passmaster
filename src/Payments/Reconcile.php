<?php
/**
 * Reconciliation of payments between provider and local state (A352).
 *
 * Webhooks can be lost (endpoint down, wrong secret, provider outage). Every five minutes (cron
 * wpcap_process_jobs) this job asks the provider about orders that may be behind:
 *  - pending orders with a provider payment, at least MIN_AGE old and at most MAX_AGE_DAYS;
 *  - orders that lapsed (expired) or were cancelled here in the last CLOSED_DAYS – the buyer may have
 *    paid anyway (late payment).
 * The answer (PaymentLookupInterface::find_payment()) is applied through Webhooks::process() like a
 * webhook (idempotent per event id, all state-machine rules). Each order is checked again with a
 * backoff that grows with its age. Refunds are reconciled by Refunds (job refund_reconcile for
 * unclear outcomes and pending refunds, repair_stuck() for refunds stuck in "processing").
 *
 * @package PassCaptain
 */

namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Jobs\TimeBudget;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reconcile {
	/** Minimum age of an order before the provider is asked (webhooks usually arrive within seconds). */
	const MIN_AGE = 600;
	/** Pending orders older than this are no longer checked. */
	const MAX_AGE_DAYS = 7;
	/** Lapsed/cancelled orders are checked this long after their last change. */
	const CLOSED_DAYS = 2;
	/** Orders checked per run at most. */
	const MAX_PER_RUN = 20;
	/** Transient prefix: time of the next check of an order. */
	const NEXT_PREFIX = 'wpcap_pay_recon_';

	/** Event types the job applies (refunds have their own reconciliation). */
	const APPLY_TYPES = array( 'paid', 'failed', 'payment_pending' );

	/** Cron callback (wpcap_process_jobs). */
	public static function cron() {
		self::run();
	}

	/** Seconds until the next check of an order of the given age (grows with the age). */
	public static function backoff( $age ) {
		$age = (int) $age;
		if ( $age < HOUR_IN_SECONDS ) { return 10 * MINUTE_IN_SECONDS; }
		if ( $age < DAY_IN_SECONDS ) { return HOUR_IN_SECONDS; }
		return 6 * HOUR_IN_SECONDS;
	}

	/**
	 * Gateways that can look up payments, by provider id.
	 *
	 * @return array<string,PaymentLookupInterface>
	 */
	public static function gateways() {
		$out = array();
		foreach ( Registry::all() as $id => $gateway ) {
			if ( $gateway instanceof PaymentLookupInterface ) { $out[ (string) $id ] = $gateway; }
		}
		return $out;
	}

	/**
	 * One reconciliation run.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return array{checked:int,applied:array<int,string>,errors:int}
	 */
	public static function run( $now = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = null === $now ? time() : (int) $now;
		$out = array( 'checked' => 0, 'applied' => array(), 'errors' => 0 );
		$gateways = self::gateways();
		if ( ! $gateways ) { return $out; }
		$providers = implode( ',', array_map( static function ( $id ) use ( $wpdb ) { return $wpdb->prepare( '%s', $id ); }, array_keys( $gateways ) ) );
		$young = gmdate( 'Y-m-d H:i:s', $now - self::MIN_AGE );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$p}wpcap_orders WHERE payment_provider IN ($providers) AND provider_reference<>'' AND created_at<=%s AND ((payment_status='pending' AND created_at>=%s) OR (payment_status IN ('expired','cancelled') AND updated_at>=%s)) ORDER BY order_id DESC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- provider list prepared above.
			$young,
			gmdate( 'Y-m-d H:i:s', $now - self::MAX_AGE_DAYS * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', $now - self::CLOSED_DAYS * DAY_IN_SECONDS )
		), ARRAY_A );
		$deadline = TimeBudget::deadline();
		foreach ( (array) $rows as $order ) {
			if ( $out['checked'] >= self::MAX_PER_RUN || TimeBudget::exhausted( $deadline ) ) { break; }
			$key = self::NEXT_PREFIX . (int) $order['order_id'];
			$next = (int) get_transient( $key );
			if ( $next > $now ) { continue; }
			$age = $now - (int) strtotime( (string) $order['created_at'] . ' UTC' );
			set_transient( $key, $now + self::backoff( $age ), self::backoff( $age ) + MINUTE_IN_SECONDS );
			++$out['checked'];
			$result = self::check( $gateways[ (string) $order['payment_provider'] ], $order );
			if ( is_wp_error( $result ) ) {
				++$out['errors'];
				continue;
			}
			if ( '' !== $result ) { $out['applied'][ (int) $order['order_id'] ] = $result; }
		}
		return $out;
	}

	/**
	 * Asks the provider about one order and applies a state change.
	 *
	 * @return string|\WP_Error Result label of Webhooks::process(), '' when nothing was applied.
	 */
	public static function check( PaymentLookupInterface $gateway, array $order ) {
		try {
			$event = $gateway->find_payment( $order );
		} catch ( \Throwable $e ) {
			$event = new \WP_Error( 'wpcap_reconcile_exception', $e->getMessage() );
		}
		if ( is_wp_error( $event ) ) { return $event; }
		if ( ! is_array( $event ) || ! in_array( (string) ( $event['type'] ?? '' ), self::APPLY_TYPES, true ) ) { return ''; }
		$type = (string) $event['type'];
		$pending = 'pending' === (string) $order['payment_status'];
		// Only a payment changes a closed order (late payment rules); "failed"/"payment_pending" only a pending one.
		if ( ! $pending && 'paid' !== $type ) { return ''; }
		// The event must belong to this order.
		if ( (string) ( $event['order_public_id'] ?? '' ) !== (string) $order['public_id'] ) { return ''; }
		$provider = method_exists( $gateway, 'id' ) ? (string) $gateway->id() : (string) $order['payment_provider'];
		$result = Webhooks::process( $provider, $event );
		if ( is_wp_error( $result ) ) { return $result; }
		$label = is_string( $result ) ? $result : 'processed';
		if ( ! in_array( $label, array( 'ignored', 'duplicate', 'already_paid' ), true ) ) {
			Audit::record( 'payment_reconciled', 'order', (int) $order['order_id'], array( 'provider' => $provider, 'type' => $type, 'result' => $label, 'local_status' => (string) $order['payment_status'] ) );
		}
		return $label;
	}
}
