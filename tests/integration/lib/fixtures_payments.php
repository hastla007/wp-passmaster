<?php
/**
 * Shared fixtures of tests/integration/payments.php: HTTP and REST fakes (wp_remote_*, WP_REST_Request, DNS), http_* queue helpers and the Stripe/PayPal request builders.
 * Loaded by run.php at the position of payments.php, also when WPCAP_TEST_ONLY skips that file, so modules
 * that use them run on their own (Z1). The test file itself only holds its checks.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\Webhooks;

/* ---- HTTP + REST fakes ---- */
$GLOBALS['wpcap_test_http_queue'] = array();
$GLOBALS['wpcap_test_http_requests'] = array();

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		// Like WP_Http::request(): a pre_http_request filter can short-circuit (A5 blocks all outbound HTTP).
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) { return $pre; }
		$method = strtoupper( $args['method'] ?? 'GET' );
		$GLOBALS['wpcap_test_http_requests'][] = array( 'method' => $method, 'url' => $url, 'headers' => $args['headers'] ?? array(), 'body' => $args['body'] ?? '', 'timeout' => $args['timeout'] ?? null, 'args' => $args );
		// Like WP_Http: pre_http_request short-circuits, http_api_curl sees the cURL handle and the arguments.
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) { return $pre; }
		if ( function_exists( 'curl_init' ) && has_action( 'http_api_curl' ) ) { $handle = curl_init(); do_action( 'http_api_curl', $handle, $args, $url ); unset( $handle ); } // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5.
		foreach ( $GLOBALS['wpcap_test_http_queue'] as $i => $entry ) {
			// A WP_Error (transport failure) answers any URL; array( 'error' => WP_Error, 'match' => … ) only matching ones.
			$match = is_array( $entry ) ? (string) ( $entry['match'] ?? '' ) : '';
			if ( '' === $match || false !== strpos( $url, $match ) ) {
				array_splice( $GLOBALS['wpcap_test_http_queue'], $i, 1 );
				if ( $entry instanceof WP_Error ) { return $entry; }
				if ( isset( $entry['error'] ) && $entry['error'] instanceof WP_Error ) { return $entry['error']; }
				return array( 'response' => array( 'code' => $entry['code'] ?? 200, 'message' => '' ), 'headers' => $entry['headers'] ?? array(), 'body' => is_string( $entry['body'] ?? null ) ? $entry['body'] : json_encode( $entry['body'] ?? array() ) );
			}
		}
		return new WP_Error( 'http_request_failed', 'No scripted response for ' . $method . ' ' . $url );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) { function wp_remote_post( $url, $args = array() ) { $args['method'] = $args['method'] ?? 'POST'; return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_safe_remote_post' ) ) { function wp_safe_remote_post( $url, $args = array() ) { $args['method'] = $args['method'] ?? 'POST'; return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $url, $args = array() ) { $args['method'] = $args['method'] ?? 'GET'; return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { foreach ( (array) ( $r['headers'] ?? array() ) as $k => $v ) { if ( strtolower( $k ) === strtolower( $h ) ) { return $v; } } return ''; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); } }
// DNS for OutboundHttp: every test host resolves to a public address unless a test sets $GLOBALS['wpcap_test_dns'][ $host ].
add_filter( 'wpcap_outbound_resolve', static function ( $ips, $host ) { return $ips ?? ( $GLOBALS['wpcap_test_dns'][ $host ] ?? array( '93.184.216.34' ) ); }, 10, 2 );
// HTTP is stubbed, so pinning never touches cURL: behave the same with or without the cURL extension (CI images differ).
$GLOBALS['wpcap_test_filters']['wpcap_outbound_can_pin'] = true;

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		private $params = array();
		private $headers = array();
		private $body = '';
		public function __construct( public $method = 'GET', public $route = '' ) {}
		public function get_route() { return (string) $this->route; }
		public function get_method() { return (string) $this->method; }
		private static function canonical( $key ) { return str_replace( '-', '_', strtolower( (string) $key ) ); }
		public function set_header( $key, $value ) { $this->headers[ self::canonical( $key ) ] = (string) $value; }
		public function get_header( $key ) { return $this->headers[ self::canonical( $key ) ] ?? null; }
		public function set_body( $body ) { $this->body = (string) $body; }
		public function get_body() { return $this->body; }
		public function get_json_params() { $d = json_decode( $this->body, true ); return is_array( $d ) ? $d : null; }
		public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
		public function get_param( $k ) { return $this->params[ $k ] ?? ( $this->get_json_params()[ $k ] ?? ( $this->query[ $k ] ?? null ) ); }
		// Query string (A645): get_param() falls back to it like WordPress does.
		private $query = array();
		public function set_query_params( array $q ) { $this->query = $q; }
		public function get_query_params() { return $this->query; }
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
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/stripe/webhook' );
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
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/paypal/webhook' );
	$req->set_body( json_encode( $event ) );
	if ( $with_headers ) {
		foreach ( array( 'PAYPAL-TRANSMISSION-ID' => 'tid-1', 'PAYPAL-TRANSMISSION-TIME' => gmdate( 'c' ), 'PAYPAL-TRANSMISSION-SIG' => 'sig==', 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA' ) as $h => $v ) { $req->set_header( $h, $v ); }
	}
	$req['provider'] = 'paypal';
	return $req;
}
