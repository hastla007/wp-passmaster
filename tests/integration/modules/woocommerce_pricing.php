<?php
/**
 * WooCommerce adapter: optional Pass Captain prices (series price tiers) in the cart.
 * Included from run.php after series_pricing.php (uses its sp_series() helper). WooCommerce's cart
 * and session are replaced by the small fakes below.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Series\Pricing;
use PassCaptain\Integrations\WooCommerce\CartPricing;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

global $wpdb;
$p = $wpdb->prefix;

class Wpcap_Test_Cart_Product extends WC_Product {
	public $price = '30';
	public $regular_price = '30';
	public $sale_price = '';
	public string $name = 'Ticket';
	public function get_name() { return $this->name; }
	public function get_price() { return $this->price; }
	public function set_price( $v ) { $this->price = $v; }
	public function get_regular_price() { return $this->regular_price; }
	public function set_regular_price( $v ) { $this->regular_price = $v; }
	public function get_sale_price() { return $this->sale_price; }
	public function set_sale_price( $v ) { $this->sale_price = $v; }
	public function is_taxable() { return true; }
}
class Wpcap_Test_Session { public array $data = array(); public function set( $k, $v ) { $this->data[ $k ] = $v; } public function get( $k, $d = null ) { return $this->data[ $k ] ?? $d; } }
class Wpcap_Test_Cart { public array $cart_contents = array(); public function get_cart() { return $this->cart_contents; } }
class Wpcap_Test_WC { public $cart; public $session; public bool $store_api = false; public function is_store_api_request() { return $this->store_api; } }
class Wpcap_Test_Errors { public array $errors = array(); public function add( $code, $message ) { $this->errors[ $code ] = $message; } }
if ( ! function_exists( 'WC' ) ) {
	function WC() { return $GLOBALS['wpcap_test_wc']; }
}
if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $amount ) { return '<span class="amount">' . number_format( (float) $amount, 2, ',', '.' ) . '&nbsp;&euro;</span>'; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
}
if ( ! function_exists( 'wc_prices_include_tax' ) ) {
	function wc_prices_include_tax() { return 'yes' === get_option( 'woocommerce_prices_include_tax', 'yes' ); }
}

/** Fresh fake cart with one line of $qty units of a product linked to $type_id. */
function wcp_cart( $type_id, $qty, $product_id ) {
	$product = new Wpcap_Test_Cart_Product( $product_id );
	$product->update_meta_data( Woo::PRODUCT_META, $type_id );
	$GLOBALS['wpcap_test_products'][ $product_id ] = $product;
	$wc = new Wpcap_Test_WC();
	$wc->cart = new Wpcap_Test_Cart();
	$wc->session = new Wpcap_Test_Session();
	$GLOBALS['wpcap_test_wc'] = $wc;
	$item = CartPricing::add_cart_item( array( 'key' => 'line' . $product_id, 'data' => $product, 'quantity' => $qty ), 'line' . $product_id );
	$wc->cart->cart_contents[ 'line' . $product_id ] = $item;
	return $wc;
}

