<?php
namespace PassCaptain\Privacy;

use PassCaptain\Audit;
use PassCaptain\Privacy;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Daily enforcement of the retention settings (storage limitation, Art. 5(1)(e) GDPR).
 *
 * After the end of an occurrence (Occurrence::end_sql(): ends_at, otherwise start + default duration, A606) plus N days:
 * - participant answers are deleted (N = setting attendee_retention_days, or the field's own
 *   retention_days when the setting is 0),
 * - attendee name/e-mail in ticket assignments, tickets.attendee_email and the names in offline
 *   packages are cleared (N = attendee_retention_days, or filter wpcap_attendee_default_retention_days,
 *   default 365, when the setting is 0); `wpcap_privacy_attendees_cleared` lets the wallet module
 *   update these passes without the name (Google object, Apple push, A566),
 * - Apple Wallet device registrations of these tickets are deleted one day later (after the push),
 * - pending attendee details (orders.attendee_data) of paid orders are cleared.
 *
 * Further steps (all periods filterable through wpcap_privacy_retention_days, 0 disables a step):
 * - unpaid orders: attendee_data after 1 day; buyer e-mail pseudonymised and billing data removed
 *   after 90 days (never paid: no ticket, no invoice),
 * - all orders after the statutory retention period (setting retention_order_years, default 10 years
 *   counted from the end of the calendar year of the order, § 147 AO / § 257 HGB; AT § 132 BAO:
 *   7 years): buyer e-mail, user link, order link, billing and attendee data of the order, its ticket
 *   assignments and service bookings. Amounts, items, refunds, invoices, credit notes and fiscal
 *   documents are never changed here (they are the bookkeeping records; deleting them is the
 *   operator's decision after the period),
 * - event waitlist and appointment waitlist entries: N days (setting retention_waitlist_days,
 *   default 30) after the end of the occurrence / slot (event-wide entries: after the last
 *   occurrence of the event) – e-mail pseudonymised, tokens cleared,
 * - unconfirmed waitlist sign-ups (double opt-in, both waitlists) are deleted as soon as their
 *   confirmation link expired,
 * - service bookings that never became a booking (held/expired) or were cancelled/declined: after
 *   90 days; bookings without an order (pass bookings) after the slot end plus the attendee period,
 * - member passes: N days (setting retention_pass_days, default 365) after valid_until – e-mail
 *   pseudonymised, pass code invalidated,
 * - finished queue messages lose recipient and content after 30 days (rows deleted after a year),
 *   finished SMS messages lose text and error after 30 days,
 * - check-ins, admission entries and admission conflicts lose staff user and device after 365 days
 *   (conflicts also the resolution note); labels of revoked/expired scanner devices and of used or
 *   expired pairing codes are cleared after the same period,
 * - audit log entries are deleted after 730 days,
 * - financial records (A123/A605, setting retention_financial_years, default 0 = keep): invoices
 *   with their lines and correction documents once their calendar year (site time zone) plus N years
 *   has ended; the buyer data of the orders concerned is pseudonymised at the same time,
 * - Apple Wallet registrations of erased tickets whose delayed purge is due (A510),
 * - module steps (guest lists, event waitlist, buyer limits, partner codes, box office reports) via
 *   the filter wpcap_privacy_retention_steps,
 * - order attribution (UTM source/medium/campaign, referrer host; assets/tracking.js) is cleared 90 days
 *   after the order,
 * - expired rate-limit counters (wpcap_rate_limits, PERF-16) and old check-in idempotency claims
 *   are deleted on every run; finished holds, jobs, seat holds and payment events are deleted by
 *   the hourly housekeeping() (A444).
 *
 * Periods are settings where SETTINGS lists one (Einstellungen → Datenschutz, A123) and all are
 * filterable (wpcap_privacy_retention_days).
 *
 * Work runs in batches of 500 rows within a time budget (~20 s). When the budget runs out, a
 * follow-up run is queued right away (Jobs\TimeBudget::continue_later) and starts with the step that
 * did not finish (option wpcap_retention_next_step), so later steps never starve. Each run records
 * the heartbeat "retention" (system status). Only counts are written to the audit log.
 */
final class Retention {
	const HOOK = 'wpcap_privacy_retention';
	const BATCH = 500;

	/**
	 * Built-in steps in their regular order (each is a step_* method). Module steps (guest lists,
	 * waitlist, buyer limits, partner codes, box office reports) follow via wpcap_privacy_retention_steps;
	 * finished holds and jobs are deleted by the hourly housekeeping().
	 */
	const STEPS = array( 'answers', 'attendees', 'paid_attendee_data', 'unpaid_attendee_data', 'unpaid_customer_email', 'paid_orders', 'financial_records', 'waitlists', 'unconfirmed_waitlists', 'service_bookings', 'member_passes', 'messages', 'sms', 'checkin_staff', 'devices', 'wallet_purges', 'audit_logs', 'attribution', 'rate_limits', 'checkin_claims' );

	/** Option: index of the step the next run starts with (after a run that ran out of time). */
	const START_OPTION = 'wpcap_retention_next_step';

	/** Registers the daily event (activation, upgrades and the self-healing check on init, Plugin::ensure_events()). */
	public static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** Step => setting key with the configurable period in days (A123). */
	const SETTINGS = array(
		'unpaid_customer_email' => 'retention_unpaid_customer_email_days',
		'message_content' => 'retention_message_content_days',
		'message_rows' => 'retention_message_rows_days',
		'checkin_staff' => 'retention_checkin_staff_days',
		'audit_logs' => 'retention_audit_logs_days',
		'waitlist' => 'retention_waitlist_days',
		'waitlists' => 'retention_waitlist_days', // Same period: event and appointment waitlists.
		'partner_ticket_codes' => 'retention_partner_codes_days',
	);

	/** Days of one step: setting (A123), then filter (wpcap_privacy_retention_days, $days, $step); 0 = off. */
	public static function days( $step ) {
		$defaults = array(
			'unpaid_attendee_data' => 1,
			'unpaid_customer_email' => 90,
			'message_content' => 30,
			'message_rows' => 365,
			'sms_content' => 30,
			'checkin_staff' => 365,
			'audit_logs' => 730,
			'attribution' => 90,
			'service_holds' => 90,
			'waitlist' => 30,
			'waitlists' => 30,
			'member_passes' => self::setting_days( 'retention_pass_days', 365 ),
			'partner_ticket_codes' => 30,
			'reservations' => 30,
			'jobs' => 30,
			'dead_jobs' => 180,
			'seat_holds' => 30,
			'payment_events' => 90,
		);
		$days = $defaults[ $step ] ?? 0;
		if ( isset( self::SETTINGS[ $step ] ) ) {
			$configured = Settings::get( self::SETTINGS[ $step ], null );
			if ( null !== $configured && '' !== $configured ) { $days = max( 0, min( 3650, (int) $configured ) ); }
		}
		return max( 0, (int) apply_filters( 'wpcap_privacy_retention_days', $days, $step ) );
	}

	/** Years financial records are kept (setting retention_financial_years, filterable); 0 = forever. */
	public static function financial_years() {
		return max( 0, min( 30, (int) apply_filters( 'wpcap_financial_retention_years', (int) Settings::get( 'retention_financial_years', 0 ) ) ) );
	}

	/**
	 * Documents issued before this UTC time may be deleted ('' = keep everything). With N years, a
	 * document from year Y may go once Y + N has ended: cutoff = 1 January (current year - N).
	 *
	 * A605: years are calendar years of the site time zone (the business year of the documents),
	 * not UTC years: the cutoff is 1 January 00:00 site time, converted to UTC (issued_at is UTC). A
	 * document issued on 1 January shortly after midnight in Vienna (still 31 December in UTC) thus
	 * belongs to the new year and is not deleted a year early.
	 *
	 * @param int $now Reference time (UNIX).
	 */
	public static function financial_cutoff( $now ) {
		$years = self::financial_years();
		if ( ! $years ) { return ''; }
		$zone = \PassCaptain\Frontend\Format::site_timezone();
		$year = (int) ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $zone )->format( 'Y' );
		$start = new \DateTimeImmutable( sprintf( '%04d-01-01 00:00:00', $year - $years ), $zone );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function setting_days( $key, $fallback ) {
		$value = Settings::get( $key );
		return null === $value || '' === $value ? $fallback : max( 0, min( 3650, (int) $value ) );
	}

	/**
	 * Statutory retention of order records in years (setting retention_order_years, default 10,
	 * filter wpcap_privacy_retention_days with step "order_years"); 0 = never.
	 */
	public static function order_years() {
		$value = Settings::get( 'retention_order_years' );
		$years = null === $value || '' === $value ? 10 : (int) $value;
		return max( 0, min( 30, (int) apply_filters( 'wpcap_privacy_retention_days', $years, 'order_years' ) ) );
	}

	/**
	 * First moment that is still inside the statutory period: orders created before it are past
	 * their period (10 years from the end of the calendar year of the order).
	 */
	public static function order_cutoff( $now, $years ) {
		return sprintf( '%04d-01-01 00:00:00', (int) gmdate( 'Y', (int) $now ) - (int) $years );
	}

	/** Global attendee retention (setting); 0 = per-field setting for answers, default for names. */
	public static function attendee_days() {
		return max( 0, min( 3650, (int) Settings::get( 'attendee_retention_days' ) ) );
	}

	/** Attendee name/e-mail retention when the setting is 0. */
	public static function default_attendee_days() {
		return max( 0, (int) apply_filters( 'wpcap_attendee_default_retention_days', 365 ) );
	}

	/** Effective name retention after the end of an occurrence. */
	public static function name_days() {
		return self::attendee_days() > 0 ? self::attendee_days() : self::default_attendee_days();
	}

	/**
	 * Runs all steps. Returns the counts per step.
	 *
	 * @param int|null $now    Reference time (UNIX, tests).
	 * @param float    $budget Seconds before the run stops (filter wpcap_privacy_retention_budget).
	 */
	public static function run( $now = null, $budget = null ) {
		// A continuation run (TimeBudget::continue_later) passes the argument "continue": not a timestamp.
		$now = is_numeric( $now ) ? (int) $now : time();
		$budget = null === $budget ? (float) apply_filters( 'wpcap_privacy_retention_budget', 20.0 ) : (float) $budget;
		$deadline = microtime( true ) + max( 0.0, $budget );
		$counts = array();
		$steps = self::STEPS;
		// The run starts where the last incomplete run stopped, so the later steps (audit logs,
		// attribution, counters) do not starve when the first steps use up the budget every day.
		$start = (int) get_option( self::START_OPTION, 0 );
		$start = $start > 0 && $start < count( $steps ) ? $start : 0;
		$stopped = null;
		foreach ( array_merge( array_slice( $steps, $start ), array_slice( $steps, 0, $start ) ) as $step ) {
			if ( microtime( true ) >= $deadline ) {
				$counts[ $step ] = 0;
				if ( null === $stopped ) { $stopped = (int) array_search( $step, $steps, true ); }
				continue;
			}
			$counts[ $step ] = (int) call_user_func( array( __CLASS__, 'step_' . $step ), $now, $deadline );
		}
		/**
		 * Module retention steps (guest lists, waitlist, buyer limits, …): add counts per step name.
		 *
		 * @param array $counts   Counts so far.
		 * @param int   $now      Reference time.
		 * @param float $deadline microtime() limit.
		 */
		$counts = (array) apply_filters( 'wpcap_privacy_retention_steps', $counts, $now, $deadline );
		$counts['complete'] = microtime( true ) < $deadline && null === $stopped;
		update_option( self::START_OPTION, $counts['complete'] ? 0 : (int) $stopped, false );
		if ( array_sum( array_map( 'intval', array_diff_key( $counts, array( 'complete' => 1 ) ) ) ) > 0 ) {
			Audit::record( 'privacy_retention', 'system', 0, $counts );
		}
		if ( ! $counts['complete'] ) {
			// Budget used up: continue right away instead of waiting a day.
			\PassCaptain\Jobs\TimeBudget::continue_later( self::HOOK );
		}
		\PassCaptain\Ops\Heartbeat::beat( 'retention', array_sum( array_map( 'intval', array_diff_key( $counts, array( 'complete' => 1 ) ) ) ) );
		return $counts;
	}

	/** Seconds of one housekeeping run (filter wpcap_cleanup_budget). */
	const HOUSEKEEPING_BUDGET = 10.0;

	/**
	 * Hourly housekeeping (cron `wpcap_cleanup_reservations`, A444): deletes finished rows that no
	 * process reads any more, N days (wpcap_privacy_retention_days, step names below; 0 = keep) after
	 * they finished, in batches of BATCH rows within a time budget; the next run continues.
	 * - reservations (30): converted/expired/released holds with counted_qty 0 (they no longer count
	 *   for capacity), expires_at older than N days, unless their order is still awaiting payment.
	 * - jobs (30) / dead_jobs (180): completed or discarded (cancelled) jobs / dead letters (re-queueable on the operations page
	 *   until then). Idempotency keys of deleted jobs can be used again.
	 * - seat_holds (30): expired/released seat holds (sold holds stay: they mark the seat as taken).
	 * - payment_events (90): processed (and abandoned "processing") provider events; providers stop
	 *   redelivering after a few days, so the dedupe no longer needs them.
	 *
	 * @param int|null   $now    Reference time (UNIX, tests).
	 * @param float|null $budget Seconds (filter wpcap_cleanup_budget, default 10).
	 * @return array Deleted rows per step plus `complete` (false: out of time, the next run continues).
	 */
	public static function housekeeping( $now = null, $budget = null ) {
		$now = null === $now || '' === $now ? time() : (int) $now;
		$budget = null === $budget ? (float) apply_filters( 'wpcap_cleanup_budget', self::HOUSEKEEPING_BUDGET ) : (float) $budget;
		$deadline = microtime( true ) + max( 0.0, $budget );
		global $wpdb;
		$p = $wpdb->prefix;
		$steps = array(
			'reservations' => array(
				// Range on expiry (status, expires_at); the order is read by primary key.
				static fn( $cutoff ) => $wpdb->prepare( "SELECT r.reservation_id FROM {$p}wpcap_reservations r LEFT JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status IN ('converted','expired','released') AND r.expires_at<%s AND r.counted_qty=0 AND (o.order_id IS NULL OR o.payment_status<>'pending') LIMIT %d", $cutoff, self::BATCH ),
				static fn( $in ) => "DELETE FROM {$p}wpcap_reservations WHERE reservation_id IN ($in) AND status IN ('converted','expired','released') AND counted_qty=0",
			),
			'jobs' => array(
				// Range on due_jobs (status, run_after): a finished job ran after its run_after.
				static fn( $cutoff ) => $wpdb->prepare( "SELECT job_id FROM {$p}wpcap_jobs WHERE status IN ('complete','cancelled') AND run_after<%s AND updated_at<%s LIMIT %d", $cutoff, $cutoff, self::BATCH ),
				static fn( $in ) => "DELETE FROM {$p}wpcap_jobs WHERE job_id IN ($in) AND status IN ('complete','cancelled')",
			),
			'dead_jobs' => array(
				static fn( $cutoff ) => $wpdb->prepare( "SELECT job_id FROM {$p}wpcap_jobs WHERE status='dead_letter' AND run_after<%s AND updated_at<%s LIMIT %d", $cutoff, $cutoff, self::BATCH ),
				static fn( $in ) => "DELETE FROM {$p}wpcap_jobs WHERE job_id IN ($in) AND status='dead_letter'",
			),
			'seat_holds' => array(
				static fn( $cutoff ) => $wpdb->prepare( "SELECT hold_id FROM {$p}wpcap_seat_holds WHERE status IN ('expired','released') AND expires_at<%s LIMIT %d", $cutoff, self::BATCH ),
				static fn( $in ) => "DELETE FROM {$p}wpcap_seat_holds WHERE hold_id IN ($in) AND status IN ('expired','released')",
			),
			'payment_events' => array(
				// Range on cleanup (status, updated_at), schema 3.10.12.
				static fn( $cutoff ) => $wpdb->prepare( "SELECT payment_event_id FROM {$p}wpcap_payment_events WHERE status IN ('processed','processing') AND updated_at<%s LIMIT %d", $cutoff, self::BATCH ),
				static fn( $in ) => "DELETE FROM {$p}wpcap_payment_events WHERE payment_event_id IN ($in) AND status IN ('processed','processing')",
			),
		);
		$counts = array();
		foreach ( $steps as $step => $sql ) {
			$counts[ $step ] = 0;
			$days = self::days( $step );
			if ( ! $days || microtime( true ) >= $deadline ) { continue; }
			$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
			$counts[ $step ] = self::loop(
				$deadline,
				static fn() => $wpdb->get_col( $sql[0]( $cutoff ) ),
				static fn( $ids ) => (int) $wpdb->query( $sql[1]( self::in_list( $ids ) ) )
			);
		}
		$counts['complete'] = microtime( true ) < $deadline;
		return $counts;
	}

	private static function utc( $ts ) { return gmdate( 'Y-m-d H:i:s', (int) $ts ); }

	/** LIKE pattern matching placeholder addresses (no LIKE wildcards in the domain). */
	private static function placeholder_like() {
		return '%' . Privacy::PLACEHOLDER_DOMAIN;
	}

	/** SQL for the end of an occurrence (alias o); A606: the central definition. */
	private static function end_sql() { return \PassCaptain\Occurrence::end_sql( 'o' ); }

	/** Comma-separated integer list for IN() (ids are cast; never empty). */
	private static function in_list( array $ids ) { return implode( ',', array_map( 'absint', $ids ) ) ?: '0'; }

	/** Repeats $select (returns ids) / $apply (ids) until done or out of time. */
	private static function loop( $deadline, callable $select, callable $apply ) {
		$total = 0;
		while ( microtime( true ) < $deadline ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $select() ) ) );
			if ( ! $ids ) { break; }
			$changed = (int) $apply( $ids );
			$total += $changed;
			// A batch that changed nothing would be selected again: stop instead of spinning.
			if ( count( $ids ) < self::BATCH || ! $changed ) { break; }
		}
		return $total;
	}

	/** Marketing attribution of orders (UTM, referrer host) after N days. */
	private static function step_attribution( $now, $deadline ) {
		global $wpdb;
		$days = self::days( 'attribution' );
		if ( ! $days ) { return 0; }
		$table = $wpdb->prefix . 'wpcap_orders';
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $table, $cutoff ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM %i WHERE attribution<>'' AND created_at<%s LIMIT %d", $table, $cutoff, self::BATCH ) );
				if ( '' !== (string) $wpdb->last_error ) { $wpdb->last_error = ''; return array(); } // Column missing before the schema upgrade.
				return $ids;
			},
			static function ( $ids ) use ( $wpdb, $table ) {
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET attribution='' WHERE order_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $table ), $ids ) ) );
			}
		);
	}

	private static function step_answers( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$global = self::attendee_days();
		$now_sql = self::utc( $now );
		$default_days = (int) self::default_attendee_days();
		// Index-friendly pre-filter: an occurrence can only be due if it started before now - min(N).
		$min = $global > 0 ? $global : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MIN(retention_days),0) FROM %i', $p . 'wpcap_participant_fields' ) );
		$min = min( $min, $global > 0 ? $global : self::default_attendee_days() );
		$start_before = self::utc( $now - $min * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $global, $default_days, $now_sql, $start_before ) {
				// A global retention period overrides the per-field one (which falls back to the default).
				return $wpdb->get_col( $wpdb->prepare( 'SELECT a.answer_id FROM %i o INNER JOIN %i tt ON tt.occurrence_id=o.occurrence_id INNER JOIN %i t ON t.ticket_type_id=tt.ticket_type_id INNER JOIN %i a ON a.ticket_id=t.ticket_id LEFT JOIN %i f ON f.field_id=a.field_id WHERE o.starts_at<%s AND DATE_ADD(' . self::end_sql() . ', INTERVAL IF(%d>0, %d, COALESCE(f.retention_days, %d)) DAY)<%s LIMIT %d', $p . 'wpcap_occurrences', $p . 'wpcap_ticket_types', $p . 'wpcap_tickets', $p . 'wpcap_participant_answers', $p . 'wpcap_participant_fields', $start_before, $global, $global, $default_days, $now_sql, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE answer_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_participant_answers' ), $ids ) ) );
			}
		);
	}

	private static function step_attendees( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$days = self::name_days();
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		// Apple devices get the push for the nameless pass first; their registration goes one run later.
		$registration_cutoff = self::utc( $now - ( $days + 1 ) * DAY_IN_SECONDS );
		// Occurrences end at ends_at, or one day after the start when no end is set.
		$count = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT ta.assignment_id FROM %i o INNER JOIN %i tt ON tt.occurrence_id=o.occurrence_id INNER JOIN %i t ON t.ticket_type_id=tt.ticket_type_id INNER JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE o.starts_at<%s AND " . self::end_sql() . "<%s AND (ta.attendee_name<>'' OR ta.attendee_email<>'') LIMIT %d", $p . 'wpcap_occurrences', $p . 'wpcap_ticket_types', $p . 'wpcap_tickets', $p . 'wpcap_ticket_assignments', $cutoff, $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$tickets = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE assignment_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_ticket_assignments' ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_name='',attendee_email='' WHERE assignment_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_ticket_assignments' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				/**
				 * Attendee names/e-mails of these tickets were cleared by the retention run (A566):
				 * modules that copied them elsewhere (wallet passes: Google object without the name,
				 * Apple push) remove them there too.
				 *
				 * @param int[] $ticket_ids
				 */
				if ( $tickets ) { do_action( 'wpcap_privacy_attendees_cleared', $tickets ); }
				return $count;
			}
		);
		$count += self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT t.ticket_id FROM %i o INNER JOIN %i tt ON tt.occurrence_id=o.occurrence_id INNER JOIN %i t ON t.ticket_type_id=tt.ticket_type_id WHERE o.starts_at<%s AND " . self::end_sql() . "<%s AND t.attendee_email<>'' LIMIT %d", $p . 'wpcap_occurrences', $p . 'wpcap_ticket_types', $p . 'wpcap_tickets', $cutoff, $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_email='' WHERE ticket_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
		$occurrences = $wpdb->get_col( $wpdb->prepare( 'SELECT o.occurrence_id FROM %i o WHERE o.starts_at<%s AND ' . self::end_sql() . '<%s', $p . 'wpcap_occurrences', $cutoff, $cutoff ) );
		if ( $occurrences && Privacy::has_table( 'wpcap_offline_entries' ) ) {
			foreach ( array_chunk( $occurrences, self::BATCH ) as $chunk ) {
				if ( microtime( true ) >= $deadline ) { break; }
				$count += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_name='' WHERE attendee_name<>'' AND occurrence_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_offline_entries' ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( Privacy::has_table( 'wpcap_wallet_registrations' ) ) {
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $registration_cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( 'SELECT r.registration_id FROM %i o INNER JOIN %i tt ON tt.occurrence_id=o.occurrence_id INNER JOIN %i t ON t.ticket_type_id=tt.ticket_type_id INNER JOIN %i w ON w.ticket_id=t.ticket_id INNER JOIN %i r ON r.serial_number=w.serial_number WHERE o.starts_at<%s AND ' . self::end_sql() . '<%s LIMIT %d', $p . 'wpcap_occurrences', $p . 'wpcap_ticket_types', $p . 'wpcap_tickets', $p . 'wpcap_wallet_passes', $p . 'wpcap_wallet_registrations', $registration_cutoff, $registration_cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE registration_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_wallet_registrations' ), $ids ) ) );
				}
			);
		}
		return $count;
	}

	/**
	 * Pending attendee details (attendee_data) of paid orders: needed until the tickets are assigned,
	 * cleared once every occurrence of the order ended more than the name retention period ago.
	 */
	private static function step_paid_attendee_data( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - self::name_days() * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT ord.order_id FROM %i ord WHERE ord.attendee_data IS NOT NULL AND ord.payment_status NOT IN ('expired','cancelled','failed') AND ord.payment_status<>'pending' AND EXISTS (SELECT 1 FROM %i t WHERE t.order_id=ord.order_id) AND NOT EXISTS (SELECT 1 FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=ord.order_id AND " . self::end_sql() . ">=%s) LIMIT %d", $p . 'wpcap_orders', $p . 'wpcap_tickets', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET attendee_data=NULL WHERE order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_orders' ), $ids ) ) );
			}
		);
	}

	/** Pending attendee details of orders that will never issue tickets. */
	private static function step_unpaid_attendee_data( $now, $deadline ) {
		$days = self::days( 'unpaid_attendee_data' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM %i WHERE attendee_data IS NOT NULL AND payment_status IN ('expired','cancelled','failed') AND updated_at<%s LIMIT %d", $p . 'wpcap_orders', $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$changed = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET attendee_data=NULL WHERE order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_orders' ), $ids ) ) );
				/**
				 * Retention cleared the attendee details of these unpaid orders; integrations drop their copies
				 * (e.g. the attendee payload on the WooCommerce order).
				 *
				 * @param int[] $ids Order ids of this batch.
				 */
				do_action( 'wpcap_privacy_unpaid_attendee_data_cleared', array_map( 'intval', $ids ) );
				return $changed;
			}
		);
	}

	/**
	 * Financial records past the statutory period (A123): invoice lines, invoices and correction
	 * documents; the orders' buyer data (e-mail, billing address, access link) is pseudonymised.
	 */
	private static function step_financial_records( $now, $deadline ) {
		$cutoff = self::financial_cutoff( $now );
		if ( '' === $cutoff ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$credit = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT credit_note_id FROM {$p}wpcap_credit_notes WHERE issued_at<%s LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( "DELETE FROM {$p}wpcap_credit_notes WHERE credit_note_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
		$invoices = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				// Only invoices whose corrections are gone too (a later correction keeps its invoice).
				return $wpdb->get_col( $wpdb->prepare( "SELECT i.invoice_id FROM {$p}wpcap_invoices i WHERE i.issued_at<%s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_credit_notes c WHERE c.invoice_id=i.invoice_id) LIMIT %d", $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$list = self::in_list( $ids );
				$orders = array_map( 'absint', (array) $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_invoices WHERE invoice_id IN ($list)" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$p}wpcap_invoice_lines WHERE invoice_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = (int) $wpdb->query( "DELETE FROM {$p}wpcap_invoices WHERE invoice_id IN ($list)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,customer_email FROM ' . $p . 'wpcap_orders WHERE order_id IN (' . self::in_list( $orders ) . ') AND customer_email NOT LIKE %s', '%' . Privacy::PLACEHOLDER_DOMAIN ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
					$wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => Privacy::replacement_email( (string) $row->customer_email, (int) $row->order_id ), 'billing' => null, 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null ), array( 'order_id' => (int) $row->order_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
					\PassCaptain\Orders::clear_pending_link( (int) $row->order_id );
				}
				return $deleted;
			}
		);
		return $credit + $invoices;
	}

	/**
	 * Never-paid orders (no ticket, no invoice): buyer e-mail replaced by the erasure placeholder,
	 * user link, order link, attendee and billing data removed. Billing data stays for the same
	 * 90 days as the e-mail: a late payment (bank transfer, SEPA) can still complete the order and
	 * needs it for the invoice.
	 */
	private static function step_unpaid_customer_email( $now, $deadline ) {
		$days = self::days( 'unpaid_customer_email' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$like = self::placeholder_like();
		$count = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff, $like ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT ord.order_id FROM %i ord WHERE ord.payment_status IN ('expired','cancelled','failed') AND ord.updated_at<%s AND ord.created_at<%s AND (ord.customer_email NOT LIKE %s OR ord.customer_user_id<>0) AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.order_id=ord.order_id) AND NOT EXISTS (SELECT 1 FROM %i i WHERE i.order_id=ord.order_id) LIMIT %d", $p . 'wpcap_orders', $cutoff, $cutoff, $like, $p . 'wpcap_tickets', $p . 'wpcap_invoices', self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$done = 0;
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,customer_email FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_orders' ), $ids ) ) ) as $row ) {
					$email = (string) $row->customer_email;
					$replacement = Privacy::is_placeholder_email( $email ) ? $email : Privacy::replacement_email( $email, (int) $row->order_id );
					$done += (int) $wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => $replacement, 'customer_user_id' => 0, 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null, 'attendee_data' => null ), array( 'order_id' => (int) $row->order_id ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%d' ) );
					\PassCaptain\Orders::clear_pending_link( (int) $row->order_id );
				}
				return $done;
			}
		);
		// Billing data (name, address, VAT ID) of the same orders, also when the e-mail was a
		// placeholder from the start (box office). Separate statement: a deferred schema upgrade may
		// still lack the column.
		$count += self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT ord.order_id FROM %i ord WHERE ord.billing IS NOT NULL AND ord.payment_status IN ('expired','cancelled','failed') AND ord.updated_at<%s AND ord.created_at<%s AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.order_id=ord.order_id) AND NOT EXISTS (SELECT 1 FROM %i i WHERE i.order_id=ord.order_id) LIMIT %d", $p . 'wpcap_orders', $cutoff, $cutoff, $p . 'wpcap_tickets', $p . 'wpcap_invoices', self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET billing=NULL WHERE billing IS NOT NULL AND order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_orders' ), $ids ) ) );
			}
		);
		return $count;
	}

	/**
	 * Orders past the statutory retention period: personal fields of the order, its tickets and
	 * service bookings are removed. Bookkeeping records (amounts, items, refunds, invoices, credit
	 * notes, fiscal documents) are not touched.
	 */
	private static function step_paid_orders( $now, $deadline ) {
		$years = self::order_years();
		if ( ! $years ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::order_cutoff( $now, $years );
		$now_sql = self::utc( $now );
		$like = self::placeholder_like();
		$count = self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff, $now_sql, $like ) {
				// Tickets for a date that has not ended yet (long-running passes) keep their order intact.
				return $wpdb->get_col( $wpdb->prepare( "SELECT ord.order_id FROM %i ord WHERE ord.created_at<%s AND (ord.customer_email NOT LIKE %s OR ord.customer_user_id<>0 OR ord.attendee_data IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.order_id=ord.order_id AND t.status='valid' AND " . self::end_sql() . ">=%s) LIMIT %d", $p . 'wpcap_orders', $cutoff, $like, $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $now_sql, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				$done = 0;
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,customer_email FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_orders' ), $ids ) ) ) as $row ) {
					$order_id = (int) $row->order_id;
					$email = (string) $row->customer_email;
					$replacement = Privacy::is_placeholder_email( $email ) ? $email : Privacy::replacement_email( $email, $order_id );
					$done += (int) $wpdb->update( $p . 'wpcap_orders', array( 'customer_email' => $replacement, 'customer_user_id' => 0, 'access_token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'access_expires_at' => null, 'attendee_data' => null ), array( 'order_id' => $order_id ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%d' ) );
					\PassCaptain\Orders::clear_pending_link( $order_id );
					$wpdb->query( $wpdb->prepare( "UPDATE %i ta INNER JOIN %i t ON t.ticket_id=ta.ticket_id SET ta.buyer_email=%s,ta.attendee_email='',ta.attendee_name='' WHERE t.order_id=%d", $p . 'wpcap_ticket_assignments', $p . 'wpcap_tickets', $replacement, $order_id ) );
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET attendee_email='' WHERE order_id=%d AND attendee_email<>''", $p . 'wpcap_tickets', $order_id ) );
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET customer_email=%s,manage_token_hash='' WHERE order_id=%d", $p . 'wpcap_service_bookings', $replacement, $order_id ) );
				}
				return $done;
			}
		);
		// Billing data of the same orders (separate statement, see step_unpaid_customer_email).
		$count += self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE created_at<%s AND billing IS NOT NULL LIMIT %d', $p . 'wpcap_orders', $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET billing=NULL WHERE billing IS NOT NULL AND order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_orders' ), $ids ) ) );
			}
		);
		return $count;
	}

	/** Event and appointment waitlists: pseudonymised N days after the occurrence / slot ended. */
	private static function step_waitlists( $now, $deadline ) {
		$days = self::days( 'waitlists' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$like = self::placeholder_like();
		$now_sql = self::utc( $now );
		$count = 0;
		// The event waitlist is anonymised by its own retention step when that module hooks one
		// (Waitlists::retention_step, period `waitlist`, same setting); otherwise here.
		$own_step = class_exists( '\\PassCaptain\\Waitlists' ) && method_exists( '\\PassCaptain\\Waitlists', 'retention_step' ) && false !== has_filter( 'wpcap_privacy_retention_steps', array( \PassCaptain\Waitlists::class, 'retention_step' ) );
		if ( ! $own_step && Privacy::has_table( 'wpcap_waitlist' ) ) {
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff, $like ) {
					// Entries of one occurrence end with it; event-wide entries (occurrence 0) after the last occurrence.
					return $wpdb->get_col( $wpdb->prepare( 'SELECT w.waitlist_id FROM %i w WHERE w.email NOT LIKE %s AND ( ( w.occurrence_id>0 AND EXISTS (SELECT 1 FROM %i o WHERE o.occurrence_id=w.occurrence_id AND ' . self::end_sql() . '<%s) ) OR ( w.occurrence_id=0 AND EXISTS (SELECT 1 FROM %i o WHERE o.event_id=w.event_id) AND NOT EXISTS (SELECT 1 FROM %i o WHERE o.event_id=w.event_id AND ' . self::end_sql() . '>=%s) ) ) LIMIT %d', $p . 'wpcap_waitlist', $like, $p . 'wpcap_occurrences', $cutoff, $p . 'wpcap_occurrences', $p . 'wpcap_occurrences', $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p, $now_sql ) {
					return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET email=CONCAT('deleted+',LEFT(SHA2(CONCAT(waitlist_id,'|',RAND(),'|',UUID()),256),24),%s),email_hash=SHA2(CONCAT(waitlist_id,'|',RAND(),'|',UUID()),256),offer_token_hash='',manage_token_hash='',status=IF(status IN ('waiting','offered'),'expired',status),updated_at=%s WHERE waitlist_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_waitlist', Privacy::PLACEHOLDER_DOMAIN, $now_sql ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		if ( Privacy::has_table( 'wpcap_service_waitlist' ) ) {
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff, $like ) {
					return $wpdb->get_col( $wpdb->prepare( 'SELECT w.entry_id FROM %i w INNER JOIN %i s ON s.slot_id=w.slot_id WHERE w.email NOT LIKE %s AND s.ends_at<%s LIMIT %d', $p . 'wpcap_service_waitlist', $p . 'wpcap_service_slots', $like, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p, $now_sql ) {
					return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET email=CONCAT('deleted+',LEFT(SHA2(CONCAT(entry_id,'|',RAND(),'|',UUID()),256),24),%s),email_hash=SHA2(CONCAT(entry_id,'|',RAND(),'|',UUID()),256),offer_token_hash='',manage_token_hash='',status=IF(status IN ('waiting','offered'),'expired',status),updated_at=%s WHERE entry_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_service_waitlist', Privacy::PLACEHOLDER_DOMAIN, $now_sql ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		return $count;
	}

	/** Unconfirmed waitlist sign-ups (double opt-in): deleted once the confirmation link expired. */
	private static function step_unconfirmed_waitlists( $now, $deadline ) {
		if ( ! \PassCaptain\Waitlists::schema_ready() ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$now_sql = self::utc( $now );
		// Event waitlist (merged, A564): sign-ups wait as `pending` until confirmed; Waitlists::purge_unconfirmed()
		// deletes those whose confirmation period (confirm_hours) ended. The query below still covers rows of the
		// earlier `unconfirmed` state (renamed by the 3.18.0 upgrade) and the slot waitlist.
		$count = \PassCaptain\Waitlists::purge_unconfirmed( 5000 );
		foreach ( array( 'wpcap_waitlist' => 'waitlist_id', 'wpcap_service_waitlist' => 'entry_id' ) as $table => $key ) {
			if ( ! Privacy::has_table( $table ) ) { continue; }
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $table, $key, $now_sql ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT %i FROM %i WHERE status='unconfirmed' AND confirm_expires_at IS NOT NULL AND confirm_expires_at<%s LIMIT %d", $key, $p . $table, $now_sql, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p, $table, $key, $now_sql ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
					return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status='unconfirmed' AND confirm_expires_at<%s AND %i IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . $table, $now_sql, $key ), $ids ) ) );
				}
			);
		}
		return $count;
	}

	/**
	 * Service bookings: holds that lapsed and cancelled/declined bookings after 90 days; bookings
	 * without an order (pass bookings) after the slot end plus the name retention period. Bookings
	 * of an order follow the order (step_paid_orders).
	 */
	private static function step_service_bookings( $now, $deadline ) {
		if ( ! Privacy::has_table( 'wpcap_service_bookings' ) ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$like = self::placeholder_like();
		$count = 0;
		$days = self::days( 'service_holds' );
		$apply = static function ( $ids ) use ( $wpdb, $p ) {
			return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET customer_email=CONCAT('deleted+',LEFT(SHA2(CONCAT(booking_id,'|',RAND(),'|',UUID()),256),24),%s),manage_token_hash='' WHERE booking_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_service_bookings', Privacy::PLACEHOLDER_DOMAIN ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		if ( $days ) {
			$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff, $like ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT booking_id FROM %i WHERE customer_email NOT LIKE %s AND created_at<%s AND COALESCE(updated_at, created_at)<%s AND ( status IN ('expired','cancelled','declined') OR ( status='held' AND expires_at IS NOT NULL AND expires_at<%s ) ) LIMIT %d", $p . 'wpcap_service_bookings', $like, $cutoff, $cutoff, $cutoff, self::BATCH ) );
				},
				$apply
			);
		}
		$after_slot = self::utc( $now - self::name_days() * DAY_IN_SECONDS );
		$count += self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $after_slot, $like ) {
				return $wpdb->get_col( $wpdb->prepare( 'SELECT b.booking_id FROM %i b INNER JOIN %i s ON s.slot_id=b.slot_id WHERE b.order_id=0 AND b.customer_email NOT LIKE %s AND s.ends_at<%s LIMIT %d', $p . 'wpcap_service_bookings', $p . 'wpcap_service_slots', $like, $after_slot, self::BATCH ) );
			},
			$apply
		);
		return $count;
	}

	/** Member passes: pseudonymised N days after valid_until (pass code invalidated). */
	private static function step_member_passes( $now, $deadline ) {
		$days = self::days( 'member_passes' );
		if ( ! $days || ! Privacy::has_table( 'wpcap_member_passes' ) ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$like = self::placeholder_like();
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff, $like ) {
				return $wpdb->get_col( $wpdb->prepare( 'SELECT pass_id FROM %i WHERE valid_until<%s AND member_email NOT LIKE %s LIMIT %d', $p . 'wpcap_member_passes', $cutoff, $like, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET member_email=CONCAT('deleted+',LEFT(SHA2(CONCAT(pass_id,'|',RAND(),'|',UUID()),256),24),%s),token_hash=SHA2(CONCAT(pass_id,'|',RAND(),'|',UUID()),256) WHERE pass_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_member_passes', Privacy::PLACEHOLDER_DOMAIN ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	/** Finished queue messages: content after 30 days, rows after a year (dedupe keys stay until then). */
	private static function step_messages( $now, $deadline ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$count = 0;
		$content = self::days( 'message_content' );
		if ( $content ) {
			$cutoff = self::utc( $now - $content * DAY_IN_SECONDS );
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM %i WHERE status IN ('sent','failed','cancelled') AND send_at<%s AND created_at<%s AND (recipient<>'' OR payload<>'{}') LIMIT %d", $p . 'wpcap_message_queue', $cutoff, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET recipient='',payload='{}',last_error=NULL WHERE message_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_message_queue' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		$rows = self::days( 'message_rows' );
		if ( $rows ) {
			$cutoff = self::utc( $now - $rows * DAY_IN_SECONDS );
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM %i WHERE status IN ('sent','failed','cancelled') AND send_at<%s AND created_at<%s LIMIT %d", $p . 'wpcap_message_queue', $cutoff, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE message_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_message_queue' ), $ids ) ) );
				}
			);
		}
		return $count;
	}

	/** Finished SMS messages lose their text and provider error after 30 days. */
	private static function step_sms( $now, $deadline ) {
		$days = self::days( 'sms_content' );
		if ( ! $days || ! Privacy::has_table( 'wpcap_sms_messages' ) ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM %i WHERE status NOT IN ('queued','sending') AND updated_at<%s AND created_at<%s AND (body<>'' OR last_error IS NOT NULL) LIMIT %d", $p . 'wpcap_sms_messages', $cutoff, $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET body='',last_error=NULL WHERE message_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_sms_messages' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	/** Option: check-ins created before this UTC time are already anonymised (cursor of step_checkin_staff). */
	const CHECKIN_STAFF_CURSOR = 'wpcap_retention_checkin_staff_cursor';

	/** A full pass over all old check-ins at least this often (rows imported with old dates). */
	const CHECKIN_STAFF_FULL_PASS = 7 * DAY_IN_SECONDS;

	/**
	 * Old check-ins, admission entries and conflicts keep ticket, time and result but lose the
	 * scanning staff user and device (conflicts also the resolution note).
	 *
	 * A439: walks KEY created (created_at,checkin_id) in order from a stored cursor, so a daily run
	 * reads only the check-ins that became due since the last one – not every anonymised row older
	 * than the cutoff again. The cursor never passes the real cutoff (time() − days), and every
	 * CHECKIN_STAFF_FULL_PASS the walk starts from the beginning.
	 */
	private static function step_checkin_staff( $now, $deadline ) {
		$days = self::days( 'checkin_staff' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$state = get_option( self::CHECKIN_STAFF_CURSOR, array() );
		$state = is_array( $state ) ? $state : array();
		$full = (int) ( $state['full_at'] ?? 0 ) + self::CHECKIN_STAFF_FULL_PASS <= time();
		$from = $full ? '' : (string) ( $state['at'] ?? '' );
		// Check-ins are walked with a cursor; the admission tables below run every time (an up-to-date cursor must not
		// skip them).
		$total = 0;
		if ( '' === $from || $from < $cutoff ) {
			$cap = self::utc( min( $now, time() ) - $days * DAY_IN_SECONDS );
			$last = '';
			$rest = false;
			$total += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff, &$from, &$last, &$rest ) {
					$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT checkin_id,created_at FROM %i WHERE created_at>=%s AND created_at<%s AND (user_id<>0 OR device_id<>0) ORDER BY created_at,checkin_id LIMIT %d", $p . 'wpcap_checkins', '' === $from ? '1000-01-01 00:00:00' : $from, $cutoff, self::BATCH ) );
					$last = $rows ? (string) end( $rows )->created_at : '';
					$rest = count( $rows ) >= self::BATCH;
					return array_map( static fn( $row ) => (int) $row->checkin_id, $rows );
				},
				static function ( $ids ) use ( $wpdb, $p, &$from, &$last ) {
					$changed = (int) $wpdb->query( "UPDATE {$p}wpcap_checkins SET user_id=0,device_id=0 WHERE checkin_id IN (" . self::in_list( $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					// Rows of the batch's last second may continue in the next batch: resume at that second.
					if ( $changed && '' !== $last ) { $from = $last; }
					return $changed;
				}
			);
			// Everything before the cutoff is done unless the last batch was full (budget ran out).
			$at = $rest ? $from : $cutoff;
			$state = array( 'at' => '' === $at ? '' : min( $at, $cap ), 'full_at' => $full ? time() : (int) ( $state['full_at'] ?? 0 ) );
			update_option( self::CHECKIN_STAFF_CURSOR, $state, false );
		}
		// Admission entries and conflicts (staff user, device, resolution note) after the same period.
		if ( Privacy::has_table( 'wpcap_admission_entries' ) ) {
			$total += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( 'SELECT entry_id FROM %i WHERE created_at<%s AND (user_id<>0 OR device_id<>0) LIMIT %d', $p . 'wpcap_admission_entries', $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_id=0,device_id=0 WHERE entry_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_admission_entries' ), $ids ) ) );
				}
			);
		}
		if ( Privacy::has_table( 'wpcap_admission_conflicts' ) ) {
			$total += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( 'SELECT conflict_id FROM %i WHERE created_at<%s AND (user_id<>0 OR device_id<>0 OR first_user_id<>0 OR first_device_id<>0 OR resolved_by<>0 OR resolution_note IS NOT NULL) LIMIT %d', $p . 'wpcap_admission_conflicts', $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_id=0,device_id=0,first_user_id=0,first_device_id=0,resolved_by=0,resolution_note=NULL WHERE conflict_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_admission_conflicts' ), $ids ) ) );
				}
			);
		}
		return $total;
	}

	/** Apple Wallet registrations of erased tickets whose delayed purge is due (A510). */
	private static function step_wallet_purges( $now, $deadline ) {
		return \PassCaptain\Privacy::purge_due_wallet_registrations( $now, $deadline );
	}

	/**
	 * Device labels (often a person's name, "iPhone Anna") of revoked or expired scanner devices and
	 * of used or expired pairing codes are cleared after the check-in staff period.
	 */
	private static function step_devices( $now, $deadline ) {
		$days = self::days( 'checkin_staff' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		$count = 0;
		if ( Privacy::has_table( 'wpcap_scanner_devices' ) ) {
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT device_id FROM %i WHERE label<>'' AND created_at<%s AND ( (revoked_at IS NOT NULL AND revoked_at<%s) OR (expires_at IS NOT NULL AND expires_at<%s) ) LIMIT %d", $p . 'wpcap_scanner_devices', $cutoff, $cutoff, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET label='' WHERE device_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_scanner_devices' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		if ( Privacy::has_table( 'wpcap_device_pairings' ) ) {
			$count += self::loop(
				$deadline,
				static function () use ( $wpdb, $p, $cutoff ) {
					return $wpdb->get_col( $wpdb->prepare( "SELECT pairing_id FROM %i WHERE label<>'' AND created_at<%s AND expires_at<%s LIMIT %d", $p . 'wpcap_device_pairings', $cutoff, $cutoff, self::BATCH ) );
				},
				static function ( $ids ) use ( $wpdb, $p ) {
					return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET label='' WHERE pairing_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_device_pairings' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			);
		}
		return $count;
	}

	private static function step_audit_logs( $now, $deadline ) {
		$days = self::days( 'audit_logs' );
		if ( ! $days ) { return 0; }
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::utc( $now - $days * DAY_IN_SECONDS );
		return self::loop(
			$deadline,
			static function () use ( $wpdb, $p, $cutoff ) {
				return $wpdb->get_col( $wpdb->prepare( 'SELECT audit_id FROM %i WHERE created_at<%s ORDER BY audit_id LIMIT %d', $p . 'wpcap_audit_logs', $cutoff, self::BATCH ) );
			},
			static function ( $ids ) use ( $wpdb, $p ) {
				return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE audit_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_audit_logs' ), $ids ) ) );
			}
		);
	}

	/** Expired rate-limit counters (wpcap_rate_limits, index expires_at; keyed hashes only, always on). */
	private static function step_rate_limits( $now, $deadline ) {
		return \PassCaptain\RateLimit::purge_expired( $now, $deadline, self::BATCH * 10 );
	}

	/** Idempotency claims of check-ins older than 7 days (no personal data, always on). */
	private static function step_checkin_claims( $now, $deadline ) {
		return \PassCaptain\Checkin::purge_claims( $now, $deadline, self::BATCH * 10 );
	}
}
