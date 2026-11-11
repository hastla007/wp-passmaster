<?php
/** WordPress personal data exporter and eraser integration. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Privacy {
	public static function register_exporter( $exporters ) {
		$exporters['wp-pass-captain-orders'] = array( 'exporter_friendly_name' => __( 'WP Pass Captain Bestellungen und Tickets', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['wp-pass-captain-orders'] = array( 'eraser_friendly_name' => __( 'WP Pass Captain Bestellungen und Tickets', 'wp-pass-captain' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	public static function export( $email_address, $page = 1 ) {
		global $wpdb;
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status,currency,total_minor,created_at FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s OR order_id IN (SELECT DISTINCT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE attendee_email=%s) ORDER BY order_id ASC LIMIT 50 OFFSET %d", sanitize_email( $email_address ), sanitize_email( $email_address ), max( 0, ( absint( $page ) - 1 ) * 50 ) ), ARRAY_A );
		$data = array();
		foreach ( $orders as $order ) {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT tt.name,oi.quantity,oi.unit_price_minor,oi.line_total_minor FROM {$wpdb->prefix}wpcap_order_items oi INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id WHERE oi.order_id=%d", $order['order_id'] ), ARRAY_A );
			$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,attendee_email,status,transfer_count FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", $order['order_id'] ), ARRAY_A );
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'WP Pass Captain Bestellungen', 'wp-pass-captain' ), 'item_id' => 'order-' . $order['public_id'], 'data' => array(
				array( 'name' => __( 'Bestellreferenz', 'wp-pass-captain' ), 'value' => $order['public_id'] ),
				array( 'name' => __( 'Status', 'wp-pass-captain' ), 'value' => $order['status'] . ' / ' . $order['payment_status'] ),
				array( 'name' => __( 'Gesamtbetrag in kleinster Währungseinheit', 'wp-pass-captain' ), 'value' => $order['total_minor'] . ' ' . $order['currency'] ),
				array( 'name' => __( 'Erstellt (UTC)', 'wp-pass-captain' ), 'value' => $order['created_at'] ),
				array( 'name' => __( 'Positionen', 'wp-pass-captain' ), 'value' => wp_json_encode( $items ) ),
				array( 'name' => __( 'Tickets und Teilnehmerdaten', 'wp-pass-captain' ), 'value' => wp_json_encode( $tickets ) ),
			) );
		}
		$passes = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,valid_from,valid_until,status FROM {$wpdb->prefix}wpcap_member_passes WHERE member_email=%s ORDER BY pass_id LIMIT 50 OFFSET %d", sanitize_email( $email_address ), max( 0, ( absint( $page ) - 1 ) * 50 ) ), ARRAY_A );
		foreach ( $passes as $pass ) {
			$data[] = array( 'group_id' => 'wp-pass-captain-orders', 'group_label' => __( 'WP Pass Captain Pässe', 'wp-pass-captain' ), 'item_id' => 'pass-' . $pass['public_id'], 'data' => array(
				array( 'name' => __( 'Passreferenz', 'wp-pass-captain' ), 'value' => $pass['public_id'] ),
				array( 'name' => __( 'Gültig von/bis (UTC)', 'wp-pass-captain' ), 'value' => $pass['valid_from'] . ' / ' . $pass['valid_until'] ),
				array( 'name' => __( 'Status', 'wp-pass-captain' ), 'value' => $pass['status'] ),
			) );
		}
		return array( 'data' => $data, 'done' => count( $orders ) < 50 && count( $passes ) < 50 );
	}

	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email_address );
		$orders = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$wpdb->prefix}wpcap_orders WHERE customer_email=%s OR order_id IN (SELECT DISTINCT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE attendee_email=%s) ORDER BY order_id ASC LIMIT 50 OFFSET %d", $email, $email, max( 0, ( absint( $page ) - 1 ) * 50 ) ) );
		$removed = 0;
		foreach ( $orders as $order_id ) {
			$replacement = 'deleted+' . substr( hash( 'sha256', $email . '|' . $order_id ), 0, 24 ) . '@invalid.example';
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'customer_email' => $replacement, 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
			$wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'status' => 'revoked', 'token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'attendee_email' => '' ), array( 'order_id' => absint( $order_id ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_ticket_assignments SET buyer_email=%s,attendee_email='',attendee_name='' WHERE ticket_id IN (SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d)", $replacement, absint( $order_id ) ) );
			Audit::record( 'privacy_erasure', 'order', absint( $order_id ) );
			++$removed;
		}
		$passes_removed = $wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'member_email' => 'deleted+' . substr( hash( 'sha256', $email ), 0, 24 ) . '@invalid.example', 'token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'status' => 'revoked' ), array( 'member_email' => $email ), array( '%s', '%s', '%s' ), array( '%s' ) );
		return array( 'items_removed' => $removed > 0 || $passes_removed > 0, 'items_retained' => $removed > 0 || $passes_removed > 0, 'messages' => array( __( 'Finanzielle Bestellpositionen bleiben für gesetzliche Aufbewahrungspflichten erhalten; Käufer-E-Mail, Teilnehmerdaten und Passzugänge wurden entfernt.', 'wp-pass-captain' ) ), 'done' => count( $orders ) < 50 );
	}
}
