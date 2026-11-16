<?php
/**
 * Worker failure and restart scenarios (W6) for the three queues: jobs (Jobs), e-mail queue
 * (Communications) and outgoing webhooks (Webhooks\Dispatcher).
 *
 *  - expired claims (worker killed hard, no shutdown handler): re-queued after 15 minutes and
 *    processed exactly once;
 *  - poison items (kill the worker every time): attempts are counted at claim time, so stale or
 *    released claims end in the dead-letter/failed state instead of looping forever;
 *  - worker dies inside the handler (exit = fatal error/time limit): the shutdown handler hands the
 *    claim back, the next run finishes it;
 *  - retry backoff and dead-letter after the last attempt;
 *  - duplicate enqueue / emit is a no-op; durable claim before the side effect (fault injection).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fault_wpdb.php';

use PassCaptain\Jobs;
use PassCaptain\Communications;
use PassCaptain\Webhooks\Dispatcher;

global $wpdb;
$p = $wpdb->prefix;

// HTTP goes through Security\OutboundHttp and the scripted wp_remote_request() of payments.php.
/** Requests sent for a webhook event id. */
function w6_sent( $event_id ) { return count( array_filter( $GLOBALS['wpcap_test_http_requests'], static fn( $r ) => ( $r['headers']['X-WPCAP-Event-Id'] ?? '' ) === $event_id ) ); }
/** Scripted receiver answers for the next requests to the test hosts. */
function w6_answer( $code, $times = 1 ) { http_reset(); for ( $i = 0; $i < $times; $i++ ) { http_push( array(), $code, 'hooks.example' ); } }
http_reset();

/** Runs the crash worker child process and returns its output. */
function w6_child( $mode ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/w6_crash_worker.php' ) . ' ' . escapeshellarg( $mode ) . ' 2>&1';
	return (string) shell_exec( $cmd );
}
function w6_job( $key ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $key ) ) ); }
function w6_msg( $to ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_message_queue WHERE recipient=%s", $to ) ); }
function w6_last( array $rows ) { return $rows ? $rows[ count( $rows ) - 1 ] : null; }
function w6_ago( $minutes ) { return gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 ); }

$w6_runs = array();
$w6_handler = static function ( $handled, $type, $payload, $job_id ) use ( &$w6_runs ) {
	if ( 0 !== strpos( (string) $type, 'w6_' ) ) { return $handled; }
	$w6_runs[] = (int) $job_id;
	return 'w6_fail' === $type ? false : true;
};
add_filter( 'wp_pass_captain_process_job_v1', $w6_handler, 10, 4 );

/* ------------------------------------------------------------------------------------------ */
echo "W6 workers: jobs\n";
$k = 'w6-dup-' . uniqid();
Jobs::enqueue( $k, 'w6_ok', array( 'n' => 1 ), '2000-01-01 00:00:00' );
Jobs::enqueue( $k, 'w6_ok', array( 'n' => 2 ), '2000-01-01 00:00:00' );
check( 'duplicate enqueue keeps one job with the first payload', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $k ) ) ) && '{"n":1}' === w6_job( $k )->payload );
$w6_runs = array();
Jobs::process_due( 200 );
Jobs::process_due( 200 );
check( 'job handled exactly once across two worker runs', array( (int) w6_job( $k )->job_id ) === array_values( array_intersect( $w6_runs, array( (int) w6_job( $k )->job_id ) ) ) && 'complete' === w6_job( $k )->status );

