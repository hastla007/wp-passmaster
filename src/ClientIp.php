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
		$server = is_array( $server ) ? $server : $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$remote = self::normalize( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		if ( '' === $remote ) { return ''; }
		$proxies = self::trusted_proxies();
		if ( ! $proxies || ! self::in_ranges( $remote, $proxies ) ) {
			return $remote;
		}
		$header = (string) apply_filters( 'wpcap_client_ip_header', Settings::secret( 'client_ip_header' ) );
		if ( 'cf-connecting-ip' === $header ) {
			$ip = self::normalize( (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) );
			return '' !== $ip ? $ip : $remote;
		}
		if ( 'forwarded' === $header ) {
			$chain = self::forwarded_for( (string) ( $server['HTTP_FORWARDED'] ?? '' ) );
		} else {
			$chain = array_map( 'trim', explode( ',', (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ) ) );
		}
		$chain = array_values( array_filter( $chain, static fn( $hop ) => '' !== $hop ) );
		// Right to left: the right-most hop was added by our own trusted proxy.
		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			$ip = self::normalize( $chain[ $i ] );
			if ( '' === $ip ) {
				return $remote; // Malformed chain: never guess.
			}
			if ( ! self::in_ranges( $ip, $proxies ) ) {
				return $ip;
			}
			$remote = $ip; // Another trusted proxy: keep walking.
		}
		return $remote;
	}

	/** Trusted proxy entries (IP or CIDR), validated. */
	public static function trusted_proxies() {
		$list = preg_split( '/[\s,;]+/', Settings::secret( 'trusted_proxies' ) );
		$list = (array) apply_filters( 'wpcap_trusted_proxies', array_values( array_filter( array_map( 'trim', (array) $list ) ) ) );
		return array_values( array_filter( array_map( 'strval', $list ), array( __CLASS__, 'valid_range' ) ) );
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
