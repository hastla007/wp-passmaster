<?php
/**
 * Follow-ups of the audit fixes (2026-11-14):
 * - Orders::mark_refunded() errors after a refund are no longer ignored: native refunds, external
 *   refunds and the WooCommerce adapter log them, queue an `order_mark_refunded` retry job and the
 *   reconcile sweep marks such orders later.
 * - Refunds::record_external() checks every database write and rolls back on errors (retryable).
 * - WooCommerce: a retryable recording error queues a `woocommerce_refund_sync` job.
 * - Ticket delivery outbox: if completing the job fails after a successful mail, the retry does not
 *   send a second mail with rotated codes.
 * - Log helper and private temp files.
 *
 * Runs after zzz_audit_db_robustness.php and reuses its fault-injecting $wpdb proxy.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Jobs;
use PassCaptain\Log;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\TempFiles;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

global $wpdb;
$w7_real = $wpdb;
$p = $wpdb->prefix;
$w7_faults = static function () use ( $w7_real ) { $GLOBALS['wpdb'] = new Audit_Fault_WPDB( $w7_real ); return $GLOBALS['wpdb']; };
$w7_restore = static function () use ( $w7_real ) { $GLOBALS['wpdb'] = $w7_real; };
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
Refunds::register();
$w7_type = make_type( 500, 10, 1000 );
$w7_ticket_state = static function ( $ticket_id ) use ( $w7_real, $p ) {
	$t = $w7_real->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id );
	return $t->status . '/' . (int) $t->refunded;
};
$w7_jobs = static function ( $type, $like ) use ( $w7_real, $p ) {
	return $w7_real->get_results( $w7_real->prepare( "SELECT * FROM {$p}wpcap_jobs WHERE job_type=%s AND payload LIKE %s ORDER BY job_id", $type, $like ) );
};
$w7_audit = static function ( $action, $order_id ) use ( $w7_real, $p ) {
	return (int) $w7_real->get_var( $w7_real->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", $action, $order_id ) );
};

/* ------------------------------------------------------------------------------------------ */
echo "W7: record_external() checks its database writes\n";
$record_faults = array(
	'START TRANSACTION fails'      => array( '/^START TRANSACTION/', 0, 1, false ),
	'order lock fails'             => array( '/FROM \S+wpcap_orders WHERE order_id=\d+ FOR UPDATE/', 0, 1, false ),
	'ticket lock fails'            => array( '/FROM \S+wpcap_tickets WHERE .*\bticket_id IN .* FOR UPDATE/', 0, 1, false ),
	'refund insert fails'          => array( '/^INSERT INTO \S+wpcap_refunds/', 0, 1, false ),
	'order item update fails'      => array( '/UPDATE \S+wpcap_order_items SET refunded_quantity/', 0, 1, false ),
	'ticket revocation fails'      => array( "/SET status='revoked' WHERE ticket_id/", 0, 1, false ),
	'refunded flag fails'          => array( '/UPDATE \S+wpcap_tickets SET refunded=1/', 0, 1, false ),
	'order total update fails'     => array( '/UPDATE \S+wpcap_orders SET refunded_minor/', 0, 1, false ),
	'COMMIT fails'                 => array( '/^COMMIT/', 0, 1, false ),
);
foreach ( $record_faults as $label => $rule ) {
	$oid = admin_paid_order( $w7_type, 2, 'w7-ext@example.com' );
	$tids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $oid ) );
	$key = 'w7-ext-' . uniqid();
	$w7_faults()->fail( ...$rule );
	$res = Refunds::record_external( $oid, array( $tids[0] ), 1000, 'extern', $key );
	$w7_restore();
	$rows = Refunds::for_order( $oid );
	check( "record_external $label: retryable error, nothing recorded", is_wp_error( $res ) && 'wpcap_refund_record_failed' === $res->get_error_code() && ! empty( $res->get_error_data()['retryable'] ) && array() === $rows && 'valid/0' === $w7_ticket_state( $tids[0] ) && 0 === (int) Orders::get( $oid )->refunded_minor );
	$again = Refunds::record_external( $oid, array( $tids[0] ), 1000, 'extern', $key );
	$twice = Refunds::record_external( $oid, array( $tids[0] ), 1000, 'extern', $key );
	check( "record_external $label: repeating the same refund books it exactly once", is_array( $again ) && 'completed' === $again['status'] && (int) $again['refund_id'] === (int) $twice['refund_id'] && 1 === count( Refunds::for_order( $oid ) ) && 'revoked/1' === $w7_ticket_state( $tids[0] ) && 1000 === (int) Orders::get( $oid )->refunded_minor );
}
check( 'record_external errors are recorded in the audit log', $w7_audit( 'refund_db_error', $oid ) >= 1 );

