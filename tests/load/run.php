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
 *   mixed     – one ticket type: a checkout burst, on-time payment webhooks, LATE webhooks of
 *               orders whose hold lapsed (late recovery) and hold cleanup runs, all at once.
 *               Invariants: paid + active holds <= capacity, tickets = paid units, no errors
 *               (deadlocks must be absorbed, not surface as 409/503).
 *   seats     – seat map as the only limit: buyers race for (hot) seats while late webhooks
 *               recover lapsed seat holds and seat/hold cleanups run.
 *               Invariants: every valid ticket has a seat, no seat sold twice, no orphan claims.
 *
 * Usage:  php tests/load/run.php [--profile=ci|default|heavy] [--buyers=N] [--capacity=N]
 *                                [--workers=N] [--devices=N] [--tickets=N] [--events=N]
 *                                [--dupes=N] [--retry-rate=0.15] [--late=N] [--ontime=N]
 *                                [--scenario=all|checkout|checkin|webhooks|mixed|seats]
 *                                [--isolation=READ-COMMITTED] [--json=/path/result.json]
 *
 * --isolation sets the session default of every worker connection (e.g. READ-COMMITTED, the common
 * WooCommerce tuning); the plugin must stay correct because it sets REPEATABLE READ per transaction.
 *
 * DB connection as for the integration tests (WPCAP_TEST_DB_HOST/_USER/_PASS/_NAME); tables use
 * the prefix WPCAP_TEST_PREFIX (default "wpload_") and are dropped/recreated on every run.
 * Deadlocks: MySQL information_schema.INNODB_METRICS lock_deadlocks, MariaDB Innodb_deadlocks
 * (server-wide counters). A worker that dies or returns no result counts its ops as errors.
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

/** Normalised isolation level (READ COMMITTED …) or '' when not given/unknown. */
function load_isolation( $value ) {
	$value = strtoupper( trim( str_replace( array( '-', '_' ), ' ', (string) $value ) ) );
	return in_array( $value, array( 'READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE' ), true ) ? $value : '';
}

/** Seat-map module for the seats scenario (REST/asset stubs the test bootstrap does not have). */
function load_seating() {
	if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/' ); }
	if ( ! function_exists( 'rest_url' ) ) { function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); } }
	if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( ...$args ) { return true; } }
	PassCaptain\Modules\Seating::register();
}

/* ====================================================================== */
/* Worker                                                                  */
/* ====================================================================== */

