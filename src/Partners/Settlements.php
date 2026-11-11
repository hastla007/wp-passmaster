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
		if ( $events ) {
			$in = implode( ',', array_map( 'absint', $events ) );
			$settled = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT source_key FROM {$p}wpcap_partner_settlement_lines WHERE partner_id=%d AND source_key IS NOT NULL", (int) $partner->partner_id ) ) );
			$line_sql = "SELECT oi.item_id,oi.order_id,oi.ticket_type_id,oi.quantity,oi.line_total_minor,oi.tax_rate_bp,oi.tax_minor,o.event_id,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id";
			$has_partner_item = "EXISTS (SELECT 1 FROM {$p}wpcap_order_items x INNER JOIN {$p}wpcap_ticket_types xt ON xt.ticket_type_id=x.ticket_type_id INNER JOIN {$p}wpcap_occurrences xo ON xo.occurrence_id=xt.occurrence_id WHERE x.order_id=ord.order_id AND xo.event_id IN ($in))";
			$lower_sql = '' !== $lower ? $wpdb->prepare( ' AND ord.created_at >= %s', $lower ) : '';

			// Sales.
			$orders = $wpdb->get_results( $wpdb->prepare( "SELECT ord.order_id,ord.created_at,ord.discount_minor FROM {$p}wpcap_orders ord WHERE ord.payment_status IN ('paid','refunded') AND ord.currency=%s AND ord.created_at < %s{$lower_sql} AND $has_partner_item ORDER BY ord.order_id", $partner->currency, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list / prepared fragment.
			$by_order = self::lines_by_order( array_map( static function ( $o ) { return (int) $o->order_id; }, $orders ), $line_sql );
			foreach ( $orders as $order ) {
				$order_lines = $by_order[ (int) $order->order_id ] ?? array();
				$gross_lines = Data::allocate_discount( $order_lines, (int) $order->discount_minor );
				foreach ( $order_lines as $i => $line ) {
					if ( ! in_array( (int) $line->event_id, $events, true ) ) { continue; }
					$key = 'sale:' . (int) $line->item_id;
					if ( isset( $settled[ $key ] ) ) { continue; }
					$gross = (int) $gross_lines[ $i ];
					$tax = (int) $line->tax_minor;
					$tickets = (int) $line->quantity;
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
		return array( 'lines' => $lines, 'totals' => self::totals( $lines, $partner ) );
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
		$t['platform_fee_tax'] = self::pct( $t['platform_fee_net'], (int) $partner->fee_tax_rate_bp );
		$t['platform_fee_gross'] = $t['platform_fee_net'] + $t['platform_fee_tax'];
		$t['payout'] = $t['gross'] - $t['platform_fee_gross'];
		$t['terms'] = array( 'commission_bp' => (int) $partner->commission_bp, 'fee_per_ticket_minor' => (int) $partner->fee_per_ticket_minor, 'fee_tax_rate_bp' => (int) $partner->fee_tax_rate_bp, 'refund_policy' => (string) $partner->refund_policy, 'currency' => (string) $partner->currency );
		return $t;
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
		if ( ! $partner ) { return new \WP_Error( 'wpcap_partner_missing', __( 'Partner nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
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
		if ( false === $saved ) { return new \WP_Error( 'wpcap_settlement_exists', __( 'Für diesen Partner und Zeitraum gibt es bereits eine Abrechnung.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
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
		$wpdb->update( "{$p}wpcap_partner_settlements", array(
			'currency' => $partner->currency,
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
		self::store( $s, self::compute( $partner, $s->period ), $partner );
		$prefix = (string) ( Settings::get( 'partners' )['settlement_prefix'] ?? 'PA' );
		$number = Invoices::next_document_number( self::SERIES, '' !== $prefix ? $prefix : 'PA', true );
		if ( is_wp_error( $number ) ) { return $fail( $number ); }
		// Locks every line: the unique (partner_id, source_key) index rejects items settled elsewhere.
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_settlement_lines SET source_key=item_key WHERE settlement_id=%d", $settlement_id ) ) ) {
			return $fail( new \WP_Error( 'wpcap_settlement_conflict', __( 'Einzelne Positionen wurden bereits in einer anderen Abrechnung abgerechnet. Bitte neu berechnen.', 'wp-pass-captain' ), array( 'status' => 409 ) ) );
		}
		$snapshot = array(
			'partner' => array_intersect_key( (array) $partner, array_flip( array( 'partner_id', 'name', 'contact_name', 'contact_email', 'billing_address', 'vat_id', 'payout_details', 'currency' ) ) ),
			'seller' => array( 'name' => (string) Settings::get( 'seller_name' ), 'address' => (string) Settings::get( 'seller_address' ), 'vat_id' => (string) Settings::get( 'seller_vat_id' ) ),
		);
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_settlements SET status='finalized', document_number=%s, partner_snapshot=%s, finalized_by=%d, finalized_at=%s, updated_at=%s WHERE settlement_id=%d AND status='draft'", $number, wp_json_encode( $snapshot ), get_current_user_id(), $now, $now, $settlement_id ) );
		if ( 1 !== (int) $updated ) { return $fail( new \WP_Error( 'wpcap_settlement_save', __( 'Abrechnung konnte nicht abgeschlossen werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ) ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'partner_settlement_finalized', 'partner_settlement', $settlement_id, array( 'partner_id' => (int) $s->partner_id, 'period' => $s->period, 'number' => $number ) );
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
			array( __( 'Partner-Abrechnung', 'wp-pass-captain' ), (string) $s->document_number, $partner_name, $s->period, self::status_labels()[ $s->status ] ?? $s->status, $s->currency ),
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
		foreach ( self::summary( $s ) as $label => $minor ) { $rows[] = array( $label, self::m( $minor ) ); }
		return $rows;
	}

	/** Summary amounts: label => minor amount. */
	public static function summary( $s ) {
		return array(
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

	/** Printable HTML document (escaped; drafts are marked as such). */
	public static function render_html( $s ) {
		$t = self::decoded_totals( $s );
		$snapshot = (array) json_decode( (string) $s->partner_snapshot, true );
		$partner = (array) ( $snapshot['partner'] ?? (array) Repository::get( $s->partner_id ) );
		$seller = (array) ( $snapshot['seller'] ?? array( 'name' => (string) Settings::get( 'seller_name' ), 'address' => (string) Settings::get( 'seller_address' ), 'vat_id' => (string) Settings::get( 'seller_vat_id' ) ) );
		$cur = (string) $s->currency;
		$money = static function ( $minor ) use ( $cur ) { return Scope::money( $minor, $cur ); };
		$pct = static function ( $bp ) { return number_format_i18n( (int) $bp / 100, 2 ) . ' %'; };
		$terms = (array) ( $t['terms'] ?? array() );
		$kinds = self::kind_labels();
		$title = 'draft' === $s->status ? __( 'Abrechnungsentwurf', 'wp-pass-captain' ) : __( 'Partner-Abrechnung', 'wp-pass-captain' ) . ' ' . $s->document_number;
		$h = '<!doctype html><html lang="' . esc_attr( determine_locale() ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>';
		$h .= '<style>body{font:14px/1.45 system-ui,sans-serif;max-width:56rem;margin:2rem auto;padding:0 1rem;color:#1d2327}table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border-bottom:1px solid #dcdcde;padding:.35rem;text-align:left}td.n,th.n{text-align:right}.addr{white-space:pre-line}.cols{display:flex;gap:2rem;justify-content:space-between;flex-wrap:wrap}.draft{color:#b32d2e;font-weight:bold;text-transform:uppercase}@media print{button{display:none}}</style></head><body>';
		$h .= '<div class="cols"><div class="addr"><strong>' . esc_html( $seller['name'] ?? '' ) . '</strong>' . "\n" . esc_html( $seller['address'] ?? '' ) . ( ! empty( $seller['vat_id'] ) ? "\n" . esc_html( __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $seller['vat_id'] ) : '' ) . '</div>';
		$h .= '<div class="addr"><strong>' . esc_html( $partner['name'] ?? '' ) . '</strong>' . "\n" . esc_html( $partner['billing_address'] ?? '' ) . ( ! empty( $partner['vat_id'] ) ? "\n" . esc_html( __( 'USt-IdNr.:', 'wp-pass-captain' ) . ' ' . $partner['vat_id'] ) : '' ) . '</div></div>';
		if ( 'draft' === $s->status ) { $h .= '<p class="draft">' . esc_html__( 'Entwurf – nicht abgeschlossen, Werte können sich noch ändern', 'wp-pass-captain' ) . '</p>'; }
		$h .= '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( sprintf( /* translators: 1: period, 2: date */ __( 'Zeitraum: %1$s · Erstellt: %2$s', 'wp-pass-captain' ), $s->period, Scope::local( $s->finalized_at ?: $s->updated_at, Scope::site_timezone(), 'd.m.Y' ) ) );
		if ( 'paid' === $s->status ) { $h .= '<br>' . esc_html( sprintf( /* translators: 1: date, 2: reference */ __( 'Ausgezahlt am %1$s %2$s', 'wp-pass-captain' ), Scope::local( $s->paid_at, Scope::site_timezone(), 'd.m.Y' ), $s->payment_reference ) ); }
		$h .= '<br>' . esc_html( sprintf( /* translators: 1: commission, 2: fee, 3: VAT */ __( 'Konditionen: Provision %1$s vom Nettoumsatz, Ticketgebühr %2$s pro Ticket, USt auf Plattformgebühr %3$s', 'wp-pass-captain' ), $pct( $terms['commission_bp'] ?? 0 ), $money( $terms['fee_per_ticket_minor'] ?? 0 ), $pct( $terms['fee_tax_rate_bp'] ?? 0 ) ) ) . '</p>';

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
		$h .= '<h2>' . esc_html__( 'Umsatzsteuer nach Steuersatz', 'wp-pass-captain' ) . '</h2><table><thead><tr><th>' . esc_html__( 'USt-Satz', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Netto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'USt', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $t['rates'] ?? array() ) as $bp => $r ) {
			$h .= '<tr><td>' . esc_html( $pct( $bp ) ) . '</td><td class="n">' . esc_html( $money( $r['net'] ) ) . '</td><td class="n">' . esc_html( $money( $r['tax'] ) ) . '</td><td class="n">' . esc_html( $money( $r['gross'] ) ) . '</td></tr>';
		}
		$h .= '</tbody></table><h2>' . esc_html__( 'Abrechnung', 'wp-pass-captain' ) . '</h2><table><tbody>';
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
		$payout_label = (int) $s->payout_minor >= 0 ? __( 'Auszahlungsbetrag an Partner', 'wp-pass-captain' ) : __( 'Forderung an Partner', 'wp-pass-captain' );
		$h .= '<tr><th>' . esc_html( $payout_label ) . '</th><th class="n">' . esc_html( $money( abs( (int) $s->payout_minor ) ) ) . '</th></tr></tbody></table>';
		if ( (int) $s->corrections_minor ) {
			$h .= '<p>' . esc_html( sprintf( /* translators: %s: amount */ __( 'Enthält Korrekturen und Nachträge aus bereits abgeschlossenen Vorperioden: %s brutto.', 'wp-pass-captain' ), $money( $s->corrections_minor ) ) ) . '</p>';
		}
		$h .= '<h2>' . esc_html__( 'Positionen', 'wp-pass-captain' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Datum', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Art', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Event', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Bestellung', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Tickets', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'USt-Satz', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Brutto', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Provision', 'wp-pass-captain' ) . '</th><th class="n">' . esc_html__( 'Gebühr', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( self::lines( $s->settlement_id ) as $l ) {
			$label = ( $kinds[ $l->kind ] ?? $l->kind ) . ( in_array( $l->kind, array( 'late_sale', 'correction' ), true ) ? ' ' . $l->origin_period : '' );
			$h .= '<tr><td>' . esc_html( Scope::local( $l->occurred_at, Scope::site_timezone(), 'd.m.Y' ) ) . '</td><td>' . esc_html( $label ) . '</td><td>' . esc_html( $l->event_name ) . '</td><td class="n">' . (int) $l->order_id . '</td><td class="n">' . (int) $l->tickets . '</td><td class="n">' . esc_html( $pct( $l->tax_rate_bp ) ) . '</td><td class="n">' . esc_html( $money( $l->gross_minor ) ) . '</td><td class="n">' . esc_html( $money( $l->commission_minor ) ) . '</td><td class="n">' . esc_html( $money( $l->fee_minor ) ) . '</td></tr>';
		}
		$h .= '</tbody></table><button onclick="window.print()">' . esc_html__( 'Drucken oder als PDF speichern', 'wp-pass-captain' ) . '</button></body></html>';
		return $h;
	}
}
