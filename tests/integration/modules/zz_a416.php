<?php
// phpcs:ignoreFile
/**
 * A416: an SMS whose send call ended without a clear answer (timeout, dropped connection, 5xx) or whose
 * claim was left in "sending" is never sent again blindly through Twilio (no idempotency key): it gets
 * the status "unknown" and is looked up at Twilio (Messages list) – found → sent with the SID, not found
 * → queued again. The HTTP gateway (Idempotency-Key header) keeps retrying.
 */

use PassCaptain\Settings;
use PassCaptain\Sms\Service;

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
wpcap_test_ensure_registered( PassCaptain\Modules\Sms::class ); // SMS hooks (sms.php in a full run).
global $wpdb;
$p = $wpdb->prefix;
$a416_sid = 'AC' . str_repeat( 'c3', 16 );
Settings::update( array( 'twilio_account_sid' => $a416_sid, 'twilio_auth_token' => 'a416-token', 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => 'twilio', 'twilio_from' => '+4915100000416', 'default_country_code' => '+49', 'budget_monthly' => 0, 'verify_phone' => 0 ) ) ) );
// Earlier files may leave due messages behind; they must not consume the scripted responses.
$wpdb->query( "UPDATE {$p}wpcap_sms_messages SET status='skipped' WHERE status IN ('queued','sending','unknown','held')" );

function a416_message( $order_id, $phone, $body ) {
	global $wpdb;
	Service::record_consent( $order_id, $phone );
	Service::enqueue( 'a416-' . $order_id, $order_id, 0, 'reschedule', $body );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_sms_messages WHERE order_id=%d", $order_id ) );
}
function a416_fail( WP_Error $e ) { $GLOBALS['wpcap_test_http_queue'][] = array( 'error' => $e, 'match' => '/Messages.json' ); }
function a416_row( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_sms_messages WHERE message_id=%d", $id ) );
}
function a416_due( $id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_sms_messages SET send_at=%s WHERE message_id=%d", gmdate( 'Y-m-d H:i:s', time() - 1 ), $id ) );
}

echo "A416: SMS send outcome unknown → lookup instead of a second message\n";
check( 'A416: Twilio is not idempotent, the HTTP gateway is', ! Service::resend_safe( Service::provider() ) && Service::resend_safe( new PassCaptain\Sms\HttpProvider() ) );

