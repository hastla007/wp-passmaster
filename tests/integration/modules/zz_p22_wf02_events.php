<?php
/**
 * PASS-WF-02 (end to end): the four events ticket.checked_out, ticket.transferred, attendance.confirmed and
 * event.cancelled arrive as webhook and as connector event (contract 1.2.0) from the places that really
 * fire them – Checkin::check_out(), Checkin::perform(), wpcap_confirm_attendance(), the portal transfer,
 * the holder change by assignment, the admin holder change and Orders::cancel_event() – once per business
 * event, with the documented fields (docs/WEBHOOKS.md, docs/SUITE-EVENTS.md), without ticket code and,
 * without include_pii, without e-mail address or name. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Integrations\ConnectorEvents;
use PassCaptain\Integrations\ConnectorInterface;
use PassCaptain\Jobs;
use PassCaptain\Modules\Webhooks;
use PassCaptain\Orders;
use PassCaptain\TicketAssignments;
use PassCaptain\Webhooks\Dispatcher;

require_once WPCAP_PATH . 'src/functions.php';

if ( ! class_exists( 'P22_Wf02_Connector' ) ) {
	final class P22_Wf02_Connector implements ConnectorInterface {
		public array $received = array();
		public function key() { return 'p22_wf02'; }
		public function version() { return '1.0.0'; }
		public function capabilities() { return array( 'tickets' ); }
		public function deliver( $event_name, array $payload, $idempotency_key ) {
			$this->received[] = array( 'event' => $event_name, 'payload' => $payload, 'key' => $idempotency_key );
			return true;
		}
		public function of( $event ) { return array_values( array_filter( $this->received, static fn( $r ) => $event === $r['event'] ) ); }
	}
}

global $wpdb;
$p = $wpdb->prefix;
$p22_hooks_before = $GLOBALS['wpcap_test_hooks'];
$p22_filters_before = $GLOBALS['wpcap_test_filters'];
$p22_user_before = $GLOBALS['wpcap_test_user_id'] ?? 1;
$p22_enabled_before = get_option( 'wpcap_enabled_connectors', array() );
// ConnectorEvents::register() runs once per request (static flag); the hooks are restored at the end, so the
// flag is restored too – later modules that call register() must get the hooks again.
$p22_reg_flag = new ReflectionProperty( ConnectorEvents::class, 'registered' );
$p22_reg_flag->setAccessible( true );
$p22_reg_before = (bool) $p22_reg_flag->getValue();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();

echo "PASS-WF-02: ticket.checked_out, ticket.transferred, attendance.confirmed, event.cancelled end to end\n";
Webhooks::register();
ConnectorEvents::register();
$p22_conn = new P22_Wf02_Connector();
add_filter( 'wp_pass_captain_connectors_v1', static function ( $list ) use ( $p22_conn ) { $list[] = $p22_conn; return $list; } );
update_option( 'wpcap_enabled_connectors', array( 'p22_wf02' ) );
$p22_run = static function () use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='connector_delivery' AND status='queued'" );
	Jobs::process_due( 200 );
};
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$p22_types = array( 'ticket.checked_out', 'ticket.transferred', 'attendance.confirmed', 'event.cancelled', 'ticket.checked_in', 'occurrence.cancelled' );
$p22_sub = Dispatcher::create_subscription( 'https://p22.hooks.example/in', $p22_types, false );
$p22_pii = Dispatcher::create_subscription( 'https://p22-pii.hooks.example/in', array( 'ticket.transferred' ), true );
check( 'PASS-WF-02: the four event types can be subscribed (without and with include_pii)', ! is_wp_error( $p22_sub ) && ! is_wp_error( $p22_pii ) );
$p22_hooks = static function ( $type, $sub = null ) use ( $wpdb, $p, $p22_sub ) {
	$sub = $sub ? $sub : $p22_sub;
	return array_map( static fn( $j ) => json_decode( (string) $j, true ), (array) $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type=%s ORDER BY delivery_id", $sub['subscription_id'], $type ) ) );
};
$p22_of_ticket = static fn( array $rows, $public_id ) => array_values( array_filter( $rows, static fn( $r ) => $public_id === ( $r['data']['object']['id'] ?? null ) ) );
$p22_conn_of_ticket = static fn( array $rows, $ticket_id ) => array_values( array_filter( $rows, static fn( $r ) => (int) $ticket_id === (int) ( $r['payload']['data']['ticket_id'] ?? 0 ) ) );
$p22_iso = static fn( $v ) => 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $v );
$p22_ticket_keys = array( 'object', 'id', 'order_id', 'ticket_type_id', 'occurrence_id', 'event_id', 'status', 'refunded' );
$p22_conn_ticket_keys = array( 'ticket_id', 'public_id', 'order_id', 'ticket_type_id', 'occurrence_id' );
$p22_has = static fn( array $a, array $keys ) => ! array_diff( $keys, array_keys( $a ) );

/* ---- 1. Admission: real check-in and check-out (Checkin::perform / check_out) ---- */
$p22_s = adm_setup( 'Europe/Vienna', null, null, 2 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $p22_s['occ'] ) );
$p22_adm = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,public_id,order_id,ticket_type_id FROM {$p}wpcap_tickets WHERE ticket_type_id=%d ORDER BY ticket_id", $p22_s['type'] ) );
$p22_codes = array();
foreach ( $p22_s['codes'] as $p22_c ) {
	$p22_codes[ (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $p22_c ) ) ) ] = $p22_c;
}
$p22_code0 = $p22_codes[ (int) $p22_adm[0]->ticket_id ] ?? '';
$p22_code1 = $p22_codes[ (int) $p22_adm[1]->ticket_id ] ?? '';
check( 'PASS-WF-02: admission fixture (two tickets with codes)', 2 === count( $p22_adm ) && '' !== $p22_code0 && '' !== $p22_code1 );
check( 'PASS-WF-02: a refused check-out (ticket not inside) sends no ticket.checked_out', 'not_checked_in' === adm_out( $p22_code0 ) && ! $p22_hooks( 'ticket.checked_out' ) );
check( 'PASS-WF-02: the real check-in admits the ticket', 'checked_in' === adm_in( $p22_code0 ) );
$p22_out_key = 'p22-out-' . uniqid();
$p22_out1 = Checkin::check_out( $p22_code0, array( 'idempotency_key' => $p22_out_key ) );
$p22_out2 = Checkin::check_out( $p22_code0, array( 'idempotency_key' => $p22_out_key ) );
check( 'PASS-WF-02: Checkin::check_out() checks out, the same scan key replays', 'checked_out' === adm_r( $p22_out1 ) && ! empty( $p22_out2['replayed'] ) );
$p22_run();
$p22_w_out = $p22_hooks( 'ticket.checked_out' );
$p22_wo = $p22_w_out[0]['data']['object'] ?? array();
$p22_checkin_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(checkin_id) FROM {$p}wpcap_checkins WHERE ticket_id=%d AND result='checked_out'", (int) $p22_adm[0]->ticket_id ) );
check( 'PASS-WF-02: webhook ticket.checked_out once per check-out (replay sends nothing) with ticket fields, checked_out_at and source', 1 === count( $p22_w_out ) && $p22_has( $p22_wo, $p22_ticket_keys ) && 'ticket' === $p22_wo['object'] && $p22_adm[0]->public_id === $p22_wo['id'] && $p22_s['occ'] === $p22_wo['occurrence_id'] && $p22_s['event'] === $p22_wo['event_id'] && $p22_iso( $p22_wo['checked_out_at'] ?? '' ) && 'web' === ( $p22_wo['source'] ?? '' ) );
check( 'PASS-WF-02: the webhook event id of the check-out is stable (type + check-out row)', $p22_checkin_id > 0 && ( $p22_w_out[0]['id'] ?? '' ) === PassCaptain\Suite\Outbox::event_id( 'ticket.checked_out', 'checkout|' . $p22_checkin_id ) );
$p22_c_out = $p22_conn_of_ticket( $p22_conn->of( 'ticket_checked_out' ), $p22_adm[0]->ticket_id );
$p22_co = $p22_c_out[0]['payload']['data'] ?? array();
check( 'PASS-WF-02: connector ticket_checked_out once with ticket ids, source, device and area', 1 === count( $p22_c_out ) && $p22_has( $p22_co, array_merge( $p22_conn_ticket_keys, array( 'source', 'device_id', 'area_id' ) ) ) && $p22_adm[0]->public_id === $p22_co['public_id'] && (int) $p22_adm[0]->order_id === $p22_co['order_id'] && $p22_s['occ'] === $p22_co['occurrence_id'] && 'web' === $p22_co['source'] );
check( 'PASS-WF-02: connector ticket_checked_out names contract 1.2.0', '1.2.0' === ( $p22_c_out[0]['payload']['contract_version'] ?? '' ) && 'ticket_checked_out' === ( $p22_c_out[0]['payload']['event'] ?? '' ) );

