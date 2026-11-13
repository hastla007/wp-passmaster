<?php
/** Small request helpers (sanitised superglobals). */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Http {
	/** Upper-case request method (GET, POST, …), '' when unknown. */
	public static function method() {
		return strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) );
	}

	/** Whether this request is a POST. */
	public static function is_post() {
		return 'POST' === self::method();
	}

	/**
	 * Ends a response the plugin sent itself (downloads, printable pages). The action
	 * `wpcap_response_end` runs first, so tests and logging can observe it.
	 */
	public static function end() {
		/** A download or printable page was sent completely; the request ends right after. */
		do_action( 'wpcap_response_end' );
		exit;
	}
}
