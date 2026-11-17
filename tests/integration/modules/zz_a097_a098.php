<?php
/**
 * A097: settlement sheet per occurrence (door deal): sales per ticket type and channel, comps, VAT per
 * rate, refunds, box office per payment method, check-ins/no-shows, deal split; event-scoped access.
 * A098: accounting export with local-time day boundaries and sales/refunds of orders without invoice.
 * Included from run.php (uses check(), tickets() and the ex_* helpers of p3_exchange.php).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Reports\Settlement;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a97_saved_settings = get_option( Settings::OPTION );
$GLOBALS['wpcap_test_denied_caps'] = array();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0 ) );

$a97_provider = static function ( $order_id, $provider ) use ( $wpdb, $p ) {
	$wpdb->update( "{$p}wpcap_orders", array( 'payment_provider' => $provider ), array( 'order_id' => $order_id ) );
};

echo "A097: settlement sheet per occurrence\n";
$a97_event = ex_event( 'A097 Door Deal' );
$a97_occ = ex_occ( $a97_event, 100 );
$a97_std = ex_type( $a97_occ, 'Normal', 2380, 0, 1900 );
$a97_red = ex_type( $a97_occ, 'Ermäßigt', 1070, 0, 700 );
list( $a97_online, $a97_online_t ) = ex_buy( $a97_std, 2, 'a97-online@example.com' );
$a97_refund = Refunds::request( $a97_online, array( $a97_online_t[0] ), 'Storno', 'a97-refund' );
list( $a97_cash, $a97_cash_t ) = ex_buy( $a97_std, 1, 'a97-cash@example.com' );
$a97_provider( $a97_cash, 'pos_cash' );
list( $a97_card ) = ex_buy( $a97_red, 1, 'a97-card@example.com' );
$a97_provider( $a97_card, 'pos_card' );
list( $a97_partner ) = ex_buy( $a97_red, 1, 'a97-partner@example.com' );
$a97_provider( $a97_partner, 'partner' );
list( $a97_comp ) = ex_buy( $a97_std, 2, 'a97-comp@example.com' );
$a97_provider( $a97_comp, 'comp' );
foreach ( array( $a97_online_t[1], $a97_cash_t[0] ) as $a97_tid ) {
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $a97_tid, 'event_id' => $a97_event, 'user_id' => 1, 'result' => 'checked_in', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a97_tid ) );
}

$a97_deal = Settlement::parse_deal( array( 'fixed' => '1.000,00', 'share' => '70' ) );
check( 'A097: deal inputs are parsed (German decimals, percent → basis points)', array( 'fixed' => 100000, 'share_bp' => 7000 ) === $a97_deal && array( 'fixed' => 150050, 'share_bp' => 10000 ) === Settlement::parse_deal( array( 'fixed' => '1500.50', 'share' => '150' ) ) && array( 'fixed' => 0, 'share_bp' => 0 ) === Settlement::parse_deal( array( 'fixed' => 'abc', 'share' => array( 1 ) ) ) );
$a97_deal = array( 'fixed' => 1000, 'share_bp' => 7000 );
$a97 = Settlement::build( $a97_occ, $a97_deal );
$a97_cell = static function ( $sheet, $type, $channel ) {
	foreach ( $sheet['types'] as $r ) { if ( $r['ticket_type_id'] === $type && $r['channel'] === $channel ) { return $r; } }
	return null;
};
$a97_on = $a97_cell( $a97, $a97_std, 'online' );
$a97_pos_std = $a97_cell( $a97, $a97_std, 'pos' );
$a97_pos_red = $a97_cell( $a97, $a97_red, 'pos' );
$a97_par = $a97_cell( $a97, $a97_red, 'partner' );
check( 'A097: sales per ticket type and channel (online 2 sold/1 refunded, Kasse, Partner)', $a97_on && 2 === $a97_on['tickets'] && 1 === $a97_on['refunded_tickets'] && 4760 === $a97_on['gross'] && 2380 === $a97_on['refunded'] && 2380 === $a97_on['revenue'] && 2000 === $a97_on['revenue_net'] && $a97_pos_std && 2380 === $a97_pos_std['gross'] && $a97_pos_red && 1070 === $a97_pos_red['gross'] && $a97_par && 1 === $a97_par['tickets'] && 1000 === $a97_par['revenue_net'] && 4 === count( $a97['types'] ) );
check( 'A097: comps are listed separately and are not in the revenue', 2 === $a97['comps_total'] && 2 === ( $a97['comps'][ $a97_std ]['tickets'] ?? 0 ) && 5 === $a97['total']['tickets'] );
check( 'A097: totals after refunds (gross 92,80 − 23,80 = 69,00; VAT 9,00; net 60,00)', 9280 === $a97['total']['gross'] && 2380 === $a97['total']['refunded'] && 6900 === $a97['total']['revenue'] && 900 === $a97['total']['revenue_tax'] && 6000 === $a97['total']['revenue_net'] );
check( 'A097: VAT per rate (19 %: 47,60 / 7,60; 7 %: 21,40 / 1,40)', 4760 === $a97['rates'][1900]['revenue'] && 760 === $a97['rates'][1900]['revenue_tax'] && 4000 === $a97['rates'][1900]['revenue_net'] && 2140 === $a97['rates'][700]['revenue'] && 140 === $a97['rates'][700]['revenue_tax'] );
check( 'A097: channel totals match the sales channels', 2380 === $a97['channels']['online']['revenue'] && 3450 === $a97['channels']['pos']['revenue'] && 1070 === $a97['channels']['partner']['revenue'] && 0 === $a97['channels']['woocommerce']['revenue'] );
check( 'A097: box office per payment method (Bar 23,80, Karte 10,70)', 2380 === ( $a97['box_office']['pos_cash']['revenue'] ?? 0 ) && 1070 === ( $a97['box_office']['pos_card']['revenue'] ?? 0 ) && 'Bar' === $a97['box_office']['pos_cash']['label'] && 2 === count( $a97['box_office'] ) );
check( 'A097: attendance (6 valid tickets incl. comps, 2 checked in, 4 no-shows, capacity 100)', 6 === $a97['attendance']['issued'] && 2 === $a97['attendance']['checked_in'] && 4 === $a97['attendance']['no_shows'] && 100 === $a97['occurrence']['capacity'] );
check( 'A097: deal = fixed amount first, then share of the rest (60,00 − 10,00 = 50,00; 70 % = 35,00; house 25,00)', array( 'basis' => 6000, 'fixed' => 1000, 'after_fixed' => 5000, 'share_bp' => 7000, 'artist' => 3500, 'house' => 2500 ) === $a97['deal'] );
check( 'A097: fixed amount above the net revenue leaves no share', 0 === Settlement::deal( 6000, 9000, 5000 )['artist'] && 6000 === Settlement::deal( 6000, 9000, 5000 )['house'] );
$a97_report = PassCaptain\Reports\Data::build( array( 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'event_id' => $a97_event ), null );
check( 'A097: sheet reconciles with the sales report of the event', $a97_report['total']['revenue'] === $a97['total']['revenue'] && $a97_report['total']['revenue_tax'] === $a97['total']['revenue_tax'] && $a97_report['comps']['tickets'] === $a97['comps_total'] );

$a97_csv = Settlement::csv_rows( $a97 );
$a97_csv_labels = array_column( $a97_csv, 0 );
$a97_deal_row = array_values( array_filter( $a97_csv, static fn( $r ) => 'Anteil Künstler/Veranstalter' === ( $r[0] ?? '' ) ) );
check( 'A097: CSV has type/channel rows, box office, comps, no-shows and the deal', in_array( 'Ermäßigt', $a97_csv_labels, true ) && in_array( 'No-Shows', $a97_csv_labels, true ) && in_array( 'Freikarten (nicht im Umsatz)', $a97_csv_labels, true ) && $a97_deal_row && '35.00' === $a97_deal_row[0][1] && false !== strpos( PassCaptain\Reports\Scope::csv( $a97_csv ), 'Kasse;Bar;1;0;1;23.80' ) );
$a97_html = Settlement::html( $a97, array( 'nonce' => 'n', 'fixed' => '10,00', 'share' => '70' ) );
check( 'A097: print view shows the sections and the recalculation form (labelled inputs)', false !== strpos( $a97_html, 'Abrechnungsblatt' ) && false !== strpos( $a97_html, 'Kasse (Abendkasse) nach Zahlungsart' ) && false !== strpos( $a97_html, 'No-Shows' ) && false !== strpos( $a97_html, '<label for="wpcap-deal-fixed">' ) && false !== strpos( $a97_html, 'value="10,00"' ) && false !== strpos( $a97_html, 'value="wpcap_settlement_sheet"' ) );
$a97_xss_event = ex_event( '<script>alert(1)</script>' );
$a97_xss = Settlement::build( ex_occ( $a97_xss_event ) );
check( 'A097: empty occurrence renders, names are escaped', $a97_xss && 0 === $a97_xss['total']['revenue'] && false === strpos( Settlement::html( $a97_xss ), '<script>alert' ) && null === Settlement::build( 999999999 ) );

// Event scope: report users only see assigned events.
check( 'A097: administrators may open every sheet', Settlement::can_view( $a97_occ ) && isset( Settlement::occurrence_options()[ $a97_occ ] ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance' );
$wpdb->delete( "{$p}wpcap_event_staff", array( 'event_id' => $a97_event, 'user_id' => 1 ) );
check( 'A097: report users without assignment cannot open the sheet', ! Settlement::can_view( $a97_occ ) && ! isset( Settlement::occurrence_options()[ $a97_occ ] ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a97_event, 'user_id' => 1, 'capability' => 'wpcap_view_reports' ) );
check( 'A097: report users assigned to the event may open it, but not other events', Settlement::can_view( $a97_occ ) && isset( Settlement::occurrence_options()[ $a97_occ ] ) && ! Settlement::can_view( (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d", $a97_xss_event ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance', 'wpcap_view_reports' );
check( 'A097: users without report capability cannot open any sheet', ! Settlement::can_view( $a97_occ ) && array() === Settlement::occurrence_options() );
$wpdb->delete( "{$p}wpcap_event_staff", array( 'event_id' => $a97_event, 'user_id' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A097: handler is registered for admin-post', isset( PassCaptain\Modules\Reports::admin_handlers()[ Settlement::ACTION ] ) );

echo "A098: accounting export – local day boundaries, sales without invoice\n";
$a98_occ = ex_occ( ex_event( 'A098 Export' ) );
$a98_std = ex_type( $a98_occ, 'Normal', 2380, 0, 1900 );
$a98_red = ex_type( $a98_occ, 'Ermäßigt', 1070, 0, 700 );
$a98_set = static function ( $table, array $data, array $where ) use ( $wpdb, $p ) { $wpdb->update( "{$p}{$table}", $data, $where ); };
$a98_pub = static fn( $order_id ) => Orders::get( $order_id )->public_id;
// A: invoiced online sale at 2025-01-15 23:30 UTC = 2025-01-16 00:30 in Vienna.
list( $a98_a ) = ex_buy( $a98_std, 1, 'a98-a@example.com' );
$a98_inv = PassCaptain\Invoices::issue_for_order( $a98_a );
$a98_set( 'wpcap_orders', array( 'created_at' => '2025-01-15 23:30:00' ), array( 'order_id' => $a98_a ) );
$a98_set( 'wpcap_invoices', array( 'issued_at' => '2025-01-15 23:30:00' ), array( 'order_id' => $a98_a ) );
// B: box office cash sale without invoice (2 × 10,70 at 7 %), one ticket refunded the same day.
list( $a98_b, $a98_b_t ) = ex_buy( $a98_red, 2, 'a98-b@example.com' );
$a98_b_refund = Refunds::request( $a98_b, array( $a98_b_t[0] ), 'Storno', 'a98-b-refund' );
$a97_provider( $a98_b, 'pos_cash' );
$a98_set( 'wpcap_orders', array( 'created_at' => '2025-01-16 10:00:00' ), array( 'order_id' => $a98_b ) );
$a98_set( 'wpcap_refunds', array( 'updated_at' => '2025-01-16 12:00:00' ), array( 'order_id' => $a98_b ) );
// C: partner API sale at 23:59 local time (still 2025-01-16), D: 00:30 local on the next day, E: comp.
list( $a98_c ) = ex_buy( $a98_std, 1, 'a98-c@example.com' );
$a97_provider( $a98_c, 'partner' );
$a98_set( 'wpcap_orders', array( 'created_at' => '2025-01-16 22:59:00' ), array( 'order_id' => $a98_c ) );
list( $a98_d ) = ex_buy( $a98_std, 1, 'a98-d@example.com' );
$a98_set( 'wpcap_orders', array( 'created_at' => '2025-01-16 23:30:00' ), array( 'order_id' => $a98_d ) );
list( $a98_e ) = ex_buy( $a98_std, 1, 'a98-e@example.com' );
$a97_provider( $a98_e, 'comp' );
$a98_set( 'wpcap_orders', array( 'created_at' => '2025-01-16 11:00:00' ), array( 'order_id' => $a98_e ) );

$a98_by_order = static function ( array $rows ) {
	$out = array();
	foreach ( array_slice( $rows, 1 ) as $r ) { $out[ $r[3] ][] = $r; }
	return $out;
};
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
$a98_rows = PassCaptain\Invoices::export_rows( '2025-01-16', '2025-01-16' );
$a98_map = $a98_by_order( $a98_rows );
$a98_a_rows = $a98_map[ $a98_pub( $a98_a ) ] ?? array();
$a98_b_rows = $a98_map[ $a98_pub( $a98_b ) ] ?? array();
$a98_c_rows = $a98_map[ $a98_pub( $a98_c ) ] ?? array();
check( 'A098: header unchanged (15 columns, Rechnungssteller last)', 15 === count( $a98_rows[0] ) && 'Belegart' === $a98_rows[0][0] && 'Rechnungssteller' === end( $a98_rows[0] ) );
check( 'A098: invoice issued 23:30 UTC belongs to the next local day (Belegdatum 2025-01-16)', 1 === count( $a98_a_rows ) && 'Rechnung' === $a98_a_rows[0][0] && $a98_inv['invoice_number'] === $a98_a_rows[0][1] && '2025-01-16' === $a98_a_rows[0][2] && '23.80' === $a98_a_rows[0][10] );
$a98_b_sale = array_values( array_filter( $a98_b_rows, static fn( $r ) => 'Verkauf ohne Rechnung' === $r[0] ) );
$a98_b_ref = array_values( array_filter( $a98_b_rows, static fn( $r ) => 'Erstattung ohne Rechnung' === $r[0] ) );
check( 'A098: box office sale without invoice is listed per VAT rate (2 × 10,70 at 7 %)', 1 === count( $a98_b_sale ) && '' === $a98_b_sale[0][1] && '2025-01-16' === $a98_b_sale[0][2] && 2 === $a98_b_sale[0][6] && '20.00' === $a98_b_sale[0][7] && '7.00' === $a98_b_sale[0][8] && '1.40' === $a98_b_sale[0][9] && '21.40' === $a98_b_sale[0][10] && 'pos_cash' === $a98_b_sale[0][12] && false !== strpos( $a98_b_sale[0][5], 'Kasse' ) && 'Eigenhaendler' === $a98_b_sale[0][13] );
check( 'A098: refund of an order without invoice is listed negative on its completion day', ! is_wp_error( $a98_b_refund ) && 1 === count( $a98_b_ref ) && '-10.70' === $a98_b_ref[0][10] && '-0.70' === $a98_b_ref[0][9] && '-10.00' === $a98_b_ref[0][7] && '7.00' === $a98_b_ref[0][8] );
check( 'A098: partner API sale at 23:59 local time is included', 1 === count( $a98_c_rows ) && 'Verkauf ohne Rechnung' === $a98_c_rows[0][0] && 'partner' === $a98_c_rows[0][12] && '23.80' === $a98_c_rows[0][10] );
check( 'A098: sale at 00:30 local of the next day and comps are not included', ! isset( $a98_map[ $a98_pub( $a98_d ) ] ) && ! isset( $a98_map[ $a98_pub( $a98_e ) ] ) && isset( $a98_by_order( PassCaptain\Invoices::export_rows( '2025-01-17', '2025-01-17' ) )[ $a98_pub( $a98_d ) ] ) );
$a98_sum = array_sum( array_map( static fn( $r ) => (int) round( (float) $r[10] * 100 ), array_slice( $a98_rows, 1 ) ) );
$a98_tax = array_sum( array_map( static fn( $r ) => (int) round( (float) $r[9] * 100 ), array_slice( $a98_rows, 1 ) ) );
$a98_report = PassCaptain\Reports\Data::build( array( 'from' => '2025-01-16', 'to' => '2025-01-16' ), null );
check( 'A098: export reconciles with the sales report of the day (58,30 gross, VAT)', 5830 === $a98_sum && $a98_report['total']['revenue'] === $a98_sum && $a98_report['total']['revenue_tax'] === $a98_tax );
unset( $GLOBALS['wpcap_test_options']['timezone_string'] );
$a98_utc = $a98_by_order( PassCaptain\Invoices::export_rows( '2025-01-16', '2025-01-16' ) );
check( 'A098: without a site timezone the days are UTC days', ! isset( $a98_utc[ $a98_pub( $a98_a ) ] ) && isset( $a98_utc[ $a98_pub( $a98_d ) ] ) );
PassCaptain\Invoices::issue_for_order( $a98_c );
$a98_after = $a98_by_order( PassCaptain\Invoices::export_rows( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ) );
check( 'A098: once invoiced, the order is only listed with its invoice', 'Rechnung' === ( $a98_after[ $a98_pub( $a98_c ) ][0][0] ?? '' ) && 1 === count( $a98_after[ $a98_pub( $a98_c ) ] ) && ! isset( $a98_by_order( PassCaptain\Invoices::export_rows( '2025-01-16', '2025-01-16' ) )[ $a98_pub( $a98_c ) ] ) );

update_option( Settings::OPTION, $a97_saved_settings );
Settings::flush();
