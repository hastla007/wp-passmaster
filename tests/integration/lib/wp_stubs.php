<?php
/**
 * WordPress stubs several modules need (test isolation): loaded by run.php before the first module, so a
 * module also runs on its own (WPCAP_TEST_ONLY='^<module>$', tests/integration/run-each.sh).
 *
 * Each stub is the definition the first module of the full run used to declare (same behaviour in the
 * full run), and is only defined if missing. Modules may keep their own guarded copy.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }

// Ajax responses: thrown, so a handler test can read the payload (first used by modules/mail_templates.php).
if ( ! class_exists( 'Wpmt_Json_Response' ) ) { final class Wpmt_Json_Response extends Exception { public $payload; public $success; public function __construct( $success, $payload ) { parent::__construct( 'json' ); $this->success = $success; $this->payload = $payload; } } }
if ( ! function_exists( 'check_ajax_referer' ) ) { function check_ajax_referer( $action = -1, $arg = false, $die = true ) { $GLOBALS['wpcap_test_nonce_checks'][] = $action; return 1; } }
if ( ! function_exists( 'wp_send_json_success' ) ) { function wp_send_json_success( $data = null, $status = null ) { throw new Wpmt_Json_Response( true, $data ); } }
if ( ! function_exists( 'wp_send_json_error' ) ) { function wp_send_json_error( $data = null, $status = null ) { throw new Wpmt_Json_Response( false, array( 'data' => $data, 'status' => $status ) ); } }

if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { $GLOBALS['wpcap_test_shortcodes'][ $tag ] = $cb; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }

// Nonces (first used by modules/p3_boxoffice_shifts.php).
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }

// User meta in memory (first used by modules/p3_boxoffice_shifts.php).
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
