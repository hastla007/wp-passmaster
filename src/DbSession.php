<?php
/**
 * Session settings and error classification for the inventory transactions (checkout, payment
 * confirmation, hold cleanup).
 *
 * - Isolation: the capacity checks read sold and held units with consistent (non-locking) reads after
 *   the ticket type/occurrence rows are locked. That is only exact under REPEATABLE READ, where one
 *   transaction sees one snapshot. Sites tuned to READ COMMITTED (a common WooCommerce tip) would
 *   oversell, so every inventory transaction sets REPEATABLE READ for itself (SET TRANSACTION, valid
 *   for the next transaction only; the session default of other plugins stays as it is).
 * - Lock waits: checkout and payment confirmation lower innodb_lock_wait_timeout for their own
 *   statements (default 5 s, filter `wp_pass_captain_lock_wait_timeout_v1`) and restore the previous
 *   session value afterwards, so a request waits seconds instead of the server default of 50 s.
 * - Deadlocks (1213) and lock wait timeouts (1205) are recognised by their server messages
 *   (identical on MySQL and MariaDB) and treated as "busy, retry" instead of a hard failure.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DbSession {
	/** Default innodb_lock_wait_timeout (seconds) inside checkout and payment confirmation. */
	const LOCK_WAIT_SECONDS = 5;

	/** @var int Nesting depth of with_short_lock_wait() (only the outermost call sets and restores). */
	private static $lock_wait_depth = 0;

	/** Whether the server is MariaDB (null = not checked yet; false also when the setting below is unknown there). */
	private static $snapshot_setting = null;

	/**
	 * MariaDB 11.6.2+ turns innodb_snapshot_isolation on by default: a locking read (SELECT … FOR UPDATE) of a row
	 * another request changed after this transaction's first read then fails with 1020 "Record has changed since
	 * last read" and the whole transaction is rolled back. Orders, check-in, seats and refunds rely on the classic
	 * InnoDB behaviour (the locking read sees the latest committed row) – as on MySQL and older MariaDB – so it is
	 * switched off for this connection when the plugin starts and before each inventory transaction (it applies from
	 * the next statement on, also inside a transaction another plugin opened). No effect on MySQL. Same approach and
	 * name as Voucher Captain (VCAP\Db::classic_locking_reads(), MINVER-1).
	 */
	public static function classic_locking_reads() {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return;
		}
		if ( null === self::$snapshot_setting ) {
			$info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->get_var( 'SELECT VERSION()' );
			self::$snapshot_setting = false !== stripos( $info, 'mariadb' );
		}
		if ( ! self::$snapshot_setting ) {
			return;
		}
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		if ( false === $wpdb->query( 'SET SESSION innodb_snapshot_isolation = OFF' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$snapshot_setting = false; // A MariaDB version without the setting: nothing to switch off.
		}
		if ( null !== $suppress ) {
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Sets REPEATABLE READ for the next transaction of this connection. Call right before START
	 * TRANSACTION. Fails harmlessly (returns false) when a transaction is already open.
	 */
	public static function repeatable_read() {
		global $wpdb;
		self::classic_locking_reads();
		return false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
	}

	/**
	 * Runs $callback with a short innodb_lock_wait_timeout for this session and restores the previous
	 * value afterwards (also when the callback throws). Nested calls reuse the outer setting.
	 *
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function with_short_lock_wait( callable $callback ) {
		global $wpdb;
		$set = false;
		if ( 0 === self::$lock_wait_depth ) {
			/**
			 * Seconds a checkout or payment confirmation waits for a row lock before it gives up and
			 * answers "busy, retry" (503 with Retry-After). 0 keeps the server setting.
			 *
			 * @param int $seconds Default 5.
			 */
			$seconds = (int) apply_filters( 'wp_pass_captain_lock_wait_timeout_v1', self::LOCK_WAIT_SECONDS );
			if ( $seconds > 0 ) {
				// One statement: remember the session value in a user variable and lower it.
				$set = false !== $wpdb->query( $wpdb->prepare( 'SET @wpcap_lock_wait = @@SESSION.innodb_lock_wait_timeout, SESSION innodb_lock_wait_timeout = %d', min( 3600, $seconds ) ) );
			}
		}
		++self::$lock_wait_depth;
		try {
			return $callback();
		} finally {
			--self::$lock_wait_depth;
			if ( $set ) {
				$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = COALESCE(@wpcap_lock_wait, @@SESSION.innodb_lock_wait_timeout)' );
			}
		}
	}

	/** Whether a server error message is a deadlock (1213) or lock wait timeout (1205): retryable. */
	public static function is_busy_error( $message ) {
		$message = (string) $message;
		return '' !== $message && ( false !== stripos( $message, 'Deadlock found' ) || false !== stripos( $message, 'Lock wait timeout' ) );
	}

	/**
	 * Isolation level of this session and of the server default, e.g. "REPEATABLE-READ" (MySQL 8 and
	 * MariaDB 11.1+ name the variable transaction_isolation, older MariaDB tx_isolation).
	 *
	 * @return array{session: string, global: string} Empty strings when unknown.
	 */
	public static function isolation() {
		global $wpdb;
		$out = array();
		// SHOW VARIABLES instead of @@…: an unknown variable name is no error there.
		foreach ( array( 'session' => 'SESSION', 'global' => 'GLOBAL' ) as $key => $scope ) {
			$value = '';
			foreach ( (array) $wpdb->get_results( "SHOW {$scope} VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation')", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed scope keyword.
				$row = array_change_key_case( (array) $row, CASE_LOWER );
				if ( '' === $value || 'transaction_isolation' === ( $row['variable_name'] ?? '' ) ) { $value = strtoupper( (string) ( $row['value'] ?? '' ) ); }
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * System status issue when the server default is not REPEATABLE READ (the plugin forces it for its
	 * own inventory transactions, but other code and older add-ons may rely on the default).
	 *
	 * @return array[] {severity, message}
	 */
	public static function status_issues() {
		$level = self::isolation();
		$levels = array_filter( array_unique( array( $level['global'], $level['session'] ) ) );
		$other = array_values( array_diff( $levels, array( 'REPEATABLE-READ' ) ) );
		if ( ! $other ) { return array(); }
		return array(
			array(
				'severity' => 'warning',
				/* translators: %s: isolation level, e.g. READ-COMMITTED */
				'message' => sprintf( __( 'Die Datenbank läuft mit der Transaktions-Isolation %s. Pass Captain setzt für Bestellungen, Zahlungsbestätigungen und das Aufräumen von Reservierungen selbst REPEATABLE READ; Erweiterungen, die eigene Kontingent-Transaktionen öffnen, sollten dasselbe tun (siehe docs/OPERATIONS.md).', 'pass-captain' ), implode( ' / ', $other ) ),
			),
		);
	}
}
