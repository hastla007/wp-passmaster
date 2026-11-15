<?php
/**
 * Systematic fault injection across the critical flows (W6).
 *
 * For every flow a fault-free dry run records the statements it executes; then each statement in
 * turn fails with each kind of real database failure (generic error, deadlock 1213 with server-side
 * rollback, lock wait timeout 1205, lost connection 2013 with every later statement failing too).
 * After every injected fault the flow's invariant is checked:
 *
 *   - no success response without the persisted state behind it,
 *   - no half state (e.g. a paid order with only some tickets, a check-in log without the ticket
 *     status, a "failed" box office sale whose tickets were cancelled because of a transient error),
 *   - an immediate retry never acknowledges something that is not persisted,
 *   - after recovery (cron/job queue, provider redelivery after the takeover window) and a retry the
 *     flow ends in exactly the state of a fault-free run (no duplicates).
 *
 * Runs after zzz_audit_db_robustness.php. Uses check(), make_type(), ticket_code_for(), app_call().
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fault_wpdb.php';

use PassCaptain\Orders;
use PassCaptain\Checkin;
use PassCaptain\Refunds;
use PassCaptain\Jobs;
use PassCaptain\Payments\Webhooks;
use PassCaptain\BoxOffice\Service as W6Pos;
use PassCaptain\Admission\Policies;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
// Claims left "processing" by an injected crash are retried at once here, not after the 3 s wait.
$GLOBALS['wpcap_test_filters']['wpcap_webhook_claim_wait_ms'] = 0;

/**
 * Runs a fault matrix over one flow.
 *
 * @param string        $label
 * @param callable      $setup   fn(): array            fresh fixture (real $wpdb)
 * @param callable      $run     fn(array $ctx): mixed   the flow (runs under the fault proxy)
 * @param callable      $check   fn(array $ctx, $result, string $phase): string  '' or violation.
 *                               phase 'fault' (after the fault and after an immediate retry) or 'final'.
 * @param callable|null $recover fn(array $ctx): void    recovery between the retries (cron, time travel)
 * @param int|null      $max     maximum statement positions tested (evenly sampled above); all with WPCAP_TEST_SLOW=1
 * @return array{positions:int,runs:int,violations:string[]}
 */
function w6_matrix( $label, callable $setup, callable $run, callable $check, $recover = null, $max = null ) {
	$max = getenv( 'WPCAP_TEST_SLOW' ) ? PHP_INT_MAX : ( $max ?? 24 );
	$ctx = $setup();
	$f = wpcap_faults();
	$result = $run( $ctx );
	$statements = $f->log;
	wpcap_faults_restore();
	$violations = array();
	$dry = $check( $ctx, $result, 'final' );
	if ( '' !== $dry ) { $violations[] = "dry run: $dry"; }
	$n = count( $statements );
	$positions = range( 0, max( 0, $n - 1 ) );
	if ( $n > $max ) {
		$positions = array();
		for ( $i = 0; $i < $max; $i++ ) { $positions[] = (int) floor( $i * ( $n - 1 ) / max( 1, $max - 1 ) ); }
		$positions = array_values( array_unique( $positions ) );
	}
	$runs = 0;
	foreach ( $positions as $pos ) {
		foreach ( wpcap_fault_kinds() as $kind ) {
			$ctx = $setup();
			$f = wpcap_faults();
			$f->fail_nth( $pos, $kind );
			try { $result = $run( $ctx ); } catch ( Throwable $e ) { $result = new WP_Error( 'exception', $e->getMessage() ); }
			$stmt = substr( preg_replace( '/\s+/', ' ', (string) ( $f->log[ $pos ] ?? '?' ) ), 0, 90 );
			wpcap_faults_restore();
			++$runs;
			$where = "#$pos $kind [$stmt]";
			$v = $check( $ctx, $result, 'fault' );
			if ( '' !== $v ) { $violations[] = "$where after fault: $v"; continue; }
			$again = $run( $ctx ); // Immediate retry (client/provider/cashier), no recovery yet.
			$v = $check( $ctx, $again, 'fault' );
			if ( '' !== $v ) { $violations[] = "$where immediate retry: $v"; continue; }
			if ( $recover ) { $recover( $ctx ); }
			$final = $run( $ctx );
			$v = $check( $ctx, $final, 'final' );
			if ( '' !== $v ) { $violations[] = "$where final: $v"; }
		}
	}
	$ok = ! $violations;
	check( "$label: " . count( $positions ) . " of $n statements x 4 failure kinds ($runs runs) keep the invariants", $ok );
	if ( ! $ok ) { foreach ( array_slice( $violations, 0, 6 ) as $v ) { echo "         $v\n"; } }
	return array( 'positions' => count( $positions ), 'runs' => $runs, 'violations' => $violations );
}

