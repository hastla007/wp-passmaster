<?php
/**
 * Resource and appointment-slot reservation service; management of resources and slots shared by
 * the REST routes and the admin page "Service-Zeitfenster" (A157). Times are stored in UTC.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceBookings {
	/**
	 * Reserves places in a slot as a hold (status `held`, expires after $minutes, max. 15).
	 *
	 * Concurrency (A133/A135): the slot's resource row, the rows of the providers assigned to it and
	 * the slot row are locked in a fixed order (resources, providers, slots – each ascending) with
	 * locking reads only, so the transaction's snapshot is taken after every lock is held. Parallel
	 * reservations of the same slot, of overlapping slots of one resource or of slots of different
	 * resources sharing a provider are therefore serialised and the later one sees the earlier
	 * booking. Checked under the locks: slot open and in the future, free places, no overlapping
	 * booked slot of the same resource or of a resource sharing a provider (buffered windows, see
	 * blocking_slot()).
	 */
	public static function reserve( $slot_id, $email, $quantity = 1, $order_id = 0, $minutes = 10, $idempotency_key = '' ) {
		global $wpdb;
		$slot_id = absint( $slot_id );
		$email = sanitize_email( $email );
		$quantity = min( 20, max( 1, absint( $quantity ) ) );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( ! $slot_id || ! is_email( $email ) || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ) ); }
		if ( ! RateLimit::allow( 'servicebook|' . strtolower( $email ), 20, HOUR_IN_SECONDS ) ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		if ( ! RateLimit::allow( 'servicebook_ip|' . Orders::client_ip(), (int) apply_filters( 'wpcap_service_booking_ip_limit', 40 ), HOUR_IN_SECONDS ) ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		$idempotency_key = hash( 'sha256', $idempotency_key );
		$bookings = $wpdb->prefix . 'wpcap_service_bookings';
		$reused = self::reused_booking( $idempotency_key, $slot_id, $email, $quantity );
		if ( null !== $reused ) { return $reused; }
		$resource_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT resource_id FROM {$wpdb->prefix}wpcap_service_slots WHERE slot_id=%d", $slot_id ) );
		if ( ! $resource_id ) { return self::unavailable(); }
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::lock( array( $resource_id ), array( $slot_id ) );
		if ( is_wp_error( $locked ) ) { return $locked; }
		// First consistent read: only after every lock is held.
		$reused = self::reused_booking( $idempotency_key, $slot_id, $email, $quantity );
		if ( null !== $reused ) { $wpdb->query( 'ROLLBACK' ); return $reused; }
		$now = Clock::mysql();
		$slot = self::bookable_slot( $slot_id );
		if ( ! $slot || 'open' !== $slot->status || $slot->starts_at <= $now ) { $wpdb->query( 'ROLLBACK' ); return self::unavailable(); }
		// A134: booking window and per-customer limit (serialised by the resource lock).
		$rule_error = self::rule_error( $slot, $email );
		if ( $rule_error ) { $wpdb->query( 'ROLLBACK' ); return $rule_error; }
		$wpdb->query( $wpdb->prepare( "UPDATE $bookings SET status='expired' WHERE slot_id=%d AND status='held' AND expires_at <= %s", $slot_id, $now ) );
		$used = self::booked( $slot_id );
		if ( $used + $quantity > (int) $slot->capacity ) { $wpdb->query( 'ROLLBACK' ); return self::full(); }
		$blocked = self::blocking_error( $slot );
		if ( $blocked ) { $wpdb->query( 'ROLLBACK' ); return $blocked; }
		$expires = gmdate( 'Y-m-d H:i:s', Clock::now() + min( 15, max( 1, absint( $minutes ) ) ) * MINUTE_IN_SECONDS );
		$row = array( 'slot_id' => $slot_id, 'order_id' => absint( $order_id ), 'customer_email' => $email, 'idempotency_key' => $idempotency_key, 'quantity' => $quantity, 'expires_at' => $expires, 'status' => 'held', 'created_at' => $now );
		// A135: personal management token (confirm/rebook/cancel via REST); only its hash is stored.
		$token = self::flow_ready() ? bin2hex( random_bytes( 32 ) ) : '';
		if ( '' !== $token ) { $row += array( 'token_hash' => hash( 'sha256', $token ), 'updated_at' => $now ); }
		$wpdb->insert( $bookings, $row );
		if ( ! $wpdb->insert_id || '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_failed', __( 'Zeitfenster konnte nicht reserviert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		$result = array( 'booking_id' => $id, 'expires_at' => $expires, 'status' => 'held' );
		if ( '' !== $token ) { $result['token'] = $token; }
		return $result;
	}

	/** Earlier booking with the same idempotency key: its result, a conflict error, or null. */
	private static function reused_booking( $key_hash, $slot_id, $email, $quantity ) {
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT booking_id,slot_id,customer_email,quantity,expires_at,status FROM {$wpdb->prefix}wpcap_service_bookings WHERE idempotency_key=%s", $key_hash ) );
		if ( ! $existing ) { return null; }
		if ( (int) $existing->slot_id !== (int) $slot_id || strtolower( $existing->customer_email ) !== strtolower( $email ) || (int) $existing->quantity !== (int) $quantity ) { return new \WP_Error( 'wpcap_booking_idempotency_conflict', __( 'Idempotency-Key wurde für eine andere Buchung verwendet.', 'pass-captain' ), array( 'status' => 409 ) ); }
		return array( 'booking_id' => (int) $existing->booking_id, 'expires_at' => $existing->expires_at, 'status' => $existing->status, 'reused' => true );
	}

	private static function unavailable() {
		return new \WP_Error( 'wpcap_slot_unavailable', __( 'Dieses Zeitfenster ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	private static function full() {
		return new \WP_Error( 'wpcap_slot_full', __( 'Für dieses Zeitfenster sind nicht genügend Plätze frei.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	/** Slot with its resource (buffers, rules) when resource and event are bookable, else null. Plain read. */
	public static function bookable_slot( $slot_id ) {
		global $wpdb;
		$columns = self::resource_columns();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list from a constant.
		return $wpdb->get_row( $wpdb->prepare( "SELECT s.*,$columns FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE s.slot_id=%d AND r.status='active' AND e.status='published'", absint( $slot_id ) ) );
	}

	/** Booking rules per resource (A134): column => [min, max]. 0 = no restriction / until the start. */
	const RULES = array(
		'lead_minutes' => array( 0, 43200 ),
		'advance_days' => array( 0, 730 ),
		'max_per_customer' => array( 0, 100 ),
		'rebook_hours' => array( 0, 8760 ),
		'cancel_hours' => array( 0, 8760 ),
	);

	/** Whether the A134 rule columns exist (deferred upgrades). */
	public static function rules_ready() {
		return self::has_column( 'wpcap_resources', 'cancel_hours' );
	}

	/** Resource columns read together with a slot (alias r): event, name, buffers and – once present – the rules. */
	private static function resource_columns() {
		$columns = 'r.event_id,r.name AS resource_name,r.buffer_before,r.buffer_after';
		if ( self::rules_ready() ) {
			foreach ( array_keys( self::RULES ) as $rule ) { $columns .= ',r.' . $rule; }
		}
		return $columns;
	}

	/** Rules of a resource or slot row (missing columns = 0). */
	public static function rules( $row ) {
		$rules = array();
		foreach ( array_keys( self::RULES ) as $rule ) { $rules[ $rule ] = absint( $row->$rule ?? 0 ); }
		return $rules;
	}

	/** Whether "$hours before the start of $starts_at" has passed (0 hours = the start itself). */
	public static function deadline_passed( $starts_at, $hours ) {
		return Clock::now() >= strtotime( $starts_at . ' UTC' ) - absint( $hours ) * HOUR_IN_SECONDS;
	}

	/** "90 Minuten" / "3 Stunden" / "2 Tage" for a number of minutes. */
	public static function duration_label( $minutes ) {
		$minutes = absint( $minutes );
		if ( $minutes && 0 === $minutes % 1440 ) {
			/* translators: %d: number of days */
			return sprintf( _n( '%d Tag', '%d Tage', $minutes / 1440, 'pass-captain' ), $minutes / 1440 );
		}
		if ( $minutes && 0 === $minutes % 60 ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d Stunde', '%d Stunden', $minutes / 60, 'pass-captain' ), $minutes / 60 );
		}
		/* translators: %d: number of minutes */
		return sprintf( _n( '%d Minute', '%d Minuten', $minutes, 'pass-captain' ), $minutes );
	}

	/**
	 * Booking window and per-customer limit of the slot's resource (A134), checked server-side on
	 * reserve, confirm and rebook: the slot must start at least `lead_minutes` from now and at most
	 * `advance_days` ahead; the customer may hold at most `max_per_customer` active bookings (held,
	 * awaiting payment or confirmed) on not yet ended slots of the resource ($exclude_booking: the
	 * booking being confirmed or rebooked).
	 *
	 * @param object $slot            Row from bookable_slot().
	 * @param string $email           Customer e-mail ('' skips the limit).
	 * @param int    $exclude_booking Booking that does not count.
	 * @return \WP_Error|null
	 */
	public static function rule_error( $slot, $email, $exclude_booking = 0 ) {
		global $wpdb;
		$rules = self::rules( $slot );
		$start = strtotime( $slot->starts_at . ' UTC' );
		if ( $rules['lead_minutes'] && $start < Clock::now() + $rules['lead_minutes'] * MINUTE_IN_SECONDS ) {
			return new \WP_Error(
				'wpcap_booking_cutoff',
				/* translators: %s: lead time, e.g. "24 Stunden" */
				sprintf( __( 'Buchungsschluss: Dieses Zeitfenster kann nur bis %s vor Beginn gebucht werden.', 'pass-captain' ), self::duration_label( $rules['lead_minutes'] ) ),
				array( 'status' => 409 )
			);
		}
		if ( $rules['advance_days'] && $start > Clock::now() + $rules['advance_days'] * DAY_IN_SECONDS ) {
			return new \WP_Error(
				'wpcap_booking_too_early',
				/* translators: %d: number of days */
				sprintf( _n( 'Dieses Zeitfenster kann frühestens %d Tag im Voraus gebucht werden.', 'Dieses Zeitfenster kann frühestens %d Tage im Voraus gebucht werden.', $rules['advance_days'], 'pass-captain' ), $rules['advance_days'] ),
				array( 'status' => 409 )
			);
		}
		if ( $rules['max_per_customer'] && '' !== (string) $email ) {
			$p = $wpdb->prefix;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ACTIVE_SQL is a constant with placeholders.
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_service_bookings b INNER JOIN {$p}wpcap_service_slots s ON s.slot_id=b.slot_id WHERE s.resource_id=%d AND s.ends_at>%s AND b.customer_email=%s AND b.booking_id<>%d AND " . self::ACTIVE_SQL, (int) $slot->resource_id, Clock::mysql(), $email, absint( $exclude_booking ), Clock::mysql() ) );
			if ( $count >= $rules['max_per_customer'] ) {
				return new \WP_Error(
					'wpcap_booking_customer_limit',
					/* translators: %d: maximum number of bookings */
					sprintf( _n( 'Pro Person ist höchstens %d offene Buchung für dieses Angebot möglich.', 'Pro Person sind höchstens %d offene Buchungen für dieses Angebot möglich.', $rules['max_per_customer'], 'pass-captain' ), $rules['max_per_customer'] ),
					array( 'status' => 409 )
				);
			}
		}
		return null;
	}

	/** 409 error when the rebooking deadline of $slot (row with rules) has passed, else null. */
	public static function rebook_deadline_error( $slot ) {
		$hours = self::rules( $slot )['rebook_hours'];
		if ( ! self::deadline_passed( $slot->starts_at, $hours ) ) { return null; }
		$message = __( 'Der Termin hat bereits begonnen und kann nicht mehr umgebucht werden.', 'pass-captain' );
		if ( $hours ) {
			/* translators: %s: deadline, e.g. "24 Stunden" */
			$message = sprintf( __( 'Umbuchungen sind nur bis %s vor Beginn möglich.', 'pass-captain' ), self::duration_label( $hours * 60 ) );
		}
		return new \WP_Error( 'wpcap_booking_rebook_deadline', $message, array( 'status' => 409 ) );
	}

	/** 409 error when the cancellation deadline of $slot (row with rules) has passed, else null. */
	public static function cancel_deadline_error( $slot ) {
		$hours = self::rules( $slot )['cancel_hours'];
		if ( ! self::deadline_passed( $slot->starts_at, $hours ) ) { return null; }
		$message = __( 'Der Termin hat bereits begonnen und kann nicht mehr storniert werden.', 'pass-captain' );
		if ( $hours ) {
			/* translators: %s: deadline, e.g. "48 Stunden" */
			$message = sprintf( __( 'Stornierungen sind nur bis %s vor Beginn möglich.', 'pass-captain' ), self::duration_label( $hours * 60 ) );
		}
		return new \WP_Error( 'wpcap_booking_cancel_deadline', $message, array( 'status' => 409 ) );
	}

	/**
	 * Lock phase of a booking transaction (the caller started it): resource rows, the providers
	 * assigned to them, then the slot rows – each ascending, locking reads only. Every path that
	 * changes bookings or provider assignments uses this order, so they cannot deadlock each other.
	 * On a lock wait timeout or deadlock the transaction is rolled back and a retryable error returned.
	 *
	 * @param int[] $resource_ids Resources of the slots involved.
	 * @param int[] $slot_ids     Slots involved.
	 * @return true|\WP_Error
	 */
	public static function lock( array $resource_ids, array $slot_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$resource_ids = array_values( array_unique( array_filter( array_map( 'absint', $resource_ids ) ) ) );
		$slot_ids = array_values( array_unique( array_filter( array_map( 'absint', $slot_ids ) ) ) );
		sort( $resource_ids, SORT_NUMERIC );
		sort( $slot_ids, SORT_NUMERIC );
		$ok = true;
		foreach ( $resource_ids as $resource_id ) {
			if ( ! $ok ) { break; }
			$wpdb->get_var( $wpdb->prepare( "SELECT resource_id FROM {$p}wpcap_resources WHERE resource_id=%d FOR UPDATE", $resource_id ) );
			$ok = '' === (string) $wpdb->last_error;
		}
		if ( $ok && $resource_ids && self::providers_ready() ) {
			$provider_ids = array();
			foreach ( $resource_ids as $resource_id ) {
				if ( ! $ok ) { break; }
				$provider_ids = array_merge( $provider_ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT provider_id FROM {$p}wpcap_resource_providers WHERE resource_id=%d FOR UPDATE", $resource_id ) ) ) );
				$ok = '' === (string) $wpdb->last_error;
			}
			$provider_ids = array_values( array_unique( $provider_ids ) );
			sort( $provider_ids, SORT_NUMERIC );
			foreach ( $provider_ids as $provider_id ) {
				if ( ! $ok ) { break; }
				$wpdb->get_var( $wpdb->prepare( "SELECT provider_id FROM {$p}wpcap_service_providers WHERE provider_id=%d FOR UPDATE", $provider_id ) );
				$ok = '' === (string) $wpdb->last_error;
			}
		}
		foreach ( $slot_ids as $slot_id ) {
			if ( ! $ok ) { break; }
			$wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$p}wpcap_service_slots WHERE slot_id=%d FOR UPDATE", $slot_id ) );
			$ok = '' === (string) $wpdb->last_error;
		}
		if ( $ok ) { return true; }
		$wpdb->query( 'ROLLBACK' );
		return Errors::busy();
	}

	/** Whether the A133 schema (providers, resource assignment, duration) exists (deferred upgrades). */
	public static function providers_ready() {
		return self::has_column( 'wpcap_resource_providers', 'provider_id' ) && self::has_column( 'wpcap_service_providers', 'partner_id' ) && self::has_column( 'wpcap_resources', 'duration_minutes' );
	}

	/** Column check with a per-request cache (false while the table or column does not exist yet). */
	public static function has_column( $table, $column ) {
		global $wpdb;
		$key = $wpdb->prefix . $table . '.' . $column;
		if ( ! isset( self::$columns[ $key ] ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', $wpdb->prefix . $table, $column ) );
			self::$columns[ $key ] = (int) $exists > 0;
		}
		return self::$columns[ $key ];
	}

	/** Forgets the column cache (after an upgrade in the same request, tests). */
	public static function flush_schema_cache() {
		self::$columns = array();
	}

	/** Per-request cache of has_column(). */
	private static $columns = array();

	/**
	 * Buffered window of a slot: [start - buffer_before, end + buffer_after] as UTC 'Y-m-d H:i:s'.
	 *
	 * @param object $slot Row with starts_at, ends_at, buffer_before, buffer_after.
	 */
	public static function window( $slot ) {
		return array(
			gmdate( 'Y-m-d H:i:s', strtotime( $slot->starts_at . ' UTC' ) - absint( $slot->buffer_before ?? 0 ) * MINUTE_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', strtotime( $slot->ends_at . ' UTC' ) + absint( $slot->buffer_after ?? 0 ) * MINUTE_IN_SECONDS ),
		);
	}

	/**
	 * First slot that blocks a booking of $slot: a slot of the same resource, or of another resource
	 * sharing an active provider, whose buffered window overlaps the buffered window of $slot and
	 * which holds active bookings (other than $exclude_booking). Each slot's window uses the buffers
	 * of its own resource. Same-resource conflicts are reported first.
	 *
	 * @param object $slot            Row with slot_id, resource_id, starts_at, ends_at, buffer_before, buffer_after.
	 * @param int    $exclude_booking Booking that does not count (the one being rebooked).
	 * @return object|null Row with slot_id, resource_id, kind ('resource'|'provider').
	 */
	public static function blocking_slot( $slot, $exclude_booking = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		list( $from, $until ) = self::window( $slot );
		$resource_id = (int) $slot->resource_id;
		$provider_sql = self::providers_ready() ? $wpdb->prepare( " OR s2.resource_id IN (SELECT rp2.resource_id FROM {$p}wpcap_resource_providers rp2 INNER JOIN {$p}wpcap_resource_providers rp1 ON rp1.provider_id=rp2.provider_id INNER JOIN {$p}wpcap_service_providers sp ON sp.provider_id=rp1.provider_id AND sp.status='active' WHERE rp1.resource_id=%d)", $resource_id ) : '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $provider_sql is prepared above, ACTIVE_SQL is a constant with placeholders.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT s2.slot_id,s2.resource_id FROM {$p}wpcap_service_slots s2 INNER JOIN {$p}wpcap_resources r2 ON r2.resource_id=s2.resource_id WHERE s2.slot_id<>%d AND (s2.resource_id=%d$provider_sql) AND DATE_SUB(s2.starts_at, INTERVAL r2.buffer_before MINUTE) < %s AND DATE_ADD(s2.ends_at, INTERVAL r2.buffer_after MINUTE) > %s AND EXISTS (SELECT 1 FROM {$p}wpcap_service_bookings b WHERE b.slot_id=s2.slot_id AND b.booking_id<>%d AND " . self::ACTIVE_SQL . ') ORDER BY (s2.resource_id=%d) DESC,s2.starts_at LIMIT 1',
			(int) $slot->slot_id,
			$resource_id,
			$until,
			$from,
			absint( $exclude_booking ),
			Clock::mysql(),
			$resource_id
		) );
		// phpcs:enable
		if ( ! $row ) { return null; }
		$row->kind = (int) $row->resource_id === $resource_id ? 'resource' : 'provider';
		return $row;
	}

	/** blocking_slot() as a user-facing 409 error, or null. */
	public static function blocking_error( $slot, $exclude_booking = 0 ) {
		$blocking = self::blocking_slot( $slot, $exclude_booking );
		if ( ! $blocking ) { return null; }
		return 'resource' === $blocking->kind
			? new \WP_Error( 'wpcap_slot_resource_busy', __( 'Die Ressource ist zu dieser Zeit (inklusive Vor- und Nachbereitung) bereits gebucht.', 'pass-captain' ), array( 'status' => 409, 'blocking_slot_id' => (int) $blocking->slot_id ) )
			: new \WP_Error( 'wpcap_slot_provider_busy', __( 'Die Leistungserbringerin bzw. der Leistungserbringer ist zu dieser Zeit bereits für eine andere Buchung eingeteilt.', 'pass-captain' ), array( 'status' => 409, 'blocking_slot_id' => (int) $blocking->slot_id ) );
	}

	/**
	 * Slot of the same resource whose buffered window overlaps [$starts_at, $ends_at] (buffered with
	 * the resource's buffers) and which is open or has active bookings; slot id or 0. Used when slots
	 * are created, moved, reopened or generated.
	 */
	public static function overlapping_slot( $resource, $starts_at, $ends_at, $exclude_slot = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		list( $from, $until ) = self::window( (object) array( 'starts_at' => $starts_at, 'ends_at' => $ends_at, 'buffer_before' => $resource->buffer_before, 'buffer_after' => $resource->buffer_after ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ACTIVE_SQL is a constant with placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT s2.slot_id FROM {$p}wpcap_service_slots s2 WHERE s2.resource_id=%d AND s2.slot_id<>%d AND DATE_SUB(s2.starts_at, INTERVAL %d MINUTE) < %s AND DATE_ADD(s2.ends_at, INTERVAL %d MINUTE) > %s AND (s2.status='open' OR EXISTS (SELECT 1 FROM {$p}wpcap_service_bookings b WHERE b.slot_id=s2.slot_id AND " . self::ACTIVE_SQL . ')) ORDER BY s2.starts_at LIMIT 1', (int) $resource->resource_id, absint( $exclude_slot ), absint( $resource->buffer_before ), $until, absint( $resource->buffer_after ), $from, Clock::mysql() ) );
	}

	private static function overlap_error( $slot_id ) {
		return new \WP_Error( 'wpcap_service_slot_overlap', __( 'Das Zeitfenster überschneidet sich (inklusive Puffer vorher/danach) mit einem anderen Zeitfenster dieser Ressource.', 'pass-captain' ), array( 'status' => 409, 'slot_id' => (int) $slot_id ) );
	}

	/** Booking states that occupy capacity (held / awaiting payment only until expires_at); one %s placeholder = now. */
	const ACTIVE_SQL = "(b.status='confirmed' OR (b.status IN ('held','pending_payment') AND (b.expires_at IS NULL OR b.expires_at > %s)))";

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
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=%d AND " . self::ACTIVE_SQL, absint( $slot_id ), Clock::mysql() ) );
	}

	/**
	 * Resource input → validated columns: name, capacity 1–100, buffers 0–1440 minutes, default slot
	 * duration 5–1440 minutes (A133). Keys missing in $data keep the value of $current (update) or
	 * their default (create). A135: ticket_type_id (0 = free, else a ticket type of the event whose
	 * price is charged through the checkout).
	 */
	private static function resource_data( array $data, $current = null, $event_id = 0 ) {
		global $wpdb;
		$value = static function ( $key, $fallback ) use ( $data, $current ) {
			if ( array_key_exists( $key, $data ) && '' !== (string) $data[ $key ] ) { return $data[ $key ]; }
			return $current && isset( $current->$key ) ? $current->$key : $fallback;
		};
		$name = mb_substr( sanitize_text_field( (string) $value( 'name', '' ) ), 0, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_resource_invalid', __( 'Ressourcenname fehlt.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$row = array( 'name' => $name, 'capacity' => min( 100, max( 1, absint( $value( 'capacity', 1 ) ) ) ), 'buffer_before' => min( 1440, absint( $value( 'buffer_before', 0 ) ) ), 'buffer_after' => min( 1440, absint( $value( 'buffer_after', 0 ) ) ) );
		if ( self::providers_ready() ) { $row['duration_minutes'] = min( 1440, max( 5, absint( $value( 'duration_minutes', 60 ) ) ) ); }
		if ( self::rules_ready() ) {
			foreach ( self::RULES as $rule => $range ) { $row[ $rule ] = min( $range[1], max( $range[0], absint( $value( $rule, 0 ) ) ) ); }
		}
		if ( self::flow_ready() ) {
			$row['ticket_type_id'] = absint( array_key_exists( 'ticket_type_id', $data ) ? $data['ticket_type_id'] : ( $current->ticket_type_id ?? 0 ) );
			if ( $row['ticket_type_id'] && ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d AND o.event_id=%d", $row['ticket_type_id'], absint( $event_id ) ) ) ) {
				return new \WP_Error( 'wpcap_resource_ticket_type', __( 'Die Ticketart für den Preis gehört nicht zu diesem Event.', 'pass-captain' ), array( 'status' => 400 ) );
			}
		}
		return $row;
	}

	/** Creates a resource of an event (wpcap_manage_events for the event). Returns the id. */
	public static function create_resource( $event_id, array $data ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! Access::can_access_event( $event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d", $event_id ) ) ) { return new \WP_Error( 'wpcap_event_not_found', __( 'Event nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$row = self::resource_data( $data, null, $event_id );
		if ( is_wp_error( $row ) ) { return $row; }
		$wpdb->insert( $wpdb->prefix . 'wpcap_resources', array( 'event_id' => $event_id ) + $row + array( 'status' => 'active' ) );
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
		$row = self::resource_data( $data, $resource, (int) $resource->event_id );
		if ( is_wp_error( $row ) ) { return $row; }
		$row['status'] = in_array( $data['status'] ?? '', array( 'active', 'inactive' ), true ) ? $data['status'] : $resource->status;
		$wpdb->update( $wpdb->prefix . 'wpcap_resources', $row, array( 'resource_id' => (int) $resource->resource_id ) );
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
		if ( self::providers_ready() ) { $wpdb->delete( $wpdb->prefix . 'wpcap_resource_providers', array( 'resource_id' => (int) $resource->resource_id ), array( '%d' ) ); }
		ServiceProviders::flush();
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
		// A133: no second slot of the resource inside the buffered window.
		$overlap = self::overlapping_slot( $resource, $times['starts_at'], $times['ends_at'] );
		if ( $overlap ) { return self::overlap_error( $overlap ); }
		$capacity = isset( $data['capacity'] ) && '' !== (string) $data['capacity'] ? absint( $data['capacity'] ) : (int) $resource->capacity;
		$row = array( 'resource_id' => (int) $resource->resource_id ) + $times + array( 'capacity' => min( 100, max( 1, $capacity ) ), 'status' => 'open' );
		$wpdb->insert( $wpdb->prefix . 'wpcap_service_slots', $row, array( '%d', '%s', '%s', '%d', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_service_slot_save', __( 'Zeitfenster konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		Audit::record( 'service_slot_created', 'service_slot', $id, array( 'event_id' => (int) $resource->event_id ) + $row );
		return $id;
	}

	/**
	 * Generates a series of open slots for an active resource (A133). On each selected weekday between
	 * date_from and date_to (local dates in $data['timezone']), slots of `duration` minutes (default:
	 * the resource's duration) are placed from time_from until time_to; consecutive slots keep the
	 * resource buffers apart (next start = end + buffer_after + buffer_before). Candidates that would
	 * overlap an existing slot of the resource (buffered) are skipped. At most 500 slots per call.
	 *
	 * @param int   $resource_id Resource.
	 * @param array $data        date_from, date_to (Y-m-d), time_from, time_to (H:i), weekdays (ISO 1–7),
	 *                           duration (minutes), capacity, timezone (IANA, default UTC).
	 * @return array|\WP_Error created (int), skipped (int), slot_ids (int[]).
	 */
	public static function generate_slots( $resource_id, array $data ) {
		global $wpdb;
		$resource = self::resource( $resource_id );
		if ( ! $resource || 'active' !== $resource->status || ! Access::can_access_event( (int) $resource->event_id, 'wpcap_manage_events' ) ) { return new \WP_Error( 'wpcap_resource_forbidden', __( 'Keine Berechtigung für diese Ressource.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$invalid = new \WP_Error( 'wpcap_service_series_invalid', __( 'Die Angaben für die Zeitfenster-Serie sind ungültig (Zeitraum höchstens 366 Tage, Uhrzeiten von–bis, mindestens ein Wochentag).', 'pass-captain' ), array( 'status' => 400 ) );
		$zone_name = sanitize_text_field( (string) ( $data['timezone'] ?? 'UTC' ) );
		try { $zone = new \DateTimeZone( '' !== $zone_name ? $zone_name : 'UTC' ); } catch ( \Exception $e ) { return $invalid; }
		$date_ok = static function ( $value ) { return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ); };
		$time_ok = static function ( $value ) { return is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ); };
		$from = (string) ( $data['date_from'] ?? '' );
		$to = (string) ( $data['date_to'] ?? '' );
		$time_from = (string) ( $data['time_from'] ?? '' );
		$time_to = (string) ( $data['time_to'] ?? '' );
		$weekdays = array_values( array_intersect( array_map( 'absint', (array) ( $data['weekdays'] ?? array() ) ), range( 1, 7 ) ) );
		$duration = isset( $data['duration'] ) && '' !== (string) $data['duration'] ? absint( $data['duration'] ) : (int) ( $resource->duration_minutes ?? 60 );
		if ( ! $date_ok( $from ) || ! $date_ok( $to ) || $to < $from || ! $time_ok( $time_from ) || ! $time_ok( $time_to ) || $time_to <= $time_from || ! $weekdays || $duration < 5 || $duration > 1440 ) { return $invalid; }
		$day = new \DateTimeImmutable( $from . ' 00:00', $zone );
		$last = new \DateTimeImmutable( $to . ' 00:00', $zone );
		if ( (int) $day->diff( $last )->days > 366 ) { return $invalid; }
		$capacity = min( 100, max( 1, isset( $data['capacity'] ) && '' !== (string) $data['capacity'] ? absint( $data['capacity'] ) : (int) $resource->capacity ) );
		$step = ( $duration + (int) $resource->buffer_before + (int) $resource->buffer_after ) * MINUTE_IN_SECONDS;
		$now = Clock::now();
		$created = array();
		$skipped = 0;
		while ( $day <= $last && count( $created ) < 500 ) {
			if ( in_array( (int) $day->format( 'N' ), $weekdays, true ) ) {
				// DST: an opening time that occurs twice (fall back) starts at its first instance, a closing
				// time at its second, so the whole wall-clock window is covered; times in a gap move forward.
				$start = self::local_timestamp( $day->format( 'Y-m-d' ), $time_from, $zone, true );
				$day_end = self::local_timestamp( $day->format( 'Y-m-d' ), $time_to, $zone, false );
				for ( ; $start + $duration * MINUTE_IN_SECONDS <= $day_end && count( $created ) < 500; $start += $step ) {
					if ( $start <= $now ) { ++$skipped; continue; }
					$row = array( 'resource_id' => (int) $resource->resource_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + $duration * MINUTE_IN_SECONDS ), 'capacity' => $capacity, 'status' => 'open' );
					if ( self::overlapping_slot( $resource, $row['starts_at'], $row['ends_at'] ) ) { ++$skipped; continue; }
					$wpdb->insert( $wpdb->prefix . 'wpcap_service_slots', $row, array( '%d', '%s', '%s', '%d', '%s' ) );
					if ( $wpdb->insert_id ) { $created[] = (int) $wpdb->insert_id; }
				}
			}
			$day = $day->modify( '+1 day' );
		}
		Audit::record( 'service_slots_generated', 'resource', (int) $resource->resource_id, array( 'event_id' => (int) $resource->event_id, 'created' => count( $created ), 'skipped' => $skipped, 'date_from' => $from, 'date_to' => $to, 'duration' => $duration ) );
		return array( 'created' => count( $created ), 'skipped' => $skipped, 'slot_ids' => $created );
	}

	/**
	 * Unix timestamp of a local wall-clock time ("Y-m-d", "H:i") in $zone. A time that exists twice
	 * (DST fall back) resolves to the first instance when $first, else to the second; a time inside
	 * a DST gap is shifted forward by the gap (PHP behaviour, e.g. 02:30 → 03:30).
	 */
	public static function local_timestamp( $date, $time, \DateTimeZone $zone, $first = true ) {
		$wall = $date . ' ' . $time;
		$local = new \DateTimeImmutable( $wall, $zone );
		$ts = $local->getTimestamp();
		$instants = array();
		foreach ( array( -7200, -3600, -1800, 0, 1800, 3600, 7200 ) as $delta ) {
			if ( $local->setTimestamp( $ts + $delta )->format( 'Y-m-d H:i' ) === $wall ) { $instants[] = $ts + $delta; }
		}
		if ( ! $instants ) { return $ts; }
		return $first ? min( $instants ) : max( $instants );
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
		// A133: a moved or reopened slot must not overlap another slot of the resource (buffered).
		if ( 'open' === $status && ( $moved || 'open' !== $slot->status ) ) {
			$overlap = self::overlapping_slot( self::resource( (int) $slot->resource_id ), $times['starts_at'], $times['ends_at'], (int) $slot->slot_id );
			if ( $overlap ) { $wpdb->query( 'ROLLBACK' ); return self::overlap_error( $overlap ); }
		}
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
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.*,r.name AS resource_name,r.event_id,(SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND " . self::ACTIVE_SQL . ") AS booked,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_bookings b2 WHERE b2.slot_id=s.slot_id) AS booking_rows FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE r.event_id=%d ORDER BY s.starts_at,s.slot_id LIMIT %d", Clock::mysql(), absint( $event_id ), max( 1, min( 2000, absint( $limit ) ) ) ) );
	}

	/**
	 * Publicly bookable slots of a published event (max. 100, by start): open, in the future, with
	 * free places and not blocked by a booked overlapping slot of the same resource or of a resource
	 * sharing a provider (A133). Keys: slot_id, resource_id, starts_at, ends_at, capacity,
	 * resource_name, available.
	 */
	public static function public_slots( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$columns = self::resource_columns();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list from a constant.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*,$columns FROM {$p}wpcap_service_slots s INNER JOIN {$p}wpcap_resources r ON r.resource_id=s.resource_id INNER JOIN {$p}wpcap_events e ON e.event_id=r.event_id WHERE r.event_id=%d AND r.status='active' AND e.status='published' AND s.status='open' AND s.starts_at>%s ORDER BY s.starts_at,s.slot_id LIMIT 300", absint( $event_id ), Clock::mysql() ) );
		$out = array();
		foreach ( $rows as $slot ) {
			$available = (int) $slot->capacity - self::booked( (int) $slot->slot_id );
			// A134: only slots inside the booking window (lead time, maximum advance booking).
			if ( $available <= 0 || self::rule_error( $slot, '' ) || self::blocking_slot( $slot ) ) { continue; }
			$out[] = array( 'slot_id' => (int) $slot->slot_id, 'resource_id' => (int) $slot->resource_id, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'capacity' => (int) $slot->capacity, 'resource_name' => (string) $slot->resource_name, 'available' => $available );
			if ( count( $out ) >= 100 ) { break; }
		}
		return $out;
	}

	/** Bookings of an event (optionally of one slot), newest first. */
	public static function bookings( $event_id, $slot_id = 0, $limit = 200 ) {
		global $wpdb;
		$slot_sql = $slot_id ? $wpdb->prepare( ' AND s.slot_id=%d', absint( $slot_id ) ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $slot_sql is prepared above.
		return $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_id,b.slot_id,b.order_id,b.customer_email,b.quantity,b.expires_at,b.status,b.created_at,s.starts_at,s.ends_at,r.name AS resource_name FROM {$wpdb->prefix}wpcap_service_bookings b INNER JOIN {$wpdb->prefix}wpcap_service_slots s ON s.slot_id=b.slot_id INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE r.event_id=%d$slot_sql ORDER BY b.booking_id DESC LIMIT %d", absint( $event_id ), max( 1, min( 1000, absint( $limit ) ) ) ) );
	}

	/**
	 * Whether the current user may confirm a booking or mark it as no-show: event team with
	 * wpcap_manage_events, or a service provider assigned to the booking's service (A143).
	 */
	public static function can_handle_booking( $booking ) {
		if ( ! $booking ) { return false; }
		return Access::can_access_event( (int) $booking->event_id, 'wpcap_manage_events' ) || Access::can_access_resource( (int) $booking->resource_id, ServiceProviders::USER_CAP );
	}

	/** Booking statuses set by staff or service providers: confirmed and no_show. */
	const STAFF_STATUSES = array( 'confirmed', 'no_show' );

	/**
	 * Confirms a booking or marks it as no-show (A143) – for the event team (wpcap_manage_events)
	 * and for service providers of the booking's resource (can_handle_booking()).
	 *  - confirmed: only from `held`, through confirm() (same locks and rule checks). Bookings of a
	 *    resource with a price (ticket type) are confirmed by their payment, not by hand.
	 *  - no_show: only from `confirmed`, once the slot has started; frees no money (no refund).
	 * Setting the current status again is a no-op. Audit service_booking_confirmed /
	 * service_booking_no_show, action wpcap_service_booking_status_changed.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_booking_status( $booking_id, $status ) {
		global $wpdb;
		$status = sanitize_key( (string) $status );
		if ( ! in_array( $status, self::STAFF_STATUSES, true ) ) { return new \WP_Error( 'wpcap_booking_status_invalid', __( 'Unbekannter Buchungsstatus.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$booking = self::booking( $booking_id );
		if ( ! $booking ) { return new \WP_Error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( ! self::can_handle_booking( $booking ) ) { return new \WP_Error( 'wpcap_booking_forbidden', __( 'Keine Berechtigung für diese Buchung.', 'pass-captain' ), array( 'status' => 403 ) ); }
		if ( 'confirmed' === $status ) {
			if ( 'confirmed' === $booking->status ) { return true; }
			if ( 'held' !== self::booking_status( $booking ) ) { return self::state_error( __( 'Bestätigen ist nur für eine aktive Reservierung möglich.', 'pass-captain' ) ); }
			if ( self::flow_ready() && (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d", (int) $booking->resource_id ) ) ) { return self::state_error( __( 'Kostenpflichtige Buchungen werden mit der Zahlung bestätigt.', 'pass-captain' ) ); }
			$result = self::confirm( (int) $booking->booking_id, array( 'actor' => 'provider' ) );
			if ( is_wp_error( $result ) ) { return $result; }
			do_action( 'wpcap_service_booking_status_changed', (int) $booking->booking_id, 'confirmed', 'held' );
			return true;
		}
		$locked = self::begin( (int) $booking->booking_id );
		if ( is_wp_error( $locked ) ) { return $locked; }
		if ( 'no_show' === $locked->status ) { $wpdb->query( 'ROLLBACK' ); return true; }
		if ( 'confirmed' !== $locked->status ) { $wpdb->query( 'ROLLBACK' ); return self::state_error( __( 'Nicht erschienen kann nur für eine bestätigte Buchung markiert werden.', 'pass-captain' ) ); }
		if ( (string) $locked->starts_at > current_time( 'mysql', true ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_not_started', __( 'Nicht erschienen kann erst ab Beginn des Zeitfensters markiert werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'status' => 'no_show' ) + self::touched(), array( 'booking_id' => (int) $locked->booking_id ) );
		$committed = self::commit();
		if ( is_wp_error( $committed ) ) { return $committed; }
		Audit::record( 'service_booking_no_show', 'service_booking', (int) $locked->booking_id, array( 'event_id' => (int) $locked->event_id, 'resource_id' => (int) $locked->resource_id, 'slot_id' => (int) $locked->slot_id ) );
		/**
		 * A service booking was confirmed or marked as no-show by the event team or a service
		 * provider of its resource (A143).
		 *
		 * @param int    $booking_id Booking.
		 * @param string $status     confirmed or no_show.
		 * @param string $previous   Previous status (held or confirmed).
		 */
		do_action( 'wpcap_service_booking_status_changed', (int) $locked->booking_id, 'no_show', 'confirmed' );
		return true;
	}

	/**
	 * Resources a service-provider user reaches through their provider records (A143), with event name.
	 *
	 * @return object[]
	 */
	public static function provider_resources( $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? absint( $user_id ) : (int) get_current_user_id();
		$ids = ServiceProviders::resource_ids_for_user( $user_id );
		if ( ! $ids ) { return array(); }
		return (array) $wpdb->get_results( "SELECT r.*,e.name AS event_name FROM {$wpdb->prefix}wpcap_resources r INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=r.event_id WHERE r.resource_id IN (" . implode( ',', array_map( 'absint', $ids ) ) . ') ORDER BY e.name,r.name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer ids only.
	}

	/** Slots of the given services ending at or after $since (UTC), with booked places, by start. */
	public static function slots_of_resources( array $resource_ids, $since = '', $limit = 500 ) {
		global $wpdb;
		$resource_ids = array_values( array_filter( array_map( 'absint', $resource_ids ) ) );
		if ( ! $resource_ids ) { return array(); }
		$since = '' !== (string) $since ? (string) $since : '1970-01-01 00:00:00';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ACTIVE_SQL is a constant with placeholders, ids are integers.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.*,r.name AS resource_name,r.event_id,(SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND " . self::ACTIVE_SQL . ") AS booked FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE s.resource_id IN (" . implode( ',', $resource_ids ) . ') AND s.ends_at >= %s ORDER BY s.starts_at,s.slot_id LIMIT %d', current_time( 'mysql', true ), $since, max( 1, min( 2000, absint( $limit ) ) ) ) );
	}

	/** Bookings of the given services (optionally of one slot) whose slot ends at or after $since, by slot start. */
	public static function bookings_of_resources( array $resource_ids, $slot_id = 0, $since = '', $limit = 500 ) {
		global $wpdb;
		$resource_ids = array_values( array_filter( array_map( 'absint', $resource_ids ) ) );
		if ( ! $resource_ids ) { return array(); }
		$since = '' !== (string) $since ? (string) $since : '1970-01-01 00:00:00';
		$slot_sql = $slot_id ? $wpdb->prepare( ' AND s.slot_id=%d', absint( $slot_id ) ) : '';
		$price = self::flow_ready() ? ',r.ticket_type_id' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are integers, $slot_sql is prepared above, $price is a literal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_id,b.slot_id,b.order_id,b.customer_email,b.quantity,b.expires_at,b.status,b.created_at,s.starts_at,s.ends_at,s.resource_id,r.event_id,r.name AS resource_name$price FROM {$wpdb->prefix}wpcap_service_bookings b INNER JOIN {$wpdb->prefix}wpcap_service_slots s ON s.slot_id=b.slot_id INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE s.resource_id IN (" . implode( ',', $resource_ids ) . ")$slot_sql AND s.ends_at >= %s ORDER BY s.starts_at,b.booking_id LIMIT %d", $since, max( 1, min( 2000, absint( $limit ) ) ) ) );
	}

	/** Display status of a booking (held bookings past expires_at count as expired). */
	public static function booking_status( $booking ) {
		if ( in_array( $booking->status, array( 'held', 'pending_payment' ), true ) && $booking->expires_at && $booking->expires_at <= Clock::mysql() ) { return 'expired'; }
		return (string) $booking->status;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Booking lifecycle: confirm, rebook, cancel (A134 rules; A135 payment, refunds, notifications).
	 * ------------------------------------------------------------------------------------------- */

	/** Booking with slot times and the resource columns (buffers, rules), or null. Plain read. */
	public static function booking( $booking_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$columns = self::resource_columns();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list from a constant.
		return $wpdb->get_row( $wpdb->prepare( "SELECT b.*,s.resource_id,s.starts_at,s.ends_at,s.capacity,s.status AS slot_status,$columns FROM {$p}wpcap_service_bookings b INNER JOIN {$p}wpcap_service_slots s ON s.slot_id=b.slot_id INNER JOIN {$p}wpcap_resources r ON r.resource_id=s.resource_id WHERE b.booking_id=%d", absint( $booking_id ) ) );
	}

	/** Whether $booking (row) still occupies its places. */
	public static function is_active( $booking ) {
		return 'confirmed' === $booking->status || ( in_array( $booking->status, array( 'held', 'pending_payment' ), true ) && ( ! $booking->expires_at || $booking->expires_at > Clock::mysql() ) );
	}

	private static function state_error( $message = '' ) {
		return new \WP_Error( 'wpcap_booking_state', '' !== $message ? $message : __( 'Der Status dieser Buchung erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	/**
	 * Actor check: 'admin' needs wpcap_manage_events for the event; 'provider' the event team or a
	 * service provider of the resource (A143); 'customer' is authorised by the
	 * caller (booking token, order portal) and bound to the customer rules (deadlines).
	 */
	private static function actor( array $args, $booking ) {
		if ( 'provider' === ( $args['actor'] ?? '' ) ) {
			// Service provider of the resource or event team (set_booking_status(), A143).
			return self::can_handle_booking( $booking ) ? 'provider' : self::forbidden();
		}
		$actor = 'admin' === ( $args['actor'] ?? 'customer' ) ? 'admin' : 'customer';
		if ( 'admin' === $actor && ! Access::can_access_event( (int) $booking->event_id, 'wpcap_manage_events' ) ) { return self::forbidden(); }
		return $actor;
	}

	/**
	 * Opens a booking transaction for $booking_id (+ optional target slot): locks resources,
	 * providers, slots (ServiceBookings::lock()) and finally the booking row, then reads the booking.
	 *
	 * @return object|\WP_Error Fresh booking row (transaction open) or error (transaction closed).
	 */
	private static function begin( $booking_id, $target_slot = null ) {
		global $wpdb;
		$before = self::booking( $booking_id );
		if ( ! $before ) { return new \WP_Error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$resources = array( (int) $before->resource_id );
		$slots = array( (int) $before->slot_id );
		if ( $target_slot ) {
			$resources[] = (int) $target_slot->resource_id;
			$slots[] = (int) $target_slot->slot_id;
		}
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::lock( $resources, $slots );
		if ( is_wp_error( $locked ) ) { return $locked; }
		$wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM {$wpdb->prefix}wpcap_service_bookings WHERE booking_id=%d FOR UPDATE", (int) $before->booking_id ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		$booking = self::booking( $booking_id );
		// The slot of a booking is only changed under these locks; if a rebooking moved it in between,
		// the locks cover the wrong slot: retry.
		if ( ! $booking || (int) $booking->slot_id !== (int) $before->slot_id ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		return $booking;
	}

	private static function commit() {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return Errors::busy(); }
		return true;
	}

	/**
	 * Confirms a held booking. Re-checks under the locks that the hold is still valid, the slot is
	 * bookable and the A134 rules (booking window, per-customer limit) still hold.
	 *
	 * Payment (A135): when the resource has a ticket type (price/payment position), the booking is
	 * paid through the regular checkout (Rest\Controller::checkout(): payment providers, terms,
	 * vouchers, invoices, order portal). The order is linked to the booking inside the order
	 * transaction (ServiceBookingFlow::link_order(): status `pending_payment`, hold = order hold,
	 * at most until the start); `wpcap_order_paid` confirms it, an expired or cancelled order
	 * releases it. Without a ticket type the booking is confirmed at once (free service).
	 *
	 * @param int   $booking_id Booking.
	 * @param array $args       actor ('customer' default | 'admin'); for paid bookings the checkout
	 *                          fields provider, accept_terms, terms_version, locale, billing, code.
	 * @return array|\WP_Error booking_id, status ('confirmed' | 'pending_payment'), order data when paid.
	 */
	public static function confirm( $booking_id, array $args = array() ) {
		global $wpdb;
		$booking = self::begin( $booking_id );
		if ( is_wp_error( $booking ) ) { return $booking; }
		$actor = self::actor( $args, $booking );
		if ( is_wp_error( $actor ) ) { $wpdb->query( 'ROLLBACK' ); return $actor; }
		if ( in_array( $booking->status, array( 'confirmed', 'pending_payment' ), true ) && self::is_active( $booking ) ) { $wpdb->query( 'ROLLBACK' ); return array( 'booking_id' => (int) $booking->booking_id, 'status' => (string) $booking->status, 'order_id' => (int) $booking->order_id, 'already' => true ); }
		if ( 'held' !== $booking->status || ! self::is_active( $booking ) ) { $wpdb->query( 'ROLLBACK' ); return self::state_error( __( 'Die Reservierung ist abgelaufen. Bitte wähle das Zeitfenster erneut.', 'pass-captain' ) ); }
		$slot = self::bookable_slot( (int) $booking->slot_id );
		if ( ! $slot || $slot->starts_at <= Clock::mysql() ) { $wpdb->query( 'ROLLBACK' ); return self::unavailable(); }
		$rule_error = self::rule_error( $slot, (string) $booking->customer_email, (int) $booking->booking_id );
		if ( $rule_error ) { $wpdb->query( 'ROLLBACK' ); return $rule_error; }
		$ticket_type = self::flow_ready() ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_resources WHERE resource_id=%d", (int) $booking->resource_id ) ) : 0;
		if ( $ticket_type ) {
			// Keep the hold alive while the checkout runs; the order transaction then takes it over.
			$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'expires_at' => min( (string) $slot->starts_at, gmdate( 'Y-m-d H:i:s', Clock::now() + 15 * MINUTE_IN_SECONDS ) ) ) + self::touched(), array( 'booking_id' => (int) $booking->booking_id ) );
			$committed = self::commit();
			if ( is_wp_error( $committed ) ) { return $committed; }
			return ServiceBookingFlow::checkout( $booking, $slot, $ticket_type, $args );
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'status' => 'confirmed', 'expires_at' => null ) + self::touched(), array( 'booking_id' => (int) $booking->booking_id ) );
		$committed = self::commit();
		if ( is_wp_error( $committed ) ) { return $committed; }
		Audit::record( 'service_booking_confirmed', 'service_booking', (int) $booking->booking_id, array( 'event_id' => (int) $booking->event_id, 'slot_id' => (int) $booking->slot_id, 'actor' => $actor ) );
		ServiceBookingFlow::notify( (int) $booking->booking_id, 'confirmed' );
		return array( 'booking_id' => (int) $booking->booking_id, 'status' => 'confirmed' );
	}

	/** updated_at column (A135 schema) for an update, or nothing before the upgrade. */
	public static function touched() {
		return self::flow_ready() ? array( 'updated_at' => Clock::mysql() ) : array();
	}

	/** Whether the A135 schema (booking token, updated_at, resource ticket type) exists. */
	public static function flow_ready() {
		return self::has_column( 'wpcap_service_bookings', 'token_hash' ) && self::has_column( 'wpcap_resources', 'ticket_type_id' );
	}

	/**
	 * Confirms the bookings of a paid order (`wpcap_order_paid`). A booking whose hold lapsed before
	 * the payment arrived is confirmed only if its slot still has room and no resource/provider
	 * conflict (checked under the locks); otherwise it stays expired, the payment is refunded
	 * automatically (system refund, may wait for approval) and the team is informed.
	 *
	 * @return array booking_id => 'confirmed' | 'unallocated'.
	 */
	public static function confirm_paid_order( $order_id ) {
		global $wpdb;
		$out = array();
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT booking_id FROM {$wpdb->prefix}wpcap_service_bookings WHERE order_id=%d AND status IN ('pending_payment','expired','held') ORDER BY booking_id", absint( $order_id ) ) ) );
		foreach ( $ids as $booking_id ) {
			$booking = self::begin( $booking_id );
			if ( is_wp_error( $booking ) ) {
				// Lock trouble: retried by a job so a paid booking is never left unconfirmed.
				Jobs::enqueue( 'service-booking-paid|' . $booking_id, 'service_booking_paid_retry', array( 'order_id' => absint( $order_id ) ), gmdate( 'Y-m-d H:i:s', Clock::now() + 2 * MINUTE_IN_SECONDS ) );
				continue;
			}
			if ( (int) $booking->order_id !== (int) $order_id || 'confirmed' === $booking->status || 'cancelled' === $booking->status ) { $wpdb->query( 'ROLLBACK' ); continue; }
			$ok = self::is_active( $booking );
			if ( ! $ok ) {
				$slot = self::bookable_slot( (int) $booking->slot_id );
				$ok = $slot && $slot->starts_at > Clock::mysql() && self::booked( (int) $booking->slot_id ) + (int) $booking->quantity <= (int) $slot->capacity && ! self::blocking_slot( $slot, (int) $booking->booking_id );
			}
			$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', ( $ok ? array( 'status' => 'confirmed', 'expires_at' => null ) : array( 'status' => 'unallocated', 'expires_at' => null ) ) + self::touched(), array( 'booking_id' => $booking_id ) );
			if ( is_wp_error( self::commit() ) ) { continue; }
			$out[ $booking_id ] = $ok ? 'confirmed' : 'unallocated';
			if ( $ok ) {
				Audit::record( 'service_booking_confirmed', 'service_booking', $booking_id, array( 'event_id' => (int) $booking->event_id, 'slot_id' => (int) $booking->slot_id, 'order_id' => absint( $order_id ), 'actor' => 'payment' ) );
				ServiceBookingFlow::notify( $booking_id, 'confirmed' );
				continue;
			}
			// The refund follows once the order's tickets exist (wpcap_tickets_issued → refund_unallocated()).
			Audit::record( 'service_booking_payment_unallocated', 'service_booking', $booking_id, array( 'event_id' => (int) $booking->event_id, 'order_id' => absint( $order_id ) ) );
			ServiceBookingFlow::notify( $booking_id, 'unallocated' );
		}
		return $out;
	}

	/**
	 * Refunds the order of bookings that were paid without a place (`unallocated`) – after the
	 * tickets were issued (`wpcap_tickets_issued`), so the refund covers and revokes them. System
	 * refund, idempotent per booking; above the approval threshold it waits for a second approval.
	 *
	 * @return array booking_id => refund row or WP_Error.
	 */
	public static function refund_unallocated( $order_id ) {
		global $wpdb;
		$out = array();
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT booking_id FROM {$wpdb->prefix}wpcap_service_bookings WHERE order_id=%d AND status='unallocated' ORDER BY booking_id", absint( $order_id ) ) ) );
		foreach ( $ids as $booking_id ) {
			$refund = Refunds::request( absint( $order_id ), array(), __( 'Servicebuchung: Zahlung nach Ablauf der Reservierung, Zeitfenster nicht mehr frei', 'pass-captain' ), 'service-booking-unallocated-' . $booking_id, array( 'system' => true, 'source' => 'service_booking' ) );
			Audit::record( 'service_booking_unallocated_refund', 'service_booking', $booking_id, array( 'order_id' => absint( $order_id ), 'refund' => is_wp_error( $refund ) ? $refund->get_error_code() : (string) ( $refund['status'] ?? '' ) ) );
			$out[ $booking_id ] = $refund;
		}
		return $out;
	}

	/**
	 * Moves an active booking to another slot of the same event – atomically: both resources, their
	 * providers, both slots and the booking are locked; the target is checked for capacity, buffers,
	 * resource/provider conflicts (ignoring the booking itself) and the A134 rules. Customers are
	 * also bound to the rebooking deadline of the current slot. A booking with an order (paid or
	 * awaiting payment) can only move to a resource with the same ticket type (same price); the
	 * order and its tickets stay as they are.
	 *
	 * @param int   $booking_id     Booking.
	 * @param int   $target_slot_id New slot.
	 * @param array $args           actor ('customer' default | 'admin').
	 * @return array|\WP_Error booking_id, slot_id, previous_slot_id.
	 */
	public static function rebook( $booking_id, $target_slot_id, array $args = array() ) {
		global $wpdb;
		$target = self::bookable_slot( $target_slot_id );
		if ( ! $target ) { return self::unavailable(); }
		$booking = self::begin( $booking_id, $target );
		if ( is_wp_error( $booking ) ) { return $booking; }
		$actor = self::actor( $args, $booking );
		if ( is_wp_error( $actor ) ) { $wpdb->query( 'ROLLBACK' ); return $actor; }
		if ( ! in_array( $booking->status, array( 'held', 'pending_payment', 'confirmed' ), true ) || ! self::is_active( $booking ) ) { $wpdb->query( 'ROLLBACK' ); return self::state_error(); }
		if ( (int) $target->slot_id === (int) $booking->slot_id ) { $wpdb->query( 'ROLLBACK' ); return self::state_error( __( 'Die Buchung liegt bereits in diesem Zeitfenster.', 'pass-captain' ) ); }
		if ( 'customer' === $actor ) {
			$deadline = self::rebook_deadline_error( $booking );
			if ( $deadline ) { $wpdb->query( 'ROLLBACK' ); return $deadline; }
		}
		$target = self::bookable_slot( (int) $target->slot_id );
		if ( ! $target || 'open' !== $target->status || $target->starts_at <= Clock::mysql() || (int) $target->event_id !== (int) $booking->event_id ) { $wpdb->query( 'ROLLBACK' ); return self::unavailable(); }
		if ( self::flow_ready() && (int) $booking->order_id ) {
			$types = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_resources WHERE resource_id IN (%d,%d)", (int) $booking->resource_id, (int) $target->resource_id ) );
			if ( count( array_unique( array_map( 'intval', $types ) ) ) > 1 ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_rebook_price', __( 'Eine bezahlte Buchung kann nur auf ein Zeitfenster mit demselben Preis umgebucht werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		}
		$rule_error = self::rule_error( $target, (string) $booking->customer_email, (int) $booking->booking_id );
		if ( $rule_error ) { $wpdb->query( 'ROLLBACK' ); return $rule_error; }
		if ( self::booked( (int) $target->slot_id ) + (int) $booking->quantity > (int) $target->capacity ) { $wpdb->query( 'ROLLBACK' ); return self::full(); }
		$blocked = self::blocking_error( $target, (int) $booking->booking_id );
		if ( $blocked ) { $wpdb->query( 'ROLLBACK' ); return $blocked; }
		/**
		 * Inside the rebooking transaction, after every check (resources, providers, both slots and the
		 * booking are locked). For extensions and deterministic race tests.
		 */
		do_action( 'wpcap_service_booking_rebooking', (int) $booking->booking_id, (int) $target->slot_id );
		$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'slot_id' => (int) $target->slot_id ) + self::touched(), array( 'booking_id' => (int) $booking->booking_id ) );
		$committed = self::commit();
		if ( is_wp_error( $committed ) ) { return $committed; }
		Audit::record( 'service_booking_rebooked', 'service_booking', (int) $booking->booking_id, array( 'event_id' => (int) $booking->event_id, 'from_slot_id' => (int) $booking->slot_id, 'to_slot_id' => (int) $target->slot_id, 'actor' => $actor ) );
		ServiceBookingFlow::notify( (int) $booking->booking_id, 'rebooked', array( 'previous' => $booking ) );
		return array( 'booking_id' => (int) $booking->booking_id, 'slot_id' => (int) $target->slot_id, 'previous_slot_id' => (int) $booking->slot_id );
	}

	/**
	 * Cancels an active booking; its places are free at once. Customers are bound to the
	 * cancellation deadline (`cancel_hours` before the start) for confirmed bookings; staff (actor
	 * admin) are not. A135: an unpaid order of the booking is cancelled; a paid one is refunded in
	 * full through Refunds (customers within the deadline always; staff with `refund` => true, the
	 * default, which needs the refund capability). The refund may wait for a second approval.
	 *
	 * @param int   $booking_id Booking.
	 * @param array $args       actor ('customer' default | 'admin'), reason, refund (staff only, default true).
	 * @return array|\WP_Error booking_id, status, previous, refund (refund row, WP_Error or null).
	 */
	public static function cancel( $booking_id, array $args = array() ) {
		global $wpdb;
		$refund_wanted = 'admin' !== ( $args['actor'] ?? 'customer' ) || ! array_key_exists( 'refund', $args ) || ! empty( $args['refund'] );
		$booking = self::begin( $booking_id );
		if ( is_wp_error( $booking ) ) { return $booking; }
		$actor = self::actor( $args, $booking );
		if ( is_wp_error( $actor ) ) { $wpdb->query( 'ROLLBACK' ); return $actor; }
		if ( 'cancelled' === $booking->status ) { $wpdb->query( 'ROLLBACK' ); return array( 'booking_id' => (int) $booking->booking_id, 'status' => 'cancelled', 'already' => true ); }
		if ( ! self::is_active( $booking ) ) { $wpdb->query( 'ROLLBACK' ); return self::state_error(); }
		if ( 'customer' === $actor && 'confirmed' === $booking->status ) {
			$deadline = self::cancel_deadline_error( $booking );
			if ( $deadline ) { $wpdb->query( 'ROLLBACK' ); return $deadline; }
		}
		$order = (int) $booking->order_id ? Orders::get( (int) $booking->order_id ) : null;
		$paid = $order && 'paid' === $order->payment_status && 'confirmed' === $booking->status;
		if ( $paid && $refund_wanted && 'admin' === $actor && ! current_user_can( Refunds::REFUND_CAP ) && ! current_user_can( Refunds::APPROVE_CAP ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_refund_forbidden', __( 'Keine Berechtigung für Erstattungen. Storniere ohne Erstattung oder bitte eine berechtigte Person.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'status' => 'cancelled', 'expires_at' => null ) + self::touched(), array( 'booking_id' => (int) $booking->booking_id ) );
		$committed = self::commit();
		if ( is_wp_error( $committed ) ) { return $committed; }
		$reason = sanitize_text_field( (string) ( $args['reason'] ?? '' ) );
		$refund = null;
		if ( $order && 'pending_payment' === $booking->status && 'pending' === $order->payment_status ) {
			Orders::cancel_order( (int) $order->order_id, 'service_booking_cancelled' );
		} elseif ( $paid && $refund_wanted ) {
			$refund = Refunds::request( (int) $order->order_id, array(), '' !== $reason ? $reason : __( 'Servicebuchung storniert', 'pass-captain' ), 'service-booking-cancel-' . (int) $booking->booking_id, array( 'system' => 'customer' === $actor, 'source' => 'service_booking' ) );
		}
		Audit::record( 'service_booking_cancelled', 'service_booking', (int) $booking->booking_id, array( 'event_id' => (int) $booking->event_id, 'slot_id' => (int) $booking->slot_id, 'actor' => $actor, 'previous' => $booking->status, 'reason' => $reason, 'order_id' => (int) $booking->order_id, 'refund' => null === $refund ? '' : ( is_wp_error( $refund ) ? $refund->get_error_code() : (string) ( $refund['status'] ?? '' ) ) ) );
		ServiceBookingFlow::notify( (int) $booking->booking_id, 'cancelled', array( 'actor' => $actor, 'refund' => $refund, 'previous' => $booking ) );
		return array( 'booking_id' => (int) $booking->booking_id, 'status' => 'cancelled', 'previous' => (string) $booking->status, 'refund' => $refund );
	}

	/**
	 * Releases the open bookings of an order that ended without payment (`wpcap_order_cancelled`):
	 * `pending_payment` → `expired` (order expired) or `cancelled`. Confirmed bookings of a paid order
	 * that was cancelled or refunded elsewhere (order admin, provider dashboard) are cancelled too.
	 *
	 * @return int[] Booking ids changed.
	 */
	public static function release_order( $order_id ) {
		global $wpdb;
		$order = Orders::get( $order_id );
		if ( ! $order ) { return array(); }
		$changed = array();
		$ended = in_array( $order->status, array( 'cancelled', 'refunded', 'expired' ), true );
		if ( ! $ended ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT booking_id,status FROM {$wpdb->prefix}wpcap_service_bookings WHERE order_id=%d AND status IN ('held','pending_payment','confirmed')", (int) $order->order_id ) );
		foreach ( $rows as $row ) {
			$to = 'confirmed' === $row->status || 'expired' !== $order->status ? 'cancelled' : 'expired';
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_bookings SET status=%s,expires_at=NULL WHERE booking_id=%d AND status=%s", $to, (int) $row->booking_id, $row->status ) );
			if ( 1 !== (int) $done ) { continue; }
			$changed[] = (int) $row->booking_id;
			Audit::record( 'service_booking_released', 'service_booking', (int) $row->booking_id, array( 'order_id' => (int) $order->order_id, 'order_status' => $order->status, 'previous' => $row->status, 'status' => $to ) );
			if ( 'confirmed' === $row->status ) { ServiceBookingFlow::notify( (int) $row->booking_id, 'cancelled', array( 'actor' => 'order', 'order_status' => $order->status ) ); }
		}
		return $changed;
	}
}
