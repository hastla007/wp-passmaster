<?php
/**
 * Orchestrates Voucher Captain redemptions for native orders through the versioned Bridge.
 *
 * Flow: quote + reserve after the order (and its inventory hold) exists → order total reduced →
 * commit after confirmed payment → release on cancel/expiry → proportional refund on ticket refunds.
 * Provider calls never run inside a Pass Captain database transaction.
 *
 * Expected provider data: quote() returns an array with `amount_minor` (discount/credit applied, minor
 * units, same currency); reserve()/commit() return arrays that are stored and handed back unchanged.
 */
namespace PassCaptain\Integrations\Vouchers;

use PassCaptain\Audit;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Checkout {
	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'commit' ) );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'release' ) );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund' ), 20, 2 );
	}

	/**
	 * Applies a voucher code to a pending order.
	 *
	 * @return array|\WP_Error ['applied_minor'=>int, 'total_minor'=>int]
	 */
	public static function apply( $order_id, $code ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) { return array( 'applied_minor' => 0 ); }
		if ( ! Bridge::available() ) {
			return new \WP_Error( 'wpcap_voucher_unavailable', __( 'Gutscheine können derzeit nicht eingelöst werden.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$order = Orders::get( $order_id );
		if ( ! $order || 'pending' !== $order->payment_status || $order->voucher_snapshot ) {
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$items = Orders::items_for_order( $order_id );
		$context = array(
			'code' => $code,
			'currency' => $order->currency,
			'amount_minor' => (int) $order->total_minor,
			'tax_minor' => (int) $order->tax_minor,
			'customer_email' => $order->customer_email,
			'order_reference' => 'wpcap:' . $order->public_id,
			'lines' => array_map( static function ( $item ) {
				return array( 'ticket_type_id' => (int) $item->ticket_type_id, 'event' => $item->event_name, 'name' => $item->name, 'quantity' => (int) $item->quantity, 'line_total_minor' => (int) $item->line_total_minor, 'tax_rate_bp' => (int) $item->tax_rate_bp );
			}, $items ),
		);
		$quote = Bridge::quote( $context );
		if ( is_wp_error( $quote ) ) { return self::user_error( $quote ); }
		$amount = (int) ( $quote['amount_minor'] ?? 0 );
		if ( $amount < 1 || $amount > (int) $order->total_minor || strtoupper( (string) ( $quote['currency'] ?? $order->currency ) ) !== strtoupper( $order->currency ) ) {
			return new \WP_Error( 'wpcap_voucher_quote_invalid', __( 'Der Gutschein passt nicht zu dieser Bestellung.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$key = 'wpcap-order-' . $order->public_id;
		$reservation = Bridge::reserve( $quote, $key );
		if ( is_wp_error( $reservation ) ) { return self::user_error( $reservation ); }

		$old_total = (int) $order->total_minor;
		$new_total = $old_total - $amount;
		$new_tax = $old_total > 0 ? (int) round( (int) $order->tax_minor * $new_total / $old_total ) : 0;
		// Keep line VAT consistent with the reduced total (proportional, remainder on the last line).
		$tax_left = $new_tax;
		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$line_tax = $index === $last ? $tax_left : ( (int) $order->tax_minor > 0 ? (int) round( (int) $item->tax_minor * $new_tax / (int) $order->tax_minor ) : 0 );
			$line_tax = max( 0, min( $line_tax, $tax_left ) );
			$tax_left -= $line_tax;
			$wpdb->update( "{$p}wpcap_order_items", array( 'tax_minor' => $line_tax ), array( 'item_id' => (int) $item->item_id ) );
		}
		$snapshot = array( 'code_hint' => substr( $code, -4 ), 'amount_minor' => $amount, 'quote' => $quote, 'reservation' => $reservation, 'status' => 'reserved', 'refunded_minor' => 0, 'refunded_tickets' => 0 );
		$wpdb->update( "{$p}wpcap_orders", array( 'total_minor' => $new_total, 'discount_minor' => (int) $order->discount_minor + $amount, 'tax_minor' => $new_tax, 'voucher_snapshot' => wp_json_encode( $snapshot ), 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => (int) $order_id ) );
		Audit::record( 'voucher_reserved', 'order', (int) $order_id, array( 'amount_minor' => $amount ) );
		if ( 0 === $new_total ) {
			// Fully paid by voucher: confirm like a free order (commit follows via wpcap_order_paid).
			Orders::confirm_external_payment( (int) $order_id, 'voucher' );
		}
		return array( 'applied_minor' => $amount, 'total_minor' => $new_total );
	}

	/** Commits the reservation after payment. Failures are kept retryable via the jobs queue/audit. */
	public static function commit( $order_id ) {
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || 'reserved' !== $snapshot['status'] ) { return; }
		$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-' . Orders::get( $order_id )->public_id );
		if ( is_wp_error( $result ) ) {
			Audit::record( 'voucher_commit_failed', 'order', (int) $order_id, array( 'error' => $result->get_error_code() ) );
			return;
		}
		$snapshot['status'] = 'committed';
		$snapshot['redemption'] = $result;
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_committed', 'order', (int) $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
	}

	/** Releases an unused reservation when an unpaid order is cancelled or expires. */
	public static function release( $order_id ) {
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || 'reserved' !== $snapshot['status'] ) { return; }
		$order = Orders::get( $order_id );
		if ( $order && 'paid' === $order->payment_status ) { return; }
		$result = Bridge::release( (array) $snapshot['reservation'], 'wpcap-release-' . $order->public_id );
		if ( is_wp_error( $result ) ) {
			Audit::record( 'voucher_release_failed', 'order', (int) $order_id, array( 'error' => $result->get_error_code() ) );
			return;
		}
		$snapshot['status'] = 'released';
		self::save( $order_id, $snapshot );
	}

	/**
	 * Refunds the voucher share of a ticket refund, proportional to the refunded tickets
	 * (the last refund receives the remainder). Voucher Captain decides how value is restored.
	 */
	public static function refund( $refund_id, $order_id ) {
		global $wpdb;
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || 'committed' !== $snapshot['status'] ) { return; }
		$done_ids = array_map( 'absint', (array) ( $snapshot['refund_ids'] ?? array() ) );
		if ( in_array( absint( $refund_id ), $done_ids, true ) ) { return; }
		$refund = \PassCaptain\Refunds::get( $refund_id );
		$refunded_now = count( (array) json_decode( (string) $refund['ticket_ids'], true ) );
		$total_tickets = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d", (int) $order_id ) );
		$done_tickets = (int) $snapshot['refunded_tickets'] + $refunded_now;
		$share = $done_tickets >= $total_tickets
			? (int) $snapshot['amount_minor'] - (int) $snapshot['refunded_minor']
			: (int) floor( (int) $snapshot['amount_minor'] * $refunded_now / max( 1, $total_tickets ) );
		if ( $share < 1 ) { return; }
		$result = Bridge::refund( (array) ( $snapshot['redemption'] ?? array() ), $share, 'wpcap-refund-' . absint( $refund_id ) );
		if ( is_wp_error( $result ) ) {
			Audit::record( 'voucher_refund_failed', 'order', (int) $order_id, array( 'refund_id' => absint( $refund_id ), 'error' => $result->get_error_code() ) );
			return;
		}
		$snapshot['refunded_minor'] = (int) $snapshot['refunded_minor'] + $share;
		$snapshot['refunded_tickets'] = $done_tickets;
		$snapshot['refund_ids'] = array_merge( $done_ids, array( absint( $refund_id ) ) );
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_refunded', 'order', (int) $order_id, array( 'refund_id' => absint( $refund_id ), 'amount_minor' => $share ) );
	}

	private static function snapshot( $order_id ) {
		$order = Orders::get( $order_id );
		return ( $order && $order->voucher_snapshot ) ? (array) json_decode( $order->voucher_snapshot, true ) : null;
	}

	private static function save( $order_id, array $snapshot ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'voucher_snapshot' => wp_json_encode( $snapshot ) ), array( 'order_id' => absint( $order_id ) ) );
	}

	/** Provider errors are shown to customers without internal details. */
	private static function user_error( \WP_Error $error ) {
		$message = $error->get_error_message() ?: __( 'Der Gutschein konnte nicht eingelöst werden.', 'wp-pass-captain' );
		return new \WP_Error( 'wpcap_voucher_rejected', $message, array( 'status' => 409 ) );
	}
}
