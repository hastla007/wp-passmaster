<?php
/**
 * Management of bookable service resources and their time slots (admin side).
 * Booking itself lives in ServiceBookings; this class only maintains the master data.
 * Access checks are done by the callers (admin screen, REST).
 */
namespace PassCaptain;

use PassCaptain\Admin\Forms;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceResources {
	const RESOURCE_STATUSES = array( 'active', 'inactive' );
	const SLOT_STATUSES = array( 'open', 'closed' );
	const MAX_CAPACITY = 100;
	/** Upper bound of slots one weekly generator run may create. */
	const MAX_GENERATED = 500;

	public static function resource( $resource_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d", absint( $resource_id ) ) );
	}

	public static function slot( $slot_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT s.*,r.event_id,r.name AS resource_name FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE s.slot_id=%d", absint( $slot_id ) ) );
	}

	/** Resources of the given events (null = all), newest first. */
	public static function resources( $event_ids, $event_id = 0 ) {
		global $wpdb;
		$where = Admin\Queries::scope_sql( $event_ids, 'r.event_id' );
		if ( $event_id ) { $where .= $wpdb->prepare( ' AND r.event_id=%d', absint( $event_id ) ); }
		return $wpdb->get_results( "SELECT r.*,e.name AS event_name,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_slots s WHERE s.resource_id=r.resource_id) AS slot_count FROM {$wpdb->prefix}wpcap_resources r INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE 1=1{$where} ORDER BY e.name,r.name,r.resource_id LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope built from absint ids.
	}

	/** Timezone of a resource: its own, else the event's first occurrence, else the site timezone. */
	public static function timezone_of( $resource ) {
		if ( $resource && Forms::is_valid_timezone( (string) ( $resource->timezone ?? '' ) ) ) { return (string) $resource->timezone; }
		if ( $resource ) {
			global $wpdb;
			$tz = (string) $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at LIMIT 1", (int) $resource->event_id ) );
			if ( Forms::is_valid_timezone( $tz ) ) { return $tz; }
		}
		return Reports\Scope::site_timezone();
	}

	/**
	 * Creates or updates a resource.
	 *
	 * @return int|\WP_Error Resource id.
	 */
	public static function save_resource( array $data, $resource_id = 0 ) {
		global $wpdb;
		$resource_id = absint( $resource_id );
		$event_id = absint( $data['event_id'] ?? 0 );
		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		$timezone = (string) ( $data['timezone'] ?? '' );
		$status = in_array( (string) ( $data['status'] ?? 'active' ), self::RESOURCE_STATUSES, true ) ? (string) $data['status'] : 'active';
		if ( '' === $name ) { return Forms::error( 'wpcap_resource_invalid', __( 'Ressourcenname fehlt.', 'pass-captain' ) ); }
		if ( '' !== $timezone && ! Forms::is_valid_timezone( $timezone ) ) { return Forms::error( 'wpcap_resource_timezone', __( 'Ungültige Zeitzone.', 'pass-captain' ) ); }
		$values = array( 'name' => $name, 'timezone' => $timezone, 'capacity' => min( self::MAX_CAPACITY, max( 1, absint( $data['capacity'] ?? 1 ) ) ), 'status' => $status );
		if ( $resource_id ) {
			$existing = self::resource( $resource_id );
			if ( ! $existing ) { return Forms::error( 'wpcap_resource_missing', __( 'Ressource nicht gefunden.', 'pass-captain' ), 404 ); }
			if ( false === $wpdb->update( $wpdb->prefix . 'wpcap_resources', $values, array( 'resource_id' => $resource_id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) ) ) {
				return Forms::error( 'wpcap_resource_save', __( 'Ressource konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
			}
			Audit::record( 'service_resource_updated', 'resource', $resource_id, array( 'event_id' => (int) $existing->event_id ) + $values );
			return $resource_id;
		}
		if ( ! $event_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return Forms::error( 'wpcap_resource_event', __( 'Bitte ein gültiges Event wählen.', 'pass-captain' ) ); }
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_resources', array( 'event_id' => $event_id ) + $values + array( 'buffer_before' => 0, 'buffer_after' => 0 ), array( '%d', '%s', '%s', '%d', '%s', '%d', '%d' ) );
		if ( false === $inserted || ! $wpdb->insert_id ) { return Forms::error( 'wpcap_resource_save', __( 'Ressource konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$resource_id = (int) $wpdb->insert_id;
		Audit::record( 'service_resource_created', 'resource', $resource_id, array( 'event_id' => $event_id ) + $values );
		return $resource_id;
	}

	/** Sum of held (unexpired) and confirmed quantities of a slot. */
	public static function booked_quantity( $slot_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings WHERE slot_id=%d AND (status='confirmed' OR (status='held' AND (expires_at IS NULL OR expires_at > %s)))", absint( $slot_id ), current_time( 'mysql', true ) ) );
	}

	/** Slots of a resource with booked quantities, chronological. */
	public static function slots( $resource_id, $limit = 300 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.*,(SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND (b.status='confirmed' OR (b.status='held' AND (b.expires_at IS NULL OR b.expires_at > %s)))) AS booked FROM {$wpdb->prefix}wpcap_service_slots s WHERE s.resource_id=%d ORDER BY s.starts_at,s.slot_id LIMIT %d", $now, absint( $resource_id ), max( 1, (int) $limit ) ) );
	}

	/** All bookings of a slot, newest first. */
	public static function bookings( $slot_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT booking_id,order_id,customer_email,quantity,expires_at,status,created_at FROM {$wpdb->prefix}wpcap_service_bookings WHERE slot_id=%d ORDER BY created_at DESC,booking_id DESC LIMIT 500", absint( $slot_id ) ) );
	}

	private static function valid_range( $starts_utc, $ends_utc ) {
		return is_string( $starts_utc ) && is_string( $ends_utc ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $starts_utc ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ends_utc ) && $ends_utc > $starts_utc;
	}

	/**
	 * Creates one slot (times in UTC).
	 *
	 * @return int|\WP_Error Slot id.
	 */
	public static function create_slot( $resource_id, $starts_utc, $ends_utc, $capacity = 0, $audit = true ) {
		global $wpdb;
		$resource = self::resource( $resource_id );
		if ( ! $resource ) { return Forms::error( 'wpcap_resource_missing', __( 'Ressource nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::valid_range( $starts_utc, $ends_utc ) ) { return Forms::error( 'wpcap_service_slot_invalid', __( 'Zeitfenster ist ungültig: Das Ende muss nach dem Beginn liegen.', 'pass-captain' ) ); }
		$capacity = min( self::MAX_CAPACITY, max( 1, absint( $capacity ) ?: (int) $resource->capacity ) );
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_service_slots', array( 'resource_id' => (int) $resource->resource_id, 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'capacity' => $capacity, 'status' => 'open' ), array( '%d', '%s', '%s', '%d', '%s' ) );
		if ( false === $inserted || ! $wpdb->insert_id ) { return Forms::error( 'wpcap_service_slot_save', __( 'Zeitfenster konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$slot_id = (int) $wpdb->insert_id;
		if ( $audit ) { Audit::record( 'service_slot_created', 'service_slot', $slot_id, array( 'resource_id' => (int) $resource->resource_id, 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'capacity' => $capacity ) ); }
		return $slot_id;
	}

	/**
	 * Weekly generator: one slot per selected weekday between two dates (local time of the resource).
	 *
	 * @param array $args start_date, until_date (Y-m-d), weekdays (1=Mon … 7=Sun), time (H:i), duration (minutes), capacity.
	 * @return array|\WP_Error ['created' => int[], 'skipped' => int]
	 */
	public static function generate_weekly( $resource_id, array $args ) {
		global $wpdb;
		$resource = self::resource( $resource_id );
		if ( ! $resource ) { return Forms::error( 'wpcap_resource_missing', __( 'Ressource nicht gefunden.', 'pass-captain' ), 404 ); }
		$tz = self::timezone_of( $resource );
		$start = (string) ( $args['start_date'] ?? '' );
		$until = (string) ( $args['until_date'] ?? '' );
		$time = (string) ( $args['time'] ?? '' );
		$duration = absint( $args['duration'] ?? 0 );
		$weekdays = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['weekdays'] ?? array() ) ), static function ( $d ) { return $d >= 1 && $d <= 7; } ) ) );
		$valid_date = static function ( $d ) { return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ); };
		if ( ! $valid_date( $start ) || ! $valid_date( $until ) || $until < $start || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) || $duration < 5 || $duration > 1440 || ! $weekdays ) {
			return Forms::error( 'wpcap_service_generator_invalid', __( 'Bitte Zeitraum, mindestens einen Wochentag, eine Uhrzeit und eine Dauer von 5 bis 1440 Minuten angeben.', 'pass-captain' ) );
		}
		$day = new \DateTimeImmutable( $start . ' 00:00:00', new \DateTimeZone( $tz ) );
		$last = new \DateTimeImmutable( $until . ' 00:00:00', new \DateTimeZone( $tz ) );
		if ( $day->diff( $last )->days > 366 ) { return Forms::error( 'wpcap_service_generator_range', __( 'Der Zeitraum darf höchstens ein Jahr umfassen.', 'pass-captain' ) ); }
		$planned = array();
		for ( ; $day <= $last; $day = $day->modify( '+1 day' ) ) {
			if ( ! in_array( (int) $day->format( 'N' ), $weekdays, true ) ) { continue; }
			$starts = Forms::local_to_utc( $day->format( 'Y-m-d' ) . ' ' . $time, $tz );
			if ( null === $starts ) { continue; }
			$planned[] = array( $starts, gmdate( 'Y-m-d H:i:s', strtotime( $starts . ' UTC' ) + $duration * 60 ) );
		}
		if ( count( $planned ) > self::MAX_GENERATED ) {
			/* translators: %d: maximum number of slots */
			return Forms::error( 'wpcap_service_generator_many', sprintf( __( 'Es können höchstens %d Zeitfenster auf einmal erzeugt werden.', 'pass-captain' ), self::MAX_GENERATED ) );
		}
		$created = array();
		$skipped = 0;
		foreach ( $planned as list( $starts, $ends ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$wpdb->prefix}wpcap_service_slots WHERE resource_id=%d AND starts_at=%s LIMIT 1", (int) $resource->resource_id, $starts ) );
			if ( $exists ) { $skipped++; continue; }
			$id = self::create_slot( (int) $resource->resource_id, $starts, $ends, absint( $args['capacity'] ?? 0 ), false );
			if ( is_wp_error( $id ) ) { return $id; }
			$created[] = $id;
		}
		Audit::record( 'service_slots_generated', 'resource', (int) $resource->resource_id, array( 'created' => count( $created ), 'skipped' => $skipped, 'start_date' => $start, 'until_date' => $until, 'weekdays' => $weekdays, 'time' => $time, 'duration' => $duration ) );
		return array( 'created' => $created, 'skipped' => $skipped );
	}

	/**
	 * Updates times, capacity and status of a slot. Capacity cannot drop below booked places.
	 *
	 * @return int|\WP_Error Slot id.
	 */
	public static function update_slot( $slot_id, $starts_utc, $ends_utc, $capacity, $status ) {
		global $wpdb;
		$slot = self::slot( $slot_id );
		if ( ! $slot ) { return Forms::error( 'wpcap_service_slot_missing', __( 'Zeitfenster nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! self::valid_range( $starts_utc, $ends_utc ) ) { return Forms::error( 'wpcap_service_slot_invalid', __( 'Zeitfenster ist ungültig: Das Ende muss nach dem Beginn liegen.', 'pass-captain' ) ); }
		$capacity = min( self::MAX_CAPACITY, max( 1, absint( $capacity ) ) );
		$status = in_array( (string) $status, self::SLOT_STATUSES, true ) ? (string) $status : 'open';
		$booked = self::booked_quantity( $slot->slot_id );
		if ( $capacity < $booked ) {
			/* translators: %d: booked places */
			return Forms::error( 'wpcap_service_slot_capacity', sprintf( __( 'Die Kapazität darf nicht unter die bereits gebuchten Plätze (%d) sinken.', 'pass-captain' ), $booked ) );
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_service_slots', array( 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'capacity' => $capacity, 'status' => $status ), array( 'slot_id' => (int) $slot->slot_id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		Audit::record( 'service_slot_updated', 'service_slot', (int) $slot->slot_id, array( 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'capacity' => $capacity, 'status' => $status, 'booked' => $booked ) );
		return (int) $slot->slot_id;
	}

	/**
	 * Deletes a slot without active bookings (held or confirmed). Expired/cancelled booking rows go with it.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_slot( $slot_id ) {
		global $wpdb;
		$slot = self::slot( $slot_id );
		if ( ! $slot ) { return Forms::error( 'wpcap_service_slot_missing', __( 'Zeitfenster nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( self::booked_quantity( $slot->slot_id ) > 0 ) { return Forms::error( 'wpcap_service_slot_booked', __( 'Zeitfenster mit aktiven Buchungen können nicht gelöscht werden. Bitte stattdessen schließen.', 'pass-captain' ), 409 ); }
		$wpdb->delete( $wpdb->prefix . 'wpcap_service_bookings', array( 'slot_id' => (int) $slot->slot_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wpcap_service_slots', array( 'slot_id' => (int) $slot->slot_id ), array( '%d' ) );
		Audit::record( 'service_slot_deleted', 'service_slot', (int) $slot->slot_id, array( 'resource_id' => (int) $slot->resource_id, 'starts_at' => $slot->starts_at ) );
		return true;
	}
}
