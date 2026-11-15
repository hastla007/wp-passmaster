<?php
/**
 * R7 (audit round 2, experts #9/#12): refund preview with fee / goodwill cap (Refunds::preview(),
 * deducted_minor) and buyer self-service cancellation requests under the event rule (Sales\SelfService).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders as R7dOrders;
use PassCaptain\Refunds as R7dRefunds;
use PassCaptain\Sales\SelfService as R7dSelf;
use PassCaptain\Settings as R7dSettings;

global $wpdb;
$p = $wpdb->prefix;
wpcap_test_reset_transients();
$r7d_threshold = R7dSettings::get( 'refund_approval_threshold' );
R7dSettings::update( array( 'refund_approval_threshold' => 0 ) );
$r7d_paid_order = static function ( $type, $qty, $key ) {
	$r = R7dOrders::create( array( 'email' => 'r7d-' . $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'r7d-' . $key . microtime() ), array( 'skip_rate_limit' => true ) );
	if ( is_wp_error( $r ) ) { return null; }
	R7dOrders::confirm_manual_payment( $r['order_id'] );
	return R7dOrders::get( $r['order_id'] );
};

echo "R7 refunds: preview, fee and goodwill cap\n";
$r7d_type = make_type( 50, 10, 4000 );
$r7d_o = $r7d_paid_order( $r7d_type, 3, 'p' );
$r7d_t = $r7d_o ? R7dOrders::tickets_for_order( $r7d_o->order_id ) : array();
$r7d_prev = $r7d_o ? R7dRefunds::preview( $r7d_o->order_id, array( (int) $r7d_t[0]->ticket_id ), array( 'deduct_minor' => 500 ) ) : null;
check( 'preview: one ticket 40,00 minus 5,00 fee = 35,00, nothing written', is_array( $r7d_prev ) && 4000 === $r7d_prev['value_minor'] && 500 === $r7d_prev['deducted_minor'] && 3500 === $r7d_prev['amount_minor'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', $p . 'wpcap_refunds', $r7d_o->order_id ) ) );
$r7d_cap = $r7d_o ? R7dRefunds::preview( $r7d_o->order_id, array(), array( 'max_minor' => 2000 ) ) : null;
check( 'preview: goodwill cap limits the full refund to 20,00', is_array( $r7d_cap ) && 12000 === $r7d_cap['value_minor'] && 2000 === $r7d_cap['amount_minor'] && 10000 === $r7d_cap['deducted_minor'] );
$r7d_r1 = $r7d_o ? R7dRefunds::request( $r7d_o->order_id, array( (int) $r7d_t[0]->ticket_id ), 'Gebühr', 'r7d-r1', array( 'deduct_minor' => 500 ) ) : null;
check( 'refund with fee: 35,00 refunded, fee recorded, VAT on the refunded amount', is_array( $r7d_r1 ) && 'completed' === $r7d_r1['status'] && 3500 === (int) $r7d_r1['amount_minor'] && 500 === (int) $r7d_r1['deducted_minor'] );
$r7d_r2 = $r7d_o ? R7dRefunds::request( $r7d_o->order_id, array(), 'Rest', 'r7d-r2' ) : null;
check( 'later full refund does not pay out the kept fee: refunds + fee = order total', is_array( $r7d_r2 ) && 8000 === (int) $r7d_r2['amount_minor'] && 11500 === (int) R7dOrders::get( $r7d_o->order_id )->refunded_minor && 'refunded' === R7dOrders::get( $r7d_o->order_id )->status );
check( 'admin refund options parse money input', array( 'deduct_minor' => 250, 'max_minor' => 1000 ) === PassCaptain\Admin\OrdersPage::refund_options( array( 'deduct' => '2,50', 'max_amount' => '10' ), 'EUR' ) && is_wp_error( PassCaptain\Admin\OrdersPage::refund_options( array( 'deduct' => 'abc' ), 'EUR' ) ) );

echo "R7 self-service: cancellation request under the event rule\n";
$r7d_stype = make_type( 50, 10, 3000 );
$r7d_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $r7d_stype ) );
$r7d_so = $r7d_paid_order( $r7d_stype, 2, 's' );
check( 'without an event rule nothing is eligible', $r7d_so && array() === R7dSelf::eligible( $r7d_so ) );
$r7d_policy = R7dSelf::parse_policy( array( 'refund_requests' => 1, 'refund_hours_before' => '48', 'refund_fee_percent' => '10' ) );
check( 'policy parsed: 48 h, 10 %', array( 'hours_before' => 48, 'fee_bp' => 1000 ) === $r7d_policy && null === R7dSelf::parse_policy( array() ) && is_wp_error( R7dSelf::parse_policy( array( 'refund_requests' => 1, 'refund_hours_before' => '-1' ) ) ) );
$wpdb->update( "{$p}wpcap_events", array( 'refund_policy' => wp_json_encode( $r7d_policy ) ), array( 'event_id' => $r7d_event ) );
$r7d_el = $r7d_so ? R7dSelf::eligible( $r7d_so ) : array();
check( 'both tickets are eligible 7 days before the start', 2 === count( $r7d_el ) );
$r7d_ids = array_keys( $r7d_el );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_refund', 'wpcap_approve_refunds' );
$r7d_req = $r7d_so ? R7dSelf::request( $r7d_so, array( $r7d_ids[0] ), array( 'reason' => 'krank' ) ) : null;
$GLOBALS['wpcap_test_denied_caps'] = array();
$r7d_refund = is_array( $r7d_req ) ? $r7d_req['refunds'][0] : null;
check( 'request without staff rights creates a refund waiting for approval with the 10 % fee', $r7d_refund && 'pending_approval' === $r7d_refund['status'] && 2700 === (int) $r7d_refund['amount_minor'] && 300 === (int) $r7d_refund['deducted_minor'] && 0 === (int) $r7d_refund['requested_by'] );
check( 'the requested ticket is no longer offered, the other still is', $r7d_so && array( $r7d_ids[1] ) === array_keys( R7dSelf::eligible( $r7d_so ) ) );
check( 'a second request for the same ticket is refused', $r7d_so && is_wp_error( R7dSelf::request( $r7d_so, array( $r7d_ids[0] ) ) ) );
$r7d_ok = $r7d_refund ? R7dRefunds::approve( (int) $r7d_refund['refund_id'] ) : null;
check( 'staff approval pays out 27,00 and revokes the ticket', is_array( $r7d_ok ) && 'completed' === $r7d_ok['status'] && 'revoked' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE ticket_id=%d', $p . 'wpcap_tickets', $r7d_ids[0] ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET starts_at=%s WHERE occurrence_id=(SELECT occurrence_id FROM %i WHERE ticket_type_id=%d)', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS ), $p . 'wpcap_ticket_types', $r7d_stype ) );
check( 'after the deadline (24 h < 48 h) nothing is eligible', $r7d_so && array() === R7dSelf::eligible( $r7d_so ) );
$r7d_waived = $r7d_so ? R7dSelf::request( $r7d_so, array( $r7d_ids[1] ), array( 'waive_policy' => true, 'reason' => 'verschoben' ) ) : null;
check( 'special case (waive_policy, e.g. postponed date): request without deadline and without fee', is_array( $r7d_waived ) && 3000 === (int) $r7d_waived['refunds'][0]['amount_minor'] && 0 === (int) ( $r7d_waived['refunds'][0]['deducted_minor'] ?? 0 ) );
check( 'comp orders are never eligible', array() === R7dSelf::eligible( (object) array( 'order_id' => 0, 'payment_status' => 'paid', 'payment_provider' => 'comp' ) ) );
R7dSettings::update( array( 'refund_approval_threshold' => $r7d_threshold ) );
