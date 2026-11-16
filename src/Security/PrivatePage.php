<?php
/**
 * Response headers for pages reached through a secret link or meant for staff only (order portal,
 * ticket page, invoices, service portal, waitlist offer, consent page, web scanner).
 *
 * One call does both jobs: page caches (WP Super Cache, W3TC, LiteSpeed, hosting caches honour
 * DONOTCACHEPAGE and no-store) must not keep a copy that a second visitor would get, and the page
 * must not be framed (clickjacking of "accept/transfer/withdraw" buttons) or indexed by search
 * engines if a link leaks.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PrivatePage {
	/** @var string[] Header lines of the last send() call (header() is not observable on the CLI). */
	private static $last = array();

	/**
	 * Header lines of a private page.
	 *
	 * @param bool $strict_csp Full Content-Security-Policy (strict_csp()) instead of only frame-ancestors.
	 *                         Only for pages the plugin renders completely (no theme, no wp_head()).
	 * @return string[]
	 */
	public static function header_lines( $strict_csp = false ) {
		return array(
			'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private',
			'X-Frame-Options: DENY',
			'Content-Security-Policy: ' . ( $strict_csp ? self::strict_csp() : "frame-ancestors 'none'" ),
			'X-Robots-Tag: noindex, nofollow, noarchive',
			'Referrer-Policy: no-referrer',
			'X-Content-Type-Options: nosniff',
		);
	}

	/**
	 * Strict policy for standalone pages rendered entirely by the plugin (ticket page, invoice,
	 * attendee consent page): scripts only from files (no inline script, no event handlers, no eval),
	 * no plugins/objects, no framing, no <base> rewriting, forms only to the own origin.
	 * style-src keeps 'unsafe-inline': these pages ship one small inline <style> block and brand logos
	 * may carry style attributes; inline CSS cannot execute code. Script/image origins of the plugin
	 * files and uploads are added when they are served from another host (CDN offloading).
	 */
	public static function strict_csp() {
		$script = array( "'self'" );
		$img = array( "'self'", 'data:' );
		$plugin = self::origin( function_exists( 'plugins_url' ) && defined( 'WPCAP_FILE' ) ? plugins_url( 'assets/', WPCAP_FILE ) : '' );
		if ( '' !== $plugin ) { $script[] = $plugin; $img[] = $plugin; }
		$uploads = function_exists( 'wp_upload_dir' ) ? (array) wp_upload_dir( null, false ) : array();
		$upload_origin = self::origin( (string) ( $uploads['baseurl'] ?? '' ) );
		if ( '' !== $upload_origin ) { $img[] = $upload_origin; }
		$policy = "default-src 'self'; script-src " . implode( ' ', array_unique( $script ) ) . "; style-src 'self' 'unsafe-inline'; img-src " . implode( ' ', array_unique( $img ) ) . "; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
		// E.g. a brand logo from a foreign host: add its origin to img-src. Line breaks are stripped (header injection).
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'wpcap_strict_csp', $policy ) : $policy;
		$filtered = is_string( $filtered ) ? trim( str_replace( array( "\r", "\n" ), ' ', $filtered ) ) : '';
		return '' !== $filtered ? $filtered : $policy;
	}

	/** URL parts (wp_parse_url when available). */
	private static function parse( $url ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return is_array( $parts ) ? $parts : array();
	}

	/** scheme://host[:port] of $url when it differs from the site's own origin, else ''. */
	private static function origin( $url ) {
		$parts = self::parse( (string) $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true ) ) { return ''; }
		$origin = strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$home = function_exists( 'home_url' ) ? self::parse( home_url( '/' ) ) : array();
		$own = is_array( $home ) && ! empty( $home['host'] ) ? strtolower( ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] ) . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' ) : '';
		return $origin === $own ? '' : $origin;
	}

	/**
	 * Marks the request as not cacheable and sends the private-page headers (before any output).
	 *
	 * @param bool $strict_csp Send the strict Content-Security-Policy (see strict_csp()); only for pages
	 *                         the plugin renders completely, never for theme pages with a shortcode.
	 */
	public static function send( $strict_csp = false ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared constant read by page-cache plugins.
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		self::$last = self::header_lines( (bool) $strict_csp );
		if ( headers_sent() ) { return; }
		foreach ( self::$last as $line ) {
			header( $line );
		}
	}

	/**
	 * Header lines of the last send() call (tests).
	 *
	 * @return string[]
	 */
	public static function last_sent() {
		return self::$last;
	}

	/** Forgets the last send() call (tests). */
	public static function reset() {
		self::$last = array();
	}
}
