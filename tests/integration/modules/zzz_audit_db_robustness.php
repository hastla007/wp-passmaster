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

/**
 * $wpdb stand-in that forwards to the real test connection but lets a test fail selected statements.
 * Rules: pattern (regex on the SQL), skip (matches to let through first), times (failures), silent
 * (pretend success with 0 affected rows instead of returning false).
 */
final class Audit_Fault_WPDB {
	public array $rules = array();
	public function __construct( public object $inner ) {}
	public function fail( $pattern, $skip = 0, $times = 1, $silent = false ) { $this->rules[] = compact( 'pattern', 'skip', 'times', 'silent' ); return $this; }
	public function __get( $name ) { return $this->inner->$name ?? null; }
	public function __set( $name, $value ) { $this->inner->$name = $value; }
	public function __isset( $name ) { return isset( $this->inner->$name ); }
	public function __call( $name, $args ) { return $this->inner->$name( ...$args ); }
	/** null = run it, false = injected error, 0 = injected silent no-op. */
	private function intercept( $sql ) {
		foreach ( $this->rules as $i => $rule ) {
			if ( $rule['times'] <= 0 || ! preg_match( $rule['pattern'], (string) $sql ) ) { continue; }
			if ( $rule['skip'] > 0 ) { $this->rules[ $i ]['skip']--; return null; }
			$this->rules[ $i ]['times']--;
			if ( $rule['silent'] ) { $this->inner->last_error = ''; return 0; }
			$this->inner->last_error = 'Injected fault';
			return false;
		}
		return null;
	}
	public function prepare( $query, ...$args ) { return $this->inner->prepare( $query, ...$args ); }
	public function query( $sql ) { $r = $this->intercept( $sql ); return null === $r ? $this->inner->query( $sql ) : $r; }
	public function get_results( $sql, $output = OBJECT ) { return null === $this->intercept( $sql ) ? $this->inner->get_results( $sql, $output ) : array(); }
	public function get_row( $sql, $output = OBJECT ) { return null === $this->intercept( $sql ) ? $this->inner->get_row( $sql, $output ) : null; }
	public function get_col( $sql ) { return null === $this->intercept( $sql ) ? $this->inner->get_col( $sql ) : array(); }
	public function get_var( $sql ) { return null === $this->intercept( $sql ) ? $this->inner->get_var( $sql ) : null; }
	private static function pairs( array $data, $glue ) { $o = array(); foreach ( $data as $k => $v ) { $o[] = null === $v ? "$k=NULL" : "$k='$v'"; } return implode( $glue, $o ); }
	public function insert( $table, $data, $format = null ) {
		$r = $this->intercept( "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ')' );
		return null === $r ? $this->inner->insert( $table, $data, $format ) : $r;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$r = $this->intercept( "UPDATE $table SET " . self::pairs( $data, ',' ) . ' WHERE ' . self::pairs( $where, ' AND ' ) );
		return null === $r ? $this->inner->update( $table, $data, $where, $format, $where_format ) : $r;
	}
	public function delete( $table, $where, $format = null ) {
		$r = $this->intercept( "DELETE FROM $table WHERE " . self::pairs( $where, ' AND ' ) );
		return null === $r ? $this->inner->delete( $table, $where, $format ) : $r;
	}
}

if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['wpcap_test_options'][ $k ] ); return true; } }

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
$f->fail( '/^CREATE TABLE wpfault_wpcap_jobs\b/', 0, 1 );
fwrite( STDERR, "(audit test: one injected 'Injected fault' schema error follows)\n" );
$ran_admin = Installer::maybe_upgrade( 'admin' );
ob_start();
foreach ( $GLOBALS['wpcap_test_hooks']['admin_notices'] ?? array() as $cbs ) { foreach ( $cbs as $cb ) { call_user_func( $cb[0] ); } }
$notice = (string) ob_get_clean();
check( 'admin request retries, still fails, and shows an admin notice', false === $ran_admin && 'old-version' === get_option( 'wpcap_db_version' ) && false !== strpos( $notice, 'Datenbank-Aktualisierung ist fehlgeschlagen' ) && false !== strpos( $notice, 'wpfault_wpcap_jobs' ) );

$audit_restore();
$ran_ok = Installer::maybe_upgrade( 'cron' );
check( 'retry without the fault completes and stores version and fingerprint', true === $ran_ok && Installer::version() === get_option( 'wpcap_db_version' ) && $fp === get_option( 'wpcap_schema_fingerprint' ) && ! get_option( Installer::ERROR_OPTION ) );
check( 'retry created the previously missing tables', null !== $audit_real->get_var( "SHOW TABLES LIKE 'wpfault\\_wpcap\\_jobs'" ) && null !== $audit_real->get_var( "SHOW TABLES LIKE 'wpfault\\_wpcap\\_refunds'" ) );
unset( $GLOBALS['wpcap_test_hooks']['admin_notices'] );
Installer::maybe_upgrade( 'admin' );
check( 'no admin notice once the upgrade succeeded', empty( $GLOBALS['wpcap_test_hooks']['admin_notices'] ) );
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
	check( "refund $label: no provider call, refund stays approved", is_wp_error( $res ) && 'wpcap_refund_db' === $res->get_error_code() && 0 === Audit_Fault_Gateway::$calls && 'approved' === $s['status'] && 'valid/0' === $s['ticket'] && 0 === $s['refunded'] );
	$retry = Refunds::execute( $rid );
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: retry pays out once and books", is_array( $retry ) && 'completed' === $s['status'] && 1 === Audit_Fault_Gateway::$calls && 'revoked/1' === $s['ticket'] && 800 === $s['refunded'] && 1 === $s['items'] );
}

