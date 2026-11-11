<?php
/** Invoices with VAT, credit notes and accounting export. Included from run.php. */
// phpcs:ignoreFile
use PassCaptain\Orders;

echo "Invoices, credit notes, accounting export\n";
PassCaptain\Settings::update( array( 'seller_name' => 'Kulturverein', 'seller_vat_id' => 'ATU12345678', 'refund_approval_threshold' => 0 ) );
$fin_type = make_type( 10, 10, 1100 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1000 WHERE ticket_type_id=$fin_type" );
$fin = Orders::create( array( 'email' => 'fin@example.com', 'items' => array( array( 'ticket_type_id' => $fin_type, 'quantity' => 3 ) ), 'idempotency_key' => 'fin-1' ) );
Orders::confirm_manual_payment( $fin['order_id'] );
$inv = PassCaptain\Invoices::issue_for_order( $fin['order_id'] );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $inv['invoice_id'] );
$line = $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=" . (int) $inv['invoice_id'] );
check( 'invoice carries VAT from order lines', 300 === (int) $row->tax_minor && 3300 === (int) $row->total_minor && 10.0 === (float) $line->tax_rate && 'issued' === $row->status );
check( 'invoice snapshot contains seller data', false !== strpos( $row->buyer_snapshot, 'ATU12345678' ) );
$fin_tickets = tickets( $fin['order_id'] );
$refund = PassCaptain\Refunds::request( $fin['order_id'], array( (int) $fin_tickets[0]->ticket_id ), 'Absage Gast', 'fin-r1' );
$credit = PassCaptain\Invoices::credit_note_for_refund( $refund['refund_id'] );
$credit_again = PassCaptain\Invoices::credit_note_for_refund( $refund['refund_id'] );
check( 'refund creates exactly one credit note with VAT share', is_array( $credit ) && 1100 === (int) $credit['total_minor'] && 100 === (int) $credit['tax_minor'] && $credit['credit_note_id'] === $credit_again['credit_note_id'] );
$html = PassCaptain\Invoices::render_html( $inv['invoice_id'] );
check( 'print view lists invoice number, VAT and credit note', false !== strpos( $html, $inv['invoice_number'] ) && false !== strpos( $html, $credit['credit_note_number'] ) && false !== strpos( $html, 'Umsatzsteuer' ) );
$rows = PassCaptain\Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d' ) );
$kinds = array_count_values( array_column( array_slice( $rows, 1 ), 0 ) );
check( 'accounting export contains invoice lines and credit notes', ( $kinds['Rechnung'] ?? 0 ) >= 1 && ( $kinds['Gutschrift'] ?? 0 ) >= 1 && 'Belegart' === $rows[0][0] );

echo "Voucher Captain bridge\n";
final class Test_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public function quote( array $context ) { $this->calls[] = array( 'quote', $context['amount_minor'] ); return 'BAD' === $context['code'] ? new WP_Error( 'invalid', 'Gutschein ungültig' ) : array( 'amount_minor' => min( 1500, $context['amount_minor'] ), 'currency' => $context['currency'], 'token' => 'q1' ); }
	public function reserve( array $quote, $key ) { $this->calls[] = array( 'reserve', $key ); return array( 'reservation' => 'r1', 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { $this->calls[] = array( 'commit', $key ); return array( 'redemption' => 'd1' ); }
	public function release( array $reservation, $key ) { $this->calls[] = array( 'release', $key ); return true; }
	public function refund( array $redemption, $amount, $key ) { $this->calls[] = array( 'refund', $amount, $key ); return true; }
}
$voucher_provider = new Test_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] = $voucher_provider;
$vt = make_type( 10, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=$vt" );
$vo = Orders::create( array( 'email' => 'v@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 3 ) ), 'idempotency_key' => 'v-1' ) );
$bad = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'BAD' );
check( 'rejected voucher returns a customer error', is_wp_error( $bad ) && 'Gutschein ungültig' === $bad->get_error_message() );
$applied = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'GOOD-1234' );
$vrow = Orders::get( $vo['order_id'] );
$item_tax = (int) $wpdb->get_var( "SELECT SUM(tax_minor) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $vo['order_id'] );
check( 'voucher reduces total and VAT consistently', 1500 === $applied['applied_minor'] && 1500 === (int) $vrow->total_minor && 250 === (int) $vrow->tax_minor && 250 === $item_tax );
check( 'second voucher on same order is rejected', is_wp_error( PassCaptain\Integrations\Vouchers\Checkout::apply( $vo['order_id'], 'GOOD-1234' ) ) );
Orders::confirm_manual_payment( $vo['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::commit( $vo['order_id'] );
$commits = array_filter( $voucher_provider->calls, static fn( $c ) => 'commit' === $c[0] );
check( 'voucher committed exactly once after payment', 1 === count( $commits ) );
$vtickets = tickets( $vo['order_id'] );
$vr = PassCaptain\Refunds::request( $vo['order_id'], array( (int) $vtickets[0]->ticket_id ), 'Storno', 'v-r1' );
PassCaptain\Integrations\Vouchers\Checkout::refund( $vr['refund_id'], $vo['order_id'] );
PassCaptain\Integrations\Vouchers\Checkout::refund( $vr['refund_id'], $vo['order_id'] ) ;
$refunds = array_values( array_filter( $voucher_provider->calls, static fn( $c ) => 'refund' === $c[0] ) );
check( 'ticket refund refunds proportional voucher share once, with stable key', 1 === count( $refunds ) && 500 === (int) $vr['amount_minor'] && 500 === $refunds[0][1] && 'wpcap-refund-' . $vr['refund_id'] === $refunds[0][2] );
$vo2 = Orders::create( array( 'email' => 'v2@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 1 ) ), 'idempotency_key' => 'v-2' ) );
$applied2 = PassCaptain\Integrations\Vouchers\Checkout::apply( $vo2['order_id'], 'GOOD-9999' );
check( 'voucher covering the full total confirms the order and issues tickets', 0 === (int) Orders::get( $vo2['order_id'] )->total_minor && 'paid' === Orders::get( $vo2['order_id'] )->payment_status && 1 === count( tickets( $vo2['order_id'] ) ) );
$vo3 = Orders::create( array( 'email' => 'v3@example.com', 'items' => array( array( 'ticket_type_id' => $vt, 'quantity' => 2 ) ), 'idempotency_key' => 'v-3' ) );
PassCaptain\Integrations\Vouchers\Checkout::apply( $vo3['order_id'], 'GOOD-5555' );
Orders::cancel_order( $vo3['order_id'], 'test' );
PassCaptain\Integrations\Vouchers\Checkout::release( $vo3['order_id'] );
$releases = array_filter( $voucher_provider->calls, static fn( $c ) => 'release' === $c[0] );
check( 'cancelled order releases its voucher reservation', 1 === count( $releases ) && false !== strpos( Orders::get( $vo3['order_id'] )->voucher_snapshot, '"released"' ) );
unset( $GLOBALS['wpcap_test_filters'][ PassCaptain\Integrations\Vouchers\Bridge::FILTER ] );
