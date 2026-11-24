<?php
/**
 * Audit findings (main @ 14522c7): database robustness under failures.
 *
 * - Installer: a failed dbDelta() must not advance wpcap_db_version / the schema fingerprint;
 *   the upgrade lock is released and the next attempt retries.
 * - Refunds: the processing claim must be durable before the provider is called; a database error
 *   after the provider paid out leaves a recoverable state that reconcile() books without paying again.
 * - Jobs / Communications: handlers and mails only run after a durable claim; parallel workers
 *   process each item exactly once.
 *
 * Runs last (zzz_) because the queue workers process every due item in the shared test tables.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Installer;
use PassCaptain\Jobs;
use PassCaptain\Communications;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

// Audit_Fault_WPDB ($wpdb stand-in with failing statements): lib/fault_wpdb.php.
require_once dirname( __DIR__ ) . '/lib/fault_wpdb.php';

if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['wpcap_test_options'][ $k ] ); return true; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return (string) $url; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="nonce">'; } }

global $wpdb;
$audit_real = $wpdb;
$p = $wpdb->prefix;
/** Installs a fault proxy as $wpdb and returns it. */
$audit_faults = static function () use ( $audit_real ) { $GLOBALS['wpdb'] = new Audit_Fault_WPDB( $audit_real ); return $GLOBALS['wpdb']; };
$audit_restore = static function () use ( $audit_real ) { $GLOBALS['wpdb'] = $audit_real; };

/* ------------------------------------------------------------------------------------------ */
echo "Audit: failed schema upgrade does not advance the DB version\n";
$audit_saved = array();
foreach ( array( 'wpcap_db_version', 'wpcap_version', 'wpcap_schema_fingerprint', Installer::ERROR_OPTION ) as $k ) { $audit_saved[ $k ] = $GLOBALS['wpcap_test_options'][ $k ] ?? null; }
check( 'installed test schema verifies completely (no missing tables/columns)', array() === Installer::missing_schema( array_merge( array( "CREATE TABLE {$p}wpcap_jobs (\n job_id bigint(20) NOT NULL,\n status varchar(20) NOT NULL,\n PRIMARY KEY  (job_id)\n)" ), PassCaptain\Modules::schema( $p, '' ) ) ) );
check( 'missing_schema reports a missing column', array( "{$p}wpcap_jobs.no_such_column" ) === Installer::missing_schema( array( "CREATE TABLE {$p}wpcap_jobs (\n job_id bigint(20) NOT NULL,\n no_such_column int NOT NULL,\n KEY job_id (job_id)\n)" ) ) );

$audit_real->prefix = 'wpfault_';
Installer::flush_version_cache();
$fp = Installer::fingerprint();
update_option( 'wpcap_db_version', 'old-version' );
update_option( 'wpcap_schema_fingerprint', 'old-fp' );
unset( $GLOBALS['wpcap_test_options'][ Installer::ERROR_OPTION ] );
$f = $audit_faults();
$f->fail( '/^CREATE TABLE wpfault_wpcap_jobs\b/', 0, 1 );                // dbDelta error.
$f->fail( '/^CREATE TABLE wpfault_wpcap_refunds\b/', 0, 1, true );       // Silently not created (no error reported).
fwrite( STDERR, "(audit test: one injected 'Injected fault' schema error follows)\n" );
$ran = Installer::maybe_upgrade( 'cron' );
$err = get_option( Installer::ERROR_OPTION );
check( 'failed upgrade reports false and keeps the old DB version and fingerprint', false === $ran && 'old-version' === get_option( 'wpcap_db_version' ) && 'old-fp' === get_option( 'wpcap_schema_fingerprint' ) );
check( 'failure details name the failed and the silently missing table', is_array( $err ) && in_array( 'wpfault_wpcap_jobs', $err['missing'], true ) && in_array( 'wpfault_wpcap_refunds', $err['missing'], true ) && in_array( 'Injected fault', $err['errors'], true ) );
$audit_lock_db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$lock = $audit_lock_db->real_escape_string( Installer::lock_name() );
check( 'upgrade lock is released after the failed attempt', '1' === (string) $audit_lock_db->query( "SELECT GET_LOCK('$lock',0)" )->fetch_row()[0] );
$audit_lock_db->query( "SELECT RELEASE_LOCK('$lock')" );

