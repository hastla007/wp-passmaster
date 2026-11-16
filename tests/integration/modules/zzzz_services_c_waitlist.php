<?php
/**
 * Waitlists, part C: slot waitlist for full service slots (ordered queue, time-limited offer that
 * holds the place, automatic next offer on decline/expiry, transparent position, accept/leave) and
 * event waitlist automation (capacity freed → job → offer with hold; decline, unsubscribe, expiry →
 * next; capacity increase; manual offer without hold).
 *
 * Included from run.php after zzzz_services_a_rules.php (svc_* helpers).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\ServiceWaitlist;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
// Queue mechanics without the double opt-in step (covered by zzzzzz_z1_security.php).
$GLOBALS['wpcap_test_options'][ PassCaptain\Settings::OPTION ]['waitlist_double_opt_in'] = 0;

/** Token of the last mail to $email whose body matches $param=<64 hex>. */
function svc_token_from_mail( $email, $param ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $m ) {
		if ( $m['to'] === $email && preg_match( '/' . preg_quote( $param, '/' ) . '=(\d+)(?:&amp;|&)(?:do=[a-z]+(?:&amp;|&))?token=([a-f0-9]{64})/', $m['body'], $mm ) ) { return array( (int) $mm[1], $mm[2] ); }
	}
	return array( 0, '' );
}
function svc_entry( $entry_id ) { return ServiceWaitlist::get( $entry_id ); }
function svc_run_jobs() { svc_reset_limits(); return Jobs::process_due( 100 ); }

