<?php
/**
 * Load and concurrency tests against a real MySQL/MariaDB database (no web server needed).
 *
 * Spawns N independent PHP worker processes (one DB connection each) that start at the same
 * instant (barrier) and hammer the plugin's real services:
 *
 *   checkout  – B buyers race for a ticket type with limited capacity; a share of them
 *               double-submits the same idempotency key from two connections at once.
 *               Invariants: no overselling, one order per idempotency key, no unexpected errors.
 *   checkin   – D scanner devices check in the same T tickets at the same time.
 *               Invariant: exactly one successful admission per ticket; replays are idempotent.
 *   webhooks  – E paid Stripe events, each delivered R times concurrently (signed, full
 *               verify_webhook() + Webhooks::process() path).
 *               Invariant: every event applied exactly once, tickets issued exactly once.
 *
 * Usage:  php tests/load/run.php [--profile=ci|default|heavy] [--buyers=N] [--capacity=N]
 *                                [--workers=N] [--devices=N] [--tickets=N] [--events=N]
 *                                [--dupes=N] [--retry-rate=0.15] [--scenario=all|checkout|checkin|webhooks]
 *                                [--json=/path/result.json]
 *
 * DB connection as for the integration tests (WPCAP_TEST_DB_HOST/_USER/_PASS/_NAME); tables use
 * the prefix WPCAP_TEST_PREFIX (default "wpload_") and are dropped/recreated on every run.
 * Exit code 1 when an invariant is violated or the unexpected-error rate is above 0.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( false === getenv( 'WPCAP_TEST_PREFIX' ) ) { putenv( 'WPCAP_TEST_PREFIX=wpload_' ); }
require dirname( __DIR__ ) . '/integration/bootstrap.php';
require __DIR__ . '/fakes.php';

use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;

const WPCAP_LOAD_WEBHOOK_SECRET = 'whsec_load';

/** Settings every process (coordinator and workers) needs; options live in process memory. */
function load_settings() {
	PassCaptain\Settings::update( array( 'stripe_secret_key' => 'sk_test_load', 'stripe_webhook_secret' => WPCAP_LOAD_WEBHOOK_SECRET ) );
	Registry::reset();
	// All workers share one (empty) REMOTE_ADDR; the per-IP checkout limit is not what we measure here.
	$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = PHP_INT_MAX;
}

/* ====================================================================== */
/* Worker                                                                  */
/* ====================================================================== */

if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	$job = json_decode( (string) file_get_contents( $argv[2] ), true );
	load_settings();
	$stripe = Registry::get( 'stripe' );
	$out = array();
	// Barrier: every worker is booted and connected; start together.
	while ( microtime( true ) < $job['start_at'] ) { usleep( 200 ); }
	foreach ( $job['ops'] as $op ) {
		$t0 = hrtime( true );
		switch ( $op['kind'] ) {
			case 'buy':
				$GLOBALS['wpcap_test_transients'] = array();
				$r = Orders::create(
					array( 'email' => $op['email'], 'items' => array( array( 'ticket_type_id' => $op['type'], 'quantity' => $op['qty'] ) ), 'idempotency_key' => $op['key'] ),
					array( 'payment_provider' => 'manual', 'hold_minutes' => 60 )
				);
				if ( is_wp_error( $r ) ) {
					$sold_out = false !== strpos( $r->get_error_message(), 'nicht mehr genügend' );
					$res = array( 'outcome' => $sold_out ? 'sold_out' : 'error', 'detail' => $r->get_error_code() . ': ' . $r->get_error_message() );
				} else {
					$res = array( 'outcome' => empty( $r['reused'] ) ? 'created' : 'reused', 'order_id' => (int) $r['order_id'] );
				}
				break;
			case 'scan':
				$GLOBALS['wpcap_test_user_id'] = $op['device'];
				$r = Checkin::perform( $op['code'], array( 'source' => 'web', 'idempotency_key' => $op['key'] ) );
				if ( is_wp_error( $r ) ) {
					$result = (string) ( $r->get_error_data()['result'] ?? $r->get_error_code() );
					$res = array( 'outcome' => 'already_checked_in' === $result ? 'rejected_duplicate' : 'error', 'detail' => $result );
				} else {
					$res = array( 'outcome' => ! empty( $r['replayed'] ) ? 'replayed' : ( 'checked_in' === ( $r['status'] ?? '' ) ? 'admitted' : 'error' ), 'detail' => (string) ( $r['status'] ?? '' ) );
				}
				break;
			case 'webhook':
				$req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/payments/stripe/webhook' );
				$req->set_body( $op['body'] );
				$t = time();
				$req->set_header( 'Stripe-Signature', "t=$t,v1=" . hash_hmac( 'sha256', $t . '.' . $op['body'], WPCAP_LOAD_WEBHOOK_SECRET ) );
				$req['provider'] = 'stripe';
				$event = $stripe->verify_webhook( $req );
				$r = is_wp_error( $event ) ? $event : Webhooks::process( 'stripe', $event );
				$res = is_wp_error( $r )
					? array( 'outcome' => 'error', 'detail' => $r->get_error_code() . ': ' . $r->get_error_message() )
					: array( 'outcome' => (string) $r );
				break;
			default:
				$res = array( 'outcome' => 'error', 'detail' => 'unknown op' );
		}
		$res['ms'] = ( hrtime( true ) - $t0 ) / 1e6;
		$res['i'] = $op['i'];
		$out[] = $res;
	}
	echo json_encode( $out );
	exit( 0 );
}