/* ------------------------------------------------------------------------------------------ */
echo "W7: a failed order status update after a full refund is retried\n";
$mark_fault = array( "/^UPDATE \S+wpcap_orders SET status='refunded'/", 0, 1, false );

// External full refund: refund recorded, order status queued for retry, job fixes it.
$oid = admin_paid_order( $w7_type, 2, 'w7-full-ext@example.com' );
$tids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $oid ) );
$w7_faults()->fail( ...$mark_fault );
$res = Refunds::record_external( $oid, $tids, 2000, 'extern', 'w7-full-' . uniqid(), array( 'source' => 'test' ) );
$w7_restore();
$jobs = $w7_jobs( Refunds::ORDER_STATE_JOB, '{"order_id":' . $oid . ',%' );
check( 'external full refund: refund recorded although the order status update failed', is_array( $res ) && 'completed' === $res['status'] && 2000 === (int) Orders::get( $oid )->refunded_minor && 'paid' === Orders::get( $oid )->payment_status );
check( 'external full refund: retry job queued and problem logged', 1 === count( $jobs ) && 'queued' === $jobs[0]->status && $w7_audit( 'order_refund_state_pending', $oid ) >= 1 );
check( 'external full refund: order is listed as pending refund state (admin notice)', in_array( $oid, Refunds::orders_pending_refund_state( 100 ), true ) );
$w7_real->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_id=" . (int) $jobs[0]->job_id );
// The reconcile sweep in process_due() must not get there first for this check: run only the job handler path.
$handled = Refunds::handle_job( false, Refunds::ORDER_STATE_JOB, json_decode( $jobs[0]->payload, true ) );
check( 'external full refund: the retry job marks the order refunded', true === $handled && 'refunded' === Orders::get( $oid )->status && ! in_array( $oid, Refunds::orders_pending_refund_state( 100 ), true ) );
check( 'retry job is idempotent once the order is refunded', true === Refunds::handle_job( false, Refunds::ORDER_STATE_JOB, json_decode( $jobs[0]->payload, true ) ) );
check( 'other job types pass through the handler', 'x' === Refunds::handle_job( 'x', 'something_else', array() ) );
$w7_real->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=" . (int) $jobs[0]->job_id );

// Retry job failing again keeps the job queued (handler returns false).
$oid = admin_paid_order( $w7_type, 1, 'w7-retry@example.com' );
$tids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $oid ) );
$w7_faults()->fail( ...$mark_fault );
Refunds::record_external( $oid, $tids, 1000, 'extern', 'w7-retry-' . uniqid() );
$w7_restore();
$jobs = $w7_jobs( Refunds::ORDER_STATE_JOB, '{"order_id":' . $oid . ',%' );
$w7_faults()->fail( ...$mark_fault );
$handled = Refunds::handle_job( false, Refunds::ORDER_STATE_JOB, json_decode( $jobs[0]->payload, true ) );
$w7_restore();
check( 'retry job reports failure while the database still refuses (job queue backs off)', false === $handled && 'paid' === Orders::get( $oid )->payment_status );
// Even without the job (e.g. dead-lettered or never queued) the reconcile sweep marks it.
$w7_real->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='" . Refunds::ORDER_STATE_JOB . "' AND payload LIKE '{\"order_id\":" . $oid . ",%'" );
Refunds::reconcile();
check( 'reconcile() marks a fully refunded order refunded without a job', 'refunded' === Orders::get( $oid )->status && $w7_audit( 'order_refund_state_recovered', $oid ) >= 1 );

