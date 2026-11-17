<?php
/**
 * R5 (audit round 2, expert #4): payment and refund residuals.
 *
 * Included from run.php (uses check(), make_type(), tickets(), http fakes from payments.php:
 * http_reset(), http_push(), http_requests(), stripe_deliver()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
Settings::update( array( 'stripe_secret_key' => 'sk_test_r5', 'stripe_webhook_secret' => 'whsec_test', 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
Registry::reset();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

/** Paid order of $provider (reference $reference), $qty tickets of $price. */
function r5p_paid( $provider, $reference, $qty = 2, $price = 5000 ) {
	global $wpdb;
	$type = make_type( 50, 10, $price );
	$r = Orders::create( array( 'email' => 'r5p-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'r5p-' . uniqid( '', true ) ), array( 'payment_provider' => $provider, 'skip_rate_limit' => true, 'hold_minutes' => 40 ) );
	$order = Orders::get( $r['order_id'] );
	$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'provider_reference' => $reference ), array( 'order_id' => (int) $order->order_id ) );
	Webhooks::process( $provider, array( 'event_id' => 'r5p-paid-' . uniqid(), 'type' => 'paid', 'order_public_id' => $order->public_id, 'provider_reference' => $reference, 'amount_minor' => $qty * $price, 'currency' => 'EUR' ) );
	return Orders::get( $r['order_id'] );
}
/** Pending order of $provider with $reference (hold active). */
function r5p_pending( $provider, $reference, $qty = 1, $price = 5000 ) {
	global $wpdb;
	$type = make_type( 50, 10, $price );
	$r = Orders::create( array( 'email' => 'r5p-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'r5p-' . uniqid( '', true ) ), array( 'payment_provider' => $provider, 'skip_rate_limit' => true, 'hold_minutes' => 40 ) );
	if ( '' !== $reference ) {
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'provider_reference' => $reference ), array( 'order_id' => (int) $r['order_id'] ) );
	}
	return Orders::get( $r['order_id'] );
}
function r5p_charge( $order, $refunded, $id = 'ch_r5' ) {
	// The charge belongs to the order's booked payment intent (A640: refunds of another payment of the order are not booked on it).
	return array( 'id' => $id, 'object' => 'charge', 'amount' => (int) $order->total_minor, 'amount_refunded' => $refunded, 'refunded' => false, 'currency' => 'eur', 'payment_intent' => (string) $order->provider_reference, 'metadata' => array( 'wpcap_order' => $order->public_id ) );
}