// Hard-killed worker (no shutdown handler): the claim expires and is taken over once.
$k = 'w6-stale-' . uniqid();
Jobs::enqueue( $k, 'w6_ok', array(), '2000-01-01 00:00:00' );
$wpdb->update( "{$p}wpcap_jobs", array( 'status' => 'running', 'attempts' => 1, 'locked_at' => w6_ago( 5 ) ), array( 'idempotency_key' => hash( 'sha256', $k ) ) );
$w6_runs = array();
Jobs::process_due( 200 );
check( 'fresh claim of another worker (5 min) is not taken over', 'running' === w6_job( $k )->status && ! in_array( (int) w6_job( $k )->job_id, $w6_runs, true ) );
$wpdb->update( "{$p}wpcap_jobs", array( 'locked_at' => w6_ago( 16 ) ), array( 'idempotency_key' => hash( 'sha256', $k ) ) );
Jobs::process_due( 200 );
Jobs::process_due( 200 );
$job = w6_job( $k );
check( 'expired claim (16 min) is re-queued and resumed exactly once', 'complete' === $job->status && 2 === (int) $job->attempts && 1 === count( array_keys( $w6_runs, (int) $job->job_id, true ) ) );

// Poison job: killed the worker on every attempt so far.
$k = 'w6-poison-' . uniqid();
Jobs::enqueue( $k, 'w6_ok', array(), '2000-01-01 00:00:00' );
$wpdb->update( "{$p}wpcap_jobs", array( 'status' => 'running', 'attempts' => Jobs::MAX_ATTEMPTS, 'locked_at' => w6_ago( 20 ) ), array( 'idempotency_key' => hash( 'sha256', $k ) ) );
$w6_runs = array();
Jobs::process_due( 200 );
$job = w6_job( $k );
check( 'stale claim with exhausted attempts is dead-lettered, not run again', 'dead_letter' === $job->status && ! in_array( (int) $job->job_id, $w6_runs, true ) && false !== strpos( (string) $job->last_error, 'attempts exhausted' ) );

// Worker dies inside the handler (child process): the shutdown handler hands the claim back.
$k = 'w6-crash-' . uniqid();
Jobs::enqueue( $k, 'w6_crash', array(), '2000-01-01 00:00:00' );
$out = w6_child( 'job' );
$job = w6_job( $k );
check( 'worker exits inside the handler: claim released to the queue on shutdown (attempt counted)', false !== strpos( $out, 'CRASH' ) && false === strpos( $out, 'DONE' ) && 'queued' === $job->status && 1 === (int) $job->attempts && null === $job->locked_at );
check( 'released crash claim waits a backoff and carries a last error (R3: no poison job at the head of the queue)', strtotime( $job->run_after . ' UTC' ) > time() + 30 && '' !== (string) $job->last_error );
$wpdb->update( "{$p}wpcap_jobs", array( 'job_type' => 'w6_ok', 'run_after' => '2000-01-01 00:00:00' ), array( 'job_id' => (int) $job->job_id ) ); // "Bug fixed" and backoff over: the next run succeeds.
$w6_runs = array();
Jobs::process_due( 200 );
check( 'next worker run resumes and completes it once', 'complete' === w6_job( $k )->status && array( (int) $job->job_id ) === array_values( array_intersect( $w6_runs, array( (int) $job->job_id ) ) ) );
// Crash on the last allowed attempt: released as dead letter.
$k = 'w6-crash-last-' . uniqid();
Jobs::enqueue( $k, 'w6_crash', array(), '2000-01-01 00:00:00' );
$wpdb->update( "{$p}wpcap_jobs", array( 'attempts' => Jobs::MAX_ATTEMPTS - 1 ), array( 'idempotency_key' => hash( 'sha256', $k ) ) );
w6_child( 'job' );
check( 'crash on the last attempt dead-letters the job (no endless crash loop)', 'dead_letter' === w6_job( $k )->status && Jobs::MAX_ATTEMPTS === (int) w6_job( $k )->attempts );

