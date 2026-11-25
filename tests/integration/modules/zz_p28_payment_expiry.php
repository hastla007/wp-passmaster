<?php
/**
 * E20-1: the PayPal and Mollie payment expiry is bound to the end of the reservation, like Stripe's
 * Checkout Session (A577). HTTP stubs check what is sent to the provider:
 *   - Mollie bank transfer: `dueDate` (a UTC date) is the last day that ends at least
 *     HOLD_BUFFER_MINUTES before the hold, at least tomorrow (then the hold is extended to the end of
 *     tomorrow, never past the start), at most 100 days; on creation and in the PATCH after the hold
 *     was extended; independent of PHP and site time zone, across the daylight-saving switch.
 *   - Mollie immediate methods: no expiry field exists in the request; a later `expiresAt` of the
 *     response extends the hold (at most HOSTED_HOLD_MINUTES from now, never past the start).
 *   - Fallback: a lapsed hold voids the open Mollie payment (DELETE when isCancelable); PayPal (no expiry,
 *     no cancel call) never captures without an active hold, also in the status poll.
 *   - Safety net: a payment that arrives after the hold anyway goes through the late-payment recovery.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Reconcile;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

require_once dirname( __DIR__ ) . '/lib/fixtures_payments.php';

global $wpdb;
$p = $wpdb->prefix;
$p28_saved = array_intersect_key( Settings::all(), array_flip( array( 'mollie_api_key', 'enabled_providers', 'mollie', 'paypal_mode', 'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id', 'auto_refund_unmatched_payments' ) ) );
$p28_saved_tz = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$p28_saved_php_tz = date_default_timezone_get();
$p28_orders = array();

PassCaptain\Modules\Mollie::register();
// The void job (Reconcile) only while this module runs: a second registration later (r5) would void twice.
$p28_reconcile = false === has_action( 'wpcap_order_cancelled', array( Reconcile::class, 'order_cancelled' ) );
if ( $p28_reconcile ) { Reconcile::register(); }
Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Ab1', 10 ), 'enabled_providers' => array( 'manual', 'mollie', 'paypal' ), 'mollie' => array( 'methods' => array( 'ideal', 'banktransfer' ), 'billing_country' => '' ), 'auto_refund_unmatched_payments' => 0 ) );
Registry::reset();
$p28_mollie = Registry::get( 'mollie' );
$p28_methods = array( '_embedded' => array( 'methods' => array( array( 'id' => 'ideal', 'description' => 'iDEAL' ), array( 'id' => 'banktransfer', 'description' => 'Überweisung' ) ) ) );

/** Event starting $start_offset seconds from now (real clock) with one ticket type; returns [type, occurrence]. */
function p28_type( $start_offset = 10 * DAY_IN_SECONDS ) {
	global $wpdb;
	$type = make_type( 20, 10, 2500 );
	$occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $type ) );
	$wpdb->update( $wpdb->prefix . 'wpcap_occurrences', array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $start_offset ) ), array( 'occurrence_id' => $occ ) );
	return array( $type, $occ );
}
/** Pending order with one ticket; $extra goes into the payload (e.g. mollie_method). */
function p28_order( $type, $provider = 'mollie', array $extra = array() ) {
	global $p28_orders;
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array_merge( array( 'email' => 'p28-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'p28-' . uniqid( '', true ) ), $extra ), array( 'payment_provider' => $provider, 'skip_rate_limit' => true, 'hold_minutes' => 31 ) );
	$p28_orders[] = (int) $r['order_id'];
	return (int) $r['order_id'];
}
/** Sets every active hold of the order to end at $ts (UTC timestamp). */
function p28_hold_at( $order_id, $ts ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET expires_at=%s WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', gmdate( 'Y-m-d H:i:s', $ts ), $order_id ) );
}
/** End of the order's hold (latest active reservation, also when lapsed), UTC timestamp. */
function p28_hold( $order_id ) {
	global $wpdb;
	return (int) strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM %i WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', $order_id ) ) . ' UTC' );
}
/** Clock injection (UTC timestamp) or the real clock (null). */
function p28_at( $ts ) {
	if ( null === $ts ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_now'] ); return; }
	$GLOBALS['wpcap_test_filters']['wpcap_now'] = (int) $ts;
}
/** Creates the Mollie payment with stubbed method list and payment response; returns [result, request body]. */
function p28_create( $order_id, $id, array $response_extra = array() ) {
	global $p28_mollie, $p28_methods;
	$GLOBALS['wpcap_test_transients'] = array();
	http_reset();
	http_push( $p28_methods, 200, '/v2/methods' );
	$order = (array) Orders::get( $order_id );
	http_push( array_merge( array( 'id' => $id, 'status' => 'open', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $order['public_id'] ), '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/x' ) ) ), $response_extra ), 201, '/v2/payments' );
	$pay = $p28_mollie->create_payment( $order, order_items_array( $order_id ) );
	$req = http_requests( '/v2/payments' )[0] ?? array( 'body' => '' );
	return array( $pay, (array) json_decode( (string) $req['body'], true ) );
}
$p28_midnight = static function ( $days ) { return (int) strtotime( gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) . ' 00:00:00 UTC' ); };
$p28_buffer = MollieGateway::HOLD_BUFFER_MINUTES * MINUTE_IN_SECONDS;

