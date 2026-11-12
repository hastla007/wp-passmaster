<?php
/** Public checkout and authenticated scanner REST routes. */
namespace PassCaptain\Rest;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Memberships;
use PassCaptain\TicketAssignments;
use PassCaptain\Seats;
use PassCaptain\ServiceBookings;
use PassCaptain\Invoices;
use PassCaptain\Campaigns;
use PassCaptain\Waitlists;
use PassCaptain\DeveloperKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Controller {
	public static function register_routes() {
		register_rest_route( 'wp-pass-captain/v1', '/events', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'events' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/checkout', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'checkout' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'email' => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'items' => array( 'required' => true, 'type' => 'array' ),
			),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'order_status' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/transfer', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'transfer_ticket' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/resend', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'resend_tickets' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/assign', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'assign_ticket' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/billing', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'update_billing' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/invoice', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'issue_invoice' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/tickets/validate', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'validate_ticket' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
			'args' => array( 'token' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/tickets/check-in', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'check_in' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
			'args' => array( 'token' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/tickets/offline-sync', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'offline_sync' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/pos/sales', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'pos_sale' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/passes/redeem', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'redeem_pass' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/member-passes', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'issue_member_pass' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/member-passes/(?P<pass_id>\d+)/revoke', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'revoke_member_pass' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/seats', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'seats' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/seat-maps', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_seat_map' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/seat-holds', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'hold_seats' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/service-slots', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'service_slots' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/resources', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_resource' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/resources/(?P<resource_id>\d+)/slots', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_service_slot' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/service-slots/(?P<slot_id>\d+)/bookings', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'book_service_slot' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/occurrences/(?P<occurrence_id>\d+)/admission-days', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'set_admission_days' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/campaigns', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_campaign' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/payments/(?P<provider>[a-z0-9_-]+)/webhook', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( \PassCaptain\Payments\Webhooks::class, 'handle' ),
			// Authenticity is established by the provider signature check in verify_webhook().
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/checkout/options', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'checkout_options' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/waitlist', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'join_waitlist' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/waitlist/offer', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'offer_waitlist' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/waitlist/(?P<waitlist_id>\d+)/accept', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'accept_waitlist_offer' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/waitlist/(?P<waitlist_id>\d+)/decline', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'decline_waitlist_offer' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/waitlist/entries', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'list_waitlist' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/reports/summary', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'event_report' ),
			'permission_callback' => array( __CLASS__, 'report_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/developer/keys', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_developer_key' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/developer/keys/(?P<key_id>\d+)/revoke', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'revoke_developer_key' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/developer/events/(?P<event_id>\d+)', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'developer_event' ),
			'permission_callback' => array( __CLASS__, 'developer_key_permission' ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/events/(?P<event_id>\d+)/translations', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_event_translation' ), 'permission_callback' => '__return_true' ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_event_translation' ), 'permission_callback' => array( __CLASS__, 'manage_events_permission' ) ),
		) );
		register_rest_route( 'wp-pass-captain/v1', '/tax-profiles', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'save_tax_profile' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );
	}

	public static function events( \WP_REST_Request $request ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.slug,e.description,o.occurrence_id,o.starts_at,o.ends_at,o.timezone,o.location,o.capacity FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 100",
			current_time( 'mysql', true )
		) );
		$output = array();
		$locale = sanitize_text_field( $request->get_param( 'locale' ) ?: determine_locale() );
		foreach ( $rows as $row ) {
			$translation = $wpdb->get_row( $wpdb->prepare( "SELECT name,description,location FROM {$wpdb->prefix}wpcap_event_translations WHERE event_id=%d AND locale=%s", $row->event_id, $locale ) );
			$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,sales_start,sales_end FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id ASC", $row->occurrence_id ), ARRAY_A );
			$output[] = array(
				'event_id' => (int) $row->event_id,
				'name' => $translation ? $translation->name : $row->name,
				'slug' => $row->slug,
				'description' => wp_strip_all_tags( $translation ? $translation->description : $row->description ),
				'occurrence_id' => (int) $row->occurrence_id,
				'starts_at' => $row->starts_at,
				'ends_at' => $row->ends_at,
				'timezone' => $row->timezone,
				'location' => $translation && $translation->location ? $translation->location : $row->location,
				'tickets' => array_map( static function ( $type ) { return array(
					'ticket_type_id' => (int) $type['ticket_type_id'],
					'name' => $type['name'],
					// Effective (tier) price; regular_price_minor is the stored list price.
					'price_minor' => \PassCaptain\Orders::unit_price( (object) $type ),
					'regular_price_minor' => (int) $type['price_minor'],
					'price_label' => \PassCaptain\Orders::price_label( (object) $type ),
					'currency' => $type['currency'],
					'max_per_order' => (int) $type['max_per_order'],
				); }, $types ),
			);
		}
		return rest_ensure_response( $output );
	}

	public static function checkout( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: $request->get_params();
		$params = is_array( $params ) ? $params : array();
		// Only customer fields; trusted adapter options must never come from a public request.
		$payload = array_intersect_key( $params, array_flip( apply_filters( 'wpcap_checkout_payload_keys', array( 'email', 'items', 'idempotency_key', 'campaign_codes', 'attendees', 'accept_terms', 'voucher_code', 'billing' ) ) ) );
		$provider_id = sanitize_key( $params['provider'] ?? 'manual' );
		$gateway = \PassCaptain\Payments\Registry::get( $provider_id );
		$enabled = (array) \PassCaptain\Settings::get( 'enabled_providers' );
		if ( ! $gateway || ! in_array( $provider_id, $enabled, true ) ) {
			return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$hold = 'manual' === $provider_id ? max( 1, (int) \PassCaptain\Settings::get( 'manual_hold_hours' ) ) * 60 : max( 30, (int) \PassCaptain\Settings::get( 'hold_minutes' ) ); // Hosted payment pages (e.g. Stripe) live at least 30 minutes.
		// Same wording as the form field ("Rabattcode"); the field is derived from the message below.
		$campaign_messages = static function ( $messages ) {
			$messages['campaign_invalid'] = __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'wp-pass-captain' );
			$messages['campaign_redeem_failed'] = __( 'Der Rabattcode konnte nicht sicher auf die Bestellung angewendet werden. Bitte versuche es erneut.', 'wp-pass-captain' );
			return $messages;
		};
		add_filter( 'wpcap_checkout_error_messages', $campaign_messages, 5 );
		$result = Orders::create( $payload, array( 'payment_provider' => $provider_id, 'hold_minutes' => $hold ) );
		remove_filter( 'wpcap_checkout_error_messages', $campaign_messages, 5 );
		if ( is_wp_error( $result ) ) { return self::with_error_field( $result, $campaign_messages( array() ) ); }
		$voucher_code = sanitize_text_field( $params['voucher_code'] ?? '' );
		if ( '' !== $voucher_code && 'pending' === ( $result['payment_status'] ?? '' ) && empty( $result['reused'] ) ) {
			$voucher = \PassCaptain\Integrations\Vouchers\Checkout::apply( $result['order_id'], $voucher_code );
			if ( is_wp_error( $voucher ) ) {
				Orders::cancel_order( $result['order_id'], 'voucher_rejected' );
				return self::with_error_field( $voucher );
			}
			$fresh = Orders::get( $result['order_id'] );
			$result['total_minor'] = (int) $fresh->total_minor;
			$result['voucher_minor'] = (int) $voucher['applied_minor'];
			$result['status'] = $fresh->status;
			$result['payment_status'] = $fresh->payment_status;
		}
		if ( 'pending' === ( $result['payment_status'] ?? '' ) ) {
			$order = (array) Orders::get( $result['order_id'] );
			if ( ! $gateway->is_available( $order['currency'] ) ) {
				Orders::cancel_order( $result['order_id'], 'provider_unavailable_for_currency' );
				return new \WP_Error( 'wpcap_provider_currency', __( 'Diese Zahlungsart unterstützt die Währung nicht.', 'wp-pass-captain' ), array( 'status' => 400 ) );
			}
			$items = array_map( static function ( $row ) { return (array) $row; }, Orders::items_for_order( $result['order_id'] ) );
			$payment = $gateway->create_payment( $order, $items );
			if ( is_wp_error( $payment ) ) {
				Orders::cancel_order( $result['order_id'], 'payment_start_failed' );
				return new \WP_Error( $payment->get_error_code(), $payment->get_error_message(), array( 'status' => 502 ) );
			}
			if ( ! empty( $payment['provider_reference'] ) ) {
				Orders::set_provider_reference( $result['order_id'], $payment['provider_reference'] );
			}
			$result['payment'] = array_intersect_key( $payment, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
			if ( \PassCaptain\Payments\BankTransfer::applies( $order ) ) {
				// Bank details for the confirmation panel (same data as the access-link mail).
				$result['payment']['bank_transfer'] = \PassCaptain\Payments\BankTransfer::details( $order );
			}
		}
		$fresh = Orders::get( $result['order_id'] );
		if ( $fresh ) {
			$result['total_minor'] = (int) $fresh->total_minor;
			$result['currency'] = $fresh->currency;
			$result['total_formatted'] = \PassCaptain\Frontend\Format::money( (int) $fresh->total_minor, $fresh->currency );
		}
		// Short human-readable order number; the full id and the access link only travel by e-mail.
		$result['reference'] = \PassCaptain\Frontend\Format::order_reference( $result['public_id'] ?? '' );
		return new \WP_REST_Response( $result, empty( $result['reused'] ) ? 201 : 200 );
	}

	/**
	 * Adds data.field (the checkout form field the error belongs to) so the form can mark the field.
	 *
	 * @param string[] $campaign_messages Messages that belong to the discount code field.
	 */
	public static function with_error_field( \WP_Error $error, array $campaign_messages = array() ) {
		$code = $error->get_error_code();
		$map = array(
			'wpcap_invalid_checkout' => 'email',
			'wpcap_terms_required' => 'accept_terms',
			'wpcap_attendees_invalid' => 'attendees',
			'wpcap_attendee_field_required' => 'attendees',
			'wpcap_attendee_field_invalid' => 'attendees',
			'wpcap_invalid_item' => 'items',
			'wpcap_provider_unavailable' => 'provider',
			'wpcap_provider_currency' => 'provider',
		);
		$field = $map[ $code ] ?? '';
		if ( '' === $field && 0 === strpos( $code, 'wpcap_campaign_' ) ) { $field = 'campaign_code'; }
		if ( '' === $field && 0 === strpos( $code, 'wpcap_voucher_' ) ) { $field = 'voucher_code'; }
		if ( '' === $field && 'wpcap_checkout_failed' === $code && in_array( $error->get_error_message(), $campaign_messages, true ) ) { $field = 'campaign_code'; }
		$field = (string) apply_filters( 'wpcap_checkout_error_field', $field, $error );
		if ( '' === $field ) { return $error; }
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array( 'status' => 400 );
		$data['field'] = sanitize_key( $field );
		return new \WP_Error( $code, $error->get_error_message(), $data );
	}

	/** Public checkout configuration: enabled payment methods and legal texts. */
	public static function checkout_options( \WP_REST_Request $request ) {
		$currency = strtoupper( sanitize_text_field( $request->get_param( 'currency' ) ?: 'EUR' ) );
		$providers = array();
		foreach ( \PassCaptain\Payments\Registry::enabled( $currency ) as $id => $gateway ) { $providers[] = array( 'id' => $id, 'title' => $gateway->title() ); }
		return rest_ensure_response( array(
			'providers' => $providers,
			'require_terms' => (bool) \PassCaptain\Settings::get( 'require_terms' ),
			'terms_text' => wp_kses_post( (string) \PassCaptain\Settings::get( 'terms_text' ) ),
			'consumer_info_text' => wp_kses_post( (string) \PassCaptain\Settings::get( 'consumer_info_text' ) ),
			'terms_version' => \PassCaptain\Settings::terms_version(),
		) );
	}

	public static function order_status( \WP_REST_Request $request ) {
		global $wpdb;
		$public_id = sanitize_text_field( $request['public_id'] );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'access_token' ) ) );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT public_id,status,payment_status,currency,total_minor,created_at,access_token_hash,access_expires_at FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ), ARRAY_A );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_not_found', __( 'Bestellung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! $order['access_expires_at'] || $order['access_expires_at'] <= current_time( 'mysql', true ) || 64 !== strlen( $access_token ) || ! hash_equals( $order['access_token_hash'], hash( 'sha256', $access_token ) ) ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		unset( $order['access_token_hash'] );
		$order['total_minor'] = (int) $order['total_minor'];
		return rest_ensure_response( $order );
	}

	public static function transfer_ticket( \WP_REST_Request $request ) {
		$result = Orders::transfer_ticket(
			$request['public_id'],
			$request->get_param( 'access_token' ),
			$request->get_param( 'ticket_public_id' ),
			$request->get_param( 'recipient_email' )
		);
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	/** Order row when the public id and the access token match (null otherwise). */
	private static function order_with_access( $public_id, $access_token ) {
		global $wpdb;
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $access_token ) );
		if ( 64 !== strlen( $access_token ) ) { return null; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", sanitize_text_field( (string) $public_id ) ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) { return null; }
		return $order;
	}

	/** Order portal: send the tickets again (new codes). At most 3 per order and hour. */
	public static function resend_tickets( \WP_REST_Request $request ) {
		$public_id = sanitize_text_field( (string) $request['public_id'] );
		if ( ! \PassCaptain\RateLimit::allow( 'portal-resend-client|' . \PassCaptain\RateLimit::client(), 20, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$order = self::order_with_access( $public_id, $request->get_param( 'access_token' ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$limit = max( 1, (int) apply_filters( 'wpcap_portal_resend_limit', 3 ) );
		if ( ! \PassCaptain\RateLimit::allow( 'portal-resend|' . $order->public_id, $limit, HOUR_IN_SECONDS ) ) {
			/* translators: %d: allowed resends per hour */
			return new \WP_Error( 'wpcap_resend_rate_limited', sprintf( _n( 'Die Tickets können höchstens %d-mal pro Stunde erneut gesendet werden. Bitte versuche es später noch einmal.', 'Die Tickets können höchstens %d-mal pro Stunde erneut gesendet werden. Bitte versuche es später noch einmal.', $limit, 'wp-pass-captain' ), $limit ), array( 'status' => 429 ) );
		}
		$result = Orders::resend_tickets( (int) $order->order_id );
		if ( is_wp_error( $result ) ) {
			$status = in_array( $result->get_error_code(), array( 'wpcap_mail_failed', 'wpcap_ticket_rotation_failed' ), true ) ? 502 : 409;
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		return rest_ensure_response( array( 'status' => 'sent', 'email' => \PassCaptain\Frontend\Format::mask_email( $order->customer_email ) ) );
	}

	/** Order portal: buyer corrects the billing data (company/address/VAT ID) until the invoice is issued. */
	public static function update_billing( \WP_REST_Request $request ) {
		if ( ! \PassCaptain\RateLimit::allow( 'portal-billing|' . \PassCaptain\RateLimit::client(), 30, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$order = self::order_with_access( sanitize_text_field( (string) $request['public_id'] ), $request->get_param( 'access_token' ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$input = $request->get_param( 'billing' );
		$result = \PassCaptain\Billing::update_for_order( $order, is_array( $input ) ? $input : array( 'enabled' => false ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function assign_ticket( \WP_REST_Request $request ) {
		$result = TicketAssignments::assign( $request['public_id'], $request->get_param( 'access_token' ), $request->get_param( 'ticket_public_id' ), $request->get_param( 'attendee_name' ), $request->get_param( 'attendee_email' ), is_array( $request->get_param( 'answers' ) ) ? $request->get_param( 'answers' ) : null );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function issue_invoice( \WP_REST_Request $request ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", sanitize_text_field( $request['public_id'] ) ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_not_found', __( 'Bestellung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", $order->order_id ) );
		foreach ( $event_ids as $event_id ) { if ( ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_invoice_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'wp-pass-captain' ), array( 'status' => 403 ) ); } }
		$result = Invoices::issue_for_order( $order->order_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function scanner_permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_checkin' );
	}

	public static function pos_permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_manage_orders' );
	}

	public static function pos_sale( \WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		$items = $payload['items'] ?? array();
		if ( ! is_array( $items ) || ! $items || count( $items ) > 10 || '' === sanitize_text_field( $payload['idempotency_key'] ?? '' ) ) { return new \WP_Error( 'wpcap_pos_items', __( 'POS-Verkaufspositionen oder Idempotency-Key sind ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		global $wpdb;
		foreach ( $items as $item ) {
			$type_id = absint( $item['ticket_type_id'] ?? 0 );
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $type_id ) );
			if ( ! $event_id || ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_pos_forbidden', __( 'Für dieses Event fehlt die POS-Berechtigung.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		}
		$result = Orders::create( array( 'email' => $payload['email'] ?? '', 'items' => $items, 'idempotency_key' => sanitize_text_field( $payload['idempotency_key'] ) ), array( 'key_namespace' => 'pos', 'skip_rate_limit' => true, 'source' => 'pos' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( 'pending' === ( $result['payment_status'] ?? '' ) ) {
			$confirmed = Orders::confirm_manual_payment( $result['order_id'] );
			if ( is_wp_error( $confirmed ) ) { return $confirmed; }
		}
		$order = Orders::get( $result['order_id'] );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_pos_not_paid', __( 'Dieser Idempotency-Key gehört zu einer nicht bezahlten Bestellung; bitte einen neuen Verkauf starten.', 'wp-pass-captain' ), array( 'status' => 409, 'status_code' => $order ? $order->status : '' ) );
		}
		return new \WP_REST_Response( array( 'order_id' => (int) $order->order_id, 'public_id' => $order->public_id, 'status' => $order->status, 'payment_status' => $order->payment_status ), empty( $result['reused'] ) ? 201 : 200 );
	}

	public static function offline_sync( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$tokens = $payload['tokens'] ?? array();
		if ( ! is_array( $tokens ) || ! $tokens || count( $tokens ) > 100 ) { return new \WP_Error( 'wpcap_offline_batch_invalid', __( 'Der Offline-Scanbatch muss 1 bis 100 Codes enthalten.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$results = array();
		foreach ( $tokens as $raw_token ) {
			$scan = new \WP_REST_Request( 'POST' );
			$scan->set_param( 'token', sanitize_text_field( $raw_token ) );
			$scan->set_param( 'source', 'offline' );
			$result = self::check_in( $scan );
			$results[] = is_wp_error( $result ) ? array( 'status' => 'conflict', 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) : $result->get_data();
		}
		return rest_ensure_response( array( 'results' => $results, 'offline_conflicts_are_possible' => true ) );
	}

	public static function redeem_pass( \WP_REST_Request $request ) {
		$type_id = absint( $request->get_param( 'ticket_type_id' ) );
		global $wpdb;
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $type_id ) );
		if ( ! $event_id || ! Access::can_access_event( $event_id, 'wpcap_checkin' ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Für dieses Event fehlt die Einlassberechtigung.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$result = Memberships::redeem( $request->get_param( 'token' ), $type_id, $request->get_param( 'idempotency_key' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function issue_member_pass( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$rules = $payload['rules'] ?? array();
		if ( ! is_array( $rules ) || ! $rules ) { return new \WP_Error( 'wpcap_pass_rules_invalid', __( 'Mindestens eine Passregel ist erforderlich.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		foreach ( $rules as $rule ) {
			$event_id = absint( $rule['event_id'] ?? 0 );
			if ( ( $event_id && ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) || ( ! $event_id && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für eine Passregel.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		}
		$result = Memberships::issue( $payload['email'] ?? '', $payload['valid_from'] ?? '', $payload['valid_until'] ?? '', $rules );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'pass_id' => (int) $result['pass_id'], 'status' => 'issued' ), 201 );
	}

	public static function revoke_member_pass( \WP_REST_Request $request ) {
		global $wpdb;
		$pass_id = absint( $request['pass_id'] );
		$events = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d", $pass_id ) );
		if ( ! $events ) { return new \WP_Error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		foreach ( $events as $event_id ) {
			if ( ( (int) $event_id && ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) || ( ! (int) $event_id && ! current_user_can( 'manage_options' ) ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		}
		$result = Memberships::revoke( $pass_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'status' => 'revoked' ) );
	}

	public static function seats( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		$occurrence_id = absint( $request->get_param( 'occurrence_id' ) );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) );
		if ( ! $event || 'published' !== $event->status ) { return new \WP_Error( 'wpcap_seat_map_not_found', __( 'Sitzplan nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$valid_occurrence = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d AND event_id=%d AND status='scheduled'", $occurrence_id, $event_id ) );
		if ( ! $valid_occurrence ) { return new \WP_Error( 'wpcap_occurrence_not_found', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$map = $wpdb->get_row( $wpdb->prepare( "SELECT map_id,version,name FROM {$wpdb->prefix}wpcap_seat_maps WHERE event_id=%d AND status='published' ORDER BY version DESC LIMIT 1", $event_id ) );
		if ( ! $map ) { return rest_ensure_response( array( 'map' => null, 'seats' => array() ) ); }
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_seat_holds SET status='expired' WHERE status='active' AND expires_at<=%s", current_time( 'mysql', true ) ) );
		$seats = $wpdb->get_results( $wpdb->prepare( "SELECT s.seat_id,s.seat_key,s.zone,s.row_label,s.seat_label,s.position_x,s.position_y,IF(s.status='available' AND NOT EXISTS(SELECT 1 FROM {$wpdb->prefix}wpcap_seat_holds h WHERE h.seat_id=s.seat_id AND h.occurrence_id=%d AND h.status IN ('active','sold')), 'available', 'unavailable') AS availability FROM {$wpdb->prefix}wpcap_seats s WHERE s.map_id=%d ORDER BY s.zone,s.row_label,s.seat_label", $occurrence_id, $map->map_id ), ARRAY_A );
		return rest_ensure_response( array( 'map' => array( 'version' => (int) $map->version, 'name' => $map->name ), 'seats' => $seats ) );
	}

	public static function manage_events_permission() { return is_user_logged_in() && current_user_can( 'wpcap_manage_events' ); }

	public static function create_campaign( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_campaign_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$payload = (array) $request->get_json_params();
		$payload['event_id'] = $event_id;
		if ( ! empty( $payload['ticket_type_id'] ) ) {
			global $wpdb;
			$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d AND o.event_id=%d", absint( $payload['ticket_type_id'] ), $event_id ) );
			if ( ! $valid ) { return new \WP_Error( 'wpcap_campaign_scope', __( 'Die Ticketart gehört nicht zu diesem Event.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		}
		$result = Campaigns::create( $payload );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	public static function join_waitlist( \WP_REST_Request $request ) {
		$result = Waitlists::join( $request['event_id'], $request->get_param( 'occurrence_id' ), $request->get_param( 'ticket_type_id' ), $request->get_param( 'email' ), $request->get_param( 'quantity' ) ?? 1 );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function offer_waitlist( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		$type_id = absint( $request->get_param( 'ticket_type_id' ) );
		global $wpdb;
		$type_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $type_id ) );
		if ( $type_event !== $event_id || ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_waitlist_forbidden', __( 'Keine Berechtigung für diese Ticketart.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$result = Waitlists::offer_next( $event_id, $type_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function accept_waitlist_offer( \WP_REST_Request $request ) {
		$result = Waitlists::accept_offer( $request['waitlist_id'], $request->get_param( 'token' ), array( 'provider' => sanitize_key( (string) $request->get_param( 'provider' ) ), 'accept_terms' => ! empty( $request->get_param( 'accept_terms' ) ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function decline_waitlist_offer( \WP_REST_Request $request ) {
		$result = Waitlists::decline_offer( $request['waitlist_id'], $request->get_param( 'token' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function list_waitlist( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_waitlist_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$rows = array_map( static function ( $row ) {
			return array( 'waitlist_id' => (int) $row->waitlist_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'occurrence_id' => (int) $row->occurrence_id, 'email' => (string) $row->email, 'position' => (int) $row->position, 'queue_rank' => (int) $row->queue_rank, 'quantity' => (int) $row->quantity, 'priority' => (int) $row->priority, 'status' => (string) $row->status, 'offer_expires_at' => $row->offer_expires_at, 'order_id' => $row->order_public_id ? (string) $row->order_public_id : null );
		}, Waitlists::entries( $event_id ) );
		return rest_ensure_response( $rows );
	}

	public static function report_permission( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		return Access::can_access_event( $event_id, 'wpcap_view_reports' ) || false !== DeveloperKeys::authorize( $request, 'reports.read', $event_id );
	}

	public static function event_report( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		$from = sanitize_text_field( $request->get_param( 'from' ) ?: gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );
		$to = sanitize_text_field( $request->get_param( 'to' ) ?: gmdate( 'Y-m-d' ) );
		$from_parts = array_map( 'intval', explode( '-', $from ) );
		$to_parts = array_map( 'intval', explode( '-', $to ) );
		$valid_dates = 3 === count( $from_parts ) && 3 === count( $to_parts ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && checkdate( $from_parts[1], $from_parts[2], $from_parts[0] ) && checkdate( $to_parts[1], $to_parts[2], $to_parts[0] );
		if ( ! $valid_dates || $from > $to || strtotime( $to ) - strtotime( $from ) > 90 * DAY_IN_SECONDS ) { return new \WP_Error( 'wpcap_report_range', __( 'Berichtszeitraum muss gültig und höchstens 90 Tage lang sein.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$key = 'wpcap_report_' . $event_id . '_' . md5( $from . '|' . $to );
		$cached = get_transient( $key );
		if ( false !== $cached ) { return rest_ensure_response( $cached ); }
		$sales = $wpdb->get_results( $wpdb->prepare( "SELECT ord.currency,COUNT(DISTINCT ord.order_id) AS orders_count,COALESCE(SUM(oi.quantity),0) AS tickets_sold,COALESCE(SUM(oi.line_total_minor),0) AS gross_minor FROM {$wpdb->prefix}wpcap_orders ord INNER JOIN {$wpdb->prefix}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.payment_status='paid' AND ord.status<>'refunded' AND ord.created_at >= %s AND ord.created_at < DATE_ADD(%s,INTERVAL 1 DAY) GROUP BY ord.currency", $event_id, $from . ' 00:00:00', $to . ' 00:00:00' ), ARRAY_A );
		$checkins = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_checkins WHERE event_id=%d AND result='checked_in' AND created_at >= %s AND created_at < DATE_ADD(%s,INTERVAL 1 DAY)", $event_id, $from . ' 00:00:00', $to . ' 00:00:00' ) );
		$currency_totals = array();
		foreach ( $sales as $sale ) { $currency_totals[] = array( 'currency' => $sale['currency'], 'orders_count' => (int) $sale['orders_count'], 'tickets_sold' => (int) $sale['tickets_sold'], 'gross_minor' => (int) $sale['gross_minor'] ); }
		$data = array( 'event_id' => $event_id, 'from' => $from, 'to' => $to, 'sales_by_currency' => $currency_totals, 'checkins' => $checkins, 'financial_basis' => 'gross_line_totals_before_order_discounts_tax_and_refunds' );
		set_transient( $key, $data, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $data );
	}

	public static function create_developer_key( \WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		$event_id = absint( $payload['event_id'] ?? 0 );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! empty( $payload['expires_at'] ) && ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $payload['expires_at'] ) || strtotime( $payload['expires_at'] . ' UTC' ) <= time() ) ) { return new \WP_Error( 'wpcap_api_key_expiry', __( 'Ablaufdatum muss in der Zukunft liegen.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$result = DeveloperKeys::issue( $event_id, (array) ( $payload['scopes'] ?? array() ), $payload['label'] ?? '', $payload['expires_at'] ?? '' );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	public static function revoke_developer_key( \WP_REST_Request $request ) {
		$result = DeveloperKeys::revoke( $request['key_id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'status' => 'revoked' ) );
	}

	public static function developer_key_permission( \WP_REST_Request $request ) {
		return DeveloperKeys::authorize( $request, 'events.read', absint( $request['event_id'] ) ) !== false;
	}

	public static function developer_event( \WP_REST_Request $request ) {
		global $wpdb;
		// Authorised (and rate limited) once in developer_key_permission().
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name,slug,description,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $request['event_id'] ) ), ARRAY_A );
		return $event ? rest_ensure_response( $event ) : new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
	}

	public static function get_event_translation( \WP_REST_Request $request ) {
		global $wpdb;
		$locale = sanitize_text_field( $request->get_param( 'locale' ) ?: determine_locale() );
		$translation = $wpdb->get_row( $wpdb->prepare( "SELECT tr.locale,tr.name,tr.description,tr.location,tr.updated_at FROM {$wpdb->prefix}wpcap_event_translations tr INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=tr.event_id WHERE tr.event_id=%d AND tr.locale=%s AND e.status='published'", absint( $request['event_id'] ), $locale ), ARRAY_A );
		return $translation ? rest_ensure_response( $translation ) : new \WP_Error( 'wpcap_translation_not_found', __( 'Übersetzung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
	}

	public static function save_event_translation( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_translation_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$payload = (array) $request->get_json_params();
		$locale = sanitize_text_field( $payload['locale'] ?? '' );
		$name = sanitize_text_field( $payload['name'] ?? '' );
		if ( ! preg_match( '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale ) || '' === $name ) { return new \WP_Error( 'wpcap_translation_invalid', __( 'Locale oder übersetzter Titel ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_event_translations (event_id,locale,name,description,location,updated_at) VALUES (%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),location=VALUES(location),updated_at=VALUES(updated_at)", $event_id, $locale, $name, wp_kses_post( $payload['description'] ?? '' ), sanitize_text_field( $payload['location'] ?? '' ), current_time( 'mysql', true ) ) );
		return rest_ensure_response( array( 'event_id' => $event_id, 'locale' => $locale, 'status' => 'saved' ) );
	}

	public static function save_tax_profile( \WP_REST_Request $request ) {
		global $wpdb;
		$data = (array) $request->get_json_params();
		$country = strtoupper( sanitize_text_field( $data['country_code'] ?? '' ) );
		$class = sanitize_key( $data['tax_class'] ?? 'standard' );
		$rate = absint( $data['rate_basis_points'] ?? 0 );
		$from = sanitize_text_field( $data['effective_from'] ?? '' );
		$until = sanitize_text_field( $data['effective_until'] ?? '' );
		$version = sanitize_text_field( $data['version'] ?? '' );
		$from_parts = array_map( 'intval', explode( '-', $from ) );
		$until_parts = $until ? array_map( 'intval', explode( '-', $until ) ) : array();
		$valid_from = 3 === count( $from_parts ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && checkdate( $from_parts[1], $from_parts[2], $from_parts[0] );
		$valid_until = ! $until || ( 3 === count( $until_parts ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) && checkdate( $until_parts[1], $until_parts[2], $until_parts[0] ) && $until >= $from );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || ! $valid_from || ! $valid_until || ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $version ) || $rate > 10000 ) { return new \WP_Error( 'wpcap_tax_profile_invalid', __( 'Steuerprofil ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_tax_profiles', array( 'country_code' => $country, 'tax_class' => $class, 'rate_basis_points' => $rate, 'version' => $version, 'effective_from' => $from, 'effective_until' => $until ?: null, 'status' => 'draft', 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
		return false !== $inserted ? new \WP_REST_Response( array( 'tax_profile_id' => (int) $wpdb->insert_id, 'status' => 'draft', 'requires_review' => true ), 201 ) : new \WP_Error( 'wpcap_tax_profile_save', __( 'Steuerprofil konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
	}

	public static function create_seat_map( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_seat_map_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$payload = $request->get_json_params();
		$rows = $payload['seats'] ?? array();
		$name = sanitize_text_field( $payload['name'] ?? '' );
		if ( ! $name || ! is_array( $rows ) || ! $rows || count( $rows ) > 500 ) { return new \WP_Error( 'wpcap_seat_map_invalid', __( 'Sitzplan muss einen Namen und 1 bis 500 Sitzplätze enthalten.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d FOR UPDATE", $event_id ) );
		if ( ! $event ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$wpdb->prefix}wpcap_seat_maps WHERE event_id=%d", $event_id ) );
		$map_saved = $wpdb->insert( $wpdb->prefix . 'wpcap_seat_maps', array( 'event_id' => $event_id, 'version' => $version, 'name' => $name, 'status' => 'published', 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%s' ) );
		$map_id = false === $map_saved ? 0 : (int) $wpdb->insert_id;
		if ( ! $map_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_map_save', __( 'Sitzplan konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		foreach ( $rows as $seat ) {
			$key = sanitize_key( $seat['seat_key'] ?? '' );
			$label = sanitize_text_field( $seat['seat_label'] ?? '' );
			if ( ! $key || ! $label ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_invalid', __( 'Jeder Sitzplatz benötigt einen eindeutigen Schlüssel und eine Bezeichnung.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
			$seat_saved = $wpdb->insert( $wpdb->prefix . 'wpcap_seats', array( 'map_id' => $map_id, 'seat_key' => $key, 'zone' => sanitize_text_field( $seat['zone'] ?? '' ), 'row_label' => sanitize_text_field( $seat['row_label'] ?? '' ), 'seat_label' => $label, 'position_x' => (float) ( $seat['x'] ?? 0 ), 'position_y' => (float) ( $seat['y'] ?? 0 ), 'status' => 'available' ), array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s' ) );
			if ( false === $seat_saved ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_duplicate', __( 'Sitzplätze konnten nicht vollständig gespeichert werden; prüfe doppelte Schlüssel.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		return new \WP_REST_Response( array( 'map_id' => $map_id, 'version' => $version, 'seat_count' => count( $rows ) ), 201 );
	}

	public static function create_resource( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_resource_forbidden', __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$payload = $request->get_json_params();
		$name = sanitize_text_field( $payload['name'] ?? '' );
		if ( ! $name ) { return new \WP_Error( 'wpcap_resource_invalid', __( 'Ressourcenname fehlt.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$wpdb->insert( $wpdb->prefix . 'wpcap_resources', array( 'event_id' => $event_id, 'name' => $name, 'capacity' => min( 100, max( 1, absint( $payload['capacity'] ?? 1 ) ) ), 'buffer_before' => min( 1440, absint( $payload['buffer_before'] ?? 0 ) ), 'buffer_after' => min( 1440, absint( $payload['buffer_after'] ?? 0 ) ), 'status' => 'active' ), array( '%d', '%s', '%d', '%d', '%d', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_resource_save', __( 'Ressource konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		return new \WP_REST_Response( array( 'resource_id' => (int) $wpdb->insert_id ), 201 );
	}

	public static function create_service_slot( \WP_REST_Request $request ) {
		global $wpdb;
		$resource_id = absint( $request['resource_id'] );
		$resource = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d AND status='active'", $resource_id ) );
		if ( ! $resource || ! Access::can_access_event( $resource->event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_resource_forbidden', __( 'Keine Berechtigung für diese Ressource.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$payload = $request->get_json_params();
		$start = strtotime( sanitize_text_field( $payload['starts_at'] ?? '' ) );
		$end = strtotime( sanitize_text_field( $payload['ends_at'] ?? '' ) );
		if ( ! $start || ! $end || $end <= $start ) { return new \WP_Error( 'wpcap_service_slot_invalid', __( 'Zeitfenster ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$wpdb->insert( $wpdb->prefix . 'wpcap_service_slots', array( 'resource_id' => $resource_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $end ), 'capacity' => min( 100, max( 1, absint( $payload['capacity'] ?? $resource->capacity ) ) ), 'status' => 'open' ), array( '%d', '%s', '%s', '%d', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_service_slot_save', __( 'Zeitfenster konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		return new \WP_REST_Response( array( 'slot_id' => (int) $wpdb->insert_id ), 201 );
	}

	public static function set_admission_days( \WP_REST_Request $request ) {
		global $wpdb;
		$occurrence_id = absint( $request['occurrence_id'] );
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.event_id FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
		if ( ! $occurrence || ! Access::can_access_event( $occurrence->event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_admission_forbidden', __( 'Keine Berechtigung für diesen Termin.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$payload = $request->get_json_params();
		$days = $payload['days'] ?? array();
		if ( ! is_array( $days ) || ! $days || count( $days ) > 31 ) { return new \WP_Error( 'wpcap_admission_days_invalid', __( 'Bitte 1 bis 31 Veranstaltungstage angeben.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$start_date = wp_date( 'Y-m-d', strtotime( $occurrence->starts_at . ' UTC' ), new \DateTimeZone( $occurrence->timezone ) );
		$end_date = $occurrence->ends_at ? wp_date( 'Y-m-d', strtotime( $occurrence->ends_at . ' UTC' ), new \DateTimeZone( $occurrence->timezone ) ) : $start_date;
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $days as $entry ) {
			$date = sanitize_text_field( $entry['date'] ?? '' );
			$policy = sanitize_key( $entry['policy'] ?? 'single_entry' );
			$parts = array_map( 'intval', explode( '-', $date ) );
			$valid_date = 3 === count( $parts ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && checkdate( $parts[1], $parts[2], $parts[0] );
			if ( ! $valid_date || $date < $start_date || $date > $end_date || ! in_array( $policy, array( 'single_entry', 'daily', 'reentry' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_admission_day_invalid', __( 'Ein Veranstaltungstag oder eine Einlassregel ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_occurrence_days (occurrence_id,service_date,admission_policy,max_entries) VALUES (%d,%s,%s,%d) ON DUPLICATE KEY UPDATE admission_policy=VALUES(admission_policy),max_entries=VALUES(max_entries)", $occurrence_id, $date, $policy, min( 20, max( 1, absint( $entry['max_entries'] ?? 1 ) ) ) ) );
			if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_admission_save', __( 'Einlassregeln konnten nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		return rest_ensure_response( array( 'status' => 'saved', 'day_count' => count( $days ) ) );
	}

	public static function hold_seats( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		$occurrence_id = absint( $request->get_param( 'occurrence_id' ) );
		$allowed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d AND o.event_id=%d AND o.status='scheduled' AND e.status='published'", $occurrence_id, $event_id ) );
		if ( ! $allowed ) { return new \WP_Error( 'wpcap_occurrence_not_found', __( 'Termin nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$result = Seats::hold( $occurrence_id, (array) $request->get_param( 'seat_ids' ), 0, 10, $request->get_param( 'idempotency_key' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function service_slots( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		$slots = $wpdb->get_results( $wpdb->prepare( "SELECT s.slot_id,s.starts_at,s.ends_at,s.capacity,r.name AS resource_name FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE r.event_id=%d AND r.status='active' AND e.status='published' AND s.status='open' AND s.starts_at>%s ORDER BY s.starts_at LIMIT 100", $event_id, current_time( 'mysql', true ) ), ARRAY_A );
		return rest_ensure_response( $slots );
	}

	public static function book_service_slot( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$result = ServiceBookings::reserve( absint( $request['slot_id'] ), $payload['email'] ?? '', absint( $payload['quantity'] ?? 1 ), 0, 10, $payload['idempotency_key'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function validate_ticket( \WP_REST_Request $request ) {
		$result = \PassCaptain\Checkin::preview( $request->get_param( 'token' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Web scanner check-in; the logic lives in Checkin::perform() (shared with the app API). */
	public static function check_in( \WP_REST_Request $request ) {
		$result = \PassCaptain\Checkin::perform( $request->get_param( 'token' ), array( 'source' => sanitize_key( $request->get_param( 'source' ) ?: 'web' ), 'idempotency_key' => sanitize_text_field( (string) $request->get_param( 'idempotency_key' ) ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
