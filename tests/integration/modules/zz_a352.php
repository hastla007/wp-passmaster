<?php
// phpcs:ignoreFile
/**
 * A352: reconciliation job provider ↔ local payment state. Pending orders whose webhook never arrived
 * are confirmed (or cancelled) from the provider's state; an order that lapsed here but was paid at
 * the provider goes through the late-payment rules. Applied through Webhooks::process() (idempotent),
 * with a backoff per order. HTTP is faked (payments.php helpers), no provider is called.
 */
use PassCaptain\Orders;
use PassCaptain\Payments\Reconcile;
use PassCaptain\Payments\Registry;
use PassCaptain\Settings;

echo "A352: payment reconciliation provider ↔ local state\n";
global $wpdb;
$p = $wpdb->prefix;
$a352_saved = get_option( Settings::OPTION, array() );
Settings::update( array(
	'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test',
	'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1',
) );
Registry::reset();
$a352_type = make_type( 30, 10, 2000 );
$a352_age = static function ( $order_id, $minutes, array $set = array() ) use ( $wpdb, $p ) {
	$wpdb->update( "{$p}wpcap_orders", array_merge( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), $set ), array( 'order_id' => $order_id ) );
};
$a352_session = static fn( $id, $order, $status, $payment_status ) => array( 'id' => $id, 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $payment_status, 'amount_total' => (int) $order['total_minor'], 'currency' => 'eur', 'metadata' => array( 'wpcap_order' => $order['public_id'] ), 'payment_intent' => 'pi_' . substr( $id, 3 ) );
$a352_calls = static fn( $match ) => count( http_requests( $match ) );
check( 'A352: Stripe and PayPal can look up payments', isset( Reconcile::gateways()['stripe'], Reconcile::gateways()['paypal'] ) );

