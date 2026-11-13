<?php
/**
 * A182: two sites side by side (multisite-like: prefix "<p>" and "<p>2_", as wp_ and wp_2_), same
 * ids on both. The main flows must never read or write the other site's tables or caches.
 * Switching sites works like switch_to_blog(): $wpdb->prefix, per-site options and transients, and
 * the switch_blog action – no plugin cache is flushed by the test.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Installer;
use PassCaptain\Settings;

if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }

global $wpdb;
$a182_a = $wpdb->prefix;
$a182_b = $wpdb->prefix . '2_';

echo "A182: two sites with different table prefixes, no cross-talk\n";
// Site B: same schema (CREATE TABLE … LIKE), empty tables, its own options.
foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a182_b, '_%\\' ) . '%' ) ) as $a182_t ) { $wpdb->query( "DROP TABLE `$a182_t`" ); }
$a182_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a182_a . 'wpcap_', '_%\\' ) . '%' ) );
foreach ( $a182_tables as $a182_t ) {
	$wpdb->query( 'CREATE TABLE `' . $a182_b . substr( $a182_t, strlen( $a182_a ) ) . "` LIKE `$a182_t`" );
}
$a182_site = array(
	$a182_a => array( 'options' => null, 'transients' => null ),
	$a182_b => array(
		'options'    => array_intersect_key( $GLOBALS['wpcap_test_options'], array_flip( array( 'admin_email', 'woocommerce_hold_stock_minutes', 'wpcap_db_version', 'wpcap_db_version_max', 'wpcap_schema_fingerprint', 'wpcap_capacity_counters' ) ) ),
		'transients' => array(),
	),
);
$a182_switch = static function ( $to ) use ( &$a182_site ) {
	global $wpdb;
	$from = $wpdb->prefix;
	if ( $from === $to ) { return; }
	$a182_site[ $from ] = array( 'options' => $GLOBALS['wpcap_test_options'], 'transients' => $GLOBALS['wpcap_test_transients'] );
	$GLOBALS['wpcap_test_options'] = $a182_site[ $to ]['options'];
	$GLOBALS['wpcap_test_transients'] = $a182_site[ $to ]['transients'];
	$wpdb->prefix = $to;
	do_action( 'switch_blog', $to === $GLOBALS['a182_b'] ? 2 : 1, $to === $GLOBALS['a182_b'] ? 1 : 2, 'switch' );
};
$GLOBALS['a182_b'] = $a182_b;
$a182_count = static function ( $prefix, $table, $where = '1=1' ) use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}wpcap_{$table}` WHERE $where" ); };
check( 'site B has the same ' . count( $a182_tables ) . ' plugin tables, all empty', count( $a182_tables ) >= 50 && count( $a182_tables ) === count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a182_b . 'wpcap_', '_%\\' ) . '%' ) ) ) && 0 === $a182_count( $a182_b, 'orders' ) );

// Site A: a ticket type with capacity 5. Site B: the same event/occurrence/type ids, capacity 3.
$a182_type = make_type( 5, 10, 1000 );
$a182_row = $wpdb->get_row( "SELECT tt.ticket_type_id, tt.occurrence_id, o.event_id FROM {$a182_a}wpcap_ticket_types tt JOIN {$a182_a}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a182_type" );
$a182_switch( $a182_b );
$a182_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$a182_b}wpcap_events", array( 'event_id' => $a182_row->event_id, 'name' => 'Site B', 'slug' => 'site-b', 'description' => '', 'status' => 'published', 'created_at' => $a182_now, 'updated_at' => $a182_now ) );
$wpdb->insert( "{$a182_b}wpcap_occurrences", array( 'occurrence_id' => $a182_row->occurrence_id, 'event_id' => $a182_row->event_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 1800 ), 'timezone' => 'Europe/Vienna', 'location' => 'Graz', 'capacity' => 0, 'status' => 'scheduled' ) );
$wpdb->insert( "{$a182_b}wpcap_ticket_types", array( 'ticket_type_id' => $a182_type, 'occurrence_id' => $a182_row->occurrence_id, 'name' => 'B', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 3, 'max_per_order' => 10, 'status' => 'active' ) );
$wpdb->query( "UPDATE {$a182_b}wpcap_occurrences SET venue_country='DE' WHERE occurrence_id=" . (int) $a182_row->occurrence_id );
$wpdb->query( "UPDATE {$a182_a}wpcap_occurrences SET venue_country='AT' WHERE occurrence_id=" . (int) $a182_row->occurrence_id );
// Read on A (memoized in this request), then on B without any write in between.
$a182_switch( $a182_a );
check( 'site A: availability 5, venue country AT', 5 === Orders::availability( $a182_type ) && 'AT' === PassCaptain\Tax\Profiles::venue_country( (int) $a182_row->occurrence_id ) && PassCaptain\OrderLines::ready() );
$a182_switch( $a182_b );
check( 'site B: same ticket type id, its own availability (3, not site A\'s memoized 5)', 3 === Orders::availability( $a182_type ) );
check( 'site B: same occurrence id, its own venue country (tax cache per site)', 'DE' === PassCaptain\Tax\Profiles::venue_country( (int) $a182_row->occurrence_id ) );
$a182_lock_b = Installer::lock_name();
$a182_version_b = Installer::version();
$a182_switch( $a182_a );
check( 'per-site upgrade lock and schema version (cached per prefix)', $a182_lock_b !== Installer::lock_name() && $a182_version_b !== Installer::version() && false !== strpos( $a182_version_b, '+' ) );
$a182_switch( $a182_b );

// Checkout and payment on site B.
$a182_order = Orders::create( array( 'email' => 'site-b@example.com', 'items' => array( array( 'ticket_type_id' => $a182_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a182-b-1' ) );
check( 'site B: order created at site B\'s price', ! is_wp_error( $a182_order ) && 4000 === (int) $a182_order['total_minor'] && 1 === Orders::availability( $a182_type ) );
$a182_paid = Orders::confirm_manual_payment( $a182_order['order_id'] );
$a182_b_tickets = Orders::tickets_for_order( $a182_order['order_id'] );
check( 'site B: payment issues 2 tickets in site B\'s tables', true === $a182_paid && 2 === count( $a182_b_tickets ) && 2 === $a182_count( $a182_b, 'tickets' ) );
$a182_codes = ticket_code_for( $a182_order['order_id'] );
check( 'site B: ticket code checks in on site B', 2 === count( $a182_codes ) && ! is_wp_error( PassCaptain\Checkin::perform( $a182_codes[0], array( 'idempotency_key' => 'a182-b-in' ) ) ) && 1 === $a182_count( $a182_b, 'checkins' ) );
PassCaptain\Audit::record( 'a182_probe', 'system', 0, array() );
Settings::update( array( 'hold_minutes' => 17 ) );
check( 'site B: setting stored for site B', 17 === (int) Settings::get( 'hold_minutes' ) );
PassCaptain\Jobs::enqueue( 'a182-b-job', 'a182_probe', array( 'site' => 'B' ) );

// Back on site A: nothing of site B is visible or changed.
$a182_checkins_a = $a182_count( $a182_a, 'checkins' );
$a182_switch( $a182_a );
check( 'site A: availability still 5', 5 === Orders::availability( $a182_type ) );
check( 'site A: site B\'s order id is not site B\'s order here', ( static function ( $o ) { return ! $o || 'site-b@example.com' !== $o->customer_email; } )( Orders::get( $a182_order['order_id'] ) ) );
check( 'site A: site B\'s ticket code is unknown', is_wp_error( PassCaptain\Checkin::perform( $a182_codes[1], array( 'idempotency_key' => 'a182-a-in' ) ) ) && $a182_checkins_a === $a182_count( $a182_a, 'checkins' ) );
check( 'site A: no audit entry, setting and counters of site B', 0 === $a182_count( $a182_a, 'audit_logs', "action='a182_probe'" ) && 17 !== (int) Settings::get( 'hold_minutes' ) && ! PassCaptain\CapacityCounters::drift() );
$a182_ran = array();
$a182_job = static function ( $handled, $type, $payload ) use ( &$a182_ran ) {
	global $wpdb;
	if ( 'a182_probe' !== $type ) { return $handled; }
	$a182_ran[] = $wpdb->prefix . ':' . ( $payload['site'] ?? '' );
	return true;
};
add_filter( 'wpcap_process_job', $a182_job, 10, 3 );
PassCaptain\Jobs::process_due( 200 );
check( 'site A: the job queue does not run site B\'s jobs', ! $a182_ran && 1 === $a182_count( $a182_b, 'jobs', "job_type='a182_probe' AND status='queued'" ) );

// Site A sells and refunds; site B stays as it is.
$a182_order_a = Orders::create( array( 'email' => 'site-a@example.com', 'items' => array( array( 'ticket_type_id' => $a182_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a182-b-1' ) );
check( 'site A: same idempotency key as on site B creates a new order on site A', ! is_wp_error( $a182_order_a ) && empty( $a182_order_a['reused'] ) && 1000 === (int) $a182_order_a['total_minor'] );
Orders::confirm_manual_payment( $a182_order_a['order_id'] );
Orders::mark_refunded( $a182_order_a['order_id'] );
check( 'site A: refund frees site A\'s capacity only', 5 === Orders::availability( $a182_type ) );

$a182_switch( $a182_b );
check( 'site B: unchanged after site A\'s sale and refund (availability 1, 2 valid/checked-in tickets, order paid)', 1 === Orders::availability( $a182_type ) && 2 === $a182_count( $a182_b, 'tickets', "status IN ('valid','checked_in')" ) && 'paid' === Orders::get( $a182_order['order_id'] )->payment_status && 1 === $a182_count( $a182_b, 'orders' ) );
PassCaptain\Jobs::process_due( 20 );
check( 'site B: its own job queue runs its job', array( $a182_b . ':B' ) === $a182_ran );
check( 'site B: capacity counters consistent', ! PassCaptain\CapacityCounters::drift() );
check( 'site B: tables dropped with the site are only site B\'s', ( static function ( $list ) use ( $a182_b, $a182_tables ) { return count( $list ) === count( $a182_tables ) && ! array_filter( $list, static fn( $t ) => 0 !== strpos( $t, $a182_b . 'wpcap_' ) ); } )( PassCaptain\Plugin::drop_site_tables( array(), 0 ) ) );
$a182_switch( $a182_a );
check( 'site A: tables of site A never include site B\'s (prefix wp_ vs. wp_2_)', ! array_filter( PassCaptain\Plugin::drop_site_tables( array(), 0 ), static fn( $t ) => 0 === strpos( $t, $a182_b ) ) );
remove_filter( 'wpcap_process_job', $a182_job, 10 );

// Schema readiness caches (OrderLines, Tax\Profiles) belong to one site: site B not yet upgraded.
$a182_ready_a = PassCaptain\OrderLines::ready() && PassCaptain\Tax\Profiles::ready();
$a182_site[ $a182_b ]['options']['wpcap_db_version'] = '3.8.0';
$a182_switch( $a182_b );
$a182_ready_b = PassCaptain\OrderLines::ready() || PassCaptain\Tax\Profiles::ready();
$a182_switch( $a182_a );
check( 'schema readiness is evaluated per site (A current, B still on 3.8.0)', $a182_ready_a && ! $a182_ready_b && PassCaptain\OrderLines::ready() && PassCaptain\Tax\Profiles::ready() );

foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a182_b, '_%\\' ) . '%' ) ) as $a182_t ) { $wpdb->query( "DROP TABLE `$a182_t`" ); }
