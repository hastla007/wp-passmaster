<?php
/**
 * Worklist item A089: web scanner result like the offline PWA (full-screen result, tones with
 * mute switch, vibration, first-use display) and a short help code per ticket for manual entry.
 * Included from run.php after admission.php (uses adm_setup(), adm_now(), adm_ts()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Policies;
use PassCaptain\Admission\Rest as AdmissionRest;
use PassCaptain\Checkin;
use PassCaptain\HelpCode;
use PassCaptain\Orders;
use PassCaptain\Printing\Templates as PrintTemplates;
use PassCaptain\TicketPdf;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
HelpCode::register();

/** Help code line of the last ticket mail ("…: K7QM-4XWA"), all matches. */
function a089_mail_codes( $body ) {
	preg_match_all( '/nicht lesbar ist: ([2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4})/', (string) $body, $m );
	return $m[1];
}
function a089_ticket_id( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a089_reset_limits() {
	$GLOBALS['wpcap_test_transients'] = array();
	HelpCode::flush();
}

/* ---------------------------------------------------------------------------------------------
 * Help code: schema, issuance, format
 * ------------------------------------------------------------------------------------------- */
echo "A089: help code per ticket\n";
check( 'A089 schema: help_hash/help_n columns with unique index, DB version >= 3.8.6, ready()', null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_tickets LIKE 'help_hash'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_tickets LIKE 'help_n'" ) && null !== $wpdb->get_row( "SHOW INDEX FROM {$p}wpcap_tickets WHERE Key_name='help_hash' AND Non_unique=0" ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.8.6', '>=' ) && HelpCode::ready() );

$vie = 'Europe/Vienna';
$before = count( $GLOBALS['wpcap_test_mails'] );
$h = adm_setup( $vie, '2027-07-01 20:00', '2027-07-01 23:00', 3 );
$h_mail = '';
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $mail ) { if ( str_starts_with( $mail['subject'], 'Deine Tickets' ) ) { $h_mail = $mail['body']; } }
$h_codes = a089_mail_codes( $h_mail );
$h_ids = array_map( 'a089_ticket_id', $h['codes'] );
check( 'A089 ticket mail lists one help code per ticket below the ticket code', 3 === count( $h_codes ) && 3 === count( array_unique( $h_codes ) ) && 3 === count( $h['codes'] ) );
$h_rows = $wpdb->get_results( "SELECT ticket_id,help_hash,help_n FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $h_ids ) . ') ORDER BY ticket_id' );
$h_plain = false;
foreach ( $h_rows as $row ) { foreach ( $h_codes as $c ) { if ( false !== stripos( (string) $row->help_hash, str_replace( '-', '', $c ) ) ) { $h_plain = true; } } }
check( 'A089 only a keyed hash is stored (no plain help code, 64 hex)', 3 === count( array_filter( $h_rows, static fn( $r ) => 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $r->help_hash ) ) ) && ! $h_plain );
check( 'A089 for_ticket() is stable for the current code and refuses an unknown/old code', $h_codes[0] === HelpCode::for_ticket( $h_ids[0], $h['codes'][0] ) && '' === HelpCode::for_ticket( $h_ids[0], str_repeat( 'ab', 32 ) ) && '' === HelpCode::for_ticket( $h_ids[0], 'nonsense' ) );
check( 'A089 alphabet: 32 unambiguous characters, derive() uses only them', 32 === strlen( HelpCode::ALPHABET ) && 32 === count( array_unique( str_split( HelpCode::ALPHABET ) ) ) && ! preg_match( '/[01IO]/', HelpCode::ALPHABET ) && 1 === preg_match( '/^[2-9A-HJ-NP-Z]{8}$/', HelpCode::derive( $h['codes'][0], 5 ) ) );
check( 'A089 normalize(): case, spaces and hyphens are ignored; 0/O/1/I and wrong lengths are refused', 'K7QM4XWA' === HelpCode::normalize( ' k7qm-4xwa ' ) && 'K7QM4XWA' === HelpCode::normalize( 'K7QM 4XWA' ) && '' === HelpCode::normalize( 'K7QM-4XW0' ) && '' === HelpCode::normalize( 'K7QM-4XWI' ) && '' === HelpCode::normalize( 'K7QM4XW' ) && '' === HelpCode::normalize( str_repeat( 'a', 64 ) ) );

$pdf = TicketPdf::render( array( array( 'event' => 'E', 'ticket' => 'T', 'when' => 'heute', 'location' => '', 'token' => $h['codes'][0], 'help_code' => $h_codes[0] ) ) );
check( 'A089 ticket PDF shows the help code', false !== strpos( $pdf, '(' . $h_codes[0] . ')' ) );
$page_src = (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' );
check( 'A089 ticket page shows the help code (assigned lazily for older tickets)', false !== strpos( $page_src, 'HelpCode::for_ticket' ) && false !== strpos( $page_src, 'help-code' ) );
$print_values = PrintTemplates::values( (object) array( 'ticket_id' => $h_ids[2], 'event_name' => 'E' ), $h['codes'][2] );
check( 'A089 print layouts: {help_code} placeholder filled only with a printed code', in_array( '{help_code}', PrintTemplates::PLACEHOLDERS, true ) && $h_codes[2] === $print_values['help_code'] && '' === PrintTemplates::values( (object) array( 'ticket_id' => $h_ids[2] ), '' )['help_code'] && false !== strpos( PrintTemplates::default_body( 'ticket' ), '{help_code}' ) );

// Lazy backfill: a ticket without help code (issued before 3.8.6) gets the same code on demand.
$wpdb->query( "UPDATE {$p}wpcap_tickets SET help_hash=NULL,help_n=0 WHERE ticket_id=" . $h_ids[1] );
HelpCode::flush();
check( 'A089 tickets without help code get it when the plain code is known (ticket page)', $h_codes[1] === HelpCode::for_ticket( $h_ids[1], $h['codes'][1] ) && null !== $wpdb->get_var( "SELECT help_hash FROM {$p}wpcap_tickets WHERE ticket_id=" . $h_ids[1] ) );

// Collision: the first derivation is taken by another ticket -> next counter, different code.
$collide = $wpdb->get_row( "SELECT help_hash FROM {$p}wpcap_tickets WHERE ticket_id=" . $h_ids[1] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET help_hash=NULL,help_n=0 WHERE ticket_id=" . $h_ids[1] );
$other = adm_setup( $vie, '2027-07-02 20:00', '2027-07-02 23:00', 1 );
$other_id = a089_ticket_id( $other['codes'][0] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET help_hash=%s WHERE ticket_id=%d", $collide->help_hash, $other_id ) );
$moved = HelpCode::for_ticket( $h_ids[1], $h['codes'][1] );
check( 'A089 collision: the next derivation counter is used (unique help codes)', '' !== $moved && $moved !== $h_codes[1] && 1 === (int) $wpdb->get_var( "SELECT help_n FROM {$p}wpcap_tickets WHERE ticket_id=" . $h_ids[1] ) && $moved === HelpCode::for_ticket( $h_ids[1], $h['codes'][1] ) );
$h_codes[1] = $moved;
HelpCode::for_ticket( $other_id, $other['codes'][0] ); // repair the other ticket's own code

/* ---------------------------------------------------------------------------------------------
 * Help code at the scanner
 * ------------------------------------------------------------------------------------------- */
echo "A089: check-in by help code, first use\n";
a089_reset_limits();
adm_now( adm_ts( '2027-07-01 20:05', $vie ) );
$in = Checkin::perform( strtolower( str_replace( '-', ' ', $h_codes[0] ) ), array( 'idempotency_key' => 'a089-help-1' ) );
check( 'A089 typed help code (lower case, space) checks the ticket in like the QR code', ! is_wp_error( $in ) && 'checked_in' === $in['status'] && 'checked_in' === adm_ticket_status( $h['codes'][0] ) );
check( 'A089 result marks the help code and the first admission', ! is_wp_error( $in ) && ! empty( $in['help_code'] ) && true === $in['first_admission'] && 1 === $in['admissions'] && ! empty( $in['first_checked_in_at'] ) );
$audit = json_decode( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='ticket_checked_in' AND object_id=" . $h_ids[0] . ' ORDER BY audit_id DESC LIMIT 1' ), true );
check( 'A089 audit log records the admission via help code', is_array( $audit ) && 'help_code' === ( $audit['via'] ?? '' ) );
$replay = Checkin::perform( $h_codes[0], array( 'idempotency_key' => 'a089-help-1' ) );
check( 'A089 a retried request with the same key is replayed, not counted twice', ! is_wp_error( $replay ) && ! empty( $replay['replayed'] ) );

adm_now( adm_ts( '2027-07-01 20:40', $vie ) );
$again = Checkin::perform( $h['codes'][0], array( 'idempotency_key' => 'a089-qr-2' ) );
$again_data = is_wp_error( $again ) ? (array) $again->get_error_data() : array();
check( 'A089 second scan: already used, with the time of the first admission', is_wp_error( $again ) && 'already_checked_in' === ( $again_data['result'] ?? '' ) && gmdate( 'c', adm_ts( '2027-07-01 20:05', $vie ) ) === ( $again_data['first_checked_in_at'] ?? '' ) && 1 === ( $again_data['admissions'] ?? 0 ) && ! isset( $again_data['help_code'] ) );
$client = AdmissionRest::client_error( $again );
$client_data = (array) $client->get_error_data();
check( 'A089 web scanner REST error passes the first-use fields, no internal ids', ! empty( $client_data['first_checked_in_at'] ) && ! isset( $client_data['ticket_id'] ) && ! isset( $client_data['checkin_id'] ) );
$public = Checkin::public_error( $again );
check( 'A089 legacy web check-in route keeps the first-use fields without internal ids', isset( ( (array) $public->get_error_data() )['first_checked_in_at'] ) && ! isset( ( (array) $public->get_error_data() )['ticket_id'] ) );
adm_now( null );

// Re-entry ticket: admission no. 2 names the first admission and the area of the latest one.
$r = adm_setup( $vie, '2027-07-03 20:00', '2027-07-03 23:00', 1 );
Policies::save( $r['type'], array( 'policy' => 'reentry', 'max_entries' => '0' ) );
$r_area = (int) Areas::save( $r['event'], 'Nordeingang' );
adm_now( adm_ts( '2027-07-03 20:10', $vie ) );
$r1 = Checkin::perform( $r['codes'][0], array( 'idempotency_key' => 'a089-r1', 'area_id' => $r_area ) );
adm_now( adm_ts( '2027-07-03 20:30', $vie ) );
$r_inside = Checkin::perform( $r['codes'][0], array( 'idempotency_key' => 'a089-r1b', 'area_id' => $r_area ) );
$r_inside_data = is_wp_error( $r_inside ) ? (array) $r_inside->get_error_data() : array();
Checkin::check_out( $r['codes'][0], array( 'idempotency_key' => 'a089-r-out', 'area_id' => $r_area ) );
adm_now( adm_ts( '2027-07-03 21:00', $vie ) );
$r2 = Checkin::perform( $r['codes'][0], array( 'idempotency_key' => 'a089-r2', 'area_id' => $r_area ) );
adm_now( null );
check( 'A089 still inside: refusal names the first admission and the area', is_wp_error( $r_inside ) && 'already_inside' === ( $r_inside_data['result'] ?? '' ) && 'Nordeingang' === ( $r_inside_data['last_checked_in_area'] ?? '' ) && ! empty( $r_inside_data['first_checked_in_at'] ) );
check( 'A089 re-entry: first admission flag only on the first one, count and first time on later ones', ! is_wp_error( $r1 ) && true === $r1['first_admission'] && ! is_wp_error( $r2 ) && false === $r2['first_admission'] && 2 === $r2['admissions'] && gmdate( 'c', adm_ts( '2027-07-03 20:10', $vie ) ) === $r2['first_checked_in_at'] );
Policies::save( $r['type'], array( 'policy' => 'default' ) );

/* ---------------------------------------------------------------------------------------------
 * Security: unknown codes, rotation, rate limits, scope
 * ------------------------------------------------------------------------------------------- */
echo "A089: help code security\n";
a089_reset_limits();
$unknown = Checkin::preview( 'ZZZZ-ZZZZ' );
check( 'A089 unknown help code: "unknown" with a hint on the characters', is_wp_error( $unknown ) && 'unknown' === ( $unknown->get_error_data()['result'] ?? '' ) && false !== strpos( $unknown->get_error_message(), 'Hilfscode' ) );
check( 'A089 malformed input stays "ticket not found"', is_wp_error( Checkin::preview( 'hello' ) ) && 'Ticket nicht gefunden.' === Checkin::preview( 'hello' )->get_error_message() );

// Rotation: reissue -> old help code dead, new one mailed and working.
$before = count( $GLOBALS['wpcap_test_mails'] );
$reissued = Orders::reissue_ticket( $h_ids[2], array( 'reason' => 'a089' ) );
$reissue_mail = '';
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $mail ) { $reissue_mail .= $mail['body']; }
$new_codes = a089_mail_codes( $reissue_mail );
a089_reset_limits();
$old = Checkin::preview( $h_codes[2] );
$new = isset( $new_codes[0] ) ? Checkin::preview( $new_codes[0] ) : null;
check( 'A089 code rotation: the old help code stops working, the mail carries the new one', ! is_wp_error( $reissued ) && is_wp_error( $old ) && 'unknown' === ( $old->get_error_data()['result'] ?? '' ) && 1 === count( $new_codes ) && $new_codes[0] !== $h_codes[2] && is_array( $new ) && (int) $new['occurrence_id'] === $h['occ'] );

// Rate limit per user (filter lowered for the test); every attempt counts, also hits.
a089_reset_limits();
$GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] = array( 'user' => 3, 'client' => 100, 'site' => 100, 'window' => 600 );
$attempts = array();
foreach ( array( 'ZZZZ-ZZZ2', 'ZZZZ-ZZZ3', 'ZZZZ-ZZZ4', $h_codes[1] ) as $guess ) { $attempts[] = Checkin::preview( $guess ); }
$limited = end( $attempts );
check( 'A089 help-code lookups are rate-limited per user (HTTP 429, not a verdict)', is_wp_error( $limited ) && 'wpcap_help_code_rate_limited' === $limited->get_error_code() && 429 === ( $limited->get_error_data()['status'] ?? 0 ) && 'rate_limited' === ( $limited->get_error_data()['result'] ?? '' ) );
check( 'A089 the long ticket code is not affected by the help-code limit', ! is_wp_error( Checkin::preview( $h['codes'][1] ) ) );
$GLOBALS['wpcap_test_user_id'] = 2;
HelpCode::flush();
$other_user = Checkin::preview( $h_codes[1] );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A089 another staff member is not blocked by that user limit', ! is_wp_error( $other_user ) || 'wpcap_help_code_rate_limited' !== $other_user->get_error_code() );
$GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] = array( 'user' => 100, 'client' => 100, 'site' => 2, 'window' => 600 );
a089_reset_limits();
Checkin::preview( 'ZZZZ-ZZZ5' );
Checkin::preview( 'ZZZZ-ZZZ6' );
$GLOBALS['wpcap_test_user_id'] = 3;
$site = Checkin::preview( 'ZZZZ-ZZZ7' );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A089 site-wide help-code limit across users', is_wp_error( $site ) && 'wpcap_help_code_rate_limited' === $site->get_error_code() && $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='help_code_rate_limited'" ) > 0 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] );
a089_reset_limits();

$scoped = Checkin::perform( $h_codes[1], array( 'idempotency_key' => 'a089-scope', 'event_ids' => array( $h['event'] + 999 ) ) );
check( 'A089 event scope applies to help codes like to QR codes', is_wp_error( $scoped ) && 'wrong_event' === ( $scoped->get_error_data()['result'] ?? '' ) && 'valid' === adm_ticket_status( $h['codes'][1] ) );
$vouchers = new PassCaptain\Integrations\Vouchers\CheckinService();
check( 'A089 shared voucher scanner does not claim help codes (could be voucher codes)', ! $vouchers->recognizes( $h_codes[1] ) && $vouchers->recognizes( $h['codes'][1] ) );

/* ---------------------------------------------------------------------------------------------
 * Web scanner and PWA presentation (static checks; behaviour is covered by the a11y suite)
 * ------------------------------------------------------------------------------------------- */
echo "A089: scanner presentation\n";
$js = (string) file_get_contents( WPCAP_PATH . 'assets/scanner.js' );
$pub = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
$css = (string) file_get_contents( WPCAP_PATH . 'assets/public.css' );
check( 'A089 web scanner: sound switch stored in localStorage with try/catch, tones respect it', false !== strpos( $js, "wpcap-scanner-sound-v1" ) && 1 === preg_match( '/try \{ soundOn = window\.localStorage\.getItem\(soundKey\)/', $js ) && false !== strpos( $js, 'if (!audio || !soundOn) return;' ) && false !== strpos( $pub, 'wpcap-sound-toggle' ) && false !== strpos( $pub, 'aria-pressed="true"' ) );
check( 'A089 web scanner: distinct tones and vibration patterns per result', false !== strpos( $js, 'tone([660, 990]' ) && false !== strpos( $js, "tone([196, 147], 0.32, 'square')" ) && false !== strpos( $js, 'vibrate([250, 100, 250, 100, 250])' ) );
check( 'A089 web scanner: full-screen result that never takes focus, live announcement kept', false !== strpos( $pub, 'wpcap-scan-overlay" aria-hidden="true" hidden' ) && false !== strpos( $css, '.wpcap-scan-overlay{position:fixed;inset:0' ) && false !== strpos( $js, "live.textContent = prefix + ': ' + text" ) && false === strpos( $js, 'overlay.focus' ) );
check( 'A089 web scanner: first use, earlier admission, ID/age hint and help-code note shown', false !== strpos( $js, 'data.first_admission' ) && false !== strpos( $js, 'data.first_checked_in_at' ) && false !== strpos( $js, 'info.staff_hint' ) && false !== strpos( $js, 'data.staff_hint' ) && false !== strpos( $js, 'data.help_code' ) );
$keys = array( 'admitted', 'notAdmitted', 'firstAdmission', 'reentry', 'alreadyAt', 'lastAt', 'atArea', 'viaHelpCode', 'helpOffline', 'rateLimited', 'soundOn', 'soundOff', 'dismiss', 'checkedOutTitle' );
check( 'A089 web scanner: new texts are translatable (localized)', ! array_filter( $keys, static fn( $k ) => false === strpos( $pub, "'" . $k . "' => __(" ) || false === strpos( $js, 't.' . $k ) ) );
check( 'A089 web scanner: help codes are not queued offline', false !== strpos( $js, 'looksLikeHelpCode(token)' ) && false !== strpos( $js, 'response.status === 429' ) );
$app = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' );
check( 'A089 offline PWA: help code online only, tones with switch, first-use text', false !== strpos( $app, 'function normalizeHelpCode' ) && false !== strpos( $app, "handleHelpCode(help)" ) && false !== strpos( $app, "localStorage.getItem('wpcap_scanner_sound')" ) && false !== strpos( $app, 'historyText(res)' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/sw.js' ), 'wpcap-offline-scanner-v3' ) );
$mobile = (string) file_get_contents( WPCAP_PATH . 'src/Mobile/Api.php' );
check( 'A089 mobile API passes first-use and hint fields with refusals', false !== strpos( $mobile, 'Checkin::CLIENT_ERROR_KEYS' ) && in_array( 'first_checked_in_at', Checkin::CLIENT_ERROR_KEYS, true ) && in_array( 'staff_hint', Checkin::CLIENT_ERROR_KEYS, true ) );
$docs = (string) file_get_contents( WPCAP_PATH . 'docs/SECURITY.md' ) . (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
check( 'A089 decision documented (SECURITY.md) and filter listed (HOOKS.md)', false !== strpos( $docs, '## Hilfscode für den Einlass' ) && false !== strpos( $docs, '`wpcap_help_code_rate_limits`' ) );
