<?php
/** Public checkout and authenticated scanner REST routes. */
namespace PassCaptain\Rest;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Orders;

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
	}

	public static function events() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.slug,e.description,o.occurrence_id,o.starts_at,o.ends_at,o.timezone,o.location,o.capacity FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 100",
			current_time( 'mysql', true )
		) );
		$output = array();
		foreach ( $rows as $row ) {
			$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name,price_minor,currency,capacity,max_per_order,sales_start,sales_end FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' ORDER BY ticket_type_id ASC", $row->occurrence_id ), ARRAY_A );
			$output[] = array(
				'event_id' => (int) $row->event_id,
				'name' => $row->name,
				'slug' => $row->slug,
				'description' => wp_strip_all_tags( $row->description ),
				'occurrence_id' => (int) $row->occurrence_id,
				'starts_at' => $row->starts_at,
				'ends_at' => $row->ends_at,
				'timezone' => $row->timezone,
				'location' => $row->location,
				'tickets' => array_map( static function ( $type ) { return array(
					'ticket_type_id' => (int) $type['ticket_type_id'],
					'name' => $type['name'],
					'price_minor' => (int) $type['price_minor'],
					'currency' => $type['currency'],
					'max_per_order' => (int) $type['max_per_order'],
				); }, $types ),
			);
		}
		return rest_ensure_response( $output );
	}

	public static function checkout( \WP_REST_Request $request ) {
		$result = Orders::create( $request->get_json_params() ?: $request->get_params() );
		if ( is_wp_error( $result ) ) { return $result; }
		return new \WP_REST_Response( $result, 201 );
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

	public static function scanner_permission() {
		return is_user_logged_in() && current_user_can( 'wpcap_checkin' );
	}

	public static function validate_ticket( \WP_REST_Request $request ) {
		$result = self::ticket_for_token( $request->get_param( 'token' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( ! Access::can_access_event( $result['event_id'], 'wpcap_checkin' ) ) { return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		return rest_ensure_response( $result );
	}

	public static function check_in( \WP_REST_Request $request ) {
		global $wpdb;
		$found = self::ticket_for_token( $request->get_param( 'token' ) );
		if ( is_wp_error( $found ) ) { return $found; }
		if ( ! Access::can_access_event( $found['event_id'], 'wpcap_checkin' ) ) { return new \WP_Error( 'wpcap_forbidden_event', __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$tickets = $wpdb->prefix . 'wpcap_tickets';
		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM $tickets WHERE ticket_id=%d FOR UPDATE", $found['ticket_id'] ) );
		if ( ! $ticket || 'valid' !== $ticket->status ) {
			$wpdb->insert( $wpdb->prefix . 'wpcap_checkins', array( 'ticket_id' => $found['ticket_id'], 'event_id' => $found['event_id'], 'user_id' => get_current_user_id(), 'result' => $ticket ? $ticket->status : 'unknown', 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s', '%s' ) );
			$wpdb->query( 'COMMIT' );
			return new \WP_Error( 'wpcap_ticket_not_valid', __( 'Ticket wurde bereits verwendet oder ist widerrufen.', 'wp-pass-captain' ), array( 'status' => 409, 'ticket_status' => $ticket ? $ticket->status : 'unknown' ) );
		}
		$wpdb->update( $tickets, array( 'status' => 'checked_in' ), array( 'ticket_id' => $ticket->ticket_id, 'status' => 'valid' ), array( '%s' ), array( '%d', '%s' ) );
		$wpdb->insert( $wpdb->prefix . 'wpcap_checkins', array( 'ticket_id' => $ticket->ticket_id, 'event_id' => $found['event_id'], 'user_id' => get_current_user_id(), 'result' => 'checked_in', 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s', '%s' ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'ticket_checked_in', 'ticket', $ticket->ticket_id, array( 'event_id' => $found['event_id'] ) );
		return rest_ensure_response( array( 'status' => 'checked_in', 'event' => $found['event_name'], 'ticket' => $found['ticket_name'] ) );
	}

	private static function ticket_for_token( $token ) {
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.status,tt.name AS ticket_name,o.event_id,o.starts_at,o.timezone,e.name AS event_name FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.token_hash=%s LIMIT 1",
			hash( 'sha256', $token )
		) );
		if ( ! $row ) { return new \WP_Error( 'wpcap_ticket_not_found', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( strtotime( $row->starts_at . ' UTC' ) < time() - DAY_IN_SECONDS || strtotime( $row->starts_at . ' UTC' ) > time() + 365 * DAY_IN_SECONDS ) { return new \WP_Error( 'wpcap_ticket_out_of_window', __( 'Das Ticket liegt außerhalb des Einlasszeitraums.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		return array( 'ticket_id' => (int) $row->ticket_id, 'event_id' => (int) $row->event_id, 'status' => $row->status, 'event_name' => $row->event_name, 'ticket_name' => $row->ticket_name, 'starts_at' => $row->starts_at, 'timezone' => $row->timezone );
	}
}
