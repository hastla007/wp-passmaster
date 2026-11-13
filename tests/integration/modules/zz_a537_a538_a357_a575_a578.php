<?php
/**
 * A537, A538, A357, A575, A578 (and the invariant of A579): every ticket releases its capacity unit
 * at most once, recorded on the ticket (CapacityRelease); refunds, exchanges, revokes and
 * cancellations go through that rule. Included from run.php. Also a concurrency worker:
 *   php zz_a537_a538_a357_a575_a578.php --wpp1b-child <op> <order_id> <ticket_id> <target_type> <start_ts>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Allocations\Holds;
use PassCaptain\CapacityCounters;
use PassCaptain\CapacityRelease;
use PassCaptain\Exchange\Service as Exchange;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Waitlists;

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--wpp1b-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	PassCaptain\Modules\Exchange::register();
	list( , , $op, $order_id, $ticket_id, $target, $start ) = array_pad( $argv, 7, '' );
	$GLOBALS['wpdb']->query( 'SET SESSION innodb_lock_wait_timeout=5' );
	while ( microtime( true ) < (float) $start ) { usleep( 1000 ); }
	if ( 'refund' === $op ) {
		$r = Refunds::request( (int) $order_id, array( (int) $ticket_id ), 'race', 'race-' . getmypid() );
		$ok = ! is_wp_error( $r ) && 'completed' === $r['status'];
	} elseif ( 'approve' === $op ) {
		$GLOBALS['wpcap_test_user_id'] = 2;
		$r = Refunds::approve( (int) $target );
		$ok = ! is_wp_error( $r ) && 'completed' === $r['status'];
	} elseif ( 'exchange' === $op ) {
		$r = Exchange::request( (int) $ticket_id, (int) $target, array( 'channel' => 'admin', 'idempotency_key' => 'race-' . getmypid() ) );
		$ok = ! is_wp_error( $r ) && 'completed' === $r['status'];
	} else {
		$r = TicketsPage::revoke_ticket( array( 'ticket_id' => (int) $ticket_id, 'reason' => 'race', 'release_capacity' => 1 ) );
		$ok = ! is_wp_error( $r );
	}
	echo $ok ? 'OK' : 'ERR:' . ( is_wp_error( $r ) ? ( $r->get_error_data()['reason'] ?? $r->get_error_code() ) : (string) ( $r['status'] ?? '?' ) );
	exit;
}

global $wpdb;
$p = $wpdb->prefix;
echo "A537/A538/A357/A575/A578: capacity is released at most once per ticket\n";

// Hooks registered below stay local to this file (later files register their own).
$wpp1b_hooks_before = $GLOBALS['wpcap_test_hooks'] ?? array();

/** Whether $callback is hooked to $hook (the test hooks do not deduplicate). */
function wpp1b_hooked( $hook, $callback ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() as $entries ) {
		foreach ( $entries as $entry ) { if ( $entry[0] === $callback ) { return true; } }
	}
	return false;
}
if ( ! wpp1b_hooked( 'wpcap_exchange_finalize', array( Exchange::class, 'finalize' ) ) && ! wpp1b_hooked( 'wpcap_issue_order_lines', array( Exchange::class, 'filter_issue_lines' ) ) ) { PassCaptain\Modules\Exchange::register(); }
if ( ! wpp1b_hooked( 'wpcap_capacity_released', array( Holds::class, 'on_capacity_released' ) ) ) { PassCaptain\Modules\Allocations::register(); }
if ( ! wpp1b_hooked( 'wpcap_capacity_released', array( Waitlists::class, 'on_capacity_released' ) ) ) { Waitlists::register(); }
if ( ! wpp1b_hooked( 'wpcap_occurrence_cancelled', array( PassCaptain\OccurrenceCancellation::class, 'handle' ) ) ) { PassCaptain\OccurrenceCancellation::register(); }

