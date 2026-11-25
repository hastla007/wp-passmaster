<?php
/**
 * PASS-WF-02: the webhook attendance.confirmed also covers an allowed confirmation without a scan
 * (wpcap_confirm_attendance(), action wpcap_attendance_confirmed) – at most once per ticket together with the
 * first check-in, no ticket code in the payload. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Webhooks;
use PassCaptain\Orders;
use PassCaptain\Webhooks\Dispatcher;

require_once WPCAP_PATH . 'src/functions.php';

global $wpdb;
$p = $wpdb->prefix;
$p3w_hooks_before = $GLOBALS['wpcap_test_hooks'];

echo "PASS-WF-02: attendance.confirmed for confirmations without a scan\n";
Webhooks::register();
$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$p3w_sub = Dispatcher::create_subscription( 'https://p3.hooks.example/in', array( 'attendance.confirmed', 'ticket.checked_in' ), false );
check( 'PASS-WF-02: a subscription to attendance.confirmed can be created', ! is_wp_error( $p3w_sub ) );
$p3w_type = make_type( 10, 10, 1200 );
$p3w_o = Orders::create( array( 'email' => 'p3-wf02@example.com', 'items' => array( array( 'ticket_type_id' => $p3w_type, 'quantity' => 2 ) ), 'idempotency_key' => 'p3w-' . uniqid() ) );
Orders::confirm_manual_payment( $p3w_o['order_id'] );
$p3w_t = tickets( $p3w_o['order_id'] );
$p3w_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$p3w_type" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $p3w_occ ) );
$p3w_rows = static function ( $ticket_public_id ) use ( $wpdb, $p, $p3w_sub ) {
	$out = array();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='attendance.confirmed'", $p3w_sub['subscription_id'] ) ) as $json ) {
		$d = json_decode( (string) $json, true );
		if ( ( $d['data']['object']['id'] ?? '' ) === $ticket_public_id ) { $out[] = $d; }
	}
	return $out;
};

$p3w_c1 = wpcap_confirm_attendance( $p3w_t[0]->public_id, 'list' );
$p3w_c2 = wpcap_confirm_attendance( $p3w_t[0]->public_id, 'manual' );
$p3w_a = $p3w_rows( $p3w_t[0]->public_id );
check( 'PASS-WF-02: wpcap_confirm_attendance() sends attendance.confirmed once per ticket (a second confirmation sends nothing)', is_array( $p3w_c1 ) && is_array( $p3w_c2 ) && 1 === count( $p3w_a ) );
$p3w_obj = $p3w_a[0]['data']['object'] ?? array();
check( 'PASS-WF-02: the payload names the ticket, method list, source api and a confirmation time', 'ticket' === ( $p3w_obj['object'] ?? '' ) && 'list' === ( $p3w_obj['method'] ?? '' ) && 'api' === ( $p3w_obj['source'] ?? '' ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) ( $p3w_obj['confirmed_at'] ?? '' ) ) && $p3w_o['public_id'] === ( $p3w_obj['order_id'] ?? null ) );
check( 'PASS-WF-02: without include_pii no e-mail address in the payload', ! array_key_exists( 'attendee_email', $p3w_obj ) && false === strpos( (string) wp_json_encode( $p3w_a ), '@example.com' ) );
do_action( 'wpcap_ticket_checked_in', (int) $p3w_t[0]->ticket_id, array( 'source' => 'app', 'checkin_id' => 93001 ) );
check( 'PASS-WF-02: a later check-in of a confirmed ticket sends ticket.checked_in but no second attendance.confirmed', 1 === count( $p3w_rows( $p3w_t[0]->public_id ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=%d AND event_type='ticket.checked_in'", $p3w_sub['subscription_id'] ) ) );
do_action( 'wpcap_ticket_checked_in', (int) $p3w_t[1]->ticket_id, array( 'source' => 'web', 'checkin_id' => 93002 ) );
wpcap_confirm_attendance( $p3w_t[1]->public_id, 'manual' );
$p3w_b = $p3w_rows( $p3w_t[1]->public_id );
check( 'PASS-WF-02: a confirmation after the first check-in sends nothing more (method stays check_in)', 1 === count( $p3w_b ) && 'check_in' === ( $p3w_b[0]['data']['object']['method'] ?? '' ) );
$p3w_codes = array_filter( ticket_code_for( $p3w_o['order_id'] ), static fn( $c ) => '' !== (string) $c && false !== strpos( (string) wp_json_encode( array_merge( $p3w_a, $p3w_b ) ), (string) $c ) );
check( 'PASS-WF-02: no ticket code in attendance.confirmed', ! $p3w_codes );
check( 'PASS-WF-02: an unknown ticket sends nothing', 0 === Webhooks::attendance_confirmed( 99999999, array( 'method' => 'manual' ) ) );

$wpdb->query( "DELETE FROM {$p}wpcap_webhook_subscriptions" );
$GLOBALS['wpcap_test_hooks'] = $p3w_hooks_before;
