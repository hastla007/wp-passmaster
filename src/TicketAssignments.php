<?php
/** Buyer-controlled participant assignment for group orders. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketAssignments {
	public static function assign( $public_id, $access_token, $ticket_public_id, $name, $email ) {
		global $wpdb;
		$public_id = sanitize_text_field( $public_id );
		$access_token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $access_token ) );
		$email = sanitize_email( $email );
		$name = sanitize_text_field( $name );
		if ( 32 !== strlen( $public_id ) || 64 !== strlen( $access_token ) || ! $name || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_assignment_invalid', __( 'Teilnehmerdaten ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$orders = $wpdb->prefix . 'wpcap_orders';
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $orders WHERE public_id=%s", $public_id ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) { return new \WP_Error( 'wpcap_assignment_denied', __( 'Bestellzugriff ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		if ( 'paid' !== $order->payment_status ) { return new \WP_Error( 'wpcap_assignment_unpaid', __( 'Teilnehmer können erst nach Zahlung zugewiesen werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.status,e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.public_id=%s AND t.order_id=%d FOR UPDATE", sanitize_text_field( $ticket_public_id ), $order->order_id ) );
		if ( ! $ticket || 'valid' !== $ticket->status || strtotime( $ticket->starts_at . ' UTC' ) <= time() ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_not_allowed', __( 'Dieses Ticket kann nicht mehr zugewiesen werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$code = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_tickets', array( 'attendee_email' => $email, 'token_hash' => hash( 'sha256', $code ) ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'valid' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_failed', __( 'Ticket konnte nicht zugewiesen werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$assignment_table = $wpdb->prefix . 'wpcap_ticket_assignments';
		$wpdb->query( $wpdb->prepare( "INSERT INTO $assignment_table (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE buyer_email=VALUES(buyer_email),attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name),status='assigned'", $ticket->ticket_id, $order->customer_email, $email, $name, current_time( 'mysql', true ) ) );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_assignment_failed', __( 'Teilnehmerdaten konnten nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		$url = add_query_arg( 'wpcap_ticket', $code, home_url( '/' ) );
		$body = sprintf( "%s — %s\n%s %s\n%s\n%s\n\n%s", $ticket->event_name, $ticket->ticket_name, mysql2date( 'd.m.Y H:i', $ticket->starts_at, true ), $ticket->timezone, $ticket->location, sprintf( __( 'Teilnehmer: %s', 'wp-pass-captain' ), $name ), esc_url_raw( $url ) );
		wp_mail( $email, __( 'Dein WP Pass Captain Ticket', 'wp-pass-captain' ), $body );
		Audit::record( 'ticket_assigned', 'ticket', $ticket->ticket_id, array( 'order_id' => (int) $order->order_id ) );
		return array( 'status' => 'assigned' );
	}
}
