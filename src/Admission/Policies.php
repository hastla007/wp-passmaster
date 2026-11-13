<?php
/**
 * Admission policy per ticket type.
 *
 * Policies:
 *  - single      one entry in total (default when no row exists; ticket status becomes checked_in)
 *  - daily       once per valid day; valid days = occurrence_days rows of the occurrence if any,
 *                otherwise every local date from start to end of the occurrence
 *  - multi_day   once per day on an explicit set of dates (valid_dates)
 *  - multi_entry max_entries entries in total
 *  - reentry     check-in only while outside (check-out in between), optional max_entries (0 = unlimited)
 *
 * Entry window: opens_before_minutes before the occurrence start and closes_after_minutes after its
 * end (or start if it has no end); NULL = no limit. Optional daily hours day_from/day_until (HH:MM,
 * local time of the occurrence). All dates are evaluated in the occurrence timezone.
 */
namespace PassCaptain\Admission;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Policies {
	const TYPES = array( 'single', 'daily', 'multi_day', 'multi_entry', 'reentry' );
	const MAX_DATES = 366;

	public static function labels() {
		return array(
			'single' => __( 'Einmaliger Einlass', 'pass-captain' ),
			'daily' => __( 'Täglicher Einlass (einmal pro gültigem Tag)', 'pass-captain' ),
			'multi_day' => __( 'Mehrtagesticket (gewählte Tage, einmal pro Tag)', 'pass-captain' ),
			'multi_entry' => __( 'Mehrfacheinlass (N Einlässe insgesamt)', 'pass-captain' ),
			'reentry' => __( 'Wiedereinlass (Auslass, dann erneuter Einlass)', 'pass-captain' ),
		);
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_admission_policies';
	}

	/** Normalised policy of a ticket type, or null when none is configured (legacy behaviour). */
	public static function for_type( $ticket_type_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ticket_type_id=%d', absint( $ticket_type_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
		return $row ? self::normalize( $row ) : null;
	}

	private static function normalize( $row ) {
		$dates = json_decode( (string) $row->valid_dates, true );
		return array(
			'ticket_type_id' => (int) $row->ticket_type_id,
			'policy' => in_array( $row->policy, self::TYPES, true ) ? $row->policy : 'single',
			'max_entries' => (int) $row->max_entries,
			'valid_dates' => is_array( $dates ) ? array_values( array_filter( $dates, 'is_string' ) ) : array(),
			'opens_before_minutes' => null === $row->opens_before_minutes ? null : (int) $row->opens_before_minutes,
			'closes_after_minutes' => null === $row->closes_after_minutes ? null : (int) $row->closes_after_minutes,
			'day_from' => $row->day_from ? (string) $row->day_from : null,
			'day_until' => $row->day_until ? (string) $row->day_until : null,
		);
	}

