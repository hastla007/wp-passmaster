<?php
/**
 * A272: a refund call with an unclear outcome (transport error/timeout, 5xx, 409) is no "failed"
 * refund: it stays "processing" without a provider reference, is never paid out a second time and is
 * reconciled with the provider (metadata wpcap_refund via lookup job or webhook).
 * A350: such refunds are visible (operations page, order page) and can be resolved by a provider
 * lookup or a manual confirmation, both audited.
 *
 * Included from run.php (uses check(), make_type(), tickets() and the HTTP/REST fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\OperationsPage;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }
// Rendering stubs (only what the two refund views need).
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $t ) { return addslashes( (string) $t ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }

global $wpdb;
$p = $wpdb->prefix;
Settings::update( array( 'stripe_secret_key' => 'sk_test_a272', 'stripe_webhook_secret' => 'whsec_test', 'refund_approval_threshold' => 0 ) );
Registry::reset();
$GLOBALS['wpcap_test_denied_caps'] = array();

function a272_order( $key, $qty = 2 ) {
	$o = provider_order( 'stripe', make_type( 10, 10, 2500 ), $qty, $key );
	Webhooks::handle( stripe_request( stripe_session_event( "evt_{$key}_paid", 'checkout.session.completed', $o, array( 'id' => "cs_$key", 'payment_intent' => "pi_$key" ) ) ) );
	return $o;
}
function a272_posts() { return count( array_filter( $GLOBALS['wpcap_test_http_requests'], static fn( $r ) => 'POST' === $r['method'] && false !== strpos( $r['url'], '/v1/refunds' ) ) ); }
function a272_backdate( $refund_id, $minutes = 11 ) { global $wpdb; $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_refunds SET updated_at=%s WHERE refund_id=%d", gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 ), $refund_id ) ); }
function a272_jobs( $refund_id ) { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_jobs WHERE job_type='refund_reconcile' AND payload LIKE '%\"refund_id\":" . (int) $refund_id . ",%'" ); }
function a272_audits( $action, $order_id ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_id=%d", $action, $order_id ) ); }
function a272_last_refund( $order_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d ORDER BY refund_id DESC LIMIT 1", $order_id ), ARRAY_A ); }

echo "A272: unclear outcome of a refund call\n";
check( 'A272: outcome classification (transport, 5xx, 409 unknown; 400/402/404/429 definitive)', Refunds::is_unknown_outcome( new WP_Error( 'x', 'y', array( 'status' => 503 ) ) ) && Refunds::is_unknown_outcome( new WP_Error( 'x', 'y', array( 'status' => 409 ) ) ) && Refunds::is_unknown_outcome( new WP_Error( 'http_request_failed', 'timeout' ) ) && ! Refunds::is_unknown_outcome( new WP_Error( 'x', 'y', array( 'status' => 400 ) ) ) && 'definitive' === Refunds::outcome_for_status( 429 ) && 'definitive' === Refunds::outcome_for_status( 402 ) && 'unknown' === Refunds::outcome_for_status( 500 ) && ! Refunds::is_unknown_outcome( Refunds::definitive( new WP_Error( 'x', 'y', array( 'status' => 503 ) ) ) ) );

// 1. 500 on the refund POST: unknown, never retried as failed; lookup finds the refund → booked once.
http_reset();
$o1 = a272_order( 'a272a' );
http_push( array( 'error' => array( 'message' => 'Internal error' ) ), 500, '/v1/refunds' );
$r1 = Refunds::request( $o1['order_id'], array(), 'Absage', 'a272-1' );
$row1 = a272_last_refund( $o1['order_id'] );
check( 'A272: a 5xx answer keeps the refund in processing without reference (outcome unknown)', is_wp_error( $r1 ) && 'wpcap_refund_outcome_unknown' === $r1->get_error_code() && 'processing' === $row1['status'] && '' === $row1['provider_reference'] && Refunds::OUTCOME_UNKNOWN === $row1['error_message'] );
check( 'A272: tickets stay valid, nothing booked, reconciliation job planned and audited', 2 === count( tickets( $o1['order_id'], 'valid' ) ) && 0 === (int) Orders::get( $o1['order_id'] )->refunded_minor && 1 === a272_jobs( (int) $row1['refund_id'] ) && 1 === a272_audits( 'refund_outcome_unknown', $o1['order_id'] ) );
$again = Refunds::request( $o1['order_id'], array(), 'Absage', 'a272-1' );
$other = Refunds::request( $o1['order_id'], array( (int) tickets( $o1['order_id'] )[0]->ticket_id ), 'Absage', 'a272-1b' );
$other_amount = Refunds::request_amount( $o1['order_id'], 100, 'x', 'a272-1c' );
check( 'A272: neither a repeated request nor a new one pays out a second time', ! is_wp_error( $again ) && 'processing' === $again['status'] && is_wp_error( $other ) && 'wpcap_refund_overlap' === $other->get_error_code() && is_wp_error( $other_amount ) && 1 === a272_posts() );
check( 'A272: execute() on the unclear refund does not call the provider again', 'processing' === Refunds::execute( (int) $row1['refund_id'] )['status'] && 1 === a272_posts() );
http_push( array( 'object' => 'list', 'has_more' => false, 'data' => array( array( 'id' => 're_other', 'amount' => 100, 'status' => 'succeeded', 'metadata' => array() ), array( 'id' => 're_a272a', 'amount' => 5000, 'status' => 'succeeded', 'metadata' => array( 'wpcap_refund' => Refunds::key_for( (int) $row1['refund_id'] ) ) ) ) ), 200, '/v1/refunds?payment_intent=pi_a272a' );
$job1 = Refunds::handle_job( false, 'refund_reconcile', array( 'refund_id' => (int) $row1['refund_id'], 'attempt' => 0 ) );
$row1 = Refunds::get( (int) $row1['refund_id'] );
check( 'A272: the lookup via metadata wpcap_refund books the refund without a second payout', true === $job1 && 'completed' === $row1['status'] && 're_a272a' === $row1['provider_reference'] && 5000 === (int) Orders::get( $o1['order_id'] )->refunded_minor && ! tickets( $o1['order_id'], 'valid' ) && 1 === a272_posts() && 1 === a272_audits( 'refund_reconciled', $o1['order_id'] ) );
check( 'A272: other job types are left alone, a settled refund needs no further run', 'x' === Refunds::handle_job( 'x', 'other', array() ) && 'nothing' === Refunds::reconcile( (int) $row1['refund_id'] ) );

// 2. Transport error: provider has no such refund – waits 10 minutes, then "failed" (nothing paid out).
http_reset();
$o2 = a272_order( 'a272b', 1 );
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 'match' => '/v1/refunds' );
$r2 = Refunds::request( $o2['order_id'], array(), 'Absage', 'a272-2' );
$id2 = (int) a272_last_refund( $o2['order_id'] )['refund_id'];
check( 'A272: a timeout is an unknown outcome as well', is_wp_error( $r2 ) && 'wpcap_refund_outcome_unknown' === $r2->get_error_code() && 'processing' === Refunds::get( $id2 )['status'] );
http_push( array( 'object' => 'list', 'has_more' => false, 'data' => array() ), 200, '/v1/refunds?payment_intent=pi_a272b' );
$job2 = Refunds::handle_job( false, 'refund_reconcile', array( 'refund_id' => $id2, 'attempt' => 0 ) );
check( 'A272: "not found" right after the call waits (next run planned)', true === $job2 && 'processing' === Refunds::get( $id2 )['status'] && 2 === a272_jobs( $id2 ) );
a272_backdate( $id2 );
http_push( array( 'object' => 'list', 'has_more' => false, 'data' => array() ), 200, '/v1/refunds?payment_intent=pi_a272b' );
$rec2 = Refunds::reconcile( $id2 );
check( 'A272: "not found" 10 minutes later marks it failed (nothing paid out, tickets valid)', 'failed' === $rec2 && 'failed' === Refunds::get( $id2 )['status'] && 1 === count( tickets( $o2['order_id'], 'valid' ) ) );
http_push( array( 'id' => 're_a272b2', 'status' => 'succeeded', 'amount' => 2500 ), 200, '/v1/refunds' );
$new2 = Refunds::request( $o2['order_id'], array(), 'Absage', 'a272-2b' );
check( 'A272: after a confirmed failure a new refund can be paid out', ! is_wp_error( $new2 ) && 'completed' === $new2['status'] && 2500 === (int) Orders::get( $o2['order_id'] )->refunded_minor );

// 3. 409 (idempotency conflict) is unknown; the plugin refund's webhook settles it.
http_reset();
$o3 = a272_order( 'a272c', 1 );
http_push( array( 'error' => array( 'message' => 'Keys for idempotent requests can only be used with the same parameters' ) ), 409, '/v1/refunds' );
Refunds::request( $o3['order_id'], array(), 'Absage', 'a272-3' );
$id3 = (int) a272_last_refund( $o3['order_id'] )['refund_id'];
$hook3 = Webhooks::handle( stripe_request( array( 'id' => 'evt_a272c_r', 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_a272c', 'object' => 'charge', 'amount' => 2500, 'amount_refunded' => 2500, 'refunded' => true, 'currency' => 'eur', 'payment_intent' => 'pi_a272c', 'metadata' => array( 'wpcap_order' => $o3['public_id'] ), 'refunds' => array( 'data' => array( array( 'id' => 're_a272c', 'amount' => 2500, 'status' => 'succeeded', 'metadata' => array( 'wpcap_refund' => Refunds::key_for( $id3 ) ) ) ), 'has_more' => false ) ) ) ) ) );
$rows3 = $wpdb->get_results( $wpdb->prepare( "SELECT status,provider_reference,amount_minor FROM {$p}wpcap_refunds WHERE order_id=%d", $o3['order_id'] ) );
check( 'A272: a 409 is unknown and the charge.refunded webhook (wpcap_refund) books it exactly once', ! is_wp_error( $hook3 ) && 1 === count( $rows3 ) && 'completed' === $rows3[0]->status && 're_a272c' === $rows3[0]->provider_reference && 'refunded' === Orders::get( $o3['order_id'] )->status && 2500 === (int) Orders::get( $o3['order_id'] )->refunded_minor );
// A refund.failed event of an unclear refund: failed, nothing booked.
http_reset();
$o3b = a272_order( 'a272d', 1 );
http_push( array( 'error' => array( 'message' => 'boom' ) ), 502, '/v1/refunds' );
Refunds::request( $o3b['order_id'], array(), 'Absage', 'a272-3b' );
$id3b = (int) a272_last_refund( $o3b['order_id'] )['refund_id'];
Webhooks::handle( stripe_request( array( 'id' => 'evt_a272d_f', 'type' => 'refund.failed', 'data' => array( 'object' => array( 'id' => 're_a272d', 'object' => 'refund', 'amount' => 2500, 'currency' => 'eur', 'status' => 'failed', 'metadata' => array( 'wpcap_refund' => Refunds::key_for( $id3b ) ) ) ) ) ) );
check( 'A272: a refund.failed event settles an unclear refund as failed', 'failed' === Refunds::get( $id3b )['status'] && 1 === count( tickets( $o3b['order_id'], 'valid' ) ) );
// A provider that answers "paid" for a refund recorded as failed: booked after all (tickets still open), admin mailed.
$mails = count( $GLOBALS['wpcap_test_mails'] );
Webhooks::handle( stripe_request( array( 'id' => 'evt_a272d_s', 'type' => 'refund.updated', 'data' => array( 'object' => array( 'id' => 're_a272d2', 'object' => 'refund', 'amount' => 2500, 'currency' => 'eur', 'status' => 'succeeded', 'metadata' => array( 'wpcap_refund' => Refunds::key_for( $id3b ) ) ) ) ) ) );
check( 'A272: a refund paid out after all is booked late and reported', 'completed' === Refunds::get( $id3b )['status'] && 2500 === (int) Orders::get( $o3b['order_id'] )->refunded_minor && count( $GLOBALS['wpcap_test_mails'] ) > $mails );

// 4. Crash during the provider call: repair_stuck() marks it unknown and plans the reconciliation.
$o4 = a272_order( 'a272e', 1 );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $o4['order_id'], 'idempotency_key' => hash( 'sha256', 'a272-crash' ), 'amount_minor' => 2500, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => wp_json_encode( array( (int) tickets( $o4['order_id'] )[0]->ticket_id ) ), 'reason' => 'x', 'status' => 'processing', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ) );
$id4 = (int) $wpdb->insert_id;
Refunds::repair_stuck();
check( 'A350: a refund stuck without reference is marked unknown (not "needs_manual_check") and reconciled', Refunds::OUTCOME_UNKNOWN === Refunds::get( $id4 )['error_message'] && 1 === a272_jobs( $id4 ) && 1 === a272_audits( 'refund_stuck', $o4['order_id'] ) );

echo "A350: unclear refunds are visible and resolvable\n";
$unresolved = array_map( static fn( $r ) => (int) $r['refund_id'], Refunds::unresolved() );
check( 'A350: the unresolved list holds the stuck refund (with order number and provider)', in_array( $id4, $unresolved, true ) && ! in_array( (int) $row1['refund_id'], $unresolved, true ) && 'stripe' === Refunds::unresolved()[ array_search( $id4, $unresolved, true ) ]['payment_provider'] );
ob_start();
OperationsPage::render_unresolved_refunds();
$html = ob_get_clean();
check( 'A350: the operations page shows it with lookup and manual confirmation', false !== strpos( $html, 'Erstattungen mit unklarem Ausgang' ) && false !== strpos( $html, '#' . $id4 ) && false !== strpos( $html, 'wpcap_resolve_refund' ) && false !== strpos( $html, 'value="not_paid"' ) );
ob_start();
OrdersPage::render_refund_table( array( Refunds::get( $id4 ) ), 'EUR', false );
$html = ob_get_clean();
check( 'A350: the order page marks it "Ausgang unklar" with the same actions', false !== strpos( $html, 'Ausgang unklar' ) && false !== strpos( $html, 'Beim Anbieter prüfen' ) );
// Lookup from the admin: the provider has the refund pending → booked; audited.
http_reset();
http_push( array( 'object' => 'list', 'has_more' => false, 'data' => array( array( 'id' => 're_a272e', 'amount' => 2500, 'status' => 'pending', 'metadata' => array( 'wpcap_refund' => Refunds::key_for( $id4 ) ) ) ) ), 200, '/v1/refunds?payment_intent=pi_a272e' );
$look = OrdersPage::resolve_refund( array( 'refund_id' => $id4, 'do' => 'lookup' ) );
check( 'A350: the admin lookup books a refund the provider has (audited)', ! is_wp_error( $look ) && 'completed' === $look['result'] && 'completed' === Refunds::get( $id4 )['status'] && 1 === a272_audits( 'refund_lookup_requested', $o4['order_id'] ) && 0 === a272_posts() );

// Manual confirmation: approval capability and note required; "paid" books it, "not_paid" fails it.
$o5 = a272_order( 'a272f', 2 );
$t5 = tickets( $o5['order_id'] );
foreach ( array( 'a', 'b' ) as $i => $suffix ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $o5['order_id'], 'idempotency_key' => hash( 'sha256', 'a272-man-' . $suffix ), 'amount_minor' => 2500, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => wp_json_encode( array( (int) $t5[ $i ]->ticket_id ) ), 'reason' => 'x', 'status' => 'processing', 'error_message' => Refunds::OUTCOME_UNKNOWN, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$ids5[ $suffix ] = (int) $wpdb->insert_id;
}
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$denied = Refunds::resolve_manually( $ids5['a'], 'paid', 'Dashboard' );
$GLOBALS['wpcap_test_denied_caps'] = array();
$nonote = OrdersPage::resolve_refund( array( 'refund_id' => $ids5['a'], 'do' => 'paid', 'note' => '' ) );
check( 'A350: manual confirmation needs the approval capability and a note', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] && is_wp_error( $nonote ) && 'wpcap_refund_note' === $nonote->get_error_code() && 'processing' === Refunds::get( $ids5['a'] )['status'] );
$paid = OrdersPage::resolve_refund( array( 'refund_id' => $ids5['a'], 'do' => 'paid', 'note' => 'Im Stripe-Dashboard gesehen', 'reference' => 're_manual_a272' ) );
$notpaid = OrdersPage::resolve_refund( array( 'refund_id' => $ids5['b'], 'do' => 'not_paid', 'note' => 'Stripe zeigt keine Erstattung' ) );
check( 'A350: "paid" books the refund with the given reference', ! is_wp_error( $paid ) && 'completed' === Refunds::get( $ids5['a'] )['status'] && 're_manual_a272' === Refunds::get( $ids5['a'] )['provider_reference'] && 2500 === (int) Orders::get( $o5['order_id'] )->refunded_minor );
check( 'A350: "not paid" fails it (ticket stays valid), both decisions audited with the note', ! is_wp_error( $notpaid ) && 'failed' === Refunds::get( $ids5['b'] )['status'] && 1 === count( tickets( $o5['order_id'], 'valid' ) ) && 2 === a272_audits( 'refund_resolved_manually', $o5['order_id'] ) && false !== strpos( (string) $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='refund_resolved_manually' AND object_id=%d ORDER BY 1 DESC LIMIT 1", $o5['order_id'] ) ), 'Stripe zeigt' ) );
check( 'A350: a settled refund cannot be confirmed again', is_wp_error( Refunds::resolve_manually( $ids5['a'], 'not_paid', 'x' ) ) );

// Adapter without lookup (manual provider): the job hands it to the admin at once (mail, audit).
$o6 = Orders::create( array( 'email' => 'a272-manual@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 10, 10, 1000 ), 'quantity' => 1 ) ), 'idempotency_key' => 'a272-manual' ) );
Orders::confirm_manual_payment( $o6['order_id'] );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $o6['order_id'], 'idempotency_key' => hash( 'sha256', 'a272-man-6' ), 'amount_minor' => 1000, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'reason' => 'x', 'status' => 'processing', 'error_message' => Refunds::OUTCOME_UNKNOWN, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$id6 = (int) $wpdb->insert_id;
$mails = count( $GLOBALS['wpcap_test_mails'] );
$job6 = Refunds::handle_job( false, 'refund_reconcile', array( 'refund_id' => $id6, 'attempt' => 0 ) );
check( 'A350: without a provider lookup the admin is asked to decide (mail, audit, no further run)', true === $job6 && count( $GLOBALS['wpcap_test_mails'] ) === $mails + 1 && 1 === a272_audits( 'refund_reconcile_exhausted', $o6['order_id'] ) && 0 === a272_jobs( $id6 ) && 'processing' === Refunds::get( $id6 )['status'] );
$job6b = Refunds::handle_job( false, 'refund_reconcile', array( 'refund_id' => $id6, 'attempt' => Refunds::RECONCILE_ATTEMPTS - 1 ) );
Refunds::resolve_manually( $id6, 'not_paid', 'Überweisung nie ausgeführt' );
http_reset();
