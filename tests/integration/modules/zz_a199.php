<?php
/**
 * A199: deterministic time-zone and DST tests. The current time is injected through
 * PassCaptain\Clock (filter `wpcap_now`), so every check steps across the spring-forward and
 * fall-back transitions of Europe/Vienna and America/New_York without sleeping:
 * hold expiry, sales windows and door sales, reminders (send time, no double send in the
 * repeated hour, none skipped in the missing hour), service slot series and booking deadlines,
 * waitlist offer deadlines, ICS output and report day boundaries.
 *
 * Fixed past transition dates are used (real "now" is later), so the global hold expiry run at a
 * simulated time never touches holds of other tests.
 * Included from run.php (uses check(), make_type(), ex_event()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Clock;
use PassCaptain\Orders;
use PassCaptain\ServiceBookings;
use PassCaptain\Waitlists;
use PassCaptain\Settings;
use PassCaptain\Admin\Forms;
use PassCaptain\Calendar\Ics;
use PassCaptain\Modules\Reminders;
use PassCaptain\Reports\Data;

global $wpdb;
$p = $wpdb->prefix;
$a199_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a199_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$a199_saved_tz = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$a199_saved_reminders = Settings::get( 'reminders' );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.199';

/** Injects the current time (UTC "Y-m-d H:i:s") or restores the real clock (null). */
function a199_at( $utc ) {
	if ( null === $utc ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_now'] ); return; }
	$GLOBALS['wpcap_test_filters']['wpcap_now'] = strtotime( $utc . ' UTC' );
	$GLOBALS['wpcap_test_transients'] = array(); // Rate limits are not under test here.
}
/** Event + occurrence (UTC start, zone) + ticket type; returns [event, occurrence, type]. */
function a199_offer( $starts_utc, $tz, $price = 1000, $capacity = 10, array $type_extra = array(), $ends_utc = null ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A199 Konzert', 'slug' => 'a199-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'timezone' => $tz, 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array_merge( array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ), $type_extra ) );
	return array( $event, $occ, (int) $wpdb->insert_id );
}
function a199_buy( $type, $key, array $internal = array() ) {
	return Orders::create( array( 'email' => "a199-$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'a199-' . $key . '-' . uniqid() ), array_merge( array( 'hold_minutes' => 10 ), $internal ) );
}
function a199_reason( $r ) { return is_wp_error( $r ) ? (string) ( $r->get_error_data()['reason'] ?? $r->get_error_code() ) : 'ok'; }
function a199_hold( $order_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT expires_at,status FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d", $order_id ) );
}
function a199_local( $utc, $tz ) { return Forms::utc_to_local( $utc, $tz, 'Y-m-d H:i T' ); }

echo "A199: Clock\n";
a199_at( '2026-03-28 23:30:00' );
check( 'Clock::now/mysql follow the injected time', strtotime( '2026-03-28 23:30:00 UTC' ) === Clock::now() && '2026-03-28 23:40:00' === Clock::mysql( 600 ) );
check( 'Clock::today is the local date (23:30 UTC = 00:30 Vienna next day; New York still the day before)', '2026-03-29' === Clock::today( 'Europe/Vienna' ) && '2026-03-28' === Clock::today( 'America/New_York' ) && '2026-03-28' === Clock::today( 'Invalid/Zone' ) );
check( 'check-in time follows the clock', Clock::now() === PassCaptain\Checkin::now() );
a199_at( null );
check( 'without injection the real time is used', abs( Clock::now() - time() ) <= 1 );

echo "A199: hold expiry is measured in real minutes across DST\n";
// Vienna spring forward 2026-03-29 01:00 UTC (02:00 CET -> 03:00 CEST).
list( , , $a199_t ) = a199_offer( '2026-03-29 10:00:00', 'Europe/Vienna' );
a199_at( '2026-03-29 00:55:00' ); // 01:55 CET
$a199_o = a199_buy( $a199_t, 'hold-spring' );
$a199_h = a199_hold( $a199_o['order_id'] ?? 0 );
check( 'Vienna spring: hold of 10 min at 01:55 CET ends 03:05 CEST (01:05 UTC)', ! is_wp_error( $a199_o ) && '2026-03-29 01:05:00' === $a199_h->expires_at && '2026-03-29 03:05 CEST' === a199_local( $a199_h->expires_at, 'Europe/Vienna' ) && 9 === Orders::availability( $a199_t ) );
a199_at( '2026-03-29 01:04:59' );
Orders::expire_holds();
check( 'Vienna spring: still held one second before the end', 'active' === a199_hold( $a199_o['order_id'] )->status && 9 === Orders::availability( $a199_t ) );
a199_at( '2026-03-29 01:05:00' );
Orders::expire_holds();
check( 'Vienna spring: expired exactly after 10 real minutes, capacity back', 'expired' === a199_hold( $a199_o['order_id'] )->status && 'expired' === Orders::get( $a199_o['order_id'] )->payment_status && 10 === Orders::availability( $a199_t ) );

// Vienna fall back 2025-10-26 01:00 UTC (03:00 CEST -> 02:00 CET): local clock runs backwards.
list( , , $a199_t ) = a199_offer( '2025-10-26 10:00:00', 'Europe/Vienna' );
a199_at( '2025-10-26 00:55:00' ); // 02:55 CEST
$a199_o = a199_buy( $a199_t, 'hold-fall' );
$a199_h = a199_hold( $a199_o['order_id'] ?? 0 );
check( 'Vienna fall: hold at 02:55 CEST ends at 02:05 CET (earlier wall clock, 10 real minutes later)', '2025-10-26 01:05:00' === $a199_h->expires_at && '2025-10-26 02:05 CET' === a199_local( $a199_h->expires_at, 'Europe/Vienna' ) );
a199_at( '2025-10-26 01:04:00' );
Orders::expire_holds();
check( 'Vienna fall: not expired at 02:04 CET (a local-time comparison would have expired it)', 'active' === a199_hold( $a199_o['order_id'] )->status );
a199_at( '2025-10-26 01:05:00' );
Orders::expire_holds();
check( 'Vienna fall: expired at 02:05 CET', 'expired' === a199_hold( $a199_o['order_id'] )->status );

// New York spring forward 2026-03-08 07:00 UTC (02:00 EST -> 03:00 EDT).
list( , , $a199_t ) = a199_offer( '2026-03-08 20:00:00', 'America/New_York' );
a199_at( '2026-03-08 06:55:00' ); // 01:55 EST
$a199_o = a199_buy( $a199_t, 'hold-ny' );
$a199_h = a199_hold( $a199_o['order_id'] ?? 0 );
check( 'New York spring: hold at 01:55 EST ends 03:05 EDT', '2026-03-08 07:05:00' === $a199_h->expires_at && '2026-03-08 03:05 EDT' === a199_local( $a199_h->expires_at, 'America/New_York' ) );
a199_at( '2026-03-08 07:05:00' );
Orders::expire_holds();
check( 'New York spring: expired after 10 real minutes', 'expired' === a199_hold( $a199_o['order_id'] )->status );

echo "A199: sales windows and door sales\n";
// Sales start entered as 02:30 local on the spring-forward day: does not exist, moves to 03:30 CEST.
$a199_ss = Forms::local_to_utc( '2026-03-29 02:30', 'Europe/Vienna' );
list( , , $a199_t ) = a199_offer( '2026-03-30 18:00:00', 'Europe/Vienna', 1000, 10, array( 'sales_start' => $a199_ss ) );
a199_at( '2026-03-29 01:29:59' );
$a199_early = a199_buy( $a199_t, 'ss-early' );
a199_at( '2026-03-29 01:30:00' );
$a199_open = a199_buy( $a199_t, 'ss-open' );
check( 'sales start in the DST gap opens at 03:30 CEST (01:30 UTC), not before', '2026-03-29 01:30:00' === $a199_ss && 'sales_closed' === a199_reason( $a199_early ) && 'ok' === a199_reason( $a199_open ) );
// Sales end entered as 02:30 local on the fall-back day: ambiguous, first instance (CEST).
$a199_se = Forms::local_to_utc( '2025-10-26 02:30', 'Europe/Vienna' );
list( , , $a199_t ) = a199_offer( '2025-10-27 18:00:00', 'Europe/Vienna', 1000, 10, array( 'sales_end' => $a199_se ) );
a199_at( '2025-10-26 00:30:00' );
$a199_last = a199_buy( $a199_t, 'se-last' );
a199_at( '2025-10-26 00:30:01' );
$a199_late = a199_buy( $a199_t, 'se-late' );
a199_at( '2025-10-26 01:15:00' ); // 02:15 CET: wall clock before 02:30 again, sales stay closed.
$a199_again = a199_buy( $a199_t, 'se-again' );
check( 'ambiguous sales end uses the first 02:30 (CEST) and stays closed in the repeated hour', '2025-10-26 00:30:00' === $a199_se && 'ok' === a199_reason( $a199_last ) && 'sales_closed' === a199_reason( $a199_late ) && 'sales_closed' === a199_reason( $a199_again ) );
// New York: sales start 02:30 on the spring day -> 03:30 EDT (07:30 UTC).
$a199_ny_ss = Forms::local_to_utc( '2026-03-08 02:30', 'America/New_York' );
list( , , $a199_t ) = a199_offer( '2026-03-09 01:00:00', 'America/New_York', 1000, 10, array( 'sales_start' => $a199_ny_ss ) );
a199_at( '2026-03-08 07:29:00' );
$a199_e = a199_buy( $a199_t, 'ny-ss-early' );
a199_at( '2026-03-08 07:30:00' );
check( 'New York: sales start in the gap opens at 03:30 EDT', '2026-03-08 07:30:00' === $a199_ny_ss && 'sales_closed' === a199_reason( $a199_e ) && 'ok' === a199_reason( a199_buy( $a199_t, 'ny-ss-open' ) ) );
// Door sale: start 00:00 CEST on the fall-back night, no end -> open for 6 real hours (until 05:00 CET).
list( , , $a199_t ) = a199_offer( '2025-10-25 22:00:00', 'Europe/Vienna', 1000, 10, array( 'sales_end' => '2025-10-25 21:00:00' ) );
$a199_pos = array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true, 'allow_door_sales' => true );
a199_at( '2025-10-26 03:59:59' ); // 04:59:59 CET
$a199_online = a199_buy( $a199_t, 'door-online' );
$a199_door = a199_buy( $a199_t, 'door-ok', $a199_pos );
a199_at( '2025-10-26 04:00:01' ); // 05:00:01 CET = 6 h + 1 s after the start
$a199_door_late = a199_buy( $a199_t, 'door-late', $a199_pos );
check( 'door sale after the start and the online window, until 6 real hours after the start (05:00 CET)', 'occurrence_unavailable' === a199_reason( $a199_online ) && 'ok' === a199_reason( $a199_door ) && 'occurrence_unavailable' === a199_reason( $a199_door_late ) );
a199_at( null );

echo "A199: reminders across DST\n";
Reminders::register();
/** Runs the reminder scheduler every $step seconds from $from to $to; returns [utc => stage] of runs that queued. */
function a199_reminder_runs( $occ, $from, $to, $step = 600 ) {
	global $wpdb;
	$runs = array();
	for ( $t = strtotime( $from . ' UTC' ); $t <= strtotime( $to . ' UTC' ); $t += $step ) {
		$GLOBALS['wpcap_test_filters']['wpcap_now'] = $t;
		if ( Reminders::schedule_due() ) {
			$runs[ gmdate( 'Y-m-d H:i:s', $t ) ] = (int) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s ORDER BY message_id DESC LIMIT 1", '%"occurrence_id":' . (int) $occ . ',%' ) ), true )['hours'];
		}
	}
	unset( $GLOBALS['wpcap_test_filters']['wpcap_now'] );
	return $runs;
}
function a199_reminder_count( $occ ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s", '%"occurrence_id":' . (int) $occ . ',%' ) );
}
function a199_holder( $type, $at, $key ) {
	a199_at( $at );
	$r = a199_buy( $type, $key );
	a199_at( null );
	return $r;
}
Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 24, 2 ), 'subject' => 'Erinnerung {event}', 'body' => '{event} um {time} ({timezone})', 'sms' => 0, 'sms_body' => '' ) ) );
// Fall back: start 04:00 CET (03:00 UTC); the 2 h reminder is due at 02:00 CET, i.e. in the repeated hour.
list( , $a199_occ, $a199_t ) = a199_offer( '2025-10-26 03:00:00', 'Europe/Vienna', 0 );
a199_holder( $a199_t, '2025-10-20 10:00:00', 'rem-fall' );
$a199_runs = a199_reminder_runs( $a199_occ, '2025-10-25 01:00:00', '2025-10-26 02:59:00' );
check( 'Vienna fall: 24 h reminder at 05:00 CEST the day before, 2 h reminder once at 02:00 CET (second 02:00)', array( '2025-10-25 03:00:00' => 24, '2025-10-26 01:00:00' => 2 ) === $a199_runs && 2 === a199_reminder_count( $a199_occ ) );
check( 'Vienna fall: the reminder shows the local start 04:00 and the zone', false !== strpos( Reminders::render_template( null, 'wpcap_reminder', array( 'occurrence_id' => $a199_occ, 'hours' => 2, 'tickets' => 1 ) )['body'], 'um 04:00 (Europe/Vienna)' ) );
// Spring forward with a 1 h offset: start 03:30 CEST (01:30 UTC). Naive wall-clock arithmetic would
// give 02:30, which does not exist; the reminder goes out 60 real minutes before, at 01:30 CET.
Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 1 ), 'subject' => 'Erinnerung {event}', 'body' => '{event} um {time}', 'sms' => 0, 'sms_body' => '' ) ) );
list( , $a199_occ, $a199_t ) = a199_offer( '2026-03-29 01:30:00', 'Europe/Vienna', 0 );
a199_holder( $a199_t, '2026-03-20 10:00:00', 'rem-spring' );
$a199_runs = a199_reminder_runs( $a199_occ, '2026-03-28 23:00:00', '2026-03-29 01:20:00', 300 );
check( 'Vienna spring: 1 h reminder sent once at 01:30 CET, not skipped by the missing hour', array( '2026-03-29 00:30:00' => 1 ) === $a199_runs && 1 === a199_reminder_count( $a199_occ ) );
a199_at( '2026-03-29 01:29:00' );
$a199_valid_before = Reminders::still_valid( 'a199-rem-spring@example.com', array( 'occurrence_id' => $a199_occ, 'starts_at' => '2026-03-29 01:30:00' ) );
a199_at( '2026-03-29 01:30:00' );
$a199_valid_after = Reminders::still_valid( 'a199-rem-spring@example.com', array( 'occurrence_id' => $a199_occ, 'starts_at' => '2026-03-29 01:30:00' ) );
a199_at( null );
check( 'a queued reminder is valid until the start (clock-driven), not after', $a199_valid_before && ! $a199_valid_after );
// New York: fall back 2026-11-01 06:00 UTC (02:00 EDT -> 01:00 EST); start 01:30 EST (06:30 UTC),
// 1 h reminder due at 01:30 EDT (05:30 UTC): the first of the two 01:30.
list( , $a199_occ, $a199_t ) = a199_offer( '2026-11-01 06:30:00', 'America/New_York', 0 );
a199_holder( $a199_t, '2026-10-25 10:00:00', 'rem-ny' );
$a199_runs = a199_reminder_runs( $a199_occ, '2026-11-01 04:00:00', '2026-11-01 06:25:00', 300 );
check( 'New York fall: 1 h reminder exactly once, 60 real minutes before (01:30 EDT)', array( '2026-11-01 05:30:00' => 1 ) === $a199_runs && 1 === a199_reminder_count( $a199_occ ) );
// Queued test reminders must not be sent by later tests.
$wpdb->query( "UPDATE {$p}wpcap_message_queue SET status='cancelled' WHERE template_key='wpcap_reminder' AND status='queued' AND recipient LIKE 'a199-%'" );
Settings::update( array( 'reminders' => $a199_saved_reminders ) );

