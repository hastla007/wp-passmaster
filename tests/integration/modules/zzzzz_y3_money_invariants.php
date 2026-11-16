<?php
/**
 * Money invariants (LEAD-2) and regressions for amounts, receipts and settlements (PAY-3/4/5/6/9).
 *
 * Matrix: campaign none / single / stacked × voucher none / single-purpose / multi-purpose × partial
 * and full refund, in EUR and JPY (zero-decimal currency). For every combination:
 * - Σ price_snapshot.line_discounts = orders.discount_minor, Σ line VAT = orders.tax_minor;
 * - Σ invoice lines = invoice total (and the invoice total = the full consideration);
 * - a partial refund (cash + voucher share) is at most the paid value of the refunded ticket;
 * - after the full refund cash refunds = cash paid and voucher refunds = voucher amount;
 * - partner settlement = till − refunds (incl. the voucher part of a multi-purpose voucher);
 * - refunds are split over the lines by discounted value (two organizers in one order, Z2).
 *
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Campaigns;
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as Y3Vouchers;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as Y3Partners;
use PassCaptain\Partners\Settlements as Y3Settlements;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

echo "Money invariants: campaigns × vouchers × refunds (EUR, JPY)\n";

final class Y3_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	/** Share of the amount due the voucher covers (basis points) and its purpose. */
	public int $share_bp = 3000;
	public string $purpose = 'multi';
	public array $refunds = array();
	public function quote( array $context ) { return array( 'amount_minor' => max( 1, intdiv( (int) $context['amount_minor'] * $this->share_bp, 10000 ) ), 'currency' => $context['currency'], 'purpose' => $this->purpose ); }
	public function reserve( array $quote, $key ) { return array( 'reservation' => 'y3-' . $key, 'amount_minor' => $quote['amount_minor'], 'purpose' => $this->purpose ); }
	public function commit( array $reservation, $key ) { return array( 'redemption' => 'y3d-' . $key ); }
	public function release( array $reservation, $key ) { return true; }
	public function refund( array $redemption, $amount, $key ) { $this->refunds[ $key ] = (int) $amount; return true; }
}
$y3_provider_before = $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ?? null;
$y3_vp = new Y3_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $y3_vp;
$y3_settings_before = array( 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'auto_invoice' => Settings::get( 'auto_invoice' ), 'max_total_discount_bp' => Settings::get( 'max_total_discount_bp' ) );
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'max_total_discount_bp' => 0, 'seller_name' => 'Kulturverein' ) );

/** Event with two ticket types (different VAT rates) in one occurrence: [event_id, type_a, type_b]. */
function y3_event( $currency, $price_a, $price_b ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$a = make_type( 100, 10, $price_a );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a" );
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET currency=%s,tax_rate_bp=1000 WHERE ticket_type_id=%d", $currency, $a ) );
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Ermäßigt', 'price_minor' => $price_b, 'currency' => $currency, 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active', 'tax_rate_bp' => 2000 ) );
	$b = (int) $wpdb->insert_id;
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	return array( $event, $a, $b );
}

function y3_line_discount_sum( $order ) {
	$split = Orders::snapshot_line_discounts( $order );
	return null === $split ? (int) $order->discount_minor : array_sum( $split );
}

