<?php
/**
 * A354: automatic refunds of a cancellation are system refunds (independent of the canceller's
 * capabilities and 2FA, checked-in tickets included); failed ones get a retry job.
 * A436: pass cancellation reads the ticket ids per order (no GROUP_CONCAT truncation).
 */

// phpcs:ignoreFile

use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Passes\Definitions;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

$a354_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a354_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$a354_settings = array( 'auto_refund_cancelled' => Settings::get( 'auto_refund_cancelled' ), 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'require_2fa_for_refunds' => Settings::get( 'require_2fa_for_refunds' ) );

function a354_occurrence( $price = 2000 ) {
	$event = ex_event( 'A354 Konzert' );
	$occ = ex_occ( $event );
	return array( $occ, ex_type( $occ, 'Standard', $price ) );
}
function a354_cancel( $occ ) {
	global $wpdb;
	$wpdb->update( "{$wpdb->prefix}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $occ ) );
	return OccurrenceCancellation::handle( $occ );
}
function a354_job( $order_id ) {
	global $wpdb;
	foreach ( (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_jobs WHERE job_type='cancellation_refund' AND status='queued' ORDER BY job_id DESC" ) as $row ) {
		$payload = json_decode( $row->payload, true );
		if ( (int) ( $payload['order_id'] ?? 0 ) === (int) $order_id ) { return $row; }
	}
	return null;
}

echo "A354: automatic cancellation refunds are system refunds\n";
Settings::update( array( 'auto_refund_cancelled' => 1, 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 1 ) );
list( $a354_occ, $a354_type ) = a354_occurrence();
list( $a354_order, $a354_tickets ) = ex_buy( $a354_type, 2, 'a354@example.com' );
// One ticket was already used for admission; the canceller has neither refund rights nor 2FA.
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a354_tickets[0] ) );
$GLOBALS['wpcap_test_user_id'] = 7;
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::REFUND_CAP, Refunds::APPROVE_CAP );
$a354_summary = a354_cancel( $a354_occ );
$a354_refunds = Refunds::for_order( $a354_order );
check( 'A354: refund runs without refund capability and 2FA of the canceller', 1 === (int) ( $a354_summary['refunds'] ?? 0 ) && 0 === (int) ( $a354_summary['refund_errors'] ?? 1 ) && 1 === count( $a354_refunds ) && 'completed' === $a354_refunds[0]['status'] );
check( 'A354: the checked-in ticket is refunded too (allow_used), the order is refunded', 4000 === (int) $a354_refunds[0]['amount_minor'] && 2 === count( (array) json_decode( (string) $a354_refunds[0]['ticket_ids'], true ) ) && 'refunded' === Orders::get( $a354_order )->status );
check( 'A354: system refund records no requesting user', 0 === (int) $a354_refunds[0]['requested_by'] );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A354: a failed cancellation refund gets a retry job\n";
Settings::update( array( 'require_2fa_for_refunds' => 0 ) );
list( $a354_occ2, $a354_type2 ) = a354_occurrence( 1500 );
list( $a354_order2, $a354_tickets2 ) = ex_buy( $a354_type2, 1, 'a354-retry@example.com' );
// A refund of the same ticket waits for approval: the automatic refund overlaps and fails.
$a354_pending = Refunds::request( $a354_order2, $a354_tickets2, 'Kulanz', 'a354-pending', array( 'system' => true, 'require_approval' => true ) );
$a354_summary2 = a354_cancel( $a354_occ2 );
$a354_job = a354_job( $a354_order2 );
check( 'A354: failed refund is counted and queued as cancellation_refund job', 1 === (int) ( $a354_summary2['refund_errors'] ?? 0 ) && null !== $a354_job && strtotime( $a354_job->run_after . ' UTC' ) > time() );
$a354_payload = $a354_job ? json_decode( $a354_job->payload, true ) : array();
check( 'A354: job carries order, tickets and the idempotency key of the cancellation', $a354_tickets2 === array_map( 'intval', (array) ( $a354_payload['ticket_ids'] ?? array() ) ) && 'occurrence-cancelled-' . $a354_occ2 === ( $a354_payload['key'] ?? '' ) );
$a354_busy = OccurrenceCancellation::handle_job( false, 'cancellation_refund', $a354_payload, $a354_job ? (int) $a354_job->job_id : 0 );
check( 'A354: while the overlap persists the job run fails (backoff, retry)', is_wp_error( $a354_busy ) );
Refunds::reject( (int) $a354_pending['refund_id'], 'Absage erstattet' );
$a354_retry = OccurrenceCancellation::handle_job( false, 'cancellation_refund', $a354_payload, $a354_job ? (int) $a354_job->job_id : 0 );
$a354_done = array_values( array_filter( Refunds::for_order( $a354_order2 ), static fn( $r ) => 'completed' === $r['status'] ) );
check( 'A354: the retry refunds the order once the obstacle is gone', true === $a354_retry && 1 === count( $a354_done ) && 1500 === (int) $a354_done[0]['amount_minor'] && 'refunded' === Orders::get( $a354_order2 )->status );
check( 'A354: a repeated job run is idempotent (no second refund)', true === OccurrenceCancellation::handle_job( false, 'cancellation_refund', $a354_payload, 0 ) && 1 === count( array_filter( Refunds::for_order( $a354_order2 ), static fn( $r ) => 'completed' === $r['status'] ) ) );
check( 'A354: other job types are passed through', 'x' === OccurrenceCancellation::handle_job( 'x', 'ticket_mail_retry', array(), 0 ) );

