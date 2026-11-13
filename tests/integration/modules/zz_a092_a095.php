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
use PassCaptain\AdmissionChecks;
use PassCaptain\Printing\AttendeeList;
use PassCaptain\Printing\NoShowList;
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
check( 'A092 schema: doors_open_at column, DB version >= 3.8.4, ready()', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_occurrences LIKE 'doors_open_at'" ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.8.4', '>=' ) && DoorsOpen::ready() );

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

/* ---------------------------------------------------------------------------------------------
 * A094: ID/age check per ticket type with scanner hint
 * ------------------------------------------------------------------------------------------- */
echo "A094: ID/age check per ticket type shown to scanning staff\n";
$a = adm_setup( $vie, '2027-07-01 20:00', '2027-07-01 23:00', 3 );
$a_type = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . $a['type'] );
$a_base = array( 'ticket_type_id' => $a['type'], 'name' => $a_type->name, 'price' => '0', 'currency' => $a_type->currency, 'tax_rate' => '0', 'capacity' => (string) $a_type->capacity, 'max_per_order' => (string) $a_type->max_per_order, 'status' => 'active' );
check( 'A094 schema: columns on ticket types, DB version >= 3.8.5', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_ticket_types LIKE 'min_age'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_ticket_types LIKE 'staff_note'" ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.8.5', '>=' ) && AdmissionChecks::ready() );
$a_bad = EventsPage::save_ticket_type( $a_base + array( 'min_age' => '120' ) );
check( 'A094 implausible minimum age is refused', is_wp_error( $a_bad ) && 'wpcap_type_min_age' === $a_bad->get_error_code() );
$a_plain = adm_in( $a['codes'][0], adm_ts( '2027-07-01 19:30', $vie ) );
adm_now( adm_ts( '2027-07-01 19:31', $vie ) );
$a_plain_view = Checkin::preview( $a['codes'][1] );
adm_now( null );
check( 'A094 without settings the scan result carries no hint', 'checked_in' === $a_plain && ! is_wp_error( $a_plain_view ) && ! isset( $a_plain_view['staff_hint'] ) );
$a_saved = EventsPage::save_ticket_type( $a_base + array( 'id_check' => '1', 'min_age' => '18', 'staff_note' => 'Bändchen ausgeben <b>' ) );
$a_checks = AdmissionChecks::for_type( $a['type'] );
check( 'A094 ID check, minimum age and note are stored (note sanitised)', ! is_wp_error( $a_saved ) && true === $a_checks['id_check'] && 18 === $a_checks['min_age'] && 'Bändchen ausgeben' === $a_checks['staff_note'] );
adm_now( adm_ts( '2027-07-01 19:40', $vie ) );
$a_prev = Checkin::preview( $a['codes'][1] );
$a_in = Checkin::perform( $a['codes'][1], array( 'idempotency_key' => 'a094-in' ) );
adm_now( null );
$a_hint = 'Ausweis prüfen · Mindestalter 18 · Bändchen ausgeben';
check( 'A094 preview and check-in show the hint to staff (web scanner and app)', ! is_wp_error( $a_prev ) && $a_hint === $a_prev['staff_hint'] && 18 === $a_prev['min_age'] && true === $a_prev['id_check'] && ! is_wp_error( $a_in ) && 'checked_in' === $a_in['status'] && $a_hint === $a_in['staff_hint'] );
check( 'A094 buyer notice on the event card', 'ab 18 Jahren, Ausweis erforderlich' === AdmissionChecks::buyer_notice( $a_checks ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' ), 'AdmissionChecks::buyer_notice' ) );
check( 'A094 web scanner, offline app and package show the hint', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/scanner.js' ), 'data.staff_hint' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' ), 'd.type.hint' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/OfflineScanner/Package.php' ), "'hint' => \\PassCaptain\\AdmissionChecks::staff_hint" ) );
$a_off = EventsPage::save_ticket_type( $a_base + array( 'min_age' => '' ) );
check( 'A094 clearing the fields removes the hint', ! is_wp_error( $a_off ) && array() === AdmissionChecks::scan_fields( $a['type'] ) );

/* ---------------------------------------------------------------------------------------------
 * A095: no-show list per occurrence
 * ------------------------------------------------------------------------------------------- */
echo "A095: no-show list/export per occurrence\n";

function a095_id( $code ) { global $wpdb; return (int) $wpdb->get_var( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $code ) . "'" ); }
$n = adm_setup( $vie, '2027-08-01 20:00', '2027-08-01 23:00', 5 );
$n_t = adm_ts( '2027-08-01 19:30', $vie );
$n_in = adm_in( $n['codes'][0], $n_t );
$n_in2 = adm_in( $n['codes'][1], $n_t + 5 );
$n_undo = PassCaptain\Admin\TicketsPage::undo_checkin( array( 'ticket_id' => a095_id( $n['codes'][1] ), 'reason' => 'Fehlscan' ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => a095_id( $n['codes'][2] ) ) );
$n_ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, NoShowList::tickets( $n['occ'] ) );
sort( $n_ids );
$n_expect = array( a095_id( $n['codes'][1] ), a095_id( $n['codes'][3] ), a095_id( $n['codes'][4] ) );
sort( $n_expect );
check( 'A095 no-shows: valid tickets never admitted (undone admission counts as not admitted; admitted and revoked tickets excluded)', 'checked_in' === $n_in && 'checked_in' === $n_in2 && ! is_wp_error( $n_undo ) && $n_expect === $n_ids );

// Repeatable policy: status stays valid after the entry, the ticket is still not a no-show.
$r = adm_setup( $vie, '2027-08-02 20:00', '2027-08-02 23:00', 2 );
Policies::save( $r['type'], array( 'policy' => 'reentry', 'max_entries' => '0' ) );
$r_t = adm_ts( '2027-08-02 19:30', $vie );
$r_ok = 'checked_in' === adm_in( $r['codes'][0], $r_t ) && 'checked_out' === adm_out( $r['codes'][0], $r_t + 60 );
$r_ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, NoShowList::tickets( $r['occ'] ) );
check( 'A095 re-entry ticket that was inside (status still valid) is not a no-show', $r_ok && 'valid' === adm_ticket_status( $r['codes'][0] ) && array( a095_id( $r['codes'][1] ) ) === $r_ids );