$y3_month = gmdate( 'Y-m' );
$y3_failures = array();
$y3_n = 0;
foreach ( array( 'EUR' => array( 10000, 4500 ), 'JPY' => array( 3000, 1300 ) ) as $y3_cur => $y3_prices ) {
	foreach ( array( 'none', 'single', 'stacked' ) as $y3_camp ) {
		foreach ( array( 'none', 'single', 'multi' ) as $y3_vmode ) {
			$y3_n++;
			$label = "$y3_cur/campaign:$y3_camp/voucher:$y3_vmode";
			list( $ev, $ta, $tb ) = y3_event( $y3_cur, $y3_prices[0], $y3_prices[1] );
			$codes = array();
			$sfx = strtoupper( substr( md5( $label . microtime() ), 0, 8 ) );
			if ( 'none' !== $y3_camp ) {
				Campaigns::create( array( 'code' => 'YP' . $sfx, 'kind' => 'percent', 'value' => 1000, 'currency' => $y3_cur, 'priority' => 2 ) );
				$codes[] = 'YP' . $sfx;
			}
			if ( 'stacked' === $y3_camp ) {
				// Fixed code on type B first (priority 1), then 10 % on everything that is left.
				Campaigns::create( array( 'code' => 'YF' . $sfx, 'kind' => 'fixed', 'value' => intdiv( $y3_prices[1], 3 ), 'currency' => $y3_cur, 'priority' => 1, 'ticket_type_id' => $tb ) );
				$codes[] = 'YF' . $sfx;
			}
			$r = Orders::create( array( 'email' => 'y3-' . $y3_n . '@example.com', 'items' => array( array( 'ticket_type_id' => $ta, 'quantity' => 2 ), array( 'ticket_type_id' => $tb, 'quantity' => 1 ) ), 'campaign_codes' => $codes, 'idempotency_key' => 'y3-' . $sfx ), array( 'skip_rate_limit' => true ) );
			if ( is_wp_error( $r ) ) { $y3_failures[] = "$label: order " . $r->get_error_message(); continue; }
			$oid = (int) $r['order_id'];
			$voucher = 0;
			if ( 'none' !== $y3_vmode ) {
				$y3_vp->purpose = $y3_vmode;
				$applied = Y3Vouchers::apply( $oid, 'Y3-' . $sfx );
				if ( is_wp_error( $applied ) ) { $y3_failures[] = "$label: voucher " . $applied->get_error_message(); continue; }
				$voucher = (int) $applied['applied_minor'];
			}
			$o = Orders::get( $oid );
			$items = Orders::items_for_order( $oid );
			$item_tax = array_sum( array_map( static fn( $i ) => (int) $i->tax_minor, $items ) );
			$item_gross = array_sum( array_map( static fn( $i ) => (int) $i->line_total_minor, $items ) );
			if ( (int) $o->discount_minor && y3_line_discount_sum( $o ) !== (int) $o->discount_minor ) { $y3_failures[] = "$label: Σ line_discounts " . y3_line_discount_sum( $o ) . ' ≠ discount ' . $o->discount_minor; }
			if ( $item_tax !== (int) $o->tax_minor ) { $y3_failures[] = "$label: Σ line VAT $item_tax ≠ order VAT {$o->tax_minor}"; }
			$multi_voucher = 'multi' === $y3_vmode ? $voucher : 0;
			if ( $item_gross - (int) $o->discount_minor !== (int) $o->total_minor + $multi_voucher ) { $y3_failures[] = "$label: subtotal − discount ≠ amount due + multi-purpose voucher"; }
			Orders::confirm_manual_payment( $oid );
			if ( $voucher ) { Y3Vouchers::commit( $oid ); }
			$o = Orders::get( $oid );
			$cash_paid = (int) $o->total_minor;
			// Invoice.
			$inv = Invoices::issue_for_order( $oid );
			if ( is_wp_error( $inv ) ) {
				$y3_failures[] = "$label: invoice " . $inv->get_error_message();
			} else {
				$inv_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=%d", (int) $inv['invoice_id'] ) );
				$inv_lines = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(total_minor) AS total, SUM(tax_minor) AS tax FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", (int) $inv['invoice_id'] ) );
				if ( (int) $inv_lines->total !== (int) $inv_row->total_minor ) { $y3_failures[] = "$label: Σ invoice lines {$inv_lines->total} ≠ invoice total {$inv_row->total_minor}"; }
				if ( (int) $inv_lines->tax !== (int) $inv_row->tax_minor ) { $y3_failures[] = "$label: Σ invoice line VAT ≠ invoice VAT"; }
				if ( (int) $inv_row->total_minor !== $cash_paid + $multi_voucher ) { $y3_failures[] = "$label: invoice total ≠ full consideration"; }
			}
			// Partial refund of one ticket of type A.
			$tickets = array_values( array_filter( Orders::tickets_for_order( $oid ), static fn( $t ) => (int) $t->ticket_type_id === $ta ) );
			$split = (array) Orders::snapshot_line_discounts( Orders::get( $oid ) );
			$vstate = $voucher ? (array) Y3Vouchers::voucher_state( $oid ) : array();
			$item_a = array_values( array_filter( $items, static fn( $i ) => (int) $i->ticket_type_id === $ta ) )[0];
			$campaign_disc_a = (int) ( $split[ $ta ] ?? 0 ) - ( 'single' === $y3_vmode ? (int) ( $vstate['voucher_lines'][ $ta ] ?? 0 ) : 0 );
			$value_a = ( (int) $item_a->line_total_minor - $campaign_disc_a ) / 2;
			$rf1 = Refunds::request( $oid, array( (int) $tickets[0]->ticket_id ), 'Teil', 'y3-r1-' . $sfx );
			if ( is_wp_error( $rf1 ) ) { $y3_failures[] = "$label: partial refund " . $rf1->get_error_message(); continue; }
			if ( $voucher ) { Y3Vouchers::refund( (int) $rf1['refund_id'], $oid ); }
			$v1 = (int) ( $y3_vp->refunds[ 'wpcap-refund-' . (int) $rf1['refund_id'] ] ?? 0 );
			$paid_back = (int) $rf1['amount_minor'] + $v1;
			if ( $paid_back > (int) ceil( $value_a ) || $paid_back < (int) floor( $value_a ) - 1 ) { $y3_failures[] = "$label: partial refund cash {$rf1['amount_minor']} + voucher $v1 vs paid ticket value $value_a"; }
			// Full refund of the rest.
			$rf2 = Refunds::request( $oid, array(), 'Rest', 'y3-r2-' . $sfx );
			if ( is_wp_error( $rf2 ) ) { $y3_failures[] = "$label: full refund " . $rf2->get_error_message(); continue; }
			if ( $voucher ) { Y3Vouchers::refund( (int) $rf2['refund_id'], $oid ); }
			$v2 = (int) ( $y3_vp->refunds[ 'wpcap-refund-' . (int) $rf2['refund_id'] ] ?? 0 );
			if ( (int) $rf1['amount_minor'] + (int) $rf2['amount_minor'] !== $cash_paid ) { $y3_failures[] = "$label: cash refunds ≠ cash paid $cash_paid"; }
			if ( $v1 + $v2 !== $voucher ) { $y3_failures[] = "$label: voucher refunds " . ( $v1 + $v2 ) . " ≠ voucher $voucher"; }
			// Partner settlement: till − refunds.
			$pid = Y3Partners::save( array( 'name' => 'Y3 ' . $label, 'currency' => $y3_cur, 'commission_bp' => 0, 'settlement_start' => $y3_month . '-01' ) );
			$pid = is_array( $pid ) ? (int) $pid['partner_id'] : (int) $pid;
			Y3Partners::set_events( $pid, array( $ev ) );
			$tt = Y3Settlements::compute( Y3Partners::get( $pid ), $y3_month )['totals'];
			// Merged design: a single-purpose voucher is a claim of the organizer against the voucher seller and
			// is settled as its own line ("voucher"), so the organizer's sales = till + voucher value; the ticket
			// sale lines themselves carry the amount after the voucher.
			$single_voucher = 'single' === $y3_vmode ? $voucher : 0;
			$sale_lines = (int) ( $tt['kinds']['sale']['gross'] ?? 0 ) + (int) ( $tt['kinds']['late_sale']['gross'] ?? 0 );
			if ( $sale_lines !== $cash_paid + $multi_voucher ) { $y3_failures[] = "$label: settlement sale lines $sale_lines ≠ till " . ( $cash_paid + $multi_voucher ); }
			if ( (int) $tt['sales_gross'] !== $cash_paid + $multi_voucher + $single_voucher ) { $y3_failures[] = "$label: settlement sales {$tt['sales_gross']} ≠ till + single-purpose voucher " . ( $cash_paid + $multi_voucher + $single_voucher ); }
			if ( (int) $tt['refunded'] !== $cash_paid + $multi_voucher + $single_voucher || 0 !== (int) $tt['gross'] ) { $y3_failures[] = "$label: settlement refunded {$tt['refunded']}, gross {$tt['gross']} (expected all back, 0)"; }
		}
	}
}
check( 'money invariants hold for all ' . $y3_n . ' combinations (campaign × voucher × partial/full refund, EUR and JPY)' . ( $y3_failures ? ': ' . implode( ' | ', array_slice( $y3_failures, 0, 6 ) ) : '' ), 18 === $y3_n && ! $y3_failures );

