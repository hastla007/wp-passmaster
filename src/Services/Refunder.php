<?php
/**
 * Refunds for service bookings through the core refund ledger (wpcap_refunds + Refunds::execute()).
 *
 * Refunds::request() needs a staff user with the refund capability. Buyer self-service (cancellation
 * within the deadline, declined or lost bookings) runs without such a user, so this class writes the
 * refund row itself – with the same idempotency key format, the same per-order lock, overlap check and
 * approval threshold – and then hands it to Refunds::execute(), which performs the provider refund,
 * revokes the tickets, releases their capacity and fires `wpcap_refund_completed` (credit note, voucher
 * share back to the voucher through the Voucher bridge, webhooks).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Services;

use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Refunder {
	/**
	 * Refunds tickets of an order (full value via Refunds::calculate()) or – with empty $ticket_ids and
	 * an $amount_minor – a plain amount (price difference). Idempotent per $key.
	 *
	 * @param int      $order_id     Order.
	 * @param int[]    $ticket_ids   Tickets to refund (may be empty for an amount-only refund).
	 * @param int|null $amount_minor Amount for an amount-only refund (capped at the remaining order total).
	 * @param string   $reason       Reason stored on the refund.
	 * @param string   $key          Idempotency key (unique per business event).
	 * @param string   $actor        customer|admin|partner|system – staff with the approval capability skip the threshold.
	 * @return array|\WP_Error Refund row.
	 */
	public static function refund( $order_id, array $ticket_ids, $amount_minor, $reason, $key, $actor = 'system' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$key_hash = hash( 'sha256', 'refund|' . $order_id . '|' . sanitize_text_field( $key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
		if ( $existing ) { return self::resume( $existing ); }
		$ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ticket_ids && null === $amount_minor ) {
			return new \WP_Error( 'wpcap_service_refund_empty', __( 'Für diese Buchung gibt es nichts zu erstatten.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', $p . 'wpcap_orders', $order_id ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === $order->payment_provider ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
		if ( $existing ) { $wpdb->query( 'ROLLBACK' ); return self::resume( $existing ); }
		$open = self::open_refunds( $order_id );
		if ( $ticket_ids && array_intersect( $ticket_ids, $open['ticket_ids'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_overlap', __( 'Für mindestens eines der Tickets läuft bereits eine Erstattung oder sie wartet auf Freigabe.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( $ticket_ids ) {
			$calc = Refunds::calculate( $order, $ticket_ids, $open['ticket_ids'], $open['amount_minor'] );
			if ( is_wp_error( $calc ) ) { $wpdb->query( 'ROLLBACK' ); return $calc; }
		} else {
			$remaining = max( 0, (int) $order->total_minor - (int) $order->refunded_minor - $open['amount_minor'] );
			$amount = min( $remaining, max( 0, (int) $amount_minor ) );
			if ( $amount < 1 ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_service_refund_empty', __( 'Für diese Buchung gibt es nichts zu erstatten.', 'pass-captain' ), array( 'status' => 409 ) ); }
			// Amount-only refund: VAT pro rata over the order (same rule as Refunds for amount-only refunds).
			$tax = (int) $order->total_minor > 0 ? (int) round( (int) $order->tax_minor * $amount / (int) $order->total_minor ) : 0;
			$calc = array( 'ticket_ids' => array(), 'amount_minor' => $amount, 'tax_minor' => min( $amount, $tax ) );
		}
		$threshold = (int) Settings::get( 'refund_approval_threshold' );
		$cumulative = (int) $order->refunded_minor + $open['amount_minor'] + (int) $calc['amount_minor'];
		$staff = in_array( $actor, array( 'admin', 'partner' ), true ) && current_user_can( Refunds::APPROVE_CAP );
		$needs_approval = $threshold > 0 && $cumulative > $threshold && ! $staff;
		$now = current_time( 'mysql', true );
		$user = in_array( $actor, array( 'admin', 'partner' ), true ) ? get_current_user_id() : 0;
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => (int) $calc['amount_minor'],
			'tax_minor' => (int) $calc['tax_minor'],
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( array_values( array_map( 'intval', $calc['ticket_ids'] ) ) ),
			'reason' => \PassCaptain\Text::cut( sanitize_text_field( $reason ), 255 ),
			'status' => $needs_approval ? 'pending_approval' : 'approved',
			'requested_by' => $user,
			'approved_by' => $needs_approval ? 0 : $user,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( false === $inserted || ! $wpdb->insert_id ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $p . 'wpcap_refunds', $key_hash ), ARRAY_A );
			return $existing ? self::resume( $existing ) : self::busy();
		}
		$refund_id = (int) $wpdb->insert_id;
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => (int) $calc['amount_minor'], 'needs_approval' => $needs_approval, 'source' => 'service_booking', 'actor' => sanitize_key( $actor ) ) );
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'Pass Captain: Erstattung wartet auf Freigabe', 'pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'pass-captain' ), $order->public_id, number_format_i18n( (int) $calc['amount_minor'] / 100, 2 ) . ' ' . $order->currency ) );
			return Refunds::get( $refund_id );
		}
		return Refunds::execute( $refund_id );
	}

	/** Refund row written for an idempotency key (also when the provider call failed), or null. */
	public static function find( $order_id, $key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key=%s', $wpdb->prefix . 'wpcap_refunds', hash( 'sha256', 'refund|' . absint( $order_id ) . '|' . sanitize_text_field( $key ) ) ), ARRAY_A );
	}

	/** Continues an existing refund row: approved/failed ones are (re-)executed, others returned as they are. */
	private static function resume( array $row ) {
		if ( in_array( $row['status'], array( 'approved', 'failed' ), true ) ) { return Refunds::execute( (int) $row['refund_id'] ); }
		return $row;
	}

	/** Whether a refund row is settled for the booking flow (done, or waiting for a human). */
	public static function settled( $row ) {
		return is_array( $row ) && in_array( (string) ( $row['status'] ?? '' ), array( 'completed', 'pending_approval', 'rejected' ), true );
	}

	/** Tickets and amount of refunds of the order that are not finished (same rule as Refunds::request()). */
	private static function open_refunds( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_ids,amount_minor FROM %i WHERE order_id=%d AND status IN ('pending_approval','approved','processing')", $wpdb->prefix . 'wpcap_refunds', absint( $order_id ) ) );
		$ids = array();
		$amount = 0;
		foreach ( (array) $rows as $row ) {
			$ids = array_merge( $ids, array_map( 'absint', (array) json_decode( (string) $row->ticket_ids, true ) ) );
			$amount += (int) $row->amount_minor;
		}
		return array( 'ticket_ids' => array_values( array_unique( array_filter( $ids ) ) ), 'amount_minor' => $amount );
	}

	private static function busy() {
		return new \WP_Error( 'wpcap_db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}
}
