<?php
/**
 * A140: iCalendar updates and change notifications per occurrence.
 * - ICS validity: CRLF, folding at 75 octets (UTF-8 safe), TEXT escaping, UTC/TZID rules,
 *   required VEVENT properties, METHOD/ORGANIZER.
 * - Revision counter (SEQUENCE / LAST-MODIFIED) on every write path: occurrence form, cancellation,
 *   event cancellation, series update.
 * - Change mails: one mail per save (time only → occurrence_rescheduled, otherwise one combined
 *   occurrence_changed), .ics attached with the new SEQUENCE, idempotent per revision, per-event opt-out.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Queries;
use PassCaptain\Calendar\Ics;
use PassCaptain\Calendar\Revisions;
use PassCaptain\Mail\Mailer;
use PassCaptain\Notifications;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

/**
 * Validates an iCalendar document against the RFC 5545 rules the plugin promises.
 *
 * @return string[] Problems (empty = valid).
 */
function a140_validate_ics( $ics ) {
	$errors = array();
	if ( ! is_string( $ics ) || '' === $ics ) { return array( 'empty' ); }
	if ( "\r\n" !== substr( $ics, -2 ) ) { $errors[] = 'does not end with CRLF'; }
	if ( preg_match( "/(?<!\r)\n|\r(?!\n)/", $ics ) ) { $errors[] = 'bare LF or CR'; }
	$physical = explode( "\r\n", substr( $ics, 0, -2 ) );
	foreach ( $physical as $i => $line ) {
		if ( strlen( $line ) > 75 ) { $errors[] = "line $i longer than 75 octets"; }
		if ( 1 !== preg_match( '//u', $line ) ) { $errors[] = "line $i is not valid UTF-8 (split sequence)"; }
		if ( '' === $line ) { $errors[] = "line $i empty"; }
	}
	$lines = explode( "\r\n", preg_replace( "/\r\n[ \t]/", '', substr( $ics, 0, -2 ) ) );
	$stack = array();
	$timezones = array();
	$events = array();
	$current = null;
	$method = '';
	foreach ( $lines as $line ) {
		if ( ! preg_match( '/^([A-Z][A-Z0-9-]*)((?:;[A-Z0-9-]+=(?:"[^"]*"|[^";:,]*))*):(.*)$/s', $line, $m ) ) { $errors[] = "malformed content line: $line"; continue; }
		list( , $name, $params, $value ) = $m;
		if ( 'BEGIN' === $name ) { $stack[] = $value; if ( 'VEVENT' === $value ) { $current = array(); } continue; }
		if ( 'END' === $name ) {
			if ( array_pop( $stack ) !== $value ) { $errors[] = "unbalanced END:$value"; }
			if ( 'VEVENT' === $value ) { $events[] = $current; $current = null; }
			continue;
		}
		if ( 'METHOD' === $name ) { $method = $value; }
		if ( 'TZID' === $name && in_array( 'VTIMEZONE', $stack, true ) ) { $timezones[] = $value; }
		if ( null !== $current ) { $current[ $name ][] = array( $params, $value ); }
		if ( in_array( $name, array( 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'X-WR-CALNAME' ), true ) ) {
			$stripped = preg_replace( '/\\\\[\\\\;,nN]/', '', $value );
			if ( false !== strpbrk( $stripped, ";,\\" ) ) { $errors[] = "unescaped TEXT in $name: $value"; }
		}
		if ( in_array( $name, array( 'DTSTAMP', 'DTSTART', 'DTEND', 'LAST-MODIFIED', 'CREATED' ), true ) ) {
			if ( preg_match( '/;TZID=("?)([^";:]+)\1/', $params, $tz ) ) {
				if ( ! in_array( $tz[2], $timezones, true ) ) { $errors[] = "$name uses TZID {$tz[2]} without VTIMEZONE"; }
				if ( ! preg_match( '/^\d{8}T\d{6}$/', $value ) ) { $errors[] = "$name with TZID must be local time: $value"; }
			} elseif ( ! preg_match( '/^\d{8}T\d{6}Z$/', $value ) ) {
				$errors[] = "$name is neither UTC nor TZID-qualified: $value";
			}
		}
	}
	if ( $stack ) { $errors[] = 'unclosed ' . implode( ',', $stack ); }
	if ( ! in_array( $method, array( 'PUBLISH', 'CANCEL' ), true ) ) { $errors[] = 'METHOD missing or unknown'; }
	foreach ( $events as $n => $event ) {
		foreach ( array( 'UID', 'DTSTAMP', 'DTSTART', 'SEQUENCE' ) as $required ) {
			if ( 1 !== count( $event[ $required ] ?? array() ) ) { $errors[] = "VEVENT $n needs exactly one $required"; }
		}
		if ( isset( $event['SEQUENCE'][0] ) && ! preg_match( '/^\d+$/', $event['SEQUENCE'][0][1] ) ) { $errors[] = "VEVENT $n SEQUENCE not a non-negative integer"; }
		if ( 'CANCEL' === $method && empty( $event['ORGANIZER'] ) ) { $errors[] = "VEVENT $n: METHOD:CANCEL without ORGANIZER"; }
		if ( 'CANCEL' === $method && 'CANCELLED' !== ( $event['STATUS'][0][1] ?? '' ) ) { $errors[] = "VEVENT $n: METHOD:CANCEL without STATUS:CANCELLED"; }
	}
	return $errors;
}

