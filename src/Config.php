<?php
/**
 * A408: runtime values that used to be hard-coded (job attempts, stale claims, payment provider timeouts, refund
 * wait times …) in one place. Every key has a default and bounds; sites change a value with the filter
 * `wpcap_config` ( $value, $key ), out-of-range values are clamped.
 *
 * Keys are documented in docs/HOOKS.md (section Laufzeitwerte).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Config {
	/** key => [default, min, max] (integers). */
	const VALUES = array(
		'jobs_max_attempts'        => array( 8, 1, 50 ),
		'jobs_stale_minutes'       => array( 15, 2, 240 ),
		'http_timeout'             => array( 15, 3, 60 ),
		'refund_wait_minutes'      => array( 10, 1, 240 ),
		'webhook_lock_minutes'     => array( 10, 1, 60 ),
	);

	/**
	 * Value of a runtime setting, filtered and clamped to its bounds.
	 *
	 * @param string $key Key of VALUES.
	 */
	public static function get( $key ) {
		$key = (string) $key;
		if ( ! isset( self::VALUES[ $key ] ) ) { return 0; }
		list( $default, $min, $max ) = self::VALUES[ $key ];
		/**
		 * One runtime value (A408).
		 *
		 * @param int    $value Default value.
		 * @param string $key   Key of Config::VALUES.
		 */
		$value = apply_filters( 'wpcap_config', $default, $key );
		return max( $min, min( $max, (int) $value ) );
	}

	/** All values (for the system status / diagnostics). */
	public static function all() {
		$out = array();
		foreach ( array_keys( self::VALUES ) as $key ) { $out[ $key ] = self::get( $key ); }
		return $out;
	}
}