function w6_count( $sql ) { global $wpdb; return (int) $wpdb->get_var( $sql ); }
function w6_err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
/** Recovery a real site performs on its own: provider redelivery after the claim takeover window, cron. */
function w6_recover() {
	global $wpdb;
	$p = $wpdb->prefix;
	$past = gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS );
	$wpdb->query( "UPDATE {$p}wpcap_payment_events SET updated_at='$past' WHERE status='processing'" );
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE status='queued' AND job_type IN ('ticket_issue')" );
	Jobs::process_due( 50 );
}

/* ------------------------------------------------------------------------------------------ */
echo "W6 faults: checkout (Orders::create)\n";
$w6_type = make_type( 0, 10, 1000 );
w6_matrix(
	'checkout with hold',
	static function () use ( $w6_type ) {
		wpcap_test_reset_transients();
		return array( 'type' => $w6_type, 'email' => 'w6co' . uniqid() . '@example.com', 'key' => 'w6co-' . uniqid( '', true ) );
	},
	static function ( $ctx ) {
		return Orders::create( array( 'email' => $ctx['email'], 'items' => array( array( 'ticket_type_id' => $ctx['type'], 'quantity' => 2 ) ), 'idempotency_key' => $ctx['key'] ), array( 'skip_rate_limit' => true ) );
	},
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$orders = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE customer_email=%s", $ctx['email'] ) );
		$ids = $orders ? implode( ',', array_map( 'intval', $orders ) ) : '0';
		$items = w6_count( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_order_items WHERE order_id IN ($ids)" );
		$held = w6_count( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE order_id IN ($ids) AND status='active'" );
		if ( count( $orders ) > 1 ) { return 'duplicate orders'; }
		if ( $orders && ( 2 !== $items || 2 !== $held ) ) { return "half state: items $items, holds $held"; }
		if ( ! is_wp_error( $r ) && ( ! $orders || (int) $orders[0] !== (int) $r['order_id'] ) ) { return 'success without persisted order'; }
		if ( 'final' === $phase && ( is_wp_error( $r ) || ! $orders ) ) { return 'retry did not create the order: ' . w6_err( $r ); }
		return '';
	}
);

$w6_free = make_type( 0, 10, 0 );
w6_matrix(
	'free checkout with immediate issuance',
	static function () use ( $w6_free ) {
		wpcap_test_reset_transients();
		return array( 'type' => $w6_free, 'email' => 'w6free' . uniqid() . '@example.com', 'key' => 'w6free-' . uniqid( '', true ) );
	},
	static function ( $ctx ) {
		return Orders::create( array( 'email' => $ctx['email'], 'items' => array( array( 'ticket_type_id' => $ctx['type'], 'quantity' => 2 ) ), 'idempotency_key' => $ctx['key'] ), array( 'skip_rate_limit' => true ) );
	},
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status FROM {$p}wpcap_orders WHERE customer_email=%s", $ctx['email'] ) );
		$tickets = $order ? w6_count( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $order->order_id ) : 0;
		if ( $order && ! in_array( $tickets, array( 0, 2 ), true ) ) { return "partial issuance ($tickets tickets)"; }
		if ( ! is_wp_error( $r ) && ! $order ) { return 'success without persisted order'; }
		if ( 'final' === $phase && ( ! $order || 'paid' !== $order->payment_status || 2 !== $tickets ) ) { return 'not recovered: ' . ( $order ? $order->payment_status . "/$tickets tickets" : 'no order' ); }
		return '';
	},
	'w6_recover'
);

