<?php
// phpcs:ignoreFile
/**
 * A663: public, versioned add-on API – wpcap_loaded (once, with the API version), contract "addon",
 * own settings tabs (wpcap_settings_tabs), own admin pages (wpcap_admin_pages), report data and
 * sections (wpcap_report_data, wpcap_reports_render) and further export formats
 * (wpcap_export_formats). Core tabs, pages and report keys cannot be taken over.
 */

use PassCaptain\Admin\Menu;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Contracts;
use PassCaptain\Invoices;
use PassCaptain\PublicApi;
use PassCaptain\Reports\Page as ReportsPage;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }

$a663_hooks_before = $GLOBALS['wpcap_test_hooks'];

// Version and bootstrap action.
check( 'A663: the add-on API has a contract version (addon) of major 1', PublicApi::VERSION === Contracts::version( 'addon' ) && 1 === Contracts::major( PublicApi::VERSION ) );
check( 'A663: PublicApi::compatible accepts every 1.x and refuses other majors', PublicApi::compatible( '1.0' ) && PublicApi::compatible( '1.9.3' ) && ! PublicApi::compatible( '2.0' ) && ! PublicApi::compatible( 'x' ) );
$a663_loaded = array();
add_action( 'wpcap_loaded', static function ( $version ) use ( &$a663_loaded ) { $a663_loaded[] = $version; } );
$a663_actions = $GLOBALS['wpcap_test_actions'];
$GLOBALS['wpcap_test_actions'] = array_values( array_diff( (array) $GLOBALS['wpcap_test_actions'], array( 'wpcap_loaded' ) ) );
PublicApi::loaded();
PublicApi::loaded();
check( 'A663: wpcap_loaded fires once per request with the API version', array( PublicApi::VERSION ) === $a663_loaded );
$GLOBALS['wpcap_test_actions'] = $a663_actions;
check( 'A663: Plugin::run() ends with wpcap_loaded (after every core registration)', (bool) preg_match( '/Modules::register_all\(\);.*PublicApi::loaded\(\);\s*\}/s', (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ) ) );
foreach ( PublicApi::hooks() as $a663_hook => $a663_def ) {
	if ( ! in_array( $a663_def[0], array( 'action', 'filter' ), true ) || ! preg_match( '/^\d+\.\d+$/', $a663_def[1] ) ) { check( "A663: registry entry $a663_hook is well-formed", false ); }
}

// Settings tabs.
add_filter( 'wpcap_settings_tabs', static function ( $tabs ) {
	$tabs['pro'] = array( 'title' => 'Pro', 'render' => static function ( $tab ) { echo '<p id="pro-tab">Pro-Einstellungen ' . esc_html( $tab ) . '</p>'; } );
	$tabs['general'] = array( 'title' => 'Übernahme', 'render' => static function () { echo 'HIJACK'; } );
	$tabs['broken'] = array( 'title' => 'Kaputt' );
	return $tabs;
} );
$a663_tabs = SettingsPage::tabs();
check( 'A663: an add-on tab is added after the core tabs; core tabs and invalid entries cannot be replaced', 'Pro' === ( $a663_tabs['pro'] ?? '' ) && 'Allgemein' === $a663_tabs['general'] && ! isset( $a663_tabs['broken'] ) && 'pro' === array_key_last( $a663_tabs ) );
ob_start();
SettingsPage::render( 'pro' );
$a663_html = (string) ob_get_clean();
check( 'A663: the add-on tab renders its own content without the core settings form', false !== strpos( $a663_html, 'id="pro-tab">Pro-Einstellungen pro' ) && false === strpos( $a663_html, 'wpcap_save_settings' ) && false !== strpos( $a663_html, 'nav-tab-active' ) );
ob_start();
SettingsPage::render( 'general' );
$a663_general = (string) ob_get_clean();
check( 'A663: the core tab still renders the core form', false === strpos( $a663_general, 'HIJACK' ) && false !== strpos( $a663_general, 'wpcap_tab' ) );
check( 'A663: saving the add-on tab through the core form touches no core setting', array() === SettingsPage::scoped_sections( 'pro' ) );
add_filter( 'wpcap_settings_tabs', static function ( $tabs ) { $tabs['pro2'] = array( 'title' => 'Pro 2', 'capability' => 'wpcap_view_reports', 'render' => static function () { echo 'PRO2'; } ); return $tabs; }, 20 );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_view_reports' );
ob_start();
SettingsPage::render( 'pro2' );
$a663_denied = (string) ob_get_clean();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A663: an add-on tab with its own capability is not rendered without it', false === strpos( $a663_denied, 'PRO2' ) );

