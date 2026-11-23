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

// A299 (Z3): duplicate ways – Mollie is set up under Einstellungen → Zahlungsarten only.
echo "A299: no second menu entry for a page reached from another screen\n";
require_once dirname( __DIR__ ) . '/lib/menu_stubs.php';
use PassCaptain\Admin\Menu;
$a299_mollie = PassCaptain\Modules\Mollie::PAGE;
$a299_mollie_on = Modules::enabled( 'PassCaptain\\Modules\\Mollie' );
check( 'A299: Mollie is a linked page of the settings entry', SettingsPage::SLUG === ( Menu::linked_pages()[ $a299_mollie ] ?? '' ) );
check( 'A299: Mollie has no own menu entry', ! in_array( $a299_mollie, array_column( Menu::entries(), 0 ), true ) );
$GLOBALS['submenu'] = array();
Menu::add_menus();
$a299_hidden = array_column( (array) ( $GLOBALS['submenu'][''] ?? array() ), 2 );
check( 'A299: the Mollie page stays registered without menu entry (links and bookmarks keep working)', ! $a299_mollie_on || in_array( $a299_mollie, $a299_hidden, true ) );
check( 'A299: the Mollie page is not in the visible submenu', ! in_array( $a299_mollie, array_column( (array) ( $GLOBALS['submenu'][ Menu::SLUG ] ?? array() ), 2 ), true ) );
$a299_get = $_GET;
$_GET = array( 'page' => $a299_mollie );
check( 'A299: on the Mollie page the menu highlights Pass Captain → Einstellungen', Menu::SLUG === Menu::linked_parent_file( 'x' ) && SettingsPage::SLUG === Menu::linked_submenu_file( 'y', Menu::SLUG ) );
$_GET = array( 'page' => 'wpcap-orders' );
check( 'A299: other pages keep their highlighting', 'x' === Menu::linked_parent_file( 'x' ) && 'y' === Menu::linked_submenu_file( 'y', Menu::SLUG ) );
$_GET = $a299_get;
check( 'A299: the payments settings link to the Mollie page (one way in)', false !== strpos( PassCaptain\Modules\Mollie::settings_url( '', 'mollie' ), 'page=' . $a299_mollie ) );
