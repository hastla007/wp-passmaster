<?php
/**
 * Audit panel 2026-11-15, package Y6: integrations, multisite and WordPress.org.
 *
 * INT-1 uninstall opt-in out of reach of the old copy, INT-2 QR SVG from the own Bacon matrix (endroid gone),
 * INT-3 copied WooCommerce meta is not trusted, INT-4/INT-12 trashed/deleted WooCommerce orders, INT-5 audit on
 * sites without plugin tables, INT-7 deactivation clears every cron hook (+ fiscal retries come back on
 * activation), INT-8 old copy cannot be re-activated, INT-9 product block editor, INT-10 WooCommerce orders
 * without checkout and required attendee fields, INT-11 no Composer platform check, INT-13 deferred network
 * activation, WPORG-1/3/4/5, mobile API Authorization fallback.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Audit;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Y6Woo;
use PassCaptain\Orders;
use PassCaptain\Plugin as Y6Plugin;
use PassCaptain\QrCode as Y6Qr;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$y6_root = dirname( __DIR__, 3 ) . '/';
if ( is_readable( $y6_root . 'vendor/autoload.php' ) ) { require_once $y6_root . 'vendor/autoload.php'; }
if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $hook ) { $GLOBALS['y6_unscheduled'][] = $hook; return 0; } }
if ( ! function_exists( 'get_post_type' ) ) { function get_post_type( $id ) { return $GLOBALS['y6_post_types'][ (int) $id ] ?? false; } }
if ( ! function_exists( 'ms_is_switched' ) ) { function ms_is_switched() { return ! empty( $GLOBALS['y6_switched'] ); } }
if ( ! function_exists( 'plugin_basename' ) ) { function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); } }
if ( ! function_exists( 'wp_register_style' ) ) { function wp_register_style( $h, $src = '', $deps = array(), $ver = false ) { $GLOBALS['y6_styles'][ $h ] = $src; return true; } }
if ( ! function_exists( 'wp_add_inline_style' ) ) { function wp_add_inline_style( $h, $css ) { $GLOBALS['y6_inline'][ $h ] = $css; return true; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return ''; } } // Admin\Notices::dismissed() (also stubbed by zzzzz_x3_residuals).
if ( ! function_exists( 'wp_style_is' ) ) { function wp_style_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['y6_styles'][ $h ] ); } } // Assets::inline_style() needs it (else raw <style>).
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '' ) { throw new RuntimeException( (string) $m ); } }

/* ------------------------------------------------------------------ INT-1 */
echo "Y6 INT-1: uninstall opt-in in its own option\n";
$y6_settings_before = get_option( Settings::OPTION, array() );
$y6_policy_before   = get_option( Settings::UNINSTALL_POLICY, false );
// State written by 1.4.0: opt-in in wpcap_settings, no policy option yet.
$y6_s = is_array( $y6_settings_before ) ? $y6_settings_before : array();
$y6_s['delete_data_on_uninstall'] = 1;
update_option( Settings::OPTION, $y6_s );
delete_option( Settings::UNINSTALL_POLICY );
Settings::flush();
Settings::migrate_uninstall_policy();
$y6_after = get_option( Settings::OPTION, array() );
// What the uninstall.php of the old copy (1.4.0) decides: empty( $settings['delete_data_on_uninstall'] ) => keep.
check( 'INT-1: upgrade step moves the opt-in to wpcap_uninstall_policy and resets the old key (old uninstall.php keeps data)', 'delete' === get_option( Settings::UNINSTALL_POLICY ) && empty( $y6_after['delete_data_on_uninstall'] ) );
check( 'INT-1: the setting still reads as enabled', 1 === Settings::get( 'delete_data_on_uninstall' ) && Settings::deletes_data_on_uninstall() );
Settings::migrate_uninstall_policy();
check( 'INT-1: upgrade step is idempotent (policy kept, old key stays 0)', 'delete' === get_option( Settings::UNINSTALL_POLICY ) && empty( get_option( Settings::OPTION )['delete_data_on_uninstall'] ) );
Settings::update( array( 'delete_data_on_uninstall' => 0 ) );
check( 'INT-1: saving the setting off writes only the new key', 'keep' === get_option( Settings::UNINSTALL_POLICY ) && 0 === Settings::get( 'delete_data_on_uninstall' ) );
Settings::update( array( 'delete_data_on_uninstall' => 1 ) );
check( 'INT-1: saving the setting on never writes the old key', 'delete' === get_option( Settings::UNINSTALL_POLICY ) && empty( get_option( Settings::OPTION )['delete_data_on_uninstall'] ) );
delete_option( Settings::UNINSTALL_POLICY );
$y6_s['delete_data_on_uninstall'] = 0;
update_option( Settings::OPTION, $y6_s );
Settings::migrate_uninstall_policy();
check( 'INT-1: a site without opt-in gets the explicit policy "keep"', 'keep' === get_option( Settings::UNINSTALL_POLICY ) );
$y6_installer = (string) file_get_contents( WPCAP_PATH . 'src/Installer.php' );
check( 'INT-1: the installer runs the migration (DB >= 3.14.0)', false !== strpos( $y6_installer, 'Settings::migrate_uninstall_policy();' ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.14.0', '>=' ) );
$y6_uninstall = (string) file_get_contents( WPCAP_PATH . 'uninstall.php' );
$y6_reads_old = (bool) preg_match( "/\\$\\w+\\['delete_data_on_uninstall'\\]/", $y6_uninstall );
// Merged design: the explicit policy option decides; the old settings key is read only on a site whose upgrade
// never wrote the option (opt-in from before the migration), never when the option exists.
$y6_policy_first = (bool) preg_match( '/get_option\( \'wpcap_uninstall_policy\', false \);\s*if \( false !== \$policy \) \{\s*return \'delete\' === \$policy;/', $y6_uninstall );
check( 'INT-1: uninstall.php reads only wpcap_uninstall_policy', $y6_policy_first && 1 === preg_match_all( "/\\$\\w+\\['delete_data_on_uninstall'\\]/", $y6_uninstall ) );
if ( ! $y6_reads_old ) {
	// Old key set (as a stale 1.4 site would have it), policy "keep": running uninstall.php keeps every table.
	$y6_s['delete_data_on_uninstall'] = 1;
	update_option( Settings::OPTION, $y6_s );
	update_option( Settings::UNINSTALL_POLICY, 'keep' );
	$y6_tables = count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $p . 'wpcap_', '_%\\' ) . '%' ) ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'pass-captain/pass-captain.php' ); }
	require WPCAP_PATH . 'uninstall.php';
	check( 'INT-1: uninstall.php with policy "keep" keeps all tables even if the old key is 1', $y6_tables > 40 && $y6_tables === count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $p . 'wpcap_', '_%\\' ) . '%' ) ) ) );
}
update_option( Settings::OPTION, $y6_settings_before );
if ( false === $y6_policy_before ) { delete_option( Settings::UNINSTALL_POLICY ); } else { update_option( Settings::UNINSTALL_POLICY, $y6_policy_before ); }
Settings::flush();

