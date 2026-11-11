<?php
/**
 * Uninstall handler.
 *
 * Data is kept by default (orders and invoices are subject to retention duties). Only when
 * "Daten beim Löschen entfernen" (delete_data_on_uninstall) is enabled, all plugin tables, options,
 * transients, roles and capabilities are removed – on every site of a multisite network.
 *
 * @package PassCaptain
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/** Removes the plugin data of the current site if the operator opted in. */
function wpcap_uninstall_site() {
	global $wpdb;
	foreach ( array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	$settings = get_option( 'wpcap_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
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
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpcap_' ) . '%' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		wpcap_uninstall_site();
		restore_current_blog();
	}
} else {
	wpcap_uninstall_site();
}