echo "A199: service slot series and booking deadlines\n";
$a199_event = ex_event( 'A199 Bootsverleih' );
$a199_res = ServiceBookings::create_resource( $a199_event, array( 'name' => 'Boot DST', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'duration_minutes' => 60 ) );
function a199_series( $resource, $day, $from, $to, $tz, $duration = 60 ) {
	global $wpdb;
	$r = ServiceBookings::generate_slots( $resource, array( 'date_from' => $day, 'date_to' => $day, 'time_from' => $from, 'time_to' => $to, 'weekdays' => range( 1, 7 ), 'duration' => $duration, 'timezone' => $tz ) );
	if ( is_wp_error( $r ) || ! $r['slot_ids'] ) { return array(); }
	return array_map( static fn( $s ) => a199_local( $s, $tz ), $wpdb->get_col( 'SELECT starts_at FROM ' . $wpdb->prefix . 'wpcap_service_slots WHERE slot_id IN (' . implode( ',', $r['slot_ids'] ) . ') ORDER BY starts_at' ) );
}
a199_at( '2025-10-01 00:00:00' );
check( 'Vienna spring 01:00-05:00: slots at 01:00, 03:00, 04:00 (no slot in the missing hour)', array( '2026-03-29 01:00 CET', '2026-03-29 03:00 CEST', '2026-03-29 04:00 CEST' ) === a199_series( $a199_res, '2026-03-29', '01:00', '05:00', 'Europe/Vienna' ) );
check( 'Vienna fall 01:00-04:00: four real hours, 02:00 exists twice', array( '2025-10-26 01:00 CEST', '2025-10-26 02:00 CEST', '2025-10-26 02:00 CET', '2025-10-26 03:00 CET' ) === a199_series( $a199_res, '2025-10-26', '01:00', '04:00', 'Europe/Vienna' ) );
$a199_res2 = ServiceBookings::create_resource( $a199_event, array( 'name' => 'Boot DST 2', 'capacity' => 1, 'buffer_before' => 0, 'buffer_after' => 0, 'duration_minutes' => 60 ) );
check( 'Vienna fall: an opening time in the repeated hour starts at its first instance (02:00 CEST), the closing time is the second', array( '2025-10-26 02:00 CEST', '2025-10-26 02:00 CET' ) === a199_series( $a199_res2, '2025-10-26', '02:00', '03:00', 'Europe/Vienna' ) );
check( 'Vienna spring: an opening time in the gap moves forward (02:30 -> 03:30 CEST)', array( '2026-03-29 03:30 CEST' ) === a199_series( $a199_res2, '2026-03-29', '02:30', '04:30', 'Europe/Vienna' ) );
check( 'New York spring 01:00-04:00: 01:00 EST and 03:00 EDT', array( '2026-03-08 01:00 EST', '2026-03-08 03:00 EDT' ) === a199_series( $a199_res, '2026-03-08', '01:00', '04:00', 'America/New_York' ) );
check( 'New York fall 00:30-02:30 in 30-min slots: 01:00 and 01:30 exist twice', array( '2026-11-01 00:30 EDT', '2026-11-01 01:00 EDT', '2026-11-01 01:30 EDT', '2026-11-01 01:00 EST', '2026-11-01 01:30 EST', '2026-11-01 02:00 EST' ) === a199_series( $a199_res, '2026-11-01', '00:30', '02:30', 'America/New_York', 30 ) );
check( 'series again: every candidate overlaps and is skipped', array() === a199_series( $a199_res, '2026-03-29', '01:00', '05:00', 'Europe/Vienna' ) );
a199_at( '2026-03-29 02:30:00' );
check( 'slots already in the past (clock) are not generated', array( '2026-03-29 05:00 CEST' ) === a199_series( $a199_res2, '2026-03-29', '04:00', '06:00', 'Europe/Vienna' ) );

