<?php
/**
 * A134: per-resource booking rules – booking cutoff (lead time), maximum advance booking, maximum
 * open bookings per customer, rebooking and cancellation deadlines – enforced server-side in
 * reserve, confirm, rebook and cancel. Included from run.php (helpers from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\ServiceBookings;
use PassCaptain\Admin\ServiceSlotsPage;

global $wpdb;
$p = $wpdb->prefix;
$a134_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a134_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.134';

/** Slot starting $minutes from now (60 min long). */
function a134_slot( $resource, $minutes, $capacity = 3 ) {
	global $wpdb;
	$start = time() + $minutes * 60;
	$wpdb->insert( "{$wpdb->prefix}wpcap_service_slots", array( 'resource_id' => $resource, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + 3600 ), 'capacity' => $capacity, 'status' => 'open' ) );
	return (int) $wpdb->insert_id;
}
function a134_book( $slot, $email, $qty = 1 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = ServiceBookings::reserve( $slot, $email, $qty, 0, 10, 'a134-' . uniqid( '', true ) );
	return is_array( $r ) ? (int) $r['booking_id'] : $r;
}
function a134_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }

echo "A134: schema and settings\n";
ServiceBookings::flush_schema_cache();
check( 'schema 3.10.3: rule columns on resources', version_compare( PassCaptain\Installer::DB_VERSION, '3.10.3', '>=' ) && ServiceBookings::rules_ready() );
$a134_event = ex_event( 'A134 Segelkurs' );
ex_occ( $a134_event );
$a134_res = ServiceSlotsPage::save_resource( array( 'event_id' => $a134_event, 'name' => 'Kursboot', 'capacity' => '3', 'lead_value' => '2', 'lead_unit' => 'hours', 'advance_days' => '14', 'max_per_customer' => '2', 'rebook_hours' => '24', 'cancel_hours' => '48' ) );
$a134_rules = ServiceBookings::rules( ServiceBookings::resource( $a134_res ) );
check( 'admin form stores cutoff (2 h = 120 min), advance days, limit and deadlines', is_int( $a134_res ) && array( 'lead_minutes' => 120, 'advance_days' => 14, 'max_per_customer' => 2, 'rebook_hours' => 24, 'cancel_hours' => 48 ) === $a134_rules );
ServiceSlotsPage::save_resource( array( 'resource_id' => $a134_res, 'event_id' => $a134_event, 'name' => 'Kursboot', 'capacity' => '3' ) );
check( 'saving without rule fields keeps the rules', $a134_rules === ServiceBookings::rules( ServiceBookings::resource( $a134_res ) ) );
check( 'out-of-range values are clamped', 730 === ServiceBookings::rules( (object) array( 'advance_days' => 730 ) )['advance_days'] && true === ServiceBookings::update_resource( $a134_res, array( 'advance_days' => 9999 ) ) && 730 === (int) ServiceBookings::resource( $a134_res )->advance_days && true === ServiceBookings::update_resource( $a134_res, array( 'advance_days' => 14 ) ) );

echo "A134: booking window\n";
$a134_soon = a134_slot( $a134_res, 60 );
$a134_far = a134_slot( $a134_res, 30 * 1440 );
$a134_ok = a134_slot( $a134_res, 5 * 1440 );
check( 'reserve within the cutoff refused (wpcap_booking_cutoff, message names 2 Stunden)', 'wpcap_booking_cutoff' === a134_code( $e = a134_book( $a134_soon, 'a134-x@example.com' ) ) && false !== strpos( $e->get_error_message(), '2 Stunden' ) );
check( 'reserve beyond the maximum advance booking refused', 'wpcap_booking_too_early' === a134_code( a134_book( $a134_far, 'a134-x@example.com' ) ) );
$a134_listed = array_map( static function ( $r ) { return (int) $r['slot_id']; }, ServiceBookings::public_slots( $a134_event ) );
check( 'public listing only shows slots inside the booking window', in_array( $a134_ok, $a134_listed, true ) && ! in_array( $a134_soon, $a134_listed, true ) && ! in_array( $a134_far, $a134_listed, true ) );
check( 'reserve inside the window works', is_int( a134_book( $a134_ok, 'a134-x@example.com' ) ) );

