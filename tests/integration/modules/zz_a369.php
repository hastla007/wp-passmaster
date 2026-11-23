<?php
/**
 * A369: the refund badge in the admin menu (every admin page) counts pending refunds with one
 * COUNT(*) instead of loading the approval list; the status index exists (KEY status_updated).
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\Queries;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
echo "A369: pending refund badge as COUNT(*)\n";

$x2_a369_type = make_type( 10 );
$x2_a369_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$x2_a369_type" );
$x2_a369_order = Orders::create( array( 'email' => 'x2-a369@example.com', 'items' => array( array( 'ticket_type_id' => $x2_a369_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x2-a369-order-' . uniqid() ) );
$x2_a369_now = gmdate( 'Y-m-d H:i:s' );
foreach ( array( 'pending_approval', 'pending_approval', 'succeeded' ) as $i => $x2_a369_status ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $x2_a369_order['order_id'], 'idempotency_key' => hash( 'sha256', 'x2-a369-' . $i . uniqid() ), 'amount_minor' => 100, 'currency' => 'EUR', 'ticket_ids' => '[]', 'status' => $x2_a369_status, 'created_at' => $x2_a369_now, 'updated_at' => $x2_a369_now ) );
}

$x2_a369_same = true;
foreach ( array( null, array( $x2_a369_event ), array( $x2_a369_event + 100000 ), array() ) as $x2_a369_scope ) {
	$x2_a369_same = $x2_a369_same && count( Queries::pending_refunds( $x2_a369_scope ) ) === Queries::pending_refunds_count( $x2_a369_scope );
}
check( 'A369: the count equals the approval list for all scopes (all, own event, foreign event, none)', $x2_a369_same && Queries::pending_refunds_count( array( $x2_a369_event ) ) === 2 && 0 === Queries::pending_refunds_count( array() ) );

$GLOBALS['wpcap_test_query_log'] = array();
Queries::pending_refunds_count( null );
$x2_a369_log = $GLOBALS['wpcap_test_query_log'];
unset( $GLOBALS['wpcap_test_query_log'] );
check( 'A369: the badge runs a single COUNT(*) without loading rows or event names', 1 === count( $x2_a369_log ) && false !== stripos( $x2_a369_log[0], 'COUNT(*)' ) && false === stripos( $x2_a369_log[0], 'GROUP_CONCAT' ) );

$x2_a369_menu = file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' );
check( 'A369: the admin menu uses the count, not the full list', false !== strpos( $x2_a369_menu, 'Queries::pending_refunds_count(' ) && false === strpos( $x2_a369_menu, 'count( Queries::pending_refunds(' ) );
$x2_a369_idx = $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_refunds WHERE Key_name='status_updated' AND Seq_in_index=1" );
check( 'A369: refunds.status is the leading column of an index (status_updated)', $x2_a369_idx && 'status' === $x2_a369_idx[0]->Column_name );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_refunds WHERE order_id=%d", $x2_a369_order['order_id'] ) );
Orders::cancel_order( $x2_a369_order['order_id'] );
