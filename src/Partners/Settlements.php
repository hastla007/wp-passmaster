<?php
/**
 * Partner settlements ("Abrechnungen") per calendar month (site timezone).
 *
 * A settlement for period P contains every not yet settled item of the partner's events up to the
 * end of P (and not before the partner's settlement start):
 *  - sale        order line of a paid (or later refunded) order created in P,
 *  - late_sale   order line of an order created before P that was not settled yet (e.g. paid late),
 *  - refund      completed refund of an order created in P,
 *  - correction  completed refund of an order created before P (e.g. after that period was finalized).
 * Complimentary orders (guest lists, Data::comp_providers()) are never settled; their tickets are only
 * counted in totals.comp_tickets.
 * Amounts are gross incl. VAT from the order line snapshots (discount allocated like checkout);
 * refunds are allocated to lines like the reports module. Commission = commission % of the net amount
 * per line, fixed fee = fee per ticket; on refunds both are reversed according to the partner's refund
 * policy. VAT on the platform fee (commission + fees) uses the partner's fee VAT rate.
 * Payout = gross after refunds − platform fee incl. VAT (negative = claim against the partner).
 *
 * Lifecycle draft → finalized → paid. Drafts are recomputed on demand and right before finalizing.
 * Finalizing assigns a gapless document number (Invoices::next_document_number, series "partner"),
 * snapshots the partner data and marks every line with a unique source key per partner, so an item
 * can never be settled twice. Finalized/paid settlements are never changed again.
 *
 * Document type follows the partner's sales model (SalesModel, docs/SALES-MODELS.md), fixed at finalizing:
 *  - agent    → "Provisionsrechnung" (platform invoices commission + fees, VAT or reverse charge) with an
 *               informative statement of the ticket revenue collected for the organizer,
 *  - own_name → combined document: part A "Gutschrift" (self-billing for the organizer's supply, own
 *               gapless series "credit-self"), part B invoice for the platform fees (series "partner"),
 *               part C netting/payout.
 * Settlements finalized before sales models existed keep document_type '' (legacy "Veranstalter-Abrechnung").
 */
namespace PassCaptain\Partners;

