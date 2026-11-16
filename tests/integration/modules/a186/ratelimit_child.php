<?php
/**
 * Concurrency worker for the A186 rate-limit test (not loaded by run.php's module glob).
 *
 * Usage: WPCAP_TEST_RATE_BACKEND=db php ratelimit_child.php <bucket> <limit> <calls> <start_unix_float>
 * Waits for the common start time, then calls RateLimit::allow() <calls> times and prints
 * "<allowed> <backend>".
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__, 2 ) . '/bootstrap.php';

$bucket = (string) ( $argv[1] ?? 'a186' );
$limit = (int) ( $argv[2] ?? 1 );
$calls = (int) ( $argv[3] ?? 1 );
$start = (float) ( $argv[4] ?? 0 );
// Barrier: every worker is booted and connected; start together.
while ( microtime( true ) < $start ) { usleep( 500 ); }
$allowed = 0;
for ( $i = 0; $i < $calls; $i++ ) {
	if ( PassCaptain\RateLimit::allow( $bucket, $limit, 100000000 ) ) { ++$allowed; }
}
echo $allowed . ' ' . PassCaptain\RateLimit::$last_backend;
