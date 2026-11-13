<?php
/**
 * A183: fault injection for deadlock (1213), lock wait timeout (1205), lost connection (2013,
 * 2006 with WordPress' reconnect) and a failing COMMIT on the critical paths: checkout, payment
 * webhook / mark paid, check-in, refund, waitlist accept and job claim.
 *
 * Each path is swept: the operation runs once per statement it issues, and exactly that statement
 * fails with the given fault (Test_WPDB::inject_fault()). After every faulted run the state must be
 * consistent (nothing half written, no capacity counter below its source sums, no transaction left
 * open, a failure reported to the caller as retryable), and a retry without faults must reach the
 * same final state as an undisturbed run, exactly once (idempotent).
 *
 * Included from run.php (uses check(), make_type(), tickets(), ticket_code_for()).
 */
// phpcs:ignoreFile
use PassCaptain\CapacityCounters;
use PassCaptain\Checkin;
use PassCaptain\Errors;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Refunds;
use PassCaptain\Settings;
use PassCaptain\Waitlists;
use PassCaptain\Jobs\TimeBudget;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
// The sweeps create many events: they are moved behind every other upcoming date at the end, so
// lists with a limit (block choices, partner forms) in later files still show those files' dates.
$a183_first_occurrence = (int) $wpdb->get_var( "SELECT COALESCE(MAX(occurrence_id),0) FROM {$p}wpcap_occurrences" );
// Only the jobs of this file are due while it runs (restored at the end).
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='a183_parked' WHERE status IN ('queued','running')" );

$a183_job_handler = array( Orders::class, 'handle_job' );
add_filter( 'wpcap_process_job', $a183_job_handler, 10, 3 );

/** The next cron runs of the issuance safety net (Orders::issue_failed() queues a retry job). */
function a183_run_issue_jobs() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_jobs SET run_after=%s WHERE job_type='ticket_issue_retry' AND status='queued'", gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
	Jobs::process_due( 50 );
}

/** Fault kinds of the sweep: name => wpcap_test_fault() options. */
function a183_kinds() {
	return array(
		'deadlock' => array( 'kind' => 'deadlock' ),
		'lock_timeout' => array( 'kind' => 'lock_timeout' ),
		'lock_timeout_rollback' => array( 'kind' => 'lock_timeout', 'rollback' => true ),
		'lost' => array( 'kind' => 'lost' ),
		'gone_away' => array( 'kind' => 'gone_away' ),
	);
}

/** Occurrence of a ticket type. */
function a183_occ( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}

/** Capacity counters below their source sums (an under-count lets later checkouts oversell). */
function a183_undercount( $type ) {
	$out = array();
	foreach ( CapacityCounters::drift( array( a183_occ( $type ) ) ) as $d ) {
		if ( $d['sold_qty'] < $d['sold_sum'] || $d['held_qty'] < $d['held_sum'] ) { $out[] = 'under-count ' . json_encode( $d ); }
	}
	return $out;
}

/** Any counter drift (exact consistency). */
function a183_drift( $type ) {
	return array_map( static fn( $d ) => 'drift ' . json_encode( $d ), CapacityCounters::drift( array( a183_occ( $type ) ) ) );
}

/**
 * Sweeps $op over every statement it issues with every fault kind.
 *
 * @param string   $label  Path name.
 * @param callable $setup  () => context (fresh fixture per run).
 * @param callable $op     (ctx) => result of the operation.
 * @param callable $verify (ctx, result, bool final) => string[] violations.
 * @param callable $retry  (ctx, result) => result of the retry (no faults).
 * @param array    $kinds  Fault kinds (default a183_kinds()).
 * @param string   $match_override Statements that may fail (regex; default: any statement, lock errors only on locking ones).
 */
