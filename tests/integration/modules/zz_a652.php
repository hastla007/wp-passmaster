<?php
/**
 * A652: the mail to the previous holder (transfer confirmation, assignment notice to the buyer) never
 * carries the new code – not even through a custom template with {ticket_link}/{ticket_extras} – and
 * the message queue no longer uses its stored payload as a template.
 */
// phpcs:ignoreFile
use PassCaptain\Communications;
use PassCaptain\Mail\Mailer;
use PassCaptain\Mail\Templates;
use PassCaptain\Orders;
use PassCaptain\TicketAssignments;

global $wpdb;
$p = $wpdb->prefix;
echo "A652: no new code for the previous holder\n";
$GLOBALS['wpcap_test_transients'] = array();
$x1_locale = Mailer::site_locale();
foreach ( array( 'ticket_transfer_sender', 'ticket_assigned_buyer' ) as $x1_type_key ) {
	Templates::save( $x1_type_key, $x1_locale, array( 'subject' => 'X1 A652 {event_name}', 'body' => 'LINK=[{ticket_link}] EXTRA=[{ticket_extras}]', 'format' => 'text' ) );
}
$x1_type = make_type( 10, 10, 0 );
$x1_buyer = 'x1-a652-buyer@example.com';
$x1_o = Orders::create( array( 'email' => $x1_buyer, 'items' => array( array( 'ticket_type_id' => $x1_type, 'quantity' => 2 ) ), 'idempotency_key' => 'x1-a652-' . uniqid() ) );
$x1_order = Orders::get( $x1_o['order_id'] );
$x1_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $x1_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $x1_o['order_id'] ) );
$x1_tickets = $wpdb->get_col( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $x1_o['order_id'] ) );
$x1_mails_to = static function ( $to, $from ) { return array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $from ), static fn( $m ) => $to === $m['to'] ) ); };

$x1_before = count( $GLOBALS['wpcap_test_mails'] );
$x1_tr = Orders::transfer_ticket( $x1_order->public_id, $x1_token, $x1_tickets[0], 'x1-a652-friend@example.com' );
$x1_sender = $x1_mails_to( $x1_buyer, $x1_before );
$x1_recipient = $x1_mails_to( 'x1-a652-friend@example.com', $x1_before );
check( 'A652: the transfer works and the new holder gets the code link', ! is_wp_error( $x1_tr ) && $x1_recipient && false !== strpos( (string) $x1_recipient[0]['body'], 'wpcap_ticket=' ) );
check( 'A652: the confirmation to the buyer has no ticket link or code section, even with {ticket_link} in the template', 1 === count( $x1_sender ) && false !== strpos( (string) $x1_sender[0]['body'], 'LINK=' ) && false === strpos( (string) $x1_sender[0]['body'], 'wpcap_ticket=' ) );

$x1_before = count( $GLOBALS['wpcap_test_mails'] );
$x1_as = TicketAssignments::assign( $x1_order->public_id, $x1_token, $x1_tickets[1], 'Alex Neu', 'x1-a652-alex@example.com' );
$x1_buyer_note = $x1_mails_to( $x1_buyer, $x1_before );
check( 'A652: the assignment notice to the buyer has no new ticket link', ! is_wp_error( $x1_as ) && 1 === count( $x1_buyer_note ) && false === strpos( (string) $x1_buyer_note[0]['body'], 'wpcap_ticket=' ) );
check( 'A652: without_code_vars() drops link, links, extras and help code', array( 'event_name' => 'E' ) === Orders::without_code_vars( array( 'event_name' => 'E', 'ticket_link' => 'x', 'ticket_links' => 'y', 'ticket_extras' => 'z', 'help_code' => 'K7QM' ) ) );
foreach ( array( 'ticket_transfer_sender', 'ticket_assigned_buyer' ) as $x1_type_key ) { Templates::reset( $x1_type_key, $x1_locale ); }

// Queue: a payload is never a template.
Communications::enqueue( 'x1-a652-q-' . uniqid(), 'x1-a652-q@example.com', 'x1_a652_raw', array( 'subject' => 'Gefälscht', 'body' => 'Payload als Vorlage' ), '2000-01-01 00:00:00' );
$x1_before = count( $GLOBALS['wpcap_test_mails'] );
Communications::process_template( 'x1_a652_raw' );
$x1_q = $wpdb->get_row( "SELECT status,last_error FROM {$p}wpcap_message_queue WHERE template_key='x1_a652_raw'" );
check( 'A652: a queued message without renderer is not sent from its payload', ! $x1_mails_to( 'x1-a652-q@example.com', $x1_before ) && $x1_q && 'sent' !== $x1_q->status && false !== strpos( (string) $x1_q->last_error, 'x1_a652_raw' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_message_queue WHERE template_key='x1_a652_raw'" );
