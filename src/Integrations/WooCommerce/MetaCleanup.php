<?php
/**
 * Removes the plugin's WooCommerce metadata on uninstall with data removal (A284).
 *
 * Loaded directly by uninstall.php (no autoloader there), so it depends on nothing else of the
 * plugin. Only the WooCommerce CRUD API is used (HPOS and posts storage alike); with WooCommerce
 * inactive nothing can be removed here – the verified back reference in
 * OrderAdapter::linked_core_id() then keeps stale links from pointing at new orders.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class MetaCleanup {
	/** Order meta (same values as the OrderAdapter constants ORDER_META, ATTEMPT_META, REFUND_META, ATTENDEE_META, DEFER_NOTE). */
	const ORDER_KEYS = array( '_wpcap_core_order_id', '_wpcap_checkout_attempt', '_wpcap_refund_processed', '_wpcap_attendees', '_wpcap_deferred_note' );
	/** Line item and product meta (OrderAdapter::ITEM_META / PRODUCT_META). */
	const TYPE_KEY = '_wpcap_ticket_type_id';
	const BATCH = 100;

	/**
	 * Deletes the meta from orders (and their lines) and from products, within a time budget.
	 *
	 * @param int $seconds Time budget; what is left keeps its meta (harmless thanks to the back reference check).
	 * @return array{orders:int, products:int, complete:bool}
	 */
	public static function purge( $seconds = 20 ) {
		$done = array( 'orders' => 0, 'products' => 0, 'complete' => true );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			$done['complete'] = false;
			return $done;
		}
		$deadline = microtime( true ) + max( 1, (int) $seconds );
		// Each batch removes the meta it matched, so the next query finds the remaining orders.
		$seen = array();
		while ( microtime( true ) < $deadline ) {
			$orders = wc_get_orders( array( 'limit' => self::BATCH, 'meta_query' => array( array( 'key' => self::ORDER_KEYS[0], 'compare' => 'EXISTS' ) ), 'orderby' => 'ID', 'order' => 'ASC' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$fresh = 0;
			foreach ( (array) $orders as $order ) {
				if ( ! is_object( $order ) || isset( $seen[ $order->get_id() ] ) ) { continue; }
				$seen[ $order->get_id() ] = true;
				++$fresh;
				foreach ( self::ORDER_KEYS as $key ) { $order->delete_meta_data( $key ); }
				foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
					if ( is_object( $item ) && method_exists( $item, 'delete_meta_data' ) && $item->get_meta( self::TYPE_KEY, true ) ) {
						$item->delete_meta_data( self::TYPE_KEY );
						$item->save();
					}
				}
				$order->save();
				++$done['orders'];
			}
			if ( ! $fresh ) { break; }
		}
		if ( microtime( true ) >= $deadline ) { $done['complete'] = false; }
		// Products and variations are posts in every storage mode.
		if ( function_exists( 'wc_get_products' ) && microtime( true ) < $deadline ) {
			$seen = array();
			while ( microtime( true ) < $deadline ) {
				$products = wc_get_products( array( 'limit' => self::BATCH, 'type' => array_merge( array_keys( function_exists( 'wc_get_product_types' ) ? wc_get_product_types() : array() ), array( 'variation' ) ), 'meta_query' => array( array( 'key' => self::TYPE_KEY, 'compare' => 'EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$fresh = 0;
				foreach ( (array) $products as $product ) {
					if ( ! is_object( $product ) || isset( $seen[ $product->get_id() ] ) ) { continue; }
					$seen[ $product->get_id() ] = true;
					++$fresh;
					$product->delete_meta_data( self::TYPE_KEY );
					$product->save();
					++$done['products'];
				}
				if ( ! $fresh ) { break; }
			}
			if ( microtime( true ) >= $deadline ) { $done['complete'] = false; }
		}
		return $done;
	}
}
