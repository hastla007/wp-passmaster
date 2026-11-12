<?php
/**
 * B2B buyer billing data: validation (required fields, VAT ID formats, VIES filter, threshold),
 * storage on the order, invoice snapshot and rendering (HTML/PDF), order portal correction until
 * the invoice is issued, privacy export/erasure, WooCommerce mapping, box office and admin view.
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Billing;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\Printing\InvoicePdf;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

function bb_request( array $body, array $params = array() ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}
function bb_data( $res ) { return $res instanceof WP_REST_Response ? $res->get_data() : $res; }
function bb_token( $public_id ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( preg_match( '/wpcap_order=' . preg_quote( $public_id, '/' ) . '&wpcap_access=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
}
function bb_billing( array $override = array() ) {
	return array_merge( array( 'enabled' => true, 'company' => 'Muster GmbH', 'first_name' => 'Erika', 'last_name' => 'Muster', 'street' => 'Ringstraße 1/3', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT', 'vat_id' => 'ATU 1234 5678', 'po_number' => 'PO-4711' ), $override );
}
function bb_order( $type, $email, $billing, $qty = 1 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'bb-' . uniqid( '', true ), 'billing' => $billing ) );
}
function bb_field( $err ) { return is_wp_error( $err ) ? (string) ( $err->get_error_data()['field'] ?? '' ) : ''; }

echo "Billing: validation\n";
$valid_ids = array( 'ATU12345678', 'DE123456789', 'BE0123456789', 'FRXX123456789', 'NL123456789B01', 'IT12345678901', 'ESX1234567X', 'SE123456789001', 'EL123456789', 'IE1234567WA', 'CHE123456789MWST', 'PL1234567890' );
$bad_ids = array( 'ATU1234567', 'AT12345678', 'DE12345678', 'DE1234567890', 'NL123456789X01', 'XX123', 'U12345678', 'GR123456789' );
check( 'VAT ID formats: valid EU and neighbour formats accepted', ! array_filter( $valid_ids, static fn( $id ) => ! Billing::vat_id_format_valid( $id ) ) );
check( 'VAT ID formats: wrong length / prefix rejected', ! array_filter( $bad_ids, static fn( $id ) => Billing::vat_id_format_valid( $id, 'AT' ) ) );
check( 'VAT ID is normalised (spaces, dots, dashes, lower case)', 'ATU12345678' === Billing::normalize_vat_id( ' atu 1234-5678 ' ) && 'CHE123456789MWST' === Billing::normalize_vat_id( 'CHE-123.456.789 MWST' ) );
check( 'non-EU buyer without known format: plausible tax number accepted', Billing::vat_id_format_valid( 'US123456789', 'US' ) && ! Billing::vat_id_format_valid( 'US123456789', 'AT' ) );
check( 'no billing section used → null (no data)', null === Billing::validate( null ) && null === Billing::validate( array( 'enabled' => false, 'first_name' => 'X' ) ) && null === Billing::validate( array() ) );
$ok = Billing::validate( bb_billing() );
check( 'valid billing data is sanitised (VAT normalised, unknown keys dropped)', is_array( $ok ) && 'ATU12345678' === $ok['vat_id'] && 'Ringstraße 1/3' === $ok['street'] && ! isset( $ok['enabled'] ) && array_keys( $ok ) === Billing::FIELDS );
$missing = Billing::validate( bb_billing( array( 'street' => '  ' ) ) );
check( 'required address field missing → field error with the form label', is_wp_error( $missing ) && 'billing_street' === bb_field( $missing ) && 'Bitte fülle „Straße und Hausnummer“ aus.' === $missing->get_error_message() );
$no_name = Billing::validate( array( 'enabled' => true, 'company' => 'Nur Firma GmbH', 'street' => 'Weg 1', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT' ) );
check( 'name is required even with a company', is_wp_error( $no_name ) && 'billing_first_name' === bb_field( $no_name ) );
$bad_vat = Billing::validate( bb_billing( array( 'vat_id' => 'ATU123' ) ) );
check( 'invalid VAT ID → billing_vat_id error with an example', is_wp_error( $bad_vat ) && 'billing_vat_id' === bb_field( $bad_vat ) && false !== strpos( $bad_vat->get_error_message(), 'ATU12345678' ) );
$bad_zip = Billing::validate( bb_billing( array( 'country' => 'DE', 'postcode' => '1010', 'vat_id' => '' ) ) );
check( 'postcode format per country (DE: 5 digits)', is_wp_error( $bad_zip ) && 'billing_postcode' === bb_field( $bad_zip ) );
$bad_country = Billing::validate( bb_billing( array( 'country' => 'ZZ' ) ) );
check( 'unknown country rejected', is_wp_error( $bad_country ) && 'billing_country' === bb_field( $bad_country ) );
check( 'optional fields may stay empty', is_array( Billing::validate( bb_billing( array( 'company' => '', 'vat_id' => '', 'po_number' => '' ) ) ) ) );
check( 'XSS / tags are stripped', '<b>' !== substr( (string) Billing::validate( bb_billing( array( 'company' => '<b>Evil</b> AG' ) ) )['company'], 0, 3 ) && 'Evil AG' === Billing::validate( bb_billing( array( 'company' => '<b>Evil</b> AG' ) ) )['company'] );
$GLOBALS['wpcap_test_filters']['wpcap_vat_id_validate'] = new WP_Error( 'vies', 'Die UID ist laut VIES nicht gültig.' );
$vies = Billing::validate( bb_billing() );
$GLOBALS['wpcap_test_filters']['wpcap_vat_id_validate'] = false;
$vies_false = Billing::validate( bb_billing() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_vat_id_validate'] );
check( 'VIES check only via filter wpcap_vat_id_validate (WP_Error message / false)', is_wp_error( $vies ) && 'Die UID ist laut VIES nicht gültig.' === $vies->get_error_message() && is_wp_error( $vies_false ) && 'billing_vat_id' === bb_field( $vies_false ) );
check( 'no external call by default (no HTTP request queued)', is_array( Billing::validate( bb_billing() ) ) && empty( array_filter( $GLOBALS['wpcap_test_http_requests'] ?? array(), static fn( $r ) => false !== stripos( $r['url'], 'vies' ) ) ) );
$billing_src = file_get_contents( WPCAP_PATH . 'src/Billing.php' );
check( 'no reverse-charge note for admission tickets (place of supply = event location)', false === strpos( $billing_src, '__( \'Steuerschuldnerschaft' ) && false === strpos( file_get_contents( WPCAP_PATH . 'src/Invoices.php' ), 'Steuerschuldnerschaft' ) );
$saved_seller_vat = Settings::get( 'seller_vat_id' );
Settings::update( array( 'seller_vat_id' => 'DE123456789' ) );
$de = Billing::default_country();
Settings::update( array( 'seller_vat_id' => 'ATU12345678' ) );
$at = Billing::default_country();
check( 'default country follows the seller (AT/DE)', 'DE' === $de && 'AT' === $at );
Settings::update( array( 'seller_vat_id' => $saved_seller_vat ) );

echo "Billing: checkout storage and threshold\n";
$bt = make_type( 50, 10, 25000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1300 WHERE ticket_type_id=$bt" );
$with = bb_order( $bt, 'b2b@example.com', bb_billing() );
$stored = ! is_wp_error( $with ) ? Orders::billing( $with['order_id'] ) : array();
check( 'checkout stores billing data as JSON on the order; Orders::billing() returns it', 'Muster GmbH' === ( $stored['company'] ?? '' ) && 'ATU12345678' === $stored['vat_id'] && 'PO-4711' === $stored['po_number'] && null !== json_decode( (string) Orders::get( $with['order_id'] )->billing ) );
check( 'Orders::billing() accepts the order row', $stored === Orders::billing( Orders::get( $with['order_id'] ) ) );
$without = bb_order( $bt, 'b2c@example.com', null );
check( 'without the section no billing data is stored', ! is_wp_error( $without ) && array() === Orders::billing( $without['order_id'] ) && null === Orders::get( $without['order_id'] )->billing );
$bad = bb_order( $bt, 'bad@example.com', bb_billing( array( 'city' => '' ) ) );
check( 'invalid billing data blocks the order before anything is reserved', is_wp_error( $bad ) && 'billing_city' === bb_field( $bad ) && 400 === $bad->get_error_data()['status'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email='bad@example.com'" ) );
$GLOBALS['wpcap_test_filters']['wpcap_billing_required_threshold'] = 40000;
$over = bb_order( $bt, 'over@example.com', null, 2 );
$under = bb_order( $bt, 'under@example.com', null, 1 );
$over_ok = bb_order( $bt, 'over-ok@example.com', bb_billing(), 2 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_billing_required_threshold'] );
check( 'threshold filter: above the amount billing data is required (field billing_enabled)', is_wp_error( $over ) && 'wpcap_billing_required' === $over->get_error_code() && 'billing_enabled' === bb_field( $over ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email='over@example.com'" ) );
check( 'threshold filter: below the amount / with data the order works; default off', ! is_wp_error( $under ) && ! is_wp_error( $over_ok ) && 0 === Billing::required_threshold() );
$evil = Orders::create( array( 'email' => 'evil-b@example.com', 'items' => array( array( 'ticket_type_id' => $bt, 'quantity' => 1 ) ), 'idempotency_key' => 'bb-evil-' . uniqid(), 'billing' => bb_billing( array( 'vat_id' => 'NOPE' ) ) ), array( 'billing' => bb_billing( array( 'vat_id' => 'NOPE' ) ) ) );
check( 'public callers cannot skip validation via the trusted adapter option', is_wp_error( $evil ) && 'billing_vat_id' === bb_field( $evil ) );

echo "Billing: REST checkout\n";
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0 ) );
$GLOBALS['wpcap_test_transients'] = array();
$rest = bb_data( Controller::checkout( bb_request( array( 'email' => 'rest-b2b@example.com', 'items' => array( array( 'ticket_type_id' => $bt, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'bb-rest-' . uniqid(), 'billing' => bb_billing( array( 'company' => 'REST AG' ) ) ) ) ) );
check( 'REST checkout passes the billing payload key through', is_array( $rest ) && 'REST AG' === ( Orders::billing( $rest['order_id'] )['company'] ?? '' ) );
$GLOBALS['wpcap_test_transients'] = array();
$rest_err = Controller::checkout( bb_request( array( 'email' => 'rest-bad@example.com', 'items' => array( array( 'ticket_type_id' => $bt, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'bb-rest-bad-' . uniqid(), 'billing' => bb_billing( array( 'postcode' => '' ) ) ) ) );
check( 'REST error carries data.field for the inline error (billing_postcode)', is_wp_error( $rest_err ) && 'billing_postcode' === bb_field( $rest_err ) );

echo "Billing: invoice snapshot and rendering\n";
Settings::update( array( 'seller_name' => 'Veranstalter GmbH', 'seller_address' => "Ring 1\n1010 Wien", 'seller_vat_id' => 'ATU12345678' ) );
Orders::confirm_manual_payment( $with['order_id'] );
$inv = Invoices::issue_for_order( $with['order_id'] );
$snap = json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $inv['invoice_id'] ), true );
check( 'buyer snapshot contains company, name, address, VAT ID and PO number', 'Muster GmbH' === $snap['company'] && 'Erika Muster' === $snap['name'] && 'Ringstraße 1/3' === $snap['street'] && '1010' === $snap['postcode'] && 'Wien' === $snap['city'] && 'AT' === $snap['country'] && 'Österreich' === $snap['country_name'] && 'ATU12345678' === $snap['vat_id'] && 'PO-4711' === $snap['po_number'] && 'b2b@example.com' === $snap['email'] && isset( $snap['seller'] ) );
$html = Invoices::render_html( $inv['invoice_id'] );
check( 'HTML invoice: recipient block with company, name, address, country', 1 === preg_match( '#<div class="buyer"><strong>Rechnungsempfänger:</strong>\s*Muster GmbH\nErika Muster\nRingstraße 1/3\n1010 Wien\nÖsterreich\n#u', $html ) );
check( 'HTML invoice: VAT ID, buyer reference and e-mail in the recipient block', false !== strpos( $html, 'UID des Leistungsempfängers: ATU12345678' ) && false !== strpos( $html, 'Ihre Bestell-/Referenznummer: PO-4711' ) && false !== strpos( $html, "PO-4711\nb2b@example.com</div>" ) );
check( 'HTML invoice: no reverse-charge note', false === strpos( $html, 'Steuerschuldnerschaft' ) && false === strpos( $html, 'Reverse' ) );
$pdf = InvoicePdf::render( $inv['invoice_id'] );
check( 'PDF invoice: recipient block rendered', false !== strpos( $pdf, '(Muster GmbH) Tj' ) && false !== strpos( $pdf, '(Erika Muster) Tj' ) && false !== strpos( $pdf, '(1010 Wien) Tj' ) && false !== strpos( $pdf, 'ATU12345678) Tj' ) && false !== strpos( $pdf, 'PO-4711) Tj' ) && false !== strpos( $pdf, '(b2b@example.com) Tj' ) );
Orders::confirm_manual_payment( $without['order_id'] );
$plain = Invoices::issue_for_order( $without['order_id'] );
$plain_html = Invoices::render_html( $plain['invoice_id'] );
$plain_pdf = InvoicePdf::render( $plain['invoice_id'] );
check( 'invoice without billing data keeps the e-mail-only recipient line', false === strpos( $plain_html, 'class="buyer"' ) && false !== strpos( $plain_html, 'Rechnungsempfänger: b2c@example.com' ) && false === strpos( $plain_pdf, 'Leistungsempf' ) );
$refund = PassCaptain\Refunds::request( $with['order_id'], array( (int) tickets( $with['order_id'] )[0]->ticket_id ), 'Test', 'bb-cn-' . uniqid() );
$cn = Invoices::credit_note_for_refund( $refund['refund_id'] );
$cn_pdf = InvoicePdf::admin_pdf( $with['order_id'], (int) ( $cn['credit_note_id'] ?? 0 ) );
check( 'credit note PDF names the recipient from the invoice snapshot', ! is_wp_error( $cn_pdf ) && false !== strpos( $cn_pdf['pdf'], '(Muster GmbH) Tj' ) );

echo "Billing: order portal correction\n";
$portal = bb_order( $bt, 'portal-b2b@example.com', null );
$portal_order = Orders::get( $portal['order_id'] );
$token = bb_token( $portal_order->public_id );
$GLOBALS['wpcap_test_transients'] = array();
$portal_html = PassCaptain\OrderPortal::render_page( $portal_order, $token );
check( 'portal shows an editable billing form before the invoice', false !== strpos( $portal_html, 'class="wpcap-billing-form"' ) && false !== strpos( $portal_html, '/billing' ) && false !== strpos( $portal_html, 'data-billing="vat_id"' ) );
$denied = Controller::update_billing( bb_request( array( 'access_token' => str_repeat( 'a', 64 ), 'billing' => bb_billing() ), array( 'public_id' => $portal_order->public_id ) ) );
check( 'portal update needs the access token', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$invalid = Controller::update_billing( bb_request( array( 'access_token' => $token, 'billing' => bb_billing( array( 'vat_id' => 'DE1' ) ) ), array( 'public_id' => $portal_order->public_id ) ) );
check( 'portal update validates like the checkout', is_wp_error( $invalid ) && 'billing_vat_id' === bb_field( $invalid ) && array() === Orders::billing( $portal['order_id'] ) );
$upd = bb_data( Controller::update_billing( bb_request( array( 'access_token' => $token, 'billing' => bb_billing( array( 'company' => 'Korrigiert KG', 'country' => 'DE', 'postcode' => '80331', 'city' => 'München', 'vat_id' => 'DE123456789' ) ) ), array( 'public_id' => $portal_order->public_id ) ) ) );
check( 'portal update stores the corrected data', is_array( $upd ) && 'Korrigiert KG' === Orders::billing( $portal['order_id'] )['company'] && 'DE' === Orders::billing( $portal['order_id'] )['country'] );
Orders::confirm_manual_payment( $portal['order_id'] );
$portal_inv = Invoices::issue_for_order( $portal['order_id'] );
$locked = Controller::update_billing( bb_request( array( 'access_token' => $token, 'billing' => bb_billing( array( 'company' => 'Zu spät GmbH' ) ) ), array( 'public_id' => $portal_order->public_id ) ) );
check( 'after the invoice is issued the data is locked (409)', is_wp_error( $locked ) && 409 === $locked->get_error_data()['status'] && 'Korrigiert KG' === Orders::billing( $portal['order_id'] )['company'] );
$portal_html2 = PassCaptain\OrderPortal::render_page( Orders::get( $portal['order_id'] ), $token );
check( 'portal shows read-only data after the invoice', false === strpos( $portal_html2, 'class="wpcap-billing-form"' ) && false !== strpos( $portal_html2, 'Korrigiert KG' ) && false !== strpos( $portal_html2, 'bereits ausgestellt' ) );
$clear_order = bb_order( $bt, 'clear-b2b@example.com', bb_billing() );
$clear_token = bb_token( Orders::get( $clear_order['order_id'] )->public_id );
$GLOBALS['wpcap_test_transients'] = array();
$cleared = bb_data( Controller::update_billing( bb_request( array( 'access_token' => $clear_token, 'billing' => array( 'enabled' => false ) ), array( 'public_id' => Orders::get( $clear_order['order_id'] )->public_id ) ) ) );
check( 'portal: unchecking removes the billing data', is_array( $cleared ) && array() === Orders::billing( $clear_order['order_id'] ) );

echo "Billing: privacy export and erasure\n";
$export = Privacy::export( 'b2b@example.com' );
$export_values = array();
foreach ( $export['data'] as $item ) { foreach ( $item['data'] as $row ) { $export_values[ $row['name'] ] = $row['value']; } }
check( 'export contains the billing data of the order', false !== strpos( $export_values['Rechnungsdaten (Firma, Name, Anschrift, UID, Referenz)'] ?? '', 'Muster GmbH' ) && false !== strpos( $export_values['Rechnungsdaten (Firma, Name, Anschrift, UID, Referenz)'], 'ATU12345678' ) );
$erase_order = bb_order( $bt, 'erase-b2b@example.com', bb_billing() );
Orders::confirm_manual_payment( $erase_order['order_id'] );
$erase_inv = Invoices::issue_for_order( $erase_order['order_id'] );
$erase_pending = bb_order( $bt, 'erase-b2b@example.com', bb_billing( array( 'company' => 'Zweite GmbH' ) ) );
$erased = Privacy::erase( 'erase-b2b@example.com' );
$erase_snap = json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $erase_inv['invoice_id'] ), true );
check( 'erasure removes the billing data from the orders', array() === Orders::billing( $erase_order['order_id'] ) && array() === Orders::billing( $erase_pending['order_id'] ) && null === Orders::get( $erase_order['order_id'] )->billing );
check( 'issued invoice keeps its recipient snapshot (Art. 17 Abs. 3 lit. b)', 'Muster GmbH' === ( $erase_snap['company'] ?? '' ) && 'Erika Muster' === ( $erase_snap['name'] ?? '' ) && false !== strpos( Invoices::render_html( $erase_inv['invoice_id'] ), 'Muster GmbH' ) );
check( 'erasure message explains the billing data handling', (bool) array_filter( $erased['messages'], static fn( $m ) => false !== strpos( $m, 'Rechnungsdaten der Bestellungen' ) && false !== strpos( $m, 'Art. 17 Abs. 3 lit. b' ) ) );

echo "Billing: WooCommerce mapping\n";
class Bb_Woo_Order extends WC_Order {
	public array $billing = array();
	public function get_billing_company() { return $this->billing['company'] ?? ''; }
	public function get_billing_first_name() { return $this->billing['first_name'] ?? ''; }
	public function get_billing_last_name() { return $this->billing['last_name'] ?? ''; }
	public function get_billing_address_1() { return $this->billing['address_1'] ?? ''; }
	public function get_billing_address_2() { return $this->billing['address_2'] ?? ''; }
	public function get_billing_postcode() { return $this->billing['postcode'] ?? ''; }
	public function get_billing_city() { return $this->billing['city'] ?? ''; }
	public function get_billing_country() { return $this->billing['country'] ?? ''; }
}
$wt = make_type( 20, 10, 5000 );
$wc = new Bb_Woo_Order( 88001 );
$wc->email = 'woo-b2b@example.com';
$wc->billing = array( 'company' => 'Woo Handels GmbH', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'address_1' => 'Hauptstraße 5', 'address_2' => 'Top 3', 'postcode' => '4020', 'city' => 'Linz', 'country' => 'AT' );
$wc->update_meta_data( 'billing_eu_vat_number', 'ATU 9876 5432' );
$product = new WC_Product( 88501 );
$product->update_meta_data( Woo::PRODUCT_META, $wt );
$GLOBALS['wpcap_test_products'][88501] = $product;
$wc->items[] = new WC_Order_Item_Product( 88601, $product, 2, '100.00' );
$mapped = Woo::billing_from_order( $wc );
check( 'Woo billing fields mapped (address lines joined, VAT from billing_eu_vat_number)', 'Woo Handels GmbH' === $mapped['company'] && 'Hauptstraße 5, Top 3' === $mapped['street'] && 'ATU98765432' === $mapped['vat_id'] && 'AT' === $mapped['country'] && 'Linz' === $mapped['city'] );
$wc_vat = new Bb_Woo_Order( 88002 );
$wc_vat->billing = array( 'first_name' => 'A', 'last_name' => 'B' );
$wc_vat->update_meta_data( '_vat_number', 'DE123456789' );
$wc_vat2 = new Bb_Woo_Order( 88003 );
$wc_vat2->billing = array( 'first_name' => 'A', 'last_name' => 'B' );
$wc_vat2->update_meta_data( '_billing_vat', 'DE987654321' );
check( 'VAT ID from _vat_number / _billing_vat', 'DE123456789' === Woo::billing_from_order( $wc_vat )['vat_id'] && 'DE987654321' === Woo::billing_from_order( $wc_vat2 )['vat_id'] );
$wc_vat3 = new Bb_Woo_Order( 88004 );
$wc_vat3->billing = array( 'first_name' => 'A', 'last_name' => 'B' );
$wc_vat3->update_meta_data( '_my_vat', 'ATU11112222' );
$GLOBALS['wpcap_test_filters']['wpcap_woocommerce_vat_meta_keys'] = array( '_my_vat' );
$custom_key = Woo::billing_from_order( $wc_vat3 )['vat_id'];
unset( $GLOBALS['wpcap_test_filters']['wpcap_woocommerce_vat_meta_keys'] );
check( 'VAT meta keys are filterable', 'ATU11112222' === $custom_key );
$anon = new Bb_Woo_Order( 88005 );
$anon->billing = array( 'country' => 'AT' );
check( 'Woo order without name/company → no billing data', array() === Woo::billing_from_order( $anon ) && array() === Woo::billing_from_order( new WC_Order( 88006 ) ) );
$core_id = Woo::reserve( $wc );
check( 'reserve() stores the Woo billing data on the core order', is_int( $core_id ) && 'Woo Handels GmbH' === ( Orders::billing( $core_id )['company'] ?? '' ) );
$wc->billing['company'] = 'Woo Handels GmbH & Co KG';
Woo::sync_paid_order( 88001 );
$woo_paid = Orders::get( $core_id );
Invoices::issue_for_order( $core_id );
$woo_snap = json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE order_id=" . (int) $core_id ), true );
check( 'payment sync refreshes billing; the Pass Captain invoice carries the Woo data', 'paid' === $woo_paid->payment_status && 'Woo Handels GmbH & Co KG' === ( $woo_snap['company'] ?? '' ) && 'ATU98765432' === ( $woo_snap['vat_id'] ?? '' ) && 'Max Mustermann' === ( $woo_snap['name'] ?? '' ) );

echo "Billing: box office\n";
PassCaptain\Modules\BoxOffice::register();
$pos_type = make_type( 20, 10, 9000 );
$pos_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pos_type" );
PassCaptain\BoxOffice\Service::open_session( 0 );
$form = PassCaptain\BoxOffice\Page::sale_input( array( 'occurrence_id' => $pos_occ, 'qty' => array( $pos_type => 1 ), 'method' => 'invoice', 'email' => 'pos-b2b@example.com', 'form_key' => 'bb-pos-' . bin2hex( random_bytes( 8 ) ), 'billing' => array( 'company' => 'Kassen GmbH', 'first_name' => 'Kurt', 'last_name' => 'Kasse', 'street' => 'Platz 2', 'postcode' => '8010', 'city' => 'Graz', 'country' => 'AT', 'vat_id' => 'ATU12345678', 'po_number' => 'B-12' ) ) );
check( 'POS form maps the billing fields', is_array( $form['billing'] ) && 'Kassen GmbH' === $form['billing']['company'] && ! empty( $form['billing']['enabled'] ) );
$only_country = PassCaptain\BoxOffice\Page::sale_input( array( 'billing' => array( 'country' => 'AT' ) ) );
check( 'POS form: only the preselected country → no billing data', null === $only_country['billing'] );
$pos = PassCaptain\BoxOffice\Service::sell( $form );
$pos_order_id = is_wp_error( $pos ) ? 0 : (int) $pos['sale']->order_id;
$pos_snap = json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE order_id=" . $pos_order_id ), true );
check( 'POS sale on invoice: invoice carries the company data', $pos_order_id && 'Kassen GmbH' === ( $pos_snap['company'] ?? '' ) && 'B-12' === ( $pos_snap['po_number'] ?? '' ) && 'Kassen GmbH' === Orders::billing( $pos_order_id )['company'] );
$pos_bad = PassCaptain\BoxOffice\Service::sell( array_merge( $form, array( 'form_key' => 'bb-pos-' . bin2hex( random_bytes( 8 ) ), 'billing' => array( 'enabled' => true, 'company' => 'X', 'country' => 'AT' ) ) ) );
check( 'POS sale: incomplete billing data rejected before a sale row exists', is_wp_error( $pos_bad ) && 'billing_first_name' === bb_field( $pos_bad ) );
ob_start();
$ref = new ReflectionMethod( PassCaptain\BoxOffice\Page::class, 'render_billing_fields' );
$ref->setAccessible( true );
$ref->invoke( null );
$pos_html = ob_get_clean();
check( 'POS form renders the collapsed "Rechnung auf Firma" fields with labels', false !== strpos( $pos_html, '<details class="wpcap-pos-billing">' ) && false !== strpos( $pos_html, 'name="billing[vat_id]"' ) && 1 === preg_match( '#<label for="wpcap-pos-billing-street">Straße und Hausnummer</label>#u', $pos_html ) );

echo "Billing: checkout form and admin view\n";
$card_type = make_type( 30, 5, 1990 );
$card_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$card_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='bb_hidden' WHERE status='scheduled' AND occurrence_id<>$card_occ" );
$card = PassCaptain\PublicEvents::render_shortcode();
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled' WHERE status='bb_hidden'" );
check( 'event card: collapsed billing section with checkbox and fields', false !== strpos( $card, '<legend>Rechnung mit Firmen-/Adressdaten</legend>' ) && false !== strpos( $card, 'name="billing_enabled"' ) && 1 === preg_match( '#class="wpcap-billing-fields" id="(wpcap-\d+-billing-fields)" hidden>#', $card, $bm ) && false !== strpos( $card, 'aria-controls="' . $bm[1] . '"' ) );
check( 'event card: labelled inputs (for/id), required markers, autocomplete', 1 === preg_match( '#<label for="(wpcap-\d+-billing-first_name)">Vorname \*</label>\s*<input id="\1" name="billing_first_name" type="text" autocomplete="given-name" maxlength="100" required>#u', $card ) && false !== strpos( $card, 'Firma (optional)' ) && false !== strpos( $card, 'name="billing_country" autocomplete="country" required' ) && false !== strpos( $card, 'UID-Nummer (optional)' ) );
check( 'event card: country select with AT/DE', false !== strpos( $card, '<option value="AT"' ) && false !== strpos( $card, '<option value="DE"' ) );
$js = file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'checkout.js sends billing only when checked and opens the section for server errors', false !== strpos( $js, 'function billingPayload' ) && false !== strpos( $js, 'extra.billing = billing' ) && false !== strpos( $js, "field.indexOf('billing_') === 0" ) );
$admin_src = file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' );
check( 'admin order detail shows the billing data', false !== strpos( $admin_src, "esc_html_e( 'Rechnungsdaten'" ) && false !== strpos( $admin_src, 'Billing::for_order( $order )' ) );
check( 'schema: billing column on wpcap_orders, DB version 3.5.0', '3.5.0' === PassCaptain\Installer::DB_VERSION && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_orders LIKE 'billing'" ) );

echo "Billing: default threshold\n";
// Earlier admin tests save the settings form, so read the default from defaults() and set it explicitly.
$bb_defaults = PassCaptain\Settings::defaults();
PassCaptain\Settings::update( array( 'billing_required_threshold_minor' => 25000 ) );
check( 'name and address are required above 250 € by default (setting, 0 switches it off)', 25000 === $bb_defaults['billing_required_threshold_minor'] && 25000 === PassCaptain\Billing::required_threshold( 'EUR' ) && PassCaptain\Billing::required_for( 25001, 'EUR' ) && ! PassCaptain\Billing::required_for( 25000, 'EUR' ) );
PassCaptain\Settings::update( array( 'billing_required_threshold_minor' => 0 ) );
check( 'threshold 0 switches the requirement off', ! PassCaptain\Billing::required_for( 99999999, 'EUR' ) );
