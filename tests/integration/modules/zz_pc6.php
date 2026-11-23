<?php
/**
 * PC-6 (WF-SPEC-14, Z-02): `wpcap_occurrence_rescheduled` and `wpcap_occurrence_cancelled` fire from the
 * admin only after the occurrence row was really written. A failed UPDATE fires nothing, bumps no revision
 * and writes no audit entry; a parallel cancellation fires the action exactly once.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;

echo "PC-6: occurrence actions only after a successful UPDATE\n";
global $wpdb;
$p = $wpdb->prefix;

// Makes the next statement matching $re fail with a real SQL error (unknown table); returns the remover.
$pc6_break = static function ( $re ) {
	$GLOBALS['pc6_broken'] = array();
	$cb = static function ( $sql ) use ( $re ) {
		if ( ! $GLOBALS['pc6_broken'] && preg_match( $re, $sql ) ) {
			$GLOBALS['pc6_broken'][] = $sql;
			return 'UPDATE pc6_missing_table SET x=1';
		}
		return $sql;
	};
	add_filter( 'query', $cb );
	return static function () use ( $cb ) { remove_filter( 'query', $cb ); return $GLOBALS['pc6_broken']; };
};
$pc6_since = static function ( $mark, $hook ) {
	return count( array_keys( array_slice( $GLOBALS['wpcap_test_actions'], $mark ), $hook, true ) );
};
$pc6_audits = static function ( $action, $occ ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_type='occurrence' AND object_id=%d", $action, $occ ) );
};

$pc6_event = ex_event( 'PC6 Konzert' );
$pc6_occ = ex_occ( $pc6_event );
$pc6_type = ex_type( $pc6_occ, 'Eintritt', 1500 );
list( $pc6_order ) = ex_buy( $pc6_type, 2, 'pc6@example.com' );
$pc6_row = Queries::occurrence( $pc6_occ );
$pc6_new_start = Forms::utc_to_local( gmdate( 'Y-m-d H:i:s', strtotime( $pc6_row->starts_at . ' UTC' ) + 2 * DAY_IN_SECONDS ), 'Europe/Vienna' );
$pc6_input = array( 'occurrence_id' => $pc6_occ, 'starts_at' => $pc6_new_start, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => '0' );
check( 'PC-6: fixture has a paid order with tickets', $pc6_order > 0 && Queries::issued_ticket_count_for_occurrence( $pc6_occ ) > 0 );

// Rescheduling: the UPDATE of the occurrence fails.
$pc6_rev = (int) ( $pc6_row->revision ?? 0 );
$pc6_audit_before = $pc6_audits( 'occurrence_updated', $pc6_occ );
$pc6_mails = count( $GLOBALS['wpcap_test_mails'] );
$pc6_mark = count( $GLOBALS['wpcap_test_actions'] );
$pc6_undo = $pc6_break( '/^UPDATE\s+\S*wpcap_occurrences\s+SET\s+starts_at=/i' );
$pc6_fail = EventsPage::save_occurrence( $pc6_input );
$pc6_fired = $pc6_undo();
check( 'PC-6: the injected UPDATE failure was hit', 1 === count( $pc6_fired ) );
check( 'PC-6: failed reschedule returns an error', is_wp_error( $pc6_fail ) && 'wpcap_occurrence_save' === $pc6_fail->get_error_code() );
check( 'PC-6: failed reschedule fires neither wpcap_occurrence_rescheduled nor wpcap_occurrence_changed', 0 === $pc6_since( $pc6_mark, 'wpcap_occurrence_rescheduled' ) && 0 === $pc6_since( $pc6_mark, 'wpcap_occurrence_changed' ) );
$pc6_after = Queries::occurrence( $pc6_occ );
check( 'PC-6: failed reschedule leaves start, revision and audit log unchanged and sends no mail', $pc6_after->starts_at === $pc6_row->starts_at && $pc6_rev === (int) ( $pc6_after->revision ?? 0 ) && $pc6_audit_before === $pc6_audits( 'occurrence_updated', $pc6_occ ) && count( $GLOBALS['wpcap_test_mails'] ) === $pc6_mails );

// Rescheduling without a fault: fires once.
$pc6_mark = count( $GLOBALS['wpcap_test_actions'] );
$pc6_ok = EventsPage::save_occurrence( $pc6_input );
check( 'PC-6: successful reschedule fires wpcap_occurrence_rescheduled once', ! is_wp_error( $pc6_ok ) && ! empty( $pc6_ok['rescheduled'] ) && 1 === $pc6_since( $pc6_mark, 'wpcap_occurrence_rescheduled' ) && Queries::occurrence( $pc6_occ )->starts_at !== $pc6_row->starts_at );

// Saving the same values again changes 0 rows: no error, no reschedule.
$pc6_mark = count( $GLOBALS['wpcap_test_actions'] );
$pc6_same = EventsPage::save_occurrence( $pc6_input );
check( 'PC-6: saving unchanged values (0 rows) is no error and fires nothing', ! is_wp_error( $pc6_same ) && empty( $pc6_same['rescheduled'] ) && 0 === $pc6_since( $pc6_mark, 'wpcap_occurrence_rescheduled' ) );

// Cancelling: the UPDATE of the status fails.
$pc6_cancel_in = array( 'occurrence_id' => $pc6_occ, 'reason' => 'PC6 Ausfall', 'confirm' => '1' );
$pc6_rev = (int) ( Queries::occurrence( $pc6_occ )->revision ?? 0 );
$pc6_mark = count( $GLOBALS['wpcap_test_actions'] );
$pc6_undo = $pc6_break( "/^UPDATE\\s+\\S*wpcap_occurrences\\s+SET\\s+status='cancelled'/i" );
$pc6_cfail = EventsPage::cancel_occurrence( $pc6_cancel_in );
$pc6_fired = $pc6_undo();
check( 'PC-6: the injected status UPDATE failure was hit', 1 === count( $pc6_fired ) );
check( 'PC-6: failed cancellation returns an error and fires no wpcap_occurrence_cancelled', is_wp_error( $pc6_cfail ) && 0 === $pc6_since( $pc6_mark, 'wpcap_occurrence_cancelled' ) );
check( 'PC-6: failed cancellation leaves status, revision and audit log unchanged', 'scheduled' === Queries::occurrence( $pc6_occ )->status && $pc6_rev === (int) ( Queries::occurrence( $pc6_occ )->revision ?? 0 ) && 0 === $pc6_audits( 'occurrence_cancelled', $pc6_occ ) );

// Cancelling while a parallel request already cancelled it (UPDATE matches 0 rows): no second action.
$pc6_occ2 = ex_occ( $pc6_event );
$pc6_parallel = static function ( $sql ) use ( $wpdb, $p, $pc6_occ2 ) {
	if ( preg_match( "/^UPDATE\\s+\\S*wpcap_occurrences\\s+SET\\s+status='cancelled'.*occurrence_id='?{$pc6_occ2}'?/i", $sql ) && empty( $GLOBALS['pc6_parallel_done'] ) ) {
		$GLOBALS['pc6_parallel_done'] = true;
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=%d", $pc6_occ2 ) );
	}
	return $sql;
};
add_filter( 'query', $pc6_parallel );
$pc6_mark = count( $GLOBALS['wpcap_test_actions'] );
$pc6_race = EventsPage::cancel_occurrence( array( 'occurrence_id' => $pc6_occ2, 'reason' => 'PC6 parallel', 'confirm' => '1' ) );
remove_filter( 'query', $pc6_parallel );
unset( $GLOBALS['pc6_parallel_done'] );
check( 'PC-6: cancellation that lost the race answers like a second click and fires no wpcap_occurrence_cancelled', ! is_wp_error( $pc6_race ) && ! empty( $pc6_race['complete'] ) && 0 === $pc6_since( $pc6_mark, 'wpcap_occurrence_cancelled' ) && 0 === $pc6_audits( 'occurrence_cancelled', $pc6_occ2 ) );

// Cancelling without a fault: fires once.
$pc6_mark = count( $GLOBALS['wpcap_test_actions'] );
$pc6_cok = EventsPage::cancel_occurrence( $pc6_cancel_in );
check( 'PC-6: successful cancellation fires wpcap_occurrence_cancelled once and records it', ! is_wp_error( $pc6_cok ) && 1 === $pc6_since( $pc6_mark, 'wpcap_occurrence_cancelled' ) && 'cancelled' === Queries::occurrence( $pc6_occ )->status && 1 === $pc6_audits( 'occurrence_cancelled', $pc6_occ ) );
