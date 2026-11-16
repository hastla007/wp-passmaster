<?php
/**
 * A141: SMS segment math (GSM-7/UCS-2, 153/67 per part), cost per message (provider price or
 * configured price per segment and country), monthly budget with warning and hard stop, cost
 * report, signed delivery status callbacks (Twilio, generic HTTP), consent source/withdrawal source
 * and opt-out list (STOP/START).
 */

// phpcs:ignoreFile

use PassCaptain\Installer;
use PassCaptain\Modules\Sms;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Sms\Costs;
use PassCaptain\Sms\HttpProvider;
use PassCaptain\Sms\Segments;
use PassCaptain\Sms\Service;

global $wpdb;
$p = $wpdb->prefix;

/** Response data whether rest_ensure_response() wraps or not. */
function a141_data( $response ) {
	return is_object( $response ) && method_exists( $response, 'get_data' ) ? (array) $response->get_data() : (array) $response;
}

echo "A141: schema\n";
check( 'schema 3.10.2: cost columns, consent source and opt-out table, Service::ready()', version_compare( Installer::DB_VERSION, '3.10.2', '>=' ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_sms_messages LIKE 'cost'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_sms_consents LIKE 'source'" ) && Service::ready() );

echo "A141: segment math\n";
$seg = static fn( $t ) => Segments::count( $t );
check( 'empty text: 0 segments', 0 === $seg( '' )['segments'] );
check( 'GSM-7: 160 chars = 1 segment, 161 = 2', 1 === $seg( str_repeat( 'a', 160 ) )['segments'] && 'gsm7' === $seg( str_repeat( 'a', 160 ) )['encoding'] && 2 === $seg( str_repeat( 'a', 161 ) )['segments'] );
check( 'GSM-7 concatenated: 306 chars = 2 x 153, 307 = 3', 2 === $seg( str_repeat( 'a', 306 ) )['segments'] && 3 === $seg( str_repeat( 'a', 307 ) )['segments'] && 153 === $seg( str_repeat( 'a', 307 ) )['per_segment'] );
check( 'German umlauts and ß stay GSM-7', 'gsm7' === $seg( 'Grüße aus Köln: Ä Ö Ü ä ö ü ß é' )['encoding'] );
check( 'extension characters count double: 80 x € = 160 septets (1 segment), 81 x € = 2', 160 === $seg( str_repeat( '€', 80 ) )['units'] && 1 === $seg( str_repeat( '€', 80 ) )['segments'] && 2 === $seg( str_repeat( '€', 81 ) )['segments'] );
check( 'escape sequence is not split across parts (152 a + 77 € = 306 septets -> 3 parts)', 306 === $seg( str_repeat( 'a', 152 ) . str_repeat( '€', 77 ) )['units'] && 3 === $seg( str_repeat( 'a', 152 ) . str_repeat( '€', 77 ) )['segments'] );
check( 'one non-GSM character switches to UCS-2', 'ucs2' === $seg( 'Hallo Łódź' )['encoding'] && 'ucs2' === $seg( str_repeat( 'a', 100 ) . 'ł' )['encoding'] && 2 === $seg( str_repeat( 'a', 100 ) . 'ł' )['segments'] );
check( 'UCS-2: 70 chars = 1 segment, 71 = 2, 134 = 2 x 67, 135 = 3', 1 === $seg( str_repeat( 'ł', 70 ) )['segments'] && 2 === $seg( str_repeat( 'ł', 71 ) )['segments'] && 2 === $seg( str_repeat( 'ł', 134 ) )['segments'] && 3 === $seg( str_repeat( 'ł', 135 ) )['segments'] && 67 === $seg( str_repeat( 'ł', 135 ) )['per_segment'] );
check( 'emoji count as two UTF-16 units: 35 emoji = 1 segment, 36 = 2', 70 === $seg( str_repeat( '😀', 35 ) )['units'] && 1 === $seg( str_repeat( '😀', 35 ) )['segments'] && 2 === $seg( str_repeat( '😀', 36 ) )['segments'] );
check( 'surrogate pair is not split (66 a + 3 emoji = 72 units -> 2 parts)', 2 === $seg( str_repeat( 'a', 66 ) . str_repeat( '😀', 3 ) )['segments'] && 1 === $seg( str_repeat( 'a', 66 ) . '😀' )['segments'] );

echo "A141: prices\n";
$a141_sms = array_merge( Sms::settings_defaults()['sms'], (array) Settings::get( 'sms' ), array( 'provider' => 'twilio', 'twilio_from' => '+4915100000000', 'default_country_code' => '+49', 'allowed_prefixes' => '+43,+49,+41', 'currency' => 'EUR', 'prices' => "+49 0.075\n+43 0,10", 'price_default' => '0.2', 'budget_monthly' => '', 'budget_warn_percent' => 80, 'budget_hard_stop' => 1 ) );
Settings::update( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a1', 16 ), 'twilio_auth_token' => 'twilio-auth-token-123', 'sms' => $a141_sms ) );
check( 'price table parsed (comma decimals, longest prefix first)', array( '+49' => 0.075, '+43' => 0.1 ) == Costs::prices() );
check( 'price per segment by destination, default price otherwise', 0.075 === Costs::price_per_segment( '+4915112345678' ) && 0.1 === Costs::price_per_segment( '+436641234567' ) && 0.2 === Costs::price_per_segment( '+41791234567' ) );
check( 'estimate = segments x price, country by prefix', 0.15 === Costs::estimate( '+4915112345678', 2 ) && '+43' === Costs::country_of( '+436641234567' ) && '+41' === Costs::country_of( '+41791234567' ) );
check( 'admin input of the price table is sanitised', "+49 0.075\n+4366 0.12" === Costs::sanitize_prices( "+49 = 0,075\nfoo\n+4366: 0.12\n49 1" ) );

$GLOBALS['wpcap_test_transients'] = array();
$a141_type = make_type( 50, 10, 0 );
$a141_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a141_type" );
$a141_n = 0;
$a141_order = static function ( $phone ) use ( $a141_type, &$a141_n ) {
	$a141_n++;
	$GLOBALS['wpcap_test_transients'] = array();
	$sms = '' === $phone ? array() : array( 'sms_consent' => 1, 'sms_phone' => $phone );
	return Orders::create( array_merge( array( 'email' => "wpa141-$a141_n@example.com", 'items' => array( array( 'ticket_type_id' => $a141_type, 'quantity' => 1 ) ), 'idempotency_key' => "wpa141-$a141_n" ), $sms ) );
};
$a141_msg = static function ( $order_id, $key ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_sms_messages WHERE dedupe_key=%s", hash( 'sha256', $key ) ) );
};
$a141_sid = static fn( $c ) => 'SM' . str_repeat( $c, 32 );

echo "A141: cost per message\n";
$o1 = $a141_order( '+4915141000001' );
Service::enqueue( 'wpa141-c1', $o1['order_id'], $a141_occ, 'reminder', str_repeat( 'a', 200 ) );
$queued = $a141_msg( $o1['order_id'], 'wpa141-c1' );
check( 'segments and encoding stored when queued', 2 === (int) $queued->segments && 'gsm7' === $queued->encoding && null === $queued->cost );
http_reset();
http_push( array( 'sid' => $a141_sid( '1' ), 'status' => 'queued', 'price' => null ), 201, '/Messages.json' );
Service::process_due();
$m1 = $a141_msg( $o1['order_id'], 'wpa141-c1' );
check( 'without provider price: configured price x segments, country and sent_at recorded', 'sent' === $m1->status && '0.15000' === $m1->cost && 'EUR' === $m1->cost_currency && 'configured' === $m1->cost_source && '+49' === $m1->country && null !== $m1->sent_at );

Service::enqueue( 'wpa141-c2', $o1['order_id'], $a141_occ, 'reminder', 'Kurz' );
http_reset();
http_push( array( 'sid' => $a141_sid( '2' ), 'price' => '-0.08100', 'price_unit' => 'EUR', 'num_segments' => '1' ), 201, '/Messages.json' );
Service::process_due();
$m2 = $a141_msg( $o1['order_id'], 'wpa141-c2' );
check( 'provider-reported price wins (sign ignored)', '0.08100' === $m2->cost && 'provider' === $m2->cost_source && 1 === (int) $m2->segments );

Service::enqueue( 'wpa141-c3', $o1['order_id'], $a141_occ, 'reminder', 'Kurz 3' );
http_reset();
http_push( array( 'sid' => $a141_sid( '3' ), 'price' => '-0.0079', 'price_unit' => 'USD' ), 201, '/Messages.json' );
Service::process_due();
$m3 = $a141_msg( $o1['order_id'], 'wpa141-c3' );
check( 'provider price in another currency without conversion: configured price', '0.07500' === $m3->cost && 'configured' === $m3->cost_source && 'EUR' === $m3->cost_currency );
$GLOBALS['wpcap_test_filters']['wpcap_sms_convert_price'] = 0.0071;
check( 'conversion filter makes a foreign provider price usable', 0.0071 === Costs::provider_amount( '-0.0079', 'USD' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_sms_convert_price'] );
check( 'provider price converted only by the filter (null otherwise)', null === Costs::provider_amount( '0.01', 'USD' ) && 0.01 === Costs::provider_amount( '0,01', 'eur' ) && null === Costs::provider_amount( 'abc', '' ) );

$o_ch = $a141_order( '+41791410002' );
Service::enqueue( 'wpa141-c4', $o_ch['order_id'], $a141_occ, 'reminder', str_repeat( 'ł', 80 ) );
http_reset();
http_push( array( 'sid' => $a141_sid( '4' ) ), 201, '/Messages.json' );
Service::process_due();
$m4 = $a141_msg( $o_ch['order_id'], 'wpa141-c4' );
check( 'UCS-2 message to a country without own price: default price x 2 segments', 'ucs2' === $m4->encoding && 2 === (int) $m4->segments && '0.40000' === $m4->cost && '+41' === $m4->country );

echo "A141: delivery status callback (Twilio, with price)\n";
$a141_twilio = static function ( $route, $params, $key ) {
	ksort( $params );
	$data = 'https://example.test/wp-json/pass-captain/v1/sms/twilio/' . $route;
	foreach ( $params as $k => $v ) { $data .= $k . $v; }
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/sms/twilio/' . $route );
	$req->set_body( http_build_query( $params ) );
	$req->set_header( 'X-Twilio-Signature', base64_encode( hash_hmac( 'sha1', $data, $key, true ) ) );
	return 'status' === $route ? Service::twilio_status( $req ) : Service::twilio_inbound( $req );
};
$cb = array( 'MessageSid' => $a141_sid( '1' ), 'MessageStatus' => 'delivered', 'Price' => '-0.07000', 'PriceUnit' => 'EUR' );
$bad = $a141_twilio( 'status', $cb, 'wrong' );
check( 'forged Twilio callback rejected, cost unchanged', is_wp_error( $bad ) && '0.15000' === $a141_msg( $o1['order_id'], 'wpa141-c1' )->cost );
$a141_twilio( 'status', $cb, 'twilio-auth-token-123' );
$m1 = $a141_msg( $o1['order_id'], 'wpa141-c1' );
check( 'signed Twilio callback: delivered, provider price replaces the estimate, status time set', 'delivered' === $m1->status && '0.07000' === $m1->cost && 'provider' === $m1->cost_source && null !== $m1->status_at );

echo "A141: delivery status callback (generic HTTP, HMAC-SHA256)\n";
$a141_secret = 'wpa141-webhook-secret';
Settings::update( array( 'sms_http_secret' => 'Bearer wpa141', 'sms' => array_merge( $a141_sms, array( 'provider' => 'http', 'http_endpoint' => 'https://sms.example/a141', 'http_signature_header' => 'X-Signature' ) ) ) );
Service::enqueue( 'wpa141-h1', $o1['order_id'], $a141_occ, 'reminder', 'HTTP eins' );
Service::enqueue( 'wpa141-h2', $o1['order_id'], $a141_occ, 'reschedule', 'HTTP zwei' );
http_reset();
http_push( array( 'id' => 'gw-a141-1', 'price' => '0.065', 'currency' => 'EUR', 'segments' => 1 ), 200, 'sms.example' );
http_push( array( 'id' => 'gw-a141-2' ), 200, 'sms.example' );
Service::process_due();
$h1 = $a141_msg( $o1['order_id'], 'wpa141-h1' );
$h2 = $a141_msg( $o1['order_id'], 'wpa141-h2' );
check( 'HTTP gateway response price recorded as provider cost', 'sent' === $h1->status && '0.06500' === $h1->cost && 'provider' === $h1->cost_source && 'configured' === $h2->cost_source );
$a141_http = static function ( $route, $body, $signature, $header = 'X-Signature' ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/sms/http/' . $route );
	$req->set_body( $body );
	if ( null !== $signature ) { $req->set_header( $header, $signature ); }
	return 'status' === $route ? Service::http_status( $req ) : Service::http_inbound( $req );
};
$body = json_encode( array( 'reference' => 'wpcap-sms-' . $h2->message_id, 'status' => 'DELIVRD', 'price' => '0.07', 'currency' => 'EUR' ) );
$no_secret = $a141_http( 'status', $body, hash_hmac( 'sha256', $body, $a141_secret ) );
check( 'without configured webhook secret every HTTP callback is rejected (403)', is_wp_error( $no_secret ) && 403 === $no_secret->get_error_data()['status'] );
Settings::update( array( 'sms_http_webhook_secret' => $a141_secret ) );
$forged = $a141_http( 'status', $body, hash_hmac( 'sha256', $body, 'wrong' ) );
$unsigned = $a141_http( 'status', $body, null );
$tampered = $a141_http( 'status', str_replace( 'DELIVRD', 'failed', $body ), hash_hmac( 'sha256', $body, $a141_secret ) );
check( 'forged, unsigned and tampered HTTP callbacks rejected; status unchanged', is_wp_error( $forged ) && is_wp_error( $unsigned ) && is_wp_error( $tampered ) && 'sent' === $a141_msg( $o1['order_id'], 'wpa141-h2' )->status );
$ok = $a141_http( 'status', $body, 'sha256=' . hash_hmac( 'sha256', $body, $a141_secret ) );
$h2 = $a141_msg( $o1['order_id'], 'wpa141-h2' );
check( 'signed HTTP callback by reference: DELIVRD mapped to delivered, price recorded', ! is_wp_error( $ok ) && 1 === a141_data( $ok )['updated'] && 'delivered' === $h2->status && '0.07000' === $h2->cost && 'provider' === $h2->cost_source );
$form = http_build_query( array( 'id' => 'gw-a141-1', 'status' => 'undelivered', 'error_code' => '30005' ) );
$a141_http( 'status', $form, base64_encode( hash_hmac( 'sha256', $form, $a141_secret, true ) ) );
$h1 = $a141_msg( $o1['order_id'], 'wpa141-h1' );
check( 'form-encoded callback by provider id with base64 signature: undelivered with error code', 'undelivered' === $h1->status && 'provider_error_30005' === $h1->last_error );
$late = json_encode( array( 'id' => 'gw-a141-1', 'status' => 'sent' ) );
$a141_http( 'status', $late, hash_hmac( 'sha256', $late, $a141_secret ) );
check( 'late "sent" does not downgrade a final status', 'undelivered' === $a141_msg( $o1['order_id'], 'wpa141-h1' )->status );
$other = json_encode( array( 'reference' => 'wpcap-sms-' . $m4->message_id, 'status' => 'delivered' ) );
$r_other = $a141_http( 'status', $other, hash_hmac( 'sha256', $other, $a141_secret ) );
check( 'HTTP callback cannot change a message of another provider', 0 === a141_data( $r_other )['updated'] && 'sent' === $a141_msg( $o_ch['order_id'], 'wpa141-c4' )->status );

echo "A141: monthly budget\n";
$GLOBALS['wpcap_test_options']['admin_email'] = 'admin@example.com';
unset( $GLOBALS['wpcap_test_options'][ Costs::NOTICE_OPTION ] );
$spent = Costs::month_spent();
check( 'month spend sums the recorded costs of this month', abs( $spent - ( 0.07 + 0.081 + 0.075 + 0.4 + 0.065 + 0.07 ) ) < 0.00001 );
check( 'no budget: everything allowed', Costs::allows( 1000 ) );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'budget_monthly' => (string) round( $spent + 0.2, 5 ), 'budget_warn_percent' => 90, 'budget_hard_stop' => 1 ) ) ) );
$o2 = $a141_order( '+4915141000003' );
$GLOBALS['wpcap_test_mails'] = array();
Service::enqueue( 'wpa141-b1', $o2['order_id'], $a141_occ, 'reminder', str_repeat( 'b', 200 ) );
Service::enqueue( 'wpa141-b2', $o2['order_id'], $a141_occ, 'reminder', str_repeat( 'c', 200 ) );
Service::enqueue( 'wpa141-b3', $o2['order_id'], $a141_occ, 'cancellation', 'Abgesagt' );
http_reset();
http_push( array( 'id' => 'gw-a141-b1' ), 200, 'sms.example' );
http_push( array( 'id' => 'gw-a141-b2' ), 200, 'sms.example' );
http_push( array( 'id' => 'gw-a141-b3' ), 200, 'sms.example' );
$sent = Service::process_due();
$b1 = $a141_msg( $o2['order_id'], 'wpa141-b1' );
$b2 = $a141_msg( $o2['order_id'], 'wpa141-b2' );
$b3 = $a141_msg( $o2['order_id'], 'wpa141-b3' );
check( 'within budget: first message sent (0.15 of 0.20 left)', 1 === $sent && 'sent' === $b1->status && '0.15000' === $b1->cost );
check( 'hard stop: messages that would exceed the budget are held, not sent', 'held' === $b2->status && 'budget_exceeded' === $b2->last_error && 'held' === $b3->status && 1 === count( http_requests( 'sms.example' ) ) && 2 === Service::held_count() );
$subjects = array_column( $GLOBALS['wpcap_test_mails'] ?? array(), 'subject' );
check( 'warning at the threshold and stop notice: one mail each, audit entries written', 1 === count( array_filter( $subjects, static fn( $s ) => false !== strpos( $s, 'fast erreicht' ) ) ) && 1 === count( array_filter( $subjects, static fn( $s ) => false !== strpos( $s, 'ausgeschöpft' ) ) ) && null !== $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='sms_budget_stop'" ) && null !== $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='sms_budget_warning'" ) );
$st = Costs::status();
check( 'budget status: warning flagged, not yet exhausted', $st['warning'] && ! $st['exhausted'] && $st['percent'] >= 90 );
$GLOBALS['wpcap_test_mails'] = array();
Service::release_held();
http_reset();
Service::process_due();
check( 'released without more budget: held again, no second stop mail this month', 'held' === $a141_msg( $o2['order_id'], 'wpa141-b2' )->status && ! http_requests( 'sms.example' ) && ! $GLOBALS['wpcap_test_mails'] );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'budget_monthly' => (string) round( $spent + 10, 5 ) ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'releasing held messages requires manage_options', is_wp_error( Sms::release_held() ) && 2 === Service::held_count() );
$GLOBALS['wpcap_test_denied_caps'] = array();
$rel = Sms::release_held();
http_reset();
http_push( array( 'id' => 'gw-a141-b2' ), 200, 'sms.example' );
http_push( array( 'id' => 'gw-a141-b3' ), 200, 'sms.example' );
Service::process_due();
check( 'after raising the budget the released messages are sent', 2 === $rel['released'] && 'sent' === $a141_msg( $o2['order_id'], 'wpa141-b2' )->status && 'sent' === $a141_msg( $o2['order_id'], 'wpa141-b3' )->status && 0 === Service::held_count() );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'budget_monthly' => '0.01', 'budget_hard_stop' => 0 ) ) ) );
Service::enqueue( 'wpa141-b4', $o2['order_id'], $a141_occ, 'reminder', 'Ohne Stopp' );
http_reset();
http_push( array( 'id' => 'gw-a141-b4' ), 200, 'sms.example' );
Service::process_due();
check( 'without hard stop the budget only warns', 'sent' === $a141_msg( $o2['order_id'], 'wpa141-b4' )->status && Costs::status()['exhausted'] );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'budget_hard_stop' => 1 ) ) ) );
Service::enqueue( 'wpa141-b5', $o2['order_id'], $a141_occ, 'reminder', 'Verfällt' );
Service::process_due();
check( 'exhausted budget holds the next message', 'held' === $a141_msg( $o2['order_id'], 'wpa141-b5' )->status );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_sms_messages SET created_at=%s WHERE dedupe_key=%s", gmdate( 'Y-m-d H:i:s', time() - 8 * 86400 ), hash( 'sha256', 'wpa141-b5' ) ) );
Service::process_due();
check( 'held messages expire after 7 days', 'skipped' === $a141_msg( $o2['order_id'], 'wpa141-b5' )->status && 'budget_exceeded' === $a141_msg( $o2['order_id'], 'wpa141-b5' )->last_error );
Service::enqueue( 'wpa141-b6', $o2['order_id'], $a141_occ, 'reminder', 'Widerruf' );
Service::process_due();
Service::withdraw( $o2['order_id'], 'admin' );
check( 'withdrawal also skips held messages', 'skipped' === $a141_msg( $o2['order_id'], 'wpa141-b6' )->status );
check( 'unknown price never blocks (no estimate)', Costs::allows( null ) );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'budget_monthly' => '' ) ) ) );