unset( $GLOBALS['wpcap_test_hooks']['admin_notices'] );
Installer::allow_retry(); // Backoff elapsed (merged A466 backoff: one retry_at, cleared like the status page's retry button).
$f->fail( '/^CREATE TABLE wpfault_wpcap_jobs\b/', 0, 1 );
fwrite( STDERR, "(audit test: one injected 'Injected fault' schema error follows)\n" );
$ran_admin = Installer::maybe_upgrade( 'admin' );
// Merged design (A466): the failed upgrade is shown by Admin\Menu::schema_error_notice() (with retry button).
ob_start();
PassCaptain\Admin\Menu::schema_error_notice();
$notice = (string) ob_get_clean();
check( 'admin request retries, still fails, and shows an admin notice', false === $ran_admin && 'old-version' === get_option( 'wpcap_db_version' ) && false !== strpos( $notice, 'Datenbank-Update ist unvollständig' ) && false !== strpos( $notice, 'wpfault_wpcap_jobs' ) );

$audit_restore();
Installer::allow_retry(); // Backoff elapsed.
$ran_ok = Installer::maybe_upgrade( 'cron' );
check( 'retry without the fault completes and stores version and fingerprint', true === $ran_ok && Installer::version() === get_option( 'wpcap_db_version' ) && $fp === get_option( 'wpcap_schema_fingerprint' ) && ! get_option( Installer::ERROR_OPTION ) );
check( 'retry created the previously missing tables', null !== $audit_real->get_var( "SHOW TABLES LIKE 'wpfault\\_wpcap\\_jobs'" ) && null !== $audit_real->get_var( "SHOW TABLES LIKE 'wpfault\\_wpcap\\_refunds'" ) );
unset( $GLOBALS['wpcap_test_hooks']['admin_notices'] );
Installer::maybe_upgrade( 'admin' );
ob_start();
PassCaptain\Admin\Menu::schema_error_notice();
check( 'no admin notice once the upgrade succeeded', empty( $GLOBALS['wpcap_test_hooks']['admin_notices'] ) && '' === (string) ob_get_clean() );
foreach ( $audit_real->get_col( "SHOW TABLES LIKE 'wpfault\\_%'" ) as $t ) { $audit_real->query( "DROP TABLE IF EXISTS `$t`" ); }
$audit_real->prefix = $p;
Installer::flush_version_cache();
foreach ( $audit_saved as $k => $v ) { if ( null === $v ) { unset( $GLOBALS['wpcap_test_options'][ $k ] ); } else { $GLOBALS['wpcap_test_options'][ $k ] = $v; } }

