<?php
/**
 * A163: "Betrieb" admin page – queue figures, dead-letter/failed items without payload values,
 * controlled retry/discard (single and bulk, capability, audit).
 * A164: Site Health tests (WP-Cron, backlogs/dead letters, mail and SMS failures, payment webhook
 * freshness), info tab section and admin notice for critical results.
 */
// phpcs:ignoreFile
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\OperationsPage;
use PassCaptain\Operations\Monitor;
use PassCaptain\Operations\Queues;
use PassCaptain\Operations\SiteHealth;
use PassCaptain\Settings;

echo "A163/A164: operations page and Site Health monitoring\n";
global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $t ) { return (string) $t; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }

$a163_now = current_time( 'mysql', true );
$a163_old = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
$a163_audits = static fn( $action ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s", $action ) );

// ------------------------------------------------------------------ fixtures
$a163_job = static function ( $status, $payload = array( 'order_id' => 7 ), $run_after = null ) use ( $wpdb, $p, $a163_now ) {
	$wpdb->insert( "{$p}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', uniqid( 'a163', true ) ), 'job_type' => 'a163_test', 'payload' => wp_json_encode( $payload ), 'run_after' => $run_after ?? $a163_now, 'status' => $status, 'attempts' => 'dead_letter' === $status ? 8 : 0, 'last_error' => 'dead_letter' === $status ? 'Mail an geheim@example.com fehlgeschlagen' : null, 'created_at' => $a163_now, 'updated_at' => $a163_now ) );
	return (int) $wpdb->insert_id;
};
$a163_msg = static function ( $status, $recipient = 'kundin@example.com' ) use ( $wpdb, $p, $a163_now ) {
	$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', uniqid( 'a163m', true ) ), 'recipient' => $recipient, 'template_key' => 'a163_tpl', 'payload' => wp_json_encode( array( 'subject' => 'Geheimer Betreff', 'body' => 'Hallo Kundin' ) ), 'send_at' => $a163_now, 'status' => $status, 'attempts' => 5, 'last_error' => 'wp_mail', 'created_at' => $a163_now ) );
	return (int) $wpdb->insert_id;
};

$a163_dead = array( $a163_job( 'dead_letter', array( 'order_id' => 7, 'email' => 'geheim@example.com' ) ), $a163_job( 'dead_letter' ), $a163_job( 'dead_letter' ) );
$a163_backlog = $a163_job( 'queued', array( 'x' => 1 ), $a163_old );

$a163_stats = Queues::stats( 'jobs' );
check( 'A163: job stats count dead letters by status and the due backlog', $a163_stats['failed'] >= 3 && $a163_stats['counts']['dead_letter'] >= 3 && $a163_stats['due'] >= 1 );
check( 'A163: age of the oldest due job is reported (>= 3 h)', $a163_stats['oldest_due_seconds'] >= 3 * HOUR_IN_SECONDS - 5 );
$a163_items = Queues::failed_items( 'jobs', 500 );
$a163_first = current( array_filter( $a163_items['rows'], static fn( $r ) => $r['id'] === $a163_dead[0] ) );
check( 'A163: failed list shows type, attempts and payload keys only', $a163_first && 'a163_test' === $a163_first['type'] && 8 === $a163_first['attempts'] && array( 'order_id', 'email' ) === $a163_first['payload_keys'] );
check( 'A163: failed list contains no payload values or clear e-mail addresses', false === strpos( wp_json_encode( $a163_items ), 'geheim@example.com' ) && false !== strpos( (string) $a163_first['last_error'], '***@***' ) );

$a163_mfail = $a163_msg( 'failed' );
$a163_mpurged = $a163_msg( 'failed', '' );
$a163_mrows = Queues::failed_items( 'messages', 500 )['rows'];
$a163_mrow = current( array_filter( $a163_mrows, static fn( $r ) => $r['id'] === $a163_mfail ) );
check( 'A163: message recipient masked, subject/body never shown', $a163_mrow && 'k***@e***.com' === $a163_mrow['detail'] && false === strpos( wp_json_encode( $a163_mrows ), 'Geheimer Betreff' ) && array( 'subject', 'body' ) === $a163_mrow['payload_keys'] );

// ------------------------------------------------------------------ actions
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$a163_r = OperationsPage::admin_action( array( 'queue' => 'jobs', 'do' => 'retry', 'ids' => array( $a163_dead[0] ) ) );
check( 'A163: actions require manage_options', is_wp_error( $a163_r ) && 403 === ( $a163_r->get_error_data()['status'] ?? 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A163: unknown queue rejected', is_wp_error( OperationsPage::admin_action( array( 'queue' => 'nope', 'do' => 'retry', 'ids' => array( 1 ) ) ) ) );
check( 'A163: missing action rejected', is_wp_error( OperationsPage::admin_action( array( 'queue' => 'jobs', 'do' => '', 'ids' => array( $a163_dead[0] ) ) ) ) );

$a163_before = $a163_audits( 'operations_retry' );
$a163_r = OperationsPage::admin_action( array( 'queue' => 'jobs', 'do' => 'discard', 'ids' => array( 99 ), 'row_action' => 'retry:' . $a163_dead[0] ) );
$a163_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_jobs WHERE job_id=%d", $a163_dead[0] ) );
check( 'A163: row button retries exactly that job (row_action wins over the bulk selection)', ! is_wp_error( $a163_r ) && 'retry' === $a163_r['do'] && 1 === $a163_r['count'] );
check( 'A163: retried job is queued, attempts 0, due now, error cleared', 'queued' === $a163_row->status && 0 === (int) $a163_row->attempts && null === $a163_row->last_error && strtotime( $a163_row->run_after . ' UTC' ) <= time() + 1 );
check( 'A163: retry is audited with queue and ids', $a163_audits( 'operations_retry' ) === $a163_before + 1 && false !== strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='operations_retry' ORDER BY audit_id DESC LIMIT 1" ), '"queue":"jobs"' ) );
check( 'A163: retrying a job that is no longer failed changes nothing', 0 === Queues::retry( 'jobs', array( $a163_dead[0] ) ) );

$a163_handled = static fn( $handled, $type ) => 'a163_test' === $type ? true : $handled;
add_filter( 'wpcap_process_job', $a163_handled, 10, 2 );
PassCaptain\Jobs::process_due( 50 );
remove_filter( 'wpcap_process_job', $a163_handled, 10 );
check( 'A163: the retried job is processed by the normal worker', 'complete' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=%d", $a163_dead[0] ) ) );

$a163_before = $a163_audits( 'operations_discard' );
$a163_r = OperationsPage::admin_action( array( 'queue' => 'jobs', 'do' => 'discard', 'ids' => array( $a163_dead[1], $a163_backlog ) ) );
check( 'A163: bulk discard only touches failed items (queued job untouched)', ! is_wp_error( $a163_r ) && 1 === $a163_r['count'] && 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=%d", $a163_dead[1] ) ) && 'complete' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=%d", $a163_backlog ) ) );
check( 'A163: discard is audited', $a163_audits( 'operations_discard' ) === $a163_before + 1 );

$a163_r = OperationsPage::admin_action( array( 'queue' => 'messages', 'do' => 'retry', 'all' => '1' ) );
$a163_m = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_message_queue WHERE message_id=%d", $a163_mfail ) );
check( 'A163: "all" retries every failed message (attempts 0, queued)', ! is_wp_error( $a163_r ) && $a163_r['count'] >= 1 && 'queued' === $a163_m->status && 0 === (int) $a163_m->attempts );
check( 'A163: messages whose content was erased are not retried', 'failed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_message_queue WHERE message_id=%d", $a163_mpurged ) ) );
check( 'A163: message discard sets cancelled', 1 === Queues::discard( 'messages', array( $a163_mpurged ) ) && 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_message_queue WHERE message_id=%d", $a163_mpurged ) ) );
check( 'A163: empty selection is an error', is_wp_error( Queues::retry( 'jobs', array() ) ) );

if ( isset( Queues::available()['webhooks'] ) ) {
	$wpdb->insert( "{$p}wpcap_webhook_subscriptions", array( 'url' => 'https://ops.hooks.example/in', 'events' => 'order.paid', 'secret' => 'whsec_a163', 'include_pii' => 0, 'status' => 'active', 'created_at' => $a163_now, 'updated_at' => $a163_now ) );
	$a163_sub = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_webhook_deliveries", array( 'subscription_id' => $a163_sub, 'event_id' => 'evt_a163_' . uniqid(), 'event_type' => 'order.paid', 'payload' => wp_json_encode( array( 'type' => 'order.paid', 'data' => array( 'object' => array( 'id' => 'ord_1', 'status' => 'paid' ) ) ) ), 'status' => 'failed', 'attempts' => 8, 'response_code' => 500, 'last_error' => 'HTTP 500', 'next_attempt_at' => $a163_now, 'created_at' => $a163_now, 'updated_at' => $a163_now ) );
	$a163_del = (int) $wpdb->insert_id;
	$a163_wrow = current( array_filter( Queues::failed_items( 'webhooks', 500 )['rows'], static fn( $r ) => $r['id'] === $a163_del ) );
	check( 'A163: webhook failures list data.object keys and HTTP code', $a163_wrow && array( 'id', 'status' ) === $a163_wrow['payload_keys'] && false !== strpos( $a163_wrow['detail'], 'HTTP 500' ) );
	$a163_r = OperationsPage::admin_action( array( 'queue' => 'webhooks', 'row_action' => 'retry:' . $a163_del ) );
	$a163_d = $wpdb->get_row( $wpdb->prepare( "SELECT status,attempts FROM {$p}wpcap_webhook_deliveries WHERE delivery_id=%d", $a163_del ) );
	check( 'A163: webhook retry uses the redelivery (pending, attempts 0)', ! is_wp_error( $a163_r ) && 1 === $a163_r['count'] && 'pending' === $a163_d->status && 0 === (int) $a163_d->attempts );
	check( 'A163: pending webhook retry cannot be discarded as failed', 0 === Queues::discard( 'webhooks', array( $a163_del ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d", $a163_sub ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_webhook_subscriptions WHERE subscription_id=%d", $a163_sub ) );
}

// ------------------------------------------------------------------ menu and page
check( 'A163: page "Betrieb" sits in the settings/integrations area with manage_options', 'settings' === Menu::group_of( OperationsPage::SLUG ) && (bool) array_filter( Menu::entries(), static fn( $e ) => OperationsPage::SLUG === $e[0] && 'manage_options' === $e[3] ) );
check( 'A163: admin-post handler registered', isset( Menu::handlers()[ OperationsPage::ACTION ] ) );
$a163_dead[] = $a163_job( 'dead_letter', array( 'email' => 'seite@example.com' ) );
ob_start();
OperationsPage::render();
$a163_html = (string) ob_get_clean();
check( 'A163: page renders queues, retry/discard buttons and nonce', false !== strpos( $a163_html, 'Hintergrundjobs' ) && false !== strpos( $a163_html, 'retry:' . end( $a163_dead ) ) && false !== strpos( $a163_html, 'discard:' . end( $a163_dead ) ) && false !== strpos( $a163_html, '_wpnonce' ) );
check( 'A163: page shows no payload values', false === strpos( $a163_html, 'seite@example.com' ) && false === strpos( $a163_html, 'Geheimer Betreff' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$a163_denied = false;
try { OperationsPage::render(); } catch ( Throwable $e ) { $a163_denied = true; }
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A163: page itself requires manage_options', $a163_denied );

// ------------------------------------------------------------------ A164: cron
$a164_t = time();
$a164_future = array();
foreach ( SiteHealth::RECURRING_HOOKS as $a164_h ) { $a164_future[ $a164_t + 120 ][ $a164_h ] = array( 'x' => array() ); }
check( 'A164: cron good when every recurring hook is scheduled in time', 'good' === SiteHealth::test_cron( $a164_future, $a164_t, false )['status'] );
$a164_late = $a164_future;
$a164_late[ $a164_t - 20 * 60 ]['wpcap_process_jobs'] = array( 'x' => array() );
$a164_res = SiteHealth::test_cron( $a164_late, $a164_t, false );
check( 'A164: cron event 20 min late is recommended and names the hook', 'recommended' === $a164_res['status'] && false !== strpos( $a164_res['description'], 'wpcap_process_jobs' ) );
$a164_late[ $a164_t - 90 * 60 ]['wpcap_privacy_retention'] = array( 'x' => array() );
check( 'A164: cron event 90 min late is critical', 'critical' === SiteHealth::test_cron( $a164_late, $a164_t, false )['status'] );
$a164_missing = $a164_future;
unset( $a164_missing[ $a164_t + 120 ]['wpcap_process_messages'] );
check( 'A164: unscheduled recurring hook is reported', 'recommended' === SiteHealth::test_cron( $a164_missing, $a164_t, false )['status'] );
check( 'A164: foreign cron hooks are ignored', 'good' === SiteHealth::test_cron( $a164_future + array( $a164_t - 7200 => array( 'other_plugin_hook' => array() ) ), $a164_t, false )['status'] );
$a164_thr = static fn( $t ) => array( 'cron_late_minutes' => 30 ) + $t;
add_filter( 'wpcap_health_thresholds', $a164_thr );
$a164_late20 = $a164_future;
$a164_late20[ $a164_t - 20 * 60 ]['wpcap_process_jobs'] = array( 'x' => array() );
check( 'A164: thresholds are filterable (30 min: 20 min late is fine)', 'good' === SiteHealth::test_cron( $a164_late20, $a164_t, false )['status'] && 30 === SiteHealth::thresholds()['cron_late_minutes'] );
remove_filter( 'wpcap_health_thresholds', $a164_thr );
$a164_beats = get_option( Monitor::HEARTBEAT_OPTION );
Monitor::heartbeat( 'wpcap_process_jobs', $a164_t - 3 * HOUR_IN_SECONDS );
$a164_res = SiteHealth::test_cron( $a164_future, $a164_t, true );
check( 'A164: DISABLE_WP_CRON without runs for 3 h is critical (no external trigger)', 'critical' === $a164_res['status'] && false !== strpos( $a164_res['description'], 'DISABLE_WP_CRON' ) );
Monitor::heartbeat( 'wpcap_process_jobs', $a164_t - 60 );
check( 'A164: DISABLE_WP_CRON with recent runs is good (external cron works)', 'good' === SiteHealth::test_cron( $a164_future, $a164_t, true )['status'] );
Monitor::heartbeat( 'wpcap_process_jobs', $a164_t - 30 );
check( 'A164: heartbeat writes at most once a minute', $a164_t - 60 === Monitor::last_heartbeat( 'wpcap_process_jobs' ) );
Monitor::heartbeat( 'other_hook', $a164_t );
check( 'A164: heartbeat ignores other hooks', 0 === Monitor::last_heartbeat( 'other_hook' ) );
Monitor::register();
check( 'A164: heartbeat hooked to the queue cron hooks', has_action( 'wpcap_process_jobs', array( Monitor::class, 'heartbeat' ) ) !== false && has_action( 'wpcap_process_messages', array( Monitor::class, 'heartbeat' ) ) !== false );
remove_action( 'wpcap_process_jobs', array( Monitor::class, 'heartbeat' ), -999 );
remove_action( 'wpcap_process_messages', array( Monitor::class, 'heartbeat' ), -999 );
check( 'A164: durations use singular/plural', '1 Minute' === SiteHealth::duration( 1 ) && '20 Minuten' === SiteHealth::duration( 20 ) && '3 Stunden' === SiteHealth::duration( 180 ) && '1 Stunde' === SiteHealth::hours( 1 ) );

// ------------------------------------------------------------------ A164: queues
$a164_res = SiteHealth::test_queues();
check( 'A164: dead letters make the queue test at least recommended', in_array( $a164_res['status'], array( 'recommended', 'critical' ), true ) && false !== strpos( $a164_res['description'], 'Hintergrundjobs' ) );
$a164_thr = static fn( $t ) => array( 'dead_letters_critical' => 1 ) + $t;
add_filter( 'wpcap_health_thresholds', $a164_thr );
check( 'A164: dead letters above the critical threshold are critical', 'critical' === SiteHealth::test_queues()['status'] );
remove_filter( 'wpcap_health_thresholds', $a164_thr );

// ------------------------------------------------------------------ A164: mail
$a164_mail_opt = get_option( Monitor::MAIL_OPTION );
update_option( Monitor::MAIL_OPTION, array() );
check( 'A164: mail good without failures', 'good' === SiteHealth::test_mail()['status'] );
Monitor::mail_begin();
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate for kunde@example.com' ) );
Monitor::mail_end( false, 'ticket_mail' );
$a164_fail = Monitor::mail_failures( time() - 60 );
check( 'A164: wp_mail failure of a plugin mail recorded with type and code, address masked', 1 === count( $a164_fail ) && 'ticket_mail' === $a164_fail[0]['type'] && 'wp_mail_failed' === $a164_fail[0]['code'] && false === strpos( wp_json_encode( $a164_fail ), 'kunde@example.com' ) );
Monitor::mail_begin();
Monitor::mail_end( true, 'ticket_mail' );
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'fremd' ) );
check( 'A164: successful mails and foreign wp_mail failures are not counted', 1 === count( Monitor::mail_failures( time() - 60 ) ) );
$a164_res = SiteHealth::test_mail();
check( 'A164: one failure is recommended', 'recommended' === $a164_res['status'] && false !== strpos( $a164_res['description'], 'ticket_mail' ) );
for ( $i = 0; $i < 9; $i++ ) { Monitor::record_mail_failure( 'reminder', 'wp_mail_false' ); }
check( 'A164: ten failures in 24 h are critical', 'critical' === SiteHealth::test_mail()['status'] );
Monitor::record_mail_failure( 'old', 'x', '', time() - 8 * DAY_IN_SECONDS );
check( 'A164: entries older than 7 days are pruned', ! array_filter( (array) get_option( Monitor::MAIL_OPTION ), static fn( $e ) => 'old' === $e['type'] ) );
$a164_mails = count( $GLOBALS['wpcap_test_mails'] );
check( 'A164: Mailer::deliver records nothing for a sent mail', PassCaptain\Mail\Mailer::deliver( 'ok@example.com', array( 'type' => 'a164', 'subject' => 'S', 'body' => 'B' ) ) && 10 === count( Monitor::mail_failures( time() - 60 ) ) && count( $GLOBALS['wpcap_test_mails'] ) === $a164_mails + 1 );

// ------------------------------------------------------------------ A164: SMS
if ( PassCaptain\Privacy::has_table( 'wpcap_sms_messages' ) ) {
	$wpdb->insert( "{$p}wpcap_sms_messages", array( 'dedupe_key' => hash( 'sha256', uniqid( 'a164', true ) ), 'consent_id' => 0, 'order_id' => 0, 'purpose' => 'reminder', 'body' => 'x', 'status' => 'failed', 'send_at' => $a163_now, 'created_at' => $a163_now, 'updated_at' => $a163_now ) );
	$a164_sms = (int) $wpdb->insert_id;
	check( 'A164: SMS failures counted and reported', SiteHealth::sms_failures() >= 1 && in_array( SiteHealth::test_sms()['status'], array( 'recommended', 'critical' ), true ) );
	$wpdb->delete( "{$p}wpcap_sms_messages", array( 'message_id' => $a164_sms ) );
}

// ------------------------------------------------------------------ A164: payment webhooks
$a164_providers = Settings::get( 'enabled_providers' );
Settings::update( array( 'enabled_providers' => array( 'manual', 'a164pay' ) ) );
check( 'A164: payment webhooks good without orders of the provider', 'good' === SiteHealth::test_payment_webhooks()['status'] && isset( SiteHealth::payment_webhooks()['a164pay'] ) && ! isset( SiteHealth::payment_webhooks()['manual'] ) );
$a164_order = 0;
$a164_values = array( 'public_id' => substr( md5( uniqid() ), 0, 32 ), 'idempotency_key' => hash( 'sha256', uniqid() ), 'customer_email' => 'pay@example.com', 'currency' => 'EUR', 'price_snapshot' => '{}', 'payment_provider' => 'a164pay', 'created_at' => $a163_now, 'updated_at' => $a163_now );
foreach ( $wpdb->get_results( "SHOW COLUMNS FROM {$p}wpcap_orders" ) as $a164_col ) {
	if ( 'NO' === $a164_col->Null && null === $a164_col->Default && false === strpos( (string) $a164_col->Extra, 'auto_increment' ) && ! array_key_exists( $a164_col->Field, $a164_values ) ) {
		$a164_values[ $a164_col->Field ] = preg_match( '/int|decimal/', $a164_col->Type ) ? 0 : ( preg_match( '/date/', $a164_col->Type ) ? $a163_now : '' );
	}
}
$wpdb->insert( "{$p}wpcap_orders", $a164_values );
$a164_order = (int) $wpdb->insert_id;
$a164_res = SiteHealth::test_payment_webhooks();
check( 'A164: recent orders but never a webhook is recommended', 'recommended' === $a164_res['status'] && false !== strpos( $a164_res['description'], 'a164pay' ) );
$wpdb->insert( "{$p}wpcap_payment_events", array( 'provider' => 'a164pay', 'provider_event_id' => 'evt_a164', 'event_type' => 'paid', 'status' => 'processed', 'created_at' => $a163_now, 'updated_at' => $a163_now ) );
check( 'A164: fresh webhook makes it good and records the last time', 'good' === SiteHealth::test_payment_webhooks()['status'] && $a163_now === SiteHealth::payment_webhooks()['a164pay']['last'] );

// ------------------------------------------------------------------ A164: registration, info tab, notice
$a164_tests = SiteHealth::register_tests( array() );
check( 'A164: direct Site Health tests registered', isset( $a164_tests['direct']['wpcap_cron'], $a164_tests['direct']['wpcap_queues'], $a164_tests['direct']['wpcap_mail'], $a164_tests['direct']['wpcap_payment_webhooks'] ) && is_callable( $a164_tests['direct']['wpcap_cron']['test'] ) );
$a164_plugin_src = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'A164: Site Health and monitor registered by the plugin', false !== strpos( $a164_plugin_src, 'Operations\\SiteHealth::register();' ) && false !== strpos( $a164_plugin_src, 'Operations\\Monitor::register();' ) );
SiteHealth::register();
check( 'A164: Site Health filters and notice hooked', has_filter( 'site_status_tests', array( SiteHealth::class, 'register_tests' ) ) !== false && has_filter( 'debug_information', array( SiteHealth::class, 'debug_information' ) ) !== false && has_action( 'admin_notices', array( SiteHealth::class, 'admin_notice' ) ) !== false );
remove_filter( 'site_status_tests', array( SiteHealth::class, 'register_tests' ) );
remove_filter( 'debug_information', array( SiteHealth::class, 'debug_information' ) );
remove_action( 'admin_notices', array( SiteHealth::class, 'admin_notice' ) );
$a164_info = SiteHealth::debug_information( array() );
$a164_fields = $a164_info['pass-captain-operations']['fields'] ?? array();
check( 'A164: info tab section with cron, queues, mail failures and payment webhooks', isset( $a164_fields['disable_wp_cron'], $a164_fields['queue_jobs'], $a164_fields['queue_messages'], $a164_fields['mail_failures'], $a164_fields['payment_webhook_a164pay'] ) && '10' === $a164_fields['mail_failures']['value'] );
$_GET['page'] = 'wpcap-operations';
ob_start();
SiteHealth::admin_notice();
$a164_notice = (string) ob_get_clean();
check( 'A164: admin notice on plugin pages while a test is critical', false !== strpos( $a164_notice, 'notice-error' ) && false !== strpos( $a164_notice, 'E-Mails werden nicht zugestellt' ) );
$_GET['page'] = 'other-plugin';
ob_start();
SiteHealth::admin_notice();
check( 'A164: no notice on foreign pages', '' === (string) ob_get_clean() );
unset( $_GET['page'] );
$a164_docs = (string) file_get_contents( WPCAP_PATH . 'docs/OPERATIONS.md' ) . (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
check( 'A164: monitoring documented (OPERATIONS.md) and thresholds filter listed (HOOKS.md)', false !== strpos( $a164_docs, '## Betriebsüberwachung' ) && false !== strpos( $a164_docs, '`wpcap_health_thresholds`' ) );

// ------------------------------------------------------------------ clean-up
$wpdb->delete( "{$p}wpcap_orders", array( 'order_id' => $a164_order ) );
$wpdb->delete( "{$p}wpcap_payment_events", array( 'provider' => 'a164pay' ) );
Settings::update( array( 'enabled_providers' => $a164_providers ) );
update_option( Monitor::MAIL_OPTION, is_array( $a164_mail_opt ) ? $a164_mail_opt : array() );
update_option( Monitor::HEARTBEAT_OPTION, is_array( $a164_beats ) ? $a164_beats : array() );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='a163_test'" );
$wpdb->query( "DELETE FROM {$p}wpcap_message_queue WHERE template_key='a163_tpl'" );
delete_transient( SiteHealth::NOTICE_TRANSIENT );