/** Event with one occurrence and ticket type (or another type in $occ). */
function wpp1b_type( $capacity, $price = 2500, $occ = 0, $occ_capacity = 0, $name = 'Standard' ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	if ( ! $occ ) {
		$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Freigabe', 'slug' => 'wpp1b' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $wpdb->insert_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => $occ_capacity, 'status' => 'scheduled' ) );
		$occ = $wpdb->insert_id;
	}
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
function wpp1b_occ( $type ) { global $wpdb; return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type ); }
function wpp1b_event( $type ) { global $wpdb; return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types t JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=" . (int) $type ); }
/** Box office sale, paid at once. */
function wpp1b_buy( $type, $qty, array $extra = array() ) {
	$o = Orders::create( array( 'email' => 'wpp1b-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => uniqid( 'wpp1b', true ) ), $extra + array( 'external' => true, 'source' => 'pos', 'key_namespace' => 'pos', 'payment_provider' => 'manual', 'skip_rate_limit' => true ) );
	if ( is_wp_error( $o ) ) { return $o; }
	Orders::confirm_external_payment( $o['order_id'], 'wpp1b' );
	return (int) $o['order_id'];
}
function wpp1b_tickets( $order_id ) { global $wpdb; return array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=" . (int) $order_id . ' ORDER BY ticket_id' ) ); }
function wpp1b_ticket( $ticket_id ) { global $wpdb; return $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id ); }
function wpp1b_live( $type ) { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_type_id=" . (int) $type . " AND status IN ('valid','checked_in')" ); }
function wpp1b_avail( $type ) { Orders::flush_availability_cache(); return Orders::availability( $type ); }
function wpp1b_approve( $refund_id ) { $GLOBALS['wpcap_test_user_id'] = 2; $r = Refunds::approve( $refund_id ); $GLOBALS['wpcap_test_user_id'] = 1; return $r; }
function wpp1b_revoke( $ticket_id, $release = 1 ) { return TicketsPage::revoke_ticket( array( 'ticket_id' => $ticket_id, 'reason' => 'Test', 'release_capacity' => $release ) ); }
/**
 * The invariant (A579): a counting order line holds exactly one unit per ticket that has not released
 * its unit; an order that no longer counts (cancelled, refunded) has every ticket marked released.
 * Returns '' or a description of the violation.
 */
function wpp1b_invariant( $order_id ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$order = Orders::get( $order_id );
	$units = (int) $wpdb->get_var( "SELECT COALESCE(SUM(GREATEST(CAST(quantity AS SIGNED)-refunded_quantity,0)),0) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $order_id );
	$held = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $order_id . ' AND capacity_released=0' );
	$counting = 'paid' === $order->payment_status && 'refunded' !== $order->status;
	if ( $counting && $units !== $held ) { return "order $order_id: line units $units, tickets holding a unit $held"; }
	if ( ! $counting && $held ) { return "order $order_id ({$order->status}): $held tickets not marked released"; }
	if ( (int) $order->refunded_minor > (int) $order->total_minor ) { return "order $order_id: refunded more than paid"; }
	$drift = CapacityCounters::ticket_drift();
	foreach ( $drift as $row ) { if ( (int) $row['order_id'] === (int) $order_id ) { return "order $order_id: ticket drift " . wp_json_encode( $row ); } }
	CapacityCounters::flush_dirty();
	$occs = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT tt.occurrence_id FROM {$p}wpcap_order_items oi JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=" . (int) $order_id ) );
	if ( CapacityCounters::drift( $occs ) ) { return "order $order_id: counter drift " . wp_json_encode( CapacityCounters::drift( $occs ) ); }
	return '';
}
$wpp1b_events = array();
add_action( 'wpcap_capacity_released', static function ( $order_id, $reason = '', $details = array() ) use ( &$wpp1b_events ) { $wpp1b_events[] = array( (int) $order_id, (string) $reason, (array) $details ); }, 1, 3 );
$wpp1b_events_of = static function ( $order_id ) use ( &$wpp1b_events ) { return array_values( array_filter( $wpp1b_events, static function ( $e ) use ( $order_id ) { return $e[0] === (int) $order_id; } ) ); };

check( 'schema 3.10.10: tickets carry capacity_released (CapacityRelease::ready)', CapacityRelease::ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.10', '>=' ) );

/* ------------------------------------------------------------------ A537: refund after exchange */
$a = wpp1b_type( 2 );
$b = wpp1b_type( 0, 2500, wpp1b_occ( $a ), 0, 'B' );
$o = wpp1b_buy( $a, 2 );
$t = wpp1b_tickets( $o );
$r = Refunds::request( $o, array( $t[0] ), 'Kunde storniert', 'wpp1b-a537-1', array( 'require_approval' => true ) );
check( 'A537: refund waits for approval', ! is_wp_error( $r ) && 'pending_approval' === $r['status'] );
$x = Exchange::request( $t[0], $b, array( 'channel' => 'admin', 'idempotency_key' => 'wpp1b-a537-x' ) );
check( 'A537: exchange of a ticket with an open refund is refused (refund_open)', is_wp_error( $x ) && 'refund_open' === ( $x->get_error_data()['reason'] ?? '' ) && 'valid' === wpp1b_ticket( $t[0] )->status );
$tr = Orders::transfer_ticket( Orders::get( $o )->public_id, str_repeat( 'a', 64 ), wpp1b_ticket( $t[0] )->public_id, 'friend@example.com' );
check( 'A537: transfer is checked against open refunds after the order lock (access check first)', is_wp_error( $tr ) );
// Legacy path (a refund approved by an older version while the ticket was exchanged): park the
// refund, exchange, restore it and approve – execution must fail, no money, no second release.
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'rejected' ), array( 'refund_id' => $r['refund_id'] ) );
$x = Exchange::request( $t[0], $b, array( 'channel' => 'admin', 'idempotency_key' => 'wpp1b-a537-x2' ) );
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'pending_approval' ), array( 'refund_id' => $r['refund_id'] ) );
check( 'A537: exchange runs once the refund is no longer open, the old ticket records its release', ! is_wp_error( $x ) && 'completed' === $x['status'] && 1 === (int) wpp1b_ticket( $t[0] )->capacity_released );
$ap = wpp1b_approve( $r['refund_id'] );
CapacityCounters::flush_dirty();
check( 'A537: the approved refund of the exchanged ticket fails (execution_conflict needs valid/checked_in)', is_wp_error( $ap ) && 'wpcap_refund_conflict' === $ap->get_error_code() && 'failed' === Refunds::get( $r['refund_id'] )['status'] );
check( 'A537: no money paid, new ticket valid, old ticket not flagged refunded', 0 === (int) Orders::get( $o )->refunded_minor && 0 === (int) wpp1b_ticket( $t[0] )->refunded && 'valid' === wpp1b_ticket( (int) $x['new_ticket_id'] )->status );
check( 'A537: type A is not oversold (1 valid ticket, 1 place left)', 1 === wpp1b_live( $a ) && 1 === wpp1b_avail( $a ) && '' === wpp1b_invariant( $o ) );
$n1 = wpp1b_buy( $a, 1 );
$n2 = wpp1b_buy( $a, 1 );
check( 'A537: only one more buyer gets type A (capacity 2)', ! is_wp_error( $n1 ) && is_wp_error( $n2 ) && 2 === wpp1b_live( $a ) );

/* ------------------------------------------------------------------ A538: revoke with release, then refund */
$c = wpp1b_type( 3 );
$o = wpp1b_buy( $c, 3 );
$t = wpp1b_tickets( $o );
$r = Refunds::request( $o, array( $t[0] ), 'Kunde', 'wpp1b-a538-1', array( 'require_approval' => true ) );
$rv = wpp1b_revoke( $t[0] );
check( 'A538: revoke of a ticket with an open refund is refused', is_wp_error( $rv ) && 'wpcap_ticket_refund_open' === $rv->get_error_code() && 'valid' === wpp1b_ticket( $t[0] )->status );
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'rejected' ), array( 'refund_id' => $r['refund_id'] ) );
$rv = wpp1b_revoke( $t[0] );
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'pending_approval' ), array( 'refund_id' => $r['refund_id'] ) );
check( 'A538: revoke with release marks the ticket and frees one place', ! is_wp_error( $rv ) && 1 === (int) wpp1b_ticket( $t[0] )->capacity_released && 1 === wpp1b_avail( $c ) );
$ap = wpp1b_approve( $r['refund_id'] );
CapacityCounters::flush_dirty();
$line = $wpdb->get_row( "SELECT quantity,refunded_quantity FROM {$p}wpcap_order_items WHERE order_id=$o" );
check( 'A538: the later refund of the revoked ticket fails and releases nothing a second time', is_wp_error( $ap ) && 1 === (int) $line->refunded_quantity && 1 === wpp1b_avail( $c ) && 2 === wpp1b_live( $c ) && '' === wpp1b_invariant( $o ) );
// The central rule itself: a second release of the same ticket takes no second unit.
$wpdb->query( 'START TRANSACTION' );
$again = CapacityRelease::release_tickets( $o, array( $t[0] ), array( 'refund' => true ) );
$wpdb->query( 'COMMIT' );
$line = $wpdb->get_row( "SELECT refunded_quantity FROM {$p}wpcap_order_items WHERE order_id=$o" );
check( 'A538: CapacityRelease::release_tickets() never releases a marked ticket again (only flags the refund)', array() === $again && 1 === (int) $line->refunded_quantity && 1 === (int) wpp1b_ticket( $t[0] )->refunded );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 0 ), array( 'ticket_id' => $t[0] ) );

