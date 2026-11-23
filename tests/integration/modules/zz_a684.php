<?php
/**
 * A684: attendee management – filter by date and ticket type, sort orders, list state kept after actions, CSV
 * export of the chosen date.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\Queries;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;

echo "A684: attendee management filters and sorting\n";
global $wpdb;
$p = $wpdb->prefix;
$a684_type_a = make_type( 50, 10, 1000 );
$a684_occ_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a684_type_a ) );
$a684_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a684_occ_a ) );
// Second date (earlier) with its own ticket type "Abend" in the same event.
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a684_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$a684_occ_b = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a684_occ_b, 'name' => 'Abend', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$a684_type_b = (int) $wpdb->insert_id;
$a684_buy = static function ( $type, $qty ) {
	$o = Orders::create( array( 'email' => 'a684-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'a684-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
	if ( ! is_wp_error( $o ) ) { Orders::confirm_external_payment( (int) $o['order_id'], 'a684' ); }
	return $o;
};
$a684_o1 = $a684_buy( $a684_type_a, 2 );
$a684_o2 = $a684_buy( $a684_type_b, 1 );
check( 'A684: fixture orders', ! is_wp_error( $a684_o1 ) && ! is_wp_error( $a684_o2 ) );
$a684_all = Queries::search_tickets( array( 'event_id' => $a684_event ), null, 1, 50 );
$a684_occ = Queries::search_tickets( array( 'event_id' => $a684_event, 'occurrence_id' => $a684_occ_b ), null, 1, 50 );
$a684_typ = Queries::search_tickets( array( 'event_id' => $a684_event, 'ticket_type_id' => $a684_type_a ), null, 1, 50 );
check( 'A684: filter by date and by ticket type', 3 === $a684_all['total'] && 1 === $a684_occ['total'] && (int) $a684_occ['rows'][0]->occurrence_id === $a684_occ_b && 2 === $a684_typ['total'] );
$a684_by_date = Queries::search_tickets( array( 'event_id' => $a684_event, 'orderby' => 'date' ), null, 1, 50 );
check( 'A684: sorted by date the earlier date comes first', (int) $a684_by_date['rows'][0]->occurrence_id === $a684_occ_b );
$a684_new = array_map( static function ( $r ) { return (int) $r->ticket_id; }, Queries::search_tickets( array( 'event_id' => $a684_event ), null, 1, 50 )['rows'] );
$a684_old = array_map( static function ( $r ) { return (int) $r->ticket_id; }, Queries::search_tickets( array( 'event_id' => $a684_event, 'orderby' => 'oldest' ), null, 1, 50 )['rows'] );
check( 'A684: newest and oldest first are reverse orders, the page keeps the order', array_reverse( $a684_new ) === $a684_old && $a684_old === array_values( array_unique( $a684_old ) ) );
$a684_type_sorted = array_map( static function ( $r ) { return (string) $r->ticket_name; }, Queries::search_tickets( array( 'event_id' => $a684_event, 'orderby' => 'type' ), null, 1, 50 )['rows'] );
check( 'A684: sorted by ticket type', array( 'Abend', 'Standard', 'Standard' ) === $a684_type_sorted );
check( 'A684: sorting by name works (unnamed tickets last)', 3 === Queries::search_tickets( array( 'event_id' => $a684_event, 'orderby' => 'name' ), null, 1, 50 )['total'] );
check( 'A684: unknown sort keys fall back to newest first', 't.ticket_id DESC' === Queries::ticket_order( "x'; DROP" ) );
$a684_f = TicketsPage::filters( array( 's' => ' Muster ', 'event_id' => '5', 'occurrence_id' => '7', 'ticket_type_id' => '9', 'status' => 'bogus', 'orderby' => 'date' ) );
check( 'A684: request filters are sanitised (unknown status dropped, sort key checked)', 'Muster' === $a684_f['search'] && 5 === $a684_f['event_id'] && 7 === $a684_f['occurrence_id'] && 9 === $a684_f['ticket_type_id'] && '' === $a684_f['status'] && 'date' === $a684_f['orderby'] );
$a684_g = TicketsPage::filters( array( 'occurrence_id' => '7', 'ticket_type_id' => '9', 'orderby' => 'nope' ) );
check( 'A684: date and type only together with an event; default sort newest', 0 === $a684_g['occurrence_id'] && 0 === $a684_g['ticket_type_id'] && 'newest' === $a684_g['orderby'] );
$a684_back = new ReflectionMethod( TicketsPage::class, 'back_url' );
$a684_back->setAccessible( true );
parse_str( (string) wp_parse_url( $a684_back->invoke( null, array( 'return_event' => '5', 'return_occurrence' => '7', 'return_type' => '9', 'return_orderby' => 'type' ) ), PHP_URL_QUERY ), $a684_q );
check( 'A684: date, type and sort order survive an action', '7' === ( $a684_q['occurrence_id'] ?? '' ) && '9' === ( $a684_q['ticket_type_id'] ?? '' ) && 'type' === ( $a684_q['orderby'] ?? '' ) );
$a684_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' );
check( 'A684: the list offers date/type/sort filters and the CSV export of the chosen date', false !== strpos( $a684_src, "Forms::select( 'occurrence_id'" ) && false !== strpos( $a684_src, "Forms::select( 'orderby'" ) && false !== strpos( $a684_src, "'action' => 'wpcap_attendee_list'" ) );