// Native full refund (execute → finalize): same handling.
$oid = admin_paid_order( $w7_type, 2, 'w7-native@example.com' );
$w7_faults()->fail( ...$mark_fault );
$res = Refunds::request( $oid, array(), 'Test', 'w7-native-' . uniqid() );
$w7_restore();
$jobs = $w7_jobs( Refunds::ORDER_STATE_JOB, '{"order_id":' . $oid . ',%' );
check( 'native full refund: refund completed, order status retry queued', is_array( $res ) && 'completed' === $res['status'] && 'paid' === Orders::get( $oid )->payment_status && 1 === count( $jobs ) );
$w7_real->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_id=" . (int) $jobs[0]->job_id );
Jobs::process_due( 50 );
check( 'native full refund: the job queue run marks the order refunded', 'refunded' === Orders::get( $oid )->status && 'complete' === $w7_real->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=" . (int) $jobs[0]->job_id ) );

/* ------------------------------------------------------------------------------------------ */
echo "W7: WooCommerce adapter handles refund recording errors\n";
add_filter( 'wp_pass_captain_process_job_v1', array( Woo::class, 'handle_job' ), 10, 3 );
$type = make_type( 10, 10, 2500 );
$wc = woo_t_order( 7701, array( array( $type, 2, '50.00' ) ) );
$core = Woo::reserve( $wc );
$wc->status = 'processing';
Woo::sync_paid_order( 7701 );
$refund = woo_t_refund( 7702, $wc, '25.00', array( array( 0, 1, 25 ) ) );
$w7_faults()->fail( '/^INSERT INTO \S+wpcap_refunds/', 0, 1, false );
$synced = Woo::sync_refund( 7701, 7702 );
$w7_restore();
$jobs = $w7_jobs( Woo::REFUND_SYNC_JOB, '{"wc_order_id":7701,%' );
check( 'Woo refund with a database error: nothing recorded, refund not flagged as processed', false === $synced && array() === Refunds::for_order( $core ) && ! $refund->get_meta( Woo::REFUND_META, true ) );
check( 'Woo refund with a database error: retry job queued and order note added', 1 === count( $jobs ) && (bool) array_filter( $wc->notes, static fn( $n ) => false !== strpos( $n, 'automatisch erneut versucht' ) ) );
$handled = apply_filters( 'wp_pass_captain_process_job_v1', false, Woo::REFUND_SYNC_JOB, json_decode( $jobs[0]->payload, true ), (int) $jobs[0]->job_id );
check( 'Woo refund retry job records the refund once', true === $handled && 1 === count( Refunds::for_order( $core ) ) && 2500 === (int) Orders::get( $core )->refunded_minor && $refund->get_meta( Woo::REFUND_META, true ) );
check( 'Woo refund retry job is idempotent', true === Woo::handle_job( false, Woo::REFUND_SYNC_JOB, json_decode( $jobs[0]->payload, true ) ) && 1 === count( Refunds::for_order( $core ) ) );
$w7_real->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=" . (int) $jobs[0]->job_id );

// Full refund where marking the core order refunded fails.
$final = woo_t_refund( 7703, $wc, '25.00', array( array( 0, 1, 25 ) ) );
$wc->status = 'refunded';
$w7_faults()->fail( ...$mark_fault )->fail( ...$mark_fault );
Woo::sync_fully_refunded_order( 7701 );
$w7_restore();
$jobs = $w7_jobs( Refunds::ORDER_STATE_JOB, '{"order_id":' . $core . ',%' );
check( 'Woo full refund with a database error: order note, retry job queued', 'paid' === Orders::get( $core )->payment_status && count( $jobs ) >= 1 && (bool) array_filter( $wc->notes, static fn( $n ) => false !== strpos( $n, 'automatisch wiederholt' ) ) );
Refunds::handle_job( false, Refunds::ORDER_STATE_JOB, json_decode( $jobs[0]->payload, true ) );
check( 'Woo full refund: retry marks the core order refunded', 'refunded' === Orders::get( $core )->status && 5000 === (int) Orders::get( $core )->refunded_minor );
$w7_real->query( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type='" . Refunds::ORDER_STATE_JOB . "' AND status='queued'" );
remove_filter( 'wp_pass_captain_process_job_v1', array( Woo::class, 'handle_job' ), 10 );

/* ------------------------------------------------------------------------------------------ */
echo "W7: no second ticket mail when completing the outbox job fails\n";
$mails_to = static function ( $to ) { return count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $to ) ); };
$email = 'w7-outbox-' . uniqid() . '@example.com';
wpcap_test_reset_transients();
$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $w7_type, 'quantity' => 2 ) ), 'idempotency_key' => 'w7-outbox-' . uniqid() ) );
$base = $mails_to( $email );
$w7_faults()->fail( "/^UPDATE \S+wpcap_jobs SET status='complete'/", 0, 10, false );
Orders::confirm_manual_payment( $r['order_id'] );
$w7_restore();
$jobs = $w7_jobs( Orders::DELIVERY_JOB, '{"order_id":' . (int) $r['order_id'] . ',%' );
$payload = json_decode( $jobs[0]->payload, true );
$marker = Orders::delivery_marker( (int) $r['order_id'], $payload['ticket_ids'] );
check( 'outbox: mail sent once, job still queued, delivered marker stored', $base + 1 === $mails_to( $email ) && 'queued' === $jobs[0]->status && false !== get_option( $marker ) );
$w7_real->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_id=" . (int) $jobs[0]->job_id );
$tokens_before = $w7_real->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $r['order_id'] . ' ORDER BY ticket_id' );
Jobs::process_due( 50 );
$tokens_after = $w7_real->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $r['order_id'] . ' ORDER BY ticket_id' );
check( 'outbox: the retry completes the job without a second mail and without rotating codes', $base + 1 === $mails_to( $email ) && 'complete' === $w7_real->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=" . (int) $jobs[0]->job_id ) && $tokens_before === $tokens_after );
check( 'outbox: marker removed and skip recorded in the audit log', false === get_option( $marker ) && $w7_audit( 'ticket_delivery_retry_skipped', (int) $r['order_id'] ) >= 1 );

// Normal path: job completes inline, no marker left behind.
$email = 'w7-outbox-ok-' . uniqid() . '@example.com';
$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $w7_type, 'quantity' => 1 ) ), 'idempotency_key' => 'w7-outbox-ok-' . uniqid() ) );
Orders::confirm_manual_payment( $r['order_id'] );
$jobs = $w7_jobs( Orders::DELIVERY_JOB, '{"order_id":' . (int) $r['order_id'] . ',%' );
$payload = json_decode( $jobs[0]->payload, true );
check( 'outbox: successful completion leaves no delivered marker', 'complete' === $jobs[0]->status && 1 === (int) $jobs[0]->attempts && false === get_option( Orders::delivery_marker( (int) $r['order_id'], $payload['ticket_ids'] ) ) );
// A transient deadlock on completion is retried inline.
$email = 'w7-outbox-retry-' . uniqid() . '@example.com';
$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $w7_type, 'quantity' => 1 ) ), 'idempotency_key' => 'w7-outbox-retry-' . uniqid() ) );
$base = $mails_to( $email );
$w7_faults()->fail( "/^UPDATE \S+wpcap_jobs SET status='complete'/", 0, 2, false );
Orders::confirm_manual_payment( $r['order_id'] );
$w7_restore();
$jobs = $w7_jobs( Orders::DELIVERY_JOB, '{"order_id":' . (int) $r['order_id'] . ',%' );
check( 'outbox: a briefly failing completion is retried and succeeds', 'complete' === $jobs[0]->status && $base + 1 === $mails_to( $email ) );