/* ------------------------------------------------------------------ A357: full refund by ticket value */
check( 'A357: revoked ticket is no longer refundable', is_wp_error( Refunds::request( $o, array( $t[0] ), 'x', 'wpp1b-a357-0' ) ) );
$full = Refunds::request( $o, array(), 'Rest', 'wpp1b-a357-1' );
$ord = Orders::get( $o );
check( 'A357: refund of all remaining tickets pays their value only (2 × 25,00, not the revoked ticket)', ! is_wp_error( $full ) && 'completed' === $full['status'] && 5000 === (int) $full['amount_minor'] && 5000 === (int) $ord->refunded_minor );
check( 'A357: the order becomes refunded (the revoked ticket does not keep it open)', 'refunded' === $ord->status && 'refunded' === $ord->payment_status && 3 === wpp1b_avail( $c ) && '' === wpp1b_invariant( $o ) );
// Partial refund after a revoke: the value of the requested ticket, the rest stays.
$o = wpp1b_buy( $c, 3 );
$t = wpp1b_tickets( $o );
wpp1b_revoke( $t[2], 0 );
$part = Refunds::request( $o, array( $t[0] ), 'eins', 'wpp1b-a357-2' );
$rest = Refunds::request( $o, array( $t[1] ), 'zwei', 'wpp1b-a357-3' );
$ord = Orders::get( $o );
check( 'A357: ticket by ticket after a revoke (without release): 25,00 each, order refunded, 25,00 kept', 2500 === (int) $part['amount_minor'] && 2500 === (int) $rest['amount_minor'] && 'refunded' === $ord->status && 5000 === (int) $ord->refunded_minor && '' === wpp1b_invariant( $o ) );
// After an exchange: refunding everything that is left completes the order.
$e = wpp1b_type( 5 );
$e2 = wpp1b_type( 5, 2500, wpp1b_occ( $e ), 0, 'E2' );
$o = wpp1b_buy( $e, 2 );
$t = wpp1b_tickets( $o );
$x = Exchange::request( $t[0], $e2, array( 'channel' => 'admin', 'idempotency_key' => 'wpp1b-a357-x' ) );
$full = Refunds::request( $o, array(), 'alles', 'wpp1b-a357-4' );
$ord = Orders::get( $o );
check( 'A357: after an exchange, refunding all remaining tickets refunds the order total and marks it refunded', ! is_wp_error( $x ) && ! is_wp_error( $full ) && 5000 === (int) $full['amount_minor'] && 'refunded' === $ord->status && 5 === wpp1b_avail( $e ) && 5 === wpp1b_avail( $e2 ) && '' === wpp1b_invariant( $o ) );

