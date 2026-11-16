<?php
/**
 * Uninstall handler.
 *
 * Data is kept by default (orders and invoices are subject to retention duties). Only when
 * "Daten beim Löschen entfernen" is enabled (stored as option wpcap_uninstall_policy = 'delete'; the old
 * key wpcap_settings['delete_data_on_uninstall'] is only read on sites whose upgrade has not moved it yet,
 * because the uninstall.php of the old "wp-pass-captain" copy reads it on every site of a network), all plugin tables, options,
 * transients, roles and capabilities are removed. On a multisite network this is decided per site:
 * each site's tables, options and per-site user options ({site prefix}wpcap_*) are removed only for
 * sites that opted in. Network-wide user meta (wpcap_*) is shared by all sites and is therefore
 * only deleted when every site opted in; sites without any plugin data count as agreeing (A514).
 * Network options wpcap_* and the plugin's Action Scheduler actions are always removed. While the pre-rename
 * copy (folder wp-pass-captain) is still installed, every site keeps its tables (it uses the same ones).
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
	// Same list as PassCaptain\Plugin::cron_hooks() (the plugin classes are not loaded here).
	foreach ( array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_workflow_dispatch', 'wpcap_process_fiscal_order', 'wpcap_reconcile_capacity', 'wpcap_run_upgrade', 'wpcap_privacy_retention', 'wpcap_privacy_purge_wallet_registrations', 'wpcap_waitlist_process', 'wpcap_page_cache_sales_window', 'wpcap_mollie_refresh_methods', 'wpcap_network_install' ) as $hook ) {
		wp_unschedule_hook( $hook ); // All events of the hook, including continuation runs with arguments.
	}
	$legacy_installed = is_file( WP_PLUGIN_DIR . '/wp-pass-captain/wp-pass-captain.php' ); // legacy-slug
	// Action Scheduler follow-up runs (Jobs\TimeBudget::AS_GROUP; the pre-rename group only when the
	// old copy is gone – it still uses it), like Plugin::deactivate() (A514).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'pass-captain' );
		if ( ! $legacy_installed ) {
			as_unschedule_all_actions( '', array(), 'wp-pass-captain' ); // legacy-slug
		}
	}
	// The pre-rename copy (folder wp-pass-captain) still installed: it uses the same tables – keep them. (legacy-slug)
	if ( $legacy_installed ) {
		return false;
	}
	// A site without plugin data (never installed there, or already removed by an aborted run) keeps
	// nothing, so it counts as agreeing to the removal of network-wide data (A514).
	if ( ! wpcap_uninstall_site_has_data() ) {
		return true;
	}
	if ( ! wpcap_uninstall_opted_in() ) {
		return false;
	}
	$like = $wpdb->esc_like( $wpdb->prefix . 'wpcap_' ) . '%';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from SHOW TABLES for our own prefix.
	}
	wpcap_uninstall_options();
	// A284: links on WooCommerce orders/lines/products (WooCommerce CRUD, only while WooCommerce is active).
	// Otherwise a reinstall would start the IDs again and old orders would point to new, unrelated ones.
	if ( function_exists( 'wc_get_orders' ) ) {
		require_once __DIR__ . '/src/Integrations/WooCommerce/MetaCleanup.php';
		PassCaptain\Integrations\WooCommerce\MetaCleanup::purge();
	}
	foreach ( array( 'wpcap_ticket_admin', 'wpcap_event_manager', 'wpcap_box_office', 'wpcap_checkin_staff', 'wpcap_partner', 'wpcap_service_provider', 'wpcap_finance', 'wpcap_support' ) as $role ) {
		remove_role( $role );
	}
	// Core and module capabilities from one list (A372: wpcap_approve_comps was left behind).
	require_once __DIR__ . '/src/Capabilities.php';
	// Plus the audit-line capabilities (Installer::register_roles() also grants wpcap_view_audit to administrators).
	$caps = array_values( array_unique( array_merge( PassCaptain\Capabilities::all(), array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports', 'wpcap_refund', 'wpcap_approve_refunds', 'wpcap_view_finance', 'wpcap_view_audit' ) ) ) );
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

/**
 * Whether the operator opted in to removing the data of the current site: option wpcap_uninstall_policy
 * ('delete'); a site whose upgrade never moved the opt-in there still has it in
 * wpcap_settings['delete_data_on_uninstall'] (releases before 1.5).
 */
function wpcap_uninstall_opted_in() {
	$policy = get_option( 'wpcap_uninstall_policy', false );
	if ( false !== $policy ) {
		return 'delete' === $policy;
	}
	$settings = get_option( 'wpcap_settings', array() );
	return is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
}

/**
 * Deletes the site's options and transients wpcap_* through the options API (A467), so a persistent
 * object cache forgets them too: with raw SQL the cached `alloptions` kept e.g. `wpcap_db_version`,
 * and a later reinstall skipped creating its tables. Names come from the options table; transients
 * that only live in the object cache are removed by name where they are known and by cache group
 * where the cache supports it.
 */
function wpcap_uninstall_options() {
	global $wpdb;
	$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'wpcap_' ) . '%', $wpdb->esc_like( '_transient_wpcap_' ) . '%' ) );
	foreach ( $names as $name ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) ); // Also removes its _transient_timeout_ row.
		} else {
			delete_option( $name );
		}
	}
	// Timeouts without their transient (left over by an interrupted write).
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_wpcap_' ) . '%' ) ) as $name ) {
		delete_option( (string) $name );
	}
	// Fixed-name transients that a persistent object cache holds outside the options table.
	foreach ( array( 'wpcap_capacity_drift_mail', 'wpcap_wallet_google_token', 'wpcap_health_critical' ) as $transient ) {
		delete_transient( $transient );
	}
	// Object-cache groups of the plugin (availability cache, rate-limit counters).
	if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
		foreach ( array( 'wpcap', 'wpcap_rate' ) as $group ) { wp_cache_flush_group( $group ); }
	}
	// The cached option lists themselves (some object caches keep them even after delete_option()).
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/** Deletes network-wide (unprefixed) user meta wpcap_*. */
function wpcap_uninstall_global_usermeta() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'wpcap_' ) . '%' ) );
}

/**
 * Deletes the network options wpcap_* (sitemeta of the current network): background installation
 * of a network activation (A287), "old copy seen" (LegacySlug::SEEN_OPTION) and any later ones (A514).
 */
function wpcap_uninstall_site_options() {
	global $wpdb;
	delete_site_option( 'wpcap_network_install' );
	delete_site_option( 'wpcap_legacy_copy_seen' );
	if ( empty( $wpdb->sitemeta ) ) {
		return;
	}
	$network = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id=%d AND meta_key LIKE %s", $network, $wpdb->esc_like( 'wpcap_' ) . '%' ) ) as $key ) {
		delete_site_option( (string) $key );
	}
}

if ( is_multisite() ) {
	wpcap_uninstall_site_options();
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
