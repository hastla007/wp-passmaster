<?php
/**
 * A114: readable change histories per occurrence and per series (old → new for capacity, times,
 * prices/sales windows, price rules, status). A128: editing a stored series definition with a
 * preview of the effects on future occurrences; sold occurrences are kept and flagged, never
 * deleted; past, sold-out and cancelled occurrences stay untouched without an explicit choice.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\ChangeHistory;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Modules\Series;
use PassCaptain\Series\AdminPage as SeriesAdmin;
use PassCaptain\Series\Generator;
use PassCaptain\Series\Pools;
use PassCaptain\Series\Pricing;

Series::register();
global $wpdb;
$p = $wpdb->prefix;

function a114_history_text( array $rows, $timezone = 'Europe/Vienna' ) {
	$out = array();
	foreach ( $rows as $row ) { $out[] = $row->action . ': ' . implode( ' | ', ChangeHistory::lines( $row, $timezone ) ); }
	return implode( "\n", $out );
}
function a128_row( array $plan, $occurrence_id ) {
	foreach ( $plan['rows'] as $row ) { if ( (int) $row['occurrence_id'] === (int) $occurrence_id ) { return $row; } }
	return null;
}

/* ============================================================== A114 */

echo "A114: field diffs\n";
check( 'diff reports only changed fields, old → new', array( 'capacity' => array( '100', 120 ) ) === ChangeHistory::diff( array( 'capacity' => '100', 'location' => 'Wien', 'ends_at' => null ), array( 'capacity' => 120, 'location' => 'Wien', 'ends_at' => '' ) ) );
check( 'diff compares lists as lists', array() === ChangeHistory::diff( array( 'weekdays' => array( 6 ) ), array( 'weekdays' => array( '6' ) ) ) && isset( ChangeHistory::diff( array( 'weekdays' => array( 6 ) ), array( 'weekdays' => array( 6, 7 ) ) )['weekdays'] ) );
check( 'values are formatted for people (unbegrenzt, money, local time, weekdays)', 'unbegrenzt' === ChangeHistory::format_value( 'capacity', 0 ) && '19,90 EUR' === ChangeHistory::format_value( 'price_minor', 1990, array( 'currency' => 'EUR' ) ) && '01.05.2027 19:00' === ChangeHistory::format_value( 'starts_at', '2027-05-01 17:00:00', array( 'timezone' => 'Europe/Vienna' ) ) && 'Sa, So' === ChangeHistory::format_value( 'weekdays', array( 6, 7 ) ) && '−20 %' === ChangeHistory::format_value( 'amount', -2000, array( 'price_mode' => 'percent' ) ) );