echo "E20-1: Mollie bank transfer dueDate = last UTC day ending before the hold\n";
check( 'E20-1: Mollie uses the same buffer before the hold end as Stripe', PassCaptain\Payments\StripeGateway::HOLD_BUFFER_MINUTES === MollieGateway::HOLD_BUFFER_MINUTES && $p28_buffer > 0 );
list( $p28_t1 ) = p28_type();
$p28_o1 = p28_order( $p28_t1 );
// Hold ends exactly at midnight (in 4 days) plus the buffer: the day before still ends in time.
p28_hold_at( $p28_o1, $p28_midnight( 4 ) + $p28_buffer );
$p28_due = MollieGateway::due_date( (object) array( 'order_id' => $p28_o1 ) );
check( 'E20-1: hold at midnight + buffer → dueDate is the day before (ends exactly buffer minutes before the hold)', gmdate( 'Y-m-d', $p28_midnight( 3 ) ) === $p28_due );
p28_hold_at( $p28_o1, $p28_midnight( 4 ) + $p28_buffer - 1 );
check( 'E20-1: one second earlier → one day earlier (never inside the buffer)', gmdate( 'Y-m-d', $p28_midnight( 2 ) ) === MollieGateway::due_date( (object) array( 'order_id' => $p28_o1 ) ) );
p28_hold_at( $p28_o1, $p28_midnight( 4 ) + 12 * HOUR_IN_SECONDS );
$p28_due = MollieGateway::due_date( (object) array( 'order_id' => $p28_o1 ) );
$p28_due_end = (int) strtotime( $p28_due . ' 00:00:00 UTC' ) + DAY_IN_SECONDS;
$p28_ams_end = ( new DateTimeImmutable( $p28_due . ' 00:00:00', new DateTimeZone( 'Europe/Amsterdam' ) ) )->getTimestamp() + DAY_IN_SECONDS;
check( 'E20-1: hold at noon → dueDate ends (24:00 UTC) before the hold, and the Amsterdam day ends even earlier', gmdate( 'Y-m-d', $p28_midnight( 3 ) ) === $p28_due && $p28_due_end <= p28_hold( $p28_o1 ) - $p28_buffer && $p28_ams_end <= $p28_due_end );
check( 'E20-1: dueDate does not change the hold when it fits', $p28_midnight( 4 ) + 12 * HOUR_IN_SECONDS === p28_hold( $p28_o1 ) );
// PHP default and site time zone do not matter: storage and the due date are UTC.
date_default_timezone_set( 'Asia/Kathmandu' );
$GLOBALS['wpcap_test_options']['timezone_string'] = 'America/Los_Angeles';
check( 'E20-1: same dueDate with PHP time zone Asia/Kathmandu and site time zone America/Los_Angeles', $p28_due === MollieGateway::due_date( (object) array( 'order_id' => $p28_o1 ) ) );
date_default_timezone_set( $p28_saved_php_tz );
$GLOBALS['wpcap_test_options']['timezone_string'] = $p28_saved_tz;
// Upper limit: Mollie accepts at most 100 days.
p28_hold_at( $p28_o1, time() + 200 * DAY_IN_SECONDS );
check( 'E20-1: a hold longer than 100 days → dueDate in 100 days (Mollie maximum)', gmdate( 'Y-m-d', time() + 100 * DAY_IN_SECONDS ) === MollieGateway::due_date( (object) array( 'order_id' => $p28_o1 ) ) );

