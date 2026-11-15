<?php
/** Fixed-window rate limiter: persistent object cache, otherwise an atomic counter table (wpcap_rate_limits). */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RateLimit {
	/** Records a hit; returns false once $limit hits happened in the current window. */
	public static function allow( $bucket, $limit, $window = 60 ) {
		$slot = (int) floor( time() / max( 1, (int) $window ) );
		// Keyed hash: bucket names may contain client addresses or IDs; a plain md5 of an IPv4
		// address can be reversed by brute force from the options table / object cache.
		$key = 'wpcap_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $slot, self::secret() ), 0, 40 );
		return self::hit( $key, $limit, $window );
	}

	/**
	 * Counts one hit on the counter $key; false once $limit hits were counted within $ttl seconds.
	 *
	 * Every regular path counts atomically, so parallel requests cannot all read the same old value
	 * and pass together (QA-V2):
	 * - with a persistent object cache: wp_cache_add() + wp_cache_incr() in the "transient" group;
	 * - otherwise: one row per bucket in the table wpcap_rate_limits, increased by a single
	 *   INSERT … ON DUPLICATE KEY UPDATE (the row lock serialises parallel hits). The bucket is the
	 *   keyed hash of $key (hash()), so client addresses are not stored. An expired row restarts at
	 *   1; expired rows are deleted in batches by the daily retention run (PERF-16: no options row
	 *   per client and no transient garbage).
	 * Only while the table does not exist yet (before the schema upgrade ran) is the transient
	 * read-modify-write used as a best-effort fallback. In the table the counter stops at
	 * $limit + 1 (refused hits do not pile up, so a raised limit applies at once, as with the
	 * transient); the object cache counts every hit. The window (TTL from the first hit) is never
	 * extended by further hits.
	 *
	 * @param string $key   Counter name (any string; at most 172 characters for the fallback).
	 * @param int    $limit Allowed hits per TTL.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function hit( $key, $limit, $ttl ) {
		$ttl = max( 1, (int) $ttl );
		if ( self::object_cache() ) {
			wp_cache_add( $key, 0, 'transient', $ttl );
			$count = wp_cache_incr( $key, 1, 'transient' );
			if ( false !== $count ) { return (int) $count <= (int) $limit; }
		}
		$count = self::db_hit( (string) $key, (int) $limit, $ttl );
		if ( null !== $count ) { return $count <= (int) $limit; }
		$count = (int) get_transient( $key );
		if ( $count >= (int) $limit ) { return false; }
		set_transient( $key, $count + 1, $ttl );
		return true;
	}

	/**
	 * Hits counted on $key in its current window, without counting one (0 when none or expired).
	 * For limits that only count failures: check count() first, call hit() on a failure.
	 */
	public static function count( $key ) {
		global $wpdb;
		if ( self::object_cache() ) {
			$value = wp_cache_get( $key, 'transient' );
			return false === $value ? 0 : (int) $value;
		}
		if ( self::has_db() ) {
			$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$hits = $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE bucket_key=%s AND expires_at>%s', self::table(), self::hash( (string) $key ), gmdate( 'Y-m-d H:i:s' ) ) );
			$failed = '' !== (string) $wpdb->last_error;
			if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
			if ( ! $failed ) { return (int) $hits; }
		}
		return (int) get_transient( $key );
	}

	/** Rate-limit table of the current site. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_rate_limits';
	}

	/**
	 * Table definition (dbDelta format), created by the Installer. bucket_key is hash() of the
	 * counter name (HMAC-SHA256, 64 hex characters).
	 *
	 * @param string $table   Table name with prefix.
	 * @param string $charset Charset/collation clause.
	 */
	public static function schema( $table, $charset ) {
		return "CREATE TABLE $table (
				bucket_key char(64) NOT NULL,
				window_start datetime NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (bucket_key),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB $charset;";
	}

	/**
	 * Deletes expired counters in batches (daily retention run); returns the number of rows deleted.
	 *
	 * @param int|null   $now      Reference time (UNIX, tests).
	 * @param float|null $deadline microtime() after which no further batch starts.
	 * @param int        $batch    Rows per DELETE.
	 */
	public static function purge_expired( $now = null, $deadline = null, $batch = 5000 ) {
		global $wpdb;
		if ( ! self::has_db() ) { return 0; }
		$cutoff = gmdate( 'Y-m-d H:i:s', null === $now ? time() : (int) $now );
		$deadline = null === $deadline ? microtime( true ) + 10.0 : (float) $deadline;
		$batch = max( 1, (int) $batch );
		$total = 0;
		do {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<=%s LIMIT %d', self::table(), $cutoff, $batch ) );
			if ( false === $deleted ) { break; }
			$total += (int) $deleted;
		} while ( (int) $deleted >= $batch && microtime( true ) < $deadline );
		return $total;
	}

	/**
	 * Atomic hit in wpcap_rate_limits; returns the count after this hit (exact for this request), or
	 * null when the table is not usable. Portable between MariaDB and MySQL 8: no VALUES() in the UPDATE part (deprecated
	 * since MySQL 8.0.20) and no row alias (not supported by MariaDB) – the new values are passed
	 * again as parameters. Assignments run left to right: hits and window_start still see the old
	 * expires_at, which is updated last.
	 */
	private static function db_hit( $key, $limit, $ttl ) {
		global $wpdb;
		if ( ! self::has_db() ) { return null; }
		$now = time();
		$start = gmdate( 'Y-m-d H:i:s', $now );
		$expires = gmdate( 'Y-m-d H:i:s', $now + $ttl );
		$bucket = self::hash( $key );
		$table = self::table();
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		// LAST_INSERT_ID(expr) hands the value this statement wrote back to this connection only, so
		// the count is exact per request even when parallel requests hit the same row (a separate
		// SELECT could already see their hits and reject more than necessary).
		$ok = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (bucket_key, window_start, hits, expires_at) VALUES (%s, %s, LAST_INSERT_ID(1), %s) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(expires_at<=%s, 1, LEAST(hits, %d) + 1)), window_start = IF(expires_at<=%s, %s, window_start), expires_at = IF(expires_at<=%s, %s, expires_at)', $table, $bucket, $start, $expires, $start, max( 0, $limit ), $start, $start, $start, $expires ) );
		$hits = false === $ok ? null : $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
		return null === $hits ? null : (int) $hits;
	}

	/** Whether a database connection with a table prefix is available. */
	private static function has_db() {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->prefix );
	}

	/** Whether a persistent object cache with atomic increments is in use. */
	private static function object_cache() {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' );
	}

	/** Anonymised client key (keyed hash of the client address, site-specific secret). */
	public static function client() {
		return hash_hmac( 'sha256', self::client_ip(), self::secret() );
	}

	/**
	 * Network of the client for rate limits: the IPv4 address, or the /64 prefix of an IPv6 address
	 * (one end-user network; rotating addresses inside it must not reset the limit).
	 *
	 * @param string|null $ip Address (default: client_ip()).
	 */
	public static function client_network( $ip = null ) {
		$ip = null === $ip ? self::client_ip() : (string) $ip;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) { return $ip; }
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) { return $ip; }
		if ( 0 === strncmp( $packed, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) { return (string) inet_ntop( substr( $packed, 12 ) ); } // IPv4-mapped.
		return (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Keyed hash of any identifying value (e-mail address, client address plus scope) for transient
	 * and lock names. An unkeyed sha256/md5 of an e-mail or IPv4 address can be reversed by brute
	 * force or a dictionary from the options table / object cache.
	 */
	public static function hash( $value ) {
		return hash_hmac( 'sha256', (string) $value, self::secret() );
	}

	/** Forwarding headers that can be trusted when the request comes from a trusted proxy. */
	const PROXY_HEADERS = array(
		'x_forwarded_for' => 'HTTP_X_FORWARDED_FOR',
		'x_real_ip' => 'HTTP_X_REAL_IP',
		'cf_connecting_ip' => 'HTTP_CF_CONNECTING_IP',
		'true_client_ip' => 'HTTP_TRUE_CLIENT_IP',
	);

	/**
	 * Trusted reverse proxies (IP addresses or CIDR ranges): setting "trusted_proxies", constant
	 * WPCAP_TRUSTED_PROXIES (comma separated) and filter wpcap_trusted_proxies. Empty by default:
	 * forwarding headers are then ignored, because any client can send them.
	 */
	public static function trusted_proxies() {
		$raw = (string) Settings::get( 'trusted_proxies' );
		if ( defined( 'WPCAP_TRUSTED_PROXIES' ) ) { $raw .= ',' . (string) constant( 'WPCAP_TRUSTED_PROXIES' ); }
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ) );
		$list = (array) apply_filters( 'wpcap_trusted_proxies', array_values( $list ) );
		return array_values( array_filter( array_map( 'strval', $list ), static function ( $entry ) {
			$ip = explode( '/', $entry, 2 )[0];
			return false !== filter_var( $ip, FILTER_VALIDATE_IP );
		} ) );
	}

	private static function is_trusted( $ip, array $trusted ) {
		foreach ( $trusted as $entry ) {
			if ( Security\OutboundHttp::ip_in_cidr( $ip, false === strpos( $entry, '/' ) ? $entry . ( false !== strpos( $entry, ':' ) ? '/128' : '/32' ) : $entry ) ) { return true; }
		}
		return false;
	}

	/**
	 * Client address for rate limits. REMOTE_ADDR, unless REMOTE_ADDR is a trusted proxy: then the
	 * configured header (setting "trusted_proxy_header": x_forwarded_for, x_real_ip,
	 * cf_connecting_ip, true_client_ip) is used. For X-Forwarded-For the right-most address that is
	 * not itself a trusted proxy is the client (left-most entries are client-controlled).
	 * The filter wpcap_client_ip can still replace the result; invalid values fall back to REMOTE_ADDR.
	 *
	 * @param array|null $server $_SERVER (tests pass their own).
	 */
	public static function client_ip( $server = null ) {
		$server = is_array( $server ) ? $server : $_SERVER;
		$remote = trim( sanitize_text_field( wp_unslash( (string) ( $server['REMOTE_ADDR'] ?? '' ) ) ) );
		$ip = $remote;
		$trusted = self::trusted_proxies();
		if ( $trusted && false !== filter_var( $remote, FILTER_VALIDATE_IP ) && self::is_trusted( $remote, $trusted ) ) {
			$name = (string) Settings::get( 'trusted_proxy_header' );
			$header = self::PROXY_HEADERS[ $name ] ?? self::PROXY_HEADERS['x_forwarded_for'];
			$value = trim( (string) ( $server[ $header ] ?? '' ) );
			if ( '' !== $value ) {
				$hops = array_reverse( array_map( 'trim', explode( ',', $value ) ) );
				foreach ( $hops as $hop ) {
					$hop = trim( preg_replace( '/^\[([^\]]+)\](:\d+)?$/', '$1', $hop ) );
					if ( false === filter_var( $hop, FILTER_VALIDATE_IP ) ) { break; }
					$ip = $hop;
					if ( ! self::is_trusted( $hop, $trusted ) ) { break; }
				}
			}
		}
		$filtered = trim( (string) apply_filters( 'wpcap_client_ip', $ip, $server ) );
		return false !== filter_var( $filtered, FILTER_VALIDATE_IP ) ? $filtered : ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote );
	}

	/** Site secret from wp_salt(); a static key would make the hashes identical across all sites. */
	private static function secret() {
		return function_exists( 'wp_salt' ) ? 'wpcap-rate|' . wp_salt( 'nonce' ) : 'wpcap-rate|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : ABSPATH );
	}
}
