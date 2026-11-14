<?php
/**
 * A428: occurrence/event cancellation and rescheduling notices run as resumable runs (BulkRuns):
 * batched status change and revocation, refunds as idempotent jobs, mails through the message
 * queue, progress on the admin pages, a second click resumes, an aborted send reaches everybody.
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Queries;
use PassCaptain\BulkRuns;
use PassCaptain\Notifications;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a428_settings = array( 'auto_refund_cancelled' => Settings::get( 'auto_refund_cancelled' ), 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'require_2fa_for_refunds' => Settings::get( 'require_2fa_for_refunds' ) );
Settings::update( array( 'auto_refund_cancelled' => 1, 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_filters']['wpcap_bulk_run_batch_size'] = 2;

/** Occurrence with $n paid single-ticket orders (one buyer each) and one open order. */
function a428_setup( $name, $n, $event = 0 ) {
	$event = $event ?: ex_event( $name );
	$occ = ex_occ( $event, 0, 400 );
	$type = ex_type( $occ, 'Standard', 1000 );
	$orders = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		list( $order ) = ex_buy( $type, 1, "a428-{$occ}-{$i}@example.com" );
		$orders[] = $order;
	}
	$GLOBALS['wpcap_test_transients'] = array();
	$open = Orders::create( array( 'email' => "a428-open-{$occ}@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'a428-open-' . uniqid( '', true ) ), array( 'payment_provider' => 'manual', 'hold_minutes' => 600 ) );
	return array( 'event' => $event, 'occ' => $occ, 'type' => $type, 'orders' => $orders, 'open' => is_wp_error( $open ) ? 0 : (int) $open['order_id'] );
}
function a428_valid( $occ ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", $occ ) );
}
/** Mails per recipient since $before. */
function a428_mail_counts( $before, $prefix, $subject = 'bsage' ) {
	$counts = array();
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $m ) {
		if ( 0 === strpos( (string) $m['to'], $prefix ) && ( '' === $subject || false !== strpos( (string) $m['subject'], $subject ) ) ) { $counts[ $m['to'] ] = ( $counts[ $m['to'] ] ?? 0 ) + 1; }
	}
	return $counts;
}
function a428_completed_refunds( array $orders ) {
	$n = 0;
	foreach ( $orders as $order ) { $n += count( array_filter( Refunds::for_order( $order ), static fn( $r ) => 'completed' === $r['status'] ) ); }
	return $n;
}
/** Throws on the $nth call of an action (simulates a request that dies mid-way). */
function a428_abort_on( $hook, $nth ) {
	$calls = 0;
	$cb = static function () use ( &$calls, $nth ) { if ( ++$calls === $nth ) { throw new RuntimeException( 'a428 abort' ); } };
	add_action( $hook, $cb, 1, 0 );
	return $cb;
}

add_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10, 1 );

