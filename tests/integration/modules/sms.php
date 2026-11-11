<?php
/**
 * SMS module: consent enforcement, consent record, Twilio sending (form-encoded, Basic auth),
 * Twilio status callback signature validation, generic HTTP adapter escaping, change messages.
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Sms;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Sms\HttpProvider;
use PassCaptain\Sms\Service;
use PassCaptain\Sms\TwilioProvider;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/wp-pass-captain/' ); }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }

Sms::register();
global $wpdb;
$p = $wpdb->prefix;
$sid = 'AC' . str_repeat( 'a1', 16 );
$token = 'twilio-auth-token-123';

echo "SMS: configuration and phone numbers\n";
check( 'no provider configured: opt-in is not offered', ! Service::enabled() );
ob_start(); Sms::render_checkout_fields( (object) array( 'occurrence_id' => 1 ), array() ); $html = ob_get_clean();
check( 'no checkout field without provider', '' === $html );
Settings::update( array( 'twilio_account_sid' => $sid, 'twilio_auth_token' => $token, 'sms' => array_merge( Sms::settings_defaults()['sms'], array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49' ) ) ) );
check( 'Twilio configured via settings', Service::enabled() && Service::provider() instanceof TwilioProvider );
check( 'phone numbers normalized to E.164', '+4915123456789' === Service::normalize_phone( '0151 2345-6789' ) && '+43664123456' === Service::normalize_phone( '0043 664 123456' ) && '+4915123456789' === Service::normalize_phone( '+49 (151) 23456789' ) && '' === Service::normalize_phone( '12ab' ) && '' === Service::normalize_phone( '+0123' ) );
ob_start(); Sms::render_checkout_fields( (object) array( 'occurrence_id' => 5 ), array() ); $html = ob_get_clean();
check( 'checkout shows labelled phone field and unticked consent checkbox with the consent text', false !== strpos( $html, 'type="tel"' ) && false !== strpos( $html, 'name="sms_consent" value="1">' ) && false === strpos( $html, 'checked' ) && false !== strpos( $html, 'per SMS erhalten' ) && false !== strpos( $html, '<legend>' ) );
check( 'payload keys extended', in_array( 'sms_phone', apply_filters( 'wpcap_checkout_payload_keys', array( 'email' ) ), true ) && in_array( 'sms_consent', apply_filters( 'wpcap_checkout_payload_keys', array( 'email' ) ), true ) );

echo "SMS: consent at checkout\n";
$type = make_type( 30, 10, 0 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
$GLOBALS['wpcap_test_transients'] = array();
$bad = Orders::create( array( 'email' => 'sms-bad@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'sms-bad', 'sms_consent' => true, 'sms_phone' => 'abc' ) );
check( 'consent with invalid number aborts the checkout with a message', is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'Mobilnummer' ) );
$yes = Orders::create( array( 'email' => 'sms-yes@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'sms-yes', 'sms_consent' => true, 'sms_phone' => '0151 1111111' ) );
$no = Orders::create( array( 'email' => 'sms-no@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'sms-no', 'sms_phone' => '+4915122222222' ) );
$consent = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $yes['order_id'] );
check( 'consent stored with number, purpose, text version and timestamp', $consent && '+491511111111' === $consent->phone && 'event_updates' === $consent->purpose && Service::consent_version() === $consent->text_version && null !== $consent->consented_at && null === $consent->withdrawn_at );
check( 'number without consent is not stored', null === $wpdb->get_var( "SELECT consent_id FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $no['order_id'] ) && null === $wpdb->get_var( "SELECT consent_id FROM {$p}wpcap_sms_consents WHERE phone='+4915122222222'" ) );
check( 'no SMS can be queued without consent', false === Service::enqueue( 'x-no', $no['order_id'], $occ, 'reminder', 'Hallo' ) );

echo "SMS: change messages and sending via Twilio\n";
do_action( 'wpcap_occurrence_rescheduled', $occ, gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ), gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) );
$msgs = $wpdb->get_results( "SELECT * FROM {$p}wpcap_sms_messages WHERE occurrence_id=$occ" );
check( 'reschedule message only for the consenting order', 1 === count( $msgs ) && (int) $yes['order_id'] === (int) $msgs[0]->order_id && 'reschedule' === $msgs[0]->purpose && false !== strpos( $msgs[0]->body, 'Neuer Termin: Konzert' ) );
do_action( 'wpcap_occurrence_rescheduled', $occ, gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ), gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) );
check( 'same change is queued once', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_sms_messages WHERE occurrence_id=$occ" ) );
http_reset();
http_push( array( 'sid' => 'SM' . str_repeat( 'b', 32 ), 'status' => 'queued' ), 201, '/Messages.json' );
$sent = Service::process_due();
$req = http_requests( 'api.twilio.com' )[0] ?? array( 'url' => '', 'headers' => array(), 'body' => '' );
$form = form_body( $req );
check( 'Twilio Messages API called with Basic auth and form encoding', 1 === $sent && 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json' === $req['url'] && 'Basic ' . base64_encode( $sid . ':' . $token ) === $req['headers']['Authorization'] && 'application/x-www-form-urlencoded' === $req['headers']['Content-Type'] );
check( 'To, From, Body and StatusCallback sent', '+491511111111' === ( $form['To'] ?? '' ) && '+4915100000000' === ( $form['From'] ?? '' ) && false !== strpos( $form['Body'] ?? '', 'Konzert' ) && 'https://example.test/wp-json/wp-pass-captain/v1/sms/twilio/status' === ( $form['StatusCallback'] ?? '' ) );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_messages WHERE occurrence_id=$occ" );
check( 'message marked sent with provider reference', 'sent' === $row->status && 'twilio' === $row->provider && 'SM' . str_repeat( 'b', 32 ) === $row->provider_ref );

echo "SMS: Twilio delivery status callback\n";
$params = array( 'MessageSid' => $row->provider_ref, 'MessageStatus' => 'delivered', 'AccountSid' => $sid, 'To' => '+491511111111' );
$sign = static function ( $params, $key ) {
	ksort( $params );
	$data = 'https://example.test/wp-json/wp-pass-captain/v1/sms/twilio/status';
	foreach ( $params as $k => $v ) { $data .= $k . $v; }
	return base64_encode( hash_hmac( 'sha1', $data, $key, true ) );
};
$callback = static function ( $params, $signature ) {
	$req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/sms/twilio/status' );
	$req->set_body( http_build_query( $params ) );
	if ( null !== $signature ) { $req->set_header( 'X-Twilio-Signature', $signature ); }
	return Service::twilio_status( $req );
};
$forged = $callback( $params, $sign( $params, 'wrong-token' ) );
check( 'callback with wrong signature is rejected (403)', is_wp_error( $forged ) && 403 === $forged->get_error_data()['status'] );
$nosig = $callback( $params, null );
check( 'callback without signature is rejected', is_wp_error( $nosig ) );
$tampered = $params; $tampered['MessageStatus'] = 'failed';
check( 'tampered parameters are rejected', is_wp_error( $callback( $tampered, $sign( $params, $token ) ) ) );
check( 'status unchanged after rejected callbacks', 'sent' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_sms_messages WHERE message_id=" . (int) $row->message_id ) );
$ok = $callback( $params, $sign( $params, $token ) );
check( 'valid callback updates the delivery status', ! is_wp_error( $ok ) && 'delivered' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_sms_messages WHERE message_id=" . (int) $row->message_id ) );
$late = array_merge( $params, array( 'MessageStatus' => 'sent' ) );
$callback( $late, $sign( $late, $token ) );
check( 'late "sent" does not downgrade "delivered"', 'delivered' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_sms_messages WHERE message_id=" . (int) $row->message_id ) );
check( 'signature helper matches Twilio scheme', TwilioProvider::signature_valid( 'https://x.test/cb?a=1', array( 'b' => '2', 'a' => '1' ), base64_encode( hash_hmac( 'sha1', 'https://x.test/cb?a=1a1b2', 'k', true ) ), 'k' ) );

echo "SMS: cancellation, withdrawal and failures\n";
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$occ" );
Sms::occurrence_cancelled( $occ );
check( 'cancellation SMS queued (before tickets are revoked)', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_sms_messages WHERE occurrence_id=$occ AND purpose='cancellation' AND status='queued'" ) );
Service::withdraw( $yes['order_id'] );
$c2 = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $yes['order_id'] );
check( 'withdrawal erases the number and skips queued messages', '' === $c2->phone && null !== $c2->withdrawn_at && 'skipped' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_sms_messages WHERE occurrence_id=$occ AND purpose='cancellation'" ) );
http_reset();
check( 'nothing is sent after withdrawal', 0 === Service::process_due() && ! http_requests( 'twilio' ) );

$type2 = make_type( 30, 10, 0 );
$occ2 = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type2" );
$o2 = Orders::create( array( 'email' => 'sms-2@example.com', 'items' => array( array( 'ticket_type_id' => $type2, 'quantity' => 1 ) ), 'idempotency_key' => 'sms-2', 'sms_consent' => '1', 'sms_phone' => '+4915133333333' ) );
Service::enqueue( 'sms-test-reminder', $o2['order_id'], $occ2, 'reminder', 'Erinnerung' );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE order_id=" . (int) $o2['order_id'] );
http_reset();
Service::process_due();
check( 'reminder SMS for revoked tickets is skipped at send time', 'skipped' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_sms_messages WHERE order_id=" . (int) $o2['order_id'] ) && ! http_requests( 'twilio' ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='valid' WHERE order_id=" . (int) $o2['order_id'] );
Service::enqueue( 'sms-test-fail', $o2['order_id'], $occ2, 'reschedule', 'Info' );
http_reset();
http_push( array( 'code' => 21211, 'message' => "The 'To' number is not valid." ), 400, '/Messages.json' );
Service::process_due();
$failed = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_messages WHERE order_id=" . (int) $o2['order_id'] . " AND purpose='reschedule'" );
check( 'permanent provider error (4xx) fails without retry and logs no number', 'failed' === $failed->status && false === strpos( (string) $failed->last_error, '+49151' ) );

echo "SMS: generic HTTP adapter\n";
check( 'JSON body template escapes values', '{"to":"+491","text":"Er sagte \"hi\"\\nA"}' === HttpProvider::render_body( '{"to":"{to}","text":"{body}"}', 'json', array( 'to' => '+491', 'body' => "Er sagte \"hi\"\nA" ) ) );
check( 'form body template URL-encodes values', 'to=%2B491&text=a%26b%3Dc' === HttpProvider::render_body( 'to={to}&text={body}', 'form', array( 'to' => '+491', 'body' => 'a&b=c' ) ) );
Settings::update( array( 'sms_http_secret' => 'Bearer s3cret', 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => 'http', 'http_endpoint' => 'http://insecure.example', 'http_auth_header' => 'Authorization' ) ) ) );
check( 'HTTP adapter requires an https endpoint', ! Service::enabled() );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'http_endpoint' => 'https://sms.example/send' ) ) ) );
Service::enqueue( 'sms-http-1', $o2['order_id'], $occ2, 'reminder', 'Hallo "Welt"' );
http_reset();
http_push( array( 'id' => 'gw-1' ), 200, 'sms.example' );
Service::process_due();
$hr = http_requests( 'sms.example' )[0] ?? array( 'headers' => array(), 'body' => '' );
check( 'HTTP adapter posts the template with the auth header', 'Bearer s3cret' === ( $hr['headers']['Authorization'] ?? '' ) && is_array( json_decode( $hr['body'], true ) ) && 'Hallo "Welt"' === json_decode( $hr['body'], true )['text'] && 'gw-1' === $wpdb->get_var( "SELECT provider_ref FROM {$p}wpcap_sms_messages WHERE order_id=" . (int) $o2['order_id'] . " AND purpose='reminder' AND status='sent'" ) );
// Switch SMS off for later test files.
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => '' ) ) ) );
