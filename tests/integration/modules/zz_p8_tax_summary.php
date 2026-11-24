<?php
/**
 * TODO L911 (OSS and ZM summaries): Reports\TaxSummary – ledger summed per tax country, rate and
 * currency with OSS marks, and reverse-charge fee invoices of settlements per recipient VAT ID; both
 * as export types of the reports page for finance users only.
 * Included from run.php (check(), make_type()).
 */

// phpcs:ignoreFile

use PassCaptain\Reports\Exports;
use PassCaptain\Reports\TaxSummary;
use PassCaptain\Settings;

global $wpdb;
$p8t_p = $wpdb->prefix;
$p8t_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'seller_country' => 'DE' ) );
Settings::flush();

echo "L911: OSS summary per tax country and rate\n";
$p8t_head = array( 'Buchungsart', 'Buchungsdatum', 'Belegnummer', 'Bestellung', 'Bestell-ID', 'Erstattungs-ID', 'Beschreibung', 'Menge', 'Netto', 'USt-Satz', 'USt', 'Brutto', 'Waehrung', 'Zahlungsart', 'Zahlungsreferenz', 'Steuerland' );
$p8t_line = static function ( $kind, $net, $rate, $tax, $gross, $currency, $country ) { return array( $kind, '2026-11-01', '', 'X', 1, '', 'Konzert', 1, $net, $rate, $tax, $gross, $currency, 'manual', '', $country ); };
$p8t_fake = static function ( $rows, $type ) use ( $p8t_head, $p8t_line ) {
	if ( 'accounting_ledger' !== $type ) { return $rows; }
	return array(
		$p8t_head,
		$p8t_line( 'Zahlung', '100.00', '19.00', '19.00', '119.00', 'EUR', 'DE' ),
		$p8t_line( 'Zahlung', '50.00', '19.00', '9.50', '59.50', 'EUR', '' ),
		$p8t_line( 'Zahlung', '100.00', '13.00', '13.00', '113.00', 'EUR', 'AT' ),
		$p8t_line( 'Erstattung', '-10.00', '13.00', '-1.30', '-11.30', 'EUR', 'AT' ),
		$p8t_line( 'Zahlung', '80.00', '8.10', '6.48', '86.48', 'CHF', 'CH' ),
		$p8t_line( 'Erstattung', '-5.00', '19.00', '-0.95', '-5.95', 'EUR', 'DE, AT' ),
	);
};
add_filter( 'wp_pass_captain_export_rows_v1', $p8t_fake, 10, 2 );
$p8t_oss = TaxSummary::oss_rows( '2026-11-01', '2026-11-30' );
remove_filter( 'wp_pass_captain_export_rows_v1', $p8t_fake, 10 );
$p8t_by = array();
foreach ( array_slice( $p8t_oss, 1 ) as $r ) { $p8t_by[ $r[0] . '|' . $r[1] . '|' . $r[2] ] = $r; }
check( 'L911: header of the OSS summary', TaxSummary::oss_header() === $p8t_oss[0] );
check( 'L911: seller country rows (also without country) are summed, no OSS', array( 'DE', '19.00', 'EUR', '150.00', '28.50', '178.50', 2, 'nein' ) === ( $p8t_by['DE|19.00|EUR'] ?? null ) );
check( 'L911: another EU country is an OSS candidate; refunds reduce the sums', array( 'AT', '13.00', 'EUR', '90.00', '11.70', '101.70', 2, 'ja' ) === ( $p8t_by['AT|13.00|EUR'] ?? null ) );
check( 'L911: a country outside the EU is no OSS candidate, own currency kept', array( 'CH', '8.10', 'CHF', '80.00', '6.48', '86.48', 1, 'nein' ) === ( $p8t_by['CH|8.10|CHF'] ?? null ) );
check( 'L911: rows naming several countries are marked for review', 'prüfen' === ( $p8t_by['DE, AT|19.00|EUR'][7] ?? '' ) && '-5.00' === ( $p8t_by['DE, AT|19.00|EUR'][3] ?? '' ) );
check( 'L911: rows sorted by country', array( 'AT', 'CH', 'DE', 'DE, AT' ) === array_column( array_slice( $p8t_oss, 1 ), 0 ) );
check( 'L911: invalid dates give the header only', array( TaxSummary::oss_header() ) === TaxSummary::oss_rows( '', '2026-11-30' ) );
check( 'L911: decimal helpers keep exact minor units', array( -1250, 2 ) === TaxSummary::to_minor( '-12.50' ) && '-0.05' === TaxSummary::from_minor( -5, 2 ) && '7' === TaxSummary::from_minor( 7, 0 ) );
check( 'L911: OSS flag needs an EU seller country', 'nein' === TaxSummary::oss_flag( 'AT', 'CH' ) && 'ja' === TaxSummary::oss_flag( 'FR', 'AT' ) );