/* ---- 2. attendance.confirmed: first real check-in vs. wpcap_confirm_attendance() ---- */
$p22_w_att = $p22_of_ticket( $p22_hooks( 'attendance.confirmed' ), $p22_adm[0]->public_id );
$p22_wa = $p22_w_att[0]['data']['object'] ?? array();
check( 'PASS-WF-02: the real check-in sends webhook attendance.confirmed once (method check_in, source, confirmed_at)', 1 === count( $p22_w_att ) && $p22_has( $p22_wa, $p22_ticket_keys ) && 'check_in' === ( $p22_wa['method'] ?? '' ) && 'web' === ( $p22_wa['source'] ?? '' ) && $p22_iso( $p22_wa['confirmed_at'] ?? '' ) );
$p22_c_att = $p22_conn_of_ticket( $p22_conn->of( 'attendance_confirmed' ), $p22_adm[0]->ticket_id );
check( 'PASS-WF-02: … and connector attendance_confirmed once with method check_in', 1 === count( $p22_c_att ) && $p22_has( $p22_c_att[0]['payload']['data'], array_merge( $p22_conn_ticket_keys, array( 'method', 'source' ) ) ) && 'check_in' === $p22_c_att[0]['payload']['data']['method'] );
$p22_conf = wpcap_confirm_attendance( $p22_adm[1]->public_id, 'list' );
$p22_conf2 = wpcap_confirm_attendance( $p22_adm[1]->public_id, 'manual' );
check( 'PASS-WF-02: wpcap_confirm_attendance() confirms the second ticket (also when repeated)', is_array( $p22_conf ) && 'confirmed' === ( $p22_conf['status'] ?? '' ) && is_array( $p22_conf2 ) );
check( 'PASS-WF-02: a later real check-in of the confirmed ticket admits it', 'checked_in' === adm_in( $p22_code1 ) );
$p22_run();
$p22_w_att1 = $p22_of_ticket( $p22_hooks( 'attendance.confirmed' ), $p22_adm[1]->public_id );
$p22_c_att1 = $p22_conn_of_ticket( $p22_conn->of( 'attendance_confirmed' ), $p22_adm[1]->ticket_id );
check( 'PASS-WF-02: confirmation without scan: webhook attendance.confirmed once (method list, source api), the later check-in adds none', 1 === count( $p22_w_att1 ) && 'list' === ( $p22_w_att1[0]['data']['object']['method'] ?? '' ) && 'api' === ( $p22_w_att1[0]['data']['object']['source'] ?? '' ) && 1 === count( $p22_of_ticket( $p22_hooks( 'ticket.checked_in' ), $p22_adm[1]->public_id ) ) );
check( 'PASS-WF-02: … and connector attendance_confirmed once (method list, source api)', 1 === count( $p22_c_att1 ) && 'list' === $p22_c_att1[0]['payload']['data']['method'] && 'api' === $p22_c_att1[0]['payload']['data']['source'] );