/* ------------------------------------------------------------------------------------------ */
echo "W7: logger and private temp files\n";
$logged = array();
$log_cb = static function ( $level, $event, $message, $context ) use ( &$logged ) { $logged[] = array( $level, $event, $message ); };
add_action( 'wpcap_log', $log_cb, 10, 4 );
Log::error( 'w7_test_event', 'something failed', array( 'object_type' => 'order', 'object_id' => 987654, 'details' => array( 'k' => 'v' ) ) );
remove_action( 'wpcap_log', $log_cb, 10 );
check( 'Log::error fires wpcap_log', array( array( 'error', 'w7_test_event', 'something failed' ) ) === $logged );
check( 'Log::error records to the audit table', 1 === $w7_audit( 'w7_test_event', 987654 ) );
check( 'PHP error log is off unless WP_DEBUG_LOG or the filter enables it', false === Log::to_error_log( 'error', 'x' ) );
$on = static function () { return true; };
add_filter( 'wpcap_log_to_error_log', $on );
check( 'filter wpcap_log_to_error_log enables the PHP error log', true === Log::to_error_log( 'error', 'x' ) );
remove_filter( 'wpcap_log_to_error_log', $on );
Log::warning( 'w7_no_audit', 'quiet', array( 'audit' => false, 'object_id' => 987654 ) );
check( 'audit => false skips the audit table', 0 === $w7_audit( 'w7_no_audit', 987654 ) );

$dir = TempFiles::private_dir();
$file = $dir . '/secret.txt';
$written = TempFiles::put( $file, 'code-123' );
check( 'TempFiles: private dir 0700 and file 0600 with content', null !== $dir && $written && 0700 === ( fileperms( $dir ) & 0777 ) && 0600 === ( fileperms( $file ) & 0777 ) && 'code-123' === TempFiles::get( $file ) );
TempFiles::remove_with_dir( $file, $dir );
check( 'TempFiles: file and directory removed', ! file_exists( $file ) && ! is_dir( $dir ) );