/* ------------------------------------------------------------------------------------------ */
echo "W6 faults: payment confirmation\n";
$w6_paid_type = make_type( 0, 10, 1000 );
/** Pending native order of 2 tickets (manual provider). */
$w6_pending = static function ( $type ) {
	wpcap_test_reset_transients();
	$email = 'w6pay' . uniqid() . '@example.com';
	$o = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'w6pay-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
	return array( 'order' => $o, 'event_id' => 'w6evt-' . uniqid( '', true ) );
};
/** Paid + issued, or nothing; acknowledged only when complete. */
$w6_payment_check = static function ( $ctx, $r, $phase, $ack ) {
	$order = Orders::get( $ctx['order']['order_id'] );
	$tickets = count( Orders::tickets_for_order( $ctx['order']['order_id'] ) );
	if ( ! in_array( $tickets, array( 0, 2 ), true ) ) { return "partial issuance ($tickets tickets)"; }
	if ( 'paid' !== $order->payment_status && $tickets ) { return 'tickets without payment'; }
	if ( $ack( $r ) && ( 'paid' !== $order->payment_status || 2 !== $tickets ) ) { return 'acknowledged (' . ( is_string( $r ) ? $r : 'true' ) . ') but order is ' . $order->payment_status . " with $tickets tickets"; }
	if ( 'final' === $phase && ( 'paid' !== $order->payment_status || 2 !== $tickets ) ) { return 'not recovered: ' . $order->payment_status . "/$tickets tickets"; }
	return '';
};
w6_matrix(
	'paid webhook',
	static function () use ( $w6_pending, $w6_paid_type ) { return $w6_pending( $w6_paid_type ); },
	static function ( $ctx ) {
		return Webhooks::process( 'manual', array( 'event_id' => $ctx['event_id'], 'type' => 'paid', 'order_public_id' => $ctx['order']['public_id'], 'amount_minor' => 2000, 'currency' => 'EUR' ) );
	},
	static function ( $ctx, $r, $phase ) use ( $w6_payment_check ) {
		// Any non-error answer is a 2xx for the provider, which then stops retrying.
		return $w6_payment_check( $ctx, $r, $phase, static fn( $r ) => ! is_wp_error( $r ) );
	},
	'w6_recover'
);
w6_matrix(
	'manual payment confirmation',
	static function () use ( $w6_pending, $w6_paid_type ) { return $w6_pending( $w6_paid_type ); },
	static function ( $ctx ) { return Orders::confirm_manual_payment( $ctx['order']['order_id'] ); },
	static function ( $ctx, $r, $phase ) use ( $w6_payment_check ) {
		// true means "payment booked"; tickets may follow through the issuance retry (checked in 'final').
		$order = Orders::get( $ctx['order']['order_id'] );
		if ( true === $r && 'paid' !== $order->payment_status ) { return 'success without booked payment'; }
		return $w6_payment_check( $ctx, $r, $phase, static fn( $r ) => false );
	},
	'w6_recover'
);

/* ------------------------------------------------------------------------------------------ */
echo "W6 faults: cancellation and refund\n";
w6_matrix(
	'cancel paid order',
	static function () use ( $w6_pending, $w6_paid_type ) {
		$ctx = $w6_pending( $w6_paid_type );
		Orders::confirm_external_payment( $ctx['order']['order_id'], 'w6' );
		return $ctx;
	},
	static function ( $ctx ) { return Orders::cancel_order( $ctx['order']['order_id'], 'w6' ); },
	static function ( $ctx, $r, $phase ) {
		$order = Orders::get( $ctx['order']['order_id'] );
		$valid = count( tickets( $ctx['order']['order_id'], 'valid' ) );
		$revoked = count( tickets( $ctx['order']['order_id'], 'revoked' ) );
		if ( 'cancelled' === $order->status && $valid ) { return 'cancelled order with valid tickets'; }
		if ( 'cancelled' !== $order->status && $revoked ) { return "half cancellation ($revoked revoked)"; }
		if ( true === $r && 'cancelled' !== $order->status ) { return 'success without cancellation'; }
		if ( 'final' === $phase && ( 'cancelled' !== $order->status || 2 !== $revoked ) ) { return 'not cancelled after retry'; }
		return '';
	}
);

