<?php
/**
 * Minimal WP-CLI stubs for PHPStan (only the API used by src/Ops/Cli.php). Never shipped.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

namespace {
	class WP_CLI {
		/** @param string $message */
		public static function log( $message ) {}
		/** @param string $message */
		public static function line( $message = '' ) {}
		/** @param string $message */
		public static function success( $message ) {}
		/** @param string $message */
		public static function warning( $message ) {}
		/** @param string $message @param bool|int $exit */
		public static function error( $message, $exit = true ) {}
		/** @param int $code */
		public static function halt( $code ) {}
		/** @param string $question @param array<string,mixed> $assoc */
		public static function confirm( $question, $assoc = array() ) {}
		/** @param string $name @param callable|string $callable @param array<string,mixed> $args */
		public static function add_command( $name, $callable, $args = array() ) {}
	}
}

namespace WP_CLI\Utils {
	/** @param string $format @param array<int,mixed> $items @param array<int,string>|string $fields */
	function format_items( $format, $items, $fields ) {}
}
