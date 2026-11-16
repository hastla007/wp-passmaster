<?php
/**
 * A464: after a plugin rollback (older code sold without maintaining the capacity counters) and a
 * roll forward, the counters are rebuilt and capacity decisions fall back to the SUM queries until a
 * clean reconcile run – no overselling from stale "ready" counters.
 * A468: the rebuild is a resumable job (cursor, own option) instead of one synchronous pass over the
 * whole history inside the upgrade request.
 * Real drill with release 1.4.0: tests/restore/drill.sh (scenario 3).
 */

use PassCaptain\CapacityCounters;
use PassCaptain\Installer;
use PassCaptain\Orders;

echo "A464/A468: counters after a rollback, resumable rebuild\n";

global $wpdb;
$p = $wpdb->prefix;
$a464_state = get_option( CapacityCounters::OPTION );
$a464_job = get_option( CapacityCounters::REBUILD_OPTION );

check( 'rolled_back(): stored schema lower than the highest one ever stored', Installer::rolled_back( '3.5.0+abc', '3.10.14' ) && ! Installer::rolled_back( '3.10.14+abc', '3.10.14' ) && ! Installer::rolled_back( '3.10.13+x', '3.10.12' ) && ! Installer::rolled_back( '', '3.10.14' ) && ! Installer::rolled_back( '3.5.0+abc', '' ) );
check( 'install() hands the rollback to the counters', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Installer.php' ), 'CapacityCounters::after_install( $rolled_back );' ) );

update_option( CapacityCounters::OPTION, array( 'state' => 'ready', 'since' => time() - 86400 ) );
delete_option( CapacityCounters::REBUILD_OPTION );
for ( $a464_i = 0; $a464_i < 3; $a464_i++ ) { make_type( 5 ); } // more occurrences than one slice
$a464_type = make_type( 10, 10, 0 ); // free: paid at once
$a464_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a464_type ) );
$a464_new = Orders::create( array( 'email' => 'a464-new@example.com', 'items' => array( array( 'ticket_type_id' => $a464_type, 'quantity' => 4 ) ), 'idempotency_key' => 'a464-new' ) );
// Older code (before schema 3.7.0) sold 5 more: lines without counted units, counters untouched,
// order last changed three days ago (outside the two days the cron reconcile looks at).
$a464_old = Orders::create( array( 'email' => 'a464-old@example.com', 'items' => array( array( 'ticket_type_id' => $a464_type, 'quantity' => 5 ) ), 'idempotency_key' => 'a464-old' ) );
$a464_days = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET counted_qty=0 WHERE order_id=%d", $a464_old['order_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET sold_qty=sold_qty-5 WHERE ticket_type_id=%d", $a464_type ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET sold_qty=sold_qty-5 WHERE occurrence_id=%d", $a464_occ ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s,updated_at=%s WHERE order_id=%d", $a464_days, $a464_days, $a464_old['order_id'] ) );
Orders::flush_availability_cache();
check( 'setup: paid orders of the new and the old code, counters miss the old sale', ! is_wp_error( $a464_new ) && ! is_wp_error( $a464_old ) && 4 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT sold_qty FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a464_type ) ) );
check( 'setup: the cron reconcile does not see it (old order, counters agree with counted units)', ! in_array( (int) $a464_old['order_id'], CapacityCounters::reconcile()['orders'], true ) && 'ready' === CapacityCounters::state() );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET sold_qty=4 WHERE ticket_type_id=%d", $a464_type ) ); // reconcile must not have fixed it

