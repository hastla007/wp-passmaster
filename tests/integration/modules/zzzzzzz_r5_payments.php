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
	return array( 'id' => $id, 'object' => 'charge', 'amount' => (int) $order->total_minor, 'amount_refunded' => $refunded, 'refunded' => false, 'currency' => 'eur', 'payment_intent' => 'pi_r5', 'metadata' => array( 'wpcap_order' => $order->public_id ) );
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
check( 'R5-2: two dashboard refunds (10 + 20) book 30.00, the retry books nothing twice', 3000 === (int) $r5_fresh->refunded_minor && 'partial_refund_booked' === $r5_e1 && 'refund_already_booked' === $r5_e2 );
$r5_refs = $wpdb->get_col( $wpdb->prepare( "SELECT provider_reference FROM %i WHERE order_id=%d AND status='completed' ORDER BY refund_id", $p . 'wpcap_refunds', $r5_o->order_id ) );
check( 'R5-2: each Stripe refund is its own ledger row (oldest first)', array( 'ext:stripe:re_1', 'ext:stripe:re_2' ) === $r5_refs );
// The newest refund is a plugin refund: the older dashboard refund is still booked.
$r5_o2 = r5p_paid( 'stripe', 'pi_r5_2' );
http_reset();
http_push( array( 'data' => array( array( 'id' => 're_4', 'amount' => 500, 'metadata' => array( 'wpcap_refund' => 'k' ) ), array( 'id' => 're_3', 'amount' => 1000, 'metadata' => array() ) ), 'has_more' => false ), 200, 'refunds?' );
$r5_e3 = stripe_deliver( $r5_stripe, array( 'id' => 'evt_r5_r3', 'type' => 'charge.refunded', 'data' => array( 'object' => r5p_charge( $r5_o2, 1500, 'ch_r5_2' ) ) ) );
check( 'R5-2: a newer plugin refund does not hide an older dashboard refund', 'partial_refund_booked' === $r5_e3 && 1000 === (int) Orders::get( $r5_o2->order_id )->refunded_minor );
// Pagination: has_more → starting_after.
$r5_o3 = r5p_paid( 'stripe', 'pi_r5_3' );
http_reset();
http_push( array( 'data' => array( array( 'id' => 're_6', 'amount' => 700, 'metadata' => array() ) ), 'has_more' => true ), 200, 'refunds?' );
http_push( array( 'data' => array( array( 'id' => 're_5', 'amount' => 300, 'metadata' => array() ), array( 'id' => 're_x', 'amount' => 900, 'status' => 'failed', 'metadata' => array() ) ), 'has_more' => false ), 200, 'starting_after=re_6' );
$r5_e4 = stripe_deliver( $r5_stripe, array( 'id' => 'evt_r5_r4', 'type' => 'charge.refunded', 'data' => array( 'object' => r5p_charge( $r5_o3, 1000, 'ch_r5_3' ) ) ) );
check( 'R5-2: refund list is paginated (starting_after), failed refunds are skipped', 'partial_refund_booked' === $r5_e4 && 1000 === (int) Orders::get( $r5_o3->order_id )->refunded_minor && 2 === count( http_requests( 'refunds?' ) ) );
