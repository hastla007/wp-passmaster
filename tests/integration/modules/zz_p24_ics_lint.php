<?php
/**
 * PASS-FE-21 (remainder, automated part): RFC 5545 linter for every calendar file the plugin writes.
 *
 * The linter lives in this test (no external tool, no network). It checks the physical format
 * (CRLF, folding at 75 octets without split UTF-8 sequences), the content-line grammar (names,
 * parameters, no control characters), the component structure (one VCALENDAR, balanced
 * BEGIN/END, VTIMEZONE with STANDARD/DAYLIGHT), the required and once-only properties of
 * VCALENDAR and VEVENT, DATE/DATE-TIME syntax with one consistent time-zone style (UTC or TZID
 * with a matching VTIMEZONE), DTEND after DTSTART, TEXT escaping, URI values, enumerated values
 * (STATUS, TRANSP), unique UIDs and the METHOD:CANCEL rules of RFC 5546.
 *
 * Outputs checked: the .ics download per occurrence, the public event feed, the secret
 * subscription feed (CalendarFeeds), the portal download of an order, the .ics attachment of the
 * ticket mail and of change/cancellation mails (Notifications::ics_file). Cases: multi-day events,
 * a recurring series across the DST switch, full-day dates, special characters and long texts,
 * zero duration, reschedule (SEQUENCE, LAST-MODIFIED, same UID) and cancellation.
 *
 * Regressions fixed with this module (Calendar\Ics):
 * - control characters in a TEXT value (event name, location) were written unchanged (RFC 5545 3.3.11);
 * - an occurrence without duration got DTEND equal to DTSTART (3.8.2.2: DTEND must be later);
 * - a checkout URL with spaces or non-ASCII characters was written as an invalid URI (3.3.13).
 *
 * The manual acceptance in Apple Calendar, Google Calendar and Outlook stays open (X-04).
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Calendar\Ics;
use PassCaptain\Integrations\CalendarFeeds;
use PassCaptain\Modules\Calendar;
use PassCaptain\Notifications;
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;
use PassCaptain\Series\Generator;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

/* ============================================================== The linter */

/** TEXT properties checked for escaping (RFC 5545 3.3.11, RFC 7986 for the X-WR names). */
const P24_TEXT_PROPS = array( 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'COMMENT', 'CONTACT', 'X-WR-CALNAME', 'X-WR-CALDESC', 'NAME' );

/** VEVENT properties that may occur at most once (RFC 5545 3.6.1). */
const P24_EVENT_ONCE = array( 'DTSTAMP', 'UID', 'DTSTART', 'CLASS', 'CREATED', 'DESCRIPTION', 'GEO', 'LAST-MODIFIED', 'LOCATION', 'ORGANIZER', 'PRIORITY', 'SEQUENCE', 'STATUS', 'SUMMARY', 'TRANSP', 'URL', 'RECURRENCE-ID', 'DTEND', 'DURATION' );

/**
 * Parses an iCalendar document into components.
 *
 * @return array{errors:string[],calendars:array} Each component: ['name','props'=>[NAME=>[[params, value],…]],'children'=>[…]].
 */
function p24_ics_parse( $ics ) {
	$errors = array();
	if ( ! is_string( $ics ) || '' === $ics ) { return array( 'errors' => array( 'empty document' ), 'calendars' => array() ); }
	if ( 1 !== preg_match( '//u', $ics ) ) { $errors[] = 'document is not valid UTF-8'; }
	if ( "\r\n" !== substr( $ics, -2 ) ) { $errors[] = 'document does not end with CRLF'; }
	if ( preg_match( "/(?<!\r)\n|\r(?!\n)/", $ics ) ) { $errors[] = 'bare LF or CR (lines must end with CRLF)'; }
	$physical = explode( "\r\n", "\r\n" === substr( $ics, -2 ) ? substr( $ics, 0, -2 ) : $ics );
	$logical = array();
	foreach ( $physical as $i => $line ) {
		$n = $i + 1;
		if ( strlen( $line ) > 75 ) { $errors[] = "physical line $n has " . strlen( $line ) . ' octets (max. 75)'; }
		if ( 1 !== preg_match( '//u', $line ) ) { $errors[] = "physical line $n splits a UTF-8 sequence"; }
		if ( '' === $line ) { $errors[] = "physical line $n is empty"; continue; }
		if ( ' ' === $line[0] || "\t" === $line[0] ) {
			if ( ! $logical ) { $errors[] = 'document starts with a continuation line'; continue; }
			$logical[ count( $logical ) - 1 ] .= substr( $line, 1 );
		} else {
			$logical[] = $line;
		}
	}
	$root = array( 'name' => '#root', 'props' => array(), 'children' => array() );
	$stack = array( &$root );
	$param_value = '(?:"[^"\x00-\x08\x0A-\x1F\x7F]*"|[^";:,\x00-\x08\x0A-\x1F\x7F]*)';
	foreach ( $logical as $line ) {
		if ( ! preg_match( '/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=' . $param_value . '(?:,' . $param_value . ')*)*):(.*)$/s', $line, $m ) ) {
			$errors[] = 'malformed content line: ' . substr( $line, 0, 60 );
			continue;
		}
		$name = strtoupper( $m[1] );
		$value = $m[3];
		if ( preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $value ) ) { $errors[] = "$name value contains a control character"; }
		$params = array();
		if ( '' !== $m[2] && preg_match_all( '/;([A-Za-z0-9-]+)=(' . $param_value . '(?:,' . $param_value . ')*)/', $m[2], $pm, PREG_SET_ORDER ) ) {
			foreach ( $pm as $pair ) { $params[ strtoupper( $pair[1] ) ] = trim( $pair[2], '"' ); }
		}
		$top = count( $stack ) - 1;
		if ( 'BEGIN' === $name ) {
			$stack[ $top ]['children'][] = array( 'name' => strtoupper( $value ), 'props' => array(), 'children' => array() );
			$stack[] = &$stack[ $top ]['children'][ count( $stack[ $top ]['children'] ) - 1 ];
			continue;
		}
		if ( 'END' === $name ) {
			if ( 0 === $top || $stack[ $top ]['name'] !== strtoupper( $value ) ) { $errors[] = "unbalanced END:$value"; continue; }
			array_pop( $stack );
			continue;
		}
		if ( 0 === $top ) { $errors[] = "property $name outside of a component"; continue; }
		$stack[ $top ]['props'][ $name ][] = array( $params, $value );
	}
	if ( count( $stack ) > 1 ) { $errors[] = 'unclosed component ' . $stack[ count( $stack ) - 1 ]['name']; }
	return array( 'errors' => $errors, 'calendars' => $root['children'] );
}

