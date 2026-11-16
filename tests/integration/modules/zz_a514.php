<?php
/**
 * A514: multisite uninstall – sites without plugin data count as agreeing, so network-wide user meta
 * is removed once every site that has data opted in; network options wpcap_* (sitemeta) and the
 * Action Scheduler groups are removed. uninstall.php runs in a child process
 * (zz_x2_uninstall/child.php, x2_uninstall_run() from zz_a372.php).
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile

echo "A514: multisite uninstall – empty sites, sitemeta, Action Scheduler\n";

if ( ! function_exists( 'x2_uninstall_run' ) ) { require __DIR__ . '/zz_a372.php'; }

$x2_a514_sitemeta = array( 'wpcap_network_install', 'wpcap_legacy_copy_seen', 'wpcap_future_flag' );
// Site 1 opted in, site 2 never had the plugin (no tables/options), site 3 opted in.
$x2_a514 = x2_uninstall_run( array(
	'multisite' => true,
	'sites' => array( 1 => array( 'data' => true, 'delete' => true ), 2 => array( 'data' => false ), 3 => array( 'data' => true, 'delete' => true ) ),
	'sitemeta' => $x2_a514_sitemeta,
) );
check( 'A514: a site without plugin data does not block removing network-wide user meta', ! in_array( 'wpcap_legacy_theme_notice_dismissed', (array) ( $x2_a514['usermeta'] ?? array( 'wpcap_legacy_theme_notice_dismissed' ) ), true ) && in_array( 'nickname', (array) ( $x2_a514['usermeta'] ?? array() ), true ) );
check( 'A514: opted-in sites lose their tables, the empty site keeps its own options', array() === ( $x2_a514['sites'][1]['tables'] ?? array( 'x' ) ) && array() === ( $x2_a514['sites'][3]['tables'] ?? array( 'x' ) ) && array( 'blogname' ) === ( $x2_a514['sites'][2]['options'] ?? array() ) );
check( 'A514: every network option wpcap_* is deleted (sitemeta), others stay', array( 'site_name' ) === ( $x2_a514['sitemeta'] ?? array() ) );
$x2_a514_as = (array) ( $x2_a514['as'] ?? array() );
check( 'A514: Action Scheduler groups pass-captain and wp-pass-captain are cleared on every site', 3 === count( $x2_a514_as ) && ! array_filter( $x2_a514_as, static fn( $groups ) => array( 'pass-captain', 'wp-pass-captain' ) !== $groups ) );

// A site with data that keeps it still protects the shared user meta.
$x2_a514_keep = x2_uninstall_run( array(
	'multisite' => true,
	'sites' => array( 1 => array( 'data' => true, 'delete' => true ), 2 => array( 'data' => true, 'delete' => false ) ),
	'sitemeta' => array( 'wpcap_network_install' ),
) );
check( 'A514: a site with data that did not opt in keeps the network-wide user meta and its tables', in_array( 'wpcap_legacy_theme_notice_dismissed', (array) ( $x2_a514_keep['usermeta'] ?? array() ), true ) && array() !== ( $x2_a514_keep['sites'][2]['tables'] ?? array() ) && array( 'site_name' ) === ( $x2_a514_keep['sitemeta'] ?? array() ) );
