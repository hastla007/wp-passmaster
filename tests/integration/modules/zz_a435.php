<?php
/**
 * A435: the organizer settlement reads only sources that are not settled yet (in SQL: sales whose
 * source key "sale:<item>" is not taken, refunds this partner has not settled), in blocks – a long
 * settled history is not read again for every draft.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Partners\Repository as A435Partners;
use PassCaptain\Partners\Settlements as A435Settlements;

global $wpdb;
$p = $wpdb->prefix;

echo "A435: organizer settlement without the settled history\n";
$a435_type = make_type( 0 );
$a435_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a435_type" );
$a435_partner = A435Partners::save( array( 'name' => 'A435 Organizer', 'currency' => 'EUR', 'commission_bp' => 1000, 'settlement_start' => '2004-01-01' ) );
A435Partners::set_events( $a435_partner, array( $a435_event ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_partner_event_history SET valid_from=NULL WHERE partner_id=%d", $a435_partner ) );

// January: 1,500 sales, settled (finalized settlement 2004-01). February: 30 sales, one January order
// paid late (not settled), one comp order from January.
$a435_first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_id),0)+1 FROM {$p}wpcap_orders" );
$a435_orders = array();
$a435_items = array();
for ( $i = 0; $i < 1532; $i++ ) {
	$id = $a435_first + $i;
	$at = $i < 1500 || 1530 === $i || 1531 === $i ? '2004-01-15 12:00:00' : '2004-02-15 12:00:00';
	$a435_orders[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)', $id, md5( 'a435-' . $i ), hash( 'sha256', 'a435-' . $i ), "a435-$i@example.com", 'complete', 'paid', 'EUR', '{}', 1000, 1531 === $i ? 'comp' : 'stripe', $at, $at );
	$a435_items[] = $wpdb->prepare( '(%d,%d,1,1000,1000,0,0)', $id, $a435_type );
}
foreach ( array_chunk( $a435_orders, 500 ) as $chunk ) { $wpdb->query( "INSERT INTO {$p}wpcap_orders (order_id,public_id,idempotency_key,customer_email,status,payment_status,currency,price_snapshot,total_minor,payment_provider,created_at,updated_at) VALUES " . implode( ',', $chunk ) ); }
foreach ( array_chunk( $a435_items, 500 ) as $chunk ) { $wpdb->query( "INSERT INTO {$p}wpcap_order_items (order_id,ticket_type_id,quantity,unit_price_minor,line_total_minor,tax_rate_bp,tax_minor) VALUES " . implode( ',', $chunk ) ); }
$a435_last = $a435_first + 1531;
$a435_settled_orders = range( $a435_first, $a435_first + 1499 );
$wpdb->insert( "{$p}wpcap_partner_settlements", array( 'partner_id' => $a435_partner, 'period' => '2004-01', 'period_start' => '2003-12-31 23:00:00', 'period_end' => '2004-01-31 23:00:00', 'status' => 'finalized', 'currency' => 'EUR', 'totals' => '{}', 'created_at' => '2004-02-01 10:00:00', 'updated_at' => '2004-02-01 10:00:00' ) );
$a435_settlement = (int) $wpdb->insert_id;
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_partner_settlement_lines (settlement_id,partner_id,kind,item_key,source_key,order_id,item_id,event_id,occurred_at,tickets,gross_minor) SELECT %d,%d,'sale',CONCAT('sale:',item_id),CONCAT('sale:',item_id),order_id,item_id,%d,'2004-01-15 12:00:00',1,1000 FROM {$p}wpcap_order_items WHERE order_id BETWEEN %d AND %d", $a435_settlement, $a435_partner, $a435_event, $a435_first, $a435_first + 1499 ) );
// Refunds completed in February: one of a settled January sale (correction), one already settled by this partner.
$a435_refund = static function ( $order_id, $key ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $order_id, 'idempotency_key' => hash( 'sha256', $key ), 'amount_minor' => 1000, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'status' => 'completed', 'source' => 'admin', 'created_at' => '2004-02-10 12:00:00', 'updated_at' => '2004-02-10 12:00:00' ) );
	return (int) $wpdb->insert_id;
};
$a435_r1 = $a435_refund( $a435_first + 3, 'a435-r1' );
$a435_r2 = $a435_refund( $a435_first + 4, 'a435-r2' );
$a435_r2_item = (int) $wpdb->get_var( "SELECT item_id FROM {$p}wpcap_order_items WHERE order_id=" . ( $a435_first + 4 ) );
$wpdb->insert( "{$p}wpcap_partner_settlement_lines", array( 'settlement_id' => $a435_settlement, 'partner_id' => $a435_partner, 'kind' => 'correction', 'item_key' => "refund:$a435_r2:$a435_r2_item", 'source_key' => "refund:$a435_r2:$a435_r2_item", 'order_id' => $a435_first + 4, 'refund_id' => $a435_r2, 'item_id' => $a435_r2_item, 'event_id' => $a435_event, 'occurred_at' => '2004-02-10 12:00:00', 'tickets' => -1, 'gross_minor' => -1000 ) );
$wpdb->query( "ANALYZE TABLE {$p}wpcap_orders, {$p}wpcap_order_items, {$p}wpcap_partner_settlement_lines" );

$a435_log = array();
$a435_spy = static function ( $sql ) use ( &$a435_log ) { $a435_log[] = $sql; return $sql; };
$a435_q = $wpdb->num_queries;
add_filter( 'query', $a435_spy );
$a435_calc = A435Settlements::compute( A435Partners::get( $a435_partner ), '2004-02' );
remove_filter( 'query', $a435_spy );
$a435_q = $wpdb->num_queries - $a435_q;
$a435_kinds = array_count_values( array_column( $a435_calc['lines'], 'kind' ) );
check( 'February: 30 sales, the late January sale and the correction; the settled refund and the comp are not settled', array( 'correction' => 1, 'late_sale' => 1, 'sale' => 30 ) === ( ksort( $a435_kinds ) ? $a435_kinds : array() ) && ! in_array( $a435_r2, array_column( $a435_calc['lines'], 'refund_id' ), true ) && 0 === (int) $a435_calc['totals']['comp_tickets'] );
check( 'totals: gross after the correction, commission on net', 31 * 1000 - 1000 === (int) $a435_calc['totals']['gross'] );
// Which orders were read: the lines (and settled keys) of the settled January sales are not.
$a435_read = array();
foreach ( $a435_log as $sql ) {
	if ( preg_match( '/order_id IN \(([\d,]+)\)/', $sql, $m ) && preg_match( '/wpcap_order_items|wpcap_partner_settlement_lines|wpcap_orders WHERE order_id IN/', $sql ) ) { $a435_read = array_merge( $a435_read, array_map( 'intval', explode( ',', $m[1] ) ) ); }
}
$a435_history_read = array_intersect( array_unique( $a435_read ), array_diff( $a435_settled_orders, array( $a435_first + 3 ) ) );
check( 'settled history not read again: only open orders and the refunded order', array() === array_values( $a435_history_read ) && in_array( $a435_first + 3, $a435_read, true ) && in_array( $a435_first + 1530, $a435_read, true ) );
check( "bounded queries ($a435_q) for 1,532 orders of which 1,500 are settled", $a435_q < 40 );
$a435_cand = array_values( array_filter( $a435_log, static fn( $sql ) => false !== strpos( $sql, "CONCAT('sale:'" ) ) );
$a435_plan = $a435_cand ? array_values( array_filter( (array) $wpdb->get_results( 'EXPLAIN ' . $a435_cand[0] ), static fn( $r ) => 'sl' === $r->table ) ) : array();
check( 'unsettled sales found through the unique source_key index', 1 === count( $a435_plan ) && 'source_key' === (string) $a435_plan[0]->key );

// The same partner for January again (draft of a finalized period) shows nothing new.
$a435_jan = A435Settlements::compute( A435Partners::get( $a435_partner ), '2004-01' );
check( 'January recomputed: only the late sale (the comp of January is counted)', 1 === count( $a435_jan['lines'] ) && 'sale' === $a435_jan['lines'][0]['kind'] && 1 === (int) $a435_jan['totals']['comp_tickets'] );

$wpdb->query( "DELETE FROM {$p}wpcap_partner_settlement_lines WHERE settlement_id=$a435_settlement" );
$wpdb->query( "DELETE FROM {$p}wpcap_partner_settlements WHERE settlement_id=$a435_settlement" );
$wpdb->query( "DELETE FROM {$p}wpcap_refunds WHERE refund_id IN ($a435_r1,$a435_r2)" );
$wpdb->query( "DELETE FROM {$p}wpcap_order_items WHERE order_id BETWEEN $a435_first AND $a435_last" );
$wpdb->query( "DELETE FROM {$p}wpcap_orders WHERE order_id BETWEEN $a435_first AND $a435_last" );
A435Partners::set_events( $a435_partner, array() );
