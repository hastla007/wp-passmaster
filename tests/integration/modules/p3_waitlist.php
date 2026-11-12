<?php
/**
 * Waitlist: automatic offers on released capacity, inventory holds for offers, quantity fit,
 * accept → pay → tickets, decline, expiry → next entry, order expiry sync, staff actions,
 * idempotency.
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Waitlists;
use PassCaptain\Waitlists\AdminPage as WaitlistAdmin;

global $wpdb;
$p = $wpdb->prefix;

Waitlists::register();
$wl_saved = array_intersect_key( Settings::all(), array_flip( array( 'enabled_providers', 'require_terms', 'waitlist' ) ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0, 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0 ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
update_option( Waitlists::QUEUE_OPTION, array() );

function wl_ctx( $type ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	return array( $event, $occ );
}
function wl_entry( $type, $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $type, $email ) );
}
function wl_join( $type, $email, $qty = 1 ) {
	list( $event, $occ ) = wl_ctx( $type );
	$_SERVER['REMOTE_ADDR'] = '10.9.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	return Waitlists::join( $event, $occ, $type, $email, $qty );
}
function wl_token( $email ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
}
function wl_last_mail( $email ) {
	$mails = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $email ) );
	return $mails ? $mails[ count( $mails ) - 1 ] : array( 'body' => '' );
}
function wl_offer_mails( $email ) {
	return count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $email && false !== strpos( $m['body'], 'wpcap_waitlist_accept=' ) ) );
}
function wl_lapse( $entry ) {
	global $wpdb;
	$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", $past, (int) $entry->order_id ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET offer_expires_at=%s WHERE waitlist_id=%d", $past, (int) $entry->waitlist_id ) );
}

echo "Waitlist: automatic offers hold inventory\n";
$type = make_type( 2, 4, 1000 );
list( $event, $occ ) = wl_ctx( $type );
$blocker = Orders::create( array( 'email' => 'wl-blocker@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'wl-block-1' ) );
check( 'setup: ticket type sold out by a pending order', ! is_wp_error( $blocker ) && 0 === Orders::availability( $type ) );
foreach ( array( 'wl-a@example.com' => 1, 'wl-b@example.com' => 2, 'wl-c@example.com' => 1, 'wl-d@example.com' => 1, 'wl-e@example.com' => 1 ) as $email => $qty ) { wl_join( $type, $email, $qty ); }
check( 'join stores requested quantity and FIFO position', 2 === (int) wl_entry( $type, 'wl-b@example.com' )->quantity && 2 === (int) wl_entry( $type, 'wl-b@example.com' )->position && 5 === (int) wl_entry( $type, 'wl-e@example.com' )->position );
check( 'join: quantity above max_per_order is rejected', is_wp_error( wl_join( $type, 'wl-big@example.com', 5 ) ) );
check( 'nothing is offered while sold out', 0 === Waitlists::run_due( true ) && 'waiting' === wl_entry( $type, 'wl-a@example.com' )->status );

Orders::cancel_order( $blocker['order_id'], 'test' );
check( 'capacity release queues the ticket type', isset( Waitlists::queued()[ $type ] ) );
$made = Waitlists::run_due();
$a = wl_entry( $type, 'wl-a@example.com' );
$c = wl_entry( $type, 'wl-c@example.com' );
check( 'released units are offered to the next entries whose quantity fits', 2 === $made && 'offered' === $a->status && 'offered' === $c->status && 'waiting' === wl_entry( $type, 'wl-b@example.com' )->status && 'waiting' === wl_entry( $type, 'wl-d@example.com' )->status );
$a_order = Orders::get( $a->order_id );
check( 'offer creates a pending placeholder order with a hold until the offer deadline', $a_order && Waitlists::OFFER_PROVIDER === $a_order->payment_provider && 'pending' === $a_order->payment_status && (string) $a->offer_expires_at > gmdate( 'Y-m-d H:i:s', time() + 23 * 3600 ) );
check( 'held units are not available to the public', 0 === Orders::availability( $type ) );
$public = Orders::create( array( 'email' => 'wl-public@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'wl-public-1' ) );
check( 'a public buyer cannot take the held unit', is_wp_error( $public ) );
$offer_mail = wl_last_mail( 'wl-a@example.com' );
check( 'offer mail (waitlist_offer) has accept link, quantity and deadline', '' !== wl_token( 'wl-a@example.com' ) && false !== strpos( $offer_mail['body'], 'reserviert' ) && false !== strpos( $offer_mail['body'], '1 ×' ) );

echo "Waitlist: idempotency\n";
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
Waitlists::queue( array( $type ) );
check( 'processing again makes no second offer', 0 === Waitlists::run_due( true ) && 0 === Waitlists::process_type( $type ) && count( $GLOBALS['wpcap_test_mails'] ) === $mails_before );
check( 'an offered entry cannot be offered twice', is_wp_error( Waitlists::offer( $a->waitlist_id ) ) );
echo "Waitlist: accept → pay → tickets\n";
check( 'invalid token is rejected', is_wp_error( Waitlists::accept_offer( $a->waitlist_id, str_repeat( 'ab', 32 ) ) ) );
$accepted = Waitlists::accept_offer( $a->waitlist_id, wl_token( 'wl-a@example.com' ) );
$a_order = Orders::get( $a->order_id );
check( 'accept assigns the payment method and keeps the hold', ! is_wp_error( $accepted ) && true === $accepted['inventory_reserved'] && 'manual' === $a_order->payment_provider && 'accepted' === wl_entry( $type, 'wl-a@example.com' )->status && (int) $accepted['order']['order_id'] === (int) $a->order_id );
check( 'accepting twice fails', is_wp_error( Waitlists::accept_offer( $a->waitlist_id, wl_token( 'wl-a@example.com' ) ) ) );
check( 'still not available to the public after accept', 0 === Orders::availability( $type ) );
$paid = Orders::confirm_manual_payment( $a->order_id );
check( 'payment issues the tickets and marks the entry paid', true === $paid && 1 === count( tickets( $a->order_id, 'valid' ) ) && 'paid' === wl_entry( $type, 'wl-a@example.com' )->status );

echo "Waitlist: decline and expiry move on\n";
$declined = Waitlists::decline_offer( $c->waitlist_id, wl_token( 'wl-c@example.com' ) );
check( 'decline releases the hold', ! is_wp_error( $declined ) && 'declined' === wl_entry( $type, 'wl-c@example.com' )->status && 'cancelled' === Orders::get( $c->order_id )->status );
Waitlists::run_due();
$d = wl_entry( $type, 'wl-d@example.com' );
check( 'after a decline the next fitting entry is offered (2-ticket entry passed over)', 'offered' === $d->status && 'waiting' === wl_entry( $type, 'wl-b@example.com' )->status );
wl_lapse( $d );
Waitlists::run_due( true );
$e = wl_entry( $type, 'wl-e@example.com' );
check( 'an expired offer is closed and the next entry is offered', 'expired' === wl_entry( $type, 'wl-d@example.com' )->status && 'expired' === Orders::get( $d->order_id )->status && 'offered' === $e->status );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
Waitlists::on_capacity_released( (int) $d->order_id, 'expired', array() );
Waitlists::on_capacity_released( (int) $d->order_id, 'expired', array() );
Waitlists::run_due( true );
check( 'repeated release events for the same order change nothing', 'expired' === wl_entry( $type, 'wl-d@example.com' )->status && 'offered' === wl_entry( $type, 'wl-e@example.com' )->status && count( $GLOBALS['wpcap_test_mails'] ) === $mails_before );

echo "Waitlist: accepted order expires unpaid\n";
Waitlists::accept_offer( $e->waitlist_id, wl_token( 'wl-e@example.com' ) );
check( 'setup: accepted', 'accepted' === wl_entry( $type, 'wl-e@example.com' )->status );
expire_holds_of( $e->order_id );
Orders::expire_holds();
check( 'unpaid accepted order expiring marks the entry expired', 'expired' === wl_entry( $type, 'wl-e@example.com' )->status && isset( Waitlists::queued()[ $type ] ) );
check( 'no entry fits one free unit: the 2-ticket entry keeps waiting', 0 === Waitlists::run_due() && 'waiting' === wl_entry( $type, 'wl-b@example.com' )->status && 1 === Orders::availability( $type ) );

echo "Waitlist: capacity increase\n";
$wpdb->update( "{$p}wpcap_ticket_types", array( 'capacity' => 3 ), array( 'ticket_type_id' => $type ) );
do_action( 'wpcap_capacity_increased', $type, 0 );
Waitlists::run_due();
$b = wl_entry( $type, 'wl-b@example.com' );
$b_items = Orders::items_for_order( $b->order_id );
check( 'capacity increase offers the 2-ticket entry with a 2-unit hold', 'offered' === $b->status && 2 === (int) ( $b_items[0]->quantity ?? 0 ) && 0 === Orders::availability( $type ) );
check( 'mail states the quantity', false !== strpos( wl_last_mail( 'wl-b@example.com' )['body'], '2 ×' ) );

echo "Waitlist: rejoin, staff skip/remove, auto off\n";
wl_join( $type, 'wl-d@example.com', 1 );
$d2 = wl_entry( $type, 'wl-d@example.com' );
check( 'an expired entry can join again at the end of the queue', 'waiting' === $d2->status && (int) $d2->position > 5 && 0 === (int) $d2->order_id );
$skip = Waitlists::close( $b->waitlist_id, 'skipped' );
check( 'skipping an open offer withdraws it and releases its hold', ! is_wp_error( $skip ) && 'skipped' === wl_entry( $type, 'wl-b@example.com' )->status && 'cancelled' === Orders::get( $b->order_id )->status );
Waitlists::run_due();
check( 'released units of a skipped offer go to the next entry', 'offered' === wl_entry( $type, 'wl-d@example.com' )->status );
check( 'skipped/removed entries cannot be closed again', is_wp_error( Waitlists::close( $b->waitlist_id, 'removed' ) ) );

Settings::update( array( 'waitlist' => array( 'auto_offers' => 0, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 1 ) ) );
$type2 = make_type( 1, 4, 0 );
list( $event2, $occ2 ) = wl_ctx( $type2 );
$free = Orders::create( array( 'email' => 'wl-free-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $type2, 'quantity' => 1 ) ), 'idempotency_key' => 'wl-free-1' ) );
wl_join( $type2, 'wl-f1@example.com', 2 );
wl_join( $type2, 'wl-f2@example.com', 1 );
Orders::revoke_tickets( $free['order_id'], $type2, 1, 'test' );
check( 'auto offers off: a release makes no offer', 1 === Orders::availability( $type2 ) && 0 === Waitlists::run_due( true ) && 'waiting' === wl_entry( $type2, 'wl-f2@example.com' )->status );
check( 'strict FIFO: a larger entry first blocks smaller entries behind it', 0 === Waitlists::process_event_now( $event2 ) && 'waiting' === wl_entry( $type2, 'wl-f2@example.com' )->status );
Settings::update( array( 'waitlist' => array( 'auto_offers' => 0, 'offer_hours' => 2, 'max_offers_per_run' => 10, 'strict_fifo' => 0 ) ) );
$f1 = wl_entry( $type2, 'wl-f1@example.com' );
check( 'staff priority moves an entry ahead', array( 'priority' => 5 ) === Waitlists::set_priority( wl_entry( $type2, 'wl-f2@example.com' )->waitlist_id, 5 ) );
$res = WaitlistAdmin::entry_action( array( 'op' => 'process', 'event_id' => $event2 ) );
$f2 = wl_entry( $type2, 'wl-f2@example.com' );
check( 'staff "offer now" works with auto offers off; offer validity setting applies', ! is_wp_error( $res ) && 'offered' === $f2->status && (string) $f2->offer_expires_at < gmdate( 'Y-m-d H:i:s', time() + 2 * 3600 + 120 ) );
check( 'free ticket offer holds the unit without issuing tickets', 'pending' === Orders::get( $f2->order_id )->payment_status && 0 === count( tickets( $f2->order_id ) ) && 0 === Orders::availability( $type2 ) );
$free_accept = Waitlists::accept_offer( $f2->waitlist_id, wl_token( 'wl-f2@example.com' ) );
check( 'accepting a free offer completes the order and issues tickets', ! is_wp_error( $free_accept ) && 'paid' === $free_accept['status'] && 1 === count( tickets( $f2->order_id, 'valid' ) ) && 'paid' === wl_entry( $type2, 'wl-f2@example.com' )->status );
$rm = WaitlistAdmin::entry_action( array( 'op' => 'remove', 'event_id' => $event2, 'waitlist_id' => $f1->waitlist_id ) );
check( 'staff can remove a waiting entry', ! is_wp_error( $rm ) && 'removed' === wl_entry( $type2, 'wl-f1@example.com' )->status );
$saved = WaitlistAdmin::save_settings( array( 'auto_offers' => '1', 'offer_hours' => '999', 'max_offers_per_run' => '0' ) );
check( 'settings are clamped (validity 1–336 h, 1–100 offers per run)', 336 === Waitlists::config()['offer_hours'] && 1 === Waitlists::config()['max_offers_per_run'] && 1 === Waitlists::config()['auto_offers'] );

echo "Waitlist: max offers per run\n";
$type3 = make_type( 3, 4, 500 );
$block3 = Orders::create( array( 'email' => 'wl-block3@example.com', 'items' => array( array( 'ticket_type_id' => $type3, 'quantity' => 3 ) ), 'idempotency_key' => 'wl-block-3' ) );
foreach ( array( 'wl-g1@example.com', 'wl-g2@example.com', 'wl-g3@example.com' ) as $email ) { wl_join( $type3, $email ); }
Orders::cancel_order( $block3['order_id'], 'test' );
check( 'one offer per run with max_offers_per_run=1', 1 === Waitlists::run_due( true ) && 'offered' === wl_entry( $type3, 'wl-g1@example.com' )->status && 'waiting' === wl_entry( $type3, 'wl-g2@example.com' )->status );
check( 'one offer mail per entry', 1 === wl_offer_mails( 'wl-g1@example.com' ) );

// Leave the shared test runtime as it was.
foreach ( array( 'wpcap_capacity_released', 'wpcap_capacity_increased', 'wpcap_order_paid', Waitlists::HOOK, 'wpcap_process_jobs', 'wpcap_cleanup_reservations' ) as $wl_hook ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $wl_hook ] ?? array() as $wl_prio => $wl_callbacks ) {
		foreach ( $wl_callbacks as $wl_i => $wl_cb ) {
			if ( is_array( $wl_cb[0] ) && Waitlists::class === $wl_cb[0][0] ) { unset( $GLOBALS['wpcap_test_hooks'][ $wl_hook ][ $wl_prio ][ $wl_i ] ); }
		}
	}
}
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] );
update_option( Waitlists::QUEUE_OPTION, array() );
Settings::update( $wl_saved );
