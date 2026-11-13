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

	/** Cron hooks whose workers share one deadline. */
	const HOOKS = array( 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_waitlist_process' );

	/** Shared deadline (microtime) while a cron hook runs; null otherwise. */
	private static $deadline = null;

	/** Claims in flight: key => [table, id column, id, claimed status, release status]. */
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
	 */
	public static function continue_later( $hook ) {
		$args = array( 'continue' );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( $hook, $args, 'pass-captain' ) ) {
				as_enqueue_async_action( $hook, $args, 'pass-captain' );
			}
			return true;
		}
		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
			if ( ! wp_next_scheduled( $hook, $args ) ) { wp_schedule_single_event( time(), $hook, $args ); }
			return true;
		}
		return false;
	}

	/** Remembers an item claimed by this request until it is finished. */
	public static function claimed( $table, $id_column, $id, $claimed_status, $release_status ) {
		self::$claims[ $table . '#' . (int) $id ] = array( $table, $id_column, (int) $id, $claimed_status, $release_status );
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'release_claims' ) );
		}
	}

	/** The claimed item was processed (whatever the outcome). */
	public static function finished( $table, $id ) { unset( self::$claims[ $table . '#' . (int) $id ] ); }

	/** Returns claims of items this request could not finish to the queue. */
	public static function release_claims() {
		global $wpdb;
		foreach ( self::$claims as $key => list( $table, $column, $id, $claimed, $release ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET status=%s,locked_at=NULL WHERE $column=%d AND status=%s", $release, $id, $claimed ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table/column names.
			unset( self::$claims[ $key ] );
		}
	}

	/** Number of claims currently in flight (diagnostics/tests). */
	public static function open_claims() { return count( self::$claims ); }
}
