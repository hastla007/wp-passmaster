<?php
/**
 * Shared access and output helpers for the box office, print and report modules.
 *
 * Scope semantics equal Access::can_access_event(): manage_options sees every event, everybody else
 * only events assigned in wpcap_event_staff for a capability their role also grants.
 */
namespace PassCaptain\Reports;

use PassCaptain\Access;
use PassCaptain\Admin\Queries;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Scope {
	/**
	 * Event ids reachable with any of the capabilities.
	 *
	 * @return int[]|null Null = all events.
	 */
	public static function event_ids( $caps ) {
		return Queries::event_ids_for( (array) $caps );
	}

	/** Whether the current user may access the event with at least one of the capabilities. */
	public static function can_event( $event_id, $caps ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) { return false; }
		foreach ( (array) $caps as $cap ) {
			if ( ! Access::can_access_event( $event_id, $cap ) ) { continue; }
			$ids = self::event_ids( array( $cap ) );
			if ( null === $ids || in_array( $event_id, $ids, true ) ) { return true; }
		}
		return false;
	}

	/** Like can_event() for the occurrence's event, plus the user's location/partner scope (A119). */
	public static function can_occurrence( $occurrence_id, $caps ) {
		return self::can_event( self::occurrence_event( $occurrence_id ), $caps ) && Access::occurrence_in_scope( $occurrence_id );
	}

	/** Event id of an occurrence (0 if unknown). */
	public static function occurrence_event( $occurrence_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
	}

	/** Event ids an order touches. */
	public static function order_events( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d", absint( $order_id ) ) ) );
	}

	/** Whether the user may access every event of the order with one of the capabilities (and every occurrence lies in the user's scope, A119). */
	public static function can_order( $order_id, $caps ) {
		$events = self::order_events( $order_id );
		if ( ! $events ) { return false; }
		foreach ( $events as $event_id ) {
			if ( ! self::can_event( $event_id, $caps ) ) { return false; }
		}
		return Access::order_in_scope( $order_id );
	}

	/** CSV cell that spreadsheet programs never evaluate as a formula. */
	public static function csv_cell( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) { return (string) $value; }
		$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $value );
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $text ) ) { return $text; } // Plain numbers cannot be formulas.
		return preg_match( '/^[=+\-@\t\r]/', $text ) || preg_match( '/^\s+[=+\-@]/', $text ) ? "'" . $text : $text;
	}

	/** CSV text (semicolon separated, UTF-8 BOM) from rows; every cell passes csv_cell(). */
	public static function csv( array $rows ) {
		$out = fopen( 'php://temp', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), (array) $row ), ';', '"', '\\' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	/** Sends a download response (headers + body) and ends the request. */
	public static function send_download( $body, $filename, $type ) {
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . $type );
			header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary download.
		\PassCaptain\Http::end();
	}

	/** Money display "1.234,50 EUR". */
	public static function money( $minor, $currency = 'EUR' ) {
		return number_format_i18n( (int) $minor / 100, 2 ) . ' ' . strtoupper( (string) $currency );
	}

	/** Local display time for a UTC datetime. */
	public static function local( $utc, $timezone, $format = 'd.m.Y H:i' ) {
		if ( empty( $utc ) ) { return ''; }
		try {
			$tz = new \DateTimeZone( $timezone ?: 'UTC' );
		} catch ( \Throwable $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		try {
			return ( new \DateTimeImmutable( (string) $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( $format );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Site timezone (option timezone_string, fallback UTC). */
	public static function site_timezone() {
		$tz = (string) get_option( 'timezone_string', '' );
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'UTC';
	}
}