echo "E20-1: tomorrow is the earliest dueDate – the hold is extended to its end, never past the start\n";
list( $p28_t2 ) = p28_type( 5 * DAY_IN_SECONDS );
$p28_o2 = p28_order( $p28_t2 );
p28_hold_at( $p28_o2, time() + 2 * HOUR_IN_SECONDS );
$p28_due2 = MollieGateway::due_date( (object) array( 'order_id' => $p28_o2 ) );
check( 'E20-1: short hold → dueDate tomorrow, hold extended to the end of tomorrow (UTC) plus the buffer', gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) === $p28_due2 && $p28_midnight( 2 ) + $p28_buffer === p28_hold( $p28_o2 ) );
list( $p28_t3, $p28_occ3 ) = p28_type( 5 * DAY_IN_SECONDS );
$p28_o3 = p28_order( $p28_t3 );
$p28_start3 = $p28_midnight( 1 ) + 6 * HOUR_IN_SECONDS; // Tomorrow 06:00 UTC: before the end of tomorrow.
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $p28_start3 ) ), array( 'occurrence_id' => $p28_occ3 ) );
p28_hold_at( $p28_o3, min( $p28_start3, time() + HOUR_IN_SECONDS ) );
check( 'E20-1: start before the end of tomorrow → dueDate tomorrow, hold extended only up to the start (rest: late-payment re-check)', gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) === MollieGateway::due_date( (object) array( 'order_id' => $p28_o3 ) ) && $p28_start3 === p28_hold( $p28_o3 ) );

echo "E20-1: daylight-saving switch (injected clock)\n";
// 2026-03-28 22:30 UTC (Amsterdam 23:30 CET); the clocks go forward on 2026-03-29 01:00 UTC.
$p28_now = (int) strtotime( '2026-03-28 22:30:00 UTC' );
list( $p28_t4 ) = p28_type( 400 * DAY_IN_SECONDS );
$p28_o4 = p28_order( $p28_t4 );
p28_at( $p28_now );
p28_hold_at( $p28_o4, (int) strtotime( '2026-03-31 00:05:00 UTC' ) );
$p28_due4 = MollieGateway::due_date( (object) array( 'order_id' => $p28_o4 ) );
p28_hold_at( $p28_o4, (int) strtotime( '2026-03-29 10:00:00 UTC' ) );
$p28_due5 = MollieGateway::due_date( (object) array( 'order_id' => $p28_o4 ) );
$p28_hold5 = p28_hold( $p28_o4 );
p28_at( null );
check( 'E20-1: across the switch the dueDate is the UTC day before the hold (2026-03-30 for a hold until 2026-03-31 00:05 UTC)', '2026-03-30' === $p28_due4 );
check( 'E20-1: hold ending the morning after the switch → dueDate tomorrow (2026-03-29 UTC), hold to 2026-03-30 00:05 UTC', '2026-03-29' === $p28_due5 && (int) strtotime( '2026-03-30 00:05:00 UTC' ) === $p28_hold5 );

