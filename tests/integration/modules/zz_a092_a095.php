<?php
/**
 * Worklist items A092–A095: doors-open time per occurrence, capacity per entrance area, ID/age
 * check per ticket type with scanner hint, no-show list per occurrence.
 * Included from run.php after admission.php (uses adm_setup(), adm_in(), adm_out(), adm_ts()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admission\Areas;
use PassCaptain\Admission\RulesPage;
use PassCaptain\Admission\Policies;
use PassCaptain\Checkin;
use PassCaptain\DoorsOpen;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

/* ---------------------------------------------------------------------------------------------
 * A092: doors-open time
 * ------------------------------------------------------------------------------------------- */
echo "A092: doors-open time per occurrence\n";
$vie = 'Europe/Vienna';
check( 'A092 schema: doors_open_at column, DB version >= 3.8.3, ready()', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_occurrences LIKE 'doors_open_at'" ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.8.3', '>=' ) && DoorsOpen::ready() );

$d = adm_setup( $vie, '2027-05-01 20:00', '2027-05-01 23:00', 3 );
$d_occ = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=" . $d['occ'] );
$d_base = array( 'occurrence_id' => $d['occ'], 'timezone' => $vie, 'starts_at' => '2027-05-01T20:00', 'ends_at' => '2027-05-01T23:00', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' );
$d_after = EventsPage::save_occurrence( $d_base + array( 'doors_open_at' => '2027-05-01T20:30' ) );
$d_bad = EventsPage::save_occurrence( $d_base + array( 'doors_open_at' => 'gestern' ) );
$d_far = EventsPage::save_occurrence( $d_base + array( 'doors_open_at' => '2027-04-25T20:00' ) );
check( 'A092 doors after the start, unparsable or days before the start are refused', is_wp_error( $d_after ) && is_wp_error( $d_bad ) && is_wp_error( $d_far ) && 'wpcap_occurrence_doors' === $d_after->get_error_code() && null === DoorsOpen::for_occurrence( $d['occ'] ) );
$d_ok = EventsPage::save_occurrence( $d_base + array( 'doors_open_at' => '2027-05-01T19:00' ) );
check( 'A092 doors time is stored in UTC', ! is_wp_error( $d_ok ) && gmdate( 'Y-m-d H:i:s', adm_ts( '2027-05-01 19:00', $vie ) ) === DoorsOpen::for_occurrence( $d['occ'] ) );
check( 'A092 label: time only on the start day', 'Einlass ab 19:00' === DoorsOpen::label( DoorsOpen::for_occurrence( $d['occ'] ), $vie, $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=" . $d['occ'] ) ) && '' === DoorsOpen::label( null, $vie, '' ) );

$d_early = adm_in( $d['codes'][0], adm_ts( '2027-05-01 18:59', $vie ) );
check( 'A092 scan before doors is refused (no policy, inside the default window)', 'outside_entry_window' === $d_early && 'valid' === adm_ticket_status( $d['codes'][0] ) );
adm_now( adm_ts( '2027-05-01 18:30', $vie ) );
$d_err = Checkin::perform( $d['codes'][0], array( 'idempotency_key' => 'a092-msg' ) );
$d_prev = Checkin::preview( $d['codes'][1] );
adm_now( null );
check( 'A092 refusal names the doors time', is_wp_error( $d_err ) && false !== strpos( $d_err->get_error_message(), 'Einlass ab 19:00' ) );
check( 'A092 preview shows outside_entry_window and doors_open_at for staff', ! is_wp_error( $d_prev ) && 'outside_entry_window' === $d_prev['status'] && ! empty( $d_prev['doors_open_at'] ) );
check( 'A092 scan at doors time is admitted', 'checked_in' === adm_in( $d['codes'][0], adm_ts( '2027-05-01 19:00', $vie ) ) );

Policies::save( $d['type'], array( 'policy' => 'single', 'opens_before_minutes' => '180', 'closes_after_minutes' => '' ) );
check( 'A092 doors time is a hard bound even when the policy window opens earlier', 'outside_entry_window' === adm_in( $d['codes'][1], adm_ts( '2027-05-01 18:00', $vie ) ) && 'checked_in' === adm_in( $d['codes'][1], adm_ts( '2027-05-01 19:10', $vie ) ) );
Policies::save( $d['type'], array( 'policy' => 'default' ) );

$d_clear = EventsPage::save_occurrence( $d_base + array( 'doors_open_at' => '' ) );
check( 'A092 empty field removes the doors time', ! is_wp_error( $d_clear ) && null === DoorsOpen::for_occurrence( $d['occ'] ) && 'checked_in' === adm_in( $d['codes'][2], adm_ts( '2027-05-01 18:30', $vie ) ) );

// Offline package and mobile occurrence list carry the doors time.
EventsPage::save_occurrence( $d_base + array( 'doors_open_at' => '2027-05-01T19:00' ) );
$d_pkg_src = file_get_contents( WPCAP_PATH . 'src/OfflineScanner/Package.php' );
$d_app = file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' );
check( 'A092 offline package and app enforce doors_open_at', false !== strpos( $d_pkg_src, "'doors_open_at' =>" ) && false !== strpos( $d_app, 'occurrence.doors_open_at' ) );
check( 'A092 doors time is shown on the event card and ticket page', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' ), 'DoorsOpen::label' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' ), 'DoorsOpen::label' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'doors_open_at' => null ), array( 'occurrence_id' => $d['occ'] ) );

/* ---------------------------------------------------------------------------------------------
 * A093: capacity per entrance area
 * ------------------------------------------------------------------------------------------- */
echo "A093: capacity per entrance area enforced at check-in\n";
$c = adm_setup( $vie, '2027-06-01 20:00', '2027-06-01 23:00', 5 );
$c_bad = RulesPage::save_area( array( 'event_id' => $c['event'], 'name' => 'Stehplatz', 'capacity' => '-3' ) );
$c_save = RulesPage::save_area( array( 'event_id' => $c['event'], 'name' => 'Stehplatz', 'capacity' => '2' ) );
$c_area = is_array( $c_save ) ? (int) $c_save['area_id'] : 0;
$c_free = (int) Areas::save( $c['event'], 'Balkon' );
check( 'A093 schema: capacity column on areas; invalid capacity refused, valid stored', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_admission_areas LIKE 'capacity'" ) && is_wp_error( $c_bad ) && $c_area && 2 === Areas::capacity( Areas::get( $c_area ) ) && 0 === Areas::capacity( Areas::get( $c_free ) ) );
$c_rename = RulesPage::save_area( array( 'event_id' => $c['event'], 'area_id' => $c_area, 'name' => 'Stehplatz Halle' ) );
check( 'A093 renaming without a capacity field keeps the capacity', ! is_wp_error( $c_rename ) && 2 === Areas::capacity( Areas::get( $c_area ) ) );
Policies::save( $c['type'], array( 'policy' => 'reentry', 'max_entries' => '0' ) );
$c_t = adm_ts( '2027-06-01 19:30', $vie );
$c_ctx = array( 'area_id' => $c_area );
$c1 = adm_in( $c['codes'][0], $c_t, $c_ctx );
$c2 = adm_in( $c['codes'][1], $c_t + 10, $c_ctx );
$c3 = adm_in( $c['codes'][2], $c_t + 20, $c_ctx );
check( 'A093 entries up to the capacity are admitted, the next one is refused with area_full', 'checked_in' === $c1 && 'checked_in' === $c2 && 'area_full' === $c3 );
check( 'A093 refused scan is logged as area_full', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='area_full' AND event_id=" . $c['event'] ) );
adm_now( $c_t + 25 );
$c_prev = Checkin::preview( $c['codes'][2], $c_ctx );
$c_err = Checkin::perform( $c['codes'][2], $c_ctx + array( 'idempotency_key' => 'a093-msg' ) );
adm_now( null );
check( 'A093 preview shows area_full; refusal has a clear message and code', ! is_wp_error( $c_prev ) && 'area_full' === $c_prev['status'] && is_wp_error( $c_err ) && 'wpcap_area_full' === $c_err->get_error_code() && false !== strpos( $c_err->get_error_message(), 'voll' ) );
check( 'A093 an area without capacity is not limited', 'checked_in' === adm_in( $c['codes'][2], $c_t + 30, array( 'area_id' => $c_free ) ) );
check( 'A093 a check-out frees a place in the area', 'checked_out' === adm_out( $c['codes'][0], $c_t + 40, $c_ctx ) && 'checked_in' === adm_in( $c['codes'][3], $c_t + 50, $c_ctx ) && 'area_full' === adm_in( $c['codes'][0], $c_t + 60, $c_ctx ) );
check( 'A093 presence per area matches', 2 === PassCaptain\Admission\Engine::presence( $c['occ'] )['by_area'][ $c_area ] && 2 === Areas::present( $c_area, $c['occ'] ) );
$c_off = adm_in( $c['codes'][4], $c_t + 70, $c_ctx + array( 'source' => 'offline', 'scanned_at' => gmdate( 'Y-m-d H:i:s', $c_t + 65 ) ) );
check( 'A093 offline uploads (already admitted on the device) are not refused for capacity', 'checked_in' === $c_off );
$GLOBALS['wpcap_test_filters']['wpcap_admission_area_capacity'] = 10;
check( 'A093 capacity is filterable (wpcap_admission_area_capacity)', 'checked_in' === adm_in( $c['codes'][0], $c_t + 80, $c_ctx ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_admission_area_capacity'] );
