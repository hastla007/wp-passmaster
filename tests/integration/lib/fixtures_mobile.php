<?php
/**
 * Shared fixtures of tests/integration/mobile.php: REST response stub, current-user stubs, rest_url()
 * and the helpers app_request(), app_call(), ticket_code_for().
 * Loaded by run.php at the position of mobile.php, also when WPCAP_TEST_ONLY skips that file, so modules
 * that use them run on their own (Z1). The test file itself only holds its checks.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Mobile\Api;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { public function __construct( public $data = null, public $status = 200 ) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } public $headers = array(); public function header( $k, $v ) { $this->headers[ $k ] = $v; } public function get_headers() { return $this->headers; } }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); } }
if ( ! function_exists( 'wp_set_current_user' ) ) { function wp_set_current_user( $id ) { $GLOBALS['wpcap_test_user_id'] = (int) $id; } }
if ( ! function_exists( 'get_userdata' ) ) {
	// Registered test users with capabilities (has_cap(), e.g. zz_a277.php) are returned as they are;
	// ids in wpcap_test_deleted_users do not exist.
	function get_userdata( $id ) {
		if ( in_array( (int) $id, $GLOBALS['wpcap_test_deleted_users'] ?? array(), true ) ) { return false; }
		foreach ( $GLOBALS['wpcap_test_users'] ?? array() as $user ) { if ( (int) $user->ID === (int) $id && method_exists( $user, 'has_cap' ) ) { return $user; } }
		return (object) array( 'ID' => $id, 'display_name' => 'Staff ' . $id );
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) { function wp_get_current_user() { return get_userdata( get_current_user_id() ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }

function app_request( $method, $token, array $params = array() ) {
	$r = new WP_REST_Request( $method );
	if ( $token ) { $r->set_header( 'Authorization', 'Bearer ' . $token ); }
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}
function app_call( $callback, $method, $token, array $params = array() ) {
	$req = app_request( $method, $token, $params );
	$auth = Api::authenticate( $req );
	if ( true !== $auth ) { return $auth; }
	$res = call_user_func( array( Api::class, $callback ), $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
function ticket_code_for( $order_id ) {
	// Ticket codes are only stored hashed; tests read the last ticket mail for the order.
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( str_starts_with( $mail['subject'], 'Deine Tickets' ) && preg_match_all( '/Ticket-Code: ([a-f0-9]{64})/', $mail['body'], $m ) && $m[1] ) {
			global $wpdb;
			$hashes = array_map( static fn( $c ) => hash( 'sha256', $c ), $m[1] );
			$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=" . (int) $order_id . " AND token_hash IN ('" . implode( "','", $hashes ) . "')" );
			if ( $found ) { return $m[1]; }
		}
	}
	return array();
}
