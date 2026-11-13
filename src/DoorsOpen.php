<?php
/**
 * Doors-open time ("Einlass ab") per occurrence (schema 3.8.4, column wpcap_occurrences.doors_open_at,
 * UTC, NULL = no own doors time). Check-ins before that moment are refused with the result
 * `outside_entry_window`, whatever admission policy or default entry window applies; the time is
 * also shown on the ticket page, the event card and to scanning staff.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DoorsOpen {
	/** Whether the column exists (a deferred upgrade may still be pending). */
	public static function ready() {
		$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
		return false !== $version && '' !== $version && version_compare( $version, '3.8.4', '>=' );
	}

	/** Doors-open time (UTC "Y-m-d H:i:s") of an occurrence, or null. */
	public static function for_occurrence( $occurrence_id ) {
		if ( ! self::ready() || ! absint( $occurrence_id ) ) { return null; }
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT doors_open_at FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		return self::normalize( $value );
	}

	private static function normalize( $value ) {
		return empty( $value ) || '0000-00-00 00:00:00' === $value ? null : (string) $value;
	}

	/**
	 * Parses the admin form value (local time of the occurrence).
	 *
	 * @param string $raw        "Y-m-d H:i" / "Y-m-d\TH:i" in $timezone, '' = no doors time.
	 * @param string $timezone   Occurrence timezone.
	 * @param string $starts_utc Occurrence start (UTC).
	 * @return string|null|\WP_Error UTC datetime, null for none, or an error.
	 */
	public static function parse( $raw, $timezone, $starts_utc ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) { return null; }
		$utc = Admin\Forms::local_to_utc( $raw, $timezone );
		if ( null === $utc ) {
			return new \WP_Error( 'wpcap_occurrence_doors', __( 'Die Einlasszeit ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		if ( $utc > $starts_utc ) {
			return new \WP_Error( 'wpcap_occurrence_doors', __( 'Die Einlasszeit muss vor oder zum Beginn liegen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		if ( strtotime( $starts_utc . ' UTC' ) - strtotime( $utc . ' UTC' ) > 2 * DAY_IN_SECONDS ) {
			return new \WP_Error( 'wpcap_occurrence_doors', __( 'Die Einlasszeit darf höchstens zwei Tage vor dem Beginn liegen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		return $utc;
	}

	/**
	 * Admission block for a scan: 'outside_entry_window' before the doors-open time, otherwise ''.
	 *
	 * @param array $found Ticket context (occurrence_id; doors_open_at when already loaded).
	 * @param int   $at    Evaluation time (UTC timestamp).
	 */
	public static function block( array $found, $at ) {
		$doors = array_key_exists( 'doors_open_at', $found ) ? self::normalize( $found['doors_open_at'] ) : self::for_occurrence( (int) ( $found['occurrence_id'] ?? 0 ) );
		if ( null === $doors ) { return ''; }
		return (int) $at < strtotime( $doors . ' UTC' ) ? 'outside_entry_window' : '';
	}

	/**
	 * Text "Einlass ab 19:00" (time only when on the local start day, otherwise with date).
	 *
	 * @param string|null $doors_utc  Doors-open time (UTC) or null.
	 * @param string      $timezone   Occurrence timezone.
	 * @param string      $starts_utc Occurrence start (UTC) for the same-day check; '' = always with date.
	 * @return string '' when there is no doors time.
	 */
	public static function label( $doors_utc, $timezone, $starts_utc = '' ) {
		$doors_utc = self::normalize( $doors_utc );
		if ( null === $doors_utc ) { return ''; }
		$same_day = '' !== (string) $starts_utc && Admin\Forms::utc_to_local( $doors_utc, $timezone, 'Y-m-d' ) === Admin\Forms::utc_to_local( $starts_utc, $timezone, 'Y-m-d' );
		$when = Admin\Forms::utc_to_local( $doors_utc, $timezone, $same_day ? 'H:i' : 'd.m.Y H:i' );
		/* translators: %s: doors-open time (local time of the occurrence) */
		return sprintf( __( 'Einlass ab %s', 'wp-pass-captain' ), $when );
	}
}
