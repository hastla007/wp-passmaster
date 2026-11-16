<?php
/**
 * A188: background worker failures and restarts – backoff timing, dead letter after the last attempt
 * (visible to the admin), a crashing handler, two workers racing for the same jobs, a worker that dies
 * after the side effect but before the job is marked complete, and restart after a crash (stale
 * claims). Child workers: tests/integration/job_worker.php.
 *
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Jobs;
use PassCaptain\Jobs\TimeBudget;
use PassCaptain\Operations\Queues;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
$jt = "{$p}wpcap_jobs";
$GLOBALS['wpcap_test_transients'] = array();
// Only the jobs of this file are due while it runs (restored at the end).
$wpdb->query( "UPDATE $jt SET status='a188_parked' WHERE status IN ('queued','running')" );
$a188_orders_handler = array( Orders::class, 'handle_job' );
add_filter( 'wpcap_process_job', $a188_orders_handler, 10, 4 );

$a188_job = static function ( $key ) use ( $wpdb, $jt ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $jt WHERE idempotency_key=%s", hash( 'sha256', $key ) ) );
};
$a188_due_now = static function ( $key ) use ( $wpdb, $jt ) {
	$wpdb->query( $wpdb->prepare( "UPDATE $jt SET run_after=%s WHERE idempotency_key=%s", gmdate( 'Y-m-d H:i:s', time() - 5 ), hash( 'sha256', $key ) ) );
};
$a188_in = static fn( $row, $seconds ) => abs( strtotime( $row->run_after . ' UTC' ) - ( time() + $seconds ) ) <= 5;
$a188_audits = static fn( $action, $object_id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", $action, $object_id ) );

echo "A188: backoff timing and dead letter after the last attempt\n";
check( 'A188: backoff doubles from 2 minutes (2, 4, 8 … 256 min for the 8 runs)', array( 120, 240, 480, 960, 1920, 3840, 7680, 15360 ) === array_map( array( Jobs::class, 'backoff_seconds' ), range( 1, 8 ) ) && Jobs::backoff_seconds( 30 ) <= DAY_IN_SECONDS && 8 === Jobs::MAX_ATTEMPTS );
$a188_dead_hook = array();
add_action( 'wpcap_job_dead_letter', static function ( $job_id, $type, $error ) use ( &$a188_dead_hook ) { $a188_dead_hook[] = array( $job_id, $type, $error ); }, 10, 3 );
$a188_fail = static function ( $handled, $type ) {
	return 'a188_fail' === $type ? new WP_Error( 'a188_boom', 'Zustellung an kunde@example.com gescheitert' ) : $handled;
};
add_filter( 'wpcap_process_job', $a188_fail, 10, 2 );
Jobs::enqueue( 'a188-fail', 'a188_fail', array( 'x' => 1 ), gmdate( 'Y-m-d H:i:s', time() - 5 ) );
$a188_timing = true;
for ( $attempt = 1; $attempt < Jobs::MAX_ATTEMPTS; $attempt++ ) {
	Jobs::process_due( 5 );
	$row = $a188_job( 'a188-fail' );
	$a188_timing = $a188_timing && 'queued' === $row->status && $attempt === (int) $row->attempts && $a188_in( $row, Jobs::backoff_seconds( $attempt ) );
	if ( 1 === $attempt ) {
		check( 'A188: a failed run is queued again with the handler error (masked) and no lock', 'queued' === $row->status && null === $row->locked_at && false !== strpos( (string) $row->last_error, 'a188_boom' ) && false === strpos( (string) $row->last_error, 'kunde@example.com' ) );
		check( 'A188: not run again before its backoff has passed', 0 === Jobs::process_due( 5 ) && 1 === (int) $a188_job( 'a188-fail' )->attempts );
	}
	$a188_due_now( 'a188-fail' );
}
check( 'A188: run_after follows the backoff after every failed run (attempts 1–7)', $a188_timing );
Jobs::process_due( 5 );
$a188_dead = $a188_job( 'a188-fail' );
check( 'A188: the 8th failed run makes the job a dead letter', 'dead_letter' === $a188_dead->status && 8 === (int) $a188_dead->attempts );
check( 'A188: the dead letter is audited and announced (wpcap_job_dead_letter)', 1 === $a188_audits( 'job_dead_letter', (int) $a188_dead->job_id ) && array( (int) $a188_dead->job_id, 'a188_fail' ) === array_slice( $a188_dead_hook[0] ?? array(), 0, 2 ) );
$a188_listed = array_filter( Queues::failed_items( 'jobs', 500 )['rows'], static fn( $item ) => (int) $item['id'] === (int) $a188_dead->job_id && false !== strpos( $item['last_error'], 'a188_boom' ) );
check( 'A188: the dead letter is listed for the admin (operations page) and counted (Site Health)', 1 === count( $a188_listed ) && Queues::stats( 'jobs' )['failed'] >= 1 );
$a188_dead_runs = (int) $a188_dead->attempts;
$a188_due_now( 'a188-fail' );
Jobs::process_due( 5 );
check( 'A188: a dead letter is never run again by the worker', 'dead_letter' === $a188_job( 'a188-fail' )->status && $a188_dead_runs === (int) $a188_job( 'a188-fail' )->attempts );
remove_filter( 'wpcap_process_job', $a188_fail, 10 );

echo "A188: a crashing handler is a failed run, not a stuck job\n";
$a188_throw = static function ( $handled, $type ) {
	if ( 'a188_throw' === $type ) { throw new RuntimeException( 'Handler abgestürzt' ); }
	return $handled;
};
add_filter( 'wpcap_process_job', $a188_throw, 10, 2 );
Jobs::enqueue( 'a188-throw', 'a188_throw', array(), gmdate( 'Y-m-d H:i:s', time() - 5 ) );
$a188_n = Jobs::process_due( 5 );
$row = $a188_job( 'a188-throw' );
check( 'A188: exception → queued with backoff and the exception as error, no claim left open', 1 === $a188_n && 'queued' === $row->status && 1 === (int) $row->attempts && $a188_in( $row, Jobs::backoff_seconds( 1 ) ) && false !== strpos( (string) $row->last_error, 'RuntimeException: Handler abgestürzt' ) && 0 === TimeBudget::open_claims() );
remove_filter( 'wpcap_process_job', $a188_throw, 10 );
$wpdb->query( "DELETE FROM $jt WHERE job_type IN ('a188_fail','a188_throw')" );

/** Runs $workers child workers in parallel; returns their outputs. */
function a188_workers( $mode, $workers, $limit = 50 ) {
	$procs = array();
	for ( $i = 0; $i < $workers; $i++ ) {
		$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/job_worker.php' ) . ' ' . escapeshellarg( $mode ) . ' ' . (int) $limit . ' 2>&1', 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	return $out;
}

echo "A188: two (four) workers race for the same jobs\n";
$wpdb->query( "DELETE FROM {$p}wpcap_audit_logs WHERE action='a188_ran'" );
for ( $n = 1; $n <= 30; $n++ ) { Jobs::enqueue( "a188-race-$n", 'a188_race', array( 'n' => $n ), gmdate( 'Y-m-d H:i:s', time() - 5 ) ); }
$a188_out = a188_workers( 'race', 4 );
$a188_runs = $wpdb->get_results( "SELECT object_id,COUNT(*) AS n,COUNT(DISTINCT details) AS workers FROM {$p}wpcap_audit_logs WHERE action='a188_ran' GROUP BY object_id" );
$a188_twice = array_filter( $a188_runs, static fn( $r ) => 1 !== (int) $r->n );
$a188_pids = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT details) FROM {$p}wpcap_audit_logs WHERE action='a188_ran'" );
check( 'A188: every job ran exactly once although 4 workers claimed in parallel (' . implode( '+', $a188_out ) . " processed, $a188_pids workers did work)", 30 === count( $a188_runs ) && ! $a188_twice && 30 === array_sum( array_map( 'intval', $a188_out ) ) );
check( 'A188: all raced jobs are complete, none left running or locked', 30 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $jt WHERE job_type='a188_race' AND status='complete' AND locked_at IS NULL" ) );
$wpdb->query( "DELETE FROM $jt WHERE job_type='a188_race'" );

echo "A188: a worker dies after the side effect, before the job is marked complete\n";
$wpdb->query( "DELETE FROM {$p}wpcap_audit_logs WHERE action='a188_ran'" );
Jobs::enqueue( 'a188-die', 'a188_race', array( 'n' => 1 ), gmdate( 'Y-m-d H:i:s', time() - 5 ) );
a188_workers( 'die', 1 );
$row = $a188_job( 'a188-die' );
check( 'A188: the dead worker\'s claim is released on shutdown with backoff and a note (not left running)', 'queued' === $row->status && null === $row->locked_at && 1 === (int) $row->attempts && $a188_in( $row, Jobs::backoff_seconds( 1 ) ) && false !== strpos( (string) $row->last_error, 'Worker' ) );
$a188_race_handler = static function ( $handled, $type, $payload ) {
	if ( 'a188_race' !== $type ) { return $handled; }
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'wpcap_audit_logs', array( 'user_id' => 0, 'action' => 'a188_ran', 'object_type' => 'job', 'object_id' => (int) $payload['n'], 'details' => 'parent', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	return true;
};
add_filter( 'wpcap_process_job', $a188_race_handler, 10, 3 );
$a188_due_now( 'a188-die' );
Jobs::process_due( 5 );
check( 'A188: at-least-once: a handler without its own check runs again after the restart (documented)', 2 === $a188_audits( 'a188_ran', 1 ) && 'complete' === $a188_job( 'a188-die' )->status );
remove_filter( 'wpcap_process_job', $a188_race_handler, 10 );

// The built-in ticket mail retry detects the repeat: no second mail, the mailed codes stay valid.
$a188_type = make_type( 10, 10, 0 );
$a188_order = Orders::create( array( 'email' => 'a188-mail@example.com', 'items' => array( array( 'ticket_type_id' => $a188_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a188-mail-' . uniqid() ) );
$a188_oid = (int) $a188_order['order_id'];
Jobs::enqueue( 'a188-mail', 'ticket_mail_retry', array( 'order_id' => $a188_oid ), gmdate( 'Y-m-d H:i:s', time() - 5 ) );
a188_workers( 'die', 1 );
$a188_hash = $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=$a188_oid" );
$row = $a188_job( 'a188-mail' );
check( 'A188: the dying worker sent the ticket mail (resend audited) and left the job queued', 1 === $a188_audits( 'tickets_resent', $a188_oid ) && 'queued' === $row->status );
$a188_mails = count( $GLOBALS['wpcap_test_mails'] );
$a188_due_now( 'a188-mail' );
Jobs::process_due( 5 );
check( 'A188: the restarted job sends no second mail and keeps the mailed codes valid', 1 === $a188_audits( 'tickets_resent', $a188_oid ) && $a188_mails === count( $GLOBALS['wpcap_test_mails'] ) && $a188_hash === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=$a188_oid" ) && 'complete' === $a188_job( 'a188-mail' )->status );

echo "A188: restart after a crash (stale claims)\n";
$a188_counted = static function ( $handled, $type, $payload ) {
	if ( 'a188_stale' !== $type ) { return $handled; }
	$GLOBALS['a188_stale_runs'] = ( $GLOBALS['a188_stale_runs'] ?? 0 ) + 1;
	return true;
};
add_filter( 'wpcap_process_job', $a188_counted, 10, 3 );
$GLOBALS['a188_stale_runs'] = 0;
Jobs::enqueue( 'a188-stale', 'a188_stale', array(), gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
$wpdb->query( $wpdb->prepare( "UPDATE $jt SET status='running',attempts=3,locked_at=%s WHERE idempotency_key=%s", gmdate( 'Y-m-d H:i:s', time() - 10 * 60 ), hash( 'sha256', 'a188-stale' ) ) );
Jobs::process_due( 5 );
check( 'A188: a claim younger than 15 minutes is left alone (the worker may still run)', 'running' === $a188_job( 'a188-stale' )->status && 0 === $GLOBALS['a188_stale_runs'] );
$wpdb->query( $wpdb->prepare( "UPDATE $jt SET locked_at=%s WHERE idempotency_key=%s", gmdate( 'Y-m-d H:i:s', time() - 16 * 60 ), hash( 'sha256', 'a188-stale' ) ) );
Jobs::process_due( 5 );
$row = $a188_job( 'a188-stale' );
check( 'A188: after 15 minutes the claim is taken back with the backoff of a failed run and a note', 'queued' === $row->status && null === $row->locked_at && $a188_in( $row, Jobs::backoff_seconds( 3 ) ) && false !== strpos( (string) $row->last_error, 'zurückgeholt' ) && 0 === $GLOBALS['a188_stale_runs'] );
$a188_due_now( 'a188-stale' );
Jobs::process_due( 5 );
check( 'A188: the restarted job runs once and completes', 1 === $GLOBALS['a188_stale_runs'] && 'complete' === $a188_job( 'a188-stale' )->status && 4 === (int) $a188_job( 'a188-stale' )->attempts );
Jobs::enqueue( 'a188-crashloop', 'a188_stale', array(), gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
$wpdb->query( $wpdb->prepare( "UPDATE $jt SET status='running',attempts=%d,locked_at=%s WHERE idempotency_key=%s", Jobs::MAX_ATTEMPTS, gmdate( 'Y-m-d H:i:s', time() - 16 * 60 ), hash( 'sha256', 'a188-crashloop' ) ) );
Jobs::process_due( 5 );
$a188_due_now( 'a188-crashloop' );
Jobs::process_due( 5 );
$row = $a188_job( 'a188-crashloop' );
check( 'A188: a job whose last allowed run crashed becomes a dead letter instead of running again', 'dead_letter' === $row->status && 1 === $GLOBALS['a188_stale_runs'] && 1 === $a188_audits( 'job_dead_letter', (int) $row->job_id ) );
remove_filter( 'wpcap_process_job', $a188_counted, 10 );

$wpdb->query( "DELETE FROM $jt WHERE job_type IN ('a188_race','a188_stale','ticket_mail_retry') AND idempotency_key IN ('" . implode( "','", array_map( static fn( $k ) => hash( 'sha256', $k ), array( 'a188-die', 'a188-mail', 'a188-stale', 'a188-crashloop' ) ) ) . "')" );
$wpdb->query( "DELETE FROM {$p}wpcap_audit_logs WHERE action='a188_ran'" );
$wpdb->query( "UPDATE $jt SET status='queued' WHERE status='a188_parked'" );
remove_filter( 'wpcap_process_job', $a188_orders_handler, 10 );
