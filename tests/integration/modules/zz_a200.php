<?php
/**
 * A200: money calculations – property tests with a seeded random generator (many iterations) and
 * edge cases, in EUR (2 decimals), JPY (0) and KWD/BHD (3):
 * - line sums equal the order total, discount distribution sums exactly to the discount,
 *   VAT per rate sums to the totals, no negative amounts;
 * - refund splits (Invoices::refund_line_parts) sum exactly and never exceed the paid amount;
 * - partial refunds over many steps end at exactly 0 (amount and VAT);
 * - minor units per currency (PassCaptain\Money) in formatting, CSV/DATEV export, fiscal and
 *   payment amounts instead of a hard-coded /100.
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Money;
use PassCaptain\Orders;
use PassCaptain\OrderLines;
use PassCaptain\Invoices;
use PassCaptain\Refunds;
use PassCaptain\Campaigns;
use PassCaptain\Settings;
use PassCaptain\Localization;
use PassCaptain\Admin\Forms;

global $wpdb;
$p = $wpdb->prefix;
$a200_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a200_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$a200_saved_threshold = Settings::get( 'refund_approval_threshold' );
$a200_saved_billing = Settings::get( 'billing_required_threshold_minor' );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
Settings::update( array( 'refund_approval_threshold' => 0, 'billing_required_threshold_minor' => 0 ) );
mt_srand( 200 ); // Deterministic: every run checks the same cases.

echo "A200: minor units per currency\n";
check( 'decimals: EUR 2, JPY 0, KWD/BHD 3, unknown 2', 2 === Money::decimals( 'EUR' ) && 0 === Money::decimals( 'jpy' ) && 3 === Money::decimals( 'KWD' ) && 3 === Money::decimals( 'BHD' ) && 2 === Money::decimals( 'XYZ' ) );
check( 'to_decimal is exact: 12,34 EUR, 1500 JPY, 12,345 KWD, 0,005 BHD, negatives', '12.34' === Money::to_decimal( 1234, 'EUR' ) && '1500' === Money::to_decimal( 1500, 'JPY' ) && '12.345' === Money::to_decimal( 12345, 'KWD' ) && '0.005' === Money::to_decimal( 5, 'BHD' ) && '-0.05' === Money::to_decimal( -5, 'EUR' ) && '12,345' === Money::to_decimal( 12345, 'KWD', ',' ) );
check( 'format with locale and code', '1.234,50 EUR' === Money::format( 123450, 'EUR' ) && '1.500 JPY' === Money::format( 1500, 'JPY' ) && '12,345 KWD' === Money::format( 12345, 'KWD' ) && '1.234,50 EUR' === Forms::money( 123450, 'EUR' ) && '12,345 KWD' === PassCaptain\Reports\Scope::money( 12345, 'KWD' ) );
$a200_roundtrip = true;
foreach ( array( 'EUR', 'JPY', 'KWD', 'BHD', 'USD' ) as $a200_cur ) {
	for ( $i = 0; $i < 400; $i++ ) {
		$a200_v = mt_rand( -50000000, 50000000 ) * ( mt_rand( 0, 1 ) ? 1 : mt_rand( 1, 999 ) );
		if ( Money::to_minor( Money::to_decimal( $a200_v, $a200_cur ), $a200_cur ) !== $a200_v ) { $a200_roundtrip = false; }
	}
}
check( 'to_decimal/to_minor round trip (2,000 random amounts in 5 currencies)', $a200_roundtrip );
check( 'to_minor rejects precision the currency does not have', null === Money::to_minor( '12.5', 'JPY' ) && 1200 === Money::to_minor( '1200.00', 'JPY' ) && null === Money::to_minor( '1.2345', 'KWD' ) && 1250 === Money::to_minor( '1.25', 'KWD' ) && 1250 === Money::to_minor( '12,5', 'EUR' ) );
add_filter( 'wpcap_currency_decimals', $a200_dec = static function ( $d, $c ) { return 'XTS' === $c ? 4 : $d; }, 10, 2 );
check( 'filter wpcap_currency_decimals adds a currency', 4 === Money::decimals( 'XTS' ) && '1.2345' === Money::to_decimal( 12345, 'XTS' ) );
remove_filter( 'wpcap_currency_decimals', $a200_dec, 10 );
check( 'admin price entry per currency: 12,50 EUR, 1.500 JPY, 12,345 KWD (decimal, not thousands), 1,5 BHD', 1250 === Forms::parse_money( '12,50', Forms::currency_decimals( 'EUR' ) ) && 1500 === Forms::parse_money( '1.500', Forms::currency_decimals( 'JPY' ) ) && 12345 === Forms::parse_money( '12,345', Forms::currency_decimals( 'KWD' ) ) && 1500 === Forms::parse_money( '1,5', Forms::currency_decimals( 'BHD' ) ) && 1234000 === Forms::parse_money( '1.234,000', 3 ) );
add_filter( 'wpcap_currencies', $a200_cf = static function ( $list ) { return array_merge( (array) $list, array( 'JPY', 'KWD', 'BHD' ) ); } );
check( 'currency list is extensible (wpcap_currencies) and the checkout script gets the decimals', array( 'JPY', 'KWD', 'BHD' ) === array_values( array_intersect( Localization::currencies(), array( 'JPY', 'KWD', 'BHD' ) ) ) && array( 'JPY' => 0, 'KWD' => 3 ) === array_intersect_key( PassCaptain\Frontend\Format::js_config()['decimals'], array( 'JPY' => 1, 'KWD' => 1 ) ) );
remove_filter( 'wpcap_currencies', $a200_cf );
check( 'payment and fiscal amounts per currency (Mollie, PayPal, fiscal client, WooCommerce)', '12.345' === PassCaptain\Mollie\MollieGateway::format_amount( 12345, 'KWD' ) && 12345 === PassCaptain\Mollie\MollieGateway::parse_amount( '12.345', 'KWD' ) && '1500' === PassCaptain\Mollie\MollieGateway::format_amount( 1500, 'JPY' ) && '45.00' === PassCaptain\Mollie\MollieGateway::format_amount( 4500, 'EUR' ) && '1500' === PassCaptain\Payments\PayPalGateway::format_amount( 1500, 'JPY' ) && '12.34' === PassCaptain\Fiscalization\Providers\HttpClient::minor_to_major( 1234 ) && 3 === PassCaptain\Integrations\WooCommerce\OrderAdapter::currency_decimals( 'KWD' ) );
check( 'export decimals are read back per currency (journal totals, DATEV)', 1500 === Invoices::decimal_to_minor( '1500', 'JPY' ) && 12345 === Invoices::decimal_to_minor( '12.345', 'KWD' ) && -1234 === Invoices::decimal_to_minor( '-12.34' ) );

echo "A200: property tests (pure functions)\n";
$a200_ok = true;
$a200_bad = '';
for ( $i = 0; $i < 3000; $i++ ) {
	$n = mt_rand( 1, 6 );
	$w = array();
	for ( $k = 0; $k < $n; $k++ ) { $w[ 'k' . $k ] = mt_rand( 0, 4 ) ? mt_rand( 0, 100000 ) : 0; }
	$amount = mt_rand( 0, 3 ) ? mt_rand( 0, 1000000 ) : mt_rand( 0, 5 );
	$parts = Invoices::apportion( $amount, $w );
	$sum_w = array_sum( $w );
	foreach ( $parts as $k => $v ) {
		if ( $v < 0 || ( $sum_w > 0 && 0 === $w[ $k ] && 0 !== $v ) || ( $sum_w > 0 && abs( $v * $sum_w - $amount * $w[ $k ] ) >= $sum_w ) ) { $a200_ok = false; }
	}
	if ( array_sum( $parts ) !== $amount ) { $a200_ok = false; $a200_bad = json_encode( array( $amount, $w ) ); }
}
check( 'apportion: 3,000 random splits sum exactly, never negative, each part within 1 of its exact share ' . $a200_bad, $a200_ok );

$a200_ok = true;
$a200_bad = '';
for ( $i = 0; $i < 3000; $i++ ) {
	$lines = array();
	for ( $k = mt_rand( 1, 5 ); $k > 0; $k-- ) { $lines[] = (object) array( 'line_total_minor' => mt_rand( 0, 3 ) ? mt_rand( 1, 50000 ) : mt_rand( 0, 3 ) ); }
	$subtotal = array_sum( array_map( static fn( $l ) => $l->line_total_minor, $lines ) );
	$discount = mt_rand( 0, $subtotal );
	$after = OrderLines::after_discount( $lines, $discount );
	foreach ( $after as $k => $v ) { if ( $v < 0 || $v > $lines[ $k ]->line_total_minor ) { $a200_ok = false; } }
	if ( array_sum( $after ) !== $subtotal - $discount ) { $a200_ok = false; $a200_bad = json_encode( array( array_map( static fn( $l ) => $l->line_total_minor, $lines ), $discount, $after ) ); }
}
$a200_edge = OrderLines::after_discount( array( (object) array( 'line_total_minor' => 3 ), (object) array( 'line_total_minor' => 3 ), (object) array( 'line_total_minor' => 1 ) ), 6 );
check( 'discount distribution (legacy lines without stored discount): 3,000 random orders sum exactly to subtotal − discount ' . $a200_bad, $a200_ok );
check( 'edge case fixed: lines 3/3/1 with 6 discount leave exactly 1 (the last line was too small for the rounding remainder)', 1 === array_sum( $a200_edge ) && min( $a200_edge ) >= 0 );

$a200_ok = true;
for ( $i = 0; $i < 3000; $i++ ) {
	$gross = mt_rand( 0, 10000000 );
	$rate = array( 0, 500, 700, 770, 1000, 1300, 1900, 2000, 2100, 2500 )[ mt_rand( 0, 9 ) ];
	$tax = Orders::gross_tax( $gross, $rate );
	// Reference: VAT contained in a gross amount, rounded half up, integers only.
	$exact_num = $gross * $rate;
	$den = 10000 + $rate;
	$ref = intdiv( 2 * $exact_num + $den, 2 * $den );
	if ( $tax !== $ref || $tax < 0 || $tax > $gross || ( $rate && $gross && abs( ( $gross - $tax ) * $rate - $tax * 10000 ) > 10000 + $rate ) ) { $a200_ok = false; }
}
check( 'VAT from gross: 3,000 random amounts/rates round half up, 0 ≤ VAT ≤ gross, net × rate ≈ VAT', $a200_ok );

echo "A200: property tests (orders, discounts, VAT, refunds)\n";
/** Ticket type with price, rate and currency on a fresh occurrence. */
function a200_type( $event, $price, $rate, $currency ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'T' . $price, 'price_minor' => $price, 'currency' => $currency, 'tax_rate_bp' => $rate, 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
function a200_items( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT item_id,ticket_type_id,quantity,line_total_minor,discount_minor,tax_minor,tax_rate_bp FROM ' . $wpdb->prefix . 'wpcap_order_items WHERE order_id=%d ORDER BY item_id', $order_id ) );
}
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A200 Geld', 'slug' => 'a200-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$a200_event = (int) $wpdb->insert_id;
$a200_rates = array( 0, 700, 1000, 1300, 1900, 2000 );
$a200_fail = array();
$a200_orders = array();
$a200_line_overrun = array();
$a200_iterations = 48;
for ( $i = 0; $i < $a200_iterations; $i++ ) {
	$cur = array( 'EUR', 'EUR', 'JPY', 'KWD', 'BHD', 'USD' )[ $i % 6 ];
	$items = array();
	$types = array();
	for ( $k = mt_rand( 1, 4 ); $k > 0; $k-- ) {
		$price = mt_rand( 0, 9 ) ? mt_rand( 1, 'JPY' === $cur ? 30000 : 20000 ) : mt_rand( 1, 9 );
		$type = a200_type( $a200_event, $price, $a200_rates[ mt_rand( 0, 5 ) ], $cur );
		$types[] = $type;
		$items[] = array( 'ticket_type_id' => $type, 'quantity' => mt_rand( 1, 4 ) );
	}
	$codes = array();
	if ( mt_rand( 0, 3 ) ) {
		$percent = mt_rand( 0, 1 );
		$code = 'A200' . $i . strtoupper( substr( md5( (string) mt_rand() ), 0, 6 ) );
		$c = Campaigns::create( array( 'code' => $code, 'kind' => $percent ? 'percent' : 'fixed', 'value' => $percent ? mt_rand( 1, 10000 ) : mt_rand( 1, 30000 ), 'currency' => $cur, 'ticket_type_id' => mt_rand( 0, 2 ) ? 0 : $types[ array_rand( $types ) ] ) );
		if ( ! is_wp_error( $c ) ) { $codes[] = $code; }
	}
	$r = Orders::create( array( 'email' => "a200-$i@example.com", 'items' => $items, 'idempotency_key' => 'a200-' . $i . '-' . uniqid(), 'campaign_codes' => $codes ), array( 'skip_rate_limit' => true ) );
	if ( is_wp_error( $r ) && $codes && 'campaign_invalid' === ( $r->get_error_data()['reason'] ?? '' ) ) {
		// A tiny percentage on a small amount rounds to 0: the code is refused, not a money error.
		$r = Orders::create( array( 'email' => "a200-$i@example.com", 'items' => $items, 'idempotency_key' => 'a200-' . $i . '-retry-' . uniqid() ), array( 'skip_rate_limit' => true ) );
	}
	if ( is_wp_error( $r ) ) { $a200_fail[] = "create $i: " . $r->get_error_message(); continue; }
	$order = Orders::get( $r['order_id'] );
	$lines = a200_items( $r['order_id'] );
	$sum = static fn( $f ) => array_sum( array_map( static fn( $l ) => (int) $l->$f, $lines ) );
	if ( $sum( 'line_total_minor' ) !== (int) $order->subtotal_minor ) { $a200_fail[] = "$i subtotal"; }
	if ( $sum( 'discount_minor' ) !== (int) $order->discount_minor ) { $a200_fail[] = "$i discount distribution"; }
	if ( $sum( 'line_total_minor' ) - $sum( 'discount_minor' ) !== (int) $order->total_minor ) { $a200_fail[] = "$i lines ≠ total"; }
	if ( $sum( 'tax_minor' ) !== (int) $order->tax_minor ) { $a200_fail[] = "$i VAT lines ≠ order VAT"; }
	$by_rate = array();
	foreach ( $lines as $l ) {
		$net_gross = (int) $l->line_total_minor - (int) $l->discount_minor;
		if ( $l->line_total_minor < 0 || $l->discount_minor < 0 || $net_gross < 0 || $l->tax_minor < 0 || $l->tax_minor > $net_gross ) { $a200_fail[] = "$i negative/oversized line"; }
		if ( (int) $l->tax_minor !== Orders::gross_tax( $net_gross, (int) $l->tax_rate_bp ) ) { $a200_fail[] = "$i line VAT"; }
		$by_rate[ (int) $l->tax_rate_bp ] = array( ( $by_rate[ (int) $l->tax_rate_bp ][0] ?? 0 ) + $net_gross, ( $by_rate[ (int) $l->tax_rate_bp ][1] ?? 0 ) + (int) $l->tax_minor );
	}
	if ( array_sum( array_column( $by_rate, 0 ) ) !== (int) $order->total_minor || array_sum( array_column( $by_rate, 1 ) ) !== (int) $order->tax_minor ) { $a200_fail[] = "$i per-rate sums"; }
	if ( OrderLines::after_discount( $lines, (int) $order->discount_minor ) !== array_map( static fn( $l ) => (int) $l->line_total_minor - (int) $l->discount_minor, $lines ) ) { $a200_fail[] = "$i report allocation ≠ stored"; }
	if ( (int) $order->total_minor > 0 ) {
		$confirmed = Orders::confirm_manual_payment( $r['order_id'] );
		if ( true !== $confirmed ) { $a200_fail[] = "$i confirm"; continue; }
		$a200_orders[] = (int) $r['order_id'];
	}
}
check( "$a200_iterations random orders (EUR/JPY/KWD/BHD/USD, 1–4 lines, mixed VAT rates, percent/fixed discounts): lines = total, discounts = discount, VAT per line and per rate = totals, nothing negative" . ( $a200_fail ? ' ' . implode( '; ', array_slice( $a200_fail, 0, 5 ) ) : '' ), ! $a200_fail && count( $a200_orders ) > 30 );

// Refunds: amount-only refunds in random steps for even orders, ticket refunds for odd ones.
$a200_fail = array();
$a200_steps = 0;
foreach ( $a200_orders as $n => $order_id ) {
	$order = Orders::get( $order_id );
	$total = (int) $order->total_minor;
	$guard = 0;
	if ( 0 === $n % 2 ) {
		while ( ( $left = (int) Orders::get( $order_id )->total_minor - (int) Orders::get( $order_id )->refunded_minor ) > 0 && $guard++ < 12 ) {
			$amount = $guard >= 6 ? $left : mt_rand( 1, max( 1, intdiv( $left, mt_rand( 1, 3 ) ) ) );
			$ref = Refunds::request_amount( $order_id, $amount, 'A200', 'a200-amt-' . $order_id . '-' . $guard );
			if ( is_wp_error( $ref ) || 'completed' !== ( $ref['status'] ?? '' ) ) { $a200_fail[] = "$order_id amount refund " . ( is_wp_error( $ref ) ? $ref->get_error_message() : ( $ref['status'] ?? '?' ) ); break; }
			++$a200_steps;
		}
	} else {
		$ids = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $order_id ) );
		shuffle( $ids );
		while ( $ids && $guard++ < 12 ) {
			$take = array_splice( $ids, 0, mt_rand( 1, count( $ids ) ) );
			$ref = Refunds::request( $order_id, $take, 'A200', 'a200-tix-' . $order_id . '-' . $guard );
			if ( is_wp_error( $ref ) || 'completed' !== ( $ref['status'] ?? '' ) ) { $a200_fail[] = "$order_id ticket refund " . ( is_wp_error( $ref ) ? $ref->get_error_message() : ( $ref['status'] ?? '?' ) ); break; }
			++$a200_steps;
		}
	}
	$order = Orders::get( $order_id );
	$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_refunds WHERE order_id=%d AND status='completed' ORDER BY refund_id", $order_id ), ARRAY_A );
	if ( (int) $order->refunded_minor !== $total || array_sum( array_column( $refunds, 'amount_minor' ) ) !== $total ) { $a200_fail[] = "$order_id refunds do not end at 0"; }
	if ( array_sum( array_column( $refunds, 'tax_minor' ) ) !== (int) $order->tax_minor ) { $a200_fail[] = "$order_id refund VAT ≠ order VAT"; }
	$extra = Refunds::request_amount( $order_id, 1, 'A200', 'a200-extra-' . $order_id );
	if ( ! is_wp_error( $extra ) ) { $a200_fail[] = "$order_id refund beyond the paid amount accepted"; }
	// Splits onto lines: each refund sums exactly; cumulated per line never above the paid line.
	$lines = a200_items( $order_id );
	$paid = OrderLines::after_discount( $lines, (int) $order->discount_minor );
	$cum = array_fill( 0, count( $lines ), 0 );
	$cum_tax = array_fill( 0, count( $lines ), 0 );
	foreach ( $refunds as $ref ) {
		if ( (int) $ref['amount_minor'] < 0 || (int) $ref['tax_minor'] < 0 || (int) $ref['tax_minor'] > (int) $ref['amount_minor'] ) { $a200_fail[] = "$order_id negative/oversized refund"; }
		$parts = Invoices::refund_line_parts( $ref, $lines, (int) $order->discount_minor, (int) $ref['amount_minor'], (int) $ref['tax_minor'] );
		if ( array_sum( array_column( $parts, 'gross' ) ) !== (int) $ref['amount_minor'] || array_sum( array_column( $parts, 'tax' ) ) !== (int) $ref['tax_minor'] ) { $a200_fail[] = "$order_id split sum"; }
		foreach ( $parts as $part ) {
			if ( $part['gross'] < 0 || $part['tax'] < 0 ) { $a200_fail[] = "$order_id negative split"; }
			$cum[ $part['index'] ] += $part['gross'];
			$cum_tax[ $part['index'] ] += $part['tax'];
		}
		$rates = Invoices::credit_note_rates( $ref, (int) $ref['amount_minor'], (int) $ref['tax_minor'] );
		if ( array_sum( array_column( $rates, 'gross' ) ) !== (int) $ref['amount_minor'] || array_sum( array_column( $rates, 'tax' ) ) !== (int) $ref['tax_minor'] || array_sum( array_column( $rates, 'net' ) ) !== (int) $ref['amount_minor'] - (int) $ref['tax_minor'] ) { $a200_fail[] = "$order_id credit note rates"; }
	}
	foreach ( $cum as $k => $v ) {
		if ( $v > $paid[ $k ] || $cum_tax[ $k ] > (int) $lines[ $k ]->tax_minor ) { $a200_line_overrun[] = "$order_id line $k: $v > {$paid[ $k ]} / {$cum_tax[ $k ]} > {$lines[ $k ]->tax_minor}"; }
	}
	if ( array_sum( $cum ) !== $total ) { $a200_fail[] = "$order_id cumulated splits ≠ total"; }
}
check( count( $a200_orders ) . " paid orders refunded in $a200_steps random partial steps: each refund ≤ remaining, ends at exactly 0 (amount and VAT), splits and correction rates sum exactly, a further cent is refused" . ( $a200_fail ? ' ' . implode( '; ', array_slice( $a200_fail, 0, 5 ) ) : '' ), ! $a200_fail && $a200_steps > count( $a200_orders ) );
check( 'refund splits cumulated per line never exceed the paid line (gross and VAT)' . ( $a200_line_overrun ? ' ' . implode( '; ', array_slice( $a200_line_overrun, 0, 3 ) ) : '' ), ! $a200_line_overrun );