/** Unix time of a DATE / DATE-TIME value (UTC, or local in $tzid), or null when the value is invalid. */
function p24_ics_time( $value, $is_date, $tzid = '' ) {
	if ( $is_date ) {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $d ) || ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ) { return null; }
		return gmmktime( 0, 0, 0, (int) $d[2], (int) $d[3], (int) $d[1] );
	}
	if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $d ) || ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) || (int) $d[4] > 23 || (int) $d[5] > 59 || (int) $d[6] > 60 ) { return null; }
	if ( 'Z' === $d[7] ) { return gmmktime( (int) $d[4], (int) $d[5], (int) $d[6], (int) $d[2], (int) $d[3], (int) $d[1] ); }
	try {
		return ( new DateTimeImmutable( sprintf( '%s-%s-%s %s:%s:%s', $d[1], $d[2], $d[3], $d[4], $d[5], $d[6] ), new DateTimeZone( '' !== $tzid ? $tzid : 'UTC' ) ) )->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Lints an iCalendar document against RFC 5545 (and RFC 5546 for METHOD:CANCEL).
 *
 * @return string[] Problems (empty = valid).
 */
function p24_ics_lint( $ics ) {
	$parsed = p24_ics_parse( $ics );
	$errors = $parsed['errors'];
	if ( 1 !== count( $parsed['calendars'] ) || 'VCALENDAR' !== ( $parsed['calendars'][0]['name'] ?? '' ) ) {
		$errors[] = 'document must be exactly one VCALENDAR';
		return $errors;
	}
	$cal = $parsed['calendars'][0];
	foreach ( array( 'VERSION', 'PRODID' ) as $required ) {
		if ( 1 !== count( $cal['props'][ $required ] ?? array() ) ) { $errors[] = "VCALENDAR needs exactly one $required"; }
	}
	if ( '2.0' !== ( $cal['props']['VERSION'][0][1] ?? '' ) ) { $errors[] = 'VERSION must be 2.0'; }
	foreach ( array( 'CALSCALE', 'METHOD' ) as $once ) {
		if ( count( $cal['props'][ $once ] ?? array() ) > 1 ) { $errors[] = "VCALENDAR has more than one $once"; }
	}
	if ( isset( $cal['props']['CALSCALE'] ) && 'GREGORIAN' !== $cal['props']['CALSCALE'][0][1] ) { $errors[] = 'CALSCALE must be GREGORIAN'; }
	$method = strtoupper( (string) ( $cal['props']['METHOD'][0][1] ?? '' ) );
	if ( isset( $cal['props']['METHOD'] ) && ! preg_match( '/^[A-Z0-9-]+$/', $method ) ) { $errors[] = 'METHOD is not an iana-token'; }
	foreach ( array( 'UID', 'DTSTART', 'DTEND', 'SUMMARY', 'DTSTAMP' ) as $event_prop ) {
		if ( isset( $cal['props'][ $event_prop ] ) ) { $errors[] = "$event_prop outside of a VEVENT"; }
	}
	$timezones = array();
	$events = array();
	foreach ( $cal['children'] as $child ) {
		if ( 'VTIMEZONE' === $child['name'] ) {
			$tzid = (string) ( $child['props']['TZID'][0][1] ?? '' );
			if ( '' === $tzid ) { $errors[] = 'VTIMEZONE without TZID'; }
			$rules = array_filter( $child['children'], static fn( $c ) => in_array( $c['name'], array( 'STANDARD', 'DAYLIGHT' ), true ) );
			if ( ! $rules ) { $errors[] = "VTIMEZONE $tzid without STANDARD/DAYLIGHT"; }
			foreach ( $rules as $rule ) {
				foreach ( array( 'DTSTART', 'TZOFFSETFROM', 'TZOFFSETTO' ) as $required ) {
					if ( 1 !== count( $rule['props'][ $required ] ?? array() ) ) { $errors[] = "VTIMEZONE $tzid {$rule['name']} needs exactly one $required"; }
				}
			}
			$timezones[ $tzid ] = true;
		} elseif ( 'VEVENT' === $child['name'] ) {
			$events[] = $child;
		} elseif ( ! in_array( $child['name'], array( 'VTODO', 'VJOURNAL', 'VFREEBUSY' ), true ) && 0 !== strpos( $child['name'], 'X-' ) ) {
			$errors[] = "unknown component {$child['name']} in VCALENDAR";
		}
	}
	$styles = array();
	$uids = array();
	foreach ( $events as $n => $event ) {
		$props = $event['props'];
		$label = 'VEVENT ' . ( $n + 1 );
		foreach ( array( 'UID', 'DTSTAMP' ) as $required ) {
			if ( 1 !== count( $props[ $required ] ?? array() ) ) { $errors[] = "$label needs exactly one $required"; }
		}
		if ( empty( $props['DTSTART'] ) ) { $errors[] = "$label without DTSTART"; }
		foreach ( P24_EVENT_ONCE as $once ) {
			if ( count( $props[ $once ] ?? array() ) > 1 ) { $errors[] = "$label has more than one $once"; }
		}
		if ( isset( $props['DTEND'], $props['DURATION'] ) ) { $errors[] = "$label has DTEND and DURATION"; }
		$uid = (string) ( $props['UID'][0][1] ?? '' );
		if ( isset( $props['UID'] ) && '' === $uid ) { $errors[] = "$label has an empty UID"; }
		if ( '' !== $uid && ! isset( $props['RECURRENCE-ID'] ) ) {
			if ( isset( $uids[ $uid ] ) ) { $errors[] = "$label repeats UID $uid"; }
			$uids[ $uid ] = true;
		}
		// DTSTAMP, CREATED and LAST-MODIFIED are UTC by definition (3.8.7).
		foreach ( array( 'DTSTAMP', 'CREATED', 'LAST-MODIFIED' ) as $utc_prop ) {
			foreach ( $props[ $utc_prop ] ?? array() as $prop ) {
				if ( ! empty( $prop[0] ) || ! preg_match( '/Z$/', $prop[1] ) || null === p24_ics_time( $prop[1], false ) ) { $errors[] = "$label $utc_prop must be a UTC DATE-TIME: {$prop[1]}"; }
			}
		}
		$times = array();
		foreach ( array( 'DTSTART', 'DTEND' ) as $time_prop ) {
			if ( empty( $props[ $time_prop ] ) ) { continue; }
			list( $params, $value ) = $props[ $time_prop ][0];
			$is_date = 'DATE' === strtoupper( (string) ( $params['VALUE'] ?? '' ) );
			$tzid = (string) ( $params['TZID'] ?? '' );
			if ( $is_date ) {
				$style = 'DATE';
				if ( '' !== $tzid ) { $errors[] = "$label $time_prop: VALUE=DATE with TZID"; }
			} elseif ( '' !== $tzid ) {
				$style = 'TZID';
				if ( ! isset( $timezones[ $tzid ] ) ) { $errors[] = "$label $time_prop uses TZID $tzid without VTIMEZONE"; }
				if ( 'Z' === substr( $value, -1 ) ) { $errors[] = "$label $time_prop: UTC value with TZID"; }
			} elseif ( 'Z' === substr( $value, -1 ) ) {
				$style = 'UTC';
			} else {
				$style = 'floating';
				$errors[] = "$label $time_prop is a floating time (neither UTC nor TZID): $value";
			}
			$time = p24_ics_time( $value, $is_date, $tzid );
			if ( null === $time ) { $errors[] = "$label $time_prop is not a valid " . ( $is_date ? 'DATE' : 'DATE-TIME' ) . ": $value"; }
			$times[ $time_prop ] = array( $time, $is_date );
			if ( ! $is_date ) { $styles[ $style ] = true; }
		}
		if ( isset( $times['DTSTART'], $times['DTEND'] ) ) {
			if ( $times['DTSTART'][1] !== $times['DTEND'][1] ) { $errors[] = "$label DTEND has another value type than DTSTART"; }
			if ( null !== $times['DTSTART'][0] && null !== $times['DTEND'][0] && $times['DTEND'][0] <= $times['DTSTART'][0] ) { $errors[] = "$label DTEND is not later than DTSTART"; }
		}
		if ( isset( $props['SEQUENCE'] ) && ! preg_match( '/^\d+$/', $props['SEQUENCE'][0][1] ) ) { $errors[] = "$label SEQUENCE is not a non-negative integer"; }
		if ( isset( $props['STATUS'] ) && ! in_array( $props['STATUS'][0][1], array( 'TENTATIVE', 'CONFIRMED', 'CANCELLED' ), true ) ) { $errors[] = "$label STATUS {$props['STATUS'][0][1]} is not valid for a VEVENT"; }
		if ( isset( $props['TRANSP'] ) && ! in_array( $props['TRANSP'][0][1], array( 'OPAQUE', 'TRANSPARENT' ), true ) ) { $errors[] = "$label TRANSP is invalid"; }
		if ( isset( $props['URL'] ) && ! preg_match( '/^[A-Za-z][A-Za-z0-9+.-]*:[\x21-\x7E]+$/', $props['URL'][0][1] ) ) { $errors[] = "$label URL is not a valid URI: {$props['URL'][0][1]}"; }
		if ( isset( $props['ORGANIZER'] ) && ! preg_match( '/^mailto:[^@\s]+@[^@\s]+$/i', $props['ORGANIZER'][0][1] ) ) { $errors[] = "$label ORGANIZER is not a mailto: address"; }
		if ( 'CANCEL' === $method ) {
			if ( empty( $props['ORGANIZER'] ) ) { $errors[] = "$label: METHOD:CANCEL needs ORGANIZER (RFC 5546 3.2.5)"; }
			if ( empty( $props['SEQUENCE'] ) ) { $errors[] = "$label: METHOD:CANCEL needs SEQUENCE"; }
			if ( 'CANCELLED' !== ( $props['STATUS'][0][1] ?? '' ) ) { $errors[] = "$label: METHOD:CANCEL needs STATUS:CANCELLED"; }
		}
	}
	if ( count( $styles ) > 1 ) { $errors[] = 'inconsistent time styles: ' . implode( ', ', array_keys( $styles ) ); }
	// TEXT escaping everywhere (calendar and components).
	$walk = static function ( $component ) use ( &$walk, &$errors ) {
		foreach ( $component['props'] as $name => $list ) {
			if ( ! in_array( $name, P24_TEXT_PROPS, true ) ) { continue; }
			foreach ( $list as $prop ) {
				$rest = preg_replace( '/\\\\[\\\\;,nN]/', '', $prop[1] );
				if ( false !== strpbrk( $rest, ';,\\' ) ) { $errors[] = "unescaped ; , or \\ in $name: " . substr( $prop[1], 0, 60 ); }
			}
		}
		foreach ( $component['children'] as $child ) { $walk( $child ); }
	};
	$walk( $cal );
	return $errors;
}

/** VEVENTs of a document as lists of properties (first value per name, TEXT unescaped). */
function p24_ics_events( $ics ) {
	$parsed = p24_ics_parse( $ics );
	$out = array();
	foreach ( $parsed['calendars'][0]['children'] ?? array() as $child ) {
		if ( 'VEVENT' !== $child['name'] ) { continue; }
		$event = array();
		foreach ( $child['props'] as $name => $list ) {
			$value = $list[0][1];
			if ( in_array( $name, P24_TEXT_PROPS, true ) ) {
				$value = preg_replace_callback( '/\\\\([\\\\;,nN])/', static fn( $m ) => in_array( $m[1], array( 'n', 'N' ), true ) ? "\n" : $m[1], $value );
			}
			$event[ $name ] = $value;
		}
		$out[] = $event;
	}
	return $out;
}

/** Calendar property value (first). */
function p24_ics_calprop( $ics, $name ) {
	$parsed = p24_ics_parse( $ics );
	return (string) ( $parsed['calendars'][0]['props'][ $name ][0][1] ?? '' );
}

/** Local wall-clock time "Y-m-d H:i" of a UTC DATE-TIME value in $tz. */
function p24_local( $value, $tz = 'Europe/Vienna' ) {
	$ts = p24_ics_time( $value, false );
	return null === $ts ? '' : ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'Y-m-d H:i' );
}