// Booking rules: start Sunday 10:00 CEST (08:00 UTC) after the spring change. Deadlines are real
// durations: "24 h before" is Saturday 09:00 CET, not 10:00 wall-clock time.
$a199_rules = ServiceBookings::create_resource( $a199_event, array( 'name' => 'Kurs DST', 'capacity' => 5, 'buffer_before' => 0, 'buffer_after' => 0, 'duration_minutes' => 60 ) );
ServiceBookings::update_resource( $a199_rules, array( 'lead_minutes' => 120, 'advance_days' => 1, 'rebook_hours' => 12, 'cancel_hours' => 24 ) );
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $a199_rules, 'starts_at' => '2026-03-29 08:00:00', 'ends_at' => '2026-03-29 09:00:00', 'capacity' => 5, 'status' => 'open' ) );
$a199_slot = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $a199_rules, 'starts_at' => '2026-03-29 07:00:00', 'ends_at' => '2026-03-29 08:00:00', 'capacity' => 5, 'status' => 'open' ) );
$a199_slot2 = (int) $wpdb->insert_id;
function a199_reserve( $slot, $at, $email ) {
	a199_at( $at );
	$r = ServiceBookings::reserve( $slot, $email, 1, 0, 10, 'a199-' . uniqid( '', true ) );
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
}
check( 'advance booking (1 day = 24 h): refused at 08:59:59 CET Saturday', 'wpcap_booking_too_early' === a199_reserve( $a199_slot, '2026-03-28 07:59:59', 'a199-sb1@example.com' ) );
$a199_b1 = a199_reserve( $a199_slot, '2026-03-28 08:00:00', 'a199-sb1@example.com' );
check( 'advance booking opens at 09:00 CET Saturday (24 real hours before 10:00 CEST)', is_array( $a199_b1 ) );
$a199_slot_row = ServiceBookings::bookable_slot( $a199_slot );
a199_at( '2026-03-28 07:59:59' );
$a199_cancel_open = ServiceBookings::cancel_deadline_error( $a199_slot_row );
a199_at( '2026-03-28 08:00:00' );
$a199_cancel_closed = ServiceBookings::cancel_deadline_error( $a199_slot_row );
check( 'cancellation deadline 24 h: open until 08:59:59 CET Saturday, closed at 09:00 CET', null === $a199_cancel_open && is_wp_error( $a199_cancel_closed ) && 'wpcap_booking_cancel_deadline' === $a199_cancel_closed->get_error_code() );
a199_at( '2026-03-28 19:59:59' );
$a199_rebook_open = ServiceBookings::rebook_deadline_error( $a199_slot_row );
a199_at( '2026-03-28 20:00:00' );
$a199_rebook_closed = ServiceBookings::rebook_deadline_error( $a199_slot_row );
check( 'rebooking deadline 12 h: closes at 21:00 CET Saturday', null === $a199_rebook_open && is_wp_error( $a199_rebook_closed ) );
check( 'booking cutoff 120 min: open at 07:59:59 CEST (05:59:59 UTC)', is_array( a199_reserve( $a199_slot, '2026-03-29 05:59:59', 'a199-sb2@example.com' ) ) );
check( 'booking cutoff 120 min: closed at 06:00:01 UTC (08:00:01 CEST)', 'wpcap_booking_cutoff' === a199_reserve( $a199_slot, '2026-03-29 06:00:01', 'a199-sb3@example.com' ) );
$a199_b4 = a199_reserve( $a199_slot2, '2026-03-28 18:00:00', 'a199-sb4@example.com' );
a199_at( '2026-03-28 18:05:00' );
ServiceBookings::confirm( $a199_b4['booking_id'] ?? 0 );
a199_at( '2026-03-28 19:00:00' ); // 20:00 CET: 11 h before 08:00 CEST (07:00 UTC) start of slot 2
$a199_rb = ServiceBookings::rebook( $a199_b4['booking_id'] ?? 0, $a199_slot );
check( 'customer rebooking 11 real hours before the start is refused (12 h deadline)', is_wp_error( $a199_rb ) && 'wpcap_booking_rebook_deadline' === $a199_rb->get_error_code() );
$a199_cn = ServiceBookings::cancel( $a199_b4['booking_id'] ?? 0 );
check( 'customer cancellation of a confirmed booking after the 24 h deadline is refused', is_wp_error( $a199_cn ) && 'wpcap_booking_cancel_deadline' === $a199_cn->get_error_code() );
a199_at( null );

