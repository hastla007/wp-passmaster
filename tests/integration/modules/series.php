<?php
/**
 * Integration tests for recurring occurrence series. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Series;
use PassCaptain\Series\Generator;
use PassCaptain\Series\Rules;

Series::register();
global $wpdb;
$p = $wpdb->prefix;

function series_expand( array $rule ) {
	$normalized = Rules::normalize( $rule );
	return is_wp_error( $normalized ) ? $normalized : Rules::expand( $normalized );
}
function series_starts( $items ) { return is_wp_error( $items ) ? $items->get_error_code() : array_map( static fn( $i ) => $i['starts_at'], $items ); }
function series_dates( $items ) { return is_wp_error( $items ) ? $items->get_error_code() : array_map( static fn( $i ) => $i['date'], $items ); }

echo "Series: recurrence rules and DST (Europe/Vienna)\n";
$base = array( 'timezone' => 'Europe/Vienna', 'time' => '19:00', 'duration_minutes' => 120 );
$spring = series_expand( $base + array( 'freq' => 'weekly', 'weekdays' => array( 6 ), 'start_date' => '2027-03-20', 'count' => 3 ) );
check( 'weekly 19:00 keeps local time across spring DST (UTC 18:00 → 17:00)', array( '2027-03-20 18:00:00', '2027-03-27 18:00:00', '2027-04-03 17:00:00' ) === series_starts( $spring ) );
check( 'end time is start + duration', '2027-04-03 19:00:00' === $spring[2]['ends_at'] && '+02:00' === $spring[2]['offset'] );
$autumn = series_expand( $base + array( 'freq' => 'weekly', 'weekdays' => array( 7 ), 'start_date' => '2027-10-24', 'until' => '2027-11-07' ) );
check( 'weekly across autumn DST (UTC 17:00 → 18:00)', array( '2027-10-24 17:00:00', '2027-10-31 18:00:00', '2027-11-07 18:00:00' ) === series_starts( $autumn ) );
$gap = series_expand( array( 'timezone' => 'Europe/Vienna', 'time' => '02:30', 'freq' => 'daily', 'start_date' => '2027-03-27', 'count' => 3 ) );
check( 'time in the DST gap is shifted forward and flagged', '2027-03-28 03:30' === $gap[1]['local'] && $gap[1]['shifted'] && ! $gap[0]['shifted'] && '2027-03-28 01:30:00' === $gap[1]['starts_at'] );
$ambiguous = series_expand( array( 'timezone' => 'Europe/Vienna', 'time' => '02:30', 'freq' => 'dates', 'dates' => "31.10.2027" ) );
check( 'ambiguous fall-back time uses the first (summer time) instance', '2027-10-31 00:30:00' === $ambiguous[0]['starts_at'] && '+02:00' === $ambiguous[0]['offset'] );
$multi = series_expand( $base + array( 'freq' => 'weekly', 'weekdays' => array( 2, 4 ), 'start_date' => '2027-01-01', 'count' => 4 ) );
check( 'weekly on several weekdays', array( '2027-01-05', '2027-01-07', '2027-01-12', '2027-01-14' ) === series_dates( $multi ) );
$biweekly = series_expand( $base + array( 'freq' => 'weekly', 'interval' => 2, 'weekdays' => array( 1 ), 'start_date' => '2027-01-04', 'count' => 3 ) );
check( 'every second week', array( '2027-01-04', '2027-01-18', '2027-02-01' ) === series_dates( $biweekly ) );
$daily = series_expand( $base + array( 'freq' => 'daily', 'interval' => 3, 'start_date' => '2027-01-30', 'until' => '2027-02-08' ) );
check( 'every third day until end date (inclusive)', array( '2027-01-30', '2027-02-02', '2027-02-05', '2027-02-08' ) === series_dates( $daily ) );

echo "Series: monthly rules and exclusions\n";
$last_friday = series_expand( $base + array( 'freq' => 'monthly_nth', 'nth' => -1, 'nth_weekday' => 5, 'start_date' => '2027-01-01', 'count' => 4 ) );
check( 'monthly last Friday', array( '2027-01-29', '2027-02-26', '2027-03-26', '2027-04-30' ) === series_dates( $last_friday ) );
$second_tue = series_expand( $base + array( 'freq' => 'monthly_nth', 'nth' => 2, 'nth_weekday' => 2, 'start_date' => '2027-01-01', 'count' => 3 ) );
check( 'monthly second Tuesday', array( '2027-01-12', '2027-02-09', '2027-03-09' ) === series_dates( $second_tue ) );
$fifth = series_expand( $base + array( 'freq' => 'monthly_nth', 'nth' => 5, 'nth_weekday' => 1, 'start_date' => '2027-01-01', 'until' => '2027-06-30' ) );
check( 'fifth Monday only in months that have one', array( '2027-03-29', '2027-05-31' ) === series_dates( $fifth ) );
$day31 = series_expand( $base + array( 'freq' => 'monthly_day', 'month_day' => 31, 'start_date' => '2027-01-01', 'count' => 3 ) );
check( 'monthly on the 31st skips shorter months', array( '2027-01-31', '2027-03-31', '2027-05-31' ) === series_dates( $day31 ) );
$excl = series_expand( $base + array( 'freq' => 'weekly', 'weekdays' => array( 3 ), 'start_date' => '2027-12-01', 'count' => 4, 'exclusions' => "22.12.2027\n2027-12-29" ) );
check( 'exclusions are skipped and do not count', array( '2027-12-01', '2027-12-08', '2027-12-15', '2028-01-05' ) === series_dates( $excl ) );
$list = series_expand( $base + array( 'freq' => 'dates', 'dates' => "2027-05-03, 01.05.2027\n2027-05-03", 'exclusions' => '2027-05-03' ) );
check( 'explicit date list is sorted, deduplicated and honours exclusions', array( '2027-05-01' ) === series_dates( $list ) );
check( 'more than 200 occurrences are rejected', 'wpcap_series_too_many' === series_starts( series_expand( $base + array( 'freq' => 'daily', 'start_date' => '2027-01-01', 'until' => '2027-12-31' ) ) ) );
check( 'count above 200 is rejected', 'wpcap_series_too_many' === series_starts( series_expand( $base + array( 'freq' => 'daily', 'start_date' => '2027-01-01', 'count' => 201 ) ) ) );
check( 'missing end is rejected', 'wpcap_series_end' === series_starts( series_expand( $base + array( 'freq' => 'daily', 'start_date' => '2027-01-01' ) ) ) );
check( 'invalid dates are rejected', 'wpcap_series_invalid_date' === series_starts( series_expand( $base + array( 'freq' => 'dates', 'dates' => '31.02.2027' ) ) ) );
check( 'invalid timezone is rejected', 'wpcap_series_timezone' === series_starts( series_expand( array( 'freq' => 'daily', 'timezone' => 'Mars/Base', 'time' => '19:00', 'start_date' => '2027-01-01', 'count' => 1 ) ) ) );

echo "Series: generation, ticket type copies, idempotency\n";
$tpl_type = make_type( 50, 6, 4500 );
$tpl_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$tpl_type" );
$series_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$tpl_occ" );
// Template: starts 2027-01-10 18:00 UTC, sales from 30 days before until 2 h before start.
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='2027-01-10 18:00:00',capacity=80,location='Stadtsaal' WHERE occurrence_id=$tpl_occ" );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1300,tax_class='reduced',sales_start='2026-12-11 18:00:00',sales_end='2027-01-10 16:00:00' WHERE ticket_type_id=$tpl_type" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $tpl_occ, 'name' => 'Ermäßigt', 'price_minor' => 2500, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 2, 'tax_rate_bp' => 1300, 'status' => 'active' ) );
$tpl_type2 = (int) $wpdb->insert_id;

$input = array( 'event_id' => $series_event, 'template_occurrence_id' => $tpl_occ, 'freq' => 'weekly', 'weekdays' => array( 6 ), 'start_date' => '2027-03-20', 'count' => 4, 'time' => '19:00', 'duration_minutes' => 90, 'timezone' => 'Europe/Vienna', 'name' => 'Frühjahr' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = Generator::create( $input );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'generation requires the event capability', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$other_type = make_type( 5 );
$other_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$other_type" );
check( 'template of another event is rejected', is_wp_error( Generator::preview( array( 'template_occurrence_id' => $other_occ ) + $input ) ) );

$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=$series_event" );
$preview = Generator::preview( $input );
check( 'preview lists 4 new occurrences and writes nothing', ! is_wp_error( $preview ) && 4 === count( $preview['items'] ) && ! array_filter( $preview['items'], static fn( $i ) => $i['exists'] ) && $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=$series_event" ) );
$run1 = Generator::create( $input );
check( 'creation adds 4 occurrences', ! is_wp_error( $run1 ) && 4 === count( $run1['created'] ) && 0 === $run1['skipped_existing'] );
$occs = $wpdb->get_results( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id IN (" . implode( ',', $run1['created'] ) . ') ORDER BY starts_at' );
check( 'occurrences get UTC starts across DST, duration, timezone, template location and capacity', '2027-03-20 18:00:00' === $occs[0]->starts_at && '2027-04-03 17:00:00' === $occs[2]->starts_at && '2027-04-03 18:30:00' === $occs[2]->ends_at && 'Europe/Vienna' === $occs[2]->timezone && 'Stadtsaal' === $occs[0]->location && 80 === (int) $occs[0]->capacity );
$copied = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id", $occs[2]->occurrence_id ) );
check( 'ticket types are copied with price, VAT, capacity and limits', 2 === count( $copied ) && 4500 === (int) $copied[0]->price_minor && 1300 === (int) $copied[0]->tax_rate_bp && 'reduced' === $copied[0]->tax_class && 50 === (int) $copied[0]->capacity && 6 === (int) $copied[0]->max_per_order && 'Ermäßigt' === $copied[1]->name && 2 === (int) $copied[1]->max_per_order );
check( 'sales window is relative to the new start (30 days / 2 h before)', '2027-04-03 15:00:00' === $copied[0]->sales_end && '2027-03-04 17:00:00' === $copied[0]->sales_start && null === $copied[1]->sales_end );
check( 'series definition and occurrence links are stored', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_series WHERE event_id=$series_event" ) && 4 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_series_occurrences WHERE series_id=" . (int) $run1['series_id'] ) );

$run2 = Generator::create( $input );
check( 're-running creates nothing and reuses the series', ! is_wp_error( $run2 ) && 0 === count( $run2['created'] ) && 4 === $run2['skipped_existing'] && $run2['series_id'] === $run1['series_id'] );
$preview2 = Generator::preview( $input );
check( 'preview marks existing start times', 4 === count( array_filter( $preview2['items'], static fn( $i ) => $i['exists'] ) ) );
$extended = Generator::create( array( 'count' => 6, 'sales_end_minutes_before' => '60' ) + $input );
check( 'extending the series only adds the missing occurrences', ! is_wp_error( $extended ) && 2 === count( $extended['created'] ) && 4 === $extended['skipped_existing'] );
$ext_type = $wpdb->get_row( $wpdb->prepare( "SELECT tt.sales_end,o.starts_at FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.occurrence_id=%d ORDER BY tt.ticket_type_id LIMIT 1", $extended['created'][0] ) );
check( 'explicit "sales end 60 minutes before start" overrides the template', gmdate( 'Y-m-d H:i:s', strtotime( $ext_type->starts_at . ' UTC' ) - 3600 ) === $ext_type->sales_end );
$past = Generator::create( array( 'freq' => 'dates', 'dates' => "2020-01-01\n2027-06-01", 'count' => '' ) + $input );
check( 'past dates are skipped', ! is_wp_error( $past ) && 1 === $past['skipped_past'] && 1 === count( $past['created'] ) );
$bad_sales = Generator::preview( array( 'sales_end_minutes_before' => '2h' ) + $input );
check( 'invalid sales offset is rejected', is_wp_error( $bad_sales ) );

if ( class_exists( 'PassCaptain\\Seating\\Layouts' ) && function_exists( 'seating_draft' ) ) {
	echo "Series: seat-map link\n";
	$layout = PassCaptain\Seating\Layouts::create( $series_event, 'Stadtsaal' );
	PassCaptain\Seating\Layouts::save_draft( $layout['layout_id'], seating_draft() );
	$version = PassCaptain\Seating\Layouts::publish( $layout['layout_id'] );
	PassCaptain\Seating\Layouts::link_occurrence( $tpl_occ, $version['version_id'], array( $tpl_type => array( 'parkett' ), $tpl_type2 => array( 'balkon' ) ) );
	$seated = Generator::create( array( 'freq' => 'dates', 'dates' => '2027-09-04', 'count' => '', 'seat_version_id' => $version['version_id'] ) + $input );
	$new_occ = $seated['created'][0] ?? 0;
	$new_types = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id", $new_occ ) );
	check( 'generated occurrence is linked to the seat map with the template zone mapping', 1 === $seated['seat_links'] && array( (int) $new_types[0] => array( 'parkett' ), (int) $new_types[1] => array( 'balkon' ) ) === PassCaptain\Seating\Layouts::type_zones( $new_occ ) );
}
