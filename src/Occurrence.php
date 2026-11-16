<?php
/**
 * Central definition of an occurrence's time phases (A535): when it has started, when it is over
 * and until when online sales run. Multi-day occurrences (festivals) keep running until their
 * `ends_at`; occurrences without an end are over after a configurable default duration (setting
 * `occurrence_default_duration_hours`, default 6 h, filter `wpcap_occurrence_default_duration`).
 *
 * Online sales: without a `sales_end` a ticket type is sold until the start (as before); a
 * `sales_end` after the start extends online sales up to the effective end (e.g. day tickets for
 * day 2 and 3 of a festival). Door sales (box office) run until the effective end.
 *
 * All times are UTC ("Y-m-d H:i:s"); rows only need `starts_at` and `ends_at`.
 *
 * Local wall-clock arithmetic (A555/A556): local_shift() moves a time by the same local calendar
 * distance as a rescheduled start, local_days_before() subtracts whole local days – both keep the
 * local time of day across DST changes.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Occurrence {
	/** Default duration of an occurrence without `ends_at` (hours). */
	const DEFAULT_DURATION_HOURS = 6;

	/**
	 * Duration (seconds) assumed for occurrences without `ends_at`: setting
	 * `occurrence_default_duration_hours` (1–168), filter `wpcap_occurrence_default_duration`.
	 */
	public static function default_duration() {
		$hours = (int) Settings::get( 'occurrence_default_duration_hours' );
		$hours = $hours > 0 ? min( 168, $hours ) : self::DEFAULT_DURATION_HOURS;
		/**
		 * Duration (seconds) of an occurrence without end time; decides when it counts as over
		 * (online and door sales, public listing, scanner date lists). Same value in PHP and SQL.
		 *
		 * @param int $seconds Default from the setting `occurrence_default_duration_hours`.
		 */
		$seconds = (int) apply_filters( 'wpcap_occurrence_default_duration', $hours * HOUR_IN_SECONDS );
		return max( MINUTE_IN_SECONDS, $seconds );
	}

	/** Start (Unix timestamp) of an occurrence row; 0 when unknown. */
	public static function start_ts( $occurrence ) {
		$starts = (string) ( is_array( $occurrence ) ? ( $occurrence['starts_at'] ?? '' ) : ( $occurrence->starts_at ?? '' ) );
		$ts = '' !== $starts ? strtotime( $starts . ' UTC' ) : false;
		return false === $ts ? 0 : (int) $ts;
	}

	/** Effective end (Unix timestamp): `ends_at` (never before the start), else start + default duration. */
	public static function end_ts( $occurrence ) {
		$start = self::start_ts( $occurrence );
		$ends = (string) ( is_array( $occurrence ) ? ( $occurrence['ends_at'] ?? '' ) : ( $occurrence->ends_at ?? '' ) );
		$end = '' !== $ends && '0000-00-00 00:00:00' !== $ends ? strtotime( $ends . ' UTC' ) : false;
		return false === $end ? $start + self::default_duration() : max( $start, (int) $end );
	}

	/** Effective end as UTC MySQL datetime (COALESCE(ends_at, starts_at + default duration)). */
	public static function effective_end( $occurrence ) {
		return gmdate( 'Y-m-d H:i:s', self::end_ts( $occurrence ) );
	}

	/** Whether the occurrence has started (start before $now). */
	public static function has_started( $occurrence, $now = null ) {
		return self::start_ts( $occurrence ) < ( null === $now ? Clock::now() : (int) $now );
	}

	/** Whether the occurrence is over (effective end before $now). */
	public static function is_over( $occurrence, $now = null ) {
		return self::end_ts( $occurrence ) < ( null === $now ? Clock::now() : (int) $now );
	}

	/** Whether the occurrence is running: started and not over. */
	public static function is_running( $occurrence, $now = null ) {
		return self::has_started( $occurrence, $now ) && ! self::is_over( $occurrence, $now );
	}

	/**
	 * End of online sales (Unix timestamp) of a ticket type: its `sales_end` capped at the effective
	 * end, or the start when no `sales_end` is set.
	 */
	public static function online_sales_until( $occurrence, $type ) {
		$sales_end = (string) ( is_array( $type ) ? ( $type['sales_end'] ?? '' ) : ( $type->sales_end ?? '' ) );
		$ts = '' !== $sales_end ? strtotime( $sales_end . ' UTC' ) : false;
		return false === $ts ? self::start_ts( $occurrence ) : min( (int) $ts, self::end_ts( $occurrence ) );
	}

	/**
	 * Whether online sales of the ticket type are over because of the occurrence's phase: started
	 * and past online_sales_until() (a `sales_end` before the start is the sales window's business).
	 */
	public static function online_sales_over( $occurrence, $type, $now = null ) {
		$now = null === $now ? Clock::now() : (int) $now;
		return self::has_started( $occurrence, $now ) && self::online_sales_until( $occurrence, $type ) < $now;
	}

	/** SQL expression of the effective end of occurrence alias $alias. */
	public static function end_sql( $alias = 'o' ) {
		$a = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		return "COALESCE(GREATEST($a.ends_at,$a.starts_at),DATE_ADD($a.starts_at,INTERVAL " . (int) self::default_duration() . ' SECOND))';
	}

	/**
	 * SQL condition: online sales of ticket type alias $type are open with regard to the phase of
	 * occurrence alias $occ at $now (not started, or a `sales_end` still ahead and not over).
	 * The sales window itself (sales_start/sales_end) is checked separately.
	 */
	public static function type_sale_sql( $type = 'tt', $occ = 'o', $now = null ) {
		global $wpdb;
		$t = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $type );
		$o = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $occ );
		$now = null === $now ? Clock::mysql() : (string) $now;
		return $wpdb->prepare( "($o.starts_at >= %s OR ($t.sales_end IS NOT NULL AND $t.sales_end >= %s AND " . self::end_sql( $o ) . ' >= %s))', $now, $now, $now ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized aliases.
	}

	/**
	 * SQL condition for public listings: occurrence alias $occ has not started, or is running and at
	 * least one active ticket type is still on sale online (sales_end ahead).
	 */
	public static function listable_sql( $occ = 'o', $now = null ) {
		global $wpdb;
		$o = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $occ );
		$now = null === $now ? Clock::mysql() : (string) $now;
		return $wpdb->prepare( "($o.starts_at >= %s OR (" . self::end_sql( $o ) . " >= %s AND EXISTS (SELECT 1 FROM {$wpdb->prefix}wpcap_ticket_types ltt WHERE ltt.occurrence_id=$o.occurrence_id AND ltt.status='active' AND ltt.sales_end IS NOT NULL AND ltt.sales_end >= %s AND (ltt.sales_start IS NULL OR ltt.sales_start <= %s))))", $now, $now, $now, $now ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized alias.
	}

	/**
	 * Admin hint when a ticket type's `sales_end` lies after the occurrence's effective end (online
	 * sales stop at the end anyway); '' otherwise.
	 */
	public static function sales_end_warning( $occurrence, $sales_end ) {
		$sales_end = (string) $sales_end;
		if ( '' === $sales_end || strtotime( $sales_end . ' UTC' ) <= self::end_ts( $occurrence ) ) { return ''; }
		$ends = (string) ( is_array( $occurrence ) ? ( $occurrence['ends_at'] ?? '' ) : ( $occurrence->ends_at ?? '' ) );
		return '' !== $ends
			? __( 'Das Verkaufsende liegt nach dem Ende des Termins. Online verkauft wird höchstens bis zum Terminende.', 'pass-captain' )
			/* translators: %d: default duration in hours */
			: sprintf( _n( 'Das Verkaufsende liegt nach dem Ende des Termins (ohne Endzeit: Beginn + %d Stunde). Online verkauft wird höchstens bis dahin; für mehrtägige Termine bitte ein Ende eintragen.', 'Das Verkaufsende liegt nach dem Ende des Termins (ohne Endzeit: Beginn + %d Stunden). Online verkauft wird höchstens bis dahin; für mehrtägige Termine bitte ein Ende eintragen.', max( 1, (int) round( self::default_duration() / HOUR_IN_SECONDS ) ), 'pass-captain' ), max( 1, (int) round( self::default_duration() / HOUR_IN_SECONDS ) ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Local wall-clock arithmetic (A555, A556).
	 * ------------------------------------------------------------------------------------------- */

	/** DateTimeZone of an occurrence timezone name (UTC when invalid). */
	public static function zone( $timezone ) {
		try {
			return new \DateTimeZone( '' !== (string) $timezone ? (string) $timezone : 'UTC' );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/** Local wall-clock time ("Y-m-d H:i:s") of a UTC datetime. */
	private static function wall( $utc, \DateTimeZone $tz ) {
		return ( new \DateTimeImmutable( (string) $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	}

	/** UTC datetime of a local wall-clock time (DST gap: shifted forward; ambiguous: first instance). */
	private static function from_wall( $wall, \DateTimeZone $tz ) {
		return ( new \DateTimeImmutable( (string) $wall, $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Moves UTC $utc by the local calendar distance between $from_utc and $to_utc in $timezone, e.g.
	 * a sales window when its occurrence moves by a week: 10:00 local stays 10:00 local, also across
	 * a DST change. null/'' is returned unchanged.
	 */
	public static function local_shift( $utc, $from_utc, $to_utc, $timezone ) {
		if ( null === $utc || '' === (string) $utc ) { return $utc; }
		try {
			$tz = self::zone( $timezone );
			$delta = strtotime( self::wall( $to_utc, $tz ) . ' UTC' ) - strtotime( self::wall( $from_utc, $tz ) . ' UTC' );
			if ( ! $delta ) { return (string) $utc; }
			return self::from_wall( gmdate( 'Y-m-d H:i:s', strtotime( self::wall( $utc, $tz ) . ' UTC' ) + $delta ), $tz );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) + strtotime( $to_utc . ' UTC' ) - strtotime( $from_utc . ' UTC' ) );
		}
	}

	/** UTC time $days local calendar days before UTC $utc (same local time of day across DST). */
	public static function local_days_before( $utc, $days, $timezone ) {
		try {
			$tz = self::zone( $timezone );
			return self::from_wall( ( new \DateTimeImmutable( self::wall( $utc, $tz ) . ' UTC' ) )->modify( '-' . absint( $days ) . ' days' )->format( 'Y-m-d H:i:s' ), $tz );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) - absint( $days ) * DAY_IN_SECONDS );
		}
	}

	/** ISO 8601 with the offset of $timezone (e.g. 2026-12-01T20:00:00+01:00); null when empty. */
	public static function iso( $utc, $timezone ) {
		if ( null === $utc || '' === (string) $utc || '0000-00-00 00:00:00' === (string) $utc ) { return null; }
		try {
			return ( new \DateTimeImmutable( (string) $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( self::zone( $timezone ) )->format( 'c' );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
