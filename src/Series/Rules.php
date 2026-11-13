<?php
/**
 * Recurrence rules for occurrence series (pure, no database).
 *
 * Rule fields: freq (daily|weekly|monthly_day|monthly_nth|dates), interval (1–52), weekdays (ISO 1–7),
 * month_day (1–31), nth (1–5 or -1 = last), nth_weekday (ISO 1–7), dates (list of Y-m-d),
 * start_date (Y-m-d), time (H:i, local), duration_minutes, timezone (IANA), until (Y-m-d) or count,
 * exclusions (list of Y-m-d).
 *
 * Semantics: times are wall-clock times in the timezone, so a weekly 19:00 event stays at 19:00
 * local across DST changes (its UTC time shifts). A time inside a DST gap (e.g. 02:30 on the
 * spring-forward day) is moved forward by the gap and flagged "shifted"; an ambiguous time on the
 * fall-back day uses the first (summer time) instance. The end is start + duration in real minutes.
 * Excluded dates do not count towards "count". At most MAX occurrences are generated.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Series;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rules {
	const MAX = 200;
	const MAX_SCAN_DAYS = 3700;
	const FREQS = array( 'daily', 'weekly', 'monthly_day', 'monthly_nth', 'dates' );

	/** Parses a date in Y-m-d or d.m.Y; returns Y-m-d or null. */
	public static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m ) ) {
			list( , $d, $mo, $y ) = $m;
		} else {
			return null;
		}
		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/** List of dates from a textarea/array (newline, comma or semicolon separated). */
	public static function parse_dates( $value, &$invalid = array() ) {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) || '' === trim( (string) $item ) ) { continue; }
			$date = self::parse_date( $item );
			if ( null === $date ) { $invalid[] = (string) $item; continue; }
			$out[ $date ] = $date;
		}
		ksort( $out );
		return array_values( $out );
	}

	private static function error( $code, $message ) {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	/**
	 * Validates a rule.
	 *
	 * @return array|\WP_Error normalised rule.
	 */
	public static function normalize( array $in ) {
		$freq = in_array( $in['freq'] ?? '', self::FREQS, true ) ? $in['freq'] : '';
		if ( '' === $freq ) { return self::error( 'wpcap_series_freq', __( 'Bitte eine Wiederholungsart wählen.', 'wp-pass-captain' ) ); }
		$timezone = is_string( $in['timezone'] ?? null ) ? $in['timezone'] : '';
		if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) { return self::error( 'wpcap_series_timezone', __( 'Die Zeitzone ist ungültig.', 'wp-pass-captain' ) ); }
		$time = trim( (string) ( $in['time'] ?? '' ) );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $tm ) ) { return self::error( 'wpcap_series_time', __( 'Die Uhrzeit ist ungültig (HH:MM).', 'wp-pass-captain' ) ); }
		$time = sprintf( '%02d:%02d', $tm[1], $tm[2] );
		$duration = absint( $in['duration_minutes'] ?? 0 );
		if ( $duration > 60 * 24 * 14 ) { return self::error( 'wpcap_series_duration', __( 'Die Dauer ist zu lang.', 'wp-pass-captain' ) ); }
		$invalid = array();
		$exclusions = self::parse_dates( $in['exclusions'] ?? array(), $invalid );
		$rule = array(
			'freq' => $freq,
			'interval' => max( 1, min( 52, absint( $in['interval'] ?? 1 ) ) ),
			'time' => $time,
			'duration_minutes' => $duration,
			'timezone' => $timezone,
			'exclusions' => $exclusions,
		);
		if ( 'dates' === $freq ) {
			$rule['dates'] = self::parse_dates( $in['dates'] ?? array(), $invalid );
			if ( $invalid ) {
				/* translators: %s: invalid dates */
				return self::error( 'wpcap_series_invalid_date', sprintf( __( 'Ungültige Datumsangaben: %s', 'wp-pass-captain' ), implode( ', ', array_slice( $invalid, 0, 5 ) ) ) );
			}
			if ( ! $rule['dates'] ) { return self::error( 'wpcap_series_dates', __( 'Bitte mindestens ein Datum angeben.', 'wp-pass-captain' ) ); }
			if ( count( $rule['dates'] ) > self::MAX ) {
				/* translators: %d: maximum */
				return self::error( 'wpcap_series_too_many', sprintf( __( 'Höchstens %d Termine pro Serie.', 'wp-pass-captain' ), self::MAX ) );
			}
		} else {
			$start = self::parse_date( $in['start_date'] ?? '' );
			if ( null === $start ) { return self::error( 'wpcap_series_start', __( 'Das Startdatum ist ungültig.', 'wp-pass-captain' ) ); }
			$rule['start_date'] = $start;
			$until = trim( (string) ( $in['until'] ?? '' ) );
			$count = absint( $in['count'] ?? 0 );
			if ( '' !== $until ) {
				$rule['until'] = self::parse_date( $until );
				if ( null === $rule['until'] || $rule['until'] < $start ) { return self::error( 'wpcap_series_until', __( 'Das Enddatum ist ungültig oder liegt vor dem Start.', 'wp-pass-captain' ) ); }
			}
			if ( $count ) {
				if ( $count > self::MAX ) {
					/* translators: %d: maximum */
					return self::error( 'wpcap_series_too_many', sprintf( __( 'Höchstens %d Termine pro Serie.', 'wp-pass-captain' ), self::MAX ) );
				}
				$rule['count'] = $count;
			}
			if ( empty( $rule['until'] ) && empty( $rule['count'] ) ) { return self::error( 'wpcap_series_end', __( 'Bitte ein Enddatum oder eine Anzahl angeben.', 'wp-pass-captain' ) ); }
			if ( 'weekly' === $freq ) {
				$days = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['weekdays'] ?? array() ) ), static function ( $d ) { return $d >= 1 && $d <= 7; } ) ) );
				sort( $days );
				$rule['weekdays'] = $days ? $days : array( (int) ( new \DateTimeImmutable( $start ) )->format( 'N' ) );
			} elseif ( 'monthly_day' === $freq ) {
				$day = absint( $in['month_day'] ?? 0 );
				if ( $day < 1 || $day > 31 ) { return self::error( 'wpcap_series_month_day', __( 'Der Tag im Monat muss zwischen 1 und 31 liegen.', 'wp-pass-captain' ) ); }
				$rule['month_day'] = $day;
			} elseif ( 'monthly_nth' === $freq ) {
				$nth = (int) ( $in['nth'] ?? 0 );
				$weekday = absint( $in['nth_weekday'] ?? 0 );
				if ( ! in_array( $nth, array( 1, 2, 3, 4, 5, -1 ), true ) || $weekday < 1 || $weekday > 7 ) { return self::error( 'wpcap_series_nth', __( 'Bitte Woche im Monat und Wochentag angeben.', 'wp-pass-captain' ) ); }
				$rule['nth'] = $nth;
				$rule['nth_weekday'] = $weekday;
			}
		}
		if ( $invalid ) {
			/* translators: %s: invalid dates */
			return self::error( 'wpcap_series_invalid_date', sprintf( __( 'Ungültige Datumsangaben: %s', 'wp-pass-captain' ), implode( ', ', array_slice( $invalid, 0, 5 ) ) ) );
		}
		ksort( $rule );
		return $rule;
	}

	private static function matches( array $rule, \DateTimeImmutable $day, \DateTimeImmutable $start ) {
		$diff_days = (int) $start->diff( $day )->format( '%a' );
		switch ( $rule['freq'] ) {
			case 'daily':
				return 0 === $diff_days % $rule['interval'];
			case 'weekly':
				if ( ! in_array( (int) $day->format( 'N' ), $rule['weekdays'], true ) ) { return false; }
				$week0 = $start->modify( '-' . ( (int) $start->format( 'N' ) - 1 ) . ' days' );
				$weeks = intdiv( (int) $week0->diff( $day )->format( '%a' ), 7 );
				return 0 === $weeks % $rule['interval'];
			case 'monthly_day':
			case 'monthly_nth':
				$months = ( (int) $day->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12 + (int) $day->format( 'n' ) - (int) $start->format( 'n' );
				if ( 0 !== $months % $rule['interval'] ) { return false; }
				if ( 'monthly_day' === $rule['freq'] ) { return (int) $day->format( 'j' ) === $rule['month_day']; }
				if ( (int) $day->format( 'N' ) !== $rule['nth_weekday'] ) { return false; }
				$dom = (int) $day->format( 'j' );
				return -1 === $rule['nth'] ? $dom + 7 > (int) $day->format( 't' ) : (int) ceil( $dom / 7 ) === $rule['nth'];
		}
		return false;
	}

	/**
	 * Expands a normalised rule into occurrences.
	 *
	 * @return array|\WP_Error list of {date, local, offset, starts_at (UTC), ends_at (UTC|null), shifted, dst}.
	 */
	public static function expand( array $rule ) {
		$tz = new \DateTimeZone( $rule['timezone'] );
		$utc = new \DateTimeZone( 'UTC' );
		$excluded = array_flip( $rule['exclusions'] ?? array() );
		$dates = array();
		if ( 'dates' === $rule['freq'] ) {
			$dates = $rule['dates'];
		} else {
			$start = new \DateTimeImmutable( $rule['start_date'] );
			$day = $start;
			$count = $rule['count'] ?? 0;
			$until = $rule['until'] ?? null;
			for ( $i = 0; $i < self::MAX_SCAN_DAYS; $i++, $day = $day->modify( '+1 day' ) ) {
				$date = $day->format( 'Y-m-d' );
				if ( null !== $until && $date > $until ) { break; }
				if ( $count && count( $dates ) >= $count ) { break; }
				if ( ! self::matches( $rule, $day, $start ) || isset( $excluded[ $date ] ) ) { continue; }
				$dates[] = $date;
				if ( count( $dates ) > self::MAX ) {
					/* translators: %d: maximum */
					return new \WP_Error( 'wpcap_series_too_many', sprintf( __( 'Die Regel ergibt mehr als %d Termine; bitte Enddatum oder Anzahl verringern.', 'wp-pass-captain' ), self::MAX ), array( 'status' => 400 ) );
				}
			}
		}
		$out = array();
		foreach ( $dates as $date ) {
			if ( isset( $excluded[ $date ] ) ) { continue; }
			$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $rule['time'], $tz );
			// Ambiguous wall time (DST fall-back): use the first instance, one hour earlier in UTC.
			$earlier = $local->setTimestamp( $local->getTimestamp() - 3600 );
			$ambiguous = $earlier->format( 'Y-m-d H:i' ) === $local->format( 'Y-m-d H:i' );
			if ( $ambiguous ) { $local = $earlier; }
			$starts = $local->setTimezone( $utc );
			$shifted = $local->format( 'Y-m-d H:i' ) !== $date . ' ' . $rule['time'];
			$out[] = array(
				'date' => $date,
				'local' => $local->format( 'Y-m-d H:i' ),
				'offset' => $local->format( 'P' ),
				'shifted' => $shifted,
				// A102: 'gap' (time does not exist, moved forward), 'ambiguous' (first instance used) or ''.
				'dst' => $shifted ? 'gap' : ( $ambiguous ? 'ambiguous' : '' ),
				'starts_at' => $starts->format( 'Y-m-d H:i:s' ),
				'ends_at' => $rule['duration_minutes'] ? $starts->modify( '+' . (int) $rule['duration_minutes'] . ' minutes' )->format( 'Y-m-d H:i:s' ) : null,
			);
		}
		return $out;
	}
}