echo "A134: maximum open bookings per customer\n";
$a134_s2 = a134_slot( $a134_res, 6 * 1440 );
$a134_s3 = a134_slot( $a134_res, 7 * 1440 );
$a134_second = a134_book( $a134_s2, 'a134-x@example.com' );
check( 'second booking of the customer allowed, third refused (limit 2)', is_int( $a134_second ) && 'wpcap_booking_customer_limit' === a134_code( a134_book( $a134_s3, 'a134-x@example.com' ) ) );
check( 'the limit ignores the case of the e-mail address', 'wpcap_booking_customer_limit' === a134_code( a134_book( $a134_s3, 'A134-X@Example.com' ) ) );
check( 'other customers are not affected', is_int( a134_book( $a134_s3, 'a134-y@example.com' ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE booking_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $a134_second ) );
$a134_third = a134_book( $a134_s3, 'a134-x@example.com' );
check( 'an expired hold no longer counts', is_int( $a134_third ) );

echo "A134: confirm re-checks the rules\n";
check( 'confirm of a valid hold works (customer counts itself only once)', 'confirmed' === ( ServiceBookings::confirm( $a134_third )['status'] ?? '' ) && 'confirmed' === ServiceBookings::booking( $a134_third )->status );
check( 'confirm of an expired hold refused', 'wpcap_booking_state' === a134_code( ServiceBookings::confirm( $a134_second ) ) );
$a134_late = a134_slot( $a134_res, 3 * 60 );
$a134_late_b = a134_book( $a134_late, 'a134-z@example.com' );
ServiceBookings::update_resource( $a134_res, array( 'lead_minutes' => 240 ) );
check( 'cutoff passed while the hold was open: confirm refused', is_int( $a134_late_b ) && 'wpcap_booking_cutoff' === a134_code( ServiceBookings::confirm( $a134_late_b ) ) && 'held' === ServiceBookings::booking( $a134_late_b )->status );
ServiceBookings::update_resource( $a134_res, array( 'lead_minutes' => 120 ) );

echo "A134: rebooking deadline and target rules\n";
// Booking 10 hours ahead (lead time 2 h ok), rebooking deadline 24 h.
$a134_r_slot = a134_slot( $a134_res, 10 * 60 );
$a134_rb = a134_book( $a134_r_slot, 'a134-r@example.com' );
ServiceBookings::confirm( $a134_rb );
$a134_target = a134_slot( $a134_res, 8 * 1440 );
check( 'customer rebooking after the deadline refused', 'wpcap_booking_rebook_deadline' === a134_code( $e = ServiceBookings::rebook( $a134_rb, $a134_target ) ) && false !== strpos( $e->get_error_message(), '1 Tag' ) && $a134_r_slot === (int) ServiceBookings::booking( $a134_rb )->slot_id );
check( 'staff may rebook after the deadline', $a134_target === ( ServiceBookings::rebook( $a134_rb, $a134_target, array( 'actor' => 'admin' ) )['slot_id'] ?? 0 ) );
$a134_early = a134_slot( $a134_res, 20 * 1440 );
check( 'rebooking to a slot outside the booking window refused (customer and staff)', 'wpcap_booking_too_early' === a134_code( ServiceBookings::rebook( $a134_rb, $a134_early ) ) && 'wpcap_booking_too_early' === a134_code( ServiceBookings::rebook( $a134_rb, $a134_early, array( 'actor' => 'admin' ) ) ) );
$a134_t2 = a134_slot( $a134_res, 9 * 1440, 1 );
a134_book( $a134_t2, 'a134-full@example.com' );
check( 'rebooking to a full slot refused', 'wpcap_slot_full' === a134_code( ServiceBookings::rebook( $a134_rb, $a134_t2 ) ) );
$a134_t3 = a134_slot( $a134_res, 10 * 1440 );
$a134_moved = ServiceBookings::rebook( $a134_rb, $a134_t3 );
check( 'customer rebooking before the deadline works, limit counts the booking only once', $a134_t3 === ( $a134_moved['slot_id'] ?? 0 ) && 'confirmed' === ServiceBookings::booking( $a134_rb )->status );
$a134_other_res = ServiceBookings::create_resource( ex_event( 'A134 Fremd' ), array( 'name' => 'Fremdboot' ) );
$a134_other_slot = a134_slot( $a134_other_res, 8 * 1440 );
check( 'rebooking to another event refused', 'wpcap_slot_unavailable' === a134_code( ServiceBookings::rebook( $a134_rb, $a134_other_slot, array( 'actor' => 'admin' ) ) ) );

echo "A134: cancellation deadline\n";
$a134_c_slot = a134_slot( $a134_res, 30 * 60 );
$a134_cb = a134_book( $a134_c_slot, 'a134-c@example.com' );
ServiceBookings::confirm( $a134_cb );
check( 'customer cancellation after the deadline (48 h) refused', 'wpcap_booking_cancel_deadline' === a134_code( $e = ServiceBookings::cancel( $a134_cb ) ) && false !== strpos( $e->get_error_message(), '2 Tage' ) && 'confirmed' === ServiceBookings::booking( $a134_cb )->status );
check( 'staff cancellation after the deadline works, places free', 'cancelled' === ( ServiceBookings::cancel( $a134_cb, array( 'actor' => 'admin', 'reason' => 'Wetter' ) )['status'] ?? '' ) && 0 === ServiceBookings::booked( $a134_c_slot ) );
check( 'cancel is idempotent', true === ( ServiceBookings::cancel( $a134_cb )['already'] ?? false ) );
check( 'customer cancellation before the deadline works', 'cancelled' === ( ServiceBookings::cancel( $a134_rb )['status'] ?? '' ) );
$a134_h = a134_book( a134_slot( $a134_res, 30 * 60 + 5 ), 'a134-h@example.com' );
check( 'an unconfirmed hold can always be released by the customer', 'cancelled' === ( ServiceBookings::cancel( $a134_h )['status'] ?? '' ) );

echo "A134: staff actions need event rights\n";
$GLOBALS['wpcap_test_user_id'] = 4134;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=4134" );
check( 'actor admin without event rights refused', 'wpcap_resource_forbidden' === a134_code( ServiceBookings::cancel( $a134_third, array( 'actor' => 'admin' ) ) ) && 'confirmed' === ServiceBookings::booking( $a134_third )->status );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'documented in OPERATIONS.md', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/OPERATIONS.md' ), 'Buchungsschluss' ) );

$GLOBALS['wpcap_test_user_id'] = $a134_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $a134_saved_denied;
$GLOBALS['wpcap_test_transients'] = array();
