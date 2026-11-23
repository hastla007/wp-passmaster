<?php
/**
 * Presale/service fee (A425), off by default.
 *
 * A fee per ticket (setting `service_fee_ticket_minor`, or per ticket type in the column
 * wpcap_ticket_types.service_fee_minor: NULL = the setting, 0 = no fee for this type) and/or per order
 * (setting `service_fee_order_minor`) for the own online checkout. Trusted channels (WooCommerce,
 * box office, partners, staff holds, guest lists, waitlist offers, service bookings) and free orders
 * never carry it; a line that is free after its discount carries no per-ticket fee.
 *
 * The fee is an order line of its own (wpcap_order_items.fee_kind 'ticket' or 'order', quantity 0 =
 * no admission and no inventory) next to the ticket line it belongs to. It takes the VAT rate and tax
 * profile columns of that ticket line ("tax like the ticket line"), so invoices, the VAT summary per
 * rate, reports and the accounting export include it without special cases. Campaign discounts never
 * reduce it. The amounts that applied are frozen in the order's price_snapshot ("service_fee").
 *
 * Refunds follow the existing rules (Refunds::calculate()): a refunded ticket takes its per-ticket fee
 * along; the per-order fee is part of what is left and goes back with the last ticket (a full refund
 * refunds the order total). Deductions (deduct_minor/deduct_bp) work as before.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceFee {
	const KIND_TICKET = 'ticket';
	const KIND_ORDER = 'order';

	public static function register() {
		add_filter( 'wpcap_invoice_items', array( __CLASS__, 'invoice_items' ), 20, 1 );
	}

	/** Whether the schema has the fee columns (wpcap_order_items.fee_kind, wpcap_ticket_types.service_fee_minor). */
	public static function ready() {
		return Log::has_column( 'wpcap_order_items', 'fee_kind' ) && Log::has_column( 'wpcap_ticket_types', 'service_fee_minor' );
	}

	/** Configured default fees (gross, minor units of the ticket currency). */
	public static function config() {
		return array(
			'ticket_minor' => max( 0, (int) Settings::get( 'service_fee_ticket_minor' ) ),
			'order_minor' => max( 0, (int) Settings::get( 'service_fee_order_minor' ) ),
		);
	}

	/**
	 * Per-ticket fee of a ticket type (gross, minor units): its own value, else the setting; 0 for free
	 * types and before the schema upgrade.
	 *
	 * @param object $type Ticket type row (service_fee_minor may be missing or NULL).
	 */
	public static function per_ticket( $type ) {
		if ( ! is_object( $type ) || (int) ( $type->price_minor ?? 0 ) <= 0 ) { return 0; }
		$own = property_exists( $type, 'service_fee_minor' ) ? $type->service_fee_minor : null;
		return max( 0, null === $own || '' === $own ? self::config()['ticket_minor'] : (int) $own );
	}

	/** Whether the checkout of these types may show a fee (display only). */
	public static function shown_for( array $types ) {
		if ( self::config()['order_minor'] > 0 ) { return true; }
		foreach ( $types as $type ) {
			if ( self::per_ticket( $type ) > 0 ) { return true; }
		}
		return false;
	}

	/**
	 * Fee lines of an order of the own online checkout (Orders::create(), after discounts and VAT).
	 *
	 * Filter `wpcap_service_fee` (array $fee, array $selected, array $context): change or drop the fee
	 * per order. $fee = ['ticket' => [ticket_type_id => per-ticket fee], 'order_minor' => int]; return
	 * zeros for no fee.
	 *
	 * @param array $selected Lines of Orders::create() (type, quantity, line_total, discount_minor, tax_rate_bp, tax_info).
	 * @param int   $payable  Order total after discounts (no fee on free orders).
	 * @param array $context  currency, email.
	 * @return array{lines: array[], total_minor:int, tax_minor:int, snapshot: array}|null Null = no fee.
	 */
	public static function for_order( array $selected, $payable, array $context = array() ) {
		if ( (int) $payable <= 0 || ! $selected ) { return null; }
		$selected = array_values( $selected );
		$fee = array( 'ticket' => array(), 'order_minor' => self::config()['order_minor'] );
		foreach ( $selected as $line ) {
			$fee['ticket'][ (int) $line['type']->ticket_type_id ] = self::per_ticket( $line['type'] );
		}
		$fee = (array) apply_filters( 'wpcap_service_fee', $fee, $selected, $context );
		$lines = array();
		$first = null;
		foreach ( $selected as $index => $line ) {
			$quantity = (int) $line['quantity'];
			// A line that is free after its discount carries no fee.
			if ( $quantity < 1 || (int) $line['line_total'] - (int) ( $line['discount_minor'] ?? 0 ) <= 0 ) { continue; }
			if ( null === $first ) { $first = $index; }
			$unit = max( 0, (int) ( $fee['ticket'][ (int) $line['type']->ticket_type_id ] ?? 0 ) );
			if ( $unit > 0 ) {
				$lines[] = array( 'index' => $index, 'kind' => self::KIND_TICKET, 'unit_minor' => $unit, 'total_minor' => $unit * $quantity, 'rate_bp' => (int) $line['tax_rate_bp'] );
			}
		}
		if ( null === $first ) { return null; }
		$per_order = max( 0, (int) ( $fee['order_minor'] ?? 0 ) );
		if ( $per_order > 0 ) {
			$lines[] = array( 'index' => $first, 'kind' => self::KIND_ORDER, 'unit_minor' => $per_order, 'total_minor' => $per_order, 'rate_bp' => (int) $selected[ $first ]['tax_rate_bp'] );
		}
		if ( ! $lines ) { return null; }
		$total = 0;
		$tax = 0;
		$by_kind = array( self::KIND_TICKET => 0, self::KIND_ORDER => 0 );
		foreach ( $lines as $i => $line ) {
			$lines[ $i ]['tax_minor'] = Orders::gross_tax( $line['total_minor'], $line['rate_bp'] );
			$total += $line['total_minor'];
			$tax += $lines[ $i ]['tax_minor'];
			$by_kind[ $line['kind'] ] += $line['total_minor'];
		}
		return array(
			'lines' => $lines,
			'total_minor' => $total,
			'tax_minor' => $tax,
			'snapshot' => array( 'total_minor' => $total, 'tax_minor' => $tax, 'ticket_minor' => $by_kind[ self::KIND_TICKET ], 'order_minor' => $by_kind[ self::KIND_ORDER ] ),
		);
	}

	/**
	 * Fee frozen with an order (price_snapshot.service_fee), or null.
	 *
	 * @param object $order Order row with price_snapshot.
	 * @return array|null total_minor, tax_minor, ticket_minor, order_minor.
	 */
	public static function of_order( $order ) {
		if ( ! is_object( $order ) || ! isset( $order->price_snapshot ) ) { return null; }
		$snapshot = json_decode( (string) $order->price_snapshot, true );
		$fee = is_array( $snapshot ) && is_array( $snapshot['service_fee'] ?? null ) ? $snapshot['service_fee'] : null;
		return $fee && (int) ( $fee['total_minor'] ?? 0 ) > 0 ? $fee : null;
	}

	/** Whether an order line (row with fee_kind) is a service fee line. */
	public static function is_fee_line( $line ) {
		return is_object( $line ) && '' !== (string) ( $line->fee_kind ?? '' );
	}

	/** Number of tickets a per-ticket fee line covers (1 for the per-order fee). */
	public static function covered( $line ) {
		$unit = (int) ( $line->unit_price_minor ?? 0 );
		$total = (int) ( $line->orig_line_total_minor ?? $line->line_total_minor ?? 0 );
		return self::KIND_TICKET === (string) ( $line->fee_kind ?? '' ) && $unit > 0 ? max( 1, intdiv( $total, $unit ) ) : 1;
	}

	/**
	 * Display name of a fee line.
	 *
	 * @param object $line      Order line with fee_kind.
	 * @param string $type_name Name of the ticket type it belongs to.
	 */
	public static function label( $line, $type_name = '' ) {
		if ( self::KIND_ORDER === (string) ( $line->fee_kind ?? '' ) ) {
			return __( 'Servicegebühr je Bestellung', 'pass-captain' );
		}
		return '' !== (string) $type_name
			/* translators: %s: ticket type name */
			? sprintf( __( 'Servicegebühr (%s)', 'pass-captain' ), $type_name )
			: __( 'Servicegebühr', 'pass-captain' );
	}

	/** wpcap_invoice_items: fee lines are named as such and count the tickets they cover. */
	public static function invoice_items( $items ) {
		foreach ( (array) $items as $index => $item ) {
			if ( ! self::is_fee_line( $item ) ) { continue; }
			$item = clone $item;
			$item->name = self::label( $item, (string) ( $item->name ?? '' ) );
			$item->quantity = self::covered( $item );
			$items[ $index ] = $item;
		}
		return $items;
	}

	/**
	 * Gross service fee and VAT per currency of paid orders in a range (reports).
	 *
	 * @param int[] $order_ids Orders of the report.
	 * @return array<string, array{gross:int, tax:int, orders:int}>
	 */
	public static function totals( array $order_ids ) {
		global $wpdb;
		$out = array();
		if ( ! $order_ids || ! self::ready() ) { return $out; }
		$p = $wpdb->prefix;
		foreach ( array_chunk( array_values( array_unique( array_map( 'absint', $order_ids ) ) ), 500 ) as $chunk ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.currency,COUNT(DISTINCT oi.order_id) AS orders,SUM(oi.line_total_minor) AS gross,SUM(oi.tax_minor) AS tax FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.fee_kind<>'' AND oi.order_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') GROUP BY o.currency', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
			foreach ( $rows as $row ) {
				$cur = (string) $row->currency;
				$out[ $cur ] = array(
					'gross' => (int) ( $out[ $cur ]['gross'] ?? 0 ) + (int) $row->gross,
					'tax' => (int) ( $out[ $cur ]['tax'] ?? 0 ) + (int) $row->tax,
					'orders' => (int) ( $out[ $cur ]['orders'] ?? 0 ) + (int) $row->orders,
				);
			}
		}
		return $out;
	}
}
