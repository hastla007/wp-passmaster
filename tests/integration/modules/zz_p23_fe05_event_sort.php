<?php
/**
 * PASS-FE-05 (old duplicate L1411): the ticket list also sorts by event (event name, then date and
 * ticket) when it shows tickets of several events. Included from run.php.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\Queries;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;

echo "PASS-FE-05: ticket list sorted by event\n";
global $wpdb;
$p = $wpdb->prefix;

$p23_mark = 'P23S-' . uniqid();
$p23_types = array();
$p23_events = array();
// Created in the order Zeta, Alpha so that "newest first" and "by event" differ.
foreach ( array( 'Zeta', 'Alpha' ) as $p23_label ) {
	$p23_type = make_type( 20, 10, 0 );
	$p23_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$p23_type" );
	$wpdb->update( "{$p}wpcap_events", array( 'name' => "$p23_label $p23_mark" ), array( 'event_id' => $p23_event ) );
	$p23_types[ $p23_label ] = $p23_type;
	$p23_events[ $p23_label ] = $p23_event;
}
$p23_orders = array();
foreach ( array( 'Zeta', 'Alpha', 'Zeta' ) as $p23_i => $p23_label ) {
	wpcap_test_reset_transients();
	$p23_orders[] = Orders::create( array( 'email' => "p23-$p23_i-" . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $p23_types[ $p23_label ], 'quantity' => 1 ) ), 'idempotency_key' => 'p23-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $p23_types[ $p23_label ], 'name' => "P23 $p23_i" ) ) ) );
}
check( 'p23 fixture: three free orders over two events', 3 === count( array_filter( $p23_orders, static function ( $o ) { return ! is_wp_error( $o ); } ) ) );

$p23_search = static function ( $orderby ) use ( $p23_events ) {
	// Scope to the two fixture events (event_ids as for a scoped user), all other tickets of the run stay out.
	return Queries::search_tickets( array( 'orderby' => $orderby ), array_values( $p23_events ), 1, 50 );
};
$p23_by_event = $p23_search( 'event' );
$p23_names = array_map( static function ( $r ) { return (string) $r->event_name; }, $p23_by_event['rows'] );
check( 'PASS-FE-05: sort by event puts Alpha before both Zeta tickets (event name, not creation order)', 3 === $p23_by_event['total'] && array( "Alpha $p23_mark", "Zeta $p23_mark", "Zeta $p23_mark" ) === $p23_names );
$p23_zeta_ids = array_map( static function ( $r ) { return (int) $r->ticket_id; }, array_slice( $p23_by_event['rows'], 1 ) );
check( 'PASS-FE-05: within an event the tickets keep date/ticket order', $p23_zeta_ids[0] < $p23_zeta_ids[1] );
$p23_newest = array_map( static function ( $r ) { return (string) $r->event_name; }, $p23_search( 'newest' )['rows'] );
check( 'PASS-FE-05: the default order (newest first) is unchanged', "Zeta $p23_mark" === $p23_newest[0] && "Alpha $p23_mark" === $p23_newest[1] );
check( 'PASS-FE-05: the event sort combines with a status filter and counts without the event join', 3 === Queries::search_tickets( array( 'orderby' => 'event', 'status' => 'valid' ), array_values( $p23_events ), 1, 50 )['total'] );
check( 'PASS-FE-05: the event sort is offered in the sort select and accepted by the request filters', isset( Queries::ticket_orders()['event'] ) && 'event' === TicketsPage::filters( array( 'orderby' => 'event' ) )['orderby'] && 'e.name ASC, o.event_id ASC, o.starts_at ASC, t.ticket_id ASC' === Queries::ticket_order( 'event', 'desc' ) );
$p23_page2 = Queries::search_tickets( array( 'orderby' => 'event' ), array_values( $p23_events ), 2, 2 );
check( 'PASS-FE-05: pagination of the event sort (page 2 of 2 holds the last Zeta ticket)', 3 === $p23_page2['total'] && 1 === count( $p23_page2['rows'] ) && $p23_zeta_ids[1] === (int) $p23_page2['rows'][0]->ticket_id );

// Cleanup: cancel the free orders so no counters stay behind.
foreach ( $p23_orders as $p23_o ) {
	if ( ! is_wp_error( $p23_o ) ) { Orders::cancel_order( (int) $p23_o['order_id'], 'p23_cleanup' ); }
}
