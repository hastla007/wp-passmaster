<?php
/**
 * A158: waitlist self-unsubscribe (personal link in every waitlist mail, POST confirmation,
 * idempotent, audited, rate-limited, order portal) and races around offers: parallel/double
 * acceptance, accept vs. expiry job, accept vs. decline, accept vs. unsubscribe, an entry removed
 * while its offer is being created. Real parallel runs: tests/load/run.php (scenario waitlist).
 */

// phpcs:ignoreFile

use PassCaptain\Mail\Templates;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Waitlists;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }

global $wpdb;
$p = $wpdb->prefix;

Waitlists::register();
$a158_saved = array_intersect_key( Settings::all(), array_flip( array( 'enabled_providers', 'require_terms', 'waitlist' ) ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0, 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0 ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
update_option( Waitlists::QUEUE_OPTION, array() );
$GLOBALS['wpcap_test_transients'] = array();

function a158_ctx( $type ) {
	global $wpdb;
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
	return array( (int) $wpdb->get_var( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=$occ" ), $occ );
}
function a158_join( $type, $email, $qty = 1 ) {
	list( $event, $occ ) = a158_ctx( $type );
	$_SERVER['REMOTE_ADDR'] = '10.158.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	return Waitlists::join( $event, $occ, $type, $email, $qty );
}
function a158_entry_by_id( $id ) { return Waitlists::get( $id ); }
function a158_entry( $type, $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s ORDER BY waitlist_id DESC LIMIT 1", $type, $email ) );
}
function a158_mails( $email ) { return array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $email ) ); }
function a158_unsub_token( $email, $id = 0 ) {
	foreach ( array_reverse( a158_mails( $email ) ) as $mail ) {
		if ( preg_match( '/wpcap_waitlist_unsubscribe=(\d+)(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) && ( ! $id || (int) $m[1] === (int) $id ) ) { return $m[2]; }
	}
	return '';
}
function a158_offer_token( $email ) {
	foreach ( array_reverse( a158_mails( $email ) ) as $mail ) {
		if ( preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
}
function a158_audits( $action, $id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_type='waitlist' AND object_id=%d", $action, $id ) );
}
/** Sold out type with N waiting entries; returns [type, blocker order id]. */
function a158_sold_out( $capacity, $price, array $emails ) {
	$type = make_type( $capacity, 4, $price );
	$blocker = Orders::create( array( 'email' => 'a158-block-' . $type . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $capacity ) ), 'idempotency_key' => 'a158-block-' . $type ) );
	foreach ( $emails as $email ) { a158_join( $type, $email ); }
	return array( $type, is_wp_error( $blocker ) ? 0 : (int) $blocker['order_id'] );
}
function a158_free_up( $blocker ) {
	Orders::cancel_order( $blocker, 'test' );
	return Waitlists::run_due( true );
}
function a158_lapse_hold( $entry, $offer_too = true ) {
	global $wpdb;
	$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", $past, (int) $entry->order_id ) );
	if ( $offer_too ) { $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET offer_expires_at=%s WHERE waitlist_id=%d", $past, (int) $entry->waitlist_id ) ); }
}
/**
 * Consistency of a ticket type: paid + actively held units within capacity, issued tickets match
 * paid lines, open entries have a pending order with an active hold (or a paid one), closed entries
 * hold nothing.
 */
function a158_consistent( $type, $capacity ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$sold = (int) $wpdb->get_var( "SELECT COALESCE(SUM(oi.quantity-oi.refunded_quantity),0) FROM {$p}wpcap_order_items oi JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id=$type AND o.payment_status='paid'" );
	$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type AND status='active' AND expires_at>'$now'" );
	$tickets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_type_id=$type AND status IN ('valid','checked_in')" );
	if ( $sold + $held > $capacity || $tickets !== $sold ) { return "sold $sold held $held tickets $tickets cap $capacity"; }
	foreach ( $wpdb->get_results( "SELECT w.waitlist_id,w.status,w.order_id,o.payment_status,(SELECT COUNT(*) FROM {$p}wpcap_reservations r WHERE r.order_id=w.order_id AND r.status='active' AND r.expires_at>'$now') AS holds FROM {$p}wpcap_waitlist w LEFT JOIN {$p}wpcap_orders o ON o.order_id=w.order_id WHERE w.ticket_type_id=$type" ) as $row ) {
		$open = in_array( $row->status, array( 'offered', 'accepted' ), true );
		if ( $open && ! ( 'pending' === $row->payment_status && (int) $row->holds ) ) { return "open entry {$row->waitlist_id} without hold"; }
		if ( 'paid' === $row->status && 'paid' !== $row->payment_status ) { return "paid entry {$row->waitlist_id} with unpaid order"; }
		if ( in_array( $row->status, array( 'expired', 'declined', 'removed', 'skipped' ), true ) && (int) $row->order_id && ( 'paid' === $row->payment_status || (int) $row->holds ) ) { return "closed entry {$row->waitlist_id} still holds/paid ({$row->payment_status})"; }
	}
	return '';
}

echo "A158: personal unsubscribe link in waitlist mails\n";
list( $t1, $b1 ) = a158_sold_out( 1, 2500, array( 'a158-u1@example.com', 'a158-u2@example.com', 'a158-u3@example.com' ) );
$u1 = a158_entry( $t1, 'a158-u1@example.com' );
$u1_token = a158_unsub_token( 'a158-u1@example.com', $u1->waitlist_id );
check( 'join mail carries the personal unsubscribe link', '' !== $u1_token && false !== strpos( a158_mails( 'a158-u1@example.com' )[0]['body'], 'austragen' ) );
check( 'only the token hash is stored', hash( 'sha256', $u1_token ) === $u1->unsubscribe_token_hash && false === strpos( json_encode( $u1 ), $u1_token ) );
check( 'tokens are personal (other entry, other token)', '' !== a158_unsub_token( 'a158-u2@example.com' ) && a158_unsub_token( 'a158-u2@example.com' ) !== $u1_token );

Templates::save( 'waitlist_joined', 'de_DE', array( 'subject' => 'Warteliste', 'body' => 'Eingetragen: {event_name}', 'format' => 'text' ) );
a158_join( $t1, 'a158-custom@example.com' );
Templates::reset( 'waitlist_joined', 'de_DE' );
$custom_mail = a158_mails( 'a158-custom@example.com' )[0]['body'] ?? '';
check( 'a customised template without {unsubscribe_link} still gets the link appended', 0 === strpos( $custom_mail, 'Eingetragen:' ) && '' !== a158_unsub_token( 'a158-custom@example.com' ) );

echo "A158: GET shows a confirmation only, POST unsubscribes\n";
list( $code, $html ) = Waitlists::unsubscribe_page( $u1->waitlist_id, $u1_token );
check( 'page (GET) shows a POST form with nonce and changes nothing', 200 === $code && false !== strpos( $html, 'method="post"' ) && false !== strpos( $html, 'admin-post.php' ) && false !== strpos( $html, 'name="_wpnonce"' ) && false !== strpos( $html, 'value="' . Waitlists::UNSUBSCRIBE_ACTION . '"' ) && 'waiting' === a158_entry_by_id( $u1->waitlist_id )->status && 'a158-u1@example.com' === a158_entry_by_id( $u1->waitlist_id )->email );
list( $bad_code ) = Waitlists::unsubscribe_page( $u1->waitlist_id, str_repeat( 'ab', 32 ) );
check( 'wrong token: page 410, unsubscribe refused', 410 === $bad_code && 'wpcap_waitlist_unsubscribe_invalid' === Waitlists::unsubscribe( $u1->waitlist_id, str_repeat( 'ab', 32 ) )->get_error_code() && is_wp_error( Waitlists::unsubscribe( $u1->waitlist_id, 'short' ) ) );
check( 'a token of another entry does not work', is_wp_error( Waitlists::unsubscribe( $u1->waitlist_id, a158_unsub_token( 'a158-u2@example.com' ) ) ) );
$GLOBALS['wpcap_test_actions'] = array();
$res = Waitlists::unsubscribe( $u1->waitlist_id, $u1_token );
$u1_after = a158_entry_by_id( $u1->waitlist_id );
check( 'unsubscribe removes the entry and clears the e-mail', ! is_wp_error( $res ) && 'removed' === $res['status'] && false === $res['already'] && 'removed' === $u1_after->status && '' === $u1_after->email && hash( 'sha256', 'deleted|' . $u1->waitlist_id ) === $u1_after->email_hash );
check( 'unsubscribe is audited and fires wpcap_waitlist_unsubscribed', 1 === a158_audits( 'waitlist_unsubscribed', $u1->waitlist_id ) && in_array( 'wpcap_waitlist_unsubscribed', $GLOBALS['wpcap_test_actions'], true ) );
$again = Waitlists::unsubscribe( $u1->waitlist_id, $u1_token );
list( $code2, $html2 ) = Waitlists::unsubscribe_page( $u1->waitlist_id, $u1_token );
check( 'idempotent: second POST succeeds without a change or a second audit entry', ! is_wp_error( $again ) && true === $again['already'] && 1 === a158_audits( 'waitlist_unsubscribed', $u1->waitlist_id ) && 200 === $code2 && false !== strpos( $html2, 'bereits' ) && false === strpos( $html2, '<form' ) );
check( 'result page texts', false !== strpos( Waitlists::unsubscribe_result_html( $res ), 'ausgetragen' ) && false !== strpos( Waitlists::unsubscribe_result_html( $again ), 'nicht mehr' ) );
a158_join( $t1, 'a158-u1@example.com' );
$u1_new = a158_entry( $t1, 'a158-u1@example.com' );
check( 'the address can join again later (new entry at the end)', $u1_new && (int) $u1_new->waitlist_id !== (int) $u1->waitlist_id && 'waiting' === $u1_new->status );

echo "A158: offer mail link, unsubscribing an open offer releases the place\n";
a158_free_up( $b1 );
$u2 = a158_entry( $t1, 'a158-u2@example.com' );
$u2_join_token = a158_unsub_token( 'a158-u2@example.com' );
$offer_mail = a158_mails( 'a158-u2@example.com' )[ count( a158_mails( 'a158-u2@example.com' ) ) - 1 ]['body'];
check( 'setup: next entry offered', 'offered' === $u2->status && (int) $u2->order_id > 0 && 0 === Orders::availability( $t1 ) );
check( 'offer mail carries the same personal unsubscribe link', false !== strpos( $offer_mail, 'wpcap_waitlist_accept=' ) && false !== strpos( $offer_mail, $u2_join_token ) );
list( $code3, $html3 ) = Waitlists::unsubscribe_page( $u2->waitlist_id, $u2_join_token );
check( 'page warns that the reserved tickets are released', 200 === $code3 && false !== strpos( $html3, 'reserviert' ) );
$res2 = Waitlists::unsubscribe( $u2->waitlist_id, $u2_join_token );
check( 'unsubscribing an open offer cancels the placeholder order', ! is_wp_error( $res2 ) && true === $res2['released_offer'] && 'cancelled' === Orders::get( $u2->order_id )->status && 'removed' === a158_entry_by_id( $u2->waitlist_id )->status );
check( 'the offer link is dead afterwards', is_wp_error( Waitlists::accept_offer( $u2->waitlist_id, a158_offer_token( 'a158-u2@example.com' ) ) ) );
Waitlists::run_due( true );
check( 'the place goes to the next entry', 'offered' === a158_entry( $t1, 'a158-u3@example.com' )->status && '' === a158_consistent( $t1, 1 ) );

echo "A158: accepted offers cannot be unsubscribed\n";
$u3 = a158_entry( $t1, 'a158-u3@example.com' );
$acc = Waitlists::accept_offer( $u3->waitlist_id, a158_offer_token( 'a158-u3@example.com' ) );
$res3 = Waitlists::unsubscribe( $u3->waitlist_id, a158_unsub_token( 'a158-u3@example.com' ) );
check( 'accepted (payment open): unsubscribe answers 409 and changes nothing', ! is_wp_error( $acc ) && is_wp_error( $res3 ) && 'wpcap_waitlist_unsubscribe_accepted' === $res3->get_error_code() && 'accepted' === a158_entry_by_id( $u3->waitlist_id )->status && 'pending' === Orders::get( $u3->order_id )->payment_status );
Orders::confirm_manual_payment( $u3->order_id );
$res3b = Waitlists::unsubscribe( $u3->waitlist_id, a158_unsub_token( 'a158-u3@example.com' ) );
check( 'paid entry: link answers "not on the list" without changes', ! is_wp_error( $res3b ) && true === $res3b['already'] && 'paid' === a158_entry_by_id( $u3->waitlist_id )->status && '' === a158_consistent( $t1, 1 ) );

echo "A158: rate limit\n";
$GLOBALS['wpcap_test_transients'] = array();
$rl_entry = a158_entry( $t1, 'a158-custom@example.com' );
$limited = null;
for ( $i = 0; $i < 11; $i++ ) { $limited = Waitlists::unsubscribe( $rl_entry->waitlist_id, str_repeat( 'cd', 32 ) ); }
check( 'more than 10 attempts per entry and hour are refused (429)', is_wp_error( $limited ) && 'wpcap_waitlist_rate' === $limited->get_error_code() && 429 === Waitlists::unsubscribe_page( $rl_entry->waitlist_id, a158_unsub_token( 'a158-custom@example.com' ) )[0] );
check( 'rate-limited: even the right token changes nothing', is_wp_error( Waitlists::unsubscribe( $rl_entry->waitlist_id, a158_unsub_token( 'a158-custom@example.com' ) ) ) && 'waiting' === a158_entry_by_id( $rl_entry->waitlist_id )->status );
$GLOBALS['wpcap_test_transients'] = array();

echo "A158: order portal\n";
list( $t2, $b2 ) = a158_sold_out( 1, 0, array( 'a158-portal@example.com', 'a158-other@example.com' ) );
$t2b = make_type( 5, 4, 0 );
$portal_order = Orders::create( array( 'email' => 'a158-portal@example.com', 'items' => array( array( 'ticket_type_id' => $t2b, 'quantity' => 1 ) ), 'idempotency_key' => 'a158-portal-' . uniqid() ) );
$portal_order = Orders::get( $portal_order['order_id'] );
$pe = a158_entry( $t2, 'a158-portal@example.com' );
$other = a158_entry( $t2, 'a158-other@example.com' );
$card = Waitlists::portal_html( $portal_order );
check( 'portal lists open entries with the order e-mail and an unsubscribe form', false !== strpos( $card, 'wpcap-waitlist' ) && false !== strpos( $card, 'value="' . $pe->waitlist_id . '"' ) && false === strpos( $card, 'value="' . $other->waitlist_id . '"' ) && false !== strpos( $card, '_wpcap_waitlist_nonce' ) );
check( 'portal: no card for orders without entries', '' === Waitlists::portal_html( (object) array( 'public_id' => 'x', 'customer_email' => 'nobody-a158@example.com' ) ) );
$bad_nonce = Waitlists::handle_portal_request( $portal_order, array( 'wpcap_waitlist_op' => 'unsubscribe', 'waitlist_id' => $pe->waitlist_id, '_wpcap_waitlist_nonce' => 'x' ) );
$foreign = Waitlists::handle_portal_request( $portal_order, array( 'wpcap_waitlist_op' => 'unsubscribe', 'waitlist_id' => $other->waitlist_id, '_wpcap_waitlist_nonce' => 'n' ) );
check( 'portal: wrong nonce or an entry of another address is refused', is_wp_error( $bad_nonce ) && is_wp_error( $foreign ) && 'waiting' === a158_entry_by_id( $other->waitlist_id )->status && 'waiting' === a158_entry_by_id( $pe->waitlist_id )->status );
$portal_res = Waitlists::handle_portal_request( $portal_order, array( 'wpcap_waitlist_op' => 'unsubscribe', 'waitlist_id' => $pe->waitlist_id, '_wpcap_waitlist_nonce' => 'n' ) );
check( 'portal: own entry is removed (audited with source portal)', ! is_wp_error( $portal_res ) && 'removed' === a158_entry_by_id( $pe->waitlist_id )->status && 1 === a158_audits( 'waitlist_unsubscribed', $pe->waitlist_id ) && false !== strpos( Waitlists::portal_html( $portal_order, 'removed' ), 'ausgetragen' ) );

echo "A158: races – double accept, accept vs. decline\n";
list( $t3, $b3 ) = a158_sold_out( 1, 0, array( 'a158-r1@example.com', 'a158-r2@example.com' ) );
a158_free_up( $b3 );
$r1 = a158_entry( $t3, 'a158-r1@example.com' );
$r1_token = a158_offer_token( 'a158-r1@example.com' );
$first = Waitlists::accept_offer( $r1->waitlist_id, $r1_token );
$second = Waitlists::accept_offer( $r1->waitlist_id, $r1_token );
check( 'second accept after the first commit fails; one order, one ticket', ! is_wp_error( $first ) && 'paid' === $first['status'] && is_wp_error( $second ) && 1 === count( tickets( $r1->order_id, 'valid' ) ) && '' === a158_consistent( $t3, 1 ) );
check( 'decline after accept fails and changes nothing', is_wp_error( Waitlists::decline_offer( $r1->waitlist_id, $r1_token ) ) && 'paid' === a158_entry_by_id( $r1->waitlist_id )->status && 'complete' === Orders::get( $r1->order_id )->status );
check( 'unsubscribe after a free accept: "not on the list", nothing released', true === ( Waitlists::unsubscribe( $r1->waitlist_id, a158_unsub_token( 'a158-r1@example.com' ) )['already'] ?? false ) && 'paid' === a158_entry_by_id( $r1->waitlist_id )->status );

list( $t4, $b4 ) = a158_sold_out( 1, 2500, array( 'a158-d1@example.com', 'a158-d2@example.com' ) );
a158_free_up( $b4 );
$d1 = a158_entry( $t4, 'a158-d1@example.com' );
$d1_token = a158_offer_token( 'a158-d1@example.com' );
$dec = Waitlists::decline_offer( $d1->waitlist_id, $d1_token );
$late = Waitlists::accept_offer( $d1->waitlist_id, $d1_token );
check( 'decline then accept: accept fails, order stays cancelled, no payment method assigned', ! is_wp_error( $dec ) && is_wp_error( $late ) && 'declined' === a158_entry_by_id( $d1->waitlist_id )->status && 'cancelled' === Orders::get( $d1->order_id )->status && Waitlists::OFFER_PROVIDER === Orders::get( $d1->order_id )->payment_provider );
Waitlists::run_due( true );
check( 'after the decline the next entry holds the place, state consistent', 'offered' === a158_entry( $t4, 'a158-d2@example.com' )->status && '' === a158_consistent( $t4, 1 ) );

echo "A158: races – accept vs. expiry\n";
$d2 = a158_entry( $t4, 'a158-d2@example.com' );
$d2_token = a158_offer_token( 'a158-d2@example.com' );
a158_lapse_hold( $d2, false );
$lapsed = Waitlists::accept_offer( $d2->waitlist_id, $d2_token );
check( 'hold lapsed but the expiry job did not run yet: accept is refused, nothing assigned', is_wp_error( $lapsed ) && 'offered' === a158_entry_by_id( $d2->waitlist_id )->status && Waitlists::OFFER_PROVIDER === Orders::get( $d2->order_id )->payment_provider );
a158_join( $t4, 'a158-d3@example.com' );
Waitlists::run_due( true );
check( 'expiry job: entry expired, place offered to the next entry', 'expired' === a158_entry_by_id( $d2->waitlist_id )->status && 'expired' === Orders::get( $d2->order_id )->status && 'offered' === a158_entry( $t4, 'a158-d3@example.com' )->status );
check( 'accept after the expiry job ran fails; the next offer is untouched', is_wp_error( Waitlists::accept_offer( $d2->waitlist_id, $d2_token ) ) && 'expired' === Orders::get( $d2->order_id )->status && 'offered' === a158_entry( $t4, 'a158-d3@example.com' )->status && '' === a158_consistent( $t4, 1 ) );

$d3 = a158_entry( $t4, 'a158-d3@example.com' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() + 5 ), (int) $d3->order_id ) );
$edge = Waitlists::accept_offer( $d3->waitlist_id, a158_offer_token( 'a158-d3@example.com' ) );
$held_until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active'", (int) $d3->order_id ) );
check( 'accept seconds before the hold lapses extends the hold under the lock (payment window)', ! is_wp_error( $edge ) && 'accepted' === $edge['status'] && $held_until >= gmdate( 'Y-m-d H:i:s', time() + 25 * MINUTE_IN_SECONDS ) );
$expired_now = Orders::expire_holds();
Waitlists::run_due( true );
check( 'the expiry job afterwards leaves the accepted offer alone', 'accepted' === a158_entry_by_id( $d3->waitlist_id )->status && 'pending' === Orders::get( $d3->order_id )->payment_status && '' === a158_consistent( $t4, 1 ) );
check( 'payment completes it: one ticket, entry paid', true === Orders::confirm_manual_payment( $d3->order_id ) && 'paid' === a158_entry_by_id( $d3->waitlist_id )->status && '' === a158_consistent( $t4, 1 ) );

list( $t5, $b5 ) = a158_sold_out( 1, 0, array( 'a158-f1@example.com' ) );
a158_free_up( $b5 );
$f1 = a158_entry( $t5, 'a158-f1@example.com' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() + 3 ), (int) $f1->order_id ) );
$free_edge = Waitlists::accept_offer( $f1->waitlist_id, a158_offer_token( 'a158-f1@example.com' ) );
Orders::expire_holds();
check( 'free offer accepted just before the hold lapses: completed, entry paid, consistent', ! is_wp_error( $free_edge ) && 'paid' === $free_edge['status'] && 'paid' === a158_entry_by_id( $f1->waitlist_id )->status && 1 === count( tickets( $f1->order_id, 'valid' ) ) && '' === a158_consistent( $t5, 1 ) );

echo "A158: races – accept vs. unsubscribe, staff, removal during the offer\n";
list( $t6, $b6 ) = a158_sold_out( 1, 2500, array( 'a158-x1@example.com', 'a158-x2@example.com' ) );
a158_free_up( $b6 );
$x1 = a158_entry( $t6, 'a158-x1@example.com' );
$x1_offer = a158_offer_token( 'a158-x1@example.com' );
Waitlists::unsubscribe( $x1->waitlist_id, a158_unsub_token( 'a158-x1@example.com' ) );
check( 'unsubscribe then accept: accept fails, hold released', is_wp_error( Waitlists::accept_offer( $x1->waitlist_id, $x1_offer ) ) && 'cancelled' === Orders::get( $x1->order_id )->status && Waitlists::OFFER_PROVIDER === Orders::get( $x1->order_id )->payment_provider );
Waitlists::run_due( true );
$x2 = a158_entry( $t6, 'a158-x2@example.com' );
$x2_offer = a158_offer_token( 'a158-x2@example.com' );
Waitlists::close( $x2->waitlist_id, 'skipped' );
check( 'staff skip then accept: accept fails, consistent', is_wp_error( Waitlists::accept_offer( $x2->waitlist_id, $x2_offer ) ) && 'skipped' === a158_entry_by_id( $x2->waitlist_id )->status && '' === a158_consistent( $t6, 1 ) );

// Entry removed between the offer claim and linking the placeholder order (simulated inside the
// order transaction): the new hold must not survive.
list( $t7, $b7 ) = a158_sold_out( 1, 2500, array( 'a158-y1@example.com' ) );
$y1 = a158_entry( $t7, 'a158-y1@example.com' );
$a158_remove_cb = static function () use ( $y1 ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='removed',email='',offer_token_hash='' WHERE waitlist_id=%d", (int) $y1->waitlist_id ) );
};
Orders::cancel_order( $b7, 'test' );
add_action( 'wpcap_order_creating', $a158_remove_cb, 10, 0 );
$made = Waitlists::run_due( true );
remove_action( 'wpcap_order_creating', $a158_remove_cb, 10 );
$y1_orders = $wpdb->get_col( $wpdb->prepare( "SELECT o.status FROM {$p}wpcap_orders o JOIN {$p}wpcap_order_items i ON i.order_id=o.order_id WHERE i.ticket_type_id=%d AND o.payment_provider=%s", $t7, Waitlists::OFFER_PROVIDER ) );
check( 'entry removed while its offer is created: placeholder order cancelled, place free again', 0 === $made && 'removed' === a158_entry_by_id( $y1->waitlist_id )->status && array( 'cancelled' ) === array_values( array_unique( $y1_orders ) ) && 1 === Orders::availability( $t7 ) );

echo "A158: retention and erasure revoke the link\n";
list( $t8, $b8 ) = a158_sold_out( 1, 2500, array( 'a158-z1@example.com' ) );
$z1 = a158_entry( $t8, 'a158-z1@example.com' );
$z1_token = a158_unsub_token( 'a158-z1@example.com' );
PassCaptain\Privacy::erase( 'a158-z1@example.com' );
check( 'privacy erasure clears the unsubscribe token hash (link answers invalid)', '' === a158_entry_by_id( $z1->waitlist_id )->unsubscribe_token_hash && is_wp_error( Waitlists::unsubscribe( $z1->waitlist_id, $z1_token ) ) );

// Leave the shared test runtime as it was.
foreach ( array( 'wpcap_capacity_released', 'wpcap_capacity_increased', 'wpcap_order_paid', Waitlists::HOOK, 'wpcap_process_jobs', 'wpcap_cleanup_reservations' ) as $a158_hook ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $a158_hook ] ?? array() as $a158_prio => $a158_callbacks ) {
		foreach ( $a158_callbacks as $a158_i => $a158_cb ) {
			if ( is_array( $a158_cb[0] ) && Waitlists::class === $a158_cb[0][0] ) { unset( $GLOBALS['wpcap_test_hooks'][ $a158_hook ][ $a158_prio ][ $a158_i ] ); }
		}
	}
}
// The retention step stays registered once (p3_waitlist left one); drop the copy added here.
foreach ( array_reverse( $GLOBALS['wpcap_test_hooks']['wpcap_privacy_retention_steps'][10] ?? array(), true ) as $a158_i => $a158_cb ) {
	if ( is_array( $a158_cb[0] ) && Waitlists::class === $a158_cb[0][0] ) { unset( $GLOBALS['wpcap_test_hooks']['wpcap_privacy_retention_steps'][10][ $a158_i ] ); break; }
}
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] );
update_option( Waitlists::QUEUE_OPTION, array() );
Settings::update( $a158_saved );
$GLOBALS['wpcap_test_transients'] = array();