echo "Waitlist for full service slots\n";
list( $wl_event ) = svc_event();
$wl_res = svc_resource( $wl_event, array( 'capacity' => 1, 'rules' => array( 'waitlist_offer_minutes' => 30 ) ) );
$wl_slot = svc_slot( $wl_res, 80, 60, 1 );
$holder = svc_book( $wl_slot, 'holder@svc.test' );
ServiceBookings::confirm_free( $holder['booking_id'], $holder['manage_token'] );
$open_slot = svc_slot( $wl_res, 90, 60, 2 );
$not_full = ServiceWaitlist::join( $open_slot, 'early@svc.test' );
check( 'joining the waitlist of a slot with free places is refused (book directly)', is_wp_error( $not_full ) && 'wpcap_waitlist_slot_available' === $not_full->get_error_code() );
svc_reset_limits();
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$ja = ServiceWaitlist::join( $wl_slot, 'wait-a@svc.test' );
$jb = ServiceWaitlist::join( $wl_slot, 'wait-b@svc.test' );
$ja2 = ServiceWaitlist::join( $wl_slot, 'WAIT-A@svc.test' );
list( $ea, $ta ) = svc_token_from_mail( 'wait-a@svc.test', 'wpcap_service_waitlist' );
list( $eb, $tb ) = svc_token_from_mail( 'wait-b@svc.test', 'wpcap_service_waitlist' );
$a_mail = svc_mails_to( 'wait-a@svc.test', $mails_before );
check( 'join: uniform response, no duplicate entry, mail with position, status and leave link', $ja === $jb && $ja === $ja2 && ! isset( $ja['position'] ) && 1 === count( $a_mail ) && str_contains( $a_mail[0]['body'], 'Position 1' ) && str_contains( $a_mail[0]['body'], 'do=leave' ) && 2 === count( ServiceWaitlist::entries( $wl_slot ) ) );
$pos_a = ServiceWaitlist::position( $ea, $ta );
$pos_b = ServiceWaitlist::position( $eb, $tb );
check( 'transparent position via token; wrong token refused', 1 === $pos_a['position'] && 2 === $pos_b['position'] && 'waiting' === $pos_b['status'] && is_wp_error( ServiceWaitlist::position( $ea, $tb ) ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
ServiceBookings::cancel( $holder['booking_id'], array( 'actor' => 'customer' ) );
svc_run_jobs();
$ea_row = svc_entry( $ea );
check( 'a freed place is offered to position 1 via the job queue; the offer holds it for 30 minutes', 'offered' === $ea_row->status && abs( strtotime( $ea_row->offer_expires_at . ' UTC' ) - ( time() + 1800 ) ) < 120 && 'waiting' === svc_entry( $eb )->status && 1 === ServiceWaitlist::held_quantity( $wl_slot ) && 0 === svc_free( $wl_slot ) );
list( , $offer_a ) = svc_token_from_mail( 'wait-a@svc.test', 'wpcap_service_waitlist' );
check( 'offer mail with accept and decline links (German default)', (bool) array_filter( svc_mails_to( 'wait-a@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['subject'], 'frei geworden' ) && str_contains( $m['body'], 'do=accept' ) && str_contains( $m['body'], 'do=decline' ) ) && 64 === strlen( $offer_a ) );
$sneak = svc_book( $wl_slot, 'sneaky@svc.test' );
check( 'nobody else can book the held place', is_wp_error( $sneak ) && 'wpcap_slot_full' === $sneak->get_error_code() );
check( 'position of the next entry stays transparent while an offer is open', 2 === ServiceWaitlist::position( $eb, $tb )['position'] );
ServiceWaitlist::decline( $ea, $offer_a );
svc_run_jobs();
check( 'decline: the offer moves on to the next entry automatically', 'declined' === svc_entry( $ea )->status && 'offered' === svc_entry( $eb )->status );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_waitlist SET offer_expires_at=%s WHERE entry_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $eb ) );
list( , $offer_b ) = svc_token_from_mail( 'wait-b@svc.test', 'wpcap_service_waitlist' );
check( 'an expired offer cannot be accepted', is_wp_error( ServiceWaitlist::accept( $eb, $offer_b ) ) );
ServiceWaitlist::sweep();
check( 'sweep expires the offer; with nobody left the place is free again', 'expired' === svc_entry( $eb )->status && 1 === svc_free( $wl_slot ) && svc_audit( 'service_waitlist_offer_expired', $wl_slot ) );
// Accept on a free resource → confirmed booking; repeated accept is idempotent.
$holder2 = svc_book( $wl_slot, 'holder2@svc.test' );
ServiceBookings::confirm_free( $holder2['booking_id'], $holder2['manage_token'] );
svc_reset_limits();
ServiceWaitlist::join( $wl_slot, 'wait-c@svc.test' );
list( $ec, $tc ) = svc_token_from_mail( 'wait-c@svc.test', 'wpcap_service_waitlist' );
ServiceBookings::cancel( $holder2['booking_id'], array( 'actor' => 'customer' ) );
svc_run_jobs();
list( , $offer_c ) = svc_token_from_mail( 'wait-c@svc.test', 'wpcap_service_waitlist' );
$acc = ServiceWaitlist::accept( $ec, $offer_c );
$acc_again = ServiceWaitlist::accept( $ec, $offer_c );
check( 'accept on a free resource books the held place (confirmed); repeated accept returns the same booking', 'accepted' === ( $acc['status'] ?? '' ) && 'confirmed' === $acc['booking']['status'] && 'accepted' === svc_entry( $ec )->status && ! empty( $acc_again['reused'] ) && $acc_again['booking']['booking_id'] === $acc['booking']['booking_id'] && 1 === ServiceResources::booked_quantity( $wl_slot ) && 0 === ServiceWaitlist::held_quantity( $wl_slot ) );
// Leave and strict FIFO.
$fifo_slot = svc_slot( $wl_res, 110, 60, 1 );
$fifo_holder = svc_book( $fifo_slot, 'fifo-holder@svc.test' );
ServiceBookings::confirm_free( $fifo_holder['booking_id'], $fifo_holder['manage_token'] );
svc_reset_limits();
ServiceWaitlist::join( $fifo_slot, 'fifo-two@svc.test', 2 );
ServiceWaitlist::join( $fifo_slot, 'fifo-one@svc.test', 1 );
ServiceWaitlist::join( $fifo_slot, 'fifo-leave@svc.test', 1 );
list( $e2 ) = svc_token_from_mail( 'fifo-two@svc.test', 'wpcap_service_waitlist' );
list( $e1, $t1 ) = svc_token_from_mail( 'fifo-one@svc.test', 'wpcap_service_waitlist' );
list( $el, $tl ) = svc_token_from_mail( 'fifo-leave@svc.test', 'wpcap_service_waitlist' );
ServiceWaitlist::leave( $el, $tl );
ServiceBookings::cancel( $fifo_holder['booking_id'], array( 'actor' => 'customer' ) );
svc_run_jobs();
check( 'leave removes the entry from the queue; strict order: an entry needing 2 places blocks later ones when only 1 is free', 'left' === svc_entry( $el )->status && 'waiting' === svc_entry( $e2 )->status && 'waiting' === svc_entry( $e1 )->status && 2 === ServiceWaitlist::position( $e1, $t1 )['position'] );
// Paid resource: accept creates an order with payment hold, payment confirms.
list( $wp_event, $wp_type ) = svc_event( 2500 );
$wp_res = svc_resource( $wp_event, array( 'capacity' => 1, 'ticket_type_id' => $wp_type ) );
$wp_slot = svc_slot( $wp_res, 70, 60, 1 );
$wp_holder = svc_book( $wp_slot, 'paid-holder@svc.test', 1, array( 'actor' => 'admin', 'status' => 'confirmed' ) );
svc_reset_limits();
ServiceWaitlist::join( $wp_slot, 'paid-wait@svc.test' );
list( $ep ) = svc_token_from_mail( 'paid-wait@svc.test', 'wpcap_service_waitlist' );
ServiceBookings::cancel( $wp_holder['booking_id'], array( 'actor' => 'admin' ) );
svc_run_jobs();
list( , $offer_p ) = svc_token_from_mail( 'paid-wait@svc.test', 'wpcap_service_waitlist' );
$wp_acc = ServiceWaitlist::accept( $ep, $offer_p );
$wp_booking = ServiceBookings::get( $wp_acc['booking']['booking_id'] ?? 0 );
check( 'paid resource: accepting creates a pending order and a held booking linked to it', $wp_booking && 'held' === $wp_booking->status && (int) $wp_acc['order_id'] === (int) $wp_booking->order_id && 'pending' === Orders::get( $wp_acc['order_id'] )->payment_status );
Orders::confirm_external_payment( $wp_acc['order_id'], 'svc-wl-pay' );
check( 'payment confirms the waitlist booking', 'confirmed' === svc_status( $wp_booking->booking_id ) );

echo "Event waitlist automation\n";
$ew_type = make_type( 1, 10, 1500 );
$ew_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ew_type" );
$ew_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$ew_occ" );
svc_reset_limits();
$ew_sold = Orders::create( array( 'email' => 'sold@svc.test', 'items' => array( array( 'ticket_type_id' => $ew_type, 'quantity' => 1 ) ), 'idempotency_key' => 'ew-sold-' . uniqid() ) );
Orders::confirm_manual_payment( $ew_sold['order_id'] );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
foreach ( array( 'ew-x@svc.test', 'ew-y@svc.test', 'ew-z@svc.test' ) as $mail ) { svc_reset_limits(); Waitlists::join( $ew_event, $ew_occ, $ew_type, $mail ); }
svc_run_jobs();
$ew_row = static fn( $mail ) => $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT * FROM {$GLOBALS['wpdb']->prefix}wpcap_waitlist WHERE email=%s", $mail ) );
check( 'sold out: entries wait, nothing is offered; join mail carries an unsubscribe link', 'waiting' === $ew_row( 'ew-x@svc.test' )->status && (bool) array_filter( svc_mails_to( 'ew-z@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['body'], 'wpcap_waitlist_leave=' ) ) );
list( $ez_id, $ez_manage ) = svc_token_from_mail( 'ew-z@svc.test', 'wpcap_waitlist_leave' );
check( 'unsubscribe with the manage token', is_array( Waitlists::unsubscribe( $ez_id, $ez_manage ) ) && 'unsubscribed' === $ew_row( 'ew-z@svc.test' )->status && is_wp_error( Waitlists::unsubscribe( $ez_id, $ez_manage ) ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$ew_ticket = tickets( $ew_sold['order_id'] )[0];
PassCaptain\Refunds::request( $ew_sold['order_id'], array( (int) $ew_ticket->ticket_id ), 'Rückgabe', 'ew-refund-' . uniqid() );
svc_run_jobs();
$x = $ew_row( 'ew-x@svc.test' );
check( 'a refund frees capacity → job offers it to the next entry with a hold (no manual step)', 'offered' === $x->status && 1 === (int) $x->hold_quantity && 'waiting' === $ew_row( 'ew-y@svc.test' )->status && svc_audit( 'waitlist_offer_made', (int) $x->waitlist_id ) );
check( 'offer mail: reserved note, accept, decline and unsubscribe links', (bool) array_filter( svc_mails_to( 'ew-x@svc.test', $mails_before ), static fn( $m ) => str_contains( $m['body'], 'für dich reserviert' ) && str_contains( $m['body'], 'wpcap_waitlist_accept=' ) && str_contains( $m['body'], 'wpcap_waitlist_decline=' ) && str_contains( $m['body'], 'wpcap_waitlist_leave=' ) ) );
svc_reset_limits();
$ew_public = Orders::create( array( 'email' => 'public@svc.test', 'items' => array( array( 'ticket_type_id' => $ew_type, 'quantity' => 1 ) ), 'idempotency_key' => 'ew-public-' . uniqid() ) );
check( 'the held ticket is not sellable to others (availability 0, checkout refused)', 0 === Orders::availability( $ew_type ) && is_wp_error( $ew_public ) );
list( $ex_id, $ex_token ) = svc_token_from_mail( 'ew-x@svc.test', 'wpcap_waitlist_decline' );
Waitlists::decline_offer( $ex_id, $ex_token );
svc_run_jobs();
$y = $ew_row( 'ew-y@svc.test' );
check( 'decline releases the hold and the next entry gets the offer', 'declined' === $ew_row( 'ew-x@svc.test' )->status && 'offered' === $y->status && 1 === (int) $y->hold_quantity );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_waitlist SET offer_expires_at=%s WHERE waitlist_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $y->waitlist_id ) );
check( 'an expired offer does not block sales any more', 1 === Orders::availability( $ew_type ) );
Waitlists::sweep();
check( 'sweep marks the expired offer; with nobody waiting the ticket is free', 'expired' === $ew_row( 'ew-y@svc.test' )->status && 0 === (int) $ew_row( 'ew-y@svc.test' )->hold_quantity && 1 === Orders::availability( $ew_type ) );
// Capacity increase → offer by the sweep; accept consumes the own hold.
$ew2_type = make_type( 1, 10, 0 );
$ew2_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ew2_type" );
$ew2_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$ew2_occ" );
svc_reset_limits();
Orders::create( array( 'email' => 'free-sold@svc.test', 'items' => array( array( 'ticket_type_id' => $ew2_type, 'quantity' => 1 ) ), 'idempotency_key' => 'ew2-sold-' . uniqid() ) );
svc_reset_limits();
Waitlists::join( $ew2_event, $ew2_occ, $ew2_type, 'ew-cap@svc.test' );
$manual = Waitlists::offer_next( $ew2_event, $ew2_type );
check( 'manual offer while sold out is made without hold (inventory_reserved false)', is_array( $manual ) && false === $manual['inventory_reserved'] && 0 === (int) $ew_row( 'ew-cap@svc.test' )->hold_quantity );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_waitlist SET status='waiting',offer_token_hash='',offer_expires_at=NULL WHERE waitlist_id=%d", (int) $ew_row( 'ew-cap@svc.test' )->waitlist_id ) );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET capacity=2 WHERE ticket_type_id=$ew2_type" );
Waitlists::sweep();
$cap_row = $ew_row( 'ew-cap@svc.test' );
check( 'capacity raised by staff: the sweep offers the new ticket with a hold', 'offered' === $cap_row->status && 1 === (int) $cap_row->hold_quantity && 0 === Orders::availability( $ew2_type ) );
list( $ec_id, $ec_token ) = svc_token_from_mail( 'ew-cap@svc.test', 'wpcap_waitlist_accept' );
$ec_acc = Waitlists::accept_offer( $ec_id, $ec_token );
check( 'accept consumes the own hold and creates the order (free ticket issued at once)', 'accepted' === ( $ec_acc['status'] ?? '' ) && 'accepted' === $ew_row( 'ew-cap@svc.test' )->status && (int) $ec_acc['order']['order_id'] === (int) $ew_row( 'ew-cap@svc.test' )->order_id && 0 === (int) $ew_row( 'ew-cap@svc.test' )->hold_quantity && 1 === count( tickets( $ec_acc['order']['order_id'] ) ) && 0 === Orders::availability( $ew2_type ) );
check( 'an accepted offer cannot be accepted again', is_wp_error( Waitlists::accept_offer( $ec_id, $ec_token ) ) );

unset( $GLOBALS['wpcap_test_options'][ PassCaptain\Settings::OPTION ]['waitlist_double_opt_in'] );
