<?php
/**
 * A086: reports, organizer settlements and reseller statements count refunds of multi-purpose voucher
 * orders from the stored refund plans (cash + voucher share, full VAT) instead of the cash-only refund row.
 * A087: organizer settlements use the stored voucher value (face_value_minor) of single-purpose vouchers
 * from the ticket service and reverse the voucher claim when tickets are refunded.
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as VoucherCheckout;
use PassCaptain\Integrations\Vouchers\TicketService;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as PartnerRepo;
use PassCaptain\Partners\Settlements;
use PassCaptain\Partners\Statement;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A086/A087: voucher refunds in reports and settlements\n";

final class A86_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $single = array();
	public function quote( array $context ) {
		$quote = array( 'amount_minor' => min( 1500, (int) $context['amount_minor'] ), 'currency' => $context['currency'] );
		if ( in_array( $context['code'], $this->single, true ) ) { $quote['voucher_type'] = 'single_purpose'; }
		return $quote;
	}
	public function reserve( array $quote, $key ) { return array( 'reservation' => 'r-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { return array( 'redemption' => 'd-' . $key ); }
	public function release( array $reservation, $key ) { return true; }
	public function refund( array $redemption, $amount, $key ) { return true; }
}
$a86_provider = new A86_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $a86_provider;
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'voucher_default_purpose' => 'multi' ) );

$a86_event_of = static function ( $type_id ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $type_id ) );
};
$a86_partner = static function ( $name, $event_id ) {
	$id = PartnerRepo::save( array( 'name' => $name, 'billing_address' => "Weg 1\n10115 Berlin", 'sales_model' => 'agent', 'commission_bp' => 0, 'fee_per_ticket_minor' => 0, 'fee_tax_rate_bp' => 1900, 'settlement_start' => gmdate( 'Y-m' ) . '-01' ) );
	PartnerRepo::set_events( $id, array( $event_id ) );
	return $id;
};
$a86_settle = static function ( $partner_id ) {
	return Settlements::compute( PartnerRepo::get( $partner_id ), gmdate( 'Y-m' ) );
};
$a86_report = static function ( $event_id ) {
	return PassCaptain\Reports\Data::build( array( 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'event_id' => $event_id ), null );
};
$a86_refund = static function ( $order_id, array $ticket_ids, $key ) {
	$r = Refunds::request( $order_id, $ticket_ids, 'Storno', $key );
	if ( is_array( $r ) && ! empty( $r['refund_id'] ) ) { VoucherCheckout::refund( $r['refund_id'], $order_id ); }
	return $r;
};

// --- A086: multi-purpose voucher (3 × 10,00 incl. 20 % VAT = 5,00 VAT; 15,00 voucher + 15,00 cash) ----
$a86_type = make_type( 20, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=$a86_type" );
$a86_ev = $a86_event_of( $a86_type );
$a86_m = Orders::create( array( 'email' => 'a86-m@example.com', 'items' => array( array( 'ticket_type_id' => $a86_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a86-m1' ) );
VoucherCheckout::apply( $a86_m['order_id'], 'A86-MPV-0001' );
Orders::confirm_manual_payment( $a86_m['order_id'] );
VoucherCheckout::commit( $a86_m['order_id'] );
PassCaptain\Invoices::issue_for_order( $a86_m['order_id'] );
// The reseller statement lists API sales: this order counts as sold through a reseller.
$a86_reseller = PartnerRepo::save( array( 'name' => 'A86 Wiederverkäufer', 'billing_address' => "Weg 2\n10115 Berlin", 'sales_model' => 'agent', 'commission_bp' => 0, 'fee_per_ticket_minor' => 0, 'fee_tax_rate_bp' => 1900 ) );
$wpdb->insert( "{$p}wpcap_partner_orders", array( 'order_id' => (int) $a86_m['order_id'], 'partner_id' => $a86_reseller, 'mode' => 'direct', 'external_reference' => 'A86-EXT', 'confirmed_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a86_org = $a86_partner( 'A86 Veranstalter', $a86_ev );

$a86_rep0 = $a86_report( $a86_ev );
check( 'A086: report before refunds shows the full 30,00 incl. 5,00 VAT', 3000 === (int) $a86_rep0['total']['gross'] && 500 === (int) $a86_rep0['total']['tax'] && 3000 === (int) $a86_rep0['total']['revenue'] );

$a86_t = tickets( $a86_m['order_id'] );
$a86_r1 = $a86_refund( $a86_m['order_id'], array( (int) $a86_t[0]->ticket_id ), 'a86-m-r1' );
$a86_plan1 = VoucherCheckout::refund_plan( $a86_r1['refund_id'] );
check( 'A086: refund 1 of 3 is 5,00 cash + 5,00 voucher (stored plan, VAT 1,67)', 500 === (int) $a86_r1['amount_minor'] && 500 === (int) $a86_plan1['voucher_minor'] && 167 === (int) $a86_plan1['tax_minor'] );
$a86_rep1 = $a86_report( $a86_ev );
check( 'A086: report counts the refunded ticket at 10,00 (cash + voucher), not only the 5,00 cash', 1000 === (int) $a86_rep1['total']['refunded'] && 167 === (int) $a86_rep1['total']['refunded_tax'] && 2000 === (int) $a86_rep1['total']['revenue'] && 333 === (int) $a86_rep1['total']['revenue_tax'] && 1 === (int) $a86_rep1['total']['refunded_tickets'] );
check( 'A086: report rate and event buckets match the total', 2000 === (int) ( $a86_rep1['rates'][2000]['revenue'] ?? -1 ) && 2000 === (int) ( $a86_rep1['dimensions']['event'][ $a86_ev ]['revenue'] ?? -1 ) );

$a86_r2 = $a86_refund( $a86_m['order_id'], array( (int) $a86_t[1]->ticket_id, (int) $a86_t[2]->ticket_id ), 'a86-m-r2' );
$a86_rep2 = $a86_report( $a86_ev );
check( 'A086: fully refunded order leaves 0,00 revenue and 0,00 VAT in the report (not 15,00)', 3000 === (int) $a86_rep2['total']['refunded'] && 500 === (int) $a86_rep2['total']['refunded_tax'] && 0 === (int) $a86_rep2['total']['revenue'] && 0 === (int) $a86_rep2['total']['revenue_tax'] );
check( 'A086: report refunds equal the credit notes of the refunds', (int) $a86_rep2['total']['refunded'] === (int) PassCaptain\Invoices::credit_note_for_refund( $a86_r1['refund_id'] )['total_minor'] + (int) PassCaptain\Invoices::credit_note_for_refund( $a86_r2['refund_id'] )['total_minor'] );

$a86_st = Statement::compute( PartnerRepo::get( $a86_reseller ), gmdate( 'Y-m' ) );
check( 'A086: reseller statement nets the voucher share of refunds (sales 30,00, refunds 30,00, balance 0)', 3000 === (int) $a86_st['totals']['sales_gross'] && 3000 === (int) $a86_st['totals']['refunded'] && 0 === (int) $a86_st['totals']['gross'] && 0 === (int) $a86_st['totals']['tax'] );

$a86_s = $a86_settle( $a86_org );
check( 'A086: organizer settlement nets the full refunds (gross 0, VAT 0)', 3000 === (int) $a86_s['totals']['sales_gross'] && 3000 === (int) $a86_s['totals']['refunded'] && 500 === (int) $a86_s['totals']['refunded_tax'] && 0 === (int) $a86_s['totals']['gross'] && 0 === (int) $a86_s['totals']['tax'] );

// The stored plan wins: the report must not recompute (e.g. after the order lines changed).
$a86_snap = VoucherCheckout::voucher_state( $a86_m['order_id'] );
check( 'A086: plans are stored in the voucher snapshot', isset( $a86_snap['refund_plans'][ (string) $a86_r1['refund_id'] ], $a86_snap['refund_plans'][ (string) $a86_r2['refund_id'] ] ) );
$a86_amounts = VoucherCheckout::refund_amounts( (object) array( 'refund_id' => $a86_r2['refund_id'], 'order_id' => $a86_m['order_id'], 'amount_minor' => 1000, 'tax_minor' => 1 ) );
check( 'A086: refund_amounts() reads cash + voucher and the full VAT from the stored plan', 2000 === $a86_amounts['amount_minor'] && 333 === $a86_amounts['tax_minor'] && 1000 === $a86_amounts['voucher_minor'] && 'multi' === $a86_amounts['purpose'] );
$a86_plain = VoucherCheckout::refund_amounts( (object) array( 'refund_id' => 0, 'order_id' => 0, 'amount_minor' => 700, 'tax_minor' => 70 ) );
check( 'A086: refunds without voucher keep the refund row amounts', 700 === $a86_plain['amount_minor'] && 70 === $a86_plain['tax_minor'] && 0 === $a86_plain['voucher_minor'] && null === $a86_plain['purpose'] );

// --- A087: single-purpose voucher at checkout (3 × 10,00, 15,00 voucher booked as discount) ----------
$a87_type = make_type( 20, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=$a87_type" );
$a87_ev = $a86_event_of( $a87_type );
$a87_org = $a86_partner( 'A87 Veranstalter', $a87_ev );
$a86_provider->single[] = 'A87-SPV-0001';
$a87_s = Orders::create( array( 'email' => 'a87-s@example.com', 'items' => array( array( 'ticket_type_id' => $a87_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a87-s1' ) );
VoucherCheckout::apply( $a87_s['order_id'], 'A87-SPV-0001' );
Orders::confirm_manual_payment( $a87_s['order_id'] );
VoucherCheckout::commit( $a87_s['order_id'] );
$a87_set0 = $a86_settle( $a87_org );
check( 'A087: checkout single-purpose voucher: 15,00 sale + 15,00 voucher claim', 3000 === (int) $a87_set0['totals']['sales_gross'] && 1500 === (int) ( $a87_set0['totals']['kinds']['voucher']['gross'] ?? 0 ) );
$a87_t = tickets( $a87_s['order_id'] );
$a87_r1 = $a86_refund( $a87_s['order_id'], array( (int) $a87_t[0]->ticket_id ), 'a87-s-r1' );
$a87_set1 = $a86_settle( $a87_org );
$a87_back = array_values( array_filter( $a87_set1['lines'], static fn( $l ) => 'voucher_refund' === $l['kind'] ) );
check( 'A087: refund of 1 of 3 tickets reverses 5,00 cash and the 5,00 voucher claim (20,00 left, not 25,00)', 500 === (int) $a87_r1['amount_minor'] && 1 === count( $a87_back ) && -500 === (int) $a87_back[0]['gross_minor'] && 0 === (int) $a87_back[0]['tax_minor'] && (int) $a87_r1['refund_id'] === (int) $a87_back[0]['refund_id'] && 1000 === (int) $a87_set1['totals']['refunded'] && 2000 === (int) $a87_set1['totals']['gross'] );

// --- A087: single-purpose voucher from the ticket service (2 tickets, lines 0,00, voucher value 75,00) --
$a87_service = TicketService::service( null, 1 );
$a87_ts_type = make_type( 10, 10, 4000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1000 WHERE ticket_type_id=$a87_ts_type" );
$a87_ts_ev = $a86_event_of( $a87_ts_type );
$a87_ts_org = $a86_partner( 'A87 Ticketdienst', $a87_ts_ev );
$a87_src = array( 'provider' => 'voucher-captain', 'voucher_id' => 870, 'redemption_key' => 'vcap-a87-870', 'code_hint' => 'A087', 'purpose' => 'single', 'amount_minor' => 7500 );
$a87_ts = $a87_service->issue_tickets( $a87_src, 'a87-ts-single', array( 'ticket_type_id' => $a87_ts_type, 'quantity' => 2 ), array( 'email' => 'a87-ts@example.com' ) );
check( 'A087: ticket service order is free with face_value_minor 75,00', 'issued' === $a87_ts['status'] && 0 === (int) Orders::get( $a87_ts['order_id'] )->subtotal_minor && 7500 === (int) VoucherCheckout::voucher_state( $a87_ts['order_id'] )['face_value_minor'] && 7500 === VoucherCheckout::single_purpose_minor( $a87_ts['order_id'] ) );
$a87_ts0 = $a86_settle( $a87_ts_org );
$a87_ts_v = array_values( array_filter( $a87_ts0['lines'], static fn( $l ) => 'voucher' === $l['kind'] ) );
check( 'A087: organizer is settled the 75,00 voucher value of the ticket service order (not 0,00)', 1 === count( $a87_ts_v ) && 7500 === (int) $a87_ts_v[0]['gross_minor'] && 0 === (int) $a87_ts_v[0]['tax_minor'] && 7500 === (int) $a87_ts0['totals']['sales_gross'] && 7500 === (int) $a87_ts0['totals']['gross'] );
// A stored face value wins over the reserved amount (e.g. a partially used voucher snapshot).
$a87_snap = VoucherCheckout::voucher_state( $a87_ts['order_id'] );
$wpdb->update( "{$p}wpcap_orders", array( 'voucher_snapshot' => wp_json_encode( array( 'amount_minor' => 9999 ) + $a87_snap ) ), array( 'order_id' => $a87_ts['order_id'] ) );
check( 'A087: single_purpose_minor() prefers face_value_minor', 7500 === VoucherCheckout::single_purpose_minor( $a87_ts['order_id'] ) );
$wpdb->update( "{$p}wpcap_orders", array( 'voucher_snapshot' => wp_json_encode( $a87_snap ) ), array( 'order_id' => $a87_ts['order_id'] ) );

$a87_ts_t = tickets( $a87_ts['order_id'] );
$a87_ts_r1 = $a86_refund( $a87_ts['order_id'], array( (int) $a87_ts_t[0]->ticket_id ), 'a87-ts-r1' );
$a87_ts_plan = VoucherCheckout::refund_plan( $a87_ts_r1['refund_id'] ?? 0 );
check( 'A087: refund of 1 of 2 free voucher tickets plans half the voucher value (37,50)', is_array( $a87_ts_r1 ) && 0 === (int) $a87_ts_r1['amount_minor'] && is_array( $a87_ts_plan ) && 3750 === (int) $a87_ts_plan['voucher_minor'] && 'single' === $a87_ts_plan['purpose'] );
$a87_ts1 = $a86_settle( $a87_ts_org );
check( 'A087: settlement reverses the voucher claim of the refunded ticket (37,50 left)', 3750 === (int) $a87_ts1['totals']['gross'] && -3750 === (int) ( $a87_ts1['totals']['kinds']['voucher_refund']['gross'] ?? 0 ) );
$a87_ts_r2 = $a86_refund( $a87_ts['order_id'], array( (int) $a87_ts_t[1]->ticket_id ), 'a87-ts-r2' );
$a87_ts2 = $a86_settle( $a87_ts_org );
check( 'A087: last refund reverses the remainder (0,00 left)', 3750 === (int) VoucherCheckout::refund_plan( $a87_ts_r2['refund_id'] )['voucher_minor'] && 0 === (int) $a87_ts2['totals']['gross'] && 0 === (int) $a87_ts2['totals']['payout'] );

// Voucher lines carry their own source keys, so finalizing marks them as settled exactly once.
$a87_keys = array_column( array_filter( $a87_ts2['lines'], static fn( $l ) => in_array( $l['kind'], array( 'voucher', 'voucher_refund' ), true ) ), 'item_key' );
sort( $a87_keys );
check( 'A087: voucher claim and its reversals have unique settlement keys', array( 'voucher:' . (int) $a87_ts['order_id'], 'voucher_refund:' . (int) $a87_ts_r1['refund_id'], 'voucher_refund:' . (int) $a87_ts_r2['refund_id'] ) === $a87_keys );
$a87_draft = Settlements::create_draft( $a87_ts_org, gmdate( 'Y-m' ) );
$a87_csv = is_wp_error( $a87_draft ) ? array() : Settlements::csv_rows( Settlements::get( $a87_draft ) );
check( 'A087: settlement CSV names the reversed voucher claim', str_contains( wp_json_encode( $a87_csv, JSON_UNESCAPED_UNICODE ), 'Einzweckgutschein zurückgegeben' ) );

foreach ( array( $a86_org, $a87_org, $a87_ts_org ) as $a86_pid ) { PartnerRepo::set_events( $a86_pid, array() ); }
unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] );
