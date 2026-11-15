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
		$email = sanitize_email( $email );
		$quantity = Orders::positive_int( $quantity );
		if ( $quantity < 1 || $quantity > 20 ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$idempotency_key = sanitize_text_field( $idempotency_key );
		$actor = (string) ( $args['actor'] ?? 'customer' );
		$actor = in_array( $actor, array( 'customer', 'admin', 'system' ), true ) ? $actor : 'customer';
		if ( ! $slot_id || ! is_email( $email ) || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_booking_invalid', __( 'Buchungsdaten oder Idempotency-Key ungültig.', 'pass-captain' ) ); }
		if ( empty( $args['skip_rate_limit'] ) ) {
			$rate_key = 'wpcap_servicebook_' . RateLimit::hash( strtolower( $email ) );
			$rate = (int) get_transient( $rate_key );
			if ( $rate >= 20 ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
			set_transient( $rate_key, $rate + 1, HOUR_IN_SECONDS );
			$ip_key = 'wpcap_servicebook_ip_' . RateLimit::client();
			$ip_rate = (int) get_transient( $ip_key );
			if ( $ip_rate >= (int) apply_filters( 'wpcap_service_booking_ip_limit', 40 ) ) { return new \WP_Error( 'wpcap_booking_rate', __( 'Zu viele Buchungsversuche; bitte warte kurz.', 'pass-captain' ), array( 'status' => 429 ) ); }
			set_transient( $ip_key, $ip_rate + 1, HOUR_IN_SECONDS );
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
			$rule_error = self::rule_error( $resource, $slot, $rules, $email, $quantity, 0 );
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
	private static function rule_error( $resource, $slot, array $rules, $email, $quantity, $exclude_booking_id ) {
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
			$rule_error = self::rule_error( $resource, $target, ServiceResources::rules( $resource ), $booking->customer_email, (int) $booking->quantity, (int) $booking->booking_id );
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
			$rule_error = self::rule_error( $resource, $target, ServiceResources::rules( $resource ), $locked_booking->customer_email, $quantity, (int) $locked_booking->booking_id );
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

	private static function state_error() {
		return new \WP_Error( 'wpcap_booking_state', __( 'Der Status dieser Buchung erlaubt diese Aktion nicht.', 'pass-captain' ), array( 'status' => 409 ) );
	}
}
