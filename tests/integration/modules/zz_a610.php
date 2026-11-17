<?php
// phpcs:ignoreFile
/**
 * A610: the seat map endpoint of a cancelled date, or of one whose online sale is over, answers with
 * its state (occurrence_status, sales_open false, a message) instead of 404 – the picker shows the
 * reason and locks the order (tests/js/seating-picker.test.js). Bookable dates carry
 * occurrence_status "scheduled" and sales_open true; dates without a map stay 404.
 */

use PassCaptain\Seating\AdminPage as A610SeatingAdmin;
use PassCaptain\Seating\Layouts;
use PassCaptain\Seating\Reservations;
use PassCaptain\Seating\Rest as A610SeatingRest;

if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $d ) { return $d; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }

echo "A610: seat map of cancelled and past dates\n";
global $wpdb;
$p = $wpdb->prefix;
$a610_type = make_type( 10, 5, 2000 );
$a610_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a610_type" );
$a610_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a610_occ" );
$a610_layout = A610SeatingAdmin::create( array( 'event_id' => $a610_event, 'name' => 'A610 Saal' ) );
$a610_draft = array(
	'width' => 400, 'height' => 200,
	'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett', 'color' => '#2271b1' ) ),
	'categories' => array( array( 'key' => 'kat1', 'name' => 'Kategorie 1', 'color' => '#00a32a' ) ),
	'groups' => array( array( 'key' => 'ga', 'type' => 'row', 'label' => '' ) ),
	'seats' => array(
		array( 'key' => 'a1', 'group' => 'ga', 'row' => 'Reihe A', 'label' => '1', 'x' => 40, 'y' => 60, 'zone' => 'parkett', 'category' => 'kat1' ),
		array( 'key' => 'a2', 'group' => 'ga', 'row' => 'Reihe A', 'label' => '2', 'x' => 80, 'y' => 60, 'zone' => 'parkett', 'category' => 'kat1' ),
	),
);
$a610_pub = A610SeatingAdmin::save( array( 'layout_id' => $a610_layout['layout_id'] ?? 0, 'draft' => json_encode( $a610_draft ), 'do' => 'publish' ) );
$a610_link = A610SeatingAdmin::link( array( 'occurrence_id' => $a610_occ, 'version_id' => $a610_pub['version_id'] ?? 0, 'zones' => array( $a610_type => array( 'parkett' ) ) ) );
check( 'A610 fixture: date linked to a seat map', ! is_wp_error( $a610_pub ) && ! is_wp_error( $a610_link ) && Layouts::link( $a610_occ ) );
$a610_get = static function ( $occ ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$GLOBALS['wpcap_test_filters']['wpcap_seating_availability_limit'] = 100000; // Other tests may have used the per-client budget (DB backend).
	$r = A610SeatingRest::availability( array( 'occurrence_id' => $occ ) );
	return is_object( $r ) && method_exists( $r, 'get_data' ) ? $r->get_data() : $r; // WP_REST_Response stub of other tests.
};

$a610_open = $a610_get( $a610_occ );
check( 'A610 a bookable date: the map with occurrence_status scheduled and sales_open true' . ( is_wp_error( $a610_open ) ? ' – ' . $a610_open->get_error_code() : '' ), is_array( $a610_open ) && 2 === count( $a610_open['seats'] ?? array() ) && 'scheduled' === ( $a610_open['occurrence_status'] ?? '' ) && true === ( $a610_open['sales_open'] ?? null ) && null === Reservations::closed_state( $a610_occ ) );

$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a610_occ ) );
$a610_cancelled = $a610_get( $a610_occ );
check( 'A610 a cancelled date: 200 with occurrence_status cancelled, sales_open false and a message, no map', is_array( $a610_cancelled ) && 'cancelled' === ( $a610_cancelled['occurrence_status'] ?? '' ) && false === ( $a610_cancelled['sales_open'] ?? null ) && false !== strpos( (string) ( $a610_cancelled['message'] ?? '' ), 'abgesagt' ) && ! isset( $a610_cancelled['seats'] ) );
check( 'A610 internal callers (WooCommerce, exchange) still get no map for a cancelled date', null === Reservations::availability( $a610_occ ) );

$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'scheduled', 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'occurrence_id' => $a610_occ ) );
$a610_past = $a610_get( $a610_occ );
check( 'A610 a past date: occurrence_status ended, sales_open false', is_array( $a610_past ) && 'ended' === ( $a610_past['occurrence_status'] ?? '' ) && false === ( $a610_past['sales_open'] ?? null ) && '' !== (string) ( $a610_past['message'] ?? '' ) );

$wpdb->update( "{$p}wpcap_events", array( 'status' => 'draft' ), array( 'event_id' => $a610_event ) );
$a610_draft_answer = $a610_get( $a610_occ );
check( 'A610 a date of an unpublished event stays 404 (nothing about it is public)', is_wp_error( $a610_draft_answer ) && 'wpcap_seat_map_not_found' === $a610_draft_answer->get_error_code() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_seating_availability_limit'] );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a610_done' ), array( 'occurrence_id' => $a610_occ ) );
