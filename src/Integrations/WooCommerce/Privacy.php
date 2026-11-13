<?php
/**
 * Personal data that the WooCommerce adapter keeps on WooCommerce orders (A196).
 *
 * The only personal data the adapter writes to a WooCommerce order is the attendee payload of the
 * checkout (order meta `_wpcap_attendees`: names, e-mail addresses and answers per ticket). It is
 * moved to the tickets when they are issued and deleted from the order then. Until that happens
 * (unpaid, cancelled or failed orders) it stays on the order, so it is covered here:
 *
 * - Export: WooCommerce's order exporter (keyed by the billing e-mail) lists the pending attendee
 *   entries (filter `woocommerce_privacy_export_order_personal_data`).
 * - Erasure: WooCommerce's order eraser and its own retention of cancelled/failed/pending orders
 *   (action `woocommerce_privacy_remove_order_personal_data`) delete the meta. Because WooCommerce
 *   only anonymises orders on request when "Remove personal data from orders on request" is enabled,
 *   the plugin's own eraser deletes it as well when it anonymises the linked Pass Captain order
 *   (action `wpcap_privacy_order_anonymized`), independent of that setting.
 * - Retention: when the daily run clears the attendee details of an unpaid Pass Captain order
 *   (step `unpaid_attendee_data`), the copy on the WooCommerce order goes too.
 *
 * Everything else on the WooCommerce order (billing address, line items, the order item meta
 * `_wpcap_ticket_type_id` and `_wpcap_core_order_id`) is either WooCommerce's own data, handled by
 * WooCommerce's exporter/eraser, or no personal data.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Privacy {
	public static function register() {
		add_filter( 'woocommerce_privacy_export_order_personal_data', array( __CLASS__, 'export_order' ), 10, 2 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'erase_order' ) );
		add_action( 'wpcap_privacy_order_anonymized', array( __CLASS__, 'core_order_anonymized' ) );
		add_action( 'wpcap_privacy_unpaid_attendee_data_cleared', array( __CLASS__, 'core_attendee_data_cleared' ) );
	}

	/**
	 * Adds the pending attendee entries of an order to WooCommerce's order export.
	 *
	 * @param array     $data     Rows (name/value) WooCommerce exports for the order.
	 * @param \WC_Order $wc_order Order.
	 */
	public static function export_order( $data, $wc_order = null ) {
		$data = is_array( $data ) ? $data : array();
		if ( ! is_object( $wc_order ) || ! method_exists( $wc_order, 'get_meta' ) ) { return $data; }
		foreach ( Attendees::for_order( $wc_order ) as $i => $attendee ) {
			$parts = array();
			foreach ( array( 'name', 'email' ) as $key ) {
				if ( '' !== (string) ( $attendee[ $key ] ?? '' ) ) { $parts[] = (string) $attendee[ $key ]; }
			}
			foreach ( (array) ( $attendee['answers'] ?? array() ) as $field => $answer ) {
				if ( '' !== (string) $answer ) { $parts[] = $field . ': ' . $answer; }
			}
			if ( ! $parts ) { continue; }
			$data[] = array(
				/* translators: %d: number of the ticket within the order */
				'name'  => sprintf( __( 'Pass Captain: Angaben zur teilnehmenden Person (Ticket %d, noch nicht ausgestellt)', 'pass-captain' ), $i + 1 ),
				'value' => implode( ' / ', $parts ),
			);
		}
		return $data;
	}

	/**
	 * Deletes the attendee payload from a WooCommerce order.
	 *
	 * @param \WC_Order $wc_order Order.
	 * @return bool Whether something was deleted.
	 */
	public static function erase_order( $wc_order ) {
		if ( ! is_object( $wc_order ) || ! method_exists( $wc_order, 'get_meta' ) || ! $wc_order->get_meta( OrderAdapter::ATTENDEE_META, true ) ) { return false; }
		$wc_order->delete_meta_data( OrderAdapter::ATTENDEE_META );
		$wc_order->save();
		return true;
	}

	/** WooCommerce order linked to a Pass Captain order (null when none or WooCommerce is missing). */
	public static function wc_order_for_core( $core_order_id ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! absint( $core_order_id ) ) { return null; }
		$orders = wc_get_orders( array( 'limit' => 1, 'meta_key' => OrderAdapter::ORDER_META, 'meta_value' => absint( $core_order_id ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $orders ? $orders[0] : null;
	}

	/** wpcap_privacy_order_anonymized: the plugin eraser anonymised a (WooCommerce) order. */
	public static function core_order_anonymized( $core_order_id ) {
		if ( ! self::from_woocommerce( array( $core_order_id ) ) ) { return; }
		self::erase_order( self::wc_order_for_core( $core_order_id ) );
	}

	/**
	 * wpcap_privacy_unpaid_attendee_data_cleared: retention cleared the attendee details of unpaid orders.
	 *
	 * @param int[] $order_ids Pass Captain order ids (one retention batch).
	 */
	public static function core_attendee_data_cleared( $order_ids ) {
		foreach ( self::from_woocommerce( (array) $order_ids ) as $core_order_id ) {
			self::erase_order( self::wc_order_for_core( $core_order_id ) );
		}
	}

	/** The ids among $order_ids whose order came through WooCommerce. */
	private static function from_woocommerce( array $order_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( ! $ids ) { return array(); }
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE payment_provider='woocommerce' AND order_id IN (" . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}
}
