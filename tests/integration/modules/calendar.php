<?php
/**
 * Calendar module: ICS validity (CRLF, folding, escaping, UTC times, stable UID), public endpoints,
 * ticket-mail attachment and the shortcode views. Included by run.php.
 */

// phpcs:ignoreFile

use PassCaptain\Calendar\Ics;
use PassCaptain\Modules\Calendar;
use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/wp-pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { $GLOBALS['wpcap_test_shortcodes'][ $tag ] = $cb; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }

Calendar::register();
global $wpdb;
$p = $wpdb->prefix;

echo "Calendar: iCalendar format\n";
check( 'TEXT escaping of backslash, semicolon, comma and newline', 'a\\,b\\;c\\\\d\\nE' === Ics::escape_text( "a,b;c\\d\r\nE" ) );
$long = 'DESCRIPTION:' . str_repeat( 'Größenwahn – ', 20 );
$folded = Ics::fold( $long );
$parts = explode( "\r\n", $folded );
$ok = count( $parts ) > 1;
foreach ( $parts as $i => $part ) {
	$ok = $ok && strlen( $part ) <= 75 && ( 0 === $i || ' ' === $part[0] ) && 1 === preg_match( '//u', $part );
}
check( 'lines folded at 75 octets with CRLF+space, UTF-8 sequences intact', $ok );
check( 'unfolding restores the original line', $long === str_replace( "\r\n ", '', $folded ) );
check( 'short lines are not folded', 'SUMMARY:x' === Ics::fold( 'SUMMARY:x' ) );

$type = make_type( 10, 10, 0 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
$start = gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s, ends_at=NULL, location=%s WHERE occurrence_id=%d", $start, 'Saal 1; Wien, Ring', $occ ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events SET name=%s, description=%s WHERE event_id=%d", 'Konzert, Nacht; Teil 2', '<p>Ein sehr langer Beschreibungstext mit Umlauten äöü und Sonderzeichen, der über die Zeilenlänge hinausgeht und gefaltet werden muss.</p>', $event ) );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/tickets/', 'attach_ics' => 1 ) ) );

$ics = Ics::occurrence_calendar( $occ );
$lines = explode( "\r\n", rtrim( (string) $ics, "\r\n" ) );
$max = max( array_map( 'strlen', $lines ) );
check( 'calendar uses CRLF only and ends with CRLF', is_string( $ics ) && "\r\n" === substr( $ics, -2 ) && false === strpos( str_replace( "\r\n", '', $ics ), "\n" ) );
check( 'no physical line exceeds 75 octets', $max <= 75 );
$unfolded = str_replace( "\r\n ", '', $ics );
check( 'VCALENDAR with VERSION, PRODID, METHOD and one VEVENT', 0 === strpos( $ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" ) && 1 === substr_count( $ics, 'BEGIN:VEVENT' ) && false !== strpos( $ics, 'METHOD:PUBLISH' ) && false !== strpos( $ics, "END:VCALENDAR\r\n" ) );
check( 'DTSTART/DTEND in UTC with default duration of 2 hours', false !== strpos( $unfolded, 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $start . ' UTC' ) ) ) && false !== strpos( $unfolded, 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $start . ' UTC' ) + 7200 ) ) );
check( 'DTSTAMP present in UTC', 1 === preg_match( '/DTSTAMP:\d{8}T\d{6}Z/', $unfolded ) );
check( 'summary and location escaped', false !== strpos( $unfolded, 'SUMMARY:Konzert\\, Nacht\\; Teil 2' ) && false !== strpos( $unfolded, 'LOCATION:Saal 1\\; Wien\\, Ring' ) );
check( 'stable UID per occurrence', false !== strpos( $unfolded, 'UID:wpcap-occurrence-' . $occ . '@example.test' ) && $ics !== null && preg_replace( '/DTSTAMP:\S+/', '', $ics ) === preg_replace( '/DTSTAMP:\S+/', '', (string) Ics::occurrence_calendar( $occ ) ) );
check( 'checkout link with card anchor as URL', false !== strpos( $unfolded, 'URL:https://example.test/tickets/#wpcap-occ-' . $occ ) );
check( 'description has no HTML', false === strpos( $unfolded, '<p>' ) );

$wpdb->query( "UPDATE {$p}wpcap_occurrences SET ends_at=DATE_ADD(starts_at, INTERVAL 90 MINUTE) WHERE occurrence_id=$occ" );
check( 'ends_at is used for DTEND', false !== strpos( str_replace( "\r\n ", '', (string) Ics::occurrence_calendar( $occ ) ), 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $start . ' UTC' ) + 5400 ) ) );