echo "R5: Stripe dashboard partial refunds are all booked (paginated refund list)\n";
$r5_stripe = new StripeGateway();
$r5_o = r5p_paid( 'stripe', 'pi_r5_1' );
http_reset();
// State at processing time: re_2 (20.00, newest) and re_1 (10.00); both webhooks delivered afterwards.
$r5_list = array( 'data' => array( array( 'id' => 're_2', 'amount' => 2000, 'status' => 'succeeded', 'metadata' => array() ), array( 'id' => 're_1', 'amount' => 1000, 'status' => 'succeeded', 'metadata' => array() ) ), 'has_more' => false );
http_push( $r5_list, 200, 'refunds?' );
http_push( $r5_list, 200, 'refunds?' );
$r5_e1 = stripe_deliver( $r5_stripe, array( 'id' => 'evt_r5_r1', 'type' => 'charge.refunded', 'data' => array( 'object' => r5p_charge( $r5_o, 1000 ) ) ) );
$r5_e2 = stripe_deliver( $r5_stripe, array( 'id' => 'evt_r5_r2', 'type' => 'charge.refunded', 'data' => array( 'object' => r5p_charge( $r5_o, 3000 ) ) ) );
$r5_fresh = Orders::get( $r5_o->order_id );
// Merged design (A346/A347): charge.refunded is a cumulative snapshot of the charge's external refunds; only
// the part not yet booked for the charge is booked (labels partial_refund / already_booked).
check( 'R5-2: two dashboard refunds (10 + 20) book 30.00, the retry books nothing twice', 3000 === (int) $r5_fresh->refunded_minor && 'partial_refund' === $r5_e1 && 'already_booked' === $r5_e2 );
$r5_refs = $wpdb->get_results( $wpdb->prepare( "SELECT provider_reference,amount_minor FROM %i WHERE order_id=%d AND status='completed' ORDER BY refund_id", $p . 'wpcap_refunds', $r5_o->order_id ) );
check( 'R5-2: the dashboard refunds of the charge are booked once against the charge (cumulative, 30.00)', 1 === count( $r5_refs ) && 'ch_r5' === $r5_refs[0]->provider_reference && 3000 === (int) $r5_refs[0]->amount_minor );
// The newest refund is a plugin refund: the older dashboard refund is still booked.
$r5_o2 = r5p_paid( 'stripe', 'pi_r5_2' );
http_reset();
http_push( array( 'data' => array( array( 'id' => 're_4', 'amount' => 500, 'metadata' => array( 'wpcap_refund' => 'k' ) ), array( 'id' => 're_3', 'amount' => 1000, 'metadata' => array() ) ), 'has_more' => false ), 200, 'refunds?' );
$r5_e3 = stripe_deliver( $r5_stripe, array( 'id' => 'evt_r5_r3', 'type' => 'charge.refunded', 'data' => array( 'object' => r5p_charge( $r5_o2, 1500, 'ch_r5_2' ) ) ) );
check( 'R5-2: a newer plugin refund does not hide an older dashboard refund', 'partial_refund' === $r5_e3 && 1000 === (int) Orders::get( $r5_o2->order_id )->refunded_minor );
// Pagination: has_more → starting_after.
$r5_o3 = r5p_paid( 'stripe', 'pi_r5_3' );
http_reset();
http_push( array( 'data' => array( array( 'id' => 're_6', 'amount' => 700, 'metadata' => array() ) ), 'has_more' => true ), 200, 'refunds?' );
http_push( array( 'data' => array( array( 'id' => 're_5', 'amount' => 300, 'metadata' => array() ), array( 'id' => 're_x', 'amount' => 900, 'status' => 'failed', 'metadata' => array() ) ), 'has_more' => false ), 200, 'starting_after=re_6' );
$r5_e4 = stripe_deliver( $r5_stripe, array( 'id' => 'evt_r5_r4', 'type' => 'charge.refunded', 'data' => array( 'object' => r5p_charge( $r5_o3, 1000, 'ch_r5_3' ) ) ) );
check( 'R5-2: refund list is paginated (starting_after), failed refunds are skipped', 'partial_refund' === $r5_e4 && 1000 === (int) Orders::get( $r5_o3->order_id )->refunded_minor && 2 === count( http_requests( 'refunds?' ) ) );

