<?php
/**
 * A459: the cancellation request in the order portal failed for every logged-in buyer: the form
 * carried a WordPress nonce of the logged-in user, but the portal script posts to the REST API
 * without X-WP-Nonce, so REST runs the request as logged out and the nonce never verified (it also
 * expired on a page left open). The form now carries a user-independent keyed hash of order and
 * access token. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }

echo "A459: portal cancellation request works for logged-in buyers (no user-bound nonce)\n";
global $wpdb;
$a459_saved_settings = get_option( Settings::OPTION, array() );
$a459_saved_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
Settings::update( array( 'refund_approval_threshold' => 0, 'portal_refund_request_hours' => 24 ) );
$a459_type = make_type( 10, 10, 2500 );
$GLOBALS['wpcap_test_transients'] = array();
$a459_r = Orders::create( array( 'email' => 'a459@example.com', 'items' => array( array( 'ticket_type_id' => $a459_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a459-' . uniqid( '', true ) ) );
$a459_o = (int) $a459_r['order_id'];
Orders::confirm_external_payment( $a459_o, 'test' );
$a459_t = array_map( static fn( $t ) => (string) $t->public_id, tickets( $a459_o, 'valid' ) );
$a459_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$wpdb->prefix}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a459_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a459_o ) );
$a459_order = Orders::get( $a459_o );
$a459_pub = (string) $a459_order->public_id;

// The buyer is logged in while the portal page is rendered.
$GLOBALS['wpcap_test_user_id'] = 45901;
$a459_html = PortalSelfService::portal_html( $a459_order, $a459_token, PortalSelfService::tickets( $a459_order ) );
preg_match( '/class="wpcap-refund-form">.*?name="nonce" value="([^"]*)"/s', $a459_html, $a459_m );
$a459_proof = (string) ( $a459_m[1] ?? '' );
check( 'A459 setup: paid order with two tickets', 2 === count( $a459_t ) );
check( 'A459: the refund form carries the user-independent order proof, not a WordPress nonce', '' !== $a459_proof && hash_equals( PortalSelfService::refund_form_sig( $a459_pub, $a459_token ), $a459_proof ) );
check( 'A459: the proof does not depend on the user and is bound to order and access token', PortalSelfService::refund_form_sig( $a459_pub, $a459_token ) === $a459_proof && PortalSelfService::refund_form_sig( $a459_pub, str_repeat( 'a', 64 ) ) !== $a459_proof && PortalSelfService::refund_form_sig( str_repeat( 'b', 32 ), $a459_token ) !== $a459_proof );

// The REST request arrives without X-WP-Nonce: WordPress treats it as logged out.
$GLOBALS['wpcap_test_user_id'] = 0;
$a459_rest = static function ( $proof, $token ) use ( $a459_pub, $a459_t ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/orders/' . $a459_pub . '/refund-request' );
	$req->set_param( 'public_id', $a459_pub );
	$req->set_body( wp_json_encode( array( 'access_token' => $token, 'nonce' => $proof, 'idempotency_key' => 'k' . bin2hex( random_bytes( 12 ) ), 'ticket_public_ids' => array( $a459_t[0] ) ) ) );
	return PortalSelfService::rest_refund_request( $req );
};
$a459_bad = $a459_rest( PortalSelfService::refund_form_sig( $a459_pub, str_repeat( 'c', 64 ) ), $a459_token );
check( 'A459: a proof of another access token is refused (403 wpcap_nonce)', is_wp_error( $a459_bad ) && 'wpcap_nonce' === $a459_bad->get_error_code() && ! Refunds::for_order( $a459_o ) );
$a459_ok = $a459_rest( $a459_proof, $a459_token );
$a459_rows = Refunds::for_order( $a459_o );
check( 'A459: the request of the logged-in buyer is accepted by the logged-out REST call (pending approval)', ! is_wp_error( $a459_ok ) && 1 === count( $a459_rows ) && 'pending_approval' === $a459_rows[0]['status'] );

$GLOBALS['wpcap_test_user_id'] = $a459_saved_user;
update_option( Settings::OPTION, $a459_saved_settings );
