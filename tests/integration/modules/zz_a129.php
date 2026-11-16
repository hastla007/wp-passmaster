<?php
/**
 * A129: functional acceptance of reports, accounting export and correction documents.
 *
 * Mixed tax rates (19 %, 7 %, 0 %), partial refunds (tickets and amount-only), a full correction
 * (Storno), an order without invoice and a second currency (CHF): the sales report reconciles
 * (sales − refunds = revenue, every grouping and every currency adds up), report refunds per rate
 * equal the rows of the correction documents, the accounting export carries signs, references,
 * currencies and gap-free numbers and its totals per currency and rate equal the report, and the
 * DATEV-style booking batch agrees with the export. Included from run.php (helpers from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Scope;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

echo "A129: reports, accounting export and correction documents\n";
$a129_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'invoice_prefix' => 'WPA129', 'credit_note_prefix' => 'WRK129' ) );
$a129_tz = Scope::site_timezone();
$a129_day = static function ( $offset ) use ( $a129_tz ) { return Scope::local( gmdate( 'Y-m-d H:i:s', time() + $offset * DAY_IN_SECONDS ), $a129_tz, 'Y-m-d' ); };
$a129_from = $a129_day( -1 );
$a129_to = $a129_day( 1 );

// One event with EUR types at 19 %, 7 %, 0 % and a CHF type at 8.1 %.
$a129_event = ex_event( 'A129 Abnahme' );
$a129_occ = ex_occ( $a129_event );
$a129_std = ex_type( $a129_occ, 'Standard 19 %', 2380, 0, 1900 );
$a129_red = ex_type( $a129_occ, 'Ermäßigt 7 %', 1070, 0, 700 );
$a129_free = ex_type( $a129_occ, 'Steuerfrei', 1500, 0, 0 );
$a129_chf = ex_type( $a129_occ, 'Schweiz', 3243, 0, 810 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'currency' => 'CHF' ), array( 'ticket_type_id' => $a129_chf ) );

function a129_buy( array $items, $email ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => $email, 'items' => $items, 'idempotency_key' => 'a129-' . uniqid( '', true ) ) );
	if ( is_wp_error( $r ) ) { return 0; }
	if ( 'paid' !== $r['payment_status'] ) { Orders::confirm_external_payment( $r['order_id'], 'test' ); }
	return (int) $r['order_id'];
}
function a129_tickets( $order_id ) {
	$out = array();
	foreach ( tickets( $order_id, 'valid' ) as $t ) { $out[ (int) $t->ticket_type_id ][] = (int) $t->ticket_id; }
	return $out;
}
function a129_amount_refund( $order_id, $amount, $key ) {
	$r = Refunds::request_amount( $order_id, $amount, 'Kulanz', $key, array( 'system' => true ) );
	if ( is_wp_error( $r ) ) { return 0; }
	if ( 'completed' !== ( Refunds::get( $r['refund_id'] )['status'] ?? '' ) ) { Refunds::approve( $r['refund_id'] ); }
	return (int) $r['refund_id'];
}

// A: mixed rates, invoiced; partial ticket refund (19 % + 7 %) and amount-only goodwill refund.
$a129_a = a129_buy( array( array( 'ticket_type_id' => $a129_std, 'quantity' => 2 ), array( 'ticket_type_id' => $a129_red, 'quantity' => 2 ), array( 'ticket_type_id' => $a129_free, 'quantity' => 1 ) ), 'a129-a@example.com' );
$a129_a_order = Orders::get( $a129_a );
check( 'A129 order A: 2×23,80 (19 %) + 2×10,70 (7 %) + 15,00 (0 %), VAT 7,60 + 1,40', 8400 === (int) $a129_a_order->total_minor && 900 === (int) $a129_a_order->tax_minor && 'EUR' === $a129_a_order->currency );
$a129_a_inv = Invoices::issue_for_order( $a129_a );
$a129_a_t = a129_tickets( $a129_a );
$a129_a_r1 = Refunds::request( $a129_a, array( $a129_a_t[ $a129_std ][0], $a129_a_t[ $a129_red ][0] ), 'Teil', 'a129-a-r1' );
$a129_a_cn1 = Invoices::credit_note_for_refund( $a129_a_r1['refund_id'] );
$a129_a_r2 = a129_amount_refund( $a129_a, 1001, 'a129-a-r2' );
$a129_a_cn2 = $a129_a_r2 ? Invoices::credit_note_for_refund( $a129_a_r2 ) : null;

// B: full correction (Storno) of a mixed-rate invoice.
$a129_b = a129_buy( array( array( 'ticket_type_id' => $a129_std, 'quantity' => 1 ), array( 'ticket_type_id' => $a129_red, 'quantity' => 1 ) ), 'a129-b@example.com' );
$a129_b_inv = Invoices::issue_for_order( $a129_b );
$a129_b_r = Refunds::request( $a129_b, array(), 'Storno', 'a129-b-r' );
$a129_b_cn = Invoices::credit_note_for_refund( $a129_b_r['refund_id'] );

// C: second currency (CHF), invoiced, one ticket refunded.
$a129_c = a129_buy( array( array( 'ticket_type_id' => $a129_chf, 'quantity' => 3 ) ), 'a129-c@example.com' );
$a129_c_order = Orders::get( $a129_c );
$a129_c_inv = Invoices::issue_for_order( $a129_c );
$a129_c_t = a129_tickets( $a129_c );
$a129_c_r = Refunds::request( $a129_c, array( $a129_c_t[ $a129_chf ][0] ), 'Teil', 'a129-c-r' );
$a129_c_cn = Invoices::credit_note_for_refund( $a129_c_r['refund_id'] );
check( 'A129 order C is a CHF order with 8.1 % VAT', 'CHF' === $a129_c_order->currency && 9729 === (int) $a129_c_order->total_minor && is_array( $a129_c_cn ) && 'CHF' === $a129_c_cn['currency'] );

// D: no invoice (e.g. box office / auto invoice off); amount-only partial refund.
$a129_d = a129_buy( array( array( 'ticket_type_id' => $a129_std, 'quantity' => 1 ), array( 'ticket_type_id' => $a129_free, 'quantity' => 1 ) ), 'a129-d@example.com' );
$a129_d_r = a129_amount_refund( $a129_d, 500, 'a129-d-r' );

check( 'A129 all documents were issued', is_array( $a129_a_inv ) && is_array( $a129_a_cn1 ) && is_array( $a129_a_cn2 ) && is_array( $a129_b_inv ) && is_array( $a129_b_cn ) && is_array( $a129_c_inv ) && $a129_d_r > 0 );

$a129_orders = array( $a129_a, $a129_b, $a129_c, $a129_d );
$a129_pub = array();
foreach ( $a129_orders as $a129_o ) { $a129_pub[ (string) Orders::get( $a129_o )->public_id ] = $a129_o; }

echo "A129: correction documents\n";
$a129_sum_ok = static function ( $cn, $rates ) {
	return array_sum( array_column( $rates, 'gross' ) ) === (int) $cn['total_minor'] && array_sum( array_column( $rates, 'tax' ) ) === (int) $cn['tax_minor'] && array_sum( array_column( $rates, 'net' ) ) === (int) $cn['net_minor'];
};
$a129_rates1 = Invoices::credit_rates( $a129_a_cn1 );
check( 'A129 partial ticket correction: 7 % row 10,70/0,70 and 19 % row 23,80/3,80', array( array( 'rate_bp' => 700, 'net' => 1000, 'tax' => 70, 'gross' => 1070 ), array( 'rate_bp' => 1900, 'net' => 2000, 'tax' => 380, 'gross' => 2380 ) ) === $a129_rates1 && $a129_sum_ok( $a129_a_cn1, $a129_rates1 ) );
$a129_rates2 = Invoices::credit_rates( $a129_a_cn2 );
$a129_rates2_by = array_column( $a129_rates2, null, 'rate_bp' );
check( 'A129 amount-only correction: all three rates, 0 % row without VAT, rows add up to the odd-cent total', 3 === count( $a129_rates2 ) && 0 === $a129_rates2_by[0]['tax'] && $a129_rates2_by[0]['gross'] > 0 && 1001 === (int) $a129_a_cn2['total_minor'] && $a129_sum_ok( $a129_a_cn2, $a129_rates2 ) );
$a129_b_lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", $a129_b_inv['invoice_id'] ) );
$a129_b_inv_rates = array();
foreach ( Invoices::rate_summary( $a129_b_lines ) as $a129_rate => $a129_s ) { $a129_b_inv_rates[] = array( 'rate_bp' => (int) round( (float) $a129_rate * 100 ), 'net' => $a129_s['net'], 'tax' => $a129_s['tax'], 'gross' => $a129_s['gross'] ); }
check( 'A129 full correction (Storno) mirrors the invoice per rate', $a129_b_inv_rates === Invoices::credit_rates( $a129_b_cn ) && (int) $a129_b_cn['total_minor'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT total_minor FROM {$p}wpcap_invoices WHERE invoice_id=%d", $a129_b_inv['invoice_id'] ) ) );
check( 'A129 every correction references its invoice', (int) $a129_a_cn1['invoice_id'] === (int) $a129_a_inv['invoice_id'] && (int) $a129_b_cn['invoice_id'] === (int) $a129_b_inv['invoice_id'] && (int) $a129_c_cn['invoice_id'] === (int) $a129_c_inv['invoice_id'] );

echo "A129: refund split shared by report and correction documents\n";
$a129_lines = Data::lines_by_order( array( $a129_a ) )[ $a129_a ];
$a129_split_by_rate = static function ( $refund_id ) use ( $wpdb, $p, $a129_lines ) {
	$refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE refund_id=%d", $refund_id ) );
	$out = array();
	foreach ( Data::refund_split( $refund, $a129_lines ) as $part ) {
		$rate = (int) $a129_lines[ $part['index'] ]->tax_rate_bp;
		$out[ $rate ] = array( 'gross' => ( $out[ $rate ]['gross'] ?? 0 ) + $part['amount'], 'tax' => ( $out[ $rate ]['tax'] ?? 0 ) + $part['tax'] );
	}
	ksort( $out );
	return $out;
};
$a129_rates_map = static function ( array $rates ) { $out = array(); foreach ( $rates as $r ) { $out[ $r['rate_bp'] ] = array( 'gross' => $r['gross'], 'tax' => $r['tax'] ); } return $out; };
check( 'A129 ticket refund: report split per rate equals the correction document', $a129_rates_map( $a129_rates1 ) === $a129_split_by_rate( $a129_a_r1['refund_id'] ) );
check( 'A129 amount-only refund: report split per rate equals the correction (no VAT on the 0 % line)', $a129_rates_map( $a129_rates2 ) === $a129_split_by_rate( $a129_a_r2 ) );
$a129_parts = Invoices::refund_line_parts( array( 'ticket_ids' => '[]' ), $a129_lines, 0, 777, 55 );
check( 'A129 refund_line_parts: parts add up exactly, tickets 0 for amount-only', 777 === array_sum( array_column( $a129_parts, 'gross' ) ) && 55 === array_sum( array_column( $a129_parts, 'tax' ) ) && 0 === array_sum( array_column( $a129_parts, 'tickets' ) ) );

echo "A129: sales report reconciles\n";
$a129_report = Data::build( array( 'from' => $a129_from, 'to' => $a129_to, 'event_id' => $a129_event ), null );
$a129_t = $a129_report['total'];
check( 'A129 report: reconcile() finds no differences', array() === Data::reconcile( $a129_report ) );
check( 'A129 report: sales − refunds = revenue, revenue − VAT = net after refunds', $a129_t['gross'] - $a129_t['refunded'] === $a129_t['revenue'] && $a129_t['revenue'] - $a129_t['revenue_tax'] === $a129_t['revenue_net'] && $a129_t['tax'] - $a129_t['refunded_tax'] === $a129_t['revenue_tax'] );
check( 'A129 report: currencies EUR and CHF reported separately', array( 'CHF', 'EUR' ) === array_keys( $a129_report['currencies'] ) && in_array( $a129_report['currency'], array( 'CHF/EUR', 'EUR/CHF' ), true ) );
$a129_eur = $a129_report['currencies']['EUR']['total'];
$a129_chf_t = $a129_report['currencies']['CHF']['total'];
// EUR sales: A 84,00 + B 34,50 + D 38,80 = 157,30; refunds: 34,50 + 10,01 + 34,50 + 5,00 = 84,01.
check( 'A129 report EUR: sales 157,30, refunds 84,01, revenue 73,29', 15730 === $a129_eur['gross'] && 8401 === $a129_eur['refunded'] && 7329 === $a129_eur['revenue'] );
check( 'A129 report CHF: sales 97,29, one ticket refunded (32,43), revenue 64,86', 9729 === $a129_chf_t['gross'] && 3243 === $a129_chf_t['refunded'] && 1 === $a129_chf_t['refunded_tickets'] && 6486 === $a129_chf_t['revenue'] );
$a129_inv_rate_sums = array();
foreach ( array( $a129_a_inv, $a129_b_inv ) as $a129_inv ) {
	foreach ( Invoices::rate_summary( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", $a129_inv['invoice_id'] ) ) ) as $a129_rate => $a129_s ) {
		$a129_bp = (int) round( (float) $a129_rate * 100 );
		$a129_inv_rate_sums[ $a129_bp ] = array( 'gross' => ( $a129_inv_rate_sums[ $a129_bp ]['gross'] ?? 0 ) + $a129_s['gross'], 'tax' => ( $a129_inv_rate_sums[ $a129_bp ]['tax'] ?? 0 ) + $a129_s['tax'] );
	}
}
$a129_rep_sales = array();
foreach ( $a129_report['currencies']['EUR']['rates'] as $a129_bp => $a129_b ) { $a129_rep_sales[ $a129_bp ] = array( 'gross' => $a129_b['gross'], 'tax' => $a129_b['tax'] ); }
// D (not invoiced): 23,80 / 3,80 at 19 % and 15,00 / 0 at 0 %.
$a129_inv_rate_sums[1900]['gross'] += 2380; $a129_inv_rate_sums[1900]['tax'] += 380; $a129_inv_rate_sums[0]['gross'] += 1500;
ksort( $a129_inv_rate_sums );
check( 'A129 report sales per rate equal the invoice lines (+ the order without invoice)', $a129_inv_rate_sums === $a129_rep_sales );

echo "A129: accounting export\n";
$a129_rows = Invoices::export_rows( $a129_from, $a129_to );
check( 'A129 export: stable header (EXPORT_COLUMNS, 15 columns)', Invoices::EXPORT_COLUMNS === $a129_rows[0] && 15 === count( $a129_rows[0] ) && array( 'Belegart', 'Belegnummer', 'Belegdatum', 'Bestellung', 'Bezug' ) === array_slice( $a129_rows[0], 0, 5 ) );
$a129_mine = array_values( array_filter( array_slice( $a129_rows, 1 ), static fn( $r ) => isset( $a129_pub[ $r[3] ] ) ) );
$a129_kind = static fn( $kind ) => array_values( array_filter( $a129_mine, static fn( $r ) => $kind === $r[0] ) );
check( 'A129 export: every row has 15 cells and Netto + USt = Brutto', $a129_mine && ! array_filter( $a129_mine, static fn( $r ) => 15 !== count( $r ) || Invoices::decimal_to_minor( $r[7] ) + Invoices::decimal_to_minor( $r[9] ) !== Invoices::decimal_to_minor( $r[10] ) ) );
$a129_inv_rates_seen = array_values( array_unique( array_map( static fn( $r ) => $r[8], $a129_kind( 'Rechnung' ) ) ) );
sort( $a129_inv_rates_seen, SORT_NUMERIC );
check( 'A129 export: invoices positive, one row per invoice line with its rate', 6 === count( $a129_kind( 'Rechnung' ) ) && ! array_filter( $a129_kind( 'Rechnung' ), static fn( $r ) => Invoices::decimal_to_minor( $r[10] ) <= 0 || '' !== $r[4] ) && array( '0.00', '7.00', '8.10', '19.00' ) === $a129_inv_rates_seen );
$a129_inv_numbers = array( $a129_a_inv['invoice_number'], $a129_b_inv['invoice_number'], $a129_c_inv['invoice_number'] );
check( 'A129 export: corrections negative and referencing the corrected invoice', 8 === count( $a129_kind( 'Rechnungskorrektur' ) ) && ! array_filter( $a129_kind( 'Rechnungskorrektur' ), static fn( $r ) => Invoices::decimal_to_minor( $r[10] ) >= 0 || Invoices::decimal_to_minor( $r[9] ) > 0 || ! in_array( $r[4], $a129_inv_numbers, true ) ) );
$a129_cn_rows = array_values( array_filter( $a129_mine, static fn( $r ) => $r[1] === $a129_a_cn1['credit_note_number'] ) );
check( 'A129 export: correction rows equal the document breakdown (rate, net, VAT, gross)', array( array( '7.00', '-10.00', '-0.70', '-10.70', $a129_a_inv['invoice_number'] ), array( '19.00', '-20.00', '-3.80', '-23.80', $a129_a_inv['invoice_number'] ) ) === array_map( static fn( $r ) => array( $r[8], $r[7], $r[9], $r[10], $r[4] ), $a129_cn_rows ) );
check( 'A129 export: order without invoice as sale rows and a negative refund row per rate (0 % without VAT)', 2 === count( $a129_kind( 'Verkauf ohne Rechnung' ) ) && 2 === count( $a129_kind( 'Erstattung ohne Rechnung' ) ) && ! array_filter( $a129_kind( 'Erstattung ohne Rechnung' ), static fn( $r ) => Invoices::decimal_to_minor( $r[10] ) >= 0 || ( '0.00' === $r[8] && 0 !== Invoices::decimal_to_minor( $r[9] ) ) ) );
check( 'A129 export: currency per row (CHF rows only for the CHF order)', ! array_filter( $a129_mine, static fn( $r ) => ( $a129_c === $a129_pub[ $r[3] ] ) !== ( 'CHF' === $r[11] ) ) );
$a129_journal = Invoices::journal_totals( $a129_mine );
$a129_rep_rates = static function ( array $rates ) { $out = array(); foreach ( $rates as $bp => $b ) { $out[ number_format( $bp / 100, 2, '.', '' ) ] = array( 'net' => $b['revenue_net'], 'tax' => $b['revenue_tax'], 'gross' => $b['revenue'] ); } return $out; };
check( 'A129 export totals per currency equal the report revenue (gross, VAT, net)', array( 'CHF', 'EUR' ) === array_keys( $a129_journal ) && array( 'net' => $a129_eur['revenue_net'], 'tax' => $a129_eur['revenue_tax'], 'gross' => $a129_eur['revenue'] ) === $a129_journal['EUR']['total'] && array( 'net' => $a129_chf_t['revenue_net'], 'tax' => $a129_chf_t['revenue_tax'], 'gross' => $a129_chf_t['revenue'] ) === $a129_journal['CHF']['total'] );
check( 'A129 export totals per currency and rate equal the report rates', $a129_rep_rates( $a129_report['currencies']['EUR']['rates'] ) === $a129_journal['EUR']['rates'] && $a129_rep_rates( $a129_report['currencies']['CHF']['rates'] ) === $a129_journal['CHF']['rates'] );
check( 'A129 export: period filter excludes other days', ! array_filter( array_slice( Invoices::export_rows( '2001-01-01', '2001-01-31' ), 1 ), static fn( $r ) => isset( $a129_pub[ $r[3] ] ) ) && ! array_filter( array_slice( Invoices::export_rows( $a129_day( 3 ), $a129_day( 5 ) ), 1 ), static fn( $r ) => isset( $a129_pub[ $r[3] ] ) ) );
check( 'A129 decimal_to_minor is exact', -1230 === Invoices::decimal_to_minor( '-12.3' ) && 5 === Invoices::decimal_to_minor( '0.05' ) && 100000001 === Invoices::decimal_to_minor( '1000000.01' ) && 0 === Invoices::decimal_to_minor( 'x' ) );

echo "A129: gap-free document numbers\n";
$a129_numbers = array_merge( $a129_inv_numbers, array( $a129_a_cn1['credit_note_number'], $a129_a_cn2['credit_note_number'], $a129_b_cn['credit_note_number'], $a129_c_cn['credit_note_number'] ) );
check( 'A129 numbers use the configured prefixes and the year', ! array_filter( $a129_inv_numbers, static fn( $n ) => ! preg_match( '/^WPA129-\d{4}-\d{6}$/', $n ) ) && ! array_filter( array_slice( $a129_numbers, 3 ), static fn( $n ) => ! preg_match( '/^WRK129-\d{4}-\d{6}$/', $n ) ) );
check( 'A129 number_gaps() finds missing numbers per range', array( 'X-2026' => array( 'X-2026-000002', 'X-2026-000004' ) ) === Invoices::number_gaps( array( 'X-2026-000005', 'X-2026-000001', 'X-2026-000003', 'Y-2026-000007', 'Y-2026-000008', 'frei' ) ) );
$a129_gaps = array_filter( Invoices::document_gaps( $a129_from, $a129_to ), static fn( $k ) => 0 === strpos( $k, 'WPA129-' ) || 0 === strpos( $k, 'WRK129-' ), ARRAY_FILTER_USE_KEY );
check( 'A129 invoice and correction ranges of this run are gap-free', array() === $a129_gaps && array() === Invoices::number_gaps( $a129_numbers ) );

echo "A129: DATEV-style booking batch\n";
$a129_datev = Invoices::datev_rows( $a129_from, $a129_to );
$a129_docs = array_merge( $a129_numbers, array_keys( $a129_pub ) );
$a129_bookings = array_values( array_filter( array_slice( $a129_datev, 1 ), static fn( $r ) => in_array( $r[7], $a129_docs, true ) ) );
check( 'A129 DATEV: header with the booking batch fields', Invoices::DATEV_COLUMNS === $a129_datev[0] && 'Umsatz (ohne Soll/Haben-Kz)' === $a129_datev[0][0] && 'Belegfeld 2' === $a129_datev[0][8] );
$a129_a_bookings = array_values( array_filter( $a129_bookings, static fn( $r ) => $r[7] === $a129_a_inv['invoice_number'] ) );
check( 'A129 DATEV: invoice A booked per rate on SKR03 accounts (8400/8300/8200 against 1360), debit', 3 === count( $a129_a_bookings ) && array( '8400', '8300', '8200' ) === array_map( static fn( $r ) => $r[4], $a129_a_bookings ) && ! array_filter( $a129_a_bookings, static fn( $r ) => 'S' !== $r[1] || '1360' !== $r[3] || 'EUR' !== $r[2] ) && array( '47,60', '21,40', '15,00' ) === array_map( static fn( $r ) => $r[0], $a129_a_bookings ) );
$a129_cn_bookings = array_values( array_filter( $a129_bookings, static fn( $r ) => $r[7] === $a129_a_cn1['credit_note_number'] ) );
check( 'A129 DATEV: correction booked as credit with the corrected invoice in Belegfeld 2', 2 === count( $a129_cn_bookings ) && ! array_filter( $a129_cn_bookings, static fn( $r ) => 'H' !== $r[1] || $a129_a_inv['invoice_number'] !== $r[8] ) && array( '10,70', '23,80' ) === array_map( static fn( $r ) => $r[0], $a129_cn_bookings ) );
check( 'A129 DATEV: date as TTMM, CHF bookings with currency code', ! array_filter( $a129_bookings, static fn( $r ) => ! preg_match( '/^\d{4}$/', $r[6] ) ) && array_filter( $a129_bookings, static fn( $r ) => 'CHF' === $r[2] && $r[7] === $a129_c_inv['invoice_number'] && '8200' === $r[4] ) );
$a129_datev_sum = array();
foreach ( $a129_bookings as $a129_r ) { $a129_datev_sum[ $a129_r[2] ] = ( $a129_datev_sum[ $a129_r[2] ] ?? 0 ) + ( 'S' === $a129_r[1] ? 1 : -1 ) * Invoices::decimal_to_minor( str_replace( ',', '.', $a129_r[0] ) ); }
ksort( $a129_datev_sum );
check( 'A129 DATEV: debit − credit per currency equals the export and the report revenue', array( 'CHF' => $a129_chf_t['revenue'], 'EUR' => $a129_eur['revenue'] ) === $a129_datev_sum );
$GLOBALS['wpcap_test_filters']['wpcap_datev_accounts'] = array( 'revenue' => array( 1900 => '4400', 700 => '4300' ), 'revenue_default' => '4200', 'contra_by_provider' => array( (string) $a129_a_order->payment_provider => '1800' ), 'bu_keys' => array( 810 => '99' ), 'unknown' => 'x' );
$a129_datev2 = array_values( array_filter( array_slice( Invoices::datev_rows( $a129_from, $a129_to ), 1 ), static fn( $r ) => in_array( $r[7], array( $a129_a_inv['invoice_number'], $a129_c_inv['invoice_number'] ), true ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_datev_accounts'] );
check( 'A129 DATEV: accounts, BU keys and money account per provider via wpcap_datev_accounts', 4 === count( $a129_datev2 ) && array( '4400', '4300', '4200', '4200' ) === array_map( static fn( $r ) => $r[4], $a129_datev2 ) && '1800' === $a129_datev2[0][3] && '' === $a129_datev2[0][5] && '99' === $a129_datev2[3][5] && 'CHF' === $a129_datev2[3][2] );

echo "A129: report CSV\n";
$a129_csv = Data::csv_rows( $a129_report );
$a129_cur_rows = array_values( array_filter( $a129_csv, static fn( $r ) => isset( $r[0] ) && in_array( $r[0], array( 'EUR', 'CHF' ), true ) ) );
check( 'A129 report CSV: "Netto nach Erstattung" column and a section per currency and rate', in_array( 'Netto nach Erstattung', $a129_csv[2] ?? array(), true ) && 6 === count( $a129_cur_rows ) && array( 'EUR', 'Gesamt', '73.29', number_format( $a129_eur['revenue_net'] / 100, 2, '.', '' ) ) === array( end( $a129_cur_rows )[0], end( $a129_cur_rows )[1], end( $a129_cur_rows )[9], end( $a129_cur_rows )[11] ) && $a129_eur['revenue_net'] === 7329 - $a129_eur['revenue_tax'] );

echo "A129: report page\n";
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
$a129_get = $_GET;
$_GET = array( 'from' => $a129_from, 'to' => $a129_to, 'event_id' => (string) $a129_event );
ob_start();
try { PassCaptain\Reports\Page::render(); $a129_html = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $a129_html = 'EXCEPTION ' . $e->getMessage(); }
$_GET = $a129_get;
check( 'A129 report page: table per currency, DATEV link, no reconciliation error', false !== strpos( $a129_html, 'Umsatz nach Währung' ) && false !== strpos( $a129_html, 'format=datev' ) && false === strpos( $a129_html, 'stimmen nicht überein' ) && false === strpos( $a129_html, 'EXCEPTION' ) );

update_option( Settings::OPTION, $a129_saved );
Settings::flush();