/* ---- 3. ticket.transferred: portal transfer, assignment with a new holder, admin holder change ---- */
$p22_type = make_type( 10, 10, 0 );
$p22_buyer = 'p22-buyer@example.com';
$p22_o = Orders::create( array( 'email' => $p22_buyer, 'items' => array( array( 'ticket_type_id' => $p22_type, 'quantity' => 3 ) ), 'idempotency_key' => 'p22-tr-' . uniqid() ) );
$p22_order = Orders::get( $p22_o['order_id'] );
$p22_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $p22_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $p22_o['order_id'] ) );
$p22_tt = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,public_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $p22_o['order_id'] ) );
$p22_rotated = array();
add_action( 'wpcap_ticket_code_rotated', static function ( $ticket_id, $code ) use ( &$p22_rotated ) { $p22_rotated[] = (string) $code; }, 99, 2 );
$p22_tr = Orders::transfer_ticket( $p22_order->public_id, $p22_token, $p22_tt[0]->public_id, 'p22-friend@example.com' );
$p22_as = TicketAssignments::assign( $p22_order->public_id, $p22_token, $p22_tt[1]->public_id, 'Paula Zweiundzwanzig', 'p22-paula@example.com' );
$p22_as_same = TicketAssignments::assign( $p22_order->public_id, $p22_token, $p22_tt[1]->public_id, 'Paula Zweiundzwanzig', 'p22-paula@example.com' );
$p22_ad = Orders::change_ticket_holder( (int) $p22_tt[2]->ticket_id, 'p22-admin-new@example.com', 'Arne Neu', array( 'reason' => 'P22', 'notify' => false ) );
check( 'PASS-WF-02: portal transfer, assignment and admin holder change succeed', ! is_wp_error( $p22_tr ) && ! is_wp_error( $p22_as ) && ! is_wp_error( $p22_as_same ) && ! is_wp_error( $p22_ad ) );
$p22_run();
$p22_w_tr = $p22_hooks( 'ticket.transferred' );
$p22_src = static function ( array $rows, $public_id ) use ( $p22_of_ticket ) { $r = $p22_of_ticket( $rows, $public_id ); return 1 === count( $r ) ? $r[0]['data']['object'] : null; };
$p22_wt0 = $p22_src( $p22_w_tr, $p22_tt[0]->public_id );
$p22_wt1 = $p22_src( $p22_w_tr, $p22_tt[1]->public_id );
$p22_wt2 = $p22_src( $p22_w_tr, $p22_tt[2]->public_id );
check( 'PASS-WF-02: webhook ticket.transferred once per holder change, source portal / assign / admin (saving the same holder again sends none)', 3 === count( $p22_w_tr ) && $p22_wt0 && $p22_wt1 && $p22_wt2 && 'portal' === $p22_wt0['source'] && 'assign' === $p22_wt1['source'] && 'admin' === $p22_wt2['source'] );
check( 'PASS-WF-02: ticket.transferred carries the ticket fields and holder_changes >= 1', $p22_has( (array) $p22_wt0, $p22_ticket_keys ) && $p22_has( (array) $p22_wt2, $p22_ticket_keys ) && $p22_wt0['holder_changes'] >= 1 && $p22_wt1['holder_changes'] >= 1 && 1 === $p22_wt2['holder_changes'] && $p22_o['public_id'] === $p22_wt0['order_id'] );
$p22_w_pii = $p22_hooks( 'ticket.transferred', $p22_pii );
$p22_pii_portal = $p22_src( $p22_w_pii, $p22_tt[0]->public_id );
check( 'PASS-WF-02: only with include_pii the new holder address comes as attendee_email', ! array_key_exists( 'attendee_email', (array) $p22_wt0 ) && 'p22-friend@example.com' === ( $p22_pii_portal['attendee_email'] ?? '' ) );
$p22_c_tr = $p22_conn->of( 'ticket_transferred' );
$p22_cs = array_map( static fn( $r ) => $r['payload']['data']['source'], $p22_c_tr );
sort( $p22_cs );
check( 'PASS-WF-02: connector ticket_transferred once per holder change with sources admin, assign, portal', array( 'admin', 'assign', 'portal' ) === $p22_cs && ! array_filter( $p22_c_tr, static fn( $r ) => ! array_key_exists( 'holder_changes', $r['payload']['data'] ) || ! array_key_exists( 'public_id', $r['payload']['data'] ) ) );

