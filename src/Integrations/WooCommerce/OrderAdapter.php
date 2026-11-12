<?php
/**
 * Optional WooCommerce adapter.
 *
 * Uses WooCommerce order/product CRUD only (HPOS and legacy storage compatible) and never reads
 * or writes WooCommerce tables directly. Pass Captain keeps inventory and ticket authority:
 *
 * 1. Cart/checkout: availability, sales window and per-order limit are validated (classic + blocks).
 * 2. Order created (before payment): a Pass Captain order with an inventory hold is created.
 *    If the hold cannot be placed, the checkout is aborted – no payment is taken.
 * 3. Payment confirmed (processing/completed/payment_complete): hold is converted and exactly one
 *    ticket per unit is issued (idempotent). Late payments re-check capacity; if none is left the
 *    order is flagged for review instead of overbooking.
 *    Gateways that collect money on delivery (cash on delivery, filter
 *    `wpcap_woocommerce_issue_on_completed_gateways`) issue only at "completed".
 * 4. on-hold (e.g. bank transfer): hold is extended.
 * 5. cancelled/failed: hold released, valid tickets revoked.
 * 6. Refunds: every WooCommerce refund is recorded in the Pass Captain refund ledger (refund row,
 *    refunded amount, credit note) when the core supports external refunds; refunded ticket
 *    quantities revoke tickets; a full refund revokes all valid tickets.
 * 7. Participant fields: per-ticket attendee data is collected in the checkout (see Attendees).
 * 8. Optional (setting, default off): cart prices from Orders::unit_price(), e.g. series price
 *    tiers (see CartPricing). The hold still uses the WooCommerce line totals as trusted override.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderAdapter {
	const PRODUCT_META  = '_wpcap_ticket_type_id';
	const ORDER_META    = '_wpcap_core_order_id';
	const ATTEMPT_META  = '_wpcap_checkout_attempt';
	const REFUND_META   = '_wpcap_refund_processed';
	const ITEM_META     = '_wpcap_ticket_type_id';
	const ATTENDEE_META = '_wpcap_attendees';
	const DEFER_NOTE    = '_wpcap_deferred_note';

	/** Declares HPOS and Cart/Checkout Blocks compatibility. */
	public static function declare_compatibility() {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPCAP_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPCAP_FILE, true );
	}

	/** Registers hooks when WooCommerce is active. */
	public static function register() {
		if ( ! class_exists( '\\WooCommerce' ) ) {
			return;
		}
		// Product configuration.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );

		// Cart and checkout validation (classic + Store API/blocks).
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 4 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'validate_cart_update' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_cart_errors' ), 10, 2 );
		// Store API quantity limits (cart block quantity selector and update-item requests).
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( __CLASS__, 'store_api_quantity_maximum' ), 10, 3 );
		add_filter( 'woocommerce_store_api_cart_item_quantity_validation', array( __CLASS__, 'store_api_validate_quantity' ), 10, 4 );

		// Per-ticket participant fields (classic and block checkout).
		Attendees::register();

		// Optional: Pass Captain prices (e.g. series price tiers) in the cart.
		CartPricing::register();

		// Inventory hold before payment.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'reserve_classic' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reserve_store_api' ) );

		// Payment and lifecycle.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'sync_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'sync_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'sync_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'sync_on_hold_order' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'sync_cancelled_order' ), 20 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'sync_cancelled_order' ), 20 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync_refund' ), 20, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_fully_refunded_order' ), 20 );

		// Display and admin tools.
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer_tickets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_wpcap_resend_tickets', array( __CLASS__, 'action_resend_tickets' ) );
		add_action( 'woocommerce_order_action_wpcap_retry_tickets', array( __CLASS__, 'action_retry_tickets' ) );
		add_action( 'wpcap_occurrence_cancelled_woocommerce_order', array( __CLASS__, 'note_cancelled_occurrence' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Product configuration                                               */
	/* ------------------------------------------------------------------ */

	/** Renders the ticket category selector in the product "General" tab. */
	public static function product_field() {
		global $wpdb, $product_object;
		$current = ( $product_object && is_callable( array( $product_object, 'get_meta' ) ) ) ? absint( $product_object->get_meta( self::PRODUCT_META, true ) ) : 0;
		$p       = $wpdb->prefix;
		$rows    = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.name,tt.status,tt.capacity,o.starts_at,o.timezone,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id ORDER BY o.starts_at DESC, tt.ticket_type_id ASC LIMIT 500" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$options = array( '' => __( '— Kein Ticketprodukt —', 'wp-pass-captain' ) );
		foreach ( $rows as $row ) {
			$label = sprintf( '%1$s · %2$s · %3$s', $row->event_name, mysql2date( 'd.m.Y H:i', get_date_from_gmt( $row->starts_at ), true ), $row->name );
			if ( 'published' !== $row->event_status || 'active' !== $row->status ) {
				$label .= ' (' . __( 'nicht im Verkauf', 'wp-pass-captain' ) . ')';
			}
			$options[ (string) $row->ticket_type_id ] = $label;
		}
		if ( $current && ! isset( $options[ (string) $current ] ) ) {
			/* translators: %d: ticket type ID */
			$options[ (string) $current ] = sprintf( __( 'Unbekannte Ticketkategorie #%d', 'wp-pass-captain' ), $current );
		}
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => __( 'Pass Captain Ticketkategorie', 'wp-pass-captain' ),
				'description' => CartPricing::enabled()
					? __( 'Verkauft dieses Produkt Tickets der gewählten Kategorie. Kontingent, Verkaufsfenster, Höchstmenge und – laut WooCommerce-Einstellung – der Preis inklusive Preisstufen kommen aus Pass Captain. Lagerverwaltung von WooCommerce für dieses Produkt deaktiviert lassen.', 'wp-pass-captain' )
					: __( 'Verkauft dieses Produkt Tickets der gewählten Kategorie. Kontingent, Verkaufsfenster und Höchstmenge kommen aus Pass Captain; der Preis kommt aus WooCommerce. Lagerverwaltung von WooCommerce für dieses Produkt deaktiviert lassen.', 'wp-pass-captain' ),
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => $current ? (string) $current : '',
			)
		);
		echo '</div>';
	}

	/** Saves the ticket category selection. */
	public static function save_product_field( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
			return;
		}
		// Nonce is verified by WooCommerce before woocommerce_admin_process_product_object fires.
		$type_id = absint( wp_unslash( $_POST[ self::PRODUCT_META ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $type_id && self::ticket_type( $type_id ) ) {
			$product->update_meta_data( self::PRODUCT_META, $type_id );
			// Ticket products are virtual and sold individually per line quantity.
			$product->set_virtual( true );
		} else {
			$product->delete_meta_data( self::PRODUCT_META );
		}
	}

	/** Returns the linked ticket type ID of a product (variations inherit from the parent). */
	public static function product_ticket_type_id( $product ) {
		if ( ! $product ) {
			return 0;
		}
		$type_id = absint( $product->get_meta( self::PRODUCT_META, true ) );
		if ( ! $type_id && $product->get_parent_id() ) {
			$parent  = wc_get_product( $product->get_parent_id() );
			$type_id = $parent ? absint( $parent->get_meta( self::PRODUCT_META, true ) ) : 0;
		}
		return $type_id;
	}

	/* ------------------------------------------------------------------ */
	/* Cart validation                                                     */
	/* ------------------------------------------------------------------ */

	/** Validates adding a ticket product to the cart. */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed;
		}
		$variation_id = absint( $variation_id ? $variation_id : ( $_REQUEST['variation_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$product      = wc_get_product( $variation_id ? $variation_id : $product_id );
		$type_id      = self::product_ticket_type_id( $product );
		if ( ! $type_id ) {
			return $passed;
		}
		$quantities             = self::cart_quantities();
		$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + absint( $quantity );
		$error                  = self::quantity_error( $type_id, $quantities[ $type_id ] );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return $passed;
	}

	/** Validates quantity changes on the classic cart page. */
	public static function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed || empty( $values['data'] ) ) {
			return $passed;
		}
		$type_id = self::product_ticket_type_id( $values['data'] );
		if ( ! $type_id ) {
			return $passed;
		}
		$quantities             = self::cart_quantities( $cart_item_key );
		$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + absint( $quantity );
		$error                  = self::quantity_error( $type_id, $quantities[ $type_id ] );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Store API: caps the quantity selector and update-item requests at what can still be bought
	 * (per-order maximum and remaining capacity, minus the same ticket type in other cart lines).
	 */
	public static function store_api_quantity_maximum( $value, $product, $cart_item = null ) {
		$type_id = self::product_ticket_type_id( $product );
		if ( ! $type_id ) {
			return $value;
		}
		$limit = self::quantity_limit( $type_id, self::other_cart_quantity( $type_id, $cart_item ) );
		return null === $limit ? $value : min( (int) $value > 0 ? (int) $value : PHP_INT_MAX, $limit );
	}

	/** Store API update-item (WooCommerce 11.2+): rejects quantities with the Pass Captain message. */
	public static function store_api_validate_quantity( $valid, $quantity, $product, $cart_item = null ) {
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$type_id = self::product_ticket_type_id( $product );
		if ( ! $type_id ) {
			return $valid;
		}
		$error = self::quantity_error( $type_id, self::other_cart_quantity( $type_id, $cart_item ) + absint( $quantity ) );
		return $error ? new \WP_Error( 'wpcap_ticket_quantity', $error ) : $valid;
	}

	/** Quantity of a ticket type in all cart lines except $cart_item (by key). */
	private static function other_cart_quantity( $type_id, $cart_item = null ) {
		$skip = is_array( $cart_item ) ? (string) ( $cart_item['key'] ?? '' ) : '';
		if ( '' === $skip ) {
			// Add-to-cart form (no cart line yet): everything already in the cart counts.
			return self::cart_quantities()[ $type_id ] ?? 0;
		}
		return self::cart_quantities( $skip )[ $type_id ] ?? 0;
	}

	/**
	 * Highest quantity of a ticket type a single cart line may hold, given $others already in the cart.
	 * Null if the type is not limited beyond WooCommerce's own limits.
	 */
	public static function quantity_limit( $type_id, $others = 0 ) {
		$type = self::ticket_type( $type_id );
		if ( ! $type ) {
			return 0;
		}
		$limits    = array( (int) $type->max_per_order - $others );
		$available = Orders::availability( $type_id );
		if ( null !== $available ) {
			$limits[] = $available - $others;
		}
		$seats = self::free_seat_count( $type );
		if ( null !== $seats ) {
			$limits[] = $seats - $others;
		}
		return max( 0, min( $limits ) );
	}

	/** Classic cart/checkout validation. Store API requests use store_api_cart_errors instead. */
	public static function check_cart_items() {
		if ( self::is_store_api_request() ) {
			return;
		}
		foreach ( self::cart_errors() as $error ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/** Cart/Checkout Blocks validation. */
	public static function store_api_cart_errors( $errors, $cart = null ) {
		foreach ( self::cart_errors() as $index => $error ) {
			$errors->add( 'wpcap_ticket_unavailable_' . $index, $error );
		}
	}

	/** Returns validation errors for all ticket lines in the cart. */
	private static function cart_errors() {
		$errors = array();
		foreach ( self::cart_quantities() as $type_id => $quantity ) {
			$error = self::quantity_error( $type_id, $quantity );
			if ( $error ) {
				$errors[] = $error;
			}
		}
		return $errors;
	}

	/** Sums ticket quantities per ticket type in the current cart. */
	public static function cart_quantities( $skip_key = '' ) {
		$quantities = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $quantities;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $key === $skip_key || empty( $item['data'] ) ) {
				continue;
			}
			$type_id = self::product_ticket_type_id( $item['data'] );
			if ( $type_id ) {
				$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + absint( $item['quantity'] );
			}
		}
		return $quantities;
	}

	/**
	 * Returns a customer facing error if $quantity units of the ticket type cannot be bought now.
	 * This check is non-locking; the binding check happens when the hold is placed.
	 */
	public static function quantity_error( $type_id, $quantity ) {
		$type = self::ticket_type( $type_id );
		if ( ! $type ) {
			return __( 'Diese Ticketkategorie existiert nicht mehr.', 'wp-pass-captain' );
		}
		$name = sprintf( '%s – %s', $type->event_name, $type->name );
		$now  = current_time( 'mysql', true );
		if ( 'published' !== $type->event_status || 'active' !== $type->status || 'scheduled' !== $type->occurrence_status || $type->starts_at <= $now ) {
			/* translators: %s: event and ticket name */
			return sprintf( __( '„%s“ ist nicht mehr buchbar.', 'wp-pass-captain' ), $name );
		}
		if ( ( $type->sales_start && $type->sales_start > $now ) || ( $type->sales_end && $type->sales_end < $now ) ) {
			/* translators: %s: event and ticket name */
			return sprintf( __( 'Der Verkauf für „%s“ ist derzeit geschlossen.', 'wp-pass-captain' ), $name );
		}
		if ( $quantity > (int) $type->max_per_order ) {
			/* translators: 1: max quantity, 2: event and ticket name */
			return sprintf( __( 'Pro Bestellung sind höchstens %1$d Tickets für „%2$s“ möglich.', 'wp-pass-captain' ), (int) $type->max_per_order, $name );
		}
		$available = Orders::availability( $type_id );
		if ( null !== $available && $quantity > $available ) {
			return 0 === $available
				/* translators: %s: event and ticket name */
				? sprintf( __( '„%s“ ist ausverkauft.', 'wp-pass-captain' ), $name )
				/* translators: 1: available quantity, 2: event and ticket name */
				: sprintf( __( 'Für „%2$s“ sind nur noch %1$d Tickets verfügbar.', 'wp-pass-captain' ), $available, $name );
		}
		$seats = self::free_seat_count( $type );
		if ( null !== $seats && $quantity > $seats ) {
			return 0 === $seats
				/* translators: %s: event and ticket name */
				? sprintf( __( 'Für „%s“ sind keine passenden Sitzplätze mehr frei.', 'wp-pass-captain' ), $name )
				/* translators: 1: free seats, 2: event and ticket name */
				: sprintf( __( 'Für „%2$s“ sind nur noch %1$d passende Sitzplätze frei.', 'wp-pass-captain' ), $seats, $name );
		}
		return '';
	}

	/**
	 * Free seats for a ticket type that is mapped to a seat map (Seating module), or null if the type
	 * needs no seat. WooCommerce cannot show the seat picker: the core assigns the best available
	 * seats of the type's zones when the hold is placed, so the cart only has to check that enough
	 * matching seats are free (non-locking, like the capacity check).
	 */
	private static function free_seat_count( $type ) {
		if ( ! class_exists( '\\PassCaptain\\Seating\\Layouts' ) || ! class_exists( '\\PassCaptain\\Seating\\Reservations' ) ) {
			return null;
		}
		$occurrence_id = (int) $type->occurrence_id;
		if ( ! \PassCaptain\Seating\Layouts::link( $occurrence_id ) ) {
			return null;
		}
		$zones = \PassCaptain\Seating\Layouts::type_zones( $occurrence_id )[ (int) $type->ticket_type_id ] ?? array();
		if ( ! $zones ) {
			return null;
		}
		$map = \PassCaptain\Seating\Reservations::availability( $occurrence_id );
		if ( ! is_array( $map ) ) {
			return 0;
		}
		$free = 0;
		foreach ( (array) ( $map['seats'] ?? array() ) as $seat ) {
			if ( 'free' === ( $seat['status'] ?? '' ) && ( in_array( (string) ( $seat['zone'] ?? '' ), $zones, true ) || in_array( (string) ( $seat['category'] ?? '' ), $zones, true ) ) ) {
				++$free;
			}
		}
		return $free;
	}

	/* ------------------------------------------------------------------ */
	/* Hold before payment                                                 */
	/* ------------------------------------------------------------------ */

	/** Classic checkout: place the hold; throwing aborts checkout before payment. */
	public static function reserve_classic( $order_id, $posted_data = array(), $order = null ) {
		$order  = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		$result = self::reserve( $order );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/** Checkout block (Store API): place the hold; throwing aborts checkout before payment. */
	public static function reserve_store_api( $order ) {
		$result = self::reserve( $order );
		if ( is_wp_error( $result ) ) {
			if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wpcap_ticket_unavailable', esc_html( $result->get_error_message() ), 409 );
			}
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Creates the Pass Captain order with an inventory hold for a WooCommerce order.
	 *
	 * WooCommerce re-uses a pending order when a customer retries checkout. Each attempt therefore
	 * gets its own idempotency key and the previous unpaid Pass Captain order is cancelled first.
	 *
	 * @return int|\WP_Error|null Core order ID, error, or null if the order has no ticket lines.
	 */
	public static function reserve( $wc_order ) {
		if ( ! $wc_order instanceof \WC_Order ) {
			return null;
		}
		$lines = self::ticket_lines( $wc_order );
		if ( ! $lines ) {
			return null;
		}
		$existing = absint( $wc_order->get_meta( self::ORDER_META, true ) );
		if ( $existing ) {
			$core = Orders::get( $existing );
			if ( $core && 'paid' === $core->payment_status ) {
				return $existing;
			}
			Orders::cancel_order( $existing, 'woocommerce_checkout_retry' );
		}
		$email = sanitize_email( $wc_order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'wpcap_missing_email', __( 'Für Tickets wird eine gültige E-Mail-Adresse benötigt.', 'wp-pass-captain' ) );
		}
		$attempt = absint( $wc_order->get_meta( self::ATTEMPT_META, true ) ) + 1;
		$unit_prices = array_map( static function ( $line ) {
			return $line['unit_minor'];
		}, $lines );
		$line_totals = array_map( static function ( $line ) {
			return $line['gross'];
		}, $lines );
		$result  = Orders::create(
			array(
				'email'           => $email,
				'items'           => array_values( array_map( static function ( $line ) {
					return array( 'ticket_type_id' => $line['ticket_type_id'], 'quantity' => $line['quantity'] );
				}, $lines ) ),
				'idempotency_key' => $wc_order->get_id() . '-' . $attempt,
				'attendees'       => Attendees::for_order( $wc_order ),
			),
			array(
				'external'      => true,
				'currency'      => $wc_order->get_currency(),
				'hold_minutes'  => self::hold_minutes(),
				'source'        => 'woocommerce',
				'payment_provider' => 'woocommerce-' . sanitize_key( $wc_order->get_payment_method() ?: 'unknown' ),
				'key_namespace' => 'woocommerce',
				'unit_prices'   => $unit_prices,
				'line_totals'   => $line_totals,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$wc_order->update_meta_data( self::ATTEMPT_META, $attempt );
		$wc_order->update_meta_data( self::ORDER_META, absint( $result['order_id'] ) );
		$wc_order->save();
		/* translators: %d: hold minutes */
		$wc_order->add_order_note( sprintf( __( 'Pass Captain: Tickets für %d Minuten reserviert.', 'wp-pass-captain' ), (int) $result['hold_minutes'] ) );
		return absint( $result['order_id'] );
	}

	/** Hold duration for WooCommerce orders (at least the WooCommerce "hold stock" setting). */
	private static function hold_minutes() {
		$minutes = max( Orders::HOLD_MINUTES, absint( get_option( 'woocommerce_hold_stock_minutes', 60 ) ) );
		return (int) apply_filters( 'wpcap_woocommerce_hold_minutes', $minutes );
	}

	/**
	 * Aggregates ticket lines of a WooCommerce order by ticket type.
	 *
	 * @return array<int, array{ticket_type_id:int, quantity:int, unit_minor:int}>
	 */
	private static function ticket_lines( \WC_Order $wc_order ) {
		// Minor units follow the currency, not the shop's display decimals setting.
		$factor   = 10 ** self::currency_decimals( $wc_order->get_currency() );
		$lines    = array();
		foreach ( $wc_order->get_items( 'line_item' ) as $item ) {
			$type_id = self::item_ticket_type_id( $item );
			$qty     = absint( $item->get_quantity() );
			if ( ! $type_id || ! $qty ) {
				continue;
			}
			// WooCommerce stores amounts as decimal strings; convert once at the adapter boundary.
			$gross = (int) round( ( (float) $item->get_total() + (float) $item->get_total_tax() ) * $factor );
			if ( ! isset( $lines[ $type_id ] ) ) {
				$lines[ $type_id ] = array( 'ticket_type_id' => $type_id, 'quantity' => 0, 'gross' => 0 );
			}
			$lines[ $type_id ]['quantity'] += $qty;
			$lines[ $type_id ]['gross']    += max( 0, $gross );
		}
		foreach ( $lines as $type_id => $line ) {
			$lines[ $type_id ]['unit_minor'] = intdiv( $line['gross'], max( 1, $line['quantity'] ) );
		}
		return $lines;
	}

	/** ISO 4217 minor unit digits for the currencies WooCommerce commonly uses. */
	public static function currency_decimals( $currency ) {
		$zero  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$three = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );
		$currency = strtoupper( (string) $currency );
		return in_array( $currency, $zero, true ) ? 0 : ( in_array( $currency, $three, true ) ? 3 : 2 );
	}

	/** Ticket quantities per ticket type of a WooCommerce order. */
	public static function order_quantities( $wc_order ) {
		$quantities = array();
		foreach ( $wc_order->get_items( 'line_item' ) as $item ) {
			$type_id = self::item_ticket_type_id( $item );
			$qty     = absint( $item->get_quantity() );
			if ( $type_id && $qty ) {
				$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + $qty;
			}
		}
		return $quantities;
	}

	/** Ticket type of an order line; stored on the line so later product changes do not matter. */
	private static function item_ticket_type_id( $item ) {
		$type_id = absint( $item->get_meta( self::ITEM_META, true ) );
		if ( $type_id ) {
			return $type_id;
		}
		$type_id = self::product_ticket_type_id( $item->get_product() );
		if ( $type_id ) {
			$item->update_meta_data( self::ITEM_META, $type_id );
			$item->save();
		}
		return $type_id;
	}

	/* ------------------------------------------------------------------ */
	/* Payment and lifecycle                                               */
	/* ------------------------------------------------------------------ */

	/** Converts the hold and issues tickets once WooCommerce reports a paid order. */
	public static function sync_paid_order( $wc_order_id, $force = false ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( ! $wc_order ) {
			return;
		}
		$core_id = absint( $wc_order->get_meta( self::ORDER_META, true ) );
		if ( ! $core_id ) {
			// Orders created in the admin or before the adapter was active have no hold yet.
			$core_id = self::reserve( $wc_order );
			if ( is_wp_error( $core_id ) ) {
				/* translators: %s: error message */
				$wc_order->add_order_note( sprintf( __( 'Pass Captain: Tickets konnten nicht reserviert werden: %s', 'wp-pass-captain' ), $core_id->get_error_message() ) );
				return;
			}
			if ( ! $core_id ) {
				return;
			}
		}
		$core = Orders::get( $core_id );
		if ( $core && 'paid' === $core->payment_status && Orders::tickets_for_order( $core_id ) ) {
			return;
		}
		if ( self::defers_issuance( $wc_order ) ) {
			// Cash on delivery & co.: "processing" means "to be delivered", not "paid". Keep the hold.
			Orders::extend_hold( $core_id, (int) apply_filters( 'wpcap_woocommerce_deferred_hold_minutes', 14 * DAY_IN_SECONDS / MINUTE_IN_SECONDS, $wc_order ) );
			if ( ! $wc_order->get_meta( self::DEFER_NOTE, true ) ) {
				$wc_order->update_meta_data( self::DEFER_NOTE, 1 );
				$wc_order->save();
				$wc_order->add_order_note( __( 'Pass Captain: Zahlung bei Lieferung/Abholung – die Tickets bleiben reserviert und werden ausgestellt, sobald die Bestellung auf „Abgeschlossen“ gesetzt wird.', 'wp-pass-captain' ) );
			}
			return;
		}
		if ( $core && 'needs_review' === $core->status && ! $force ) {
			// Already flagged and reported; only the explicit "re-issue" order action retries.
			return;
		}
		if ( $core && 'cancelled' === $core->status ) {
			// A failed payment released the hold; the customer has paid on a later attempt.
			$core_id = self::reserve( $wc_order );
			if ( is_wp_error( $core_id ) || ! $core_id ) {
				$message = is_wp_error( $core_id ) ? $core_id->get_error_message() : '';
				/* translators: %s: error message */
				$wc_order->add_order_note( sprintf( __( 'Pass Captain: Zahlung eingegangen, aber Tickets konnten nicht erneut reserviert werden – bitte manuell klären. %s', 'wp-pass-captain' ), $message ) );
				return;
			}
		}
		$had_tickets = (bool) Orders::tickets_for_order( $core_id );
		$result = Orders::confirm_external_payment( $core_id, 'woocommerce:' . $wc_order->get_id() );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: Keine Tickets ausgestellt – %s', 'wp-pass-captain' ), $result->get_error_message() ) );
			return;
		}
		$count = count( Orders::tickets_for_order( $core_id ) );
		if ( ! $count ) {
			$wc_order->add_order_note( __( 'Pass Captain: Zahlung übernommen, Tickets warten noch auf die Fiskalisierung. Fiskalstatus prüfen oder den Fiskal-Retry auslösen.', 'wp-pass-captain' ) );
			return;
		}
		/* translators: %d: ticket count */
		$wc_order->add_order_note( sprintf( _n( 'Pass Captain: %d Ticket ausgestellt und per E-Mail versendet.', 'Pass Captain: %d Tickets ausgestellt und per E-Mail versendet.', $count, 'wp-pass-captain' ), $count ) );
		if ( ! $had_tickets && $count ) {
			// Personal link for ticket transfer/self-service, sent once with the first issuance.
			Orders::rotate_access_link( $core_id );
		}
		if ( $count && $wc_order->get_meta( self::ATTENDEE_META, true ) ) {
			// Participant data now lives on the tickets; do not keep a second copy on the WooCommerce order.
			$wc_order->delete_meta_data( self::ATTENDEE_META );
			$wc_order->save();
		}
	}

	/**
	 * Whether tickets must wait for "completed" because the gateway confirms an order before the
	 * money is collected (cash on delivery by default).
	 */
	public static function defers_issuance( $wc_order ) {
		$method = is_callable( array( $wc_order, 'get_payment_method' ) ) ? (string) $wc_order->get_payment_method() : '';
		if ( '' === $method ) {
			return false;
		}
		/**
		 * Payment gateway IDs whose orders get tickets only when the order is completed.
		 *
		 * @param string[]  $gateways Gateway IDs. Default array( 'cod' ).
		 * @param \WC_Order $wc_order WooCommerce order.
		 */
		$gateways = (array) apply_filters( 'wpcap_woocommerce_issue_on_completed_gateways', array( 'cod' ), $wc_order );
		if ( ! in_array( $method, $gateways, true ) ) {
			return false;
		}
		$status = is_callable( array( $wc_order, 'get_status' ) ) ? (string) $wc_order->get_status() : '';
		return 'completed' !== $status;
	}

	/** Extends the hold while a manual payment (e.g. bank transfer) is outstanding. */
	public static function sync_on_hold_order( $wc_order_id ) {
		$core_id = self::core_order_id( $wc_order_id );
		if ( $core_id ) {
			$minutes = (int) apply_filters( 'wpcap_woocommerce_on_hold_minutes', 3 * DAY_IN_SECONDS / MINUTE_IN_SECONDS );
			Orders::extend_hold( $core_id, $minutes );
		}
	}

	/** Releases the hold and revokes valid tickets for cancelled or failed orders. */
	public static function sync_cancelled_order( $wc_order_id ) {
		$core_id = self::core_order_id( $wc_order_id );
		if ( $core_id ) {
			Orders::cancel_order( $core_id, 'woocommerce_' . current_filter() );
		}
	}

	/**
	 * Mirrors a WooCommerce refund into Pass Captain. Idempotent per refund.
	 *
	 * With core support (Refunds::record_external) the refund goes through the core refund ledger:
	 * wpcap_refunds row, order refunded amount, refunded/revoked tickets, released capacity, credit
	 * note and the wpcap_refund_completed hook. Without it only the refunded tickets are revoked.
	 */
	public static function sync_refund( $wc_order_id, $refund_id ) {
		$core_id = self::core_order_id( $wc_order_id );
		$refund  = wc_get_order( absint( $refund_id ) );
		if ( ! $core_id || ! $refund || $refund->get_meta( self::REFUND_META, true ) ) {
			return;
		}
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( $wc_order && self::can_record_refunds() ) {
			self::record_refund( $wc_order, $refund, $core_id );
			return;
		}
		$revoked = 0;
		$kept    = 0;
		foreach ( self::refund_type_quantities( $refund ) as $type_id => $qty ) {
			$done     = Orders::revoke_tickets( $core_id, $type_id, $qty, 'woocommerce_refund_' . $refund->get_id() );
			$revoked += $done;
			$kept    += $qty - $done;
		}
		$refund->update_meta_data( self::REFUND_META, 1 );
		$refund->save();
		self::note_refund( $wc_order, $revoked, $kept );
	}

	/** Records a WooCommerce refund through the core refund ledger. */
	private static function record_refund( $wc_order, $refund, $core_id ) {
		global $wpdb;
		$plan = self::refund_plan( $wc_order, $refund, $core_id );
		if ( $plan['ticket_ids'] || $plan['amount_minor'] > 0 ) {
			$ids   = implode( ',', array_map( 'absint', $plan['ticket_ids'] ) );
			$valid = $ids ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id IN ($ids) AND status='valid'" ) : 0; // phpcs:ignore WordPress.DB
			$reason = is_callable( array( $refund, 'get_reason' ) ) ? (string) $refund->get_reason() : '';
			$result = call_user_func(
				array( '\\PassCaptain\\Refunds', 'record_external' ),
				$core_id,
				$plan['ticket_ids'],
				$plan['amount_minor'],
				'' !== $reason ? $reason : __( 'Erstattung in WooCommerce', 'wp-pass-captain' ),
				'woocommerce-refund-' . $refund->get_id(),
				array(
					'source'             => 'woocommerce',
					'provider_reference' => 'woocommerce:' . $refund->get_id(),
				)
			);
			if ( is_wp_error( $result ) ) {
				if ( $wc_order ) {
					/* translators: %s: error message */
					$wc_order->add_order_note( sprintf( __( 'Pass Captain: Erstattung konnte nicht übernommen werden – %s', 'wp-pass-captain' ), $result->get_error_message() ) );
				}
				return;
			}
			$requested = $plan['final'] ? count( $plan['ticket_ids'] ) : array_sum( $plan['quantities'] );
			self::note_refund( $wc_order, $valid, max( 0, $requested - $valid ) );
		}
		$refund->update_meta_data( self::REFUND_META, 1 );
		$refund->save();
	}

	/** Whether the core can record refunds executed outside Pass Captain. */
	public static function can_record_refunds() {
		return method_exists( '\\PassCaptain\\Refunds', 'record_external' );
	}

	/**
	 * Works out what a WooCommerce refund means for the Pass Captain order.
	 *
	 * - Refunded ticket quantities select that many not yet refunded tickets of the type (valid
	 *   tickets first, newest first; checked-in tickets are refunded but stay checked in).
	 * - The amount is the refunded gross of the ticket lines. A refund without any line (amount
	 *   only) is attributed to tickets in proportion of the ticket share of the WooCommerce order.
	 * - The refund that leaves the WooCommerce order fully refunded covers all remaining tickets and
	 *   the remaining amount, so the core order ends up exactly refunded.
	 *
	 * @return array{ticket_ids:int[], amount_minor:int, quantities:array<int,int>, final:bool}
	 */
	public static function refund_plan( $wc_order, $refund, $core_id ) {
		global $wpdb;
		$p          = $wpdb->prefix;
		$core       = Orders::get( $core_id );
		$plan       = array( 'ticket_ids' => array(), 'amount_minor' => 0, 'quantities' => array(), 'final' => false );
		if ( ! $core ) {
			return $plan;
		}
		$factor     = 10 ** self::currency_decimals( $wc_order->get_currency() );
		$remaining  = max( 0, (int) $core->total_minor - (int) $core->refunded_minor );
		$quantities = self::refund_type_quantities( $refund );
		$amount     = 0;
		$has_lines  = false;
		foreach ( $refund->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $refund_item ) {
			$has_lines = true;
			if ( ! is_callable( array( $refund_item, 'get_total' ) ) || ! self::refund_item_type_id( $refund_item ) ) {
				continue;
			}
			$amount += (int) round( abs( (float) $refund_item->get_total() + (float) $refund_item->get_total_tax() ) * $factor );
		}
		if ( self::is_final_refund( $wc_order, $refund ) ) {
			$plan['final']        = true;
			$plan['ticket_ids']   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY ticket_id", $core_id ) ) ); // phpcs:ignore WordPress.DB
			$plan['amount_minor'] = $remaining;
			$plan['quantities']   = $quantities;
			return $plan;
		}
		if ( ! $has_lines ) {
			$refunded = is_callable( array( $refund, 'get_amount' ) ) ? (int) round( abs( (float) $refund->get_amount() ) * $factor ) : 0;
			$wc_total = is_callable( array( $wc_order, 'get_total' ) ) ? (int) round( (float) $wc_order->get_total() * $factor ) : 0;
			$amount   = $wc_total > 0 ? (int) round( $refunded * min( 1, (int) $core->total_minor / $wc_total ) ) : 0;
		}
		foreach ( $quantities as $type_id => $qty ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d AND ticket_type_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY (status='valid') DESC, ticket_id DESC LIMIT %d", $core_id, $type_id, $qty ) ); // phpcs:ignore WordPress.DB
			$plan['ticket_ids'] = array_merge( $plan['ticket_ids'], array_map( 'intval', $ids ) );
		}
		$plan['quantities']   = $quantities;
		$plan['amount_minor'] = min( $remaining, $amount );
		return $plan;
	}

	/** Refunded ticket quantities per ticket type of a WooCommerce refund. */
	private static function refund_type_quantities( $refund ) {
		$quantities = array();
		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			$qty     = abs( (int) $refund_item->get_quantity() );
			$type_id = $qty ? self::refund_item_type_id( $refund_item ) : 0;
			if ( $type_id ) {
				$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + $qty;
			}
		}
		return $quantities;
	}

	/** Ticket type of a refund line (from the refunded original line, else from the product). */
	private static function refund_item_type_id( $refund_item ) {
		if ( ! is_callable( array( $refund_item, 'get_product' ) ) ) {
			return 0;
		}
		$original_id = absint( $refund_item->get_meta( '_refunded_item_id', true ) );
		$original    = $original_id ? \WC_Order_Factory::get_order_item( $original_id ) : null;
		return $original ? self::item_ticket_type_id( $original ) : self::product_ticket_type_id( $refund_item->get_product() );
	}

	/** True if $refund is the latest refund and leaves nothing of the WooCommerce order to refund. */
	private static function is_final_refund( $wc_order, $refund ) {
		if ( ! is_callable( array( $wc_order, 'get_total' ) ) || ! is_callable( array( $wc_order, 'get_total_refunded' ) ) ) {
			return false;
		}
		$total = (float) $wc_order->get_total();
		if ( $total <= 0 || $total - (float) $wc_order->get_total_refunded() > 0.0001 ) {
			return false;
		}
		if ( is_callable( array( $wc_order, 'get_refunds' ) ) ) {
			$refunds = $wc_order->get_refunds();
			$latest  = $refunds ? reset( $refunds ) : null;
			return ! $latest || (int) $latest->get_id() === (int) $refund->get_id();
		}
		return true;
	}

	/** Order note about revoked/kept tickets of a refund. */
	private static function note_refund( $wc_order, $revoked, $kept ) {
		if ( ! $wc_order || ( ! $revoked && ! $kept ) ) {
			return;
		}
		/* translators: %d: ticket count */
		$note = sprintf( __( 'Pass Captain: %d Ticket(s) wegen Erstattung widerrufen.', 'wp-pass-captain' ), $revoked );
		if ( $kept ) {
			/* translators: %d: ticket count */
			$note .= ' ' . sprintf( __( '%d erstattete(s) Ticket(s) waren bereits eingecheckt oder nicht mehr gültig und wurden nicht verändert.', 'wp-pass-captain' ), $kept );
		}
		$wc_order->add_order_note( $note );
	}

	/**
	 * Full refund: record outstanding WooCommerce refunds (status "refunded" fires before
	 * woocommerce_order_refunded), then mark the core order refunded and revoke remaining tickets.
	 */
	public static function sync_fully_refunded_order( $wc_order_id ) {
		$core_id = self::core_order_id( $wc_order_id );
		if ( ! $core_id ) {
			return;
		}
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( $wc_order && self::can_record_refunds() && is_callable( array( $wc_order, 'get_refunds' ) ) ) {
			foreach ( array_reverse( (array) $wc_order->get_refunds() ) as $refund ) {
				self::sync_refund( $wc_order_id, $refund->get_id() );
			}
		}
		Orders::mark_refunded( $core_id, 'woocommerce_refunded' );
	}

	/** Adds a note to the WooCommerce order when one of its occurrences was cancelled. */
	public static function note_cancelled_occurrence( $core_order_id, $occurrence_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return; }
		$orders = wc_get_orders( array( 'limit' => 1, 'meta_key' => self::ORDER_META, 'meta_value' => absint( $core_order_id ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $orders ) {
			$orders[0]->add_order_note( __( 'Pass Captain: Ein Termin dieser Bestellung wurde abgesagt; die Tickets sind widerrufen. Bitte die Bestellung in WooCommerce erstatten.', 'wp-pass-captain' ) );
		}
	}

	/** Returns the linked core order ID. */
	private static function core_order_id( $wc_order_id ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		return $wc_order ? absint( $wc_order->get_meta( self::ORDER_META, true ) ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Display and admin                                                   */
	/* ------------------------------------------------------------------ */

	/** Lists tickets on the thank-you page and in "My Account → Orders". */
	public static function render_customer_tickets( $wc_order ) {
		$core_id = $wc_order instanceof \WC_Order ? absint( $wc_order->get_meta( self::ORDER_META, true ) ) : 0;
		if ( ! $core_id ) {
			return;
		}
		$tickets = Orders::tickets_for_order( $core_id );
		echo '<section class="wpcap-woo-tickets"><h2>' . esc_html__( 'Deine Tickets', 'wp-pass-captain' ) . '</h2>';
		if ( ! $tickets ) {
			echo '<p>' . esc_html__( 'Deine Tickets werden nach bestätigter Zahlung per E-Mail zugestellt.', 'wp-pass-captain' ) . '</p></section>';
			return;
		}
		echo '<p>' . esc_html__( 'Die Ticket-Links mit QR-Code haben wir dir per E-Mail gesendet.', 'wp-pass-captain' ) . '</p>';
		self::render_ticket_table( $tickets );
		echo '</section>';
	}

	/** Adds the Pass Captain meta box to the order edit screen (HPOS and legacy). */
	public static function add_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'wpcap-woo-tickets', __( 'Pass Captain Tickets', 'wp-pass-captain' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side' );
		}
	}

	/** Renders the admin meta box. */
	public static function render_meta_box( $post_or_order ) {
		$wc_order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		$core_id  = $wc_order ? absint( $wc_order->get_meta( self::ORDER_META, true ) ) : 0;
		if ( ! $core_id ) {
			echo '<p>' . esc_html__( 'Keine Tickets in dieser Bestellung.', 'wp-pass-captain' ) . '</p>';
			return;
		}
		$core = Orders::get( $core_id );
		if ( $core ) {
			/* translators: 1: order ID, 2: status, 3: payment status */
			echo '<p>' . esc_html( sprintf( __( 'Pass Captain Bestellung #%1$d: %2$s / %3$s', 'wp-pass-captain' ), $core_id, $core->status, $core->payment_status ) ) . '</p>';
			if ( 'needs_review' === $core->status ) {
				echo '<p><strong>' . esc_html__( 'Zahlung eingegangen, aber kein Kontingent mehr frei. Bitte Platz freigeben und „Tickets erneut ausstellen“ wählen oder erstatten.', 'wp-pass-captain' ) . '</strong></p>';
			}
		}
		$tickets = Orders::tickets_for_order( $core_id );
		if ( $tickets ) {
			self::render_ticket_table( $tickets );
		}
	}

	/** Adds order actions. */
	public static function order_actions( $actions, $wc_order = null ) {
		if ( $wc_order instanceof \WC_Order && $wc_order->get_meta( self::ORDER_META, true ) && current_user_can( 'wpcap_manage_orders' ) ) {
			$actions['wpcap_resend_tickets'] = __( 'Pass Captain: Tickets erneut senden (neue Codes)', 'wp-pass-captain' );
			$actions['wpcap_retry_tickets']  = __( 'Pass Captain: Tickets erneut ausstellen', 'wp-pass-captain' );
		}
		return $actions;
	}

	/** Order action: rotate codes and resend tickets. */
	public static function action_resend_tickets( $wc_order ) {
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) {
			return;
		}
		$result = Orders::resend_tickets( absint( $wc_order->get_meta( self::ORDER_META, true ) ) );
		$wc_order->add_order_note( is_wp_error( $result ) ? sprintf( /* translators: %s: error */ __( 'Pass Captain: Erneuter Versand fehlgeschlagen – %s', 'wp-pass-captain' ), $result->get_error_message() ) : __( 'Pass Captain: Tickets mit neuen Codes erneut gesendet.', 'wp-pass-captain' ) );
	}

	/** Order action: retry issuance (e.g. after resolving a needs_review order). */
	public static function action_retry_tickets( $wc_order ) {
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) {
			return;
		}
		if ( self::defers_issuance( $wc_order ) ) {
			$wc_order->add_order_note( __( 'Pass Captain: Bei dieser Zahlungsart werden die Tickets erst mit dem Status „Abgeschlossen“ ausgestellt.', 'wp-pass-captain' ) );
		} elseif ( $wc_order->is_paid() ) {
			self::sync_paid_order( $wc_order->get_id(), true );
		} else {
			$wc_order->add_order_note( __( 'Pass Captain: Die Bestellung ist noch nicht bezahlt.', 'wp-pass-captain' ) );
		}
	}

	/** Renders a ticket table (no codes – tokens are only stored as hashes). */
	private static function render_ticket_table( array $tickets ) {
		$labels = array(
			'valid'      => __( 'gültig', 'wp-pass-captain' ),
			'checked_in' => __( 'eingecheckt', 'wp-pass-captain' ),
			'revoked'    => __( 'widerrufen', 'wp-pass-captain' ),
		);
		echo '<table class="shop_table wpcap-ticket-table"><thead><tr><th>' . esc_html__( 'Event', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Ticket', 'wp-pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $tickets as $ticket ) {
			$when = mysql2date( 'd.m.Y H:i', get_date_from_gmt( $ticket->starts_at ), true );
			echo '<tr><td>' . esc_html( $ticket->event_name . ' · ' . $when ) . '</td><td>' . esc_html( $ticket->ticket_name ) . '</td><td>' . esc_html( $labels[ $ticket->status ] ?? $ticket->status ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Loads a ticket type with event/occurrence context. */
	public static function ticket_type( $type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.*,o.starts_at,o.status AS occurrence_status,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d", absint( $type_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Detects Store API (blocks) requests. */
	private static function is_store_api_request() {
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'is_store_api_request' ) ) ) {
			return WC()->is_store_api_request();
		}
		return false;
	}
}
