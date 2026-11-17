<?php
/**
 * A434: sales report, accounting/DATEV export, occurrence settlement and reseller statement read
 * many orders in blocks with a bounded number of queries (no query per order), the export is
 * streamed row by row, and the figures stay the same (exchange supplements, refunds, DATEV bookings
 * of refunds of the same order that are not next to each other in the export).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Partners\Repository as A434Partners;
use PassCaptain\Partners\Statement;
use PassCaptain\Reports\Data;
use PassCaptain\Reports\Settlement;

global $wpdb;
$p = $wpdb->prefix;

echo "A434: reports and exports without a query per order\n";
// 1,200 paid orders (more than one block) of one ticket type on a day of their own, two tickets each.
$a434_day = '2003-02-10';
$a434_at = $a434_day . ' 12:00:00';
$a434_type = make_type( 0 );
$a434_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a434_type" );
$a434_n = 1200;
$a434_first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_id),0)+1 FROM {$p}wpcap_orders" );
foreach ( array_chunk( range( 0, $a434_n - 1 ), 400 ) as $chunk ) {
	$orders = array();
	$items = array();
	foreach ( $chunk as $i ) {
		$id = $a434_first + $i;
		$orders[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s)', $id, md5( 'a434-' . $i ), hash( 'sha256', 'a434-' . $i ), "a434-$i@example.com", 'complete', 'paid', 'EUR', '{}', 2000, 2000, 'stripe', $a434_at, $a434_at );
		$items[] = $wpdb->prepare( '(%d,%d,2,1000,2000,1900,319)', $id, $a434_type );
	}
	$wpdb->query( "INSERT INTO {$p}wpcap_orders (order_id,public_id,idempotency_key,customer_email,status,payment_status,currency,price_snapshot,total_minor,subtotal_minor,payment_provider,created_at,updated_at) VALUES " . implode( ',', $orders ) );
	$wpdb->query( "INSERT INTO {$p}wpcap_order_items (order_id,ticket_type_id,quantity,unit_price_minor,line_total_minor,tax_rate_bp,tax_minor) VALUES " . implode( ',', $items ) );
}
$a434_ids = range( $a434_first, $a434_first + $a434_n - 1 );
// The last order is the supplementary payment of an exchange: money, but no tickets of its own.
$a434_supp = end( $a434_ids );
$wpdb->insert( "{$p}wpcap_ticket_exchanges", array( 'order_id' => $a434_first, 'old_ticket_id' => 0, 'root_ticket_id' => 0, 'from_type_id' => $a434_type, 'to_type_id' => $a434_type, 'status' => 'completed', 'supplementary_order_id' => $a434_supp, 'created_at' => $a434_at, 'updated_at' => $a434_at ) );
// Refunds of the same day: order 1, order 2, order 1 again (amount-only, 5 € each).
$a434_r = 0;
foreach ( array( $a434_first, $a434_first + 1, $a434_first ) as $order_id ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $order_id, 'idempotency_key' => hash( 'sha256', 'a434-r' . ( ++$a434_r ) ), 'amount_minor' => 500, 'tax_minor' => 80, 'currency' => 'EUR', 'ticket_ids' => '[]', 'status' => 'completed', 'source' => 'admin', 'created_at' => $a434_at, 'updated_at' => $a434_day . ' 13:0' . $a434_r . ':00' ) );
}
$wpdb->query( "ANALYZE TABLE {$p}wpcap_orders, {$p}wpcap_order_items, {$p}wpcap_refunds" );

$a434_count = static function ( callable $fn ) use ( $wpdb ) {
	$before = $wpdb->num_queries;
	$result = $fn();
	return array( $wpdb->num_queries - $before, $result );
};

list( $a434_q, $a434_report ) = $a434_count( static fn() => Data::build( array( 'from' => $a434_day, 'to' => $a434_day ), null ) );
check( "sales report: $a434_q queries for $a434_n orders (blocks, no query per order)", $a434_q < 60 );
check( 'sales report figures: tickets without the supplement, gross, refunds of the day', ( $a434_n - 1 ) * 2 === (int) $a434_report['total']['tickets'] && $a434_n * 2000 === (int) $a434_report['total']['gross'] && 1500 === (int) $a434_report['total']['refunded'] );

list( $a434_q, $a434_rows ) = $a434_count( static fn() => Invoices::export_rows( $a434_day, $a434_day ) );
$a434_sales = array_values( array_filter( $a434_rows, static fn( $r ) => Invoices::EXPORT_KIND_SALE_UNINVOICED === $r[0] ) );
$a434_refund_rows = array_values( array_filter( $a434_rows, static fn( $r ) => Invoices::EXPORT_KIND_REFUND_UNINVOICED === $r[0] ) );
check( "accounting export: $a434_q queries for $a434_n orders", $a434_q < 60 );
check( 'accounting export rows: one sale per order (supplement with 0 tickets), three refunds', $a434_n === count( $a434_sales ) && 3 === count( $a434_refund_rows ) && 0 === (int) array_values( array_filter( $a434_sales, static fn( $r ) => md5( 'a434-' . ( $a434_n - 1 ) ) === $r[3] ) )[0][6] );
$a434_streamed = array();
$a434_sent = Invoices::export_stream( $a434_day, $a434_day, false, static function ( array $row ) use ( &$a434_streamed ) { $a434_streamed[] = $row; } );
check( 'streamed export: the same rows as export_rows(), one call per row', $a434_rows === $a434_streamed && count( $a434_rows ) === $a434_sent );

list( $a434_q, $a434_datev ) = $a434_count( static fn() => Invoices::datev_rows( $a434_day, $a434_day ) );
$a434_mine = array_values( array_filter( array_slice( $a434_datev, 1 ), static fn( $r ) => $r[7] === substr( md5( 'a434-0' ), 0, 36 ) ) );
check( "DATEV batch: $a434_q queries", $a434_q < 60 );
check( 'DATEV: both refunds of order 1 in one booking although another refund lies between them', 2 === count( $a434_mine ) && array( 'S', 'H' ) === array_column( $a434_mine, 1 ) && '10,00' === $a434_mine[1][0] && '20,00' === $a434_mine[0][0] );
$a434_dstream = array();
Invoices::export_stream( $a434_day, $a434_day, true, static function ( array $row ) use ( &$a434_dstream ) { $a434_dstream[] = $row; } );
check( 'streamed DATEV batch equals datev_rows() (Windows-1252)', array_map( static fn( $r ) => array_map( static fn( $c ) => mb_convert_encoding( (string) $c, 'Windows-1252', 'UTF-8' ), $r ), $a434_datev ) === $a434_dstream );

list( $a434_q, $a434_sheet ) = $a434_count( static fn() => Settlement::build( $a434_occ ) );
check( "occurrence settlement: $a434_q queries for $a434_n orders", $a434_q < 60 );
check( 'occurrence settlement figures', ( $a434_n - 1 ) * 2 === (int) $a434_sheet['total']['tickets'] && $a434_n * 2000 === (int) $a434_sheet['total']['gross'] && 1500 === (int) $a434_sheet['total']['refunded'] );

// Reseller statement: every order was sold through the partner API.
$a434_partner = A434Partners::save( array( 'name' => 'A434 Reseller', 'currency' => 'EUR', 'settlement_start' => '2003-01-01' ) );
$a434_po = array_map( static fn( $id ) => $wpdb->prepare( '(%d,%d,%s,%s,%s)', $id, $a434_partner, 'sell', $a434_at, $a434_at ), $a434_ids );
$wpdb->query( "INSERT INTO {$p}wpcap_partner_orders (order_id,partner_id,mode,confirmed_at,created_at) VALUES " . implode( ',', $a434_po ) );
list( $a434_q, $a434_statement ) = $a434_count( static fn() => Statement::compute( A434Partners::get( $a434_partner ), '2003-02' ) );
check( "reseller statement: $a434_q queries for $a434_n orders", $a434_q < 60 );
check( 'reseller statement figures', ( $a434_n - 1 ) * 2 === (int) $a434_statement['totals']['tickets_sold'] && $a434_n * 2000 === (int) $a434_statement['totals']['sales_gross'] && 1500 === (int) $a434_statement['totals']['refunded'] );

$wpdb->query( "DELETE FROM {$p}wpcap_partner_orders WHERE partner_id=" . (int) $a434_partner );
$wpdb->query( "DELETE FROM {$p}wpcap_partners WHERE partner_id=" . (int) $a434_partner );
$wpdb->query( "DELETE FROM {$p}wpcap_ticket_exchanges WHERE supplementary_order_id=$a434_supp" );
$wpdb->query( "DELETE FROM {$p}wpcap_refunds WHERE order_id IN ($a434_first," . ( $a434_first + 1 ) . ')' );
$wpdb->query( "DELETE FROM {$p}wpcap_order_items WHERE order_id BETWEEN $a434_first AND $a434_supp" );
$wpdb->query( "DELETE FROM {$p}wpcap_orders WHERE order_id BETWEEN $a434_first AND $a434_supp" );
