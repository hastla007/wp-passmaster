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
	 * @return string[]
	 */
	public static function header_lines() {
		return array(
			'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private',
			'X-Frame-Options: DENY',
			"Content-Security-Policy: frame-ancestors 'none'",
			'X-Robots-Tag: noindex, nofollow, noarchive',
			'Referrer-Policy: no-referrer',
			'X-Content-Type-Options: nosniff',
		);
	}

	/** Marks the request as not cacheable and sends the private-page headers (before any output). */
	public static function send() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared constant read by page-cache plugins.
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		self::$last = self::header_lines();
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
