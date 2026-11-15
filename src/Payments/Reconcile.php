<?php
/**
 * Keeps provider payments and plugin orders in step where webhooks alone are not enough.
 *
 * - Void on cancellation/expiry: `wpcap_order_cancelled` queues a job that annuls the still open
 *   payment at the provider through the gateway's optional void_payment() (Stripe: expire the
 *   Checkout Session; Mollie: DELETE when isCancelable; PayPal: nothing – a closed order is never
 *   captured). Not for cancellations caused by the provider itself (payment failed, refund of an
 *   unpaid order).
 * - Status poll: every job run (5 minutes) up to 20 pending orders with a provider reference that are
 *   older than `payment_poll_after_minutes` (default 15, at most 2 days old) are asked at the provider
 *   through the optional poll_payment(); the result is applied via Webhooks::process() with a
 *   synthetic or the webhook's own event id, i.e. idempotent and with the same checks (amount,
 *   currency, attempt). Each order is polled at most every 15 minutes.
 *
 * No settlement import: the poll covers the only ticket-relevant gap (a payment whose webhook got
 * lost); fees, payouts and balance transactions are accounting data that stay in the provider's
 * reports and can be matched with the ledger export (Invoices::ledger_export_rows(): order id,
 * payment reference, refunds).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Jobs;
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
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
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
	public static function handle_job( $handled, $type, $payload = array() ) {
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
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id FROM %i WHERE payment_status='pending' AND provider_reference<>'' AND payment_provider IN ('stripe','mollie','paypal') AND created_at<%s AND created_at>%s ORDER BY order_id LIMIT %d", $wpdb->prefix . 'wpcap_orders', gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), self::POLL_LIMIT ) );
		$polled = 0;
		foreach ( $rows as $row ) {
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
}
