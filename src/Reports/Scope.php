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
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
	}

	/** Event ids an order touches. */
	public static function order_events( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT o.event_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $order_id ) ) ) );
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

	/**
	 * CSV cell that spreadsheet programs never evaluate as a formula: a leading = + - @ (also after
	 * whitespace) is neutralised with an apostrophe, and so is a formula character right after a quote,
	 * semicolon or tab inside the text (where a lenient reader could start a new cell). Valid JSON
	 * documents (archive columns) stay unchanged; csv() quotes them per RFC 4180. Stored HTML entities
	 * are decoded first (A497: "Kids &lt;3" is "Kids <3" in the export) – before the formula check, so
	 * "&#61;1+1" is guarded like "=1+1".
	 */
	public static function csv_cell( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) { return (string) $value; }
		if ( is_bool( $value ) ) { return $value ? '1' : '0'; }
		$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', \PassCaptain\Text::plain( $value ) );
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $text ) ) { return $text; } // Plain numbers cannot be formulas.
		if ( preg_match( '/^[=+\-@\t\r]/', $text ) || preg_match( '/^\s+[=+\-@]/', $text ) ) { return "'" . $text; }
		if ( preg_match( '/[";\t]\s*[=+\-@]/', $text ) && ! self::is_json_document( $text ) ) { return "'" . $text; }
		return $text;
	}

	/** Whether the text is a JSON object or array (machine-readable archive cells). */
	private static function is_json_document( $text ) {
		if ( '' === $text || ! in_array( $text[0], array( '{', '[' ), true ) ) { return false; }
		json_decode( $text );
		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * CSV text (semicolon separated) from rows; every cell passes csv_cell(). The one CSV writer of the
	 * plugin (reports, attendee/no-show lists, settlements, accounting export).
	 *
	 * Quoting is RFC 4180 only (escape character ''): with the escape character "\" a cell like
	 * `x\";=1+1;\"` was written so that spreadsheet programs closed the field early and read `=1+1`
	 * as a cell of its own (A495) – csv_cell() only sees the start of the cell.
	 *
	 * @param array $rows Rows (arrays of cells).
	 * @param array $args bom (bool, default true: UTF-8 byte order mark), encoding (default 'UTF-8';
	 *                    e.g. 'Windows-1252' for the DATEV import: cells are converted, no BOM).
	 */
	public static function csv( array $rows, array $args = array() ) {
		// In-memory stream for fputcsv() (no file on disk, so WP_Filesystem does not apply).
		$out = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::write_csv( $out, $rows, 0, $args );
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * Streams CSV rows as a download (PERF-10/QA-7): the rows (array or generator) are written one by
	 * one into a temporary stream that spills to disk above 2 MB, so large exports neither build a
	 * PHP string nor need a row limit. Each row goes through csv() (same quoting and cell rules).
	 *
	 * @param iterable $rows     Rows (header first).
	 * @param string   $filename Download file name.
	 */
	public static function send_csv( $rows, $filename ) {
		$out = fopen( 'php://temp/maxmemory:2097152', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::write_csv( $out, $rows );
		self::send_stream( $out, $filename, 'text/csv; charset=utf-8' );
	}

	/**
	 * Sends a download from an open stream and ends the request. The wpcap_pre_send_download filter
	 * receives the stream (rewound) instead of a string body.
	 *
	 * @param resource $handle   Readable stream.
	 */
	public static function send_stream( $handle, $filename, $type ) {
		rewind( $handle );
		if ( null !== apply_filters( 'wpcap_pre_send_download', null, $handle, $filename, $type ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . $type );
			header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			$stat = fstat( $handle );
			if ( ! empty( $stat['size'] ) ) { header( 'Content-Length: ' . (int) $stat['size'] ); }
		}
		rewind( $handle );
		fpassthru( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		\PassCaptain\Http::end();
	}

	/**
	 * Writes CSV (BOM, semicolons, csv_cell()) to a stream row by row; $rows may be any iterable
	 * (a generator for large lists, A477). With $flush_every the output is flushed after that many
	 * rows (download streaming).
	 *
	 * @param resource $out  Stream.
	 * @param iterable $rows Rows (first row = header).
	 */
	public static function write_csv( $out, $rows, $flush_every = 0, array $args = array() ) {
		// A495: RFC 4180 quoting without a backslash escape – `\"` cannot end a quoted cell early.
		$encoding = (string) ( $args['encoding'] ?? 'UTF-8' );
		$utf8 = 'UTF-8' === strtoupper( $encoding );
		if ( $utf8 && ( $args['bom'] ?? true ) ) { fwrite( $out, "\xEF\xBB\xBF" ); }
		$n = 0;
		foreach ( $rows as $row ) {
			$cells = array_map( array( __CLASS__, 'csv_cell' ), (array) $row );
			if ( ! $utf8 ) {
				$cells = array_map( static function ( $cell ) use ( $encoding ) { return mb_convert_encoding( $cell, $encoding, 'UTF-8' ); }, $cells );
			}
			fputcsv( $out, $cells, ';', '"', '' );
			if ( $flush_every && 0 === ++$n % $flush_every ) {
				fflush( $out );
				self::flush_output();
			}
		}
	}

	/** Whether a streamed download is being sent (flush_output() pushes output to the client). */
	private static $streaming = false;

	/**
	 * Streams a CSV download from an iterable of rows and ends the request (A477): rows are written
	 * as they are produced instead of building the whole file in memory.
	 *
	 * @param iterable $rows Rows (first row = header).
	 */
	public static function stream_csv( $rows, $filename, $flush_every = 500 ) {
		self::start_stream( $filename, 'text/csv; charset=utf-8' );
		$out = fopen( 'php://output', 'w' );
		self::write_csv( $out, $rows, max( 1, (int) $flush_every ) );
		fclose( $out );
		\PassCaptain\Http::end();
	}

	/**
	 * Headers of a streamed response ($filename '' = inline, e.g. a print view) and no output
	 * buffering in the way; flush_output() then sends what was written so far.
	 */
	public static function start_stream( $filename, $type ) {
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		if ( headers_sent() ) { return; } // Output started already (CLI, tests): plain output, no streaming.
		header( 'Content-Type: ' . $type );
		if ( '' !== (string) $filename ) { header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) . '"' ); }
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' );
		// Output buffers of WordPress, themes or caches would hold the whole file in memory.
		while ( ob_get_level() > 0 && @ob_end_flush() ) {} // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a buffer that cannot be removed stays.
		self::$streaming = true;
	}

	/** Sends the output written so far during a streamed response (no-op otherwise). */
	public static function flush_output() {
		if ( self::$streaming ) { flush(); }
	}

	/** Sends a download response (headers + body) and ends the request. */
	public static function send_download( $body, $filename, $type ) {
		// Filter wpcap_pre_send_download: a non-null return takes over delivery (e.g. storage, tests).
		if ( null !== apply_filters( 'wpcap_pre_send_download', null, $body, $filename, $type ) ) { return; }
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
		return \PassCaptain\Money::format( $minor, $currency );
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
