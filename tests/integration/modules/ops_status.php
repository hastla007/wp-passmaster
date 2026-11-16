<?php
/**
 * System status page, worker heartbeats, queue retry/discard actions, diagnose/reconciliation
 * checks with their idempotent repairs, manual review of stuck refunds, the dbDelta backoff and
 * the WP-CLI data functions.
 */

// phpcs:ignoreFile

use PassCaptain\Admin\StatusPage;
use PassCaptain\Communications;
use PassCaptain\Diagnostics;
use PassCaptain\Installer;
use PassCaptain\Jobs;
use PassCaptain\Ops\Cli;
use PassCaptain\Ops\Heartbeat;
use PassCaptain\Ops\Status;
use PassCaptain\Webhooks\Dispatcher;

/* Stubs identical to those of later test files (only defined when missing). */
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['wpcap_test_options'][ $k ] ); return true; } }

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
Status::flush_cache();
$ops_now = current_time( 'mysql', true );
$ops_ago = static function ( $seconds ) { return gmdate( 'Y-m-d H:i:s', time() - $seconds ); };
$ops_job = static function ( $status, $extra = array() ) use ( $wpdb, $p, $ops_now ) {
	$wpdb->insert( "{$p}wpcap_jobs", array_merge( array( 'idempotency_key' => hash( 'sha256', uniqid( 'ops', true ) ), 'job_type' => 'ops_test', 'payload' => '{}', 'run_after' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ), 'status' => $status, 'attempts' => 8, 'last_error' => 'No handler acknowledged the job.', 'created_at' => $ops_now, 'updated_at' => $ops_now ), $extra ) );
	return (int) $wpdb->insert_id;
};
$ops_message = static function ( $status, $extra = array() ) use ( $wpdb, $p, $ops_now ) {
	$wpdb->insert( "{$p}wpcap_message_queue", array_merge( array( 'dedupe_key' => hash( 'sha256', uniqid( 'opsm', true ) ), 'recipient' => 'ops-buyer@example.com', 'template_key' => 'ops_test', 'payload' => '{}', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ), 'status' => $status, 'attempts' => 5, 'last_error' => 'Template fehlt oder wp_mail ist fehlgeschlagen.', 'created_at' => $ops_now ), $extra ) );
	return (int) $wpdb->insert_id;
};
$ops_field = static function ( $table, $id_col, $id, $col ) use ( $wpdb, $p ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT $col FROM {$p}$table WHERE $id_col=%d", $id ) );
};
$ops_audits = static function ( $action ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s", $action ) );
};

/* ------------------------------------------------------------------------------------------ */
echo "Ops: worker heartbeats\n";
foreach ( array( 'jobs', 'messages', 'webhooks', 'sms', 'cleanup' ) as $w ) { unset( $GLOBALS['wpcap_test_options'][ Heartbeat::OPTION_PREFIX . $w ] ); }
check( 'no heartbeat recorded yet means stale', null === Heartbeat::last( 'jobs' ) && Heartbeat::is_stale( 'jobs' ) );
$t0 = time();
Jobs::process_due( 1 );
Communications::process_due();
Dispatcher::process( 1 );
PassCaptain\Sms\Service::process_due( 1 );
Heartbeat::register();
do_action( 'wpcap_cleanup_reservations' );
$beats = array();
foreach ( array( 'jobs', 'messages', 'webhooks', 'sms', 'cleanup' ) as $w ) { $beats[ $w ] = Heartbeat::last( $w ); }
check( 'every worker records a heartbeat at the end of its run', ! in_array( null, $beats, true ) && min( array_map( static function ( $b ) { return $b['time']; }, $beats ) ) >= $t0 );
check( 'heartbeat stores the processed count and is fresh', is_int( $beats['jobs']['processed'] ) && ! Heartbeat::is_stale( 'jobs' ) && ! Heartbeat::is_stale( 'cleanup' ) );
check( 'heartbeat older than four intervals is stale', Heartbeat::is_stale( 'jobs', time() + 21 * 60 ) && ! Heartbeat::is_stale( 'jobs', time() + 19 * 60 ) && ! Heartbeat::is_stale( 'cleanup', time() + 3 * 3600 ) );

