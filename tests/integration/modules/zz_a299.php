<?php
/**
 * A299: modules can be switched off on the settings screen (setting disabled_modules).
 */

use PassCaptain\Admin\SettingsPage;
use PassCaptain\Modules;
use PassCaptain\Settings;

echo "A299: modules switchable in the UI\n";
$a299_before = Settings::get( 'disabled_modules' );
check( 'A299: all modules are on by default', array() === Settings::defaults()['disabled_modules'] && Modules::enabled( 'PassCaptain\\Modules\\Sms' ) );
check( 'A299: the general tab offers the switch', isset( SettingsPage::scoped_sections( 'general' )['features']['fields']['disabled_modules'] ) );
$a299_saved = SettingsPage::save( array( 'wpcap_tab' => 'general', 'hold_minutes' => (string) Settings::get( 'hold_minutes' ), 'disabled_modules' => array( 'Sms', 'NoSuchModule' ) ) );
check( 'A299: saving keeps only known modules', ! is_wp_error( $a299_saved ) && array( 'Sms' ) === Settings::get( 'disabled_modules' ) );
check( 'A299: a disabled module reports enabled() = false, others stay on', ! Modules::enabled( 'PassCaptain\\Modules\\Sms' ) && Modules::enabled( 'PassCaptain\\Modules\\Wallet' ) );
ob_start(); SettingsPage::render( 'general' ); $a299_html = (string) ob_get_clean();
check( 'A299: the checkbox renders checked', (bool) preg_match( '/name="disabled_modules\[\]" value="Sms" checked/', $a299_html ) );
Settings::update( array( 'disabled_modules' => (array) $a299_before ) );
check( 'A299: switching it on again restores the module', Modules::enabled( 'PassCaptain\\Modules\\Sms' ) );
