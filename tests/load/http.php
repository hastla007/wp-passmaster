<?php
/**
 * End-to-end load test through the real WordPress REST API (full WP + plugin bootstrap per request).
 *
 * Starts a private multi-worker PHP web server for the given WordPress install (php -S with
 * PHP_CLI_SERVER_WORKERS), creates fixture data directly via the plugin, fires concurrent requests
 * with curl_multi and verifies the database afterwards:
 *
 *   checkout  POST /pass-captain/v1/checkout              (B buyers, limited capacity, double submits)
 *   check-in  POST /pass-captain/v1/tickets/check-in      (T tickets x D devices, cookie + nonce auth)
 *   webhooks  POST /pass-captain/v1/payments/stripe/webhook (E events x R signed duplicate deliveries)
 *
 * Usage: php tests/load/http.php --wp=/path/to/wordpress [--server-workers=8] [--concurrency=32]
 *          [--buyers=300] [--capacity=100] [--tickets=10] [--devices=10] [--events=30] [--dupes=5]
 *          [--port=8091] [--json=/path/result.json]
 *
 * Nothing in the site's settings is changed: the webhook secret is passed to the private server as
 * WPCAP_STRIPE_WEBHOOK_SECRET and the per-IP checkout limit is lifted by a router script that only
 * that server uses. Fixture events are set to "draft" at the end. Use a disposable install only.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

// Unique per run: open manual-payment orders of an earlier run must not block the same buyers.
$run_id = substr( md5( uniqid( '', true ) ), 0, 8 );
$opts = getopt( '', array( 'wp:', 'server-workers:', 'concurrency:', 'buyers:', 'capacity:', 'tickets:', 'devices:', 'events:', 'dupes:', 'port:', 'json:', 'router' ) );

/* ---- Router mode: runs inside the private php -S server. ---- */
if ( PHP_SAPI === 'cli-server' ) {
	$root = getenv( 'WPCAP_LOAD_WP' );
	$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	if ( '/' !== $path && is_file( $root . $path ) && ! str_ends_with( $path, '.php' ) ) { return false; }
	// Pre-initialised hook (WordPress converts $wp_filter arrays into WP_Hook objects on load).
	// All buyers come from 127.0.0.1: lift the per-client limits (only on this private server).
	foreach ( array( 'wpcap_checkout_ip_limit', 'wpcap_manual_orders_per_client', 'wpcap_manual_open_orders_per_email' ) as $hook ) {
		$GLOBALS['wp_filter'][ $hook ][10]['wpcap_load'] = array( 'function' => static fn() => PHP_INT_MAX, 'accepted_args' => 1 );
	}
	chdir( $root );
	$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
	require $root . '/index.php';
	return true;
}

$root = rtrim( (string) ( $opts['wp'] ?? '' ), '/' );
if ( ! $root || ! is_file( "$root/wp-load.php" ) ) { fwrite( STDERR, "Usage: php tests/load/http.php --wp=/path/to/wordpress\n" ); exit( 2 ); }
$cfg = array(
	'server_workers' => (int) ( $opts['server-workers'] ?? 8 ), 'concurrency' => (int) ( $opts['concurrency'] ?? 32 ),
	'buyers' => (int) ( $opts['buyers'] ?? 300 ), 'capacity' => (int) ( $opts['capacity'] ?? 100 ),
	'tickets' => (int) ( $opts['tickets'] ?? 10 ), 'devices' => (int) ( $opts['devices'] ?? 10 ),
	'events' => (int) ( $opts['events'] ?? 30 ), 'dupes' => (int) ( $opts['dupes'] ?? 5 ), 'port' => (int) ( $opts['port'] ?? 8091 ),
);
$secret = 'whsec_' . bin2hex( random_bytes( 12 ) );

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define( 'WP_USE_THEMES', false );
require "$root/wp-load.php";

