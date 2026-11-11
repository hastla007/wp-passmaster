<?php
/** Optional WooCommerce adapter; uses order and product CRUD APIs only. */
namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrderAdapter {
	const PRODUCT_META = '_wpcap_ticket_type_id';
	const ORDER_META = '_wpcap_core_order_id';

	public static function register() {
		if ( ! class_exists( '\\WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) { return; }
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'sync_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'sync_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'sync_cancelled_order' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_refunded_order' ), 20 );
	}

	public static function product_field() {
		woocommerce_wp_text_input( array(
			'id' => self::PRODUCT_META,
			'label' => __( 'Pass Captain Ticketkategorie-ID', 'wp-pass-captain' ),
			'description' => __( 'Verknüpft dieses Produkt mit einer Ticketkategorie. Ticketpreis und Kontingent werden von Pass Captain bestimmt.', 'wp-pass-captain' ),
			'desc_tip' => true,
			'type' => 'number',
			'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
		) );
	}

	public static function save_product_field( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) { return; }
		$type_id = absint( wp_unslash( $_POST[ self::PRODUCT_META ] ?? 0 ) );
		if ( $type_id ) { $product->update_meta_data( self::PRODUCT_META, $type_id ); }
		else { $product->delete_meta_data( self::PRODUCT_META ); }
	}

	public static function sync_paid_order( $wc_order_id ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( ! $wc_order || $wc_order->get_meta( self::ORDER_META ) ) { return; }
		$email = sanitize_email( $wc_order->get_billing_email() );
		if ( ! is_email( $email ) ) { $wc_order->add_order_note( __( 'Pass Captain: gültige Rechnungs-E-Mail fehlt.', 'wp-pass-captain' ) ); return; }
		$items = array();
		foreach ( $wc_order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$type_id = $product ? absint( $product->get_meta( self::PRODUCT_META, true ) ) : 0;
			if ( ! $type_id ) { continue; }
			$items[] = array( 'ticket_type_id' => $type_id, 'quantity' => absint( $item->get_quantity() ) );
		}
		if ( ! $items ) { return; }
		$result = Orders::create( array( 'email' => $email, 'items' => $items, 'idempotency_key' => 'woocommerce-' . absint( $wc_order_id ) ) );
		if ( is_wp_error( $result ) ) { $wc_order->add_order_note( sprintf( __( 'Pass Captain konnte keine Tickets anlegen: %s', 'wp-pass-captain' ), $result->get_error_message() ) ); return; }
		$paid = 'paid' === ( $result['payment_status'] ?? '' ) ? true : Orders::confirm_manual_payment( $result['order_id'] );
		if ( is_wp_error( $paid ) ) { $wc_order->add_order_note( sprintf( __( 'Pass Captain wartet auf manuelle Zahlungsbestätigung: %s', 'wp-pass-captain' ), $paid->get_error_message() ) ); return; }
		$wc_order->update_meta_data( self::ORDER_META, absint( $result['order_id'] ) );
		$wc_order->save();
		$wc_order->add_order_note( __( 'Pass Captain Tickets wurden synchronisiert.', 'wp-pass-captain' ) );
	}

	public static function sync_cancelled_order( $wc_order_id ) { self::change_order_state( $wc_order_id, 'cancelled' ); }
	public static function sync_refunded_order( $wc_order_id ) { self::change_order_state( $wc_order_id, 'refunded' ); }

	private static function change_order_state( $wc_order_id, $state ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		$core_order_id = $wc_order ? absint( $wc_order->get_meta( self::ORDER_META, true ) ) : 0;
		if ( ! $core_order_id ) { return; }
		global $wpdb;
		$orders = $wpdb->prefix . 'wpcap_orders';
		$wpdb->update( $orders, array( 'status' => $state, 'payment_status' => $state, 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => $core_order_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_reservations', array( 'status' => 'released' ), array( 'order_id' => $core_order_id, 'status' => 'active' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 'refunded' === $state ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'status' => 'revoked' ), array( 'order_id' => $core_order_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) );
		}
	}
}
