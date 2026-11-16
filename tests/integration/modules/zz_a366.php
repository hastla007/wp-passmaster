<?php
/**
 * A366: replay protection of the SMS callbacks – signed timestamp (±5 min) for the generic HTTP
 * gateway, every accepted callback processed once (also Twilio inbound), message-id deduplication.
 * Runs after modules/sms.php (SMS tables and module registered).
 */
// phpcs:ignoreFile
use PassCaptain\Modules\Sms;
use PassCaptain\RateLimit;
use PassCaptain\Settings;
use PassCaptain\Sms\Service;

global $wpdb;

echo "A366: SMS callbacks without replay\n";
$x1_sms_before = (array) Settings::get( 'sms' );
$x1_secret = 'x1-a366-secret';
Settings::update( array( 'sms_http_webhook_secret' => $x1_secret, 'sms' => array_merge( Sms::settings_defaults()['sms'], array( 'provider' => 'http', 'http_endpoint' => 'https://sms.example/x1', 'default_country_code' => '+49' ) ) ) );
$x1_inbound = static function ( $body, $signature, $timestamp = null ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/sms/http/inbound' );
	$req->set_body( $body );
	$req->set_header( 'X-Signature', $signature );
	if ( null !== $timestamp ) { $req->set_header( 'X-Timestamp', (string) $timestamp ); }
	$res = Service::http_inbound( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};
$x1_sig = static function ( $body, $ts = '' ) use ( $x1_secret ) { return hash_hmac( 'sha256', ( '' === $ts ? '' : $ts . '.' ) . $body, $x1_secret ); };
$x1_phone = '+4915136636600';
$x1_start = json_encode( array( 'from' => $x1_phone, 'text' => 'START' ) );
$x1_stop = json_encode( array( 'from' => $x1_phone, 'text' => 'STOP' ) );
$x1_ts = time();

$r1 = $x1_inbound( $x1_start, $x1_sig( $x1_start, $x1_ts ), $x1_ts );
$r2 = $x1_inbound( $x1_stop, $x1_sig( $x1_stop, $x1_ts ), $x1_ts );
check( 'A366: timestamped START and STOP are accepted; the number is opted out', is_array( $r1 ) && empty( $r1['duplicate'] ) && is_array( $r2 ) && 'stop' === $r2['keyword'] && Service::is_opted_out( $x1_phone ) );
$r3 = $x1_inbound( $x1_start, $x1_sig( $x1_start, $x1_ts ), $x1_ts );
check( 'A366: a replayed START is answered as duplicate and does not opt the number in again', is_array( $r3 ) && true === $r3['duplicate'] && Service::is_opted_out( $x1_phone ) );
$x1_old = $x1_ts - 600;
$r4 = $x1_inbound( $x1_start, $x1_sig( $x1_start, $x1_old ), $x1_old );
check( 'A366: a correctly signed but stale callback (10 min) is refused (403)', is_wp_error( $r4 ) && 'wpcap_sms_stale' === $r4->get_error_code() && 403 === $r4->get_error_data()['status'] && Service::is_opted_out( $x1_phone ) );
$r5 = $x1_inbound( $x1_start, $x1_sig( $x1_start ), $x1_ts + 1 );
check( 'A366: with a timestamp header the signature must cover the timestamp', is_wp_error( $r5 ) && 'wpcap_sms_signature' === $r5->get_error_code() );
$r6 = $x1_inbound( $x1_start, $x1_sig( $x1_start, 'abc' ), 'abc' );
check( 'A366: a non-numeric timestamp is refused', is_wp_error( $r6 ) && 'wpcap_sms_stale' === $r6->get_error_code() );

// Without timestamp (gateways that cannot send one): accepted once, a replay is a duplicate.
$x1_legacy = json_encode( array( 'from' => $x1_phone, 'text' => 'START', 'n' => 1 ) );
$r7 = $x1_inbound( $x1_legacy, $x1_sig( $x1_legacy ) );
$x1_after_legacy = Service::is_opted_out( $x1_phone );
$x1_inbound( $x1_stop . ' ', $x1_sig( $x1_stop . ' ' ) );
$r8 = $x1_inbound( $x1_legacy, $x1_sig( $x1_legacy ) );
check( 'A366: a callback without timestamp is processed once; its replay changes nothing', is_array( $r7 ) && empty( $r7['duplicate'] ) && ! $x1_after_legacy && is_array( $r8 ) && true === $r8['duplicate'] && Service::is_opted_out( $x1_phone ) );

// Message id of the gateway: re-sent with a new timestamp, still processed once.
$x1_mid = json_encode( array( 'from' => $x1_phone, 'text' => 'START', 'message_id' => 'x1-a366-in-1' ) );
$r9 = $x1_inbound( $x1_mid, $x1_sig( $x1_mid, $x1_ts ), $x1_ts );
$x1_inbound( json_encode( array( 'from' => $x1_phone, 'text' => 'STOP', 'message_id' => 'x1-a366-in-2' ) ), $x1_sig( json_encode( array( 'from' => $x1_phone, 'text' => 'STOP', 'message_id' => 'x1-a366-in-2' ) ), $x1_ts ), $x1_ts );
$r10 = $x1_inbound( $x1_mid, $x1_sig( $x1_mid, $x1_ts + 2 ), $x1_ts + 2 );
check( 'A366: the same gateway message id is processed once (also with a new timestamp)', is_array( $r9 ) && empty( $r9['duplicate'] ) && is_array( $r10 ) && true === $r10['duplicate'] && Service::is_opted_out( $x1_phone ) );

// Required timestamp.
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'http_require_timestamp' => 1 ) ) ) );
$x1_new = json_encode( array( 'from' => $x1_phone, 'text' => 'START', 'n' => 2 ) );
$r11 = $x1_inbound( $x1_new, $x1_sig( $x1_new ) );
check( 'A366: with "Zeitstempel verlangen" a callback without timestamp is refused', is_wp_error( $r11 ) && 403 === $r11->get_error_data()['status'] && Service::is_opted_out( $x1_phone ) );

