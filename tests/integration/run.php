<?php
/**
 * Integration tests for order holds, payment confirmation and the WooCommerce adapter.
 *
 * Usage: php tests/integration/run.php   (requires a disposable MySQL/MariaDB database)
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

use PassCaptain\Orders;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

$failures = 0;
$passes   = 0;
function check( $name, $condition ) {
	global $failures, $passes;
	if ( $condition ) { $passes++; echo "  ok   $name\n"; } else { $failures++; echo "  FAIL $name\n"; }
}

function make_type( $capacity, $max = 10, $price = 2500, $occurrence_capacity = 0 ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Konzert', 'slug' => 'e' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => $occurrence_capacity, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => $max, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}

function make_wc_order( $id, $type_id, $qty, $line_total = '50.00', $tax = '0' ) {
	static $item_id = 1000, $product_id = 500;
	$product = new WC_Product( ++$product_id );
	$product->update_meta_data( Woo::PRODUCT_META, $type_id );
	$GLOBALS['wpcap_test_products'][ $product_id ] = $product;
	$order = new WC_Order( $id );
	$order->items[] = new WC_Order_Item_Product( ++$item_id, $product, $qty, $line_total, $tax );
	return $order;
}

function tickets( $core_id, $status = null ) {
	return array_values( array_filter( Orders::tickets_for_order( $core_id ), static fn( $t ) => null === $status || $t->status === $status ) );
}

function expire_holds_of( $core_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $core_id ) );
}

if ( in_array( '--child', $argv, true ) ) {
	// Concurrency worker: buy one ticket of the given type and print the result.
	$type = (int) $argv[2];
	$r = Orders::create( array( 'email' => 'c' . getmypid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'child-' . getmypid() ) );
	echo is_wp_error( $r ) ? 'ERR' : 'OK';
	exit;
}

wpcap_test_install();
global $wpdb;
$p = $wpdb->prefix;

echo "Native checkout\n";
$type = make_type( 5 );
$r = Orders::create( array( 'email' => 'native@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'native-1' ) );
check( 'paid native order is created with a hold', ! is_wp_error( $r ) && 'pending' === $r['payment_status'] && 10 === $r['hold_minutes'] );
check( 'availability reflects the hold', 3 === Orders::availability( $type ) );
$again = Orders::create( array( 'email' => 'native@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'native-1' ) );
check( 'same idempotency key reuses the order', ! is_wp_error( $again ) && ! empty( $again['reused'] ) && $again['order_id'] === $r['order_id'] );
$free = make_type( 2, 10, 0 );
$fr = Orders::create( array( 'email' => 'free@example.com', 'items' => array( array( 'ticket_type_id' => $free, 'quantity' => 2 ) ), 'idempotency_key' => 'free-1' ) );
check( 'free native order issues tickets immediately', ! is_wp_error( $fr ) && 2 === count( tickets( $fr['order_id'], 'valid' ) ) );

echo "WooCommerce: hold, payment, idempotent issuance\n";
$type = make_type( 3, 4 );
$wc = make_wc_order( 1, $type, 2, '40.00', '8.00' );
$core = Woo::reserve( $wc );
check( 'hold placed at checkout', is_int( $core ) && $core > 0 && 1 === Orders::availability( $type ) );
check( 'core order stays pending until paid', 'pending' === Orders::get( $core )->payment_status && ! tickets( $core ) );
check( 'Woo price incl. tax is snapshotted', 2400 === (int) $wpdb->get_var( "SELECT unit_price_minor FROM {$p}wpcap_order_items WHERE order_id=$core" ) );
check( 'no access-link mail before payment', 0 === count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'buyer@example.com' === $m['to'] ) ) );
Woo::sync_paid_order( 1 );
Woo::sync_paid_order( 1 );
Woo::sync_paid_order( 1 );
check( 'exactly one ticket per unit after repeated paid hooks', 2 === count( tickets( $core ) ) );
check( 'order marked paid', 'paid' === Orders::get( $core )->payment_status );
check( 'sold units still counted', 1 === Orders::availability( $type ) );
$buyer_mails = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'buyer@example.com' === $m['to'] ) );
check( 'ticket mail and access link sent once each', 2 === count( $buyer_mails ) );

echo "WooCommerce: cart validation and oversell protection\n";
check( 'cart check rejects quantity above availability', '' !== Woo::quantity_error( $type, 2 ) );
check( 'cart check accepts remaining quantity', '' === Woo::quantity_error( $type, 1 ) );
check( 'cart check enforces max per order', '' !== Woo::quantity_error( make_type( 100, 4 ), 5 ) );
$wc2 = make_wc_order( 2, $type, 2 );
$threw = false;
try { Woo::reserve_classic( 2, array(), $wc2 ); } catch ( Exception $e ) { $threw = true; }
check( 'classic checkout aborts when tickets are gone', $threw && ! $wc2->get_meta( Woo::ORDER_META ) );

echo "WooCommerce: checkout retry does not double-hold\n";
$type = make_type( 2 );
$wc3 = make_wc_order( 3, $type, 2 );
$first = Woo::reserve( $wc3 );
$second = Woo::reserve( $wc3 );
check( 'retry creates a new attempt', is_int( $second ) && $second !== $first );
check( 'previous attempt cancelled, capacity held once', 'cancelled' === Orders::get( $first )->status && 0 === Orders::availability( $type ) );

echo "WooCommerce: late payment recovery\n";
$type = make_type( 2 );
$wc4 = make_wc_order( 4, $type, 1 );
$core4 = Woo::reserve( $wc4 );
expire_holds_of( $core4 );
Woo::sync_paid_order( 4 );
check( 'expired hold re-allocated when capacity is free', 1 === count( tickets( $core4 ) ) && 'paid' === Orders::get( $core4 )->payment_status );
$wc5 = make_wc_order( 5, $type, 1 );
$core5 = Woo::reserve( $wc5 );
expire_holds_of( $core5 );
$wc6 = make_wc_order( 6, $type, 1 );
$core6 = Woo::reserve( $wc6 ); // takes the last ticket while order 5 is unpaid and expired
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
Woo::sync_paid_order( 5 );
check( 'late payment without capacity is flagged, not overbooked', 'needs_review' === Orders::get( $core5 )->status && ! tickets( $core5 ) );
check( 'admin is notified about unallocated payment', count( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $mails_before ), static fn( $m ) => 'admin@example.com' === $m['to'] ) ) === 1 );
check( 'order note explains missing tickets', (bool) preg_grep( '/Keine Tickets/', $wc5->notes ) );
Woo::sync_paid_order( 6 );
check( 'the in-time order still gets its ticket', 1 === count( tickets( $core6 ) ) && 0 === Orders::availability( $type ) );

echo "WooCommerce: on-hold, failed and cancelled\n";
$type = make_type( 5 );
$wc7 = make_wc_order( 7, $type, 1 );
$core7 = Woo::reserve( $wc7 );
Woo::sync_on_hold_order( 7 );
$exp = $wpdb->get_var( "SELECT expires_at FROM {$p}wpcap_reservations WHERE order_id=$core7 AND status='active'" );
check( 'on-hold extends the hold to days', strtotime( $exp . ' UTC' ) > time() + 2 * 86400 );
Woo::sync_cancelled_order( 7 );
check( 'failed/cancelled releases the hold', 'cancelled' === Orders::get( $core7 )->status && 5 === Orders::availability( $type ) );
Woo::sync_paid_order( 7 );
$core7b = absint( $wc7->get_meta( Woo::ORDER_META ) );
check( 'payment after failed attempt re-reserves and issues', $core7b !== $core7 && 1 === count( tickets( $core7b, 'valid' ) ) );

echo "WooCommerce: refunds\n";
$type = make_type( 10 );
$wc8 = make_wc_order( 8, $type, 3, '75.00' );
$core8 = Woo::reserve( $wc8 );
Woo::sync_paid_order( 8 );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE order_id=$core8 ORDER BY ticket_id ASC LIMIT 1" );
$refund = new WC_Order( 9001 );
$ri = new WC_Order_Item_Product( 9101, $wc8->items[0]->get_product(), -1, '-25.00' );
$ri->update_meta_data( '_refunded_item_id', $wc8->items[0]->get_id() );
$refund->items[] = $ri;
Woo::sync_refund( 8, 9001 );
Woo::sync_refund( 8, 9001 );
check( 'partial refund revokes exactly the refunded quantity (idempotent)', 1 === count( tickets( $core8, 'revoked' ) ) && 1 === count( tickets( $core8, 'valid' ) ) );
Woo::sync_fully_refunded_order( 8 );
check( 'full refund revokes remaining valid tickets, keeps check-in history', 2 === count( tickets( $core8, 'revoked' ) ) && 1 === count( tickets( $core8, 'checked_in' ) ) );
check( 'full refund frees capacity', 'refunded' === Orders::get( $core8 )->status && 10 === Orders::availability( $type ) );

echo "Admin-created Woo order without checkout hold\n";
$type = make_type( 5 );
$wc10 = make_wc_order( 10, $type, 2 );
Woo::sync_paid_order( 10 );
$core10 = absint( $wc10->get_meta( Woo::ORDER_META ) );
check( 'paid order without hold reserves and issues', $core10 && 2 === count( tickets( $core10 ) ) );

echo "Concurrency: parallel checkouts never oversell\n";
$type = make_type( 5 );
$procs = array();
for ( $i = 0; $i < 20; $i++ ) {
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child ' . $type, 'r' );
}
$ok = 0;
foreach ( $procs as $proc ) { $ok += 'OK' === trim( stream_get_contents( $proc ) ) ? 1 : 0; pclose( $proc ); }
$held = (int) $wpdb->get_var( "SELECT SUM(quantity) FROM {$p}wpcap_reservations WHERE ticket_type_id=$type AND status='active'" );
check( "exactly 5 of 20 parallel buyers get a hold (got $ok, held $held)", 5 === $ok && 5 === $held );

echo "\n$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
