<?php
/**
 * A184: state machines (docs/STATE-MACHINES.md) and duplicate / out-of-order payment events.
 *
 * Every scenario is replayed through the real REST entry point (Webhooks::handle → adapter
 * verify_webhook() → Webhooks::process()) for Stripe, PayPal and Mollie, and through the order status
 * hooks for WooCommerce. Expected results are the ones documented in docs/STATE-MACHINES.md.
 * Uses the HTTP/REST fakes of payments.php and the Mollie helpers of modules/mollie.php.
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;
use PassCaptain\StateMachine;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Mollie\MollieGateway;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;

echo "A184: state machine tables and documentation\n";
$a184_doc = (string) file_get_contents( WPCAP_PATH . 'docs/STATE-MACHINES.md' );
$a184_missing = array();
foreach ( StateMachine::TRANSITIONS as $entity => $map ) {
	foreach ( $map as $from => $targets ) {
		foreach ( array_merge( array( $from ), $targets ) as $state ) {
			if ( false === strpos( $a184_doc, '`' . $state . '`' ) ) { $a184_missing[] = "$entity:$state"; }
			if ( ! isset( $map[ $state ] ) ) { $a184_missing[] = "$entity:$state(undeclared)"; }
		}
	}
}
check( 'every state of every guarded entity is declared and documented', ! $a184_missing );
check( 'terminal states have no way out', StateMachine::is_terminal( 'order', 'refunded' ) && StateMachine::is_terminal( 'refund', 'completed' ) && StateMachine::is_terminal( 'job', 'complete' ) && ! StateMachine::is_terminal( 'order', 'cancelled' ) );
check( 'order transitions: never paid cannot become refunded, paid cannot expire', ! StateMachine::allowed( 'order', 'pending_payment', 'refunded' ) && ! StateMachine::allowed( 'order', 'expired', 'refunded' ) && ! StateMachine::allowed( 'order', 'complete', 'expired' ) && StateMachine::allowed( 'order', 'expired', 'complete' ) );
check( 'source_sql lists exactly the allowed sources', "status IN ('pending_payment','expired','needs_review')" === StateMachine::source_sql( 'order', 'complete' ) && '1=0' === StateMachine::source_sql( 'order', 'pending_payment' ) );

$a184_t = make_type( 10, 10, 2000 );
$a184_pending = Orders::create( array( 'email' => 'a184-sm@example.com', 'items' => array( array( 'ticket_type_id' => $a184_t, 'quantity' => 1 ) ), 'idempotency_key' => 'a184-sm-1' ) );
$a184_mr = Orders::mark_refunded( $a184_pending['order_id'], 'test' );
$a184_jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='order_refund_mark_retry' AND payload LIKE '%\"order_id\":" . (int) $a184_pending['order_id'] . "%'" );
check( 'mark_refunded refuses a never paid order (final, no retry job)', is_wp_error( $a184_mr ) && 'wpcap_order_state' === $a184_mr->get_error_code() && 'pending_payment' === Orders::get( $a184_pending['order_id'] )->status && 0 === $a184_jobs );
check( 'the refund-mark retry job acknowledges a refused transition', true === Orders::handle_job( false, 'order_refund_mark_retry', array( 'order_id' => $a184_pending['order_id'] ) ) );
Orders::confirm_manual_payment( $a184_pending['order_id'] );
$a184_guard = Orders::cancel_order( $a184_pending['order_id'], 'late_failure', array( 'pending' ) );
check( 'cancel_order with a payment-status guard leaves a paid order and its tickets alone', is_wp_error( $a184_guard ) && 'wpcap_order_state' === $a184_guard->get_error_code() && 'complete' === Orders::get( $a184_pending['order_id'] )->status && 1 === count( tickets( $a184_pending['order_id'], 'valid' ) ) );
$a184_sql = $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id=" . (int) $a184_pending['order_id'] . ' AND ' . StateMachine::source_sql( 'order', 'expired' ) );
check( 'a paid order is no source for "expired"', null === $a184_sql );

// Jobs: a run whose claim was taken over (stale claim re-queued and claimed again) must not finish the job.
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='a184_takeover'" );
PassCaptain\Jobs::enqueue( 'a184-takeover', 'a184_takeover', array() );
$a184_job_cb = static function ( $handled, $type ) use ( $wpdb, $p ) {
	if ( 'a184_takeover' !== $type ) { return $handled; }
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET locked_at='2001-01-01 00:00:00' WHERE job_type='a184_takeover'" );
	return true;
};
add_filter( 'wpcap_process_job', $a184_job_cb, 5, 2 );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='a184_takeover'" );
PassCaptain\Jobs::process_due( 50 );
remove_filter( 'wpcap_process_job', $a184_job_cb, 5 );
check( 'a job is completed only by the run that still holds its claim', 'running' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_type='a184_takeover'" ) );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='a184_takeover'" );

/* ------------------------------------------------------------------------------------------------ */
echo "A184: duplicate and out-of-order payment events (Stripe, PayPal, Mollie)\n";