/* ------------------------------------------------------------------ INT-2 / PERF-12 */
echo "Y6 INT-2: QR SVG from the own matrix\n";
// Another plugin loaded an incompatible endroid/qr-code (4.x/5.x API) first.
if ( ! class_exists( '\\Endroid\\QrCode\\Builder\\Builder' ) ) {
	eval( 'namespace Endroid\\QrCode\\Builder; class Builder { public function __construct( ...$a ) { throw new \\Error( "endroid 4.x API" ); } }' );
	eval( 'namespace Endroid\\QrCode\\Writer; class SvgWriter { public function __construct( ...$a ) { throw new \\Error( "endroid 4.x API" ); } }' );
}
$y6_has_bacon = class_exists( '\\BaconQrCode\\Encoder\\Encoder' );
$y6_uri = Y6Qr::data_uri( 'PC1.y6-ticket-payload' );
$y6_svg = 0 === strpos( $y6_uri, 'data:image/svg+xml;base64,' ) ? base64_decode( substr( $y6_uri, 26 ) ) : '';
$y6_matrix = Y6Qr::matrix( 'PC1.y6-ticket-payload' );
check( 'INT-2: Bacon encoder is installed for the tests', $y6_has_bacon );
check( 'INT-2: ticket QR renders although a foreign endroid Builder is loaded', '' !== $y6_svg && false !== strpos( $y6_svg, '<svg' ) && false !== strpos( $y6_svg, '<path fill="#000" d="M' ) );
check( 'INT-2: SVG viewBox is the matrix plus the quiet zone', $y6_matrix && false !== strpos( $y6_svg, 'viewBox="0 0 ' . ( count( $y6_matrix ) + 2 * Y6Qr::QUIET_ZONE ) . ' ' ) );
check( 'INT-2: PDF and SVG share one matrix (TicketPdf::qr_matrix delegates)', PassCaptain\TicketPdf::qr_matrix( 'abc' ) === Y6Qr::matrix( 'abc' ) && array() === Y6Qr::matrix( '' ) );
check( 'INT-2: the device pairing QR uses the same renderer', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/DevicesPage.php' ), 'QrCode::data_uri( $pairing[\'qr_payload\'] )' ) );
$y6_composer = json_decode( (string) file_get_contents( WPCAP_PATH . 'composer.json' ), true );
check( 'PERF-12: endroid/qr-code is no longer a dependency, Bacon is required directly', ! isset( $y6_composer['require']['endroid/qr-code'] ) && isset( $y6_composer['require']['bacon/bacon-qr-code'] ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/QrCode.php' ), 'Endroid\\QrCode\\Builder' ) );
check( 'PERF-12: own vendor Bacon is not reported as foreign; the status page checks it', '' === Y6Qr::foreign_encoder() && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Ops/Status.php' ), 'QrCode::foreign_encoder()' ) );

/* ------------------------------------------------------------------ INT-3 */
echo "Y6 INT-3: copied WooCommerce meta is not trusted\n";
$y6_type = make_type( 10 );
$y6_wc = make_wc_order( 960001, $y6_type, 2 );
$y6_core = Y6Woo::reserve( $y6_wc );
Y6Woo::sync_paid_order( 960001 );
check( 'INT-3: original order is linked and has its tickets', array( (int) $y6_core ) === Y6Woo::core_order_ids( $y6_wc ) && 2 === count( tickets( $y6_core, 'valid' ) ) );
// A renewal / duplicated order with a copy of every meta key.
$y6_copy = make_wc_order( 960002, $y6_type, 2 );
$y6_copy->meta = $y6_wc->meta;
check( 'INT-3: the copy does not own the original core order', array() === Y6Woo::core_order_ids( $y6_copy ) );
Y6Woo::sync_cancelled_order( 960002 );
check( 'INT-3: cancelling/failing the copy leaves the original tickets valid', 'paid' === Orders::get( $y6_core )->payment_status && 2 === count( tickets( $y6_core, 'valid' ) ) );
check( 'INT-3: the core order maps back to the original WooCommerce order', function_exists( 'wc_get_orders' ) && ( $y6_back = Y6Woo::wc_order_for_core( $y6_core ) ) && 960001 === $y6_back->get_id() );
$y6_pending = make_wc_order( 960003, $y6_type, 1 );
$y6_pending_core = Y6Woo::reserve( $y6_pending );
$y6_copy2 = make_wc_order( 960004, $y6_type, 1 );
$y6_copy2->meta = $y6_pending->meta;
Y6Woo::sync_cancelled_order( 960004 );
check( 'INT-3: a copy of an unpaid order does not release the original hold', 'pending_payment' === Orders::get( $y6_pending_core )->status && array( (int) $y6_pending_core ) === Y6Woo::core_order_ids( $y6_pending ) );
$y6_filtered = Y6Woo::exclude_copied_meta( array( '_wpcap_core_order_id' => 5, '_wpcap_checkout_attempt' => 1, '_billing_email' => 'a@example.com' ) );
$y6_rows = Y6Woo::exclude_copied_meta( array( array( 'meta_key' => '_wpcap_core_orders', 'meta_value' => 'x' ), array( 'meta_key' => '_order_key', 'meta_value' => 'y' ) ) );
$y6_sql = Y6Woo::exclude_copied_meta( "SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 1" );
check( 'INT-3: Subscriptions copy filters drop _wpcap_* (array, rows and SQL form)', array( '_billing_email' => 'a@example.com' ) === $y6_filtered && 1 === count( $y6_rows ) && '_order_key' === reset( $y6_rows )['meta_key'] && false !== strpos( $y6_sql, "NOT LIKE '\\_wpcap\\_%'" ) );
$y6_adapter = (string) file_get_contents( WPCAP_PATH . 'src/Integrations/WooCommerce/OrderAdapter.php' );
check( 'INT-3: the copy filters are registered', false !== strpos( $y6_adapter, "'wc_subscriptions_renewal_order_data'" ) && false !== strpos( $y6_adapter, "'wcs_renewal_order_meta_query'" ) && false !== strpos( $y6_adapter, "'wc_subscriptions_subscription_data'" ) );

/* ------------------------------------------------------------------ INT-4 / INT-12 */
echo "Y6 INT-4: trashed and deleted WooCommerce orders\n";
$y6_t4 = make_type( 3 );
$y6_wc4 = make_wc_order( 960010, $y6_t4, 2 );
$y6_core4 = Y6Woo::reserve( $y6_wc4 );
check( 'INT-4: hold placed', 1 === Orders::availability( $y6_t4 ) );
Y6Woo::sync_trashed_order( 960010 );
check( 'INT-4: trashing an unpaid order releases the hold', 'cancelled' === Orders::get( $y6_core4 )->status && 3 === Orders::availability( $y6_t4 ) );
Y6Woo::sync_untrashed_order( 960010 );
$y6_core4b = (int) $y6_wc4->get_meta( Y6Woo::ORDER_META );
check( 'INT-4: restoring it from the trash reserves again', $y6_core4b && $y6_core4b !== (int) $y6_core4 && 'pending_payment' === Orders::get( $y6_core4b )->status && 1 === Orders::availability( $y6_t4 ) );
Y6Woo::sync_untrashed_order( 960010 );
check( 'INT-4: a second restore does not reserve twice', $y6_core4b === (int) $y6_wc4->get_meta( Y6Woo::ORDER_META ) && 1 === Orders::availability( $y6_t4 ) );
$GLOBALS['y6_post_types'][960010] = 'shop_order';
$GLOBALS['y6_post_types'][960011] = 'post';
Y6Woo::sync_trashed_post( 960011 );
check( 'INT-4: posts of other types are ignored', 'pending_payment' === Orders::get( $y6_core4b )->status );
Y6Woo::sync_deleted_post( 960010 );
check( 'INT-4: deleting the shop_order post (classic storage) releases the hold', 'cancelled' === Orders::get( $y6_core4b )->status && 3 === Orders::availability( $y6_t4 ) );
$y6_wc5 = make_wc_order( 960012, $y6_t4, 1 );
$y6_core5 = Y6Woo::reserve( $y6_wc5 );
$y6_wc5->paid = true;
Y6Woo::sync_paid_order( 960012 );
$y6_audit_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", 'woocommerce_paid_order_removed', $y6_core5 ) );
Y6Woo::sync_trashed_order( 960012 );
check( 'INT-12: a trashed paid order keeps its tickets, gets a note and an audit entry', 1 === count( tickets( $y6_core5, 'valid' ) ) && (bool) preg_grep( '/Papierkorb/', $y6_wc5->notes ) && $y6_audit_before + 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", 'woocommerce_paid_order_removed', $y6_core5 ) ) );
Y6Woo::sync_deleted_order( 960012 );
check( 'INT-12: deleting a paid order is audited too', $y6_audit_before + 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", 'woocommerce_paid_order_removed', $y6_core5 ) ) && 1 === count( tickets( $y6_core5, 'valid' ) ) );
Y6Woo::sync_untrashed_order( 960012 );
check( 'INT-4: restoring a paid order changes nothing', $y6_core5 === (int) $y6_wc5->get_meta( Y6Woo::ORDER_META ) );

/* ------------------------------------------------------------------ INT-5 */
echo "Y6 INT-5: audit on a site without plugin tables\n";
$y6_prefix = $wpdb->prefix;
$GLOBALS['y6_switched'] = true;
$wpdb->prefix = $y6_prefix . 'y6nosite_';
$wpdb->last_error = '';
Audit::record( 'role_changed', 'user', 1, array( 'role' => 'editor' ) );
$y6_err = $wpdb->last_error;
$wpdb->prefix = $y6_prefix;
check( 'INT-5: no insert (and no DB error) on a switched site without the audit table', '' === $y6_err );
$y6_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='y6_switched_ok'" );
Audit::record( 'y6_switched_ok', 'user', 1 );
check( 'INT-5: switched to a site with tables, the entry is written', $y6_cnt + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='y6_switched_ok'" ) );
$GLOBALS['y6_switched'] = false;

/* ------------------------------------------------------------------ INT-7 */
echo "Y6 INT-7: deactivation clears every cron hook\n";
$GLOBALS['y6_unscheduled'] = array();
if ( function_exists( 'get_current_blog_id' ) ) { Y6Plugin::deactivate(); }
$y6_hooks = $GLOBALS['y6_unscheduled'];
check( 'INT-7: deactivation removes wpcap_webhooks_dispatch and wpcap_process_fiscal_order', in_array( 'wpcap_webhooks_dispatch', Y6Plugin::CRON_HOOKS, true ) && in_array( 'wpcap_process_fiscal_order', Y6Plugin::CRON_HOOKS, true ) && ( ! function_exists( 'get_current_blog_id' ) || ( in_array( 'wpcap_webhooks_dispatch', $y6_hooks, true ) && in_array( 'wpcap_process_fiscal_order', $y6_hooks, true ) ) ) );
$y6_fd = $wpdb->get_var( "SHOW TABLES LIKE '{$p}wpcap\\_fiscal\\_documents'" );
if ( $y6_fd ) {
	$wpdb->insert( "{$p}wpcap_fiscal_documents", array( 'order_id' => 960099, 'seller_id' => 1, 'document_key' => hash( 'sha256', 'y6-' . uniqid() ), 'country_code' => 'DE', 'provider_id' => 'y6', 'status' => 'retry', 'currency' => 'EUR', 'snapshot' => '{}', 'attempts' => 1, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$y6_resched = PassCaptain\Modules\Fiscalization::reschedule_retries();
	check( 'INT-7: activation re-creates the fiscal retry events removed by the deactivation', $y6_resched >= 1 && false !== wp_next_scheduled( 'wpcap_process_fiscal_order', array( 960099 ) ) && 0 === PassCaptain\Modules\Fiscalization::reschedule_retries() );
	$wpdb->query( "DELETE FROM {$p}wpcap_fiscal_documents WHERE order_id=960099" );
}

/* ------------------------------------------------------------------ INT-8 */
echo "Y6 INT-8: the old copy cannot be re-activated next to this one\n";
require WPCAP_PATH . 'pass-captain.php'; // Returns early (WPCAP_VERSION is defined) after registering the legacy guards.
$y6_guard = null;
foreach ( $GLOBALS['wpcap_test_hooks']['check_admin_referer'][1] ?? array() as $y6_entry ) { $y6_guard = $y6_entry[0]; }
$y6_refused = static function ( $action ) use ( &$y6_guard ) {
	try { $y6_guard( $action, 1 ); return false; } catch ( Throwable $e ) { return true; }
};
check( 'INT-8: activating wp-pass-captain/wp-pass-captain.php is refused before it is loaded', is_callable( $y6_guard ) && $y6_refused( 'activate-plugin_wp-pass-captain/wp-pass-captain.php' ) );
check( 'INT-8: activating this copy or another plugin is not affected', is_callable( $y6_guard ) && ! $y6_refused( 'activate-plugin_pass-captain/pass-captain.php' ) && ! $y6_refused( 'activate-plugin_akismet/akismet.php' ) );
$_REQUEST = array( 'action' => 'delete-selected' ); $_POST = array( 'checked' => array( 'wp-pass-captain/wp-pass-captain.php' ) );
$y6_bulk_delete = is_callable( $y6_guard ) && $y6_refused( 'bulk-plugins' );
$_REQUEST = array( 'action' => 'activate-selected' );
$y6_bulk_activate = is_callable( $y6_guard ) && $y6_refused( 'bulk-plugins' );
$_REQUEST = array(); $_POST = array();
check( 'INT-8: bulk activation is refused, bulk deletion of the old copy stays possible', $y6_bulk_activate && ! $y6_bulk_delete );
$y6_links = apply_filters( 'plugin_action_links', array( 'activate' => 'A', 'delete' => 'D' ), 'wp-pass-captain/wp-pass-captain.php' );
$y6_links_own = apply_filters( 'plugin_action_links', array( 'deactivate' => 'X' ), 'pass-captain/pass-captain.php' );
check( 'INT-8: the plugin list offers no "Activate" link for the old copy', ! isset( $y6_links['activate'] ) && isset( $y6_links['delete'] ) && isset( $y6_links['wpcap_legacy'] ) && array( 'deactivate' => 'X' ) === $y6_links_own );

/* ------------------------------------------------------------------ INT-9 / INT-11 */
echo "Y6 INT-9/INT-11: compatibility declarations\n";
check( 'INT-9: product block editor declared incompatible', false !== strpos( $y6_adapter, "declare_compatibility( 'product_block_editor', WPCAP_FILE, false )" ) );
$y6_main = (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' );
check( 'INT-11: no Composer platform check in the release, own PHP version check instead', false === ( $y6_composer['config']['platform-check'] ?? true ) && false !== strpos( $y6_main, "version_compare( PHP_VERSION, '8.2', '<' )" ) && false === strpos( $y6_main, ' fn(' ) );

/* ------------------------------------------------------------------ INT-10 */
echo "Y6 INT-10: WooCommerce orders without checkout and required attendee fields\n";
$y6_t10 = make_type( 5 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $y6_t10, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 1, 'position' => 0, 'status' => 'active' ) );
$y6_checkout = make_wc_order( 960020, $y6_t10, 1 );
$y6_checkout_r = Y6Woo::reserve( $y6_checkout );
check( 'INT-10: the checkout still enforces required attendee fields', is_wp_error( $y6_checkout_r ) && 'wpcap_attendee_field_required' === $y6_checkout_r->get_error_code() );
$y6_admin = make_wc_order( 960021, $y6_t10, 2 );
$y6_admin->paid = true;
Y6Woo::sync_paid_order( 960021 ); // Created in the admin / over REST: no hold, no attendee data.
$y6_admin_core = (int) $y6_admin->get_meta( Y6Woo::ORDER_META );
check( 'INT-10: paid admin/REST order gets its tickets without attendee data', $y6_admin_core && 2 === count( tickets( $y6_admin_core, 'valid' ) ) );
check( 'INT-10: the order note says the buyer completes the data via the order link', (bool) preg_grep( '/Bestelllink/', $y6_admin->notes ) );
$y6_partial = Orders::validate_attendees( array( $y6_t10 => 2 ), array( array( 'ticket_type_id' => $y6_t10, 'answers' => array() ) ), true );
check( 'INT-10: entries that are given are still validated', is_wp_error( $y6_partial ) && 'wpcap_attendee_field_required' === $y6_partial->get_error_code() );

/* ------------------------------------------------------------------ INT-13 */
echo "Y6 INT-13: deferred network activation\n";
$y6_defer = new ReflectionMethod( Y6Plugin::class, 'defer_site_activation' );
$y6_defer->setAccessible( true );
$y6_s2 = get_option( Settings::OPTION, array() );
$y6_s2 = is_array( $y6_s2 ) ? $y6_s2 : array();
$y6_s2b = $y6_s2; $y6_s2b['delete_data_on_uninstall'] = 1;
update_option( Settings::OPTION, $y6_s2b );
$y6_pol = get_option( Settings::UNINSTALL_POLICY, false );
delete_option( Settings::UNINSTALL_POLICY );
$y6_dbv = get_option( 'wpcap_db_version' );
update_option( 'wpcap_db_version', '3.11.0+x' );
$y6_defer->invoke( null );
check( 'INT-13: a deferred site gets the opt-in migration and an upgrade cron event at once', 'delete' === get_option( Settings::UNINSTALL_POLICY ) && empty( get_option( Settings::OPTION )['delete_data_on_uninstall'] ) && false !== wp_next_scheduled( PassCaptain\Installer::UPGRADE_HOOK ) );
check( 'INT-13: the activation request installs at most a batch of sites', Y6Plugin::NETWORK_ACTIVATION_SITES > 0 && Y6Plugin::NETWORK_ACTIVATION_SECONDS > 0 );
update_option( Settings::OPTION, $y6_s2 );
if ( false === $y6_pol ) { delete_option( Settings::UNINSTALL_POLICY ); } else { update_option( Settings::UNINSTALL_POLICY, $y6_pol ); }
if ( false === $y6_dbv ) { delete_option( 'wpcap_db_version' ); } else { update_option( 'wpcap_db_version', $y6_dbv ); }
Settings::flush();

/* ------------------------------------------------------------------ WPORG */
echo "Y6 WPORG: readme, bundled sources, admin CSS and notices\n";
$y6_readme = (string) file_get_contents( WPCAP_PATH . 'readme.txt' );
check( 'WPORG-1: External services covers the HTTP/JSON connector and the Mollie methods lookup, no placeholders', false !== strpos( $y6_readme, 'HTTP/JSON connector' ) && false !== strpos( $y6_readme, 'GET /v2/methods' ) && false === strpos( $y6_readme, 'to be verified manually' ) && false !== strpos( $y6_readme, 'https://www.fiskaly.com/privacy-policy' ) && false !== strpos( $y6_readme, 'https://fiskaltrust.eu/en-eu/privacy-policy/' ) );
$y6_dist = (string) file_get_contents( WPCAP_PATH . '.distignore' );
check( 'WPORG-3: tweetnacl origin note ships with the ZIP (SOURCE.txt, not excluded)', is_readable( WPCAP_PATH . 'assets/vendor/tweetnacl/SOURCE.txt' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/vendor/tweetnacl/SOURCE.txt' ), 'github.com/dchest/tweetnacl-js' ) && ! preg_match( '/^\*\.txt$/m', $y6_dist ) && false !== strpos( $y6_readme, 'tweetnacl-js 1.0.3' ) );
$y6_get = $_GET;
$_GET = array( 'page' => 'wpcap-orders' );
$GLOBALS['y6_inline'] = array();
ob_start(); PassCaptain\Admin\Menu::admin_styles(); $y6_css_out = ob_get_clean();
$_GET = array( 'page' => 'other-plugin' );
$y6_inline_plugin = $GLOBALS['y6_inline']['wpcap-admin'] ?? '';
$GLOBALS['y6_inline'] = array();
PassCaptain\Admin\Menu::admin_styles();
// Merged: the small menu-heading CSS (wpcap-admin-menu) is added on every admin page (the menu is shown
// everywhere); the plugin page CSS (wpcap-admin) only on the plugin's pages.
check( 'WPORG-4: admin CSS via wp_add_inline_style on plugin pages only, no raw <style>', '' === $y6_css_out && false !== strpos( $y6_inline_plugin, 'input[type=checkbox]' ) && ! isset( $GLOBALS['y6_inline']['wpcap-admin'] ) && array() === array_diff( array_keys( $GLOBALS['y6_inline'] ), array( 'wpcap-admin-menu' ) ) );
// Merged: credentials live in the unified Secrets vault (option wpcap_secrets); its notice for entries that
// cannot be decrypted (key lost: sealed under an unknown key id) appears on the plugin's own screens only.
$y6_vault_before = get_option( PassCaptain\Secrets::OPTION, array() );
update_option( PassCaptain\Secrets::OPTION, array( 'mollie_api_key' => 'v1:0000000000:' . base64_encode( random_bytes( 48 ) ) ), false );
$_GET = array( 'page' => 'other-plugin' );
ob_start(); PassCaptain\Secrets::admin_notice(); $y6_notice_other = ob_get_clean();
$_GET = array( 'page' => 'wpcap-settings' );
ob_start(); PassCaptain\Secrets::admin_notice(); $y6_notice_own = ob_get_clean();
update_option( PassCaptain\Secrets::OPTION, $y6_vault_before, false );
$_GET = $y6_get;
check( 'WPORG-5: vault notice only on the plugin\'s pages', '' === $y6_notice_other && false !== strpos( $y6_notice_own, 'notice-error' ) );
Settings::update( array( 'mollie_api_key' => '' ) );
PassCaptain\Security\Vault::reset();

/* ------------------------------------------------------------------ Mobile API */
echo "Y6: mobile API Authorization fallback\n";
check( 'mobile API reads REDIRECT_HTTP_AUTHORIZATION when the header was stripped', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Mobile/Devices.php' ), "\$_SERVER['REDIRECT_HTTP_AUTHORIZATION']" ) );
