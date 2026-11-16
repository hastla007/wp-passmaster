<?php
/**
 * State machines (docs/STATE-MACHINES.md) against duplicate, late and out-of-order events (W6).
 *
 * 1. Named scenarios: refund webhook before the paid webhook, paid after cancel, failed after paid,
 *    duplicate/foreign paid events, double check-in, refund after check-in, undo after refund,
 *    late payment after expiry, needs_review, refund request transitions.
 * 2. Property test: seeded random sequences of events on fresh orders. After every event the
 *    observed order/payment, ticket and reservation transitions must be in the documented allowed
 *    set and the cross-entity invariants must hold.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Checkin;
use PassCaptain\Refunds;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Admin\TicketsPage;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

/** Allowed order transitions "status|payment_status" (docs/STATE-MACHINES.md, section Order). */
const W6_ORDER_STATES = array(
	'P' => 'pending_payment|pending',
	'C' => 'complete|paid',
	'E' => 'expired|expired',
	'X' => 'cancelled|cancelled',
	'R' => 'refunded|refunded',
	'N' => 'needs_review|paid_unallocated',
);
const W6_ORDER_ALLOWED = array(
	'P' => array( 'C', 'E', 'X', 'R', 'N' ),
	'E' => array( 'C', 'N', 'X', 'R' ),
	'N' => array( 'C', 'X', 'R' ),
	'C' => array( 'X', 'R' ),
	'X' => array( 'R' ),
	'R' => array(),
);
const W6_TICKET_ALLOWED = array(
	'valid' => array( 'checked_in', 'revoked' ),
	'checked_in' => array( 'valid' ), // Only by an explicit undo of the check-in (guarded).
	'revoked' => array(),
);
const W6_RESERVATION_ALLOWED = array(
	'active' => array( 'converted', 'released', 'expired' ),
	'expired' => array( 'converted' ),
	'released' => array( 'converted' ),
	'converted' => array(),
);

function w6sm_order_state( $order ) {
	$key = array_search( $order->status . '|' . $order->payment_status, W6_ORDER_STATES, true );
	return false === $key ? '?' . $order->status . '|' . $order->payment_status : $key;
}
/** Snapshot of everything the state machines cover for one order. */
function w6sm_snapshot( $order_id ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$order = Orders::get( $order_id );
	$tickets = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,status,refunded FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $order_id ) ) as $t ) {
		$admissions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=" . (int) $t->ticket_id . " AND result='checked_in'" ) - (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=" . (int) $t->ticket_id . " AND result='undone'" );
		$tickets[ (int) $t->ticket_id ] = array( 'status' => $t->status, 'refunded' => (int) $t->refunded, 'admissions' => $admissions );
	}
	$reservations = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT reservation_id,status FROM {$p}wpcap_reservations WHERE order_id=%d", $order_id ) ) as $r ) { $reservations[ (int) $r->reservation_id ] = $r->status; }
	$completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$p}wpcap_refunds WHERE order_id=%d AND status='completed'", $order_id ) );
	return array( 'order' => w6sm_order_state( $order ), 'total' => (int) $order->total_minor, 'refunded_minor' => (int) $order->refunded_minor, 'completed_refunds' => $completed, 'tickets' => $tickets, 'reservations' => $reservations );
}
/** Violations between two snapshots (transition + invariants). */
function w6sm_violations( array $a, array $b, $ever_paid, $qty ) {
	$v = array();
	if ( '?' === $b['order'][0] ) { $v[] = 'unknown order state ' . $b['order']; }
	elseif ( $a['order'] !== $b['order'] && ! in_array( $b['order'], W6_ORDER_ALLOWED[ $a['order'] ] ?? array(), true ) ) { $v[] = "order {$a['order']} -> {$b['order']}"; }
	foreach ( $b['tickets'] as $id => $t ) {
		$before = $a['tickets'][ $id ]['status'] ?? null;
		if ( null !== $before && $before !== $t['status'] && ! in_array( $t['status'], W6_TICKET_ALLOWED[ $before ], true ) ) { $v[] = "ticket $id $before -> {$t['status']}"; }
		if ( null === $before && 'valid' !== $t['status'] ) { $v[] = "ticket $id issued as {$t['status']}"; }
		if ( ( $a['tickets'][ $id ]['refunded'] ?? 0 ) > $t['refunded'] ) { $v[] = "ticket $id refunded flag reset"; }
		if ( $t['refunded'] && 'valid' === $t['status'] ) { $v[] = "refunded ticket $id is valid"; }
		if ( $t['admissions'] !== ( 'checked_in' === $t['status'] ? 1 : 0 ) ) { $v[] = "ticket $id: {$t['admissions']} effective admissions while {$t['status']}"; }
	}
	foreach ( $a['tickets'] as $id => $t ) { if ( ! isset( $b['tickets'][ $id ] ) ) { $v[] = "ticket $id deleted"; } }
	foreach ( $b['reservations'] as $id => $status ) {
		$before = $a['reservations'][ $id ] ?? null;
		if ( null !== $before && $before !== $status && ! in_array( $status, W6_RESERVATION_ALLOWED[ $before ], true ) ) { $v[] = "reservation $id $before -> $status"; }
	}
	$n = count( $b['tickets'] );
	if ( $n && ! $ever_paid ) { $v[] = 'tickets without any payment'; }
	if ( ! in_array( $n, array( 0, $qty ), true ) ) { $v[] = "partial issuance ($n)"; }
	if ( in_array( $b['order'], array( 'X', 'R' ), true ) && array_filter( $b['tickets'], static fn( $t ) => 'valid' === $t['status'] ) ) { $v[] = "valid tickets on a {$b['order']} order"; }
	if ( 'C' === $b['order'] && $n !== $qty ) { $v[] = 'paid order without its tickets'; }
	if ( 'P' !== $b['order'] && in_array( 'active', $b['reservations'], true ) ) { $v[] = "active hold on a {$b['order']} order"; }
	if ( $b['refunded_minor'] > $b['total'] ) { $v[] = 'refunded more than the total'; }
	if ( $b['refunded_minor'] !== $b['completed_refunds'] && 'R' !== $b['order'] ) { $v[] = "refunded_minor {$b['refunded_minor']} != completed refunds {$b['completed_refunds']}"; }
	return $v;
}

