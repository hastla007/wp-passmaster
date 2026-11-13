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
}
