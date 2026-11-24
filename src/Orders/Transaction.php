<?php
/**
 * Transactions of the order services: start, state check after a server-side rollback, capacity release hook.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\DbSession;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind the private Orders::begin(), in_transaction() and capacity_released() and the public
 * Orders::transaction_alive() and reset_transaction_tracking(). Moved out of Orders without behaviour change
 * (TODO "Gott-Klasse Orders") so that the parts under src/Orders/ (Payment, Cancellation) use the same
 * transaction tracking without widening the Orders facade: begin(), in_transaction() and
 * capacity_released() stay private there.
 *
 * @internal Not part of the add-on API: only Orders and its parts call it; use the Orders facade (same signatures).
 */
final class Transaction {
	/**
	 * Whether the connection is still inside a transaction. False after the server rolled the
	 * transaction back (deadlock victim, lock wait timeout with innodb_rollback_on_timeout); null if
	 * the state cannot be determined.
	 *
	 * MariaDB reports it in @@in_transaction. MySQL has no such variable (querying it would only write
	 * "Unknown system variable" to the error log), so there a savepoint probe is used: a savepoint is
	 * only kept inside a transaction, so SAVEPOINT + RELEASE SAVEPOINT succeeds inside one and RELEASE
	 * fails ("SAVEPOINT does not exist") once the server has ended the transaction. The probe changes
	 * no data and errors are suppressed (no error-log line per order).
	 */
	/** @var bool|null Whether the server reports @@in_transaction (MariaDB); null = not asked yet. */
	private static $tracks_transactions = null;

	/** @var bool|null Whether the transaction marker table is usable on this connection; null = not set up. */
	private static $marker_ready = null;

	/** @var bool Whether the current transaction wrote its marker row (MySQL path). */
	private static $marker_active = false;