// Retry backoff and dead letter for a handler that keeps failing.
$k = 'w6-fail-' . uniqid();
Jobs::enqueue( $k, 'w6_fail', array(), '2000-01-01 00:00:00' );
$delays = array();
for ( $i = 1; $i <= Jobs::MAX_ATTEMPTS; $i++ ) {
	Jobs::process_due( 200 );
	$job = w6_job( $k );
	$delays[] = strtotime( $job->run_after . ' UTC' ) - time();
	if ( 'dead_letter' !== $job->status ) { $wpdb->update( "{$p}wpcap_jobs", array( 'run_after' => '2000-01-01 00:00:00' ), array( 'job_id' => (int) $job->job_id ) ); }
}
$job = w6_job( $k );
$growing = true;
for ( $i = 1; $i < count( $delays ) - 1; $i++ ) { if ( $delays[ $i ] < $delays[ $i - 1 ] ) { $growing = false; } }
check( 'failing job backs off exponentially (2, 4, 8 ... min) and dead-letters after ' . Jobs::MAX_ATTEMPTS . ' attempts', $growing && abs( $delays[0] - 120 ) <= 2 && abs( $delays[1] - 240 ) <= 2 && 'dead_letter' === $job->status && Jobs::MAX_ATTEMPTS === (int) $job->attempts );
$w6_runs = array();
Jobs::process_due( 200 );
check( 'dead-lettered job is never picked up again', ! in_array( (int) $job->job_id, $w6_runs, true ) );

/* ------------------------------------------------------------------------------------------ */
echo "W6 workers: e-mail queue\n";
$to = 'w6-stale-' . uniqid() . '@example.com';
Communications::enqueue( 'w6-' . $to, $to, 'w6_mail', array( 'subject' => 'W6', 'body' => 'Hallo' ), '2000-01-01 00:00:00' );
$wpdb->update( "{$p}wpcap_message_queue", array( 'status' => 'sending', 'attempts' => 1, 'locked_at' => w6_ago( 16 ) ), array( 'recipient' => $to ) );
Communications::process_due();
Communications::process_due();
$sent = count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $to ) );
check( 'expired sending claim is resumed and the mail sent exactly once', 1 === $sent && 'sent' === w6_msg( $to )->status );
$to = 'w6-poison-' . uniqid() . '@example.com';
Communications::enqueue( 'w6-' . $to, $to, 'w6_mail', array( 'subject' => 'W6', 'body' => 'Hallo' ), '2000-01-01 00:00:00' );
$wpdb->update( "{$p}wpcap_message_queue", array( 'status' => 'sending', 'attempts' => Communications::MAX_ATTEMPTS, 'locked_at' => w6_ago( 16 ) ), array( 'recipient' => $to ) );
Communications::process_due();
check( 'stale message with exhausted attempts fails instead of being re-sent forever', 'failed' === w6_msg( $to )->status && ! array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $to ) );
$to = 'w6-crash-' . uniqid() . '@example.com';
Communications::enqueue( 'w6-' . $to, $to, 'w6_crash', array( 'subject' => 'W6', 'body' => 'Hallo' ), '2000-01-01 00:00:00' );
$out = w6_child( 'message' );
check( 'mail worker exits while rendering: message back in the queue, attempt counted', false !== strpos( $out, 'CRASH' ) && 'queued' === w6_msg( $to )->status && 1 === (int) w6_msg( $to )->attempts );
check( 'released mail claim waits a backoff (send_at in the future) with a last error', strtotime( w6_msg( $to )->send_at . ' UTC' ) > time() + 30 && '' !== (string) w6_msg( $to )->last_error );
$wpdb->update( "{$p}wpcap_message_queue", array( 'template_key' => 'w6_mail', 'send_at' => '2000-01-01 00:00:00' ), array( 'recipient' => $to ) );
Communications::process_due();
check( 'next run sends it once', 'sent' === w6_msg( $to )->status && 1 === count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $to ) ) );
$to = 'w6-bounce-' . uniqid() . '@example.com';
Communications::enqueue( 'w6-' . $to, $to, 'w6_mail', array( 'subject' => 'W6', 'body' => 'Hallo' ), '2000-01-01 00:00:00' );
$GLOBALS['wpcap_test_filters']['wpcap_mail'] = false; // Transport fails.
$delays = array();
for ( $i = 1; $i <= Communications::MAX_ATTEMPTS; $i++ ) {
	Communications::process_due();
	$m = w6_msg( $to );
	$delays[] = strtotime( $m->send_at . ' UTC' ) - time();
	if ( 'failed' !== $m->status ) { $wpdb->update( "{$p}wpcap_message_queue", array( 'send_at' => '2000-01-01 00:00:00' ), array( 'recipient' => $to ) ); }
}
unset( $GLOBALS['wpcap_test_filters']['wpcap_mail'] );
check( 'mail transport failures back off (2, 4, 8 ... min) and fail after ' . Communications::MAX_ATTEMPTS . ' attempts', abs( $delays[0] - 120 ) <= 2 && abs( $delays[1] - 240 ) <= 2 && 'failed' === w6_msg( $to )->status && Communications::MAX_ATTEMPTS === (int) w6_msg( $to )->attempts );

