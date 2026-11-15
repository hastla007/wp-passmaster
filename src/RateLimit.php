<?php
/** Fixed-window rate limiter on transients (object-cache aware). */
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
	 * Counts one hit on the transient counter $key; false once $limit hits were counted.
	 *
	 * With a persistent object cache the counter is increased atomically (wp_cache_add() +
	 * wp_cache_incr() in the "transient" group, where WordPress keeps transients in that case), so
	 * parallel requests cannot all read the same old value and pass together. Without one, transients
	 * live in the options table and the read-modify-write stays best effort (a burst of exactly
	 * simultaneous requests can exceed the limit by a few hits). Hits over the limit are counted in
	 * the atomic path; the window (TTL from the first hit) is not extended by them.
	 *
	 * @param string $key   Transient name (at most 172 characters).
	 * @param int    $limit Allowed hits per TTL.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function hit( $key, $limit, $ttl ) {
		$ttl = max( 1, (int) $ttl );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
			wp_cache_add( $key, 0, 'transient', $ttl );
			$count = wp_cache_incr( $key, 1, 'transient' );
			if ( false !== $count ) { return (int) $count <= (int) $limit; }
		}
		$count = (int) get_transient( $key );
		if ( $count >= (int) $limit ) { return false; }
		set_transient( $key, $count + 1, $ttl );
		return true;
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