echo "A199: waitlist offer deadlines\n";
function a199_waitlist_offer( $starts, $tz, $at, $key ) {
	list( $event, $occ, $type ) = a199_offer( $starts, $tz, 0, 1 );
	a199_at( $at );
	Waitlists::join( $event, $occ, $type, "a199-wl-$key@example.com" );
	$offer = Waitlists::offer_next( $event, $type );
	$mail = end( $GLOBALS['wpcap_test_mails'] );
	preg_match( '/token=([a-f0-9]{64})/', (string) ( $mail['body'] ?? '' ), $m );
	return array( $offer, $m[1] ?? '' );
}
// Offer 24 h at 13:00 CET Saturday -> 14:00 CEST Sunday (24 real hours; the wall clock moves 25 h).
list( $a199_offer, $a199_token ) = a199_waitlist_offer( '2026-03-31 18:00:00', 'Europe/Vienna', '2026-03-28 12:00:00', 'spring' );
check( 'Vienna spring: offer deadline is 24 real hours later (14:00 CEST)', is_array( $a199_offer ) && '2026-03-29 12:00:00' === $a199_offer['expires_at'] && '2026-03-29 14:00 CEST' === a199_local( $a199_offer['expires_at'], 'Europe/Vienna' ) );
a199_at( '2026-03-29 12:00:00' );
$a199_late = Waitlists::accept_offer( $a199_offer['waitlist_id'], $a199_token );
a199_at( '2026-03-29 11:59:59' );
$a199_ok = Waitlists::accept_offer( $a199_offer['waitlist_id'], $a199_token );
check( 'offer can be accepted until one second before the deadline, not at it', is_wp_error( $a199_late ) && ! is_wp_error( $a199_ok ) );
// Offer near the start: capped at the start (New York fall back night, start 03:00 EST = 08:00 UTC).
list( $a199_offer ) = a199_waitlist_offer( '2026-11-01 08:00:00', 'America/New_York', '2026-10-31 20:00:00', 'ny' );
check( 'New York fall: offer deadline capped at the start (03:00 EST), 12 real hours', is_array( $a199_offer ) && '2026-11-01 08:00:00' === $a199_offer['expires_at'] && '2026-11-01 03:00 EST' === a199_local( $a199_offer['expires_at'], 'America/New_York' ) );
a199_at( null );

