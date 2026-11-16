<?php
/**
 * A346: a Stripe charge.refunded for a full refund that the plugin itself is paying out right now
 * books nothing (no refund over 0 €, no credit note). A347: Stripe partial refunds are reconciled
 * cumulatively (all external refunds of the charge minus what is booked), not via the "latest" refund.
 *
 * Included from run.php (uses check(), make_type(), tickets() and the HTTP/REST fakes of payments.php).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

global $wpdb;
$p = $wpdb->prefix;
Settings::update( array( 'stripe_secret_key' => 'sk_test_a346', 'stripe_webhook_secret' => 'whsec_test', 'refund_approval_threshold' => 0 ) );
Registry::reset();
$GLOBALS['wpcap_test_denied_caps'] = array();

function a346_label( $r ) {
	if ( is_wp_error( $r ) ) { return 'error:' . $r->get_error_code(); }
	if ( is_object( $r ) && method_exists( $r, 'get_data' ) ) { $r = $r->get_data(); }
	return (string) ( $r['result'] ?? '?' );
}
function a346_paid_order( $key, $qty = 2, $price = 3000 ) {
	$o = provider_order( 'stripe', make_type( 10, 10, $price ), $qty, $key );
	Webhooks::handle( stripe_request( stripe_session_event( "evt_{$key}_paid", 'checkout.session.completed', $o, array( 'id' => "cs_$key", 'payment_intent' => "pi_$key" ) ) ) );
	return $o;
}
function a346_charge_event( $id, $o, $key, $amount_refunded, $full, $refunds = null ) {
	$charge = array( 'id' => "ch_$key", 'object' => 'charge', 'amount' => (int) $o['total_minor'], 'amount_refunded' => $amount_refunded, 'refunded' => $full, 'currency' => 'eur', 'payment_intent' => "pi_$key", 'metadata' => array( 'wpcap_order' => $o['public_id'] ) );
	if ( null !== $refunds ) { $charge['refunds'] = array( 'object' => 'list', 'data' => $refunds, 'has_more' => false ); }
	return array( 'id' => $id, 'type' => 'charge.refunded', 'data' => array( 'object' => $charge ) );
}
function a346_rows( $order_id ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT refund_id,amount_minor,status,reason FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", $order_id ) ); }

echo "A346: Stripe full-refund event while the plugin's own refund is running\n";
http_reset();
$a346_o = a346_paid_order( 'a346full' );
$a346_during = '';
// The charge.refunded webhook arrives while the refund request is still waiting for Stripe's answer.
$a346_hook = static function ( $pre, $args, $url ) use ( $a346_o, &$a346_during ) {
	if ( false === strpos( $url, '/v1/refunds' ) || 'POST' !== strtoupper( $args['method'] ?? '' ) ) { return $pre; }
	$key = (string) ( $args['headers']['Idempotency-Key'] ?? '' );
	$a346_during = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a346_full', $a346_o, 'a346full', 6000, true, array( array( 'id' => 're_a346', 'amount' => 6000, 'status' => 'succeeded', 'metadata' => array( 'wpcap_order' => $a346_o['public_id'], 'wpcap_refund' => $key ) ) ) ) ) ) );
	return array( 'response' => array( 'code' => 200, 'message' => '' ), 'headers' => array(), 'body' => json_encode( array( 'id' => 're_a346', 'status' => 'succeeded', 'amount' => 6000 ) ) );
};
add_filter( 'pre_http_request', $a346_hook, 10, 3 );
$a346_refund = Refunds::request( $a346_o['order_id'], array(), 'Absage', 'a346-native' );
remove_filter( 'pre_http_request', $a346_hook, 10 );
$a346_rows = a346_rows( $a346_o['order_id'] );
check( 'A346: the full-refund event of the own refund books nothing (ignored, no row over 0 €)', 'ignored' === $a346_during && 1 === count( $a346_rows ) && ! array_filter( $a346_rows, static fn( $r ) => 0 === (int) $r->amount_minor ) );
check( 'A346: the native refund completes the order once (refunded total = paid total)', ! is_wp_error( $a346_refund ) && 'completed' === $a346_refund['status'] && 6000 === (int) Orders::get( $a346_o['order_id'] )->refunded_minor && 'refunded' === Orders::get( $a346_o['order_id'] )->status );
$a346_again = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a346_full2', $a346_o, 'a346full', 6000, true, array( array( 'id' => 're_a346', 'amount' => 6000, 'status' => 'succeeded', 'metadata' => array( 'wpcap_refund' => 'wpcap-refund-' . (int) $a346_refund['refund_id'] ) ) ) ) ) ) );
check( 'A346: a later full-refund event of the own refund books nothing either', 'ignored' === $a346_again && 1 === count( a346_rows( $a346_o['order_id'] ) ) );

// The same race with an event that reports an external amount (cumulative, e.g. Mollie or a dashboard
// refund on top): the running refund holds the whole open amount, so nothing is booked and the admin is told.
$a346_o2 = a346_paid_order( 'a346race', 1, 3000 );
$a346_mails = count( $GLOBALS['wpcap_test_mails'] );
$a346_label2 = '';
$a346_hook2 = static function ( $pre, $args, $url ) use ( $a346_o2, &$a346_label2 ) {
	if ( false === strpos( $url, '/v1/refunds' ) || 'POST' !== strtoupper( $args['method'] ?? '' ) ) { return $pre; }
	$a346_label2 = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a346_race', $a346_o2, 'a346race', 3000, true, array( array( 'id' => 're_a346_dash', 'amount' => 3000, 'status' => 'succeeded', 'metadata' => array() ) ) ) ) ) );
	return array( 'response' => array( 'code' => 200, 'message' => '' ), 'headers' => array(), 'body' => json_encode( array( 'id' => 're_a346_own', 'status' => 'succeeded', 'amount' => 3000 ) ) );
};
add_filter( 'pre_http_request', $a346_hook2, 10, 3 );
Refunds::request( $a346_o2['order_id'], array(), 'Absage', 'a346-race' );
remove_filter( 'pre_http_request', $a346_hook2, 10 );
$a346_rows2 = a346_rows( $a346_o2['order_id'] );
check( 'A346: an external refund on top of a running full native refund books no 0 € refund and alerts', 'already_booked' === $a346_label2 && 1 === count( $a346_rows2 ) && 3000 === (int) $a346_rows2[0]->amount_minor && count( $GLOBALS['wpcap_test_mails'] ) > $a346_mails && 3000 === (int) Orders::get( $a346_o2['order_id'] )->refunded_minor );
check( 'A346: record_external refuses a refund that would be capped to 0 €', 'wpcap_refund_nothing' === ( is_wp_error( $nothing = Refunds::record_external( $a346_o2['order_id'], array(), 500, 'x', 'a346-nothing' ) ) ? $nothing->get_error_code() : '' ) && 1 === count( a346_rows( $a346_o2['order_id'] ) ) );

echo "A347: Stripe partial refunds are reconciled cumulatively\n";
http_reset();
$a347_o = a346_paid_order( 'a347part', 3, 2000 );
// Two dashboard refunds (1000, then 2000). Newer API versions embed no refunds: the adapter reads the
// list, which already contains both when the first event is delivered.
$a347_list = array( 'object' => 'list', 'has_more' => false, 'data' => array(
	array( 'id' => 're_a347_b', 'amount' => 2000, 'status' => 'succeeded', 'metadata' => array() ),
	array( 'id' => 're_a347_a', 'amount' => 1000, 'status' => 'succeeded', 'metadata' => array() ),
) );
http_push( $a347_list, 200, '/v1/refunds?charge=ch_a347part' );
$a347_r1 = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a347_1', $a347_o, 'a347part', 1000, false ) ) ) );
http_push( $a347_list, 200, '/v1/refunds?charge=ch_a347part' );
$a347_r2 = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a347_2', $a347_o, 'a347part', 3000, false ) ) ) );
check( 'A347: two dashboard partial refunds are booked with their sum, not twice the newest', 'partial_refund' === $a347_r1 && 'already_booked' === $a347_r2 && 3000 === (int) Orders::get( $a347_o['order_id'] )->refunded_minor && 3000 === array_sum( array_map( static fn( $r ) => (int) $r->amount_minor, a346_rows( $a347_o['order_id'] ) ) ) );

// An external refund followed by a plugin refund: the newest refund is the plugin's own, the external one
// must still be booked.
$a347_o2 = a346_paid_order( 'a347mix', 3, 2000 );
$a347_list2 = array( 'object' => 'list', 'has_more' => false, 'data' => array(
	array( 'id' => 're_a347_own', 'amount' => 2000, 'status' => 'succeeded', 'metadata' => array( 'wpcap_refund' => 'wpcap-refund-999999' ) ),
	array( 'id' => 're_a347_ext', 'amount' => 500, 'status' => 'succeeded', 'metadata' => array() ),
) );
http_push( $a347_list2, 200, '/v1/refunds?charge=ch_a347mix' );
$a347_r3 = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a347_3', $a347_o2, 'a347mix', 2500, false ) ) ) );
check( 'A347: an external refund is booked even if a plugin refund is the newest one', 'partial_refund' === $a347_r3 && 500 === (int) Orders::get( $a347_o2['order_id'] )->refunded_minor );
// Failed external refunds do not count; a paginated refund list is read completely.
http_push( array( 'object' => 'list', 'has_more' => true, 'data' => array( array( 'id' => 're_a347_f', 'amount' => 700, 'status' => 'failed', 'metadata' => array() ) ) ), 200, '/v1/refunds?charge=ch_a347mix' );
http_push( array( 'object' => 'list', 'has_more' => false, 'data' => array( array( 'id' => 're_a347_ext', 'amount' => 500, 'status' => 'succeeded', 'metadata' => array() ), array( 'id' => 're_a347_ext2', 'amount' => 300, 'status' => 'pending', 'metadata' => array() ) ) ), 200, 'starting_after=re_a347_f' );
$a347_r4 = a346_label( Webhooks::handle( stripe_request( a346_charge_event( 'evt_a347_4', $a347_o2, 'a347mix', 3500, false ) ) ) );
check( 'A347: failed refunds are skipped and every page of the refund list is read', 'partial_refund' === $a347_r4 && 800 === (int) Orders::get( $a347_o2['order_id'] )->refunded_minor );
http_push( array( 'error' => array( 'message' => 'boom' ) ), 500, '/v1/refunds?charge=ch_a347mix' );
$a347_r5 = Webhooks::handle( stripe_request( a346_charge_event( 'evt_a347_5', $a347_o2, 'a347mix', 3500, false ) ) );
check( 'A347: a failing refund list is retried by Stripe (5xx), nothing booked', is_wp_error( $a347_r5 ) && (int) ( $a347_r5->get_error_data()['status'] ?? 0 ) >= 500 && 800 === (int) Orders::get( $a347_o2['order_id'] )->refunded_minor );
http_reset();