echo "WooCommerce: series price tiers in the cart (opt-in)\n";
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_notices'] = array();
$ws = sp_series( array( 12 ) );
$w_occ = $ws['occurrences'][0];
$w_type = $ws['types'][ $w_occ ]['Standard'];
$until_local = ( new DateTimeImmutable( '@' . ( time() + 86400 ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
$w_rule = Pricing::create( $ws['series_id'], array( 'kind' => 'early_bird', 'label' => 'Frühbucher', 'until' => $until_local, 'price_mode' => 'fixed', 'amount' => '19,00' ) );
CartPricing::flush();

// Default off: WooCommerce keeps its product price.
$wc = wcp_cart( $w_type, 2, 8701 );
CartPricing::apply_cart_prices( $wc->cart );
$product = $wc->cart->cart_contents['line8701']['data'];
check( 'option is off by default: Woo product price stays', ! CartPricing::enabled() && '30' === $product->get_price() && null === CartPricing::quote( $product ) && '<b>30</b>' === CartPricing::price_html( '<b>30</b>', $product ) );

update_option( CartPricing::OPTION, 'yes' );
CartPricing::flush();
$wc = wcp_cart( $w_type, 2, 8702 );
$product = $wc->cart->cart_contents['line8702']['data'];
check( 'add to cart remembers the shown tier price', 1900 === $wc->cart->cart_contents['line8702'][ CartPricing::SEEN_KEY ] );
CartPricing::apply_cart_prices( $wc->cart );
check( 'cart line price comes from Orders::unit_price (early bird, gross)', 19.0 === $product->get_price() && 25.0 === $product->get_regular_price() && 19.0 === $product->get_sale_price() );
$html = CartPricing::price_html( '<b>30</b>', $product );
check( 'product price HTML shows tier price, label and "statt" regular price', false !== strpos( $html, '19,00' ) && false !== strpos( $html, 'Frühbucher' ) && false !== strpos( $html, 'statt <del>' ) && false !== strpos( $html, '25,00' ) && false === strpos( $html, '30' ) );
$line_html = CartPricing::cart_item_price( 'x', $wc->cart->cart_contents['line8702'], 'line8702' );
check( 'classic cart/mini cart unit price shows the tier', false !== strpos( $line_html, '19,00' ) && false !== strpos( $line_html, 'statt' ) );
$meta = CartPricing::item_data( array(), $wc->cart->cart_contents['line8702'] );
check( 'cart line meta (classic + blocks item_data) names the tier', 1 === count( $meta ) && 'Frühbucher' === $meta[0]['value'] && 'Preisstufe' === $meta[0]['key'] );
check( 'unchanged price: no notice', array() === CartPricing::changes() );

$GLOBALS['wpcap_test_filters']['wpcap_woocommerce_use_core_prices'] = false;
CartPricing::flush();
check( 'filter can opt a product out', null === CartPricing::quote( $product ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_woocommerce_use_core_prices'] );

// Prices entered excluding tax: gross core price is converted with the ticket type's rate (no WC_Tax here).
$wpdb->update( "{$p}wpcap_ticket_types", array( 'tax_rate_bp' => 2000 ), array( 'ticket_type_id' => $w_type ) );
update_option( 'woocommerce_prices_include_tax', 'no' );
CartPricing::flush();
$q = CartPricing::quote( $product );
check( 'prices excl. tax: net entry price derived from the gross tier price (19,00 / 1.2)', abs( $q['price'] - 15.833333 ) < 0.000001 && abs( $q['regular'] - 20.833333 ) < 0.000001 && 1900 === $q['unit_minor'] );
check( 'net entry price plus tax rounds back to the gross price', 1900 === (int) round( $q['price'] * 1.2 * 100 ) );
update_option( 'woocommerce_prices_include_tax', 'yes' );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'tax_rate_bp' => 0 ), array( 'ticket_type_id' => $w_type ) );
check( 'entry_price: incl. tax keeps gross, zero-decimal currency', 19.0 === CartPricing::entry_price( 1900, 2, true, 20 ) && 1900.0 === CartPricing::entry_price( 1900, 0, true, 0 ) );

// Early bird ends between add-to-cart and checkout.
$wpdb->update( "{$p}wpcap_series_price_rules", array( 'until_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'rule_id' => $w_rule ) );
Pricing::flush_cache();
CartPricing::flush();
CartPricing::apply_cart_prices( $wc->cart );
check( 'expired tier: cart is re-priced to the stored price', 25.0 === $product->get_price() && 25.0 === $product->get_regular_price() && '' === $product->get_sale_price() );
check( 'price change since add-to-cart is detected', 1 === count( CartPricing::changes() ) && 2500 === array_values( CartPricing::changes() )[0]['new'] );
$GLOBALS['wpcap_test_notices'] = array();
CartPricing::report_changes_classic();
check( 'classic cart shows a price change notice once and stores the new price in the session', 1 === count( $GLOBALS['wpcap_test_notices'] ) && false !== strpos( $GLOBALS['wpcap_test_notices'][0], 'jetzt 25,00' ) && false !== strpos( $GLOBALS['wpcap_test_notices'][0], 'bisher 19,00' ) && 2500 === $wc->session->get( 'cart' )['line8702'][ CartPricing::SEEN_KEY ] && ! isset( $wc->session->get( 'cart' )['line8702']['data'] ) );
CartPricing::apply_cart_prices( $wc->cart );
CartPricing::report_changes_classic();
check( 'acknowledged change is not reported again', 1 === count( $GLOBALS['wpcap_test_notices'] ) && array() === CartPricing::changes() );

// Block checkout submission: change aborts once with a message.
$wc->cart->cart_contents['line8702'][ CartPricing::SEEN_KEY ] = 1900;
$wc->store_api = true;
CartPricing::apply_cart_prices( $wc->cart );
CartPricing::report_changes_classic();
check( 'Store API requests do not use classic notices', 1 === count( $GLOBALS['wpcap_test_notices'] ) && 1 === count( CartPricing::changes() ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
$errors = new Wpcap_Test_Errors();
CartPricing::report_changes_store_api( $errors );
check( 'block checkout submission is stopped once when the price changed', 1 === count( $errors->errors ) && false !== strpos( reset( $errors->errors ), 'erneut ab' ) );
CartPricing::apply_cart_prices( $wc->cart );
$errors = new Wpcap_Test_Errors();
CartPricing::report_changes_store_api( $errors );
check( 'second block checkout submission passes', array() === $errors->errors );
$wc->cart->cart_contents['line8702'][ CartPricing::SEEN_KEY ] = 1900;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
CartPricing::apply_cart_prices( $wc->cart );
$errors = new Wpcap_Test_Errors();
CartPricing::report_changes_store_api( $errors );
check( 'block cart (GET) just shows the new totals', array() === $errors->errors && 2500 === $wc->cart->cart_contents['line8702'][ CartPricing::SEEN_KEY ] );
unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
$wc->store_api = false;

// Classic checkout submission: error notice blocks the order once.
$wc->cart->cart_contents['line8702'][ CartPricing::SEEN_KEY ] = 1900;
CartPricing::apply_cart_prices( $wc->cart );
$GLOBALS['wpcap_test_notices'] = array();
$GLOBALS['wpcap_test_actions'][] = 'woocommerce_checkout_process';
CartPricing::report_changes_classic();
check( 'classic checkout submission gets a blocking message to review the new total', 1 === count( $GLOBALS['wpcap_test_notices'] ) && false !== strpos( $GLOBALS['wpcap_test_notices'][0], 'erneut ab' ) );
$GLOBALS['wpcap_test_actions'] = array_values( array_diff( $GLOBALS['wpcap_test_actions'], array( 'woocommerce_checkout_process' ) ) );

// Hold: WooCommerce line totals stay the trusted override, so both orders match exactly.
$wc_order = woo_t_order( 7401, array( array( $w_type, 2, '50.00' ) ) );
$core = Woo::reserve( $wc_order );
check( 'reservation keeps the WooCommerce line total (core order equals Woo order)', is_int( $core ) && 5000 === (int) Orders::get( $core )->total_minor );

// Currency mismatch: never reinterpret amounts.
$wpdb->update( "{$p}wpcap_ticket_types", array( 'currency' => 'CHF' ), array( 'ticket_type_id' => $w_type ) );
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() { return $GLOBALS['wpcap_test_options']['woocommerce_currency'] ?? 'EUR'; }
}
CartPricing::flush();
check( 'ticket currency differing from the shop currency keeps the Woo price', null === CartPricing::quote( $product ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'currency' => 'EUR' ), array( 'ticket_type_id' => $w_type ) );

check( 'settings: opt-in checkbox appended to WooCommerce → Produkte → Allgemein only', CartPricing::OPTION === CartPricing::settings( array(), '' )[1]['id'] && 'no' === CartPricing::settings( array(), '' )[1]['default'] && array() === CartPricing::settings( array(), 'inventory' ) );
update_option( CartPricing::OPTION, 'no' );
CartPricing::flush();