	/**
	 * Starts a transaction. On servers without @@in_transaction (MySQL) a marker row is written into a
	 * connection-local InnoDB temporary table inside the transaction: a server-side rollback (deadlock
	 * victim) removes it, so in_transaction() can tell that the transaction is gone.
	 *
	 * Every transaction runs under REPEATABLE READ, whatever the session default is (DbSession): the
	 * capacity checks rely on one consistent snapshot taken after the row locks. Under READ COMMITTED a
	 * payment committing between two reads would be missing from both sums (overselling).
	 *
	 * @return int|bool Result of START TRANSACTION (false on error).
	 */
	public static function begin() {
		global $wpdb;
		self::$marker_active = false;
		$marker = self::uses_marker() ? self::marker_table() : '';
		if ( '' !== $marker ) {
			// Outside the transaction (autocommit): clear the marker of the previous transaction. If that fails
			// (lost connection, temporary table gone), the table is set up again before the next transaction.
			$cleared = $wpdb->query( "DELETE FROM {$marker}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
			if ( false === $cleared ) { self::$marker_ready = null; $marker = ''; }
		}
		// Right before START TRANSACTION: SET TRANSACTION applies to the next transaction only (an
		// autocommit statement in between, like the marker DELETE above, would consume it).
		DbSession::repeatable_read();
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( false !== $started && '' !== $marker ) {
			// Only this transaction depends on the marker: a failed write leaves in_transaction() undecided (null).
			self::$marker_active = false !== $wpdb->query( "INSERT INTO {$marker} (id) VALUES (1)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
		}
		return $started;
	}

	/** True when the transaction state must be tracked with the marker table instead of @@in_transaction. */
	private static function uses_marker() {
		global $wpdb;
		if ( null === self::$tracks_transactions ) {
			$tracks = self::server_tracks_transactions( (string) $wpdb->get_var( 'SELECT VERSION()' ) );
			/** Test seam: force the marker path (false) or the @@in_transaction path (true). */
			self::$tracks_transactions = (bool) apply_filters( 'wpcap_db_tracks_in_transaction', $tracks );
		}
		return ! self::$tracks_transactions;
	}

	/** Name of the connection-local marker table ('' when it can't be created, e.g. no CREATE TEMPORARY TABLES privilege). */
	private static function marker_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_trx_marker';
		if ( null === self::$marker_ready ) {
			// CREATE TEMPORARY TABLE does not commit implicitly; it is visible only to this connection.
			$created = false !== $wpdb->query( $wpdb->prepare( 'CREATE TEMPORARY TABLE IF NOT EXISTS %i (id tinyint unsigned NOT NULL) ENGINE=InnoDB', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- connection-local temporary marker table, name from $wpdb->prefix.
			if ( $created ) {
				self::$marker_ready = true;
			} elseif ( false !== stripos( (string) $wpdb->last_error, 'denied' ) ) {
				// No CREATE TEMPORARY TABLES privilege: stop trying for this request. Transient errors (lost
				// connection, lock timeout) are retried with the next transaction.
				self::$marker_ready = false;
			}
		}
		return self::$marker_ready ? $table : '';
	}

	/**
	 * Whether the transaction started with begin() is still open: true/false, or null when the server
	 * can't tell (then callers rely on the checked statements and COMMIT alone).
	 */
	public static function in_transaction() {
		global $wpdb;
		if ( self::uses_marker() ) {
			$marker = ( self::$marker_ready && self::$marker_active ) ? $wpdb->prefix . 'wpcap_trx_marker' : '';
			if ( '' !== $marker ) {
				$value = $wpdb->get_var( "SELECT COUNT(*) FROM {$marker}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no input.
				if ( '' === $wpdb->last_error && null !== $value ) { return (int) $value > 0; }
			}
			// No marker for this transaction (no CREATE TEMPORARY TABLES privilege, failed write): savepoint probe.
			return self::savepoint_probe();
		}
		$value = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' === $wpdb->last_error && null !== $value ) { return 1 === (int) $value; }
		return self::savepoint_probe();
	}

	/** Savepoint probe (see in_transaction()): true inside a transaction, false outside, null if unknown. */
	private static function savepoint_probe() {
		global $wpdb;
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$set = $wpdb->query( 'SAVEPOINT wpcap_tx_probe' );
		$released = false !== $set ? $wpdb->query( 'RELEASE SAVEPOINT wpcap_tx_probe' ) : false;
		if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
		if ( false === $set ) { return null; }
		return false !== $released;
	}

	/** Resets the cached server detection (tests). */
	public static function reset_transaction_tracking() {
		self::$tracks_transactions = null;
		self::$marker_ready = null;
		self::$marker_active = false;
	}

	/** MariaDB reports its transaction state in @@in_transaction; MySQL does not. */
	private static function server_tracks_transactions( $version ) {
		return false !== stripos( (string) $version, 'mariadb' );
	}

	/**
	 * Whether the connection is still inside a transaction (false after a server-side rollback, null if
	 * unknown). For callers that started the transaction themselves (START TRANSACTION, not begin()), so
	 * the marker table of begin() is never consulted: @@in_transaction on MariaDB, else the savepoint probe.
	 */
	public static function transaction_alive() {
		global $wpdb;
		if ( ! self::uses_marker() ) {
			$value = $wpdb->get_var( 'SELECT @@in_transaction' );
			if ( '' === $wpdb->last_error && null !== $value ) { return 1 === (int) $value; }
		}
		return self::savepoint_probe();
	}

	/**
	 * Fires `wpcap_capacity_released` after inventory of an order stopped counting (hold expired,
	 * order cancelled/refunded, tickets revoked, payment unallocated). Core capacity and pools are
	 * derived from orders and holds, so this is informational for modules with own counters/caches.
	 */
	public static function capacity_released( $order_id, $reason, array $details = array() ) {
		do_action( 'wpcap_capacity_released', (int) $order_id, (string) $reason, $details );
	}
}