/** Unfolded property value of the first VEVENT ('' when missing). */
function a140_prop( $ics, $name ) {
	$unfolded = preg_replace( "/\r\n[ \t]/", '', (string) $ics );
	return preg_match( '/^' . preg_quote( $name, '/' ) . '(?:;[^:\r\n]*)?:(.*)$/m', $unfolded, $m ) ? rtrim( $m[1], "\r" ) : '';
}

/** RFC 5545 TEXT unescaping (inverse of Ics::escape_text). */
function a140_unescape( $value ) {
	return preg_replace_callback( '/\\\\([\\\\;,nN])/', static fn( $m ) => in_array( $m[1], array( 'n', 'N' ), true ) ? "\n" : $m[1], $value );
}

/** Mails since $before to $to whose subject starts with $prefix. */
function a140_mails( $before, $to, $prefix = '' ) {
	return array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $before ), static fn( $m ) => $to === $m['to'] && ( '' === $prefix || str_starts_with( (string) $m['subject'], $prefix ) ) ) );
}

/** The .ics attachment of a mail ('' when none). */
function a140_ics_of( $mail ) {
	foreach ( (array) ( $mail['files'] ?? array() ) as $name => $content ) {
		if ( '.ics' === substr( $name, -4 ) ) { return $content; }
	}
	return '';
}

/* ============================================================== ICS rules */

echo "A140: iCalendar correctness (folding, escaping, CRLF, UTC/TZID)\n";
$a140_text = "Ein, zwei; drei\\vier\r\nfünf\rsechs\nsieben";
check( 'escape_text escapes , ; \\ and every newline form; unescaping restores the text', 'Ein\\, zwei\\; drei\\\\vier\\nfünf\\nsechs\\nsieben' === Ics::escape_text( $a140_text ) && "Ein, zwei; drei\\vier\nfünf\nsechs\nsieben" === a140_unescape( Ics::escape_text( $a140_text ) ) );
check( 'line of exactly 75 octets stays unfolded, 76 octets are folded', str_repeat( 'a', 75 ) === Ics::fold( str_repeat( 'a', 75 ) ) && str_repeat( 'a', 75 ) . "\r\n a" === Ics::fold( str_repeat( 'a', 76 ) ) );
$a140_edge = Ics::fold( str_repeat( 'a', 74 ) . 'ä' . str_repeat( 'b', 10 ) );
check( 'a multi-byte character at the 75-octet boundary moves to the next line whole', 0 === strpos( $a140_edge, str_repeat( 'a', 74 ) . "\r\n ä" ) && 1 === preg_match( '//u', explode( "\r\n", $a140_edge )[0] ) );
$a140_long = Ics::fold( 'DESCRIPTION:' . str_repeat( '€ä', 120 ) );
$a140_parts = explode( "\r\n", $a140_long );
check( 'continuation lines are at most 75 octets including the leading space', count( $a140_parts ) > 3 && ! array_filter( $a140_parts, static fn( $l ) => strlen( $l ) > 75 ) && ! array_filter( array_slice( $a140_parts, 1 ), static fn( $l ) => ' ' !== $l[0] ) );

