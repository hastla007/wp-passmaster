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
	 * Longest e-mail address the plugin stores: every e-mail column is varchar(190) (index length
	 * limit of utf8mb4). Longer addresses are refused at the entrance (A493), never cut.
	 */
	const EMAIL_MAX = 190;

	/**
	 * Sanitised e-mail address of an untrusted field (A492): sanitize_email() of a string or number,
	 * '' for arrays, objects, booleans and null – never a TypeError (sanitize_email() calls strlen()).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function email( $value ) {
		return sanitize_email( self::str( $value ) );
	}

	/**
	 * Whether an (already sanitised) e-mail address is valid and fits the e-mail columns
	 * (EMAIL_MAX). Use it instead of is_email() wherever the address is stored.
	 *
	 * @param mixed $email Address.
	 */
	public static function valid_email( $email ) {
		return is_string( $email ) && strlen( $email ) <= self::EMAIL_MAX && is_email( $email );
	}

	/**
	 * Optional e-mail field (A498): '' when nothing was entered, the sanitised address when it is valid
	 * and fits the columns, false otherwise. WordPress' sanitize_email() returns '' for input without
	 * "@" or a valid domain ("kein-mail"), so "'' !== sanitize_email( $raw )" alone treated such input
	 * as "no e-mail given" and accepted it silently.
	 *
	 * @param mixed $value Raw value.
	 * @return string|false
	 */
	public static function optional_email( $value ) {
		$raw = trim( self::str( $value ) );
		if ( '' === $raw ) { return ''; }
		$email = sanitize_email( $raw );
		return self::valid_email( $email ) ? $email : false;
	}

	/**
	 * Whether a valid e-mail address is too long to be stored (more than EMAIL_MAX bytes), so the
	 * caller can name the reason instead of "invalid".
	 *
	 * @param mixed $email Address.
	 */
	public static function email_too_long( $email ) {
		return is_string( $email ) && strlen( $email ) > self::EMAIL_MAX && is_email( $email );
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
