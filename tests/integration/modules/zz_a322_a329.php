<?php
/**
 * Package R4 (support/debugging): A322–A329.
 *
 * A327 central support log (levels, debug mode, redaction, rotation), A322 checkout failures with a
 * Fehler-ID, A323 webhook rejections/crashes counted per provider and shown in Site Health, A324
 * neutral payment start errors with the provider text in the log, A325 dead-letter reasons, A326
 * dead letters linked to their order, A328 order history ("Verlauf") with mails and jobs, A329
 * support export in the Site Health info tab.
 *
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\OperationsPage;
use PassCaptain\Admin\OrderHistory;
use PassCaptain\Communications;
use PassCaptain\Jobs;
use PassCaptain\Log;
use PassCaptain\Operations\Monitor;
use PassCaptain\Operations\Queues;
use PassCaptain\Operations\SiteHealth;
use PassCaptain\Orders;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }

global $wpdb;
$p = $wpdb->prefix;
echo "R4: support log (A327)\n";

$r4_options = $GLOBALS['wpcap_test_options'];
$r4_transients = $GLOBALS['wpcap_test_transients'];
$r4_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_transients'] = array();
$r4_secret = 'sk_live_' . bin2hex( random_bytes( 10 ) );
$r4_buyer = 'r4.' . bin2hex( random_bytes( 3 ) ) . '@buyer-example.com';
$r4_log_ini = ini_get( 'error_log' );
$r4_php_log = sys_get_temp_dir() . '/wpcap-r4-' . getmypid() . '.log';
@unlink( $r4_php_log );
ini_set( 'error_log', $r4_php_log );
$r4_last = static function () use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT COALESCE(MAX(log_id),0) FROM {$p}wpcap_logs" ); };
$r4_since = static function ( $from ) use ( $wpdb, $p ) { return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_logs WHERE log_id>%d ORDER BY log_id", $from ), ARRAY_A ); };

check( 'A327: support log table exists (schema 3.10.16) and Log::ready()', Log::ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.16', '>=' ) );
check( 'A327: message queue and jobs carry an order_id column', Log::has_column( 'wpcap_message_queue', 'order_id' ) && Log::has_column( 'wpcap_message_queue', 'sent_at' ) && Log::has_column( 'wpcap_jobs', 'order_id' ) );
$r4_from = $r4_last();
update_option( Log::DEBUG_SINCE_OPTION, 0 );
Settings::update( array( 'debug_logging' => 0 ) );
check( 'A327: without debug mode only info and above are stored', ! Log::debug_enabled() && '' === Log::debug( 'test', 'nope' ) && '' !== Log::info( 'test', 'yes' ) );
Settings::update( array( 'debug_logging' => 1 ) );
Log::settings_changed( array( 'debug_logging' => 0 ), array( 'debug_logging' => 1 ) );
check( 'A327: switching the setting on starts the debug mode', Log::debug_enabled() && '' !== Log::debug( 'test', 'debug entry' ) );
update_option( Log::DEBUG_SINCE_OPTION, time() - 25 * HOUR_IN_SECONDS );
check( 'A327: debug mode ends by itself after 24 hours', ! Log::debug_enabled() && '' === Log::debug( 'test', 'late' ) );
Settings::update( array( 'debug_logging' => 0 ) );
$r4_ref = Log::error( 'test', 'Provider said: Invalid API Key ' . $r4_secret . ' for ' . $r4_buyer . ' card 4111 1111 1111 1111', array( 'order_id' => 77, 'ticket_code' => 'abc123def456', 'token' => 'tok-secret-value', 'email' => $r4_buyer, 'access_link' => 'https://example.test/?wpcap_access=xyz', 'api_key' => 'k-' . $r4_secret, 'step' => 'issue' ) );
$r4_rows = $r4_since( $r4_from );
$r4_row = end( $r4_rows );
check( 'A327: entry with level, channel, 12-hex reference, request id and order column', preg_match( '/^[a-f0-9]{12}$/', $r4_ref ) && $r4_ref === $r4_row['reference'] && 'error' === $r4_row['level'] && 'test' === $r4_row['channel'] && 77 === (int) $r4_row['order_id'] && Log::request_id() === $r4_row['request_id'] );
$r4_dump = json_encode( $r4_row );
check( 'A327: no secret, card number, e-mail, ticket code, token or access link is stored', false === strpos( $r4_dump, $r4_secret ) && false === strpos( $r4_dump, '4111 1111 1111 1111' ) && false === strpos( $r4_dump, $r4_buyer ) && false === strpos( $r4_dump, 'abc123def456' ) && false === strpos( $r4_dump, 'tok-secret-value' ) && false === strpos( $r4_dump, 'wpcap_access' ) && array( 'api_key' => PassCaptain\Redact::MASK, 'step' => 'issue' ) === json_decode( (string) $r4_row['context'], true ) );
check( 'A327: errors also go to the PHP error log (redacted, with reference)', false !== strpos( (string) @file_get_contents( $r4_php_log ), $r4_ref ) && false === strpos( (string) @file_get_contents( $r4_php_log ), $r4_secret ) );
check( 'A327: entries can be found by reference and by order', $r4_ref === ( Log::entries( array( 'reference' => strtoupper( $r4_ref ) ) )[0]['reference'] ?? '' ) && $r4_ref === ( Log::entries( array( 'order_id' => 77 ) )[0]['reference'] ?? '' ) );
$r4_scoped = Log::scope( array( 'order_id' => 78 ), static fn() => Log::warning( 'test', 'scoped' ) );
check( 'A327: Log::scope() attributes entries to the order of the scope', 78 === (int) ( Log::entries( array( 'reference' => $r4_scoped ) )[0]['order_id'] ?? 0 ) && array() === Log::current_scope() );
$wpdb->insert( "{$p}wpcap_logs", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'level' => 'error', 'channel' => 'old', 'reference' => 'aaaaaaaaaaaa', 'message' => 'old' ) );
$wpdb->insert( "{$p}wpcap_logs", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'level' => 'info', 'channel' => 'old', 'reference' => 'bbbbbbbbbbbb', 'message' => 'old info' ) );
$wpdb->insert( "{$p}wpcap_logs", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'level' => 'warning', 'channel' => 'old', 'reference' => 'cccccccccccc', 'message' => 'old warning' ) );
Log::purge();
check( 'A327: rotation removes entries older than the retention', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_logs WHERE reference='aaaaaaaaaaaa'" ) && 1 === count( Log::entries( array( 'reference' => $r4_ref ) ) ) );
check( 'A327: debug/info entries go after 7 days, warnings stay 30 days', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_logs WHERE reference='bbbbbbbbbbbb'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_logs WHERE reference='cccccccccccc'" ) );
do_action( 'wpcap_privacy_order_anonymized', 78 );
Log::forget_order( 78 );
check( 'A327: privacy erasure of an order removes its log entries', array() === Log::entries( array( 'order_id' => 78 ) ) );
$r4_seen = array();
$r4_listener = static function ( $entry ) use ( &$r4_seen ) { $r4_seen[] = $entry; };
add_action( 'wpcap_log', $r4_listener );
Log::warning( 'test', 'hooked' );
remove_action( 'wpcap_log', $r4_listener );
check( 'A327: wpcap_log announces stored entries', 1 === count( $r4_seen ) && 'hooked' === $r4_seen[0]['message'] );
check( 'A327: Log registered by the plugin, debug setting exists', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), 'Log::register();' ) && array_key_exists( 'debug_logging', Settings::defaults() ) );

/* ====================================================================================== A322 */
echo "R4: checkout failures (A322)\n";
$r4_type = make_type( 50, 10, 0 );
$r4_from = $r4_last();
$r4_boom = static function () use ( $r4_secret ) { throw new LogicException( 'module broke with ' . $r4_secret ); };
add_action( 'wpcap_order_creating', $r4_boom );
$r4_res = Orders::create( array( 'email' => $r4_buyer, 'items' => array( array( 'ticket_type_id' => $r4_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r4-boom-' . uniqid() ) );
remove_action( 'wpcap_order_creating', $r4_boom );
$r4_data = is_wp_error( $r4_res ) ? (array) $r4_res->get_error_data() : array();
$r4_ref = (string) ( $r4_data['reference'] ?? '' );
check( 'A322: unexpected checkout exception returns a Fehler-ID to the buyer', is_wp_error( $r4_res ) && 'wpcap_checkout_failed' === $r4_res->get_error_code() && preg_match( '/^[a-f0-9]{12}$/', $r4_ref ) && false !== strpos( $r4_res->get_error_message(), $r4_ref ) && false === strpos( $r4_res->get_error_message(), $r4_secret ) );
$r4_entry = Log::entries( array( 'reference' => $r4_ref ) )[0] ?? array();
check( 'A322: support log has class, file:line and the abort reason, without secret', 'checkout' === ( $r4_entry['channel'] ?? '' ) && 'error' === ( $r4_entry['level'] ?? '' ) && 'LogicException' === ( $r4_entry['context']['exception'] ?? '' ) && false !== strpos( (string) ( $r4_entry['context']['where'] ?? '' ), 'zz_a322_a329.php:' ) && false === strpos( json_encode( $r4_entry ), $r4_secret ) );
check( 'A322: nothing of the failed order is left', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email=%s", $r4_buyer ) ) );
$r4_busy = static function () { throw new RuntimeException( 'db_busy' ); };
add_action( 'wpcap_order_creating', $r4_busy );
$r4_res = Orders::create( array( 'email' => 'r4-busy@example.com', 'items' => array( array( 'ticket_type_id' => $r4_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r4-busy-' . uniqid() ) );
remove_action( 'wpcap_order_creating', $r4_busy );
$r4_rows = $r4_since( $r4_from );
check( 'A322: a busy database is logged as a warning (retryable answer, no Fehler-ID needed)', is_wp_error( $r4_res ) && 503 === $r4_res->get_error_data()['status'] && (bool) array_filter( $r4_rows, static fn( $r ) => 'checkout' === $r['channel'] && 'warning' === $r['level'] ) );
$r4_small = make_type( 1, 10, 0 );
$r4_from = $r4_last();
Orders::create( array( 'email' => 'r4-a@example.com', 'items' => array( array( 'ticket_type_id' => $r4_small, 'quantity' => 1 ) ), 'idempotency_key' => 'r4-a-' . uniqid() ) );
$r4_res = Orders::create( array( 'email' => 'r4-b@example.com', 'items' => array( array( 'ticket_type_id' => $r4_small, 'quantity' => 1 ) ), 'idempotency_key' => 'r4-b-' . uniqid() ) );
check( 'A322: ordinary outcomes (sold out) stay without reference and without error entry', is_wp_error( $r4_res ) && 'sold_out' === $r4_res->get_error_data()['reason'] && empty( $r4_res->get_error_data()['reference'] ) && ! array_filter( $r4_since( $r4_from ), static fn( $r ) => 'checkout' === $r['channel'] && in_array( $r['level'], array( 'error', 'warning' ), true ) ) );

/* ====================================================================================== A323 / A324 */
final class R4_Fake_Gateway implements PassCaptain\Payments\GatewayInterface {
	public static $start_error = null;
	public function id() { return 'r4fake'; }
	public function title() { return 'R4'; }
	public function is_available( $currency ) { return true; }
	public function create_payment( array $order, array $items = array() ) { return self::$start_error ?: array( 'provider' => 'r4fake', 'status' => 'pending', 'redirect_url' => 'https://pay.example/r4', 'provider_reference' => 'r4ref' ); }
	public function verify_webhook( \WP_REST_Request $request ) {
		if ( 'good' !== $request->get_header( 'x-r4' ) ) { return new WP_Error( 'wpcap_r4_signature', 'Ungültige Signatur.', array( 'status' => 401 ) ); }
		return json_decode( (string) $request->get_body(), true );
	}
	public function supports_refunds() { return false; }
	public function refund( array $order, $amount_minor, $idempotency_key ) { return new WP_Error( 'no', 'no' ); }
}
$r4_gateways = static function ( $list ) { $list[] = new R4_Fake_Gateway(); return $list; };
add_filter( 'wpcap_payment_gateways', $r4_gateways );
PassCaptain\Payments\Registry::reset();
update_option( Monitor::WEBHOOK_OPTION, array() );
update_option( Monitor::PAYMENT_START_OPTION, array() );

echo "R4: webhook failures (A323)\n";
check( 'A323: webhook test good without failures', 'good' === SiteHealth::test_webhook_errors()['status'] );
$r4_from = $r4_last();
$r4_req = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/r4fake/webhook' );
$r4_req->set_param( 'provider', 'r4fake' );
$r4_req->set_body( '{}' );
$r4_hook = Webhooks::handle( $r4_req );
Webhooks::handle( $r4_req );
$r4_fail = Monitor::provider_failures( Monitor::WEBHOOK_OPTION, time() - 60 );
check( 'A323: signature failures are counted per provider (merged per minute)', is_wp_error( $r4_hook ) && 401 === $r4_hook->get_error_data()['status'] && 1 === count( $r4_fail ) && 'r4fake' === $r4_fail[0]['provider'] && 'signature' === $r4_fail[0]['kind'] && 2 === (int) $r4_fail[0]['n'] );
check( 'A323: only the first failure of the minute is written to the support log', 1 === count( array_filter( $r4_since( $r4_from ), static fn( $r ) => 'webhook' === $r['channel'] ) ) );
$r4_health = SiteHealth::test_webhook_errors();
check( 'A323: Site Health shows signature failures right away (recommended)', 'recommended' === $r4_health['status'] && false !== strpos( $r4_health['description'], 'r4fake' ) && false !== strpos( $r4_health['description'], 'Signatur ungültig' ) );
// A crash while applying a verified event: class/file:line in the log, claim released, critical.
$r4_order = Orders::create( array( 'email' => 'r4-hook@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 1500 ), 'quantity' => 1 ) ), 'idempotency_key' => 'r4-hook-' . uniqid() ), array( 'payment_provider' => 'r4fake' ) );
$r4_crash = static function () { throw new DomainException( 'listener crashed' ); };
add_action( 'wpcap_order_cancelled', $r4_crash );
$r4_req2 = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/r4fake/webhook' );
$r4_req2->set_param( 'provider', 'r4fake' );
$r4_req2->set_header( 'x-r4', 'good' );
$r4_req2->set_body( json_encode( array( 'event_id' => 'evt_r4_' . uniqid(), 'type' => 'failed', 'order_public_id' => $r4_order['public_id'] ) ) );
$r4_hook = Webhooks::handle( $r4_req2 );
remove_action( 'wpcap_order_cancelled', $r4_crash );
$r4_ref = (string) ( ( is_wp_error( $r4_hook ) ? (array) $r4_hook->get_error_data() : array() )['reference'] ?? '' );
$r4_entry = Log::entries( array( 'reference' => $r4_ref ) )[0] ?? array();
check( 'A323: a crash answers 500 with a reference and is logged with class and order', is_wp_error( $r4_hook ) && 500 === $r4_hook->get_error_data()['status'] && 'DomainException' === ( $r4_entry['context']['exception'] ?? '' ) && (int) $r4_order['order_id'] === (int) ( $r4_entry['order_id'] ?? 0 ) );
check( 'A323: the event claim is released for the redelivery', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_payment_events WHERE provider='r4fake' AND status='processing' AND order_id IN (0,%d)", $r4_order['order_id'] ) ) );
$r4_health = SiteHealth::test_webhook_errors();
check( 'A323: a crash makes the webhook test critical, with the Fehler-ID', 'critical' === $r4_health['status'] && false !== strpos( $r4_health['description'], 'Absturz' ) && false !== strpos( $r4_health['description'], $r4_ref ) );
check( 'A323: the critical test is part of the admin notice', in_array( $r4_health['label'], SiteHealth::critical_labels( true ), true ) );

echo "R4: payment start failures (A324)\n";
$r4_settings_providers = (array) Settings::get( 'enabled_providers' );
Settings::update( array( 'enabled_providers' => array_values( array_unique( array_merge( $r4_settings_providers, array( 'r4fake' ) ) ) ) ) );
R4_Fake_Gateway::$start_error = new WP_Error( 'wpcap_r4_api', 'R4-Fehler: Your account cannot currently make live charges (key ' . $r4_secret . ', customer ' . $r4_buyer . ')' );
$r4_paid_type = make_type( 50, 10, 2000 );
$r4_checkout = static function ( $key ) use ( $r4_paid_type, $r4_buyer ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/checkout' );
	$req->set_body( json_encode( array( 'email' => $r4_buyer, 'items' => array( array( 'ticket_type_id' => $r4_paid_type, 'quantity' => 1 ) ), 'provider' => 'r4fake', 'idempotency_key' => $key, 'accept_terms' => true ) ) );
	$req->set_header( 'content-type', 'application/json' );
	return PassCaptain\Rest\Controller::checkout( $req );
};
$r4_from = $r4_last();
$r4_res = $r4_checkout( 'r4-start-1' );
$r4_data = is_wp_error( $r4_res ) ? (array) $r4_res->get_error_data() : array();
$r4_ref = (string) ( $r4_data['reference'] ?? '' );
check( 'A324: buyer sees a neutral message with Fehler-ID, not the provider text', is_wp_error( $r4_res ) && 502 === $r4_data['status'] && 'wpcap_r4_api' === $r4_res->get_error_code() && '' !== $r4_ref && false !== strpos( $r4_res->get_error_message(), $r4_ref ) && false === strpos( $r4_res->get_error_message(), 'live charges' ) && false === strpos( json_encode( $r4_data ), 'live charges' ) );
$r4_entry = Log::entries( array( 'reference' => $r4_ref ) )[0] ?? array();
$r4_order_row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,status FROM {$p}wpcap_orders WHERE idempotency_key=%s", hash( 'sha256', 'r4-start-1' ) ) );
check( 'A324: the provider text is in the support log (redacted) with order and provider', false !== strpos( (string) ( $r4_entry['message'] ?? '' ), 'live charges' ) && 'r4fake' === ( $r4_entry['context']['provider'] ?? '' ) && $r4_order_row && (int) $r4_order_row->order_id === (int) $r4_entry['order_id'] && false === strpos( json_encode( $r4_entry ), $r4_secret ) && false === strpos( json_encode( $r4_entry ), $r4_buyer ) );
check( 'A324: the order is cancelled', $r4_order_row && 'cancelled' === $r4_order_row->status );
check( 'A324: one failure makes the payment start test "recommended"', 'recommended' === SiteHealth::test_payment_start()['status'] );
for ( $r4_i = 2; $r4_i <= 5; $r4_i++ ) { $r4_checkout( 'r4-start-' . $r4_i ); }
$r4_health = SiteHealth::test_payment_start();
check( 'A324: five failures within an hour are critical (admin notice)', 'critical' === $r4_health['status'] && false !== strpos( $r4_health['description'], 'r4fake: 5' ) && in_array( $r4_health['label'], SiteHealth::critical_labels( true ), true ) );
R4_Fake_Gateway::$start_error = null;
Settings::update( array( 'enabled_providers' => $r4_settings_providers ) );
remove_filter( 'wpcap_payment_gateways', $r4_gateways );
PassCaptain\Payments\Registry::reset();
update_option( Monitor::WEBHOOK_OPTION, array() );
update_option( Monitor::PAYMENT_START_OPTION, array() );

/* ====================================================================================== A325 / A326 */
echo "R4: dead letters (A325, A326)\n";
$r4_job_order = Orders::create( array( 'email' => 'r4-job@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'r4-job-' . uniqid() ) );
$r4_job_order_id = (int) $r4_job_order['order_id'];
$r4_run_job = static function ( $type, array $payload ) use ( $wpdb, $p ) {
	Jobs::enqueue( 'r4|' . $type . '|' . uniqid(), $type, $payload );
	$id = (int) $wpdb->get_var( "SELECT MAX(job_id) FROM {$p}wpcap_jobs" );
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET attempts=%d, run_after=%s WHERE job_id=%d", Jobs::MAX_ATTEMPTS - 1, gmdate( 'Y-m-d H:i:s', time() - 60 ), $id ) );
	Jobs::process_due( 50, $type );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_jobs WHERE job_id=%d", $id ) );
};
$r4_block = static function () { return false; };
add_filter( 'wpcap_mail', $r4_block );
$r4_orders_handler = array( Orders::class, 'handle_job' );
add_filter( 'wpcap_process_job', $r4_orders_handler, 10, 4 );
$r4_job = $r4_run_job( 'ticket_mail_retry', array( 'order_id' => $r4_job_order_id ) );
remove_filter( 'wpcap_process_job', $r4_orders_handler, 10 );
remove_filter( 'wpcap_mail', $r4_block );
check( 'A325: dead letter of a failed ticket mail says why (handler WP_Error)', 'dead_letter' === $r4_job->status && false !== strpos( (string) $r4_job->last_error, 'wpcap_mail_failed' ) && false === strpos( (string) $r4_job->last_error, 'No handler acknowledged' ) );
check( 'A326: jobs store their order (order_id column)', $r4_job_order_id === (int) $r4_job->order_id );
$r4_mailfail = static function ( $handled, $type ) {
	if ( 'r4_mailfail' !== $type ) { return $handled; }
	Monitor::mail_begin();
	do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Could not connect to smtp.r4.example for kunde@example.com' ) );
	Monitor::mail_end( false, 'tickets' );
	return false;
};
add_filter( 'wpcap_process_job', $r4_mailfail, 10, 2 );
$r4_job2 = $r4_run_job( 'r4_mailfail', array( 'order_id' => $r4_job_order_id, 'ticket_id' => 4242, 'occurrence_id' => 99 ) );
remove_filter( 'wpcap_process_job', $r4_mailfail, 10 );
check( 'A325: a mail error of the run (wp_mail_failed) becomes part of last_error, address masked', false !== strpos( (string) $r4_job2->last_error, 'SMTP Error: Could not connect' ) && false !== strpos( (string) $r4_job2->last_error, 'r4_mailfail' ) && false === strpos( (string) $r4_job2->last_error, 'kunde@example.com' ) );
$r4_items = Queues::failed_items( 'jobs', 200 );
$r4_item = current( array_filter( $r4_items['rows'], static fn( $r ) => (int) $r['id'] === (int) $r4_job2->job_id ) );
check( 'A326: dead letter lists order, ticket and occurrence ids (values, not only field names)', $r4_item && array( 'order_id' => $r4_job_order_id, 'ticket_id' => 4242, 'occurrence_id' => 99 ) === $r4_item['refs'] );
$r4_html = OperationsPage::refs_html( $r4_item['refs'], OperationsPage::ref_lookup( array( $r4_item ) ) );
check( 'A326: operations page links the dead letter to its order', false !== strpos( $r4_html, 'order_id=' . $r4_job_order_id ) || false !== strpos( $r4_html, (string) PassCaptain\Frontend\Format::order_reference( $r4_job_order['public_id'] ) ) );
check( 'A326: refs never read other payload values', array() === Queues::payload_refs( json_encode( array( 'email' => 'x@example.com', 'order_id' => 'abc', 'ticket_id' => -3 ) ) ) );

// Message queue: reason of a failed message.
Communications::enqueue( 'r4-missing-' . uniqid(), 'r4-q@example.com', 'r4_missing', array( 'order_id' => $r4_job_order_id ) );
$r4_msg_id = (int) $wpdb->get_var( "SELECT MAX(message_id) FROM {$p}wpcap_message_queue" );
Communications::process_template( 'r4_missing' );
$r4_msg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_message_queue WHERE message_id=%d", $r4_msg_id ) );
check( 'A325: a message without template names the template, not "Template fehlt oder wp_mail…"', false !== strpos( (string) $r4_msg->last_error, 'r4_missing' ) && false === strpos( (string) $r4_msg->last_error, 'Template fehlt oder wp_mail' ) );
check( 'A328: queued messages store their order (order_id column)', $r4_job_order_id === (int) $r4_msg->order_id );

/* ====================================================================================== A328 */
echo "R4: order history (A328)\n";
$r4_tpl = static function ( $tpl, $key ) { return 'r4_hist' === $key ? array( 'subject' => 'R4', 'body' => 'Hallo' ) : $tpl; };
add_filter( 'wpcap_email_template', $r4_tpl, 10, 2 );
Communications::enqueue( 'r4-hist-' . uniqid(), 'r4-q@example.com', 'r4_hist', array( 'x' => 1 ), '', $r4_job_order_id );
$r4_hist_id = (int) $wpdb->get_var( "SELECT MAX(message_id) FROM {$p}wpcap_message_queue" );
Communications::process_template( 'r4_hist' );
remove_filter( 'wpcap_email_template', $r4_tpl, 10 );
$r4_hist = $wpdb->get_row( $wpdb->prepare( "SELECT status,sent_at,order_id FROM {$p}wpcap_message_queue WHERE message_id=%d", $r4_hist_id ) );
check( 'A328: a sent queued mail records when it went out', 'sent' === $r4_hist->status && null !== $r4_hist->sent_at && $r4_job_order_id === (int) $r4_hist->order_id );
$r4_mail_log = array_filter( Log::entries( array( 'order_id' => $r4_job_order_id, 'channel' => 'mail' ) ), static fn( $r ) => 'info' === $r['level'] );
check( 'A328: successful mails of the order are logged (type, no address)', (bool) array_filter( $r4_mail_log, static fn( $r ) => 'queue_r4_hist' === ( $r['context']['mail_type'] ?? '' ) ) && (bool) array_filter( $r4_mail_log, static fn( $r ) => 'tickets' === ( $r['context']['mail_type'] ?? '' ) ) && false === strpos( json_encode( $r4_mail_log ), 'r4-job@example.com' ) );
$r4_entries = OrderHistory::entries( $r4_job_order_id );
$r4_sources = array_count_values( array_column( $r4_entries, 'source' ) );
check( 'A328: history combines audit, mails, jobs and the support log', ! empty( $r4_sources['audit'] ) && ! empty( $r4_sources['mail'] ) && ! empty( $r4_sources['job'] ) && ! empty( $r4_sources['log'] ) );
check( 'A328: history is sorted newest first', $r4_entries === array_values( ( static function ( $e ) { usort( $e, static fn( $a, $b ) => strcmp( $b['at'], $a['at'] ) ); return $e; } )( $r4_entries ) ) );
check( 'A328: history holds no buyer address or ticket code', false === strpos( json_encode( $r4_entries ), 'r4-job@example.com' ) && false === strpos( json_encode( $r4_entries ), 'r4-q@example.com' ) );
// Jobs queued before schema 3.10.16 (no order_id column value) are found by their payload.
Jobs::enqueue( 'r4-legacy-' . uniqid(), 'r4_legacy', array( 'order_id' => $r4_job_order_id ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET order_id=0 WHERE job_type='r4_legacy'" );
Jobs::enqueue( 'r4-other-' . uniqid(), 'r4_other', array( 'order_id' => (int) ( $r4_job_order_id . '1' ) ) );
$r4_labels = array_column( OrderHistory::entries( $r4_job_order_id ), 'label' );
check( 'A328: legacy jobs matched by payload, without catching order ids that only start alike', in_array( 'r4_legacy', $r4_labels, true ) && ! in_array( 'r4_other', $r4_labels, true ) );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type IN ('r4_legacy','r4_other','r4_mailfail') OR (job_type='ticket_mail_retry' AND order_id=" . (int) $r4_job_order_id . ')' );
ob_start();
OrderHistory::render( $r4_job_order_id );
$r4_out = (string) ob_get_clean();
check( 'A328: the order page renders the "Verlauf" section', false !== strpos( $r4_out, 'Verlauf' ) && false !== strpos( $r4_out, 'wpcap-order-history' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' ), 'OrderHistory::render(' ) );

/* ====================================================================================== A329 */
echo "R4: support export (A329)\n";
Log::error( 'test', 'R4 visible last error' );
$r4_info = SiteHealth::debug_information( array() );
$r4_fields = $r4_info['pass-captain-support']['fields'] ?? array();
check( 'A329: support section with version, schema, fingerprint, schema error, modules, providers, WooCommerce, currencies, locale, holds, debug, last errors', isset( $r4_fields['version'], $r4_fields['db_version'], $r4_fields['fingerprint'], $r4_fields['schema_error'], $r4_fields['modules'], $r4_fields['providers'], $r4_fields['woocommerce'], $r4_fields['currencies'], $r4_fields['locale'], $r4_fields['holds'], $r4_fields['debug'], $r4_fields['last_errors'] ) );
check( 'A329: values are filled (version, db version, last error)', WPCAP_VERSION === $r4_fields['version']['value'] && false !== strpos( $r4_fields['db_version']['value'], PassCaptain\Installer::DB_VERSION ) && false !== strpos( $r4_fields['last_errors']['value'], 'R4 visible last error' ) && false !== strpos( $r4_fields['currencies']['value'], 'EUR' ) );
$r4_stripe_before = PassCaptain\Secrets::stored( 'stripe_secret_key' );
PassCaptain\Secrets::set( 'stripe_secret_key', $r4_secret );
Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe' ) ) );
$r4_info = SiteHealth::debug_information( array() );
check( 'A329: provider mode is shown (Stripe live) without the key', false !== strpos( $r4_info['pass-captain-support']['fields']['providers']['value'], 'stripe (Live)' ) && false === strpos( json_encode( $r4_info ), $r4_secret ) && 'live' === SiteHealth::provider_mode( 'stripe' ) );
PassCaptain\Secrets::set( 'stripe_secret_key', 'sk_test_' . bin2hex( random_bytes( 6 ) ) );
check( 'A329: test keys are reported as test mode', 'test' === SiteHealth::provider_mode( 'stripe' ) );
if ( '' === (string) $r4_stripe_before ) { PassCaptain\Secrets::set( 'stripe_secret_key', '' ); }
check( 'A329: support export carries no buyer address', false === strpos( json_encode( $r4_info ), $r4_buyer ) );

/* ====================================================================================== docs */
$r4_docs = (string) file_get_contents( WPCAP_PATH . 'docs/OPERATIONS.md' ) . (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
check( 'R4: support log, debug mode and history documented', false !== strpos( $r4_docs, '## Support-Protokoll' ) && false !== strpos( $r4_docs, 'WPCAP_DEBUG' ) && false !== strpos( $r4_docs, '`wpcap_log`' ) && false !== strpos( $r4_docs, '`wpcap_payment_start_failed`' ) && false !== strpos( $r4_docs, '`wpcap_support_info`' ) );

ini_set( 'error_log', (string) $r4_log_ini );
@unlink( $r4_php_log );
$GLOBALS['wpcap_test_options'] = $r4_options;
$GLOBALS['wpcap_test_transients'] = $r4_transients;
if ( null === $r4_user ) { unset( $GLOBALS['wpcap_test_user_id'] ); } else { $GLOBALS['wpcap_test_user_id'] = $r4_user; }
PassCaptain\Settings::flush();
PassCaptain\Redact::reset();
