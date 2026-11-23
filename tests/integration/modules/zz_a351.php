<?php
/**
 * A351: Mollie pay-later methods (Klarna, Riverty) end in `authorized` and need a capture. They are
 * no longer offered, and an authorized payment that still arrives is captured for a payable order
 * (and never for a closed one or a different amount).
 *
 * Included from run.php after modules/mollie.php (uses mollie_payment(), mollie_webhook()).
 */
// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
use PassCaptain\Modules\Mollie;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;

echo "A351: pay-later methods are not offered\n";
$a351_before = array( 'mollie' => Settings::get( 'mollie' ), 'mollie_api_key' => PassCaptain\Secrets::get( 'mollie_api_key' ), 'enabled_providers' => Settings::get( 'enabled_providers' ) );
Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Ab1', 10 ), 'enabled_providers' => array( 'manual', 'mollie' ), 'mollie' => array( 'methods' => array( 'ideal', 'klarna', 'riverty' ), 'billing_country' => '' ) ) );
Registry::reset();
check( 'A351: Klarna and Riverty are not in the settings list', ! array_intersect( array( 'klarna', 'riverty' ), Mollie::KNOWN_METHODS ) );
check( 'A351: stored Klarna/Riverty are dropped from the configuration', array( 'ideal' ) === MollieGateway::config()['methods'] );
$a351_saved = Mollie::save( array( 'methods' => array( 'ideal', 'klarna' ) ) );
check( 'A351: they cannot be saved either', ! is_wp_error( $a351_saved ) && array( 'ideal' ) === Settings::get( 'mollie' )['methods'] );

// Without configured methods the list Mollie offers is passed without pay-later methods.
Settings::update( array( 'mollie' => array( 'methods' => array(), 'billing_country' => '' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
$a351_type = make_type( 20, 10, 2500 );
$a351_r = Orders::create( array( 'email' => 'a351-1@example.com', 'items' => array( array( 'ticket_type_id' => $a351_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a351-1' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 30 ) );
$a351_o = (array) Orders::get( $a351_r['order_id'] );
http_push( array( '_embedded' => array( 'methods' => array( array( 'id' => 'ideal' ), array( 'id' => 'klarna' ), array( 'id' => 'creditcard' ) ) ) ), 200, '/v2/methods' );
http_push( mollie_payment( 'tr_A351one', 'open', $a351_o ), 201, '/v2/payments' );
$a351_pay = Registry::get( 'mollie' )->create_payment( $a351_o );
$a351_body = json_decode( (string) ( http_requests( '/v2/payments' )[0]['body'] ?? '' ), true );
check( 'A351: an unrestricted payment excludes Klarna from the method list', ! is_wp_error( $a351_pay ) && array( 'ideal', 'creditcard' ) === ( $a351_body['method'] ?? null ) );
Orders::set_provider_reference( $a351_o['order_id'], 'tr_A351one' );

echo "A351: an authorized payment is captured\n";
http_reset();
http_push( mollie_payment( 'tr_A351one', 'authorized', $a351_o, array( 'method' => 'klarna' ) ), 200, '/v2/payments/tr_A351one?' );
$GLOBALS['wpcap_test_http_queue'][] = array( 'error' => new WP_Error( 'http_request_failed', 'timeout' ), 'match' => '/captures' );
$a351_first = Webhooks::handle( mollie_webhook( 'tr_A351one' ) );
// Merged design: the generic capture path (Webhooks payment_authorized → MollieGateway::capture_authorized());
// every processing error of a webhook is answered 5xx (500), the claim is released for the redelivery.
check( 'A351: a failed capture call is answered 503 (Mollie redelivers)', is_wp_error( $a351_first ) && (int) ( $a351_first->get_error_data()['status'] ?? 0 ) >= 500 && 'pending' === Orders::get( $a351_o['order_id'] )->payment_status );
http_reset();
http_push( mollie_payment( 'tr_A351one', 'authorized', $a351_o, array( 'method' => 'klarna' ) ), 200, '/v2/payments/tr_A351one?' );
http_push( array( 'resource' => 'capture', 'id' => 'cpt_A351', 'status' => 'pending' ), 201, '/v2/payments/tr_A351one/captures' );
$a351_second = Webhooks::handle( mollie_webhook( 'tr_A351one' ) );
$a351_cap = http_requests( '/captures' )[0] ?? array( 'headers' => array(), 'method' => '' );
check( 'A351: the redelivery captures the full amount once (fixed Idempotency-Key)', ! is_wp_error( $a351_second ) && 'POST' === $a351_cap['method'] && 'wpcap-capture-tr_A351one' === ( $a351_cap['headers']['Idempotency-Key'] ?? '' ) );
check( 'A351: no tickets yet (the payment is not paid), the capture is audited', 'pending' === Orders::get( $a351_o['order_id'] )->payment_status && ! tickets( $a351_o['order_id'] ) && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='payment_capture_requested' AND object_id=%d", $a351_o['order_id'] ) ) > 0 );
http_reset();
http_push( mollie_payment( 'tr_A351one', 'paid', $a351_o, array( 'method' => 'klarna' ) ), 200, '/v2/payments/tr_A351one?' );
Webhooks::handle( mollie_webhook( 'tr_A351one' ) );
check( 'A351: the following "paid" webhook issues the ticket', 'paid' === Orders::get( $a351_o['order_id'] )->payment_status && 1 === count( tickets( $a351_o['order_id'], 'valid' ) ) );

echo "A351: closed orders and other amounts are never captured\n";
$a351_r2 = Orders::create( array( 'email' => 'a351-2@example.com', 'items' => array( array( 'ticket_type_id' => $a351_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a351-2' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 30 ) );
Orders::set_provider_reference( $a351_r2['order_id'], 'tr_A351two' );
$a351_o2 = (array) Orders::get( $a351_r2['order_id'] );
Orders::cancel_order( $a351_r2['order_id'], 'test' );
http_reset();
http_push( mollie_payment( 'tr_A351two', 'authorized', $a351_o2 ), 200, '/v2/payments/tr_A351two?' );
$a351_closed = Webhooks::handle( mollie_webhook( 'tr_A351two' ) );
check( 'A351: authorization for a cancelled order: acknowledged, not captured', ! is_wp_error( $a351_closed ) && ! http_requests( '/captures' ) );
$a351_r3 = Orders::create( array( 'email' => 'a351-3@example.com', 'items' => array( array( 'ticket_type_id' => $a351_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a351-3' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 30 ) );
Orders::set_provider_reference( $a351_r3['order_id'], 'tr_A351three' );
$a351_o3 = (array) Orders::get( $a351_r3['order_id'] );
http_reset();
http_push( mollie_payment( 'tr_A351three', 'authorized', $a351_o3, array( 'amount' => array( 'value' => '0.01', 'currency' => 'EUR' ) ) ), 200, '/v2/payments/tr_A351three?' );
Webhooks::handle( mollie_webhook( 'tr_A351three' ) );
check( 'A351: authorization of a different amount is not captured', ! http_requests( '/captures' ) && 'pending' === Orders::get( $a351_r3['order_id'] )->payment_status );
check( 'A351: the return handler shows "pending" for an authorized payment', 'pending' === ( static function () use ( $a351_o3 ) { http_reset(); http_push( mollie_payment( 'tr_A351three', 'authorized', $a351_o3 ), 200, '/v2/payments/tr_A351three' ); return Mollie::handle_return( $a351_o3['public_id'] ); } )() );

Settings::update( $a351_before );
Registry::reset();
http_reset();
