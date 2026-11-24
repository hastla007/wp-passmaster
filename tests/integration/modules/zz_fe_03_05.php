<?php
/**
 * PASS-FE-03 to -05, open parts: ticket list filter by ticket type over all dates and sort by status,
 * attendee CSV filtered by ticket type and status, rights per participant field (wpcap_pii_can_view_answer)
 * and the order portal link from the WooCommerce customer account. Included from run.php.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\Queries;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;
use PassCaptain\Pii;
use PassCaptain\Printing\AttendeeList;
use PassCaptain\OrderLinkRequest;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Fe05Woo;

echo "PASS-FE-03..05: attendee filters over dates, field rights, account portal link\n";
global $wpdb;
$p = $wpdb->prefix;

/* ---------------------------------------------------------------- fixture: one event, two dates, "Standard" on both */
$fe5_type_a = make_type( 50, 10, 0 );
$fe5_occ_a = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$fe5_type_a" );
$fe5_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$fe5_occ_a" );
$fe5_name = (string) $wpdb->get_var( "SELECT name FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$fe5_type_a" );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $fe5_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$fe5_occ_b = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $fe5_occ_b, 'name' => $fe5_name, 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$fe5_type_b = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $fe5_occ_b, 'name' => 'FE5 VIP', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$fe5_type_vip = (int) $wpdb->insert_id;
foreach ( array( $fe5_type_a, $fe5_type_b, $fe5_type_vip ) as $fe5_t ) {
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $fe5_t, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $fe5_t, 'field_key' => 'gesundheit', 'label' => 'Gesundheit', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
}
$fe5_buy = static function ( $type, $qty, $tag ) {
	wpcap_test_reset_transients();
	$attendees = array();
	for ( $i = 0; $i < $qty; $i++ ) { $attendees[] = array( 'ticket_type_id' => $type, 'name' => "FE5 $tag $i", 'answers' => array( 'firma' => "Firma $tag", 'gesundheit' => "Allergie $tag" ) ); }
	return Orders::create( array( 'email' => "fe5-$tag-" . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'fe5-' . uniqid(), 'attendees' => $attendees ) );
};
$fe5_o1 = $fe5_buy( $fe5_type_a, 2, 'a' );
$fe5_o2 = $fe5_buy( $fe5_type_b, 1, 'b' );
$fe5_o3 = $fe5_buy( $fe5_type_vip, 1, 'v' );
check( 'fixture: three free orders over two dates (same ticket type name on both, VIP on the second)', ! is_wp_error( $fe5_o1 ) && ! is_wp_error( $fe5_o2 ) && ! is_wp_error( $fe5_o3 ) && 4 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=$fe5_event AND t.status='valid'" ) );
// One ticket of the first date revoked, so the status sort has two groups.
$fe5_revoked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_type_id=%d ORDER BY ticket_id LIMIT 1", $fe5_type_a ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => $fe5_revoked ) );

/* ---------------------------------------------------------------- FE-05: ticket list over several dates */
$fe5_by_name = Queries::search_tickets( array( 'event_id' => $fe5_event, 'type_name' => $fe5_name ), null, 1, 50 );
$fe5_occs = array_values( array_unique( array_map( static function ( $r ) { return (int) $r->occurrence_id; }, $fe5_by_name['rows'] ) ) );
sort( $fe5_occs );
check( 'FE-05: filter by ticket type name finds the type on every date of the event', 3 === $fe5_by_name['total'] && array( $fe5_occ_a, $fe5_occ_b ) === $fe5_occs && ! in_array( 'FE5 VIP', array_column( $fe5_by_name['rows'], 'ticket_name' ), true ) );
$fe5_name_valid = Queries::search_tickets( array( 'event_id' => $fe5_event, 'type_name' => $fe5_name, 'status' => 'valid' ), null, 1, 50 );
check( 'FE-05: type name and status filter combine', 2 === $fe5_name_valid['total'] );
$fe5_type_wins = Queries::search_tickets( array( 'event_id' => $fe5_event, 'ticket_type_id' => $fe5_type_vip, 'type_name' => $fe5_name ), null, 1, 50 );
check( 'FE-05: a chosen ticket type of one date wins over the name filter', 1 === $fe5_type_wins['total'] );
$fe5_status_sorted = array_map( static function ( $r ) { return (string) $r->status; }, Queries::search_tickets( array( 'event_id' => $fe5_event, 'orderby' => 'status' ), null, 1, 50 )['rows'] );
check( 'FE-05: sort by status (valid before revoked), offered in the sort select', array( 'valid', 'valid', 'valid', 'revoked' ) === $fe5_status_sorted && isset( Queries::ticket_orders()['status'] ) );
$fe5_f = TicketsPage::filters( array( 'event_id' => (string) $fe5_event, 'type_name' => " $fe5_name ", 'orderby' => 'status' ) );
$fe5_g = TicketsPage::filters( array( 'type_name' => $fe5_name ) );
$fe5_h = TicketsPage::filters( array( 'event_id' => '3', 'ticket_type_id' => '9', 'type_name' => 'X' ) );
check( 'FE-05: request filters: type name trimmed, only with an event, not together with a single ticket type', $fe5_name === $fe5_f['type_name'] && 'status' === $fe5_f['orderby'] && '' === $fe5_g['type_name'] && '' === $fe5_h['type_name'] && 9 === $fe5_h['ticket_type_id'] );
$fe5_opts = TicketsPage::type_name_options( $fe5_event );
check( 'FE-05: the name filter lists each ticket type name of the event once', 2 === count( $fe5_opts ) && isset( $fe5_opts[ $fe5_name ], $fe5_opts['FE5 VIP'] ) && false !== strpos( $fe5_opts[ $fe5_name ], '(alle Termine)' ) );
check( 'FE-05: list state keeps the type name (pagination, views)', $fe5_name === ( TicketsPage::list_args( $fe5_f )['type_name'] ?? '' ) );
$fe5_back = new ReflectionMethod( TicketsPage::class, 'back_url' );
$fe5_back->setAccessible( true );
parse_str( (string) wp_parse_url( $fe5_back->invoke( null, array( 'return_event' => (string) $fe5_event, 'return_type_name' => $fe5_name, 'return_orderby' => 'status' ) ), PHP_URL_QUERY ), $fe5_q );
check( 'FE-05: type name and status sort survive an action', $fe5_name === ( $fe5_q['type_name'] ?? '' ) && 'status' === ( $fe5_q['orderby'] ?? '' ) );
check( 'FE-05: CSV link arguments: type name (also from a chosen type), list status valid/checked_in only', array( 'type_name' => $fe5_name, 'status' => 'valid' ) === TicketsPage::csv_filter_args( array( 'type_name' => $fe5_name, 'status' => 'valid' ) ) && array( 'type_name' => 'FE5 VIP' ) === TicketsPage::csv_filter_args( array( 'ticket_type_id' => $fe5_type_vip, 'status' => 'revoked' ) ) && array() === TicketsPage::csv_filter_args( array() ) );

/* ---------------------------------------------------------------- FE-05: attendee CSV over dates, filtered */
$fe5_scope = AttendeeList::event_scope( $fe5_event );
$fe5_all = iterator_to_array( AttendeeList::event_row_stream( $fe5_scope['occurrences'], 'full' ), false );
$fe5_named = iterator_to_array( AttendeeList::event_row_stream( $fe5_scope['occurrences'], 'full', array( 'type_name' => $fe5_name ) ), false );
check( 'FE-05: event CSV unfiltered unchanged (3 valid tickets), filtered by type name over both dates', 3 === count( $fe5_all ) && 2 === count( $fe5_named ) && array( $fe5_name ) === array_values( array_unique( array_column( $fe5_named, 2 ) ) ) && 2 === count( array_unique( array_column( $fe5_named, 7 ) ) ) );
$fe5_checked = iterator_to_array( AttendeeList::event_row_stream( $fe5_scope['occurrences'], 'basic', array( 'status' => 'checked_in' ) ), false );
check( 'FE-05: status filter (nobody admitted yet → no checked-in rows; basic level too)', array() === $fe5_checked && 3 === count( iterator_to_array( AttendeeList::event_row_stream( $fe5_scope['occurrences'], 'basic', array( 'status' => 'valid' ) ), false ) ) );
$fe5_lf = AttendeeList::list_filter( array( 'type_name' => ' VIP ', 'status' => 'revoked' ) );
check( 'FE-05: CSV request filter sanitised (unknown status dropped)', 'VIP' === $fe5_lf['type_name'] && '' === $fe5_lf['status'] );
$fe5_single = iterator_to_array( AttendeeList::row_stream( $fe5_occ_b, 'full', array( 'type_name' => 'FE5 VIP' ) ), false );
check( 'FE-05: the per-date CSV takes the same filter', 1 === count( $fe5_single ) && 'FE5 VIP' === $fe5_single[0][2] );
$fe5_src = (string) file_get_contents( WPCAP_PATH . 'src/Printing/Page.php' );
check( 'FE-05: the handler passes the filter to both exports and audits that the export was filtered', false !== strpos( $fe5_src, 'AttendeeList::list_filter(' ) && false !== strpos( $fe5_src, "'filtered' =>" ) );

/* ---------------------------------------------------------------- FE-05: rights per participant field */
$fe5_header = AttendeeList::header( $fe5_occ_b, 'full' );
check( 'FE-05: by default every field is visible (header unchanged, all answers)', in_array( 'Gesundheit', $fe5_header, true ) && in_array( 'Firma', $fe5_header, true ) && true === Pii::can_view_answer( 'gesundheit', true ) );
$fe5_deny = static function ( $allowed, $key ) { return 'gesundheit' === $key ? false : $allowed; };
add_filter( 'wpcap_pii_can_view_answer', $fe5_deny, 10, 2 );
$fe5_header2 = AttendeeList::header( $fe5_occ_b, 'full' );
$fe5_rows2 = iterator_to_array( AttendeeList::row_stream( $fe5_occ_b, 'full' ), false );
$fe5_ev_header = AttendeeList::event_header( $fe5_scope['occurrences'], 'full' );
$fe5_ev_rows = iterator_to_array( AttendeeList::event_row_stream( $fe5_scope['occurrences'], 'full' ), false );
$fe5_order_tickets = Queries::order_tickets( (int) $fe5_o2['order_id'] );
remove_filter( 'wpcap_pii_can_view_answer', $fe5_deny, 10 );
check( 'FE-05: a field denied by wpcap_pii_can_view_answer is left out of the attendee list (column and answers)', ! in_array( 'Gesundheit', $fe5_header2, true ) && in_array( 'Firma', $fe5_header2, true ) && count( $fe5_header2 ) === count( $fe5_rows2[0] ) && false === strpos( wp_json_encode( $fe5_rows2 ), 'Allergie' ) && false !== strpos( wp_json_encode( $fe5_rows2 ), 'Firma b' ) );
check( 'FE-05: … and out of the export over all dates', ! in_array( 'Gesundheit', $fe5_ev_header, true ) && false === strpos( wp_json_encode( $fe5_ev_rows ), 'Allergie' ) && count( $fe5_ev_header ) === count( $fe5_ev_rows[0] ) );
check( 'FE-05: … and out of the tickets of the order detail', isset( $fe5_order_tickets[0]->answers['Firma'] ) && ! isset( $fe5_order_tickets[0]->answers['Gesundheit'] ) );
check( 'FE-05: without the filter the order detail shows the field again', isset( Queries::order_tickets( (int) $fe5_o2['order_id'] )[0]->answers['Gesundheit'] ) );
$fe5_mask = static function ( $allowed, $field ) { return 'buyer_email' === $field ? false : $allowed; };
add_filter( 'wpcap_pii_can_view', $fe5_mask, 10, 2 );
$fe5_rows3 = iterator_to_array( AttendeeList::row_stream( $fe5_occ_b, 'full' ), false );
remove_filter( 'wpcap_pii_can_view', $fe5_mask, 10 );
check( 'FE-05: the e-mail columns of the full list follow the PII matrix per field (buyer address masked)', false !== strpos( (string) $fe5_rows3[0][5], '***@' ) && false === strpos( wp_json_encode( array_column( $fe5_rows3, 5 ) ), 'fe5-' ) && false !== strpos( wp_json_encode( array_column( iterator_to_array( AttendeeList::row_stream( $fe5_occ_b, 'full' ), false ), 5 ) ), 'fe5-' ) );

/* ---------------------------------------------------------------- FE-04: order portal from the customer account */
$fe5_form = OrderLinkRequest::account_form_html( 'Kundin@Example.com' );
check( 'FE-04: account form posts the order address to the link request (nonce, honeypot, no portal token)', false !== strpos( $fe5_form, 'name="wpcap_order_link_email" value="kundin@example.com"' ) && false !== strpos( $fe5_form, 'name="_wpnonce"' ) && false !== strpos( $fe5_form, 'name="website" value=""' ) && false === strpos( $fe5_form, 'wpcap_access' ) && false !== strpos( $fe5_form, 'Link zur Bestellverwaltung per E-Mail anfordern' ) );
check( 'FE-04: no form for an empty or placeholder address', '' === OrderLinkRequest::account_form_html( '' ) && '' === OrderLinkRequest::account_form_html( 'x@invalid.example' ) );
$fe5_sub = OrderLinkRequest::submit( array( 'wpcap_order_link_email' => 'kundin@example.com', '_wpnonce' => wp_create_nonce( 'wpcap_order_link_request' ), 'website' => '' ) );
check( 'FE-04: the posted account form is accepted like the public form (neutral answer)', false === $fe5_sub['error'] && OrderLinkRequest::generic_message() === $fe5_sub['message'] );
$fe5_product = new WC_Product( 93050 );
$fe5_product->update_meta_data( Fe05Woo::PRODUCT_META, $fe5_type_vip );
$GLOBALS['wpcap_test_products'][93050] = $fe5_product;
$fe5_wc = new WC_Order( 93050 );
$fe5_wc->items[] = new WC_Order_Item_Product( 93050, $fe5_product, 1, '0.00', '0' );
$fe5_core = Fe05Woo::reserve( $fe5_wc );
Fe05Woo::sync_paid_order( 93050 );
$fe5_render = static function () use ( $fe5_wc ) { ob_start(); Fe05Woo::render_customer_tickets( $fe5_wc ); return (string) ob_get_clean(); };
$fe5_thanks = $fe5_render();
$GLOBALS['wpcap_test_filters']['wpcap_woo_account_order_view'] = true;
$fe5_account = $fe5_render();
unset( $GLOBALS['wpcap_test_filters']['wpcap_woo_account_order_view'] );
$fe5_core_email = is_int( $fe5_core ) ? (string) $wpdb->get_var( "SELECT customer_email FROM {$p}wpcap_orders WHERE order_id=$fe5_core" ) : '';
check( 'FE-04: the account order view offers the portal link request for the order address, the thank-you page does not', is_int( $fe5_core ) && false !== strpos( $fe5_account, 'wpcap-account-portal-link' ) && false !== strpos( $fe5_account, esc_attr( $fe5_core_email ) ) && false === strpos( $fe5_thanks, 'wpcap-account-portal-link' ) && false !== strpos( $fe5_thanks, 'Deine Tickets' ) );
check( 'FE-04: outside the account (no logged-in view-order endpoint) no form is shown', false === Fe05Woo::is_account_order_view() );
if ( is_int( $fe5_core ) ) { Orders::cancel_order( $fe5_core, 'fe5_cleanup' ); }
