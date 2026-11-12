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
use PassCaptain\Jobs;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Checkout {
	/** Job type for commit retries (Jobs queue). */
	const JOB = 'voucher_commit';
	/** The Jobs queue moves a job to dead_letter after this many attempts; the last one flags the order. */
	const FINAL_ATTEMPT = 8;

	public static function register() {
		add_action( 'wpcap_order_paid', array( __CLASS__, 'commit' ) );
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 4 );
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

		$order_ref = $order->public_id;
		$old_total = (int) $order->total_minor;
		$new_total = $old_total - $amount;
		$new_tax = $old_total > 0 ? (int) round( (int) $order->tax_minor * $new_total / $old_total ) : 0;
		// Keep line VAT consistent with the reduced total (proportional, remainder on the last line).
		$tax_left = $new_tax;
		$last = count( $items ) - 1;
		$snapshot = array( 'code_hint' => substr( $code, -4 ), 'amount_minor' => $amount, 'quote' => $quote, 'reservation' => $reservation, 'status' => 'reserved', 'refunded_minor' => 0, 'refunded_tickets' => 0 );
		// One transaction, every write checked; the order row only changes while it is still pending,
		// unchanged and without a voucher (a concurrent retry or an expiry in between wins).
		$wpdb->query( 'START TRANSACTION' );
		$ok = false !== $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_orders SET total_minor=%d,discount_minor=discount_minor+%d,tax_minor=%d,voucher_snapshot=%s,updated_at=%s WHERE order_id=%d AND payment_status='pending' AND total_minor=%d AND (voucher_snapshot IS NULL OR voucher_snapshot='')",
			$new_total,
			$amount,
			$new_tax,
			wp_json_encode( $snapshot ),
			current_time( 'mysql', true ),
			(int) $order_id,
			$old_total
		) ) && 1 === (int) $wpdb->rows_affected;
		foreach ( $ok ? $items : array() as $index => $item ) {
			$line_tax = $index === $last ? $tax_left : ( (int) $order->tax_minor > 0 ? (int) round( (int) $item->tax_minor * $new_tax / (int) $order->tax_minor ) : 0 );
			$line_tax = max( 0, min( $line_tax, $tax_left ) );
			$tax_left -= $line_tax;
			$ok = false !== $wpdb->update( "{$p}wpcap_order_items", array( 'tax_minor' => $line_tax ), array( 'item_id' => (int) $item->item_id ) );
			if ( ! $ok ) { break; }
		}
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			$current = Orders::get( $order_id );
			// The same reservation key is shared with a concurrent request that applied it: keep it.
			if ( ! $current || ! $current->voucher_snapshot ) {
				Bridge::release( (array) $reservation, 'wpcap-release-' . $order_ref );
			}
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'voucher_reserved', 'order', (int) $order_id, array( 'amount_minor' => $amount ) );
		if ( 0 === $new_total ) {
			// Fully paid by voucher: confirm like a free order (commit follows via wpcap_order_paid).
			Orders::confirm_external_payment( (int) $order_id, 'voucher' );
		}
		return array( 'applied_minor' => $amount, 'total_minor' => $new_total );
	}

	/**
	 * Commits the voucher after payment (wpcap_order_paid).
	 *
	 * - Reservation released before the payment arrived (order expired, late payment recovered):
	 *   the voucher is reserved again with the stored quote and then committed.
	 * - Temporary provider errors: retried through the Jobs queue (same idempotency keys).
	 * - Anything else (voucher used up, voided, provider gone for good): the order keeps its tickets
	 *   but the voucher snapshot is marked `commit_failed`, audited and the admin is emailed –
	 *   never a silent discount without a voucher charge.
	 *
	 * @return bool True when nothing is left to do, false when a retry is scheduled.
	 */
	public static function commit( $order_id, $final = false ) {
		$order_id = absint( $order_id );
		$snapshot = self::snapshot( $order_id );
		if ( ! $snapshot || ! in_array( $snapshot['status'] ?? '', array( 'reserved', 'released' ), true ) ) { return true; }
		$order = Orders::get( $order_id );
		// Refunded or cancelled before the (retried) commit: the voucher is not charged at all.
		if ( 'reserved' === $snapshot['status'] && $order && ( in_array( $order->status, array( 'refunded', 'cancelled' ), true ) || in_array( $order->payment_status, array( 'refunded', 'cancelled' ), true ) ) ) {
			$released = Bridge::release( (array) $snapshot['reservation'], 'wpcap-release-' . $order->public_id );
			if ( is_wp_error( $released ) ) {
				Audit::record( 'voucher_release_failed', 'order', $order_id, array( 'error' => $released->get_error_code() ) );
				return true;
			}
			$snapshot['status'] = 'released';
			unset( $snapshot['pending_refund_ids'] );
			self::save( $order_id, $snapshot );
			Audit::record( 'voucher_released', 'order', $order_id, array( 'reason' => $order->status ) );
			return true;
		}
		if ( 'released' === $snapshot['status'] ) {
			if ( ! $order || 'paid' !== $order->payment_status ) { return true; }
			$again = self::reserve_again( $order_id, $order, $snapshot );
			if ( is_wp_error( $again ) ) { return self::reserve_again_error( $order_id, $snapshot, $again, $final ); }
			$snapshot = $again;
		}
		$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-' . ( $order ? $order->public_id : $order_id ) );
		// Released on the voucher side (reservation TTL, cron, API) while the order waited for payment.
		if ( is_wp_error( $result ) && 'vcap_reservation_missing' === $result->get_error_code() && empty( $snapshot['late_reservation'] ) && $order && 'paid' === $order->payment_status ) {
			$again = self::reserve_again( $order_id, $order, $snapshot );
			if ( is_wp_error( $again ) ) { return self::reserve_again_error( $order_id, $snapshot, $again, $final ); }
			$snapshot = $again;
			$result = Bridge::commit( (array) $snapshot['reservation'], 'wpcap-commit-late-' . $order->public_id );
		}
		if ( is_wp_error( $result ) ) { return self::commit_error( $order_id, $snapshot, $result, $final ); }
		$pending = array_map( 'absint', (array) ( $snapshot['pending_refund_ids'] ?? array() ) );
		$snapshot['status'] = 'committed';
		$snapshot['redemption'] = $result;
		unset( $snapshot['error'], $snapshot['pending_refund_ids'] );
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_committed', 'order', $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
		// Ticket refunds made while the commit was still pending receive their voucher share now.
		foreach ( $pending as $refund_id ) { self::refund( $refund_id, $order_id ); }
		return true;
	}

	/**
	 * Reserves the voucher again with the stored quote (late payment after a release on either side).
	 *
	 * @return array|\WP_Error Updated snapshot.
	 */
	private static function reserve_again( $order_id, $order, array $snapshot ) {
		$again = Bridge::reserve( (array) ( $snapshot['quote'] ?? array() ), 'wpcap-order-late-' . $order->public_id );
		if ( is_wp_error( $again ) ) { return $again; }
		$amount = $again['applied_minor'] ?? ( $again['amount_minor'] ?? null );
		if ( null !== $amount && (int) $amount !== (int) $snapshot['amount_minor'] ) {
			Bridge::release( (array) $again, 'wpcap-release-late-' . $order->public_id );
			return new \WP_Error( 'wpcap_voucher_amount_changed', __( 'Der Gutschein deckt den ursprünglichen Betrag nicht mehr.', 'wp-pass-captain' ) );
		}
		$snapshot['reservation'] = $again;
		$snapshot['status'] = 'reserved';
		$snapshot['late_reservation'] = true;
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_reserved_again', 'order', $order_id, array( 'amount_minor' => (int) $snapshot['amount_minor'] ) );
		return $snapshot;
	}

	/** Filter wp_pass_captain_process_job_v1: retries a voucher commit. */
	public static function handle_job( $handled, $type, $payload = array(), $job_id = 0 ) {
		if ( self::JOB !== $type ) { return $handled; }
		global $wpdb;
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$wpdb->prefix}wpcap_jobs WHERE job_id=%d", absint( $job_id ) ) );
		return self::commit( absint( $payload['order_id'] ?? 0 ), $attempts >= self::FINAL_ATTEMPT );
	}

	/** Whether a provider error may go away by itself (outage, plugin update, lock timeout). */
	public static function is_temporary( \WP_Error $error ) {
		$data = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		return $status >= 500 || in_array( $error->get_error_code(), array( 'vcap_temporary_failure', 'wpcap_voucher_unavailable' ), true );
	}

	private static function reserve_again_error( $order_id, array $snapshot, \WP_Error $error, $final ) {
		return 'wpcap_voucher_amount_changed' === $error->get_error_code() ? self::needs_review( $order_id, $snapshot, $error ) : self::commit_error( $order_id, $snapshot, $error, $final );
	}

	private static function commit_error( $order_id, array $snapshot, \WP_Error $error, $final ) {
		if ( ! $final && self::is_temporary( $error ) ) {
			Audit::record( 'voucher_commit_failed', 'order', (int) $order_id, array( 'error' => $error->get_error_code(), 'retry' => true ) );
			Jobs::enqueue( 'voucher-commit-' . (int) $order_id, self::JOB, array( 'order_id' => (int) $order_id ), gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ) );
			return false;
		}
		return self::needs_review( $order_id, $snapshot, $error );
	}

	/** Paid, tickets issued, but the voucher could not be charged: flag, audit and alert. */
	private static function needs_review( $order_id, array $snapshot, \WP_Error $error ) {
		$snapshot['status'] = 'commit_failed';
		$snapshot['error'] = $error->get_error_code();
		self::save( $order_id, $snapshot );
		Audit::record( 'voucher_commit_failed', 'order', (int) $order_id, array( 'error' => $error->get_error_code(), 'retry' => false ) );
		$order = Orders::get( $order_id );
		wp_mail(
			get_option( 'admin_email' ),
			__( 'WP Pass Captain: Gutschein konnte nicht belastet werden', 'wp-pass-captain' ),
			sprintf(
				/* translators: 1: order id, 2: amount with currency, 3: last code characters, 4: error code */
				__( 'Bestellung %1$s ist bezahlt und die Tickets wurden ausgestellt, aber der Gutschein (…%3$s, %2$s) konnte in WP Voucher Captain nicht belastet werden (Fehler: %4$s). Bitte den Betrag beim Kunden nachfordern oder den Gutschein manuell belasten.', 'wp-pass-captain' ),
				$order ? $order->public_id : (int) $order_id,
				\PassCaptain\Admin\Forms::money( (int) $snapshot['amount_minor'], $order ? $order->currency : 'EUR' ),
				(string) ( $snapshot['code_hint'] ?? '' ),
				$error->get_error_code()
			)
		);
		return true;
	}

	/** Voucher snapshot of an order for display (null when no voucher was used). */
	public static function voucher_state( $order_id ) {
		return self::snapshot( $order_id );
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
		if ( ! $snapshot ) { return; }
		$done_ids = array_map( 'absint', (array) ( $snapshot['refund_ids'] ?? array() ) );
		if ( in_array( absint( $refund_id ), $done_ids, true ) ) { return; }
		if ( 'reserved' === $snapshot['status'] ) {
			// Commit still pending (retry job): the share is refunded right after the commit.
			$pending = array_map( 'absint', (array) ( $snapshot['pending_refund_ids'] ?? array() ) );
			if ( ! in_array( absint( $refund_id ), $pending, true ) ) {
				$snapshot['pending_refund_ids'] = array_merge( $pending, array( absint( $refund_id ) ) );
				self::save( $order_id, $snapshot );
			}
			return;
		}
		if ( 'committed' !== $snapshot['status'] ) { return; }
		$refund = \PassCaptain\Refunds::get( $refund_id );
		$order = Orders::get( $order_id );
		if ( ! $refund || ! $order ) { return; }
		$refunded_ids = array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) );
		$refunded_now = count( $refunded_ids );
		$total_tickets = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d", (int) $order_id ) );
		$done_tickets = (int) $snapshot['refunded_tickets'] + $refunded_now;
		// By value, like the cash share: voucher amount × gross of the refunded tickets / order subtotal.
		$subtotal = max( 1, (int) $order->subtotal_minor ?: ( (int) $order->total_minor + (int) $order->discount_minor ) );
		$left = (int) $snapshot['amount_minor'] - (int) $snapshot['refunded_minor'];
		$share = $done_tickets >= $total_tickets
			? $left
			: min( $left, (int) floor( (int) $snapshot['amount_minor'] * \PassCaptain\Refunds::gross_for_tickets( $order, $refunded_ids ) / $subtotal ) );
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