/* ------------------------------------------------------------------------------------------ */
echo "W6 workers: outgoing webhooks\n";
PassCaptain\Modules\Webhooks::register();
$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='disabled'" ); // Only this test's subscriptions.
$w6_sub = Dispatcher::create_subscription( 'https://w6.hooks.example/in', array( 'order.paid' ) );
$sid = (int) $w6_sub['subscription_id'];
/** Delivery rows of the test subscription. */
$w6_deliveries = static function ( $sid ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_webhook_deliveries WHERE subscription_id=%d ORDER BY delivery_id", $sid ) ); };
$dedupe = 'w6-' . uniqid();
$q1 = Dispatcher::emit( 'order.paid', $dedupe, array( 'object' => 'order', 'id' => 'w6' ) );
$q2 = Dispatcher::emit( 'order.paid', $dedupe, array( 'object' => 'order', 'id' => 'w6' ) );
check( 'emitting the same event twice queues one delivery', 1 === $q1 && 0 === $q2 && 1 === count( $w6_deliveries( $sid ) ) );
$d = $w6_deliveries( $sid )[0];

// Durable claim: no HTTP request unless the claim committed.
$claim_faults = array(
	'START TRANSACTION fails'  => array( '/^START TRANSACTION/', 'error' ),
	'claim SELECT deadlocks'   => array( '/wpcap_webhook_deliveries d INNER JOIN .* FOR UPDATE/', 'deadlock' ),
	'claim UPDATE lock wait'   => array( "/^UPDATE \S+wpcap_webhook_deliveries SET status='delivering'/", 'lock_wait' ),
	'claim UPDATE 0 rows'      => array( "/^UPDATE \S+wpcap_webhook_deliveries SET status='delivering'/", 'silent' ),
	'COMMIT of the claim lost' => array( '/^COMMIT/', 'gone' ),
);
foreach ( $claim_faults as $label => list( $pattern, $kind ) ) {
	w6_answer( 200, 5 );
	$f = wpcap_faults();
	$f->fail( $pattern, 0, 'silent' === $kind ? 1000 : 1, $kind );
	Dispatcher::process( 5 );
	wpcap_faults_restore();
	$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $d->delivery_id );
	check( "webhook $label: nothing sent, delivery stays pending without attempt", 0 === w6_sent( $d->event_id ) && 'pending' === $row->status && 0 === (int) $row->attempts );
}
w6_answer( 200, 5 );
Dispatcher::process( 5 );
Dispatcher::process( 5 );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $d->delivery_id );
check( 'next run delivers it exactly once', 1 === w6_sent( $d->event_id ) && 'succeeded' === $row->status && 1 === (int) $row->attempts );

