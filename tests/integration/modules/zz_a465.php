<?php
/**
 * A465: the default entry window (2 h before the start until the end, since after release 1.4.0)
 * must not silently start rejecting scans on existing installs after an update. Installs from
 * schema 3.5.0 and older that never saved the scanner settings keep "no window" (stored explicitly)
 * and administrators get a notice; fresh installs and stored choices are unchanged.
 */

use PassCaptain\Admission\Engine;
use PassCaptain\Installer;
use PassCaptain\Settings;

echo "A465: default entry window is pinned off for existing installs\n";

$a465_orig = get_option( Settings::OPTION, array() );
$a465_notice = get_option( Installer::ENTRY_WINDOW_NOTICE );
$a465_without = static function () use ( $a465_orig ) {
	$s = is_array( $a465_orig ) ? $a465_orig : array();
	unset( $s['default_entry_window'] );
	update_option( Settings::OPTION, $s );
	delete_option( Installer::ENTRY_WINDOW_NOTICE );
	Settings::flush();
};

$a465_without();
check( 'new default: entry window on', 1 === (int) Settings::get( 'default_entry_window' ) );
check( 'upgrade from 1.4.0 (schema 3.5.0): window pinned off and notice flagged', true === Installer::pin_legacy_entry_window( '3.5.0+abc' ) && 0 === (int) Settings::get( 'default_entry_window' ) && array_key_exists( 'default_entry_window', get_option( Settings::OPTION ) ) && 1 === (int) get_option( Installer::ENTRY_WINDOW_NOTICE ) );
// Scan an hour before the 2 h window of a date that starts in three hours: allowed as before the update.
$a465_occ = array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * HOUR_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'doors_open_at' => null );
$a465_method = new ReflectionMethod( Engine::class, 'default_window_block' );
check( 'pinned install: a scan outside the new default window is still admitted', '' === $a465_method->invoke( null, $a465_occ, time() ) );
check( 'a second upgrade run does not write again', false === Installer::pin_legacy_entry_window( '3.5.0+abc' ) );

$a465_without();
check( 'fresh install: nothing pinned', false === Installer::pin_legacy_entry_window( '' ) && 1 === (int) Settings::get( 'default_entry_window' ) && false === get_option( Installer::ENTRY_WINDOW_NOTICE ) );
check( 'fresh install: the default window applies', 'outside_entry_window' === $a465_method->invoke( null, $a465_occ, time() ) );
check( 'install that already had the window (schema 3.6.0+): nothing pinned', false === Installer::pin_legacy_entry_window( '3.6.0+x' ) && false === Installer::pin_legacy_entry_window( '3.10.14+x' ) );
check( 'rollback to 1.4.0 and forward on a new install: baseline is the highest schema, nothing pinned', false === Installer::pin_legacy_entry_window( Installer::migration_baseline( '3.5.0+rb', '3.10.14' ) ) );
$a465_s = get_option( Settings::OPTION );
$a465_s['default_entry_window'] = 1;
update_option( Settings::OPTION, $a465_s );
Settings::flush();
check( 'a stored choice is never changed', false === Installer::pin_legacy_entry_window( '3.5.0+abc' ) && 1 === (int) Settings::get( 'default_entry_window' ) );
check( 'install() runs the pinning step', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Installer.php' ), 'self::pin_legacy_entry_window( $previous_version );' ) );

// Admin notice on plugin pages until dismissed or switched on.
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce">'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
$a465_without();
Installer::pin_legacy_entry_window( '3.5.0+abc' );
$a465_get = $_GET;
$_GET['page'] = 'wpcap-settings';
ob_start();
PassCaptain\Admin\Menu::entry_window_notice();
$a465_html = ob_get_clean();
check( 'notice explains the pinned window and links to the settings', false !== strpos( $a465_html, 'Standard-Einlassfenster' ) && false !== strpos( $a465_html, 'wie bisher' ) && false !== strpos( $a465_html, PassCaptain\Admin\Menu::ENTRY_WINDOW_DISMISS ) );
$a465_s = get_option( Settings::OPTION );
$a465_s['default_entry_window'] = 1;
update_option( Settings::OPTION, $a465_s );
Settings::flush();
ob_start();
PassCaptain\Admin\Menu::entry_window_notice();
check( 'switching the window on ends the notice', '' === ob_get_clean() && false === get_option( Installer::ENTRY_WINDOW_NOTICE ) );
check( 'dismiss handler is registered', isset( PassCaptain\Admin\Menu::handlers()[ PassCaptain\Admin\Menu::ENTRY_WINDOW_DISMISS ] ) );
$_GET = $a465_get;

update_option( Settings::OPTION, $a465_orig );
if ( false === $a465_notice ) { delete_option( Installer::ENTRY_WINDOW_NOTICE ); } else { update_option( Installer::ENTRY_WINDOW_NOTICE, $a465_notice ); }
Settings::flush();
