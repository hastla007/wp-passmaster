<?php
/**
 * Z reports (Kassenabschluss) of box office shifts.
 *
 * compute() derives the totals of a shift from its booked sales (per payment method and VAT rate)
 * and its movements (refund/storno payouts with their VAT split, cash in/out, corrections).
 * insert() writes the Z report row inside the closing transaction: its number is the next one of
 * the register (derived under the register lock, so gapless per register), the grand total is the
 * previous grand total plus this shift's net turnover, and every row is chained by hash to its
 * predecessor (verify() detects edits and gaps). Z rows are never updated or deleted.
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ZReport {
	/** Columns that make up the hash of a Z report (in this order). */
	const HASHED = array( 'register_id', 'z_number', 'shift_id', 'source', 'currency', 'opened_at', 'closed_at', 'opened_by', 'closed_by', 'net_total_minor', 'grand_total_minor', 'counted_cash_minor', 'expected_cash_minor', 'cash_difference_minor', 'counted_card_minor', 'expected_card_minor', 'card_difference_minor', 'difference_reason', 'totals', 'prev_hash' );

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_boxoffice_' . $name;
	}

	/* ------------------------------------------------------------------ totals */

	/**
	 * Splits a gross amount and its VAT over VAT rates in proportion to the given lines; the last
	 * rate takes the rounding remainder, so the parts add up exactly.
	 *
	 * @param array $lines rate_bp => [gross, tax]
	 * @return array rate_bp => [gross, tax, net]
	 */
	public static function split_vat( array $lines, $gross, $tax ) {
		$gross = (int) $gross;
		$tax = (int) $tax;
		ksort( $lines );
		if ( ! $lines ) { return array( 0 => array( 'gross' => $gross, 'tax' => $tax, 'net' => $gross - $tax ) ); }
		$sum_g = array_sum( array_map( static function ( $l ) { return (int) $l[0]; }, $lines ) );
		$sum_t = array_sum( array_map( static function ( $l ) { return (int) $l[1]; }, $lines ) );
		$out = array();
		$left_g = $gross;
		$left_t = $tax;
		$rates = array_keys( $lines );
		$last = end( $rates );
		foreach ( $lines as $rate => $l ) {
			if ( $rate === $last ) {
				$g = $left_g;
				$t = $left_t;
			} else {
				$g = $sum_g ? (int) round( $l[0] * $gross / $sum_g ) : 0;
				$t = $sum_t ? (int) round( $l[1] * $tax / $sum_t ) : 0;
			}
			$left_g -= $g;
			$left_t -= $t;
			$out[ (int) $rate ] = array( 'gross' => $g, 'tax' => $t, 'net' => $g - $t );
		}
		return $out;
	}

	/** Order lines per VAT rate: order_id => rate_bp => [gross, tax, quantity]. */
	private static function order_lines( array $order_ids ) {
		global $wpdb;
		$order_ids = array_filter( array_map( 'absint', $order_ids ) );
		if ( ! $order_ids ) { return array(); }
		$out = array();
		$rows = $wpdb->get_results( "SELECT order_id,tax_rate_bp,COALESCE(SUM(" . \PassCaptain\OrderLines::paid( 'line_total_minor', '' ) . "),0) AS g,COALESCE(SUM(" . \PassCaptain\OrderLines::paid( 'tax_minor', '' ) . "),0) AS t,COALESCE(SUM(" . \PassCaptain\OrderLines::paid( 'quantity', '' ) . "),0) AS q FROM {$wpdb->prefix}wpcap_order_items WHERE order_id IN (" . implode( ',', $order_ids ) . ') GROUP BY order_id,tax_rate_bp' );
		foreach ( $rows as $r ) { $out[ (int) $r->order_id ][ (int) $r->tax_rate_bp ] = array( (int) $r->g, (int) $r->t, (int) $r->q ); }
		return $out;
	}

	/**
	 * VAT split of a refund (positive values, stored negated on the movement): by the VAT rates of
	 * the refunded tickets, or of the whole order for refunds without tickets.
	 */
	public static function refund_vat( $order_id, array $ticket_ids, $amount, $tax ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$lines = array();
		$ticket_ids = array_filter( array_map( 'absint', $ticket_ids ) );
		if ( $ticket_ids ) {
			$unit = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,tax_rate_bp,quantity,line_total_minor,tax_minor FROM {$p}wpcap_order_items WHERE order_id=%d", absint( $order_id ) ) ) as $i ) {
				$q = max( 1, (int) $i->quantity );
				$unit[ (int) $i->ticket_type_id ] = array( (int) $i->tax_rate_bp, (int) $i->line_total_minor / $q, (int) $i->tax_minor / $q );
			}
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_id IN (" . implode( ',', $ticket_ids ) . ')', absint( $order_id ) ) ) as $type ) {
				if ( ! isset( $unit[ (int) $type ] ) ) { continue; }
				list( $rate, $g, $t ) = $unit[ (int) $type ];
				$lines[ $rate ][0] = ( $lines[ $rate ][0] ?? 0 ) + $g;
				$lines[ $rate ][1] = ( $lines[ $rate ][1] ?? 0 ) + $t;
			}
		}
		if ( ! $lines ) {
			foreach ( self::order_lines( array( $order_id ) )[ absint( $order_id ) ] ?? array() as $rate => $l ) { $lines[ $rate ] = array( $l[0], $l[1] ); }
		}
		$split = self::split_vat( $lines, $amount, $tax );
		$out = array();
		foreach ( $split as $rate => $v ) {
			$out[ $rate ] = array( 'gross' => -$v['gross'], 'tax' => -$v['tax'], 'net' => -$v['net'] );
		}
		return $out;
	}

	private static function add_vat( array &$target, $rate, array $v ) {
		$rate = (int) $rate;
		if ( ! isset( $target[ $rate ] ) ) { $target[ $rate ] = array( 'gross' => 0, 'tax' => 0, 'net' => 0 ); }
		foreach ( array( 'gross', 'tax', 'net' ) as $k ) { $target[ $rate ][ $k ] += (int) ( $v[ $k ] ?? 0 ); }
	}

	/**
	 * Totals of a shift (all amounts in minor units; refunds/payouts as positive amounts where
	 * named so, VAT tables signed).
	 *
	 * @return array currency, methods{m: sales_count, sales_gross, refund_count, refund_amount, net
	 *               (+ legacy count, gross, voided_count, voided)}, vat{rate: gross, tax, net} (net of
	 *               refunds), vat_sales, vat_refunds, counts{sales, stornos, refunds, comps,
	 *               comp_tickets, tickets, voided_sales}, cash{opening, sales, refunds, in, out,
	 *               corrections, expected}, card{expected}, movements[], net_total, plus the legacy
	 *               keys expected_cash, sales and voided.
	 */
	public static function compute( $shift_id ) {
		global $wpdb;
		$shift = Shifts::get( $shift_id );
		$methods = array();
		$blank = array( 'sales_count' => 0, 'sales_gross' => 0, 'refund_count' => 0, 'refund_amount' => 0, 'net' => 0 );
		foreach ( array_keys( Service::methods() ) as $m ) { $methods[ $m ] = $blank; }
		$counts = array( 'sales' => 0, 'stornos' => 0, 'refunds' => 0, 'comps' => 0, 'comp_tickets' => 0, 'tickets' => 0, 'voided_sales' => 0 );
		$vat_sales = array();
		$vat_refunds = array();
		$sales = $wpdb->get_results( $wpdb->prepare( 'SELECT sale_id,order_id,payment_method,total_minor,tax_minor,status FROM ' . self::t( 'sales' ) . " WHERE session_id=%d AND status IN ('completed','voided','storno_pending') ORDER BY sale_id", absint( $shift_id ) ) );
		$lines = self::order_lines( array_map( static function ( $s ) { return (int) $s->order_id; }, $sales ) );
		foreach ( $sales as $s ) {
			$m = $s->payment_method;
			if ( ! isset( $methods[ $m ] ) ) { $methods[ $m ] = $blank; }
			$methods[ $m ]['sales_count']++;
			$methods[ $m ]['sales_gross'] += (int) $s->total_minor;
			$counts['sales']++;
			$order_lines = $lines[ (int) $s->order_id ] ?? array();
			$qty = array_sum( array_map( static function ( $l ) { return (int) $l[2]; }, $order_lines ) );
			$counts['tickets'] += $qty;
			if ( 'free' === $m ) { $counts['comps']++; $counts['comp_tickets'] += $qty; }
			if ( 'voided' === $s->status ) { $counts['voided_sales']++; }
			$pairs = array();
			foreach ( $order_lines as $rate => $l ) { $pairs[ $rate ] = array( $l[0], $l[1] ); }
			foreach ( self::split_vat( $pairs, (int) $s->total_minor, (int) $s->tax_minor ) as $rate => $v ) { self::add_vat( $vat_sales, $rate, $v ); }
		}
		$cash = array( 'opening' => $shift ? (int) $shift->opening_float_minor : 0, 'sales' => 0, 'refunds' => 0, 'in' => 0, 'out' => 0, 'corrections' => 0, 'expected' => 0 );
		$movement_list = array();
		foreach ( Shifts::movements( $shift_id ) as $mv ) {
			$amount = (int) $mv->amount_minor;
			$movement_list[] = array( 'movement_id' => (int) $mv->movement_id, 'kind' => $mv->kind, 'method' => $mv->method, 'amount' => $amount, 'receipt' => (string) $mv->receipt_number, 'sale_id' => (int) $mv->sale_id, 'refund_id' => (int) $mv->refund_id, 'reason' => $mv->reason, 'override' => $mv->override_reason, 'user' => (int) $mv->created_by, 'at' => $mv->created_at );
			switch ( $mv->kind ) {
				case 'storno':
				case 'refund':
					$m = $mv->method;
					if ( ! isset( $methods[ $m ] ) ) { $methods[ $m ] = $blank; }
					$methods[ $m ]['refund_count']++;
					$methods[ $m ]['refund_amount'] += -$amount;
					$counts[ 'storno' === $mv->kind ? 'stornos' : 'refunds' ]++;
					foreach ( (array) json_decode( (string) $mv->vat, true ) as $rate => $v ) { self::add_vat( $vat_refunds, $rate, (array) $v ); }
					break;
				case 'cash_in':
					$cash['in'] += $amount;
					break;
				case 'cash_out':
					$cash['out'] += -$amount;
					break;
				case 'correction':
					$cash['corrections'] += $amount;
					break;
			}
		}
		$net_total = 0;
		foreach ( $methods as $m => $t ) {
			$methods[ $m ]['net'] = $t['sales_gross'] - $t['refund_amount'];
			// Legacy keys (session_totals() before registers).
			$methods[ $m ]['count'] = $t['sales_count'];
			$methods[ $m ]['gross'] = $t['sales_gross'];
			$methods[ $m ]['voided_count'] = $t['refund_count'];
			$methods[ $m ]['voided'] = $t['refund_amount'];
			$net_total += $methods[ $m ]['net'];
		}
		$vat = array();
		foreach ( $vat_sales as $rate => $v ) { self::add_vat( $vat, $rate, $v ); }
		foreach ( $vat_refunds as $rate => $v ) { self::add_vat( $vat, $rate, $v ); }
		ksort( $vat );
		ksort( $vat_sales );
		ksort( $vat_refunds );
		$cash['sales'] = $methods['cash']['sales_gross'];
		$cash['refunds'] = $methods['cash']['refund_amount'];
		$cash['expected'] = $cash['opening'] + $cash['sales'] - $cash['refunds'] + $cash['in'] - $cash['out'] + $cash['corrections'];
		return array(
			'currency' => $shift ? $shift->currency : 'EUR',
			'methods' => $methods,
			'vat' => $vat,
			'vat_sales' => $vat_sales,
			'vat_refunds' => $vat_refunds,
			'counts' => $counts,
			'cash' => $cash,
			'card' => array( 'expected' => $methods['card']['net'] ),
			'movements' => $movement_list,
			'net_total' => $net_total,
			'expected_cash' => $cash['expected'],
			'sales' => $counts['sales'],
			'voided' => $counts['voided_sales'],
		);
	}

	/* ------------------------------------------------------------------ storage */

	public static function hash( $row ) {
		$row = (array) $row;
		$parts = array();
		foreach ( self::HASHED as $col ) { $parts[] = $col . '=' . ( null === ( $row[ $col ] ?? null ) ? '' : (string) $row[ $col ] ); }
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	/**
	 * Inserts the Z report of a shift. Must run inside the closing transaction with the register row
	 * locked (FOR UPDATE) before any plain read of the transaction, which serialises the numbering
	 * per register; UNIQUE (register_id, z_number) and UNIQUE shift_id guard it in addition.
	 *
	 * @return int|\WP_Error Z id.
	 */
	public static function insert( $shift, array $totals, $reason, $closed_at, $source = 'close' ) {
		global $wpdb;
		// The register row lock serialises this per register; a plain read (snapshot taken after the
		// lock) avoids gap locks that could deadlock closes of other registers.
		$last = $wpdb->get_row( $wpdb->prepare( 'SELECT z_number,grand_total_minor,hash FROM ' . self::t( 'zreports' ) . ' WHERE register_id=%d ORDER BY z_number DESC LIMIT 1', (int) $shift->register_id ) );
		$net = (int) ( $totals['net_total'] ?? 0 );
		$row = array(
			'register_id' => (int) $shift->register_id,
			'z_number' => $last ? (int) $last->z_number + 1 : 1,
			'shift_id' => (int) $shift->session_id,
			'source' => $source,
			'currency' => (string) $shift->currency,
			'opened_at' => (string) $shift->opened_at,
			'closed_at' => (string) $closed_at,
			'opened_by' => (int) $shift->user_id,
			'closed_by' => 'migration' === $source ? (int) $shift->closed_by : get_current_user_id(),
			'net_total_minor' => $net,
			'grand_total_minor' => ( $last ? (int) $last->grand_total_minor : 0 ) + $net,
			'counted_cash_minor' => isset( $totals['cash']['counted'] ) ? (int) $totals['cash']['counted'] : null,
			'expected_cash_minor' => isset( $totals['cash']['expected'] ) ? (int) $totals['cash']['expected'] : null,
			'cash_difference_minor' => isset( $totals['cash']['difference'] ) ? (int) $totals['cash']['difference'] : null,
			'counted_card_minor' => isset( $totals['card']['counted'] ) ? (int) $totals['card']['counted'] : null,
			'expected_card_minor' => isset( $totals['card']['expected'] ) ? (int) $totals['card']['expected'] : null,
			'card_difference_minor' => isset( $totals['card']['difference'] ) ? (int) $totals['card']['difference'] : null,
			'difference_reason' => mb_substr( (string) $reason, 0, 255 ),
			'totals' => wp_json_encode( $totals ),
			'prev_hash' => $last ? (string) $last->hash : '',
			'created_at' => current_time( 'mysql', true ),
		);
		$row['hash'] = self::hash( $row );
		if ( false === $wpdb->insert( self::t( 'zreports' ), $row ) ) {
			return new \WP_Error( 'wpcap_pos_z', __( 'Der Z-Bericht konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Z report for a cash session closed before registers existed (migration): totals and counts as
	 * stored on the session, marked with source "migration". Caller holds the register lock.
	 */
	public static function insert_legacy( $session, $register_id ) {
		$stored = (array) json_decode( (string) $session->totals, true );
		$methods = array();
		foreach ( (array) ( $stored['methods'] ?? array() ) as $m => $t ) {
			$t = (array) $t;
			$methods[ $m ] = array( 'sales_count' => (int) ( $t['count'] ?? 0 ), 'sales_gross' => (int) ( $t['gross'] ?? 0 ), 'refund_count' => (int) ( $t['voided_count'] ?? 0 ), 'refund_amount' => (int) ( $t['voided'] ?? 0 ), 'net' => (int) ( $t['net'] ?? 0 ) );
		}
		$expected = null === $session->expected_cash_minor ? (int) ( $stored['expected_cash'] ?? 0 ) : (int) $session->expected_cash_minor;
		$totals = array(
			'legacy' => true,
			'currency' => $session->currency,
			'methods' => $methods,
			'vat' => array(),
			'counts' => array( 'sales' => (int) ( $stored['sales'] ?? 0 ), 'stornos' => (int) ( $stored['voided'] ?? 0 ), 'refunds' => 0, 'comps' => (int) ( $methods['free']['sales_count'] ?? 0 ), 'comp_tickets' => 0, 'tickets' => 0, 'voided_sales' => (int) ( $stored['voided'] ?? 0 ) ),
			'cash' => array( 'opening' => (int) $session->opening_float_minor, 'expected' => $expected, 'counted' => null === $session->closing_count_minor ? null : (int) $session->closing_count_minor, 'difference' => null === $session->difference_minor ? null : (int) $session->difference_minor ),
			'card' => array( 'expected' => (int) ( $methods['card']['net'] ?? 0 ), 'counted' => null, 'difference' => null ),
			'movements' => array(),
			'net_total' => (int) ( $stored['net_total'] ?? 0 ),
			'register' => array( 'register_id' => (int) $register_id ),
			'cashiers' => array( (int) $session->user_id ),
		);
		$shift = clone $session;
		$shift->register_id = (int) $register_id;
		return self::insert( $shift, $totals, (string) $session->closing_note, $session->closed_at ?: $session->opened_at, 'migration' );
	}

	/** Z report row with decoded totals (totals_data) or null. */
	public static function get( $z_id ) {
		global $wpdb;
		$z = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'zreports' ) . ' WHERE z_id=%d', absint( $z_id ) ) );
		if ( $z ) { $z->totals_data = (array) json_decode( (string) $z->totals, true ); }
		return $z;
	}

	public static function for_shift( $shift_id ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT z_id FROM ' . self::t( 'zreports' ) . ' WHERE shift_id=%d', absint( $shift_id ) ) );
		return $id ? self::get( $id ) : null;
	}

	/** Z reports, newest first (optionally of one register). */
	public static function list_reports( $register_id = 0, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$where = $register_id ? $wpdb->prepare( 'WHERE register_id=%d', absint( $register_id ) ) : '';
		return $wpdb->get_results( 'SELECT z_id,register_id,z_number,shift_id,source,currency,opened_at,closed_at,opened_by,closed_by,net_total_minor,grand_total_minor,cash_difference_minor,card_difference_minor FROM ' . self::t( 'zreports' ) . " $where ORDER BY closed_at DESC,z_id DESC" . $wpdb->prepare( ' LIMIT %d OFFSET %d', max( 1, (int) $limit ), max( 0, (int) $offset ) ) );
	}

	/**
	 * Verifies the Z chain of a register: numbers 1..n without gaps, each hash matches the row,
	 * each prev_hash the predecessor, and the grand total adds up.
	 *
	 * @return string[] Problems (empty = intact), keyed by z_id.
	 */
	public static function verify( $register_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'zreports' ) . ' WHERE register_id=%d ORDER BY z_number', absint( $register_id ) ) );
		$problems = array();
		$prev = null;
		$expected_number = 1;
		foreach ( $rows as $z ) {
			/* translators: %d: Z number */
			if ( (int) $z->z_number !== $expected_number ) { $problems[ (int) $z->z_id ] = sprintf( __( 'Lücke in der Z-Nummerierung vor Z %d.', 'wp-pass-captain' ), (int) $z->z_number ); }
			if ( ! hash_equals( self::hash( $z ), (string) $z->hash ) ) { $problems[ (int) $z->z_id ] = sprintf( __( 'Z %d wurde nachträglich verändert.', 'wp-pass-captain' ), (int) $z->z_number ); }
			if ( (string) $z->prev_hash !== ( $prev ? (string) $prev->hash : '' ) ) { $problems[ (int) $z->z_id ] = sprintf( __( 'Die Verkettung vor Z %d ist unterbrochen.', 'wp-pass-captain' ), (int) $z->z_number ); }
			if ( (int) $z->grand_total_minor !== ( $prev ? (int) $prev->grand_total_minor : 0 ) + (int) $z->net_total_minor ) { $problems[ (int) $z->z_id ] = sprintf( __( 'Der Grand Total von Z %d stimmt nicht.', 'wp-pass-captain' ), (int) $z->z_number ); }
			$prev = $z;
			$expected_number = (int) $z->z_number + 1;
		}
		return $problems;
	}

	/* ------------------------------------------------------------------ output */

	private static function money( $minor, $currency ) {
		return null === $minor ? '–' : Scope::money( (int) $minor, $currency );
	}

	/** Rows [section, label, value] of a Z report (also the CSV export). */
	public static function rows( $z ) {
		$t = $z->totals_data;
		$cur = $z->currency;
		$tz = Scope::site_timezone();
		$register = Registers::get( $z->register_id );
		$rows = array();
		$head = __( 'Kopf', 'wp-pass-captain' );
		$rows[] = array( $head, __( 'Kasse', 'wp-pass-captain' ), ( $register ? $register->name : '#' . $z->register_id ) . ' (ID ' . (int) $z->register_id . ')' );
		if ( $register && '' !== (string) $register->location ) { $rows[] = array( $head, __( 'Standort', 'wp-pass-captain' ), $register->location ); }
		$rows[] = array( $head, __( 'Z-Nummer', 'wp-pass-captain' ), (int) $z->z_number );
		$rows[] = array( $head, __( 'Schicht', 'wp-pass-captain' ), (int) $z->shift_id );
		$rows[] = array( $head, __( 'Geöffnet', 'wp-pass-captain' ), Scope::local( $z->opened_at, $tz, 'd.m.Y H:i:s' ) );
		$rows[] = array( $head, __( 'Geschlossen', 'wp-pass-captain' ), Scope::local( $z->closed_at, $tz, 'd.m.Y H:i:s' ) );
		$rows[] = array( $head, __( 'Kassierer', 'wp-pass-captain' ), implode( ', ', array_map( array( Service::class, 'user_name' ), (array) ( $t['cashiers'] ?? array( $z->opened_by ) ) ) ) );
		$rows[] = array( $head, __( 'Abgeschlossen von', 'wp-pass-captain' ), Service::user_name( $z->closed_by ) );
		if ( 'migration' === $z->source ) { $rows[] = array( $head, __( 'Herkunft', 'wp-pass-captain' ), __( 'Aus Kassensitzung vor Version mit Kassen übernommen', 'wp-pass-captain' ) ); }
		$pm = __( 'Zahlarten', 'wp-pass-captain' );
		foreach ( (array) ( $t['methods'] ?? array() ) as $m => $v ) {
			$rows[] = array( $pm, Service::method_label( $m ) . ' – ' . __( 'Verkäufe', 'wp-pass-captain' ), (int) ( $v['sales_count'] ?? 0 ) . ' / ' . self::money( $v['sales_gross'] ?? 0, $cur ) );
			$rows[] = array( $pm, Service::method_label( $m ) . ' – ' . __( 'Stornos/Erstattungen', 'wp-pass-captain' ), (int) ( $v['refund_count'] ?? 0 ) . ' / ' . self::money( -(int) ( $v['refund_amount'] ?? 0 ), $cur ) );
			$rows[] = array( $pm, Service::method_label( $m ) . ' – ' . __( 'Netto', 'wp-pass-captain' ), self::money( $v['net'] ?? 0, $cur ) );
		}
		$vs = __( 'USt-Sätze', 'wp-pass-captain' );
		foreach ( (array) ( $t['vat'] ?? array() ) as $rate => $v ) {
			$label = number_format_i18n( (int) $rate / 100, 2 ) . ' %';
			$rows[] = array( $vs, $label . ' ' . __( 'Netto', 'wp-pass-captain' ), self::money( $v['net'], $cur ) );
			$rows[] = array( $vs, $label . ' ' . __( 'USt.', 'wp-pass-captain' ), self::money( $v['tax'], $cur ) );
			$rows[] = array( $vs, $label . ' ' . __( 'Brutto', 'wp-pass-captain' ), self::money( $v['gross'], $cur ) );
		}
		$cs = __( 'Zähler', 'wp-pass-captain' );
		$c = (array) ( $t['counts'] ?? array() );
		$rows[] = array( $cs, __( 'Verkäufe', 'wp-pass-captain' ), (int) ( $c['sales'] ?? 0 ) );
		$rows[] = array( $cs, __( 'Stornos', 'wp-pass-captain' ), (int) ( $c['stornos'] ?? 0 ) );
		$rows[] = array( $cs, __( 'Erstattungen', 'wp-pass-captain' ), (int) ( $c['refunds'] ?? 0 ) );
		$rows[] = array( $cs, __( 'Freikarten (Verkäufe/Tickets)', 'wp-pass-captain' ), (int) ( $c['comps'] ?? 0 ) . ' / ' . (int) ( $c['comp_tickets'] ?? 0 ) );
		$rows[] = array( $cs, __( 'Tickets', 'wp-pass-captain' ), (int) ( $c['tickets'] ?? 0 ) );
		$cash = (array) ( $t['cash'] ?? array() );
		$bs = __( 'Bargeld', 'wp-pass-captain' );
		foreach ( array( 'opening' => __( 'Anfangsbestand (Wechselgeld)', 'wp-pass-captain' ), 'sales' => __( 'Barumsatz', 'wp-pass-captain' ), 'refunds' => __( 'Barauszahlungen (Storno/Erstattung)', 'wp-pass-captain' ), 'in' => __( 'Einlagen', 'wp-pass-captain' ), 'out' => __( 'Entnahmen', 'wp-pass-captain' ), 'corrections' => __( 'Korrekturen', 'wp-pass-captain' ), 'expected' => __( 'Soll-Bestand', 'wp-pass-captain' ), 'counted' => __( 'Ist-Bestand (gezählt)', 'wp-pass-captain' ), 'difference' => __( 'Kassendifferenz', 'wp-pass-captain' ), 'handover' => __( 'Übergeben an nächste Schicht', 'wp-pass-captain' ), 'to_safe' => __( 'Abgeschöpft (Tresor/Bank)', 'wp-pass-captain' ) ) as $k => $label ) {
			if ( array_key_exists( $k, $cash ) ) { $rows[] = array( $bs, $label, self::money( $cash[ $k ], $cur ) ); }
		}
		foreach ( (array) ( $cash['denominations'] ?? array() ) as $value => $qty ) {
			$rows[] = array( $bs, sprintf( /* translators: %s: denomination */ __( 'Stückelung %s', 'wp-pass-captain' ), Cash::label( $value ) ), (int) $qty );
		}
		$card = (array) ( $t['card'] ?? array() );
		$ks = __( 'Kartenterminal', 'wp-pass-captain' );
		$rows[] = array( $ks, __( 'Soll (Kartenumsatz)', 'wp-pass-captain' ), self::money( $card['expected'] ?? 0, $cur ) );
		$rows[] = array( $ks, __( 'Terminal-Summe', 'wp-pass-captain' ), self::money( $card['counted'] ?? null, $cur ) );
		$rows[] = array( $ks, __( 'Differenz', 'wp-pass-captain' ), self::money( $card['difference'] ?? null, $cur ) );
		if ( '' !== (string) $z->difference_reason ) { $rows[] = array( $bs, __( 'Grund der Differenz', 'wp-pass-captain' ), $z->difference_reason ); }
		if ( ! empty( $t['blind_count'] ) ) { $rows[] = array( $bs, __( 'Blindzählung', 'wp-pass-captain' ), __( 'ja', 'wp-pass-captain' ) ); }
		foreach ( (array) ( $t['count_history'] ?? array() ) as $n => $c ) {
			/* translators: 1: number of the count, 2: user, 3: counted cash, 4: cash difference */
			$rows[] = array( $bs, sprintf( __( 'Zählung %1$d (%2$s)', 'wp-pass-captain' ), $n + 1, Service::user_name( (int) $c['counted_by'] ) ), sprintf( __( 'Ist %1$s, Differenz %2$s', 'wp-pass-captain' ), self::money( $c['cash'], $cur ), self::money( $c['cash_difference'], $cur ) ) );
		}
		if ( ! empty( $t['handover'] ) ) { $rows[] = array( $bs, __( 'Übergabe an', 'wp-pass-captain' ), Service::user_name( $t['handover']['to_user'] ) ); }
		$ms = __( 'Buchungen', 'wp-pass-captain' );
		$kinds = self::kind_labels();
		foreach ( (array) ( $t['movements'] ?? array() ) as $mv ) {
			$rows[] = array( $ms, ( $kinds[ $mv['kind'] ] ?? $mv['kind'] ) . ( '' !== $mv['receipt'] ? ' ' . $mv['receipt'] : '' ) . ' (' . Service::method_label( $mv['method'] ) . '): ' . $mv['reason'] . ( '' !== (string) $mv['override'] ? ' [' . __( 'Override:', 'wp-pass-captain' ) . ' ' . $mv['override'] . ']' : '' ), self::money( $mv['amount'], $cur ) );
		}
		$gs = __( 'Summen', 'wp-pass-captain' );
		$rows[] = array( $gs, __( 'Nettoumsatz dieser Schicht', 'wp-pass-captain' ), self::money( $z->net_total_minor, $cur ) );
		$rows[] = array( $gs, __( 'Grand Total (kumuliert)', 'wp-pass-captain' ), self::money( $z->grand_total_minor, $cur ) );
		$rows[] = array( $gs, __( 'Prüfsumme', 'wp-pass-captain' ), $z->hash );
		return $rows;
	}

	public static function kind_labels() {
		return array(
			'cash_in' => __( 'Einlage', 'wp-pass-captain' ),
			'cash_out' => __( 'Entnahme', 'wp-pass-captain' ),
			'storno' => __( 'Storno', 'wp-pass-captain' ),
			'refund' => __( 'Erstattung', 'wp-pass-captain' ),
			'correction' => __( 'Korrektur', 'wp-pass-captain' ),
		);
	}

	/**
	 * 80 mm payout receipt (Storno-/Auszahlungsbeleg) of a refund/storno movement: own receipt
	 * number, reference to the sale receipt, refunded tickets, VAT per rate, method, register/shift.
	 */
	public static function payout_receipt_html( $mv ) {
		global $wpdb;
		$sale = Service::sale( $mv->sale_id );
		$cur = $mv->currency;
		$m = static function ( $minor ) use ( $cur ) { return number_format_i18n( $minor / 100, 2 ) . ' ' . $cur; };
		$items = array();
		$ids = array_filter( array_map( 'absint', (array) json_decode( (string) $mv->ticket_ids, true ) ) );
		if ( $ids ) {
			$items = $wpdb->get_results( "SELECT tt.name,COUNT(*) AS n FROM {$wpdb->prefix}wpcap_tickets t LEFT JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.ticket_id IN (" . implode( ',', $ids ) . ') GROUP BY tt.name ORDER BY tt.name' );
		}
		$h = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html( (string) $mv->receipt_number ) . '</title>'
			. '<style>@page{size:80mm auto;margin:0}body{width:80mm;margin:0;padding:3mm 4mm;font:12px/1.3 "DejaVu Sans Mono",monospace;color:#000}h1{font-size:15px;margin:0 0 2mm;text-align:center}p{margin:0 0 1.5mm}.c{text-align:center}table{width:100%;border-collapse:collapse}td.n{text-align:right;white-space:nowrap}.sep{border-top:1px dashed #000;margin:2mm 0}.big td{font-size:15px;font-weight:bold}.small{font-size:10px}@media print{.noprint{display:none}}</style></head><body onload="window.print()">';
		$h .= '<p class="c"><strong>' . esc_html( (string) Settings::get( 'seller_name' ) ) . '</strong><br>' . nl2br( esc_html( (string) Settings::get( 'seller_address' ) ) ) . '</p>';
		$h .= '<h1>' . esc_html( 'storno' === $mv->kind ? __( 'STORNOBELEG', 'wp-pass-captain' ) : __( 'AUSZAHLUNGSBELEG', 'wp-pass-captain' ) ) . '</h1>';
		$h .= '<p>' . esc_html( __( 'Beleg-Nr.:', 'wp-pass-captain' ) . ' ' . $mv->receipt_number ) . '<br>' . ( $sale ? esc_html( __( 'zu Beleg', 'wp-pass-captain' ) . ' ' . $sale->receipt_number ) . '<br>' : '' );
		$h .= esc_html( __( 'Datum:', 'wp-pass-captain' ) . ' ' . Scope::local( $mv->created_at, Scope::site_timezone(), 'd.m.Y H:i' ) ) . '<br>' . esc_html( __( 'Kasse/Schicht/Bediener:', 'wp-pass-captain' ) . ' ' . Service::register_label( $mv->shift_id ) . ' / ' . (int) $mv->shift_id . ' / ' . Service::user_name( $mv->created_by ) ) . '</p><div class="sep"></div><table>';
		foreach ( $items as $i ) { $h .= '<tr><td>' . esc_html( (int) $i->n . ' x ' . $i->name ) . '</td></tr>'; }
		$h .= '</table><table class="big"><tr><td>' . esc_html__( 'AUSZAHLUNG', 'wp-pass-captain' ) . '</td><td class="n">' . esc_html( $m( (int) $mv->amount_minor ) ) . '</td></tr></table><table class="small">';
		foreach ( (array) json_decode( (string) $mv->vat, true ) as $rate => $v ) {
			$h .= '<tr><td>' . esc_html( sprintf( /* translators: 1: rate, 2: net */ __( 'USt %1$s %%: Netto %2$s', 'wp-pass-captain' ), number_format_i18n( (int) $rate / 100, 2 ), $m( (int) $v['net'] ) ) ) . '</td><td class="n">' . esc_html( $m( (int) $v['tax'] ) ) . '</td></tr>';
		}
		$h .= '</table><div class="sep"></div><p>' . esc_html( __( 'Zahlart:', 'wp-pass-captain' ) . ' ' . Service::method_label( $mv->method ) ) . '<br>' . esc_html( $mv->reason ) . '</p>';
		$h .= '<p class="noprint c"><button onclick="window.print()">' . esc_html__( 'Drucken', 'wp-pass-captain' ) . '</button></p></body></html>';
		return $h;
	}

	/** CSV (semicolon, UTF-8 BOM) of a Z report. */
	public static function csv( $z ) {
		return Scope::csv( array_merge( array( array( __( 'Abschnitt', 'wp-pass-captain' ), __( 'Bezeichnung', 'wp-pass-captain' ), __( 'Wert', 'wp-pass-captain' ) ) ), self::rows( $z ) ) );
	}

	/** Printable HTML (A4; "Drucken" or "Als PDF speichern" in the browser). */
	public static function html( $z ) {
		$seller = (string) Settings::get( 'seller_name' );
		/* translators: 1: Z number, 2: register */
		$title = sprintf( __( 'Z-Bericht Nr. %1$d – %2$s', 'wp-pass-captain' ), (int) $z->z_number, ( $r = Registers::get( $z->register_id ) ) ? $r->name : '#' . (int) $z->register_id );
		$h = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. '<style>@page{size:A4;margin:15mm}body{font:13px/1.4 system-ui,sans-serif;color:#000;max-width:50rem;margin:1.5rem auto;padding:0 1rem}h1{font-size:20px;margin:0 0 .25rem}h2{font-size:15px;margin:1.2rem 0 .3rem;border-bottom:1px solid #000}table{width:100%;border-collapse:collapse}td{padding:.2rem .3rem;border-bottom:1px solid #ddd;vertical-align:top}td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.small{font-size:11px;color:#333;word-break:break-all}@media print{.noprint{display:none}}</style></head><body>';
		$h .= '<p>' . esc_html( $seller ) . '</p><h1>' . esc_html( $title ) . '</h1>';
		$section = null;
		foreach ( self::rows( $z ) as $row ) {
			if ( $row[0] !== $section ) {
				$h .= ( null === $section ? '' : '</table>' ) . '<h2>' . esc_html( $row[0] ) . '</h2><table>';
				$section = $row[0];
			}
			$h .= '<tr><td>' . esc_html( $row[1] ) . '</td><td class="n' . ( 64 === strlen( (string) $row[2] ) ? ' small' : '' ) . '">' . esc_html( (string) $row[2] ) . '</td></tr>';
		}
		$h .= '</table>';
		/** Extra HTML for the Z report (e.g. fiscal signature data); must be escaped by the callback. */
		$h .= (string) apply_filters( 'wpcap_boxoffice_zreport_html_extra', '', $z );
		$h .= '<p class="noprint"><button onclick="window.print()">' . esc_html__( 'Drucken / als PDF speichern', 'wp-pass-captain' ) . '</button></p></body></html>';
		return $h;
	}
}
