<?php
/**
 * Add-on products (parking, merchandise, cloakroom …) bound to a main ticket (schema 3.18.5).
 *
 * A ticket type has a kind: "ticket" (default, every type created before 3.18.5) or "addon". An add-on
 * belongs to an occurrence like any ticket type and has its own price, tax rate/class and capacity, but:
 *  - it can only be bought together with at least one main ticket (kind "ticket") of the same occurrence
 *    in the same order; optionally at most addon_max_per_ticket units per main ticket (0 = no limit).
 *    Orders::create() checks this for every channel (online, box office, adapters, comps);
 *  - it produces no ticket row, so there is no QR code, no PDF, no wallet pass and nothing to check in.
 *    The order line is listed in the order summary (mail, portal), on the invoice and on the receipt with
 *    its own tax rate;
 *  - it does not use the occurrence capacity (venue seats), only its own type capacity;
 *  - it carries no per-ticket service fee, no attendee data and does not count towards buyer limits
 *    that apply to all ticket types of an event;
 *  - refunds: refunds are ticket based. A partial refund of main tickets never refunds an add-on; the
 *    refund of the last open tickets of an order refunds the rest of the order, add-ons included (the
 *    existing "last tickets get the remainder" rule of Refunds::calculate()). An add-on alone can be
 *    refunded by amount (Refunds::request_amount()).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Log;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Addons {
	const TICKET = 'ticket';
	const ADDON = 'addon';
	const KINDS = array( self::TICKET, self::ADDON );

	/** Whether wpcap_ticket_types has the kind column (schema 3.18.5; not yet during a deferred upgrade). */
	public static function ready() {
		return Log::has_column( 'wpcap_ticket_types', 'kind' );
	}

	/** Kind of a ticket type row ('ticket' when the column does not exist yet or holds anything else). */
	public static function kind( $type ) {
		$kind = is_object( $type ) ? (string) ( $type->kind ?? self::TICKET ) : self::TICKET;
		return in_array( $kind, self::KINDS, true ) ? $kind : self::TICKET;
	}

	/** Whether a ticket type row is an add-on product. */
	public static function is_addon( $type ) {
		return self::ADDON === self::kind( $type );
	}

	/** Units of the add-on allowed per main ticket of the occurrence in one order (0 = no limit). */
	public static function max_per_ticket( $type ) {
		return is_object( $type ) ? max( 0, (int) ( $type->addon_max_per_ticket ?? 0 ) ) : 0;
	}

	/**
	 * Splits ticket type rows into main tickets and add-ons, keys and order kept.
	 *
	 * @param object[] $types Ticket type rows.
	 * @return array{0: object[], 1: object[]} [tickets, addons]
	 */
	public static function split( array $types ) {
		$tickets = array();
		$addons = array();
		foreach ( $types as $key => $type ) {
			if ( self::is_addon( $type ) ) { $addons[ $key ] = $type; } else { $tickets[ $key ] = $type; }
		}
		return array( $tickets, $addons );
	}

	/** Main ticket types only (list re-indexed when it was a list). */
	public static function tickets( array $types ) {
		$tickets = self::split( $types )[0];
		return array_is_list( $types ) ? array_values( $tickets ) : $tickets;
	}

	/**
	 * Ids of add-on types among the given ticket type ids (one query; empty before the upgrade).
	 *
	 * @param int[] $type_ids Ticket type ids.
	 * @return int[]
	 */
	public static function addon_ids( array $type_ids ) {
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids || ! self::ready() ) { return array(); }
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_type_id FROM %i WHERE kind=%s AND ticket_type_id IN (' . implode( ',', $type_ids ) . ')', $wpdb->prefix . 'wpcap_ticket_types', self::ADDON ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list.
	}

	/**
	 * SQL condition (with leading AND) that leaves out add-on types of the ticket type alias; '' before
	 * the upgrade (every type is a ticket then).
	 */
	public static function not_addon_sql( $alias = 'tt' ) {
		$alias = preg_replace( '/[^a-z_]/', '', (string) $alias );
		return self::ready() ? ' AND ' . ( '' !== $alias ? $alias . '.' : '' ) . "kind<>'" . self::ADDON . "'" : '';
	}

	/** SELECT column of the kind (",tt.kind"), '' before the upgrade. */
	public static function column_sql( $alias = 'tt' ) {
		$alias = preg_replace( '/[^a-z_]/', '', (string) $alias );
		return self::ready() ? ',' . ( '' !== $alias ? $alias . '.' : '' ) . 'kind' : '';
	}

	/**
	 * Sets ->addon_qty (sold + held units of the add-on types, from the maintained counters) on occurrence
	 * rows with a capacity, so seat displays computed from the occurrence counters leave them out. One query.
	 *
	 * @param object[] $occurrences occurrence_id => row (SELECT o.*).
	 */
	public static function annotate_occurrences( array $occurrences ) {
		$ids = array();
		foreach ( $occurrences as $occurrence ) {
			if ( is_object( $occurrence ) && (int) ( $occurrence->capacity ?? 0 ) > 0 ) { $ids[] = (int) $occurrence->occurrence_id; }
		}
		if ( ! $ids || ! self::ready() ) { return; }
		global $wpdb;
		$units = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT occurrence_id,SUM(sold_qty+held_qty) AS units FROM %i WHERE kind=%s AND occurrence_id IN (' . implode( ',', array_unique( $ids ) ) . ') GROUP BY occurrence_id', $wpdb->prefix . 'wpcap_ticket_types', self::ADDON ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list.
			$units[ (int) $row->occurrence_id ] = (int) $row->units;
		}
		foreach ( $occurrences as $occurrence ) {
			if ( is_object( $occurrence ) && isset( $occurrence->occurrence_id ) ) { $occurrence->addon_qty = $units[ (int) $occurrence->occurrence_id ] ?? 0; }
		}
	}

	/**
	 * The add-on rule of an order: every add-on needs at least one main ticket of its occurrence in the
	 * same order, and at most addon_max_per_ticket units per main ticket.
	 *
	 * @param array    $lines ticket_type_id => quantity.
	 * @param object[] $types ticket_type_id => locked ticket type row.
	 * @return string '' or the checkout error code (addon_requires_ticket, addon_limit).
	 */
	public static function checkout_error( array $lines, array $types ) {
		$main = array();
		foreach ( $lines as $type_id => $quantity ) {
			$type = $types[ $type_id ] ?? null;
			if ( $type && ! self::is_addon( $type ) ) { $main[ (int) $type->occurrence_id ] = ( $main[ (int) $type->occurrence_id ] ?? 0 ) + (int) $quantity; }
		}
		foreach ( $lines as $type_id => $quantity ) {
			$type = $types[ $type_id ] ?? null;
			if ( ! $type || ! self::is_addon( $type ) ) { continue; }
			$tickets = $main[ (int) $type->occurrence_id ] ?? 0;
			if ( $tickets < 1 ) { return 'addon_requires_ticket'; }
			$max = self::max_per_ticket( $type );
			if ( $max > 0 && (int) $quantity > $max * $tickets ) { return 'addon_limit'; }
		}
		return '';
	}

	/** Buyer-facing label of an add-on order line (order summary in portal and mail). */
	public static function line_label( $name ) {
		/* translators: %s: name of the add-on product, e.g. "Parkplatz" */
		return sprintf( __( '%s (Zusatzprodukt)', 'pass-captain' ), (string) $name );
	}
}