echo "A141: cost report\n";
$report = Costs::report( 12 );
$cur = array_values( array_filter( $report, static fn( $r ) => gmdate( 'Y-m' ) === $r->month && 'EUR' === $r->currency ) )[0] ?? null;
check( 'monthly report: messages, segments, cost and provider-reported share', $cur && (int) $cur->messages >= 9 && (int) $cur->segments >= 12 && (float) $cur->cost > 0 && (float) $cur->provider_cost >= 0.07 + 0.081 + 0.065 + 0.07 - 0.00001 );
$by_country = array();
foreach ( Costs::breakdown() as $r ) { $by_country[ $r->country ] = ( $by_country[ $r->country ] ?? 0 ) + (float) $r->cost; }
check( 'breakdown by destination country', isset( $by_country['+49'], $by_country['+41'] ) && abs( $by_country['+41'] - 0.4 ) < 0.00001 );
ob_start();
Sms::render_cost_report();
$html = ob_get_clean();
check( 'admin cost report renders the month and the country table', false !== strpos( $html, 'Kostenbericht' ) && false !== strpos( $html, gmdate( 'Y-m' ) ) && false !== strpos( $html, '+41' ) && false !== strpos( $html, 'EUR' ) );
$saved = Sms::save( array_merge( $a141_sms, array( 'provider' => 'http', 'http_endpoint' => 'https://sms.example/a141', 'prices' => "+49 = 0,08\nunsinn", 'price_default' => 'abc', 'budget_monthly' => '25,50', 'budget_warn_percent' => '150', 'currency' => 'chf', 'budget_hard_stop' => '' ) ) );
$s = (array) Settings::get( 'sms' );
check( 'admin save sanitises prices, budget, warning threshold, hard stop flag and currency', ! is_wp_error( $saved ) && "+49 0.08" === $s['prices'] && '' === $s['price_default'] && '25.5' === $s['budget_monthly'] && 100 === $s['budget_warn_percent'] && 0 === $s['budget_hard_stop'] && 'CHF' === $s['currency'] );
Settings::update( array( 'sms' => array_merge( $a141_sms, array( 'provider' => 'http', 'http_endpoint' => 'https://sms.example/a141' ) ) ) );

