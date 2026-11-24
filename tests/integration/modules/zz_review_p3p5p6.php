<?php
/**
 * Review findings P3 (country presets, payment rules, net prices, money display), P5 (accessibility and
 * translation of the order link page, manual holds, settings history, voucher reconciliation) and P6
 * (contracts and docs of the public, app and offline interfaces).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__, 2 ) . '/contracts/shape.php';

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\SettingsTools;
use PassCaptain\Contracts;
use PassCaptain\CountryPresets;
use PassCaptain\Integrations\Vouchers\ReconciliationReport;
use PassCaptain\Localization;
use PassCaptain\Money;
use PassCaptain\OfflineScanner\Package;
use PassCaptain\OrderLinkRequest;
use PassCaptain\Payments\Rules;
use PassCaptain\Security\FormGuard;
use PassCaptain\Settings;
use PassCaptain\SettingsManager;

if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$rv_saved = get_option( Settings::OPTION, array() );
$rv_root = dirname( __DIR__, 3 );
$rv_doc = static fn( $file ) => (string) file_get_contents( $rv_root . '/docs/' . $file );

echo "Review P6: contracts and docs\n";
$rv_public = wpcap_contract_fixture( 'public' );
$rv_app = wpcap_contract_fixture( 'app' );
$rv_offline = wpcap_contract_fixture( 'offline' );
check( 'P6-1: public contract is 1.3 (minor bump for link-request and form_guard); the fixture names the same version', '1.3' === Contracts::version( 'public' ) && '1.3' === $rv_public['version'] );
check( 'P6-1: the public fixture has POST /orders/link-request and the optional checkout param form_guard', isset( $rv_public['endpoints']['POST /orders/link-request']['response']['message'] ) && isset( $rv_public['endpoints']['POST /checkout']['request']['form_guard']['ms'] ) );
OrderLinkRequest::register_routes();
$rv_route = $GLOBALS['wpcap_test_routes']['pass-captain/v1/orders/link-request'] ?? null;
$rv_req = new WP_REST_Request( 'POST' );
$rv_req->route = '/pass-captain/v1/orders/link-request';
$rv_req->set_param( 'email', 'rv-nobody-' . uniqid() . '@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
$rv_res = is_array( $rv_route ) ? call_user_func( $rv_route['callback'], $rv_req ) : null;
$rv_res = $rv_res instanceof WP_REST_Response ? $rv_res->get_data() : $rv_res;
check( 'P6-1: POST /orders/link-request answers the fixture shape (same message for an unknown address)', is_array( $rv_res ) && ! wpcap_contract_violations( $rv_public['endpoints']['POST /orders/link-request']['response'], $rv_res ) && OrderLinkRequest::generic_message() === $rv_res['message'] && 'public' === Contracts::api_for_request( $rv_req ) );
Settings::update( array( 'checkout_bot_check' => 1, 'checkout_min_fill_seconds' => 3 ) );
$rv_trap = FormGuard::check( array( 'form_guard' => array( 'hp' => 'bot', 'ms' => 9000 ) ) );
$rv_fast = FormGuard::check( array( 'form_guard' => array( 'hp' => '', 'ms' => 10 ) ) );
$rv_err_body = static fn( $e ) => array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'data' => $e->get_error_data() );
check( 'P6-1: form_guard refusals are 400 wpcap_form_guard / wpcap_form_too_fast in the public error shape; a request without form_guard passes', is_wp_error( $rv_trap ) && 'wpcap_form_guard' === $rv_trap->get_error_code() && 400 === $rv_trap->get_error_data()['status'] && is_wp_error( $rv_fast ) && 'wpcap_form_too_fast' === $rv_fast->get_error_code() && ! wpcap_contract_violations( $rv_public['endpoints']['error']['response'], $rv_err_body( $rv_trap ) ) && true === FormGuard::check( array() ) );
check( 'P6-1: CONTRACTS.md and SECURITY.md document public 1.3, link-request (limits, same answer) and the bot check scope', false === strpos( $rv_doc( 'CONTRACTS.md' ), '| `public` | 1.2 (1.1:' ) && false !== strpos( $rv_doc( 'CONTRACTS.md' ), '| `public` | 1.3 (' ) && false !== strpos( $rv_doc( 'SECURITY.md' ), '`POST /orders/link-request`' ) && false !== strpos( $rv_doc( 'SECURITY.md' ), 'Gleiche Antwort für jede Adresse' ) && false !== strpos( $rv_doc( 'SECURITY.md' ), 'wpcap_form_too_fast' ) && false !== strpos( $rv_doc( 'SECURITY.md' ), '**Geltungsbereich:**' ) );
check( 'P6-2: app contract, CONTRACTS.md row and fixture agree on 1.2; the fixture lists permissions.check_out', '1.2' === Contracts::version( 'app' ) && PassCaptain\Mobile\Api::VERSION === $rv_app['version'] && 'bool' === ( $rv_app['endpoints']['GET /app/me']['response']['?permissions']['check_out'] ?? '' ) && false !== strpos( $rv_doc( 'CONTRACTS.md' ), '| `app` | 1.2 (' ) );
check( 'P6-3: offline contract has its own minor (1.1) while the package format v stays 1', '1.1' === Contracts::version( 'offline' ) && 1 === Package::VERSION && 1 === Package::CONTRACT_MINOR && '1.1' === $rv_offline['version'] && false !== strpos( $rv_doc( 'CONTRACTS.md' ), '| `offline` | 1.1 (' ) );
$rv_payload = $rv_offline['endpoints']['package payload']['response'];
check( 'P6-3: the offline fixture lists the new package fields as optional', isset( $rv_payload['?permissions']['check_out'], $rv_payload['?checkout']['staff_area_ids'], $rv_payload['ticket_types'][0]['?own_policy'] ) && ! isset( $rv_payload['permissions'] ) );
$rv_ext = $rv_doc( 'EXTENSIONS.md' );
$rv_row = (string) ( preg_match( '/^\| 1\.4\.0 \| 1\.7\.0 \|.*$/m', $rv_ext, $rv_m ) ? $rv_m[0] : '' );
check( 'P6-4: the extension contract row 1.4.0 lists the new filters (settings schema, country presets, payment rules)', false !== strpos( $rv_row, 'wp_pass_captain_country_presets_v1' ) && false !== strpos( $rv_row, 'wp_pass_captain_payment_rules_v1' ) && false !== strpos( $rv_row, 'wp_pass_captain_settings_schema_v1' ) );
check( 'P6-4: HOOKS.md lists the template overrides order-link-request.php and order-link-invalid.php', false !== strpos( $rv_doc( 'HOOKS.md' ), '`order-link-request.php`' ) && false !== strpos( $rv_doc( 'HOOKS.md' ), '`order-link-invalid.php`' ) );

echo "Review P3: country presets with currency\n";
$rv_all = CountryPresets::all();
check( 'P3-5: DE/AT propose EUR, CH proposes CHF (EUR stays offered) and CHF 400 as invoice-address threshold', 'EUR' === $rv_all['DE']['values']['default_currency'] && 'EUR' === $rv_all['AT']['values']['currencies'] && 'CHF' === $rv_all['CH']['values']['default_currency'] && 'CHF,EUR' === $rv_all['CH']['values']['currencies'] && 40000 === $rv_all['CH']['values']['billing_required_threshold_minor'] );
Settings::update( array( 'seller_country' => 'DE', 'default_currency' => 'EUR', 'currencies' => 'EUR', 'billing_required_threshold_minor' => 25000 ) );
$rv_ch = array_column( CountryPresets::diff( 'CH' ), null, 'key' );
check( 'P3-5: the CH diff shows the currencies and the threshold flagged as legal', isset( $rv_ch['default_currency'], $rv_ch['currencies'] ) && 'CHF' === $rv_ch['default_currency']['new'] && true === ( $rv_ch['billing_required_threshold_minor']['legal'] ?? null ) && 40000 === $rv_ch['billing_required_threshold_minor']['new'] );
$rv_conflict = CountryPresets::apply( 'CH', array( 'default_currency' ) );
check( 'P3-5: a default currency outside the offered currencies is refused, nothing is applied', is_wp_error( $rv_conflict ) && 'wpcap_preset_currency' === $rv_conflict->get_error_code() && 'EUR' === Settings::get( 'default_currency' ) );
$rv_applied = CountryPresets::apply( 'CH', array( 'default_currency', 'currencies' ) );
check( 'P3-5: default currency and currencies applied together work (CHF default, EUR still offered)', is_array( $rv_applied ) && 'CHF' === Localization::default_currency() && in_array( 'EUR', Localization::currencies(), true ) );
check( 'P3-5: the threshold label also names CH', false !== strpos( (string) ( PassCaptain\SettingsSchema::get( 'billing_required_threshold_minor' )['label'] ?? '' ), 'CH: 400 CHF' ) );
Settings::update( array( 'default_currency' => 'EUR', 'currencies' => 'EUR' ) );
ob_start();
PassCaptain\Admin\SettingsPage::render( 'tools' );
ob_end_clean();
$_GET['view'] = 'preset';
$_GET['preset'] = 'ch';
ob_start();
SettingsTools::render();
$rv_html = (string) ob_get_clean();
unset( $_GET['view'], $_GET['preset'] );
$rv_box = static function ( $key ) use ( $rv_html ) { return preg_match( '/<input type="checkbox" id="wpcap-preset-' . preg_quote( $key, '/' ) . '"[^>]*>/', $rv_html, $m ) ? $m[0] : ''; };
check( 'P3-10: legal rows of the preset preview are not preselected, other rows are', '' !== $rv_box( 'billing_required_threshold_minor' ) && false === strpos( $rv_box( 'billing_required_threshold_minor' ), ' checked' ) && false === strpos( $rv_box( 'seller_country' ), ' checked' ) && false !== strpos( $rv_box( 'default_currency' ), ' checked' ) );
check( 'P3-9: the preset preview shows money values formatted, not in minor units', false !== strpos( $rv_html, esc_html( Money::format( 40000, 'EUR' ) ) ) );

echo "Review P3: payment rules\n";
Settings::update( array( 'payment_rules' => "manual | countries=DE,AT\nstripe | currency=eur,CHF" ) );
check( 'P3-6: a country rule does not match an order without billing country (fail closed); the event card (no country yet) still shows it', ! Rules::allows( 'manual', array( 'total_minor' => 100, 'country' => '' ) ) && Rules::allows( 'manual', array( 'total_minor' => 100, 'country' => 'at' ) ) && ! Rules::allows( 'manual', array( 'country' => 'CH' ) ) && Rules::allows( 'manual', array( 'event_ids' => array( 5 ) ) ) );
$rv_parsed = Rules::parse( "stripe | currency=eur,CHF | max=100" );
check( 'P3-7: currency condition is parsed (ISO codes, upper case) and invalid codes are refused', ! is_wp_error( $rv_parsed ) && array( 'EUR', 'CHF' ) === $rv_parsed['stripe'][0]['currencies'] && is_wp_error( Rules::parse( 'stripe | currency=EURO' ) ) && is_wp_error( Rules::parse( 'stripe | currency=E1R' ) ) );
check( 'P3-7: allows() checks the order currency; no currency in the context (event card) is not checked', Rules::allows( 'stripe', array( 'currency' => 'CHF', 'total_minor' => 1 ) ) && ! Rules::allows( 'stripe', array( 'currency' => 'USD', 'total_minor' => 1 ) ) && ! Rules::allows( 'stripe', array( 'currency' => '' ) ) && Rules::allows( 'stripe', array( 'event_ids' => array( 5 ) ) ) );
$rv_help = (string) ( PassCaptain\SettingsSchema::get( 'payment_rules' )['help'] ?? '' );
check( 'P3-6/7: help text and HOOKS.md explain the currency condition and the known billing country', false !== strpos( $rv_help, 'currency=' ) && false !== strpos( $rv_help, 'bekanntes Rechnungsland' ) && false !== strpos( $rv_doc( 'HOOKS.md' ), 'ohne Rechnungsland bietet diese Regel die Zahlungsart nicht an' ) );
Settings::update( array( 'payment_rules' => '' ) );

echo "Review P3: net price entry with tax profiles\n";
$wpdb->query( "DELETE FROM {$p}wpcap_tax_profiles WHERE country_code='LU'" );
$wpdb->insert( "{$p}wpcap_tax_profiles", array( 'country_code' => 'LU', 'tax_class' => 'standard', 'rate_basis_points' => 1700, 'version' => 'rv-lu', 'effective_from' => '2000-01-01', 'status' => 'active', 'label' => 'LU 17', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
Settings::update( array( 'price_entry' => 'net', 'seller_country' => 'LU' ) );
$rv_event = EventsPage::create_event( array( 'name' => 'RV net ' . uniqid(), 'timezone' => 'Europe/Berlin', 'ticket_name' => 'Standard', 'currency' => 'EUR', 'starts_at' => gmdate( 'Y-m-d\TH:i', time() + 30 * DAY_IN_SECONDS ), 'price' => '10,00', 'tax_rate' => '19', 'status' => 'draft' ) );
$rv_price = is_array( $rv_event ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT tt.price_minor FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", (int) $rv_event['event_id'] ) ) : 0;
check( 'P3-8: creating an event converts the net price with the tax profile rate (17 %), like saving a ticket type', 1170 === $rv_price );
$rv_plan = PassCaptain\Admin\Import::plan( "event,starts_at,ticket,price,tax_rate\nRV import " . uniqid() . ',' . gmdate( 'Y-m-d H:i', time() + 40 * DAY_IN_SECONDS ) . ",Standard,\"10,00\",19\n", 'Europe/Berlin' );
$rv_import = PassCaptain\Admin\Import::import( $rv_plan );
$rv_iprice = is_array( $rv_import ) && $rv_import['event_ids'] ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT tt.price_minor FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", (int) $rv_import['event_ids'][0] ) ) : 0;
check( 'P3-8: the CSV import converts net prices the same way', 1170 === $rv_iprice );
Settings::update( array( 'price_entry' => 'gross' ) );
$rv_plan2 = PassCaptain\Admin\Import::plan( "event,starts_at,ticket,price,tax_rate\nRV import gross " . uniqid() . ',' . gmdate( 'Y-m-d H:i', time() + 40 * DAY_IN_SECONDS ) . ",Standard,\"10,00\",19\n", 'Europe/Berlin' );
$rv_import2 = PassCaptain\Admin\Import::import( $rv_plan2 );
$rv_iprice2 = is_array( $rv_import2 ) && $rv_import2['event_ids'] ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT tt.price_minor FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d", (int) $rv_import2['event_ids'][0] ) ) : 0;
check( 'P3-8: with gross entry (default) the CSV price is stored unchanged', 1000 === $rv_iprice2 );
$wpdb->query( "DELETE FROM {$p}wpcap_tax_profiles WHERE country_code='LU'" );

echo "Review P3/P5: money and dates in settings tools, voucher reconciliation\n";
Settings::update( array( 'default_currency' => 'EUR', 'currencies' => 'EUR' ) );
check( 'P3-9: money settings are shown as amounts in the default currency (overview and history)', Money::format( 25000, 'EUR' ) === SettingsTools::value_text( 'billing_required_threshold_minor', 25000 ) && Money::format( 25000, 'EUR' ) === SettingsManager::display_value( 25000, 'billing_required_threshold_minor' ) && '25000' === SettingsManager::display_value( 25000 ) && '7' === SettingsManager::display_value( 7, 'retention_order_years' ) );
check( 'P5-13: history times use the site date/time format', wp_date( PassCaptain\Frontend\Format::datetime_format(), strtotime( '2026-01-02 03:04:05 UTC' ) ) === SettingsTools::history_time( '2026-01-02 03:04:05' ) && false === strpos( (string) file_get_contents( $rv_root . '/src/Admin/SettingsTools.php' ), "'d.m.Y H:i'" ) );
$rv_refunded = ReconciliationReport::provider_finding( array( 'status' => 'committed', 'amount_minor' => 1000, 'refunded_minor' => 500 ), array( 'status' => 'committed', 'refunded_minor' => 300 ), 'CHF' );
check( 'P3-9: refund difference is formatted in the order currency', 'provider_refunded' === ( $rv_refunded[0] ?? '' ) && false !== strpos( $rv_refunded[1], Money::format( 500, 'CHF' ) ) && false !== strpos( $rv_refunded[1], Money::format( 300, 'CHF' ) ) && false === strpos( $rv_refunded[1], 'kleinste Einheit' ) );
$rv_status = ReconciliationReport::provider_finding( array( 'status' => 'committed', 'amount_minor' => 1000 ), array( 'status' => 'released' ) );
check( 'P5-14: voucher states are shown with labels, not raw codes', 'provider_status' === ( $rv_status[0] ?? '' ) && false !== strpos( $rv_status[1], ReconciliationReport::voucher_status_label( 'committed' ) ) && false !== strpos( $rv_status[1], ReconciliationReport::voucher_status_label( 'released' ) ) && 'committed' !== ReconciliationReport::voucher_status_label( 'committed' ) );
$rv_local = ReconciliationReport::local_findings( (object) array( 'payment_status' => 'pending', 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'status' => 'committed', 'amount_minor' => 1000 ), array(), time() );
$rv_unpaid = array_column( $rv_local, 1, 0 )['committed_unpaid'] ?? '';
check( 'P5-14: the payment status in the finding is the order status label', false !== strpos( $rv_unpaid, PassCaptain\Admin\Forms::status_label( 'pending', 'order' ) ) && false === strpos( $rv_unpaid, 'Zahlungsstatus: pending' ) );

echo "Review P5: order link page, manual holds\n";
$rv_err_page = OrderLinkRequest::page( array( '_wpnonce' => 'n', 'wpcap_order_link_email' => 'not-an-address' ) );
$rv_ok_page = OrderLinkRequest::page( array( '_wpnonce' => 'n', 'wpcap_order_link_email' => 'rv-ok-' . uniqid() . '@example.com' ) );
check( 'P5-11: an input error is an alert linked to the e-mail field (aria-invalid, aria-describedby)', false !== strpos( $rv_err_page, 'role="alert"' ) && false !== strpos( $rv_err_page, 'aria-invalid="true" aria-describedby="' . OrderLinkRequest::NOTICE_ID . '"' ) && false !== strpos( $rv_err_page, 'id="' . OrderLinkRequest::NOTICE_ID . '"' ) );
check( 'P5-11: the neutral answer stays a status message without error marking', false !== strpos( $rv_ok_page, 'role="status"' ) && false === strpos( $rv_ok_page, 'aria-invalid' ) && false === strpos( $rv_ok_page, 'role="alert"' ) );
check( 'P5-11: the trap field label is translatable (no hard-coded "Website")', false === strpos( OrderLinkRequest::form_html(), '<label>Website' ) && false !== strpos( OrderLinkRequest::form_html(), 'for="wpcap-order-link-hp"' ) );
$rv_mh = (string) file_get_contents( $rv_root . '/src/Admin/ManualHoldsRelease.php' );
check( 'P5-12: manual hold release uses plural forms for tickets and orders', false !== strpos( $rv_mh, "_n( '%1\$s (%2\$d Ticket)', '%1\$s (%2\$d Tickets)'" ) && false !== strpos( $rv_mh, "_n( '%d offene Überweisungsbestellung', '%d offene Überweisungsbestellungen'" ) && false !== strpos( $rv_mh, "_n( '%d Ticket', '%d Tickets'" ) );

update_option( Settings::OPTION, $rv_saved );
Settings::flush();