echo "A114: occurrence history (capacity, times, status, ticket type prices and sales windows)\n";
$h_type = make_type( 50, 10, 2500, 100 );
$h_occ = Queries::ticket_type( $h_type );
$h_occ_id = (int) $h_occ->occurrence_id;
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='2027-06-05 17:00:00',ends_at=NULL WHERE occurrence_id=$h_occ_id" );
$saved = EventsPage::save_occurrence( array( 'occurrence_id' => $h_occ_id, 'starts_at' => '2027-06-05T20:00', 'ends_at' => '2027-06-05T22:00', 'timezone' => 'Europe/Vienna', 'location' => 'Großer Saal', 'capacity' => '120', 'status' => 'scheduled' ) );
$h_audit = $wpdb->get_row( "SELECT * FROM {$p}wpcap_audit_logs WHERE action='occurrence_updated' AND object_id=$h_occ_id ORDER BY audit_id DESC LIMIT 1" );
$h_details = json_decode( $h_audit->details, true );
check( 'occurrence_updated stores old → new of capacity, start, end and location', ! is_wp_error( $saved ) && array( '100', 120 ) == $h_details['changes']['capacity'] && array( '2027-06-05 17:00:00', '2027-06-05 18:00:00' ) === $h_details['changes']['starts_at'] && array( null, '2027-06-05 20:00:00' ) === $h_details['changes']['ends_at'] && 'Großer Saal' === $h_details['changes']['location'][1] && ! isset( $h_details['changes']['timezone'] ) && '2027-06-05 18:00:00' === $h_details['new_starts_at'] );
$h_text = a114_history_text( ChangeHistory::for_occurrence( $h_occ_id ) );
check( 'occurrence history reads "Kontingent: 100 → 120" and the moved start in local time', false !== strpos( $h_text, 'Kontingent: 100 → 120' ) && false !== strpos( $h_text, 'Beginn: 05.06.2027 19:00 → 05.06.2027 20:00' ) && false !== strpos( $h_text, 'Ort: Wien → Großer Saal' ) );
EventsPage::save_ticket_type( array( 'ticket_type_id' => $h_type, 'name' => 'Standard', 'price' => '29,90', 'currency' => 'EUR', 'tax_rate' => '20', 'max_per_order' => '10', 'capacity' => '60', 'sales_end' => '2027-06-05T18:00', 'status' => 'active' ) );
$h_text = a114_history_text( ChangeHistory::for_occurrence( $h_occ_id ) );
check( 'ticket type changes (price, capacity, sales end) appear in the occurrence history', false !== strpos( $h_text, 'Ticketart „Standard“' ) && false !== strpos( $h_text, 'Preis: 25,00 EUR → 29,90 EUR' ) && false !== strpos( $h_text, 'Kontingent: 50 → 60' ) && false !== strpos( $h_text, 'Verkaufsende: — → 05.06.2027 18:00' ) );
EventsPage::save_occurrence( array( 'occurrence_id' => $h_occ_id, 'starts_at' => '2027-06-05T20:00', 'ends_at' => '2027-06-05T22:00', 'timezone' => 'Europe/Vienna', 'location' => 'Großer Saal', 'capacity' => '0', 'status' => 'scheduled' ) );
check( 'capacity 0 is shown as "unbegrenzt"', false !== strpos( a114_history_text( ChangeHistory::for_occurrence( $h_occ_id, 1 ) ), 'Kontingent: 120 → unbegrenzt' ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $h_occ_id, 'reason' => 'Künstler erkrankt', 'confirm' => '1' ) );
$h_text = a114_history_text( ChangeHistory::for_occurrence( $h_occ_id, 1 ) );
check( 'cancellation is in the history with status change and reason', false !== strpos( $h_text, 'occurrence_cancelled' ) && false !== strpos( $h_text, 'Status: Geplant → Abgesagt/Storniert' ) && false !== strpos( $h_text, 'Grund: Künstler erkrankt' ) );
ob_start();
ChangeHistory::render( ChangeHistory::for_occurrence( $h_occ_id ), 'Europe/Vienna', 'wpcap-occurrence-history' );
$h_html = ob_get_clean();
check( 'history renders as a table with action, user and old → new details', false !== strpos( $h_html, 'id="wpcap-occurrence-history"' ) && false !== strpos( $h_html, 'Termin geändert' ) && false !== strpos( $h_html, 'Kontingent: 100 → 120' ) && false !== strpos( $h_html, '<th scope="col">' ) );
check( 'the occurrence admin view renders the history', false !== strpos( file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/EventsPage.php' ), "ChangeHistory::render( ChangeHistory::for_occurrence( (int) \$occ->occurrence_id )" ) );
$legacy = (object) array( 'action' => 'occurrence_updated', 'object_type' => 'occurrence', 'details' => json_encode( array( 'old_starts_at' => '2027-01-01 18:00:00', 'new_starts_at' => '2027-01-01 19:00:00' ) ) );
check( 'entries written before A114 still show the start change', array( 'Beginn: 01.01.2027 19:00 → 01.01.2027 20:00' ) === ChangeHistory::lines( $legacy, 'Europe/Vienna' ) );

/* ============================================================== A128 */

echo "A128: series edit – setup\n";
$s_tpl_type = make_type( 500, 10, 3000, 0 );
$s_tpl_occ = (int) Queries::ticket_type( $s_tpl_type )->occurrence_id;
$s_event = (int) Queries::occurrence( $s_tpl_occ )->event_id;
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET capacity=80,location='Stadtsaal' WHERE occurrence_id=$s_tpl_occ" );
$s_input = array( 'event_id' => $s_event, 'template_occurrence_id' => $s_tpl_occ, 'freq' => 'weekly', 'weekdays' => array( 6 ), 'start_date' => '2027-05-01', 'count' => 5, 'time' => '19:00', 'duration_minutes' => 120, 'timezone' => 'Europe/Vienna', 'sales_end_minutes_before' => '60', 'name' => 'Mai-Reihe' );
$s_run = Generator::create( $s_input );
$s_id = (int) $s_run['series_id'];
list( $o1, $o2, $o3, $o4, $o5 ) = $s_run['created'];
$s_type_of = static function ( $occ ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ . ' ORDER BY ticket_type_id LIMIT 1' ); };
check( 'setup: 5 Saturdays generated, schema 3.8.7 flag columns exist', 5 === count( $s_run['created'] ) && Generator::flags_ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.8.7', '>=' ) );
$s_o2_order = admin_paid_order( $s_type_of( $o2 ), 2, 'a128-o2@example.com' );
$s_o3_order = admin_paid_order( $s_type_of( $o3 ), 1, 'a128-o3@example.com' );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET capacity=1 WHERE occurrence_id=$o3" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$o5" );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $s_event, 'starts_at' => '2020-05-02 17:00:00', 'timezone' => 'Europe/Vienna', 'location' => 'Stadtsaal', 'capacity' => 80, 'status' => 'scheduled' ) );
$o_past = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_series_occurrences", array( 'series_id' => $s_id, 'occurrence_id' => $o_past, 'starts_at' => '2020-05-02 17:00:00', 'created_at' => '2020-01-01 00:00:00' ) );
check( 'setup: o2 has 2 sold tickets, o3 is sold out, o5 cancelled', $s_o2_order && $s_o3_order && 2 === Queries::sold_for_occurrence( $o2 ) && 1 === Queries::sold_for_occurrence( $o3 ) );

