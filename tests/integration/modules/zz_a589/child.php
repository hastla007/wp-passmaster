<?php
/**
 * Concurrency worker for tests/integration/modules/zz_a589_a594.php (A589): waits until a common start
 * time, then checks one ticket in with the GIVEN idempotency key (an app retry racing the first
 * request) and prints OK, REPLAY or ERR:<result>.
 * Usage: php child.php <ticket code> <start microtime> <idempotency key>
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';
$GLOBALS['wpcap_test_user_id'] = 1;
$start = (float) ( $argv[2] ?? 0 );
while ( microtime( true ) < $start ) { usleep( 2000 ); }
$r = PassCaptain\Checkin::perform( $argv[1] ?? '', array( 'source' => 'app', 'idempotency_key' => (string) ( $argv[3] ?? '' ) ) );
echo is_wp_error( $r ) ? 'ERR:' . ( $r->get_error_data()['result'] ?? $r->get_error_code() ) : ( empty( $r['replayed'] ) ? 'OK' : 'REPLAY' );
