<?php
/**
 * Named MySQL locks (GET_LOCK) scoped to this installation.
 *
 * GET_LOCK names are global to the database server, not per database: two WordPress installs on
 * one server with the same table prefix (e.g. "wp_") would otherwise block each other. The name
 * starts with a hash of table prefix and DB_NAME (like Installer::lock_name()) and stays within
 * MySQL's 64-character limit (A364).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DbLock {
	/**
	 * Lock name for a purpose, unique per installation (prefix + database).
	 *
	 * @param string $purpose Short purpose, e.g. "wl_12" (letters, digits, underscore).
	 * @return string At most 64 characters.
	 */
	public static function name( $purpose ) {
		global $wpdb;
		$scope = substr( md5( (string) $wpdb->prefix . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 12 );
		return substr( 'wpcap_' . $scope . '_' . preg_replace( '/[^A-Za-z0-9_]/', '', (string) $purpose ), 0, 64 );
	}

	/**
	 * Takes the lock.
	 *
	 * @param string $purpose Purpose (see name()).
	 * @param int    $wait    Seconds to wait for another holder (0 = do not wait).
	 * @return bool Whether this session holds the lock now.
	 */
	public static function acquire( $purpose, $wait = 0 ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', self::name( $purpose ), max( 0, (int) $wait ) ) );
	}

	/** Releases the lock taken with acquire(). */
	public static function release( $purpose ) {
		self::release_scoped( self::name( $purpose ) );
	}

	/**
	 * Takes a lock under a name the caller already scoped to this installation (e.g. the buyer locks
	 * of Sales\BuyerLimits, which carry Installer::lock_scope()). At most 64 characters.
	 */
	public static function acquire_scoped( $name, $wait = 0 ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', Text::cut( (string) $name, 64 ), max( 0, (int) $wait ) ) );
	}

	/** Releases a lock taken with acquire_scoped(). */
	public static function release_scoped( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', Text::cut( (string) $name, 64 ) ) );
	}
}
