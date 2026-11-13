<?php
/**
 * Resource and appointment-slot reservation service; management of resources and slots shared by
 * the REST routes and the admin page "Service-Zeitfenster" (A157). Times are stored in UTC.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceBookings {
	public static function reserve( $slot_id, $email, $quantity = 1, $order_id = 0, $minutes = 10, $idempotency_key = '' ) {
		global $wpdb;
		$slot_id = absint( $slot_id );
		$email = sanitize_email( $email );
		$quantity = min( 20, max( 1, absint( $quantity ) ) );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( ! $slot_id || ! is_email( $email ) || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ) ); }
		$rate_key = 'wpcap_servicebook_' . hash( 'sha256', strtolower( $email ) );
		$rate = (int) get_transient( $rate_key );
		if ( $rate >= 20 ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $rate_key, $rate + 1, HOUR_IN_SECONDS );
		$ip_key = 'wpcap_servicebook_ip_' . hash( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
		$ip_rate = (int) get_transient( $ip_key );
		if ( $ip_rate >= (int) apply_filters( 'wpcap_service_booking_ip_limit', 40 ) ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $ip_key, $ip_rate + 1, HOUR_IN_SECONDS );
		$idempotency_key = hash( 'sha256', $idempotency_key );
		$wpdb->query( 'START TRANSACTION' );
		$slots = $wpdb->prefix . 'wpcap_service_slots';
		$bookings = $wpdb->prefix . 'wpcap_service_bookings';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT booking_id,slot_id,customer_email,quantity,expires_at,status FROM $bookings WHERE idempotency_key=%s", $idempotency_key ) );
		if ( $existing ) {
			if ( (int) $existing->slot_id !== $slot_id || strtolower( $existing->customer_email ) !== strtolower( $email ) || (int) $existing->quantity !== $quantity ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_idempotency_conflict', __( 'Idempotency-Key wurde für eine andere Buchung verwendet.', 'pass-captain' ), array( 'status' => 409 ) ); }
			$wpdb->query( 'COMMIT' );
			return array( 'booking_id' => (int) $existing->booking_id, 'expires_at' => $existing->expires_at, 'status' => $existing->status, 'reused' => true );
		}
		$now = current_time( 'mysql', true );
		$slot = $wpdb->get_row( $wpdb->prepare( "SELECT s.* FROM $slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE s.slot_id=%d AND r.status='active' AND e.status='published' FOR UPDATE", $slot_id ) );
		if ( ! $slot || 'open' !== $slot->status || $slot->starts_at <= $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_slot_unavailable', __( 'Dieses Zeitfenster ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->query( $wpdb->prepare( "UPDATE $bookings SET status='expired' WHERE slot_id=%d AND status='held' AND expires_at <= %s", $slot_id, $now ) );
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM $bookings WHERE slot_id=%d AND status IN ('held','confirmed') AND (expires_at IS NULL OR expires_at > %s)", $slot_id, $now ) );
		if ( $used + $quantity > (int) $slot->capacity ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_slot_full', __( 'Für dieses Zeitfenster sind nicht genügend Plätze frei.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$expires = gmdate( 'Y-m-d H:i:s', time() + min( 15, max( 1, absint( $minutes ) ) ) * MINUTE_IN_SECONDS );
		$wpdb->insert( $bookings, array( 'slot_id' => $slot_id, 'order_id' => absint( $order_id ), 'customer_email' => $email, 'idempotency_key' => $idempotency_key, 'quantity' => $quantity, 'expires_at' => $expires, 'status' => 'held', 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_failed', __( 'Zeitfenster konnte nicht reserviert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		return array( 'booking_id' => $id, 'expires_at' => $expires );
	}

	/** Booking states that occupy capacity (held only until expires_at); one %s placeholder = now. */
	const ACTIVE_SQL = "(b.status='confirmed' OR (b.status='held' AND (b.expires_at IS NULL OR b.expires_at > %s)))";

	/** Resource row, or null. */
	public static function resource( $resource_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d", absint( $resource_id ) ) );
	}

	/** Slot row with event id and resource name, or null. */
	public static function slot( $slot_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT s.*,r.event_id,r.name AS resource_name FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE s.slot_id=%d", absint( $slot_id ) ) );
	}

	private static function forbidden() {
		return new \WP_Error( 'wpcap_resource_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	private static function not_found() {
		return new \WP_Error( 'wpcap_service_not_found', __( 'Ressource oder Zeitfenster nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	/** Places held or confirmed in a slot (expired holds excluded). */
	public static function booked( $slot_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ACTIVE_SQL is a constant with placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=%d AND " . self::ACTIVE_SQL, absint( $slot_id ), current_time( 'mysql', true ) ) );
	}

	/** Resource input → validated columns (name, capacity 1–100, buffers 0–1440 minutes). */
	private static function resource_data( array $data ) {
		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_resource_invalid', __( 'Ressourcenname fehlt.', 'pass-captain' ), array( 'status' => 400 ) ); }
		return array( 'name' => $name, 'capacity' => min( 100, max( 1, absint( $data['capacity'] ?? 1 ) ) ), 'buffer_before' => min( 1440, absint( $data['buffer_before'] ?? 0 ) ), 'buffer_after' => min( 1440, absint( $data['buffer_after'] ?? 0 ) ) );
	}

	/** Creates a resource of an event (wpcap_manage_events for the event). Returns the id. */
	public static function create_resource( $event_id, array $data ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$row = self::resource_data( $data );
		if ( is_wp_error( $row ) ) { return $row; }
		$wpdb->insert( $wpdb->prefix . 'wpcap_resources', array( 'event_id' => $event_id ) + $row + array( 'status' => 'active' ), array( '%d', '%s', '%d', '%d', '%d', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_resource_save', __( 'Ressource konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		Audit::record( 'service_resource_created', 'resource', $id, array( 'event_id' => $event_id ) + $row );
		return $id;
	}

	/** Updates name, capacity, buffers and status (active/inactive) of a resource. */
	public static function update_resource( $resource_id, array $data ) {
		global $wpdb;
		$resource = self::resource( $resource_id );
		if ( ! $resource ) { return self::not_found(); }
		if ( ! Access::can_access_event( (int) $resource->event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		$row = self::resource_data( $data );
		if ( is_wp_error( $row ) ) { return $row; }
		$row['status'] = in_array( $data['status'] ?? '', array( 'active', 'inactive' ), true ) ? $data['status'] : $resource->status;
		$wpdb->update( $wpdb->prefix . 'wpcap_resources', $row, array( 'resource_id' => (int) $resource->resource_id ), array( '%s', '%d', '%d', '%d', '%s' ), array( '%d' ) );
		Audit::record( 'service_resource_updated', 'resource', (int) $resource->resource_id, array( 'event_id' => (int) $resource->event_id, 'before' => array( 'name' => $resource->name, 'capacity' => (int) $resource->capacity, 'status' => $resource->status ), 'after' => $row ) );
		return true;
	}

	/** Deletes a resource with its slots – only when none of its slots ever had a booking. */
	public static function delete_resource( $resource_id ) {
		global $wpdb;
		$resource = self::resource( $resource_id );
		if ( ! $resource ) { return self::not_found(); }
		if ( ! Access::can_access_event( (int) $resource->event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		$bookings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_bookings b INNER JOIN {$wpdb->prefix}wpcap_service_slots s ON s.slot_id=b.slot_id WHERE s.resource_id=%d", (int) $resource->resource_id ) );
		if ( $bookings ) { return new \WP_Error( 'wpcap_resource_has_bookings', __( 'Die Ressource hat Buchungen und kann nicht gelöscht werden. Deaktiviere sie stattdessen.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$slots = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_service_slots WHERE resource_id=%d", (int) $resource->resource_id ) );
		$wpdb->delete( $wpdb->prefix . 'wpcap_resources', array( 'resource_id' => (int) $resource->resource_id ), array( '%d' ) );
		Audit::record( 'service_resource_deleted', 'resource', (int) $resource->resource_id, array( 'event_id' => (int) $resource->event_id, 'name' => $resource->name, 'slots_deleted' => $slots ) );
		return true;
	}

	/** UTC timestamp of a slot time: 'Y-m-d H:i[:s]' (also with T) is read as UTC, other strings via strtotime(). */
	private static function utc_time( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value ) ) { $value = str_replace( 'T', ' ', $value ) . ' UTC'; }
		return '' === $value ? false : strtotime( $value );
	}

	/** Slot input → validated start/end (UTC 'Y-m-d H:i:s'). */
	private static function slot_times( array $data ) {
		$start = self::utc_time( $data['starts_at'] ?? '' );
		$end = self::utc_time( $data['ends_at'] ?? '' );
		if ( ! $start || ! $end || $end <= $start ) { return new \WP_Error( 'wpcap_service_slot_invalid', __( 'Zeitfenster ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		return array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $end ) );
	}

	/** Creates an open slot for an active resource ($data: starts_at, ends_at in UTC, capacity). Returns the id. */
	public static function create_slot( $resource_id, array $data ) {
		global $wpdb;
		$resource = self::resource( $resource_id );
		if ( ! $resource || 'active' !== $resource->status || ! Access::can_access_event( (int) $resource->event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_resource_forbidden', __( 'Keine Berechtigung für diese Ressource.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$times = self::slot_times( $data );
		if ( is_wp_error( $times ) ) { return $times; }
		$capacity = isset( $data['capacity'] ) && '' !== (string) $data['capacity'] ? absint( $data['capacity'] ) : (int) $resource->capacity;
		$row = array( 'resource_id' => (int) $resource->resource_id ) + $times + array( 'capacity' => min( 100, max( 1, $capacity ) ), 'status' => 'open' );
		$wpdb->insert( $wpdb->prefix . 'wpcap_service_slots', $row, array( '%d', '%s', '%s', '%d', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_service_slot_save', __( 'Zeitfenster konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		Audit::record( 'service_slot_created', 'service_slot', $id, array( 'event_id' => (int) $resource->event_id ) + $row );
		return $id;
	}

	/**
	 * Updates capacity, status (open/closed) and – only without active bookings – the time of a slot.
	 * The capacity cannot drop below the places already booked.
	 */
	public static function update_slot( $slot_id, array $data ) {
		global $wpdb;
		$slot = self::slot( $slot_id );
		if ( ! $slot ) { return self::not_found(); }
		if ( ! Access::can_access_event( (int) $slot->event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		$times = self::slot_times( $data );
		if ( is_wp_error( $times ) ) { return $times; }
		$capacity = min( 100, max( 1, isset( $data['capacity'] ) && '' !== (string) $data['capacity'] ? absint( $data['capacity'] ) : (int) $slot->capacity ) );
		$status = in_array( $data['status'] ?? '', array( 'open', 'closed' ), true ) ? $data['status'] : $slot->status;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$wpdb->prefix}wpcap_service_slots WHERE slot_id=%d FOR UPDATE", (int) $slot->slot_id ) );
		$booked = self::booked( $slot->slot_id );
		if ( $capacity < $booked ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error(
				'wpcap_service_slot_capacity',
				/* translators: %d: number of booked places */
				sprintf( __( 'Die Kapazität darf nicht unter die bereits gebuchten Plätze (%d) sinken.', 'pass-captain' ), $booked ),
				array( 'status' => 409 )
			);
		}
		// Minute precision: the admin form has no seconds.
		$moved = substr( $times['starts_at'], 0, 16 ) !== substr( (string) $slot->starts_at, 0, 16 ) || substr( $times['ends_at'], 0, 16 ) !== substr( (string) $slot->ends_at, 0, 16 );
		if ( $booked && $moved ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_service_slot_booked', __( 'Das Zeitfenster hat Buchungen; die Uhrzeit kann nicht mehr geändert werden. Schließe es und lege ein neues an.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! $moved ) { $times = array( 'starts_at' => (string) $slot->starts_at, 'ends_at' => (string) $slot->ends_at ); }
		$row = $times + array( 'capacity' => $capacity, 'status' => $status );
		$wpdb->update( $wpdb->prefix . 'wpcap_service_slots', $row, array( 'slot_id' => (int) $slot->slot_id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'service_slot_updated', 'service_slot', (int) $slot->slot_id, array( 'event_id' => (int) $slot->event_id, 'before' => array( 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'capacity' => (int) $slot->capacity, 'status' => $slot->status ), 'after' => $row ) );
		return true;
	}

	/** Deletes a slot that never had a booking (expired holds count: they stay as history). */
	public static function delete_slot( $slot_id ) {
		global $wpdb;
		$slot = self::slot( $slot_id );
		if ( ! $slot ) { return self::not_found(); }
		if ( ! Access::can_access_event( (int) $slot->event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_bookings WHERE slot_id=%d", (int) $slot->slot_id ) ) ) {
			return new \WP_Error( 'wpcap_service_slot_has_bookings', __( 'Das Zeitfenster hat Buchungen und kann nicht gelöscht werden. Schließe es stattdessen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$wpdb->delete( $wpdb->prefix . 'wpcap_service_slots', array( 'slot_id' => (int) $slot->slot_id ), array( '%d' ) );
		Audit::record( 'service_slot_deleted', 'service_slot', (int) $slot->slot_id, array( 'event_id' => (int) $slot->event_id, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at ) );
		return true;
	}

	/** Resources of an event with slot count. */
	public static function resources( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT r.*,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_slots s WHERE s.resource_id=r.resource_id) AS slot_count FROM {$wpdb->prefix}wpcap_resources r WHERE r.event_id=%d ORDER BY r.name,r.resource_id", absint( $event_id ) ) );
	}

	/** Slots of an event (all states) with booked places and number of booking rows, ordered by start. */
	public static function slots( $event_id, $limit = 500 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ACTIVE_SQL is a constant with placeholders.
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.*,r.name AS resource_name,r.event_id,(SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND " . self::ACTIVE_SQL . ") AS booked,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_bookings b2 WHERE b2.slot_id=s.slot_id) AS booking_rows FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE r.event_id=%d ORDER BY s.starts_at,s.slot_id LIMIT %d", current_time( 'mysql', true ), absint( $event_id ), max( 1, min( 2000, absint( $limit ) ) ) ) );
	}

	/** Bookings of an event (optionally of one slot), newest first. */
	public static function bookings( $event_id, $slot_id = 0, $limit = 200 ) {
		global $wpdb;
		$slot_sql = $slot_id ? $wpdb->prepare( ' AND s.slot_id=%d', absint( $slot_id ) ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $slot_sql is prepared above.
		return $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_id,b.slot_id,b.order_id,b.customer_email,b.quantity,b.expires_at,b.status,b.created_at,s.starts_at,s.ends_at,r.name AS resource_name FROM {$wpdb->prefix}wpcap_service_bookings b INNER JOIN {$wpdb->prefix}wpcap_service_slots s ON s.slot_id=b.slot_id INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE r.event_id=%d$slot_sql ORDER BY b.booking_id DESC LIMIT %d", absint( $event_id ), max( 1, min( 1000, absint( $limit ) ) ) ) );
	}

	/** Display status of a booking (held bookings past expires_at count as expired). */
	public static function booking_status( $booking ) {
		if ( 'held' === $booking->status && $booking->expires_at && $booking->expires_at <= current_time( 'mysql', true ) ) { return 'expired'; }
		return (string) $booking->status;
	}
}
