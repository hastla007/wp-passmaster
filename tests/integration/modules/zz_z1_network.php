<?php
// phpcs:ignoreFile
/**
 * Z1 (merge follow-up): after a network activation the uninstall opt-in protection (INT-1) of every site
 * no longer waits for that site's install batch. NetworkInstall::run() first migrates the opt-in of up to
 * POLICY_BATCH sites (only sites with plugin data), then installs its BATCH. Child process (multisite
 * stubs must not leak into later tests). Self-contained (WPCAP_TEST_ONLY=z1_network).
 */

echo "Z1: uninstall opt-in protection of all sites ahead of the install batches\n";
$z1nw_raw = (string) shell_exec( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_z1_network/child.php' ) . ' 2>&1' );
$z1nw = json_decode( trim( $z1nw_raw ), true );
check( 'z1: network child process ran' . ( is_array( $z1nw ) ? '' : ' – ' . substr( $z1nw_raw, 0, 300 ) ), is_array( $z1nw ) );
$z1nw = is_array( $z1nw ) ? $z1nw : array();
check( 'z1: the first run installs one batch (5 sites) but migrates the opt-in of 20 sites', 5 === ( $z1nw['run1'] ?? 0 ) && 5 === ( $z1nw['run1_installed'] ?? 0 ) && 19 === ( $z1nw['run1_migrated'] ?? 0 ) );
check( 'z1: after the second run every site with plugin data is protected (opt-in moved, old key 0), the site without data untouched', ! empty( $z1nw['run2_all_migrated'] ) && 10 === ( $z1nw['run2_installed'] ?? 0 ) && ! empty( $z1nw['policies_done'] ) && ! empty( $z1nw['back_on_blog_1'] ) );
check( 'z1: the install batches still visit every site exactly once and finish', ! empty( $z1nw['all_installed_once'] ) && ! empty( $z1nw['finished'] ) );
