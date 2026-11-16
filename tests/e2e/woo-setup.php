<?php
/**
 * Prepares WooCommerce on a disposable test site for the Blocks checkout E2E test: activates WooCommerce
 * (must already be in wp-content/plugins/woocommerce), runs its installer, switches to a block theme,
 * makes sure the checkout page uses the checkout block and creates a virtual product linked to a Pass
 * Captain ticket type with a required participant field. Prints JSON.
 *
 * Usage: php tests/e2e/woo-setup.php /path/to/wordpress <ticket_type_id>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$root = rtrim( $argv[1] ?? '', '/' );
$type = (int) ( $argv[2] ?? 0 );
if ( ! $root || ! is_file( "$root/wp-load.php" ) || ! $type ) {
	fwrite( STDERR, "Usage: php tests/e2e/woo-setup.php /path/to/wordpress <ticket_type_id>\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define( 'WP_USE_THEMES', false );
require "$root/wp-load.php";
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );

if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	$r = activate_plugin( 'woocommerce/woocommerce.php' );
	if ( is_wp_error( $r ) ) { fwrite( STDERR, 'activate: ' . $r->get_error_message() . "\n" ); exit( 1 ); }
	fwrite( STDERR, "woocommerce activated, re-run to finish the setup\n" );
	echo wp_json_encode( array( 'restart' => true ) ) . "\n";
	exit( 0 );
}
if ( ! get_option( 'woocommerce_checkout_page_id' ) && class_exists( 'WC_Install' ) ) { WC_Install::install(); }
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'AT' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Nachnahme' ) );
switch_theme( 'twentytwentyfive' );

$checkout = (int) get_option( 'woocommerce_checkout_page_id' );
if ( $checkout && ! has_block( 'woocommerce/checkout', get_post( $checkout ) ) ) {
	wp_update_post( array( 'ID' => $checkout, 'post_content' => '<!-- wp:woocommerce/checkout /-->' ) );
}
$cart = (int) get_option( 'woocommerce_cart_page_id' );

$product_id = (int) get_option( 'wpcap_e2e_product' );
if ( ! $product_id || ! wc_get_product( $product_id ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'E2E Konzertticket' );
	$product->set_regular_price( '25.00' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$product->update_meta_data( \PassCaptain\Integrations\WooCommerce\OrderAdapter::PRODUCT_META, $type );
	$product_id = $product->save();
	update_option( 'wpcap_e2e_product', $product_id );
}
echo wp_json_encode( array( 'product_id' => $product_id, 'checkout_url' => get_permalink( $checkout ), 'cart_url' => get_permalink( $cart ), 'shop' => home_url( '/' ) ) ) . "\n";
