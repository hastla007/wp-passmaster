<?php
/**
 * Exchanges an access token from a mail link (?wpcap_access=…, ?wpcap_ticket=…) for a short-lived
 * cookie, so the token does not stay in the address bar, the browser history, bookmarks or the
 * access logs of later requests (SEC-7 / PRIV-14).
 *
 * Flow on a private page (order portal, invoice, invoice PDF, ticket page):
 * 1. GET with a valid token in the URL: the page calls exchange(). It sets an HttpOnly,
 *    SameSite=Lax cookie (Secure on HTTPS, path = home path, name per order/ticket) and answers
 *    303 with the same URL without the token plus the marker MARKER=1.
 * 2. Request without token: resolve() reads the token from the cookie. The cookie value is the
 *    token sealed with Vault (XSalsa20-Poly1305, own context, bound to scope, reference and expiry),
 *    so it cannot be forged or moved to another order; the server still stores only the token hash.
 * 3. Cookies blocked: the request after the redirect carries the marker but no cookie. resolve()
 *    then remembers for FLAG_TTL that cookies do not work for this order/ticket (transient, no
 *    secret) and the page asks to open the mail link again. That second visit renders directly with
 *    the token in the URL (no further redirect, no loop).
 *
 * Without libsodium or key (Vault cannot seal) the exchange is skipped and the token URL keeps
 * working as before. Only GET/HEAD requests are exchanged (POSTs keep their body).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AccessCookie {
	/** Lifetime of the cookie in seconds (short-lived; the mail link works again afterwards). */
	const TTL = 7200;

	/** How long a "cookies blocked" note for one order/ticket is kept (seconds). */
	const FLAG_TTL = 86400;

	/** Query argument added to the clean URL after the exchange. */
	const MARKER = 'wpcap_ck';

	/** Vault context of the sealed cookie value. */
	const CONTEXT = 'access-cookie';

	/** @var array Last decision (tests; header() and exit are not observable on the CLI). */
	private static $last = array();

	/** Cookie name for one order (scope order, ref public id) or ticket (scope ticket, ref ticket_ref()). */
	public static function name( $scope, $ref ) {
		return 'wpcap_ax_' . substr( RateLimit::hash( 'access-cookie|' . $scope . '|' . $ref ), 0, 20 );
	}

	/** Non-secret reference of a ticket token for the clean ticket URL (?wpcap_tref=…). */
	public static function ticket_ref( $token ) {
		return substr( RateLimit::hash( 'ticket-ref|' . (string) $token ), 0, 32 );
	}

	/** Sealed cookie value, '' when Vault cannot encrypt (no libsodium or no key). */
	public static function seal( $scope, $ref, $token, $expires ) {
		if ( ! Vault::available() ) { return ''; }
		$value = Vault::encrypt( (string) wp_json_encode( array( 's' => (string) $scope, 'r' => (string) $ref, 't' => (string) $token, 'e' => (int) $expires ) ), self::CONTEXT );
		return Vault::is_encrypted( $value ) ? $value : '';
	}

	/** Token from a sealed value when scope, reference and expiry match, else ''. */
	public static function open( $scope, $ref, $value, $now = null ) {
		if ( ! is_string( $value ) || ! Vault::is_encrypted( $value ) ) { return ''; }
		$plain = Vault::decrypt( $value, self::CONTEXT );
		$data = is_string( $plain ) ? json_decode( $plain, true ) : null;
		if ( ! is_array( $data ) || (string) ( $data['s'] ?? '' ) !== (string) $scope || ! hash_equals( (string) $ref, (string) ( $data['r'] ?? '' ) ) ) { return ''; }
		if ( (int) ( $data['e'] ?? 0 ) <= ( null === $now ? time() : (int) $now ) ) { return ''; }
		$token = (string) ( $data['t'] ?? '' );
		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
	}

	/** Whether cookies were found blocked for this order/ticket recently (then the token URL renders directly). */
	public static function cookies_blocked( $scope, $ref ) {
		return (bool) get_transient( self::flag_key( $scope, $ref ) );
	}

	private static function flag_key( $scope, $ref ) {
		return 'wpcap_axblk_' . substr( RateLimit::hash( 'access-cookie-blocked|' . $scope . '|' . $ref ), 0, 32 );
	}

	private static function path() {
		$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : parse_url( home_url( '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback before WordPress is loaded.
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	private static function is_get( $server = null ) {
		$method = strtoupper( (string) ( ( null === $server ? $_SERVER : $server )['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against fixed values only.
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * What a page does with a valid token from the URL: redirect (set cookie, 303 to $clean_url)
	 * or render directly (cookies blocked earlier, POST request, Vault unavailable).
	 *
	 * @return array{action:string,url?:string,cookie?:array}
	 */
	public static function plan( $scope, $ref, $token, $clean_url, $server = null ) {
		if ( ! self::is_get( $server ) || self::cookies_blocked( $scope, $ref ) ) { return array( 'action' => 'render' ); }
		$expires = time() + self::TTL;
		$value = self::seal( $scope, $ref, $token, $expires );
		if ( '' === $value ) { return array( 'action' => 'render' ); }
		return array(
			'action' => 'redirect',
			'url'    => add_query_arg( self::MARKER, '1', $clean_url ),
			'cookie' => array(
				'name'     => self::name( $scope, $ref ),
				'value'    => $value,
				'expires'  => $expires,
				'path'     => self::path(),
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
				'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			),
		);
	}

	/**
	 * Exchanges a verified URL token for the cookie and ends the request with a 303 to $clean_url.
	 * Returns (page renders with the token) when the exchange is not possible.
	 */
	public static function exchange( $scope, $ref, $token, $clean_url ) {
		$plan = self::plan( $scope, $ref, $token, $clean_url );
		self::$last = $plan;
		if ( 'redirect' !== $plan['action'] || headers_sent() ) { return; }
		PrivatePage::send(); // The redirect (with Set-Cookie) must not be cached either.
		$cookie = $plan['cookie'];
		setcookie( $cookie['name'], $cookie['value'], array_intersect_key( $cookie, array_flip( array( 'expires', 'path', 'domain', 'secure', 'httponly', 'samesite' ) ) ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- HttpOnly access cookie, see class doc.
		wp_safe_redirect( $plan['url'], 303 );
		exit;
	}

	/**
	 * Token of a request without token in the URL: from the cookie of this order/ticket, '' when
	 * there is none. If the redirect marker is present but the cookie is missing, cookies are
	 * blocked: noted for FLAG_TTL so the next visit of the mail link renders without exchange.
	 */
	public static function resolve( $scope, $ref, $get = null, $cookies = null ) {
		$cookies = null === $cookies ? $_COOKIE : (array) $cookies;
		$raw = $cookies[ self::name( $scope, $ref ) ] ?? '';
		$token = self::open( $scope, $ref, is_string( $raw ) ? wp_unslash( $raw ) : '' );
		if ( '' !== $token ) {
			self::$last = array( 'action' => 'cookie' );
			return $token;
		}
		if ( self::marker_present( $get ) ) {
			set_transient( self::flag_key( $scope, $ref ), 1, self::FLAG_TTL );
			self::$last = array( 'action' => 'blocked' );
		}
		return '';
	}

	/** Whether the request is the one right after an exchange redirect. */
	public static function marker_present( $get = null ) {
		$get = null === $get ? $_GET : (array) $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only marker.
		return ! empty( $get[ self::MARKER ] );
	}

	/** Page shown when the cookie did not come back (cookies blocked); ends the request. */
	public static function blocked_page() {
		PrivatePage::send();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::blocked_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in blocked_html().
		exit;
	}

	public static function blocked_html() {
		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Bitte Link erneut öffnen', 'pass-captain' ) . '</title></head><body><main><h1>' . esc_html__( 'Bitte Link erneut öffnen', 'pass-captain' ) . '</h1><p>' . esc_html__( 'Dein Browser hat das kurzlebige Sitzungs-Cookie für diese Seite nicht gespeichert. Bitte öffne den Link aus deiner E-Mail noch einmal – er funktioniert dann auch ohne Cookie.', 'pass-captain' ) . '</p></main></body></html>';
	}

	/** Whether the current request was authorised by the access cookie (links on the page can omit the token). */
	public static function via_cookie() {
		return 'cookie' === ( self::$last['action'] ?? '' );
	}

	/** Last decision: redirect, render, cookie or blocked (tests). */
	public static function last() {
		return self::$last;
	}

	public static function reset() {
		self::$last = array();
	}
}
