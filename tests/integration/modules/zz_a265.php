<?php
/**
 * A265: manual payment (bank transfer) is only offered with a bank account (IBAN + recipient) or a
 * payment instruction text; not enabled on new installs, old installs keep it (with a notice when
 * incomplete); the buyer never sees a transfer request without recipient and IBAN.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Installer;
use PassCaptain\Payments\BankTransfer;
use PassCaptain\Payments\ManualGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
echo "A265: manual payment only when configured\n";

$a265_saved = get_option( Settings::OPTION, array() );
$a265_request = static function ( array $body ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	return $r;
};
$a265_data = static fn( $res ) => $res instanceof WP_REST_Response ? $res->get_data() : $res;
$a265_orders = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
$a265_gateway = Registry::get( 'manual' );

// --- Defaults and label -------------------------------------------------------------------------
check( 'new installs start without an enabled payment method', array() === Settings::defaults()['enabled_providers'] );
check( 'online label is "Überweisung" (no "Zahlung vor Ort")', 'Überweisung' === $a265_gateway->title() );

// --- Availability -------------------------------------------------------------------------------
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'bank_iban' => '', 'bank_recipient' => '', 'bank_bic' => '', 'seller_name' => '', 'manual_instructions' => '', 'require_terms' => 0 ) );
check( 'without IBAN and instructions manual payment is not available', false === $a265_gateway->is_available( 'EUR' ) && ! isset( Registry::enabled( 'EUR' )['manual'] ) && ManualGateway::enabled_but_incomplete() );
Settings::update( array( 'bank_iban' => 'AT611904300234573201' ) );
check( 'IBAN without recipient (and no seller name) is not enough', false === $a265_gateway->is_available( 'EUR' ) && ! BankTransfer::has_account() );
Settings::update( array( 'seller_name' => 'Kulturverein' ) );
check( 'IBAN with the seller name as recipient makes it available', true === $a265_gateway->is_available( 'EUR' ) && BankTransfer::has_account() && isset( Registry::enabled( 'EUR' )['manual'] ) && ! ManualGateway::enabled_but_incomplete() );
Settings::update( array( 'bank_iban' => '', 'seller_name' => '', 'manual_instructions' => 'Bitte nach Rechnung zahlen.' ) );
check( 'a payment instruction text alone makes it available', true === $a265_gateway->is_available( 'EUR' ) && ! BankTransfer::has_account() );

// --- Checkout: incomplete → refused before an order exists ---------------------------------------
Settings::update( array( 'manual_instructions' => '' ) );
$a265_type = make_type( 5, 10, 2000 );
$GLOBALS['wpcap_test_transients'] = array();
$a265_before = $a265_orders();
$a265_err = Controller::checkout( $a265_request( array( 'email' => 'a265@example.com', 'items' => array( array( 'ticket_type_id' => $a265_type, 'quantity' => 2 ) ), 'provider' => 'manual', 'idempotency_key' => 'a265-off-' . uniqid() ) ) );
check( 'checkout with unconfigured manual payment is refused, no order and no hold', is_wp_error( $a265_err ) && 'wpcap_provider_unavailable' === $a265_err->get_error_code() && 'provider' === ( $a265_err->get_error_data()['field'] ?? '' ) && $a265_before === $a265_orders() && 5 === PassCaptain\Orders::availability( $a265_type ) );

// --- Instructions only: no transfer request -------------------------------------------------------
Settings::update( array( 'manual_instructions' => 'Bitte nach Rechnung zahlen.' ) );
$a265_mails = count( $GLOBALS['wpcap_test_mails'] );
$a265_res = $a265_data( Controller::checkout( $a265_request( array( 'email' => 'a265-instr@example.com', 'items' => array( array( 'ticket_type_id' => $a265_type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'a265-instr-' . uniqid() ) ) ) );
check( 'instructions only: checkout response has no bank_transfer block, only the instruction text', is_array( $a265_res ) && ! isset( $a265_res['payment']['bank_transfer'] ) && 'Bitte nach Rechnung zahlen.' === ( $a265_res['payment']['instructions'] ?? '' ) );
$a265_details = BankTransfer::details( PassCaptain\Orders::get( $a265_res['order_id'] ) );
check( 'instructions only: details carry no recipient, IBAN, BIC or remittance', false === $a265_details['transfer'] && '' === $a265_details['iban'] && '' === $a265_details['recipient'] && '' === $a265_details['remittance'] && array( 'Betrag', 'Zahlbar bis' ) === array_keys( BankTransfer::rows( $a265_details ) ) );
$a265_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $a265_mails ), static fn( $m ) => 'a265-instr@example.com' === $m['to'] ) )[0] ?? array( 'body' => '' );
check( 'instructions only: the order mail shows the instructions but no transfer data', false !== strpos( $a265_mail['body'], 'Bitte nach Rechnung zahlen.' ) && false === strpos( $a265_mail['body'], 'IBAN' ) && false === strpos( $a265_mail['body'], 'Verwendungszweck' ) );
PassCaptain\Orders::cancel_order( $a265_res['order_id'], 'test_cleanup' );

// --- Full account: transfer request with recipient and IBAN ---------------------------------------
Settings::update( array( 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Kulturverein', 'manual_instructions' => '' ) );
$a265_full = $a265_data( Controller::checkout( $a265_request( array( 'email' => 'a265-full@example.com', 'items' => array( array( 'ticket_type_id' => $a265_type, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'a265-full-' . uniqid() ) ) ) );
$a265_bank = $a265_full['payment']['bank_transfer'] ?? array();
check( 'with recipient and IBAN the checkout returns the transfer request', true === ( $a265_bank['transfer'] ?? null ) && 'Kulturverein' === ( $a265_bank['recipient'] ?? '' ) && 'AT61 1904 3002 3457 3201' === ( $a265_bank['iban'] ?? '' ) );
$a265_strip = static function ( $details ) { $details['iban'] = ''; return $details; };
add_filter( 'wpcap_bank_transfer_details', $a265_strip );
$a265_filtered = BankTransfer::details( PassCaptain\Orders::get( $a265_full['order_id'] ) );
remove_filter( 'wpcap_bank_transfer_details', $a265_strip );
check( 'a filter that removes the IBAN also removes the transfer request', false === $a265_filtered['transfer'] && '' === $a265_filtered['recipient'] && '' === $a265_filtered['remittance'] );
PassCaptain\Orders::cancel_order( $a265_full['order_id'], 'test_cleanup' );

// --- Admin: payments tab status and notice --------------------------------------------------------
Settings::update( array( 'bank_iban' => '', 'bank_recipient' => '', 'manual_instructions' => '' ) );
ob_start(); SettingsPage::render( 'payments', '' ); $a265_overview = ob_get_clean();
ob_start(); SettingsPage::render( 'payments', 'manual' ); $a265_section = ob_get_clean();
check( 'payments tab marks manual payment "(nicht vollständig konfiguriert)"', false !== strpos( $a265_overview, '(nicht vollständig konfiguriert)' ) && false !== strpos( $a265_section, '(nicht vollständig konfiguriert)' ) && false !== strpos( $a265_section, 'erst angeboten' ) );
$_GET['page'] = 'wpcap-settings';
ob_start(); Menu::manual_payment_notice(); $a265_notice = ob_get_clean();
$_GET['page'] = 'some-other-plugin';
ob_start(); Menu::manual_payment_notice(); $a265_elsewhere = ob_get_clean();
$_GET['page'] = 'wpcap-settings';
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
ob_start(); Menu::manual_payment_notice(); $a265_denied = ob_get_clean();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin notice on plugin pages when manual payment is enabled but incomplete (admins only)', false !== strpos( $a265_notice, 'nicht vollständig konfiguriert' ) && false !== strpos( $a265_notice, 'section=manual' ) && '' === $a265_elsewhere && '' === $a265_denied );
Settings::update( array( 'manual_instructions' => 'Hinweis' ) );
ob_start(); Menu::manual_payment_notice(); $a265_ok = ob_get_clean();
Settings::update( array( 'manual_instructions' => '', 'enabled_providers' => array() ) );
ob_start(); Menu::manual_payment_notice(); $a265_off = ob_get_clean();
unset( $_GET['page'] );
check( 'no notice when configured or switched off', '' === $a265_ok && '' === $a265_off );

// --- Upgrade: existing installs keep manual payment -----------------------------------------------
update_option( Settings::OPTION, array( 'seller_name' => 'Alt' ) );
Settings::flush();
check( 'fresh install (no previous schema): nothing pinned', false === Installer::pin_legacy_manual_provider( '' ) && array() === Settings::get( 'enabled_providers' ) );
check( 'upgrade from 3.10.7 without stored providers keeps "manual" enabled', true === Installer::pin_legacy_manual_provider( '3.10.7+1.0' ) && array( 'manual' ) === get_option( Settings::OPTION )['enabled_providers'] && 'Alt' === Settings::get( 'seller_name' ) );
update_option( Settings::OPTION, array( 'enabled_providers' => array( 'stripe' ) ) );
Settings::flush();
check( 'a stored provider list is never changed', false === Installer::pin_legacy_manual_provider( '3.9.0' ) && array( 'stripe' ) === Settings::get( 'enabled_providers' ) );
update_option( Settings::OPTION, array() );
Settings::flush();
check( 'from 3.10.9 on nothing is pinned', false === Installer::pin_legacy_manual_provider( '3.10.9' ) && array() === Settings::get( 'enabled_providers' ) );

update_option( Settings::OPTION, $a265_saved );
Settings::flush();
$GLOBALS['wpcap_test_transients'] = array();
