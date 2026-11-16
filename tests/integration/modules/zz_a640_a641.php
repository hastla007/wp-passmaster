<?php
/**
 * A640: a refunded/chargeback event of another payment of the order (double payment) books nothing.
 * A641: PayPal captures an approved order only for a known, payable order; Stripe sessions map to
 * orders only through metadata.wpcap_order. HTTP is faked (payments.php helpers), no provider is called.
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a640_saved = get_option( Settings::OPTION, array() );
Settings::update( array(
	'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test',
	'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1',
	'refund_approval_threshold' => 0,
) );
Registry::reset();
$a640_stripe = Registry::get( 'stripe' );
$a640_paypal = Registry::get( 'paypal' );
$a640_type = make_type( 30, 10, 2000 );
// Fresh OAuth token for every faked PayPal call sequence (the token cache may be empty).
$a640_token = static function () { http_push( array( 'access_token' => 'A21-a640', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' ); };
$a640_refunds = static fn( $order_id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $order_id ) );

echo "A640: refunds of another payment of the order\n";
http_reset();
$so = provider_order( 'stripe', $a640_type, 1, 'a640-stripe-' . uniqid() );
$paid = Webhooks::process( 'stripe', array( 'event_id' => 'evt_a640_paid_' . uniqid(), 'type' => 'paid', 'order_public_id' => $so['public_id'], 'provider_reference' => 'pi_a640_first', 'related_references' => array( 'pi_a640_first' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
$double = Webhooks::process( 'stripe', array( 'event_id' => 'evt_a640_double_' . uniqid(), 'type' => 'paid', 'order_public_id' => $so['public_id'], 'provider_reference' => 'pi_a640_second', 'related_references' => array( 'pi_a640_second' ), 'amount_minor' => 2000, 'currency' => 'EUR' ) );
check( 'A640: setup – paid once, the second payment is a double payment', 'paid' === $paid && 'double_payment' === $double && 'pi_a640_first' === Orders::get( $so['order_id'] )->provider_reference );
$charge = static fn( $id, $intent ) => array( 'id' => $id, 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_' . $id, 'object' => 'charge', 'amount' => 2000, 'amount_refunded' => 2000, 'refunded' => true, 'currency' => 'eur', 'payment_intent' => $intent, 'metadata' => array( 'wpcap_order' => $so['public_id'] ), 'refunds' => array( 'data' => array( array( 'id' => 're_' . $id, 'amount' => 2000, 'status' => 'succeeded', 'metadata' => array() ) ), 'has_more' => false ) ) ) );
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$other = stripe_deliver( $a640_stripe, $charge( 'evt_a640_r1_' . uniqid(), 'pi_a640_second' ) );
$order = Orders::get( $so['order_id'] );
check( 'A640: refunding the double payment does not refund the order or revoke its tickets', 'refund_other_payment' === $other && 'complete' === $order->status && 'paid' === $order->payment_status && 1 === count( tickets( $so['order_id'], 'valid' ) ) && 0 === $a640_refunds( $so['order_id'] ) );
check( 'A640: …it is audited and the admin is told', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='refund_of_other_payment' AND object_id=%d", $so['order_id'] ) ) && count( $GLOBALS['wpcap_test_mails'] ) > $mails_before );
$dispute = stripe_deliver( $a640_stripe, array( 'id' => 'evt_a640_dp_' . uniqid(), 'type' => 'charge.dispute.funds_withdrawn', 'data' => array( 'object' => array( 'id' => 'dp_a640', 'object' => 'dispute', 'amount' => 2000, 'currency' => 'eur', 'charge' => 'ch_a640_second', 'payment_intent' => 'pi_a640_second', 'metadata' => array( 'wpcap_order' => $so['public_id'] ) ) ) ) );
check( 'A640: a chargeback of the double payment is not booked either', 'refund_other_payment' === $dispute && 'complete' === Orders::get( $so['order_id'] )->status );
$own = stripe_deliver( $a640_stripe, $charge( 'evt_a640_r2_' . uniqid(), 'pi_a640_first' ) );
check( 'A640: a refund of the booked payment is still booked', 'refunded' === $own && 'refunded' === Orders::get( $so['order_id'] )->status && 1 === $a640_refunds( $so['order_id'] ) );

// PayPal: the refund names its capture; another capture of the same order is not booked.
http_reset();
$po = provider_order( 'paypal', $a640_type, 1, 'a640-paypal-' . uniqid() );
Webhooks::process( 'paypal', array( 'event_id' => 'capture-A640CAP1', 'type' => 'paid', 'order_public_id' => $po['public_id'], 'provider_reference' => 'A640CAP1', 'amount_minor' => 2000, 'currency' => 'EUR' ) );
$a640_token();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
http_push( array( 'id' => 'A640CAP2', 'status' => 'REFUNDED', 'custom_id' => $po['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '20.00' ) ), 200, '/v2/payments/captures/A640CAP2' );
$pp_ev = $a640_paypal->verify_webhook( paypal_request( array( 'id' => 'WH-A640-1', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => array( 'id' => 'A640REF2', 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => 'EUR', 'value' => '20.00' ), 'links' => array( array( 'rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/A640CAP2' ) ) ) ) ) );
check( 'A640: PayPal refund of another capture of the order is not booked', ! is_wp_error( $pp_ev ) && 'refund_other_payment' === Webhooks::process( 'paypal', $pp_ev ) && 'complete' === Orders::get( $po['order_id'] )->status && 0 === $a640_refunds( $po['order_id'] ) );

echo "A641: PayPal captures only for a known, payable order\n";
http_reset();
$co = provider_order( 'paypal', $a640_type, 1, 'a641-cancelled-' . uniqid() );
Orders::set_provider_reference( $co['order_id'], 'A641ORDER1' );
Orders::cancel_order( $co['order_id'], 'test' );
$approved = static fn( $id, $paypal_order, $public_id ) => array( 'id' => $id, 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => array( 'id' => $paypal_order, 'status' => 'APPROVED', 'purchase_units' => array( array( 'reference_id' => $public_id, 'custom_id' => $public_id, 'amount' => array( 'currency_code' => 'EUR', 'value' => '20.00' ) ) ) ) );
$a640_token();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$ap1 = $a640_paypal->verify_webhook( paypal_request( $approved( 'WH-A641-1', 'A641ORDER1', $co['public_id'] ) ) );
check( 'A641: an approved PayPal order of a cancelled order is not captured', ! is_wp_error( $ap1 ) && 'ignored' === $ap1['type'] && ! http_requests( '/capture' ) );
http_reset();
$a640_token();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$ap2 = $a640_paypal->verify_webhook( paypal_request( $approved( 'WH-A641-2', 'A641FOREIGN', 'unknown-public-id' ) ) );
check( 'A641: an approved PayPal order of an unknown order is not captured', ! is_wp_error( $ap2 ) && 'ignored' === $ap2['type'] && ! http_requests( '/capture' ) );
http_reset();
$ok = provider_order( 'paypal', $a640_type, 1, 'a641-ok-' . uniqid() );
Orders::set_provider_reference( $ok['order_id'], 'A641ORDER3' );
$a640_token();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$ap3 = $a640_paypal->verify_webhook( paypal_request( $approved( 'WH-A641-3', 'A641OTHER', $ok['public_id'] ) ) );
check( 'A641: an approved PayPal order that is not the order\'s stored PayPal order is not captured', ! is_wp_error( $ap3 ) && 'ignored' === $ap3['type'] && ! http_requests( '/capture' ) );
http_reset();
$a640_token();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
http_push( array( 'id' => 'A641ORDER3', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'A641CAP3', 'status' => 'COMPLETED', 'custom_id' => $ok['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '20.00' ) ) ) ) ) ) ), 201, '/v2/checkout/orders/A641ORDER3/capture' );
$ap4 = $a640_paypal->verify_webhook( paypal_request( $approved( 'WH-A641-4', 'A641ORDER3', $ok['public_id'] ) ) );
check( 'A641: the matching payable order is captured and confirmed', ! is_wp_error( $ap4 ) && 'paid' === $ap4['type'] && 1 === count( http_requests( '/capture' ) ) && 'paid' === Webhooks::process( 'paypal', $ap4 ) );
http_reset();
$a640_token();
$ret = PayPalGateway::handle_return( $co['public_id'], 'A641ORDER1' );
check( 'A641: the return of a cancelled order captures nothing ("nothing charged")', is_wp_error( $ret ) && 'failed' === PayPalGateway::return_state( $ret ) && ! http_requests( '/capture' ) );

echo "A641: Stripe maps sessions only through metadata.wpcap_order\n";
$sc = provider_order( 'stripe', $a640_type, 1, 'a641-stripe-' . uniqid() );
$foreign = stripe_session_event( 'evt_a641_cr_' . uniqid(), 'checkout.session.completed', $sc, array( 'metadata' => array() ) );
$res = stripe_deliver( $a640_stripe, $foreign );
check( 'A641: a session with only client_reference_id confirms nothing', 'ignored' === $res && 'pending' === Orders::get( $sc['order_id'] )->payment_status );
$res2 = stripe_deliver( $a640_stripe, stripe_session_event( 'evt_a641_md_' . uniqid(), 'checkout.session.completed', $sc ) );
check( 'A641: with metadata.wpcap_order it is confirmed', 'paid' === $res2 && 'paid' === Orders::get( $sc['order_id'] )->payment_status );

update_option( Settings::OPTION, $a640_saved );
Settings::flush();
Registry::reset();
http_reset();
