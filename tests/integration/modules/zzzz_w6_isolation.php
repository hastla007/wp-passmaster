<?php
/**
 * Table prefix isolation (multisite sites, several installs in one database) and the static guard
 * against direct WooCommerce table access (W6).
 *
 * Two sites get their own schema (prefixes w6sa_ / w6sb_, like wp_2_ / wp_3_ on multisite). Key flows
 * run on site A, then on site B with colliding auto-increment ids. Site B must neither see nor change
 * anything of site A: no rows, no cross-site check-in, webhook, queue run, expiry, privacy export or
 * cached lookups (switch_to_blog() only changes $wpdb->prefix inside one PHP request).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Checkin;
use PassCaptain\Refunds;
use PassCaptain\Jobs;
use PassCaptain\Communications;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Installer;

global $wpdb;
$p = $wpdb->prefix;
$w6i_home = $p;

/* ------------------------------------------------------------------------------------------ */
echo "W6 isolation: no direct WooCommerce table access (static)\n";
$w6i_forbidden = array(
	'HPOS/legacy order tables' => '/\b(wc_orders|wc_orders_meta|wc_order_addresses|wc_order_operational_data|wc_order_stats|wc_order_product_lookup|wc_order_tax_lookup|wc_order_coupon_lookup|woocommerce_order_items|woocommerce_order_itemmeta)\b/',
	'WooCommerce lookup/session tables' => '/\b(wc_product_meta_lookup|wc_customer_lookup|wc_category_lookup|woocommerce_sessions|wc_reserved_stock|woocommerce_api_keys)\b/',
	'posts/postmeta (shop_order storage)' => '/\$wpdb->(posts|postmeta)\b|\{\$wpdb->(posts|postmeta)\}/',
	'shop_order post type in SQL' => '/(SELECT|UPDATE|DELETE|INSERT)[^;]*shop_order/i',
	'hard-coded table prefix' => '/[\'"` ]wp_wpcap_/',
	'network-wide prefix for plugin tables' => '/base_prefix\s*\.\s*[\'"]wpcap_|\{\$wpdb->base_prefix\}wpcap_/',
);
$w6i_hits = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
$files = 0;
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	++$files;
	foreach ( file( $file->getPathname() ) as $no => $line ) {
		if ( preg_match( '#^\s*(\*|//)#', $line ) ) { continue; } // Comments may name tables.
		foreach ( $w6i_forbidden as $label => $re ) {
			if ( preg_match( $re, $line ) ) { $w6i_hits[] = "$label: " . substr( $file->getPathname(), strlen( WPCAP_PATH ) ) . ':' . ( $no + 1 ); }
		}
	}
}
check( "src/ ($files files) never queries WooCommerce/WordPress order tables directly, never hard-codes or shares the table prefix", ! $w6i_hits );
foreach ( array_slice( $w6i_hits, 0, 5 ) as $h ) { echo "         $h\n"; }
$w6i_wc = (string) file_get_contents( WPCAP_PATH . 'src/Integrations/WooCommerce/OrderAdapter.php' );
check( 'WooCommerce adapter declares HPOS compatibility and reads orders through wc_get_order()', false !== strpos( $w6i_wc, 'wc_get_order(' ) && false !== strpos( $w6i_wc, "declare_compatibility( 'custom_order_tables'" ) );