/* ------------------------------------------------------------------ A575: exchange returns one allocation unit */
$h = wpp1b_type( 0, 2500, 0, 20 );
$h2 = wpp1b_type( 0, 2500, wpp1b_occ( $h ), 0, 'Andere' );
$hold = Holds::create( array( 'occurrence_id' => wpp1b_occ( $h ), 'name' => 'Künstler', 'quantity' => 5 ) );
$o = wpp1b_buy( $h, 3, array( 'allocation_id' => (int) $hold->hold_id ) );
$t = wpp1b_tickets( $o );
$before = Holds::get( $hold->hold_id );
$x = Exchange::request( $t[0], $h2, array( 'channel' => 'admin', 'idempotency_key' => 'wpp1b-a575' ) );
$after = Holds::get( $hold->hold_id );
check( 'A575: exchanging one of three hold tickets returns exactly one unit to the hold (used 3 → 2)', ! is_wp_error( $x ) && 3 === (int) $before->used && 2 === (int) $after->used );
$sold = 0;
for ( $i = 0; $i < 5; $i++ ) { if ( ! is_wp_error( wpp1b_buy( $h, 1, array( 'allocation_id' => (int) $hold->hold_id ) ) ) ) { ++$sold; } }
check( 'A575: the hold then sells only its remaining 3 units', 3 === $sold && 5 === (int) Holds::get( $hold->hold_id )->used );
$ev = $wpp1b_events_of( $o );
check( 'A575: the exchange is announced as a partial release (type, quantity 1)', 1 === count( $ev ) && 'exchange' === $ev[0][1] && $h === (int) $ev[0][2]['ticket_type_id'] && 1 === (int) $ev[0][2]['quantity'] );

