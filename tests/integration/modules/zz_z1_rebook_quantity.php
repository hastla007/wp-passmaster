<?php
/**
 * Z1 (merge follow-up): ServiceBookingFlow::rebook_options() checks the booking rules with the
 * booking's quantity. Before, it checked one place, so the manage view offered slots that
 * ServiceBookings::rebook() then refused with the per-customer limit.
 * Self-contained (WPCAP_TEST_ONLY=z1_rebook).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\ServiceBookingFlow;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceResources;

echo "Z1: rebook options respect the booking quantity\n";
global $wpdb;
ServiceBookings::flush_schema_cache();
$z1r_type = make_type( 100, 20, 0 );
$z1r_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $z1r_type );
$z1r_res = (int) ServiceResources::save_resource( array( 'event_id' => $z1r_event, 'name' => 'Z1 Boot ' . uniqid(), 'capacity' => 10, 'timezone' => 'Europe/Vienna', 'rules' => array( 'cutoff_minutes' => 0, 'max_per_customer' => 3, 'max_period' => 'day' ) ) );
$z1r_tz = new DateTimeZone( 'Europe/Vienna' );
$z1r_slot = static function ( $days, $time ) use ( $z1r_res, $z1r_tz ) {
	$start = ( new DateTimeImmutable( 'today ' . $time, $z1r_tz ) )->modify( '+' . $days . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) );
	return (int) ServiceResources::create_slot( $z1r_res, $start->format( 'Y-m-d H:i:s' ), $start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' ), 10 );
};
$z1r_a = $z1r_slot( 5, '10:00' ); // day 5: the booking to move (2 places)
$z1r_b = $z1r_slot( 6, '10:00' ); // day 6: candidate; the customer already holds 2 places that day
$z1r_c = $z1r_slot( 6, '14:00' );
$z1r_d = $z1r_slot( 7, '10:00' ); // day 7: free for the customer
$z1r_mail = 'z1-rebook-' . uniqid() . '@example.com';
wpcap_test_reset_transients();
$z1r_book = ServiceBookings::reserve( $z1r_a, $z1r_mail, 2, 0, 10, 'z1r-a-' . uniqid() );
wpcap_test_reset_transients();
$z1r_other = ServiceBookings::reserve( $z1r_c, $z1r_mail, 2, 0, 10, 'z1r-c-' . uniqid() );
$z1r_bid = (int) ( $z1r_book['booking_id'] ?? 0 );
$z1r_confirmed = ! is_wp_error( ServiceBookings::confirm( $z1r_bid ) ) && ! is_wp_error( ServiceBookings::confirm( (int) ( $z1r_other['booking_id'] ?? 0 ) ) );
check( 'z1: two confirmed bookings of 2 places on different days', $z1r_bid && $z1r_confirmed && 'confirmed' === ServiceBookings::booking( $z1r_bid )->status );
$z1r_options = array_column( ServiceBookingFlow::rebook_options( ServiceBookings::booking( $z1r_bid ) ), 'slot_id' );
check( 'z1: slot on a day where 2 more places exceed the limit of 3 is not offered', ! in_array( $z1r_b, $z1r_options, true ) );
check( 'z1: slot on a free day is offered', in_array( $z1r_d, $z1r_options, true ) );
wpcap_test_reset_transients();
$z1r_refused = ServiceBookings::rebook( $z1r_bid, $z1r_b, array( 'actor' => 'customer' ) );
check( 'z1: rebook() refuses the same slot with the per-customer limit (options and action agree)', is_wp_error( $z1r_refused ) && 'wpcap_booking_customer_limit' === $z1r_refused->get_error_code() );
