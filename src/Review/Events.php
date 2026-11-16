<?php
/**
 * Events of the review participation contract (A667, A669): maps the core hooks to outbox events of
 * Review\Contract (Workflow\Outbox::record()) – after the commit, idempotent, without personal data.
 *
 * - experience_completed: first valid admission of a ticket (wpcap_ticket_checked_in) or an allowed
 *   attendance confirmation (wpcap_attendance_confirmed, command attendance.confirm, A676). Only for
 *   paid, not refunded tickets of dates and events that are not cancelled. One per completion cycle:
 *   a new cycle starts only after the admission was undone (experience_excluded check_in_undone).
 * - experience_excluded per ticket: check_in_undone (no valid admission left), refunded, revoked,
 *   transferred (previous recipient_ref no longer valid) and no_show (cron: valid event tickets
 *   without admission NO_SHOW_GRACE_HOURS after the end of the date, for dates that ended within
 *   NO_SHOW_WINDOW_DAYS).
 * - experience_cancelled once per cancelled date or event (scope occurrence|event).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Review;

use PassCaptain\Workflow\Outbox;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Events {
	/** After the core, module and workflow listeners of the same hooks. */
	const PRIORITY = 62;
	const NO_SHOW_GRACE_HOURS = 6;
	const NO_SHOW_WINDOW_DAYS = 14;
	const NO_SHOW_BATCH = 200;

	public static function register() {
		$p = self::PRIORITY;
		add_action( 'wpcap_ticket_checked_in', array( __CLASS__, 'checked_in' ), $p, 2 );
		add_action( 'wpcap_attendance_confirmed', array( __CLASS__, 'confirmed' ), $p, 2 );
		add_action( 'wpcap_checkin_undone', array( __CLASS__, 'undone' ), $p, 2 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund_completed' ), $p, 2 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'order_refunded' ), $p, 2 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'revoked' ), $p, 2 );
		add_action( 'wpcap_ticket_transferred', array( __CLASS__, 'transferred' ), $p, 2 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_cancelled' ), $p, 2 );
		add_action( 'wpcap_event_cancelled', array( __CLASS__, 'event_cancelled' ), $p, 2 );
		add_action( 'wpcap_process_jobs', array( __CLASS__, 'scan_no_shows' ), 24, 0 );
	}

	/* ------------------------------------------------------------------ Participation (no PII) */

	/** Ticket row with order, date and event state; null when unknown. */
	private static function row( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.status,t.refunded,t.ticket_type_id,t.transfer_count,tt.occurrence_id,o.event_id,o.status AS occurrence_status,e.status AS event_status,ord.payment_status,ord.locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
	}

	/** Holder generation of a ticket: grows with every holder change and transfer. */
	public static function generation( $ticket_id, $row = null ) {
		$row = $row ? $row : self::row( $ticket_id );
		return \PassCaptain\TicketAssignments::holder_changes( (int) $ticket_id ) + (int) ( $row->transfer_count ?? 0 );
	}

	/** Opaque recipient reference of a ticket's current holder (A668); changes with every holder change. */
	public static function recipient_ref( $ticket_id, $generation = null ) {
		$generation = null === $generation ? self::generation( $ticket_id ) : (int) $generation;
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'wpcap-review';
		return 'rcp_' . substr( hash_hmac( 'sha256', 'review-recipient|' . (int) $ticket_id . '|' . $generation, $salt ), 0, 32 );
	}

	/** Experience id of a date (the experience a participation belongs to). */
	public static function experience_id( $occurrence_id ) {
		return 'occurrence:' . (int) $occurrence_id;
	}

	/** Participation object of the contract; null when the ticket is unknown. */
	public static function participation( $ticket_id, $row = null ) {
		$row = $row ? $row : self::row( $ticket_id );
		if ( ! $row ) { return null; }
		return array(
			'participation_id' => (string) $row->public_id,
			'experience_id' => self::experience_id( (int) $row->occurrence_id ),
			'event_id' => (int) $row->event_id,
			'occurrence_id' => (int) $row->occurrence_id,
			'ticket_type_id' => (int) $row->ticket_type_id,
			'recipient_ref' => self::recipient_ref( (int) $row->ticket_id, self::generation( (int) $row->ticket_id, $row ) ),
			'locale' => (string) $row->locale,
		);
	}

	/**
	 * Why a participation cannot count as a completed experience ('' = it can): unpaid, refunded,
	 * revoked/cancelled ticket, cancelled date or event.
	 */
	public static function ineligible( $row ) {
		if ( ! $row ) { return 'unknown'; }
		if ( 'paid' !== (string) $row->payment_status ) { return 'unpaid'; }
		if ( (int) $row->refunded ) { return 'refunded'; }
		if ( ! in_array( (string) $row->status, array( 'valid', 'checked_in' ), true ) ) { return 'revoked'; }
		if ( 'cancelled' === (string) $row->occurrence_status || 'cancelled' === (string) $row->event_status ) { return 'cancelled'; }
		return '';
	}

	/** Valid admissions of a ticket (admissions minus undone ones). */
	public static function admissions( $ticket_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(result='checked_in')-SUM(result='undone'),0) FROM {$wpdb->prefix}wpcap_checkins WHERE ticket_id=%d AND result IN ('checked_in','undone')", absint( $ticket_id ) ) );
	}

	/** Whether the ticket's experience is completed in the current cycle (not undone since). */
	public static function completed( $ticket_id ) {
		if ( ! Outbox::ready() || ! Outbox::multi_ready() ) { return self::admissions( $ticket_id ) > 0; }
		return self::recorded( Outbox::event_id( 'experience_completed', 'rv-done|' . (int) $ticket_id . '|' . self::cycle( $ticket_id ) ) );
	}

	/** Completion cycle: number of completions already taken back by an undo. */
	private static function cycle( $ticket_id ) {
		global $wpdb;
		if ( ! Outbox::multi_ready() ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Outbox::table() . " WHERE contract='review' AND event_type='experience_excluded' AND subject_id=%s AND event_id IN (" . implode( ',', array_fill( 0, 20, '%s' ) ) . ')', array_merge( array( (string) self::public_id( $ticket_id ) ), self::undo_ids( $ticket_id, 20 ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
	}

	/** Event ids of the first $n check_in_undone exclusions of a ticket. */
	private static function undo_ids( $ticket_id, $n ) {
		$ids = array();
		for ( $i = 0; $i < $n; $i++ ) { $ids[] = Outbox::event_id( 'experience_excluded', 'rv-ex|' . (int) $ticket_id . '|check_in_undone|' . $i ); }
		return $ids;
	}

	private static function public_id( $ticket_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
	}

	/** Whether an outbox event with this id exists. */
	private static function recorded( $event_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT outbox_id FROM %i WHERE event_id=%s', Outbox::table(), (string) $event_id ) );
	}

	private static function now() {
		return gmdate( 'Y-m-d\TH:i:s\Z', \PassCaptain\Clock::now() );
	}

	/* ------------------------------------------------------------------ Completion */

	/** wpcap_ticket_checked_in: the first valid admission completes the experience. */
	public static function checked_in( $ticket_id, $context = array() ) {
		$context = is_array( $context ) ? $context : array();
		return self::complete( $ticket_id, 'check_in', sanitize_key( (string) ( $context['source'] ?? 'web' ) ) );
	}

	/** wpcap_attendance_confirmed: an explicitly allowed attendance confirmation (A676). */
	public static function confirmed( $ticket_id, $context = array() ) {
		$context = is_array( $context ) ? $context : array();
		return self::complete( $ticket_id, 'confirmed', sanitize_key( (string) ( $context['source'] ?? 'command' ) ) );
	}

	private static function complete( $ticket_id, $method, $source ) {
		$row = self::row( $ticket_id );
		if ( '' !== self::ineligible( $row ) ) { return false; }
		$data = self::participation( $ticket_id, $row ) + array( 'outcome' => 'attended', 'completed_at' => self::now(), 'method' => $method, 'source' => $source );
		return Outbox::record( 'experience_completed', 'rv-done|' . (int) $ticket_id . '|' . self::cycle( $ticket_id ), (string) $row->public_id, $data );
	}

	/* ------------------------------------------------------------------ Exclusions (A669) */

	/**
	 * Records experience_excluded once per ticket and reason ($key distinguishes repeated reasons).
	 *
	 * @param string|null $previous_ref Recipient reference that is no longer valid (transfer).
	 */
	private static function exclude( $ticket_id, $reason, $key = '', $previous_ref = null ) {
		$row = self::row( $ticket_id );
		if ( ! $row ) { return false; }
		$data = self::participation( $ticket_id, $row ) + array( 'outcome' => 'excluded', 'reason' => $reason, 'excluded_at' => self::now(), 'previous_recipient_ref' => $previous_ref );
		return Outbox::record( 'experience_excluded', 'rv-ex|' . (int) $ticket_id . '|' . $reason . ( '' !== (string) $key ? '|' . $key : '' ), (string) $row->public_id, $data );
	}

	/** wpcap_checkin_undone: excluded only when no valid admission is left and the cycle was completed. */
	public static function undone( $ticket_id, $context = array() ) {
		if ( self::admissions( $ticket_id ) > 0 ) { return false; }
		$cycle = self::cycle( $ticket_id );
		if ( ! self::recorded( Outbox::event_id( 'experience_completed', 'rv-done|' . (int) $ticket_id . '|' . $cycle ) ) ) { return false; }
		return self::exclude( $ticket_id, 'check_in_undone', (string) $cycle );
	}

	/** wpcap_refund_completed: every refunded ticket of the refund. */
	public static function refund_completed( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$ids = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_ids FROM {$wpdb->prefix}wpcap_refunds WHERE refund_id=%d", absint( $refund_id ) ) ), true );
		$n = 0;
		foreach ( array_filter( array_map( 'absint', (array) $ids ) ) as $ticket_id ) { $n += self::exclude( $ticket_id, 'refunded' ) ? 1 : 0; }
		return $n;
	}

	/** wpcap_order_refunded outside the native flow: every ticket of the order. */
	public static function order_refunded( $order_id, $reason = '' ) {
		global $wpdb;
		if ( 0 === strpos( (string) $reason, 'native_refund_' ) ) { return 0; }
		$n = 0;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", absint( $order_id ) ) ) as $ticket_id ) { $n += self::exclude( (int) $ticket_id, 'refunded' ) ? 1 : 0; }
		return $n;
	}

	public static function revoked( $ticket_id, $reason = '' ) {
		return self::exclude( $ticket_id, 'revoked' );
	}

	/**
	 * wpcap_ticket_transferred: the previous holder is no longer the recipient. previous_recipient_ref
	 * is the reference of the last completion (null when the experience was not completed yet).
	 */
	public static function transferred( $ticket_id, $context = array() ) {
		global $wpdb;
		$context = is_array( $context ) ? $context : array();
		$previous = null;
		if ( Outbox::multi_ready() ) {
			$payload = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM %i WHERE contract='review' AND event_type='experience_completed' AND subject_id=%s ORDER BY outbox_id DESC LIMIT 1", Outbox::table(), self::public_id( $ticket_id ) ) );
			$decoded = $payload ? json_decode( (string) $payload, true ) : null;
			$previous = is_array( $decoded ) ? ( $decoded['data']['recipient_ref'] ?? null ) : null;
		}
		return self::exclude( $ticket_id, 'transferred', (string) self::generation( $ticket_id ), $previous );
	}

	/* ------------------------------------------------------------------ Cancellation (event level) */

	public static function occurrence_cancelled( $occurrence_id, $issued = 0 ) {
		global $wpdb;
		$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		if ( ! $event_id ) { return false; }
		$experience = self::experience_id( (int) $occurrence_id );
		return Outbox::record( 'experience_cancelled', 'rv-cancel|' . $experience, $experience, array( 'experience_id' => $experience, 'scope' => 'occurrence', 'event_id' => $event_id, 'occurrence_ids' => array( (int) $occurrence_id ), 'outcome' => 'cancelled', 'cancelled_at' => self::now() ) );
	}

	public static function event_cancelled( $event_id, $context = array() ) {
		global $wpdb;
		$occurrences = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_occurrences WHERE event_id=%d ORDER BY occurrence_id", absint( $event_id ) ) ) );
		$experience = 'event:' . (int) $event_id;
		return Outbox::record( 'experience_cancelled', 'rv-cancel|' . $experience, $experience, array( 'experience_id' => $experience, 'scope' => 'event', 'event_id' => (int) $event_id, 'occurrence_ids' => $occurrences, 'outcome' => 'cancelled', 'cancelled_at' => self::now() ) );
	}

	/* ------------------------------------------------------------------ No-show (cron) */

	/**
	 * experience_excluded no_show for valid event tickets without a valid admission, once the date has
	 * ended (plus grace). Tickets already recorded are skipped in SQL (event id computed with SHA2).
	 *
	 * @return int Recorded exclusions.
	 */
	public static function scan_no_shows() {
		global $wpdb;
		if ( ! Outbox::ready() || ! Outbox::multi_ready() ) { return 0; }
		$p = $wpdb->prefix;
		$now = \PassCaptain\Clock::now();
		/** Hours after the end of a date before a missing admission counts as no-show (A669). */
		$grace = max( 0, (int) apply_filters( 'wpcap_review_no_show_grace_hours', self::NO_SHOW_GRACE_HOURS ) );
		$until = gmdate( 'Y-m-d H:i:s', $now - $grace * HOUR_IN_SECONDS );
		$from = gmdate( 'Y-m-d H:i:s', $now - self::NO_SHOW_WINDOW_DAYS * DAY_IN_SECONDS );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.ticket_id FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id
			WHERE t.status='valid' AND t.refunded=0 AND ord.payment_status='paid' AND o.status<>'cancelled' AND e.status<>'cancelled'
			AND COALESCE(o.ends_at,o.starts_at)<%s AND COALESCE(o.ends_at,o.starts_at)>=%s
			AND COALESCE((SELECT SUM(c.result='checked_in')-SUM(c.result='undone') FROM {$p}wpcap_checkins c WHERE c.ticket_id=t.ticket_id AND c.result IN ('checked_in','undone')),0)<=0
			AND NOT EXISTS (SELECT 1 FROM %i x WHERE x.event_id=CONCAT('evt_',LEFT(SHA2(CONCAT('experience_excluded|rv-ex|',t.ticket_id,'|no_show'),256),32)))
			ORDER BY t.ticket_id LIMIT %d",
			$until,
			$from,
			Outbox::table(),
			self::NO_SHOW_BATCH
		) );
		$n = 0;
		foreach ( (array) $ids as $ticket_id ) { $n += self::exclude( (int) $ticket_id, 'no_show' ) ? 1 : 0; }
		return $n;
	}
}
