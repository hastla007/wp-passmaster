<?php
/**
 * A536: waitlist offers for ticket types with required participant fields (offer without attendee
 * data, fields collected and enforced on acceptance).
 * A563: an entry that cannot be offered (buyer limit, quantity) is blocked with a reason and the queue
 * continues; the buyer limit is checked when joining.
 * Included from run.php (uses check(), make_type(), tickets()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Modules\Allocations;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Waitlists;
use PassCaptain\Waitlists\AdminPage as WaitlistAdmin;

global $wpdb;
$p = $wpdb->prefix;

echo "A536/A563: waitlist offers with required fields, blocked entries, buyer limit at sign-up\n";
Waitlists::register();
Allocations::register();
$w5_saved = array_intersect_key( Settings::all(), array_flip( array( 'enabled_providers', 'require_terms', 'waitlist' ) ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0, 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0 ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
$w5_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
$GLOBALS['wpcap_test_user_id'] = 0;
update_option( Waitlists::QUEUE_OPTION, array() );

$w5_ctx = static function ( $type ) use ( $wpdb, $p ) {
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
	return array( (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" ), $occ );
};
$w5_join = static function ( $type, $email, $qty = 1 ) use ( $w5_ctx ) {
	list( $event, $occ ) = $w5_ctx( $type );
	$_SERVER['REMOTE_ADDR'] = '10.53.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	$GLOBALS['wpcap_test_transients'] = array();
	return Waitlists::join( $event, $occ, $type, $email, $qty );
};
$w5_entry = static function ( $type, $email ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $type, $email ) );
};
$w5_token = static function ( $email ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
};
$w5_buy = static function ( $type, $email, $qty, array $extra = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'w5-' . uniqid( '', true ) ) + $extra );
};

// --- A536: required participant field ------------------------------------------------------------
$w5_type = make_type( 1, 10, 0 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $w5_type, 'field_key' => 'birth', 'label' => 'Geburtsdatum', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
$w5_a = $w5_buy( $w5_type, 'w5-a@example.com', 1, array( 'attendees' => array( array( 'ticket_type_id' => $w5_type, 'name' => 'A', 'answers' => array( 'birth' => '1990' ) ) ) ) );
check( 'A536 setup: the only ticket is sold (free order)', ! is_wp_error( $w5_a ) && 0 === Orders::availability( $w5_type ) );
$w5_join( $w5_type, 'w5-wait@example.com' );
Orders::cancel_order( $w5_a['order_id'], 'test' );
$w5_made = Waitlists::run_due( true );
$w5_e = $w5_entry( $w5_type, 'w5-wait@example.com' );
check( 'A536: an offer is made for a ticket type with a required field (no attendee data needed)', 1 === $w5_made && 'offered' === $w5_e->status && (int) $w5_e->order_id > 0 );
$w5_tok = $w5_token( 'w5-wait@example.com' );
$w5_form = Waitlists::attendee_form_html( (int) $w5_e->order_id );
check( 'A536: the offer page collects the attendee fields', false !== strpos( $w5_form, 'attendees[0][answers][birth]' ) && false !== strpos( $w5_form, 'required' ) );
$w5_missing = Waitlists::accept_offer( (int) $w5_e->waitlist_id, $w5_tok, array( 'attendees' => array() ) );
check( 'A536: accepting without the required field is refused and the offer stays open', is_wp_error( $w5_missing ) && 'wpcap_attendee_field_required' === $w5_missing->get_error_code() && 'offered' === $w5_entry( $w5_type, 'w5-wait@example.com' )->status );
$w5_ok = Waitlists::accept_offer( (int) $w5_e->waitlist_id, $w5_tok, array( 'attendees' => array( array( 'ticket_type_id' => $w5_type, 'name' => 'Wartende', 'answers' => array( 'birth' => '1985' ) ) ) ) );
$w5_tickets = tickets( (int) $w5_e->order_id, 'valid' );
$w5_answer = $w5_tickets ? $wpdb->get_var( $wpdb->prepare( "SELECT a.answer FROM {$p}wpcap_participant_answers a INNER JOIN {$p}wpcap_participant_fields f ON f.field_id=a.field_id WHERE a.ticket_id=%d AND f.field_key='birth'", (int) $w5_tickets[0]->ticket_id ) ) : null;
check( 'A536: accepting with the data issues the ticket with the answer', ! is_wp_error( $w5_ok ) && 'paid' === $w5_ok['status'] && 1 === count( $w5_tickets ) && '1985' === $w5_answer );
$w5_plain = $w5_buy( $w5_type, 'w5-plain@example.com', 1 );
check( 'A536: the regular checkout still enforces required fields', is_wp_error( $w5_plain ) && 'wpcap_attendee_field_required' === $w5_plain->get_error_code() );

// --- A563: buyer limit at sign-up and blocked entries ---------------------------------------------
$w6_type = make_type( 3, 10, 2500 );
list( $w6_event ) = $w5_ctx( $w6_type );
BuyerLimits::set( $w6_event, 0, 2 );
BuyerLimits::flush();
$w6_fan = $w5_buy( $w6_type, 'w6-fan@example.com', 2 );
Orders::confirm_external_payment( $w6_fan['order_id'], 'w6' );
$w6_other = $w5_buy( $w6_type, 'w6-other@example.com', 1 );
check( 'A563 setup: sold out, the fan holds the per-person maximum', ! is_wp_error( $w6_other ) && 0 === Orders::availability( $w6_type ) );
$w6_refused = $w5_join( $w6_type, 'w6-fan@example.com' );
check( 'A563: joining the waitlist over the buyer limit is refused', is_wp_error( $w6_refused ) && 'wpcap_waitlist_buyer_limit' === $w6_refused->get_error_code() && ! $w5_entry( $w6_type, 'w6-fan@example.com' ) );
// An entry that passed the sign-up but is over the limit when offered (bought meanwhile): insert directly.
$wpdb->insert( "{$p}wpcap_waitlist", array( 'event_id' => $w6_event, 'occurrence_id' => $w5_ctx( $w6_type )[1], 'ticket_type_id' => $w6_type, 'email_hash' => hash( 'sha256', 'w6-fan@example.com' ), 'email' => 'w6-fan@example.com', 'position' => 1, 'quantity' => 1, 'status' => 'waiting', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$w5_join( $w6_type, 'w6-next@example.com' );
Orders::cancel_order( $w6_other['order_id'], 'test' );
$w6_made = Waitlists::run_due( true );
$w6_fan_e = $w5_entry( $w6_type, 'w6-fan@example.com' );
$w6_next_e = $w5_entry( $w6_type, 'w6-next@example.com' );
check( 'A563: the entry over the buyer limit does not block the queue (next entry gets the offer)', 1 === $w6_made && 'offered' === $w6_next_e->status );
check( 'A563: the entry is marked blocked with its reason', 'blocked' === $w6_fan_e->status && 'buyer_limit' === $w6_fan_e->block_reason && '' !== Waitlists::block_reason_label( $w6_fan_e->block_reason ) );
$w6_rows = Waitlists::entries( $w6_event );
check( 'A563: the admin list shows the blocked entry with reason', (bool) array_filter( $w6_rows, static fn( $r ) => 'blocked' === $r->status && 'buyer_limit' === $r->block_reason ) && 'Nicht anbietbar' === Waitlists::status_label( 'blocked' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$w6_unblock = WaitlistAdmin::entry_action( array( 'op' => 'unblock', 'event_id' => $w6_event, 'waitlist_id' => (int) $w6_fan_e->waitlist_id ) );
$w6_fan_e = $w5_entry( $w6_type, 'w6-fan@example.com' );
check( 'A563: staff can put a blocked entry back into the queue', is_array( $w6_unblock ) && 'waiting' === $w6_fan_e->status && '' === $w6_fan_e->block_reason );
$GLOBALS['wpcap_test_user_id'] = 0;

// Maximum per order lowered after the sign-up: blocked (quantity_limit), next entry served.
$w7_type = make_type( 2, 10, 0 );
$w7_hold = $w5_buy( $w7_type, 'w7-hold@example.com', 2 );
$w5_join( $w7_type, 'w7-big@example.com', 2 );
$w5_join( $w7_type, 'w7-small@example.com', 1 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'max_per_order' => 1 ), array( 'ticket_type_id' => $w7_type ) );
Orders::cancel_order( $w7_hold['order_id'], 'test' );
Waitlists::run_due( true );
check( 'A563: an entry above the maximum per order is blocked, the next one is offered', 'blocked' === $w5_entry( $w7_type, 'w7-big@example.com' )->status && 'quantity_limit' === $w5_entry( $w7_type, 'w7-big@example.com' )->block_reason && 'offered' === $w5_entry( $w7_type, 'w7-small@example.com' )->status );

BuyerLimits::set( $w6_event, 0, 0 );
BuyerLimits::flush();
Settings::update( $w5_saved );
$GLOBALS['wpcap_test_user_id'] = $w5_user;
update_option( Waitlists::QUEUE_OPTION, array() );
// The test hook stub keeps duplicate callbacks: drop the retention step added above again, a second
// run of it would report 0 anonymised entries (like zz_a158.php).
foreach ( array_reverse( $GLOBALS['wpcap_test_hooks']['wpcap_privacy_retention_steps'][10] ?? array(), true ) as $w5_i => $w5_cb ) {
	if ( is_array( $w5_cb[0] ) && Waitlists::class === $w5_cb[0][0] ) { unset( $GLOBALS['wpcap_test_hooks']['wpcap_privacy_retention_steps'][10][ $w5_i ] ); break; }
}