/* ---- 4. event.cancelled: Orders::cancel_event() ---- */
$p22_etype = make_type( 10, 10, 1000 );
$p22_eocc = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$p22_etype" );
$p22_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$p22_eocc" );
$p22_eo = Orders::create( array( 'email' => 'p22-event@example.com', 'items' => array( array( 'ticket_type_id' => $p22_etype, 'quantity' => 2 ) ), 'idempotency_key' => 'p22-ev-' . uniqid() ) );
Orders::confirm_manual_payment( $p22_eo['order_id'] );
$p22_cancel = Orders::cancel_event( $p22_event, 'P22' );
$p22_again = Orders::cancel_event( $p22_event, 'P22' );
check( 'PASS-WF-02: the event cancellation completes, a second one is rejected', ! is_wp_error( $p22_cancel ) && ! empty( $p22_cancel['complete'] ) && is_wp_error( $p22_again ) );
$p22_run();
$p22_w_ev = array_values( array_filter( $p22_hooks( 'event.cancelled' ), static fn( $r ) => $p22_event === ( $r['data']['object']['id'] ?? 0 ) ) );
$p22_we = $p22_w_ev[0]['data']['object'] ?? array();
check( 'PASS-WF-02: webhook event.cancelled once with object event, id, status cancelled and the cancelled dates', 1 === count( $p22_w_ev ) && array( 'id', 'object', 'occurrence_ids', 'status' ) === ( static function ( $k ) { sort( $k ); return $k; } )( array_keys( $p22_we ) ) && 'event' === $p22_we['object'] && 'cancelled' === $p22_we['status'] && array( $p22_eocc ) === $p22_we['occurrence_ids'] );
check( 'PASS-WF-02: each date of the event also sends occurrence.cancelled', 1 === count( array_filter( $p22_hooks( 'occurrence.cancelled' ), static fn( $r ) => $p22_eocc === ( $r['data']['object']['id'] ?? 0 ) ) ) );
$p22_c_ev = array_values( array_filter( $p22_conn->of( 'event_cancelled' ), static fn( $r ) => $p22_event === ( $r['payload']['data']['event_id'] ?? 0 ) ) );
check( 'PASS-WF-02: connector event_cancelled once with event_id, status and occurrence_ids, plus occurrence_cancelled for the date', 1 === count( $p22_c_ev ) && 'cancelled' === $p22_c_ev[0]['payload']['data']['status'] && array( $p22_eocc ) === $p22_c_ev[0]['payload']['data']['occurrence_ids'] && 1 === count( array_filter( $p22_conn->of( 'occurrence_cancelled' ), static fn( $r ) => $p22_eocc === (int) ( $r['payload']['data']['occurrence_id'] ?? 0 ) ) ) );

