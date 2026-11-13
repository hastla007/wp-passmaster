<?php
/**
 * History benchmark (A429, A430, A444): seeds a long sales history (default 500,000 reservations
 * and orders, 100,000 jobs, seat holds and payment events) and measures the checkout capacity
 * check, the capacity reconcile cron and the hourly cleanup (Retention::housekeeping()).
 *
 * Usage:  php tests/load/history.php seed|measure|raw|cleanup|drop
 *         WPP6_N=100000 php tests/load/history.php seed     (smaller data set)
 * DB connection as for the integration tests; tables use the prefix WPCAP_TEST_PREFIX (default
 * "wphist_"), `seed` recreates them, `drop` removes them. Results: docs/TESTING.md.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile
if ( false === getenv( 'WPCAP_TEST_PREFIX' ) ) { putenv( 'WPCAP_TEST_PREFIX=wphist_' ); }
require dirname( __DIR__ ) . '/integration/bootstrap.php';

global $wpdb;
$p = $wpdb->prefix;
$mode = $argv[1] ?? 'measure';
$N = (int) ( getenv( 'WPP6_N' ) ?: 500000 );

function t( callable $fn, $runs = 5 ) {
	$best = INF; $res = null;
	for ( $i = 0; $i < $runs; $i++ ) { $s = microtime( true ); $res = $fn(); $best = min( $best, microtime( true ) - $s ); }
	return array( round( $best * 1000, 1 ), $res );
}
function explain( $sql ) {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql ) as $r ) { $out[] = sprintf( '%s:%s key=%s rows=%s', $r->table, $r->type, $r->key ?? '-', $r->rows ); }
	return implode( ' | ', $out );
}

if ( 'drop' === $mode ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . '%' ) ) as $tbl ) { $wpdb->query( "DROP TABLE `$tbl`" ); }
	echo "dropped\n";
	exit;
}

if ( 'seed' === $mode ) {
	wpcap_test_install();
	$now = time();
	$wpdb->query( "SET SESSION sql_log_bin=0" );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Bench', 'slug' => 'bench', 'description' => '', 'status' => 'published', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$event = (int) $wpdb->insert_id;
	$types = array();
	for ( $o = 0; $o < 60; $o++ ) {
		$start = $now + ( $o - 50 ) * 7 * DAY_IN_SECONDS; // 50 past, 10 upcoming
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'timezone' => 'Europe/Vienna', 'location' => 'X', 'capacity' => 100000, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		for ( $k = 0; $k < 3; $k++ ) {
			$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'T' . $k, 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 100000, 'max_per_order' => 10, 'status' => 'active' ) );
			$types[] = (int) $wpdb->insert_id;
		}
	}
	$hot = end( $types ); // upcoming type with a long history (on-sale since months)
	$wpdb->query( "CREATE TEMPORARY TABLE seq (n int unsigned PRIMARY KEY)" );
	$wpdb->query( 'INSERT INTO seq VALUES ' . implode( ',', array_map( static fn( $i ) => "($i)", range( 0, 999 ) ) ) );
	$wpdb->query( 'START TRANSACTION' );
	$batch = 1000;
	$typecount = count( $types );
	for ( $b = 0; $b < $N / $batch; $b++ ) {
		$base = $b * $batch;
		// Orders: one per reservation; 85 % paid, 15 % expired.
		$wpdb->query( "INSERT INTO {$p}wpcap_orders (order_id,public_id,idempotency_key,customer_email,status,payment_status,currency,price_snapshot,created_at,updated_at)
			SELECT $base+n+1, MD5(CONCAT('o',$base+n)), SHA2(CONCAT('k',$base+n),256), CONCAT('b',$base+n,'@example.com'),
			IF(($base+n)%20<17,'complete','expired'), IF(($base+n)%20<17,'paid','expired'), 'EUR','{}',
			FROM_UNIXTIME($now - 200*86400 + ($base+n)*30), FROM_UNIXTIME($now - 200*86400 + ($base+n)*30) FROM seq" );
		// Hot type gets 40 % of the history, the rest spread over all types.
		$wpdb->query( "INSERT INTO {$p}wpcap_order_items (order_id,ticket_type_id,quantity,unit_price_minor,line_total_minor,counted_qty)
			SELECT $base+n+1, IF(($base+n)%5<2,$hot,ELT(1+(($base+n)%$typecount),". implode( ',', $types ) . ")), 1, 1000, 1000, IF(($base+n)%20<17,1,0) FROM seq" );
		$wpdb->query( "INSERT INTO {$p}wpcap_reservations (order_id,ticket_type_id,quantity,expires_at,status,counted_qty)
			SELECT $base+n+1, IF(($base+n)%5<2,$hot,ELT(1+(($base+n)%$typecount),". implode( ',', $types ) . ")), 1,
			FROM_UNIXTIME($now - 200*86400 + ($base+n)*30 + 900), IF(($base+n)%20<17,'converted',IF(($base+n)%20<19,'expired','released')), 0 FROM seq" );
		if ( 0 === $b % 50 ) { $wpdb->query( 'COMMIT' ); $wpdb->query( 'START TRANSACTION' ); }
	}
	$wpdb->query( 'COMMIT' );
	// 300 live holds on the hot type.
	$wpdb->query( "UPDATE {$p}wpcap_reservations SET status='active',counted_qty=quantity,expires_at=" . "FROM_UNIXTIME($now+600)" . " ORDER BY reservation_id DESC LIMIT 300" );
	$wpdb->query( "UPDATE {$p}wpcap_orders o JOIN {$p}wpcap_reservations r ON r.order_id=o.order_id SET o.status='pending_payment',o.payment_status='pending' WHERE r.status='active'" );
	$wpdb->query( "UPDATE {$p}wpcap_order_items oi JOIN {$p}wpcap_reservations r ON r.order_id=oi.order_id SET oi.counted_qty=0 WHERE r.status='active'" );
	// Counters from the source tables, then "ready".
	foreach ( $wpdb->get_col( "SELECT occurrence_id FROM {$p}wpcap_occurrences" ) as $occ ) { PassCaptain\CapacityCounters::rebuild_occurrence( (int) $occ ); }
	// Jobs, seat holds, payment events history.
	$wpdb->query( "INSERT INTO {$p}wpcap_jobs (idempotency_key,job_type,payload,run_after,status,attempts,created_at,updated_at) SELECT SHA2(CONCAT('j',a.n*1000+b.n),256),'ticket_mail_retry','{}',FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),'complete',1,FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170) FROM seq a JOIN seq b WHERE a.n<100" );
	$wpdb->query( "INSERT INTO {$p}wpcap_seat_holds (occurrence_id,seat_id,order_id,request_key,expires_at,status) SELECT 1,b.n+1,a.n*1000+b.n+1,SHA2(CONCAT('s',a.n*1000+b.n),256),FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),IF(b.n%3=0,'sold','expired') FROM seq a JOIN seq b WHERE a.n<100" );
	$wpdb->query( "INSERT INTO {$p}wpcap_payment_events (provider,provider_event_id,event_type,order_id,status,created_at,updated_at) SELECT 'stripe',CONCAT('evt_',a.n*1000+b.n),'paid',a.n*1000+b.n+1,'processed',FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170) FROM seq a JOIN seq b WHERE a.n<100" );
	$wpdb->query( "ANALYZE TABLE {$p}wpcap_reservations, {$p}wpcap_orders, {$p}wpcap_order_items, {$p}wpcap_jobs, {$p}wpcap_seat_holds, {$p}wpcap_payment_events" );
	echo 'seeded: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations" ) . " reservations, hot type $hot\n";
	exit;
}

update_option( PassCaptain\CapacityCounters::OPTION, array( 'state' => 'ready', 'since' => time() ) );
$hot = (int) $wpdb->get_var( "SELECT MAX(ticket_type_id) FROM {$p}wpcap_ticket_types" );
$type = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$hot" );
$occ = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=" . (int) $type->occurrence_id );

if ( 'measure' === $mode ) {
	echo 'reservations: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations" ) . ', of hot type: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE ticket_type_id=$hot" ) . "\n";
	$now = gmdate( 'Y-m-d H:i:s' );
	echo "EXPLAIN type check:  " . explain( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $hot, $now ) ) . "\n";
	echo "EXPLAIN occ check:   " . explain( $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.counted_qty>0 AND (r.status<>'active' OR r.expires_at <= %s) WHERE tt.occurrence_id=%d", $now, $occ->occurrence_id ) ) . "\n";
	$tl = implode( ',', $wpdb->get_col( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ->occurrence_id ) );
	echo "EXPLAIN occ (IN):    " . explain( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($tl) AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $now ) ) . "\n";
	$m = new ReflectionMethod( PassCaptain\Orders::class, 'capacity_error' );
	$m->setAccessible( true );
	$type->capacity = 10000000; $occ->capacity = 10000000;
	list( $ms, $res ) = t( static fn() => $m->invoke( null, $type, $occ, 1, 0 ), 5 );
	echo "capacity_error (type + occurrence): {$ms} ms (result '" . $res . "')\n";
	list( $ms, $res ) = t( static fn() => PassCaptain\CapacityCounters::used_for_types( array( $hot ), 0, $now ), 5 );
	echo "used_for_types: {$ms} ms\n";
	add_filter( 'wpcap_capacity_reconcile_confirm_us', static fn() => 0 );
	list( $ms, $res ) = t( static fn() => PassCaptain\CapacityCounters::reconcile(), 3 );
	echo "reconcile (cron): {$ms} ms report=" . wp_json_encode( array_intersect_key( $res, array_flip( array( 'state', 'resynced_orders', 'repaired_occurrences', 'complete' ) ) ) ) . "\n";
	list( $ms, $res ) = t( static fn() => PassCaptain\CapacityCounters::reconcile( true ), 1 );
	echo "reconcile --all: {$ms} ms\n";
	exit;
}

if ( 'raw' === $mode ) {
	$now = gmdate( 'Y-m-d H:i:s' );
	$tl = implode( ',', $wpdb->get_col( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ->occurrence_id ) );
	foreach ( array(
		'type'      => $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $hot, $now ),
		'occ join'  => $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.counted_qty>0 AND (r.status<>'active' OR r.expires_at <= %s) WHERE tt.occurrence_id=%d", $now, $occ->occurrence_id ),
		'occ IN'    => $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($tl) AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $now ),
	) as $k => $sql ) {
		list( $ms ) = t( static fn() => $wpdb->get_var( $sql ), 5 );
		echo "$k: $ms ms  " . explain( $sql ) . "\n";
	}
	exit;
}

if ( 'cleanup' === $mode ) {
	if ( ! method_exists( PassCaptain\Privacy\Retention::class, 'housekeeping' ) ) { echo "no housekeeping\n"; exit; }
	foreach ( array( 'wpcap_reservations', 'wpcap_jobs', 'wpcap_seat_holds', 'wpcap_payment_events' ) as $tbl ) { echo "$tbl before: " . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}$tbl" ) . "\n"; }
	$runs = 0; $total = microtime( true );
	do {
		$s = microtime( true );
		$r = PassCaptain\Privacy\Retention::housekeeping( null, 10.0 );
		++$runs;
		printf( "run %d: %.1f s %s\n", $runs, microtime( true ) - $s, wp_json_encode( $r ) );
	} while ( empty( $r['complete'] ) && $runs < 20 );
	printf( "total %.1f s\n", microtime( true ) - $total );
	foreach ( array( 'wpcap_reservations', 'wpcap_jobs', 'wpcap_seat_holds', 'wpcap_payment_events' ) as $tbl ) { echo "$tbl after: " . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}$tbl" ) . "\n"; }
	exit;
}