$w6_refund_type = make_type( 0, 10, 1190 );
w6_matrix(
	'native partial refund (request + execute)',
	static function () use ( $w6_pending, $w6_refund_type ) {
		$ctx = $w6_pending( $w6_refund_type );
		Orders::confirm_external_payment( $ctx['order']['order_id'], 'w6' );
		$ctx['ticket'] = (int) tickets( $ctx['order']['order_id'] )[0]->ticket_id;
		$ctx['key'] = 'w6refund-' . uniqid( '', true );
		return $ctx;
	},
	static function ( $ctx ) { return Refunds::request( $ctx['order']['order_id'], array( $ctx['ticket'] ), 'w6', $ctx['key'] ); },
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$oid = (int) $ctx['order']['order_id'];
		$refunds = $wpdb->get_results( "SELECT status,provider_reference FROM {$p}wpcap_refunds WHERE order_id=$oid" );
		$order = Orders::get( $oid );
		$t = $wpdb->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ctx['ticket'] );
		$items = w6_count( "SELECT COALESCE(SUM(refunded_quantity),0) FROM {$p}wpcap_order_items WHERE order_id=$oid" );
		if ( count( $refunds ) > 1 ) { return 'duplicate refund rows'; }
		// Documented terminal state: the payout note could not be stored after the provider call, so the
		// refund stays "processing" without reference for a manual check (never re-paid automatically).
		$manual_check = $refunds && 'processing' === $refunds[0]->status && '' === (string) $refunds[0]->provider_reference;
		$completed = $refunds && 'completed' === $refunds[0]->status;
		$booked = 'revoked' === $t->status && 1 === (int) $t->refunded && 1190 === (int) $order->refunded_minor && 1 === $items;
		$untouched = 'valid' === $t->status && 0 === (int) $t->refunded && 0 === (int) $order->refunded_minor && 0 === $items;
		if ( $completed && ! $booked ) { return 'completed refund without booking'; }
		if ( ! $completed && ! $untouched ) { return 'half-booked refund (' . ( $refunds ? $refunds[0]->status : 'no row' ) . ')'; }
		if ( is_array( $r ) && 'completed' === ( $r['status'] ?? '' ) && ! $booked ) { return 'success without booking'; }
		if ( 'final' === $phase && ! ( $completed && $booked ) && ! ( $manual_check && $untouched ) ) { return 'not completed after retry (' . ( $refunds ? $refunds[0]->status : 'no row' ) . ')'; }
		return '';
	},
	static function ( $ctx ) { Refunds::reconcile(); }
);

w6_matrix(
	'external (WooCommerce/provider) refund booking',
	static function () use ( $w6_pending, $w6_refund_type ) {
		$ctx = $w6_pending( $w6_refund_type );
		Orders::confirm_external_payment( $ctx['order']['order_id'], 'w6' );
		$ctx['ticket'] = (int) tickets( $ctx['order']['order_id'] )[0]->ticket_id;
		$ctx['key'] = 'w6ext-' . uniqid( '', true );
		return $ctx;
	},
	static function ( $ctx ) { return Refunds::record_external( $ctx['order']['order_id'], array( $ctx['ticket'] ), 1190, 'w6', $ctx['key'], array( 'source' => 'woocommerce' ) ); },
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$oid = (int) $ctx['order']['order_id'];
		$refunds = $wpdb->get_results( "SELECT status FROM {$p}wpcap_refunds WHERE order_id=$oid" );
		$order = Orders::get( $oid );
		$t = $wpdb->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ctx['ticket'] );
		$items = w6_count( "SELECT COALESCE(SUM(refunded_quantity),0) FROM {$p}wpcap_order_items WHERE order_id=$oid" );
		if ( count( $refunds ) > 1 ) { return 'duplicate refund rows'; }
		$booked = 'revoked' === $t->status && 1 === (int) $t->refunded && 1190 === (int) $order->refunded_minor && 1 === $items;
		$untouched = 'valid' === $t->status && 0 === (int) $t->refunded && 0 === (int) $order->refunded_minor && 0 === $items;
		if ( $refunds && ! $booked ) { return 'refund row without (complete) booking: ticket ' . $t->status . '/' . $t->refunded . ', refunded_minor ' . $order->refunded_minor . ", items $items"; }
		if ( ! $refunds && ! $untouched ) { return 'booking without refund row'; }
		if ( is_array( $r ) && ! $booked ) { return 'success without booking'; }
		if ( 'final' === $phase && ! $booked ) { return 'not booked after retry'; }
		return '';
	}
);

