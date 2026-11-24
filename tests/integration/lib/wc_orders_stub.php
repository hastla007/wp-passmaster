<?php
/**
 * wc_get_orders() over $GLOBALS['wpcap_test_orders'] (meta_key/meta_value, meta_query EXISTS, limit), the
 * definition modules/woocommerce_multiseller.php introduced. Not loaded for every module: the plugin
 * checks function_exists( 'wc_get_orders' ), so only modules that need it load this file (require_once).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! function_exists( 'wc_get_orders' ) ) {
	// First definition wins for the whole run: also serves later modules (meta_query EXISTS + limit for
	// MetaCleanup in zz_a196/zz_a284).
	function wc_get_orders( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['wpcap_test_orders'] ?? array() as $order ) {
			if ( isset( $args['meta_key'] ) ) {
				if ( in_array( (string) ( $args['meta_value'] ?? '' ), array_map( 'strval', (array) ( $order->meta[ $args['meta_key'] ] ?? array() ) ), true ) ) { return array( $order ); }
				continue;
			}
			foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
				if ( is_array( $clause ) && 'EXISTS' === ( $clause['compare'] ?? '' ) && '' === (string) $order->get_meta( $clause['key'], true ) ) { continue 2; }
			}
			$out[] = $order;
			if ( count( $out ) >= (int) ( $args['limit'] ?? 10 ) ) { break; }
		}
		return $out;
	}
}
