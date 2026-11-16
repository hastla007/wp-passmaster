<?php
/**
 * A581: an attendee address entered at checkout alone does not make a ticket "held elsewhere" – the
 * ticket mail went to the buyer only, so the portal PDF, the "send me my order link" form and the
 * reminders stay with the buyer until the current code is actually delivered to someone else
 * (transfer, assignment to another address, resend/reissue to the attendee address).
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;
use PassCaptain\TicketCodes;

echo "A581: who holds the ticket code (checkout attendee address)\n";
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\ServiceBookings::flush_schema_cache();

check( 'A581: schema 3.10.18 column wpcap_tickets.code_with_attendee exists', TicketCodes::holder_ready() );
$a581_type = make_type( 20, 10, 0 );
$a581_o = Orders::create( array( 'email' => 'kaeufer581@example.com', 'items' => array( array( 'ticket_type_id' => $a581_type, 'quantity' => 3 ) ), 'attendees' => array( array( 'ticket_type_id' => $a581_type, 'name' => 'Gast Eins', 'email' => 'gast581@example.com' ), array( 'ticket_type_id' => $a581_type, 'name' => 'Gast Zwei', 'email' => 'zwei581@example.com' ) ), 'idempotency_key' => 'a581-' . uniqid() ) );
$a581_order = Orders::get( $a581_o['order_id'] );
$a581_state = static function ( $ticket_id ) use ( $a581_order ) {
	foreach ( PortalSelfService::tickets( $a581_order ) as $t ) { if ( (int) $t->ticket_id === (int) $ticket_id ) { return PortalSelfService::pdf_state( $t, $a581_order ); } }
	return '';
};
$a581_t = PortalSelfService::tickets( $a581_order );
$a581_mails_to = static function ( $to ) { return count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $to === $m['to'] && str_starts_with( (string) $m['subject'], 'Deine Tickets' ) ) ); };
check( 'A581 fixture: attendee addresses stored, the ticket mail went to the buyer only', 'gast581@example.com' === $a581_t[0]->attendee_email && 0 === $a581_mails_to( 'gast581@example.com' ) && $a581_mails_to( 'kaeufer581@example.com' ) >= 1 );
check( 'A581: the buyer can download the PDF of a ticket with a checkout attendee address', 'available' === $a581_state( $a581_t[0]->ticket_id ) );
check( 'A581: the ticket page offers the buyer\'s order link for it', PortalSelfService::can_request_order_link( $a581_t[0], $a581_order ) );

// Reminders go to whoever holds the code: the buyer.
$a581_holders = $wpdb->get_col( $wpdb->prepare( "SELECT LOWER(" . TicketCodes::holder_email_sql() . ") FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.order_id=%d ORDER BY t.ticket_id", $a581_o['order_id'] ) );
check( 'A581: reminder address is the buyer while the code is with the buyer', array( 'kaeufer581@example.com', 'kaeufer581@example.com', 'kaeufer581@example.com' ) === $a581_holders );

// Reissue to the attendee address: now the attendee holds the code.
$a581_re = Orders::reissue_ticket( (int) $a581_t[0]->ticket_id, array( 'reason' => 'A581' ) );
check( 'A581: after a reissue mailed to the attendee address the ticket is held elsewhere', is_array( $a581_re ) && 'gast581@example.com' === $a581_re['recipient'] && 'held_elsewhere' === $a581_state( $a581_t[0]->ticket_id ) && ! PortalSelfService::can_request_order_link( PortalSelfService::tickets( $a581_order )[0], $a581_order ) );
check( 'A581: … and reminders go to the attendee', 'gast581@example.com' === strtolower( (string) $wpdb->get_var( $wpdb->prepare( "SELECT " . TicketCodes::holder_email_sql() . " FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d", (int) $a581_t[0]->ticket_id ) ) ) );

// A resend of the whole order goes to the attendee addresses of the tickets.
$a581_resend = Orders::resend_tickets( $a581_o['order_id'] );
check( 'A581: after a resend the attendee-addressed tickets are held elsewhere, the buyer\'s own stays available', true === $a581_resend && 'held_elsewhere' === $a581_state( $a581_t[1]->ticket_id ) && 'available' === $a581_state( $a581_t[2]->ticket_id ) );

// Upgrade: existing tickets with an attendee address are marked unless the audit log proves the buyer
// received the only code.
$a581_o2 = Orders::create( array( 'email' => 'alt581@example.com', 'items' => array( array( 'ticket_type_id' => $a581_type, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $a581_type, 'name' => 'Alt', 'email' => 'altgast581@example.com' ) ), 'idempotency_key' => 'a581b-' . uniqid() ) );
$a581_old = tickets( $a581_o2['order_id'] )[0];
$wpdb->query( "UPDATE {$p}wpcap_tickets SET code_with_attendee=1 WHERE ticket_id=" . (int) $a581_t[1]->ticket_id );
PassCaptain\Installer::backfill_code_holder( '3.10.17' );
$a581_flag = static function ( $id ) { global $wpdb; return (int) $wpdb->get_var( "SELECT code_with_attendee FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=" . (int) $id ); };
check( 'A581: upgrade keeps "with the buyer" for tickets whose code provably went to the buyer only', 0 === $a581_flag( $a581_old->ticket_id ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET issued_at=%s WHERE ticket_id=%d", '2001-01-01 00:00:00', (int) $a581_old->ticket_id ) );
PassCaptain\Installer::backfill_code_holder( '3.10.17' );
check( 'A581: upgrade marks tickets older than the audit log as held elsewhere (conservative)', 1 === $a581_flag( $a581_old->ticket_id ) && false === PassCaptain\Installer::backfill_code_holder( '3.10.18' ) );
