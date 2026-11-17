<?php
/**
 * X4: optional signed QR format (PC1.<claims>.<Ed25519>) – rendering, verification in
 * Checkin::lookup/perform (web scanner, mobile API, offline sync), tampering, key ids, rotation
 * with grace and revocation, validity window, reissued/revoked tickets, classic codes unchanged.
 */
// phpcs:ignoreFile
use PassCaptain\Checkin;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;
use PassCaptain\Orders;
use PassCaptain\Security\SignedQr;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'x4-test-salt-' . $scheme; } }
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'] );
wpcap_test_reset_transients();
\PassCaptain\Security\Vault::set_keys_for_tests( str_repeat( "\x42", 32 ) );
update_option( SignedQr::OPTION, array() );

echo "X4: signed QR codes\n";
$type = make_type( 50, 10, 0 );
$oid = admin_paid_order( $type, 3, 'x4-qr@example.com' );
$codes = ticket_code_for( $oid );
$rows = $wpdb->get_results( "SELECT ticket_id,public_id,token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $oid . ' ORDER BY ticket_id' );
$by_hash = array();
foreach ( $rows as $r ) { $by_hash[ $r->token_hash ] = $r; }
$ticket_of = static function ( $code ) use ( $by_hash ) { return $by_hash[ hash( 'sha256', $code ) ]; };
check( 'fixture: paid order with three ticket codes', 3 === count( $codes ) && 3 === count( $rows ) );

Settings::update( array( 'signed_qr' => 0 ) );
check( 'setting off (default): the QR carries the classic ticket code', $codes[0] === SignedQr::qr_value( $codes[0] ) && ! SignedQr::enabled() );
Settings::update( array( 'signed_qr' => 1 ) );
$qr = SignedQr::qr_value( $codes[0] );
check( 'setting on: the QR carries a PC1 payload', SignedQr::is_signed( $qr ) && 0 === strpos( $qr, 'PC1.' ) );
$claims = SignedQr::verify( $qr );
$t0 = $ticket_of( $codes[0] );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
check( 'claims: public id, occurrence, window, key id, code version', is_array( $claims ) && $t0->public_id === $claims['t'] && $occ === $claims['o'] && $claims['v'][0] < $claims['v'][1] && SignedQr::active_id() === $claims['k'] && substr( $t0->token_hash, 0, 16 ) === $claims['c'] );
$json = SignedQr::b64u_decode( explode( '.', $qr )[1] );
check( 'no personal data and no ticket code in the payload', false === strpos( $json, 'x4-qr@example.com' ) && false === strpos( $qr, $codes[0] ) && false === strpos( $json, $t0->token_hash ) && array( 't', 'o', 'v', 'k', 'c' ) === array_keys( json_decode( $json, true ) ) );
check( 'the signing secret is stored Vault-encrypted', SignedQr::OPTION && 0 === strpos( (string) get_option( SignedQr::OPTION )['keys'][ $claims['k'] ]['secret'], 'pcv1:' ) );

$preview = Checkin::preview( $qr );
check( 'preview of a valid signed QR: valid ticket', is_array( $preview ) && 'valid' === $preview['status'] && $t0->public_id === $preview['ticket_public_id'] );
$res = Checkin::perform( $qr, array( 'source' => 'web', 'idempotency_key' => 'x4-qr-1' ) );
check( 'check-in with a signed QR (web scanner path)', is_array( $res ) && 'checked_in' === $res['status'] );
$again = Checkin::perform( $qr, array( 'source' => 'web', 'idempotency_key' => 'x4-qr-1' ) );
check( 'same idempotency key: replayed, not counted twice', is_array( $again ) && ! empty( $again['replayed'] ) );
$second = Checkin::perform( $qr, array( 'source' => 'web' ) );
check( 'the server still checks the ticket state: second admission refused', is_wp_error( $second ) && 'already_checked_in' === $second->get_error_data()['result'] );
$classic = Checkin::perform( $codes[0], array( 'source' => 'web' ) );
check( 'the classic code of the same ticket is the same ticket (already used)', is_wp_error( $classic ) && 'already_checked_in' === $classic->get_error_data()['result'] );

// Classic codes keep working with the setting on.
$res = Checkin::perform( $codes[1], array( 'source' => 'web' ) );
check( 'classic code still works while signed QR is enabled', is_array( $res ) && 'checked_in' === $res['status'] );

// Tampering.
$qr2 = SignedQr::qr_value( $codes[2] );
list( $pre, $body, $sig ) = explode( '.', $qr2 );
$forged_claims = json_decode( SignedQr::b64u_decode( $body ), true );
$forged_claims['t'] = $t0->public_id;
$forged = $pre . '.' . SignedQr::b64u_encode( json_encode( $forged_claims ) ) . '.' . $sig;
$r = Checkin::perform( $forged, array( 'source' => 'web' ) );
check( 'tampered claims (other ticket id): rejected as invalid signature', is_wp_error( $r ) && 'wpcap_qr_invalid' === $r->get_error_code() && 'invalid_signature' === $r->get_error_data()['result'] );
$flipped = $pre . '.' . $body . '.' . ( 'A' === $sig[0] ? 'B' : 'A' ) . substr( $sig, 1 );
check( 'tampered signature: rejected', is_wp_error( Checkin::preview( $flipped ) ) && 'wpcap_qr_invalid' === Checkin::preview( $flipped )->get_error_code() );
$other = sodium_crypto_sign_keypair();
$wrong_kid = $forged_claims;
$wrong_kid['t'] = $ticket_of( $codes[2] )->public_id;
$wrong_kid['k'] = 'ffffffffffffffff';
$b = 'PC1.' . SignedQr::b64u_encode( json_encode( $wrong_kid ) );
$wrong = $b . '.' . SignedQr::b64u_encode( sodium_crypto_sign_detached( $b, sodium_crypto_sign_secretkey( $other ) ) );
check( 'unknown key id: rejected', is_wp_error( Checkin::perform( $wrong, array( 'source' => 'web' ) ) ) && 'invalid_signature' === Checkin::perform( $wrong, array( 'source' => 'web' ) )->get_error_data()['result'] );
$wrong_kid['k'] = $claims['k'];
$b = 'PC1.' . SignedQr::b64u_encode( json_encode( $wrong_kid ) );
$foreign = $b . '.' . SignedQr::b64u_encode( sodium_crypto_sign_detached( $b, sodium_crypto_sign_secretkey( $other ) ) );
check( 'known key id but signed with a foreign key: rejected', is_wp_error( Checkin::perform( $foreign, array( 'source' => 'web' ) ) ) );
check( 'invalid signed codes are not handed to scanner adapters', '' === \PassCaptain\ScannerAdapters::for_code( $forged ) || ! \PassCaptain\ScannerAdapters::all() );
check( 'nothing was admitted by the forged codes', 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ticket_of( $codes[2] )->ticket_id ) );

