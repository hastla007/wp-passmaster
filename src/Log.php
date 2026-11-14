<?php
/**
 * Single logging helper for operational problems (database errors, stuck queue items, failed upgrades).
 *
 * Every entry
 * - fires the action `wpcap_log` (level, event, message, context) so an add-on or a logging plugin can
 *   forward it;
 * - is written to the audit table (`wpcap_audit_logs`, action = event) when that table exists, so it is
 *   visible in the admin without server access;
 * - reaches the PHP error log only when the site enabled it: `WP_DEBUG` and `WP_DEBUG_LOG`, or the filter
 *   `wpcap_log_to_error_log` returns true (e.g. on hosts without debug logging that still want it).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Log {
	/** @var array<string,bool> Audit table availability per table name (per request). */
	private static $audit_tables = array();

	/** @var bool Guards against recursion when the audit insert itself triggers a log call. */
	private static $writing = false;

	/**
	 * Logs an error.
	 *
	 * @param string $event   Short machine name (becomes the audit action), e.g. `refund_db_error`.
	 * @param string $message Human readable message for the PHP error log (no personal data).
	 * @param array  $context object_type (string), object_id (int), details (array, audit details),
	 *                        audit (bool, default true).
	 */
	public static function error( $event, $message, array $context = array() ) {
		self::write( 'error', $event, $message, $context );
	}

	/** Logs a warning (same channels as error()). */
	public static function warning( $event, $message, array $context = array() ) {
		self::write( 'warning', $event, $message, $context );
	}

	/** Whether entries of this level/event go to the PHP error log. */
	public static function to_error_log( $level, $event ) {
		$enabled = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		return (bool) apply_filters( 'wpcap_log_to_error_log', $enabled, (string) $level, (string) $event );
	}

	private static function write( $level, $event, $message, array $context ) {
		if ( self::$writing ) { return; }
		self::$writing = true;
		try {
			$event = sanitize_key( (string) $event );
			$message = (string) $message;
			/** Every Pass Captain log entry (level `error`/`warning`). */
			do_action( 'wpcap_log', $level, $event, $message, $context );
			if ( self::to_error_log( $level, $event ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only when the site enabled WP_DEBUG_LOG or the wpcap_log_to_error_log filter.
				error_log( 'Pass Captain [' . $level . '] ' . $event . ': ' . $message );
			}
			if ( ( $context['audit'] ?? true ) && self::audit_available() ) {
				$details = (array) ( $context['details'] ?? array() );
				if ( ! $details ) { $details = array( 'message' => substr( $message, 0, 500 ) ); }
				Audit::record( $event, (string) ( $context['object_type'] ?? 'system' ), absint( $context['object_id'] ?? 0 ), $details );
			}
		} finally {
			self::$writing = false;
		}
	}

	/** True when the audit table exists (checked once per request and table name). */
	private static function audit_available() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
		$table = $wpdb->prefix . 'wpcap_audit_logs';
		if ( ! isset( self::$audit_tables[ $table ] ) ) {
			$like = addcslashes( $table, '_%\\' ); // Same as $wpdb->esc_like().
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			// A failed lookup (database error) is not cached: the next entry checks again.
			if ( null === $found && '' !== (string) $wpdb->last_error ) { return false; }
			self::$audit_tables[ $table ] = null !== $found;
		}
		return self::$audit_tables[ $table ];
	}
}
