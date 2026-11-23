<?php
/**
 * A162: multisite network administration – network-wide report for a period (orders, tickets, gross,
 * refunds, net per site and currency with totals), upcoming dates per site, paged overview, and the
 * cross-site isolation of these figures. Runs after zzzzz_w9_access.php (same blog-switch stubs).
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Ops\Network;

echo "A162: multisite network report\n";
global $wpdb;
$a162_base = $wpdb->prefix;
$GLOBALS['w9_prefixes'] = array( 1 => $a162_base, 2 => $a162_base . 'a162s2_', 3 => $a162_base . 'a162none_' );
$GLOBALS['w9_blog_stack'] = array();
$GLOBALS['w9_blog'] = 1;
if ( ! function_exists( 'switch_to_blog' ) ) {
	// Same stubs as zzzzz_w9_access.php (whichever module runs first defines them).
	function switch_to_blog( $id ) { global $wpdb; $GLOBALS['w9_blog_stack'][] = $GLOBALS['w9_blog']; $GLOBALS['w9_blog'] = (int) $id; $GLOBALS['wpcap_test_blog_id'] = (int) $id; $wpdb->prefix = $GLOBALS['w9_prefixes'][ (int) $id ]; return true; }
	function restore_current_blog() { global $wpdb; $GLOBALS['w9_blog'] = array_pop( $GLOBALS['w9_blog_stack'] ) ?? 1; $GLOBALS['wpcap_test_blog_id'] = $GLOBALS['w9_blog']; $wpdb->prefix = $GLOBALS['w9_prefixes'][ $GLOBALS['w9_blog'] ]; return true; }
}
if ( ! function_exists( 'get_current_blog_id' ) ) { function get_current_blog_id() { return (int) ( $GLOBALS['wpcap_test_blog_id'] ?? $GLOBALS['w9_blog'] ?? 1 ); } }
if ( ! function_exists( 'network_admin_url' ) ) { function network_admin_url( $path = '' ) { return 'https://example.test/wp-admin/network/' . ltrim( (string) $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $url, $action = -1 ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=test'; } }
if ( ! function_exists( 'get_admin_url' ) ) { function get_admin_url( $blog_id = null, $path = '' ) { return 'https://example.test/' . (int) $blog_id . '/wp-admin/' . $path; } }
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

$a162_today = gmdate( 'Y-m-d', \PassCaptain\Clock::now() );
$a162_site1_before = Network::site_period( 1, $a162_today, $a162_today );

// Site 2: own schema and data (paid EUR order with 2 tickets, a refund, a comp order, an old order, a CHF order).
$wpdb->prefix = $GLOBALS['w9_prefixes'][2];
wpcap_test_install();
$a162_p = $wpdb->prefix;
$a162_now = \PassCaptain\Clock::mysql();
$a162_old = gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - 60 * DAY_IN_SECONDS );
$wpdb->insert( "{$a162_p}wpcap_events", array( 'name' => 'A162 Site 2', 'slug' => 'a162-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a162_now, 'updated_at' => $a162_now ) );
$a162_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$a162_p}wpcap_occurrences", array( 'event_id' => $a162_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + 5 * DAY_IN_SECONDS ), 'timezone' => 'UTC', 'location' => 'X', 'capacity' => 0, 'status' => 'scheduled' ) );
$wpdb->insert( "{$a162_p}wpcap_occurrences", array( 'event_id' => $a162_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + 90 * DAY_IN_SECONDS ), 'timezone' => 'UTC', 'location' => 'X', 'capacity' => 0, 'status' => 'scheduled' ) );
function a162_order( $currency, $total, $qty, $created, $provider = 'stripe', $refunded = 0 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$key = uniqid( 'a162', true );
	$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => md5( $key ), 'idempotency_key' => hash( 'sha256', $key ), 'customer_email' => 'a162@example.com', 'status' => 'complete', 'payment_status' => 'paid', 'payment_provider' => $provider, 'currency' => $currency, 'total_minor' => $total, 'subtotal_minor' => $total, 'refunded_minor' => $refunded, 'price_snapshot' => '{}', 'created_at' => $created, 'updated_at' => $created ) );
	$id = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $id, 'ticket_type_id' => 1, 'quantity' => $qty, 'unit_price_minor' => $qty ? intdiv( $total, $qty ) : 0, 'line_total_minor' => $total ) );
	return $id;
}
$a162_paid = a162_order( 'EUR', 5000, 2, $a162_now, 'stripe', 1000 );
$wpdb->insert( "{$a162_p}wpcap_refunds", array( 'order_id' => $a162_paid, 'idempotency_key' => hash( 'sha256', 'a162-r' ), 'ticket_ids' => '[]', 'amount_minor' => 1000, 'currency' => 'EUR', 'reason' => 'A162', 'status' => 'completed', 'created_at' => $a162_now, 'updated_at' => $a162_now ) );
a162_order( 'EUR', 0, 3, $a162_now, 'comp' );
a162_order( 'EUR', 7000, 1, $a162_old );
a162_order( 'CHF', 2300, 1, $a162_now, 'manual' );
$wpdb->prefix = $a162_base;

$a162_s2 = Network::site_period( 2, $a162_today, $a162_today );
check( 'A162: period figures of a site per currency (comps and older orders excluded, refunds netted)', $a162_s2['installed'] && array( 'orders' => 1, 'tickets' => 2, 'gross' => 5000, 'refunded' => 1000, 'net' => 4000 ) === ( $a162_s2['currencies']['EUR'] ?? null ) && array( 'orders' => 1, 'tickets' => 1, 'gross' => 2300, 'refunded' => 0, 'net' => 2300 ) === ( $a162_s2['currencies']['CHF'] ?? null ) );
$a162_s2_long = Network::site_period( 2, gmdate( 'Y-m-d', \PassCaptain\Clock::now() - 70 * DAY_IN_SECONDS ), $a162_today );
check( 'A162: a longer period includes the older order', 2 === $a162_s2_long['currencies']['EUR']['orders'] && 12000 === $a162_s2_long['currencies']['EUR']['gross'] );
check( 'A162: site 1 figures are not touched by site 2 data (isolation)', $a162_site1_before === Network::site_period( 1, $a162_today, $a162_today ) && $GLOBALS['w9_prefixes'][1] === $wpdb->prefix && 1 === get_current_blog_id() );
$a162_s3 = Network::site_period( 3, $a162_today, $a162_today );
check( 'A162: a site without plugin tables is reported as not installed, not read', ! $a162_s3['installed'] && array() === $a162_s3['currencies'] );

$a162_report = Network::period_report( $a162_today, $a162_today, array( 1, 2, 3 ) );
$a162_sum_ok = true;
foreach ( $a162_report['totals'] as $cur => $f ) {
	foreach ( $f as $k => $v ) {
		$expected = (int) ( $a162_report['sites'][0]['currencies'][ $cur ][ $k ] ?? 0 ) + (int) ( $a162_report['sites'][1]['currencies'][ $cur ][ $k ] ?? 0 );
		if ( $expected !== (int) $v ) { $a162_sum_ok = false; }
	}
}
check( 'A162: network totals per currency are the sum of the sites (currencies never mixed)', $a162_sum_ok && isset( $a162_report['totals']['CHF'], $a162_report['totals']['EUR'] ) && 3 === count( $a162_report['sites'] ) );
$a162_rows = Network::period_csv_rows( $a162_report );
$a162_total_rows = array_values( array_filter( $a162_rows, static fn( $r ) => 'total' === $r[0] ) );
check( 'A162: period CSV has a row per site and currency plus total rows', 'site_id' === $a162_rows[0][0] && 'net' === end( $a162_rows[0] ) && in_array( array( 2, 'Testsite', 'yes', $a162_today, $a162_today, 'EUR', 1, 2, '50.00', '10.00', '40.00' ), $a162_rows, true ) && count( $a162_total_rows ) === count( $a162_report['totals'] ) );

list( $a162_f, $a162_t ) = Network::period( '2020-01-01', '2026-11-20' );
list( $a162_sf, $a162_st ) = Network::period( '2026-11-20', '2026-11-01' );
list( $a162_df ) = Network::period( 'kaputt', '2026-11-20' );
check( 'A162: period input is validated (at most 366 days, swapped dates, default 30 days)', '2025-11-20' === $a162_f && '2026-11-20' === $a162_t && '2026-11-01' === $a162_sf && '2026-11-20' === $a162_st && '2026-10-22' === $a162_df );

$a162_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s", 'network_report_exported' ) );
$a162_ids = static fn() => array( 1, 2 );
add_filter( 'wpcap_network_site_ids', $a162_ids );
$a162_csv = Network::report( $a162_today, $a162_today );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_network_options' );
$a162_denied = Network::report( $a162_today, $a162_today );
$GLOBALS['wpcap_test_denied_caps'] = array();
$a162_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s", 'network_report_exported' ) );
check( 'A162: period CSV download needs manage_network_options and is audited', is_array( $a162_csv ) && false !== strpos( $a162_csv['csv'], 'refunded' ) && false !== strpos( $a162_csv['filename'], str_replace( '-', '', $a162_today ) ) && is_wp_error( $a162_denied ) && $a162_before + 1 === $a162_after );
$a162_overview = Network::overview( array( 2 ) );
check( 'A162: the overview counts upcoming dates (next 30 days) per site', 1 === $a162_overview[0]['upcoming'] );
remove_filter( 'wpcap_network_site_ids', $a162_ids );

// Paging: 45 sites -> 3 pages of 20 (the health check runs only for the listed sites).
$a162_many = static fn() => range( 1, 45 );
add_filter( 'wpcap_network_site_ids', $a162_many );
for ( $i = 4; $i <= 45; $i++ ) { $GLOBALS['w9_prefixes'][ $i ] = $a162_base . 'a162none' . $i . '_'; }
$a162_page = Network::page_of_sites( 3 );
$a162_clamped = Network::page_of_sites( 99 );
check( 'A162: overview pages of 20 sites, out-of-range page clamped', 3 === $a162_page['pages'] && 45 === $a162_page['total'] && range( 41, 45 ) === $a162_page['ids'] && 3 === $a162_clamped['page'] && 20 === count( Network::page_of_sites( 1 )['ids'] ) );
$a162_html = Network::render_html( array( 'paged' => 1, 'from' => $a162_today, 'to' => $a162_today ) );
check( 'A162: network page shows upcoming dates, the period report with totals and page links', false !== strpos( $a162_html, 'Termine (nächste 30 Tage)' ) && false !== strpos( $a162_html, 'Netzwerkbericht für einen Zeitraum' ) && false !== strpos( $a162_html, 'Summe (diese Seite)' ) && false !== strpos( $a162_html, 'aria-current="page"' ) && false !== strpos( $a162_html, 'paged=3' ) && 1 === substr_count( $a162_html, '<h1>' ) );
remove_filter( 'wpcap_network_site_ids', $a162_many );
unset( $GLOBALS['wpcap_test_hooks']['wpcap_network_site_ids'] );
check( 'A162: prefix and blog restored after the network page', $a162_base === $wpdb->prefix && 1 === get_current_blog_id() );

foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $GLOBALS['w9_prefixes'][2] . 'wpcap_', '_%\\' ) . '%' ) ) as $a162_table ) { $wpdb->query( "DROP TABLE IF EXISTS `$a162_table`" ); }
$wpdb->prefix = $a162_base;
