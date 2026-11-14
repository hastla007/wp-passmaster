<?php
/**
 * Money property tests (W6), seeded and deterministic.
 *
 *  - Orders::allocate(): exact proportional split for any amounts (tiny lines, totals above the sum).
 *  - Currencies with 0 (JPY), 2 (EUR) and 3 (KWD/BHD) minor-unit digits: parsing, display, WooCommerce.
 *  - Random orders (1–3 lines, mixed VAT rates, quantities, order-wide / percent / type-restricted
 *    campaigns) in EUR, JPY and KWD: subtotal, discount split, per-line VAT, invoice lines and a random
 *    sequence of partial refunds must all reconcile to the minor unit.
 *
 * WPCAP_TEST_SLOW=1 runs ten times as many random cases.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Invoices;
use PassCaptain\Campaigns;
use PassCaptain\Localization;
use PassCaptain\Admin\Forms;
use PassCaptain\Frontend\Format;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as W6Woo;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$w6m_scale = getenv( 'WPCAP_TEST_SLOW' ) ? 10 : 1;
mt_srand( 4711 );

/* ------------------------------------------------------------------------------------------ */
echo "W6 money: exact allocation\n";
$bad = array();
for ( $i = 0; $i < 2000 * $w6m_scale; $i++ ) {
	$n = mt_rand( 1, 6 );
	$amounts = array();
	for ( $j = 0; $j < $n; $j++ ) { $amounts[ 'k' . $j ] = mt_rand( 0, 3 ) ? mt_rand( 0, 100000 ) : mt_rand( 0, 3 ); }
	$sum = array_sum( $amounts );
	$total = mt_rand( 0, $sum + 50 );
	$parts = Orders::allocate( $amounts, $total );
	$ok = array_sum( $parts ) === min( $total, $sum ) && array_keys( $parts ) === array_keys( $amounts );
	foreach ( $parts as $k => $part ) { $ok = $ok && $part >= 0 && $part <= $amounts[ $k ]; }
	if ( ! $ok ) { $bad[] = json_encode( array( $amounts, $total, $parts ) ); }
}
check( ( 2000 * $w6m_scale ) . ' random splits add up exactly and never exceed a line', ! $bad );
foreach ( array_slice( $bad, 0, 3 ) as $b ) { echo "         $b\n"; }
check( 'tiny last line: discount 2 over [1,1,1] is fully allocated', array( 0, 1, 1 ) === array_values( Orders::allocate( array( 1, 1, 1 ), 2 ) ) );

/* ------------------------------------------------------------------------------------------ */
echo "W6 money: currencies with 0, 2 and 3 decimals\n";
check( 'minor-unit digits: JPY 0, EUR 2, KWD/BHD 3 (admin forms, WooCommerce and display agree)', 0 === Localization::currency_decimals( 'JPY' ) && 2 === Localization::currency_decimals( 'eur' ) && 3 === Localization::currency_decimals( 'KWD' ) && 3 === Forms::currency_decimals( 'BHD' ) && 0 === W6Woo::currency_decimals( 'JPY' ) && 3 === W6Woo::currency_decimals( 'KWD' ) );
check( 'display: 1500 JPY, 12,345 KWD, 12,34 EUR (minor units)', '1.500 JPY' === Format::money( 1500, 'JPY' ) && '12,345 KWD' === Format::money( 12345, 'KWD' ) && '12,34 €' === Format::money( 1234, 'EUR' ) );
check( 'admin price entry for KWD keeps three decimals (12,345 → 12345 fils)', 12345 === Forms::parse_money( '12,345', Forms::currency_decimals( 'KWD' ) ) && 1500 === Forms::parse_money( '1.500', Forms::currency_decimals( 'JPY' ) ) );
$round = true;
foreach ( array( 'JPY', 'EUR', 'KWD' ) as $cur ) {
	$d = Forms::currency_decimals( $cur );
	for ( $i = 0; $i < 200; $i++ ) { $v = mt_rand( 0, 99999999 ); if ( $v !== Forms::parse_money( Forms::money_input( $v, $d ), $d ) ) { $round = false; } }
}
check( 'admin money input/parse round-trips every amount in JPY, EUR and KWD', $round );

