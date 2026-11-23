<?php
/**
 * Concurrency, part E (real parallel processes, tests/integration/workers/service_worker.php):
 * slot capacity, collisions of overlapping slots, parallel rebooking into the last place, parallel
 * acceptance of event waitlist offers next to public checkouts (never oversold) and parallel
 * acceptance of the same slot waitlist offer (one booking).
 *
 * Included from run.php after zzzz_services_a_rules.php (svc_* helpers).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;
use PassCaptain\ServiceWaitlist;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
// Queue mechanics without the double opt-in step (covered by zzzzzz_z1_security.php).
$GLOBALS['wpcap_test_options'][ PassCaptain\Settings::OPTION ]['waitlist_double_opt_in'] = 0;

/** Runs worker processes that start at the same moment; returns their outputs in order. */
function svc_parallel( array $jobs ) {
	$start = sprintf( '%.3f', microtime( true ) + 1.2 );
	$procs = array();
	foreach ( $jobs as $args ) {
		$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/service_worker.php' ) . ' ' . $start . ' ' . implode( ' ', array_map( 'escapeshellarg', array_map( 'strval', $args ) ) ) . ' 2>&1', 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	if ( getenv( 'WPCAP_SVC_DEBUG' ) ) { fwrite( STDERR, 'PAR ' . json_encode( $out ) . "\n" ); }
	return $out;
}
function svc_ok( array $out ) { return count( array_filter( $out, static fn( $o ) => str_starts_with( $o, 'OK:' ) ) ); }

echo "Concurrency: slot capacity and collisions\n";
list( $cc_event ) = svc_event();
$cc_res = svc_resource( $cc_event, array( 'capacity' => 3, 'rules' => array( 'cutoff_minutes' => 0 ) ) );
$cc_slot = svc_slot( $cc_res, 30, 60, 3 );
$out = svc_parallel( array_map( static fn( $i ) => array( 'book', $cc_slot, "par$i@svc.test" ), range( 1, 8 ) ) );
check( '8 parallel bookings for 3 places: exactly 3 succeed, the rest get "full"', 3 === svc_ok( $out ) && 5 === count( array_filter( $out, static fn( $o ) => 'ERR:wpcap_slot_full' === $o ) ) && 3 === ServiceResources::booked_quantity( $cc_slot ) );
// Two overlapping legacy slots of one resource: bookings may only land in one of them.
$ov_res = svc_resource( $cc_event, array( 'capacity' => 5, 'buffer_after' => 15 ) );
$ov_a = svc_slot( $ov_res, 40, 60, 5 );
$ov_a_row = ServiceResources::slot( $ov_a );
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $ov_res, 'starts_at' => gmdate( 'Y-m-d H:i:s', strtotime( $ov_a_row->ends_at . ' UTC' ) + 5 * 60 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', strtotime( $ov_a_row->ends_at . ' UTC' ) + 65 * 60 ), 'capacity' => 5, 'status' => 'open' ) );
$ov_b = (int) $wpdb->insert_id; // Starts 5 minutes after A ends – inside A's 15-minute buffer.
$jobs = array();
for ( $i = 1; $i <= 6; $i++ ) { $jobs[] = array( 'book', 0 === $i % 2 ? $ov_a : $ov_b, "ov$i@svc.test" ); }
$out = svc_parallel( $jobs );
$in_a = ServiceResources::booked_quantity( $ov_a );
$in_b = ServiceResources::booked_quantity( $ov_b );
check( 'parallel bookings of two slots colliding within the buffer never land in both', ( 0 === $in_a ) !== ( 0 === $in_b ) && svc_ok( $out ) === $in_a + $in_b && 3 === $in_a + $in_b && 3 === count( array_filter( $out, static fn( $o ) => 'ERR:wpcap_slot_resource_busy' === $o ) ) );
// Parallel rebooking into the last free place.
$rb_target = svc_slot( $cc_res, 50, 60, 1 );
$rb_src = svc_slot( $cc_res, 55, 60, 3 );
$r1 = svc_book( $rb_src, 'rb-par1@svc.test' );
$r2 = svc_book( $rb_src, 'rb-par2@svc.test' );
ServiceBookings::confirm_free( $r1['booking_id'], $r1['manage_token'] );
ServiceBookings::confirm_free( $r2['booking_id'], $r2['manage_token'] );
$out = svc_parallel( array( array( 'rebook', $r1['booking_id'], $rb_target ), array( 'rebook', $r2['booking_id'], $rb_target ) ) );
check( 'two parallel rebookings into the last place: exactly one moves, the other stays put', 1 === svc_ok( $out ) && 1 === ServiceResources::booked_quantity( $rb_target ) && 1 === ServiceResources::booked_quantity( $rb_src ) );

echo "Concurrency: waitlist acceptances never oversell\n";
$pw_type = make_type( 2, 10, 1200 );
$pw_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pw_type" );
$pw_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$pw_occ" );
svc_reset_limits();
$pw_sold = Orders::create( array( 'email' => 'pw-sold@svc.test', 'items' => array( array( 'ticket_type_id' => $pw_type, 'quantity' => 2 ) ), 'idempotency_key' => 'pw-sold-' . uniqid() ) );
foreach ( array( 'pw-a@svc.test', 'pw-b@svc.test', 'pw-c@svc.test' ) as $mail ) { svc_reset_limits(); Waitlists::join( $pw_event, $pw_occ, $pw_type, $mail ); }
Orders::cancel_order( $pw_sold['order_id'], 'test' ); // 2 tickets free → 2 offers with hold.
// Merged design: the event waitlist queue (Waitlists, A-line) offers freed units on its run (cron / job worker).
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
Waitlists::run_due( true );
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] );
list( $pa_id, $pa_token ) = svc_token_from_mail( 'pw-a@svc.test', 'wpcap_waitlist_accept' );
list( $pb_id, $pb_token ) = svc_token_from_mail( 'pw-b@svc.test', 'wpcap_waitlist_accept' );
check( 'cancellation of 2 tickets: the first 2 entries get offers with holds, the third waits', $pa_id && $pb_id && 2 === Waitlists::held_quantity( (object) array( 'ticket_type_id' => $pw_type ) ) && 'waiting' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_waitlist WHERE email='pw-c@svc.test'" ) );
$out = svc_parallel( array(
	array( 'accept_event', $pa_id, $pa_token ),
	array( 'accept_event', $pb_id, $pb_token ),
	array( 'accept_event', $pa_id, $pa_token ),
	array( 'buy', $pw_type, 'pw-public1@svc.test' ),
	array( 'buy', $pw_type, 'pw-public2@svc.test' ),
	array( 'buy', $pw_type, 'pw-public3@svc.test' ),
) );
$pw_orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT r.order_id) FROM {$p}wpcap_reservations r WHERE r.ticket_type_id=$pw_type AND r.status='active' AND r.expires_at>'" . gmdate( 'Y-m-d H:i:s' ) . "'" );
$accept_a_orders = array_unique( array_filter( array( $out[0], $out[2] ), static fn( $o ) => str_starts_with( $o, 'OK:' ) ) );
$accept_a_errors = array_values( array_filter( array( $out[0], $out[2] ), static fn( $o ) => ! str_starts_with( $o, 'OK:' ) ) );
check( 'parallel: both offers accepted once each (no false "sold out" from the other hold), the duplicate acceptance of A yields no second order', str_starts_with( $out[1], 'OK:' ) && 1 === count( $accept_a_orders ) && array( 'ERR:wpcap_waitlist_offer_invalid' ) === $accept_a_errors && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email='pw-a@svc.test'" ) );
check( 'public checkouts racing the acceptances all fail: never oversold (2 holds for 2 tickets)', 0 === svc_ok( array_slice( $out, 3 ) ) && 2 === $pw_orders && 0 === Orders::availability( $pw_type ) );
// Parallel acceptance of the same slot waitlist offer → one booking.
$sw_res = svc_resource( $cc_event, array( 'capacity' => 1 ) );
$sw_slot = svc_slot( $sw_res, 70, 60, 1 );
$sw_hold = svc_book( $sw_slot, 'sw-holder@svc.test' );
ServiceBookings::confirm_free( $sw_hold['booking_id'], $sw_hold['manage_token'] );
svc_reset_limits();
ServiceWaitlist::join( $sw_slot, 'sw-wait@svc.test' );
ServiceBookings::cancel( $sw_hold['booking_id'], array( 'actor' => 'customer' ) );
svc_run_jobs();
list( $sw_id, $sw_token ) = svc_token_from_mail( 'sw-wait@svc.test', 'wpcap_service_waitlist' );
$out = svc_parallel( array( array( 'accept_slot', $sw_id, $sw_token ), array( 'accept_slot', $sw_id, $sw_token ), array( 'book', $sw_slot, 'sw-public@svc.test' ) ) );
$sw_bookings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_service_bookings WHERE slot_id=$sw_slot AND customer_email='sw-wait@svc.test'" );
check( 'parallel acceptance of one slot offer creates one booking; a public booking racing it fails', 1 === $sw_bookings && 'ERR:wpcap_slot_full' === $out[2] && 1 === ServiceResources::booked_quantity( $sw_slot ) && 'confirmed' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_service_bookings WHERE slot_id=$sw_slot AND customer_email='sw-wait@svc.test'" ) );

unset( $GLOBALS['wpcap_test_options'][ PassCaptain\Settings::OPTION ]['waitlist_double_opt_in'] );