/* ====================================================================== */
/* Coordinator                                                             */
/* ====================================================================== */

$profiles = array(
	'ci'      => array( 'buyers' => 200, 'capacity' => 60, 'workers' => 20, 'devices' => 8, 'tickets' => 10, 'events' => 20, 'dupes' => 5 ),
	'default' => array( 'buyers' => 500, 'capacity' => 150, 'workers' => 50, 'devices' => 20, 'tickets' => 25, 'events' => 60, 'dupes' => 8 ),
	'heavy'   => array( 'buyers' => 1000, 'capacity' => 300, 'workers' => 100, 'devices' => 40, 'tickets' => 50, 'events' => 120, 'dupes' => 10 ),
);
$opts = getopt( '', array( 'profile:', 'buyers:', 'capacity:', 'workers:', 'devices:', 'tickets:', 'events:', 'dupes:', 'retry-rate:', 'scenario:', 'json:', 'seed:' ) );
$cfg = $profiles[ $opts['profile'] ?? 'default' ] ?? $profiles['default'];
foreach ( array( 'buyers', 'capacity', 'workers', 'devices', 'tickets', 'events', 'dupes' ) as $k ) {
	if ( isset( $opts[ $k ] ) ) { $cfg[ $k ] = max( 1, (int) $opts[ $k ] ); }
}
$cfg['retry_rate'] = isset( $opts['retry-rate'] ) ? (float) $opts['retry-rate'] : 0.15;
$cfg['scenario'] = $opts['scenario'] ?? 'all';
mt_srand( (int) ( $opts['seed'] ?? 20261111 ) );

global $wpdb;
$p = $wpdb->prefix;
$max_conn = (int) ( $wpdb->get_row( "SHOW VARIABLES LIKE 'max_connections'", ARRAY_A )['Value'] ?? 151 );
if ( $cfg['workers'] > $max_conn - 5 ) {
	fwrite( STDERR, "workers capped at " . ( $max_conn - 5 ) . " (max_connections=$max_conn)\n" );
	$cfg['workers'] = $max_conn - 5;
}

echo "WP Pass Captain load test – PHP " . PHP_VERSION . ", DB " . $wpdb->get_var( 'SELECT VERSION()' ) . ", prefix $p\n";
echo 'Config: ' . json_encode( $cfg ) . "\n";
wpcap_test_install();
load_settings();

