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
