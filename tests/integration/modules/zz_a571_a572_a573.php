<?php
/**
 * A571–A573: check-in histogram from the admission start without taken-back scans and without a
 * 72-hour cut-off; the report's check-in rate uses the no-show list's "admitted" rule; holders of
 * passes appear in lists and figures of their linked occurrences (not on the anchor occurrence).
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Passes\Definitions;
use PassCaptain\Printing\AttendeeList;
use PassCaptain\Printing\NoShowList;
use PassCaptain\Printing\Templates;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Scope;

echo "A571–A573: histogram, check-in rate and pass holders in occurrence figures\n";
global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Modules\Passes::register();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$a57x_settings = array_intersect_key( PassCaptain\Settings::all(), array_flip( array( 'default_entry_window', 'entry_opens_before_minutes' ) ) );
PassCaptain\Settings::update( array( 'default_entry_window' => 0 ) );

$a57x_log = static function ( $ticket_id, $event_id, $ts, $result = 'checked_in' ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $ticket_id, 'event_id' => $event_id, 'user_id' => 1, 'result' => $result, 'source' => 'web', 'scanned_at' => gmdate( 'Y-m-d H:i:s', $ts ), 'created_at' => gmdate( 'Y-m-d H:i:s', $ts ) ) );
};

// --- A571/A572: ordinary occurrence, a test scan days before that was taken back ---
$a57x_type = make_type( 50, 10, 0 );
$a57x_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a57x_type" );
$a57x_event = Scope::occurrence_event( $a57x_occ );
$a57x_base = (int) ( floor( ( time() + 10 * 86400 ) / 3600 ) * 3600 ) + 1200; // xx:20 UTC, in 10 days.
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a57x_base - 2 * 3600 - 1200 ), 'timezone' => 'Europe/Vienna' ), array( 'occurrence_id' => $a57x_occ ) );
$a57x_o = Orders::create( array( 'email' => 'a571@example.com', 'items' => array( array( 'ticket_type_id' => $a57x_type, 'quantity' => 4 ) ), 'idempotency_key' => 'a571-' . uniqid() ) );
$a57x_t = array_map( static function ( $t ) { return (int) $t->ticket_id; }, tickets( $a57x_o['order_id'] ) );
$a57x_log( $a57x_t[0], $a57x_event, $a57x_base - 5 * 86400 );            // test scan at set-up …
$a57x_log( $a57x_t[0], $a57x_event, $a57x_base - 5 * 86400 + 60, 'undone' ); // … taken back
$a57x_log( $a57x_t[1], $a57x_event, $a57x_base );
$a57x_log( $a57x_t[2], $a57x_event, $a57x_base + 600 );
$a57x_log( $a57x_t[2], $a57x_event, $a57x_base + 3 * 3600 );             // re-entry (repeatable) 3 h later
$a57x_log( $a57x_t[3], $a57x_event, $a57x_base + 3600 );
$a57x_log( $a57x_t[3], $a57x_event, $a57x_base + 3660, 'undone' );        // admitted by mistake, taken back

$a57x_hist = Data::checkin_histogram( $a57x_occ );
$a57x_h = static function ( $ts ) { return Scope::local( gmdate( 'Y-m-d H:i:s', $ts ), 'Europe/Vienna', 'Y-m-d H:00' ); };
$a57x_expected = array(
	$a57x_h( $a57x_base - 2 * 3600 ) => 0, // admission start (= start, no doors time)
	$a57x_h( $a57x_base - 3600 ) => 0,
	$a57x_h( $a57x_base ) => 2,
	$a57x_h( $a57x_base + 3600 ) => 0,      // the taken-back entry is gone
	$a57x_h( $a57x_base + 2 * 3600 ) => 0,
	$a57x_h( $a57x_base + 3 * 3600 ) => 1,
);
check( 'A571: histogram starts at the admission start, taken-back scans (also the test scan days before) are not counted', $a57x_expected === $a57x_hist );

// No 72-hour cut-off: a real scan days later is still counted, a long gap is not filled.
$a57x_log( $a57x_t[1], $a57x_event, $a57x_base + 4 * 86400 );
$a57x_hist2 = Data::checkin_histogram( $a57x_occ );
check( 'A571: entries more than 72 h after the first one are kept (long gaps not filled)', 4 === array_sum( $a57x_hist2 ) && isset( $a57x_hist2[ $a57x_h( $a57x_base + 4 * 86400 ) ] ) && count( $a57x_hist2 ) < 20 );
$wpdb->query( "DELETE FROM {$p}wpcap_checkins WHERE ticket_id=" . $a57x_t[1] . " AND scanned_at='" . gmdate( 'Y-m-d H:i:s', $a57x_base + 4 * 86400 ) . "'" );

// Doors-open time moves the axis start.
if ( PassCaptain\DoorsOpen::ready() ) {
	$wpdb->update( "{$p}wpcap_occurrences", array( 'doors_open_at' => gmdate( 'Y-m-d H:i:s', $a57x_base - 3600 ) ), array( 'occurrence_id' => $a57x_occ ) );
	$a57x_hist3 = Data::checkin_histogram( $a57x_occ );
	check( 'A571: with a doors-open time the axis starts there', $a57x_h( $a57x_base - 3600 ) === array_key_first( $a57x_hist3 ) );
	$wpdb->update( "{$p}wpcap_occurrences", array( 'doors_open_at' => null ), array( 'occurrence_id' => $a57x_occ ) );
}

// A572: report rate and no-show list agree.
$a57x_from = gmdate( 'Y-m-d', $a57x_base - 86400 );
$a57x_to = gmdate( 'Y-m-d', $a57x_base + 86400 );
$a57x_stats = Data::occurrence_stats( array( $a57x_occ ), $a57x_from . ' 00:00:00', $a57x_to . ' 23:59:59', null )[ $a57x_occ ] ?? array();
$a57x_noshow = array_map( static function ( $t ) { return (int) $t->ticket_id; }, NoShowList::tickets( $a57x_occ ) );
sort( $a57x_noshow );
$a57x_expected_noshow = array( $a57x_t[0], $a57x_t[3] );
sort( $a57x_expected_noshow );
check( 'A572: check-in rate counts only tickets that are still admitted (2 of 4, 50 %)', 4 === ( $a57x_stats['issued'] ?? 0 ) && 2 === ( $a57x_stats['checked_in'] ?? 0 ) && 50.0 === ( $a57x_stats['checkin_rate'] ?? null ) );
check( 'A572: the no-show list lists exactly the other tickets (issued − checked in)', $a57x_expected_noshow === $a57x_noshow && count( $a57x_noshow ) === $a57x_stats['issued'] - $a57x_stats['checked_in'] );

// --- A573: passes on linked occurrences ---
$a573_ev = (int) $wpdb->get_var( 'SELECT 1' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A573 Festival', 'slug' => 'a573' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a573_ev = (int) $wpdb->insert_id;
$a573_occ = array();
foreach ( array( 0, 1, 2 ) as $d ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a573_ev, 'starts_at' => gmdate( 'Y-m-d H:i:s', $a57x_base + $d * 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $a57x_base + $d * 86400 + 8 * 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Gelände', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a573_occ[ $d ] = (int) $wpdb->insert_id;
}
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a573_occ[1], 'name' => 'Tagesticket', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
$a573_day = (int) $wpdb->insert_id;
// Pass sold on day 0 (anchor), valid on days 1 and 2 only.
$a573_pass = Definitions::create( array( 'occurrence_id' => $a573_occ[0], 'name' => 'Wochenendpass', 'price_minor' => 0, 'occurrence_ids' => array( $a573_occ[1], $a573_occ[2] ) ) );
check( 'A573 fixture: pass created', is_int( $a573_pass ) && $a573_pass > 0 );
$GLOBALS['wpcap_test_transients'] = array();
$a573_o = Orders::create( array( 'email' => 'a573@example.com', 'items' => array( array( 'ticket_type_id' => $a573_pass, 'quantity' => 1 ), array( 'ticket_type_id' => $a573_day, 'quantity' => 1 ) ), 'idempotency_key' => 'a573-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$a573_pass_tid = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) ( $a573_o['order_id'] ?? 0 ) . " AND ticket_type_id=$a573_pass" );
$a573_day_tid = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) ( $a573_o['order_id'] ?? 0 ) . " AND ticket_type_id=$a573_day" );
check( 'A573 fixture: pass and day ticket issued', $a573_pass_tid > 0 && $a573_day_tid > 0 );
// The pass holder was admitted on day 1 (admission entry with occurrence, log row with event).
$a57x_log( $a573_pass_tid, $a573_ev, $a57x_base + 86400 + 300 );
$a573_checkin = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_admission_entries", array( 'ticket_id' => $a573_pass_tid, 'occurrence_id' => $a573_occ[1], 'area_id' => 0, 'service_date' => gmdate( 'Y-m-d', $a57x_base + 86400 ), 'direction' => 'in', 'checkin_id' => $a573_checkin, 'undone' => 0, 'entered_at' => gmdate( 'Y-m-d H:i:s', $a57x_base + 86400 + 300 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );

$a573_ids = static function ( array $rows ) { return array_map( static function ( $t ) { return (int) $t->ticket_id; }, $rows ); };
check( 'A573: the attendee list of a linked occurrence contains the pass holder', in_array( $a573_pass_tid, $a573_ids( Templates::tickets_for_occurrence( $a573_occ[1] ) ), true ) && in_array( $a573_pass_tid, $a573_ids( Templates::tickets_for_occurrence( $a573_occ[2] ) ), true ) );
check( 'A573: the anchor occurrence (not linked) does not list the pass', ! in_array( $a573_pass_tid, $a573_ids( Templates::tickets_for_occurrence( $a573_occ[0] ) ), true ) && ! in_array( $a573_pass_tid, $a573_ids( NoShowList::tickets( $a573_occ[0] ) ), true ) );
check( 'A573: no-shows per linked occurrence (admitted on day 1, absent on day 2)', array( $a573_day_tid ) === $a573_ids( NoShowList::tickets( $a573_occ[1] ) ) && array( $a573_pass_tid ) === $a573_ids( NoShowList::tickets( $a573_occ[2] ) ) );
$a573_stats = Data::occurrence_stats( $a573_occ, gmdate( 'Y-m-d H:i:s', $a57x_base - 86400 ), gmdate( 'Y-m-d H:i:s', $a57x_base + 4 * 86400 ), null );
check( 'A573: report figures per linked occurrence (day 0: 0, day 1: 2 valid / 1 admitted, day 2: 1 / 0)', 0 === ( $a573_stats[ $a573_occ[0] ]['issued'] ?? -1 ) && 2 === ( $a573_stats[ $a573_occ[1] ]['issued'] ?? 0 ) && 1 === ( $a573_stats[ $a573_occ[1] ]['checked_in'] ?? 0 ) && 1 === ( $a573_stats[ $a573_occ[2] ]['issued'] ?? 0 ) && 0 === ( $a573_stats[ $a573_occ[2] ]['checked_in'] ?? -1 ) );
check( 'A573: the histogram of day 1 counts the pass entry, day 0 nothing', 1 === array_sum( Data::checkin_histogram( $a573_occ[1] ) ) && array() === Data::checkin_histogram( $a573_occ[0] ) );
$a573_basic = AttendeeList::rows( $a573_occ[1], 'basic' );
$a573_status = array_column( array_slice( $a573_basic, 1 ), 2, 1 );
check( 'A573: attendee list shows the pass as checked in on day 1 and the day ticket as valid', 3 === count( $a573_basic ) && in_array( 'Eingecheckt', array_column( array_slice( $a573_basic, 1 ), 2 ), true ) && in_array( 'Gültig', array_column( array_slice( $a573_basic, 1 ), 2 ), true ) );
// An undone pass entry no longer counts.
$wpdb->update( "{$p}wpcap_admission_entries", array( 'undone' => 1 ), array( 'ticket_id' => $a573_pass_tid ) );
check( 'A573: an undone pass entry makes the holder a no-show again', in_array( $a573_pass_tid, $a573_ids( NoShowList::tickets( $a573_occ[1] ) ), true ) );

PassCaptain\Settings::update( $a57x_settings );