$failures = array();
$report = array();
function invariant( $name, $ok, $detail = '' ) {
	global $failures;
	printf( "  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, '' !== $detail ? " ($detail)" : '' );
	if ( ! $ok ) { $failures[] = $name; }
}

function load_make_type( $capacity, $max_per_order, $price ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Lasttest', 'slug' => 'load-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => $max_per_order, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}

/** Distributes ops over workers, runs them behind a start barrier and returns [results by op index, wall seconds]. */
function innodb_status( $name ) {
	global $wpdb;
	return (int) ( $wpdb->get_row( "SHOW GLOBAL STATUS LIKE '" . $name . "'", ARRAY_A )['Value'] ?? 0 );
}

function run_workers( array $per_worker ) {
	global $last_db_stats;
	$deadlocks = innodb_status( 'Innodb_deadlocks' );
	$lock_waits = innodb_status( 'Innodb_row_lock_waits' );
	$dir = sys_get_temp_dir() . '/wpcap-load-' . getmypid();
	if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
	$start_at = microtime( true ) + 1.0 + count( $per_worker ) * 0.02;
	$procs = array();
	foreach ( $per_worker as $w => $ops ) {
		if ( ! $ops ) { continue; }
		$file = "$dir/job-$w.json";
		file_put_contents( $file, json_encode( array( 'start_at' => $start_at, 'ops' => $ops ) ) );
		$proc = proc_open( array( PHP_BINARY, __FILE__, '--worker', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$procs[] = array( $proc, $pipes, $file );
	}
	$results = array();
	$end = $start_at;
	foreach ( $procs as list( $proc, $pipes, $file ) ) {
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		proc_close( $proc );
		unlink( $file );
		$rows = json_decode( $stdout, true );
		if ( ! is_array( $rows ) ) {
			fwrite( STDERR, "worker failed: " . substr( $stdout . $stderr, 0, 2000 ) . "\n" );
			continue;
		}
		foreach ( $rows as $row ) { $results[ $row['i'] ][] = $row; }
		$end = max( $end, microtime( true ) );
	}
	@rmdir( $dir );
	// Server-wide counters: only meaningful when nothing else uses the database server meanwhile.
	$last_db_stats = array( 'deadlocks' => innodb_status( 'Innodb_deadlocks' ) - $deadlocks, 'lock_waits' => innodb_status( 'Innodb_row_lock_waits' ) - $lock_waits );
	return array( $results, max( 0.001, $end - $start_at ) );
}

function percentile( array $sorted, $pct ) {
	if ( ! $sorted ) { return 0.0; }
	$idx = (int) ceil( $pct / 100 * count( $sorted ) ) - 1;
	return $sorted[ max( 0, min( count( $sorted ) - 1, $idx ) ) ];
}

function summarize( $name, array $results, $wall, $workers ) {
	global $last_db_stats;
	$ms = array();
	$outcomes = array();
	foreach ( $results as $rows ) {
		foreach ( $rows as $row ) {
			$ms[] = $row['ms'];
			$outcomes[ $row['outcome'] ] = ( $outcomes[ $row['outcome'] ] ?? 0 ) + 1;
		}
	}
	sort( $ms );
	$n = count( $ms );
	ksort( $outcomes );
	return array(
		'scenario' => $name, 'requests' => $n, 'workers' => $workers, 'wall_s' => round( $wall, 2 ), 'rps' => round( $n / $wall, 1 ),
		'p50_ms' => round( percentile( $ms, 50 ), 1 ), 'p95_ms' => round( percentile( $ms, 95 ), 1 ), 'p99_ms' => round( percentile( $ms, 99 ), 1 ), 'max_ms' => round( $n ? end( $ms ) : 0, 1 ),
		'deadlocks' => $last_db_stats['deadlocks'] ?? 0, 'lock_waits' => $last_db_stats['lock_waits'] ?? 0,
		'errors' => $outcomes['error'] ?? 0, 'error_rate' => $n ? round( 100 * ( $outcomes['error'] ?? 0 ) / $n, 2 ) : 0, 'outcomes' => $outcomes,
	);
}

function error_samples( array $results ) {
	$samples = array();
	foreach ( $results as $rows ) { foreach ( $rows as $row ) { if ( 'error' === $row['outcome'] ) { $samples[ $row['detail'] ?? '?' ] = ( $samples[ $row['detail'] ?? '?' ] ?? 0 ) + 1; } } }
	return $samples;
}

$W = $cfg['workers'];

/* ---- Scenario 1: concurrent checkout with limited capacity ---- */
if ( in_array( $cfg['scenario'], array( 'all', 'checkout' ), true ) ) {
	echo "\nScenario checkout: {$cfg['buyers']} buyers, capacity {$cfg['capacity']}, {$W} parallel connections\n";
	$type = load_make_type( $cfg['capacity'], 4, 2500 );
	$per_worker = array_fill( 0, $W, array() );
	$buyers = array();
	$i = 0;
	for ( $b = 0; $b < $cfg['buyers']; $b++ ) {
		$qty = mt_rand( 1, 100 ) <= 70 ? 1 : mt_rand( 2, 4 );
		$key = 'load-buyer-' . $b . '-' . bin2hex( random_bytes( 4 ) );
		$buyers[ $key ] = $qty;
		$op = array( 'kind' => 'buy', 'type' => $type, 'qty' => $qty, 'key' => $key, 'email' => "buyer$b@example.com" );
		$w = $b % $W;
		$per_worker[ $w ][] = $op + array( 'i' => $i++ );
		if ( mt_rand() / mt_getrandmax() < $cfg['retry_rate'] ) {
			// Double submit: same idempotency key on another connection at (nearly) the same moment.
			$per_worker[ ( $w + 1 ) % $W ][] = $op + array( 'i' => $i++ );
		}
	}
	$demand = array_sum( $buyers );
	list( $results, $wall ) = run_workers( $per_worker );
	// Idempotency: group responses by key.
	$by_key = array();
	foreach ( $per_worker as $ops ) { foreach ( $ops as $op ) { foreach ( $results[ $op['i'] ] ?? array() as $row ) { $by_key[ $op['key'] ][] = $row; } } }
	$split_keys = 0;
	$sold = 0;
	foreach ( $by_key as $key => $rows ) {
		$ids = array_unique( array_filter( array_map( static fn( $r ) => $r['order_id'] ?? null, $rows ) ) );
		if ( count( $ids ) > 1 ) { $split_keys++; }
		if ( $ids ) { $sold += $buyers[ $key ]; }
	}
	$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type AND status='active'" );
	$orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT o.order_id) FROM {$p}wpcap_orders o JOIN {$p}wpcap_order_items i ON i.order_id=o.order_id WHERE i.ticket_type_id=$type" );
	$dup_keys = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT idempotency_key FROM {$p}wpcap_orders WHERE idempotency_key IS NOT NULL AND idempotency_key<>'' GROUP BY idempotency_key HAVING COUNT(*)>1) d" );
	$s = summarize( 'checkout (Orders::create)', $results, $wall, $W );
	$created = $s['outcomes']['created'] ?? 0;
	invariant( 'no overselling: held <= capacity', $held <= $cfg['capacity'], "held $held of {$cfg['capacity']}, demand $demand" );
	invariant( 'capacity is used up when demand exceeds it', $demand < $cfg['capacity'] || $cfg['capacity'] - $held < 4, 'left ' . ( $cfg['capacity'] - $held ) );
	invariant( 'held tickets = tickets of successful orders', $held === $sold, "sold $sold" );
	invariant( 'one order per idempotency key (responses)', 0 === $split_keys, "$split_keys keys with >1 order id" );
	invariant( 'one order per idempotency key (database)', 0 === $dup_keys && $orders === $created, "$orders orders, $created created responses" );
	invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( error_samples( $results ) ) );
	$report[] = $s;
}

