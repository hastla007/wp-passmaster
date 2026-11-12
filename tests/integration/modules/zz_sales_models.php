<?php
/**
 * Sales models per organizer (docs/SALES-MODELS.md): own_name (Eigenhändler, default) vs. agent
 * (Vermittler). Customer invoices in the organizer's name and number range, credit notes following the
 * seller, mixed-seller orders refused at checkout and at invoice time, reverse-charge logic, settlement
 * documents "Provisionsrechnung" (agent) and "Gutschrift" + fee invoice (own_name), gapless series and
 * immutability of finalized documents.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Partners\AdminPage as SmAdmin;
use PassCaptain\Partners\Repository as SmPartners;
use PassCaptain\Partners\SalesModel;
use PassCaptain\Partners\Settlements as SmSettlements;

global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Payments\Registry::reset();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'seller_name' => 'Plattform GmbH', 'seller_address' => "Ring 1\n1010 Wien", 'seller_vat_id' => 'DE999999999', 'seller_country' => 'DE', 'invoice_prefix' => 'WPC', 'credit_note_prefix' => 'GS' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$sm_hooks_before = $GLOBALS['wpcap_test_hooks'] ?? array();
PassCaptain\Modules\Partners::register();

$sm_m1 = gmdate( 'Y-m', strtotime( 'first day of -1 month' ) );
$sm_t1 = gmdate( 'Y-m-d H:i:s', strtotime( $sm_m1 . '-10 12:00:00 UTC' ) );
$sm_year = Invoices::local_year();

function sm_type( $price, $tax_bp ) {
	global $wpdb;
	$type = make_type( 100, 10, $price );
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_ticket_types SET tax_rate_bp=" . (int) $tax_bp . " WHERE ticket_type_id=" . (int) $type );
	return $type;
}
function sm_event( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function sm_order( array $types, $key, $created_at = null ) {
	global $wpdb;
	$items = array();
	foreach ( $types as $type => $qty ) { $items[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	$r = Orders::create( array( 'email' => "$key@example.com", 'items' => $items, 'idempotency_key' => $key . '-' . uniqid() ) );
	if ( is_wp_error( $r ) ) { return $r; }
	Orders::confirm_manual_payment( $r['order_id'] );
	if ( $created_at ) { $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET created_at=%s WHERE order_id=%d", $created_at, $r['order_id'] ) ); }
	return (int) $r['order_id'];
}
function sm_snapshot( $invoice_id ) {
	global $wpdb;
	return (array) json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$wpdb->prefix}wpcap_invoices WHERE invoice_id=" . (int) $invoice_id ), true );
}
function sm_seq( $series ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_number FROM {$wpdb->prefix}wpcap_document_sequences WHERE series=%s AND year=%d", $series, Invoices::local_year() ) );
}

echo "Sales models: organizer settings\n";
$sm_ta = sm_type( 1190, 1900 );
$sm_tb = sm_type( 2140, 700 );
$sm_tp = sm_type( 1000, 1000 ); // platform event without organizer
$sm_tc = sm_type( 1190, 1900 ); // own_name organizer
$sm_tz = sm_type( 500, 0 );     // small business organizer
$sm_agent = SmAdmin::admin_action( array( 'do' => 'save_partner', 'name' => 'Theater Agentur', 'billing_address' => "Bühnenweg 2\n80331 München", 'vat_id' => 'DE111111111', 'currency' => 'EUR', 'commission' => '10', 'fee_per_ticket' => '0,50', 'fee_tax_rate' => '19', 'settlement_start' => $sm_m1 . '-01', 'event_ids' => array( sm_event( $sm_ta ), sm_event( $sm_tb ) ), 'sales_model' => 'agent', 'legal_name' => 'Theater Agentur GmbH', 'country' => 'de', 'tax_number' => '143/123/45678', 'invoice_prefix' => 'TAG' ) );
$sm_a = (int) ( $sm_agent['partner_id'] ?? 0 );
$sm_pa = SmPartners::get( $sm_a );
check( 'admin form stores sales model and seller fields', $sm_a && 'agent' === $sm_pa->sales_model && 'Theater Agentur GmbH' === $sm_pa->legal_name && 'DE' === $sm_pa->country && '143/123/45678' === $sm_pa->tax_number && 'TAG' === $sm_pa->invoice_prefix );
check( 'default sales model is own_name (Eigenhändler)', 'own_name' === SmPartners::get( SmPartners::save( array( 'name' => 'Default e.V.' ) ) )->sales_model && SalesModel::OWN_NAME === SalesModel::PLATFORM_DEFAULT && SalesModel::OWN_NAME === SalesModel::model_of( null ) );
SmPartners::save( array( 'name' => 'Theater Agentur', 'billing_address' => "Bühnenweg 2\n80331 München", 'vat_id' => 'DE111111111', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $sm_m1 . '-01' ), $sm_a );
check( 'partial update keeps sales model and seller fields', 'agent' === SmPartners::get( $sm_a )->sales_model && 'TAG' === SmPartners::get( $sm_a )->invoice_prefix );
check( 'invalid sales model, prefix with separators, reserved or duplicate prefix and bad country are rejected',
	is_wp_error( SmPartners::save( array( 'name' => 'X', 'sales_model' => 'broker' ) ) )
	&& is_wp_error( SmPartners::save( array( 'name' => 'X', 'invoice_prefix' => 'A-B' ) ) )
	&& is_wp_error( SmPartners::save( array( 'name' => 'X', 'invoice_prefix' => 'wpc' ) ) )
	&& is_wp_error( SmPartners::save( array( 'name' => 'X', 'invoice_prefix' => 'TAG' ) ) )
	&& is_wp_error( SmPartners::save( array( 'name' => 'X', 'invoice_prefix' => 'V12' ) ) )
	&& is_wp_error( SmPartners::save( array( 'name' => 'X', 'country' => 'Deutschland' ) ) ) );

echo "Sales models: reverse-charge logic\n";
check( 'reverse charge only between different EU countries with a plausible VAT ID of the recipient country',
	SalesModel::reverse_charge( 'DE', 'AT', 'ATU12345678' )
	&& SalesModel::reverse_charge( 'AT', 'DE', 'DE 123 456 789' )
	&& SalesModel::reverse_charge( 'DE', 'GR', 'EL123456789' )
	&& ! SalesModel::reverse_charge( 'DE', 'DE', 'DE123456789' )
	&& ! SalesModel::reverse_charge( 'DE', 'AT', '' )
	&& ! SalesModel::reverse_charge( 'DE', 'AT', 'DE123456789' )
	&& ! SalesModel::reverse_charge( 'DE', 'CH', 'CHE123456789' )
	&& ! SalesModel::reverse_charge( 'CH', 'DE', 'DE123456789' )
	&& ! SalesModel::reverse_charge( '', 'AT', 'ATU12345678' ) );
check( 'reverse-charge note wording', 'Steuerschuldnerschaft des Leistungsempfängers / Reverse Charge' === SalesModel::reverse_charge_note() );

echo "Sales models: customer invoices (agent = organizer as seller)\n";
$sm_seq_platform = (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_number FROM {$p}wpcap_invoice_sequences WHERE year=%d", $sm_year ) );
$sm_o1 = sm_order( array( $sm_ta => 2, $sm_tb => 1 ), 'sm-agent-1' );
$sm_i1 = Invoices::issue_for_order( $sm_o1 );
$sm_s1 = sm_snapshot( $sm_i1['invoice_id'] );
check( 'agent invoice is numbered in the organizer series TAG-YYYY-000001', ! is_wp_error( $sm_i1 ) && sprintf( 'TAG-%d-000001', $sm_year ) === $sm_i1['invoice_number'] );
check( 'seller snapshot is the organizer (legal name, address, VAT ID, tax number), platform as agent', 'agent' === $sm_s1['sales_model'] && 'Theater Agentur GmbH' === $sm_s1['seller']['name'] && 'DE111111111' === $sm_s1['seller']['vat_id'] && '143/123/45678' === $sm_s1['seller']['tax_number'] && 'Plattform GmbH' === $sm_s1['agent']['name'] && $sm_a === $sm_s1['partner_id'] );
$sm_html1 = Invoices::render_html( $sm_i1['invoice_id'] );
check( 'invoice view shows organizer as seller and "Vermittelt durch {platform}"', false !== strpos( $sm_html1, 'Theater Agentur GmbH' ) && false !== strpos( $sm_html1, 'Steuernummer: 143/123/45678' ) && false !== strpos( $sm_html1, 'Vermittelt durch Plattform GmbH' ) && false !== strpos( $sm_html1, 'TAG-' ) );
$sm_pdf1 = PassCaptain\Printing\InvoicePdf::render( $sm_i1['invoice_id'] );
check( 'invoice PDF shows organizer and agent note', str_starts_with( $sm_pdf1, '%PDF' ) && false !== strpos( $sm_pdf1, 'Vermittelt durch Plattform GmbH' ) && false !== strpos( $sm_pdf1, 'Theater Agentur GmbH' ) );
$sm_i2 = Invoices::issue_for_order( sm_order( array( $sm_ta => 1 ), 'sm-agent-2' ) );
check( 'next agent invoice continues the organizer series without gaps', sprintf( 'TAG-%d-000002', $sm_year ) === $sm_i2['invoice_number'] );
$sm_ip = Invoices::issue_for_order( sm_order( array( $sm_tp => 1 ), 'sm-platform' ) );
$sm_sp = sm_snapshot( $sm_ip['invoice_id'] );
check( 'platform event: invoice by the platform in its own series (organizer series do not consume platform numbers)', sprintf( 'WPC-%d-%06d', $sm_year, max( 1, $sm_seq_platform ) ) === $sm_ip['invoice_number'] && 'own_name' === $sm_sp['sales_model'] && 'Plattform GmbH' === $sm_sp['seller']['name'] && empty( $sm_sp['agent'] ) && false === strpos( Invoices::render_html( $sm_ip['invoice_id'] ), 'Vermittelt durch' ) );
$sm_ref = PassCaptain\Refunds::request( $sm_o1, array( (int) tickets( $sm_o1, 'valid' )[0]->ticket_id ), 'Absage', 'sm-ref-1' );
$sm_cn = Invoices::credit_note_for_refund( $sm_ref['refund_id'] );
$sm_ref_p = PassCaptain\Refunds::request( (int) $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $sm_ip['invoice_id'] ), array( (int) tickets( (int) $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $sm_ip['invoice_id'] ), 'valid' )[0]->ticket_id ), 'Absage', 'sm-ref-2' );
$sm_cn_p = Invoices::credit_note_for_refund( $sm_ref_p['refund_id'] );
check( 'credit note of an agent invoice follows the organizer (own correction series TAG-K)', is_array( $sm_cn ) && sprintf( 'TAG-K-%d-000001', $sm_year ) === $sm_cn['credit_note_number'] && 1 === preg_match( '/^GS-\d{4}-\d{6}$/', (string) $sm_cn_p['credit_note_number'] ) );
check( 'credit note view keeps the organizer as seller', false !== strpos( Invoices::render_html( $sm_i1['invoice_id'] ), $sm_cn['credit_note_number'] ) && false !== strpos( Invoices::render_html( $sm_i1['invoice_id'] ), 'Theater Agentur GmbH' ) );
$sm_rows = Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d' ) );
$sm_export = array_filter( $sm_rows, static fn( $r ) => ( $r[1] ?? '' ) === $GLOBALS['sm_i1']['invoice_number'] );
check( 'accounting export marks agent documents with the organizer as seller', 'Rechnungssteller' === end( $sm_rows[0] ) && $sm_export && 'Vermittler' === array_values( $sm_export )[0][13] && 'Theater Agentur GmbH' === array_values( $sm_export )[0][14] );

$sm_b = SmPartners::save( array( 'name' => 'Zweite Agentur', 'billing_address' => 'Weg 3, Graz', 'tax_number' => '12 345/6789', 'sales_model' => 'agent' ) );
$sm_tb2 = sm_type( 1000, 1000 );
SmPartners::set_events( $sm_b, array( sm_event( $sm_tb2 ) ) );
$sm_ib = Invoices::issue_for_order( sm_order( array( $sm_tb2 => 1 ), 'sm-agent-b' ) );
check( 'organizer without own prefix numbers in its own series V{id}-YYYY-000001', sprintf( 'V%d-%d-000001', $sm_b, $sm_year ) === $sm_ib['invoice_number'] && 3 === sm_seq( 'invoice-org-' . $sm_a ) && 2 === sm_seq( 'invoice-org-' . $sm_b ) );

echo "Sales models: multi-organizer orders\n";
$sm_mixed = Orders::create( array( 'email' => 'mixed@example.com', 'items' => array( array( 'ticket_type_id' => $sm_ta, 'quantity' => 1 ), array( 'ticket_type_id' => $sm_tp, 'quantity' => 1 ) ), 'idempotency_key' => 'sm-mixed-' . uniqid() ) );
check( 'checkout refuses an order mixing an agent organizer with platform events', is_wp_error( $sm_mixed ) && false !== strpos( $sm_mixed->get_error_message(), 'separat bestellen' ) );
$sm_mixed2 = Orders::create( array( 'email' => 'mixed2@example.com', 'items' => array( array( 'ticket_type_id' => $sm_ta, 'quantity' => 1 ), array( 'ticket_type_id' => $sm_tb2, 'quantity' => 1 ) ), 'idempotency_key' => 'sm-mixed2-' . uniqid() ) );
check( 'checkout refuses an order with two agent organizers', is_wp_error( $sm_mixed2 ) );
$sm_c = SmPartners::save( array( 'name' => 'Eigen KG', 'billing_address' => "Platz 1\n10115 Berlin", 'vat_id' => 'DE222222222', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $sm_m1 . '-01' ) );
SmPartners::set_events( $sm_c, array( sm_event( $sm_tc ) ) );
$sm_own_mix = sm_order( array( $sm_tc => 1, $sm_tp => 1 ), 'sm-own-mix' );
$sm_own_mix_inv = Invoices::issue_for_order( $sm_own_mix );
check( 'own_name organizer + platform event in one order: one platform invoice', ! is_wp_error( $sm_own_mix_inv ) && 0 === strpos( $sm_own_mix_inv['invoice_number'], 'WPC-' ) );
$GLOBALS['wpcap_test_filters']['wpcap_refuse_mixed_seller_orders'] = false;
$sm_legacy_mixed = sm_order( array( $sm_ta => 1, $sm_tp => 1 ), 'sm-legacy-mixed' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_refuse_mixed_seller_orders'] );
$sm_seq_before = array( sm_seq( 'invoice-org-' . $sm_a ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_number FROM {$p}wpcap_invoice_sequences WHERE year=%d", $sm_year ) ) );
$sm_refused = Invoices::issue_for_order( $sm_legacy_mixed );
check( 'invoice for a mixed agent order (e.g. created before the guard) is refused with a clear message', is_wp_error( $sm_refused ) && 'wpcap_invoice_mixed_sellers' === $sm_refused->get_error_code() && false !== strpos( $sm_refused->get_error_message(), 'Theater Agentur' ) && 409 === $sm_refused->get_error_data()['status'] );
check( 'refused invoice consumes no number and stores nothing', ! $wpdb->get_var( "SELECT invoice_id FROM {$p}wpcap_invoices WHERE order_id=" . (int) $sm_legacy_mixed ) && $sm_seq_before === array( sm_seq( 'invoice-org-' . $sm_a ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_number FROM {$p}wpcap_invoice_sequences WHERE year=%d", $sm_year ) ) ) );
check( 'refusal is audited', (bool) $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='invoice_refused' AND object_type='order' AND object_id=" . (int) $sm_legacy_mixed ) );

echo "Sales models: agent invoice preconditions\n";
$sm_inc = SmPartners::save( array( 'name' => 'Ohne Adresse', 'sales_model' => 'agent' ) );
$sm_ti = sm_type( 1000, 1000 );
SmPartners::set_events( $sm_inc, array( sm_event( $sm_ti ) ) );
$sm_inc_r = Invoices::issue_for_order( sm_order( array( $sm_ti => 1 ), 'sm-inc' ) );
check( 'agent invoice needs organizer address and VAT ID/tax number', is_wp_error( $sm_inc_r ) && 'wpcap_invoice_organizer_incomplete' === $sm_inc_r->get_error_code() && false !== strpos( $sm_inc_r->get_error_message(), 'Rechnungsanschrift' ) );
$sm_small = SmPartners::save( array( 'name' => 'Klein', 'billing_address' => 'Gasse 1, Linz', 'tax_number' => '46 123/4567', 'sales_model' => 'agent', 'small_business' => 1, 'small_business_note' => 'Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG' ) );
$sm_tv = sm_type( 1190, 1900 );
SmPartners::set_events( $sm_small, array( sm_event( $sm_tv ), sm_event( $sm_tz ) ) );
$sm_vat_r = Invoices::issue_for_order( sm_order( array( $sm_tv => 1 ), 'sm-small-vat' ) );
check( 'small-business organizer: invoice with VAT is refused (§ 14c)', is_wp_error( $sm_vat_r ) && 'wpcap_invoice_small_business_vat' === $sm_vat_r->get_error_code() );
$sm_zero = Invoices::issue_for_order( sm_order( array( $sm_tz => 2 ), 'sm-small-0' ) );
check( 'small-business organizer: 0 % invoice carries the small-business note', ! is_wp_error( $sm_zero ) && false !== strpos( Invoices::render_html( $sm_zero['invoice_id'] ), 'Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG' ) );

echo "Sales models: platform operator as small business\n";
PassCaptain\Settings::update( array( 'seller_small_business' => 1, 'seller_small_business_note' => '' ) );
$sm_pv = sm_type( 1190, 1900 );
$sm_pv_r = Invoices::issue_for_order( sm_order( array( $sm_pv => 1 ), 'sm-plat-small-vat' ) );
check( 'small-business platform: invoice with VAT is refused (§ 14c)', is_wp_error( $sm_pv_r ) && 'wpcap_invoice_small_business_vat' === $sm_pv_r->get_error_code() );
$sm_p0 = sm_type( 1000, 0 );
$sm_p0_r = Invoices::issue_for_order( sm_order( array( $sm_p0 => 1 ), 'sm-plat-small-0' ) );
check( 'small-business platform: 0 % invoice carries the § 19 UStG note (DE default)', ! is_wp_error( $sm_p0_r ) && false !== strpos( Invoices::render_html( $sm_p0_r['invoice_id'] ), '§ 19 UStG' ) );
PassCaptain\Settings::update( array( 'seller_country' => 'AT' ) );
check( 'Austrian platform: default note cites § 6 Abs. 1 Z 27 UStG; an own text wins', str_contains( Invoices::seller()['small_business_note'], '§ 6 Abs. 1 Z 27' ) && ( function () { PassCaptain\Settings::update( array( 'seller_small_business_note' => 'Eigener Hinweis' ) ); return 'Eigener Hinweis' === Invoices::seller()['small_business_note']; } )() );
PassCaptain\Settings::update( array( 'seller_small_business' => 0, 'seller_small_business_note' => '', 'seller_country' => 'DE' ) );
check( 'without the setting the platform has no small-business note', '' === Invoices::seller()['small_business_note'] );

echo "Sales models: settlements – agent (Provisionsrechnung)\n";
$sm_ta_m1 = SmPartners::save( array( 'name' => 'Vermittelt GmbH', 'billing_address' => "Allee 5\n50667 Köln", 'vat_id' => 'DE333333333', 'sales_model' => 'agent', 'invoice_prefix' => 'VMG', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $sm_m1 . '-01' ) );
$sm_tx = sm_type( 1190, 1900 );
SmPartners::set_events( $sm_ta_m1, array( sm_event( $sm_tx ) ) );
sm_order( array( $sm_tx => 2 ), 'sm-st-agent', $sm_t1 );
$sm_d1 = SmSettlements::create_draft( $sm_ta_m1, $sm_m1 );
$sm_draft = SmSettlements::get( $sm_d1 );
check( 'agent draft is typed as commission invoice', 'commission_invoice' === SmSettlements::document_type( $sm_draft ) && 'agent' === $sm_draft->sales_model && false !== strpos( SmSettlements::render_html( $sm_draft ), 'Entwurf Provisionsrechnung' ) );
$sm_f1 = SmSettlements::finalize( $sm_d1 );
$sm_h1 = SmSettlements::render_html( $sm_f1 );
check( 'finalized: Provisionsrechnung with PA number, no Gutschrift number', ! is_wp_error( $sm_f1 ) && 1 === preg_match( '/^PA-\d{4}-\d{6}$/', (string) $sm_f1->document_number ) && null === $sm_f1->self_billing_number && false !== strpos( $sm_h1, 'Provisionsrechnung ' . $sm_f1->document_number ) && false === strpos( $sm_h1, 'Gutschrift' ) );
check( 'commission 10 % of 20,00 + 2 × 0,50 = 3,00 + 19 % = 3,57; payout 23,80 − 3,57', 300 === (int) $sm_f1->platform_fee_net_minor && 57 === (int) $sm_f1->platform_fee_tax_minor && 2380 - 357 === (int) $sm_f1->payout_minor );
check( 'informative statement of collected ticket revenue (not an invoice)', false !== strpos( $sm_h1, 'Informative Aufstellung' ) && false !== strpos( $sm_h1, 'keine Rechnung' ) && false !== strpos( $sm_h1, 'Auszahlungsbetrag an Veranstalter' ) && false === strpos( $sm_h1, 'Reverse Charge' ) );
$sm_at = SmPartners::save( array( 'name' => 'Wiener Bühne GmbH', 'billing_address' => "Gasse 7\n1070 Wien", 'vat_id' => 'ATU12345678', 'country' => 'AT', 'vat_id_checked_at' => gmdate( 'Y-m-d' ), 'sales_model' => 'agent', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $sm_m1 . '-01' ) );
$sm_tat = sm_type( 1100, 1000 );
SmPartners::set_events( $sm_at, array( sm_event( $sm_tat ) ) );
sm_order( array( $sm_tat => 2 ), 'sm-st-at', $sm_t1 );
$sm_fat = SmSettlements::finalize( SmSettlements::create_draft( $sm_at, $sm_m1 ) );
$sm_hat = SmSettlements::render_html( $sm_fat );
check( 'AT organizer with VAT ID: commission invoice without VAT and reverse-charge note', ! is_wp_error( $sm_fat ) && 0 === (int) $sm_fat->platform_fee_tax_minor && 300 === (int) $sm_fat->platform_fee_gross_minor && false !== strpos( $sm_hat, 'Steuerschuldnerschaft des Leistungsempfängers / Reverse Charge' ) && false !== strpos( $sm_hat, 'ATU12345678' ) && false !== strpos( $sm_hat, 'DE999999999' ) );

echo "Sales models: settlements – own_name (Gutschrift + fee invoice)\n";
$sm_gu_before = sm_seq( 'credit-self' );
$sm_noid = SmPartners::save( array( 'name' => 'Ohne Steuernummer', 'billing_address' => 'Str. 1, Bonn', 'settlement_start' => $sm_m1 . '-01' ) );
$sm_tn = sm_type( 1190, 1900 );
SmPartners::set_events( $sm_noid, array( sm_event( $sm_tn ) ) );
sm_order( array( $sm_tn => 1 ), 'sm-st-noid', $sm_t1 );
$sm_dn = SmSettlements::create_draft( $sm_noid, $sm_m1 );
$sm_fn = SmSettlements::finalize( $sm_dn );
check( 'Gutschrift needs the organizer VAT ID or tax number; refused finalize consumes no numbers', is_wp_error( $sm_fn ) && 'wpcap_settlement_supplier_incomplete' === $sm_fn->get_error_code() && 'draft' === SmSettlements::get( $sm_dn )->status && $sm_gu_before === sm_seq( 'credit-self' ) );
sm_order( array( $sm_tc => 2 ), 'sm-st-own', $sm_t1 );
$sm_fc = SmSettlements::finalize( SmSettlements::create_draft( $sm_c, $sm_m1 ) );
$sm_tc_tot = SmSettlements::decoded_totals( $sm_fc );
$sm_hc = SmSettlements::render_html( $sm_fc );
check( 'own_name: Gutschrift number GU-YYYY-… from series credit-self plus PA number for the fee invoice', ! is_wp_error( $sm_fc ) && 'self_billing' === $sm_fc->document_type && 1 === preg_match( '/^GU-\d{4}-\d{6}$/', (string) $sm_fc->self_billing_number ) && 1 === preg_match( '/^PA-\d{4}-\d{6}$/', (string) $sm_fc->document_number ) && max( 1, $sm_gu_before ) === (int) substr( $sm_fc->self_billing_number, -6 ) );
check( 'Gutschrift wording: titled "Gutschrift", self-billing reference, organizer as supplier', false !== strpos( $sm_hc, 'Gutschrift ' . $sm_fc->self_billing_number ) && false !== strpos( $sm_hc, 'Teil A – Gutschrift' ) && false !== strpos( $sm_hc, '§ 14 Abs. 2 Satz 2 UStG' ) && false !== strpos( $sm_hc, 'Leistender Unternehmer (Gutschriftsempfänger)' ) && false !== strpos( $sm_hc, 'DE222222222' ) && false !== strpos( $sm_hc, 'Teil B – Rechnung über Plattformgebühren ' . $sm_fc->document_number ) && false !== strpos( $sm_hc, 'Teil C – Verrechnung' ) );
check( 'Gutschrift = net ticket revenue + VAT per rate (20,00 + 3,80 = 23,80); payout = 23,80 − 3,57', 2000 === $sm_tc_tot['self_billing']['net'] && 380 === $sm_tc_tot['self_billing']['tax'] && 2380 === $sm_tc_tot['self_billing']['gross'] && 380 === $sm_tc_tot['self_billing']['rates'][1900]['tax'] && 2023 === (int) $sm_fc->payout_minor );
$sm_ks = SmPartners::save( array( 'name' => 'Klein Eigen', 'billing_address' => 'Weg 9, Kiel', 'tax_number' => '15/123/45678', 'small_business' => 1, 'commission_bp' => 1000, 'fee_per_ticket_minor' => 0, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $sm_m1 . '-01' ) );
$sm_tks = sm_type( 1190, 1900 );
SmPartners::set_events( $sm_ks, array( sm_event( $sm_tks ) ) );
sm_order( array( $sm_tks => 1 ), 'sm-st-small', $sm_t1 );
$sm_fks = SmSettlements::finalize( SmSettlements::create_draft( $sm_ks, $sm_m1 ) );
$sm_tks_tot = SmSettlements::decoded_totals( $sm_fks );
check( 'small-business organizer: Gutschrift without VAT and with the § 19 note; numbers stay consecutive', ! is_wp_error( $sm_fks ) && 0 === $sm_tks_tot['self_billing']['tax'] && 1000 === $sm_tks_tot['self_billing']['gross'] && 1000 - 119 === (int) $sm_fks->payout_minor && false !== strpos( SmSettlements::render_html( $sm_fks ), '§ 19 UStG' ) && (int) substr( $sm_fks->self_billing_number, -6 ) === (int) substr( $sm_fc->self_billing_number, -6 ) + 1 );
$sm_ato = SmPartners::save( array( 'name' => 'Salzburg Events GmbH', 'billing_address' => 'Platz 2, 5020 Salzburg', 'vat_id' => 'ATU87654321', 'country' => 'AT', 'commission_bp' => 0, 'fee_per_ticket_minor' => 100, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $sm_m1 . '-01' ) );
$sm_tao = sm_type( 1190, 1900 );
SmPartners::set_events( $sm_ato, array( sm_event( $sm_tao ) ) );
sm_order( array( $sm_tao => 1 ), 'sm-st-ato', $sm_t1 );
$sm_fao = SmSettlements::finalize( SmSettlements::create_draft( $sm_ato, $sm_m1 ) );
$sm_hao = SmSettlements::render_html( $sm_fao );
check( 'foreign EU organizer (own_name): Gutschrift and fee invoice under reverse charge', ! is_wp_error( $sm_fao ) && 0 === SmSettlements::decoded_totals( $sm_fao )['self_billing']['tax'] && 0 === (int) $sm_fao->platform_fee_tax_minor && 2 <= substr_count( $sm_hao, 'Steuerschuldnerschaft des Leistungsempfängers / Reverse Charge' ) );
$sm_csv = PassCaptain\Reports\Scope::csv( SmSettlements::csv_rows( $sm_fc ) );
check( 'CSV names the document type and both numbers', false !== strpos( $sm_csv, 'Gutschrift + Gebührenrechnung' ) && false !== strpos( $sm_csv, $sm_fc->self_billing_number ) && false !== strpos( $sm_csv, $sm_fc->document_number ) );

echo "Sales models: finalized documents are immutable\n";
$sm_before = array( $sm_fc->totals, $sm_fc->self_billing_number, $sm_fc->document_number, $sm_fc->payout_minor, $sm_fc->partner_snapshot );
SmPartners::save( array( 'sales_model' => 'agent', 'name' => 'Eigen KG (jetzt Vermittler)', 'billing_address' => 'Neu 1', 'vat_id' => 'DE222222222', 'commission_bp' => 2000, 'settlement_start' => $sm_m1 . '-01' ), $sm_c );
$sm_after = SmSettlements::get( $sm_fc->settlement_id );
check( 'changing the sales model/terms later does not change a finalized Gutschrift', $sm_before === array( $sm_after->totals, $sm_after->self_billing_number, $sm_after->document_number, $sm_after->payout_minor, $sm_after->partner_snapshot ) && 'self_billing' === SmSettlements::document_type( $sm_after ) && false !== strpos( SmSettlements::render_html( $sm_after ), 'Platz 1' ) && false === strpos( SmSettlements::render_html( $sm_after ), 'jetzt Vermittler' ) );
check( 'finalized settlements cannot be recomputed, finalized again or deleted', is_wp_error( SmSettlements::recompute( $sm_fc->settlement_id ) ) && is_wp_error( SmSettlements::finalize( $sm_fc->settlement_id ) ) && is_wp_error( SmSettlements::delete_draft( $sm_fc->settlement_id ) ) );
$sm_dup = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_settlements SET self_billing_number=%s WHERE settlement_id=%d", $sm_fc->self_billing_number, $sm_fks->settlement_id ) );
check( 'database rejects a duplicate Gutschrift number', false === $sm_dup );
$sm_inv_before = sm_snapshot( $sm_i1['invoice_id'] );
SmPartners::save( array( 'name' => 'Theater Agentur', 'legal_name' => 'Umbenannt GmbH', 'billing_address' => 'Neu', 'vat_id' => 'DE111111111' ), $sm_a );
check( 'issued customer invoice keeps the organizer snapshot', $sm_inv_before === sm_snapshot( $sm_i1['invoice_id'] ) && false === strpos( Invoices::render_html( $sm_i1['invoice_id'] ), 'Umbenannt' ) );

echo "Sales models: admin views\n";
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
$sm_render = static function ( $cb, array $get = array() ) { $_GET = $get; ob_start(); try { call_user_func( $cb ); } catch ( Throwable $e ) { ob_end_clean(); $_GET = array(); return 'DIED:' . $e->getMessage(); } $_GET = array(); return (string) ob_get_clean(); };
$sm_list = $sm_render( array( SmAdmin::class, 'render_partners' ) );
$sm_detail = $sm_render( array( SmAdmin::class, 'render_partners' ), array( 'partner_id' => (string) $sm_inc ) );
check( 'organizer list shows the sales model column and incomplete billing data', false !== strpos( $sm_list, 'Verkaufsmodell' ) && false !== strpos( $sm_list, 'Vermittler' ) && false !== strpos( $sm_list, 'Eigenhändler' ) && false !== strpos( $sm_list, 'Rechnungsangaben unvollständig' ) );
check( 'organizer form has the sales model section and warns about missing data', false !== strpos( $sm_detail, 'name="sales_model"' ) && false !== strpos( $sm_detail, 'name="invoice_prefix"' ) && false !== strpos( $sm_detail, 'name="small_business"' ) && false !== strpos( $sm_detail, 'USt-IdNr. oder Steuernummer' ) );
$sm_st = $sm_render( array( SmAdmin::class, 'render_settlements' ) );
check( 'settlement list shows document type and Gutschrift number', false !== strpos( $sm_st, 'Provisionsrechnung (Vermittler)' ) && false !== strpos( $sm_st, $sm_fc->self_billing_number ) );
check( 'invoice seller label for admin views', 'Vermittler – Rechnungssteller: Theater Agentur GmbH' === Invoices::seller_label( $sm_s1 ) && false !== strpos( Invoices::seller_label( $sm_sp ), 'Eigenhändler' ) );

$GLOBALS['wpcap_test_hooks'] = $sm_hooks_before;