use PassCaptain\Audit;
use PassCaptain\Invoices;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Settlements {
	const STATUSES = array( 'draft', 'finalized', 'paid' );
	const SERIES = 'partner';
	const SELF_BILLING_SERIES = 'credit-self';
	const TYPE_SELF_BILLING = 'self_billing';
	const TYPE_COMMISSION = 'commission_invoice';
	const MANAGE_CAP = 'wpcap_view_finance';

	public static function status_labels() {
		return array( 'draft' => __( 'Entwurf', 'wp-pass-captain' ), 'finalized' => __( 'Abgeschlossen', 'wp-pass-captain' ), 'paid' => __( 'Ausgezahlt', 'wp-pass-captain' ) );
	}

	public static function kind_labels() {
		return array(
			'sale' => __( 'Verkauf', 'wp-pass-captain' ),
			'late_sale' => __( 'Nachtrag Verkauf (Vorperiode)', 'wp-pass-captain' ),
			'refund' => __( 'Erstattung', 'wp-pass-captain' ),
			'correction' => __( 'Korrektur Erstattung (Vorperiode)', 'wp-pass-captain' ),
		);
	}

	public static function valid_period( $period ) {
		return is_string( $period ) && preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $period );
	}

	/** UTC bounds [start, end) of a month in the site timezone. */
	public static function period_bounds( $period ) {
		$first = $period . '-01';
		$last = gmdate( 'Y-m-t', strtotime( $first . ' 00:00:00 UTC' ) );
		return Data::bounds( $first, $last, Scope::site_timezone() );
	}

	/** Rounds half away from zero (symmetric for refunds). */
	private static function pct( $amount, $bp ) {
		return (int) round( (int) $amount * (int) $bp / 10000 );
	}

	/* ------------------------------------------------------------------ Calculation */

	/**
	 * Computes the lines and totals of a period (pure read, nothing is stored).
	 *
	 * @param object $partner Partner row (terms are read from it).
	 * @return array ['lines' => array[], 'totals' => array]
	 */
	public static function compute( $partner, $period ) {
		global $wpdb;
		$p = $wpdb->prefix;
		list( $start, $end ) = self::period_bounds( $period );
		$tz = Scope::site_timezone();
		$lower = '';
		if ( ! empty( $partner->settlement_start ) && Data::valid_date( substr( (string) $partner->settlement_start, 0, 10 ) ) ) {
			$lower = Data::bounds( substr( (string) $partner->settlement_start, 0, 10 ), substr( (string) $partner->settlement_start, 0, 10 ), $tz )[0];
		}
		$events = Repository::event_ids( $partner->partner_id );
		$lines = array();
		$comp_tickets = 0;
		if ( $events ) {
			$in = implode( ',', array_map( 'absint', $events ) );
			$settled = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT source_key FROM {$p}wpcap_partner_settlement_lines WHERE partner_id=%d AND source_key IS NOT NULL", (int) $partner->partner_id ) ) );
			$line_sql = "SELECT oi.item_id,oi.order_id,oi.ticket_type_id," . \PassCaptain\OrderLines::paid_columns() . ",oi.tax_rate_bp,o.event_id,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id";
			$has_partner_item = "EXISTS (SELECT 1 FROM {$p}wpcap_order_items x INNER JOIN {$p}wpcap_ticket_types xt ON xt.ticket_type_id=x.ticket_type_id INNER JOIN {$p}wpcap_occurrences xo ON xo.occurrence_id=xt.occurrence_id WHERE x.order_id=ord.order_id AND xo.event_id IN ($in))";
			$lower_sql = '' !== $lower ? $wpdb->prepare( ' AND ord.created_at >= %s', $lower ) : '';

			// Sales.
			$orders = $wpdb->get_results( $wpdb->prepare( "SELECT ord.order_id,ord.created_at,ord.discount_minor,ord.payment_provider FROM {$p}wpcap_orders ord WHERE ord.payment_status IN ('paid','refunded') AND ord.currency=%s AND ord.created_at < %s{$lower_sql} AND $has_partner_item ORDER BY ord.order_id", $partner->currency, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list / prepared fragment.
			$by_order = self::lines_by_order( array_map( static function ( $o ) { return (int) $o->order_id; }, $orders ), $line_sql );
			$comp_providers = Data::comp_providers();
			foreach ( $orders as $order ) {
				$order_lines = $by_order[ (int) $order->order_id ] ?? array();
				if ( in_array( (string) $order->payment_provider, $comp_providers, true ) ) {
					// Guest list comps (GuestLists module): no sale, no commission/fee; counted for information
					// only (orders created in the period).
					if ( $order->created_at >= $start ) {
						foreach ( $order_lines as $line ) {
							if ( in_array( (int) $line->event_id, $events, true ) ) { $comp_tickets += (int) $line->quantity; }
						}
					}
					continue;
				}
				$gross_lines = Data::allocate_discount( $order_lines, (int) $order->discount_minor );
				$supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order->order_id );
				foreach ( $order_lines as $i => $line ) {
					if ( ! in_array( (int) $line->event_id, $events, true ) ) { continue; }
					// Lines moved in by a ticket exchange carry no paid amount (OrderLines): the money is on
					// the original line, a supplementary order or a refund.
					if ( \PassCaptain\OrderLines::is_moved_in( $line ) ) { continue; }
					$key = 'sale:' . (int) $line->item_id;
					if ( isset( $settled[ $key ] ) ) { continue; }
					$gross = (int) $gross_lines[ $i ];
					$tax = (int) $line->tax_minor;
					// An exchange supplement pays for a ticket already counted (and charged) on its original order.
					$tickets = $supplement ? 0 : (int) $line->quantity;
					$lines[] = self::line( $order->created_at >= $start ? 'sale' : 'late_sale', $key, $line, (int) $order->order_id, 0, $order->created_at, $order->created_at, $tickets, $gross, $tax, $partner, $tz );
				}
			}

			// Completed refunds.
			$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.updated_at,ord.created_at AS order_created_at FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE r.status='completed' AND ord.currency=%s AND r.updated_at < %s AND ord.created_at < %s{$lower_sql} AND $has_partner_item ORDER BY r.refund_id", $partner->currency, $end, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$refund_lines = self::lines_by_order( array_unique( array_map( static function ( $r ) { return (int) $r->order_id; }, $refunds ) ), $line_sql );
			foreach ( $refunds as $refund ) {
				$order_lines = $refund_lines[ (int) $refund->order_id ] ?? array();
				foreach ( self::allocate_refund( $refund, $order_lines ) as $i => $share ) {
					$line = $order_lines[ $i ];
					if ( ! in_array( (int) $line->event_id, $events, true ) ) { continue; }
					$key = 'refund:' . (int) $refund->refund_id . ':' . (int) $line->item_id;
					if ( isset( $settled[ $key ] ) ) { continue; }
					$kind = $refund->order_created_at >= $start ? 'refund' : 'correction';
					$lines[] = self::line( $kind, $key, $line, (int) $refund->order_id, (int) $refund->refund_id, $refund->updated_at, $refund->order_created_at, -$share['tickets'], -$share['amount'], -$share['tax'], $partner, $tz );
				}
			}
		}
		usort( $lines, static function ( $a, $b ) { return array( $a['occurred_at'], $a['item_key'] ) <=> array( $b['occurred_at'], $b['item_key'] ); } );
		$totals = self::totals( $lines, $partner );
		$totals['comp_tickets'] = $comp_tickets;
		return array( 'lines' => $lines, 'totals' => $totals );
	}

	private static function line( $kind, $key, $line, $order_id, $refund_id, $occurred_at, $order_created_at, $tickets, $gross, $tax, $partner, $tz ) {
		$net = $gross - $tax;
		$is_refund = $refund_id > 0;
		$policy = (string) $partner->refund_policy;
		$commission = self::pct( $net, (int) $partner->commission_bp );
		$fee = $tickets * (int) $partner->fee_per_ticket_minor;
		if ( $is_refund ) {
			if ( 'keep_all' === $policy ) { $commission = 0; }
			if ( 'reverse_all' !== $policy ) { $fee = 0; }
		}
		return array(
			'kind' => $kind,
			'item_key' => $key,
			'order_id' => $order_id,
			'refund_id' => $refund_id,
			'item_id' => (int) $line->item_id,
			'event_id' => (int) $line->event_id,
			'event_name' => (string) $line->event_name,
			'origin_period' => Scope::local( $order_created_at, $tz, 'Y-m' ),
			'occurred_at' => (string) $occurred_at,
			'tax_rate_bp' => (int) $line->tax_rate_bp,
			'tickets' => (int) $tickets,
			'gross_minor' => (int) $gross,
			'tax_minor' => (int) $tax,
			'net_minor' => (int) $net,
			'commission_minor' => $commission,
			'fee_minor' => $fee,
		);
	}

	/**
	 * Splits a refund over the order lines (refunded tickets weighted by unit price; amount-only
	 * refunds by line total). The last share receives the rounding remainder.
	 *
	 * @return array<int,array{tickets:int,amount:int,tax:int}> line index => share.
	 */
	public static function allocate_refund( $refund, array $lines ) {
		global $wpdb;
		if ( ! $lines ) { return array(); }
		$by_type = array();
		foreach ( $lines as $i => $line ) {
			if ( ! isset( $by_type[ (int) $line->ticket_type_id ] ) ) { $by_type[ (int) $line->ticket_type_id ] = $i; }
		}
		$weights = array();
		$ids = array_filter( array_map( 'absint', (array) json_decode( (string) $refund->ticket_ids, true ) ) );
		if ( $ids ) {
			foreach ( $wpdb->get_col( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $ids ) . ')' ) as $type_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
				$i = $by_type[ (int) $type_id ] ?? null;
				if ( null === $i ) { continue; }
				$unit = intdiv( (int) $lines[ $i ]->line_total_minor, max( 1, (int) $lines[ $i ]->quantity ) );
				$weights[ $i ] = array( ( $weights[ $i ][0] ?? 0 ) + 1, ( $weights[ $i ][1] ?? 0 ) + $unit );
			}
		}
		if ( ! $weights ) {
			foreach ( $lines as $i => $line ) { $weights[ $i ] = array( 0, (int) $line->line_total_minor ); }
		}
		$sum = max( 1, array_sum( array_column( $weights, 1 ) ) );
		$amount_left = (int) $refund->amount_minor;
		$tax_left = (int) $refund->tax_minor;
		$keys = array_keys( $weights );
		$out = array();
		foreach ( $keys as $n => $i ) {
			$last = $n === count( $keys ) - 1;
			$amount = $last ? $amount_left : intdiv( (int) $refund->amount_minor * $weights[ $i ][1], $sum );
			$tax = $last ? $tax_left : intdiv( (int) $refund->tax_minor * $weights[ $i ][1], $sum );
			$amount_left -= $amount;
			$tax_left -= $tax;
			$out[ $i ] = array( 'tickets' => (int) $weights[ $i ][0], 'amount' => $amount, 'tax' => $tax );
		}
		return $out;
	}

	private static function lines_by_order( array $order_ids, $line_sql ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_map( 'absint', $order_ids ), 500 ) as $chunk ) {
			foreach ( $wpdb->get_results( $line_sql . ' WHERE oi.order_id IN (' . implode( ',', $chunk ) . ') ORDER BY oi.order_id,oi.item_id' ) as $line ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
				$out[ (int) $line->order_id ][] = $line;
			}
		}
		return $out;
	}

	/** Totals of a line set under the partner's terms. */
	public static function totals( array $lines, $partner ) {
		$t = array( 'tickets_sold' => 0, 'tickets_refunded' => 0, 'sales_gross' => 0, 'sales_tax' => 0, 'refunded' => 0, 'refunded_tax' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0, 'corrections' => 0, 'commission' => 0, 'fee' => 0, 'rates' => array(), 'events' => array(), 'kinds' => array() );
		foreach ( $lines as $l ) {
			if ( 0 === $l['refund_id'] ) {
				$t['tickets_sold'] += $l['tickets'];
				$t['sales_gross'] += $l['gross_minor'];
				$t['sales_tax'] += $l['tax_minor'];
			} else {
				$t['tickets_refunded'] += -$l['tickets'];
				$t['refunded'] += -$l['gross_minor'];
				$t['refunded_tax'] += -$l['tax_minor'];
			}
			if ( in_array( $l['kind'], array( 'late_sale', 'correction' ), true ) ) { $t['corrections'] += $l['gross_minor']; }
			$t['gross'] += $l['gross_minor'];
			$t['tax'] += $l['tax_minor'];
			$t['net'] += $l['net_minor'];
			$t['commission'] += $l['commission_minor'];
			$t['fee'] += $l['fee_minor'];
			$rate = (string) $l['tax_rate_bp'];
			$t['rates'][ $rate ] = array(
				'gross' => ( $t['rates'][ $rate ]['gross'] ?? 0 ) + $l['gross_minor'],
				'tax' => ( $t['rates'][ $rate ]['tax'] ?? 0 ) + $l['tax_minor'],
				'net' => ( $t['rates'][ $rate ]['net'] ?? 0 ) + $l['net_minor'],
			);
			$ev = (string) $l['event_id'];
			$t['events'][ $ev ] = array(
				'name' => $l['event_name'],
				'tickets' => ( $t['events'][ $ev ]['tickets'] ?? 0 ) + $l['tickets'],
				'gross' => ( $t['events'][ $ev ]['gross'] ?? 0 ) + $l['gross_minor'],
				'net' => ( $t['events'][ $ev ]['net'] ?? 0 ) + $l['net_minor'],
				'commission' => ( $t['events'][ $ev ]['commission'] ?? 0 ) + $l['commission_minor'],
				'fee' => ( $t['events'][ $ev ]['fee'] ?? 0 ) + $l['fee_minor'],
			);
			$t['kinds'][ $l['kind'] ] = array(
				'tickets' => ( $t['kinds'][ $l['kind'] ]['tickets'] ?? 0 ) + $l['tickets'],
				'gross' => ( $t['kinds'][ $l['kind'] ]['gross'] ?? 0 ) + $l['gross_minor'],
				'tax' => ( $t['kinds'][ $l['kind'] ]['tax'] ?? 0 ) + $l['tax_minor'],
			);
		}
		ksort( $t['rates'], SORT_NUMERIC );
		$t['platform_fee_net'] = $t['commission'] + $t['fee'];
		// Sales model: reverse charge on the fee invoice, self-billing amounts for own_name organizers.
		$model = SalesModel::model_of( $partner );
		$platform = SalesModel::platform_seller();
		$partner_country = SalesModel::partner_country( $partner );
		$t['sales_model'] = $model;
		$t['fee_reverse_charge'] = SalesModel::reverse_charge( $platform['country'], $partner_country, (string) $partner->vat_id );
		$t['platform_fee_tax'] = $t['fee_reverse_charge'] ? 0 : self::pct( $t['platform_fee_net'], (int) $partner->fee_tax_rate_bp );
		$t['platform_fee_gross'] = $t['platform_fee_net'] + $t['platform_fee_tax'];
		if ( SalesModel::OWN_NAME === $model ) {
			$t['self_billing'] = self::self_billing_totals( $t['rates'], $partner, $partner_country, $platform );
			$t['payout'] = $t['self_billing']['gross'] - $t['platform_fee_gross'];
		} else {
			$t['payout'] = $t['gross'] - $t['platform_fee_gross'];
		}
		$t['terms'] = array( 'commission_bp' => (int) $partner->commission_bp, 'fee_per_ticket_minor' => (int) $partner->fee_per_ticket_minor, 'fee_tax_rate_bp' => (int) $partner->fee_tax_rate_bp, 'refund_policy' => (string) $partner->refund_policy, 'currency' => (string) $partner->currency );
		return $t;
	}

	/**
	 * Gutschrift amounts (organizer's supply to the platform): net ticket revenue per VAT rate plus the
	 * organizer's VAT at the same rate; no VAT for small businesses or under reverse charge (foreign EU
	 * organizer, platform owes the VAT).
	 */
	private static function self_billing_totals( array $rates, $partner, $partner_country, array $platform ) {
		$reverse_charge = SalesModel::reverse_charge( $partner_country, $platform['country'], $platform['vat_id'] );
		$small = ! empty( $partner->small_business );
		$out = array( 'net' => 0, 'tax' => 0, 'gross' => 0, 'rates' => array(), 'reverse_charge' => $reverse_charge, 'small_business' => $small );
		foreach ( $rates as $bp => $r ) {
			$tax = ( $small || $reverse_charge ) ? 0 : (int) $r['tax'];
			$out['rates'][ $bp ] = array( 'net' => (int) $r['net'], 'tax' => $tax, 'gross' => (int) $r['net'] + $tax );
			$out['net'] += (int) $r['net'];
			$out['tax'] += $tax;
		}
		$out['gross'] = $out['net'] + $out['tax'];
		return $out;
	}

	/* ------------------------------------------------------------------ Persistence */

	public static function get( $settlement_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_partner_settlements WHERE settlement_id=%d", absint( $settlement_id ) ) );
	}

	public static function lines( $settlement_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_partner_settlement_lines WHERE settlement_id=%d ORDER BY occurred_at,line_id", absint( $settlement_id ) ) );
	}

	/**
	 * Settlements, optionally limited to partners and statuses (newest period first).
	 *
	 * @param int[]|null    $partner_ids Null = all partners.
	 * @param string[]|null $statuses    Null = all statuses.
	 */
	public static function query( $partner_ids = null, $statuses = null, $limit = 200 ) {
		global $wpdb;
		$where = '1=1';
		if ( null !== $partner_ids ) {
			$partner_ids = array_filter( array_map( 'absint', (array) $partner_ids ) );
			if ( ! $partner_ids ) { return array(); }
			$where .= ' AND s.partner_id IN (' . implode( ',', $partner_ids ) . ')';
		}
		if ( null !== $statuses ) {
			$statuses = array_values( array_intersect( self::STATUSES, (array) $statuses ) );
			if ( ! $statuses ) { return array(); }
			$where .= " AND s.status IN ('" . implode( "','", $statuses ) . "')";
		}
		return $wpdb->get_results( "SELECT s.*,pa.name AS partner_name FROM {$wpdb->prefix}wpcap_partner_settlements s INNER JOIN {$wpdb->prefix}wpcap_partners pa ON pa.partner_id=s.partner_id WHERE $where ORDER BY s.period DESC, s.settlement_id DESC LIMIT " . max( 1, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelisted values.
	}

	/** Creates a draft for a partner and month. */
	public static function create_draft( $partner_id, $period ) {
		global $wpdb;
		$partner = Repository::get( $partner_id );
		if ( ! $partner ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Veranstalter nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! self::valid_period( $period ) ) { return new \WP_Error( 'wpcap_settlement_period', __( 'Ungültiger Abrechnungszeitraum (JJJJ-MM).', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		list( $start, $end ) = self::period_bounds( $period );
		if ( $start > current_time( 'mysql', true ) ) { return new \WP_Error( 'wpcap_settlement_future', __( 'Für zukünftige Zeiträume kann keine Abrechnung erstellt werden.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$latest = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(period) FROM {$wpdb->prefix}wpcap_partner_settlements WHERE partner_id=%d AND status IN ('finalized','paid')", (int) $partner->partner_id ) );
		if ( '' !== $latest && $period <= $latest ) {
			/* translators: %s: period */
			return new \WP_Error( 'wpcap_settlement_order', sprintf( __( 'Es gibt bereits eine abgeschlossene Abrechnung bis %s. Spätere Änderungen werden in der nächsten Periode als Korrektur abgerechnet.', 'wp-pass-captain' ), $latest ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$saved = $wpdb->insert( "{$wpdb->prefix}wpcap_partner_settlements", array(
			'partner_id' => (int) $partner->partner_id,
			'period' => $period,
			'period_start' => $start,
			'period_end' => $end,
			'status' => 'draft',
			'currency' => $partner->currency,
			'totals' => '{}',
			'created_by' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( false === $saved ) { return new \WP_Error( 'wpcap_settlement_exists', __( 'Für diesen Veranstalter und Zeitraum gibt es bereits eine Abrechnung.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$id = (int) $wpdb->insert_id;
		$r = self::recompute( $id );
		if ( is_wp_error( $r ) ) { return $r; }
		Audit::record( 'partner_settlement_created', 'partner_settlement', $id, array( 'partner_id' => (int) $partner->partner_id, 'period' => $period ) );
		return $id;
	}

	private static function immutable_error() {
		return new \WP_Error( 'wpcap_settlement_immutable', __( 'Abgeschlossene Abrechnungen können nicht mehr geändert werden. Spätere Änderungen werden in der nächsten Periode als Korrektur abgerechnet.', 'wp-pass-captain' ), array( 'status' => 409 ) );
	}

	/** Recomputes a draft (lines and totals). */
	public static function recompute( $settlement_id ) {
		$s = self::get( $settlement_id );
		if ( ! $s ) { return new \WP_Error( 'wpcap_settlement_missing', __( 'Abrechnung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( 'draft' !== $s->status ) { return self::immutable_error(); }
		$partner = Repository::get( $s->partner_id );
		$result = self::compute( $partner, $s->period );
		self::store( $s, $result, $partner );
		return $result;
	}

	/** Replaces the lines and totals of a draft. */
	private static function store( $s, array $result, $partner ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_partner_settlement_lines WHERE settlement_id=%d", (int) $s->settlement_id ) );
		foreach ( $result['lines'] as $line ) {
			$wpdb->insert( "{$p}wpcap_partner_settlement_lines", array_merge( $line, array( 'settlement_id' => (int) $s->settlement_id, 'partner_id' => (int) $s->partner_id, 'event_name' => substr( $line['event_name'], 0, 190 ) ) ) );
		}
		$t = $result['totals'];
		$model = (string) ( $t['sales_model'] ?? SalesModel::OWN_NAME );
		$wpdb->update( "{$p}wpcap_partner_settlements", array(
			'currency' => $partner->currency,
			'sales_model' => $model,
			'document_type' => SalesModel::AGENT === $model ? self::TYPE_COMMISSION : self::TYPE_SELF_BILLING,
			'tickets_sold' => $t['tickets_sold'],
			'tickets_refunded' => $t['tickets_refunded'],
			'gross_minor' => $t['gross'],
			'tax_minor' => $t['tax'],
			'net_minor' => $t['net'],
			'refunded_minor' => $t['refunded'],
			'corrections_minor' => $t['corrections'],
			'commission_minor' => $t['commission'],
			'fee_minor' => $t['fee'],
			'platform_fee_net_minor' => $t['platform_fee_net'],
			'platform_fee_tax_minor' => $t['platform_fee_tax'],
			'platform_fee_gross_minor' => $t['platform_fee_gross'],
			'payout_minor' => $t['payout'],
			'totals' => wp_json_encode( $t ),
			'updated_at' => current_time( 'mysql', true ),
		), array( 'settlement_id' => (int) $s->settlement_id ) );
	}

	/**
	 * Finalizes a draft: recomputes it, assigns the document number and locks the lines.
	 */
	public static function finalize( $settlement_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$settlement_id = absint( $settlement_id );
		$wpdb->query( 'START TRANSACTION' );
		$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_partner_settlements WHERE settlement_id=%d FOR UPDATE", $settlement_id ) );
		$fail = static function ( $error ) use ( $wpdb ) { $wpdb->query( 'ROLLBACK' ); return $error; };
		if ( ! $s ) { return $fail( new \WP_Error( 'wpcap_settlement_missing', __( 'Abrechnung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ) ); }
		if ( 'draft' !== $s->status ) { return $fail( self::immutable_error() ); }
		if ( $s->period_end > current_time( 'mysql', true ) ) { return $fail( new \WP_Error( 'wpcap_settlement_open_period', __( 'Der Abrechnungszeitraum ist noch nicht beendet.', 'wp-pass-captain' ), array( 'status' => 409 ) ) ); }
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_partner_settlements WHERE partner_id=%d AND status='draft' AND period < %s LIMIT 1", (int) $s->partner_id, $s->period ) ) ) {
			return $fail( new \WP_Error( 'wpcap_settlement_order', __( 'Bitte zuerst die Entwürfe früherer Zeiträume abschließen oder löschen.', 'wp-pass-captain' ), array( 'status' => 409 ) ) );
		}
		$partner = Repository::get( $s->partner_id );
		$model = SalesModel::model_of( $partner );
		// A Gutschrift names the organizer as supplier: address and VAT ID or tax number are mandatory.
		$missing = SalesModel::OWN_NAME === $model ? SalesModel::missing_supplier_fields( $partner ) : array();
		if ( $missing ) {
			/* translators: %s: missing fields */
			return $fail( new \WP_Error( 'wpcap_settlement_supplier_incomplete', sprintf( __( 'Gutschrift nicht möglich – beim Veranstalter fehlen: %s.', 'wp-pass-captain' ), implode( ', ', $missing ) ), array( 'status' => 409 ) ) );
		}
		self::store( $s, self::compute( $partner, $s->period ), $partner );
		$settings = (array) Settings::get( 'partners' );
		$prefix = (string) ( $settings['settlement_prefix'] ?? 'PA' );
		$number = Invoices::next_document_number( self::SERIES, '' !== $prefix ? $prefix : 'PA', true );
		if ( is_wp_error( $number ) ) { return $fail( $number ); }
		$self_billing_number = null;
		if ( SalesModel::OWN_NAME === $model ) {
			$sb_prefix = (string) ( $settings['self_billing_prefix'] ?? 'GU' );
			$self_billing_number = Invoices::next_document_number( self::SELF_BILLING_SERIES, '' !== $sb_prefix ? $sb_prefix : 'GU', true );
			if ( is_wp_error( $self_billing_number ) ) { return $fail( $self_billing_number ); }
		}
		// Locks every line: the unique (partner_id, source_key) index rejects items settled elsewhere.
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_settlement_lines SET source_key=item_key WHERE settlement_id=%d", $settlement_id ) ) ) {
			return $fail( new \WP_Error( 'wpcap_settlement_conflict', __( 'Einzelne Positionen wurden bereits in einer anderen Abrechnung abgerechnet. Bitte neu berechnen.', 'wp-pass-captain' ), array( 'status' => 409 ) ) );
		}
		$snapshot = array(
			'partner' => array_intersect_key( (array) $partner, array_flip( array( 'partner_id', 'name', 'contact_name', 'contact_email', 'billing_address', 'vat_id', 'payout_details', 'currency', 'sales_model', 'legal_name', 'country', 'tax_number', 'vat_id_checked_at', 'small_business', 'small_business_note' ) ) ),
			'seller' => SalesModel::platform_seller(),
			'supplier' => SalesModel::organizer_seller( $partner ),
			'sales_model' => $model,
		);
		$now = current_time( 'mysql', true );
		$sb_sql = null === $self_billing_number ? 'NULL' : $wpdb->prepare( '%s', $self_billing_number );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_settlements SET status='finalized', document_number=%s, self_billing_number=$sb_sql, partner_snapshot=%s, finalized_by=%d, finalized_at=%s, updated_at=%s WHERE settlement_id=%d AND status='draft'", $number, wp_json_encode( $snapshot ), get_current_user_id(), $now, $now, $settlement_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared fragment.
		if ( 1 !== (int) $updated ) { return $fail( new \WP_Error( 'wpcap_settlement_save', __( 'Abrechnung konnte nicht abgeschlossen werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ) ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'partner_settlement_finalized', 'partner_settlement', $settlement_id, array( 'partner_id' => (int) $s->partner_id, 'period' => $s->period, 'number' => $number, 'self_billing_number' => $self_billing_number, 'sales_model' => $model ) );
		return self::get( $settlement_id );
	}

	/** Marks a finalized settlement as paid out. */
	public static function mark_paid( $settlement_id, $reference = '' ) {
		global $wpdb;
		$s = self::get( $settlement_id );
		if ( ! $s ) { return new \WP_Error( 'wpcap_settlement_missing', __( 'Abrechnung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( 'finalized' !== $s->status ) { return new \WP_Error( 'wpcap_settlement_state', __( 'Nur abgeschlossene Abrechnungen können als ausgezahlt markiert werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_partner_settlements SET status='paid', paid_at=%s, payment_reference=%s, updated_at=%s WHERE settlement_id=%d AND status='finalized'", $now, substr( sanitize_text_field( (string) $reference ), 0, 190 ), $now, (int) $s->settlement_id ) );
		Audit::record( 'partner_settlement_paid', 'partner_settlement', (int) $s->settlement_id, array( 'partner_id' => (int) $s->partner_id, 'reference' => substr( sanitize_text_field( (string) $reference ), 0, 190 ) ) );
		return self::get( $settlement_id );
	}

	/** Deletes a draft. */
	public static function delete_draft( $settlement_id ) {
		global $wpdb;
		$s = self::get( $settlement_id );
		if ( ! $s ) { return new \WP_Error( 'wpcap_settlement_missing', __( 'Abrechnung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( 'draft' !== $s->status ) { return self::immutable_error(); }
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_partner_settlement_lines WHERE settlement_id=%d", (int) $s->settlement_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_partner_settlements WHERE settlement_id=%d AND status='draft'", (int) $s->settlement_id ) );
		Audit::record( 'partner_settlement_deleted', 'partner_settlement', (int) $s->settlement_id, array( 'partner_id' => (int) $s->partner_id, 'period' => $s->period ) );
		return array( 'deleted' => (int) $s->settlement_id );
	}

	/* ------------------------------------------------------------------ Access */

	public static function can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( self::MANAGE_CAP );
	}

	/** Finance sees everything; linked partner users see their own finalized/paid settlements. */
	public static function can_view( $settlement ) {
		if ( ! $settlement ) { return false; }
		if ( self::can_manage() ) { return true; }
		return 'draft' !== $settlement->status && in_array( (int) $settlement->partner_id, Repository::partners_of_user( get_current_user_id() ), true );
	}

	/** Settlements visible to the current user. */
	public static function visible() {
		if ( self::can_manage() ) { return self::query(); }
		return self::query( Repository::partners_of_user( get_current_user_id() ), array( 'finalized', 'paid' ) );
	}

	/* ------------------------------------------------------------------ Output */

	public static function decoded_totals( $s ) {
		$t = json_decode( (string) $s->totals, true );
		return is_array( $t ) ? $t : array();
	}

	/** Document type: stored type, derived from the draft totals, or '' for legacy settlements. */
	public static function document_type( $s ) {
		if ( ! empty( $s->document_type ) ) { return (string) $s->document_type; }
		if ( 'draft' !== $s->status ) { return ''; }
		$model = (string) ( self::decoded_totals( $s )['sales_model'] ?? SalesModel::model_of( Repository::get( $s->partner_id ) ) );
		return SalesModel::AGENT === $model ? self::TYPE_COMMISSION : self::TYPE_SELF_BILLING;
	}

	/** Document title incl. numbers ("Provisionsrechnung PA-…", "Gutschrift GU-… und Rechnung PA-…"). */
	public static function document_title( $s ) {
		$type = self::document_type( $s );
		$draft = 'draft' === $s->status;
		if ( self::TYPE_COMMISSION === $type ) {
			return $draft ? __( 'Entwurf Provisionsrechnung', 'wp-pass-captain' ) : __( 'Provisionsrechnung', 'wp-pass-captain' ) . ' ' . $s->document_number;
		}
		if ( self::TYPE_SELF_BILLING === $type ) {
			/* translators: 1: Gutschrift number, 2: invoice number */
			return $draft ? __( 'Entwurf Gutschrift und Rechnung über Plattformgebühren', 'wp-pass-captain' ) : sprintf( __( 'Gutschrift %1$s und Rechnung %2$s', 'wp-pass-captain' ), $s->self_billing_number, $s->document_number );
		}
		return $draft ? __( 'Abrechnungsentwurf', 'wp-pass-captain' ) : __( 'Veranstalter-Abrechnung', 'wp-pass-captain' ) . ' ' . $s->document_number;
	}

	/** Short document type label for lists. */
	public static function document_label( $s ) {
		$type = self::document_type( $s );
		if ( self::TYPE_COMMISSION === $type ) { return __( 'Provisionsrechnung (Vermittler)', 'wp-pass-captain' ); }
		if ( self::TYPE_SELF_BILLING === $type ) { return __( 'Gutschrift + Gebührenrechnung (Eigenhändler)', 'wp-pass-captain' ); }
		return __( 'Veranstalter-Abrechnung', 'wp-pass-captain' );
	}

	private static function m( $minor ) {
		return number_format( (int) $minor / 100, 2, '.', '' );
	}

	/** CSV rows (amounts as decimals, lines included). */
	public static function csv_rows( $s ) {
		$t = self::decoded_totals( $s );
		$snapshot = (array) json_decode( (string) $s->partner_snapshot, true );
		$partner_name = (string) ( $snapshot['partner']['name'] ?? ( Repository::get( $s->partner_id )->name ?? '' ) );
		$kinds = self::kind_labels();
		$rows = array(
			array( self::document_label( $s ), (string) $s->document_number, $partner_name, $s->period, self::status_labels()[ $s->status ] ?? $s->status, $s->currency, (string) ( $s->self_billing_number ?? '' ) ),
			array(),
			array( __( 'Art', 'wp-pass-captain' ), __( 'Datum (UTC)', 'wp-pass-captain' ), __( 'Event', 'wp-pass-captain' ), __( 'Bestellung', 'wp-pass-captain' ), __( 'Erstattung', 'wp-pass-captain' ), __( 'Ursprungsperiode', 'wp-pass-captain' ), __( 'USt-Satz %', 'wp-pass-captain' ), __( 'Tickets', 'wp-pass-captain' ), __( 'Brutto', 'wp-pass-captain' ), __( 'USt', 'wp-pass-captain' ), __( 'Netto', 'wp-pass-captain' ), __( 'Provision', 'wp-pass-captain' ), __( 'Ticketgebühr', 'wp-pass-captain' ) ),
		);
		foreach ( self::lines( $s->settlement_id ) as $l ) {
			$rows[] = array( $kinds[ $l->kind ] ?? $l->kind, $l->occurred_at, $l->event_name, (int) $l->order_id, (int) $l->refund_id ?: '', $l->origin_period, number_format( (int) $l->tax_rate_bp / 100, 2, '.', '' ), (int) $l->tickets, self::m( $l->gross_minor ), self::m( $l->tax_minor ), self::m( $l->net_minor ), self::m( $l->commission_minor ), self::m( $l->fee_minor ) );
		}
		$rows[] = array();
		$rows[] = array( __( 'USt-Satz %', 'wp-pass-captain' ), __( 'Brutto', 'wp-pass-captain' ), __( 'USt', 'wp-pass-captain' ), __( 'Netto', 'wp-pass-captain' ) );
		foreach ( (array) ( $t['rates'] ?? array() ) as $bp => $r ) {
			$rows[] = array( number_format( (int) $bp / 100, 2, '.', '' ), self::m( $r['gross'] ), self::m( $r['tax'] ), self::m( $r['net'] ) );
		}
		$rows[] = array();
		$rows[] = array( __( 'Verkaufte Tickets', 'wp-pass-captain' ), (int) $s->tickets_sold );
		$rows[] = array( __( 'Erstattete Tickets', 'wp-pass-captain' ), (int) $s->tickets_refunded );
		if ( ! empty( $t['comp_tickets'] ) ) { $rows[] = array( __( 'Freikarten (nicht abgerechnet)', 'wp-pass-captain' ), (int) $t['comp_tickets'] ); }
		foreach ( self::summary( $s ) as $label => $minor ) { $rows[] = array( $label, self::m( $minor ) ); }
		return $rows;
	}

	/** Summary amounts: label => minor amount. */
	public static function summary( $s ) {
		$t = self::decoded_totals( $s );
		$extra = array();
		if ( self::TYPE_SELF_BILLING === self::document_type( $s ) && isset( $t['self_billing'] ) ) {
			$extra = array(
				__( 'Gutschrift netto', 'wp-pass-captain' ) => (int) $t['self_billing']['net'],
				__( 'Gutschrift USt', 'wp-pass-captain' ) => (int) $t['self_billing']['tax'],
				__( 'Gutschrift brutto', 'wp-pass-captain' ) => (int) $t['self_billing']['gross'],
			);
		}
		return $extra + array(
			__( 'Erstattungen brutto', 'wp-pass-captain' ) => (int) $s->refunded_minor,
			__( 'davon Korrekturen/Nachträge aus Vorperioden', 'wp-pass-captain' ) => (int) $s->corrections_minor,
			__( 'Ticketumsatz brutto nach Erstattungen', 'wp-pass-captain' ) => (int) $s->gross_minor,
			__( 'darin USt', 'wp-pass-captain' ) => (int) $s->tax_minor,
			__( 'Ticketumsatz netto', 'wp-pass-captain' ) => (int) $s->net_minor,
			__( 'Provision (netto)', 'wp-pass-captain' ) => (int) $s->commission_minor,
			__( 'Ticketgebühren (netto)', 'wp-pass-captain' ) => (int) $s->fee_minor,
			__( 'Plattformgebühr netto', 'wp-pass-captain' ) => (int) $s->platform_fee_net_minor,
			__( 'USt auf Plattformgebühr', 'wp-pass-captain' ) => (int) $s->platform_fee_tax_minor,
			__( 'Plattformgebühr brutto', 'wp-pass-captain' ) => (int) $s->platform_fee_gross_minor,
			__( 'Auszahlungsbetrag', 'wp-pass-captain' ) => (int) $s->payout_minor,
		);
	}

	/** Printable HTML document (escaped; drafts are marked as such). Layout depends on the document type. */
	public static function render_html( $s ) {
		$t = self::decoded_totals( $s );
		$snapshot = (array) json_decode( (string) $s->partner_snapshot, true );
		$current = Repository::get( $s->partner_id );
		$partner = (array) ( $snapshot['partner'] ?? (array) $current );
		$seller = (array) ( $snapshot['seller'] ?? SalesModel::platform_seller() );
		$supplier = (array) ( $snapshot['supplier'] ?? ( $current ? SalesModel::organizer_seller( $current ) : array() ) );
		$type = self::document_type( $s );
		$cur = (string) $s->currency;
		$money = static function ( $minor ) use ( $cur ) { return Scope::money( $minor, $cur ); };
		$pct = static function ( $bp ) { return number_format_i18n( (int) $bp / 100, 2 ) . ' %'; };
		$terms = (array) ( $t['terms'] ?? array() );
		$kinds = self::kind_labels();
		$title = self::document_title( $s );
		$date = Scope::local( $s->finalized_at ?: $s->updated_at, Scope::site_timezone(), 'd.m.Y' );
		$block = static function ( array $lines, $heading = '' ) {
			$first = array_shift( $lines );
			return '<div class="addr">' . ( '' !== $heading ? '<small>' . esc_html( $heading ) . '</small>' . "\n" : '' ) . '<strong>' . esc_html( (string) $first ) . '</strong>' . ( $lines ? "\n" . esc_html( implode( "\n", $lines ) ) : '' ) . '</div>';
		};
		$h = '<!doctype html><html lang="' . esc_attr( determine_locale() ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>';
		$h .= '<style>body{font:14px/1.45 system-ui,sans-serif;max-width:56rem;margin:2rem auto;padding:0 1rem;color:#1d2327}table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border-bottom:1px solid #dcdcde;padding:.35rem;text-align:left}td.n,th.n{text-align:right}.addr{white-space:pre-line}.cols{display:flex;gap:2rem;justify-content:space-between;flex-wrap:wrap}.draft{color:#b32d2e;font-weight:bold;text-transform:uppercase}section.part{border-top:2px solid #1d2327;margin-top:2rem;padding-top:.5rem}.note{font-weight:600}@media print{button{display:none}section.part{break-inside:avoid}}</style></head><body>';
		if ( '' === $type ) {
			// Legacy layout (settlements finalized before sales models existed).
			$h .= '<div class="cols">' . $block( \PassCaptain\Invoices::seller_lines( $seller ) ) . $block( array_merge( array( (string) ( $partner['name'] ?? '' ) ), preg_split( '/\r\n|\r|\n/', (string) ( $partner['billing_address'] ?? '' ) ), ! empty( $partner['vat_id'] ) ? array( __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $partner['vat_id'] ) : array() ) ) . '</div>';
		} else {
			$h .= '<div class="cols">' . $block( \PassCaptain\Invoices::seller_lines( $seller ), __( 'Aussteller', 'wp-pass-captain' ) ) . $block( \PassCaptain\Invoices::seller_lines( $supplier ), __( 'Veranstalter', 'wp-pass-captain' ) ) . '</div>';
		}
		if ( 'draft' === $s->status ) { $h .= '<p class="draft">' . esc_html__( 'Entwurf – nicht abgeschlossen, Werte können sich noch ändern', 'wp-pass-captain' ) . '</p>'; }
		$h .= '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( sprintf( /* translators: 1: period, 2: date */ __( 'Zeitraum: %1$s · Erstellt: %2$s', 'wp-pass-captain' ), $s->period, $date ) );
		if ( 'paid' === $s->status ) { $h .= '<br>' . esc_html( sprintf( /* translators: 1: date, 2: reference */ __( 'Ausgezahlt am %1$s %2$s', 'wp-pass-captain' ), Scope::local( $s->paid_at, Scope::site_timezone(), 'd.m.Y' ), $s->payment_reference ) ); }
		$h .= '<br>' . esc_html( sprintf( /* translators: 1: commission, 2: fee, 3: VAT */ __( 'Konditionen: Provision %1$s vom Nettoumsatz, Ticketgebühr %2$s pro Ticket, USt auf Plattformgebühr %3$s', 'wp-pass-captain' ), $pct( $terms['commission_bp'] ?? 0 ), $money( $terms['fee_per_ticket_minor'] ?? 0 ), $pct( $terms['fee_tax_rate_bp'] ?? 0 ) ) ) . '</p>';

		if ( self::TYPE_SELF_BILLING === $type ) {
			$h .= self::html_self_billing( $s, $t, $seller, $supplier, $money, $pct, $date );
			$h .= self::html_fee_invoice( $s, $t, $seller, $supplier, $money, $pct, __( 'Teil B – Rechnung über Plattformgebühren', 'wp-pass-captain' ) );
			$h .= '<section class="part"><h2>' . esc_html__( 'Teil C – Verrechnung', 'wp-pass-captain' ) . '</h2><table><tbody>';
			$h .= '<tr><td>' . esc_html__( 'Gutschriftsbetrag (Teil A)', 'wp-pass-captain' ) . '</td><td class="n">' . esc_html( $money( (int) ( $t['self_billing']['gross'] ?? 0 ) ) ) . '</td></tr>';
			$h .= '<tr><td>' . esc_html__( 'abzüglich Rechnungsbetrag Plattformgebühren (Teil B)', 'wp-pass-captain' ) . '</td><td class="n">' . esc_html( $money( -(int) $s->platform_fee_gross_minor ) ) . '</td></tr>';
			$h .= self::payout_row( $s, $money ) . '</tbody></table></section>';
		} elseif ( self::TYPE_COMMISSION === $type ) {
			$h .= self::html_fee_invoice( $s, $t, $seller, $supplier, $money, $pct, '' );
			$h .= '<section class="part"><h2>' . esc_html__( 'Informative Aufstellung: vereinnahmte Ticketerlöse (keine Rechnung)', 'wp-pass-captain' ) . '</h2><p>' . esc_html__( 'Die Ticketerlöse wurden im Namen und für Rechnung des Veranstalters vereinnahmt. Die Kundenrechnungen wurden im Namen des Veranstalters ausgestellt; die Umsatzsteuer auf die Ticketumsätze schuldet der Veranstalter.', 'wp-pass-captain' ) . '</p><table><tbody>';
			$rows = array(
				__( 'Ticketumsatz brutto (Verkäufe)', 'wp-pass-captain' ) => (int) ( $t['sales_gross'] ?? 0 ),
				__( 'Erstattungen brutto', 'wp-pass-captain' ) => -(int) $s->refunded_minor,
				__( 'Vereinnahmte Ticketerlöse nach Erstattungen', 'wp-pass-captain' ) => (int) $s->gross_minor,
				__( 'darin enthaltene USt (Schuldner: Veranstalter)', 'wp-pass-captain' ) => (int) $s->tax_minor,
				__( 'abzüglich Provisionsrechnung (brutto)', 'wp-pass-captain' ) => -(int) $s->platform_fee_gross_minor,
			);
			foreach ( $rows as $label => $minor ) { $h .= '<tr><td>' . esc_html( $label ) . '</td><td class="n">' . esc_html( $money( $minor ) ) . '</td></tr>'; }
			$h .= self::payout_row( $s, $money ) . '</tbody></table></section>';
		} else {
			$h .= '<h2>' . esc_html__( 'Abrechnung', 'wp-pass-captain' ) . '</h2><table><tbody>';
			$rows = array(
				__( 'Ticketumsatz brutto (Verkäufe)', 'wp-pass-captain' ) => (int) ( $t['sales_gross'] ?? 0 ),
				__( 'Erstattungen brutto', 'wp-pass-captain' ) => -(int) $s->refunded_minor,
				__( 'Ticketumsatz brutto nach Erstattungen', 'wp-pass-captain' ) => (int) $s->gross_minor,
				__( 'darin enthaltene USt', 'wp-pass-captain' ) => (int) $s->tax_minor,
				__( 'Ticketumsatz netto', 'wp-pass-captain' ) => (int) $s->net_minor,
				sprintf( /* translators: %s: percent */ __( 'Provision %s vom Nettoumsatz', 'wp-pass-captain' ), $pct( $terms['commission_bp'] ?? 0 ) ) => -(int) $s->commission_minor,
				__( 'Ticketgebühren', 'wp-pass-captain' ) => -(int) $s->fee_minor,
				sprintf( /* translators: %s: percent */ __( 'USt %s auf Plattformgebühr', 'wp-pass-captain' ), $pct( $terms['fee_tax_rate_bp'] ?? 0 ) ) => -(int) $s->platform_fee_tax_minor,
			);
			foreach ( $rows as $label => $minor ) { $h .= '<tr><td>' . esc_html( $label ) . '</td><td class="n">' . esc_html( $money( $minor ) ) . '</td></tr>'; }
			$h .= self::payout_row( $s, $money ) . '</tbody></table>';
		}
		if ( (int) $s->corrections_minor ) {
			$h .= '<p>' . esc_html( sprintf( /* translators: %s: amount */ __( 'Enthält Korrekturen und Nachträge aus bereits abgeschlossenen Vorperioden: %s brutto.', 'wp-pass-captain' ), $money( $s->corrections_minor ) ) ) . '</p>';
		}

		$h .= '' !== $type ? '<section class="part"><h2>' . esc_html__( 'Anlage: Einzelaufstellung', 'wp-pass-captain' ) . '</h2>' : '';
		$h .= '<h2>' . esc_html__( 'Übersicht nach Art', 'wp-pass-captain' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Art', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Tickets', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'USt', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $kinds as $kind => $label ) {
			if ( empty( $t['kinds'][ $kind ] ) ) { continue; }
			$k = $t['kinds'][ $kind ];
			$h .= '<tr><td>' . esc_html( $label ) . '</td><td class="n">' . (int) $k['tickets'] . '</td><td class="n">' . esc_html( $money( $k['tax'] ) ) . '</td><td class="n">' . esc_html( $money( $k['gross'] ) ) . '</td></tr>';
		}
		$h .= '</tbody></table>';
		$h .= '<h2>' . esc_html__( 'Nach Event', 'wp-pass-captain' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Event', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Tickets', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Netto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Provision', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Ticketgebühr', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $t['events'] ?? array() ) as $ev ) {
			$h .= '<tr><td>' . esc_html( $ev['name'] ) . '</td><td class="n">' . (int) $ev['tickets'] . '</td><td class="n">' . esc_html( $money( $ev['gross'] ) ) . '</td><td class="n">' . esc_html( $money( $ev['net'] ) ) . '</td><td class="n">' . esc_html( $money( $ev['commission'] ) ) . '</td><td class="n">' . esc_html( $money( $ev['fee'] ) ) . '</td></tr>';
		}
		$h .= '</tbody></table>';
		$h .= '<h2>' . esc_html__( 'Ticketumsatz nach Steuersatz (Kundenverkauf)', 'wp-pass-captain' ) . '</h2><table><thead><tr><th>' . esc_html__( 'USt-Satz', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Netto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'USt', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $t['rates'] ?? array() ) as $bp => $r ) {
			$h .= '<tr><td>' . esc_html( $pct( $bp ) ) . '</td><td class="n">' . esc_html( $money( $r['net'] ) ) . '</td><td class="n">' . esc_html( $money( $r['tax'] ) ) . '</td><td class="n">' . esc_html( $money( $r['gross'] ) ) . '</td></tr>';
		}
		$h .= '</tbody></table>';
		$h .= '<h2>' . esc_html__( 'Positionen', 'wp-pass-captain' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Datum', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Art', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Event', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Bestellung', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Tickets', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'USt-Satz', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Provision', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Gebühr', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( self::lines( $s->settlement_id ) as $l ) {
			$label = ( $kinds[ $l->kind ] ?? $l->kind ) . ( in_array( $l->kind, array( 'late_sale', 'correction' ), true ) ? ' ' . $l->origin_period : '' );
			$h .= '<tr><td>' . esc_html( Scope::local( $l->occurred_at, Scope::site_timezone(), 'd.m.Y' ) ) . '</td><td>' . esc_html( $label ) . '</td><td>' . esc_html( $l->event_name ) . '</td><td class="n">' . (int) $l->order_id . '</td><td class="n">' . (int) $l->tickets . '</td><td class="n">' . esc_html( $pct( $l->tax_rate_bp ) ) . '</td><td class="n">' . esc_html( $money( $l->gross_minor ) ) . '</td><td class="n">' . esc_html( $money( $l->commission_minor ) ) . '</td><td class="n">' . esc_html( $money( $l->fee_minor ) ) . '</td></tr>';
		}
		$h .= '</tbody></table>' . ( '' !== $type ? '</section>' : '' ) . '<button onclick="window.print()">' . esc_html__( 'Drucken oder als PDF speichern', 'wp-pass-captain' ) . '</button></body></html>';
		return $h;
	}

	private static function payout_row( $s, $money ) {
		$payout_label = (int) $s->payout_minor >= 0 ? __( 'Auszahlungsbetrag an Veranstalter', 'wp-pass-captain' ) : __( 'Forderung an Veranstalter', 'wp-pass-captain' );
		return '<tr><th>' . esc_html( $payout_label ) . '</th><th class="n">' . esc_html( $money( abs( (int) $s->payout_minor ) ) ) . '</th></tr>';
	}

	/** Part A of the own_name document: self-billing "Gutschrift" for the organizer's supply to the platform. */
	private static function html_self_billing( $s, array $t, array $seller, array $supplier, $money, $pct, $date ) {
		$sb = (array) ( $t['self_billing'] ?? array() );
		$number = 'draft' === $s->status ? __( '(Nummer wird beim Abschließen vergeben)', 'wp-pass-captain' ) : (string) $s->self_billing_number;
		$h = '<section class="part"><h2>' . esc_html( sprintf( /* translators: %s: number */ __( 'Teil A – Gutschrift %s', 'wp-pass-captain' ), $number ) ) . '</h2>';
		$h .= '<p>' . esc_html__( 'Abrechnung im Gutschriftsverfahren: Der Leistungsempfänger rechnet über die Leistung des Veranstalters ab (§ 14 Abs. 2 Satz 2 UStG; Österreich: § 11 Abs. 7 UStG). Der Veranstalter kann der Gutschrift widersprechen; sie verliert damit ihre Wirkung als Rechnung.', 'wp-pass-captain' ) . '</p>';
		$h .= '<div class="cols"><div class="addr"><small>' . esc_html__( 'Leistender Unternehmer (Gutschriftsempfänger)', 'wp-pass-captain' ) . '</small>' . "\n" . esc_html( implode( "\n", \PassCaptain\Invoices::seller_lines( $supplier ) ) ) . '</div>';
		$h .= '<div class="addr"><small>' . esc_html__( 'Leistungsempfänger (Gutschriftsaussteller)', 'wp-pass-captain' ) . '</small>' . "\n" . esc_html( implode( "\n", \PassCaptain\Invoices::seller_lines( $seller ) ) ) . '</div></div>';
		$h .= '<p>' . esc_html( sprintf( /* translators: 1: period, 2: date */ __( 'Leistung: Durchführung der Veranstaltungen und Einräumung der Eintrittsberechtigungen laut Anlage (Leistungskommission, Verkauf durch die Plattform im eigenen Namen für Rechnung des Veranstalters). Leistungszeitraum: %1$s. Gutschriftsdatum: %2$s.', 'wp-pass-captain' ), $s->period, $date ) ) . '</p>';
		$h .= '<table><thead><tr><th>' . esc_html__( 'USt-Satz', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Netto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'USt', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		$no_vat = ! empty( $sb['small_business'] ) || ! empty( $sb['reverse_charge'] );
		foreach ( (array) ( $sb['rates'] ?? array() ) as $bp => $r ) {
			$h .= '<tr><td>' . esc_html( $no_vat ? '—' : $pct( $bp ) ) . '</td><td class="n">' . esc_html( $money( $r['net'] ) ) . '</td><td class="n">' . esc_html( $money( $r['tax'] ) ) . '</td><td class="n">' . esc_html( $money( $r['gross'] ) ) . '</td></tr>';
		}
		$h .= '<tr><th>' . esc_html__( 'Gutschriftsbetrag', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html( $money( (int) ( $sb['net'] ?? 0 ) ) ) . '</th><th class="n">' . esc_html( $money( (int) ( $sb['tax'] ?? 0 ) ) ) . '</th><th class="n">' . esc_html( $money( (int) ( $sb['gross'] ?? 0 ) ) ) . '</th></tr></tbody></table>';
		if ( ! empty( $sb['small_business'] ) ) {
			$h .= '<p class="note">' . esc_html( (string) ( $supplier['small_business_note'] ?? SalesModel::default_small_business_note() ) ) . '</p>';
		} elseif ( ! empty( $sb['reverse_charge'] ) ) {
			$h .= '<p class="note">' . esc_html( SalesModel::reverse_charge_note() ) . '</p><p><small>' . esc_html__( 'Die Steuer schuldet der Leistungsempfänger (§ 13b UStG / Art. 196 MwStSystRL / § 19 Abs. 1 öUStG).', 'wp-pass-captain' ) . '</small></p>';
		}
		return $h . '</section>';
	}

	/** Platform fee invoice (part B of the own_name document, or the whole "Provisionsrechnung" for agents). */
	private static function html_fee_invoice( $s, array $t, array $seller, array $supplier, $money, $pct, $heading ) {
		$terms = (array) ( $t['terms'] ?? array() );
		$rc = ! empty( $t['fee_reverse_charge'] );
		$h = '<section class="part">';
		if ( '' !== $heading ) {
			$number = 'draft' === $s->status ? __( '(Nummer wird beim Abschließen vergeben)', 'wp-pass-captain' ) : (string) $s->document_number;
			$h .= '<h2>' . esc_html( $heading . ' ' . $number ) . '</h2>';
			$h .= '<div class="cols"><div class="addr"><small>' . esc_html__( 'Rechnungssteller', 'wp-pass-captain' ) . '</small>' . "\n" . esc_html( implode( "\n", \PassCaptain\Invoices::seller_lines( $seller ) ) ) . '</div>';
			$h .= '<div class="addr"><small>' . esc_html__( 'Rechnungsempfänger', 'wp-pass-captain' ) . '</small>' . "\n" . esc_html( implode( "\n", \PassCaptain\Invoices::seller_lines( $supplier ) ) ) . '</div></div>';
		} else {
			$h .= '<h2>' . esc_html__( 'Rechnung über Vermittlungsleistungen', 'wp-pass-captain' ) . '</h2>';
		}
		$h .= '<p>' . esc_html( sprintf( /* translators: %s: period */ __( 'Leistung: Vermittlung und Abwicklung des Ticketverkaufs für die Veranstaltungen laut Anlage. Leistungszeitraum: %s.', 'wp-pass-captain' ), $s->period ) ) . '</p><table><tbody>';
		$h .= '<tr><td>' . esc_html( sprintf( /* translators: 1: percent, 2: net revenue */ __( 'Provision %1$s auf Netto-Ticketumsatz %2$s', 'wp-pass-captain' ), $pct( $terms['commission_bp'] ?? 0 ), $money( (int) $s->net_minor ) ) ) . '</td><td class="n">' . esc_html( $money( (int) $s->commission_minor ) ) . '</td></tr>';
		$h .= '<tr><td>' . esc_html( sprintf( /* translators: %s: fee per ticket */ __( 'Ticketgebühren (%s je Ticket)', 'wp-pass-captain' ), $money( $terms['fee_per_ticket_minor'] ?? 0 ) ) ) . '</td><td class="n">' . esc_html( $money( (int) $s->fee_minor ) ) . '</td></tr>';
		$h .= '<tr><td>' . esc_html__( 'Nettobetrag', 'wp-pass-captain' ) . '</td><td class="n">' . esc_html( $money( (int) $s->platform_fee_net_minor ) ) . '</td></tr>';
		$tax_label = $rc ? __( 'Umsatzsteuer (Reverse Charge)', 'wp-pass-captain' ) : sprintf( /* translators: %s: percent */ __( 'Umsatzsteuer %s', 'wp-pass-captain' ), $pct( $terms['fee_tax_rate_bp'] ?? 0 ) );
		$h .= '<tr><td>' . esc_html( $tax_label ) . '</td><td class="n">' . esc_html( $money( (int) $s->platform_fee_tax_minor ) ) . '</td></tr>';
		$h .= '<tr><th>' . esc_html__( 'Rechnungsbetrag', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html( $money( (int) $s->platform_fee_gross_minor ) ) . '</th></tr></tbody></table>';
		if ( $rc ) {
			$h .= '<p class="note">' . esc_html( SalesModel::reverse_charge_note() ) . '</p><p><small>' . esc_html__( 'Die Steuer schuldet der Leistungsempfänger (§ 13b UStG / Art. 196 MwStSystRL / § 19 Abs. 1 öUStG).', 'wp-pass-captain' ) . '</small></p>';
		}
		return $h . '</section>';
	}
}
