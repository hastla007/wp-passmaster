<?php
/**
 * Deterministic timezone / DST tests (W6): Europe/Vienna 2026-03-29 (02:00 → 03:00, CET → CEST),
 * 2026-10-25 (03:00 → 02:00, CEST → CET) and midnight boundaries.
 *
 * Series generation, sales windows, reservation expiry, admission day boundaries, reminders and
 * service slots. Everything runs a second time with a non-UTC PHP default timezone (some hosts and
 * plugins change it); results must be identical because all storage and arithmetic is UTC.
 *
 * Uses adm_setup()/adm_in()/adm_ts() from admission.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Series\Rules;
use PassCaptain\Series\Generator;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;
use PassCaptain\Modules\Reminders;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

/** UTC "Y-m-d H:i:s" of a Vienna wall time. */
function w6t_utc( $local ) { return gmdate( 'Y-m-d H:i:s', adm_ts( $local, 'Europe/Vienna' ) ); }
/** Expanded occurrences [date => [local, UTC start, shifted]] of a rule. */
function w6t_expand( array $in ) {
	$rule = Rules::normalize( $in + array( 'timezone' => 'Europe/Vienna', 'duration_minutes' => 120 ) );
	if ( is_wp_error( $rule ) ) { return $rule->get_error_message(); }
	$out = array();
	foreach ( Rules::expand( $rule ) as $o ) { $out[ $o['date'] ] = array( $o['local'], $o['starts_at'], $o['shifted'], $o['ends_at'] ); }
	return $out;
}

