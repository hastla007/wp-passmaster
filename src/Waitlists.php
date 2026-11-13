<?php
/**
 * Waitlist per event/occurrence/ticket type with automatic offers that hold inventory.
 *
 * Lifecycle of an entry: waiting → offered → accepted → paid, or expired | declined | skipped |
 * removed. An offer creates a pending placeholder order (payment_provider `waitlist_offer`) whose
 * reservation holds the offered quantity until the offer expires, so core capacity counts it and
 * public buyers cannot take the unit. Accepting assigns a payment method to that order (free
 * orders are confirmed immediately); expiry, cancellation or a lapsed payment hold mark the entry
 * expired and the freed units are offered to the next entries. Every waitlist mail carries a
 * personal unsubscribe link (keyed token, only its hash is stored); unsubscribing needs a POST
 * confirmation and releases an open offer. See docs/WAITLIST.md.
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

	/** Query argument of the personal unsubscribe link (with `token`). */
	const UNSUBSCRIBE_QUERY = 'wpcap_waitlist_unsubscribe';

	/** admin-post action of the unsubscribe confirmation (POST + nonce). */
	const UNSUBSCRIBE_ACTION = 'wpcap_waitlist_unsubscribe';

	/** Minutes an accepted free offer keeps its hold at least while the order is completed. */
	const ACCEPT_GUARD_MINUTES = 15;

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
	 * and unsubscribe token removed; status and counts stay. Open offers are left alone.
	 */
	public static function retention_step( $counts, $now, $deadline ) {
		global $wpdb;
		if ( microtime( true ) >= $deadline ) { return $counts; }
		$days = max( 0, (int) \PassCaptain\Privacy\Retention::days( 'waitlist' ) );
		if ( ! $days ) { $counts['waitlist'] = 0; return $counts; }
		$p = $wpdb->prefix;
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $now - $days * DAY_IN_SECONDS );
		$end = 'COALESCE(o.ends_at, DATE_ADD(o.starts_at, INTERVAL 1 DAY))';
		$counts['waitlist'] = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_waitlist w SET w.email='',w.email_hash=SHA2(CONCAT('deleted|',w.waitlist_id),256),w.offer_token_hash='',w.unsubscribe_token_hash='',w.updated_at=%s WHERE ( w.email<>'' OR w.unsubscribe_token_hash<>'' ) AND w.status<>'offered' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_occurrences o WHERE ( o.occurrence_id=w.occurrence_id OR ( w.occurrence_id=0 AND o.event_id=w.event_id ) ) AND $end>=%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static SQL.
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
		if ( ! $event_id || ! is_email( $email ) ) { return new \WP_Error( 'wpcap_waitlist_invalid', __( 'Veranstaltung oder E-Mail-Adresse ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! RateLimit::allow( 'waitlist|' . $email . '|' . $event_id, 5, HOUR_IN_SECONDS ) || ! RateLimit::allow( 'waitlist_ip|' . Orders::client_ip() . '|' . $event_id, 20, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.event_id=%d AND e.status='published' AND o.occurrence_id=%d AND o.status='scheduled'", $event_id, $occurrence_id ) );
		if ( ! $valid ) { return new \WP_Error( 'wpcap_waitlist_event', __( 'Diese Veranstaltung ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_type_id,max_per_order FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d AND occurrence_id=%d AND status='active'", $ticket_type_id, $occurrence_id ) );
		if ( ! $type ) { return new \WP_Error( 'wpcap_waitlist_ticket', __( 'Diese Ticketart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $quantity > max( 1, (int) $type->max_per_order ) ) {
			/* translators: %d: maximum number of tickets per order */
			return new \WP_Error( 'wpcap_waitlist_quantity', sprintf( _n( 'Auf der Warteliste ist höchstens %d Ticket pro Eintrag möglich.', 'Auf der Warteliste sind höchstens %d Tickets pro Eintrag möglich.', max( 1, (int) $type->max_per_order ), 'pass-captain' ), max( 1, (int) $type->max_per_order )), array( 'status' => 400 ) );
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
			$waitlist_id = (int) $existing->waitlist_id;
			$unsubscribe = self::unsubscribe_token( $waitlist_id, $email_hash );
			$wpdb->update( $table, array( 'occurrence_id' => $occurrence_id, 'email' => $email, 'position' => $position, 'quantity' => $quantity, 'priority' => 0, 'status' => 'waiting', 'offer_token_hash' => '', 'unsubscribe_token_hash' => hash( 'sha256', $unsubscribe ), 'offer_expires_at' => null, 'offered_at' => null, 'order_id' => 0, 'created_at' => $now, 'updated_at' => $now ), array( 'waitlist_id' => $waitlist_id ) );
			if ( '' !== $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
		} else {
			$wpdb->insert( $table, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email_hash' => $email_hash, 'email' => $email, 'position' => $position, 'quantity' => $quantity, 'status' => 'waiting', 'created_at' => $now, 'updated_at' => $now ) );
			$waitlist_id = (int) $wpdb->insert_id;
			if ( ! $waitlist_id ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
			$unsubscribe = self::unsubscribe_token( $waitlist_id, $email_hash );
			$wpdb->update( $table, array( 'unsubscribe_token_hash' => hash( 'sha256', $unsubscribe ) ), array( 'waitlist_id' => $waitlist_id ) );
			if ( '' !== $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
		}
		$wpdb->query( 'COMMIT' );
		self::send_mail( 'waitlist_joined', $email, array_merge( self::mail_names( $event_id, $ticket_type_id ), array( 'position' => (int) $position, 'quantity' => $quantity ) ), $waitlist_id, $unsubscribe );
		// Units may already be free (e.g. a hold just lapsed): let the next run offer them.
		self::queue( array( $ticket_type_id ) );
		return self::join_response();
	}

	private static function join_failed() {
		return new \WP_Error( 'wpcap_waitlist_join', __( 'Der Wartelistenplatz konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
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
		if ( ! $row ) { return new \WP_Error( 'wpcap_waitlist_empty', __( 'Für diese Ticketart gibt es keinen wartenden Eintrag.', 'pass-captain' ), array( 'status' => 404 ) ); }
		return self::offer_entry( $row );
	}

	/** Offers one entry by id (staff action). */
	public static function offer( $waitlist_id ) {
		$row = self::get( $waitlist_id );
		if ( ! $row || 'waiting' !== $row->status ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Dieser Eintrag wartet nicht (mehr) auf ein Angebot.', 'pass-captain' ), array( 'status' => 409 ) ); }
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
			return new \WP_Error( 'wpcap_waitlist_too_late', __( 'Für diesen Termin ist kein Wartelistenangebot mehr möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$hash = hash( 'sha256', $token );
		$now = current_time( 'mysql', true );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='offered',offer_token_hash=%s,offer_expires_at=%s,offered_at=%s,order_id=0,updated_at=%s WHERE waitlist_id=%d AND status='waiting'", $hash, gmdate( 'Y-m-d H:i:s', $expires_ts ), $now, $now, (int) $row->waitlist_id ) );
		if ( 1 !== (int) $claimed ) { return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
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
		// Linked only while the claim stands: an entry removed meanwhile (unsubscribe, staff, privacy
		// erasure) no longer matches, and the hold just created must not outlive it.
		$linked = $wpdb->update( $table, array( 'order_id' => $order_id, 'offer_expires_at' => $expires ), array( 'waitlist_id' => (int) $row->waitlist_id, 'offer_token_hash' => $hash, 'status' => 'offered' ) );
		if ( 1 !== (int) $linked ) {
			self::release_offer_order( $order_id, 'waitlist_offer_withdrawn' );
			return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$accept_url = add_query_arg( array( 'wpcap_waitlist_accept' => (int) $row->waitlist_id, 'token' => $token ), home_url( '/' ) );
		$sent = self::send_mail( 'waitlist_offer', $row->email, array_merge( self::mail_names( $row->event_id, $row->ticket_type_id ), array( 'offer_expires' => wp_date( 'd.m.Y H:i T', strtotime( $expires . ' UTC' ) ), 'accept_link' => Mail\Mailer::link( $accept_url ), 'position' => (int) $row->position, 'quantity' => $quantity ) ), (int) $row->waitlist_id, self::ensure_unsubscribe_token( $row ) );
		if ( ! $sent ) {
			// Back to the queue first, so the release below does not mark the entry expired.
			self::revert_claim( (int) $row->waitlist_id, $hash );
			Orders::cancel_order( $order_id, 'waitlist_offer_mail_failed' );
			return new \WP_Error( 'wpcap_waitlist_mail', __( 'Das Angebot konnte nicht per E-Mail gesendet werden und wurde zurückgenommen.', 'pass-captain' ), array( 'status' => 502 ) );
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
		return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
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
			return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$gateway = null;
		// Free offers keep their hold a little longer while the order is completed; paid offers for
		// the payment window of the chosen method (never shortened).
		$minutes = self::ACCEPT_GUARD_MINUTES;
		if ( $paid ) {
			$enabled = Payments\Registry::enabled( $order->currency );
			$wanted = sanitize_key( $args['provider'] ?? '' );
			$gateway = $enabled[ $wanted ] ?? ( $enabled['manual'] ?? ( $enabled ? reset( $enabled ) : null ) );
			if ( ! $gateway ) { return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400 ) ); }
			$minutes = max( 30, (int) Settings::get( 'hold_minutes' ) );
			if ( 'manual' === $gateway->id() ) {
				// Bank transfer: never beyond the start (and not offered within manual_cutoff_hours).
				$minutes = Orders::manual_hold_minutes( array_map( static function ( $item ) { return (int) $item->ticket_type_id; }, Orders::items_for_order( (int) $order->order_id ) ) );
				if ( null === $minutes ) {
					return new \WP_Error( 'wpcap_manual_too_late', __( 'Eine Zahlung per Überweisung ist so kurz vor Beginn nicht mehr möglich. Bitte wähle eine andere Zahlungsart.', 'pass-captain' ), array( 'status' => 400 ) );
				}
			}
		}
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$claimed = self::claim_offer( $row, $gateway ? $gateway->id() : '', $minutes );
		if ( Errors::is_busy( $claimed ) ) {
			// Deadlock victim (e.g. against the expiry job at the deadline): everything was rolled
			// back; one more try sees the winner's result.
			usleep( 100000 );
			$claimed = self::claim_offer( $row, $gateway ? $gateway->id() : '', $minutes );
		}
		if ( is_wp_error( $claimed ) ) { return $claimed; }
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

	/**
	 * Claims an open offer for acceptance in one transaction: the placeholder order, the entry and the
	 * order's active holds are locked (order first, like Orders), then status, token, deadline and the
	 * hold are checked again under the locks. Only then the entry becomes `accepted`, the hold is
	 * extended to at least $minutes from now and – for paid offers – the order gets the chosen
	 * payment method. Parallel accepts, a decline, an unsubscribe, a staff action or the expiry job
	 * therefore either see the claimed entry or win before it: never both.
	 *
	 * @param object $row      Entry verified by open_offer() (its offer token hash is the expected one).
	 * @param string $provider Payment method for paid offers, '' for free ones.
	 * @param int    $minutes  Minimum remaining hold after the claim.
	 * @return true|\WP_Error
	 */
	private static function claim_offer( $row, $provider, $minutes ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $row->order_id;
		$now = current_time( 'mysql', true );
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Errors::busy( __( 'Das Angebot konnte gerade nicht angenommen werden. Bitte versuche es gleich noch einmal.', 'pass-captain' ) );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status,payment_provider FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE waitlist_id=%d FOR UPDATE", (int) $row->waitlist_id ) );
		// Only this order's holds: on small tables the optimizer would otherwise scan the expiry index
		// and lock the holds of other orders too (deadlocks with their cancellations).
		$held = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT reservation_id FROM {$p}wpcap_reservations FORCE INDEX (order_id) WHERE order_id=%d AND status='active' AND expires_at > %s ORDER BY reservation_id FOR UPDATE", $order_id, $now ) ) );
		if ( '' !== $wpdb->last_error ) { return $busy(); }
		$open = $order && $locked && $held
			&& 'pending' === $order->payment_status && self::OFFER_PROVIDER === $order->payment_provider
			&& 'offered' === $locked->status && (int) $locked->order_id === $order_id
			&& '' !== (string) $row->offer_token_hash && hash_equals( (string) $locked->offer_token_hash, (string) $row->offer_token_hash )
			&& (string) $locked->offer_expires_at > $now;
		if ( ! $open ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot konnte nicht angenommen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_waitlist SET status='accepted',offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status='offered'", $now, (int) $row->waitlist_id ) );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $minutes ) * MINUTE_IN_SECONDS );
		$extended = $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=GREATEST(expires_at,%s) WHERE reservation_id IN (" . implode( ',', $held ) . ')', $until ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$assigned = true;
		if ( '' !== (string) $provider ) {
			$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
			$assigned = $wpdb->update( $p . 'wpcap_orders', array( 'payment_provider' => (string) $provider, 'terms_version' => Settings::terms_version( $locale ), 'locale' => $locale, 'updated_at' => $now ), array( 'order_id' => $order_id ) );
		}
		if ( 1 !== (int) $claimed || false === $extended || false === $assigned || false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		return true;
	}

	/**
	 * Cancels the placeholder order of a closed offer. A transient lock conflict is retried once
	 * right away; after that Orders::cancel_order() has queued its own retry job, so the hold never
	 * outlives the entry for long.
	 */
	private static function release_offer_order( $order_id, $reason ) {
		$result = Orders::cancel_order( $order_id, $reason );
		if ( is_wp_error( $result ) ) {
			usleep( 50000 );
			$result = Orders::cancel_order( $order_id, $reason );
		}
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
		self::release_offer_order( (int) $row->order_id, 'waitlist_declined' );
		Audit::record( 'waitlist_declined', 'waitlist', (int) $row->waitlist_id, array( 'order_id' => (int) $row->order_id ) );
		return array( 'status' => 'declined' );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Self-unsubscribe (personal link in every waitlist mail, order portal).
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Personal unsubscribe token of an entry: keyed hash of entry id and e-mail hash (site secret),
	 * so every mail of the entry carries the same link without storing the token. Only its SHA-256
	 * hash is stored (`unsubscribe_token_hash`) and compared in constant time; clearing the hash
	 * (retention, privacy erasure) revokes the link.
	 */
	private static function unsubscribe_token( $waitlist_id, $email_hash ) {
		return hash_hmac( 'sha256', 'wpcap-waitlist-unsubscribe|' . absint( $waitlist_id ) . '|' . (string) $email_hash, 'wpcap-waitlist|' . wp_salt( 'auth' ) );
	}

	/** Token for a mail of an entry; stores its hash when missing (entries from before 3.10.1). */
	private static function ensure_unsubscribe_token( $row ) {
		global $wpdb;
		$token = self::unsubscribe_token( (int) $row->waitlist_id, (string) $row->email_hash );
		$hash = hash( 'sha256', $token );
		if ( ! hash_equals( (string) ( $row->unsubscribe_token_hash ?? '' ), $hash ) ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'unsubscribe_token_hash' => $hash ), array( 'waitlist_id' => (int) $row->waitlist_id ) );
		}
		return $token;
	}

	/** Personal unsubscribe page URL (GET shows a confirmation, nothing changes). */
	public static function unsubscribe_url( $waitlist_id, $token ) {
		return add_query_arg( array( self::UNSUBSCRIBE_QUERY => absint( $waitlist_id ), 'token' => (string) $token ), home_url( '/' ) );
	}

	/**
	 * Sends a waitlist mail through the central templates with the {unsubscribe_link} placeholder. A
	 * customised template without the link gets the standard sentence with the link appended, so
	 * every waitlist mail carries it.
	 */
	private static function send_mail( $type, $to, array $vars, $waitlist_id, $unsubscribe_token ) {
		$url = self::unsubscribe_url( $waitlist_id, $unsubscribe_token );
		$vars['unsubscribe_link'] = Mail\Mailer::link( $url );
		$message = Mail\Mailer::compose( $type, $vars );
		if ( ! $message ) { return false; }
		if ( false === strpos( (string) $message['body'], (string) $unsubscribe_token ) ) {
			$message = Mail\Mailer::apply_filtered( $message, array( 'body' => rtrim( (string) $message['body'] ) . "\n\n" . Mail\Types::waitlist_unsubscribe_note( $url ) ) );
		}
		return Mail\Mailer::deliver( $to, $message );
	}

	/** Rate limit of unsubscribe attempts: per client address and per entry (one hour). */
	private static function unsubscribe_allowed( $waitlist_id ) {
		return RateLimit::allow( 'wl_unsub_ip|' . RateLimit::client(), 30, HOUR_IN_SECONDS ) && RateLimit::allow( 'wl_unsub|' . absint( $waitlist_id ), 10, HOUR_IN_SECONDS );
	}

	private static function unsubscribe_rate_error() {
		return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
	}

	private static function unsubscribe_invalid() {
		return new \WP_Error( 'wpcap_waitlist_unsubscribe_invalid', __( 'Dieser Link zum Austragen ist ungültig.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/** Entry for id + unsubscribe token (constant-time hash compare) or null. */
	private static function unsubscribe_row( $waitlist_id, $token ) {
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { return null; }
		$row = self::get( $waitlist_id );
		if ( ! $row || '' === (string) $row->unsubscribe_token_hash || ! hash_equals( (string) $row->unsubscribe_token_hash, hash( 'sha256', $token ) ) ) { return null; }
		return $row;
	}

	/**
	 * Unsubscribes the entry of a personal link (POST confirmation of the unsubscribe page).
	 * Idempotent: an entry that is already off the list answers with `already` = true.
	 *
	 * @return array|\WP_Error status (removed|closed), already, released_offer.
	 */
	public static function unsubscribe( $waitlist_id, $token, $source = 'link' ) {
		$waitlist_id = absint( $waitlist_id );
		if ( ! self::unsubscribe_allowed( $waitlist_id ) ) { return self::unsubscribe_rate_error(); }
		$row = self::unsubscribe_row( $waitlist_id, $token );
		if ( ! $row ) { return self::unsubscribe_invalid(); }
		return self::unsubscribe_entry( $waitlist_id, $source, array( 'unsubscribe_token_hash' => (string) $row->unsubscribe_token_hash ) );
	}

	/**
	 * Removes an entry on the buyer's request: status `removed`, e-mail emptied and the e-mail hash
	 * replaced (like the retention anonymisation, so the address can join again later), offer token
	 * removed. An open offer is withdrawn: its placeholder order is cancelled, the hold released and
	 * the units queued for the next entry. Entry and placeholder order are locked in one
	 * transaction (order first, like accept_offer()), so a parallel accept either wins before (then
	 * the entry is `accepted` and stays) or sees the removed entry.
	 *
	 * @param array $match Column => value the locked row must still have (token hash, e-mail hash).
	 * @return array|\WP_Error
	 */
	private static function unsubscribe_entry( $waitlist_id, $source, array $match ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$table = $p . 'wpcap_waitlist';
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Errors::busy( __( 'Das Austragen hat gerade nicht geklappt. Bitte versuche es gleich noch einmal.', 'pass-captain' ) );
		};
		$row = null;
		$order = null;
		for ( $attempt = 0; $attempt < 3 && ! $row; $attempt++ ) {
			$peek = self::get( $waitlist_id );
			if ( ! $peek ) { return self::unsubscribe_invalid(); }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
			$order = (int) $peek->order_id ? $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $peek->order_id ) ) : null;
			$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE waitlist_id=%d FOR UPDATE", absint( $waitlist_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			if ( '' !== $wpdb->last_error ) { return $busy(); }
			if ( ! $locked ) {
				$wpdb->query( 'ROLLBACK' );
				return self::unsubscribe_invalid();
			}
			if ( (int) $locked->order_id !== (int) $peek->order_id ) {
				// An offer was linked meanwhile: start over and lock its order first.
				$wpdb->query( 'ROLLBACK' );
				continue;
			}
			$row = $locked;
		}
		if ( ! $row ) { return $busy(); }
		foreach ( $match as $column => $value ) {
			if ( '' === (string) $value || ! hash_equals( (string) ( $row->$column ?? '' ), (string) $value ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::unsubscribe_invalid();
			}
		}
		$previous = (string) $row->status;
		if ( 'accepted' === $previous ) {
			$wpdb->query( 'COMMIT' );
			return new \WP_Error( 'wpcap_waitlist_unsubscribe_accepted', __( 'Du hast das Angebot bereits angenommen; die Bestellung wartet auf deine Zahlung. Zahlst du nicht, verfällt die Reservierung automatisch und der Platz geht an die nächste Person.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $previous, array( 'waiting', 'offered' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return array( 'status' => 'removed' === $previous ? 'removed' : 'closed', 'already' => true, 'released_offer' => false );
		}
		$now = current_time( 'mysql', true );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='removed',email='',email_hash=%s,offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status=%s", hash( 'sha256', 'deleted|' . (int) $row->waitlist_id ), $now, (int) $row->waitlist_id, $previous ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( 1 !== (int) $changed || false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		$release = 'offered' === $previous && $order && 'pending' === $order->payment_status;
		if ( $release ) {
			self::release_offer_order( (int) $order->order_id, 'waitlist_unsubscribed' );
		}
		if ( 'offered' === $previous ) {
			// The next entry gets the units (also when the release hook is not wired, e.g. module off).
			self::queue_occurrences_of( array( (int) $row->ticket_type_id ) );
		}
		Audit::record( 'waitlist_unsubscribed', 'waitlist', (int) $row->waitlist_id, array( 'previous' => $previous, 'order_id' => $release ? (int) $order->order_id : 0, 'source' => sanitize_key( (string) $source ) ) );
		/**
		 * A buyer took an entry off the waitlist (personal link or order portal).
		 *
		 * @param int    $waitlist_id Entry (now `removed`, e-mail emptied).
		 * @param string $previous    Status before: `waiting` or `offered` (open offer withdrawn).
		 * @param string $source      `link` or `portal`.
		 */
		do_action( 'wpcap_waitlist_unsubscribed', (int) $row->waitlist_id, $previous, sanitize_key( (string) $source ) );
		return array( 'status' => 'removed', 'already' => false, 'released_offer' => $release );
	}

	/**
	 * Inner HTML of the unsubscribe page for a personal link (GET). Never changes anything: mail
	 * scanners and link previews only see the confirmation form.
	 *
	 * @return array{0:int,1:string} HTTP status and HTML (escaped).
	 */
	public static function unsubscribe_page( $waitlist_id, $token ) {
		$waitlist_id = absint( $waitlist_id );
		$title = '<h1>' . esc_html__( 'Von der Warteliste austragen', 'pass-captain' ) . '</h1>';
		if ( ! self::unsubscribe_allowed( $waitlist_id ) ) {
			return array( 429, $title . '<p>' . esc_html( self::unsubscribe_rate_error()->get_error_message() ) . '</p>' );
		}
		$row = self::unsubscribe_row( $waitlist_id, $token );
		if ( ! $row ) {
			return array( 410, $title . '<p>' . esc_html( self::unsubscribe_invalid()->get_error_message() ) . '</p>' );
		}
		if ( 'removed' === $row->status ) {
			return array( 200, $title . '<p>' . esc_html__( 'Du bist bereits von dieser Warteliste ausgetragen.', 'pass-captain' ) . '</p>' );
		}
		if ( 'accepted' === $row->status ) {
			return array( 409, $title . '<p>' . esc_html__( 'Du hast das Angebot bereits angenommen; die Bestellung wartet auf deine Zahlung. Zahlst du nicht, verfällt die Reservierung automatisch und der Platz geht an die nächste Person.', 'pass-captain' ) . '</p>' );
		}
		if ( ! in_array( $row->status, array( 'waiting', 'offered' ), true ) ) {
			return array( 200, $title . '<p>' . esc_html__( 'Du stehst nicht mehr auf dieser Warteliste.', 'pass-captain' ) . '</p>' );
		}
		$names = self::mail_names( $row->event_id, $row->ticket_type_id );
		$html = $title . '<dl>';
		$html .= '<dt>' . esc_html__( 'Event', 'pass-captain' ) . '</dt><dd>' . esc_html( $names['event_name'] ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</dt><dd>' . esc_html( $names['ticket_name'] ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Anzahl', 'pass-captain' ) . '</dt><dd>' . esc_html( (string) (int) $row->quantity ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Status', 'pass-captain' ) . '</dt><dd>' . esc_html( self::status_label( $row->status ) ) . '</dd></dl>';
		$note = 'offered' === $row->status
			? __( 'Für dich sind gerade Tickets reserviert. Wenn du dich austrägst, verfällt dieses Angebot sofort und die Tickets gehen an die nächste Person auf der Warteliste.', 'pass-captain' )
			: __( 'Wenn du dich austrägst, bekommst du für diese Ticketart kein Angebot mehr. Deine E-Mail-Adresse wird aus dem Eintrag gelöscht.', 'pass-captain' );
		$html .= '<p>' . esc_html( $note ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::UNSUBSCRIBE_ACTION . '_' . $waitlist_id ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::UNSUBSCRIBE_ACTION ) . '"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) $waitlist_id ) . '"><input type="hidden" name="token" value="' . esc_attr( strtolower( (string) $token ) ) . '">';
		$html .= '<p><button type="submit">' . esc_html__( 'Jetzt von der Warteliste austragen', 'pass-captain' ) . '</button></p></form>';
		return array( 200, $html );
	}

	/** template_redirect: confirmation page of a personal unsubscribe link. */
	public static function maybe_render_unsubscribe_page() {
		// Capability link (unsubscribe token); the page only shows a nonce-protected POST form.
		$waitlist_id = absint( $_GET[ self::UNSUBSCRIBE_QUERY ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $waitlist_id || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		list( $code, $html ) = self::unsubscribe_page( $waitlist_id, $token );
		status_header( $code );
		self::page_head( __( 'Von der Warteliste austragen', 'pass-captain' ) );
		echo $html . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in unsubscribe_page().
		exit;
	}

	/** admin-post (POST only, nonce): performs the unsubscribe confirmed on the page. */
	public static function handle_unsubscribe_request() {
		if ( ! Http::is_post() ) {
			status_header( 405 );
			exit;
		}
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		check_admin_referer( self::UNSUBSCRIBE_ACTION . '_' . $waitlist_id );
		$token = sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) );
		$result = self::unsubscribe( $waitlist_id, $token, 'link' );
		nocache_headers();
		status_header( is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 409 ) : 200 );
		self::page_head( __( 'Von der Warteliste austragen', 'pass-captain' ) );
		echo self::unsubscribe_result_html( $result ) . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in unsubscribe_result_html().
		exit;
	}

	/** Result message of an unsubscribe (escaped HTML). */
	public static function unsubscribe_result_html( $result ) {
		if ( is_wp_error( $result ) ) {
			return '<h1>' . esc_html__( 'Austragen nicht möglich', 'pass-captain' ) . '</h1><p>' . esc_html( $result->get_error_message() ) . '</p>';
		}
		if ( ! empty( $result['already'] ) ) {
			return '<h1>' . esc_html__( 'Nicht mehr auf der Warteliste', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Du stehst nicht mehr auf dieser Warteliste. Es ist nichts weiter zu tun.', 'pass-captain' ) . '</p>';
		}
		$text = ! empty( $result['released_offer'] )
			? __( 'Du bist von der Warteliste ausgetragen. Die für dich reservierten Tickets gehen an die nächste Person auf der Warteliste.', 'pass-captain' )
			: __( 'Du bist von der Warteliste ausgetragen und bekommst dafür keine E-Mails mehr.', 'pass-captain' );
		return '<h1>' . esc_html__( 'Ausgetragen', 'pass-captain' ) . '</h1><p>' . esc_html( $text ) . '</p>';
	}

	/** Open entries (waiting, offered) with the buyer address of an order, for the order portal. */
	public static function portal_entries( $order ) {
		global $wpdb;
		$email = strtolower( trim( (string) ( $order->customer_email ?? '' ) ) );
		if ( '' === $email || Mail\Mailer::is_placeholder( $email ) ) { return array(); }
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT w.waitlist_id,w.status,w.quantity,e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_events e ON e.event_id=w.event_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id LEFT JOIN {$p}wpcap_occurrences o ON o.occurrence_id=w.occurrence_id WHERE w.email_hash=%s AND w.status IN ('waiting','offered') ORDER BY w.waitlist_id LIMIT 20", hash( 'sha256', $email ) ) );
	}

	private static function portal_nonce_action( $order ) {
		return 'wpcap_portal_waitlist_' . (string) $order->public_id;
	}

	/**
	 * Order portal (POST to the verified portal link, nonce): unsubscribes an entry with the same
	 * e-mail address as the order.
	 *
	 * @return array|\WP_Error
	 */
	public static function handle_portal_request( $order, array $post ) {
		$nonce = sanitize_text_field( (string) ( $post['_wpcap_waitlist_nonce'] ?? '' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::portal_nonce_action( $order ) ) ) {
			return new \WP_Error( 'wpcap_waitlist_nonce', __( 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$waitlist_id = absint( $post['waitlist_id'] ?? 0 );
		if ( 'unsubscribe' !== sanitize_key( (string) ( $post['wpcap_waitlist_op'] ?? '' ) ) || ! $waitlist_id ) { return self::unsubscribe_invalid(); }
		if ( ! self::unsubscribe_allowed( $waitlist_id ) ) { return self::unsubscribe_rate_error(); }
		$email = strtolower( trim( (string) ( $order->customer_email ?? '' ) ) );
		if ( '' === $email || Mail\Mailer::is_placeholder( $email ) ) { return self::unsubscribe_invalid(); }
		return self::unsubscribe_entry( $waitlist_id, 'portal', array( 'email_hash' => hash( 'sha256', $email ) ) );
	}

	/** Order portal card with the buyer's open waitlist entries ('' without entries; escaped HTML). */
	public static function portal_html( $order, $result_flag = '' ) {
		$entries = self::portal_entries( $order );
		$flag = sanitize_key( (string) $result_flag );
		if ( ! $entries && '' === $flag ) { return ''; }
		$html = '<section class="card" id="wpcap-waitlist" aria-labelledby="wpcap-waitlist-title"><h2 id="wpcap-waitlist-title">' . esc_html__( 'Deine Wartelisten', 'pass-captain' ) . '</h2>';
		if ( 'removed' === $flag ) {
			$html .= '<p role="status">' . esc_html__( 'Du bist von der Warteliste ausgetragen.', 'pass-captain' ) . '</p>';
		} elseif ( 'error' === $flag ) {
			$html .= '<p role="alert">' . esc_html__( 'Das Austragen hat nicht geklappt. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ) . '</p>';
		}
		if ( ! $entries ) { return $html . '</section>'; }
		$html .= '<p class="muted">' . esc_html__( 'Mit deiner E-Mail-Adresse stehst du auf diesen Wartelisten.', 'pass-captain' ) . '</p><ul>';
		$nonce = wp_create_nonce( self::portal_nonce_action( $order ) );
		foreach ( $entries as $entry ) {
			$when = $entry->starts_at ? Frontend\Format::event_start( $entry->starts_at, (string) $entry->timezone ) : '';
			$html .= '<li><strong>' . esc_html( $entry->event_name . ' — ' . $entry->ticket_name ) . '</strong>' . ( '' !== $when ? '<br>' . esc_html( $when ) : '' );
			$html .= '<br>' . esc_html( __( 'Anzahl', 'pass-captain' ) . ': ' . (int) $entry->quantity . ' · ' . self::status_label( $entry->status ) );
			$html .= '<form method="post"><input type="hidden" name="wpcap_waitlist_op" value="unsubscribe"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) (int) $entry->waitlist_id ) . '"><input type="hidden" name="_wpcap_waitlist_nonce" value="' . esc_attr( $nonce ) . '">';
			$html .= '<button type="submit">' . esc_html__( 'Von der Warteliste austragen', 'pass-captain' ) . '</button></form></li>';
		}
		return $html . '</ul></section>';
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
			return new \WP_Error( 'wpcap_waitlist_state', __( 'Dieser Eintrag ist bereits abgeschlossen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status=%s,offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status=%s", $status, current_time( 'mysql', true ), (int) $row->waitlist_id, $row->status ) );
		if ( 1 !== (int) $changed ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Der Eintrag wurde gerade geändert. Bitte neu laden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( (int) $row->order_id && in_array( $row->status, self::OPEN_OFFER, true ) ) {
			$order = Orders::get( (int) $row->order_id );
			if ( $order && 'pending' === $order->payment_status ) { self::release_offer_order( (int) $row->order_id, 'waitlist_' . $status ); }
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
			'waiting' => __( 'Wartet', 'pass-captain' ),
			'offered' => __( 'Angebot offen', 'pass-captain' ),
			'accepted' => __( 'Angenommen, Zahlung offen', 'pass-captain' ),
			'paid' => __( 'Bezahlt', 'pass-captain' ),
			'expired' => __( 'Abgelaufen', 'pass-captain' ),
			'declined' => __( 'Abgelehnt', 'pass-captain' ),
			'skipped' => __( 'Übersprungen', 'pass-captain' ),
			'removed' => __( 'Entfernt', 'pass-captain' ),
		);
		return $labels[ $status ] ?? (string) $status;
	}

	/* ---------------------------------------------------------------------------------------------
	 * Public offer page.
	 * ------------------------------------------------------------------------------------------- */

	public static function maybe_render_offer_page() {
		// Capability link (offer token), no nonce by design: the token itself is the authorisation.
		$waitlist_id = absint( $_GET['wpcap_waitlist_accept'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $waitlist_id || 64 !== strlen( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		$row = self::open_offer( $waitlist_id, $token );
		status_header( $row ? 200 : 410 );
		$order = $row ? Orders::get( (int) $row->order_id ) : null;
		$names = $row ? self::mail_names( $row->event_id, $row->ticket_type_id ) : array();
		$gateways = $order && (int) $order->total_minor > 0 ? Payments\Registry::enabled( $order->currency ) : array();
		self::page_head( __( 'Wartelistenangebot annehmen', 'pass-captain' ) );
		echo '<h1>' . esc_html__( 'Wartelistenangebot annehmen', 'pass-captain' ) . '</h1>';
		if ( ! $row || ! $order ) {
			echo '<p>' . esc_html__( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ) . '</p></main></body></html>';
			exit;
		}
		echo '<p>' . esc_html__( 'Für dich sind Tickets reserviert. Nimm das Angebot bis zum angegebenen Zeitpunkt an, sonst geht es an die nächste Person auf der Warteliste.', 'pass-captain' ) . '</p><dl>';
		echo '<dt>' . esc_html__( 'Event', 'pass-captain' ) . '</dt><dd>' . esc_html( $names['event_name'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Ticketart', 'pass-captain' ) . '</dt><dd>' . esc_html( $names['ticket_name'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Anzahl', 'pass-captain' ) . '</dt><dd>' . esc_html( (string) (int) $row->quantity ) . '</dd>';
		echo '<dt>' . esc_html__( 'Preis', 'pass-captain' ) . '</dt><dd>' . esc_html( Frontend\Format::money( (int) $order->total_minor, $order->currency ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Reserviert bis', 'pass-captain' ) . '</dt><dd>' . esc_html( wp_date( 'd.m.Y H:i T', strtotime( $row->offer_expires_at . ' UTC' ) ) ) . '</dd></dl>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpcap_accept_waitlist_' . $waitlist_id );
		echo '<input type="hidden" name="action" value="wpcap_accept_waitlist"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) $waitlist_id ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		if ( count( $gateways ) > 1 ) {
			echo '<fieldset><legend>' . esc_html__( 'Zahlungsart', 'pass-captain' ) . '</legend>';
			$first = true;
			foreach ( $gateways as $id => $gateway ) {
				echo '<label><input type="radio" name="provider" value="' . esc_attr( $id ) . '"' . ( $first ? ' checked' : '' ) . '> ' . esc_html( $gateway->title() ) . '</label><br>';
				$first = false;
			}
			echo '</fieldset>';
		}
		if ( $gateways && Settings::get( 'require_terms' ) ) {
			echo '<p><label><input type="checkbox" name="accept_terms" value="1" required> ' . esc_html__( 'Ich akzeptiere die Bedingungen und Verbraucherinformationen.', 'pass-captain' ) . '</label></p>';
		}
		echo '<p><button type="submit" name="decision" value="accept">' . esc_html__( 'Angebot annehmen', 'pass-captain' ) . '</button> <button type="submit" name="decision" value="decline" formnovalidate>' . esc_html__( 'Angebot ablehnen', 'pass-captain' ) . '</button></p></form></main></body></html>';
		exit;
	}

	private static function page_head( $title ) {
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo esc_html( $title ); ?></title></head><body><main><?php
	}

	public static function handle_accept_request() {
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		check_admin_referer( 'wpcap_accept_waitlist_' . $waitlist_id );
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) ) ) );
		$decline = 'decline' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$result = $decline ? self::decline_offer( $waitlist_id, $token ) : self::accept_offer( $waitlist_id, $token, array( 'provider' => sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) ), 'accept_terms' => ! empty( $_POST['accept_terms'] ) ) );
		nocache_headers();
		status_header( is_wp_error( $result ) ? 409 : 200 );
		/* translators: %s: site name */
		self::page_head( sprintf( __( 'Warteliste – %s', 'pass-captain' ), Orders::site_name() ) );
		if ( is_wp_error( $result ) ) {
			echo '<h1>' . esc_html__( 'Angebot nicht verfügbar', 'pass-captain' ) . '</h1><p>' . esc_html( $result->get_error_message() ) . '</p>';
		} elseif ( $decline ) {
			echo '<h1>' . esc_html__( 'Angebot abgelehnt', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Danke für deine Rückmeldung. Die Tickets gehen an die nächste Person auf der Warteliste.', 'pass-captain' ) . '</p>';
		} else {
			echo '<h1>' . esc_html__( 'Angebot angenommen', 'pass-captain' ) . '</h1>';
			if ( 'complete' === ( $result['order']['status'] ?? '' ) ) {
				echo '<p>' . esc_html__( 'Deine Bestellung ist abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'pass-captain' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Dein Ticket ist reserviert und die Bestellung wartet auf die Zahlung. Den Link zu deiner Bestellung und die Zahlungsinformationen haben wir dir per E-Mail gesendet.', 'pass-captain' ) . '</p>';
				$payment = $result['payment'] ?? array();
				if ( ! empty( $payment['redirect_url'] ) ) {
					echo '<p><a href="' . esc_url( $payment['redirect_url'] ) . '">' . esc_html__( 'Jetzt bezahlen', 'pass-captain' ) . '</a></p>';
				}
				if ( ! empty( $payment['bank_transfer'] ) && is_array( $payment['bank_transfer'] ) ) {
					$bank = $payment['bank_transfer'];
					echo '<dl>';
					foreach ( array( 'amount' => __( 'Betrag', 'pass-captain' ), 'recipient' => __( 'Empfänger', 'pass-captain' ), 'iban' => 'IBAN', 'bic' => 'BIC', 'remittance' => __( 'Verwendungszweck', 'pass-captain' ), 'due_date' => __( 'Zahlbar bis', 'pass-captain' ) ) as $key => $label ) {
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
