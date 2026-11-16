<?php
/**
 * A611: the full attendee export carries date, seat, check-in time and entrance area, and only active fields.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Printing\AttendeeList;

echo "A611: complete attendee export\n";
global $wpdb;
$p = $wpdb->prefix;
$a611_type = make_type( 20, 10, 1000 );
$a611_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a611_type ) );
$a611_order = PassCaptain\Orders::create( array( 'email' => 'a611-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a611_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a611-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A611: fixture order', ! is_wp_error( $a611_order ) );
if ( ! is_wp_error( $a611_order ) ) {
	PassCaptain\Orders::confirm_external_payment( (int) $a611_order['order_id'], 'a611' );
	$a611_tickets = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $a611_order['order_id'] ) ) );
	$a611_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a611_occ ) );
	// Active and removed field.
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a611_type, 'field_key' => 'verein', 'label' => 'Verein', 'field_type' => 'text', 'required' => 0, 'position' => 1, 'status' => 'active' ) );
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a611_type, 'field_key' => 'alt', 'label' => 'Alt-Feld', 'field_type' => 'text', 'required' => 0, 'position' => 2, 'status' => 'deleted' ) );
	// First ticket admitted 10 minutes ago (check-in log; the ticket counts as checked in).
	$a611_at = gmdate( 'Y-m-d H:i:s', time() - 600 );
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $a611_tickets[0], 'event_id' => $a611_event, 'user_id' => 1, 'result' => 'checked_in', 'created_at' => $a611_at ) );
	$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a611_tickets[0] ) );
	$a611_seat = static function ( $label, $ticket_id ) use ( $a611_tickets ) { return $ticket_id === $a611_tickets[1] ? 'Reihe 3, Platz 7' : $label; };
	add_filter( 'wpcap_ticket_seat_label', $a611_seat, 10, 2 );
	$a611_rows = AttendeeList::rows( $a611_occ, 'full' );
	remove_filter( 'wpcap_ticket_seat_label', $a611_seat, 10 );
	$a611_head = $a611_rows[0];
	check( 'A611: header has date, seat, check-in time and area, then only active fields', array( 'Termin', 'Sitzplatz', 'Eingecheckt um', 'Bereich' ) === array_slice( $a611_head, 7, 4 ) && 'Verein' === end( $a611_head ) && ! in_array( 'Alt-Feld', $a611_head, true ) );
	$a611_by_status = array();
	foreach ( array_slice( $a611_rows, 1 ) as $r ) { $a611_by_status[ $r[3] ][] = $r; }
	$a611_in = $a611_by_status['Eingecheckt'][0] ?? array();
	$a611_out = $a611_by_status['Gültig'][0] ?? array();
	$a611_tz = (string) $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a611_occ ) );
	check( 'A611: the admitted ticket shows its check-in time in site time', $a611_in && PassCaptain\Reports\Scope::local( $a611_at, $a611_tz ) === $a611_in[9] );
	check( 'A611: a ticket not admitted has no check-in time; the date is filled for all', $a611_out && '' === $a611_out[9] && '' !== $a611_out[7] && $a611_out[7] === $a611_in[7] );
	check( 'A611: the seat label is exported', 'Reihe 3, Platz 7' === ( $a611_out[8] ?? '' ) || 'Reihe 3, Platz 7' === ( $a611_in[8] ?? '' ) );
	check( 'A611: the basic list keeps its three columns', 3 === count( AttendeeList::rows( $a611_occ, 'basic' )[0] ) );
}
