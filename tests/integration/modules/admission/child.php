<?php
/**
 * Concurrency worker for tests/integration/modules/admission.php: waits until a common start time,
 * then checks one ticket in and prints OK or ERR:<result>.
 * Usage: php child.php <ticket code> <start microtime>
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';
$GLOBALS['wpcap_test_user_id'] = 1;
$start = (float) ( $argv[2] ?? 0 );
while ( microtime( true ) < $start ) { usleep( 2000 ); }
$r = PassCaptain\Checkin::perform( $argv[1] ?? '', array( 'source' => 'web', 'idempotency_key' => 'par-' . getmypid() . '-' . uniqid( '', true ) ) );
echo is_wp_error( $r ) ? 'ERR:' . ( $r->get_error_data()['result'] ?? '?' ) : 'OK';
