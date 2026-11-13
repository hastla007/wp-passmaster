<?php
/**
 * Central guard for every outbound HTTP call of the plugin (A202): webhooks, fiscal providers, SMS
 * gateways, Wallet (Google, APNs) and payment providers.
 *
 * - HTTPS only. Plain HTTP or private targets only for exact origins (scheme://host:port) that the
 *   caller passes as a fixed provider exception (efsta EFR on 127.0.0.1:5618) or that the site adds
 *   with the filter `wpcap_outbound_http_exceptions`.
 * - Provider calls name their fixed hosts (`hosts`); any other host is refused.
 * - The host is resolved once (A and AAAA); every address must be public (no private, loopback,
 *   link-local, CGNAT, multicast, reserved, documentation or cloud metadata ranges, also inside
 *   IPv4-mapped/NAT64/6to4 IPv6 addresses). The connection is pinned to the checked address via
 *   CURLOPT_RESOLVE (action `http_api_curl`), so a second DNS answer (rebinding) is never used.
 *   Without cURL the request is refused (filter `wpcap_outbound_allow_unpinned`).
 * - No automatic redirects; a caller may allow a few, each target is validated again.
 * - Timeouts (default 10 s, at most 30 s; connect timeout at most 5 s) and a response size limit
 *   (default 1 MB).
 * - Error messages are redacted: Authorization/API key header values, URL credentials and secret
 *   query parameters never appear in errors, logs or the action `wpcap_outbound_http_blocked`.
 *
 * DNS can be replaced (tests, split-horizon setups) with the filter `wpcap_outbound_resolve`.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OutboundHttp {
	const DEFAULT_TIMEOUT = 10;
	const MAX_TIMEOUT = 30;
	const CONNECT_TIMEOUT = 5;
	const DEFAULT_MAX_BYTES = 1048576;
	const MAX_REDIRECTS = 3;
	/** Request argument carrying the CURLOPT_RESOLVE entries of a checked request. */
	const PIN_ARG = 'wpcap_outbound_pin';

	/** Host suffixes that never leave the local network. */
	const LOCAL_SUFFIXES = array( '.localhost', '.local', '.internal', '.localdomain', '.home.arpa' );

	/** Non-public IPv4 ranges (RFC 6890 special purpose, metadata endpoints). */
	const BLOCKED_V4 = array(
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
		'192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
		'203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '168.63.129.16/32',
	);
	/** Non-public IPv6 ranges; embedded IPv4 (mapped, NAT64, 6to4) is checked separately. */
	const BLOCKED_V6 = array(
		'::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
	);

	private static $hooked = false;
	/** cURL options applied to the last pinned request (diagnostics, tests). */
	private static $last_curl = array();

	/**
	 * Performs a guarded request.
	 *
	 * @param string $url    Target URL.
	 * @param array  $args   wp_remote_request() arguments (method, headers, body, timeout).
	 * @param array  $policy context (label), hosts (fixed provider hosts), exceptions (allowed
	 *                       "scheme://host:port" origins), max_bytes, redirects (0–3).
	 * @return array|\WP_Error Response array or error (codes wpcap_http_*; message redacted).
	 */
	public static function request( $url, array $args = array(), array $policy = array() ) {
		self::hook();
		$secrets = self::secret_values( (array) ( $args['headers'] ?? array() ) );
		$redirects = max( 0, min( self::MAX_REDIRECTS, (int) ( $policy['redirects'] ?? 0 ) ) );
		$max_bytes = max( 1024, (int) ( $policy['max_bytes'] ?? self::DEFAULT_MAX_BYTES ) );
		for ( $hop = 0; ; $hop++ ) {
			$target = self::check( $url, $policy );
			if ( is_wp_error( $target ) ) { return $target; }
			if ( $target['pin'] && ! self::can_pin() && ! apply_filters( 'wpcap_outbound_allow_unpinned', false, (string) ( $policy['context'] ?? '' ) ) ) {
				return self::refuse( 'wpcap_http_unpinned', __( 'Ausgehende Anfrage abgelehnt: Ohne cURL kann die geprüfte Zieladresse nicht festgelegt werden.', 'pass-captain' ), $url, $policy );
			}
			$send = self::harden( $args, $target, $max_bytes );
			$response = wp_remote_request( $url, $send );
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'wpcap_http_transport', self::redact( $response->get_error_message(), $secrets ), array( 'context' => (string) ( $policy['context'] ?? '' ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( $hop >= $redirects || '' === $location ) {
					/* translators: %d: HTTP status code */
					return new \WP_Error( 'wpcap_http_redirect', sprintf( __( 'Weiterleitung abgelehnt (HTTP %d).', 'pass-captain' ), $code ), array( 'status' => $code ) );
				}
				$next = self::absolute( $location, $url );
				if ( self::origin( $next ) !== self::origin( $url ) ) { $args['headers'] = self::strip_sensitive( (array) ( $args['headers'] ?? array() ) ); }
				if ( 303 === $code || ( in_array( $code, array( 301, 302 ), true ) && 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) ) {
					$args['method'] = 'GET';
					unset( $args['body'] );
				}
				$url = $next;
				continue;
			}
			if ( strlen( (string) wp_remote_retrieve_body( $response ) ) > $max_bytes ) {
				/* translators: %d: byte limit */
				return new \WP_Error( 'wpcap_http_too_large', sprintf( __( 'Die Antwort ist zu groß (mehr als %d Bytes).', 'pass-captain' ), $max_bytes ), array( 'status' => $code ) );
			}
			return $response;
		}
	}

	/**
	 * Validates a URL against the policy and resolves its host once.
	 *
	 * @param array $policy See request(); resolve => false skips DNS (form validation).
	 * @return array|\WP_Error ['scheme','host','port','ips','pin' (CURLOPT_RESOLVE entries),'exception'].
	 */
	public static function check( $url, array $policy = array() ) {
		$url = trim( (string) $url );
		$parts = preg_match( '~^[a-z][a-z0-9+.-]*://~i', $url ) ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || preg_match( '/[\s\\\\]/', $url ) ) {
			return self::refuse( 'wpcap_http_url_invalid', __( 'Ausgehende Anfrage abgelehnt: ungültige Adresse.', 'pass-captain' ), $url, $policy );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host = rtrim( strtolower( trim( (string) $parts['host'], '[]' ) ), '.' );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'http' === $scheme ? 80 : 443 );
		$origin = $scheme . '://' . ( false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . $port;
		if ( in_array( $origin, self::exceptions( $policy ), true ) ) {
			// Fixed provider exception: exact origin, no further checks (e.g. local efsta EFR).
			return array( 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => array(), 'pin' => array(), 'exception' => true );
		}
		if ( 'https' !== $scheme ) {
			return self::refuse( 'wpcap_http_insecure', __( 'Ausgehende Anfrage abgelehnt: nur HTTPS ist erlaubt.', 'pass-captain' ), $url, $policy );
		}
		$hosts = array_map( 'strtolower', (array) ( $policy['hosts'] ?? array() ) );
		if ( $hosts && ! in_array( $host, $hosts, true ) ) {
			return self::refuse( 'wpcap_http_host_not_allowed', __( 'Ausgehende Anfrage abgelehnt: Dieser Host ist für den Anbieter nicht freigegeben.', 'pass-captain' ), $url, $policy );
		}
		if ( 'localhost' === $host || self::ends_with_any( $host, self::LOCAL_SUFFIXES ) || ( false === strpos( $host, '.' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) ) {
			return self::refuse( 'wpcap_http_host_blocked', __( 'Ausgehende Anfrage abgelehnt: Das Ziel ist eine lokale, private oder reservierte Adresse.', 'pass-captain' ), $url, $policy );
		}
		$literal = filter_var( $host, FILTER_VALIDATE_IP );
		if ( ! $literal && ( preg_match( '/^[0-9.]+$/', $host ) || preg_match( '/^0x[0-9a-f]+$/i', $host ) || preg_match( '/(^|\.)0x[0-9a-f]*(\.|$)/i', $host ) ) ) {
			// Non-canonical IP spellings (2130706433, 0x7f.1, 127.1) are never sent.
			return self::refuse( 'wpcap_http_host_blocked', __( 'Ausgehende Anfrage abgelehnt: Das Ziel ist eine lokale, private oder reservierte Adresse.', 'pass-captain' ), $url, $policy );
		}
		if ( false === ( $policy['resolve'] ?? true ) ) {
			if ( $literal && ! self::is_public_ip( $host ) ) {
				return self::refuse( 'wpcap_http_host_blocked', __( 'Ausgehende Anfrage abgelehnt: Das Ziel ist eine lokale, private oder reservierte Adresse.', 'pass-captain' ), $url, $policy );
			}
			return array( 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $literal ? array( $host ) : array(), 'pin' => array(), 'exception' => false );
		}
		$ips = $literal ? array( $host ) : self::resolve( $host );
		if ( ! $ips ) {
			return self::refuse( 'wpcap_http_dns', __( 'Ausgehende Anfrage abgelehnt: Der Host konnte nicht aufgelöst werden.', 'pass-captain' ), $url, $policy );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return self::refuse( 'wpcap_http_host_blocked', __( 'Ausgehende Anfrage abgelehnt: Das Ziel ist eine lokale, private oder reservierte Adresse.', 'pass-captain' ), $url, $policy );
			}
		}
		$pin_ip = $ips[0];
		foreach ( $ips as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { $pin_ip = $ip; break; }
		}
		$pin = $literal ? array() : array( $host . ':' . $port . ':' . ( false !== strpos( $pin_ip, ':' ) ? '[' . $pin_ip . ']' : $pin_ip ) );
		return array( 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips, 'pin' => $pin, 'exception' => false );
	}

	/** Whether an IP address is publicly routable (not private, loopback, link-local, metadata, …). */
	public static function is_public_ip( $ip ) {
		$ip = trim( (string) $ip, '[]' );
		$bin = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $bin ) { return false; }
		if ( 4 === strlen( $bin ) ) { return ! self::in_ranges( $bin, self::BLOCKED_V4 ); }
		// IPv4-mapped/compatible (::ffff:a.b.c.d, ::a.b.c.d), NAT64 (64:ff9b::/96, 64:ff9b:1::/48) and 6to4 (2002::/16).
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) || str_repeat( "\0", 12 ) === substr( $bin, 0, 12 ) ) {
			return self::is_public_ip( inet_ntop( substr( $bin, 12 ) ) );
		}
		if ( self::in_ranges( $bin, array( '64:ff9b::/96' ) ) ) { return self::is_public_ip( inet_ntop( substr( $bin, 12 ) ) ); }
		if ( self::in_ranges( $bin, array( '64:ff9b:1::/48' ) ) ) { return false; }
		if ( self::in_ranges( $bin, array( '2002::/16' ) ) ) { return self::is_public_ip( inet_ntop( substr( $bin, 2, 4 ) ) ); }
		return ! self::in_ranges( $bin, self::BLOCKED_V6 );
	}

	/**
	 * Removes secrets from a message: values of sensitive headers, Bearer/Basic credentials, URL
	 * user info and secret query parameters.
	 *
	 * @param string   $text    Message.
	 * @param string[] $secrets Additional literal values to mask.
	 */
	public static function redact( $text, array $secrets = array() ) {
		$text = (string) $text;
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) >= 4 ) { $text = str_replace( $secret, '[redacted]', $text ); }
		}
		$text = (string) preg_replace( '/\b(Bearer|Basic|Token|Bot)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $text );
		$text = (string) preg_replace( '/((?:proxy-)?authorization|x-api-key|api-key|x-auth-token|cookie)(\s*[:=]\s*)[^\s,;]+(?:\s+[^\s,;]+)?/i', '$1$2[redacted]', $text );
		$text = (string) preg_replace( '~(://)[^/@\s:]+(?::[^/@\s]*)?@~', '$1[redacted]@', $text );
		$text = (string) preg_replace( '/([?&](?:[a-z0-9_.-]*(?:key|token|secret|password|passwd|pwd|signature|sig|auth|credential)[a-z0-9_.-]*))=[^&#\s]*/i', '$1=[redacted]', $text );
		return $text;
	}

	/** Headers with the values of sensitive headers replaced (for logs). */
	public static function redact_headers( array $headers ) {
		$out = array();
		foreach ( $headers as $name => $value ) { $out[ $name ] = self::is_sensitive_header( (string) $name ) ? '[redacted]' : $value; }
		return $out;
	}

	/**
	 * Action `http_api_curl`: pins the connection of a checked request to the validated address,
	 * disables cURL's own redirects, limits protocols and the connect timeout.
	 */
	public static function apply_curl_options( $handle, $args = array(), $url = '' ) {
		$options = self::curl_options( (array) $args );
		if ( ! $options ) { return; }
		self::$last_curl = $options;
		if ( function_exists( 'curl_setopt_array' ) && ( is_resource( $handle ) || $handle instanceof \CurlHandle ) ) {
			curl_setopt_array( $handle, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- pinning needs the handle WordPress passes to http_api_curl.
		}
	}

	/** cURL options for request arguments built by request() (empty for other requests). */
	public static function curl_options( array $args ) {
		if ( empty( $args[ self::PIN_ARG ] ) || ! defined( 'CURLOPT_RESOLVE' ) ) { return array(); }
		$options = array(
			CURLOPT_RESOLVE => array_values( (array) $args[ self::PIN_ARG ] ),
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min( self::CONNECT_TIMEOUT, max( 1, (int) ( $args['timeout'] ?? self::DEFAULT_TIMEOUT ) ) ),
		);
		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) { $options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS; }
		return $options;
	}

	/** cURL options of the last pinned request (diagnostics). */
	public static function last_curl_options() {
		return self::$last_curl;
	}

	/* ------------------------------------------------------------------ internals */

	/** Whether WordPress will use the cURL transport (the only one that honours the pin). */
	private static function can_pin() {
		return (bool) apply_filters( 'wpcap_outbound_can_pin', function_exists( 'curl_init' ) && function_exists( 'curl_exec' ) && defined( 'CURLOPT_RESOLVE' ) );
	}

	private static function hook() {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) { return; }
		self::$hooked = true;
		add_action( 'http_api_curl', array( __CLASS__, 'apply_curl_options' ), 99, 3 );
	}

	/** Request arguments with the guard's limits; pinned when the transport supports it. */
	private static function harden( array $args, array $target, $max_bytes ) {
		$args['method'] = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$args['timeout'] = max( 1, min( self::MAX_TIMEOUT, (int) ( $args['timeout'] ?? self::DEFAULT_TIMEOUT ) ) );
		$args['redirection'] = 0;
		$args['limit_response_size'] = $max_bytes + 1;
		$args['sslverify'] = true;
		$args['stream'] = false;
		unset( $args['filename'] );
		$args['reject_unsafe_urls'] = ! $target['exception'];
		if ( $target['pin'] ) { $args[ self::PIN_ARG ] = $target['pin']; } else { unset( $args[ self::PIN_ARG ] ); }
		return $args;
	}

	/** Allowed exception origins: the caller's fixed provider origins plus the site filter. */
	private static function exceptions( array $policy ) {
		$list = (array) apply_filters( 'wpcap_outbound_http_exceptions', array(), (string) ( $policy['context'] ?? '' ) );
		$list = array_merge( (array) ( $policy['exceptions'] ?? array() ), $list );
		$out = array();
		foreach ( $list as $origin ) {
			$p = wp_parse_url( (string) $origin );
			if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) ) { continue; }
			$scheme = strtolower( (string) $p['scheme'] );
			$host = strtolower( trim( (string) $p['host'], '[]' ) );
			$port = isset( $p['port'] ) ? (int) $p['port'] : ( 'http' === $scheme ? 80 : 443 );
			$out[] = $scheme . '://' . ( false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . $port;
		}
		return $out;
	}

	/** A and AAAA addresses of a host (filter `wpcap_outbound_resolve` replaces DNS). */
	private static function resolve( $host ) {
		$filtered = apply_filters( 'wpcap_outbound_resolve', null, $host );
		if ( is_array( $filtered ) ) {
			$ips = $filtered;
		} else {
			$ips = function_exists( 'gethostbynamel' ) ? (array) gethostbynamel( $host ) : array();
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing AAAA record is not an error.
				foreach ( (array) $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) { $ips[] = $record['ipv6']; }
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $ips ), static function ( $ip ) { return false !== filter_var( trim( $ip, '[]' ), FILTER_VALIDATE_IP ); } ) ) );
	}

	private static function in_ranges( $bin, array $ranges ) {
		foreach ( $ranges as $range ) {
			list( $net, $bits ) = explode( '/', $range );
			$net = inet_pton( $net );
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) { continue; }
			$bits = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) { continue; }
			$rest = $bits % 8;
			if ( 0 === $rest ) { return true; }
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) { return true; }
		}
		return false;
	}

	private static function refuse( $code, $message, $url, array $policy ) {
		$context = (string) ( $policy['context'] ?? '' );
		/** A blocked outbound request: error code, redacted URL, caller context (for logs/monitoring). */
		do_action( 'wpcap_outbound_http_blocked', $code, self::redact( $url ), $context );
		return new \WP_Error( $code, $message, array( 'context' => $context ) );
	}

	private static function ends_with_any( $host, array $suffixes ) {
		foreach ( $suffixes as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) { return true; }
		}
		return false;
	}

	private static function is_sensitive_header( $name ) {
		return (bool) preg_match( '/authorization|cookie|key|token|secret|signature|password|credential/i', $name );
	}

	/** Values of sensitive headers (masked in error messages). */
	private static function secret_values( array $headers ) {
		$out = array();
		foreach ( $headers as $name => $value ) {
			if ( ! self::is_sensitive_header( (string) $name ) || ! is_scalar( $value ) ) { continue; }
			$value = (string) $value;
			$out[] = $value;
			if ( preg_match( '/^\S+\s+(.+)$/', $value, $m ) ) { $out[] = $m[1]; }
		}
		return $out;
	}

	private static function strip_sensitive( array $headers ) {
		foreach ( array_keys( $headers ) as $name ) {
			if ( self::is_sensitive_header( (string) $name ) ) { unset( $headers[ $name ] ); }
		}
		return $headers;
	}

	private static function origin( $url ) {
		$p = wp_parse_url( (string) $url );
		return is_array( $p ) ? strtolower( ( $p['scheme'] ?? '' ) . '://' . ( $p['host'] ?? '' ) . ':' . ( $p['port'] ?? '' ) ) : '';
	}

	private static function absolute( $location, $base ) {
		if ( preg_match( '~^[a-z][a-z0-9+.-]*://~i', $location ) ) { return $location; }
		$p = wp_parse_url( $base );
		$root = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( 0 === strpos( $location, '//' ) ) { return ( $p['scheme'] ?? 'https' ) . ':' . $location; }
		if ( 0 === strpos( $location, '/' ) ) { return $root . $location; }
		$path = (string) ( $p['path'] ?? '/' );
		return $root . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}
}
