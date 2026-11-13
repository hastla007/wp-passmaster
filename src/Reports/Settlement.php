<?php
/**
 * Settlement sheet per occurrence ("Abrechnungsblatt", door deal / evening settlement) for promoter and
 * venue deals: tickets sold per ticket type and sales channel, comps separately, gross/VAT/net per rate,
 * completed refunds, box office takings per payment method, check-ins and no-shows, and a deal block
 * (fixed amount first, then a percentage share of the remaining net revenue). Print view and CSV.
 *
 * Figures cover the whole life of the occurrence (no date range): every paid or later refunded order
 * with tickets for the occurrence and every completed refund of those orders, computed exactly like the
 * sales report (Data::build(): discount allocation, exchange lines, voucher refund plans, comps). The deal
 * inputs are not stored; they only parametrise the calculation of one view or export.
 */
namespace PassCaptain\Reports;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Settlement {
	const ACTION = 'wpcap_settlement_sheet';

	/** Whether the current user may see the sheet of the occurrence (report scope, see Data::scope()). */
	public static function can_view( $occurrence_id ) {
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id || ! \PassCaptain\Access::occurrence_in_scope( $occurrence_id ) ) { return false; }
		$scope = Data::scope();
		if ( false === $scope ) { return false; }
		return null === $scope || in_array( $event_id, (array) $scope, true );
	}

	/** Occurrences in the user's report scope (last 365 days and upcoming, newest first): id => label. */
	public static function occurrence_options() {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = Data::scope();
		if ( false === $scope ) { return array(); }
		$scope_sql = \PassCaptain\Admin\Queries::scope_sql( $scope, 'o.event_id' ) . \PassCaptain\Access::occurrence_scope_sql( 'o' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.starts_at,o.timezone,o.status,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.starts_at >= %s $scope_sql ORDER BY o.starts_at DESC LIMIT 300", gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer scope list.
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->occurrence_id ] = $r->name . ' – ' . Scope::local( $r->starts_at, $r->timezone ) . ( 'scheduled' !== $r->status ? ' (' . __( 'abgesagt', 'pass-captain' ) . ')' : '' );
		}
		return $out;
	}

	/**
	 * Deal inputs from a request: fixed amount (decimal, "1.500,00" or "1500.00") and share in percent.
	 *
	 * @return array{fixed:int,share_bp:int} Fixed amount in minor units, share in basis points (0–10000).
	 */
	public static function parse_deal( array $in ) {
		$fixed = is_scalar( $in['fixed'] ?? '' ) ? trim( (string) ( $in['fixed'] ?? '' ) ) : '';
		$share = is_scalar( $in['share'] ?? '' ) ? trim( (string) ( $in['share'] ?? '' ) ) : '';
		return array(
			'fixed' => max( 0, (int) round( self::decimal( $fixed ) * 100 ) ),
			'share_bp' => min( 10000, max( 0, (int) round( self::decimal( $share ) * 100 ) ) ),
		);
	}

	/** German or English decimal notation to float (invalid input = 0). */
	private static function decimal( $text ) {
		$text = str_replace( array( ' ', "\u{00A0}", '€', '%' ), '', (string) $text );
		if ( false !== strpos( $text, ',' ) ) { $text = str_replace( array( '.', ',' ), array( '', '.' ), $text ); }
		return is_numeric( $text ) ? (float) $text : 0.0;
	}

	/**
	 * Deal split: the fixed amount is deducted from the net revenue first, the share applies to the rest.
	 *
	 * @return array{basis:int,fixed:int,after_fixed:int,share_bp:int,artist:int,house:int}
	 */
	public static function deal( $net_minor, $fixed_minor, $share_bp ) {
		$net = (int) $net_minor;
		$fixed = max( 0, (int) $fixed_minor );
		$share_bp = min( 10000, max( 0, (int) $share_bp ) );
		$after = max( 0, $net - $fixed );
		$artist = (int) round( $after * $share_bp / 10000 );
		return array( 'basis' => $net, 'fixed' => $fixed, 'after_fixed' => $after, 'share_bp' => $share_bp, 'artist' => $artist, 'house' => $net - $artist );
	}

	private static function bucket() {
		return array( 'tickets' => 0, 'gross' => 0, 'tax' => 0, 'refunded_tickets' => 0, 'refunded' => 0, 'refunded_tax' => 0 );
	}

	/** Adds derived figures (after refunds) to a bucket. */
	private static function finish( array $b ) {
		$b['net_tickets'] = $b['tickets'] - $b['refunded_tickets'];
		$b['revenue'] = $b['gross'] - $b['refunded'];
		$b['revenue_tax'] = $b['tax'] - $b['refunded_tax'];
		$b['revenue_net'] = $b['revenue'] - $b['revenue_tax'];
		return $b;
	}

	/** Labels of box office providers (pos_cash => "Bar", ...). */
	private static function provider_label( $provider ) {
		if ( class_exists( '\PassCaptain\BoxOffice\Service' ) ) {
			foreach ( \PassCaptain\BoxOffice\Service::methods() as $method ) {
				if ( $method['provider'] === $provider ) { return $method['label']; }
			}
		}
		return (string) $provider;
	}

	/**
	 * Settlement figures of an occurrence (no permission check: callers use can_view()).
	 *
	 * @param array $deal parse_deal() result.
	 * @return array|null Null for an unknown occurrence.
	 */
	public static function build( $occurrence_id, array $deal = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
		if ( ! $occ ) { return null; }
		$comp_providers = Data::comp_providers();
		$channel_labels = Data::channel_labels();

		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT ord.order_id,ord.payment_provider,ord.currency,ord.discount_minor FROM {$p}wpcap_orders ord INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE tt.occurrence_id=%d AND ord.payment_status IN ('paid','refunded') ORDER BY ord.order_id", $occurrence_id ) );
		$order_map = array();
		foreach ( $orders as $o ) { $order_map[ (int) $o->order_id ] = $o; }
		$lines_by_order = Data::lines_by_order( array_keys( $order_map ) );

		$type_channel = array(); // ticket type id => channel => bucket
		$type_names = array();
		$channels = array();
		foreach ( $channel_labels as $key => $label ) { $channels[ $key ] = self::bucket(); }
		$rates = array(); // rate bp => bucket
		$providers = array(); // box office provider => bucket
		$total = self::bucket();
		$currencies = array();

		$add = static function ( $line, $provider, array $values ) use ( &$type_channel, &$type_names, &$channels, &$rates, &$providers, &$total ) {
			$type = (int) $line->ticket_type_id;
			$channel = Data::channel_of( $provider );
			$rate = (int) $line->tax_rate_bp;
			$type_names[ $type ] = (string) $line->ticket_name;
			$type_channel[ $type ][ $channel ] = $type_channel[ $type ][ $channel ] ?? self::bucket();
			$rates[ $rate ] = $rates[ $rate ] ?? self::bucket();
			$is_pos = 'pos' === $channel;
			if ( $is_pos ) { $providers[ $provider ] = $providers[ $provider ] ?? self::bucket(); }
			foreach ( $values as $field => $value ) {
				$type_channel[ $type ][ $channel ][ $field ] += $value;
				$channels[ $channel ][ $field ] += $value;
				$rates[ $rate ][ $field ] += $value;
				$total[ $field ] += $value;
				if ( $is_pos ) { $providers[ $provider ][ $field ] += $value; }
			}
		};

		// Sales (comps are not sales; they are taken from occurrence_stats() below).
		$sale_orders = array();
		foreach ( $order_map as $order_id => $order ) {
			if ( in_array( (string) $order->payment_provider, $comp_providers, true ) ) { continue; }
			$sale_orders[] = $order_id;
			$lines = $lines_by_order[ $order_id ] ?? array();
			$gross_lines = Data::allocate_discount( $lines, (int) $order->discount_minor );
			$supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order_id );
			foreach ( $lines as $i => $line ) {
				if ( (int) $line->occurrence_id !== $occurrence_id || \PassCaptain\OrderLines::is_moved_in( $line ) ) { continue; }
				$currencies[ $order->currency ] = true;
				$add( $line, (string) $order->payment_provider, array( 'tickets' => $supplement ? 0 : (int) $line->quantity, 'gross' => (int) $gross_lines[ $i ], 'tax' => (int) $line->tax_minor ) );
			}
		}

		// Completed refunds of these orders (the part that belongs to this occurrence).
		if ( $sale_orders ) {
			$in = implode( ',', array_map( 'absint', $sale_orders ) );
			$refunds = $wpdb->get_results( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,ord.payment_provider,(ord.voucher_snapshot IS NOT NULL AND ord.voucher_snapshot<>'') AS has_voucher FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE r.status='completed' AND r.order_id IN ($in) ORDER BY r.refund_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( $refunds as $refund ) {
				$lines = $lines_by_order[ (int) $refund->order_id ] ?? array();
				foreach ( Data::refund_split( $refund, $lines ) as $part ) {
					$line = $lines[ $part['index'] ];
					if ( (int) $line->occurrence_id !== $occurrence_id ) { continue; }
					$add( $line, (string) $refund->payment_provider, array( 'refunded_tickets' => $part['tickets'], 'refunded' => $part['amount'], 'refunded_tax' => $part['tax'] ) );
				}
			}
		}

		// Capacity, comps, issued tickets and check-ins (same figures as the report's occupancy table).
		$stats = Data::occurrence_stats( array( $occurrence_id ), '1970-01-01 00:00:00', '1970-01-01 00:00:00', null )[ $occurrence_id ] ?? array();
		$comps = array();
		foreach ( (array) ( $stats['types'] ?? array() ) as $t ) {
			if ( (int) $t['comps'] > 0 ) { $comps[ (int) $t['ticket_type_id'] ] = array( 'name' => (string) $t['name'], 'tickets' => (int) $t['comps'] ); }
		}

		// Rows per ticket type and channel (ticket type order, then channel order).
		$type_rows = array();
		$type_order = array();
		foreach ( (array) ( $stats['types'] ?? array() ) as $t ) { $type_order[] = (int) $t['ticket_type_id']; }
		foreach ( array_keys( $type_channel ) as $type ) { if ( ! in_array( $type, $type_order, true ) ) { $type_order[] = $type; } }
		foreach ( $type_order as $type ) {
			foreach ( array_keys( $channel_labels ) as $channel ) {
				if ( ! isset( $type_channel[ $type ][ $channel ] ) ) { continue; }
				$type_rows[] = array_merge( self::finish( $type_channel[ $type ][ $channel ] ), array( 'ticket_type_id' => $type, 'ticket_type' => $type_names[ $type ], 'channel' => $channel, 'channel_label' => $channel_labels[ $channel ] ) );
			}
		}
		$channel_rows = array();
		foreach ( $channels as $key => $b ) { $channel_rows[ $key ] = array_merge( self::finish( $b ), array( 'label' => $channel_labels[ $key ] ) ); }
		ksort( $rates, SORT_NUMERIC );
		$rate_rows = array();
		foreach ( $rates as $rate => $b ) { $rate_rows[ $rate ] = array_merge( self::finish( $b ), array( 'rate_bp' => (int) $rate, 'label' => number_format_i18n( $rate / 100, 2 ) . ' %' ) ); }
		ksort( $providers );
		$box_office = array();
		foreach ( $providers as $provider => $b ) { $box_office[ $provider ] = array_merge( self::finish( $b ), array( 'label' => self::provider_label( $provider ) ) ); }
		$total = self::finish( $total );

		$issued = (int) ( $stats['issued'] ?? 0 );
		$checked_in = (int) ( $stats['checked_in'] ?? 0 );
		$deal = array_merge( array( 'fixed' => 0, 'share_bp' => 0 ), $deal );
		if ( ! $currencies ) {
			$currencies[ (string) ( $wpdb->get_var( $wpdb->prepare( "SELECT currency FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id LIMIT 1", $occurrence_id ) ) ?: 'EUR' ) ] = true;
		}
		return array(
			'occurrence' => array(
				'occurrence_id' => $occurrence_id,
				'event_id' => (int) $occ->event_id,
				'event_name' => (string) $occ->event_name,
				'starts' => Scope::local( $occ->starts_at, $occ->timezone ),
				'timezone' => (string) $occ->timezone,
				'location' => (string) ( $occ->location ?? '' ),
				'status' => (string) $occ->status,
				'capacity' => (int) ( $stats['capacity'] ?? 0 ),
			),
			'currency' => implode( '/', array_keys( $currencies ) ),
			'types' => $type_rows,
			'channels' => $channel_rows,
			'rates' => $rate_rows,
			'box_office' => $box_office,
			'comps' => $comps,
			'comps_total' => array_sum( array_column( $comps, 'tickets' ) ),
			'total' => $total,
			'attendance' => array( 'issued' => $issued, 'checked_in' => $checked_in, 'no_shows' => max( 0, $issued - $checked_in ) ),
			'deal' => self::deal( $total['revenue_net'], $deal['fixed'], $deal['share_bp'] ),
		);
	}

	/** CSV rows (amounts as decimals with a dot). */
	public static function csv_rows( array $sheet ) {
		$m = static function ( $minor ) { return number_format( (int) $minor / 100, 2, '.', '' ); };
		$o = $sheet['occurrence'];
		$fig = static function ( array $b ) use ( $m ) { return array( $b['tickets'], $b['refunded_tickets'], $b['net_tickets'], $m( $b['gross'] ), $m( $b['refunded'] ), $m( $b['revenue'] ), $m( $b['revenue_tax'] ), $m( $b['revenue_net'] ) ); };
		$head = array( __( 'Verkauft', 'pass-captain' ), __( 'Erstattet', 'pass-captain' ), __( 'Tickets netto', 'pass-captain' ), __( 'Brutto', 'pass-captain' ), __( 'Erstattet brutto', 'pass-captain' ), __( 'Umsatz nach Erstattung', 'pass-captain' ), __( 'davon USt', 'pass-captain' ), __( 'Netto', 'pass-captain' ) );
		$rows = array(
			array( __( 'Abrechnungsblatt', 'pass-captain' ), $o['event_name'], $o['starts'], $o['timezone'], $sheet['currency'] ),
			array(),
			array_merge( array( __( 'Ticketart', 'pass-captain' ), __( 'Kanal', 'pass-captain' ) ), $head ),
		);
		foreach ( $sheet['types'] as $r ) { $rows[] = array_merge( array( $r['ticket_type'], $r['channel_label'] ), $fig( $r ) ); }
		$rows[] = array();
		$rows[] = array_merge( array( __( 'Kanal', 'pass-captain' ), '' ), $head );
		foreach ( $sheet['channels'] as $r ) { $rows[] = array_merge( array( $r['label'], '' ), $fig( $r ) ); }
		$rows[] = array_merge( array( __( 'Gesamt', 'pass-captain' ), '' ), $fig( $sheet['total'] ) );
		$rows[] = array();
		$rows[] = array_merge( array( __( 'USt-Satz', 'pass-captain' ), '' ), $head );
		foreach ( $sheet['rates'] as $r ) { $rows[] = array_merge( array( $r['label'], '' ), $fig( $r ) ); }
		$rows[] = array();
		$rows[] = array_merge( array( __( 'Kasse', 'pass-captain' ), __( 'Zahlungsart', 'pass-captain' ) ), $head );
		foreach ( $sheet['box_office'] as $provider => $r ) { $rows[] = array_merge( array( __( 'Kasse', 'pass-captain' ), $r['label'] ), $fig( $r ) ); }
		$rows[] = array();
		$rows[] = array( __( 'Freikarten (nicht im Umsatz)', 'pass-captain' ), __( 'Tickets', 'pass-captain' ) );
		foreach ( $sheet['comps'] as $c ) { $rows[] = array( $c['name'], $c['tickets'] ); }
		$rows[] = array( __( 'Gesamt', 'pass-captain' ), $sheet['comps_total'] );
		$rows[] = array();
		$a = $sheet['attendance'];
		$rows[] = array( __( 'Kapazität', 'pass-captain' ), $o['capacity'] ?: '∞' );
		$rows[] = array( __( 'Gültige Tickets (inkl. Freikarten)', 'pass-captain' ), $a['issued'] );
		$rows[] = array( __( 'Eingecheckt', 'pass-captain' ), $a['checked_in'] );
		$rows[] = array( __( 'No-Shows', 'pass-captain' ), $a['no_shows'] );
		$rows[] = array();
		$d = $sheet['deal'];
		$rows[] = array( __( 'Deal', 'pass-captain' ), __( 'Betrag', 'pass-captain' ) );
		$rows[] = array( __( 'Netto-Umsatz nach Erstattungen', 'pass-captain' ), $m( $d['basis'] ) );
		$rows[] = array( __( 'abzüglich Fixbetrag', 'pass-captain' ), $m( $d['fixed'] ) );
		$rows[] = array( __( 'Bemessungsgrundlage', 'pass-captain' ), $m( $d['after_fixed'] ) );
		$rows[] = array( __( 'Anteil %', 'pass-captain' ), number_format( $d['share_bp'] / 100, 2, '.', '' ) );
		$rows[] = array( __( 'Anteil Künstler/Veranstalter', 'pass-captain' ), $m( $d['artist'] ) );
		$rows[] = array( __( 'Verbleibt Spielstätte/Haus', 'pass-captain' ), $m( $d['house'] ) );
		return $rows;
	}

	/**
	 * Printable HTML (escaped) with a form to recalculate the deal.
	 *
	 * @param array $form Query args for the recalculation form: occurrence_id, fixed, share (raw input), nonce.
	 */
	public static function html( array $sheet, array $form = array() ) {
		$cur = $sheet['currency'];
		$money = static function ( $minor ) use ( $cur ) { return Scope::money( $minor, $cur ); };
		$o = $sheet['occurrence'];
		$e = static function ( $text ) { return esc_html( (string) $text ); };
		$head = '<th scope="col" class="n">' . $e( __( 'Verkauft', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Erstattet', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Tickets netto', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Brutto', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Erstattet brutto', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Umsatz nach Erstattung', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'davon USt', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Netto', 'pass-captain' ) ) . '</th>';
		$cells = static function ( array $b ) use ( $money, $e ) {
			return '<td class="n">' . (int) $b['tickets'] . '</td><td class="n">' . (int) $b['refunded_tickets'] . '</td><td class="n">' . (int) $b['net_tickets'] . '</td><td class="n">' . $e( $money( $b['gross'] ) ) . '</td><td class="n">' . $e( $b['refunded'] ? '-' . $money( $b['refunded'] ) : $money( 0 ) ) . '</td><td class="n">' . $e( $money( $b['revenue'] ) ) . '</td><td class="n">' . $e( $money( $b['revenue_tax'] ) ) . '</td><td class="n">' . $e( $money( $b['revenue_net'] ) ) . '</td>';
		};
		$title = __( 'Abrechnungsblatt', 'pass-captain' ) . ' – ' . $o['event_name'] . ' – ' . $o['starts'];
		$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e( $title ) . '</title>'
			. '<style>@page{size:A4;margin:12mm}body{font:12px/1.4 system-ui,sans-serif;color:#000;max-width:60rem;margin:1rem auto;padding:0 1rem}table{width:100%;border-collapse:collapse;margin:.5rem 0 1rem}th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}th{background:#eee}.n{text-align:right}tfoot td,tfoot th{font-weight:bold}form{background:#f6f7f7;border:1px solid #c3c4c7;padding:.5rem 1rem;margin:1rem 0}form label{margin-right:1rem}.sign{display:flex;gap:3rem;margin-top:3rem}.sign div{flex:1;border-top:1px solid #000;padding-top:.25rem}@media print{form,.noprint{display:none}}</style></head><body>';
		$html .= '<h1>' . $e( __( 'Abrechnungsblatt', 'pass-captain' ) ) . '</h1>';
		$html .= '<p><strong>' . $e( $o['event_name'] ) . '</strong> · ' . $e( $o['starts'] ) . ( '' !== $o['location'] ? ' · ' . $e( $o['location'] ) : '' ) . ( 'scheduled' !== $o['status'] ? ' · ' . $e( __( 'abgesagt', 'pass-captain' ) ) : '' ) . '<br>' . $e( sprintf( /* translators: 1: date/time, 2: currency */ __( 'Stand: %1$s · Beträge in %2$s, brutto inkl. USt, Erstattungen abgezogen.', 'pass-captain' ), Scope::local( gmdate( 'Y-m-d H:i:s' ), Scope::site_timezone() ), $sheet['currency'] ) ) . '</p>';

		if ( $form ) {
			$html .= '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( (string) ( $form['nonce'] ?? '' ) ) . '"><input type="hidden" name="occurrence_id" value="' . (int) $o['occurrence_id'] . '">'
				. '<p><strong>' . $e( __( 'Deal berechnen (wird nicht gespeichert)', 'pass-captain' ) ) . '</strong></p><p>'
				. '<label for="wpcap-deal-fixed">' . $e( __( 'Fixbetrag', 'pass-captain' ) ) . '</label> <input id="wpcap-deal-fixed" type="text" inputmode="decimal" name="fixed" size="10" value="' . esc_attr( (string) ( $form['fixed'] ?? '' ) ) . '" aria-describedby="wpcap-deal-help"> '
				. '<label for="wpcap-deal-share">' . $e( __( 'Anteil %', 'pass-captain' ) ) . '</label> <input id="wpcap-deal-share" type="text" inputmode="decimal" name="share" size="6" value="' . esc_attr( (string) ( $form['share'] ?? '' ) ) . '" aria-describedby="wpcap-deal-help"> '
				. '<button name="format" value="html">' . $e( __( 'Neu berechnen', 'pass-captain' ) ) . '</button> <button name="format" value="csv">' . $e( __( 'CSV-Export', 'pass-captain' ) ) . '</button></p>'
				. '<p id="wpcap-deal-help">' . $e( __( 'Vom Netto-Umsatz nach Erstattungen wird zuerst der Fixbetrag abgezogen (z. B. Miete/Kosten des Hauses); vom Rest erhält der Künstler/Veranstalter den Anteil in Prozent.', 'pass-captain' ) ) . '</p></form>';
		}

		$html .= '<h2>' . $e( __( 'Verkäufe nach Ticketart und Kanal', 'pass-captain' ) ) . '</h2><table><thead><tr><th scope="col">' . $e( __( 'Ticketart', 'pass-captain' ) ) . '</th><th scope="col">' . $e( __( 'Kanal', 'pass-captain' ) ) . '</th>' . $head . '</tr></thead><tbody>';
		foreach ( $sheet['types'] as $r ) { $html .= '<tr><td>' . $e( $r['ticket_type'] ) . '</td><td>' . $e( $r['channel_label'] ) . '</td>' . $cells( $r ) . '</tr>'; }
		if ( ! $sheet['types'] ) { $html .= '<tr><td colspan="10">' . $e( __( 'Keine Verkäufe.', 'pass-captain' ) ) . '</td></tr>'; }
		$html .= '</tbody><tfoot><tr><th scope="row" colspan="2">' . $e( __( 'Gesamt', 'pass-captain' ) ) . '</th>' . $cells( $sheet['total'] ) . '</tr></tfoot></table>';

		$html .= '<h2>' . $e( __( 'Nach Verkaufskanal', 'pass-captain' ) ) . '</h2><table><thead><tr><th scope="col">' . $e( __( 'Kanal', 'pass-captain' ) ) . '</th>' . $head . '</tr></thead><tbody>';
		foreach ( $sheet['channels'] as $r ) { $html .= '<tr><td>' . $e( $r['label'] ) . '</td>' . $cells( $r ) . '</tr>'; }
		$html .= '</tbody></table>';

		$html .= '<h2>' . $e( __( 'Umsatzsteuer nach Satz', 'pass-captain' ) ) . '</h2><table><thead><tr><th scope="col">' . $e( __( 'USt-Satz', 'pass-captain' ) ) . '</th>' . $head . '</tr></thead><tbody>';
		foreach ( $sheet['rates'] as $r ) { $html .= '<tr><td>' . $e( $r['label'] ) . '</td>' . $cells( $r ) . '</tr>'; }
		$html .= '</tbody></table>';

		$html .= '<h2>' . $e( __( 'Kasse (Abendkasse) nach Zahlungsart', 'pass-captain' ) ) . '</h2><table><thead><tr><th scope="col">' . $e( __( 'Zahlungsart', 'pass-captain' ) ) . '</th>' . $head . '</tr></thead><tbody>';
		foreach ( $sheet['box_office'] as $r ) { $html .= '<tr><td>' . $e( $r['label'] ) . '</td>' . $cells( $r ) . '</tr>'; }
		if ( ! $sheet['box_office'] ) { $html .= '<tr><td colspan="9">' . $e( __( 'Keine Kassenverkäufe.', 'pass-captain' ) ) . '</td></tr>'; }
		$html .= '</tbody></table>';

		$html .= '<h2>' . $e( __( 'Freikarten (nicht im Umsatz)', 'pass-captain' ) ) . '</h2><table><thead><tr><th scope="col">' . $e( __( 'Ticketart', 'pass-captain' ) ) . '</th><th scope="col" class="n">' . $e( __( 'Tickets', 'pass-captain' ) ) . '</th></tr></thead><tbody>';
		foreach ( $sheet['comps'] as $c ) { $html .= '<tr><td>' . $e( $c['name'] ) . '</td><td class="n">' . (int) $c['tickets'] . '</td></tr>'; }
		$html .= '</tbody><tfoot><tr><th scope="row">' . $e( __( 'Gesamt', 'pass-captain' ) ) . '</th><td class="n">' . (int) $sheet['comps_total'] . '</td></tr></tfoot></table>';

		$a = $sheet['attendance'];
		$html .= '<h2>' . $e( __( 'Besucher', 'pass-captain' ) ) . '</h2><table><tbody>'
			. '<tr><th scope="row">' . $e( __( 'Kapazität', 'pass-captain' ) ) . '</th><td class="n">' . $e( $o['capacity'] ?: '∞' ) . '</td></tr>'
			. '<tr><th scope="row">' . $e( __( 'Gültige Tickets (inkl. Freikarten)', 'pass-captain' ) ) . '</th><td class="n">' . (int) $a['issued'] . '</td></tr>'
			. '<tr><th scope="row">' . $e( __( 'Eingecheckt', 'pass-captain' ) ) . '</th><td class="n">' . (int) $a['checked_in'] . '</td></tr>'
			. '<tr><th scope="row">' . $e( __( 'No-Shows', 'pass-captain' ) ) . '</th><td class="n">' . (int) $a['no_shows'] . '</td></tr></tbody></table>';

		$d = $sheet['deal'];
		$html .= '<h2>' . $e( __( 'Deal', 'pass-captain' ) ) . '</h2><table><tbody>'
			. '<tr><th scope="row">' . $e( __( 'Netto-Umsatz nach Erstattungen', 'pass-captain' ) ) . '</th><td class="n">' . $e( $money( $d['basis'] ) ) . '</td></tr>'
			. '<tr><th scope="row">' . $e( __( 'abzüglich Fixbetrag', 'pass-captain' ) ) . '</th><td class="n">' . $e( '-' . $money( $d['fixed'] ) ) . '</td></tr>'
			. '<tr><th scope="row">' . $e( __( 'Bemessungsgrundlage', 'pass-captain' ) ) . '</th><td class="n">' . $e( $money( $d['after_fixed'] ) ) . '</td></tr>'
			. '<tr><th scope="row">' . $e( sprintf( /* translators: %s: percentage */ __( 'Anteil Künstler/Veranstalter (%s %%)', 'pass-captain' ), number_format_i18n( $d['share_bp'] / 100, 2 ) ) ) . '</th><td class="n">' . $e( $money( $d['artist'] ) ) . '</td></tr>'
			. '<tr><th scope="row">' . $e( __( 'Verbleibt Spielstätte/Haus', 'pass-captain' ) ) . '</th><td class="n">' . $e( $money( $d['house'] ) ) . '</td></tr></tbody></table>';

		$html .= '<div class="sign"><div>' . $e( __( 'Spielstätte/Veranstalter', 'pass-captain' ) ) . '</div><div>' . $e( __( 'Künstler/Tourneeleitung', 'pass-captain' ) ) . '</div></div>';
		$html .= '<p class="noprint"><button onclick="window.print()">' . $e( __( 'Drucken', 'pass-captain' ) ) . '</button></p></body></html>';
		return $html;
	}

	/** admin-post.php?action=wpcap_settlement_sheet&occurrence_id=…&fixed=…&share=…&format=html|csv&_wpnonce=… */
	public static function handle() {
		check_admin_referer( self::ACTION );
		$in = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$occurrence_id = absint( $in['occurrence_id'] ?? 0 );
		if ( ! self::can_view( $occurrence_id ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$deal = self::parse_deal( $in );
		$sheet = self::build( $occurrence_id, $deal );
		if ( ! $sheet ) { wp_die( esc_html__( 'Termin nicht gefunden.', 'pass-captain' ), 404 ); }
		$format = 'csv' === ( $in['format'] ?? '' ) ? 'csv' : 'html';
		Audit::record( 'settlement_sheet_' . $format, 'occurrence', $occurrence_id, $deal );
		if ( 'csv' === $format ) {
			Scope::send_download( Scope::csv( self::csv_rows( $sheet ) ), 'abrechnung-termin-' . $occurrence_id . '.csv', 'text/csv; charset=utf-8' );
		}
		nocache_headers();
		echo self::html( $sheet, array( 'nonce' => wp_create_nonce( self::ACTION ), 'fixed' => is_scalar( $in['fixed'] ?? '' ) ? sanitize_text_field( (string) ( $in['fixed'] ?? '' ) ) : '', 'share' => is_scalar( $in['share'] ?? '' ) ? sanitize_text_field( (string) ( $in['share'] ?? '' ) ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		exit;
	}
}