echo "A199: ICS output\n";
$a199_row = (object) array( 'occurrence_id' => 991990, 'starts_at' => '2026-03-29 00:30:00', 'ends_at' => '2026-03-29 02:30:00', 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'status' => 'scheduled', 'event_name' => 'Nachtkonzert', 'event_description' => '' );
a199_at( '2026-03-01 12:00:00' );
$a199_ics = Ics::build( array( Ics::entry_from_row( $a199_row ) ) );
a199_at( null );
check( 'ICS: times in UTC (Z), across the gap 01:30 CET-04:30 CEST = 2 h, no TZID/VTIMEZONE', false !== strpos( $a199_ics, "DTSTART:20260329T003000Z\r\n" ) && false !== strpos( $a199_ics, "DTEND:20260329T023000Z\r\n" ) && false === strpos( $a199_ics, 'TZID' ) && false === strpos( $a199_ics, 'VTIMEZONE' ) );
check( 'ICS: DTSTAMP follows the clock', false !== strpos( $a199_ics, "DTSTAMP:20260301T120000Z\r\n" ) );
$a199_row->starts_at = '2025-10-26 00:30:00'; // 02:30 CEST (first instance)
$a199_row->ends_at = null;                    // default 2 h -> 03:30 CET
$a199_entry = Ics::entry_from_row( $a199_row );
check( 'ICS: fall back default duration is 2 real hours (02:30 CEST -> 03:30 CET)', 7200 === $a199_entry['end'] - $a199_entry['start'] && false !== strpos( Ics::build( array( $a199_entry ) ), 'DTEND:20251026T023000Z' ) );
list( , $a199_occ ) = a199_offer( '2026-11-01 05:30:00', 'America/New_York' ); // 01:30 EDT
check( 'ICS: New York occurrence at the first 01:30 is written as 05:30 UTC', false !== strpos( (string) Ics::occurrence_calendar( $a199_occ ), 'DTSTART:20261101T053000Z' ) );