/** Ticket type in $currency with price and VAT rate. */
function w6m_type( $price, $rate, $currency, $capacity = 0 ) {
	global $wpdb;
	$type = make_type( $capacity, 50, $price );
	$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", array( 'currency' => $currency, 'tax_rate_bp' => $rate ), array( 'ticket_type_id' => $type ) );
	return $type;
}
/** WooCommerce order fake in another currency. */
function w6m_wc_order( $id, $currency ) {
	return new class( $id, $currency ) extends WC_Order {
		public function __construct( int $id, public string $cur ) { parent::__construct( $id ); }
		public function get_currency() { return $this->cur; }
	};
}
$woo_ok = true;
$woo_cases = array();
$wc_id = 70000;
foreach ( array( 'JPY' => 1, 'EUR' => 100, 'KWD' => 1000 ) as $cur => $factor ) {
	for ( $i = 0; $i < 5; $i++ ) {
		$qty = mt_rand( 1, 4 );
		$net_minor = mt_rand( 1, 50000 ) * $qty;
		$tax_minor = intdiv( $net_minor * 20, 100 );
		$fmt = static fn( $minor ) => number_format( $minor / $factor, (int) log10( $factor ), '.', '' );
		$type = w6m_type( 1, 0, $cur );
		$wc = w6m_wc_order( ++$wc_id, $cur );
		$product = new WC_Product( 80000 + $wc_id );
		$product->update_meta_data( W6Woo::PRODUCT_META, $type );
		$GLOBALS['wpcap_test_products'][ 80000 + $wc_id ] = $product;
		$wc->items[] = new WC_Order_Item_Product( 90000 + $wc_id, $product, $qty, $fmt( $net_minor ), $fmt( $tax_minor ) );
		$core = W6Woo::reserve( $wc );
		$order = is_int( $core ) ? Orders::get( $core ) : null;
		$line = $order ? (int) $wpdb->get_var( "SELECT line_total_minor FROM {$p}wpcap_order_items WHERE order_id=" . (int) $core ) : -1;
		$woo_cases[] = "$cur " . $fmt( $net_minor ) . '+' . $fmt( $tax_minor ) . " → $line";
		if ( ! $order || $net_minor + $tax_minor !== (int) $order->total_minor || $net_minor + $tax_minor !== $line || $cur !== $order->currency ) { $woo_ok = false; }
	}
}
check( 'WooCommerce line totals in JPY/EUR/KWD become exact core minor units (' . $woo_cases[0] . ', ' . end( $woo_cases ) . ')', $woo_ok );

