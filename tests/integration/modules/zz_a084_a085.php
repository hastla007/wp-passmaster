<?php
/**
 * A084: refund corrections are "Rechnungskorrektur" (prefix RK for new installs), not "Gutschrift".
 * A085: corrections show net/VAT/gross per tax rate (document, PDF, accounting export).
 * Included from run.php (helpers from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

echo "A084: Rechnungskorrektur instead of Gutschrift (prefix RK)\n";
$a84_saved = get_option( Settings::OPTION, array() );
check( 'default correction prefix is RK; self-billing prefix of partner settlements stays GU', 'RK' === Settings::defaults()['credit_note_prefix'] && 'RK' === Invoices::CREDIT_PREFIX_DEFAULT && 'GU' === ( Settings::defaults()['partners']['self_billing_prefix'] ?? 'GU' ) );
// Existing install (schema < 3.8.3) without a stored prefix: the former default GS is pinned.
$a84_opt = $a84_saved;
unset( $a84_opt['credit_note_prefix'] );
update_option( Settings::OPTION, $a84_opt );
Settings::flush();
$a84_pinned = PassCaptain\Installer::pin_legacy_credit_prefix( '3.8.2+abc' );
check( 'upgrade from an older schema pins GS when no prefix is stored', true === $a84_pinned && 'GS' === ( get_option( Settings::OPTION )['credit_note_prefix'] ?? '' ) && 'GS' === Settings::get( 'credit_note_prefix' ) );
$a84_opt['credit_note_prefix'] = 'XY';
update_option( Settings::OPTION, $a84_opt );
Settings::flush();
check( 'a configured prefix is never changed by the migration', false === PassCaptain\Installer::pin_legacy_credit_prefix( '3.0.0' ) && 'XY' === Settings::get( 'credit_note_prefix' ) );
unset( $a84_opt['credit_note_prefix'] );
update_option( Settings::OPTION, $a84_opt );
Settings::flush();
$wpdb->query( "UPDATE {$p}wpcap_credit_notes SET credit_note_number=CONCAT('X', credit_note_number) WHERE credit_note_number LIKE 'GS-%'" );
check( 'a current schema without former GS documents keeps the new default', false === PassCaptain\Installer::pin_legacy_credit_prefix( PassCaptain\Installer::version() ) && 'RK' === Settings::get( 'credit_note_prefix' ) );
$wpdb->query( "UPDATE {$p}wpcap_credit_notes SET credit_note_number=SUBSTRING(credit_note_number, 2) WHERE credit_note_number LIKE 'XGS-%'" );
$wpdb->insert( "{$p}wpcap_credit_notes", array( 'invoice_id' => 999999, 'refund_id' => 999999, 'credit_note_number' => 'GS-2020-000001', 'currency' => 'EUR', 'net_minor' => 100, 'tax_minor' => 0, 'total_minor' => 100, 'issued_at' => '2020-01-01 00:00:00' ) );
check( 'issued GS documents are detected (lost version option) and not renumbered', true === PassCaptain\Installer::pin_legacy_credit_prefix( '' ) && 'GS' === Settings::get( 'credit_note_prefix' ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_credit_notes WHERE credit_note_number ='GS-2020-000001'" ) === 1 );
$wpdb->delete( "{$p}wpcap_credit_notes", array( 'refund_id' => 999999 ) );
// New install: no stored prefix → RK documents.
update_option( Settings::OPTION, $a84_opt );
Settings::flush();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0 ) );
$a84_type = ex_type( ex_occ( ex_event( 'A084' ) ), 'Normal', 2200, 0, 1000 );
list( $a84_o, $a84_t ) = ex_buy( $a84_type, 2, 'a84@example.com' );
$a84_inv = Invoices::issue_for_order( $a84_o );
$a84_r = Refunds::request( $a84_o, array( $a84_t[0] ), 'Test', 'a84-r' );
$a84_cn = Invoices::credit_note_for_refund( $a84_r['refund_id'] );
check( 'new correction documents get the RK prefix', is_array( $a84_cn ) && 1 === preg_match( '/^RK-\d{4}-\d{6}$/', (string) $a84_cn['credit_note_number'] ) );
$a84_rows = Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d', time() + 86400 ) );
$a84_kinds = array_count_values( array_column( array_slice( $a84_rows, 1 ), 0 ) );
check( 'accounting export calls corrections "Rechnungskorrektur", never "Gutschrift"', ( $a84_kinds['Rechnungskorrektur'] ?? 0 ) >= 1 && ! isset( $a84_kinds['Gutschrift'] ) );
check( 'refund mail line reads "Rechnungskorrektur: …"', "Rechnungskorrektur: RK-1\n" === PassCaptain\Mail\Types::credit_note( 'RK-1' ) && '' === PassCaptain\Mail\Types::credit_note( '' ) );
$a84_csv = PassCaptain\Reports\Data::csv_rows( PassCaptain\Reports\Data::build( array( 'from' => gmdate( 'Y-m-d', time() - 86400 ), 'to' => gmdate( 'Y-m-d', time() + 86400 ) ), null ) );
check( 'report CSV labels corrections "Rechnungskorrekturen"', in_array( 'Rechnungskorrekturen', array_column( $a84_csv, 0 ), true ) && ! in_array( 'Gutschriften', array_column( $a84_csv, 0 ), true ) );
update_option( Settings::OPTION, $a84_saved );
Settings::flush();

echo "A085: Rechnungskorrekturen per tax rate\n";
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'credit_note_prefix' => 'RK' ) );
$a85_occ = ex_occ( ex_event( 'A085 gemischt' ) );
$a85_std = ex_type( $a85_occ, 'Standard 19 %', 2380, 0, 1900 );
$a85_red = ex_type( $a85_occ, 'Ermäßigt 7 %', 1070, 0, 700 );
$GLOBALS['wpcap_test_transients'] = array();
$a85 = Orders::create( array( 'email' => 'a85@example.com', 'items' => array( array( 'ticket_type_id' => $a85_std, 'quantity' => 2 ), array( 'ticket_type_id' => $a85_red, 'quantity' => 2 ) ), 'idempotency_key' => 'a85-' . uniqid() ) );
if ( 'paid' !== $a85['payment_status'] ) { Orders::confirm_external_payment( $a85['order_id'], 'test' ); }
$a85_o = (int) $a85['order_id'];
$a85_order = Orders::get( $a85_o );
check( 'mixed-rate order: 2×23,80 (19 %) + 2×10,70 (7 %), VAT 7,60 + 1,40', 6900 === (int) $a85_order->total_minor && 900 === (int) $a85_order->tax_minor );
$a85_inv = Invoices::issue_for_order( $a85_o );
$a85_by_type = array();
foreach ( tickets( $a85_o, 'valid' ) as $t ) { $a85_by_type[ (int) $t->ticket_type_id ][] = (int) $t->ticket_id; }
$a85_sum_ok = static function ( $cn, $rates ) {
	return array_sum( array_column( $rates, 'gross' ) ) === (int) $cn['total_minor'] && array_sum( array_column( $rates, 'tax' ) ) === (int) $cn['tax_minor'] && array_sum( array_column( $rates, 'net' ) ) === (int) $cn['net_minor'];
};

// 1) One 7 % ticket: a single 7 % row.
$a85_r1 = Refunds::request( $a85_o, array( $a85_by_type[ $a85_red ][0] ), 'Teil', 'a85-r1' );
$a85_cn1 = Invoices::credit_note_for_refund( $a85_r1['refund_id'] );
$a85_rates1 = Invoices::credit_rates( $a85_cn1 );
check( 'single-ticket refund: one 7 % row (10,00 net, 0,70 VAT, 10,70 gross)', array( array( 'rate_bp' => 700, 'net' => 1000, 'tax' => 70, 'gross' => 1070 ) ) === $a85_rates1 );
check( 'breakdown is stored with the document', null !== $a85_cn1['rates'] && $a85_rates1 === json_decode( $a85_cn1['rates'], true ) );

// 2) One ticket of each rate: two rows.
$a85_r2 = Refunds::request( $a85_o, array( $a85_by_type[ $a85_std ][0], $a85_by_type[ $a85_red ][1] ), 'Teil', 'a85-r2' );
$a85_cn2 = Invoices::credit_note_for_refund( $a85_r2['refund_id'] );
$a85_rates2 = Invoices::credit_rates( $a85_cn2 );
check( 'mixed refund: rows for 7 % and 19 % with net/VAT/gross of the refunded tickets', 2 === count( $a85_rates2 ) && array( 'rate_bp' => 700, 'net' => 1000, 'tax' => 70, 'gross' => 1070 ) === $a85_rates2[0] && array( 'rate_bp' => 1900, 'net' => 2000, 'tax' => 380, 'gross' => 2380 ) === $a85_rates2[1] && $a85_sum_ok( $a85_cn2, $a85_rates2 ) );

// 3) Amount-only refund (goodwill): spread over all lines by rate, rows add up exactly.
$a85_r3 = Refunds::request_amount( $a85_o, 1001, 'Kulanz', 'a85-r3', array( 'system' => true ) );
if ( ! is_wp_error( $a85_r3 ) && 'completed' !== ( Refunds::get( $a85_r3['refund_id'] )['status'] ?? '' ) ) { Refunds::approve( $a85_r3['refund_id'] ); }
$a85_cn3 = is_wp_error( $a85_r3 ) ? null : Invoices::credit_note_for_refund( $a85_r3['refund_id'] );
$a85_rates3 = $a85_cn3 ? Invoices::credit_rates( $a85_cn3 ) : array();
// Both 7 % tickets are refunded already: the goodwill amount lands on what is left (19 %, A200),
// with the VAT of that rate (10,01 gross → 1,60 VAT).
check( 'amount-only refund: spread over what is left (19 %), rows add up to the document totals (odd cent)', is_array( $a85_cn3 ) && array( array( 'rate_bp' => 1900, 'net' => 841, 'tax' => 160, 'gross' => 1001 ) ) === $a85_rates3 && 1001 === (int) $a85_cn3['total_minor'] && $a85_sum_ok( $a85_cn3, $a85_rates3 ) );
check( 'breakdown is deterministic (same result on recomputation)', $a85_rates3 === Invoices::credit_note_rates( Refunds::get( $a85_r3['refund_id'] ), (int) $a85_cn3['total_minor'], (int) $a85_cn3['tax_minor'] ) );

// Legacy document (before schema 3.8.3, no stored breakdown): computed identically on display.
$wpdb->update( "{$p}wpcap_credit_notes", array( 'rates' => null ), array( 'credit_note_id' => (int) $a85_cn2['credit_note_id'] ) );
$a85_legacy = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE credit_note_id=%d", (int) $a85_cn2['credit_note_id'] ) );
check( 'documents without stored breakdown are computed from the refund', $a85_rates2 === Invoices::credit_rates( $a85_legacy ) );
// A stored breakdown that does not match the totals is not trusted.
$wpdb->update( "{$p}wpcap_credit_notes", array( 'rates' => '[{"rate_bp":1900,"net":1,"tax":1,"gross":2}]' ), array( 'credit_note_id' => (int) $a85_cn2['credit_note_id'] ) );
$a85_bad = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE credit_note_id=%d", (int) $a85_cn2['credit_note_id'] ) );
check( 'inconsistent stored breakdown falls back to the computation', $a85_rates2 === Invoices::credit_rates( $a85_bad ) );
$wpdb->update( "{$p}wpcap_credit_notes", array( 'rates' => wp_json_encode( $a85_rates2 ) ), array( 'credit_note_id' => (int) $a85_cn2['credit_note_id'] ) );

// Document views.
$a85_html = Invoices::render_html( $a85_inv['invoice_id'] );
$a85_cn_html = substr( $a85_html, (int) strpos( $a85_html, 'Rechnungskorrektur ' . $a85_cn2['credit_note_number'] ) );
$a85_cn_html = substr( $a85_cn_html, 0, (int) strpos( $a85_cn_html, 'Korrekturbetrag' ) );
check( 'HTML: correction lists net/VAT/gross per rate and VAT per rate', false !== strpos( $a85_cn_html, 'Aufstellung nach Steuersätzen' ) && false !== strpos( $a85_cn_html, 'Umsatzsteuer 19,00 %' ) && false !== strpos( $a85_cn_html, 'Umsatzsteuer 7,00 %' ) && false !== strpos( $a85_cn_html, '-3,80 EUR' ) && false !== strpos( $a85_cn_html, '-0,70 EUR' ) && false !== strpos( $a85_cn_html, '-20,00 EUR' ) && false === strpos( $a85_html, 'Gutschrift' ) );
$a85_pdf = PassCaptain\Printing\InvoicePdf::render( $a85_inv['invoice_id'], (int) $a85_cn2['credit_note_id'] );
check( 'PDF: correction page shows rows per rate', false !== strpos( $a85_pdf, '(USt-Satz 19,00 %) Tj' ) && false !== strpos( $a85_pdf, '(USt-Satz 7,00 %) Tj' ) && false !== strpos( $a85_pdf, '(-23,80 EUR) Tj' ) && false !== strpos( $a85_pdf, '(-10,70 EUR) Tj' ) && false !== strpos( $a85_pdf, '(-34,50 EUR) Tj' ) );
$a85_rows = array_values( array_filter( Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d', time() + 86400 ) ), static fn( $r ) => $r[1] === $a85_cn2['credit_note_number'] ) );
check( 'export: one row per rate with rate, net, VAT, gross', 2 === count( $a85_rows ) && array( 'Rechnungskorrektur', '7.00', '-10.00', '-0.70', '-10.70' ) === array( $a85_rows[0][0], $a85_rows[0][8], $a85_rows[0][7], $a85_rows[0][9], $a85_rows[0][10] ) && array( '19.00', '-20.00', '-3.80', '-23.80' ) === array( $a85_rows[1][8], $a85_rows[1][7], $a85_rows[1][9], $a85_rows[1][10] ) );

// Single-rate order: one row equal to the totals; 0 % lines get no VAT.
$a85_zero = ex_type( $a85_occ, 'Steuerfrei', 1500, 0, 0 );
$GLOBALS['wpcap_test_transients'] = array();
$a85z = Orders::create( array( 'email' => 'a85z@example.com', 'items' => array( array( 'ticket_type_id' => $a85_std, 'quantity' => 1 ), array( 'ticket_type_id' => $a85_zero, 'quantity' => 1 ) ), 'idempotency_key' => 'a85z-' . uniqid() ) );
if ( 'paid' !== $a85z['payment_status'] ) { Orders::confirm_external_payment( $a85z['order_id'], 'test' ); }
Invoices::issue_for_order( $a85z['order_id'] );
$a85z_r = Refunds::request( $a85z['order_id'], array(), 'Absage', 'a85z-r' );
$a85z_cn = Invoices::credit_note_for_refund( $a85z_r['refund_id'] );
$a85z_rates = Invoices::credit_rates( $a85z_cn );
check( 'full refund with a 0 % line: 0 % row without VAT, 19 % row carries all VAT', 2 === count( $a85z_rates ) && array( 'rate_bp' => 0, 'net' => 1500, 'tax' => 0, 'gross' => 1500 ) === $a85z_rates[0] && array( 'rate_bp' => 1900, 'net' => 2000, 'tax' => 380, 'gross' => 2380 ) === $a85z_rates[1] );
check( 'apportion: largest remainder, parts add up', array( 'a' => 34, 'b' => 33, 'c' => 33 ) === Invoices::apportion( 100, array( 'a' => 1, 'b' => 1, 'c' => 1 ) ) && array( 'x' => 0, 'y' => 7 ) === Invoices::apportion( 7, array( 'x' => 0, 'y' => 5 ) ) );
check( 'schema: wpcap_credit_notes.rates exists (3.8.3)', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_credit_notes LIKE 'rates'" ) && Invoices::credit_rates_ready() );