/* ---- Scenario 2: the same tickets scanned by many devices at once ---- */
if ( in_array( $cfg['scenario'], array( 'all', 'checkin' ), true ) ) {
	$D = min( $cfg['devices'], $W );
	echo "\nScenario check-in: {$cfg['tickets']} tickets, each scanned by {$D} devices simultaneously (+1 replay each)\n";
	$type = load_make_type( $cfg['tickets'], 10, 0 );
	$GLOBALS['wpcap_test_mails'] = array();
	$order_ids = array();
	// Orders are limited to 10 tickets per line; issue the fixture tickets in chunks.
	for ( $left = $cfg['tickets']; $left > 0; $left -= 10 ) {
		$order = Orders::create( array( 'email' => 'door@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => min( 10, $left ) ) ), 'idempotency_key' => 'load-door-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
		if ( is_wp_error( $order ) ) { fwrite( STDERR, $order->get_error_message() . "\n" ); break; }
		$order_ids[] = (int) $order['order_id'];
	}
	$order_in = implode( ',', $order_ids ?: array( 0 ) );
	$codes = array();
	foreach ( $GLOBALS['wpcap_test_mails'] as $mail ) {
		if ( preg_match_all( '/Ticket-Code: ([a-f0-9]{64})/', $mail['body'], $m ) ) { $codes = array_merge( $codes, $m[1] ); }
	}
	$codes = array_values( array_unique( $codes ) );
	invariant( 'fixture: free orders issued all ticket codes', count( $codes ) === $cfg['tickets'], count( $codes ) . ' codes' );
	$per_worker = array_fill( 0, $D, array() );
	$i = 0;
	$ticket_of = array();
	// Even devices scan the tickets in order (same-ticket races), odd devices in their own random
	// order, so first admissions of different tickets also run concurrently (cross-ticket locking).
	$orders_by_device = array();
	for ( $d = 0; $d < $D; $d++ ) {
		$seq = array_keys( $codes );
		if ( $d % 2 ) { shuffle( $seq ); }
		$orders_by_device[ $d ] = $seq;
	}
	foreach ( array( false, true ) as $replay ) {
		// Second pass: each device retries its own scans with the same idempotency key (lost response).
		foreach ( array_keys( $codes ) as $pos ) {
			for ( $d = 0; $d < $D; $d++ ) {
				$t = $orders_by_device[ $d ][ $pos ];
				$ticket_of[ $i ] = $t;
				$per_worker[ $d ][] = array( 'kind' => 'scan', 'code' => $codes[ $t ], 'device' => 100 + $d, 'key' => "dev$d-t$t", 'i' => $i++, 'replay' => $replay );
			}
		}
	}
	list( $results, $wall ) = run_workers( $per_worker );
	$admitted = array_fill( 0, count( $codes ), 0 );
	foreach ( $results as $idx => $rows ) { foreach ( $rows as $row ) { if ( 'admitted' === $row['outcome'] ) { $admitted[ $ticket_of[ $idx ] ]++; } } }
	$db_admits = $wpdb->get_col( "SELECT COUNT(*) FROM {$p}wpcap_checkins c JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id WHERE t.order_id IN ($order_in) AND c.result='checked_in' GROUP BY c.ticket_id" );
	$not_checked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($order_in) AND status<>'checked_in'" );
	$s = summarize( 'check-in (Checkin::perform)', $results, $wall, $D );
	invariant( 'exactly one successful scan per ticket (responses)', count( array_filter( $admitted, static fn( $n ) => 1 !== $n ) ) === 0, 'admits per ticket: ' . implode( ',', array_unique( $admitted ) ) );
	invariant( 'exactly one admission row per ticket (database)', count( $db_admits ) === count( $codes ) && array_unique( array_map( 'intval', $db_admits ) ) === array( 1 ), count( $db_admits ) . ' tickets with admissions' );
	invariant( 'all tickets checked in', 0 === $not_checked );
	invariant( 'every admitted response is backed by an admission row', array_sum( $admitted ) === array_sum( array_map( 'intval', $db_admits ) ), array_sum( $admitted ) . ' admitted responses, ' . array_sum( array_map( 'intval', $db_admits ) ) . ' rows' );
	invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( error_samples( $results ) ) );
	$report[] = $s;
}

/* ---- Scenario 3: webhook burst with duplicate deliveries ---- */
if ( in_array( $cfg['scenario'], array( 'all', 'webhooks' ), true ) ) {
	$E = $cfg['events'];
	$R = $cfg['dupes'];
	echo "\nScenario webhooks: {$E} Stripe payment events, each delivered {$R}x concurrently\n";
	$type = load_make_type( $E * 2, 2, 1500 );
	$orders = array();
	for ( $e = 0; $e < $E; $e++ ) {
		$GLOBALS['wpcap_test_transients'] = array();
		$r = Orders::create( array( 'email' => "hook$e@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => "load-hook-$e-" . uniqid() ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, 'fixture order failed: ' . $r->get_error_message() . "\n" ); exit( 2 ); }
		$orders[] = (array) Orders::get( $r['order_id'] );
	}
	$per_worker = array_fill( 0, $W, array() );
	$i = 0;
	$event_of = array();
	foreach ( $orders as $e => $o ) {
		$body = json_encode( array( 'id' => "evt_load_$e", 'type' => 'checkout.session.completed', 'data' => array( 'object' => array(
			'id' => 'cs_test_' . $o['public_id'], 'object' => 'checkout.session', 'amount_total' => (int) $o['total_minor'], 'currency' => 'eur',
			'client_reference_id' => $o['public_id'], 'metadata' => array( 'wpcap_order' => $o['public_id'] ),
			'payment_intent' => 'pi_' . substr( $o['public_id'], 0, 12 ), 'payment_status' => 'paid', 'status' => 'complete',
		) ) ) );
		for ( $r = 0; $r < $R; $r++ ) {
			$event_of[ $i ] = $e;
			$per_worker[ ( $e * $R + $r ) % $W ][] = array( 'kind' => 'webhook', 'body' => $body, 'i' => $i++ );
		}
	}
	list( $results, $wall ) = run_workers( $per_worker );
	$applied = array_fill( 0, $E, 0 );
	foreach ( $results as $idx => $rows ) { foreach ( $rows as $row ) { if ( 'paid' === $row['outcome'] ) { $applied[ $event_of[ $idx ] ]++; } } }
	$ids = implode( ',', array_map( static fn( $o ) => (int) $o['order_id'], $orders ) );
	$paid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE order_id IN ($ids) AND payment_status='paid'" );
	$tickets = $wpdb->get_col( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($ids) GROUP BY order_id" );
	$events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_payment_events WHERE provider='stripe' AND provider_event_id LIKE 'evt_load_%' AND status='processed'" );
	$s = summarize( 'webhook (verify + process)', $results, $wall, $W );
	invariant( 'every event applied exactly once', count( array_filter( $applied, static fn( $n ) => 1 !== $n ) ) === 0, 'applications per event: ' . implode( ',', array_unique( $applied ) ) );
	invariant( 'all orders paid', $paid === $E, "$paid of $E" );
	invariant( 'tickets issued exactly once (2 per order)', count( $tickets ) === $E && array_unique( array_map( 'intval', $tickets ) ) === array( 2 ), count( $tickets ) . ' orders with tickets' );
	invariant( 'one processed event row per event', $events === $E, "$events rows" );
	invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( error_samples( $results ) ) );
	$report[] = $s;
}

/* ---- Summary ---- */
echo "\n";
$cols = array( 'scenario' => 28, 'requests' => 8, 'workers' => 7, 'wall_s' => 7, 'rps' => 7, 'p50_ms' => 7, 'p95_ms' => 7, 'p99_ms' => 7, 'max_ms' => 8, 'deadlocks' => 9, 'error_rate' => 10 );
$line = '';
foreach ( $cols as $c => $w ) { $line .= str_pad( 'error_rate' === $c ? 'errors %' : $c, $w + 2 ); }
echo rtrim( $line ) . "\n" . str_repeat( '-', strlen( rtrim( $line ) ) ) . "\n";
foreach ( $report as $row ) {
	$line = '';
	foreach ( $cols as $c => $w ) { $line .= str_pad( (string) $row[ $c ], $w + 2 ); }
	echo rtrim( $line ) . "\n";
	echo '    outcomes: ' . json_encode( $row['outcomes'] ) . "\n";
}
if ( isset( $opts['json'] ) ) {
	file_put_contents( $opts['json'], json_encode( array( 'config' => $cfg, 'results' => $report, 'failures' => $failures ), JSON_PRETTY_PRINT ) );
}
echo "\n" . ( $failures ? count( $failures ) . ' invariant(s) violated: ' . implode( '; ', $failures ) : 'All invariants hold.' ) . "\n";
exit( $failures ? 1 : 0 );
