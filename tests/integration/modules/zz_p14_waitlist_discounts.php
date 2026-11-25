<?php
/**
 * Owner decision 25.11.2026 ("Rabatte gelten"): a booking through a waitlist offer is treated like a
 * normal booking – discount codes and vouchers can be redeemed on acceptance (Waitlists\Discounts):
 * price, VAT, invoice, refund and voucher redemption; buyer limits stay and the offer's capacity stays
 * held under the claim's locks; a failed acceptance opens the offer again at its offer price.
 * Included from run.php (uses check(), make_type(), tickets()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Campaigns;
use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout as VoucherCheckout;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Settings;
use PassCaptain\Waitlists;
use PassCaptain\Waitlists\Discounts;

global $wpdb;
$p = $wpdb->prefix;

echo "Owner decision 25.11.2026: discounts on waitlist offers\n";

final class P14_Voucher_Provider implements PassCaptain\Integrations\Vouchers\ProviderInterface {
	public array $calls = array();
	public function quote( array $context ) {
		$this->calls[] = array( 'quote', $context['code'], (int) $context['amount_minor'] );
		if ( 0 === strpos( $context['code'], 'BAD' ) ) { return new WP_Error( 'vcap_voucher_invalid', 'Gutschein ungültig' ); }
		$cap = 0 === strpos( $context['code'], 'FULL' ) ? PHP_INT_MAX : 1500;
		return array( 'amount_minor' => min( $cap, (int) $context['amount_minor'] ), 'currency' => $context['currency'] );
	}
	public function reserve( array $quote, $key ) { $this->calls[] = array( 'reserve', $key ); return array( 'reservation' => 'r-' . $key, 'amount_minor' => $quote['amount_minor'] ); }
	public function commit( array $reservation, $key ) { $this->calls[] = array( 'commit', $key ); return array( 'redemption' => 'd-' . $key ); }
	public function release( array $reservation, $key ) { $this->calls[] = array( 'release', $key ); return true; }
	public function refund( array $redemption, $amount, $key ) { $this->calls[] = array( 'refund', $amount, $key ); return true; }
}

Waitlists::register();
PassCaptain\Modules\Allocations::register();
$p14_saved = get_option( Settings::OPTION, array() );
$p14_provider_before = $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ?? null;
$p14_vp = new P14_Voucher_Provider();
$GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $p14_vp;
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Testverein', 'require_terms' => 0, 'billing_required_threshold_minor' => 0, 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'voucher_default_purpose' => 'multi', 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0, 'double_opt_in' => 0 ) ) );
$p14_delay = $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
$p14_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
$GLOBALS['wpcap_test_user_id'] = 0;
update_option( Waitlists::QUEUE_OPTION, array() );

/** A sold-out type of $qty units at $price (20 % VAT) and an open offer of all $qty units to $email. */
$p14_offer = static function ( $price, $qty, $email, $setup = null ) use ( $wpdb, $p ) {
	$type = make_type( $qty, 10, $price );
	$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=2000 WHERE ticket_type_id=" . (int) $type );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	if ( $setup ) { $setup( $event, $type ); }
	$GLOBALS['wpcap_test_transients'] = array();
	$sold = Orders::create( array( 'email' => 'p14-first-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'p14-' . uniqid( '', true ) ) );
	$_SERVER['REMOTE_ADDR'] = '10.78.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	$GLOBALS['wpcap_test_transients'] = array();
	Waitlists::join( $event, $occ, $type, $email, $qty );
	if ( ! is_wp_error( $sold ) ) { Orders::cancel_order( $sold['order_id'], 'test' ); }
	Waitlists::run_due( true );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $type, $email ) );
	$token = '';
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { $token = $m[1]; break; }
	}
	return array( $row, $token, $type, $event );
};
$p14_status = static function ( $row ) use ( $wpdb, $p ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_waitlist WHERE waitlist_id=%d", (int) $row->waitlist_id ) );
};
$p14_uses = static function ( $code ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT used_count,(SELECT COUNT(*) FROM {$p}wpcap_campaign_uses u WHERE u.campaign_id=c.campaign_id AND u.status IN ('reserved','committed')) AS live FROM {$p}wpcap_campaigns c WHERE code=%s", $code ) );
};
$p14_held = static function ( $order_id ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s", $order_id, gmdate( 'Y-m-d H:i:s' ) ) );
};

