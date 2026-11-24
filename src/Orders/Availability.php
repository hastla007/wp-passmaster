<?php
/**
 * Remaining sellable units of ticket types for display and cart validation (non-locking).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\CapacityCounters;
use PassCaptain\Clock;
use PassCaptain\DisplayBatch;
use PassCaptain\Orders;
use PassCaptain\Sales;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Per-request memo, short object cache and the uncached computation behind Orders::availability() and
 * Orders::availability_many(). Moved out of Orders without behaviour change (TODO "Gott-Klasse Orders");
 * the filter `query` keeps its callback Orders::note_query, so listeners and tests see the same hook.
 *
 * @internal Not part of the add-on API: only Orders calls it; use the Orders facade (same signatures).
 */
final class Availability {
	/** Per-request availability memo: ticket_type_id => remaining (int|null), of the site $memo_prefix. */
	private static $availability_memo = array();

	/** Table prefix the memo belongs to (multisite: switch_to_blog() changes it within a request). */
	private static $memo_prefix = null;

	/** Whether this request wrote to plugin tables (then the object cache is not read any more). */
	private static $wrote = false;

	private static $query_hooked = false;

	/**
	 * Forgets memoised availability. Runs on every write to a plugin table of this request (filter
	 * `query`), when capacity counters change and on demand (e.g. after module data changed).
	 */
	public static function flush() {
		self::$availability_memo = array();
		self::$wrote = true;
		/** Request caches of modules (holds, passes, seat links, limits) are stale after a write. */
		do_action( 'wpcap_availability_flushed' );
	}

	/**
	 * Action `switch_blog` (and any other prefix change): the memo and the request caches of the
	 * modules belong to the previous site. Does not count as a write (the object cache stays usable;
	 * its keys contain the prefix).
	 */
	public static function site_switched() {
		global $wpdb;
		self::$availability_memo = array();
		self::$memo_prefix = (string) $wpdb->prefix;
		do_action( 'wpcap_availability_flushed' );
	}

	/** Filter `query`: a write to a plugin table (or a rollback) invalidates the availability memo. */
	public static function note_query( $query ) {
		if ( is_string( $query ) && preg_match( '/^\s*(?:(?:INSERT|UPDATE|DELETE|REPLACE)\b[^;]*wpcap_|ROLLBACK)/is', $query ) ) {
			self::flush();
		}
		return $query;
	}

	/**
	 * Remaining sellable units for a ticket type (non-locking, for display/cart validation).
	 * Never used for capacity decisions (those run in capacity_error() under row locks).
	 *
	 * Memoised per request (invalidated by any write to a plugin table in this request) and kept
	 * for a few seconds in the object cache (filter `wpcap_availability_cache_ttl`, default 10 s,
	 * 0 disables; only read by requests that have not written to plugin tables).
	 *
	 * @return int|null Null when neither the type nor the occurrence is limited.
	 */
	public static function of( $ticket_type_id ) {
		$ticket_type_id = absint( $ticket_type_id );
		$result = self::many( array( $ticket_type_id ) );
		return array_key_exists( $ticket_type_id, $result ) ? $result[ $ticket_type_id ] : 0;
	}