function a183_sweep( $label, callable $setup, callable $op, callable $verify, callable $retry, array $kinds = array(), $match_override = null ) {
	$match = $match_override ?? '/^\s*(?!SELECT\s+(?:VERSION|@@in_transaction))/i';
	global $wpdb;
	$kinds = $kinds ? $kinds : a183_kinds();
	// Undisturbed run: the reference, and the statements of the operation.
	$ctx = $setup();
	$GLOBALS['wpcap_test_query_log'] = array();
	$r = $op( $ctx );
	$log = $GLOBALS['wpcap_test_query_log'];
	unset( $GLOBALS['wpcap_test_query_log'] );
	$base = array_merge( $verify( $ctx, $r, false ), $verify( $ctx, $retry( $ctx, $r ), true ) );
	check( "A183 $label: undisturbed run is consistent" . ( $base ? ' ' . implode( '; ', array_slice( $base, 0, 3 ) ) : '' ), ! $base );
	foreach ( $kinds as $name => $fault ) {
		// Lock errors only hit statements that take locks: writes, locking reads and COMMIT (plain
		// consistent reads never wait for a lock). A lost connection can hit any statement.
		$kind_match = in_array( $fault['kind'], array( 'deadlock', 'lock_timeout' ), true ) && null === $match_override ? '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|COMMIT)\b|\bFOR UPDATE\b|\bLOCK IN SHARE MODE\b/i' : $match;
		$points = count( preg_grep( $kind_match, $log ) );
		$violations = array();
		$fired_total = 0;
		for ( $i = 0; $i < $points; $i++ ) {
			$ctx = $setup();
			$GLOBALS['a183_kind'] = $name;
			wpcap_test_fault( $kind_match, $fault['kind'], array_merge( $fault, array( 'skip' => $i ) ) );
			$r = $op( $ctx );
			$fired = wpcap_test_faults_clear();
			$GLOBALS['a183_fired'] = $fired[0] ?? '';
			if ( ! $fired ) { continue; }
			++$fired_total;
			$where = "$name@$i " . substr( preg_replace( '/\s+/', ' ', $fired[0] ), 0, 90 );
			if ( 1 === (int) $wpdb->get_var( 'SELECT @@in_transaction' ) ) {
				$violations[] = "$where: transaction left open";
				$wpdb->query( 'ROLLBACK' );
			}
			foreach ( $verify( $ctx, $r, false ) as $v ) { $violations[] = "$where: $v"; }
			$r2 = $retry( $ctx, $r );
			foreach ( $verify( $ctx, $r2, true ) as $v ) { $violations[] = "$where (after retry): $v"; }
		}
		if ( getenv( 'A183_VERBOSE' ) ) { foreach ( $violations as $v ) { echo "     $label: $v\n"; } }
		check( "A183 $label under $name: $fired_total fault points, state consistent and retry idempotent" . ( $violations ? ' (' . count( $violations ) . ') ' . implode( ' | ', array_slice( $violations, 0, 4 ) ) : '' ), $fired_total > 0 && ! $violations );
	}
}

/** Failed calls must report a transient, retryable error (503) – never "sold out", "not found" or 409. */
function a183_error_ok( $r ) {
	return ! is_wp_error( $r ) || Errors::is_busy( $r );
}
function a183_err( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() . ' ' . json_encode( $r->get_error_data() ) : '';
}

