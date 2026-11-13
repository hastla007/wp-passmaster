<?php
/**
 * A429: the checkout capacity check reads only the holds that still count (index counted_type),
 * never the reservation history. A430: the reconcile cron uses index ranges only and finds drift
 * without the double correlated SUM.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\CapacityCounters;
use PassCaptain\Installer;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;

echo "A429/A430: capacity check and reconcile at scale\n";
$a429_cols = static fn( $table, $key ) => array_map( static fn( $r ) => $r->Column_name, (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_$table WHERE Key_name='$key'" ) );
check( 'schema 3.10.8: reservations (ticket_type_id, counted_qty), orders (updated_at), capacity_sum covers counted_qty',
	version_compare( Installer::DB_VERSION, '3.10.8', '>=' )
	&& array( 'ticket_type_id', 'counted_qty' ) === $a429_cols( 'reservations', 'counted_type' )
	&& array( 'updated_at' ) === $a429_cols( 'orders', 'updated' )
	&& 'counted_qty' === ( $a429_cols( 'order_items', 'capacity_sum' )[4] ?? '' ) );

// An upgraded site with the old capacity_sum gets it rebuilt (dbDelta would keep the old one).
$wpdb->query( "ALTER TABLE {$p}wpcap_order_items DROP INDEX capacity_sum, ADD KEY capacity_sum (ticket_type_id,order_id,quantity,refunded_quantity)" );
$a429_items_schema = "CREATE TABLE {$p}wpcap_order_items (\n\titem_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\tPRIMARY KEY  (item_id),\n\tKEY capacity_sum (ticket_type_id,order_id,quantity,refunded_quantity,counted_qty)\n) ENGINE=InnoDB";
check( 'upgrade_indexes() rebuilds the old capacity_sum with counted_qty', array( "{$p}wpcap_order_items.capacity_sum" ) === Installer::upgrade_indexes( array( $a429_items_schema ) ) && 5 === count( $a429_cols( 'order_items', 'capacity_sum' ) ) );

// A long-running on-sale: one ticket type with thousands of converted holds (counted_qty 0).
$a429_type = make_type( 100000, 10, 2500, 100000 );
$a429_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a429_type" );
$a429_old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
for ( $chunk = 0; $chunk < 6; $chunk++ ) {
	$values = array();
	for ( $i = 0; $i < 1000; $i++ ) { $values[] = '(' . ( 800000000 + $chunk * 1000 + $i ) . ",$a429_type,1,'$a429_old','converted',0)"; }
	$wpdb->query( "INSERT INTO {$p}wpcap_reservations (order_id,ticket_type_id,quantity,expires_at,status,counted_qty) VALUES " . implode( ',', $values ) );
}
$wpdb->query( "ANALYZE TABLE {$p}wpcap_reservations, {$p}wpcap_orders, {$p}wpcap_order_items" );

$a429_plans = static function ( array $statements ) use ( $wpdb, $p ) {
	$plans = array();
	foreach ( $statements as $sql ) {
		if ( ! preg_match( '/^\s*SELECT\b/i', $sql ) || false === strpos( $sql, "{$p}wpcap_reservations" ) || false !== stripos( $sql, 'FOR UPDATE' ) ) { continue; }
		foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql ) as $row ) {
			if ( false !== strpos( (string) $row->table, 'reservations' ) || 'r' === $row->table ) { $plans[] = array( 'sql' => $sql, 'type' => (string) $row->type, 'key' => (string) $row->key ); }
		}
	}
	return $plans;
};
$a429_log = array();
$a429_spy = static function ( $sql ) use ( &$a429_log ) { $a429_log[] = $sql; return $sql; };

// A429: the lapsed-hold correction of the type and of the occurrence.
add_filter( 'query', $a429_spy );
$a429_o1 = Orders::create( array( 'email' => 'a429-1@example.com', 'items' => array( array( 'ticket_type_id' => $a429_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a429-o1' ) );
remove_filter( 'query', $a429_spy );
$a429_lapsed = array_filter( $a429_plans( $a429_log ), static fn( $plan ) => false !== strpos( $plan['sql'], 'counted_qty>0' ) );
check( 'checkout: order created with counters enabled', ! is_wp_error( $a429_o1 ) && CapacityCounters::enabled() );
check( 'A429: capacity check reads holds by range on counted_type (type and occurrence), no full scan',
	2 <= count( $a429_lapsed ) && ! array_filter( $a429_lapsed, static fn( $plan ) => 'range' !== $plan['type'] || 'counted_type' !== $plan['key'] ) );

// Semantics unchanged: holds that lapsed while still "active" free the occurrence capacity.
$a429_type2 = make_type( 0, 10, 2500, 2 );
$a429_o2 = Orders::create( array( 'email' => 'a429-2@example.com', 'items' => array( array( 'ticket_type_id' => $a429_type2, 'quantity' => 2 ) ), 'idempotency_key' => 'a429-o2' ) );
$a429_full = Orders::create( array( 'email' => 'a429-3@example.com', 'items' => array( array( 'ticket_type_id' => $a429_type2, 'quantity' => 1 ) ), 'idempotency_key' => 'a429-o3' ) );
expire_holds_of( $a429_o2['order_id'] );
$a429_o4 = Orders::create( array( 'email' => 'a429-4@example.com', 'items' => array( array( 'ticket_type_id' => $a429_type2, 'quantity' => 2 ) ), 'idempotency_key' => 'a429-o4' ) );
check( 'occurrence capacity: full while held, free again once the holds lapsed', ! is_wp_error( $a429_o2 ) && is_wp_error( $a429_full ) && ! is_wp_error( $a429_o4 ) );

// A430: reconcile (cron) finds drift through index ranges only.
$a429_zero = static fn() => 0;
add_filter( 'wpcap_capacity_reconcile_confirm_us', $a429_zero );
$a429_hold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT reservation_id FROM {$p}wpcap_reservations WHERE order_id=%d", $a429_o1['order_id'] ) );
// A hold that lapsed long ago but still counts (its writer never synced), order untouched for weeks:
// the old cron query only looked at recently changed orders/holds and missed it.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET status='expired',expires_at=%s WHERE reservation_id=%d", $a429_old, $a429_hold ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET status='expired',payment_status='expired',updated_at=%s WHERE order_id=%d", $a429_old, $a429_o1['order_id'] ) );
// A counter edited by hand.
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET sold_qty=sold_qty+5 WHERE occurrence_id=$a429_occ" );
$a429_log = array();
add_filter( 'query', $a429_spy );
$a429_report = CapacityCounters::reconcile();
remove_filter( 'query', $a429_spy );
$a429_scans = array_filter( $a429_plans( $a429_log ), static fn( $plan ) => 'ALL' === $plan['type'] || 'index' === $plan['type'] );
check( 'A430: no reconcile query scans the reservation history', array() === $a429_scans );
check( 'A430: no correlated SUM subqueries in the reconcile run', ! array_filter( $a429_log, static fn( $sql ) => (bool) preg_match( '/\(SELECT SUM\(counted_qty\)/i', $sql ) ) );
check( 'A430: old lapsed hold that still counted is re-synced', in_array( (int) $a429_o1['order_id'], $a429_report['orders'], true ) && 0 === (int) $wpdb->get_var( "SELECT counted_qty FROM {$p}wpcap_reservations WHERE reservation_id=$a429_hold" ) );
check( 'A430: hand-edited occurrence counter repaired', in_array( $a429_occ, $a429_report['occurrences'], true ) && array() === CapacityCounters::drift( array( $a429_occ ) ) );
// An active hold that does not count.
$a429_o5 = Orders::create( array( 'email' => 'a429-5@example.com', 'items' => array( array( 'ticket_type_id' => $a429_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a429-o5' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET counted_qty=0 WHERE order_id=%d", $a429_o5['order_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET updated_at=%s WHERE order_id=%d", $a429_old, $a429_o5['order_id'] ) );
$a429_report = CapacityCounters::reconcile();
check( 'A430: active hold that does not count is re-synced', in_array( (int) $a429_o5['order_id'], $a429_report['orders'], true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT counted_qty FROM {$p}wpcap_reservations WHERE order_id=%d", $a429_o5['order_id'] ) ) );
$a429_all = CapacityCounters::reconcile( true );
check( 'A430: reconcile --all finds nothing left', 0 === $a429_all['resynced_orders'] && 0 === $a429_all['repaired_occurrences'] && array() === CapacityCounters::drift() );
remove_filter( 'wpcap_capacity_reconcile_confirm_us', $a429_zero );

$wpdb->query( "DELETE FROM {$p}wpcap_reservations WHERE order_id BETWEEN 800000000 AND 800009999" );
