<?php
/**
 * Native refunds with optional second approval.
 *
 * Refunds are position based: the caller names the tickets to refund. The amount is derived from
 * the order item snapshot (incl. proportional discount), the provider refund is requested through the
 * payment adapter, refunded tickets are revoked (checked-in tickets stay as history) and their
 * capacity is released. Every request is idempotent via its key and recorded in wpcap_refunds.
 */
namespace PassCaptain;

use PassCaptain\Payments\Registry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Refunds {
	const APPROVE_CAP = 'wpcap_approve_refunds';
	const REFUND_CAP = 'wpcap_refund';

	/**
	 * Requests a refund. Executes it immediately unless a second approval is required.
	 *
	 * @param int    $order_id        Order ID.
	 * @param int[]  $ticket_ids      Tickets to refund; empty = all tickets not yet refunded.
	 * @param string $reason          Reason (stored in audit log and refund record).
	 * @param string $idempotency_key Unique key for this refund request.
	 * @return array|\WP_Error Refund row as array.
	 */
	public static function request( $order_id, array $ticket_ids, $reason, $idempotency_key ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$key_hash = hash( 'sha256', 'refund|' . $order_id . '|' . sanitize_text_field( $idempotency_key ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
		if ( $existing ) { return $existing; }

		$check = self::check_actor();
		if ( is_wp_error( $check ) ) { return $check; }
		$order = Orders::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_refund_state', __( 'Nur bezahlte Bestellungen können erstattet werden.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'woocommerce' === $order->payment_provider ) {
			return new \WP_Error( 'wpcap_refund_woocommerce', __( 'WooCommerce-Bestellungen bitte in WooCommerce erstatten; die Tickets werden automatisch widerrufen.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$calc = self::calculate( $order, $ticket_ids );
		if ( is_wp_error( $calc ) ) { return $calc; }

		$needs_approval = (int) Settings::get( 'refund_approval_threshold' ) > 0 && $calc['amount_minor'] > (int) Settings::get( 'refund_approval_threshold' ) && ! current_user_can( self::APPROVE_CAP );
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( "{$p}wpcap_refunds", array(
			'order_id' => $order_id,
			'idempotency_key' => $key_hash,
			'amount_minor' => $calc['amount_minor'],
			'tax_minor' => $calc['tax_minor'],
			'currency' => $order->currency,
			'ticket_ids' => wp_json_encode( $calc['ticket_ids'] ),
			'reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
			'status' => $needs_approval ? 'pending_approval' : 'approved',
			'requested_by' => get_current_user_id(),
			'approved_by' => $needs_approval ? 0 : get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( false === $inserted ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE idempotency_key=%s", $key_hash ), ARRAY_A );
			return $existing ? $existing : new \WP_Error( 'wpcap_refund_save', __( 'Die Erstattung konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		$refund_id = (int) $wpdb->insert_id;
		Audit::record( 'refund_requested', 'order', $order_id, array( 'refund_id' => $refund_id, 'amount_minor' => $calc['amount_minor'], 'needs_approval' => $needs_approval ) );
		if ( $needs_approval ) {
			wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Erstattung wartet auf Freigabe', 'wp-pass-captain' ), sprintf( /* translators: 1: order id, 2: amount */ __( 'Für Bestellung %1$s wurde eine Erstattung über %2$s angefordert, die eine zweite Freigabe benötigt.', 'wp-pass-captain' ), $order->public_id, number_format_i18n( $calc['amount_minor'] / 100, 2 ) . ' ' . $order->currency ) );
			return self::get( $refund_id );
		}
		return self::execute( $refund_id );
	}

	/** Second approval by a different user with the approval capability. */
	public static function approve( $refund_id ) {
		$refund = self::get( $refund_id );
		if ( ! $refund || 'pending_approval' !== $refund['status'] ) {
			return new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( self::APPROVE_CAP ) || (int) $refund['requested_by'] === get_current_user_id() ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Die Freigabe muss durch eine andere berechtigte Person erfolgen.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$check = self::check_actor();
		if ( is_wp_error( $check ) ) { return $check; }
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		Audit::record( 'refund_approved', 'order', (int) $refund['order_id'], array( 'refund_id' => absint( $refund_id ) ) );
		return self::execute( $refund_id );
	}

	/** Rejects a pending refund. */
	public static function reject( $refund_id, $reason = '' ) {
		global $wpdb;
		if ( ! current_user_can( self::APPROVE_CAP ) ) {
			return new \WP_Error( 'wpcap_refund_approver', __( 'Keine Berechtigung.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_refunds', array( 'status' => 'rejected', 'error_message' => substr( sanitize_text_field( $reason ), 0, 255 ), 'approved_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => absint( $refund_id ), 'status' => 'pending_approval' ) );
		return $updated ? self::get( $refund_id ) : new \WP_Error( 'wpcap_refund_not_pending', __( 'Diese Erstattung wartet nicht auf Freigabe.', 'wp-pass-captain' ), array( 'status' => 409 ) );
	}

	/**
	 * Executes an approved (or previously failed) refund: provider refund, ticket revocation,
	 * capacity release, order totals. Safe to retry after a provider failure.
	 */
	public static function execute( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$refund_id = absint( $refund_id );
		$wpdb->query( 'START TRANSACTION' );
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		if ( ! $refund || ! in_array( $refund['status'], array( 'approved', 'failed' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return $refund ? $refund : new \WP_Error( 'wpcap_refund_missing', __( 'Erstattung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
		}
		$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'processing', 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
		$wpdb->query( 'COMMIT' );

		$order = Orders::get( (int) $refund['order_id'] );
		$gateway = Registry::get( $order->payment_provider ?: 'manual' );
		$provider_reference = 'manual';
		if ( $gateway && $gateway->supports_refunds() && (int) $refund['amount_minor'] > 0 ) {
			$result = $gateway->refund( (array) $order, (int) $refund['amount_minor'], 'wpcap-refund-' . $refund_id );
			if ( is_wp_error( $result ) ) {
				$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'failed', 'error_message' => substr( $result->get_error_message(), 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
				Audit::record( 'refund_failed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'error' => $result->get_error_code() ) );
				return $result;
			}
			$provider_reference = (string) $result;
		}

		$ticket_ids = array_map( 'absint', (array) json_decode( $refund['ticket_ids'], true ) );
		$revoked = 0;
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			$per_type = $wpdb->get_results( "SELECT ticket_type_id, COUNT(*) AS n FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND status='valid' GROUP BY ticket_type_id" );
			$revoked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id IN ($ids) AND order_id=%d AND status='valid'", (int) $refund['order_id'] ) );
			foreach ( $per_type as $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+%d) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", (int) $row->n, (int) $refund['order_id'], (int) $row->ticket_type_id ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id IN ($ids) AND order_id=%d", (int) $refund['order_id'] ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET refunded_minor=LEAST(total_minor,refunded_minor+%d),updated_at=%s WHERE order_id=%d", (int) $refund['amount_minor'], current_time( 'mysql', true ), (int) $refund['order_id'] ) );
		$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'completed', 'provider_reference' => substr( $provider_reference, 0, 191 ), 'error_message' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'refund_id' => $refund_id ) );
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0", (int) $refund['order_id'] ) );
		if ( 0 === $remaining ) {
			Orders::mark_refunded( (int) $refund['order_id'], 'native_refund_' . $refund_id );
		}
		Audit::record( 'refund_completed', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'amount_minor' => (int) $refund['amount_minor'], 'revoked_tickets' => $revoked, 'provider' => $order->payment_provider ) );
		do_action( 'wpcap_refund_completed', $refund_id, (int) $refund['order_id'] );
		return self::get( $refund_id );
	}

	/** Returns a refund row as array or null. */
	public static function get( $refund_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE refund_id=%d", absint( $refund_id ) ), ARRAY_A );
	}

	/** Refunds of an order. */
	public static function for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", absint( $order_id ) ), ARRAY_A );
	}

	/**
	 * Calculates the refund amount for tickets (not yet refunded) from the order item snapshots.
	 * The last remaining tickets receive the rounding remainder so a full refund equals the order total.
	 */
	public static function calculate( $order, array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$open = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY ticket_id", (int) $order->order_id ) );
		$open_ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, $open );
		$ticket_ids = $ticket_ids ? array_values( array_unique( array_map( 'absint', $ticket_ids ) ) ) : $open_ids;
		if ( ! $ticket_ids || array_diff( $ticket_ids, $open_ids ) ) {
			return new \WP_Error( 'wpcap_refund_tickets', __( 'Die gewählten Tickets gehören nicht zur Bestellung oder wurden bereits erstattet.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$remaining_total = max( 0, (int) $order->total_minor - (int) $order->refunded_minor );
		if ( count( $ticket_ids ) === count( $open_ids ) ) {
			$amount = $remaining_total;
		} else {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,quantity,line_total_minor FROM {$p}wpcap_order_items WHERE order_id=%d", (int) $order->order_id ) );
			$unit = array();
			foreach ( $items as $item ) { $unit[ (int) $item->ticket_type_id ] = intdiv( (int) $item->line_total_minor, max( 1, (int) $item->quantity ) ); }
			$gross = 0;
			foreach ( $open as $ticket ) {
				if ( in_array( (int) $ticket->ticket_id, $ticket_ids, true ) ) { $gross += $unit[ (int) $ticket->ticket_type_id ] ?? 0; }
			}
			$subtotal = max( 1, (int) $order->subtotal_minor ?: (int) $order->total_minor );
			$amount = min( $remaining_total, (int) round( $gross * (int) $order->total_minor / $subtotal ) );
		}
		$tax = (int) $order->total_minor > 0 ? (int) round( (int) $order->tax_minor * $amount / (int) $order->total_minor ) : 0;
		return array( 'ticket_ids' => $ticket_ids, 'amount_minor' => $amount, 'tax_minor' => $tax );
	}

	/** Optional enforcement of two-factor authentication for refund actions. */
	private static function check_actor() {
		if ( ! current_user_can( self::REFUND_CAP ) && ! current_user_can( self::APPROVE_CAP ) ) {
			return new \WP_Error( 'wpcap_refund_forbidden', __( 'Keine Berechtigung für Erstattungen.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		if ( Settings::get( 'require_2fa_for_refunds' ) && ! self::user_has_2fa( get_current_user_id() ) ) {
			return new \WP_Error( 'wpcap_refund_2fa', __( 'Für Erstattungen ist eine aktivierte Zwei-Faktor-Anmeldung erforderlich.', 'wp-pass-captain' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Detects 2FA via the Two Factor plugin or a `wpcap_user_has_2fa` filter for other providers. */
	public static function user_has_2fa( $user_id ) {
		$has = class_exists( '\\Two_Factor_Core' ) && method_exists( '\\Two_Factor_Core', 'is_user_using_two_factor' ) && \Two_Factor_Core::is_user_using_two_factor( $user_id );
		return (bool) apply_filters( 'wpcap_user_has_2fa', $has, $user_id );
	}
}
