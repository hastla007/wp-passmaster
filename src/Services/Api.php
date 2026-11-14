<?php
/**
 * REST endpoints for service bookings and waitlists (namespace wp-pass-captain/v1).
 *
 * Buyers authenticate with the order access token (bookings paid through an order) or the booking's
 * manage token (bookings without order); waitlist entries with their offer/manage token. Staff
 * actions (partner decision, check-in, no-show) need ServiceResources::can_manage().
 *
 * @package PassCaptain
 */

namespace PassCaptain\Services;

use PassCaptain\Memberships;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\ServiceWaitlist;
use PassCaptain\Waitlists;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Api {
	const NS = 'wp-pass-captain/v1';

	public static function register_routes() {
		$public = '__return_true';
		$order = '/orders/(?P<public_id>[a-f0-9]{32})/service-bookings';
		$routes = array(
			array( $order, \WP_REST_Server::READABLE, 'order_bookings', $public ),
			array( $order . '/(?P<booking_id>\d+)/cancel', \WP_REST_Server::CREATABLE, 'order_cancel', $public ),
			array( $order . '/(?P<booking_id>\d+)/rebook', \WP_REST_Server::CREATABLE, 'order_rebook', $public ),
			array( $order . '/(?P<booking_id>\d+)/rebook-options', \WP_REST_Server::READABLE, 'order_rebook_options', $public ),
			array( '/service-bookings/(?P<booking_id>\d+)/attach-order', \WP_REST_Server::CREATABLE, 'attach_order', $public ),
			array( '/service-bookings/(?P<booking_id>\d+)/confirm', \WP_REST_Server::CREATABLE, 'confirm_free', $public ),
			array( '/service-bookings/(?P<booking_id>\d+)/cancel', \WP_REST_Server::CREATABLE, 'token_cancel', $public ),
			array( '/service-bookings/(?P<booking_id>\d+)/rebook', \WP_REST_Server::CREATABLE, 'token_rebook', $public ),
			array( '/service-bookings/(?P<booking_id>\d+)/decide', \WP_REST_Server::CREATABLE, 'decide', array( __CLASS__, 'staff_permission' ) ),
			array( '/service-bookings/(?P<booking_id>\d+)/check-in', \WP_REST_Server::CREATABLE, 'check_in', array( __CLASS__, 'staff_permission' ) ),
			array( '/service-bookings/(?P<booking_id>\d+)/no-show', \WP_REST_Server::CREATABLE, 'no_show', array( __CLASS__, 'staff_permission' ) ),
			array( '/service-slots/(?P<slot_id>\d+)/waitlist', \WP_REST_Server::CREATABLE, 'join_slot_waitlist', $public ),
			array( '/service-waitlist/(?P<entry_id>\d+)', \WP_REST_Server::READABLE, 'waitlist_position', $public ),
			array( '/service-waitlist/(?P<entry_id>\d+)/accept', \WP_REST_Server::CREATABLE, 'waitlist_accept', $public ),
			array( '/service-waitlist/(?P<entry_id>\d+)/decline', \WP_REST_Server::CREATABLE, 'waitlist_decline', $public ),
			array( '/service-waitlist/(?P<entry_id>\d+)/leave', \WP_REST_Server::CREATABLE, 'waitlist_leave', $public ),
			array( '/waitlist/(?P<waitlist_id>\d+)/decline', \WP_REST_Server::CREATABLE, 'event_waitlist_decline', $public ),
			array( '/waitlist/(?P<waitlist_id>\d+)/unsubscribe', \WP_REST_Server::CREATABLE, 'event_waitlist_unsubscribe', $public ),
			array( '/member-passes/redeem-service', \WP_REST_Server::CREATABLE, 'redeem_service_pass', $public ),
		);
		foreach ( $routes as list( $path, $methods, $callback, $permission ) ) {
			register_rest_route( self::NS, $path, array( 'methods' => $methods, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => $permission ) );
		}
	}

	/** Staff: logged in and allowed to manage the booking's resource (event staff or its organizer). */
	public static function staff_permission( \WP_REST_Request $request ) {
		$booking = ServiceBookings::get( absint( $request['booking_id'] ) );
		return $booking && ServiceResources::can_manage( ServiceBookings::resource_of( $booking ) );
	}

	private static function respond( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	private static function denied() {
		return new \WP_Error( 'wpcap_order_access_denied', __( 'Der Zugriff ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/* -------- Buyer via order access token */

	public static function order_bookings( \WP_REST_Request $request ) {
		$order = ServiceBookings::for_order_access( $request['public_id'], $request->get_param( 'access_token' ) );
		return $order ? rest_ensure_response( ServiceBookings::for_order( (int) $order->order_id ) ) : self::denied();
	}

	public static function order_cancel( \WP_REST_Request $request ) {
		$booking = ServiceBookings::for_order_access( $request['public_id'], $request->get_param( 'access_token' ), absint( $request['booking_id'] ) );
		return $booking ? self::respond( ServiceBookings::cancel( (int) $booking->booking_id, array( 'actor' => 'customer' ) ) ) : self::denied();
	}

	public static function order_rebook( \WP_REST_Request $request ) {
		$booking = ServiceBookings::for_order_access( $request['public_id'], $request->get_param( 'access_token' ), absint( $request['booking_id'] ) );
		return $booking ? self::respond( ServiceBookings::rebook( (int) $booking->booking_id, absint( $request->get_param( 'slot_id' ) ), array( 'actor' => 'customer', 'idempotency_key' => (string) $request->get_param( 'idempotency_key' ), 'provider' => sanitize_key( (string) $request->get_param( 'provider' ) ) ) ) ) : self::denied();
	}

	public static function order_rebook_options( \WP_REST_Request $request ) {
		$booking = ServiceBookings::for_order_access( $request['public_id'], $request->get_param( 'access_token' ), absint( $request['booking_id'] ) );
		if ( ! $booking ) { return self::denied(); }
		return rest_ensure_response( array_map( array( __CLASS__, 'slot_view' ), ServiceBookings::rebook_options( $booking ) ) );
	}

	/** Public slot data (no booking details). */
	public static function slot_view( $slot ) {
		return array( 'slot_id' => (int) $slot->slot_id, 'resource_name' => (string) $slot->resource_name, 'starts_at' => (string) $slot->starts_at, 'ends_at' => (string) $slot->ends_at, 'free' => (int) $slot->free, 'unit_minor' => (int) $slot->unit_minor, 'supplement_minor' => (int) ( $slot->supplement_minor ?? 0 ) );
	}

	/* -------- Buyer via booking manage token */

	public static function attach_order( \WP_REST_Request $request ) {
		$booking = ServiceBookings::with_token( absint( $request['booking_id'] ), $request->get_param( 'token' ) );
		$order = ServiceBookings::for_order_access( (string) $request->get_param( 'order_public_id' ), $request->get_param( 'access_token' ) );
		if ( ! $booking || ! $order ) { return self::denied(); }
		return self::respond( ServiceBookings::attach_order( (int) $booking->booking_id, (int) $order->order_id ) );
	}

	public static function confirm_free( \WP_REST_Request $request ) {
		return self::respond( ServiceBookings::confirm_free( absint( $request['booking_id'] ), $request->get_param( 'token' ) ) );
	}

	public static function token_cancel( \WP_REST_Request $request ) {
		$booking = ServiceBookings::with_token( absint( $request['booking_id'] ), $request->get_param( 'token' ) );
		if ( ! $booking || (int) $booking->order_id ) { return self::denied(); }
		return self::respond( ServiceBookings::cancel( (int) $booking->booking_id, array( 'actor' => 'customer' ) ) );
	}

	public static function token_rebook( \WP_REST_Request $request ) {
		$booking = ServiceBookings::with_token( absint( $request['booking_id'] ), $request->get_param( 'token' ) );
		if ( ! $booking || (int) $booking->order_id ) { return self::denied(); }
		return self::respond( ServiceBookings::rebook( (int) $booking->booking_id, absint( $request->get_param( 'slot_id' ) ), array( 'actor' => 'customer', 'idempotency_key' => (string) $request->get_param( 'idempotency_key' ) ) ) );
	}

	/* -------- Staff */

	public static function decide( \WP_REST_Request $request ) {
		return self::respond( ServiceBookings::decide( absint( $request['booking_id'] ), sanitize_key( (string) $request->get_param( 'decision' ) ), sanitize_text_field( (string) $request->get_param( 'reason' ) ) ) );
	}

	public static function check_in( \WP_REST_Request $request ) {
		return self::respond( ServiceBookings::check_in( absint( $request['booking_id'] ), 'rest' ) );
	}

	public static function no_show( \WP_REST_Request $request ) {
		return self::respond( ServiceBookings::mark_no_show( absint( $request['booking_id'] ), 'rest' ) );
	}

	/* -------- Waitlists */

	public static function join_slot_waitlist( \WP_REST_Request $request ) {
		return self::respond( ServiceWaitlist::join( absint( $request['slot_id'] ), (string) $request->get_param( 'email' ), absint( $request->get_param( 'quantity' ) ?: 1 ) ) );
	}

	public static function waitlist_position( \WP_REST_Request $request ) {
		return self::respond( ServiceWaitlist::position( absint( $request['entry_id'] ), $request->get_param( 'token' ) ) );
	}

	public static function waitlist_accept( \WP_REST_Request $request ) {
		return self::respond( ServiceWaitlist::accept( absint( $request['entry_id'] ), $request->get_param( 'token' ) ) );
	}

	public static function waitlist_decline( \WP_REST_Request $request ) {
		return self::respond( ServiceWaitlist::decline( absint( $request['entry_id'] ), $request->get_param( 'token' ) ) );
	}

	public static function waitlist_leave( \WP_REST_Request $request ) {
		return self::respond( ServiceWaitlist::leave( absint( $request['entry_id'] ), $request->get_param( 'token' ) ) );
	}

	public static function event_waitlist_decline( \WP_REST_Request $request ) {
		return self::respond( Waitlists::decline_offer( absint( $request['waitlist_id'] ), $request->get_param( 'token' ) ) );
	}

	public static function event_waitlist_unsubscribe( \WP_REST_Request $request ) {
		return self::respond( Waitlists::unsubscribe( absint( $request['waitlist_id'] ), $request->get_param( 'token' ) ) );
	}

	/** Pass holder uses a service pass (the 64-hex pass code is the credential). */
	public static function redeem_service_pass( \WP_REST_Request $request ) {
		$ip_key = 'wpcap_svcpass_ip_' . hash( 'sha256', \PassCaptain\RateLimit::client_ip() );
		$attempts = (int) get_transient( $ip_key );
		if ( $attempts >= 60 ) { return new \WP_Error( 'wpcap_pass_rate', __( 'Zu viele Versuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $ip_key, $attempts + 1, HOUR_IN_SECONDS );
		return self::respond( Memberships::redeem_service( (string) $request->get_param( 'token' ), absint( $request->get_param( 'resource_id' ) ), (string) $request->get_param( 'idempotency_key' ), absint( $request->get_param( 'slot_id' ) ), absint( $request->get_param( 'quantity' ) ?: 1 ) ) );
	}
}
