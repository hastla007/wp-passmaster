<?php
/**
 * N11/A404: unused leftovers removed (assets/order-portal.js, Refunds::tax_for_tickets,
 * GuestLists\Service::entry_tickets); BoxOffice\Service::unfinished_sales() is shown in the shift view.
 */

echo "N11/A404: leftovers\n";
check( 'N11: the unused portal script is gone (the portal script is inline)', ! file_exists( WPCAP_PATH . 'assets/order-portal.js' ) && str_contains( (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' ), "'X-WPCAP-Access-Token':TOKEN" ) );
check( 'A404: dead helpers are removed', ! method_exists( PassCaptain\Refunds::class, 'tax_for_tickets' ) && ! method_exists( PassCaptain\GuestLists\Service::class, 'entry_tickets' ) );
check( 'A404: the shift view lists unfinished sales', str_contains( (string) file_get_contents( WPCAP_PATH . 'src/BoxOffice/ShiftPage.php' ), 'Service::unfinished_sales( $shift->session_id )' ) );

global $wpdb;
$n11_table = $wpdb->prefix . 'wpcap_boxoffice_sales';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $n11_table ) ) === $n11_table ) {
	$n11_session = 990000 + random_int( 1, 9999 );
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $n11_table, array( 'session_id' => $n11_session, 'order_id' => 1, 'form_key_hash' => str_repeat( 'a', 64 ), 'payment_method' => 'cash', 'currency' => 'EUR', 'total_minor' => 1500, 'cashier_id' => 1, 'status' => 'pending', 'created_at' => $now ) );
	$n11_pending = (int) $wpdb->insert_id;
	$wpdb->insert( $n11_table, array( 'session_id' => $n11_session, 'order_id' => 2, 'form_key_hash' => str_repeat( 'b', 64 ), 'payment_method' => 'cash', 'currency' => 'EUR', 'total_minor' => 900, 'cashier_id' => 1, 'status' => 'completed', 'receipt_number' => 'K-1', 'created_at' => $now ) );
	$n11_rows = PassCaptain\BoxOffice\Service::unfinished_sales( $n11_session );
	check( 'A404: unfinished_sales() returns only the pending sale of the shift', 1 === count( $n11_rows ) && $n11_pending === (int) $n11_rows[0]->sale_id );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_id=%d', $n11_table, $n11_session ) );
}