Campaigns::create( array( 'code' => 'P14TEN', 'kind' => 'percent', 'value' => 1000, 'currency' => 'EUR' ) );
Campaigns::create( array( 'code' => 'P14ALL', 'kind' => 'percent', 'value' => 10000, 'currency' => 'EUR' ) );
Campaigns::create( array( 'code' => 'P14ONCE', 'kind' => 'fixed', 'value' => 500, 'currency' => 'EUR', 'per_buyer_limit' => 1 ) );

// --- Discount code: price, VAT, capacity under lock, payment, invoice, refund ----------------------
list( $c_row, $c_tok, $c_type ) = $p14_offer( 5000, 2, 'p14-code@example.com' );
check( 'setup: a paid offer of 2 x 50.00 (20 % VAT) holds the last two units', $c_row && 'offered' === $c_row->status && 64 === strlen( $c_tok ) && 2 === $p14_held( (int) $c_row->order_id ) && 0 === Orders::availability( $c_type ) );
$c_order = Orders::get( (int) $c_row->order_id );
check( 'the offer page asks for a voucher or discount code on paid offers', false !== strpos( Discounts::form_html( $c_order ), 'name="code"' ) && false !== strpos( Discounts::form_html( $c_order ), 'Gutschein- oder Rabattcode' ) );
$c_bad = Waitlists::accept_offer( (int) $c_row->waitlist_id, $c_tok, array( 'provider' => 'manual', 'campaign_codes' => array( 'P14NOPE' ) ) );
$c_after_bad = Orders::get( (int) $c_row->order_id );
check( 'an unknown code is refused with the code field, the offer stays open at its price with its hold', is_wp_error( $c_bad ) && 'code' === ( $c_bad->get_error_data()['field'] ?? '' ) && 'offered' === $p14_status( $c_row ) && 10000 === (int) $c_after_bad->total_minor && 2 === $p14_held( (int) $c_row->order_id ) );
$c_ok = Waitlists::accept_offer( (int) $c_row->waitlist_id, $c_tok, array( 'provider' => 'manual', 'code' => ' p14ten ' ) );
$c_o = Orders::get( (int) $c_row->order_id );
$c_item = $wpdb->get_row( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=" . (int) $c_row->order_id );
$c_snap = json_decode( (string) $c_o->price_snapshot, true );
check( 'discount code on acceptance: 10 % off, amount due 90.00, accepted with bank transfer', ! is_wp_error( $c_ok ) && 'accepted' === $c_ok['status'] && 9000 === (int) $c_ok['order']['total_minor'] && 1000 === (int) $c_ok['order']['discount_minor'] && 9000 === (int) $c_o->total_minor && 1000 === (int) $c_o->discount_minor && 10000 === (int) $c_o->subtotal_minor );
check( 'discount code: VAT on the discounted amount, line discount and price snapshot like the checkout', 1500 === (int) $c_o->tax_minor && 1500 === (int) $c_item->tax_minor && ( ! property_exists( $c_item, 'discount_minor' ) || 1000 === (int) $c_item->discount_minor ) && 'P14TEN' === ( $c_snap['campaigns'][0]['code'] ?? '' ) && 1000 === (int) ( $c_snap['line_discounts'][ $c_type ] ?? 0 ) && 9000 === (int) $c_snap['total_minor'] );
$c_use = $p14_uses( 'P14TEN' );
check( 'discount code: one use reserved for the order', 1 === (int) $c_use->used_count && 1 === (int) $c_use->live && 'reserved' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_campaign_uses WHERE order_id=" . (int) $c_row->order_id ) );
check( 'capacity: the accepted offer keeps holding its two units, nothing is free for others', 2 === $p14_held( (int) $c_row->order_id ) && 0 === Orders::availability( $c_type ) );
$c_other = Orders::create( array( 'email' => 'p14-other@example.com', 'items' => array( array( 'ticket_type_id' => $c_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p14-other-' . uniqid() ) );
check( 'capacity: a parallel checkout for the type is refused (no oversell)', is_wp_error( $c_other ) );
$c_again = Waitlists::accept_offer( (int) $c_row->waitlist_id, $c_tok, array( 'provider' => 'manual', 'code' => 'P14TEN' ) );
check( 'a second acceptance of the same offer is refused and does not redeem the code twice', is_wp_error( $c_again ) && 1 === (int) $p14_uses( 'P14TEN' )->used_count );
Orders::confirm_manual_payment( (int) $c_row->order_id );
$c_paid = Orders::get( (int) $c_row->order_id );
check( 'discount code: payment of 90.00 books the order, the use is committed, two tickets issued', 'paid' === $c_paid->payment_status && 'committed' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_campaign_uses WHERE order_id=" . (int) $c_row->order_id ) && 2 === count( tickets( (int) $c_row->order_id, 'valid' ) ) && 'paid' === $p14_status( $c_row ) );
$c_inv = Invoices::issue_for_order( (int) $c_row->order_id );
$c_inv_row = is_wp_error( $c_inv ) ? null : $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $c_inv['invoice_id'] );
check( 'discount code: the invoice shows 90.00 with 15.00 VAT', $c_inv_row && 9000 === (int) $c_inv_row->total_minor && 1500 === (int) $c_inv_row->tax_minor );
$c_tickets = tickets( (int) $c_row->order_id );
$c_ref = Refunds::request( (int) $c_row->order_id, array( (int) $c_tickets[0]->ticket_id ), 'Storno', 'p14-c-r1' );
check( 'discount code: refunding one ticket refunds the discounted price 45.00', ! is_wp_error( $c_ref ) && 4500 === (int) $c_ref['amount_minor'] );

// --- Buyer limits stay -------------------------------------------------------------------------------
list( $b_row, $b_tok, $b_type, $b_event ) = $p14_offer( 3000, 2, 'p14-limit@example.com', static function ( $event ) { BuyerLimits::set( $event, 0, 2 ); BuyerLimits::flush(); } );
Orders::create( array( 'email' => 'p14-limit@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 1000 ), 'quantity' => 1 ) ), 'idempotency_key' => 'p14-once-' . uniqid(), 'campaign_codes' => array( 'P14ONCE' ) ) );
$b_once = Waitlists::accept_offer( (int) $b_row->waitlist_id, $b_tok, array( 'provider' => 'manual', 'code' => 'P14ONCE' ) );
check( 'buyer limit of a code: already redeemed by this e-mail address elsewhere – refused, the offer stays open', is_wp_error( $b_once ) && 'wpcap_campaign_buyer_limit' === $b_once->get_error_code() && 'offered' === $p14_status( $b_row ) && 6000 === (int) Orders::get( (int) $b_row->order_id )->total_minor );
$b_ok = Waitlists::accept_offer( (int) $b_row->waitlist_id, $b_tok, array( 'provider' => 'manual', 'code' => 'P14TEN' ) );
check( 'buyer limit: the offer within the per-person maximum is accepted with a code', ! is_wp_error( $b_ok ) && 5400 === (int) $b_ok['order']['total_minor'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET capacity=capacity+5 WHERE ticket_type_id=%d", $b_type ) );
$b_more = Orders::create( array( 'email' => 'p14-limit@example.com', 'items' => array( array( 'ticket_type_id' => $b_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p14-more-' . uniqid(), 'campaign_codes' => array( 'P14TEN' ) ) );
check( 'buyer limit: the discounted waitlist order counts against the per-person maximum (a third ticket is refused)', is_wp_error( $b_more ) );
BuyerLimits::set( $b_event, 0, 0 );
BuyerLimits::flush();

// --- Voucher (multi-purpose): amount due, redemption, invoice payments, refund share -------------------
list( $v_row, $v_tok, $v_type ) = $p14_offer( 2000, 3, 'p14-voucher@example.com' );
$v_calls = count( $p14_vp->calls );
$v_ok = Waitlists::accept_offer( (int) $v_row->waitlist_id, $v_tok, array( 'provider' => 'manual', 'code' => 'GIFT-AAAA-1234' ) );
$v_o = Orders::get( (int) $v_row->order_id );
$v_state = VoucherCheckout::voucher_state( (int) $v_row->order_id );
check( 'voucher on acceptance: quote and reservation at the voucher plugin, amount due 60.00 - 15.00', ! is_wp_error( $v_ok ) && 'accepted' === $v_ok['status'] && 4500 === (int) $v_o->total_minor && 'reserved' === ( $v_state['status'] ?? '' ) && 1500 === (int) $v_state['amount_paid_by_voucher'] && 'quote' === $p14_vp->calls[ $v_calls ][0] && 6000 === $p14_vp->calls[ $v_calls ][2] );
check( 'voucher: multi-purpose keeps the full VAT on the lines (means of payment)', 1000 === (int) $v_o->tax_minor && 0 === (int) $v_o->discount_minor );
Orders::confirm_manual_payment( (int) $v_row->order_id );
VoucherCheckout::commit( (int) $v_row->order_id );
check( 'voucher: payment of the rest books the order and commits the voucher', 'paid' === Orders::get( (int) $v_row->order_id )->payment_status && 'committed' === VoucherCheckout::voucher_state( (int) $v_row->order_id )['status'] && 3 === count( tickets( (int) $v_row->order_id, 'valid' ) ) );
$v_inv = Invoices::issue_for_order( (int) $v_row->order_id );
$v_inv_row = is_wp_error( $v_inv ) ? null : $wpdb->get_row( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $v_inv['invoice_id'] );
$v_sum = $v_inv_row ? Invoices::payment_summary( $v_inv_row, (array) json_decode( $v_inv_row->buyer_snapshot, true ) ) : array( 'rows' => array() );
check( 'voucher: the invoice shows the full 60.00 and lists the voucher under payments', $v_inv_row && 6000 === (int) $v_inv_row->total_minor && 2 === count( $v_sum['rows'] ) && -1500 === $v_sum['rows'][0][1] && 4500 === $v_sum['rows'][1][1] );
$v_tickets = tickets( (int) $v_row->order_id );
$v_ref = Refunds::request( (int) $v_row->order_id, array( (int) $v_tickets[0]->ticket_id ), 'Storno', 'p14-v-r1' );
VoucherCheckout::refund( $v_ref['refund_id'], (int) $v_row->order_id );
$v_refund_calls = array_values( array_filter( $p14_vp->calls, static fn( $c ) => 'refund' === $c[0] ) );
check( 'voucher: refunding one ticket pays back the cash share and returns the voucher share', 1500 === (int) $v_ref['amount_minor'] && 1 === count( $v_refund_calls ) && 500 === $v_refund_calls[0][1] );

// --- Code and voucher together; a refused voucher restores the offer price ---------------------------
list( $r_row, $r_tok ) = $p14_offer( 5000, 1, 'p14-restore@example.com' );
$r_releases = count( array_filter( $p14_vp->calls, static fn( $c ) => 'release' === $c[0] ) );
$r_bad = Waitlists::accept_offer( (int) $r_row->waitlist_id, $r_tok, array( 'provider' => 'manual', 'campaign_codes' => array( 'P14TEN' ), 'voucher_code' => 'BAD-0001' ) );
$r_o = Orders::get( (int) $r_row->order_id );
check( 'refused voucher: error on the code field, the offer is open again with its hold', is_wp_error( $r_bad ) && 'code' === ( $r_bad->get_error_data()['field'] ?? '' ) && 'offered' === $p14_status( $r_row ) && 1 === $p14_held( (int) $r_row->order_id ) && 'waitlist_offer' === $r_o->payment_provider );
check( 'refused voucher: back to the offer price – discount code released, lines and VAT restored', 5000 === (int) $r_o->total_minor && 0 === (int) $r_o->discount_minor && 833 === (int) $r_o->tax_minor && '' === (string) $r_o->voucher_snapshot && false === strpos( (string) $r_o->price_snapshot, Discounts::SNAPSHOT_KEY ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_campaign_uses WHERE order_id=" . (int) $r_row->order_id . " AND status IN ('reserved','committed')" ) );
$r_ok = Waitlists::accept_offer( (int) $r_row->waitlist_id, $r_tok, array( 'provider' => 'manual', 'campaign_codes' => array( 'P14TEN' ), 'voucher_code' => 'GIFT-BBBB-5678' ) );
$r_o = Orders::get( (int) $r_row->order_id );
check( 'code and voucher together: 50.00 - 10 % = 45.00, voucher 15.00, amount due 30.00 (voucher quoted on 45.00)', ! is_wp_error( $r_ok ) && 3000 === (int) $r_o->total_minor && 500 === (int) $r_o->discount_minor && 750 === (int) $r_o->tax_minor && 'reserved' === VoucherCheckout::voucher_state( (int) $r_row->order_id )['status'] );

// --- Voucher or code covering everything: booked at once like a free offer ---------------------------
list( $f_row, $f_tok ) = $p14_offer( 4000, 1, 'p14-full@example.com' );
$f_ok = Waitlists::accept_offer( (int) $f_row->waitlist_id, $f_tok, array( 'provider' => 'manual', 'code' => 'FULL-0001' ) );
VoucherCheckout::commit( (int) $f_row->order_id ); // wpcap_order_paid hook in the plugin.
check( 'voucher covering everything: booked at once, ticket issued, voucher committed', ! is_wp_error( $f_ok ) && 'paid' === $f_ok['status'] && 'paid' === Orders::get( (int) $f_row->order_id )->payment_status && 1 === count( tickets( (int) $f_row->order_id, 'valid' ) ) && 'paid' === $p14_status( $f_row ) && 'committed' === VoucherCheckout::voucher_state( (int) $f_row->order_id )['status'] );
list( $a_row, $a_tok ) = $p14_offer( 4000, 1, 'p14-all@example.com' );
$a_ok = Waitlists::accept_offer( (int) $a_row->waitlist_id, $a_tok, array( 'code' => 'P14ALL' ) );
check( '100 % discount code: booked at once without a payment method, the use committed', ! is_wp_error( $a_ok ) && 'paid' === $a_ok['status'] && 0 === (int) Orders::get( (int) $a_row->order_id )->total_minor && 1 === count( tickets( (int) $a_row->order_id, 'valid' ) ) && 'committed' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_campaign_uses WHERE order_id=" . (int) $a_row->order_id ) );

// --- REST and free offers ------------------------------------------------------------------------------
list( $rest_row, $rest_tok ) = $p14_offer( 1000, 1, 'p14-rest@example.com' );
$rest_req = new WP_REST_Request( 'POST' );
$rest_req->set_param( 'waitlist_id', (int) $rest_row->waitlist_id );
$rest_req->set_param( 'token', $rest_tok );
$rest_req->set_param( 'provider', 'manual' );
$rest_req->set_param( 'code', 'P14TEN' );
$rest_res = PassCaptain\Rest\Controller::accept_waitlist_offer( $rest_req );
$rest_data = is_wp_error( $rest_res ) ? array() : $rest_res->get_data();
check( 'REST POST /waitlist/{id}/accept takes the code like the checkout', 900 === (int) ( $rest_data['order']['total_minor'] ?? 0 ) && 100 === (int) ( $rest_data['order']['discount_minor'] ?? 0 ) );
list( $z_row ) = $p14_offer( 0, 1, 'p14-free@example.com' );
check( 'free offers show no code field', $z_row && '' === Discounts::form_html( Orders::get( (int) $z_row->order_id ) ) );

// --- p16 finding: the entry quantity is capped by max_line_quantity as well as max_per_order ----------------
echo "Waitlist quantity: max_line_quantity caps max_per_order\n";
Settings::update( array( 'max_line_quantity' => 3 ) );
$q_type = make_type( 0, 10, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET capacity=1 WHERE ticket_type_id=$q_type" );
$q_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$q_type" );
$q_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$q_occ" );
check( 'waitlist max quantity: min(max_per_order 10, max_line_quantity 3) = 3', 3 === Waitlists::max_quantity( (object) array( 'max_per_order' => 10 ) ) && 2 === Waitlists::max_quantity( (object) array( 'max_per_order' => 2 ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$q_four = Waitlists::join( $q_event, $q_occ, $q_type, 'p14-q4@example.com', 4 );
check( 'waitlist sign-up over max_line_quantity is refused with the lower maximum in the message', is_wp_error( $q_four ) && 'wpcap_waitlist_quantity' === $q_four->get_error_code() && false !== strpos( $q_four->get_error_message(), '3' ) );
$GLOBALS['wpcap_test_transients'] = array();
$q_three = Waitlists::join( $q_event, $q_occ, $q_type, 'p14-q3@example.com', 3 );
check( 'waitlist sign-up at max_line_quantity is accepted', ! is_wp_error( $q_three ) );
// Entry signed up before the line limit was lowered: blocked as quantity_limit instead of an offer the checkout refuses.
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET capacity=10 WHERE ticket_type_id=$q_type" );
Settings::update( array( 'max_line_quantity' => 2 ) );
Waitlists::process_type( $q_type, true );
$q_entry = $wpdb->get_row( $wpdb->prepare( "SELECT status,block_reason FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $q_type, 'p14-q3@example.com' ) );
check( 'an entry above a lowered max_line_quantity is blocked (quantity_limit), no offer is made', $q_entry && 'blocked' === $q_entry->status && 'quantity_limit' === $q_entry->block_reason );

update_option( Settings::OPTION, $p14_saved );
Settings::flush();
if ( null === $p14_provider_before ) { unset( $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] ); } else { $GLOBALS['wpcap_test_filters'][ Bridge::FILTER ] = $p14_provider_before; }
if ( null === $p14_delay ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = $p14_delay; }
$GLOBALS['wpcap_test_user_id'] = $p14_user;
update_option( Waitlists::QUEUE_OPTION, array() );
