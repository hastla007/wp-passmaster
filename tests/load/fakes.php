<?php
/**
 * Fakes for the load test workers (same as the HTTP/REST fakes in tests/integration/payments.php):
 * a minimal WP_REST_Request and an HTTP layer that refuses every outbound call.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) { return new WP_Error( 'http_request_failed', 'Outbound HTTP is disabled in load tests: ' . $url ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) { function wp_remote_post( $url, $args = array() ) { return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $url, $args = array() ) { return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { return ''; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }

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
