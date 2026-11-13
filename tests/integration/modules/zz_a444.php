<?php
/**
 * A444: finished rows of reservations, jobs, seat holds and payment events are deleted after N
 * days by the hourly cleanup cron (in batches, with a time budget); everything still in use stays.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Privacy\Retention;

global $wpdb;
$p = $wpdb->prefix;

echo "A444: cleanup of finished rows\n";
$a444_now = time();
$a444_ago = static fn( $days ) => gmdate( 'Y-m-d H:i:s', $a444_now - (int) ( $days * DAY_IN_SECONDS ) );
$a444_hooked = (bool) preg_match( "/add_action\\( 'wpcap_cleanup_reservations', array\\( Privacy\\\\Retention::class, 'housekeeping' \\)/", (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' ) );
check( 'housekeeping runs on the hourly cleanup cron', $a444_hooked );
check( 'payment events have the cleanup index (status, updated_at)', array( 'status', 'updated_at' ) === array_map( static fn( $r ) => $r->Column_name, (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_payment_events WHERE Key_name='cleanup'" ) ) );

// Reservations: order 1 paid long ago, order 2 still pending (its holds stay), order 3 recent.
$a444_type = make_type( 0 );
$a444_res = static function ( $order, $status, $counted, $expires ) use ( $wpdb, $p, $a444_type ) {
	$wpdb->insert( "{$p}wpcap_reservations", array( 'order_id' => $order, 'ticket_type_id' => $a444_type, 'quantity' => 1, 'expires_at' => $expires, 'status' => $status, 'counted_qty' => $counted ) );
	return (int) $wpdb->insert_id;
};
$a444_order = static function ( $payment_status ) use ( $wpdb, $p ) {
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => md5( uniqid( '', true ) ), 'idempotency_key' => hash( 'sha256', uniqid( 'a444', true ) ), 'customer_email' => 'a444@example.com', 'status' => 'pending' === $payment_status ? 'pending_payment' : 'complete', 'payment_status' => $payment_status, 'currency' => 'EUR', 'price_snapshot' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
	return (int) $wpdb->insert_id;
};
$a444_paid = $a444_order( 'paid' );
$a444_pending = $a444_order( 'pending' );
$a444_gone = array();
$a444_keep = array();
$a444_gone[] = $a444_res( $a444_paid, 'converted', 0, $a444_ago( 40 ) );
$a444_gone[] = $a444_res( $a444_paid, 'expired', 0, $a444_ago( 40 ) );
$a444_gone[] = $a444_res( $a444_paid, 'released', 0, $a444_ago( 40 ) );
$a444_gone[] = $a444_res( 990000001, 'expired', 0, $a444_ago( 40 ) ); // order deleted meanwhile
$a444_keep['active'] = $a444_res( $a444_paid, 'active', 1, $a444_ago( 40 ) );
$a444_keep['still counts'] = $a444_res( $a444_paid, 'expired', 1, $a444_ago( 40 ) );
$a444_keep['pending order'] = $a444_res( $a444_pending, 'expired', 0, $a444_ago( 40 ) );
$a444_keep['recent'] = $a444_res( $a444_paid, 'converted', 0, $a444_ago( 5 ) );
// Many old rows: more than one batch.
$values = array();
for ( $i = 0; $i < 1200; $i++ ) { $values[] = '(' . ( 990100000 + $i ) . ",$a444_type,1,'" . $a444_ago( 60 ) . "','converted',0)"; }
$wpdb->query( "INSERT INTO {$p}wpcap_reservations (order_id,ticket_type_id,quantity,expires_at,status,counted_qty) VALUES " . implode( ',', $values ) );

// Jobs.
$a444_job = static function ( $status, $days ) use ( $wpdb, $p, $a444_ago ) {
	$at = $a444_ago( $days );
	$wpdb->insert( "{$p}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', uniqid( 'a444j', true ) ), 'job_type' => 'a444', 'payload' => '{}', 'run_after' => $at, 'status' => $status, 'attempts' => 1, 'created_at' => $at, 'updated_at' => $at ) );
	return (int) $wpdb->insert_id;
};
$a444_jobs_gone = array( $a444_job( 'complete', 40 ), $a444_job( 'cancelled', 40 ), $a444_job( 'dead_letter', 200 ) );
$a444_jobs_keep = array( $a444_job( 'complete', 5 ), $a444_job( 'queued', 40 ), $a444_job( 'running', 40 ), $a444_job( 'dead_letter', 40 ) );

// Seat holds.
$a444_hold = static function ( $status, $days ) use ( $wpdb, $p, $a444_ago ) {
	static $seat = 0;
	$wpdb->insert( "{$p}wpcap_seat_holds", array( 'occurrence_id' => 990000001, 'seat_id' => ++$seat, 'order_id' => 0, 'request_key' => hash( 'sha256', uniqid( 'a444h', true ) ), 'expires_at' => $a444_ago( $days ), 'status' => $status ) );
	return (int) $wpdb->insert_id;
};
$a444_holds_gone = array( $a444_hold( 'expired', 40 ), $a444_hold( 'released', 40 ) );
$a444_holds_keep = array( $a444_hold( 'sold', 400 ), $a444_hold( 'active', 40 ), $a444_hold( 'expired', 5 ) );

// Payment events (dedupe of provider redeliveries: kept 90 days).
$a444_event = static function ( $status, $days ) use ( $wpdb, $p, $a444_ago ) {
	$wpdb->insert( "{$p}wpcap_payment_events", array( 'provider' => 'a444', 'provider_event_id' => uniqid( 'evt_', true ), 'event_type' => 'paid', 'order_id' => 0, 'status' => $status, 'created_at' => $a444_ago( $days ), 'updated_at' => $a444_ago( $days ) ) );
	return (int) $wpdb->insert_id;
};
$a444_events_gone = array( $a444_event( 'processed', 100 ), $a444_event( 'processing', 100 ) );
$a444_events_keep = array( $a444_event( 'processed', 30 ) );

$a444_exists = static fn( $table, $col, array $ids ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}$table WHERE $col IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );

// No time left: nothing deleted, the run reports itself incomplete.
$a444_none = Retention::housekeeping( $a444_now, 0.0 );
check( 'no time budget: nothing deleted, incomplete', empty( $a444_none['complete'] ) && 4 === $a444_exists( 'wpcap_reservations', 'reservation_id', $a444_gone ) );

// Step switched off by the filter.
$a444_off = static fn( $days, $step ) => 'payment_events' === $step ? 0 : $days;
add_filter( 'wpcap_privacy_retention_days', $a444_off, 10, 2 );
$a444_run = Retention::housekeeping( $a444_now, 30.0 );
remove_filter( 'wpcap_privacy_retention_days', $a444_off, 10 );
check( 'reservations: finished rows (converted/expired/released, counted_qty 0) deleted in batches', 1204 === (int) $a444_run['reservations'] && 0 === $a444_exists( 'wpcap_reservations', 'reservation_id', $a444_gone ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id BETWEEN 990100000 AND 990101199" ) );
check( 'reservations: active, still counting, pending-order and recent rows stay', count( $a444_keep ) === $a444_exists( 'wpcap_reservations', 'reservation_id', array_values( $a444_keep ) ) );
check( 'jobs: complete/discarded after 30 days, dead letters after 180 days; queued/running/recent stay', 2 === (int) $a444_run['jobs'] && 1 === (int) $a444_run['dead_jobs'] && 0 === $a444_exists( 'wpcap_jobs', 'job_id', $a444_jobs_gone ) && 4 === $a444_exists( 'wpcap_jobs', 'job_id', $a444_jobs_keep ) );
check( 'seat holds: expired/released after 30 days; sold, active and recent stay', 2 === (int) $a444_run['seat_holds'] && 0 === $a444_exists( 'wpcap_seat_holds', 'hold_id', $a444_holds_gone ) && 3 === $a444_exists( 'wpcap_seat_holds', 'hold_id', $a444_holds_keep ) );
check( 'payment events: step off via wpcap_privacy_retention_days (0) keeps them', 0 === (int) $a444_run['payment_events'] && 2 === $a444_exists( 'wpcap_payment_events', 'payment_event_id', $a444_events_gone ) );
check( 'complete run reports complete', ! empty( $a444_run['complete'] ) );

$a444_run = Retention::housekeeping( $a444_now, 30.0 );
check( 'payment events: processed/processing after 90 days deleted, recent kept', 2 === (int) $a444_run['payment_events'] && 0 === $a444_exists( 'wpcap_payment_events', 'payment_event_id', $a444_events_gone ) && 1 === $a444_exists( 'wpcap_payment_events', 'payment_event_id', $a444_events_keep ) );
check( 'second run finds nothing else', 0 === (int) $a444_run['reservations'] && 0 === (int) $a444_run['jobs'] && 0 === (int) $a444_run['seat_holds'] );

$wpdb->query( "DELETE FROM {$p}wpcap_reservations WHERE reservation_id IN (" . implode( ',', array_values( $a444_keep ) ) . ')' );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='a444'" );
$wpdb->query( "DELETE FROM {$p}wpcap_seat_holds WHERE occurrence_id=990000001" );
$wpdb->query( "DELETE FROM {$p}wpcap_payment_events WHERE provider='a444'" );
$wpdb->query( "DELETE FROM {$p}wpcap_orders WHERE order_id IN ($a444_paid,$a444_pending)" );
