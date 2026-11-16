<?php
/**
 * A437: the webhook log cleanup runs hourly (not with every 5-minute job run) and deletes old final
 * deliveries in batches of 1,000 on the index cleanup (status, updated_at) within a time budget.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Webhooks as WebhooksModule;
use PassCaptain\Webhooks\Dispatcher;

global $wpdb;
$p = $wpdb->prefix;

echo "A437: webhook log cleanup\n";
$a437_cols = array_map( static fn( $r ) => $r->Column_name, (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_webhook_deliveries WHERE Key_name='cleanup'" ) );
check( 'schema: webhook_deliveries KEY cleanup (status, updated_at)', array( 'status', 'updated_at' ) === $a437_cols );

// Registration: hourly cleanup cron only.
$a437_hooks = $GLOBALS['wpcap_test_hooks'] ?? array();
unset( $GLOBALS['wpcap_test_hooks']['wpcap_process_jobs'], $GLOBALS['wpcap_test_hooks']['wpcap_cleanup_reservations'] );
WebhooksModule::register();
$a437_on = static fn( $hook ) => (bool) array_filter( array_merge( ...array_values( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array( array() ) ) ), static fn( $e ) => array( Dispatcher::class, 'purge_old' ) === $e[0] );
check( 'purge_old runs with the hourly wpcap_cleanup_reservations, not with every wpcap_process_jobs', $a437_on( 'wpcap_cleanup_reservations' ) && ! $a437_on( 'wpcap_process_jobs' ) );
$GLOBALS['wpcap_test_hooks'] = $a437_hooks;

// 2,500 old final deliveries, old pending ones and young final ones.
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_deliveries" );
// The deliveries belong to active subscriptions: old pending deliveries of missing or inactive
// subscriptions are cancelled by the cleanup (merged line, Dispatcher::cancel_stale()).
for ( $i = 0; $i < 7; $i++ ) {
	$wpdb->query( $wpdb->prepare( "REPLACE INTO {$p}wpcap_webhook_subscriptions (subscription_id,url,events,secret,status,created_at,updated_at) VALUES (%d,'https://example.test/a437','order.paid','x','active',%s,%s)", 9000 + $i, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ) );
}
$a437_old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
$a437_young = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$a437_rows = array();
for ( $i = 0; $i < 2530; $i++ ) {
	$status = $i < 2500 ? array( 'succeeded', 'failed', 'cancelled' )[ $i % 3 ] : ( $i < 2520 ? 'pending' : 'succeeded' );
	$at = $i < 2520 ? $a437_old : $a437_young;
	$a437_rows[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s)', 9000 + $i % 7, 'evt_a437_' . $i, 'order.paid', '{}', $status, $at, $at, $at );
}
foreach ( array_chunk( $a437_rows, 500 ) as $chunk ) {
	$wpdb->query( "INSERT INTO {$p}wpcap_webhook_deliveries (subscription_id,event_id,event_type,payload,status,next_attempt_at,created_at,updated_at) VALUES " . implode( ',', $chunk ) );
}
$wpdb->query( "ANALYZE TABLE {$p}wpcap_webhook_deliveries" );

$a437_log = array();
$a437_spy = static function ( $sql ) use ( &$a437_log ) { $a437_log[] = $sql; return $sql; };
add_filter( 'query', $a437_spy );
$a437_first = Dispatcher::purge_old( 0 );
remove_filter( 'query', $a437_spy );
$a437_deletes = array_values( array_filter( $a437_log, static fn( $sql ) => 0 === stripos( ltrim( $sql ), 'DELETE' ) ) );
check( 'budget 0: one batch of 1,000 rows, the next run continues', 1000 === $a437_first && 1 === count( $a437_deletes ) && (bool) preg_match( '/LIMIT 1000\s*$/', $a437_deletes[0] ?? '' ) && 1530 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries" ) );

$a437_log = array();
add_filter( 'query', $a437_spy );
$a437_rest = Dispatcher::purge_old();
remove_filter( 'query', $a437_spy );
check( 'default budget: the remaining 1,500 old final deliveries in two batches', 1500 === $a437_rest && 2 === count( array_filter( $a437_log, static fn( $sql ) => 0 === stripos( ltrim( $sql ), 'DELETE' ) ) ) );
check( 'pending deliveries and young final deliveries stay', 20 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE status='pending'" ) && 10 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE status='succeeded'" ) );

// Steady state (the usual hourly run): thousands of young deliveries, nothing to delete. The old
// DELETE read the whole log every 5 minutes; now it is an index range that finds nothing.
$a437_rows = array();
for ( $i = 0; $i < 3000; $i++ ) { $a437_rows[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s)', 9000 + $i % 7, 'evt_a437_y' . $i, 'order.paid', '{}', 'succeeded', $a437_young, $a437_young, $a437_young ); }
foreach ( array_chunk( $a437_rows, 500 ) as $chunk ) { $wpdb->query( "INSERT INTO {$p}wpcap_webhook_deliveries (subscription_id,event_id,event_type,payload,status,next_attempt_at,created_at,updated_at) VALUES " . implode( ',', $chunk ) ); }
$wpdb->query( "ANALYZE TABLE {$p}wpcap_webhook_deliveries" );
$a437_log = array();
add_filter( 'query', $a437_spy );
$a437_none = Dispatcher::purge_old();
remove_filter( 'query', $a437_spy );
$a437_deletes = array_values( array_filter( $a437_log, static fn( $sql ) => 0 === stripos( ltrim( $sql ), 'DELETE' ) ) );
$a437_plan = $a437_deletes ? (array) $wpdb->get_row( 'EXPLAIN ' . $a437_deletes[0] ) : array();
if ( 'range' !== ( $a437_plan['type'] ?? '' ) ) { fwrite( STDERR, 'A437 plan: ' . json_encode( $a437_plan ) . "\n" ); }
check( 'nothing due: one DELETE as a range on KEY cleanup (no full scan)', 0 === $a437_none && 1 === count( $a437_deletes ) && 'range' === ( $a437_plan['type'] ?? '' ) && 'cleanup' === ( $a437_plan['key'] ?? '' ) );

$wpdb->query( "DELETE FROM {$p}wpcap_webhook_deliveries WHERE event_id LIKE 'evt\\_a437\\_%'" );
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions WHERE subscription_id BETWEEN 9000 AND 9006" );