/* ------------------------------------------------------------------------------------------ */
echo "Ops: WP-Cron health\n";
PassCaptain\Ops\Heartbeat::beat( 'retention' ); // R3: the retention run reports a heartbeat too.
$hooks = array( 'wpcap_process_jobs' => time() + 120, 'wpcap_process_messages' => time() + 60, 'wpcap_cleanup_reservations' => time() + 1800, 'wpcap_privacy_retention' => time() + 3600 );
$by_hook = static function ( array $rows ) { $out = array(); foreach ( $rows as $r ) { $out[ $r['hook'] ] = $r; } return $out; };
$h = $by_hook( Status::cron_health( null, $hooks, false ) );
check( 'scheduled hooks with fresh heartbeats are ok and show next and last run', 'ok' === $h['wpcap_process_jobs']['state'] && $h['wpcap_process_jobs']['next_run'] === $hooks['wpcap_process_jobs'] && $h['wpcap_process_jobs']['last_beat'] >= $t0 && 'ok' === $h['wpcap_privacy_retention']['state'] );
$h = $by_hook( Status::cron_health( null, array( 'wpcap_process_jobs' => false ) + $hooks, false ) );
check( 'unscheduled hook is an error', 'error' === $h['wpcap_process_jobs']['state'] && false !== strpos( $h['wpcap_process_jobs']['message'], 'Nicht geplant' ) );
update_option( Heartbeat::OPTION_PREFIX . 'jobs', array( 'time' => time() - 3600, 'processed' => 3 ) );
$h = $by_hook( Status::cron_health( null, $hooks, true ) );
check( 'DISABLE_WP_CRON without a recent heartbeat is an error naming the external cron', 'error' === $h['wpcap_process_jobs']['state'] && false !== strpos( $h['wpcap_process_jobs']['message'], 'DISABLE_WP_CRON' ) );
$h = $by_hook( Status::cron_health( null, $hooks, false ) );
check( 'stale heartbeat with WP-Cron enabled is an error (cron not triggered)', 'error' === $h['wpcap_process_jobs']['state'] && false !== strpos( $h['wpcap_process_jobs']['message'], 'Server-Cron' ) );
unset( $GLOBALS['wpcap_test_options'][ Heartbeat::OPTION_PREFIX . 'jobs' ] );
$h = $by_hook( Status::cron_health( null, $hooks, true ) );
check( 'never-run worker with DISABLE_WP_CRON is a warning', 'warning' === $h['wpcap_process_jobs']['state'] );
Heartbeat::beat( 'jobs', 1 );
$h = $by_hook( Status::cron_health( null, array( 'wpcap_process_jobs' => time() - 1200 ) + $hooks, false ) );
check( 'overdue scheduled run is a warning', 'warning' === $h['wpcap_process_jobs']['state'] && false !== strpos( $h['wpcap_process_jobs']['message'], 'überfällig' ) );

