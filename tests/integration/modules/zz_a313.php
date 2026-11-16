<?php
// phpcs:ignoreFile
/**
 * A313: month calendar puts every occurrence on the day of its own timezone (the time shown is
 * that zone's, so day and time agree with card, ticket and .ics), marks foreign zones, hides
 * "previous month" in the current month, links back to the current month, marks today and shows
 * the weekday on narrow screens.
 */
use PassCaptain\Modules\Calendar;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
// Same site timezone as the other admin tests of the suite.
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A313: month calendar time zones and navigation\n";
global $wpdb;
$p = $wpdb->prefix;
$a313_site = new DateTimeZone( wp_timezone_string() ?: 'UTC' );
$a313_ny = new DateTimeZone( 'America/New_York' );
$a313_month = ( new DateTime( 'first day of +2 month', $a313_site ) )->format( 'Y-m' );
$a313_next_month = ( new DateTime( $a313_month . '-01', $a313_site ) )->modify( '+1 month' )->format( 'Y-m' );

$a313_type = make_type( 10 );
$a313_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a313_type" );
$a313_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a313_occ" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events SET name=%s WHERE event_id=%d", 'Broadway Abend', $a313_event ) );
// 23:30 in New York on the 10th = the 11th in Vienna.
$a313_local = new DateTime( $a313_month . '-10 23:30:00', $a313_ny );
$a313_utc = ( clone $a313_local )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $a313_utc, 'timezone' => 'America/New_York' ), array( 'occurrence_id' => $a313_occ ) );
// 22:00 in New York on the last day of the month = the 1st of the next month in Vienna (and in UTC).
$a313_last = ( new DateTime( $a313_month . '-01', $a313_ny ) )->format( 't' );
$a313_late = new DateTime( $a313_month . '-' . $a313_last . ' 22:00:00', $a313_ny );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a313_event, 'starts_at' => ( clone $a313_late )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'timezone' => 'America/New_York', 'location' => 'NYC', 'capacity' => 0, 'status' => 'scheduled' ) );
$a313_occ2 = (int) $wpdb->insert_id;
// A Vienna occurrence on the 15th: same offset as the site, no zone abbreviation.
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a313_event, 'starts_at' => ( new DateTime( $a313_month . '-15 19:00:00', new DateTimeZone( 'Europe/Vienna' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$a313_occ3 = (int) $wpdb->insert_id;

/** Content of the grid cell of a day (YYYY-MM-DD). */
function a313_cell( $html, $date ) {
	return preg_match( '#<td[^>]*><span class="wpcap-cal-day">(?:(?!</td>).)*<time datetime="' . preg_quote( $date, '#' ) . '">.*?</td>#s', $html, $m ) ? $m[0] : '';
}
$a313_grid = Calendar::render_month( $a313_month, $a313_event );
$a313_abbr = $a313_local->format( 'T' );
check( 'A313: occurrence shows on the day of its own timezone with its local time and zone abbreviation', false !== strpos( a313_cell( $a313_grid, $a313_month . '-10' ), '#wpcap-occ-' . $a313_occ ) && false !== strpos( a313_cell( $a313_grid, $a313_month . '-10' ), '23:30 ' . $a313_abbr . ' Broadway Abend' ) );
check( 'A313: not on the day of the site timezone', false === strpos( a313_cell( $a313_grid, $a313_month . '-11' ), '#wpcap-occ-' . $a313_occ ) );
check( 'A313: the aria label names the same local date as the cell', false !== strpos( $a313_grid, 'Broadway Abend um 23:30 ' . $a313_abbr . ', 10.' . substr( $a313_month, 5, 2 ) . '.' . substr( $a313_month, 0, 4 ) ) );
check( 'A313: a late date on the last local day stays in its month', false !== strpos( a313_cell( $a313_grid, $a313_month . '-' . $a313_last ), '#wpcap-occ-' . $a313_occ2 ) && false === strpos( Calendar::render_month( $a313_next_month, $a313_event ), '#wpcap-occ-' . $a313_occ2 ) );
check( 'A313: same offset as the site: time without zone abbreviation', false !== strpos( a313_cell( $a313_grid, $a313_month . '-15' ), '>19:00 Broadway Abend</a>' ) );

$a313_current = ( new DateTime( 'now', $a313_site ) )->format( 'Y-m' );
$a313_now_grid = Calendar::render_month( $a313_current, $a313_event );
check( 'A313: current month has no "previous month" and no "current month" link', false === strpos( $a313_now_grid, 'rel="prev"' ) && false === strpos( $a313_now_grid, 'wpcap-cal-current' ) && false !== strpos( $a313_now_grid, 'rel="next"' ) );
check( 'A313: today is marked', 1 === substr_count( $a313_now_grid, 'aria-current="date"' ) && false !== strpos( $a313_now_grid, 'class="wpcap-cal-today"' ) );
check( 'A313: later month links back and to the current month', false !== strpos( $a313_grid, 'rel="prev"' ) && 1 === preg_match( '#<a class="wpcap-cal-current" href="[^"]*wpcap_month=' . $a313_current . '[^"]*">#', $a313_grid ) && false === strpos( $a313_grid, 'aria-current="date"' ) );
$a313_past = Calendar::render_month( ( new DateTime( $a313_current . '-01', $a313_site ) )->modify( '-3 month' )->format( 'Y-m' ) );
check( 'A313: a past month (typed URL) has no "previous" link but a way back', false === strpos( $a313_past, 'rel="prev"' ) && false !== strpos( $a313_past, 'wpcap-cal-current' ) );
$a313_first_wd = (int) ( new DateTime( $a313_month . '-01', $a313_site ) )->format( 'N' ) - 1;
$a313_names = array( 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' );
check( 'A313: every day carries its weekday (shown on narrow screens)', false !== strpos( a313_cell( $a313_grid, $a313_month . '-01' ), '<span class="wpcap-cal-wd">' . $a313_names[ $a313_first_wd ] . '</span>' ) && (int) ( new DateTime( $a313_month . '-01' ) )->format( 't' ) === substr_count( $a313_grid, 'class="wpcap-cal-wd"' ) );
$a313_css = (string) file_get_contents( WPCAP_PATH . 'assets/calendar/calendar.css' );
check( 'A313: CSS hides the weekday on wide screens and shows it in the mobile layout', false !== strpos( $a313_css, '.wpcap-cal-wd{display:none}' ) && false !== strpos( $a313_css, '.wpcap-cal-grid .wpcap-cal-wd{display:inline}' ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=$a313_event" );
