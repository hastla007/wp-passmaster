<?php
/**
 * A264: a full external refund (provider dashboard) whose booking in wpcap_refunds fails is not
 * acknowledged: the order is marked refunded, the webhook answers 5xx, and the redelivery books the
 * refund row (refunded total, credit note) exactly once.
 *
 * Included from run.php (uses check(), make_type(), tickets() and the HTTP/REST fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;

echo "A264: full external refund with a failing refund booking\n";
Settings::update( array( 'stripe_secret_key' => 'sk_test_a264', 'stripe_webhook_secret' => 'whsec_test', 'refund_approval_threshold' => 0 ) );
Registry::reset();
http_reset();
$a264_o = provider_order( 'stripe', make_type( 10, 10, 3000 ), 2, 'a264-full' );
Webhooks::handle( stripe_request( stripe_session_event( 'evt_a264_paid', 'checkout.session.completed', $a264_o, array( 'id' => 'cs_a264', 'payment_intent' => 'pi_a264' ) ) ) );
check( 'A264: setup – order paid with two tickets', 'paid' === Orders::get( $a264_o['order_id'] )->payment_status && 2 === count( tickets( $a264_o['order_id'], 'valid' ) ) );

$a264_event = array( 'id' => 'evt_a264_refund', 'type' => 'charge.refunded', 'data' => array( 'object' => array(
	'id' => 'ch_a264', 'object' => 'charge', 'amount' => 6000, 'amount_refunded' => 6000, 'refunded' => true, 'currency' => 'eur',
	'payment_intent' => 'pi_a264', 'metadata' => array( 'wpcap_order' => $a264_o['public_id'] ),
	'refunds' => array( 'data' => array( array( 'id' => 're_a264', 'amount' => 6000, 'status' => 'succeeded', 'metadata' => array() ) ), 'has_more' => false ),
) ) );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_refunds \(/', 'lock_timeout', array( 'rollback' => true ) );
$a264_first = Webhooks::handle( stripe_request( $a264_event ) );
wpcap_test_faults_clear();
$a264_rows = static function () use ( $wpdb, $p, $a264_o ) { return $wpdb->get_results( $wpdb->prepare( "SELECT amount_minor,status FROM {$p}wpcap_refunds WHERE order_id=%d", $a264_o['order_id'] ) ); };
$a264_order = Orders::get( $a264_o['order_id'] );
check( 'A264: a failed refund booking is answered with a retryable 5xx (not acknowledged)', is_wp_error( $a264_first ) && (int) ( $a264_first->get_error_data()['status'] ?? 0 ) >= 500 );
check( 'A264: the order is still closed right away (refunded, tickets revoked)', 'refunded' === $a264_order->status && ! tickets( $a264_o['order_id'], 'valid' ) );
check( 'A264: nothing booked yet, the failure is audited', ! $a264_rows() && 0 === (int) $a264_order->refunded_minor && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='external_refund_booking_failed' AND object_id=%d", $a264_o['order_id'] ) ) > 0 );
check( 'A264: the event is not marked processed', null === $wpdb->get_var( "SELECT status FROM {$p}wpcap_payment_events WHERE provider='stripe' AND provider_event_id='evt_a264_refund'" ) );

$a264_second = Webhooks::handle( stripe_request( $a264_event ) );
$a264_rows_after = $a264_rows();
check( 'A264: the redelivery books the refund row once (full amount, completed)', ! is_wp_error( $a264_second ) && 1 === count( $a264_rows_after ) && 6000 === (int) $a264_rows_after[0]->amount_minor && 'completed' === $a264_rows_after[0]->status && 6000 === (int) Orders::get( $a264_o['order_id'] )->refunded_minor );
$a264_third = Webhooks::handle( stripe_request( $a264_event ) );
check( 'A264: a further redelivery books nothing again', 1 === count( $a264_rows() ) && 6000 === (int) Orders::get( $a264_o['order_id'] )->refunded_minor );
http_reset();
