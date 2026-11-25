<?php
/**
 * WooCommerce adapter with tickets of different invoice issuers (sellers, docs/SALES-MODELS.md):
 * mixed-cart policy (reject in classic cart/checkout and Store API, or split into one Pass Captain order
 * per seller), allocation of coupons, fees, shipping and rounding (largest remainder), invoices per issuer,
 * refunds per seller and line (full, partial, amount-only, checked-in tickets, cancelled events) and
 * settlement correction lines. Included from run.php after woocommerce.php (uses its fakes/helpers).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as MsPartners;
use PassCaptain\Partners\SalesModel;
use PassCaptain\Partners\Settlements as MsSettlements;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Integrations\WooCommerce\SellerSplit;

global $wpdb;
$p = $wpdb->prefix;

wpcap_test_route_exception(); // WooCommerce Store API exception class (lib/fixtures_shared.php).
class Wpcap_Ms_Item {
	use Test_Meta;
	public function __construct( public int $id, public string $kind, public string $total, public string $tax = '0' ) { $GLOBALS['wpcap_test_items'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_type() { return $this->kind; }
	public function get_quantity() { return 1; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
}
class Wpcap_Ms_Order extends Wpcap_Test_Woo_Order {
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = array_merge( (array) ( $this->meta[ $k ] ?? array() ), array( $v ) ); }
}
if ( ! function_exists( 'WC' ) ) {
	function WC() { return $GLOBALS['wpcap_test_wc']; }
}
require_once dirname( __DIR__ ) . '/lib/wc_orders_stub.php'; // wc_get_orders() over the test orders.
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); }
}

/** Ticket type with VAT rate. */
function ms_type( $price, $tax_bp, $capacity = 50 ) {
	global $wpdb;
	$type = make_type( $capacity, 10, $price );
	$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", array( 'tax_rate_bp' => $tax_bp ), array( 'ticket_type_id' => $type ) );
	return $type;
}
function ms_event( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function ms_product( $type_id ) {
	static $id = 12500;
	$product = new WC_Product( ++$id );
	if ( $type_id ) { $product->update_meta_data( Woo::PRODUCT_META, $type_id ); }
	$GLOBALS['wpcap_test_products'][ $id ] = $product;
	return $product;
}
/**
 * WooCommerce order fake: lines are [type_id|0, qty, total, tax] for products, ['fee', total, tax] and
 * ['shipping', total, tax]. The order total is the sum of all gross amounts.
 */
function ms_order( $id, array $lines ) {
	static $item_id = 12000;
	$order = new Wpcap_Ms_Order( $id );
	$total = 0.0;
	foreach ( $lines as $line ) {
		if ( 'fee' === $line[0] || 'shipping' === $line[0] ) {
			$order->items[] = new Wpcap_Ms_Item( ++$item_id, $line[0], $line[1], $line[2] ?? '0' );
			$total += (float) $line[1] + (float) ( $line[2] ?? 0 );
			continue;
		}
		$order->items[] = new WC_Order_Item_Product( ++$item_id, ms_product( $line[0] ), $line[1], $line[2], $line[3] ?? '0' );
		$total += (float) $line[2] + (float) ( $line[3] ?? 0 );
	}
	$order->total = number_format( $total, 2, '.', '' );
	return $order;
}
/** Amount-only refund (no lines). */
function ms_amount_refund( $id, Wpcap_Test_Woo_Order $order, $amount ) {
	$refund = new Wpcap_Test_Woo_Refund( $id );
	$refund->amount = (string) $amount;
	$order->refund_list[] = $refund;
	return $refund;
}
function ms_cart( array $type_ids, $store_api = false ) {
	$wc = new Wpcap_Ms_WC();
	$wc->cart = new Wpcap_Ms_Cart();
	$wc->store_api = $store_api;
	foreach ( $type_ids as $i => $type_id ) {
		$wc->cart->cart_contents[ 'ms' . $i ] = array( 'key' => 'ms' . $i, 'data' => ms_product( $type_id ), 'quantity' => 1 );
	}
	$GLOBALS['wpcap_test_wc'] = $wc;
	return $wc;
}
function ms_core( $core_id ) { return Orders::get( $core_id ); }
function ms_items( $core_id ) {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->get_results( "SELECT ticket_type_id,quantity,line_total_minor,tax_rate_bp,tax_minor FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=" . (int) $core_id . " ORDER BY ticket_type_id" ) as $row ) { $out[ (int) $row->ticket_type_id ] = $row; }
	return $out;
}

PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'seller_name' => 'Plattform GmbH', 'seller_address' => "Ring 1\n1010 Wien", 'seller_vat_id' => 'DE999999999', 'seller_country' => 'DE', 'invoice_prefix' => 'WPC', 'credit_note_prefix' => 'GS' ) );
delete_option( SellerSplit::OPTION );
delete_option( Woo::CHECKED_IN_OPTION );
$ms_m1 = gmdate( 'Y-m', strtotime( 'first day of -1 month' ) );

