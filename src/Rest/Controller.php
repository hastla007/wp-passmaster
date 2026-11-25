<?php
/** Public checkout and authenticated scanner REST routes. */
namespace PassCaptain\Rest;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Memberships;
use PassCaptain\TicketAssignments;
use PassCaptain\ServiceBookings;
use PassCaptain\Invoices;
use PassCaptain\Campaigns;
use PassCaptain\Waitlists;
use PassCaptain\DeveloperKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Controller {
	/** Minimum length of a client-chosen checkout idempotency key (A639; the checkout form sends a UUID). */
	const MIN_CHECKOUT_KEY_LENGTH = 16;

	/**
	 * A421: argument schemas of the public write routes. WordPress refuses wrongly typed values with
	 * 400 (rest_invalid_param) before the callback runs: strings stay strings, quantities and ids are
	 * whole numbers ≥ 1 (no negative or fractional quantities that a cast would silently turn around).
	 *
	 * @param string $kind token|id|quantity|email|text|items|object|bool
	 * @param array  $extra Further schema keywords (maxLength, maximum, required …).
	 */
	public static function arg( $kind, array $extra = array() ) {
		$schemas = array(
			'token' => array( 'type' => 'string', 'maxLength' => 64 ),
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'quantity' => array( 'type' => 'integer', 'minimum' => 1 ),
			'email' => array( 'type' => 'string', 'maxLength' => \PassCaptain\Http::EMAIL_MAX ),
			'text' => array( 'type' => 'string', 'maxLength' => 191 ),
			'object' => array( 'type' => 'object' ),
			'bool' => array( 'type' => array( 'boolean', 'string', 'integer' ) ),
			'items' => array(
				'type' => 'array',
				'maxItems' => 10,
				'items' => array(
					'type' => 'object',
					'properties' => array(
						'ticket_type_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
						'quantity' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					),
				),
			),
		);
		return array_merge( $schemas[ $kind ], array( 'validate_callback' => 'rest_validate_request_arg' ), $extra );
	}

	public static function register_routes() {
		// A401: one method per resource group, called in the former order of the route registrations.
		self::register_checkout_routes();
		self::register_order_routes();
		self::register_admission_routes();
		self::register_member_pass_routes();
		self::register_service_routes();
		self::register_campaign_routes();
		self::register_payment_routes();
		self::register_waitlist_routes();
		self::register_admin_routes();
	}

	/** Event list and checkout. (A401: part of register_routes(), same order.) */
	private static function register_checkout_routes() {
		register_rest_route( 'pass-captain/v1', '/events', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'events' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'pass-captain/v1', '/checkout', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'checkout' ),
			'permission_callback' => '__return_true',
			'args' => array(
				// A492: type check before sanitising (sanitize_email() of an array is a TypeError); the
				// callback is type-safe as well, the length limit is checked by Orders::create().
				'email' => array( 'required' => true, 'type' => 'string', 'validate_callback' => 'rest_validate_request_arg', 'sanitize_callback' => array( \PassCaptain\Http::class, 'email' ) ),
				// A421: lines with whole ticket type ids and quantities ≥ 1 (Orders::create() checks the same).
				'items' => self::arg( 'items', array( 'required' => true ) ),
				'accept_terms' => self::arg( 'bool' ),
				'confirm_age' => self::arg( 'bool' ), // SET-PC-18
			),
		) );
	}

	/** Order portal: status, transfer, resend, refund request, assignment, billing, payout account, invoice. (A401: part of register_routes(), same order.) */
	private static function register_order_routes() {
		// A645: the access token belongs in the header X-WPCAP-Access-Token or a POST body; GET with
		// ?access_token= still works for now but is answered with Deprecation: true (A370).
		$order_token = array( 'access_token' => array( 'type' => 'string', 'maxLength' => 64, 'validate_callback' => 'rest_validate_request_arg' ) );
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'order_status' ), 'permission_callback' => '__return_true', 'args' => $order_token ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'order_status' ), 'permission_callback' => '__return_true', 'args' => $order_token ),
		) );
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/transfer', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'transfer_ticket' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'access_token' => self::arg( 'token' ), // Optional: the X-WPCAP-Access-Token header (Rest\Credentials) carries it otherwise.
				'ticket_public_id' => self::arg( 'text', array( 'required' => true, 'maxLength' => 32 ) ),
				'recipient_email' => self::arg( 'email', array( 'required' => true ) ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/resend', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'resend_tickets' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'access_token' => self::arg( 'token' ), // Optional: the X-WPCAP-Access-Token header (Rest\Credentials) carries it otherwise.
			),
		) );
		// One route for both portal refund requests (PortalSelfService registers it too): registered last
		// with override, so the dispatcher in request_refund() always answers.
		self::register_refund_route();
		if ( function_exists( 'add_action' ) && ! has_action( 'rest_api_init', array( __CLASS__, 'register_refund_route' ) ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_refund_route' ), 100 );
		}
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/assign', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'assign_ticket' ),
			'permission_callback' => '__return_true',
			// A635: typed and length-limited like the other portal routes (attendee columns are varchar(190)).
			'args' => array(
				'access_token' => array( 'type' => 'string', 'maxLength' => 64, 'validate_callback' => 'rest_validate_request_arg' ), // Or the X-WPCAP-Access-Token header.
				'ticket_public_id' => array( 'required' => true, 'type' => 'string', 'maxLength' => 32, 'validate_callback' => 'rest_validate_request_arg' ),
				'attendee_name' => array( 'required' => true, 'type' => 'string', 'maxLength' => TicketAssignments::NAME_MAX, 'validate_callback' => 'rest_validate_request_arg' ),
				'attendee_email' => array( 'type' => 'string', 'maxLength' => \PassCaptain\Http::EMAIL_MAX, 'validate_callback' => 'rest_validate_request_arg' ),
				'answers' => array( 'type' => 'object', 'validate_callback' => 'rest_validate_request_arg' ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/billing', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'update_billing' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'access_token' => self::arg( 'token' ), // Optional: the X-WPCAP-Access-Token header (Rest\Credentials) carries it otherwise.
				'billing' => self::arg( 'object' ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/payout-account', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'update_payout_account' ),
			'permission_callback' => '__return_true', // Capability link: the access token is checked in the callback.
			'args' => array(
				'access_token' => self::arg( 'token' ), // Optional: the X-WPCAP-Access-Token header (Rest\Credentials) carries it otherwise.
				'iban' => self::arg( 'text', array( 'maxLength' => 64 ) ),
				'holder' => self::arg( 'text', array( 'maxLength' => 190 ) ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/invoice', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'issue_invoice' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
	}

	/** Admission and sales on site: validate, check-in, offline sync, box office sale, pass redemption. (A401: part of register_routes(), same order.) */
	private static function register_admission_routes() {
		register_rest_route( 'pass-captain/v1', '/tickets/validate', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'validate_ticket' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
			'args' => array( 'token' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( 'pass-captain/v1', '/tickets/check-in', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'check_in' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
			'args' => array( 'token' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( 'pass-captain/v1', '/tickets/offline-sync', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'offline_sync' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/pos/sales', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'pos_sale' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/passes/redeem', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'redeem_pass' ),
			'permission_callback' => array( __CLASS__, 'scanner_permission' ),
		) );
	}

	/** Member passes: issue, revoke, approve, reject. (A401: part of register_routes(), same order.) */
	private static function register_member_pass_routes() {
		// One registration with both endpoints (issue and list) of the route.
		register_rest_route( 'pass-captain/v1', '/member-passes', array(
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array( __CLASS__, 'issue_member_pass' ),
				'permission_callback' => array( __CLASS__, 'pos_permission' ),
			),
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array( __CLASS__, 'list_member_passes' ),
				'permission_callback' => array( __CLASS__, 'pos_permission' ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/member-passes/(?P<pass_id>\d+)/revoke', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'revoke_member_pass' ),
			'permission_callback' => array( __CLASS__, 'pos_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/member-passes/(?P<pass_id>\d+)/approve', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'approve_member_pass' ),
			'permission_callback' => array( __CLASS__, 'member_approve_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/member-passes/(?P<pass_id>\d+)/reject', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'reject_member_pass' ),
			'permission_callback' => array( __CLASS__, 'member_approve_permission' ),
		) );
	}

	/** Service slots, resources, bookings and admission days of an occurrence. (A401: part of register_routes(), same order.) */
	private static function register_service_routes() {
		// A529: the old seat API (GET /events/{id}/seats, POST /events/{id}/seat-maps, POST /events/{id}/seat-holds)
		// had no purchase path (public holds that nothing could buy, an UPDATE on every GET) and is removed;
		// seat selection and sale go through the Seating module (/seating/occurrences/{id}, checkout).
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/service-slots', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'service_slots' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/resources', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_resource' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/resources/(?P<resource_id>\d+)/slots', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_service_slot' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/service-slots/(?P<slot_id>\d+)/bookings', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'book_service_slot' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'email' => self::arg( 'email', array( 'required' => true ) ),
				'quantity' => self::arg( 'quantity', array( 'maximum' => \PassCaptain\ServiceBookings::MAX_QUANTITY ) ),
				'idempotency_key' => self::arg( 'text' ),
			),
		) );
		// Service booking self-service (A135): personal booking token from the reservation.
		register_rest_route( 'pass-captain/v1', '/service-bookings/(?P<booking_id>\d+)', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( \PassCaptain\ServiceBookingFlow::class, 'rest_get' ),
			'permission_callback' => array( \PassCaptain\ServiceBookingFlow::class, 'token_permission' ),
		) );
		foreach ( array( 'confirm', 'rebook', 'cancel' ) as $service_op ) {
			register_rest_route( 'pass-captain/v1', '/service-bookings/(?P<booking_id>\d+)/' . $service_op, array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array( \PassCaptain\ServiceBookingFlow::class, 'rest_' . $service_op ),
				'permission_callback' => array( \PassCaptain\ServiceBookingFlow::class, 'token_permission' ),
			) );
		}
		register_rest_route( 'pass-captain/v1', '/occurrences/(?P<occurrence_id>\d+)/admission-days', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'set_admission_days' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
	}

	/** Discount campaigns: preview and per-event list. (A401: part of register_routes(), same order.) */
	private static function register_campaign_routes() {
		register_rest_route( 'pass-captain/v1', '/campaigns/preview', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'preview_campaign' ),
			'permission_callback' => '__return_true', // Public, rate-limited, read-only (no locks, nothing reserved).
			'args' => array(
				'code' => self::arg( 'text', array( 'maxLength' => 64 ) ),
				'items' => self::arg( 'items' ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/campaigns', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_campaign' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
	}

	/** Payment webhooks, availability and live checkout options. (A401: part of register_routes(), same order.) */
	private static function register_payment_routes() {
		register_rest_route( 'pass-captain/v1', '/payments/(?P<provider>[a-z0-9_-]+)/webhook', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( \PassCaptain\Payments\Webhooks::class, 'handle' ),
			// Authenticity is established by the provider signature check in verify_webhook().
			'permission_callback' => '__return_true',
		) );
		// Public live availability for cached event pages (Frontend\Availability; rate limited, 5 s cacheable).
		register_rest_route( 'pass-captain/v1', '/availability', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( \PassCaptain\Frontend\Availability::class, 'rest' ),
			'permission_callback' => '__return_true',
			'args' => array( 'types' => array( 'required' => true ) ),
		) );
		register_rest_route( 'pass-captain/v1', '/checkout/options', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'checkout_options' ),
			'permission_callback' => '__return_true',
		) );
		// A456: live purchase state of event cards served from a page cache or CDN (no-store, read-only).
		register_rest_route( 'pass-captain/v1', '/checkout/live', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( \PassCaptain\PublicEvents::class, 'rest_live' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Waitlist: sign-up, offers, accept/decline, entries. (A401: part of register_routes(), same order.) */
	private static function register_waitlist_routes() {
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/waitlist', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'join_waitlist' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'occurrence_id' => self::arg( 'id', array( 'required' => true ) ),
				'ticket_type_id' => self::arg( 'id', array( 'required' => true ) ),
				'email' => self::arg( 'email', array( 'required' => true ) ),
				'quantity' => self::arg( 'quantity' ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/waitlist/offer', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'offer_waitlist' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/waitlist/(?P<waitlist_id>\d+)/accept', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'accept_waitlist_offer' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'token' => self::arg( 'token' ), // Or the X-WPCAP-Access-Token header (Rest\Credentials).
				'accept_terms' => self::arg( 'bool' ),
				'attendees' => array( 'type' => 'array', 'validate_callback' => 'rest_validate_request_arg' ),
				'billing' => self::arg( 'object' ), // L707: invoice data like the checkout.
				// Owner decision 25.11.2026: discount codes and vouchers like the checkout (Waitlists\Discounts).
				'code' => self::arg( 'text' ),
				'campaign_codes' => array( 'type' => 'array', 'validate_callback' => 'rest_validate_request_arg' ),
				'voucher_code' => self::arg( 'text' ),
			),
		) );
		register_rest_route( 'pass-captain/v1', '/waitlist/(?P<waitlist_id>\d+)/decline', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'decline_waitlist_offer' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'token' => self::arg( 'token' ), // Or the X-WPCAP-Access-Token header (Rest\Credentials).
			),
		) );
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/waitlist/entries', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'list_waitlist' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
	}

	/** Reports, developer API, event translations and tax profiles. (A401: part of register_routes(), same order.) */
	private static function register_admin_routes() {
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/reports/summary', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'event_report' ),
			'permission_callback' => array( __CLASS__, 'report_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/developer/keys', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_developer_key' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/developer/keys/(?P<key_id>\d+)/revoke', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'revoke_developer_key' ),
			'permission_callback' => array( __CLASS__, 'manage_events_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/developer/events/(?P<event_id>\d+)', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'developer_event' ),
			'permission_callback' => array( __CLASS__, 'developer_key_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/developer/events/(?P<event_id>\d+)/service-slots', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'developer_service_slots' ),
			'permission_callback' => array( __CLASS__, 'developer_slots_permission' ),
		) );
		register_rest_route( 'pass-captain/v1', '/events/(?P<event_id>\d+)/translations', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_event_translation' ), 'permission_callback' => '__return_true' ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_event_translation' ), 'permission_callback' => array( __CLASS__, 'manage_events_permission' ) ),
		) );
		register_rest_route( 'pass-captain/v1', '/tax-profiles', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'save_tax_profile' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );
	}

	/** Resource => filter of prepare() (public add-on API, PublicApi::HOOKS). */
	const PREPARE_HOOKS = array(
		'event' => 'wpcap_rest_prepare_event',
		'order' => 'wpcap_rest_prepare_order',
		'developer_event' => 'wpcap_rest_prepare_developer_event',
	);

	/**
	 * Filters wpcap_rest_prepare_event, wpcap_rest_prepare_order and wpcap_rest_prepare_developer_event
	 * (A331): add fields to a REST item. Additive only – the contract fields of the item (tests/contracts/)
	 * cannot be removed, renamed or changed by a filter; only new keys are taken over.
	 *
	 * @param string $resource event, order, developer_event.
	 * @param array  $item     Item as the contract defines it.
	 * @param mixed  ...$args  Source row and/or WP_REST_Request.
	 */
	public static function prepare( $resource, array $item, ...$args ) {
		if ( ! isset( self::PREPARE_HOOKS[ $resource ] ) ) { return $item; }
		$filtered = apply_filters( self::PREPARE_HOOKS[ $resource ], $item, ...$args );
		if ( ! is_array( $filtered ) ) { return $item; }
		return $item + array_diff_key( $filtered, $item );
	}

	public static function events( \WP_REST_Request $request ) {
		global $wpdb;
		// Upcoming occurrences plus running ones with a ticket type still on sale online (A535).
		$rows = $wpdb->get_results(
			"SELECT e.event_id,e.name,e.slug,e.description,o.occurrence_id,o.starts_at,o.ends_at,o.timezone,o.location,o.capacity FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND " . \PassCaptain\Occurrence::listable_sql( 'o' ) . ' ORDER BY o.starts_at ASC LIMIT 100' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
		);
		$output = array();
		$locale = sanitize_text_field( $request->get_param( 'locale' ) ?: determine_locale() );
		// Translations, ticket types and occurrences of all listed dates in three queries (no N+1).
		$translations = array();
		$types_of = array();
		$occurrences = array();
		if ( $rows ) {
			$event_in = implode( ',', array_unique( array_map( static function ( $row ) { return (int) $row->event_id; }, $rows ) ) );
			$occ_in = implode( ',', array_unique( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_id,name,description,location FROM {$wpdb->prefix}wpcap_event_translations WHERE event_id IN ($event_in) AND locale=%s", $locale ) ) as $translation ) {
				$translations[ (int) $translation->event_id ] = $translation;
			}
			// Hidden types never, code-only types only with a matching ?code= (Sales\Visibility).
			$type_rows = \PassCaptain\Sales\Visibility::filter_listing( (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id IN ($occ_in) AND status='active' ORDER BY ticket_type_id ASC" ), \PassCaptain\Http::str( $request->get_param( 'code' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( $type_rows as $type_row ) {
				$type = array_intersect_key( (array) $type_row, array_flip( array( 'ticket_type_id', 'occurrence_id', 'name', 'price_minor', 'currency', 'capacity', 'max_per_order', 'sales_start', 'sales_end', 'service_fee_minor', 'kind', 'addon_max_per_ticket' ) ) );
				$types_of[ (int) $type['occurrence_id'] ][] = $type;
			}
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id IN ($occ_in)" ) as $occurrence ) {
				$occurrences[ (int) $occurrence->occurrence_id ] = $occurrence;
			}
		}
		// A561: availability of all listed ticket types with a constant number of queries.
		$type_ids = array();
		foreach ( $types_of as $list ) { foreach ( $list as $type ) { $type_ids[] = (int) $type['ticket_type_id']; } }
		// Modules load their per-occurrence data for exactly these dates in one query (A376: seat map links, buyer limits).
		if ( $rows ) { do_action( 'wpcap_listing_occurrences', array_values( array_unique( array_map( static fn( $row ) => (int) $row->occurrence_id, $rows ) ) ), array_values( array_unique( array_map( static fn( $row ) => (int) $row->event_id, $rows ) ) ) ); }
		// One display batch with the rows loaded above: price rules, series prices and pools of all listed
		// types answer from grouped queries instead of several queries per ticket type (PERF-1).
		$preloaded = array( 'types' => array(), 'occurrences' => $occurrences );
		foreach ( $type_rows ?? array() as $type_row ) { $preloaded['types'][ (int) $type_row->ticket_type_id ] = $type_row; }
		\PassCaptain\DisplayBatch::run( $type_ids, static function () use ( $rows, $translations, $types_of, $occurrences, $type_ids, $request, &$output ) {
		$available = $type_ids ? Orders::availability_many( $type_ids, array( 'occurrences' => $occurrences ) ) : array();
		$now = \PassCaptain\Clock::now();
		foreach ( $rows as $row ) {
			$translation = $translations[ (int) $row->event_id ] ?? null;
			$types = $types_of[ (int) $row->occurrence_id ] ?? array();
			$occurrence = $occurrences[ (int) $row->occurrence_id ] ?? null;
			$phase_row = $occurrence ? $occurrence : $row;
			$output[] = self::prepare( 'event', array(
				'event_id' => (int) $row->event_id,
				'name' => $translation ? $translation->name : $row->name,
				'slug' => $row->slug,
				'description' => wp_strip_all_tags( $translation ? $translation->description : $row->description ),
				'occurrence_id' => (int) $row->occurrence_id,
				'starts_at' => $row->starts_at,
				'ends_at' => $row->ends_at,
				'timezone' => $row->timezone,
				'location' => $translation && $translation->location ? $translation->location : $row->location,
				// A561: times as ISO 8601 with the offset of the occurrence's timezone (starts_at/ends_at
				// above stay UTC "Y-m-d H:i:s" for existing clients), lifecycle phase (A562) and revision (A560).
				'start' => \PassCaptain\Occurrence::iso( $row->starts_at, $row->timezone ),
				'end' => \PassCaptain\Occurrence::iso( $row->ends_at, $row->timezone ),
				'effective_end' => \PassCaptain\Occurrence::iso( \PassCaptain\Occurrence::effective_end( $row ), $row->timezone ),
				'phase' => \PassCaptain\Occurrence::phase( $phase_row, $now ),
				'revision' => (int) ( $occurrence->revision ?? 0 ),
				'tickets' => array_map( static function ( $type ) use ( $occurrence, $phase_row, $available, $now, $row ) {
					$left = array_key_exists( (int) $type['ticket_type_id'], $available ) ? $available[ (int) $type['ticket_type_id'] ] : 0;
					$unit = \PassCaptain\Orders::unit_price( (object) $type, $occurrence );
					$shown = \PassCaptain\ServiceFee::shown_unit( (object) $type, $unit );
					return array(
					'ticket_type_id' => (int) $type['ticket_type_id'],
					'name' => $type['name'],
					// Effective (tier) price; regular_price_minor is the stored list price.
					'price_minor' => $unit,
					// A425: per-ticket service fee of the own checkout and the price to display (PAngV: incl. fee).
					'service_fee_minor' => $shown - $unit,
					'display_price_minor' => $shown,
					'order_fee_minor' => \PassCaptain\ServiceFee::shown()['order_minor'],
					'regular_price_minor' => (int) $type['price_minor'],
					'price_label' => \PassCaptain\Orders::price_label( (object) $type, $occurrence ),
					'currency' => $type['currency'],
					'max_per_order' => (int) $type['max_per_order'],
					// A561: sales status, window (ISO 8601 with offset) and remaining units (null = unlimited).
					'sales_status' => self::sales_status( $type, $phase_row, $left, $now ),
					'sales_start' => \PassCaptain\Occurrence::iso( $type['sales_start'], $row->timezone ),
					'sales_end' => \PassCaptain\Occurrence::iso( $type['sales_end'], $row->timezone ),
					'online_until' => \PassCaptain\Occurrence::iso( gmdate( 'Y-m-d H:i:s', \PassCaptain\Occurrence::online_sales_until( $phase_row, $type ) ), $row->timezone ),
					'available' => null === $left ? null : max( 0, (int) $left ),
					// Sales\Addons: "ticket" or "addon" (add-on product: only with a ticket of the same date, at most addon_max_per_ticket per ticket, 0 = no limit).
					'kind' => \PassCaptain\Sales\Addons::kind( (object) $type ),
					'addon_max_per_ticket' => \PassCaptain\Sales\Addons::max_per_ticket( (object) $type ),
				); }, $types ),
			), $row, $request );
		}
		}, $preloaded );
		$response = rest_ensure_response( $output );
		// A561: sales status and availability change by the minute – never stored by browsers or proxies.
		if ( $response instanceof \WP_REST_Response && method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'no-store, max-age=0' ); }
		return $response;
	}

	/**
	 * Online sales status of a ticket type at $now (A561): not_started (sales_start ahead), ended
	 * (sales_end passed or the occurrence's phase ended online sales, Occurrence), sold_out or on_sale.
	 *
	 * @param array    $type      Ticket type (sales_start, sales_end).
	 * @param int|null $available Remaining units (null = unlimited).
	 */
	public static function sales_status( array $type, $occurrence, $available, $now ) {
		$start = (string) ( $type['sales_start'] ?? '' );
		$end = (string) ( $type['sales_end'] ?? '' );
		if ( '' !== $start && strtotime( $start . ' UTC' ) > $now ) { return 'not_started'; }
		if ( ( '' !== $end && strtotime( $end . ' UTC' ) < $now ) || \PassCaptain\Occurrence::online_sales_over( $occurrence, $type, $now ) ) { return 'ended'; }
		return null !== $available && (int) $available < 1 ? 'sold_out' : 'on_sale';
	}

	/**
	 * Public checkout. $context is for trusted internal callers only (never reachable from REST,
	 * which passes the request alone), e.g. service bookings (A135): `internal` (extra
	 * Orders::create() options; payment_provider/hold_minutes cannot be overridden) and
	 * `manual_start_ts` (start the bank-transfer hold is capped at, instead of the ticket type's
	 * occurrence start).
	 */
	public static function checkout( \WP_REST_Request $request, array $context = array() ) {
		$params = $request->get_json_params() ?: $request->get_params();
		$params = is_array( $params ) ? $params : array();
		// Bot check of the public checkout form (trap field, minimum fill time): only requests that carry the
		// form's `form_guard`; API clients and internal callers (with $context) are not affected.
		if ( ! $context ) {
			$guard = \PassCaptain\Security\FormGuard::check( $params );
			if ( is_wp_error( $guard ) ) { return $guard; }
		}
		$params = self::checkout_single_code( $params );
		// Only customer fields; trusted adapter options must never come from a public request.
		$payload = array_intersect_key( $params, array_flip( apply_filters( 'wpcap_checkout_payload_keys', array( 'email', 'items', 'idempotency_key', 'campaign_codes', 'attendees', 'accept_terms', 'confirm_age', 'terms_version', 'locale', 'voucher_code', 'billing', 'expected_prices', 'attribution', 'buyer_name' ) ) ) );
		$provider_id = sanitize_key( \PassCaptain\Http::str( $params['provider'] ?? 'manual' ) );
		$gateway = \PassCaptain\Payments\Registry::get( $provider_id );
		$enabled = (array) \PassCaptain\Settings::get( 'enabled_providers' );
		// Free tickets need no payment method: without a usable one (default "manual" not enabled or not set up)
		// an order of free types only goes through as a free order; Orders::create() refuses it if anything is to pay.
		$free_only = 'manual' === $provider_id && $gateway && ( ! in_array( 'manual', $enabled, true ) || ! $gateway->is_available( '' ) ) && self::free_items_only( $payload['items'] ?? array() );
		if ( ! $free_only && ( ! $gateway || ! in_array( $provider_id, $enabled, true ) ) ) {
			return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// A639: a client-chosen idempotency key identifies the attempt across retries; short (guessable)
		// keys are refused. Without a key the server generates one.
		$client_key = \PassCaptain\Http::str( $params['idempotency_key'] ?? '' );
		if ( '' !== $client_key && strlen( trim( $client_key ) ) < self::MIN_CHECKOUT_KEY_LENGTH ) {
			return new \WP_Error( 'wpcap_idempotency_key', __( 'Der Bestellversuch ist ungültig. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// Hosted payment pages (Stripe Checkout lives at least 30 minutes) end with the hold (A577) and must
		// never outlive it (QA-6): a gateway may require a longer minimum hold (StripeGateway::min_hold_minutes()).
		$min_hold = method_exists( $gateway, 'min_hold_minutes' ) ? max( 0, (int) $gateway->min_hold_minutes() ) : 0;
		$hold = self::checkout_hold( $gateway, $provider_id, $free_only, $min_hold, $params, $payload, $context );
		if ( is_wp_error( $hold ) ) { return $hold; }
		$fingerprint = Orders::request_fingerprint( $payload, $provider_id );
		// Trusted internal options never override provider, hold, request or fingerprint (left operand wins).
		$result = self::checkout_create_order( $payload, array( 'payment_provider' => $provider_id, 'hold_minutes' => $hold, 'request' => $request, 'request_fingerprint' => $fingerprint, 'free_only' => $free_only ) + (array) ( $context['internal'] ?? array() ) );
		if ( ! is_array( $result ) ) { return $result; }
		// Hosted payment pages return to the page the buyer ordered on (same site only), not the home page.
		$return_to = is_string( $params['return_to'] ?? null ) ? $params['return_to'] : ( is_string( $params['return_url'] ?? null ) ? $params['return_url'] : '' );
		if ( method_exists( \PassCaptain\Payments\ReturnNotice::class, 'set_base' ) ) { \PassCaptain\Payments\ReturnNotice::set_base( $return_to ); }
		$refused = self::checkout_reuse_error( $result, $provider_id, $fingerprint );
		if ( $refused ) { return $refused; }
		unset( $result['request_fingerprint'], $result['payment_provider'] );
		$refused = self::checkout_apply_voucher( $result, $params );
		if ( $refused ) { return $refused; }
		$refused = self::checkout_start_payment( $result, $gateway, $provider_id, $min_hold, $params );
		if ( $refused ) { return $refused; }
		return self::checkout_response( $result );
	}

	/**
	 * One code field ("Gutschein- oder Rabattcode"): a known campaign code is a discount, anything else is
	 * tried as a voucher (when a voucher plugin is connected).
	 */
	private static function checkout_single_code( array $params ) {
		$single_code = sanitize_text_field( \PassCaptain\Http::str( $params['code'] ?? '' ) );
		if ( '' !== $single_code ) {
			if ( \PassCaptain\Campaigns::is_code( $single_code ) || ! \PassCaptain\Integrations\Vouchers\Bridge::available() ) {
				$params['campaign_codes'] = array_merge( (array) ( $params['campaign_codes'] ?? array() ), array( $single_code ) );
			} elseif ( '' === (string) ( $params['voucher_code'] ?? '' ) ) {
				$params['voucher_code'] = $single_code;
			}
		}
		return $params;
	}

	/**
	 * Hold of the checkout (minutes, or for bank transfer the capped hold) or the refusal of the payment method.
	 *
	 * @return int|\WP_Error
	 */
	private static function checkout_hold( $gateway, $provider_id, $free_only, $min_hold, array $params, array $payload, array $context ) {
		$hold = max( Orders::HOSTED_HOLD_MINUTES, $min_hold, (int) \PassCaptain\Settings::get( 'hold_minutes' ) );
		if ( 'manual' === $provider_id && ! $free_only && ! $gateway->is_available( '' ) ) {
			// Enabled but without bank account or payment instructions (A265): no order, no blocked seats.
			return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400, 'field' => 'provider' ) );
		}
		if ( 'manual' === $provider_id && ! $free_only ) {
			// Bank transfer: hold ends at the latest with the start; refused within manual_cutoff_hours.
			$hold = isset( $context['manual_start_ts'] )
				? Orders::manual_hold_until( (int) $context['manual_start_ts'] )
				: Orders::manual_hold_minutes( array_map( static function ( $item ) { return absint( is_array( $item ) ? ( $item['ticket_type_id'] ?? 0 ) : 0 ); }, (array) ( $payload['items'] ?? array() ) ) );
			if ( null === $hold ) {
				return new \WP_Error( 'wpcap_manual_too_late', __( 'Eine Zahlung per Überweisung ist so kurz vor Beginn nicht mehr möglich. Bitte wähle eine andere Zahlungsart.', 'pass-captain' ), array( 'status' => 400, 'field' => 'provider' ) );
			}
			$blocked = self::manual_hold_limit( \PassCaptain\Http::str( $params['email'] ?? '' ), $hold );
			if ( is_wp_error( $blocked ) ) { return $blocked; }
		}
		return $hold;
	}

	/**
	 * Orders::create() with the checkout's campaign messages; a new reservation refreshes cached event pages.
	 *
	 * @return array|\WP_Error|\WP_REST_Response The result, or the checkout error for the browser.
	 */
	private static function checkout_create_order( array $payload, array $options ) {
		// Same wording as the form field ("Rabattcode"); the field is derived from the message below.
		$campaign_messages = static function ( $messages ) {
			$messages['campaign_invalid'] = __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'pass-captain' );
			$messages['campaign_redeem_failed'] = __( 'Der Rabattcode konnte nicht sicher auf die Bestellung angewendet werden. Bitte versuche es erneut.', 'pass-captain' );
			return $messages;
		};
		add_filter( 'wpcap_checkout_error_messages', $campaign_messages, 5 );
		$result = Orders::create( $payload, $options );
		remove_filter( 'wpcap_checkout_error_messages', $campaign_messages, 5 );
		if ( is_wp_error( $result ) ) { return self::checkout_error( self::with_error_field( $result, $campaign_messages( array() ) ), $payload ); }
		if ( empty( $result['reused'] ) ) {
			// New reservation: purge adapters may refresh the cached event page (wp_pass_captain_availability_changed_v1).
			\PassCaptain\Frontend\Availability::changed( array_map( static function ( $line ) { return is_array( $line ) ? absint( $line['ticket_type_id'] ?? 0 ) : 0; }, (array) ( $payload['items'] ?? array() ) ), 'reserved' );
		}
		return $result;
	}

	/** Refusal of a reused attempt (other provider, other data, cancelled or expired), else null. */
	private static function checkout_reuse_error( array $result, $provider_id, $fingerprint ) {
		if ( ! empty( $result['reused'] ) ) {
			// Same key, different attempt (provider, tickets, e-mail or codes changed): never hand out the old
			// order – its payment would arrive for another provider or amount (paid without tickets).
			// A639: another payment method first – that payment could not be assigned to the order by any
			// webhook. Its own message tells the buyer to reload for a new attempt with the other method.
			if ( (string) ( $result['payment_provider'] ?? $provider_id ) !== $provider_id ) {
				return new \WP_Error( 'wpcap_checkout_provider_changed', __( 'Dieser Bestellversuch läuft bereits mit einer anderen Zahlungsart. Bitte lade die Seite neu, um mit einer anderen Zahlungsart zu bestellen.', 'pass-captain' ), array( 'status' => 409, 'field' => 'provider' ) );
			}
			$stored = (string) ( $result['request_fingerprint'] ?? '' );
			if ( '' !== $stored && ! hash_equals( $stored, $fingerprint ) ) {
				return new \WP_Error( 'wpcap_idempotency_mismatch', __( 'Diese Bestellung wurde mit anderen Angaben begonnen. Bitte lade die Seite neu und sende das Formular erneut ab.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		// A retry of an attempt that was cancelled (voucher rejected, payment start failed) or expired must not look like a new order.
		if ( ! empty( $result['reused'] ) && in_array( $result['payment_status'] ?? '', array( 'cancelled', 'expired' ), true ) ) {
			return new \WP_Error( 'wpcap_checkout_closed', __( 'Dieser Bestellversuch wurde abgebrochen. Bitte sende das Formular erneut ab.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/** Applies the voucher code to a pending order (updates $result); the error when it is rejected, else null. */
	private static function checkout_apply_voucher( array &$result, array $params ) {
		$voucher_code = sanitize_text_field( $params['voucher_code'] ?? '' );
		// Also on a retry whose first attempt stopped before the voucher was stored (the reservation key is the same).
		$needs_voucher = empty( $result['reused'] ) || ! Orders::get( $result['order_id'] )->voucher_snapshot;
		if ( '' !== $voucher_code && 'pending' === ( $result['payment_status'] ?? '' ) && $needs_voucher ) {
			$voucher = \PassCaptain\Integrations\Vouchers\Checkout::apply( $result['order_id'], $voucher_code );
			if ( is_wp_error( $voucher ) ) {
				Orders::cancel_order( $result['order_id'], 'voucher_rejected', array( 'pending' ) );
				return self::with_error_field( $voucher );
			}
			$fresh = Orders::get( $result['order_id'] );
			$result['total_minor'] = (int) $fresh->total_minor;
			$result['voucher_minor'] = (int) $voucher['applied_minor'];
			$result['status'] = $fresh->status;
			$result['payment_status'] = $fresh->payment_status;
		}
		return null;
	}

	/** Starts the payment of a pending order (adds `payment` to $result); the refusal or start failure, else null. */
	private static function checkout_start_payment( array &$result, $gateway, $provider_id, $min_hold, array $params ) {
		if ( 'pending' !== ( $result['payment_status'] ?? '' ) ) { return null; }
		$order = (array) Orders::get( $result['order_id'] );
		if ( ! $gateway->is_available( $order['currency'] ) ) {
			Orders::cancel_order( $result['order_id'], 'provider_unavailable_for_currency', array( 'pending' ) );
			return new \WP_Error( 'wpcap_provider_currency', __( 'Diese Zahlungsart unterstützt die Währung nicht.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		// SET-PC-40: rules per event, amount and billing country.
		if ( ! \PassCaptain\Payments\Rules::allows( $provider_id, \PassCaptain\Payments\Rules::order_context( (int) $result['order_id'] ) ) ) {
			Orders::cancel_order( $result['order_id'], 'provider_not_allowed', array( 'pending' ) );
			return new \WP_Error( 'wpcap_provider_rule', __( 'Diese Zahlungsart ist für diese Bestellung nicht verfügbar. Bitte wähle eine andere Zahlungsart.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( $min_hold ) {
			// A retry may come minutes later: stretch the hold so the new payment page still ends inside it.
			$hold_until = self::hold_until( (int) $result['order_id'] );
			if ( $hold_until && $hold_until < \PassCaptain\Clock::now() + $min_hold * MINUTE_IN_SECONDS ) {
				Orders::extend_hold( (int) $result['order_id'], $min_hold );
				$hold_until = self::hold_until( (int) $result['order_id'] );
			}
			if ( ! $hold_until ) {
				// The hold already lapsed: no new payment page for an order without reserved tickets.
				return new \WP_Error( 'wpcap_checkout_closed', __( 'Dieser Bestellversuch wurde abgebrochen. Bitte sende das Formular erneut ab.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			$order['hold_expires_at'] = $hold_until;
		}
		$items = array_map( static function ( $row ) { return (array) $row; }, Orders::items_for_order( $result['order_id'] ) );
		// A554: hosted payment pages return to the page the checkout ran on (only URLs of this site).
		$order['return_base'] = \PassCaptain\Payments\ReturnNotice::base( \PassCaptain\Http::str( $params['return_url'] ?? '' ) );
		$payment = $gateway->create_payment( $order, $items );
		if ( is_wp_error( $payment ) ) {
			// Only while still unpaid (A639): a concurrent confirmation of a retried attempt keeps its payment.
			Orders::cancel_order( $result['order_id'], 'payment_start_failed', array( 'pending' ) );
			// Neutral text + reference for the buyer; the provider text goes to the support log (A324).
			return \PassCaptain\Payments\StartFailures::handle( $provider_id, (int) $result['order_id'], $payment );
		}
		if ( ! empty( $payment['provider_reference'] ) ) {
			Orders::set_provider_reference( $result['order_id'], $payment['provider_reference'] );
		}
		$result['payment'] = array_intersect_key( $payment, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
		if ( \PassCaptain\Payments\BankTransfer::applies( $order ) ) {
			// Bank details for the confirmation panel (same data as the access-link mail); only with recipient and IBAN (A265).
			$bank = \PassCaptain\Payments\BankTransfer::details( $order );
			if ( ! empty( $bank['transfer'] ) ) {
				$result['payment']['bank_transfer'] = $bank;
			} else {
				$result['payment']['instructions'] = (string) $bank['instructions'];
			}
		}
		return null;
	}

	/** Checkout response: current totals and the short reference; order_id and public_id stay on the server. */
	private static function checkout_response( array $result ) {
		$fresh = Orders::get( $result['order_id'] );
		if ( $fresh ) {
			$result['total_minor'] = (int) $fresh->total_minor;
			$result['currency'] = $fresh->currency;
			$result['total_formatted'] = \PassCaptain\Frontend\Format::money( (int) $fresh->total_minor, $fresh->currency );
		}
		// Short human-readable order number; the full id and the access link only travel by e-mail.
		$result['reference'] = \PassCaptain\Frontend\Format::order_reference( $result['public_id'] ?? '' );
		// The sequential order_id reveals sales volume; the public_id is half of the portal link. Neither goes to the browser.
		unset( $result['order_id'], $result['public_id'] );
		return new \WP_REST_Response( $result, empty( $result['reused'] ) ? 201 : 200 );
	}

	/**
	 * Checkout error for the browser: a busy database (503, retryable) carries retry_after (Retry-After
	 * header via Sales\CheckoutGate::retry_after_header()); "not enough tickets" (409) carries the
	 * remaining selectable quantity per requested ticket type, so the form can update its maximum.
	 */
	public static function checkout_error( \WP_Error $error, array $payload ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$status = (int) ( $data['status'] ?? 0 );
		if ( 503 === $status && ! empty( $data['retryable'] ) && empty( $data['retry_after'] ) ) {
			$data['retry_after'] = 2;
		} elseif ( 409 === $status && 'wpcap_checkout_failed' === $error->get_error_code() ) {
			$ids = array_map( static function ( $line ) { return is_array( $line ) ? absint( $line['ticket_type_id'] ?? 0 ) : 0; }, (array) ( $payload['items'] ?? array() ) );
			$remaining = array();
			foreach ( \PassCaptain\Frontend\Availability::snapshot( $ids ) as $id => $state ) {
				$remaining[ (string) $id ] = (int) $state['max'];
			}
			if ( $remaining ) { $data['remaining'] = $remaining; }
		} else {
			return $error;
		}
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/** End of the order's active hold as Unix timestamp (earliest active reservation), 0 when none is left. */
	private static function hold_until( $order_id ) {
		global $wpdb;
		$until = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM %i WHERE order_id=%d AND status='active' AND expires_at > %s", $wpdb->prefix . 'wpcap_reservations', absint( $order_id ), \PassCaptain\Clock::mysql() ) );
		return $until ? (int) strtotime( $until . ' UTC' ) : 0;
	}

	/**
	 * Adds data.field (the checkout form field the error belongs to) so the form can mark the field.
	 *
	 * @param string[] $campaign_messages Messages that belong to the discount code field.
	 */
	/**
	 * Manual payment holds tickets for days without any payment: one visitor must not be able to block an event
	 * with a stream of anonymous orders. Unpaid manual orders per e-mail address: setting manual_open_orders_per_email
	 * (SET-PC-20, default 2; filter `wpcap_manual_open_orders_per_email` keeps precedence) and 5 per client within the hold time
	 * (filter `wpcap_manual_orders_per_client`); paid or expired orders do not count.
	 */
	public static function manual_open_orders_limit() {
		return max( 1, (int) apply_filters( 'wpcap_manual_open_orders_per_email', max( 1, min( 50, (int) \PassCaptain\Settings::get( 'manual_open_orders_per_email', 2 ) ) ) ) );
	}

	/** Checkout guard for manual payment (see manual_open_orders_limit()). */
	private static function manual_hold_limit( $email, $hold_minutes ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( is_email( $email ) ) {
			$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s AND payment_provider='manual' AND payment_status='pending' AND status NOT IN ('cancelled','expired','refunded')", $email ) );
			if ( $open >= self::manual_open_orders_limit() ) {
				return new \WP_Error( 'wpcap_manual_open_orders', __( 'Für diese E-Mail-Adresse gibt es bereits offene, noch nicht bezahlte Bestellungen. Bitte zuerst diese bezahlen oder ablaufen lassen.', 'pass-captain' ), array( 'status' => 429, 'field' => 'email' ) );
			}
		}
		// Atomic across workers (A288), per client and – for IPv6 – its /48 network (A280).
		$window = max( HOUR_IN_SECONDS, (int) $hold_minutes * MINUTE_IN_SECONDS );
		if ( ! \PassCaptain\RateLimit::allow_client( 'manual_orders', max( 1, (int) apply_filters( 'wpcap_manual_orders_per_client', 5 ) ), $window ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		return true;
	}

	public static function with_error_field( \WP_Error $error, array $campaign_messages = array() ) {
		$code = $error->get_error_code();
		$map = array(
			'wpcap_invalid_checkout' => 'email',
			'wpcap_terms_required' => 'accept_terms',
			'wpcap_terms_changed' => 'accept_terms',
			'wpcap_age_confirmation_required' => 'confirm_age', // SET-PC-18
			'wpcap_attendees_invalid' => 'attendees',
			'wpcap_attendee_field_required' => 'attendees',
			'wpcap_attendee_field_invalid' => 'attendees',
			'wpcap_attendee_name_required' => 'attendees',
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

	/**
	 * Public checkout configuration: enabled payment methods and legal texts. terms_html /
	 * consumer_info_html are the texts as the event card shows them (A457: the checkout script swaps
	 * them in after a 409 wpcap_terms_changed); never stored by a cache.
	 */
	public static function checkout_options( \WP_REST_Request $request ) {
		$currency = strtoupper( sanitize_text_field( $request->get_param( 'currency' ) ?: 'EUR' ) );
		$providers = array();
		foreach ( \PassCaptain\Payments\Registry::enabled( $currency ) as $id => $gateway ) { $providers[] = array( 'id' => $id, 'title' => $gateway->title() ); }
		// Legal texts in the buyer's language (?locale=en_US), falling back to the default language.
		$locale = \PassCaptain\Mail\Mailer::normalize_locale( (string) $request->get_param( 'locale' ) );
		if ( '' === $locale ) { $locale = \PassCaptain\Mail\Mailer::current_locale(); }
		$terms = (string) \PassCaptain\Settings::legal_text( 'terms_text', $locale );
		$info = (string) \PassCaptain\Settings::legal_text( 'consumer_info_text', $locale );
		$response = rest_ensure_response( array(
			'providers' => $providers,
			'require_terms' => (bool) \PassCaptain\Settings::get( 'require_terms' ),
			'locale' => $locale,
			'terms_text' => wp_kses_post( $terms ),
			'consumer_info_text' => wp_kses_post( $info ),
			'terms_version' => \PassCaptain\Settings::terms_version( $locale ),
			'terms_html' => '' === trim( $terms ) ? '' : wp_kses_post( wpautop( $terms ) ),
			'consumer_info_html' => '' === trim( $info ) ? '' : wp_kses_post( wpautop( $info ) ),
			// Notice for a checkout of dated tickets (Frontend\LegalNotice); '' when switched off.
			'withdrawal_notice' => wp_kses_post( \PassCaptain\Frontend\LegalNotice::withdrawal( true )['text'] ),
			'privacy_policy_url' => esc_url_raw( \PassCaptain\Frontend\LegalNotice::privacy_url() ),
		) );
		if ( method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' ); }
		return $response;
	}

	/** Cache-Control of every answer of the plugin namespace that sets none itself (A370, A505). */
	const NO_STORE = 'no-store, no-cache, must-revalidate, max-age=0, private';

	/**
	 * rest_post_dispatch (A370, A505): answers of the plugin namespace carry live data (availability,
	 * order and booking states, scanner results) or token-protected data and are never stored by
	 * browsers, proxies or CDNs – unless the route set its own Cache-Control.
	 */
	public static function cache_headers( $response, $server = null, $request = null ) {
		unset( $server );
		if ( ! is_object( $response ) || ! method_exists( $response, 'header' ) || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) { return $response; }
		if ( 0 !== strpos( (string) $request->get_route(), '/pass-captain/' ) ) { return $response; }
		$headers = method_exists( $response, 'get_headers' ) ? array_change_key_case( (array) $response->get_headers(), CASE_LOWER ) : array();
		if ( ! isset( $headers['cache-control'] ) ) { $response->header( 'Cache-Control', self::NO_STORE ); }
		return $response;
	}

	/** Access token of an order portal request: header X-WPCAP-Access-Token, else the parameter (A645). */
	private static function access_token_of( \WP_REST_Request $request ) {
		return Credentials::token( $request ); // Header X-WPCAP-Access-Token first, then access_token.
	}

	/**
	 * Order status for the order portal (GET or POST). The token comes from the header
	 * X-WPCAP-Access-Token or the POST body; the query string still works but is deprecated (A645).
	 */
	public static function order_status( \WP_REST_Request $request ) {
		global $wpdb;
		$public_id = sanitize_text_field( $request['public_id'] );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', self::access_token_of( $request ) ) );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status,currency,total_minor,created_at,access_token_hash,access_expires_at FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ), ARRAY_A );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_not_found', __( 'Bestellung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		// A645: hash_equals() runs on a string (Orders::access_matches() casts); A631: a pending link counts.
		if ( 64 !== strlen( $access_token ) || ! Orders::access_matches( $order, $access_token ) ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		unset( $order['access_token_hash'], $order['order_id'] );
		$order['total_minor'] = (int) $order['total_minor'];
		$response = rest_ensure_response( self::prepare( 'order', $order, $request ) );
		$query = method_exists( $request, 'get_query_params' ) ? (array) $request->get_query_params() : array();
		if ( '' === \PassCaptain\Http::str( $request->get_header( 'x-wpcap-access-token' ) ) && '' !== \PassCaptain\Http::str( $query['access_token'] ?? '' ) && method_exists( $response, 'header' ) ) {
			// The token was in the URL (server and proxy logs): still answered, but marked for removal.
			$response->header( 'Deprecation', 'true' );
		}
		return $response;
	}

	public static function transfer_ticket( \WP_REST_Request $request ) {
		$result = Orders::transfer_ticket(
			$request['public_id'],
			Credentials::token( $request ),
			$request->get_param( 'ticket_public_id' ),
			$request->get_param( 'recipient_email' )
		);
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	/** Order row when the public id and the access token match (null otherwise). */
	private static function order_with_access( $public_id, $access_token ) {
		global $wpdb;
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', is_scalar( $access_token ) ? (string) $access_token : '' ) );
		if ( 64 !== strlen( $access_token ) ) { return null; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", sanitize_text_field( (string) $public_id ) ) );
		if ( ! Orders::access_matches( $order, $access_token ) ) { return null; }
		return $order;
	}

	/** Order portal: send the tickets again (new codes). At most 3 per order and hour. */
	public static function resend_tickets( \WP_REST_Request $request ) {
		$public_id = sanitize_text_field( (string) $request['public_id'] );
		if ( ! \PassCaptain\RateLimit::allow( 'portal-resend-client|' . \PassCaptain\RateLimit::client(), 20, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$order = self::order_with_access( $public_id, Credentials::token( $request ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$limit = max( 1, (int) apply_filters( 'wpcap_portal_resend_limit', \PassCaptain\RateLimit::configured( 'rate_portal_resend_per_hour' ) ) );
		if ( ! \PassCaptain\RateLimit::allow( 'portal-resend|' . $order->public_id, $limit, HOUR_IN_SECONDS ) ) {
			/* translators: %d: allowed resends per hour */
			return new \WP_Error( 'wpcap_resend_rate_limited', sprintf( _n( 'Die Tickets können höchstens %d-mal pro Stunde erneut gesendet werden. Bitte versuche es später noch einmal.', 'Die Tickets können höchstens %d-mal pro Stunde erneut gesendet werden. Bitte versuche es später noch einmal.', $limit, 'pass-captain' ), $limit ), array( 'status' => 429 ) );
		}
		// Self-service: transferred tickets are skipped; only_own: only tickets the buyer still holds (no participant e-mail of someone else).
		$result = Orders::resend_tickets( (int) $order->order_id, array( 'skip_transferred' => true, 'self_service' => true, 'only_unassigned' => filter_var( $request->get_param( 'only_own' ), FILTER_VALIDATE_BOOLEAN ) ) );
		if ( is_wp_error( $result ) ) {
			$status = in_array( $result->get_error_code(), array( 'wpcap_mail_failed', 'wpcap_ticket_rotation_failed' ), true ) ? 502 : 409;
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		return rest_ensure_response( array( 'status' => 'sent', 'email' => \PassCaptain\Frontend\Format::mask_email( $order->customer_email ) ) );
	}

	/**
	 * Order portal: refund request after a postponed occurrence (RescheduleRefunds). Token like the
	 * other portal actions (Rest\Credentials); per client 10 and per order 3 requests per hour; the
	 * refund always waits for a staff approval.
	 */
	/** POST /orders/{id}/refund-request (see request_refund()). */
	public static function register_refund_route() {
		register_rest_route( 'pass-captain/v1', '/orders/(?P<public_id>[a-f0-9]{32})/refund-request', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'request_refund' ),
			'permission_callback' => '__return_true', // Capability link: access token (and the portal nonce) checked in the callback.
		), true );
	}

	/**
	 * Order portal refund requests: with ticket_public_ids a self-service cancellation request for chosen
	 * tickets (PortalSelfService, nonce-checked); without, the refund request for tickets of postponed
	 * dates (RescheduleRefunds).
	 */
	public static function request_refund( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ?: $request->get_params();
		if ( is_array( $params ) && array_key_exists( 'ticket_public_ids', $params ) && class_exists( \PassCaptain\PortalSelfService::class ) ) {
			if ( '' === \PassCaptain\Http::str( $params['access_token'] ?? '' ) && '' !== Credentials::token( $request ) && method_exists( $request, 'set_param' ) ) {
				$request->set_param( 'access_token', Credentials::token( $request ) ); // Token from the header.
			}
			return \PassCaptain\PortalSelfService::rest_refund_request( $request );
		}
		if ( ! \PassCaptain\RateLimit::allow( 'portal-refund-client|' . \PassCaptain\RateLimit::client(), 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$order = self::order_with_access( sanitize_text_field( (string) $request['public_id'] ), Credentials::token( $request ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! \PassCaptain\RateLimit::allow( 'portal-refund|' . $order->public_id, 3, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$result = \PassCaptain\RescheduleRefunds::request( $order );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => (int) ( $data['status'] ?? 409 ) ) );
		}
		return rest_ensure_response( array( 'status' => (string) ( $result['status'] ?? '' ), 'refund_id' => (int) ( $result['refund_id'] ?? 0 ) ) );
	}

	/** Order portal: buyer corrects the billing data (company/address/VAT ID) until the invoice is issued. */
	public static function update_billing( \WP_REST_Request $request ) {
		if ( ! \PassCaptain\RateLimit::allow( 'portal-billing|' . \PassCaptain\RateLimit::client(), 30, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$order = self::order_with_access( sanitize_text_field( (string) $request['public_id'] ), Credentials::token( $request ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$input = $request->get_param( 'billing' );
		$result = \PassCaptain\Billing::update_for_order( $order, is_array( $input ) ? $input : array( 'enabled' => false ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Bank account for refunds of a bank-transfer order (order portal, access token). */
	public static function update_payout_account( \WP_REST_Request $request ) {
		if ( ! \PassCaptain\RateLimit::allow( 'portal-payout|' . \PassCaptain\RateLimit::client(), 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$order = self::order_with_access( sanitize_text_field( (string) $request['public_id'] ), Credentials::token( $request ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Bestellzugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$result = \PassCaptain\PayoutAccount::save( $order, (string) $request->get_param( 'iban' ), (string) $request->get_param( 'holder' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Order portal: name/e-mail of a ticket's attendee. A holder change mails the new holder, so the
	 * route is rate limited like resend/billing (A635): 30 per client and 20 per order and hour
	 * (filter `wpcap_portal_assign_rate_limit`).
	 */
	public static function assign_ticket( \WP_REST_Request $request ) {
		if ( ! \PassCaptain\RateLimit::allow( 'portal-assign-client|' . \PassCaptain\RateLimit::client(), 30, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		// Per order only with a valid access link (a guessed order id cannot use up the buyer's budget).
		$order = self::order_with_access( \PassCaptain\Http::str( $request['public_id'] ), Credentials::token( $request ) );
		if ( $order ) {
			/**
			 * Assignments (name/e-mail changes) per order and hour through the order portal (A635).
			 *
			 * @param int    $limit Setting rate_portal_assign_per_hour (default 20, SET-PC-38).
			 * @param object $order Order row.
			 */
			$per_order = max( 1, (int) apply_filters( 'wpcap_portal_assign_rate_limit', \PassCaptain\RateLimit::configured( 'rate_portal_assign_per_hour' ), $order ) );
			if ( ! \PassCaptain\RateLimit::allow( 'portal-assign|' . $order->public_id, $per_order, HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
			}
		}
		$result = TicketAssignments::assign( $request['public_id'], Credentials::token( $request ), $request->get_param( 'ticket_public_id' ), $request->get_param( 'attendee_name' ), $request->get_param( 'attendee_email' ), is_array( $request->get_param( 'answers' ) ) ? $request->get_param( 'answers' ) : null );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function issue_invoice( \WP_REST_Request $request ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', sanitize_text_field( $request['public_id'] ) ) );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_not_found', __( 'Bestellung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", $order->order_id ) );
		// A638: fail closed – an order without ticket lines (no event to check) is for administrators only.
		if ( ! $event_ids && ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'wpcap_invoice_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ), array( 'status' => 403 ) ); }
		foreach ( $event_ids as $event_id ) { if ( ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_invoice_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ), array( 'status' => 403 ) ); } }
		if ( ! Access::order_in_scope( $order->order_id ) ) { return new \WP_Error( 'wpcap_invoice_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$result = Invoices::issue_for_order( $order->order_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function scanner_permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_checkin' );
	}

	public static function pos_permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_manage_orders' );
	}

	/**
	 * Deprecated route POST /pos/sales: kept for old clients but booked through the box office
	 * (BoxOffice\Service::sell()) – open shift, gapless receipt number, journal and Z report, same
	 * fiscal-method rules. Without the box office module the route is gone (410).
	 */
	public static function pos_sale( \WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		$items = $payload['items'] ?? array();
		$key = sanitize_text_field( (string) ( $payload['idempotency_key'] ?? '' ) );
		if ( ! is_array( $items ) || ! $items || count( $items ) > 10 || '' === $key ) { return new \WP_Error( 'wpcap_pos_items', __( 'POS-Verkaufspositionen oder Idempotency-Key sind ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! \PassCaptain\Modules::enabled( \PassCaptain\Modules\BoxOffice::class ) ) {
			return new \WP_Error( 'wpcap_pos_route_gone', __( 'Kassenverkäufe laufen über das Kassenmodul (Schicht, Belegnummer, Z-Bericht).', 'pass-captain' ), array( 'status' => 410 ) );
		}
		global $wpdb;
		$quantities = array();
		$occurrence_id = 0;
		foreach ( $items as $item ) {
			$type_id = absint( $item['ticket_type_id'] ?? 0 );
			$occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
			if ( ! $occ || ( $occurrence_id && $occ !== $occurrence_id ) ) { return new \WP_Error( 'wpcap_pos_items', __( 'Alle Positionen eines Kassenverkaufs müssen zum selben Termin gehören.', 'pass-captain' ), array( 'status' => 400 ) ); }
			if ( $occ !== $occurrence_id ) {
				// Same scope check as before the box office module took over the sale.
				$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $occ ) );
				if ( ! $event_id || ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_pos_forbidden', __( 'Für dieses Event fehlt die POS-Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) ); }
			}
			$occurrence_id = $occ;
			$quantities[ $type_id ] = ( $quantities[ $type_id ] ?? 0 ) + max( 0, (int) ( $item['quantity'] ?? 0 ) );
		}
		$result = \PassCaptain\BoxOffice\Service::sell( array(
			'occurrence_id' => $occurrence_id,
			'quantities' => $quantities,
			'email' => (string) ( $payload['email'] ?? '' ),
			// "payment_method" is the field name of the old POS route.
			'method' => sanitize_key( (string) ( $payload['method'] ?? $payload['payment_method'] ?? 'cash' ) ),
			'form_key' => 'rest-' . $key,
		) );
		if ( is_wp_error( $result ) ) { return $result; }
		$sale = $result['sale'];
		$order = Orders::get( (int) $sale->order_id );
		$tickets = $order ? Orders::tickets_for_order( $order->order_id ) : array();
		return new \WP_REST_Response( array( 'order_id' => (int) $sale->order_id, 'public_id' => $order ? $order->public_id : '', 'status' => $order ? $order->status : '', 'payment_status' => $order ? $order->payment_status : '', 'receipt_number' => (string) $sale->receipt_number, 'tickets_issued' => (bool) $tickets, 'ticket_issuance_pending' => ! $tickets, 'deprecated' => true ), empty( $result['reused'] ) ? 201 : 200 );
	}

	/**
	 * Offline queue of the web scanner without the admission module: `scans` [{token, idempotency_key,
	 * occurrence_id?}] (1–100). Each scan carries its idempotency key, so a repeated upload never books
	 * twice or turns into a double-admission conflict (A589). The old form `tokens` (codes only) is still
	 * accepted; such a code gets a key derived from code and user, so it is booked at most once this way.
	 * A scan the server could not book comes back with `retryable: true` (A345).
	 */
	public static function offline_sync( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$scans = $payload['scans'] ?? ( $payload['tokens'] ?? array() );
		if ( ! is_array( $scans ) || ! $scans || count( $scans ) > 100 ) { return new \WP_Error( 'wpcap_offline_batch_invalid', __( 'Der Offline-Scanbatch muss 1 bis 100 Codes enthalten.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$results = array();
		foreach ( $scans as $scan ) {
			$scan = is_array( $scan ) ? $scan : array( 'token' => (string) $scan );
			$token = sanitize_text_field( \PassCaptain\Http::str( $scan['token'] ?? '' ) );
			$key = sanitize_text_field( \PassCaptain\Http::str( $scan['idempotency_key'] ?? '' ) );
			// Same key format as check_in(): the queue reuses the key of a timed-out online attempt (A541).
			$context = array( 'source' => 'offline', 'idempotency_key' => '' !== $key ? $key : 'legacy-offline:' . hash( 'sha256', \PassCaptain\Checkin::normalize_code( $token ) ?: strtolower( $token ) ), 'occurrence_id' => absint( $scan['occurrence_id'] ?? 0 ) );
			$result = \PassCaptain\Checkin::perform( $token, array_filter( $context ) );
			if ( is_wp_error( $result ) && \PassCaptain\Errors::is_busy( $result ) ) {
				$results[] = array( 'status' => 'retry', 'retryable' => true, 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'idempotency_key' => $key );
			} elseif ( is_wp_error( $result ) ) {
				$results[] = array( 'status' => 'conflict', 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'idempotency_key' => $key );
			} else {
				$results[] = $result + array( 'idempotency_key' => $key );
			}
		}
		return rest_ensure_response( array( 'results' => $results, 'offline_conflicts_are_possible' => true ) );
	}

	public static function redeem_pass( \WP_REST_Request $request ) {
		// Redeeming passes is its own capability (split off wpcap_checkin); event access stays wpcap_checkin.
		if ( ! current_user_can( \PassCaptain\Capabilities::REDEEM_PASSES ) ) { return new \WP_Error( 'wpcap_pass_redeem_capability', __( 'Du darfst keine Pässe einlösen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$type_id = absint( $request->get_param( 'ticket_type_id' ) );
		global $wpdb;
		$occurrence_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
		if ( ! $occurrence_id || ! Access::can_access_occurrence( $occurrence_id, 'wpcap_checkin' ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Für dieses Event fehlt die Einlassberechtigung.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$result = Memberships::redeem( $request->get_param( 'token' ), $type_id, $request->get_param( 'idempotency_key' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function issue_member_pass( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$rules = $payload['rules'] ?? array();
		if ( ! is_array( $rules ) || ! $rules || count( $rules ) > 50 ) { return new \WP_Error( 'wpcap_pass_rules_invalid', __( 'Mindestens eine Passregel ist erforderlich.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! method_exists( Memberships::class, 'validate_rule_for_current_user' ) ) {
			// Fallback pre-check on the raw event ids; validate_rule_for_current_user() below is the complete check
			// (event derived from the ticket type, resource scope of service rules, "any" and global rules).
			$event_ids = array_map( static function ( $rule ) { return is_array( $rule ) ? absint( $rule['event_id'] ?? 0 ) : 0; }, $rules );
			if ( ! Memberships::in_scope( $event_ids, Memberships::REQUEST_CAP ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für eine Passregel.', 'pass-captain' ), array( 'status' => 403 ) ); }
		}
		// Same scope check as the admin screen for every rule (event via ticket type, resource, "any", global rules).
		$normalized = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) { return new \WP_Error( 'wpcap_pass_rules_invalid', __( 'Mindestens eine Passregel ist erforderlich.', 'pass-captain' ), array( 'status' => 400 ) ); }
			$checked = method_exists( Memberships::class, 'validate_rule_for_current_user' ) ? Memberships::validate_rule_for_current_user( $rule ) : $rule;
			if ( is_wp_error( $checked ) ) { return $checked; }
			$normalized[] = $checked;
		}
		$text = static function ( $key ) use ( $payload ) { return is_scalar( $payload[ $key ] ?? '' ) ? (string) ( $payload[ $key ] ?? '' ) : ''; };
		$result = Memberships::issue( $text( 'email' ), $text( 'valid_from' ), $text( 'valid_until' ), $normalized );
		// A481: without administrator rights the pass is only requested; a second person approves it.
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'pass_id' => (int) $result['pass_id'], 'status' => 'active' === $result['status'] ? 'issued' : 'pending' ), 'active' === $result['status'] ? 201 : 202 );
	}

	public static function member_approve_permission() {
		return is_user_logged_in() && current_user_can( Memberships::APPROVE_CAP );
	}

	public static function list_member_passes( \WP_REST_Request $request ) {
		$rows = Memberships::list_passes( sanitize_key( (string) $request->get_param( 'status' ) ) );
		return rest_ensure_response( array_map( static function ( $row ) {
			return array( 'pass_id' => (int) $row->pass_id, 'email' => (string) $row->member_email, 'status' => (string) $row->status, 'valid_from' => (string) $row->valid_from, 'valid_until' => (string) $row->valid_until, 'requested_by' => (int) ( $row->requested_by ?? 0 ), 'approved_by' => (int) ( $row->approved_by ?? 0 ), 'uses' => (int) $row->uses, 'rules' => array_map( static function ( $rule ) { return array( 'event_id' => (int) $rule->event_id, 'ticket_type_id' => (int) $rule->ticket_type_id, 'uses_total' => (int) $rule->uses_total, 'uses_per_day' => (int) $rule->uses_per_day ); }, $row->rules ) );
		}, $rows ) );
	}

	public static function approve_member_pass( \WP_REST_Request $request ) {
		$result = Memberships::approve( absint( $request['pass_id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'pass_id' => (int) $result['pass_id'], 'status' => 'issued' ) );
	}

	public static function reject_member_pass( \WP_REST_Request $request ) {
		$result = Memberships::reject( absint( $request['pass_id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'status' => 'rejected' ) );
	}

	public static function revoke_member_pass( \WP_REST_Request $request ) {
		global $wpdb;
		$pass_id = absint( $request['pass_id'] );
		$events = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d", $pass_id ) );
		if ( ! $events ) { return new \WP_Error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		// current_user_can_manage_pass() is the complete check (service rules: managed resource); the raw event
		// check is the fallback (it would refuse every service pass to non-administrators).
		if ( ! method_exists( Memberships::class, 'current_user_can_manage_pass' ) && ! Memberships::in_scope( array_map( 'intval', $events ), Memberships::REQUEST_CAP ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( method_exists( Memberships::class, 'current_user_can_manage_pass' ) && ! Memberships::current_user_can_manage_pass( $pass_id ) ) { return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$result = Memberships::revoke( $pass_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'status' => 'revoked' ) );
	}

	public static function manage_events_permission() { return is_user_logged_in() && current_user_can( 'wpcap_manage_events' ); }

	/** Non-binding discount preview for the checkout estimate (Campaigns::preview()). */
	public static function preview_campaign( \WP_REST_Request $request ) {
		if ( ! \PassCaptain\RateLimit::allow( 'campaign-preview|' . \PassCaptain\RateLimit::client(), (int) apply_filters( 'wpcap_campaign_preview_rate_limit', 30 ), 600 ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$params = $request->get_json_params() ?: $request->get_params();
		$result = Campaigns::preview( (array) ( $params['items'] ?? array() ), sanitize_text_field( \PassCaptain\Http::str( $params['code'] ?? '' ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function create_campaign( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_campaign_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$payload = (array) $request->get_json_params();
		$payload['event_id'] = $event_id;
		// A616: local times (site time zone), ISO 8601 with offset, or a date (ends_at: end of that day) → UTC.
		$timezone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		foreach ( array( 'starts_at' => false, 'ends_at' => true ) as $bound => $is_end ) {
			$utc = Campaigns::window_bound( $payload[ $bound ] ?? '', $is_end, $timezone );
			if ( is_wp_error( $utc ) ) { return $utc; }
			$payload[ $bound ] = (string) $utc;
		}
		if ( ! empty( $payload['ticket_type_id'] ) ) {
			global $wpdb;
			$valid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d AND o.event_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $payload['ticket_type_id'] ), $event_id ) );
			if ( ! $valid ) { return new \WP_Error( 'wpcap_campaign_scope', __( 'Die Ticketart gehört nicht zu diesem Event.', 'pass-captain' ), array( 'status' => 400 ) ); }
		}
		$result = Campaigns::create( $payload );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	/** Whether every requested line is a ticket type with base price 0 (free order without a payment method). */
	private static function free_items_only( $items ) {
		global $wpdb;
		$ids = array();
		foreach ( (array) $items as $line ) {
			$id = is_array( $line ) ? absint( $line['ticket_type_id'] ?? 0 ) : 0;
			if ( ! $id ) { return false; }
			$ids[ $id ] = $id;
		}
		if ( ! $ids || count( $ids ) > 50 ) { return false; }
		$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_type_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND price_minor<>0', array_merge( array( $wpdb->prefix . 'wpcap_ticket_types' ), array_values( $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder list.
		return 0 === $paid;
	}

	public static function join_waitlist( \WP_REST_Request $request ) {
		$result = Waitlists::join( $request['event_id'], $request->get_param( 'occurrence_id' ), $request->get_param( 'ticket_type_id' ), $request->get_param( 'email' ), $request->get_param( 'quantity' ) ?? 1 );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function offer_waitlist( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		$type_id = absint( $request->get_param( 'ticket_type_id' ) );
		global $wpdb;
		$type_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $type_id ) );
		if ( $type_event !== $event_id || ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_waitlist_forbidden', __( 'Keine Berechtigung für diese Ticketart.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$result = Waitlists::offer_next( $event_id, $type_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function accept_waitlist_offer( \WP_REST_Request $request ) {
		// Attendee data (participant fields) is collected on acceptance (A536); Orders::validate_attendees() sanitises it.
		$attendees = $request->get_param( 'attendees' );
		$result = Waitlists::accept_offer( $request['waitlist_id'], Credentials::token( $request, 'token' ), array( 'provider' => sanitize_key( \PassCaptain\Http::str( $request->get_param( 'provider' ) ) ), 'accept_terms' => \PassCaptain\Http::accepted( $request->get_param( 'accept_terms' ) ), 'terms_version' => \PassCaptain\Http::str( $request->get_param( 'terms_version' ) ), 'locale' => \PassCaptain\Http::str( $request->get_param( 'locale' ) ), 'attendees' => is_array( $attendees ) ? $attendees : array(), 'billing' => is_array( $request->get_param( 'billing' ) ) ? $request->get_param( 'billing' ) : null, 'code' => \PassCaptain\Http::str( $request->get_param( 'code' ) ), 'campaign_codes' => is_array( $request->get_param( 'campaign_codes' ) ) ? $request->get_param( 'campaign_codes' ) : array(), 'voucher_code' => \PassCaptain\Http::str( $request->get_param( 'voucher_code' ) ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function decline_waitlist_offer( \WP_REST_Request $request ) {
		$result = Waitlists::decline_offer( $request['waitlist_id'], Credentials::token( $request, 'token' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function list_waitlist( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_orders' ) ) { return new \WP_Error( 'wpcap_waitlist_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$rows = array_map( static function ( $row ) {
			return array( 'waitlist_id' => (int) $row->waitlist_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'occurrence_id' => (int) $row->occurrence_id, 'email' => \PassCaptain\Pii::show( 'buyer_email', (string) $row->email ), 'position' => (int) $row->position, 'queue_rank' => (int) $row->queue_rank, 'quantity' => (int) $row->quantity, 'priority' => (int) $row->priority, 'status' => (string) $row->status, 'offer_expires_at' => $row->offer_expires_at, 'order_id' => $row->order_public_id ? (string) $row->order_public_id : null );
		}, array_values( array_filter( Waitlists::entries( $event_id ), static function ( $row ) { return ! (int) $row->occurrence_id || Access::occurrence_in_scope( (int) $row->occurrence_id ); } ) ) );
		return rest_ensure_response( $rows );
	}

	public static function report_permission( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		// The summary covers all occurrences of the event: location-scoped users need all of them in scope (A119).
		if ( Access::can_access_event( $event_id, 'wpcap_view_reports' ) && Access::event_fully_in_scope( $event_id, 0, 'wpcap_view_reports' ) ) { return true; }
		// true, false or a 429 WP_Error (never collapse it with || – an object is truthy).
		return DeveloperKeys::permission( $request, 'reports.read', $event_id );
	}

	public static function event_report( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		// Day boundaries follow the site timezone (like the reports page), not UTC.
		$tz = \PassCaptain\Reports\Scope::site_timezone();
		$today = \PassCaptain\Clock::today( $tz );
		$from = sanitize_text_field( $request->get_param( 'from' ) ?: ( new \DateTimeImmutable( $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '-30 days' )->format( 'Y-m-d' ) );
		$to = sanitize_text_field( $request->get_param( 'to' ) ?: $today );
		$from_parts = array_map( 'intval', explode( '-', $from ) );
		$to_parts = array_map( 'intval', explode( '-', $to ) );
		$valid_dates = 3 === count( $from_parts ) && 3 === count( $to_parts ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && checkdate( $from_parts[1], $from_parts[2], $from_parts[0] ) && checkdate( $to_parts[1], $to_parts[2], $to_parts[0] );
		if ( ! $valid_dates || $from > $to || strtotime( $to ) - strtotime( $from ) > 90 * DAY_IN_SECONDS ) { return new \WP_Error( 'wpcap_report_range', __( 'Berichtszeitraum muss gültig und höchstens 90 Tage lang sein.', 'pass-captain' ), array( 'status' => 400 ) ); }
		// Cached per site and event under generation keys: every write to orders, payments, refunds,
		// check-ins, ticket types, occurrences or the event invalidates it (Reports\Cache, A201).
		$data = \PassCaptain\Reports\Cache::remember( 'rest_event_report', $event_id, array( 'from' => $from, 'to' => $to, 'tz' => $tz ), static function () use ( $event_id, $from, $to, $tz ) {
			return self::event_report_data( $event_id, $from, $to, $tz );
		} );
		// Staff without access to payment data (check-in/partner roles) get no amounts; developer keys keep the full report.
		$minimise = get_current_user_id() && ! \PassCaptain\Security\DataPolicy::can_view_payment();
		return rest_ensure_response( $minimise ? \PassCaptain\Security\DataPolicy::strip_payment( (array) $data ) : $data );
	}

	/** Uncached figures of GET /reports/events/{id} (see event_report()); day boundaries in $tz (A199). */
	public static function event_report_data( $event_id, $from, $to, $tz = null ) {
		global $wpdb;
		$tz = $tz ?? \PassCaptain\Reports\Scope::site_timezone();
		list( $range_start, $range_end ) = \PassCaptain\Reports\Data::bounds( $from, $to, $tz );
		$sales = $wpdb->get_results( $wpdb->prepare( "SELECT ord.currency,COUNT(DISTINCT ord.order_id) AS orders_count,COALESCE(SUM(" . \PassCaptain\OrderLines::paid( 'quantity' ) . "),0) AS tickets_sold,COALESCE(SUM(" . \PassCaptain\OrderLines::paid( 'line_total_minor' ) . "),0) AS gross_minor FROM {$wpdb->prefix}wpcap_orders ord INNER JOIN {$wpdb->prefix}wpcap_order_items oi ON oi.order_id=ord.order_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND ord.payment_status='paid' AND ord.status<>'refunded' AND ord.created_at >= %s AND ord.created_at < %s GROUP BY ord.currency", $event_id, $range_start, $range_end ), ARRAY_A );
		$checkins = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_checkins WHERE event_id=%d AND result='checked_in' AND created_at >= %s AND created_at < %s", $event_id, $range_start, $range_end ) );
		$currency_totals = array();
		foreach ( $sales as $sale ) { $currency_totals[] = array( 'currency' => $sale['currency'], 'orders_count' => (int) $sale['orders_count'], 'tickets_sold' => (int) $sale['tickets_sold'], 'gross_minor' => (int) $sale['gross_minor'] ); }
		$data = array( 'event_id' => $event_id, 'from' => $from, 'to' => $to, 'sales_by_currency' => $currency_totals, 'checkins' => $checkins, 'financial_basis' => 'gross_line_totals_before_order_discounts_tax_and_refunds' );
		return $data;
	}

	public static function create_developer_key( \WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		$event_id = absint( $payload['event_id'] ?? 0 );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_api_key_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( ! empty( $payload['expires_at'] ) && ( ! is_string( $payload['expires_at'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $payload['expires_at'] ) || strtotime( $payload['expires_at'] . ' UTC' ) <= \PassCaptain\Clock::now() ) ) { return new \WP_Error( 'wpcap_api_key_expiry', __( 'Ablaufdatum muss in der Zukunft liegen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		// Expiry (UTC, future), scopes and the optional per-key rate limit are validated in issue().
		$result = DeveloperKeys::issue( $event_id, (array) ( $payload['scopes'] ?? array() ), (string) ( $payload['label'] ?? '' ), (string) ( $payload['expires_at'] ?? '' ), $payload['rate_limit'] ?? 0 );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	public static function revoke_developer_key( \WP_REST_Request $request ) {
		$result = DeveloperKeys::revoke( $request['key_id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'status' => 'revoked' ) );
	}

	public static function developer_key_permission( \WP_REST_Request $request ) {
		return DeveloperKeys::permission( $request, 'events.read', absint( $request['event_id'] ) );
	}

	public static function developer_event( \WP_REST_Request $request ) {
		global $wpdb;
		// Authorised (and rate limited) once in developer_key_permission().
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name,slug,description,status FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", absint( $request['event_id'] ) ), ARRAY_A );
		return $event ? rest_ensure_response( self::prepare( 'developer_event', $event, $request ) ) : new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	/** GET /developer/events/{id}/service-slots: scope slots.read (DeveloperKeys::ROUTES). */
	public static function developer_slots_permission( \WP_REST_Request $request ) {
		return DeveloperKeys::permission( $request, 'slots.read', absint( $request['event_id'] ) );
	}

	/** All service slots of the event with capacity and booked places; no customer data. */
	public static function developer_service_slots( \WP_REST_Request $request ) {
		// Authorised (and rate limited) once in developer_slots_permission().
		$rows = array_map( static function ( $slot ) {
			return array( 'slot_id' => (int) $slot->slot_id, 'resource_id' => (int) $slot->resource_id, 'resource_name' => (string) $slot->resource_name, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'capacity' => (int) $slot->capacity, 'booked' => (int) $slot->booked, 'status' => (string) $slot->status );
		}, ServiceBookings::slots( absint( $request['event_id'] ) ) );
		return rest_ensure_response( $rows );
	}

	public static function get_event_translation( \WP_REST_Request $request ) {
		global $wpdb;
		$locale = sanitize_text_field( $request->get_param( 'locale' ) ?: determine_locale() );
		$translation = $wpdb->get_row( $wpdb->prepare( "SELECT tr.locale,tr.name,tr.description,tr.location,tr.updated_at FROM %i tr INNER JOIN %i e ON e.event_id=tr.event_id WHERE tr.event_id=%d AND tr.locale=%s AND e.status='published'", $wpdb->prefix . 'wpcap_event_translations', $wpdb->prefix . 'wpcap_events', absint( $request['event_id'] ), $locale ), ARRAY_A );
		return $translation ? rest_ensure_response( $translation ) : new \WP_Error( 'wpcap_translation_not_found', __( 'Übersetzung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	public static function save_event_translation( \WP_REST_Request $request ) {
		global $wpdb;
		$event_id = absint( $request['event_id'] );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_translation_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$payload = (array) $request->get_json_params();
		$locale = sanitize_text_field( $payload['locale'] ?? '' );
		$name = sanitize_text_field( $payload['name'] ?? '' );
		if ( ! preg_match( '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale ) || '' === $name ) { return new \WP_Error( 'wpcap_translation_invalid', __( 'Locale oder übersetzter Titel ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wpcap_event_translations (event_id,locale,name,description,location,updated_at) VALUES (%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),location=VALUES(location),updated_at=VALUES(updated_at)", $event_id, $locale, $name, wp_kses_post( $payload['description'] ?? '' ), sanitize_text_field( $payload['location'] ?? '' ), \PassCaptain\Clock::mysql() ) );
		\PassCaptain\PageCache::event_changed( $event_id, 'translation' ); // A446.
		return rest_ensure_response( array( 'event_id' => $event_id, 'locale' => $locale, 'status' => 'saved' ) );
	}

	/** POST /tax-profiles: creates a draft (A115: activation only through the review step in the admin, Tax\Profiles::activate()). */
	public static function save_tax_profile( \WP_REST_Request $request ) {
		$data = (array) $request->get_json_params();
		unset( $data['tax_profile_id'] ); // Creates only; drafts are edited in the admin.
		if ( ! isset( $data['rate_basis_points'] ) && isset( $data['rate'] ) && is_scalar( $data['rate'] ) && preg_match( '/^(\d{1,3})(?:[.,](\d{1,2}))?$/', trim( (string) $data['rate'] ), $m ) ) {
			$data['rate_basis_points'] = (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '', 2, '0' ); // "rate" in percent (documented alias).
		}
		$id = \PassCaptain\Tax\Profiles::save_draft( $data );
		if ( is_wp_error( $id ) ) { return $id; }
		return new \WP_REST_Response( array( 'tax_profile_id' => (int) $id, 'status' => 'draft', 'requires_review' => true ), 201 );
	}

	public static function create_resource( \WP_REST_Request $request ) {
		$id = ServiceBookings::create_resource( absint( $request['event_id'] ), (array) $request->get_json_params() );
		return is_wp_error( $id ) ? $id : new \WP_REST_Response( array( 'resource_id' => (int) $id ), 201 );
	}

	public static function create_service_slot( \WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		// Strict: "Y-m-d H:i:s" (UTC) or ISO 8601 with offset; no strtotime() guessing ("tomorrow", server time zone).
		foreach ( array( 'starts_at', 'ends_at' ) as $field ) {
			$utc = \PassCaptain\DateInput::utc( $payload[ $field ] ?? null, $field );
			if ( is_wp_error( $utc ) ) { return $utc; }
			if ( null !== $utc ) { $payload[ $field ] = $utc; }
		}
		$id = ServiceBookings::create_slot( absint( $request['resource_id'] ), $payload );
		return is_wp_error( $id ) ? $id : new \WP_REST_Response( array( 'slot_id' => (int) $id ), 201 );
	}

	public static function set_admission_days( \WP_REST_Request $request ) {
		global $wpdb;
		$occurrence_id = absint( $request['occurrence_id'] );
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.event_id FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
		if ( ! $occurrence || ! Access::can_access_occurrence( $occurrence_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_admission_forbidden', __( 'Keine Berechtigung für diesen Termin.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$payload = $request->get_json_params();
		$days = $payload['days'] ?? array();
		if ( ! is_array( $days ) || ! $days || count( $days ) > 31 ) { return new \WP_Error( 'wpcap_admission_days_invalid', __( 'Bitte 1 bis 31 Veranstaltungstage angeben.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$start_date = wp_date( 'Y-m-d', strtotime( $occurrence->starts_at . ' UTC' ), new \DateTimeZone( $occurrence->timezone ) );
		$end_date = $occurrence->ends_at ? wp_date( 'Y-m-d', strtotime( $occurrence->ends_at . ' UTC' ), new \DateTimeZone( $occurrence->timezone ) ) : $start_date;
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $days as $entry ) {
			$date = sanitize_text_field( $entry['date'] ?? '' );
			$policy = sanitize_key( $entry['policy'] ?? 'single_entry' );
			$parts = array_map( 'intval', explode( '-', $date ) );
			$valid_date = 3 === count( $parts ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && checkdate( $parts[1], $parts[2], $parts[0] );
			if ( ! $valid_date || $date < $start_date || $date > $end_date || ! in_array( $policy, array( 'single_entry', 'daily', 'reentry' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_admission_day_invalid', __( 'Ein Veranstaltungstag oder eine Einlassregel ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (occurrence_id,service_date,admission_policy,max_entries) VALUES (%d,%s,%s,%d) ON DUPLICATE KEY UPDATE admission_policy=VALUES(admission_policy),max_entries=VALUES(max_entries)', $wpdb->prefix . 'wpcap_occurrence_days', $occurrence_id, $date, $policy, min( 20, max( 1, absint( $entry['max_entries'] ?? 1 ) ) ) ) );
			if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_admission_save', __( 'Einlassregeln konnten nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		return rest_ensure_response( array( 'status' => 'saved', 'day_count' => count( $days ) ) );
	}

	public static function service_slots( \WP_REST_Request $request ) {
		// Only slots a customer can book now (free places, no resource/provider conflict incl. buffers).
		// Optional (A268, booking page): resource=<id>, week=<Y-m-d> (Monday in the event timezone).
		$event_id = absint( $request['event_id'] );
		$filter = array( 'resource_id' => absint( $request->get_param( 'resource' ) ) );
		$week = \PassCaptain\Http::str( $request->get_param( 'week' ) );
		if ( '' !== $week ) {
			$range = \PassCaptain\Frontend\ServiceBooking::week_range( $event_id, $week );
			if ( ! $range ) { return new \WP_Error( 'wpcap_invalid_week', __( 'Ungültige Woche.', 'pass-captain' ), array( 'status' => 400 ) ); }
			$filter += $range;
		}
		return rest_ensure_response( ServiceBookings::public_slots( $event_id, array_filter( $filter ) ) );
	}

	public static function book_service_slot( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		// A421: -2 or 2.5 is no quantity (absint() turned -2 into 2); 1 when not given.
		$quantity = isset( $payload['quantity'] ) ? \PassCaptain\Http::positive_int( $payload['quantity'] ) : 1;
		if ( $quantity < 1 || $quantity > ServiceBookings::MAX_QUANTITY ) {
			return new \WP_Error( 'wpcap_service_quantity', __( 'Bitte eine gültige Anzahl angeben.', 'pass-captain' ), array( 'status' => 400, 'field' => 'quantity' ) );
		}
		$result = ServiceBookings::reserve( absint( $request['slot_id'] ), $payload['email'] ?? '', $quantity, 0, 10, is_scalar( $payload['idempotency_key'] ?? null ) ? (string) $payload['idempotency_key'] : '', array( 'request' => $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function validate_ticket( \WP_REST_Request $request ) {
		$result = \PassCaptain\Checkin::preview( \PassCaptain\Http::str( $request->get_param( 'token' ) ) );
		return is_wp_error( $result ) ? \PassCaptain\Checkin::client_error( $result ) : rest_ensure_response( $result );
	}

	/** Web scanner check-in; the logic lives in Checkin::perform() (shared with the app API). */
	public static function check_in( \WP_REST_Request $request ) {
		// The source is never taken from the client (A592): "offline" would evaluate a client-chosen time
		// and turn refusals into conflicts.
		$result = \PassCaptain\Checkin::perform( \PassCaptain\Http::str( $request->get_param( 'token' ) ), array_filter( array( 'source' => 'web', 'idempotency_key' => sanitize_text_field( \PassCaptain\Http::str( $request->get_param( 'idempotency_key' ) ) ), 'occurrence_id' => \PassCaptain\Http::positive_int( $request->get_param( 'occurrence_id' ) ) ) ) );
		return is_wp_error( $result ) ? \PassCaptain\Checkin::client_error( $result ) : rest_ensure_response( $result );
	}
}
