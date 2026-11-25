<?php
/**
 * Orders::max_lines(): the order line limit of the checkout is readable and filterable (wpcap_max_lines).
 */

use PassCaptain\Orders;

echo "Max lines: order line limit via Orders::max_lines() and filter wpcap_max_lines\n";

check( 'ML: default is 10 lines', 10 === Orders::max_lines() );
$GLOBALS['wpcap_test_filters']['wpcap_max_lines'] = 3;
check( 'ML: the filter changes the limit', 3 === Orders::max_lines() );
$GLOBALS['wpcap_test_filters']['wpcap_max_lines'] = 0;
check( 'ML: at least 1', 1 === Orders::max_lines() );
$GLOBALS['wpcap_test_filters']['wpcap_max_lines'] = 5000;
check( 'ML: at most 100', 100 === Orders::max_lines() );
$GLOBALS['wpcap_test_filters']['wpcap_max_lines'] = 2;
$ml_items = array();
foreach ( array( 1, 2, 3 ) as $ml_i ) { $ml_items[] = array( 'ticket_type_id' => make_type( 0, 10, 1000 ), 'quantity' => 1 ); }
$ml_result = Orders::create( array( 'email' => 'ml@example.com', 'items' => $ml_items, 'idempotency_key' => 'ml-' . uniqid() ) );
check( 'ML: a checkout with more lines than the limit is refused (400)', is_wp_error( $ml_result ) && 'wpcap_invalid_checkout' === $ml_result->get_error_code() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_max_lines'] );
check( 'ML: the checkout no longer hard-codes 10 lines', false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' ), 'count( $s->lines ) > 10' ) );
