<?php
/**
 * Integration tests for the reports module (src/Reports): revenue per dimension and VAT rate with
 * refunds netted, capacity, check-in rates/histogram, channel share, scoping, CSV and SVG output.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Service as Pos;
use PassCaptain\Orders;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Page as ReportsPage;
use PassCaptain\Reports\Scope;

PassCaptain\Modules\BoxOffice::register();
PassCaptain\Payments\Registry::reset();

global $wpdb;
$p = $wpdb->prefix;

echo "Reports: revenue with refunds netted\n";
$ra = make_type( 10, 10, 1190 );
$rocc = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ra" );
$revent = Scope::occurrence_event( $rocc );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, name='Normal' WHERE ticket_type_id=$ra" );
$wpdb->query( "UPDATE {$p}wpcap_events SET name='=Gala' WHERE event_id=$revent" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $rocc, 'name' => 'Kind', 'price_minor' => 1070, 'currency' => 'EUR', 'capacity' => 5, 'max_per_order' => 10, 'tax_rate_bp' => 700, 'status' => 'active' ) );
$rb = (int) $wpdb->insert_id;

$GLOBALS['wpcap_test_transients'] = array();
$o1 = Orders::create( array( 'email' => 'report@example.com', 'items' => array( array( 'ticket_type_id' => $ra, 'quantity' => 2 ), array( 'ticket_type_id' => $rb, 'quantity' => 1 ) ), 'idempotency_key' => 'wpr-1-' . uniqid() ) );
Orders::confirm_manual_payment( $o1['order_id'] );
$unpaid = Orders::create( array( 'email' => 'unpaid-report@example.com', 'items' => array( array( 'ticket_type_id' => $ra, 'quantity' => 1 ) ), 'idempotency_key' => 'wpr-2-' . uniqid() ) );
Pos::open_session( 0 );
$pos = Pos::sell( array( 'occurrence_id' => $rocc, 'quantities' => array( $ra => 1 ), 'method' => 'cash', 'form_key' => 'wpr-pos-' . bin2hex( random_bytes( 8 ) ) ) );
$refund = PassCaptain\Refunds::request( $o1['order_id'], array( (int) tickets( $o1['order_id'] )[0]->ticket_id ), 'Test', 'wpr-refund' );
check( 'fixture: refund of one 11,90 ticket completed with pro-rata VAT', 'completed' === $refund['status'] && 1190 === (int) $refund['amount_minor'] && 155 === (int) $refund['tax_minor'] && ! is_wp_error( $pos ) );

$today = gmdate( 'Y-m-d' );
$report = Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $revent ), null );
$t = $report['total'];
check( 'totals: 4 tickets, 46,40 gross, 6,40 VAT, 11,90 refunded, 34,50 after refunds', 4 === $t['tickets'] && 4640 === $t['gross'] && 640 === $t['tax'] && 4000 === $t['net'] && 1190 === $t['refunded'] && 1 === $t['refunded_tickets'] && 3450 === $t['revenue'] && 485 === $t['revenue_tax'] );
$r19 = $report['rates'][1900];
$r7 = $report['rates'][700];
check( 'VAT per rate with refund netted', 3570 === $r19['gross'] && 570 === $r19['tax'] && 3000 === $r19['net'] && 1190 === $r19['refunded'] && 155 === $r19['refunded_tax'] && 2380 === $r19['revenue'] && 1070 === $r7['gross'] && 70 === $r7['tax'] && 1070 === $r7['revenue'] );
$types = $report['dimensions']['ticket_type'];
check( 'per ticket type', 3 === $types[ $ra ]['tickets'] && 1 === $types[ $ra ]['refunded_tickets'] && 2380 === $types[ $ra ]['revenue'] && 1 === $types[ $rb ]['tickets'] );
$prov = $report['dimensions']['provider'];
check( 'per payment provider', 3450 === $prov['manual']['gross'] && 1190 === $prov['manual']['refunded'] && 1190 === $prov['pos_cash']['gross'] && 0 === $prov['pos_cash']['refunded'] );
check( 'per event, occurrence and day', 3450 === $report['dimensions']['event'][ $revent ]['revenue'] && 3450 === $report['dimensions']['occurrence'][ $rocc ]['revenue'] && 3450 === $report['dimensions']['day'][ $today ]['revenue'] && 1 === count( $report['dimensions']['event'] ) );
check( 'box office vs online share', 1190 === $report['channels']['pos']['revenue'] && 1 === $report['channels']['pos']['tickets'] && 2260 === $report['channels']['online']['revenue'] );
check( 'unpaid orders are not counted', ! isset( $report['dimensions']['provider'][''] ) && 4 === $t['tickets'] );
$yesterday = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
$empty = Data::build( array( 'from' => $yesterday, 'to' => $yesterday, 'event_id' => $revent ), null );
check( 'date range filter excludes other days', 0 === $empty['total']['tickets'] && 0 === $empty['total']['refunded'] );
$lines = array( (object) array( 'line_total_minor' => 1000 ), (object) array( 'line_total_minor' => 500 ), (object) array( 'line_total_minor' => 1 ) );
check( 'discount allocation matches checkout (remainder on the last line)', array( 667, 334, 0 ) === Data::allocate_discount( $lines, 500 ) && 1001 === array_sum( Data::allocate_discount( $lines, 500 ) ) );

echo "Reports: capacity and check-in\n";
$occ = $report['occurrences'][ $rocc ];
check( 'sold vs capacity (type capacities 10 + 5)', 15 === $occ['capacity'] && 3 === $occ['sold'] && 20.0 === $occ['utilisation'] );
$pos_ticket = tickets( $pos['sale']->order_id )[0];
$o1_valid = tickets( $o1['order_id'], 'valid' );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id IN (" . (int) $pos_ticket->ticket_id . ',' . (int) $o1_valid[0]->ticket_id . ')' );
$day = gmdate( 'Y-m-d' );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $pos_ticket->ticket_id, 'event_id' => $revent, 'user_id' => 1, 'result' => 'checked_in', 'created_at' => "$day 17:10:00" ) );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $pos_ticket->ticket_id, 'event_id' => $revent, 'user_id' => 1, 'result' => 'already_checked_in', 'created_at' => "$day 17:20:00" ) );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $o1_valid[0]->ticket_id, 'event_id' => $revent, 'user_id' => 1, 'result' => 'checked_in', 'scanned_at' => "$day 19:05:00", 'created_at' => "$day 19:30:00" ) );
$report = Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $revent, 'occurrence_id' => $rocc ), null );
$occ = $report['occurrences'][ $rocc ];
check( 'check-in rate per occurrence (2 of 3 valid tickets)', 3 === $occ['issued'] && 2 === $occ['checked_in'] && 66.7 === $occ['checkin_rate'] );
$expected_hist = array( "$day 18:00" => 1, "$day 19:00" => 0, "$day 20:00" => 1 );
check( 'check-ins per hour in occurrence time (Vienna, empty hours filled, failed scans ignored)', $expected_hist === $report['histogram'] );

echo "Reports: scope, CSV and charts\n";
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance' );
$scope_none = Data::scope();
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $revent, 'user_id' => 1, 'capability' => 'wpcap_view_reports' ) );
$scope_one = Data::scope();
$scoped = Data::build( array( 'from' => $today, 'to' => $today ), $scope_one );
$foreign = Data::build( array( 'from' => $today, 'to' => $today, 'event_id' => $revent + 100000 ), $scope_one );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE event_id=$revent AND user_id=1" );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_view_finance', 'wpcap_view_reports' );
$scope_false = Data::scope();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'finance/admin see all events, report viewers only assigned events', null === Data::scope() && array() === $scope_none && array( $revent ) === $scope_one && false === $scope_false );
check( 'scoped report only contains the assigned event', array( $revent ) === array_keys( $scoped['dimensions']['event'] ) && 3450 === $scoped['total']['revenue'] && 0 === $foreign['total']['tickets'] && ! $foreign['occurrences'] );
$csv = Scope::csv( Data::csv_rows( $report ) );
check( 'CSV export contains sections and is injection safe', false !== strpos( $csv, "'=Gala" ) && false === strpos( $csv, ';=Gala' ) && false !== strpos( $csv, 'USt-Satz' ) && false !== strpos( $csv, '34.50' ) && false !== strpos( $csv, 'Check-ins pro Stunde' ) );
$svg = ReportsPage::bar_chart( array( '<b>A</b>' => 10, 'B' => 5 ), 'T<script>' );
check( 'SVG bar chart: escaped labels and proportional bars', str_starts_with( $svg, '<svg' ) && false === strpos( $svg, '<script>' ) && false !== strpos( $svg, '&lt;b&gt;A&lt;/b&gt;' ) && false !== strpos( $svg, 'width="420"' ) && false !== strpos( $svg, 'width="210"' ) );
check( 'invalid dates fall back to defaults', Data::valid_date( '2026-02-28' ) && ! Data::valid_date( '2026-02-30' ) && ! Data::valid_date( "2026-01-01' OR 1" ) );
