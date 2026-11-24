<?php
/**
 * Holds of bank transfer / manual payment orders and hold deadlines of pending orders.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Audit;
use PassCaptain\Clock;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::manual_hold_minutes(), manual_hold_cap(), manual_hold_error(),
 * manual_event_hold_cap(), manual_held_for_event(), manual_holds_exhausted(), manual_hold_until(),
 * extend_hold(), extend_hold_to(), hold_end(), extend_hold_for_delayed_payment(), has_active_hold(), lock_holds()
 * and set_hold_status(). Moved out of Orders without behaviour change (TODO "Gott-Klasse Orders").
 *
 * @internal Not part of the add-on API: only Orders calls it; use the Orders facade (same signatures).
 */
final class Holds {
	/**
	 * Hold for a bank transfer / manual payment: `manual_hold_hours`, but never beyond the start of
	 * the earliest occurrence of the given ticket types (seats are not blocked past the start, and
	 * no payment arrives after the event). Null when the start is closer than `manual_cutoff_hours`
	 * (or already passed): bank transfer is then not offered.
	 *
	 * @param int[] $type_ids Ticket types of the order.
	 * @return int|null Minutes.
	 */
	public static function minutes( array $type_ids ) {
		global $wpdb;
		$minutes = max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60;
		$type_ids = array_filter( array_map( 'absint', $type_ids ) );
		if ( ! $type_ids ) { return $minutes; }
		$start = $wpdb->get_var( "SELECT MIN(o.starts_at) FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id IN (" . implode( ',', $type_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		return self::until( $start ? strtotime( $start . ' UTC' ) : 0, $minutes );
	}

	/**
	 * Upper bound for units held by unpaid bank transfer orders (A634), per ticket type and per date with a
	 * capacity: `manual_hold_max_percent` of the capacity (0 = no bound), but never fewer than
	 * `wpcap_manual_hold_min_units` units (default 10), so small events still sell by bank transfer.
	 *
	 * @param int $capacity Capacity (> 0).
	 * @return int|null Null when there is no bound.
	 */
	public static function cap( $capacity ) {
		$percent = min( 100, max( 0, (int) Settings::get( 'manual_hold_max_percent' ) ) );
		if ( $percent < 1 || $percent >= 100 || (int) $capacity < 1 ) { return null; }
		$floor = max( 1, (int) apply_filters( 'wpcap_manual_hold_min_units', 10 ) );
		return max( $floor, (int) ceil( (int) $capacity * $percent / 100 ) );
	}

	/**
	 * Checks the new manual order's lines against manual_hold_cap() and manual_event_hold_cap() (inside
	 * Orders::create() through Orders::manual_hold_error(), with the ticket types and occurrences locked). Returns 'manual_hold_limit',
	 * 'manual_event_hold_limit', 'db_busy' or ''.
	 *
	 * @param int[]    $lines       ticket_type_id => quantity.
	 * @param object[] $types       Locked ticket types by id.
	 * @param object[] $occurrences Locked occurrences by id.
	 * @param string   $now         UTC now.
	 */
	public static function error( array $lines, array $types, array $occurrences, $now ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$held_sql = "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id INNER JOIN {$p}wpcap_ticket_types t ON t.ticket_type_id=r.ticket_type_id WHERE r.status='active' AND r.expires_at > %s AND o.payment_provider='manual' AND o.payment_status='pending' AND ";
		$per_occurrence = array();
		foreach ( $lines as $type_id => $quantity ) {
			$type = $types[ $type_id ];
			$occ_id = (int) $type->occurrence_id;
			$per_occurrence[ $occ_id ] = ( $per_occurrence[ $occ_id ] ?? 0 ) + (int) $quantity;
			$cap = self::cap( (int) $type->capacity );
			if ( null === $cap ) { continue; }
			$held = (int) $wpdb->get_var( $wpdb->prepare( $held_sql . 'r.ticket_type_id=%d', $now, (int) $type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL with placeholders.
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $held + (int) $quantity > $cap ) { return 'manual_hold_limit'; }
		}
		foreach ( $per_occurrence as $occ_id => $quantity ) {
			$occurrence = $occurrences[ $occ_id ] ?? null;
			$cap = $occurrence ? self::cap( (int) $occurrence->capacity ) : null;
			if ( null === $cap ) { continue; }
			$held = (int) $wpdb->get_var( $wpdb->prepare( $held_sql . 't.occurrence_id=%d', $now, (int) $occ_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL with placeholders.
			if ( '' !== $wpdb->last_error ) { return 'db_busy'; }
			if ( $held + (int) $quantity > $cap ) { return 'manual_hold_limit'; }
		}
		// Absolute bound per event over all its dates (`manual_hold_max_event_tickets`, 0 = off). Dates of the
		// same event are locked one by one, so two checkouts for different dates may overshoot it by one order.
		$event_cap = self::event_cap();
		if ( $event_cap ) {
			$per_event = array();
			foreach ( $per_occurrence as $occ_id => $quantity ) {
				$event_id = isset( $occurrences[ $occ_id ]->event_id ) ? (int) $occurrences[ $occ_id ]->event_id : 0;
				if ( $event_id ) { $per_event[ $event_id ] = ( $per_event[ $event_id ] ?? 0 ) + (int) $quantity; }
			}
			foreach ( $per_event as $event_id => $quantity ) {
				$held = self::held_for_event( $event_id, $now );
				if ( null === $held ) { return 'db_busy'; }
				if ( $held + (int) $quantity > $event_cap ) { return 'manual_event_hold_limit'; }
			}
		}
		return '';
	}

	/** Absolute bound of tickets held by unpaid bank transfer orders per event (0 = no bound). */
	public static function event_cap() {
		return max( 0, (int) Settings::get( 'manual_hold_max_event_tickets', 0 ) );
	}

	/**
	 * Tickets held right now by unpaid bank transfer orders (active, unexpired holds) over all dates of an
	 * event, or of one date when $occurrence_id is given. Null on a database error.
	 */
	public static function held_for_event( $event_id, $now = null, $occurrence_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = null === $now ? Clock::mysql() : (string) $now;
		$where = $occurrence_id ? $wpdb->prepare( 't.occurrence_id=%d', absint( $occurrence_id ) ) : $wpdb->prepare( 'oc.event_id=%d', absint( $event_id ) );
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(r.quantity),0) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id INNER JOIN {$p}wpcap_ticket_types t ON t.ticket_type_id=r.ticket_type_id INNER JOIN {$p}wpcap_occurrences oc ON oc.occurrence_id=t.occurrence_id WHERE r.status='active' AND r.expires_at > %s AND o.payment_provider='manual' AND o.payment_status='pending' AND " . $where, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
		return '' !== $wpdb->last_error ? null : (int) $held;
	}

	/**
	 * Whether unpaid bank transfer orders already hold as much of a date as they may (the event bound
	 * `manual_hold_max_event_tickets` or the date's share `manual_hold_max_percent`): the checkout then
	 * offers no bank transfer for it (PublicEvents::card_providers()); Orders::create() refuses it anyway.
	 * Bounds per ticket type are not considered here (other types of the date may still be open).
	 */
	public static function exhausted( $event_id, $occurrence_id ) {
		global $wpdb;
		$event_cap = self::event_cap();
		if ( $event_cap && $event_id ) {
			$held = self::held_for_event( $event_id );
			if ( null !== $held && $held >= $event_cap ) { return true; }
		}
		if ( ! $occurrence_id || null === self::cap( 100 ) ) { return false; } // Share switched off.
		$capacity = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT capacity FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
		$cap = self::cap( $capacity );
		if ( null !== $cap ) {
			$held = self::held_for_event( 0, null, $occurrence_id );
			if ( null !== $held && $held >= $cap ) { return true; }
		}
		return false;
	}

	/** manual_hold_minutes() for a known start timestamp (0 = unknown: no cap). */
	public static function until( $start_ts, $minutes = null ) {
		$minutes = null === $minutes ? max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60 : (int) $minutes;
		if ( ! $start_ts ) { return $minutes; }
		$left = (int) floor( ( (int) $start_ts - Clock::now() ) / 60 );
		$cutoff = max( 0, (int) Settings::get( 'manual_cutoff_hours' ) ) * 60;
		if ( $left < 1 || $left < $cutoff ) { return null; }
		return max( 1, min( $minutes, $left ) );
	}

	/** Extends active holds of a pending order (e.g. WooCommerce "on-hold" bank transfers). */
	public static function extend( $order_id, $minutes ) {
		return self::extend_to( $order_id, Clock::now() + max( 1, absint( $minutes ) ) * MINUTE_IN_SECONDS );
	}

	/** Extends the active, unexpired holds of an order to at least $until_ts (never shortens). */
	public static function extend_to( $order_id, $until_ts ) {
		global $wpdb;
		$until = gmdate( 'Y-m-d H:i:s', (int) $until_ts );
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=GREATEST(expires_at,%s) WHERE order_id=%d AND status='active' AND expires_at > %s", $until, absint( $order_id ), Clock::mysql() ) );
	}

	/** End of the order's hold (earliest active, unexpired hold) as timestamp; 0 without one. */
	public static function end( $order_id ) {
		global $wpdb;
		$end = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s", absint( $order_id ), Clock::mysql() ) );
		return $end ? (int) strtotime( $end . ' UTC' ) : 0;
	}

	/**
	 * Delayed payment methods (Stripe asynchronous methods such as SEPA Direct Debit, Mollie bank
	 * transfer) confirm days after the buyer finished the payment page. When the buyer chose one,
	 * the hold is extended like a bank transfer hold (`manual_hold_hours`, at most until the start,
	 * not within `manual_cutoff_hours`; see manual_hold_minutes()), so the payment does not arrive
	 * after the hold (A577).
	 *
	 * @return int|null New hold end (timestamp), or null when the start is too close (no extension:
	 *                  a later payment goes through the late payment re-check).
	 */
	public static function extend_for_delayed_payment( $order_id ) {
		$type_ids = array_map( static function ( $item ) { return (int) $item->ticket_type_id; }, Orders::items_for_order( $order_id ) );
		$minutes = self::minutes( $type_ids );
		if ( null === $minutes ) { return null; }
		$until = Clock::now() + $minutes * MINUTE_IN_SECONDS;
		self::extend_to( $order_id, $until );
		Audit::record( 'hold_extended_delayed_payment', 'order', absint( $order_id ), array( 'minutes' => $minutes ) );
		return max( $until, self::end( $order_id ) );
	}

	/** Whether the order still has an unexpired active hold (non-locking read). */
	public static function active( $order_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id=%d AND status='active' AND expires_at > %s", $wpdb->prefix . 'wpcap_reservations', absint( $order_id ), Clock::mysql() ) ) > 0;
	}

	/**
	 * Locks reservations by primary key (FOR UPDATE): record locks only.
	 *
	 * @return array|false Reservation rows (reservation_id, status, expires_at) or false on a database error.
	 */
	public static function lock( array $hold_ids ) {
		global $wpdb;
		$hold_ids = array_values( array_filter( array_map( 'absint', $hold_ids ) ) );
		if ( ! $hold_ids ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT reservation_id,status,expires_at FROM %i WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $hold_ids ), '%d' ) ) . ') ORDER BY reservation_id FOR UPDATE', array_merge( array( $wpdb->prefix . 'wpcap_reservations' ), $hold_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
		return '' !== $wpdb->last_error ? false : (array) $rows;
	}

	/** Sets the status of reservations (by primary key) that are in one of $from; false on a database error. */
	public static function set_status( array $hold_ids, $status, array $from ) {
		global $wpdb;
		$hold_ids = array_values( array_filter( array_map( 'absint', $hold_ids ) ) );
		if ( ! $hold_ids ) { return 0; }
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status=%s WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $hold_ids ), '%d' ) ) . ') AND status IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_reservations', (string) $status ), $hold_ids, array_values( $from ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
	}
}
