<?php
/**
 * Regression tests for the findings of audit round 3 (TODO.md "Auditbefunde Runde 3").
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Exchange\Service as Exchange;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;

echo "Audit 3: late payment with a transient module error is retried, not flagged\n";
$a3_type = make_type( 50 );
$GLOBALS['wpcap_test_transients'] = array();
$a3 = Orders::create( array( 'email' => 'late-busy@example.com', 'items' => array( array( 'ticket_type_id' => $a3_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a3-late-busy' ) );
expire_holds_of( $a3['order_id'] );
$a3_busy = static function ( $result, $demand, $context ) {
	return 'recover' === ( $context['phase'] ?? '' ) ? new WP_Error( 'db_busy', 'busy', array( 'status' => 503, 'retryable' => true ) ) : $result;
};
add_filter( 'wpcap_reserve_capacity', $a3_busy, 99, 3 );
$GLOBALS['wpcap_test_mails'] = array();
$a3_first = Orders::confirm_external_payment( $a3['order_id'], 'stripe:evt-a3' );
remove_filter( 'wpcap_reserve_capacity', $a3_busy, 99 );
$a3_order = Orders::get( $a3['order_id'] );
check( 'transient module error rolls back with a retryable 503', is_wp_error( $a3_first ) && 'wpcap_db_busy' === $a3_first->get_error_code() && ! empty( $a3_first->get_error_data()['retryable'] ) );
check( 'order stays pending (not needs_review), no admin alert, no tickets', 'pending' === $a3_order->payment_status && 'needs_review' !== $a3_order->status && ! $GLOBALS['wpcap_test_mails'] && ! tickets( $a3['order_id'] ) );
$a3_retry = Orders::confirm_external_payment( $a3['order_id'], 'stripe:evt-a3' );
check( 'webhook redelivery then allocates the tickets', true === $a3_retry && 'paid' === Orders::get( $a3['order_id'] )->payment_status && 2 === count( tickets( $a3['order_id'], 'valid' ) ) );

echo "Audit 3: admin retry of a needs_review order\n";
$a3b_type = make_type( 1 );
$a3b = Orders::create( array( 'email' => 'late-full@example.com', 'items' => array( array( 'ticket_type_id' => $a3b_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a3-late-full' ) );
expire_holds_of( $a3b['order_id'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='expired' WHERE order_id=%d", $a3b['order_id'] ) );
PassCaptain\CapacityCounters::sync_order( $a3b['order_id'] );
$a3b_other = Orders::create( array( 'email' => 'other@example.com', 'items' => array( array( 'ticket_type_id' => $a3b_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a3-other' ) );
$a3b_late = Orders::confirm_external_payment( $a3b['order_id'], 'stripe:evt-a3b' );
check( 'late payment without capacity is flagged needs_review', is_wp_error( $a3b_late ) && 'wpcap_needs_review' === $a3b_late->get_error_code() && 'paid_unallocated' === Orders::get( $a3b['order_id'] )->payment_status );
$a3b_too_early = PassCaptain\Admin\OrdersPage::retry_allocation( array( 'order_id' => $a3b['order_id'] ) );
check( 'retry while still sold out keeps the order in review', is_wp_error( $a3b_too_early ) && 'needs_review' === Orders::get( $a3b['order_id'] )->status );
Orders::cancel_order( $a3b_other['order_id'], 'test' );
$a3b_retry = PassCaptain\Admin\OrdersPage::retry_allocation( array( 'order_id' => $a3b['order_id'] ) );
check( 'retry after capacity was freed issues the ticket', ! is_wp_error( $a3b_retry ) && 'paid' === Orders::get( $a3b['order_id'] )->payment_status && 1 === count( tickets( $a3b['order_id'], 'valid' ) ) );
$a3b_again = PassCaptain\Admin\OrdersPage::retry_allocation( array( 'order_id' => $a3b['order_id'] ) );
check( 'retry is refused for orders that are not in review', is_wp_error( $a3b_again ) );

echo "Audit 3: exchange keeps paid order lines (value, settlement, invoice, VAT rate)\n";
ex_policy( array() );
$a4_event = ex_event( 'Umtausch Abrechnung' );
$a4_occ = ex_occ( $a4_event );
$a4_std = ex_type( $a4_occ, 'Steh', 5000 );
$a4_vip = ex_type( $a4_occ, 'VIP', 8000 );
$a4_cheap = ex_type( $a4_occ, 'Rang', 2000 );
$a4_reduced = ex_type( $a4_occ, 'Ermäßigt 13 %', 4000, 0, 1300 );
// A001: value of the other tickets of a type is not changed by an exchange into that type.
$GLOBALS['wpcap_test_transients'] = array();
$a4_mix = Orders::create( array( 'email' => 'mix@example.com', 'items' => array( array( 'ticket_type_id' => $a4_vip, 'quantity' => 1 ), array( 'ticket_type_id' => $a4_std, 'quantity' => 1 ) ), 'idempotency_key' => 'a4-mix' ) );
Orders::confirm_external_payment( $a4_mix['order_id'], 'test' );
$a4_vip_ticket = null; $a4_std_ticket = null;
foreach ( tickets( $a4_mix['order_id'], 'valid' ) as $t ) { if ( $a4_vip === (int) $t->ticket_type_id ) { $a4_vip_ticket = $t; } else { $a4_std_ticket = $t; } }
$a4_before = Exchange::ticket_value( ex_ticket( $a4_vip_ticket->ticket_id ) );
$a4_up = Exchange::request( (int) $a4_std_ticket->ticket_id, $a4_vip, array( 'channel' => 'admin', 'waive' => true ) );
$a4_after = Exchange::ticket_value( ex_ticket( $a4_vip_ticket->ticket_id ) );
check( 'A001: value of the original VIP ticket stays 80,00 after another ticket was upgraded into VIP', ! is_wp_error( $a4_up ) && 'completed' === $a4_up['status'] && 8000 === $a4_before && 8000 === $a4_after );
$a4_paid = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(' . PassCaptain\OrderLines::paid( 'quantity', '' ) . ') AS q,SUM(' . PassCaptain\OrderLines::paid( 'line_total_minor', '' ) . ") AS g FROM {$p}wpcap_order_items WHERE order_id=%d", $a4_mix['order_id'] ) );
check( 'A004: paid lines of the order are unchanged by the exchange (2 tickets, 130,00)', 2 === (int) $a4_paid->q && 13000 === (int) $a4_paid->g );
check( 'A004: live lines still move the unit for capacity', 2 === (int) ex_item( $a4_mix['order_id'], $a4_vip )->quantity && 0 === (int) ex_item( $a4_mix['order_id'], $a4_std )->quantity );
$a4_inv = PassCaptain\Invoices::issue_for_order( $a4_mix['order_id'] );
$a4_inv_lines = $wpdb->get_results( $wpdb->prepare( "SELECT l.* FROM {$p}wpcap_invoice_lines l INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=l.invoice_id WHERE i.order_id=%d", $a4_mix['order_id'] ) );
$a4_inv_sum = array_sum( array_map( static fn( $l ) => (int) $l->total_minor, $a4_inv_lines ) );
check( 'A004: invoice after the exchange shows the paid lines (sum = order total, no moved-in line)', ! is_wp_error( $a4_inv ) && 13000 === $a4_inv_sum && 2 === count( $a4_inv_lines ) );

// A004: settlement = payments − refunds for an upgrade with supplement and a downgrade with refund.
$a4_partner = PassCaptain\Partners\Repository::save( array( 'name' => 'Umtausch GmbH', 'commission_bp' => 0, 'fee_per_ticket_minor' => 100, 'fee_tax_rate_bp' => 0, 'settlement_start' => gmdate( 'Y-m' ) . '-01' ) );
PassCaptain\Partners\Repository::set_events( $a4_partner, array( $a4_event ) );
list( $a4_o1, $a4_i1 ) = ex_buy( $a4_std, 1, 'up@example.com' );
$a4_x1 = Exchange::request( $a4_i1[0], $a4_vip, array( 'channel' => 'admin' ) );
Orders::confirm_external_payment( (int) $a4_x1['supplementary_order_id'], 'supp-pay' );
list( $a4_o2, $a4_i2 ) = ex_buy( $a4_vip, 1, 'down@example.com' );
$a4_x2 = Exchange::request( $a4_i2[0], $a4_cheap, array( 'channel' => 'admin', 'refund_policy' => 'refund' ) );
$a4_s = PassCaptain\Partners\Settlements::compute( PassCaptain\Partners\Repository::get( $a4_partner ), gmdate( 'Y-m' ) );
$a4_cash_in = 5000 + 3000 + 8000 + 13000; // O1, supplement, O2, and the mixed order above (same event).
$a4_cash_out = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$p}wpcap_refunds WHERE status='completed' AND order_id IN (%d,%d,%d,%d)", $a4_o1, $a4_o2, $a4_mix['order_id'], (int) $a4_x1['supplementary_order_id'] ) );
check( 'A004: upgrade completed after supplement payment, downgrade refunded 60,00', 'completed' === Exchange::get( (int) $a4_x1['exchange_id'] )['status'] && 'completed' === $a4_x2['status'] && 6000 === (int) $a4_x2['refund_minor'] && 6000 === $a4_cash_out );
check( 'A004: settlement gross = payments − refunds (' . $a4_s['totals']['gross'] . ')', $a4_cash_in - $a4_cash_out === (int) $a4_s['totals']['gross'] && $a4_cash_in === (int) $a4_s['totals']['sales_gross'] );
check( 'A004: tickets and per-ticket fees counted once (4 sold, no late_sale from moved units)', 4 === (int) $a4_s['totals']['tickets_sold'] && 400 === (int) $a4_s['totals']['fee'] && ! array_filter( $a4_s['lines'], static fn( $l ) => 'late_sale' === $l['kind'] ) );

// A005: a ticket paid with a multi-purpose voucher keeps its value.
list( $a5_o, $a5_i ) = ex_buy( $a4_std, 1, 'voucher@example.com' );
$wpdb->update( "{$p}wpcap_orders", array( 'total_minor' => 0, 'voucher_snapshot' => wp_json_encode( array( 'purpose' => 'multi', 'status' => 'committed', 'amount_minor' => 5000, 'amount_paid_by_voucher' => 5000 ) ) ), array( 'order_id' => $a5_o ) );
check( 'A005: ticket fully paid by a multi-purpose voucher is worth 50,00 (not 0)', 5000 === Exchange::ticket_value( ex_ticket( $a5_i[0] ) ) );

// A006: no exchange between different VAT rates.
list( , $a6_i ) = ex_buy( $a4_std, 1, 'rate@example.com' );
$a6 = Exchange::request( $a6_i[0], $a4_reduced, array( 'channel' => 'admin' ) );
check( 'A006: exchange into a type with another VAT rate is refused', is_wp_error( $a6 ) && 'wpcap_exchange_tax_rate' === $a6->get_error_code() && 'valid' === ex_ticket( $a6_i[0] )->status );
check( 'A006: such a type is not offered as a target', ! in_array( $a4_reduced, array_map( static fn( $o ) => (int) $o['ticket_type_id'], Exchange::options( $a6_i[0], 'admin' ) ), true ) );

echo "Audit 3: bank transfer hold ends with the start (A002)\n";
function a3_request( array $body ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	return $r;
}
$a2_soon_event = ex_event( 'Kurz vor Beginn' );
$a2_soon = ex_type( ex_occ( $a2_soon_event, 0, 1 ), 'Abend', 2000 ); // starts in 1 hour
$a2_far = ex_type( ex_occ( $a2_soon_event, 0, 24 * 30 ), 'Später', 2000 );
$GLOBALS['wpcap_test_transients'] = array();
$a2_res = PassCaptain\Rest\Controller::checkout( a3_request( array( 'email' => 'late-bank@example.com', 'items' => array( array( 'ticket_type_id' => $a2_soon, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'a2-' . uniqid() ) ) );
$a2_data = is_wp_error( $a2_res ) ? array() : $a2_res->get_data();
$a2_exp = $a2_data ? $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d", (int) $a2_data['order_id'] ) ) : null;
check( 'A002: transfer hold for an event in 1 h ends at the start, not after 72 h', $a2_exp && strtotime( $a2_exp . ' UTC' ) <= time() + 3600 + 60 );
$a2_far_res = PassCaptain\Rest\Controller::checkout( a3_request( array( 'email' => 'far-bank@example.com', 'items' => array( array( 'ticket_type_id' => $a2_far, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'a2f-' . uniqid() ) ) );
$a2_far_exp = is_wp_error( $a2_far_res ) ? null : $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d", (int) $a2_far_res->get_data()['order_id'] ) );
check( 'A002: far events keep the configured 72 h', $a2_far_exp && abs( strtotime( $a2_far_exp . ' UTC' ) - ( time() + 72 * 3600 ) ) < 120 );
PassCaptain\Settings::update( array( 'manual_cutoff_hours' => 24 ) );
$a2_cut = PassCaptain\Rest\Controller::checkout( a3_request( array( 'email' => 'cut-bank@example.com', 'items' => array( array( 'ticket_type_id' => $a2_soon, 'quantity' => 1 ) ), 'provider' => 'manual', 'idempotency_key' => 'a2c-' . uniqid() ) ) );
check( 'A002: within manual_cutoff_hours bank transfer is refused', is_wp_error( $a2_cut ) && 'wpcap_manual_too_late' === $a2_cut->get_error_code() );
check( 'A002: helper returns null within the cutoff, minutes otherwise', null === Orders::manual_hold_until( time() + 3600 ) && 72 * 60 === Orders::manual_hold_until( time() + 30 * 86400 ) );
PassCaptain\Settings::update( array( 'manual_cutoff_hours' => 0 ) );

echo "Audit 3: targeted campaign discount stays on its lines (A007)\n";
$a7_e1 = ex_event( 'Kampagne A' );
$a7_e2 = ex_event( 'Kampagne B' );
$a7_t1 = ex_type( ex_occ( $a7_e1 ), 'A 20 %', 10000, 0, 2000 );
$a7_t2 = ex_type( ex_occ( $a7_e2 ), 'B 10 %', 10000, 0, 1000 );
PassCaptain\Campaigns::create( array( 'code' => 'NURA25', 'kind' => 'fixed', 'value' => 2500, 'currency' => 'EUR', 'event_id' => $a7_e1 ) );
$GLOBALS['wpcap_test_transients'] = array();
$a7 = Orders::create( array( 'email' => 'camp@example.com', 'items' => array( array( 'ticket_type_id' => $a7_t1, 'quantity' => 1 ), array( 'ticket_type_id' => $a7_t2, 'quantity' => 1 ) ), 'campaign_codes' => array( 'NURA25' ), 'idempotency_key' => 'a7-' . uniqid() ) );
$a7_lines = array();
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=%d", $a7['order_id'] ) ) as $l ) { $a7_lines[ (int) $l->ticket_type_id ] = $l; }
check( 'A007: discount 25,00 only on the event-A line, stored per line', ! is_wp_error( $a7 ) && 2500 === (int) $a7_lines[ $a7_t1 ]->discount_minor && 0 === (int) $a7_lines[ $a7_t2 ]->discount_minor && 17500 === (int) Orders::get( $a7['order_id'] )->total_minor );
check( 'A007: VAT per line on its own discounted gross (A: 75,00 @20 % = 12,50; B: 100,00 @10 % = 9,09)', 1250 === (int) $a7_lines[ $a7_t1 ]->tax_minor && 909 === (int) $a7_lines[ $a7_t2 ]->tax_minor && 2159 === (int) Orders::get( $a7['order_id'] )->tax_minor );
Orders::confirm_external_payment( $a7['order_id'], 'test' );
PassCaptain\Invoices::issue_for_order( $a7['order_id'] );
$a7_inv = $wpdb->get_col( $wpdb->prepare( "SELECT l.total_minor FROM {$p}wpcap_invoice_lines l INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=l.invoice_id WHERE i.order_id=%d ORDER BY l.invoice_line_id", $a7['order_id'] ) );
check( 'A007: invoice lines 75,00 and 100,00 (the other event keeps its full price)', array( 7500, 10000 ) === array_map( 'intval', $a7_inv ) );
$a7_gross = PassCaptain\Reports\Data::allocate_discount( array_values( $a7_lines ), 2500 );
check( 'A007: reports/settlements use the stored line discount', array( 7500, 10000 ) === array_values( $a7_gross ) );