/** Fresh pending native order of $qty tickets (manual provider, price 1000). */
function w6sm_order( $type, $qty = 2 ) {
	wpcap_test_reset_transients();
	return Orders::create( array( 'email' => 'w6sm' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'w6sm-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
}
function w6sm_event( $o, $type, $id, array $extra = array() ) {
	return Webhooks::process( 'manual', array_merge( array( 'event_id' => $id, 'type' => $type, 'order_public_id' => $o['public_id'], 'amount_minor' => (int) $o['total_minor'], 'currency' => 'EUR' ), $extra ) );
}
function w6sm_status( $order_id ) { $o = Orders::get( $order_id ); return $o->status . '|' . $o->payment_status; }
function w6sm_admin_mails( $since ) { return count( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $since ), static fn( $m ) => 'admin@example.com' === $m['to'] ) ); }

$sm_type = make_type( 0, 10, 1000 );

/* ------------------------------------------------------------------------------------------ */
echo "W6 state machines: out-of-order and duplicate payment events\n";
$o = w6sm_order( $sm_type );
$r1 = w6sm_event( $o, 'refunded', 'w6sm-ref-first-' . uniqid() );
check( 'refund webhook before the paid webhook: order cancelled (never refunded), holds released, no tickets', 'cancelled_unpaid' === $r1 && 'cancelled|cancelled' === w6sm_status( $o['order_id'] ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=" . (int) $o['order_id'] . " AND status='active'" ) && 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=" . (int) $o['order_id'] . " AND status='released'" ) && ! Orders::tickets_for_order( $o['order_id'] ) );
check( 'refund webhook of an unpaid order: no refund record, refunded amount unchanged, audited', array() === PassCaptain\Refunds::for_order( $o['order_id'] ) && 0 === (int) Orders::get( $o['order_id'] )->refunded_minor && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='provider_refund_unpaid_cancelled' AND object_id=" . (int) $o['order_id'] ) );
$mails = count( $GLOBALS['wpcap_test_mails'] );
$r2 = w6sm_event( $o, 'paid', 'w6sm-paid-late-' . uniqid() );
check( 'late paid webhook for the cancelled order issues nothing and alerts the admin', 'paid_for_closed_order' === $r2 && 'cancelled|cancelled' === w6sm_status( $o['order_id'] ) && ! Orders::tickets_for_order( $o['order_id'] ) && 1 === w6sm_admin_mails( $mails ) );
$o = w6sm_order( $sm_type );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE order_id=" . (int) $o['order_id'] );
Orders::expire_holds();
$r3 = w6sm_event( $o, 'refunded', 'w6sm-ref-expired-' . uniqid() );
check( 'refund webhook of an expired (never paid) order cancels it, never refunded', 'cancelled_unpaid' === $r3 && 'cancelled|cancelled' === w6sm_status( $o['order_id'] ) );
$o = w6sm_order( $sm_type );
w6sm_event( $o, 'paid', 'w6sm-paid-ref-' . uniqid() );
$r4 = w6sm_event( $o, 'refunded', 'w6sm-ref-paid-' . uniqid() );
check( 'refund webhook of a paid order still marks it refunded', 'refunded' === $r4 && 'refunded|refunded' === w6sm_status( $o['order_id'] ) );

$o = w6sm_order( $sm_type );
w6sm_event( $o, 'refunded', 'w6sm-part-' . uniqid(), array( 'amount_minor' => 500 ) );
check( 'partial refund event before payment is only logged', 'pending_payment|pending' === w6sm_status( $o['order_id'] ) );

$o = w6sm_order( $sm_type );
$f = w6sm_event( $o, 'failed', 'w6sm-fail-' . uniqid() );
$pd = w6sm_event( $o, 'paid', 'w6sm-paid-' . uniqid() );
check( 'paid after failed/cancelled: no tickets, order stays cancelled', 'cancelled' === $f && 'paid_for_closed_order' === $pd && 'cancelled|cancelled' === w6sm_status( $o['order_id'] ) && ! Orders::tickets_for_order( $o['order_id'] ) );

$o = w6sm_order( $sm_type );
$paid_id = 'w6sm-paid-' . uniqid();
w6sm_event( $o, 'paid', $paid_id, array( 'provider_reference' => 'pay_A' ) );
$late_fail = w6sm_event( $o, 'failed', 'w6sm-fail-late-' . uniqid(), array( 'provider_reference' => 'pay_A' ) );
check( 'failed event after payment is ignored', 'ignored' === $late_fail && 'complete|paid' === w6sm_status( $o['order_id'] ) && 2 === count( tickets( $o['order_id'], 'valid' ) ) );
check( 'redelivered paid event (same id) is a duplicate', 'duplicate' === w6sm_event( $o, 'paid', $paid_id, array( 'provider_reference' => 'pay_A' ) ) && 2 === count( Orders::tickets_for_order( $o['order_id'] ) ) );
check( 'second paid event of the same attempt (new id) issues nothing new', 'already_paid' === w6sm_event( $o, 'paid', 'w6sm-paid2-' . uniqid(), array( 'provider_reference' => 'pay_A' ) ) && 2 === count( Orders::tickets_for_order( $o['order_id'] ) ) );
$mails = count( $GLOBALS['wpcap_test_mails'] );
check( 'paid event of another attempt is flagged as double payment', 'double_payment' === w6sm_event( $o, 'paid', 'w6sm-paid3-' . uniqid(), array( 'provider_reference' => 'pay_B' ) ) && 1 === w6sm_admin_mails( $mails ) && 2 === count( Orders::tickets_for_order( $o['order_id'] ) ) );
check( 'stale failure of an older attempt is ignored', 'ignored' === w6sm_event( $o, 'failed', 'w6sm-fail-old-' . uniqid(), array( 'provider_reference' => 'pay_0' ) ) );
check( 'amount mismatch never issues tickets', 'amount_mismatch' === w6sm_event( w6sm_order( $sm_type ), 'paid', 'w6sm-mm-' . uniqid(), array( 'amount_minor' => 1 ) ) );

$o = w6sm_order( $sm_type );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE order_id=" . (int) $o['order_id'] );
Orders::expire_holds();
check( 'expired order: holds expired', 'expired|expired' === w6sm_status( $o['order_id'] ) );
check( 'late payment after expiry recovers the order (capacity free)', 'paid' === w6sm_event( $o, 'paid', 'w6sm-late-' . uniqid() ) && 'complete|paid' === w6sm_status( $o['order_id'] ) && 2 === count( tickets( $o['order_id'], 'valid' ) ) );
Orders::expire_holds();
check( 'expiry sweep never touches a paid order', 'complete|paid' === w6sm_status( $o['order_id'] ) );

$tiny = make_type( 1, 10, 1000 );
$o = w6sm_order( $tiny, 1 );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE order_id=" . (int) $o['order_id'] );
Orders::expire_holds();
$thief = w6sm_order( $tiny, 1 );
Orders::confirm_manual_payment( $thief['order_id'] );
$nr = w6sm_event( $o, 'paid', 'w6sm-nr-' . uniqid() );
check( 'late payment without capacity: needs_review, no tickets, final answer', 'needs_review' === $nr && 'needs_review|paid_unallocated' === w6sm_status( $o['order_id'] ) && ! Orders::tickets_for_order( $o['order_id'] ) );
check( 'needs_review: failed event is ignored', 'ignored' === w6sm_event( $o, 'failed', 'w6sm-nrf-' . uniqid() ) );
Orders::cancel_order( $thief['order_id'], 'w6' );
check( 'needs_review -> complete once capacity is free and the payment is confirmed again', true === Orders::confirm_external_payment( $o['order_id'], 'w6' ) && 'complete|paid' === w6sm_status( $o['order_id'] ) && 1 === count( tickets( $o['order_id'], 'valid' ) ) );

$o = w6sm_order( $sm_type );
Orders::confirm_manual_payment( $o['order_id'] );
Orders::mark_refunded( $o['order_id'], 'w6' );
check( 'cancel after refund is a no-op (refunded is final)', true === Orders::cancel_order( $o['order_id'], 'w6' ) && 'refunded|refunded' === w6sm_status( $o['order_id'] ) );
check( 'manual confirmation of a refunded order is refused', is_wp_error( Orders::confirm_manual_payment( $o['order_id'] ) ) );

/* ------------------------------------------------------------------------------------------ */
echo "W6 state machines: check-in and refunds\n";
$free = make_type( 0, 10, 0 );
wpcap_test_reset_transients();
$fo = Orders::create( array( 'email' => 'w6sm-ci@example.com', 'items' => array( array( 'ticket_type_id' => $free, 'quantity' => 2 ) ), 'idempotency_key' => 'w6sm-ci-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$codes = ticket_code_for( $fo['order_id'] );
$c1 = Checkin::perform( $codes[0], array( 'idempotency_key' => 'w6sm-a' ) );
$c2 = Checkin::perform( $codes[0], array( 'idempotency_key' => 'w6sm-b' ) );
$c1r = Checkin::perform( $codes[0], array( 'idempotency_key' => 'w6sm-a' ) );
$tid = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $codes[0] ) . "'" );
check( 'double check-in: second scan refused, replay of the first answers checked_in, one admission logged', 'checked_in' === $c1['status'] && is_wp_error( $c2 ) && 'already_checked_in' === $c2->get_error_data()['result'] && ! empty( $c1r['replayed'] ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=$tid AND result='checked_in'" ) );

// Refund after check-in (paid order).
$po = w6sm_order( $sm_type );
Orders::confirm_manual_payment( $po['order_id'] );
$pcodes = ticket_code_for( $po['order_id'] );
$pt = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $po['order_id'] ) );
$in = Checkin::perform( $pcodes[0], array( 'idempotency_key' => 'w6sm-p-' . uniqid() ) );
$checked_ticket = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $pcodes[0] ) . "'" );
$rf = Refunds::request( $po['order_id'], array( $checked_ticket ), 'w6', 'w6sm-rf-' . uniqid() );
$row = $wpdb->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=$checked_ticket" );
check( 'refund after check-in: refund completes, ticket stays checked_in (history) and is flagged refunded', 'checked_in' === $in['status'] && is_array( $rf ) && 'completed' === $rf['status'] && 'checked_in' === $row->status && 1 === (int) $row->refunded );
$undo = TicketsPage::undo_checkin( array( 'ticket_id' => $checked_ticket ) );
check( 'undo of a refunded check-in is refused (would make the refunded ticket valid again)', is_wp_error( $undo ) && 'checked_in' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$checked_ticket" ) );
$rf2 = Refunds::request( $po['order_id'], array( $checked_ticket ), 'w6', 'w6sm-rf2-' . uniqid() );
check( 'second refund of the same ticket is refused', is_wp_error( $rf2 ) );
$rest = Refunds::request( $po['order_id'], array(), 'w6', 'w6sm-rest-' . uniqid() );
check( 'refund of the rest refunds the order fully; revoked ticket cannot check in', is_array( $rest ) && 'refunded|refunded' === w6sm_status( $po['order_id'] ) && 1000 * 2 === (int) Orders::get( $po['order_id'] )->refunded_minor && is_wp_error( Checkin::perform( $pcodes[1], array( 'idempotency_key' => 'w6sm-r-' . uniqid() ) ) ) );

// Undo of a normal check-in is allowed and frees the ticket again.
$uo = w6sm_order( $sm_type, 1 );
Orders::confirm_manual_payment( $uo['order_id'] );
$ucode = ticket_code_for( $uo['order_id'] )[0];
Checkin::perform( $ucode, array( 'idempotency_key' => 'w6sm-u1-' . uniqid() ) );
$uid = (int) tickets( $uo['order_id'] )[0]->ticket_id;
$undo = TicketsPage::undo_checkin( array( 'ticket_id' => $uid ) );
check( 'undo of a check-in on a paid order: ticket valid again, admission can be repeated once', ! is_wp_error( $undo ) && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$uid" ) && 'checked_in' === Checkin::perform( $ucode, array( 'idempotency_key' => 'w6sm-u2-' . uniqid() ) )['status'] );

// Refund request transitions.
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$ro = w6sm_order( $sm_type );
Orders::confirm_manual_payment( $ro['order_id'] );
$pending = Refunds::request( $ro['order_id'], array(), 'w6', 'w6sm-pa-' . uniqid() );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'refund above the threshold waits for approval', 'pending_approval' === $pending['status'] );
$GLOBALS['wpcap_test_user_id'] = 2;
$rej = Refunds::reject( $pending['refund_id'], 'nein' );
$app = Refunds::approve( $pending['refund_id'] );
$rej2 = Refunds::reject( $pending['refund_id'], 'nochmal' );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'rejected refund cannot be approved or rejected again; order and tickets unchanged', 'rejected' === $rej['status'] && is_wp_error( $app ) && is_wp_error( $rej2 ) && 2 === count( tickets( $ro['order_id'], 'valid' ) ) && 'complete|paid' === w6sm_status( $ro['order_id'] ) );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