echo "A428: occurrence cancellation aborted mid-way and resumed by a second click\n";
$a = a428_setup( 'A428 Konzert', 5 );
$before = count( $GLOBALS['wpcap_test_mails'] );
$abort = a428_abort_on( 'wpcap_ticket_revoked', 3 );
$a428_error = '';
try {
	EventsPage::cancel_occurrence( array( 'occurrence_id' => $a['occ'], 'reason' => 'Sturm', 'confirm' => '1' ) );
} catch ( RuntimeException $e ) {
	$a428_error = $e->getMessage();
}
remove_action( 'wpcap_ticket_revoked', $abort, 1 );
$run = BulkRuns::occurrence_run( $a['occ'] );
check( 'A428: the aborted request leaves a running run with status already cancelled', 'a428 abort' === $a428_error && 'cancelled' === Queries::occurrence( $a['occ'] )->status && $run && 'running' === $run->status && null === $run->locked_until );
check( 'A428: batched steps: open order cancelled, refunds planned as jobs, part of the tickets revoked', 'cancelled' === Orders::get( $a['open'] )->payment_status && 5 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='cancellation_refund' AND payload LIKE %s", '{"run_id":' . (int) $run->run_id . ',%' ) ) && a428_valid( $a['occ'] ) > 0 && a428_valid( $a['occ'] ) < 5 );
check( 'A428: holders of the revoked batches are already queued for the mail', BulkRuns::mail_counts( $run )['total'] >= 2 );
$resumed = EventsPage::cancel_occurrence( array( 'occurrence_id' => $a['occ'] ) );
$run = BulkRuns::occurrence_run( $a['occ'] );
check( 'A428: a second click resumes and finishes the cancellation', ! is_wp_error( $resumed ) && ! empty( $resumed['resumed'] ) && ! empty( $resumed['complete'] ) && 'complete' === $run->status && 0 === a428_valid( $a['occ'] ) );
check( 'A428: every paid order is refunded exactly once', 5 === a428_completed_refunds( $a['orders'] ) && 5 === (int) $resumed['summary']['refunds'] && 0 === (int) $resumed['summary']['refund_errors'] );
$counts = a428_mail_counts( $before, 'a428-' . $a['occ'] . '-' );
check( 'A428: every holder gets exactly one cancellation mail', 5 === count( $counts ) && array( 1 ) === array_values( array_unique( $counts ) ) && 5 === (int) $resumed['summary']['notified'] );
check( 'A428: summary counts all revoked tickets and the cancelled order', 5 === (int) $resumed['summary']['revoked'] && 1 === (int) $resumed['summary']['cancelled_orders'] );
$again = EventsPage::cancel_occurrence( array( 'occurrence_id' => $a['occ'] ) );
check( 'A428: a further click changes nothing (no mails, no refunds)', ! is_wp_error( $again ) && 5 === a428_completed_refunds( $a['orders'] ) && $counts === a428_mail_counts( $before, 'a428-' . $a['occ'] . '-' ) );

$_GET = array( 'page' => 'pass-captain', 'view' => 'occurrence', 'occurrence_id' => $a['occ'] );
ob_start();
try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$html = (string) ob_get_clean();
$_GET = array();
check( 'A428: the occurrence page shows the progress of the cancellation', false === strpos( $html, 'EXCEPTION' ) && false !== strpos( $html, 'wpcap-bulk-runs' ) && false !== strpos( $html, '5 Tickets widerrufen' ) && false !== strpos( $html, '5 von 5 E-Mails versendet' ) );

echo "A428: an aborted run is continued by its background job\n";
$b = a428_setup( 'A428 Theater', 4 );
$before = count( $GLOBALS['wpcap_test_mails'] );
$abort = a428_abort_on( 'wpcap_ticket_revoked', 1 );
try {
	EventsPage::cancel_occurrence( array( 'occurrence_id' => $b['occ'], 'reason' => 'Krankheit', 'confirm' => '1' ) );
} catch ( RuntimeException $e ) {}
remove_action( 'wpcap_ticket_revoked', $abort, 1 );
$run_b = BulkRuns::occurrence_run( $b['occ'] );
$_GET = array( 'page' => 'pass-captain', 'view' => 'cancel_occurrence', 'occurrence_id' => $b['occ'] );
ob_start();
EventsPage::render();
$pending_html = (string) ob_get_clean();
$_GET = array();
check( 'A428: the cancellation page of an unfinished run offers to continue', false !== strpos( $pending_html, 'wpcap_resume_bulk_run' ) && false !== strpos( $pending_html, 'läuft' ) );
$handled = BulkRuns::handle_job( false, 'bulk_run', array( 'run_id' => (int) $run_b->run_id ), 0 );
$run_b = BulkRuns::get( $run_b->run_id );
check( 'A428: the bulk_run job finishes the run', true === $handled && 'complete' === $run_b->status && 0 === a428_valid( $b['occ'] ) && 4 === a428_completed_refunds( $b['orders'] ) && 4 === count( a428_mail_counts( $before, 'a428-' . $b['occ'] . '-' ) ) );
check( 'A428: other job types are passed through', 'x' === BulkRuns::handle_job( 'x', 'ticket_mail_retry', array(), 0 ) );

echo "A428: resume button (admin-post processing)\n";
$c = a428_setup( 'A428 Oper', 2 );
$abort = a428_abort_on( 'wpcap_ticket_revoked', 1 );
try { EventsPage::cancel_occurrence( array( 'occurrence_id' => $c['occ'], 'reason' => 'x', 'confirm' => '1' ) ); } catch ( RuntimeException $e ) {}
remove_action( 'wpcap_ticket_revoked', $abort, 1 );
$run_c = BulkRuns::occurrence_run( $c['occ'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = EventsPage::resume_bulk_run( array( 'run_id' => $run_c->run_id ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$ok = EventsPage::resume_bulk_run( array( 'run_id' => $run_c->run_id ) );
check( 'A428: resuming a run checks the event rights and finishes it', is_wp_error( $denied ) && ! is_wp_error( $ok ) && $ok['complete'] && 0 === a428_valid( $c['occ'] ) );

echo "A428: event cancellation is resumable\n";
$ev = ex_event( 'A428 Festival' );
$e1 = a428_setup( '', 2, $ev );
$e2 = a428_setup( '', 2, $ev );
$before = count( $GLOBALS['wpcap_test_mails'] );
$abort = a428_abort_on( 'wpcap_occurrence_cancelled', 2 );
$ev_error = '';
try {
	EventsPage::cancel_event( array( 'event_id' => $ev, 'reason' => 'Unwetter', 'confirm' => '1' ) );
} catch ( RuntimeException $e ) {
	$ev_error = $e->getMessage();
}
remove_action( 'wpcap_occurrence_cancelled', $abort, 1 );
$ev_run = BulkRuns::event_run( $ev );
check( 'A428: event mails are queued before the occurrences are processed', 'a428 abort' === $ev_error && 'cancelled' === Queries::event( $ev )->status && $ev_run && 'running' === $ev_run->status && 6 === BulkRuns::mail_counts( $ev_run )['total'] && a428_valid( $e2['occ'] ) > 0 );
$ev_res = EventsPage::cancel_event( array( 'event_id' => $ev ) );
$counts = a428_mail_counts( $before, 'a428-' );
check( 'A428: second click on a cancelled event resumes (no reason needed) and finishes', ! is_wp_error( $ev_res ) && ! empty( $ev_res['resumed'] ) && ! empty( $ev_res['complete'] ) && 0 === a428_valid( $e1['occ'] ) + a428_valid( $e2['occ'] ) );
check( 'A428: one event mail per buyer, none per occurrence, all orders refunded once', 6 === count( $counts ) && array( 1 ) === array_values( array_unique( $counts ) ) && 6 === (int) $ev_res['notified_count'] && 4 === a428_completed_refunds( array_merge( $e1['orders'], $e2['orders'] ) ) && 4 === (int) $ev_res['refunds'] );
check( 'A428: open orders of the event are cancelled', 'cancelled' === Orders::get( $e1['open'] )->payment_status && 'cancelled' === Orders::get( $e2['open'] )->payment_status );

remove_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10 );

echo "A428: rescheduling notices reach every holder after an aborted send\n";
add_action( 'wpcap_occurrence_rescheduled', array( Notifications::class, 'occurrence_rescheduled' ), 10, 3 );
add_action( 'wpcap_occurrence_changed', array( Notifications::class, 'occurrence_changed' ), 10, 3 );
$r = a428_setup( 'A428 Lesung', 5 );
$row = Queries::occurrence( $r['occ'] );
$before = count( $GLOBALS['wpcap_test_mails'] );
$abort = a428_abort_on( 'wpcap_mail_before_send', 2 );
$res_error = '';
try {
	EventsPage::save_occurrence( array( 'occurrence_id' => $r['occ'], 'starts_at' => PassCaptain\Admin\Forms::utc_to_local( gmdate( 'Y-m-d H:i:s', strtotime( $row->starts_at . ' UTC' ) + 3600 ), 'Europe/Vienna' ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => '0' ) );
} catch ( RuntimeException $e ) {
	$res_error = $e->getMessage();
}
remove_action( 'wpcap_mail_before_send', $abort, 1 );
$sent_now = count( a428_mail_counts( $before, 'a428-' . $r['occ'] . '-', '' ) );
// The request died: claims and leases are released on shutdown.
PassCaptain\Jobs\TimeBudget::release_claims();
BulkRuns::release_leases();
$notify_runs = BulkRuns::for_object( 'notify', $r['occ'] );
check( 'A428: abort while sending: one mail out, every holder queued', 'a428 abort' === $res_error && 1 === $sent_now && 1 === count( $notify_runs ) && 5 === BulkRuns::mail_counts( $notify_runs[0] )['total'] );
BulkRuns::handle_job( false, 'bulk_run', array( 'run_id' => (int) $notify_runs[0]->run_id ), 0 );
$counts = a428_mail_counts( $before, 'a428-' . $r['occ'] . '-', '' );
check( 'A428: the continuation sends the rest; nobody gets two mails', 5 === count( $counts ) && array( 1 ) === array_values( array_unique( $counts ) ) && 'complete' === BulkRuns::get( $notify_runs[0]->run_id )->status );
remove_action( 'wpcap_occurrence_rescheduled', array( Notifications::class, 'occurrence_rescheduled' ), 10 );
remove_action( 'wpcap_occurrence_changed', array( Notifications::class, 'occurrence_changed' ), 10 );

echo "A428: schema and documentation\n";
check( 'A428: runs table exists and the schema version is raised', BulkRuns::ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.13', '>=' ) );
$docs = (string) file_get_contents( WPCAP_PATH . 'docs/OPERATIONS.md' ) . file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
check( 'A428: behaviour and hooks are documented', false !== strpos( $docs, 'wpcap_occurrence_cancellation_step' ) && false !== strpos( $docs, 'wpcap_bulk_run_completed' ) && false !== strpos( $docs, 'bulk_run' ) );

unset( $GLOBALS['wpcap_test_filters']['wpcap_bulk_run_batch_size'] );
Settings::update( $a428_settings );
