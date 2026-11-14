<?php
/**
 * Event-scoped waitlist with ordered entries and expiring offers.
 *
 * Automation: when capacity of a ticket type frees up (cancellation, expired hold, refund, revoked
 * tickets, capacity raised – see register()), a job offers the place to the next waiting entry. An
 * offer made while capacity is free reserves it: the entry's hold_quantity counts as used capacity
 * (filters wpcap_ticket_availability and wpcap_reserve_capacity) until the offer is accepted (the
 * order then holds the ticket), declined, unsubscribed or expired (→ next entry). Offers are made
 * under the ticket type row lock that Orders::create() also takes, so an offer and a parallel
 * checkout never both get the last ticket.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Waitlists {
	/** Job type: offer freed capacity of ticket types to the next entries. */
	const JOB = 'waitlist_capacity_freed';

	/** Waitlist entry whose hold is consumed by the order being created (accept_offer()). */
	private static $accepting = 0;

	public static function register() {
		add_filter( 'wpcap_ticket_availability', array( __CLASS__, 'filter_availability' ), 20, 3 );
		add_filter( 'wpcap_reserve_capacity', array( __CLASS__, 'reserve_capacity' ), 20, 3 );
		add_action( 'wpcap_capacity_released', array( __CLASS__, 'on_capacity_released' ), 20, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund_completed' ), 30, 2 );
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/** Acceptance window of an offer in hours (filter wpcap_waitlist_offer_hours, default 24). */
	public static function offer_hours() {
		return min( 720, max( 1, (int) apply_filters( 'wpcap_waitlist_offer_hours', 24 ) ) );
	}

	/**
	 * Tickets held by open (unexpired) offers: of the ticket type, or – when the occurrence itself is
	 * limited – of all ticket types of its occurrence. $exclude_waitlist_id is not counted.
	 */
	public static function held_quantity( $type, $occurrence = null, $exclude_waitlist_id = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		if ( $occurrence && (int) $occurrence->capacity > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(w.hold_quantity),0) FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id WHERE tt.occurrence_id=%d AND w.status='offered' AND w.offer_expires_at>%s AND w.waitlist_id<>%d", (int) $occurrence->occurrence_id, $now, absint( $exclude_waitlist_id ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hold_quantity),0) FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND status='offered' AND offer_expires_at>%s AND waitlist_id<>%d", (int) $type->ticket_type_id, $now, absint( $exclude_waitlist_id ) ) );
	}

	/**
	 * Filter wpcap_ticket_availability: open offer holds are not sellable. Conservative when only the
	 * ticket type is limited but other types of a limited occurrence hold offers (never oversells).
	 */
	public static function filter_availability( $remaining, $type, $occurrence ) {
		if ( null === $remaining || ! is_numeric( $remaining ) ) { return $remaining; }
		return max( 0, (int) $remaining - self::held_quantity( $type, $occurrence, self::$accepting ) );
	}

	/**
	 * Filter wpcap_reserve_capacity (inside Orders::create(), ticket types and occurrences locked):
	 * a checkout may not take tickets that are held for waitlist offers – except the order created by
	 * accepting that offer.
	 */
	public static function reserve_capacity( $result, $demand, $context ) {
		if ( $result instanceof \WP_Error || 'create' !== ( $context['phase'] ?? '' ) ) { return $result; }
		foreach ( (array) $demand as $type_id => $quantity ) {
			$type = $context['types'][ $type_id ] ?? null;
			if ( ! $type ) { continue; }
			$occurrence = $context['occurrences'][ (int) $type->occurrence_id ] ?? null;
			if ( ! self::held_quantity( $type, $occurrence, self::$accepting ) ) { continue; }
			$available = Orders::availability( (int) $type_id );
			if ( null !== $available && (int) $available < (int) $quantity ) {
				return new \WP_Error( 'sold_out', __( 'Für diese Ticketart sind nicht mehr genügend Tickets verfügbar.', 'pass-captain' ) );
			}
		}
		return $result;
	}

	/** Hook wpcap_capacity_released: queue offers for the order's ticket types. */
	public static function on_capacity_released( $order_id ) {
		global $wpdb;
		$types = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d", absint( $order_id ) ) ) );
		self::queue( $types );
	}

	/** Hook wpcap_refund_completed: partial refunds free capacity without wpcap_capacity_released. */
	public static function on_refund_completed( $refund_id, $order_id ) {
		self::on_capacity_released( $order_id );
	}

	/** Queues an offer run for ticket types that have waiting entries. */
	public static function queue( array $type_ids ) {
		global $wpdb;
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids ) { return 0; }
		$waiting = array_map( 'absint', $wpdb->get_col( "SELECT DISTINCT ticket_type_id FROM {$wpdb->prefix}wpcap_waitlist WHERE status='waiting' AND ticket_type_id IN (" . implode( ',', $type_ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( $waiting as $type_id ) {
			Jobs::enqueue( 'waitlist-freed-' . $type_id . '-' . wp_generate_uuid4(), self::JOB, array( 'ticket_type_id' => $type_id ) );
		}
		return count( $waiting );
	}

	/** Filter wp_pass_captain_process_job_v1. */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::JOB !== $type ) { return $handled; }
		$result = self::auto_offer( absint( ( (array) $payload )['ticket_type_id'] ?? 0 ) );
		// A failed mail puts the entry back into the queue; the job retries later.
		return ! is_wp_error( $result );
	}

	/**
	 * Offers free tickets of a type to the next waiting entries, one ticket per entry, each with a
	 * hold. Runs under the ticket type row lock (same lock as Orders::create()).
	 *
	 * @return int|\WP_Error Number of offers made.
	 */
	public static function auto_offer( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket_type_id = absint( $ticket_type_id );
		$made = 0;
		for ( $guard = 0; $guard < 50; $guard++ ) {
			$result = self::offer_one( $ticket_type_id, true );
			if ( is_wp_error( $result ) ) { return 'wpcap_waitlist_empty' === $result->get_error_code() || 'wpcap_waitlist_no_capacity' === $result->get_error_code() ? $made : $result; }
			++$made;
		}
		return $made;
	}

	/**
	 * Makes one offer for a ticket type. With $require_capacity the offer is only made (with a hold)
	 * when a ticket is free; without it (manual offer by staff) a sold-out type gets an offer without
	 * hold that can only be accepted if a ticket is free at that time.
	 *
	 * @return array|\WP_Error
	 */
	private static function offer_one( $ticket_type_id, $require_capacity ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$table = $p . 'wpcap_waitlist';
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new \WP_Error( 'wpcap_db_busy', __( 'Gerade ist sehr viel los. Bitte versuche es in einem Moment erneut.', 'pass-captain' ), array( 'status' => 503 ) ); }
		// Locking reads first: ticket type, then its occurrence (Orders::create() lock order).
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $ticket_type_id ) );
		$occurrence = $type ? $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.status AS event_status FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d FOR UPDATE", (int) $type->occurrence_id ) ) : null;
		if ( ! $type || ! $occurrence ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_empty', __( 'Für diese Ticketart gibt es keinen wartenden Eintrag.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired',offer_token_hash='',hold_quantity=0,updated_at=%s WHERE ticket_type_id=%d AND status='offered' AND offer_expires_at<=%s", $now, $ticket_type_id, $now ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,event_id,email,position FROM $table WHERE ticket_type_id=%d AND status='waiting' ORDER BY position,waitlist_id LIMIT 1 FOR UPDATE", $ticket_type_id ) );
		if ( ! $row ) { $wpdb->query( 'COMMIT' ); return new \WP_Error( 'wpcap_waitlist_empty', __( 'Für diese Ticketart gibt es keinen wartenden Eintrag.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$sellable = 'active' === $type->status && 'scheduled' === $occurrence->status && 'published' === $occurrence->event_status && strtotime( $occurrence->starts_at . ' UTC' ) > time();
		$available = $sellable ? Orders::availability( $ticket_type_id ) : 0; // Already net of open offer holds.
		$hold = $sellable && ( null === $available || (int) $available >= 1 ) ? 1 : 0;
		if ( $require_capacity && ! $hold ) { $wpdb->query( 'COMMIT' ); return new \WP_Error( 'wpcap_waitlist_no_capacity', __( 'Derzeit ist kein Ticket frei.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$token = bin2hex( random_bytes( 32 ) );
		$expires = gmdate( 'Y-m-d H:i:s', min( time() + self::offer_hours() * HOUR_IN_SECONDS, max( time() + HOUR_IN_SECONDS, strtotime( $occurrence->starts_at . ' UTC' ) ) ) );
		$wpdb->update( $table, array( 'status' => 'offered', 'offer_token_hash' => hash( 'sha256', $token ), 'offer_expires_at' => $expires, 'hold_quantity' => $hold, 'updated_at' => $now ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'waiting' ), array( '%s', '%s', '%s', '%d', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $wpdb->rows_affected || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$manage = self::rotate_manage_token( (int) $row->waitlist_id );
		$accept_url = add_query_arg( array( 'wpcap_waitlist_accept' => (int) $row->waitlist_id, 'token' => $token ), home_url( '/' ) );
		$decline_url = add_query_arg( array( 'wpcap_waitlist_decline' => (int) $row->waitlist_id, 'token' => $token ), home_url( '/' ) );
		$sent = Mail\Mailer::send( 'waitlist_offer', $row->email, array_merge( self::mail_names( (int) $row->event_id, $ticket_type_id ), array( 'offer_expires' => wp_date( 'd.m.Y H:i T', strtotime( $expires . ' UTC' ) ), 'accept_link' => Mail\Mailer::link( $accept_url ), 'decline_link' => Mail\Mailer::link( $decline_url ), 'leave_link' => Mail\Mailer::link( self::leave_url( (int) $row->waitlist_id, $manage ) ), 'position' => (int) $row->position, 'reserved_note' => $hold ? __( 'Ein Ticket ist bis dahin für dich reserviert.', 'pass-captain' ) : __( 'Mit der Annahme wird das Ticket für dich reserviert, sofern es noch verfügbar ist.', 'pass-captain' ) ) ) );
		if ( ! $sent ) {
			$wpdb->update( $table, array( 'status' => 'waiting', 'offer_token_hash' => '', 'offer_expires_at' => null, 'hold_quantity' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'offered', 'offer_token_hash' => hash( 'sha256', $token ) ), array( '%s', '%s', '%s', '%d', '%s' ), array( '%d', '%s', '%s' ) );
			return new \WP_Error( 'wpcap_waitlist_mail', __( 'Das Angebot wurde gespeichert, aber die E-Mail konnte nicht gesendet werden.', 'pass-captain' ), array( 'status' => 502 ) );
		}
		Audit::record( 'waitlist_offer_made', 'waitlist', (int) $row->waitlist_id, array( 'ticket_type_id' => $ticket_type_id, 'position' => (int) $row->position, 'hold' => $hold, 'automatic' => $require_capacity ) );
		return array( 'waitlist_id' => (int) $row->waitlist_id, 'position' => (int) $row->position, 'expires_at' => $expires, 'inventory_reserved' => (bool) $hold );
	}

	/** New manage (unsubscribe) token for an entry; returns the plain token. */
	private static function rotate_manage_token( $waitlist_id ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'manage_token_hash' => hash( 'sha256', $token ) ), array( 'waitlist_id' => absint( $waitlist_id ) ), array( '%s' ), array( '%d' ) );
		return $token;
	}

	public static function leave_url( $waitlist_id, $token ) {
		return add_query_arg( array( 'wpcap_waitlist_leave' => absint( $waitlist_id ), 'token' => $token ), home_url( '/' ) );
	}

	/**
	 * Declines an open offer (offer token); the hold goes to the next entry.
	 *
	 * @return array|\WP_Error
	 */
	public static function decline_offer( $waitlist_id, $token ) {
		global $wpdb;
		$row = self::entry_with_token( $waitlist_id, $token, 'offer' );
		if ( ! $row || 'offered' !== $row->status ) { return self::invalid_offer(); }
		$changed = $wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'status' => 'declined', 'offer_token_hash' => '', 'hold_quantity' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'offered' ), array( '%s', '%s', '%d', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $changed ) { return self::invalid_offer(); }
		Audit::record( 'waitlist_offer_declined', 'waitlist', (int) $row->waitlist_id, array( 'ticket_type_id' => (int) $row->ticket_type_id ) );
		self::queue( array( (int) $row->ticket_type_id ) );
		return array( 'status' => 'declined' );
	}

	/**
	 * Unsubscribes an entry (manage token from the sign-up/offer mail, or an offer token).
	 *
	 * @return array|\WP_Error
	 */
	public static function unsubscribe( $waitlist_id, $token ) {
		global $wpdb;
		$row = self::entry_with_token( $waitlist_id, $token, 'any' );
		if ( ! $row || ! in_array( $row->status, array( 'waiting', 'offered' ), true ) ) { return self::invalid_offer(); }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='unsubscribed',offer_token_hash='',manage_token_hash='',hold_quantity=0,updated_at=%s WHERE waitlist_id=%d AND status IN ('waiting','offered')", current_time( 'mysql', true ), (int) $row->waitlist_id ) );
		if ( 1 !== (int) $changed ) { return self::invalid_offer(); }
		Audit::record( 'waitlist_unsubscribed', 'waitlist', (int) $row->waitlist_id, array( 'ticket_type_id' => (int) $row->ticket_type_id, 'was' => $row->status ) );
		if ( 'offered' === $row->status ) { self::queue( array( (int) $row->ticket_type_id ) ); }
		return array( 'status' => 'unsubscribed' );
	}

	/** Entry whose offer token ($kind offer) or manage/offer token ($kind any) matches. */
	private static function entry_with_token( $waitlist_id, $token, $kind ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE waitlist_id=%d", absint( $waitlist_id ) ) );
		if ( ! $row ) { return null; }
		$hash = hash( 'sha256', $token );
		$offer = '' !== (string) $row->offer_token_hash && hash_equals( (string) $row->offer_token_hash, $hash );
		$manage = 'any' === $kind && '' !== (string) $row->manage_token_hash && hash_equals( (string) $row->manage_token_hash, $hash );
		return $offer || $manage ? $row : null;
	}

	/** Expired offers release their hold and the next entry is offered (job worker run). @return int Expired offers. */
	public static function sweep( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$now = current_time( 'mysql', true );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT waitlist_id,ticket_type_id FROM $table WHERE status='offered' AND offer_expires_at<=%s LIMIT %d", $now, absint( $limit ) ) );
		$types = array();
		$expired = 0;
		foreach ( (array) $rows as $row ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired',offer_token_hash='',hold_quantity=0,updated_at=%s WHERE waitlist_id=%d AND status='offered' AND offer_expires_at<=%s", $now, (int) $row->waitlist_id, $now ) );
			if ( 1 === (int) $changed ) {
				++$expired;
				$types[] = (int) $row->ticket_type_id;
				Audit::record( 'waitlist_offer_expired', 'waitlist', (int) $row->waitlist_id, array( 'ticket_type_id' => (int) $row->ticket_type_id ) );
			}
		}
		// Expired offers and capacity raised by staff: offer what is free now.
		$waiting = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM $table WHERE status='waiting' LIMIT %d", absint( $limit ) ) ) );
		foreach ( array_unique( array_merge( $types, $waiting ) ) as $type_id ) { self::auto_offer( $type_id ); }
		return $expired;
	}

	private static function invalid_offer() {
		return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	public static function join( $event_id, $occurrence_id, $ticket_type_id, $email ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$occurrence_id = absint( $occurrence_id );
		$ticket_type_id = absint( $ticket_type_id );
		$email = sanitize_email( $email );
		if ( ! $event_id || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_waitlist_invalid', __( 'Veranstaltung oder E-Mail-Adresse ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$rate_key = 'wpcap_waitlist_' . hash( 'sha256', $email . '|' . $event_id );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) { return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_key = 'wpcap_waitlist_ip_' . hash( 'sha256', $ip . '|' . $event_id );
		$ip_attempts = (int) get_transient( $ip_key );
		if ( $ip_attempts >= 20 ) { return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $ip_key, $ip_attempts + 1, HOUR_IN_SECONDS );
		$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.event_id=%d AND e.status='published' AND o.occurrence_id=%d AND o.status='scheduled'", $event_id, $occurrence_id ) );
		if ( ! $valid ) { return new \WP_Error( 'wpcap_waitlist_event', __( 'Diese Veranstaltung ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d AND occurrence_id=%d AND status='active'", $ticket_type_id, $occurrence_id ) );
		if ( ! $type ) { return new \WP_Error( 'wpcap_waitlist_ticket', __( 'Diese Ticketart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired',offer_token_hash='',hold_quantity=0,updated_at=%s WHERE event_id=%d AND ticket_type_id=%d AND status='offered' AND offer_expires_at<=%s", current_time( 'mysql', true ), absint( $event_id ), absint( $ticket_type_id ), current_time( 'mysql', true ) ) );
		$email_hash = hash( 'sha256', strtolower( $email ) );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d FOR UPDATE", $event_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,status,position FROM $table WHERE event_id=%d AND ticket_type_id=%d AND email_hash=%s", $event_id, $ticket_type_id, $email_hash ) );
		if ( $existing ) {
			$wpdb->query( 'COMMIT' );
			// Same response as a new entry so the endpoint does not reveal who is on a waitlist.
			return self::join_response();
		}
		$position = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $table WHERE event_id=%d AND ticket_type_id=%d", $event_id, $ticket_type_id ) );
		$manage = bin2hex( random_bytes( 32 ) );
		$wpdb->insert( $table, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email_hash' => $email_hash, 'email' => $email, 'position' => $position, 'status' => 'waiting', 'manage_token_hash' => hash( 'sha256', $manage ), 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_waitlist_join', __( 'Der Wartelistenplatz konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		$waitlist_id = (int) $wpdb->insert_id;
		Mail\Mailer::send( 'waitlist_joined', $email, array_merge( self::mail_names( $event_id, $ticket_type_id ), array( 'position' => (int) $position, 'leave_link' => Mail\Mailer::link( self::leave_url( $waitlist_id, $manage ) ) ) ) );
		// A ticket may already be free (e.g. released while the person was signing up).
		self::queue( array( $ticket_type_id ) );
		return self::join_response();
	}

	/** Event and ticket names for waitlist mail placeholders. */
	private static function mail_names( $event_id, $ticket_type_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT e.name AS event_name,tt.name AS ticket_name FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND e.event_id=%d", absint( $ticket_type_id ), absint( $event_id ) ) );
		return array( 'event_name' => (string) ( $row->event_name ?? '' ), 'ticket_name' => (string) ( $row->ticket_name ?? '' ) );
	}

	/** Uniform public response for waitlist sign-ups. */
	private static function join_response() {
		return array( 'status' => 'received', 'message' => __( 'Danke! Falls die Adresse noch nicht eingetragen war, bekommst du eine Bestätigung per E-Mail.', 'pass-captain' ) );
	}

	/**
	 * Manual offer (staff) to the next waiting entry of a ticket type. If a ticket is free it is held
	 * for the offer (inventory_reserved true); otherwise the offer can only be accepted if a ticket
	 * is free at that time.
	 */
	public static function offer_next( $event_id, $ticket_type_id ) {
		global $wpdb;
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", absint( $ticket_type_id ) ) );
		if ( $owner !== absint( $event_id ) ) { return new \WP_Error( 'wpcap_waitlist_empty', __( 'Für diese Ticketart gibt es keinen wartenden Eintrag.', 'pass-captain' ), array( 'status' => 404 ) ); }
		return self::offer_one( absint( $ticket_type_id ), false );
	}

	/**
	 * Entries of an event (optionally one occurrence) in queue order: ticket type, then position.
	 * Expired offers are marked first so the list shows the real state.
	 */
	public static function entries( $event_id, $occurrence_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired',offer_token_hash='',hold_quantity=0,updated_at=%s WHERE event_id=%d AND status='offered' AND offer_expires_at<=%s", $now, absint( $event_id ), $now ) );
		$where = $occurrence_id ? $wpdb->prepare( ' AND w.occurrence_id=%d', absint( $occurrence_id ) ) : '';
		return $wpdb->get_results( $wpdb->prepare( "SELECT w.waitlist_id,w.event_id,w.occurrence_id,w.ticket_type_id,w.email,w.position,w.status,w.offer_expires_at,w.hold_quantity,w.order_id,w.created_at,tt.name AS ticket_name,o.starts_at,o.timezone FROM $table w LEFT JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id LEFT JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=w.occurrence_id WHERE w.event_id=%d", absint( $event_id ) ) . $where . ' ORDER BY o.starts_at,w.occurrence_id,w.ticket_type_id,w.position,w.waitlist_id LIMIT 2000' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared.
	}

	/**
	 * Removes an entry from the waitlist (the person may sign up again later). Audited without the address.
	 *
	 * @return array|\WP_Error Removed entry ids.
	 */
	public static function remove( $waitlist_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,event_id,ticket_type_id,position,status FROM $table WHERE waitlist_id=%d", absint( $waitlist_id ) ) );
		if ( ! $row ) { return new \WP_Error( 'wpcap_waitlist_missing', __( 'Wartelisteneintrag nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->delete( $table, array( 'waitlist_id' => (int) $row->waitlist_id ), array( '%d' ) );
		if ( 'offered' === $row->status ) { self::queue( array( (int) $row->ticket_type_id ) ); }
		Audit::record( 'waitlist_entry_removed', 'waitlist', (int) $row->waitlist_id, array( 'event_id' => (int) $row->event_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'position' => (int) $row->position, 'status' => $row->status ) );
		return array( 'waitlist_id' => (int) $row->waitlist_id, 'event_id' => (int) $row->event_id );
	}

	public static function maybe_render_offer_page() {
		$waitlist_id = absint( $_GET['wpcap_waitlist_accept'] ?? 0 );
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( ! $waitlist_id || 64 !== strlen( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		status_header( 200 );
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Wartelistenangebot annehmen', 'pass-captain' ); ?></title></head><body><main><h1><?php esc_html_e( 'Wartelistenangebot annehmen', 'pass-captain' ); ?></h1><p><?php esc_html_e( 'Mit der Annahme wird eine Bestellung angelegt und das Ticket reserviert, sofern es noch verfügbar ist.', 'pass-captain' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'wpcap_accept_waitlist_' . $waitlist_id ); ?><input type="hidden" name="action" value="wpcap_accept_waitlist"><input type="hidden" name="waitlist_id" value="<?php echo esc_attr( $waitlist_id ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><button type="submit"><?php esc_html_e( 'Angebot annehmen', 'pass-captain' ); ?></button></form></main></body></html><?php
		exit;
	}

	public static function handle_accept_request() {
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		check_admin_referer( 'wpcap_accept_waitlist_' . $waitlist_id );
		$result = self::accept_offer( $waitlist_id, wp_unslash( $_POST['token'] ?? '' ) );
		status_header( is_wp_error( $result ) ? 409 : 200 );
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Warteliste – %s', 'pass-captain' ), Orders::site_name() ) ); ?></title></head><body><main><?php if ( is_wp_error( $result ) ) : ?><h1><?php esc_html_e( 'Angebot nicht verfügbar', 'pass-captain' ); ?></h1><p><?php echo esc_html( $result->get_error_message() ); ?></p><?php else : ?><h1><?php esc_html_e( 'Angebot angenommen', 'pass-captain' ); ?></h1><p><?php echo esc_html( 'complete' === ( $result['order']['status'] ?? '' ) ? __( 'Deine Bestellung ist abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'pass-captain' ) : __( 'Dein Ticket ist reserviert und die Bestellung wartet auf die Zahlung. Den Link zu deiner Bestellung und die Zahlungsinformationen haben wir dir per E-Mail gesendet.', 'pass-captain' ) ); ?></p><?php endif; ?></main></body></html><?php
		exit;
	}

	/**
	 * Accepts an offer: creates the order (the offer's own hold is consumed, other holds stay
	 * protected) and only then marks the entry accepted. Repeated or parallel acceptances of the same
	 * offer reuse the same order (idempotency key = waitlist id); a failed order leaves the offer open
	 * until it expires.
	 */
	public static function accept_offer( $waitlist_id, $token ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { return self::invalid_offer(); }
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,email,ticket_type_id,offer_token_hash,offer_expires_at,status FROM $table WHERE waitlist_id=%d", absint( $waitlist_id ) ) );
		if ( ! $row || 'offered' !== $row->status || $row->offer_expires_at <= current_time( 'mysql', true ) || ! hash_equals( (string) $row->offer_token_hash, hash( 'sha256', $token ) ) ) { return self::invalid_offer(); }
		self::$accepting = (int) $row->waitlist_id;
		try {
			$order = Orders::create(
				array( 'email' => $row->email, 'items' => array( array( 'ticket_type_id' => (int) $row->ticket_type_id, 'quantity' => 1 ) ), 'idempotency_key' => (string) (int) $row->waitlist_id, 'accept_terms' => 1 ),
				array( 'key_namespace' => 'waitlist', 'skip_rate_limit' => true, 'source' => 'waitlist' )
			);
		} finally {
			self::$accepting = 0;
		}
		if ( is_wp_error( $order ) ) {
			// Keep the offer open until it expires so the person can try again.
			return new \WP_Error( 'wpcap_waitlist_sold_out', $order->get_error_message(), array( 'status' => 409 ) );
		}
		$updated = $wpdb->update( $table, array( 'status' => 'accepted', 'offer_token_hash' => '', 'hold_quantity' => 0, 'order_id' => (int) $order['order_id'], 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'offered' ), array( '%s', '%s', '%d', '%d', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $updated ) {
			// A parallel acceptance of the same offer won: same order, reported once.
			return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot wurde bereits angenommen.', 'pass-captain' ), array( 'status' => 409, 'order_id' => (int) $order['order_id'] ) );
		}
		Audit::record( 'waitlist_offer_accepted', 'waitlist', (int) $row->waitlist_id, array( 'order_id' => (int) $order['order_id'] ) );
		return array( 'status' => 'accepted', 'order' => $order, 'inventory_reserved' => 'pending' === ( $order['payment_status'] ?? '' ) );
	}
}