	/**
	 * availability() for many ticket types with a constant number of queries (listings, REST).
	 *
	 * @param int[] $ticket_type_ids Ticket type ids.
	 * @param array $rows            Optional preloaded rows: types (id => full row), occurrences (id => full row).
	 * @return array ticket_type_id => int|null (0 for unknown types).
	 */
	public static function many( array $ticket_type_ids, array $rows = array() ) {
		global $wpdb;
		if ( ! self::$query_hooked && function_exists( 'add_filter' ) ) {
			add_filter( 'query', array( Orders::class, 'note_query' ) );
			self::$query_hooked = true;
		}
		if ( self::$memo_prefix !== (string) $wpdb->prefix ) { self::site_switched(); }
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_type_ids ) ) ) );
		$out = array();
		$missing = array();
		foreach ( $ids as $id ) {
			if ( array_key_exists( $id, self::$availability_memo ) ) { $out[ $id ] = self::$availability_memo[ $id ]; } else { $missing[] = $id; }
		}
		if ( ! $missing ) {
			return $out;
		}
		$ttl = max( 0, min( 60, (int) apply_filters( 'wpcap_availability_cache_ttl', 10 ) ) );
		$use_cache = $ttl > 0 && function_exists( 'wp_cache_get' );
		$cache_key = static function ( $id ) use ( $wpdb ) { return 'availability:' . $wpdb->prefix . $id; };
		if ( $use_cache && ! self::$wrote ) {
			foreach ( $missing as $i => $id ) {
				$found = false;
				$cached = wp_cache_get( $cache_key( $id ), 'wpcap', false, $found );
				if ( $found && is_array( $cached ) && array_key_exists( 'v', $cached ) ) {
					$out[ $id ] = self::$availability_memo[ $id ] = $cached['v'];
					unset( $missing[ $i ] );
				}
			}
		}
		if ( ! $missing ) {
			return $out;
		}
		foreach ( self::compute( array_values( $missing ), $rows ) as $id => $value ) {
			$out[ $id ] = self::$availability_memo[ $id ] = $value;
			if ( $use_cache ) { wp_cache_set( $cache_key( $id ), array( 'v' => $value ), 'wpcap', $ttl ); }
		}
		return $out;
	}

	/** Uncached availability of ticket types (see availability_many()). */
	private static function compute( array $ids, array $rows ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		$types = array();
		$need = array();
		foreach ( $ids as $id ) {
			// Preloaded rows, else the display batch (event page, GET /events), else one grouped query.
			$batched = isset( $rows['types'][ $id ] ) ? null : DisplayBatch::type( $id );
			if ( isset( $rows['types'][ $id ] ) && is_object( $rows['types'][ $id ] ) && isset( $rows['types'][ $id ]->status ) ) { $types[ $id ] = $rows['types'][ $id ]; } elseif ( $batched && isset( $batched->status ) ) { $types[ $id ] = $batched; } else { $need[] = $id; }
		}
		if ( $need ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN (" . implode( ',', $need ) . ')' ) as $row ) { $types[ (int) $row->ticket_type_id ] = $row; }
		}
		$occurrences = array();
		$need = array();
		foreach ( $types as $type ) {
			$occ_id = (int) $type->occurrence_id;
			$batched = isset( $rows['occurrences'][ $occ_id ] ) ? null : DisplayBatch::occurrence( $occ_id );
			if ( isset( $rows['occurrences'][ $occ_id ] ) && is_object( $rows['occurrences'][ $occ_id ] ) && isset( $rows['occurrences'][ $occ_id ]->status ) ) { $occurrences[ $occ_id ] = $rows['occurrences'][ $occ_id ]; } elseif ( $batched && isset( $batched->status ) ) { $occurrences[ $occ_id ] = $batched; } else { $need[ $occ_id ] = $occ_id; }
		}
		if ( $need ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id IN (" . implode( ',', array_map( 'intval', $need ) ) . ')' ) as $row ) { $occurrences[ (int) $row->occurrence_id ] = $row; }
		}
		$limited_types = array();
		$limited_occ = array();
		foreach ( $types as $id => $type ) {
			if ( (int) $type->capacity > 0 ) { $limited_types[] = (int) $id; }
			$occ = $occurrences[ (int) $type->occurrence_id ] ?? null;
			if ( $occ && (int) $occ->capacity > 0 && ! Sales\Addons::is_addon( $type ) ) { $limited_occ[ (int) $occ->occurrence_id ] = (int) $occ->occurrence_id; }
		}
		$type_used = array();
		$occ_used = array();
		$grouped = static function ( $sql ) use ( $wpdb ) {
			$map = array();
			foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
				$row = array_values( (array) $row );
				$map[ (int) $row[0] ] = (int) $row[1];
			}
			return $map;
		};
		$counted = CapacityCounters::enabled() && ( ! $types || isset( reset( $types )->sold_qty ) );
		if ( $limited_types ) {
			$in = implode( ',', $limited_types );
			if ( $counted ) {
				// Counters minus holds that are still marked active but already lapsed.
				$lapsed = $grouped( $wpdb->prepare( "SELECT ticket_type_id,SUM(counted_qty) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at <= %s GROUP BY ticket_type_id", $now ) );
				foreach ( $limited_types as $id ) { $type_used[ $id ] = (int) $types[ $id ]->sold_qty + (int) $types[ $id ]->held_qty - ( $lapsed[ $id ] ?? 0 ); }
			} else {
				$sold = $grouped( "SELECT oi.ticket_type_id,SUM(oi.quantity - oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id IN ($in) AND ord.payment_status='paid' AND ord.status <> 'refunded' GROUP BY oi.ticket_type_id" );
				$held = $grouped( $wpdb->prepare( "SELECT ticket_type_id,SUM(quantity) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at > %s GROUP BY ticket_type_id", $now ) );
				foreach ( $limited_types as $id ) { $type_used[ $id ] = ( $sold[ $id ] ?? 0 ) + ( $held[ $id ] ?? 0 ); }
			}
		}
		if ( $limited_occ ) {
			$in = implode( ',', $limited_occ );
			// Add-on products (Sales\Addons) use no seats of the occurrence.
			$no_addon = Sales\Addons::not_addon_sql( 'tt' );
			if ( $counted ) {
				$lapsed = $grouped( $wpdb->prepare( "SELECT tt.occurrence_id,SUM(r.counted_qty) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.status='active' AND r.expires_at <= %s WHERE tt.occurrence_id IN ($in)$no_addon GROUP BY tt.occurrence_id", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list and literal add-on fragment.
				// Rows of a listing already carry the add-on units (Sales\Addons::annotate_occurrences()).
				$addon_units = array();
				$addon_missing = array();
				foreach ( $limited_occ as $occ_id ) {
					if ( isset( $occurrences[ $occ_id ]->addon_qty ) ) { $addon_units[ $occ_id ] = (int) $occurrences[ $occ_id ]->addon_qty; } else { $addon_missing[] = (int) $occ_id; }
				}
				if ( $addon_missing && Sales\Addons::ready() ) {
					$addon_units += $grouped( "SELECT occurrence_id,SUM(sold_qty+held_qty) FROM {$p}wpcap_ticket_types WHERE occurrence_id IN (" . implode( ',', $addon_missing ) . ") AND kind='" . Sales\Addons::ADDON . "' GROUP BY occurrence_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list and class constant.
				}
				foreach ( $limited_occ as $occ_id ) { $occ_used[ $occ_id ] = (int) $occurrences[ $occ_id ]->sold_qty + (int) $occurrences[ $occ_id ]->held_qty - ( $addon_units[ $occ_id ] ?? 0 ) - ( $lapsed[ $occ_id ] ?? 0 ); }
			} else {
				$sold = $grouped( "SELECT tt.occurrence_id,SUM(oi.quantity - oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE tt.occurrence_id IN ($in) AND ord.payment_status='paid' AND ord.status <> 'refunded'$no_addon GROUP BY tt.occurrence_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list and literal add-on fragment.
				$held = $grouped( $wpdb->prepare( "SELECT tt.occurrence_id,SUM(r.quantity) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=r.ticket_type_id WHERE tt.occurrence_id IN ($in) AND r.status='active' AND r.expires_at > %s$no_addon GROUP BY tt.occurrence_id", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list and literal add-on fragment.
				foreach ( $limited_occ as $occ_id ) { $occ_used[ $occ_id ] = ( $sold[ $occ_id ] ?? 0 ) + ( $held[ $occ_id ] ?? 0 ); }
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$type = $types[ $id ] ?? null;
			$occurrence = $type ? ( $occurrences[ (int) $type->occurrence_id ] ?? null ) : null;
			if ( ! $type || ! $occurrence ) { $out[ $id ] = 0; continue; }
			$limits = array();
			if ( (int) $type->capacity > 0 ) { $limits[] = (int) $type->capacity - $type_used[ $id ]; }
			if ( (int) $occurrence->capacity > 0 && ! Sales\Addons::is_addon( $type ) ) { $limits[] = (int) $occurrence->capacity - $occ_used[ (int) $occurrence->occurrence_id ]; }
			$remaining = $limits ? max( 0, min( $limits ) ) : null;
			/**
			 * Remaining units after module limits (e.g. series quota pools). Return null for unlimited
			 * or a smaller non-negative int; never more than the core value.
			 */
			$filtered = apply_filters( 'wpcap_ticket_availability', $remaining, $type, $occurrence );
			if ( null === $filtered || ! is_numeric( $filtered ) ) { $out[ $id ] = $remaining; continue; }
			$filtered = max( 0, (int) $filtered );
			$out[ $id ] = null === $remaining ? $filtered : min( $remaining, $filtered );
		}
		return $out;
	}
}