/* ------------------------------------------------------------------ A578: partial refund and revoke notify */
$w = wpp1b_type( 2 );
$o = wpp1b_buy( $w, 2 );
update_option( Waitlists::QUEUE_OPTION, array() );
$j = Waitlists::join( wpp1b_event( $w ), wpp1b_occ( $w ), $w, 'wpp1b-wait@example.com', 1 );
update_option( Waitlists::QUEUE_OPTION, array() );
$t = wpp1b_tickets( $o );
$r = Refunds::request( $o, array( $t[0] ), 'eins', 'wpp1b-a578-1' );
$ev = $wpp1b_events_of( $o );
check( 'A578: a partial refund fires wpcap_capacity_released (partial_refund, type, quantity 1)', ! is_wp_error( $j ) && ! is_wp_error( $r ) && 1 === count( $ev ) && 'partial_refund' === $ev[0][1] && $w === (int) $ev[0][2]['ticket_type_id'] && 1 === (int) $ev[0][2]['quantity'] && (int) $r['refund_id'] === (int) $ev[0][2]['refund_id'] );
check( 'A578: … and the waitlist of the type is queued for the free place', isset( Waitlists::queued()[ $w ] ) && 1 === wpp1b_avail( $w ) );
update_option( Waitlists::QUEUE_OPTION, array() );
wpp1b_revoke( $t[1] );
$ev = $wpp1b_events_of( $o );
check( 'A578: an admin revoke with release fires tickets_revoked with type and quantity, the waitlist is queued', 2 === count( $ev ) && 'tickets_revoked' === $ev[1][1] && $w === (int) $ev[1][2]['ticket_type_id'] && 1 === (int) $ev[1][2]['quantity'] && isset( Waitlists::queued()[ $w ] ) );
$wpp1b_n = count( $wpp1b_events );
$o2 = wpp1b_buy( $w, 1 );
wpp1b_revoke( wpp1b_tickets( $o2 )[0], 0 );
check( 'A578: a revoke without release announces nothing', count( $wpp1b_events ) === $wpp1b_n );
// Allocation hold: a partial refund returns exactly the refunded unit.
$hold = Holds::create( array( 'occurrence_id' => wpp1b_occ( $h ), 'name' => 'Presse', 'quantity' => 4 ) );
$o = wpp1b_buy( $h, 3, array( 'allocation_id' => (int) $hold->hold_id ) );
$t = wpp1b_tickets( $o );
Refunds::request( $o, array( $t[1] ), 'eins', 'wpp1b-a578-2' );
check( 'A578: a partial refund returns one unit to the allocation hold (used 3 → 2)', 2 === (int) Holds::get( $hold->hold_id )->used );
wpp1b_revoke( $t[2] );
check( 'A578: an admin revoke with release returns one more unit (used 2 → 1)', 1 === (int) Holds::get( $hold->hold_id )->used && '' === wpp1b_invariant( $o ) );