/* ------------------------------------------------------------------------------------------ */
echo "W6 state machines: seeded random event sequences\n";
$events = array( 'paid', 'paid_dup', 'failed', 'refunded', 'refund_partial_event', 'cancel', 'expire', 'manual', 'checkin', 'checkin_again', 'native_refund_one', 'undo_checkin', 'mark_refunded' );
mt_srand( 20261114 );
$sequences = getenv( 'WPCAP_TEST_SLOW' ) ? 300 : 60;
$violations = array();
$transitions = array();
for ( $s = 0; $s < $sequences; $s++ ) {
	$o = w6sm_order( $sm_type );
	$oid = (int) $o['order_id'];
	$last_paid = 'w6r-' . $s . '-0';
	$ever_paid = false;
	$before = w6sm_snapshot( $oid );
	$trace = array();
	$len = mt_rand( 3, 7 );
	for ( $i = 0; $i < $len; $i++ ) {
		$e = $events[ mt_rand( 0, count( $events ) - 1 ) ];
		$trace[] = $e;
		$codes = 'C' === $before['order'] || array_filter( $before['tickets'] ) ? ticket_code_for( $oid ) : array();
		$first = $before['tickets'] ? (int) array_key_first( $before['tickets'] ) : 0;
		switch ( $e ) {
			case 'paid': $last_paid = "w6r-$s-$i"; w6sm_event( $o, 'paid', $last_paid ); break;
			case 'paid_dup': w6sm_event( $o, 'paid', $last_paid ); break;
			case 'failed': w6sm_event( $o, 'failed', "w6r-f-$s-$i" ); break;
			case 'refunded': w6sm_event( $o, 'refunded', "w6r-r-$s-$i" ); break;
			case 'refund_partial_event': w6sm_event( $o, 'refunded', "w6r-pr-$s-$i", array( 'amount_minor' => 300 ) ); break;
			case 'cancel': Orders::cancel_order( $oid, 'w6' ); break;
			case 'expire':
				$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE order_id=$oid AND status='active'" );
				Orders::expire_holds();
				break;
			case 'manual': Orders::confirm_manual_payment( $oid ); break;
			case 'checkin':
			case 'checkin_again': if ( $codes ) { Checkin::perform( $codes[0], array( 'idempotency_key' => "w6r-c-$s-$i" ) ); } break;
			case 'native_refund_one': if ( $first ) { Refunds::request( $oid, array( $first ), 'w6', "w6r-n-$s-$i" ); } break;
			case 'undo_checkin': if ( $first ) { TicketsPage::undo_checkin( array( 'ticket_id' => $first ) ); } break;
			case 'mark_refunded': Orders::mark_refunded( $oid, 'w6' ); break;
		}
		$after = w6sm_snapshot( $oid );
		if ( 'C' === $after['order'] ) { $ever_paid = true; }
		$transitions[ $before['order'] . '>' . $after['order'] ] = true;
		foreach ( w6sm_violations( $before, $after, $ever_paid, 2 ) as $v ) { $violations[] = "seq $s [" . implode( ',', $trace ) . "]: $v"; }
		$before = $after;
	}
}
ksort( $transitions );
check( "$sequences random sequences: every transition allowed and all invariants hold (seen: " . implode( ' ', array_keys( array_filter( $transitions, static fn( $v, $k ) => $k[0] !== $k[2], ARRAY_FILTER_USE_BOTH ) ) ) . ')', ! $violations );
foreach ( array_slice( $violations, 0, 8 ) as $v ) { echo "         $v\n"; }