/** check() with the linter's findings printed on failure. */
function p24_check_lint( $name, $ics ) {
	$errors = p24_ics_lint( $ics );
	if ( $errors ) { echo '       ' . implode( "\n       ", array_slice( $errors, 0, 8 ) ) . "\n"; }
	check( $name, array() === $errors );
}

/* ============================================================== Linter self-test */

echo "PASS-FE-21: RFC 5545 linter self-test\n";
$p24_ok = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//T//T//DE\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:a@x\r\nDTSTAMP:20270101T000000Z\r\nSEQUENCE:0\r\nDTSTART:20270101T100000Z\r\nDTEND:20270101T110000Z\r\nSUMMARY:a\\, b\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$p24_tz = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Vienna\r\nBEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nUID:b@x\r\nDTSTAMP:20270101T000000Z\r\nDTSTART;TZID=Europe/Vienna:20270101T100000\r\nDTEND;TZID=Europe/Vienna:20270101T110000\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:c@x\r\nDTSTAMP:20270101T000000Z\r\nDTSTART;VALUE=DATE:20270102\r\nDTEND;VALUE=DATE:20270103\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
check( 'a valid UTC document and a valid TZID/VTIMEZONE + all-day (VALUE=DATE) document pass', array() === p24_ics_lint( $p24_ok ) && array() === p24_ics_lint( $p24_tz ) );
$p24_bad = array(
	'bare LF' => str_replace( "\r\n", "\n", $p24_ok ),
	'line > 75 octets' => str_replace( 'SUMMARY:a\\, b', 'SUMMARY:' . str_repeat( 'x', 70 ), $p24_ok ),
	'split UTF-8 sequence at a fold' => str_replace( 'SUMMARY:a\\, b', 'SUMMARY:' . substr( 'ä', 0, 1 ) . "\r\n " . substr( 'ä', 1 ), $p24_ok ),
	'unescaped comma' => str_replace( 'SUMMARY:a\\, b', 'SUMMARY:a, b', $p24_ok ),
	'unknown escape' => str_replace( 'SUMMARY:a\\, b', 'SUMMARY:a\\x', $p24_ok ),
	'control character' => str_replace( 'SUMMARY:a\\, b', "SUMMARY:a\x07b", $p24_ok ),
	'missing UID' => str_replace( "UID:a@x\r\n", '', $p24_ok ),
	'missing DTSTAMP' => str_replace( "DTSTAMP:20270101T000000Z\r\n", '', $p24_ok ),
	'local DTSTAMP' => str_replace( 'DTSTAMP:20270101T000000Z', 'DTSTAMP:20270101T000000', $p24_ok ),
	'floating DTSTART' => str_replace( 'DTSTART:20270101T100000Z', 'DTSTART:20270101T100000', $p24_ok ),
	'TZID without VTIMEZONE' => str_replace( 'DTSTART:20270101T100000Z', 'DTSTART;TZID=Europe/Vienna:20270101T100000', $p24_ok ),
	'DTEND equal to DTSTART' => str_replace( 'DTEND:20270101T110000Z', 'DTEND:20270101T100000Z', $p24_ok ),
	'DTEND of another value type' => str_replace( 'DTEND:20270101T110000Z', 'DTEND;VALUE=DATE:20270102', $p24_ok ),
	'invalid date' => str_replace( 'DTSTART:20270101T100000Z', 'DTSTART:20270231T100000Z', $p24_ok ),
	'two SUMMARY' => str_replace( "SUMMARY:a\\, b\r\n", "SUMMARY:a\r\nSUMMARY:b\r\n", $p24_ok ),
	'DTEND and DURATION' => str_replace( "SUMMARY:a\\, b\r\n", "DURATION:PT1H\r\n", $p24_ok ),
	'bad STATUS' => str_replace( "SUMMARY:a\\, b\r\n", "STATUS:DONE\r\n", $p24_ok ),
	'URI with a space' => str_replace( "SUMMARY:a\\, b\r\n", "URL:https://x.test/a b\r\n", $p24_ok ),
	'duplicate UID' => str_replace( "END:VEVENT\r\nEND:VCALENDAR", "END:VEVENT\r\nBEGIN:VEVENT\r\nUID:a@x\r\nDTSTAMP:20270101T000000Z\r\nDTSTART:20270101T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR", $p24_ok ),
	'CANCEL without ORGANIZER/STATUS' => str_replace( 'METHOD:PUBLISH', 'METHOD:CANCEL', $p24_ok ),
	'unbalanced END' => str_replace( "END:VEVENT\r\n", "END:VTODO\r\n", $p24_ok ),
	'missing VERSION' => str_replace( "VERSION:2.0\r\n", '', $p24_ok ),
	'mixed UTC and TZID' => str_replace( "DTSTART;VALUE=DATE:20270102\r\nDTEND;VALUE=DATE:20270103", "DTSTART:20270102T100000Z", $p24_tz ),
	'malformed parameter' => str_replace( 'DTSTART:20270101T100000Z', 'DTSTART;TZID:20270101T100000Z', $p24_ok ),
);
$p24_missed = array_keys( array_filter( $p24_bad, static fn( $doc ) => array() === p24_ics_lint( $doc ) ) );
if ( $p24_missed ) { echo '       not detected: ' . implode( ', ', $p24_missed ) . "\n"; }
check( 'the linter detects each of ' . count( $p24_bad ) . ' seeded violations (format, escaping, required/once-only properties, time styles, DTEND, URI, UID, CANCEL)', ! $p24_missed );