// Validity window.
$expired_cb = static function ( $w ) { return array( time() - 7200, time() - 60 ); };
add_filter( 'wpcap_signed_qr_window', $expired_cb );
$qr_expired = SignedQr::qr_value( $codes[2] );
// The filter stays active for the check: the current window is computed through it as well (QA-2).
$r = Checkin::perform( $qr_expired, array( 'source' => 'web' ) );
remove_filter( 'wpcap_signed_qr_window', $expired_cb );
check( 'expired validity window: refused (out_of_window), ticket stays valid', is_wp_error( $r ) && 'wpcap_qr_expired' === $r->get_error_code() && 'out_of_window' === $r->get_error_data()['result'] && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ticket_of( $codes[2] )->ticket_id ) );
check( 'default window: like the lookup plausibility range (a year before the start until a day after the last valid time)', abs( $claims['v'][0] - ( time() + 7 * 86400 - 365 * 86400 ) ) < 120 && $claims['v'][1] >= time() + 7 * 86400 + 86400 - 120 );

// Key rotation with grace, then revocation.
$before_kid = SignedQr::active_id();
$rot = SignedQr::rotate( false, 'test' );
check( 'rotation: new key id, previous kept for its QR codes', $rot['key_id'] !== $before_kid && $before_kid === $rot['previous'] && $rot['previous_valid_until'] >= $claims['v'][1] );
$p_ok = Checkin::preview( $qr2 );
check( 'rotated key (grace): QR signed with the previous key still verifies', is_array( $p_ok ) && 'valid' === $p_ok['status'] );
check( 'public keys list the active and the retired key', 2 === count( SignedQr::public_keys() ) && 'active' === SignedQr::public_keys()[0]['status'] );
$qr_new = SignedQr::qr_value( $codes[2] );
check( 'new QR codes use the new key', $rot['key_id'] === SignedQr::verify( $qr_new )['k'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$denied = \PassCaptain\OfflineScanner\AdminPage::rotate_qr_key( array( 'revoke' => '1' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'key rotation requires manage_options', is_wp_error( $denied ) && $rot['key_id'] === SignedQr::active_id() );
$rev = \PassCaptain\OfflineScanner\AdminPage::rotate_qr_key( array( 'revoke' => '1' ) );
check( 'rotation with revoke: previous key dropped at once', is_array( $rev ) && $rev['revoked'] && is_wp_error( SignedQr::verify( $qr_new ) ) );
check( 'QR of the revoked key is rejected; QR of the earlier grace key still verifies', is_wp_error( Checkin::preview( $qr_new ) ) && is_array( Checkin::preview( $qr2 ) ) );
check( 'audit records the rotations', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='qr_key_rotated'" ) >= 2 );

// Reissued ticket: new code version voids the old QR (classic and signed).
$GLOBALS['wpcap_test_mails'] = array();
$t2 = $ticket_of( $codes[2] );
$re = Orders::reissue_ticket( (int) $t2->ticket_id, array( 'notify' => false ) );
$fresh = SignedQr::for_ticket( (int) $t2->ticket_id );
$r = Checkin::perform( $qr2, array( 'source' => 'web' ) );
check( 'reissued ticket: QR of the previous code is refused as revoked (code version)', is_array( $re ) && is_wp_error( $r ) && 'wpcap_qr_superseded' === $r->get_error_code() && 'revoked' === $r->get_error_data()['result'] );
// Merged scanner answer (Arbeitsliste): a replaced code is recognised and explained ("replaced", 409), not "unknown".
check( 'reissued ticket: the old classic code is unknown', is_wp_error( Checkin::preview( $codes[2] ) ) && 'replaced' === Checkin::preview( $codes[2] )->get_error_data()['result'] && 'reissue' === Checkin::preview( $codes[2] )->get_error_data()['replaced_reason'] );
$r = Checkin::preview( $fresh );
check( 'reissued ticket: the QR of the new code is valid', is_array( $r ) && 'valid' === $r['status'] );

// Revoked ticket.
Orders::revoke_ids( array( (int) $t2->ticket_id ) );
$r = Checkin::perform( $fresh, array( 'source' => 'web' ) );
check( 'revoked ticket: a validly signed QR is refused (server checks the state)', is_wp_error( $r ) && 'revoked' === $r->get_error_data()['result'] );

// Mobile API and offline sync accept signed codes.
$type2 = make_type( 20, 10, 0 );
$oid2 = admin_paid_order( $type2, 2, 'x4-qr2@example.com' );
$codes2 = ticket_code_for( $oid2 );
$event2 = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type2 );
$pairing = Devices::create_pairing( array( $event2 ), 'X4 QR', 12 );
$device = Devices::pair( $pairing['code'], 'X4 QR', 'pwa', '1.0' );
$GLOBALS['wpcap_test_user_id'] = 1;
$res = app_call( 'check_in', 'POST', $device['token'], array( 'code' => SignedQr::qr_value( $codes2[0] ), 'idempotency_key' => 'x4-app-1' ) );
check( 'mobile API check-in with a signed QR', is_array( $res ) && ! empty( $res['ok'] ) && 'checked_in' === $res['result'] );
$sync = app_call( 'sync', 'POST', $device['token'], array( 'scans' => array( array( 'code' => SignedQr::qr_value( $codes2[1] ), 'idempotency_key' => 'x4-off-1', 'scanned_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) ) ) ) ); // Merged sync API: scanned_at with a zone (ISO 8601 Z).
check( 'offline sync upload with a signed QR is admitted', is_array( $sync ) && ! empty( $sync['results'][0]['ok'] ) );
check( 'offline keys endpoint publishes the QR keys (public part only)', ( static function () use ( $device ) {
	$req = app_request( 'GET', $device['token'] );
	if ( true !== Api::authenticate( $req ) ) { return false; }
	$data = \PassCaptain\OfflineScanner\Rest::keys( $req )->get_data();
	return ! empty( $data['qr_keys'] ) && ! isset( $data['qr_keys'][0]['secret'] ) && 32 === strlen( base64_decode( $data['qr_keys'][0]['public_key'] ) );
} )() );

Settings::update( array( 'signed_qr' => 0 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