echo "A141: consent source, withdrawal source and opt-out (STOP/START)\n";
$o3 = $a141_order( '+4915141000004' );
$c3 = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o3['order_id'] );
check( 'checkout consent records source and timestamp', 'checkout' === $c3->source && null !== $c3->consented_at && '' === $c3->withdrawn_source );
$o4 = $a141_order( '' );
check( 'consent recorded by an admin import keeps its source', Service::record_consent( $o4['order_id'], '+4915141000005', null, 'Admin Import' ) && 'adminimport' === $wpdb->get_var( "SELECT source FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o4['order_id'] ) );
Service::withdraw( $o4['order_id'], 'admin' );
Service::withdraw( $o4['order_id'], 'privacy_erasure' );
$c4 = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o4['order_id'] );
check( 'withdrawal records time and source; a second withdrawal keeps the first source', 'admin' === $c4->withdrawn_source && null !== $c4->withdrawn_at && '' === $c4->phone );

$o5 = $a141_order( '+4915141000006' );
$o6 = $a141_order( '+4915141000006' );
Service::enqueue( 'wpa141-s1', $o5['order_id'], $a141_occ, 'reminder', 'Vor STOP' );
$stop = json_encode( array( 'from' => '+49 151 41000006', 'text' => ' Stop ' ) );
$forged = $a141_http( 'inbound', $stop, hash_hmac( 'sha256', $stop, 'wrong' ) );
check( 'forged inbound STOP is rejected and withdraws nothing', is_wp_error( $forged ) && null === $wpdb->get_var( "SELECT withdrawn_at FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o5['order_id'] ) );
$r = $a141_http( 'inbound', $stop, hash_hmac( 'sha256', $stop, $a141_secret ) );
$c5 = $wpdb->get_row( "SELECT * FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o5['order_id'] );
check( 'signed inbound STOP (HTTP gateway) withdraws all consents of the number with source inbound_stop', ! is_wp_error( $r ) && 'stop' === a141_data( $r )['keyword'] && 2 === a141_data( $r )['withdrawn'] && 'inbound_stop' === $c5->withdrawn_source && '' === $c5->phone && 'inbound_stop' === $wpdb->get_var( "SELECT withdrawn_source FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o6['order_id'] ) && 'skipped' === $a141_msg( $o5['order_id'], 'wpa141-s1' )->status );
check( 'number is on the opt-out list only as hash', Service::is_opted_out( '+4915141000006' ) && null !== $wpdb->get_var( $wpdb->prepare( "SELECT phone_hash FROM {$p}wpcap_sms_optouts WHERE phone_hash=%s AND source='inbound_stop'", Service::phone_hash( '+4915141000006' ) ) ) && null === $wpdb->get_var( "SELECT phone_hash FROM {$p}wpcap_sms_optouts WHERE phone_hash LIKE '%4915141000006%'" ) );
$again = $a141_order( '+4915141000006' );
check( 'checkout refuses a new consent for an opted-out number', is_wp_error( $again ) && false !== strpos( $again->get_error_message(), 'STOP' ) );
$o7 = $a141_order( '' );
check( 'record_consent refuses an opted-out number', false === Service::record_consent( $o7['order_id'], '+4915141000006', null, 'admin' ) );
$start = json_encode( array( 'from' => '+4915141000006', 'text' => 'START' ) );
$r = $a141_http( 'inbound', $start, hash_hmac( 'sha256', $start, $a141_secret ) );
check( 'START removes the number from the opt-out list; old consents stay withdrawn', 'start' === a141_data( $r )['keyword'] && ! Service::is_opted_out( '+4915141000006' ) && '' === $wpdb->get_var( "SELECT phone FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o5['order_id'] ) );
$o8 = $a141_order( '+4915141000006' );
check( 'after START a new explicit consent is possible', ! is_wp_error( $o8 ) && null !== Service::consent_for_order( $o8['order_id'] ) );
$o9 = $a141_order( '+4915141000007' );
$tw = $a141_twilio( 'inbound', array( 'From' => '+4915141000007', 'To' => '+4915100000000', 'Body' => 'ABMELDEN', 'MessageSid' => 'SMa141' ), 'twilio-auth-token-123' );
check( 'Twilio inbound STOP keyword also puts the number on the opt-out list', ! is_wp_error( $tw ) && 1 === a141_data( $tw )['withdrawn'] && Service::is_opted_out( '+4915141000007' ) && null === Service::consent_for_order( $o9['order_id'] ) );
check( 'keyword detection', 'stop' === Service::keyword( 'stopp!' ) && 'start' === Service::keyword( ' unstop ' ) && '' === Service::keyword( 'Bitte nicht mehr' ) );
$exp = Sms::export( 'wpa141-' . ( $a141_n - 6 ) . '@example.com' );
check( 'privacy export lists consent source and withdrawal source', ! empty( $exp['data'] ) && in_array( 'Quelle der Einwilligung', array_column( $exp['data'][0]['data'], 'name' ), true ) );
Sms::erase( 'wpa141-' . ( $a141_n - 1 ) . '@example.com' );
check( 'privacy erasure of an active consent: source privacy_erasure', 'privacy_erasure' === $wpdb->get_var( "SELECT withdrawn_source FROM {$p}wpcap_sms_consents WHERE order_id=" . (int) $o8['order_id'] ) );

// Switch SMS off for later test files.
unset( $GLOBALS['wpcap_test_options']['admin_email'] );
Settings::update( array( 'sms_http_webhook_secret' => '', 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => '', 'budget_monthly' => '' ) ) ) );