/* ============================================================== Fixtures */

if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { $GLOBALS['wpcap_test_shortcodes'][ $tag ] = $cb; } }
\PassCaptain\Modules\Series::register();
Calendar::register();
$p24_cal_saved = Settings::get( 'calendar' );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/tickets/', 'attach_ics' => 1, 'first_weekday' => 1 ) ) );
$p24_utc = static fn( $local, $tz = 'Europe/Vienna' ) => ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$p24_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => "Sommer, Fest; Teil \\2: \"Live\" \x07🎉 – " . str_repeat( 'Größenwahn ', 6 ), 'slug' => 'p24-' . uniqid(), 'description' => "<p>Drei Tage, zwei Bühnen; ein \\ Pfad.</p>\n<p>Zeile zwei mit Umlauten äöü und 😀 sowie einem sehr langen Text, der gefaltet werden muss.</p>", 'status' => 'published', 'created_at' => $p24_now, 'updated_at' => $p24_now ) );
$p24_event = (int) $wpdb->insert_id;
$p24_add_occ = static function ( $starts, $ends, $location = 'Wien' ) use ( $wpdb, $p, $p24_event ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $p24_event, 'starts_at' => $starts, 'ends_at' => $ends, 'timezone' => 'Europe/Vienna', 'location' => $location, 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( $occ, (int) $wpdb->insert_id );
};