echo "PAY-5: stacked codes – calculated amounts = stored line split\n";
$s_x = make_type( 50, 10, 10000 );
$s_y = make_type( 50, 10, 10000 );
$s_z = make_type( 50, 10, 10000 );
$s_sfx = strtoupper( substr( md5( microtime() ), 0, 6 ) );
Campaigns::create( array( 'code' => 'YALL' . $s_sfx, 'kind' => 'fixed', 'value' => 10000, 'currency' => 'EUR', 'priority' => 1 ) );
Campaigns::create( array( 'code' => 'YY' . $s_sfx, 'kind' => 'fixed', 'value' => 10000, 'currency' => 'EUR', 'priority' => 2, 'ticket_type_id' => $s_y ) );
$s_r = Orders::create( array( 'email' => 'y3-stack@example.com', 'items' => array( array( 'ticket_type_id' => $s_x, 'quantity' => 1 ), array( 'ticket_type_id' => $s_y, 'quantity' => 1 ), array( 'ticket_type_id' => $s_z, 'quantity' => 1 ) ), 'campaign_codes' => array( 'YALL' . $s_sfx, 'YY' . $s_sfx ), 'idempotency_key' => 'y3-stack-' . $s_sfx ), array( 'skip_rate_limit' => true ) );
$s_o = ! is_wp_error( $s_r ) ? Orders::get( $s_r['order_id'] ) : null;
$s_snap = $s_o ? json_decode( (string) $s_o->price_snapshot, true ) : array();
$s_codes = array_sum( array_column( (array) ( $s_snap['campaigns'] ?? array() ), 'amount_minor' ) );
check( 'stacked codes on overlapping lines: Σ line discounts = discount = Σ code amounts, no line below zero', $s_o && array_sum( (array) $s_snap['line_discounts'] ) === (int) $s_o->discount_minor && $s_codes === (int) $s_o->discount_minor && max( (array) $s_snap['line_discounts'] ) <= 10000 && (int) $s_o->total_minor === 30000 - (int) $s_o->discount_minor );
// with_price_rules = 0: the code must not reach a price-rule line through the stored split either.
Campaigns::create( array( 'code' => 'YNR' . $s_sfx, 'kind' => 'percent', 'value' => 5000, 'currency' => 'EUR', 'with_price_rules' => 0 ) );
$s_calc = Campaigns::calculate(
	array(
		array( 'type' => (object) array( 'ticket_type_id' => 901 ), 'event_id' => 1, 'quantity' => 1, 'line_total' => 1000, 'price_rule' => array( 'kind' => 'early_bird' ) ),
		array( 'type' => (object) array( 'ticket_type_id' => 902 ), 'event_id' => 1, 'quantity' => 1, 'line_total' => 1000 ),
	),
	array( 'YNR' . $s_sfx ),
	'EUR'
);
check( 'calculate() returns the line split it used; with_price_rules = 0 keeps the price-rule line out of it', is_array( $s_calc ) && 500 === (int) $s_calc[0]['amount_minor'] && array( 1 => 500 ) === array_filter( (array) $s_calc[0]['lines'] ) );

