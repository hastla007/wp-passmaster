<?php
/**
 * One rule for giving a ticket's capacity unit back (A579): every ticket releases its unit at most
 * once, and the release is recorded on the ticket (`wpcap_tickets.capacity_released`, schema 3.10.10).
 *
 * Every flow that ends a ticket's claim on capacity goes through this class:
 * - refunds (native, external/WooCommerce/partner): release_tickets() in the booking transaction;
 * - admin revoke with "release capacity" and Orders::revoke_tickets(): release_tickets();
 * - exchange: the unit moves out of its order line (Exchange\Service::release_unit()); mark() records it;
 * - order cancellation and full refund: the whole order stops counting (order status); mark_order()
 *   records it on every ticket.
 *
 * A ticket that is already marked never takes a second unit off its order line, whichever flow comes
 * second (refund after revoke, refund after exchange, a retried booking). Partial releases are
 * announced after the commit with `wpcap_capacity_released` (ticket_type_id and quantity per type),
 * so waitlists and allocation holds learn about them like about a cancellation.
 *
 * Without the column (deferred upgrade) the old behaviour applies: refunds are guarded by
 * `refunded`, revokes by the status change.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CapacityRelease {

	/** Whether wpcap_tickets.capacity_released exists (schema 3.10.10; not yet during a deferred upgrade). */
	public static function ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		// Only a positive answer is cached: the upgrade may complete later in the same process.
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_tickets LIKE 'capacity_released'" );
		}
		return $ready[ $key ];
	}

	/**
	 * Releases the capacity unit of each ticket that has not released it yet: the ticket is marked
	 * and one unit of a line of its order and type counts as refunded, in one statement (a repeat or a
	 * second flow never takes a second unit). Call inside the caller's transaction, after the order
	 * row (and preferably the tickets) are locked. Tickets of other orders are ignored.
	 *
	 * @param int   $order_id   Order.
	 * @param int[] $ticket_ids Tickets.
	 * @param array $args       refund (bool): also flag the tickets as refunded.
	 * @return array|false ticket_type_id => units released now; false on a database error.
	 */
	public static function release_tickets( $order_id, array $ticket_ids, array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $order_id || ! $ids ) { return array(); }
		$refund = ! empty( $args['refund'] );
		$ready = self::ready();
		$in = implode( ',', $ids );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,ticket_type_id' . ( $ready ? ',capacity_released' : '' ) . ",refunded FROM {$p}wpcap_tickets WHERE ticket_id IN ($in) AND order_id=%d ORDER BY ticket_id FOR UPDATE", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( '' !== $wpdb->last_error ) { return false; }
		$released = array();
		foreach ( (array) $rows as $ticket ) {
			$type_id = (int) $ticket->ticket_type_id;
			$open = $ready ? ! (int) $ticket->capacity_released : ( ! $refund || ! (int) $ticket->refunded );
			if ( $open ) {
				// A line of the ticket's type that still counts a unit (an exchange may have emptied the first one).
				$item_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d AND quantity>refunded_quantity ORDER BY item_id LIMIT 1 FOR UPDATE", $order_id, $type_id ) );
				if ( '' !== $wpdb->last_error ) { return false; }
				$set = $ready ? 't.capacity_released=1' : 't.ticket_id=t.ticket_id';
				$set .= $refund ? ',t.refunded=1' : '';
				$guard = $ready ? 't.capacity_released=0' : ( $refund ? 't.refunded=0' : '1=1' );
				if ( $item_id ) {
					$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets t INNER JOIN {$p}wpcap_order_items oi ON oi.item_id=%d SET $set,oi.refunded_quantity=LEAST(oi.quantity,oi.refunded_quantity+1) WHERE t.ticket_id=%d AND $guard", $item_id, (int) $ticket->ticket_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments.
				} else {
					// No line counts a unit of this type any more: only record the release.
					$changed = $ready || $refund ? $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets t SET $set WHERE t.ticket_id=%d AND $guard", (int) $ticket->ticket_id ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments.
				}
				if ( false === $changed ) { return false; }
				if ( $item_id && (int) $changed > 0 ) {
					$released[ $type_id ] = ( $released[ $type_id ] ?? 0 ) + 1;
				}
			}
			if ( $refund && ! (int) $ticket->refunded ) {
				// Released earlier (revoke, exchange, cancellation) or just now: the refund flag is separate.
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id=%d AND refunded=0", (int) $ticket->ticket_id ) ) ) { return false; }
			}
		}
		ksort( $released );
		return $released;
	}

	/**
	 * Records that tickets no longer hold a unit although no order line was changed here (exchange:
	 * the unit already moved out of the line; inside the caller's transaction).
	 *
	 * @param int[] $ticket_ids Tickets.
	 * @return bool
	 */
	public static function mark( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ids || ! self::ready() ) { return true; }
		return false !== $wpdb->query( "UPDATE {$wpdb->prefix}wpcap_tickets SET capacity_released=1 WHERE ticket_id IN (" . implode( ',', $ids ) . ') AND capacity_released=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/**
	 * Records that every ticket of an order released its unit because the order as a whole stopped
	 * counting (cancelled, fully refunded). Inside the caller's transaction.
	 *
	 * @return bool
	 */
	public static function mark_order( $order_id ) {
		global $wpdb;
		if ( ! self::ready() ) { return true; }
		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET capacity_released=1 WHERE order_id=%d AND capacity_released=0", absint( $order_id ) ) );
	}

	/**
	 * After the commit: brings the counters in line and fires `wpcap_capacity_released` once per
	 * ticket type with the released quantity (partial release; listeners return exactly these units).
	 *
	 * @param int    $order_id Order.
	 * @param array  $released ticket_type_id => units (release_tickets()).
	 * @param string $reason   Reason of the release (partial_refund, tickets_revoked, exchange, …).
	 * @param array  $details  Extra details passed to the hook.
	 */
	public static function announce( $order_id, array $released, $reason, array $details = array() ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! array_filter( $released ) ) { return; }
		CapacityCounters::sync_order_now( $order_id );
		foreach ( $released as $type_id => $quantity ) {
			if ( (int) $quantity < 1 ) { continue; }
			do_action( 'wpcap_capacity_released', $order_id, (string) $reason, array( 'ticket_type_id' => (int) $type_id, 'quantity' => (int) $quantity ) + $details );
		}
	}
}
