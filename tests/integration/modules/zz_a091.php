<?php
/**
 * A091: ticket page with order number and wallet links; ticket PDFs named after the readable order number.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Frontend\Format;
use PassCaptain\TicketPdf;
use PassCaptain\WalletPasses\Service;

echo "A091: ticket page and PDF names\n";
global $wpdb;
$a091_type = make_type( 10, 10, 1000 );
$a091_o = PassCaptain\Orders::create( array( 'email' => 'a091-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a091_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a091-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A091: fixture order', ! is_wp_error( $a091_o ) );
if ( ! is_wp_error( $a091_o ) ) {
	$a091_order = PassCaptain\Orders::get( (int) $a091_o['order_id'] );
	$a091_tid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", (int) $a091_o['order_id'] ) );
	if ( ! $a091_tid ) { PassCaptain\Orders::confirm_external_payment( (int) $a091_o['order_id'], 'a091' ); $a091_tid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", (int) $a091_o['order_id'] ) ); }
	$a091_issued = array( array( 'ticket_id' => $a091_tid, 'ticket_type_id' => $a091_type, 'token' => str_repeat( 'a', 64 ), 'event' => 'Konzert', 'ticket' => 'Standard', 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien' ) );
	$a091_att = TicketPdf::mail_attachment( $a091_order, $a091_issued );
	$a091_name = isset( $a091_att['files'][0] ) ? basename( $a091_att['files'][0] ) : '';
	check( 'A091: the mailed ticket PDF is named after the readable order number', '' === $a091_name || 'tickets-' . Format::order_reference( $a091_order->public_id ) . '.pdf' === $a091_name );
	if ( ! empty( $a091_att['files'] ) ) { PassCaptain\Mail\TicketAttachment::remove( $a091_att ); }
	check( 'A091: without a wallet pass state the ticket page shows no wallet buttons', array() === Service::ticket_links( $a091_tid ) );
}
$a091_src = (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' );
check( 'A091: the ticket page shows the order number and wallet links', false !== strpos( $a091_src, 'ord.public_id AS order_public_id' ) && false !== strpos( $a091_src, 'Frontend\\Format::order_reference( (string) $ticket->order_public_id )' ) && false !== strpos( $a091_src, 'WalletPasses\\Service::ticket_links(' ) );
