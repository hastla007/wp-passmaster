<?php
// phpcs:ignoreFile
/**
 * A502: the calendar block selects its event from a list (WPCAP_BLOCK_CALENDAR, editor test in
 * tests/js/blocks.test.js) and has a limit for the list view that the renderer uses.
 */
use PassCaptain\Modules\Calendar;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A502: calendar block event list and limit\n";
global $wpdb;
$p = $wpdb->prefix;
$a502_type = make_type( 10 );
$a502_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a502_type" );
$a502_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a502_occ" );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'A502 Workshop' ), array( 'event_id' => $a502_event ) );
foreach ( array( 9, 10 ) as $a502_d ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a502_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $a502_d * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Raum 1', 'capacity' => 0, 'status' => 'scheduled' ) );
}
$a502_list = static function ( $attrs ) { return substr_count( Calendar::render_block( $attrs ), '<li class="wpcap-cal-item' ); };
check( 'A502 the list view of the block shows at most "limit" dates', 1 === $a502_list( array( 'view' => 'list', 'eventId' => $a502_event, 'limit' => 1 ) ) && 3 === $a502_list( array( 'view' => 'list', 'eventId' => $a502_event, 'limit' => 50 ) ) );
check( 'A502 without or with an invalid limit the default 50 applies; above 200 is capped', 3 === $a502_list( array( 'view' => 'list', 'eventId' => $a502_event ) ) && 3 === $a502_list( array( 'view' => 'list', 'eventId' => $a502_event, 'limit' => 0 ) ) && 3 === $a502_list( array( 'view' => 'list', 'eventId' => $a502_event, 'limit' => 9999 ) ) );
$a502_choices = Calendar::block_choices();
$a502_mine = array_values( array_filter( $a502_choices['events'], static function ( $e ) use ( $a502_event ) { return $e['id'] === $a502_event; } ) );
check( 'A502 the block choices list the event with id and a label with name and ID (no dates list)', 1 === count( $a502_mine ) && false !== strpos( $a502_mine[0]['label'], 'A502 Workshop' ) && false !== strpos( $a502_mine[0]['label'], '#' . $a502_event ) && array( 'id', 'label' ) === array_keys( $a502_mine[0] ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='a502_done' WHERE event_id=$a502_event" );