// Expired and poison claims.
Dispatcher::emit( 'order.paid', 'w6-stale-' . uniqid(), array( 'object' => 'order', 'id' => 'w6s' ) );
$stale = w6_last( $w6_deliveries( $sid ) );
$wpdb->update( "{$p}wpcap_webhook_deliveries", array( 'status' => 'delivering', 'attempts' => 1, 'locked_at' => w6_ago( 16 ) ), array( 'delivery_id' => (int) $stale->delivery_id ) );
w6_answer( 200, 5 );
Dispatcher::process( 5 );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $stale->delivery_id );
check( 'expired delivering claim is resumed and delivered once (attempts 2)', 'succeeded' === $row->status && 2 === (int) $row->attempts && 1 === w6_sent( $stale->event_id ) );
Dispatcher::emit( 'order.paid', 'w6-poison-' . uniqid(), array( 'object' => 'order', 'id' => 'w6p' ) );
$poison = w6_last( $w6_deliveries( $sid ) );
$wpdb->update( "{$p}wpcap_webhook_deliveries", array( 'status' => 'delivering', 'attempts' => Dispatcher::max_attempts(), 'locked_at' => w6_ago( 16 ) ), array( 'delivery_id' => (int) $poison->delivery_id ) );
w6_answer( 200, 5 );
Dispatcher::process( 5 );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $poison->delivery_id );
check( 'stale delivery with exhausted attempts fails instead of looping', 'failed' === $row->status && 0 === w6_sent( $poison->event_id ) );

// Worker dies during the HTTP request (child process).
$crash = Dispatcher::create_subscription( 'https://crash.hooks.example/in', array( 'order.paid' ) );
Dispatcher::emit( 'order.paid', 'w6-crash-' . uniqid(), array( 'object' => 'order', 'id' => 'w6c' ) );
$cd = w6_last( $w6_deliveries( (int) $crash['subscription_id'] ) );
$out = w6_child( 'webhook' );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $cd->delivery_id );
check( 'dispatcher exits during the request: delivery released to pending, attempt counted', false !== strpos( $out, 'CRASH' ) && 'pending' === $row->status && 1 === (int) $row->attempts && null === $row->locked_at );
check( 'released delivery waits a backoff with a last error', strtotime( $row->next_attempt_at . ' UTC' ) > time() + 30 && '' !== (string) $row->last_error );
Dispatcher::set_status( (int) $crash['subscription_id'], false );

// Backoff and final failure for a receiver that keeps answering 500.
Dispatcher::emit( 'order.paid', 'w6-500-' . uniqid(), array( 'object' => 'order', 'id' => 'w6f' ) );
$fd = w6_last( $w6_deliveries( $sid ) );
$delays = array();
for ( $i = 1; $i <= Dispatcher::max_attempts(); $i++ ) {
	w6_answer( 500, 5 );
	Dispatcher::process( 5 );
	$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $fd->delivery_id );
	$delays[] = strtotime( $row->next_attempt_at . ' UTC' ) - time();
	if ( 'pending' === $row->status ) { $wpdb->update( "{$p}wpcap_webhook_deliveries", array( 'next_attempt_at' => w6_ago( 1 ) ), array( 'delivery_id' => (int) $fd->delivery_id ) ); }
}
check( 'failing receiver: backoff 60 s, 120 s, 240 s ... and failed after ' . Dispatcher::max_attempts() . ' attempts', abs( $delays[0] - 60 ) <= 2 && abs( $delays[1] - 120 ) <= 2 && abs( $delays[2] - 240 ) <= 2 && 'failed' === $row->status && Dispatcher::max_attempts() === (int) $row->attempts );
$redo = Dispatcher::redeliver( (int) $fd->delivery_id );
w6_answer( 200, 5 );
Dispatcher::process( 5 );
http_reset();
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=" . (int) $fd->delivery_id );
check( 'manual redelivery after the receiver recovered succeeds with a fresh budget', true === $redo && 'succeeded' === $row->status && 1 === (int) $row->attempts );
Dispatcher::set_status( $sid, false );

