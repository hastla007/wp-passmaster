<?php
/**
 * Per-ticket participant fields in the WooCommerce checkout.
 *
 * Ticket types can require participant fields (wpcap_participant_fields). The native checkout
 * renders them per ticket; this class does the same for WooCommerce:
 *
 * - Classic (shortcode) checkout: fields after the order notes, validated in
 *   woocommerce_after_checkout_validation, stored on the order in woocommerce_checkout_create_order.
 * - Checkout block: the cart exposes the required fields through the Store API (extensions.wpcap),
 *   a small inner block (forced into the contact information step) renders them and sends the
 *   answers as checkout extension data; validated and stored in
 *   woocommerce_store_api_checkout_update_order_from_request.
 *
 * The answers are kept on the WooCommerce order only until the tickets are issued
 * (OrderAdapter::reserve passes them to Orders::create as `attendees`).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Attendees {
	const POST_KEY  = 'wpcap_attendees';
	const EXT_NS    = 'wpcap';
	const HANDLE    = 'wpcap-woo-attendees';

	/** Registers the checkout hooks. */
	public static function register() {
		// Classic checkout.
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic' ), 10, 2 );

		// Checkout block (Store API).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		}
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_store_api' ), 10, 2 );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_block_integration' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Shared logic                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Ticket types in $quantities that need participant data, with their active fields.
	 *
	 * @param array<int,int> $quantities ticket_type_id => quantity.
	 * @return array<int, array{ticket_type_id:int, label:string, quantity:int, fields:array}>
	 */
	public static function requirements( array $quantities ) {
		global $wpdb;
		$out = array();
		foreach ( $quantities as $type_id => $quantity ) {
			$type_id  = absint( $type_id );
			$quantity = absint( $quantity );
			if ( ! $type_id || ! $quantity ) {
				continue;
			}
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT field_key,label,field_type,required,purpose FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", $type_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$fields = array();
			foreach ( $rows as $row ) {
				$fields[] = array(
					'key'      => (string) $row->field_key,
					'label'    => (string) $row->label,
					'type'     => in_array( $row->field_type, array( 'text', 'email', 'textarea', 'checkbox' ), true ) ? $row->field_type : 'text',
					'required' => (bool) (int) $row->required,
					'purpose'  => (string) $row->purpose,
				);
			}
			/**
			 * Whether the WooCommerce checkout asks for per-ticket participant data (name, e-mail and
			 * the configured participant fields) for a ticket type. Default: only if fields exist.
			 *
			 * @param bool $collect Collect participant data.
			 * @param int  $type_id Ticket type ID.
			 */
			if ( ! apply_filters( 'wpcap_woocommerce_collect_attendees', (bool) $fields, $type_id ) ) {
				continue;
			}
			$type  = OrderAdapter::ticket_type( $type_id );
			$out[] = array(
				'ticket_type_id' => $type_id,
				'label'          => $type ? sprintf( '%s – %s', $type->event_name, $type->name ) : (string) $type_id,
				'quantity'       => $quantity,
				'fields'         => $fields,
			);
		}
		return $out;
	}

	/**
	 * Normalises raw input (ticket_type_id => list of {name, email, answers{field_key:value}}) into
	 * the `attendees` payload of Orders::create and validates it against the participant fields.
	 * Unknown types, surplus entries and unknown field keys are dropped.
	 *
	 * @param mixed          $raw        Raw input (array or JSON string).
	 * @param array<int,int> $quantities ticket_type_id => quantity.
	 * @return array|\WP_Error List of attendees or the first validation error.
	 */
	public static function normalize( $raw, array $quantities ) {
		if ( is_string( $raw ) ) {
			$raw = '' === $raw ? array() : json_decode( $raw, true );
		}
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::requirements( $quantities ) as $requirement ) {
			$type_id = $requirement['ticket_type_id'];
			$entries = isset( $raw[ $type_id ] ) && is_array( $raw[ $type_id ] ) ? array_values( $raw[ $type_id ] ) : array();
			$keys    = array_column( $requirement['fields'], 'key' );
			for ( $i = 0; $i < $requirement['quantity']; $i++ ) {
				$entry   = isset( $entries[ $i ] ) && is_array( $entries[ $i ] ) ? $entries[ $i ] : array();
				$answers = isset( $entry['answers'] ) && is_array( $entry['answers'] ) ? $entry['answers'] : array();
				$clean   = array();
				foreach ( $keys as $key ) {
					$value         = $answers[ $key ] ?? '';
					$clean[ $key ] = is_scalar( $value ) ? mb_substr( trim( (string) $value ), 0, 2000 ) : '';
				}
				$name  = isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? (string) $entry['name'] : '';
				$email = isset( $entry['email'] ) && is_scalar( $entry['email'] ) ? (string) $entry['email'] : '';
				$out[] = array(
					'ticket_type_id' => $type_id,
					'name'           => mb_substr( sanitize_text_field( $name ), 0, 190 ),
					'email'          => sanitize_email( $email ),
					'answers'        => $clean,
				);
			}
		}
		foreach ( $out as $entry ) {
			if ( '' !== $entry['email'] && ! is_email( $entry['email'] ) ) {
				return new \WP_Error( 'wpcap_attendee_email_invalid', __( 'Bitte eine gültige E-Mail-Adresse für die teilnehmende Person angeben oder das Feld leer lassen.', 'pass-captain' ) );
			}
		}
		$valid = Orders::validate_attendees( $quantities, $out );
		return is_wp_error( $valid ) ? $valid : $out;
	}

	/** Attendee payload stored on a WooCommerce order (empty if none). */
	public static function for_order( $wc_order ) {
		$stored = $wc_order ? $wc_order->get_meta( OrderAdapter::ATTENDEE_META, true ) : array();
		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/** Stores the attendee payload on the order (or removes stale data). */
	private static function store( $wc_order, array $attendees ) {
		if ( $attendees ) {
			$wc_order->update_meta_data( OrderAdapter::ATTENDEE_META, $attendees );
		} else {
			$wc_order->delete_meta_data( OrderAdapter::ATTENDEE_META );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Classic checkout                                                    */
	/* ------------------------------------------------------------------ */

	/** Renders one fieldset per ticket after the order notes. */
	public static function render_classic( $checkout = null ) {
		$requirements = self::requirements( OrderAdapter::cart_quantities() );
		if ( ! $requirements ) {
			return;
		}
		// Re-populate after a non-AJAX reload; nonce was checked by WooCommerce for that request.
		$posted = isset( $_POST[ self::POST_KEY ] ) && is_array( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( function_exists( 'wp_enqueue_style' ) && defined( 'WPCAP_URL' ) ) {
			wp_enqueue_style( self::HANDLE, WPCAP_URL . 'assets/woocommerce/attendees.css', array(), WPCAP_VERSION );
		}
		echo '<div class="wpcap-woo-attendees" id="wpcap-woo-attendees">';
		echo '<h3>' . esc_html__( 'Angaben zu den Teilnehmenden', 'pass-captain' ) . '</h3>';
		$number = 0;
		foreach ( $requirements as $requirement ) {
			$type_id = $requirement['ticket_type_id'];
			for ( $i = 0; $i < $requirement['quantity']; $i++ ) {
				++$number;
				$base  = self::POST_KEY . '[' . $type_id . '][' . $i . ']';
				$id    = 'wpcap_att_' . $type_id . '_' . $i;
				$value = static function ( $key ) use ( $posted, $type_id, $i ) {
					$entry = $posted[ $type_id ][ $i ] ?? array();
					$v     = in_array( $key, array( 'name', 'email' ), true ) ? ( $entry[ $key ] ?? '' ) : ( $entry['answers'][ $key ] ?? '' );
					return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
				};
				echo '<fieldset class="wpcap-woo-attendee"><legend>' . esc_html( sprintf( /* translators: 1: ticket number, 2: event and ticket name */ __( 'Ticket %1$d · %2$s', 'pass-captain' ), $number, $requirement['label'] ) ) . '</legend>';
				woocommerce_form_field( $base . '[name]', array( 'type' => 'text', 'id' => $id . '_name', 'label' => __( 'Name', 'pass-captain' ), 'autocomplete' => 'off', 'custom_attributes' => array( 'maxlength' => 190 ) ), $value( 'name' ) );
				woocommerce_form_field( $base . '[email]', array( 'type' => 'email', 'id' => $id . '_email', 'label' => __( 'E-Mail', 'pass-captain' ), 'autocomplete' => 'off' ), $value( 'email' ) );
				foreach ( $requirement['fields'] as $index => $field ) {
					woocommerce_form_field(
						$base . '[answers][' . $field['key'] . ']',
						array(
							'type'        => $field['type'],
							'id'          => $id . '_f' . $index,
							'label'       => $field['label'],
							'required'    => $field['required'],
							'description' => $field['purpose'],
							'value'       => 'checkbox' === $field['type'] ? '1' : null,
						),
						$value( $field['key'] )
					);
				}
				echo '</fieldset>';
			}
		}
		echo '</div>';
	}

	/** Validates the posted participant data (WooCommerce verified the checkout nonce). */
	public static function validate_classic( $data, $errors ) {
		$result = self::posted_classic();
		if ( is_wp_error( $result ) && $errors instanceof \WP_Error ) {
			$errors->add( 'wpcap_attendees', $result->get_error_message(), array( 'id' => 'wpcap-woo-attendees' ) );
		}
	}

	/** Stores the validated participant data on the new order. */
	public static function save_classic( $wc_order, $data = array() ) {
		$result = self::posted_classic();
		self::store( $wc_order, is_wp_error( $result ) ? array() : $result );
	}

	/** Normalised participant data of the current classic checkout POST. */
	private static function posted_classic() {
		$raw = isset( $_POST[ self::POST_KEY ] ) && is_array( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize() sanitises.
		return self::normalize( $raw, OrderAdapter::cart_quantities() );
	}

	/* ------------------------------------------------------------------ */
	/* Checkout block                                                      */
	/* ------------------------------------------------------------------ */

	/** Exposes the required fields on the cart and accepts the answers on checkout. */
	public static function register_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::EXT_NS,
				'data_callback'   => array( __CLASS__, 'cart_data' ),
				'schema_callback' => array( __CLASS__, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => self::EXT_NS,
				'schema_callback' => array( __CLASS__, 'checkout_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/** Cart extension data: ticket types that need participant data. */
	public static function cart_data() {
		return array( 'attendee_types' => self::requirements( OrderAdapter::cart_quantities() ) );
	}

	/** Schema of the cart extension data. */
	public static function cart_schema() {
		return array(
			'attendee_types' => array(
				'description' => __( 'Ticketarten, für die Angaben zu den Teilnehmenden nötig sind.', 'pass-captain' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/** Schema of the checkout extension data (JSON encoded answers). */
	public static function checkout_schema() {
		return array(
			'attendees' => array(
				'description' => __( 'Angaben zu den Teilnehmenden je Ticket (JSON).', 'pass-captain' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'maxLength'   => 200000,
			),
		);
	}

	/**
	 * Validates and stores the answers sent by the checkout block. Only the final POST is
	 * validated; intermediate updates of the draft order store whatever is valid.
	 */
	public static function save_store_api( $wc_order, $request ) {
		$extensions = is_object( $request ) || is_array( $request ) ? ( $request['extensions'] ?? array() ) : array();
		$raw        = is_array( $extensions ) ? ( $extensions[ self::EXT_NS ]['attendees'] ?? '' ) : '';
		$result     = self::normalize( $raw, OrderAdapter::order_quantities( $wc_order ) );
		$is_submit  = is_object( $request ) && method_exists( $request, 'get_method' ) ? 'POST' === $request->get_method() : true;
		if ( is_wp_error( $result ) ) {
			self::store( $wc_order, array() );
			if ( $is_submit ) {
				if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wpcap_attendees_invalid', esc_html( $result->get_error_message() ), 400 );
				}
				throw new \Exception( esc_html( $result->get_error_message() ) );
			}
			return;
		}
		self::store( $wc_order, $result );
	}

	/** Registers the checkout block integration that loads the field renderer. */
	public static function register_block_integration( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) && interface_exists( '\\Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) ) {
			$registry->register( new BlocksIntegration() );
		}
	}

	/** Strings and settings for the block script. */
	public static function script_data() {
		return array(
			'i18n' => array(
				'title'        => __( 'Angaben zu den Teilnehmenden', 'pass-captain' ),
				/* translators: 1: ticket number, 2: event and ticket name */
				'ticket'       => __( 'Ticket %1$d · %2$s', 'pass-captain' ),
				'name'         => __( 'Name', 'pass-captain' ),
				'email'        => __( 'E-Mail', 'pass-captain' ),
				'optional'     => __( '(optional)', 'pass-captain' ),
				/* translators: %s: field label */
				'required'     => __( 'Bitte „%s“ ausfüllen.', 'pass-captain' ),
				'invalidEmail' => __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'pass-captain' ),
			),
		);
	}
}