// 1) Timeout: status unknown, no second POST on the next run.
$m = a416_message( 9416001, '+4915177700001', 'A416 Erinnerung eins' );
http_reset();
a416_fail( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15000 milliseconds' ) );
Service::process_due();
$r = a416_row( $m->message_id );
check( 'A416: a timeout leaves the message "unknown" instead of queued for a resend', 'unknown' === $r->status && 1 === (int) $r->attempts && false !== strpos( (string) $r->last_error, 'outcome_unknown' ) );
http_reset();
Service::process_due();
check( 'A416: before the check delay nothing is sent or looked up', ! http_requests( 'api.twilio.com' ) && 'unknown' === a416_row( $m->message_id )->status );

// 2) Lookup finds the message → sent with the SID, still exactly one POST overall.
a416_due( $m->message_id );
http_reset();
http_push( array( 'messages' => array(
	array( 'sid' => 'SM' . str_repeat( '9', 32 ), 'body' => 'andere Nachricht', 'date_created' => gmdate( 'D, d M Y H:i:s +0000' ), 'status' => 'delivered' ),
	array( 'sid' => 'SM' . str_repeat( '1', 32 ), 'body' => 'A416 Erinnerung eins', 'date_created' => gmdate( 'D, d M Y H:i:s +0000' ), 'status' => 'sent' ),
) ), 200, '/Messages.json?' );
Service::process_due();
$r = a416_row( $m->message_id );
$get = http_requests( 'Messages.json?' );
check( 'A416: the lookup asks Twilio for messages to the number from the sender (GET)', 1 === count( $get ) && 'GET' === $get[0]['method'] && false !== strpos( $get[0]['url'], 'To=%2B4915177700001' ) && false !== strpos( $get[0]['url'], 'From=%2B4915100000416' ) );
check( 'A416: found at Twilio → sent with the SID, no second POST', 'sent' === $r->status && 'SM' . str_repeat( '1', 32 ) === $r->provider_ref && ! array_filter( http_requests( 'api.twilio.com' ), static fn( $q ) => 'POST' === $q['method'] ) );

// 3) Lookup does not find it → queued and sent once.
$m2 = a416_message( 9416002, '+4915177700002', 'A416 Erinnerung zwei' );
http_reset();
http_push( array( 'message' => 'Service unavailable' ), 503, '/Messages.json' );
Service::process_due();
check( 'A416: HTTP 503 from Twilio is an unknown outcome too', 'unknown' === a416_row( $m2->message_id )->status );
a416_due( $m2->message_id );
http_reset();
http_push( array( 'messages' => array() ), 200, '/Messages.json?' );
http_push( array( 'sid' => 'SM' . str_repeat( '2', 32 ), 'status' => 'queued' ), 201, '/Messages.json' );
Service::process_due();
$r = a416_row( $m2->message_id );
$a416_posts = count( array_filter( http_requests( 'api.twilio.com' ), static fn( $q ) => 'POST' === $q['method'] ) );
$a416_ok = 'sent' === $r->status && 'SM' . str_repeat( '2', 32 ) === $r->provider_ref && 1 === $a416_posts;
if ( ! $a416_ok ) {
	// Diagnostic for a one-off CI failure: row state, consent and the requests made.
	$a416_c = $wpdb->get_row( $wpdb->prepare( "SELECT withdrawn_at,retired_at,phone<>'' AS has_phone,verification FROM {$p}wpcap_sms_consents WHERE consent_id=%d", (int) $r->consent_id ) );
	echo '       row: ' . wp_json_encode( array( 'status' => $r->status, 'ref' => $r->provider_ref, 'attempts' => $r->attempts, 'error' => $r->last_error, 'send_at' => $r->send_at, 'now' => current_time( 'mysql', true ), 'posts' => $a416_posts, 'consent' => $a416_c, 'requests' => array_map( static fn( $q ) => $q['method'] . ' ' . $q['url'], http_requests( '' ) ), 'queue_left' => count( (array) ( $GLOBALS['wpcap_test_http_queue'] ?? array() ) ) ) ) . "\n";
}
check( 'A416: not found at Twilio → sent again (exactly one new POST)', $a416_ok );

// 4) Lookup fails → stays unknown and is checked later.
$m3 = a416_message( 9416003, '+4915177700003', 'A416 Erinnerung drei' );
http_reset();
a416_fail( new WP_Error( 'http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer' ) );
Service::process_due();
a416_due( $m3->message_id );
http_reset();
http_push( array( 'message' => 'oops' ), 500, '/Messages.json?' );
Service::process_due();
$r = a416_row( $m3->message_id );
check( 'A416: a failed lookup keeps the message unknown and postpones the next check', 'unknown' === $r->status && strtotime( $r->send_at . ' UTC' ) > time() + 600 && ! array_filter( http_requests( 'api.twilio.com' ), static fn( $q ) => 'POST' === $q['method'] ) );

// 5) A crashed claim (stale "sending") becomes unknown, not queued.
$m4 = a416_message( 9416004, '+4915177700004', 'A416 Erinnerung vier' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='sending',attempts=1,locked_at=%s WHERE message_id=%d", gmdate( 'Y-m-d H:i:s', time() - 20 * 60 ), $m4->message_id ) );
http_reset();
Service::process_due();
check( 'A416: a stale claim of a Twilio message becomes unknown and is not resent', 'unknown' === a416_row( $m4->message_id )->status && ! array_filter( http_requests( 'api.twilio.com' ), static fn( $q ) => 'POST' === $q['method'] ) );

// 6) Connect error (never reached Twilio) is still retried as before.
$m5 = a416_message( 9416005, '+4915177700005', 'A416 Erinnerung fünf' );
http_reset();
a416_fail( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
Service::process_due();
check( 'A416: a connect error (nothing sent) is queued for a normal retry', 'queued' === a416_row( $m5->message_id )->status );

// 7) A delivery report settles an unknown message of the HTTP gateway (reference = message id).
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET status='unknown',provider='http' WHERE message_id=%d", $m4->message_id ) );
check( 'A416: a delivery report settles an unknown message', 1 === Service::apply_status( 'http', '', 'delivered', '', null, '', (int) $m4->message_id ) && 'delivered' === a416_row( $m4->message_id )->status );

Service::withdraw( 9416003 );
check( 'A416: withdrawing the consent also skips an unknown message', 'skipped' === a416_row( $m3->message_id )->status );
$wpdb->query( "UPDATE {$p}wpcap_sms_messages SET status='skipped' WHERE status IN ('queued','sending','unknown','held')" );
http_reset();
