<?php
/**
 * Waitlist for fully booked service slots: ordered queue (strict FIFO by position), time-limited
 * offers that hold the place (counted as used capacity until accepted, declined or expired),
 * automatic next offer and a position lookup for the person on the list.
 *
 * Offers are made under the same locks as bookings (resource row, then slot row), so an offer never
 * promises a place that a parallel booking takes.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceWaitlist {
	const OPEN_STATUSES = array( 'waiting', 'offered' );

	/** Places held by open (unexpired) offers of a slot; $exclude_entry_id is not counted. */
	public static function held_quantity( $slot_id, $exclude_entry_id = 0 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM %i WHERE slot_id=%d AND entry_id<>%d AND status='offered' AND offer_expires_at>%s", $wpdb->prefix . 'wpcap_service_waitlist', absint( $slot_id ), absint( $exclude_entry_id ), current_time( 'mysql', true ) ) );
	}

	public static function get( $entry_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE entry_id=%d', $wpdb->prefix . 'wpcap_service_waitlist', absint( $entry_id ) ) );
	}

	/**
	 * Puts an address on the waitlist of a full slot. The response is the same whether or not the
	 * address was already listed; the position comes by mail (with a link to look it up later).
	 *
	 * @return array|\WP_Error
	 */
	public static function join( $slot_id, $email, $quantity = 1 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$slot_id = absint( $slot_id );
		$email = sanitize_email( $email );
		$quantity = min( 20, max( 1, absint( $quantity ) ) );
		if ( ! $slot_id || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_waitlist_invalid', __( 'Zeitfenster oder E-Mail-Adresse ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		// Per address and slot, and per client; RateLimit::hit() counts atomically (QA-V2).
		if ( ! \PassCaptain\RateLimit::hit( 'wpcap_svcwait_' . \PassCaptain\RateLimit::hash( strtolower( $email ) . '|' . $slot_id ), 5, HOUR_IN_SECONDS ) || ! \PassCaptain\RateLimit::hit( 'wpcap_svcwait_ip_' . \PassCaptain\RateLimit::client(), 30, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$slot = ServiceResources::slot( $slot_id );
		$resource = $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
		$published = $resource ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE event_id=%d', $p . 'wpcap_events', (int) $resource->event_id ) ) : '';
		if ( ! $slot || 'open' !== $slot->status || 'active' !== $resource->status || 'published' !== $published || $slot->starts_at <= current_time( 'mysql', true ) ) {
			return new \WP_Error( 'wpcap_slot_unavailable', __( 'Dieses Zeitfenster ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! ServiceResources::rules( $resource )['waitlist_enabled'] ) { return new \WP_Error( 'wpcap_waitlist_disabled', __( 'Für dieses Angebot gibt es keine Warteliste.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$email_hash = hash( 'sha256', strtolower( $email ) );
		$now = current_time( 'mysql', true );
		$token = bin2hex( random_bytes( 32 ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::busy(); }
		$slot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d FOR UPDATE', $p . 'wpcap_service_slots', $slot_id ) );
		$free = (int) $slot->capacity - ServiceResources::booked_quantity( $slot_id ) - self::held_quantity( $slot_id );
		$waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE slot_id=%d AND status IN ('waiting','offered')", $p . 'wpcap_service_waitlist', $slot_id ) );
		if ( $free >= $quantity && 0 === $waiting ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_waitlist_slot_available', __( 'In diesem Zeitfenster sind noch Plätze frei – du kannst direkt buchen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT entry_id,status FROM %i WHERE slot_id=%d AND email_hash=%s FOR UPDATE', $p . 'wpcap_service_waitlist', $slot_id, $email_hash ) );
		if ( $existing && in_array( $existing->status, array_merge( self::OPEN_STATUSES, array( 'accepted' ) ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return self::join_response();
		}
		$position = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(position),0) FROM %i WHERE slot_id=%d', $p . 'wpcap_service_waitlist', $slot_id ) );
		$row = array( 'email' => $email, 'quantity' => $quantity, 'position' => $position, 'status' => 'waiting', 'offer_token_hash' => '', 'offer_expires_at' => null, 'manage_token_hash' => hash( 'sha256', $token ), 'booking_id' => 0, 'updated_at' => $now );
		if ( $existing ) {
			// Former entry (expired, declined, left): back to the end of the queue.
			$ok = false !== $wpdb->update( "{$p}wpcap_service_waitlist", $row, array( 'entry_id' => (int) $existing->entry_id ) );
			$entry_id = (int) $existing->entry_id;
		} else {
			$ok = false !== $wpdb->insert( "{$p}wpcap_service_waitlist", $row + array( 'slot_id' => $slot_id, 'email_hash' => $email_hash, 'created_at' => $now ) );
			$entry_id = (int) $wpdb->insert_id;
		}
		if ( ! $ok || ! $entry_id || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::busy(); }
		Audit::record( 'service_waitlist_joined', 'service_waitlist', $entry_id, array( 'slot_id' => $slot_id, 'position' => $position, 'quantity' => $quantity ) );
		$ahead = self::ahead( $slot_id, $position );
		Mail\Mailer::send( 'service_waitlist_joined', $email, self::vars( $slot, $resource ) + array( 'position' => $ahead + 1, 'quantity' => $quantity, 'status_link' => Mail\Mailer::link( self::link( $entry_id, $token, 'status' ) ), 'leave_link' => Mail\Mailer::link( self::link( $entry_id, $token, 'leave' ) ) ) );
		return self::join_response();
	}

	private static function join_response() {
		return array( 'status' => 'received', 'message' => __( 'Danke! Falls die Adresse noch nicht eingetragen war, bekommst du deine Wartelistenposition per E-Mail.', 'pass-captain' ) );
	}

	/** Number of open entries ahead of a position. */
	private static function ahead( $slot_id, $position ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE slot_id=%d AND position<%d AND status IN ('waiting','offered')", $wpdb->prefix . 'wpcap_service_waitlist', absint( $slot_id ), absint( $position ) ) );
	}

	/** Entry whose manage or offer token matches, else null. */
	public static function with_token( $entry_id, $token ) {
		$token = Security\Input::hex( $token );
		$entry = self::get( $entry_id );
		if ( ! $entry || 64 !== strlen( $token ) ) { return null; }
		$hash = hash( 'sha256', $token );
		$ok = ( '' !== (string) $entry->manage_token_hash && hash_equals( (string) $entry->manage_token_hash, $hash ) ) || ( '' !== (string) $entry->offer_token_hash && hash_equals( (string) $entry->offer_token_hash, $hash ) );
		return $ok ? $entry : null;
	}

	/**
	 * Transparent position for the person on the list.
	 *
	 * @return array|\WP_Error status, position (1 = next), quantity, offer_expires_at, slot.
	 */
	public static function position( $entry_id, $token ) {
		$entry = self::with_token( $entry_id, $token );
		if ( ! $entry ) { return self::invalid(); }
		$expired = 'offered' === $entry->status && $entry->offer_expires_at <= current_time( 'mysql', true );
		$slot = ServiceResources::slot( (int) $entry->slot_id );
		return array(
			'status' => $expired ? 'expired' : (string) $entry->status,
			'position' => in_array( $entry->status, self::OPEN_STATUSES, true ) && ! $expired ? self::ahead( (int) $entry->slot_id, (int) $entry->position ) + 1 : 0,
			'quantity' => (int) $entry->quantity,
			'offer_expires_at' => 'offered' === $entry->status ? $entry->offer_expires_at : null,
			'resource_name' => $slot ? (string) $slot->resource_name : '',
			'starts_at' => $slot ? (string) $slot->starts_at : '',
		);
	}

	/**
	 * Offers freed places to the next waiting entries (strict queue order: an entry that needs more
	 * places than are free blocks the ones behind it). Each offer holds its places until it expires.
	 *
	 * @return int Number of offers made.
	 */
	public static function offer_next( $slot_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$slot_id = absint( $slot_id );
		$offered = 0;
		for ( $guard = 0; $guard < 50; $guard++ ) {
			$slot = ServiceResources::slot( $slot_id );
			if ( ! $slot ) { return $offered; }
			$now = current_time( 'mysql', true );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $offered; }
			$resource = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id=%d FOR UPDATE', $p . 'wpcap_resources', (int) $slot->resource_id ) );
			$slot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d FOR UPDATE', $p . 'wpcap_service_slots', $slot_id ) );
			if ( ! $resource || ! $slot ) { $wpdb->query( 'ROLLBACK' ); return $offered; }
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired',offer_token_hash='',updated_at=%s WHERE slot_id=%d AND status='offered' AND offer_expires_at<=%s", $p . 'wpcap_service_waitlist', $now, $slot_id, $now ) );
			$rules = ServiceResources::rules( $resource );
			$deadline = strtotime( $slot->starts_at . ' UTC' ) - $rules['cutoff_minutes'] * MINUTE_IN_SECONDS;
			if ( 'open' !== $slot->status || 'active' !== $resource->status || ! $rules['waitlist_enabled'] || $deadline <= time() + 5 * MINUTE_IN_SECONDS ) { $wpdb->query( 'COMMIT' ); return $offered; }
			$free = (int) $slot->capacity - ServiceResources::booked_quantity( $slot_id ) - self::held_quantity( $slot_id );
			$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE slot_id=%d AND status='waiting' ORDER BY position,entry_id LIMIT 1 FOR UPDATE", $p . 'wpcap_service_waitlist', $slot_id ) );
			if ( ! $entry || (int) $entry->quantity > $free ) { $wpdb->query( 'COMMIT' ); return $offered; }
			$token = bin2hex( random_bytes( 32 ) );
			$expires = gmdate( 'Y-m-d H:i:s', min( time() + $rules['waitlist_offer_minutes'] * MINUTE_IN_SECONDS, $deadline ) );
			$changed = $wpdb->update( "{$p}wpcap_service_waitlist", array( 'status' => 'offered', 'offer_token_hash' => hash( 'sha256', $token ), 'offer_expires_at' => $expires, 'updated_at' => $now ), array( 'entry_id' => (int) $entry->entry_id, 'status' => 'waiting' ) );
			if ( 1 !== (int) $changed || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return $offered; }
			Audit::record( 'service_waitlist_offered', 'service_waitlist', (int) $entry->entry_id, array( 'slot_id' => $slot_id, 'position' => (int) $entry->position, 'quantity' => (int) $entry->quantity, 'expires_at' => $expires ) );
			$sent = Mail\Mailer::send( 'service_waitlist_offer', (string) $entry->email, self::vars( $slot, $resource ) + array(
				'quantity' => (int) $entry->quantity,
				'offer_expires' => wp_date( 'd.m.Y H:i', strtotime( $expires . ' UTC' ), new \DateTimeZone( ServiceResources::timezone_of( $resource ) ) ),
				'accept_link' => Mail\Mailer::link( self::link( (int) $entry->entry_id, $token, 'accept' ) ),
				'decline_link' => Mail\Mailer::link( self::link( (int) $entry->entry_id, $token, 'decline' ) ),
			) );
			if ( ! $sent ) {
				// Without the mail nobody can accept: the entry goes back to the queue and we stop here.
				$wpdb->update( "{$p}wpcap_service_waitlist", array( 'status' => 'waiting', 'offer_token_hash' => '', 'offer_expires_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'entry_id' => (int) $entry->entry_id, 'status' => 'offered' ) );
				Audit::record( 'service_waitlist_offer_mail_failed', 'service_waitlist', (int) $entry->entry_id, array( 'slot_id' => $slot_id ) );
				return $offered;
			}
			++$offered;
		}
		return $offered;
	}

	/**
	 * Accepts an offer: books the held places (free resources: confirmed booking; paid resources: an
	 * order with payment hold is created and the booking is confirmed on payment). Idempotent – a
	 * repeated or parallel acceptance returns the same booking.
	 *
	 * @return array|\WP_Error
	 */
	public static function accept( $entry_id, $token ) {
		global $wpdb;
		$entry = self::get( $entry_id );
		$token = Security\Input::hex( $token );
		if ( ! $entry || 64 !== strlen( $token ) ) { return self::invalid(); }
		$hash = hash( 'sha256', $token );
		$token_ok = ( '' !== (string) $entry->offer_token_hash && hash_equals( (string) $entry->offer_token_hash, $hash ) );
		if ( 'accepted' === $entry->status && (int) $entry->booking_id ) {
			// Repeated click on the link (the offer token is kept as proof after acceptance).
			return $token_ok ? array( 'status' => 'accepted', 'booking' => ServiceBookings::state( ServiceBookings::get( (int) $entry->booking_id ) ), 'reused' => true ) : self::invalid();
		}
		if ( ! $token_ok || 'offered' !== $entry->status || $entry->offer_expires_at <= current_time( 'mysql', true ) ) { return self::invalid(); }
		$slot = ServiceResources::slot( (int) $entry->slot_id );
		$resource = $slot ? ServiceResources::resource( (int) $slot->resource_id ) : null;
		if ( ! $resource ) { return self::invalid(); }
		$order_id = 0;
		if ( (int) $resource->ticket_type_id ) {
			$order = Orders::create(
				array( 'email' => (string) $entry->email, 'items' => array( array( 'ticket_type_id' => (int) $resource->ticket_type_id, 'quantity' => (int) $entry->quantity ) ), 'idempotency_key' => 'svcwl-' . (int) $entry->entry_id, 'accept_terms' => 1 ),
				array( 'key_namespace' => 'service_waitlist', 'skip_rate_limit' => true, 'source' => 'service_waitlist' )
			);
			if ( is_wp_error( $order ) ) { return new \WP_Error( 'wpcap_waitlist_order', $order->get_error_message(), array( 'status' => 409 ) ); }
			$order_id = (int) $order['order_id'];
		}
		$booking = ServiceBookings::reserve( (int) $entry->slot_id, (string) $entry->email, (int) $entry->quantity, $order_id, 15, 'svcwl-' . (int) $entry->entry_id, array( 'actor' => 'system', 'skip_rate_limit' => true, 'waitlist_entry_id' => (int) $entry->entry_id ) );
		if ( is_wp_error( $booking ) ) { return $booking; }
		// The booking consumed the offer in its own transaction (status accepted, booking_id set).
		Audit::record( 'service_waitlist_accepted', 'service_waitlist', (int) $entry->entry_id, array( 'booking_id' => (int) $booking['booking_id'], 'order_id' => $order_id ) );
		if ( $order_id ) {
			$result = ServiceBookings::attach_order( (int) $booking['booking_id'], $order_id );
		} else {
			$result = ServiceBookings::confirm( (int) $booking['booking_id'], array( 'manage_token' => (string) ( $booking['manage_token'] ?? '' ) ) );
		}
		return array( 'status' => 'accepted', 'booking' => is_wp_error( $result ) ? ServiceBookings::state( ServiceBookings::get( (int) $booking['booking_id'] ) ) : $result, 'order_id' => $order_id );
	}

	/** Declines an open offer; the places go to the next entry. */
	public static function decline( $entry_id, $token ) {
		global $wpdb;
		$entry = self::with_token( $entry_id, $token );
		if ( ! $entry || 'offered' !== $entry->status ) { return self::invalid(); }
		$changed = $wpdb->update( $wpdb->prefix . 'wpcap_service_waitlist', array( 'status' => 'declined', 'offer_token_hash' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'entry_id' => (int) $entry->entry_id, 'status' => 'offered' ) );
		if ( 1 !== (int) $changed ) { return self::invalid(); }
		Audit::record( 'service_waitlist_declined', 'service_waitlist', (int) $entry->entry_id, array( 'slot_id' => (int) $entry->slot_id ) );
		ServiceBookings::slot_freed( (int) $entry->slot_id );
		return array( 'status' => 'declined' );
	}

	/** Leaves the waitlist (waiting or offered). */
	public static function leave( $entry_id, $token ) {
		global $wpdb;
		$entry = self::with_token( $entry_id, $token );
		if ( ! $entry || ! in_array( $entry->status, self::OPEN_STATUSES, true ) ) { return self::invalid(); }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='left',offer_token_hash='',updated_at=%s WHERE entry_id=%d AND status IN ('waiting','offered')", $wpdb->prefix . 'wpcap_service_waitlist', current_time( 'mysql', true ), (int) $entry->entry_id ) );
		if ( 1 !== (int) $changed ) { return self::invalid(); }
		Audit::record( 'service_waitlist_left', 'service_waitlist', (int) $entry->entry_id, array( 'slot_id' => (int) $entry->slot_id, 'was' => $entry->status ) );
		if ( 'offered' === $entry->status ) { ServiceBookings::slot_freed( (int) $entry->slot_id ); }
		return array( 'status' => 'left' );
	}

	/** Admin: removes an entry (audited without the address). */
	public static function remove( $entry_id ) {
		global $wpdb;
		$entry = self::get( $entry_id );
		if ( ! $entry ) { return new \WP_Error( 'wpcap_waitlist_missing', __( 'Wartelisteneintrag nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->delete( $wpdb->prefix . 'wpcap_service_waitlist', array( 'entry_id' => (int) $entry->entry_id ), array( '%d' ) );
		Audit::record( 'service_waitlist_removed', 'service_waitlist', (int) $entry->entry_id, array( 'slot_id' => (int) $entry->slot_id, 'status' => $entry->status, 'position' => (int) $entry->position ) );
		if ( 'offered' === $entry->status ) { ServiceBookings::slot_freed( (int) $entry->slot_id ); }
		return array( 'slot_id' => (int) $entry->slot_id );
	}

	/** Entries of a slot in queue order. */
	public static function entries( $slot_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE slot_id=%d ORDER BY position,entry_id LIMIT 500', $wpdb->prefix . 'wpcap_service_waitlist', absint( $slot_id ) ) );
	}

	/** Expired offers move on to the next entry. @return int Slots processed. */
	public static function sweep( $limit = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$slots = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT slot_id FROM %i WHERE status='offered' AND offer_expires_at<=%s LIMIT %d", $p . 'wpcap_service_waitlist', $now, absint( $limit ) ) ) );
		foreach ( $slots as $slot_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired',offer_token_hash='',updated_at=%s WHERE slot_id=%d AND status='offered' AND offer_expires_at<=%s", $p . 'wpcap_service_waitlist', $now, $slot_id, $now ) );
			Audit::record( 'service_waitlist_offer_expired', 'service_slot', $slot_id );
			self::offer_next( $slot_id );
		}
		// Slots with waiting entries and free places (e.g. capacity raised by staff).
		$open = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT w.slot_id FROM %i w INNER JOIN %i s ON s.slot_id=w.slot_id WHERE w.status='waiting' AND s.status='open' AND s.starts_at>%s GROUP BY w.slot_id ORDER BY MIN(s.starts_at) LIMIT %d", $p . 'wpcap_service_waitlist', $p . 'wpcap_service_slots', $now, absint( $limit ) ) ) );
		foreach ( array_diff( $open, $slots ) as $slot_id ) { self::offer_next( $slot_id ); }
		return count( $slots );
	}

	/** Public link for an entry action (GET shows a confirmation form; the action itself is a POST). */
	public static function link( $entry_id, $token, $do ) {
		return add_query_arg( array( 'wpcap_service_waitlist' => absint( $entry_id ), 'do' => sanitize_key( $do ), 'token' => $token ), home_url( '/' ) );
	}

	private static function vars( $slot, $resource ) {
		global $wpdb;
		$event = $resource ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_events', (int) $resource->event_id ) ) : '';
		$tz = ServiceResources::timezone_of( $resource );
		return array(
			'resource_name' => (string) ( $resource->name ?? '' ),
			'event_name' => $event,
			'slot_date' => wp_date( 'd.m.Y H:i', strtotime( $slot->starts_at . ' UTC' ), new \DateTimeZone( $tz ) ) . ' (' . $tz . ')',
		);
	}

	private static function invalid() {
		return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	private static function busy() {
		return new \WP_Error( 'wpcap_db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503, 'retryable' => true ) );
	}
}
