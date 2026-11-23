<?php
/**
 * A376: seat map links and buyer limits are loaded for the occurrences/events a request needs (one
 * IN query per listing via `wpcap_listing_occurrences`, or one lookup per id), not the whole history.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Allocations\Holds;
use PassCaptain\Seating\Layouts;

global $wpdb;
echo "A376: seat links and buyer limits only for the requested ids\n";

$x2_a376_links = Layouts::table( 'links' );
$x2_a376_limits = Holds::table( 'limits' );
$x2_a376_now = gmdate( 'Y-m-d H:i:s' );
// "History": 30 links/limits of other (old) occurrences and events.
for ( $i = 1; $i <= 30; $i++ ) {
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $x2_a376_links (occurrence_id,layout_id,version_id,updated_at) VALUES (%d,%d,%d,%s)", 9900000 + $i, 77, 78, $x2_a376_now ) );
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $x2_a376_limits (event_id,ticket_type_id,max_per_buyer,updated_at) VALUES (%d,0,4,%s)", 9900000 + $i, $x2_a376_now ) );
}

$x2_a376_rows = static function ( callable $run ) {
	$GLOBALS['wpcap_test_query_log'] = array();
	$run();
	$log = $GLOBALS['wpcap_test_query_log'];
	unset( $GLOBALS['wpcap_test_query_log'] );
	return $log;
};

Layouts::flush_links();
$x2_a376_log = $x2_a376_rows( static function () { Layouts::prime_links( array( 9900001, 9900002, 9999999 ) ); } );
$x2_a376_after = $x2_a376_rows( static function () use ( &$x2_a376_found ) { $x2_a376_found = array( Layouts::link( 9900001 ), Layouts::link( 9900002 ), Layouts::link( 9999999 ) ); } );
check( 'A376: a listing primes its seat links in one IN query', 1 === count( $x2_a376_log ) && false !== strpos( $x2_a376_log[0], 'IN (9900001,9900002,9999999)' ) );
check( 'A376: primed links (also "no link") need no further query', array() === $x2_a376_after && 9900001 === (int) $x2_a376_found[0]->occurrence_id && null === $x2_a376_found[2] );
Layouts::flush_links();
$x2_a376_one = $x2_a376_rows( static function () { Layouts::link( 9900005 ); Layouts::link( 9900005 ); } );
check( 'A376: link() alone reads only that occurrence, once', 1 === count( $x2_a376_one ) && false !== strpos( $x2_a376_one[0], 'IN (9900005)' ) );

BuyerLimits::flush();
$x2_a376_log = $x2_a376_rows( static function () { BuyerLimits::prime( array( 9900003, 9900004 ) ); } );
$x2_a376_after = $x2_a376_rows( static function () use ( &$x2_a376_lim ) { $x2_a376_lim = array( BuyerLimits::for_event( 9900003 ), BuyerLimits::for_event( 9900004 ) ); } );
check( 'A376: a listing primes the buyer limits of its events in one query', 1 === count( $x2_a376_log ) && false !== strpos( $x2_a376_log[0], 'IN (9900003,9900004)' ) );
check( 'A376: limits come from the cache afterwards', array() === $x2_a376_after && array( 0 => 4 ) === $x2_a376_lim[0] && array( 0 => 4 ) === $x2_a376_lim[1] );
BuyerLimits::flush();
$x2_a376_one = $x2_a376_rows( static function () { BuyerLimits::for_event( 9900006 ); BuyerLimits::for_event( 9900006 ); BuyerLimits::for_event( 9999998 ); } );
check( 'A376: for_event() reads only the requested events (no table scan of all limits)', 2 === count( $x2_a376_one ) && false !== strpos( $x2_a376_one[0], 'IN (9900006)' ) && array() === BuyerLimits::for_event( 9999998 ) );

// The listing announces its occurrences; both modules prime from that action.
$x2_a376_src = file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' ) . file_get_contents( WPCAP_PATH . 'src/Modules/Seating.php' ) . file_get_contents( WPCAP_PATH . 'src/Modules/Allocations.php' );
check( 'A376: [wpcap_events] fires wpcap_listing_occurrences, Seating and Allocations prime from it', false !== strpos( $x2_a376_src, "do_action( 'wpcap_listing_occurrences'" ) && false !== strpos( $x2_a376_src, "add_action( 'wpcap_listing_occurrences', array( \\PassCaptain\\Seating\\Layouts::class, 'prime_links' )" ) && false !== strpos( $x2_a376_src, 'BuyerLimits::prime( (array) $event_ids )' ) );

$wpdb->query( "DELETE FROM $x2_a376_links WHERE occurrence_id BETWEEN 9900001 AND 9900030" );
$wpdb->query( "DELETE FROM $x2_a376_limits WHERE event_id BETWEEN 9900001 AND 9900030" );
Layouts::flush_links();
BuyerLimits::flush();
