<?php
/**
 * R7 (audit round 2, expert #9): complimentary tickets / guest list without the box office
 * (Sales\Comps over Orders::create( …, ['comp' => true] )).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders as R7bOrders;
use PassCaptain\Sales\Comps as R7bComps;
use PassCaptain\Reports\Data as R7bReport;

global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();

echo "R7 comps: tickets without cash session, price 0, capacity applies\n";
$r7b_type = make_type( 5, 2, 4900 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $r7b_type, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 1, 'position' => 1, 'status' => 'active' ) );
$GLOBALS['wpcap_test_mails'] = array();
$r7b = R7bComps::issue( array( 'ticket_type_id' => $r7b_type, 'recipient_email' => 'vip@example.com', 'recipient_name' => 'Vera VIP', 'quantity' => 3, 'batch_key' => 'b1' ) );
check( 'single recipient: 3 tickets issued (above max_per_order 2, required field may follow)', ! is_wp_error( $r7b ) && 3 === $r7b['issued'] && 1 === count( $r7b['orders'] ) );
$r7b_order = is_wp_error( $r7b ) ? null : R7bOrders::get( $r7b['orders'][0] );
check( 'order is paid, 0 €, provider comp, no terms version', $r7b_order && 'paid' === $r7b_order->payment_status && 'complete' === $r7b_order->status && 0 === (int) $r7b_order->total_minor && 'comp' === $r7b_order->payment_provider && '' === (string) $r7b_order->terms_version );
check( 'three valid tickets with the guest name', $r7b_order && 3 === count( array_filter( R7bOrders::tickets_for_order( $r7b_order->order_id ), static fn( $t ) => 'valid' === $t->status ) ) && 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a INNER JOIN %i t ON t.ticket_id=a.ticket_id WHERE t.order_id=%d AND a.attendee_name='Vera VIP'", $p . 'wpcap_ticket_assignments', $p . 'wpcap_tickets', $r7b_order->order_id ) ) );
check( 'no box office sale, receipt or fiscal document', $r7b_order && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', $p . 'wpcap_boxoffice_sales', $r7b_order->order_id ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', $p . 'wpcap_fiscal_documents', $r7b_order->order_id ) ) );
check( 'audit entry comp_tickets_issued', 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action='comp_tickets_issued' AND object_id=%d", $p . 'wpcap_audit_logs', $r7b_order ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $r7b_type ) ) : -1 ) ) );
$r7b_again = R7bComps::issue( array( 'ticket_type_id' => $r7b_type, 'recipient_email' => 'vip@example.com', 'recipient_name' => 'Vera VIP', 'quantity' => 3, 'batch_key' => 'b1' ) );
check( 'resubmitting the same batch issues nothing new', ! is_wp_error( $r7b_again ) && 0 === $r7b_again['issued'] && $r7b_again['orders'] === $r7b['orders'] );
check( 'availability counts comp tickets (5 - 3 = 2)', 2 === R7bOrders::availability( $r7b_type ) );
$r7b_list = R7bComps::issue( array( 'ticket_type_id' => $r7b_type, 'guest_list' => "Name,E-Mail\nAnna Gast,anna@example.com\nBert Gast;bert@example.com\nCarla,carla@example.com", 'batch_key' => 'b2' ) );
check( 'guest list: capacity stops the third guest (2 left)', ! is_wp_error( $r7b_list ) && 2 === $r7b_list['issued'] && 1 === count( $r7b_list['failed'] ) && 'carla@example.com' === $r7b_list['failed'][0]['email'] );
check( 'invalid guest list line is refused before anything is issued', is_wp_error( R7bComps::parse_guest_list( "Anna,anna@example.com\nkaputt" ) ) );
check( 'customer checkout cannot claim the comp idempotency key namespace', ! is_wp_error( $r7b_list ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE idempotency_key=%s', $p . 'wpcap_orders', hash( 'sha256', 'comp:b2:2:anna@example.com' ) ) ) );

echo "R7 comps: report filter Freikarten\n";
$r7b_from = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$r7b_to = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
$r7b_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $r7b_type ) );
$r7b_comp = R7bReport::build( array( 'from' => $r7b_from, 'to' => $r7b_to, 'event_id' => $r7b_event, 'channel' => 'comp' ), null );
$r7b_sales = R7bReport::build( array( 'from' => $r7b_from, 'to' => $r7b_to, 'event_id' => $r7b_event, 'channel' => 'sales' ), null );
check( 'channel=comp counts the 5 complimentary tickets', 5 === (int) ( $r7b_comp['total']['tickets'] ?? -1 ) );
check( 'channel=sales excludes them', 0 === (int) ( $r7b_sales['total']['tickets'] ?? -1 ) );
