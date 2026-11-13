<?php
/**
 * REST endpoints of the offline scanner for paired devices (device bearer token of the mobile API):
 *
 *   GET /app/offline/keys                            – trusted Ed25519 public keys
 *   GET /app/offline/occurrences                     – occurrences this device may hold packages for
 *   GET /app/offline/package?occurrence_id=&since=   – signed full/delta package
 *
 * Also extends existing app responses (rest_request_after_callbacks): /app/pair returns the public
 * keys ("offline"), /app/me announces the feature, /app/scan/sync applies the event's offline rules.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Access;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest {
	public static function routes() {
		$auth = array( Api::class, 'authenticate' );
		register_rest_route( Api::NS, '/app/offline/keys', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'keys' ), 'permission_callback' => $auth ) );
		register_rest_route( Api::NS, '/app/offline/occurrences', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'occurrences' ), 'permission_callback' => $auth ) );
		register_rest_route( Api::NS, '/app/offline/package', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'package' ), 'permission_callback' => $auth ) );
	}

	public static function keys( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'keys' => Keys::public_keys(), 'server_time' => gmdate( 'c' ) ) );
	}

	public static function occurrences( \WP_REST_Request $request ) {
		$device = Devices::current();
		$out = array();
		foreach ( Assignments::occurrences_for_device( $device ) as $row ) {
			if ( ! Access::can_access_occurrence( (int) $row->occurrence_id, 'wpcap_checkin' ) ) { continue; }
			$rules = Rules::get( (int) $row->event_id );
			$out[] = array(
				'occurrence_id' => (int) $row->occurrence_id,
				'event_id' => (int) $row->event_id,
				'event' => (string) $row->event_name,
				'starts_at' => gmdate( 'c', strtotime( $row->starts_at . ' UTC' ) ),
				'ends_at' => $row->ends_at ? gmdate( 'c', strtotime( $row->ends_at . ' UTC' ) ) : null,
				'timezone' => (string) $row->timezone,
				'status' => (string) $row->status,
				'rules' => array( 'max_offline_hours' => $rules['max_offline_hours'], 'duplicate_policy' => $rules['duplicate_policy'], 'attendee_names' => Rules::attendee_names( $rules ) ),
			);
		}
		return rest_ensure_response( array( 'occurrences' => $out, 'device_id' => (string) $device->public_id, 'server_time' => gmdate( 'c' ) ) );
	}

	/**
	 * Signed package. Errors: 404 unknown occurrence, 403 wpcap_offline_disabled (event not opted in),
	 * 403 wpcap_offline_not_assigned (device not assigned / not paired for the event / no check-in access).
	 */
	public static function package( \WP_REST_Request $request ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$device = Devices::current();
		$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $request->get_param( 'occurrence_id' ) ) ) );
		if ( ! $occurrence ) {
			return new \WP_Error( 'wpcap_offline_occurrence', __( 'Termin nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$event_id = (int) $occurrence->event_id;
		if ( ! Rules::enabled( $event_id ) ) {
			return new \WP_Error( 'wpcap_offline_disabled', __( 'Offline-Scannen ist für dieses Event nicht freigegeben.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$assignment = Assignments::find( (int) $device->device_id, $event_id, (int) $occurrence->occurrence_id );
		if ( ! $assignment || ! in_array( $event_id, Devices::event_ids( $device ), true ) || ! Access::can_access_occurrence( (int) $occurrence->occurrence_id, 'wpcap_checkin' ) ) {
			return new \WP_Error( 'wpcap_offline_not_assigned', __( 'Dieses Gerät ist für diesen Termin nicht zum Offline-Scannen zugewiesen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$since = $request->get_param( 'since' );
		$body = Package::build( $device, $occurrence, is_string( $since ) ? $since : null );
		Assignments::touch( $assignment );
		$response = rest_ensure_response( $body );
		if ( method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'no-store' ); }
		return $response;
	}

	private static function route( $request ) {
		if ( method_exists( $request, 'get_route' ) ) { return (string) $request->get_route(); }
		return (string) ( $request->route ?? '' );
	}

	/** Extends app responses; see class doc. */
	public static function after_callbacks( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! is_object( $request ) ) { return $response; }
		$route = self::route( $request );
		$base = '/' . Api::NS . '/app/';
		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $response; }
		if ( $base . 'pair' === $route && ! empty( $data['token'] ) ) {
			$data['offline'] = array( 'keys' => Keys::public_keys(), 'package_format' => Package::VERSION );
		} elseif ( $base . 'me' === $route && isset( $data['features'] ) ) {
			$data['features']['offline_packages'] = true;
		} elseif ( $base . 'scan/sync' === $route && isset( $data['results'] ) && Devices::current() ) {
			$scans = $request->get_param( 'scans' );
			$data['results'] = Sync::apply( $data['results'], is_array( $scans ) ? $scans : array(), Devices::current() );
		} else {
			return $response;
		}
		if ( method_exists( $response, 'set_data' ) ) { $response->set_data( $data ); } else { $response->data = $data; }
		return $response;
	}
}
