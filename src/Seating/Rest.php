<?php
/** Public, read-only seat availability endpoint (no personal data). */
namespace PassCaptain\Seating;

use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest {
	const NS = 'pass-captain/v1';

	public static function register_routes() {
		register_rest_route( self::NS, '/seating/occurrences/(?P<occurrence_id>\d+)', array(
			'methods' => 'GET',
			'callback' => array( __CLASS__, 'availability' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function availability( $request ) {
		if ( ! RateLimit::allow( 'seating_availability|' . RateLimit::client(), (int) apply_filters( 'wpcap_seating_availability_limit', 120 ), 60 ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$occurrence_id = absint( $request['occurrence_id'] ?? $request->get_param( 'occurrence_id' ) );
		// A610: a cancelled date or one whose online sale is over answers with its state (200, no map).
		$data = Reservations::closed_state( $occurrence_id );
		if ( null === $data ) { $data = Reservations::availability( $occurrence_id ); }
		if ( null === $data ) {
			return new \WP_Error( 'wpcap_seat_map_not_found', __( 'Für diesen Termin gibt es keinen Saalplan.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$response = rest_ensure_response( $data );
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'no-store' ); }
		return $response;
	}
}
