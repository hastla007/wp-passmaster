<?php
/**
 * Fixed-window rate limiter, atomic across PHP workers and web servers.
 *
 * Backends (filter `wpcap_rate_limit_backend`):
 * - `cache`: wp_cache_add() + wp_cache_incr() when a persistent object cache is installed
 *   (wp_using_ext_object_cache()); atomic with Redis/Memcached shared by all web servers.
 * - `db` (default without object cache): one row per bucket in `wpcap_rate_limits` (bucket_key = keyed
 *   hash of the counter name, no client data), counted with a single
 *   `INSERT … ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(…)`, so every worker gets its own exact count
 *   (QA-V2, PERF-16, A186).
 * - `transient`: the former, non-atomic counter; used only as a fallback when the table is missing
 *   (before the database upgrade) or the database fails.
 *
 * @package PassCaptain
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class RateLimit {
	const GROUP = 'wpcap_rate';

	/** Backend that served the last hit (diagnostics, tests). */
	public static $last_backend = '';

	/**
	 * SET-PC-38: limit an operator set for a rate (settings rate_*, default = the former fixed value).
	 * A stored value outside the register limits (only possible without the settings screen) is
	 * raised to the safe lower bound; filters of the callers still have the last word.
	 */
	public static function configured( $key ) {
		$default = (int) ( Settings::defaults()[ $key ] ?? 1 );
		$value = Settings::get( $key, $default );
		$value = is_numeric( $value ) ? (int) $value : $default;
		$entry = SettingsSchema::get( $key );
		if ( is_array( $entry ) && 'int' === $entry['type'] ) { $value = min( (int) $entry['max'], max( (int) $entry['min'], $value ) ); }
		return max( 1, $value );
	}

	/** Records a hit; returns false once more than $limit hits happened in the current window. */
	public static function allow( $bucket, $limit, $window = 60 ) {
		list( $key, $window, $slot ) = self::key( $bucket, $window );
		// The key contains the window slot: the counter lives until the end of that slot.
		return self::hit( $key, $limit, max( 1, ( $slot + 1 ) * $window - Clock::real() ) );
	}

	/**
	 * Hits of the bucket in the current window, without counting one (A279/A288). Failure counters
	 * check `hits() >= $limit` before the attempt and record a failure with allow() afterwards, so only
	 * failed attempts count; parallel requests can exceed the limit at most by the requests in flight.
	 */
	public static function hits( $bucket, $window = 60 ) {
		list( $key ) = self::key( $bucket, $window );
		return self::count( $key );
	}

	/** Counter name, window and slot of a bucket for allow()/hits(). */
	private static function key( $bucket, $window ) {
		$window = max( 1, (int) $window );
		$slot = (int) floor( Clock::real() / $window );
		// Keyed hash: bucket names may contain client addresses or IDs; a plain md5 of an IPv4
		// address can be reversed by brute force from the options table / object cache.
		return array( 'wpcap_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $slot, self::secret() ), 0, 40 ), $window, $slot );
	}

	/** Active backend: cache | db | transient (filter wpcap_rate_limit_backend). */
	public static function backend() {
		$default = self::object_cache() ? 'cache' : 'db';
		$backend = function_exists( 'apply_filters' ) ? (string) apply_filters( 'wpcap_rate_limit_backend', $default ) : $default;
		if ( 'cache' === $backend && ! function_exists( 'wp_cache_incr' ) ) { return 'db'; }
		return in_array( $backend, array( 'cache', 'db', 'transient' ), true ) ? $backend : $default;
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
		$backend = self::backend();
		if ( 'cache' === $backend ) {
			wp_cache_add( $key, 0, 'transient', $ttl );
			$count = wp_cache_incr( $key, 1, 'transient' );
			if ( false !== $count ) {
				self::$last_backend = 'cache';
				return (int) $count <= (int) $limit;
			}
			// Evicted between add and incr, or no shared cache: count in the database instead.
		}
		if ( 'transient' !== $backend ) {
			$count = self::db_hit( (string) $key, (int) $limit, $ttl );
			if ( null !== $count ) {
				self::$last_backend = 'db';
				return $count <= (int) $limit;
			}
		}
		self::$last_backend = 'transient';
		$count = (int) get_transient( $key );
		if ( $count >= (int) $limit ) { return false; }
		set_transient( $key, $count + 1, $ttl );
		return true;
	}

	/**
	 * Takes back one hit that hit() allowed on $key (e.g. a checkout that did not create an order), so
	 * only successful actions use up the limit while the check itself stays atomic. Never goes below 0
	 * and does not touch an expired window. Not undone in the transient fallback (before the table exists).
	 *
	 * @param string $key Counter name passed to hit().
	 */
	public static function refund( $key ) {
		global $wpdb;
		$backend = self::backend();
		if ( 'cache' === $backend ) {
			$done = function_exists( 'wp_cache_decr' ) ? wp_cache_decr( $key, 1, 'transient' ) : wp_cache_incr( $key, -1, 'transient' );
			if ( false !== $done ) { return; }
		}
		if ( 'transient' !== $backend && self::has_db() ) {
			$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits=GREATEST(hits,1)-1 WHERE bucket_key=%s AND expires_at>%s', self::table(), self::hash( (string) $key ), Clock::real_mysql() ) );
			if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
			if ( false !== $ok ) { return; }
		}
		// Transient fallback (table not created yet): best effort, the hit stays counted (its TTL is unknown here).
	}

	/**
	 * Hits counted on $key in its current window, without counting one (0 when none or expired).
	 * For limits that only count failures: check count() first, call hit() on a failure.
	 */
	public static function count( $key ) {
		global $wpdb;
		$backend = self::backend();
		if ( 'cache' === $backend ) {
			$value = wp_cache_get( $key, 'transient' );
			if ( false !== $value ) { return (int) $value; }
		}
		if ( 'transient' !== $backend && self::has_db() ) {
			$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$hits = $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE bucket_key=%s AND expires_at>%s', self::table(), self::hash( (string) $key ), Clock::real_mysql() ) );
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
		$cutoff = gmdate( 'Y-m-d H:i:s', null === $now ? Clock::real() : (int) $now );
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

	/** Deletes up to $limit expired counters (one batch; kept for callers of the former API). */
	public static function purge( $limit = 1000 ) {
		return self::purge_expired( null, null, max( 1, (int) $limit ) );
	}

	/**
	 * One-time claim of a key for $ttl seconds (A366: replay protection of signed callbacks): true for
	 * the first call, false for every further call until the claim expires. Atomic with the database
	 * and object-cache backends (exactly one of parallel callers gets true); the transient fallback is
	 * best effort.
	 */
	public static function claim( $bucket, $ttl ) {
		$ttl = max( 1, (int) $ttl );
		$key = 'wpcap_rl_' . substr( hash_hmac( 'sha256', 'claim|' . $bucket, self::secret() ), 0, 40 );
		$backend = self::backend();
		if ( 'cache' === $backend && wp_cache_add( $key, 1, self::GROUP, $ttl ) ) {
			self::$last_backend = 'cache';
			return true;
		}
		if ( 'cache' === $backend && false !== wp_cache_get( $key, self::GROUP ) ) {
			self::$last_backend = 'cache';
			return false;
		}
		if ( 'transient' !== $backend ) {
			// Limit 1: the first hit of the window counts 1, every further one 2 (the counter stops at limit + 1).
			$count = self::db_hit( $key, 1, $ttl );
			if ( null !== $count && $count > 0 ) {
				self::$last_backend = 'db';
				return 1 === $count;
			}
		}
		self::$last_backend = 'transient';
		if ( get_transient( $key ) ) { return false; }
		set_transient( $key, 1, $ttl );
		return true;
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
		$now = Clock::real();
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

	/**
	 * Anonymised client key (keyed hash, site-specific secret) of the client address (client_ip()).
	 * IPv6 clients count per network prefix, /64 by default (ClientIp::limit_key(), A280), so rotating
	 * addresses inside one connection does not reset limits.
	 *
	 * @param int $ipv6_prefix Prefix length for IPv6 (0 = default /64; e.g. 48 for the wider network).
	 */
	public static function client( $ipv6_prefix = 0 ) {
		$ip = self::client_ip();
		$key = ClientIp::limit_key( $ip, $ipv6_prefix );
		return hash_hmac( 'sha256', '' !== $key ? $key : $ip, self::secret() );
	}

	/**
	 * Per-client limit for requests that hold inventory (checkout, seat/service holds, waitlist): counts
	 * the client (IPv6: its /64) and, for IPv6, also its /48 network with `wpcap_rate_limit_ipv6_network_factor`
	 * (default 4) times the limit, so a client with a whole /48 cannot multiply its limit by rotating /64s.
	 *
	 * @param string $bucket Bucket name; the client key is appended.
	 */
	public static function allow_client( $bucket, $limit, $window = 60 ) {
		if ( ! self::allow( $bucket . '|' . self::client(), $limit, $window ) ) { return false; }
		if ( ! ClientIp::is_ipv6( self::client_ip() ) ) { return true; }
		$factor = max( 1, (int) apply_filters( 'wpcap_rate_limit_ipv6_network_factor', 4 ) );
		return self::allow( $bucket . '|net|' . self::client( 48 ), (int) $limit * $factor, $window );
	}

	/**
	 * Network of the client for rate limits: the IPv4 address, or the /64 prefix of an IPv6 address
	 * (one end-user network; rotating addresses inside it must not reset the limit).
	 *
	 * @param string|null $ip Address (default: client_ip()).
	 */
	public static function client_network( $ip = null ) {
		$ip = null === $ip ? self::client_ip() : (string) $ip;
		// N03: the same key as ClientIp::limit_key() with a fixed /64 (IPv4-mapped addresses as IPv4).
		$key = ClientIp::limit_key( $ip, 64 );
		return '' !== $key ? $key : $ip;
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
		'forwarded' => 'HTTP_FORWARDED',
	);

	/**
	 * Configured forwarding header (a key of PROXY_HEADERS): setting "trusted_proxy_header"; when that is
	 * the default, the setting "client_ip_header" (constant/env WPCAP_CLIENT_IP_HEADER, filter
	 * wpcap_client_ip_header: x-forwarded-for, forwarded, cf-connecting-ip) is honoured as well.
	 */
	public static function proxy_header() {
		$name = (string) Settings::get( 'trusted_proxy_header' );
		if ( '' === $name || 'x_forwarded_for' === $name ) {
			$alt = str_replace( '-', '_', strtolower( (string) apply_filters( 'wpcap_client_ip_header', Settings::secret( 'client_ip_header' ) ) ) );
			if ( isset( self::PROXY_HEADERS[ $alt ] ) ) { $name = $alt; }
		}
		return isset( self::PROXY_HEADERS[ $name ] ) ? $name : 'x_forwarded_for';
	}

	/**
	 * Trusted reverse proxies (IP addresses or CIDR ranges): setting "trusted_proxies", constant
	 * WPCAP_TRUSTED_PROXIES (comma separated) and filter wpcap_trusted_proxies. Empty by default:
	 * forwarding headers are then ignored, because any client can send them.
	 */
	public static function trusted_proxies() {
		$raw = (string) Settings::get( 'trusted_proxies' );
		if ( defined( 'WPCAP_TRUSTED_PROXIES' ) ) { $raw .= ',' . (string) constant( 'WPCAP_TRUSTED_PROXIES' ); }
		$env = getenv( 'WPCAP_TRUSTED_PROXIES' );
		if ( is_string( $env ) && '' !== $env ) { $raw .= ',' . $env; }
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ) );
		$list = (array) apply_filters( 'wpcap_trusted_proxies', array_values( $list ) );
		// N03: one validation for all callers – an address or a CIDR range with a possible prefix length.
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $list ) ), array( ClientIp::class, 'valid_range' ) ) );
	}

	private static function is_trusted( $ip, array $trusted ) {
		// IPv4-mapped peers (::ffff:10.0.0.7) match IPv4 ranges (N03, as ClientIp::in_ranges()).
		return ClientIp::in_ranges( (string) $ip, $trusted );
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
			$name = self::proxy_header();
			$header = self::PROXY_HEADERS[ $name ];
			$value = trim( (string) ( $server[ $header ] ?? '' ) );
			if ( '' !== $value ) {
				// RFC 7239 Forwarded: the for= values, same right-to-left rule as X-Forwarded-For.
				$chain = 'forwarded' === $name ? ClientIp::forwarded_for( $value ) : explode( ',', $value );
				$hops = array_reverse( array_map( 'trim', $chain ) );
				foreach ( $hops as $hop ) {
					$hop = ClientIp::normalize( $hop ); // [IPv6]:port and IPv4:port (N03).
					if ( '' === $hop ) { break; }
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
