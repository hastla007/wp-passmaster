<?php
/**
 * A343: a paid order without tickets is processed again automatically – the webhook is not
 * acknowledged when the issuance fails (redelivery + retry job), and the cron sweep queues a retry
 * job for paid orders whose issuance never ran (process killed, job lost).
 *
 * Included from run.php (uses check(), make_type(), tickets() and the HTTP/REST fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;
$a343_jobs = static function ( $order_id ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='ticket_issue_retry' AND payload=%s AND status IN ('queued','running')", wp_json_encode( array( 'order_id' => (int) $order_id ) ) ) );
};

echo "A343: failed issuance after a webhook payment\n";
Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => 'sk_test_a343', 'stripe_webhook_secret' => 'whsec_test' ) );
Registry::reset();
http_reset();
$a343_o = provider_order( 'stripe', make_type( 10, 10, 1500 ), 1, 'a343-webhook' );
$a343_event = stripe_session_event( 'evt_a343_paid', 'checkout.session.completed', $a343_o, array( 'id' => 'cs_a343', 'payment_intent' => 'pi_a343' ) );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_tickets \(/', 'lock_timeout', array( 'rollback' => true ) );
$a343_first = Webhooks::handle( stripe_request( $a343_event ) );
wpcap_test_faults_clear();
check( 'A343: issuance failure is answered with a 5xx (the provider redelivers)', is_wp_error( $a343_first ) && (int) ( $a343_first->get_error_data()['status'] ?? 0 ) >= 500 );
check( 'A343: the order is paid without tickets, the event is not processed, a retry job is queued', 'paid' === Orders::get( $a343_o['order_id'] )->payment_status && ! tickets( $a343_o['order_id'] ) && null === $wpdb->get_var( "SELECT status FROM {$p}wpcap_payment_events WHERE provider='stripe' AND provider_event_id='evt_a343_paid'" ) && 1 === $a343_jobs( $a343_o['order_id'] ) );
Webhooks::handle( stripe_request( $a343_event ) );
check( 'A343: the redelivery issues the ticket once', 1 === count( tickets( $a343_o['order_id'], 'valid' ) ) );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='ticket_issue_retry'" );

echo "A343: sweep of paid orders whose issuance never ran\n";
$a343_m = Orders::create( array( 'email' => 'a343-sweep@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 10, 10, 1500 ), 'quantity' => 2 ) ), 'idempotency_key' => 'a343-sweep' ) );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_tickets \(/', 'lock_timeout', array( 'rollback' => true ) );
Orders::confirm_manual_payment( $a343_m['order_id'] );
wpcap_test_faults_clear();
// The process died before (or the enqueue of) the retry job: nothing is queued any more.
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='ticket_issue_retry'" );
check( 'A343: setup – paid, no tickets, no job', 'paid' === Orders::get( $a343_m['order_id'] )->payment_status && ! tickets( $a343_m['order_id'] ) && 0 === $a343_jobs( $a343_m['order_id'] ) );
Orders::sweep_unissued( 1000 ); // Other modules may leave recent paid orders without tickets behind.
check( 'A343: within the grace period nothing is swept', 0 === $a343_jobs( $a343_m['order_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET updated_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ), $a343_m['order_id'] ) );
$a343_old = Orders::create( array( 'email' => 'a343-old@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 10, 10, 1500 ), 'quantity' => 1 ) ), 'idempotency_key' => 'a343-old' ) );
wpcap_test_fault( '/^INSERT INTO \S*wpcap_tickets \(/', 'lock_timeout', array( 'rollback' => true ) );
Orders::confirm_manual_payment( $a343_old['order_id'] );
wpcap_test_faults_clear();
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='ticket_issue_retry'" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET updated_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), $a343_old['order_id'] ) );
check( 'A343: the hourly cron runs the sweep', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), "add_action( 'wpcap_cleanup_reservations', array( Orders::class, 'sweep_unissued' )" ) );
$a343_swept = Orders::sweep_unissued( 1000 );
check( 'A343: the sweep queues one retry job for the recent order only', $a343_swept >= 1 && 1 === $a343_jobs( $a343_m['order_id'] ) && 0 === $a343_jobs( $a343_old['order_id'] ) && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='ticket_issue_swept' AND object_id=%d", $a343_m['order_id'] ) ) > 0 );
check( 'A343: a second sweep queues nothing more', 0 === Orders::sweep_unissued() && 1 === $a343_jobs( $a343_m['order_id'] ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='ticket_issue_retry'" );
$a343_handler = array( Orders::class, 'handle_job' );
add_filter( 'wpcap_process_job', $a343_handler, 10, 4 );
Jobs::process_due( 50 );
remove_filter( 'wpcap_process_job', $a343_handler, 10 );
check( 'A343: the job issues the missing tickets', 2 === count( tickets( $a343_m['order_id'], 'valid' ) ) && 0 === $a343_jobs( $a343_m['order_id'] ) );
check( 'A343: an order paid long ago is left to the Doctor check', ! tickets( $a343_old['order_id'] ) );
// Leave no paid order without tickets behind for later checks.
Orders::issue_and_email( $a343_old['order_id'] );
http_reset();
