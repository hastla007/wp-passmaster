<?php
/**
 * "Wiederverkäufer-Abrechnung": monthly statement of the orders a partner sold through the API
 * (wpcap_partner_orders) – computed on demand, nothing is stored or numbered.
 *
 * Attribution:
 *  - The organizer of an event is settled for ALL paid sales of its events (Settlements), whoever sold
 *    them – reseller sales included.
 *  - The seller of record (reseller, or an organizer selling itself) collected the money for its API
 *    sales. This statement lists those sales and refunds of the month (sales by confirmation time,
 *    refunds by completion time) and the reseller's remuneration: commission (reseller_commission_bp
 *    of the net amount) plus a fee per ticket (reseller_fee_minor, net), with VAT at the partner's fee
 *    VAT rate. Refunds reverse commission and fee.
 *      amount due to the platform = gross collected − refunds − remuneration incl. VAT
 *    For organizers selling their own events, commission/fee are normally 0, so the amount due is the
 *    money they collected themselves (to be netted against their settlement payout).
 * Only orders in the partner's currency are included.
 */
namespace PassCaptain\Partners;

use PassCaptain\Reports\Data;
use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Statement {
	private static function pct( $amount, $bp ) {
		return (int) round( (int) $amount * (int) $bp / 10000 );
	}

	/**
	 * @return array ['period','currency','lines' => array[], 'totals' => array]
	 */
	public static function compute( $partner, $period ) {
		global $wpdb;
		$p = $wpdb->prefix;
		list( $start, $end ) = Settlements::period_bounds( $period );
		$line_sql = "SELECT oi.item_id,oi.order_id,oi.ticket_type_id," . \PassCaptain\OrderLines::paid_columns() . ",oi.tax_rate_bp,o.event_id,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id";
		$lines = array();
		// Sales confirmed in the period (paid or later refunded).
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT ord.order_id,ord.public_id,ord.discount_minor,COALESCE(po.confirmed_at,ord.created_at) AS sold_at,po.external_reference FROM {$p}wpcap_partner_orders po INNER JOIN {$p}wpcap_orders ord ON ord.order_id=po.order_id WHERE po.partner_id=%d AND ord.payment_status IN ('paid','refunded') AND ord.currency=%s AND COALESCE(po.confirmed_at,ord.created_at) >= %s AND COALESCE(po.confirmed_at,ord.created_at) < %s ORDER BY ord.order_id", (int) $partner->partner_id, $partner->currency, $start, $end ) );
		$by_order = self::lines_by_order( array_map( static function ( $o ) { return (int) $o->order_id; }, $orders ), $line_sql );
		foreach ( $orders as $order ) {
			$order_lines = $by_order[ (int) $order->order_id ] ?? array();
			$gross_lines = Data::allocate_discount( $order_lines, (int) $order->discount_minor );
			$supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order->order_id );
			foreach ( $order_lines as $i => $line ) {
				if ( \PassCaptain\OrderLines::is_moved_in( $line ) ) { continue; } // Moved in by an exchange: paid elsewhere.
				// An exchange supplement is money for a ticket that was already counted on its original order.
				$lines[] = self::line( 'sale', $order, $line, 0, $order->sold_at, $supplement ? 0 : (int) $line->quantity, (int) $gross_lines[ $i ], (int) $line->tax_minor, $partner );
			}
		}
		// Refunds completed in the period.
		$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.updated_at,ord.public_id,po.external_reference,(ord.voucher_snapshot IS NOT NULL AND ord.voucher_snapshot<>'') AS has_voucher FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_partner_orders po ON po.order_id=r.order_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE po.partner_id=%d AND r.status='completed' AND ord.currency=%s AND r.updated_at >= %s AND r.updated_at < %s ORDER BY r.refund_id", (int) $partner->partner_id, $partner->currency, $start, $end ) );
		$refund_lines = self::lines_by_order( array_unique( array_map( static function ( $r ) { return (int) $r->order_id; }, $refunds ) ), $line_sql );
		foreach ( $refunds as $refund ) {
			// Sales count the full ticket value (multi-purpose voucher included), so refunds count cash +
			// voucher share and the full VAT from the stored refund plan.
			$amounts = \PassCaptain\Integrations\Vouchers\Checkout::refund_amounts( $refund );
			$refund->amount_minor = $amounts['amount_minor'];
			$refund->tax_minor = $amounts['tax_minor'];
			$order_lines = $refund_lines[ (int) $refund->order_id ] ?? array();
			foreach ( Settlements::allocate_refund( $refund, $order_lines ) as $i => $share ) {
				$lines[] = self::line( 'refund', $refund, $order_lines[ $i ], (int) $refund->refund_id, $refund->updated_at, -$share['tickets'], -$share['amount'], -$share['tax'], $partner );
			}
		}
		usort( $lines, static function ( $a, $b ) { return array( $a['occurred_at'], $a['kind'], $a['order'] ) <=> array( $b['occurred_at'], $b['kind'], $b['order'] ); } );
		return array( 'period' => $period, 'currency' => (string) $partner->currency, 'lines' => $lines, 'totals' => self::totals( $lines, $partner ) );
	}

	private static function line( $kind, $order, $line, $refund_id, $occurred_at, $tickets, $gross, $tax, $partner ) {
		$net = $gross - $tax;
		return array(
			'kind' => $kind,
			'order' => (string) $order->public_id,
			'external_reference' => (string) $order->external_reference,
			'refund_id' => (int) $refund_id,
			'event_id' => (int) $line->event_id,
			'event_name' => (string) $line->event_name,
			'ticket_type_id' => (int) $line->ticket_type_id,
			'occurred_at' => (string) $occurred_at,
			'tax_rate_bp' => (int) $line->tax_rate_bp,
			'tickets' => (int) $tickets,
			'gross_minor' => (int) $gross,
			'tax_minor' => (int) $tax,
			'net_minor' => (int) $net,
			'commission_minor' => self::pct( $net, (int) ( $partner->reseller_commission_bp ?? 0 ) ),
			'fee_minor' => (int) $tickets * (int) ( $partner->reseller_fee_minor ?? 0 ),
		);
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

	public static function totals( array $lines, $partner ) {
		$t = array( 'tickets_sold' => 0, 'tickets_refunded' => 0, 'sales_gross' => 0, 'refunded' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0, 'commission' => 0, 'fee' => 0 );
		foreach ( $lines as $l ) {
			if ( 'sale' === $l['kind'] ) {
				$t['tickets_sold'] += $l['tickets'];
				$t['sales_gross'] += $l['gross_minor'];
			} else {
				$t['tickets_refunded'] += -$l['tickets'];
				$t['refunded'] += -$l['gross_minor'];
			}
			$t['gross'] += $l['gross_minor'];
			$t['tax'] += $l['tax_minor'];
			$t['net'] += $l['net_minor'];
			$t['commission'] += $l['commission_minor'];
			$t['fee'] += $l['fee_minor'];
		}
		$t['remuneration_net'] = $t['commission'] + $t['fee'];
		// The remuneration is the reseller's service to the platform: no VAT for a small business
		// reseller; reverse charge when the reseller is established in another EU state than the
		// platform and the platform has a VAT ID (SalesModel::reverse_charge()).
		$platform = SalesModel::platform_seller();
		$t['remuneration_small_business'] = ! empty( $partner->small_business );
		$t['remuneration_reverse_charge'] = ! $t['remuneration_small_business'] && SalesModel::reverse_charge( SalesModel::partner_country( $partner ), (string) $platform['country'], (string) $platform['vat_id'] );
		$t['remuneration_note'] = $t['remuneration_small_business']
			? SalesModel::organizer_seller( $partner )['small_business_note']
			: ( $t['remuneration_reverse_charge'] ? SalesModel::reverse_charge_note() : '' );
		$t['remuneration_tax'] = ( $t['remuneration_small_business'] || $t['remuneration_reverse_charge'] ) ? 0 : self::pct( $t['remuneration_net'], (int) $partner->fee_tax_rate_bp );
		/**
		 * Document of the remuneration: 'reseller_invoice' (default: the reseller invoices the platform
		 * with its own number) or 'self_billing' (the platform issues a Gutschrift; needs the reseller's
		 * agreement and a number range – see the open tax question in TODO.md).
		 */
		$t['remuneration_document'] = (string) apply_filters( 'wpcap_reseller_statement_document', 'reseller_invoice', $partner );
		$t['remuneration_gross'] = $t['remuneration_net'] + $t['remuneration_tax'];
		$t['due_to_platform'] = $t['gross'] - $t['remuneration_gross'];
		// An organizer selling its own events: the money it collected is netted automatically against its
		// organizer settlement payout (Settlements, "collected_self") – nothing is transferred separately.
		$t['netted_in_settlement'] = Repository::ROLE_ORGANIZER === Repository::role_of( $partner );
		$t['terms'] = array( 'role' => Repository::role_of( $partner ), 'commission_bp' => (int) ( $partner->reseller_commission_bp ?? 0 ), 'fee_per_ticket_minor' => (int) ( $partner->reseller_fee_minor ?? 0 ), 'fee_tax_rate_bp' => (int) $partner->fee_tax_rate_bp );
		return $t;
	}

	/** API representation (GET /partner/sales/statement). */
	public static function payload( $partner, $period ) {
		$s = self::compute( $partner, $period );
		$iso = static function ( $utc ) { return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $utc . ' UTC' ) ); };
		$t = $s['totals'];
		return array(
			'period' => $s['period'],
			'currency' => $s['currency'],
			'terms' => $t['terms'],
			'totals' => array(
				'tickets_sold' => $t['tickets_sold'], 'tickets_refunded' => $t['tickets_refunded'], 'sales_gross_minor' => $t['sales_gross'], 'refunded_minor' => $t['refunded'],
				'gross_minor' => $t['gross'], 'tax_minor' => $t['tax'], 'net_minor' => $t['net'], 'commission_minor' => $t['commission'], 'fee_minor' => $t['fee'],
				'remuneration_net_minor' => $t['remuneration_net'], 'remuneration_tax_minor' => $t['remuneration_tax'], 'remuneration_gross_minor' => $t['remuneration_gross'], 'due_to_platform_minor' => $t['due_to_platform'], 'netted_in_settlement' => (bool) $t['netted_in_settlement'],
				'remuneration_reverse_charge' => $t['remuneration_reverse_charge'], 'remuneration_small_business' => $t['remuneration_small_business'], 'remuneration_note' => $t['remuneration_note'], 'remuneration_document' => $t['remuneration_document'],
			),
			'lines' => array_map( static function ( $l ) use ( $iso ) {
				return array( 'kind' => $l['kind'], 'order_id' => $l['order'], 'external_reference' => $l['external_reference'], 'event_id' => $l['event_id'], 'ticket_type_id' => $l['ticket_type_id'], 'occurred_at' => $iso( $l['occurred_at'] ), 'tax_rate_bp' => $l['tax_rate_bp'], 'tickets' => $l['tickets'], 'gross_minor' => $l['gross_minor'], 'tax_minor' => $l['tax_minor'], 'net_minor' => $l['net_minor'], 'commission_minor' => $l['commission_minor'], 'fee_minor' => $l['fee_minor'] );
			}, $s['lines'] ),
		);
	}

	/** CSV rows (header + lines + totals) for the admin download. */
	public static function csv_rows( $partner, $period ) {
		$s = self::compute( $partner, $period );
		$tz = Scope::site_timezone();
		$money = static function ( $minor ) { return number_format( (int) $minor / 100, 2, ',', '' ); };
		$rows = array( array( __( 'Art', 'pass-captain' ), __( 'Datum', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ), __( 'Externe Referenz', 'pass-captain' ), __( 'Event', 'pass-captain' ), __( 'Tickets', 'pass-captain' ), __( 'USt-Satz %', 'pass-captain' ), __( 'Brutto', 'pass-captain' ), __( 'USt', 'pass-captain' ), __( 'Netto', 'pass-captain' ), __( 'Provision', 'pass-captain' ), __( 'Gebühr', 'pass-captain' ) ) );
		$kinds = array( 'sale' => __( 'Verkauf', 'pass-captain' ), 'refund' => __( 'Erstattung', 'pass-captain' ) );
		foreach ( $s['lines'] as $l ) {
			$rows[] = array( $kinds[ $l['kind'] ] ?? $l['kind'], Scope::local( $l['occurred_at'], $tz, 'd.m.Y H:i' ), substr( $l['order'], 0, 8 ), $l['external_reference'], $l['event_name'], $l['tickets'], number_format( $l['tax_rate_bp'] / 100, 2, ',', '' ), $money( $l['gross_minor'] ), $money( $l['tax_minor'] ), $money( $l['net_minor'] ), $money( $l['commission_minor'] ), $money( $l['fee_minor'] ) );
		}
		$t = $s['totals'];
		$rows[] = array();
		$rows[] = array( __( 'Vereinnahmt brutto nach Erstattungen', 'pass-captain' ), $money( $t['gross'] ) );
		$rows[] = array( __( 'Vergütung netto (Provision + Gebühren)', 'pass-captain' ), $money( $t['remuneration_net'] ) );
		$rows[] = array( __( 'USt auf Vergütung', 'pass-captain' ), $money( $t['remuneration_tax'] ) );
		if ( '' !== $t['remuneration_note'] ) { $rows[] = array( __( 'Hinweis Vergütung', 'pass-captain' ), $t['remuneration_note'] ); }
		$rows[] = array( __( 'Beleg der Vergütung', 'pass-captain' ), 'self_billing' === $t['remuneration_document'] ? __( 'Gutschrift der Plattform', 'pass-captain' ) : __( 'Rechnung des Wiederverkäufers (eigene Rechnungsnummer)', 'pass-captain' ) );
		$rows[] = array( $t['netted_in_settlement'] ? __( 'Wird mit der Veranstalter-Abrechnung verrechnet', 'pass-captain' ) : __( 'An die Plattform abzuführen', 'pass-captain' ), $money( $t['due_to_platform'] ) );
		return $rows;
	}
}