echo "E20-1: dueDate in the create request and in the PATCH after the hold was extended\n";
list( $p28_t6 ) = p28_type( 10 * DAY_IN_SECONDS );
$p28_o6 = p28_order( $p28_t6, 'mollie', array( 'mollie_method' => 'banktransfer' ) );
list( $p28_pay6, $p28_body6 ) = p28_create( $p28_o6, 'tr_p28bank6' );
$p28_hold6 = p28_hold( $p28_o6 );
check( 'E20-1: bank transfer chosen → request has method banktransfer and dueDate = last UTC day ending before the extended hold', ! is_wp_error( $p28_pay6 ) && 'banktransfer' === ( $p28_body6['method'] ?? '' ) && gmdate( 'Y-m-d', $p28_hold6 - $p28_buffer - DAY_IN_SECONDS ) === ( $p28_body6['dueDate'] ?? '' ) && ! isset( $p28_body6['expiresAt'] ) );
check( 'E20-1: the extended hold outlasts the whole due date', (int) strtotime( ( $p28_body6['dueDate'] ?? '' ) . ' 00:00:00 UTC' ) + DAY_IN_SECONDS <= $p28_hold6 - $p28_buffer );
http_reset();
http_push( array( 'id' => 'tr_p28bank6' ), 200, 'payments/tr_p28bank6' );
p28_hold_at( $p28_o6, $p28_midnight( 6 ) + 3 * HOUR_IN_SECONDS );
$p28_ext = $p28_mollie->after_hold_extended( (array) Orders::get( $p28_o6 ), array( 'method' => 'banktransfer', 'provider_reference' => 'tr_p28bank6' ) );
$p28_patch = http_requests( 'payments/tr_p28bank6' );
check( 'E20-1: hold extended → PATCH dueDate follows the new hold end', true === $p28_ext && $p28_patch && 'PATCH' === $p28_patch[0]['method'] && gmdate( 'Y-m-d', $p28_midnight( 5 ) ) === ( json_decode( (string) $p28_patch[0]['body'], true )['dueDate'] ?? '' ) );
http_reset();
check( 'E20-1: no PATCH for an immediate method', true === $p28_mollie->after_hold_extended( (array) Orders::get( $p28_o6 ), array( 'method' => 'ideal', 'provider_reference' => 'tr_p28bank6' ) ) && array() === http_requests( 'payments/' ) );

echo "E20-1: Mollie immediate methods – response expiresAt aligns the hold\n";
list( $p28_t7 ) = p28_type();
$p28_o7 = p28_order( $p28_t7 );
p28_hold_at( $p28_o7, time() + 10 * MINUTE_IN_SECONDS );
$p28_exp7 = time() + 20 * MINUTE_IN_SECONDS;
list( $p28_pay7, $p28_body7 ) = p28_create( $p28_o7, 'tr_p28ideal7', array( 'expiresAt' => gmdate( 'Y-m-d\TH:i:sP', $p28_exp7 ) ) );
check( 'E20-1: no expiry field in the request of an immediate method (Mollie has none), no dueDate', ! is_wp_error( $p28_pay7 ) && array( 'ideal' ) === ( $p28_body7['method'] ?? null ) && ! isset( $p28_body7['dueDate'] ) && ! isset( $p28_body7['expiresAt'] ) );
check( 'E20-1: expiresAt after the hold → hold extended to expiresAt + buffer', abs( p28_hold( $p28_o7 ) - ( $p28_exp7 + $p28_buffer ) ) <= 1 );
list( $p28_t8 ) = p28_type();
$p28_o8 = p28_order( $p28_t8 );
p28_hold_at( $p28_o8, time() + 10 * MINUTE_IN_SECONDS );
p28_create( $p28_o8, 'tr_p28ideal8', array( 'expiresAt' => gmdate( 'Y-m-d\TH:i:sP', time() + 3 * HOUR_IN_SECONDS ) ) );
check( 'E20-1: a long payment window extends the hold to at most HOSTED_HOLD_MINUTES from now', abs( p28_hold( $p28_o8 ) - ( time() + Orders::HOSTED_HOLD_MINUTES * MINUTE_IN_SECONDS ) ) <= 2 );
list( $p28_t9 ) = p28_type();
$p28_o9 = p28_order( $p28_t9 );
$p28_hold9 = time() + 25 * MINUTE_IN_SECONDS;
p28_hold_at( $p28_o9, $p28_hold9 );
p28_create( $p28_o9, 'tr_p28ideal9', array( 'expiresAt' => gmdate( 'Y-m-d\TH:i:sP', time() + 15 * MINUTE_IN_SECONDS ) ) );
$p28_same9 = $p28_hold9 === p28_hold( $p28_o9 );
p28_create( $p28_o9, 'tr_p28idealx', array() );
check( 'E20-1: expiresAt before the hold, or none → hold unchanged', $p28_same9 && $p28_hold9 === p28_hold( $p28_o9 ) );
list( $p28_t10, $p28_occ10 ) = p28_type();
$p28_o10 = p28_order( $p28_t10 );
$p28_start10 = time() + 12 * MINUTE_IN_SECONDS;
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $p28_start10 ) ), array( 'occurrence_id' => $p28_occ10 ) );
p28_hold_at( $p28_o10, time() + 5 * MINUTE_IN_SECONDS );
p28_create( $p28_o10, 'tr_p28ideal10', array( 'expiresAt' => gmdate( 'Y-m-d\TH:i:sP', time() + 20 * MINUTE_IN_SECONDS ) ) );
check( 'E20-1: the aligned hold never outlasts the start', $p28_start10 === p28_hold( $p28_o10 ) );

