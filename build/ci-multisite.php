<?php
/**
 * Helper for build/ci-multisite-test.sh (run with `wp eval-file`): reads the plugin state of every site of a
 * multisite network without loading plugin classes (also works after the uninstall).
 *
 *   wp eval-file build/ci-multisite.php state            JSON: one entry per site
 *   wp eval-file build/ci-multisite.php optin <id,...>    sets wpcap_uninstall_policy=delete on these sites
 *   wp eval-file build/ci-multisite.php usermeta          JSON: global and per-site wpcap_* user meta rows
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile -- CI helper, runs inside WP-CLI.

global $wpdb;
$command = $args[0] ?? 'state';
$cron_hooks = array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_run_upgrade', 'wpcap_privacy_retention', 'wpcap_privacy_purge_wallet_registrations', 'wpcap_process_fiscal_order' );

if ( 'optin' === $command ) {
	foreach ( array_filter( array_map( 'intval', explode( ',', (string) ( $args[1] ?? '' ) ) ) ) as $site_id ) {
		switch_to_blog( $site_id );
		// Same storage as Settings::update( array( 'delete_data_on_uninstall' => 1 ) ).
		update_option( 'wpcap_uninstall_policy', 'delete', false );
		update_user_option( 1, 'wpcap_ci_site_pref', 'x' ); // Per-site user option ({prefix}wpcap_*).
		restore_current_blog();
	}
	update_user_meta( 1, 'wpcap_ci_global', 'x' ); // Network-wide user meta.
	echo 'ok';
	return;
}

if ( 'usermeta' === $command ) {
	echo wp_json_encode(
		array(
			'global' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'wpcap_' ) . '%' ) ),
			'all' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, '%' . $wpdb->esc_like( 'wpcap_' ) . '%' ) ),
		)
	);
	return;
}

$sites = array();
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
	switch_to_blog( (int) $site_id );
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpcap_' ) . '%' ) );
	$cron = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook ) { if ( in_array( $hook, $cron_hooks, true ) ) { $cron[] = $hook; } }
	}
	$options = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'wpcap_' ) . '%' ) );
	$sites[] = array(
		'id' => (int) $site_id,
		'url' => get_site_url( (int) $site_id ),
		'db_version' => (string) get_option( 'wpcap_db_version', '' ),
		'tables' => count( $tables ),
		'options' => $options,
		'policy' => (string) get_option( 'wpcap_uninstall_policy', '' ),
		'cron' => array_values( array_unique( $cron ) ),
	);
	restore_current_blog();
}
// Tables of sites that no longer exist (deleted site must not leave plugin tables behind).
$known = array_map( static function ( $s ) use ( $wpdb ) { return $wpdb->get_blog_prefix( $s['id'] ); }, $sites );
$orphans = 0;
foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' . $wpdb->esc_like( 'wpcap_' ) . '%' ) ) as $table ) {
	$owner = preg_replace( '/wpcap_.*$/', '', $table );
	if ( ! in_array( $owner, $known, true ) ) { ++$orphans; }
}
echo wp_json_encode( array( 'sites' => $sites, 'orphan_tables' => $orphans ) );
