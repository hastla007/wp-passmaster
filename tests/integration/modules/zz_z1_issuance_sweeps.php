<?php
/**
 * Z1 (merge follow-up): Orders::reconcile_issuance() (job queue, other line) and the A343 sweep
 * Orders::sweep_unissued() follow one rule set:
 * - paid orders older than UNISSUED_LOOKBACK (2 days) are not issued and mailed automatically by
 *   either (Doctor check); reconcile_issuance() used 30 days.
 * - an order reconcile_issuance() cannot complete is handed to the sweep's retry job (one fixed key) and
 *   not picked again, so a few orders that never complete cannot hold its batch forever (before, the
 *   same lowest order ids were retried on every run and later orders were never reached).
 * - an order with an open issuance job gets no second job; it is still tried on every run.
 * Self-contained (WPCAP_TEST_ONLY=z1_issuance).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
echo "Z1: issuance sweeps (reconcile_issuance and A343)\n";

/** Paid order without tickets and without a queued job (issuance failed, retry job lost). */
$z1i_unissued = static function ( $label ) use ( $wpdb, $p ) {
	$o = Orders::create( array( 'email' => 'z1i-' . $label . '@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 10, 10, 1500 ), 'quantity' => 1 ) ), 'idempotency_key' => 'z1i-' . $label . '-' . uniqid() ) );
	wpcap_test_fault( '/^INSERT INTO \S*wpcap_tickets \(/', 'lock_timeout', array( 'rollback' => true, 'times' => Orders::DB_RETRIES + 1 ) );
	Orders::confirm_manual_payment( $o['order_id'] );
	wpcap_test_faults_clear();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_jobs WHERE job_type IN ('ticket_issue','ticket_issue_retry') AND payload=%s", wp_json_encode( array( 'order_id' => (int) $o['order_id'] ) ) ) );
	return (int) $o['order_id'];
};
$z1i_sweep_job = static function ( $order_id ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE idempotency_key=%s", hash( 'sha256', 'ticket-issue-sweep|' . (int) $order_id ) ) );
};

// Earlier modules may leave paid orders without tickets: issue what can be issued, hand over the rest.
Orders::reconcile_issuance( 1000 );

$z1i_old = $z1i_unissued( 'old' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET updated_at=%s, created_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), $z1i_old ) );
$z1i_mails = count( $GLOBALS['wpcap_test_mails'] );
Orders::reconcile_issuance( 1000 );
check( 'z1: an order paid 3 days ago is not issued or mailed by reconcile_issuance (Doctor check, like A343)', ! tickets( $z1i_old ) && ! array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $z1i_mails ), static fn( $m ) => 'z1i-old@example.com' === $m['to'] ) );

$z1i_a = $z1i_unissued( 'a' );
$z1i_b = $z1i_unissued( 'b' );
$z1i_c = $z1i_unissued( 'c' );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_tickets \(/', 'lock_timeout', array( 'rollback' => true, 'times' => 100 ) );
Orders::reconcile_issuance( 2 );
wpcap_test_faults_clear();
check( 'z1: orders reconcile_issuance could not complete get the one sweep retry job', 1 === $z1i_sweep_job( $z1i_a ) && 1 === $z1i_sweep_job( $z1i_b ) && 0 === $z1i_sweep_job( $z1i_c ) && ! tickets( $z1i_a ) );
$z1i_done = Orders::reconcile_issuance( 2 );
check( 'z1: the next run reaches the later order instead of retrying the handed-over ones', 1 === count( tickets( $z1i_c, 'valid' ) ) && ! tickets( $z1i_a ) && ! tickets( $z1i_b ) && $z1i_done >= 1 );
check( 'z1: the A343 sweep does not queue a second job for a handed-over order', 1 === $z1i_sweep_job( $z1i_a ) && ( Orders::sweep_unissued( 1000 ) || true ) && 1 === $z1i_sweep_job( $z1i_a ) );

$z1i_d = $z1i_unissued( 'd' );
PassCaptain\Jobs::enqueue( 'z1i-open-' . $z1i_d, 'ticket_issue', array( 'order_id' => $z1i_d ), gmdate( 'Y-m-d H:i:s', time() + 600 ) );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_tickets \(/', 'lock_timeout', array( 'rollback' => true, 'times' => 100 ) );
Orders::reconcile_issuance( 1000 );
wpcap_test_faults_clear();
check( 'z1: an order with an open issuance job gets no second job when the immediate attempt fails', ! tickets( $z1i_d ) && 0 === $z1i_sweep_job( $z1i_d ) );
Orders::reconcile_issuance( 1000 );
check( 'z1: ... and is still tried on the next run (the job is not due yet)', 1 === count( tickets( $z1i_d, 'valid' ) ) );

// Leave no paid order without tickets behind (the jobs of later modules would otherwise mail them).
foreach ( array( $z1i_old, $z1i_a, $z1i_b, $z1i_d ) as $z1i_id ) { Orders::issue_and_email( $z1i_id ); }
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type IN ('ticket_issue','ticket_issue_retry')" );