echo "E20-1: fallback – lapsed hold voids the Mollie payment; late payment is recovered\n";
list( $p28_t11 ) = p28_type();
$p28_o11 = p28_order( $p28_t11 );
Orders::set_provider_reference( $p28_o11, 'tr_p28void11' );
expire_holds_of( $p28_o11 );
Orders::expire_holds( 10, array( $p28_o11 ) );
http_reset();
http_push( array( 'id' => 'tr_p28void11', 'status' => 'open', 'isCancelable' => true ), 200, 'payments/tr_p28void11' );
http_push( array( 'id' => 'tr_p28void11', 'status' => 'canceled' ), 200, 'payments/tr_p28void11' );
PassCaptain\Jobs::process_due( 50 );
$p28_void = http_requests( 'payments/tr_p28void11' );
check( 'E20-1: hold lapsed → order expired, open Mollie payment deleted (void job)', 'expired' === Orders::get( $p28_o11 )->payment_status && 2 === count( $p28_void ) && 'DELETE' === $p28_void[1]['method'] );
$p28_o11o = Orders::get( $p28_o11 );
$p28_late = Webhooks::process( 'mollie', MollieGateway::normalize( array( 'resource' => 'payment', 'id' => 'tr_p28void11', 'status' => 'paid', 'method' => 'ideal', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ), 'metadata' => array( 'order' => $p28_o11o->public_id ) ) ) );
check( 'E20-1: a payment Mollie accepted anyway → late-payment recovery (paid, ticket issued while capacity is free)', 'paid' === $p28_late && 'paid' === Orders::get( $p28_o11 )->payment_status && 1 === count( tickets( $p28_o11, 'valid' ) ) );

