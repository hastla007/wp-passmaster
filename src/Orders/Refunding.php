<?php
/**
 * Ticket revocation, refundable tickets and the refund of paid orders without tickets.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Audit;
use PassCaptain\CapacityCounters;
use PassCaptain\CapacityRelease;
use PassCaptain\Errors;
use PassCaptain\Orders;
use PassCaptain\Payments;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::revoke_tickets(), refundable_tickets(), settle_unallocated() and the job
 * `late_payment_refund` (run_late_refund(), called by Orders\Issuance::handle_job()). Moved out of Orders
 * without behaviour change (TODO "Gott-Klasse Orders"). Transactions are started and checked through
 * Orders\Transaction.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Refunding {
	/**
	 * Revokes up to $count still valid tickets of a ticket type (newest first). Checked-in tickets
	 * are never revoked. Their units are released through CapacityRelease (once per ticket) and
	 * announced with `wpcap_capacity_released` (tickets_revoked, type, quantity). Returns the number
	 * of revoked tickets.
	 */
	public static function revoke_tickets( $order_id, $ticket_type_id, $count, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$count = absint( $count );
		if ( ! $count ) { return 0; }
		// Inside a caller's transaction (MariaDB reports it) the revoke joins it.
		$nested = true === Transaction::transaction_alive();
		if ( ! $nested && false === Transaction::begin() ) { return 0; }
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$candidates = '' === $wpdb->last_error ? $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_type_id=%d AND status='valid' ORDER BY ticket_id DESC LIMIT %d FOR UPDATE", $order_id, absint( $ticket_type_id ), $count ) ) : array();
		$revoked_ids = '' === $wpdb->last_error ? Orders::revoke_ids( $candidates ) : array();
		// Revoked units no longer consume capacity.
		$released = $revoked_ids ? CapacityRelease::release_tickets( $order_id, $revoked_ids ) : array();
		if ( false === $released || false === Transaction::transaction_alive() || ( ! $nested && false === $wpdb->query( 'COMMIT' ) ) ) {
			if ( ! $nested ) { $wpdb->query( 'ROLLBACK' ); }
			return 0;
		}
		$revoked = count( $revoked_ids );
		Orders::fire_revoked( $revoked_ids, $reason );
		if ( $revoked ) {
			CapacityCounters::sync_order_now( $order_id );
			CapacityRelease::announce( $order_id, $released, 'tickets_revoked', array( 'reason' => (string) $reason ) );
			Audit::record( 'tickets_revoked', 'order', $order_id, array( 'ticket_type_id' => absint( $ticket_type_id ), 'count' => $revoked, 'reason' => sanitize_text_field( $reason ) ) );
		}
		return $revoked;
	}

	/**
	 * Job `late_payment_refund`: refunds the full payment of an order that issued no tickets
	 * (`needs_review`/`paid_unallocated` or `cancelled`) and marks it refunded. The provider call
	 * uses a fixed idempotency key, so a repeated run never pays out twice. An order that was
	 * allocated or refunded meanwhile is left alone.
	 *
	 * @return bool True when done (or nothing to do), false to retry.
	 */
	public static function run_late_refund( $order_id, $reason ) {
		global $wpdb;
		$order = Orders::get( $order_id );
		if ( ! $order ) { return '' === $wpdb->last_error ? true : Issuance::job_error( false, 'wpcap_db_busy' ); }
		$open = ( 'needs_review' === $order->status && 'paid_unallocated' === $order->payment_status ) || 'cancelled' === $order->status;
		if ( ! $open || (int) $order->total_minor < 1 ) { return true; }
		$gateway = Payments\Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! $gateway->supports_refunds() ) { return true; }
		$result = $gateway->refund( (array) $order, (int) $order->total_minor, 'wpcap-late-refund-' . (int) $order_id );
		if ( is_wp_error( $result ) ) {
			\PassCaptain\Log::error( 'late_payment_refund_failed', 'late payment refund failed', array( 'object_type' => 'order', 'object_id' => (int) $order_id, 'details' => array( 'error' => $result->get_error_code() ) ) );
			return $result; // The provider's reason becomes the job's last_error (A325).
		}
		$marked = Orders::mark_refunded( $order_id, 'late_payment_auto_refund' );
		if ( true !== $marked ) { return Issuance::job_error( $marked, 'wpcap_refund_mark_failed' ); } // mark_refunded() queued its own repair; the provider call is idempotent.
		// No tickets, no refund row: the order total is booked as refunded, so the provider's refund
		// webhook finds nothing left to book.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET refunded_minor=total_minor WHERE order_id=%d AND status='refunded'", (int) $order_id ) );
		Audit::record( 'late_payment_refunded', 'order', (int) $order_id, array( 'reason' => sanitize_key( $reason ), 'provider_reference' => \PassCaptain\Text::cut( sanitize_text_field( (string) $result ), 191 ), 'amount_minor' => (int) $order->total_minor ) );
		return true;
	}

	/**
	 * Closes a paid order that issued no tickets (`needs_review`/`paid_unallocated`, A358) by refunding
	 * the payment in full – the admin action next to "Zuteilung erneut versuchen":
	 *
	 * - 'provider': refund through the payment provider with the fixed key of the automatic late
	 *   refund (`wpcap-late-refund-{id}`), so this action and the job `late_payment_refund` never pay
	 *   out twice;
	 * - 'external': the money was returned outside the plugin (bank transfer, provider dashboard
	 *   without webhook); nothing is sent to the provider.
	 *
	 * Then the order is marked refunded (refunded amount = total, no refund row: no tickets, no
	 * invoice line was issued for them). WooCommerce orders are refunded in WooCommerce.
	 *
	 * @param int    $order_id Order.
	 * @param string $mode     'provider' or 'external'.
	 * @param string $note     Reason / note (audit).
	 * @return true|\WP_Error
	 */
	public static function settle_unallocated( $order_id, $mode, $note = '' ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$order = Orders::get( $order_id );
		if ( ! $order && '' !== $wpdb->last_error ) { return Errors::busy(); }
		if ( ! $order || 'needs_review' !== $order->status || 'paid_unallocated' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_order_state', __( 'Der Bestellstatus erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === (string) $order->payment_provider ) {
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$reference = '';
		if ( 'provider' === $mode ) {
			$gateway = Payments\Registry::get( (string) $order->payment_provider );
			if ( ! $gateway || ! $gateway->supports_refunds() || (int) $order->total_minor < 1 ) {
				return new \WP_Error( 'wpcap_refund_unsupported', __( 'Diese Zahlungsart kann nicht automatisch erstatten. Bitte die Zahlung außerhalb des Plugins zurückzahlen und „Extern erstattet – abschließen“ verwenden.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			$result = $gateway->refund( (array) $order, (int) $order->total_minor, 'wpcap-late-refund-' . $order_id );
			if ( is_wp_error( $result ) ) {
				\PassCaptain\Log::error( 'unallocated_refund_failed', 'unallocated refund failed', array( 'object_type' => 'order', 'object_id' => $order_id, 'details' => array( 'error' => $result->get_error_code() ) ) );
				return $result;
			}
			$reference = \PassCaptain\Text::cut( sanitize_text_field( (string) $result ), 191 );
		} elseif ( 'external' !== $mode ) {
			return new \WP_Error( 'wpcap_refund_mode', __( 'Unbekannte Art der Erstattung.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$marked = Orders::mark_refunded( $order_id, 'provider' === $mode ? 'unallocated_refunded' : 'unallocated_refunded_externally' );
		if ( true !== $marked ) { return $marked; } // mark_refunded() queued its own repair; a repeated provider call is idempotent.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET refunded_minor=total_minor WHERE order_id=%d AND status='refunded'", $order_id ) );
		Audit::record( 'unallocated_settled', 'order', $order_id, array( 'mode' => 'provider' === $mode ? 'provider' : 'external', 'provider_reference' => $reference, 'amount_minor' => (int) $order->total_minor, 'note' => \PassCaptain\Text::cut( sanitize_text_field( (string) $note ), 255 ) ) );
		return true;
	}

	/**
	 * Tickets of an order that can still be refunded natively: not refunded yet, and either valid,
	 * checked in, or revoked because their occurrence or event was cancelled (the buyer paid for an
	 * event that did not take place). Tickets revoked for other reasons are not refundable here.
	 *
	 * @param int       $order_id Order.
	 * @param bool|null $failed   Set to true when a read failed (the list is then incomplete, A183).
	 * @return object[] Rows {ticket_id, ticket_type_id}, ordered by ticket_id.
	 */
	public static function refundable_tickets( $order_id, &$failed = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.ticket_type_id FROM %i t LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.refunded=0 AND (t.status IN ('valid','checked_in') OR (t.status='revoked' AND (o.status='cancelled' OR e.status='cancelled'))) ORDER BY t.ticket_id", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events',
			absint( $order_id )
		) );
		$failed = '' !== $wpdb->last_error;
		$rows = (array) apply_filters( 'wpcap_refundable_tickets', $rows, absint( $order_id ) );
		$failed = $failed || '' !== $wpdb->last_error;
		return $rows;
	}
}