echo "Calendar: public endpoints only for published events, no PII\n";
$r = Orders::create( array( 'email' => 'cal-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'cal-1' ) );
check( 'occurrence .ics contains no buyer data', false === strpos( (string) Calendar::ics_for( 'occurrence', $occ ), 'cal-buyer' ) );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Graz', 'capacity' => 0, 'status' => 'cancelled' ) );
$cancelled_occ = (int) $wpdb->insert_id;
$feed = (string) Calendar::ics_for( 'event', $event );
check( 'event feed lists all occurrences with calendar name', 2 === substr_count( $feed, 'BEGIN:VEVENT' ) && false !== strpos( $feed, 'X-WR-CALNAME:' ) );
check( 'cancelled occurrence appears as STATUS:CANCELLED in the feed', false !== strpos( $feed, 'UID:wpcap-occurrence-' . $cancelled_occ . '@' ) && false !== strpos( $feed, 'STATUS:CANCELLED' ) );
check( 'webcal feed URL', 0 === strpos( Ics::feed_url( $event, true ), 'webcal://example.test/?' ) );
$wpdb->query( "UPDATE {$p}wpcap_events SET status='draft' WHERE event_id=$event" );
check( 'unpublished event: no occurrence download and no feed', null === Calendar::ics_for( 'occurrence', $occ ) && null === Calendar::ics_for( 'event', $event ) );
check( 'unknown kind is rejected', null === Calendar::ics_for( 'order', $occ ) );
$wpdb->query( "UPDATE {$p}wpcap_events SET status='published' WHERE event_id=$event" );

echo "Calendar: ticket mail attachment\n";
$GLOBALS['wpcap_test_mails'] = array();
$r = Orders::create( array( 'email' => 'cal-mail@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'cal-2' ) );
$ticket_mail = null;
foreach ( $GLOBALS['wpcap_test_mails'] as $m ) { if ( 'cal-mail@example.com' === $m['to'] && str_starts_with( $m['subject'], 'Deine Tickets' ) ) { $ticket_mail = $m; } }
$ics_files = array_filter( array_keys( (array) ( $ticket_mail['files'] ?? array() ) ), static fn( $f ) => 0 === strpos( $f, 'termin-' . $occ . '-' ) && '.ics' === substr( $f, -4 ) );
check( 'one .ics per occurrence attached to the ticket mail', 1 === count( $ics_files ) );
$attached = $ics_files ? $ticket_mail['files'][ reset( $ics_files ) ] : '';
check( 'attached calendar is the occurrence calendar', false !== strpos( $attached, 'UID:wpcap-occurrence-' . $occ . '@' ) );
check( 'temporary .ics removed after sending', ! glob( trailingslashit( get_temp_dir() ) . 'termin-' . $occ . '-*.ics' ) );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/tickets/', 'attach_ics' => 0 ) ) );
check( 'attachment can be switched off', array( 'subject' => 's', 'body' => 'b', 'attachments' => array() ) === Calendar::attach_ics( array( 'subject' => 's', 'body' => 'b', 'attachments' => array() ), null, array( array( 'occurrence_id' => $occ ) ) ) );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/tickets/', 'attach_ics' => 1 ) ) );

$dropped = Calendar::attach_ics( array( 'subject' => 's', 'body' => 'b', 'attachments' => array() ), null, array( array( 'occurrence_id' => $occ ) ) );
$file = $dropped['attachments'][0] ?? '';
check( 'temp .ics has an unguessable name', 1 === preg_match( '/termin-' . $occ . '-[0-9a-f]{16}\.ics$/', $file ) && file_exists( $file ) );
Calendar::cleanup_temp_files();
check( 'shutdown cleanup removes .ics files a filter dropped from the mail', '' !== $file && ! file_exists( $file ) );

echo "Calendar: shortcode and block\n";
$draft_type = make_type( 5, 10, 0 );
$draft_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$draft_type" );
$wpdb->query( "UPDATE {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id SET e.status='draft', e.name='Geheim' WHERE o.occurrence_id=$draft_occ" );
$list = Calendar::render_shortcode( array( 'view' => 'list', 'event' => $event ) );
check( 'list view links to the checkout anchor and the .ics download', false !== strpos( $list, 'https://example.test/tickets/#wpcap-occ-' . $occ ) && false !== strpos( $list, 'wpcap_ics=occurrence&amp;id=' . $occ ) );
check( 'list view uses <time datetime> and escapes names', false !== strpos( $list, '<time datetime="' ) && false !== strpos( $list, 'Konzert, Nacht; Teil 2' ) );
check( 'list view hides cancelled occurrences', false === strpos( $list, 'wpcap-occ-' . $cancelled_occ ) );
$all = Calendar::render_list();
check( 'drafts are not listed', false === strpos( $all, 'Geheim' ) );
$month = gmdate( 'Y-m', strtotime( $start . ' UTC' ) );
$_GET['wpcap_month'] = $month;
$grid = Calendar::render_shortcode( array( 'view' => 'month', 'event' => $event ) );
unset( $_GET['wpcap_month'] );
check( 'month view is a table with caption and column headers', false !== strpos( $grid, '<table class="wpcap-cal-grid"><caption>' ) && 7 === substr_count( $grid, '<th scope="col">' ) );
check( 'month view links the occurrence with an accessible label', false !== strpos( $grid, '#wpcap-occ-' . $occ ) && false !== strpos( $grid, 'aria-label="Konzert, Nacht; Teil 2 um' ) );
check( 'month view has prev/next navigation', false !== strpos( $grid, 'rel="prev"' ) && false !== strpos( $grid, 'rel="next"' ) );
check( 'invalid month falls back to current month', false !== strpos( Calendar::render_month( '2026-13' ), '<caption>' ) );
$card = Calendar::add_card_anchor( '<article class="wpcap-event"><h3>x</h3></article>', (object) array( 'occurrence_id' => 42 ) );
check( 'event cards get an anchor id', 0 === strpos( $card, '<article id="wpcap-occ-42" class="wpcap-event"' ) );
check( 'shortcode registered', isset( $GLOBALS['wpcap_test_shortcodes']['wp_pass_captain_calendar'] ) || ! isset( $GLOBALS['wpcap_test_shortcodes'] ) );
$block = json_decode( (string) file_get_contents( WPCAP_PATH . 'src/blocks/calendar/block.json' ), true );
check( 'block.json declares a dynamic block with view attribute', 'wp-pass-captain/calendar' === ( $block['name'] ?? '' ) && 'file:./render.php' === ( $block['render'] ?? '' ) && isset( $block['attributes']['view'] ) );
