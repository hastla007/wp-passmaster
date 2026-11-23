<?php
/**
 * A127: WooCommerce adapter against a real, installed WooCommerce (run with `wp eval-file`).
 * Classic order flow with the real WooCommerce CRUD/status/refund APIs, in whatever order storage is
 * active (HPOS or posts): hold on checkout, tickets on payment, partial refund, cancellation, sold out.
 */
use PassCaptain\Integrations\WooCommerce\OrderAdapter;
use Automattic\WooCommerce\Utilities\OrderUtil;

global $wpdb;
$p = $wpdb->prefix;
$fail = 0;
$check = static function ( $name, $ok ) use ( &$fail ) { echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } };
$storage = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts';
echo 'WooCommerce ' . WC()->version . ", order storage: $storage\n";
wp_set_current_user( 1 );
add_filter( 'wpcap_checkout_ip_limit', static fn() => PHP_INT_MAX );

$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Woo smoke ' . $storage, 'slug' => 'woo-smoke-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Woo', 'price_minor' => 1000, 'currency' => get_woocommerce_currency(), 'capacity' => 5, 'max_per_order' => 10, 'status' => 'active' ) );
$type = (int) $wpdb->insert_id;

$product = new WC_Product_Simple();
$product->set_name( 'Ticket smoke ' . $storage );
$product->set_virtual( true );
$product->set_regular_price( '10' );
$product->update_meta_data( OrderAdapter::PRODUCT_META, $type );
$product->save();

$tickets = static function ( $core, $status = null ) use ( $wpdb, $p ) {
	$sql = "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=%d" . ( $status ? ' AND status=%s' : '' );
	return (int) $wpdb->get_var( $status ? $wpdb->prepare( $sql, $core, $status ) : $wpdb->prepare( $sql, $core ) );
};
$order_with = static function ( $qty, $email ) use ( $product ) {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Woo' );
	$order->set_billing_last_name( 'Smoke' );
	$order->set_payment_method( 'bacs' );
	$order->calculate_totals();
	$order->save();
	return $order;
};
$checkout = static function ( $order ) {
	try { do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order ); return true; } catch ( Exception $e ) { return $e->getMessage(); }
};

// A: 2 tickets, paid.
$a = $order_with( 2, 'woo-a-' . uniqid() . '@example.com' );
$check( 'checkout places a hold (core order linked to the WooCommerce order)', true === $checkout( $a ) && ( $core_a = (int) wc_get_order( $a->get_id() )->get_meta( OrderAdapter::ORDER_META ) ) > 0 );
$a = wc_get_order( $a->get_id() );
$a->payment_complete( 'txn-' . uniqid() );
$check( 'payment completes: exactly 2 valid tickets issued', 2 === $tickets( $core_a, 'valid' ) && 2 === $tickets( $core_a ) );
$a = wc_get_order( $a->get_id() );
$a->update_status( 'completed' );
$check( 'completing the order issues no duplicate tickets', 2 === $tickets( $core_a ) );

// Partial refund of one ticket.
$item_id = array_key_first( $a->get_items() );
$refund = wc_create_refund( array( 'order_id' => $a->get_id(), 'amount' => '10', 'line_items' => array( $item_id => array( 'qty' => 1, 'refund_total' => 10 ) ) ) );
$check( 'partial WooCommerce refund is booked once in the refund journal', ! is_wp_error( $refund ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $core_a ) ) );
$check( 'one ticket refunded, one still valid', 1 === $tickets( $core_a, 'valid' ) );

// B: 3 tickets held, then cancelled → capacity released.
$b = $order_with( 3, 'woo-b-' . uniqid() . '@example.com' );
$check( 'second order holds 3 of the remaining 4', true === $checkout( $b ) );
$avail_held = PassCaptain\Orders::availability( $type );
$b = wc_get_order( $b->get_id() );
$b->update_status( 'cancelled' );
$avail_after = PassCaptain\Orders::availability( $type );
$check( 'hold counts against capacity (1 left while held)', 1 === (int) $avail_held );
$check( 'cancelling the WooCommerce order releases the hold (4 left)', 4 === (int) $avail_after );

// C: more than available → checkout aborted before payment.
$c = $order_with( 5, 'woo-c-' . uniqid() . '@example.com' );
$res = $checkout( $c );
$check( 'checkout over the remaining capacity is aborted before payment', true !== $res && ! (int) wc_get_order( $c->get_id() )->get_meta( OrderAdapter::ORDER_META ) );

// Clean up the fixture product/event (orders stay as history of the run).
$product->set_status( 'draft' );
$product->save();
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'draft' ), array( 'event_id' => $event ) );
if ( $fail ) { WP_CLI::halt( 1 ); }