// 1) Stripe: webhook lost, the session is paid → the order is confirmed and tickets issued.
$a352_o1 = provider_order( 'stripe', $a352_type, 2, 'a352-s1-' . uniqid() );
Orders::set_provider_reference( $a352_o1['order_id'], 'cs_test_a352one' );
$a352_age( $a352_o1['order_id'], 20 );
$a352_young = provider_order( 'stripe', $a352_type, 1, 'a352-young-' . uniqid() );
Orders::set_provider_reference( $a352_young['order_id'], 'cs_test_a352young' );
http_reset();
http_push( $a352_session( 'cs_test_a352one', $a352_o1, 'complete', 'paid' ), 200, 'checkout/sessions/cs_test_a352one' );
$a352_run = Reconcile::run();
check( 'A352: a paid session of a pending order is applied (lost webhook)', 'paid' === Orders::get( $a352_o1['order_id'] )->payment_status && 2 === count( tickets( $a352_o1['order_id'], 'valid' ) ) && isset( $a352_run['applied'][ (int) $a352_o1['order_id'] ] ) );
check( 'A352: the reconciliation is audited', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='payment_reconciled' AND object_id=%d", $a352_o1['order_id'] ) ) );
check( 'A352: orders younger than 10 minutes are left to the webhook', 0 === $a352_calls( 'cs_test_a352young' ) && 'pending' === Orders::get( $a352_young['order_id'] )->payment_status );

// 2) Backoff: the next run does not ask about the same order again right away.
http_reset();
Reconcile::run();
check( 'A352: an order is not asked about again before its backoff', 0 === $a352_calls( 'cs_test_a352one' ) );
check( 'A352: the backoff grows with the age of the order', 600 === Reconcile::backoff( 1200 ) && HOUR_IN_SECONDS === Reconcile::backoff( 2 * HOUR_IN_SECONDS ) && 6 * HOUR_IN_SECONDS === Reconcile::backoff( 3 * DAY_IN_SECONDS ) );

// 3) An open session changes nothing; an expired one cancels the pending order.
$a352_o2 = provider_order( 'stripe', $a352_type, 1, 'a352-s2-' . uniqid() );
Orders::set_provider_reference( $a352_o2['order_id'], 'cs_test_a352two' );
$a352_age( $a352_o2['order_id'], 20 );
http_reset();
http_push( $a352_session( 'cs_test_a352two', $a352_o2, 'open', 'unpaid' ), 200, 'checkout/sessions/cs_test_a352two' );
Reconcile::run();
check( 'A352: an open payment leaves the order pending', 1 === $a352_calls( 'cs_test_a352two' ) && 'pending' === Orders::get( $a352_o2['order_id'] )->payment_status );
delete_transient( Reconcile::NEXT_PREFIX . (int) $a352_o2['order_id'] );
http_reset();
http_push( $a352_session( 'cs_test_a352two', $a352_o2, 'expired', 'unpaid' ), 200, 'checkout/sessions/cs_test_a352two' );
Reconcile::run();
check( 'A352: an expired session cancels the pending order', 'pending' !== Orders::get( $a352_o2['order_id'] )->payment_status && ! tickets( $a352_o2['order_id'], 'valid' ) );

// 4) Lapsed here, paid at the provider: the late-payment rules decide (no silent loss of the payment).
$a352_o3 = provider_order( 'stripe', $a352_type, 1, 'a352-s3-' . uniqid() );
Orders::set_provider_reference( $a352_o3['order_id'], 'cs_test_a352three' );
expire_holds_of( $a352_o3['order_id'] );
Orders::expire_holds();
$a352_age( $a352_o3['order_id'], 90 );
$a352_before = Orders::get( $a352_o3['order_id'] )->payment_status;
http_reset();
http_push( $a352_session( 'cs_test_a352three', $a352_o3, 'complete', 'paid' ), 200, 'checkout/sessions/cs_test_a352three' );
Reconcile::run();
$a352_after = Orders::get( $a352_o3['order_id'] );
check( 'A352: a payment for an order that lapsed here is found and booked (late payment)', 'expired' === $a352_before && in_array( $a352_after->payment_status, array( 'paid', 'paid_unallocated' ), true ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='payment_reconciled' AND object_id=%d AND details LIKE %s", $a352_o3['order_id'], '%"local_status":"expired"%' ) ) );

// 5) PayPal: the PayPal order was completed (capture) but the webhook never came.
$a352_o4 = provider_order( 'paypal', $a352_type, 1, 'a352-pp-' . uniqid() );
Orders::set_provider_reference( $a352_o4['order_id'], 'A352PPORDER1' );
$a352_age( $a352_o4['order_id'], 30 );
http_reset();
http_push( array( 'access_token' => 'A21-a352', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'A352PPORDER1', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'custom_id' => $a352_o4['public_id'], 'payments' => array( 'captures' => array( array( 'id' => 'CAPA352ONE', 'status' => 'COMPLETED', 'custom_id' => $a352_o4['public_id'], 'amount' => array( 'value' => '20.00', 'currency_code' => 'EUR' ), 'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'A352PPORDER1' ) ) ) ) ) ) ) ), 200, '/v2/checkout/orders/A352PPORDER1' );
Reconcile::run();
check( 'A352: a completed PayPal capture of a pending order is applied', 'paid' === Orders::get( $a352_o4['order_id'] )->payment_status && 1 === count( tickets( $a352_o4['order_id'], 'valid' ) ) );

// 6) A lookup failure changes nothing and is retried after the backoff.
$a352_o5 = provider_order( 'stripe', $a352_type, 1, 'a352-s5-' . uniqid() );
Orders::set_provider_reference( $a352_o5['order_id'], 'cs_test_a352five' );
$a352_age( $a352_o5['order_id'], 20 );
http_reset();
http_push( array( 'error' => array( 'message' => 'down' ) ), 500, 'checkout/sessions/cs_test_a352five' );
$a352_err = Reconcile::run();
check( 'A352: a failed lookup leaves the order pending and is counted', 'pending' === Orders::get( $a352_o5['order_id'] )->payment_status && $a352_err['errors'] >= 1 && 0 < (int) get_transient( Reconcile::NEXT_PREFIX . (int) $a352_o5['order_id'] ) );

// 7) Mollie: the same mapping as the webhook (same event id, so a later webhook is a duplicate).
Settings::update( array( 'mollie_api_key' => 'test_a352a352a352a352a352a352a352a3' ) );
http_reset();
http_push( array( 'id' => 'tr_A352mollie', 'status' => 'paid', 'amount' => array( 'value' => '20.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => 'a352-not-a-real-order' ) ), 200, 'payments/tr_A352mollie' );
$a352_m = ( new PassCaptain\Mollie\MollieGateway() )->find_payment( array( 'provider_reference' => 'tr_A352mollie' ) );
check( 'A352: Mollie maps the fetched payment like the webhook', is_array( $a352_m ) && 'paid' === $a352_m['type'] && 'mollie-tr_A352mollie-paid' === $a352_m['event_id'] && 2000 === (int) $a352_m['amount_minor'] && false !== strpos( http_requests( 'payments/tr_A352mollie' )[0]['url'] ?? '', 'embed=refunds' ) );
check( 'A352: a reference that is not a Mollie payment is not looked up', null === ( new PassCaptain\Mollie\MollieGateway() )->find_payment( array( 'provider_reference' => 'cs_other' ) ) );

update_option( Settings::OPTION, $a352_saved );
Settings::flush();
Registry::reset();
http_reset();
