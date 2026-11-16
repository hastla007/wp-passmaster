<?php
/**
 * A477: attendee and no-show lists of large events are read block by block (keyset on the list
 * order) instead of one query for all tickets plus IN lists with every ticket id; CSV and HTML are
 * written while reading. Checked with a block size of 3 over 11 tickets (name ties, unnamed
 * tickets, field answers, admitted and undone check-ins).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Printing\AttendeeList;
use PassCaptain\Printing\NoShowList;
use PassCaptain\Printing\Templates;
use PassCaptain\Reports\Scope;

global $wpdb;
$p = $wpdb->prefix;

echo "A477: attendee/no-show lists in blocks\n";
$a477_type = make_type( 50, 20, 0 );
$a477_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a477_type ) );
$a477_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a477_occ ) );
$GLOBALS['wpcap_test_transients'] = array();
$a477_order = Orders::create( array( 'email' => 'a477@example.com', 'items' => array( array( 'ticket_type_id' => $a477_type, 'quantity' => 11 ) ), 'idempotency_key' => 'a477-' . uniqid() ) );
$a477_ids = is_wp_error( $a477_order ) ? array() : array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a477_order['order_id'] ) );
sort( $a477_ids );
// Names with ties (two "Berg", two "anna"/"Anna" equal for the collation) and three without a name.
$a477_names = array( 'Zoe', 'Berg', 'anna', 'Berg', 'Anna', '', 'Müller', '', 'Cem', 'Berg', '' );
$a477_now = gmdate( 'Y-m-d H:i:s' );
foreach ( $a477_ids as $i => $a477_id ) {
	if ( '' !== $a477_names[ $i ] ) { $wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $a477_id, 'buyer_email' => 'a477@example.com', 'attendee_name' => $a477_names[ $i ], 'created_at' => $a477_now ) ); }
}
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a477_type, 'field_key' => 'company', 'label' => 'Firma' ) );
$a477_field = (int) $wpdb->insert_id;
foreach ( $a477_ids as $i => $a477_id ) { $wpdb->insert( "{$p}wpcap_participant_answers", array( 'ticket_id' => $a477_id, 'field_id' => $a477_field, 'answer' => 'Firma ' . $i, 'created_at' => $a477_now, 'updated_at' => $a477_now ) ); }
// Admitted: ticket 0 (checked_in status), ticket 1 (repeatable: valid with a checked_in row), ticket 2 admitted and undone.
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a477_ids[0] ) );
foreach ( array( array( 1, 'checked_in' ), array( 2, 'checked_in' ), array( 2, 'undone' ) ) as list( $i, $result ) ) {
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $a477_ids[ $i ], 'event_id' => $a477_event, 'user_id' => 1, 'result' => $result, 'created_at' => $a477_now ) );
}

// Reference: the whole list in one query, as before.
$a477_all = Templates::tickets_for_occurrence( $a477_occ, array( 'valid', 'checked_in' ) );
$a477_expected_order = array_map( static fn( $t ) => (int) $t->ticket_id, $a477_all );

$GLOBALS['wpcap_test_filters']['wpcap_attendee_list_chunk'] = 3;
$GLOBALS['wpcap_test_query_log'] = array();
$a477_full = AttendeeList::rows( $a477_occ, 'full' );
$a477_log = $GLOBALS['wpcap_test_query_log'];
unset( $GLOBALS['wpcap_test_query_log'] );
$a477_order_seen = array();
foreach ( array_slice( $a477_full, 1 ) as $row ) {
	foreach ( $a477_all as $t ) { if ( substr( $t->public_id, 0, 12 ) === $row[0] ) { $a477_order_seen[] = (int) $t->ticket_id; } }
}
check( 'A477: block-wise list has every ticket once, in the same order as before (ties and unnamed tickets across block borders)', 11 === count( $a477_full ) - 1 && $a477_expected_order === $a477_order_seen );
check( 'A477: field answers are joined per block', 'Firma' === end( $a477_full[0] ) && ! array_filter( array_slice( $a477_full, 1 ), static fn( $r ) => 0 !== strpos( (string) end( $r ), 'Firma ' ) ) );
$a477_blocks = array_filter( $a477_log, static fn( $q ) => false !== strpos( $q, 'AS sort_key' ) );
$a477_in = array();
foreach ( $a477_log as $q ) { if ( preg_match_all( '/ticket_id IN \(([\d,\s]+)\)/', $q, $m ) ) { foreach ( $m[1] as $list ) { $a477_in[] = count( explode( ',', $list ) ); } } }
check( 'A477: tickets are read in blocks of 3 with LIMIT (4 block queries for 11 tickets)', 4 === count( $a477_blocks ) && ! array_filter( $a477_blocks, static fn( $q ) => ! preg_match( '/LIMIT 3\s*$/', trim( $q ) ) ) );
check( 'A477: no IN list holds more ids than one block', $a477_in && max( $a477_in ) <= 3 );
check( 'A477: basic list has the same rows', 12 === count( AttendeeList::rows( $a477_occ, 'basic' ) ) );

// No-show list: never admitted = 11 - ticket 0 - ticket 1 (ticket 2 was undone).
$a477_ns = array_map( static fn( $t ) => (int) $t->ticket_id, NoShowList::tickets( $a477_occ ) );
check( 'A477: no-show list in blocks drops admitted tickets only', 9 === count( $a477_ns ) && ! in_array( $a477_ids[0], $a477_ns, true ) && ! in_array( $a477_ids[1], $a477_ns, true ) && in_array( $a477_ids[2], $a477_ns, true ) );
check( 'A477: no-show count comes from one query and matches', 9 === NoShowList::count( $a477_occ ) && 11 === Templates::count_for_occurrence( $a477_occ, array( 'valid', 'checked_in' ) ) );
$a477_html = NoShowList::html( $a477_occ, 'basic' );
check( 'A477: no-show print view shows the counts and all rows', false !== strpos( $a477_html, '9 von 11 gültigen Tickets nicht eingelassen' ) && 9 === substr_count( $a477_html, '<tr><td>' ) );
$a477_print = AttendeeList::html( $a477_occ, 'full' );
check( 'A477: attendee print view shows all tickets with the count', false !== strpos( $a477_print, '11 Tickets' ) && 11 === substr_count( $a477_print, '<td class="box">' ) );

// CSV written row by row equals the CSV of the complete list.
$a477_stream = fopen( 'php://temp', 'w+' );
Scope::write_csv( $a477_stream, PassCaptain\Printing\Page::with_header( AttendeeList::header( $a477_occ, 'full' ), AttendeeList::row_stream( $a477_occ, 'full' ) ), 3 );
rewind( $a477_stream );
check( 'A477: streamed CSV equals the CSV of the whole list', Scope::csv( $a477_full ) === stream_get_contents( $a477_stream ) );
fclose( $a477_stream );
check( 'A477: the download handler streams (no full row array)', false === strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Printing/Page.php' ), 'Scope::csv( AttendeeList::rows' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_attendee_list_chunk'] );