use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
$failures = array();
function invariant( $name, $ok, $detail = '' ) {
	global $failures;
	printf( "  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, '' !== $detail ? " ($detail)" : '' );
	if ( ! $ok ) { $failures[] = $name; }
}

/* ---- Private server ---- */
$base = "http://127.0.0.1:{$cfg['port']}";
$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$cfg['port']}", '-t', $root, __FILE__ ),
	array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$pipes, $root,
	array_merge( getenv(), array( 'PHP_CLI_SERVER_WORKERS' => (string) $cfg['server_workers'], 'WPCAP_LOAD_WP' => $root, 'WPCAP_STRIPE_WEBHOOK_SECRET' => $secret ) )
);
$created_events = array();
register_shutdown_function( static function () use ( &$server, &$created_events, $wpdb, $p ) {
	if ( is_resource( $server ) ) {
		$status = proc_get_status( $server );
		// php -S forks its workers; stop the whole process group.
		exec( 'pkill -TERM -P ' . (int) $status['pid'] . ' 2>/dev/null' );
		proc_terminate( $server );
		proc_close( $server );
	}
	if ( $created_events ) { $wpdb->query( "UPDATE {$p}wpcap_events SET status='draft' WHERE event_id IN (" . implode( ',', array_map( 'intval', $created_events ) ) . ')' ); }
} );
for ( $i = 0; $i < 50; $i++ ) {
	usleep( 100000 );
	$ch = curl_init( "$base/?rest_route=/pass-captain/v1/checkout/options" );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5 ) );
	curl_exec( $ch );
	if ( 200 === curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ) { break; }
}

echo 'Pass Captain HTTP load test – ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . ", server $base with {$cfg['server_workers']} workers, client concurrency {$cfg['concurrency']}\n";

function load_event( $name, array $types ) {
	global $wpdb, $created_events;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => $name, 'slug' => 'load-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$created_events[] = $event;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$ids = array();
	foreach ( $types as list( $price, $cap ) ) {
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Lasttest', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $cap, 'max_per_order' => 10, 'status' => 'active' ) );
		$ids[] = (int) $wpdb->insert_id;
	}
	return $ids;
}

/** Fires requests with a sliding window of $concurrency; returns [rows by index, wall seconds]. */
function fire( array $requests, $concurrency ) {
	$mh = curl_multi_init();
	$queue = $requests;
	$active = array();
	$rows = array();
	$t0 = microtime( true );
	$add = static function () use ( &$queue, &$active, $mh ) {
		$req = array_shift( $queue );
		$ch = curl_init( $req['url'] );
		curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $req['body'], CURLOPT_HTTPHEADER => $req['headers'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ) );
		curl_multi_add_handle( $mh, $ch );
		$active[ (int) $ch ] = array( $ch, $req );
	};
	while ( $queue && count( $active ) < $concurrency ) { $add(); }
	do {
		curl_multi_exec( $mh, $running );
		curl_multi_select( $mh, 0.05 );
		while ( $info = curl_multi_info_read( $mh ) ) {
			$ch = $info['handle'];
			list( , $req ) = $active[ (int) $ch ];
			unset( $active[ (int) $ch ] );
			$rows[ $req['i'] ] = array( 'code' => curl_getinfo( $ch, CURLINFO_HTTP_CODE ), 'ms' => curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000, 'body' => json_decode( (string) curl_multi_getcontent( $ch ), true ) ?: array() );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
			if ( $queue ) { $add(); }
		}
	} while ( $active );
	curl_multi_close( $mh );
	return array( $rows, max( 0.001, microtime( true ) - $t0 ) );
}