/* ------------------------------------------------------------------------------------------- */
echo "A183: harness self-test\n";
$t = make_type( 5 );
wpcap_test_fault( '/^INSERT INTO \S+wpcap_audit_logs/', 'deadlock' );
$wpdb->query( 'START TRANSACTION' );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET name='A183-tx' WHERE ticket_type_id=$t" );
$dead = PassCaptain\Audit::record( 'a183', 'test', 1 );
$dead_err = $wpdb->last_error;
$in_tx = (int) $wpdb->get_var( 'SELECT @@in_transaction' );
$wpdb->query( 'COMMIT' );
check( 'A183 harness: deadlock fails the statement and rolls the transaction back', false !== strpos( $dead_err, 'Deadlock' ) && 0 === $in_tx && 'Standard' === $wpdb->get_var( "SELECT name FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t" ) && 1 === count( wpcap_test_faults_clear() ) );
wpcap_test_fault( '/^UPDATE \S+wpcap_ticket_types/', 'lock_timeout', array( 'skip' => 1 ) );
$wpdb->query( 'START TRANSACTION' );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET name='A183-1' WHERE ticket_type_id=$t" );
$second = $wpdb->query( "UPDATE {$p}wpcap_ticket_types SET max_per_order=3 WHERE ticket_type_id=$t" );
$in_tx = (int) $wpdb->get_var( 'SELECT @@in_transaction' );
$wpdb->query( 'COMMIT' );
check( 'A183 harness: lock wait timeout fails only the statement (skip counts matches)', false === $second && 1 === $in_tx && 'A183-1' === $wpdb->get_var( "SELECT name FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t" ) && 10 === (int) $wpdb->get_var( "SELECT max_per_order FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t" ) && 1 === count( wpcap_test_faults_clear() ) );
$conn = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
wpcap_test_fault( '/^COMMIT/', 'lost', array( 'executed' => true ) );
$wpdb->query( 'START TRANSACTION' );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET name='A183-2' WHERE ticket_type_id=$t" );
$commit = $wpdb->query( 'COMMIT' );
check( 'A183 harness: COMMIT whose reply is lost reports failure, but the data is committed on a new session', false === $commit && 'A183-2' === $wpdb->get_var( "SELECT name FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t" ) && $conn !== $wpdb->get_var( 'SELECT CONNECTION_ID()' ) );
wpcap_test_faults_clear();
wpcap_test_fault( '/^UPDATE/', 'gone_away' );
$wpdb->query( 'START TRANSACTION' );
$wpdb->query( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t FOR UPDATE" );
$ok = $wpdb->query( "UPDATE {$p}wpcap_ticket_types SET name='A183-3' WHERE ticket_type_id=$t" );
$in_tx = (int) $wpdb->get_var( 'SELECT @@in_transaction' );
$wpdb->query( 'ROLLBACK' );
check( 'A183 harness: gone away reconnects like wpdb and the statement autocommits outside the transaction', 1 === $ok && 0 === $in_tx && 'A183-3' === $wpdb->get_var( "SELECT name FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$t" ) && 1 === count( wpcap_test_faults_clear() ) );

/* ------------------------------------------------------------------------------------------- */
echo "A183: checkout / order creation under faults\n";
/** Problems of the order created with $key: [violations, order row or null]. */
function a183_order_state( $key, $qty, $paid_flow ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$out = array();
	$orders = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_orders WHERE idempotency_key=%s", hash( 'sha256', $key ) ) );
	if ( count( $orders ) > 1 ) { $out[] = 'duplicate orders for one key'; }
	$order = $orders[0] ?? null;
	if ( $order ) {
		$items = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $order->order_id );
		if ( $items !== $qty ) { $out[] = "half-written order: $items of $qty units in lines"; }
		if ( $paid_flow ) {
			$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE status='active' AND order_id=" . (int) $order->order_id );
			if ( $held !== $qty ) { $out[] = "half-written order: $held of $qty units held"; }
		}
	}
	return array( $out, $order );
}
/** Lines or holds of a ticket type whose order does not exist (writes after a lost transaction). */
function a183_orphans( $type ) {
	global $wpdb;
	$p = $wpdb->prefix;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations r LEFT JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE o.order_id IS NULL AND r.ticket_type_id=" . (int) $type )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_order_items i LEFT JOIN {$p}wpcap_orders o ON o.order_id=i.order_id WHERE o.order_id IS NULL AND i.ticket_type_id=" . (int) $type );
}
$co_setup = static function ( $price ) {
	return static function () use ( $price ) {
		$GLOBALS['wpcap_test_transients'] = array();
		return array( 'type' => make_type( 3, 10, $price ), 'key' => 'a183-co-' . uniqid( '', true ), 'price' => $price );
	};
};
$co_op = static function ( $ctx ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'a183-co@example.com', 'items' => array( array( 'ticket_type_id' => $ctx['type'], 'quantity' => 2 ) ), 'idempotency_key' => $ctx['key'] ) );
};
$co_verify = static function ( $ctx, $r, $final ) {
	$out = array();
	if ( ! a183_error_ok( $r ) ) { $out[] = 'not retryable: ' . a183_err( $r ); }
	if ( $final && is_wp_error( $r ) ) { $out[] = 'retry failed: ' . a183_err( $r ); }
	list( $problems, $order ) = a183_order_state( $ctx['key'], 2, $ctx['price'] > 0 );
	$out = array_merge( $out, $problems );
	if ( $orphans = a183_orphans( $ctx['type'] ) ) { $out[] = "$orphans orphan lines/holds"; }
	if ( ! $order && ! is_wp_error( $r ) ) { $out[] = 'success reported without an order'; }
	if ( $order && 0 === $ctx['price'] && ( $final || ! is_wp_error( $r ) ) && 2 !== count( tickets( (int) $order->order_id, 'valid' ) ) ) { $out[] = 'free order without its 2 tickets'; }
	$out = array_merge( $out, $final ? a183_drift( $ctx['type'] ) : a183_undercount( $ctx['type'] ) );
	Orders::flush_availability_cache();
	if ( $final && 1 !== Orders::availability( $ctx['type'] ) ) { $out[] = 'availability ' . Orders::availability( $ctx['type'] ) . ' instead of 1'; }
	return $out;
};
$co_retry = static function ( $ctx, $r ) use ( $co_op ) {
	Orders::flush_availability_cache();
	$r2 = $co_op( $ctx );
	a183_run_issue_jobs();
	return $r2;
};
a183_sweep( 'checkout (paid, hold)', $co_setup( 2500 ), $co_op, $co_verify, $co_retry );
a183_sweep( 'checkout (free, tickets issued)', $co_setup( 0 ), $co_op, $co_verify, $co_retry );
a183_sweep( 'checkout, COMMIT committed but its reply lost', $co_setup( 2500 ), $co_op, $co_verify, $co_retry, array( 'lost_after_commit' => array( 'kind' => 'lost', 'executed' => true ) ), '/^COMMIT/' );