/* ------------------------------------------------------------------------------------------ */
echo "W6 money: random orders, discounts, VAT, invoices and partial refunds\n";
$rates = array( 0, 500, 700, 1000, 1300, 1900, 2000, 2100 );
$violations = array();
$cases = 40 * $w6m_scale;
$stats = array( 'discounted' => 0, 'restricted' => 0, 'refunds' => 0 );
for ( $c = 0; $c < $cases; $c++ ) {
	$cur = array( 'EUR', 'JPY', 'KWD' )[ $c % 3 ];
	$lines = array();
	$types = array();
	for ( $l = 0, $n = mt_rand( 1, 3 ); $l < $n; $l++ ) {
		$price = mt_rand( 0, 4 ) ? mt_rand( 1, 30000 ) : mt_rand( 1, 9 );
		$type = w6m_type( $price, $rates[ mt_rand( 0, count( $rates ) - 1 ) ], $cur );
		$types[] = $type;
		$lines[] = array( 'ticket_type_id' => $type, 'quantity' => mt_rand( 1, 4 ) );
	}
	$subtotal_expected = 0;
	foreach ( $lines as $line ) { $subtotal_expected += (int) $wpdb->get_var( "SELECT price_minor FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $line['ticket_type_id'] ) * $line['quantity']; }
	$codes = array();
	$restricted_type = 0;
	$kind = mt_rand( 0, 3 );
	if ( $kind ) {
		$code = 'W6M' . $c . strtoupper( substr( uniqid(), -5 ) );
		$campaign = array( 'code' => $code, 'currency' => $cur, 'kind' => 1 === $kind ? 'percent' : 'fixed', 'value' => 1 === $kind ? mt_rand( 100, 9000 ) : mt_rand( 1, max( 1, $subtotal_expected ) ) );
		if ( 3 === $kind && count( $types ) > 1 ) { $restricted_type = $types[ mt_rand( 0, count( $types ) - 1 ) ]; $campaign['ticket_type_id'] = $restricted_type; $campaign['value'] = 1; }
		Campaigns::create( $campaign );
		$codes[] = $code;
	}
	$GLOBALS['wpcap_test_transients'] = array();
	$o = Orders::create( array( 'email' => "w6m$c@example.com", 'items' => $lines, 'idempotency_key' => "w6m-$c-" . uniqid(), 'campaign_codes' => $codes ), array( 'skip_rate_limit' => true ) );
	if ( is_wp_error( $o ) ) { $violations[] = "case $c: order failed: " . $o->get_error_message(); continue; }
	$oid = (int) $o['order_id'];
	Orders::confirm_manual_payment( $oid );
	$order = Orders::get( $oid );
	$items = $wpdb->get_results( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=$oid ORDER BY item_id" );
	$split = (array) Orders::snapshot_line_discounts( $order );
	$sum_lines = 0;
	$sum_tax = 0;
	$sum_disc = 0;
	$v = array();
	foreach ( $items as $item ) {
		$disc = (int) ( $split[ (int) $item->ticket_type_id ] ?? 0 );
		$sum_lines += (int) $item->line_total_minor;
		$sum_tax += (int) $item->tax_minor;
		$sum_disc += $disc;
		if ( $disc > (int) $item->line_total_minor ) { $v[] = 'line discount above line total'; }
		if ( (int) $item->tax_minor !== Orders::gross_tax( (int) $item->line_total_minor - $disc, (int) $item->tax_rate_bp ) ) { $v[] = 'line VAT not computed on the discounted line amount'; }
		if ( $restricted_type && $disc && (int) $item->ticket_type_id !== $restricted_type ) { $v[] = 'type-restricted discount reduced another line'; }
	}
	if ( $sum_lines !== (int) $order->subtotal_minor || $sum_lines !== $subtotal_expected ) { $v[] = "subtotal $order->subtotal_minor != lines $sum_lines"; }
	if ( (int) $order->total_minor !== (int) $order->subtotal_minor - (int) $order->discount_minor ) { $v[] = 'total != subtotal - discount'; }
	if ( $sum_tax !== (int) $order->tax_minor ) { $v[] = 'order VAT != sum of line VAT'; }
	if ( (int) $order->discount_minor && $sum_disc !== (int) $order->discount_minor ) { $v[] = "discount split $sum_disc != discount $order->discount_minor"; }
	if ( (int) $order->discount_minor ) { ++$stats['discounted']; }
	if ( $restricted_type ) { ++$stats['restricted']; }
	// Invoice.
	$inv = Invoices::issue_for_order( $oid );
	if ( is_wp_error( $inv ) ) { $v[] = 'invoice failed: ' . $inv->get_error_message(); }
	else {
		$il = $wpdb->get_row( "SELECT COALESCE(SUM(total_minor),0) AS total, COALESCE(SUM(tax_minor),0) AS tax, COALESCE(SUM(discount_minor),0) AS disc FROM {$p}wpcap_invoice_lines WHERE invoice_id=" . (int) $inv['invoice_id'] );
		if ( (int) $il->total !== (int) $order->total_minor || (int) $il->tax !== (int) $order->tax_minor || (int) $il->disc !== (int) $order->discount_minor ) { $v[] = "invoice lines total/tax/discount $il->total/$il->tax/$il->disc != order $order->total_minor/$order->tax_minor/$order->discount_minor"; }
	}
	// Random partial refunds until everything is refunded.
	$ids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $oid ) );
	shuffle( $ids );
	$groups = array();
	while ( $ids ) { $groups[] = array_splice( $ids, 0, mt_rand( 1, max( 1, count( $ids ) ) ) ); }
	$refunded = 0;
	$refund_tax = 0;
	foreach ( $groups as $g => $group ) {
		$r = Refunds::request( $oid, $group, 'w6', "w6m-$c-$g" );
		if ( is_wp_error( $r ) || 'completed' !== $r['status'] ) { $v[] = 'refund failed: ' . ( is_wp_error( $r ) ? $r->get_error_message() : $r['status'] ); break; }
		++$stats['refunds'];
		$refunded += (int) $r['amount_minor'];
		$refund_tax += (int) $r['tax_minor'];
		if ( (int) $r['tax_minor'] > (int) $r['amount_minor'] ) { $v[] = 'refund VAT above refund amount'; }
		if ( $g < count( $groups ) - 1 ) {
			// A partial refund returns the discounted price of exactly these tickets.
			$expected = 0.0;
			foreach ( $group as $tid ) {
				$t = (int) $wpdb->get_var( "SELECT ticket_type_id FROM {$p}wpcap_tickets WHERE ticket_id=$tid" );
				foreach ( $items as $item ) { if ( (int) $item->ticket_type_id === $t ) { $expected += ( (int) $item->line_total_minor - (int) ( $split[ $t ] ?? 0 ) ) / (int) $item->quantity; } }
			}
			if ( abs( (int) $r['amount_minor'] - $expected ) > 0.5 ) { $v[] = "partial refund {$r['amount_minor']} != discounted ticket price " . round( $expected, 2 ); }
		}
	}
	$order = Orders::get( $oid );
	if ( $refunded !== (int) $order->total_minor || (int) $order->refunded_minor !== (int) $order->total_minor ) { $v[] = "refunds $refunded / refunded_minor $order->refunded_minor != total $order->total_minor"; }
	if ( $refund_tax !== (int) $order->tax_minor ) { $v[] = "refund VAT $refund_tax != order VAT $order->tax_minor"; }
	if ( 'refunded' !== $order->status ) { $v[] = 'order not refunded after refunding every ticket'; }
	foreach ( array_unique( $v ) as $msg ) { $violations[] = "case $c ($cur, " . count( $lines ) . ' lines, discount ' . (int) $order->discount_minor . "): $msg"; }
}
check( "$cases random orders ({$stats['discounted']} discounted, {$stats['restricted']} type-restricted, {$stats['refunds']} refunds) reconcile to the minor unit", ! $violations );
foreach ( array_slice( $violations, 0, 8 ) as $v ) { echo "         $v\n"; }
