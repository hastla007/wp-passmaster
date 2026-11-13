<?php
/**
 * Worklist item A271 (server-side safety net): a scanned value that holds more than one ticket code
 * (a keyboard wedge scanner typing the next code behind the previous one) is refused as a whole
 * instead of admitting the first code. Client-side parts of A271/A315/A539–A542 are covered by
 * tests/js/scanner.test.js and tests/js/offline-scanner.test.js.
 * Included from run.php after admission.php (uses adm_setup(), adm_ticket_status()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admission\Rest as AdmissionRest;
use PassCaptain\Checkin;

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A271: concatenated ticket codes\n";
$a271 = adm_setup( 'Europe/Vienna', null, null, 2 );
list( $a271_t1, $a271_t2 ) = $a271['codes'];

check( 'A271 normalize_code: one code (raw, upper case, ticket URL) is accepted', $a271_t1 === Checkin::normalize_code( strtoupper( $a271_t1 ) ) && $a271_t1 === Checkin::normalize_code( 'https://example.test/?wpcap_ticket=' . $a271_t1 . '&x=1' ) );
check( 'A271 normalize_code: two codes or a run longer than 64 hex characters give no code', '' === Checkin::normalize_code( $a271_t1 . $a271_t2 ) && '' === Checkin::normalize_code( $a271_t1 . ' ' . $a271_t2 ) && '' === Checkin::normalize_code( $a271_t1 . 'a' ) );
check( 'A271 has_multiple_codes: true for concatenated/two codes, false for one code repeated or none', Checkin::has_multiple_codes( $a271_t1 . $a271_t2 ) && Checkin::has_multiple_codes( $a271_t1 . "\n" . $a271_t2 ) && ! Checkin::has_multiple_codes( $a271_t1 . ' ' . $a271_t1 ) && ! Checkin::has_multiple_codes( $a271_t1 ) && ! Checkin::has_multiple_codes( 'K7QM-4XWA' ) );

$a271_r = Checkin::perform( $a271_t1 . $a271_t2, array( 'idempotency_key' => 'a271-' . uniqid( '', true ) ) );
check( 'A271 check-in of T1T2 is refused (wpcap_ticket_ambiguous, 400, invalid_code), nobody admitted', is_wp_error( $a271_r ) && 'wpcap_ticket_ambiguous' === $a271_r->get_error_code() && 400 === (int) $a271_r->get_error_data()['status'] && 'invalid_code' === $a271_r->get_error_data()['result'] && 'valid' === adm_ticket_status( $a271_t1 ) && 'valid' === adm_ticket_status( $a271_t2 ) );
$a271_c = AdmissionRest::client_error( $a271_r );
check( 'A271 web scanner answer keeps the wpcap_ code (shown as refusal, not as system error)', 'wpcap_ticket_ambiguous' === $a271_c->get_error_code() && 400 === (int) $a271_c->get_error_data()['status'] );
check( 'A271 preview of two codes is refused as well', is_wp_error( Checkin::preview( $a271_t1 . ' ' . $a271_t2 ) ) );
check( 'A271 a single code is still admitted afterwards', 'checked_in' === adm_in( $a271_t1 ) && 'valid' === adm_ticket_status( $a271_t2 ) );