/* ------------------------------------------------------------------------------------------ */
echo "Audit: refund claim and local booking survive database errors\n";
final class Audit_Fault_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static int $calls = 0;
	public function id() { return 'audit_fake'; }
	public function title() { return 'Fake'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) { return array(); }
	public function verify_webhook( \WP_REST_Request $request ) { return null; }
	public function supports_refunds() { return true; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { self::$calls++; return 'fake_' . $idempotency_key; }
}
$audit_gateways = static function ( $list ) { $list[] = new Audit_Fault_Gateway(); return $list; };
add_filter( 'wpcap_payment_gateways', $audit_gateways );
PassCaptain\Payments\Registry::reset();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$audit_type = make_type( 200, 10, 800 );
/** Paid order (3 × 8 €) of the fake provider plus an approved refund of its first ticket (or all tickets). */
$audit_refund = static function ( $all = false ) use ( $audit_type, $audit_real ) {
	$p = $audit_real->prefix;
	$order_id = admin_paid_order( $audit_type, 3, 'audit-refund@example.com' );
	$audit_real->query( "UPDATE {$p}wpcap_orders SET payment_provider='audit_fake' WHERE order_id=" . (int) $order_id );
	$tids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $order_id ) );
	$refund_tids = $all ? $tids : array( $tids[0] );
	$now = gmdate( 'Y-m-d H:i:s' );
	$audit_real->insert( "{$p}wpcap_refunds", array( 'order_id' => $order_id, 'idempotency_key' => hash( 'sha256', uniqid( 'audit', true ) ), 'amount_minor' => 800 * count( $refund_tids ), 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => json_encode( $refund_tids ), 'reason' => 'audit', 'status' => 'approved', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	return array( (int) $audit_real->insert_id, (int) $order_id, $tids );
};
/** Local booking state of a refund scenario. */
$audit_state = static function ( $refund_id, $order_id, $ticket_id ) use ( $audit_real ) {
	$p = $audit_real->prefix;
	$r = Refunds::get( $refund_id );
	$t = $audit_real->get_row( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id );
	return array(
		'status'    => $r['status'],
		'reference' => $r['provider_reference'],
		'ticket'    => $t->status . '/' . (int) $t->refunded,
		'refunded'  => (int) Orders::get( $order_id )->refunded_minor,
		'items'     => (int) $audit_real->get_var( "SELECT SUM(refunded_quantity) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $order_id ),
	);
};

// Claim failures: nothing is paid out, the refund stays approved and can simply be retried.
$claim_faults = array(
	'START TRANSACTION fails'              => array( '/^START TRANSACTION/', 0, 1, false ),
	'claim UPDATE fails'                   => array( "/SET status='processing'/", 0, 1, false ),
	'claim UPDATE affects 0 rows'          => array( "/SET status='processing'/", 0, 1, true ),
	'COMMIT of the claim fails'            => array( '/^COMMIT/', 0, 1, false ),
);
foreach ( $claim_faults as $label => $rule ) {
	list( $rid, $oid, $tids ) = $audit_refund();
	Audit_Fault_Gateway::$calls = 0;
	$audit_faults()->fail( ...$rule );
	$res = Refunds::execute( $rid );
	$audit_restore();
	$s = $audit_state( $rid, $oid, $tids[0] );
	// Merged design (A063/A183): database errors are retryable busy errors (Errors::busy(), HTTP 503).
	check( "refund $label: no provider call, refund stays approved", PassCaptain\Errors::is_busy( $res ) && 0 === Audit_Fault_Gateway::$calls && 'approved' === $s['status'] && 'valid/0' === $s['ticket'] && 0 === $s['refunded'] );
	$retry = Refunds::execute( $rid );
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: retry pays out once and books", is_array( $retry ) && 'completed' === $s['status'] && 1 === Audit_Fault_Gateway::$calls && 'revoked/1' === $s['ticket'] && 800 === $s['refunded'] && 1 === $s['items'] );
}

// Failures between the provider payout and the end of the local booking. Merged statements: the order
// line count is updated together with the ticket (CapacityRelease), the order total together with the
// refund completion (one UPDATE … JOIN), so these faults hit those combined statements.
$booking_faults = array(
	'ticket revocation fails'              => array( "/SET status='revoked' WHERE ticket_id/", 0, 1, false ),
	'order item update fails'              => array( '/oi\.refunded_quantity=LEAST/', 0, 1, false ),
	'ticket refunded flag fails'           => array( '/UPDATE \S+wpcap_tickets SET refunded=1/', 0, 1, false ),
	'order total update fails'             => array( '/o\.refunded_minor=LEAST/', 0, 1, false ),
	'refund completion fails'              => array( "/SET r\.status='completed'/", 0, 1, false ),
	'refund completion affects 0 rows'     => array( "/SET r\.status='completed'/", 0, 1, true ),
	'COMMIT of the booking fails'          => array( '/^COMMIT/', 1, 1, false ),
);
$n = 0;
foreach ( $booking_faults as $label => $rule ) {
	list( $rid, $oid, $tids ) = $audit_refund();
	Audit_Fault_Gateway::$calls = 0;
	$audit_faults()->fail( ...$rule );
	$res = Refunds::execute( $rid );
	$audit_restore();
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: provider paid once, booking rolled back, payout recorded", PassCaptain\Errors::is_busy( $res ) && 1 === Audit_Fault_Gateway::$calls && 'processing' === $s['status'] && 'fake_wpcap-refund-' . $rid === $s['reference'] && 'valid/0' === $s['ticket'] && 0 === $s['refunded'] && 0 === $s['items'] );
	check( "refund $label: unbooked refund is counted", Refunds::unbooked_count() >= 1 );
	// Alternate the recovery path. Merged design (A183): execute() again resumes the local booking of a
	// refund that holds its provider reference (no second payout); reconcile() books it as well.
	if ( 0 === $n++ % 2 ) {
		$again = Refunds::execute( $rid );
		check( "refund $label: execute() again does not pay out a second time", is_array( $again ) && 'completed' === $again['status'] && 1 === Audit_Fault_Gateway::$calls );
		$booked = 1;
	} else {
		$booked = Refunds::reconcile();
		$again = Refunds::execute( $rid );
		check( "refund $label: execute() again does not pay out a second time", is_array( $again ) && 'completed' === $again['status'] && 1 === Audit_Fault_Gateway::$calls );
	}
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: reconciliation books it without a provider call", $booked >= 1 && 'completed' === $s['status'] && 1 === Audit_Fault_Gateway::$calls && 'revoked/1' === $s['ticket'] && 800 === $s['refunded'] && 1 === $s['items'] );
	Refunds::reconcile();
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: reconciliation is idempotent", 'completed' === $s['status'] && 800 === $s['refunded'] && 1 === $s['items'] && 1 === Audit_Fault_Gateway::$calls );
}

// Full refund booked by reconciliation marks the order refunded.
list( $rid, $oid, $tids ) = $audit_refund( true );
Audit_Fault_Gateway::$calls = 0;
$audit_faults()->fail( "/SET r\.status='completed'/", 0, 1, false );
Refunds::execute( $rid );
$audit_restore();
check( 'full refund: order still paid while the booking is pending', 'paid' === Orders::get( $oid )->payment_status && 1 === Audit_Fault_Gateway::$calls );
Refunds::reconcile();
check( 'full refund: reconciliation completes it and marks the order refunded', 'completed' === Refunds::get( $rid )['status'] && 'refunded' === Orders::get( $oid )->status && 2400 === (int) Orders::get( $oid )->refunded_minor && 1 === Audit_Fault_Gateway::$calls );

// Provider paid, but even the payout note cannot be stored: stays processing (bound), never re-paid automatically.
// Merged design: the booking in the same run stores the reference as well, so the booking fails too here.
list( $rid, $oid, $tids ) = $audit_refund();
Audit_Fault_Gateway::$calls = 0;
$audit_faults()->fail( '/UPDATE \S+wpcap_refunds SET provider_reference=/', 0, 1, false )->fail( "/SET status='revoked' WHERE ticket_id/", 0, 1, false );
$res = Refunds::execute( $rid );
$audit_restore();
Refunds::reconcile();
Refunds::execute( $rid );
$s = $audit_state( $rid, $oid, $tids[0] );
check( 'payout note fails: error, refund stays processing, no second payout by retry or reconcile', is_wp_error( $res ) && 'processing' === $s['status'] && '' === $s['reference'] && 1 === Audit_Fault_Gateway::$calls && 0 === $s['refunded'] );
$overlap = Refunds::request( $oid, array( $tids[0] ), 'Test', 'audit-overlap-' . uniqid() );
check( 'payout note fails: the tickets stay bound against new refunds', is_wp_error( $overlap ) && 'wpcap_refund_overlap' === $overlap->get_error_code() );
$audit_real->query( "UPDATE {$p}wpcap_refunds SET status='failed' WHERE refund_id=" . (int) $rid ); // Clean up for later queue runs.

remove_filter( 'wpcap_payment_gateways', $audit_gateways );
PassCaptain\Payments\Registry::reset();

/* ------------------------------------------------------------------------------------------ */
echo "Audit: queue workers only run handlers / send mail after a durable claim\n";
$audit_handled = array();
$audit_handler = static function ( $handled, $type, $payload, $job_id ) use ( &$audit_handled ) {
	if ( 'audit_fault_job' !== $type ) { return $handled; }
	$audit_handled[] = (int) $job_id;
	return true;
};
add_filter( 'wp_pass_captain_process_job_v1', $audit_handler, 10, 4 );
$job_faults = array(
	'START TRANSACTION fails'     => array( '/^START TRANSACTION/', 0, 1, false ),
	'claim SELECT fails'          => array( "/FROM \S+wpcap_jobs WHERE status='queued'.*FOR UPDATE/", 0, 1, false ),
	'claim UPDATE fails'          => array( "/UPDATE \S+wpcap_jobs SET status='running'/", 0, 1, false ),
	'claim UPDATE affects 0 rows' => array( "/UPDATE \S+wpcap_jobs SET status='running'/", 0, 1000, true ),
	'COMMIT of the claim fails'   => array( '/^COMMIT/', 0, 1, false ),
);
foreach ( $job_faults as $label => $rule ) {
	$key = 'audit-job-' . uniqid( '', true );
	Jobs::enqueue( $key, 'audit_fault_job', array( 'x' => 1 ), '2000-01-01 00:00:00' );
	$job = $audit_real->get_row( $audit_real->prepare( "SELECT job_id FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $key ) ) );
	$audit_handled = array();
	$audit_faults()->fail( ...$rule );
	// Only this job type: the queue sweeps (refund/issuance reconcile) of a plain run would consume the
	// injected fault on their own transactions when earlier test files left work for them.
	$processed = Jobs::process_due( 50, 'audit_fault_job' );
	$audit_restore();
	$row = $audit_real->get_row( "SELECT status,attempts FROM {$p}wpcap_jobs WHERE job_id=" . (int) $job->job_id );
	check( "jobs $label: handler not run, job stays queued without an attempt", 0 === $processed && array() === $audit_handled && 'queued' === $row->status && 0 === (int) $row->attempts );
	Jobs::process_due( 200, 'audit_fault_job' );
	$row = $audit_real->get_row( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=" . (int) $job->job_id );
	check( "jobs $label: next run handles it exactly once", array( (int) $job->job_id ) === array_values( array_filter( $audit_handled, static fn( $id ) => $id === (int) $job->job_id ) ) && 'complete' === $row->status );
}
remove_filter( 'wp_pass_captain_process_job_v1', $audit_handler, 10 );

$msg_faults = array(
	'START TRANSACTION fails'     => array( '/^START TRANSACTION/', 0, 1, false ),
	'claim UPDATE fails'          => array( "/UPDATE \S+wpcap_message_queue SET status='sending'/", 0, 1, false ),
	'claim UPDATE affects 0 rows' => array( "/UPDATE \S+wpcap_message_queue SET status='sending'/", 0, 1000, true ),
	'COMMIT of the claim fails'   => array( '/^COMMIT/', 0, 1, false ),
);
// A652 (merged design): the stored payload is data, never a template – a renderer supplies subject and body.
$audit_renderer = static function ( $template, $key, $payload ) {
	if ( ! in_array( $key, array( 'audit_fault', 'audit_parallel' ), true ) ) { return $template; }
	return array( 'subject' => (string) ( $payload['subject'] ?? '' ), 'body' => (string) ( $payload['body'] ?? '' ) );
};
add_filter( 'wpcap_email_template', $audit_renderer, 10, 3 );
$audit_sent_to = static function ( $to ) { return count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $to ) ); };
foreach ( $msg_faults as $label => $rule ) {
	$to = 'audit-msg-' . uniqid() . '@example.com';
	Communications::enqueue( 'audit-msg-' . $to, $to, 'audit_fault', array( 'subject' => 'Audit', 'body' => 'Hallo' ), '2000-01-01 00:00:00' );
	$audit_faults()->fail( ...$rule );
	$processed = Communications::process_due();
	$audit_restore();
	$row = $audit_real->get_row( $audit_real->prepare( "SELECT status,attempts FROM {$p}wpcap_message_queue WHERE recipient=%s", $to ) );
	check( "messages $label: no mail sent, message stays queued without an attempt", 0 === $processed && 0 === $audit_sent_to( $to ) && 'queued' === $row->status && 0 === (int) $row->attempts );
	Communications::process_due();
	$row = $audit_real->get_row( $audit_real->prepare( "SELECT status FROM {$p}wpcap_message_queue WHERE recipient=%s", $to ) );
	check( "messages $label: next run sends it exactly once", 1 === $audit_sent_to( $to ) && 'sent' === $row->status );
}

echo "Audit: parallel queue workers process every item exactly once\n";
$audit_jobs = array();
for ( $i = 0; $i < 40; $i++ ) {
	$key = 'audit-par-' . $i . '-' . uniqid( '', true );
	Jobs::enqueue( $key, 'audit_parallel', array( 'i' => $i ), '2000-01-01 00:00:00' );
	$audit_jobs[] = (int) $audit_real->get_var( $audit_real->prepare( "SELECT job_id FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', $key ) ) );
	Communications::enqueue( 'audit-par-msg-' . $key, "audit-par-$i@example.com", 'audit_parallel', array( 'subject' => 'Parallel', 'body' => 'Hallo' ), '2000-01-01 00:00:00' );
}
$procs = array();
for ( $i = 0; $i < 6; $i++ ) {
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/audit_queue_worker.php' ), 'r' );
}
$jobs_seen = array();
$mails_seen = array();
foreach ( $procs as $proc ) {
	foreach ( preg_split( '/\R/', (string) stream_get_contents( $proc ) ) as $line ) {
		if ( 0 === strpos( $line, 'J:' ) ) { $jobs_seen[] = (int) substr( $line, 2 ); }
		if ( 0 === strpos( $line, 'M:' ) ) { $mails_seen[] = substr( $line, 2 ); }
	}
	pclose( $proc );
}
sort( $jobs_seen );
$expected_jobs = $audit_jobs;
sort( $expected_jobs );
$counts = array_count_values( $mails_seen );
$complete = (int) $audit_real->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='audit_parallel' AND status='complete'" );
$sent = (int) $audit_real->get_var( "SELECT COUNT(*) FROM {$p}wpcap_message_queue WHERE template_key='audit_parallel' AND status='sent'" );
check( 'parallel workers: each of 40 jobs handled exactly once (' . count( $jobs_seen ) . ' handled)', $expected_jobs === $jobs_seen && 40 === $complete );
check( 'parallel workers: each of 40 messages sent exactly once (' . count( $mails_seen ) . '' . ' sent, ' . count( $counts ) . ' distinct, ' . $sent . ' marked sent)', 40 === count( $counts ) && array( 1 ) === array_values( array_unique( $counts ) ) && 40 === $sent );
remove_filter( 'wpcap_email_template', $audit_renderer, 10 );
