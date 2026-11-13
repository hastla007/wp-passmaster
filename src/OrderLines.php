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
	/**
	 * SELECT fragment with the paid values under the usual column names.
	 *
	 * @param string $alias Table alias of wpcap_order_items ('' for none).
	 */
	public static function paid_columns( $alias = 'oi' ) {
		$a = '' !== $alias ? $alias . '.' : '';
		return "COALESCE({$a}orig_quantity,{$a}quantity) AS quantity,COALESCE({$a}orig_line_total_minor,{$a}line_total_minor) AS line_total_minor,COALESCE({$a}orig_tax_minor,{$a}tax_minor) AS tax_minor";
	}

	/** Expression for one paid column ('quantity', 'line_total_minor', 'tax_minor'). */
	public static function paid( $column, $alias = 'oi' ) {
		$a = '' !== $alias ? $alias . '.' : '';
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
		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_order_items SET orig_quantity=COALESCE(orig_quantity,quantity),orig_line_total_minor=COALESCE(orig_line_total_minor,line_total_minor),orig_tax_minor=COALESCE(orig_tax_minor,tax_minor) WHERE item_id=%d", absint( $item_id ) ) );
	}

	/** Whether a line (paid values) carries nothing: created by an exchange. */
	public static function is_moved_in( $line ) {
		return 0 === (int) $line->quantity && 0 === (int) $line->line_total_minor;
	}
}
