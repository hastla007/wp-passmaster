<?php
/**
 * Resource and appointment-slot bookings: holds, booking rules, payment confirmation, partner
 * confirmation, cancellation, rebooking and no-show handling. See docs/SERVICE-BOOKINGS.md.
 *
 * Status model: held (capacity hold, expires) → confirmed | pending_confirmation (partner decision
 * due by confirm_by) → checked_in | no_show; cancelled / declined end a booking (capacity freed),
 * expired = hold lapsed. A dearer rebooking holds the target place with a supplement row
 * (supplement_for = booking, status held until the supplementary order's hold ends) that becomes
 * merged when the supplement is paid. Capacity counts held (unexpired), pending_confirmation, confirmed,
 * checked_in and no_show plus open waitlist offers (ServiceWaitlist).
 *
 * Lock order (every path): resource row(s) by id → slot row(s) by id → booking row → order row.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

use PassCaptain\Services\Refunder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceBookings {
	/** Job types (Jobs queue). */
	const JOB_REFUND = 'service_booking_refund';
	const JOB_SLOT_FREED = 'service_slot_freed';
	/** Statuses a buyer or admin may cancel / rebook. */
	const CANCELLABLE = array( 'held', 'pending_confirmation', 'confirmed' );
	const REBOOKABLE = array( 'pending_confirmation', 'confirmed' );

	/* ================================================================== Reservation */

	/**
	 * Reserves places in a slot (capacity hold). Idempotent per key.
	 *
	 * @param array $args actor (customer|admin|system; non-customers skip cutoff, opening window and
	 *                    per-customer limit), skip_rate_limit, waitlist_entry_id (its offer hold is
	 *                    consumed), status (held|confirmed – confirmed only for internal pass bookings),
	 *                    pass_use_id.
	 * @return array|\WP_Error booking_id, expires_at, status, manage_token (new bookings only), reused.
	 */
	public static function reserve( $slot_id, $email, $quantity = 1, $order_id = 0, $minutes = 10, $idempotency_key = '', array $args = array() ) {
		global $wpdb;
		$slot_id = absint( $slot_id );
		$email = Http::email( $email ); // A492: arrays/objects from a request body become '' instead of a TypeError.
		$quantity = Orders::positive_int( $quantity );
		if ( $quantity < 1 || $quantity > 20 ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$idempotency_key = sanitize_text_field( Http::str( $idempotency_key ) );
		$actor = (string) ( $args['actor'] ?? 'customer' );
		$actor = in_array( $actor, array( 'customer', 'admin', 'system' ), true ) ? $actor : 'customer';
		if ( ! $slot_id || ! is_email( $email ) || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ) ); }
		if ( empty( $args['skip_rate_limit'] ) ) {
			// Per address and per client; RateLimit::hit() counts atomically (QA-V2).
			if ( ! RateLimit::hit( 'wpcap_servicebook_' . RateLimit::hash( strtolower( $email ) ), 20, HOUR_IN_SECONDS ) || ! RateLimit::hit( 'wpcap_servicebook_ip_' . RateLimit::client(), (int) apply_filters( 'wpcap_service_booking_ip_limit', 40 ), HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) );
			}
		}
		$gate_key = $idempotency_key;
		$idempotency_key = hash( 'sha256', $idempotency_key );
		$slots = $wpdb->prefix . 'wpcap_service_slots';
		$bookings = $wpdb->prefix . 'wpcap_service_bookings';
		// Add-on gate (e.g. virtual waiting room) for customer bookings; a retry of an existing booking passes.
		if ( 'customer' === $actor && Sales\CheckoutGate::is_active() && ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE idempotency_key=%s', $wpdb->prefix . 'wpcap_service_bookings', $idempotency_key ) ) ) {
			$gate_resource = $wpdb->get_row( $wpdb->prepare( 'SELECT r.event_id,r.ticket_type_id FROM %i s INNER JOIN %i r ON r.resource_id=s.resource_id WHERE s.slot_id=%d', $wpdb->prefix . 'wpcap_service_slots', $wpdb->prefix . 'wpcap_resources', $slot_id ) );
			$gate = Sales\CheckoutGate::check( array(
				'source' => empty( $args['pass_use_id'] ) ? 'service_booking' : 'service_pass_booking',
				'phase' => 'booking',
				'items' => $gate_resource && (int) $gate_resource->ticket_type_id ? array( (int) $gate_resource->ticket_type_id => $quantity ) : array(),
				'event_ids' => $gate_resource ? array( (int) $gate_resource->event_id ) : array(),
				'slot_id' => $slot_id,
				'email' => $email,
				'idempotency_key' => $gate_key,
				'request' => $args['request'] ?? null,
			) );
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		$resource_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT resource_id FROM %i WHERE slot_id=%d', $slots, $slot_id ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		// Lock phase first (locking reads only): resource (serialises bookings of overlapping slots),
		// then the slot. The first consistent read below then sees every committed booking.
		$resource = $resource_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_resources', $resource_id ) ) : null;
		$slot = $resource ? $wpdb->get_row( $wpdb->prepare( "SELECT s.* FROM %i s INNER JOIN %i e ON e.event_id=%d WHERE s.slot_id=%d AND s.resource_id=%d AND e.status='published' FOR UPDATE", $slots, $wpdb->prefix . 'wpcap_events', (int) $resource->event_id, $slot_id, $resource_id ) ) : null;
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT booking_id,slot_id,customer_email,quantity,expires_at,status FROM %i WHERE idempotency_key=%s', $bookings, $idempotency_key ) );
		if ( $existing ) {
			if ( (int) $existing->slot_id !== $slot_id || strtolower( $existing->customer_email ) !== strtolower( $email ) || (int) $existing->quantity !== $quantity ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_idempotency_conflict', __( 'Idempotency-Key wurde für eine andere Buchung verwendet.', 'pass-captain' ), array( 'status' => 409 ) ); }
			$wpdb->query( 'COMMIT' );
			return array( 'booking_id' => (int) $existing->booking_id, 'expires_at' => $existing->expires_at, 'status' => $existing->status, 'reused' => true );
		}
		$now = current_time( 'mysql', true );
		if ( ! $resource || 'active' !== $resource->status || ! $slot || 'open' !== $slot->status || $slot->starts_at <= $now ) { $wpdb->query( 'ROLLBACK' ); return self::unavailable(); }
		$rules = ServiceResources::rules( $resource );
		if ( 'customer' === $actor ) {
			$rule_error = self::booking_rule_error( $resource, $slot, $rules, $email, $quantity, 0 );
			if ( $rule_error ) { $wpdb->query( 'ROLLBACK' ); return $rule_error; }
		}
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired',updated_at=%s WHERE slot_id=%d AND status='held' AND expires_at <= %s", $bookings, $now, $slot_id, $now ) );
		$entry_id = absint( $args['waitlist_entry_id'] ?? 0 );
		if ( $entry_id ) {
			// Booking from a waitlist offer: the offer must still be open; it is consumed in this transaction.
			$entry = $wpdb->get_row( $wpdb->prepare( "SELECT entry_id FROM %i WHERE entry_id=%d AND slot_id=%d AND status='offered' AND offer_expires_at>%s FOR UPDATE", $wpdb->prefix . 'wpcap_service_waitlist', $entry_id, $slot_id, $now ) );
			if ( ! $entry ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) ); }
		}
		$capacity_error = self::capacity_error( $resource, $slot, $quantity, 0, $entry_id );
		if ( $capacity_error ) { $wpdb->query( 'ROLLBACK' ); return $capacity_error; }
		$status = 'confirmed' === ( $args['status'] ?? '' ) ? 'confirmed' : 'held';
		$expires = null;
		if ( 'held' === $status ) {
			$expires = gmdate( 'Y-m-d H:i:s', time() + min( 15, max( 1, absint( $minutes ) ) ) * MINUTE_IN_SECONDS );
			$order_hold = $order_id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM %i WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', absint( $order_id ) ) ) : '';
			if ( '' !== $order_hold && $order_hold > $expires ) { $expires = $order_hold; }
		}
		list( $unit, $currency ) = ServiceResources::price_of( $resource );
		$token = bin2hex( random_bytes( 32 ) );
		$inserted = $wpdb->insert( $bookings, array( 'slot_id' => $slot_id, 'order_id' => absint( $order_id ), 'customer_email' => $email, 'idempotency_key' => $idempotency_key, 'quantity' => $quantity, 'expires_at' => $expires, 'status' => $status, 'amount_minor' => empty( $args['pass_use_id'] ) ? $unit * $quantity : 0, 'currency' => $currency, 'manage_token_hash' => hash( 'sha256', $token ), 'pass_use_id' => absint( $args['pass_use_id'] ?? 0 ), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $inserted || ! $wpdb->insert_id ) {
			$wpdb->query( 'ROLLBACK' );
			// A parallel request with the same key won the unique index.
			$won = $wpdb->get_row( $wpdb->prepare( 'SELECT booking_id,expires_at,status FROM %i WHERE idempotency_key=%s', $bookings, $idempotency_key ) );
			return $won ? array( 'booking_id' => (int) $won->booking_id, 'expires_at' => $won->expires_at, 'status' => $won->status, 'reused' => true ) : new \WP_Error( 'wpcap_booking_failed', __( 'Zeitfenster konnte nicht reserviert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( $entry_id && 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='accepted',booking_id=%d,updated_at=%s WHERE entry_id=%d AND status='offered'", $wpdb->prefix . 'wpcap_service_waitlist', $id, $now, $entry_id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::busy();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		Audit::record( 'service_booking_reserved', 'service_booking', $id, array( 'slot_id' => $slot_id, 'quantity' => $quantity, 'order_id' => absint( $order_id ), 'status' => $status, 'actor' => $actor ) );
		return array( 'booking_id' => $id, 'expires_at' => $expires, 'status' => $status, 'manage_token' => $token );
	}

	/**
	 * Booking rules of the resource for a customer booking (cutoff, opening window, per-customer limit,
	 * no-show block). $exclude_booking_id is left out of the per-customer count (rebooking).
	 *
	 * @return \WP_Error|null
	 */
	private static function booking_rule_error( $resource, $slot, array $rules, $email, $quantity, $exclude_booking_id ) {
		global $wpdb;
		$start = strtotime( $slot->starts_at . ' UTC' );
		if ( $rules['cutoff_minutes'] > 0 && $start - $rules['cutoff_minutes'] * MINUTE_IN_SECONDS <= time() ) {
			/* translators: %d: minutes */
			return new \WP_Error( 'wpcap_booking_closed', sprintf( __( 'Buchungsschluss: Dieses Zeitfenster kann nur bis %d Minuten vor Beginn gebucht werden.', 'pass-captain' ), (int) $rules['cutoff_minutes'] ), array( 'status' => 409 ) );
		}
		if ( $rules['opens_days'] > 0 && $start > time() + $rules['opens_days'] * DAY_IN_SECONDS ) {
			/* translators: %d: days */
			return new \WP_Error( 'wpcap_booking_too_early', sprintf( __( 'Dieses Zeitfenster ist noch nicht buchbar: Buchungen sind höchstens %d Tage im Voraus möglich.', 'pass-captain' ), (int) $rules['opens_days'] ), array( 'status' => 409 ) );
		}
		$p = $wpdb->prefix;
		if ( $rules['max_per_customer'] > 0 ) {
			list( $from, $to ) = self::period_window( $resource, $slot->starts_at, $rules['max_period'] );
			$booked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(b.quantity),0) FROM {$p}wpcap_service_bookings b INNER JOIN {$p}wpcap_service_slots s ON s.slot_id=b.slot_id WHERE s.resource_id=%d AND b.customer_email=%s AND b.booking_id<>%d AND s.starts_at>=%s AND s.starts_at<%s AND " . ServiceResources::active_sql( 'b' ), (int) $resource->resource_id, $email, absint( $exclude_booking_id ), $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment.
			if ( $booked + $quantity > $rules['max_per_customer'] ) {
				/* translators: %d: places */
				return new \WP_Error( 'wpcap_booking_limit', sprintf( __( 'Pro Person sind in diesem Zeitraum höchstens %d Plätze buchbar.', 'pass-captain' ), (int) $rules['max_per_customer'] ), array( 'status' => 409 ) );
			}
		}
		if ( $rules['noshow_block_after'] > 0 ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - $rules['noshow_block_days'] * DAY_IN_SECONDS );
			$noshows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i b INNER JOIN %i s ON s.slot_id=b.slot_id WHERE s.resource_id=%d AND b.customer_email=%s AND b.status='no_show' AND b.noshow_at>=%s", $p . 'wpcap_service_bookings', $p . 'wpcap_service_slots', (int) $resource->resource_id, $email, $since ) );
			if ( $noshows >= $rules['noshow_block_after'] ) {
				return new \WP_Error( 'wpcap_booking_blocked', __( 'Wegen nicht wahrgenommener Termine sind neue Buchungen für dieses Angebot derzeit nicht möglich. Bitte wende dich an den Veranstalter.', 'pass-captain' ), array( 'status' => 403 ) );
			}
		}
		return null;
	}

	/** UTC window [from, to) of the limit period containing the slot start (local time of the resource). */
	private static function period_window( $resource, $starts_utc, $period ) {
		if ( 'total' === $period ) { return array( current_time( 'mysql', true ), '9999-12-31 00:00:00' ); }
		$tz = new \DateTimeZone( ServiceResources::timezone_of( $resource ) );
		$local = ( new \DateTimeImmutable( $starts_utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		if ( 'week' === $period ) {
			$from = $local->modify( 'monday this week' )->setTime( 0, 0 );
			$to = $from->modify( '+1 week' );
		} elseif ( 'month' === $period ) {
			$from = $local->modify( 'first day of this month' )->setTime( 0, 0 );
			$to = $from->modify( '+1 month' );
		} else {
			$from = $local->setTime( 0, 0 );
			$to = $from->modify( '+1 day' );
		}
		$utc = new \DateTimeZone( 'UTC' );
		return array( $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Capacity and collision check under the resource and slot locks. $exclude_booking_id is not
	 * counted (rebooking/late confirmation); $waitlist_entry_id's open offer hold is consumed.
	 *
	 * @return \WP_Error|null
	 */
	private static function capacity_error( $resource, $slot, $quantity, $exclude_booking_id, $waitlist_entry_id = 0 ) {
		global $wpdb;
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=%d AND b.booking_id<>%d AND " . ServiceResources::active_sql( 'b' ), (int) $slot->slot_id, absint( $exclude_booking_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment.
		$held = ServiceWaitlist::held_quantity( (int) $slot->slot_id, $waitlist_entry_id );
		if ( $used + $held + $quantity > (int) $slot->capacity ) {
			return new \WP_Error( 'wpcap_slot_full', __( 'Für dieses Zeitfenster sind nicht genügend Plätze frei.', 'pass-captain' ), array( 'status' => 409, 'waitlist' => (bool) ServiceResources::rules( $resource )['waitlist_enabled'] ) );
		}
		// A booked slot of the same resource that overlaps incl. buffers (legacy or REST-created slots).
		if ( ServiceResources::conflicting_slot( $resource, $slot->starts_at, $slot->ends_at, (int) $slot->slot_id, true ) ) {
			return new \WP_Error( 'wpcap_slot_conflict', __( 'Dieses Zeitfenster überschneidet sich mit einer bestehenden Buchung derselben Ressource (inklusive Pufferzeit).', 'pass-captain' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/* ================================================================== Order linkage and confirmation */

	/**
	 * Links a held booking to the (pending or paid) order that pays it. The order must contain enough
	 * units of the resource's ticket type that are not used by other bookings. A paid order confirms
	 * the booking immediately; otherwise the payment does (wpcap_order_paid).
	 *
	 * @return array|\WP_Error Booking state.
	 */
	public static function attach_order( $booking_id, $order_id ) {
		global $wpdb;
		$booking = self::get( $booking_id );
		$order = Orders::get( $order_id );
		if ( ! $booking || ! $order ) { return self::not_found(); }
		$resource = ServiceResources::resource( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT resource_id FROM %i WHERE slot_id=%d', $wpdb->prefix . 'wpcap_service_slots', (int) $booking->slot_id ) ) );
		if ( ! $resource || ! (int) $resource->ticket_type_id ) { return new \WP_Error( 'wpcap_booking_free', __( 'Diese Ressource ist kostenlos; eine Bestellung ist nicht nötig.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( (int) $booking->order_id && (int) $booking->order_id !== (int) $order->order_id ) { return new \WP_Error( 'wpcap_booking_order_conflict', __( 'Diese Buchung ist bereits mit einer anderen Bestellung verknüpft.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( ! in_array( $booking->status, array( 'held', 'expired' ), true ) && ! (int) $booking->order_id ) { return self::state_error(); }
		if ( strtolower( $order->customer_email ) !== strtolower( $booking->customer_email ) ) { return new \WP_Error( 'wpcap_booking_order_conflict', __( 'Bestellung und Buchung gehören nicht zur selben E-Mail-Adresse.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$ordered = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(quantity-refunded_quantity),0) FROM %i WHERE order_id=%d AND ticket_type_id=%d', $wpdb->prefix . 'wpcap_order_items', (int) $order->order_id, (int) $resource->ticket_type_id ) );
		$linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM %i WHERE order_id=%d AND booking_id<>%d AND status NOT IN ('cancelled','declined','expired')", $wpdb->prefix . 'wpcap_service_bookings', (int) $order->order_id, (int) $booking->booking_id ) );
		if ( $ordered - $linked < (int) $booking->quantity ) { return new \WP_Error( 'wpcap_booking_order_items', __( 'Die Bestellung enthält nicht genügend Plätze dieser Ressource.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( ! (int) $booking->order_id ) {
			$hold = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM %i WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', (int) $order->order_id ) );
			$data = array( 'order_id' => (int) $order->order_id, 'updated_at' => current_time( 'mysql', true ) );
			if ( 'held' === $booking->status && '' !== $hold && $hold > (string) $booking->expires_at ) { $data['expires_at'] = $hold; }
			$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', $data, array( 'booking_id' => (int) $booking->booking_id, 'order_id' => 0 ) );
			Audit::record( 'service_booking_order_attached', 'service_booking', (int) $booking->booking_id, array( 'order_id' => (int) $order->order_id ) );
		}
		if ( 'paid' === $order->payment_status ) { return self::confirm( (int) $booking->booking_id ); }
		return self::state( self::get( $booking_id ) );
	}

	/** Hook wpcap_order_paid / wpcap_tickets_issued: confirms the order's bookings and fills ticket links. */
	public static function on_order_paid( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) { return; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT booking_id,status,ticket_ids,refund_id,cancel_reason,supplement_for FROM %i WHERE order_id=%d ORDER BY booking_id', $wpdb->prefix . 'wpcap_service_bookings', $order_id ) );
		foreach ( (array) $rows as $row ) {
			if ( (int) $row->supplement_for ) {
				// Supplementary order of a dearer rebooking: move the booking (or refund the supplement).
				if ( 'merged' !== $row->status && ! (int) $row->refund_id ) { self::complete_supplement( (int) $row->booking_id ); }
			} elseif ( in_array( $row->status, array( 'held', 'expired' ), true ) ) {
				self::confirm( (int) $row->booking_id );
			} elseif ( in_array( $row->status, array( 'confirmed', 'pending_confirmation', 'checked_in' ), true ) && '' === (string) $row->ticket_ids ) {
				self::fill_tickets( (int) $row->booking_id );
			} elseif ( in_array( $row->status, array( 'cancelled', 'declined' ), true ) && ! (int) $row->refund_id && 'admin_no_refund' !== $row->cancel_reason ) {
				// Cancelled while the payment was still open: the late payment is refunded.
				self::fill_tickets( (int) $row->booking_id );
				self::refund( (int) $row->booking_id );
			}
		}
	}

	/** Hook wpcap_order_cancelled: holds of an order that expired/was cancelled unpaid are released. */
	public static function on_order_cancelled( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT booking_id,slot_id FROM %i WHERE order_id=%d AND status='held'", $wpdb->prefix . 'wpcap_service_bookings', absint( $order_id ) ) );
		foreach ( (array) $rows as $row ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired',updated_at=%s WHERE booking_id=%d AND status='held'", $wpdb->prefix . 'wpcap_service_bookings', current_time( 'mysql', true ), (int) $row->booking_id ) );
			if ( 1 === (int) $changed ) {
				Audit::record( 'service_booking_expired', 'service_booking', (int) $row->booking_id, array( 'order_id' => absint( $order_id ) ) );
				self::slot_freed( (int) $row->slot_id );
			}
		}
	}

	/** Free booking (resource without price) confirmed by the buyer with the booking's manage token. */
	public static function confirm_free( $booking_id, $token ) {
		$booking = self::with_token( $booking_id, $token );
		if ( ! $booking ) { return self::not_found(); }
		$resource = self::resource_of( $booking );
		if ( $resource && (int) $resource->ticket_type_id ) { return new \WP_Error( 'wpcap_booking_payment_required', __( 'Diese Buchung wird mit der Zahlung der Bestellung bestätigt.', 'pass-captain' ), array( 'status' => 402 ) ); }
		return self::confirm( (int) $booking->booking_id, array( 'manage_token' => $token ) );
	}

	/**
	 * Confirms a held (or late-paid expired) booking under the resource/slot locks. Paid bookings need
	 * a paid order. Resources with partner confirmation go to pending_confirmation. A late payment whose
	 * place is gone is cancelled and refunded (never overbooked). Idempotent.
	 *
	 * @return array|\WP_Error Booking state.
	 */
	public static function confirm( $booking_id, array $context = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$booking = self::get( $booking_id );
		if ( ! $booking ) { return self::not_found(); }
		$resource_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT resource_id FROM %i WHERE slot_id=%d', $p . 'wpcap_service_slots', (int) $booking->slot_id ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$resource = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d FOR UPDATE', $p . 'wpcap_resources', $resource_id ) );
		$slot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d FOR UPDATE', $p . 'wpcap_service_slots', (int) $booking->slot_id ) );
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d FOR UPDATE', $p . 'wpcap_service_bookings', (int) $booking->booking_id ) );
		if ( '' !== (string) $wpdb->last_error || ! $resource || ! $slot || ! $booking ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		if ( ! in_array( $booking->status, array( 'held', 'expired' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return self::state( $booking );
		}
		if ( (int) $resource->ticket_type_id ) {
			$order = (int) $booking->order_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT payment_status FROM %i WHERE order_id=%d', $p . 'wpcap_orders', (int) $booking->order_id ) ) : null;
			if ( ! $order || 'paid' !== $order->payment_status ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wpcap_booking_payment_required', __( 'Diese Buchung wird mit der Zahlung der Bestellung bestätigt.', 'pass-captain' ), array( 'status' => 402 ) );
			}
		}
		$now = current_time( 'mysql', true );
		$lapsed = 'expired' === $booking->status || ( $booking->expires_at && $booking->expires_at <= $now );
		$lost = false;
		if ( $lapsed ) {
			$lost = null !== self::capacity_error( $resource, $slot, (int) $booking->quantity, (int) $booking->booking_id );
		}
		$rules = ServiceResources::rules( $resource );
		if ( $lost ) {
			$data = array( 'status' => 'cancelled', 'cancel_reason' => 'capacity_lost', 'expires_at' => null, 'updated_at' => $now );
		} elseif ( $rules['requires_confirmation'] ) {
			$by = min( time() + $rules['confirmation_hours'] * HOUR_IN_SECONDS, strtotime( $slot->starts_at . ' UTC' ) );
			$data = array( 'status' => 'pending_confirmation', 'confirm_by' => gmdate( 'Y-m-d H:i:s', $by ), 'expires_at' => null, 'updated_at' => $now );
		} else {
			$data = array( 'status' => 'confirmed', 'expires_at' => null, 'updated_at' => $now );
		}
		$updated = $wpdb->update( "{$p}wpcap_service_bookings", $data, array( 'booking_id' => (int) $booking->booking_id ) );
		if ( 1 !== (int) $updated || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		self::fill_tickets( (int) $booking->booking_id );
		$booking = self::get( $booking->booking_id );
		Audit::record( 'service_booking_' . ( $lost ? 'capacity_lost' : $data['status'] ), 'service_booking', (int) $booking->booking_id, array( 'slot_id' => (int) $slot->slot_id, 'order_id' => (int) $booking->order_id, 'late' => $lapsed ) );
		if ( $lost ) {
			self::refund( (int) $booking->booking_id );
			self::mail( 'service_booking_cancelled', $booking, array( 'reason' => __( 'Das Zeitfenster war bei Zahlungseingang bereits ausgebucht.', 'pass-captain' ) ) );
			return self::state( self::get( $booking->booking_id ) );
		}
		if ( 'pending_confirmation' === $data['status'] ) {
			self::mail( 'service_booking_pending', $booking, array() );
			self::notify_partner( $booking, $resource );
		} else {
			self::mail( 'service_booking_confirmed', $booking, array( 'manage_token' => (string) ( $context['manage_token'] ?? '' ) ) );
		}
		return self::state( $booking );
	}

	/** Links the booking to still unlinked tickets of the resource's ticket type in its order; updates the amount. */
	public static function fill_tickets( $booking_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$booking = self::get( $booking_id );
		if ( ! $booking || ! (int) $booking->order_id || '' !== (string) $booking->ticket_ids ) { return; }
		$resource = self::resource_of( $booking );
		if ( ! $resource || ! (int) $resource->ticket_type_id ) { return; }
		$used = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_ids FROM %i WHERE order_id=%d AND booking_id<>%d AND ticket_ids<>''", $p . 'wpcap_service_bookings', (int) $booking->order_id, (int) $booking->booking_id ) ) as $json ) {
			$used = array_merge( $used, array_map( 'absint', (array) json_decode( (string) $json, true ) ) );
		}
		$candidates = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND ticket_type_id=%d AND refunded=0 AND status IN ('valid','checked_in') ORDER BY ticket_id", $p . 'wpcap_tickets', (int) $booking->order_id, (int) $resource->ticket_type_id ) ) );
		$free = array_values( array_diff( $candidates, $used ) );
		if ( count( $free ) < (int) $booking->quantity ) { return; }
		$ids = array_slice( $free, 0, (int) $booking->quantity );
		$order = Orders::get( (int) $booking->order_id );
		// After a dearer rebooking the first payment (origin_minor, other order) is part of the booking value.
		$amount = $order ? Refunds::gross_for_tickets( $order, $ids ) + (int) $booking->origin_minor : (int) $booking->amount_minor;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET ticket_ids=%s,amount_minor=%d,currency=%s,updated_at=%s WHERE booking_id=%d AND ticket_ids=''", $p . 'wpcap_service_bookings', wp_json_encode( $ids ), $amount, $order ? (string) $order->currency : (string) $booking->currency, current_time( 'mysql', true ), (int) $booking->booking_id ) );
	}

	/* ================================================================== Partner confirmation */

	/**
	 * Partner/admin decision on a booking waiting for confirmation. Decline cancels and refunds in full.
	 * Access is checked by the caller (ServiceResources::can_manage()).
	 *
	 * @param string $decision confirm|decline
	 * @return array|\WP_Error Booking state.
	 */
	public static function decide( $booking_id, $decision, $reason = '' ) {
		global $wpdb;
		$booking = self::get( $booking_id );
		if ( ! $booking ) { return self::not_found(); }
		if ( 'decline' === $decision ) {
			return self::cancel( (int) $booking->booking_id, array( 'actor' => 'partner', 'reason' => 'partner_declined', 'note' => $reason ) );
		}
		if ( 'confirm' !== $decision ) { return new \WP_Error( 'wpcap_booking_decision', __( 'Unbekannte Entscheidung.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( 'confirmed' === $booking->status ) { return self::state( $booking ); }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='confirmed',confirm_by=NULL,updated_at=%s WHERE booking_id=%d AND status='pending_confirmation'", $wpdb->prefix . 'wpcap_service_bookings', current_time( 'mysql', true ), (int) $booking->booking_id ) );
		if ( 1 !== (int) $changed ) { return self::state_error(); }
		$booking = self::get( $booking_id );
		Audit::record( 'service_booking_partner_confirmed', 'service_booking', (int) $booking->booking_id, array( 'user_id' => get_current_user_id() ) );
		self::mail( 'service_booking_confirmed', $booking, array() );
		return self::state( $booking );
	}

	/* ================================================================== Cancellation */

	/**
	 * Cancels a booking: capacity is released, paid bookings are refunded through the refund ledger
	 * (cash to the payment provider, voucher share back to the voucher), the buyer is notified and the
	 * slot's waitlist gets the place. Idempotent: a second call only resumes an unfinished refund.
	 *
	 * @param array $ctx actor (customer|admin|partner|system), reason (code), note, refund (bool, default true).
	 * @return array|\WP_Error Booking state incl. refund.
	 */
	public static function cancel( $booking_id, array $ctx = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$actor = (string) ( $ctx['actor'] ?? 'customer' );
		$actor = in_array( $actor, array( 'customer', 'admin', 'partner', 'system' ), true ) ? $actor : 'customer';
		$reason = sanitize_key( $ctx['reason'] ?? ( 'customer' === $actor ? 'customer' : 'admin' ) );
		$refund_wanted = ! array_key_exists( 'refund', $ctx ) || ! empty( $ctx['refund'] );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d FOR UPDATE', $p . 'wpcap_service_bookings', absint( $booking_id ) ) );
		if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		if ( ! $booking ) { $wpdb->query( 'ROLLBACK' ); return self::not_found(); }
		if ( in_array( $booking->status, array( 'cancelled', 'declined' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			// Repeat call: finish a refund that failed or was interrupted.
			if ( (int) $booking->order_id && '' !== (string) $booking->ticket_ids && ! in_array( $booking->cancel_reason, array( 'admin_no_refund', 'expired' ), true ) ) { self::refund( (int) $booking->booking_id ); }
			return self::state( self::get( $booking->booking_id ) );
		}
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		$resource = $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
		if ( ! in_array( $booking->status, self::CANCELLABLE, true ) || ! $slot ) { $wpdb->query( 'ROLLBACK' ); return self::state_error(); }
		if ( 'customer' === $actor ) {
			$rules = ServiceResources::rules( $resource );
			$deadline = strtotime( $slot->starts_at . ' UTC' ) - $rules['cancel_deadline_minutes'] * MINUTE_IN_SECONDS;
			if ( time() > $deadline ) {
				$wpdb->query( 'ROLLBACK' );
				/* translators: %s: deadline */
				return new \WP_Error( 'wpcap_booking_cancel_deadline', sprintf( __( 'Die Stornierungsfrist ist abgelaufen (bis %s).', 'pass-captain' ), self::local_time( $deadline, $resource ) ), array( 'status' => 409 ) );
			}
		} elseif ( strtotime( $slot->ends_at . ' UTC' ) < time() && 'system' !== $actor ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_booking_past', __( 'Vergangene Termine können nicht storniert werden; bitte als „nicht erschienen“ markieren oder eine Erstattung in der Bestellung anlegen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$was_paid = in_array( $booking->status, array( 'pending_confirmation', 'confirmed' ), true );
		$declined = in_array( $reason, array( 'partner_declined', 'confirmation_timeout' ), true );
		$stored_reason = ( ! $refund_wanted && in_array( $actor, array( 'admin', 'partner' ), true ) ) ? 'admin_no_refund' : $reason;
		$updated = $wpdb->update( "{$p}wpcap_service_bookings", array( 'status' => $declined ? 'declined' : 'cancelled', 'cancel_reason' => substr( $stored_reason, 0, 64 ), 'confirm_by' => null, 'expires_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'booking_id' => (int) $booking->booking_id ) );
		if ( 1 !== (int) $updated || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		Audit::record( $declined ? 'service_booking_declined' : 'service_booking_cancelled', 'service_booking', (int) $booking->booking_id, array( 'actor' => $actor, 'reason' => $stored_reason, 'slot_id' => (int) $booking->slot_id, 'order_id' => (int) $booking->order_id, 'quantity' => (int) $booking->quantity, 'user_id' => get_current_user_id() ) );
		$refund = null;
		if ( $was_paid && (int) $booking->order_id ) {
			self::fill_tickets( (int) $booking->booking_id );
			if ( $refund_wanted ) {
				$refund = self::refund( (int) $booking->booking_id, $actor );
			} else {
				// Cancelled without refund (e.g. forfeited): the booking's tickets must not admit anybody.
				$revoked = Orders::revoke_ids( array_map( 'absint', (array) json_decode( (string) self::get( $booking->booking_id )->ticket_ids, true ) ) );
				Orders::fire_revoked( $revoked, 'service_booking_' . (int) $booking->booking_id );
			}
		}
		if ( (int) $booking->pass_use_id ) { Memberships::release_use( (int) $booking->pass_use_id ); }
		// A pending dearer rebooking ends with the booking; a cancelled supplement takes its unpaid order along.
		self::drop_supplements( (int) $booking->booking_id, 'original_cancelled' );
		if ( (int) $booking->supplement_for && (int) $booking->order_id && 'pending' === (string) ( Orders::get( (int) $booking->order_id )->payment_status ?? '' ) ) {
			Orders::cancel_order( (int) $booking->order_id, 'service_supplement_cancelled' );
		}
		$fresh = self::get( $booking->booking_id );
		$note = sanitize_text_field( (string) ( $ctx['note'] ?? '' ) );
		self::mail( $declined ? 'service_booking_declined' : 'service_booking_cancelled', $fresh, array( 'reason' => '' !== $note ? $note : self::reason_label( $stored_reason ), 'refund' => $refund ) );
		self::slot_freed( (int) $booking->slot_id );
		return self::state( $fresh ) + ( is_wp_error( $refund ) ? array( 'refund_error' => $refund->get_error_code() ) : array() );
	}

	/**
	 * Refunds the booking's tickets (idempotent per booking). A failure leaves a retry job
	 * (service_booking_refund) that resumes the same refund row; it never pays out twice.
	 *
	 * @return array|\WP_Error|null Refund row, error, or null when there is nothing to refund.
	 */
	public static function refund( $booking_id, $actor = 'system' ) {
		global $wpdb;
		$booking = self::get( $booking_id );
		if ( ! $booking || ! (int) $booking->order_id ) { return null; }
		$ticket_ids = array_map( 'absint', (array) json_decode( (string) $booking->ticket_ids, true ) );
		if ( ! $ticket_ids ) { return null; }
		$result = Refunder::refund( (int) $booking->order_id, $ticket_ids, null, sprintf( /* translators: %d: booking id */ __( 'Servicebuchung #%d storniert', 'pass-captain' ), (int) $booking->booking_id ), 'service-booking-' . (int) $booking->booking_id, $actor );
		$row = is_array( $result ) && ! empty( $result['refund_id'] ) ? $result : Refunder::find( (int) $booking->order_id, 'service-booking-' . (int) $booking->booking_id );
		if ( $row && ! (int) $booking->refund_id ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'refund_id' => (int) $row['refund_id'] ), array( 'booking_id' => (int) $booking->booking_id ) );
		}
		$origin = null;
		if ( (int) $booking->origin_order_id && (int) $booking->origin_minor ) {
			// Dearer rebooking: the first payment (its tickets were replaced) is refunded as an amount from the original order.
			$origin = Refunder::refund( (int) $booking->origin_order_id, array(), (int) $booking->origin_minor, sprintf( /* translators: %d: booking id */ __( 'Servicebuchung #%d storniert', 'pass-captain' ), (int) $booking->booking_id ), 'service-booking-' . (int) $booking->booking_id . '-origin', $actor );
		}
		if ( is_wp_error( $result ) || ! Refunder::settled( $result ) || ( null !== $origin && ( is_wp_error( $origin ) || ! Refunder::settled( $origin ) ) ) ) {
			$failed = ( is_wp_error( $result ) || ! Refunder::settled( $result ) ) ? $result : $origin;
			Audit::record( 'service_booking_refund_failed', 'service_booking', (int) $booking->booking_id, array( 'error' => is_wp_error( $failed ) ? $failed->get_error_code() : (string) ( $failed['status'] ?? '' ) ) );
			Jobs::enqueue( 'service-booking-refund-' . (int) $booking->booking_id, self::JOB_REFUND, array( 'booking_id' => (int) $booking->booking_id ), gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ) );
		}
		return $result;
	}

	/* ================================================================== Rebooking */

	/**
	 * Moves a booking to another slot atomically (both slots locked; the new slot is checked for
	 * capacity, collisions and – for buyers – the booking rules; the old place is released in the same
	 * transaction). Same price is always allowed; a different price follows the resource's
	 * price_policy (same_price: refused; refund_difference: cheaper target → partial refund of the
	 * difference, dearer target → supplementary order for the difference; the booking moves when it is
	 * paid, see rebook_with_supplement()/complete_supplement()). Idempotent per key.
	 *
	 * @param array $ctx actor (customer|admin|partner), idempotency_key, provider (gateway for a supplement).
	 * @return array|\WP_Error Booking state incl. refund (price difference).
	 */
	public static function rebook( $booking_id, $new_slot_id, array $ctx = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$actor = (string) ( $ctx['actor'] ?? 'customer' );
		$actor = in_array( $actor, array( 'customer', 'admin', 'partner' ), true ) ? $actor : 'customer';
		$key = sanitize_text_field( (string) ( $ctx['idempotency_key'] ?? '' ) );
		$key_hash = '' !== $key ? hash( 'sha256', 'rebook|' . absint( $booking_id ) . '|' . $key ) : '';
		$booking = self::get( $booking_id );
		$new_slot_id = absint( $new_slot_id );
		if ( ! $booking ) { return self::not_found(); }
		if ( '' !== $key_hash && hash_equals( (string) $booking->last_action_key, $key_hash ) ) { return self::state( $booking ) + array( 'reused' => true ); }
		$old_slot = ServiceResources::slot( (int) $booking->slot_id );
		$new_slot = ServiceResources::slot( $new_slot_id );
		if ( ! $old_slot || ! $new_slot ) { return self::unavailable(); }
		if ( (int) $old_slot->slot_id === $new_slot_id ) { return new \WP_Error( 'wpcap_rebook_same', __( 'Die Buchung ist bereits in diesem Zeitfenster.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$old_resource = ServiceResources::resource( (int) $old_slot->resource_id );
		$new_resource = ServiceResources::resource( (int) $new_slot->resource_id );
		if ( (int) $old_resource->event_id !== (int) $new_resource->event_id ) { return new \WP_Error( 'wpcap_rebook_scope', __( 'Umbuchungen sind nur innerhalb desselben Angebots möglich.', 'pass-captain' ), array( 'status' => 409 ) ); }
		// Price difference per place.
		$old_unit = (int) $booking->quantity ? intdiv( (int) $booking->amount_minor, (int) $booking->quantity ) : 0;
		list( $new_unit ) = ServiceResources::price_of( $new_resource );
		if ( (int) $old_resource->ticket_type_id === (int) $new_resource->ticket_type_id ) { $new_unit = $old_unit; }
		$difference = ( $old_unit - $new_unit ) * (int) $booking->quantity;
		$policy = ServiceResources::rules( $old_resource )['price_policy'];
		if ( 0 !== $difference && ( 'same_price' === $policy || ( $difference < 0 && ! self::supplement_possible( $booking, $new_resource ) ) ) ) {
			return new \WP_Error( $difference < 0 ? 'wpcap_rebook_price_higher' : 'wpcap_rebook_price', $difference < 0 ? __( 'Das gewählte Zeitfenster ist teurer. Bitte storniere und buche neu.', 'pass-captain' ) : __( 'Umbuchungen sind nur auf Zeitfenster zum gleichen Preis möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$open_supplement = self::open_supplement( (int) $booking->booking_id );
		if ( $difference < 0 ) {
			// Dearer target (price_policy refund_difference): supplementary payment, the move follows the payment.
			return self::rebook_with_supplement( $booking, $old_slot, $new_slot, $old_resource, $new_resource, -$difference, $actor, $ctx );
		}
		if ( $open_supplement ) { return self::supplement_open_error(); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		// Lock order: resources by id, slots by id, then the booking.
		$resource_ids = array_unique( array( (int) $old_resource->resource_id, (int) $new_resource->resource_id ) );
		sort( $resource_ids, SORT_NUMERIC );
		$locked = array();
		foreach ( $resource_ids as $rid ) { $locked[ $rid ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d FOR UPDATE', $p . 'wpcap_resources', $rid ) ); }
		$slot_ids = array( (int) $old_slot->slot_id, $new_slot_id );
		sort( $slot_ids, SORT_NUMERIC );
		$slots = array();
		foreach ( $slot_ids as $sid ) { $slots[ $sid ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d FOR UPDATE', $p . 'wpcap_service_slots', $sid ) ); }
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d FOR UPDATE', $p . 'wpcap_service_bookings', (int) $booking->booking_id ) );
		if ( '' !== (string) $wpdb->last_error || ! $booking || in_array( null, $slots, true ) || in_array( null, $locked, true ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		if ( '' !== $key_hash && hash_equals( (string) $booking->last_action_key, $key_hash ) ) { $wpdb->query( 'COMMIT' ); return self::state( $booking ) + array( 'reused' => true ); }
		if ( ! in_array( $booking->status, self::REBOOKABLE, true ) || (int) $booking->slot_id !== (int) $old_slot->slot_id ) { $wpdb->query( 'ROLLBACK' ); return self::state_error(); }
		$target = $slots[ $new_slot_id ];
		$resource = $locked[ (int) $new_resource->resource_id ];
		$now = current_time( 'mysql', true );
		if ( 'open' !== $target->status || $target->starts_at <= $now || 'active' !== $resource->status ) { $wpdb->query( 'ROLLBACK' ); return self::unavailable(); }
		if ( 'customer' === $actor ) {
			$rules = ServiceResources::rules( $locked[ (int) $old_resource->resource_id ] );
			$deadline = strtotime( $slots[ (int) $old_slot->slot_id ]->starts_at . ' UTC' ) - $rules['rebook_deadline_minutes'] * MINUTE_IN_SECONDS;
			if ( time() > $deadline ) {
				$wpdb->query( 'ROLLBACK' );
				/* translators: %s: deadline */
				return new \WP_Error( 'wpcap_rebook_deadline', sprintf( __( 'Die Umbuchungsfrist ist abgelaufen (bis %s).', 'pass-captain' ), self::local_time( $deadline, $old_resource ) ), array( 'status' => 409 ) );
			}
			$rule_error = self::booking_rule_error( $resource, $target, ServiceResources::rules( $resource ), $booking->customer_email, (int) $booking->quantity, (int) $booking->booking_id );
			if ( $rule_error ) { $wpdb->query( 'ROLLBACK' ); return $rule_error; }
		}
		$capacity_error = self::capacity_error( $resource, $target, (int) $booking->quantity, (int) $booking->booking_id );
		if ( $capacity_error ) { $wpdb->query( 'ROLLBACK' ); return $capacity_error; }
		$data = array( 'slot_id' => $new_slot_id, 'rebook_count' => (int) $booking->rebook_count + 1, 'last_action_key' => $key_hash, 'updated_at' => $now );
		// After a dearer rebooking the booking value is split over two orders: the supplementary order
		// (order_id, amount_minor − origin_minor) and the first payment (origin_order_id, origin_minor).
		// A cheaper move refunds the supplementary part first, the rest from the original order (PAY-9).
		$origin_part = 0;
		if ( $difference > 0 ) {
			$data['amount_minor'] = max( 0, (int) $booking->amount_minor - $difference );
			if ( (int) $booking->origin_order_id && (int) $booking->origin_minor ) {
				$supplement_part = max( 0, (int) $booking->amount_minor - (int) $booking->origin_minor );
				$origin_part = min( (int) $booking->origin_minor, max( 0, $difference - $supplement_part ) );
				$data['origin_minor'] = (int) $booking->origin_minor - $origin_part;
			}
		}
		$updated = $wpdb->update( "{$p}wpcap_service_bookings", $data, array( 'booking_id' => (int) $booking->booking_id, 'slot_id' => (int) $old_slot->slot_id ) );
		if ( 1 !== (int) $updated || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		$count = (int) $booking->rebook_count + 1;
		Audit::record( 'service_booking_rebooked', 'service_booking', (int) $booking->booking_id, array( 'actor' => $actor, 'from_slot' => (int) $old_slot->slot_id, 'to_slot' => $new_slot_id, 'difference_minor' => $difference, 'user_id' => get_current_user_id() ) );
		$refund = null;
		if ( $difference - $origin_part > 0 && (int) $booking->order_id ) {
			$refund = Refunder::refund( (int) $booking->order_id, array(), $difference - $origin_part, sprintf( /* translators: %d: booking id */ __( 'Preisdifferenz Umbuchung #%d', 'pass-captain' ), (int) $booking->booking_id ), 'service-rebook-' . (int) $booking->booking_id . '-' . $count, $actor );
			if ( is_wp_error( $refund ) || ! Refunder::settled( $refund ) ) {
				Audit::record( 'service_booking_refund_failed', 'service_booking', (int) $booking->booking_id, array( 'error' => is_wp_error( $refund ) ? $refund->get_error_code() : (string) ( $refund['status'] ?? '' ), 'kind' => 'rebook_difference' ) );
			}
		}
		if ( $origin_part > 0 ) {
			$origin_refund = Refunder::refund( (int) $booking->origin_order_id, array(), $origin_part, sprintf( /* translators: %d: booking id */ __( 'Preisdifferenz Umbuchung #%d', 'pass-captain' ), (int) $booking->booking_id ), 'service-rebook-' . (int) $booking->booking_id . '-' . $count . '-origin', $actor );
			if ( is_wp_error( $origin_refund ) || ! Refunder::settled( $origin_refund ) ) {
				Audit::record( 'service_booking_refund_failed', 'service_booking', (int) $booking->booking_id, array( 'error' => is_wp_error( $origin_refund ) ? $origin_refund->get_error_code() : (string) ( $origin_refund['status'] ?? '' ), 'kind' => 'rebook_difference_origin' ) );
			}
			$refund = null === $refund ? $origin_refund : $refund;
		}
		$fresh = self::get( $booking->booking_id );
		self::mail( 'service_booking_rebooked', $fresh, array( 'old_slot' => $old_slot, 'refund' => $refund ) );
		self::slot_freed( (int) $old_slot->slot_id );
		return self::state( $fresh ) + array( 'difference_minor' => $difference ) + ( is_wp_error( $refund ) ? array( 'refund_error' => $refund->get_error_code() ) : array() );
	}

	/* ================================================================== Supplementary payment (dearer rebooking) */

	/** Whether a dearer move of the booking can be settled by a supplementary order. */
	private static function supplement_possible( $booking, $new_resource ) {
		return (int) $booking->order_id && ! (int) $booking->pass_use_id && ! (int) $booking->supplement_for && ! (int) $booking->origin_order_id
			&& $new_resource && (int) $new_resource->ticket_type_id && '' !== (string) $booking->currency;
	}

	/** Open (held, unexpired) supplement row of a booking, or null. */
	public static function open_supplement( $booking_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE supplement_for=%d AND status='held' AND expires_at>%s ORDER BY booking_id DESC LIMIT 1", $wpdb->prefix . 'wpcap_service_bookings', absint( $booking_id ), current_time( 'mysql', true ) ) );
	}

	private static function supplement_open_error() {
		return new \WP_Error( 'wpcap_rebook_supplement_open', __( 'Für diese Buchung ist bereits eine Umbuchung mit offener Zuzahlung vorgemerkt. Bitte bezahle sie oder warte, bis sie abläuft.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	/**
	 * Dearer target: a supplementary order for the price difference (target resource's ticket type,
	 * trusted line total = difference) is created through Orders::create(), the target place is held
	 * by a supplement row (status held, supplement_for = booking) until the order's payment hold ends,
	 * and the payment is started with the chosen (or first enabled) gateway. The original booking keeps
	 * its place; complete_supplement() moves it atomically when the supplement is paid. An unpaid
	 * supplement expires with the order hold and only releases the target place.
	 *
	 * @param array $ctx provider (payment gateway id).
	 * @return array|\WP_Error Booking state + supplement {booking_id, order_id, public_id, amount_minor, currency, slot_id, expires_at, payment}.
	 */
	private static function rebook_with_supplement( $booking, $old_slot, $new_slot, $old_resource, $new_resource, $supplement, $actor, array $ctx ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$open = self::open_supplement( (int) $booking->booking_id );
		if ( $open ) {
			return (int) $open->slot_id === (int) $new_slot->slot_id ? self::supplement_result( $booking, $open, false ) : self::supplement_open_error();
		}
		if ( 'customer' === $actor ) {
			$deadline = strtotime( $old_slot->starts_at . ' UTC' ) - ServiceResources::rules( $old_resource )['rebook_deadline_minutes'] * MINUTE_IN_SECONDS;
			/* translators: %s: deadline */
			if ( time() > $deadline ) { return new \WP_Error( 'wpcap_rebook_deadline', sprintf( __( 'Die Umbuchungsfrist ist abgelaufen (bis %s).', 'pass-captain' ), self::local_time( $deadline, $old_resource ) ), array( 'status' => 409 ) ); }
		}
		if ( ! in_array( $booking->status, self::REBOOKABLE, true ) ) { return self::state_error(); }
		$gateway = self::supplement_gateway( (string) ( $ctx['provider'] ?? '' ), (string) $booking->currency );
		if ( ! $gateway ) { return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$quantity = (int) $booking->quantity;
		$type_id = (int) $new_resource->ticket_type_id;
		$attempt = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE supplement_for=%d', $p . 'wpcap_service_bookings', (int) $booking->booking_id ) );
		$hold = 'manual' === $gateway->id() ? max( 1, (int) Settings::get( 'manual_hold_hours' ) ) * 60 : max( 30, (int) Settings::get( 'hold_minutes' ) );
		$order = Orders::create(
			array( 'email' => (string) $booking->customer_email, 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $quantity ) ), 'idempotency_key' => 'svcsupp-' . (int) $booking->booking_id . '-' . (int) $new_slot->slot_id . '-' . (int) $booking->rebook_count . '-' . $attempt ),
			array( 'external' => true, 'unit_prices' => array( $type_id => intdiv( $supplement, max( 1, $quantity ) ) ), 'line_totals' => array( $type_id => $supplement ), 'currency' => (string) $booking->currency, 'payment_provider' => $gateway->id(), 'source' => 'service_supplement', 'key_namespace' => 'service_supplement', 'skip_rate_limit' => true, 'skip_buyer_limits' => true, 'hold_minutes' => $hold )
		);
		if ( is_wp_error( $order ) ) { return new \WP_Error( 'wpcap_rebook_supplement_order', $order->get_error_message(), array( 'status' => 409 ) ); }
		$order_id = (int) $order['order_id'];
		if ( 'pending' !== ( $order['payment_status'] ?? '' ) ) { return self::busy(); } // A reused order that is no longer open; the next attempt uses a new key.
		$refuse = static function ( $error ) use ( $order_id ) {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
			Orders::cancel_order( $order_id, 'service_supplement_refused' );
			return $error;
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $refuse( self::busy() ); }
		// Lock order: resources by id, slots by id, then the booking (as rebook()).
		$resource_ids = array_unique( array( (int) $old_resource->resource_id, (int) $new_resource->resource_id ) );
		sort( $resource_ids, SORT_NUMERIC );
		$locked = array();
		foreach ( $resource_ids as $rid ) { $locked[ $rid ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d FOR UPDATE', $p . 'wpcap_resources', $rid ) ); }
		$slot_ids = array( (int) $old_slot->slot_id, (int) $new_slot->slot_id );
		sort( $slot_ids, SORT_NUMERIC );
		$slots = array();
		foreach ( $slot_ids as $sid ) { $slots[ $sid ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d FOR UPDATE', $p . 'wpcap_service_slots', $sid ) ); }
		$locked_booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d FOR UPDATE', $p . 'wpcap_service_bookings', (int) $booking->booking_id ) );
		if ( '' !== (string) $wpdb->last_error || ! $locked_booking || in_array( null, $slots, true ) || in_array( null, $locked, true ) ) { return $refuse( self::busy() ); }
		$now = current_time( 'mysql', true );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE supplement_for=%d AND status='held' AND expires_at>%s ORDER BY booking_id DESC LIMIT 1", $p . 'wpcap_service_bookings', (int) $booking->booking_id, $now ) );
		if ( $existing ) {
			// A parallel request created the supplement first: same target → same result, else refused.
			$wpdb->query( 'COMMIT' );
			if ( (int) $existing->order_id !== $order_id ) { Orders::cancel_order( $order_id, 'service_supplement_refused' ); }
			return (int) $existing->slot_id === (int) $new_slot->slot_id ? self::supplement_result( $locked_booking, $existing, false ) : self::supplement_open_error();
		}
		if ( ! in_array( $locked_booking->status, self::REBOOKABLE, true ) || (int) $locked_booking->slot_id !== (int) $old_slot->slot_id ) { return $refuse( self::state_error() ); }
		$target = $slots[ (int) $new_slot->slot_id ];
		$resource = $locked[ (int) $new_resource->resource_id ];
		if ( 'open' !== $target->status || $target->starts_at <= $now || 'active' !== $resource->status ) { return $refuse( self::unavailable() ); }
		if ( 'customer' === $actor ) {
			$rule_error = self::booking_rule_error( $resource, $target, ServiceResources::rules( $resource ), $locked_booking->customer_email, $quantity, (int) $locked_booking->booking_id );
			if ( $rule_error ) { return $refuse( $rule_error ); }
		}
		$capacity_error = self::capacity_error( $resource, $target, $quantity, (int) $locked_booking->booking_id );
		if ( $capacity_error ) { return $refuse( $capacity_error ); }
		$expires = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM %i WHERE order_id=%d AND status='active'", $p . 'wpcap_reservations', $order_id ) );
		if ( '' === $expires ) { $expires = gmdate( 'Y-m-d H:i:s', time() + $hold * MINUTE_IN_SECONDS ); }
		$inserted = $wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => (int) $target->slot_id, 'order_id' => $order_id, 'customer_email' => (string) $locked_booking->customer_email, 'idempotency_key' => hash( 'sha256', 'svcsupp|' . $order_id ), 'quantity' => $quantity, 'expires_at' => $expires, 'status' => 'held', 'amount_minor' => $supplement, 'currency' => (string) $locked_booking->currency, 'supplement_for' => (int) $locked_booking->booking_id, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ) );
		$supplement_id = (int) $wpdb->insert_id;
		if ( false === $inserted || ! $supplement_id || false === $wpdb->query( 'COMMIT' ) ) { return $refuse( self::busy() ); }
		Audit::record( 'service_booking_supplement_created', 'service_booking', (int) $locked_booking->booking_id, array( 'actor' => $actor, 'supplement_id' => $supplement_id, 'order_id' => $order_id, 'from_slot' => (int) $old_slot->slot_id, 'to_slot' => (int) $target->slot_id, 'amount_minor' => $supplement, 'expires_at' => $expires, 'user_id' => get_current_user_id() ) );
		$result = self::supplement_result( $locked_booking, self::get( $supplement_id ), true );
		if ( isset( $result['payment_error'] ) ) {
			// The payment could not be started: the order is cancelled, which releases the hold (on_order_cancelled).
			Orders::cancel_order( $order_id, 'payment_start_failed' );
			return new \WP_Error( 'wpcap_rebook_supplement_payment', (string) $result['payment_error'], array( 'status' => 502 ) );
		}
		// Portal access to the supplementary order (payment details; the new tickets after payment).
		Orders::rotate_access_link( $order_id );
		return $result;
	}

	/** Payment gateway for a supplement: the requested one when enabled, else the first enabled for the currency. */
	private static function supplement_gateway( $provider, $currency ) {
		$enabled = Payments\Registry::enabled( $currency );
		$provider = sanitize_key( $provider );
		if ( '' !== $provider ) { return $enabled[ $provider ] ?? null; }
		return $enabled ? reset( $enabled ) : null;
	}

	/** Buyer-facing result for an open supplement; (re)starts its payment. payment_error on failure. */
	private static function supplement_result( $booking, $supplement, $fresh ) {
		$order = Orders::get( (int) $supplement->order_id );
		$state = self::state( $booking ) + array(
			'supplement' => array(
				'booking_id' => (int) $supplement->booking_id,
				'order_id' => (int) $supplement->order_id,
				'public_id' => $order ? (string) $order->public_id : '',
				'amount_minor' => (int) $supplement->amount_minor,
				'currency' => (string) $supplement->currency,
				'slot_id' => (int) $supplement->slot_id,
				'expires_at' => (string) $supplement->expires_at,
			),
			'reused' => ! $fresh,
		);
		$payment = self::supplement_payment( $supplement );
		if ( is_wp_error( $payment ) ) {
			$state['payment_error'] = $payment->get_error_message();
		} else {
			$state['supplement']['payment'] = $payment;
		}
		return $state;
	}

	/**
	 * Starts the provider payment of an open supplement's order (hosted payment page or payment instructions).
	 *
	 * @return array|\WP_Error provider, status, redirect_url, instructions (bank_transfer when applicable).
	 */
	public static function supplement_payment( $supplement ) {
		$order = $supplement ? Orders::get( (int) $supplement->order_id ) : null;
		if ( ! $order || ! (int) $supplement->supplement_for || 'held' !== $supplement->status || 'pending' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_rebook_supplement_state', __( 'Für diese Umbuchung ist keine Zuzahlung (mehr) offen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$gateway = Payments\Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || ! $gateway->is_available( (string) $order->currency ) ) { return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$items = array_map( static function ( $row ) { return (array) $row; }, Orders::items_for_order( (int) $order->order_id ) );
		$payment = $gateway->create_payment( (array) $order, $items );
		if ( is_wp_error( $payment ) ) { return $payment; }
		if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( (int) $order->order_id, $payment['provider_reference'] ); }
		$out = array_intersect_key( (array) $payment, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
		if ( Payments\BankTransfer::applies( (array) $order ) ) { $out['bank_transfer'] = Payments\BankTransfer::details( (array) $order ); }
		return $out;
	}

	/**
	 * The supplementary order is paid: moves the original booking into the supplement's slot in one
	 * transaction (both resources, both slots, both booking rows locked). The booking then belongs to
	 * the supplementary order, whose tickets (target resource's type) replace the old ones (revoked);
	 * origin_order_id / origin_minor keep the first payment for a later cancellation refund. A lapsed
	 * supplement moves only while the place is still free; otherwise – and when the original booking is
	 * no longer active – the supplementary order is refunded in full and the booking stays. Idempotent.
	 *
	 * @return array|\WP_Error State of the original booking.
	 */
	public static function complete_supplement( $supplement_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$supplement = self::get( $supplement_id );
		if ( ! $supplement || ! (int) $supplement->supplement_for ) { return self::not_found(); }
		$original = self::get( (int) $supplement->supplement_for );
		if ( ! $original ) { return self::not_found(); }
		if ( 'merged' === $supplement->status ) { return self::state( $original ); }
		if ( in_array( $supplement->status, array( 'cancelled', 'declined' ), true ) ) {
			self::refund_supplement( $supplement );
			return self::state( $original );
		}
		$old_slot_row = ServiceResources::slot( (int) $original->slot_id );
		$new_slot_row = ServiceResources::slot( (int) $supplement->slot_id );
		if ( ! $old_slot_row || ! $new_slot_row ) { return self::unavailable(); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$resource_ids = array_unique( array( (int) $old_slot_row->resource_id, (int) $new_slot_row->resource_id ) );
		sort( $resource_ids, SORT_NUMERIC );
		$locked = array();
		foreach ( $resource_ids as $rid ) { $locked[ $rid ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d FOR UPDATE', $p . 'wpcap_resources', $rid ) ); }
		$slot_ids = array_unique( array( (int) $old_slot_row->slot_id, (int) $new_slot_row->slot_id ) );
		sort( $slot_ids, SORT_NUMERIC );
		$slots = array();
		foreach ( $slot_ids as $sid ) { $slots[ $sid ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d FOR UPDATE', $p . 'wpcap_service_slots', $sid ) ); }
		// Booking rows by id (an original is always older than its supplement).
		$original = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d FOR UPDATE', $p . 'wpcap_service_bookings', (int) $original->booking_id ) );
		$supplement = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d FOR UPDATE', $p . 'wpcap_service_bookings', (int) $supplement->booking_id ) );
		if ( '' !== (string) $wpdb->last_error || ! $original || ! $supplement || in_array( null, $slots, true ) || in_array( null, $locked, true ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		if ( ! in_array( $supplement->status, array( 'held', 'expired' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return self::complete_supplement( (int) $supplement->booking_id );
		}
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT payment_status FROM %i WHERE order_id=%d', $p . 'wpcap_orders', (int) $supplement->order_id ) );
		if ( ! $order || 'paid' !== $order->payment_status ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_booking_payment_required', __( 'Diese Buchung wird mit der Zahlung der Bestellung bestätigt.', 'pass-captain' ), array( 'status' => 402 ) );
		}
		$now = current_time( 'mysql', true );
		$target = $slots[ (int) $supplement->slot_id ];
		$resource = $locked[ (int) $new_slot_row->resource_id ];
		$failure = '';
		if ( ! in_array( $original->status, self::REBOOKABLE, true ) || (int) $original->slot_id !== (int) $old_slot_row->slot_id ) {
			$failure = 'original_inactive';
		} elseif ( 'open' !== $target->status || $target->starts_at <= $now || 'active' !== $resource->status ) {
			$failure = 'slot_unavailable';
		} elseif ( ( 'expired' === $supplement->status || ( $supplement->expires_at && $supplement->expires_at <= $now ) ) && null !== self::capacity_error( $resource, $target, (int) $supplement->quantity, (int) $supplement->booking_id ) ) {
			$failure = 'capacity_lost';
		}
		if ( '' !== $failure ) {
			$updated = $wpdb->update( "{$p}wpcap_service_bookings", array( 'status' => 'cancelled', 'cancel_reason' => 'supplement_' . $failure, 'expires_at' => null, 'updated_at' => $now ), array( 'booking_id' => (int) $supplement->booking_id ) );
			if ( 1 !== (int) $updated || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
			Audit::record( 'service_booking_supplement_failed', 'service_booking', (int) $original->booking_id, array( 'supplement_id' => (int) $supplement->booking_id, 'order_id' => (int) $supplement->order_id, 'reason' => $failure ) );
			$refund = self::refund_supplement( self::get( (int) $supplement->booking_id ) );
			self::mail( 'service_booking_cancelled', self::get( (int) $supplement->booking_id ), array( 'reason' => __( 'Die Umbuchung war bei Eingang der Zuzahlung nicht mehr möglich. Deine bisherige Buchung bleibt unverändert; die Zuzahlung wird erstattet.', 'pass-captain' ), 'refund' => $refund ) );
			self::slot_freed( (int) $supplement->slot_id );
			return self::state( self::get( (int) $original->booking_id ) ) + array( 'supplement_failed' => $failure );
		}
		$moved = $wpdb->update( "{$p}wpcap_service_bookings", array( 'slot_id' => (int) $target->slot_id, 'order_id' => (int) $supplement->order_id, 'origin_order_id' => (int) $original->order_id, 'origin_minor' => (int) $original->amount_minor, 'amount_minor' => (int) $original->amount_minor + (int) $supplement->amount_minor, 'ticket_ids' => '', 'rebook_count' => (int) $original->rebook_count + 1, 'updated_at' => $now ), array( 'booking_id' => (int) $original->booking_id, 'slot_id' => (int) $old_slot_row->slot_id ) );
		$merged = 1 === (int) $moved ? $wpdb->update( "{$p}wpcap_service_bookings", array( 'status' => 'merged', 'expires_at' => null, 'updated_at' => $now ), array( 'booking_id' => (int) $supplement->booking_id ) ) : 0;
		if ( 1 !== (int) $merged || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		// The old tickets are replaced by the supplementary order's tickets (target resource's ticket type).
		$revoked = Orders::revoke_ids( array_map( 'absint', (array) json_decode( (string) $original->ticket_ids, true ) ) );
		Orders::fire_revoked( $revoked, 'service_rebook_' . (int) $original->booking_id );
		self::fill_tickets( (int) $original->booking_id );
		Audit::record( 'service_booking_rebooked', 'service_booking', (int) $original->booking_id, array( 'actor' => 'system', 'from_slot' => (int) $old_slot_row->slot_id, 'to_slot' => (int) $target->slot_id, 'difference_minor' => -(int) $supplement->amount_minor, 'supplement_id' => (int) $supplement->booking_id, 'supplement_order_id' => (int) $supplement->order_id, 'origin_order_id' => (int) $original->order_id ) );
		$fresh = self::get( (int) $original->booking_id );
		self::mail( 'service_booking_rebooked', $fresh, array( 'old_slot' => $old_slot_row, 'refund' => null ) );
		self::slot_freed( (int) $old_slot_row->slot_id );
		return self::state( $fresh ) + array( 'difference_minor' => -(int) $supplement->amount_minor );
	}

	/** Full refund of a paid supplementary order whose move did not happen (idempotent per supplement). */
	private static function refund_supplement( $supplement ) {
		global $wpdb;
		if ( ! $supplement || ! (int) $supplement->order_id ) { return null; }
		$order = Orders::get( (int) $supplement->order_id );
		if ( ! $order || 'paid' !== $order->payment_status ) { return null; }
		$key = 'service-supplement-' . (int) $supplement->booking_id;
		$tickets = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM %i WHERE order_id=%d AND refunded=0 AND status IN ('valid','checked_in')", $wpdb->prefix . 'wpcap_tickets', (int) $order->order_id ) ) );
		/* translators: %d: booking id */
		$reason = sprintf( __( 'Zuzahlung Umbuchung #%d nicht verrechnet', 'pass-captain' ), (int) $supplement->supplement_for );
		$result = $tickets || Refunder::find( (int) $order->order_id, $key ) ? Refunder::refund( (int) $order->order_id, $tickets, null, $reason, $key ) : Refunder::refund( (int) $order->order_id, array(), (int) $order->total_minor, $reason, $key );
		$row = is_array( $result ) && ! empty( $result['refund_id'] ) ? $result : Refunder::find( (int) $order->order_id, $key );
		if ( $row && ! (int) $supplement->refund_id ) { $wpdb->update( $wpdb->prefix . 'wpcap_service_bookings', array( 'refund_id' => (int) $row['refund_id'] ), array( 'booking_id' => (int) $supplement->booking_id ) ); }
		if ( is_wp_error( $result ) || ! Refunder::settled( $result ) ) {
			Audit::record( 'service_booking_refund_failed', 'service_booking', (int) $supplement->booking_id, array( 'error' => is_wp_error( $result ) ? $result->get_error_code() : (string) ( $result['status'] ?? '' ), 'kind' => 'supplement' ) );
			Jobs::enqueue( 'service-booking-refund-' . (int) $supplement->booking_id, self::JOB_REFUND, array( 'booking_id' => (int) $supplement->booking_id ), gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ) );
		}
		return $result;
	}

	/** Cancels the open supplements of a booking and their unpaid orders (e.g. the booking itself was cancelled). */
	private static function drop_supplements( $booking_id, $reason ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT booking_id,order_id,slot_id FROM %i WHERE supplement_for=%d AND status='held'", $wpdb->prefix . 'wpcap_service_bookings', absint( $booking_id ) ) );
		foreach ( (array) $rows as $row ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='cancelled',cancel_reason=%s,expires_at=NULL,updated_at=%s WHERE booking_id=%d AND status='held'", $wpdb->prefix . 'wpcap_service_bookings', substr( 'supplement_' . sanitize_key( $reason ), 0, 64 ), current_time( 'mysql', true ), (int) $row->booking_id ) );
			if ( 1 !== (int) $changed ) { continue; }
			$order = Orders::get( (int) $row->order_id );
			if ( $order && 'pending' === $order->payment_status ) { Orders::cancel_order( (int) $row->order_id, 'service_supplement_dropped' ); }
			Audit::record( 'service_booking_supplement_dropped', 'service_booking', absint( $booking_id ), array( 'supplement_id' => (int) $row->booking_id, 'reason' => sanitize_key( $reason ) ) );
			self::slot_freed( (int) $row->slot_id );
		}
	}

	/**
	 * Slots a booking may be moved to: open future slots of the same resource or of resources of the
	 * same event with the same price (cheaper or – with a supplementary payment – dearer with
	 * price_policy refund_difference) and free places. Dearer rows carry supplement_minor.
	 */
	public static function rebook_options( $booking, $limit = 50, $supplements = true ) {
		global $wpdb;
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		if ( ! $slot ) { return array(); }
		$resource = ServiceResources::resource( (int) $slot->resource_id );
		$policy = ServiceResources::rules( $resource )['price_policy'];
		$old_unit = (int) $booking->quantity ? intdiv( (int) $booking->amount_minor, (int) $booking->quantity ) : 0;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*,r.name AS resource_name,r.ticket_type_id,r.timezone,r.event_id FROM %i s INNER JOIN %i r ON r.resource_id=s.resource_id WHERE r.event_id=%d AND r.status='active' AND s.status='open' AND s.starts_at>%s AND s.slot_id<>%d ORDER BY s.starts_at LIMIT 300", $wpdb->prefix . 'wpcap_service_slots', $wpdb->prefix . 'wpcap_resources', (int) $resource->event_id, current_time( 'mysql', true ), (int) $slot->slot_id ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$target = ServiceResources::resource( (int) $row->resource_id );
			list( $unit ) = ServiceResources::price_of( $target );
			if ( (int) $target->ticket_type_id === (int) $resource->ticket_type_id ) { $unit = $old_unit; }
			if ( $unit !== $old_unit && 'same_price' === $policy ) { continue; }
			if ( $unit > $old_unit && ( ! $supplements || ! self::supplement_possible( $booking, $target ) ) ) { continue; }
			$row->supplement_minor = max( 0, ( $unit - $old_unit ) * (int) $booking->quantity );
			$free = (int) $row->capacity - ServiceResources::booked_quantity( (int) $row->slot_id ) - ServiceWaitlist::held_quantity( (int) $row->slot_id );
			if ( $free < (int) $booking->quantity ) { continue; }
			$row->free = $free;
			$row->unit_minor = $unit;
			$out[] = $row;
			if ( count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	/* ================================================================== Check-in and no-show */

	/** Marks a booking as checked in (staff, or a check-in of one of its tickets). */
	public static function check_in( $booking_id, $source = 'admin' ) {
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='checked_in',checked_in_at=%s,updated_at=%s WHERE booking_id=%d AND status IN ('confirmed','no_show')", $wpdb->prefix . 'wpcap_service_bookings', current_time( 'mysql', true ), current_time( 'mysql', true ), absint( $booking_id ) ) );
		if ( 1 !== (int) $changed ) { return self::state_error(); }
		Audit::record( 'service_booking_checked_in', 'service_booking', absint( $booking_id ), array( 'source' => sanitize_key( $source ) ) );
		return self::state( self::get( $booking_id ) );
	}

	/** Hook wpcap_ticket_checked_in: a booking linked to the ticket counts as checked in. */
	public static function on_ticket_checked_in( $ticket_id ) {
		global $wpdb;
		$ticket_id = absint( $ticket_id );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT booking_id,ticket_ids FROM %i WHERE order_id=(SELECT order_id FROM %i WHERE ticket_id=%d) AND status IN ('confirmed','no_show')", $wpdb->prefix . 'wpcap_service_bookings', $wpdb->prefix . 'wpcap_tickets', $ticket_id ) );
		foreach ( (array) $rows as $row ) {
			if ( in_array( $ticket_id, array_map( 'absint', (array) json_decode( (string) $row->ticket_ids, true ) ), true ) ) { self::check_in( (int) $row->booking_id, 'ticket' ); }
		}
	}

	/**
	 * Records a no-show (staff action or sweep after slot end + grace). With policy "forfeit" the
	 * booking keeps no refund claim and the configured fee is noted. Counts towards the block rule.
	 */
	public static function mark_no_show( $booking_id, $source = 'admin' ) {
		global $wpdb;
		$booking = self::get( $booking_id );
		if ( ! $booking ) { return self::not_found(); }
		$resource = self::resource_of( $booking );
		$rules = ServiceResources::rules( $resource );
		$fee = 'forfeit' === $rules['noshow_policy'] ? (int) $rules['noshow_fee_minor'] : 0;
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='no_show',noshow_at=%s,fee_minor=%d,updated_at=%s WHERE booking_id=%d AND status='confirmed'", $wpdb->prefix . 'wpcap_service_bookings', current_time( 'mysql', true ), $fee, current_time( 'mysql', true ), (int) $booking->booking_id ) );
		if ( 1 !== (int) $changed ) { return self::state_error(); }
		$booking = self::get( $booking_id );
		Audit::record( 'service_booking_no_show', 'service_booking', (int) $booking->booking_id, array( 'source' => sanitize_key( $source ), 'policy' => $rules['noshow_policy'], 'fee_minor' => $fee ) );
		self::mail( 'service_booking_no_show', $booking, array() );
		return self::state( $booking );
	}

	/**
	 * Periodic maintenance (job worker run): partner decisions past their deadline are declined and
	 * refunded, no-shows are recorded, waitlist offers expire and move on.
	 *
	 * @return array counts
	 */
	public static function sweep( $limit = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$out = array( 'confirmation_timeouts' => 0, 'no_shows' => 0 );
		foreach ( array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT booking_id FROM %i WHERE status='pending_confirmation' AND confirm_by<=%s ORDER BY confirm_by LIMIT %d", $p . 'wpcap_service_bookings', $now, absint( $limit ) ) ) ) as $id ) {
			$r = self::cancel( $id, array( 'actor' => 'system', 'reason' => 'confirmation_timeout' ) );
			if ( ! is_wp_error( $r ) ) { ++$out['confirmation_timeouts']; }
		}
		// Only resources with a no-show policy (rules JSON is written by ServiceResources::save_resource()),
		// slots ended within the longest possible grace period (7 days) plus a day.
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT b.booking_id,s.ends_at,r.rules FROM %i b INNER JOIN %i s ON s.slot_id=b.slot_id INNER JOIN %i r ON r.resource_id=s.resource_id WHERE b.status='confirmed' AND s.ends_at<=%s AND s.ends_at>=%s AND (r.rules LIKE %s OR r.rules LIKE %s) ORDER BY s.ends_at LIMIT %d", $p . 'wpcap_service_bookings', $p . 'wpcap_service_slots', $p . 'wpcap_resources', $now, gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ), '%"noshow_policy":"mark"%', '%"noshow_policy":"forfeit"%', absint( $limit ) ) );
		foreach ( (array) $candidates as $row ) {
			$rules = ServiceResources::sanitize_rules( (array) json_decode( (string) $row->rules, true ) );
			if ( 'none' === $rules['noshow_policy'] || strtotime( $row->ends_at . ' UTC' ) + $rules['noshow_grace_minutes'] * MINUTE_IN_SECONDS > time() ) { continue; }
			if ( ! is_wp_error( self::mark_no_show( (int) $row->booking_id, 'sweep' ) ) ) { ++$out['no_shows']; }
		}
		$out['service_waitlist'] = ServiceWaitlist::sweep();
		$out['event_waitlist'] = Waitlists::sweep();
		return $out;
	}

	/* ================================================================== Jobs */

	/** Filter wp_pass_captain_process_job_v1. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		$payload = (array) $payload;
		if ( self::JOB_REFUND === $type ) {
			$booking = self::get( absint( $payload['booking_id'] ?? 0 ) );
			if ( ! $booking ) { return true; }
			if ( (int) $booking->supplement_for ) {
				$result = self::refund_supplement( $booking );
				return null === $result || Refunder::settled( $result );
			}
			$result = self::refund( (int) $booking->booking_id );
			$origin_done = ! (int) $booking->origin_order_id || Refunder::settled( Refunder::find( (int) $booking->origin_order_id, 'service-booking-' . (int) $booking->booking_id . '-origin' ) );
			return ( null === $result || Refunder::settled( $result ) ) && $origin_done;
		}
		if ( self::JOB_SLOT_FREED === $type ) {
			ServiceWaitlist::offer_next( absint( $payload['slot_id'] ?? 0 ) );
			return true;
		}
		return $handled;
	}

	/** Queues the waitlist offer for a slot whose place became free. */
	public static function slot_freed( $slot_id ) {
		if ( ! $slot_id ) { return; }
		Jobs::enqueue( 'service-slot-freed-' . absint( $slot_id ) . '-' . wp_generate_uuid4(), self::JOB_SLOT_FREED, array( 'slot_id' => absint( $slot_id ) ) );
	}

	/* ================================================================== Buyer access */

	/** Booking of an order the buyer has a valid order access token for, else null. */
	public static function for_order_access( $public_id, $access_token, $booking_id = 0 ) {
		global $wpdb;
		$access_token = Security\Input::hex( $access_token );
		if ( 64 !== strlen( $access_token ) ) { return null; }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', sanitize_text_field( (string) $public_id ) ) );
		if ( ! $order || ! $order->access_expires_at || $order->access_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $order->access_token_hash, hash( 'sha256', $access_token ) ) ) { return null; }
		if ( ! $booking_id ) { return $order; }
		$booking = self::get( $booking_id );
		return $booking && (int) $booking->order_id === (int) $order->order_id ? $booking : null;
	}

	/** Booking whose manage token matches (bookings without order), else null. */
	public static function with_token( $booking_id, $token ) {
		$token = Security\Input::hex( $token );
		$booking = self::get( $booking_id );
		if ( ! $booking || 64 !== strlen( $token ) || '' === (string) $booking->manage_token_hash || ! hash_equals( (string) $booking->manage_token_hash, hash( 'sha256', $token ) ) ) { return null; }
		return $booking;
	}

	/** Bookings of an order with buyer actions and deadlines (portal, REST). */
	public static function for_order( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND status NOT IN ('expired','merged') ORDER BY booking_id", $wpdb->prefix . 'wpcap_service_bookings', absint( $order_id ) ) );
		return array_map( array( __CLASS__, 'public_view' ), (array) $rows );
	}

	/** Buyer-facing view of a booking (no internal ids of other people, no tokens). */
	public static function public_view( $booking ) {
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		$resource = $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
		$rules = ServiceResources::rules( $resource );
		$start = $slot ? strtotime( $slot->starts_at . ' UTC' ) : 0;
		$cancel_until = $start - $rules['cancel_deadline_minutes'] * MINUTE_IN_SECONDS;
		$rebook_until = $start - $rules['rebook_deadline_minutes'] * MINUTE_IN_SECONDS;
		return array(
			'booking_id' => (int) $booking->booking_id,
			'status' => (string) $booking->status,
			'quantity' => (int) $booking->quantity,
			'resource_name' => $slot ? (string) $slot->resource_name : '',
			'starts_at' => $slot ? (string) $slot->starts_at : '',
			'ends_at' => $slot ? (string) $slot->ends_at : '',
			'timezone' => ServiceResources::timezone_of( $resource ),
			'amount_minor' => (int) $booking->amount_minor,
			'currency' => (string) $booking->currency,
			'cancel_until' => gmdate( 'Y-m-d H:i:s', $cancel_until ),
			'rebook_until' => gmdate( 'Y-m-d H:i:s', $rebook_until ),
			'can_cancel' => in_array( $booking->status, self::CANCELLABLE, true ) && time() <= $cancel_until,
			'can_rebook' => in_array( $booking->status, self::REBOOKABLE, true ) && time() <= $rebook_until,
			'confirm_by' => $booking->confirm_by,
			'is_supplement' => (bool) (int) ( $booking->supplement_for ?? 0 ),
			'supplement' => self::supplement_view( $booking ),
		);
	}

	/** Open supplementary payment of a booking (dearer rebooking waiting for payment) for buyers, or null. */
	private static function supplement_view( $booking ) {
		$open = (int) ( $booking->supplement_for ?? 0 ) ? ( 'held' === $booking->status ? $booking : null ) : self::open_supplement( (int) $booking->booking_id );
		if ( ! $open ) { return null; }
		$slot = ServiceResources::slot( (int) $open->slot_id );
		return array( 'booking_id' => (int) $open->booking_id, 'amount_minor' => (int) $open->amount_minor, 'currency' => (string) $open->currency, 'expires_at' => (string) $open->expires_at, 'resource_name' => $slot ? (string) $slot->resource_name : '', 'starts_at' => $slot ? (string) $slot->starts_at : '' );
	}

	/* ================================================================== Helpers */

	public static function get( $booking_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id=%d', $wpdb->prefix . 'wpcap_service_bookings', absint( $booking_id ) ) );
	}

	public static function resource_of( $booking ) {
		$slot = $booking ? ServiceResources::slot( (int) $booking->slot_id ) : null;
		return $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
	}

	/** Compact booking state for callers. */
	public static function state( $booking ) {
		if ( ! $booking ) { return self::not_found(); }
		$refund = (int) $booking->refund_id ? Refunds::get( (int) $booking->refund_id ) : null;
		return array( 'booking_id' => (int) $booking->booking_id, 'slot_id' => (int) $booking->slot_id, 'status' => (string) $booking->status, 'order_id' => (int) $booking->order_id, 'refund_id' => (int) $booking->refund_id, 'refund_status' => $refund ? (string) $refund['status'] : '', 'confirm_by' => $booking->confirm_by );
	}

	/** Local date/time of a timestamp in the resource timezone. */
	private static function local_time( $timestamp, $resource ) {
		return wp_date( 'd.m.Y H:i', (int) $timestamp, new \DateTimeZone( ServiceResources::timezone_of( $resource ) ) );
	}

	/** Readable reason for mails. */
	public static function reason_label( $code ) {
		$labels = array(
			'customer' => __( 'Storniert auf deinen Wunsch.', 'pass-captain' ),
			'admin' => __( 'Storniert durch den Veranstalter.', 'pass-captain' ),
			'admin_no_refund' => __( 'Storniert durch den Veranstalter.', 'pass-captain' ),
			'partner_declined' => __( 'Der Anbieter konnte die Buchung nicht bestätigen.', 'pass-captain' ),
			'confirmation_timeout' => __( 'Der Anbieter hat die Buchung nicht rechtzeitig bestätigt.', 'pass-captain' ),
			'capacity_lost' => __( 'Das Zeitfenster war bei Zahlungseingang bereits ausgebucht.', 'pass-captain' ),
		);
		return $labels[ $code ] ?? $labels['admin'];
	}

	/** Sends a booking mail to the buyer with the common placeholders. */
	private static function mail( $type, $booking, array $extra ) {
		if ( ! $booking || ! is_email( (string) $booking->customer_email ) ) { return false; }
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		$resource = $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
		$vars = self::mail_vars( $booking, $slot, $resource );
		$refund = $extra['refund'] ?? null;
		$amount = is_array( $refund ) ? (int) $refund['amount_minor'] : 0;
		$currency = is_array( $refund ) ? (string) $refund['currency'] : '';
		$state = is_array( $refund ) ? (string) $refund['status'] : '';
		$vars['refund_info'] = static function () use ( $refund, $amount, $currency, $state ) {
			if ( null === $refund ) { return ''; }
			if ( is_wp_error( $refund ) || 'failed' === $state ) { return __( 'Die Erstattung wird gerade bearbeitet; du erhältst eine eigene Bestätigung, sobald sie ausgeführt ist.', 'pass-captain' ); }
			if ( 'pending_approval' === $state ) { return __( 'Die Erstattung wird geprüft und anschließend ausgeführt.', 'pass-captain' ); }
			/* translators: %s: amount */
			return $amount > 0 ? sprintf( __( 'Erstattet werden %s (Zahlungsmittel bzw. Gutschein).', 'pass-captain' ), number_format_i18n( $amount / 100, 2 ) . ' ' . $currency ) : '';
		};
		$vars['reason'] = (string) ( $extra['reason'] ?? '' );
		if ( isset( $extra['old_slot'] ) && $extra['old_slot'] ) {
			$vars['old_slot_date'] = self::local_time( strtotime( $extra['old_slot']->starts_at . ' UTC' ), $resource );
		}
		if ( ! empty( $extra['manage_token'] ) ) {
			$vars['manage_link'] = Mail\Mailer::link( add_query_arg( array( 'wpcap_service_booking' => (int) $booking->booking_id, 'token' => (string) $extra['manage_token'] ), home_url( '/' ) ) );
		}
		$locale = (int) $booking->order_id ? (string) ( Orders::get( (int) $booking->order_id )->locale ?? '' ) : '';
		return Mail\Mailer::send( $type, (string) $booking->customer_email, $vars, array(), $locale );
	}

	/** Placeholder values shared by all service booking mails. */
	public static function mail_vars( $booking, $slot, $resource ) {
		global $wpdb;
		$event = $resource ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', (int) $resource->event_id ) ) : '';
		$rules = ServiceResources::rules( $resource );
		$start = $slot ? strtotime( $slot->starts_at . ' UTC' ) : time();
		$fee = (int) $booking->fee_minor;
		$fee_currency = (string) $booking->currency;
		return array(
			'booking_reference' => '#' . (int) $booking->booking_id,
			'resource_name' => $slot ? (string) $slot->resource_name : '',
			'event_name' => $event,
			'slot_date' => self::local_time( $start, $resource ) . ' (' . ServiceResources::timezone_of( $resource ) . ')',
			'quantity' => (int) $booking->quantity,
			'cancel_until' => self::local_time( $start - $rules['cancel_deadline_minutes'] * MINUTE_IN_SECONDS, $resource ),
			'rebook_until' => self::local_time( $start - $rules['rebook_deadline_minutes'] * MINUTE_IN_SECONDS, $resource ),
			'confirm_by' => $booking->confirm_by ? self::local_time( strtotime( $booking->confirm_by . ' UTC' ), $resource ) : '',
			'manage_link' => '',
			'old_slot_date' => '',
			'fee_info' => static function () use ( $fee, $fee_currency ) {
				/* translators: %s: amount */
				return $fee > 0 ? sprintf( __( 'Laut Buchungsbedingungen wird eine Gebühr von %s fällig.', 'pass-captain' ), number_format_i18n( $fee / 100, 2 ) . ' ' . $fee_currency ) : '';
			},
		);
	}

	/** Notice to the partner contact (or the site admin) that a booking waits for confirmation. */
	private static function notify_partner( $booking, $resource ) {
		$to = '';
		if ( (int) $resource->partner_id && ServiceResources::partners_enabled() ) {
			$partner = Partners\Repository::get( (int) $resource->partner_id );
			$to = $partner ? (string) $partner->contact_email : '';
		}
		if ( ! is_email( $to ) ) { $to = (string) get_option( 'admin_email' ); }
		if ( ! is_email( $to ) ) { return; }
		$slot = ServiceResources::slot( (int) $booking->slot_id );
		$vars = self::mail_vars( $booking, $slot, $resource );
		$vars['admin_link'] = Mail\Mailer::link( admin_url( 'admin.php?page=wpcap-services&slot_id=' . (int) $booking->slot_id ) );
		Mail\Mailer::send( 'service_booking_partner_request', $to, $vars );
	}

	private static function busy() {
		return new \WP_Error( 'wpcap_db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}

	private static function unavailable() {
		return new \WP_Error( 'wpcap_slot_unavailable', __( 'Dieses Zeitfenster ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	private static function not_found() {
		return new \WP_Error( 'wpcap_booking_not_found', __( 'Buchung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
	}

	private static function state_error( $message = '' ) {
		return new \WP_Error( 'wpcap_booking_state', '' !== $message ? $message : __( 'Der Status dieser Buchung erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
	}

	/* =============================================================================================
	 * Resources, slots, booking rules, providers and the slot booking flow of the A-line (A133–A135,
	 * A143, A157, A268, A421, A644; ServiceBookingFlow, Admin\ServiceSlotsPage, Frontend\ServiceBooking).
	 * Merged from the parallel implementation: the order/payment based booking flow above
	 * (reserve(), confirm(), cancel(), rebook()) is the public one; the A-line variants of those four
	 * are kept as slot_reserve(), slot_confirm(), slot_cancel() and slot_rebook() for its internal flow.
	 * ============================================================================================= */

	/** Most places of one service booking (REST refuses more with 400, A421). */
	const MAX_QUANTITY = 20;

	/** Reservations per e-mail address and hour (A644: only reservations that were made count). */
	const EMAIL_HOURLY_LIMIT = 20;

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
	public static function slot_reserve( $slot_id, $email, $quantity = 1, $order_id = 0, $minutes = 10, $idempotency_key = '' ) {
		global $wpdb;
		$slot_id = absint( $slot_id );
		$email = Http::email( $email );
		$quantity = min( self::MAX_QUANTITY, max( 1, absint( $quantity ) ) );
		$idempotency_key = sanitize_text_field( Http::str( $idempotency_key ) );
		if ( ! $slot_id || ! Http::valid_email( $email ) || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ) ); }
		// A644: the client counts every attempt; the e-mail address (chosen by whoever sends the request)
		// only counts reservations that were made, so failed or invalid attempts cannot lock it out.
		$email_bucket = 'servicebook|' . strtolower( $email );
		if ( ! RateLimit::allow_client( 'servicebook_ip', (int) apply_filters( 'wpcap_service_booking_ip_limit', 40 ), HOUR_IN_SECONDS ) ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
		if ( RateLimit::hits( $email_bucket, HOUR_IN_SECONDS ) >= self::EMAIL_HOURLY_LIMIT ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
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
		RateLimit::allow( $email_bucket, self::EMAIL_HOURLY_LIMIT, HOUR_IN_SECONDS ); // A644: counted once the hold exists.
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

	private static function service_not_found() {
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
		if ( ! $resource ) { return self::service_not_found(); }
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
		if ( ! $resource ) { return self::service_not_found(); }
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
		if ( ! $slot ) { return self::service_not_found(); }
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
		if ( ! $slot ) { return self::service_not_found(); }
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
	 *
	 * @param int   $event_id Event.
	 * @param array $filter   Optional (A268, booking page): resource_id; from / to (UTC
	 *                        'Y-m-d H:i:s', start >= from and < to); limit (1–100).
	 */
	public static function public_slots( $event_id, array $filter = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$columns = self::resource_columns();
		$where = '';
		$args = array( absint( $event_id ), Clock::mysql() );
		if ( ! empty( $filter['resource_id'] ) ) { $where .= ' AND s.resource_id=%d'; $args[] = absint( $filter['resource_id'] ); }
		foreach ( array( 'from' => '>=', 'to' => '<' ) as $key => $op ) {
			if ( ! empty( $filter[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $filter[ $key ] ) ) { $where .= " AND s.starts_at$op%s"; $args[] = (string) $filter[ $key ]; }
		}
		$limit = min( 100, max( 1, absint( $filter['limit'] ?? 100 ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list from a constant, $where built from fixed fragments with placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*,$columns FROM {$p}wpcap_service_slots s INNER JOIN {$p}wpcap_resources r ON r.resource_id=s.resource_id INNER JOIN {$p}wpcap_events e ON e.event_id=r.event_id WHERE r.event_id=%d AND r.status='active' AND e.status='published' AND s.status='open' AND s.starts_at>%s$where ORDER BY s.starts_at,s.slot_id LIMIT 300", ...$args ) );
		$out = array();
		foreach ( $rows as $slot ) {
			$available = (int) $slot->capacity - self::booked( (int) $slot->slot_id );
			// A134: only slots inside the booking window (lead time, maximum advance booking).
			if ( $available <= 0 || self::rule_error( $slot, '' ) || self::blocking_slot( $slot ) ) { continue; }
			$out[] = array( 'slot_id' => (int) $slot->slot_id, 'resource_id' => (int) $slot->resource_id, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'capacity' => (int) $slot->capacity, 'resource_name' => (string) $slot->resource_name, 'available' => $available );
			if ( count( $out ) >= $limit ) { break; }
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
			$result = self::slot_confirm( (int) $booking->booking_id, array( 'actor' => 'provider' ) );
			if ( is_wp_error( $result ) ) { return $result; }
			do_action( 'wpcap_service_booking_status_changed', (int) $booking->booking_id, 'confirmed', 'held' );
			return true;
		}
		$locked = self::begin( (int) $booking->booking_id );
		if ( is_wp_error( $locked ) ) { return $locked; }
		if ( 'no_show' === $locked->status ) { $wpdb->query( 'ROLLBACK' ); return true; }
		if ( 'confirmed' !== $locked->status ) { $wpdb->query( 'ROLLBACK' ); return self::state_error( __( 'Nicht erschienen kann nur für eine bestätigte Buchung markiert werden.', 'pass-captain' ) ); }
		if ( (string) $locked->starts_at > Clock::mysql() ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_booking_not_started', __( 'Nicht erschienen kann erst ab Beginn des Zeitfensters markiert werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
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
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.*,r.name AS resource_name,r.event_id,(SELECT COALESCE(SUM(b.quantity),0) FROM {$wpdb->prefix}wpcap_service_bookings b WHERE b.slot_id=s.slot_id AND " . self::ACTIVE_SQL . ") AS booked FROM {$wpdb->prefix}wpcap_service_slots s INNER JOIN {$wpdb->prefix}wpcap_resources r ON r.resource_id=s.resource_id WHERE s.resource_id IN (" . implode( ',', $resource_ids ) . ') AND s.ends_at >= %s ORDER BY s.starts_at,s.slot_id LIMIT %d', Clock::mysql(), $since, max( 1, min( 2000, absint( $limit ) ) ) ) );
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
	public static function slot_confirm( $booking_id, array $args = array() ) {
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
	public static function slot_rebook( $booking_id, $target_slot_id, array $args = array() ) {
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
	public static function slot_cancel( $booking_id, array $args = array() ) {
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