/* ------------------------------------------------------------------------------------------ */
echo "Ops: queue summaries and failed items\n";
$dead = $ops_job( 'dead_letter' );
$dead2 = $ops_job( 'dead_letter' );
$long = $ops_job( 'running', array( 'locked_at' => $ops_ago( 600 ) ) );
$overdue_job = $ops_job( 'queued', array( 'run_after' => $ops_ago( 3600 ), 'attempts' => 0 ) );
$failed_msg = $ops_message( 'failed' );
$s = Status::queue_summary( 'jobs' );
check( 'job summary counts dead letters, overdue and long-running claims', $s['failed'] >= 2 && $s['overdue'] >= 1 && $s['long_running'] >= 1 && ! isset( $s['counts']['complete'] ) );
$backlog = array();
foreach ( Status::job_backlog() as $row ) { $backlog[ $row->status . '/' . $row->job_type ] = (int) $row->n; }
check( 'job backlog is grouped by status and type', ( $backlog['dead_letter/ops_test'] ?? 0 ) >= 2 && ( $backlog['running/ops_test'] ?? 0 ) >= 1 );
$ids = array_map( static function ( $r ) { return (int) $r->id; }, Status::failed_items( 'jobs' ) );
check( 'failed job list contains the dead letters with last_error', in_array( $dead, $ids, true ) && 'No handler acknowledged the job.' === Status::failed_items( 'jobs', 1 )[0]->last_error );
$mail_errors = array_map( static function ( $r ) { return $r->last_error; }, Status::email_failures() );
check( 'e-mail transport failures are listed from the message queue', in_array( 'Template fehlt oder wp_mail ist fehlgeschlagen.', $mail_errors, true ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id IN (%d,%d)", $long, $overdue_job ) );

echo "Ops: retry and discard with capability, nonce and audit\n";
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$denied = StatusPage::queue_action( array( 'queue' => 'jobs', 'id' => $dead, 'op' => 'retry' ) );
check( 'retry without manage_options is forbidden and changes nothing', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] && 'dead_letter' === $ops_field( 'wpcap_jobs', 'job_id', $dead, 'status' ) );
$_POST = array( 'queue' => 'jobs', 'id' => $dead, 'op' => 'retry' );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$died = false;
try { StatusPage::handle_queue_action(); } catch ( Wpcap_Test_Die $e ) { $died = true; } catch ( Wpcap_Test_Redirect $e ) { $died = false; }
check( 'handler checks the item nonce and dies with 403 for users without capability', $died && in_array( 'wpcap_ops_queue_jobs_' . $dead, $GLOBALS['wpcap_test_nonce_checks'], true ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$audits = $ops_audits( 'ops_queue_retry' );
$redirect = '';
try { StatusPage::handle_queue_action(); } catch ( Wpcap_Test_Redirect $e ) { $redirect = $e->getMessage(); }
check( 'retry requeues the dead letter (attempts reset, due now) and redirects to the status page', 'queued' === $ops_field( 'wpcap_jobs', 'job_id', $dead, 'status' ) && 0 === (int) $ops_field( 'wpcap_jobs', 'job_id', $dead, 'attempts' ) && strtotime( $ops_field( 'wpcap_jobs', 'job_id', $dead, 'run_after' ) ) <= time() && false !== strpos( $redirect, 'page=wpcap-status' ) );
check( 'retry is audited', $audits + 1 === $ops_audits( 'ops_queue_retry' ) );
$again = StatusPage::queue_action( array( 'queue' => 'jobs', 'id' => $dead, 'op' => 'retry' ) );
check( 'second retry of the same item is refused (no double requeue)', is_wp_error( $again ) && 'wpcap_ops_state' === $again->get_error_code() && $audits + 1 === $ops_audits( 'ops_queue_retry' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=%d", $dead ) );
$discarded = StatusPage::queue_action( array( 'queue' => 'jobs', 'id' => $dead2, 'op' => 'discard' ) );
check( 'discard marks the dead letter cancelled and audits it', true === $discarded && 'cancelled' === $ops_field( 'wpcap_jobs', 'job_id', $dead2, 'status' ) && $ops_audits( 'ops_queue_discard' ) >= 1 );
check( 'discard of a non-failed item is refused', is_wp_error( StatusPage::queue_action( array( 'queue' => 'jobs', 'id' => $dead2, 'op' => 'discard' ) ) ) );
check( 'unknown queue or operation is rejected', is_wp_error( StatusPage::queue_action( array( 'queue' => 'wpcap_orders', 'id' => 1, 'op' => 'retry' ) ) ) && is_wp_error( StatusPage::queue_action( array( 'queue' => 'jobs', 'id' => $dead2, 'op' => 'delete' ) ) ) );
check( 'failed e-mail can be retried', true === StatusPage::queue_action( array( 'queue' => 'messages', 'id' => $failed_msg, 'op' => 'retry' ) ) && 'queued' === $ops_field( 'wpcap_message_queue', 'message_id', $failed_msg, 'status' ) && 0 === (int) $ops_field( 'wpcap_message_queue', 'message_id', $failed_msg, 'attempts' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_message_queue SET status='failed' WHERE message_id=%d", $failed_msg ) );
check( 'failed e-mail can be discarded (cancelled, picked up by retention)', true === StatusPage::queue_action( array( 'queue' => 'messages', 'id' => $failed_msg, 'op' => 'discard' ) ) && 'cancelled' === $ops_field( 'wpcap_message_queue', 'message_id', $failed_msg, 'status' ) );
$sub = Dispatcher::create_subscription( 'https://ops.hooks.example/in', array( 'order.paid' ) );
$wpdb->insert( "{$p}wpcap_webhook_deliveries", array( 'subscription_id' => $sub['subscription_id'], 'event_id' => 'evt_ops_' . uniqid(), 'event_type' => 'order.paid', 'payload' => '{"id":"evt_ops","data":{"object":{"order_id":1}}}', 'status' => 'failed', 'attempts' => 8, 'last_error' => 'HTTP 500', 'next_attempt_at' => $ops_now, 'created_at' => $ops_now, 'updated_at' => $ops_now ) );
$delivery = (int) $wpdb->insert_id;
check( 'failed webhook delivery is redelivered via the dispatcher', true === StatusPage::queue_action( array( 'queue' => 'webhooks', 'id' => $delivery, 'op' => 'retry' ) ) && 'pending' === $ops_field( 'wpcap_webhook_deliveries', 'delivery_id', $delivery, 'status' ) );
Dispatcher::delete_subscription( $sub['subscription_id'] );
$wpdb->insert( "{$p}wpcap_sms_messages", array( 'dedupe_key' => hash( 'sha256', uniqid( 'opss', true ) ), 'consent_id' => 0, 'order_id' => 0, 'occurrence_id' => 0, 'purpose' => 'reminder', 'body' => 'x', 'status' => 'failed', 'attempts' => 4, 'last_error' => 'provider down', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ), 'created_at' => $ops_now, 'updated_at' => $ops_now ) );
$sms = (int) $wpdb->insert_id;
check( 'failed SMS can be retried and discarded', true === StatusPage::queue_action( array( 'queue' => 'sms', 'id' => $sms, 'op' => 'retry' ) ) && 'queued' === $ops_field( 'wpcap_sms_messages', 'message_id', $sms, 'status' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_sms_messages WHERE message_id=%d", $sms ) );

/* ------------------------------------------------------------------------------------------ */
echo "Ops: critical issues and admin notice\n";
$notice_dead = $ops_job( 'dead_letter' );
$issues = Status::issues();
check( 'dead letters are a critical issue', (bool) array_filter( $issues, static function ( $i ) { return 'error' === $i['severity'] && false !== strpos( $i['message'], 'Job-Queue' ); } ) );
Status::flush_issues();
$_GET = array( 'page' => 'wpcap-orders' );
ob_start(); StatusPage::render_backlog_notice(); $notice = ob_get_clean();
check( 'admin notice on plugin pages links to the status page', false !== strpos( $notice, 'notice-error' ) && false !== strpos( $notice, 'page=wpcap-status' ) && false !== strpos( $notice, 'Job-Queue' ) );
check( 'critical issues are cached for the notice', is_array( get_transient( Status::ISSUES_TRANSIENT ) ) );
$_GET = array( 'page' => 'wpcap-status' );
ob_start(); StatusPage::render_backlog_notice(); $own = ob_get_clean();
$_GET = array( 'page' => 'woocommerce' );
ob_start(); StatusPage::render_backlog_notice(); $foreign = ob_get_clean();
$_GET = array( 'page' => 'wpcap-orders' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
ob_start(); StatusPage::render_backlog_notice(); $nocap = ob_get_clean();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'no notice on the status page itself, on foreign pages or without capability', '' === $own && '' === $foreign && '' === $nocap );
check( 'menu title shows the number of critical issues', false !== strpos( StatusPage::menu_title(), 'awaiting-mod' ) );
StatusPage::queue_action( array( 'queue' => 'jobs', 'id' => $notice_dead, 'op' => 'discard' ) );
check( 'an operator action drops the cached issues', false === get_transient( Status::ISSUES_TRANSIENT ) );
$_GET = array();

/* ------------------------------------------------------------------------------------------ */
echo "Ops: diagnostics detect seeded inconsistencies, repairs are idempotent\n";
$diag = static function ( $key ) { return Diagnostics::run( array( $key ) )['checks'][ $key ]; };
check( 'clean schema check reports ok', 'ok' === $diag( 'schema' )['severity'] && 0 === $diag( 'schema' )['count'] );

// Expired holds.
$type = make_type( 20 );
wpcap_test_reset_transients();
$hold = PassCaptain\Orders::create( array( 'email' => 'ops-hold@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'ops-hold-' . uniqid() ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", $ops_ago( 7200 ), $hold['order_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s WHERE order_id=%d", $ops_ago( 7200 ), $hold['order_id'] ) );
$wpdb->insert( "{$p}wpcap_seat_holds", array( 'occurrence_id' => 1, 'seat_id' => 999001, 'order_id' => 0, 'request_key' => hash( 'sha256', 'ops-seat' . uniqid() ), 'expires_at' => $ops_ago( 7200 ), 'status' => 'active' ) );
$seat_hold = (int) $wpdb->insert_id;
$c = $diag( 'expired_holds' );
check( 'expired-but-open reservations, seat holds and orders are detected', $c['count'] >= 3 && 'warning' === $c['severity'] && $c['repairable'] && $c['samples'] );
$r = Diagnostics::repair( 'expired_holds' );
check( 'repair releases holds and expires the order', ! is_wp_error( $r ) && $r['repaired'] >= 3 && 0 === $r['report']['count'] && 'expired' === PassCaptain\Orders::get( $hold['order_id'] )->payment_status && 'expired' === $ops_field( 'wpcap_seat_holds', 'hold_id', $seat_hold, 'status' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active'", $hold['order_id'] ) ) );
$r2 = Diagnostics::repair( 'expired_holds' );
check( 'second repair changes nothing', ! is_wp_error( $r2 ) && 0 === $r2['repaired'] );
check( 'repair is audited', $ops_audits( 'ops_diagnostics_repair' ) >= 2 );

// Paid order without tickets.
$paid = admin_paid_order( $type, 2, 'ops-paid@example.com' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id DESC LIMIT 1", $paid ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET updated_at=%s WHERE order_id=%d", $ops_ago( 3600 ), $paid ) );
$c = $diag( 'paid_without_tickets' );
$sample_ids = array_map( static function ( $s ) { return (int) $s['order_id']; }, $c['samples'] );
check( 'paid order missing a ticket is detected with the missing count', in_array( $paid, $sample_ids, true ) && 1 === (int) $c['samples'][ array_search( $paid, $sample_ids, true ) ]['missing'] && 'error' === $c['severity'] );
$jobs_for = static function ( $order_id ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='ticket_issue' AND status='queued' AND payload=%s", wp_json_encode( array( 'order_id' => $order_id ) ) ) ); };
$r = Diagnostics::repair( 'paid_without_tickets' );
check( 'repair queues one ticket_issue job for the order', ! is_wp_error( $r ) && 1 === $jobs_for( $paid ) );
Diagnostics::repair( 'paid_without_tickets' );
check( 'repeated repair does not queue a second job while one is open', 1 === $jobs_for( $paid ) );
PassCaptain\Orders::handle_job( false, 'ticket_issue', array( 'order_id' => $paid ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_type='ticket_issue' AND payload=%s", wp_json_encode( array( 'order_id' => $paid ) ) ) );
$after = array_map( static function ( $s ) { return (int) $s['order_id']; }, $diag( 'paid_without_tickets' )['samples'] );
check( 'the queued issuance job restores the ticket and the finding disappears', ! in_array( $paid, $after, true ) && 0 === PassCaptain\Orders::missing_tickets( $paid ) );

// Tickets without valid order.
$before = $diag( 'tickets_without_order' )['count'];
$wpdb->insert( "{$p}wpcap_tickets", array( 'public_id' => substr( md5( uniqid() ), 0, 32 ), 'order_id' => 99999901, 'ticket_type_id' => $type, 'token_hash' => hash( 'sha256', uniqid() ), 'status' => 'valid', 'issued_at' => $ops_now ) );
$orphan = (int) $wpdb->insert_id;
$c = $diag( 'tickets_without_order' );
check( 'valid ticket without order is detected and not auto-repairable', $before + 1 === $c['count'] && ! $c['repairable'] && is_wp_error( Diagnostics::repair( 'tickets_without_order' ) ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_tickets WHERE ticket_id=%d", $orphan ) );

// Duplicate refunds and refund helpers.
$refund_row = static function ( $order_id, $status, $reference, $ticket_ids = array(), $updated = null ) use ( $wpdb, $p, $ops_now ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $order_id, 'idempotency_key' => hash( 'sha256', uniqid( 'opsr', true ) ), 'amount_minor' => 2500, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => wp_json_encode( $ticket_ids ), 'reason' => 'ops test', 'status' => $status, 'provider_reference' => $reference, 'created_at' => $ops_now, 'updated_at' => $updated ?? $ops_now ) );
	return (int) $wpdb->insert_id;
};
$before = $diag( 'duplicate_refunds' )['count'];
$dup1 = $refund_row( 99999902, 'completed', 're_ops_dup' );
$dup2 = $refund_row( 99999902, 'completed', 're_ops_dup' );
$refund_row( 99999903, 'completed', 'manual' );
$refund_row( 99999903, 'completed', 'manual' );
$c = $diag( 'duplicate_refunds' );
check( 'two refunds with the same provider reference are detected (manual ignored)', $before + 1 === $c['count'] && in_array( 're_ops_dup', array_map( static function ( $s ) { return $s['provider_reference']; }, $c['samples'] ), true ) && ! $c['repairable'] );
$wpdb->query( "DELETE FROM {$p}wpcap_refunds WHERE order_id IN (99999902,99999903)" );

// Stale claims.
$stale_job = $ops_job( 'running', array( 'locked_at' => $ops_ago( 3600 ), 'attempts' => 1 ) );
$stale_msg = $ops_message( 'sending', array( 'locked_at' => $ops_ago( 3600 ), 'attempts' => 1 ) );
$c = $diag( 'stale_claims' );
check( 'claims older than 15 minutes are detected per queue', $c['count'] >= 2 && in_array( 'jobs', array_map( static function ( $s ) { return $s['queue']; }, $c['samples'] ), true ) );
$r = Diagnostics::repair( 'stale_claims' );
check( 'repair returns stale claims to the queue', ! is_wp_error( $r ) && $r['repaired'] >= 2 && 'queued' === $ops_field( 'wpcap_jobs', 'job_id', $stale_job, 'status' ) && 'queued' === $ops_field( 'wpcap_message_queue', 'message_id', $stale_msg, 'status' ) && null === $ops_field( 'wpcap_jobs', 'job_id', $stale_job, 'locked_at' ) );
$r2 = Diagnostics::repair( 'stale_claims' );
check( 'second stale-claim repair finds nothing', ! is_wp_error( $r2 ) && 0 === $r2['repaired'] );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_jobs WHERE job_id=%d", $stale_job ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_message_queue WHERE message_id=%d", $stale_msg ) );

// Unbooked provider refund.
$ub_order = admin_paid_order( $type, 1, 'ops-unbooked@example.com' );
$ub_ticket = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d", $ub_order ) );
$ub = $refund_row( $ub_order, 'processing', 're_ops_paid_out', array( $ub_ticket ) );
$c = $diag( 'unbooked_refunds' );
check( 'paid-out but unbooked refund is detected', $c['count'] >= 1 && $c['repairable'] && in_array( $ub, array_map( static function ( $s ) { return (int) $s['refund_id']; }, $c['samples'] ), true ) );
$r = Diagnostics::repair( 'unbooked_refunds' );
check( 'repair books it via Refunds::reconcile (ticket revoked)', ! is_wp_error( $r ) && 'completed' === $ops_field( 'wpcap_refunds', 'refund_id', $ub, 'status' ) && 'revoked' === $ops_field( 'wpcap_tickets', 'ticket_id', $ub_ticket, 'status' ) );
$r2 = Diagnostics::repair( 'unbooked_refunds' );
check( 'repeated reconcile books nothing twice', ! is_wp_error( $r2 ) && 0 === $r2['repaired'] && 0 === $r2['report']['count'] );

// Refunds stuck in processing without provider reference: manual review.
$st_order = admin_paid_order( $type, 2, 'ops-stuck@example.com' );
$st_tickets = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $st_order ) ) );
$st_fail = $refund_row( $st_order, 'processing', '', array( $st_tickets[0] ), $ops_ago( 3600 ) );
$st_done = $refund_row( $st_order, 'processing', '', array( $st_tickets[1] ), $ops_ago( 3600 ) );
$fresh = $refund_row( $st_order, 'processing', '', array(), $ops_now );
$c = $diag( 'stuck_refunds' );
$stuck_ids = array_map( static function ( $s ) { return (int) $s['refund_id']; }, $c['samples'] );
check( 'refunds stuck in processing without reference are listed (fresh ones not yet)', in_array( $st_fail, $stuck_ids, true ) && in_array( $st_done, $stuck_ids, true ) && ! in_array( $fresh, $stuck_ids, true ) && ! $c['repairable'] );
check( 'stuck refunds raise a critical issue', (bool) array_filter( Status::issues(), static function ( $i ) { return false !== strpos( $i['message'], 'processing' ); } ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$no = StatusPage::resolve_refund( array( 'refund_id' => $st_fail, 'resolution' => 'failed' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'manual resolution needs manage_options', is_wp_error( $no ) && 403 === $no->get_error_data()['status'] && 'processing' === $ops_field( 'wpcap_refunds', 'refund_id', $st_fail, 'status' ) );
$res = StatusPage::resolve_refund( array( 'refund_id' => $st_fail, 'resolution' => 'failed', 'reference' => 'PSP shows no payout' ) );
check( 'mark as failed: refund failed (retryable), tickets untouched, audited', is_array( $res ) && 'failed' === $res['status'] && 'valid' === $ops_field( 'wpcap_tickets', 'ticket_id', $st_tickets[0], 'status' ) && false !== strpos( $res['error_message'], 'Manuell' ) && $ops_audits( 'refund_manual_resolution' ) >= 1 );
check( 'resolving twice is refused', is_wp_error( StatusPage::resolve_refund( array( 'refund_id' => $st_fail, 'resolution' => 'completed' ) ) ) );
$res = StatusPage::resolve_refund( array( 'refund_id' => $st_done, 'resolution' => 'completed', 'reference' => 're_PSP_123' ) );
check( 'mark as completed: booked with the given reference, ticket revoked', is_array( $res ) && 'completed' === $res['status'] && 'manual-review:re_PSP_123' === $res['provider_reference'] && 'revoked' === $ops_field( 'wpcap_tickets', 'ticket_id', $st_tickets[1], 'status' ) );
check( 'invalid resolution is rejected', is_wp_error( StatusPage::resolve_refund( array( 'refund_id' => $fresh, 'resolution' => 'deleted' ) ) ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_refunds WHERE refund_id=%d", $fresh ) );

// Status page actions: diagnose stores a report; capability checks.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$no_diag = StatusPage::diagnose();
$no_repair = StatusPage::repair( array( 'check' => 'stale_claims' ) );
$no_schema = StatusPage::schema_retry();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'diagnose, repair and schema retry need manage_options', is_wp_error( $no_diag ) && is_wp_error( $no_repair ) && is_wp_error( $no_schema ) && 403 === $no_schema->get_error_data()['status'] );
$report = StatusPage::diagnose();
check( 'diagnose runs every check and stores the report', is_array( $report ) && count( Diagnostics::checks() ) === count( $report['checks'] ) && get_option( Diagnostics::REPORT_OPTION ) === $report );
check( 'schema retry with a current schema reports that nothing is to do', is_wp_error( StatusPage::schema_retry() ) && 'wpcap_ops_schema_ok' === StatusPage::schema_retry()->get_error_code() );

echo "Ops: status page renders\n";
$render_dead = $ops_job( 'dead_letter', array( 'last_error' => 'ops render marker' ) );
ob_start();
StatusPage::render();
$html = ob_get_clean();
check( 'status page shows cron table, queues, failed items with actions and the diagnose report', false !== strpos( $html, 'WP-Cron und Worker' ) && false !== strpos( $html, 'wpcap_process_jobs' ) && false !== strpos( $html, 'ops render marker' ) && false !== strpos( $html, 'value="wpcap_ops_queue_action"' ) && false !== strpos( $html, 'Letzte Diagnose' ) && false !== strpos( $html, 'Datenbankschema' ) );
check( 'status page masks e-mail recipients', false === strpos( $html, 'ops-buyer@example.com' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$blocked = false;
try { StatusPage::render(); } catch ( Wpcap_Test_Die $e ) { $blocked = true; }
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'status page is not rendered without manage_options', $blocked );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_jobs WHERE job_id=%d", $render_dead ) );
check( 'status page handlers are registered as admin-post actions', isset( PassCaptain\Admin\Menu::handlers()['wpcap_ops_queue_action'], PassCaptain\Admin\Menu::handlers()['wpcap_ops_refund_resolve'], PassCaptain\Admin\Menu::handlers()['wpcap_ops_schema_retry'] ) );

echo "Ops: WP-CLI data\n";
$cli_stale = $ops_job( 'running', array( 'locked_at' => $ops_ago( 3600 ), 'attempts' => 1 ) );
$cli = Cli::diagnose_data( array( 'repair' => 'stale_claims' ) );
check( 'wp pass-captain diagnose --repair runs the repair and reports every check', isset( $cli['repairs']['stale_claims'] ) && $cli['repairs']['stale_claims'] >= 1 && count( Diagnostics::checks() ) === count( $cli['rows'] ) && 'queued' === $ops_field( 'wpcap_jobs', 'job_id', $cli_stale, 'status' ) );
$st = Cli::status_data();
check( 'wp pass-captain status returns issues, cron health and queue summaries', isset( $st['queues']['jobs'], $st['queues']['messages'] ) && count( Status::cron_hooks() ) === count( $st['cron'] ) && is_array( $st['issues'] ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_jobs WHERE job_id=%d", $cli_stale ) );

/* ------------------------------------------------------------------------------------------ */
echo "Ops: dbDelta backoff after a failed schema upgrade\n";
final class Ops_Test_Broken_Module {
	public static function schema( $prefix, $charset ) {
		return array( "CREATE TABLE {$prefix}wpcap_ops_broken (\n\t\t\t\tid bigint(20) unsigned NOT NULL,\n\t\t\t\tbroken NOSUCHTYPE NOT NULL,\n\t\t\t\tPRIMARY KEY  (id)\n\t\t\t) ENGINE=InnoDB $charset;" );
	}
}
check( 'backoff delays are 1, 5, 15 and then 60 minutes', array( 60, 300, 900, 3600, 3600 ) === array_map( array( Installer::class, 'retry_delay' ), array( 1, 2, 3, 4, 9 ) ) );
$ops_saved = array();
foreach ( array( 'wpcap_db_version', 'wpcap_version', 'wpcap_schema_fingerprint', Installer::ERROR_OPTION ) as $k ) { $ops_saved[ $k ] = $GLOBALS['wpcap_test_options'][ $k ] ?? null; }
$ops_tables = static function () use ( $wpdb ) { return count( (array) $wpdb->get_col( "SHOW TABLES LIKE 'wpops\\_%'" ) ); };
$wpdb->prefix = 'wpops_';
Installer::flush_version_cache();
$GLOBALS['wpcap_test_filters']['wpcap_modules'] = array_merge( PassCaptain\Modules::classes(), array( Ops_Test_Broken_Module::class ) );
Installer::flush_version_cache();
update_option( 'wpcap_db_version', 'old-version' );
unset( $GLOBALS['wpcap_test_options'][ Installer::ERROR_OPTION ] );
fwrite( STDERR, "(ops test: injected schema errors (NOSUCHTYPE) follow)\n" );
$t = time();
$ran = Installer::maybe_upgrade( 'cron' );
$err = get_option( Installer::ERROR_OPTION );
check( 'failed upgrade records attempt 1 and the next retry in one minute', false === $ran && 1 === $err['attempts'] && $err['next_retry'] >= $t + 60 && $err['next_retry'] <= time() + 60 );
$wpdb->query( 'DROP TABLE IF EXISTS wpops_wpcap_events' );
$ran = Installer::maybe_upgrade( 'admin' );
check( 'within the backoff neither admin nor cron requests run dbDelta again', false === $ran && false === Installer::maybe_upgrade( 'cron' ) && false === Installer::maybe_upgrade( 'frontend' ) && 1 === get_option( Installer::ERROR_OPTION )['attempts'] && null === $wpdb->get_var( "SHOW TABLES LIKE 'wpops\\_wpcap\\_events'" ) );
check( 'retry_blocked_until reports the backoff end', Installer::retry_blocked_until() === $err['next_retry'] && 0 === Installer::retry_blocked_until( $err['next_retry'] + 1 ) );
$delays = array();
$expected = array( 2 => 300, 3 => 900, 4 => 3600, 5 => 3600 );
$within = true;
for ( $i = 2; $i <= 5; $i++ ) {
	$GLOBALS['wpcap_test_options'][ Installer::ERROR_OPTION ]['next_retry'] = time() - 1; // Backoff elapsed.
	// The upgrade attempt itself can take seconds on a busy server (MySQL): next_retry must lie between
	// "start + delay" and "end + delay" instead of within a fixed tolerance.
	$t0 = time();
	Installer::maybe_upgrade( 'cron' );
	$t1 = time();
	$e = get_option( Installer::ERROR_OPTION );
	$delays[ $e['attempts'] ] = $e['next_retry'] - $t0;
	$d = $expected[ $e['attempts'] ] ?? -1;
	$within = $within && $e['next_retry'] >= $t0 + $d && $e['next_retry'] <= $t1 + $d;
}
check( 'each further failure backs off longer (5, 15, 60, 60 minutes)', array_keys( $delays ) === array( 2, 3, 4, 5 ) && $within );
check( 'diagnostics report the schema error with attempts and next retry', 1 <= $diag( 'schema' )['count'] && 5 === $diag( 'schema' )['samples'][0]['attempts'] && $diag( 'schema' )['repairable'] );
$forced = StatusPage::schema_retry();
check( '"jetzt erneut versuchen" ignores the backoff (fails again here and counts the attempt)', is_wp_error( $forced ) && 'wpcap_diag_schema_failed' === $forced->get_error_code() && 6 === get_option( Installer::ERROR_OPTION )['attempts'] );
unset( $GLOBALS['wpcap_test_filters']['wpcap_modules'] );
Installer::flush_version_cache();
$ran = Installer::maybe_upgrade( 'cron' );
check( 'a new schema version is tried at once despite the backoff and clears the error', true === $ran && ! get_option( Installer::ERROR_OPTION ) && Installer::version() === get_option( 'wpcap_db_version' ) );
$GLOBALS['wpcap_test_filters']['wpcap_modules'] = array_merge( PassCaptain\Modules::classes(), array( Ops_Test_Broken_Module::class ) );
Installer::flush_version_cache();
Installer::maybe_upgrade( 'cron' );
$GLOBALS['wpcap_test_options'][ Installer::ERROR_OPTION ]['next_retry'] = time() + 3600;
unset( $GLOBALS['wpcap_test_filters']['wpcap_modules'] );
Installer::flush_version_cache();
$GLOBALS['wpcap_test_options'][ Installer::ERROR_OPTION ]['version'] = Installer::version(); // Same version, still in backoff.
update_option( 'wpcap_db_version', 'old-version' );
$ops_audits_here = static function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action='ops_diagnostics_repair'" ); };
$audits = $ops_audits_here();
$fixed = StatusPage::schema_retry();
check( 'forced retry after the cause was fixed succeeds, clears the error and is audited', is_array( $fixed ) && 1 === $fixed['repaired'] && ! get_option( Installer::ERROR_OPTION ) && Installer::version() === get_option( 'wpcap_db_version' ) && $audits + 1 === $ops_audits_here() );
foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE 'wpops\\_%'" ) as $tbl ) { $wpdb->query( "DROP TABLE IF EXISTS `$tbl`" ); }
$wpdb->prefix = $p;
Installer::flush_version_cache();
Status::flush_cache();
foreach ( $ops_saved as $k => $v ) { if ( null === $v ) { unset( $GLOBALS['wpcap_test_options'][ $k ] ); } else { $GLOBALS['wpcap_test_options'][ $k ] = $v; } }
check( 'test schema restored', 'ok' === $diag( 'schema' )['severity'] && 0 === $ops_tables() );
$_POST = array();
