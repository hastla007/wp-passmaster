<?php
// phpcs:ignoreFile
/**
 * A529: the old, half-finished seat API without purchase path is gone: no public seat holds, no
 * UPDATE on every GET, no second way to create seat maps. Seat selection runs through the Seating
 * module.
 */
require_once dirname( __DIR__ ) . '/channels.php';
echo "A529: old seat API removed\n";
$a529_routes = wpcap_test_rest_endpoints();
check( 'A529: GET /events/{id}/seats is no longer registered', ! isset( $a529_routes['GET pass-captain/v1/events/(?P<event_id>\d+)/seats'] ) );
check( 'A529: POST /events/{id}/seat-holds (public holds) is no longer registered', ! isset( $a529_routes['POST pass-captain/v1/events/(?P<event_id>\d+)/seat-holds'] ) );
check( 'A529: POST /events/{id}/seat-maps is no longer registered', ! isset( $a529_routes['POST pass-captain/v1/events/(?P<event_id>\d+)/seat-maps'] ) );
check( 'A529: the Seating module route stays', isset( $a529_routes['GET pass-captain/v1/seating/occurrences/(?P<occurrence_id>\d+)'] ) );
check( 'A529: the legacy hold class and callbacks are gone', ! class_exists( 'PassCaptain\\Seats' ) && ! method_exists( PassCaptain\Rest\Controller::class, 'hold_seats' ) && ! method_exists( PassCaptain\Rest\Controller::class, 'seats' ) && ! method_exists( PassCaptain\Rest\Controller::class, 'create_seat_map' ) );
