<?php
/**
 * A348: refunds pending at the provider are tracked (provider_status), their later outcome is applied
 * (webhook or reconciliation job), and a booked refund that fails at the provider is reversed: status
 * "reversed", refunded total reduced, credit note reversed with its own document, admin alert, and the
 * amount can be paid out again.
 *
 * Included from run.php (uses check(), make_type(), tickets() and the HTTP/REST fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Refunds;
use PassCaptain\Settings;
use PassCaptain\StateMachine;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;
Settings::update( array( 'stripe_secret_key' => 'sk_test_a348', 'stripe_webhook_secret' => 'whsec_test', 'refund_approval_threshold' => 0 ) );
Registry::reset();
$GLOBALS['wpcap_test_denied_caps'] = array();

function a348_order( $key, $qty = 2 ) {
	$o = provider_order( 'stripe', make_type( 10, 10, 2500 ), $qty, $key );
	Webhooks::handle( stripe_request( stripe_session_event( "evt_{$key}_paid", 'checkout.session.completed', $o, array( 'id' => "cs_$key", 'payment_intent' => "pi_$key" ) ) ) );
	return $o;
}
function a348_refund_event( $id, $type, $refund_id, $re, $status ) {
	return stripe_request( array( 'id' => $id, 'type' => $type, 'data' => array( 'object' => array( 'id' => $re, 'object' => 'refund', 'amount' => 2500, 'currency' => 'eur', 'status' => $status, 'metadata' => array( 'wpcap_refund' => Refunds::key_for( $refund_id ) ) ) ) ) );
}

echo "A348: refunds pending or failing later at the provider\n";
check( 'A348: a completed refund can only become "reversed" (terminal)', StateMachine::allowed( 'refund', 'completed', 'reversed' ) && StateMachine::is_terminal( 'refund', 'reversed' ) && ! StateMachine::allowed( 'refund', 'reversed', 'completed' ) );

// 1. Pending refund: booked, tracked, the success event settles the provider status.
http_reset();
$o1 = a348_order( 'a348a', 1 );
http_push( array( 'id' => 're_a348a', 'status' => 'pending', 'amount' => 2500 ), 200, '/v1/refunds' );
$r1 = Refunds::request( $o1['order_id'], array(), 'Absage', 'a348-1' );
$id1 = (int) $r1['refund_id'];
check( 'A348: a refund pending at the provider is booked and marked "pending" with a status job', 'completed' === $r1['status'] && 'pending' === $r1['provider_status'] && 're_a348a' === $r1['provider_reference'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='refund_reconcile' AND payload LIKE '%\"refund_id\":$id1,%'" ) === 1 );
http_push( array( 'id' => 're_a348a', 'object' => 'refund', 'status' => 'pending', 'amount' => 2500 ), 200, '/v1/refunds/re_a348a' );
check( 'A348: the job keeps waiting while the provider still reports "pending"', 'waiting' === Refunds::reconcile( $id1 ) && 'pending' === Refunds::get( $id1 )['provider_status'] );
$booked_at = Refunds::get( $id1 )['updated_at'];
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET updated_at=%s WHERE refund_id=%d", '2026-01-02 03:04:05', $id1 ) );
Webhooks::handle( a348_refund_event( 'evt_a348a_ok', 'refund.updated', $id1, 're_a348a', 'succeeded' ) );
check( 'A348: the success event sets the provider status and keeps the booking date', 'succeeded' === Refunds::get( $id1 )['provider_status'] && '2026-01-02 03:04:05' === Refunds::get( $id1 )['updated_at'] && 'completed' === Refunds::get( $id1 )['status'] );

// 2. Pending, then failed: booking reversed, credit note reversed, admin alerted, retry possible.
http_reset();
$o2 = a348_order( 'a348b', 2 );
Invoices::issue_for_order( $o2['order_id'] );
$t2 = tickets( $o2['order_id'] );
http_push( array( 'id' => 're_a348b', 'status' => 'pending', 'amount' => 2500 ), 200, '/v1/refunds' );
$r2 = Refunds::request( $o2['order_id'], array( (int) $t2[0]->ticket_id ), 'Kunde krank', 'a348-2' );
$id2 = (int) $r2['refund_id'];
$credit = Invoices::credit_note_for_refund( $id2 );
$hooks_before = count( array_filter( $GLOBALS['wpcap_test_actions'], static fn( $h ) => 'wpcap_refund_reversed' === $h ) );
$mails = count( $GLOBALS['wpcap_test_mails'] );
// The job finds it failed (lookup by its provider id).
http_push( array( 'id' => 're_a348b', 'object' => 'refund', 'status' => 'failed', 'amount' => 2500 ), 200, '/v1/refunds/re_a348b' );
$job = Refunds::handle_job( false, 'refund_reconcile', array( 'refund_id' => $id2, 'attempt' => 0 ) );
$row2 = Refunds::get( $id2 );
$credit_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_credit_notes WHERE refund_id=%d", $id2 ) );
check( 'A348: a refund failing later is reversed (status, provider status, reversal date)', true === $job && 'reversed' === $row2['status'] && 'failed' === $row2['provider_status'] && null !== $row2['reversed_at'] && $row2['updated_at'] === $r2['updated_at'] );
check( 'A348: the refunded total goes back to 0, the ticket stays revoked', 0 === (int) Orders::get( $o2['order_id'] )->refunded_minor && 'revoked' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $t2[0]->ticket_id ) );
check( 'A348: the credit note gets a reversal document with its own number', is_array( $credit ) && $credit_after && '' !== (string) $credit_after->reversal_number && $credit_after->reversal_number !== $credit_after->credit_note_number && null !== $credit_after->reversed_at );
check( 'A348: admin alert, audit and hook wpcap_refund_reversed', count( $GLOBALS['wpcap_test_mails'] ) === $mails + 1 && false !== strpos( end( $GLOBALS['wpcap_test_mails'] )['subject'], 'gescheitert' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='refund_reversed' AND object_id=%d", $o2['order_id'] ) ) && count( array_filter( $GLOBALS['wpcap_test_actions'], static fn( $h ) => 'wpcap_refund_reversed' === $h ) ) === $hooks_before + 1 );
$rows = Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d', time() + 86400 ) );
$reversal_rows = array_values( array_filter( $rows, static fn( $r ) => (string) $r[1] === (string) $credit_after->reversal_number ) );
$credit_rows = array_values( array_filter( $rows, static fn( $r ) => (string) $r[1] === (string) $credit_after->credit_note_number ) );
check( 'A348: the export lists the correction and its reversal (positive, referencing the correction)', $credit_rows && $reversal_rows && '-' === substr( (string) $credit_rows[0][10], 0, 1 ) && '-' !== substr( (string) $reversal_rows[0][10], 0, 1 ) && (string) $credit_after->credit_note_number === (string) $reversal_rows[0][4] );
Webhooks::handle( a348_refund_event( 'evt_a348b_f2', 'refund.failed', $id2, 're_a348b', 'failed' ) );
check( 'A348: a repeated failure event reverses nothing twice', 0 === (int) Orders::get( $o2['order_id'] )->refunded_minor && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='refund_reversed' AND object_id=%d", $o2['order_id'] ) ) );
ob_start();
OrdersPage::render_refund_table( array( Refunds::get( $id2 ) ), 'EUR', false );
$html = ob_get_clean();
check( 'A348: the order page shows the reversal and offers a new payout', false !== strpos( $html, 'gescheitert' ) && false !== strpos( $html, 'value="retry"' ) );
http_push( array( 'id' => 're_a348b2', 'status' => 'succeeded', 'amount' => 2500 ), 200, '/v1/refunds' );
$retry = OrdersPage::resolve_refund( array( 'refund_id' => $id2, 'do' => 'retry' ) );
$retry_again = Refunds::retry_reversed( $id2 );
check( 'A348: the amount is paid out again once (new refund, idempotent)', ! is_wp_error( $retry ) && 'completed' === $retry['result']['status'] && (int) $retry['result']['refund_id'] === (int) $retry_again['refund_id'] && 2500 === (int) Orders::get( $o2['order_id'] )->refunded_minor && count( array_filter( $GLOBALS['wpcap_test_http_requests'], static fn( $r ) => 'POST' === $r['method'] && false !== strpos( $r['url'], '/v1/refunds' ) ) ) === 2 );

// 3. A refund booked as succeeded that fails later (webhook), on a fully refunded order: reversed, retry from "refunded".
http_reset();
$o3 = a348_order( 'a348c', 1 );
http_push( array( 'id' => 're_a348c', 'status' => 'succeeded', 'amount' => 2500 ), 200, '/v1/refunds' );
$r3 = Refunds::request( $o3['order_id'], array(), 'Absage', 'a348-3' );
check( 'A348: an immediate success is stored as provider status "succeeded"', 'succeeded' === $r3['provider_status'] && 'refunded' === Orders::get( $o3['order_id'] )->status );
Webhooks::handle( a348_refund_event( 'evt_a348c_f', 'charge.refund.updated', (int) $r3['refund_id'], 're_a348c', 'failed' ) );
check( 'A348: a later failure of a succeeded refund reverses it as well', 'reversed' === Refunds::get( (int) $r3['refund_id'] )['status'] && 0 === (int) Orders::get( $o3['order_id'] )->refunded_minor );
Webhooks::handle( a348_refund_event( 'evt_a348c_other', 'refund.failed', (int) $r3['refund_id'], 're_unrelated', 'failed' ) );
http_push( array( 'id' => 're_a348c2', 'status' => 'succeeded', 'amount' => 2500 ), 200, '/v1/refunds' );
$retry3 = Refunds::retry_reversed( (int) $r3['refund_id'] );
check( 'A348: the new payout also works for an order that was fully refunded', ! is_wp_error( $retry3 ) && 'completed' === $retry3['status'] && 2500 === (int) Orders::get( $o3['order_id'] )->refunded_minor );
check( 'A348: only reversed refunds can be paid out again', is_wp_error( Refunds::retry_reversed( (int) $retry3['refund_id'] ) ) );
http_reset();
