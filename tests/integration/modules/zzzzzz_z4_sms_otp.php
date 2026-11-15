<?php
/**
 * Z4 / PRIV-16: SMS opt-in only with a number confirmed by a 6-digit code (OTP): code request with
 * rate limits, hashed code, attempt limit, expiry, one-time use at checkout, consent marked verified
 * with timestamp, unverified opt-ins get no SMS, legacy consents stay valid.
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Sms;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Sms\Service;
use PassCaptain\Sms\Verification;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); } }

global $wpdb;
$p = $wpdb->prefix;
$z4s_sid = 'AC' . str_repeat( 'c3', 16 );
Settings::update( array( 'twilio_account_sid' => $z4s_sid, 'twilio_auth_token' => 'z4-token', 'sms' => array_merge( Sms::settings_defaults()['sms'], array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49' ) ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$z4s_type = make_type( 50, 10, 0 );
$z4s_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $z4s_type ) );
$z4s_order = static function ( $key, array $extra = array() ) use ( $z4s_type ) {
	return Orders::create( array_merge( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $z4s_type, 'quantity' => 1 ) ), 'idempotency_key' => $key, 'sms_consent' => 1 ), $extra ) );
};
$z4s_last_code = static function () {
	$req = http_requests( 'api.twilio.com' );
	$req = end( $req );
	$form = $req ? form_body( $req ) : array();
	return preg_match( '/\b(\d{6})\b/', (string) ( $form['Body'] ?? '' ), $m ) ? $m[1] : '';
};
$z4s_push = static function () { http_push( array( 'sid' => 'SM' . bin2hex( random_bytes( 16 ) ), 'status' => 'queued' ), 201, '/Messages.json' ); };

echo "Z4 PRIV-16: setting and checkout field\n";
check( 'number verification is on by default (setting sms.verify_phone)', Verification::required() && 1 === Sms::settings_defaults()['sms']['verify_phone'] );
ob_start(); Sms::render_checkout_fields( (object) array( 'occurrence_id' => 7 ), array() ); $z4s_html = ob_get_clean();
check( 'checkout shows send button, one-time-code field and hidden verification token', false !== strpos( $z4s_html, 'wpcap-sms-send' ) && false !== strpos( $z4s_html, 'autocomplete="one-time-code"' ) && false !== strpos( $z4s_html, 'name="sms_verification"' ) && false !== strpos( $z4s_html, 'role="status"' ) );
$z4s_cfg = Sms::checkout_config();
check( 'script config carries verify flag and both REST URLs', true === $z4s_cfg['verify'] && false !== strpos( $z4s_cfg['requestUrl'], '/sms/verify/request' ) && false !== strpos( $z4s_cfg['confirmUrl'], '/sms/verify/confirm' ) );
check( 'payload key sms_verification is accepted', in_array( 'sms_verification', Sms::payload_keys( array() ), true ) );

echo "Z4 PRIV-16: opt-in without confirmed number is rejected\n";
$z4s_no = $z4s_order( 'z4s-no', array( 'sms_phone' => '+4915112340001' ) );
check( 'checkout with opt-in but without verification token fails with a clear message', is_wp_error( $z4s_no ) && false !== strpos( $z4s_no->get_error_message(), 'Code' ) );
check( 'no consent row and no order for the rejected attempt', null === $wpdb->get_var( $wpdb->prepare( 'SELECT consent_id FROM %i WHERE phone=%s', $p . 'wpcap_sms_consents', '+4915112340001' ) ) );
$z4s_fake = $z4s_order( 'z4s-fake', array( 'sms_phone' => '+4915112340001', 'sms_verification' => str_repeat( 'ab', 32 ) ) );
check( 'made-up verification token is rejected', is_wp_error( $z4s_fake ) );
$z4s_plain = Orders::create( array( 'email' => 'z4s-plain@example.com', 'items' => array( array( 'ticket_type_id' => $z4s_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z4s-plain' ) );
check( 'orders without opt-in are unaffected', ! is_wp_error( $z4s_plain ) );

echo "Z4 PRIV-16: code request\n";
http_reset();
check( 'invalid number: no SMS', is_wp_error( Verification::request( 'abc' ) ) && ! http_requests( 'twilio' ) );
check( 'number outside the allowed mobile ranges: no SMS (premium/landline protection)', is_wp_error( Verification::request( '+4990012345678' ) ) && is_wp_error( Verification::request( '+1 202 555 0100' ) ) && ! http_requests( 'twilio' ) );
$z4s_push();
$z4s_req = Verification::request( '0151 1234 0002' );
$z4s_code = $z4s_last_code();
$z4s_row = is_array( $z4s_req ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', $p . 'wpcap_sms_verifications', hash( 'sha256', $z4s_req['token'] ) ) ) : null;
check( 'code request sends exactly one SMS with a 6-digit code to the normalised number', is_array( $z4s_req ) && 1 === count( http_requests( 'api.twilio.com' ) ) && 1 === preg_match( '/^\d{6}$/', $z4s_code ) && '+4915112340002' === ( form_body( http_requests( 'api.twilio.com' )[0] )['To'] ?? '' ) );
check( 'response: 64-hex token, 600 s validity, masked number (no full number)', is_array( $z4s_req ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $z4s_req['token'] ) && 600 === $z4s_req['expires_in'] && false === strpos( $z4s_req['phone_masked'], '12340002' ) );
check( 'only hashes are stored (no plain code, no plain token)', $z4s_row && 64 === strlen( $z4s_row->code_hash ) && false === strpos( json_encode( $z4s_row ), $z4s_code . '"' ) && $z4s_row->token_hash !== $z4s_req['token'] && 1 === (int) $z4s_row->sends );
check( 'code request leaves no SMS in the outbox (code is not stored as message)', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE body LIKE %s', $p . 'wpcap_sms_messages', '%' . addcslashes( $z4s_code, '_%\\' ) . '%' ) ) );
$z4s_wait = Verification::request( '+4915112340002', $z4s_req['token'] );
check( 'resend within 60 s is refused (429)', is_wp_error( $z4s_wait ) && 429 === $z4s_wait->get_error_data()['status'] && 1 === count( http_requests( 'api.twilio.com' ) ) );

echo "Z4 PRIV-16: code confirmation\n";
$z4s_wrong = '000000' === $z4s_code ? '111111' : '000000';
check( 'wrong code is rejected', is_wp_error( Verification::confirm( $z4s_req['token'], $z4s_wrong ) ) );
check( 'malformed code/token rejected without touching the row', is_wp_error( Verification::confirm( $z4s_req['token'], '12' ) ) && is_wp_error( Verification::confirm( 'xyz', $z4s_code ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM %i WHERE verification_id=%d', $p . 'wpcap_sms_verifications', $z4s_row->verification_id ) ) );
$z4s_ok = Verification::confirm( $z4s_req['token'], substr( $z4s_code, 0, 3 ) . ' ' . substr( $z4s_code, 3 ) );
$z4s_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE verification_id=%d', $p . 'wpcap_sms_verifications', $z4s_row->verification_id ) );
check( 'correct code (spaces ignored) verifies; code hash is cleared', is_array( $z4s_ok ) && true === $z4s_ok['verified'] && null !== $z4s_row->verified_at && '' === $z4s_row->code_hash );

echo "Z4 PRIV-16: checkout with confirmed number\n";
$z4s_other = $z4s_order( 'z4s-other', array( 'sms_phone' => '+4915112349999', 'sms_verification' => $z4s_req['token'] ) );
check( 'token of another number is rejected', is_wp_error( $z4s_other ) );
$z4s_yes = $z4s_order( 'z4s-yes', array( 'sms_phone' => '+49 151 1234 0002', 'sms_verification' => $z4s_req['token'] ) );
$z4s_consent = is_array( $z4s_yes ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_yes['order_id'] ) ) : null;
check( 'checkout with verified token stores the consent as verified with verification time', $z4s_consent && 'verified' === $z4s_consent->verification && $z4s_row->verified_at === $z4s_consent->verified_at && null !== $z4s_consent->consented_at );
check( 'consent log: audit entry sms_consent_verified with timestamp', is_array( $z4s_yes ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action='sms_consent_verified' AND object_type='order' AND object_id=%d AND details LIKE %s", $p . 'wpcap_audit_logs', $z4s_yes['order_id'], '%' . addcslashes( (string) $z4s_row->verified_at, '_%\\' ) . '%' ) ) );
check( 'verification is bound to the order (used_order_id)', is_array( $z4s_yes ) && (int) $z4s_yes['order_id'] === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT used_order_id FROM %i WHERE verification_id=%d', $p . 'wpcap_sms_verifications', $z4s_row->verification_id ) ) );
$z4s_again = $z4s_order( 'z4s-again', array( 'sms_phone' => '+4915112340002', 'sms_verification' => $z4s_req['token'] ) );
check( 'a verification token works for one order only', is_wp_error( $z4s_again ) );
check( 'verified consent receives SMS', true === Service::enqueue( 'z4s-v', $z4s_yes['order_id'], $z4s_occ, 'reminder', 'Hallo' ) );

echo "Z4 PRIV-16: attempts and expiry\n";
http_reset();
$z4s_push();
$z4s_req2 = Verification::request( '+4915112340003' );
$z4s_code2 = $z4s_last_code();
$z4s_bad2 = '000000' === $z4s_code2 ? '111111' : '000000';
for ( $i = 0; $i < Verification::MAX_ATTEMPTS; $i++ ) { Verification::confirm( $z4s_req2['token'], $z4s_bad2 ); }
check( 'after 5 wrong codes even the correct code is rejected', is_wp_error( Verification::confirm( $z4s_req2['token'], $z4s_code2 ) ) );
$z4s_push();
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_sent_at=%s WHERE token_hash=%s', $p . 'wpcap_sms_verifications', gmdate( 'Y-m-d H:i:s', time() - 120 ), hash( 'sha256', $z4s_req2['token'] ) ) );
$z4s_req3 = Verification::request( '+4915112340003', $z4s_req2['token'] );
$z4s_code3 = $z4s_last_code();
check( 'after the attempts are used up a new request starts a fresh verification (new token)', is_array( $z4s_req3 ) && $z4s_req3['token'] !== $z4s_req2['token'] );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at=%s WHERE token_hash=%s', $p . 'wpcap_sms_verifications', gmdate( 'Y-m-d H:i:s', time() - 1 ), hash( 'sha256', $z4s_req3['token'] ) ) );
check( 'expired code (10 minutes) is rejected', is_wp_error( Verification::confirm( $z4s_req3['token'], $z4s_code3 ) ) );
$z4s_push();
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_sent_at=%s WHERE token_hash=%s', $p . 'wpcap_sms_verifications', gmdate( 'Y-m-d H:i:s', time() - 120 ), hash( 'sha256', $z4s_req3['token'] ) ) );
$z4s_resend = Verification::request( '+4915112340003', $z4s_req3['token'] );
$z4s_code4 = $z4s_last_code();
check( 'resend after the cooldown keeps the token and sends a new code', is_array( $z4s_resend ) && $z4s_resend['token'] === $z4s_req3['token'] && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT sends FROM %i WHERE token_hash=%s', $p . 'wpcap_sms_verifications', hash( 'sha256', $z4s_req3['token'] ) ) ) );
check( 'old code no longer valid, new one is', ( $z4s_code3 === $z4s_code4 || is_wp_error( Verification::confirm( $z4s_req3['token'], $z4s_code3 ) ) ) && is_array( Verification::confirm( $z4s_req3['token'], $z4s_code4 ) ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET verified_at=%s WHERE token_hash=%s', $p . 'wpcap_sms_verifications', gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ), hash( 'sha256', $z4s_req3['token'] ) ) );
check( 'a verification older than one hour is not accepted at checkout', is_wp_error( $z4s_order( 'z4s-old', array( 'sms_phone' => '+4915112340003', 'sms_verification' => $z4s_req3['token'] ) ) ) );

echo "Z4 PRIV-16: cost and abuse protection\n";
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
$z4s_codes = 0;
for ( $i = 0; $i < 4; $i++ ) {
	$z4s_push();
	$r = Verification::request( '+4915112340004' );
	if ( is_array( $r ) ) { ++$z4s_codes; }
}
check( 'at most 3 codes per number and hour', 3 === $z4s_codes && 3 === count( http_requests( 'api.twilio.com' ) ) && 429 === $r->get_error_data()['status'] );
$GLOBALS['wpcap_test_transients'] = array();
http_reset();
$z4s_ip = 0;
for ( $i = 0; $i < 7; $i++ ) {
	$z4s_push();
	if ( is_array( Verification::request( '+49151123401' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) ) ) ) { ++$z4s_ip; }
}
check( 'at most 5 codes per client address and hour (rotating numbers)', 5 === $z4s_ip && 5 === count( http_requests( 'api.twilio.com' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
add_filter( 'wpcap_sms_otp_limits', $z4s_lim = static function ( $l ) { $l['site_day'] = 2; $l['ip_hour'] = 100; return $l; } );
http_reset();
$z4s_site = 0;
for ( $i = 0; $i < 3; $i++ ) { $z4s_push(); if ( is_array( Verification::request( '+49151123402' . $i . '0' ) ) ) { ++$z4s_site; } }
remove_filter( 'wpcap_sms_otp_limits', $z4s_lim );
check( 'site-wide daily cap (filter wpcap_sms_otp_limits) stops cost pumping', 2 === $z4s_site );
$GLOBALS['wpcap_test_transients'] = array();
$z4s_rest = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/sms/verify/request' );
$z4s_rest->set_param( 'phone', '+4915112340005' );
$z4s_rest->set_header( 'Origin', 'https://evil.example' );
http_reset();
$z4s_cross = Verification::rest_request( $z4s_rest );
check( 'REST request from a foreign origin is refused before any SMS', is_wp_error( $z4s_cross ) && 403 === $z4s_cross->get_error_data()['status'] && ! http_requests( 'twilio' ) );
$z4s_rest->set_header( 'Origin', 'https://example.test' );
$z4s_push();
$z4s_same = Verification::rest_request( $z4s_rest );
check( 'REST request from the own origin sends the code', ! is_wp_error( $z4s_same ) && 1 === count( http_requests( 'api.twilio.com' ) ) );
$z4s_conf = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/sms/verify/confirm' );
$z4s_conf->set_header( 'Referer', 'https://evil.example/x' );
$z4s_conf->set_param( 'token', str_repeat( 'a', 64 ) );
$z4s_conf->set_param( 'code', '123456' );
check( 'REST confirm from a foreign origin is refused', is_wp_error( Verification::rest_confirm( $z4s_conf ) ) && 403 === Verification::rest_confirm( $z4s_conf )->get_error_data()['status'] );
check( 'same-origin helper: own host ok, missing headers ok (non-browser, rate limited), foreign host refused', Verification::same_origin( 'https://example.test', '' ) && Verification::same_origin( '', '' ) && ! Verification::same_origin( '', 'https://example.test.evil.example/' ) );

echo "Z4 PRIV-16: unverified and legacy consents\n";
$wpdb->insert( $p . 'wpcap_sms_consents', array( 'order_id' => is_array( $z4s_plain ) ? $z4s_plain['order_id'] : 0, 'phone' => '+4915112340006', 'purpose' => 'event_updates', 'text_version' => 'v0', 'consented_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$z4s_legacy = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_plain['order_id'] ) );
check( 'rows without explicit state (stock before 3.15.2) are legacy', $z4s_legacy && 'legacy' === $z4s_legacy->verification && null === $z4s_legacy->verified_at );
check( 'legacy consents stay valid and receive SMS', true === Service::enqueue( 'z4s-legacy', $z4s_plain['order_id'], $z4s_occ, 'reminder', 'Hallo' ) );
$z4s_u = Orders::create( array( 'email' => 'z4s-u@example.com', 'items' => array( array( 'ticket_type_id' => $z4s_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z4s-u' ) );
Service::record_consent( $z4s_u['order_id'], '+4915112340007' );
check( 'an unverified consent gets no SMS while verification is on', 'unverified' === $wpdb->get_var( $wpdb->prepare( 'SELECT verification FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_u['order_id'] ) ) && false === Service::enqueue( 'z4s-unv', $z4s_u['order_id'], $z4s_occ, 'reminder', 'Hallo' ) );
// A message queued earlier is skipped at send time.
$wpdb->insert( $p . 'wpcap_sms_messages', array( 'dedupe_key' => hash( 'sha256', 'z4s-queued' . uniqid() ), 'consent_id' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT consent_id FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_u['order_id'] ) ), 'order_id' => $z4s_u['order_id'], 'occurrence_id' => $z4s_occ, 'purpose' => 'reschedule', 'body' => 'x', 'status' => 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$z4s_qid = (int) $wpdb->insert_id;
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='skipped' WHERE status='queued' AND message_id<>%d", $p . 'wpcap_sms_messages', $z4s_qid ) );
http_reset();
Service::process_due();
check( 'queued message of an unverified consent is skipped at send time (no SMS)', 'skipped' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE message_id=%d', $p . 'wpcap_sms_messages', $z4s_qid ) ) && ! http_requests( 'api.twilio.com' ) );
Sms::save( array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49', 'allowed_prefixes' => '+49', 'consent_text' => Service::consent_text(), 'verify_phone' => '0' ) );
check( 'admin can switch verification off (hidden 0 + checkbox)', ! Verification::required() );
check( 'with verification off, unverified consents receive SMS as before', true === Service::enqueue( 'z4s-unv-off', $z4s_u['order_id'], $z4s_occ, 'reminder', 'Hallo' ) );
$z4s_off = $z4s_order( 'z4s-off', array( 'sms_phone' => '+4915112340008' ) );
check( 'with verification off, checkout stores the opt-in as unverified', is_array( $z4s_off ) && 'unverified' === $wpdb->get_var( $wpdb->prepare( 'SELECT verification FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_off['order_id'] ) ) );
Sms::save( array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49', 'allowed_prefixes' => '+49', 'consent_text' => Service::consent_text() ) );
check( 'a save without the key keeps the current value', ! Verification::required() );
Sms::save( array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49', 'allowed_prefixes' => '+49', 'consent_text' => Service::consent_text(), 'verify_phone' => '1' ) );
check( 'switched on again', Verification::required() );
ob_start(); Sms::render_admin(); $z4s_admin = ob_get_clean();
check( 'admin shows the setting and the consent states incl. legacy stock', false !== strpos( $z4s_admin, 'name="verify_phone" id="wpcap-sms-verify_phone" value="1" checked' ) && false !== strpos( $z4s_admin, 'unverifiziert (Altbestand)' ) && false !== strpos( $z4s_admin, 'per SMS-Code bestätigt' ) && false === strpos( $z4s_admin, 'OTP) ist nicht implementiert' ) );

echo "Z4 PRIV-16: privacy\n";
$z4s_export = Sms::export( 'z4s-yes@example.com' );
$z4s_flat = json_encode( $z4s_export['data'], JSON_UNESCAPED_UNICODE );
check( 'privacy export shows the confirmation of the number with time', false !== strpos( $z4s_flat, 'Bestätigung der Nummer' ) && false !== strpos( $z4s_flat, 'per SMS-Code bestätigt (' . $z4s_consent->verified_at ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at=%s WHERE token_hash=%s', $p . 'wpcap_sms_verifications', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), hash( 'sha256', $z4s_req2['token'] ) ) );
check( 'verifications are deleted after one day (numbers of abandoned attempts are not kept)', Verification::prune() >= 1 && null === $wpdb->get_var( $wpdb->prepare( 'SELECT verification_id FROM %i WHERE token_hash=%s', $p . 'wpcap_sms_verifications', hash( 'sha256', $z4s_req2['token'] ) ) ) );

echo "Z4 PRIV-16: upgrade keeps existing opt-ins as legacy\n";
$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN verification, DROP COLUMN verified_at', $p . 'wpcap_sms_consents' ) );
$z4s_pre = Orders::create( array( 'email' => 'z4s-pre@example.com', 'items' => array( array( 'ticket_type_id' => $z4s_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z4s-pre' ) );
$wpdb->insert( $p . 'wpcap_sms_consents', array( 'order_id' => $z4s_pre['order_id'], 'phone' => '+4915112340009', 'purpose' => 'event_updates', 'text_version' => 'old', 'consented_at' => gmdate( 'Y-m-d H:i:s' ) ) );
// dbDelta adds a missing column with ALTER TABLE ... ADD COLUMN <definition from the CREATE TABLE statement>.
$z4s_schema = implode( "\n", Sms::schema( $p, '' ) );
$z4s_defs = array();
foreach ( array( 'verification', 'verified_at' ) as $z4s_col ) {
	if ( preg_match( '/^\s*(' . $z4s_col . ' [^,\n]+),?$/m', $z4s_schema, $z4s_m ) ) { $z4s_defs[] = 'ADD COLUMN ' . $z4s_m[1]; }
}
$wpdb->query( 'ALTER TABLE ' . $p . 'wpcap_sms_consents ' . implode( ', ', $z4s_defs ) );
check( 'upgrade adds the columns; existing opt-ins become "unverifiziert (Altbestand)"', 2 === count( $z4s_defs ) && 'legacy' === $wpdb->get_var( $wpdb->prepare( 'SELECT verification FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_pre['order_id'] ) ) && 'unverifiziert (Altbestand)' === Sms::verification_label( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $p . 'wpcap_sms_consents', $z4s_pre['order_id'] ) ) ) );
check( 'DB version 3.15.2 (schema change)', '3.15.2' === PassCaptain\Installer::DB_VERSION );

// Switch SMS off for later test files.
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => '' ) ) ) );
$GLOBALS['wpcap_test_transients'] = array();