function stats( $name, array $rows, $wall, callable $classify ) {
	$ms = array_column( $rows, 'ms' );
	sort( $ms );
	$pct = static fn( $q ) => $ms ? $ms[ max( 0, (int) ceil( $q / 100 * count( $ms ) ) - 1 ) ] : 0;
	$outcomes = array();
	foreach ( $rows as $r ) { $o = $classify( $r ); $outcomes[ $o ] = ( $outcomes[ $o ] ?? 0 ) + 1; }
	ksort( $outcomes );
	$n = count( $rows );
	return array( 'scenario' => $name, 'requests' => $n, 'wall_s' => round( $wall, 2 ), 'rps' => round( $n / $wall, 1 ), 'p50_ms' => round( $pct( 50 ), 1 ), 'p95_ms' => round( $pct( 95 ), 1 ), 'p99_ms' => round( $pct( 99 ), 1 ), 'max_ms' => round( $ms ? end( $ms ) : 0, 1 ), 'errors' => $outcomes['error'] ?? 0, 'error_rate' => $n ? round( 100 * ( $outcomes['error'] ?? 0 ) / $n, 2 ) : 0, 'outcomes' => $outcomes );
}

$report = array();
$json = array( 'Content-Type: application/json' );
mt_srand( 20261111 );

/* ---- checkout ---- */
echo "\nScenario checkout: {$cfg['buyers']} buyers, capacity {$cfg['capacity']}\n";
list( $type ) = load_event( 'Lasttest HTTP Checkout', array( array( 2500, $cfg['capacity'] ) ) );
$reqs = array();
$keys = array();
$i = 0;
for ( $b = 0; $b < $cfg['buyers']; $b++ ) {
	$qty = mt_rand( 1, 100 ) <= 70 ? 1 : mt_rand( 2, 4 );
	$key = 'http-load-' . $b . '-' . bin2hex( random_bytes( 4 ) );
	$keys[ $key ] = $qty;
	$body = json_encode( array( 'email' => "httpbuyer$b-{$run_id}@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => $key, 'accept_terms' => true, 'provider' => 'manual' ) );
	$reqs[] = array( 'i' => $i++, 'key' => $key, 'url' => "$base/?rest_route=/pass-captain/v1/checkout", 'body' => $body, 'headers' => $json );
	if ( mt_rand( 1, 100 ) <= 15 ) { $reqs[] = array( 'i' => $i++, 'key' => $key, 'url' => "$base/?rest_route=/pass-captain/v1/checkout", 'body' => $body, 'headers' => $json ); }
}
list( $rows, $wall ) = fire( $reqs, $cfg['concurrency'] );
$by_key = array();
foreach ( $reqs as $r ) { if ( ! empty( $rows[ $r['i'] ]['body']['order_id'] ) ) { $by_key[ $r['key'] ][] = (int) $rows[ $r['i'] ]['body']['order_id']; } }
$split = count( array_filter( $by_key, static fn( $ids ) => count( array_unique( $ids ) ) > 1 ) );
$sold = array_sum( array_intersect_key( $keys, $by_key ) );
$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type AND status='active'" );
$s = stats( 'checkout (REST)', $rows, $wall, static fn( $r ) => 201 === $r['code'] ? 'created' : ( 200 === $r['code'] ? 'reused' : ( 409 === $r['code'] && in_array( (string) ( $r['body']['data']['reason'] ?? '' ), array( 'sold_out', 'occurrence_sold_out' ), true ) ? 'sold_out' : 'error' ) ) );
invariant( 'no overselling', $held <= $cfg['capacity'], "held $held of {$cfg['capacity']}" );
invariant( 'held = tickets of successful orders', $held === $sold, "sold $sold" );
invariant( 'one order per idempotency key', 0 === $split );
invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( array_count_values( array_map( static fn( $r ) => $r['code'] . ' ' . ( $r['body']['code'] ?? '' ), array_filter( $rows, static fn( $r ) => ! in_array( $r['code'], array( 200, 201, 409 ), true ) ) ) ) ) );
$report[] = $s;

/* ---- check-in ---- */
echo "\nScenario check-in: {$cfg['tickets']} tickets x {$cfg['devices']} concurrent scans\n";
list( $free ) = load_event( 'Lasttest HTTP Einlass', array( array( 0, $cfg['tickets'] ) ) );
$codes = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$codes ) {
	if ( preg_match_all( '/wpcap_ticket=([a-f0-9]{64})/', (string) $atts['message'], $m ) ) { $codes = array_merge( $codes, $m[1] ); }
	return true;
}, 1, 2 );
$order_ids = array();
for ( $left = $cfg['tickets']; $left > 0; $left -= 10 ) {
	$o = Orders::create( array( 'email' => 'httpdoor@example.com', 'items' => array( array( 'ticket_type_id' => $free, 'quantity' => min( 10, $left ) ) ), 'idempotency_key' => 'http-door-' . uniqid( '', true ), 'accept_terms' => true ), array( 'skip_rate_limit' => true ) );
	if ( ! is_wp_error( $o ) ) { $order_ids[] = (int) $o['order_id']; }
}
$codes = array_values( array_unique( $codes ) );
invariant( 'fixture: ticket codes issued', count( $codes ) === $cfg['tickets'], count( $codes ) . ' codes' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$expiration = time() + HOUR_IN_SECONDS;
$session = WP_Session_Tokens::get_instance( $admin->ID )->create( $expiration );
$cookie = wp_generate_auth_cookie( $admin->ID, $expiration, 'logged_in', $session );
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
$nonce = wp_create_nonce( 'wp_rest' );
$auth = array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $nonce, 'Cookie: ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $cookie ) );
$reqs = array();
$ticket_of = array();
$i = 0;
foreach ( $codes as $t => $code ) {
	for ( $d = 0; $d < $cfg['devices']; $d++ ) {
		$ticket_of[ $i ] = $t;
		$reqs[] = array( 'i' => $i++, 'url' => "$base/?rest_route=/pass-captain/v1/tickets/check-in", 'body' => json_encode( array( 'token' => $code, 'idempotency_key' => "http-dev$d-$t-" . uniqid() ) ), 'headers' => $auth );
	}
}
list( $rows, $wall ) = fire( $reqs, $cfg['concurrency'] );
$admitted = array_fill( 0, count( $codes ), 0 );
foreach ( $rows as $idx => $r ) { if ( 200 === $r['code'] && 'checked_in' === ( $r['body']['status'] ?? '' ) ) { $admitted[ $ticket_of[ $idx ] ]++; } }
$s = stats( 'check-in (REST)', $rows, $wall, static fn( $r ) => 200 === $r['code'] ? 'admitted' : ( 'already_checked_in' === ( $r['body']['data']['result'] ?? '' ) ? 'rejected_duplicate' : 'error' ) );
$db = $order_ids ? $wpdb->get_col( "SELECT COUNT(*) FROM {$p}wpcap_checkins c JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id WHERE t.order_id IN (" . implode( ',', $order_ids ) . ") AND c.result='checked_in' GROUP BY c.ticket_id" ) : array();
invariant( 'exactly one successful scan per ticket', $codes && ! array_filter( $admitted, static fn( $n ) => 1 !== $n ), 'per ticket: ' . implode( ',', array_unique( $admitted ) ) );
invariant( 'exactly one admission row per ticket', count( $db ) === count( $codes ) && array_unique( array_map( 'intval', $db ) ) === array( 1 ) );
invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( array_count_values( array_map( static fn( $r ) => $r['code'] . ' ' . ( $r['body']['code'] ?? '' ), array_filter( $rows, static fn( $r ) => 200 !== $r['code'] && 'already_checked_in' !== ( $r['body']['data']['result'] ?? '' ) ) ) ) ) );
WP_Session_Tokens::get_instance( $admin->ID )->destroy( $session );
$report[] = $s;

