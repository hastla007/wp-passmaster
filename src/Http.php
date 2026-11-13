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

	/**
	 * Text value of an untrusted request field: strings and numbers as string; '' for arrays,
	 * objects, booleans and null (A194: wrongly typed input must not cause PHP warnings).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function str( $value ) {
		return is_string( $value ) || is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}

	/**
	 * Positive integer of an untrusted request field: integers, integral floats and digit strings
	 * ("12", " 12 "); 0 for everything else – booleans, negatives, fractions, "1e3", "12abc", arrays.
	 * Unlike absint() a negative number never turns into a positive one.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function positive_int( $value ) {
		if ( is_int( $value ) ) { return $value > 0 ? $value : 0; }
		if ( is_float( $value ) ) { return $value > 0 && $value < PHP_INT_MAX && floor( $value ) === $value ? (int) $value : 0; }
		if ( is_string( $value ) && preg_match( '/^\s*\+?([0-9]{1,18})\s*$/', $value, $m ) ) { return max( 0, (int) $m[1] ); }
		return 0;
	}
}
