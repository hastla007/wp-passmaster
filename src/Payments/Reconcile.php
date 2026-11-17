<?php
/**
 * Keeps provider payments and plugin orders in step where webhooks alone are not enough.
 *
 * - Reconciliation (A352): webhooks can be lost (endpoint down, wrong secret, provider outage). Every
 *   five minutes (cron wpcap_process_jobs, run()) the provider is asked about orders that may be behind:
 *   pending orders with a provider payment, at least MIN_AGE old and at most MAX_AGE_DAYS; orders that
 *   lapsed (expired) or were cancelled here in the last CLOSED_DAYS – the buyer may have paid anyway
 *   (late payment). The answer (PaymentLookupInterface::find_payment()) is applied through
 *   Webhooks::process() like a webhook (idempotent per event id, all state-machine rules). Each order
 *   is checked again with a backoff that grows with its age.
 * - Status poll (gateways without PaymentLookupInterface that offer poll_payment()): up to 20 pending
 *   orders older than `payment_poll_after_minutes` (default 15, at most 2 days old), each at most every
 *   15 minutes, applied via Webhooks::process() as well.
 * - Void on cancellation/expiry: `wpcap_order_cancelled` queues a job that annuls the still open
 *   payment at the provider through the gateway's optional void_payment() (Stripe: expire the
 *   Checkout Session; Mollie: DELETE when isCancelable; PayPal: nothing – a closed order is never
 *   captured). Not for cancellations caused by the provider itself (payment failed, refund of an
 *   unpaid order).
 *
 * Refunds are reconciled by Refunds (job refund_reconcile for unclear outcomes and pending refunds,
 * repair_stuck() for refunds stuck in "processing"). No settlement import: fees, payouts and balance
 * transactions stay in the provider's reports and can be matched with the ledger export.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Jobs;
use PassCaptain\Jobs\TimeBudget;
use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reconcile {
	const VOID_JOB = 'payment_void';

	/** Polled orders per run. */
	const POLL_LIMIT = 20;

	public static function register() {
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'order_cancelled' ), 20, 2 );
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 4 );
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'run_poll' ), 5, 0 );
	}

	/** wpcap_order_cancelled: queue the void of an open provider payment (not for provider-side cancellations). */
	public static function order_cancelled( $order_id, $reason = '' ) {
		$reason = (string) $reason;
		if ( preg_match( '/_(payment_failed|refund_unpaid)$/', $reason ) ) { return false; }
		$order = Orders::get( absint( $order_id ) );
		if ( ! $order || '' === (string) $order->provider_reference || 'paid' === (string) $order->payment_status ) { return false; }
		$gateway = Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! method_exists( $gateway, 'void_payment' ) ) { return false; }
		return true === Jobs::enqueue( 'payment-void|' . (int) $order->order_id . '|' . (string) $order->provider_reference, self::VOID_JOB, array( 'order_id' => (int) $order->order_id, 'reference' => (string) $order->provider_reference ) );
	}

	/** Job: void the provider payment of a cancelled/expired order. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( self::VOID_JOB !== $type ) { return $handled; }
		$payload = (array) $payload;
		$order = Orders::get( absint( $payload['order_id'] ?? 0 ) );
		if ( ! $order || 'paid' === (string) $order->payment_status ) { return true; }
		$gateway = Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! method_exists( $gateway, 'void_payment' ) ) { return true; }
		$data = (array) $order;
		$data['provider_reference'] = (string) ( $payload['reference'] ?? $order->provider_reference );
		$result = $gateway->void_payment( $data );
		Audit::record( is_wp_error( $result ) ? 'payment_void_failed' : 'payment_voided', 'order', (int) $order->order_id, array( 'provider' => (string) $order->payment_provider, 'error' => is_wp_error( $result ) ? $result->get_error_code() : '' ) );
		return ! is_wp_error( $result );
	}

	/** Action wpcap_process_jobs: status poll (see poll_pending()). */
	public static function run_poll() {
		self::poll_pending();
	}

	/**
	 * Polls pending orders whose webhook may have been lost.
	 *
	 * @return int Number of orders polled.
	 */
	public static function poll_pending() {
		global $wpdb;
		$minutes = max( 5, (int) Settings::get( 'payment_poll_after_minutes' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id,payment_provider FROM %i WHERE payment_status='pending' AND provider_reference<>'' AND payment_provider IN ('stripe','mollie','paypal') AND created_at<%s AND created_at>%s ORDER BY order_id LIMIT %d", $wpdb->prefix . 'wpcap_orders', gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), self::POLL_LIMIT ) );
		$polled = 0;
		$lookup = self::gateways(); // Covered by run() (find_payment()).
		foreach ( $rows as $row ) {
			if ( isset( $lookup[ (string) $row->payment_provider ] ) ) { continue; }
			if ( ! RateLimit::allow( 'psp-poll|' . (int) $row->order_id, 1, 15 * MINUTE_IN_SECONDS ) ) { continue; }
			++$polled;
			self::poll_order( (int) $row->order_id );
		}
		return $polled;
	}

	/**
	 * Asks the provider about one pending order and applies the result through Webhooks::process().
	 *
	 * @return string|\WP_Error|null Webhooks result, null when there was nothing to apply.
	 */
	public static function poll_order( $order_id ) {
		$order = Orders::get( absint( $order_id ) );
		if ( ! $order || 'pending' !== (string) $order->payment_status ) { return null; }
		$gateway = Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! method_exists( $gateway, 'poll_payment' ) ) { return null; }
		$event = $gateway->poll_payment( (array) $order );
		if ( is_wp_error( $event ) ) {
			Audit::record( 'payment_poll_failed', 'order', (int) $order->order_id, array( 'provider' => (string) $order->payment_provider, 'error' => $event->get_error_code() ) );
			return $event;
		}
		if ( ! is_array( $event ) || 'ignored' === ( $event['type'] ?? '' ) || (string) ( $event['order_public_id'] ?? '' ) !== (string) $order->public_id ) { return null; }
		$result = Webhooks::process( (string) $order->payment_provider, $event );
		Audit::record( 'payment_polled', 'order', (int) $order->order_id, array( 'provider' => (string) $order->payment_provider, 'type' => (string) $event['type'], 'result' => is_wp_error( $result ) ? $result->get_error_code() : (string) $result ) );
		return $result;
	}

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
		$now = null === $now ? \PassCaptain\Clock::now() : (int) $now;
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
