<?php
// phpcs:ignoreFile
/**
 * A287: network activation installs only the current site in the activation request; every other site
 * is installed lazily or by the background batches of `wpcap_network_install` (resumable, per site
 * switch_to_blog, stops when the plugin is no longer network-active). Runs in a child process
 * (multisite stubs must not leak into later tests).
 */

echo "A287: network activation in background batches\n";
$a287_cmd = 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a287/child.php' ) . ' 2>&1';
$a287_raw = (string) shell_exec( $a287_cmd );
$a287 = json_decode( trim( $a287_raw ), true );
check( 'A287 child process ran' . ( is_array( $a287 ) ? '' : ' – ' . substr( $a287_raw, 0, 300 ) ), is_array( $a287 ) );
$a287 = is_array( $a287 ) ? $a287 : array();
check( 'A287 activation queues the network and schedules the batch on the activating site', ! empty( $a287['queued'] ) && ! empty( $a287['scheduled_on_activating_site'] ) );
check( 'A287 one run installs one batch (20 sites), each after switch_to_blog, and reschedules', 20 === ( $a287['run1'] ?? 0 ) && 20 === ( $a287['after_run1_offset'] ?? 0 ) && ! empty( $a287['rescheduled'] ) && ! empty( $a287['switched_per_site'] ) );
check( 'A287 following runs finish the rest; every site exactly once; the queue is removed', 20 === ( $a287['run2'] ?? 0 ) && 5 === ( $a287['run3'] ?? 0 ) && ! empty( $a287['all_sites_once'] ) && ! empty( $a287['finished'] ) && 0 === ( $a287['run_after_finish'] ?? -1 ) );
check( 'A287 a failing site is skipped (lazy install later), not retried in a loop', ! empty( $a287['offset_past_failed_site'] ) );
check( 'A287 nothing runs once the plugin is no longer network-active', ! empty( $a287['stops_when_not_network_active'] ) );

$a287_plugin = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
preg_match( '/public static function activate\(.*?\n\t}\n/s', $a287_plugin, $a287_m );
check( 'A287 Plugin::activate() no longer loops over all sites', isset( $a287_m[0] ) && false === strpos( $a287_m[0], 'get_sites' ) && false === strpos( $a287_m[0], 'switch_to_blog' ) && false !== strpos( $a287_m[0], 'NetworkInstall::start()' ) );
check( 'A287 the batch hook is unscheduled on deactivation/uninstall and its state removed on uninstall', in_array( PassCaptain\NetworkInstall::HOOK, PassCaptain\Plugin::cron_hooks(), true ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'uninstall.php' ), "delete_site_option( 'wpcap_network_install' )" ) );