$a140_doc = Ics::build( array(
	array( 'occurrence_id' => 9001, 'start' => gmmktime( 18, 30, 0, 3, 29, 2027 ), 'end' => gmmktime( 21, 0, 0, 3, 29, 2027 ), 'summary' => 'Größe, Mut; & \\ Ärger', 'location' => "Halle 1; Eingang B,\nHof", 'description' => str_repeat( 'Lange Beschreibung mit Umlauten äöü, Kommas; und Zeilen' . "\n", 5 ), 'url' => "https://example.test/x?a=1\r\nX-INJECT:1", 'status' => 'scheduled', 'sequence' => 4, 'last_modified' => gmmktime( 9, 15, 0, 3, 1, 2027 ) ),
	array( 'occurrence_id' => 9002, 'start' => gmmktime( 18, 30, 0, 3, 30, 2027 ), 'end' => 0, 'summary' => 'Abgesagt', 'location' => '', 'description' => '', 'url' => '', 'status' => 'cancelled' ),
), array( 'name' => 'Kalender, Teil; 1', 'now' => gmmktime( 12, 0, 0, 3, 2, 2027 ) ) );
check( 'generated feed passes the validator (CRLF, ≤75 octets, escaping, UTC, required properties)', array() === a140_validate_ics( $a140_doc ) );
check( 'validator rejects bare LF, long lines, unescaped commas, floating times and TZID without VTIMEZONE', array() !== a140_validate_ics( "BEGIN:VCALENDAR\nEND:VCALENDAR\n" ) && array() !== a140_validate_ics( "BEGIN:VCALENDAR\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:a,b\r\nEND:VCALENDAR\r\n" ) && array() !== a140_validate_ics( "BEGIN:VCALENDAR\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20270101T000000Z\r\nSEQUENCE:0\r\nDTSTART:20270101T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" ) && array() !== a140_validate_ics( "BEGIN:VCALENDAR\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20270101T000000Z\r\nSEQUENCE:0\r\nDTSTART;TZID=Europe/Vienna:20270101T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" ) && array() !== a140_validate_ics( 'BEGIN:VCALENDAR' . "\r\n" . 'SUMMARY:' . str_repeat( 'x', 80 ) . "\r\nEND:VCALENDAR\r\n" ) );
check( 'TEXT values round-trip through escaping and folding', 'Größe, Mut; & \\ Ärger' === a140_unescape( a140_prop( $a140_doc, 'SUMMARY' ) ) && "Halle 1; Eingang B,\nHof" === a140_unescape( a140_prop( $a140_doc, 'LOCATION' ) ) && 'Kalender, Teil; 1' === a140_unescape( a140_prop( $a140_doc, 'X-WR-CALNAME' ) ) );
check( 'times are UTC (Z), no TZID and therefore no VTIMEZONE needed', false !== strpos( $a140_doc, 'DTSTART:20270329T183000Z' ) && false === strpos( $a140_doc, 'TZID' ) && false === strpos( $a140_doc, 'VTIMEZONE' ) );
check( 'DTSTAMP = generation time, SEQUENCE = revision, LAST-MODIFIED = last change', 2 === substr_count( $a140_doc, 'DTSTAMP:20270302T120000Z' ) && false !== strpos( $a140_doc, "SEQUENCE:4\r\n" ) && false !== strpos( $a140_doc, "LAST-MODIFIED:20270301T091500Z\r\n" ) && false !== strpos( $a140_doc, "SEQUENCE:0\r\n" ) && 1 === substr_count( $a140_doc, 'LAST-MODIFIED' ) );
check( 'URL cannot inject a property line', false === strpos( $a140_doc, "\r\nX-INJECT" ) );
check( 'cancelled entry: STATUS:CANCELLED and TRANSPARENT; METHOD stays PUBLISH without organizer', false !== strpos( $a140_doc, "STATUS:CANCELLED\r\nTRANSP:TRANSPARENT" ) && false !== strpos( $a140_doc, 'METHOD:PUBLISH' ) && false === strpos( $a140_doc, 'ORGANIZER' ) );
$a140_cancel_doc = Ics::build( array( array( 'occurrence_id' => 9002, 'start' => gmmktime( 18, 30, 0, 3, 30, 2027 ), 'end' => 0, 'summary' => 'Abgesagt', 'status' => 'cancelled', 'sequence' => 2 ) ), array( 'method' => 'CANCEL', 'organizer' => 'veranstalter@example.test' ) );
check( 'METHOD:CANCEL only with ORGANIZER (quoted CN) and STATUS:CANCELLED; validator agrees', false !== strpos( $a140_cancel_doc, 'METHOD:CANCEL' ) && false !== strpos( $a140_cancel_doc, 'ORGANIZER;CN="' ) && false !== strpos( $a140_cancel_doc, ':mailto:veranstalter@example.test' ) && array() === a140_validate_ics( $a140_cancel_doc ) && false !== strpos( Ics::build( array(), array( 'method' => 'CANCEL' ) ), 'METHOD:PUBLISH' ) );
check( 'parameter values drop DQUOTE and control characters', '"Bad Name"' === Ics::param_value( "Bad \"Name\"\r\n" ) );

