<?php
/**
 * Worker for tests/integration/modules/zz_a287.php, in its own process: multisite functions are
 * stubbed here (in the main process is_multisite() is already defined as false). Prints one JSON object.
 * Usage: php child.php
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';

use PassCaptain\NetworkInstall;

$GLOBALS['a287_sites'] = range( 1, 45 );
$GLOBALS['a287_blog'] = 1;
$GLOBALS['a287_site_options'] = array( 'active_sitewide_plugins' => array( 'pass-captain/pass-captain.php' => time() ) );
$GLOBALS['a287_cron'] = array();
function is_multisite() { return true; }
function get_current_blog_id() { return $GLOBALS['a287_blog']; }
function get_sites( $args = array() ) { return array_slice( $GLOBALS['a287_sites'], (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) ); }
function switch_to_blog( $id ) { $GLOBALS['a287_stack'][] = $GLOBALS['a287_blog']; $GLOBALS['a287_blog'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['a287_blog'] = array_pop( $GLOBALS['a287_stack'] ); return true; }
function get_site_option( $k, $d = false ) { return $GLOBALS['a287_site_options'][ $k ] ?? $d; }
function update_site_option( $k, $v ) { $GLOBALS['a287_site_options'][ $k ] = $v; return true; }
function delete_site_option( $k ) { unset( $GLOBALS['a287_site_options'][ $k ] ); return true; }
function plugin_basename( $f ) { return 'pass-captain/' . basename( $f ); }
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['a287_cron'][ $GLOBALS['a287_blog'] ][ $hook ] ?? false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['a287_cron'][ $GLOBALS['a287_blog'] ][ $hook ] = (int) $ts; return true; }

$out = array();
$installed = array();
$installer = static function ( $site_id ) use ( &$installed ) { $installed[] = array( $site_id, $GLOBALS['a287_blog'] ); };
add_filter( 'wpcap_network_install_batch', static fn() => 20 );

NetworkInstall::start();
$out['queued'] = is_array( NetworkInstall::state() ) && 0 === NetworkInstall::state()['offset'];
$out['scheduled_on_activating_site'] = isset( $GLOBALS['a287_cron'][1][ NetworkInstall::HOOK ] );

$GLOBALS['a287_cron'] = array();
$out['run1'] = NetworkInstall::run( $installer );
$out['after_run1_offset'] = NetworkInstall::state()['offset'] ?? null;
$out['rescheduled'] = isset( $GLOBALS['a287_cron'][1][ NetworkInstall::HOOK ] );
$out['switched_per_site'] = 20 === count( $installed ) && $installed[0] === array( 1, 1 ) && $installed[19] === array( 20, 20 ) && 1 === $GLOBALS['a287_blog'];
$GLOBALS['a287_cron'] = array();
$out['run2'] = NetworkInstall::run( $installer );
$out['run3'] = NetworkInstall::run( $installer );
$out['all_sites_once'] = range( 1, 45 ) === array_map( static fn( $i ) => $i[0], $installed );
$out['finished'] = null === NetworkInstall::state();
$out['run_after_finish'] = NetworkInstall::run( $installer );

// A site that fails hard is not retried in a loop: progress is saved before each site.
NetworkInstall::start();
try { NetworkInstall::run( static function ( $id ) { if ( 3 === $id ) { throw new RuntimeException( 'boom' ); } } ); } catch ( RuntimeException $e ) { /* fatal in the real request */ }
$out['offset_past_failed_site'] = 3 === ( NetworkInstall::state()['offset'] ?? null ) && 1 === $GLOBALS['a287_blog'];

// Network deactivation in between: the queue is dropped, nothing installed.
$GLOBALS['a287_site_options']['active_sitewide_plugins'] = array();
$count = count( $installed );
$out['stops_when_not_network_active'] = 0 === NetworkInstall::run( $installer ) && $count === count( $installed ) && null === NetworkInstall::state();

echo json_encode( $out );