echo "A128: preview of a definition change\n";
// Saturdays and Sundays until 29.05., 20:00 instead of 19:00, without 08.05. and 22.05., capacity 100, sales end 2 h before.
$s_edit = array( 'weekdays' => array( 6, 7 ), 'count' => '', 'until' => '2027-05-29', 'exclusions' => "08.05.2027\n22.05.2027", 'time' => '20:00', 'capacity' => '100', 'sales_end_minutes_before' => '120' ) + $s_input;
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$s_denied = Generator::plan_update( $s_id, $s_edit );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'editing requires the event capability', is_wp_error( $s_denied ) && 403 === $s_denied->get_error_data()['status'] );
$s_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=$s_event" );
$s_def_before = $wpdb->get_var( "SELECT definition FROM {$p}wpcap_series WHERE series_id=$s_id" );
$plan = Generator::plan_update( $s_id, $s_edit );
check( 'preview writes nothing', ! is_wp_error( $plan ) && $s_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=$s_event" ) && $s_def_before === $wpdb->get_var( "SELECT definition FROM {$p}wpcap_series WHERE series_id=$s_id" ) );
check( 'preview counts: 4 added, 1 changed, 1 deleted, 1 kept+flagged, 2 skipped, 1 past untouched', array( 'add' => 4, 'change' => 1, 'delete' => 1, 'flag' => 1, 'skip' => 2, 'conflict' => 0, 'unflag' => 0, 'unchanged' => 0, 'past' => 1 ) === $plan['counts'] );
$r1 = a128_row( $plan, $o1 );
check( 'o1: start 19:00 → 20:00 local, end follows, capacity 80 → 100, sales window adjusted', 'change' === $r1['action'] && array( '2027-05-01 17:00:00', '2027-05-01 18:00:00' ) === $r1['changes']['starts_at'] && array( '2027-05-01 19:00:00', '2027-05-01 20:00:00' ) === $r1['changes']['ends_at'] && array( 80, 100 ) === $r1['changes']['capacity'] && $r1['sales'] && ! $r1['reschedule'] );
check( 'o2 (2 sold, now excluded) is kept and flagged, never deleted', 'flag' === a128_row( $plan, $o2 )['action'] && 'sold' === a128_row( $plan, $o2 )['reason'] );
check( 'o3 (sold out) is skipped without the explicit option', 'skip' === a128_row( $plan, $o3 )['action'] && 'sold_out' === a128_row( $plan, $o3 )['reason'] );
check( 'o4 (no sales, nothing linked) will be deleted', 'delete' === a128_row( $plan, $o4 )['action'] && array() === Generator::blocking_references( $o4 ) );
check( 'o5 (cancelled) is skipped without the explicit option', 'skip' === a128_row( $plan, $o5 )['action'] && 'cancelled' === a128_row( $plan, $o5 )['reason'] );
check( 'the past occurrence is not in the plan', null === a128_row( $plan, $o_past ) );
$adds = array_values( array_map( static fn( $r ) => $r['date'], array_filter( $plan['rows'], static fn( $r ) => 'add' === $r['action'] ) ) );
check( 'the new Sundays are listed as additions', array( '2027-05-02', '2027-05-09', '2027-05-16', '2027-05-23' ) === $adds );
check( 'definition diff lists rule and default changes old → new', array( '19:00', '20:00' ) === $plan['changes']['time'] && array( 0, 100 ) === $plan['changes']['capacity'] && array( array( 6 ), array( 6, 7 ) ) === $plan['changes']['weekdays'] && array( 5, null ) === $plan['changes']['count'] && ! isset( $plan['changes']['timezone'] ) );
check( 'blocking references name the sales tables of o2', in_array( 'wpcap_order_items', Generator::blocking_references( $o2 ), true ) || in_array( 'wpcap_tickets', Generator::blocking_references( $o2 ), true ) );
$r1_lines = SeriesAdmin::edit_row_lines( $r1, 'Europe/Vienna' );
check( 'preview lines are readable (old → new, sales window note)', in_array( 'Beginn: 01.05.2027 19:00 → 01.05.2027 20:00', $r1_lines, true ) && in_array( 'Kontingent: 80 → 100', $r1_lines, true ) && in_array( 'Verkaufszeitraum der Ticketarten wird angepasst.', $r1_lines, true ) );
check( 'preview line of a kept occurrence points to the cancellation flow', false !== strpos( implode( ' ', SeriesAdmin::edit_row_lines( a128_row( $plan, $o2 ), 'Europe/Vienna' ) ), '„Termin absagen“' ) );

echo "A128: applying the change\n";
check( 'a stale or missing preview fingerprint changes nothing', 'wpcap_series_plan_stale' === Generator::update( $s_id, $s_edit, array(), 'x' )->get_error_code() && $s_def_before === $wpdb->get_var( "SELECT definition FROM {$p}wpcap_series WHERE series_id=$s_id" ) );
$mark = count( $GLOBALS['wpcap_test_actions'] );
$s_res = Generator::update( $s_id, $s_edit, array(), $plan['fingerprint'] );
check( 'update result matches the preview', ! is_wp_error( $s_res ) && 4 === count( $s_res['added'] ) && array( $o1 ) === $s_res['changed'] && array( $o4 ) === $s_res['deleted'] && array( $o2 ) === $s_res['flagged'] && 2 === $s_res['skipped'] );
check( 'wpcap_series_updated fired, no reschedule for o1 without tickets', in_array( 'wpcap_series_updated', admin_actions_since( $mark ), true ) && ! in_array( 'wpcap_occurrence_rescheduled', admin_actions_since( $mark ), true ) );
$q1 = Queries::occurrence( $o1 );
$q1_type = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=$o1 ORDER BY ticket_type_id LIMIT 1" );
check( 'o1 moved to 20:00 local with capacity 100 and sales end 2 h before', '2027-05-01 18:00:00' === $q1->starts_at && '2027-05-01 20:00:00' === $q1->ends_at && 100 === (int) $q1->capacity && '2027-05-01 16:00:00' === $q1_type->sales_end );
check( 'o4 and its ticket types are deleted', null === Queries::occurrence( $o4 ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_types WHERE occurrence_id=$o4" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_series_occurrences WHERE occurrence_id=$o4" ) );
check( 'o2 still exists with its tickets and is flagged "outside_rule"', null !== Queries::occurrence( $o2 ) && 'scheduled' === Queries::occurrence( $o2 )->status && 2 === Queries::issued_ticket_count_for_occurrence( $o2 ) && 'outside_rule' === $wpdb->get_var( "SELECT flag FROM {$p}wpcap_series_occurrences WHERE occurrence_id=$o2" ) && array( $o2 ) === array_map( static fn( $o ) => (int) $o->occurrence_id, Generator::flagged( $s_id ) ) );
check( 'sold-out o3, cancelled o5 and the past occurrence are unchanged', '2027-05-15 17:00:00' === Queries::occurrence( $o3 )->starts_at && 1 === (int) Queries::occurrence( $o3 )->capacity && '2027-05-29 17:00:00' === Queries::occurrence( $o5 )->starts_at && 'cancelled' === Queries::occurrence( $o5 )->status && 80 === (int) Queries::occurrence( $o5 )->capacity && '2020-05-02 17:00:00' === Queries::occurrence( $o_past )->starts_at );
$new_occ = Queries::occurrence( $s_res['added'][0] );
check( 'added occurrences use the new rule, default capacity and copied ticket types', '2027-05-02 18:00:00' === $new_occ->starts_at && 100 === (int) $new_occ->capacity && 'Stadtsaal' === $new_occ->location && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $new_occ->occurrence_id ) && $s_id === (int) $wpdb->get_var( "SELECT series_id FROM {$p}wpcap_series_occurrences WHERE occurrence_id=" . (int) $new_occ->occurrence_id ) );
$s_def = json_decode( $wpdb->get_var( "SELECT definition FROM {$p}wpcap_series WHERE series_id=$s_id" ), true );
check( 'stored definition is the new one (hash updated)', '20:00' === $s_def['rule']['time'] && array( 6, 7 ) === $s_def['rule']['weekdays'] && 100 === $s_def['capacity'] && Generator::definition_hash( $s_def ) === $wpdb->get_var( "SELECT definition_hash FROM {$p}wpcap_series WHERE series_id=$s_id" ) );
$s_text = a114_history_text( ChangeHistory::for_series( $s_id ) );
check( 'series history: definition change old → new with effects', false !== strpos( $s_text, 'series_updated: ' ) && false !== strpos( $s_text, 'Uhrzeit (Ortszeit): 19:00 → 20:00' ) && false !== strpos( $s_text, 'Wochentage: Sa → Sa, So' ) && false !== strpos( $s_text, 'Kontingent: unbegrenzt → 100' ) && false !== strpos( $s_text, 'Auswirkung: 4 hinzugefügt, 1 geändert, 1 entfernt, 1 mit Verkäufen behalten und markiert, 2 übersprungen' ) && false !== strpos( $s_text, 'series_generated: ' ) );
$o1_text = a114_history_text( ChangeHistory::for_occurrence( $o1 ) );
check( 'occurrence history of o1 shows the series change', false !== strpos( $o1_text, 'Kontingent: 80 → 100' ) && false !== strpos( $o1_text, 'durch Serie #' . $s_id ) && false !== strpos( $o1_text, 'Beginn: 01.05.2027 19:00 → 01.05.2027 20:00' ) );
$new_type = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $new_occ->occurrence_id );
check( 'added occurrences get the new sales window (2 h before start)', '2027-05-02 16:00:00' === $new_type->sales_end );
check( 'occurrence history of o2 shows the flag', false !== strpos( a114_history_text( ChangeHistory::for_occurrence( $o2 ) ), 'occurrence_flagged: Markierung: — → außerhalb der Serienregel' ) );

