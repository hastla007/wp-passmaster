<?php
/**
 * A555: a rescheduled single occurrence moves the sales windows of its ticket types by the same
 * local calendar distance (windows that would end up outside [now, end] are kept, with a warning).
 * A556: series offsets "N days before the start" count local days (same time of day across DST).
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Occurrence;
use PassCaptain\Series\Generator;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

function a555_occurrence( $starts_utc, array $windows, $timezone = 'Europe/Vienna' ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A555 Konzert', 'slug' => 'a555-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $starts_utc, 'timezone' => $timezone, 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$types = array();
	foreach ( $windows as $name => $window ) {
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active', 'sales_start' => $window[0], 'sales_end' => $window[1] ) );
		$types[ $name ] = (int) $wpdb->insert_id;
	}
	return array( 'event' => $event, 'occ' => $occ, 'types' => $types );
}
function a555_type( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT sales_start,sales_end FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $id ) );
}
function a555_local( $utc ) {
	return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d H:i T' );
}

echo "A555: sales windows follow a rescheduled occurrence\n";
// Start Tue 2027-03-30 19:00 CEST; presale from Sat 2027-03-27 10:00 CET (before the DST change on 28.3.).
$a555 = a555_occurrence( '2027-03-30 17:00:00', array( 'Vorverkauf' => array( '2027-03-27 09:00:00', '2027-03-30 15:00:00' ), 'Abendkasse' => array( null, null ) ) );
$a555_res = EventsPage::save_occurrence( array( 'occurrence_id' => $a555['occ'], 'timezone' => 'Europe/Vienna', 'starts_at' => '2027-04-06T19:00', 'ends_at' => '', 'location' => 'Halle', 'capacity' => 0 ) );
$a555_t = a555_type( $a555['types']['Vorverkauf'] );
check( 'A555: the occurrence moved one week', ! is_wp_error( $a555_res ) && '2027-04-06 17:00:00' === $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=" . $a555['occ'] ) );
check( 'A555: sales start moves one local week (Sat 10:00 stays 10:00, now CEST)', '2027-04-03 10:00 CEST' === a555_local( $a555_t->sales_start ) && '2027-04-03 08:00:00' === $a555_t->sales_start );
check( 'A555: sales end moves with it (2 h before the start)', '2027-04-06 15:00:00' === $a555_t->sales_end );
check( 'A555: a type without window stays without window', null === a555_type( $a555['types']['Abendkasse'] )->sales_start && null === a555_type( $a555['types']['Abendkasse'] )->sales_end );
check( 'A555: the shift is in the audit log of the ticket type', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='ticket_type_updated' AND object_id=%d AND details LIKE %s", $a555['types']['Vorverkauf'], '%occurrence_rescheduled%' ) ) );

echo "A555: opting out keeps the windows\n";
$a555_keep = a555_occurrence( '2027-05-10 17:00:00', array( 'Standard' => array( '2027-05-01 08:00:00', '2027-05-10 16:00:00' ) ) );
EventsPage::save_occurrence( array( 'occurrence_id' => $a555_keep['occ'], 'timezone' => 'Europe/Vienna', 'starts_at' => '2027-05-17T19:00', 'ends_at' => '', 'location' => 'Halle', 'capacity' => 0, 'shift_sales_windows_shown' => '1' ) );
$a555_kt = a555_type( $a555_keep['types']['Standard'] );
check( 'A555: unticked box: windows unchanged', '2027-05-01 08:00:00' === $a555_kt->sales_start && '2027-05-10 16:00:00' === $a555_kt->sales_end );
EventsPage::save_occurrence( array( 'occurrence_id' => $a555_keep['occ'], 'timezone' => 'Europe/Vienna', 'starts_at' => '2027-05-24T19:00', 'ends_at' => '', 'location' => 'Halle', 'capacity' => 0, 'shift_sales_windows_shown' => '1', 'shift_sales_windows' => '1' ) );
$a555_kt = a555_type( $a555_keep['types']['Standard'] );
check( 'A555: ticked box: windows move by the week from the last start', '2027-05-08 08:00:00' === $a555_kt->sales_start && '2027-05-17 16:00:00' === $a555_kt->sales_end );

echo "A555: a window that would lie outside [now, end] is not moved\n";
$a555_soon = a555_occurrence( gmdate( 'Y-m-d H:i:00', time() + 3 * DAY_IN_SECONDS ), array( 'Frühbucher' => array( null, gmdate( 'Y-m-d H:i:00', time() + DAY_IN_SECONDS ) ), 'Normal' => array( null, null ) ) );
$a555_new_start = ( new DateTimeImmutable( '@' . ( time() - 6 * HOUR_IN_SECONDS ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
$a555_before = a555_type( $a555_soon['types']['Frühbucher'] );
$a555_res = EventsPage::save_occurrence( array( 'occurrence_id' => $a555_soon['occ'], 'timezone' => 'Europe/Vienna', 'starts_at' => $a555_new_start, 'ends_at' => '', 'location' => 'Halle', 'capacity' => 0 ) );
check( 'A555: the early-bird window (would have ended in the past) is kept unchanged', $a555_before->sales_end === a555_type( $a555_soon['types']['Frühbucher'] )->sales_end );
check( 'A555: the admin gets a warning naming the type', ! is_wp_error( $a555_res ) && false !== strpos( (string) ( $a555_res['warning'] ?? '' ), '„Frühbucher“' ) && false !== strpos( (string) $a555_res['warning'], 'nicht mitverschoben' ) );

echo "A555/A556: local wall-clock helpers\n";
check( 'local_shift: +7 local days across the spring change keeps 10:00', '2027-04-03 08:00:00' === Occurrence::local_shift( '2027-03-27 09:00:00', '2027-03-30 17:00:00', '2027-04-06 17:00:00', 'Europe/Vienna' ) );
check( 'local_shift: across the autumn change (New York) keeps 09:00', '2027-11-08 14:00:00' === Occurrence::local_shift( '2027-11-01 13:00:00', '2027-11-03 23:00:00', '2027-11-11 00:00:00', 'America/New_York' ) );
check( 'local_shift: no move, no change; null stays null', '2027-01-01 10:00:00' === Occurrence::local_shift( '2027-01-01 10:00:00', '2027-02-01 10:00:00', '2027-02-01 10:00:00', 'Europe/Vienna' ) && null === Occurrence::local_shift( null, '2027-02-01 10:00:00', '2027-02-08 10:00:00', 'Europe/Vienna' ) );
check( 'local_days_before: 14 days before 19:00 CEST is 19:00 CET', '2027-03-20 18:00:00' === Occurrence::local_days_before( '2027-04-03 17:00:00', 14, 'Europe/Vienna' ) );

echo "A556: series sales start N days before the start, across DST\n";
check( 'A556: offset in days = local days (19:00 CEST start, 14 days before = 19:00 CET)', '2027-03-20 18:00:00' === Generator::sales_time( '2027-04-03 17:00:00', 14, DAY_IN_SECONDS, null, null, 'Europe/Vienna' ) );
check( 'A556: offset in minutes stays an exact duration', '2027-04-03 15:00:00' === Generator::sales_time( '2027-04-03 17:00:00', 120, MINUTE_IN_SECONDS, null, null, 'Europe/Vienna' ) );
$a556_template = (object) array( 'starts_at' => '2027-03-30 17:00:00' );
check( 'A556: template-relative window keeps its local time of day (10:00 CET → 10:00 CEST)', '2027-04-03 08:00:00' === Generator::sales_time( '2027-04-06 17:00:00', null, DAY_IN_SECONDS, '2027-03-27 09:00:00', $a556_template, 'Europe/Vienna' ) );
check( 'A556: no offset and no template value: no window', null === Generator::sales_time( '2027-04-06 17:00:00', null, DAY_IN_SECONDS, null, $a556_template, 'Europe/Vienna' ) );