foreach ( array( 'UTC', 'Europe/Vienna' ) as $w6t_php_tz ) {
	date_default_timezone_set( $w6t_php_tz );
	echo "W6 time (PHP default timezone $w6t_php_tz): series generation across DST\n";

	$weekly = w6t_expand( array( 'freq' => 'weekly', 'start_date' => '2026-03-22', 'count' => 3, 'time' => '20:00' ) );
	check( "[$w6t_php_tz] weekly 20:00 keeps local time across spring forward (19:00 → 18:00 UTC)", array( '2026-03-22' => '2026-03-22 19:00:00', '2026-03-29' => '2026-03-29 18:00:00', '2026-04-05' => '2026-04-05 18:00:00' ) === array_map( static fn( $o ) => $o[1], $weekly ) );
	$weekly = w6t_expand( array( 'freq' => 'weekly', 'start_date' => '2026-10-18', 'count' => 3, 'time' => '20:00' ) );
	check( "[$w6t_php_tz] weekly 20:00 keeps local time across fall back (18:00 → 19:00 UTC)", array( '2026-10-18' => '2026-10-18 18:00:00', '2026-10-25' => '2026-10-25 19:00:00', '2026-11-01' => '2026-11-01 19:00:00' ) === array_map( static fn( $o ) => $o[1], $weekly ) );
	$spring = w6t_expand( array( 'freq' => 'daily', 'start_date' => '2026-03-28', 'count' => 3, 'time' => '02:30' ) );
	check( "[$w6t_php_tz] 02:30 on 2026-03-29 does not exist: shifted to 03:30 CEST (01:30 UTC) and flagged", '2026-03-29 03:30' === $spring['2026-03-29'][0] && '2026-03-29 01:30:00' === $spring['2026-03-29'][1] && true === $spring['2026-03-29'][2] && false === $spring['2026-03-28'][2] && '2026-03-28 01:30:00' === $spring['2026-03-28'][1] && '2026-03-30 00:30:00' === $spring['2026-03-30'][1] );
	$fall = w6t_expand( array( 'freq' => 'daily', 'start_date' => '2026-10-24', 'count' => 3, 'time' => '02:30' ) );
	check( "[$w6t_php_tz] 02:30 on 2026-10-25 exists twice: the first instance (CEST, 00:30 UTC) is used once", '2026-10-25 00:30:00' === $fall['2026-10-25'][1] && false === $fall['2026-10-25'][2] && 3 === count( $fall ) && '2026-10-26 01:30:00' === $fall['2026-10-26'][1] );
	check( "[$w6t_php_tz] duration is elapsed time: 2 h show starting 01:30 CET on 2026-03-29 ends 04:30 CEST", '2026-03-29 02:30:00' === w6t_expand( array( 'freq' => 'dates', 'dates' => '2026-03-29', 'time' => '01:30' ) )['2026-03-29'][3] );
	$midnight = w6t_expand( array( 'freq' => 'dates', 'dates' => "2026-03-29\n2026-10-25\n2026-10-26", 'time' => '00:00' ) );
	check( "[$w6t_php_tz] midnight starts stay on their local date (23:00 / 22:00 / 23:00 UTC the day before)", '2026-03-28 23:00:00' === $midnight['2026-03-29'][1] && '2026-10-24 22:00:00' === $midnight['2026-10-25'][1] && '2026-10-25 23:00:00' === $midnight['2026-10-26'][1] );

	echo "W6 time [$w6t_php_tz]: sales windows\n";
	check( "[$w6t_php_tz] 'sales start 7 days before' a 20:00 show after spring forward starts at 20:00 CET", w6t_utc( '2026-03-25 20:00' ) === Generator::sales_time( w6t_utc( '2026-04-01 20:00' ), 7, 86400, null, null, 'Europe/Vienna' ) );
	check( "[$w6t_php_tz] 'sales start 7 days before' after fall back starts at 20:00 CEST", w6t_utc( '2026-10-21 20:00' ) === Generator::sales_time( w6t_utc( '2026-10-28 20:00' ), 7, 86400, null, null, 'Europe/Vienna' ) );
	check( "[$w6t_php_tz] 'sales end 60 minutes before' is elapsed time across the gap (03:30 CEST → 01:30 CET)", w6t_utc( '2026-03-29 01:30' ) === Generator::sales_time( w6t_utc( '2026-03-29 03:30' ), 60, 60, null, null, 'Europe/Vienna' ) );
	$template = (object) array( 'starts_at' => w6t_utc( '2026-03-25 20:00' ), 'timezone' => 'Europe/Vienna' );
	check( "[$w6t_php_tz] template sales start (7 days before at 10:00) copied across DST keeps 10:00 local", w6t_utc( '2026-03-25 10:00' ) === Generator::sales_time( w6t_utc( '2026-04-01 20:00' ), null, 86400, w6t_utc( '2026-03-18 10:00' ), $template, 'Europe/Vienna' ) );
	$type = make_type( 0, 10, 1000 );
	$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_end' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'ticket_type_id' => $type ) );
	wpcap_test_reset_transients();
	$closed = Orders::create( array( 'email' => 'w6t@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w6t-c-' . uniqid() ), array( 'skip_rate_limit' => true ) );
	$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_end' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ), array( 'ticket_type_id' => $type ) );
	$open = Orders::create( array( 'email' => 'w6t@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w6t-o-' . uniqid() ), array( 'skip_rate_limit' => true ) );
	check( "[$w6t_php_tz] sales window compares UTC: closed one second after the end, open two minutes before", is_wp_error( $closed ) && ! is_wp_error( $open ) );

	echo "W6 time [$w6t_php_tz]: reservation expiry\n";
	$hold = $wpdb->get_var( "SELECT expires_at FROM {$p}wpcap_reservations WHERE order_id=" . (int) $open['order_id'] );
	check( "[$w6t_php_tz] hold expiry is stored in UTC (now + 10 min)", abs( strtotime( $hold . ' UTC' ) - ( time() + 600 ) ) <= 5 );
	$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() + 30 ) . "' WHERE order_id=" . (int) $open['order_id'] );
	Orders::expire_holds();
	$still = Orders::get( $open['order_id'] )->payment_status;
	$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 1 ) . "' WHERE order_id=" . (int) $open['order_id'] );
	Orders::expire_holds();
	check( "[$w6t_php_tz] hold expires exactly by UTC (still pending 30 s before, expired 1 s after)", 'pending' === $still && 'expired' === Orders::get( $open['order_id'] )->payment_status );

	echo "W6 time [$w6t_php_tz]: admission day boundaries\n";
	$sd = static function ( $local_or_utc, $cutoff, $is_utc = false ) {
		$GLOBALS['wpcap_test_filters']['wpcap_admission_day_cutoff_hour'] = $cutoff;
		$ts = $is_utc ? strtotime( $local_or_utc . ' UTC' ) : adm_ts( $local_or_utc, 'Europe/Vienna' );
		$d = Engine::service_date( $ts, 'Europe/Vienna', array() );
		unset( $GLOBALS['wpcap_test_filters']['wpcap_admission_day_cutoff_hour'] );
		return $d;
	};
	check( "[$w6t_php_tz] midnight without cutoff: 23:59:59 belongs to the day, 00:00 to the next", '2026-03-28' === $sd( '2026-03-28 23:59:59', 0 ) && '2026-03-29' === $sd( '2026-03-29 00:00:00', 0 ) && '2026-10-25' === $sd( '2026-10-25 00:00:00', 0 ) );
	check( "[$w6t_php_tz] spring forward, cutoff 03:00: 01:59 CET is the previous day, 03:00 CEST already the new day", '2026-03-28' === $sd( '2026-03-29 00:59:00', 3, true ) && '2026-03-29' === $sd( '2026-03-29 01:00:00', 3, true ) );
	check( "[$w6t_php_tz] fall back, cutoff 03:00: both 02:30 (CEST and CET) belong to the previous day, 03:00 CET to the new day", '2026-10-24' === $sd( '2026-10-25 00:30:00', 3, true ) && '2026-10-24' === $sd( '2026-10-25 01:30:00', 3, true ) && '2026-10-25' === $sd( '2026-10-25 02:00:00', 3, true ) );

	$s = adm_setup( 'Europe/Vienna', '2026-10-24 18:00', '2026-10-26 23:00', 1 );
	Policies::save( $s['type'], array( 'policy' => 'daily' ) );
	$GLOBALS['wpcap_test_filters']['wpcap_admission_day_cutoff_hour'] = 3;
	$first = adm_in( $s['codes'][0], strtotime( '2026-10-25 00:30:00 UTC' ) );  // 02:30 CEST
	$second = adm_in( $s['codes'][0], strtotime( '2026-10-25 01:30:00 UTC' ) ); // 02:30 CET, same service day
	$next = adm_in( $s['codes'][0], strtotime( '2026-10-25 02:00:00 UTC' ) );   // 03:00 CET, new service day
	unset( $GLOBALS['wpcap_test_filters']['wpcap_admission_day_cutoff_hour'] );
	check( "[$w6t_php_tz] daily ticket in the repeated hour: one entry per service day (02:30 CEST ok, 02:30 CET refused, 03:00 CET ok)", 'checked_in' === $first && 'daily_limit_reached' === $second && 'checked_in' === $next );

	echo "W6 time [$w6t_php_tz]: reminders and service slots\n";
	$start = strtotime( w6t_utc( '2026-03-29 20:00' ) . ' UTC' );
	check( "[$w6t_php_tz] 24 h reminder across spring forward is 24 elapsed hours (19:00 CET the day before)", 24 === Reminders::stage( $start, array( 24 ), $start - 24 * 3600 ) && 0 === Reminders::stage( $start, array( 24 ), $start - 24 * 3600 - 1 ) && w6t_utc( '2026-03-28 19:00' ) === gmdate( 'Y-m-d H:i:s', $start - 24 * 3600 ) );
	check( "[$w6t_php_tz] stage picks the smallest due offset and stops at the start", 2 === Reminders::stage( $start, array( 2, 24 ), $start - 3600 ) && 24 === Reminders::stage( $start, array( 2, 24 ), $start - 5 * 3600 ) && 0 === Reminders::stage( $start, array( 2, 24 ), $start ) );
	$slot_req = new WP_REST_Request( 'POST' );
	$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => (int) $s['event'], 'name' => 'Tisch', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
	$slot_req->set_param( 'resource_id', (int) $wpdb->insert_id );
	$slot_req->set_body( json_encode( array( 'starts_at' => '2026-10-25T02:30:00+02:00', 'ends_at' => '2026-10-25T02:30:00+01:00' ) ) );
	$slot = PassCaptain\Rest\Controller::create_service_slot( $slot_req );
	$row = $slot instanceof WP_REST_Response ? $wpdb->get_row( "SELECT starts_at,ends_at FROM {$p}wpcap_service_slots WHERE slot_id=" . (int) $slot->get_data()['slot_id'] ) : null;
	check( "[$w6t_php_tz] service slot in the repeated hour (02:30 CEST → 02:30 CET) is stored in UTC as one hour", $row && '2026-10-25 00:30:00' === $row->starts_at && '2026-10-25 01:30:00' === $row->ends_at );
}
date_default_timezone_set( 'UTC' );
