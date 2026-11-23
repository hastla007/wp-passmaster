<?php
/**
 * Worklist item A279: wrong service booking tokens count per client first; the per-booking lock is
 * short and generous, so guessing booking ids from one client no longer locks the real customer out.
 * Included from run.php after zz_a277.php (q1_event()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\RateLimit;
use PassCaptain\ServiceBookingFlow;

$q1s_saved_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$GLOBALS['wpcap_test_transients'] = array();

echo "A279: service booking token lockout\n";
PassCaptain\ServiceBookings::flush_schema_cache();
$q1s_res = PassCaptain\ServiceBookings::create_resource( q1_event(), array( 'name' => 'A279 Kajak', 'capacity' => 5 ) );
$q1s_book = static function ( $minutes, $email ) use ( $wpdb, $q1s_res ) {
	$start = time() + $minutes * 60;
	$wpdb->insert( "{$wpdb->prefix}wpcap_service_slots", array( 'resource_id' => $q1s_res, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + 3600 ), 'capacity' => 5, 'status' => 'open' ) );
	$GLOBALS['wpcap_test_transients'] = array();
	$r = PassCaptain\ServiceBookings::reserve( (int) $wpdb->insert_id, $email, 1, 0, 10, 'a279-' . uniqid( '', true ) );
	return is_array( $r ) ? array( (int) $r['booking_id'], (string) ( $r['token'] ?? '' ) ) : array( 0, '' );
};
list( $q1s_b1, $q1s_t1 ) = $q1s_book( 19 * 1440, 'a279-1@example.com' );
list( $q1s_b2, $q1s_t2 ) = $q1s_book( 19 * 1440 + 90, 'a279-2@example.com' );
list( $q1s_b3, $q1s_t3 ) = $q1s_book( 19 * 1440 + 180, 'a279-3@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
$q1s_code = static function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; };
check( 'A279 setup: two bookings with tokens', $q1s_b1 && $q1s_b2 && 64 === strlen( $q1s_t1 ) && 64 === strlen( $q1s_t2 ) );

$_SERVER['REMOTE_ADDR'] = '198.51.100.79'; // Attacker.
$q1s_codes = array();
for ( $i = 0; $i < ServiceBookingFlow::TOKEN_ATTEMPTS; $i++ ) { $q1s_codes[] = $q1s_code( ServiceBookingFlow::verify_token( $q1s_b1, str_repeat( 'a', 64 ) ) ); }
check( 'A279 wrong tokens are refused with 403 up to the client limit', array( 'wpcap_booking_access_denied' ) === array_values( array_unique( $q1s_codes ) ) );
check( 'A279 then the attacking client gets 429, also for other bookings', 'wpcap_booking_rate' === $q1s_code( ServiceBookingFlow::verify_token( $q1s_b1, str_repeat( 'b', 64 ) ) ) && 'wpcap_booking_rate' === $q1s_code( ServiceBookingFlow::verify_token( $q1s_b2, $q1s_t2 ) ) );

$_SERVER['REMOTE_ADDR'] = '203.0.113.79'; // Real customer.
check( 'A279 the real customer still opens the booking after the attack (was locked for 1 h)', 'ok' === $q1s_code( ServiceBookingFlow::verify_token( $q1s_b1, $q1s_t1 ) ) );

echo "A279: distributed guessing hits the short per-booking lock\n";
$GLOBALS['wpcap_test_filters']['wpcap_service_booking_token_limits'] = array( 'client' => 10, 'client_window' => 3600, 'booking' => 4, 'booking_window' => 600 );
for ( $i = 1; $i <= 4; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( 100 + $i );
	ServiceBookingFlow::verify_token( $q1s_b2, str_repeat( 'c', 64 ) );
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.80';
check( 'A279 after `booking` failures from many clients the booking answers 429 for the booking window, other bookings open', 'wpcap_booking_rate' === $q1s_code( ServiceBookingFlow::verify_token( $q1s_b2, $q1s_t2 ) ) && 'ok' === $q1s_code( ServiceBookingFlow::verify_token( $q1s_b3, $q1s_t3 ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_service_booking_token_limits'] );
check( 'A279 token_limits(): defaults 10/h per client, 50/10 min per booking', array( 'client' => 10, 'client_window' => 3600, 'booking' => 50, 'booking_window' => 600 ) === ServiceBookingFlow::token_limits() );

echo "A279/A288: RateLimit::hits() reads without counting\n";
$q1s_bucket = 'a279-hits-' . uniqid();
check( 'A288 hits() is 0 for a new bucket and does not count', 0 === RateLimit::hits( $q1s_bucket, 60 ) && 0 === RateLimit::hits( $q1s_bucket, 60 ) );
RateLimit::allow( $q1s_bucket, 5, 60 );
RateLimit::allow( $q1s_bucket, 5, 60 );
check( 'A288 hits() returns the hits of the current window', 2 === RateLimit::hits( $q1s_bucket, 60 ) );
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';
$q1s_db = 'a279-hits-db-' . uniqid();
RateLimit::allow( $q1s_db, 5, 60 );
RateLimit::allow( $q1s_db, 5, 60 );
RateLimit::allow( $q1s_db, 5, 60 );
check( 'A288 hits() with the database backend', 'db' === RateLimit::$last_backend && 3 === RateLimit::hits( $q1s_db, 60 ) && 0 === RateLimit::hits( $q1s_db . 'x', 60 ) );
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = getenv( 'WPCAP_TEST_RATE_BACKEND' ) ?: 'transient';

if ( null === $q1s_saved_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $q1s_saved_remote; }
$GLOBALS['wpcap_test_transients'] = array();
