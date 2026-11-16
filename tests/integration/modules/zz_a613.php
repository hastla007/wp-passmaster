<?php
/**
 * A613: revenue report by payment date. Orders get paid_at (schema 3.18.1) when the payment is booked; the
 * report can count revenue by order date (default) or payment date; orders without paid_at fall back.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Reports\Data;

echo "A613: revenue by payment date\n";
global $wpdb;
$p = $wpdb->prefix;
check( 'A613: schema 3.18.1 has orders.paid_at', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_orders LIKE 'paid_at'" ) && Orders::schema_at_least( '3.18.1' ) );
$a613_type = make_type( 50, 10, 4200 );
$a613_order = Orders::create( array( 'email' => 'a613-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a613_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a613-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A613: fixture order created', ! is_wp_error( $a613_order ) );
if ( ! is_wp_error( $a613_order ) ) {
	$a613_id = (int) $a613_order['order_id'];
	check( 'A613: an unpaid order has no payment date', null === $wpdb->get_var( "SELECT paid_at FROM {$p}wpcap_orders WHERE order_id=$a613_id" ) );
	// Ordered 40 days ago, paid now.
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), $a613_id ) );
	$a613_paid = Orders::confirm_external_payment( $a613_id, 'a613-ref' );
	$a613_row = $wpdb->get_row( "SELECT payment_status,paid_at FROM {$p}wpcap_orders WHERE order_id=$a613_id" );
	check( 'A613: booking the payment stores the payment date', 'paid' === $a613_row->payment_status && $a613_row->paid_at && abs( strtotime( $a613_row->paid_at . ' UTC' ) - time() ) < 300 );
	$a613_today = \PassCaptain\Clock::today( \PassCaptain\Reports\Scope::site_timezone() );
	$a613_by_order = Data::build( array( 'from' => $a613_today, 'to' => $a613_today, 'basis' => 'order' ), null );
	$a613_by_payment = Data::build( array( 'from' => $a613_today, 'to' => $a613_today, 'basis' => 'payment' ), null );
	check( 'A613: by order date the old order is not today\'s revenue, by payment date it is', 'payment' === $a613_by_payment['basis'] && 'order' === $a613_by_order['basis'] && $a613_by_payment['total']['tickets'] - $a613_by_order['total']['tickets'] >= 2 );
	$wpdb->query( "UPDATE {$p}wpcap_orders SET paid_at=NULL WHERE order_id=$a613_id" );
	\PassCaptain\Reports\Cache::flush_order( $a613_id );
	$a613_fallback = Data::build( array( 'from' => $a613_today, 'to' => $a613_today, 'basis' => 'payment' ), null );
	check( 'A613: without paid_at (paid before 3.18.1) the order date counts', $a613_fallback['total']['tickets'] === $a613_by_order['total']['tickets'] );
}
check( 'A613: the report page offers the date basis', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Reports/Page.php' ), "Forms::select( 'basis'" ) && 'order' === \PassCaptain\Reports\Page::filters( array() )['basis'] && 'payment' === \PassCaptain\Reports\Page::filters( array( 'basis' => 'payment' ) )['basis'] );
