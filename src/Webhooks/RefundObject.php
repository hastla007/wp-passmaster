<?php
/**
 * Refund description of the webhooks order.refunded and refund.reversed (A675): a refund per order,
 * per order line and per ticket (kind tickets | amount | full). Public ticket ids only – no ticket
 * codes, no personal data. Formerly part of the workflow events (Workflow\Events, removed with PC-7).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Webhooks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class RefundObject {
	/**
	 * Refund per order, line and ticket. kind: tickets (these tickets were refunded) or amount (an
	 * amount without tickets).
	 *
	 * @return array|null refund_id, order_id, kind, amount_minor, currency, source, status, ticket_ids (public), lines.
	 */
	public static function refund( $refund_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.currency,r.ticket_ids,r.status,r.source,o.public_id FROM %i r INNER JOIN %i o ON o.order_id=r.order_id WHERE r.refund_id=%d", $p . 'wpcap_refunds', $p . 'wpcap_orders', absint( $refund_id ) ) );
		if ( ! $r ) { return null; }
		$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) $r->ticket_ids, true ) ) ) );
		list( $public, $lines ) = self::ticket_lines( (int) $r->order_id, $ids );
		return array(
			'refund_id' => (int) $r->refund_id,
			'order_id' => (string) $r->public_id,
			'kind' => $ids ? 'tickets' : 'amount',
			'amount_minor' => (int) $r->amount_minor,
			'currency' => (string) $r->currency,
			'source' => sanitize_key( (string) $r->source ),
			'status' => sanitize_key( (string) $r->status ),
			'ticket_ids' => $public,
			'lines' => $lines,
		);
	}

	/** Full refund outside the native flow (Orders::mark_refunded()): every line and ticket of the order. */
	public static function full_refund( $order_id, $reason = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,currency FROM %i WHERE order_id=%d", $p . 'wpcap_orders', absint( $order_id ) ) );
		if ( ! $o ) { return null; }
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d ORDER BY ticket_id", $p . 'wpcap_tickets', (int) $o->order_id ) ) );
		list( $public ) = self::ticket_lines( (int) $o->order_id, $ids );
		$lines = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT item_id,ticket_type_id,quantity FROM %i WHERE order_id=%d ORDER BY item_id", $p . 'wpcap_order_items', (int) $o->order_id ) ) as $line ) {
			$lines[] = array( 'line_id' => (int) $line->item_id, 'ticket_type_id' => (int) $line->ticket_type_id, 'quantity' => (int) $line->quantity );
		}
		return array( 'refund_id' => null, 'order_id' => (string) $o->public_id, 'kind' => 'full', 'amount_minor' => null, 'currency' => (string) $o->currency, 'source' => sanitize_key( (string) $reason ), 'status' => 'completed', 'ticket_ids' => $public, 'lines' => $lines );
	}

	/** Order line of a ticket type (lines are per ticket type; 0 when the line is gone). */
	private static function line_of( $order_id, $ticket_type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT item_id FROM %i WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", $wpdb->prefix . 'wpcap_order_items', $order_id, $ticket_type_id ) );
	}

	/** Public ids of tickets and their grouping per order line: [public ids, lines]. */
	private static function ticket_lines( $order_id, array $ticket_ids ) {
		global $wpdb;
		if ( ! $ticket_ids ) { return array( array(), array() ); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,public_id,ticket_type_id FROM ' . $wpdb->prefix . 'wpcap_tickets WHERE order_id=%d AND ticket_id IN (' . implode( ',', array_map( 'absint', $ticket_ids ) ) . ') ORDER BY ticket_id', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		$public = array();
		$per_type = array();
		foreach ( (array) $rows as $row ) {
			$public[] = (string) $row->public_id;
			$per_type[ (int) $row->ticket_type_id ] = ( $per_type[ (int) $row->ticket_type_id ] ?? 0 ) + 1;
		}
		$lines = array();
		foreach ( $per_type as $type_id => $quantity ) { $lines[] = array( 'line_id' => self::line_of( $order_id, $type_id ), 'ticket_type_id' => $type_id, 'quantity' => $quantity ); }
		return array( $public, $lines );
	}
}
