<?php
// phpcs:ignoreFile
/**
 * A673–A675 after PC-7 (no workflow contract in the core): the event outbox (Suite\Outbox) that serves
 * the review contract – once per event id, allowlist, delivery in sequence order with retries, backoff,
 * final rejections, provider version check, pull (since) and replay with capability – and the webhook
 * side of A674/A675 that stays: refund per order/line/ticket (kind tickets | full), stable event ids,
 * deduplicated check-ins, include_pii opt-in, refund.reversed.
 */

use PassCaptain\Orders;
use PassCaptain\Review\Contract;
use PassCaptain\Suite\Outbox;
use PassCaptain\Webhooks\Dispatcher;

$a67x_hooks_before = $GLOBALS['wpcap_test_hooks'];
$a67x_filters_before = $GLOBALS['wpcap_test_filters'];
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );
PassCaptain\Modules\Webhooks::register();
$a67x_t = Outbox::table();
$wpdb->query( "DELETE FROM $a67x_t" );

echo "A675: event outbox of the review contract\n";
$a67x_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) make_type( 5 ) );
$a67x_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a67x_occ" );
$a67x_record = static function ( $key ) use ( $a67x_occ, $a67x_event ) {
	$experience = 'occurrence:' . $a67x_occ;
	return Outbox::record( 'experience_cancelled', 'a67x|' . $key, $experience, array( 'experience_id' => $experience, 'scope' => 'occurrence', 'event_id' => $a67x_event, 'occurrence_ids' => array( $a67x_occ ), 'outcome' => 'cancelled', 'cancelled_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'attendee_email' => 'leak@example.com', 'surprise' => 1 ) );
};
$a67x_ids = array();
foreach ( range( 1, 5 ) as $a67x_i ) { $a67x_ids[] = $a67x_record( $a67x_i ); }
check( 'A675: every new event is recorded once with a stable id; the same business event again records nothing', 5 === count( array_unique( array_filter( $a67x_ids ) ) ) && false === $a67x_record( 1 ) && $a67x_ids[0] === Outbox::event_id( 'experience_cancelled', 'a67x|1' ) );
$a67x_payload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM $a67x_t WHERE event_id=%s", (string) $a67x_ids[0] ) );
check( 'A673: the allowlist drops personal data and unknown fields before recording', '' !== $a67x_payload && false === strpos( $a67x_payload, '@' ) && false === strpos( $a67x_payload, 'surprise' ) );
check( 'PC-7: rows carry the review contract; workflow event types are not recorded', 5 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a67x_t WHERE contract='review'" ) && false === Outbox::record( 'order.paid', 'a67x-wf', 'x', array() ) && null === Outbox::contract_for( 'ticket.checked_in' ) );
check( 'A673: without a provider nothing is delivered and nothing is marked', 0 === Outbox::deliver() && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a67x_t WHERE status<>'pending'" ) && 'missing' === ( get_option( Contract::status_option() )['state'] ?? '' ) );

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
check( 'A673: a provider of another major version is never called', 0 === Outbox::deliver() && ! $a67x_provider->received && 'incompatible' === get_option( Contract::status_option() )['state'] && 'wpcap_review_contract_version' === get_option( Contract::status_option() )['code'] );
$a67x_provider->version = 'throw';
check( 'A673: a provider whose version check throws is never called', 0 === Outbox::deliver() && ! $a67x_provider->received );
$a67x_provider->version = '1.2.0';
$a67x_provider->answers = array( new WP_Error( 'wpcap_review_temporary', 'later' ), 'throw', new WP_Error( 'wpcap_review_rejected', 'no' ), new WP_Error( 'custom', 'final', array( 'retryable' => false ) ) );
$a67x_done = Outbox::deliver( 500 );
$a67x_seq = array_map( static fn( $e ) => $e['sequence'], $a67x_provider->received );
$a67x_sorted = $a67x_seq;
sort( $a67x_sorted );
check( 'A673: a compatible provider (any 1.x) receives every event once, in sequence order', 5 === $a67x_done && 5 === count( $a67x_provider->received ) && $a67x_seq === $a67x_sorted && 1 === $a67x_provider->received[0]['attempt'] && false === $a67x_provider->received[0]['replay'] && 'review' === $a67x_provider->received[0]['contract'] );
$a67x_status = $wpdb->get_results( "SELECT outbox_id,status,attempts,last_error FROM $a67x_t ORDER BY outbox_id" );
check( 'A673: temporary errors and exceptions are retried, rejections (and retryable=false) are final', 'pending' === $a67x_status[0]->status && 'wpcap_review_temporary' === $a67x_status[0]->last_error && 'pending' === $a67x_status[1]->status && 'wpcap_review_provider_error' === $a67x_status[1]->last_error && 'failed' === $a67x_status[2]->status && 'failed' === $a67x_status[3]->status && 'delivered' === $a67x_status[4]->status );
$a67x_provider->received = array();
check( 'A673: retries wait for their backoff', 0 === Outbox::deliver() );
$wpdb->query( "UPDATE $a67x_t SET next_attempt_at='2000-01-01 00:00:00' WHERE status='pending'" );
Outbox::deliver();
check( 'A673: a due retry is delivered again with attempt 2', 2 === count( $a67x_provider->received ) && 2 === $a67x_provider->received[0]['attempt'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a67x_t WHERE status='pending'" ) );
$a67x_since = Outbox::since( 0, 3 );
check( 'A673: Outbox::since() lets a provider catch up by sequence (replay flag, no attempt)', 3 === count( $a67x_since ) && $a67x_since[0]['sequence'] < $a67x_since[1]['sequence'] && true === $a67x_since[0]['replay'] && ! isset( $a67x_since[0]['attempt'] ) && 5 === count( Outbox::since( 0, 10, array( 'experience_cancelled' ), 'review' ) ) && array() === Outbox::since( 0, 10, array(), 'workflow' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Contract::CAPABILITY );
$a67x_denied = Outbox::replay( 0 );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A673: replay needs the contract capability', is_wp_error( $a67x_denied ) && 'wpcap_review_forbidden' === $a67x_denied->get_error_code() );
$a67x_first = (int) $wpdb->get_var( "SELECT MAX(outbox_id) FROM $a67x_t" );
$a67x_provider->received = array();
check( 'A673: replay queues events again and the provider sees replay = true', 1 === Outbox::replay( $a67x_first ) && 1 === Outbox::deliver() && true === $a67x_provider->received[0]['replay'] );
$wpdb->insert( $a67x_t, array( 'contract' => 'workflow', 'event_id' => 'evt_a67x_legacy_' . substr( md5( uniqid() ), 0, 12 ), 'event_type' => 'order.paid', 'payload' => '{}', 'occurred_at' => gmdate( 'Y-m-d H:i:s' ), 'status' => 'pending', 'next_attempt_at' => '2000-01-01 00:00:00', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a67x_provider->received = array();
check( 'PC-7: a leftover workflow row of an older version is never delivered', 0 === Outbox::deliver() && ! $a67x_provider->received && 'pending' === $wpdb->get_var( "SELECT status FROM $a67x_t WHERE contract='workflow'" ) );
unset( $GLOBALS['wpcap_test_filters'][ Contract::FILTER ] );
$wpdb->last_error = 'earlier error';
$a67x_record( 'error-state' );
check( 'A675: recording keeps $wpdb->last_error of the caller', 'earlier error' === $wpdb->last_error );
$wpdb->last_error = '';
check( 'A673: unknown event types are not recorded', false === Outbox::record( 'order.deleted', 'x', 'x', array() ) );

echo "A674/A675: webhooks (refund per order, line and ticket; stable ids)\n";
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$a67x_sub = Dispatcher::create_subscription( 'https://wf.hooks.example/in', array( 'order.refunded', 'ticket.checked_in', 'attendance.confirmed', 'ticket.transferred', 'event.cancelled', 'refund.reversed', 'ticket.checked_out', 'occurrence.changed' ), true );
check( 'A674: the event types can be subscribed', ! is_wp_error( $a67x_sub ) && 8 === count( explode( ',', Dispatcher::subscription( $a67x_sub['subscription_id'] )->events ) ) );
$a67x_wtype = make_type( 10, 10, 1500 );
$a67x_w = Orders::create( array( 'email' => 'wf-web@example.com', 'items' => array( array( 'ticket_type_id' => $a67x_wtype, 'quantity' => 2 ) ), 'idempotency_key' => 'a67x-w-' . uniqid() ) );
Orders::confirm_manual_payment( $a67x_w['order_id'] );
$a67x_wt = tickets( $a67x_w['order_id'] );
$a67x_wr = PassCaptain\Refunds::request( $a67x_w['order_id'], array( (int) $a67x_wt[0]->ticket_id ), 'Storno', 'a67x-wr-' . uniqid() );
$a67x_wp = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='order.refunded' ORDER BY delivery_id DESC LIMIT 1", $a67x_sub['subscription_id'] ) ), true );
$a67x_wref = $a67x_wp['data']['object']['refund'] ?? array();
$a67x_line = (int) $wpdb->get_var( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=" . (int) $a67x_w['order_id'] );
check( 'A675: webhook order.refunded keeps id/amount_minor/currency/ticket_ids and adds kind, source and lines', (int) $a67x_wr['refund_id'] === $a67x_wref['id'] && 1500 === $a67x_wref['amount_minor'] && 'EUR' === $a67x_wref['currency'] && array( $a67x_wt[0]->public_id ) === $a67x_wref['ticket_ids'] && 'tickets' === $a67x_wref['kind'] && array( array( 'line_id' => $a67x_line, 'ticket_type_id' => $a67x_wtype, 'quantity' => 1 ) ) === $a67x_wref['lines'] );
check( 'A674: every webhook payload carries its schema version (api_version unchanged)', '1.1' === $a67x_wp['schema_version'] && Dispatcher::API_VERSION === $a67x_wp['api_version'] && '2026-11-01' === Dispatcher::API_VERSION );
check( 'A674: the webhook event id is stable per business event (type + refund)', $a67x_wp['id'] === Outbox::event_id( 'order.refunded', 'refund|' . (int) $a67x_wr['refund_id'] ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_wt[1]->ticket_id, array( 'source' => 'app', 'checkin_id' => 88001 ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_wt[1]->ticket_id, array( 'source' => 'app', 'checkin_id' => 88001 ) );
do_action( 'wpcap_ticket_checked_in', (int) $a67x_wt[1]->ticket_id, array( 'source' => 'app', 'checkin_id' => 88002 ) );
$a67x_wcount = static function ( $type ) use ( $wpdb, $p, $a67x_sub ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type=%s", $a67x_sub['subscription_id'], $type ) ); };
check( 'A674/A675: webhook check-ins are deduplicated per check-in, attendance.confirmed once per ticket', 2 === $a67x_wcount( 'ticket.checked_in' ) && 1 === $a67x_wcount( 'attendance.confirmed' ) );
$a67x_att = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='attendance.confirmed'", $a67x_sub['subscription_id'] ) ), true );
check( 'A674: include_pii webhooks keep their documented opt-in field (attendee_email)', 'ticket' === $a67x_att['data']['object']['object'] && 'check_in' === $a67x_att['data']['object']['method'] && array_key_exists( 'attendee_email', $a67x_att['data']['object'] ) === ( '' !== (string) $wpdb->get_var( 'SELECT attendee_email FROM ' . $p . 'wpcap_tickets WHERE ticket_id=' . (int) $a67x_wt[1]->ticket_id ) ) );
do_action( 'wpcap_refund_reversed', (int) $a67x_wr['refund_id'], (int) $a67x_w['order_id'] );
$a67x_rev = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='refund.reversed'", $a67x_sub['subscription_id'] ) ), true );
check( 'A674: webhook refund.reversed names the refund and reversed = true', true === ( $a67x_rev['data']['object']['refund']['reversed'] ?? null ) && (int) $a67x_wr['refund_id'] === $a67x_rev['data']['object']['refund']['id'] );
$a67x_f = Orders::create( array( 'email' => 'wf-full@example.com', 'items' => array( array( 'ticket_type_id' => $a67x_wtype, 'quantity' => 2 ) ), 'idempotency_key' => 'a67x-f-' . uniqid() ) );
Orders::confirm_manual_payment( $a67x_f['order_id'] );
Orders::mark_refunded( $a67x_f['order_id'], 'stripe_dashboard' );
$a67x_full = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='order.refunded' ORDER BY delivery_id DESC LIMIT 1", $a67x_sub['subscription_id'] ) ), true );
$a67x_fref = $a67x_full['data']['object']['refund'] ?? array();
check( 'A675: a full refund outside the native flow is webhook order.refunded kind full with every ticket and line', 'full' === ( $a67x_fref['kind'] ?? '' ) && null === $a67x_fref['id'] && 2 === count( $a67x_fref['ticket_ids'] ) && 2 === $a67x_fref['lines'][0]['quantity'] && 'stripe_dashboard' === $a67x_fref['source'] );
$a67x_blob = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(payload) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type IN ('order.refunded','refund.reversed')", $a67x_sub['subscription_id'] ) );
$a67x_leak = array_filter( array_merge( ticket_code_for( $a67x_w['order_id'] ), ticket_code_for( $a67x_f['order_id'] ) ), static fn( $c ) => '' !== (string) $c && false !== strpos( $a67x_blob, (string) $c ) );
check( 'A675: no ticket code in the refund webhooks', '' !== $a67x_blob && ! $a67x_leak );
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$wpdb->query( "DELETE FROM $a67x_t WHERE created_at<'2100-01-01'" );
$GLOBALS['wpcap_test_hooks'] = $a67x_hooks_before;
$GLOBALS['wpcap_test_filters'] = $a67x_filters_before;