// A real paid order lands in the summary of its day (seller country, ticket rate).
$p8t_type = make_type( 10, 10, 2380 );
$wpdb->query( "UPDATE {$p8t_p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$p8t_type" );
$p8t_order = PassCaptain\Orders::create( array( 'email' => 'p8-tax@example.com', 'items' => array( array( 'ticket_type_id' => $p8t_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p8t-' . uniqid() ) );
if ( ! is_wp_error( $p8t_order ) ) { PassCaptain\Orders::confirm_manual_payment( $p8t_order['order_id'] ); }
$p8t_today = gmdate( 'Y-m-d' );
$p8t_real = TaxSummary::oss_rows( $p8t_today, $p8t_today );
$p8t_real_de = array_values( array_filter( array_slice( $p8t_real, 1 ), static fn( $r ) => '19.00' === $r[1] && 'EUR' === $r[2] ) );
check( 'L911: a paid order appears in the summary with its rate (from the ledger export)', ! is_wp_error( $p8t_order ) && $p8t_real_de && (int) $p8t_real_de[0][6] >= 1 && (float) $p8t_real_de[0][5] >= 23.80 );

echo "L911: ZM summary (reverse-charge fee invoices)\n";
$wpdb->query( "DELETE FROM {$p8t_p}wpcap_partner_settlements WHERE period LIKE '1999-%'" );
$p8t_settle = static function ( $partner_id, $period, $status, array $totals, array $partner, $number ) use ( $wpdb, $p8t_p ) {
	$wpdb->insert( "{$p8t_p}wpcap_partner_settlements", array( 'partner_id' => $partner_id, 'period' => $period, 'period_start' => $period . '-01 00:00:00', 'period_end' => $period . '-28 00:00:00', 'status' => $status, 'currency' => 'EUR', 'totals' => wp_json_encode( $totals ), 'partner_snapshot' => wp_json_encode( array( 'partner' => $partner ) ), 'document_number' => $number, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	return (int) $wpdb->insert_id;
};
$p8t_at = array( 'vat_id' => 'atu 12345678', 'country' => 'AT' );
$p8t_settle( 990001, '1999-01', 'finalized', array( 'fee_reverse_charge' => true, 'platform_fee_net' => 12345 ), $p8t_at, 'PA-1' );
$p8t_settle( 990001, '1999-02', 'paid', array( 'fee_reverse_charge' => true, 'platform_fee_net' => 655 ), $p8t_at, 'PA-2' );
$p8t_settle( 990002, '1999-02', 'finalized', array( 'fee_reverse_charge' => false, 'platform_fee_net' => 5000 ), array( 'vat_id' => 'DE123456789', 'country' => 'DE' ), 'PA-3' );
$p8t_settle( 990003, '1999-03', 'draft', array( 'fee_reverse_charge' => true, 'platform_fee_net' => 7000 ), array( 'vat_id' => 'FR12345678901', 'country' => 'FR' ), '' );
$p8t_settle( 990004, '1999-04', 'finalized', array( 'fee_reverse_charge' => true, 'platform_fee_net' => 9000 ), array( 'vat_id' => 'NL123456789B01', 'country' => 'NL' ), 'PA-4' );
$p8t_zm = TaxSummary::zm_rows( '1999-01-01', '1999-03-31' );
check( 'L911: ZM header', TaxSummary::zm_header() === $p8t_zm[0] );
check( 'L911: ZM sums reverse-charge fee invoices per VAT ID of the quarter (finalized and paid)', 2 === count( $p8t_zm ) && array( 'ATU12345678', 'AT', 'Sonstige Leistung', '130.00', 'EUR', 'PA-1 PA-2', '1999-01 1999-02' ) === $p8t_zm[1] );
check( 'L911: ZM leaves out domestic invoices, drafts and other quarters', false === strpos( wp_json_encode( $p8t_zm ), 'DE123456789' ) && false === strpos( wp_json_encode( $p8t_zm ), 'FR123' ) && false === strpos( wp_json_encode( $p8t_zm ), 'NL123' ) );
check( 'L911: ZM of the next quarter', 'NL123456789B01' === ( TaxSummary::zm_rows( '1999-04-01', '1999-06-30' )[1][0] ?? '' ) );
$wpdb->query( "DELETE FROM {$p8t_p}wpcap_partner_settlements WHERE period LIKE '1999-%'" );

echo "L911: export types on the reports page\n";
$p8t_types = Exports::types();
check( 'L911: both summaries are export types for finance users', isset( $p8t_types['tax_oss'], $p8t_types['tax_zm'] ) && 'wpcap_view_finance' === $p8t_types['tax_oss']['capability'] );
$p8t_built = Exports::build( 'tax_oss', array( 'from' => $p8t_today, 'to' => $p8t_today, 'event_id' => 0, 'occurrence_id' => 0 ) );
check( 'L911: the OSS export builds a CSV', is_array( $p8t_built ) && 0 === strpos( $p8t_built['filename'], 'pass-captain-steuer-laender-' ) && false !== strpos( $p8t_built['csv'], 'OSS-Kandidat' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_view_finance' );
$p8t_hidden = Exports::types();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'L911: without finance rights the summaries are not offered', ! isset( $p8t_hidden['tax_oss'] ) && ! isset( $p8t_hidden['tax_zm'] ) );
$p8t_rows_cb = $p8t_types['tax_oss']['rows'];
check( 'L911: a report scope limited to events gets no site-wide figures', array( TaxSummary::oss_header() ) === $p8t_rows_cb( array( 'from' => $p8t_today, 'to' => $p8t_today ), array( 1, 2 ) ) );

update_option( Settings::OPTION, $p8t_saved );
Settings::flush();