// Status callback replay.
$x1_status = json_encode( array( 'id' => 'x1-a366-none', 'status' => 'delivered' ) );
$req = new WP_REST_Request( 'POST', '/pass-captain/v1/sms/http/status' );
$req->set_body( $x1_status );
$req->set_header( 'X-Signature', $x1_sig( $x1_status, $x1_ts ) );
$req->set_header( 'X-Timestamp', (string) $x1_ts );
$s1 = Service::http_status( $req )->get_data();
$s2 = Service::http_status( $req )->get_data();
check( 'A366: a replayed status callback is answered as duplicate', empty( $s1['duplicate'] ) && true === $s2['duplicate'] );

// Twilio inbound: the signature has no timestamp, a replay is processed once.
$x1_token = 'x1-a366-twilio';
Settings::update( array( 'twilio_auth_token' => $x1_token, 'twilio_account_sid' => 'AC' . str_repeat( 'c3', 16 ), 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000' ) ) ) );
$x1_twilio = static function ( $params ) use ( $x1_token ) {
	ksort( $params );
	$data = 'https://example.test/wp-json/pass-captain/v1/sms/twilio/inbound';
	foreach ( $params as $k => $v ) { $data .= $k . $v; }
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/sms/twilio/inbound' );
	$req->set_body( http_build_query( $params ) );
	$req->set_header( 'X-Twilio-Signature', base64_encode( hash_hmac( 'sha1', $data, $x1_token, true ) ) );
	$res = Service::twilio_inbound( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};
$t_start = array( 'From' => $x1_phone, 'To' => '+4915100000000', 'Body' => 'START', 'MessageSid' => 'SMx1a366start' );
$t1 = $x1_twilio( $t_start );
$x1_twilio( array( 'From' => $x1_phone, 'To' => '+4915100000000', 'Body' => 'STOP', 'MessageSid' => 'SMx1a366stop' ) );
$t2 = $x1_twilio( $t_start );
check( 'A366: a replayed Twilio START does not opt the number in again', is_array( $t1 ) && empty( $t1['duplicate'] ) && is_array( $t2 ) && true === $t2['duplicate'] && Service::is_opted_out( $x1_phone ) );

check( 'A366: RateLimit::claim() is true once per key', RateLimit::claim( 'x1-a366-claim', 60 ) && ! RateLimit::claim( 'x1-a366-claim', 60 ) );
$x1_backend = $GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';
$x1_c1 = RateLimit::claim( 'x1-a366-claim-db', 60 );
$x1_c2 = RateLimit::claim( 'x1-a366-claim-db', 60 );
$x1_db = RateLimit::$last_backend;
$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_rate_limits SET expires_at='2000-01-01 00:00:00' WHERE expires_at>NOW() - INTERVAL 1 DAY AND hits=2" );
$x1_c3 = RateLimit::claim( 'x1-a366-claim-db', 60 );
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = $x1_backend;
check( 'A366: the database claim is true once and again after it expired', 'db' === $x1_db && $x1_c1 && ! $x1_c2 && $x1_c3 );
Settings::update( array( 'sms' => $x1_sms_before ) );
