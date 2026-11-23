<?php
/**
 * A344: WooCommerce payment confirmation and (partial) refund that hit a busy database are retried
 * by jobs (woocommerce_paid_sync per order, woocommerce_refund_sync per WooCommerce refund id)
 * instead of only leaving an order note.
 *
 * Included from run.php after modules/woocommerce.php (uses woo_t_order(), woo_t_refund()).
 */
// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

global $wpdb;
$p = $wpdb->prefix;
$a344_jobs = static function ( $type ) use ( $wpdb, $p ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT job_id,payload,status FROM {$p}wpcap_jobs WHERE job_type=%s AND status IN ('queued','running')", $type ) );
};
$a344_run = static function () use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type IN ('woocommerce_paid_sync','woocommerce_refund_sync') AND status='queued'" );
	add_filter( 'wpcap_process_job', array( Woo::class, 'handle_job' ), 10, 3 );
	Jobs::process_due( 50 );
	remove_filter( 'wpcap_process_job', array( Woo::class, 'handle_job' ), 10 );
};

echo "A344: WooCommerce payment confirmation under a busy database\n";
// Table names may be backtick-quoted (%i): the fault patterns allow it (\S* after the name).
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type IN ('woocommerce_paid_sync','woocommerce_refund_sync')" );
$a344_type = make_type( 10, 10, 3000 );
$a344_wc = woo_t_order( 7440, array( array( $a344_type, 2, '60.00' ) ), 'stripe' );
$a344_core = Woo::reserve( $a344_wc );
$a344_wc->status = 'processing';
wpcap_test_fault( '/^UPDATE \S*wpcap_reservations\S* SET status=.converted./', 'lock_timeout', array( 'rollback' => true ) );
$a344_r = Woo::sync_paid_order( 7440 );
wpcap_test_faults_clear();
$a344_paid_jobs = $a344_jobs( 'woocommerce_paid_sync' );
check( 'A344: busy payment confirmation: no tickets yet, but a retry job for the WooCommerce order', is_wp_error( $a344_r ) && 'pending' === Orders::get( $a344_core )->payment_status && ! tickets( $a344_core ) && 1 === count( $a344_paid_jobs ) && 7440 === (int) json_decode( $a344_paid_jobs[0]->payload, true )['woo_order'] );
check( 'A344: the order note says it is retried automatically', 1 === count( array_filter( $a344_wc->notes, static fn( $n ) => false !== strpos( $n, 'automatisch erneut' ) ) ) );
wpcap_test_fault( '/^UPDATE \S*wpcap_reservations\S* SET status=.converted./', 'lock_timeout', array( 'rollback' => true ) );
$a344_run();
wpcap_test_faults_clear();
check( 'A344: a job run that is still busy stays queued (backoff), without a second job or note', 1 === count( $a344_jobs( 'woocommerce_paid_sync' ) ) && 1 === count( array_filter( $a344_wc->notes, static fn( $n ) => false !== strpos( $n, 'automatisch erneut' ) ) ) );
$a344_run();
check( 'A344: the retry confirms the payment and issues the tickets once', 'paid' === Orders::get( $a344_core )->payment_status && 2 === count( tickets( $a344_core, 'valid' ) ) && ! $a344_jobs( 'woocommerce_paid_sync' ) );
check( 'A344: a later status hook changes nothing', true === Woo::sync_paid_order( 7440 ) && 2 === count( tickets( $a344_core ) ) );

echo "A344: WooCommerce partial refund under a busy database\n";
$a344_refund = woo_t_refund( 7441, $a344_wc, '30.00', array( array( 0, 1, '30.00' ) ) );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_refunds\S* \(/', 'lock_timeout', array( 'rollback' => true ) );
$a344_rr = Woo::sync_refund( 7440, 7441 );
wpcap_test_faults_clear();
$a344_rows = static function () use ( $wpdb, $p, $a344_core ) { return $wpdb->get_results( $wpdb->prepare( "SELECT amount_minor,status FROM {$p}wpcap_refunds WHERE order_id=%d", $a344_core ) ); };
$a344_refund_jobs = $a344_jobs( 'woocommerce_refund_sync' );
// Merged OrderAdapter::sync_refund() reports the failed booking with false (hook callback), the job carries the retry.
check( 'A344: busy refund booking: nothing booked, retry job for this WooCommerce refund id', ( is_wp_error( $a344_rr ) || false === $a344_rr ) && ! $a344_rows() && 1 === count( $a344_refund_jobs ) && 7441 === (int) json_decode( $a344_refund_jobs[0]->payload, true )['refund_id'] && '' === (string) $a344_refund->get_meta( Woo::REFUND_META, true ) );
$a344_run();
$a344_booked = $a344_rows();
check( 'A344: the retry books the refund once (one ticket refunded)', 1 === count( $a344_booked ) && 3000 === (int) $a344_booked[0]->amount_minor && 1 === count( tickets( $a344_core, 'valid' ) ) && ! $a344_jobs( 'woocommerce_refund_sync' ) && $a344_refund->get_meta( Woo::REFUND_META, true ) );
Woo::sync_refund( 7440, 7441 );
check( 'A344: a repeated refund hook books nothing again', 1 === count( $a344_rows() ) );

echo "A344: final errors are not retried\n";
$a344_wc2 = woo_t_order( 7442, array( array( make_type( 1, 10, 1000 ), 1, '10.00' ) ), 'stripe' );
$a344_core2 = Woo::reserve( $a344_wc2 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id INNER JOIN {$p}wpcap_order_items oi ON oi.ticket_type_id=tt.ticket_type_id SET o.status='cancelled' WHERE oi.order_id=%d", $a344_core2 ) );
expire_holds_of( $a344_core2 );
$a344_wc2->status = 'processing';
Woo::sync_paid_order( 7442 );
check( 'A344: a late payment for a cancelled date is flagged for review, no retry job', 'needs_review' === Orders::get( $a344_core2 )->status && ! $a344_jobs( 'woocommerce_paid_sync' ) );