/* ============================================================== Single download per occurrence */

echo "PASS-FE-21: .ics download per occurrence (special characters, multi-day, full days, DST, zero duration)\n";
// Multi-day festival across the autumn DST switch: Fri 29.10.2027 18:00 → Sun 31.10.2027 12:00 Vienna (CEST → CET).
list( $p24_multi, $p24_multi_type ) = $p24_add_occ( $p24_utc( '2027-10-29 18:00' ), $p24_utc( '2027-10-31 12:00' ), "Halle 1; Eingang B, Hof \\ Nord\x01" );
$p24_ics = (string) Ics::occurrence_calendar( $p24_multi );
p24_check_lint( 'occurrence download with commas, semicolons, backslashes, quotes, emoji and control characters is valid', $p24_ics );
$p24_e = p24_ics_events( $p24_ics )[0] ?? array();
check( 'SUMMARY and LOCATION round-trip (control characters removed, everything else kept)', "Sommer, Fest; Teil \\2: \"Live\" 🎉 – " . str_repeat( 'Größenwahn ', 6 ) === ( $p24_e['SUMMARY'] ?? '' ) && 'Halle 1; Eingang B, Hof \\ Nord' === ( $p24_e['LOCATION'] ?? '' ) );
check( 'DESCRIPTION is plain text with escaped separators and the checkout link', false === strpos( $p24_e['DESCRIPTION'] ?? '<', '<' ) && false !== strpos( $p24_e['DESCRIPTION'] ?? '', 'Drei Tage, zwei Bühnen; ein \\ Pfad.' ) && false !== strpos( $p24_e['DESCRIPTION'] ?? '', 'https://example.test/tickets/?wpcap_occurrence=' . $p24_multi ) );
check( 'multi-day: DTSTART/DTEND in UTC give the local wall-clock times on both sides of the DST switch (61 h)', '2027-10-29 18:00' === p24_local( $p24_e['DTSTART'] ?? '' ) && '2027-10-31 12:00' === p24_local( $p24_e['DTEND'] ?? '' ) && '20271029T160000Z' === ( $p24_e['DTSTART'] ?? '' ) && '20271031T110000Z' === ( $p24_e['DTEND'] ?? '' ) );
check( 'stable UID per occurrence, SEQUENCE, DTSTAMP, STATUS:CONFIRMED, METHOD:PUBLISH', 'wpcap-occurrence-' . $p24_multi . '@example.test' === ( $p24_e['UID'] ?? '' ) && '0' === ( $p24_e['SEQUENCE'] ?? '' ) && 1 === preg_match( '/^\d{8}T\d{6}Z$/', $p24_e['DTSTAMP'] ?? '' ) && 'CONFIRMED' === ( $p24_e['STATUS'] ?? '' ) && 'PUBLISH' === p24_ics_calprop( $p24_ics, 'METHOD' ) );
check( 'a second download differs only in DTSTAMP (same UID, same content)', preg_replace( '/DTSTAMP:\S+/', '', $p24_ics ) === preg_replace( '/DTSTAMP:\S+/', '', (string) Ics::occurrence_calendar( $p24_multi ) ) );
check( 'time-zone style is consistent: UTC throughout, no TZID and no VTIMEZONE', false === strpos( $p24_ics, 'TZID' ) && false === strpos( $p24_ics, 'VTIMEZONE' ) );

