<?php
/**
 * Shared fixtures of tests/integration/admin.php: WordPress stubs of the admin handlers (redirect/die as exceptions, users) and the admin_* helpers.
 * Loaded by run.php at the position of admin.php, also when WPCAP_TEST_ONLY skips that file, so modules
 * that use them run on their own (Z1). The test file itself only holds its checks.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

/* ---- WordPress stubs needed by the admin handlers (only if no other test file defined them) ---- */
final class Wpcap_Test_Redirect extends Exception {}
final class Wpcap_Test_Die extends Exception {}
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $len = 12, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $len ) ), 0, $len ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1 ) { $GLOBALS['wpcap_test_nonce_checks'][] = $action; return 1; } }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $url ) { throw new Wpcap_Test_Redirect( $url ); } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message = '', $title = '', $args = array() ) { throw new Wpcap_Test_Die( (string) $message ); } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		foreach ( $GLOBALS['wpcap_test_users'] ?? array() as $user ) {
			if ( ( 'id' === $field && (int) $user->ID === (int) $value ) || ( 'login' === $field && $user->user_login === $value ) || ( 'email' === $field && $user->user_email === $value ) ) { return $user; }
		}
		return false;
	}
}
final class Wpcap_Test_User {
	public array $caps = array();
	public function __construct( public int $ID, public string $user_login, public string $user_email ) {}
	public function add_cap( $cap ) { $this->caps[] = $cap; }
}
$GLOBALS['wpcap_test_users'] = array( new Wpcap_Test_User( 7, 'scanner', 'scanner@example.com' ) );

function admin_actions_since( $offset ) { return array_slice( $GLOBALS['wpcap_test_actions'], $offset ); }
function admin_last_audit( $action ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s ORDER BY audit_id DESC LIMIT 1", $action ) );
}
/** Creates a paid order with $qty tickets of $type and returns its order id. */
function admin_paid_order( $type, $qty, $email ) {
	wpcap_test_reset_transients();
	$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'admin-' . uniqid( '', true ) ) );
	if ( is_wp_error( $r ) ) { echo '    (order failed: ' . $r->get_error_message() . ")\n"; return 0; }
	Orders::confirm_manual_payment( $r['order_id'] );
	return (int) $r['order_id'];
}