echo "PAY-4: single-purpose voucher and campaign – receipts follow the voucher\n";
Settings::update( array( 'voucher_default_purpose' => 'single' ) );
$y3_vp->purpose = 'single';
$y3_vp->share_bp = 3000;
list( $f_ev, $f_a, $f_b ) = y3_event( 'EUR', 10000, 5000 );
$f_sfx = strtoupper( substr( md5( microtime() . 'f' ), 0, 6 ) );
Campaigns::create( array( 'code' => 'YFP' . $f_sfx, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR' ) );
$f_r = Orders::create( array( 'email' => 'y3-fiscal@example.com', 'items' => array( array( 'ticket_type_id' => $f_a, 'quantity' => 2 ) ), 'campaign_codes' => array( 'YFP' . $f_sfx ), 'idempotency_key' => 'y3-f1-' . $f_sfx ), array( 'skip_rate_limit' => true ) );
$f_oid = (int) $f_r['order_id'];
// An unsent fiscal document as Modules\Fiscalization::snapshot_order() writes it at checkout.
$f_snapshot = array( 'schema_version' => 1, 'order_id' => $f_oid, 'gross_minor' => 18000, 'tax_minor' => 1636, 'items' => array( array( 'ticket_type_id' => $f_a, 'quantity' => 2, 'gross_minor' => 18000, 'tax_rate_bp' => 1000, 'tax_minor' => 1636, 'seller_mode' => 'platform_seller' ) ) );
$wpdb->insert( "{$p}wpcap_fiscal_documents", array( 'order_id' => $f_oid, 'seller_id' => 1, 'document_key' => hash( 'sha256', 'y3f' . $f_oid ), 'country_code' => 'AT', 'provider_id' => 'test', 'status' => 'pending', 'currency' => 'EUR', 'gross_minor' => 18000, 'tax_minor' => 1636, 'snapshot' => wp_json_encode( $f_snapshot ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$f_doc = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_fiscal_document_items", array( 'document_id' => $f_doc, 'ticket_type_id' => $f_a, 'seller_mode' => 'platform_seller', 'quantity' => 2, 'gross_minor' => 18000, 'tax_rate_bp' => 1000, 'tax_minor' => 1636 ) );
$f_applied = Y3Vouchers::apply( $f_oid, 'Y3F-' . $f_sfx );
$f_o = Orders::get( $f_oid );
$f_doc_row = $wpdb->get_row( "SELECT gross_minor,tax_minor,snapshot FROM {$p}wpcap_fiscal_documents WHERE document_id=$f_doc" );
$f_doc_item = $wpdb->get_row( "SELECT gross_minor,tax_minor FROM {$p}wpcap_fiscal_document_items WHERE document_id=$f_doc" );
$f_doc_snap = json_decode( (string) $f_doc_row->snapshot, true );
check( 'single-purpose voucher on a campaign order: line split includes the voucher (Σ = discount)', ! is_wp_error( $f_applied ) && 5400 === (int) $f_applied['applied_minor'] && 12600 === (int) $f_o->total_minor && array( $f_a => 2000 + 5400 ) === Orders::snapshot_line_discounts( $f_o ) && 7400 === (int) $f_o->discount_minor );
check( 'the unsent fiscal document is rebuilt on the reduced amount (items, totals, snapshot)', 12600 === (int) $f_doc_row->gross_minor && 12600 === (int) $f_doc_item->gross_minor && (int) $f_o->tax_minor === (int) $f_doc_row->tax_minor && 1145 === (int) $f_o->tax_minor && 12600 === (int) $f_doc_snap['gross_minor'] && 12600 === (int) $f_doc_snap['items'][0]['gross_minor'] );
Orders::confirm_manual_payment( $f_oid );
Y3Vouchers::commit( $f_oid );
$f_inv = Invoices::issue_for_order( $f_oid );
$f_inv_row = $wpdb->get_row( $wpdb->prepare( "SELECT total_minor FROM {$p}wpcap_invoices WHERE invoice_id=%d", (int) $f_inv['invoice_id'] ) );
$f_inv_lines = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total_minor) FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", (int) $f_inv['invoice_id'] ) );
$f_pid = Y3Partners::save( array( 'name' => 'Y3 fiscal', 'currency' => 'EUR', 'commission_bp' => 0, 'settlement_start' => $y3_month . '-01' ) );
$f_pid = is_array( $f_pid ) ? (int) $f_pid['partner_id'] : (int) $f_pid;
Y3Partners::set_events( $f_pid, array( $f_ev ) );
$f_tt = Y3Settlements::compute( Y3Partners::get( $f_pid ), $y3_month )['totals'];
check( 'invoice lines and partner settlement use the amount after the voucher (12.600, not 18.000)', 12600 === (int) $f_inv_row->total_minor && 12600 === $f_inv_lines && 12600 === (int) ( $f_tt['kinds']['sale']['gross'] ?? 0 ) && 5400 === (int) ( $f_tt['kinds']['voucher']['gross'] ?? 0 ) && 18000 === (int) $f_tt['sales_gross'] );
// A document that may already have reached the provider cannot follow: the voucher is refused.
$g_r = Orders::create( array( 'email' => 'y3-fiscal2@example.com', 'items' => array( array( 'ticket_type_id' => $f_a, 'quantity' => 1 ) ), 'idempotency_key' => 'y3-f2-' . $f_sfx ), array( 'skip_rate_limit' => true ) );
$g_oid = (int) $g_r['order_id'];
$wpdb->insert( "{$p}wpcap_fiscal_documents", array( 'order_id' => $g_oid, 'seller_id' => 1, 'document_key' => hash( 'sha256', 'y3g' . $g_oid ), 'country_code' => 'AT', 'provider_id' => 'test', 'status' => 'retry', 'attempts' => 1, 'currency' => 'EUR', 'gross_minor' => 10000, 'tax_minor' => 909, 'snapshot' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$g_applied = Y3Vouchers::apply( $g_oid, 'Y3G-' . $f_sfx );
$g_o = Orders::get( $g_oid );
check( 'single-purpose voucher is refused once a fiscal document was (possibly) sent; the order stays unchanged', is_wp_error( $g_applied ) && 'wpcap_voucher_fiscal_locked' === $g_applied->get_error_code() && 10000 === (int) $g_o->total_minor && empty( $g_o->voucher_snapshot ) );
Settings::update( array( 'voucher_default_purpose' => 'multi' ) );

echo "PAY-6 / PAY-3: multi-purpose voucher + campaign – partial refund and settlement\n";
$y3_vp->purpose = 'multi';
$y3_vp->share_bp = 8333; // 15.000 of 18.000
list( $m_ev, $m_a ) = y3_event( 'EUR', 10000, 5000 );
$m_sfx = strtoupper( substr( md5( microtime() . 'm' ), 0, 6 ) );
Campaigns::create( array( 'code' => 'YMP' . $m_sfx, 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR' ) );
$m_r = Orders::create( array( 'email' => 'y3-multi@example.com', 'items' => array( array( 'ticket_type_id' => $m_a, 'quantity' => 2 ) ), 'campaign_codes' => array( 'YMP' . $m_sfx ), 'idempotency_key' => 'y3-m1-' . $m_sfx ), array( 'skip_rate_limit' => true ) );
$m_oid = (int) $m_r['order_id'];
$m_applied = Y3Vouchers::apply( $m_oid, 'Y3M-' . $m_sfx );
Orders::confirm_manual_payment( $m_oid );
Y3Vouchers::commit( $m_oid );
$m_due = (int) Orders::get( $m_oid )->total_minor;
$m_tickets = array_values( Orders::tickets_for_order( $m_oid ) );
$m_rf = Refunds::request( $m_oid, array( (int) $m_tickets[0]->ticket_id ), 'Teil', 'y3-m-r1-' . $m_sfx );
Y3Vouchers::refund( (int) $m_rf['refund_id'], $m_oid );
$m_v = (int) ( $y3_vp->refunds[ 'wpcap-refund-' . (int) $m_rf['refund_id'] ] ?? -1 );
check( 'partial refund with campaign + multi-purpose voucher pays back the ticket once (cash half of the cash part, voucher half of the voucher)', ! is_wp_error( $m_rf ) && 14999 === (int) $m_applied['applied_minor'] && 3001 === $m_due && 1501 === (int) $m_rf['amount_minor'] && 7499 === $m_v && 9000 >= (int) $m_rf['amount_minor'] + $m_v );
$m_full = Refunds::request( $m_oid, array(), 'Rest', 'y3-m-r2-' . $m_sfx );
Y3Vouchers::refund( (int) $m_full['refund_id'], $m_oid );
$m_pid = Y3Partners::save( array( 'name' => 'Y3 multi', 'currency' => 'EUR', 'commission_bp' => 1000, 'settlement_start' => $y3_month . '-01' ) );
$m_pid = is_array( $m_pid ) ? (int) $m_pid['partner_id'] : (int) $m_pid;
Y3Partners::set_events( $m_pid, array( $m_ev ) );
$m_tt = Y3Settlements::compute( Y3Partners::get( $m_pid ), $y3_month )['totals'];
check( 'fully refunded multi-purpose voucher order settles to zero (refunds include the voucher part)', 18000 === (int) $m_tt['sales_gross'] && 18000 === (int) $m_tt['refunded'] && 0 === (int) $m_tt['gross'] && 0 === (int) $m_tt['payout'] );

echo "PAY-9: cheaper rebooking after a dearer one refunds both orders\n";
$y3_providers_before = Settings::get( 'enabled_providers' );
Settings::update( array( 'enabled_providers' => array( 'manual', 'svc_supp' ) ) );
list( $r9_event, $r9_type ) = svc_event( 3000 );
$r9_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$r9_type" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $r9_occ, 'name' => 'Premium', 'price_minor' => 5000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$r9_dear_type = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $r9_occ, 'name' => 'Basis', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$r9_cheap_type = (int) $wpdb->insert_id;
$r9_res = svc_resource( $r9_event, array( 'ticket_type_id' => $r9_type, 'capacity' => 4, 'rules' => array( 'price_policy' => 'refund_difference' ) ) );
$r9_dear = svc_resource( $r9_event, array( 'ticket_type_id' => $r9_dear_type, 'capacity' => 4, 'rules' => array( 'price_policy' => 'refund_difference' ) ) );
$r9_cheap = svc_resource( $r9_event, array( 'ticket_type_id' => $r9_cheap_type, 'capacity' => 4 ) );
list( $r9b, $r9o ) = svc_paid( svc_slot( $r9_res, 400 ), $r9_type, 'y3-rebook@svc.test', 1 );
$r9_up = PassCaptain\ServiceBookings::rebook( $r9b, svc_slot( $r9_dear, 410 ), array( 'actor' => 'customer', 'idempotency_key' => 'y3-up', 'provider' => 'svc_supp' ) );
$r9_supp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_service_bookings WHERE supplement_for=%d ORDER BY booking_id DESC LIMIT 1", $r9b ) );
Orders::confirm_external_payment( (int) $r9_supp->order_id, 'y3-supp-pay' );
$r9_moved = PassCaptain\ServiceBookings::get( $r9b );
$r9_down = PassCaptain\ServiceBookings::rebook( $r9b, svc_slot( $r9_cheap, 420 ), array( 'actor' => 'admin', 'idempotency_key' => 'y3-down' ) );
$r9_after = PassCaptain\ServiceBookings::get( $r9b );
check( 'after upgrade (30 € + 20 €) a move to 10 € refunds 40 €: 20 € from the supplement and 20 € from the first order', ! is_wp_error( $r9_up ) && 3000 === (int) $r9_moved->origin_minor && 5000 === (int) $r9_moved->amount_minor && ! is_wp_error( $r9_down ) && 4000 === (int) $r9_down['difference_minor'] && 2000 === (int) Orders::get( (int) $r9_supp->order_id )->refunded_minor && 2000 === (int) Orders::get( $r9o )->refunded_minor && 1000 === (int) $r9_after->amount_minor && 1000 === (int) $r9_after->origin_minor );
$r9_cancel = PassCaptain\ServiceBookings::cancel( $r9b, array( 'actor' => 'admin' ) );
check( 'a later cancellation refunds only what is left of the first payment (no double refund)', ! is_wp_error( $r9_cancel ) && 3000 === (int) Orders::get( $r9o )->refunded_minor && 2000 === (int) Orders::get( (int) $r9_supp->order_id )->refunded_minor );
Settings::update( array( 'enabled_providers' => $y3_providers_before ) );

echo "Z2: settlement refunds are split by discounted line values (two organizers in one order)\n";
// Organizer 1 sells A (100.00, campaign −50.00 on A only), organizer 2 sells B (50.00): both received
// 50.00. A full refund must take 50.00 back from each, not 66.67/33.33 by list price.
$z2s_a = make_type( 50, 10, 10000 );
$z2s_b = make_type( 50, 10, 5000 );
$z2s_ev = static function ( $type ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type ); };
$z2s_sfx = strtoupper( substr( md5( microtime() ), 0, 8 ) );
Campaigns::create( array( 'code' => 'ZA' . $z2s_sfx, 'kind' => 'fixed', 'value' => 5000, 'currency' => 'EUR', 'priority' => 1, 'ticket_type_id' => $z2s_a ) );
$z2s_r = Orders::create( array( 'email' => 'z2s@example.com', 'items' => array( array( 'ticket_type_id' => $z2s_a, 'quantity' => 1 ), array( 'ticket_type_id' => $z2s_b, 'quantity' => 1 ) ), 'campaign_codes' => array( 'ZA' . $z2s_sfx ), 'idempotency_key' => 'z2s-' . $z2s_sfx ), array( 'skip_rate_limit' => true ) );
$z2s_oid = (int) $z2s_r['order_id'];
Orders::confirm_manual_payment( $z2s_oid );
$z2s_full = Refunds::request( $z2s_oid, array(), 'Alles', 'z2s-full-' . $z2s_sfx );
$z2s_tt = array();
foreach ( array( 'a' => $z2s_a, 'b' => $z2s_b ) as $z2s_k => $z2s_type ) {
	$z2s_pid = Y3Partners::save( array( 'name' => 'Z2 ' . $z2s_k . $z2s_sfx, 'currency' => 'EUR', 'commission_bp' => 0, 'settlement_start' => $y3_month . '-01' ) );
	$z2s_pid = is_array( $z2s_pid ) ? (int) $z2s_pid['partner_id'] : (int) $z2s_pid;
	Y3Partners::set_events( $z2s_pid, array( $z2s_ev( $z2s_type ) ) );
	$z2s_tt[ $z2s_k ] = Y3Settlements::compute( Y3Partners::get( $z2s_pid ), $y3_month )['totals'];
}
check( 'two organizers, campaign on one line: each settlement takes back exactly what it sold (5000/5000, net 0)', ! is_wp_error( $z2s_full ) && 10000 === (int) Orders::get( $z2s_oid )->total_minor && 5000 === (int) $z2s_tt['a']['sales_gross'] && 5000 === (int) $z2s_tt['a']['refunded'] && 0 === (int) $z2s_tt['a']['gross'] && 5000 === (int) $z2s_tt['b']['sales_gross'] && 5000 === (int) $z2s_tt['b']['refunded'] && 0 === (int) $z2s_tt['b']['gross'] );
$z2s_lines = array( (object) array( 'ticket_type_id' => 1, 'quantity' => 1, 'line_total_minor' => 10000 ), (object) array( 'ticket_type_id' => 2, 'quantity' => 1, 'line_total_minor' => 5000 ) );
$z2s_amount_only = (object) array( 'ticket_ids' => '[]', 'amount_minor' => 5000, 'tax_minor' => 0 );
$z2s_new = Y3Settlements::allocate_refund( $z2s_amount_only, $z2s_lines, array( 5000, 5000 ) );
$z2s_legacy = Y3Settlements::allocate_refund( $z2s_amount_only, $z2s_lines );
$z2s_zero = Y3Settlements::allocate_refund( $z2s_amount_only, $z2s_lines, array( 0, 0 ) );
check( 'allocate_refund: amount-only refunds by discounted line value; legacy list-price split without values; fully discounted → list price', 2500 === $z2s_new[0]['amount'] && 2500 === $z2s_new[1]['amount'] && 3333 === $z2s_legacy[0]['amount'] && 1667 === $z2s_legacy[1]['amount'] && $z2s_legacy === $z2s_zero );

echo "R7: automatic group discount (min_quantity, auto_apply) + code + per-buyer limit keep the money invariants\n";
list( $r7m_ev, $r7m_a, $r7m_b ) = y3_event( 'EUR', 2000, 900 );
$r7m_sfx = strtoupper( substr( md5( microtime() ), 0, 6 ) );
$r7m_auto = Campaigns::create( array( 'code' => 'R7AUTO' . $r7m_sfx, 'kind' => 'percent', 'value' => 1500, 'currency' => 'EUR', 'priority' => 1, 'event_id' => $r7m_ev, 'auto_apply' => 1, 'min_quantity' => 4 ) );
Campaigns::create( array( 'code' => 'R7FIX' . $r7m_sfx, 'kind' => 'fixed', 'value' => 777, 'currency' => 'EUR', 'priority' => 2, 'event_id' => $r7m_ev, 'per_buyer_limit' => 1 ) );
$r7m_items = array( array( 'ticket_type_id' => $r7m_a, 'quantity' => 3 ), array( 'ticket_type_id' => $r7m_b, 'quantity' => 1 ) );
$r7m_small = Orders::create( array( 'email' => 'r7m-small@example.com', 'items' => array( array( 'ticket_type_id' => $r7m_a, 'quantity' => 3 ) ), 'idempotency_key' => 'r7m-s-' . $r7m_sfx ), array( 'skip_rate_limit' => true ) );
check( 'auto group discount does not apply below the minimum quantity (3 < 4)', ! is_wp_error( $r7m_small ) && 0 === (int) $r7m_small['discount_minor'] && 6000 === (int) $r7m_small['total_minor'] );
$r7m = Orders::create( array( 'email' => 'r7m@example.com', 'items' => $r7m_items, 'campaign_codes' => array( 'R7FIX' . $r7m_sfx ), 'idempotency_key' => 'r7m-' . $r7m_sfx ), array( 'skip_rate_limit' => true ) );
$r7m_o = is_wp_error( $r7m ) ? null : Orders::get( $r7m['order_id'] );
$r7m_items_rows = $r7m_o ? Orders::items_for_order( $r7m_o->order_id ) : array();
$r7m_expected = (int) floor( 6900 * 1500 / 10000 ) + 777;
check( 'auto 15 % (4 tickets) + fixed code: discount ' . $r7m_expected . ', Σ line discounts = discount, Σ line VAT = order VAT, total = subtotal − discount',
	$r7m_o && $r7m_expected === (int) $r7m_o->discount_minor && y3_line_discount_sum( $r7m_o ) === (int) $r7m_o->discount_minor
	&& array_sum( array_map( static fn( $i ) => (int) $i->tax_minor, $r7m_items_rows ) ) === (int) $r7m_o->tax_minor
	&& 6900 - $r7m_expected === (int) $r7m_o->total_minor && 2 === count( (array) json_decode( (string) $r7m_o->price_snapshot, true )['campaigns'] ) );
check( 'per-buyer limit 1: the same buyer cannot use the code a second time', ! is_wp_error( $r7m ) && is_wp_error( Orders::create( array( 'email' => 'r7m@example.com', 'items' => $r7m_items, 'campaign_codes' => array( 'R7FIX' . $r7m_sfx ), 'idempotency_key' => 'r7m2-' . $r7m_sfx ), array( 'skip_rate_limit' => true ) ) ) );
if ( $r7m_o ) {
	Orders::confirm_manual_payment( $r7m_o->order_id );
	$r7m_cash = (int) Orders::get( $r7m_o->order_id )->total_minor;
	$r7m_t = array_values( array_filter( Orders::tickets_for_order( $r7m_o->order_id ), static fn( $t ) => (int) $t->ticket_type_id === $r7m_a ) );
	$r7m_r1 = Refunds::request( $r7m_o->order_id, array( (int) $r7m_t[0]->ticket_id ), 'Teil', 'r7m-r1-' . $r7m_sfx );
	$r7m_r2 = Refunds::request( $r7m_o->order_id, array(), 'Rest', 'r7m-r2-' . $r7m_sfx );
	check( 'partial + full refund of the auto-discounted order give back exactly the cash paid', ! is_wp_error( $r7m_r1 ) && ! is_wp_error( $r7m_r2 ) && (int) $r7m_r1['amount_minor'] + (int) $r7m_r2['amount_minor'] === $r7m_cash );
}
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_campaigns SET status='inactive' WHERE code IN (%s,%s)", 'R7AUTO' . $r7m_sfx, 'R7FIX' . $r7m_sfx ) );

Settings::update( $y3_settings_before );
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $y3_provider_before;
