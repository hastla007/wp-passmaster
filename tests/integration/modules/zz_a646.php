<?php
/**
 * A646: the old route POST /events/{id}/seat-holds no longer lets anyone block seats of the old seat
 * maps (410 Gone, nothing held); a new seat map version retires the older published ones.
 */
// phpcs:ignoreFile
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;
echo "A646: old seat holds and seat map versions\n";
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$x1_type = make_type( 10, 10, 1000 );
$x1_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $x1_type ) );
$x1_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $x1_occ ) );
$x1_map = static function ( $name ) use ( $x1_event ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/events/' . $x1_event . '/seat-maps' );
	$req['event_id'] = $x1_event;
	$req->set_body( json_encode( array( 'name' => $name, 'seats' => array( array( 'seat_key' => 'a1', 'seat_label' => 'A1' ), array( 'seat_key' => 'a2', 'seat_label' => 'A2' ) ) ) ) );
	$res = Controller::create_seat_map( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};
$x1_v1 = $x1_map( 'Saal v1' );
$x1_v2 = $x1_map( 'Saal v2' );
$x1_status = static function ( $map_id ) use ( $wpdb, $p ) { return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_seat_maps WHERE map_id=%d", $map_id ) ); };
check( 'A646: publishing version 2 retires version 1', is_array( $x1_v1 ) && is_array( $x1_v2 ) && 'retired' === $x1_status( $x1_v1['map_id'] ) && 'published' === $x1_status( $x1_v2['map_id'] ) && 1 === $x1_v2['retired_versions'] );

$x1_old_seat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT seat_id FROM {$p}wpcap_seats WHERE map_id=%d ORDER BY seat_id LIMIT 1", $x1_v1['map_id'] ) );
$x1_new_seat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT seat_id FROM {$p}wpcap_seats WHERE map_id=%d ORDER BY seat_id LIMIT 1", $x1_v2['map_id'] ) );
$x1_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_seat_holds" );
$GLOBALS['wpcap_test_user_id'] = 0;
$req = new WP_REST_Request( 'POST', '/pass-captain/v1/events/' . $x1_event . '/seat-holds' );
$req['event_id'] = $x1_event;
$req->set_param( 'occurrence_id', $x1_occ );
$req->set_param( 'seat_ids', array( $x1_new_seat ) );
$req->set_param( 'idempotency_key', 'x1-a646-' . uniqid() );
$x1_hold = Controller::hold_seats( $req );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A646: an anonymous hold of old seat-map seats is refused with 410 and nothing is held', is_wp_error( $x1_hold ) && 410 === $x1_hold->get_error_data()['status'] && $x1_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_seat_holds" ) );
$x1_old = PassCaptain\Seats::hold( $x1_occ, array( $x1_old_seat ), 0, 10, 'x1-a646-old-' . uniqid() );
check( 'A646: seats of a retired version can no longer be held at all', is_wp_error( $x1_old ) && 'wpcap_seat_unavailable' === $x1_old->get_error_code() );
$req = new WP_REST_Request( 'GET', '/pass-captain/v1/events/' . $x1_event . '/seats' );
$req['event_id'] = $x1_event;
$req->set_param( 'occurrence_id', $x1_occ );
$x1_seats = Controller::seats( $req );
$x1_seats = $x1_seats instanceof WP_REST_Response ? $x1_seats->get_data() : $x1_seats;
check( 'A646: the public seat list shows the current version', is_array( $x1_seats ) && 2 === (int) $x1_seats['map']['version'] );
