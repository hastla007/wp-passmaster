<?php
/**
 * A131: refunds of tickets already used for admission need an explicit exception (reason + approver
 * capability) and always a second approval; system flows (cancellation) and the box office (own
 * exception check) stay possible.
 */
use PassCaptain\Refunds;

echo "A131: refund after check-in needs an exception\n";
global $wpdb;
$p = $wpdb->prefix;
$a131_type = ex_type( ex_occ( ex_event( 'Nach Einlass' ) ), 'Einlass', 3000 );
list( $a131_order, $a131_tickets ) = ex_buy( $a131_type, 2, 'a131@example.com' );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a131_tickets[0] ) );

check( 'used_ticket_ids finds the checked-in ticket only', array( $a131_tickets[0] ) === Refunds::used_ticket_ids( $a131_tickets ) );
$a131_plain = Refunds::request( $a131_order, array( $a131_tickets[0] ), 'Kulanz', 'a131-plain-' . uniqid() );
check( 'refund of a used ticket without exception is refused (409, names the count)', is_wp_error( $a131_plain ) && 'wpcap_refund_used_tickets' === $a131_plain->get_error_code() && false !== strpos( $a131_plain->get_error_message(), 'Ausnahme' ) );
check( 'refused attempt leaves the ticket unrefunded', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT refunded FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a131_tickets[0] ) ) );
$a131_unused = Refunds::request( $a131_order, array( $a131_tickets[1] ), 'Storno', 'a131-unused-' . uniqid() );
check( 'unused ticket of the same order refunds as before', is_array( $a131_unused ) && 'pending_approval' !== $a131_unused['status'] );

$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$a131_noperm = Refunds::request( $a131_order, array( $a131_tickets[0] ), 'Kulanz', 'a131-noperm-' . uniqid(), array( 'used_override' => 'Abbruch' ) );
check( 'without approver capability the exception is not possible', is_wp_error( $a131_noperm ) && 'wpcap_refund_used_tickets' === $a131_noperm->get_error_code() && false !== strpos( $a131_noperm->get_error_message(), 'Freigabeberechtigung' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

$a131_ok = Refunds::request( $a131_order, array( $a131_tickets[0] ), 'Kulanz', 'a131-ok-' . uniqid(), array( 'used_override' => 'Veranstaltung nach 20 min abgebrochen' ) );
check( 'with reason and capability: request created, always pending second approval', is_array( $a131_ok ) && 'pending_approval' === $a131_ok['status'] );
check( 'the exception and its reason are part of the refund reason', is_array( $a131_ok ) && false !== strpos( (string) $a131_ok['reason'], 'Ausnahme: Veranstaltung nach 20 min abgebrochen' ) );

// System flows and the box office (own exception check) are not blocked.
list( $a131_o2, $a131_t2 ) = ex_buy( $a131_type, 1, 'a131b@example.com' );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a131_t2[0] ) );
$a131_sys = Refunds::request( $a131_o2, $a131_t2, 'Termin abgesagt', 'a131-sys-' . uniqid(), array( 'system' => true ) );
check( 'system refund (cancelled date) of a used ticket is allowed', is_array( $a131_sys ) );
list( $a131_o3, $a131_t3 ) = ex_buy( $a131_type, 1, 'a131c@example.com' );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a131_t3[0] ) );
$a131_pos = Refunds::request( $a131_o3, $a131_t3, 'POS-Storno', 'a131-pos-' . uniqid(), array( 'allow_used' => true ) );
check( 'box office path (allow_used after its own check) is allowed', is_array( $a131_pos ) );
check( 'the admin refund form passes the exception field', false !== strpos( file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/OrdersPage.php' ), "'used_override' => Forms::text( \$in, 'used_override', 200 )" ) );
