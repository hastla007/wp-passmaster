<?php
/**
 * A378: the "Allgemein" settings tab is relieved — proxy/security/support/uninstall move to
 * "Erweitert", data protection gets its own tab, vouchers only show with a voucher provider.
 */

use PassCaptain\Admin\SettingsPage;
use PassCaptain\Settings;

echo "A378: settings tabs relieved\n";
$a378_tabs = SettingsPage::tabs();
check( 'A378: privacy and advanced are core tabs', 'Datenschutz' === ( $a378_tabs['privacy'] ?? '' ) && 'Erweitert' === ( $a378_tabs['advanced'] ?? '' ) );
$a378_ids = static fn( $tab ) => array_keys( SettingsPage::scoped_sections( $tab ) );
check( 'A378: general no longer holds proxy, privacy, security, support, uninstall', ! array_intersect( $a378_ids( 'general' ), array( 'network', 'privacy', 'security', 'support', 'uninstall' ) ) && in_array( 'general', $a378_ids( 'general' ), true ) && in_array( 'mail', $a378_ids( 'general' ), true ) );
check( 'A378: advanced holds proxy, security, support, uninstall', ! array_diff( array( 'network', 'security', 'support', 'uninstall' ), $a378_ids( 'advanced' ) ) );
check( 'A378: privacy tab holds the retention periods', array( 'privacy' ) === $a378_ids( 'privacy' ) );

$a378_before = Settings::get( 'voucher_default_purpose' );
Settings::update( array( 'voucher_default_purpose' => 'multi' ) );
$a378_provider = PassCaptain\Integrations\Vouchers\Bridge::available();
check( 'A378: voucher section follows the voucher provider (default value)', $a378_provider === in_array( 'vouchers', $a378_ids( 'general' ), true ) );
if ( ! $a378_provider ) {
	$a378_saved = SettingsPage::save( array( 'wpcap_tab' => 'general', 'hold_minutes' => (string) Settings::get( 'hold_minutes' ) ) );
	check( 'A378: hidden voucher setting is not written by a general save', ! is_wp_error( $a378_saved ) && ! array_key_exists( 'voucher_default_purpose', (array) $a378_saved['saved'] ) );
	Settings::update( array( 'voucher_default_purpose' => 'single' ) );
	check( 'A378: a stored non-default voucher purpose keeps the section visible', in_array( 'vouchers', $a378_ids( 'general' ), true ) );
}
Settings::update( array( 'voucher_default_purpose' => $a378_before ) );

ob_start(); SettingsPage::render( 'advanced' ); $a378_html = (string) ob_get_clean();
check( 'A378: advanced tab renders its sections and the tab marker', false !== strpos( $a378_html, 'id="wpcap-settings-network"' ) && false !== strpos( $a378_html, 'name="delete_data_on_uninstall"' ) && false !== strpos( $a378_html, 'value="advanced"' ) );
