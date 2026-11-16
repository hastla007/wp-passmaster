<?php
/**
 * A284: WooCommerce links survive no reinstall. uninstall.php (with data removal) deletes the plugin's
 * meta from WooCommerce orders, lines and products through the CRUD API (MetaCleanup), and a link
 * that still points to a core order of another WooCommerce order (IDs restart after a reinstall) is
 * ignored: the core order's idempotency key must be the keyed hash of "<WooCommerce id>-<attempt>".
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as A284Woo;
use PassCaptain\Integrations\WooCommerce\MetaCleanup as A284Cleanup;

global $wpdb;
$p = $wpdb->prefix;

echo "A284: stale WooCommerce links after a reinstall\n";
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['wpcap_test_orders'] ?? array() as $order ) {
			if ( isset( $args['meta_key'] ) && (string) $order->get_meta( $args['meta_key'], true ) !== (string) ( $args['meta_value'] ?? '' ) ) { continue; }
			foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
				if ( is_array( $clause ) && 'EXISTS' === ( $clause['compare'] ?? '' ) && '' === (string) $order->get_meta( $clause['key'], true ) ) { continue 2; }
			}
			$out[] = $order;
			if ( count( $out ) >= (int) ( $args['limit'] ?? 10 ) ) { break; }
		}
		return $out;
	}
}

$a284_type = make_type( 10, 10, 2000 );
// The "old" order from before the reinstall is created first, so a naive meta lookup finds it first.
$a284_old = new WC_Order( 92841 );
$a284_new = make_wc_order( 92840, $a284_type, 1, '20.00' );
$a284_core = A284Woo::reserve( $a284_new );
$a284_new->paid = true;
A284Woo::sync_paid_order( 92840 );
check( 'A284 fixture: the new WooCommerce order has its paid core order', is_int( $a284_core ) && 'paid' === Orders::get( $a284_core )->payment_status && 1 === count( Orders::tickets_for_order( $a284_core ) ) );
check( 'A284 the genuine link is verified', $a284_core === A284Woo::linked_core_id( $a284_new ) );

// Old order: same core id in its meta (the tables were dropped and the IDs started again).
$a284_old->update_meta_data( A284Woo::ORDER_META, $a284_core );
$a284_old->update_meta_data( A284Woo::ATTEMPT_META, 1 );
$a284_old->items[] = new WC_Order_Item_Product( 928410, $a284_new->items[0]->get_product(), 1, '20.00' );
check( 'A284 a link to the core order of another WooCommerce order is ignored', 0 === A284Woo::linked_core_id( $a284_old ) );
check( 'A284 core → WooCommerce lookup skips the stale order', ( A284Woo::wc_order_for_core( $a284_core ) ?: null ) === $a284_new && PassCaptain\Integrations\WooCommerce\Privacy::wc_order_for_core( $a284_core ) === $a284_new );

A284Woo::note_cancelled_occurrence( $a284_core, 0 );
check( 'A284 order notes for the core order go to the genuine WooCommerce order only', 1 === count( array_filter( $a284_new->notes, static fn( $n ) => false !== strpos( $n, 'abgesagt' ) ) ) && ! array_filter( $a284_old->notes, static fn( $n ) => false !== strpos( $n, 'abgesagt' ) ) );

// Checkout retry / refund on the old order must not cancel or refund the foreign order.
$a284_retry = A284Woo::reserve( $a284_old );
check( 'A284 reserve() on the stale order leaves the foreign order alone and links a new one', is_int( $a284_retry ) && $a284_retry !== $a284_core && 'paid' === Orders::get( $a284_core )->payment_status && 'cancelled' !== Orders::get( $a284_core )->status && $a284_retry === A284Woo::linked_core_id( $a284_old ) );
A284Woo::sync_fully_refunded_order( 92841 );
check( 'A284 refunding the old order does not refund the foreign core order', 'paid' === Orders::get( $a284_core )->payment_status && 1 === count( array_filter( Orders::tickets_for_order( $a284_core ), static fn( $t ) => 'valid' === $t->status ) ) );

// Uninstall cleanup through the CRUD API (orders, lines, products).
check( 'A284 MetaCleanup uses the adapter meta keys', array( A284Woo::ORDER_META, A284Woo::ATTEMPT_META, A284Woo::REFUND_META, A284Woo::ATTENDEE_META, A284Woo::DEFER_NOTE ) === A284Cleanup::ORDER_KEYS && A284Woo::ITEM_META === A284Cleanup::TYPE_KEY && A284Woo::PRODUCT_META === A284Cleanup::TYPE_KEY );
final class A284_Product { public array $meta = array(); public bool $saved = false; public function __construct( public int $id ) {} public function get_id() { return $this->id; } public function get_meta( $k, $s = true ) { return $this->meta[ $k ] ?? ''; } public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); } public function save() { $this->saved = true; } }
$a284_product = new A284_Product( 92842 );
$a284_product->meta[ A284Woo::PRODUCT_META ] = $a284_type;
$a284_plain = new A284_Product( 92843 );
$GLOBALS['a284_products'] = array( $a284_product, $a284_plain );
if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( $args = array() ) {
		return array_values( array_filter( $GLOBALS['a284_products'] ?? array(), static function ( $pr ) use ( $args ) {
			foreach ( (array) ( $args['meta_query'] ?? array() ) as $c ) { if ( '' === (string) $pr->get_meta( $c['key'] ) ) { return false; } }
			return true;
		} ) );
	}
}
$a284_all_orders = $GLOBALS['wpcap_test_orders'];
$GLOBALS['wpcap_test_orders'] = array( 92840 => $a284_new, 92841 => $a284_old );
$a284_new->update_meta_data( A284Woo::ATTENDEE_META, array( 'x' ) );
$a284_done = A284Cleanup::purge( 5 );
$GLOBALS['wpcap_test_orders'] = $a284_all_orders;
$a284_left = array();
foreach ( array( $a284_new, $a284_old ) as $a284_o ) {
	foreach ( A284Cleanup::ORDER_KEYS as $a284_k ) { if ( '' !== (string) $a284_o->get_meta( $a284_k ) ) { $a284_left[] = $a284_o->get_id() . ':' . $a284_k; } }
	foreach ( $a284_o->items as $a284_i ) { if ( '' !== (string) $a284_i->get_meta( A284Cleanup::TYPE_KEY ) ) { $a284_left[] = $a284_o->get_id() . ':item'; } }
}
check( 'A284 purge removes the order and line meta of all linked orders' . ( $a284_left ? ' – left ' . implode( ',', $a284_left ) : '' ), ! $a284_left && 2 === $a284_done['orders'] && $a284_done['complete'] );
check( 'A284 purge removes the ticket type of products (and leaves others alone)', '' === $a284_product->get_meta( A284Woo::PRODUCT_META ) && $a284_product->saved && ! $a284_plain->saved && 1 === $a284_done['products'] );
check( 'A284 uninstall.php runs the cleanup (with data removal, WooCommerce active)', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' ), 'MetaCleanup::purge()' ) );
check( 'A284 MetaCleanup works without the plugin autoloader (no other plugin classes)', ! preg_match( '/\b(?:Orders|Settings|OrderAdapter)::/', (string) php_strip_whitespace( dirname( __DIR__, 3 ) . '/src/Integrations/WooCommerce/MetaCleanup.php' ) ) );
foreach ( array( $a284_core, $a284_retry ) as $a284_c ) { if ( is_int( $a284_c ) ) { Orders::cancel_order( $a284_c, 'a284_cleanup' ); } }
