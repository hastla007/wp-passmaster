<?php
/**
 * A453: the date selection of the events block (PublicEvents::block_choices()) is aggregated per
 * event: an event with hundreds of dates no longer pushes other events out of a shared 500-row
 * limit; per event the next BLOCK_CHOICE_DATES dates with `dates`/`more`. The editor side
 * (a saved selection that is no longer listed) is tested in tests/js/blocks.test.js.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\PublicEvents;

global $wpdb;
$p = $wpdb->prefix;

/** Published (or other status) event with $n upcoming dates starting $first_days from now, one per day. */
function a453_event( $name, $n, $first_days, $status = 'published' ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => $name, 'slug' => 'a453-' . uniqid(), 'description' => '', 'status' => $status, 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$values = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%d,%s)', $event, gmdate( 'Y-m-d H:i:s', time() + ( $first_days + $i ) * 86400 ), 'Europe/Vienna', 'Wien', 0, 'scheduled' );
	}
	foreach ( array_chunk( $values, 200 ) as $chunk ) {
		$wpdb->query( "INSERT INTO {$p}wpcap_occurrences (event_id,starts_at,timezone,location,capacity,status) VALUES " . implode( ',', $chunk ) );
	}
	return $event;
}
function a453_choice( $choices, $id ) {
	return array_values( array_filter( $choices, static fn( $e ) => $id === $e['id'] ) )[0] ?? null;
}

echo "A453: events block choices per event\n";
// Events of earlier test files are hidden meanwhile (the list is capped at BLOCK_CHOICE_EVENTS).
$a453_hidden = array_map( 'intval', $wpdb->get_col( "SELECT event_id FROM {$p}wpcap_events WHERE status='published'" ) );
if ( $a453_hidden ) { $wpdb->query( "UPDATE {$p}wpcap_events SET status='a453_hidden' WHERE event_id IN (" . implode( ',', $a453_hidden ) . ')' ); }
// An event with 600 dates starting tomorrow (before everything else) and one with a single later date.
$a453_many = a453_event( 'A453 Tageskurs', 600, 1 );
$a453_later = a453_event( 'A453 Gala', 1, 900 );
$a453_three = a453_event( 'A453 Trio', 3, 950 );
$a453_draft = a453_event( 'A453 Entwurf', 2, 3, 'draft' );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a453_later, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ), 'timezone' => 'Europe/Vienna', 'location' => '', 'capacity' => 0, 'status' => 'scheduled' ) );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a453_three, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 960 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => '', 'capacity' => 0, 'status' => 'cancelled' ) );

$a453_choices = PublicEvents::block_choices()['events'];
$a453_m = a453_choice( $a453_choices, $a453_many );
$a453_l = a453_choice( $a453_choices, $a453_later );
$a453_t = a453_choice( $a453_choices, $a453_three );
check( 'an event after another with 600 dates is still listed', null !== $a453_l && 1 === count( $a453_l['occurrences'] ) && 1 === $a453_l['dates'] && false === $a453_l['more'] );
check( 'event with many dates: next BLOCK_CHOICE_DATES dates, total count and "more"', null !== $a453_m && PublicEvents::BLOCK_CHOICE_DATES === count( $a453_m['occurrences'] ) && 600 === $a453_m['dates'] && true === $a453_m['more'] );
$a453_first = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d AND starts_at>=%s ORDER BY starts_at,occurrence_id LIMIT 1", $a453_many, gmdate( 'Y-m-d H:i:s' ) ) );
check( 'dates in start order, beginning with the next one', $a453_m && $a453_first === $a453_m['occurrences'][0]['id'] );
check( 'small event: all upcoming scheduled dates (no past or cancelled ones)', $a453_t && 3 === count( $a453_t['occurrences'] ) && 3 === $a453_t['dates'] && ! $a453_t['more'] );
check( 'labels carry name, next date and ID', $a453_l && 0 === strpos( $a453_l['label'], 'A453 Gala (' ) && false !== strpos( $a453_l['label'], ', #' . $a453_later . ')' ) && $a453_l['occurrences'][0]['label'] === substr( $a453_l['label'], strlen( 'A453 Gala (' ), strlen( $a453_l['occurrences'][0]['label'] ) ) );
check( 'unpublished events are not offered', null === a453_choice( $a453_choices, $a453_draft ) );
$a453_ids = array_column( $a453_choices, 'id' );
check( 'events ordered by their next date', array_search( $a453_many, $a453_ids, true ) < array_search( $a453_later, $a453_ids, true ) && array_search( $a453_later, $a453_ids, true ) < array_search( $a453_three, $a453_ids, true ) );

// Clean up: the 600 dates would slow down later listing tests.
foreach ( array( $a453_many, $a453_later, $a453_three, $a453_draft ) as $a453_id ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_occurrences WHERE event_id=%d", $a453_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_events WHERE event_id=%d", $a453_id ) );
}
if ( $a453_hidden ) { $wpdb->query( "UPDATE {$p}wpcap_events SET status='published' WHERE status='a453_hidden'" ); }