echo "WooCommerce multi-seller: largest-remainder rounding\n";
check( 'largest remainder: 1,00 over three equal weights = 34/33/33 (tie goes to the first)', array( 34, 33, 33 ) === array_values( SellerSplit::largest_remainder( 100, array( 1, 1, 1 ) ) ) );
check( 'largest remainder: negative amounts mirror positive ones', array( -3, -3, -4 ) === array_values( SellerSplit::largest_remainder( -10, array( 3, 3, 4 ) ) ) );
check( 'largest remainder: largest fractional remainder wins, not the largest weight', array( 'a' => 2, 'b' => 5 ) === SellerSplit::largest_remainder( 7, array( 'a' => 1, 'b' => 2 ) ) && array( 'x' => 0, 'y' => 7 ) === SellerSplit::largest_remainder( 7, array( 'x' => 0, 'y' => 5 ) ) );
check( 'largest remainder: no positive weight = equal split in key order', array( 4, 3 ) === array_values( SellerSplit::largest_remainder( 7, array( 0, 0 ) ) ) );
mt_srand( 4711 );
$ms_ok = true;
for ( $i = 0; $i < 300; $i++ ) {
	$w = array();
	for ( $k = 0, $n = mt_rand( 1, 6 ); $k < $n; $k++ ) { $w[ 'k' . $k ] = mt_rand( 0, 100000 ); }
	$amount = mt_rand( -50000, 50000 );
	$r = SellerSplit::largest_remainder( $amount, $w );
	$sum = array_sum( $w );
	foreach ( $r as $k => $v ) {
		$exact = $sum > 0 ? $amount * $w[ $k ] / $sum : $amount / count( $w );
		if ( abs( $v - $exact ) >= 1 ) { $ms_ok = false; }
	}
	if ( array_sum( $r ) !== $amount || $r !== SellerSplit::largest_remainder( $amount, $w ) ) { $ms_ok = false; }
}
check( 'largest remainder (300 seeded random cases): shares add up exactly, each within one minor unit of the exact share, deterministic', $ms_ok );

echo "WooCommerce multi-seller: sellers per ticket type\n";
$ms_tp  = ms_type( 1070, 700 );   // platform event, 7 %
$ms_ta  = ms_type( 2380, 1900 );  // agent organizer Alpha, 19 %
$ms_ta2 = ms_type( 2380, 1900 );  // second event of Alpha
$ms_tb  = ms_type( 1000, 0 );     // agent organizer Beta (small business, 0 %)
$ms_to  = ms_type( 1500, 700 );   // own_name organizer = platform sells
$ms_pa = MsPartners::save( array( 'name' => 'Alpha', 'legal_name' => 'Agentur Alpha GmbH', 'billing_address' => "Alphaweg 1\n80331 München", 'vat_id' => 'DE444444444', 'sales_model' => 'agent', 'invoice_prefix' => 'MSA', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 0, 'fee_tax_rate_bp' => 1900, 'settlement_start' => $ms_m1 . '-01' ) );
$ms_pb = MsPartners::save( array( 'name' => 'Beta', 'legal_name' => 'Agentur Beta KG', 'billing_address' => "Betagasse 2\n1010 Wien", 'tax_number' => '12 345/6789', 'sales_model' => 'agent', 'invoice_prefix' => 'MSB', 'small_business' => 1, 'settlement_start' => $ms_m1 . '-01' ) );
$ms_po = MsPartners::save( array( 'name' => 'Eigen OG', 'billing_address' => 'Platz 3, Linz', 'vat_id' => 'ATU11111111' ) );
MsPartners::set_events( $ms_pa, array( ms_event( $ms_ta ), ms_event( $ms_ta2 ) ) );
MsPartners::set_events( $ms_pb, array( ms_event( $ms_tb ) ) );
MsPartners::set_events( $ms_po, array( ms_event( $ms_to ) ) );
$ms_ka = 'org-' . $ms_pa;
$ms_kb = 'org-' . $ms_pb;
$ms_keys = SalesModel::seller_keys_for_types( array( $ms_tp, $ms_ta, $ms_ta2, $ms_tb, $ms_to ) );
check( 'seller keys: agent organizers sell themselves, own_name organizers and platform events are sold by the platform', array( $ms_tp => 'platform', $ms_ta => $ms_ka, $ms_ta2 => $ms_ka, $ms_tb => $ms_kb, $ms_to => 'platform' ) === $ms_keys );
check( 'seller order is deterministic (platform first, then partner ID)', array( 'platform', $ms_ka, $ms_kb ) === SalesModel::sort_seller_keys( array( $ms_kb, 'platform', $ms_ka, $ms_ka ) ) );
check( 'seller names come from the legal name / platform settings', 'Agentur Alpha GmbH' === SalesModel::seller_name( $ms_ka ) && 'Plattform GmbH' === SalesModel::seller_name( 'platform' ) );