echo "E20-1: PayPal – no capture without an active hold (status poll too)\n";
Settings::update( array( 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1' ) );
Registry::reset();
$p28_pp = Registry::get( 'paypal' );
list( $p28_t12 ) = p28_type();
$p28_o12 = p28_order( $p28_t12, 'paypal' );
Orders::set_provider_reference( $p28_o12, 'PAYP28A' );
$p28_o12o = Orders::get( $p28_o12 );
$p28_capture = array( 'id' => 'PAYP28A', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'custom_id' => $p28_o12o->public_id, 'payments' => array( 'captures' => array( array( 'id' => 'CAPP28A', 'status' => 'COMPLETED', 'custom_id' => $p28_o12o->public_id, 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ) ) ) ) ) );
p28_hold_at( $p28_o12, time() - 60 );
http_reset();
http_push( array( 'access_token' => 'P28-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'PAYP28A', 'status' => 'APPROVED', 'purchase_units' => array( array( 'custom_id' => $p28_o12o->public_id ) ) ), 200, '/v2/checkout/orders/PAYP28A' );
$p28_poll = $p28_pp->poll_payment( (array) $p28_o12o );
check( 'E20-1: PayPal status poll after the hold ended: approved order is not captured', null === $p28_poll && array() === http_requests( '/capture' ) );
$p28_ret = PayPalGateway::handle_return( $p28_o12o->public_id, 'PAYP28A' );
check( 'E20-1: PayPal return after the hold ended: no capture ("Reservierung abgelaufen")', is_wp_error( $p28_ret ) && 'wpcap_paypal_hold_expired' === $p28_ret->get_error_code() && array() === http_requests( '/capture' ) );
p28_hold_at( $p28_o12, time() + 10 * MINUTE_IN_SECONDS );
http_reset();
http_push( array( 'access_token' => 'P28-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'PAYP28A', 'status' => 'APPROVED', 'purchase_units' => array( array( 'custom_id' => $p28_o12o->public_id ) ) ), 200, '/v2/checkout/orders/PAYP28A' );
http_push( $p28_capture, 201, '/v2/checkout/orders/PAYP28A/capture' );
$p28_poll = $p28_pp->poll_payment( (array) Orders::get( $p28_o12 ) );
check( 'E20-1: PayPal status poll with an active hold: captured (paid event)', is_array( $p28_poll ) && 'paid' === ( $p28_poll['type'] ?? '' ) && 1 === count( http_requests( '/capture' ) ) );
list( $p28_t13 ) = p28_type();
$p28_o13 = p28_order( $p28_t13, 'paypal' );
http_reset();
http_push( array( 'access_token' => 'P28-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'PAYP28B', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYP28B' ) ) ), 201, '/v2/checkout/orders' );
$p28_pp_pay = $p28_pp->create_payment( (array) Orders::get( $p28_o13 ), order_items_array( $p28_o13 ) );
$p28_pp_body = (string) ( http_requests( '/v2/checkout/orders' )[0]['body'] ?? '' );
check( 'E20-1: PayPal order with intent CAPTURE and no expiry field (Orders API v2 has none); void is a no-op', ! is_wp_error( $p28_pp_pay ) && 'CAPTURE' === ( json_decode( $p28_pp_body, true )['intent'] ?? '' ) && ! preg_match( '/expir|valid_until|due_?date/i', $p28_pp_body ) && true === $p28_pp->void_payment( (array) Orders::get( $p28_o13 ) ) );

// Cleanup: release the holds of this module's orders and restore settings, clock and time zones.
p28_at( null );
http_reset();
foreach ( $p28_orders as $p28_id ) {
	if ( 'pending' === Orders::get( $p28_id )->payment_status ) { expire_holds_of( $p28_id ); }
}
Orders::expire_holds( 50, $p28_orders );
PassCaptain\Jobs::process_due( 50 );
http_reset();
Settings::update( $p28_saved );
Registry::reset();
if ( $p28_reconcile ) {
	remove_action( 'wpcap_order_cancelled', array( Reconcile::class, 'order_cancelled' ), 20 );
	remove_filter( 'wpcap_process_job', array( Reconcile::class, 'handle_job' ), 10 );
	remove_action( 'wpcap_process_jobs', array( Reconcile::class, 'run_poll' ), 5 );
}
check( 'E20-1: isolation – real clock, PHP and site time zone and settings restored', ! array_key_exists( 'wpcap_now', $GLOBALS['wpcap_test_filters'] ?? array() ) && $p28_saved_php_tz === date_default_timezone_get() && ( $GLOBALS['wpcap_test_options']['timezone_string'] ?? null ) === $p28_saved_tz && $p28_saved === array_intersect_key( Settings::all(), $p28_saved ) );
