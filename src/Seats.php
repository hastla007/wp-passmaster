<?php
/** Atomic seat holds for versioned seating plans. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Seats {
	public static function hold( $occurrence_id, array $seat_ids, $order_id = 0, $minutes = 10, $idempotency_key = '' ) {
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		$seat_ids = array_values( array_unique( array_filter( array_map( 'absint', $seat_ids ) ) ) );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( ! $occurrence_id || ! $seat_ids || count( $seat_ids ) > 8 || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_seat_selection_invalid', __( 'Sitzplatzauswahl oder Idempotency-Key ungültig.', 'pass-captain' ) ); }
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$rate_key = 'wpcap_seathold_' . hash( 'sha256', $ip );
		$rate = (int) get_transient( $rate_key );
		if ( $rate >= 20 ) { return new \WP_Error( 'wpcap_seat_hold_rate', __( 'Zu viele Sitzplatzreservierungen; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $rate_key, $rate + 1, HOUR_IN_SECONDS );
		$idempotency_key = hash( 'sha256', $idempotency_key );
		sort( $seat_ids, SORT_NUMERIC );
		$wpdb->query( 'START TRANSACTION' );
		$holds = $wpdb->prefix . 'wpcap_seat_holds';
		$seats = $wpdb->prefix . 'wpcap_seats';
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT hold_id,seat_id,expires_at,status FROM $holds WHERE request_key=%s", $idempotency_key ) );
		if ( $existing ) {
			$existing_ids = array_map( 'intval', wp_list_pluck( $existing, 'seat_id' ) );
			sort( $existing_ids, SORT_NUMERIC );
			if ( $existing_ids !== $seat_ids ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_idempotency_conflict', __( 'Idempotency-Key wurde bereits für andere Sitzplätze verwendet.', 'pass-captain' ), array( 'status' => 409 ) ); }
			if ( 'active' !== $existing[0]->status || $existing[0]->expires_at <= current_time( 'mysql', true ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_hold_expired', __( 'Diese Sitzplatzreservierung ist abgelaufen oder bereits abgeschlossen.', 'pass-captain' ), array( 'status' => 409 ) ); }
			$wpdb->query( 'COMMIT' );
			return array( 'seat_ids' => $existing_ids, 'expires_at' => $existing[0]->expires_at, 'reused' => true );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE $holds SET status='expired' WHERE status='active' AND expires_at <= %s", $now ) );
		foreach ( $seat_ids as $seat_id ) {
			$seat = $wpdb->get_row( $wpdb->prepare( "SELECT s.seat_id,s.status FROM $seats s INNER JOIN {$wpdb->prefix}wpcap_seat_maps m ON m.map_id=s.map_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=m.event_id WHERE s.seat_id=%d AND o.occurrence_id=%d AND m.status='published' FOR UPDATE", $seat_id, $occurrence_id ) );
			$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $holds WHERE occurrence_id=%d AND seat_id=%d AND status IN ('active','sold')", $occurrence_id, $seat_id ) );
			if ( ! $seat || 'available' !== $seat->status || $active ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_unavailable', __( 'Mindestens ein Sitzplatz ist nicht mehr verfügbar.', 'pass-captain' ), array( 'status' => 409 ) ); }
		}
		$expires = gmdate( 'Y-m-d H:i:s', time() + min( 15, max( 1, absint( $minutes ) ) ) * MINUTE_IN_SECONDS );
		foreach ( $seat_ids as $seat_id ) {
			$wpdb->insert( $holds, array( 'occurrence_id' => $occurrence_id, 'seat_id' => $seat_id, 'order_id' => absint( $order_id ), 'request_key' => $idempotency_key, 'expires_at' => $expires, 'status' => 'active' ), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
			if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_seat_hold_failed', __( 'Sitzplätze konnten nicht reserviert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		}
		$wpdb->query( 'COMMIT' );
		return array( 'seat_ids' => $seat_ids, 'expires_at' => $expires );
	}
}