$a184_mollie_before = array( 'mollie_api_key' => Settings::get( 'mollie_api_key' ), 'enabled_providers' => Settings::get( 'enabled_providers' ) );
Settings::update( array(
	'stripe_secret_key' => 'sk_test_a184', 'stripe_webhook_secret' => 'whsec_test',
	'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1',
	'mollie_api_key' => 'test_' . str_repeat( 'Ab1', 10 ),
	'enabled_providers' => array( 'manual', 'stripe', 'paypal', 'mollie' ),
) );
Registry::reset();
$GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_ip_limit'] = 100000;
$GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_global_limit'] = 100000;

/** Label of a Webhooks::handle() answer: the result, "retry" (5xx), "redeliver" or "rejected" (4xx). */
function a184_label( $r ) {
	if ( is_wp_error( $r ) ) {
		if ( 'wpcap_webhook_redeliver' === $r->get_error_code() ) { return 'redeliver'; }
		return (int) ( ( (array) $r->get_error_data() )['status'] ?? 0 ) >= 500 ? 'retry' : 'rejected';
	}
	if ( is_object( $r ) && method_exists( $r, 'get_data' ) ) { $r = $r->get_data(); }
	return (string) ( $r['result'] ?? '?' );
}
function a184_alerts() { return count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'Pass Captain: Zahlung prüfen' === $m['subject'] ) ); }
function a184_refund_rows( $order_id ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d", $order_id ) ); }
function a184_mollie_known( $order_id, $payment_id ) { global $wpdb; $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_mollie_payments (payment_id,order_id,created_at) VALUES (%s,%d,%s)", $payment_id, $order_id, gmdate( 'Y-m-d H:i:s' ) ) ); }

/*
 * Adapter drivers. $ref names a payment attempt (Stripe session/intent, PayPal capture, Mollie payment),
 * $id a provider event (Stripe/PayPal event id; Mollie has no event ids: it posts the payment id and
 * the current payment state is fetched, so a "refund" or "chargeback" is a new snapshot of the payment).
 */
$a184_drivers = array(
	'stripe' => array(
		'paid' => static function ( $o, $ref, $id ) {
			return a184_label( Webhooks::handle( stripe_request( stripe_session_event( "evt_a184_$id", 'checkout.session.completed', $o, array( 'id' => "cs_a184_$ref", 'payment_intent' => "pi_a184_$ref" ) ) ) ) );
		},
		'failed' => static function ( $o, $ref, $id ) {
			return a184_label( Webhooks::handle( stripe_request( stripe_session_event( "evt_a184_$id", 'checkout.session.expired', $o, array( 'id' => "cs_a184_$ref", 'payment_status' => 'unpaid', 'status' => 'expired', 'payment_intent' => null ) ) ) ) );
		},
		'refund' => static function ( $o, $ref, $id, $amount ) {
			$full = $amount >= (int) $o['total_minor'];
			$charge = array( 'id' => "ch_a184_$ref", 'object' => 'charge', 'currency' => 'eur', 'metadata' => array( 'wpcap_order' => $o['public_id'] ), 'payment_intent' => "pi_a184_$ref", 'refunded' => $full, 'amount_refunded' => $amount, 'refunds' => array( 'data' => array( array( 'id' => "re_a184_$id", 'amount' => $amount, 'metadata' => array() ) ) ) );
			return a184_label( Webhooks::handle( stripe_request( array( 'id' => "evt_a184_$id", 'type' => 'charge.refunded', 'data' => array( 'object' => $charge ) ) ) ) );
		},
		'chargeback' => static function ( $o, $ref, $id, $amount ) {
			// Disputes carry no order metadata: the adapter reads it from the PaymentIntent.
			http_push( array( 'id' => "pi_a184_$ref", 'metadata' => array( 'wpcap_order' => $o['public_id'] ) ), 200, "/v1/payment_intents/pi_a184_$ref" );
			$dispute = array( 'id' => "dp_a184_$id", 'object' => 'dispute', 'amount' => $amount, 'currency' => 'eur', 'payment_intent' => "pi_a184_$ref", 'metadata' => array(), 'status' => 'needs_response' );
			return a184_label( Webhooks::handle( stripe_request( array( 'id' => "evt_a184_$id", 'type' => 'charge.dispute.funds_withdrawn', 'data' => array( 'object' => $dispute ) ) ) ) );
		},
	),
	'paypal' => array(
		'paid' => static function ( $o, $ref, $id ) {
			http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
			return a184_label( Webhooks::handle( paypal_request( array( 'id' => "WH-A184-$id", 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => array( 'id' => "CAPA184$ref", 'status' => 'COMPLETED', 'custom_id' => $o['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => PayPalGateway::format_amount( (int) $o['total_minor'], 'EUR' ) ) ) ) ) ) );
		},
		'failed' => static function ( $o, $ref, $id ) {
			http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
			return a184_label( Webhooks::handle( paypal_request( array( 'id' => "WH-A184-$id", 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => array( 'id' => "CAPA184$ref", 'status' => 'DECLINED', 'custom_id' => $o['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => PayPalGateway::format_amount( (int) $o['total_minor'], 'EUR' ) ) ) ) ) ) );
		},
		'refund' => static function ( $o, $ref, $id, $amount, $type = 'PAYMENT.CAPTURE.REFUNDED' ) {
			http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
			return a184_label( Webhooks::handle( paypal_request( array( 'id' => "WH-A184-$id", 'event_type' => $type, 'resource' => array( 'id' => "REFA184$id", 'status' => 'COMPLETED', 'custom_id' => $o['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => PayPalGateway::format_amount( $amount, 'EUR' ) ) ) ) ) ) );
		},
		'chargeback' => static function ( $o, $ref, $id, $amount ) use ( &$a184_drivers ) {
			return $a184_drivers['paypal']['refund']( $o, $ref, $id, $amount, 'PAYMENT.CAPTURE.REVERSED' );
		},
	),
	'mollie' => array(
		'snapshot' => static function ( $o, $ref, $status, $refunded = 0, $charged_back = 0 ) {
			$payment_id = "tr_A184$ref";
			a184_mollie_known( (int) $o['order_id'], $payment_id );
			$embedded = array();
			if ( $refunded ) { $embedded['refunds'] = array( array( 'id' => "re_A184$ref$refunded", 'status' => 'refunded', 'amount' => array( 'value' => MollieGateway::format_amount( $refunded, 'EUR' ), 'currency' => 'EUR' ), 'metadata' => null ) ); }
			if ( $charged_back ) { $embedded['chargebacks'] = array( array( 'id' => "chb_A184$ref", 'amount' => array( 'value' => MollieGateway::format_amount( $charged_back, 'EUR' ), 'currency' => 'EUR' ) ) ); }
			http_push( mollie_payment( $payment_id, $status, $o, $embedded ? array( '_embedded' => $embedded ) : array() ), 200, '/v2/payments/' . $payment_id );
			return a184_label( Webhooks::handle( mollie_webhook( $payment_id ) ) );
		},
	),
);
$a184_m = $a184_drivers['mollie']['snapshot'];
$a184_new = static function ( $provider, $key, $type = 0 ) {
	return provider_order( $provider, $type ?: make_type( 10, 10, 2000 ), 1, "a184-$provider-$key" );
};
$a184_state = static function ( $o ) { $r = Orders::get( $o['order_id'] ); return $r->status . '/' . $r->payment_status; };

foreach ( array( 'stripe', 'paypal', 'mollie' ) as $prov ) {
	$d = $a184_drivers[ $prov ];
	http_reset();
	$GLOBALS['wpcap_test_transients'] = array(); // Checkout rate limit and cached PayPal token.
	http_push( array( 'access_token' => 'A21-a184', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
	$is_m = 'mollie' === $prov;

	// 1. The same "paid" twice.
	$o = $a184_new( $prov, 'dup' );
	$r1 = $is_m ? $a184_m( $o, 'dup1', 'paid' ) : $d['paid']( $o, 'dup1', 'dup1' );
	$r2 = $is_m ? $a184_m( $o, 'dup1', 'paid' ) : $d['paid']( $o, 'dup1', 'dup1' );
	check( "$prov: a repeated paid event is applied once", 'paid' === $r1 && 'duplicate' === $r2 && 'complete/paid' === $a184_state( $o ) && 1 === count( tickets( $o['order_id'] ) ) );

	// 2. A second successful payment (other attempt).
	$o = $a184_new( $prov, 'second' );
	$alerts = a184_alerts();
	$r1 = $is_m ? $a184_m( $o, 'sec1', 'paid' ) : $d['paid']( $o, 'sec1', 'sec1' );
	$r2 = $is_m ? $a184_m( $o, 'sec2', 'paid' ) : $d['paid']( $o, 'sec2', 'sec2' );
	check( "$prov: a second paid attempt is a double payment (alert, no second issuance)", 'paid' === $r1 && 'double_payment' === $r2 && 1 === count( tickets( $o['order_id'] ) ) && a184_alerts() === $alerts + 1 );

	// 3. Refund before paid.
	$o = $a184_new( $prov, 'rbp' );
	if ( $is_m ) {
		// Mollie snapshot "paid + refunded": the payment is applied first, then Mollie is asked to redeliver.
		$r1 = $a184_m( $o, 'rbp1', 'paid', 2000 );
		$mid = $a184_state( $o );
		$r2 = $a184_m( $o, 'rbp1', 'paid', 2000 );
		check( 'mollie: refund seen before the payment: payment applied, redelivery requested, redelivery books the refund', 'redeliver' === $r1 && 'complete/paid' === $mid && 'refunded' === $r2 && 'refunded/refunded' === $a184_state( $o ) && 1 === a184_refund_rows( $o['order_id'] ) && ! tickets( $o['order_id'], 'valid' ) );
	} else {
		$r1 = $d['refund']( $o, 'rbp1', 'rbp-r', 2000 );
		$mid = $a184_state( $o );
		$r2 = $d['paid']( $o, 'rbp1', 'rbp-p' );
		$r3 = $d['refund']( $o, 'rbp1', 'rbp-r', 2000 );
		check( "$prov: refund before paid is deferred (5xx), applied on redelivery after the payment", 'retry' === $r1 && 'pending_payment/pending' === $mid && 'paid' === $r2 && 'refunded' === $r3 && 'refunded/refunded' === $a184_state( $o ) && 1 === a184_refund_rows( $o['order_id'] ) && ! tickets( $o['order_id'], 'valid' ) );
	}

	// 4. Paid after cancelled, then that payment refunded by the operator.
	$o = $a184_new( $prov, 'pac' );
	$alerts = a184_alerts();
	$r1 = $is_m ? $a184_m( $o, 'pac1', 'expired' ) : $d['failed']( $o, 'pac1', 'pac-f' );
	$r2 = $is_m ? $a184_m( $o, 'pac2', 'paid' ) : $d['paid']( $o, 'pac2', 'pac-p' );
	$r3 = $is_m ? $a184_m( $o, 'pac2', 'paid', 2000 ) : $d['refund']( $o, 'pac2', 'pac-r', 2000 );
	check( "$prov: paid after cancelled issues nothing and alerts; its refund books nothing", 'cancelled' === $r1 && 'paid_for_closed_order' === $r2 && 'refund_for_unpaid_order' === $r3 && 'cancelled/cancelled' === $a184_state( $o ) && ! tickets( $o['order_id'] ) && 0 === a184_refund_rows( $o['order_id'] ) && a184_alerts() === $alerts + 1 );

	// 5. Paid after expired: late payment with capacity left completes the order.
	$o = $a184_new( $prov, 'pae' );
	expire_holds_of( $o['order_id'] );
	Orders::expire_holds();
	$mid = $a184_state( $o );
	$r1 = $is_m ? $a184_m( $o, 'pae1', 'paid' ) : $d['paid']( $o, 'pae1', 'pae-p' );
	check( "$prov: paid after expired is a late payment (capacity re-checked, tickets issued)", 'expired/expired' === $mid && 'paid' === $r1 && 'complete/paid' === $a184_state( $o ) && 1 === count( tickets( $o['order_id'], 'valid' ) ) );

	// 6. Failed after paid.
	$o = $a184_new( $prov, 'fap' );
	$r1 = $is_m ? $a184_m( $o, 'fap1', 'paid' ) : $d['paid']( $o, 'fap1', 'fap-p' );
	$r2 = $is_m ? $a184_m( $o, 'fap2', 'expired' ) : $d['failed']( $o, 'fap1', 'fap-f' );
	check( "$prov: failed after paid changes nothing", 'paid' === $r1 && 'ignored' === $r2 && 'complete/paid' === $a184_state( $o ) && 1 === count( tickets( $o['order_id'], 'valid' ) ) );

	// 7. Chargeback after a full refund.
	$o = $a184_new( $prov, 'car' );
	$r1 = $is_m ? $a184_m( $o, 'car1', 'paid' ) : $d['paid']( $o, 'car1', 'car-p' );
	$r2 = $is_m ? $a184_m( $o, 'car1', 'paid', 2000 ) : $d['refund']( $o, 'car1', 'car-r', 2000 );
	$alerts = a184_alerts();
	$r3 = $is_m ? $a184_m( $o, 'car1', 'paid', 2000, 2000 ) : $d['chargeback']( $o, 'car1', 'car-c', 2000 );
	$r4 = $is_m ? $a184_m( $o, 'car1', 'paid', 2000, 2000 ) : $d['chargeback']( $o, 'car1', 'car-c', 2000 );
	check( "$prov: chargeback after a full refund books nothing and alerts once", 'paid' === $r1 && 'refunded' === $r2 && 'chargeback_after_refund' === $r3 && 'duplicate' === $r4 && 1 === a184_refund_rows( $o['order_id'] ) && 2000 === (int) Orders::get( $o['order_id'] )->refunded_minor && a184_alerts() === $alerts + 1 );

	// 8. Chargeback of a paid order works like an external full refund.
	$o = $a184_new( $prov, 'cbp' );
	$r1 = $is_m ? $a184_m( $o, 'cbp1', 'paid' ) : $d['paid']( $o, 'cbp1', 'cbp-p' );
	$r2 = $is_m ? $a184_m( $o, 'cbp1', 'paid', 0, 2000 ) : $d['chargeback']( $o, 'cbp1', 'cbp-c', 2000 );
	$reason = $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$p}wpcap_refunds WHERE order_id=%d", $o['order_id'] ) );
	check( "$prov: chargeback of a paid order is booked as a refund and revokes the tickets", 'paid' === $r1 && 'refunded' === $r2 && 'refunded/refunded' === $a184_state( $o ) && $prov . '_chargeback' === $reason && ! tickets( $o['order_id'], 'valid' ) );

	// 9. Partial refunds: duplicate and (Mollie) older snapshot after a newer one.
	$o = $a184_new( $prov, 'part' );
	$r1 = $is_m ? $a184_m( $o, 'part1', 'paid' ) : $d['paid']( $o, 'part1', 'part-p' );
	if ( $is_m ) {
		$r2 = $a184_m( $o, 'part1', 'paid', 1500 );
		$r3 = $a184_m( $o, 'part1', 'paid', 1500 );
		$r4 = $a184_m( $o, 'part1', 'paid', 500 );
		check( 'mollie: cumulative refund snapshots book only the unbooked rest (duplicate, older snapshot)', 'partial_refund' === $r2 && 'duplicate' === $r3 && 'already_booked' === $r4 && 1500 === (int) Orders::get( $o['order_id'] )->refunded_minor && 1 === a184_refund_rows( $o['order_id'] ) );
	} else {
		$r2 = $d['refund']( $o, 'part1', 'part-r', 500 );
		$r3 = $d['refund']( $o, 'part1', 'part-r', 500 );
		check( "$prov: a repeated partial refund event is booked once", 'paid' === $r1 && 'partial_refund' === $r2 && 'duplicate' === $r3 && 500 === (int) Orders::get( $o['order_id'] )->refunded_minor && 1 === a184_refund_rows( $o['order_id'] ) && 'complete/paid' === $a184_state( $o ) );
	}

	// 10. A late payment without capacity (needs review), then repeated confirmations and a refund.
	$type = make_type( 1, 10, 2000 );
	$o = $a184_new( $prov, 'rev', $type );
	expire_holds_of( $o['order_id'] );
	Orders::expire_holds();
	$other = Orders::create( array( 'email' => "a184-$prov-other@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => "a184-$prov-other" ) );
	Orders::confirm_manual_payment( $other['order_id'] );
	$r1 = $is_m ? $a184_m( $o, 'rev1', 'paid' ) : $d['paid']( $o, 'rev1', 'rev-p' );
	Orders::cancel_order( $other['order_id'], 'a184' ); // Capacity is free again: still nothing happens behind the operator's back.
	$r2 = 'stripe' === $prov ? a184_label( Webhooks::handle( stripe_request( stripe_session_event( 'evt_a184_rev-async', 'checkout.session.async_payment_succeeded', $o, array( 'id' => 'cs_a184_rev1', 'payment_intent' => 'pi_a184_rev1' ) ) ) ) ) : 'needs_review';
	$r3 = $is_m ? $a184_m( $o, 'rev2', 'paid' ) : $d['paid']( $o, 'rev2', 'rev-p2' );
	$mid = $a184_state( $o );
	$r4 = $is_m ? $a184_m( $o, 'rev1', 'paid', 2000 ) : $d['refund']( $o, 'rev1', 'rev-r', 2000 );
	check( "$prov: paid without capacity → needs review; repeats stay there, another attempt is a double payment, a full refund closes it", 'needs_review' === $r1 && 'needs_review' === $r2 && 'double_payment' === $r3 && 'needs_review/paid_unallocated' === $mid && 'refunded' === $r4 && 'refunded/refunded' === $a184_state( $o ) && ! tickets( $o['order_id'] ) && 0 === a184_refund_rows( $o['order_id'] ) );
}
unset( $GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_ip_limit'], $GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_global_limit'] );

/* ------------------------------------------------------------------------------------------------ */
echo "A184: duplicate and out-of-order WooCommerce status changes\n";
$a184_wc = 18400;
$a184_w = static function () use ( &$a184_wc ) {
	$wc = make_wc_order( ++$a184_wc, make_type( 10, 10, 2000 ), 1, '20.00' );
	return array( $wc, Woo::reserve( $wc ) );
};
$a184_audits = static function ( $action, $order_id ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_type='order' AND object_id=%d", $action, $order_id ) ); };

list( $wc, $core ) = $a184_w();
Woo::sync_paid_order( $wc->get_id() );
Woo::sync_paid_order( $wc->get_id() );
check( 'woocommerce: processing/completed twice issues once', 1 === count( tickets( $core ) ) && 1 === $a184_audits( 'external_payment_confirmed', $core ) );
Woo::sync_failed_order( $wc->get_id() );
check( 'woocommerce: failed after paid keeps the tickets (note on the order)', 'complete' === Orders::get( $core )->status && 1 === count( tickets( $core, 'valid' ) ) && false !== strpos( (string) end( $wc->notes ), 'Fehlgeschlagen' ) );
Woo::sync_cancelled_order( $wc->get_id() );
check( 'woocommerce: cancelled after paid revokes the tickets', 'cancelled' === Orders::get( $core )->status && ! tickets( $core, 'valid' ) );

list( $wc, $core ) = $a184_w();
Woo::sync_failed_order( $wc->get_id() );
check( 'woocommerce: failed before paid cancels and releases the hold', 'cancelled/cancelled' === $a184_state( array( 'order_id' => $core ) ) );

list( $wc, $core ) = $a184_w();
Woo::sync_fully_refunded_order( $wc->get_id() );
check( 'woocommerce: refunded before paid closes the order as cancelled, books nothing', 'cancelled/cancelled' === $a184_state( array( 'order_id' => $core ) ) && 0 === a184_refund_rows( $core ) );
Woo::sync_paid_order( $wc->get_id() );
$core2 = absint( $wc->get_meta( Woo::ORDER_META ) );
check( 'woocommerce: a later payment re-reserves (WooCommerce is authoritative) and issues once', $core2 !== $core && 1 === count( tickets( $core2, 'valid' ) ) && ! tickets( $core ) );

list( $wc, $core ) = $a184_w();
Woo::sync_paid_order( $wc->get_id() );
Woo::sync_fully_refunded_order( $wc->get_id() );
Woo::sync_fully_refunded_order( $wc->get_id() );
Woo::sync_paid_order( $wc->get_id() );
check( 'woocommerce: full refund twice is applied once; processing after refunded issues nothing', 'refunded/refunded' === $a184_state( array( 'order_id' => $core ) ) && 1 === $a184_audits( 'order_refunded', $core ) && ! tickets( $core, 'valid' ) && $core === absint( $wc->get_meta( Woo::ORDER_META ) ) );

/* Restore the provider settings for the following tests. */
Settings::update( array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'paypal_client_id' => '', 'paypal_client_secret' => '', 'paypal_webhook_id' => '' ) + $a184_mollie_before );
Registry::reset();
http_reset();
