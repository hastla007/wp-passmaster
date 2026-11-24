<?php
/**
 * L908: an invoice in the platform's own name is only issued once its master data is complete (name,
 * address, tax number or VAT ID). The refusal names the missing fields, is audited, reserves no
 * invoice number and goes away as soon as the data is filled in.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Partners\SalesModel;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
echo "L908 platform master data before invoicing\n";

$p4_before = array_intersect_key( Settings::all(), array_flip( array( 'seller_name', 'seller_address', 'seller_vat_id', 'seller_tax_number', 'seller_small_business', 'auto_invoice', 'require_terms' ) ) );
Settings::update( array( 'seller_name' => '', 'seller_address' => '', 'seller_vat_id' => '', 'seller_tax_number' => '', 'seller_small_business' => 0, 'auto_invoice' => 0, 'require_terms' => 0 ) );

check( 'L908: empty platform data lists name, address and tax number as missing', 3 === count( SalesModel::missing_platform_fields() ) );
check( 'L908: tax number alone satisfies the tax field', array() === SalesModel::missing_platform_fields( array( 'name' => 'A', 'address' => 'B', 'vat_id' => '', 'tax_number' => '1/2/3' ) ) );
check( 'L908: VAT ID alone satisfies the tax field', array() === SalesModel::missing_platform_fields( array( 'name' => 'A', 'address' => 'B', 'vat_id' => 'ATU12345678', 'tax_number' => ' ' ) ) );
check( 'L908: whitespace-only name counts as missing', 1 === count( SalesModel::missing_platform_fields( array( 'name' => '  ', 'address' => 'B', 'vat_id' => 'X', 'tax_number' => '' ) ) ) );

$p4_type = ex_type( ex_occ( ex_event( 'L908 Event' ) ), 'Standard', 2000 );
list( $p4_order ) = ex_buy( $p4_type, 1, 'l908@example.com' );
check( 'L908: setup: paid order', $p4_order > 0 );
$p4_numbers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_invoices" );

Settings::update( array( 'seller_name' => 'Plattform GmbH', 'seller_address' => '' ) );
$p4_refused = Invoices::issue_for_order( $p4_order );
check( 'L908: invoice refused while the address and tax number are missing', is_wp_error( $p4_refused ) && 'wpcap_invoice_platform_incomplete' === $p4_refused->get_error_code() );
check( 'L908: refusal names the missing fields', is_wp_error( $p4_refused ) && 2 === count( (array) ( $p4_refused->get_error_data()['missing'] ?? array() ) ) && false !== strpos( $p4_refused->get_error_message(), 'Anschrift' ) );
check( 'L908: refusal creates no invoice', $p4_numbers === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_invoices" ) );
check( 'L908: refusal is audited', (bool) $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='invoice_refused' AND object_type='order' AND object_id=" . (int) $p4_order ) );

// Automatic invoicing after payment follows the same rule (no invoice, no fatal).
Settings::update( array( 'auto_invoice' => 1 ) );
list( $p4_auto ) = ex_buy( $p4_type, 1, 'l908-auto@example.com' );
check( 'L908: auto invoice after payment is skipped while data is incomplete', $p4_auto > 0 && ! $wpdb->get_var( "SELECT invoice_id FROM {$p}wpcap_invoices WHERE order_id=" . (int) $p4_auto ) );
Settings::update( array( 'auto_invoice' => 0 ) );

Settings::update( array( 'seller_address' => "Ring 1\n1010 Wien", 'seller_tax_number' => '12/345/67890' ) );
$p4_issued = Invoices::issue_for_order( $p4_order );
check( 'L908: invoice issued once the master data is complete', is_array( $p4_issued ) && ! empty( $p4_issued['invoice_number'] ) && empty( $p4_issued['reused'] ) );
$p4_snapshot = json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE order_id=" . (int) $p4_order ), true );
check( 'L908: invoice snapshot carries the completed seller data', '12/345/67890' === ( $p4_snapshot['seller']['tax_number'] ?? '' ) && 'Plattform GmbH' === ( $p4_snapshot['seller']['name'] ?? '' ) );

Settings::update( $p4_before );