/* ------------------------------------------------------------------------------------------ */
echo "W6 faults: check-in (Checkin::perform)\n";
/** One issued ticket of a free order; returns [type, order_id, code]. */
function w6_ticket( $type ) {
	wpcap_test_reset_transients();
	$o = Orders::create( array( 'email' => 'w6t' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w6t-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
	$codes = ticket_code_for( $o['order_id'] );
	global $wpdb;
	return array( 'type' => $type, 'order_id' => (int) $o['order_id'], 'code' => $codes[0] ?? '', 'ticket_id' => (int) $wpdb->get_var( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=" . (int) $o['order_id'] ) );
}
$w6_ci_type = make_type( 0, 10, 0 );
w6_matrix(
	'single-entry check-in',
	static function () use ( $w6_ci_type ) { return w6_ticket( $w6_ci_type ) + array( 'key' => 'w6ci-' . uniqid( '', true ) ); },
	static function ( $ctx ) { return Checkin::perform( $ctx['code'], array( 'idempotency_key' => $ctx['key'], 'source' => 'web', 'device_id' => 0 ) ); },
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$status = $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ctx['ticket_id'] );
		$logged = w6_count( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=" . (int) $ctx['ticket_id'] . " AND result='checked_in'" );
		if ( $logged > 1 ) { return "$logged admissions logged"; }
		if ( ( 'checked_in' === $status ) !== ( 1 === $logged ) ) { return "half state: ticket $status, $logged admission logs"; }
		if ( ! is_wp_error( $r ) && 'checked_in' !== $status ) { return 'success without persisted check-in'; }
		if ( 'final' === $phase && ( is_wp_error( $r ) || 'checked_in' !== $status ) ) { return 'retry did not check in: ' . w6_err( $r ); }
		return '';
	}
);

$w6_multi_type = make_type( 0, 10, 0 );
Policies::save( $w6_multi_type, array( 'policy' => 'multi_entry', 'max_entries' => '2' ) );
w6_matrix(
	'multi-entry check-in (2nd of max. 2 entries)',
	static function () use ( $w6_multi_type ) {
		$ctx = w6_ticket( $w6_multi_type ) + array( 'key' => 'w6mi-' . uniqid( '', true ) );
		Checkin::perform( $ctx['code'], array( 'idempotency_key' => 'first-' . $ctx['key'], 'source' => 'web' ) );
		return $ctx;
	},
	static function ( $ctx ) { return Checkin::perform( $ctx['code'], array( 'idempotency_key' => $ctx['key'], 'source' => 'web' ) ); },
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tid = (int) $ctx['ticket_id'];
		$logged = w6_count( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=$tid AND result='checked_in'" );
		$entries = w6_count( "SELECT COUNT(*) FROM {$p}wpcap_admission_entries WHERE ticket_id=$tid AND direction='in' AND undone=0" );
		$state = w6_count( "SELECT entries FROM {$p}wpcap_admission_state WHERE ticket_id=$tid" );
		if ( $logged !== $entries || $entries !== $state ) { return "inconsistent admission: $logged logs, $entries entries, state $state"; }
		if ( $entries > 2 ) { return "$entries entries exceed the limit"; }
		if ( ! is_wp_error( $r ) && 2 !== $entries ) { return 'success without persisted entry'; }
		if ( 'final' === $phase ) {
			if ( is_wp_error( $r ) || 2 !== $entries ) { return 'retry did not admit: ' . w6_err( $r ); }
			$third = Checkin::perform( $ctx['code'], array( 'idempotency_key' => 'third-' . $ctx['key'], 'source' => 'web' ) );
			if ( ! is_wp_error( $third ) || 'entry_limit_reached' !== ( $third->get_error_data()['result'] ?? '' ) ) { return 'third entry not refused'; }
		}
		return '';
	}
);

/* ------------------------------------------------------------------------------------------ */
echo "W6 faults: offline sync (/app/scan/sync)\n";
$w6_sync_type = make_type( 0, 10, 0 );
$w6_sync_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$w6_sync_type" );
$GLOBALS['wpcap_test_user_id'] = 1;
$w6_pairing = PassCaptain\Mobile\Devices::create_pairing( array( $w6_sync_event ), 'W6', 12 );
$w6_device = PassCaptain\Mobile\Devices::pair( $w6_pairing['code'], 'W6 device', 'ios', '1.0.0' );
$GLOBALS['wpcap_test_user_id'] = 1;
$w6_token = is_array( $w6_device ) ? (string) ( $w6_device['token'] ?? '' ) : '';
check( 'offline sync fixture: device paired', '' !== $w6_token );
w6_matrix(
	'offline sync upload',
	static function () use ( $w6_sync_type, $w6_token ) {
		$ctx = w6_ticket( $w6_sync_type ) + array( 'key' => 'w6sync-' . uniqid( '', true ) );
		$ctx['request'] = app_request( 'POST', $w6_token, array( 'scans' => array( array( 'code' => $ctx['code'], 'idempotency_key' => $ctx['key'], 'scanned_at' => gmdate( 'c', time() - 60 ), 'direction' => 'in' ) ) ) );
		PassCaptain\Mobile\Api::authenticate( $ctx['request'] ); // Device authenticated before the faults start.
		return $ctx;
	},
	static function ( $ctx ) {
		$res = PassCaptain\Mobile\Api::sync( $ctx['request'] );
		return $res instanceof WP_REST_Response ? $res->get_data() : $res;
	},
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$status = $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=" . (int) $ctx['ticket_id'] );
		if ( is_wp_error( $r ) ) { return 'final' === $phase ? 'request failed after retry' : ''; } // Whole request failed: the device keeps the scan.
		$row = $r['results'][0] ?? array();
		if ( ! empty( $row['ok'] ) && 'checked_in' !== $status ) { return 'scan acknowledged without persisted admission'; }
		// The app marks every returned row as synced unless it is flagged retryable: a transient
		// server error would silently drop an admission that happened offline.
		if ( empty( $row['ok'] ) && 'valid' === $status && empty( $row['retryable'] ) ) { return 'transient failure returned as final result (' . ( $row['result'] ?? '?' ) . ')'; }
		if ( 'final' === $phase && ( empty( $row['ok'] ) || 'checked_in' !== $status ) ) { return 'not admitted after retry'; }
		return '';
	}
);

/* ------------------------------------------------------------------------------------------ */
echo "W6 faults: box office sale\n";
$GLOBALS['wpcap_test_user_id'] = 4242;
$w6_session = W6Pos::open_session_for( 4242 );
if ( ! $w6_session || 'open' !== $w6_session->status ) { $w6_session = W6Pos::open_session( 0 ); }
$w6_pos_type = make_type( 0, 10, 1500 );
$w6_pos_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$w6_pos_type" );
w6_matrix(
	'box office cash sale',
	static function () use ( $w6_pos_type, $w6_pos_occ ) {
		$GLOBALS['wpcap_test_user_id'] = 4242;
		return array( 'type' => $w6_pos_type, 'occ' => $w6_pos_occ, 'key' => 'w6pos-' . bin2hex( random_bytes( 10 ) ) );
	},
	static function ( $ctx ) {
		$GLOBALS['wpcap_test_user_id'] = 4242;
		return W6Pos::sell( array( 'occurrence_id' => $ctx['occ'], 'quantities' => array( $ctx['type'] => 2 ), 'method' => 'cash', 'form_key' => $ctx['key'] ) );
	},
	static function ( $ctx, $r, $phase ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sales = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE form_key_hash=%s", hash( 'sha256', 'pos-sale|' . $ctx['key'] ) ) );
		$sale = $sales[0] ?? null;
		$order = $sale && $sale->order_id ? Orders::get( $sale->order_id ) : null;
		$tickets = $order ? count( Orders::tickets_for_order( $order->order_id ) ) : 0;
		$valid = $order ? count( tickets( $order->order_id, 'valid' ) ) : 0;
		if ( count( $sales ) > 1 ) { return 'duplicate sale rows'; }
		if ( $sale && 'failed' === $sale->status ) { return 'transient error marked the sale failed (order ' . ( $order ? $order->status : '-' ) . ')'; }
		if ( $order && ! in_array( $tickets, array( 0, 2 ), true ) ) { return "partial issuance ($tickets)"; }
		if ( $sale && 'completed' === $sale->status && ( ! $order || 'paid' !== $order->payment_status || 2 !== $valid || '' === (string) $sale->receipt_number ) ) { return 'completed sale without paid order, tickets or receipt'; }
		if ( is_array( $r ) && ( ! $sale || 'completed' !== $sale->status ) ) { return 'success without booked sale'; }
		if ( 'final' === $phase && ( ! is_array( $r ) || ! $sale || 'completed' !== $sale->status || 2 !== $valid ) ) { return 'not booked after retry: ' . w6_err( $r ) . ' sale ' . ( $sale ? $sale->status : 'none' ); }
		return '';
	},
	'w6_recover'
);
$GLOBALS['wpcap_test_user_id'] = 1;

// Leave nothing due for later queue tests.
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type IN ('ticket_issue','ticket_delivery') AND status='queued'" );
unset( $GLOBALS['wpcap_test_filters']['wpcap_webhook_claim_wait_ms'] );
