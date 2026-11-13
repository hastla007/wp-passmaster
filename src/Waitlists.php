<?php
/**
 * Waitlist per event/occurrence/ticket type with automatic offers that hold inventory.
 *
 * Lifecycle of an entry: waiting → offered → accepted → paid, or expired | declined | skipped |
 * removed. An offer creates a pending placeholder order (payment_provider `waitlist_offer`) whose
 * reservation holds the offered quantity until the offer expires, so core capacity counts it and
 * public buyers cannot take the unit. Accepting assigns a payment method to that order (free
 * orders are confirmed immediately); expiry, cancellation or a lapsed payment hold mark the entry
 * expired and the freed units are offered to the next entries. See docs/WAITLIST.md.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Waitlists {
	/** Payment provider id of placeholder orders that hold an open offer. */
	const OFFER_PROVIDER = 'waitlist_offer';

	/** Cron hook that processes queued ticket types. */
	const HOOK = 'wpcap_waitlist_process';

	/** Option with ticket types waiting for processing (type id => due timestamp). */
	const QUEUE_OPTION = 'wpcap_waitlist_queue';

	/** Entry states. Terminal: paid, expired, declined, skipped, removed. */
	const STATUSES = array( 'waiting', 'offered', 'accepted', 'paid', 'expired', 'declined', 'skipped', 'removed' );

	/** States in which an entry holds inventory through its order. */
	const OPEN_OFFER = array( 'offered', 'accepted' );

	/** Hooks (called by Modules\Waitlist::register()). */
	public static function register() {
		add_action( 'wpcap_capacity_released', array( __CLASS__, 'on_capacity_released' ), 10, 3 );
		add_action( 'wpcap_capacity_increased', array( __CLASS__, 'on_capacity_increased' ), 10, 2 );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_order_paid' ), 10, 1 );
		add_action( self::HOOK, array( __CLASS__, 'run_due' ), 10, 0 );
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'run_due' ), 20, 0 );
		add_action( 'wpcap_cleanup_reservations', array( __CLASS__, 'sweep' ), 30, 0 );
		add_filter( 'wpcap_privacy_retention_steps', array( __CLASS__, 'retention_step' ), 10, 3 );
	}

	/**
	 * Retention (wpcap_privacy_retention_steps): waitlist entries are anonymised once their date (or,
	 * for event-wide entries, the last date of the event) ended more than `waitlist` days ago
	 * (default 30, filter wpcap_privacy_retention_days): e-mail emptied, e-mail hash replaced, offer
	 * token removed; status and counts stay. Open offers are left alone.
	 */
	public static function retention_step( $counts, $now, $deadline ) {
		global $wpdb;
		if ( microtime( true ) >= $deadline ) { return $counts; }
		$days = max( 0, (int) apply_filters( 'wpcap_privacy_retention_days', 30, 'waitlist' ) );
		if ( ! $days ) { $counts['waitlist'] = 0; return $counts; }
		$p = $wpdb->prefix;
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $now - $days * DAY_IN_SECONDS );
		$end = 'COALESCE(o.ends_at, DATE_ADD(o.starts_at, INTERVAL 1 DAY))';
		$counts['waitlist'] = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_waitlist w SET w.email='',w.email_hash=SHA2(CONCAT('deleted|',w.waitlist_id),256),w.offer_token_hash='',w.updated_at=%s WHERE w.email<>'' AND w.status<>'offered' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_occurrences o WHERE ( o.occurrence_id=w.occurrence_id OR ( w.occurrence_id=0 AND o.event_id=w.event_id ) ) AND $end>=%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static SQL.
			gmdate( 'Y-m-d H:i:s', (int) $now ),
			$cutoff
		) );
		return $counts;
	}

	/** Settings merged with defaults: auto_offers, offer_hours, max_offers_per_run, strict_fifo. */
	public static function config() {
		$defaults = array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0 );
		$stored = Settings::get( 'waitlist' );
		$c = array_merge( $defaults, is_array( $stored ) ? $stored : array() );
		$c['auto_offers'] = (int) ! empty( $c['auto_offers'] );
		$c['strict_fifo'] = (int) ! empty( $c['strict_fifo'] );
		$c['offer_hours'] = max( 1, min( 336, (int) $c['offer_hours'] ) );
		$c['max_offers_per_run'] = max( 1, min( 100, (int) $c['max_offers_per_run'] ) );
		return $c;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Joining.
	 * ------------------------------------------------------------------------------------------- */

	public static function join( $event_id, $occurrence_id, $ticket_type_id, $email, $quantity = 1 ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$occurrence_id = absint( $occurrence_id );
		$ticket_type_id = absint( $ticket_type_id );
		$quantity = max( 1, absint( $quantity ) );
		$email = sanitize_email( $email );
		if ( ! $event_id || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_waitlist_invalid', __( 'Veranstaltung oder E-Mail-Adresse ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$rate_key = 'wpcap_waitlist_' . hash( 'sha256', $email . '|' . $event_id );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) { return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_key = 'wpcap_waitlist_ip_' . hash( 'sha256', $ip . '|' . $event_id );
		$ip_attempts = (int) get_transient( $ip_key );
		if ( $ip_attempts >= 20 ) { return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) ); }
		set_transient( $ip_key, $ip_attempts + 1, HOUR_IN_SECONDS );
		$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.event_id=%d AND e.status='published' AND o.occurrence_id=%d AND o.status='scheduled'", $event_id, $occurrence_id ) );
		if ( ! $valid ) { return new \WP_Error( 'wpcap_waitlist_event', __( 'Diese Veranstaltung ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_type_id,max_per_order FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d AND occurrence_id=%d AND status='active'", $ticket_type_id, $occurrence_id ) );
		if ( ! $type ) { return new \WP_Error( 'wpcap_waitlist_ticket', __( 'Diese Ticketart ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		if ( $quantity > max( 1, (int) $type->max_per_order ) ) {
			/* translators: %d: maximum number of tickets per order */
			return new \WP_Error( 'wpcap_waitlist_quantity', sprintf( _n( 'Auf der Warteliste ist höchstens %d Ticket pro Eintrag möglich.', 'Auf der Warteliste sind höchstens %d Tickets pro Eintrag möglich.', max( 1, (int) $type->max_per_order ), 'wp-pass-captain' ), max( 1, (int) $type->max_per_order )), array( 'status' => 400 ) );
		}
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$now = current_time( 'mysql', true );
		$email_hash = hash( 'sha256', strtolower( $email ) );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d FOR UPDATE", $event_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,status FROM $table WHERE event_id=%d AND ticket_type_id=%d AND email_hash=%s FOR UPDATE", $event_id, $ticket_type_id, $email_hash ) );
		$position = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $table WHERE event_id=%d AND ticket_type_id=%d", $event_id, $ticket_type_id ) );
		if ( $existing ) {
			// Finished entries (offer expired/declined, earlier purchase) may join again at the end of the
			// queue; open entries and entries skipped/removed by staff stay as they are. Same response
			// either way so the endpoint does not reveal who is on a waitlist.
			if ( ! in_array( $existing->status, array( 'expired', 'declined', 'paid' ), true ) ) {
				$wpdb->query( 'COMMIT' );
				return self::join_response();
			}
			$wpdb->update( $table, array( 'occurrence_id' => $occurrence_id, 'email' => $email, 'position' => $position, 'quantity' => $quantity, 'priority' => 0, 'status' => 'waiting', 'offer_token_hash' => '', 'offer_expires_at' => null, 'offered_at' => null, 'order_id' => 0, 'created_at' => $now, 'updated_at' => $now ), array( 'waitlist_id' => (int) $existing->waitlist_id ) );
			if ( '' !== $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
		} else {
			$wpdb->insert( $table, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email_hash' => $email_hash, 'email' => $email, 'position' => $position, 'quantity' => $quantity, 'status' => 'waiting', 'created_at' => $now, 'updated_at' => $now ) );
			if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
		}
		$wpdb->query( 'COMMIT' );
		Mail\Mailer::send( 'waitlist_joined', $email, array_merge( self::mail_names( $event_id, $ticket_type_id ), array( 'position' => (int) $position, 'quantity' => $quantity ) ) );
		// Units may already be free (e.g. a hold just lapsed): let the next run offer them.
		self::queue( array( $ticket_type_id ) );
		return self::join_response();
	}

	private static function join_failed() {
		return new \WP_Error( 'wpcap_waitlist_join', __( 'Der Wartelistenplatz konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
	}

	/** Event and ticket names for waitlist mail placeholders. */
	private static function mail_names( $event_id, $ticket_type_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT e.name AS event_name,tt.name AS ticket_name FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND e.event_id=%d", absint( $ticket_type_id ), absint( $event_id ) ) );
		return array( 'event_name' => (string) ( $row->event_name ?? '' ), 'ticket_name' => (string) ( $row->ticket_name ?? '' ) );
	}

	/** Uniform public response for waitlist sign-ups. */
	private static function join_response() {
		return array( 'status' => 'received', 'message' => __( 'Danke! Falls die Adresse noch nicht eingetragen war, bekommst du eine Bestätigung per E-Mail.', 'wp-pass-captain' ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Queue processing.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Marks ticket types for processing after a short delay (filter `wpcap_waitlist_process_delay`,
	 * seconds, default 60), so several releases in a row are offered in one run.
	 *
	 * @param int[]    $ticket_type_ids Ticket types.
	 * @param int|null $due             Timestamp; default now + delay.
	 */
	public static function queue( array $ticket_type_ids, $due = null ) {
		$ids = array_filter( array_map( 'absint', $ticket_type_ids ) );
		if ( ! $ids ) { return; }
		$due = null === $due ? time() + max( 0, (int) apply_filters( 'wpcap_waitlist_process_delay', 60 ) ) : (int) $due;
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		foreach ( $ids as $id ) {
			$queue[ $id ] = isset( $queue[ $id ] ) ? min( (int) $queue[ $id ], $due ) : $due;
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $due, self::HOOK );
		}
	}

	/** Ticket types currently queued (type id => due timestamp). */
	public static function queued() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? array_map( 'intval', $queue ) : array();
	}

	/**
	 * Cron: syncs lapsed offers, then processes every queued ticket type that is due.
	 *
	 * @param bool $force Process all queued types regardless of their due time (admin "now", tests).
	 * @return int Number of offers made.
	 */
	public static function run_due( $force = false ) {
		self::sync_lapsed();
		$queue = self::queued();
		if ( ! $queue ) { return 0; }
		$now = time();
		$force = true === $force; // A TimeBudget continuation passes "continue": not a forced run.
		$due = array_keys( array_filter( $queue, static function ( $ts ) use ( $now, $force ) { return $force || $ts <= $now; } ) );
		if ( ! $due ) { return 0; }
		// Remove before processing: releases during the run (e.g. a failed mail) queue the type again.
		update_option( self::QUEUE_OPTION, array_diff_key( $queue, array_flip( $due ) ), false );
		$made = 0;
		// Shared cron time budget: types not reached go back into the queue and a follow-up run starts.
		$deadline = Jobs\TimeBudget::deadline();
		foreach ( array_values( $due ) as $i => $type_id ) {
			if ( Jobs\TimeBudget::exhausted( $deadline ) ) {
				self::queue( array_slice( $due, $i ), $now );
				Jobs\TimeBudget::continue_later( self::HOOK );
				break;
			}
			$made += self::process_type( (int) $type_id );
		}
		return $made;
	}

	/** Hourly: queue every ticket type with waiting entries (catches capacity increases), then run. */
	public static function sweep() {
		global $wpdb;
		$p = $wpdb->prefix;
		$types = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT w.ticket_type_id FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE w.status='waiting' AND o.status='scheduled' AND o.starts_at > %s LIMIT 500", current_time( 'mysql', true ) ) );
		if ( $types ) { self::queue( $types, time() ); }
		return self::run_due();
	}

	/**
	 * Offers freed units of one ticket type to the next waiting entries whose quantity fits.
	 *
	 * Order: priority (high first), then position (FIFO). Entries that need more units than are free
	 * are passed over unless strict FIFO is configured. Every offer re-checks capacity under the
	 * order locks (Orders::create), so a concurrent run can never offer the same unit twice.
	 *
	 * @param bool $manual Run even when automatic offers are disabled.
	 * @return int Offers made.
	 */
	public static function process_type( $ticket_type_id, $manual = false ) {
		global $wpdb;
		$ticket_type_id = absint( $ticket_type_id );
		$c = self::config();
		if ( ! $ticket_type_id || ( ! $c['auto_offers'] && ! $manual ) ) { return 0; }
		$lock = substr( $wpdb->prefix . 'wpcap_wl_' . $ticket_type_id, 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 0; }
		$made = 0;
		try {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.max_per_order FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND tt.status='active' AND o.status='scheduled' AND e.status='published' AND o.starts_at > %s", $ticket_type_id, current_time( 'mysql', true ) ) );
			if ( ! $type ) { return 0; }
			$available = Orders::availability( $ticket_type_id );
			$available = null === $available ? PHP_INT_MAX : (int) $available;
			if ( $available <= 0 ) { return 0; }
			$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE ticket_type_id=%d AND status='waiting' ORDER BY priority DESC,position,waitlist_id LIMIT 200", $ticket_type_id ) );
			foreach ( $entries as $entry ) {
				if ( $made >= $c['max_offers_per_run'] || $available <= 0 ) { break; }
				$quantity = max( 1, (int) $entry->quantity );
				if ( $quantity > max( 1, (int) $type->max_per_order ) ) { continue; }
				if ( $quantity > $available ) {
					if ( $c['strict_fifo'] ) { break; }
					continue;
				}
				$result = self::offer_entry( $entry );
				if ( is_wp_error( $result ) ) {
					// Capacity/sales state changed under the lock: stop; a mail failure only skips this entry.
					if ( 'wpcap_waitlist_mail' === $result->get_error_code() ) { continue; }
					break;
				}
				$available -= $quantity;
				++$made;
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $made;
	}

	/**
	 * Offers the next waiting entry of a ticket type (staff action / REST). Inventory is held.
	 *
	 * @return array|\WP_Error
	 */
	public static function offer_next( $event_id, $ticket_type_id ) {
		global $wpdb;
		self::sync_lapsed();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE event_id=%d AND ticket_type_id=%d AND status='waiting' ORDER BY priority DESC,position,waitlist_id LIMIT 1", absint( $event_id ), absint( $ticket_type_id ) ) );
		if ( ! $row ) { return new \WP_Error( 'wpcap_waitlist_empty', __( 'Für diese Ticketart gibt es keinen wartenden Eintrag.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		return self::offer_entry( $row );
	}

	/** Offers one entry by id (staff action). */
	public static function offer( $waitlist_id ) {
		$row = self::get( $waitlist_id );
		if ( ! $row || 'waiting' !== $row->status ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Dieser Eintrag wartet nicht (mehr) auf ein Angebot.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		return self::offer_entry( $row );
	}

	/**
	 * Creates the offer: claims the entry, creates the placeholder order with a hold for the requested
	 * quantity until the offer expires, and mails the accept link.
	 *
	 * @return array|\WP_Error
	 */
	private static function offer_entry( $row ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$c = self::config();
		$now_ts = time();
		$expires_ts = $now_ts + $c['offer_hours'] * HOUR_IN_SECONDS;
		$starts = (string) $wpdb->get_var( $wpdb->prepare( "SELECT o.starts_at FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", (int) $row->ticket_type_id ) );
		if ( '' !== $starts ) { $expires_ts = min( $expires_ts, (int) strtotime( $starts . ' UTC' ) ); }
		if ( $expires_ts - $now_ts < 15 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'wpcap_waitlist_too_late', __( 'Für diesen Termin ist kein Wartelistenangebot mehr möglich.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$hash = hash( 'sha256', $token );
		$now = current_time( 'mysql', true );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='offered',offer_token_hash=%s,offer_expires_at=%s,offered_at=%s,order_id=0,updated_at=%s WHERE waitlist_id=%d AND status='waiting'", $hash, gmdate( 'Y-m-d H:i:s', $expires_ts ), $now, $now, (int) $row->waitlist_id ) );
		if ( 1 !== (int) $claimed ) { return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$quantity = max( 1, (int) $row->quantity );
		$order = Orders::create(
			array( 'email' => $row->email, 'items' => array( array( 'ticket_type_id' => (int) $row->ticket_type_id, 'quantity' => $quantity ) ), 'idempotency_key' => 'offer-' . (int) $row->waitlist_id . '-' . substr( $hash, 0, 16 ) ),
			array( 'external' => true, 'payment_provider' => self::OFFER_PROVIDER, 'hold_minutes' => (int) ceil( ( $expires_ts - $now_ts ) / MINUTE_IN_SECONDS ), 'key_namespace' => 'waitlist', 'skip_rate_limit' => true, 'source' => 'waitlist' )
		);
		if ( is_wp_error( $order ) ) {
			self::revert_claim( (int) $row->waitlist_id, $hash );
			return new \WP_Error( 'wpcap_waitlist_unavailable', $order->get_error_message(), array( 'status' => 409 ) );
		}
		$order_id = (int) $order['order_id'];
		$held_until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active'", $order_id ) );
		$expires = '' !== $held_until ? $held_until : gmdate( 'Y-m-d H:i:s', $expires_ts );
		$wpdb->update( $table, array( 'order_id' => $order_id, 'offer_expires_at' => $expires ), array( 'waitlist_id' => (int) $row->waitlist_id, 'offer_token_hash' => $hash ) );
		$accept_url = add_query_arg( array( 'wpcap_waitlist_accept' => (int) $row->waitlist_id, 'token' => $token ), home_url( '/' ) );
		$sent = Mail\Mailer::send( 'waitlist_offer', $row->email, array_merge( self::mail_names( $row->event_id, $row->ticket_type_id ), array( 'offer_expires' => wp_date( 'd.m.Y H:i T', strtotime( $expires . ' UTC' ) ), 'accept_link' => Mail\Mailer::link( $accept_url ), 'position' => (int) $row->position, 'quantity' => $quantity ) ) );
		if ( ! $sent ) {
			// Back to the queue first, so the release below does not mark the entry expired.
			self::revert_claim( (int) $row->waitlist_id, $hash );
			Orders::cancel_order( $order_id, 'waitlist_offer_mail_failed' );
			return new \WP_Error( 'wpcap_waitlist_mail', __( 'Das Angebot konnte nicht per E-Mail gesendet werden und wurde zurückgenommen.', 'wp-pass-captain' ), array( 'status' => 502 ) );
		}
		// Process the queue again right after the deadline (the hold lapses then).
		self::queue( array( (int) $row->ticket_type_id ), (int) strtotime( $expires . ' UTC' ) + MINUTE_IN_SECONDS );
		Audit::record( 'waitlist_offered', 'waitlist', (int) $row->waitlist_id, array( 'order_id' => $order_id, 'ticket_type_id' => (int) $row->ticket_type_id, 'quantity' => $quantity, 'expires_at' => $expires ) );
		do_action( 'wpcap_waitlist_offered', (int) $row->waitlist_id, $order_id );
		return array( 'waitlist_id' => (int) $row->waitlist_id, 'position' => (int) $row->position, 'quantity' => $quantity, 'order_id' => $order_id, 'expires_at' => $expires, 'inventory_reserved' => true );
	}

	private static function revert_claim( $waitlist_id, $hash ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='waiting',offer_token_hash='',offer_expires_at=NULL,offered_at=NULL,order_id=0,updated_at=%s WHERE waitlist_id=%d AND status='offered' AND offer_token_hash=%s", current_time( 'mysql', true ), absint( $waitlist_id ), $hash ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Status sync with orders.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * `wpcap_capacity_released`: an open offer whose order stopped holding inventory (hold expired,
	 * cancelled, unallocated payment) is expired; the freed units are queued for the waitlist of every
	 * ticket type of the same occurrence (occurrence capacity is shared).
	 */
	public static function on_capacity_released( $order_id, $reason = '', $details = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		if ( ! $order_id ) { return; }
		$closed = self::close_offers_of_order( $order_id );
		if ( 'event_cancelled' === $reason ) { return; }
		$type_ids = ! empty( $details['ticket_type_id'] ) ? array( absint( $details['ticket_type_id'] ) ) : array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$p}wpcap_order_items WHERE order_id=%d", $order_id ) ) );
		$type_ids = array_merge( $type_ids, $closed );
		self::queue_occurrences_of( $type_ids );
	}

	/** `wpcap_capacity_increased` (ticket type id and/or occurrence id): offer the new units. */
	public static function on_capacity_increased( $ticket_type_id = 0, $occurrence_id = 0 ) {
		global $wpdb;
		$types = $ticket_type_id ? array( absint( $ticket_type_id ) ) : array();
		if ( $occurrence_id ) {
			$types = array_merge( $types, array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d", absint( $occurrence_id ) ) ) ) );
		}
		self::queue_occurrences_of( $types );
	}

	/** Queues every ticket type with waiting entries that shares an occurrence with the given types. */
	private static function queue_occurrences_of( array $type_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type_ids = array_values( array_unique( array_filter( array_map( 'absint', $type_ids ) ) ) );
		if ( ! $type_ids ) { return; }
		$in = implode( ',', $type_ids );
		$waiting = $wpdb->get_col( "SELECT DISTINCT w.ticket_type_id FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id WHERE w.status='waiting' AND tt.occurrence_id IN (SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN ($in))" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( $waiting ) { self::queue( $waiting ); }
	}

	/** Marks open offers of an order expired. Returns their ticket type ids. */
	private static function close_offers_of_order( $order_id, $status = 'expired' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT waitlist_id,ticket_type_id FROM $table WHERE order_id=%d AND status IN ('offered','accepted')", absint( $order_id ) ) );
		$types = array();
		foreach ( $rows as $row ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status=%s,offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status IN ('offered','accepted')", $status, current_time( 'mysql', true ), (int) $row->waitlist_id ) );
			if ( 1 === (int) $changed ) {
				$types[] = (int) $row->ticket_type_id;
				Audit::record( 'waitlist_' . $status, 'waitlist', (int) $row->waitlist_id, array( 'order_id' => absint( $order_id ) ) );
			}
		}
		return $types;
	}

	/** `wpcap_order_paid`: the entry of a waitlist order is done. */
	public static function on_order_paid( $order_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='paid',offer_token_hash='',updated_at=%s WHERE order_id=%d AND status IN ('offered','accepted')", current_time( 'mysql', true ), absint( $order_id ) ) );
	}

	/**
	 * Reconciles open offers with their orders: lapsed holds are expired through the core cleanup
	 * (which fires `wpcap_capacity_released`), and entries whose order is no longer pending are
	 * closed even when that hook did not reach this module.
	 */
	public static function sync_lapsed() {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_orders ord ON ord.order_id=w.order_id WHERE w.status IN ('offered','accepted') AND ord.payment_status='pending' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=w.order_id AND r.status='active' AND r.expires_at > %s)", $now ) );
		if ( $lapsed ) { Orders::expire_holds(); }
		$rows = $wpdb->get_results( "SELECT w.order_id,ord.payment_status FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_orders ord ON ord.order_id=w.order_id WHERE w.status IN ('offered','accepted') AND w.order_id>0 AND ord.payment_status <> 'pending' LIMIT 200" );
		foreach ( $rows as $row ) {
			if ( 'paid' === $row->payment_status ) {
				self::on_order_paid( (int) $row->order_id );
			} else {
				self::queue_occurrences_of( self::close_offers_of_order( (int) $row->order_id ) );
			}
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * Accept / decline.
	 * ------------------------------------------------------------------------------------------- */

	/** Entry row or null. */
	public static function get( $waitlist_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE waitlist_id=%d", absint( $waitlist_id ) ) );
	}

	/** Open offer for id + token (valid, not expired, still holding inventory) or null. */
	private static function open_offer( $waitlist_id, $token ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { return null; }
		$row = self::get( $waitlist_id );
		$now = current_time( 'mysql', true );
		if ( ! $row || 'offered' !== $row->status || ! $row->order_id || (string) $row->offer_expires_at <= $now || ! hash_equals( (string) $row->offer_token_hash, hash( 'sha256', $token ) ) ) { return null; }
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s", (int) $row->order_id, $now ) );
		return $held ? $row : null;
	}

	private static function invalid_offer() {
		return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'wp-pass-captain' ), array( 'status' => 403 ) );
	}

	/**
	 * Accepts an offer. Free orders are completed (tickets issued); paid orders get the chosen
	 * payment method and keep their hold for the payment window.
	 *
	 * @param array $args provider (payment gateway id), accept_terms (bool).
	 * @return array|\WP_Error status, order (order_id, public_id, status, payment_status, total_minor, currency), payment?, inventory_reserved.
	 */
	public static function accept_offer( $waitlist_id, $token, array $args = array() ) {
		global $wpdb;
		$row = self::open_offer( $waitlist_id, $token );
		if ( ! $row ) { return self::invalid_offer(); }
		$order = Orders::get( (int) $row->order_id );
		if ( ! $order || 'pending' !== $order->payment_status ) { return self::invalid_offer(); }
		$paid = (int) $order->total_minor > 0;
		if ( $paid && Settings::get( 'require_terms' ) && empty( $args['accept_terms'] ) ) {
			return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$gateway = null;
		if ( $paid ) {
			$enabled = Payments\Registry::enabled( $order->currency );
			$wanted = sanitize_key( $args['provider'] ?? '' );
			$gateway = $enabled[ $wanted ] ?? ( $enabled['manual'] ?? ( $enabled ? reset( $enabled ) : null ) );
			if ( ! $gateway ) { return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		}
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$now = current_time( 'mysql', true );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='accepted',offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status='offered' AND offer_token_hash=%s", $now, (int) $row->waitlist_id, (string) $row->offer_token_hash ) );
		if ( 1 !== (int) $claimed ) { return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot konnte nicht angenommen werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$order_id = (int) $order->order_id;
		$payment = null;
		if ( ! $paid ) {
			$confirmed = Orders::confirm_external_payment( $order_id, 'waitlist:' . (int) $row->waitlist_id );
			if ( is_wp_error( $confirmed ) ) {
				self::reopen( $row );
				return new \WP_Error( 'wpcap_waitlist_sold_out', $confirmed->get_error_message(), array( 'status' => 409 ) );
			}
			self::on_order_paid( $order_id );
		} else {
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payment_provider' => $gateway->id(), 'terms_version' => Settings::terms_version(), 'locale' => function_exists( 'determine_locale' ) ? determine_locale() : '', 'updated_at' => $now ), array( 'order_id' => $order_id, 'payment_status' => 'pending' ) );
			// The hold now covers the payment window of the chosen method (never shortened).
			$minutes = max( 30, (int) Settings::get( 'hold_minutes' ) );
			if ( 'manual' === $gateway->id() ) {
				// Bank transfer: never beyond the start (and not offered within manual_cutoff_hours).
				$minutes = Orders::manual_hold_minutes( array_map( static function ( $item ) { return (int) $item->ticket_type_id; }, Orders::items_for_order( $order_id ) ) );
				if ( null === $minutes ) {
					$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payment_provider' => self::OFFER_PROVIDER ), array( 'order_id' => $order_id, 'payment_status' => 'pending' ) );
					self::reopen( $row );
					return new \WP_Error( 'wpcap_manual_too_late', __( 'Eine Zahlung per Überweisung ist so kurz vor Beginn nicht mehr möglich. Bitte wähle eine andere Zahlungsart.', 'wp-pass-captain' ), array( 'status' => 400 ) );
				}
			}
			Orders::extend_hold( $order_id, $minutes );
			$fresh = (array) Orders::get( $order_id );
			$items = array_map( static function ( $item ) { return (array) $item; }, Orders::items_for_order( $order_id ) );
			$payment = $gateway->create_payment( $fresh, $items );
			if ( is_wp_error( $payment ) ) {
				$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payment_provider' => self::OFFER_PROVIDER ), array( 'order_id' => $order_id, 'payment_status' => 'pending' ) );
				self::reopen( $row );
				return new \WP_Error( $payment->get_error_code(), $payment->get_error_message(), array( 'status' => 502 ) );
			}
			if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( $order_id, $payment['provider_reference'] ); }
			$due = Payments\BankTransfer::due_at( $order_id );
			if ( '' !== $due ) { $wpdb->update( $table, array( 'offer_expires_at' => $due ), array( 'waitlist_id' => (int) $row->waitlist_id ) ); }
			// Order link (bank details, status) by mail, like a regular checkout.
			Orders::rotate_access_link( $order_id );
			$payment = array_intersect_key( $payment, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
			if ( Payments\BankTransfer::applies( $fresh ) ) { $payment['bank_transfer'] = Payments\BankTransfer::details( $fresh ); }
		}
		Audit::record( 'waitlist_accepted', 'waitlist', (int) $row->waitlist_id, array( 'order_id' => $order_id, 'provider' => $gateway ? $gateway->id() : 'free' ) );
		$order = Orders::get( $order_id );
		$result = array(
			'status' => $paid ? 'accepted' : 'paid',
			'order' => array( 'order_id' => $order_id, 'public_id' => $order->public_id, 'status' => $order->status, 'payment_status' => $order->payment_status, 'total_minor' => (int) $order->total_minor, 'currency' => $order->currency ),
			'inventory_reserved' => 'pending' === $order->payment_status,
		);
		if ( $payment ) { $result['payment'] = $payment; }
		return $result;
	}

	/** Restores an open offer after a failed accept (the hold is untouched). */
	private static function reopen( $row ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'status' => 'offered', 'offer_token_hash' => (string) $row->offer_token_hash, 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'accepted' ) );
	}

	/** Declines an open offer: the hold is released and the units go to the next entry. */
	public static function decline_offer( $waitlist_id, $token ) {
		global $wpdb;
		$row = self::open_offer( $waitlist_id, $token );
		if ( ! $row ) { return self::invalid_offer(); }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='declined',offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status='offered' AND offer_token_hash=%s", current_time( 'mysql', true ), (int) $row->waitlist_id, (string) $row->offer_token_hash ) );
		if ( 1 !== (int) $changed ) { return self::invalid_offer(); }
		Orders::cancel_order( (int) $row->order_id, 'waitlist_declined' );
		Audit::record( 'waitlist_declined', 'waitlist', (int) $row->waitlist_id, array( 'order_id' => (int) $row->order_id ) );
		return array( 'status' => 'declined' );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Staff actions.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Ends an entry by staff: `skipped` (passed over) or `removed`. An open, unpaid offer is withdrawn
	 * and its hold released, so the units go to the next entry.
	 */
	public static function close( $waitlist_id, $status = 'skipped' ) {
		global $wpdb;
		$status = 'removed' === $status ? 'removed' : 'skipped';
		$row = self::get( $waitlist_id );
		if ( ! $row || ! in_array( $row->status, array( 'waiting', 'offered', 'accepted' ), true ) ) {
			return new \WP_Error( 'wpcap_waitlist_state', __( 'Dieser Eintrag ist bereits abgeschlossen.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status=%s,offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status=%s", $status, current_time( 'mysql', true ), (int) $row->waitlist_id, $row->status ) );
		if ( 1 !== (int) $changed ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Der Eintrag wurde gerade geändert. Bitte neu laden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		if ( (int) $row->order_id && in_array( $row->status, self::OPEN_OFFER, true ) ) {
			$order = Orders::get( (int) $row->order_id );
			if ( $order && 'pending' === $order->payment_status ) { Orders::cancel_order( (int) $row->order_id, 'waitlist_' . $status ); }
		}
		Audit::record( 'waitlist_' . $status, 'waitlist', (int) $row->waitlist_id, array( 'previous' => $row->status, 'user_id' => get_current_user_id() ) );
		return array( 'status' => $status );
	}

	/** Sets the priority of an entry (higher first; position decides within one priority). */
	public static function set_priority( $waitlist_id, $priority ) {
		global $wpdb;
		$priority = max( -1000, min( 1000, (int) $priority ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'priority' => $priority, 'updated_at' => current_time( 'mysql', true ) ), array( 'waitlist_id' => absint( $waitlist_id ) ) );
		return array( 'priority' => $priority );
	}

	/** Processes all ticket types of an event now (staff action, also when auto offers are off). */
	public static function process_event_now( $event_id ) {
		global $wpdb;
		self::sync_lapsed();
		$types = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ticket_type_id FROM {$wpdb->prefix}wpcap_waitlist WHERE event_id=%d AND status='waiting'", absint( $event_id ) ) );
		$made = 0;
		foreach ( $types as $type_id ) { $made += self::process_type( (int) $type_id, true ); }
		return $made;
	}

	/**
	 * Entries of an event with queue position among the waiting entries (1-based, by priority/FIFO).
	 *
	 * @return object[]
	 */
	public static function entries( $event_id, $limit = 500 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT w.*,tt.name AS ticket_name,o.starts_at,ord.public_id AS order_public_id,ord.payment_status AS order_payment_status FROM {$p}wpcap_waitlist w LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=w.occurrence_id LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=w.order_id WHERE w.event_id=%d ORDER BY w.ticket_type_id,FIELD(w.status,'offered','accepted','waiting') DESC,w.priority DESC,w.position,w.waitlist_id LIMIT %d", absint( $event_id ), max( 1, absint( $limit ) ) ) );
		$rank = array();
		foreach ( $rows as $row ) {
			$row->queue_rank = 0;
			if ( 'waiting' === $row->status ) {
				$rank[ (int) $row->ticket_type_id ] = ( $rank[ (int) $row->ticket_type_id ] ?? 0 ) + 1;
				$row->queue_rank = $rank[ (int) $row->ticket_type_id ];
			}
		}
		return $rows;
	}

	/** Customer-facing label of an entry status. */
	public static function status_label( $status ) {
		$labels = array(
			'waiting' => __( 'Wartet', 'wp-pass-captain' ),
			'offered' => __( 'Angebot offen', 'wp-pass-captain' ),
			'accepted' => __( 'Angenommen, Zahlung offen', 'wp-pass-captain' ),
			'paid' => __( 'Bezahlt', 'wp-pass-captain' ),
			'expired' => __( 'Abgelaufen', 'wp-pass-captain' ),
			'declined' => __( 'Abgelehnt', 'wp-pass-captain' ),
			'skipped' => __( 'Übersprungen', 'wp-pass-captain' ),
			'removed' => __( 'Entfernt', 'wp-pass-captain' ),
		);
		return $labels[ $status ] ?? (string) $status;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Public offer page.
	 * ------------------------------------------------------------------------------------------- */

	public static function maybe_render_offer_page() {
		$waitlist_id = absint( $_GET['wpcap_waitlist_accept'] ?? 0 );
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( ! $waitlist_id || 64 !== strlen( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		$row = self::open_offer( $waitlist_id, $token );
		status_header( $row ? 200 : 410 );
		$order = $row ? Orders::get( (int) $row->order_id ) : null;
		$names = $row ? self::mail_names( $row->event_id, $row->ticket_type_id ) : array();
		$gateways = $order && (int) $order->total_minor > 0 ? Payments\Registry::enabled( $order->currency ) : array();
		self::page_head( __( 'Wartelistenangebot annehmen', 'wp-pass-captain' ) );
		echo '<h1>' . esc_html__( 'Wartelistenangebot annehmen', 'wp-pass-captain' ) . '</h1>';
		if ( ! $row || ! $order ) {
			echo '<p>' . esc_html__( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'wp-pass-captain' ) . '</p></main></body></html>';
			exit;
		}
		echo '<p>' . esc_html__( 'Für dich sind Tickets reserviert. Nimm das Angebot bis zum angegebenen Zeitpunkt an, sonst geht es an die nächste Person auf der Warteliste.', 'wp-pass-captain' ) . '</p><dl>';
		echo '<dt>' . esc_html__( 'Event', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( $names['event_name'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Ticketart', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( $names['ticket_name'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Anzahl', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( (string) (int) $row->quantity ) . '</dd>';
		echo '<dt>' . esc_html__( 'Preis', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( Frontend\Format::money( (int) $order->total_minor, $order->currency ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Reserviert bis', 'wp-pass-captain' ) . '</dt><dd>' . esc_html( wp_date( 'd.m.Y H:i T', strtotime( $row->offer_expires_at . ' UTC' ) ) ) . '</dd></dl>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpcap_accept_waitlist_' . $waitlist_id );
		echo '<input type="hidden" name="action" value="wpcap_accept_waitlist"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) $waitlist_id ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		if ( count( $gateways ) > 1 ) {
			echo '<fieldset><legend>' . esc_html__( 'Zahlungsart', 'wp-pass-captain' ) . '</legend>';
			$first = true;
			foreach ( $gateways as $id => $gateway ) {
				echo '<label><input type="radio" name="provider" value="' . esc_attr( $id ) . '"' . ( $first ? ' checked' : '' ) . '> ' . esc_html( $gateway->title() ) . '</label><br>';
				$first = false;
			}
			echo '</fieldset>';
		}
		if ( $gateways && Settings::get( 'require_terms' ) ) {
			echo '<p><label><input type="checkbox" name="accept_terms" value="1" required> ' . esc_html__( 'Ich akzeptiere die Bedingungen und Verbraucherinformationen.', 'wp-pass-captain' ) . '</label></p>';
		}
		echo '<p><button type="submit" name="decision" value="accept">' . esc_html__( 'Angebot annehmen', 'wp-pass-captain' ) . '</button> <button type="submit" name="decision" value="decline" formnovalidate>' . esc_html__( 'Angebot ablehnen', 'wp-pass-captain' ) . '</button></p></form></main></body></html>';
		exit;
	}

	private static function page_head( $title ) {
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo esc_html( $title ); ?></title></head><body><main><?php
	}

	public static function handle_accept_request() {
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		check_admin_referer( 'wpcap_accept_waitlist_' . $waitlist_id );
		$token = wp_unslash( $_POST['token'] ?? '' );
		$decline = 'decline' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$result = $decline ? self::decline_offer( $waitlist_id, $token ) : self::accept_offer( $waitlist_id, $token, array( 'provider' => sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) ), 'accept_terms' => ! empty( $_POST['accept_terms'] ) ) );
		nocache_headers();
		status_header( is_wp_error( $result ) ? 409 : 200 );
		/* translators: %s: site name */
		self::page_head( sprintf( __( 'Warteliste – %s', 'wp-pass-captain' ), Orders::site_name() ) );
		if ( is_wp_error( $result ) ) {
			echo '<h1>' . esc_html__( 'Angebot nicht verfügbar', 'wp-pass-captain' ) . '</h1><p>' . esc_html( $result->get_error_message() ) . '</p>';
		} elseif ( $decline ) {
			echo '<h1>' . esc_html__( 'Angebot abgelehnt', 'wp-pass-captain' ) . '</h1><p>' . esc_html__( 'Danke für deine Rückmeldung. Die Tickets gehen an die nächste Person auf der Warteliste.', 'wp-pass-captain' ) . '</p>';
		} else {
			echo '<h1>' . esc_html__( 'Angebot angenommen', 'wp-pass-captain' ) . '</h1>';
			if ( 'complete' === ( $result['order']['status'] ?? '' ) ) {
				echo '<p>' . esc_html__( 'Deine Bestellung ist abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'wp-pass-captain' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Dein Ticket ist reserviert und die Bestellung wartet auf die Zahlung. Den Link zu deiner Bestellung und die Zahlungsinformationen haben wir dir per E-Mail gesendet.', 'wp-pass-captain' ) . '</p>';
				$payment = $result['payment'] ?? array();
				if ( ! empty( $payment['redirect_url'] ) ) {
					echo '<p><a href="' . esc_url( $payment['redirect_url'] ) . '">' . esc_html__( 'Jetzt bezahlen', 'wp-pass-captain' ) . '</a></p>';
				}
				if ( ! empty( $payment['bank_transfer'] ) && is_array( $payment['bank_transfer'] ) ) {
					$bank = $payment['bank_transfer'];
					echo '<dl>';
					foreach ( array( 'amount' => __( 'Betrag', 'wp-pass-captain' ), 'recipient' => __( 'Empfänger', 'wp-pass-captain' ), 'iban' => 'IBAN', 'bic' => 'BIC', 'remittance' => __( 'Verwendungszweck', 'wp-pass-captain' ), 'due_date' => __( 'Zahlbar bis', 'wp-pass-captain' ) ) as $key => $label ) {
						if ( '' !== (string) ( $bank[ $key ] ?? '' ) ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $bank[ $key ] ) . '</dd>'; }
					}
					echo '</dl>';
				} elseif ( ! empty( $payment['instructions'] ) ) {
					echo '<p>' . esc_html( (string) $payment['instructions'] ) . '</p>';
				}
			}
		}
		echo '</main></body></html>';
		exit;
	}
}