echo "R5: provider refunds that fail later are reversed (Stripe refund.failed, Mollie refund failed)\n";
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Payments\Cases;
use PassCaptain\Payments\Reconcile;
Cases::register();
Reconcile::register();
Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Ab1', 10 ), 'auto_refund_unmatched_payments' => 0 ) );
Registry::reset();
$r5_o8 = r5p_paid( 'stripe', 'pi_r5_8', 1, 4000 );
$r5_inv8 = PassCaptain\Invoices::issue_for_order( $r5_o8->order_id );
http_reset();
http_push( array( 'id' => 're_p8', 'object' => 'refund', 'status' => 'pending', 'amount' => 4000 ), 200, '/v1/refunds' );
$r5_ref8 = PassCaptain\Refunds::request( $r5_o8->order_id, array(), 'R5 test', 'r5-ref8' );
PassCaptain\Invoices::credit_note_for_refund( is_array( $r5_ref8 ) ? (int) $r5_ref8['refund_id'] : 0 );
$r5_mails8 = count( $GLOBALS['wpcap_test_mails'] );
check( 'R5-8: precondition – pending Stripe refund is booked (order refunded)', is_array( $r5_ref8 ) && 'completed' === $r5_ref8['status'] && 'refunded' === Orders::get( $r5_o8->order_id )->status );
// Merged design: a plugin refund (metadata wpcap_refund = its provider key) that fails later is settled through
// Refunds::apply_provider_states() (A348): the refund becomes "reversed" (provider status failed), the credit
// note gets a reversal document, the admin is alerted and can pay out again; the order is paid again.
$r5_key8 = PassCaptain\Refunds::provider_key( is_array( $r5_ref8 ) ? (int) $r5_ref8['refund_id'] : 0 );
$r5_fail_event = array( 'id' => 'evt_r5_rf', 'type' => 'charge.refund.updated', 'data' => array( 'object' => array( 'id' => 're_p8', 'object' => 'refund', 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card', 'amount' => 4000, 'currency' => 'eur', 'payment_intent' => 'pi_r5_8', 'metadata' => array( 'wpcap_order' => $r5_o8->public_id, 'wpcap_refund' => $r5_key8 ) ) ) );
$r5_res8 = stripe_deliver( $r5_stripe, $r5_fail_event );
$r5_after8 = Orders::get( $r5_o8->order_id );
$r5_row8 = PassCaptain\Refunds::get( (int) $r5_ref8['refund_id'] );
check( 'R5-8: Stripe charge.refund.updated (failed) reverses the booking: refund reversed, refunded amount 0, order paid again', ! is_wp_error( $r5_res8 ) && 'reversed' === $r5_row8['status'] && 'failed' === $r5_row8['provider_status'] && 0 === (int) $r5_after8->refunded_minor && 'paid' === $r5_after8->payment_status && 'complete' === $r5_after8->status );
check( 'R5-8: tickets stay revoked, admin alert, the reversal is audited and can be paid out again', array() === tickets( $r5_o8->order_id, 'valid' ) && count( $GLOBALS['wpcap_test_mails'] ) > $r5_mails8 && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT audit_id FROM %i WHERE object_type='order' AND object_id=%d AND action='refund_reversed'", $p . 'wpcap_audit_logs', $r5_o8->order_id ) ) );
$r5_cn8 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d', $p . 'wpcap_credit_notes', (int) $r5_ref8['refund_id'] ) );
$r5_today8 = gmdate( 'Y-m-d' );
$r5_storno = array_values( array_filter( PassCaptain\Invoices::export_rows( $r5_today8, $r5_today8 ), static fn( $r ) => $r5_cn8 && '' !== (string) $r5_cn8->reversal_number && (string) $r[1] === (string) $r5_cn8->reversal_number ) );
check( 'R5-8: the credit note is reversed (reversal document in the print view and the export, positive amount)', $r5_cn8 && '' !== (string) $r5_cn8->reversal_number && false !== strpos( PassCaptain\Invoices::render_html( $r5_inv8['invoice_id'] ), 'Storniert durch Beleg' ) && 1 === count( $r5_storno ) && '40.00' === $r5_storno[0][10] );
$r5_failed_ledger = array_values( array_filter( PassCaptain\Invoices::ledger_export_rows( $r5_today8, $r5_today8 ), static fn( $r ) => 'Erstattung fehlgeschlagen' === $r[0] && (int) $r[4] === (int) $r5_o8->order_id ) );
check( 'R5-8: ledger export books the failed refund back (positive amount)', 1 === count( $r5_failed_ledger ) && '40.00' === $r5_failed_ledger[0][11] );
check( 'R5-8: redelivery changes nothing', 'duplicate' === stripe_deliver( $r5_stripe, $r5_fail_event ) && 0 === (int) Orders::get( $r5_o8->order_id )->refunded_minor );
$r5_pending_upd = $r5_fail_event;
$r5_pending_upd['id'] = 'evt_r5_rf2';
$r5_pending_upd['data']['object']['status'] = 'succeeded';
check( 'R5-8: a refund update that did not fail is ignored', 'ignored' === stripe_deliver( $r5_stripe, $r5_pending_upd ) );
// Mollie: plugin refund (re_ id) reported as failed in the embedded refunds.
$r5_m8 = r5p_paid( 'mollie', 'tr_r5m8aaaa', 1, 3000 );
http_reset();
http_push( array( 'resource' => 'refund', 'id' => 're_r5m8aaaa', 'status' => 'queued' ), 201, '/refunds' );
$r5_mref = PassCaptain\Refunds::request( $r5_m8->order_id, array(), 'R5 Mollie', 'r5-mref' );
$r5_mevent = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5m8aaaa', 'status' => 'paid', 'amount' => array( 'value' => '30.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_m8->public_id ), '_embedded' => array( 'refunds' => array( array( 'id' => 're_r5m8aaaa', 'status' => 'failed', 'amount' => array( 'value' => '30.00', 'currency' => 'EUR' ), 'metadata' => array( 'wpcap_refund' => 'k' ) ) ) ) ) );
$r5_mres = Webhooks::process( 'mollie', $r5_mevent );
check( 'R5-8: Mollie refund with status failed is normalized as refund_failed and reversed', is_array( $r5_mref ) && 'completed' === $r5_mref['status'] && 'refund_failed' === $r5_mevent['type'] && 'refund_failed' === $r5_mres && 0 === (int) Orders::get( $r5_m8->order_id )->refunded_minor );
$r5_mevent2 = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5m8aaaa', 'status' => 'paid', 'amount' => array( 'value' => '30.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_m8->public_id ), '_embedded' => array( 'refunds' => array( array( 'id' => 're_r5m8aaaa', 'status' => 'failed', 'amount' => array( 'value' => '30.00', 'currency' => 'EUR' ), 'metadata' => array( 'wpcap_refund' => 'k' ) ) ) ) ) );
check( 'R5-8: once reversed, the failed Mollie refund is no longer reported', 'refund_failed' !== $r5_mevent2['type'] );

