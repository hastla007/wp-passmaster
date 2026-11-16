<?php
/**
 * A651: the privacy export of a buyer leaves out the holder data of tickets they transferred
 * (admissions, wallet passes, name, answers); the new holder's own export still has them.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Privacy;

global $wpdb;
$p = $wpdb->prefix;
echo "A651: privacy export of transferred tickets\n";
$GLOBALS['wpcap_test_transients'] = array();
$x1_type = make_type( 10, 10, 0 );
$x1_buyer = 'x1-a651-buyer@example.com';
$x1_holder = 'x1-a651-holder@example.com';
$x1_o = Orders::create( array( 'email' => $x1_buyer, 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 2 ) ), 'idempotency_key' => 'x1-a651-' . uniqid() ) );
$x1_t = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $x1_o['order_id'] ) ) );
// Ticket 0 was passed on (as Orders::transfer_ticket() leaves it), ticket 1 stayed with the buyer.
$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $x1_holder, 'transfer_count' => 1 ), array( 'ticket_id' => $x1_t[0] ) );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $x1_t[0], 'attendee_name' => 'Hanna Halterin', 'attendee_email' => $x1_holder, 'buyer_email' => $x1_buyer, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
foreach ( $x1_t as $tid ) {
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $tid, 'event_id' => 1, 'user_id' => 1, 'result' => 'checked_in', 'source' => 'web', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $tid, 'provider' => 'apple', 'serial_number' => 'x1-a651-serial-' . $tid, 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
}
$x1_flat = static function ( $export ) { return (string) wp_json_encode( $export['data'] ?? array() ); };
$x1_buyer_export = $x1_flat( Privacy::export( $x1_buyer, 1 ) );
$x1_holder_export = $x1_flat( Privacy::export( $x1_holder, 1 ) );
check( 'A651: the buyer export has no wallet serial or admission of the transferred ticket', false === strpos( $x1_buyer_export, 'x1-a651-serial-' . $x1_t[0] ) );
check( 'A651: the buyer export has no name the new holder entered', false === strpos( $x1_buyer_export, 'Hanna Halterin' ) );
check( 'A651: the buyer export still lists the transfer and the own ticket with its data', false !== strpos( $x1_buyer_export, 'x1-a651-serial-' . $x1_t[1] ) && false !== strpos( $x1_buyer_export, 'An eine andere Person' ) );
check( 'A651: the new holder\'s export has the transferred ticket with name, admission and wallet pass', false !== strpos( $x1_holder_export, 'x1-a651-serial-' . $x1_t[0] ) && false !== strpos( $x1_holder_export, 'Hanna Halterin' ) && false !== strpos( $x1_holder_export, 'checked_in' ) );
