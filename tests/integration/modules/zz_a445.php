<?php
/**
 * A445: the WooCommerce order id is stored on the core order at reserve() (provider_reference
 * "woocommerce:<id>"), so notes for a cancelled occurrence find the WooCommerce order directly
 * instead of a meta_value search per order; old orders without it fall back to the meta search.
 * Included from run.php (uses check(), make_type(), make_wc_order()).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\WooCommerce\OrderAdapter as X2Woo;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
echo "A445: core order → WooCommerce order without meta search\n";

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['wpcap_test_orders'] ?? array() as $order ) {
			if ( isset( $args['meta_key'] ) && (string) $order->get_meta( $args['meta_key'], true ) !== (string) ( $args['meta_value'] ?? '' ) ) { continue; }
			$out[] = $order;
		}
		return $out;
	}
}

$x2_a445_type = make_type( 10, 10, 2000 );
$x2_a445_wc = make_wc_order( 94450, $x2_a445_type, 1, '20.00' );
$GLOBALS['wpcap_test_orders'][94450] = $x2_a445_wc;
$x2_a445_core = X2Woo::reserve( $x2_a445_wc );
check( 'A445: reserve() stores the WooCommerce order id on the core order', is_int( $x2_a445_core ) && 'woocommerce:94450' === (string) Orders::get( $x2_a445_core )->provider_reference );

// With the meta no longer searchable (e.g. a slow meta table is skipped), the direct reference still works.
$x2_a445_wc->update_meta_data( X2Woo::ORDER_META, 0 );
check( 'A445: the WooCommerce order is found through the stored id, not the meta search', $x2_a445_wc === X2Woo::wc_order_for_core( $x2_a445_core ) );
X2Woo::note_cancelled_occurrence( $x2_a445_core, 0 );
check( 'A445: the cancellation note reaches that order', 1 === count( array_filter( $x2_a445_wc->notes, static fn( $n ) => false !== strpos( $n, 'abgesagt' ) ) ) );

// The reference is verified like the meta link (A284): a foreign WooCommerce order id is ignored.
$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => 'woocommerce:94451' ), array( 'order_id' => $x2_a445_core ) );
$GLOBALS['wpcap_test_orders'][94451] = new WC_Order( 94451 );
check( 'A445: a reference to a WooCommerce order the core order was not created for is ignored', null === X2Woo::wc_order_for_core( $x2_a445_core ) );

// Orders reserved before the update (no reference) still use the meta search.
$wpdb->update( "{$p}wpcap_orders", array( 'provider_reference' => '' ), array( 'order_id' => $x2_a445_core ) );
$x2_a445_wc->update_meta_data( X2Woo::ORDER_META, $x2_a445_core );
check( 'A445: without a stored id the meta lookup is the fallback', $x2_a445_wc === X2Woo::wc_order_for_core( $x2_a445_core ) );

Orders::cancel_order( $x2_a445_core );
unset( $GLOBALS['wpcap_test_orders'][94450], $GLOBALS['wpcap_test_orders'][94451] );
