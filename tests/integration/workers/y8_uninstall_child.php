<?php
/**
 * Child process for tests/integration/modules/zzzzz_y8_small_bugs.php: runs uninstall.php with
 * WordPress stubs (single site, data kept) and prints the scheduler calls as one JSON line.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'pass-captain/pass-captain.php' );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/wpcap-y8-plugins' ); // Defined by WordPress before uninstall.php runs (no legacy copy here).
$GLOBALS['y8_calls'] = array();
function wp_unschedule_hook( $hook ) { $GLOBALS['y8_calls'][] = 'cron:' . $hook; return 0; }
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) { $GLOBALS['y8_calls'][] = 'as:' . $hook . '|' . $group; }
function get_option( $key, $default = false ) { return array(); }
function is_multisite() { return false; }
require dirname( __DIR__, 3 ) . '/uninstall.php';
echo json_encode( $GLOBALS['y8_calls'] ), "\n";
