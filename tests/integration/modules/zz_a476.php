<?php
/**
 * A476: the event detail page shows one page of occurrences (the page with the next date by
 * default) with sold units from one GROUP BY query; occurrence selects and per-date forms
 * (assignments, admission rules, offline scanner, series template, seating, guest lists) use a
 * bounded window with a "dates from" filter. Checked with a daily series of 130 dates.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Queries;
use PassCaptain\Admin\Forms;
use PassCaptain\Orders;

require_once __DIR__ . '/zz_r6_stubs.inc';

global $wpdb;
$p = $wpdb->prefix;

echo "A476: long series on the event page and in occurrence selects\n";
$a476_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A476 Serie', 'slug' => 'a476-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a476_now, 'updated_at' => $a476_now ) );
$a476_event = (int) $wpdb->insert_id;
$a476_occ = array();
for ( $i = -60; $i < 70; $i++ ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a476_event, 'starts_at' => gmdate( 'Y-m-d 18:00:00', time() + $i * DAY_IN_SECONDS + ( $i >= 0 ? DAY_IN_SECONDS : 0 ) ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 100, 'status' => 'scheduled' ) );
	$a476_occ[] = (int) $wpdb->insert_id;
}
// Sold units on two dates of the second page.
$a476_sold_occ = array( $a476_occ[61], $a476_occ[62] );
foreach ( $a476_sold_occ as $k => $occ_id ) {
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ_id, 'name' => 'Standard', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
	$GLOBALS['wpcap_test_transients'] = array();
	Orders::create( array( 'email' => "a476-$k@example.com", 'items' => array( array( 'ticket_type_id' => (int) $wpdb->insert_id, 'quantity' => 2 + $k ) ), 'idempotency_key' => 'a476-' . $k . uniqid() ) );
}

$a476_page = Queries::occurrence_page( $a476_event );
check( 'A476: 130 dates in 3 pages of 50; the default page holds the next date', 130 === $a476_page['total'] && 3 === $a476_page['pages'] && 2 === $a476_page['page'] && 50 === count( $a476_page['rows'] ) && in_array( $a476_occ[60], array_map( static fn( $o ) => (int) $o->occurrence_id, $a476_page['rows'] ), true ) );
$a476_p3 = Queries::occurrence_page( $a476_event, 3 );
check( 'A476: last page has the remaining 30 dates in order', 30 === count( $a476_p3['rows'] ) && $a476_occ[100] === (int) $a476_p3['rows'][0]->occurrence_id && $a476_occ[129] === (int) end( $a476_p3['rows'] )->occurrence_id );
check( 'A476: a page number beyond the end shows the last page', 3 === Queries::occurrence_page( $a476_event, 99 )['page'] );

$GLOBALS['wpcap_test_query_log'] = array();
$a476_sold = Queries::sold_for_occurrences( array_map( static fn( $o ) => (int) $o->occurrence_id, $a476_page['rows'] ) );
$a476_log = $GLOBALS['wpcap_test_query_log'];
unset( $GLOBALS['wpcap_test_query_log'] );
check( 'A476: sold units of a page with one query, same numbers as per occurrence', 1 === count( $a476_log ) && 2 === $a476_sold[ $a476_sold_occ[0] ] && 3 === $a476_sold[ $a476_sold_occ[1] ] && 0 === $a476_sold[ $a476_occ[60] ] && Queries::sold_for_occurrence( $a476_sold_occ[1] ) === $a476_sold[ $a476_sold_occ[1] ] );

// Event detail page: 50 rows, pagination, no query per row.
$_GET = array( 'page' => 'pass-captain', 'view' => 'event', 'event_id' => $a476_event );
$GLOBALS['wpcap_test_query_log'] = array();
ob_start();
try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a476_html = ob_get_clean();
$a476_render_log = $GLOBALS['wpcap_test_query_log'];
unset( $GLOBALS['wpcap_test_query_log'] );
$_GET = array();
$a476_rows = substr_count( $a476_html, 'view=cancel_occurrence' );
$a476_per_row = count( array_filter( $a476_render_log, static fn( $q ) => false !== strpos( $q, 'SUM(oi.quantity - oi.refunded_quantity)' ) ) );
check( 'A476: event page renders one page of dates (50) with pagination', false === strpos( $a476_html, 'EXCEPTION' ) && 50 === $a476_rows && false !== strpos( $a476_html, 'Seite 2 von 3' ) );
check( 'A476: sold units on the event page come from one query, not one per date', 1 === $a476_per_row );
$_GET = array( 'page' => 'pass-captain', 'view' => 'event', 'event_id' => $a476_event, 'paged' => 3 );
ob_start();
try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a476_html3 = ob_get_clean();
$_GET = array();
check( 'A476: ?paged=3 shows the last 30 dates', 30 === substr_count( $a476_html3, 'view=cancel_occurrence' ) && false !== strpos( $a476_html3, 'Seite 3 von 3' ) );

// Selects: bounded window, next dates first, assigned dates kept, date filter.
$GLOBALS['wpcap_test_filters']['wpcap_admin_occurrence_choices'] = 20;
$a476_win = Queries::occurrence_window( $a476_event, array( $a476_occ[5] ) );
$a476_ids = array_map( static fn( $o ) => (int) $o->occurrence_id, $a476_win['rows'] );
check( 'A476: window holds the next 20 dates plus a kept older one, in date order', 21 === count( $a476_ids ) && $a476_occ[60] === $a476_ids[1] && $a476_occ[5] === $a476_ids[0] && $a476_occ[79] === end( $a476_ids ) && $a476_win['more'] && 130 === $a476_win['total'] );
$a476_from = gmdate( 'Y-m-d', strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a476_occ[100] ) ) . ' UTC' ) );
$a476_win2 = Queries::occurrence_window( $a476_event, array(), $a476_from );
check( 'A476: "dates from" moves the window', $a476_occ[100] === (int) $a476_win2['rows'][0]->occurrence_id && $a476_from === $a476_win2['from'] );
$a476_end = Queries::occurrence_window( $a476_event, array(), gmdate( 'Y-m-d', time() + 400 * DAY_IN_SECONDS ) );
check( 'A476: past the last date the window is filled with the latest dates', 20 === count( $a476_end['rows'] ) && $a476_occ[129] === (int) end( $a476_end['rows'] )->occurrence_id );
ob_start();
Forms::occurrence_window_filter( $a476_win, 'wpcap-assignments', array( 'event_id' => $a476_event ) );
$a476_filter = ob_get_clean();
check( 'A476: the filter names the shown/total dates and offers "Termine ab"', false !== strpos( $a476_filter, '21 von 130 Terminen' ) && false !== strpos( $a476_filter, 'name="occ_from"' ) && false !== strpos( $a476_filter, 'name="event_id" value="' . $a476_event . '"' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_admin_occurrence_choices'] );
$a476_small = Queries::occurrence_window( $a476_event );
ob_start();
Forms::occurrence_window_filter( array( 'rows' => array(), 'total' => 0, 'more' => false, 'from' => '' ), 'x' );
check( 'A476: complete short lists get no filter note', '' === ob_get_clean() && 130 === count( $a476_small['rows'] ) && ! $a476_small['more'] );
$a476_src = '';
foreach ( array( 'src/Admin/AssignmentsPage.php', 'src/Admission/RulesPage.php', 'src/OfflineScanner/AdminPage.php', 'src/Series/AdminPage.php', 'src/Seating/AdminPage.php', 'src/GuestLists/Page.php', 'src/Admin/CheckinsPage.php' ) as $f ) { $a476_src .= file_get_contents( dirname( __DIR__, 3 ) . '/' . $f ); }
check( 'A476: no admin page lists every date of an event any more', false === strpos( $a476_src, 'Queries::occurrences_for_event(' ) );
