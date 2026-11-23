<?php
/**
 * Strict date/time input for API payloads (REST, programmatic calls).
 *
 * Accepted: "Y-m-d H:i:s" (taken as UTC) or ISO 8601 with an explicit offset
 * ("2026-12-01T18:00:00+01:00", "…Z", optional fraction, offset with or without colon; a trailing
 * " UTC" counts as offset 0). Everything else ("tomorrow", "01.12.2026 18:00", values without an
 * offset in ISO form, impossible dates) is rejected with HTTP 400 and the field name, instead of
 * being guessed by strtotime() or truncated by MySQL to 0000-00-00.
 *
 * Admin forms keep entering local wall-clock times; they convert with Admin\Forms::local_to_utc()
 * and pass the resulting "Y-m-d H:i:s" UTC value, which this parser accepts unchanged.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DateInput {
	/**
	 * Parses one field to a UTC MySQL datetime.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $field Field name for the error message (e.g. "starts_at").
	 * @return string|null|\WP_Error "Y-m-d H:i:s" in UTC, null for an empty value, WP_Error (status 400) otherwise.
	 */
	public static function utc( $value, $field ) {
		if ( null === $value || '' === $value ) { return null; }
		if ( ! is_string( $value ) ) { return self::error( $field ); }
		$value = trim( $value );
		if ( '' === $value ) { return null; }
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $value, $m ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $m[1] . ' ' . $m[2], new \DateTimeZone( 'UTC' ) );
			return self::valid( $date ) && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : self::error( $field );
		}
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?(?:\.\d{1,6})?(Z| ?UTC|[+-]\d{2}(?::?\d{2})?)$/i', $value, $m ) ) {
			return self::error( $field );
		}
		$offset = strtoupper( ltrim( $m[4] ) );
		if ( 'Z' === $offset || 'UTC' === $offset ) {
			$offset = '+00:00';
		} else {
			$digits = str_replace( ':', '', substr( $offset, 1 ) );
			$digits = str_pad( $digits, 4, '0' );
			if ( (int) substr( $digits, 0, 2 ) > 14 || (int) substr( $digits, 2, 2 ) > 59 ) { return self::error( $field ); }
			$offset = $offset[0] . substr( $digits, 0, 2 ) . ':' . substr( $digits, 2, 2 );
		}
		$local = $m[1] . 'T' . $m[2] . ( '' !== $m[3] ? $m[3] : ':00' );
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $local . $offset );
		if ( ! self::valid( $date ) || $date->format( 'Y-m-d\TH:i:s' ) !== $local ) { return self::error( $field ); }
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parses several fields of $input at once.
	 *
	 * @param array    $input  Payload.
	 * @param string[] $fields Field names.
	 * @return array|\WP_Error Field => UTC datetime or null (only fields present in $input), or the first error.
	 */
	public static function fields( array $input, array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			$value = self::utc( $input[ $field ], $field );
			if ( is_wp_error( $value ) ) { return $value; }
			$out[ $field ] = $value;
		}
		return $out;
	}

	private static function valid( $date ) {
		if ( ! $date instanceof \DateTimeImmutable ) { return false; }
		$errors = \DateTimeImmutable::getLastErrors();
		return ! ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) && (int) $date->format( 'Y' ) >= 1000;
	}

	private static function error( $field ) {
		return new \WP_Error(
			'wpcap_invalid_datetime',
			/* translators: %s: field name, e.g. starts_at */
			sprintf( __( 'Feld „%s“: ungültiges Datum. Erwartet wird „JJJJ-MM-TT HH:MM:SS“ (UTC) oder ISO 8601 mit Zeitzonen-Offset, z. B. „2026-12-01T18:00:00+01:00“.', 'pass-captain' ), $field ),
			array( 'status' => 400, 'field' => $field )
		);
	}
}
