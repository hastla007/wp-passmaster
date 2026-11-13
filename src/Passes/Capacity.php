<?php
/**
 * Capacity of passes: one pass unit consumes one place in EVERY linked occurrence ("all-M
 * reservation", also for "N of M" passes – capacity is never at risk when the holder later picks
 * any N of the M dates).
 *
 * Usage is derived like the core: paid order items minus refunded units plus active, unexpired
 * reservations. The core counts a pass only at its anchor occurrence (ticket type occurrence_id);
 * this module adds the "foreign" pass units (linked occurrence ≠ anchor) to every linked occurrence.
 * Expiry, cancellation, refunds and revocations therefore release capacity in all linked
 * occurrences automatically (`wpcap_capacity_released` needs no bookkeeping).
 *
 * Locking (deadlock-free by construction):
 *  1. The core locks ticket types (ascending id), then their occurrences (ascending id), then runs
 *     `wpcap_reserve_capacity`.
 *  2. reserve_lock() (priority 5, before every other module) uses locking reads only and locks
 *     the gate rows (wpcap_pass_gates, one per linked occurrence) of all occurrences the order
 *     touches – anchors and linked occurrences – in ascending occurrence id.
 *  3. reserve_check() (priority 1000, after all modules took their locks) reads usage. The
 *     transaction's consistent snapshot is therefore taken after the gate locks.
 * Every order touching a linked occurrence – a pass or a single-day ticket – serialises on that
 * occurrence's gate. Gate locks are only taken after all core locks of the same transaction and
 * always in ascending order, so no wait cycle is possible. Gates are used instead of locking the
 * extra occurrence rows directly: the core already holds the anchor occurrence lock, which may
 * have a higher id than other linked occurrences; two passes with different anchors over the same
 * dates would then lock occurrence rows in opposite orders and deadlock.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Capacity {
	/** Lock-phase state of the order currently inside `wpcap_reserve_capacity`. */
	private static $pending = null;

	/* ---------------------------------------------------------------------------------------------
	 * Usage (same definition as Orders::capacity_error()).
	 * ------------------------------------------------------------------------------------------- */

	/** wpcap_occurrence_sold (admin occupancy): plus paid pass units anchored at another occurrence. */
	public static function sold_display( $sold, $occurrence_id ) {
		if ( ! Definitions::all_ids() ) { return $sold; }
		// A far future "now" leaves out holds: sold units only.
		return (int) $sold + self::pass_used( $occurrence_id, 0, '9999-12-31 00:00:00', true );
	}

	/** Units of all ticket types anchored at an occurrence (core usage). */
	public static function core_used( $occurrence_id, $exclude_order_id, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", absint( $occurrence_id ), absint( $exclude_order_id ) ) );
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d", absint( $occurrence_id ), $now, absint( $exclude_order_id ) ) );
		return $sold + $held;
	}

	/**
	 * Pass units counted at an occurrence: all passes linked to it, or with $foreign_only only those
	 * anchored elsewhere (the part the core does not count there).
	 */
	public static function pass_used( $occurrence_id, $exclude_order_id, $now, $foreign_only = true ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$links = Definitions::table( 'occurrences' );
		$occurrence_id = absint( $occurrence_id );
		$foreign = $foreign_only ? $wpdb->prepare( ' AND tt.occurrence_id <> %d', $occurrence_id ) : '';
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN $links po ON po.type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE po.occurrence_id=%d$foreign AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", $occurrence_id, absint( $exclude_order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, prepared fragment.
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN $links po ON po.type_id=r.ticket_type_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE po.occurrence_id=%d$foreign AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d", $occurrence_id, $now, absint( $exclude_order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, prepared fragment.
		return $sold + $held;
	}

	/** Units of the given ticket types (paid minus refunded plus active holds). */
	public static function type_units( array $type_ids, $exclude_order_id, $now ) {
		global $wpdb;
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids ) { return 0; }
		$p = $wpdb->prefix;
		$in = implode( ',', $type_ids );
		$sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id IN ($in) AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d", absint( $exclude_order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at > %s AND order_id <> %d", $now, absint( $exclude_order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		return $sold + $held;
	}

	/* ---------------------------------------------------------------------------------------------
	 * `wpcap_reserve_capacity`.
	 * ------------------------------------------------------------------------------------------- */

	/** Lock phase (priority 5): locking reads only; locks the gates of all touched occurrences. */
	public static function reserve_lock( $result, $demand, $context ) {
		self::$pending = null;
		if ( is_wp_error( $result ) || ! is_array( $demand ) ) { return $result; }
		global $wpdb;
		$p = $wpdb->prefix;
		$demand = array_filter( array_map( 'absint', $demand ) );
		if ( ! $demand ) { return $result; }
		$type_ids = array_map( 'absint', array_keys( $demand ) );
		$types = (array) ( $context['types'] ?? array() );
		$anchor = array();
		foreach ( $type_ids as $type_id ) {
			$type = $types[ $type_id ] ?? null;
			if ( ! $type ) {
				// Not passed by the caller: the core already holds this row's exclusive lock.
				$type = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d LOCK IN SHARE MODE", $type_id ) );
			}
			if ( $type ) { $anchor[ $type_id ] = (int) $type->occurrence_id; }
		}
		$links_table = Definitions::table( 'occurrences' );
		$links = array();
		foreach ( (array) $wpdb->get_results( "SELECT type_id,occurrence_id FROM $links_table WHERE type_id IN (" . implode( ',', $type_ids ) . ') ORDER BY type_id,occurrence_id LOCK IN SHARE MODE' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, integer list.
			$links[ (int) $row->type_id ][] = (int) $row->occurrence_id;
		}
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		$touched = array_values( array_unique( array_filter( array_merge( array_values( $anchor ), $links ? array_merge( ...array_values( $links ) ) : array() ) ) ) );
		if ( ! $touched ) { return $result; }
		$gated = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT occurrence_id FROM $links_table WHERE occurrence_id IN (" . implode( ',', $touched ) . ') ORDER BY occurrence_id LOCK IN SHARE MODE' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, integer list.
		if ( '' !== $wpdb->last_error ) { return self::busy(); }
		if ( ! $gated ) { return $result; }
		sort( $gated, SORT_NUMERIC );
		$gates_table = Definitions::table( 'gates' );
		$gates = array();
		foreach ( $gated as $occurrence_id ) {
			$gate = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,pass_quota FROM $gates_table WHERE occurrence_id=%d FOR UPDATE", $occurrence_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			if ( '' !== $wpdb->last_error ) { return self::busy(); }
			$gates[ $occurrence_id ] = $gate ? (int) $gate->pass_quota : 0;
		}
		self::$pending = array(
			'demand' => $demand,
			'anchor' => $anchor,
			'links' => $links,
			'gates' => $gates,
			'order_id' => absint( $context['order_id'] ?? 0 ),
			'now' => (string) ( $context['now'] ?? current_time( 'mysql', true ) ),
		);
		return $result;
	}

	/** Check phase (priority 1000): capacity and pass quota of every gated occurrence. */
	public static function reserve_check( $result, $demand, $context ) {
		$state = self::$pending;
		self::$pending = null;
		if ( is_wp_error( $result ) || ! $state ) { return $result; }
		global $wpdb;
		$p = $wpdb->prefix;
		foreach ( $state['gates'] as $occurrence_id => $quota ) {
			$need = 0;
			$foreign_need = 0;
			$pass_need = 0;
			foreach ( $state['demand'] as $type_id => $quantity ) {
				$linked = in_array( $occurrence_id, $state['links'][ $type_id ] ?? array(), true );
				if ( ( $state['anchor'][ $type_id ] ?? 0 ) === $occurrence_id ) {
					$need += $quantity;
				} elseif ( $linked ) {
					$need += $quantity;
					$foreign_need += $quantity;
				}
				if ( $linked ) { $pass_need += $quantity; }
			}
			if ( ! $need ) { continue; }
			$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.occurrence_id,o.capacity,o.status,o.starts_at,o.timezone,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
			if ( '' !== $wpdb->last_error ) { return self::busy(); }
			// A cancelled linked date no longer holds places (the pass stays valid for the others).
			if ( ! $occ || ( 'cancelled' === $occ->status && $foreign_need === $need ) ) { continue; }
			if ( (int) $occ->capacity > 0 ) {
				$used = self::core_used( $occurrence_id, $state['order_id'], $state['now'] ) + self::pass_used( $occurrence_id, $state['order_id'], $state['now'], true );
				if ( '' !== $wpdb->last_error ) { return self::busy(); }
				if ( $used + $need > (int) $occ->capacity ) {
					if ( ! $foreign_need ) {
						return new \WP_Error( 'occurrence_sold_out', __( 'Für diesen Termin sind nicht mehr genügend Plätze verfügbar.', 'wp-pass-captain' ), array( 'status' => 409 ) );
					}
					/* translators: 1: event name, 2: date */
					return new \WP_Error( 'pass_occurrence_sold_out', sprintf( __( 'Der Pass ist nicht mehr verfügbar: Der enthaltene Termin „%1$s“ am %2$s ist ausgebucht.', 'wp-pass-captain' ), $occ->event_name, Display::date( $occ->starts_at, $occ->timezone ) ), array( 'status' => 409 ) );
				}
			}
			if ( $quota > 0 && $pass_need > 0 ) {
				$pass_used = self::pass_used( $occurrence_id, $state['order_id'], $state['now'], false );
				if ( '' !== $wpdb->last_error ) { return self::busy(); }
				if ( $pass_used + $pass_need > $quota ) {
					/* translators: 1: event name, 2: date */
					return new \WP_Error( 'pass_quota_exhausted', sprintf( __( 'Das Pass-Kontingent für „%1$s“ am %2$s ist ausgeschöpft.', 'wp-pass-captain' ), $occ->event_name, Display::date( $occ->starts_at, $occ->timezone ) ), array( 'status' => 409 ) );
				}
			}
		}
		return $result;
	}

	private static function busy() {
		self::$pending = null;
		return new \WP_Error( 'db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'wp-pass-captain' ), array( 'status' => 503 ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Availability (display, cart validation).
	 * ------------------------------------------------------------------------------------------- */

	/** Free places of an occurrence including foreign pass usage; null when unlimited. */
	public static function occurrence_remaining( $occurrence, $now = null ) {
		if ( ! $occurrence || (int) $occurrence->capacity < 1 ) { return null; }
		$now = $now ? $now : current_time( 'mysql', true );
		$id = (int) $occurrence->occurrence_id;
		return max( 0, (int) $occurrence->capacity - self::core_used( $id, 0, $now ) - self::pass_used( $id, 0, $now, true ) );
	}

	/** Remaining passes allowed by an occurrence's pass quota; null without quota. */
	public static function quota_remaining( $occurrence_id, $now = null ) {
		$quota = Definitions::quota( $occurrence_id );
		if ( $quota < 1 ) { return null; }
		return max( 0, $quota - self::pass_used( $occurrence_id, 0, $now ? $now : current_time( 'mysql', true ), false ) );
	}

	/** Filter callback for `wpcap_ticket_availability` (only ever lowers the value). */
	public static function availability( $remaining, $type, $occurrence = null ) {
		if ( ! $type || empty( $type->ticket_type_id ) ) { return $remaining; }
		global $wpdb;
		$config = Definitions::get( (int) $type->ticket_type_id );
		$anchor_id = (int) $type->occurrence_id;
		$check = array();
		if ( $config ) {
			$check = $config->occurrence_ids;
			if ( ! in_array( $anchor_id, $check, true ) && Definitions::pass_ids_for_occurrence( $anchor_id ) ) { $check[] = $anchor_id; }
		} elseif ( Definitions::pass_ids_for_occurrence( $anchor_id ) ) {
			$check = array( $anchor_id );
		}
		if ( ! $check ) { return $remaining; }
		$now = current_time( 'mysql', true );
		$min = $remaining;
		foreach ( $check as $occurrence_id ) {
			$occ = $occurrence && (int) $occurrence->occurrence_id === $occurrence_id ? $occurrence : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $occurrence_id ) );
			if ( ! $occ || ( 'cancelled' === $occ->status && $occurrence_id !== $anchor_id ) ) { continue; }
			$limits = array( self::occurrence_remaining( $occ, $now ) );
			if ( $config && in_array( $occurrence_id, $config->occurrence_ids, true ) ) { $limits[] = self::quota_remaining( $occurrence_id, $now ); }
			foreach ( $limits as $left ) {
				if ( null === $left ) { continue; }
				$min = null === $min ? $left : min( (int) $min, $left );
			}
		}
		return $min;
	}

	/** `wpcap_capacity_released`: usage is derived, only the request cache is dropped. */
	public static function on_capacity_released( $order_id = 0, $reason = '', $details = array() ) {
		Definitions::flush();
	}
}