/** Commit-reply-lost variant for the other paths: every COMMIT of the operation. */
function a183_lost_commit() {
	return array( 'lost_after_commit' => array( 'kind' => 'lost', 'executed' => true ) );
}

/* ------------------------------------------------------------------------------------------- */
echo "A183: payment webhook / mark paid under faults\n";
/** Order state after a payment: problems (tickets never partial, paid ⇔ holds converted). */
function a183_paid_state( $order_id, $qty, $final ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$out = array();
	$order = Orders::get( $order_id );
	$valid = count( tickets( $order_id, 'valid' ) );
	$all = count( tickets( $order_id ) );
	$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE status='active' AND order_id=" . (int) $order_id );
	if ( $all !== $valid || ( $valid && $valid !== $qty ) ) { $out[] = "partial issuance: $valid valid of $all tickets"; }
	if ( 'paid' === $order->payment_status ) {
		if ( $held ) { $out[] = "paid order still holds $held units"; }
		if ( $final && $valid !== $qty ) { $out[] = "paid order with $valid of $qty tickets"; }
	} else {
		if ( $all ) { $out[] = 'tickets of an unpaid order'; }
		// After a lost connection the conversion of the holds may have run on its own: the order is
		// then like one whose hold lapsed, and the redelivered payment is booked as a late payment.
		if ( $held !== $qty && ( $final || ! in_array( $GLOBALS['a183_kind'] ?? '', array( 'lost', 'gone_away' ), true ) || $held ) ) { $out[] = "unpaid order lost its hold ($held of $qty)"; }
		if ( $final ) { $out[] = 'order not paid after retry: ' . $order->payment_status; }
	}
	return $out;
}
$wh_setup = static function ( $expired ) {
	return static function () use ( $expired ) {
		$GLOBALS['wpcap_test_transients'] = array();
		$type = make_type( 3, 10, 1000 );
		$order = Orders::create( array( 'email' => 'a183-wh@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'a183-wh-' . uniqid( '', true ) ), array( 'payment_provider' => 'stripe' ) );
		if ( $expired ) { expire_holds_of( $order['order_id'] ); }
		return array( 'type' => $type, 'order_id' => (int) $order['order_id'], 'public_id' => $order['public_id'], 'event' => 'evt_a183_' . uniqid() );
	};
};
$wh_op = static function ( $ctx ) {
	return Webhooks::process( 'stripe', array( 'event_id' => $ctx['event'], 'type' => 'paid', 'order_public_id' => $ctx['public_id'], 'provider_reference' => 'pi_' . $ctx['event'], 'amount_minor' => 2000, 'currency' => 'EUR' ) );
};
$wh_verify = static function ( $ctx, $r, $final ) {
	global $wpdb;
	$out = array();
	if ( ! a183_error_ok( $r ) ) { $out[] = 'not retryable: ' . a183_err( $r ); }
	if ( ! is_wp_error( $r ) && ! in_array( $r, array( 'paid', 'already_paid', 'duplicate' ), true ) ) { $out[] = "payment not booked: $r"; }
	$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_payment_events WHERE provider='stripe' AND provider_event_id=%s", $ctx['event'] ) );
	if ( is_wp_error( $r ) && 'processed' === $status ) { $out[] = 'failed event recorded as processed (no redelivery)'; }
	if ( $final && 'processed' !== $status ) { $out[] = "event not processed after redelivery: $status"; }
	$out = array_merge( $out, a183_paid_state( $ctx['order_id'], 2, $final ) );
	return array_merge( $out, $final ? a183_drift( $ctx['type'] ) : a183_undercount( $ctx['type'] ) );
};
$wh_retry = static function ( $ctx, $r ) use ( $wh_op ) {
	global $wpdb;
	// The provider redelivers until it gets a 2xx; a claim left in "processing" is taken over after 10 minutes.
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_payment_events SET updated_at=%s WHERE provider='stripe' AND provider_event_id=%s AND status='processing'", gmdate( 'Y-m-d H:i:s', time() - 11 * 60 ), $ctx['event'] ) );
	$r2 = $wh_op( $ctx );
	a183_run_issue_jobs();
	return is_wp_error( $r2 ) ? $r2 : ( 'duplicate' === $r2 && is_wp_error( $r ) ? 'redelivery answered duplicate after a failure' : $r2 );
};
a183_sweep( 'payment webhook (hold active)', $wh_setup( false ), $wh_op, $wh_verify, $wh_retry );
a183_sweep( 'payment webhook (late payment, hold lapsed)', $wh_setup( true ), $wh_op, $wh_verify, $wh_retry );
a183_sweep( 'payment webhook, COMMIT committed but its reply lost', $wh_setup( false ), $wh_op, $wh_verify, $wh_retry, a183_lost_commit(), '/^COMMIT/' );

/* ------------------------------------------------------------------------------------------- */
echo "A183: check-in under faults\n";
$ci_setup = static function () {
	$GLOBALS['wpcap_test_transients'] = array();
	$type = make_type( 10, 10, 0 );
	$order = Orders::create( array( 'email' => 'a183-ci@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'a183-ci-' . uniqid( '', true ) ) );
	return array( 'type' => $type, 'order_id' => (int) $order['order_id'], 'code' => ticket_code_for( $order['order_id'] )[0], 'key' => 'a183-scan-' . uniqid( '', true ) );
};
$ci_op = static function ( $ctx ) { return Checkin::perform( $ctx['code'], array( 'idempotency_key' => $ctx['key'] ) ); };
$ci_verify = static function ( $ctx, $r, $final ) {
	global $wpdb;
	$out = array();
	if ( ! a183_error_ok( $r ) ) { $out[] = 'not retryable: ' . a183_err( $r ); }
	if ( $final && ( is_wp_error( $r ) || 'checked_in' !== $r['status'] ) ) { $out[] = 'retry did not admit: ' . ( is_wp_error( $r ) ? a183_err( $r ) : $r['status'] ); }
	$ticket = tickets( $ctx['order_id'] )[0];
	$logs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_checkins WHERE result='checked_in' AND ticket_id=" . (int) $ticket->ticket_id );
	$admitted = 'checked_in' === $ticket->status;
	if ( $admitted !== ( 1 === $logs ) || $logs > 1 ) { $out[] = "ticket {$ticket->status} with $logs admission logs"; }
	if ( ! is_wp_error( $r ) && ! $admitted ) { $out[] = 'admission reported but ticket not checked in'; }
	if ( is_wp_error( $r ) && $admitted ) { $out[] = 'scan reported as failed but the ticket was admitted (a rescan says "already checked in")'; }
	return $out;
};
$ci_retry = static function ( $ctx, $r ) use ( $ci_op ) { return $ci_op( $ctx ); };
a183_sweep( 'check-in', $ci_setup, $ci_op, $ci_verify, $ci_retry );
a183_sweep( 'check-in, COMMIT committed but its reply lost', $ci_setup, $ci_op, $ci_verify, $ci_retry, a183_lost_commit(), '/^COMMIT/' );

/* ------------------------------------------------------------------------------------------- */
echo "A183: refund under faults\n";
$rf_setup = static function () {
	$GLOBALS['wpcap_test_transients'] = array();
	$type = make_type( 5, 10, 1000 );
	$order = Orders::create( array( 'email' => 'a183-rf@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'a183-rf-' . uniqid( '', true ) ) );
	Orders::confirm_manual_payment( $order['order_id'] );
	$all = tickets( $order['order_id'] );
	return array( 'type' => $type, 'order_id' => (int) $order['order_id'], 'ticket' => (int) $all[0]->ticket_id, 'other' => (int) $all[1]->ticket_id, 'key' => 'a183-rf-' . uniqid( '', true ) );
};
$rf_op = static function ( $ctx ) { return Refunds::request( $ctx['order_id'], array( $ctx['ticket'] ), 'A183', $ctx['key'] ); };
$rf_verify = static function ( $ctx, $r, $final ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$out = array();
	if ( ! a183_error_ok( $r ) ) { $out[] = 'not retryable: ' . a183_err( $r ); }
	$rows = $wpdb->get_results( "SELECT status,provider_reference FROM {$p}wpcap_refunds WHERE order_id=" . (int) $ctx['order_id'] );
	if ( count( $rows ) > 1 ) { $out[] = count( $rows ) . ' refund rows for one request'; }
	$status = $rows ? $rows[0]->status : '';
	$ticket = $wpdb->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ctx['ticket'] );
	$other = $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ctx['other'] );
	$order = Orders::get( $ctx['order_id'] );
	$line = (int) $wpdb->get_var( "SELECT SUM(refunded_quantity) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $ctx['order_id'] );
	$booked = 'revoked' === $ticket->status && 1 === (int) $ticket->refunded && 1000 === (int) $order->refunded_minor && 1 === $line;
	$untouched = 'valid' === $ticket->status && 0 === (int) $ticket->refunded && 0 === (int) $order->refunded_minor && 0 === $line;
	if ( 'completed' === $status && ! $booked ) { $out[] = "completed refund half booked (ticket {$ticket->status}/{$ticket->refunded}, refunded_minor {$order->refunded_minor}, line $line)"; }
	// "processing": paid out, bookkeeping still open – finished by the repair (repair_stuck()).
	if ( 'completed' !== $status && ! $untouched && ( $final || 'processing' !== $status ) ) { $out[] = "refund $status but bookkeeping changed (ticket {$ticket->status}/{$ticket->refunded}, refunded_minor {$order->refunded_minor}, line $line)"; }
	if ( 'valid' !== $other || 'paid' !== $order->payment_status ) { $out[] = "other ticket $other, order {$order->payment_status}"; }
	if ( $final && 'completed' !== $status ) { $out[] = "refund $status after retry"; }
	return array_merge( $out, $final ? a183_drift( $ctx['type'] ) : a183_undercount( $ctx['type'] ) );
};
$rf_retry = static function ( $ctx, $r ) use ( $rf_op ) {
	global $wpdb;
	$r2 = $rf_op( $ctx );
	// A payout without its bookkeeping is finished by the cron repair (after 10 minutes).
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_refunds SET updated_at=%s WHERE order_id=%d AND status='processing'", gmdate( 'Y-m-d H:i:s', time() - 11 * 60 ), $ctx['order_id'] ) );
	Refunds::repair_stuck();
	return $r2;
};
a183_sweep( 'refund', $rf_setup, $rf_op, $rf_verify, $rf_retry );
a183_sweep( 'refund, COMMIT committed but its reply lost', $rf_setup, $rf_op, $rf_verify, $rf_retry, a183_lost_commit(), '/^COMMIT/' );

/* ------------------------------------------------------------------------------------------- */
echo "A183: waitlist accept under faults\n";
$wl_setup = static function () {
	global $wpdb;
	for ( $try = 0; $try < 3; $try++ ) {
		$GLOBALS['wpcap_test_transients'] = array();
		$type = make_type( 1, 10, 0 );
		$occ = a183_occ( $type );
		$event = (int) $wpdb->get_var( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=$occ" );
		$email = 'a183-wl-' . uniqid() . '@example.com';
		$_SERVER['REMOTE_ADDR'] = '10.83.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
		Waitlists::join( $event, $occ, $type, $email );
		$offer = Waitlists::offer_next( $event, $type );
		if ( ! is_wp_error( $offer ) ) { break; } // Fixture only (e.g. a join refused by its rate limit): a fresh one.
	}
	$mail = end( $GLOBALS['wpcap_test_mails'] );
	preg_match( '/token=([a-f0-9]{64})/', $mail['body'], $m );
	return array( 'type' => $type, 'waitlist_id' => (int) $offer['waitlist_id'], 'token' => $m[1] ?? '', 'order_id' => (int) Waitlists::get( $offer['waitlist_id'] )->order_id );
};
$wl_op = static function ( $ctx ) { return Waitlists::accept_offer( $ctx['waitlist_id'], $ctx['token'] ); };
$wl_verify = static function ( $ctx, $r, $final ) {
	global $wpdb;
	$out = array();
	$entry = Waitlists::get( $ctx['waitlist_id'] );
	$order = Orders::get( $ctx['order_id'] );
	$held = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_reservations WHERE status='active' AND order_id=" . (int) $ctx['order_id'] );
	if ( 'pending' === $order->payment_status && ! $held && ! tickets( $ctx['order_id'] ) && in_array( $GLOBALS['a183_kind'] ?? '', array( 'lost', 'gone_away' ), true ) ) {
		// The hold conversion ran on its own after a lost connection: the offer has lapsed (no
		// tickets, nothing held, the expiry job closes it and offers the units to the next entry).
		return $final ? a183_drift( $ctx['type'] ) : a183_undercount( $ctx['type'] );
	}
	if ( ! a183_error_ok( $r ) ) { $out[] = 'not retryable: ' . a183_err( $r ); }
	if ( 'offered' === $entry->status ) {
		if ( 'pending' !== $order->payment_status ) { $out[] = "open offer with order {$order->payment_status}"; }
		if ( $final ) { $out[] = 'offer still open after retry'; }
	} elseif ( 'paid' === $entry->status || 'accepted' === $entry->status ) {
		if ( 'paid' !== $order->payment_status && ! is_wp_error( $r ) ) { $out[] = "accepted free offer, order {$order->payment_status}"; }
		if ( $final && 'paid' !== $order->payment_status ) { $out[] = "accepted free offer, order {$order->payment_status} after retry"; }
	} else {
		$out[] = "entry {$entry->status}";
	}
	$out = array_merge( $out, a183_paid_state( $ctx['order_id'], 1, $final ) );
	return array_merge( $out, $final ? a183_drift( $ctx['type'] ) : a183_undercount( $ctx['type'] ) );
};
$wl_retry = static function ( $ctx, $r ) use ( $wl_op ) {
	$r2 = $wl_op( $ctx );
	a183_run_issue_jobs();
	// Already accepted by the first call: the link is used up, the order is what counts.
	return is_wp_error( $r2 ) && 'offered' !== Waitlists::get( $ctx['waitlist_id'] )->status && ! Errors::is_busy( $r2 ) ? array( 'status' => 'paid' ) : $r2;
};
a183_sweep( 'waitlist accept (free offer)', $wl_setup, $wl_op, $wl_verify, $wl_retry );
a183_sweep( 'waitlist accept, COMMIT committed but its reply lost', $wl_setup, $wl_op, $wl_verify, $wl_retry, a183_lost_commit(), '/^COMMIT/' );

/* ------------------------------------------------------------------------------------------- */
echo "A183: job claim under faults\n";
$GLOBALS['a183_job_runs'] = array();
add_filter( 'wpcap_process_job', static function ( $handled, $type, $payload ) {
	if ( 'a183_job' !== $type ) { return $handled; }
	$GLOBALS['a183_job_runs'][ $payload['key'] ] = ( $GLOBALS['a183_job_runs'][ $payload['key'] ] ?? 0 ) + 1;
	return true;
}, 10, 3 );
$jb_setup = static function () {
	$key = 'a183-job-' . uniqid( '', true );
	Jobs::enqueue( $key, 'a183_job', array( 'key' => $key ), gmdate( 'Y-m-d H:i:s', time() - 5 ) );
	return array( 'key' => $key );
};
$jb_op = static function ( $ctx ) { return Jobs::process_due( 1 ); };
$jb_verify = static function ( $ctx, $r, $final ) {
	global $wpdb;
	$out = array();
	$runs = $GLOBALS['a183_job_runs'][ $ctx['key'] ] ?? 0;
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts FROM {$wpdb->prefix}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $ctx['key'] ) ) );
	if ( $runs > 1 && ! preg_match( "/status='complete'|SET status=/", (string) ( $GLOBALS['a183_fired'] ?? '' ) ) ) { $out[] = "handler ran $runs times"; }
	if ( $runs > 2 ) { $out[] = "handler ran $runs times"; }
	if ( ! in_array( $job->status, array( 'queued', 'running', 'complete' ), true ) ) { $out[] = "job {$job->status}"; }
	if ( 'complete' === $job->status && ! $runs ) { $out[] = 'job complete without running'; }
	if ( $final && ( 'complete' !== $job->status || ! $runs ) ) { $out[] = "job {$job->status} after $runs runs"; }
	return $out;
};
$jb_retry = static function ( $ctx, $r ) use ( $jb_op ) {
	global $wpdb;
	// The next cron runs: a claim of a worker that died is taken back after 15 minutes (with the
	// backoff of a failed run), and the job runs once that has passed.
	TimeBudget::release_claims();
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_jobs SET locked_at=%s WHERE idempotency_key=%s AND status='running'", gmdate( 'Y-m-d H:i:s', time() - 16 * 60 ), hash( 'sha256', $ctx['key'] ) ) );
	$jb_op( $ctx );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_jobs SET run_after=%s WHERE idempotency_key=%s AND status='queued'", gmdate( 'Y-m-d H:i:s', time() - 5 ), hash( 'sha256', $ctx['key'] ) ) );
	return $jb_op( $ctx );
};
a183_sweep( 'job claim', $jb_setup, $jb_op, $jb_verify, $jb_retry );
a183_sweep( 'job claim, COMMIT committed but its reply lost', $jb_setup, $jb_op, $jb_verify, $jb_retry, a183_lost_commit(), '/^COMMIT/' );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type IN ('a183_job','ticket_issue_retry')" );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='queued' WHERE status='a183_parked'" );
remove_filter( 'wpcap_process_job', $a183_job_handler, 10 );
wpcap_test_faults_clear();
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at=starts_at + INTERVAL 3 YEAR WHERE occurrence_id>$a183_first_occurrence" );