echo "WooCommerce multi-seller: policy reject (default) – classic cart and checkout\n";
check( 'default policy is reject', SellerSplit::REJECT === SellerSplit::policy() );
ms_cart( array( $ms_ta ) );
$GLOBALS['wpcap_test_notices'] = array();
$ms_add = Woo::validate_add_to_cart( true, ms_product( $ms_tp )->id, 1 );
check( 'classic add-to-cart: ticket of another seller is refused with a clear message', false === $ms_add && 1 === count( $GLOBALS['wpcap_test_notices'] ) && false !== strpos( $GLOBALS['wpcap_test_notices'][0], 'verschiedener Rechnungssteller' ) && false !== strpos( $GLOBALS['wpcap_test_notices'][0], 'Agentur Alpha GmbH' ) && false !== strpos( $GLOBALS['wpcap_test_notices'][0], 'Plattform GmbH' ) );
$GLOBALS['wpcap_test_notices'] = array();
check( 'classic add-to-cart: same seller (another event of the organizer) is accepted', true === Woo::validate_add_to_cart( true, ms_product( $ms_ta2 )->id, 1 ) && ! $GLOBALS['wpcap_test_notices'] );
ms_cart( array( $ms_tp ) );
check( 'classic add-to-cart: own_name organizer and platform event share one seller', true === Woo::validate_add_to_cart( true, ms_product( $ms_to )->id, 1 ) );
check( 'classic add-to-cart: non-ticket products are not affected', true === Woo::validate_add_to_cart( true, ms_product( 0 )->id, 1 ) );
ms_cart( array( $ms_ta, $ms_tp ) );
$GLOBALS['wpcap_test_notices'] = array();
Woo::check_cart_items();
check( 'classic cart/checkout validation (woocommerce_check_cart_items) refuses a mixed cart', 1 === count( array_filter( $GLOBALS['wpcap_test_notices'], static fn( $n ) => false !== strpos( $n, 'verschiedener Rechnungssteller' ) ) ) );

echo "WooCommerce multi-seller: policy reject – Store API (cart/checkout blocks)\n";
ms_cart( array( $ms_ta, $ms_tb ), true );
$GLOBALS['wpcap_test_notices'] = array();
Woo::check_cart_items();
$ms_errors = new Wpcap_Ms_Errors();
Woo::store_api_cart_errors( $ms_errors );
check( 'Store API: classic hook stays silent, woocommerce_store_api_cart_errors reports the mixed cart', ! $GLOBALS['wpcap_test_notices'] && 1 === count( array_filter( $ms_errors->errors, static fn( $e ) => false !== strpos( $e, 'Agentur Alpha GmbH' ) && false !== strpos( $e, 'Agentur Beta KG' ) ) ) );
ms_cart( array( $ms_ta ), true );
$ms_thrown = null;
try { Woo::store_api_validate_add_to_cart( ms_product( $ms_tb ) ); } catch ( Exception $e ) { $ms_thrown = $e; }
check( 'Store API add-to-cart (woocommerce_store_api_validate_add_to_cart) throws a RouteException', $ms_thrown instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException && 'wpcap_mixed_sellers' === $ms_thrown->error_code && false !== strpos( $ms_thrown->getMessage(), 'verschiedener Rechnungssteller' ) );
$ms_thrown = null;
try { Woo::store_api_validate_add_to_cart( ms_product( $ms_ta2 ) ); } catch ( Exception $e ) { $ms_thrown = $e; }
check( 'Store API add-to-cart of the same seller passes', null === $ms_thrown );

$ms_orders_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
$ms_rej = ms_order( 7601, array( array( $ms_ta, 1, '20.00', '3.80' ), array( $ms_tp, 1, '10.00', '0.70' ) ) );
$ms_rej_r = Woo::reserve( $ms_rej );
$ms_classic = null;
try { Woo::reserve_classic( 7601, array(), $ms_rej ); } catch ( Exception $e ) { $ms_classic = $e; }
$ms_blocks = null;
try { Woo::reserve_store_api( $ms_rej ); } catch ( Exception $e ) { $ms_blocks = $e; }
check( 'hold (last line of defence): mixed order is refused, nothing is reserved', is_wp_error( $ms_rej_r ) && 'wpcap_mixed_sellers' === $ms_rej_r->get_error_code() && $ms_orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) && ! $ms_rej->get_meta( Woo::ORDER_META ) );
check( 'classic checkout and Store API checkout both abort before payment', $ms_classic instanceof Exception && $ms_blocks instanceof Automattic\WooCommerce\StoreApi\Exceptions\RouteException && 409 === $ms_blocks->status );

