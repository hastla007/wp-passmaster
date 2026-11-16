<?php
/**
 * Capacity arithmetic for reserved contingents.
 *
 * Usage figures are computed like the core (Orders::capacity_error()/availability()): paid order
 * items minus refunded units plus active, unexpired inventory reservations. A hold blocks its open
 * remainder: kills block their whole quantity, holds block quantity - used (units of the hold that
 * are already sold are part of the core usage). Consumption of pending orders whose reservation has
 * lapsed (not yet cleaned up by the hourly expiry job) is ignored, like the core ignores the lapsed
 * reservation itself.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Allocations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Capacity {
	/** Units of one ticket type that count against its capacity. */
	public static function type_used( $ticket_type_id, $exclude_order_id, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $ticket_type_id, $exclude_order_id ) );
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND status='active' AND expires_at > %s AND order_id <> %d", $ticket_type_id, $now, $exclude_order_id ) );
		return $sold + $held;
	}

	/** Units of all ticket types of an occurrence that count against its capacity. */
	public static function occurrence_used( $occurrence_id, $exclude_order_id, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $occurrence_id, $exclude_order_id ) );
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d", $occurrence_id, $now, $exclude_order_id ) );
		return $sold + $held;
	}

	/**
	 * Consumed units per hold that belong to pending orders without an active reservation (hold
	 * lapsed, expiry job not run yet). They no longer occupy capacity and return to the hold.
	 *
	 * @return array hold_id => units
	 */
	public static function stale_usage( array $hold_ids, $now ) {
		global $wpdb;
		$hold_ids = array_filter( array_map( 'absint', $hold_ids ) );
		if ( ! $hold_ids ) { return array(); }
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.hold_id, SUM(u.quantity - u.released) AS units FROM {$p}wpcap_alloc_usage u INNER JOIN {$p}wpcap_orders o ON o.order_id=u.order_id WHERE u.hold_id IN (" . implode( ',', $hold_ids ) . ") AND u.status='active' AND o.payment_status='pending' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=u.order_id AND r.status='active' AND r.expires_at > %s) GROUP BY u.hold_id",
			$now
		) );
		$out = array();
		foreach ( (array) $rows as $row ) { $out[ (int) $row->hold_id ] = (int) $row->units; }
		return $out;
	}

	/**
	 * Units a hold still blocks for public sale.
	 *
	 * @param object $hold     Hold row.
	 * @param int    $stale    Lapsed consumption (see stale_usage()).
	 * @param int    $consumed Units consumed by the order being checked.
	 */
	public static function open( $hold, $stale = 0, $consumed = 0 ) {
		if ( 'active' !== $hold->status ) { return 0; }
		if ( 'kill' === $hold->kind ) { return (int) $hold->quantity; }
		return max( 0, (int) $hold->quantity - max( 0, (int) $hold->used - (int) $stale ) - (int) $consumed );
	}

	/**
	 * Checks a demand against capacity minus open holds.
	 *
	 * @param array $demand      ticket_type_id => quantity.
	 * @param array $types       Ticket type rows by id (locked).
	 * @param array $occurrences Occurrence rows by id (locked).
	 * @param array $holds       Active hold rows by id (locked).
	 * @param array $consumed    hold_id => units consumed by this order.
	 * @param array $restore     hold_id => units of this order's own lapsed consumption (recovery).
	 * @return string '' or an error code (sold_out, occurrence_sold_out, db_busy).
	 */
	public static function check( array $demand, array $types, array $occurrences, array $holds, array $consumed, $exclude_order_id, $now, array $restore = array() ) {
		global $wpdb;
		if ( ! $holds ) { return ''; }
		$stale = self::stale_usage( array_keys( $holds ), $now );
		if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
		$by_occurrence = array();
		foreach ( $holds as $hold ) {
			// A recovering order's own lapsed consumption is counted as stale and re-consumed here.
			$units = self::open( $hold, $stale[ (int) $hold->hold_id ] ?? 0, ( $consumed[ (int) $hold->hold_id ] ?? 0 ) - ( $restore[ (int) $hold->hold_id ] ?? 0 ) );
			if ( $units < 1 ) { continue; }
			$occ = (int) $hold->occurrence_id;
			$by_occurrence[ $occ ]['all'] = ( $by_occurrence[ $occ ]['all'] ?? 0 ) + $units;
			if ( (int) $hold->ticket_type_id ) {
				$by_occurrence[ $occ ]['types'][ (int) $hold->ticket_type_id ] = ( $by_occurrence[ $occ ]['types'][ (int) $hold->ticket_type_id ] ?? 0 ) + $units;
			}
		}
		$occ_demand = array();
		foreach ( $demand as $type_id => $quantity ) {
			$type = $types[ $type_id ] ?? null;
			if ( ! $type ) { continue; }
			$occ = (int) $type->occurrence_id;
			$occ_demand[ $occ ] = ( $occ_demand[ $occ ] ?? 0 ) + (int) $quantity;
			$blocked = $by_occurrence[ $occ ]['types'][ (int) $type_id ] ?? 0;
			if ( $blocked > 0 && (int) $type->capacity > 0 ) {
				$used = self::type_used( (int) $type_id, $exclude_order_id, $now );
				if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
				if ( $used + (int) $quantity + $blocked > (int) $type->capacity ) { return 'sold_out'; }
			}
		}
		foreach ( $occ_demand as $occ => $quantity ) {
			$occurrence = $occurrences[ $occ ] ?? null;
			$blocked = $by_occurrence[ $occ ]['all'] ?? 0;
			if ( ! $occurrence || $blocked < 1 || (int) $occurrence->capacity < 1 ) { continue; }
			$used = self::occurrence_used( $occ, $exclude_order_id, $now );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $used + $quantity + $blocked > (int) $occurrence->capacity ) { return 'occurrence_sold_out'; }
		}
		return '';
	}

	/**
	 * Units still free for public sale given the open holds, or null when unlimited. $type may be
	 * null for the occurrence as a whole.
	 */
	public static function public_remaining( $type, $occurrence, array $holds, $now ) {
		$stale = self::stale_usage( array_keys( $holds ), $now );
		$all = 0;
		$own = 0;
		foreach ( $holds as $hold ) {
			if ( (int) $hold->occurrence_id !== (int) $occurrence->occurrence_id ) { continue; }
			$units = self::open( $hold, $stale[ (int) $hold->hold_id ] ?? 0 );
			$all += $units;
			if ( $type && (int) $hold->ticket_type_id === (int) $type->ticket_type_id ) { $own += $units; }
		}
		$limits = array();
		if ( $type && (int) $type->capacity > 0 ) {
			$limits[] = (int) $type->capacity - self::type_used( (int) $type->ticket_type_id, 0, $now ) - $own;
		}
		if ( (int) $occurrence->capacity > 0 ) {
			$limits[] = (int) $occurrence->capacity - self::occurrence_used( (int) $occurrence->occurrence_id, 0, $now ) - $all;
		}
		return $limits ? max( 0, min( $limits ) ) : null;
	}
}
