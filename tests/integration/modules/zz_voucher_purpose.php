<?php
/**
 * Single-purpose vs multi-purpose vouchers (Einzweck-/Mehrzweckgutschein, docs/VOUCHERS.md):
 * VAT at checkout, amount due, invoice section "Zahlungen", refund split, credit notes, ticket service.
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as VoucherCheckout;
use PassCaptain\Integrations\Vouchers\TicketService;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "Voucher purpose (single-purpose / multi-purpose)\n";

final class VP_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	/** Extra quote data per code (e.g. purpose fields). */
	public array $extra = array();
	public function quote( array $context ) {
		$this->calls[] = array( 'quote', $context['code'] );
		$cap = (int) ( $this->extra[ $context['code'] ]['cap'] ?? 1500 );
		$quote = array( 'amount_minor' => min( $cap, (int) $context['amount_minor'] ), 'currency' => $context['currency'] );
		return array_merge( $quote, (array) ( $this->extra[ $context['code'] ]['data'] ?? array() ) );
	}
	public function reserve( array $quote, $key ) { $this->calls[] = array( 'reserve', $key ); return array( 'reservation' => 'r-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { $this->calls[] = array( 'commit', $key ); return array( 'redemption' => 'd-' . $key ); }
	public function release( array $reservation, $key ) { $this->calls[] = array( 'release', $key ); return true; }
	public function refund( array $redemption, $amount, $key ) { $this->calls[] = array( 'refund', $amount, $key ); return true; }
}
$vp_provider = new VP_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $vp_provider;
Settings::update( array( 'seller_name' => 'Kulturverein', 'refund_approval_threshold' => 0, 'auto_invoice' => 0 ) );
$vp_refund_calls = static fn() => array_values( array_filter( $vp_provider->calls, static fn( $c ) => 'refund' === $c[0] ) );

// --- Purpose resolution ---------------------------------------------------------------------------
check( 'multi-purpose is the default setting', 'multi' === Settings::get( 'voucher_default_purpose' ) && 'multi' === VoucherCheckout::purpose( array() ) );
check( 'purpose spellings from Voucher Captain data are recognised', 'single' === VoucherCheckout::purpose( array( 'purpose' => 'SPV' ) ) && 'single' === VoucherCheckout::purpose( array( 'quote' => array( 'voucher' => array( 'voucher_type' => 'Einzweckgutschein' ) ) ) ) && 'multi' === VoucherCheckout::purpose( array( 'tax_type' => 'multi_purpose' ) ) && 'single' === VoucherCheckout::purpose( array( 'single_purpose' => true ) ) && 'multi' === VoucherCheckout::purpose( array( 'single_purpose' => 0 ) ) && 'multi' === VoucherCheckout::purpose( array( 'voucher_type' => 'value' ) ) );
Settings::update( array( 'voucher_default_purpose' => 'single' ) );
check( 'the setting decides when the provider says nothing', 'single' === VoucherCheckout::purpose( array( 'voucher_type' => 'gift' ) ) && 'multi' === VoucherCheckout::purpose( array( 'purpose' => 'mehrzweck' ) ) );
Settings::update( array( 'voucher_default_purpose' => 'multi' ) );
$vp_filter = static function ( $purpose, $voucher, $context ) { return '9999' === ( $context['code_hint'] ?? '' ) ? 'single' : $purpose; };
add_filter( 'wpcap_voucher_purpose', $vp_filter, 10, 3 );
check( 'the wpcap_voucher_purpose filter can decide per voucher', 'single' === VoucherCheckout::purpose( array(), array( 'code_hint' => '9999' ) ) && 'multi' === VoucherCheckout::purpose( array(), array( 'code_hint' => '0000' ) ) );

// --- Multi-purpose at checkout --------------------------------------------------------------------
$vp_type = make_type( 20, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=$vp_type" );
$m = Orders::create( array( 'email' => 'mpv@example.com', 'items' => array( array( 'ticket_type_id' => $vp_type, 'quantity' => 3 ) ), 'idempotency_key' => 'vp-m1' ) );
$m_before = Orders::get( $m['order_id'] );
$m_applied = VoucherCheckout::apply( $m['order_id'], 'MPV-ABCD-1234' );
$m_row = Orders::get( $m['order_id'] );
$m_item_tax = (int) $wpdb->get_var( "SELECT SUM(tax_minor) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $m['order_id'] );
$m_snap = VoucherCheckout::voucher_state( $m['order_id'] );
check( 'multi-purpose: VAT and lines keep the full consideration', 500 === (int) $m_before->tax_minor && 500 === (int) $m_row->tax_minor && 500 === $m_item_tax && 0 === (int) $m_row->discount_minor && 3000 === (int) $m_row->subtotal_minor );
check( 'multi-purpose: amount due to the gateway is total minus voucher', is_array( $m_applied ) && 'multi' === $m_applied['purpose'] && 1500 === $m_applied['amount_due_minor'] && 1500 === (int) $m_row->total_minor && 'pending' === $m_row->payment_status );
check( 'multi-purpose: snapshot records the voucher payment', 'multi' === $m_snap['purpose'] && 1500 === (int) $m_snap['amount_paid_by_voucher'] && 3000 === (int) $m_snap['gross_minor'] && 1500 === VoucherCheckout::voucher_payment_minor( $m['order_id'] ) && 3000 === VoucherCheckout::gross_minor( $m['order_id'] ) );
$m_alloc = PassCaptain\Reports\Data::allocate_discount( $wpdb->get_results( "SELECT line_total_minor FROM {$p}wpcap_order_items WHERE order_id=" . (int) $m['order_id'] ), (int) $m_row->discount_minor );
check( 'multi-purpose: reports and settlements see the full ticket revenue', 3000 === array_sum( $m_alloc ) );
Orders::confirm_manual_payment( $m['order_id'] );
VoucherCheckout::commit( $m['order_id'] );
check( 'multi-purpose: voucher committed after payment', 'committed' === VoucherCheckout::voucher_state( $m['order_id'] )['status'] );

$m_inv = Invoices::issue_for_order( $m['order_id'] );
$m_inv_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $m_inv['invoice_id'] );
$m_lines = (int) $wpdb->get_var( "SELECT SUM(total_minor) FROM {$p}wpcap_invoice_lines WHERE invoice_id=" . (int) $m_inv['invoice_id'] );
check( 'multi-purpose invoice: full gross amount and full VAT', 3000 === (int) $m_inv_row->total_minor && 500 === (int) $m_inv_row->tax_minor && 3000 === $m_lines );
$m_summary = Invoices::payment_summary( $m_inv_row, (array) json_decode( $m_inv_row->buyer_snapshot, true ) );
check( 'multi-purpose invoice: payments list the voucher and the remaining amount paid', 2 === count( $m_summary['rows'] ) && -1500 === $m_summary['rows'][0][1] && str_contains( $m_summary['rows'][0][0], '…1234' ) && 1500 === $m_summary['rows'][1][1] );
$m_html = Invoices::render_html( $m_inv['invoice_id'] );
check( 'invoice print view shows "Zahlungen" with masked code only', str_contains( $m_html, 'Zahlungen' ) && str_contains( $m_html, 'Bezahlt mit Gutschein …1234' ) && ! str_contains( $m_html, 'MPV-ABCD' ) && str_contains( $m_html, 'Restbetrag (bezahlt)' ) );
$m_pdf = PassCaptain\Printing\InvoicePdf::render( $m_inv['invoice_id'] );
check( 'invoice PDF renders with the payments section', is_string( $m_pdf ) && str_starts_with( $m_pdf, '%PDF' ) );

// Refund split: proportional to the refunded tickets' value, voucher part back to the voucher.
$m_tickets = tickets( $m['order_id'] );
$m_r1 = Refunds::request( $m['order_id'], array( (int) $m_tickets[0]->ticket_id ), 'Storno', 'vp-m-r1' );
VoucherCheckout::refund( $m_r1['refund_id'], $m['order_id'] );
$m_credit1 = Invoices::credit_note_for_refund( $m_r1['refund_id'] );
$m_rc = $vp_refund_calls();
check( 'refund 1 of 3: cash part via gateway, voucher part back to the voucher', 'completed' === $m_r1['status'] && 500 === (int) $m_r1['amount_minor'] && 1 === count( $m_rc ) && 500 === $m_rc[0][1] && 'wpcap-refund-' . $m_r1['refund_id'] === $m_rc[0][2] );
check( 'refund 1 of 3: credit note corrects the full ticket price and its VAT', 1000 === (int) $m_credit1['total_minor'] && 167 === (int) $m_credit1['tax_minor'] && 833 === (int) $m_credit1['net_minor'] );
$m_r2 = Refunds::request( $m['order_id'], array( (int) $m_tickets[1]->ticket_id, (int) $m_tickets[2]->ticket_id ), 'Storno', 'vp-m-r2' );
VoucherCheckout::refund( $m_r2['refund_id'], $m['order_id'] );
$m_credit2 = Invoices::credit_note_for_refund( $m_r2['refund_id'] );
$m_rc = $vp_refund_calls();
$m_final = VoucherCheckout::voucher_state( $m['order_id'] );
check( 'last refund: remainder of the voucher, credit notes add up to the invoice', 1000 === (int) $m_r2['amount_minor'] && 2 === count( $m_rc ) && 1000 === $m_rc[1][1] && 2000 === (int) $m_credit2['total_minor'] && 333 === (int) $m_credit2['tax_minor'] && 1500 === (int) $m_final['refunded_minor'] && 3 === (int) $m_final['refunded_tickets'] );
VoucherCheckout::refund( $m_r2['refund_id'], $m['order_id'] );
check( 'repeated refund hook does not refund the voucher twice', 2 === count( $vp_refund_calls() ) );

// Multi-purpose voucher covering everything: nothing due, invoice still shows the full VAT.
$f = Orders::create( array( 'email' => 'mpv-full@example.com', 'items' => array( array( 'ticket_type_id' => $vp_type, 'quantity' => 1 ) ), 'idempotency_key' => 'vp-f1' ) );
VoucherCheckout::apply( $f['order_id'], 'MPV-FULL-5678' );
$f_row = Orders::get( $f['order_id'] );
check( 'multi-purpose voucher covering the total confirms the order with full VAT', 0 === (int) $f_row->total_minor && 'paid' === $f_row->payment_status && 167 === (int) $f_row->tax_minor && 1 === count( tickets( $f['order_id'] ) ) );
VoucherCheckout::commit( $f['order_id'] );
$f_inv = Invoices::issue_for_order( $f['order_id'] );
$f_inv_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $f_inv['invoice_id'] );
$f_summary = Invoices::payment_summary( $f_inv_row, (array) json_decode( $f_inv_row->buyer_snapshot, true ) );
check( 'fully voucher-paid invoice: 10,00 total, 1,67 VAT, nothing left to pay', 1000 === (int) $f_inv_row->total_minor && 167 === (int) $f_inv_row->tax_minor && -1000 === $f_summary['rows'][0][1] && 0 === $f_summary['rows'][1][1] );
$f_r = Refunds::request( $f['order_id'], array(), 'Absage', 'vp-f-r1' );
VoucherCheckout::refund( $f_r['refund_id'], $f['order_id'] );
$f_credit = Invoices::credit_note_for_refund( $f_r['refund_id'] );
$f_rc = $vp_refund_calls();
check( 'refund of a fully voucher-paid order: no cash, whole value back to the voucher, full credit note', 0 === (int) $f_r['amount_minor'] && 1000 === end( $f_rc )[1] && 1000 === (int) $f_credit['total_minor'] && 167 === (int) $f_credit['tax_minor'] );

// --- Single-purpose at checkout -------------------------------------------------------------------
$vp_provider->extra['SPV-ABCD-4321'] = array( 'data' => array( 'voucher' => array( 'voucher_type' => 'single_purpose' ) ) );
$s = Orders::create( array( 'email' => 'spv@example.com', 'items' => array( array( 'ticket_type_id' => $vp_type, 'quantity' => 3 ) ), 'idempotency_key' => 'vp-s1' ) );
$s_applied = VoucherCheckout::apply( $s['order_id'], 'SPV-ABCD-4321' );
$s_row = Orders::get( $s['order_id'] );
$s_item_tax = (int) $wpdb->get_var( "SELECT SUM(tax_minor) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $s['order_id'] );
$s_snap = VoucherCheckout::voucher_state( $s['order_id'] );
check( 'single-purpose: redemption stays a price reduction (VAT on the rest)', 'single' === $s_applied['purpose'] && 1500 === (int) $s_row->total_minor && 250 === (int) $s_row->tax_minor && 250 === $s_item_tax && 1500 === (int) $s_row->discount_minor );
check( 'single-purpose: purpose recorded, no voucher payment', 'single' === $s_snap['purpose'] && 0 === (int) $s_snap['amount_paid_by_voucher'] && 0 === VoucherCheckout::voucher_payment_minor( $s['order_id'] ) && 1500 === VoucherCheckout::gross_minor( $s['order_id'] ) );
Orders::confirm_manual_payment( $s['order_id'] );
VoucherCheckout::commit( $s['order_id'] );
$s_inv = Invoices::issue_for_order( $s['order_id'] );
$s_inv_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $s_inv['invoice_id'] );
$s_summary = Invoices::payment_summary( $s_inv_row, (array) json_decode( $s_inv_row->buyer_snapshot, true ) );
check( 'single-purpose invoice: reduced amount, no payment rows, a VAT note', 1500 === (int) $s_inv_row->total_minor && 250 === (int) $s_inv_row->tax_minor && array() === $s_summary['rows'] && 1 === count( $s_summary['notes'] ) && str_contains( Invoices::render_html( $s_inv['invoice_id'] ), 'Einzweckgutschein …4321' ) );
$s_tickets = tickets( $s['order_id'] );
$s_r1 = Refunds::request( $s['order_id'], array( (int) $s_tickets[0]->ticket_id ), 'Storno', 'vp-s-r1' );
VoucherCheckout::refund( $s_r1['refund_id'], $s['order_id'] );
$s_credit = Invoices::credit_note_for_refund( $s_r1['refund_id'] );
check( 'single-purpose refund: credit note equals the cash refund, voucher share returned', 500 === (int) $s_r1['amount_minor'] && 500 === (int) $s_credit['total_minor'] && (int) $s_r1['tax_minor'] === (int) $s_credit['tax_minor'] && 500 === ( $vp_rc_all = $vp_refund_calls() )[ count( $vp_rc_all ) - 1 ][1] );

// Filter decides at checkout (code ending 9999 → single-purpose).
$fl = Orders::create( array( 'email' => 'flt@example.com', 'items' => array( array( 'ticket_type_id' => $vp_type, 'quantity' => 2 ) ), 'idempotency_key' => 'vp-fl1' ) );
$fl_applied = VoucherCheckout::apply( $fl['order_id'], 'ANY-9999' );
check( 'filter result is applied at checkout', 'single' === $fl_applied['purpose'] && 'single' === VoucherCheckout::voucher_state( $fl['order_id'] )['purpose'] );
remove_filter( 'wpcap_voucher_purpose', $vp_filter, 10 );
unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] );