/* ---- webhooks ---- */
echo "\nScenario webhooks: {$cfg['events']} Stripe events x {$cfg['dupes']} deliveries\n";
list( $paid_type ) = load_event( 'Lasttest HTTP Webhooks', array( array( 1500, $cfg['events'] * 2 ) ) );
$orders = array();
for ( $e = 0; $e < $cfg['events']; $e++ ) {
	$o = Orders::create( array( 'email' => "httphook$e@example.com", 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 2 ) ), 'idempotency_key' => "http-hook-$e-" . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
	$orders[] = (array) Orders::get( $o['order_id'] );
}
$reqs = array();
$i = 0;
foreach ( $orders as $e => $o ) {
	$body = json_encode( array( 'id' => 'evt_http_' . $o['public_id'], 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'id' => 'cs_test_' . $o['public_id'], 'object' => 'checkout.session', 'amount_total' => (int) $o['total_minor'], 'currency' => 'eur', 'client_reference_id' => $o['public_id'], 'metadata' => array( 'wpcap_order' => $o['public_id'] ), 'payment_intent' => 'pi_' . substr( $o['public_id'], 0, 12 ), 'payment_status' => 'paid', 'status' => 'complete' ) ) ) );
	for ( $r = 0; $r < $cfg['dupes']; $r++ ) {
		$t = time();
		$reqs[] = array( 'i' => $i++, 'url' => "$base/?rest_route=/pass-captain/v1/payments/stripe/webhook", 'body' => $body, 'headers' => array( 'Content-Type: application/json', "Stripe-Signature: t=$t,v1=" . hash_hmac( 'sha256', "$t.$body", $secret ) ) );
	}
}
list( $rows, $wall ) = fire( $reqs, $cfg['concurrency'] );
// 503 wpcap_webhook_in_progress: the same event is still being processed by a parallel delivery (A183).
$s = stats( 'webhook (REST)', $rows, $wall, static fn( $r ) => 200 === $r['code'] ? (string) ( $r['body']['result'] ?? 'ok' ) : ( 'wpcap_webhook_in_progress' === ( $r['body']['code'] ?? '' ) ? 'in_flight' : 'error' ) );
$ids = implode( ',', array_map( static fn( $o ) => (int) $o['order_id'], $orders ) );
$paid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE order_id IN ($ids) AND payment_status='paid'" );
$tickets = $wpdb->get_col( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($ids) GROUP BY order_id" );
invariant( 'every event applied exactly once', ( $s['outcomes']['paid'] ?? 0 ) === $cfg['events'], ( $s['outcomes']['paid'] ?? 0 ) . ' paid results' );
invariant( 'all orders paid, 2 tickets each', $paid === $cfg['events'] && count( $tickets ) === $cfg['events'] && array_unique( array_map( 'intval', $tickets ) ) === array( 2 ) );
invariant( 'no unexpected errors', 0 === $s['errors'], json_encode( array_count_values( array_map( static fn( $r ) => $r['code'] . ' ' . ( $r['body']['code'] ?? '' ), array_filter( $rows, static fn( $r ) => 200 !== $r['code'] ) ) ) ) );
$report[] = $s;

echo "\n" . str_pad( 'scenario', 20 ) . str_pad( 'requests', 10 ) . str_pad( 'wall_s', 9 ) . str_pad( 'rps', 9 ) . str_pad( 'p50_ms', 9 ) . str_pad( 'p95_ms', 9 ) . str_pad( 'p99_ms', 9 ) . str_pad( 'max_ms', 9 ) . "errors %\n" . str_repeat( '-', 92 ) . "\n";
foreach ( $report as $r ) {
	echo str_pad( $r['scenario'], 20 ) . str_pad( (string) $r['requests'], 10 ) . str_pad( (string) $r['wall_s'], 9 ) . str_pad( (string) $r['rps'], 9 ) . str_pad( (string) $r['p50_ms'], 9 ) . str_pad( (string) $r['p95_ms'], 9 ) . str_pad( (string) $r['p99_ms'], 9 ) . str_pad( (string) $r['max_ms'], 9 ) . $r['error_rate'] . "\n";
	echo '    outcomes: ' . json_encode( $r['outcomes'] ) . "\n";
}
if ( isset( $opts['json'] ) ) { file_put_contents( $opts['json'], json_encode( array( 'config' => $cfg, 'results' => $report, 'failures' => $failures ), JSON_PRETTY_PRINT ) ); }
echo "\n" . ( $failures ? count( $failures ) . ' invariant(s) violated: ' . implode( '; ', $failures ) : 'All invariants hold.' ) . "\n";
exit( $failures ? 1 : 0 );
