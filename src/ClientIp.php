<?php
/**
 * Client address behind reverse proxies / CDNs.
 *
 * Forwarding headers are forgeable by anyone, so they are only honoured when the direct peer
 * (REMOTE_ADDR) is a configured trusted proxy (setting `trusted_proxies`: IPs or CIDR ranges, one per
 * line or comma separated; constant/env WPCAP_TRUSTED_PROXIES; filter `wpcap_trusted_proxies`). Without a trusted proxy the result is
 * always REMOTE_ADDR – headers are never trusted by default.
 *
 * Header (setting `client_ip_header`, constant/env WPCAP_CLIENT_IP_HEADER, filter `wpcap_client_ip_header`):
 * - `x-forwarded-for`: the chain is read from the right; trusted proxies are skipped, the right-most
 *   untrusted address is the client (left-hand entries can be set by the client and are ignored).
 * - `forwarded`: RFC 7239 `for=` values, same right-to-left rule.
 * - `cf-connecting-ip`: Cloudflare's single client address (REMOTE_ADDR must be a Cloudflare range
 *   listed in `trusted_proxies`).
 * A missing or malformed header falls back to REMOTE_ADDR.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ClientIp {
	const HEADERS = array( 'x-forwarded-for', 'forwarded', 'cf-connecting-ip' );

	/**
	 * Resolved client address (validated IP, or '' when REMOTE_ADDR is missing/invalid).
	 *
	 * @param array|null $server $_SERVER-like array (tests); default $_SERVER.
	 */
	public static function get( $server = null ) {
		// N03: one resolver. RateLimit::client_ip() honours the same settings (trusted_proxies with
		// constant/env, the header choice incl. cf-connecting-ip) plus the filter wpcap_client_ip.
		$server = is_array( $server ) ? $server : $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		if ( '' === self::normalize( (string) ( $server['REMOTE_ADDR'] ?? '' ) ) ) { return ''; }
		return self::normalize( RateLimit::client_ip( $server ) );
	}

	/**
	 * Key of an address for rate limits (A280): IPv4 (also IPv4-mapped IPv6) as is, IPv6 reduced to its
	 * network prefix (default /64, the smallest network a provider assigns; filter
	 * `wpcap_rate_limit_ipv6_prefix`, 32–128), e.g. "2001:db8:1:2::/64". A client rotating addresses
	 * inside its prefix stays one client. '' for an invalid address.
	 *
	 * @param string $ip     Address.
	 * @param int    $prefix Prefix length for IPv6 (0 = filtered default).
	 */
	public static function limit_key( $ip, $prefix = 0 ) {
		$ip = self::unmap( trim( (string) $ip ) );
		$bin = '' !== $ip ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) { return ''; }
		if ( 4 === strlen( $bin ) ) { return inet_ntop( $bin ); }
		$bits = $prefix > 0 ? (int) $prefix : (int) apply_filters( 'wpcap_rate_limit_ipv6_prefix', 64 );
		$bits = min( 128, max( 32, $bits ) );
		return inet_ntop( self::mask( $bin, $bits ) ) . '/' . $bits;
	}

	/** Whether the address is IPv6 (not IPv4-mapped). */
	public static function is_ipv6( $ip ) {
		$bin = @inet_pton( self::unmap( trim( (string) $ip ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false !== $bin && 16 === strlen( $bin );
	}

	/** Binary address with every bit after $bits cleared. */
	private static function mask( $bin, $bits ) {
		$out = '';
		for ( $i = 0, $n = strlen( $bin ); $i < $n; $i++ ) {
			$keep = max( 0, min( 8, $bits - 8 * $i ) );
			$out .= chr( ord( $bin[ $i ] ) & ( ( 0xff << ( 8 - $keep ) ) & 0xff ) );
		}
		return $out;
	}

	/** Trusted proxy entries (IP or CIDR), validated. */
	public static function trusted_proxies() {
		// N03: the same list as RateLimit (setting, constant/env WPCAP_TRUSTED_PROXIES, filter).
		return RateLimit::trusted_proxies();
	}

	/** Whether $range is an IPv4/IPv6 address or CIDR range. */
	public static function valid_range( $range ) {
		$parts = explode( '/', (string) $range, 2 );
		$bin = '' !== $parts[0] ? @inet_pton( $parts[0] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) { return false; }
		if ( ! isset( $parts[1] ) ) { return true; }
		return ctype_digit( $parts[1] ) && (int) $parts[1] <= 8 * strlen( $bin );
	}

	/** Whether $ip lies in one of $ranges (IPs or CIDR, IPv4 and IPv6; IPv4-mapped IPv6 is matched as IPv4). */
	public static function in_ranges( $ip, array $ranges ) {
		$bin = @inet_pton( self::unmap( (string) $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) { return false; }
		foreach ( $ranges as $range ) {
			$parts = explode( '/', (string) $range, 2 );
			$net = @inet_pton( self::unmap( $parts[0] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) { continue; }
			$bits = isset( $parts[1] ) ? (int) $parts[1] : 8 * strlen( $net );
			$bytes = intdiv( $bits, 8 );
			if ( $bytes > 0 && 0 !== substr_compare( $bin, $net, 0, $bytes ) ) { continue; }
			$rest = $bits % 8;
			if ( $rest ) {
				$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
				if ( ( ord( $bin[ $bytes ] ) & $mask ) !== ( ord( $net[ $bytes ] ) & $mask ) ) { continue; }
			}
			return true;
		}
		return false;
	}

	/** `for=` values of an RFC 7239 Forwarded header, in order (obfuscated/unknown values stay as given). */
	public static function forwarded_for( $header ) {
		$out = array();
		foreach ( explode( ',', (string) $header ) as $element ) {
			$found = '';
			foreach ( explode( ';', $element ) as $pair ) {
				$kv = explode( '=', trim( $pair ), 2 );
				if ( 2 === count( $kv ) && 'for' === strtolower( trim( $kv[0] ) ) ) {
					$found = trim( trim( $kv[1] ), '"' );
				}
			}
			$out[] = '' !== $found ? $found : 'unknown';
		}
		return '' === trim( (string) $header ) ? array() : $out;
	}

	/** Validated address without port/brackets, or ''. */
	public static function normalize( $value ) {
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $value, $m ) ) {
			$value = $m[1]; // [IPv6]:port
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $m ) ) {
			$value = $m[1]; // IPv4:port
		}
		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	/** ::ffff:1.2.3.4 → 1.2.3.4. */
	private static function unmap( $ip ) {
		return preg_match( '/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', (string) $ip, $m ) ? $m[1] : (string) $ip;
	}
}
