<?php
/**
 * Time budget for queue workers that run inside a WP-Cron / Action Scheduler request.
 *
 * A cron hook (e.g. wpcap_process_messages) may run several workers in one PHP request: core
 * messages, SMS, jobs, webhooks. They share one deadline that is armed when the hook starts, so
 * the request stays well below max_execution_time even when every item waits for an HTTP timeout.
 * Workers called directly (tests, WP-CLI) get their own deadline per call.
 *
 * Workers check the budget before claiming the next item, so nothing is claimed that is not
 * processed. If PHP still dies while an item is in flight (fatal error, time limit), the claim is
 * released on shutdown instead of waiting for the 15-minute stale-lock recovery.
 */
namespace PassCaptain\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TimeBudget {
	/** Default seconds of queue work per cron hook (filter: wpcap_cron_time_budget). */
	const DEFAULT_SECONDS = 20;

	/** Action Scheduler group of follow-up runs (before the rename: LegacySlug::LEGACY_AS_GROUP). */
	const AS_GROUP = 'pass-captain';

	/** Cron hooks whose workers share one deadline. */
	const HOOKS = array( 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_waitlist_process' );

	/** Shared deadline (microtime) while a cron hook runs; null otherwise. */
	private static $deadline = null;

	/** Claims in flight: key => [table, id column, id, claimed status, release status, final status, max attempts, retry columns, extra columns]. */
	private static $claims = array();

	private static $shutdown_registered = false;

	/** Arms the shared deadline at the start of each worker hook and disarms it at the end. */
	public static function register() {
		foreach ( self::HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'arm' ), -1000, 0 );
			add_action( $hook, array( __CLASS__, 'disarm' ), 100000, 0 );
		}
	}

	/** Budget in seconds, capped so one more item with a 15 s HTTP timeout still fits into max_execution_time. */
	public static function seconds() {
		$seconds = (float) apply_filters( 'wpcap_cron_time_budget', self::DEFAULT_SECONDS );
		$max     = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) { $seconds = min( $seconds, $max - 15 ); }
		return (float) max( 1.0, $seconds );
	}

	public static function arm() { self::$deadline = microtime( true ) + self::seconds(); }

	public static function disarm() { self::$deadline = null; }

	/** Deadline for a worker call: the shared one while a hook runs, else a fresh one. */
	public static function deadline() {
		return null !== self::$deadline ? self::$deadline : microtime( true ) + self::seconds();
	}

	/** True when a worker should stop before claiming the next item. */
	public static function exhausted( $deadline ) { return microtime( true ) >= (float) $deadline; }

	/**
	 * Asks for an immediate follow-up run of $hook because a backlog remains. Uses Action Scheduler
	 * when available, else a single WP-Cron event (with an argument, so WP does not treat it as a
	 * duplicate of the recurring event of the same hook).
	 *
	 * Action Scheduler: only a PENDING continuation counts as "already planned". The continuation
	 * that is running right now (status in-progress) must not stop the next one from being queued –
	 * as_has_scheduled_action() counts running actions too, so a backlog used to wait for the next
	 * five-minute tick after the first follow-up run.
	 */
	public static function continue_later( $hook ) {
		$args = array( 'continue' );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// A follow-up run still pending in the pre-rename group counts as well (no second run).
			if ( ! self::as_pending( $hook, $args ) && ! \PassCaptain\LegacySlug::has_legacy_action( $hook, $args ) ) {
				as_enqueue_async_action( $hook, $args, self::AS_GROUP );
			}
			return true;
		}
		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
			if ( ! wp_next_scheduled( $hook, $args ) ) { wp_schedule_single_event( time(), $hook, $args ); }
			return true;
		}
		return false;
	}

	/** Whether Action Scheduler already holds a pending (not running) action of $hook with $args. */
	private static function as_pending( $hook, array $args ) {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$status = class_exists( '\\ActionScheduler_Store' ) ? \ActionScheduler_Store::STATUS_PENDING : 'pending';
			$ids = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => self::AS_GROUP, 'status' => $status, 'per_page' => 1 ), 'ids' );
			return ! empty( $ids );
		}
		// Older Action Scheduler without the query API: fall back to the combined check.
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::AS_GROUP );
	}

	/**
	 * Remembers an item claimed by this request until it is finished.
	 *
	 * With $final_status and $max_attempts, an item whose attempts (counted at claim time) are used up
	 * goes to $final_status instead of back to the queue: an item that kills the worker every time
	 * (fatal error, time limit) must not be retried forever.
	 *
	 * With $retry (due => due-time column, error => last-error column) a released item is not due again
	 * right away: it waits a backoff (1, 2, 4 … minutes by attempts, at most an hour) and keeps a
	 * readable last error. Otherwise the same poisonous item would be claimed first on every run
	 * (queues are ordered by due time) and block everything queued behind it.
	 *
	 * Short form claimed( …, $release_status, array $release_set ): extra columns set when the claim
	 * is released on shutdown (e.g. the next run time and an error note of a job whose worker died).
	 *
	 * @param string|array $final_status Final status, or the extra release columns (short form).
	 * @param array        $retry        due (string column), error (string column).
	 * @param array        $release_set  Extra columns (name => value) set on release.
	 */
	public static function claimed( $table, $id_column, $id, $claimed_status, $release_status, $final_status = '', $max_attempts = 0, array $retry = array(), array $release_set = array() ) {
		if ( is_array( $final_status ) ) {
			$release_set = $final_status;
			$final_status = '';
		}
		self::$claims[ $table . '#' . (int) $id ] = array( $table, $id_column, (int) $id, $claimed_status, $release_status, (string) $final_status, (int) $max_attempts, $retry, $release_set );
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'release_claims' ) );
		}
	}

	/** The claimed item was processed (whatever the outcome). */
	public static function finished( $table, $id ) { unset( self::$claims[ $table . '#' . (int) $id ] ); }

	/** Backoff in seconds after $attempts failed attempts (1, 2, 4 … minutes, capped at one hour). */
	public static function backoff( $attempts ) {
		return (int) min( HOUR_IN_SECONDS, 60 * ( 2 ** max( 0, min( (int) $attempts - 1, 6 ) ) ) );
	}

	/**
	 * Returns claims of items this request could not finish to the queue (shutdown, or an exception that
	 * escaped a worker). With retry columns the item is due again only after a backoff and carries a
	 * last error (the PHP fatal error message, redacted, when there is one).
	 *
	 * @param string $reason Optional error text (e.g. an exception message); redacted before storing.
	 */
	public static function release_claims( $reason = '' ) {
		global $wpdb;
		if ( ! self::$claims || ! isset( $wpdb ) ) { return; }
		$reason = is_string( $reason ) ? $reason : '';
		if ( '' === $reason ) {
			$last = error_get_last();
			$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
			if ( is_array( $last ) && ( (int) $last['type'] & $fatal ) ) {
				$reason = 'PHP fatal error: ' . (string) $last['message'];
			}
		}
		$reason = substr( \PassCaptain\Security\Redact::text( '' !== $reason ? $reason : 'Worker stopped while processing (fatal error or time limit).' ), 0, 190 );
		$now = current_time( 'mysql', true );
		foreach ( self::$claims as $key => $claim ) {
			list( $table, $column, $id, $claimed, $release, $final, $max, $retry ) = $claim;
			$extra = (array) ( $claim[8] ?? array() );
			unset( self::$claims[ $key ] );
			$sql = 'UPDATE %i SET status=';
			$args = array( $table );
			if ( '' !== $final && $max > 0 ) {
				$sql .= 'IF(attempts>=%d,%s,%s)';
				array_push( $args, $max, $final, $release );
			} else {
				$sql .= '%s';
				$args[] = $release;
			}
			$sql .= ',locked_at=NULL';
			$due = (string) ( $retry['due'] ?? '' );
			$error = (string) ( $retry['error'] ?? '' );
			if ( '' !== $due ) {
				// Due again after a backoff by attempts (same curve as backoff()).
				$sql .= ',%i=DATE_ADD(%s, INTERVAL LEAST(%d, 60*POW(2, LEAST(GREATEST(attempts-1,0),6))) SECOND)';
				array_push( $args, $due, $now, HOUR_IN_SECONDS );
			}
			if ( '' !== $error ) {
				$sql .= ',%i=%s';
				array_push( $args, $error, $reason );
			}
			foreach ( $extra as $name => $value ) {
				$name = sanitize_key( (string) $name );
				if ( '' === $name || in_array( $name, array( 'status', 'locked_at', $due, $error ), true ) ) { continue; }
				if ( null === $value ) {
					$sql .= ',%i=NULL';
					$args[] = $name;
				} else {
					$sql .= ',%i=%s';
					array_push( $args, $name, (string) $value );
				}
			}
			$sql .= ' WHERE %i=%d AND status=%s';
			array_push( $args, $column, $id, $claimed );
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, built above.
		}
	}

	/** Number of claims currently in flight (diagnostics/tests). */
	public static function open_claims() { return count( self::$claims ); }
}
