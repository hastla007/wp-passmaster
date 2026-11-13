<?php
/**
 * Fixed-window rate limiter, atomic across PHP workers and web servers.
 *
 * Backends (filter `wpcap_rate_limit_backend`):
 * - `cache`: wp_cache_add() + wp_cache_incr() when a persistent object cache is installed
 *   (wp_using_ext_object_cache()); atomic with Redis/Memcached shared by all web servers.
 * - `db` (default without object cache): one row per bucket and window in `wpcap_rate_limits`,
 *   counted with a single `INSERT … ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)`, so every
 *   worker gets its own exact count.
 * - `transient`: the former, non-atomic counter; used only as a fallback when the table is missing
 *   (before the database upgrade) or the database fails.
 *
 * Every call counts as a hit; a call is allowed while the hit count of the current window is at most
 * $limit, so under any concurrency exactly $limit calls per window are allowed.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RateLimit {
	const GROUP = 'wpcap_rate';

	/** Backend that served the last hit (diagnostics, tests). */
	public static $last_backend = '';

	/** Records a hit; returns false once more than $limit hits happened in the current window. */
	public static function allow( $bucket, $limit, $window = 60 ) {
		$window = max( 1, (int) $window );
		$slot = (int) floor( time() / $window );
		// Keyed hash: bucket names may contain client addresses or IDs; a plain md5 of an IPv4
		// address can be reversed by brute force from the database / object cache.
		$key = substr( hash_hmac( 'sha256', $bucket . '|' . $slot, self::secret() ), 0, 40 );
		return self::hit( $key, $window, ( $slot + 1 ) * $window ) <= (int) $limit;
	}

	/** Anonymised client key (keyed hash of the client address, see ClientIp; site-specific secret). */
	public static function client() {
		return hash_hmac( 'sha256', ClientIp::get(), self::secret() );
	}

	/** Active backend: cache | db | transient. */
	public static function backend() {
		$default = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ? 'cache' : 'db';
		$backend = (string) apply_filters( 'wpcap_rate_limit_backend', $default );
		return in_array( $backend, array( 'cache', 'db', 'transient' ), true ) ? $backend : $default;
	}

	/** Deletes rows of past windows (cron `wpcap_cleanup_reservations`, and now and then on a hit). */
	public static function purge( $limit = 1000 ) {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_rate_limits WHERE expires_at < %s LIMIT %d", gmdate( 'Y-m-d H:i:s' ), max( 1, (int) $limit ) ) );
		return false === $result ? 0 : (int) $result;
	}

	/** Atomically increments the counter of $key and returns the new count. */
	private static function hit( $key, $window, $expires_ts ) {
		$backend = self::backend();
		if ( 'cache' === $backend && function_exists( 'wp_cache_incr' ) ) {
			wp_cache_add( $key, 0, self::GROUP, $window );
			$count = wp_cache_incr( $key, 1, self::GROUP );
			if ( false !== $count ) {
				self::$last_backend = 'cache';
				return (int) $count;
			}
			// Evicted between add and incr, or no shared cache: count in the database instead.
		}
		if ( 'transient' !== $backend ) {
			$count = self::db_hit( $key, $expires_ts );
			if ( null !== $count ) {
				self::$last_backend = 'db';
				return $count;
			}
		}
		self::$last_backend = 'transient';
		$transient = 'wpcap_rl_' . $key;
		$count = (int) get_transient( $transient ) + 1;
		set_transient( $transient, $count, $window );
		return $count;
	}

	/** Database counter; null when the table is missing or the database fails. */
	private static function db_hit( $key, $expires_ts ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_rate_limits';
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		// LAST_INSERT_ID(expr) stores the value per connection: the count this worker produced, not a later one.
		$done = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (rl_key,hits,expires_at) VALUES (%s,LAST_INSERT_ID(1),%s) ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)", $key, gmdate( 'Y-m-d H:i:s', (int) $expires_ts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$count = false === $done ? null : (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
		if ( null !== $count && 0 === random_int( 0, 199 ) ) {
			self::purge( 500 );
		}
		return null !== $count && $count > 0 ? $count : null;
	}

	/** Site secret from wp_salt(); a static key would make the hashes identical across all sites. */
	private static function secret() {
		return function_exists( 'wp_salt' ) ? 'wpcap-rate|' . wp_salt( 'nonce' ) : 'wpcap-rate|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : ABSPATH );
	}
}