/* ------------------------------------------------------------------------------------------ */
echo "W6 workers: SMS queue\n";
final class W6_Sms_Provider implements PassCaptain\Sms\ProviderInterface {
	public array $sent = array();
	public function id() { return 'w6sms'; }
	public function title() { return 'W6'; }
	public function is_configured() { return true; }
	public function send( $to, $body, $reference ) { $this->sent[] = $reference; return array( 'provider_ref' => 'w6-' . $reference ); }
}
$w6_sms = new W6_Sms_Provider();
$w6_sms_filter = static function ( $list ) use ( $w6_sms ) { $list[] = $w6_sms; return $list; };
add_filter( 'wpcap_sms_providers', $w6_sms_filter );
$w6_sms_settings = PassCaptain\Settings::get( 'sms' );
PassCaptain\Settings::update( array( 'sms' => array_merge( (array) $w6_sms_settings, array( 'provider' => 'w6sms' ) ) ) );
$wpdb->query( "UPDATE {$p}wpcap_sms_messages SET status='skipped' WHERE status IN ('queued','sending')" ); // Only this test's messages.
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_sms_consents", array( 'order_id' => 990000 + mt_rand( 1, 9999 ), 'phone' => '+4915100000000', 'purpose' => 'w6', 'text_version' => 'w6', 'consented_at' => $now ) );
$w6_consent = (int) $wpdb->insert_id;
/** Queued SMS row; returns its id. */
$w6_sms_row = static function ( array $extra = array() ) use ( $wpdb, $p, $w6_consent, $now ) {
	$wpdb->insert( "{$p}wpcap_sms_messages", array_merge( array( 'dedupe_key' => hash( 'sha256', uniqid( '', true ) ), 'consent_id' => $w6_consent, 'order_id' => 1, 'purpose' => 'w6', 'body' => 'Hallo', 'status' => 'queued', 'send_at' => '2000-01-01 00:00:00', 'created_at' => $now, 'updated_at' => $now ), $extra ) );
	return (int) $wpdb->insert_id;
};
$w6_sms_status = static function ( $id ) use ( $wpdb, $p ) { return $wpdb->get_row( "SELECT status,attempts FROM {$p}wpcap_sms_messages WHERE message_id=" . (int) $id ); };
$id = $w6_sms_row();
$w6_sms_faults = array(
	'START TRANSACTION fails' => array( '/^START TRANSACTION/', 'error' ),
	'claim UPDATE 0 rows' => array( "/^UPDATE \S+wpcap_sms_messages SET status='sending'/", 'silent' ),
	'COMMIT of the claim lost' => array( '/^COMMIT/', 'gone' ),
);
foreach ( $w6_sms_faults as $label => list( $pattern, $kind ) ) {
	$w6_sms->sent = array();
	$f = wpcap_faults();
	$f->fail( $pattern, 0, 'silent' === $kind ? 1000 : 1, $kind );
	PassCaptain\Sms\Service::process_due( 5 );
	wpcap_faults_restore();
	check( "sms $label: nothing sent, message stays queued without attempt", ! $w6_sms->sent && 'queued' === $w6_sms_status( $id )->status && 0 === (int) $w6_sms_status( $id )->attempts );
}
$w6_sms->sent = array();
PassCaptain\Sms\Service::process_due( 5 );
PassCaptain\Sms\Service::process_due( 5 );
check( 'sms: next run sends it exactly once', array( 'wpcap-sms-' . $id ) === $w6_sms->sent && 'sent' === $w6_sms_status( $id )->status );
$poison = $w6_sms_row( array( 'status' => 'sending', 'attempts' => PassCaptain\Sms\Service::MAX_ATTEMPTS, 'locked_at' => w6_ago( 16 ) ) );
$w6_sms->sent = array();
PassCaptain\Sms\Service::process_due( 5 );
check( 'sms: stale claim with exhausted attempts fails instead of being re-sent', 'failed' === $w6_sms_status( $poison )->status && ! $w6_sms->sent );
remove_filter( 'wpcap_sms_providers', $w6_sms_filter );
PassCaptain\Settings::update( array( 'sms' => $w6_sms_settings ) );

remove_filter( 'wp_pass_captain_process_job_v1', $w6_handler, 10 );