/* ============================================================== Schema + revisions */

echo "A140: revision counter per occurrence\n";
Notifications::register();
Settings::update( array( 'calendar' => array( 'checkout_url' => '', 'attach_ics' => 1 ) ) );
check( 'schema 3.9.9: revision, revised_at, notified_revision and events.notify_changes exist, ready()', version_compare( PassCaptain\Installer::DB_VERSION, '3.9.9', '>=' ) && Revisions::ready() && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_occurrences LIKE 'revision'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_occurrences LIKE 'revised_at'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_occurrences LIKE 'notified_revision'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_events LIKE 'notify_changes'" ) );

$a140_event = ex_event( 'Änderungsgala' );
$a140_occ = ex_occ( $a140_event );
$a140_type = ex_type( $a140_occ, 'Saal', 2000 );
list( $a140_order, $a140_tickets ) = ex_buy( $a140_type, 2, 'a140@example.com' );
$a140_row = static fn() => Queries::occurrence( $a140_occ );
check( 'setup: new occurrence has revision 0, ICS SEQUENCE:0 and no LAST-MODIFIED', $a140_order && 0 === (int) $a140_row()->revision && '0' === a140_prop( Ics::occurrence_calendar( $a140_occ ), 'SEQUENCE' ) && '' === a140_prop( Ics::occurrence_calendar( $a140_occ ), 'LAST-MODIFIED' ) );
check( 'diff() sees only calendar-relevant fields; NULL equals empty', array( 'location' => array( 'A', 'B' ) ) === Revisions::diff( (object) array( 'location' => 'A', 'ends_at' => null, 'capacity' => 5 ), array( 'location' => 'B', 'ends_at' => '', 'capacity' => 9 ) ) );

$a140_save = static function ( $start, $location, array $extra = array() ) use ( $a140_occ ) {
	return EventsPage::save_occurrence( $extra + array( 'occurrence_id' => $a140_occ, 'starts_at' => $start, 'timezone' => 'Europe/Vienna', 'location' => $location, 'capacity' => '0', 'status' => 'scheduled' ) );
};

// 1. Time only → existing reschedule mail, with the updated .ics attached.
$before = count( $GLOBALS['wpcap_test_mails'] );
$r = $a140_save( '2027-03-10T19:30', 'Wien' );
$a140_m = a140_mails( $before, 'a140@example.com' );
check( 'time change: revision 1, revised_at set', ! is_wp_error( $r ) && 1 === (int) $a140_row()->revision && ! empty( $a140_row()->revised_at ) );
check( 'time change: exactly one mail, the reschedule mail', 1 === count( $a140_m ) && str_starts_with( $a140_m[0]['subject'], 'Terminänderung' ) );
$a140_att = a140_ics_of( $a140_m[0] ?? array() );
check( 'reschedule mail carries the .ics with SEQUENCE:1 and the new start; attachment is valid', '1' === a140_prop( $a140_att, 'SEQUENCE' ) && '20270310T183000Z' === a140_prop( $a140_att, 'DTSTART' ) && 'wpcap-occurrence-' . $a140_occ . '@example.test' === a140_prop( $a140_att, 'UID' ) && array() === a140_validate_ics( $a140_att ) && '' !== a140_prop( $a140_att, 'LAST-MODIFIED' ) );
check( 'temporary .ics of the change mail is removed', ! glob( trailingslashit( get_temp_dir() ) . 'termin-' . $a140_occ . '-*.ics' ) );
check( 'the "Verschoben" audit entry is still written', (bool) $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='occurrence_rescheduled' AND object_id=$a140_occ" ) );

// 2. Same values again → no revision, no mail.
$before = count( $GLOBALS['wpcap_test_mails'] );
$a140_save( '2027-03-10T19:30', 'Wien' );
check( 'saving unchanged values keeps the revision and sends nothing', 1 === (int) $a140_row()->revision && ! a140_mails( $before, 'a140@example.com' ) );

// 3. Capacity only → not calendar-relevant.
$a140_save( '2027-03-10T19:30', 'Wien', array( 'capacity' => '500' ) );
check( 'capacity change does not raise the revision', 1 === (int) $a140_row()->revision );

// 4. Location only → new mail type.
$before = count( $GLOBALS['wpcap_test_mails'] );
$a140_save( '2027-03-10T19:30', 'Konzerthaus, Großer Saal' );
$a140_m = a140_mails( $before, 'a140@example.com' );
check( 'location change: revision 2 and exactly one "Geänderte Termindetails" mail', 2 === (int) $a140_row()->revision && 1 === count( $a140_m ) && str_starts_with( $a140_m[0]['subject'], 'Geänderte Termindetails' ) );
check( 'location mail lists "Ort: Wien → Konzerthaus, Großer Saal" and links the .ics', isset( $a140_m[0] ) && false !== strpos( $a140_m[0]['body'], 'Ort: Wien → Konzerthaus, Großer Saal' ) && false !== strpos( $a140_m[0]['body'], 'wpcap_ics=occurrence' ) && false === strpos( $a140_m[0]['body'], 'Beginn:' ) );
$a140_att = a140_ics_of( $a140_m[0] ?? array() );
check( 'attached .ics: same UID, SEQUENCE:2, escaped new location', '2' === a140_prop( $a140_att, 'SEQUENCE' ) && 'Konzerthaus\\, Großer Saal' === a140_prop( $a140_att, 'LOCATION' ) && 'wpcap-occurrence-' . $a140_occ . '@example.test' === a140_prop( $a140_att, 'UID' ) );

// 5. Time + location in one save → one combined mail, no separate reschedule mail.
$before = count( $GLOBALS['wpcap_test_mails'] );
$r = $a140_save( '2027-03-11T20:00', 'Arena Wien' );
$a140_m = a140_mails( $before, 'a140@example.com' );
check( 'time + location: revision 3, still reported as rescheduled', 3 === (int) $a140_row()->revision && ! is_wp_error( $r ) && $r['rescheduled'] );
check( 'time + location: exactly ONE mail (combined), no extra reschedule mail', 1 === count( $a140_m ) && str_starts_with( $a140_m[0]['subject'], 'Geänderte Termindetails' ) && ! a140_mails( $before, 'a140@example.com', 'Terminänderung' ) );
check( 'combined mail lists the new start and the new location', isset( $a140_m[0] ) && false !== strpos( $a140_m[0]['body'], 'Beginn: 10.03.2027 19:30 → 11.03.2027 20:00' ) && false !== strpos( $a140_m[0]['body'], 'Ort: Konzerthaus, Großer Saal → Arena Wien' ) );
check( 'reschedule audit marks the combined mail', false !== strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='occurrence_rescheduled' AND object_id=$a140_occ ORDER BY audit_id DESC LIMIT 1" ), '"combined":true' ) );
check( 'change history records the notification per revision', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='occurrence_change_notified' AND object_id=$a140_occ" ) );

// 6. Idempotent per revision.
$before = count( $GLOBALS['wpcap_test_mails'] );
$again = Notifications::occurrence_changed( $a140_occ, array( 'location' => array( 'Konzerthaus, Großer Saal', 'Arena Wien' ) ), 3 );
check( 'a repeated notification for the same revision sends nothing', 0 === $again && ! a140_mails( $before, 'a140@example.com' ) && 3 === (int) $a140_row()->notified_revision );
check( 'claim_notification is true exactly once per revision', Revisions::claim_notification( $a140_occ, 4 ) && ! Revisions::claim_notification( $a140_occ, 4 ) && ! Revisions::claim_notification( $a140_occ, 3 ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET notified_revision=3 WHERE occurrence_id=$a140_occ" );

// 7. End / doors open only → change mail (no reschedule).
$before = count( $GLOBALS['wpcap_test_mails'] );
$a140_save( '2027-03-11T20:00', 'Arena Wien', array( 'ends_at' => '2027-03-11T23:00' ) );
$a140_m = a140_mails( $before, 'a140@example.com' );
check( 'end time only: revision 4, one change mail with "Ende: nicht angegeben → 11.03.2027 23:00"', 4 === (int) $a140_row()->revision && 1 === count( $a140_m ) && false !== strpos( $a140_m[0]['body'], 'Ende: nicht angegeben → 11.03.2027 23:00' ) );

// 8. Per-event opt-out.
EventsPage::update_event( array( 'event_id' => $a140_event, 'name' => 'Änderungsgala', 'status' => 'published', 'notify_changes_shown' => '1' ) );
check( 'unchecked box in the event form switches change mails off', 0 === (int) Queries::event( $a140_event )->notify_changes && ! Revisions::event_notifies( $a140_event ) );
EventsPage::update_event( array( 'event_id' => $a140_event, 'name' => 'Änderungsgala', 'status' => 'published' ) );
check( 'saving without the checkbox field (API/other forms) keeps the choice', 0 === (int) Queries::event( $a140_event )->notify_changes );
$before = count( $GLOBALS['wpcap_test_mails'] );
$a140_save( '2027-03-12T20:00', 'Stadthalle', array( 'ends_at' => '2027-03-12T23:00' ) );
check( 'opted out: revision still rises (calendar stays correct), but neither change nor reschedule mail is sent', 5 === (int) $a140_row()->revision && ! a140_mails( $before, 'a140@example.com' ) && '5' === a140_prop( Ics::occurrence_calendar( $a140_occ ), 'SEQUENCE' ) );
EventsPage::update_event( array( 'event_id' => $a140_event, 'name' => 'Änderungsgala', 'status' => 'published', 'notify_changes_shown' => '1', 'notify_changes' => '1' ) );
check( 'checkbox switches change mails on again', Revisions::event_notifies( $a140_event ) );

// 9. Only holders of valid tickets.
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'order_id' => $a140_order ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$a140_save( '2027-03-12T20:00', 'Stadthalle Graz', array( 'ends_at' => '2027-03-12T23:00' ) );
check( 'no valid tickets: no mail and the revision is not marked as notified', ! a140_mails( $before, 'a140@example.com' ) && 6 === (int) $a140_row()->revision && 6 !== (int) $a140_row()->notified_revision );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'order_id' => $a140_order ) );

// 10. Cancellation → STATUS:CANCELLED with higher SEQUENCE, attached to the cancellation mail.
add_action( 'wpcap_occurrence_cancelled', array( PassCaptain\OccurrenceCancellation::class, 'handle' ), 10, 1 );
$before = count( $GLOBALS['wpcap_test_mails'] );
$a140_cancel = EventsPage::cancel_occurrence( array( 'occurrence_id' => $a140_occ, 'reason' => 'Unwetter', 'confirm' => '1' ) );
remove_action( 'wpcap_occurrence_cancelled', array( PassCaptain\OccurrenceCancellation::class, 'handle' ), 10 );
$a140_ics = Ics::occurrence_calendar( $a140_occ );
check( 'cancellation raises the revision; download is STATUS:CANCELLED with SEQUENCE:7 and valid', ! is_wp_error( $a140_cancel ) && 7 === (int) $a140_row()->revision && 'CANCELLED' === a140_prop( $a140_ics, 'STATUS' ) && '7' === a140_prop( $a140_ics, 'SEQUENCE' ) && 'PUBLISH' === a140_prop( $a140_ics, 'METHOD' ) && array() === a140_validate_ics( $a140_ics ) );
$a140_m = a140_mails( $before, 'a140@example.com', 'Absage' );
check( 'cancellation mail carries the cancelled .ics; no change mail', 1 === count( $a140_m ) && 'CANCELLED' === a140_prop( a140_ics_of( $a140_m[0] ), 'STATUS' ) && '7' === a140_prop( a140_ics_of( $a140_m[0] ), 'SEQUENCE' ) && ! a140_mails( $before, 'a140@example.com', 'Geänderte' ) );
$GLOBALS['wpcap_test_filters']['wpcap_calendar_organizer'] = 'tickets@example.test';
$a140_ics = Ics::occurrence_calendar( $a140_occ );
unset( $GLOBALS['wpcap_test_filters']['wpcap_calendar_organizer'] );
check( 'with an organizer address the cancelled single file is METHOD:CANCEL with ORGANIZER', 'CANCEL' === a140_prop( $a140_ics, 'METHOD' ) && 'mailto:tickets@example.test' === preg_replace( '/^.*:(mailto:)/', '$1', a140_prop( $a140_ics, 'ORGANIZER' ) ) && array() === a140_validate_ics( $a140_ics ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
check( 'a cancelled occurrence never gets a change mail', 0 === Notifications::occurrence_changed( $a140_occ, array( 'location' => array( 'a', 'b' ) ), 8 ) && ! a140_mails( $before, 'a140@example.com' ) );

// 11. Event cancellation (bulk path) raises every remaining occurrence; the feed keeps them as CANCELLED.
$a140_ev2 = ex_event( 'Ganz abgesagt' );
$a140_o1 = ex_occ( $a140_ev2, 0, 200 );
$a140_o2 = ex_occ( $a140_ev2, 0, 300 );
$a140_res = PassCaptain\Orders::cancel_event( $a140_ev2 );
$a140_feed = Ics::event_feed( $a140_ev2 );
check( 'event cancellation: every occurrence revision 1, feed still served with STATUS:CANCELLED', ! is_wp_error( $a140_res ) && 1 === Revisions::current( $a140_o1 ) && 1 === Revisions::current( $a140_o2 ) && is_string( $a140_feed ) && 2 === substr_count( $a140_feed, 'STATUS:CANCELLED' ) && 2 === substr_count( $a140_feed, "SEQUENCE:1\r\n" ) && array() === a140_validate_ics( $a140_feed ) );
$wpdb->query( "UPDATE {$p}wpcap_events SET status='draft' WHERE event_id=$a140_ev2" );
check( 'drafts stay private (no download, no feed)', null === Ics::occurrence_calendar( $a140_o1 ) && null === Ics::event_feed( $a140_ev2 ) );

// 12. Series update path (A128 fixture: o3 was moved by a series change).
if ( isset( $o3 ) ) {
	check( 'series update raised the revision of the moved occurrence and the .ics carries it', Revisions::current( $o3 ) >= 1 && (string) Revisions::current( $o3 ) === a140_prop( Ics::build( array( Ics::entry_from_row( $wpdb->get_row( "SELECT o.*,e.name AS event_name,e.description AS event_description FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=" . (int) $o3 ) ) ) ), 'SEQUENCE' ) );
}
$a140_gen = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Series/Generator.php' );
check( 'series update bumps the revision and notifies through after_save', false !== strpos( $a140_gen, "Revisions::bump( \$occurrence_id )" ) && false !== strpos( $a140_gen, 'Notifications::after_save( $occurrence_id, $row[\'changes\']' ) );

/* ============================================================== Mail type */

echo "A140: mail type occurrence_changed\n";
$a140_type_def = Mailer::type( 'occurrence_changed' );
check( 'occurrence_changed is registered with label, placeholders and sample', is_array( $a140_type_def ) && '' !== $a140_type_def['label'] && isset( $a140_type_def['placeholders']['changes'], $a140_type_def['placeholders']['ics_url'] ) && is_array( call_user_func( $a140_type_def['sample'] ) ) );
$a140_docs = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/MAIL-TEMPLATES.md' ) . file_get_contents( dirname( __DIR__, 3 ) . '/docs/HOOKS.md' );
check( 'documented: mail type and the new hooks', false !== strpos( $a140_docs, '`occurrence_changed`' ) && false !== strpos( $a140_docs, '`wpcap_occurrence_changed`' ) && false !== strpos( $a140_docs, '`wpcap_calendar_organizer`' ) );

// Later test files count mails: unhook the notifications again.
remove_action( 'wpcap_occurrence_rescheduled', array( Notifications::class, 'occurrence_rescheduled' ), 10 );
remove_action( 'wpcap_occurrence_changed', array( Notifications::class, 'occurrence_changed' ), 10 );
