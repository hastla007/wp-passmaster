<?php
/**
 * Guest list lookup for check-in staff (cookie authentication + REST nonce, capability
 * wpcap_checkin; event scope is checked per occurrence/entry in Service).
 *
 *   GET  /pass-captain/v1/guestlists/search?occurrence_id=…&q=…   name/company/short-code lookup
 *   POST /pass-captain/v1/guestlists/entries/{id}/arrive {count?} check in the entry's tickets
 *   GET  /pass-captain/v1/guestlists/will-call?occurrence_id=…     alphabetical will-call list
 */
namespace PassCaptain\GuestLists;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest {
	const NS = 'pass-captain/v1';

	public static function routes() {
		$perm = array( __CLASS__, 'permission' );
		register_rest_route( self::NS, '/guestlists/search', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'search' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/guestlists/entries/(?P<entry_id>\d+)/arrive', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'arrive' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/guestlists/will-call', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'will_call' ), 'permission_callback' => $perm ) );
	}

	public static function permission() {
		return is_user_logged_in() && ( current_user_can( Service::CHECKIN_CAP ) || current_user_can( Service::MANAGE_CAP ) );
	}

	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => (int) ( $data['status'] ?? 400 ) ) );
		}
		return rest_ensure_response( $result );
	}

	public static function search( \WP_REST_Request $request ) {
		return self::respond( Service::search( absint( $request->get_param( 'occurrence_id' ) ), (string) $request->get_param( 'q' ) ) );
	}

	public static function arrive( \WP_REST_Request $request ) {
		return self::respond( Service::arrive( absint( $request->get_param( 'entry_id' ) ), absint( $request->get_param( 'count' ) ) ) );
	}

	public static function will_call( \WP_REST_Request $request ) {
		$rows = Service::will_call( absint( $request->get_param( 'occurrence_id' ) ) );
		if ( is_wp_error( $rows ) ) { return self::respond( $rows ); }
		return rest_ensure_response( array_map( static function ( $e ) {
			return array( 'entry_id' => (int) $e->entry_id, 'name' => (string) $e->name, 'company' => (string) $e->company, 'list' => (string) $e->list_name, 'ticket' => (string) $e->ticket_name, 'quantity' => (int) $e->quantity, 'plus_ones' => (int) $e->plus_ones, 'status' => (string) $e->status, 'codes' => $e->codes, 'tickets_checked_in' => (int) $e->tickets_checked_in );
		}, $rows ) );
	}
}