// Failures between the provider payout and the end of the local booking.
$booking_faults = array(
	'ticket revocation fails'              => array( "/SET status='revoked' WHERE ticket_id/", 0, 1, false ),
	'order item update fails'              => array( '/UPDATE \S+wpcap_order_items SET refunded_quantity/', 0, 1, false ),
	'ticket refunded flag fails'           => array( '/UPDATE \S+wpcap_tickets SET refunded=1/', 0, 1, false ),
	'order total update fails'             => array( '/UPDATE \S+wpcap_orders SET refunded_minor/', 0, 1, false ),
	'refund completion fails'              => array( "/SET status='completed'/", 0, 1, false ),
	'refund completion affects 0 rows'     => array( "/SET status='completed'/", 0, 1, true ),
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
	check( "refund $label: provider paid once, booking rolled back, payout recorded", is_wp_error( $res ) && 'wpcap_refund_booking_pending' === $res->get_error_code() && 1 === Audit_Fault_Gateway::$calls && 'processing' === $s['status'] && 'fake_wpcap-refund-' . $rid === $s['reference'] && 'valid/0' === $s['ticket'] && 0 === $s['refunded'] && 0 === $s['items'] );
	$again = Refunds::execute( $rid );
	check( "refund $label: execute() again does not pay out a second time", is_array( $again ) && 'processing' === $again['status'] && 1 === Audit_Fault_Gateway::$calls );
	check( "refund $label: unbooked refund is counted", Refunds::unbooked_count() >= 1 );
	// Alternate the recovery path: direct reconcile() and the job queue cron.
	if ( 0 === $n++ % 2 ) { $booked = Refunds::reconcile(); } else { Jobs::process_due( 1 ); $booked = 1; }
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: reconciliation books it without a provider call", $booked >= 1 && 'completed' === $s['status'] && 1 === Audit_Fault_Gateway::$calls && 'revoked/1' === $s['ticket'] && 800 === $s['refunded'] && 1 === $s['items'] );
	Refunds::reconcile();
	$s = $audit_state( $rid, $oid, $tids[0] );
	check( "refund $label: reconciliation is idempotent", 'completed' === $s['status'] && 800 === $s['refunded'] && 1 === $s['items'] && 1 === Audit_Fault_Gateway::$calls );
}

// Full refund booked by reconciliation marks the order refunded.
list( $rid, $oid, $tids ) = $audit_refund( true );
Audit_Fault_Gateway::$calls = 0;
$audit_faults()->fail( "/SET status='completed'/", 0, 1, false );
Refunds::execute( $rid );
$audit_restore();
check( 'full refund: order still paid while the booking is pending', 'paid' === Orders::get( $oid )->payment_status && 1 === Audit_Fault_Gateway::$calls );
Refunds::reconcile();
check( 'full refund: reconciliation completes it and marks the order refunded', 'completed' === Refunds::get( $rid )['status'] && 'refunded' === Orders::get( $oid )->status && 2400 === (int) Orders::get( $oid )->refunded_minor && 1 === Audit_Fault_Gateway::$calls );

// Provider paid, but even the payout note cannot be stored: stays processing (bound), never re-paid automatically.
list( $rid, $oid, $tids ) = $audit_refund();
Audit_Fault_Gateway::$calls = 0;
$audit_faults()->fail( '/UPDATE \S+wpcap_refunds SET provider_reference=/', 0, 1, false );
$res = Refunds::execute( $rid );
$audit_restore();
Refunds::reconcile();
Refunds::execute( $rid );
$s = $audit_state( $rid, $oid, $tids[0] );
check( 'payout note fails: error, refund stays processing, no second payout by retry or reconcile', is_wp_error( $res ) && 'wpcap_refund_booking' === $res->get_error_code() && 'processing' === $s['status'] && '' === $s['reference'] && 1 === Audit_Fault_Gateway::$calls && 0 === $s['refunded'] );
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
	$processed = Jobs::process_due( 50 );
	$audit_restore();
	$row = $audit_real->get_row( "SELECT status,attempts FROM {$p}wpcap_jobs WHERE job_id=" . (int) $job->job_id );
	check( "jobs $label: handler not run, job stays queued without an attempt", 0 === $processed && array() === $audit_handled && 'queued' === $row->status && 0 === (int) $row->attempts );
	Jobs::process_due( 200 );
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
check( 'parallel workers: each of 40 messages sent exactly once (' . count( $mails_seen ) . ' sent)', 40 === count( $counts ) && array( 1 ) === array_values( array_unique( $counts ) ) && 40 === $sent );
