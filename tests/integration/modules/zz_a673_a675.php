<?php
// phpcs:ignoreFile
/**
 * A673–A675: workflow provider contract v1 (versioned envelope, field allowlist without codes/PII,
 * error codes, capability), the missing post-commit events (ticket.transferred, ticket.checked_out,
 * event.cancelled, occurrence.changed, attendance.confirmed, refund.reversed) with a schema version per
 * envelope, the refund per order/line/ticket and the outbox that is filled independently of webhook
 * subscriptions and deduplicates repeated hooks. Webhooks keep their payloads (fields only added).
 */

use PassCaptain\Orders;
use PassCaptain\Workflow\Contract;
use PassCaptain\Workflow\Events;
use PassCaptain\Workflow\Outbox;
use PassCaptain\Webhooks\Dispatcher;

require_once dirname( __DIR__, 2 ) . '/contracts/shape.php';

$a67x_hooks_before = $GLOBALS['wpcap_test_hooks'];
$a67x_filters_before = $GLOBALS['wpcap_test_filters'];
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );
Events::register();
PassCaptain\Modules\Webhooks::register();
$a67x_t = Outbox::table();
$a67x_rows = static function ( $where = '1=1' ) use ( $wpdb, $a67x_t ) { return $wpdb->get_results( "SELECT * FROM $a67x_t WHERE $where ORDER BY outbox_id" ); };
$a67x_count = static function ( $type, $subject ) use ( $wpdb, $a67x_t ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $a67x_t WHERE event_type=%s AND subject_id=%s", $type, (string) $subject ) ); };
$a67x_last = static function ( $type ) use ( $wpdb, $a67x_t ) { $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $a67x_t WHERE event_type=%s ORDER BY outbox_id DESC LIMIT 1", $type ) ); return $row ? json_decode( $row->payload, true ) : null; };

echo "A673: workflow provider contract v1\n";
check( 'A673: contract "workflow" 1.0.0 is listed with the other contracts', '1.0.0' === PassCaptain\Contracts::version( 'workflow' ) && Contract::VERSION === PassCaptain\Contracts::version( 'workflow' ) );
check( 'A673: every event type has a schema version and a subject object', ! array_filter( Contract::EVENTS, static fn( $d ) => ! preg_match( '/^\d+\.\d+$/', $d[0] ) || ! isset( Contract::OBJECTS[ $d[1] ] ) ) );
$a67x_dropped = array();
$a67x_clean = Contract::sanitize( 'ticket.checked_in', array( 'ticket_id' => 'abc', 'event_id' => '12', 'refunded' => 1, 'attendee_email' => 'x@example.com', 'token' => 'secret', 'attendee_name' => 'Max', 'source' => 'app', 'surprise' => 1 ), $a67x_dropped );
check( 'A673: the allowlist keeps contract fields with their types and drops codes, PII and unknown fields', array( 'ticket_id' => 'abc', 'event_id' => 12, 'refunded' => true, 'source' => 'app' ) === $a67x_clean && array( 'attendee_email', 'token', 'attendee_name', 'surprise' ) === $a67x_dropped );
check( 'A673: no allowlisted field is a forbidden (code/PII) key', ! array_filter( Contract::OBJECTS, static fn( $o ) => array_intersect( array_keys( $o ), Contract::FORBIDDEN ) ) && ! array_filter( Contract::EVENTS, static fn( $d ) => array_intersect( array_keys( $d[2] ), Contract::FORBIDDEN ) ) );
check( 'A673: the contract names its error codes, capability and deprecation period', isset( Contract::ERRORS['wpcap_workflow_contract_version'], Contract::ERRORS['wpcap_workflow_rejected'], Contract::ERRORS['wpcap_workflow_temporary'] ) && 'manage_options' === Contract::CAPABILITY && Contract::DEPRECATION_MINOR_RELEASES >= 1 && Contract::DEPRECATION_MONTHS >= 1 && is_array( Contract::describe()['deprecated'] ) );
$a67x_fx = wpcap_contract_fixture( 'workflow' );
$a67x_fx_ok = true;
foreach ( (array) ( $a67x_fx['endpoints'] ?? array() ) as $a67x_type => $a67x_ep ) {
	// Frozen fixture fields must still exist with the same type (removal or type change = new major).
	$a67x_current = wpcap_contract_json( array_map( static fn( $t ) => $t, Contract::fields( $a67x_type ) ) );
	$a67x_fx_ok = $a67x_fx_ok && Contract::known( $a67x_type ) && Contract::schema_version( $a67x_type ) === $a67x_ep['schema_version'] && ! array_diff( array_keys( $a67x_ep['request']['data'] ), array_keys( (array) $a67x_current ) );
}
check( 'A673: the fixture tests/contracts/workflow.json covers every event type and no field of it was removed', $a67x_fx_ok && array_keys( Contract::EVENTS ) === array_keys( (array) $a67x_fx['endpoints'] ) );

echo "A674/A675: post-commit events into the outbox (no webhook subscription, no provider)\n";
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$wpdb->query( "DELETE FROM $a67x_t" );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$a67x_type = make_type( 10, 10, 2000 );
$a67x_o = Orders::create( array( 'email' => 'wf-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $a67x_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a67x-' . uniqid() ) );
Orders::confirm_manual_payment( $a67x_o['order_id'] );
$a67x_o2 = Orders::create( array( 'email' => 'wf-full@example.com', 'items' => array( array( 'ticket_type_id' => $a67x_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a67x-f-' . uniqid() ) );
$a67x_tickets = tickets( $a67x_o['order_id'] );
$a67x_codes = ticket_code_for( $a67x_o['order_id'] );
$a67x_order = Orders::get( $a67x_o['order_id'] );
check( 'A674: order.paid and one ticket.issued per ticket are recorded without any webhook subscription', 1 === $a67x_count( 'order.paid', $a67x_order->public_id ) && 3 === count( $a67x_rows( "event_type='ticket.issued' AND subject_id IN ('" . implode( "','", array_map( static fn( $t ) => $t->public_id, $a67x_tickets ) ) . "')" ) ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries d INNER JOIN {$p}wpcap_webhook_subscriptions s ON s.subscription_id=d.subscription_id" ) );
do_action( 'wpcap_order_paid', (int) $a67x_o['order_id'] );
do_action( 'wpcap_tickets_issued', (int) $a67x_o['order_id'], 3 );
check( 'A675: repeated hooks (retries, repeated provider webhooks) record each event only once', 1 === $a67x_count( 'order.paid', $a67x_order->public_id ) && 3 === count( $a67x_rows( "event_type='ticket.issued' AND subject_id IN ('" . implode( "','", array_map( static fn( $t ) => $t->public_id, $a67x_tickets ) ) . "')" ) ) );
$a67x_issued = $a67x_last( 'ticket.issued' );
check( 'A674: envelope carries id, type, contract, schema version, occurred_at, source and a stable subject id', 0 === strpos( $a67x_issued['id'], 'evt_' ) && 'workflow' === $a67x_issued['contract'] && '1.0.0' === $a67x_issued['contract_version'] && '1.0' === $a67x_issued['schema_version'] && preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $a67x_issued['occurred_at'] ) && 'ticket' === $a67x_issued['subject']['type'] && $a67x_issued['subject']['id'] === $a67x_issued['data']['ticket_id'] );
check( 'A675: ticket.issued names the order line of the ticket (issuance per ticket with a stable id)', (int) $wpdb->get_var( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=" . (int) $a67x_o['order_id'] ) === $a67x_issued['data']['line_id'] );

// Transfer (portal) → ticket.transferred.
$a67x_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a67x_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a67x_o['order_id'] ) );
$a67x_tr = Orders::transfer_ticket( $a67x_order->public_id, $a67x_token, $a67x_tickets[2]->public_id, 'wf-friend@example.com' );
$a67x_transferred = $a67x_last( 'ticket.transferred' );
check( 'A674: a portal transfer records ticket.transferred after the commit (source, holder changes)', ! is_wp_error( $a67x_tr ) && $a67x_tickets[2]->public_id === ( $a67x_transferred['subject']['id'] ?? '' ) && 'portal' === $a67x_transferred['data']['source'] && 1 === $a67x_transferred['data']['holder_changes'] );

// Check-in (real scan) → ticket.checked_in + attendance.confirmed once per ticket.
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 7200 ) ), array( 'occurrence_id' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a67x_type" ) ) );
$a67x_in = PassCaptain\Checkin::perform_ticket( (int) $a67x_tickets[0]->ticket_id, array( 'source' => 'admin', 'idempotency_key' => 'a67x-in-' . uniqid() ) );
$a67x_checked = $a67x_last( 'ticket.checked_in' );
check( 'A674: a check-in records ticket.checked_in with its check-in id and attendance.confirmed', ! is_wp_error( $a67x_in ) && $a67x_tickets[0]->public_id === $a67x_checked['subject']['id'] && $a67x_checked['data']['checkin_id'] > 0 && 1 === $a67x_count( 'attendance.confirmed', $a67x_tickets[0]->public_id ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_tickets[0]->ticket_id, array( 'source' => 'app', 'checkin_id' => $a67x_checked['data']['checkin_id'] ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_tickets[0]->ticket_id, array( 'source' => 'app', 'checkin_id' => $a67x_checked['data']['checkin_id'] + 1000 ) );
check( 'A675: the same check-in reported twice is one event; a second admission is a new one; attendance stays once', 2 === $a67x_count( 'ticket.checked_in', $a67x_tickets[0]->public_id ) && 1 === $a67x_count( 'attendance.confirmed', $a67x_tickets[0]->public_id ) );
do_action( 'wpcap_ticket_checked_out', (int) $a67x_tickets[0]->ticket_id, array( 'source' => 'app', 'checkin_id' => 99001 ) );
do_action( 'wpcap_ticket_checked_out', (int) $a67x_tickets[0]->ticket_id, array( 'source' => 'app', 'checkin_id' => 99001 ) );
check( 'A674: ticket.checked_out is recorded once per check-out', 1 === $a67x_count( 'ticket.checked_out', $a67x_tickets[0]->public_id ) && 99001 === $a67x_last( 'ticket.checked_out' )['data']['checkin_id'] );

// Refund of one ticket → order.refunded with order, line and ticket; reversal → refund.reversed.
$a67x_refund = PassCaptain\Refunds::request( $a67x_o['order_id'], array( (int) $a67x_tickets[1]->ticket_id ), 'Gast verhindert', 'a67x-r-' . uniqid() );
$a67x_refunded = $a67x_last( 'order.refunded' );
$a67x_line = (int) $wpdb->get_var( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=" . (int) $a67x_o['order_id'] );
check( 'A675: order.refunded describes the refund per order, line and ticket (kind tickets)', is_array( $a67x_refund ) && $a67x_order->public_id === $a67x_refunded['data']['order_id'] && 'tickets' === $a67x_refunded['data']['refund']['kind'] && array( $a67x_tickets[1]->public_id ) === $a67x_refunded['data']['refund']['ticket_ids'] && array( array( 'line_id' => $a67x_line, 'ticket_type_id' => $a67x_type, 'quantity' => 1 ) ) === $a67x_refunded['data']['refund']['lines'] && 2000 === $a67x_refunded['data']['refund']['amount_minor'] );
do_action( 'wpcap_refund_reversed', (int) $a67x_refund['refund_id'], (int) $a67x_o['order_id'] );
do_action( 'wpcap_refund_reversed', (int) $a67x_refund['refund_id'], (int) $a67x_o['order_id'] );
check( 'A674: refund.reversed (storno of a refund) is recorded once with the same refund description', 1 === $a67x_count( 'refund.reversed', $a67x_order->public_id ) && (int) $a67x_refund['refund_id'] === $a67x_last( 'refund.reversed' )['data']['refund']['refund_id'] );

// Full refund outside the native flow (Woo / provider dashboard).
Orders::confirm_manual_payment( $a67x_o2['order_id'] );
Orders::mark_refunded( $a67x_o2['order_id'], 'stripe_dashboard' );
$a67x_full = $a67x_last( 'order.refunded' );
check( 'A675: a full refund outside the native flow records order.refunded (kind full, every line and ticket)', Orders::get( $a67x_o2['order_id'] )->public_id === $a67x_full['data']['order_id'] && 'full' === $a67x_full['data']['refund']['kind'] && 2 === count( $a67x_full['data']['refund']['ticket_ids'] ) && 2 === $a67x_full['data']['refund']['lines'][0]['quantity'] && null === $a67x_full['data']['refund']['refund_id'] );

// Occurrence change and event cancellation.
$a67x_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a67x_type" );
do_action( 'wpcap_occurrence_changed', $a67x_occ, array( 'location' => array( 'Wien', 'Graz' ), 'starts_at' => array( 'a', 'b' ) ), 4 );
do_action( 'wpcap_occurrence_changed', $a67x_occ, array( 'location' => array( 'Wien', 'Graz' ), 'starts_at' => array( 'a', 'b' ) ), 4 );
$a67x_changed = $a67x_last( 'occurrence.changed' );
check( 'A674: occurrence.changed is recorded once per revision, with the field names only', 1 === $a67x_count( 'occurrence.changed', (string) $a67x_occ ) && array( 'location', 'starts_at' ) === $a67x_changed['data']['changed_fields'] && false === strpos( wp_json_encode( $a67x_changed ), 'Graz' ) );
$a67x_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a67x_occ" );
$a67x_cancel = Orders::cancel_event( $a67x_event );
$a67x_ev = $a67x_last( 'event.cancelled' );
check( 'A674: an event cancellation records event.cancelled (with its occurrences) and occurrence.cancelled', ! is_wp_error( $a67x_cancel ) && (string) $a67x_event === $a67x_ev['subject']['id'] && in_array( $a67x_occ, $a67x_ev['data']['occurrence_ids'], true ) && 1 === $a67x_count( 'occurrence.cancelled', (string) $a67x_occ ) );

// No code, no address in any envelope; every envelope fulfils the frozen fixture.
$a67x_all = $a67x_rows();
$a67x_blob = implode( "\n", array_map( static fn( $r ) => $r->payload, $a67x_all ) );
$a67x_leak = false !== strpos( $a67x_blob, '@example.com' ) || false !== strpos( $a67x_blob, '@' );
foreach ( $a67x_codes as $a67x_code ) { $a67x_leak = $a67x_leak || false !== strpos( $a67x_blob, $a67x_code ) || false !== strpos( $a67x_blob, substr( $a67x_code, 0, 16 ) ); }
check( 'A673: no envelope contains a ticket code, help code or e-mail address (also with include_pii webhooks)', $a67x_all && ! $a67x_leak );
$a67x_violations = array();
foreach ( $a67x_all as $a67x_row ) {
	$a67x_env = Outbox::envelope_of( $a67x_row );
	$a67x_violations = array_merge( $a67x_violations, wpcap_contract_violations( $a67x_fx['endpoints'][ $a67x_row->event_type ]['request'], $a67x_env, $a67x_row->event_type ) );
}
check( 'A673: every recorded envelope matches tests/contracts/workflow.json' . ( $a67x_violations ? ' – ' . implode( '; ', array_slice( $a67x_violations, 0, 5 ) ) : '' ), ! $a67x_violations );
$a67x_types = array_unique( array_map( static fn( $r ) => $r->event_type, $a67x_all ) );
check( 'A674: the flow covered the new event types', ! array_diff( array( 'ticket.transferred', 'ticket.checked_out', 'event.cancelled', 'occurrence.changed', 'attendance.confirmed', 'refund.reversed' ), $a67x_types ) );

echo "A673: delivery to a provider, errors, replay\n";
check( 'A673: without a provider nothing is delivered and nothing is marked', 0 === Outbox::deliver() && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a67x_t WHERE status<>'pending'" ) && 'missing' === ( get_option( Outbox::STATUS_OPTION )['state'] ?? '' ) );
class A67x_Provider {
	public $version = '1.2.0';
	public $received = array();
	public $answers = array();
	public function contract_version() { if ( 'throw' === $this->version ) { throw new RuntimeException( 'x' ); } return $this->version; }
	public function receive( array $envelope ) {
		$this->received[] = $envelope;
		$answer = array_shift( $this->answers );
		if ( 'throw' === $answer ) { throw new RuntimeException( 'boom' ); }
		return null === $answer ? true : $answer;
	}
}
$a67x_provider = new A67x_Provider();
$GLOBALS['wpcap_test_filters'][ Contract::FILTER ] = $a67x_provider;
$a67x_provider->version = '2.0.0';
check( 'A673: a provider of another major version is never called', 0 === Outbox::deliver() && ! $a67x_provider->received && 'incompatible' === get_option( Outbox::STATUS_OPTION )['state'] && 'wpcap_workflow_contract_version' === get_option( Outbox::STATUS_OPTION )['code'] );
$a67x_provider->version = 'throw';
check( 'A673: a provider whose version check throws is never called', 0 === Outbox::deliver() && ! $a67x_provider->received );
$a67x_provider->version = '1.2.0';
$a67x_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a67x_t WHERE status='pending'" );
$a67x_provider->answers = array( new WP_Error( 'wpcap_workflow_temporary', 'later' ), 'throw', new WP_Error( 'wpcap_workflow_rejected', 'no' ), new WP_Error( 'custom', 'final', array( 'retryable' => false ) ) );
$a67x_done = Outbox::deliver( 500 );
$a67x_seq = array_map( static fn( $e ) => $e['sequence'], $a67x_provider->received );
$a67x_sorted = $a67x_seq;
sort( $a67x_sorted );
check( 'A673: a compatible provider (any 1.x) receives every event once, in sequence order', $a67x_pending === $a67x_done && $a67x_pending === count( $a67x_provider->received ) && $a67x_seq === $a67x_sorted && 1 === $a67x_provider->received[0]['attempt'] && false === $a67x_provider->received[0]['replay'] );
$a67x_status = $wpdb->get_results( "SELECT outbox_id,status,attempts,last_error FROM $a67x_t ORDER BY outbox_id LIMIT 4" );
check( 'A673: temporary errors and exceptions are retried, rejections (and retryable=false) are final', 'pending' === $a67x_status[0]->status && 'wpcap_workflow_temporary' === $a67x_status[0]->last_error && 'pending' === $a67x_status[1]->status && 'wpcap_workflow_provider_error' === $a67x_status[1]->last_error && 'failed' === $a67x_status[2]->status && 'failed' === $a67x_status[3]->status && 'delivered' === $wpdb->get_var( "SELECT status FROM $a67x_t ORDER BY outbox_id LIMIT 4,1" ) );
$a67x_provider->received = array();
check( 'A673: retries wait for their backoff', 0 === Outbox::deliver() );
$wpdb->query( "UPDATE $a67x_t SET next_attempt_at='2000-01-01 00:00:00' WHERE status='pending'" );
Outbox::deliver();
check( 'A673: a due retry is delivered again with attempt 2', 2 === count( $a67x_provider->received ) && 2 === $a67x_provider->received[0]['attempt'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a67x_t WHERE status='pending'" ) );
$a67x_since = Outbox::since( 0, 3 );
check( 'A673: Outbox::since() lets a provider catch up by sequence (replay flag, no attempt)', 3 === count( $a67x_since ) && $a67x_since[0]['sequence'] < $a67x_since[1]['sequence'] && true === $a67x_since[0]['replay'] && ! isset( $a67x_since[0]['attempt'] ) && 1 === count( Outbox::since( 0, 1, array( 'event.cancelled' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Contract::CAPABILITY );
$a67x_denied = Outbox::replay( 0 );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A673: replay needs the contract capability', is_wp_error( $a67x_denied ) && 'wpcap_workflow_forbidden' === $a67x_denied->get_error_code() );
$a67x_first = (int) $wpdb->get_var( "SELECT MAX(outbox_id) FROM $a67x_t" );
$a67x_provider->received = array();
check( 'A673: replay queues events again and the provider sees replay = true', 1 === Outbox::replay( $a67x_first ) && 1 === Outbox::deliver() && true === $a67x_provider->received[0]['replay'] );
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );

echo "A674/A675: webhooks (backward compatible)\n";
$a67x_sub = Dispatcher::create_subscription( 'https://wf.hooks.example/in', array( 'order.refunded', 'ticket.checked_in', 'attendance.confirmed', 'ticket.transferred', 'event.cancelled', 'refund.reversed', 'ticket.checked_out', 'occurrence.changed' ), true );
check( 'A674: the new event types can be subscribed', ! is_wp_error( $a67x_sub ) && 8 === count( explode( ',', Dispatcher::subscription( $a67x_sub['subscription_id'] )->events ) ) );
$a67x_wtype = make_type( 10, 10, 1500 );
$a67x_w = Orders::create( array( 'email' => 'wf-web@example.com', 'items' => array( array( 'ticket_type_id' => $a67x_wtype, 'quantity' => 2 ) ), 'idempotency_key' => 'a67x-w-' . uniqid() ) );
Orders::confirm_manual_payment( $a67x_w['order_id'] );
$a67x_wt = tickets( $a67x_w['order_id'] );
$a67x_wr = PassCaptain\Refunds::request( $a67x_w['order_id'], array( (int) $a67x_wt[0]->ticket_id ), 'Storno', 'a67x-wr-' . uniqid() );
$a67x_wp = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='order.refunded' ORDER BY delivery_id DESC LIMIT 1", $a67x_sub['subscription_id'] ) ), true );
$a67x_wref = $a67x_wp['data']['object']['refund'] ?? array();
check( 'A675: webhook order.refunded keeps id/amount_minor/currency/ticket_ids and adds kind, source and lines', (int) $a67x_wr['refund_id'] === $a67x_wref['id'] && 1500 === $a67x_wref['amount_minor'] && 'EUR' === $a67x_wref['currency'] && array( $a67x_wt[0]->public_id ) === $a67x_wref['ticket_ids'] && 'tickets' === $a67x_wref['kind'] && 1 === $a67x_wref['lines'][0]['quantity'] );
check( 'A674: every webhook payload carries its schema version (api_version unchanged)', '1.1' === $a67x_wp['schema_version'] && Dispatcher::API_VERSION === $a67x_wp['api_version'] && '2026-11-01' === Dispatcher::API_VERSION );
check( 'A674: webhook and workflow use the same event id for the same business event', $a67x_wp['id'] === Outbox::event_id( 'order.refunded', 'refund|' . (int) $a67x_wr['refund_id'] ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_wt[1]->ticket_id, array( 'source' => 'app', 'checkin_id' => 88001 ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_wt[1]->ticket_id, array( 'source' => 'app', 'checkin_id' => 88001 ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_wt[1]->ticket_id, array( 'source' => 'app', 'checkin_id' => 88002 ) );
$a67x_wcount = static function ( $type ) use ( $wpdb, $p, $a67x_sub ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type=%s", $a67x_sub['subscription_id'], $type ) ); };
check( 'A674/A675: webhook check-ins are deduplicated per check-in, attendance.confirmed once per ticket', 2 === $a67x_wcount( 'ticket.checked_in' ) && 1 === $a67x_wcount( 'attendance.confirmed' ) );
$a67x_att = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='attendance.confirmed'", $a67x_sub['subscription_id'] ) ), true );
check( 'A674: include_pii webhooks keep their documented opt-in field (attendee_email) – the workflow contract never carries it', 'ticket' === $a67x_att['data']['object']['object'] && 'check_in' === $a67x_att['data']['object']['method'] && array_key_exists( 'attendee_email', $a67x_att['data']['object'] ) === ( '' !== (string) $wpdb->get_var( 'SELECT attendee_email FROM ' . $p . 'wpcap_tickets WHERE ticket_id=' . (int) $a67x_wt[1]->ticket_id ) ) && false === strpos( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM $a67x_t WHERE event_type='attendance.confirmed' AND subject_id=%s", $a67x_wt[1]->public_id ) ), '@' ) );
do_action( 'wpcap_refund_reversed', (int) $a67x_wr['refund_id'], (int) $a67x_w['order_id'] );
$a67x_rev = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='refund.reversed'", $a67x_sub['subscription_id'] ) ), true );
check( 'A674: webhook refund.reversed names the refund and reversed = true', true === ( $a67x_rev['data']['object']['refund']['reversed'] ?? null ) && (int) $a67x_wr['refund_id'] === $a67x_rev['data']['object']['refund']['id'] );

// Outbox recording never disturbs the caller's error state and works without its table.
$wpdb->last_error = 'earlier error';
Outbox::record( 'order.paid', 'a67x-error-state', 'x', array() );
check( 'A675: recording keeps $wpdb->last_error of the caller', 'earlier error' === $wpdb->last_error );
$wpdb->last_error = '';
check( 'A673: unknown event types are not recorded', false === Outbox::record( 'order.deleted', 'x', 'x', array() ) );
$wpdb->query( "DELETE FROM $a67x_t WHERE created_at<'2100-01-01'" );
$GLOBALS['wpcap_test_hooks'] = $a67x_hooks_before;
$GLOBALS['wpcap_test_filters'] = $a67x_filters_before;