echo "A128: explicit options, unflagging, conflicts\n";
$plan2 = Generator::plan_update( $s_id, $s_edit, array( 'include_sold_out' => true ) );
$r3 = a128_row( $plan2, $o3 );
check( 'with "include sold out" o3 is changed – only the start (capacity unchanged in the definition)', 'change' === $r3['action'] && isset( $r3['changes']['starts_at'] ) && ! isset( $r3['changes']['capacity'] ) && $r3['reschedule'] && 'skip' === a128_row( $plan2, $o5 )['action'] );
check( 'already applied occurrences are unchanged now', 0 === $plan2['counts']['add'] && null === a128_row( $plan2, $o1 ) && 'flag' === a128_row( $plan2, $o2 )['action'] );
$mark = count( $GLOBALS['wpcap_test_actions'] );
$s_res2 = Generator::update( $s_id, $s_edit, array( 'include_sold_out' => true ), $plan2['fingerprint'] );
check( 'moving the sold-out o3 (1 ticket) fires wpcap_occurrence_rescheduled', ! is_wp_error( $s_res2 ) && array( $o3 ) === $s_res2['changed'] && '2027-05-15 18:00:00' === Queries::occurrence( $o3 )->starts_at && 1 === (int) Queries::occurrence( $o3 )->capacity && in_array( 'wpcap_occurrence_rescheduled', admin_actions_since( $mark ), true ) );
$s_edit3 = array( 'exclusions' => '22.05.2027', 'sales_end_minutes_before' => '30' ) + $s_edit;
$plan3 = Generator::plan_update( $s_id, $s_edit3 );
$r2 = a128_row( $plan3, $o2 );
check( 'o2 back in the rule: changed (20:00) and unflagged', 'change' === $r2['action'] && $r2['unflag'] && $r2['reschedule'] && 2 === $r2['issued'] );
$plan_low = Generator::plan_update( $s_id, array( 'capacity' => '1' ) + $s_edit3, array( 'include_sold_out' => true ) );
check( 'a default capacity below the sold tickets is a conflict, not applied', 'conflict' === a128_row( $plan_low, $o2 )['action'] && 'capacity_below_sold' === a128_row( $plan_low, $o2 )['reason'] );
$s_res3 = Generator::update( $s_id, $s_edit3, array(), $plan3['fingerprint'] );
check( 'a changed sales window offset is applied to unchanged dates and logged old → new', '2027-05-01 17:30:00' === $wpdb->get_var( "SELECT sales_end FROM {$p}wpcap_ticket_types WHERE occurrence_id=$o1" ) && false !== strpos( a114_history_text( ChangeHistory::for_occurrence( $o1 ) ), 'Verkaufsende: 01.05.2027 18:00 → 01.05.2027 19:30' ) && '2027-05-15 16:00:00' === $wpdb->get_var( "SELECT sales_end FROM {$p}wpcap_ticket_types WHERE occurrence_id=$o3" ) );
check( 'after applying, o2 is unflagged and moved', ! is_wp_error( $s_res3 ) && '' === $wpdb->get_var( "SELECT flag FROM {$p}wpcap_series_occurrences WHERE occurrence_id=$o2" ) && '2027-05-08 18:00:00' === Queries::occurrence( $o2 )->starts_at && array() === Generator::flagged( $s_id ) && false !== strpos( a114_history_text( ChangeHistory::for_occurrence( $o2 ) ), 'occurrence_unflagged' ) );
$other = Generator::create( array( 'freq' => 'dates', 'dates' => '2027-07-03', 'count' => '', 'until' => '', 'name' => 'Sommer' ) + $s_input );
$dup = Generator::plan_update( (int) $other['series_id'], $s_edit3 );
check( 'another series cannot take over an identical definition', is_wp_error( $dup ) && 'wpcap_series_duplicate' === $dup->get_error_code() );
$cancelled_plan = Generator::plan_update( $s_id, array( 'location' => 'Kleiner Saal' ) + $s_edit3, array( 'include_cancelled' => true ) );
check( 'with "include cancelled" the cancelled occurrence gets the data change but stays cancelled', 'change' === a128_row( $cancelled_plan, $o5 )['action'] && array( 'Stadtsaal', 'Kleiner Saal' ) === a128_row( $cancelled_plan, $o5 )['changes']['location'] && ! isset( a128_row( $cancelled_plan, $o5 )['changes']['status'] ) );

