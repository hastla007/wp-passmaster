<?php
/**
 * RFC 5545 iCalendar writer for published occurrences.
 *
 * - Lines end with CRLF and are folded at 75 octets (never inside a UTF-8 sequence).
 * - TEXT values escape backslash, semicolon, comma and newlines.
 * - All DATE-TIME values are written in UTC ("Z"), so no VTIMEZONE component is required.
 * - UIDs are stable per occurrence: "wpcap-occurrence-{id}@{host}" – the same in the ticket mail,
 *   the change mails, the single download and the event feed, so every copy updates one entry.
 * - SEQUENCE is the occurrence's revision counter (A140, Revisions): it grows with every change of
 *   start, end, doors open, location, venue country or status; LAST-MODIFIED is the time of that
 *   change and DTSTAMP the time the file was generated. Cancelled occurrences carry
 *   STATUS:CANCELLED; a single cancelled occurrence is sent as METHOD:CANCEL when an organizer
 *   address is configured (filter `wpcap_calendar_organizer`, required by RFC 5546 for CANCEL).
 * - Only event data is exported; no buyer or attendee data (no PII).
 */
namespace PassCaptain\Calendar;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Ics {
	const PRODID = '-//WP Pass Captain//Event Calendar//DE';

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

	/** Quoted parameter value (RFC 5545 3.2): DQUOTE and control characters are not allowed. */
	public static function param_value( $value ) {
		return '"' . preg_replace( '/["\x00-\x1F\x7F]/', '', (string) $value ) . '"';
	}

	/**
	 * Organizer address for ORGANIZER (needed for METHOD:CANCEL); empty = no ORGANIZER line.
	 *
	 * @return string E-mail address or ''.
	 */
	public static function organizer() {
		/**
		 * E-mail address written as ORGANIZER into calendar files (default: none). With an address,
		 * the file of a single cancelled occurrence uses METHOD:CANCEL instead of METHOD:PUBLISH.
		 *
		 * @param string $email Organizer e-mail address ('' = none).
		 */
		$email = (string) apply_filters( 'wpcap_calendar_organizer', '' );
		return '' !== $email && is_email( $email ) ? $email : '';
	}

