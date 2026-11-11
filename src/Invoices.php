<?php
/** Per-year, transaction-locked invoice numbering primitive. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Invoices {
	public static function issue_for_order( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", $order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Eine Rechnung kann erst für bezahlte Bestellungen erstellt werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,invoice_number FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d LIMIT 1", $order_id ) );
		if ( $existing ) { return array( 'invoice_id' => (int) $existing->invoice_id, 'invoice_number' => $existing->invoice_number, 'reused' => true ); }
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT oi.*,tt.name FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d", $order_id ) );
		$number = self::next_number();
		if ( is_wp_error( $number ) ) { return $number; }
		$wpdb->query( 'START TRANSACTION' );
		$locked_order = $wpdb->get_row( $wpdb->prepare( "SELECT payment_status FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id,invoice_number FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d", $order_id ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'invoice_id' => (int) $existing->invoice_id, 'invoice_number' => $existing->invoice_number, 'reused' => true ); }
		if ( ! $locked_order || 'paid' !== $locked_order->payment_status ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_order_unpaid', __( 'Die Bestellung ist nicht mehr als bezahlt markiert.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->insert( $wpdb->prefix . 'wpcap_invoices', array( 'order_id' => $order_id, 'invoice_number' => $number, 'currency' => $order->currency, 'subtotal_minor' => $order->total_minor, 'tax_minor' => 0, 'total_minor' => $order->total_minor, 'buyer_snapshot' => wp_json_encode( array( 'email' => $order->customer_email, 'currency' => $order->currency, 'total_minor' => (int) $order->total_minor ) ), 'issued_at' => current_time( 'mysql', true ), 'status' => 'draft' ), array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ) );
		$invoice_id = (int) $wpdb->insert_id;
		if ( ! $invoice_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_create', __( 'Rechnung konnte nicht erstellt werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		foreach ( $items as $item ) {
			$wpdb->insert( $wpdb->prefix . 'wpcap_invoice_lines', array( 'invoice_id' => $invoice_id, 'description' => $item->name, 'quantity' => $item->quantity, 'unit_minor' => $item->unit_price_minor, 'tax_rate' => 0, 'tax_minor' => 0, 'total_minor' => $item->line_total_minor ), array( '%d', '%s', '%d', '%d', '%f', '%d', '%d' ) );
			if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_line', __( 'Rechnungsposition konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'invoice_issued', 'invoice', $invoice_id, array( 'order_id' => $order_id, 'invoice_number' => $number ) );
		return array( 'invoice_id' => $invoice_id, 'invoice_number' => $number );
	}

	public static function next_number( $year = 0 ) {
		global $wpdb;
		$year = $year ? absint( $year ) : (int) gmdate( 'Y' );
		$table = $wpdb->prefix . 'wpcap_invoice_sequences';
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (year,next_number) VALUES (%d,1)", $year ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT next_number FROM $table WHERE year=%d FOR UPDATE", $year ) );
		if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_sequence', __( 'Rechnungsnummer konnte nicht reserviert werden.', 'wp-pass-captain' ) ); }
		$number = (int) $row->next_number;
		$updated = $wpdb->update( $table, array( 'next_number' => $number + 1 ), array( 'year' => $year ), array( '%d' ), array( '%d' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_invoice_sequence', __( 'Rechnungsnummer konnte nicht reserviert werden.', 'wp-pass-captain' ) ); }
		$wpdb->query( 'COMMIT' );
		return sprintf( 'WPC-%d-%06d', $year, $number );
	}
}
