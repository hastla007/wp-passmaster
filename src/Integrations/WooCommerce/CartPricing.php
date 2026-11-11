<?php
/**
 * Optional: Pass Captain ticket prices in the WooCommerce cart.
 *
 * Off by default (WooCommerce keeps its product prices). When the shop owner enables
 * "Pass-Captain-Preise im WooCommerce-Warenkorb verwenden" (WooCommerce → Einstellungen →
 * Produkte), the price of every cart line linked to a ticket type comes from
 * Orders::unit_price() – the stored ticket price plus the server-side filter
 * `wpcap_ticket_unit_price` (e.g. series price tiers: early bird, last minute, demand, weekday).
 *
 * - Prices are recomputed on every cart load and every calculate_totals() (classic cart, mini cart,
 *   checkout, Store API/blocks), so a tier that ends between add-to-cart and checkout is applied.
 *   Nothing from the client is used: only the product's linked ticket type and the cart quantity.
 * - Core prices are gross minor units. If WooCommerce prices are entered excluding tax, the gross
 *   price is converted to net with the WooCommerce base tax rates of the product's tax class
 *   (fallback: the ticket type's tax rate), so customers in the shop's base location pay exactly
 *   the core gross price.
 * - The customer is told when the unit price of a line changed since it was last shown (added to
 *   the cart or the last notice). During checkout submission (classic and block) a change aborts
 *   the order once so nobody pays a price they have not seen; resubmitting places the order.
 * - The hold still receives the WooCommerce line totals as trusted override, so the Pass Captain
 *   order matches the WooCommerce order exactly (coupons included).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CartPricing {
	const OPTION   = 'wpcap_woocommerce_core_prices';
	const SEEN_KEY  = 'wpcap_seen_unit_minor';

	/** Per-request quote cache: "type|qty|phase" => quote. */
	private static $quotes = array();

	/** Unit price changes detected in this request: cart item key => change. */
	private static $changes = array();

	/** Registers hooks (only when WooCommerce is active; called from OrderAdapter::register()). */
	public static function register() {
		add_filter( 'woocommerce_get_settings_products', array( __CLASS__, 'settings' ), 10, 2 );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'apply_cart_prices' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_prices' ), 20 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'add_cart_item' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price' ), 20, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'report_changes_classic' ), 20 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'report_changes_store_api' ), 20, 2 );
	}

	/** Whether the option is on (filterable per product/ticket type when both are given). */
	public static function enabled( $product = null, $type_id = 0 ) {
		$enabled = 'yes' === get_option( self::OPTION, 'no' );
		if ( null === $product ) {
			return $enabled;
		}
		/**
		 * Whether Pass Captain prices apply to this WooCommerce ticket product.
		 *
		 * @param bool        $enabled Global setting.
		 * @param \WC_Product $product Product or variation (cart line product).
		 * @param int         $type_id Linked ticket type.
		 */
		return (bool) apply_filters( 'wpcap_woocommerce_use_core_prices', $enabled, $product, (int) $type_id );
	}

	/** WooCommerce → Einstellungen → Produkte → Allgemein: opt-in checkbox. */
	public static function settings( $settings, $section = '' ) {
		if ( '' !== (string) $section ) {
			return $settings;
		}
		$settings[] = array(
			'title' => __( 'WP Pass Captain', 'wp-pass-captain' ),
			'type'  => 'title',
			'id'    => 'wpcap_woocommerce_options',
		);
		$settings[] = array(
			'title'    => __( 'Ticketpreise', 'wp-pass-captain' ),
			'desc'     => __( 'Pass-Captain-Preise (z. B. Serien-Preisstufen) im WooCommerce-Warenkorb verwenden', 'wp-pass-captain' ),
			'desc_tip' => __( 'Ticketprodukte kosten dann den Preis der Pass-Captain-Ticketkategorie inklusive aktiver Preisstufe statt des WooCommerce-Produktpreises. Der Preis wird im Warenkorb und an der Kasse laufend neu berechnet.', 'wp-pass-captain' ),
			'id'       => self::OPTION,
			'type'     => 'checkbox',
			'default'  => 'no',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wpcap_woocommerce_options',
		);
		return $settings;
	}

	/* ------------------------------------------------------------------ */
	/* Price calculation (server-side only)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Effective price of a ticket product for a quantity, or null if core prices do not apply.
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param int         $quantity Quantity of the ticket type in the cart (1 for display).
	 * @param string      $phase    'display' (product price) or 'cart'.
	 * @return array{type_id:int, unit_minor:int, regular_minor:int, label:string, price:float, regular:float}|null
	 *         price/regular are in WooCommerce's entry convention (incl. or excl. tax).
	 */
	public static function quote( $product, $quantity = 1, $phase = 'cart' ) {
		$type_id = OrderAdapter::product_ticket_type_id( $product );
		if ( ! $type_id || ! self::enabled( $product, $type_id ) ) {
			return null;
		}
		$quantity  = max( 1, absint( $quantity ) );
		$cache_key = $type_id . '|' . $quantity . '|' . $phase;
		if ( ! array_key_exists( $cache_key, self::$quotes ) ) {
			self::$quotes[ $cache_key ] = self::core_quote( $type_id, $quantity, $phase );
		}
		$core = self::$quotes[ $cache_key ];
		if ( ! $core ) {
			return null;
		}
		$rate = self::tax_rate_percent( $product, $core['type'] );
		return array(
			'type_id'       => $type_id,
			'unit_minor'    => $core['unit_minor'],
			'regular_minor' => $core['regular_minor'],
			'label'         => $core['label'],
			'price'         => self::entry_price( $core['unit_minor'], $core['decimals'], self::prices_include_tax(), $rate ),
			'regular'       => self::entry_price( $core['regular_minor'], $core['decimals'], self::prices_include_tax(), $rate ),
		);
	}

	/** Core unit price, stored price and tier label of a ticket type (null: not applicable). */
	private static function core_quote( $type_id, $quantity, $phase ) {
		$type = OrderAdapter::ticket_type( $type_id );
		if ( ! $type ) {
			return null;
		}
		$currency = strtoupper( (string) ( $type->currency ?? '' ) );
		$shop     = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : $currency;
		if ( '' !== $currency && '' !== $shop && $currency !== $shop ) {
			// Never reinterpret an amount in another currency; WooCommerce's price stays.
			return null;
		}
		$context = array( 'source' => 'woocommerce', 'phase' => $phase, 'quantity' => $quantity );
		$unit    = Orders::unit_price( $type, null, $quantity, $context );
		$label   = $unit !== (int) $type->price_minor ? Orders::price_label( $type, null, $context ) : '';
		return array(
			'type'          => $type,
			'unit_minor'    => (int) $unit,
			'regular_minor' => (int) $type->price_minor,
			'label'         => $label,
			'decimals'      => OrderAdapter::currency_decimals( '' !== $currency ? $currency : $shop ),
		);
	}

	/**
	 * Converts a gross core price (minor units) into WooCommerce's entry convention. Pure.
	 *
	 * @param int   $gross_minor         Gross price in minor units.
	 * @param int   $decimals            Currency minor unit digits.
	 * @param bool  $prices_include_tax  WooCommerce "Preise inkl. Steuer eingeben".
	 * @param float $rate_percent        Total tax rate of the product in percent (0 = untaxed).
	 */
	public static function entry_price( $gross_minor, $decimals, $prices_include_tax, $rate_percent ) {
		$gross = (int) $gross_minor / ( 10 ** (int) $decimals );
		if ( $prices_include_tax || $rate_percent <= 0 ) {
			return round( $gross, (int) $decimals );
		}
		// Net with WooCommerce's rounding precision so net + tax rounds back to the gross price.
		return round( $gross / ( 1 + $rate_percent / 100 ), 6 );
	}

	/** Whether WooCommerce prices are entered including tax (or taxes are off). */
	private static function prices_include_tax() {
		if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) {
			return true;
		}
		return function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : true;
	}

	/**
	 * Tax rate (percent) used to derive a net entry price: WooCommerce base rates of the product's
	 * tax class (compound rates applied like WooCommerce does), else the ticket type's tax rate.
	 */
	private static function tax_rate_percent( $product, $type ) {
		if ( self::prices_include_tax() ) {
			return 0.0;
		}
		if ( is_callable( array( $product, 'is_taxable' ) ) && ! $product->is_taxable() ) {
			return 0.0;
		}
		if ( class_exists( '\\WC_Tax' ) && is_callable( array( $product, 'get_tax_class' ) ) ) {
			$rates = \WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
			if ( $rates ) {
				$taxes = \WC_Tax::calc_exclusive_tax( 100.0, $rates );
				return (float) array_sum( $taxes );
			}
		}
		return (int) ( $type->tax_rate_bp ?? 0 ) / 100;
	}

	/* ------------------------------------------------------------------ */
	/* Cart                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Sets the effective price on every linked cart line. Runs on cart load (mini cart, Store API
	 * responses) and before each totals calculation, so the price is always current.
	 */
	public static function apply_cart_prices( $cart ) {
		if ( ! is_object( $cart ) || ! isset( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) ) {
			return;
		}
		$quantities = self::quantities( $cart->cart_contents );
		foreach ( $cart->cart_contents as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! is_object( $product ) ) {
				continue;
			}
			$type_id = OrderAdapter::product_ticket_type_id( $product );
			$quote   = $type_id ? self::quote( $product, $quantities[ $type_id ] ?? 1, 'cart' ) : null;
			if ( ! $quote ) {
				continue;
			}
			$product->set_price( $quote['price'] );
			$lower = $quote['unit_minor'] < $quote['regular_minor'];
			$product->set_regular_price( $lower ? $quote['regular'] : $quote['price'] );
			$product->set_sale_price( $lower ? $quote['price'] : '' );

			$seen = $item[ self::SEEN_KEY ] ?? null;
			if ( null === $seen ) {
				// Line from before the option was enabled: the current price counts as shown.
				$cart->cart_contents[ $key ][ self::SEEN_KEY ] = $quote['unit_minor'];
			} elseif ( (int) $seen !== $quote['unit_minor'] ) {
				self::$changes[ $key ] = array(
					'name'   => is_callable( array( $product, 'get_name' ) ) ? $product->get_name() : '',
					'old'    => (int) $seen,
					'new'    => $quote['unit_minor'],
					'label'  => $quote['label'],
					'type'   => $type_id,
				);
			} else {
				unset( self::$changes[ $key ] );
			}
		}
	}

	/** Ticket quantity per ticket type over all cart lines. */
	private static function quantities( array $contents ) {
		$quantities = array();
		foreach ( $contents as $item ) {
			$type_id = empty( $item['data'] ) ? 0 : OrderAdapter::product_ticket_type_id( $item['data'] );
			if ( $type_id ) {
				$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + absint( $item['quantity'] ?? 0 );
			}
		}
		return $quantities;
	}

	/** Remembers the price shown when a line is added (classic and Store API add-to-cart). */
	public static function add_cart_item( $item, $key = '' ) {
		if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
			return $item;
		}
		$type_id = OrderAdapter::product_ticket_type_id( $item['data'] );
		if ( ! $type_id ) {
			return $item;
		}
		$quantities = function_exists( 'WC' ) && WC()->cart ? self::quantities( WC()->cart->cart_contents ) : array();
		$quote      = self::quote( $item['data'], ( $quantities[ $type_id ] ?? 0 ) + absint( $item['quantity'] ?? 1 ), 'cart' );
		if ( $quote ) {
			$item[ self::SEEN_KEY ] = $quote['unit_minor'];
			$item['data']->set_price( $quote['price'] );
		}
		return $item;
	}

	/** Price changes detected so far in this request (cart item key => change). */
	public static function changes() {
		return self::$changes;
	}

	/** Classic cart/checkout: notice (cart page) or error (checkout submission) per changed line. */
	public static function report_changes_classic() {
		if ( ! self::$changes || self::is_store_api_request() ) {
			return;
		}
		$submitting = did_action( 'woocommerce_checkout_process' ) > 0;
		foreach ( self::$changes as $change ) {
			wc_add_notice( self::change_message( $change, $submitting ), $submitting ? 'error' : 'notice' );
		}
		if ( $submitting && function_exists( 'WC' ) && WC()->session ) {
			// The checkout's failure response then refreshes the order review with the new total.
			WC()->session->set( 'refresh_totals', true );
		}
		self::acknowledge();
	}

	/**
	 * Store API: a change during the block checkout submission aborts it once (the block shows the
	 * message and refreshes the totals); other Store API requests show the new totals anyway.
	 */
	public static function report_changes_store_api( $errors, $cart = null ) {
		if ( ! self::$changes ) {
			return;
		}
		if ( self::is_checkout_submission() && is_object( $errors ) ) {
			foreach ( array_values( self::$changes ) as $index => $change ) {
				$errors->add( 'wpcap_ticket_price_changed_' . $index, self::change_message( $change, true ) );
			}
		}
		self::acknowledge();
	}

	/** Customer facing message for a changed unit price. */
	public static function change_message( array $change, $submitting = false ) {
		$new = self::format_minor( $change['new'], $change['type'] );
		if ( '' !== (string) $change['label'] ) {
			$new .= ' (' . $change['label'] . ')';
		}
		$message = sprintf(
			/* translators: 1: product name, 2: new price (with tier label), 3: previous price */
			__( 'Der Ticketpreis für „%1$s“ hat sich geändert: jetzt %2$s statt bisher %3$s.', 'wp-pass-captain' ),
			$change['name'],
			$new,
			self::format_minor( $change['old'], $change['type'] )
		);
		if ( $submitting ) {
			$message .= ' ' . __( 'Bitte prüfe die neue Summe und sende die Bestellung erneut ab.', 'wp-pass-captain' );
		}
		return $message;
	}

	/** Marks the current prices as shown and stores that in the cart session. */
	private static function acknowledge() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			self::$changes = array();
			return;
		}
		$cart = WC()->cart;
		foreach ( self::$changes as $key => $change ) {
			if ( isset( $cart->cart_contents[ $key ] ) ) {
				$cart->cart_contents[ $key ][ self::SEEN_KEY ] = $change['new'];
			}
		}
		self::$changes = array();
		if ( WC()->session ) {
			$session = array();
			foreach ( $cart->cart_contents as $key => $values ) {
				unset( $values['data'] );
				$session[ $key ] = $values;
			}
			WC()->session->set( 'cart', $session ? $session : null );
		}
	}

	/** Gross amount of minor units as WooCommerce price string (plain text for notices). */
	private static function format_minor( $minor, $type_id ) {
		$type     = OrderAdapter::ticket_type( $type_id );
		$decimals = OrderAdapter::currency_decimals( $type ? $type->currency : '' );
		$amount   = (int) $minor / ( 10 ** $decimals );
		return function_exists( 'wc_price' ) ? wp_strip_all_tags( html_entity_decode( wc_price( $amount ), ENT_QUOTES, 'UTF-8' ) ) : number_format( $amount, $decimals, ',', '.' );
	}

	/* ------------------------------------------------------------------ */
	/* Display                                                             */
	/* ------------------------------------------------------------------ */

	/** Product price HTML (product page, loops, product blocks, Store API price_html). */
	public static function price_html( $html, $product = null ) {
		if ( ! is_object( $product ) ) {
			return $html;
		}
		$quote = self::quote( $product, 1, 'display' );
		if ( ! $quote ) {
			return $html;
		}
		$price   = self::to_display( $product, $quote['price'] );
		$regular = self::to_display( $product, $quote['regular'] );
		$out     = self::tier_html( $price, $regular, $quote['unit_minor'] < $quote['regular_minor'], $quote['label'] );
		if ( is_callable( array( $product, 'get_price_suffix' ) ) ) {
			$out .= $product->get_price_suffix( $quote['price'] );
		}
		return $out;
	}

	/** Classic cart and mini cart unit price: effective price and – if lower – "statt" regular price. */
	public static function cart_item_price( $html, $cart_item = array(), $key = '' ) {
		$product = $cart_item['data'] ?? null;
		if ( ! is_object( $product ) ) {
			return $html;
		}
		$type_id    = OrderAdapter::product_ticket_type_id( $product );
		$quantities = function_exists( 'WC' ) && WC()->cart ? self::quantities( WC()->cart->cart_contents ) : array();
		$quote      = $type_id ? self::quote( $product, $quantities[ $type_id ] ?? absint( $cart_item['quantity'] ?? 1 ), 'cart' ) : null;
		if ( ! $quote ) {
			return $html;
		}
		$cart_display = get_option( 'woocommerce_tax_display_cart', 'incl' );
		$price        = self::to_display( $product, $quote['price'], $cart_display );
		$regular      = self::to_display( $product, $quote['regular'], $cart_display );
		// The tier label is shown once, as cart line meta (item_data).
		return self::tier_html( $price, $regular, $quote['unit_minor'] < $quote['regular_minor'], '' );
	}

	/** Cart line meta "Preisstufe" (classic cart/checkout, cart and checkout blocks via item_data). */
	public static function item_data( $data, $cart_item = array() ) {
		$product = $cart_item['data'] ?? null;
		if ( ! is_object( $product ) ) {
			return $data;
		}
		$type_id    = OrderAdapter::product_ticket_type_id( $product );
		$quantities = function_exists( 'WC' ) && WC()->cart ? self::quantities( WC()->cart->cart_contents ) : array();
		$quote      = $type_id ? self::quote( $product, $quantities[ $type_id ] ?? absint( $cart_item['quantity'] ?? 1 ), 'cart' ) : null;
		if ( $quote && '' !== $quote['label'] ) {
			$data   = is_array( $data ) ? $data : array();
			$data[] = array(
				'key'   => __( 'Preisstufe', 'wp-pass-captain' ),
				'value' => $quote['label'],
			);
		}
		return $data;
	}

	/** Entry price → display price according to the shop's/cart's tax display setting. */
	private static function to_display( $product, $entry, $display = '' ) {
		if ( ! function_exists( 'wc_get_price_to_display' ) ) {
			return (float) $entry;
		}
		$args = array( 'price' => $entry, 'qty' => 1 );
		if ( '' !== $display ) {
			return 'excl' === $display ? wc_get_price_excluding_tax( $product, $args ) : wc_get_price_including_tax( $product, $args );
		}
		return wc_get_price_to_display( $product, $args );
	}

	/** Effective price, tier label and – if lower – the regular price after "statt". */
	public static function tier_html( $price, $regular, $show_regular, $label ) {
		$current = self::money( $price );
		$out     = '<span class="wpcap-price">' . ( $show_regular ? '<ins class="wpcap-price__current">' . $current . '</ins>' : $current );
		if ( '' !== (string) $label ) {
			$out .= ' <span class="wpcap-price__tier">' . esc_html( $label ) . '</span>';
		}
		if ( $show_regular ) {
			/* translators: %s: regular price HTML */
			$out .= ' <span class="wpcap-price__regular">' . sprintf( esc_html__( 'statt %s', 'wp-pass-captain' ), '<del>' . self::money( $regular ) . '</del>' ) . '</span>';
		}
		return $out . '</span>';
	}

	private static function money( $amount ) {
		return function_exists( 'wc_price' ) ? wc_price( $amount ) : esc_html( number_format( (float) $amount, 2, ',', '.' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Request detection                                                   */
	/* ------------------------------------------------------------------ */

	private static function is_store_api_request() {
		return function_exists( 'WC' ) && is_callable( array( WC(), 'is_store_api_request' ) ) && WC()->is_store_api_request();
	}

	/** Whether this is the block checkout's "place order" request (POST …/wc/store/…/checkout). */
	private static function is_checkout_submission() {
		$method = strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'POST' !== $method ) {
			return false;
		}
		$uri = rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return (bool) preg_match( '#/wc/store(?:/v\d+)?/checkout(?:[/?&]|$)#', $uri );
	}

	/** Resets the per-request caches (tests, long-running processes). */
	public static function flush() {
		self::$quotes  = array();
		self::$changes = array();
	}
}
