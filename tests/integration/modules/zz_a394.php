<?php
// phpcs:ignoreFile
/**
 * A394: on narrow screens the month calendar is no table without column headers but a list: the
 * month as title and one entry per day with dates ("Montag, 16. November") and its links. CSS shows
 * either the table (wide) or the list (narrow).
 */
use PassCaptain\Modules\Calendar;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A394: month calendar on narrow screens\n";
global $wpdb;
$p = $wpdb->prefix;
$a394_tz = new DateTimeZone( 'Europe/Vienna' );
$a394_type = make_type( 10 );
$a394_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a394_type" );
$a394_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a394_occ" );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'A394 Chor' ), array( 'event_id' => $a394_event ) );
$a394_start = new DateTime( 'first day of +3 month', $a394_tz );
$a394_start->setDate( (int) $a394_start->format( 'Y' ), (int) $a394_start->format( 'n' ), 12 )->setTime( 19, 30 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => ( clone $a394_start )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'timezone' => 'Europe/Vienna' ), array( 'occurrence_id' => $a394_occ ) );
$a394_html = Calendar::render_month( $a394_start->format( 'Y-m' ), $a394_event );
$a394_months = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
$a394_days = array( 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag' );
$a394_label = $a394_days[ (int) $a394_start->format( 'N' ) - 1 ] . ', 12. ' . $a394_months[ (int) $a394_start->format( 'n' ) ];
preg_match( '#<div class="wpcap-cal-agenda">(.*?)</div>#s', $a394_html, $a394_agenda );
$a394_agenda = $a394_agenda[1] ?? '';
check( 'A394: the agenda names the month as its title', false !== strpos( $a394_agenda, '<p class="wpcap-cal-agenda-title">' . $a394_months[ (int) $a394_start->format( 'n' ) ] . ' ' . $a394_start->format( 'Y' ) . '</p>' ) );
check( 'A394: a list entry per day with dates: weekday, day and month, then the links', 1 === substr_count( $a394_agenda, '<li class="wpcap-cal-agenda-day' ) && false !== strpos( $a394_agenda, '<time datetime="' . $a394_start->format( 'Y-m-d' ) . '">' . $a394_label . '</time>' ) && false !== strpos( $a394_agenda, '>19:30 A394 Chor</a>' ) && false !== strpos( $a394_agenda, '<ol class="wpcap-cal-agenda-days">' ) );
check( 'A394: the table still holds the entry for wide screens', 1 === preg_match( '#<table class="wpcap-cal-grid">.*>19:30 A394 Chor</a>.*</table>#s', $a394_html ) );
$a394_css = (string) file_get_contents( WPCAP_PATH . 'assets/calendar/calendar.css' );
check( 'A394: CSS shows the agenda instead of the table on narrow screens and no longer turns table cells into blocks', false !== strpos( $a394_css, '.wpcap-cal-agenda{display:none}' ) && false !== strpos( $a394_css, '@media (max-width:600px){.wpcap-cal-grid{display:none}.wpcap-cal-agenda{display:block}}' ) && false === strpos( $a394_css, '.wpcap-cal-grid td{display:block' ) && false === strpos( $a394_css, '.wpcap-cal-grid tr,' ) );
$a394_empty = Calendar::render_month( ( clone $a394_start )->modify( '+1 month' )->format( 'Y-m' ), $a394_event );
check( 'A394: a month without dates has the title but no empty list', false !== strpos( $a394_empty, '<div class="wpcap-cal-agenda"><p class="wpcap-cal-agenda-title">' ) && false === strpos( $a394_empty, 'wpcap-cal-agenda-days' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a394_occ ) );
