<?php
/**
 * Database drop-ins with replicas (HyperDB, LudicrousDB and compatible), A285.
 *
 * Such drop-ins send every statement that starts with SELECT to a replica. Inside a transaction that
 * breaks the locking the plugin relies on: `SELECT … FOR UPDATE` (row locks against overselling,
 * double payment, double check-in), `GET_LOCK()` and the reads between START TRANSACTION and COMMIT
 * would run on another connection – without the transaction, without the lock and possibly on stale
 * data. This class hooks the `query` filter (applied by wpdb and by these drop-ins before a statement
 * is routed) and switches all further reads of the request to the primary as soon as a transaction
 * or a locking read starts. Plain reads (event list, availability display) keep using the replicas
 * until then.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DbReplication {
	/** Statements that need the primary for themselves and every following read of the request. */
	const PATTERN = '/^\s*(?:START\s+TRANSACTION|BEGIN\b|SET\s+(?:SESSION\s+)?TRANSACTION|SELECT\b.*?(?:\bFOR\s+UPDATE\b|\bFOR\s+SHARE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bGET_LOCK\s*\())/is';

	private static $primary = false;

	/** Registers the filter when the active database class can route reads (no effect with plain wpdb). */
	public static function register() {
		global $wpdb;
		if ( self::supported( $wpdb ) ) {
			add_filter( 'query', array( __CLASS__, 'route' ), 0 );
		}
	}

	/** Whether the database object is a replica-aware drop-in. */
	public static function supported( $db ) {
		return is_object( $db ) && ( method_exists( $db, 'send_reads_to_masters' ) || method_exists( $db, 'send_reads_to_primaries' ) );
	}

	/**
	 * `query` filter: sends this and all later reads to the primary when a transaction or a locking read
	 * starts. Returns the statement unchanged.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public static function route( $query ) {
		if ( ! self::$primary && is_string( $query ) && preg_match( self::PATTERN, $query ) ) {
			self::use_primary();
		}
		return $query;
	}

	/** Sends every further read of this request to the primary (also callable before a critical read). */
	public static function use_primary() {
		global $wpdb;
		if ( ! self::supported( $wpdb ) ) { return false; }
		if ( method_exists( $wpdb, 'send_reads_to_primaries' ) ) {
			$wpdb->send_reads_to_primaries();
		} else {
			$wpdb->send_reads_to_masters();
		}
		self::$primary = true;
		return true;
	}

	/** Tests: forget the per-request state. */
	public static function reset() {
		self::$primary = false;
	}
}