echo "WooCommerce multi-seller: policy split – allocation of coupons, fees, shipping, mixed VAT\n";
update_option( SellerSplit::OPTION, SellerSplit::SPLIT );
ms_cart( array( $ms_ta, $ms_tp ) );
$GLOBALS['wpcap_test_notices'] = array();
Woo::check_cart_items();
$ms_errors = new Wpcap_Ms_Errors();
Woo::store_api_cart_errors( $ms_errors );
check( 'split: mixed carts pass the classic and the Store API validation', ! $GLOBALS['wpcap_test_notices'] && ! $ms_errors->errors && true === Woo::validate_add_to_cart( true, ms_product( $ms_tb )->id, 1 ) );
$GLOBALS['wpcap_test_filters']['wpcap_woocommerce_mixed_seller_policy'] = 'reject';
check( 'policy is filterable', SellerSplit::REJECT === SellerSplit::policy() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_woocommerce_mixed_seller_policy'] );

// Platform 10,70 (7 %), Alpha 2 × 23,80 + 1 × 23,80 (19 %), Beta 3 × 10,00 (0 %), a non-ticket product 10,00,
// a cart discount as negative fee −10,00, a service fee 2,50 and shipping 4,90 → WooCommerce total 119,50.
$ms_wc = ms_order( 7610, array(
	array( $ms_tb, 3, '30.00' ),
	array( $ms_ta, 2, '40.00', '7.60' ),
	array( 0, 1, '10.00' ),
	array( $ms_tp, 1, '10.00', '0.70' ),
	array( $ms_ta2, 1, '20.00', '3.80' ),
	array( 'fee', '-10.00' ),
	array( 'fee', '2.10', '0.40' ),
	array( 'shipping', '4.90' ),
) );
$ms_alloc = Woo::allocation( $ms_wc );
// Weights 1070 / 7140 / 3000 / shop 1000 (sum 12210): floors 87/584/245/81, remainders .633/.767/.700/.900 → +1 shop, Alpha, Beta.
check( 'negative fee: largest remainder over sellers and shop (87 / 585 / 246, shop 82)', 1000 === $ms_alloc['fee_discount'] && array( 'platform' => 87, $ms_ka => 585, $ms_kb => 246 ) === array_map( static fn( $s ) => $s['fee_discount'], $ms_alloc['sellers'] ) && 82 === $ms_alloc['shop']['fee_discount'] );
check( 'within a seller the share follows the line gross (Alpha 390 / 195)', 390 === $ms_alloc['sellers'][ $ms_ka ]['lines'][ $ms_ta ]['fee_discount'] && 195 === $ms_alloc['sellers'][ $ms_ka ]['lines'][ $ms_ta2 ]['fee_discount'] );
check( 'positive fee and shipping stay with the shop, not with any seller', 250 === $ms_alloc['shop']['fees_positive'] && 490 === $ms_alloc['shop']['shipping'] && 1000 === $ms_alloc['shop']['products_gross'] );
$ms_sum = array_sum( array_map( static fn( $s ) => $s['gross'], $ms_alloc['sellers'] ) ) + $ms_alloc['shop']['products_gross'] - $ms_alloc['shop']['fee_discount'] + $ms_alloc['shop']['fees_positive'] + $ms_alloc['shop']['shipping'];
check( 'allocation reconciles to the cent: sellers + shop = WooCommerce total (119,50)', 11950 === $ms_sum && 11950 === (int) round( (float) $ms_wc->get_total() * 100 ) );
$ms_core1 = Woo::reserve( $ms_wc );
$ms_map = Woo::seller_orders( $ms_wc );
check( 'split: one Pass Captain order per seller, first one in the classic order meta', 3 === count( $ms_map ) && array( 'platform', $ms_ka, $ms_kb ) === array_keys( $ms_map ) && $ms_core1 === $ms_map['platform'] && $ms_core1 === (int) $ms_wc->get_meta( Woo::ORDER_META ) && array_values( $ms_map ) === Woo::core_order_ids( $ms_wc ) );
check( 'seller orders: totals after the allocated discount (9,83 / 65,55 / 27,54)', 983 === (int) ms_core( $ms_map['platform'] )->total_minor && 6555 === (int) ms_core( $ms_map[ $ms_ka ] )->total_minor && 2754 === (int) ms_core( $ms_map[ $ms_kb ] )->total_minor );
$ms_ia = ms_items( $ms_map[ $ms_ka ] );
check( 'mixed VAT: each line keeps its rate, VAT is computed from the allocated gross', 1900 === (int) $ms_ia[ $ms_ta ]->tax_rate_bp && 4370 === (int) $ms_ia[ $ms_ta ]->line_total_minor && Orders::gross_tax( 4370, 1900 ) === (int) $ms_ia[ $ms_ta ]->tax_minor && Orders::gross_tax( 2185, 1900 ) === (int) $ms_ia[ $ms_ta2 ]->tax_minor && Orders::gross_tax( 983, 700 ) === (int) ms_items( $ms_map['platform'] )[ $ms_tp ]->tax_minor && 0 === (int) ms_items( $ms_map[ $ms_kb ] )[ $ms_tb ]->tax_minor );
check( 'each seller order only holds its own ticket types', array( $ms_tp ) === array_keys( ms_items( $ms_map['platform'] ) ) && array( $ms_ta, $ms_ta2 ) === array_keys( $ms_ia ) && array( $ms_tb ) === array_keys( ms_items( $ms_map[ $ms_kb ] ) ) );
check( 'allocation snapshot and lookup meta are stored; order note names the sellers', is_array( $ms_wc->get_meta( SellerSplit::PLAN_META ) ) && array_values( $ms_map ) === array_map( 'intval', $ms_wc->meta[ SellerSplit::LOOKUP_META ] ) && false !== strpos( end( $ms_wc->notes ), 'aufgeteilt auf 3' ) && false !== strpos( end( $ms_wc->notes ), 'Agentur Beta KG' ) );
check( 'every seller order resolves to exactly one seller (SalesModel::order_context)', 'own_name' === SalesModel::order_context( $ms_map['platform'] )['model'] && $ms_pa === SalesModel::order_context( $ms_map[ $ms_ka ] )['partner_id'] && $ms_pb === SalesModel::order_context( $ms_map[ $ms_kb ] )['partner_id'] );

$ms_retry_old = $ms_map;
$ms_core2 = Woo::reserve( $ms_wc );
$ms_map = Woo::seller_orders( $ms_wc );
check( 'checkout retry: all previous seller orders cancelled, new ones created, capacity held once', $ms_core2 !== $ms_core1 && 3 === count( array_filter( $ms_retry_old, static fn( $id ) => 'cancelled' === ms_core( $id )->status ) ) && ! array_intersect( $ms_retry_old, $ms_map ) && 47 === Orders::availability( $ms_tb ) );

$ms_cpn = ms_order( 7615, array( array( $ms_tp, 3, '25.67', '1.80' ), array( $ms_ta, 1, '16.81', '3.19' ) ) ); // fixed cart coupon already spread by WooCommerce
$ms_cpn_a = Woo::allocation( $ms_cpn );
check( 'coupons: WooCommerce line totals after coupon are adopted unchanged (27,47 / 20,00)', 2747 === $ms_cpn_a['sellers']['platform']['gross'] && 2000 === $ms_cpn_a['sellers'][ $ms_ka ]['gross'] && 0 === $ms_cpn_a['fee_discount'] && 915 === $ms_cpn_a['sellers']['platform']['lines'][ $ms_tp ]['unit_minor'] );
$ms_tie = Woo::allocation( ms_order( 7616, array( array( $ms_tb, 1, '10.00' ), array( $ms_ta, 1, '10.00' ), array( $ms_tp, 1, '10.00' ), array( 'fee', '-0.02' ) ) ) );
check( 'rounding tie: 0,02 over three equal sellers goes to platform and Alpha (seller order), Beta gets 0', array( 'platform' => 1, $ms_ka => 1, $ms_kb => 0 ) === array_map( static fn( $s ) => $s['fee_discount'], $ms_tie['sellers'] ) );
$ms_big = Woo::allocation( ms_order( 7617, array( array( $ms_tp, 1, '10.00' ), array( 'fee', '-15.00' ) ) ) );
check( 'discount larger than the tickets is capped (order total never negative)', 1000 === $ms_big['fee_discount'] && 0 === $ms_big['sellers']['platform']['gross'] );
$ms_single = Woo::allocation( ms_order( 7618, array( array( $ms_tp, 2, '20.00', '1.40' ), array( 'fee', '-2.14' ) ) ) );
check( 'single seller: the same rules apply (negative fee reduces the seller order)', 1 === count( $ms_single['sellers'] ) && 1926 === $ms_single['sellers']['platform']['gross'] );

$ms_full_t = ms_type( 1000, 0, 1 );
$ms_fail = ms_order( 7619, array( array( $ms_tp, 1, '10.00', '0.70' ), array( $ms_full_t, 1, '10.00' ) ) );
MsPartners::set_events( $ms_pb, array( ms_event( $ms_tb ), ms_event( $ms_full_t ) ) );
Orders::create( array( 'email' => 'block@example.com', 'items' => array( array( 'ticket_type_id' => $ms_full_t, 'quantity' => 1 ) ), 'idempotency_key' => 'ms-block' ) );
$ms_avail_tp = Orders::availability( $ms_tp );
$ms_fail_r = Woo::reserve( $ms_fail );
check( 'split is all-or-nothing: a sold-out seller line cancels the seller orders already created', is_wp_error( $ms_fail_r ) && $ms_avail_tp === Orders::availability( $ms_tp ) && ! $ms_fail->get_meta( Woo::ORDER_META ) );

echo "WooCommerce multi-seller: payment, tickets and invoices per issuer\n";
$ms_wc->status = 'processing';
Woo::sync_paid_order( 7610 );
Woo::sync_paid_order( 7610 );
check( 'payment confirms every seller order; exactly one ticket per unit (idempotent)', 1 === count( tickets( $ms_map['platform'] ) ) && 3 === count( tickets( $ms_map[ $ms_ka ] ) ) && 3 === count( tickets( $ms_map[ $ms_kb ] ) ) && 3 === count( array_filter( $ms_map, static fn( $id ) => 'paid' === ms_core( $id )->payment_status ) ) );
check( 'order notes name the seller order and issuer', 1 === count( array_filter( $ms_wc->notes, static fn( $n ) => false !== strpos( $n, '[#' . $ms_map[ $ms_ka ] . ' Agentur Alpha GmbH] 3 Tickets ausgestellt' ) ) ) );
$ms_year = Invoices::local_year();
$ms_inv_p = Invoices::issue_for_order( $ms_map['platform'] );
$ms_inv_a = Invoices::issue_for_order( $ms_map[ $ms_ka ] );
$ms_inv_b = Invoices::issue_for_order( $ms_map[ $ms_kb ] );
check( 'each seller order is invoiced by its issuer in its own number range', ! is_wp_error( $ms_inv_p ) && 0 === strpos( $ms_inv_p['invoice_number'], 'WPC-' ) && ! is_wp_error( $ms_inv_a ) && sprintf( 'MSA-%d-000001', $ms_year ) === $ms_inv_a['invoice_number'] && ! is_wp_error( $ms_inv_b ) && sprintf( 'MSB-%d-000001', $ms_year ) === $ms_inv_b['invoice_number'] );
$ms_snap = (array) json_decode( (string) $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE invoice_id=" . (int) $ms_inv_a['invoice_id'] ), true );
check( 'organizer invoice carries the organizer as seller and the allocated total', 'Agentur Alpha GmbH' === $ms_snap['seller']['name'] && 6555 === (int) $ms_snap['total_minor'] );
ob_start();
Woo::render_meta_box( $ms_wc );
$ms_box = (string) ob_get_clean();
check( 'admin meta box lists every seller order with its issuer', 3 === substr_count( $ms_box, 'Pass Captain Bestellung #' ) && false !== strpos( $ms_box, 'Rechnungssteller: Agentur Beta KG' ) );

echo "WooCommerce multi-seller: refunds per seller and line\n";
$ms_r1 = woo_t_refund( 7620, $ms_wc, '23.80', array( array( 1, 1, 23.80 ) ) ); // one Alpha ticket (line index 1)
$ms_plans = Woo::refund_plans( $ms_wc, $ms_r1, Woo::core_order_ids( $ms_wc ) );
check( 'line refund maps to the seller order holding the ticket type', 1 === count( $ms_plans[ $ms_map[ $ms_ka ] ]['ticket_ids'] ) && 2380 === $ms_plans[ $ms_map[ $ms_ka ] ]['amount_minor'] && 0 === $ms_plans[ $ms_map['platform'] ]['amount_minor'] && ! $ms_plans[ $ms_map[ $ms_kb ] ]['ticket_ids'] );
Woo::sync_refund( 7610, 7620 );
Woo::sync_refund( 7610, 7620 );
check( 'line refund booked once on the Alpha order only (ledger, refunded flag, revoked ticket)', 1 === count( PassCaptain\Refunds::for_order( $ms_map[ $ms_ka ] ) ) && 2380 === (int) ms_core( $ms_map[ $ms_ka ] )->refunded_minor && 0 === (int) ms_core( $ms_map['platform'] )->refunded_minor && 0 === count( PassCaptain\Refunds::for_order( $ms_map[ $ms_kb ] ) ) && 1 === count( tickets( $ms_map[ $ms_ka ], 'revoked' ) ) );
$ms_cn = Invoices::credit_note_for_refund( (int) PassCaptain\Refunds::for_order( $ms_map[ $ms_ka ] )[0]['refund_id'] );
check( 'credit note of the seller refund is issued by the organizer (MSA-K series)', is_array( $ms_cn ) && sprintf( 'MSA-K-%d-000001', $ms_year ) === $ms_cn['credit_note_number'] );
check( 'order note states the seller order of the refund', 1 === count( array_filter( $ms_wc->notes, static fn( $n ) => false !== strpos( $n, 'Erstattung für Bestellung #' . $ms_map[ $ms_ka ] . ' (Agentur Alpha GmbH)' ) ) ) );

$ms_r2 = ms_amount_refund( 7621, $ms_wc, '10.00' );
$ms_plans = Woo::refund_plans( $ms_wc, $ms_r2, Woo::core_order_ids( $ms_wc ) );
// Ticket share round(1000 × 10292 / 11950) = 861 over remaining 983 / 4175 / 2754 → 106.97 / 454.33 / 299.70 → 107 / 454 / 300.
check( 'amount-only refund: ticket share 8,61 split by remaining amount with largest remainder (1,07 / 4,54 / 3,00), no tickets', 107 === $ms_plans[ $ms_map['platform'] ]['amount_minor'] && 454 === $ms_plans[ $ms_map[ $ms_ka ] ]['amount_minor'] && 300 === $ms_plans[ $ms_map[ $ms_kb ] ]['amount_minor'] && ! array_filter( array_column( $ms_plans, 'ticket_ids' ) ) );
Woo::sync_refund( 7610, 7621 );
check( 'amount-only refund booked on all three seller orders', 107 === (int) ms_core( $ms_map['platform'] )->refunded_minor && 2380 + 454 === (int) ms_core( $ms_map[ $ms_ka ] )->refunded_minor && 300 === (int) ms_core( $ms_map[ $ms_kb ] )->refunded_minor && 3 === count( tickets( $ms_map[ $ms_kb ], 'valid' ) ) );

echo "WooCommerce multi-seller: checked-in tickets\n";
$ms_b_tickets = tickets( $ms_map[ $ms_kb ], 'valid' );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=" . (int) $ms_b_tickets[0]->ticket_id );
$ms_b_line = $ms_wc->items[0]->get_id();
$ms_guard = null;
try { Woo::guard_refund( new Wpcap_Test_Woo_Refund( 7699 ), array( 'order_id' => 7610, 'line_items' => array( $ms_b_line => array( 'qty' => 3 ) ) ) ); } catch ( Exception $e ) { $ms_guard = $e; }
check( 'default deny: a quantity refund that needs a checked-in ticket is refused before WooCommerce pays out', $ms_guard instanceof Exception && false !== strpos( $ms_guard->getMessage(), 'nur 2 nicht eingecheckte Tickets' ) );
$ms_guard = null;
try { Woo::guard_refund( new Wpcap_Test_Woo_Refund( 7699 ), array( 'order_id' => 7610, 'line_items' => array( $ms_b_line => array( 'qty' => 2 ) ) ) ); } catch ( Exception $e ) { $ms_guard = $e; }
try { Woo::guard_refund( new Wpcap_Test_Woo_Refund( 7699 ), array( 'order_id' => 7610, 'line_items' => array() ) ); } catch ( Exception $e ) { $ms_guard = $e; }
check( 'quantities served by valid tickets and amount-only refunds pass the guard', null === $ms_guard );
update_option( Woo::CHECKED_IN_OPTION, 'yes' );
// E11-1: a person refunding checked-in tickets gives a reason (and needs the approver capability).
try { Woo::guard_refund( new Wpcap_Test_Woo_Refund( 7699 ), array( 'order_id' => 7610, 'reason' => 'Abbruch', 'line_items' => array( $ms_b_line => array( 'qty' => 3 ) ) ) ); } catch ( Exception $e ) { $ms_guard = $e; }
check( 'admin override (option) allows refunding checked-in tickets', null === $ms_guard && Woo::checked_in_refund_allowed() );
update_option( Woo::CHECKED_IN_OPTION, 'no' );
$GLOBALS['wpcap_test_filters']['wpcap_woocommerce_refund_checked_in'] = true;
check( 'checked-in refund policy is filterable', Woo::checked_in_refund_allowed() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_woocommerce_refund_checked_in'] );

$ms_r3 = woo_t_refund( 7622, $ms_wc, '30.00', array( array( 0, 3, 30.00 ) ) ); // bypassing the guard (e.g. programmatic refund)
$ms_plan3 = Woo::refund_plan( $ms_wc, $ms_r3, $ms_map[ $ms_kb ] );
check( 'deny: the plan skips the checked-in ticket (2 tickets, 1 denied) but books the amount (capped at the remainder)', 2 === count( $ms_plan3['ticket_ids'] ) && 1 === $ms_plan3['denied_checked_in'] && 2754 - 300 === $ms_plan3['amount_minor'] );
Woo::sync_refund( 7610, 7622 );
check( 'checked-in ticket stays checked in and unrefunded; order note explains it', 'checked_in' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ms_b_tickets[0]->ticket_id ) && '0' === (string) $wpdb->get_var( "SELECT refunded FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ms_b_tickets[0]->ticket_id ) && 1 === count( array_filter( $ms_wc->notes, static fn( $n ) => false !== strpos( $n, 'laut Einstellung nicht als erstattet markiert' ) ) ) );

echo "WooCommerce multi-seller: full refund\n";
$ms_refunded_so_far = 23.80 + 10.00 + 30.00;
$ms_r4 = ms_amount_refund( 7623, $ms_wc, number_format( 119.50 - $ms_refunded_so_far, 2, '.', '' ) );
$ms_wc->status = 'refunded';
Woo::sync_fully_refunded_order( 7610 );
Woo::sync_refund( 7610, 7623 );
check( 'final refund: every seller order ends up exactly refunded', 3 === count( array_filter( $ms_map, static fn( $id ) => (int) ms_core( $id )->refunded_minor === (int) ms_core( $id )->total_minor ) ) );
check( 'final refund: all remaining tickets revoked except the checked-in one (deny)', 0 === count( tickets( $ms_map['platform'], 'valid' ) ) && 0 === count( tickets( $ms_map[ $ms_ka ], 'valid' ) ) && 0 === count( tickets( $ms_map[ $ms_kb ], 'valid' ) ) && 1 === count( tickets( $ms_map[ $ms_kb ], 'checked_in' ) ) && 'refunded' === ms_core( $ms_map['platform'] )->status );
$ms_ledger = 0;
foreach ( $ms_map as $id ) { $ms_ledger += array_sum( array_map( static fn( $r ) => (int) $r['amount_minor'], PassCaptain\Refunds::for_order( $id ) ) ); }
check( 'ledger over all seller orders equals the sum of the seller order totals (102,92)', 10292 === $ms_ledger );

echo "WooCommerce multi-seller: event cancellation\n";
$ms_wc2 = ms_order( 7630, array( array( $ms_ta, 2, '40.00', '7.60' ), array( $ms_tp, 1, '10.00', '0.70' ) ) );
Woo::reserve( $ms_wc2 );
$ms_wc2->status = 'processing';
Woo::sync_paid_order( 7630 );
$ms_map2 = Woo::seller_orders( $ms_wc2 );
$ms_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $ms_ta );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $ms_occ ) );
Orders::revoke_tickets( $ms_map2[ $ms_ka ], $ms_ta, 2, 'occurrence_cancelled' );
Woo::note_cancelled_occurrence( $ms_map2[ $ms_ka ], $ms_occ );
check( 'cancellation note on the WooCommerce order names the seller order (lookup via split meta)', 1 === count( array_filter( $ms_wc2->notes, static fn( $n ) => false !== strpos( $n, 'Bestellung #' . $ms_map2[ $ms_ka ] . ' (Agentur Alpha GmbH) wurde abgesagt' ) ) ) && $ms_wc2 === Woo::wc_order_for_core( $ms_map2[ $ms_ka ] ) );
$ms_r5 = woo_t_refund( 7631, $ms_wc2, '47.60', array( array( 0, 2, 47.60 ) ) );
Woo::sync_refund( 7630, 7631 );
check( 'refund of the cancelled line refunds the revoked tickets of the Alpha order only', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $ms_map2[ $ms_ka ] . " AND refunded=1" ) && 4760 === (int) ms_core( $ms_map2[ $ms_ka ] )->refunded_minor && 0 === (int) ms_core( $ms_map2['platform'] )->refunded_minor && 1 === count( tickets( $ms_map2['platform'], 'valid' ) ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'scheduled' ), array( 'occurrence_id' => $ms_occ ) );

