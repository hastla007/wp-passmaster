<?php
/**
 * Central clock for time-dependent business rules (holds, reminders, service booking deadlines,
 * waitlist offers, sales windows, report defaults). All stored times are UTC; local wall-clock
 * times are only derived for display and for day boundaries.
 *
 * The filter `wpcap_now` makes the current time injectable, so tests and simulations can step
 * across DST transitions deterministically instead of sleeping.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Clock {
	/** Current Unix timestamp (UTC), filterable via `wpcap_now`. */
	public static function now() {
		$real = time();
		/**
		 * Current time used by the plugin's business rules (holds, deadlines, reminders, sales windows).
		 *
		 * @param int $timestamp Unix timestamp (default time()).
		 */
		$ts = apply_filters( 'wpcap_now', $real );
		return is_numeric( $ts ) && (int) $ts > 0 ? (int) $ts : $real;
	}

	/** Current time (plus an offset in seconds) as UTC MySQL datetime. */
	public static function mysql( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', self::now() + (int) $offset );
	}

	/** Today's local date (Y-m-d) in the given timezone; invalid zones fall back to UTC. */
	public static function today( $timezone = 'UTC' ) {
		try {
			$zone = new \DateTimeZone( '' !== (string) $timezone ? (string) $timezone : 'UTC' );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		return ( new \DateTimeImmutable( '@' . self::now() ) )->setTimezone( $zone )->format( 'Y-m-d' );
	}
}
