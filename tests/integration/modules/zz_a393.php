<?php
// phpcs:ignoreFile
/**
 * A393: links of the calendar (month entries, ".ics" links of the list) have an accessible name
 * that starts with their visible text (WCAG 2.5.3 Label in Name) and adds event and date.
 */
use PassCaptain\Modules\Calendar;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A393: visible link text in the accessible name\n";
global $wpdb;
$p = $wpdb->prefix;
$a393_type = make_type( 10 );
$a393_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a393_type" );
$a393_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a393_occ" );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'A393 Lesung' ), array( 'event_id' => $a393_event ) );
$a393_start = new DateTime( 'first day of +2 month 18:00', new DateTimeZone( 'Europe/Vienna' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => ( clone $a393_start )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'timezone' => 'Europe/Vienna' ), array( 'occurrence_id' => $a393_occ ) );

/** [visible text, aria-label] of every link with an aria-label. */
function a393_links( $html ) {
	preg_match_all( '#<a\b[^>]*\baria-label="([^"]*)"[^>]*>(.*?)</a>#s', $html, $m, PREG_SET_ORDER );
	return array_map( static function ( $l ) { return array( trim( html_entity_decode( strip_tags( $l[2] ), ENT_QUOTES ) ), html_entity_decode( $l[1], ENT_QUOTES ) ); }, $m );
}
$a393_month = a393_links( Calendar::render_month( $a393_start->format( 'Y-m' ), $a393_event ) );
$a393_list = a393_links( Calendar::render_list( $a393_event ) );
$a393_all = array_merge( $a393_month, $a393_list );
$a393_bad = array_filter( $a393_all, static function ( $l ) { return '' === $l[0] || 0 !== strpos( $l[1], $l[0] ); } );
check( 'A393: month and list links with an aria-label begin with their visible text' . ( $a393_bad ? ' – ' . json_encode( array_values( $a393_bad ) ) : '' ), count( $a393_all ) >= 2 && ! $a393_bad );
check( 'A393: the month entry adds the date', in_array( array( '18:00 A393 Lesung', '18:00 A393 Lesung, ' . $a393_start->format( 'd.m.Y' ) ), $a393_month, true ) );
check( 'A393: the .ics link names event and date after its visible text', in_array( array( 'Zum Kalender (.ics)', 'Zum Kalender (.ics): A393 Lesung am ' . $a393_start->format( 'd.m.Y' ) ), $a393_list, true ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a393_occ ) );
