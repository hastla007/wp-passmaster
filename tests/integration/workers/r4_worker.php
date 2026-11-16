<?php
/**
 * Child process for the R4 tests (tests/integration/modules/zzzzzzz_r4_scanner.php).
 *
 * Usage: php r4_worker.php checkin <start_at_unix_float> <code> <idempotency_key> <source> [user_id]
 *   One Checkin::perform() on its own database connection after a common start time; prints
 *   OK, OK(replay) or ERR:<result>.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require dirname( __DIR__ ) . '/bootstrap.php';

// Options live in memory in the test bootstrap: the fixture occurrence lies outside the default entry window.
PassCaptain\Settings::update( array( 'default_entry_window' => 0 ) );
$start = (float) ( $argv[2] ?? 0 );
while ( microtime( true ) < $start ) { usleep( 500 ); }
$GLOBALS['wpcap_test_user_id'] = (int) ( $argv[6] ?? 1 );
$context = array( 'source' => (string) ( $argv[5] ?? 'app' ), 'idempotency_key' => (string) ( $argv[4] ?? '' ) );
if ( 'offline' === $context['source'] ) { $context['scanned_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 ); }
$r = PassCaptain\Checkin::perform( (string) ( $argv[3] ?? '' ), $context );
echo is_wp_error( $r ) ? 'ERR:' . ( $r->get_error_data()['result'] ?? $r->get_error_code() ) : 'OK' . ( ! empty( $r['replayed'] ) ? '(replay)' : '' );
