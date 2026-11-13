<?php
/**
 * RFC 5545 iCalendar writer for published occurrences.
 *
 * - Lines end with CRLF and are folded at 75 octets (never inside a UTF-8 sequence).
 * - TEXT values escape backslash, semicolon, comma and newlines.
 * - All DATE-TIME values are written in UTC ("Z"), so no VTIMEZONE component is required.
 * - UIDs are stable per occurrence: "wpcap-occurrence-{id}@{host}".
 * - Only event data is exported; no buyer or attendee data (no PII).
 */
namespace PassCaptain\Calendar;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Ics {
	const PRODID = '-//Pass Captain//Event Calendar//DE';

	/** Escapes a TEXT property value (RFC 5545 3.3.11). */
	public static function escape_text( $value ) {
		$value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $value );
	}

	/** Folds one content line at 75 octets with CRLF + space; multi-byte characters are never split. */
	public static function fold( $line ) {
		$line = (string) $line;
		if ( strlen( $line ) <= 75 ) { return $line; }
		$out = '';
		$current = '';
		$limit = 75;
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) { $chars = str_split( $line ); }
		foreach ( $chars as $char ) {
			if ( strlen( $current ) + strlen( $char ) > $limit ) {
				$out .= $current . "\r\n ";
				$current = '';
				$limit = 74; // Continuation lines start with one space octet.
			}
			$current .= $char;
		}
		return $out . $current;
	}

	/** UTC DATE-TIME value from a Unix timestamp. */
	public static function utc( $timestamp ) {
		return gmdate( 'Ymd\THis\Z', (int) $timestamp );
	}

	/** Stable UID of an occurrence. */
	public static function uid( $occurrence_id ) {
		$host = (string) wp_parse_url_host( home_url( '/' ) );
		return 'wpcap-occurrence-' . absint( $occurrence_id ) . '@' . ( '' !== $host ? $host : 'localhost' );
	}

	/**
	 * Builds a VCALENDAR document.
	 *
	 * @param array $entries List of ['occurrence_id','start'(ts),'end'(ts),'summary','location','description','url','status'].
	 * @param array $options ['name' => calendar name for feeds, 'now' => DTSTAMP timestamp].
	 */
	public static function build( array $entries, array $options = array() ) {
		$now = (int) ( $options['now'] ?? time() );
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID, 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH' );
		if ( ! empty( $options['name'] ) ) {
			$lines[] = 'X-WR-CALNAME:' . self::escape_text( $options['name'] );
		}
		foreach ( $entries as $entry ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . self::uid( $entry['occurrence_id'] );
			$lines[] = 'DTSTAMP:' . self::utc( $now );
			$lines[] = 'DTSTART:' . self::utc( $entry['start'] );
			$lines[] = 'DTEND:' . self::utc( max( (int) $entry['end'], (int) $entry['start'] ) );
			$lines[] = 'SUMMARY:' . self::escape_text( $entry['summary'] );
			if ( '' !== (string) ( $entry['location'] ?? '' ) ) { $lines[] = 'LOCATION:' . self::escape_text( $entry['location'] ); }
			if ( '' !== (string) ( $entry['description'] ?? '' ) ) { $lines[] = 'DESCRIPTION:' . self::escape_text( $entry['description'] ); }
			if ( '' !== (string) ( $entry['url'] ?? '' ) ) { $lines[] = 'URL:' . str_replace( array( "\r", "\n" ), '', (string) $entry['url'] ); }
			$lines[] = 'STATUS:' . ( 'cancelled' === ( $entry['status'] ?? '' ) ? 'CANCELLED' : 'CONFIRMED' );
			$lines[] = 'TRANSP:OPAQUE';
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	/** Maps an occurrence row (with event_name, event_description) to a calendar entry. */
	public static function entry_from_row( $row ) {
		$start = (int) strtotime( $row->starts_at . ' UTC' );
		$end = ! empty( $row->ends_at ) ? (int) strtotime( $row->ends_at . ' UTC' ) : $start + (int) apply_filters( 'wpcap_calendar_default_duration', 2 * HOUR_IN_SECONDS, $row );
		$description = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) ( $row->event_description ?? '' ) ) ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $description ) > 500 ) { $description = mb_substr( $description, 0, 497 ) . '...'; }
		$url = self::checkout_url( (int) $row->occurrence_id );
		return array(
			'occurrence_id' => (int) $row->occurrence_id,
			'start' => $start,
			'end' => $end,
			'summary' => (string) $row->event_name,
			'location' => (string) $row->location,
			'description' => trim( $description . ( '' !== $url ? "\n\n" . $url : '' ) ),
			'url' => $url,
			'status' => (string) $row->status,
		);
	}

	/** Checkout link of an occurrence: configured shortcode page + card anchor. */
	public static function checkout_url( $occurrence_id ) {
		$settings = (array) Settings::get( 'calendar' );
		$base = (string) ( $settings['checkout_url'] ?? '' );
		$url = '' !== $base ? $base . '#wpcap-occ-' . absint( $occurrence_id ) : '';
		return (string) apply_filters( 'wpcap_calendar_checkout_url', $url, absint( $occurrence_id ) );
	}

	/** One occurrence of a published event, or null. */
	public static function published_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status,e.name AS event_name,e.description AS event_description FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d AND e.status='published'", absint( $occurrence_id ) ) );
	}

	/** iCalendar for one occurrence or null when it is not public. */
	public static function occurrence_calendar( $occurrence_id ) {
		$row = self::published_occurrence( $occurrence_id );
		return $row ? self::build( array( self::entry_from_row( $row ) ) ) : null;
	}

	/** Feed of a published event: occurrences from 30 days ago on (cancelled ones as STATUS:CANCELLED). */
	public static function event_feed( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name FROM {$p}wpcap_events WHERE event_id=%d AND status='published'", absint( $event_id ) ) );
		if ( ! $event ) { return null; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status,e.name AS event_name,e.description AS event_description FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.event_id=%d AND o.starts_at>=%s ORDER BY o.starts_at LIMIT 500", (int) $event->event_id, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		return self::build( array_map( array( __CLASS__, 'entry_from_row' ), $rows ), array( 'name' => $event->name ) );
	}

	/** Public download URL of one occurrence. */
	public static function occurrence_url( $occurrence_id ) {
		return add_query_arg( array( 'wpcap_ics' => 'occurrence', 'id' => absint( $occurrence_id ) ), home_url( '/' ) );
	}

	/** Subscribable feed URL of an event (webcal:// variant for one-click subscription). */
	public static function feed_url( $event_id, $webcal = false ) {
		$url = add_query_arg( array( 'wpcap_ics' => 'event', 'id' => absint( $event_id ) ), home_url( '/' ) );
		return $webcal ? preg_replace( '#^https?://#', 'webcal://', $url ) : $url;
	}
}

/** Host part of a URL (wp_parse_url when available). */
function wp_parse_url_host( $url ) {
	$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	return is_string( $host ) ? strtolower( $host ) : '';
}
