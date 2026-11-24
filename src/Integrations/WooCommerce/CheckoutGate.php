<?php
/**
 * WooCommerce side of the add-on checkout gate (wp_pass_captain_checkout_allowed_v1, see
 * PassCaptain\Sales\CheckoutGate): asked when a ticket product is added to the cart (classic and Store
 * API) and when the checkout is submitted (classic validation, Store API checkout before the hold).
 * Not asked for order processing after checkout (payment, status changes) or orders created in the
 * WooCommerce admin. Products without a Pass Captain ticket type are never affected.
 *
 * Sources in the context: `woocommerce` (classic) and `woocommerce_store_api` (blocks); phases `cart`
 * and `checkout`.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Sales\CheckoutGate as Gate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class CheckoutGate {
	/** Registers the hooks when WooCommerce is active. */
	public static function register() {
		if ( ! class_exists( '\\WooCommerce' ) ) {
			return;
		}
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 20, 4 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'store_api_validate_add_to_cart' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 20, 2 );
		// Before OrderAdapter::reserve_store_api() (priority 10) places the hold.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_api_checkout' ), 5 );
	}

	/** Classic add to cart: refuses with a notice. */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$type_id = OrderAdapter::product_ticket_type_id( $product );
		if ( ! $type_id ) {
			return $passed;
		}
		$items             = OrderAdapter::cart_quantities();
		$items[ $type_id ] = ( $items[ $type_id ] ?? 0 ) + absint( $quantity );
		$result            = Gate::check( array( 'source' => 'woocommerce', 'phase' => 'cart', 'items' => $items ) );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return false;
		}
		return $passed;
	}

	/** Store API add to cart (blocks, mini cart): refuses with the gate's HTTP status. */
	public static function store_api_validate_add_to_cart( $product, $request = null ) {
		$type_id = OrderAdapter::product_ticket_type_id( $product );
		if ( ! $type_id ) {
			return;
		}
		$quantity          = is_object( $request ) && method_exists( $request, 'get_param' ) ? max( 1, absint( $request->get_param( 'quantity' ) ) ) : 1;
		$items             = OrderAdapter::cart_quantities();
		$items[ $type_id ] = ( $items[ $type_id ] ?? 0 ) + $quantity;
		self::throw_if_refused( Gate::check( array( 'source' => 'woocommerce_store_api', 'phase' => 'cart', 'items' => $items, 'request' => $request ) ) );
	}

	/** Classic checkout submit: adds the refusal to the checkout errors (no order is created). */
	public static function validate_checkout( $data, $errors ) {
		$items = OrderAdapter::cart_quantities();
		if ( ! $items ) {
			return;
		}
		$email  = is_array( $data ) && is_string( $data['billing_email'] ?? null ) ? sanitize_email( $data['billing_email'] ) : '';
		$result = Gate::check( array( 'source' => 'woocommerce', 'phase' => 'checkout', 'items' => $items, 'email' => $email ) );
		if ( is_wp_error( $result ) && is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wpcap_checkout_not_allowed', $result->get_error_message() );
		}
	}

	/** Store API checkout: refuses before the hold is placed and before payment. */
	public static function store_api_checkout( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = is_object( $item ) && method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$type_id = $product ? OrderAdapter::product_ticket_type_id( $product ) : 0;
			if ( $type_id ) {
				$items[ $type_id ] = ( $items[ $type_id ] ?? 0 ) + absint( $item->get_quantity() );
			}
		}
		if ( ! $items ) {
			return;
		}
		$email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
		self::throw_if_refused( Gate::check( array( 'source' => 'woocommerce_store_api', 'phase' => 'checkout', 'items' => $items, 'email' => $email, 'idempotency_key' => method_exists( $order, 'get_id' ) ? (string) $order->get_id() : '' ) ) );
	}

	/**
	 * Store API: a refusal becomes a RouteException with the gate's status (e.g. 429/503) and retry_after.
	 *
	 * @param true|\WP_Error $result Gate result.
	 */
	private static function throw_if_refused( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		$data = (array) $result->get_error_data();
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( (string) $result->get_error_code() ), esc_html( $result->get_error_message() ), (int) $data['status'], array_intersect_key( $data, array_flip( array( 'retry_after', 'checkout_gate' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped above.
		}
		throw new \Exception( esc_html( $result->get_error_message() ), (int) $data['status'] );
	}
}
