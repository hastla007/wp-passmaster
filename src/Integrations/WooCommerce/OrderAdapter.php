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
 * 9. Tickets of different invoice issuers (sellers): rejected in cart/checkout by default, or split into
 *    one Pass Captain order per seller (see SellerSplit for the policy and the allocation rules).
 *    Refunds are mapped to the seller orders and their tickets.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Billing;
use PassCaptain\Orders;
use PassCaptain\Partners\SalesModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderAdapter {
	const PRODUCT_META  = '_wpcap_ticket_type_id';
	const ORDER_META    = '_wpcap_core_order_id';
	const ATTEMPT_META  = '_wpcap_checkout_attempt';
	const REFUND_META   = '_wpcap_refund_processed';
	const ITEM_META     = '_wpcap_ticket_type_id';
	const FISCAL_SELLER_META = '_wpcap_fiscal_seller_id';
	const FISCAL_MODE_META = '_wpcap_fiscal_seller_mode';
	const ATTENDEE_META = '_wpcap_attendees';
	const DEFER_NOTE    = '_wpcap_deferred_note';
	/** Option: WooCommerce refunds with quantities may refund checked-in tickets ('yes'/'no', default 'no'). */
	const CHECKED_IN_OPTION = 'wpcap_woocommerce_refund_checked_in';

	/** Declares HPOS and Cart/Checkout Blocks compatibility. */
	public static function declare_compatibility() {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPCAP_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPCAP_FILE, true );
		// The ticket category field is a classic product data field (woocommerce_product_options_general_product_data);
		// the new product block editor does not show it.
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', WPCAP_FILE, false );
	}

	/** Job that retries mirroring a WooCommerce refund after a database error. */
	const REFUND_SYNC_JOB = 'woocommerce_refund_sync';

	/** Displays submitted seller-specific fiscal receipts on the customer order-details page. */
	public static function render_fiscal_receipts( $wc_order ) {
		if ( ! is_object( $wc_order ) || ! is_callable( array( $wc_order, 'get_meta' ) ) ) { return; }
		foreach ( self::core_order_ids( $wc_order ) as $core_id ) {
			echo \PassCaptain\Fiscalization\ReceiptRenderer::render( $core_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all provider-controlled values.
		}
	}

	/** Registers hooks when WooCommerce is active. */
	public static function register() {
		if ( ! class_exists( '\\WooCommerce' ) ) {
			return;
		}
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
		// Product configuration.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );
		// Same rule for every other way of saving a product (WooCommerce REST API, quick edit, CSV import).
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'guard_product_link' ), 10, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( __CLASS__, 'guard_product_link' ), 10, 1 );

		// Cart and checkout validation (classic + Store API/blocks).
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 4 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'validate_cart_update' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_cart_errors' ), 10, 2 );
		// Mixed invoice issuers (sellers): Store API add-to-cart; settings for the policy.
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'store_api_validate_add_to_cart' ) );
		add_filter( 'woocommerce_get_settings_products', array( SellerSplit::class, 'settings' ), 20, 2 );
		// Store API quantity limits (cart block quantity selector and update-item requests).
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( __CLASS__, 'store_api_quantity_maximum' ), 10, 3 );
		add_filter( 'woocommerce_store_api_cart_item_quantity_validation', array( __CLASS__, 'store_api_validate_quantity' ), 10, 4 );

		// WooCommerce Subscriptions & co.: never copy Pass Captain's order meta to renewals/subscriptions.
		foreach ( array( 'wc_subscriptions_renewal_order_data', 'wc_subscriptions_resubscribe_order_data', 'wc_subscriptions_subscription_data', 'wcs_renewal_order_meta', 'wcs_resubscribe_order_meta', 'wcs_subscription_meta', 'wcs_renewal_order_meta_query', 'wcs_resubscribe_order_meta_query', 'wcs_subscription_meta_query' ) as $copy_filter ) {
			add_filter( $copy_filter, array( __CLASS__, 'exclude_copied_meta' ), 20, 1 );
		}

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
		// Trash/delete: an unpaid order releases its hold, restoring it from the trash reserves again (HPOS
		// and posts storage; posts hooks only for the shop_order post type, every handler is idempotent).
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'sync_trashed_order' ), 20, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'sync_deleted_order' ), 20, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'sync_untrashed_order' ), 20, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'sync_trashed_post' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'sync_deleted_post' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'sync_untrashed_post' ), 20, 1 );
		// Refunds of checked-in tickets are refused before WooCommerce pays them out (unless allowed).
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'guard_refund' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_fully_refunded_order' ), 20 );

		// Display and admin tools.
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer_tickets' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_fiscal_receipts' ), 20 );
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
		$fiscal_seller = ( $product_object && is_callable( array( $product_object, 'get_meta' ) ) ) ? absint( $product_object->get_meta( self::FISCAL_SELLER_META, true ) ) : 0;
		$fiscal_mode = ( $product_object && is_callable( array( $product_object, 'get_meta' ) ) ) ? sanitize_key( $product_object->get_meta( self::FISCAL_MODE_META, true ) ) : 'platform_seller';
		$p       = $wpdb->prefix;
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT tt.ticket_type_id,tt.name,tt.status,tt.capacity,o.starts_at,o.timezone,e.event_id,e.name AS event_name,e.status AS event_status FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id ORDER BY o.starts_at DESC, tt.ticket_type_id ASC LIMIT 500', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$options = array( '' => __( '— Kein Ticketprodukt —', 'pass-captain' ) );
		$allowed = array();
		foreach ( $rows as $row ) {
			// SEC-V2: only ticket types of events the user manages (shop managers have no event scope of their own).
			$allowed[ (int) $row->event_id ] = $allowed[ (int) $row->event_id ] ?? self::can_link_event( (int) $row->event_id );
			if ( ! $allowed[ (int) $row->event_id ] ) { continue; }
			$label = sprintf( '%1$s · %2$s · %3$s', $row->event_name, mysql2date( 'd.m.Y H:i', get_date_from_gmt( $row->starts_at ), true ), $row->name );
			if ( 'published' !== $row->event_status || 'active' !== $row->status ) {
				$label .= ' (' . __( 'nicht im Verkauf', 'pass-captain' ) . ')';
			}
			$options[ (string) $row->ticket_type_id ] = $label;
		}
		$locked = $current && ! self::can_link_ticket_type( $current );
		if ( $locked ) {
			// Linked to an event the user does not manage: shown, but not changeable (save_product_field keeps it).
			$type = self::ticket_type( $current );
			$options = array( (string) $current => sprintf( '%1$s · %2$s (%3$s)', $type ? $type->event_name : '#' . $current, $type ? $type->name : '', __( 'keine Berechtigung für diese Veranstaltung – nicht änderbar', 'pass-captain' ) ) );
		} elseif ( $current && ! isset( $options[ (string) $current ] ) ) {
			/* translators: %d: ticket type ID */
			$options[ (string) $current ] = sprintf( __( 'Unbekannte Ticketkategorie #%d', 'pass-captain' ), $current );
		}
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => __( 'Pass Captain Ticketkategorie', 'pass-captain' ),
				'description' => CartPricing::enabled()
					? __( 'Verkauft dieses Produkt Tickets der gewählten Kategorie. Kontingent, Verkaufsfenster, Höchstmenge und – laut WooCommerce-Einstellung – der Preis inklusive Preisstufen kommen aus Pass Captain. Lagerverwaltung von WooCommerce für dieses Produkt deaktiviert lassen.', 'pass-captain' )
					: __( 'Verkauft dieses Produkt Tickets der gewählten Kategorie. Kontingent, Verkaufsfenster und Höchstmenge kommen aus Pass Captain; der Preis kommt aus WooCommerce. Lagerverwaltung von WooCommerce für dieses Produkt deaktiviert lassen.', 'pass-captain' ),
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => $current ? (string) $current : '',
				'custom_attributes' => $locked ? array( 'disabled' => 'disabled' ) : array(),
			)
		);
		$sellers = $wpdb->get_results( $wpdb->prepare( "SELECT seller_id,legal_name,country_code FROM %i WHERE status='active' ORDER BY legal_name", $p . 'wpcap_legal_sellers' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$seller_options = array( '' => __( '— Verkäufer für normales Produkt wählen —', 'pass-captain' ) );
		foreach ( $sellers as $seller ) { $seller_options[ (string) $seller->seller_id ] = $seller->legal_name . ' (' . $seller->country_code . ')'; }
		woocommerce_wp_select( array( 'id' => self::FISCAL_SELLER_META, 'label' => __( 'Fiskalischer Verkäufer (normales WooCommerce-Produkt)', 'pass-captain' ), 'description' => __( 'Für gemischte Warenkörbe. Ticketprodukte verwenden weiterhin die Verkäuferzuordnung der Pass-Captain-Ticketart.', 'pass-captain' ), 'desc_tip' => true, 'options' => $seller_options, 'value' => $fiscal_seller ? (string) $fiscal_seller : '' ) );
		woocommerce_wp_select( array( 'id' => self::FISCAL_MODE_META, 'label' => __( 'Verkäuferrolle', 'pass-captain' ), 'options' => array( 'platform_seller' => __( 'Betreibergesellschaft ist Verkäufer', 'pass-captain' ), 'organizer_seller' => __( 'Veranstalter ist Verkäufer', 'pass-captain' ) ), 'value' => in_array( $fiscal_mode, array( 'platform_seller', 'organizer_seller' ), true ) ? $fiscal_mode : 'platform_seller' ) );
		echo '</div>';
	}

	/** Saves the ticket category selection and seller assignment for ordinary WooCommerce products. */
	public static function save_product_field( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
			return;
		}
		// Nonce is verified by WooCommerce before woocommerce_admin_process_product_object fires.
		$posted = isset( $_POST[ self::PRODUCT_META ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = absint( wp_unslash( $_POST[ self::PRODUCT_META ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current = absint( $product->get_meta( self::PRODUCT_META, true ) );
		if ( $type_id !== $current && ( ( $current && ! self::can_link_ticket_type( $current ) ) || ( $type_id && ! self::can_link_ticket_type( $type_id ) ) ) ) {
			// SEC-V2: linking or unlinking a ticket type of an event the user does not manage is refused;
			// the product keeps its current link and seller fields (disabled field: nothing posted, no notice).
			if ( $posted ) {
				self::admin_error( __( 'Die Ticketkategorie wurde nicht geändert: Du darfst nur Ticketkategorien von Veranstaltungen verknüpfen, die du verwaltest.', 'pass-captain' ) );
			}
			return;
		}
		if ( $type_id && self::ticket_type( $type_id ) ) {
			$product->update_meta_data( self::PRODUCT_META, $type_id );
			// Ticket products are virtual and sold individually per line quantity.
			$product->set_virtual( true );
		} else {
			$product->delete_meta_data( self::PRODUCT_META );
		}
		if ( $type_id ) {
			$product->delete_meta_data( self::FISCAL_SELLER_META );
			$product->delete_meta_data( self::FISCAL_MODE_META );
		} else {
			$seller_id = absint( wp_unslash( $_POST[ self::FISCAL_SELLER_META ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$seller_mode = sanitize_key( wp_unslash( $_POST[ self::FISCAL_MODE_META ] ?? 'platform_seller' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $seller_id && in_array( $seller_mode, array( 'organizer_seller', 'platform_seller' ), true ) ) {
				$product->update_meta_data( self::FISCAL_SELLER_META, $seller_id );
				$product->update_meta_data( self::FISCAL_MODE_META, $seller_mode );
			} else {
				$product->delete_meta_data( self::FISCAL_SELLER_META );
				$product->delete_meta_data( self::FISCAL_MODE_META );
			}
		}
	}

	/**
	 * Whether the current user may link products to ticket types of an event: administrators
	 * (manage_options) or users with wpcap_manage_events for that event (Access::can_access_event).
	 */
	public static function can_link_event( $event_id ) {
		return current_user_can( 'manage_options' ) || ( absint( $event_id ) && \PassCaptain\Access::can_access_event( absint( $event_id ), 'wpcap_manage_events' ) );
	}

	/** Whether the current user may link (or unlink) this ticket type; unknown types only for administrators. */
	public static function can_link_ticket_type( $type_id ) {
		global $wpdb;
		if ( current_user_can( 'manage_options' ) ) { return true; }
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $type_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $event_id > 0 && self::can_link_event( $event_id );
	}

	/**
	 * Before a product is saved by a logged-in user: a changed ticket type link that the user may
	 * not set or remove (SEC-V2) is reset to the stored value. Saves without a user (cron, WP-CLI) are
	 * not restricted.
	 */
	public static function guard_product_link( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) || ! get_current_user_id() ) { return; }
		$id = method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : 0;
		$stored = $id ? absint( get_post_meta( $id, self::PRODUCT_META, true ) ) : 0;
		$new = absint( $product->get_meta( self::PRODUCT_META, true ) );
		if ( $new === $stored ) { return; }
		if ( ( $stored && ! self::can_link_ticket_type( $stored ) ) || ( $new && ! self::can_link_ticket_type( $new ) ) ) {
			if ( $stored ) {
				$product->update_meta_data( self::PRODUCT_META, $stored );
			} else {
				$product->delete_meta_data( self::PRODUCT_META );
			}
			\PassCaptain\Audit::record( 'woocommerce_product_link_refused', 'product', $id, array( 'ticket_type_id' => $new, 'kept' => $stored ) );
		}
	}

	/** Error notice on the product screen (WooCommerce meta box errors), else the plugin's admin notice. */
	private static function admin_error( $message ) {
		if ( class_exists( '\WC_Admin_Meta_Boxes' ) && is_callable( array( '\WC_Admin_Meta_Boxes', 'add_error' ) ) ) {
			\WC_Admin_Meta_Boxes::add_error( $message );
			return;
		}
		\PassCaptain\Admin\Forms::flash( 'error', $message );
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
		$seller_error           = SellerSplit::add_to_cart_error( $type_id, array_keys( $quantities ) );
		$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + absint( $quantity );
		$error                  = '' !== $seller_error ? $seller_error : self::quantity_error( $type_id, $quantities[ $type_id ] );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Store API add-to-cart (cart/checkout blocks, mini cart): refuses a ticket of another seller with
	 * the same message as the classic cart. Throwing aborts the request with an error notice.
	 */
	public static function store_api_validate_add_to_cart( $product ) {
		$type_id = self::product_ticket_type_id( $product );
		if ( ! $type_id ) {
			return;
		}
		$error = SellerSplit::add_to_cart_error( $type_id, array_keys( self::cart_quantities() ) );
		if ( '' === $error ) {
			return;
		}
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wpcap_mixed_sellers', esc_html( $error ), 400 );
		}
		throw new \Exception( esc_html( $error ) );
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

	/** Returns validation errors for all ticket lines in the cart (including mixed sellers). */
	private static function cart_errors() {
		$errors     = array();
		$quantities = self::cart_quantities();
		$mixed      = SellerSplit::mixed_error( array_keys( $quantities ) );
		if ( '' !== $mixed ) {
			$errors[] = $mixed;
		}
		foreach ( $quantities as $type_id => $quantity ) {
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
			return __( 'Diese Ticketkategorie existiert nicht mehr.', 'pass-captain' );
		}
		$name = sprintf( '%s – %s', $type->event_name, $type->name );
		$now  = current_time( 'mysql', true );
		if ( 'published' !== $type->event_status || 'active' !== $type->status || 'scheduled' !== $type->occurrence_status || $type->starts_at <= $now ) {
			/* translators: %s: event and ticket name */
			return sprintf( __( '„%s“ ist nicht mehr buchbar.', 'pass-captain' ), $name );
		}
		if ( ( $type->sales_start && $type->sales_start > $now ) || ( $type->sales_end && $type->sales_end < $now ) ) {
			/* translators: %s: event and ticket name */
			return sprintf( __( 'Der Verkauf für „%s“ ist derzeit geschlossen.', 'pass-captain' ), $name );
		}
		if ( $quantity > (int) $type->max_per_order ) {
			/* translators: 1: max quantity, 2: event and ticket name */
			return sprintf( __( 'Pro Bestellung sind höchstens %1$d Tickets für „%2$s“ möglich.', 'pass-captain' ), (int) $type->max_per_order, $name );
		}
		$available = Orders::availability( $type_id );
		if ( null !== $available && $quantity > $available ) {
			return 0 === $available
				/* translators: %s: event and ticket name */
				? sprintf( __( '„%s“ ist ausverkauft.', 'pass-captain' ), $name )
				/* translators: 1: available quantity, 2: event and ticket name */
				: sprintf( __( 'Für „%2$s“ sind nur noch %1$d Tickets verfügbar.', 'pass-captain' ), $available, $name );
		}
		$seats = self::free_seat_count( $type );
		if ( null !== $seats && $quantity > $seats ) {
			return 0 === $seats
				/* translators: %s: event and ticket name */
				? sprintf( __( 'Für „%s“ sind keine passenden Sitzplätze mehr frei.', 'pass-captain' ), $name )
				/* translators: 1: free seats, 2: event and ticket name */
				: sprintf( __( 'Für „%2$s“ sind nur noch %1$d passende Sitzplätze frei.', 'pass-captain' ), $seats, $name );
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
	 * Creates the Pass Captain order(s) with an inventory hold for a WooCommerce order.
	 *
	 * WooCommerce re-uses a pending order when a customer retries checkout. Each attempt therefore
	 * gets its own idempotency key and the previous unpaid Pass Captain orders are cancelled first.
	 *
	 * Tickets of different invoice issuers (sellers) are refused (policy "reject", default) or get one
	 * Pass Captain order per seller (policy "split"): all of them are created or none (a failure cancels
	 * the orders created so far). The first one is stored in ORDER_META, all of them in
	 * SellerSplit::SPLIT_META, the allocation snapshot in SellerSplit::PLAN_META.
	 *
	 * @param bool $after_payment Called for an order that never went through the checkout (created in the
	 *                            admin or over REST and then paid): tickets whose type has required attendee
	 *                            fields are issued without the data; the buyer adds it in the order portal.
	 * @return int|\WP_Error|null First core order ID, error, or null if the order has no ticket lines.
	 */
	public static function reserve( $wc_order, $after_payment = false ) {
		if ( ! $wc_order instanceof \WC_Order ) {
			return null;
		}
		$allocation = self::allocation( $wc_order );
		if ( ! $allocation['sellers'] ) {
			return null;
		}
		$existing_ids = self::core_order_ids( $wc_order );
		foreach ( $existing_ids as $existing ) {
			$core = Orders::get( $existing );
			if ( $core && 'paid' === $core->payment_status ) {
				return $existing_ids[0];
			}
		}
		foreach ( $existing_ids as $existing ) {
			Orders::cancel_order( $existing, 'woocommerce_checkout_retry' );
		}
		$email = sanitize_email( $wc_order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'wpcap_missing_email', __( 'Für Tickets wird eine gültige E-Mail-Adresse benötigt.', 'pass-captain' ) );
		}
		$split = count( $allocation['sellers'] ) > 1;
		if ( $split && SellerSplit::SPLIT !== SellerSplit::policy() ) {
			$types = array();
			foreach ( $allocation['sellers'] as $seller ) {
				$types = array_merge( $types, array_keys( $seller['lines'] ) );
			}
			return new \WP_Error( 'wpcap_mixed_sellers', SellerSplit::mixed_error( $types ) );
		}
		// Fiscal documents (Modules\Fiscalization): non-ticket lines need a legal seller, negative fees that
		// cannot be represented on a receipt block the checkout; extras are attached to one seller order each.
		$fiscal_extra = self::fiscal_additional_items( $wc_order, $allocation );
		if ( is_wp_error( $fiscal_extra ) ) {
			return $fiscal_extra;
		}
		$fiscal_extra   = self::assign_fiscal_extras( $fiscal_extra, $allocation );
		$payment_method = is_callable( array( $wc_order, 'get_payment_method' ) ) ? sanitize_key( (string) $wc_order->get_payment_method() ) : '';
		$attempt   = absint( $wc_order->get_meta( self::ATTEMPT_META, true ) ) + 1;
		$attendees = Attendees::for_order( $wc_order );
		$created   = array();
		$hold      = 0;
		foreach ( $allocation['sellers'] as $key => $seller ) {
			$lines  = $seller['lines'];
			$types  = array_keys( $lines );
			$result = Orders::create(
				array(
					'email'           => $email,
					'items'           => array_values( array_map( static function ( $line ) {
						return array( 'ticket_type_id' => $line['ticket_type_id'], 'quantity' => $line['quantity'] );
					}, $lines ) ),
					'idempotency_key' => $wc_order->get_id() . '-' . $attempt . ( $split ? '-' . $key : '' ),
					'attendees'       => $split ? array_values( array_filter( $attendees, static function ( $a ) use ( $types ) {
						return is_array( $a ) && in_array( absint( $a['ticket_type_id'] ?? 0 ), $types, true );
					} ) ) : $attendees,
				),
				array(
					'external'      => true,
					'currency'      => $wc_order->get_currency(),
					'hold_minutes'  => self::hold_minutes(),
					'source'        => 'woocommerce',
					'key_namespace' => 'woocommerce',
					'unit_prices'   => array_map( static function ( $line ) {
						return $line['unit_minor'];
					}, $lines ),
					'line_totals'   => array_map( static function ( $line ) {
						return $line['gross'];
					}, $lines ),
					'billing'       => self::billing_from_order( $wc_order ),
					'fiscal_extra_items'    => $fiscal_extra[ $key ] ?? array(),
					'fiscal_payment_method' => '' !== $payment_method ? $payment_method : 'unknown',
					'attendees_deferred'    => (bool) $after_payment,
				)
			);
			if ( is_wp_error( $result ) ) {
				// All or nothing: release the holds of the seller orders created for this attempt.
				foreach ( $created as $core_id ) {
					Orders::cancel_order( $core_id, 'woocommerce_split_failed' );
				}
				return $result;
			}
			$created[ $key ] = absint( $result['order_id'] );
			$hold            = (int) $result['hold_minutes'];
		}
		$first = (int) reset( $created );
		$wc_order->update_meta_data( self::ATTEMPT_META, $attempt );
		$wc_order->update_meta_data( self::ORDER_META, $first );
		$wc_order->update_meta_data( SellerSplit::PLAN_META, $allocation );
		if ( $split ) {
			$wc_order->update_meta_data( SellerSplit::SPLIT_META, $created );
			if ( is_callable( array( $wc_order, 'add_meta_data' ) ) ) {
				// One non-unique meta row per seller order, so a seller order can be traced back (wc_get_orders).
				$wc_order->delete_meta_data( SellerSplit::LOOKUP_META );
				foreach ( $created as $core_id ) {
					$wc_order->add_meta_data( SellerSplit::LOOKUP_META, (string) $core_id, false );
				}
			}
		} else {
			$wc_order->delete_meta_data( SellerSplit::SPLIT_META );
		}
		$wc_order->save();
		if ( $split ) {
			$names = array();
			foreach ( $created as $key => $core_id ) {
				$names[] = sprintf( '#%d %s', $core_id, SalesModel::seller_name( $key ) );
			}
			/* translators: 1: hold minutes, 2: number of seller orders, 3: list of orders and sellers */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: Tickets für %1$d Minuten reserviert, aufgeteilt auf %2$d Bestellungen je Rechnungssteller: %3$s.', 'pass-captain' ), $hold, count( $created ), implode( ', ', $names ) ) );
		} else {
			/* translators: %d: hold minutes */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: Tickets für %d Minuten reserviert.', 'pass-captain' ), $hold ) );
		}
		return $first;
	}

	/**
	 * Per-seller allocation of a WooCommerce order (SellerSplit::allocate() with this adapter's ticket
	 * type lookup).
	 */
	public static function allocation( $wc_order ) {
		return SellerSplit::allocate(
			$wc_order,
			static function ( $item ) {
				return self::item_ticket_type_id( $item );
			}
		);
	}

	/**
	 * Pass Captain order IDs linked to a WooCommerce order (one, or one per seller when split).
	 *
	 * @param \WC_Order|int $wc_order Order or ID.
	 * @return int[]
	 */
	public static function core_order_ids( $wc_order ) {
		$wc_order = is_object( $wc_order ) ? $wc_order : wc_get_order( absint( $wc_order ) );
		if ( ! $wc_order ) {
			return array();
		}
		$map = $wc_order->get_meta( SellerSplit::SPLIT_META, true );
		if ( is_array( $map ) && $map ) {
			$ids = array();
			foreach ( $map as $key => $core_id ) {
				if ( absint( $core_id ) ) {
					$ids[ absint( $core_id ) ] = (string) $key;
				}
			}
		} else {
			$id  = absint( $wc_order->get_meta( self::ORDER_META, true ) );
			$ids = $id ? array( $id => '' ) : array();
		}
		return $ids ? self::owned_core_ids( $wc_order, $ids ) : array();
	}

	/**
	 * Keeps only the Pass Captain orders that were created for this WooCommerce order. The meta can be
	 * copied to another order (WooCommerce Subscriptions renewals, "duplicate order" plugins, imports); a
	 * copy must never cancel, refund or display the original order's tickets.
	 *
	 * A core order belongs to the WooCommerce order when its keyed idempotency key is the one reserve()
	 * derives for this order ID and one of its checkout attempts ("{id}-{attempt}[-{seller key}]"), or when
	 * its payment was confirmed with the reference "woocommerce:{id}" (confirm_core()).
	 *
	 * @param array<int,string> $ids Core order ID => seller key ('' when not split).
	 * @return int[]
	 */
	private static function owned_core_ids( $wc_order, array $ids ) {
		global $wpdb;
		$wc_id    = (int) $wc_order->get_id();
		$attempts = min( 50, max( 1, absint( $wc_order->get_meta( self::ATTEMPT_META, true ) ) ) );
		$keys     = array();
		foreach ( array_unique( array_values( $ids ) ) as $seller ) {
			for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
				$plain          = $wc_id . '-' . $attempt . ( '' !== $seller ? '-' . $seller : '' );
				$keys[ hash_hmac( 'sha256', $plain, 'wpcap-channel-woocommerce' ) ] = true; // Orders::create() with key_namespace "woocommerce".
				$keys[ hash( 'sha256', 'woocommerce-' . $plain ) ] = true; // Keys of early pre-1.0 builds.
			}
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,idempotency_key,provider_reference FROM %i WHERE order_id IN ($placeholders)", array_merge( array( $wpdb->prefix . 'wpcap_orders' ), array_keys( $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
		$owned = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $keys[ (string) $row->idempotency_key ] ) || 'woocommerce:' . $wc_id === (string) $row->provider_reference ) {
				$owned[ (int) $row->order_id ] = true;
			}
		}
		// Keep the stored order (the first one is the classic ORDER_META order).
		return array_values( array_filter( array_keys( $ids ), static function ( $id ) use ( $owned ) {
			return isset( $owned[ $id ] );
		} ) );
	}

	/**
	 * Filters of WooCommerce Subscriptions that copy order meta to renewal/resubscribe orders and to the
	 * subscription itself: Pass Captain's own meta (_wpcap_*) is never copied (tickets belong to the order
	 * they were bought with; a renewal with ticket products gets its own hold when it is paid).
	 *
	 * Handles the array forms (meta key => value, or rows with meta_key) and the SQL query form.
	 *
	 * @param mixed $data Meta data or SQL query.
	 * @return mixed
	 */
	public static function exclude_copied_meta( $data ) {
		if ( is_string( $data ) ) {
			// wcs_*_meta_query: SELECT meta_key, meta_value FROM … WHERE … – exclude our keys in SQL.
			return '' === trim( $data ) ? $data : $data . " AND meta_key NOT LIKE '\\_wpcap\\_%'";
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			$meta_key = is_array( $value ) && isset( $value['meta_key'] ) ? (string) $value['meta_key'] : ( is_string( $key ) ? $key : '' );
			if ( 0 === strpos( $meta_key, '_wpcap_' ) ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/** Seller key => core order ID of a split WooCommerce order (empty if not split). */
	public static function seller_orders( $wc_order ) {
		$map = $wc_order ? $wc_order->get_meta( SellerSplit::SPLIT_META, true ) : array();
		return is_array( $map ) ? array_map( 'absint', $map ) : array();
	}

	/**
	 * Billing data of a WooCommerce order for the core order, so invoices issued by Pass Captain
	 * carry company, name, address and VAT ID. The VAT ID is read from common meta keys of VAT
	 * plugins (filter `wpcap_woocommerce_vat_meta_keys`); the result can be changed with the filter
	 * `wpcap_woocommerce_billing`. Empty when the order has neither a name nor a company.
	 *
	 * @return array Billing::FIELDS => string, or an empty array.
	 */
	public static function billing_from_order( $wc_order ) {
		if ( ! is_object( $wc_order ) ) {
			return array();
		}
		$get = static function ( $method ) use ( $wc_order ) {
			return is_callable( array( $wc_order, $method ) ) ? (string) call_user_func( array( $wc_order, $method ) ) : '';
		};
		$vat_id = '';
		$keys   = (array) apply_filters( 'wpcap_woocommerce_vat_meta_keys', array( '_billing_vat', '_vat_number', 'billing_eu_vat_number', '_billing_eu_vat_number', '_billing_vat_number', 'vat_number', '_billing_vat_id' ), $wc_order );
		foreach ( $keys as $key ) {
			$value = is_callable( array( $wc_order, 'get_meta' ) ) ? $wc_order->get_meta( (string) $key, true ) : '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$vat_id = (string) $value;
				break;
			}
		}
		$billing = array(
			'company'    => $get( 'get_billing_company' ),
			'first_name' => $get( 'get_billing_first_name' ),
			'last_name'  => $get( 'get_billing_last_name' ),
			'street'     => implode( ', ', array_filter( array( trim( $get( 'get_billing_address_1' ) ), trim( $get( 'get_billing_address_2' ) ) ), 'strlen' ) ),
			'postcode'   => $get( 'get_billing_postcode' ),
			'city'       => $get( 'get_billing_city' ),
			'country'    => $get( 'get_billing_country' ),
			'vat_id'     => $vat_id,
			'po_number'  => '',
		);
		$billing = Billing::sanitize( apply_filters( 'wpcap_woocommerce_billing', $billing, $wc_order ) );
		if ( '' === $billing['company'] && '' === $billing['first_name'] && '' === $billing['last_name'] ) {
			return array();
		}
		return $billing;
	}

	/** Hold duration for WooCommerce orders (at least the WooCommerce "hold stock" setting). */
	private static function hold_minutes() {
		$minutes = max( Orders::HOLD_MINUTES, absint( get_option( 'woocommerce_hold_stock_minutes', 60 ) ) );
		return (int) apply_filters( 'wpcap_woocommerce_hold_minutes', $minutes );
	}

	/**
	 * Legal seller (wpcap_ticket_sellers) per fiscally mapped ticket type of an allocation.
	 *
	 * @return array<int,int> ticket type ID => legal seller ID (unmapped types are missing).
	 */
	private static function fiscal_ticket_sellers( array $allocation ) {
		global $wpdb;
		$type_ids = array();
		foreach ( (array) ( $allocation['sellers'] ?? array() ) as $seller ) {
			$type_ids = array_merge( $type_ids, array_map( 'absint', array_keys( (array) $seller['lines'] ) ) );
		}
		$type_ids = array_values( array_filter( array_unique( $type_ids ) ) );
		if ( ! $type_ids ) {
			return array();
		}
		$ids = implode( ',', $type_ids );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT ticket_type_id,seller_id FROM {$wpdb->prefix}wpcap_ticket_sellers WHERE ticket_type_id IN ({$ids})" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- integer list.
			$out[ (int) $row->ticket_type_id ] = (int) $row->seller_id;
		}
		return $out;
	}

	/**
	 * Collects mapped WooCommerce goods, positive fees and shipping for seller-specific fiscal documents.
	 *
	 * Only applies when the order contains fiscally mapped tickets. Rules (docs/WOOCOMMERCE.md, "Fiskalbelege"):
	 * every non-ticket line needs a legal seller (product/item meta or the filter
	 * `wpcap_woocommerce_fiscal_item_seller`); negative fees keep the W10 allocation. Their ticket share
	 * reduces the ticket lines (represented on the receipt as reduced line amounts); a share that would
	 * fall on non-ticket products cannot be represented, so the checkout is refused while a fiscal receipt
	 * is required for the tickets (active profile). Without required receipts nothing changes.
	 *
	 * @return array|\WP_Error List of extra lines (with seller_id) or an error that aborts the checkout.
	 */
	private static function fiscal_additional_items( \WC_Order $wc_order, array $allocation ) {
		$mapped = self::fiscal_ticket_sellers( $allocation );
		if ( ! $mapped ) {
			return array();
		}
		$items       = array();
		$unallocated = false;
		foreach ( $wc_order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( self::item_ticket_type_id( $item ) ) {
				continue;
			}
			$assignment = self::fiscal_item_assignment( $item, 'product', $wc_order );
			if ( is_wp_error( $assignment ) ) {
				$unallocated = true;
				continue;
			}
			$line = self::fiscal_item_snapshot( $item, $assignment, $item_id, $wc_order );
			if ( is_wp_error( $line ) ) {
				return $line;
			}
			$items[] = $line;
		}
		$factor = 10 ** self::currency_decimals( $wc_order->get_currency() );
		foreach ( array( 'fee' => $wc_order->get_items( 'fee' ), 'shipping' => $wc_order->get_items( 'shipping' ) ) as $kind => $order_items ) {
			foreach ( $order_items as $item_id => $item ) {
				if ( 'fee' === $kind && self::item_gross_minor( $item, $factor ) < 0 ) {
					$error = self::negative_fee_fiscal_error( $allocation, array_keys( $mapped ) );
					if ( $error ) {
						return $error;
					}
					continue; // Allocated to the ticket lines by SellerSplit (or no receipt required).
				}
				$assignment = self::fiscal_item_assignment( $item, $kind, $wc_order );
				if ( is_wp_error( $assignment ) ) {
					$unallocated = true;
					continue;
				}
				$line = self::fiscal_item_snapshot( $item, $assignment, $item_id, $wc_order );
				if ( is_wp_error( $line ) ) {
					return $line;
				}
				$items[] = $line;
			}
		}
		if ( $unallocated ) {
			return self::mixed_fiscal_allocation_error( true, true, false, false );
		}
		return $items;
	}

	/** Gross amount (total + tax) of an order item in minor units. */
	private static function item_gross_minor( $item, $factor ) {
		$total = is_callable( array( $item, 'get_total' ) ) ? (float) $item->get_total() : 0.0;
		$tax   = is_callable( array( $item, 'get_total_tax' ) ) ? (float) $item->get_total_tax() : 0.0;
		return (int) round( ( $total + $tax ) * $factor );
	}

	/**
	 * Error for a negative fee that cannot be represented on the fiscal receipts of this order, or null.
	 *
	 * Representable: the whole discount is allocated to ticket lines (SellerSplit rule 2 gives the shop
	 * bucket nothing, i.e. there are no non-ticket products), or no ticket of the order needs a receipt.
	 */
	public static function negative_fee_fiscal_error( array $allocation, array $type_ids ) {
		if ( (int) ( $allocation['shop']['fee_discount'] ?? 0 ) <= 0 ) {
			return null;
		}
		if ( ! class_exists( '\\PassCaptain\\Modules\\Fiscalization' ) || ! \PassCaptain\Modules\Fiscalization::requires_receipts( $type_ids, 'woocommerce' ) ) {
			return null;
		}
		return new \WP_Error( 'wpcap_fiscal_negative_fee', __( 'Ein Rabatt als negative Gebühr kann in dieser Bestellung nicht auf dem Fiskalbeleg abgebildet werden, weil er auch auf andere Produkte als Tickets entfällt. Bitte einen Gutschein (Coupon) verwenden oder die Tickets getrennt bestellen.', 'pass-captain' ) );
	}

	/**
	 * Attaches each fiscal extra line to one seller order of the allocation: the order whose tickets have
	 * the same legal seller, else the first fiscally mapped order, else the first order.
	 *
	 * @return array<string,array> seller key => extra lines.
	 */
	private static function assign_fiscal_extras( array $extras, array $allocation ) {
		if ( ! $extras ) {
			return array();
		}
		$mapped    = self::fiscal_ticket_sellers( $allocation );
		$keys      = array_keys( (array) $allocation['sellers'] );
		$legal     = array();
		$fallback  = '';
		foreach ( $allocation['sellers'] as $key => $seller ) {
			foreach ( array_keys( (array) $seller['lines'] ) as $type_id ) {
				if ( isset( $mapped[ (int) $type_id ] ) ) {
					$legal[ $mapped[ (int) $type_id ] ] = $legal[ $mapped[ (int) $type_id ] ] ?? (string) $key;
					$fallback = '' !== $fallback ? $fallback : (string) $key;
				}
			}
		}
		$fallback = '' !== $fallback ? $fallback : (string) reset( $keys );
		$out      = array();
		foreach ( $extras as $extra ) {
			$out[ $legal[ (int) $extra['seller_id'] ] ?? $fallback ][] = $extra;
		}
		return $out;
	}

	/** Pure guard shared with tests and integrations: every non-ticket line and surcharge needs an owner. */
	public static function mixed_fiscal_allocation_error( $has_fiscal_ticket, $has_unallocated_line, $has_unallocated_fee, $has_unallocated_shipping ) {
		if ( $has_fiscal_ticket && ( $has_unallocated_line || $has_unallocated_fee || $has_unallocated_shipping ) ) {
			return new \WP_Error( 'wpcap_fiscal_mixed_allocation_required', __( 'Jede zusätzliche Ware, Gebühr und Versandposition in einer Bestellung mit fiskalischen Tickets muss einem rechtlichen Verkäufer zugeordnet sein.', 'pass-captain' ) );
		}
		return null;
	}

	/** Resolve seller assignment from order-line metadata/product configuration or a trusted integration. */
	private static function fiscal_item_assignment( $item, $kind, \WC_Order $wc_order ) {
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
		$seller_id = absint( $item->get_meta( self::FISCAL_SELLER_META, true ) );
		$seller_mode = sanitize_key( (string) $item->get_meta( self::FISCAL_MODE_META, true ) );
		if ( ! $seller_id && $product ) {
			$seller_id = absint( $product->get_meta( self::FISCAL_SELLER_META, true ) );
			$seller_mode = sanitize_key( (string) $product->get_meta( self::FISCAL_MODE_META, true ) );
		}
		$assignment = apply_filters( 'wpcap_woocommerce_fiscal_item_seller', array( 'seller_id' => $seller_id, 'seller_mode' => $seller_mode ), $item, sanitize_key( $kind ), $product, $wc_order );
		if ( ! is_array( $assignment ) ) { return new \WP_Error( 'wpcap_fiscal_item_seller_missing' ); }
		$seller_id = absint( $assignment['seller_id'] ?? 0 );
		$seller_mode = sanitize_key( $assignment['seller_mode'] ?? '' );
		if ( ! $seller_id || ! in_array( $seller_mode, array( 'organizer_seller', 'platform_seller' ), true ) ) { return new \WP_Error( 'wpcap_fiscal_item_seller_missing' ); }
		return array( 'seller_id' => $seller_id, 'seller_mode' => $seller_mode );
	}

	/** Snapshot a WooCommerce order line after discounts, in the order currency's minor units. */
	private static function fiscal_item_snapshot( $item, array $assignment, $item_id, \WC_Order $wc_order ) {
		$currency = $wc_order->get_currency();
		$factor = 10 ** self::currency_decimals( $currency );
		$gross = (int) round( ( (float) $item->get_total() + (float) $item->get_total_tax() ) * $factor );
		$tax = (int) round( (float) $item->get_total_tax() * $factor );
		if ( $gross < 0 ) { return new \WP_Error( 'wpcap_fiscal_negative_extra_line', __( 'Negative Beträge müssen als WooCommerce-Rabatt (Gutschein) statt als negative Position eingerichtet werden.', 'pass-captain' ) ); }
		$taxes = is_callable( array( $item, 'get_taxes' ) ) ? (array) $item->get_taxes() : array();
		$rate_ids = array_keys( array_filter( (array) ( $taxes['total'] ?? array() ), static function ( $amount ) { return abs( (float) $amount ) > 0.000001; } ) );
		$rate_bp = 0;
		if ( count( $rate_ids ) > 1 ) { return new \WP_Error( 'wpcap_fiscal_multiple_tax_rates', __( 'Eine zusätzliche Warenkorbposition enthält mehrere Steuersätze und kann nicht sicher einem Fiskalbeleg zugeordnet werden.', 'pass-captain' ) ); }
		if ( $rate_ids && class_exists( '\WC_Tax' ) && is_callable( array( '\WC_Tax', 'get_rate_percent' ) ) ) {
			$percent = (float) str_replace( '%', '', (string) \WC_Tax::get_rate_percent( absint( reset( $rate_ids ) ) ) );
			$rate_bp = (int) round( $percent * 100 );
		} elseif ( $tax > 0 ) {
			return new \WP_Error( 'wpcap_fiscal_tax_rate_unavailable', __( 'Der WooCommerce-Steuersatz für eine zusätzliche Position konnte nicht eindeutig bestimmt werden.', 'pass-captain' ) );
		}
		$quantity = max( 1, absint( is_callable( array( $item, 'get_quantity' ) ) ? $item->get_quantity() : 1 ) );
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
		$product_id = $product && is_callable( array( $product, 'get_id' ) ) ? absint( $product->get_id() ) : 0;
		return array(
			'line_key' => self::fiscal_line_key( $item_id, $item ),
			'product_id' => $product_id,
			'seller_id' => absint( $assignment['seller_id'] ),
			'seller_mode' => sanitize_key( $assignment['seller_mode'] ),
			'description' => sanitize_text_field( (string) $item->get_name() ),
			'quantity' => $quantity,
			'gross_minor' => max( 0, $gross ),
			'tax_rate_bp' => $rate_bp,
			'tax_minor' => min( max( 0, $gross ), max( 0, $tax ) ),
		);
	}

	/** ISO 4217 minor unit digits for the currencies WooCommerce commonly uses. */
	public static function currency_decimals( $currency ) {
		return \PassCaptain\Localization::currency_decimals( $currency );
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

	/**
	 * Converts the hold(s) and issues tickets once WooCommerce reports a paid order. A split order
	 * confirms every seller order (each issues its own tickets, mail and invoice).
	 */
	public static function sync_paid_order( $wc_order_id, $force = false ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( ! $wc_order ) {
			return;
		}
		$core_ids = self::core_order_ids( $wc_order );
		if ( ! $core_ids ) {
			// Orders created in the admin or over REST (or before the adapter was active) have no hold yet;
			// required attendee data cannot have been collected, the buyer completes it in the order portal.
			$core_id = self::reserve( $wc_order, true );
			if ( is_wp_error( $core_id ) ) {
				/* translators: %s: error message */
				$wc_order->add_order_note( sprintf( __( 'Pass Captain: Tickets konnten nicht reserviert werden: %s', 'pass-captain' ), $core_id->get_error_message() ) );
				return;
			}
			if ( ! $core_id ) {
				return;
			}
			$core_ids = self::core_order_ids( $wc_order );
			if ( self::missing_attendee_data( $core_ids ) ) {
				$wc_order->add_order_note( __( 'Pass Captain: Die Bestellung wurde nicht über den Checkout angelegt; Pflichtangaben zu Teilnehmenden fehlen. Die Tickets werden trotzdem ausgestellt – die Kundschaft ergänzt die Angaben über den Bestelllink aus der Ticket-E-Mail.', 'pass-captain' ) );
			}
		}
		$cores = array();
		foreach ( $core_ids as $core_id ) {
			$cores[ $core_id ] = Orders::get( $core_id );
		}
		$open = array_filter( $cores, static function ( $core, $core_id ) {
			return ! ( $core && 'paid' === $core->payment_status && Orders::tickets_for_order( $core_id ) );
		}, ARRAY_FILTER_USE_BOTH );
		if ( ! $open ) {
			return;
		}
		if ( self::defers_issuance( $wc_order ) ) {
			// Cash on delivery & co.: "processing" means "to be delivered", not "paid". Keep the hold.
			foreach ( array_keys( $open ) as $core_id ) {
				Orders::extend_hold( $core_id, (int) apply_filters( 'wpcap_woocommerce_deferred_hold_minutes', 14 * DAY_IN_SECONDS / MINUTE_IN_SECONDS, $wc_order ) );
			}
			if ( ! $wc_order->get_meta( self::DEFER_NOTE, true ) ) {
				$wc_order->update_meta_data( self::DEFER_NOTE, 1 );
				$wc_order->save();
				$wc_order->add_order_note( __( 'Pass Captain: Zahlung bei Lieferung/Abholung – die Tickets bleiben reserviert und werden ausgestellt, sobald die Bestellung auf „Abgeschlossen“ gesetzt wird.', 'pass-captain' ) );
			}
			return;
		}
		$any_paid      = (bool) array_filter( $cores, static function ( $core ) {
			return $core && 'paid' === $core->payment_status;
		} );
		$any_cancelled = (bool) array_filter( $open, static function ( $core ) {
			return $core && 'cancelled' === $core->status;
		} );
		if ( $any_cancelled && ! $any_paid ) {
			// A failed payment released the hold; the customer has paid on a later attempt.
			$core_id = self::reserve( $wc_order );
			if ( is_wp_error( $core_id ) || ! $core_id ) {
				$message = is_wp_error( $core_id ) ? $core_id->get_error_message() : '';
				/* translators: %s: error message */
				$wc_order->add_order_note( sprintf( __( 'Pass Captain: Zahlung eingegangen, aber Tickets konnten nicht erneut reserviert werden – bitte manuell klären. %s', 'pass-captain' ), $message ) );
				return;
			}
			$core_ids = self::core_order_ids( $wc_order );
		}
		$labels = array_flip( self::seller_orders( $wc_order ) );
		$issued = 0;
		foreach ( $core_ids as $core_id ) {
			$issued += self::confirm_core( $wc_order, $core_id, $force, isset( $labels[ $core_id ] ) ? SalesModel::seller_name( $labels[ $core_id ] ) : '' );
		}
		if ( $issued && $wc_order->get_meta( self::ATTENDEE_META, true ) ) {
			// Participant data now lives on the tickets; do not keep a second copy on the WooCommerce order.
			$wc_order->delete_meta_data( self::ATTENDEE_META );
			$wc_order->save();
		}
	}

	/** Whether a ticket type of these core orders has required attendee fields but the order has no attendee data. */
	private static function missing_attendee_data( array $core_ids ) {
		global $wpdb;
		foreach ( $core_ids as $core_id ) {
			$core = Orders::get( $core_id );
			if ( ! $core || ! empty( $core->attendee_data ) ) {
				continue;
			}
			$required = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i f INNER JOIN %i i ON i.ticket_type_id=f.ticket_type_id WHERE i.order_id=%d AND f.required=1 AND f.status='active'", $wpdb->prefix . 'wpcap_participant_fields', $wpdb->prefix . 'wpcap_order_items', (int) $core_id ) );
			if ( $required ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Confirms the payment of one Pass Captain order of a WooCommerce order and issues its tickets.
	 *
	 * @param string $seller Seller name for the order note of a split order ('' = not split).
	 * @return int Number of tickets of the order after the call (0 when nothing was issued).
	 */
	private static function confirm_core( $wc_order, $core_id, $force, $seller = '' ) {
		$core = Orders::get( $core_id );
		if ( $core && 'paid' === $core->payment_status && Orders::tickets_for_order( $core_id ) ) {
			return 0;
		}
		if ( $core && 'needs_review' === $core->status && ! $force ) {
			// Already flagged and reported; only the explicit "re-issue" order action retries.
			return 0;
		}
		$prefix      = '' !== $seller ? sprintf( '[#%d %s] ', $core_id, $seller ) : '';
		$had_tickets = (bool) Orders::tickets_for_order( $core_id );
		// The buyer may have changed the billing address after the hold; invoices use the paid state.
		if ( ! Billing::invoice_issued( $core_id ) ) {
			Billing::save( $core_id, self::billing_from_order( $wc_order ) );
		}
		$result = Orders::confirm_external_payment( $core_id, 'woocommerce:' . $wc_order->get_id() );
		if ( is_wp_error( $result ) ) {
			/* translators: 1: seller order prefix (may be empty), 2: error message */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: %1$sKeine Tickets ausgestellt – %2$s', 'pass-captain' ), $prefix, $result->get_error_message() ) );
			return 0;
		}
		$count = count( Orders::tickets_for_order( $core_id ) );
		$fiscal = ! $count && class_exists( '\\PassCaptain\\Modules\\Fiscalization' ) ? \PassCaptain\Modules\Fiscalization::blocking_status( $core_id ) : '';
		if ( '' !== $fiscal ) {
			/* translators: %s: seller order prefix (may be empty) */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: %sZahlung übernommen, Tickets warten noch auf die Fiskalisierung. Fiskalstatus unter Pass Captain → Fiskalisierung prüfen oder den Fiskal-Retry auslösen.', 'pass-captain' ), $prefix ) );
			return 0;
		}
		/* translators: 1: seller order prefix (may be empty), 2: ticket count */
		$wc_order->add_order_note( sprintf( _n( 'Pass Captain: %1$s%2$d Ticket ausgestellt und per E-Mail versendet.', 'Pass Captain: %1$s%2$d Tickets ausgestellt und per E-Mail versendet.', $count, 'pass-captain' ), $prefix, $count ) );
		if ( ! $had_tickets && $count ) {
			// Personal link for ticket transfer/self-service, sent once with the first issuance.
			Orders::rotate_access_link( $core_id );
		}
		return $count;
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

	/** Extends the hold(s) while a manual payment (e.g. bank transfer) is outstanding. */
	public static function sync_on_hold_order( $wc_order_id ) {
		$minutes = (int) apply_filters( 'wpcap_woocommerce_on_hold_minutes', 3 * DAY_IN_SECONDS / MINUTE_IN_SECONDS );
		foreach ( self::core_order_ids( $wc_order_id ) as $core_id ) {
			Orders::extend_hold( $core_id, $minutes );
		}
	}

	/** Releases the hold(s) and revokes valid tickets for cancelled or failed orders. */
	public static function sync_cancelled_order( $wc_order_id ) {
		foreach ( self::core_order_ids( $wc_order_id ) as $core_id ) {
			Orders::cancel_order( $core_id, 'woocommerce_' . current_filter() );
		}
	}

	/** Order moved to the trash (HPOS hook; also fired by the posts data store). */
	public static function sync_trashed_order( $wc_order_id ) {
		self::release_removed_order( $wc_order_id, 'trash' );
	}

	/** Order about to be deleted permanently. */
	public static function sync_deleted_order( $wc_order_id ) {
		self::release_removed_order( $wc_order_id, 'delete' );
	}

	/** Order restored from the trash. */
	public static function sync_untrashed_order( $wc_order_id ) {
		self::restore_untrashed_order( $wc_order_id );
	}

	/** Posts storage: wp_trash_post for shop_order posts. */
	public static function sync_trashed_post( $post_id ) {
		if ( self::is_order_post( $post_id ) ) { self::release_removed_order( $post_id, 'trash' ); }
	}

	/** Posts storage: order post about to be removed permanently (before_delete_post). */
	public static function sync_deleted_post( $post_id ) {
		if ( self::is_order_post( $post_id ) ) { self::release_removed_order( $post_id, 'delete' ); }
	}

	/** Posts storage: untrashed_post for shop_order posts. */
	public static function sync_untrashed_post( $post_id ) {
		if ( self::is_order_post( $post_id ) ) { self::restore_untrashed_order( $post_id ); }
	}

	private static function is_order_post( $post_id ) {
		return function_exists( 'get_post_type' ) && 'shop_order' === get_post_type( absint( $post_id ) );
	}

	/**
	 * An unpaid WooCommerce order that is trashed or deleted releases its hold (Orders::cancel_order with
	 * only_unpaid). A paid order keeps its tickets – deleting a WooCommerce order is no refund – but it is
	 * recorded in the audit log and, when only trashed, noted on the order.
	 *
	 * @param string $how 'trash' or 'delete'.
	 */
	private static function release_removed_order( $wc_order_id, $how ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( ! $wc_order ) {
			return;
		}
		$paid = array();
		foreach ( self::core_order_ids( $wc_order ) as $core_id ) {
			$result = Orders::cancel_order( $core_id, 'woocommerce_order_' . $how, true );
			if ( is_wp_error( $result ) && 'wpcap_order_paid_state' === $result->get_error_code() ) {
				$paid[] = $core_id;
				\PassCaptain\Audit::record( 'woocommerce_paid_order_removed', 'order', $core_id, array( 'wc_order_id' => (int) $wc_order->get_id(), 'action' => $how ) );
			}
		}
		if ( $paid && 'trash' === $how ) {
			/* translators: %s: list of Pass Captain order IDs */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: Bestellung in den Papierkorb verschoben. Die Tickets der bezahlten Bestellung(en) %s bleiben gültig – für einen Widerruf die Bestellung erstatten oder stornieren.', 'pass-captain' ), '#' . implode( ', #', $paid ) ) );
		}
	}

	/** A restored unpaid order reserves its tickets again (its hold was released when it was trashed). */
	private static function restore_untrashed_order( $wc_order_id ) {
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( ! $wc_order || ( is_callable( array( $wc_order, 'is_paid' ) ) && $wc_order->is_paid() ) ) {
			return;
		}
		$status = is_callable( array( $wc_order, 'get_status' ) ) ? (string) $wc_order->get_status() : 'pending';
		if ( ! in_array( $status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) ) {
			return;
		}
		foreach ( self::core_order_ids( $wc_order ) as $core_id ) {
			$core = Orders::get( $core_id );
			if ( $core && 'cancelled' !== $core->status ) {
				return; // Still reserved (or paid): nothing to restore.
			}
		}
		$result = self::reserve( $wc_order );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message */
			$wc_order->add_order_note( sprintf( __( 'Pass Captain: Bestellung wiederhergestellt, aber die Tickets konnten nicht erneut reserviert werden: %s', 'pass-captain' ), $result->get_error_message() ) );
			return;
		}
		if ( $result && 'on-hold' === $status ) {
			self::sync_on_hold_order( $wc_order->get_id() );
		}
	}

	/**
	 * Mirrors a WooCommerce refund into Pass Captain. Idempotent per refund.
	 *
	 * With core support (Refunds::record_external) the refund goes through the core refund ledger of
	 * every affected seller order: wpcap_refunds row, order refunded amount, refunded/revoked tickets,
	 * released capacity, credit note (by the seller that issued the invoice) and the
	 * wpcap_refund_completed hook. Without it only the refunded tickets are revoked.
	 */
	public static function sync_refund( $wc_order_id, $refund_id ) {
		$core_ids = self::core_order_ids( $wc_order_id );
		$refund   = wc_get_order( absint( $refund_id ) );
		if ( ! $core_ids || ! $refund || $refund->get_meta( self::REFUND_META, true ) ) {
			return true;
		}
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( $wc_order && self::can_record_refunds() ) {
			try {
				$recorded = self::record_refund( $wc_order, $refund, $core_ids );
				if ( $recorded ) {
					self::record_fiscal_extra_refund( $wc_order, $refund, $core_ids );
				}
				return $recorded;
			} catch ( \Throwable $error ) {
				// An exception (core, add-on hook, WooCommerce) must not lose the refund: the job queue repeats it.
				self::refund_failed( $wc_order, (int) $refund->get_id(), $error->getMessage(), true );
				return false;
			}
		}
		$revoked = 0;
		$kept    = 0;
		$owners  = self::type_owners( $core_ids );
		foreach ( self::refund_type_quantities( $refund ) as $type_id => $qty ) {
			$core_id  = $owners[ $type_id ] ?? $core_ids[0];
			$done     = Orders::revoke_tickets( $core_id, $type_id, $qty, 'woocommerce_refund_' . $refund->get_id() );
			$revoked += $done;
			$kept    += $qty - $done;
		}
		$refund->update_meta_data( self::REFUND_META, 1 );
		$refund->save();
		if ( $wc_order ) {
			self::record_fiscal_extra_refund( $wc_order, $refund, $core_ids );
		}
		self::note_refund( $wc_order, $revoked, $kept );
		return true;
	}

	/**
	 * Filter wp_pass_captain_process_job_v1: retries a WooCommerce refund whose recording failed with a
	 * retryable database error (idempotent: the refund meta and the core idempotency key prevent doubles).
	 */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::REFUND_SYNC_JOB !== $type ) {
			return $handled;
		}
		$payload = (array) $payload;
		self::$refund_job_running = true;
		self::$refund_job_error   = '';
		try {
			$done = true === self::sync_refund( absint( $payload['wc_order_id'] ?? 0 ), absint( $payload['refund_id'] ?? 0 ) );
		} finally {
			self::$refund_job_running = false;
		}
		if ( $done ) {
			return true;
		}
		// Not done: the job queue retries with backoff and finally dead-letters it (system status page).
		$error = self::last_refund_job_error();
		return new \WP_Error( 'wpcap_woocommerce_refund_sync', '' !== $error ? $error : 'WooCommerce refund could not be mirrored yet.' );
	}

	/** Error kept by refund_failed() during the running refund sync job. */
	private static function last_refund_job_error() {
		return (string) self::$refund_job_error;
	}

	/** Whether the refund sync job is running (no second job is queued from inside it). */
	private static $refund_job_running = false;

	/** Last error of the running refund sync job (kept as the job's last_error). */
	private static $refund_job_error = '';

	/**
	 * A WooCommerce refund could not be mirrored: queues one retry job per WooCommerce refund (the
	 * idempotency key is the refund id, so repeated failures never queue a second job), audits it and
	 * tells the shop admin once. Inside the job run only the error is kept; the job queue retries it
	 * with backoff and dead-letters it after Jobs::MAX_ATTEMPTS (listed on the system status page).
	 */
	private static function refund_failed( $wc_order, $refund_id, $message, $retryable ) {
		$message = sanitize_text_field( (string) $message );
		if ( self::$refund_job_running ) {
			self::$refund_job_error = substr( $message, 0, 400 );
			return;
		}
		$core_ids = self::core_order_ids( $wc_order->get_id() );
		\PassCaptain\Jobs::enqueue(
			self::refund_job_key( $refund_id ),
			self::REFUND_SYNC_JOB,
			array( 'wc_order_id' => (int) $wc_order->get_id(), 'refund_id' => (int) $refund_id ),
			gmdate( 'Y-m-d H:i:s', time() + 2 * MINUTE_IN_SECONDS )
		);
		\PassCaptain\Audit::record( 'woocommerce_refund_sync_failed', 'order', (int) ( $core_ids[0] ?? 0 ), array( 'wc_order_id' => (int) $wc_order->get_id(), 'refund_id' => (int) $refund_id, 'retryable' => (bool) $retryable, 'message' => substr( $message, 0, 190 ) ) );
		$note = $retryable
			/* translators: %s: error message */
			? __( 'Pass Captain: Erstattung konnte noch nicht übernommen werden (%s). Sie wird automatisch erneut versucht.', 'pass-captain' )
			/* translators: %s: error message */
			: __( 'Pass Captain: Erstattung konnte nicht übernommen werden – %s. Sie wird automatisch erneut versucht; schlägt das dauerhaft fehl, erscheint der Auftrag auf der Seite Pass Captain → Systemstatus.', 'pass-captain' );
		$wc_order->add_order_note( sprintf( $note, $message ) );
	}

	/** Idempotency key of the retry job of a WooCommerce refund (one job per refund). */
	public static function refund_job_key( $refund_id ) {
		return 'woocommerce-refund-sync-' . absint( $refund_id );
	}

	/**
	 * Records a WooCommerce refund through the core refund ledger, once per affected seller order.
	 *
	 * @param int[] $core_ids Pass Captain orders of the WooCommerce order.
	 * @return bool True when recorded (or nothing to record); false when a retry was queued or it failed for good.
	 */
	private static function record_refund( $wc_order, $refund, $core_ids ) {
		global $wpdb;
		$plans     = self::refund_plans( $wc_order, $refund, (array) $core_ids );
		$labels    = array_flip( self::seller_orders( $wc_order ) );
		$reason    = is_callable( array( $refund, 'get_reason' ) ) ? (string) $refund->get_reason() : '';
		$valid_all = 0;
		$requested = 0;
		$denied    = 0;
		foreach ( $plans as $core_id => $plan ) {
			$denied += $plan['denied_checked_in'];
			if ( ! $plan['ticket_ids'] && $plan['amount_minor'] <= 0 ) {
				continue;
			}
			$ids    = array_values( array_map( 'absint', $plan['ticket_ids'] ) );
			$valid  = $ids ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND status='valid'", array_merge( array( $wpdb->prefix . 'wpcap_tickets' ), $ids ) ) ) : 0; // phpcs:ignore WordPress.DB
			$result = call_user_func(
				array( '\\PassCaptain\\Refunds', 'record_external' ),
				$core_id,
				$plan['ticket_ids'],
				$plan['amount_minor'],
				'' !== $reason ? $reason : __( 'Erstattung in WooCommerce', 'pass-captain' ),
				'woocommerce-refund-' . $refund->get_id(),
				array(
					'source'             => 'woocommerce',
					'provider_reference' => 'woocommerce:' . $refund->get_id(),
				)
			);
			if ( is_wp_error( $result ) ) {
				// This seller order recorded nothing; the job queue repeats the sync (seller orders already
				// recorded are skipped by their idempotency key). Temporary database errors usually pass on
				// the next attempt; other errors are retried as well and end up on the system status page.
				$data = (array) $result->get_error_data();
				self::refund_failed( $wc_order, (int) $refund->get_id(), $result->get_error_message(), ! empty( $data['retryable'] ) );
				return false;
			}
			if ( isset( $labels[ $core_id ] ) ) {
				$wc_order->add_order_note(
					sprintf(
						/* translators: 1: seller order ID, 2: seller name, 3: amount, 4: ticket count */
						__( 'Pass Captain: Erstattung für Bestellung #%1$d (%2$s): %3$s, %4$d Ticket(s).', 'pass-captain' ),
						$core_id,
						SalesModel::seller_name( $labels[ $core_id ] ),
						number_format_i18n( (int) ( $result['amount_minor'] ?? $plan['amount_minor'] ) / ( 10 ** self::currency_decimals( $wc_order->get_currency() ) ), self::currency_decimals( $wc_order->get_currency() ) ) . ' ' . $wc_order->get_currency(),
						count( $plan['ticket_ids'] )
					)
				);
			}
			$valid_all += $valid;
			$requested += $plan['final'] ? count( $plan['ticket_ids'] ) : array_sum( $plan['quantities'] ) - $plan['denied_checked_in'];
		}
		self::note_refund( $wc_order, $valid_all, max( 0, $requested - $valid_all ) );
		if ( $denied ) {
			$wc_order->add_order_note(
				sprintf(
					/* translators: %d: ticket count */
					__( 'Pass Captain: %d erstattete(s) Ticket(s) waren bereits eingecheckt und wurden laut Einstellung nicht als erstattet markiert; der Betrag wurde gebucht.', 'pass-captain' ),
					$denied
				)
			);
		}
		$refund->update_meta_data( self::REFUND_META, 1 );
		$refund->save();
		return true;
	}

	/** Whether the core can record refunds executed outside Pass Captain. */
	public static function can_record_refunds() {
		return method_exists( '\\PassCaptain\\Refunds', 'record_external' );
	}

	/**
	 * Whether WooCommerce refunds may refund checked-in tickets. Uses the core setting
	 * `refund_after_checkin` when it exists, otherwise the WooCommerce option (default: no). Filter
	 * `wpcap_woocommerce_refund_checked_in` (bool $allowed, WC_Order|null $wc_order).
	 */
	public static function checked_in_refund_allowed( $wc_order = null ) {
		$core    = \PassCaptain\Settings::get( 'refund_after_checkin' );
		$allowed = null !== $core && '' !== $core
			? in_array( $core, array( true, 1, '1', 'yes', 'allow' ), true )
			: 'yes' === get_option( self::CHECKED_IN_OPTION, 'no' );
		return (bool) apply_filters( 'wpcap_woocommerce_refund_checked_in', $allowed, $wc_order );
	}

	/**
	 * Action woocommerce_create_refund (inside wc_create_refund(), before the refund is saved and before
	 * the gateway pays out): refuses a refund whose quantities can only be served by checked-in tickets
	 * while refunding checked-in tickets is not allowed. WooCommerce turns the exception into an error
	 * shown in the refund dialog; nothing is refunded. Amount-only refunds are never refused.
	 *
	 * @param object $refund WC_Order_Refund being created.
	 * @param array  $args   wc_create_refund() arguments (order_id, line_items[item_id][qty]).
	 * @throws \Exception When the refund would need checked-in tickets.
	 */
	public static function guard_refund( $refund, $args = array() ) {
		$args     = (array) $args;
		$order_id = absint( $args['order_id'] ?? ( is_object( $refund ) && is_callable( array( $refund, 'get_parent_id' ) ) ? $refund->get_parent_id() : 0 ) );
		$wc_order = $order_id ? wc_get_order( $order_id ) : null;
		$core_ids = $wc_order ? self::core_order_ids( $wc_order ) : array();
		if ( ! $core_ids || self::checked_in_refund_allowed( $wc_order ) ) {
			return;
		}
		$quantities = array();
		foreach ( (array) ( $args['line_items'] ?? array() ) as $item_id => $line ) {
			$qty  = absint( is_array( $line ) ? ( $line['qty'] ?? 0 ) : 0 );
			$item = $qty ? \WC_Order_Factory::get_order_item( absint( $item_id ) ) : null;
			$type = $item ? self::item_ticket_type_id( $item ) : 0;
			if ( $type ) {
				$quantities[ $type ] = ( $quantities[ $type ] ?? 0 ) + $qty;
			}
		}
		$owners = self::type_owners( $core_ids );
		foreach ( $quantities as $type_id => $qty ) {
			$core_id = $owners[ $type_id ] ?? 0;
			if ( ! $core_id ) {
				continue;
			}
			$open    = self::refundable_tickets( $core_id, $type_id );
			$usable  = count( array_filter( $open, static function ( $t ) {
				return 'checked_in' !== $t['status'];
			} ) );
			if ( $qty > $usable && count( $open ) > $usable ) {
				$type = self::ticket_type( $type_id );
				$name = $type ? sprintf( '%s – %s', $type->event_name, $type->name ) : '#' . $type_id;
				throw new \Exception(
					esc_html(
						sprintf(
							/* translators: 1: event and ticket name, 2: number of tickets that can be refunded */
							__( 'Pass Captain: Für „%1$s“ können nur %2$d nicht eingecheckte Tickets erstattet werden. Eingecheckte Tickets werden laut Einstellung nicht erstattet. Erstatte nur einen Betrag ohne Menge oder erlaube die Erstattung eingecheckter Tickets unter WooCommerce → Einstellungen → Produkte → Pass Captain.', 'pass-captain' ),
							$name,
							$usable
						)
					)
				);
			}
		}
	}

	/**
	 * Works out what a WooCommerce refund means for one Pass Captain order of the WooCommerce order.
	 * Kept for single-order callers; see refund_plans() for the rules.
	 *
	 * @return array{ticket_ids:int[], amount_minor:int, quantities:array<int,int>, final:bool, denied_checked_in:int}
	 */
	public static function refund_plan( $wc_order, $refund, $core_id ) {
		$core_ids = self::core_order_ids( $wc_order );
		$plans    = self::refund_plans( $wc_order, $refund, $core_ids ? $core_ids : array( $core_id ) );
		return $plans[ absint( $core_id ) ] ?? array( 'ticket_ids' => array(), 'amount_minor' => 0, 'quantities' => array(), 'final' => false, 'denied_checked_in' => 0 );
	}

	/**
	 * Maps a WooCommerce refund to the Pass Captain (seller) orders of the WooCommerce order.
	 *
	 * - Refund lines: each refunded ticket line belongs to the seller order that holds its ticket type.
	 *   Its quantity selects that many not yet refunded tickets there (valid first, then tickets revoked
	 *   by an event/occurrence cancellation, checked-in tickets last and only when allowed; newest first);
	 *   its gross amount (total + tax) goes to that seller order. Fee and shipping refunds stay with the shop.
	 * - Amount-only refund (no lines): the ticket share is refund × (sum of seller order totals / WooCommerce
	 *   order total), distributed over the seller orders in proportion to their remaining refundable
	 *   amount (largest remainder, seller order sequence breaks ties). No tickets are refunded.
	 * - The refund that leaves the WooCommerce order fully refunded covers all remaining (allowed) tickets
	 *   and the remaining amount of every seller order, so each ends up exactly refunded.
	 * - Every amount is capped at the seller order's remaining amount. Checked-in tickets that a quantity
	 *   would need while not allowed are counted in denied_checked_in (the amount is still booked).
	 *
	 * @param int[] $core_ids Pass Captain orders of the WooCommerce order.
	 * @return array<int,array{ticket_ids:int[], amount_minor:int, quantities:array<int,int>, final:bool, denied_checked_in:int}>
	 */
	public static function refund_plans( $wc_order, $refund, array $core_ids ) {
		$plans = array();
		$cores = array();
		foreach ( array_map( 'absint', $core_ids ) as $core_id ) {
			$core = Orders::get( $core_id );
			if ( $core ) {
				$cores[ $core_id ] = $core;
				$plans[ $core_id ] = array( 'ticket_ids' => array(), 'amount_minor' => 0, 'quantities' => array(), 'final' => false, 'denied_checked_in' => 0 );
			}
		}
		if ( ! $cores ) {
			return $plans;
		}
		$factor    = 10 ** self::currency_decimals( $wc_order->get_currency() );
		$remaining = array();
		foreach ( $cores as $core_id => $core ) {
			$remaining[ $core_id ] = max( 0, (int) $core->total_minor - (int) $core->refunded_minor );
		}
		$owners  = self::type_owners( array_keys( $cores ) );
		$allowed = self::checked_in_refund_allowed( $wc_order );
		$amounts = array_fill_keys( array_keys( $cores ), 0 );
		$lines   = false;
		foreach ( $refund->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $refund_item ) {
			$lines = true;
			if ( 'line_item' !== SellerSplit::item_kind( $refund_item ) || ! is_callable( array( $refund_item, 'get_total' ) ) ) {
				continue;
			}
			$type_id = self::refund_item_type_id( $refund_item );
			$core_id = $type_id ? ( $owners[ $type_id ] ?? 0 ) : 0;
			if ( $core_id ) {
				$amounts[ $core_id ] += (int) round( abs( (float) $refund_item->get_total() + (float) $refund_item->get_total_tax() ) * $factor );
			}
		}
		if ( self::is_final_refund( $wc_order, $refund ) ) {
			foreach ( $cores as $core_id => $core ) {
				$open = self::refundable_tickets( $core_id );
				$plans[ $core_id ]['final'] = true;
				foreach ( $open as $ticket ) {
					if ( 'checked_in' === $ticket['status'] && ! $allowed ) {
						++$plans[ $core_id ]['denied_checked_in'];
						continue;
					}
					$plans[ $core_id ]['ticket_ids'][] = $ticket['ticket_id'];
				}
				sort( $plans[ $core_id ]['ticket_ids'] );
				$plans[ $core_id ]['amount_minor'] = $remaining[ $core_id ];
			}
			foreach ( self::refund_type_quantities( $refund ) as $type_id => $qty ) {
				$core_id = $owners[ $type_id ] ?? 0;
				if ( $core_id ) {
					$plans[ $core_id ]['quantities'][ $type_id ] = $qty;
				}
			}
			return $plans;
		}
		if ( ! $lines ) {
			$refunded = is_callable( array( $refund, 'get_amount' ) ) ? (int) round( abs( (float) $refund->get_amount() ) * $factor ) : 0;
			$wc_total = is_callable( array( $wc_order, 'get_total' ) ) ? (int) round( (float) $wc_order->get_total() * $factor ) : 0;
			$cores_total = array_sum( array_map( static function ( $core ) {
				return (int) $core->total_minor;
			}, $cores ) );
			$share   = $wc_total > 0 ? (int) round( $refunded * min( 1, $cores_total / $wc_total ) ) : 0;
			$amounts = SellerSplit::distribute_refund( $share, $remaining );
		}
		foreach ( self::refund_type_quantities( $refund ) as $type_id => $qty ) {
			$core_id = $owners[ $type_id ] ?? 0;
			if ( ! $core_id ) {
				continue;
			}
			$plans[ $core_id ]['quantities'][ $type_id ] = $qty;
			$picked = 0;
			foreach ( self::refundable_tickets( $core_id, $type_id ) as $ticket ) {
				if ( $picked >= $qty ) {
					break;
				}
				if ( 'checked_in' === $ticket['status'] && ! $allowed ) {
					++$plans[ $core_id ]['denied_checked_in'];
					++$picked;
					continue;
				}
				$plans[ $core_id ]['ticket_ids'][] = $ticket['ticket_id'];
				++$picked;
			}
		}
		foreach ( $cores as $core_id => $core ) {
			$plans[ $core_id ]['amount_minor'] = min( $remaining[ $core_id ], (int) ( $amounts[ $core_id ] ?? 0 ) );
		}
		return $plans;
	}

	/**
	 * Not yet refunded tickets of a Pass Captain order (Orders::refundable_tickets(): valid, checked in,
	 * or revoked by a cancellation) with their status, in refund order: valid first, then revoked by a
	 * cancellation, then checked in; newest first within each group.
	 *
	 * @return array<int,array{ticket_id:int,ticket_type_id:int,status:string}>
	 */
	private static function refundable_tickets( $core_id, $type_id = 0 ) {
		global $wpdb;
		$rows = Orders::refundable_tickets( $core_id );
		$ids  = array();
		foreach ( $rows as $row ) {
			if ( ! $type_id || (int) $row->ticket_type_id === (int) $type_id ) {
				$ids[ (int) $row->ticket_id ] = (int) $row->ticket_type_id;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		$in       = implode( ',', array_keys( $ids ) );
		$statuses = $wpdb->get_results( "SELECT ticket_id,status FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id IN ($in)" ); // phpcs:ignore WordPress.DB
		$out      = array();
		$rank     = array( 'valid' => 0, 'revoked' => 1, 'checked_in' => 2 );
		foreach ( (array) $statuses as $row ) {
			$out[] = array( 'ticket_id' => (int) $row->ticket_id, 'ticket_type_id' => $ids[ (int) $row->ticket_id ], 'status' => (string) $row->status );
		}
		usort( $out, static function ( $a, $b ) use ( $rank ) {
			return array( $rank[ $a['status'] ] ?? 3, $b['ticket_id'] ) <=> array( $rank[ $b['status'] ] ?? 3, $a['ticket_id'] );
		} );
		return $out;
	}

	/**
	 * Which Pass Captain order holds a ticket type (split orders have each type in exactly one order).
	 *
	 * @param int[] $core_ids Pass Captain orders.
	 * @return array<int,int> ticket type ID => core order ID (first order wins).
	 */
	private static function type_owners( array $core_ids ) {
		global $wpdb;
		$core_ids = array_values( array_filter( array_map( 'absint', $core_ids ) ) );
		if ( ! $core_ids ) {
			return array();
		}
		$owners = array();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,ticket_type_id FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $core_ids ), '%d' ) ) . ') ORDER BY item_id', array_merge( array( $wpdb->prefix . 'wpcap_order_items' ), $core_ids ) ) ); // phpcs:ignore WordPress.DB
		$order  = array_flip( $core_ids );
		usort( $rows, static function ( $a, $b ) use ( $order ) {
			return ( $order[ (int) $a->order_id ] ?? 0 ) <=> ( $order[ (int) $b->order_id ] ?? 0 );
		} );
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $owners[ (int) $row->ticket_type_id ] ) ) {
				$owners[ (int) $row->ticket_type_id ] = (int) $row->order_id;
			}
		}
		return $owners;
	}

	/**
	 * Appends fiscal correction tasks for refunded non-ticket WooCommerce lines (goods, fees, shipping)
	 * to the seller documents of the Pass Captain order(s) (action `wpcap_woocommerce_non_ticket_refund`).
	 * Ticket refunds are recorded by the core refund ledger (wpcap_refund_completed). Idempotent per refund.
	 *
	 * @param int[] $core_ids Pass Captain orders of the WooCommerce order (one per seller when split).
	 */
	private static function record_fiscal_extra_refund( $wc_order, $refund, array $core_ids ) {
		if ( $refund->get_meta( '_wpcap_fiscal_extra_refund_processed', true ) ) {
			return;
		}
		$factor = 10 ** self::currency_decimals( $wc_order->get_currency() );
		$lines  = array();
		foreach ( $refund->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $refund_item ) {
			if ( self::refund_item_type_id( $refund_item ) ) {
				continue;
			}
			$original_id = absint( $refund_item->get_meta( '_refunded_item_id', true ) );
			$original    = $original_id && class_exists( 'WC_Order_Factory' ) ? \WC_Order_Factory::get_order_item( $original_id ) : null;
			$line_key    = $original ? self::fiscal_line_key( $original_id, $original ) : '';
			$total       = is_callable( array( $refund_item, 'get_total' ) ) ? abs( (float) $refund_item->get_total() ) : 0;
			$tax         = is_callable( array( $refund_item, 'get_total_tax' ) ) ? abs( (float) $refund_item->get_total_tax() ) : 0;
			$quantity    = is_callable( array( $refund_item, 'get_quantity' ) ) ? abs( (int) $refund_item->get_quantity() ) : 1;
			$lines[]     = array(
				'line_key'     => $line_key,
				'quantity'     => max( 1, $quantity ),
				'amount_minor' => (int) round( ( $total + $tax ) * $factor ),
				'tax_minor'    => (int) round( $tax * $factor ),
			);
		}
		if ( ! $lines && is_callable( array( $refund, 'get_amount' ) ) ) {
			$refund_minor     = (int) round( abs( (float) $refund->get_amount() ) * $factor );
			$core_total_minor = 0;
			foreach ( $core_ids as $core_id ) {
				$core_order        = Orders::get( absint( $core_id ) );
				$core_total_minor += $core_order ? absint( $core_order->total_minor ) : 0;
			}
			$wc_total_minor     = (int) round( abs( (float) $wc_order->get_total() ) * $factor );
			$ticket_share       = $wc_total_minor > 0 ? min( 1, $core_total_minor / $wc_total_minor ) : 0;
			$extra_refund_minor = max( 0, $refund_minor - (int) round( $refund_minor * $ticket_share ) );
			if ( $extra_refund_minor > 0 ) {
				// Amount-only WooCommerce refunds lack a source line; flag the seller split for manual allocation.
				$lines[] = array( 'line_key' => '', 'quantity' => 0, 'amount_minor' => $extra_refund_minor, 'tax_minor' => 0 );
			}
		}
		$refund->update_meta_data( '_wpcap_fiscal_extra_refund_processed', 1 );
		$refund->save();
		if ( $lines ) {
			do_action( 'wpcap_woocommerce_non_ticket_refund', array_values( array_map( 'absint', $core_ids ) ), absint( $refund->get_id() ), sanitize_text_field( $wc_order->get_currency() ), $lines );
		}
	}

	/** Stable key shared by the sale snapshot and its WooCommerce refund line. */
	private static function fiscal_line_key( $item_id, $item ) {
		$item_id = is_callable( array( $item, 'get_id' ) ) && $item->get_id() ? $item->get_id() : $item_id;
		return sanitize_key( 'woo_' . sanitize_key( get_class( $item ) ) . '_' . absint( $item_id ) );
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
		$note = sprintf( __( 'Pass Captain: %d Ticket(s) wegen Erstattung widerrufen.', 'pass-captain' ), $revoked );
		if ( $kept ) {
			/* translators: %d: ticket count */
			$note .= ' ' . sprintf( __( '%d erstattete(s) Ticket(s) waren bereits eingecheckt oder nicht mehr gültig und wurden nicht verändert.', 'pass-captain' ), $kept );
		}
		$wc_order->add_order_note( $note );
	}

	/**
	 * Full refund: record outstanding WooCommerce refunds (status "refunded" fires before
	 * woocommerce_order_refunded), then mark the core order refunded and revoke remaining tickets.
	 */
	public static function sync_fully_refunded_order( $wc_order_id ) {
		$core_ids = self::core_order_ids( $wc_order_id );
		if ( ! $core_ids ) {
			return;
		}
		$wc_order = wc_get_order( absint( $wc_order_id ) );
		if ( $wc_order && self::can_record_refunds() && is_callable( array( $wc_order, 'get_refunds' ) ) ) {
			foreach ( array_reverse( (array) $wc_order->get_refunds() ) as $refund ) {
				self::sync_refund( $wc_order_id, $refund->get_id() );
			}
		}
		foreach ( $core_ids as $core_id ) {
			$result = Orders::mark_refunded( $core_id, 'woocommerce_refunded' );
			if ( is_wp_error( $result ) ) {
				// Database error: nothing changed in Pass Captain; log it, queue a retry and tell the shop admin.
				\PassCaptain\Refunds::schedule_order_state( $core_id, 'woocommerce_refunded', $result->get_error_message() );
				if ( $wc_order ) {
					$wc_order->add_order_note( __( 'Pass Captain: Die Tickets konnten wegen eines Datenbankfehlers noch nicht widerrufen werden. Der Vorgang wird automatisch wiederholt.', 'pass-captain' ) );
				}
			}
		}
	}

	/** Adds a note to the WooCommerce order when one of its occurrences was cancelled. */
	public static function note_cancelled_occurrence( $core_order_id, $occurrence_id ) {
		$wc_order = self::wc_order_for_core( $core_order_id );
		if ( ! $wc_order ) {
			return;
		}
		$seller = array_search( absint( $core_order_id ), self::seller_orders( $wc_order ), true );
		if ( false !== $seller ) {
			$wc_order->add_order_note(
				sprintf(
					/* translators: 1: seller order ID, 2: seller name */
					__( 'Pass Captain: Ein Termin der Bestellung #%1$d (%2$s) wurde abgesagt; die Tickets sind widerrufen. Bitte diese Positionen in WooCommerce mit Menge erstatten – die Erstattung wird dieser Bestellung zugeordnet.', 'pass-captain' ),
					absint( $core_order_id ),
					SalesModel::seller_name( $seller )
				)
			);
			return;
		}
		$wc_order->add_order_note( __( 'Pass Captain: Ein Termin dieser Bestellung wurde abgesagt; die Tickets sind widerrufen. Bitte die Bestellung in WooCommerce erstatten.', 'pass-captain' ) );
	}

	/** WooCommerce order of a Pass Captain order (single order or one seller order of a split order). */
	public static function wc_order_for_core( $core_order_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		foreach ( array( self::ORDER_META, SellerSplit::LOOKUP_META ) as $key ) {
			$orders = wc_get_orders( array( 'limit' => 5, 'meta_key' => $key, 'meta_value' => absint( $core_order_id ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( (array) $orders as $order ) {
				// A copy of the meta (renewal, duplicated order) does not own the core order.
				if ( in_array( absint( $core_order_id ), self::core_order_ids( $order ), true ) ) {
					return $order;
				}
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Display and admin                                                   */
	/* ------------------------------------------------------------------ */

	/** Lists tickets on the thank-you page and in "My Account → Orders". */
	public static function render_customer_tickets( $wc_order ) {
		$core_ids = $wc_order instanceof \WC_Order ? self::core_order_ids( $wc_order ) : array();
		if ( ! $core_ids ) {
			return;
		}
		$tickets = array();
		foreach ( $core_ids as $core_id ) {
			$tickets = array_merge( $tickets, Orders::tickets_for_order( $core_id ) );
		}
		echo '<section class="wpcap-woo-tickets"><h2>' . esc_html__( 'Deine Tickets', 'pass-captain' ) . '</h2>';
		if ( ! $tickets ) {
			echo '<p>' . esc_html__( 'Deine Tickets werden nach bestätigter Zahlung per E-Mail zugestellt.', 'pass-captain' ) . '</p></section>';
			return;
		}
		echo '<p>' . esc_html__( 'Die Ticket-Links mit QR-Code haben wir dir per E-Mail gesendet.', 'pass-captain' ) . '</p>';
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
			add_meta_box( 'wpcap-woo-tickets', __( 'Pass Captain Tickets', 'pass-captain' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side' );
		}
	}

	/** Renders the admin meta box. */
	public static function render_meta_box( $post_or_order ) {
		$wc_order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		$core_ids = $wc_order ? self::core_order_ids( $wc_order ) : array();
		if ( ! $core_ids ) {
			echo '<p>' . esc_html__( 'Keine Tickets in dieser Bestellung.', 'pass-captain' ) . '</p>';
			return;
		}
		$sellers = array_flip( self::seller_orders( $wc_order ) );
		foreach ( $core_ids as $core_id ) {
			$core = Orders::get( $core_id );
			if ( $core ) {
				/* translators: 1: order ID, 2: status, 3: payment status */
				echo '<p>' . esc_html( sprintf( __( 'Pass Captain Bestellung #%1$d: %2$s / %3$s', 'pass-captain' ), $core_id, $core->status, $core->payment_status ) );
				if ( isset( $sellers[ $core_id ] ) ) {
					/* translators: %s: seller name */
					echo '<br>' . esc_html( sprintf( __( 'Rechnungssteller: %s', 'pass-captain' ), SalesModel::seller_name( $sellers[ $core_id ] ) ) );
				}
				echo '</p>';
				if ( 'needs_review' === $core->status ) {
					echo '<p><strong>' . esc_html__( 'Zahlung eingegangen, aber kein Kontingent mehr frei. Bitte Platz freigeben und „Tickets erneut ausstellen“ wählen oder erstatten.', 'pass-captain' ) . '</strong></p>';
				}
			}
			$tickets = Orders::tickets_for_order( $core_id );
			if ( $tickets ) {
				self::render_ticket_table( $tickets );
			}
		}
	}

	/** Adds order actions. */
	public static function order_actions( $actions, $wc_order = null ) {
		if ( $wc_order instanceof \WC_Order && $wc_order->get_meta( self::ORDER_META, true ) && current_user_can( 'wpcap_manage_orders' ) ) {
			$actions['wpcap_resend_tickets'] = __( 'Pass Captain: Tickets erneut senden (neue Codes)', 'pass-captain' );
			$actions['wpcap_retry_tickets']  = __( 'Pass Captain: Tickets erneut ausstellen', 'pass-captain' );
		}
		return $actions;
	}

	/** Order action: rotate codes and resend tickets. */
	public static function action_resend_tickets( $wc_order ) {
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) {
			return;
		}
		$result = true;
		foreach ( self::core_order_ids( $wc_order ) as $core_id ) {
			$sent   = Orders::resend_tickets( $core_id );
			$result = is_wp_error( $sent ) ? $sent : $result;
		}
		$wc_order->add_order_note( is_wp_error( $result ) ? sprintf( /* translators: %s: error */ __( 'Pass Captain: Erneuter Versand fehlgeschlagen – %s', 'pass-captain' ), $result->get_error_message() ) : __( 'Pass Captain: Tickets mit neuen Codes erneut gesendet.', 'pass-captain' ) );
	}

	/** Order action: retry issuance (e.g. after resolving a needs_review order). */
	public static function action_retry_tickets( $wc_order ) {
		if ( ! current_user_can( 'wpcap_manage_orders' ) ) {
			return;
		}
		if ( self::defers_issuance( $wc_order ) ) {
			$wc_order->add_order_note( __( 'Pass Captain: Bei dieser Zahlungsart werden die Tickets erst mit dem Status „Abgeschlossen“ ausgestellt.', 'pass-captain' ) );
		} elseif ( $wc_order->is_paid() ) {
			self::sync_paid_order( $wc_order->get_id(), true );
		} else {
			$wc_order->add_order_note( __( 'Pass Captain: Die Bestellung ist noch nicht bezahlt.', 'pass-captain' ) );
		}
	}

	/** Renders a ticket table (no codes – tokens are only stored as hashes). */
	private static function render_ticket_table( array $tickets ) {
		$labels = array(
			'valid'      => __( 'gültig', 'pass-captain' ),
			'checked_in' => __( 'eingecheckt', 'pass-captain' ),
			'revoked'    => __( 'widerrufen', 'pass-captain' ),
		);
		echo '<table class="shop_table wpcap-ticket-table"><thead><tr><th>' . esc_html__( 'Event', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ticket', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th></tr></thead><tbody>';
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
		return $wpdb->get_row( $wpdb->prepare( 'SELECT tt.*,o.starts_at,o.status AS occurrence_status,e.name AS event_name,e.status AS event_status FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', absint( $type_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Detects Store API (blocks) requests. */
	private static function is_store_api_request() {
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'is_store_api_request' ) ) ) {
			return WC()->is_store_api_request();
		}
		return false;
	}
}