// Full days (the plugin has no all-day flag): 00:00 → 00:00 local over two days, written as UTC DATE-TIME.
list( $p24_days ) = $p24_add_occ( $p24_utc( '2027-03-27 00:00' ), $p24_utc( '2027-03-29 00:00' ) );
$p24_ics = (string) Ics::occurrence_calendar( $p24_days );
$p24_e = p24_ics_events( $p24_ics )[0] ?? array();
p24_check_lint( 'full-day occurrence across the spring DST switch is valid', $p24_ics );
check( 'full days: midnight to midnight local on both sides of the spring switch (47 h)', '2027-03-27 00:00' === p24_local( $p24_e['DTSTART'] ?? '' ) && '2027-03-29 00:00' === p24_local( $p24_e['DTEND'] ?? '' ) && 47 * 3600 === p24_ics_time( $p24_e['DTEND'] ?? '', false ) - p24_ics_time( $p24_e['DTSTART'] ?? '', false ) );

// Zero duration (regression): no end and a default duration of 0, or an end equal to the start.
list( $p24_zero ) = $p24_add_occ( $p24_utc( '2027-05-01 20:00' ), null );
$p24_zero_duration = static fn() => 0;
add_filter( 'wpcap_calendar_default_duration', $p24_zero_duration );
$p24_ics = (string) Ics::occurrence_calendar( $p24_zero );
remove_filter( 'wpcap_calendar_default_duration', $p24_zero_duration );
p24_check_lint( 'zero default duration: valid (DTEND left out instead of DTEND = DTSTART)', $p24_ics );
check( 'zero duration: DTSTART kept, no DTEND', ! isset( p24_ics_events( $p24_ics )[0]['DTEND'] ) && isset( p24_ics_events( $p24_ics )[0]['DTSTART'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET ends_at=starts_at WHERE occurrence_id=%d", $p24_zero ) );
p24_check_lint( 'end equal to the start (imported data): valid', (string) Ics::occurrence_calendar( $p24_zero ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET ends_at=NULL WHERE occurrence_id=%d", $p24_zero ) );
check( 'without filter the default duration of 2 h applies', 7200 === p24_ics_time( p24_ics_events( (string) Ics::occurrence_calendar( $p24_zero ) )[0]['DTEND'] ?? '', false ) - p24_ics_time( p24_ics_events( (string) Ics::occurrence_calendar( $p24_zero ) )[0]['DTSTART'] ?? '', false ) );

// URI value (regression): a checkout page with spaces / non-ASCII characters.
$p24_url_filter = static fn( $url, $id ) => 'https://example.test/tickets für alle/?wpcap_occurrence=' . $id . '#wpcap-occ-' . $id;
add_filter( 'wpcap_calendar_checkout_url', $p24_url_filter, 10, 2 );
$p24_ics = (string) Ics::occurrence_calendar( $p24_zero );
remove_filter( 'wpcap_calendar_checkout_url', $p24_url_filter, 10 );
p24_check_lint( 'checkout URL with a space and an umlaut: URL is a valid URI', $p24_ics );
check( 'URL is percent-encoded (UTF-8), query and anchor kept', 'https://example.test/tickets%20f%C3%BCr%20alle/?wpcap_occurrence=' . $p24_zero . '#wpcap-occ-' . $p24_zero === ( p24_ics_events( $p24_ics )[0]['URL'] ?? '' ) );

/* ============================================================== Series + feeds */

echo "PASS-FE-21: recurring series across the DST switch in the event feed\n";
list( $p24_tpl ) = $p24_add_occ( $p24_utc( '2027-03-13 19:00' ), $p24_utc( '2027-03-13 21:00' ), 'Stadtsaal' );
$p24_series = Generator::create( array( 'event_id' => $p24_event, 'template_occurrence_id' => $p24_tpl, 'freq' => 'weekly', 'weekdays' => array( 6 ), 'start_date' => '2027-03-20', 'count' => 4, 'time' => '19:00', 'duration_minutes' => 120, 'timezone' => 'Europe/Vienna', 'name' => 'P24 Frühjahr' ) );
check( 'series fixture: 4 weekly occurrences created', ! is_wp_error( $p24_series ) && 4 === count( $p24_series['created'] ?? array() ) );
$p24_series_ids = is_wp_error( $p24_series ) ? array() : array_map( 'intval', $p24_series['created'] );
$p24_feed = (string) Ics::event_feed( $p24_event );
p24_check_lint( 'event feed with the series, the multi-day and the full-day dates is valid', $p24_feed );
$p24_by_uid = array();
foreach ( p24_ics_events( $p24_feed ) as $p24_e ) { $p24_by_uid[ $p24_e['UID'] ] = $p24_e; }
$p24_series_local = array();
foreach ( $p24_series_ids as $p24_id ) { $p24_series_local[] = p24_local( $p24_by_uid[ 'wpcap-occurrence-' . $p24_id . '@example.test' ]['DTSTART'] ?? '' ); }
sort( $p24_series_local );
check( 'every series date is its own VEVENT with its own stable UID', 4 === count( array_intersect_key( $p24_by_uid, array_flip( array_map( static fn( $id ) => 'wpcap-occurrence-' . $id . '@example.test', $p24_series_ids ) ) ) ) );
check( 'series keeps 19:00 local across the spring switch (UTC 18:00 → 17:00)', array( '2027-03-20 19:00', '2027-03-27 19:00', '2027-04-03 19:00', '2027-04-10 19:00' ) === $p24_series_local && '20270403T170000Z' === ( $p24_by_uid[ 'wpcap-occurrence-' . ( $p24_series_ids[2] ?? 0 ) . '@example.test' ]['DTSTART'] ?? '' ) );
check( 'feed has the calendar name (escaped TEXT) and the same entry as the single download', '' !== p24_ics_calprop( $p24_feed, 'X-WR-CALNAME' ) && isset( $p24_by_uid[ 'wpcap-occurrence-' . $p24_multi . '@example.test' ] ) && p24_ics_events( (string) Ics::occurrence_calendar( $p24_multi ) )[0]['DTEND'] === $p24_by_uid[ 'wpcap-occurrence-' . $p24_multi . '@example.test' ]['DTEND'] );

$p24_sub = CalendarFeeds::create( 'event', $p24_event, 'P24, Presse; intern' );
$p24_sub_ics = is_array( $p24_sub ) ? (string) CalendarFeeds::render( $p24_sub['token'] ) : '';
p24_check_lint( 'secret subscription feed (CalendarFeeds) is valid, incl. REFRESH-INTERVAL and LAST-MODIFIED', $p24_sub_ics );
check( 'subscription feed: same UIDs as the public feed, escaped label', is_array( $p24_sub ) && array_keys( $p24_by_uid ) == array_column( p24_ics_events( $p24_sub_ics ), 'UID' ) && 'P24, Presse; intern' === preg_replace_callback( '/\\\\([\\\\;,])/', static fn( $m ) => $m[1], p24_ics_calprop( $p24_sub_ics, 'X-WR-CALNAME' ) ) );

/* ============================================================== Reschedule + cancellation */

echo "PASS-FE-21: reschedule (SEQUENCE) and cancellation (STATUS:CANCELLED / METHOD:CANCEL)\n";
$p24_moved = $p24_series_ids[1] ?? 0;
$p24_before = p24_ics_events( (string) Ics::occurrence_calendar( $p24_moved ) )[0] ?? array();
$p24_r = EventsPage::save_occurrence( array( 'occurrence_id' => $p24_moved, 'starts_at' => '2027-03-28 18:30', 'ends_at' => '2027-03-28 20:30', 'timezone' => 'Europe/Vienna', 'location' => 'Stadtsaal, Bühne 2', 'capacity' => '0' ) );
$p24_after_ics = (string) Ics::occurrence_calendar( $p24_moved );
$p24_after = p24_ics_events( $p24_after_ics )[0] ?? array();
p24_check_lint( 'rescheduled occurrence download is valid', $p24_after_ics );
check( 'reschedule: same UID, SEQUENCE + 1, LAST-MODIFIED set, new local start on the DST day', ! is_wp_error( $p24_r ) && ( $p24_before['UID'] ?? '' ) === ( $p24_after['UID'] ?? 'x' ) && (int) ( $p24_before['SEQUENCE'] ?? -1 ) + 1 === (int) ( $p24_after['SEQUENCE'] ?? 0 ) && '' !== ( $p24_after['LAST-MODIFIED'] ?? '' ) && '2027-03-28 18:30' === p24_local( $p24_after['DTSTART'] ?? '' ) && 'Stadtsaal, Bühne 2' === ( $p24_after['LOCATION'] ?? '' ) );
$p24_sub_after = is_array( $p24_sub ) ? (string) CalendarFeeds::render( $p24_sub['token'] ) : '';
$p24_sub_moved = array_values( array_filter( p24_ics_events( $p24_sub_after ), static fn( $e ) => $e['UID'] === ( $p24_after['UID'] ?? '' ) ) )[0] ?? array();
p24_check_lint( 'subscription feed after the change is valid', $p24_sub_after );
check( 'subscription feed raises SEQUENCE for the moved date and shows the new start', (int) ( $p24_sub_moved['SEQUENCE'] ?? 0 ) >= 1 && ( $p24_after['DTSTART'] ?? '' ) === ( $p24_sub_moved['DTSTART'] ?? 'x' ) );

$p24_cancelled = $p24_series_ids[3] ?? 0;
$p24_c = EventsPage::cancel_occurrence( array( 'occurrence_id' => $p24_cancelled, 'reason' => 'Unwetter', 'confirm' => '1' ) );
$p24_c_ics = (string) Ics::occurrence_calendar( $p24_cancelled );
$p24_c_e = p24_ics_events( $p24_c_ics )[0] ?? array();
p24_check_lint( 'cancelled occurrence without organizer: valid METHOD:PUBLISH file', $p24_c_ics );
check( 'cancelled without organizer: STATUS:CANCELLED, TRANSPARENT, SEQUENCE raised, same UID', ! is_wp_error( $p24_c ) && 'PUBLISH' === p24_ics_calprop( $p24_c_ics, 'METHOD' ) && 'CANCELLED' === ( $p24_c_e['STATUS'] ?? '' ) && 'TRANSPARENT' === ( $p24_c_e['TRANSP'] ?? '' ) && (int) ( $p24_c_e['SEQUENCE'] ?? 0 ) >= 1 && 'wpcap-occurrence-' . $p24_cancelled . '@example.test' === ( $p24_c_e['UID'] ?? '' ) );
$p24_org = static fn() => 'tickets@example.test';
add_filter( 'wpcap_calendar_organizer', $p24_org );
$p24_c_ics = (string) Ics::occurrence_calendar( $p24_cancelled );
p24_check_lint( 'cancelled occurrence with organizer: valid METHOD:CANCEL file (RFC 5546: ORGANIZER, SEQUENCE, STATUS)', $p24_c_ics );
check( 'METHOD:CANCEL with ORGANIZER mailto', 'CANCEL' === p24_ics_calprop( $p24_c_ics, 'METHOD' ) && 'mailto:tickets@example.test' === ( p24_ics_events( $p24_c_ics )[0]['ORGANIZER'] ?? '' ) );
$p24_feed = (string) Ics::event_feed( $p24_event );
p24_check_lint( 'event feed with organizer and a cancelled date is valid (stays METHOD:PUBLISH)', $p24_feed );
check( 'feed keeps METHOD:PUBLISH and marks only the cancelled date', 'PUBLISH' === p24_ics_calprop( $p24_feed, 'METHOD' ) && 1 === substr_count( $p24_feed, 'STATUS:CANCELLED' ) );
remove_filter( 'wpcap_calendar_organizer', $p24_org );

/* ============================================================== Mail attachments + portal */

echo "PASS-FE-21: .ics attachments in mails and the portal download\n";
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_mails'] = array();
$p24_mail_to = 'p24-ics-' . uniqid() . '@example.com';
$p24_types = $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id IN (%d,%d) ORDER BY ticket_type_id", $p24_multi, $p24_moved ) );
$p24_order = Orders::create( array( 'email' => $p24_mail_to, 'items' => array_map( static fn( $t ) => array( 'ticket_type_id' => (int) $t, 'quantity' => 1 ), $p24_types ), 'idempotency_key' => 'p24-' . uniqid() ) );
$p24_files = array();
foreach ( $GLOBALS['wpcap_test_mails'] as $p24_m ) {
	if ( $p24_mail_to !== $p24_m['to'] ) { continue; }
	foreach ( (array) ( $p24_m['files'] ?? array() ) as $p24_name => $p24_content ) { if ( '.ics' === substr( $p24_name, -4 ) ) { $p24_files[] = $p24_content; } }
}
check( 'ticket mail carries one .ics per occurrence (2)', ! is_wp_error( $p24_order ) && 2 === count( $p24_files ) );
$p24_lint = array();
foreach ( $p24_files as $p24_content ) { $p24_lint = array_merge( $p24_lint, p24_ics_lint( $p24_content ) ); }
if ( $p24_lint ) { echo '       ' . implode( "\n       ", array_slice( $p24_lint, 0, 8 ) ) . "\n"; }
check( 'every .ics attachment of the ticket mail is valid', $p24_files && ! $p24_lint );
$p24_file = Notifications::ics_file( $p24_moved );
$p24_file_ics = $p24_file && is_file( $p24_file[0] ) ? (string) file_get_contents( $p24_file[0] ) : '';
foreach ( $p24_file as $p24_f ) { @unlink( $p24_f ); }
p24_check_lint( 'the .ics of change and cancellation mails (Notifications::ics_file) is valid', $p24_file_ics );
check( 'change-mail .ics carries the current SEQUENCE', ( $p24_after['SEQUENCE'] ?? 'x' ) === ( p24_ics_events( $p24_file_ics )[0]['SEQUENCE'] ?? '' ) );

$p24_token = bin2hex( random_bytes( 32 ) );
$p24_oid = is_wp_error( $p24_order ) ? 0 : (int) $p24_order['order_id'];
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $p24_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'order_id' => $p24_oid ) );
$GLOBALS['wpcap_test_transients'] = array();
$p24_portal = PortalSelfService::ics( (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=%d", $p24_oid ) ), $p24_token );
$p24_portal_ics = is_array( $p24_portal ) ? (string) $p24_portal['content'] : '';
p24_check_lint( 'portal download of the order (two dates) is valid', $p24_portal_ics );
check( 'portal download: both dates in start order with the UIDs of the single downloads, no buyer data', 2 === count( p24_ics_events( $p24_portal_ics ) ) && array( 'wpcap-occurrence-' . $p24_moved . '@example.test', 'wpcap-occurrence-' . $p24_multi . '@example.test' ) == array_column( p24_ics_events( $p24_portal_ics ), 'UID' ) && false === strpos( $p24_portal_ics, $p24_mail_to ) );

/* ============================================================== Cleanup */

if ( is_array( $p24_sub ) ) { $wpdb->delete( "{$p}wpcap_calendar_feeds", array( 'feed_id' => (int) $p24_sub['feed_id'] ) ); }
Settings::update( array( 'calendar' => is_array( $p24_cal_saved ) ? $p24_cal_saved : array() ) );
// Later modules run without the calendar module's mail hooks (see calendar.php).
remove_filter( 'wpcap_ticket_mail', array( Calendar::class, 'attach_ics' ), 20 );
remove_filter( 'wpcap_ticket_resend_attachments', array( Calendar::class, 'resend_ics' ), 20 );
remove_filter( 'wpcap_event_card_html', array( Calendar::class, 'add_card_anchor' ), 5 );
$GLOBALS['wpcap_test_transients'] = array();
