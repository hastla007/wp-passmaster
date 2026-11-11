<?php
/**
 * Mollie adapter: create payment, webhook (fetch-by-id authenticity), status mapping, refunds with
 * idempotency key, external refunds, method availability (cached) and the return handler.
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Mollie;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/wp-pass-captain/' ); }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }

Mollie::register();
global $wpdb;
$p = $wpdb->prefix;
$api_key = 'test_' . str_repeat( 'Ab1', 10 );

function mollie_payment( $id, $status, $order, array $extra = array() ) {
	return array_merge( array( 'resource' => 'payment', 'id' => $id, 'status' => $status, 'amount' => array( 'value' => MollieGateway::format_amount( (int) $order['total_minor'], $order['currency'] ), 'currency' => $order['currency'] ), 'metadata' => array( 'order' => $order['public_id'] ), '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/select-method/' . $id ) ) ), $extra );
}
function mollie_webhook( $id ) {
	$req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/payments/mollie/webhook' );
	$req->set_body( 'id=' . $id );
	$req['provider'] = 'mollie';
	return $req;
}
function mollie_deliver( $gateway, $id ) {
	$event = $gateway->verify_webhook( mollie_webhook( $id ) );
	return is_wp_error( $event ) ? $event : Webhooks::process( 'mollie', $event );
}

echo "Mollie: registration and amounts\n";
Registry::reset();
check( 'gateway not available without API key', Registry::get( 'mollie' ) instanceof MollieGateway && ! Registry::get( 'mollie' )->is_available( 'EUR' ) );
Settings::update( array( 'mollie_api_key' => $api_key, 'enabled_providers' => array( 'manual', 'mollie' ), 'refund_approval_threshold' => 0 ) );
$mollie = Registry::get( 'mollie' );
check( 'available for supported currencies only', $mollie->is_available( 'EUR' ) && $mollie->is_available( 'chf' ) && ! $mollie->is_available( 'XXX' ) && $mollie->supports_refunds() );
check( 'amount strings use currency decimals', '45.00' === MollieGateway::format_amount( 4500, 'EUR' ) && '0.05' === MollieGateway::format_amount( 5, 'EUR' ) && '1500' === MollieGateway::format_amount( 1500, 'JPY' ) && 4500 === MollieGateway::parse_amount( '45.00', 'EUR' ) && 4550 === MollieGateway::parse_amount( '45.5', 'EUR' ) && 1500 === MollieGateway::parse_amount( '1500', 'JPY' ) && -1 === MollieGateway::parse_amount( '4,50', 'EUR' ) );

echo "Mollie: methods\n";
Settings::update( array( 'mollie' => array( 'methods' => array( 'ideal', 'creditcard', 'klarna' ), 'billing_country' => 'NL' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
http_push( array( '_embedded' => array( 'methods' => array( array( 'id' => 'ideal', 'description' => 'iDEAL' ), array( 'id' => 'creditcard', 'description' => 'Kreditkarte' ), array( 'id' => 'bancontact', 'description' => 'Bancontact' ) ) ) ), 200, '/v2/methods' );
$methods = MollieGateway::selectable_methods( 2500, 'EUR' );
$mq = http_requests( '/v2/methods' )[0] ?? array( 'url' => '' );
parse_str( (string) parse_url( $mq['url'], PHP_URL_QUERY ), $q );
check( 'only enabled methods Mollie offers for amount/currency', array( 'ideal' => 'iDEAL', 'creditcard' => 'Kreditkarte' ) === $methods );
check( 'methods queried with amount, currency and billing country', '25.00' === ( $q['amount']['value'] ?? '' ) && 'EUR' === ( $q['amount']['currency'] ?? '' ) && 'NL' === ( $q['billingCountry'] ?? '' ) );
http_reset();
MollieGateway::selectable_methods( 2500, 'EUR' );
check( 'methods are cached', ! http_requests( '/v2/methods' ) );
$type = make_type( 30, 10, 2500 );
$types = $wpdb->get_results( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
ob_start(); Mollie::render_checkout_fields( (object) array( 'occurrence_id' => 3 ), $types ); $html = ob_get_clean();
check( 'checkout shows a labelled method selector', false !== strpos( $html, '<legend>' ) && false !== strpos( $html, 'value="ideal"' ) && false === strpos( $html, 'bancontact' ) );

echo "Mollie: create payment\n";
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
$r = Orders::create( array( 'email' => 'mol-1@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'mol-1', 'mollie_method' => 'ideal' ), array( 'payment_provider' => 'mollie', 'hold_minutes' => 30 ) );
$order = (array) Orders::get( $r['order_id'] );
check( 'chosen method stored for the order', 'ideal' === Mollie::chosen_method( $order['order_id'] ) );
$GLOBALS['wpcap_test_transients'] = array();
http_push( array( '_embedded' => array( 'methods' => array( array( 'id' => 'ideal', 'description' => 'iDEAL' ) ) ) ), 200, '/v2/methods' );
http_push( mollie_payment( 'tr_Test1234', 'open', $order ), 201, '/v2/payments' );
$pay = $mollie->create_payment( $order, order_items_array( $order['order_id'] ) );
$cr = http_requests( '/v2/payments' )[0] ?? array( 'headers' => array(), 'body' => '', 'method' => '', 'url' => '' );
$body = json_decode( $cr['body'], true );
check( 'create returns the checkout redirect and payment id', ! is_wp_error( $pay ) && 'mollie' === $pay['provider'] && 'tr_Test1234' === $pay['provider_reference'] && 0 === strpos( $pay['redirect_url'], 'https://www.mollie.com/checkout/' ) );
check( 'POST /v2/payments with bearer key and idempotency key', 'POST' === $cr['method'] && 'https://api.mollie.com/v2/payments' === $cr['url'] && 'Bearer ' . $api_key === $cr['headers']['Authorization'] && 'wpcap-mollie-' . $order['public_id'] === $cr['headers']['Idempotency-Key'] );
check( 'amount string, description, metadata, method', array( 'currency' => 'EUR', 'value' => '50.00' ) === $body['amount'] && $order['public_id'] === $body['metadata']['order'] && 'ideal' === $body['method'] && false !== strpos( $body['description'], $order['public_id'] ) && 'de_DE' === $body['locale'] );
check( 'webhookUrl and redirectUrl', 'https://example.test/wp-json/wp-pass-captain/v1/payments/mollie/webhook' === $body['webhookUrl'] && false !== strpos( $body['redirectUrl'], 'wpcap_mollie_return=1' ) && false !== strpos( $body['redirectUrl'], $order['public_id'] ) );
Orders::set_provider_reference( $order['order_id'], 'tr_Test1234' );
http_reset();
http_push( mollie_payment( 'tr_Test1234', 'open', $order ), 200, '/v2/payments/tr_Test1234' );
$again = $mollie->create_payment( (array) Orders::get( $order['order_id'] ) );
check( 'retried checkout reuses the open payment', ! is_wp_error( $again ) && 'tr_Test1234' === $again['provider_reference'] && 1 === count( $GLOBALS['wpcap_test_http_requests'] ) );
http_reset();
http_push( array( 'status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'The amount is lower than the minimum' ), 422, '/v2/payments' );
$err = $mollie->create_payment( (array) Orders::get( Orders::create( array( 'email' => 'mol-err@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'mol-err' ), array( 'payment_provider' => 'mollie' ) )['order_id'] ) );
check( 'Mollie error detail is returned', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'lower than the minimum' ) );

echo "Mollie: webhook\n";
http_reset();
$bad = $mollie->verify_webhook( mollie_webhook( 'evil' ) );
check( 'malformed id rejected without API call', is_wp_error( $bad ) && 400 === $bad->get_error_data()['status'] && ! $GLOBALS['wpcap_test_http_requests'] );
$unknown = $mollie->verify_webhook( mollie_webhook( 'tr_unknown1' ) );
check( 'unknown payment id: acknowledged as ignored without an API call (no amplification)', ! is_wp_error( $unknown ) && 'ignored' === $unknown['type'] && ! $GLOBALS['wpcap_test_http_requests'] );
check( 'payment id created by create_payment is known', MollieGateway::known_payment( 'tr_Test1234' ) && ! MollieGateway::known_payment( 'tr_unknown1' ) );
$GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_ip_limit'] = 0;
$throttled = $mollie->verify_webhook( mollie_webhook( 'tr_Test1234' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_ip_limit'] );
check( 'webhook lookups are throttled per IP (429, no API call)', is_wp_error( $throttled ) && 429 === $throttled->get_error_data()['status'] && ! $GLOBALS['wpcap_test_http_requests'] );
http_push( array( 'detail' => 'boom' ), 500, '/v2/payments/tr_Test1234' );
$down = $mollie->verify_webhook( mollie_webhook( 'tr_Test1234' ) );
check( 'API failure: 503 so Mollie retries', is_wp_error( $down ) && 503 === $down->get_error_data()['status'] && ! tickets( $order['order_id'] ) );
http_reset();
http_push( mollie_payment( 'tr_Test1234', 'open', $order ), 200, '/v2/payments/tr_Test1234' );
check( 'open payment is ignored', 'ignored' === mollie_deliver( $mollie, 'tr_Test1234' ) && ! tickets( $order['order_id'] ) );
http_push( mollie_payment( 'tr_Test1234', 'paid', $order ), 200, '/v2/payments/tr_Test1234' );
$paid = mollie_deliver( $mollie, 'tr_Test1234' );
$wr = http_requests( '/v2/payments/tr_Test1234' );
check( 'webhook fetches the payment from the API (authenticity)', false !== strpos( end( $wr )['url'], 'embed=refunds' ) && 'Bearer ' . $api_key === end( $wr )['headers']['Authorization'] );
check( 'paid payment confirms the order and issues tickets', 'paid' === $paid && 'paid' === Orders::get( $order['order_id'] )->payment_status && 2 === count( tickets( $order['order_id'], 'valid' ) ) );
http_push( mollie_payment( 'tr_Test1234', 'paid', $order ), 200, '/v2/payments/tr_Test1234' );
check( 'repeated webhook is processed once', 'duplicate' === mollie_deliver( $mollie, 'tr_Test1234' ) && 2 === count( tickets( $order['order_id'] ) ) );
$mm = (array) Orders::get( Orders::create( array( 'email' => 'mol-mm@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'mol-mm' ), array( 'payment_provider' => 'mollie' ) )['order_id'] );
Orders::set_provider_reference( $mm['order_id'], 'tr_Mismatch1' );
http_push( mollie_payment( 'tr_Mismatch1', 'paid', $mm, array( 'amount' => array( 'value' => '0.01', 'currency' => 'EUR' ) ) ), 200, '/v2/payments/tr_Mismatch1' );
check( 'amount mismatch issues no tickets', 'amount_mismatch' === mollie_deliver( $mollie, 'tr_Mismatch1' ) && ! tickets( $mm['order_id'] ) );
foreach ( array( 'failed', 'canceled', 'expired' ) as $i => $status ) {
	$fo = (array) Orders::get( Orders::create( array( 'email' => "mol-f$i@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => "mol-f$i" ), array( 'payment_provider' => 'mollie' ) )['order_id'] );
	Orders::set_provider_reference( $fo['order_id'], 'tr_Fail' . $i . 'xx' );
	http_push( mollie_payment( 'tr_Fail' . $i . 'xx', $status, $fo ), 200, '/v2/payments/tr_Fail' . $i );
	check( "$status payment cancels the pending order", 'cancelled' === mollie_deliver( $mollie, 'tr_Fail' . $i . 'xx' ) && 'cancelled' === Orders::get( $fo['order_id'] )->status );
}
$foreign = (array) Orders::get( Orders::create( array( 'email' => 'mol-foreign@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'mol-foreign' ), array( 'payment_provider' => 'stripe' ) )['order_id'] );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_mollie_payments (payment_id,order_id,created_at) VALUES ('tr_Foreign1',%d,%s)", $foreign['order_id'], gmdate( 'Y-m-d H:i:s' ) ) );
http_push( mollie_payment( 'tr_Foreign1', 'paid', $foreign ), 200, '/v2/payments/tr_Foreign1' );
check( 'payment for an order of another provider is ignored', 'ignored' === mollie_deliver( $mollie, 'tr_Foreign1' ) && ! tickets( $foreign['order_id'] ) );

echo "Mollie: refunds\n";
http_reset();
$all = tickets( $order['order_id'] );
http_push( array( 'resource' => 'refund', 'id' => 're_Abc1', 'status' => 'pending', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ) ), 201, '/refunds' );
$rf = PassCaptain\Refunds::request( $order['order_id'], array( (int) $all[0]->ticket_id ), 'Kunde krank', 'mol-r1' );
$rq = http_requests( '/refunds' )[0] ?? array( 'url' => '', 'headers' => array(), 'body' => '' );
$rb = json_decode( $rq['body'], true );
check( 'refund completes with the Mollie refund id', ! is_wp_error( $rf ) && 'completed' === $rf['status'] && 're_Abc1' === $rf['provider_reference'] );
check( 'refund: POST /v2/payments/{id}/refunds, amount string, idempotency key, marker', 'https://api.mollie.com/v2/payments/tr_Test1234/refunds' === $rq['url'] && array( 'currency' => 'EUR', 'value' => '25.00' ) === $rb['amount'] && 'wpcap-refund-' . $rf['refund_id'] === $rq['headers']['Idempotency-Key'] && ! empty( $rb['metadata']['wpcap_refund'] ) );
$own = array( 'refunds' => array( array( 'id' => 're_Abc1', 'status' => 'pending', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ), 'metadata' => array( 'wpcap_refund' => 'wpcap-refund-1' ) ) ) );
http_push( mollie_payment( 'tr_Test1234', 'paid', $order, array( 'amountRefunded' => array( 'value' => '25.00', 'currency' => 'EUR' ), '_embedded' => $own ) ), 200, '/v2/payments/tr_Test1234' );
check( 'webhook for the plugin\'s own refund books nothing twice', 'duplicate' === mollie_deliver( $mollie, 'tr_Test1234' ) && 1 === count( tickets( $order['order_id'], 'valid' ) ) );
$ext = $own;
$ext['refunds'][] = array( 'id' => 're_Dash1', 'status' => 'refunded', 'amount' => array( 'value' => '25.00', 'currency' => 'EUR' ), 'metadata' => null );
http_push( mollie_payment( 'tr_Test1234', 'paid', $order, array( '_embedded' => $ext ) ), 200, '/v2/payments/tr_Test1234' );
$event = $mollie->verify_webhook( mollie_webhook( 'tr_Test1234' ) );
check( 'dashboard refund normalized as refunded with its amount', ! is_wp_error( $event ) && 'refunded' === $event['type'] && 2500 === $event['amount_minor'] && 'mollie-tr_Test1234-refunded-2500' === $event['event_id'] );
check( 'dashboard refund of the remaining amount marks the order refunded', 'refunded' === Webhooks::process( 'mollie', $event ) && 'refunded' === Orders::get( $order['order_id'] )->status );
http_reset();
http_push( array( 'detail' => 'The payment is already refunded' ), 422, '/refunds' );
$o3 = (array) Orders::get( Orders::create( array( 'email' => 'mol-3@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'mol-3' ), array( 'payment_provider' => 'mollie' ) )['order_id'] );
Orders::set_provider_reference( $o3['order_id'], 'tr_Three333' );
http_push( mollie_payment( 'tr_Three333', 'paid', $o3 ), 200, '/v2/payments/tr_Three333' );
mollie_deliver( $mollie, 'tr_Three333' );
$rerr = PassCaptain\Refunds::request( $o3['order_id'], array(), 'x', 'mol-r3' );
check( 'refund error keeps the tickets', is_wp_error( $rerr ) && false !== strpos( $rerr->get_error_message(), 'already refunded' ) && 1 === count( tickets( $o3['order_id'], 'valid' ) ) );

echo "Mollie: chargebacks\n";
$o6 = (array) Orders::get( Orders::create( array( 'email' => 'mol-6@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'mol-6' ), array( 'payment_provider' => 'mollie' ) )['order_id'] );
Orders::set_provider_reference( $o6['order_id'], 'tr_Six66666' );
http_reset();
http_push( mollie_payment( 'tr_Six66666', 'paid', $o6 ), 200, '/v2/payments/tr_Six66666' );
mollie_deliver( $mollie, 'tr_Six66666' );
$cb_reversed = array( 'chargebacks' => array( array( 'id' => 'chb_1', 'amount' => array( 'value' => '50.00', 'currency' => 'EUR' ), 'reversedAt' => '2026-11-10T10:00:00+00:00' ) ) );
http_push( mollie_payment( 'tr_Six66666', 'paid', $o6, array( '_embedded' => $cb_reversed ) ), 200, '/v2/payments/tr_Six66666' );
check( 'reversed chargeback changes nothing', 'duplicate' === mollie_deliver( $mollie, 'tr_Six66666' ) && 2 === count( tickets( $o6['order_id'], 'valid' ) ) );
$wr = http_requests( '/v2/payments/tr_Six66666' );
check( 'webhook embeds refunds and chargebacks', false !== strpos( end( $wr )['url'], 'embed=refunds,chargebacks' ) );
http_push( mollie_payment( 'tr_Six66666', 'paid', $o6, array( 'amountChargedBack' => array( 'value' => '50.00', 'currency' => 'EUR' ) ) ), 200, '/v2/payments/tr_Six66666' );
$cb = $mollie->verify_webhook( mollie_webhook( 'tr_Six66666' ) );
check( 'full chargeback normalized as refunded with a distinct event id', ! is_wp_error( $cb ) && 'refunded' === $cb['type'] && 5000 === $cb['amount_minor'] && 'mollie-tr_Six66666-chargeback-5000' === $cb['event_id'] );
check( 'full chargeback revokes the tickets', 'refunded' === Webhooks::process( 'mollie', $cb ) && 'refunded' === Orders::get( $o6['order_id'] )->status && ! tickets( $o6['order_id'], 'valid' ) );

echo "Mollie: buyer return\n";
$o4 = (array) Orders::get( Orders::create( array( 'email' => 'mol-4@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'mol-4' ), array( 'payment_provider' => 'mollie' ) )['order_id'] );
Orders::set_provider_reference( $o4['order_id'], 'tr_Four4444' );
http_reset();
http_push( mollie_payment( 'tr_Four4444', 'paid', $o4 ), 200, '/v2/payments/tr_Four4444' );
check( 'return after payment confirms immediately (idempotent with webhook)', 'success' === Mollie::handle_return( $o4['public_id'] ) && 1 === count( tickets( $o4['order_id'], 'valid' ) ) );
http_push( mollie_payment( 'tr_Four4444', 'paid', $o4 ), 200, '/v2/payments/tr_Four4444' );
check( 'later webhook is a duplicate', 'duplicate' === mollie_deliver( $mollie, 'tr_Four4444' ) );
$o5 = (array) Orders::get( Orders::create( array( 'email' => 'mol-5@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'mol-5' ), array( 'payment_provider' => 'mollie' ) )['order_id'] );
Orders::set_provider_reference( $o5['order_id'], 'tr_Five5555' );
http_push( mollie_payment( 'tr_Five5555', 'canceled', $o5 ), 200, '/v2/payments/tr_Five5555' );
check( 'cancelled payment returns the cancel notice', 'cancel' === Mollie::handle_return( $o5['public_id'] ) );
check( 'unknown order returns failed', 'failed' === Mollie::handle_return( str_repeat( '0', 32 ) ) );

echo "Mollie: settings\n";
check( 'API key format validated', is_wp_error( Mollie::save( array( 'mollie_api_key' => 'sk_live_wrong' ) ) ) );
Mollie::save( array( 'methods' => array( 'ideal', 'evil' ), 'billing_country' => 'de' ) );
check( 'unknown methods dropped, country normalized, key kept', array( 'ideal' ) === MollieGateway::config()['methods'] && 'DE' === MollieGateway::config()['billing_country'] && $api_key === Settings::secret( 'mollie_api_key' ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ) ) );
http_reset();
