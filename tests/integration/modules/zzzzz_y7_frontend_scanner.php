<?php
/**
 * Y7 (audit panel 2026-11-15, frontend & scanner):
 * - QA-2: a signed QR sent before the occurrence was moved by more than a day stays valid for the new date
 *   (window checked against the current occurrence dates); forged signatures stay refused.
 * - QA-11: month calendar groups occurrences by the same timezone their time label uses.
 * The JS findings (FE-1…FE-8, PRIV-5) are covered in tests/js.
 */
// phpcs:ignoreFile
use PassCaptain\Checkin;
use PassCaptain\Modules\Calendar;
use PassCaptain\Security\SignedQr;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'y7-test-salt-' . $scheme; } }
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'] );
\PassCaptain\Security\Vault::set_keys_for_tests( str_repeat( "\x42", 32 ) );

echo "Y7: signed QR after moving the occurrence (QA-2)\n";
Settings::update( array( 'signed_qr' => 1 ) );
$type = make_type( 20, 10, 0 );
$oid = admin_paid_order( $type, 2, 'y7-qr@example.com' );
$codes = ticket_code_for( $oid );
$occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $type ) );
check( 'fixture: paid order with two codes', 2 === count( $codes ) && $occ > 0 );
$qr = SignedQr::qr_value( $codes[0] );
$qr_b = SignedQr::qr_value( $codes[1] );
$claims = SignedQr::verify( $qr );
$old_start = strtotime( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT starts_at FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $occ ) ) . ' UTC' );
check( 'fixture: QR signed with the window of the original date', SignedQr::is_signed( $qr ) && is_array( $claims ) && $claims['v'][1] <= $old_start + 2 * DAY_IN_SECONDS );

// The organiser moves the occurrence by 30 days; the PDF ticket with the old QR is still in use.
$new_start = $old_start + 30 * DAY_IN_SECONDS;
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET starts_at=%s, ends_at=NULL WHERE occurrence_id=%d', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', $new_start ), $occ ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] = $new_start - HOUR_IN_SECONDS; // shortly before the new start
check( 'precondition: the scan lies outside the signed window', ! SignedQr::in_window( $claims, $new_start - HOUR_IN_SECONDS ) );
$preview = Checkin::preview( $qr );
check( 'preview of the old QR after the move: valid', is_array( $preview ) && 'valid' === $preview['status'] );
$res = Checkin::perform( $qr, array( 'source' => 'web', 'idempotency_key' => 'y7-qa2-1' ) );
check( 'check-in with the old QR shortly before the new start: admitted', is_array( $res ) && 'checked_in' === $res['status'] );

// Manipulated signature: still refused (the widened window never replaces the signature check).
list( $pre, $body, $sig ) = explode( '.', $qr_b );
$flipped = $pre . '.' . $body . '.' . ( 'A' === $sig[0] ? 'B' : 'A' ) . substr( $sig, 1 );
$r = Checkin::perform( $flipped, array( 'source' => 'web' ) );
check( 'manipulated signature after the move: refused (invalid_signature)', is_wp_error( $r ) && 'wpcap_qr_invalid' === $r->get_error_code() && 'invalid_signature' === $r->get_error_data()['result'] );
$forged_claims = json_decode( SignedQr::b64u_decode( $body ), true );
$forged_claims['v'] = array( 0, PHP_INT_MAX >> 1 );
$forged = $pre . '.' . SignedQr::b64u_encode( json_encode( $forged_claims ) ) . '.' . $sig;
check( 'manipulated window claims: refused', is_wp_error( Checkin::perform( $forged, array( 'source' => 'web' ) ) ) );

// More than a day after the current end the QR is refused again.
$GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] = $new_start + 2 * DAY_IN_SECONDS;
$late = Checkin::perform( $qr_b, array( 'source' => 'web' ) );
check( 'two days after the moved occurrence: refused (out_of_window)', is_wp_error( $late ) && 'out_of_window' === ( $late->get_error_data()['result'] ?? '' ) );
check( 'the second ticket was never admitted', 'valid' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE token_hash=%s', $p . 'wpcap_tickets', hash( 'sha256', $codes[1] ) ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] );
Settings::update( array( 'signed_qr' => 0 ) );

echo "Y7: month calendar timezone (QA-11)\n";
// Site in Europe/Berlin, occurrence in America/New_York at 20:00 local time: in Berlin it is already the
// next day (02:00). Day cell and time label must use the same timezone (the occurrence's own).
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return (string) ( $GLOBALS['wpcap_test_site_timezone'] ?? 'UTC' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
$GLOBALS['wpcap_test_site_timezone'] = 'Europe/Berlin';
$year = (int) gmdate( 'Y' ) + 1;
$cal_type = make_type( 5, 5, 0 );
$cal_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $cal_type ) );
$cal_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $cal_occ ) );
$local = new DateTime( $year . '-03-10 20:00:00', new DateTimeZone( 'America/New_York' ) );
$utc = ( clone $local )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET starts_at=%s, ends_at=NULL, timezone=%s WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $utc, 'America/New_York', $cal_occ ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET name=%s WHERE event_id=%d', $p . 'wpcap_events', 'Y7 Jazz NYC', $cal_event ) );
$grid = Calendar::render_month( $year . '-03', $cal_event );
$cell = static function ( $html, $day ) {
	return preg_match( '#<time datetime="' . preg_quote( $day, '#' ) . '">\d+</time></span>(.*?)</td>#s', $html, $m ) ? $m[1] : '';
};
check( 'fixture: in Berlin the occurrence is on the next day', '11' === ( clone $local )->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( 'd' ) );
// Merged calendar: the time label names the zone when it differs from the site's ("20:00 EST", A393 label
// starts with the visible text).
check( 'occurrence listed on its own local date (10th) with its local time 20:00', 1 === preg_match( '/>20:00( [A-Z]{2,5})? Y7 Jazz NYC</', $cell( $grid, $year . '-03-10' ) ) );
check( 'not listed on the Berlin date (11th)', false === strpos( $cell( $grid, $year . '-03-11' ), 'Y7 Jazz NYC' ) );
check( 'the accessible label carries the same date and time', 1 === preg_match( '/aria-label="20:00( [A-Z]{2,5})? Y7 Jazz NYC, 10\.03\.' . $year . '"/', $grid ) );

// Month boundary: 20:00 New York on the last day of a month is the 1st of the next month in Berlin.
$edge = new DateTime( $year . '-04-30 20:00:00', new DateTimeZone( 'America/New_York' ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET starts_at=%s WHERE occurrence_id=%d', $p . 'wpcap_occurrences', ( clone $edge )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $cal_occ ) );
$april = Calendar::render_month( $year . '-04', $cal_event );
$may = Calendar::render_month( $year . '-05', $cal_event );
check( 'month boundary: shown on 30 April (its own date) in the April grid', 1 === preg_match( '/>20:00( [A-Z]{2,5})? Y7 Jazz NYC</', $cell( $april, $year . '-04-30' ) ) );
check( 'month boundary: not shown in the May grid', false === strpos( $may, 'Y7 Jazz NYC' ) );
$GLOBALS['wpcap_test_site_timezone'] = 'UTC';
