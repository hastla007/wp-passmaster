<?php
/**
 * Core capacity check of a ticket type and its occurrence under row locks.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\CapacityCounters;
use PassCaptain\Clock;
use PassCaptain\Sales;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::capacity_error() (counters or summed paid lines and holds). Moved out of
 * Orders without behaviour change (TODO "Gott-Klasse Orders"). Callers hold the row locks.
 *
 * @internal Not part of the add-on API: only Orders calls it; use the Orders facade (same signatures).
 */
final class Capacity {
	/**
	 * Returns an error code if $quantity more units of $type (and $occurrence_quantity more units of
	 * the occurrence, default $quantity) would exceed type or occurrence capacity; '' when they fit.
	 * Callers must hold row locks on the ticket type and occurrence, and pass rows read under those
	 * locks after any counter change in the same transaction. Capacity 0 means unlimited.
	 *
	 * With capacity counters (CapacityCounters::enabled()) usage is sold_qty + held_qty of the
	 * locked rows minus holds that still count but no longer hold (range counted_qty>0 on the index
	 * counted_type (ticket_type_id,counted_qty), A429: only the few holds that still count, never the
	 * reservation history); without them the paid lines and holds are summed (upgrade fallback).
	 */
	public static function error( $type, $occurrence, $quantity, $exclude_order_id = 0, $occurrence_quantity = null ) {
		$occurrence_quantity = null === $occurrence_quantity ? $quantity : (int) $occurrence_quantity;
		if ( CapacityCounters::enabled() && isset( $occurrence->sold_qty, $occurrence->held_qty ) && ( (int) $type->capacity <= 0 || isset( $type->sold_qty, $type->held_qty ) ) ) {
			return self::counted( $type, $occurrence, (int) $quantity, absint( $exclude_order_id ), $occurrence_quantity );
		}
		return self::summed( $type, $occurrence, (int) $quantity, absint( $exclude_order_id ), $occurrence_quantity );
	}

	/** capacity_error() from the locked counters. */
	private static function counted( $type, $occurrence, $quantity, $exclude_order_id, $occurrence_quantity ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		if ( (int) $type->capacity > 0 ) {
			// Holds that no longer hold (lapsed while still "active", or already expired/released/
			// converted but not yet synced) still count in held_qty until the counters catch up.
			$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $type->ticket_type_id, $now ) );
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$own = 0;
			if ( $exclude_order_id ) {
				$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d", $exclude_order_id, $type->ticket_type_id ) )
					+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE order_id=%d AND ticket_type_id=%d AND status='active' AND expires_at > %s", $exclude_order_id, $type->ticket_type_id, $now ) );
				if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			}
			if ( (int) $type->sold_qty + (int) $type->held_qty - $lapsed - $own + $quantity > (int) $type->capacity ) {
				return 'sold_out';
			}
		}
		if ( (int) $occurrence->capacity > 0 && ! Sales\Addons::is_addon( $type ) ) {
			// The occurrence's types first, then one range per type on counted_type (ticket_type_id,
			// counted_qty): a join would read every historical hold of each type (A429).
			// Add-on products (Sales\Addons) use no seats: the occurrence counters hold the units of every
			// type, so the add-on types' own counters are taken off again (and their holds are not "lapsed").
			$occ_rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,sold_qty,held_qty' . Sales\Addons::column_sql( '' ) . " FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $occurrence->occurrence_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal column list.
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$occ_types = array();
			$addon_units = 0;
			foreach ( $occ_rows as $occ_row ) {
				if ( Sales\Addons::is_addon( $occ_row ) ) { $addon_units += (int) $occ_row->sold_qty + (int) $occ_row->held_qty; } else { $occ_types[] = (int) $occ_row->ticket_type_id; }
			}
			$lapsed = $occ_types ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN (" . implode( ',', $occ_types ) . ") AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $now ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			$own = 0;
			if ( $exclude_order_id ) {
				$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(oi.counted_qty),0) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d AND tt.occurrence_id=%d" . Sales\Addons::not_addon_sql( 'tt' ), $exclude_order_id, $occurrence->occurrence_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal fragment.
					+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE r.order_id=%d AND tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s" . Sales\Addons::not_addon_sql( 'tt' ), $exclude_order_id, $occurrence->occurrence_id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal fragment.
				if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			}
			if ( (int) $occurrence->sold_qty + (int) $occurrence->held_qty - $addon_units - $lapsed - $own + $occurrence_quantity > (int) $occurrence->capacity ) {
				return 'occurrence_sold_out';
			}
		}
		return '';
	}

	/** capacity_error() by summing paid lines and active holds (used until the counters are verified). */
	private static function summed( $type, $occurrence, $quantity, $exclude_order_id, $occurrence_quantity ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		$exclude_order_id = absint( $exclude_order_id );
		// Sold and held units in ONE statement each: two statements could straddle a payment that moves
		// units from held to sold (under READ COMMITTED they would be missing from both sums).
		if ( (int) $type->capacity > 0 ) {
			$used = $wpdb->get_var( $wpdb->prepare(
				"SELECT (SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d) + (SELECT COALESCE(SUM(quantity),0) FROM %i WHERE ticket_type_id=%d AND status='active' AND expires_at > %s AND order_id <> %d)",
				$p . 'wpcap_order_items', $p . 'wpcap_orders', $type->ticket_type_id, $exclude_order_id,
				$p . 'wpcap_reservations', $type->ticket_type_id, $now, $exclude_order_id
			) );
			if ( '' !== $wpdb->last_error || null === $used ) { return 'db_busy'; }
			if ( (int) $used + $quantity > (int) $type->capacity ) {
				return 'sold_out';
			}
		}
		if ( (int) $occurrence->capacity > 0 && ! Sales\Addons::is_addon( $type ) ) {
			$no_addon = Sales\Addons::not_addon_sql( 'tt' ); // Add-on products use no seats.
			$occ_used = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal fragment.
				"SELECT (SELECT COALESCE(SUM(oi.quantity - oi.refunded_quantity),0) FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id=%d AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.order_id <> %d$no_addon) + (SELECT COALESCE(SUM(r.quantity),0) FROM %i r INNER JOIN %i tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id=%d AND r.status='active' AND r.expires_at > %s AND r.order_id <> %d$no_addon)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal add-on fragment (Sales\Addons::not_addon_sql()).
				$p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_orders', $occurrence->occurrence_id, $exclude_order_id,
				$p . 'wpcap_reservations', $p . 'wpcap_ticket_types', $occurrence->occurrence_id, $now, $exclude_order_id
			) );
			if ( '' !== $wpdb->last_error || null === $occ_used ) { return 'db_busy'; }
			if ( (int) $occ_used + $occurrence_quantity > (int) $occurrence->capacity ) {
				return 'occurrence_sold_out';
			}
		}
		return '';
	}
}
