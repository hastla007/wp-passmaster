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

echo "Audit 3: box office exchange is booked into the shift (A008)\n";
$a8_type = ex_type( $a4_occ, 'Kasse Steh', 3000 );
$a8_vip = ex_type( $a4_occ, 'Kasse VIP', 4500 );
list( $a8_o, $a8_i ) = ex_buy( $a8_type, 1, 'a8@example.com' );
$GLOBALS['wpcap_test_user_id'] = 4242; // A cashier without an open shift.
$a8_none = Exchange::box_office_exchange( ex_ticket( $a8_i[0] )->public_id, $a8_vip, array( 'pos_method' => 'cash' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A008: cash exchange at the counter needs an open shift', is_wp_error( $a8_none ) && 'wpcap_pos_no_session' === $a8_none->get_error_code() && 'valid' === ex_ticket( $a8_i[0] )->status );
$a8_shift = PassCaptain\BoxOffice\Service::open_session( 0 );
$a8 = Exchange::box_office_exchange( ex_ticket( $a8_i[0] )->public_id, $a8_vip, array( 'pos_method' => 'cash', 'idempotency_key' => 'a8-1' ) );
$a8_sale = ! is_wp_error( $a8 ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE order_id=%d", (int) $a8['supplementary_order_id'] ) ) : null;
check( 'A008: supplement 15,00 is a completed box office sale with receipt number in the open shift', $a8_sale && 'completed' === $a8_sale->status && '' !== (string) $a8_sale->receipt_number && 1500 === (int) $a8_sale->total_minor && (int) $a8_sale->session_id === (int) $a8_shift->session_id && ! empty( $a8['receipt_number'] ) );
$a8_z = PassCaptain\BoxOffice\ZReport::compute( (int) $a8_shift->session_id );
check( 'A008: Z report counts the supplement as cash sale', (int) ( $a8_z['methods']['cash']['sales_gross'] ?? 0 ) >= 1500 );
$a8_new = (int) $a8['new_ticket_id'];
PassCaptain\Refunds::request( $a8_o, array( $a8_new ), 'Absage', 'a8-refund' );
$a8_supp_refund = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE order_id=%d", (int) $a8['supplementary_order_id'] ) );
$a8_mv = $a8_supp_refund ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_movements WHERE refund_id=%d", (int) $a8_supp_refund->refund_id ) ) : 0;
check( 'A008: a later refund of the supplement is booked as payout of the shift', $a8_supp_refund && 'completed' === $a8_supp_refund->status && 1 === (int) $a8_mv );

echo "Audit 3: buyer limit also applies to exchanges (A009)\n";
$a9_event = ex_event( 'Limit Umtausch' );
$a9_occ = ex_occ( $a9_event );
$a9_std = ex_type( $a9_occ, 'Std', 3000 );
$a9_vip = ex_type( $a9_occ, 'VIP', 3000 );
PassCaptain\Allocations\BuyerLimits::set( $a9_event, $a9_vip, 1 );
$GLOBALS['wpcap_test_transients'] = array();
$a9 = Orders::create( array( 'email' => 'Hamster@Example.com', 'items' => array( array( 'ticket_type_id' => $a9_vip, 'quantity' => 1 ), array( 'ticket_type_id' => $a9_std, 'quantity' => 3 ) ), 'idempotency_key' => 'a9-' . uniqid() ) );
Orders::confirm_external_payment( $a9['order_id'], 'test' );
$a9_std_tickets = array_values( array_filter( tickets( $a9['order_id'], 'valid' ), static fn( $t ) => $a9_std === (int) $t->ticket_type_id ) );
$a9_x = Exchange::request( (int) $a9_std_tickets[0]->ticket_id, $a9_vip, array( 'channel' => 'api' ) );
check( 'A009: exchange Std → VIP is refused when the buyer already has the 1 allowed VIP ticket', is_wp_error( $a9_x ) && 'allocation_buyer_limit' === ( $a9_x->get_error_data()['reason'] ?? '' ) && 'valid' === ex_ticket( $a9_std_tickets[0]->ticket_id )->status );
check( 'A009: the refused exchange left the order lines unchanged', 3 === (int) ex_item( $a9['order_id'], $a9_std )->quantity && 1 === (int) ex_item( $a9['order_id'], $a9_vip )->quantity );
$a9_staff = Exchange::request( (int) $a9_std_tickets[1]->ticket_id, $a9_vip, array( 'channel' => 'admin' ) );
check( 'A009: staff exchanges stay exempt (like box office/admin sales)', ! is_wp_error( $a9_staff ) && 'completed' === $a9_staff['status'] );
$GLOBALS['wpcap_test_transients'] = array();
$a9_buy = Orders::create( array( 'email' => 'hamster@example.com', 'items' => array( array( 'ticket_type_id' => $a9_vip, 'quantity' => 1 ) ), 'idempotency_key' => 'a9b-' . uniqid() ) );
check( 'A009: VIP units gained by exchange count for later purchases', is_wp_error( $a9_buy ) );

echo "Audit 3: comps need the comp approval right (A010)\n";
list( , $a10_i ) = ex_buy( $a4_std, 1, 'a10@example.com' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_comps' );
$a10_waive = Exchange::request( $a10_i[0], $a4_vip, array( 'channel' => 'admin', 'waive' => true ) );
$a10_pos = Exchange::box_office_exchange( $a10_i[0], $a4_vip, array( 'pos_method' => 'free' ) );
$a10_sell = PassCaptain\BoxOffice\Service::sell( array( 'form_key' => 'a10-free-' . bin2hex( random_bytes( 6 ) ), 'occurrence_id' => $a4_occ, 'method' => 'free', 'quantities' => array( $a4_vip => 2 ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A010: goodwill exchange (waive) without wpcap_approve_comps is refused (403)', is_wp_error( $a10_waive ) && 'wpcap_exchange_waive_forbidden' === $a10_waive->get_error_code() && 403 === $a10_waive->get_error_data()['status'] && 'valid' === ex_ticket( $a10_i[0] )->status );
check( 'A010: box office exchange with method free without the right is refused', is_wp_error( $a10_pos ) && 'wpcap_exchange_waive_forbidden' === $a10_pos->get_error_code() );
check( 'A010: box office free tickets without the right are refused', is_wp_error( $a10_sell ) && 'wpcap_pos_free_forbidden' === $a10_sell->get_error_code() );
$a10_ok = Exchange::request( $a10_i[0], $a4_vip, array( 'channel' => 'admin', 'waive' => true ) );
check( 'A010: with the right the goodwill exchange works', ! is_wp_error( $a10_ok ) && 'completed' === $a10_ok['status'] && 1 === (int) $a10_ok['waived'] );

echo "Audit 3: portal name correction without attendee e-mail (A012)\n";
list( $a12_o, $a12_i ) = ex_buy( $a4_std, 1, 'a12@example.com' );
$a12_token = str_repeat( 'ef', 32 );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a12_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a12_o ) );
$a12_before = ex_ticket( $a12_i[0] );
$a12 = PassCaptain\TicketAssignments::assign( Orders::get( $a12_o )->public_id, $a12_token, $a12_before->public_id, 'Maria Muster', '' );
$a12_after = ex_ticket( $a12_i[0] );
$a12_name = $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", $a12_i[0] ) );
check( 'A012: name correction without e-mail is accepted and keeps the ticket code', ! is_wp_error( $a12 ) && 'Maria Muster' === $a12_name && $a12_before->token_hash === $a12_after->token_hash );
$a12_bad = PassCaptain\TicketAssignments::assign( Orders::get( $a12_o )->public_id, $a12_token, $a12_before->public_id, 'Maria Muster', 'kein-mail' );
check( 'A012: an invalid e-mail is still refused', is_wp_error( $a12_bad ) && 400 === $a12_bad->get_error_data()['status'] );

echo "Audit 3: pending orders show what was ordered (A013)\n";
$GLOBALS['wpcap_test_transients'] = array();
$a13 = Orders::create( array( 'email' => 'a13@example.com', 'items' => array( array( 'ticket_type_id' => $a4_std, 'quantity' => 2 ) ), 'idempotency_key' => 'a13-' . uniqid() ), array( 'payment_provider' => 'manual', 'hold_minutes' => 600 ) );
$a13_lines = Orders::summary_lines( $a13['order_id'] );
check( 'A013: summary lists event, type, quantity and amount', 1 === count( $a13_lines ) && 2 === $a13_lines[0]['quantity'] && 'Steh' === $a13_lines[0]['ticket'] && 10000 === $a13_lines[0]['gross_minor'] );
$a13_vars = PassCaptain\Mail\Types::order_access_vars( Orders::get( $a13['order_id'] )->public_id );
check( 'A013: order mail placeholder {order_items} carries the lines', false !== strpos( (string) $a13_vars['order_items']['text'], '2 × Umtausch Abrechnung — Steh' ) );

echo "Audit 3: portal shows a pending upgrade with bank details (A014)\n";
ex_policy( array( 'portal_enabled' => 1, 'portal_hours' => 24, 'portal_max_per_ticket' => 3 ) );
PassCaptain\Settings::update( array( 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Kulturverein' ) );
list( $a14_o, $a14_i ) = ex_buy( $a4_std, 1, 'a14@example.com' );
$a14_token = str_repeat( 'cd', 32 );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a14_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a14_o ) );
$a14_t = ex_ticket( $a14_i[0] );
$a14 = PassCaptain\Exchange\Portal::exchange( Orders::get( $a14_o )->public_id, $a14_token, array( 'ticket_public_id' => $a14_t->public_id, 'ticket_type_id' => $a4_vip, 'idempotency_key' => 'a14', 'provider' => 'manual' ) );
check( 'A014: pending upgrade response carries the bank details', ! is_wp_error( $a14 ) && 'pending_payment' === $a14['status'] && ! empty( $a14['payment']['bank'] ) && in_array( 'AT61 1904 3002 3457 3201', $a14['payment']['bank'], true ) );
$a14_html = PassCaptain\Exchange\Portal::ticket_html( '', (object) array( 'ticket_id' => $a14_i[0], 'public_id' => $a14_t->public_id, 'status' => 'valid', 'transfer_count' => 0 ), Orders::get( $a14_o ), $a14_token );
check( 'A014: after reload the portal shows the open exchange (amount, IBAN) instead of a new form', false !== strpos( $a14_html, 'Umtausch wartet auf Zahlung' ) && false !== strpos( $a14_html, 'AT61 1904 3002 3457 3201' ) && false === strpos( $a14_html, 'wpcap-exchange-form' ) );
ex_policy( array() );

echo "Audit 3: staff occurrence selectors (A016)\n";
$a16_rows = array(
	(object) array( 'occurrence_id' => 1, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 ), 'status' => 'scheduled', 'event_status' => 'published', 'label' => 'Vorgestern' ),
	(object) array( 'occurrence_id' => 2, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * 86400 ), 'status' => 'scheduled', 'event_status' => 'published', 'label' => 'Später' ),
	(object) array( 'occurrence_id' => 3, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'status' => 'scheduled', 'event_status' => 'published', 'label' => 'Läuft' ),
	(object) array( 'occurrence_id' => 4, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'status' => 'cancelled', 'event_status' => 'published', 'label' => 'Abgesagt' ),
	(object) array( 'occurrence_id' => 5, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'status' => 'scheduled', 'event_status' => 'draft', 'label' => 'Entwurf' ),
);
$a16 = PassCaptain\Admin\Queries::occurrence_choices( $a16_rows );
check( 'A016: running occurrence is the default, then upcoming, then past (marked); cancelled and drafts hidden', array( 3, 2, 1 ) === array_keys( $a16 ) && false !== strpos( $a16[1], 'vergangen' ) );
check( 'A016: the box office gets no past dates', array( 3, 2 ) === array_keys( PassCaptain\Admin\Queries::occurrence_choices( $a16_rows, false ) ) );

echo "Audit 3: discount code administration and preview (A017)\n";
$a17_event = ex_event( 'Rabatt Admin' );
$a17_type = ex_type( ex_occ( $a17_event ), 'Normal', 4000 );
check( 'A017: no active code → the checkout field is hidden', false === PassCaptain\Campaigns::has_active( $a17_event, 'EUR' ) || PassCaptain\Campaigns::has_active( 0, 'EUR' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$a17_global = PassCaptain\Admin\CampaignsPage::create( array( 'code' => 'ALLE10', 'kind' => 'percent', 'value' => '10', 'currency' => 'EUR', 'event_id' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A017: codes for all events need manage_options', is_wp_error( $a17_global ) );
$a17 = PassCaptain\Admin\CampaignsPage::create( array( 'code' => 'herbst20', 'name' => 'Herbst', 'kind' => 'percent', 'value' => '20', 'currency' => 'EUR', 'event_id' => $a17_event, 'usage_limit' => 5 ) );
check( 'A017: admin form creates an event code (uppercase, 20 %)', ! is_wp_error( $a17 ) && 'HERBST20' === $a17['code'] && true === PassCaptain\Campaigns::has_active( $a17_event, 'EUR' ) );
$a17_preview = PassCaptain\Campaigns::preview( array( array( 'ticket_type_id' => $a17_type, 'quantity' => 2 ) ), 'herbst20' );
check( 'A017: preview 80,00 − 16,00 = 64,00 without reserving a use', ! is_wp_error( $a17_preview ) && 8000 === $a17_preview['subtotal_minor'] && 1600 === $a17_preview['discount_minor'] && 6400 === $a17_preview['total_minor'] && 0 === (int) PassCaptain\Campaigns::get( $a17['campaign_id'] )->used_count );
check( 'A017: preview with an unknown code is an error', is_wp_error( PassCaptain\Campaigns::preview( array( array( 'ticket_type_id' => $a17_type, 'quantity' => 1 ) ), 'GIBTSNICHT' ) ) );
$a17_off = PassCaptain\Admin\CampaignsPage::set_status( array( 'campaign_id' => $a17['campaign_id'], 'status' => 'inactive' ) );
check( 'A017: deactivated code is no longer offered', true === $a17_off && false === PassCaptain\Campaigns::has_active( $a17_event, 'EUR' ) || PassCaptain\Campaigns::has_active( 0, 'EUR' ) );
$a17_list = PassCaptain\Campaigns::all( null );
check( 'A017: list contains usage statistics', (bool) array_filter( $a17_list, static fn( $r ) => 'HERBST20' === $r->code && isset( $r->orders_committed, $r->discount_committed ) ) );

echo "Audit 3: refund VAT uses the stored line discount (A020 with A007)\n";
$GLOBALS['wpcap_test_transients'] = array();
$a20 = Orders::create( array( 'email' => 'a20@example.com', 'items' => array( array( 'ticket_type_id' => $a7_t1, 'quantity' => 1 ), array( 'ticket_type_id' => $a7_t2, 'quantity' => 1 ) ), 'campaign_codes' => array( 'NURA25' ), 'idempotency_key' => 'a20-' . uniqid() ) );
Orders::confirm_external_payment( $a20['order_id'], 'test' );
$a20_b = array_values( array_filter( tickets( $a20['order_id'], 'valid' ), static fn( $t ) => $a7_t2 === (int) $t->ticket_type_id ) )[0];
$a20_r = PassCaptain\Refunds::request( $a20['order_id'], array( (int) $a20_b->ticket_id ), 'Test', 'a20-r' );
check( 'A020: refund of the undiscounted event-B ticket: 100,00 with 9,09 VAT (10 %)', ! is_wp_error( $a20_r ) && 10000 === (int) $a20_r['amount_minor'] && 909 === (int) $a20_r['tax_minor'] );

echo "Audit 3: refund mail per payment method, payout IBAN, short order number (A018)\n";
check( 'A018: IBAN checksum validation', 'AT611904300234573201' === PassCaptain\PayoutAccount::normalize_iban( 'AT61 1904 3002 3457 3201' ) && '' === PassCaptain\PayoutAccount::normalize_iban( 'AT61 1904 3002 3457 3202' ) );
$GLOBALS['wpcap_test_transients'] = array();
$a18 = Orders::create( array( 'email' => 'a18@example.com', 'items' => array( array( 'ticket_type_id' => $a4_std, 'quantity' => 1 ) ), 'idempotency_key' => 'a18-' . uniqid() ), array( 'payment_provider' => 'manual', 'hold_minutes' => 600 ) );
Orders::confirm_manual_payment( $a18['order_id'] );
$a18_order = Orders::get( $a18['order_id'] );
$GLOBALS['wpcap_test_mails'] = array();
PassCaptain\Refunds::request( $a18['order_id'], array(), 'Absage', 'a18-r1' );
$a18_mail = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'a18@example.com' === $m['to'] && str_starts_with( $m['subject'], 'Erstattung' ) ) )[0] ?? array( 'body' => '' );
$a18_ref = PassCaptain\Frontend\Format::order_reference( $a18_order->public_id );
check( 'A018: bank transfer refund mail says "erstatten wir … per Überweisung", asks for the IBAN and uses the short number', false !== strpos( $a18_mail['body'], 'erstatten wir dir 50,00 EUR per Überweisung' ) && false !== strpos( $a18_mail['body'], 'IBAN' ) && false !== strpos( $a18_mail['body'], $a18_ref ) && false === strpos( $a18_mail['body'], $a18_order->public_id ) );
$a18_bad = PassCaptain\PayoutAccount::save( $a18_order, 'DE00 1234', 'Max' );
$a18_ok = PassCaptain\PayoutAccount::save( $a18_order, 'AT61 1904 3002 3457 3201', 'Max Muster' );
check( 'A018: payout account is validated and stored masked in the answer', is_wp_error( $a18_bad ) && ! is_wp_error( $a18_ok ) && 'AT61 •••• •••• 3201' === $a18_ok['iban_masked'] && 'AT611904300234573201' === PassCaptain\PayoutAccount::get( Orders::get( $a18['order_id'] ) )['iban'] );
check( 'A018: with an IBAN the note names the masked account', false !== strpos( PassCaptain\PayoutAccount::refund_note( Orders::get( $a18['order_id'] ) ), 'AT61 •••• •••• 3201' ) );
list( $a18c_o ) = ex_buy( $a4_std, 1, 'a18c@example.com' );
check( 'A018: card/wallet orders need no payout account', is_wp_error( PassCaptain\PayoutAccount::save( Orders::get( $a18c_o ), 'AT61 1904 3002 3457 3201', 'X' ) ) || 'manual' === Orders::get( $a18c_o )->payment_provider );

echo "Audit 3: occurrence cancellation with preview, reason and summary (A019)\n";
$a19_event = ex_event( 'Absage Vorschau' );
$a19_occ = ex_occ( $a19_event );
$a19_type = ex_type( $a19_occ, 'Abend', 2000 );
list( $a19_o ) = ex_buy( $a19_type, 2, 'a19@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
Orders::create( array( 'email' => 'a19p@example.com', 'items' => array( array( 'ticket_type_id' => $a19_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a19p-' . uniqid() ), array( 'payment_provider' => 'manual', 'hold_minutes' => 600 ) );
$a19_pv = PassCaptain\Admin\EventsPage::cancellation_preview( $a19_occ );
check( 'A019: preview lists tickets, paid and pending orders and recipients', 2 === $a19_pv['tickets'] && 1 === $a19_pv['paid_orders'] && 1 === $a19_pv['pending_orders'] && 1 === $a19_pv['recipients'] );
$a19_no = PassCaptain\Admin\EventsPage::cancel_occurrence( array( 'occurrence_id' => $a19_occ ) );
check( 'A019: cancelling needs a reason and an explicit confirmation', is_wp_error( $a19_no ) && 'scheduled' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_occurrences WHERE occurrence_id=$a19_occ" ) );
add_action( 'wpcap_occurrence_cancelled', array( PassCaptain\OccurrenceCancellation::class, 'handle' ), 10, 1 );
$a19 = PassCaptain\Admin\EventsPage::cancel_occurrence( array( 'occurrence_id' => $a19_occ, 'reason' => 'Künstler erkrankt', 'confirm' => 1 ) );
remove_action( 'wpcap_occurrence_cancelled', array( PassCaptain\OccurrenceCancellation::class, 'handle' ), 10 );
check( 'A019: summary counts cancelled orders, revoked tickets and notified holders', ! is_wp_error( $a19 ) && 1 === $a19['summary']['cancelled_orders'] && 2 === $a19['summary']['revoked'] && $a19['summary']['notified'] >= 1 );
check( 'A019: the reason is in the audit log', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='occurrence_cancelled' AND object_id=%d AND details LIKE %s", $a19_occ, '%erkrankt%' ) ) );

echo "Audit 3: amount refund VAT with a multi-purpose voucher (A021)\n";
$a21_type = ex_type( $a4_occ, 'Gutschein 10 %', 10000, 0, 1000 );
list( $a21_o ) = ex_buy( $a21_type, 1, 'a21@example.com' );
// 100,00 incl. 9,09 VAT: 50,00 paid by a multi-purpose voucher, 50,00 in cash.
$wpdb->update( "{$p}wpcap_orders", array( 'total_minor' => 5000, 'voucher_snapshot' => wp_json_encode( array( 'purpose' => 'multi', 'status' => 'committed', 'amount_minor' => 5000, 'amount_paid_by_voucher' => 5000 ) ) ), array( 'order_id' => $a21_o ) );
$a21 = PassCaptain\Refunds::request_amount( $a21_o, 2000, 'Kulanz', 'a21-r', array( 'system' => true ) );
check( 'A021: refund of 20,00 cash carries 1,82 VAT (share of the full 100,00), not 3,64', ! is_wp_error( $a21 ) && 182 === (int) $a21['tax_minor'] );

echo "Audit 3: invoice issued after a partial refund gets its correction (A022)\n";
list( $a22_o, $a22_i ) = ex_buy( $a4_std, 2, 'a22@example.com' );
$a22_r = PassCaptain\Refunds::request( $a22_o, array( $a22_i[0] ), 'Teil', 'a22-r' );
$a22_inv = PassCaptain\Invoices::issue_for_order( $a22_o );
$a22_cn = $wpdb->get_row( $wpdb->prepare( "SELECT c.* FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id WHERE i.order_id=%d", $a22_o ) );
check( 'A022: the earlier partial refund (50,00) gets a credit note when the invoice is issued', ! is_wp_error( $a22_r ) && 'completed' === $a22_r['status'] && ! is_wp_error( $a22_inv ) && $a22_cn && 5000 === (int) $a22_cn->total_minor && (int) $a22_cn->refund_id === (int) $a22_r['refund_id'] );

echo "Audit 3: series exchange only within the same seller (A024)\n";
$a24_e1 = ex_event( 'Serie Plattform' );
$a24_e2 = ex_event( 'Serie Veranstalter' );
$a24_e3 = ex_event( 'Serie Plattform 2' );
$a24_o1 = ex_occ( $a24_e1 ); $a24_o2 = ex_occ( $a24_e2, 0, 200 ); $a24_o3 = ex_occ( $a24_e3, 0, 220 );
$a24_t1 = ex_type( $a24_o1, 'A', 3000 ); $a24_t2 = ex_type( $a24_o2, 'B', 3000 ); $a24_t3 = ex_type( $a24_o3, 'C', 3000 );
$now24 = gmdate( 'Y-m-d H:i:s' );
foreach ( array( $a24_o1, $a24_o2, $a24_o3 ) as $o ) { $wpdb->insert( "{$p}wpcap_series_occurrences", array( 'series_id' => 990024, 'occurrence_id' => $o, 'starts_at' => $now24, 'created_at' => $now24 ) ); }
$a24_partner = PassCaptain\Partners\Repository::save( array( 'name' => 'Fremder Veranstalter', 'commission_bp' => 1000, 'settlement_start' => gmdate( 'Y-m' ) . '-01' ) );
PassCaptain\Partners\Repository::set_events( $a24_partner, array( $a24_e2 ) );
ex_policy( array( 'allow_series' => 1 ) );
list( , $a24_i ) = ex_buy( $a24_t1, 2, 'a24@example.com' );
$a24_x = Exchange::request( $a24_i[0], $a24_t2, array( 'channel' => 'admin' ) );
check( 'A024: series exchange into an event of another organizer is refused', is_wp_error( $a24_x ) && 'wpcap_exchange_other_seller' === $a24_x->get_error_code() );
$a24_ok = Exchange::request( $a24_i[1], $a24_t3, array( 'channel' => 'admin' ) );
check( 'A024: series exchange between events of the same seller still works', ! is_wp_error( $a24_ok ) && 'completed' === $a24_ok['status'] );
ex_policy( array() );
$wpdb->query( "DELETE FROM {$p}wpcap_series_occurrences WHERE series_id=990024" );

echo "Audit 3: single-purpose voucher is a claim in the organizer settlement (A025)\n";
$a25_event = ex_event( 'Einzweck Abrechnung' );
$a25_type = ex_type( ex_occ( $a25_event ), 'Normal', 5000 );
$a25_partner = PassCaptain\Partners\Repository::save( array( 'name' => 'Einzweck GmbH', 'commission_bp' => 0, 'fee_per_ticket_minor' => 0, 'fee_tax_rate_bp' => 0, 'settlement_start' => gmdate( 'Y-m' ) . '-01' ) );
PassCaptain\Partners\Repository::set_events( $a25_partner, array( $a25_event ) );
list( $a25_o ) = ex_buy( $a25_type, 1, 'a25@example.com' );
// 20,00 single-purpose voucher booked as a discount: 30,00 paid.
$wpdb->update( "{$p}wpcap_orders", array( 'discount_minor' => 2000, 'total_minor' => 3000, 'voucher_snapshot' => wp_json_encode( array( 'purpose' => 'single', 'status' => 'committed', 'amount_minor' => 2000 ) ) ), array( 'order_id' => $a25_o ) );
$a25_s = PassCaptain\Partners\Settlements::compute( PassCaptain\Partners\Repository::get( $a25_partner ), gmdate( 'Y-m' ) );
$a25_v = array_values( array_filter( $a25_s['lines'], static fn( $l ) => 'voucher' === $l['kind'] ) );
check( 'A025: organizer is settled the full 50,00 (30,00 sale + 20,00 voucher claim as own line)', 1 === count( $a25_v ) && 2000 === $a25_v[0]['gross_minor'] && 5000 === (int) $a25_s['totals']['sales_gross'] );

echo "Audit 3: buyer limit keys keyed, migrated, erased and pruned (A026)\n";
$a26_keys = PassCaptain\Allocations\BuyerLimits::keys( 'Hamster@Example.com' );
check( 'A026: buyer key is a keyed hash, not sha256 of the address', 1 === count( $a26_keys ) && hash( 'sha256', 'email:hamster@example.com' ) !== $a26_keys[0] );
$a26_table = $wpdb->prefix . 'wpcap_alloc_buyers';
$wpdb->query( "UPDATE $a26_table SET buyer_key=SHA2(CONCAT('legacy',buyer_id),256)" ); // Simulate old unkeyed rows.
unset( $GLOBALS['wpcap_test_options']['wpcap_alloc_key_version'] );
PassCaptain\Allocations\BuyerLimits::maybe_migrate_keys();
check( 'A026: migration rebuilds the rows from the orders with keyed hashes', 2 <= PassCaptain\Allocations\BuyerLimits::count_for( $a9_event, 'hamster@example.com' ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a26_table WHERE buyer_key LIKE '%legacy%'" ) );
$a26_export = PassCaptain\Allocations\BuyerLimits::privacy_export( 'hamster@example.com' );
$a26_erase = PassCaptain\Allocations\BuyerLimits::privacy_erase( 'hamster@example.com' );
check( 'A026: exporter lists the rows, eraser removes them', ! empty( $a26_export['data'] ) && true === $a26_erase['items_removed'] && 0 === PassCaptain\Allocations\BuyerLimits::count_for( $a9_event, 'hamster@example.com' ) );
$a26_old = ex_event( 'Lange vorbei' );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a26_old, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 900 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$wpdb->insert( $a26_table, array( 'event_id' => $a26_old, 'buyer_key' => str_repeat( 'a', 64 ), 'holdings' => '{}', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a26_counts = PassCaptain\Privacy\Retention::run();
check( 'A026: retention deletes buyer rows of long finished events', $a26_counts['buyer_limits'] >= 1 && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $a26_table WHERE event_id=%d", $a26_old ) ) );
