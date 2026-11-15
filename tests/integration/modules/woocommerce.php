<?php
/**
 * WooCommerce adapter: participant fields, cash on delivery, Store API quantity limits and
 * refund accounting. Included from run.php (WooCommerce itself is replaced by the fakes in
 * bootstrap.php plus the subclasses below).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Integrations\WooCommerce\Attendees;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

global $wpdb;
$p = $wpdb->prefix;

class Wpcap_Test_Woo_Order extends WC_Order {
	public string $payment_method = '';
	public string $status = 'pending';
	public string $total = '0';
	public array $refund_list = array();
	public function get_payment_method() { return $this->payment_method; }
	public function get_status() { return $this->status; }
	public function get_total() { return $this->total; }
	public function get_total_refunded() { return array_sum( array_map( static fn( $r ) => (float) $r->amount, $this->refund_list ) ); }
	public function get_refunds() { return array_reverse( $this->refund_list ); } // newest first, like WooCommerce
}
class Wpcap_Test_Woo_Refund extends WC_Order {
	public string $amount = '0';
	public string $reason = '';
	public function get_amount() { return $this->amount; }
	public function get_reason() { return $this->reason; }
}

function woo_t_order( $id, array $lines, $method = '' ) {
	static $product_id = 8800, $item_id = 8900;
	$order = new Wpcap_Test_Woo_Order( $id );
	$order->payment_method = $method;
	$total = 0;
	foreach ( $lines as list( $type_id, $qty, $line_total ) ) {
		$product = new WC_Product( ++$product_id );
		$product->update_meta_data( Woo::PRODUCT_META, $type_id );
		$GLOBALS['wpcap_test_products'][ $product_id ] = $product;
		$order->items[] = new WC_Order_Item_Product( ++$item_id, $product, $qty, $line_total );
		$total += (float) $line_total;
	}
	$order->total = (string) $total;
	return $order;
}

function woo_t_refund( $id, Wpcap_Test_Woo_Order $order, $amount, array $lines ) {
	static $item_id = 9500;
	$refund = new Wpcap_Test_Woo_Refund( $id );
	$refund->amount = (string) $amount;
	foreach ( $lines as list( $line_index, $qty, $line_total ) ) {
		$original = $order->items[ $line_index ];
		$item = new WC_Order_Item_Product( ++$item_id, $original->get_product(), -$qty, (string) -$line_total );
		$item->update_meta_data( '_refunded_item_id', $original->get_id() );
		$refund->items[] = $item;
	}
	$order->refund_list[] = $refund;
	return $refund;
}

function woo_t_field( $type_id, $key, $label, $type = 'text', $required = 1 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_participant_fields", array( 'ticket_type_id' => $type_id, 'field_key' => $key, 'label' => $label, 'field_type' => $type, 'required' => $required, 'purpose' => 'Namensschild', 'status' => 'active' ) );
}

echo "WooCommerce: participant fields in the checkout\n";
$type = make_type( 10 );
woo_t_field( $type, 'firma', 'Firma' );
woo_t_field( $type, 'diet', 'Ernährung', 'text', 0 );
$plain = make_type( 10 );
$req = Attendees::requirements( array( $type => 2, $plain => 1 ) );
check( 'only ticket types with participant fields need attendee data', 1 === count( $req ) && $type === $req[0]['ticket_type_id'] && 2 === $req[0]['quantity'] && 'firma' === $req[0]['fields'][0]['key'] && $req[0]['fields'][0]['required'] && ! $req[0]['fields'][1]['required'] );
$missing = Attendees::normalize( array( $type => array( array( 'answers' => array( 'firma' => 'ACME' ) ) ) ), array( $type => 2 ) );
check( 'missing required answer for the second ticket is rejected with the field label', is_wp_error( $missing ) && false !== strpos( $missing->get_error_message(), 'Firma' ) );
$raw = array(
	$type  => array(
		array( 'name' => 'Ada <b>L</b>', 'email' => 'ada@example.com', 'answers' => array( 'firma' => ' ACME ', 'evil' => 'x' ) ),
		array( 'answers' => array( 'firma' => 'Globex', 'diet' => 'vegan' ) ),
		array( 'answers' => array( 'firma' => 'surplus' ) ),
	),
	$plain => array( array( 'name' => 'ignored' ) ),
);
$ok = Attendees::normalize( $raw, array( $type => 2, $plain => 1 ) );
check( 'valid answers are normalised (trimmed, unknown keys/types/surplus dropped)', is_array( $ok ) && 2 === count( $ok ) && 'ACME' === $ok[0]['answers']['firma'] && ! isset( $ok[0]['answers']['evil'] ) && 'Ada L' === $ok[0]['name'] && 'vegan' === $ok[1]['answers']['diet'] );
check( 'JSON input from the checkout block is accepted', is_array( Attendees::normalize( wp_json_encode( $raw ), array( $type => 2 ) ) ) );
$bad_email = Attendees::normalize( array( $type => array( array( 'email' => 'nope', 'answers' => array( 'firma' => 'A' ) ), array( 'answers' => array( 'firma' => 'B' ) ) ) ), array( $type => 2 ) );
check( 'invalid attendee e-mail is rejected', is_wp_error( $bad_email ) );
check( 'types without fields need no input', array() === Attendees::normalize( array(), array( $plain => 3 ) ) );

$wc = woo_t_order( 7101, array( array( $type, 2, '60.00' ) ) );
$fail = Woo::reserve( $wc );
check( 'without attendee data the hold fails with the field message (as reported in B4)', is_wp_error( $fail ) && false !== strpos( $fail->get_error_message(), 'Firma' ) );
$wc->update_meta_data( Woo::ATTENDEE_META, $ok );
$core = Woo::reserve( $wc );
check( 'attendee data from the checkout is passed to the core order', is_int( $core ) && $core > 0 && false !== strpos( (string) Orders::get( $core )->attendee_data, 'Globex' ) );
$wc->status = 'processing';
Woo::sync_paid_order( 7101 );
$answers = $wpdb->get_col( "SELECT a.answer FROM {$p}wpcap_participant_answers a INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=$core ORDER BY a.answer" );
check( 'issued tickets carry the participant answers', array( 'ACME', 'Globex', 'vegan' ) === $answers );
check( 'participant data is removed from the WooCommerce order after issuance', '' === $wc->get_meta( Woo::ATTENDEE_META ) );

echo "WooCommerce: cash on delivery issues at completed\n";
$type = make_type( 5 );
$cod = woo_t_order( 7201, array( array( $type, 1, '25.00' ) ), 'cod' );
$cod_core = Woo::reserve( $cod );
$cod->status = 'processing';
Woo::sync_paid_order( 7201 );
Woo::sync_paid_order( 7201 );
$hold_until = $wpdb->get_var( "SELECT MAX(expires_at) FROM {$p}wpcap_reservations WHERE order_id=$cod_core AND status='active'" );
check( 'COD order in processing gets no tickets and keeps an extended hold', 0 === count( tickets( $cod_core ) ) && 'pending' === Orders::get( $cod_core )->payment_status && strtotime( $hold_until . ' UTC' ) > time() + 13 * DAY_IN_SECONDS );
check( 'COD deferral is noted once', 1 === count( array_filter( $cod->notes, static fn( $n ) => false !== strpos( $n, 'Abgeschlossen' ) ) ) );
Woo::action_retry_tickets( $cod );
check( 'manual re-issue respects the COD deferral', 0 === count( tickets( $cod_core ) ) );
$cod->status = 'completed';
Woo::sync_paid_order( 7201 );
check( 'COD order gets its ticket when completed', 1 === count( tickets( $cod_core, 'valid' ) ) );
$card = woo_t_order( 7202, array( array( $type, 1, '25.00' ) ), 'stripe' );
$card->status = 'processing';
Woo::sync_paid_order( 7202 );
check( 'other gateways still issue at processing', 1 === count( tickets( absint( $card->get_meta( Woo::ORDER_META ) ) ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_woocommerce_issue_on_completed_gateways'] = array( 'bacs', 'cod' );
check( 'deferred gateway list is filterable', Woo::defers_issuance( woo_t_order( 7203, array(), 'bacs' ) ) && ! Woo::defers_issuance( woo_t_order( 7204, array(), 'stripe' ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_woocommerce_issue_on_completed_gateways'] );

echo "WooCommerce: Store API quantity limits\n";
$type = make_type( 3, 10 );
$product = new WC_Product( 8790 );
$product->update_meta_data( Woo::PRODUCT_META, $type );
$other = new WC_Product( 8791 );
check( 'quantity maximum is capped at the remaining capacity', 3 === Woo::store_api_quantity_maximum( 9999, $product, array( 'key' => 'abc', 'quantity' => 1 ) ) );
check( 'quantity maximum of non-ticket products is untouched', 9999 === Woo::store_api_quantity_maximum( 9999, $other, null ) );
$too_many = Woo::store_api_validate_quantity( true, 4, $product, array( 'key' => 'abc', 'quantity' => 1 ) );
check( 'update-item above capacity is rejected with a message', is_wp_error( $too_many ) && false !== strpos( $too_many->get_error_message(), 'nur noch 3' ) );
check( 'update-item within capacity is accepted', true === Woo::store_api_validate_quantity( true, 3, $product, array( 'key' => 'abc', 'quantity' => 1 ) ) );
$small = make_type( 100, 4 );
check( 'per-order maximum caps the Store API quantity', 4 === Woo::quantity_limit( $small ) && 1 === Woo::quantity_limit( $small, 3 ) );

check( 'fiscal ticket-only WooCommerce carts pass allocation guard', null === Woo::mixed_fiscal_allocation_error( true, false, false, false ) );
check( 'mixed fiscal WooCommerce carts reject unassigned products, fees and shipping', is_wp_error( Woo::mixed_fiscal_allocation_error( true, true, false, false ) ) && is_wp_error( Woo::mixed_fiscal_allocation_error( true, false, true, false ) ) && is_wp_error( Woo::mixed_fiscal_allocation_error( true, false, false, true ) ) );
check( 'assigned mixed carts and carts without mapped fiscal tickets are not blocked by the allocation guard', null === Woo::mixed_fiscal_allocation_error( true, false, false, false ) && null === Woo::mixed_fiscal_allocation_error( false, true, true, true ) );

echo "WooCommerce: refunds\n";
$type = make_type( 10, 10, 2500 );
$wc = woo_t_order( 7301, array( array( $type, 2, '50.00' ) ) );
$core = Woo::reserve( $wc );
$wc->status = 'processing';
Woo::sync_paid_order( 7301 );
$refund = woo_t_refund( 7302, $wc, '25.00', array( array( 0, 1, 25 ) ) );
$plan = Woo::refund_plan( $wc, $refund, $core );
check( 'partial refund plan: one ticket, its gross amount', 1 === count( $plan['ticket_ids'] ) && 2500 === $plan['amount_minor'] && ! $plan['final'] );
$amount_only = new Wpcap_Test_Woo_Refund( 7303 );
$amount_only->amount = '10.00';
$plan_amount = Woo::refund_plan( $wc, $amount_only, $core );
check( 'amount-only refund: no tickets, amount attributed to tickets', array() === $plan_amount['ticket_ids'] && 1000 === $plan_amount['amount_minor'] );
$mixed = woo_t_order( 7304, array( array( $type, 1, '25.00' ), array( 0, 1, '75.00' ) ) );
$mixed_core = Woo::reserve( $mixed );
$mixed_refund = new Wpcap_Test_Woo_Refund( 7305 );
$mixed_refund->amount = '20.00';
check( 'amount-only refund on a mixed order counts only the ticket share', 500 === Woo::refund_plan( $mixed, $mixed_refund, $mixed_core )['amount_minor'] );

Woo::sync_refund( 7301, 7302 );
Woo::sync_refund( 7301, 7302 );
check( 'partial refund revokes exactly one ticket (idempotent)', 1 === count( tickets( $core, 'revoked' ) ) && 1 === count( tickets( $core, 'valid' ) ) );
if ( Woo::can_record_refunds() ) {
	$rows = PassCaptain\Refunds::for_order( $core );
	check( 'partial Woo refund is recorded in the refund ledger', 1 === count( $rows ) && 2500 === (int) $rows[0]['amount_minor'] && 'completed' === $rows[0]['status'] && 2500 === (int) Orders::get( $core )->refunded_minor );
	check( 'refunded ticket is flagged refunded', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$core AND refunded=1" ) );
} else {
	echo "  skip refund ledger checks (core Refunds::record_external not available)\n";
}
$final = woo_t_refund( 7306, $wc, '25.00', array( array( 0, 1, 25 ) ) );
$final_plan = Woo::refund_plan( $wc, $final, $core );
check( 'final refund plan covers the remaining ticket', $final_plan['final'] && 1 === count( $final_plan['ticket_ids'] ) );
$wc->status = 'refunded';
Woo::sync_fully_refunded_order( 7301 ); // WooCommerce sets "refunded" before woocommerce_order_refunded fires.
Woo::sync_refund( 7301, 7306 );
check( 'full refund: all tickets revoked and core order refunded', 2 === count( tickets( $core, 'revoked' ) ) && 'refunded' === Orders::get( $core )->status );
if ( Woo::can_record_refunds() ) {
	$rows = PassCaptain\Refunds::for_order( $core );
	check( 'full refund: two ledger rows summing to the order total', 2 === count( $rows ) && 5000 === array_sum( array_map( static fn( $r ) => (int) $r['amount_minor'], $rows ) ) && 5000 === (int) Orders::get( $core )->refunded_minor );
}
