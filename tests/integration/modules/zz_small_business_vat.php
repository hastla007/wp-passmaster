<?php
/**
 * Small-business sellers (Kleinunternehmer) never sell with VAT: saving a ticket type with a rate > 0 is
 * refused (event form, create event, CSV import), the checkout refuses such orders instead of creating a
 * paid order that cannot be invoiced, and existing types are listed in Site Health. Agent model: the
 * organizer is named as contract partner (checkout notices, ticket/access mails, order portal).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Import;
use PassCaptain\Frontend\LegalNotice;
use PassCaptain\Operations\SiteHealth;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as SbPartners;
use PassCaptain\Partners\SalesModel;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
// Order portal stubs (as zz_a682).
if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
PassCaptain\Payments\Registry::reset();
Settings::update( array( 'seller_name' => 'Plattform GmbH', 'seller_address' => "Ring 1\n1010 Wien", 'seller_vat_id' => 'DE999999999', 'seller_country' => 'DE', 'seller_small_business' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
PassCaptain\Modules\Partners::register();

function sb_type( $price, $tax_bp ) {
	global $wpdb;
	$type = make_type( 100, 10, $price );
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_ticket_types SET tax_rate_bp=" . (int) $tax_bp . ' WHERE ticket_type_id=' . (int) $type );
	return $type;
}
function sb_event( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function sb_occ( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}
function sb_buy( $type, $key ) {
	$GLOBALS['wpcap_test_transients'] = array(); // no checkout rate limit between the test orders
	return Orders::create( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => $key . '-' . uniqid() ) );
}
$sb_form = static function ( $type, $tax ) {
	return array( 'ticket_type_id' => $type, 'name' => 'Standard', 'price' => '11,90', 'currency' => 'EUR', 'tax_rate' => $tax, 'max_per_order' => 10, 'capacity' => 100, 'status' => 'active', 'confirm_sold_change' => 1 );
};

echo "Small business: platform as seller\n";
$sb_t19 = sb_type( 1190, 1900 );
$sb_t0 = sb_type( 1000, 0 );
$sb_before = sb_buy( $sb_t19, 'sb-before' );
check( 'SB: without the setting a 19 % order is created as before', ! is_wp_error( $sb_before ) );
check( 'SB: without the setting saving 19 % is allowed', ! is_wp_error( EventsPage::save_ticket_type( $sb_form( $sb_t19, '19' ) ) ) );
check( 'SB: without the setting the 19 % platform type is not listed in Site Health', ! in_array( $sb_t19, array_column( SalesModel::small_business_vat_types( 500 ), 'ticket_type_id' ), true ) );

Settings::update( array( 'seller_small_business' => 1 ) );
$sb_err = EventsPage::save_ticket_type( $sb_form( $sb_t19, '19' ) );
check( 'SB: small-business platform: saving a type with 19 % is refused with a field error', is_wp_error( $sb_err ) && 'wpcap_type_small_business_vat' === $sb_err->get_error_code() && 'tax_rate' === ( $sb_err->get_error_data()['field'] ?? '' ) && false !== strpos( $sb_err->get_error_message(), 'Kleinunternehmer' ) );
check( 'SB: the refused save left the stored rate unchanged (no silent price change)', 1900 === (int) $wpdb->get_var( "SELECT tax_rate_bp FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $sb_t19 ) );
check( 'SB: saving 0 % is allowed', ! is_wp_error( EventsPage::save_ticket_type( $sb_form( $sb_t0, '0' ) ) ) );
$sb_new = EventsPage::save_ticket_type( array( 'occurrence_id' => sb_occ( $sb_t0 ), 'name' => 'Neu', 'price' => '5', 'currency' => 'EUR', 'tax_rate' => '7', 'max_per_order' => 5 ) );
check( 'SB: a new type with 7 % is refused too', is_wp_error( $sb_new ) && 'tax_rate' === ( $sb_new->get_error_data()['field'] ?? '' ) );
$sb_ev = EventsPage::create_event( array( 'name' => 'SB Gala', 'starts_at' => gmdate( 'Y-m-d\TH:i', time() + 20 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'ticket_name' => 'Parkett', 'price' => '12,50', 'currency' => 'EUR', 'tax_rate' => '19', 'status' => 'draft' ) );
check( 'SB: create event with a 19 % first type is refused (field tax_rate)', is_wp_error( $sb_ev ) && 'wpcap_type_small_business_vat' === $sb_ev->get_error_code() && 'tax_rate' === ( $sb_ev->get_error_data()['field'] ?? '' ) );
$sb_plan = Import::plan( "event,starts_at,ticket,price,tax_rate\nSB Import,2030-05-01 19:00,Steh,10,19\nSB Import,2030-05-01 19:00,Sitz,10,0\n", 'Europe/Vienna' );
check( 'SB: CSV import names the row with VAT and imports nothing', 1 === count( $sb_plan['errors'] ) && false !== strpos( $sb_plan['errors'][0], 'Zeile 2' ) && false !== strpos( $sb_plan['errors'][0], 'Kleinunternehmer' ) );
check( 'SB: profile rate > 0 with form rate 0 is refused on the tax class field', 'tax_class' === ( SalesModel::small_business_type_error( 0, 0, 1900 )->get_error_data()['field'] ?? '' ) && null === SalesModel::small_business_type_error( 0, 0, 0 ) );

$sb_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
$sb_refused = sb_buy( $sb_t19, 'sb-vat' );
check( 'SB: checkout refuses an order with VAT for a small-business platform (reason small_business_vat, 409)', is_wp_error( $sb_refused ) && 'wpcap_checkout_failed' === $sb_refused->get_error_code() && 'small_business_vat' === ( $sb_refused->get_error_data()['reason'] ?? '' ) && 409 === ( $sb_refused->get_error_data()['status'] ?? 0 ) );
check( 'SB: the buyer message is safe (no tax law, no internals) and no order row is left', is_wp_error( $sb_refused ) && false === strpos( $sb_refused->get_error_message(), '§' ) && false !== strpos( $sb_refused->get_error_message(), 'Veranstalter' ) && $sb_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) );
check( 'SB: a 0 % order is created', ! is_wp_error( sb_buy( $sb_t0, 'sb-zero' ) ) );
$sb_health = SiteHealth::test_small_business_vat();
check( 'SB: Site Health lists the existing 19 % type (critical, with event and rate)', 'critical' === $sb_health['status'] && 'wpcap_small_business_vat' === $sb_health['test'] && false !== strpos( $sb_health['description'], '19 %' ) && isset( SiteHealth::test_callbacks()['small_business_vat'] ) );
check( 'SB: the list contains the type, not the 0 % one', in_array( $sb_t19, array_column( SalesModel::small_business_vat_types( 500 ), 'ticket_type_id' ), true ) && ! in_array( $sb_t0, array_column( SalesModel::small_business_vat_types( 500 ), 'ticket_type_id' ), true ) );

echo "Small business: organizer in the agent model\n";
$sb_org_t = sb_type( 1190, 1900 );
$sb_own_t = sb_type( 1190, 1900 );
$sb_agent = SbPartners::save( array( 'name' => 'Klein Agentur', 'legal_name' => 'Klein Agentur e.U.', 'billing_address' => "Gasse 1\n4020 Linz", 'tax_number' => '46 123/4567', 'sales_model' => 'agent', 'small_business' => 1 ) );
$sb_own = SbPartners::save( array( 'name' => 'Klein Eigen', 'billing_address' => 'Weg 9, Kiel', 'tax_number' => '15/123/45678', 'small_business' => 1 ) );
SbPartners::set_events( $sb_agent, array( sb_event( $sb_org_t ) ) );
SbPartners::set_events( $sb_own, array( sb_event( $sb_own_t ) ) );
$sb_oerr = EventsPage::save_ticket_type( $sb_form( $sb_org_t, '19' ) );
check( 'SB: small-business agent organizer: saving 19 % is refused and names the organizer', is_wp_error( $sb_oerr ) && false !== strpos( $sb_oerr->get_error_message(), 'Klein Agentur e.U.' ) );
$sb_oref = sb_buy( $sb_org_t, 'sb-org-vat' );
check( 'SB: checkout refuses VAT on tickets of a small-business agent organizer', is_wp_error( $sb_oref ) && 'small_business_vat' === ( $sb_oref->get_error_data()['reason'] ?? '' ) );
Settings::update( array( 'seller_small_business' => 0 ) );
check( 'SB: own_name organizer that is a small business: the platform sells, VAT stays allowed', ! is_wp_error( EventsPage::save_ticket_type( $sb_form( $sb_own_t, '19' ) ) ) && ! is_wp_error( sb_buy( $sb_own_t, 'sb-own' ) ) );
check( 'SB: Site Health still lists the agent organizer type', in_array( $sb_org_t, array_column( SalesModel::small_business_vat_types( 500 ), 'ticket_type_id' ), true ) && ! in_array( $sb_own_t, array_column( SalesModel::small_business_vat_types( 500 ), 'ticket_type_id' ), true ) );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=0 WHERE ticket_type_id=" . (int) $sb_org_t );

echo "Contract partner in the agent model\n";
$sb_card = LegalNotice::checkout_html( (object) array( 'event_id' => sb_event( $sb_org_t ), 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array() );
check( 'CP: checkout notices name the organizer with address in the agent model', false !== strpos( $sb_card, 'wpcap-contract-partner' ) && false !== strpos( $sb_card, 'Vertragspartner: Klein Agentur e.U., Gasse 1, 4020 Linz' ) );
check( 'CP: own_name organizer and platform events: no contract partner line', false === strpos( LegalNotice::checkout_html( (object) array( 'event_id' => sb_event( $sb_own_t ) ), array() ), 'Vertragspartner' ) && false === strpos( LegalNotice::checkout_html( (object) array( 'event_id' => sb_event( $sb_t0 ) ), array() ), 'Vertragspartner' ) );
$sb_ao = sb_buy( $sb_org_t, 'sb-cp' );
Orders::confirm_manual_payment( $sb_ao['order_id'] );
$sb_order = Orders::get( $sb_ao['order_id'] );
$sb_mail = PassCaptain\Mail\Mailer::compose( 'tickets', Orders::ticket_mail_vars( $sb_order, array(), PassCaptain\Mail\Blocks::links( array() ) ) );
$sb_access = PassCaptain\Mail\Mailer::compose( 'order_access', array( 'order_id' => (string) $sb_order->public_id, 'access_link' => 'https://example.test/x' ) );
check( 'CP: ticket mail and access mail name the contract partner', false !== strpos( (string) $sb_mail['body'], 'Vertragspartner: Klein Agentur e.U.' ) && false !== strpos( (string) $sb_access['body'], 'Vertragspartner: Klein Agentur e.U.' ) );
$sb_po = sb_buy( $sb_t0, 'sb-cp-plat' );
$sb_pmail = PassCaptain\Mail\Mailer::compose( 'tickets', Orders::ticket_mail_vars( Orders::get( $sb_po['order_id'] ), array(), PassCaptain\Mail\Blocks::links( array() ) ) );
check( 'CP: platform order: mail without contract partner line', false === strpos( (string) $sb_pmail['body'], 'Vertragspartner' ) && isset( PassCaptain\Mail\Types::all()['tickets']['placeholders']['contract_partner'], PassCaptain\Mail\Types::all()['order_access']['placeholders']['contract_partner'] ) );
try { $sb_portal = (string) PassCaptain\OrderPortal::render_page( $sb_order, str_repeat( 'a', 64 ) ); } catch ( Throwable $e ) { $sb_portal = 'EXCEPTION ' . $e->getMessage(); }
try { $sb_pportal = (string) PassCaptain\OrderPortal::render_page( Orders::get( $sb_po['order_id'] ), str_repeat( 'a', 64 ) ); } catch ( Throwable $e ) { $sb_pportal = 'EXCEPTION ' . $e->getMessage(); }
check( 'CP: order portal shows the contract partner only in the agent model', false !== strpos( $sb_portal, 'Vertragspartner: Klein Agentur e.U.' ) && false === strpos( $sb_pportal, 'Vertragspartner' ) && false === strpos( $sb_pportal, 'EXCEPTION' ) );

Settings::update( array( 'seller_small_business' => 0 ) );