$n_full = NoShowList::rows( $n['occ'], 'full' );
$n_basic = NoShowList::rows( $n['occ'], 'basic' );
$n_flat = implode( '|', array_merge( ...$n_basic ) );
check( 'A095 full list has header plus 3 rows with buyer e-mail and order; basic list only name and ticket type', 4 === count( $n_full ) && 6 === count( $n_full[0] ) && false !== strpos( implode( '|', array_merge( ...$n_full ) ), '@' ) && 4 === count( $n_basic ) && 2 === count( $n_basic[0] ) && false === strpos( $n_flat, '@' ) );
$n_csv = PassCaptain\Reports\Scope::csv( $n_full );
check( 'A095 CSV export has one line per no-show', 4 === count( array_filter( explode( "\n", trim( $n_csv ) ) ) ) );
$n_html = NoShowList::html( $n['occ'], 'basic' );
check( 'A095 print view names count and total', false !== strpos( $n_html, 'No-Show-Liste' ) && false !== strpos( $n_html, '3 von 4 gültigen Tickets nicht eingelassen' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$n_lvl_basic = AttendeeList::access_level( $n['occ'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events', 'wpcap_checkin', 'wpcap_manage_orders' );
$n_lvl_none = AttendeeList::access_level( $n['occ'] );
$GLOBALS['wpcap_test_denied_caps'] = array();
$n_page = (string) file_get_contents( WPCAP_PATH . 'src/Printing/Page.php' );
check( 'A095 access follows the attendee-list scope (basic for check-in staff, none without access); handler offers HTML and CSV', 'basic' === $n_lvl_basic && '' === $n_lvl_none && false !== strpos( $n_page, 'NoShowList::rows( $occurrence_id, $level )' ) && false !== strpos( $n_page, 'noshow_csv' ) );