// Roll forward: install() → after_install( rolled_back: true ). First slice only (budget 0 = one occurrence).
$GLOBALS['wpcap_test_filters']['wpcap_capacity_rebuild_budget'] = 0;
$a464_total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT occurrence_id) FROM {$p}wpcap_ticket_types" );
$a464_first = (int) $wpdb->get_var( "SELECT MIN(occurrence_id) FROM {$p}wpcap_ticket_types" );
CapacityCounters::after_install( true );
$a464_progress = CapacityCounters::rebuild_progress();
check( 'rollback detected: counters are rebuilt although they said "ready"', 'building' === CapacityCounters::state() && 'rollback' === ( $a464_progress['reason'] ?? '' ) );
check( 'decisions use the SUM queries while rebuilding (writers keep the counters maintained)', ! CapacityCounters::enabled() && CapacityCounters::maintained() );
check( 'A468: the upgrade request rebuilt only its slice, not the whole history (' . $a464_total . ' occurrences)', $a464_total > 1 && $a464_first === (int) $a464_progress['cursor'] );
check( 'A468: progress is stored in its own option (independent of the schema version)', is_array( get_option( CapacityCounters::REBUILD_OPTION ) ) && $a464_first === (int) get_option( CapacityCounters::REBUILD_OPTION )['cursor'] );
check( 'no overselling meanwhile: availability from the SUM queries', 1 === Orders::availability( $a464_type ) );
$a464_over = Orders::create( array( 'email' => 'a464-over@example.com', 'items' => array( array( 'ticket_type_id' => $a464_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a464-over' ) );
check( 'a purchase beyond the real capacity is rejected', is_wp_error( $a464_over ) );
// Further upgrade attempts (e.g. interrupted request) continue from the cursor instead of restarting.
CapacityCounters::after_install( false );
$a464_p2 = CapacityCounters::rebuild_progress();
check( 'A468: the next slice continues after the cursor', null !== $a464_p2 && (int) $a464_p2['cursor'] > $a464_first && 'rollback' === $a464_p2['reason'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='capacity_counters_rebuild_started' AND details LIKE '%rollback%'" ) );
$a464_buy = Orders::create( array( 'email' => 'a464-during@example.com', 'items' => array( array( 'ticket_type_id' => $a464_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a464-during' ) );
check( 'selling the last unit during the rebuild works', ! is_wp_error( $a464_buy ) && 0 === Orders::availability( $a464_type ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_capacity_rebuild_budget'] );
// The reconcile cron finishes the job (each run continues from the cursor).
$a464_runs = 0;
while ( 'building' === CapacityCounters::state() && $a464_runs < 20 ) {
	$a464_report = CapacityCounters::reconcile();
	++$a464_runs;
}
check( 'the reconcile cron completes the rebuild → verifying', 'verifying' === CapacityCounters::state() && false === get_option( CapacityCounters::REBUILD_OPTION ) && (bool) $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='capacity_counters_rebuilt' LIMIT 1" ) );
check( 'rebuilt counters include the sales of the old code', 10 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT sold_qty FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a464_type ) ) && ! CapacityCounters::drift( array( $a464_occ ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_capacity_verify_after'] = 0;
$GLOBALS['wpcap_test_filters']['wpcap_capacity_reconcile_confirm_us'] = 0;
CapacityCounters::reconcile();
unset( $GLOBALS['wpcap_test_filters']['wpcap_capacity_verify_after'], $GLOBALS['wpcap_test_filters']['wpcap_capacity_reconcile_confirm_us'] );
Orders::flush_availability_cache();
check( 'after a clean reconcile run the counters decide again – and the type is sold out', 'ready' === CapacityCounters::state() && CapacityCounters::enabled() && 0 === Orders::availability( $a464_type ) && is_wp_error( Orders::create( array( 'email' => 'a464-late@example.com', 'items' => array( array( 'ticket_type_id' => $a464_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a464-late' ) ) ) );

// A plain upgrade with "ready" counters still skips the rebuild.
CapacityCounters::after_install( false );
check( 'no rollback, counters ready: no rebuild', 'ready' === CapacityCounters::state() && false === get_option( CapacityCounters::REBUILD_OPTION ) );
// A rebuild stuck in "building" from older code (no cursor option) is resumed by the cron.
update_option( CapacityCounters::OPTION, array( 'state' => 'building', 'since' => time() - 7200 ) );
delete_option( CapacityCounters::REBUILD_OPTION );
CapacityCounters::reconcile();
check( 'a "building" state without cursor (interrupted older upgrade) is completed by the cron', 'verifying' === CapacityCounters::state() );

if ( false === $a464_state ) { delete_option( CapacityCounters::OPTION ); } else { update_option( CapacityCounters::OPTION, $a464_state ); }
if ( false === $a464_job ) { delete_option( CapacityCounters::REBUILD_OPTION ); } else { update_option( CapacityCounters::REBUILD_OPTION, $a464_job ); }
$GLOBALS['wpcap_test_mails'] = array();
$GLOBALS['wpcap_test_transients'] = array();