/* ------------------------------------------------------------------------------------------ */
echo "W6 isolation: two sites with their own table prefix\n";
/** Switches the site like switch_to_blog() does for the plugin: only $wpdb->prefix changes. */
function w6i_site( $prefix ) {
	global $wpdb;
	$wpdb->prefix = $prefix;
	Installer::flush_version_cache();
	$GLOBALS['wpcap_test_transients'] = array();
}
function w6i_drop( $prefix ) {
	global $wpdb;
	foreach ( $wpdb->get_col( "SHOW TABLES LIKE '" . str_replace( '_', '\\_', $prefix ) . "%'" ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }
}
/** Row count and content hash of every plugin table of a site. */
function w6i_state( $prefix ) {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->get_col( "SHOW TABLES LIKE '" . str_replace( '_', '\\_', $prefix ) . "wpcap\\_%'" ) as $t ) {
		$rows = $wpdb->get_results( "SELECT * FROM `$t`", ARRAY_A );
		$out[ substr( $t, strlen( $prefix ) ) ] = count( $rows ) . ':' . md5( json_encode( $rows ) );
	}
	return $out;
}
function w6i_rows( $prefix ) { return array_sum( array_map( static fn( $s ) => (int) strtok( $s, ':' ), w6i_state( $prefix ) ) ); }
/** Key flows on the current site; returns what the cross-site checks need. */
function w6i_flows( $tag ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$free = make_type( 10, 10, 0 );
	$fo = Orders::create( array( 'email' => "w6i-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $free, 'quantity' => 2 ) ), 'idempotency_key' => "w6i-free-$tag" ), array( 'skip_rate_limit' => true ) );
	$codes = ticket_code_for( $fo['order_id'] );
	$checked = Checkin::perform( $codes[0], array( 'idempotency_key' => "w6i-ci-$tag" ) );
	$paid_type = make_type( 10, 10, 1500 );
	$po = Orders::create( array( 'email' => "w6i-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 2 ) ), 'idempotency_key' => "w6i-paid-$tag" ), array( 'skip_rate_limit' => true ) );
	Orders::confirm_manual_payment( $po['order_id'] );
	$refund = Refunds::request( $po['order_id'], array( (int) tickets( $po['order_id'] )[0]->ticket_id ), 'w6', "w6i-ref-$tag" );
	$pending = Orders::create( array( 'email' => "w6i-$tag@example.com", 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 1 ) ), 'idempotency_key' => "w6i-pend-$tag" ), array( 'skip_rate_limit' => true ) );
	Jobs::enqueue( "w6i-job-$tag", 'w6i_job', array( 'site' => $tag ), '2000-01-01 00:00:00' );
	Communications::enqueue( "w6i-msg-$tag", "w6i-msg-$tag@example.com", 'w6i', array( 'subject' => 'S', 'body' => 'B' ), '2099-01-01 00:00:00' );
	return array( 'free' => $fo, 'codes' => $codes, 'checked' => $checked, 'paid' => $po, 'refund' => $refund, 'pending' => $pending, 'type' => $paid_type, 'occurrence' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$paid_type" ) );
}

$w6i_a = 'w6sa_';
$w6i_b = 'w6sb_';
w6i_drop( $w6i_a );
w6i_drop( $w6i_b );
w6i_site( $w6i_a );
wpcap_test_install();
w6i_site( $w6i_b );
wpcap_test_install();
check( 'both sites have their own complete schema and their own upgrade lock', count( w6i_state( $w6i_a ) ) === count( w6i_state( $w6i_b ) ) && count( w6i_state( $w6i_a ) ) > 50 && 0 === w6i_rows( $w6i_b ) && ( static function () use ( $w6i_a, $w6i_b ) { w6i_site( $w6i_a ); $la = Installer::lock_name(); w6i_site( $w6i_b ); return $la !== Installer::lock_name(); } )() );

w6i_site( $w6i_a );
$A = w6i_flows( 'a' );
check( 'site A flows work (free order, check-in, paid order, refund, pending order, queue items)', 2 === count( $A['codes'] ) && 'checked_in' === ( $A['checked']['status'] ?? '' ) && 'completed' === ( $A['refund']['status'] ?? '' ) && ! is_wp_error( $A['pending'] ) );
check( 'site B has no rows after the site A flows', 0 === w6i_rows( $w6i_b ) );
$state_a = w6i_state( $w6i_a );

w6i_site( $w6i_b );
$B = w6i_flows( 'b' );
check( 'site B flows work with the same auto-increment ids as site A', 'completed' === ( $B['refund']['status'] ?? '' ) && (int) $B['paid']['order_id'] === (int) $A['paid']['order_id'] && "w6i-b@example.com" === Orders::get( $A['paid']['order_id'] )->customer_email );
check( 'site A data unchanged by everything site B did', $state_a === w6i_state( $w6i_a ) );

// Cross-site attempts from site B.
$cross_ci = Checkin::perform( $A['codes'][1], array( 'idempotency_key' => 'w6i-cross' ) );
check( "site A's ticket code is unknown on site B", is_wp_error( $cross_ci ) && 'unknown' === ( $cross_ci->get_error_data()['result'] ?? '' ) );
$cross_hook = Webhooks::process( 'manual', array( 'event_id' => 'w6i-cross-paid', 'type' => 'paid', 'order_public_id' => $A['pending']['public_id'], 'amount_minor' => 1500, 'currency' => 'EUR' ) );
check( "a payment webhook for site A's order arriving at site B changes nothing", 'ignored' === $cross_hook );
$wpdb->query( "UPDATE {$w6i_a}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE order_id=" . (int) $A['pending']['order_id'] );
$state_a = w6i_state( $w6i_a );
$handled = array();
$h = static function ( $done, $type, $payload ) use ( &$handled ) { if ( 'w6i_job' === $type ) { $handled[] = $payload['site']; return true; } return $done; };
add_filter( 'wp_pass_captain_process_job_v1', $h, 10, 3 );
Jobs::process_due( 50 );
Orders::expire_holds();
Communications::process_due();
remove_filter( 'wp_pass_captain_process_job_v1', $h, 10 );
check( "site B's cron (jobs, expiry, mail queue) only processes site B", array( 'b' ) === $handled && $state_a === w6i_state( $w6i_a ) );
$export = PassCaptain\Privacy::export( 'w6i-a@example.com' );
check( "privacy export on site B finds nothing of site A's buyer", is_array( $export ) && empty( $export['data'] ) );

// Per-request caches are keyed by site (switch_to_blog() inside one request).
w6i_site( $w6i_a );
$wpdb->insert( "{$w6i_a}wpcap_series_occurrences", array( 'series_id' => 777, 'occurrence_id' => 99999, 'starts_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$series_a = PassCaptain\Series\Pricing::series_of( 99999 );
w6i_site( $w6i_b );
$series_b = PassCaptain\Series\Pricing::series_of( 99999 );
check( "series lookup cache is per site (same occurrence id on another site is not a series date: $series_a / $series_b)", 777 === $series_a && 0 === $series_b );
w6i_site( $w6i_a );
$jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$w6i_a}wpcap_jobs WHERE job_type='w6i_job' AND status='queued'" );
$handled = array();
add_filter( 'wp_pass_captain_process_job_v1', $h, 10, 3 );
Jobs::process_due( 50 );
remove_filter( 'wp_pass_captain_process_job_v1', $h, 10 );
check( "site A's own cron then processes site A's job", 1 === $jobs && array( 'a' ) === $handled );

w6i_site( $w6i_home );
w6i_drop( $w6i_a );
w6i_drop( $w6i_b );
