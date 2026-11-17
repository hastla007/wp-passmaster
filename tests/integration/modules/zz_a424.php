<?php
/**
 * A424: order portal access lasts until some time after the order's last date (not only 30 days from the
 * purchase); the admin can send a new order link (the invoice travels with "Tickets erneut senden").
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Orders;

echo "A424: order link lifetime and resend\n";
global $wpdb;
$p = $wpdb->prefix;
$a424_type = make_type( 20, 10, 1500 );
$a424_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a424_type" );
// The date is in 90 days.
$a424_start = gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s, ends_at=%s WHERE occurrence_id=%d", $a424_start, gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS ), $a424_occ ) );
$a424 = Orders::create( array( 'email' => 'a424-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a424_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a424-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A424: fixture order', ! is_wp_error( $a424 ) );
if ( ! is_wp_error( $a424 ) ) {
	$a424_id = (int) $a424['order_id'];
	$a424_exp = strtotime( (string) $wpdb->get_var( "SELECT access_expires_at FROM {$p}wpcap_orders WHERE order_id=$a424_id" ) . ' UTC' );
	check( 'A424: the link of an order for a date in 90 days lasts until about 30 days after that date', $a424_exp > time() + 115 * DAY_IN_SECONDS && $a424_exp < time() + 125 * DAY_IN_SECONDS );
	check( 'A424: without dates the link lasts 30 days from now', abs( strtotime( Orders::access_until( 0 ) . ' UTC' ) - ( time() + 30 * DAY_IN_SECONDS ) ) < 120 );
	$GLOBALS['wpcap_test_filters']['wpcap_order_access_days'] = 60;
	check( 'A424: the number of days is filterable (wpcap_order_access_days)', abs( strtotime( Orders::access_until( 0 ) . ' UTC' ) - ( time() + 60 * DAY_IN_SECONDS ) ) < 120 );
	unset( $GLOBALS['wpcap_test_filters']['wpcap_order_access_days'] );
	$wpdb->query( "UPDATE {$p}wpcap_orders SET access_expires_at='2000-01-01 00:00:00' WHERE order_id=$a424_id" );
	Orders::rotate_access_link( $a424_id );
	$a424_new = strtotime( (string) $wpdb->get_var( "SELECT access_expires_at FROM {$p}wpcap_orders WHERE order_id=$a424_id" ) . ' UTC' );
	check( 'A424: a new link gets the same lifetime (after the last date)', $a424_new > time() + 115 * DAY_IN_SECONDS );
}
check( 'A424: admin action "Bestelllink erneut senden" is registered', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' ), "'wpcap_send_order_link' => array( OrdersPage::class, 'handle_send_order_link' )" ) && method_exists( \PassCaptain\Admin\OrdersPage::class, 'handle_send_order_link' ) );
