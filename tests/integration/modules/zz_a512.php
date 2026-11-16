<?php
/**
 * A512: plugin activation installs behind the upgrade lock (Installer::run_locked()), so it never runs
 * dbDelta in parallel with an upgrade. With the lock held by another session the activation waits at
 * most `wpcap_activation_lock_wait` seconds and then leaves the schema alone (Installer::install()
 * is never called here: the test dbDelta stub drops tables).
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Installer;
use PassCaptain\Plugin;

global $wpdb;
echo "A512: activation runs under the upgrade lock\n";

$x2_a512_other = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$x2_a512_held = '1' === (string) $x2_a512_other->query( "SELECT GET_LOCK('" . $x2_a512_other->real_escape_string( Installer::lock_name() ) . "',0)" )->fetch_row()[0];
$GLOBALS['wpcap_test_filters']['wpcap_activation_lock_wait'] = 1;
$x2_a512_cron = $GLOBALS['wpcap_test_cron'] ?? array();
$GLOBALS['wpcap_test_cron'] = array();
$GLOBALS['wpcap_test_query_log'] = array();
$x2_a512_start = microtime( true );
Plugin::activate( false );
$x2_a512_took = microtime( true ) - $x2_a512_start;
$x2_a512_log = $GLOBALS['wpcap_test_query_log'];
unset( $GLOBALS['wpcap_test_query_log'], $GLOBALS['wpcap_test_filters']['wpcap_activation_lock_wait'] );
$x2_a512_other->query( "SELECT RELEASE_LOCK('" . $x2_a512_other->real_escape_string( Installer::lock_name() ) . "')" );
$x2_a512_other->close();
$x2_a512_ddl = array_filter( $x2_a512_log, static fn( $q ) => 1 === preg_match( '/^\s*(CREATE|DROP|ALTER)\s+TABLE/i', $q ) );
$x2_a512_waited = (bool) array_filter( $x2_a512_log, static fn( $q ) => false !== strpos( $q, 'GET_LOCK(' ) && false !== strpos( $q, ',1)' ) );
check( 'A512: with the upgrade lock taken elsewhere, activation waits briefly and runs no schema change', $x2_a512_held && array() === $x2_a512_ddl && $x2_a512_waited && $x2_a512_took >= 0.9 && $x2_a512_took < 10 );
check( 'A512: and schedules no jobs (init re-schedules them once the running upgrade is done)', array() === (array) $GLOBALS['wpcap_test_cron'] );
$GLOBALS['wpcap_test_cron'] = $x2_a512_cron;

$x2_a512_src = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'A512: activate_site() installs only inside Installer::run_locked()', 1 === preg_match( '/Installer::run_locked\(\s*static function \(\) use \( &\$installed \) \{\s*\$installed = Installer::install\(\);/', $x2_a512_src ) && 1 === substr_count( $x2_a512_src, 'Installer::install()' ) );
