<?php
/**
 * A359: PublicEvents::register_assets() runs on every front-end request and must not resolve the
 * payment gateways (fixed EUR, decrypts provider secrets); the checkout gets them from /checkout/options.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Payments\Registry;
use PassCaptain\PublicEvents;

echo "A359: no gateway lookup when registering the checkout assets\n";

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( ...$a ) { return true; } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) { return true; } }
if ( ! function_exists( 'wp_register_style' ) ) { function wp_register_style( ...$a ) { return true; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'nonce'; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }

final class X2_A359_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static int $checks = 0;
	public function id() { return 'x2_a359'; }
	public function title() { return 'X2'; }
	public function is_available( $currency ) { self::$checks++; return true; }
	public function create_payment( array $order, array $items = array() ) { return new WP_Error( 'x', 'x' ); }
	public function verify_webhook( \WP_REST_Request $request ) { return new WP_Error( 'x', 'x' ); }
	public function supports_refunds() { return false; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { return new WP_Error( 'x', 'x' ); }
}
$x2_a359_cb = static function ( $list ) { $list[] = new X2_A359_Gateway(); return $list; };
add_filter( 'wpcap_payment_gateways', $x2_a359_cb );
$x2_a359_settings = $GLOBALS['wpcap_test_options']['wpcap_settings'] ?? array();
PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual', 'x2_a359' ) ) );
Registry::reset();
X2_A359_Gateway::$checks = 0;
PublicEvents::register_assets();
check( 'A359: registering the checkout script does not query payment gateways', 0 === X2_A359_Gateway::$checks );
check( 'A359: the gateway is still found for a listing (sanity)', isset( Registry::enabled( 'EUR' )['x2_a359'] ) && X2_A359_Gateway::$checks > 0 );
remove_filter( 'wpcap_payment_gateways', $x2_a359_cb );
$GLOBALS['wpcap_test_options']['wpcap_settings'] = $x2_a359_settings;
Registry::reset();
