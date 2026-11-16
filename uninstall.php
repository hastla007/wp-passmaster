<?php
/**
 * Uninstall handler.
 *
 * Data is kept by default (orders and invoices are subject to retention duties). Only when
 * "Daten beim Löschen entfernen" is enabled (stored as option wpcap_uninstall_policy = 'delete'; the old
 * key wpcap_settings['delete_data_on_uninstall'] is no longer read, because the uninstall.php of the old
 * "wp-pass-captain" copy reads it on every site of a network), all plugin tables, options,
 * transients, roles and capabilities are removed. On a multisite network this is decided per site:
 * each site's tables, options and per-site user options ({site prefix}wpcap_*) are removed only for
 * sites that opted in. Network-wide user meta (wpcap_*) is shared by all sites and is therefore
 * only deleted when every site opted in (a site without any plugin data keeps nothing and counts as opted in).
 *
 * Large networks: the loop asks PHP for more time and keeps running when the browser disconnects. If the
 * request is still cut off (hard max_execution_time of the web server), WordPress keeps the plugin files and
 * reports the failure; deleting the plugin again repeats the uninstall. Every step is idempotent: sites
 * already cleaned up have no data left and are skipped, so the repetition continues where it stopped.
 * Deleting the plugin with WP-CLI (`wp plugin deactivate pass-captain --network`, then `wp plugin uninstall pass-captain`) has no
 * time limit.
 *
 * @package PassCaptain
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/** Removes the plugin data of the current site if the operator opted in; returns whether it did. */
function wpcap_uninstall_site() {
	global $wpdb;
	foreach ( array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_run_upgrade', 'wpcap_privacy_retention', 'wpcap_privacy_purge_wallet_registrations', 'wpcap_process_fiscal_order' ) as $hook ) {
		wp_unschedule_hook( $hook ); // All events of the hook, including continuation runs with arguments.
	}
	// Action Scheduler runs (Jobs\TimeBudget continuations), when another plugin such as WooCommerce provides it.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'wp-pass-captain' );
	}
	if ( 'delete' !== get_option( 'wpcap_uninstall_policy', 'keep' ) ) {
		return ! wpcap_uninstall_site_has_data(); // Nothing kept (never installed, or already removed by an aborted run).
	}
	$like = $wpdb->esc_like( $wpdb->prefix . 'wpcap_' ) . '%';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from SHOW TABLES for our own prefix.
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'wpcap_' ) . '%', $wpdb->esc_like( '_transient_wpcap_' ) . '%', $wpdb->esc_like( '_transient_timeout_wpcap_' ) . '%' ) );
	foreach ( array( 'wpcap_ticket_admin', 'wpcap_event_manager', 'wpcap_box_office', 'wpcap_checkin_staff', 'wpcap_partner', 'wpcap_finance', 'wpcap_support' ) as $role ) {
		remove_role( $role );
	}
	$caps = array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance', 'wpcap_view_audit' ); // Installer::register_roles() also grants wpcap_view_audit to administrators.
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) { $role->remove_cap( $cap ); }
	}
	foreach ( get_users( array( 'fields' => 'all', 'capability__in' => $caps ) ) as $user ) {
		foreach ( $caps as $cap ) { $user->remove_cap( $cap ); }
	}
	// Per-site user options (update_user_option) carry the site prefix, so this only hits this site.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( $wpdb->get_blog_prefix() . 'wpcap_' ) . '%' ) );
	return true;
}

/** Whether the current site has plugin data (schema version, settings or tables). */
function wpcap_uninstall_site_has_data() {
	global $wpdb;
	if ( false !== get_option( 'wpcap_db_version', false ) || false !== get_option( 'wpcap_settings', false ) ) {
		return true;
	}
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpcap_' ) . '%' ) );
}

/** Deletes network-wide (unprefixed) user meta wpcap_*. */
function wpcap_uninstall_global_usermeta() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'wpcap_' ) . '%' ) );
}

if ( is_multisite() ) {
	// Many sites: more time where the host allows it, and no abort when the admin closes the browser tab.
	ignore_user_abort( true );
	if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
		set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- uninstall of a whole network, see the file comment.
	}
	$wpcap_all_opted_in = true;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpcap_site_id ) {
		switch_to_blog( (int) $wpcap_site_id );
		$wpcap_all_opted_in = wpcap_uninstall_site() && $wpcap_all_opted_in;
		restore_current_blog();
	}
	// User meta is shared by the whole network: keep it while any site still keeps its data.
	if ( $wpcap_all_opted_in ) {
		wpcap_uninstall_global_usermeta();
	}
} elseif ( wpcap_uninstall_site() ) {
	wpcap_uninstall_global_usermeta();
}
