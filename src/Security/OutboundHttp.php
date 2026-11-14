<?php
/**
 * Hardened outbound HTTP for admin-configurable target URLs (webhooks, generic SMS gateway,
 * fiscal provider services).
 *
 * - https only; plain http only for explicitly allowed local services (allow_local, e.g. a local
 *   fiscal service on the same host when the setting "fiscal_allow_local_service" is enabled)
 * - no credentials in URLs
 * - the host is resolved and every address must be public: loopback, private (RFC 1918, ULA),
 *   link-local / cloud metadata (169.254.0.0/16, fe80::/10), CGNAT, multicast, reserved and
 *   documentation ranges are blocked (SSRF); IPv4-mapped/NAT64 IPv6 addresses are unwrapped
 * - no redirects (a 3xx response is returned as an error), bounded timeout and response size
 * - optional JSON validation; error messages never contain the URL query, headers or body
 *
 * DNS resolution is filterable (`wpcap_outbound_resolve`, tests use a fake resolver). The check
 * runs right before each request; wp_safe_remote_* re-validates the host on top. A DNS rebinding
 * attacker with a TTL of 0 could still race the two lookups – see docs/SECURITY.md.
 */
namespace PassCaptain\Security;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OutboundHttp {
	const MAX_BYTES = 1048576;
	const MAX_TIMEOUT = 15;

	/** Blocked IPv4 ranges (CIDR). */
	const BLOCKED_V4 = array(
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
		'192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
		'203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
	);

