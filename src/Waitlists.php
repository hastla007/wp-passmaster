<?php
/**
 * Waitlist per event/occurrence/ticket type with automatic offers that hold inventory.
 *
 * Lifecycle of an entry: (pending →) waiting → offered → accepted → paid, or expired | declined |
 * skipped | removed | blocked. With double opt-in (A564, default on) a sign-up starts as `pending`:
 * only the confirmation link in the mail makes it `waiting` (queue position from then on);
 * unconfirmed entries never get offers and are deleted after `confirm_hours`. An entry that cannot be offered (buyer limit, quantity above the per-order
 * maximum, attendee data) is `blocked` with a `block_reason` and the queue continues with the next
 * entry (A563); staff can put it back with unblock(). Attendee data (required participant fields)
 * is not needed for the offer: the offer page collects it and accept_offer() enforces it (A536).
 * An offer creates a pending placeholder order (payment_provider `waitlist_offer`) whose
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
	/** Waitlist sign-ups with mail per address, event and hour (A644: only sign-ups that send a mail count). */
	const EMAIL_HOURLY_LIMIT = 5;

	/** Payment provider id of placeholder orders that hold an open offer. */
	const OFFER_PROVIDER = 'waitlist_offer';

	/** Cron hook that processes queued ticket types. */
	const HOOK = 'wpcap_waitlist_process';

	/** Option with ticket types waiting for processing (type id => due timestamp). */
	const QUEUE_OPTION = 'wpcap_waitlist_queue';
	/** DbLock purpose guarding read-modify-write of QUEUE_OPTION (A364). */
	const QUEUE_LOCK = 'wl_queue';

	/** Entry states. Terminal: paid, expired, declined, skipped, removed, closed (date cancelled/over, A557); blocked until staff unblock it. */
	const STATUSES = array( 'pending', 'waiting', 'offered', 'accepted', 'paid', 'expired', 'declined', 'skipped', 'removed', 'closed', 'blocked' );

	/**
	 * Offer failures that concern only one entry (Orders::create reason or error code => block reason):
	 * the entry is blocked and the next entry gets the units (A563).
	 */
	const BLOCK_REASONS = array(
		'allocation_buyer_limit' => 'buyer_limit',
		'quantity_limit' => 'quantity_limit',
		'wpcap_invalid_item' => 'quantity_limit',
		'wpcap_attendee_field_required' => 'attendee_data',
		'wpcap_attendee_field_invalid' => 'attendee_data',
		'wpcap_attendees_invalid' => 'attendee_data',
	);

	/** Offer failures for this run only (not enough seats for the entry's quantity): skipped, noted as `seats`. */
	const SKIP_REASONS = array( 'seat_sold_out', 'seat_unavailable' );

	/** States in which an entry holds inventory through its order. */
	const OPEN_OFFER = array( 'offered', 'accepted' );

	/** Query argument of the personal unsubscribe link (with `token`). */
	const UNSUBSCRIBE_QUERY = 'wpcap_waitlist_unsubscribe';

	/** admin-post action of the unsubscribe confirmation (POST + form proof). */
	const UNSUBSCRIBE_ACTION = 'wpcap_waitlist_unsubscribe';

	/** Query argument of the confirmation link (double opt-in, A564; with `token`). */
	const CONFIRM_QUERY = 'wpcap_waitlist_confirm';

	/** admin-post action of the confirmation (POST + nonce). */
	const CONFIRM_ACTION = 'wpcap_waitlist_confirm';

	/** POST field of the form proof (form_sig()) on the offer, unsubscribe and result pages (A462). */
	const SIG_FIELD = '_wpcap_sig';

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
		// A557: entries of a cancelled date are closed right away, past dates by the sweep.
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'on_occurrence_cancelled' ), 30, 1 );
		// A562: a date that starts or ends closes its entries right away instead of on the hourly sweep.
		add_action( Occurrence::PHASE_ACTION, array( __CLASS__, 'on_phase_changed' ), 30, 2 );
		// A565: people on the waitlist learn about a new date or location.
		add_action( 'wpcap_occurrence_changed', array( __CLASS__, 'on_occurrence_changed' ), 30, 2 );
		add_filter( 'wpcap_privacy_retention_steps', array( __CLASS__, 'retention_step' ), 10, 3 );
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 3 );
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
		$end = Occurrence::end_sql( 'o' ); // A606.
		// Merged schema: the audit line's personal manage link (manage_token_hash) ends with the entry, and an entry of
		// an ended date no longer waits (status closed, as close_ended() sets it).
		$manage = ServiceBookings::has_column( 'wpcap_waitlist', 'manage_token_hash' ) ? "w.manage_token_hash=''," : '';
		$counts['waitlist'] = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$p}wpcap_waitlist w SET w.email='',w.email_hash=SHA2(CONCAT('deleted|',w.waitlist_id),256),w.offer_token_hash='',w.unsubscribe_token_hash='',{$manage}w.status=IF(w.status IN ('waiting','pending'),'closed',w.status),w.updated_at=%s WHERE ( w.email<>'' OR w.unsubscribe_token_hash<>'' ) AND w.status<>'offered' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_occurrences o WHERE ( o.occurrence_id=w.occurrence_id OR ( w.occurrence_id=0 AND o.event_id=w.event_id ) ) AND $end>=%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static SQL.
			gmdate( 'Y-m-d H:i:s', (int) $now ),
			$cutoff
		) );
		return $counts;
	}

	/**
	 * Settings merged with defaults: auto_offers, offer_hours, max_offers_per_run, strict_fifo,
	 * close_mail, change_mail (A565), double_opt_in and confirm_hours (A564).
	 */
	public static function config() {
		// Offer hours default to the security/settings value waitlist_offer_hours (offer_hours()).
		$defaults = array( 'auto_offers' => 1, 'offer_hours' => self::offer_hours(), 'max_offers_per_run' => 10, 'strict_fifo' => 0 );
		$stored = Settings::get( 'waitlist' );
		$c = array_merge( $defaults + array( 'close_mail' => 1, 'change_mail' => 1, 'double_opt_in' => 1, 'confirm_hours' => 48, 'offer_cutoff_minutes' => 15 ), is_array( $stored ) ? $stored : array() );
		$c['auto_offers'] = (int) ! empty( $c['auto_offers'] );
		$c['close_mail'] = (int) ! empty( $c['close_mail'] );
		$c['change_mail'] = (int) ! empty( $c['change_mail'] );
		$c['double_opt_in'] = (int) ! empty( $c['double_opt_in'] );
		$c['confirm_hours'] = max( 1, min( 168, (int) $c['confirm_hours'] ) );
		$c['offer_cutoff_minutes'] = max( 5, min( 1440, (int) $c['offer_cutoff_minutes'] ) );
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
		$quantity = null === $quantity || '' === $quantity ? 1 : Http::positive_int( $quantity );
		$email = Http::email( $email );
		if ( ! $quantity ) { return new \WP_Error( 'wpcap_waitlist_quantity', __( 'Bitte eine gültige Anzahl angeben.', 'pass-captain' ), array( 'status' => 400 ) ); }
		if ( ! $event_id || ! Http::valid_email( $email ) ) { return new \WP_Error( 'wpcap_waitlist_invalid', __( 'Veranstaltung oder E-Mail-Adresse ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$valid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.event_id=%d AND e.status='published' AND o.occurrence_id=%d AND o.status='scheduled'", $event_id, $occurrence_id ) );
		if ( ! $valid ) { return new \WP_Error( 'wpcap_waitlist_event', __( 'Diese Veranstaltung ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_type_id,max_per_order FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d AND occurrence_id=%d AND status='active'", $ticket_type_id, $occurrence_id ) );
		if ( ! $type ) { return new \WP_Error( 'wpcap_waitlist_ticket', __( 'Diese Ticketart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $quantity > max( 1, (int) $type->max_per_order ) ) {
			/* translators: %d: maximum number of tickets per order */
			return new \WP_Error( 'wpcap_waitlist_quantity', sprintf( _n( 'Auf der Warteliste ist höchstens %d Ticket pro Eintrag möglich.', 'Auf der Warteliste sind höchstens %d Tickets pro Eintrag möglich.', max( 1, (int) $type->max_per_order ), 'pass-captain' ), max( 1, (int) $type->max_per_order )), array( 'status' => 400 ) );
		}
		/**
		 * Refuses a waitlist sign-up that could never be offered (A563), e.g. the buyer limit
		 * (Allocations module). Return a WP_Error to refuse, the given value otherwise.
		 *
		 * @param \WP_Error|null $error   null.
		 * @param array          $context event_id, occurrence_id, ticket_type_id, email, quantity.
		 */
		$refused = apply_filters( 'wpcap_waitlist_join_error', null, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email' => $email, 'quantity' => $quantity ) );
		if ( is_wp_error( $refused ) ) { return $refused; }
		// A644: limits after validation. The client counts every valid request; the address (anyone can
		// type it) only counts sign-ups that send a mail, so junk requests cannot lock it out.
		$email_bucket = 'waitlist|' . strtolower( $email ) . '|' . $event_id; // Y1: one bucket per address, whatever the case.
		// One network (IPv6 /64) is also limited across all events (audit Y1, filter wp_pass_captain_waitlist_ip_limit_v1).
		if ( ! RateLimit::allow_client( 'waitlist_ip|' . $event_id, 20, HOUR_IN_SECONDS ) || RateLimit::hits( $email_bucket, HOUR_IN_SECONDS ) >= self::EMAIL_HOURLY_LIMIT
			|| ! RateLimit::hit( 'wpcap_waitlist_ipall_' . RateLimit::hash( RateLimit::client_network() ), self::ip_limit_per_hour(), HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_waitlist_rate', __( 'Zu viele Wartelistenanfragen. Bitte versuche es später erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$now = Clock::mysql();
		$email_hash = hash( 'sha256', strtolower( $email ) );
		// A564: double opt-in – the entry waits for the confirmation link before it joins the queue.
		$doi = self::double_opt_in( array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email' => $email, 'quantity' => $quantity ) );
		$confirm = $doi ? bin2hex( random_bytes( 32 ) ) : '';
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d FOR UPDATE", $event_id ) );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,status FROM $table WHERE event_id=%d AND ticket_type_id=%d AND email_hash=%s FOR UPDATE", $event_id, $ticket_type_id, $email_hash ) );
		$position = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $table WHERE event_id=%d AND ticket_type_id=%d", $event_id, $ticket_type_id ) );
		if ( $existing ) {
			// Finished entries (offer expired/declined, earlier purchase, blocked) may join again at the end
			// of the queue; open entries and entries skipped/removed by staff stay as they are. Same
			// response either way so the endpoint does not reveal who is on a waitlist.
			// A pending sign-up (A564) gets a fresh confirmation link with the new details.
			$rejoin = array( 'expired', 'declined', 'paid', 'blocked' );
			if ( $doi ) { $rejoin[] = 'pending'; }
			if ( ! in_array( $existing->status, $rejoin, true ) ) {
				$wpdb->query( 'COMMIT' );
				return self::join_response( $doi );
			}
			$waitlist_id = (int) $existing->waitlist_id;
			$unsubscribe = self::unsubscribe_token( $waitlist_id, $email_hash );
			$wpdb->update( $table, array( 'occurrence_id' => $occurrence_id, 'email' => $email, 'position' => $doi ? 0 : $position, 'quantity' => $quantity, 'priority' => 0, 'status' => $doi ? 'pending' : 'waiting', 'offer_token_hash' => '', 'unsubscribe_token_hash' => hash( 'sha256', $unsubscribe ), 'offer_expires_at' => null, 'offered_at' => null, 'order_id' => 0, 'created_at' => $now, 'updated_at' => $now ) + ( self::block_ready() ? array( 'block_reason' => '' ) : array() ) + self::confirm_columns( $confirm ), array( 'waitlist_id' => $waitlist_id ) );
			if ( '' !== $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
		} else {
			$wpdb->insert( $table, array( 'event_id' => $event_id, 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $ticket_type_id, 'email_hash' => $email_hash, 'email' => $email, 'position' => $doi ? 0 : $position, 'quantity' => $quantity, 'status' => $doi ? 'pending' : 'waiting', 'created_at' => $now, 'updated_at' => $now ) + self::confirm_columns( $confirm ) );
			$waitlist_id = (int) $wpdb->insert_id;
			if ( ! $waitlist_id ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
			$unsubscribe = self::unsubscribe_token( $waitlist_id, $email_hash );
			$wpdb->update( $table, array( 'unsubscribe_token_hash' => hash( 'sha256', $unsubscribe ) ), array( 'waitlist_id' => $waitlist_id ) );
			if ( '' !== $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::join_failed(); }
		}
		$wpdb->query( 'COMMIT' );
		RateLimit::allow( $email_bucket, self::EMAIL_HOURLY_LIMIT, HOUR_IN_SECONDS ); // A644: a sign-up mail goes out.
		if ( $doi ) {
			$c = self::config();
			$names = self::mail_names( $event_id, $ticket_type_id );
			$sent = Mail\Mailer::send( 'waitlist_confirm', $email, array_merge( $names, array(
				'quantity' => $quantity,
				'confirm_link' => Mail\Mailer::link( self::confirm_url( $waitlist_id, $confirm ) ),
				'confirm_expires' => Frontend\Format::deadline( (int) strtotime( $now . ' UTC' ) + $c['confirm_hours'] * HOUR_IN_SECONDS, $names['timezone'] ), // A608.
				'confirm_hours' => $c['confirm_hours'],
			) ) );
			Audit::record( 'waitlist_signup_pending', 'waitlist', $waitlist_id, array( 'ticket_type_id' => $ticket_type_id, 'quantity' => $quantity, 'mail_sent' => (bool) $sent ) );
			return self::join_response( $doi );
		}
		self::send_mail( 'waitlist_joined', $email, array_merge( self::mail_names( $event_id, $ticket_type_id ), array( 'position' => (int) $position, 'quantity' => $quantity ) ), $waitlist_id, $unsubscribe );
		// Units may already be free (e.g. a hold just lapsed): let the next run offer them.
		self::queue( array( $ticket_type_id ) );
		return self::join_response( $doi );
	}

	/** Whether the double opt-in columns exist (schema 3.10.21; deferred upgrades). */
	public static function doi_ready() {
		return ServiceBookings::has_column( 'wpcap_waitlist', 'confirm_token_hash' ) && ServiceBookings::has_column( 'wpcap_waitlist', 'confirmed_at' );
	}

	/** Whether new sign-ups need the e-mail confirmation (setting `double_opt_in`, schema present). */
	public static function double_opt_in_enabled() {
		return self::doi_ready() && (bool) self::config()['double_opt_in'] && (bool) Security\Policy::get( 'waitlist_double_opt_in' );
	}

	/**
	 * Whether this sign-up needs the e-mail confirmation (A564).
	 *
	 * @param array $context event_id, occurrence_id, ticket_type_id, email, quantity.
	 */
	public static function double_opt_in( array $context = array() ) {
		if ( ! self::double_opt_in_enabled() ) { return false; }
		/**
		 * Whether a waitlist sign-up must be confirmed through the link in the confirmation mail
		 * (double opt-in, A564). Return false only where the address is verified otherwise.
		 *
		 * @param bool  $required true (setting `double_opt_in` on).
		 * @param array $context  event_id, occurrence_id, ticket_type_id, email, quantity.
		 */
		return (bool) apply_filters( 'wpcap_waitlist_double_opt_in', true, $context );
	}

	/** Columns of a sign-up for the confirmation token ('' = no confirmation needed). */
	private static function confirm_columns( $token ) {
		if ( ! self::doi_ready() ) { return array(); }
		return array( 'confirm_token_hash' => '' === (string) $token ? '' : hash( 'sha256', (string) $token ), 'confirmed_at' => null );
	}

	/** Personal confirmation page URL (GET shows a confirmation button, nothing changes). */
	public static function confirm_url( $waitlist_id, $token ) {
		return add_query_arg( array( self::CONFIRM_QUERY => absint( $waitlist_id ), 'token' => (string) $token ), home_url( '/' ) );
	}

	private static function confirm_invalid() {
		return new \WP_Error( 'wpcap_waitlist_confirm_invalid', __( 'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte trage dich erneut in die Warteliste ein.', 'pass-captain' ), array( 'status' => 410 ) );
	}

	/** Entry for id + confirmation token (constant-time hash compare) or null; any status. */
	private static function confirm_row( $waitlist_id, $token ) {
		$token = strtolower( (string) preg_replace( '/[^a-f0-9]/', '', Http::str( $token ) ) );
		if ( 64 !== strlen( $token ) || ! self::doi_ready() ) { return null; }
		$row = self::get( $waitlist_id );
		if ( ! $row || '' === (string) ( $row->confirm_token_hash ?? '' ) || ! hash_equals( (string) $row->confirm_token_hash, hash( 'sha256', $token ) ) ) { return null; }
		return $row;
	}

	/** Whether a pending entry's confirmation window has passed. */
	private static function confirm_lapsed( $row ) {
		return (int) strtotime( (string) $row->created_at . ' UTC' ) + self::config()['confirm_hours'] * HOUR_IN_SECONDS <= Clock::now();
	}

	/**
	 * Confirms a pending sign-up (double opt-in, A564; POST of the confirmation page): the entry
	 * becomes `waiting` at the end of the queue, `confirmed_at` records the confirmation, the
	 * waitlist_joined mail follows and the ticket type is queued for offers. Idempotent: a second
	 * confirmation answers with `already` = true.
	 *
	 * @return array|\WP_Error status, already, position.
	 */
	public static function confirm( $waitlist_id, $token ) {
		global $wpdb;
		$waitlist_id = absint( $waitlist_id );
		if ( ! self::unsubscribe_allowed( $waitlist_id ) ) { return self::unsubscribe_rate_error(); }
		$row = self::confirm_row( $waitlist_id, $token );
		if ( ! $row ) { return self::confirm_invalid(); }
		if ( 'pending' !== $row->status ) {
			return null !== $row->confirmed_at ? array( 'status' => (string) $row->status, 'already' => true, 'position' => (int) $row->position ) : self::confirm_invalid();
		}
		if ( self::confirm_lapsed( $row ) ) { return self::confirm_invalid(); }
		// The date must still be on sale (cancelled, started or sales ended meanwhile: A557).
		$now = Clock::mysql();
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND tt.status='active' AND o.status='scheduled' AND e.status='published' AND o.starts_at>%s AND ( tt.sales_end IS NULL OR tt.sales_end>%s )", (int) $row->ticket_type_id, $now, $now ) );
		if ( ! $open ) {
			$wpdb->delete( $wpdb->prefix . 'wpcap_waitlist', array( 'waitlist_id' => $waitlist_id, 'status' => 'pending' ) );
			return new \WP_Error( 'wpcap_waitlist_event', __( 'Diese Veranstaltung ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 410 ) );
		}
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$wpdb->query( 'START TRANSACTION' );
		// Same lock as join(): positions are handed out in order.
		$wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d FOR UPDATE", (int) $row->event_id ) );
		$position = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0) FROM $table WHERE event_id=%d AND ticket_type_id=%d", (int) $row->event_id, (int) $row->ticket_type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='waiting',position=%d,confirmed_at=%s,updated_at=%s WHERE waitlist_id=%d AND status='pending' AND confirm_token_hash=%s", $position, $now, $now, $waitlist_id, (string) $row->confirm_token_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( 1 !== (int) $changed || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$fresh = self::get( $waitlist_id );
			if ( $fresh && 'pending' !== $fresh->status && null !== $fresh->confirmed_at ) { return array( 'status' => (string) $fresh->status, 'already' => true, 'position' => (int) $fresh->position ); }
			return Errors::busy( __( 'Die Bestätigung hat gerade nicht geklappt. Bitte versuche es gleich noch einmal.', 'pass-captain' ) );
		}
		Audit::record( 'waitlist_confirmed', 'waitlist', $waitlist_id, array( 'ticket_type_id' => (int) $row->ticket_type_id, 'position' => $position ) );
		/**
		 * A waitlist sign-up was confirmed through the link in the confirmation mail (A564).
		 *
		 * @param int $waitlist_id Entry (now `waiting`).
		 */
		do_action( 'wpcap_waitlist_confirmed', $waitlist_id );
		self::send_mail( 'waitlist_joined', (string) $row->email, array_merge( self::mail_names( $row->event_id, $row->ticket_type_id ), array( 'position' => $position, 'quantity' => (int) $row->quantity ) ), $waitlist_id, self::ensure_unsubscribe_token( $row ) );
		self::queue( array( (int) $row->ticket_type_id ) );
		return array( 'status' => 'waiting', 'already' => false, 'position' => $position );
	}

	/**
	 * Deletes sign-ups that were not confirmed within `confirm_hours` (A564): the address was never
	 * confirmed, so nothing of it is kept. Runs in the sweep.
	 *
	 * @return int Entries deleted.
	 */
	public static function purge_unconfirmed( $limit = 500 ) {
		global $wpdb;
		if ( ! self::doi_ready() ) { return 0; }
		$cutoff = gmdate( 'Y-m-d H:i:s', Clock::now() - self::config()['confirm_hours'] * HOUR_IN_SECONDS );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_waitlist WHERE status='pending' AND created_at<=%s LIMIT %d", $cutoff, max( 1, (int) $limit ) ) );
		if ( $deleted ) { Audit::record( 'waitlist_unconfirmed_deleted', 'waitlist', 0, array( 'count' => $deleted ) ); }
		return $deleted;
	}

	/**
	 * Inner HTML of the confirmation page (GET): entry details and a POST button. Never changes
	 * anything, so mail scanners and link previews cannot confirm a sign-up.
	 *
	 * @return array{0:int,1:string} HTTP status and HTML (escaped).
	 */
	public static function confirm_page( $waitlist_id, $token ) {
		$waitlist_id = absint( $waitlist_id );
		$title = '<h1>' . esc_html__( 'Wartelistenplatz bestätigen', 'pass-captain' ) . '</h1>';
		if ( ! self::unsubscribe_allowed( $waitlist_id ) ) {
			return array( 429, $title . '<p>' . esc_html( self::unsubscribe_rate_error()->get_error_message() ) . '</p>' );
		}
		$row = self::confirm_row( $waitlist_id, $token );
		if ( $row && 'pending' !== $row->status && null !== $row->confirmed_at ) {
			return array( 200, $title . '<p>' . esc_html__( 'Dein Wartelistenplatz ist bereits bestätigt.', 'pass-captain' ) . '</p>' );
		}
		if ( ! $row || 'pending' !== $row->status || self::confirm_lapsed( $row ) ) {
			return array( 410, $title . '<p>' . esc_html( self::confirm_invalid()->get_error_message() ) . '</p>' );
		}
		$html = $title . self::details_html( self::mail_names( $row->event_id, $row->ticket_type_id ), (int) $row->quantity );
		$html .= '<p>' . esc_html__( 'Bitte bestätige, dass du mit dieser E-Mail-Adresse auf die Warteliste möchtest. Erst dann bekommst du einen Platz in der Warteschlange und Angebote per E-Mail.', 'pass-captain' ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::CONFIRM_ACTION . '_' . $waitlist_id ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::CONFIRM_ACTION ) . '"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) $waitlist_id ) . '"><input type="hidden" name="token" value="' . esc_attr( strtolower( (string) $token ) ) . '">';
		$html .= '<p><button type="submit">' . esc_html__( 'Wartelistenplatz bestätigen', 'pass-captain' ) . '</button></p></form>';
		return array( 200, $html );
	}

	/** template_redirect: confirmation page of a double opt-in link. */
	public static function maybe_render_confirm_page() {
		// Capability link (confirmation token); the page only shows a nonce-protected POST form.
		$waitlist_id = absint( $_GET[ self::CONFIRM_QUERY ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $waitlist_id || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		PageCache::no_store( 'waitlist_confirm' );
		Security\PrivatePage::send(); // Token page: no cache, no framing, no indexing, no referrer (audit FE-7).
		list( $code, $html ) = self::confirm_page( $waitlist_id, $token );
		status_header( $code );
		self::page_head( __( 'Wartelistenplatz bestätigen', 'pass-captain' ) );
		echo $html . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in confirm_page().
		\PassCaptain\Http::end();
	}

	/** admin-post (POST only, nonce): confirms the sign-up of the confirmation page. */
	public static function handle_confirm_request() {
		if ( ! Http::is_post() ) {
			status_header( 405 );
			\PassCaptain\Http::end();
		}
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		check_admin_referer( self::CONFIRM_ACTION . '_' . $waitlist_id );
		$token = sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) );
		$result = self::confirm( $waitlist_id, $token );
		PageCache::no_store( 'waitlist_result' );
		Security\PrivatePage::send();
		status_header( is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 409 ) : 200 );
		self::page_head( __( 'Wartelistenplatz bestätigen', 'pass-captain' ) );
		echo self::confirm_result_html( $result ) . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in confirm_result_html().
		\PassCaptain\Http::end();
	}

	/** Result message of a confirmation (escaped HTML). */
	public static function confirm_result_html( $result ) {
		if ( is_wp_error( $result ) ) {
			return '<h1>' . esc_html__( 'Bestätigung nicht möglich', 'pass-captain' ) . '</h1><p>' . esc_html( $result->get_error_message() ) . '</p>';
		}
		if ( ! empty( $result['already'] ) ) {
			return '<h1>' . esc_html__( 'Bereits bestätigt', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Dein Wartelistenplatz ist bereits bestätigt. Es ist nichts weiter zu tun.', 'pass-captain' ) . '</p>';
		}
		/* translators: %d: waitlist sign-up number */
		$text = sprintf( __( 'Du stehst jetzt auf der Warteliste (Anmeldung Nr. %d). Wir schreiben dir, sobald ein Platz frei wird.', 'pass-captain' ), (int) ( $result['position'] ?? 0 ) );
		return '<h1>' . esc_html__( 'Bestätigt', 'pass-captain' ) . '</h1><p>' . esc_html( $text ) . '</p>';
	}

	private static function join_failed() {
		return new \WP_Error( 'wpcap_waitlist_join', __( 'Der Wartelistenplatz konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
	}

	/**
	 * Event, ticket type, date (with time zone) and location of an entry for waitlist mails and
	 * pages (A565): event_name, ticket_name, event_date, event_location, timezone.
	 */
	private static function mail_names( $event_id, $ticket_type_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND e.event_id=%d", absint( $ticket_type_id ), absint( $event_id ) ) );
		return array(
			'event_name' => (string) ( $row->event_name ?? '' ),
			'ticket_name' => (string) ( $row->ticket_name ?? '' ),
			'event_date' => ! empty( $row->starts_at ) ? Orders::format_start( (string) $row->starts_at, (string) $row->timezone ) : '',
			'event_location' => (string) ( $row->location ?? '' ),
			'timezone' => (string) ( $row->timezone ?? '' ),
		);
	}

	/** Definition list of an entry for the public pages: event, date, location, ticket type, quantity (escaped). */
	private static function details_html( array $names, $quantity, array $extra = array() ) {
		$rows = array(
			__( 'Event', 'pass-captain' ) => $names['event_name'] ?? '',
			__( 'Termin', 'pass-captain' ) => $names['event_date'] ?? '',
			__( 'Ort', 'pass-captain' ) => $names['event_location'] ?? '',
			__( 'Ticketart', 'pass-captain' ) => $names['ticket_name'] ?? '',
			__( 'Anzahl', 'pass-captain' ) => (string) (int) $quantity,
		) + $extra;
		$html = '<dl>';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) { continue; }
			$html .= '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * Uniform public response for waitlist sign-ups: the same for new, pending and existing entries
	 * (it depends only on whether this request needs the confirmation, never on the address's entries).
	 */
	private static function join_response( $doi = false ) {
		if ( $doi ) {
			return array( 'status' => 'received', 'confirmation_required' => true, 'message' => __( 'Danke! Falls die Adresse noch nicht eingetragen war, bekommst du eine E-Mail mit einem Bestätigungslink. Erst nach der Bestätigung stehst du auf der Warteliste.', 'pass-captain' ) );
		}
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
		$due = null === $due ? Clock::now() + max( 0, (int) apply_filters( 'wpcap_waitlist_process_delay', 60 ) ) : (int) $due;
		// Read-modify-write of the queue option under a short lock (A364): parallel releases and a
		// running cron must not overwrite each other's entries. Without the lock (timeout) the write
		// still happens – losing nothing is better than a perfect merge.
		$locked = DbLock::acquire( self::QUEUE_LOCK, 5 );
		try {
			$queue = self::fresh_queue();
			foreach ( $ids as $id ) {
				$queue[ $id ] = isset( $queue[ $id ] ) ? min( (int) $queue[ $id ], $due ) : $due;
			}
			update_option( self::QUEUE_OPTION, $queue, false );
		} finally {
			if ( $locked ) { DbLock::release( self::QUEUE_LOCK ); }
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $due, self::HOOK );
		}
	}

	/** Ticket types currently queued (type id => due timestamp). */
	public static function queued() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? array_map( 'intval', $queue ) : array();
	}

	/** The queue as stored now (bypasses this request's option cache; call under QUEUE_LOCK). */
	private static function fresh_queue() {
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::QUEUE_OPTION, 'options' ); }
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
		$now = Clock::now();
		$force = true === $force; // A TimeBudget continuation passes "continue": not a forced run.
		$due = array_keys( array_filter( $queue, static function ( $ts ) use ( $now, $force ) { return $force || $ts <= $now; } ) );
		if ( ! $due ) { return 0; }
		// Remove before processing: releases during the run (e.g. a failed mail) queue the type again.
		// Only the taken types are removed, from the queue as stored under the lock (A364): types
		// queued since the read above stay, and a type re-queued with a later due time is kept.
		if ( ! DbLock::acquire( self::QUEUE_LOCK, 5 ) ) { return 0; }
		try {
			$current = self::fresh_queue();
			$taken = array();
			foreach ( $due as $type_id ) {
				if ( isset( $current[ $type_id ] ) && ( $force || $current[ $type_id ] <= $now ) ) { $taken[] = $type_id; }
			}
			update_option( self::QUEUE_OPTION, array_diff_key( $current, array_flip( $taken ) ), false );
		} finally {
			DbLock::release( self::QUEUE_LOCK );
		}
		$due = $taken;
		if ( ! $due ) { return 0; }
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
		self::close_ended();
		self::purge_unconfirmed();
		$types = $wpdb->get_col( "SELECT DISTINCT w.ticket_type_id FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE w.status='waiting' AND o.status='scheduled' AND " . Occurrence::type_sale_sql( 'tt', 'o' ) . ' LIMIT 500' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
		if ( $types ) { self::queue( $types, Clock::now() ); }
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
		// Lock name unique per installation (A364), not only per table prefix.
		$lock = 'wl_' . $ticket_type_id;
		if ( ! DbLock::acquire( $lock ) ) { return 0; }
		$made = 0;
		try {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.max_per_order FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND tt.status='active' AND o.status='scheduled' AND e.status='published' AND " . Occurrence::type_sale_sql( 'tt', 'o' ), $ticket_type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
			if ( ! $type ) { return 0; }
			$available = Orders::availability( $ticket_type_id );
			$available = null === $available ? PHP_INT_MAX : (int) $available;
			if ( $available <= 0 ) { return 0; }
			$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE ticket_type_id=%d AND status='waiting' ORDER BY priority DESC,position,waitlist_id LIMIT 200", $ticket_type_id ) );
			foreach ( $entries as $entry ) {
				if ( $made >= $c['max_offers_per_run'] || $available <= 0 ) { break; }
				$quantity = max( 1, (int) $entry->quantity );
				if ( $quantity > max( 1, (int) $type->max_per_order ) ) {
					// Never offerable (maximum per order lowered after the sign-up): mark it, go on.
					self::block_entry( $entry, 'quantity_limit' );
					continue;
				}
				if ( $quantity > $available ) {
					if ( $c['strict_fifo'] ) { break; }
					continue;
				}
				$result = self::offer_entry( $entry );
				if ( is_wp_error( $result ) ) {
					// A mail failure only skips this entry.
					if ( 'wpcap_waitlist_mail' === $result->get_error_code() ) { continue; }
					// A563: a failure of this entry alone (buyer limit, attendee data …) must not stop the
					// queue: the entry is blocked with its reason and the next one gets the units.
					$reason = (string) ( $result->get_error_data()['reason'] ?? '' );
					if ( isset( self::BLOCK_REASONS[ $reason ] ) ) {
						self::block_entry( $entry, self::BLOCK_REASONS[ $reason ] );
						continue;
					}
					// Not enough seats for this quantity: like a quantity above the free units.
					if ( in_array( $reason, self::SKIP_REASONS, true ) ) {
						self::note_entry( $entry, 'seats' );
						if ( $c['strict_fifo'] ) { break; }
						continue;
					}
					// Capacity/sales state changed under the lock: stop.
					break;
				}
				$available -= $quantity;
				++$made;
			}
		} finally {
			DbLock::release( $lock );
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
		$now_ts = Clock::now();
		$expires_ts = $now_ts + $c['offer_hours'] * HOUR_IN_SECONDS;
		// Never beyond the end of online sales: the start, or a sales_end after it (A535).
		$phase = $wpdb->get_row( $wpdb->prepare( "SELECT o.starts_at,o.ends_at,tt.sales_end FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", (int) $row->ticket_type_id ) );
		if ( $phase ) { $expires_ts = min( $expires_ts, Occurrence::online_sales_until( $phase, $phase ) ); }
		// SET-PC-21: lock period before the end of online sales (waitlist offer_cutoff_minutes, default 15).
		if ( $expires_ts - $now_ts < $c['offer_cutoff_minutes'] * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'wpcap_waitlist_too_late', __( 'Für diesen Termin ist kein Wartelistenangebot mehr möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$hash = hash( 'sha256', $token );
		$now = Clock::mysql();
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='offered',offer_token_hash=%s,offer_expires_at=%s,offered_at=%s,order_id=0,updated_at=%s WHERE waitlist_id=%d AND status='waiting'", $hash, gmdate( 'Y-m-d H:i:s', $expires_ts ), $now, $now, (int) $row->waitlist_id ) );
		if ( 1 !== (int) $claimed ) { return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$quantity = max( 1, (int) $row->quantity );
		$order = Orders::create(
			array( 'email' => $row->email, 'items' => array( array( 'ticket_type_id' => (int) $row->ticket_type_id, 'quantity' => $quantity ) ), 'idempotency_key' => 'offer-' . (int) $row->waitlist_id . '-' . substr( $hash, 0, 16 ) ),
			array( 'external' => true, 'payment_provider' => self::OFFER_PROVIDER, 'hold_minutes' => (int) ceil( ( $expires_ts - $now_ts ) / MINUTE_IN_SECONDS ), 'key_namespace' => 'waitlist', 'skip_rate_limit' => true, 'source' => 'waitlist', 'defer_attendees' => true )
		);
		if ( is_wp_error( $order ) ) {
			self::revert_claim( (int) $row->waitlist_id, $hash );
			$data = (array) $order->get_error_data();
			// `reason`: machine-readable cause (sold_out, allocation_buyer_limit, seat_sold_out, …), see process_type().
			return new \WP_Error( 'wpcap_waitlist_unavailable', $order->get_error_message(), array( 'status' => 409, 'reason' => (string) ( $data['reason'] ?? $order->get_error_code() ) ) );
		}
		$order_id = (int) $order['order_id'];
		$held_until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active'", $order_id ) );
		$expires = '' !== $held_until ? $held_until : gmdate( 'Y-m-d H:i:s', $expires_ts );
		// Linked only while the claim stands: an entry removed meanwhile (unsubscribe, staff, privacy
		// erasure) no longer matches, and the hold just created must not outlive it.
		$linked = $wpdb->update( $table, array( 'order_id' => $order_id, 'offer_expires_at' => $expires ) + ( self::block_ready() ? array( 'block_reason' => '' ) : array() ), array( 'waitlist_id' => (int) $row->waitlist_id, 'offer_token_hash' => $hash, 'status' => 'offered' ) );
		if ( 1 !== (int) $linked ) {
			self::release_offer_order( $order_id, 'waitlist_offer_withdrawn' );
			return new \WP_Error( 'wpcap_waitlist_offer', __( 'Das Angebot konnte nicht erstellt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$accept_url = add_query_arg( array( 'wpcap_waitlist_accept' => (int) $row->waitlist_id, 'token' => $token ), home_url( '/' ) );
		$names = self::mail_names( $row->event_id, $row->ticket_type_id );
		$sent = self::send_mail( 'waitlist_offer', $row->email, array_merge( $names, array( 'offer_expires' => Frontend\Format::deadline( $expires, $names['timezone'] ), 'accept_link' => Mail\Mailer::link( $accept_url ), 'position' => (int) $row->position, 'quantity' => $quantity ) ), (int) $row->waitlist_id, self::ensure_unsubscribe_token( $row ) );
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
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='waiting',offer_token_hash='',offer_expires_at=NULL,offered_at=NULL,order_id=0,updated_at=%s WHERE waitlist_id=%d AND status='offered' AND offer_token_hash=%s", Clock::mysql(), absint( $waitlist_id ), $hash ) );
	}

	/** Whether the `block_reason` column exists (schema 3.10.14; deferred upgrades). */
	public static function block_ready() {
		return ServiceBookings::has_column( 'wpcap_waitlist', 'block_reason' );
	}

	/**
	 * Marks a waiting entry that cannot be offered as `blocked` with its reason (A563): the queue
	 * goes on with the next entry; staff see the reason and can unblock it.
	 *
	 * @param object $row    Entry.
	 * @param string $reason buyer_limit | quantity_limit | attendee_data.
	 */
	private static function block_entry( $row, $reason ) {
		global $wpdb;
		$reason = sanitize_key( (string) $reason );
		$set = self::block_ready() ? $wpdb->prepare( ',block_reason=%s', $reason ) : '';
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='blocked'$set,updated_at=%s WHERE waitlist_id=%d AND status='waiting'", Clock::mysql(), (int) $row->waitlist_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared fragment.
		if ( 1 !== (int) $changed ) { return false; }
		Audit::record( 'waitlist_blocked', 'waitlist', (int) $row->waitlist_id, array( 'reason' => $reason, 'ticket_type_id' => (int) $row->ticket_type_id, 'quantity' => (int) $row->quantity ) );
		/**
		 * A waitlist entry could not be offered and was blocked; the queue continues (A563).
		 *
		 * @param int    $waitlist_id Entry.
		 * @param string $reason      buyer_limit | quantity_limit | attendee_data.
		 */
		do_action( 'wpcap_waitlist_blocked', (int) $row->waitlist_id, $reason );
		return true;
	}

	/** Notes why a waiting entry was passed over in this run (status stays `waiting`). */
	private static function note_entry( $row, $reason ) {
		global $wpdb;
		if ( ! self::block_ready() || (string) ( $row->block_reason ?? '' ) === $reason ) { return; }
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET block_reason=%s WHERE waitlist_id=%d AND status='waiting'", sanitize_key( $reason ), (int) $row->waitlist_id ) );
	}

	/** Staff: puts a blocked entry back into the queue (same position) and processes its ticket type. */
	public static function unblock( $waitlist_id ) {
		global $wpdb;
		$row = self::get( $waitlist_id );
		if ( ! $row || 'blocked' !== $row->status ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Dieser Eintrag ist nicht gesperrt.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$reset = self::block_ready() ? ",block_reason=''" : '';
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='waiting'$reset,updated_at=%s WHERE waitlist_id=%d AND status='blocked'", Clock::mysql(), (int) $row->waitlist_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal fragment.
		if ( 1 !== (int) $changed ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Der Eintrag wurde gerade geändert. Bitte neu laden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		Audit::record( 'waitlist_unblocked', 'waitlist', (int) $row->waitlist_id, array( 'previous_reason' => (string) ( $row->block_reason ?? '' ), 'user_id' => get_current_user_id() ) );
		self::queue( array( (int) $row->ticket_type_id ) );
		return array( 'status' => 'waiting' );
	}

	/** Admin label of a block reason ('' for none). */
	public static function block_reason_label( $reason ) {
		$labels = array(
			'buyer_limit' => __( 'Käuferlimit erreicht (die Person hat schon Tickets für dieses Event)', 'pass-captain' ),
			'quantity_limit' => __( 'Gewünschte Anzahl über dem Höchstwert pro Bestellung', 'pass-captain' ),
			'attendee_data' => __( 'Teilnehmerdaten ungültig', 'pass-captain' ),
			'seats' => __( 'Zuletzt übersprungen: nicht genug freie Sitzplätze für die gewünschte Anzahl', 'pass-captain' ),
		);
		$reason = (string) $reason;
		return '' === $reason ? '' : ( $labels[ $reason ] ?? $reason );
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
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status=%s,offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status IN ('offered','accepted')", $status, Clock::mysql(), (int) $row->waitlist_id ) );
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
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='paid',offer_token_hash='',updated_at=%s WHERE order_id=%d AND status IN ('offered','accepted')", Clock::mysql(), absint( $order_id ) ) );
	}

	/**
	 * Reconciles open offers with their orders: lapsed holds are expired through the core cleanup
	 * (which fires `wpcap_capacity_released`), and entries whose order is no longer pending are
	 * closed even when that hook did not reach this module.
	 */
	public static function sync_lapsed() {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		$lapsed = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT w.order_id FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_orders ord ON ord.order_id=w.order_id WHERE w.status IN ('offered','accepted') AND ord.payment_status='pending' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=w.order_id AND r.status='active' AND r.expires_at > %s) ORDER BY w.order_id LIMIT 200", $now ) ) );
		// Exactly these orders: a backlog of other lapsed orders (expired by the jobs worker in batches)
		// must not keep an expired offer open.
		if ( $lapsed ) { Orders::expire_holds( 200, $lapsed ); }
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
		$token = strtolower( (string) preg_replace( '/[^a-f0-9]/', '', Http::str( $token ) ) );
		if ( 64 !== strlen( $token ) ) { return null; }
		$row = self::get( $waitlist_id );
		// A failed read is no invalid offer (A183): the buyer may try again.
		if ( '' !== $wpdb->last_error ) { return Errors::busy(); }
		$now = Clock::mysql();
		if ( ! $row || 'offered' !== $row->status || ! $row->order_id || (string) $row->offer_expires_at <= $now || ! hash_equals( (string) $row->offer_token_hash, hash( 'sha256', $token ) ) ) { return null; }
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active' AND expires_at > %s", (int) $row->order_id, $now ) );
		if ( '' !== $wpdb->last_error ) { return Errors::busy(); }
		return $held ? $row : null;
	}

	private static function invalid_offer() {
		return new \WP_Error( 'wpcap_waitlist_offer_invalid', __( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ), array( 'status' => 403 ) );
	}

	/**
	 * Accepts an offer. Free orders are completed (tickets issued); paid orders get the chosen
	 * payment method and keep their hold for the payment window.
	 *
	 * Attendee data is collected here (A536): the offer order was created without it, so required
	 * participant fields of the offered ticket types are enforced now (Orders::validate_attendees())
	 * and the data is stored on the order before payment/issuance.
	 *
	 * @param array $args provider (payment gateway id), accept_terms (bool), terms_version (version the
	 *                    page showed, A565), locale, attendees (list of
	 *                    {ticket_type_id, name, email, answers{field_key:value}} like the checkout).
	 * @return array|\WP_Error status, order (order_id, public_id, status, payment_status, total_minor, currency), payment?, inventory_reserved.
	 */
	public static function accept_offer( $waitlist_id, $token, array $args = array() ) {
		global $wpdb;
		$row = self::open_offer( $waitlist_id, $token );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! $row ) { return self::invalid_offer(); }
		$order = Orders::get( (int) $row->order_id );
		if ( ! $order && '' !== $wpdb->last_error ) { return Errors::busy(); }
		if ( ! $order || 'pending' !== $order->payment_status ) { return self::invalid_offer(); }
		$paid = (int) $order->total_minor > 0;
		// A565: like the checkout, the terms apply to free offers too, and the version the page showed
		// must still be the one in force.
		$locale = Mail\Mailer::normalize_locale( is_string( $args['locale'] ?? null ) ? $args['locale'] : '' );
		if ( '' === $locale ) { $locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ''; }
		if ( Settings::get( 'require_terms' ) ) {
			if ( ! Http::accepted( $args['accept_terms'] ?? null ) ) {
				return new \WP_Error( 'wpcap_terms_required', __( 'Bitte bestätige die Bedingungen und Verbraucherinformationen.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$shown = is_string( $args['terms_version'] ?? null ) ? $args['terms_version'] : '';
			if ( '' !== $shown && Settings::terms_version( $locale ) !== $shown ) {
				return new \WP_Error( 'wpcap_terms_changed', __( 'Die Bedingungen wurden inzwischen geändert. Bitte lade die Seite neu und bestätige die aktuelle Fassung.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		// A568: the wording behind the version stored with the order stays provable.
		LegalVersions::archive( $locale );
		$attendee_data = self::accepted_attendees( (int) $order->order_id, $args['attendees'] ?? array() );
		if ( is_wp_error( $attendee_data ) ) { return $attendee_data; }
		$gateway = null;
		// Free offers keep their hold a little longer while the order is completed; paid offers for
		// the payment window of the chosen method (never shortened).
		$minutes = self::ACCEPT_GUARD_MINUTES;
		if ( $paid ) {
			$enabled = Payments\Registry::enabled( $order->currency );
			$wanted = sanitize_key( $args['provider'] ?? '' );
			$gateway = $enabled[ $wanted ] ?? ( $enabled['manual'] ?? ( $enabled ? reset( $enabled ) : null ) );
			if ( ! $gateway ) { return new \WP_Error( 'wpcap_provider_unavailable', __( 'Diese Zahlungsart ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 400 ) ); }
			$minutes = max( Orders::HOSTED_HOLD_MINUTES, (int) Settings::get( 'hold_minutes' ) ); // Hosted pages end with the hold (A577).
			if ( 'manual' === $gateway->id() ) {
				// Bank transfer: never beyond the start (and not offered within manual_cutoff_hours).
				$minutes = Orders::manual_hold_minutes( array_map( static function ( $item ) { return (int) $item->ticket_type_id; }, Orders::items_for_order( (int) $order->order_id ) ) );
				if ( null === $minutes ) {
					return new \WP_Error( 'wpcap_manual_too_late', __( 'Eine Zahlung per Überweisung ist so kurz vor Beginn nicht mehr möglich. Bitte wähle eine andere Zahlungsart.', 'pass-captain' ), array( 'status' => 400 ) );
				}
			}
		}
		$table = $wpdb->prefix . 'wpcap_waitlist';
		$claimed = self::claim_offer( $row, $gateway ? $gateway->id() : '', $minutes, $attendee_data, $locale );
		if ( Errors::is_busy( $claimed ) ) {
			// Deadlock victim (e.g. against the expiry job at the deadline): everything was rolled
			// back; one more try sees the winner's result.
			usleep( 100000 );
			$claimed = self::claim_offer( $row, $gateway ? $gateway->id() : '', $minutes, $attendee_data, $locale );
		}
		if ( is_wp_error( $claimed ) ) { return $claimed; }
		$order_id = (int) $order->order_id;
		$payment = null;
		if ( ! $paid ) {
			$confirmed = Orders::confirm_external_payment( $order_id, 'waitlist:' . (int) $row->waitlist_id );
			$booked = Orders::get( $order_id );
			if ( is_wp_error( $confirmed ) && ( ! $booked || 'paid' !== $booked->payment_status ) ) {
				// Not booked: the offer is open again. A transient database error is reported as such
				// (the buyer retries the link), never as "sold out" (A183).
				self::reopen( $row );
				return Errors::is_busy( $confirmed ) ? $confirmed : new \WP_Error( 'wpcap_waitlist_sold_out', $confirmed->get_error_message(), array( 'status' => 409 ) );
			}
			self::on_order_paid( $order_id );
			if ( is_wp_error( $confirmed ) ) {
				// Booked, only the tickets are missing: the issuance is repeated by the next confirmation.
				return $confirmed;
			}
		} else {
			$fresh = (array) Orders::get( $order_id );
			$items = array_map( static function ( $item ) { return (array) $item; }, Orders::items_for_order( $order_id ) );
			$payment = $gateway->create_payment( $fresh, $items );
			if ( is_wp_error( $payment ) ) {
				$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payment_provider' => self::OFFER_PROVIDER ), array( 'order_id' => $order_id, 'payment_status' => 'pending' ) );
				self::reopen( $row );
				return Payments\StartFailures::handle( $gateway->id(), $order_id, $payment, 'waitlist_offer' );
			}
			if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( $order_id, $payment['provider_reference'] ); }
			$due = Payments\BankTransfer::due_at( $order_id );
			if ( '' !== $due ) { $wpdb->update( $table, array( 'offer_expires_at' => $due ), array( 'waitlist_id' => (int) $row->waitlist_id ) ); }
			// Order link (bank details, status) by mail, like a regular checkout.
			Orders::rotate_access_link( $order_id );
			$payment = array_intersect_key( $payment, array_flip( array( 'provider', 'status', 'redirect_url', 'instructions' ) ) );
			if ( Payments\BankTransfer::applies( $fresh ) ) {
				$bank = Payments\BankTransfer::details( $fresh );
				// Transfer request only with recipient and IBAN (A265); otherwise the instruction text.
				if ( ! empty( $bank['transfer'] ) ) { $payment['bank_transfer'] = $bank; } else { $payment['instructions'] = (string) $bank['instructions']; }
			}
		}
		Audit::record( 'waitlist_accepted', 'waitlist', (int) $row->waitlist_id, array( 'order_id' => $order_id, 'provider' => $gateway ? $gateway->id() : 'free' ) );
		$order = Orders::get( $order_id ) ?: ( Orders::get( $order_id ) ?: $order ); // Once more after a failed read.
		$result = array(
			'status' => $paid ? 'accepted' : 'paid',
			'order' => array( 'order_id' => $order_id, 'public_id' => $order->public_id, 'status' => $order->status, 'payment_status' => $order->payment_status, 'total_minor' => (int) $order->total_minor, 'currency' => $order->currency ),
			'inventory_reserved' => 'pending' === $order->payment_status,
		);
		if ( $payment ) { $result['payment'] = $payment; }
		return $result;
	}

	/** Active participant fields of ticket types (ticket_type_id => rows), for the offer page. */
	private static function attendee_fields( array $type_ids ) {
		global $wpdb;
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ' . ParticipantFields::columns() . " FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ") AND status='active' ORDER BY position,field_id", $type_ids ) ) as $field ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ParticipantFields::columns() is a column list from literals.
			$out[ (int) $field->ticket_type_id ][] = $field;
		}
		return $out;
	}

	/** Ticket type => quantity of an offer order. */
	private static function offer_lines( $order_id ) {
		$lines = array();
		foreach ( Orders::items_for_order( $order_id ) as $item ) {
			$lines[ (int) $item->ticket_type_id ] = ( $lines[ (int) $item->ticket_type_id ] ?? 0 ) + (int) $item->quantity;
		}
		return $lines;
	}

	/**
	 * Validates the attendee data sent with an acceptance against the offered ticket types (required
	 * fields enforced, A536) and returns it as JSON for the order, null when there is nothing to store.
	 *
	 * @return string|null|\WP_Error
	 */
	private static function accepted_attendees( $order_id, $attendees ) {
		$lines = self::offer_lines( $order_id );
		$attendees = is_array( $attendees ) ? $attendees : array();
		if ( ! $lines || ( ! $attendees && ! self::attendee_fields( array_keys( $lines ) ) ) ) { return null; }
		$clean = Orders::validate_attendees( $lines, $attendees );
		if ( is_wp_error( $clean ) ) { return $clean; }
		if ( ! $clean ) { return null; }
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
		$clean = Consents::annotate( $clean, 'waitlist', $locale, Consents::ip_hash( Orders::client_ip() ) );
		return (string) wp_json_encode( $clean );
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
	 * @param string|null $attendees Attendee data (JSON) for the order, null = unchanged.
	 * @param string|null $locale    Buyer locale (legal text version and mails); null = current.
	 * @return true|\WP_Error
	 */
	private static function claim_offer( $row, $provider, $minutes, $attendees = null, $locale = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = (int) $row->order_id;
		$now = Clock::mysql();
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Errors::busy( __( 'Das Angebot konnte gerade nicht angenommen werden. Bitte versuche es gleich noch einmal.', 'pass-captain' ) );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		// Each lock is checked on its own: last_error only reports the latest statement (A183).
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status,payment_provider FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		if ( '' !== $wpdb->last_error ) { return $busy(); }
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE waitlist_id=%d FOR UPDATE", (int) $row->waitlist_id ) );
		if ( '' !== $wpdb->last_error ) { return $busy(); }
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
		$until = gmdate( 'Y-m-d H:i:s', Clock::now() + max( 1, (int) $minutes ) * MINUTE_IN_SECONDS );
		$extended = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET expires_at=GREATEST(expires_at,%s) WHERE reservation_id IN (" . implode( ',', array_fill( 0, count( $held ), '%d' ) ) . ')', array_merge( array( $until ), $held ) ) );
		$assigned = true;
		$order_update = null === $attendees ? array() : array( 'attendee_data' => (string) $attendees );
		if ( null === $locale ) { $locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ''; }
		// The accepted version and the buyer language are stored for paid and free offers (A565/A568).
		$order_update += array( 'terms_version' => Settings::terms_version( $locale ), 'locale' => (string) $locale );
		if ( '' !== (string) $provider ) {
			$order_update += array( 'payment_provider' => (string) $provider );
		}
		if ( $order_update ) {
			$assigned = $wpdb->update( $p . 'wpcap_orders', $order_update + array( 'updated_at' => $now ), array( 'order_id' => $order_id ) );
		}
		if ( 1 !== (int) $claimed || false === $extended || false === $assigned || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			// The claim is the first write: if it is durable (COMMIT reply lost, or everything from
			// the claim on ran after a reconnect), the offer is this request's (A183).
			$state = $wpdb->get_row( $wpdb->prepare( "SELECT status,offer_token_hash FROM {$p}wpcap_waitlist WHERE waitlist_id=%d", (int) $row->waitlist_id ) );
			if ( ! $state || 'accepted' !== $state->status || '' !== (string) $state->offer_token_hash || '' !== $wpdb->last_error || 1 !== (int) $claimed || false === $extended || false === $assigned ) {
				return $busy();
			}
		}
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
		$reopen = static function () use ( $wpdb, $row ) {
			return $wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'status' => 'offered', 'offer_token_hash' => (string) $row->offer_token_hash, 'updated_at' => Clock::mysql() ), array( 'waitlist_id' => (int) $row->waitlist_id, 'status' => 'accepted' ) );
		};
		// Once more after a transient error; otherwise the entry follows its order (expiry sync).
		if ( false === $reopen() ) { $reopen(); }
	}

	/** Declines an open offer: the hold is released and the units go to the next entry. */
	public static function decline_offer( $waitlist_id, $token ) {
		global $wpdb;
		$row = self::open_offer( $waitlist_id, $token );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! $row ) { return self::invalid_offer(); }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='declined',offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status='offered' AND offer_token_hash=%s", Clock::mysql(), (int) $row->waitlist_id, (string) $row->offer_token_hash ) );
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
		$token = strtolower( (string) preg_replace( '/[^a-f0-9]/', '', Http::str( $token ) ) );
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
		$now = Clock::mysql();
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
		$html = $title . self::details_html( self::mail_names( $row->event_id, $row->ticket_type_id ), (int) $row->quantity, array( __( 'Status', 'pass-captain' ) => self::status_label( $row->status ) ) );
		$note = 'offered' === $row->status
			? __( 'Für dich sind gerade Tickets reserviert. Wenn du dich austrägst, verfällt dieses Angebot sofort und die Tickets gehen an die nächste Person auf der Warteliste.', 'pass-captain' )
			: __( 'Wenn du dich austrägst, bekommst du für diese Ticketart kein Angebot mehr. Deine E-Mail-Adresse wird aus dem Eintrag gelöscht.', 'pass-captain' );
		$html .= '<p>' . esc_html( $note ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::UNSUBSCRIBE_ACTION . '_' . $waitlist_id ) ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( self::SIG_FIELD ) . '" value="' . esc_attr( self::form_sig( 'unsubscribe', $waitlist_id, $token ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::UNSUBSCRIBE_ACTION ) . '"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) $waitlist_id ) . '"><input type="hidden" name="token" value="' . esc_attr( strtolower( (string) $token ) ) . '">';
		$html .= '<p><button type="submit">' . esc_html__( 'Jetzt von der Warteliste austragen', 'pass-captain' ) . '</button></p></form>';
		return array( 200, $html );
	}

	/**
	 * Proof of an offer, unsubscribe or "Jetzt bezahlen" form (A462): keyed hash of the entry and the
	 * secret of its link (offer token, unsubscribe token, result key). Unlike a WordPress nonce it does
	 * not depend on the login state and does not expire after 12–24 hours, so an offer that is open
	 * longer (offer_hours) or a page opened in the morning can still be answered; it stops working with
	 * the link itself.
	 *
	 * @param string $kind offer, unsubscribe or pay.
	 */
	public static function form_sig( $kind, $waitlist_id, $secret ) {
		$secret = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', (string) $secret ) );
		return hash_hmac( 'sha256', 'wpcap-waitlist-form|' . sanitize_key( $kind ) . '|' . absint( $waitlist_id ) . '|' . $secret, function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : ABSPATH );
	}

	/**
	 * Checks the form proof of a POST; forms rendered before the update carry only the WordPress
	 * nonce, which is still accepted (check_admin_referer(), which ends the request when it fails).
	 */
	private static function verify_form( $kind, $waitlist_id, $secret, $nonce_action ) {
		$sig = strtolower( sanitize_text_field( wp_unslash( (string) ( $_POST[ self::SIG_FIELD ] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the check.
		if ( '' !== (string) $secret && 64 === strlen( $sig ) && hash_equals( self::form_sig( $kind, $waitlist_id, $secret ), $sig ) ) { return; }
		check_admin_referer( $nonce_action );
	}

	/** template_redirect: confirmation page of a personal unsubscribe link. */
	public static function maybe_render_unsubscribe_page() {
		// Capability link (unsubscribe token); the page only shows a nonce-protected POST form.
		$waitlist_id = absint( $_GET[ self::UNSUBSCRIBE_QUERY ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $waitlist_id || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return; }
		PageCache::no_store( 'waitlist_unsubscribe' );
		Security\PrivatePage::send(); // Token page: no cache, no framing, no indexing, no referrer (audit FE-7).
		list( $code, $html ) = self::unsubscribe_page( $waitlist_id, $token );
		status_header( $code );
		self::page_head( __( 'Von der Warteliste austragen', 'pass-captain' ) );
		echo $html . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in unsubscribe_page().
		\PassCaptain\Http::end();
	}

	/** admin-post (POST only, form proof): performs the unsubscribe confirmed on the page. */
	public static function handle_unsubscribe_request() {
		if ( ! Http::is_post() ) {
			status_header( 405 );
			\PassCaptain\Http::end();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_form() checks the form proof (or the nonce of older pages) before anything changes.
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		$token = sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		self::verify_form( 'unsubscribe', $waitlist_id, $token, self::UNSUBSCRIBE_ACTION . '_' . $waitlist_id );
		$result = self::unsubscribe( $waitlist_id, $token, 'link' );
		PageCache::no_store( 'waitlist_result' );
		status_header( is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 409 ) : 200 );
		self::page_head( __( 'Von der Warteliste austragen', 'pass-captain' ) );
		echo self::unsubscribe_result_html( $result ) . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in unsubscribe_result_html().
		\PassCaptain\Http::end();
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

	/**
	 * Open entries (waiting, offered) with the buyer address of an order, for the order portal; only
	 * dates that are still scheduled, in the future and on sale (A557).
	 */
	public static function portal_entries( $order ) {
		global $wpdb;
		$email = strtolower( trim( (string) ( $order->customer_email ?? '' ) ) );
		if ( '' === $email || Mail\Mailer::is_placeholder( $email ) ) { return array(); }
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT w.waitlist_id,w.status,w.quantity,e.name AS event_name,tt.name AS ticket_name,o.starts_at,o.timezone FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_events e ON e.event_id=w.event_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE w.email_hash=%s AND w.status IN ('waiting','offered') AND o.status='scheduled' AND e.status='published' AND o.starts_at>%s AND ( tt.sales_end IS NULL OR tt.sales_end>%s ) ORDER BY w.waitlist_id LIMIT 20", hash( 'sha256', $email ), Clock::mysql(), Clock::mysql() ) );
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
		if ( ! $row || ! in_array( $row->status, array( 'pending', 'waiting', 'offered', 'accepted', 'blocked' ), true ) ) {
			return new \WP_Error( 'wpcap_waitlist_state', __( 'Dieser Eintrag ist bereits abgeschlossen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status=%s,offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status=%s", $status, Clock::mysql(), (int) $row->waitlist_id, $row->status ) );
		if ( 1 !== (int) $changed ) { return new \WP_Error( 'wpcap_waitlist_state', __( 'Der Eintrag wurde gerade geändert. Bitte neu laden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( (int) $row->order_id && in_array( $row->status, self::OPEN_OFFER, true ) ) {
			$order = Orders::get( (int) $row->order_id );
			if ( $order && 'pending' === $order->payment_status ) { self::release_offer_order( (int) $row->order_id, 'waitlist_' . $status ); }
		}
		Audit::record( 'waitlist_' . $status, 'waitlist', (int) $row->waitlist_id, array( 'previous' => $row->status, 'user_id' => get_current_user_id() ) );
		return array( 'status' => $status );
	}

	/** wpcap_occurrence_cancelled: closes the open entries of the cancelled date (A557). */
	public static function on_occurrence_cancelled( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT w.* FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id WHERE tt.occurrence_id=%d AND w.status IN ('waiting','offered') ORDER BY w.waitlist_id", absint( $occurrence_id ) ) );
		return self::close_entries( (array) $rows, 'cancelled' );
	}

	/** `wpcap_occurrence_phase_changed` (A562): started or ended dates close their open entries. */
	public static function on_phase_changed( $occurrence_id, $phase ) {
		return in_array( $phase, array( Occurrence::RUNNING, Occurrence::OVER ), true ) ? self::close_ended() : 0;
	}

	/**
	 * wpcap_occurrence_changed (A565): when the start or the location of a date changes, the people
	 * waiting for it (and those with an open offer) get the `waitlist_changed` mail with the new
	 * details and their unsubscribe link (setting `change_mail`, default on). Status changes
	 * (cancellation) are handled by on_occurrence_cancelled().
	 *
	 * @param array $changes field => [old, new] (UTC times).
	 * @return int Mails sent.
	 */
	public static function on_occurrence_changed( $occurrence_id, $changes = array() ) {
		global $wpdb;
		$changes = is_array( $changes ) ? array_intersect_key( $changes, array_flip( array( 'starts_at', 'location' ) ) ) : array();
		if ( ! $changes || ! self::config()['change_mail'] ) { return 0; }
		$p = $wpdb->prefix;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT w.*,o.timezone FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.occurrence_id=%d AND o.status='scheduled' AND w.status IN ('waiting','offered') AND w.email<>'' ORDER BY w.waitlist_id LIMIT 2000", absint( $occurrence_id ) ) );
		$sent = 0;
		foreach ( $rows as $row ) {
			if ( ! is_email( (string) $row->email ) ) { continue; }
			$names = self::mail_names( $row->event_id, $row->ticket_type_id );
			$old_date = isset( $changes['starts_at'][0] ) && '' !== (string) $changes['starts_at'][0] ? Orders::format_start( (string) $changes['starts_at'][0], (string) $row->timezone ) : $names['event_date'];
			$old_location = isset( $changes['location'] ) ? (string) $changes['location'][0] : $names['event_location'];
			$sent += self::send_mail( 'waitlist_changed', (string) $row->email, array_merge( $names, array( 'old_date' => $old_date, 'old_location' => $old_location, 'quantity' => (int) $row->quantity, 'status_note' => 'offered' === $row->status ? static function () { return __( 'Dein Angebot bleibt bis zum angegebenen Zeitpunkt gültig.', 'pass-captain' ); } : static function () { return __( 'Du bleibst auf der Warteliste.', 'pass-captain' ); } ) ), (int) $row->waitlist_id, self::ensure_unsubscribe_token( $row ) ) ? 1 : 0;
		}
		if ( $rows ) { Audit::record( 'waitlist_change_mailed', 'occurrence', absint( $occurrence_id ), array( 'entries' => count( $rows ), 'sent' => $sent, 'fields' => array_keys( $changes ) ) ); }
		return $sent;
	}

	/**
	 * Closes open entries whose date can no longer be offered (A557): occurrence or event cancelled,
	 * online sales over by the occurrence's phase (Occurrence::type_sale_sql(): started, except a
	 * ticket type with a sales_end after the start – A535/A562), or the sales window ended. Runs in
	 * the sweep and on phase changes.
	 *
	 * @return int Entries closed.
	 */
	public static function close_ended( $limit = 200 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT w.*,CASE WHEN o.status='cancelled' OR e.status='cancelled' THEN 'cancelled' ELSE 'ended' END AS close_reason FROM {$p}wpcap_waitlist w INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=w.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE w.status IN ('waiting','offered') AND ( o.status='cancelled' OR e.status='cancelled' OR NOT " . Occurrence::type_sale_sql( 'tt', 'o', $now ) . " OR ( tt.sales_end IS NOT NULL AND tt.sales_end<=%s ) ) ORDER BY w.waitlist_id LIMIT %d", $now, max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragment.
		$closed = 0;
		foreach ( array( 'cancelled', 'ended' ) as $reason ) {
			$closed += self::close_entries( array_values( array_filter( (array) $rows, static function ( $row ) use ( $reason ) { return $reason === $row->close_reason; } ) ), $reason );
		}
		return $closed;
	}

	/**
	 * Closes entries (status `closed`): an open offer is withdrawn and its hold released; with the
	 * setting `close_mail` (default on) the person gets the `waitlist_closed` mail.
	 *
	 * @param object[] $rows   Waitlist rows (waiting/offered).
	 * @param string   $reason cancelled|ended.
	 * @return int Entries closed.
	 */
	private static function close_entries( array $rows, $reason ) {
		global $wpdb;
		$closed = 0;
		$mail = (bool) self::config()['close_mail'];
		foreach ( $rows as $row ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_waitlist SET status='closed',offer_token_hash='',updated_at=%s WHERE waitlist_id=%d AND status=%s", Clock::mysql(), (int) $row->waitlist_id, (string) $row->status ) );
			if ( 1 !== (int) $changed ) { continue; }
			++$closed;
			if ( 'offered' === $row->status && (int) $row->order_id ) {
				$order = Orders::get( (int) $row->order_id );
				if ( $order && 'pending' === $order->payment_status ) { self::release_offer_order( (int) $row->order_id, 'waitlist_closed' ); }
			}
			Audit::record( 'waitlist_closed', 'waitlist', (int) $row->waitlist_id, array( 'previous' => (string) $row->status, 'reason' => $reason ) );
			if ( $mail && is_email( (string) $row->email ) ) {
				$names = self::mail_names( $row->event_id, $row->ticket_type_id );
				Mail\Mailer::send( 'waitlist_closed', (string) $row->email, $names + array(
					'reason' => static function () use ( $reason ) { return 'cancelled' === $reason ? __( 'Der Termin wurde abgesagt.', 'pass-captain' ) : __( 'Der Termin hat begonnen bzw. der Verkauf ist beendet.', 'pass-captain' ); },
				) );
			}
		}
		return $closed;
	}

	/** Sets the priority of an entry (higher first; position decides within one priority). */
	public static function set_priority( $waitlist_id, $priority ) {
		global $wpdb;
		$priority = max( -1000, min( 1000, (int) $priority ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_waitlist', array( 'priority' => $priority, 'updated_at' => Clock::mysql() ), array( 'waitlist_id' => absint( $waitlist_id ) ) );
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
	/**
	 * Entries of an event (optionally of one occurrence), queue order per ticket type.
	 *
	 * @param int $occurrence_id 0 = all dates of the event.
	 */
	public static function entries( $event_id, $occurrence_id = 0, $limit = 500 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT w.*,tt.name AS ticket_name,o.starts_at,o.timezone,ord.public_id AS order_public_id,ord.payment_status AS order_payment_status FROM %i w LEFT JOIN %i tt ON tt.ticket_type_id=w.ticket_type_id LEFT JOIN %i o ON o.occurrence_id=w.occurrence_id LEFT JOIN %i ord ON ord.order_id=w.order_id WHERE w.event_id=%d AND (%d=0 OR w.occurrence_id=%d) ORDER BY w.ticket_type_id,FIELD(w.status,'offered','accepted','waiting') DESC,w.priority DESC,w.position,w.waitlist_id LIMIT %d", $p . 'wpcap_waitlist', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_orders', absint( $event_id ), $occurrence_id, $occurrence_id, max( 1, absint( $limit ) ) ) );
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
			'pending' => __( 'Unbestätigt (E-Mail-Bestätigung offen)', 'pass-captain' ),
			'waiting' => __( 'Wartet', 'pass-captain' ),
			'offered' => __( 'Angebot offen', 'pass-captain' ),
			'accepted' => __( 'Angenommen, Zahlung offen', 'pass-captain' ),
			'paid' => __( 'Bezahlt', 'pass-captain' ),
			'expired' => __( 'Abgelaufen', 'pass-captain' ),
			'declined' => __( 'Abgelehnt', 'pass-captain' ),
			'skipped' => __( 'Übersprungen', 'pass-captain' ),
			'removed' => __( 'Entfernt', 'pass-captain' ),
			'closed' => __( 'Geschlossen (Termin abgesagt oder vorbei)', 'pass-captain' ),
			'blocked' => __( 'Nicht anbietbar', 'pass-captain' ),
		);
		$status = 'unconfirmed' === $status ? 'pending' : $status; // Legacy double opt-in status (renamed by the 3.18.0 upgrade).
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
		PageCache::no_store( 'waitlist_offer' );
		Security\PrivatePage::send(); // No page cache, no framing, no indexing, no referrer (secret link, audit FE-7).
		$row = self::open_offer( $waitlist_id, $token );
		$busy = is_wp_error( $row );
		$row = $busy ? null : $row;
		// A317: the offer was already answered with this link (mail link opened again, back button):
		// show the result with its payment status instead of "invalid".
		$done = ( ! $row && ! $busy ) ? self::answered_result_url( $waitlist_id, $token ) : '';
		if ( '' !== $done ) { self::redirect( $done ); return; }
		status_header( $row ? 200 : ( $busy ? 503 : 410 ) );
		$order = $row ? Orders::get( (int) $row->order_id ) : null;
		$gateways = $order && (int) $order->total_minor > 0 ? Payments\Registry::enabled( $order->currency ) : array();
		self::page_head( __( 'Wartelistenangebot annehmen', 'pass-captain' ) );
		echo '<h1>' . esc_html__( 'Wartelistenangebot annehmen', 'pass-captain' ) . '</h1>';
		if ( ! $row || ! $order ) {
			echo '<p>' . esc_html__( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ) . '</p></main></body></html>';
			\PassCaptain\Http::end();
		}
		echo self::offer_details_html( $row, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in offer_details_html().
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-offer-form">';
		// A462: the offer may stay open longer than a WordPress nonce lives (offer_hours > 12–24 h);
		// the form proof is bound to the offer token instead and never expires on its own.
		echo '<input type="hidden" name="' . esc_attr( self::SIG_FIELD ) . '" value="' . esc_attr( self::form_sig( 'offer', $waitlist_id, $token ) ) . '">';
		echo '<input type="hidden" name="action" value="wpcap_accept_waitlist"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) $waitlist_id ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		echo self::attendee_form_html( (int) $order->order_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in attendee_form_html().
		if ( count( $gateways ) > 1 ) {
			echo '<fieldset><legend>' . esc_html__( 'Zahlungsart', 'pass-captain' ) . '</legend>';
			$first = true;
			foreach ( $gateways as $id => $gateway ) {
				echo '<label><input type="radio" name="provider" value="' . esc_attr( $id ) . '"' . ( $first ? ' checked' : '' ) . '> ' . esc_html( $gateway->title() ) . '</label><br>';
				$first = false;
			}
			echo '</fieldset>';
		}
		echo self::legal_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in legal_html().
		echo '<p><button type="submit" name="decision" value="accept">' . esc_html__( 'Angebot annehmen', 'pass-captain' ) . '</button> <button type="submit" name="decision" value="decline" formnovalidate>' . esc_html__( 'Angebot ablehnen', 'pass-captain' ) . '</button></p></form>';
		echo self::submit_once_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script.
		echo '</main></body></html>';
		\PassCaptain\Http::end();
	}

	/**
	 * Attendee inputs of the offer page (A536): name and the participant fields of every offered
	 * ticket, posted as `attendees[i][…]` like the checkout. '' when the ticket types have no fields.
	 */
	public static function attendee_form_html( $order_id ) {
		$lines = self::offer_lines( $order_id );
		$fields = self::attendee_fields( array_keys( $lines ) );
		if ( ! $fields ) { return ''; }
		$names = array();
		foreach ( Orders::items_for_order( $order_id ) as $item ) { $names[ (int) $item->ticket_type_id ] = (string) $item->name; }
		$html = '<fieldset><legend>' . esc_html__( 'Angaben zu den Teilnehmenden', 'pass-captain' ) . '</legend><p>' . esc_html__( 'Bitte für jedes Ticket ausfüllen; mit * markierte Felder sind Pflicht.', 'pass-captain' ) . '</p>';
		$i = 0;
		foreach ( $lines as $type_id => $quantity ) {
			for ( $n = 1; $n <= $quantity; $n++, $i++ ) {
				$prefix = 'attendees[' . $i . ']';
				$id = 'wpcap-wl-att-' . $i;
				/* translators: 1: ticket type, 2: number of the ticket */
				$html .= '<fieldset><legend>' . esc_html( sprintf( __( '%1$s – Ticket %2$d', 'pass-captain' ), $names[ $type_id ] ?? '', $n ) ) . '</legend>';
				$html .= '<input type="hidden" name="' . esc_attr( $prefix . '[ticket_type_id]' ) . '" value="' . esc_attr( (string) (int) $type_id ) . '">';
				$html .= '<p><label for="' . esc_attr( $id . '-name' ) . '">' . esc_html__( 'Name', 'pass-captain' ) . '</label><br><input type="text" id="' . esc_attr( $id . '-name' ) . '" name="' . esc_attr( $prefix . '[name]' ) . '" maxlength="190" autocomplete="name"></p>';
				foreach ( $fields[ $type_id ] ?? array() as $field ) {
					$name = $prefix . '[answers][' . $field->field_key . ']';
					$fid = $id . '-' . $field->field_key;
					$required = (int) $field->required ? ' required aria-required="true"' : '';
					$label = esc_html( $field->label . ( (int) $field->required ? ' *' : '' ) );
					$hint = '' !== (string) $field->purpose ? '<br><small>' . esc_html( (string) $field->purpose ) . '</small>' : '';
					if ( 'checkbox' === $field->field_type ) {
						$html .= '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $required . '> ' . $label . '</label>' . $hint . '</p>';
					} elseif ( 'textarea' === $field->field_type ) {
						$html .= '<p><label for="' . esc_attr( $fid ) . '">' . $label . '</label><br><textarea id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" maxlength="2000"' . $required . '></textarea>' . $hint . '</p>';
					} elseif ( '' !== ( $control = ParticipantFields::control_html( $field, array( 'id' => $fid, 'name' => $name ) ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
						// A680: select, multi-select, phone, date, number.
						$html .= '<p><label for="' . esc_attr( $fid ) . '">' . $label . '</label><br>' . $control . $hint . '</p>';
					} else {
						$html .= '<p><label for="' . esc_attr( $fid ) . '">' . $label . '</label><br><input type="' . ( 'email' === $field->field_type ? 'email' : 'text' ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" maxlength="2000"' . $required . '>' . $hint . '</p>';
					}
				}
				$html .= '</fieldset>';
			}
		}
		return $html . '</fieldset>';
	}

	/**
	 * Offer details of the offer page (escaped HTML): intro, event, date with time zone, location,
	 * ticket type, quantity, price and deadline (A565: what the checkout shows before a purchase).
	 */
	public static function offer_details_html( $row, $order ) {
		$names = self::mail_names( $row->event_id, $row->ticket_type_id );
		$html = '<p>' . esc_html__( 'Für dich sind Tickets reserviert. Nimm das Angebot bis zum angegebenen Zeitpunkt an, sonst geht es an die nächste Person auf der Warteliste.', 'pass-captain' ) . '</p>';
		return $html . self::details_html( $names, (int) $row->quantity, array( __( 'Preis', 'pass-captain' ) => Frontend\Format::money( (int) $order->total_minor, $order->currency ), __( 'Reserviert bis', 'pass-captain' ) => Frontend\Format::deadline( (string) $row->offer_expires_at, $names['timezone'] ) ) );
	}

	/**
	 * Legal texts of the offer page like the checkout (A565): terms and consumer information in the
	 * page language, the version shown (checked again on acceptance) and the consent checkbox when
	 * the setting require_terms is on – for paid and free offers alike. Escaped HTML.
	 */
	public static function legal_html() {
		$legal = PublicEvents::legal_context();
		if ( ! $legal['require_terms'] ) { return ''; }
		$html = '';
		if ( ! empty( $legal['locale'] ) ) { $html .= '<input type="hidden" name="locale" value="' . esc_attr( $legal['locale'] ) . '">'; }
		if ( ! empty( $legal['terms_version'] ) ) { $html .= '<input type="hidden" name="terms_version" value="' . esc_attr( $legal['terms_version'] ) . '">'; }
		if ( '' !== trim( (string) $legal['terms_text'] ) ) { $html .= '<details class="wpcap-legal"><summary>' . esc_html__( 'Bedingungen', 'pass-captain' ) . '</summary>' . wp_kses_post( wpautop( $legal['terms_text'] ) ) . '</details>'; }
		$has_info = '' !== trim( (string) $legal['consumer_info_text'] );
		if ( $has_info ) { $html .= '<details class="wpcap-legal"><summary>' . esc_html__( 'Verbraucherinformationen', 'pass-captain' ) . '</summary>' . wp_kses_post( wpautop( $legal['consumer_info_text'] ) ) . '</details>'; }
		$html .= '<p><label><input type="checkbox" name="accept_terms" value="1" required> ' . esc_html( $has_info ? __( 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.', 'pass-captain' ) : __( 'Ich akzeptiere die Bedingungen.', 'pass-captain' ) ) . '</label></p>';
		return $html;
	}

	private static function page_head( $title ) {
		?><!doctype html><html <?php echo \PassCaptain\Frontend\Format::html_lang_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo esc_html( $title ); ?></title></head><body><main><?php
	}

	public static function handle_accept_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_form() checks the form proof (or the nonce of older pages) before anything changes.
		$waitlist_id = absint( $_POST['waitlist_id'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		Security\PrivatePage::send(); // The answer pages carry the offer/result token (audit FE-7).
		if ( 'pay' === $decision ) {
			// "Jetzt bezahlen" on the result page (A317): authorised by the result key, not the offer token.
			$key = sanitize_text_field( wp_unslash( (string) ( $_POST['wl'] ?? '' ) ) );
			self::verify_form( 'pay', $waitlist_id, $key, 'wpcap_accept_waitlist_' . $waitlist_id );
			$target = self::pay_target( $waitlist_id, $key );
			self::redirect( $target, 0 !== strpos( $target, home_url() ) );
			return;
		}
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) ) ) );
		self::verify_form( 'offer', $waitlist_id, $token, 'wpcap_accept_waitlist_' . $waitlist_id );
		$decline = 'decline' === $decision;
		// Attendee data (A536) is sanitised field by field in Orders::validate_attendees().
		$attendees = isset( $_POST['attendees'] ) && is_array( $_POST['attendees'] ) ? wp_unslash( $_POST['attendees'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated per configured field.
		$result = $decline ? self::decline_offer( $waitlist_id, $token ) : self::accept_offer( $waitlist_id, $token, array( 'provider' => sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) ), 'accept_terms' => Http::accepted( sanitize_text_field( wp_unslash( (string) ( $_POST['accept_terms'] ?? '' ) ) ) ), 'terms_version' => sanitize_text_field( wp_unslash( (string) ( $_POST['terms_version'] ?? '' ) ) ), 'locale' => sanitize_text_field( wp_unslash( (string) ( $_POST['locale'] ?? '' ) ) ), 'attendees' => $attendees ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// A317: Post/Redirect/Get. An answered offer goes to its result page (reload and back never post
		// again); a paid acceptance with a hosted payment page goes straight there. A second submit of an
		// offer that was just answered (double click) lands on the same result page.
		$target = self::answer_target( $waitlist_id, $token, $result );
		if ( '' !== $target ) {
			self::redirect( $target, 0 !== strpos( $target, home_url() ) );
			return;
		}
		PageCache::no_store( 'waitlist_result' );
		status_header( is_wp_error( $result ) ? 409 : 200 );
		/* translators: %s: site name */
		self::page_head( sprintf( __( 'Warteliste – %s', 'pass-captain' ), Orders::site_name() ) );
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'wpcap_attendee_field_required', 'wpcap_attendee_field_invalid', 'wpcap_attendees_invalid', 'wpcap_terms_required', 'wpcap_terms_changed', 'wpcap_provider_unavailable', 'wpcap_manual_too_late' ), true ) ) {
			// Input problem: the offer stays open, back to the form.
			echo '<h1>' . esc_html__( 'Bitte Angaben prüfen', 'pass-captain' ) . '</h1><p role="alert">' . esc_html( $result->get_error_message() ) . '</p><p><a href="' . esc_url( add_query_arg( array( 'wpcap_waitlist_accept' => $waitlist_id, 'token' => $token ), home_url( '/' ) ) ) . '">' . esc_html__( 'Zurück zum Angebot', 'pass-captain' ) . '</a></p>';
		} elseif ( is_wp_error( $result ) ) {
			echo '<h1>' . esc_html__( 'Angebot nicht verfügbar', 'pass-captain' ) . '</h1><p>' . esc_html( $result->get_error_message() ) . '</p>';
		}
		echo '</main></body></html>';
		\PassCaptain\Http::end();
	}

	/* ---------------------------------------------------------------------------------------------
	 * A317: result page of an answered offer (Post/Redirect/Get).
	 * ------------------------------------------------------------------------------------------- */

	/** Transient that remembers which offer token answered an entry (reload, double click, mail link again). */
	private static function answered_key( $waitlist_id ) {
		return 'wpcap_wl_answered_' . absint( $waitlist_id );
	}

	/**
	 * Key of the result page: keyed hash of entry and order. The offer token is cleared on acceptance,
	 * so the result link carries its own authorisation (no personal data in the URL).
	 */
	public static function result_key( $waitlist_id, $public_id ) {
		return substr( hash_hmac( 'sha256', 'wpcap-waitlist-result|' . absint( $waitlist_id ) . '|' . (string) $public_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** Result page URL of an entry ('' without order). */
	public static function result_url( $waitlist_id ) {
		global $wpdb;
		$public_id = (string) $wpdb->get_var( $wpdb->prepare( "SELECT o.public_id FROM {$wpdb->prefix}wpcap_waitlist w INNER JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=w.order_id WHERE w.waitlist_id=%d", absint( $waitlist_id ) ) );
		if ( '' === $public_id ) { return ''; }
		return home_url( '/' ) . '?' . http_build_query( array( 'wpcap_waitlist_result' => absint( $waitlist_id ), 'wl' => self::result_key( $waitlist_id, $public_id ) ) );
	}

	/** Result URL if this token already answered the entry, else ''. */
	public static function answered_result_url( $waitlist_id, $token ) {
		$hash = get_transient( self::answered_key( $waitlist_id ) );
		$token = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', (string) $token ) );
		if ( ! is_string( $hash ) || 64 !== strlen( $token ) || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) { return ''; }
		return self::result_url( $waitlist_id );
	}

	/**
	 * Where the answer POST continues ('' = render the error page): the provider's payment page for a
	 * paid acceptance, else the result page; for an offer answered just before by the same token the
	 * result page as well.
	 *
	 * @param array|\WP_Error $result accept_offer()/decline_offer() result.
	 */
	public static function answer_target( $waitlist_id, $token, $result ) {
		if ( is_wp_error( $result ) ) {
			return 'wpcap_waitlist_offer_invalid' === $result->get_error_code() ? self::answered_result_url( $waitlist_id, $token ) : '';
		}
		set_transient( self::answered_key( $waitlist_id ), hash( 'sha256', strtolower( (string) $token ) ), 30 * DAY_IN_SECONDS );
		$redirect = (string) ( $result['payment']['redirect_url'] ?? '' );
		if ( preg_match( '#^https://#i', $redirect ) ) { return $redirect; }
		return self::result_url( $waitlist_id );
	}

	/** Entry and order for a result key, or null. */
	private static function result_row( $waitlist_id, $key ) {
		$row = self::get( $waitlist_id );
		$order = $row && $row->order_id ? Orders::get( (int) $row->order_id ) : null;
		if ( ! $order || ! is_string( $key ) || ! hash_equals( self::result_key( $waitlist_id, $order->public_id ), $key ) ) { return null; }
		return array( $row, $order );
	}

	/**
	 * Target of "Jetzt bezahlen" on the result page: the (still open or a new) payment session of the
	 * accepted offer's order; the result page itself when there is nothing to pay.
	 */
	public static function pay_target( $waitlist_id, $key ) {
		$found = self::result_row( $waitlist_id, $key );
		if ( ! $found ) { return home_url( '/' ); }
		list( $row, $order ) = $found;
		$back = self::result_url( $waitlist_id );
		if ( 'accepted' !== $row->status || 'pending' !== $order->payment_status || ! Orders::hold_end( (int) $order->order_id ) ) { return $back; }
		$gateway = Payments\Registry::get( (string) $order->payment_provider );
		if ( ! $gateway || 'manual' === $gateway->id() ) { return $back; }
		$payment = $gateway->create_payment( (array) $order, array_map( static function ( $item ) { return (array) $item; }, Orders::items_for_order( (int) $order->order_id ) ) );
		if ( is_wp_error( $payment ) || empty( $payment['redirect_url'] ) || ! preg_match( '#^https://#i', (string) $payment['redirect_url'] ) ) { return $back; }
		if ( ! empty( $payment['provider_reference'] ) ) { Orders::set_provider_reference( (int) $order->order_id, $payment['provider_reference'] ); }
		return (string) $payment['redirect_url'];
	}

	/** Result page body (without page frame): answer and payment status of the order. */
	public static function result_html( $waitlist_id, $key ) {
		$found = self::result_row( $waitlist_id, $key );
		if ( ! $found ) {
			return '<h1>' . esc_html__( 'Wartelistenangebot', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Wartelistenangebot ist ungültig oder abgelaufen.', 'pass-captain' ) . '</p>';
		}
		list( $row, $order ) = $found;
		if ( 'declined' === $row->status ) {
			return '<h1>' . esc_html__( 'Angebot abgelehnt', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Danke für deine Rückmeldung. Die Tickets gehen an die nächste Person auf der Warteliste.', 'pass-captain' ) . '</p>';
		}
		$out = '<h1>' . esc_html__( 'Angebot angenommen', 'pass-captain' ) . '</h1>';
		/* translators: %s: short order number */
		$out .= '<p>' . esc_html( sprintf( __( 'Bestellnummer: %s', 'pass-captain' ), Frontend\Format::order_reference( $order->public_id ) ) ) . '</p>';
		if ( 'paid' === $order->payment_status ) {
			return $out . '<p>' . esc_html__( 'Deine Bestellung ist abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'pass-captain' ) . '</p>';
		}
		$until = Orders::hold_end( (int) $order->order_id );
		if ( 'pending' !== $order->payment_status || ! $until ) {
			return $out . '<p>' . esc_html__( 'Die Reservierung ist abgelaufen, ohne dass eine Zahlung eingegangen ist. Die Tickets gehen an die nächste Person auf der Warteliste.', 'pass-captain' ) . '</p>';
		}
		/* translators: %s: date and time the reservation ends */
		$out .= '<p>' . esc_html( sprintf( __( 'Dein Ticket ist reserviert und die Bestellung wartet auf die Zahlung (bis %s). Den Link zu deiner Bestellung haben wir dir per E-Mail gesendet.', 'pass-captain' ), Frontend\Format::deadline( (int) $until, self::mail_names( $row->event_id, $row->ticket_type_id )['timezone'] ) ) ) . '</p>';
		if ( Payments\BankTransfer::applies( $order ) ) {
			$bank = Payments\BankTransfer::details( $order );
			if ( ! empty( $bank['transfer'] ) ) {
				$out .= '<dl>';
				foreach ( array( 'amount' => __( 'Betrag', 'pass-captain' ), 'recipient' => __( 'Empfänger', 'pass-captain' ), 'iban' => 'IBAN', 'bic' => 'BIC', 'remittance' => __( 'Verwendungszweck', 'pass-captain' ), 'due_date' => __( 'Zahlbar bis', 'pass-captain' ) ) as $field => $label ) {
					if ( '' !== (string) ( $bank[ $field ] ?? '' ) ) { $out .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $bank[ $field ] ) . '</dd>'; }
				}
				$out .= '</dl>';
			} elseif ( '' !== (string) ( $bank['instructions'] ?? '' ) ) {
				$out .= '<p>' . esc_html( (string) $bank['instructions'] ) . '</p>';
			}
			return $out;
		}
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcap-offer-form">';
		$out .= '<input type="hidden" name="' . esc_attr( self::SIG_FIELD ) . '" value="' . esc_attr( self::form_sig( 'pay', $waitlist_id, $key ) ) . '">';
		$out .= '<input type="hidden" name="action" value="wpcap_accept_waitlist"><input type="hidden" name="waitlist_id" value="' . esc_attr( (string) absint( $waitlist_id ) ) . '"><input type="hidden" name="wl" value="' . esc_attr( $key ) . '">';
		$out .= '<p><button type="submit" name="decision" value="pay">' . esc_html__( 'Jetzt bezahlen', 'pass-captain' ) . '</button></p></form>';
		return $out . self::submit_once_script();
	}

	/** template_redirect: ?wpcap_waitlist_result=<id>&wl=<key>. */
	public static function maybe_render_result_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability link (result key).
		$waitlist_id = absint( $_GET['wpcap_waitlist_result'] ?? 0 );
		$key = sanitize_text_field( wp_unslash( (string) ( $_GET['wl'] ?? '' ) ) );
		// phpcs:enable
		if ( ! $waitlist_id ) { return; }
		PageCache::no_store( 'waitlist_result' );
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		/* translators: %s: site name */
		self::page_head( sprintf( __( 'Warteliste – %s', 'pass-captain' ), Orders::site_name() ) );
		echo self::result_html( $waitlist_id, $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in result_html().
		echo '</main></body></html>';
		\PassCaptain\Http::end();
	}

	/**
	 * Locks the offer form after the first submit (A317): a double click or Enter sends one answer.
	 * The clicked decision travels in a hidden field, because disabled buttons are not submitted.
	 */
	private static function submit_once_script() {
		return Assets::inline_script_tag( 'document.querySelectorAll(".wpcap-offer-form").forEach(function(f){f.addEventListener("submit",function(e){if(f.dataset.sent){e.preventDefault();return;}f.dataset.sent="1";var b=e.submitter;if(b&&b.name){var h=document.createElement("input");h.type="hidden";h.name=b.name;h.value=b.value;f.appendChild(h);}setTimeout(function(){f.querySelectorAll("button").forEach(function(x){x.disabled=true;});},0);});});' );
	}

	/** 303 to $url and stop; external (payment provider) URLs via wp_redirect. */
	private static function redirect( $url, $external = false ) {
		// A462: the 303 of a personal link (result page, payment page) is never stored by a page cache.
		PageCache::no_store( 'waitlist_result' );
		if ( $external ) {
			wp_redirect( $url, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- payment page URL from the gateway.
		} else {
			wp_safe_redirect( $url, 303 );
		}
		\PassCaptain\Http::end();
	}

	/* ---------------------------------------------------------------------------------------------
	 * Compatibility with the hold-based waitlist of the audit line (kept as thin aliases; the offer
	 * logic above – placeholder orders with reservations – is the only implementation).
	 * ------------------------------------------------------------------------------------------- */

	/** Job type of the earlier implementation; still handled so queued jobs are not lost. */
	const JOB = 'waitlist_capacity_freed';

	/** Validity of a sign-up confirmation link (double opt-in) in hours (default of config() confirm_hours). */
	const CONFIRM_HOURS = 48;

	/** Alias of doi_ready(): the confirmation columns exist. */
	public static function schema_ready() {
		return self::doi_ready();
	}

	/** Offer acceptance period in hours (setting waitlist_offer_hours, filter wpcap_waitlist_offer_hours, 1–720). */
	public static function offer_hours() {
		return min( 720, max( 1, (int) apply_filters( 'wpcap_waitlist_offer_hours', (int) Security\Policy::get( 'waitlist_offer_hours' ) ) ) );
	}

	/** Sign-ups per network and hour across all events (filter wp_pass_captain_waitlist_ip_limit_v1). */
	public static function ip_limit_per_hour() {
		return max( 1, (int) apply_filters( 'wp_pass_captain_waitlist_ip_limit_v1', 60 ) );
	}

	/** Tickets of a ticket type held by open offers (placeholder orders of offered/accepted entries). */
	public static function held_quantity( $type, $occurrence = null, $exclude_waitlist_id = 0 ) {
		global $wpdb;
		unset( $occurrence );
		$type_id = is_object( $type ) ? (int) ( $type->ticket_type_id ?? 0 ) : absint( $type );
		if ( ! $type_id ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM %i WHERE ticket_type_id=%d AND status IN ('offered','accepted') AND waitlist_id<>%d", $wpdb->prefix . 'wpcap_waitlist', $type_id, absint( $exclude_waitlist_id ) ) );
	}

	/** Alias of close( $id, 'removed' ) (admin list of the audit line). */
	public static function remove( $waitlist_id ) {
		return self::close( $waitlist_id, 'removed' );
	}

	/** Alias of process_type(): offers freed capacity of a ticket type to the next entries. */
	public static function auto_offer( $ticket_type_id ) {
		return self::process_type( $ticket_type_id );
	}

	/** Jobs of the earlier implementation (wp_pass_captain_process_job_v1): queued for run_due(). */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::JOB !== $type ) { return $handled; }
		$ids = array_filter( array_map( 'absint', (array) ( $payload['ticket_type_ids'] ?? ( isset( $payload['ticket_type_id'] ) ? array( $payload['ticket_type_id'] ) : array() ) ) ) );
		if ( $ids ) { self::queue( $ids ); }
		return true;
	}
}