echo "R5: delayed methods outside Stripe keep the hold (Mollie open/pending, PayPal CAPTURE.PENDING)\n";
/** Remaining hold of an order in minutes. */
function r5p_hold_minutes( $order_id ) {
	global $wpdb;
	$until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM %i WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', $order_id ) );
	return '' === $until ? 0 : (int) round( ( strtotime( $until . ' UTC' ) - time() ) / 60 );
}
$r5_mp = r5p_pending( 'mollie', 'tr_r5mpaaaa' );
http_reset();
http_push( array( 'id' => 'tr_r5mpaaaa' ), 200, 'payments/tr_r5mpaaaa' );
$r5_mpe = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5mpaaaa', 'status' => 'open', 'method' => 'banktransfer', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_mp->public_id ) ) );
$r5_mpr = Webhooks::process( 'mollie', $r5_mpe );
$r5_patch = http_requests( 'payments/tr_r5mpaaaa' );
// Merged design (A577): a delayed method extends the hold like a bank transfer hold (manual_hold_hours).
$r5_delayed_minutes = (int) Settings::get( 'manual_hold_hours' ) * 60;
check( 'R5-9: Mollie open bank transfer → payment_pending, hold extended like a bank transfer hold', 'payment_pending' === $r5_mpe['type'] && 'hold_extended' === $r5_mpr && abs( r5p_hold_minutes( $r5_mp->order_id ) - $r5_delayed_minutes ) <= 2 );
check( 'R5-9: the bank transfer due date is moved to the hold end (PATCH dueDate)', $r5_patch && 'PATCH' === $r5_patch[0]['method'] && gmdate( 'Y-m-d', PassCaptain\Orders::hold_end( $r5_mp->order_id ) ) === ( json_decode( (string) $r5_patch[0]['body'], true )['dueDate'] ?? '' ) );
$r5_dd = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5mpbbbb', 'status' => 'pending', 'method' => 'directdebit', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => 'x' ) ) );
$r5_cc = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5mpcccc', 'status' => 'open', 'method' => 'creditcard', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => 'x' ) ) );
check( 'R5-9: direct debit pending is delayed, an open card payment is not', 'payment_pending' === $r5_dd['type'] && 'ignored' === $r5_cc['type'] );
$r5_pp = r5p_pending( 'paypal', 'PPR5ORDER1' );
$r5_ppe = array( 'id' => 'WH-R5-PENDING', 'event_type' => 'PAYMENT.CAPTURE.PENDING', 'resource' => array( 'id' => 'CAPR5', 'status' => 'PENDING', 'custom_id' => $r5_pp->public_id, 'amount' => array( 'value' => '50.00', 'currency_code' => 'EUR' ), 'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'PPR5ORDER1' ) ) ) );
$r5_ppn = ( new ReflectionMethod( PassCaptain\Payments\PayPalGateway::class, 'normalize' ) );
$r5_ppn->setAccessible( true );
$r5_ppev = $r5_ppn->invoke( null, $r5_ppe );
$r5_ppr = Webhooks::process( 'paypal', $r5_ppev );
check( 'R5-9: PayPal PAYMENT.CAPTURE.PENDING → payment_pending with its own event id, hold extended', 'payment_pending' === $r5_ppev['type'] && 'capture-CAPR5-pending' === $r5_ppev['event_id'] && 'hold_extended' === $r5_ppr && abs( r5p_hold_minutes( $r5_pp->order_id ) - $r5_delayed_minutes ) <= 2 );

echo "R5: Klarna/Riverty via Mollie – authorized payments are captured\n";
$r5_k = r5p_pending( 'mollie', 'tr_r5klaaaa' );
http_reset();
http_push( array( 'resource' => 'capture', 'id' => 'cpt_r5' ), 201, 'payments/tr_r5klaaaa/captures' );
$r5_ke = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5klaaaa', 'status' => 'authorized', 'method' => 'klarna', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_k->public_id ) ) );
$r5_kr = Webhooks::process( 'mollie', $r5_ke );
$r5_kcap = http_requests( 'payments/tr_r5klaaaa/captures' );
check( 'R5-10: Mollie authorized (Klarna) is captured right away (idempotency key per payment), order stays pending', 'payment_authorized' === $r5_ke['type'] && 'capture_requested' === $r5_kr && 1 === count( $r5_kcap ) && 'POST' === $r5_kcap[0]['method'] && 'wpcap-capture-tr_r5klaaaa' === ( $r5_kcap[0]['headers']['Idempotency-Key'] ?? '' ) && 'pending' === Orders::get( $r5_k->order_id )->payment_status );
http_reset();
http_push( array( 'detail' => 'down' ), 503, 'payments/tr_r5klbbbb/captures' );
$r5_k2 = r5p_pending( 'mollie', 'tr_r5klbbbb' );
$r5_ke2 = MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5klbbbb', 'status' => 'authorized', 'method' => 'riverty', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_k2->public_id ) ) );
$r5_kr2 = Webhooks::process( 'mollie', $r5_ke2 );
check( 'R5-10: a failed capture is retried by Mollie (5xx, event released)', is_wp_error( $r5_kr2 ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE provider=%s AND provider_event_id=%s', $p . 'wpcap_payment_events', 'mollie', $r5_ke2['event_id'] ) ) );
$r5_kpaid = Webhooks::process( 'mollie', MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_r5klaaaa', 'status' => 'paid', 'method' => 'klarna', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_k->public_id ) ) ) );
check( 'R5-10: the capture\'s paid webhook confirms the order', 'paid' === $r5_kpaid && 'paid' === Orders::get( $r5_k->order_id )->payment_status );

echo "R5: cancellation voids open provider sessions; double payments become payment cases\n";
$r5_v = r5p_pending( 'stripe', 'cs_r5void' );
Orders::cancel_order( $r5_v->order_id, 'admin_cancel' );
http_reset();
http_push( array( 'id' => 'cs_r5void', 'status' => 'expired' ), 200, 'checkout/sessions/cs_r5void/expire' );
PassCaptain\Jobs::process_due( 50 );
check( 'R5-11: cancelling an order expires its open Stripe Checkout Session (job)', 1 === count( http_requests( 'checkout/sessions/cs_r5void/expire' ) ) );
$r5_vm = r5p_pending( 'mollie', 'tr_r5voidaa' );
Orders::cancel_order( $r5_vm->order_id, 'admin_cancel' );
http_reset();
http_push( array( 'id' => 'tr_r5voidaa', 'status' => 'open', 'isCancelable' => true ), 200, 'payments/tr_r5voidaa' );
http_push( array( 'id' => 'tr_r5voidaa', 'status' => 'canceled' ), 200, 'payments/tr_r5voidaa' );
PassCaptain\Jobs::process_due( 50 );
$r5_vmreq = http_requests( 'payments/tr_r5voidaa' );
check( 'R5-11: cancelling a Mollie order deletes the cancelable payment', 2 === count( $r5_vmreq ) && 'DELETE' === $r5_vmreq[1]['method'] );
$r5_vf = r5p_pending( 'stripe', 'cs_r5failed' );
Webhooks::process( 'stripe', array( 'event_id' => 'evt_r5_vf', 'type' => 'failed', 'order_public_id' => $r5_vf->public_id, 'provider_reference' => 'cs_r5failed', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
http_reset();
PassCaptain\Jobs::process_due( 50 );
check( 'R5-11: no void call when the provider itself failed the payment', 'cancelled' === Orders::get( $r5_vf->order_id )->status && array() === http_requests( 'checkout/sessions/cs_r5failed' ) );
// Payment for the cancelled order → case; automatic refund only with the setting.
$r5_late = Webhooks::process( 'stripe', array( 'event_id' => 'evt_r5_late', 'type' => 'paid', 'order_public_id' => $r5_v->public_id, 'provider_reference' => 'pi_r5late', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
$r5_case = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND kind='paid_for_closed_order'", $p . 'wpcap_payment_cases', $r5_v->order_id ) );
check( 'R5-11: payment for a cancelled order is listed as a payment case (system status), no automatic refund by default', 'paid_for_closed_order' === $r5_late && $r5_case && 'open' === $r5_case->status && 'pi_r5late' === $r5_case->reference && 1 <= PassCaptain\Diagnostics::run( array( 'payment_cases' ) )['checks']['payment_cases']['count'] );
$r5_dp = r5p_paid( 'stripe', 'pi_r5dp1', 1, 5000 );
Settings::update( array( 'auto_refund_unmatched_payments' => 1 ) );
$r5_dpr = Webhooks::process( 'stripe', array( 'event_id' => 'evt_r5_dp2', 'type' => 'paid', 'order_public_id' => $r5_dp->public_id, 'provider_reference' => 'pi_r5dp2', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
http_reset();
http_push( array( 'id' => 're_case', 'object' => 'refund', 'status' => 'succeeded' ), 200, '/v1/refunds' );
PassCaptain\Jobs::process_due( 50 );
Settings::update( array( 'auto_refund_unmatched_payments' => 0 ) );
$r5_dpcase = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND kind='double_payment'", $p . 'wpcap_payment_cases', $r5_dp->order_id ) );
$r5_dpreq = http_requests( '/v1/refunds' );
parse_str( (string) ( $r5_dpreq[0]['body'] ?? '' ), $r5_dpbody );
check( 'R5-11: with the setting a double payment is refunded automatically (second intent, own key), the order\'s payment stays untouched', 'double_payment' === $r5_dpr && $r5_dpcase && 'refunded' === $r5_dpcase->status && 'pi_r5dp2' === ( $r5_dpbody['payment_intent'] ?? '' ) && 'wpcap-case-' . $r5_dpcase->case_id === ( $r5_dpreq[0]['headers']['Idempotency-Key'] ?? '' ) && 0 === (int) Orders::get( $r5_dp->order_id )->refunded_minor );
$r5_case_refund_event = Webhooks::process( 'stripe', array( 'event_id' => 'evt_r5_dp_ref', 'type' => 'refunded', 'refund_scope' => 'full', 'order_public_id' => $r5_dp->public_id, 'provider_reference' => 'ch_r5dp2', 'related_references' => array( 'pi_r5dp2' ), 'amount_minor' => 5000, 'currency' => 'EUR' ) );
check( 'R5-11: the refund webhook of the case payment is not booked on the order', 'payment_case_refund' === $r5_case_refund_event && 'paid' === Orders::get( $r5_dp->order_id )->payment_status && 1 === count( tickets( $r5_dp->order_id, 'valid' ) ) );
check( 'R5-11: a case can be marked as resolved', true === Cases::resolve( (int) $r5_case->case_id, 'manuell erstattet' ) && 'resolved' === Cases::get( (int) $r5_case->case_id )->status );

echo "R5: status poll for pending orders without webhook\n";
$r5_poll = r5p_pending( 'stripe', 'cs_r5poll' );
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ), array( 'order_id' => $r5_poll->order_id ) );
$r5_young = r5p_pending( 'stripe', 'cs_r5young' );
// Pending leftovers of earlier tests must not use up the poll limit of this run.
$wpdb->query( $wpdb->prepare( "UPDATE %i SET created_at=%s WHERE payment_status='pending' AND order_id<>%d AND provider_reference<>'' AND payment_provider IN ('stripe','mollie','paypal')", $p . 'wpcap_orders', gmdate( 'Y-m-d H:i:s' ), $r5_poll->order_id ) );
wpcap_test_reset_transients();
http_reset();
http_push( array( 'id' => 'cs_r5poll', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 5000, 'currency' => 'eur', 'payment_intent' => 'pi_r5poll', 'metadata' => array( 'wpcap_order' => $r5_poll->public_id ) ), 200, 'checkout/sessions/cs_r5poll' );
// Merged design: providers with a payment lookup (Stripe, Mollie: PaymentLookupInterface) are reconciled by
// Reconcile::run() (A352, orders older than MIN_AGE); poll_pending() only covers gateways without it.
// Closed leftovers of earlier tests (also checked by run()) must not use up the per-run limit either.
$wpdb->query( $wpdb->prepare( "UPDATE %i SET updated_at=%s WHERE payment_status IN ('expired','cancelled') AND provider_reference<>''", $p . 'wpcap_orders', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
$r5_recon = Reconcile::run();
check( 'R5-12: a pending order older than 10 minutes is reconciled (Reconcile::run) and confirmed through Webhooks::process', isset( $r5_recon['applied'][ (int) $r5_poll->order_id ] ) && 'paid' === Orders::get( $r5_poll->order_id )->payment_status && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE provider=%s AND provider_event_id=%s', $p . 'wpcap_payment_events', 'stripe', 'reconcile-cs_r5poll-complete-paid' ) ) );
check( 'R5-12: young orders are not polled', array() === http_requests( 'cs_r5young' ) );
$r5_late_webhook = Webhooks::process( 'stripe', array( 'event_id' => 'evt_r5_poll_late', 'type' => 'paid', 'order_public_id' => $r5_poll->public_id, 'provider_reference' => 'pi_r5poll', 'related_references' => array( 'cs_r5poll', 'pi_r5poll' ), 'amount_minor' => 5000, 'currency' => 'EUR' ) );
check( 'R5-12: the late webhook afterwards is harmless (already paid)', 'already_paid' === $r5_late_webhook && 1 === count( tickets( $r5_poll->order_id, 'valid' ) ) );
$r5_mpoll = r5p_pending( 'mollie', 'tr_r5pollaa' );
http_reset();
http_push( array( 'resource' => 'payment', 'id' => 'tr_r5pollaa', 'status' => 'paid', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $r5_mpoll->public_id ) ), 200, 'payments/tr_r5pollaa' );
check( 'R5-12: Mollie poll uses the webhook event id (mollie-{id}-paid)', 'paid' === Reconcile::poll_order( $r5_mpoll->order_id ) && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE provider=%s AND provider_event_id=%s', $p . 'wpcap_payment_events', 'mollie', 'mollie-tr_r5pollaa-paid' ) ) );
