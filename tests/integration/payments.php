<?php
/**
 * Integration tests for the Stripe and PayPal adapters. Included by run.php (uses check(), make_type(), tickets()).
 *
 * HTTP is faked: tests push scripted responses onto $GLOBALS['wpcap_test_http_queue'] (optionally with a
 * 'match' substring of the URL) and inspect $GLOBALS['wpcap_test_http_requests'].
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\StripeGateway;
use PassCaptain\Payments\Webhooks;

/* ---- HTTP + REST fakes ---- */
$GLOBALS['wpcap_test_http_queue'] = array();
$GLOBALS['wpcap_test_http_requests'] = array();

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$method = strtoupper( $args['method'] ?? 'GET' );
		$GLOBALS['wpcap_test_http_requests'][] = array( 'method' => $method, 'url' => $url, 'headers' => $args['headers'] ?? array(), 'body' => $args['body'] ?? '', 'timeout' => $args['timeout'] ?? null );
		foreach ( $GLOBALS['wpcap_test_http_queue'] as $i => $entry ) {
			if ( empty( $entry['match'] ) || false !== strpos( $url, $entry['match'] ) ) {
				array_splice( $GLOBALS['wpcap_test_http_queue'], $i, 1 );
				if ( $entry instanceof WP_Error ) { return $entry; }
				return array( 'response' => array( 'code' => $entry['code'] ?? 200, 'message' => '' ), 'headers' => $entry['headers'] ?? array(), 'body' => is_string( $entry['body'] ?? null ) ? $entry['body'] : json_encode( $entry['body'] ?? array() ) );
			}
		}
		return new WP_Error( 'http_request_failed', 'No scripted response for ' . $method . ' ' . $url );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) { function wp_remote_post( $url, $args = array() ) { $args['method'] = $args['method'] ?? 'POST'; return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $url, $args = array() ) { $args['method'] = $args['method'] ?? 'GET'; return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { foreach ( (array) ( $r['headers'] ?? array() ) as $k => $v ) { if ( strtolower( $k ) === strtolower( $h ) ) { return $v; } } return ''; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		private $params = array();
		private $headers = array();
		private $body = '';
		public function __construct( public $method = 'GET', public $route = '' ) {}
		private static function canonical( $key ) { return str_replace( '-', '_', strtolower( (string) $key ) ); }
		public function set_header( $key, $value ) { $this->headers[ self::canonical( $key ) ] = (string) $value; }
		public function get_header( $key ) { return $this->headers[ self::canonical( $key ) ] ?? null; }
		public function set_body( $body ) { $this->body = (string) $body; }
		public function get_body() { return $this->body; }
		public function get_json_params() { $d = json_decode( $this->body, true ); return is_array( $d ) ? $d : null; }
		public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
		public function get_param( $k ) { return $this->params[ $k ] ?? ( $this->get_json_params()[ $k ] ?? null ); }
		public function get_params() { return array_merge( (array) $this->get_json_params(), $this->params ); }
		public function offsetExists( mixed $k ): bool { return null !== $this->get_param( $k ); }
		public function offsetGet( mixed $k ): mixed { return $this->get_param( $k ); }
		public function offsetSet( mixed $k, mixed $v ): void { $this->params[ $k ] = $v; }
		public function offsetUnset( mixed $k ): void { unset( $this->params[ $k ] ); }
	}
}

function http_reset() { $GLOBALS['wpcap_test_http_queue'] = array(); $GLOBALS['wpcap_test_http_requests'] = array(); }
function http_push( $body, $code = 200, $match = '' ) { $GLOBALS['wpcap_test_http_queue'][] = array( 'body' => $body, 'code' => $code, 'match' => $match ); }
function http_requests( $match = '' ) { return array_values( array_filter( $GLOBALS['wpcap_test_http_requests'], static fn( $r ) => '' === $match || false !== strpos( $r['url'], $match ) ) ); }
function form_body( $request ) { parse_str( (string) $request['body'], $out ); return $out; }

function provider_order( $provider, $type, $qty, $key, $discount = 0 ) {
	global $wpdb;
	$r = Orders::create( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => $key ), array( 'payment_provider' => $provider, 'hold_minutes' => 30 ) );
	if ( $discount ) {
		// Simulates a campaign discount: the provider must charge the order total, not the item sum.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET discount_minor=%d,total_minor=total_minor-%d WHERE order_id=%d", $discount, $discount, $r['order_id'] ) );
	}
	return (array) Orders::get( $r['order_id'] );
}
function order_items_array( $order_id ) { return array_map( static fn( $row ) => (array) $row, Orders::items_for_order( $order_id ) ); }