	/**
	 * Validates and stores a policy (no capability check; callers check).
	 *
	 * @param array $in policy, max_entries, valid_dates (string or list), opens_before_minutes,
	 *                  closes_after_minutes ('' = no limit), day_from, day_until.
	 * @return array|\WP_Error Normalised policy.
	 */
	public static function save( $ticket_type_id, array $in ) {
		global $wpdb;
		$ticket_type_id = absint( $ticket_type_id );
		$policy = isset( $in['policy'] ) && in_array( (string) $in['policy'], self::TYPES, true ) ? (string) $in['policy'] : '';
		if ( ! $ticket_type_id || '' === $policy ) {
			return self::invalid( __( 'Unbekannte Einlassregel.', 'pass-captain' ) );
		}
		$max = absint( $in['max_entries'] ?? 0 );
		if ( 'multi_entry' === $policy && ( $max < 1 || $max > 1000 ) ) {
			return self::invalid( __( 'Für Mehrfacheinlass bitte 1 bis 1000 Einlässe angeben.', 'pass-captain' ) );
		}
		if ( 'reentry' === $policy && $max > 1000 ) {
			return self::invalid( __( 'Höchstens 1000 Einlässe.', 'pass-captain' ) );
		}
		if ( ! in_array( $policy, array( 'multi_entry', 'reentry' ), true ) ) { $max = 0; }
		$dates = array();
		if ( 'multi_day' === $policy ) {
			$dates = self::parse_dates( $in['valid_dates'] ?? '' );
			if ( is_wp_error( $dates ) ) { return $dates; }
			if ( ! $dates ) { return self::invalid( __( 'Für Mehrtagestickets mindestens einen gültigen Tag angeben.', 'pass-captain' ) ); }
		}
		$minutes = array();
		foreach ( array( 'opens_before_minutes', 'closes_after_minutes' ) as $field ) {
			$raw = isset( $in[ $field ] ) && is_scalar( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $raw ) { $minutes[ $field ] = null; continue; }
			if ( ! preg_match( '/^-?\d{1,6}$/', $raw ) || abs( (int) $raw ) > 525600 ) {
				return self::invalid( __( 'Das Einlassfenster muss in ganzen Minuten angegeben werden.', 'pass-captain' ) );
			}
			$minutes[ $field ] = (int) $raw;
		}
		$hours = array();
		foreach ( array( 'day_from', 'day_until' ) as $field ) {
			$raw = isset( $in[ $field ] ) && is_scalar( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $raw ) { $hours[ $field ] = null; continue; }
			if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $raw ) ) {
				return self::invalid( __( 'Uhrzeiten bitte als HH:MM angeben.', 'pass-captain' ) );
			}
			$hours[ $field ] = $raw;
		}
		if ( $hours['day_from'] && $hours['day_until'] && $hours['day_until'] <= $hours['day_from'] ) {
			return self::invalid( __( 'Die tägliche Einlasszeit muss vor ihrem Ende beginnen.', 'pass-captain' ) );
		}
		$wpdb->query( $wpdb->prepare(
			'REPLACE INTO ' . self::table() . ' (ticket_type_id,policy,max_entries,valid_dates,opens_before_minutes,closes_after_minutes,day_from,day_until,updated_by,updated_at) VALUES (%d,%s,%d,%s,' . ( null === $minutes['opens_before_minutes'] ? 'NULL' : (int) $minutes['opens_before_minutes'] ) . ',' . ( null === $minutes['closes_after_minutes'] ? 'NULL' : (int) $minutes['closes_after_minutes'] ) . ',' . ( $hours['day_from'] ? "'" . $hours['day_from'] . "'" : 'NULL' ) . ',' . ( $hours['day_until'] ? "'" . $hours['day_until'] . "'" : 'NULL' ) . ',%d,%s)', // phpcs:ignore WordPress.DB.PreparedSQL -- integers and regex-validated HH:MM only.
			$ticket_type_id, $policy, $max, wp_json_encode( $dates ), get_current_user_id(), current_time( 'mysql', true )
		) );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'wpcap_admission_policy_save', __( 'Einlassregel konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		return self::for_type( $ticket_type_id );
	}

	/** Settings of the module (array setting `admission`). */
	public static function settings() {
		$stored = \PassCaptain\Settings::get( 'admission' );
		return array_merge( array( 'offline_max_age_hours' => 12 ), is_array( $stored ) ? $stored : array() );
	}

	/** Maximum age of an offline scan whose device time is used for evaluation (0–168 h). */
	public static function offline_max_age_seconds() {
		return min( 168, max( 0, (int) self::settings()['offline_max_age_hours'] ) ) * HOUR_IN_SECONDS;
	}

	/** Removes a policy (back to the default single entry / legacy day rules). */
	public static function delete( $ticket_type_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'ticket_type_id' => absint( $ticket_type_id ) ), array( '%d' ) );
	}

	/**
	 * Parses dates: list or text with "YYYY-MM-DD" items and ranges "YYYY-MM-DD..YYYY-MM-DD",
	 * separated by commas, semicolons, spaces or newlines.
	 *
	 * @return string[]|\WP_Error Sorted unique dates.
	 */
	public static function parse_dates( $input ) {
		$items = is_array( $input ) ? $input : preg_split( '/[\s,;]+/', (string) $input );
		$out = array();
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) { continue; }
			$parts = explode( '..', $item );
			if ( count( $parts ) > 2 ) { return self::invalid( __( 'Ungültige Datumsangabe.', 'pass-captain' ) ); }
			$from = self::valid_date( $parts[0] );
			$to = self::valid_date( $parts[1] ?? $parts[0] );
			if ( ! $from || ! $to || $to < $from ) { return self::invalid( __( 'Ungültige Datumsangabe.', 'pass-captain' ) ); }
			$day = new \DateTimeImmutable( $from, new \DateTimeZone( 'UTC' ) );
			while ( $day->format( 'Y-m-d' ) <= $to ) {
				$out[ $day->format( 'Y-m-d' ) ] = true;
				if ( count( $out ) > self::MAX_DATES ) { return self::invalid( __( 'Zu viele Tage (höchstens 366).', 'pass-captain' ) ); }
				$day = $day->modify( '+1 day' );
			}
		}
		$dates = array_keys( $out );
		sort( $dates );
		return $dates;
	}

	private static function valid_date( $date ) {
		$date = trim( (string) $date );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return ''; }
		return $date;
	}

	private static function invalid( $message ) {
		return new \WP_Error( 'wpcap_admission_policy_invalid', $message, array( 'status' => 400 ) );
	}

	/**
	 * Valid local dates for day-based policies; null = no day restriction.
	 *
	 * @param array $found Ticket context from Checkin::lookup (occurrence_id, starts_at, ends_at, timezone).
	 */
	public static function valid_days( array $policy, array $found ) {
		if ( 'multi_day' === $policy['policy'] ) { return $policy['valid_dates']; }
		if ( 'daily' !== $policy['policy'] ) { return null; }
		global $wpdb;
		$days = $wpdb->get_col( $wpdb->prepare( "SELECT service_date FROM {$wpdb->prefix}wpcap_occurrence_days WHERE occurrence_id=%d ORDER BY service_date", (int) $found['occurrence_id'] ) );
		if ( $days ) { return array_map( 'strval', $days ); }
		// Service days (respecting the day cutoff), so a night that ends after midnight adds no extra day.
		$start = strtotime( $found['starts_at'] . ' UTC' );
		$end = strtotime( ( $found['ends_at'] ?: $found['starts_at'] ) . ' UTC' );
		$out = array();
		$day = new \DateTimeImmutable( Engine::service_date( $start, $found['timezone'], $found ), new \DateTimeZone( 'UTC' ) );
		$last = Engine::service_date( max( $start, $end ), $found['timezone'], $found );
		while ( $day->format( 'Y-m-d' ) <= $last && count( $out ) <= self::MAX_DATES ) {
			$out[] = $day->format( 'Y-m-d' );
			$day = $day->modify( '+1 day' );
		}
		return $out;
	}

	/** Latest UTC timestamp at which the ticket can still be used (for the lookup's plausibility window). */
	public static function last_valid_ts( $ticket_type_id, array $found ) {
		$policy = self::for_type( $ticket_type_id );
		$end = strtotime( ( $found['ends_at'] ?: $found['starts_at'] ) . ' UTC' );
		$end = max( $end, strtotime( $found['starts_at'] . ' UTC' ) );
		if ( $policy ) {
			if ( null !== $policy['closes_after_minutes'] && $policy['closes_after_minutes'] > 0 ) { $end += $policy['closes_after_minutes'] * 60; }
			if ( 'multi_day' === $policy['policy'] && $policy['valid_dates'] ) {
				$last = end( $policy['valid_dates'] );
				$local = new \DateTimeImmutable( $last . ' 23:59:59', Engine::timezone( $found['timezone'] ) );
				$end = max( $end, $local->getTimestamp() );
			}
		}
		return $end;
	}
}