/* ---- 5. No code, no hash, no PII ---- */
$p22_blob = (string) wp_json_encode( array_merge( ...array_map( $p22_hooks, $p22_types ) ) ) . (string) wp_json_encode( $p22_conn->received );
$p22_secrets = array_filter( array_merge( array_values( $p22_codes ), $p22_rotated, ticket_code_for( $p22_o['order_id'] ), ticket_code_for( $p22_eo['order_id'] ), array_map( static fn( $c ) => hash( 'sha256', (string) $c ), array_merge( array_values( $p22_codes ), $p22_rotated ) ) ), static fn( $c ) => '' !== (string) $c );
check( 'PASS-WF-02: no ticket code, rotated code or code hash in any of these webhooks or connector events', '' !== $p22_blob && ! array_filter( $p22_secrets, static fn( $c ) => false !== strpos( $p22_blob, (string) $c ) ) );
check( 'PASS-WF-02: without include_pii no e-mail address and no holder name in them', false === strpos( $p22_blob, '@example.com' ) && false === strpos( $p22_blob, 'Paula' ) && false === strpos( $p22_blob, 'Arne' ) );

$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
update_option( 'wpcap_enabled_connectors', $p22_enabled_before );
$GLOBALS['wpcap_test_hooks'] = $p22_hooks_before;
$p22_reg_flag->setValue( null, $p22_reg_before );
$GLOBALS['wpcap_test_filters'] = $p22_filters_before;
$GLOBALS['wpcap_test_user_id'] = $p22_user_before;
wpcap_test_reset_transients();