	/** Blocked IPv6 ranges (CIDR). */
	const BLOCKED_V6 = array( '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '100::/64', '2001::/32', '2002::/16' );

	/** Ranges a local service may use when allow_local is set (loopback and private networks). */
	const LOCAL_V4 = array( '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16' );
	const LOCAL_V6 = array( '::1/128', 'fc00::/7' );

	/** Host names that never leave the machine / cloud network. */
	const BLOCKED_HOST_SUFFIXES = array( 'localhost', '.localhost', '.local', '.internal', '.localdomain', '.home.arpa', '.lan' );

	public static function ip_in_cidr( $ip, $cidr ) {
		list( $net, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_bin = @inet_pton( (string) $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) { return false; }
		$bits = null === $bits ? strlen( $ip_bin ) * 8 : (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) { return false; }
		$rest = $bits % 8;
		if ( $rest ) {
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) { return false; }
		}
		return true;
	}

	/** IPv4 address embedded in IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible or NAT64 (64:ff9b::) IPv6. */
	private static function embedded_v4( $ip ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin || 16 !== strlen( $bin ) ) { return null; }
		$prefix = substr( $bin, 0, 12 );
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix || str_repeat( "\0", 12 ) === $prefix || "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) === $prefix ) {
			return inet_ntop( substr( $bin, 12 ) );
		}
		return null;
	}

	/** True when the address is a public unicast address. */
	public static function is_public_ip( $ip ) {
		$ip = trim( (string) $ip, '[] ' );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { return false; }
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$v4 = self::embedded_v4( $ip );
			if ( null !== $v4 ) { return self::is_public_ip( $v4 ); }
			foreach ( self::BLOCKED_V6 as $cidr ) { if ( self::ip_in_cidr( $ip, $cidr ) ) { return false; } }
			return true;
		}
		foreach ( self::BLOCKED_V4 as $cidr ) { if ( self::ip_in_cidr( $ip, $cidr ) ) { return false; } }
		return true;
	}

	private static function is_local_ip( $ip ) {
		$ip = trim( (string) $ip, '[] ' );
		$v4 = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : self::embedded_v4( $ip );
		foreach ( null !== $v4 ? self::LOCAL_V4 : self::LOCAL_V6 as $cidr ) {
			if ( self::ip_in_cidr( null !== $v4 ? $v4 : $ip, $cidr ) ) { return true; }
		}
		return false;
	}

	/** All addresses of a host (filter wpcap_outbound_resolve can supply them, e.g. in tests). */
	public static function resolve( $host ) {
		$host = strtolower( trim( (string) $host, '[] ' ) );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) { return array( $host ); }
		$ips = function_exists( 'apply_filters' ) ? apply_filters( 'wpcap_outbound_resolve', null, $host ) : null;
		if ( is_array( $ips ) ) { return array_values( array_filter( array_map( 'strval', $ips ) ) ); }
		$ips = array();
		$v4 = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
		if ( is_array( $v4 ) ) { $ips = $v4; }
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) { $ips[] = $record['ipv6']; }
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Validates a target URL.
	 *
	 * @param array $opts allow_local (bool): permit http and loopback/private addresses (local services only).
	 * @return true|\WP_Error
	 */
	public static function validate_url( $url, array $opts = array() ) {
		$allow_local = ! empty( $opts['allow_local'] );
		$url = trim( (string) $url );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( ! is_array( $parts ) || '' === $host || preg_match( '/[\s\x00-\x1f]/', $url ) ) {
			return self::error( 'wpcap_outbound_url_invalid', __( 'Die Ziel-URL ist ungültig.', 'pass-captain' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::error( 'wpcap_outbound_url_credentials', __( 'Zugangsdaten dürfen nicht in der URL stehen.', 'pass-captain' ) );
		}
		if ( 'https' !== $scheme && ! ( $allow_local && 'http' === $scheme ) ) {
			return self::error( 'wpcap_outbound_https_required', __( 'Die Ziel-URL muss HTTPS verwenden.', 'pass-captain' ) );
		}
		$host_is_ip = false !== filter_var( $host, FILTER_VALIDATE_IP );
		// Non-canonical IPv4 notations (0x7f.1, 2130706433, 0177.0.0.1) are resolved by some resolvers.
		if ( ! $host_is_ip && preg_match( '/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+)){0,3}\.?$/i', $host ) ) {
			return self::error( 'wpcap_outbound_url_invalid', __( 'Die Ziel-URL ist ungültig.', 'pass-captain' ) );
		}
		if ( ! $host_is_ip ) {
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*\.?$/', $host ) ) {
				return self::error( 'wpcap_outbound_url_invalid', __( 'Die Ziel-URL ist ungültig.', 'pass-captain' ) );
			}
			foreach ( self::BLOCKED_HOST_SUFFIXES as $suffix ) {
				$h = rtrim( $host, '.' );
				if ( ( '.' === $suffix[0] ? str_ends_with( $h, $suffix ) : $h === $suffix ) && ! $allow_local ) {
					return self::error( 'wpcap_outbound_private_target', __( 'Interne Ziele sind nicht erlaubt.', 'pass-captain' ) );
				}
			}
		}
		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return self::error( 'wpcap_outbound_unresolvable', __( 'Der Zielhost konnte nicht aufgelöst werden.', 'pass-captain' ) );
		}
		$local = false;
		foreach ( $ips as $ip ) {
			if ( self::is_public_ip( $ip ) ) { continue; }
			if ( $allow_local && self::is_local_ip( $ip ) ) { $local = true; continue; }
			return self::error( 'wpcap_outbound_private_target', __( 'Interne, private oder Metadaten-Adressen sind als Ziel nicht erlaubt.', 'pass-captain' ) );
		}
		if ( 'http' === $scheme && ! $local ) {
			// Plain http is only acceptable on the local network, never across the internet.
			return self::error( 'wpcap_outbound_https_required', __( 'Die Ziel-URL muss HTTPS verwenden.', 'pass-captain' ) );
		}
		return true;
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Performs a validated request.
	 *
	 * @param array $args WP HTTP args (method, headers, body, timeout).
	 * @param array $opts allow_local (bool), max_bytes (int), json (bool: body must be JSON, adds 'data').
	 * @return array|\WP_Error ['code' => int, 'body' => string, 'headers' => array, 'data' => array|null]
	 */
	public static function request( $url, array $args = array(), array $opts = array() ) {
		$valid = self::validate_url( $url, $opts );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$max = max( 1024, (int) ( $opts['max_bytes'] ?? self::MAX_BYTES ) );
		$args['redirection'] = 0;
		$args['timeout'] = max( 1, min( self::MAX_TIMEOUT, (int) ( $args['timeout'] ?? 10 ) ) );
		$args['limit_response_size'] = $max;
		$args['reject_unsafe_urls'] = empty( $opts['allow_local'] );
		$args['method'] = strtoupper( (string) ( $args['method'] ?? 'POST' ) );
		$response = empty( $opts['allow_local'] ) && function_exists( 'wp_safe_remote_request' ) ? wp_safe_remote_request( $url, $args ) : wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::error( 'wpcap_outbound_transport', __( 'Das Ziel ist nicht erreichbar.', 'pass-captain' ) . ' (' . Redact::text( $response->get_error_code() ) . ')', 502 );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 300 && $code < 400 ) {
			return new \WP_Error( 'wpcap_outbound_redirect', __( 'Weiterleitungen werden nicht verfolgt.', 'pass-captain' ), array( 'status' => 502, 'response_code' => $code ) );
		}
		if ( strlen( $body ) > $max ) {
			return new \WP_Error( 'wpcap_outbound_too_large', __( 'Die Antwort ist zu groß.', 'pass-captain' ), array( 'status' => 502, 'response_code' => $code ) );
		}
		$data = null;
		if ( ! empty( $opts['json'] ) && '' !== $body ) {
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				return new \WP_Error( 'wpcap_outbound_invalid_json', __( 'Die Antwort ist kein gültiges JSON.', 'pass-captain' ), array( 'status' => 502, 'response_code' => $code ) );
			}
		}
		return array( 'code' => $code, 'body' => $body, 'headers' => (array) ( is_array( $response ) ? ( $response['headers'] ?? array() ) : array() ), 'data' => $data );
	}
}