echo "A128: DST stays flagged in the edit preview\n";
$dst_run = Generator::create( array( 'freq' => 'dates', 'dates' => '2027-03-27', 'count' => '', 'until' => '', 'time' => '02:30', 'name' => 'Nacht', 'sales_end_minutes_before' => '' ) + $s_input );
$dst_plan = Generator::plan_update( (int) $dst_run['series_id'], array( 'freq' => 'dates', 'dates' => "2027-03-27\n2027-03-28", 'count' => '', 'until' => '', 'time' => '02:30', 'name' => 'Nacht', 'sales_end_minutes_before' => '' ) + $s_input );
$dst_add = array_values( array_filter( $dst_plan['rows'], static fn( $r ) => 'add' === $r['action'] ) );
check( 'added date in the DST gap carries dst=gap and a note', 1 === count( $dst_add ) && 'gap' === $dst_add[0]['item']['dst'] && '2027-03-28 01:30:00' === $dst_add[0]['item']['starts_at'] && 1 === count( $dst_plan['dst_notes'] ) );

echo "A114/A128: price rules and pools in the series history\n";
$pr_id = Pricing::create( $s_id, array( 'kind' => 'early_bird', 'label' => 'Frühbucher', 'until' => '2027-03-01T00:00', 'price_mode' => 'fixed', 'amount' => '19,90' ) );
$pr_upd = Pricing::update( $pr_id, array( 'kind' => 'early_bird', 'label' => 'Frühbucher', 'until' => '2027-04-01T00:00', 'price_mode' => 'fixed', 'amount' => '17,90' ) );
$pr_row = Pricing::rule( $pr_id );
check( 'a price rule can be edited (same rule id)', $pr_upd === $pr_id && 1790 === (int) $pr_row->amount && '2027-03-31 22:00:00' === $pr_row->until_at );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$pr_denied = Pricing::update( $pr_id, array( 'kind' => 'early_bird', 'until' => '2027-04-01T00:00', 'amount' => '1' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'editing a price rule requires the event capability', is_wp_error( $pr_denied ) && 403 === $pr_denied->get_error_data()['status'] );
Pricing::update( $pr_id, array( 'kind' => 'early_bird', 'label' => 'Frühbucher', 'until' => '2027-04-01T00:00', 'price_mode' => 'percent', 'amount' => '-20' ) );
$pool_id = Pools::create( $s_id, array( 'name' => 'Studierende', 'type_name' => 'Standard', 'capacity' => '200' ) );
Pools::update_capacity( $pool_id, 150 );
Pricing::delete( $pr_id );
$s_text = a114_history_text( ChangeHistory::for_series( $s_id ) );
check( 'series history: price rule added with its price', false !== strpos( $s_text, 'series_price_rule_created: ' ) && false !== strpos( $s_text, 'Preis: 19,90 EUR' ) && false !== strpos( $s_text, 'Art: Frühbucherpreis' ) );
check( 'series history: price rule change old → new (amount, early-bird end, price mode)', false !== strpos( $s_text, 'Preis: 19,90 EUR → 17,90 EUR' ) && false !== strpos( $s_text, 'Frühbucher bis: 01.03.2027 00:00 → 01.04.2027 00:00' ) && false !== strpos( $s_text, 'Preis: 17,90 EUR → −20 %' ) && false !== strpos( $s_text, 'Preisart: Fester Preis → Prozentuale Änderung' ) );
check( 'series history: pool capacity 200 → 150 and rule removal', false !== strpos( $s_text, 'series_pool_updated: Kontingent: 200 → 150' ) && false !== strpos( $s_text, 'series_pool_created: Name: Studierende | Kontingent: 200' ) && false !== strpos( $s_text, 'series_price_rule_deleted: ' ) );
check( 'series and occurrence histories are separate', false === strpos( $s_text, 'occurrence_flagged' ) && false === strpos( a114_history_text( ChangeHistory::for_occurrence( $o1 ) ), 'series_price_rule' ) );

echo "A128: edit form prefill and admin wiring\n";
$prefill = Generator::definition_input( $s_def );
check( 'stored definition converts back to form input', array( '6', '7' ) === $prefill['weekdays'] && '20:00' === $prefill['time'] && '2027-05-29' === $prefill['until'] && "2027-05-08\n2027-05-22" === $prefill['exclusions'] && '100' === $prefill['capacity'] && '120' === $prefill['sales_end_minutes_before'] );
check( 'round trip: prefilled input yields the same definition', Generator::definition_hash( $s_def ) === Generator::definition_hash( Generator::definition( $prefill ) ) );
if ( function_exists( 'submit_button' ) && function_exists( 'wp_nonce_field' ) && function_exists( 'esc_url' ) ) {
	$render_plan = Generator::plan_update( $s_id, array( 'exclusions' => "08.05.2027\n22.05.2027" ) + $s_edit3 );
	$render = new ReflectionMethod( SeriesAdmin::class, 'render_edit_preview' );
	$render->setAccessible( true );
	ob_start();
	$render->invoke( null, $render_plan, Generator::definition_input( $render_plan['definition'] ) );
	$render_html = ob_get_clean();
	check( 'edit preview renders definition diff, per-date actions and a confirm form with the fingerprint', false !== strpos( $render_html, 'Seriendefinition (alt → neu)' ) && false !== strpos( $render_html, 'Ausnahmen: 22.05.2027 → 08.05.2027, 22.05.2027' ) && false !== strpos( $render_html, 'bleibt bestehen und wird markiert' ) && false !== strpos( $render_html, 'value="wpcap_series_update"' ) && false !== strpos( $render_html, 'value="' . $render_plan['fingerprint'] . '"' ) && false !== strpos( $render_html, 'Änderungen übernehmen' ) );
	$flag_render = new ReflectionMethod( SeriesAdmin::class, 'render_flagged' );
	$flag_render->setAccessible( true );
	$wpdb->query( "UPDATE {$p}wpcap_series_occurrences SET flag='outside_rule' WHERE occurrence_id=$o2" );
	ob_start();
	$flag_render->invoke( null, $s_id );
	$flag_html = ob_get_clean();
	$wpdb->query( "UPDATE {$p}wpcap_series_occurrences SET flag='' WHERE occurrence_id=$o2" );
	check( 'flagged occurrences are listed with a link to the cancellation flow', false !== strpos( $flag_html, 'Termine außerhalb der Serienregel' ) && false !== strpos( $flag_html, 'view=cancel_occurrence' ) && false !== strpos( $flag_html, '(2 verkauft)' ) );
}
$handlers = Series::admin_handlers();
check( 'admin handlers for series update and price rule update are registered', isset( $handlers['wpcap_series_update'], $handlers['wpcap_series_rule_update'] ) );

if ( class_exists( 'PassCaptain\\Seating\\Layouts' ) && function_exists( 'seating_draft' ) ) {
	echo "A128: seat-map link can be changed for future occurrences\n";
	$seat_layout = PassCaptain\Seating\Layouts::create( $s_event, 'Stadtsaal' );
	PassCaptain\Seating\Layouts::save_draft( $seat_layout['layout_id'], seating_draft() );
	$seat_version = PassCaptain\Seating\Layouts::publish( $seat_layout['layout_id'] );
	PassCaptain\Seating\Layouts::link_occurrence( $s_tpl_occ, $seat_version['version_id'], array( $s_tpl_type => array( 'parkett' ) ) );
	$seat_edit = array( 'seat_version_id' => $seat_version['version_id'] ) + $s_edit3;
	$seat_plan = Generator::plan_update( $s_id, $seat_edit );
	$seat_res = Generator::update( $s_id, $seat_edit, array(), $seat_plan['fingerprint'] );
	$seat_link = PassCaptain\Seating\Layouts::link( $o1 );
	check( 'future occurrences are linked to the new seat-map version with the template zones', ! is_wp_error( $seat_res ) && in_array( $o1, $seat_res['changed'], true ) && $seat_link && (int) $seat_version['version_id'] === (int) $seat_link->version_id && array( $s_type_of( $o1 ) => array( 'parkett' ) ) === PassCaptain\Seating\Layouts::type_zones( $o1 ) && ! PassCaptain\Seating\Layouts::link( $o5 ) );
	check( 'series history shows the seat-map change', false !== strpos( a114_history_text( ChangeHistory::for_series( $s_id, 1 ) ), 'Saalplan-Version: — → #' . (int) $seat_version['version_id'] ) );
}