/* ------------------------------------------------------------------ Cancellation exception and marker */
$k = wpp1b_type( 4 );
$o = wpp1b_buy( $k, 2 );
$t = wpp1b_tickets( $o );
$r = Refunds::request( $o, array( $t[0] ), 'abgesagt', 'wpp1b-cancel-1', array( 'require_approval' => true ) );
$cx = EventsPage::cancel_occurrence( array( 'occurrence_id' => wpp1b_occ( $k ), 'reason' => 'Unwetter', 'confirm' => 1 ) );
check( 'cancellation revokes the tickets but keeps their units (still refundable)', ! is_wp_error( $cx ) && 'revoked' === wpp1b_ticket( $t[0] )->status && 0 === (int) wpp1b_ticket( $t[0] )->capacity_released );
$ap = wpp1b_approve( $r['refund_id'] );
check( 'execution_conflict allows a ticket revoked by the cancellation of its date (refund completes, unit released once)', ! is_wp_error( $ap ) && 'completed' === $ap['status'] && 1 === (int) wpp1b_ticket( $t[0] )->capacity_released && 2500 === (int) $ap['amount_minor'] && '' === wpp1b_invariant( $o ) );
$r2 = Refunds::request( $o, array(), 'Rest', 'wpp1b-cancel-2' );
check( 'the last ticket of the cancelled date is refunded too; the order is refunded and every ticket is marked', ! is_wp_error( $r2 ) && 2500 === (int) $r2['amount_minor'] && 'refunded' === Orders::get( $o )->status && '' === wpp1b_invariant( $o ) );
$o = wpp1b_buy( wpp1b_type( 3 ), 2 );
Orders::cancel_order( $o, 'test' );
check( 'cancel_order records the release on every ticket', '' === wpp1b_invariant( $o ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$o AND capacity_released=1" ) );
$o = wpp1b_buy( wpp1b_type( 3 ), 2 );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET capacity_released=0, refunded=1 WHERE order_id=$o" );
PassCaptain\Installer::backfill_capacity_released( '3.10.7' );
check( 'upgrade backfill marks tickets refunded by earlier versions (only from < 3.10.10)', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$o AND capacity_released=1" ) && false === PassCaptain\Installer::backfill_capacity_released( '3.10.10' ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET capacity_released=0, refunded=0 WHERE order_id=$o" );

/* ------------------------------------------------------------------ Doctor / drift against tickets */
$d = wpp1b_type( 3 );
$o = wpp1b_buy( $d, 2 );
check( 'ticket_drift: a consistent order has no finding', ! array_filter( CapacityCounters::ticket_drift( array( wpp1b_occ( $d ) ) ) ) );
$wpdb->query( "UPDATE {$p}wpcap_order_items SET refunded_quantity=1 WHERE order_id=$o" );
$td = CapacityCounters::ticket_drift( array( wpp1b_occ( $d ) ) );
$doc = PassCaptain\Operations\Doctor::run( array( 'capacity_ticket_drift' ) );
check( 'ticket_drift finds a line that counts fewer units than valid tickets (unit released twice); the doctor reports it', 1 === count( $td ) && $o === $td[0]['order_id'] && 1 === $td[0]['units'] && 2 === $td[0]['tickets'] && $doc['capacity_ticket_drift']['count'] >= 1 && in_array( 'order:' . $o . ':type:' . $d, array_column( $doc['capacity_ticket_drift']['items'], 'key' ), true ) );
$wpdb->query( "UPDATE {$p}wpcap_order_items SET refunded_quantity=0 WHERE order_id=$o" );
CapacityCounters::sync_order_now( $o );

/* ------------------------------------------------------------------ Combinations, ticket by ticket */
// First operation on ticket 1, second operation on the same ticket 1, ticket 2 untouched; a pending
// refund is approved at the end. After every combination the invariant must hold.
$ops = array( 'refund', 'pending_refund', 'exchange', 'revoke', 'revoke_keep', 'cancel' );
$bad = array();
$wpp1b_run = static function ( $op, $order, $ticket, $target, &$pending, $tag ) {
	switch ( $op ) {
		case 'refund':
			return Refunds::request( $order, array( $ticket ), 'kombi', "wpp1b-combo-$tag" );
		case 'pending_refund':
			$r = Refunds::request( $order, array( $ticket ), 'kombi', "wpp1b-combo-$tag", array( 'require_approval' => true ) );
			if ( ! is_wp_error( $r ) ) { $pending[] = (int) $r['refund_id']; }
			return $r;
		case 'exchange':
			return Exchange::request( $ticket, $target, array( 'channel' => 'admin', 'idempotency_key' => "wpp1b-combo-$tag" ) );
		case 'revoke':
			return wpp1b_revoke( $ticket, 1 );
		case 'revoke_keep':
			return wpp1b_revoke( $ticket, 0 );
		default:
			return EventsPage::cancel_occurrence( array( 'occurrence_id' => wpp1b_occ( $target ), 'reason' => 'kombi', 'confirm' => 1 ) );
	}
};
$combos = 0;
foreach ( $ops as $first ) {
	foreach ( $ops as $second ) {
		$ta = wpp1b_type( 3 );
		$tb = wpp1b_type( 3, 2500, wpp1b_occ( $ta ), 0, 'Ziel' );
		$order = wpp1b_buy( $ta, 2 );
		list( $t1, $t2 ) = wpp1b_tickets( $order );
		$pending = array();
		$tag = "$first-$second-" . uniqid();
		$wpp1b_run( $first, $order, $t1, $tb, $pending, $tag . '-1' );
		// The second operation follows the first one on the ticket – or on the ticket it became.
		$current = (int) ( $wpdb->get_var( "SELECT new_ticket_id FROM " . Exchange::table() . " WHERE old_ticket_id=$t1 AND status='completed'" ) ?: $t1 );
		$wpp1b_run( $second, $order, $current, $tb, $pending, $tag . '-2' );
		foreach ( $pending as $refund_id ) { wpp1b_approve( $refund_id ); }
		CapacityCounters::flush_dirty();
		$problem = wpp1b_invariant( $order );
		$units = (int) $wpdb->get_var( "SELECT COALESCE(SUM(GREATEST(CAST(quantity AS SIGNED)-refunded_quantity,0)),0) FROM {$p}wpcap_order_items WHERE order_id=$order AND ticket_type_id=$ta" );
		$counting = 'refunded' !== Orders::get( $order )->status;
		if ( '' === $problem && $counting && wpp1b_avail( $ta ) !== 3 - $units ) { $problem = "availability of A " . wpp1b_avail( $ta ) . ' with ' . $units . ' units'; }
		if ( '' === $problem && wpp1b_live( $ta ) > 3 - (int) wpp1b_avail( $ta ) ) { $problem = 'more valid tickets than counted places'; }
		$paid_out = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount_minor),0) FROM {$p}wpcap_refunds WHERE order_id=$order AND status='completed'" );
		$refunded_tickets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$order AND refunded=1" );
		if ( '' === $problem && $paid_out > 2500 * $refunded_tickets ) { $problem = "paid out $paid_out for $refunded_tickets refunded tickets"; }
		if ( '' === $problem && 'valid' !== wpp1b_ticket( $t2 )->status && 'cancel' !== $first && 'cancel' !== $second ) { $problem = 'ticket 2 was touched'; }
		if ( '' !== $problem ) { $bad[] = "$first → $second: $problem"; }
		++$combos;
	}
}
check( "combinations refund × exchange × revoke × cancellation keep the invariant ($combos pairs)" . ( $bad ? ': ' . implode( '; ', array_slice( $bad, 0, 5 ) ) : '' ), ! $bad && 36 === $combos );