// --- Ticket service (voucher → tickets) -----------------------------------------------------------
$vp_service = TicketService::service( null, 1 );
$ts_type = make_type( 10, 10, 4000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1000 WHERE ticket_type_id=$ts_type" );
$ts_src = array( 'provider' => 'voucher-captain', 'voucher_id' => 501, 'redemption_key' => 'vcap-vp-501', 'code_hint' => 'WXYZ' );
$ts_m = $vp_service->issue_tickets( $ts_src, 'vp-ts-multi', array( 'ticket_type_id' => $ts_type, 'quantity' => 2 ), array( 'email' => 'ts-m@example.com' ) );
$ts_m_row = Orders::get( $ts_m['order_id'] );
$ts_m_snap = VoucherCheckout::voucher_state( $ts_m['order_id'] );
check( 'ticket service, multi-purpose: tickets at full price and VAT, paid by voucher', 'issued' === $ts_m['status'] && 2 === count( $ts_m['ticket_ids'] ) && 0 === (int) $ts_m_row->total_minor && 8000 === (int) $ts_m_row->subtotal_minor && 727 === (int) $ts_m_row->tax_minor && 'paid' === $ts_m_row->payment_status && 'multi' === $ts_m_snap['purpose'] && 8000 === (int) $ts_m_snap['amount_paid_by_voucher'] && 'external' === $ts_m_snap['status'] );
check( 'ticket service, multi-purpose: settlement value is the full gross amount', 8000 === TicketService::settlement_value_minor( $ts_m['order_id'] ) && 8000 === VoucherCheckout::gross_minor( $ts_m['order_id'] ) );
$ts_again = $vp_service->issue_tickets( $ts_src, 'vp-ts-multi', array( 'ticket_type_id' => $ts_type, 'quantity' => 2 ), array( 'email' => 'ts-m@example.com' ) );
check( 'ticket service: repeating the exchange keeps the voucher snapshot', true === $ts_again['reused'] && 0 === (int) Orders::get( $ts_m['order_id'] )->total_minor && 8000 === (int) VoucherCheckout::voucher_state( $ts_m['order_id'] )['amount_paid_by_voucher'] );
$ts_inv = Invoices::issue_for_order( $ts_m['order_id'] );
$ts_inv_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $ts_inv['invoice_id'] );
$ts_summary = Invoices::payment_summary( $ts_inv_row, (array) json_decode( $ts_inv_row->buyer_snapshot, true ) );
check( 'ticket service, multi-purpose invoice: full amount, voucher as payment', 8000 === (int) $ts_inv_row->total_minor && 727 === (int) $ts_inv_row->tax_minor && -8000 === $ts_summary['rows'][0][1] && str_contains( $ts_summary['rows'][0][0], '…WXYZ' ) );
$ts_v = $vp_service->issue_tickets( $ts_src + array( 'amount_minor' => 7000 ), 'vp-ts-multi-value', array( 'ticket_type_id' => $ts_type, 'quantity' => 2 ), array( 'email' => 'ts-v@example.com' ) );
$ts_v_row = Orders::get( $ts_v['order_id'] );
check( 'ticket service, multi-purpose: the redeemed voucher amount is the consideration', 7000 === (int) $ts_v_row->subtotal_minor && 636 === (int) $ts_v_row->tax_minor && 0 === (int) $ts_v_row->total_minor );
$ts_s = $vp_service->issue_tickets( $ts_src + array( 'purpose' => 'single', 'amount_minor' => 7500 ), 'vp-ts-single', array( 'ticket_type_id' => $ts_type, 'quantity' => 2 ), array( 'email' => 'ts-s@example.com' ) );
$ts_s_row = Orders::get( $ts_s['order_id'] );
$ts_s_snap = VoucherCheckout::voucher_state( $ts_s['order_id'] );
check( 'ticket service, single-purpose: tickets at 0 / VAT 0, voucher value kept for settlements', 'issued' === $ts_s['status'] && 0 === (int) $ts_s_row->total_minor && 0 === (int) $ts_s_row->subtotal_minor && 0 === (int) $ts_s_row->tax_minor && 'single' === $ts_s_snap['purpose'] && 7500 === (int) $ts_s_snap['face_value_minor'] && 7500 === TicketService::settlement_value_minor( $ts_s['order_id'] ) );
$ts_l = $vp_service->issue_tickets( $ts_src + array( 'single_purpose' => true ), 'vp-ts-single-list', array( 'ticket_type_id' => $ts_type, 'quantity' => 1 ), array( 'email' => 'ts-l@example.com' ) );
check( 'ticket service, single-purpose without value: list price is recorded', 4000 === TicketService::settlement_value_minor( $ts_l['order_id'] ) && 'list_price' === VoucherCheckout::voucher_state( $ts_l['order_id'] )['face_value_source'] );
