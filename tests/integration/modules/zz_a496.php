<?php
/**
 * A496 (rest after A537): a buyer's cancellation request and a change of the ticket holder never both
 * go through – otherwise the new holder loses the ticket while the buyer gets the money.
 * - transfer while a refund is open: refused (A537, re-checked here),
 * - holder change through the assignment while a refund is open: refused (new),
 * - a portal request for a ticket transferred meanwhile: refused under the order lock (new),
 * - execution of a buyer refund whose ticket was transferred anyway: conflict, nothing paid (new).
 * A refund of a transferred ticket requested by staff stays possible (their decision).
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;

global $wpdb;
$p = $wpdb->prefix;

echo "A496: refund requests and holder changes exclude each other\n";
$a496_saved = get_option( PassCaptain\Settings::OPTION, array() );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$a496_type = make_type( 30, 10, 2500 );
/** Paid order with 1 ticket and a known access token: [order, token, ticket]. */
function a496_order( $type ) {
	global $wpdb;
	$key = 'a496-' . bin2hex( random_bytes( 6 ) );
	$o = Orders::create( array( 'email' => $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => $key ) );
	Orders::confirm_manual_payment( $o['order_id'] );
	$token = bin2hex( random_bytes( 32 ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $o['order_id'] ) );
	return array( Orders::get( $o['order_id'] ), $token, $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,public_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", $o['order_id'] ) ) );
}
function a496_ticket( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT status,refunded,transfer_count,attendee_email,token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $id ) ); }
function a496_portal_request( $order, $ticket ) { return Refunds::request( (int) $order->order_id, array( (int) $ticket->ticket_id ), 'Portal', 'portal|a496-' . bin2hex( random_bytes( 8 ) ), array( 'system' => true, 'require_approval' => true, 'source' => 'portal' ) ); }

// 1. Open refund: transfer (A537) and holder change through the assignment are refused.
list( $o, $tok, $t ) = a496_order( $a496_type );
$r = a496_portal_request( $o, $t );
$tr = Orders::transfer_ticket( $o->public_id, $tok, $t->public_id, 'friend@example.com' );
check( 'A496: transfer while the cancellation request is open: 409 refund_open (A537)', ! is_wp_error( $r ) && is_wp_error( $tr ) && 'wpcap_ticket_refund_open' === $tr->get_error_code() );
$before = a496_ticket( $t->ticket_id );
$as = PassCaptain\TicketAssignments::assign( $o->public_id, $tok, $t->public_id, 'Neue Person', 'neu-a496@example.com' );
$after = a496_ticket( $t->ticket_id );
check( 'A496: holder change through the assignment while the request is open: 409, code and holder unchanged', is_wp_error( $as ) && 'wpcap_ticket_refund_open' === $as->get_error_code() && 409 === ( $as->get_error_data()['status'] ?? 0 ) && $before->token_hash === $after->token_hash && '' === (string) $after->attendee_email );
$fix = PassCaptain\TicketAssignments::assign( $o->public_id, $tok, $t->public_id, 'Käuferin Korrigiert', '' );
check( 'A496: a name correction for the same holder stays possible', ! is_wp_error( $fix ) && false === $fix['code_rotated'] );
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'rejected' ), array( 'refund_id' => $r['refund_id'] ) );
$as2 = PassCaptain\TicketAssignments::assign( $o->public_id, $tok, $t->public_id, 'Neue Person', 'neu-a496@example.com' );
check( 'A496: after the decision the holder change works again', ! is_wp_error( $as2 ) && true === $as2['code_rotated'] );

// 2. Transferred between the portal check and the request (race): the request is refused under the lock.
list( $o, $tok, $t ) = a496_order( $a496_type );
$tr = Orders::transfer_ticket( $o->public_id, $tok, $t->public_id, 'empfaenger-a496@example.com' );
$r = a496_portal_request( $o, $t );
check( 'A496: buyer request for a ticket transferred meanwhile: 409 ticket_transferred, no refund row', ! is_wp_error( $tr ) && is_wp_error( $r ) && 'wpcap_refund_ticket_transferred' === $r->get_error_code() && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $o->order_id ) ) );
$staff = Refunds::request( (int) $o->order_id, array( (int) $t->ticket_id ), 'Kulanz durch Veranstalter', 'a496-staff-' . uniqid(), array( 'require_approval' => true ) );
check( 'A496: staff may still request a refund of a transferred ticket (own decision)', ! is_wp_error( $staff ) && 'pending_approval' === $staff['status'] );

// 3. Execution: a buyer refund whose ticket was transferred anyway (older version, manual change) is not paid out.
list( $o, $tok, $t ) = a496_order( $a496_type );
$r = a496_portal_request( $o, $t );
$wpdb->update( "{$p}wpcap_tickets", array( 'transfer_count' => 1, 'attendee_email' => 'empfaenger2-a496@example.com' ), array( 'ticket_id' => $t->ticket_id ) );
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'approved' ), array( 'refund_id' => $r['refund_id'] ) );
$ex = Refunds::execute( $r['refund_id'] );
$after = a496_ticket( $t->ticket_id );
check( 'A496: executing the buyer refund of a transferred ticket fails (conflict), the recipient keeps the ticket, nothing refunded', is_wp_error( $ex ) && 'wpcap_refund_conflict' === $ex->get_error_code() && 'failed' === Refunds::get( $r['refund_id'] )['status'] && 'valid' === $after->status && 0 === (int) $after->refunded && 0 === (int) Orders::get( $o->order_id )->refunded_minor );

update_option( PassCaptain\Settings::OPTION, $a496_saved );
