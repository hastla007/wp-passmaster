<?php
/**
 * Rate limits of the public checkout (Orders::create()).
 *
 * Keys use RateLimit::client_network() (IPv4 address or IPv6 /64), so rotating addresses inside one
 * IPv6 network do not reset a limit, and BuyerLimits::normalize_email() (no "+tag", no Gmail dots),
 * so e-mail aliases do not either.
 *
 * Counters per network and per network + e-mail (all counted atomically with RateLimit::hit(), so
 * parallel requests never pass a limit together):
 * - holds (`wpcap_checkout_ip_*`, `wpcap_checkout_*`): counted before the order is created and taken back
 *   with RateLimit::refund() when the checkout does not create an order (sold out, sales closed, invalid
 *   code, …, see refund()). A buyer who keeps clicking on a sold-out ticket type is therefore not locked out
 *   once tickets come back.
 * - unpaid holds per network (`wpcap_checkout_unpaid_*`, default 10, filter
 *   `wpcap_checkout_unpaid_per_network`): pending orders started within one hold period (at most one
 *   hour); taken back for free orders. One network cannot reserve large parts of a drop with orders it
 *   never pays.
 * - attempts (`wpcap_checkout_try_*`): every request, never taken back; generous
 *   (`wpcap_checkout_attempt_factor` × the hold limit, default 4×). Bounds hammering on a sold-out type.
 *
 * Behind a reverse proxy or CDN without configured trusted proxies (RateLimit::trusted_proxies()),
 * REMOTE_ADDR is the same few proxy addresses for every buyer; a per-address limit would then throttle
 * the whole site at a sales start. proxy_suspect() detects that situation: with a private/loopback
 * REMOTE_ADDR the per-network limits are suspended (only the network + e-mail limits apply); with a
 * forwarding header from a public address (which any client can also send) they are raised by
 * `wpcap_checkout_proxy_limit_factor` (default 10). The system status shows a warning in both cases.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Security\OutboundHttp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CheckoutThrottle {
	/** Option: last checkout that looked like an unconfigured proxy (array kind, time; not autoloaded). */
	const SEEN_OPTION = 'wpcap_proxy_suspect_seen';

	/** Holds per network + e-mail and hour (default of the setting rate_checkout_email_per_hour, SET-PC-38). */
	const EMAIL_LIMIT = 8;

	/** Ranges of a reverse proxy / load balancer in front of the site (private, loopback, CGNAT, link-local). */
	const PROXY_RANGES = array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10' );

	/**
	 * Proxy situation of the request when no trusted proxy is configured and the client address was not
	 * resolved otherwise (filter `wpcap_client_ip`): 'private' (REMOTE_ADDR is a private or loopback
	 * address), 'header' (a forwarding header such as X-Forwarded-For or CF-Connecting-IP is present), or ''
	 * (filter `wpcap_checkout_proxy_suspect`).
	 *
	 * @param array|null $server $_SERVER (tests pass their own).
	 */
	public static function proxy_suspect( $server = null ) {
		$server = is_array( $server ) ? $server : $_SERVER;
		$kind = '';
		$remote = trim( sanitize_text_field( wp_unslash( (string) ( $server['REMOTE_ADDR'] ?? '' ) ) ) );
		if ( ! RateLimit::trusted_proxies() && RateLimit::client_ip( $server ) === $remote ) {
			if ( self::is_proxy_address( $remote ) ) {
				$kind = 'private';
			} else {
				foreach ( RateLimit::PROXY_HEADERS as $header ) {
					if ( '' !== trim( (string) ( $server[ $header ] ?? '' ) ) ) { $kind = 'header'; break; }
				}
			}
		}
		$kind = (string) apply_filters( 'wpcap_checkout_proxy_suspect', $kind, $server );
		return in_array( $kind, array( 'private', 'header' ), true ) ? $kind : '';
	}

	/** Whether $ip lies in a private, loopback, CGNAT or link-local range. */
	public static function is_proxy_address( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { return false; }
		foreach ( self::PROXY_RANGES as $range ) {
			if ( OutboundHttp::ip_in_cidr( $ip, $range ) ) { return true; }
		}
		return false;
	}

	/**
	 * Checks the limits before an order is created and counts the attempt and the hold.
	 *
	 * @param string $email        Buyer e-mail.
	 * @param int    $hold_minutes Hold period of a pending order.
	 * @return array|\WP_Error Counted keys for record()/refund(), or a 429 error (nothing stays counted but the attempt).
	 */
	public static function check( $email, $hold_minutes = 30 ) {
		$network = RateLimit::client_network();
		$buyer = BuyerLimits::normalize_email( $email );
		$suspect = self::proxy_suspect();
		if ( '' !== $suspect ) { self::remember( $suspect ); }
		$factor = 'header' === $suspect ? max( 1, (int) apply_filters( 'wpcap_checkout_proxy_limit_factor', 10 ) ) : 1;
		$ip_limit = self::times( max( 1, (int) apply_filters( 'wpcap_checkout_ip_limit', RateLimit::configured( 'rate_checkout_ip_per_hour' ) ) ), $factor );
		$attempt_factor = max( 1, (int) apply_filters( 'wpcap_checkout_attempt_factor', 4 ) );
		$unpaid_limit = self::times( max( 1, (int) apply_filters( 'wpcap_checkout_unpaid_per_network', RateLimit::configured( 'rate_checkout_unpaid_per_network' ) ) ), $factor );
		$email_limit = RateLimit::configured( 'rate_checkout_email_per_hour' ); // SET-PC-38 (default EMAIL_LIMIT)
		$per_network = 'private' !== $suspect;
		$net_hash = Orders::rate_hash( $network );
		$buyer_hash = Orders::rate_hash( $network . '|' . $buyer );
		// Attempts (never taken back).
		$attempts_ok = ( ! $per_network || RateLimit::hit( 'wpcap_checkout_try_ip_' . $net_hash, self::times( $ip_limit, $attempt_factor ), HOUR_IN_SECONDS ) )
			&& RateLimit::hit( 'wpcap_checkout_try_' . $buyer_hash, self::times( $email_limit, $attempt_factor ), HOUR_IN_SECONDS );
		$counters = array();
		if ( $per_network ) {
			$counters['hold'] = array( 'wpcap_checkout_ip_' . $net_hash, $ip_limit, HOUR_IN_SECONDS );
			$client_ip = RateLimit::client_ip();
			if ( \PassCaptain\ClientIp::is_ipv6( $client_ip ) ) {
				// A280: the /48 around the client's /64 shares `wpcap_rate_limit_ipv6_network_factor` (4) times the limit,
				// so rotating /64s of one allocation cannot multiply it.
				$net_factor = max( 1, (int) apply_filters( 'wpcap_rate_limit_ipv6_network_factor', 4 ) );
				$counters['hold48'] = array( 'wpcap_checkout_ip48_' . Orders::rate_hash( \PassCaptain\ClientIp::limit_key( $client_ip, 48 ) ), self::times( $ip_limit, $net_factor ), HOUR_IN_SECONDS );
			}
			$counters['unpaid'] = array( 'wpcap_checkout_unpaid_' . $net_hash, $unpaid_limit, max( 1, min( 60, (int) $hold_minutes ) ) * MINUTE_IN_SECONDS );
		}
		$counters['email'] = array( 'wpcap_checkout_' . $buyer_hash, $email_limit, HOUR_IN_SECONDS );
		$counted = array();
		$refused = ! $attempts_ok;
		foreach ( $counters as $name => $counter ) {
			if ( $refused ) { break; }
			if ( RateLimit::hit( $counter[0], $counter[1], $counter[2] ) ) {
				$counted[ $name ] = $counter[0];
			} else {
				$refused = true;
			}
		}
		if ( $refused ) {
			self::refund( $counted );
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		return $counted;
	}

	/** $a * $b for positive limits, saturated at PHP_INT_MAX (a filter may return PHP_INT_MAX to switch a limit off). */
	private static function times( $a, $b ) {
		return $a > intdiv( PHP_INT_MAX, max( 1, $b ) ) ? PHP_INT_MAX : $a * $b;
	}

	/**
	 * The order was created: free orders give the unpaid-hold slot back.
	 *
	 * @param array $counted Result of check().
	 * @param bool  $pending Whether the order holds tickets until payment.
	 */
	public static function record( array $counted, $pending ) {
		if ( ! $pending && isset( $counted['unpaid'] ) ) { RateLimit::refund( $counted['unpaid'] ); }
	}

	/**
	 * The checkout did not create an order (sold out, closed, invalid input, busy): the holds are taken back,
	 * only the attempt stays counted.
	 *
	 * @param array|null $counted Result of check().
	 */
	public static function refund( $counted ) {
		foreach ( is_array( $counted ) ? $counted : array() as $key ) { RateLimit::refund( $key ); }
	}

	/** Notes a suspicious request for the system status (written at most once a day). */
	private static function remember( $kind ) {
		$seen = get_option( self::SEEN_OPTION );
		if ( is_array( $seen ) && ( $seen['kind'] ?? '' ) === $kind && (int) ( $seen['time'] ?? 0 ) > \PassCaptain\Clock::real() - DAY_IN_SECONDS ) { return; }
		update_option( self::SEEN_OPTION, array( 'kind' => $kind, 'time' => \PassCaptain\Clock::real() ), false );
	}

	/**
	 * System status warning while the site seems to run behind an unconfigured proxy (current request or
	 * a checkout within the last 7 days); null otherwise.
	 *
	 * @return array|null {severity, message}
	 */
	public static function status_issue() {
		if ( RateLimit::trusted_proxies() ) { return null; }
		$kind = self::proxy_suspect();
		if ( '' === $kind ) {
			$seen = get_option( self::SEEN_OPTION );
			$kind = is_array( $seen ) && (int) ( $seen['time'] ?? 0 ) > \PassCaptain\Clock::real() - 7 * DAY_IN_SECONDS ? (string) ( $seen['kind'] ?? '' ) : '';
		}
		if ( '' === $kind ) { return null; }
		return array(
			'severity' => 'warning',
			'message' => 'private' === $kind
				? __( 'Die Website läuft offenbar hinter einem Reverse Proxy oder Load Balancer (private Absenderadresse), aber unter „Einstellungen → Erweitert“ sind keine vertrauenswürdigen Proxys eingetragen. Alle Käufer erscheinen mit derselben Adresse; das Checkout-Limit je Adresse ist deshalb ausgesetzt (es gilt nur das Limit je Adresse und E-Mail). Bitte die Proxy-Adressen und den Header mit der Client-Adresse eintragen.', 'pass-captain' )
				: __( 'Anfragen enthalten einen Weiterleitungs-Header (z. B. X-Forwarded-For oder CF-Connecting-IP), aber unter „Einstellungen → Erweitert“ sind keine vertrauenswürdigen Proxys eingetragen. Läuft die Website hinter einem CDN oder Proxy, teilen sich viele Käufer wenige Adressen; das Checkout-Limit je Adresse ist deshalb vorübergehend angehoben. Bitte die Proxy-Adressen und den Header mit der Client-Adresse eintragen.', 'pass-captain' ),
		);
	}
}