echo "A199: report day boundaries\n";
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
check( 'report bounds: the spring day has 23 h, the fall day 25 h (Vienna)', array( '2026-03-28 23:00:00', '2026-03-29 22:00:00' ) === Data::bounds( '2026-03-29', '2026-03-29', 'Europe/Vienna' ) && array( '2025-10-25 22:00:00', '2025-10-26 23:00:00' ) === Data::bounds( '2025-10-26', '2025-10-26', 'Europe/Vienna' ) );
check( 'report bounds New York fall day: 04:00-05:00 UTC (25 h)', array( '2026-11-01 04:00:00', '2026-11-02 05:00:00' ) === Data::bounds( '2026-11-01', '2026-11-01', 'America/New_York' ) );
list( $a199_revent, , $a199_rt ) = a199_offer( gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'Europe/Vienna', 1000, 20 );
$a199_created = array(
	'a' => '2026-03-28 22:30:00', // 23:30 CET 28.03.
	'b' => '2026-03-28 23:30:00', // 00:30 CET 29.03.
	'c' => '2026-03-29 21:30:00', // 23:30 CEST 29.03.
	'd' => '2026-03-29 22:30:00', // 00:30 CEST 30.03.
);
foreach ( $a199_created as $a199_k => $a199_c ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$a199_r = a199_buy( $a199_rt, 'rep-' . $a199_k );
	Orders::confirm_manual_payment( $a199_r['order_id'] );
	$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => $a199_c ), array( 'order_id' => $a199_r['order_id'] ) );
}
$a199_rep = Data::build( array( 'from' => '2026-03-28', 'to' => '2026-03-30', 'event_id' => $a199_revent ), null );
$a199_days = array_map( static fn( $b ) => $b['tickets'], $a199_rep['dimensions']['day'] );
check( 'bookings at 23:30 and 00:30 local fall on the local day (28: 1, 29: 2, 30: 1)', array( '2026-03-28' => 1, '2026-03-29' => 2, '2026-03-30' => 1 ) === $a199_days );
$a199_one = Data::build( array( 'from' => '2026-03-29', 'to' => '2026-03-29', 'event_id' => $a199_revent ), null );
check( 'single-day report of the 23-hour spring day contains exactly its two bookings', 2 === $a199_one['total']['tickets'] && 2000 === $a199_one['total']['gross'] );
a199_at( '2026-03-28 23:30:00' ); // 00:30 CET on 29.03.
$a199_default = Data::build( array( 'event_id' => $a199_revent ), null );
check( 'default report range ends today in the site timezone (00:30 local is already the 29th)', '2026-03-29' === $a199_default['to'] && '2026-03-01' === $a199_default['from'] && '2026-03-29' === PassCaptain\Reports\Page::filters( array() )['to'] && '2026-02-28' === PassCaptain\Reports\Page::filters( array() )['from'] );
/** Orders counted by the REST event report for one local day. */
function a199_api_orders( $event, $day ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$req = new WP_REST_Request( 'GET', '/pass-captain/v1/reports/events/' . $event );
	$req->set_param( 'event_id', $event );
	$req->set_param( 'from', $day );
	$req->set_param( 'to', $day );
	$res = PassCaptain\Rest\Controller::event_report( $req );
	$res = $res instanceof WP_REST_Response ? $res->get_data() : $res;
	return (int) ( $res['sales_by_currency'][0]['orders_count'] ?? 0 );
}
check( 'REST event report uses local day boundaries too (28.: 1, 29.: 2, 30.: 1 – UTC days would give 2, 2, 0)', 1 === a199_api_orders( $a199_revent, '2026-03-28' ) && 2 === a199_api_orders( $a199_revent, '2026-03-29' ) && 1 === a199_api_orders( $a199_revent, '2026-03-30' ) );
a199_at( null );
$GLOBALS['wpcap_test_options']['timezone_string'] = 'America/New_York';
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => '2026-11-01 04:30:00' ), array( 'customer_email' => 'a199-rep-a@example.com' ) ); // 00:30 EDT 01.11.
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => '2026-11-02 04:30:00' ), array( 'customer_email' => 'a199-rep-b@example.com' ) ); // 23:30 EST 01.11.
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => '2026-11-02 05:30:00' ), array( 'customer_email' => 'a199-rep-c@example.com' ) ); // 00:30 EST 02.11.
$a199_ny = Data::build( array( 'from' => '2026-11-01', 'to' => '2026-11-02', 'event_id' => $a199_revent ), null );
check( 'New York: 00:30 EDT and 23:30 EST belong to the 25-hour fall day, 00:30 EST to the next', array( '2026-11-01' => 2, '2026-11-02' => 1 ) === array_map( static fn( $b ) => $b['tickets'], $a199_ny['dimensions']['day'] ) );

// Restore.
if ( null === $a199_saved_tz ) { unset( $GLOBALS['wpcap_test_options']['timezone_string'] ); } else { $GLOBALS['wpcap_test_options']['timezone_string'] = $a199_saved_tz; }
a199_at( null );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_user_id'] = $a199_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $a199_saved_denied;