	/**
	 * Builds a VCALENDAR document.
	 *
	 * @param array $entries List of ['occurrence_id','start'(ts),'end'(ts),'summary','location','description','url','status',
	 *                       'sequence' (revision, default 0),'last_modified' (ts, 0 = none)].
	 * @param array $options ['name' => calendar name for feeds, 'now' => DTSTAMP timestamp,
	 *                       'method' => PUBLISH (default) or CANCEL, 'organizer' => e-mail address].
	 */
	public static function build( array $entries, array $options = array() ) {
		$now = (int) ( $options['now'] ?? time() );
		$organizer = (string) ( $options['organizer'] ?? '' );
		// RFC 5546: CANCEL needs an ORGANIZER; without one the cancelled entry is published instead.
		$method = 'CANCEL' === strtoupper( (string) ( $options['method'] ?? '' ) ) && '' !== $organizer ? 'CANCEL' : 'PUBLISH';
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID, 'CALSCALE:GREGORIAN', 'METHOD:' . $method );
		if ( ! empty( $options['name'] ) ) {
			$lines[] = 'X-WR-CALNAME:' . self::escape_text( $options['name'] );
		}
		foreach ( $entries as $entry ) {
			$cancelled = 'CANCEL' === $method || 'cancelled' === ( $entry['status'] ?? '' );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . self::uid( $entry['occurrence_id'] );
			$lines[] = 'DTSTAMP:' . self::utc( $now );
			$lines[] = 'SEQUENCE:' . max( 0, (int) ( $entry['sequence'] ?? 0 ) );
			if ( ! empty( $entry['last_modified'] ) ) { $lines[] = 'LAST-MODIFIED:' . self::utc( $entry['last_modified'] ); }
			$lines[] = 'DTSTART:' . self::utc( $entry['start'] );
			$lines[] = 'DTEND:' . self::utc( max( (int) $entry['end'], (int) $entry['start'] ) );
			$lines[] = 'SUMMARY:' . self::escape_text( $entry['summary'] );
			if ( '' !== (string) ( $entry['location'] ?? '' ) ) { $lines[] = 'LOCATION:' . self::escape_text( $entry['location'] ); }
			if ( '' !== (string) ( $entry['description'] ?? '' ) ) { $lines[] = 'DESCRIPTION:' . self::escape_text( $entry['description'] ); }
			if ( '' !== (string) ( $entry['url'] ?? '' ) ) { $lines[] = 'URL:' . str_replace( array( "\r", "\n" ), '', (string) $entry['url'] ); }
			if ( '' !== $organizer ) { $lines[] = 'ORGANIZER;CN=' . self::param_value( \PassCaptain\Orders::site_name() ) . ':mailto:' . str_replace( array( "\r", "\n" ), '', $organizer ); }
			$lines[] = 'STATUS:' . ( $cancelled ? 'CANCELLED' : 'CONFIRMED' );
			$lines[] = 'TRANSP:' . ( $cancelled ? 'TRANSPARENT' : 'OPAQUE' );
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
			'sequence' => (int) ( $row->revision ?? 0 ),
			'last_modified' => ! empty( $row->revised_at ) ? (int) strtotime( $row->revised_at . ' UTC' ) : 0,
		);
	}

	/** Occurrence columns read for calendar entries (revision columns from schema 3.9.9 on). */
	private static function columns() {
		return 'o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.location,o.status,' . ( Revisions::ready() ? 'o.revision,o.revised_at,' : '' ) . 'e.name AS event_name,e.description AS event_description';
	}

	/** Checkout link of an occurrence: configured shortcode page + card anchor. */
	public static function checkout_url( $occurrence_id ) {
		$settings = (array) Settings::get( 'calendar' );
		$base = (string) ( $settings['checkout_url'] ?? '' );
		$url = '' !== $base ? $base . '#wpcap-occ-' . absint( $occurrence_id ) : '';
		return (string) apply_filters( 'wpcap_calendar_checkout_url', $url, absint( $occurrence_id ) );
	}

	/**
	 * One occurrence of a published event, or null. Occurrences of a cancelled (formerly published)
	 * event stay available, so calendars that imported them receive STATUS:CANCELLED.
	 */
	public static function published_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::columns() . " FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d AND e.status IN ('published','cancelled')", absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column list built from literals.
	}

	/**
	 * iCalendar for one occurrence or null when it is not public. A cancelled occurrence is sent
	 * as METHOD:CANCEL when an organizer address is configured, otherwise as PUBLISH with
	 * STATUS:CANCELLED (both remove or mark the entry in clients that imported the same UID).
	 */
	public static function occurrence_calendar( $occurrence_id ) {
		$row = self::published_occurrence( $occurrence_id );
		if ( ! $row ) { return null; }
		return self::build( array( self::entry_from_row( $row ) ), array( 'organizer' => self::organizer(), 'method' => 'cancelled' === $row->status ? 'CANCEL' : 'PUBLISH' ) );
	}

	/** Feed of a published (or cancelled) event: occurrences from 30 days ago on (cancelled ones as STATUS:CANCELLED). */
	public static function event_feed( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name FROM {$p}wpcap_events WHERE event_id=%d AND status IN ('published','cancelled')", absint( $event_id ) ) );
		if ( ! $event ) { return null; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::columns() . " FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.event_id=%d AND o.starts_at>=%s ORDER BY o.starts_at LIMIT 500", (int) $event->event_id, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column list built from literals.
		return self::build( array_map( array( __CLASS__, 'entry_from_row' ), $rows ), array( 'name' => $event->name, 'organizer' => self::organizer() ) );
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
