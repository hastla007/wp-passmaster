<?php
/** Resource and appointment-slot reservation service. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceBookings {
	public static function reserve( $slot_id, $email, $quantity = 1, $order_id = 0, $minutes = 10, $idempotency_key = '' ) {
		global $wpdb;
		$slot_id = absint( $slot_id );
		$email = sanitize_email( $email );
		$quantity = min( 20, max( 1, absint( $quantity ) ) );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( ! $slot_id || ! is_email( $email ) || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'wp-pass-captain' ) ); }
		$rate_key = 'wpcap_servicebook_' . hash( 'sha256', strtolower( $email ) );
		$rate = (int) get_transient( $rate_key );
		if ( $rate >= 20 ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'wp-pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $rate_key, $rate + 1, HOUR_IN_SECONDS );
		$idempotency_key = hash( 'sha256', $idempotency_key );
		$wpdb->query( 'START TRANSACTION' );
		$slots = $wpdb->prefix . 'wpcap_service_slots';
		$bookings = $wpdb->prefix . 'wpcap_service_bookings';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT booking_id,slot_id,customer_email,quantity,expires_at,status FROM $bookings WHERE idempotency_key=%s", $idempotency_key ) );
		if ( $existing ) {
			if ( (int) $existing->slot_id !== $slot_id || strtolower( $existing->customer_email ) !== strtolower( $email ) || (int) $existing->quantity !== $quantity ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_idempotency_conflict', __( 'Idempotency-Key wurde für eine andere Buchung verwendet.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
			$wpdb->query( 'COMMIT' );
			return array( 'booking_id' => (int) $existing->booking_id, 'expires_at' => $existing->expires_at, 'status' => $existing->status, 'reused' => true );
		}
		$now = current_time( 'mysql', true );
		$slot = $wpdb->get_row( $wpdb->prepare( "SELECT s.* FROM $slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE s.slot_id=%d AND r.status='active' AND e.status='published' FOR UPDATE", $slot_id ) );
		if ( ! $slot || 'open' !== $slot->status || $slot->starts_at <= $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_slot_unavailable', __( 'Dieses Zeitfenster ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( $wpdb->prepare( "UPDATE $bookings SET status='expired' WHERE slot_id=%d AND status='held' AND expires_at <= %s", $slot_id, $now ) );
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM $bookings WHERE slot_id=%d AND status IN ('held','confirmed') AND (expires_at IS NULL OR expires_at > %s)", $slot_id, $now ) );
		if ( $used + $quantity > (int) $slot->capacity ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_slot_full', __( 'Für dieses Zeitfenster sind nicht genügend Plätze frei.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$expires = gmdate( 'Y-m-d H:i:s', time() + min( 15, max( 1, absint( $minutes ) ) ) * MINUTE_IN_SECONDS );
		$wpdb->insert( $bookings, array( 'slot_id' => $slot_id, 'order_id' => absint( $order_id ), 'customer_email' => $email, 'idempotency_key' => $idempotency_key, 'quantity' => $quantity, 'expires_at' => $expires, 'status' => 'held', 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_failed', __( 'Zeitfenster konnte nicht reserviert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		return array( 'booking_id' => $id, 'expires_at' => $expires );
	}
}
