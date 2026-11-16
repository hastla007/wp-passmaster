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
	/** Booking statuses that occupy capacity (held only while unexpired). */
	const ACTIVE_STATUSES = array( 'held', 'pending_confirmation', 'confirmed', 'checked_in', 'no_show' );
	const MAX_BUFFER = 1440;
	const PRICE_POLICIES = array( 'same_price', 'refund_difference' );
	const NOSHOW_POLICIES = array( 'none', 'mark', 'forfeit' );
	const LIMIT_PERIODS = array( 'day', 'week', 'month', 'total' );

	/**
	 * Default booking rules of a resource (minutes unless named otherwise). Stored per resource as JSON
	 * in `rules`; missing keys fall back to these values.
	 */
	public static function default_rules() {
		return array(
			'cutoff_minutes'          => 60,      // Buchungsschluss: no new booking later than this before start.
			'opens_days'              => 365,     // Vorlauf: bookings open at most this many days before start (0 = unlimited).
			'max_per_customer'        => 0,       // Places per customer and period (0 = unlimited).
			'max_period'              => 'day',   // day|week|month (local time of the resource) or total (all upcoming).
			'rebook_deadline_minutes' => 1440,    // Buyer rebooking up to this before start.
			'cancel_deadline_minutes' => 1440,    // Buyer cancellation (with refund) up to this before start.
			'price_policy'            => 'same_price', // same_price | refund_difference (cheaper target: partial refund).
			'requires_confirmation'   => 0,       // Partner must confirm paid bookings.
			'confirmation_hours'      => 24,      // Deadline for the partner decision; afterwards declined + refunded.
			'noshow_policy'           => 'none',  // none | mark (no-show recorded) | forfeit (recorded, no refund, fee noted).
			'noshow_grace_minutes'    => 30,      // After slot end before a booking without check-in counts as no-show.
			'noshow_fee_minor'        => 0,       // Fee noted on the booking (collected outside Pass Captain).
			'noshow_block_after'      => 0,       // Block new bookings of a customer after N no-shows (0 = never).
			'noshow_block_days'       => 180,     // Look-back window for the block.
			'waitlist_enabled'        => 1,
			'waitlist_offer_minutes'  => 120,     // Acceptance deadline of a waitlist offer.
		);
	}

	/** Effective rules of a resource (defaults merged with the stored JSON). */
	public static function rules( $resource ) {
		$stored = $resource && ! empty( $resource->rules ) ? json_decode( (string) $resource->rules, true ) : array();
		return self::sanitize_rules( is_array( $stored ) ? $stored : array() );
	}

	/** Validates and clamps a rules array; unknown keys are dropped, missing keys come from $base or the defaults. */
	public static function sanitize_rules( array $in, array $base = array() ) {
		$rules = array_merge( self::default_rules(), $base );
		$ints = array(
			'cutoff_minutes' => array( 0, 525600 ), 'opens_days' => array( 0, 3650 ), 'max_per_customer' => array( 0, 1000 ),
			'rebook_deadline_minutes' => array( 0, 525600 ), 'cancel_deadline_minutes' => array( 0, 525600 ),
			'requires_confirmation' => array( 0, 1 ), 'confirmation_hours' => array( 1, 720 ),
			'noshow_grace_minutes' => array( 0, 10080 ), 'noshow_fee_minor' => array( 0, 100000000 ),
			'noshow_block_after' => array( 0, 100 ), 'noshow_block_days' => array( 1, 3650 ),
			'waitlist_enabled' => array( 0, 1 ), 'waitlist_offer_minutes' => array( 5, 10080 ),
		);
		foreach ( $ints as $key => list( $min, $max ) ) {
			if ( array_key_exists( $key, $in ) ) { $rules[ $key ] = (int) $in[ $key ]; }
			$rules[ $key ] = min( $max, max( $min, (int) $rules[ $key ] ) );
		}
		$choices = array( 'max_period' => self::LIMIT_PERIODS, 'price_policy' => self::PRICE_POLICIES, 'noshow_policy' => self::NOSHOW_POLICIES );
		foreach ( $choices as $key => $allowed ) {
			if ( array_key_exists( $key, $in ) ) { $rules[ $key ] = (string) $in[ $key ]; }
			if ( ! in_array( $rules[ $key ], $allowed, true ) ) { $rules[ $key ] = self::default_rules()[ $key ]; }
		}
		return array_intersect_key( $rules, self::default_rules() );
	}

	/** Unit price (minor units) and currency of a resource: its linked ticket type, else free. */
	public static function price_of( $resource ) {
		if ( ! $resource || ! (int) ( $resource->ticket_type_id ?? 0 ) ) { return array( 0, '' ); }
		global $wpdb;
		$type = $wpdb->get_row( $wpdb->prepare( 'SELECT price_minor,currency FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', (int) $resource->ticket_type_id ) );
		return $type ? array( (int) $type->price_minor, (string) $type->currency ) : array( 0, '' );
	}

	/* ------------------------------------------------------------------ Partner scope */

	/** Whether the partners module is active. */
	public static function partners_enabled() {
		return in_array( 'PassCaptain\\Modules\\Partners', Modules::classes(), true ) && class_exists( '\\PassCaptain\\Partners\\Repository' );
	}

	/** Partner ids the user (default: current user) is linked to (empty without the partners module). */
	public static function user_partner_ids( $user_id = null ) {
		if ( ! self::partners_enabled() ) { return array(); }
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return $user_id ? Partners\Repository::partners_of_user( $user_id ) : array();
	}

	/**
	 * Whether the current user may manage a resource: event-scoped staff/admins with the capability,
	 * or users linked to the resource's partner (organizer).
	 */
	public static function can_manage( $resource, $capability = 'wpcap_manage_events' ) {
		if ( ! $resource ) { return false; }
		if ( Access::can_access_event( (int) $resource->event_id, $capability ) ) { return true; }
		$partner = (int) ( $resource->partner_id ?? 0 );
		return $partner > 0 && get_current_user_id() && in_array( $partner, self::user_partner_ids(), true );
	}

	/** Partner a new resource of an event belongs to by default (the event's organizer). */
	public static function default_partner( $event_id ) {
		return self::partners_enabled() ? Partners\Repository::partner_of_event( $event_id ) : 0;
	}

	public static function resource( $resource_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d', $wpdb->prefix . 'wpcap_resources', absint( $resource_id ) ) );
	}

	public static function slot( $slot_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT s.*,r.event_id,r.name AS resource_name FROM %i s INNER JOIN %i r ON r.resource_id=s.resource_id WHERE s.slot_id=%d', $wpdb->prefix . 'wpcap_service_slots', $wpdb->prefix . 'wpcap_resources', absint( $slot_id ) ) );
	}

	/** Resources of the given events (null = all), newest first. */
	public static function resources( $event_ids, $event_id = 0, array $partner_ids = array() ) {
		global $wpdb;
		$where = Admin\Queries::scope_sql( $event_ids, 'r.event_id' );
		$partner_ids = array_values( array_filter( array_map( 'absint', $partner_ids ) ) );
		if ( null !== $event_ids && $partner_ids ) {
			// Resources of the user's events plus those of the organizers the user belongs to.
			$where = ' AND ((1=1' . $where . ') OR r.partner_id IN (' . implode( ',', $partner_ids ) . '))';
		}
		if ( $event_id ) { $where .= $wpdb->prepare( ' AND r.event_id=%d', absint( $event_id ) ); }
		return $wpdb->get_results( "SELECT r.*,e.name AS event_name,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_slots s WHERE s.resource_id=r.resource_id) AS slot_count FROM {$wpdb->prefix}wpcap_resources r INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE 1=1{$where} ORDER BY e.name,r.name,r.resource_id LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope built from absint ids.
	}

	/** Timezone of a resource: its own, else the event's first occurrence, else the site timezone. */
	public static function timezone_of( $resource ) {
		if ( $resource && Forms::is_valid_timezone( (string) ( $resource->timezone ?? '' ) ) ) { return (string) $resource->timezone; }
		if ( $resource ) {
			global $wpdb;
			$tz = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM %i WHERE event_id=%d ORDER BY starts_at LIMIT 1', $wpdb->prefix . 'wpcap_occurrences', (int) $resource->event_id ) );
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
		$existing = $resource_id ? self::resource( $resource_id ) : null;
		if ( $resource_id && ! $existing ) { return Forms::error( 'wpcap_resource_missing', __( 'Ressource nicht gefunden.', 'pass-captain' ), 404 ); }
		$event_id = $existing ? (int) $existing->event_id : absint( $data['event_id'] ?? 0 );
		$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		$timezone = (string) ( $data['timezone'] ?? '' );
		$status = (string) ( $data['status'] ?? ( $existing ? $existing->status : 'active' ) );
		$status = in_array( $status, self::RESOURCE_STATUSES, true ) ? $status : 'active';
		if ( '' === $name ) { return Forms::error( 'wpcap_resource_invalid', __( 'Ressourcenname fehlt.', 'pass-captain' ) ); }
		if ( '' !== $timezone && ! Forms::is_valid_timezone( $timezone ) ) { return Forms::error( 'wpcap_resource_timezone', __( 'Ungültige Zeitzone.', 'pass-captain' ) ); }
		if ( ! $existing && ( ! $event_id || ! $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', $event_id ) ) ) ) { return Forms::error( 'wpcap_resource_event', __( 'Bitte ein gültiges Event wählen.', 'pass-captain' ) ); }
		// Keys missing in $data keep the stored value (or the default on create).
		$pick = static function ( $key, $default ) use ( $data, $existing ) {
			if ( array_key_exists( $key, $data ) ) { return $data[ $key ]; }
			return $existing && isset( $existing->$key ) ? $existing->$key : $default;
		};
		$duration = absint( $pick( 'default_duration', 60 ) );
		if ( $duration < 5 || $duration > 1440 ) { return Forms::error( 'wpcap_resource_duration', __( 'Die Standarddauer muss zwischen 5 und 1440 Minuten liegen.', 'pass-captain' ) ); }
		$ticket_type_id = absint( $pick( 'ticket_type_id', 0 ) );
		if ( $ticket_type_id && ! $wpdb->get_var( $wpdb->prepare( 'SELECT tt.ticket_type_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d AND o.event_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $ticket_type_id, $event_id ) ) ) {
			return Forms::error( 'wpcap_resource_ticket_type', __( 'Die Ticketart (Preis) muss zum Event der Ressource gehören.', 'pass-captain' ) );
		}
		$partner_id = absint( $pick( 'partner_id', $existing ? 0 : self::default_partner( $event_id ) ) );
		if ( $partner_id && ( ! self::partners_enabled() || ! Partners\Repository::get( $partner_id ) ) ) { return Forms::error( 'wpcap_resource_partner', __( 'Unbekannter Veranstalter.', 'pass-captain' ) ); }
		$rules = self::sanitize_rules( is_array( $data['rules'] ?? null ) ? $data['rules'] : array(), $existing ? self::rules( $existing ) : array() );
		$values = array(
			'name' => $name,
			'timezone' => $timezone,
			'capacity' => min( self::MAX_CAPACITY, max( 1, absint( $pick( 'capacity', 1 ) ) ) ),
			'status' => $status,
			'buffer_before' => min( self::MAX_BUFFER, absint( $pick( 'buffer_before', 0 ) ) ),
			'buffer_after' => min( self::MAX_BUFFER, absint( $pick( 'buffer_after', 0 ) ) ),
			'default_duration' => $duration,
			'partner_id' => $partner_id,
			'ticket_type_id' => $ticket_type_id,
			'rules' => wp_json_encode( $rules ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s' );
		$audit = array_diff_key( $values, array( 'rules' => 1 ) ) + array( 'rules' => $rules );
		if ( $existing ) {
			if ( false === $wpdb->update( $wpdb->prefix . 'wpcap_resources', $values, array( 'resource_id' => $resource_id ), $formats, array( '%d' ) ) ) {
				return Forms::error( 'wpcap_resource_save', __( 'Ressource konnte nicht gespeichert werden.', 'pass-captain' ), 500 );
			}
			Audit::record( 'service_resource_updated', 'resource', $resource_id, array( 'event_id' => $event_id ) + $audit );
			if ( (int) $existing->partner_id !== $partner_id ) { Audit::record( 'service_resource_partner_changed', 'resource', $resource_id, array( 'from' => (int) $existing->partner_id, 'to' => $partner_id ) ); }
			return $resource_id;
		}
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_resources', array( 'event_id' => $event_id ) + $values, array_merge( array( '%d' ), $formats ) );
		if ( false === $inserted || ! $wpdb->insert_id ) { return Forms::error( 'wpcap_resource_save', __( 'Ressource konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$resource_id = (int) $wpdb->insert_id;
		Audit::record( 'service_resource_created', 'resource', $resource_id, array( 'event_id' => $event_id ) + $audit );
		return $resource_id;
	}

	/**
	 * First slot of the resource that collides with [starts, ends] including the resource buffers
	 * (open slots and closed slots that still carry bookings; with $only_booked only slots with
	 * active bookings). 0 when free.
	 *
	 * Two slots collide when their occupied intervals [start - buffer_before, end + buffer_after]
	 * overlap, i.e. other.start < ends + gap and other.end > starts - gap with gap = before + after.
	 */
	public static function conflicting_slot( $resource, $starts_utc, $ends_utc, $exclude_slot_id = 0, $only_booked = false ) {
		global $wpdb;
		$gap = ( (int) $resource->buffer_before + (int) $resource->buffer_after ) * 60;
		$upper = gmdate( 'Y-m-d H:i:s', strtotime( $ends_utc . ' UTC' ) + $gap );
		$lower = gmdate( 'Y-m-d H:i:s', strtotime( $starts_utc . ' UTC' ) - $gap );
		$booked = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND " . self::active_sql( 'b' ) . ')';
		$state = $only_booked ? $booked : "(s.status='open' OR $booked)";
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT s.slot_id FROM {$wpdb->prefix}wpcap_service_slots s WHERE s.resource_id=%d AND s.slot_id<>%d AND s.starts_at<%s AND s.ends_at>%s AND $state ORDER BY s.starts_at LIMIT 1", (int) $resource->resource_id, absint( $exclude_slot_id ), $upper, $lower ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL fragments.
	}

	/** SQL condition for bookings that occupy capacity (table alias $a; held bookings only while unexpired). */
	public static function active_sql( $a = 'b' ) {
		$a = preg_replace( '/[^a-z_]/', '', (string) $a );
		$now = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) . ' UTC' ) );
		return "($a.status IN ('pending_confirmation','confirmed','checked_in','no_show') OR ($a.status='held' AND ($a.expires_at IS NULL OR $a.expires_at > '$now')))";
	}

	/** Sum of held (unexpired) and confirmed quantities of a slot. */
	public static function booked_quantity( $slot_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=%d AND " . self::active_sql( 'b' ), absint( $slot_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment.
	}

	/** Slots of a resource with booked quantities, chronological. */
	public static function slots( $resource_id, $limit = 300 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.*,(SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND " . self::active_sql( 'b' ) . ") AS booked,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_waitlist w WHERE w.slot_id=s.slot_id AND w.status IN ('waiting','offered')) AS waiting FROM {$wpdb->prefix}wpcap_service_slots s WHERE s.resource_id=%d ORDER BY s.starts_at,s.slot_id LIMIT %d", absint( $resource_id ), max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment.
	}

	/** All bookings of a slot, newest first. */
	public static function bookings( $slot_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT booking_id,slot_id,order_id,customer_email,quantity,expires_at,status,amount_minor,currency,confirm_by,cancel_reason,refund_id,rebook_count,checked_in_at,noshow_at,fee_minor,created_at FROM %i WHERE slot_id=%d ORDER BY created_at DESC,booking_id DESC LIMIT 500', $wpdb->prefix . 'wpcap_service_bookings', absint( $slot_id ) ) );
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
		$conflict = self::conflicting_slot( $resource, $starts_utc, $ends_utc );
		if ( $conflict ) { return self::conflict_error( $conflict ); }
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_service_slots', array( 'resource_id' => (int) $resource->resource_id, 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'capacity' => $capacity, 'status' => 'open' ), array( '%d', '%s', '%s', '%d', '%s' ) );
		if ( false === $inserted || ! $wpdb->insert_id ) { return Forms::error( 'wpcap_service_slot_save', __( 'Zeitfenster konnte nicht gespeichert werden.', 'pass-captain' ), 500 ); }
		$slot_id = (int) $wpdb->insert_id;
		if ( $audit ) { Audit::record( 'service_slot_created', 'service_slot', $slot_id, array( 'resource_id' => (int) $resource->resource_id, 'starts_at' => $starts_utc, 'ends_at' => $ends_utc, 'capacity' => $capacity ) ); }
		return $slot_id;
	}

	/** Error for a slot that overlaps another slot of the resource (incl. buffers). */
	private static function conflict_error( $slot_id ) {
		/* translators: %d: slot id */
		return Forms::error( 'wpcap_service_slot_overlap', sprintf( __( 'Das Zeitfenster überschneidet sich (inklusive Pufferzeiten) mit Zeitfenster #%d dieser Ressource.', 'pass-captain' ), (int) $slot_id ), 409 );
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
		$duration = absint( $args['duration'] ?? 0 ) ?: (int) ( $resource->default_duration ?? 0 );
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
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT slot_id FROM %i WHERE resource_id=%d AND starts_at=%s LIMIT 1', $wpdb->prefix . 'wpcap_service_slots', (int) $resource->resource_id, $starts ) );
			if ( $exists || self::conflicting_slot( $resource, $starts, $ends ) ) { $skipped++; continue; }
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
		$resource = self::resource( $slot->resource_id );
		$conflict = $resource ? self::conflicting_slot( $resource, $starts_utc, $ends_utc, (int) $slot->slot_id, 'open' !== $status ) : 0;
		if ( $conflict && ( 'open' === $status || $booked > 0 ) ) { return self::conflict_error( $conflict ); }
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
