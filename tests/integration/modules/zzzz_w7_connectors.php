<?php
/**
 * Connectors receive the plugin's domain events through the job queue (ConnectorEvents):
 * enabled connectors only, one job per event and connector, retries after exceptions and errors,
 * a stable idempotency key per event and connector.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Integrations\ConnectorEvents;
use PassCaptain\Integrations\ConnectorInterface;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Refunds;

final class W7_Fake_Connector implements ConnectorInterface {
	public array $received = array();
	/** @var array<int,string> Next results: 'throw', 'error', 'false'; consumed per delivery. */
	public array $fail_next = array();
	public function __construct( private string $k ) {}
	public function key() { return $this->k; }
	public function version() { return 1; }
	public function capabilities() { return array( 'events' ); }
	public function deliver( $event_name, array $payload, $idempotency_key ) {
		$mode = array_shift( $this->fail_next );
		if ( 'throw' === $mode ) { throw new RuntimeException( 'connector down' ); }
		if ( 'error' === $mode ) { return new WP_Error( 'w7_down', 'temporarily down' ); }
		if ( 'false' === $mode ) { return false; }
		$this->received[] = array( 'event' => $event_name, 'payload' => $payload, 'key' => $idempotency_key );
		return true;
	}
	public function events() { return array_column( $this->received, 'event' ); }
}

global $wpdb;
$p = $wpdb->prefix;
$w7c_fake = new W7_Fake_Connector( 'w7fake' );
$w7c_other = new W7_Fake_Connector( 'w7other' ); // Registered but not enabled.
$w7c_filter = static function ( $list ) use ( $w7c_fake, $w7c_other ) { $list[] = $w7c_fake; $list[] = $w7c_other; return $list; };
$w7c_jobs = static function () use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='connector_delivery'" ); };
$w7c_run = static function () use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='connector_delivery' AND status='queued'" );
	Jobs::process_due( 200 );
};
ConnectorEvents::register();
$w7c_errors = array();
$w7c_error_cb = static function ( $key, $event, $message ) use ( &$w7c_errors ) { $w7c_errors[] = "$key:$event:$message"; };
add_action( 'wp_pass_captain_connector_error_v1', $w7c_error_cb, 10, 3 );

echo "W7: connectors receive domain events through the job queue\n";
$type = make_type( 100, 10, 1500 );
$before = $w7c_jobs();
admin_paid_order( $type, 1, 'w7c-none@example.com' );
check( 'no connector enabled: nothing is queued', $before === $w7c_jobs() );

add_filter( 'wp_pass_captain_connectors_v1', $w7c_filter );
update_option( 'wpcap_enabled_connectors', array( 'w7fake' ) );
check( 'enabled_keys lists only registered and enabled connectors', array( 'w7fake' ) === ConnectorEvents::enabled_keys() );
$oid = admin_paid_order( $type, 2, 'w7c@example.com' );
check( 'paid order queues order_paid and tickets_issued for the enabled connector only', $w7c_jobs() === $before + 2 );
check( 'delivery is asynchronous: nothing delivered before the queue runs', array() === $w7c_fake->received );
$w7c_run();
check( 'queue run delivers order_paid and tickets_issued', array( 'order_paid', 'tickets_issued' ) === $w7c_fake->events() && array() === $w7c_other->received );
$paid = $w7c_fake->received[0];
check( 'payload: version, event, order identifiers, no e-mail address', 1 === $paid['payload']['version'] && 'order_paid' === $paid['payload']['event'] && $oid === $paid['payload']['data']['order_id'] && 'paid' === $paid['payload']['data']['payment_status'] && false === strpos( wp_json_encode( $paid['payload'] ), 'w7c@example.com' ) );
check( 'tickets_issued lists the issued tickets', 2 === count( $w7c_fake->received[1]['payload']['data']['tickets'] ) );
check( 'connector idempotency key = sha256(event key | connector key)', hash( 'sha256', 'order_paid|' . $oid . '|w7fake' ) === $paid['key'] );
$n = $w7c_jobs();
ConnectorEvents::order_paid( $oid );
check( 'the same domain event is queued only once per connector', $n === $w7c_jobs() );

