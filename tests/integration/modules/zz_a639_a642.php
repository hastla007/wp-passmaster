<?php
/**
 * A639: public checkout idempotency – minimum key length, no provider switch on a retry, clean-up
 * cancellations only for unpaid orders. A642: provider error texts never reach anonymous callers.
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a639_saved = array( 'enabled_providers' => Settings::get( 'enabled_providers' ), 'require_terms' => Settings::get( 'require_terms' ), 'bank_iban' => Settings::get( 'bank_iban' ), 'bank_recipient' => Settings::get( 'bank_recipient' ) );

final class A639_Gateway implements PassCaptain\Payments\GatewayInterface {
	public $confirm_first = false;
	public function id() { return 'a639test'; }
	public function title() { return 'A639'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) {
		// Simulates a concurrent confirmation of the same attempt (e.g. a webhook of a retried request).
		if ( $this->confirm_first ) { Orders::confirm_external_payment( (int) $order['order_id'], 'a639-concurrent' ); }
		return new WP_Error( 'wpcap_a639_api', 'Provider-Fehler: Invalid API Key provided: sk_live_A639SECRETVALUE (acct_123)', array( 'status' => 401 ) );
	}
	public function verify_webhook( \WP_REST_Request $request ) { return new WP_Error( 'no', 'no' ); }
	public function supports_refunds() { return false; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { return new WP_Error( 'no', 'no' ); }
}
$a639_gateway = new A639_Gateway();
$a639_filter = static function ( $list ) use ( $a639_gateway ) { $list[] = $a639_gateway; return $list; };
add_filter( 'wpcap_payment_gateways', $a639_filter );
Registry::reset();
Settings::update( array( 'enabled_providers' => array( 'manual', 'a639test' ), 'require_terms' => 0, 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Testverein' ) );

$a639_request = static function ( array $body ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	return $r;
};
$a639_type = make_type( 20, 10, 1500 );
$a639_body = static fn( $key, $provider, $email = 'a639@example.com' ) => array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $a639_type, 'quantity' => 1 ) ), 'provider' => $provider, 'idempotency_key' => $key );
$a639_count = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );

echo "A639: checkout idempotency keys\n";
$GLOBALS['wpcap_test_transients'] = array();
$before = $a639_count();
$short = Controller::checkout( $a639_request( $a639_body( 'abc123', 'manual' ) ) );
check( 'A639: a short client idempotency key is refused before an order exists', is_wp_error( $short ) && 'wpcap_idempotency_key' === $short->get_error_code() && 400 === $short->get_error_data()['status'] && $before === $a639_count() );
$no_key = $a639_body( '', 'manual', 'a639-nokey@example.com' );
unset( $no_key['idempotency_key'] );
check( 'A639: without a key the server generates one', Controller::checkout( $a639_request( $no_key ) ) instanceof WP_REST_Response );

$key = wp_generate_uuid4();
$first = Controller::checkout( $a639_request( $a639_body( $key, 'manual', 'a639-switch@example.com' ) ) );
$switch = Controller::checkout( $a639_request( $a639_body( $key, 'a639test', 'a639-switch@example.com' ) ) );
// Merged: the checkout response carries no order_id (found by e-mail). Z1: the provider switch is checked before the
// request fingerprint, so the buyer gets the specific error (before, wpcap_checkout_provider_changed was unreachable).
$first_order = $first instanceof WP_REST_Response ? Orders::get( (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s ORDER BY order_id DESC LIMIT 1", 'a639-switch@example.com' ) ) ) : null;
check( 'A639: a retry with the same key but another payment method is refused', $first_order && is_wp_error( $switch ) && 'wpcap_checkout_provider_changed' === $switch->get_error_code() && 'provider' === ( $switch->get_error_data()['field'] ?? '' ) && 409 === $switch->get_error_data()['status'] && 'pending' === Orders::get( $first_order->order_id )->payment_status && 'manual' === Orders::get( $first_order->order_id )->payment_provider );
$a639_changed = $a639_body( $key, 'manual', 'a639-switch@example.com' );
$a639_changed['items'][0]['quantity'] = 2;
$a639_mismatch = Controller::checkout( $a639_request( $a639_changed ) );
check( 'A639/Z1: same method, other tickets on the same key → the cart check of Orders::create() (A420) still answers (409)', is_wp_error( $a639_mismatch ) && 'wpcap_idempotency_conflict' === $a639_mismatch->get_error_code() && 409 === $a639_mismatch->get_error_data()['status'] );
$same = Controller::checkout( $a639_request( $a639_body( $key, 'manual', 'a639-switch@example.com' ) ) );
check( 'A639: a retry with the same method is still answered with the order', $same instanceof WP_REST_Response && 200 === $same->get_status() && ! empty( $same->get_data()['reused'] ) && $first instanceof WP_REST_Response && $same->get_data()['reference'] === $first->get_data()['reference'] );

echo "A642: payment start errors are generic for the buyer\n";
$GLOBALS['wpcap_test_transients'] = array();
$fail = Controller::checkout( $a639_request( $a639_body( wp_generate_uuid4(), 'a639test', 'a639-fail@example.com' ) ) );
$fail_order = (int) $wpdb->get_var( "SELECT MAX(order_id) FROM {$p}wpcap_orders WHERE customer_email='a639-fail@example.com'" );
$audit = (string) $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='payment_start_failed' AND object_id=%d ORDER BY audit_id DESC LIMIT 1", $fail_order ) );
check( 'A642: the buyer gets a generic error without provider text or internal code', is_wp_error( $fail ) && 'wpcap_payment_start_failed' === $fail->get_error_code() && 502 === $fail->get_error_data()['status'] && false === strpos( $fail->get_error_message(), 'API Key' ) && false === strpos( wp_json_encode( $fail->get_error_data() ), 'a639' ) );
check( 'A642: the details are in the audit log, redacted', false !== strpos( $audit, 'wpcap_a639_api' ) && false !== strpos( $audit, 'Invalid API Key' ) && false === strpos( $audit, 'A639SECRETVALUE' ) );
check( 'A642: the failed attempt is cancelled as before', 'cancelled' === Orders::get( $fail_order )->status );

echo "A639: clean-up cancellation only for unpaid orders\n";
$GLOBALS['wpcap_test_transients'] = array();
$a639_gateway->confirm_first = true;
$race = Controller::checkout( $a639_request( $a639_body( wp_generate_uuid4(), 'a639test', 'a639-race@example.com' ) ) );
$a639_gateway->confirm_first = false;
$race_order = Orders::get( (int) $wpdb->get_var( "SELECT MAX(order_id) FROM {$p}wpcap_orders WHERE customer_email='a639-race@example.com'" ) );
check( 'A639: an order paid meanwhile is not cancelled by the failed payment start', is_wp_error( $race ) && $race_order && 'paid' === $race_order->payment_status && 'complete' === $race_order->status && 1 === count( tickets( (int) $race_order->order_id, 'valid' ) ) );

remove_filter( 'wpcap_payment_gateways', $a639_filter );
Registry::reset();
Settings::update( $a639_saved );
