<?php
/**
 * Worker for tests/integration/modules/zz_z1_network.php, in its own process: multisite functions and
 * per-site options are stubbed here. Prints one JSON object.
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';

use PassCaptain\NetworkInstall;
use PassCaptain\Settings;

$GLOBALS['z1n_sites'] = range( 1, 30 );
$GLOBALS['z1n_blog'] = 1;
$GLOBALS['z1n_site_options'] = array( 'active_sitewide_plugins' => array( 'pass-captain/pass-captain.php' => time() ) );
$GLOBALS['z1n_options'] = array();
foreach ( $GLOBALS['z1n_sites'] as $z1n_id ) {
	// Sites 2..30 carry data of the old copy; the even ones opted in to deleting it (old key).
	$GLOBALS['z1n_options'][ $z1n_id ] = 1 === $z1n_id ? array() : array( 'wpcap_db_version' => '3.10.0', Settings::OPTION => array( 'delete_data_on_uninstall' => 0 === $z1n_id % 2 ? 1 : 0 ) );
}
$GLOBALS['wpcap_test_options'] = $GLOBALS['z1n_options'][1];
function is_multisite() { return true; }
function get_current_blog_id() { return $GLOBALS['z1n_blog']; }
function get_sites( $args = array() ) { return array_slice( $GLOBALS['z1n_sites'], (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) ); }
function z1n_swap( $id ) {
	$GLOBALS['z1n_options'][ $GLOBALS['z1n_blog'] ] = $GLOBALS['wpcap_test_options'];
	$GLOBALS['z1n_blog'] = (int) $id;
	$GLOBALS['wpcap_test_options'] = $GLOBALS['z1n_options'][ (int) $id ] ?? array();
	Settings::flush();
}
function switch_to_blog( $id ) { $GLOBALS['z1n_stack'][] = $GLOBALS['z1n_blog']; z1n_swap( $id ); return true; }
function restore_current_blog() { z1n_swap( array_pop( $GLOBALS['z1n_stack'] ) ); return true; }
function get_site_option( $k, $d = false ) { return $GLOBALS['z1n_site_options'][ $k ] ?? $d; }
function update_site_option( $k, $v ) { $GLOBALS['z1n_site_options'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['z1n_site_options'][ $k ] ); return true; }
function plugin_basename( $f ) { return 'pass-captain/' . basename( $f ); }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { return true; }

$out = array();
$installed = array();
$installer = static function ( $site_id ) use ( &$installed ) { $installed[] = $site_id; };
add_filter( 'wpcap_network_install_batch', static fn() => 5 );
add_filter( 'wpcap_network_install_policy_batch', static fn() => 20 );

NetworkInstall::start();
$policies = static function () {
	z1n_swap( $GLOBALS['z1n_blog'] ); // Store the current site's options.
	$out = array();
	foreach ( $GLOBALS['z1n_options'] as $id => $options ) {
		$out[ $id ] = array( $options[ Settings::UNINSTALL_POLICY ] ?? null, (int) ( $options[ Settings::OPTION ]['delete_data_on_uninstall'] ?? 0 ) );
	}
	return $out;
};
$out['run1'] = NetworkInstall::run( $installer );
$p1 = $policies();
$out['run1_installed'] = count( $installed );
$out['run1_migrated'] = count( array_filter( $p1, static fn( $p ) => null !== $p[0] ) );
NetworkInstall::run( $installer );
$p2 = $policies();
$ok = true;
foreach ( $p2 as $id => $p ) {
	$expect = 1 === $id ? array( null, 0 ) : array( 0 === $id % 2 ? 'delete' : 'keep', 0 );
	if ( $expect !== $p ) { $ok = false; }
}
$out['run2_all_migrated'] = $ok;
$out['run2_installed'] = count( $installed );
$out['policies_done'] = ! empty( NetworkInstall::state()['policies_done'] );
$out['back_on_blog_1'] = 1 === $GLOBALS['z1n_blog'];
for ( $i = 0; $i < 10 && NetworkInstall::state(); $i++ ) { NetworkInstall::run( $installer ); }
$out['all_installed_once'] = range( 1, 30 ) === $installed;
$out['finished'] = null === NetworkInstall::state();

echo json_encode( $out );
