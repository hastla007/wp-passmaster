<?php
/**
 * A349: after the return from PayPal the buyer only reads "nothing was charged" (failed) when no
 * capture can have happened; a successful capture that could not be booked and every unclear
 * outcome show "pending".
 *
 * Included from run.php (uses check(), make_type() and the HTTP fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\ReturnNotice;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

echo "A349: PayPal return notice follows the capture outcome\n";
Settings::update( array( 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1' ) );
Registry::reset();
$a349_type = make_type( 50, 10, 2000 );
$a349_n = 0;
$a349_order = static function () use ( $a349_type, &$a349_n ) {
	$o = provider_order( 'paypal', $a349_type, 1, 'a349-' . ( ++$a349_n ) );
	Orders::set_provider_reference( $o['order_id'], 'A349ORDER' . $a349_n );
	return array( $o, 'A349ORDER' . $a349_n );
};
$a349_capture = static function ( $o, $status, $value = '20.00' ) {
	return array( 'id' => 'X', 'status' => $status, 'purchase_units' => array( array( 'reference_id' => $o['public_id'], 'payments' => array( 'captures' => array( array( 'id' => 'CAP-' . $o['public_id'], 'status' => $status, 'custom_id' => $o['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => $value ) ) ) ) ) ) );
};
$a349_return = static function ( $o, $token, ...$responses ) {
	http_reset();
	http_push( array( 'access_token' => 'A21-a349', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
	foreach ( $responses as $r ) { $GLOBALS['wpcap_test_http_queue'][] = $r; }
	return PayPalGateway::handle_return( $o['public_id'], $token );
};

check( 'A349: "failed" really says nothing was charged; "pending" does not', false !== strpos( ReturnNotice::message( 'failed' ), 'nichts abgebucht' ) && false === strpos( ReturnNotice::message( 'pending' ), 'nichts abgebucht' ) );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'error' => new WP_Error( 'http_request_failed', 'timeout' ), 'match' => '/capture' ) );
check( 'A349: network error during the capture (may have been captured): pending, not failed', is_wp_error( $a349_r ) && 'pending' === PayPalGateway::return_state( $a349_r ) );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => array( 'name' => 'INTERNAL_SERVER_ERROR' ), 'code' => 500, 'match' => '/capture' ) );
check( 'A349: PayPal 5xx on the capture: pending', is_wp_error( $a349_r ) && 'pending' === PayPalGateway::return_state( $a349_r ) );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'INSTRUMENT_DECLINED', 'description' => 'declined' ) ) ), 'code' => 422, 'match' => '/capture' ) );
check( 'A349: PayPal refuses the capture (422 INSTRUMENT_DECLINED): failed', is_wp_error( $a349_r ) && 'failed' === PayPalGateway::return_state( $a349_r ) && 'pending' === Orders::get( $a349_o['order_id'] )->payment_status );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => $a349_capture( $a349_o, 'DECLINED' ), 'code' => 201, 'match' => '/capture' ) );
check( 'A349: capture status DECLINED: failed', is_wp_error( $a349_r ) && 'failed' === PayPalGateway::return_state( $a349_r ) );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => $a349_capture( $a349_o, 'COMPLETED', '0.01' ), 'code' => 201, 'match' => '/capture' ) );
check( 'A349: captured, but the amount does not match (money was taken): pending', is_wp_error( $a349_r ) && 'pending' === PayPalGateway::return_state( $a349_r ) && ! tickets( $a349_o['order_id'] ) );

list( $a349_o, $a349_t ) = $a349_order();
wpcap_test_fault( '/^INSERT IGNORE INTO \S*wpcap_payment_events/', 'lock_timeout' );
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => $a349_capture( $a349_o, 'COMPLETED' ), 'code' => 201, 'match' => '/capture' ) );
wpcap_test_faults_clear();
check( 'A349: captured, but the booking hit a busy database: pending (the webhook books it later)', is_wp_error( $a349_r ) && 'pending' === PayPalGateway::return_state( $a349_r ) && 'pending' === Orders::get( $a349_o['order_id'] )->payment_status );

list( $a349_o, $a349_t ) = $a349_order();
http_reset();
$a349_r = PayPalGateway::handle_return( $a349_o['public_id'], 'FOREIGN' );
check( 'A349: foreign token (no capture attempted): failed', is_wp_error( $a349_r ) && 'failed' === PayPalGateway::return_state( $a349_r ) && ! $GLOBALS['wpcap_test_http_requests'] );
check( 'A349: unknown order: failed', 'failed' === PayPalGateway::return_state( PayPalGateway::handle_return( 'nope-a349', 'X' ) ) );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => $a349_capture( $a349_o, 'PENDING' ), 'code' => 201, 'match' => '/capture' ) );
check( 'A349: pending capture: pending', 'pending' === PayPalGateway::return_state( $a349_r ) );

list( $a349_o, $a349_t ) = $a349_order();
$a349_r = $a349_return( $a349_o, $a349_t, array( 'body' => $a349_capture( $a349_o, 'COMPLETED' ), 'code' => 201, 'match' => '/capture' ) );
check( 'A349: completed and booked: success', 'paid' === $a349_r && 'success' === PayPalGateway::return_state( $a349_r ) && 1 === count( tickets( $a349_o['order_id'], 'valid' ) ) );
check( 'A349: a booked capture that issues no tickets (needs review, closed order) is no "success"', 'pending' === PayPalGateway::return_state( 'needs_review' ) && 'pending' === PayPalGateway::return_state( 'paid_for_closed_order' ) && 'success' === PayPalGateway::return_state( 'duplicate' ) );
http_reset();