/* ------------------------------------------------------------------ Parallel processes */
// Refund, exchange and revoke (with release) of the same ticket at the same moment: exactly one wins,
// the unit is released once, nothing is oversold.
$race_bad = array();
$wins = 0;
for ( $round = 0; $round < 4; $round++ ) {
	$ta = wpp1b_type( 3 );
	$tb = wpp1b_type( 3, 2500, wpp1b_occ( $ta ), 0, 'Ziel' );
	$order = wpp1b_buy( $ta, 2 );
	$t1 = wpp1b_tickets( $order )[0];
	$start = microtime( true ) + 0.6;
	$procs = array();
	foreach ( array( 'refund', 'exchange', 'revoke' ) as $op ) {
		$procs[ $op ] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . " --wpp1b-child $op $order $t1 $tb $start 2>&1", 'r' );
	}
	$results = array();
	foreach ( $procs as $op => $proc ) { $results[ $op ] = trim( stream_get_contents( $proc ) ); pclose( $proc ); }
	$ok = count( array_filter( $results, static function ( $v ) { return 'OK' === $v; } ) );
	$wins += $ok;
	CapacityCounters::flush_dirty();
	$problem = wpp1b_invariant( $order );
	if ( $ok > 1 ) { $problem .= ' more than one winner ' . wp_json_encode( $results ); }
	if ( '' !== $problem ) { $race_bad[] = "round $round: $problem"; }
}
check( 'parallel refund/exchange/revoke of one ticket: at most one wins, the unit is released once' . ( $race_bad ? ': ' . implode( '; ', $race_bad ) : '' ), ! $race_bad && $wins >= 1 );
// Approval of a pending refund racing an exchange and a revoke of the same ticket (both refused while
// the refund is open) and a refund of the other ticket.
$race_bad = array();
for ( $round = 0; $round < 3; $round++ ) {
	$ta = wpp1b_type( 3 );
	$tb = wpp1b_type( 3, 2500, wpp1b_occ( $ta ), 0, 'Ziel' );
	$order = wpp1b_buy( $ta, 2 );
	list( $t1, $t2 ) = wpp1b_tickets( $order );
	$r = Refunds::request( $order, array( $t1 ), 'race', 'wpp1b-race-p-' . $round, array( 'require_approval' => true ) );
	$start = microtime( true ) + 0.6;
	$procs = array(
		'approve' => popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . " --wpp1b-child approve $order $t1 {$r['refund_id']} $start 2>&1", 'r' ),
		'exchange' => popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . " --wpp1b-child exchange $order $t1 $tb $start 2>&1", 'r' ),
		'revoke' => popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . " --wpp1b-child revoke $order $t1 0 $start 2>&1", 'r' ),
		'refund2' => popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . " --wpp1b-child refund $order $t2 0 $start 2>&1", 'r' ),
	);
	$results = array();
	foreach ( $procs as $op => $proc ) { $results[ $op ] = trim( stream_get_contents( $proc ) ); pclose( $proc ); }
	CapacityCounters::flush_dirty();
	$problem = wpp1b_invariant( $order );
	if ( 'OK' === $results['exchange'] || 'OK' === $results['revoke'] ) { $problem .= ' exchange/revoke ran during an open refund ' . wp_json_encode( $results ); }
	if ( 'OK' !== $results['approve'] ) { $problem .= ' approval failed ' . wp_json_encode( $results ); }
	if ( '' !== $problem ) { $race_bad[] = "round $round: $problem"; }
}
check( 'parallel approval vs exchange/revoke of the same ticket and a refund of the other: refund wins, invariant holds' . ( $race_bad ? ': ' . implode( '; ', $race_bad ) : '' ), ! $race_bad );

$GLOBALS['wpcap_test_hooks'] = $wpp1b_hooks_before;