function stripe_request( array $event, $secret = 'whsec_test', $timestamp = null, $tamper = false ) {
	$body = json_encode( $event );
	$t = $timestamp ?? time();
	$sig = hash_hmac( 'sha256', $t . '.' . $body, $secret );
	$req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/payments/stripe/webhook' );
	$req->set_body( $tamper ? str_replace( '"amount_total":', '"amount_total":1', $body ) : $body );
	$req->set_header( 'Stripe-Signature', "t=$t,v1=" . str_repeat( '0', 64 ) . ",v1=$sig" );
	$req['provider'] = 'stripe';
	return $req;
}
function stripe_session_event( $id, $type, $order, array $override = array() ) {
	return array( 'id' => $id, 'type' => $type, 'data' => array( 'object' => array_merge( array(
		'id' => 'cs_test_' . $order['public_id'], 'object' => 'checkout.session', 'amount_total' => (int) $order['total_minor'], 'currency' => 'eur',
		'client_reference_id' => $order['public_id'], 'metadata' => array( 'wpcap_order' => $order['public_id'] ),
		'payment_intent' => 'pi_' . substr( $order['public_id'], 0, 12 ), 'payment_status' => 'paid', 'status' => 'complete',
	), $override ) ) );
}
function stripe_deliver( $gateway, array $event, ...$args ) {
	$verified = $gateway->verify_webhook( stripe_request( $event, ...$args ) );
	return is_wp_error( $verified ) ? $verified : Webhooks::process( $gateway->id(), $verified );
}

