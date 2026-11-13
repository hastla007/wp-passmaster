<?php
/**
 * Uninstall handler.
 *
 * Data is kept by default (orders and invoices are subject to retention duties). Only when
 * "Daten beim Löschen entfernen" (delete_data_on_uninstall) is enabled, all plugin tables, options,
 * transients, roles and capabilities are removed. On a multisite network this is decided per site:
 * each site's tables, options and per-site user options ({site prefix}wpcap_*) are removed only for
 * sites that opted in. Network-wide user meta (wpcap_*) is shared by all sites and is therefore
 * only deleted when every site opted in.
 *
 * @package PassCaptain
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/** Removes the plugin data of the current site if the operator opted in; returns whether it did. */
function wpcap_uninstall_site() {
	global $wpdb;
	// Same list as PassCaptain\Plugin::cron_hooks() (the plugin classes are not loaded here).
	foreach ( array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_reconcile_capacity', 'wpcap_run_upgrade', 'wpcap_privacy_retention', 'wpcap_privacy_purge_wallet_registrations', 'wpcap_waitlist_process' ) as $hook ) {
		wp_unschedule_hook( $hook ); // All events of the hook, including continuation runs with arguments.
	}
	$settings = get_option( 'wpcap_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return false;
	}
	$like = $wpdb->esc_like( $wpdb->prefix . 'wpcap_' ) . '%';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from SHOW TABLES for our own prefix.
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'wpcap_' ) . '%', $wpdb->esc_like( '_transient_wpcap_' ) . '%', $wpdb->esc_like( '_transient_timeout_wpcap_' ) . '%' ) );
	foreach ( array( 'wpcap_ticket_admin', 'wpcap_event_manager', 'wpcap_box_office', 'wpcap_checkin_staff', 'wpcap_partner', 'wpcap_finance', 'wpcap_support' ) as $role ) {
		remove_role( $role );
	}
	$caps = array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance' );
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) { $role->remove_cap( $cap ); }
	}
	foreach ( get_users( array( 'fields' => 'all', 'capability__in' => $caps ) ) as $user ) {
		foreach ( $caps as $cap ) { $user->remove_cap( $cap ); }
	}
	// Per-site user options (update_user_option) carry the site prefix, so this only hits this site.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $wpdb->get_blog_prefix() . 'wpcap_' ) . '%' ) );
	return true;
}

/** Deletes network-wide (unprefixed) user meta wpcap_*. */
function wpcap_uninstall_global_usermeta() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpcap_' ) . '%' ) );
}

if ( is_multisite() ) {
	$wpcap_all_opted_in = true;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
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