echo "WooCommerce multi-seller: settlements per seller with correction lines\n";
$ms_wc3 = ms_order( 7640, array( array( $ms_ta2, 2, '40.00', '7.60' ), array( $ms_tb, 1, '10.00' ), array( 'fee', '-5.76' ) ) );
Woo::reserve( $ms_wc3 );
$ms_wc3->status = 'processing';
Woo::sync_paid_order( 7640 );
$ms_map3 = Woo::seller_orders( $ms_wc3 );
$ms_t1 = gmdate( 'Y-m-d H:i:s', strtotime( $ms_m1 . '-12 12:00:00 UTC' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s WHERE order_id IN (" . implode( ',', array_map( 'intval', $ms_map3 ) ) . ')', $ms_t1 ) );
// Alpha settles only its seller order of this WooCommerce order (other Alpha orders of this test are not in the last month).
$ms_set = MsSettlements::finalize( MsSettlements::create_draft( $ms_pa, $ms_m1 ) );
$ms_set_lines = MsSettlements::lines( $ms_set->settlement_id );
check( 'last month: Alpha settlement contains only the Alpha seller order (gross after allocated discount)', ! is_wp_error( $ms_set ) && $ms_set_lines && array( (int) $ms_map3[ $ms_ka ] ) === array_values( array_unique( array_map( static fn( $l ) => (int) $l->order_id, $ms_set_lines ) ) ) && (int) ms_core( $ms_map3[ $ms_ka ] )->total_minor === array_sum( array_map( static fn( $l ) => (int) $l->gross_minor, $ms_set_lines ) ) );
$ms_r6 = woo_t_refund( 7641, $ms_wc3, '23.80', array( array( 0, 1, 23.80 ) ) );
Woo::sync_refund( 7640, 7641 );
$ms_now = gmdate( 'Y-m' );
$ms_corr = array_values( array_filter( MsSettlements::compute( MsPartners::get( $ms_pa ), $ms_now )['lines'], static fn( $l ) => (int) $l['order_id'] === (int) $ms_map3[ $ms_ka ] ) );
check( 'refund after the finalized month appears as correction line in the next Alpha settlement', 1 === count( $ms_corr ) && 'correction' === $ms_corr[0]['kind'] && -2380 === $ms_corr[0]['gross_minor'] && -1 === $ms_corr[0]['tickets'] && $ms_m1 === $ms_corr[0]['origin_period'] );
$ms_beta_lines = MsSettlements::compute( MsPartners::get( $ms_pb ), $ms_now )['lines'];
check( 'Beta settlement is not touched by the Alpha refund', ! array_filter( $ms_beta_lines, static fn( $l ) => (int) $l['refund_id'] > 0 && (int) $l['order_id'] === (int) $ms_map3[ $ms_kb ] ) );

echo "WooCommerce multi-seller: single-seller orders unchanged\n";
$ms_plain = ms_order( 7650, array( array( $ms_tp, 2, '20.00', '1.40' ) ) );
$ms_plain_core = Woo::reserve( $ms_plain );
check( 'single-seller order: one Pass Captain order, no split meta, classic idempotency key', is_int( $ms_plain_core ) && array( $ms_plain_core ) === Woo::core_order_ids( $ms_plain ) && array() === Woo::seller_orders( $ms_plain ) && 2140 === (int) ms_core( $ms_plain_core )->total_minor );

delete_option( SellerSplit::OPTION );
delete_option( Woo::CHECKED_IN_OPTION );
$GLOBALS['wpcap_test_wc'] = ms_cart( array() );
$GLOBALS['wpcap_test_notices'] = array();