function paypal_request( array $event, $with_headers = true ) {
	$req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/payments/paypal/webhook' );
	$req->set_body( json_encode( $event ) );
	if ( $with_headers ) {
		foreach ( array( 'PAYPAL-TRANSMISSION-ID' => 'tid-1', 'PAYPAL-TRANSMISSION-TIME' => gmdate( 'c' ), 'PAYPAL-TRANSMISSION-SIG' => 'sig==', 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA' ) as $h => $v ) { $req->set_header( $h, $v ); }
	}
	$req['provider'] = 'paypal';
	return $req;
}

/* ---- Setup ---- */
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\Settings::update( array(
	'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test',
	'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1',
	'refund_approval_threshold' => 0,
) );
Registry::reset();
$stripe = Registry::get( 'stripe' );
$paypal = Registry::get( 'paypal' );
check( 'Stripe and PayPal adapters are auto-registered and available', $stripe instanceof StripeGateway && $paypal instanceof PayPalGateway && $stripe->is_available( 'EUR' ) && $paypal->is_available( 'EUR' ) && ! $paypal->is_available( 'XXX' ) );

echo "Stripe: checkout session\n";
http_reset();
$type = make_type( 20, 10, 2500 );
$so = provider_order( 'stripe', $type, 2, 'stripe-1', 500 );
http_push( array( 'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$pay = $stripe->create_payment( $so, order_items_array( $so['order_id'] ) );
$req = http_requests( 'checkout/sessions' )[0] ?? array( 'body' => '', 'headers' => array(), 'method' => '', 'url' => '' );
$f = form_body( $req );
check( 'create_payment returns redirect and session id', ! is_wp_error( $pay ) && 'stripe' === $pay['provider'] && 'pending' === $pay['status'] && 'https://checkout.stripe.com/c/pay/cs_test_1' === $pay['redirect_url'] && 'cs_test_1' === $pay['provider_reference'] );
check( 'session request: POST, bearer key, idempotency key, 15s timeout', 'POST' === $req['method'] && 'https://api.stripe.com/v1/checkout/sessions' === $req['url'] && 'Bearer sk_test_123' === $req['headers']['Authorization'] && 'wpcap-session-' . $so['public_id'] === $req['headers']['Idempotency-Key'] && 15 === $req['timeout'] );
check( 'session charges exactly the discounted order total', 4500 === (int) $so['total_minor'] && '4500' === ( $f['line_items'][0]['price_data']['unit_amount'] ?? '' ) && '1' === $f['line_items'][0]['quantity'] && 1 === count( $f['line_items'] ) );
check( 'session params: mode, currency, references, metadata, email, expiry', 'payment' === $f['mode'] && 'eur' === $f['currency'] && $so['public_id'] === $f['client_reference_id'] && $so['public_id'] === $f['metadata']['wpcap_order'] && $so['public_id'] === $f['payment_intent_data']['metadata']['wpcap_order'] && 'stripe-1@example.com' === $f['customer_email'] && (int) $f['expires_at'] >= time() + 1800 && (int) $f['expires_at'] <= time() + 86400 && false !== strpos( $f['success_url'], 'wpcap_payment=success' ) && false !== strpos( $f['cancel_url'], 'wpcap_payment=cancel' ) );
Orders::set_provider_reference( $so['order_id'], $pay['provider_reference'] );
http_reset();
http_push( array( 'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'status' => 'open' ), 200, '/v1/checkout/sessions/cs_test_1' );
$again = $stripe->create_payment( (array) Orders::get( $so['order_id'] ), array() );
check( 'retried checkout reuses the open session', ! is_wp_error( $again ) && 'cs_test_1' === $again['provider_reference'] && 1 === count( $GLOBALS['wpcap_test_http_requests'] ) && 'GET' === $GLOBALS['wpcap_test_http_requests'][0]['method'] );
http_reset();
http_push( array( 'error' => array( 'message' => 'Invalid API Key provided' ) ), 401 );
$err = $stripe->create_payment( provider_order( 'stripe', $type, 1, 'stripe-err' ), array() );
check( 'Stripe API error is returned as WP_Error with Stripe message', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Invalid API Key provided' ) );

echo "Stripe: webhooks\n";
http_reset();
$bad = $stripe->verify_webhook( stripe_request( stripe_session_event( 'evt_bad', 'checkout.session.completed', $so ), 'whsec_wrong' ) );
check( 'wrong signing secret is rejected with 401', is_wp_error( $bad ) && 401 === $bad->get_error_data()['status'] );
$old = $stripe->verify_webhook( stripe_request( stripe_session_event( 'evt_old', 'checkout.session.completed', $so ), 'whsec_test', time() - 301 ) );
check( 'signature older than 300 s is rejected', is_wp_error( $old ) && 401 === $old->get_error_data()['status'] );
$tampered = $stripe->verify_webhook( stripe_request( stripe_session_event( 'evt_tamper', 'checkout.session.completed', $so ), 'whsec_test', null, true ) );
check( 'tampered body is rejected', is_wp_error( $tampered ) );
$nohdr = new WP_REST_Request( 'POST' ); $nohdr->set_body( '{}' );
check( 'missing signature header is rejected', is_wp_error( $stripe->verify_webhook( $nohdr ) ) );
check( 'no tickets after rejected webhooks', ! tickets( $so['order_id'] ) );

$unpaid = stripe_deliver( $stripe, stripe_session_event( 'evt_unpaid', 'checkout.session.completed', $so, array( 'payment_status' => 'unpaid' ) ) );
check( 'completed but unpaid (async method) issues nothing', 'ignored' === $unpaid && ! tickets( $so['order_id'] ) );
$paid = stripe_deliver( $stripe, stripe_session_event( 'evt_paid', 'checkout.session.completed', $so ) );
$o = Orders::get( $so['order_id'] );
check( 'valid paid event confirms order and issues tickets', 'paid' === $paid && 'paid' === $o->payment_status && 2 === count( tickets( $so['order_id'], 'valid' ) ) );
check( 'payment intent stored as provider reference', 'pi_' . substr( $so['public_id'], 0, 12 ) === $o->provider_reference );
$dup = stripe_deliver( $stripe, stripe_session_event( 'evt_paid', 'checkout.session.completed', $so ) );
check( 'duplicate event is processed once', 'duplicate' === $dup && 2 === count( tickets( $so['order_id'] ) ) );

$mo = provider_order( 'stripe', $type, 1, 'stripe-mismatch' );
$mm = stripe_deliver( $stripe, stripe_session_event( 'evt_mm', 'checkout.session.completed', $mo, array( 'amount_total' => 1 ) ) );
check( 'amount mismatch issues no tickets', 'amount_mismatch' === $mm && ! tickets( $mo['order_id'] ) && 'pending' === Orders::get( $mo['order_id'] )->payment_status );
$cur = stripe_deliver( $stripe, stripe_session_event( 'evt_cur', 'checkout.session.completed', $mo, array( 'currency' => 'usd' ) ) );
check( 'currency mismatch issues no tickets', 'amount_mismatch' === $cur && ! tickets( $mo['order_id'] ) );
$async = stripe_deliver( $stripe, stripe_session_event( 'evt_async', 'checkout.session.async_payment_succeeded', $mo ) );
check( 'async_payment_succeeded confirms the order', 'paid' === $async && 1 === count( tickets( $mo['order_id'] ) ) );

$eo = provider_order( 'stripe', $type, 1, 'stripe-expired' );
$exp = stripe_deliver( $stripe, stripe_session_event( 'evt_exp', 'checkout.session.expired', $eo, array( 'payment_status' => 'unpaid', 'status' => 'expired', 'payment_intent' => null ) ) );
check( 'expired session cancels the pending order', 'cancelled' === $exp && 'cancelled' === Orders::get( $eo['order_id'] )->status );
$fo = provider_order( 'stripe', $type, 1, 'stripe-failed' );
check( 'async_payment_failed cancels the pending order', 'cancelled' === stripe_deliver( $stripe, stripe_session_event( 'evt_fail', 'checkout.session.async_payment_failed', $fo ) ) );
$other = stripe_deliver( $stripe, array( 'id' => 'evt_other', 'type' => 'customer.created', 'data' => array( 'object' => array( 'id' => 'cus_1' ) ) ) );
check( 'unrelated events are ignored', 'ignored' === $other );
$foreign = provider_order( 'paypal', $type, 1, 'stripe-foreign' );
check( 'event for an order of another provider is ignored', 'ignored' === stripe_deliver( $stripe, stripe_session_event( 'evt_foreign', 'checkout.session.completed', $foreign ) ) && ! tickets( $foreign['order_id'] ) );

echo "Stripe: refunds\n";
http_reset();
$all = tickets( $so['order_id'] );
http_push( array( 'id' => 're_1', 'status' => 'succeeded', 'amount' => 2250 ), 200, '/v1/refunds' );
$sr = PassCaptain\Refunds::request( $so['order_id'], array( (int) $all[0]->ticket_id ), 'Kunde krank', 'stripe-r1' );
$rq = http_requests( '/v1/refunds' )[0] ?? array( 'body' => '', 'headers' => array() );
$rf = form_body( $rq );
check( 'native refund completes with Stripe refund id', ! is_wp_error( $sr ) && 'completed' === $sr['status'] && 're_1' === $sr['provider_reference'] );
check( 'refund uses payment intent, amount and idempotency key', 'pi_' . substr( $so['public_id'], 0, 12 ) === ( $rf['payment_intent'] ?? '' ) && (string) $sr['amount_minor'] === $rf['amount'] && 'wpcap-refund-' . $sr['refund_id'] === $rq['headers']['Idempotency-Key'] && ! empty( $rf['metadata']['wpcap_refund'] ) );
$charge = array( 'id' => 'evt_ref1', 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_1', 'object' => 'charge', 'amount' => 4500, 'amount_refunded' => 2250, 'refunded' => false, 'currency' => 'eur', 'payment_intent' => 'pi_x', 'metadata' => array( 'wpcap_order' => $so['public_id'] ), 'refunds' => array( 'data' => array( array( 'id' => 're_1', 'amount' => 2250, 'metadata' => array( 'wpcap_refund' => 'wpcap-refund-1' ) ) ) ) ) ) );
$own = stripe_deliver( $stripe, $charge );
check( 'charge.refunded for the plugin\'s own partial refund does not revoke more tickets', 'ignored' === $own && 1 === count( tickets( $so['order_id'], 'valid' ) ) );
http_reset();
http_push( array( 'data' => array( array( 'id' => 're_2', 'amount' => 2250, 'metadata' => array() ) ) ), 200, '/v1/refunds?charge=ch_1' );
$charge['id'] = 'evt_ref2';
$charge['data']['object']['amount_refunded'] = 4500;
$charge['data']['object']['refunded'] = true;
unset( $charge['data']['object']['refunds'] );
$full = stripe_deliver( $stripe, $charge );
check( 'full refund in Stripe dashboard marks the order refunded', 'refunded' === $full && 'refunded' === Orders::get( $so['order_id'] )->status && ! tickets( $so['order_id'], 'valid' ) );

http_reset();
$co = provider_order( 'stripe', $type, 1, 'stripe-cs-ref' );
stripe_deliver( $stripe, stripe_session_event( 'evt_cs_paid', 'checkout.session.completed', $co ) );
Orders::set_provider_reference( $co['order_id'], 'cs_test_legacy' );
http_push( array( 'id' => 'cs_test_legacy', 'payment_intent' => 'pi_legacy' ), 200, '/v1/checkout/sessions/cs_test_legacy' );
http_push( array( 'id' => 're_3', 'status' => 'pending' ), 200, '/v1/refunds' );
$cr = PassCaptain\Refunds::request( $co['order_id'], array(), 'Absage', 'stripe-r2' );
$crf = form_body( http_requests( '/v1/refunds' )[0] ?? array( 'body' => '' ) );
check( 'refund resolves a checkout session reference to its payment intent', ! is_wp_error( $cr ) && 'completed' === $cr['status'] && 'pi_legacy' === ( $crf['payment_intent'] ?? '' ) && 'refunded' === Orders::get( $co['order_id'] )->status );
http_reset();
$eo2 = provider_order( 'stripe', $type, 1, 'stripe-refund-fail' );
stripe_deliver( $stripe, stripe_session_event( 'evt_rf_paid', 'checkout.session.completed', $eo2 ) );
http_push( array( 'error' => array( 'message' => 'Charge has already been refunded.' ) ), 400, '/v1/refunds' );
$rfail = PassCaptain\Refunds::request( $eo2['order_id'], array(), 'x', 'stripe-r3' );
check( 'Stripe refund error keeps tickets and reports the message', is_wp_error( $rfail ) && false !== strpos( $rfail->get_error_message(), 'already been refunded' ) && 1 === count( tickets( $eo2['order_id'], 'valid' ) ) );

echo "PayPal: order, return capture, webhooks, refunds\n";
http_reset();
check( 'PayPal amount formatting follows currency decimals', '45.00' === PayPalGateway::format_amount( 4500, 'EUR' ) && '0.05' === PayPalGateway::format_amount( 5, 'EUR' ) && '1500' === PayPalGateway::format_amount( 1500, 'JPY' ) && '1500' === PayPalGateway::format_amount( 150000, 'HUF' ) && is_wp_error( PayPalGateway::format_amount( 150050, 'HUF' ) ) && 4500 === PayPalGateway::parse_amount( '45.00', 'EUR' ) && 4500 === PayPalGateway::parse_amount( '45', 'EUR' ) && 1500 === PayPalGateway::parse_amount( '1500', 'JPY' ) );
$po = provider_order( 'paypal', $type, 2, 'paypal-1', 500 );
http_push( array( 'access_token' => 'A21-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'id' => 'PAYORDER1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => array( array( 'rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYORDER1' ), array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYORDER1' ) ) ), 201, '/v2/checkout/orders' );
$pp = $paypal->create_payment( $po, order_items_array( $po['order_id'] ) );
$tok = http_requests( '/v1/oauth2/token' )[0] ?? array( 'headers' => array(), 'body' => '' );
$cr = http_requests( '/v2/checkout/orders' )[0] ?? array( 'headers' => array(), 'body' => '' );
$cb = json_decode( $cr['body'], true );
check( 'OAuth token via client credentials against the sandbox', 'https://api-m.sandbox.paypal.com/v1/oauth2/token' === $tok['url'] && 'Basic ' . base64_encode( 'client:secret' ) === $tok['headers']['Authorization'] && 'grant_type=client_credentials' === $tok['body'] );
check( 'create_payment returns approve link and PayPal order id', ! is_wp_error( $pp ) && 'https://www.sandbox.paypal.com/checkoutnow?token=PAYORDER1' === $pp['redirect_url'] && 'PAYORDER1' === $pp['provider_reference'] );
check( 'PayPal order: CAPTURE, total incl. discount, references, request id', 'CAPTURE' === $cb['intent'] && '45.00' === $cb['purchase_units'][0]['amount']['value'] && 'EUR' === $cb['purchase_units'][0]['amount']['currency_code'] && $po['public_id'] === $cb['purchase_units'][0]['custom_id'] && $po['public_id'] === $cb['purchase_units'][0]['reference_id'] && 'wpcap-' . $po['public_id'] === $cr['headers']['PayPal-Request-Id'] && 'Bearer A21-token' === $cr['headers']['Authorization'] && false !== strpos( $cb['payment_source']['paypal']['experience_context']['return_url'], 'wpcap_paypal_return=1' ) );
Orders::set_provider_reference( $po['order_id'], $pp['provider_reference'] );
http_reset();
http_push( array( 'id' => 'PAYORDER2', 'links' => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYORDER2' ) ) ), 201, '/v2/checkout/orders' );
$po2 = provider_order( 'paypal', $type, 1, 'paypal-2' );
$pp2 = $paypal->create_payment( $po2, array() );
check( 'cached token is reused; legacy approve link accepted', ! is_wp_error( $pp2 ) && ! http_requests( '/v1/oauth2/token' ) && 'PAYORDER2' === $pp2['provider_reference'] );

http_reset();
check( 'return with a foreign PayPal order id is rejected without capture', is_wp_error( PayPalGateway::handle_return( $po['public_id'], 'OTHERORDER' ) ) && ! $GLOBALS['wpcap_test_http_requests'] );
$capture = array( 'id' => 'PAYORDER1', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'reference_id' => $po['public_id'], 'payments' => array( 'captures' => array( array( 'id' => 'CAP1', 'status' => 'COMPLETED', 'custom_id' => $po['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '45.00' ) ) ) ) ) ) );
http_push( $capture, 201, '/v2/checkout/orders/PAYORDER1/capture' );
$ret = PayPalGateway::handle_return( $po['public_id'], 'PAYORDER1' );
$capreq = http_requests( '/capture' )[0] ?? array( 'headers' => array() );
check( 'return handler captures and issues tickets', 'paid' === $ret && 2 === count( tickets( $po['order_id'], 'valid' ) ) && 'CAP1' === Orders::get( $po['order_id'] )->provider_reference );
check( 'capture is idempotent via PayPal-Request-Id', 'wpcap-capture-PAYORDER1' === $capreq['headers']['PayPal-Request-Id'] );
check( 'second return does not capture again', 'paid' === PayPalGateway::handle_return( $po['public_id'], 'PAYORDER1' ) && 1 === count( http_requests( '/capture' ) ) );

http_reset();
$mp = provider_order( 'paypal', $type, 1, 'paypal-mismatch' );
Orders::set_provider_reference( $mp['order_id'], 'PAYORDER3' );
http_push( array( 'id' => 'PAYORDER3', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP3', 'status' => 'COMPLETED', 'custom_id' => $mp['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '0.01' ) ) ) ) ) ) ), 201, '/capture' );
check( 'captured amount mismatch issues no tickets', is_wp_error( PayPalGateway::handle_return( $mp['public_id'], 'PAYORDER3' ) ) && ! tickets( $mp['order_id'] ) );

$ev = array( 'id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => array( 'id' => 'CAP1', 'status' => 'COMPLETED', 'custom_id' => $po['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '45.00' ) ) );
http_reset();
check( 'webhook without transmission headers is rejected without API call', 401 === ( $paypal->verify_webhook( paypal_request( $ev, false ) )->get_error_data()['status'] ?? 0 ) && ! $GLOBALS['wpcap_test_http_requests'] );
http_push( array( 'verification_status' => 'FAILURE' ), 200, '/v1/notifications/verify-webhook-signature' );
$vf = $paypal->verify_webhook( paypal_request( $ev ) );
check( 'webhook with failed verification is rejected (401)', is_wp_error( $vf ) && 401 === $vf->get_error_data()['status'] );
http_reset();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, '/v1/notifications/verify-webhook-signature' );
$vs = $paypal->verify_webhook( paypal_request( $ev ) );
$vreq = json_decode( http_requests( 'verify-webhook-signature' )[0]['body'] ?? '', true );
check( 'verification request carries headers, webhook id and the original event', is_array( $vreq ) && 'WH-ID-1' === $vreq['webhook_id'] && 'tid-1' === $vreq['transmission_id'] && 'SHA256withRSA' === $vreq['auth_algo'] && $ev === $vreq['webhook_event'] );
check( 'capture webhook after return is a duplicate (same event id)', ! is_wp_error( $vs ) && 'capture-CAP1' === $vs['event_id'] && 'duplicate' === Webhooks::process( 'paypal', $vs ) && 2 === count( tickets( $po['order_id'] ) ) );

http_reset();
$wo = provider_order( 'paypal', $type, 1, 'paypal-webhook-only' );
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$wev = $paypal->verify_webhook( paypal_request( array( 'id' => 'WH-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => array( 'id' => 'CAP2', 'status' => 'COMPLETED', 'custom_id' => $wo['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ) ) ) );
check( 'capture webhook alone confirms the order', 'paid' === Webhooks::process( 'paypal', $wev ) && 1 === count( tickets( $wo['order_id'] ) ) );
http_reset();
$do = provider_order( 'paypal', $type, 1, 'paypal-denied' );
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$dev = $paypal->verify_webhook( paypal_request( array( 'id' => 'WH-3', 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => array( 'id' => 'CAP4', 'status' => 'DECLINED', 'custom_id' => $do['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ) ) ) );
check( 'denied capture cancels the order', 'failed' === $dev['type'] && 'cancelled' === Webhooks::process( 'paypal', $dev ) );

http_reset();
$all = tickets( $po['order_id'] );
http_push( array( 'name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.' ), 404, '/v2/checkout/orders/CAP1' );
http_push( array( 'id' => 'REF1', 'status' => 'COMPLETED' ), 201, '/v2/payments/captures/CAP1/refund' );
$pr = PassCaptain\Refunds::request( $po['order_id'], array( (int) $all[0]->ticket_id ), 'Kunde krank', 'paypal-r1' );
$rr = http_requests( '/refund' )[0] ?? array( 'headers' => array(), 'body' => '' );
$rb = json_decode( $rr['body'], true );
check( 'PayPal refund uses capture id, amount and request id', ! is_wp_error( $pr ) && 'completed' === $pr['status'] && 'REF1' === $pr['provider_reference'] && '22.50' === $rb['amount']['value'] && 'EUR' === $rb['amount']['currency_code'] && 'wpcap-refund-' . $pr['refund_id'] === $rr['headers']['PayPal-Request-Id'] );
http_reset();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$rev = $paypal->verify_webhook( paypal_request( array( 'id' => 'WH-4', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => array( 'id' => 'REF1', 'status' => 'COMPLETED', 'custom_id' => $rb['custom_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '22.50' ) ) ) ) );
check( 'refund webhook for the plugin\'s own refund is ignored', 'ignored' === $rev['type'] && 1 === count( tickets( $po['order_id'], 'valid' ) ) );

http_reset();
$ro = provider_order( 'paypal', $type, 1, 'paypal-order-ref' );
Orders::set_provider_reference( $ro['order_id'], 'PAYORDER5' );
http_push( array( 'id' => 'PAYORDER5', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP5', 'status' => 'COMPLETED', 'custom_id' => $ro['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ) ) ) ) ) ), 201, '/v2/checkout/orders/PAYORDER5/capture' );
PayPalGateway::handle_return( $ro['public_id'], 'PAYORDER5' );
Orders::set_provider_reference( $ro['order_id'], 'PAYORDER5' ); // e.g. confirmed before the capture id was stored
http_push( array( 'id' => 'PAYORDER5', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP5' ) ) ) ) ) ), 200, '/v2/checkout/orders/PAYORDER5' );
http_push( array( 'id' => 'REF5', 'status' => 'COMPLETED' ), 201, '/v2/payments/captures/CAP5/refund' );
$r5 = PassCaptain\Refunds::request( $ro['order_id'], array(), 'Absage', 'paypal-r2' );
check( 'refund resolves a PayPal order id to its capture id', ! is_wp_error( $r5 ) && 'REF5' === $r5['provider_reference'] && 'refunded' === Orders::get( $ro['order_id'] )->status );

http_reset();
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
http_push( array( 'id' => 'CAP2', 'status' => 'REFUNDED', 'custom_id' => $wo['public_id'], 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ), 200, '/v2/payments/captures/CAP2' );
$dash = $paypal->verify_webhook( paypal_request( array( 'id' => 'WH-5', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => array( 'id' => 'REF9', 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ), 'links' => array( array( 'rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAP2' ) ) ) ) ) );
check( 'dashboard refund webhook finds the order via the capture and marks it refunded', ! is_wp_error( $dash ) && $wo['public_id'] === $dash['order_public_id'] && 'refunded' === Webhooks::process( 'paypal', $dash ) && 'refunded' === Orders::get( $wo['order_id'] )->status );

http_reset();
PassCaptain\Settings::update( array( 'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'paypal_client_id' => '', 'paypal_client_secret' => '', 'paypal_webhook_id' => '' ) );
check( 'adapters are unavailable without credentials', ! $stripe->is_available( 'EUR' ) && ! $paypal->is_available( 'EUR' ) );
Registry::reset();