echo "A436: pass cancellation reads ticket ids per order\n";
if ( class_exists( Definitions::class ) && method_exists( Definitions::class, 'create' ) ) {
	Settings::update( array( 'auto_refund_cancelled' => 1 ) );
	$a436_event = ex_event( 'A436 Festival' );
	$a436_anchor = ex_occ( $a436_event, 0, 200 );
	$a436_day = ex_occ( $a436_event, 0, 230 );
	$a436_pass = Definitions::create( array( 'occurrence_id' => $a436_anchor, 'name' => 'A436 Pass', 'price_minor' => 3000, 'occurrence_ids' => array( $a436_anchor, $a436_day ), 'on_cancel' => 'revoke' ) );
	list( $a436_order, $a436_tickets ) = ex_buy( (int) $a436_pass, 3, 'a436@example.com' );
	// One pass was used for admission on the first day (A354 for passes).
	$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a436_tickets[0] ) );
	// A tiny group_concat_max_len makes a GROUP_CONCAT list unusable at once.
	$wpdb->query( 'SET SESSION group_concat_max_len=4' );
	$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a436_day ) );
	add_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10, 1 );
	do_action( 'wpcap_occurrence_cancelled', $a436_day, 0 );
	remove_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10 );
	$wpdb->query( 'SET SESSION group_concat_max_len=1048576' );
	$a436_refunds = array_values( array_filter( Refunds::for_order( $a436_order ), static fn( $r ) => 'completed' === $r['status'] ) );
	$a436_ids = $a436_refunds ? array_map( 'intval', (array) json_decode( (string) $a436_refunds[0]['ticket_ids'], true ) ) : array();
	sort( $a436_ids );
	check( 'A436: every pass ticket of the order is refunded (no truncated id list)', ! is_wp_error( $a436_pass ) && 1 === count( $a436_refunds ) && $a436_tickets === $a436_ids && 9000 === (int) $a436_refunds[0]['amount_minor'] );
	check( 'A436: no GROUP_CONCAT left in the pass cancellation', false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Passes/Cancellation.php' ), 'GROUP_CONCAT(' ) );
} else {
	echo "  skip passes module not loaded\n";
}

Settings::update( $a354_settings );
$GLOBALS['wpcap_test_user_id'] = $a354_user ?? 1;
$GLOBALS['wpcap_test_denied_caps'] = $a354_denied;