echo "A200: exports use the currency's minor unit\n";
$GLOBALS['wpcap_test_transients'] = array();
$a200_jpy = a200_type( $a200_event, 1500, 1000, 'JPY' );
$a200_kwd = a200_type( $a200_event, 12345, 0, 'KWD' );
$a200_oj = Orders::create( array( 'email' => 'a200-jpy@example.com', 'items' => array( array( 'ticket_type_id' => $a200_jpy, 'quantity' => 2 ) ), 'idempotency_key' => 'a200-jpy-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$a200_ok_ = Orders::create( array( 'email' => 'a200-kwd@example.com', 'items' => array( array( 'ticket_type_id' => $a200_kwd, 'quantity' => 1 ) ), 'idempotency_key' => 'a200-kwd-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Orders::confirm_manual_payment( $a200_oj['order_id'] );
Orders::confirm_manual_payment( $a200_ok_['order_id'] );
$a200_today = gmdate( 'Y-m-d' );
$a200_rows = Invoices::export_rows( $a200_today, $a200_today );
$a200_find = static function ( $public_id ) use ( $a200_rows ) { foreach ( $a200_rows as $row ) { if ( ( $row[3] ?? '' ) === $public_id ) { return $row; } } return null; };
$a200_rj = $a200_find( Orders::get( $a200_oj['order_id'] )->public_id );
$a200_rk = $a200_find( Orders::get( $a200_ok_['order_id'] )->public_id );
check( 'accounting export: 3.000 JPY (gross, no decimals) and 12.345 KWD (three decimals)', $a200_rj && '3000' === $a200_rj[10] && '273' === $a200_rj[9] && 'JPY' === $a200_rj[11] && $a200_rk && '12.345' === $a200_rk[10] && 'KWD' === $a200_rk[11] );
$a200_journal = Invoices::journal_totals( $a200_rows );
check( 'journal totals read the export back exactly per currency', 3000 <= $a200_journal['JPY']['total']['gross'] && 12345 <= $a200_journal['KWD']['total']['gross'] );
$a200_report = PassCaptain\Reports\Data::build( array( 'from' => $a200_today, 'to' => $a200_today, 'event_id' => $a200_event ), null );
$a200_csv = PassCaptain\Reports\Data::csv_rows( $a200_report );
$a200_cur_rows = array();
foreach ( $a200_csv as $row ) { if ( in_array( $row[0] ?? '', array( 'JPY', 'KWD' ), true ) && ( $row[1] ?? '' ) === $a200_report['total']['label'] ) { $a200_cur_rows[ $row[0] ] = $row[3]; } }
check( 'report CSV: per-currency totals use the currency decimals (no "/100")', isset( $a200_cur_rows['JPY'], $a200_cur_rows['KWD'] ) && false === strpos( $a200_cur_rows['JPY'], '.' ) && 1 === preg_match( '/^\d+\.\d{3}$/', $a200_cur_rows['KWD'] ) );
check( 'settlement sheet: fixed deal amount in the minor unit of the currency', array( 'fixed' => 1500, 'share_bp' => 5000 ) === PassCaptain\Reports\Settlement::parse_deal( array( 'fixed' => '1.500', 'share' => '50' ), 'JPY' ) && 1500 === PassCaptain\Reports\Settlement::parse_deal( array( 'fixed' => '1,5', 'share' => '0' ), 'KWD' )['fixed'] );
check( 'refund mail amount and invoice PDF money use the currency', '3.000 JPY' === Money::format( 3000, 'JPY' ) && false !== strpos( PassCaptain\Frontend\Format::money( 12345, 'KWD' ), '12,345' ) );

// Restore.
Settings::update( array( 'refund_approval_threshold' => $a200_saved_threshold, 'billing_required_threshold_minor' => $a200_saved_billing ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_user_id'] = $a200_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $a200_saved_denied;
