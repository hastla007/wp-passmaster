<?php
/**
 * A4: extension points for the add-on – settings sections/defaults, admin menu, report data/metrics/
 * tabs, CSV export rows and extra export types, scanner adapter registry.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Menu;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Checkin;
use PassCaptain\Invoices;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Exports;
use PassCaptain\Reports\Page;
use PassCaptain\ScannerAdapters;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A4: settings defaults and sections for add-ons\n";
$x_defaults = static function ( $d ) { return $d + array( 'w7x_mode' => 'basic', 'w7x_limit' => 10, 'hold_minutes' => 999 ); };
$x_sections = static function ( $sections ) {
	$sections['w7x'] = array( 'title' => 'W7 Add-on', 'fields' => array(
		'w7x_mode'  => array( 'type' => 'choice', 'label' => 'Modus', 'options' => array( 'basic' => 'Basic', 'pro' => 'Pro' ) ),
		'w7x_limit' => array( 'type' => 'int', 'label' => 'Limit', 'min' => 1, 'max' => 100, 'sanitize' => static fn( $v ) => $v > 50 ? new WP_Error( 'w7x', 'Höchstens 50.' ) : $v ),
		'w7x_bogus' => array( 'type' => 'unknown', 'label' => 'x' ),
	) );
	$sections['general']['fields']['hold_minutes'] = array( 'type' => 'text', 'label' => 'hijack' ); // Core field: must stay int.
	return $sections;
};
add_filter( 'wp_pass_captain_settings_defaults_v1', $x_defaults );
add_filter( 'wp_pass_captain_settings_sections_v1', $x_sections );
Settings::flush();
$defs = SettingsPage::field_definitions();
check( 'add-on defaults are merged, core defaults cannot be replaced', 'basic' === Settings::defaults()['w7x_mode'] && 999 !== Settings::defaults()['hold_minutes'] );
check( 'add-on section appears with its valid fields only', isset( $defs['w7x'] ) && array( 'w7x_mode', 'w7x_limit' ) === array_keys( $defs['w7x']['fields'] ) );
check( 'core fields cannot be retyped by the filter', 'int' === $defs['general']['fields']['hold_minutes']['type'] );
$saved = SettingsPage::save( array( 'w7x_mode' => 'pro', 'w7x_limit' => '20' ) );
check( 'add-on fields are saved with the core form', ! is_wp_error( $saved ) && 'pro' === Settings::get( 'w7x_mode' ) && 20 === (int) Settings::get( 'w7x_limit' ) );
$saved = SettingsPage::save( array( 'w7x_mode' => 'pro', 'w7x_limit' => '80' ) );
check( 'sanitize callback can reject a value (error shown, old value kept)', in_array( 'Höchstens 50.', $saved['errors'], true ) && 20 === (int) Settings::get( 'w7x_limit' ) );
remove_filter( 'wp_pass_captain_settings_defaults_v1', $x_defaults );
remove_filter( 'wp_pass_captain_settings_sections_v1', $x_sections );
Settings::flush();

echo "A4: admin menu entries outside modules\n";
$x_menu = static function ( $pages ) {
	$pages[] = array( 'w7x-dashboard', 'W7', 'manage_options', '__return_true_w7' );
	$pages[] = array( 'w7x-valid', 'W7 valid', 'manage_options', 'strlen' );
	$pages[] = array( 'wpcap-settings', 'Hijack', 'manage_options', 'strlen' ); // Core slug.
	$pages[] = array( 'broken' );
	return $pages;
};
add_filter( 'wp_pass_captain_admin_menu_v1', $x_menu );
$extra = Menu::extra_pages();
remove_filter( 'wp_pass_captain_admin_menu_v1', $x_menu );
check( 'only valid, non-core add-on pages are added', array( 'w7x-valid' ) === array_column( $extra, 0 ) );

echo "A4: report data, metrics, tabs and exports\n";
$today = gmdate( 'Y-m-d' );
$x_data = static function ( $report, $filters, $scope ) { $report['extra']['w7x'] = 42; return $report; };
add_filter( 'wp_pass_captain_report_data_v1', $x_data, 10, 3 );
$report = Data::build( array( 'from' => $today, 'to' => $today ), null );
remove_filter( 'wp_pass_captain_report_data_v1', $x_data, 10 );
check( 'report data filter can add figures under extra', 42 === $report['extra']['w7x'] );
$report = Data::build( array( 'from' => $today, 'to' => $today ), null );
$x_metrics = static function ( $rows, $report ) { $rows['W7 Kennzahl'] = 7; $rows['Array'] = array( 1 ); return $rows; };
add_filter( 'wp_pass_captain_report_metrics_v1', $x_metrics, 10, 2 );
$rows = Page::metrics( array( 'Verkaufte Tickets' => '3' ), $report, array() );
remove_filter( 'wp_pass_captain_report_metrics_v1', $x_metrics, 10 );
check( 'report metrics filter adds rows (scalars only)', array( 'Verkaufte Tickets' => '3', 'W7 Kennzahl' => '7' ) === $rows );
$x_tabs = static function ( $tabs ) {
	$tabs['w7x'] = array( 'label' => 'W7', 'callback' => static function () { echo 'w7-tab'; } );
	$tabs['secret'] = array( 'label' => 'Secret', 'callback' => 'strlen', 'capability' => 'w7x_secret_cap' );
	$tabs['overview'] = array( 'label' => 'x', 'callback' => 'strlen' );
	return $tabs;
};
add_filter( 'wp_pass_captain_report_tabs_v1', $x_tabs );
$GLOBALS['wpcap_test_denied_caps'] = array( 'w7x_secret_cap' );
$tabs = Page::tabs();
$GLOBALS['wpcap_test_denied_caps'] = array();
remove_filter( 'wp_pass_captain_report_tabs_v1', $x_tabs );
check( 'report tabs: valid tabs only, capability respected, overview reserved', array( 'w7x' ) === array_keys( $tabs ) );

$x_rows = static function ( $rows, $type, $context ) {
	foreach ( $rows as $i => $row ) { $rows[ $i ][] = 0 === $i ? $type . '-col' : '=SUM(A1)'; }
	return $rows;
};
add_filter( 'wp_pass_captain_export_rows_v1', $x_rows, 10, 3 );
$csv_rows = Data::csv_rows( $report );
$acc_rows = Invoices::export_rows( $today, $today );
check( 'export rows filter adds a column to the report CSV', 'report-col' === end( $csv_rows[0] ) );
check( 'export rows filter adds a column to the accounting export', 'accounting-col' === end( $acc_rows[0] ) );
$csv = PassCaptain\Reports\Scope::csv( $csv_rows );
check( 'added cells pass the CSV formula guard', false !== strpos( $csv, "'=SUM(A1)" ) );
$x_types = static function ( $types ) {
	$types['w7x_list'] = array( 'label' => 'W7 Liste', 'rows' => static fn( $filters, $scope ) => array( array( 'Spalte' ), array( '=1+1' ), array( 'b' ) ), 'filename' => 'w7-liste' );
	$types['w7x_denied'] = array( 'label' => 'Denied', 'rows' => static fn() => array(), 'capability' => 'w7x_secret_cap' );
	$types['accounting'] = array( 'label' => 'Hijack', 'rows' => static fn() => array() );
	return $types;
};
add_filter( 'wp_pass_captain_export_types_v1', $x_types );
$GLOBALS['wpcap_test_denied_caps'] = array( 'w7x_secret_cap' );
$types = Exports::types();
$built = Exports::build( 'w7x_list', array( 'from' => $today, 'to' => $today, 'event_id' => 0, 'occurrence_id' => 0 ) );
$denied = Exports::build( 'w7x_denied', array( 'from' => $today, 'to' => $today, 'event_id' => 0, 'occurrence_id' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
remove_filter( 'wp_pass_captain_export_types_v1', $x_types );
check( 'extra export types: allowed ones listed, core ids reserved', array( 'w7x_list' ) === array_keys( $types ) );
check( 'extra export builds a CSV (rows filter applied, formulas guarded)', is_array( $built ) && 'w7-liste-' . $today . '-' . $today . '.csv' === $built['filename'] && false !== strpos( $built['csv'], 'w7x_list-col' ) && false !== strpos( $built['csv'], "'=1+1" ) );
check( 'extra export without the capability is refused', is_wp_error( $denied ) && 'wpcap_export_unknown' === $denied->get_error_code() );
remove_filter( 'wp_pass_captain_export_rows_v1', $x_rows, 10 );

echo "A4: scanner adapter registry\n";
$x_calls = array();
$x_adapters = static function ( $adapters ) use ( &$x_calls ) {
	$adapters['w7x_cards'] = array(
		'label'      => 'W7 Karten',
		'recognizes' => static fn( $code ) => str_starts_with( (string) $code, 'W7-' ) || str_starts_with( (string) $code, 'ALL' ),
		'check_in'   => static function ( $code, $context ) use ( &$x_calls ) {
			$x_calls[] = $code;
			if ( 'W7-THROW' === $code ) { throw new RuntimeException( 'boom' ); }
			if ( 'W7-DENY' === $code ) { return new WP_Error( 'w7x_denied', 'Karte gesperrt', array( 'result' => 'blocked' ) ); }
			return array( 'status' => 'checked_in', 'label' => 'Karte ' . $code, 'device' => (int) ( $context['device_id'] ?? 0 ) );
		},
		'preview'    => static fn( $code ) => array( 'status' => 'valid', 'label' => 'Karte ' . $code ),
	);
	$adapters['w7x_broken'] = array( 'label' => 'broken', 'recognizes' => 'strlen' ); // No check_in: ignored.
	return $adapters;
};
add_filter( 'wp_pass_captain_scanner_adapters_v1', $x_adapters );
check( 'registry lists valid adapters only', array( 'w7x_cards' ) === array_keys( ScannerAdapters::all() ) );
$res = Checkin::perform( 'W7-1001', array( 'device_id' => 5 ) );
check( 'adapter checks in its own codes through Checkin::perform()', is_array( $res ) && 'checked_in' === $res['status'] && 'w7x_cards' === $res['adapter'] && 5 === $res['device'] );
$prev = Checkin::preview( 'W7-1001' );
check( 'adapter preview through Checkin::preview()', is_array( $prev ) && 'valid' === $prev['status'] && 'w7x_cards' === $prev['adapter'] );
$deny = Checkin::perform( 'W7-DENY' );
check( 'adapter WP_Error is passed on with the adapter id', is_wp_error( $deny ) && 'w7x_denied' === $deny->get_error_code() && 'w7x_cards' === $deny->get_error_data()['adapter'] );
$throw = Checkin::perform( 'W7-THROW' );
check( 'adapter exception becomes a retryable error', is_wp_error( $throw ) && 'wpcap_scanner_adapter_failed' === $throw->get_error_code() );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
$x_calls = array();
$forbidden = Checkin::perform( 'W7-1002' );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'adapter capability is checked before the callback', is_wp_error( $forbidden ) && 403 === $forbidden->get_error_data()['status'] && array() === $x_calls );
// Core tickets always win, even if an adapter "recognizes" them.
$x_type = make_type( 10, 10, 0 );
$GLOBALS['wpcap_test_transients'] = array();
$x_order = PassCaptain\Orders::create( array( 'email' => 'w7x-scan@example.com', 'items' => array( array( 'ticket_type_id' => $x_type, 'quantity' => 1 ) ), 'idempotency_key' => 'w7x-scan-' . uniqid() ) );
$x_token = bin2hex( random_bytes( 32 ) );
$x_ticket = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $x_order['order_id'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET token_hash=%s WHERE ticket_id=%d", hash( 'sha256', $x_token ), $x_ticket ) );
$x_calls = array();
$core = Checkin::perform( 'ALL' . $x_token, array( 'idempotency_key' => 'w7x-core-' . uniqid() ) );
check( 'core ticket codes are never handed to an adapter', is_array( $core ) && empty( $core['adapter'] ) && array() === $x_calls && 'checked_in' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$x_ticket" ) );
remove_filter( 'wp_pass_captain_scanner_adapters_v1', $x_adapters );
$none = Checkin::perform( 'W7-1003' );
check( 'without adapters unknown codes stay unknown', is_wp_error( $none ) && 'wpcap_ticket_not_found' === $none->get_error_code() );