if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	$job = json_decode( (string) file_get_contents( $argv[2] ), true );
	load_settings();
	if ( '' !== ( $job['isolation'] ?? '' ) ) { $wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . load_isolation( $job['isolation'] ) ); }
	if ( ! empty( $job['seating'] ) ) { load_seating(); }
	$stripe = Registry::get( 'stripe' );
	$out = array();
	// Barrier: every worker is booted and connected; start together.
	while ( microtime( true ) < $job['start_at'] ) { usleep( 200 ); }
	foreach ( $job['ops'] as $op ) {
		$t0 = hrtime( true );
		switch ( $op['kind'] ) {
			case 'buy':
				// Only this buyer's per-IP+e-mail counter (a full reset would serialise the workers on the table).
				$GLOBALS['wpcap_test_transients'] = array();
				$wpdb->delete( \PassCaptain\RateLimit::table(), array( 'bucket_key' => \PassCaptain\RateLimit::hash( 'wpcap_checkout_' . Orders::rate_hash( Orders::client_ip() . '|' . strtolower( $op['email'] ) ) ) ) );
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
			case 'seatbuy':
				$GLOBALS['wpcap_test_transients'] = array();
				$r = Orders::create(
					array( 'email' => $op['email'], 'items' => array( array( 'ticket_type_id' => $op['type'], 'quantity' => count( $op['seats'] ) ) ), 'seats' => array( $op['type'] => $op['seats'] ), 'idempotency_key' => $op['key'] ),
					array( 'payment_provider' => 'stripe', 'hold_minutes' => 30, 'skip_rate_limit' => true )
				);
				$taken = is_wp_error( $r ) && preg_match( '/Sitzplatz|Sitzplätze|nicht mehr genügend/', $r->get_error_message() );
				$res = is_wp_error( $r ) ? array( 'outcome' => $taken ? 'seat_taken' : 'error', 'detail' => $r->get_error_code() . ': ' . $r->get_error_message() ) : array( 'outcome' => 'created', 'order_id' => (int) $r['order_id'] );
				break;
			case 'seatcleanup':
				PassCaptain\Seating\Reservations::cleanup();
				$res = array( 'outcome' => 'cleanup' );
				break;
			case 'expire':
				Orders::expire_holds();
				$res = array( 'outcome' => 'expired_run' );
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
$opts = getopt( '', array( 'profile:', 'buyers:', 'capacity:', 'workers:', 'devices:', 'tickets:', 'events:', 'dupes:', 'retry-rate:', 'scenario:', 'json:', 'seed:', 'late:', 'ontime:', 'isolation:' ) );
$cfg = $profiles[ $opts['profile'] ?? 'default' ] ?? $profiles['default'];
foreach ( array( 'buyers', 'capacity', 'workers', 'devices', 'tickets', 'events', 'dupes' ) as $k ) {
	if ( isset( $opts[ $k ] ) ) { $cfg[ $k ] = max( 1, (int) $opts[ $k ] ); }
}
$cfg['retry_rate'] = isset( $opts['retry-rate'] ) ? (float) $opts['retry-rate'] : 0.15;
$cfg['scenario'] = $opts['scenario'] ?? 'all';
// Mixed/seats: lapsed-hold orders with late webhooks and on-time orders (default: a share of the capacity).
$cfg['late'] = max( 1, (int) ( $opts['late'] ?? max( 4, intdiv( $cfg['capacity'], 4 ) ) ) );
$cfg['ontime'] = max( 1, (int) ( $opts['ontime'] ?? max( 2, intdiv( $cfg['capacity'], 8 ) ) ) );
$cfg['isolation'] = load_isolation( $opts['isolation'] ?? '' );
if ( isset( $opts['isolation'] ) && '' === $cfg['isolation'] ) { fwrite( STDERR, "unknown --isolation\n" ); exit( 2 ); }
mt_srand( (int) ( $opts['seed'] ?? 20261111 ) );

global $wpdb;
$p = $wpdb->prefix;
$max_conn = (int) ( $wpdb->get_row( "SHOW VARIABLES LIKE 'max_connections'", ARRAY_A )['Value'] ?? 151 );
if ( $cfg['workers'] > $max_conn - 5 ) {
	fwrite( STDERR, "workers capped at " . ( $max_conn - 5 ) . " (max_connections=$max_conn)\n" );
	$cfg['workers'] = $max_conn - 5;
}

echo "Pass Captain load test – PHP " . PHP_VERSION . ", DB " . $wpdb->get_var( 'SELECT VERSION()' ) . ", prefix $p\n";
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

function innodb_status( $name ) {
	global $wpdb;
	return (int) ( $wpdb->get_row( "SHOW GLOBAL STATUS LIKE '" . $name . "'", ARRAY_A )['Value'] ?? 0 );
}

/** Server-wide deadlock counter: MySQL 8 has no Innodb_deadlocks status, it counts in INNODB_METRICS. */
function db_deadlocks() {
	global $wpdb;
	static $mysql = null;
	if ( null === $mysql ) { $mysql = false === stripos( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'mariadb' ); }
	if ( $mysql ) {
		$count = $wpdb->get_var( "SELECT `COUNT` FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'" );
		if ( null !== $count && '' === $wpdb->last_error ) { return (int) $count; }
	}
	return innodb_status( 'Innodb_deadlocks' );
}

/** Distributes ops over workers, runs them behind a start barrier and returns [results by op index, wall seconds]. */
function run_workers( array $per_worker, array $job_options = array() ) {
	global $last_db_stats, $cfg;
	$deadlocks = db_deadlocks();
	$lock_waits = innodb_status( 'Innodb_row_lock_waits' );
	$dir = sys_get_temp_dir() . '/wpcap-load-' . getmypid();
	if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
	$start_at = microtime( true ) + 1.0 + count( $per_worker ) * 0.02;
	$procs = array();
	foreach ( $per_worker as $w => $ops ) {
		if ( ! $ops ) { continue; }
		$file = "$dir/job-$w.json";
		file_put_contents( $file, json_encode( array( 'start_at' => $start_at, 'ops' => $ops, 'isolation' => $cfg['isolation'] ?? '' ) + $job_options ) );
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
			$rows = array();
		}
		foreach ( $rows as $row ) { $results[ $row['i'] ][] = $row; }
		$end = max( $end, microtime( true ) );
	}
	@rmdir( $dir );
	// An op without a result (worker crashed, fatal error, cut-off output) is an error, not a skipped request.
	$missing = 0;
	foreach ( $per_worker as $ops ) {
		foreach ( $ops as $op ) {
			if ( ! isset( $results[ $op['i'] ] ) ) {
				$results[ $op['i'] ][] = array( 'outcome' => 'error', 'detail' => 'no result (worker crashed)', 'ms' => 0.0, 'i' => $op['i'] );
				++$missing;
			}
		}
	}
	if ( $missing ) { fwrite( STDERR, "$missing ops without a result counted as errors\n" ); }
	// Server-wide counters: only meaningful when nothing else uses the database server meanwhile.
	$last_db_stats = array( 'deadlocks' => db_deadlocks() - $deadlocks, 'lock_waits' => innodb_status( 'Innodb_row_lock_waits' ) - $lock_waits );
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
		wpcap_test_reset_transients();
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

/** Signed Stripe "checkout.session.completed" body for a fixture order. */
function load_paid_event( array $order, $event_id ) {
	return json_encode( array( 'id' => $event_id, 'type' => 'checkout.session.completed', 'data' => array( 'object' => array(
		'id' => 'cs_test_' . $order['public_id'], 'object' => 'checkout.session', 'amount_total' => (int) $order['total_minor'], 'currency' => 'eur',
		'client_reference_id' => $order['public_id'], 'metadata' => array( 'wpcap_order' => $order['public_id'] ),
		'payment_intent' => 'pi_' . substr( $order['public_id'], 0, 12 ), 'payment_status' => 'paid', 'status' => 'complete',
	) ) ) );
}

/** Outcome counts per op tag. */
function load_by_tag( array $results, array $tag_of ) {
	$by_tag = array();
	foreach ( $results as $idx => $rows ) { foreach ( $rows as $row ) { $by_tag[ $tag_of[ $idx ] ][ $row['outcome'] ] = ( $by_tag[ $tag_of[ $idx ] ][ $row['outcome'] ] ?? 0 ) + 1; } }
	ksort( $by_tag );
	return $by_tag;
}

/* ---- Scenario 4: checkout burst + on-time webhooks + late webhooks + hold cleanup on one type ---- */
if ( in_array( $cfg['scenario'], array( 'all', 'mixed' ), true ) ) {
	$L = $cfg['late'];
	$P = $cfg['ontime'];
	$C = $cfg['capacity'];
	echo "\nScenario mixed: capacity $C, $L lapsed-hold orders (late webhooks), $P on-time orders, {$cfg['buyers']} new buyers, $W connections" . ( $cfg['isolation'] ? ", sessions {$cfg['isolation']}" : '' ) . "\n";
	$type = load_make_type( $C, 4, 1500 );
	$late = array();
	$ontime = array();
	for ( $e = 0; $e < $L + $P; $e++ ) {
		wpcap_test_reset_transients();
		$r = Orders::create( array( 'email' => "mix$e@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => "load-mix-$e-" . uniqid() ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 30, 'skip_rate_limit' => true ) );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, 'fixture order failed: ' . $r->get_error_message() . "\n" ); exit( 2 ); }
		if ( $e < $L ) { $late[] = (array) Orders::get( $r['order_id'] ); } else { $ontime[] = (array) Orders::get( $r['order_id'] ); }
	}
	$late_ids = implode( ',', array_map( static fn( $o ) => (int) $o['order_id'], $late ) ?: array( 0 ) );
	// The holds of the "late" orders lapsed; the cleanup has not run yet (it runs during the burst).
	$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE order_id IN ($late_ids)" );
	$ops = array();
	foreach ( array_merge( $late, $ontime ) as $e => $o ) {
		$ops[] = array( 'kind' => 'webhook', 'body' => load_paid_event( $o, "evt_load_mix_{$o['order_id']}" ), 'tag' => $e < $L ? 'late' : 'ontime' );
	}
	for ( $b = 0; $b < $cfg['buyers']; $b++ ) {
		$ops[] = array( 'kind' => 'buy', 'type' => $type, 'qty' => 1, 'key' => "load-mixbuy-$b-" . uniqid(), 'email' => "mixbuyer$b@example.com", 'tag' => 'buy' );
	}
	for ( $x = 0; $x < 5; $x++ ) { $ops[] = array( 'kind' => 'expire', 'tag' => 'expire' ); }
	shuffle( $ops );
	$per_worker = array_fill( 0, $W, array() );
	$tag_of = array();
	foreach ( $ops as $k => $op ) { $tag_of[ $k ] = $op['tag']; $per_worker[ $k % $W ][] = $op + array( 'i' => $k ); }
	list( $results, $wall ) = run_workers( $per_worker );
	$sold = (int) $wpdb->get_var( "SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id=$type AND o.payment_status='paid'" );
	$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type AND status='active' AND expires_at > UTC_TIMESTAMP()" );
	$tickets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_type_id=$type AND status='valid'" );
	$late_open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE order_id IN ($late_ids) AND payment_status IN ('pending','expired')" );
	$s = summarize( 'mixed (checkout+late pay)', $results, $wall, $W );
	echo '  by tag: ' . json_encode( load_by_tag( $results, $tag_of ) ) . "\n";
	invariant( 'no overselling: paid + active holds <= capacity', $sold + $held <= $C, ( $sold + $held ) . " of $C (paid $sold, held $held)" );
	invariant( 'valid tickets = paid units', $tickets === $sold, "$tickets tickets" );
	invariant( 'every late webhook was answered finally (paid or needs_review)', 0 === $late_open, "$late_open late orders still unpaid" );
	invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( error_samples( $results ) ) );
	$report[] = $s;
}

/* ---- Scenario 5: seat map, hot seats, late webhooks with lapsed seat holds, cleanups ---- */
if ( in_array( $cfg['scenario'], array( 'all', 'seats' ), true ) ) {
	load_seating();
	$N = min( $cfg['late'], $cfg['capacity'] );
	$SEATS = $cfg['capacity'];
	echo "\nScenario seats: $SEATS seats (type capacity 0 = the seat map is the limit), $N lapsed-hold orders with late webhooks, {$cfg['buyers']} buyers, $W connections" . ( $cfg['isolation'] ? ", sessions {$cfg['isolation']}" : '' ) . "\n";
	$type = load_make_type( 0, 4, 1500 );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	$created = PassCaptain\Seating\AdminPage::create( array( 'event_id' => $event, 'name' => 'Saal' ) );
	$seat_defs = array();
	for ( $n = 1; $n <= $SEATS; $n++ ) { $seat_defs[] = array( 'key' => "s$n", 'group' => 'g', 'row' => 'A', 'label' => (string) $n, 'x' => 10 * $n, 'y' => 10, 'zone' => 'z' ); }
	$draft = array( 'width' => max( 1000, 10 * $SEATS + 100 ), 'height' => 500, 'zones' => array( array( 'key' => 'z', 'name' => 'Z', 'color' => '#000000' ) ), 'categories' => array(), 'groups' => array( array( 'key' => 'g', 'type' => 'row', 'label' => '' ) ), 'seats' => $seat_defs );
	$published = PassCaptain\Seating\AdminPage::save( array( 'layout_id' => $created['layout_id'], 'draft' => json_encode( $draft ), 'do' => 'publish', 'upgrade_links' => '1' ) );
	if ( is_wp_error( $published ) ) { fwrite( STDERR, 'fixture seat map failed: ' . $published->get_error_message() . "\n" ); exit( 2 ); }
	PassCaptain\Seating\AdminPage::link( array( 'occurrence_id' => $occ, 'version_id' => $published['version_id'], 'zones' => array( $type => array( 'z' ) ) ) );
	$seat_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT seat_id FROM ' . PassCaptain\Seating\Layouts::table( 'seats' ) . ' WHERE version_id=%d ORDER BY seat_id', $published['version_id'] ) ) );
	$late = array();
	for ( $e = 0; $e < $N; $e++ ) {
		wpcap_test_reset_transients();
		$r = Orders::create( array( 'email' => "seatlate$e@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'seats' => array( $type => array( $seat_ids[ $e ] ) ), 'idempotency_key' => "load-seatlate-$e-" . uniqid() ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 30, 'skip_rate_limit' => true ) );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, 'fixture order failed: ' . $r->get_error_message() . "\n" ); exit( 2 ); }
		$late[] = (array) Orders::get( $r['order_id'] );
	}
	$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$late_ids = implode( ',', array_map( static fn( $o ) => (int) $o['order_id'], $late ) ?: array( 0 ) );
	$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='$past' WHERE order_id IN ($late_ids)" );
	$wpdb->query( 'UPDATE ' . PassCaptain\Seating\Layouts::table( 'claims' ) . " SET expires_at='$past' WHERE order_id IN ($late_ids)" );
	$ops = array();
	foreach ( $late as $o ) { $ops[] = array( 'kind' => 'webhook', 'body' => load_paid_event( $o, "evt_load_seat_{$o['order_id']}" ), 'tag' => 'late' ); }
	for ( $b = 0; $b < $cfg['buyers']; $b++ ) {
		$q = mt_rand( 1, 100 ) <= 70 ? 1 : 2;
		$hot = mt_rand( 1, 100 ) <= 60; // 60 % want one of the first N+5 seats (including the lapsed ones).
		$pick = array();
		while ( count( $pick ) < $q ) { $pick[ $seat_ids[ $hot ? mt_rand( 0, min( $SEATS, $N + 5 ) - 1 ) : mt_rand( 0, $SEATS - 1 ) ] ] = true; }
		$ops[] = array( 'kind' => 'seatbuy', 'type' => $type, 'seats' => array_keys( $pick ), 'key' => "load-seatbuy-$b-" . uniqid(), 'email' => "seatbuyer$b@example.com", 'tag' => 'buy' );
	}
	for ( $x = 0; $x < 5; $x++ ) { $ops[] = array( 'kind' => 'seatcleanup', 'tag' => 'cleanup' ); $ops[] = array( 'kind' => 'expire', 'tag' => 'expire' ); }
	shuffle( $ops );
	$per_worker = array_fill( 0, $W, array() );
	$tag_of = array();
	foreach ( $ops as $k => $op ) { $tag_of[ $k ] = $op['tag']; $per_worker[ $k % $W ][] = $op + array( 'i' => $k ); }
	list( $results, $wall ) = run_workers( $per_worker, array( 'seating' => true ) );
	$claims_table = PassCaptain\Seating\Layouts::table( 'claims' );
	$active_claims = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $claims_table WHERE occurrence_id=$occ AND active_key IS NOT NULL" );
	$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $claims_table c LEFT JOIN {$p}wpcap_orders o ON o.order_id=c.order_id WHERE c.occurrence_id=$occ AND c.active_key IS NOT NULL AND o.order_id IS NULL" );
	$valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_type_id=$type AND status='valid'" );
	$seated = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT seat_key) FROM ' . PassCaptain\Seating\Layouts::table( 'ticket_seats' ) . " WHERE occurrence_id=$occ AND status='assigned'" );
	$late_open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE order_id IN ($late_ids) AND payment_status IN ('pending','expired')" );
	$s = summarize( 'seats (claims+late pay)', $results, $wall, $W );
	echo '  by tag: ' . json_encode( load_by_tag( $results, $tag_of ) ) . "\n";
	invariant( 'active claims <= seats', $active_claims <= $SEATS, "$active_claims claims" );
	invariant( 'every valid ticket has its own seat', $valid === $seated, "$valid valid tickets, $seated distinct seats assigned" );
	invariant( 'no orphan claims', 0 === $orphans, "$orphans" );
	invariant( 'every late webhook was answered finally (paid or needs_review)', 0 === $late_open, "$late_open late orders still unpaid" );
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
