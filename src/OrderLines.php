<?php
/**
 * Order lines "as paid" (schema 3.8.0).
 *
 * A ticket exchange moves units and money between the lines of a paid order (the live columns
 * quantity / line_total_minor / tax_minor feed capacity and refund maths). Accounting must not see
 * those moves: invoices, reports, settlements, Z reports and affiliate commissions read the values
 * as they were paid. Before the first mutation of a paid line, Exchange\Service stores the paid
 * values in orig_quantity / orig_line_total_minor / orig_tax_minor (NULL = never changed); lines an
 * exchange creates get orig_* = 0 (nothing was paid for them in this order – the money is on the
 * original line, a supplementary order or a refund).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrderLines {
	/** Per-request cache of ready(). */
	private static $ready = null;

	/**
	 * Whether the 3.8.0 columns (orig_*, discount_minor) exist. Until a deferred upgrade has run, the
	 * live columns are used (no "unknown column" errors in that window).
	 */
	public static function ready() {
		if ( null === self::$ready ) {
			$version = (string) get_option( 'wpcap_db_version', '' );
			$version = strtok( $version, '+' );
			self::$ready = false !== $version && '' !== $version && version_compare( $version, '3.8.0', '>=' );
		}
		return self::$ready;
	}

	/** Resets the cache (after an install/upgrade in the same request, tests). */
	public static function flush() {
		self::$ready = null;
	}

	/**
	 * SELECT fragment with the paid values under the usual column names.
	 *
	 * @param string $alias Table alias of wpcap_order_items ('' for none).
	 */
	public static function paid_columns( $alias = 'oi' ) {
		$a = '' !== $alias ? $alias . '.' : '';
		if ( ! self::ready() ) { return "{$a}quantity,{$a}line_total_minor,{$a}tax_minor"; }
		return "COALESCE({$a}orig_quantity,{$a}quantity) AS quantity,COALESCE({$a}orig_line_total_minor,{$a}line_total_minor) AS line_total_minor,COALESCE({$a}orig_tax_minor,{$a}tax_minor) AS tax_minor,{$a}discount_minor";
	}

	/** Expression for one paid column ('quantity', 'line_total_minor', 'tax_minor'). */
	public static function paid( $column, $alias = 'oi' ) {
		$a = '' !== $alias ? $alias . '.' : '';
		if ( ! self::ready() ) { return $a . $column; }
		$orig = 'quantity' === $column ? 'orig_quantity' : 'orig_' . $column;
		return "COALESCE({$a}{$orig},{$a}{$column})";
	}

	/**
	 * Stores the paid values of a line before it is changed (idempotent: the first snapshot wins).
	 * Call inside the caller's transaction, after the line is locked.
	 *
	 * @return bool False on a database error.
	 */
	public static function freeze( $item_id ) {
		global $wpdb;
		if ( ! self::ready() ) { return true; }
		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_order_items SET orig_quantity=COALESCE(orig_quantity,quantity),orig_line_total_minor=COALESCE(orig_line_total_minor,line_total_minor),orig_tax_minor=COALESCE(orig_tax_minor,tax_minor) WHERE item_id=%d", absint( $item_id ) ) );
	}

	/**
	 * Gross per line after the order discount: the discount stored per line at checkout (campaigns
	 * apply to their eligible lines only, schema 3.8.0); for older orders (or lines without it) the
	 * order discount is spread proportionally, the last line taking the remainder.
	 *
	 * @param object[] $lines    Lines with line_total_minor (and discount_minor when available).
	 * @param int      $discount Order discount (orders.discount_minor).
	 * @return int[] Index (0-based, in the given order) => gross after discount.
	 */
	public static function after_discount( array $lines, $discount ) {
		$lines = array_values( $lines );
		$stored = 0;
		$complete = (bool) $lines;
		foreach ( $lines as $line ) {
			if ( ! isset( $line->discount_minor ) || null === $line->discount_minor ) { $complete = false; break; }
			$stored += (int) $line->discount_minor;
		}
		$out = array();
		if ( $complete && $stored === (int) $discount ) {
			foreach ( $lines as $i => $line ) { $out[ $i ] = max( 0, (int) $line->line_total_minor - (int) $line->discount_minor ); }
			return $out;
		}
		$subtotal = array_sum( array_map( static function ( $l ) { return (int) $l->line_total_minor; }, $lines ) );
		$left = (int) $discount;
		$last = count( $lines ) - 1;
		foreach ( $lines as $i => $line ) {
			$share = $i === $last ? $left : ( $subtotal > 0 ? intdiv( (int) $discount * (int) $line->line_total_minor, $subtotal ) : 0 );
			$share = min( (int) $line->line_total_minor, max( 0, $share ) );
			$left -= $share;
			$out[ $i ] = (int) $line->line_total_minor - $share;
		}
		return $out;
	}

	/** Whether a line (paid values) carries nothing: created by an exchange. */
	public static function is_moved_in( $line ) {
		return 0 === (int) $line->quantity && 0 === (int) $line->line_total_minor;
	}
}