// Admin pages.
add_filter( 'wpcap_admin_pages', static function ( $pages ) {
	$pages[] = array( 'slug' => 'wpcap-pro-insights', 'title' => 'Insights', 'capability' => 'wpcap_view_reports', 'callback' => '__return_true', 'area' => 'finance' );
	$pages[] = array( 'slug' => 'wpcap-settings', 'title' => 'Fake', 'capability' => 'read', 'callback' => '__return_true' );
	$pages[] = array( 'slug' => 'wpcap-pro-noarea', 'title' => 'Ohne Bereich', 'capability' => 'read', 'callback' => '__return_true', 'area' => 'nope' );
	$pages[] = array( 'slug' => 'wpcap-pro-nocb', 'title' => 'Ohne Callback', 'capability' => 'read' );
	return $pages;
} );
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
$a663_entries = array();
foreach ( Menu::entries() as $a663_e ) { $a663_entries[ $a663_e[0] ][] = $a663_e; }
check( 'A663: an add-on page joins the menu in its area', isset( $a663_entries['wpcap-pro-insights'] ) && 'finance' === $a663_entries['wpcap-pro-insights'][0][5] && 'wpcap_view_reports' === $a663_entries['wpcap-pro-insights'][0][3] );
check( 'A663: core slugs cannot be taken over, unknown areas fall back to settings, entries without callback are ignored', 1 === count( $a663_entries['wpcap-settings'] ) && 'settings' === $a663_entries['wpcap-pro-noarea'][0][5] && ! isset( $a663_entries['wpcap-pro-nocb'] ) );

// Report data (additive) and export formats.
$a663_report = array( 'from' => '2026-11-01', 'to' => '2026-11-30', 'total' => array( 'gross' => 100 ), 'currency' => 'EUR' );
add_filter( 'wpcap_report_data', static function ( $r, $filters ) { $r['pro_forecast'] = 42; $r['total'] = 'kaputt'; return $r; }, 10, 2 );
$a663_ext = ReportsPage::extended( $a663_report, array( 'event_id' => 0 ), true );
check( 'A663: wpcap_report_data adds figures but cannot change core keys', 42 === $a663_ext['pro_forecast'] && array( 'gross' => 100 ) === $a663_ext['total'] );
add_filter( 'wpcap_export_formats', static function ( $formats ) {
	$formats['lexware'] = array( 'label' => 'Lexware', 'rows' => static function ( $from, $to ) { return array( array( 'Datum', 'Text', 'Betrag' ), array( $from, '=HYPERLINK("x")', '-12.50' ), 'junk', array( $to, array( 'nested' ), null ) ); } );
	$formats['datev'] = array( 'label' => 'Fake', 'rows' => static fn() => array() );
	$formats['nofn'] = array( 'label' => 'Ohne' );
	return $formats;
} );
$a663_formats = Invoices::export_formats();
check( 'A663: wpcap_export_formats registers a format; csv/datev are reserved, entries without rows callback ignored', array( 'lexware' ) === array_keys( $a663_formats ) && 'Lexware' === $a663_formats['lexware']['label'] );
$a663_rows = Invoices::extension_rows( $a663_formats['lexware'], '2026-11-01', '2026-11-30' );
check( 'A663: export rows get the date range, only scalar cells, formulas neutralised, numbers kept', 3 === count( $a663_rows ) && array( '2026-11-01', "'=HYPERLINK(\"x\")", '-12.50' ) === $a663_rows[1] && array( '2026-11-30', '', '' ) === $a663_rows[2] );

$GLOBALS['wpcap_test_hooks'] = $a663_hooks_before;
