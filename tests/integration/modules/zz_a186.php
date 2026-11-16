<?php
/**
 * A186: client address behind trusted proxies (ClientIp) and rate limits that hold exactly across
 * parallel PHP workers (RateLimit database / object-cache backends).
 */

// phpcs:ignoreFile

use PassCaptain\ClientIp;
use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a186_server_before = $_SERVER;
$a186_settings_before = array( 'trusted_proxies' => Settings::get( 'trusted_proxies' ), 'client_ip_header' => Settings::get( 'client_ip_header' ) );
$a186_ip = static function ( array $server, $proxies = '', $header = 'x-forwarded-for' ) {
	Settings::update( array( 'trusted_proxies' => $proxies, 'client_ip_header' => $header ) );
	return ClientIp::get( $server );
};

echo "A186: client address and proxy headers\n";
$spoof = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_FORWARDED' => 'for=1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4' );
check( 'without trusted proxies every forwarding header is ignored', '198.51.100.9' === $a186_ip( $spoof ) && '198.51.100.9' === $a186_ip( $spoof, '', 'cf-connecting-ip' ) && '198.51.100.9' === $a186_ip( $spoof, '', 'forwarded' ) );
check( 'headers from a peer that is not a trusted proxy are ignored', '198.51.100.9' === $a186_ip( $spoof, '10.0.0.0/8' ) && '198.51.100.9' === $a186_ip( $spoof, '10.0.0.0/8', 'cf-connecting-ip' ) );
$xff = array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.7, 10.9.9.9' );
check( 'X-Forwarded-For: right-most untrusted address (client-supplied left part ignored)', '203.0.113.7' === $a186_ip( $xff, "10.0.0.0/8\n192.168.0.0/16" ) );
check( 'X-Forwarded-For: a chain of trusted proxies only yields its left-most hop', '10.0.0.5' === $a186_ip( array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5' ), '10.0.0.0/8' ) );
check( 'X-Forwarded-For: malformed entry or missing header falls back to REMOTE_ADDR', '10.1.2.3' === $a186_ip( array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7, garbage' ), '10.0.0.0/8' ) && '10.1.2.3' === $a186_ip( array( 'REMOTE_ADDR' => '10.1.2.3' ), '10.0.0.0/8' ) );
check( 'X-Forwarded-For: IPv4 with port, single trusted address, comma separated list', '192.0.2.1' === $a186_ip( array( 'REMOTE_ADDR' => '172.16.0.4', 'HTTP_X_FORWARDED_FOR' => '192.0.2.1:5555' ), '10.0.0.1, 172.16.0.4' ) );
check( 'IPv6 proxies (CIDR) and IPv4-mapped peers', '192.0.2.1' === $a186_ip( array( 'REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.1' ), '2001:db8::/32' ) && '192.0.2.2' === $a186_ip( array( 'REMOTE_ADDR' => '::ffff:10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '192.0.2.2' ), '10.0.0.0/8' ) );
$fwd = array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_FORWARDED' => 'for=192.0.2.60;proto=http;by=203.0.113.43, for="[2001:db8:cafe::17]:4711"' );
check( 'Forwarded (RFC 7239): right-most untrusted for=, IPv6 in brackets with port', '2001:db8:cafe::17' === $a186_ip( $fwd, '10.0.0.0/8', 'forwarded' ) );
check( 'Forwarded: obfuscated/unknown for= falls back to REMOTE_ADDR', '10.1.2.3' === $a186_ip( array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_FORWARDED' => 'for=unknown' ), '10.0.0.0/8', 'forwarded' ) && '10.1.2.3' === $a186_ip( array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_FORWARDED' => 'for=_hidden' ), '10.0.0.0/8', 'forwarded' ) );
$cf = array( 'REMOTE_ADDR' => '173.245.48.5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.77', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6' );
check( 'CF-Connecting-IP only from a trusted Cloudflare range; invalid value falls back', '198.51.100.77' === $a186_ip( $cf, '173.245.48.0/20', 'cf-connecting-ip' ) && '173.245.48.5' === $a186_ip( array( 'HTTP_CF_CONNECTING_IP' => 'nope' ) + $cf, '173.245.48.0/20', 'cf-connecting-ip' ) );
check( 'invalid proxy entries are ignored', array( '10.0.0.0/8', '2001:db8::/32' ) === ( function () { Settings::update( array( 'trusted_proxies' => "foo\n10.0.0.0/8\n10.0.0.0/33\n2001:db8::/32\n300.1.1.1" ) ); return ClientIp::trusted_proxies(); } )() );
check( 'missing or invalid REMOTE_ADDR gives an empty address', '' === $a186_ip( array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ), '10.0.0.0/8' ) && '' === $a186_ip( array( 'REMOTE_ADDR' => 'x' ), '10.0.0.0/8' ) );
Settings::update( array( 'trusted_proxies' => '10.0.0.0/8', 'client_ip_header' => 'x-forwarded-for' ) );
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
$a186_client_a = RateLimit::client();
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.51';
check( 'Orders::client_ip() and RateLimit::client() use the resolved client', '203.0.113.51' === Orders::client_ip() && $a186_client_a !== RateLimit::client() && 64 === strlen( RateLimit::client() ) && false === strpos( RateLimit::client(), '203.0.113' ) );

echo "A186: atomic rate-limit backends\n";
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';
$wpdb->query( "DELETE FROM {$p}wpcap_rate_limits" );
$a186_seq = array();
for ( $i = 0; $i < 4; $i++ ) { $a186_seq[] = RateLimit::allow( 'a186-db', 3, 3600 ); }
check( 'database backend: exactly the limit is allowed, then refused', array( true, true, true, false ) === $a186_seq && 'db' === RateLimit::$last_backend );
check( 'database backend: one row per bucket and window, counting every hit, key not reversible', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_rate_limits" ) && 4 === (int) $wpdb->get_var( "SELECT hits FROM {$p}wpcap_rate_limits" ) && 40 === strlen( (string) $wpdb->get_var( "SELECT rl_key FROM {$p}wpcap_rate_limits" ) ) );
$GLOBALS['wpcap_test_transients'] = array();
check( 'database counters survive a cleared transient/object cache', false === RateLimit::allow( 'a186-db', 3, 3600 ) );
$wpdb->insert( "{$p}wpcap_rate_limits", array( 'rl_key' => str_repeat( 'e', 40 ), 'hits' => 9, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
check( 'purge() removes counters of past windows only', 1 === RateLimit::purge() && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_rate_limits" ) && has_action( 'wpcap_cleanup_reservations' ) );

$wpdb->query( "RENAME TABLE {$p}wpcap_rate_limits TO {$p}wpcap_rate_limits_a186" );
$a186_fallback = RateLimit::allow( 'a186-missing', 1, 3600 ) && ! RateLimit::allow( 'a186-missing', 1, 3600 );
$a186_fallback_backend = RateLimit::$last_backend;
$wpdb->query( "RENAME TABLE {$p}wpcap_rate_limits_a186 TO {$p}wpcap_rate_limits" );
check( 'missing table (before the upgrade): transient fallback, still limiting', $a186_fallback && 'transient' === $a186_fallback_backend );

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) { if ( array_key_exists( "$group:$key", $GLOBALS['pf_cache'] ?? array() ) ) { return false; } $GLOBALS['pf_cache'][ "$group:$key" ] = $value; $GLOBALS['pf_cache_ttl'][ "$group:$key" ] = $ttl; return true; }
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, $offset = 1, $group = '' ) { if ( ! empty( $GLOBALS['a186_evict'] ) || ! array_key_exists( "$group:$key", $GLOBALS['pf_cache'] ?? array() ) ) { return false; } $GLOBALS['pf_cache'][ "$group:$key" ] += $offset; return $GLOBALS['pf_cache'][ "$group:$key" ]; }
}
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'cache';
$a186_seq = array( RateLimit::allow( 'a186-cache', 2, 120 ), RateLimit::allow( 'a186-cache', 2, 120 ), RateLimit::allow( 'a186-cache', 2, 120 ) );
$a186_ttls = array_filter( $GLOBALS['pf_cache_ttl'] ?? array(), static fn( $ttl, $k ) => 0 === strpos( $k, 'wpcap_rate:' ), ARRAY_FILTER_USE_BOTH );
check( 'object-cache backend: wp_cache_incr counts, window as TTL', array( true, true, false ) === $a186_seq && 'cache' === RateLimit::$last_backend && in_array( 120, $a186_ttls, true ) );
$GLOBALS['a186_evict'] = true;
RateLimit::allow( 'a186-evicted', 2, 120 );
unset( $GLOBALS['a186_evict'] );
check( 'object-cache backend: a failed increment is counted in the database instead', 'db' === RateLimit::$last_backend );

echo "A186: the limit holds exactly with parallel workers\n";
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';
$wpdb->query( "DELETE FROM {$p}wpcap_rate_limits" );
$a186_workers = 8;
$a186_calls = 30;
$a186_limit = 50;
$a186_start = microtime( true ) + 1.5;
$a186_env = array_merge( getenv(), array( 'WPCAP_TEST_RATE_BACKEND' => 'db' ) );
$a186_procs = array();
for ( $w = 0; $w < $a186_workers; $w++ ) {
	$proc = proc_open( array( PHP_BINARY, __DIR__ . '/a186/ratelimit_child.php', 'a186-parallel', (string) $a186_limit, (string) $a186_calls, sprintf( '%.3f', $a186_start ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $a186_env );
	$a186_procs[] = array( $proc, $pipes );
}
$a186_allowed = 0;
$a186_outputs = array();
foreach ( $a186_procs as list( $proc, $pipes ) ) {
	$out = trim( (string) stream_get_contents( $pipes[1] ) );
	$err = trim( (string) stream_get_contents( $pipes[2] ) );
	proc_close( $proc );
	$a186_outputs[] = $out . ( '' !== $err ? ' ERR:' . $err : '' );
	$a186_allowed += (int) $out;
}
$a186_backends = array_unique( array_map( static fn( $o ) => explode( ' ', $o . ' ' )[1], $a186_outputs ) );
$a186_hits = (int) $wpdb->get_var( "SELECT SUM(hits) FROM {$p}wpcap_rate_limits" );
check( "$a186_workers workers × $a186_calls calls, limit $a186_limit: exactly $a186_limit allowed (got $a186_allowed), every hit counted ($a186_hits)", $a186_limit === $a186_allowed && $a186_workers * $a186_calls === $a186_hits && array( 'db' ) === array_values( $a186_backends ) );
if ( $a186_limit !== $a186_allowed ) { echo '    worker output: ' . implode( ' | ', $a186_outputs ) . "\n"; }

echo "A186: checkout limit per real client behind a proxy (database backend)\n";
$wpdb->query( "DELETE FROM {$p}wpcap_rate_limits" );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = 2;
$a186_t = make_type( 50, 10, 1000 );
$a186_buy = static function ( $client, $n ) use ( $a186_t ) {
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, ' . $client; // Left part is client-supplied and ignored.
	return Orders::create( array( 'email' => "a186-$n@example.com", 'items' => array( array( 'ticket_type_id' => $a186_t, 'quantity' => 1 ) ), 'idempotency_key' => 'a186-' . $n . '-' . uniqid( '', true ) ) );
};
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$a1 = $a186_buy( '203.0.113.60', 1 );
$a2 = $a186_buy( '203.0.113.60', 2 );
$a3 = $a186_buy( '203.0.113.60', 3 );
$b1 = $a186_buy( '203.0.113.61', 4 );
check( 'checkout limit applies per resolved client, atomically in the database', ! is_wp_error( $a1 ) && ! is_wp_error( $a2 ) && is_wp_error( $a3 ) && 429 === $a3->get_error_data()['status'] && ! is_wp_error( $b1 ) && 'db' === RateLimit::$last_backend );
Settings::update( array( 'trusted_proxies' => '' ) );
$c1 = $a186_buy( '203.0.113.62', 5 );
$c2 = $a186_buy( '203.0.113.63', 6 );
$c3 = $a186_buy( '203.0.113.64', 7 );
check( 'without a trusted proxy all clients behind it share the proxy address (and its limit)', ! is_wp_error( $c1 ) && ! is_wp_error( $c2 ) && is_wp_error( $c3 ) && 429 === $c3->get_error_data()['status'] );

unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] );
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = getenv( 'WPCAP_TEST_RATE_BACKEND' ) ?: 'transient';
Settings::update( $a186_settings_before );
$_SERVER = $a186_server_before;