// Check-in, refund, order refunded, occurrence events, cancellation.
$tids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $oid ) );
do_action( 'wpcap_ticket_checked_in', $tids[0], array( 'source' => 'web', 'idempotency_key' => 'w7c-scan-1', 'device_id' => 3 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
Refunds::request( $oid, array(), 'Test', 'w7c-refund-' . uniqid() );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
do_action( 'wpcap_occurrence_rescheduled', $occ, '2030-01-01 18:00:00', '2030-01-02 18:00:00' );
do_action( 'wpcap_occurrence_cancelled', $occ, 3 );
wpcap_test_reset_transients();
$pending = Orders::create( array( 'email' => 'w7c-cancel@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w7c-cancel-' . uniqid() ) );
Orders::cancel_order( $pending['order_id'], 'test' );
$w7c_fake->received = array();
$w7c_run();
$events = $w7c_fake->events();
sort( $events );
check( 'check-in, refund, order refunded, reschedule, occurrence cancel and order cancel are delivered', array( 'occurrence_cancelled', 'occurrence_rescheduled', 'order_cancelled', 'order_refunded', 'refund_completed', 'ticket_checked_in' ) === $events );
$by_event = array_column( $w7c_fake->received, 'payload', 'event' );
check( 'event payloads carry the right identifiers', $tids[0] === $by_event['ticket_checked_in']['data']['ticket_id'] && 3 === $by_event['ticket_checked_in']['data']['device_id'] && $oid === $by_event['refund_completed']['data']['order_id'] && 'refunded' === $by_event['order_refunded']['data']['status'] && '2030-01-02 18:00:00' === $by_event['occurrence_rescheduled']['data']['new_starts_at'] && 3 === $by_event['occurrence_cancelled']['data']['issued_tickets'] && (int) $pending['order_id'] === $by_event['order_cancelled']['data']['order_id'] );

echo "W7: failing connectors are retried, others are not affected\n";
update_option( 'wpcap_enabled_connectors', array( 'w7fake', 'w7other' ) );
$w7c_fake->received = array();
$w7c_other->received = array();
$w7c_errors = array();
$w7c_fake->fail_next = array( 'throw', 'error' );
do_action( 'wpcap_occurrence_rescheduled', $occ, '2030-01-02 18:00:00', '2030-01-03 18:00:00' );
$w7c_run();
$job = $wpdb->get_row( "SELECT status,attempts FROM {$p}wpcap_jobs WHERE job_type='connector_delivery' AND payload LIKE '{\"connector\":\"w7fake\"%' ORDER BY job_id DESC LIMIT 1" );
check( 'exception: error action fired, job stays queued, other connector delivered once', array() === $w7c_fake->received && 1 === count( $w7c_other->received ) && 'queued' === $job->status && 1 === (int) $job->attempts && array( 'w7fake:occurrence_rescheduled:connector down' ) === $w7c_errors );
$w7c_run();
check( 'WP_Error result: retried again, reported', array() === $w7c_fake->received && 2 === count( $w7c_errors ) && 1 === count( $w7c_other->received ) );
$w7c_run();
$job = $wpdb->get_row( "SELECT status,attempts FROM {$p}wpcap_jobs WHERE job_type='connector_delivery' AND payload LIKE '{\"connector\":\"w7fake\"%' ORDER BY job_id DESC LIMIT 1" );
check( 'third attempt delivers exactly once; the other connector is not called again', 1 === count( $w7c_fake->received ) && 'complete' === $job->status && 1 === count( $w7c_other->received ) );
check( 'retries use the same idempotency key', hash( 'sha256', 'occurrence_rescheduled|' . $occ . '|2030-01-03 18:00:00|w7fake' ) === $w7c_fake->received[0]['key'] );

// A connector disabled after queueing: the job ends without delivery.
$w7c_fake->received = array();
do_action( 'wpcap_occurrence_rescheduled', $occ, '2030-01-03 18:00:00', '2030-01-04 18:00:00' );
update_option( 'wpcap_enabled_connectors', array( 'w7other' ) );
$w7c_run();
check( 'disabled connector: queued job completes without delivery', array() === $w7c_fake->received && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='connector_delivery' AND status='queued'" ) );

remove_filter( 'wp_pass_captain_connectors_v1', $w7c_filter );
remove_action( 'wp_pass_captain_connector_error_v1', $w7c_error_cb, 10 );
update_option( 'wpcap_enabled_connectors', array() );
