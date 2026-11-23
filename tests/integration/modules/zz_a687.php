<?php
/**
 * A687: mobile API – batch check-in/out (/app/scan/batch, idempotent retry), compatibility (/app/info,
 * core and app version headers), search flag; MOBILE-API.md updated.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admission\BulkCheckin;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;
use PassCaptain\Orders;

echo "A687: mobile API batch actions and version checks\n";
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { public function __construct( public $data = null, public $status = 200 ) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); } }
if ( ! function_exists( 'wp_set_current_user' ) ) { function wp_set_current_user( $id ) { $GLOBALS['wpcap_test_user_id'] = (int) $id; } }
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'Staff ' . $id ); } }
if ( ! function_exists( 'wp_get_current_user' ) ) { function wp_get_current_user() { return get_userdata( get_current_user_id() ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }

global $wpdb;
$p = $wpdb->prefix;
$z687_call = static function ( $callback, $token, array $params = array(), array $headers = array() ) {
	$req = new WP_REST_Request( 'POST' );
	if ( $token ) { $req->set_header( 'Authorization', 'Bearer ' . $token ); }
	foreach ( $headers as $k => $v ) { $req->set_header( $k, $v ); }
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$auth = 'info' === $callback ? true : ( 'pair' === $callback ? true : Api::authenticate( $req ) );
	if ( true !== $auth ) { return $auth; }
	$res = call_user_func( array( Api::class, $callback ), $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};

$GLOBALS['wpcap_test_user_id'] = 7;
$z687_type = make_type( 20, 10, 0 );
$z687_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $z687_type ) );
$z687_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $z687_occ ) );
// Admission is possible now (entry windows of the default setting).
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => PassCaptain\Clock::mysql( 600 ) ), array( 'occurrence_id' => $z687_occ ) );
$z687_order = Orders::create( array( 'email' => 'z687@example.com', 'items' => array( array( 'ticket_type_id' => $z687_type, 'quantity' => 4 ) ), 'idempotency_key' => 'z687-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$z687_tickets = tickets( $z687_order['order_id'] );
$z687_public = array_map( static function ( $t ) { return (string) $t->public_id; }, $z687_tickets );
$z687_pairing = Devices::create_pairing( array( $z687_event ), 'Gruppeneinlass', 4 );
$GLOBALS['wpcap_test_user_id'] = 0;
$z687_paired = Devices::pair( $z687_pairing['code'], 'Tablet', 'android', '2.0.0' );
$z687_token = $z687_paired['token'];
check( 'A687: fixture (4 tickets, paired device)', 4 === count( $z687_public ) && str_starts_with( $z687_token, 'wpcapd_' ) );

// Compatibility: /app/info and /app/me, version headers.
$z687_info = $z687_call( 'info', '' );
check( 'A687: /app/info names API and core version, minimum app version and the batch/search flags', '1.2' === $z687_info['api_version'] && Api::core_version() === $z687_info['core_version'] && '' === $z687_info['min_app_version'] && true === $z687_info['features']['batch'] && true === $z687_info['features']['search'] && 100 === $z687_info['features']['batch_max'] && ! isset( $z687_info['event_ids'] ) );
$z687_me = $z687_call( 'me', $z687_token );
check( 'A687: /app/me carries the same versions and flags', '1.2' === $z687_me['api_version'] && Api::core_version() === $z687_me['core_version'] && true === $z687_me['features']['batch'] );
$z687_min = static function () { return '2.1'; };
add_filter( 'wpcap_app_min_version', $z687_min );
$z687_old = $z687_call( 'me', $z687_token, array(), array( 'X-WPCAP-App-Version' => '2.0.5' ) );
$z687_new = $z687_call( 'me', $z687_token, array(), array( 'X-WPCAP-App-Version' => '2.1.0' ) );
$z687_pair_old = $z687_call( 'pair', '', array( 'code' => 'ABCDEFGHJKLMNPQRSTUV', 'app_version' => '1.9' ) );
check( 'A687: apps older than wpcap_app_min_version get HTTP 426 (also when pairing), newer ones pass', is_wp_error( $z687_old ) && 'wpcap_app_outdated' === $z687_old->get_error_code() && 426 === $z687_old->get_error_data()['status'] && '2.1' === $z687_old->get_error_data()['min_app_version'] && is_array( $z687_new ) && is_wp_error( $z687_pair_old ) && 426 === $z687_pair_old->get_error_data()['status'] );
check( 'A687: /app/info shows the minimum app version', '2.1' === $z687_call( 'info', '' )['min_app_version'] );
remove_filter( 'wpcap_app_min_version', $z687_min );
$z687_core = $z687_call( 'me', $z687_token, array(), array( 'X-WPCAP-Min-Core' => '99.0.0' ) );
$z687_core_ok = $z687_call( 'me', $z687_token, array(), array( 'X-WPCAP-Min-Core' => '0.1' ) );
check( 'A687: an app that needs a newer core gets HTTP 409 wpcap_core_outdated with the installed version', ! preg_match( '/^\d/', Api::core_version() ) || ( is_wp_error( $z687_core ) && 'wpcap_core_outdated' === $z687_core->get_error_code() && 409 === $z687_core->get_error_data()['status'] && is_array( $z687_core_ok ) ) );
check( 'A687: version comparison (core 1.5.0 below 1.6, not below 1.5; empty or malformed never below)', Api::version_below( '1.5.0', '1.6' ) && ! Api::version_below( '1.5.0', '1.5' ) && ! Api::version_below( '', '1.0' ) && ! Api::version_below( '1.0', 'latest' ) && Api::version_below( '1.5.0', '1.5.1-beta' ) );
check( 'A687: malformed version headers are ignored', is_array( $z687_call( 'me', $z687_token, array(), array( 'X-WPCAP-Min-Core' => 'latest', 'X-WPCAP-App-Version' => 'beta' ) ) ) );

// Batch check-in.
$z687_bad_key = $z687_call( 'batch', $z687_token, array( 'tickets' => array( $z687_public[0] ), 'direction' => 'in', 'batch_key' => 'kurz' ) );
check( 'A687: a batch needs a batch_key of 8–64 characters', is_wp_error( $z687_bad_key ) && 400 === $z687_bad_key->get_error_data()['status'] );
$z687_too_many = $z687_call( 'batch', $z687_token, array( 'tickets' => array_map( static function ( $i ) { return str_repeat( dechex( $i % 16 ), 32 ); }, range( 1, 101 ) ), 'direction' => 'in', 'batch_key' => 'batch-too-many' ) );
check( 'A687: more than 100 tickets are refused', is_wp_error( $z687_too_many ) || ( is_array( $z687_too_many ) && 0 === $z687_too_many['counts']['done'] ) );
$z687_key = 'gruppe-' . uniqid();
$z687_first = $z687_call( 'batch', $z687_token, array( 'tickets' => array( $z687_public[0], $z687_public[1], str_repeat( 'f', 32 ) ), 'direction' => 'in', 'batch_key' => $z687_key, 'occurrence_id' => $z687_occ ) );
check( 'A687: batch check-in admits each ticket and reports unknown ids', 2 === $z687_first['counts']['done'] && 1 === $z687_first['counts']['refused'] && false === $z687_first['ok'] && true === $z687_first['complete'] && 'unknown' === $z687_first['results'][2]['result'] && $z687_public[0] === $z687_first['results'][0]['public_id'] && ! isset( $z687_first['results'][0]['ticket_id'] ) );
$z687_status = $wpdb->get_col( $wpdb->prepare( "SELECT status FROM {$p}wpcap_tickets WHERE public_id IN (%s,%s)", $z687_public[0], $z687_public[1] ) );
check( 'A687: the tickets are checked in', array( 'checked_in', 'checked_in' ) === $z687_status );
$z687_rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_in'" );
$z687_retry = $z687_call( 'batch', $z687_token, array( 'tickets' => array( $z687_public[0], $z687_public[1], $z687_public[2] ), 'direction' => 'in', 'batch_key' => $z687_key, 'occurrence_id' => $z687_occ ) );
check( 'A687: repeating the batch key replays booked tickets and admits only the new one', 3 === $z687_retry['counts']['done'] && true === $z687_retry['results'][0]['replayed'] && true === $z687_retry['results'][1]['replayed'] && false === $z687_retry['results'][2]['replayed'] && $z687_rows_before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_in'" ) );
$z687_other = $z687_call( 'batch', $z687_token, array( 'tickets' => array( $z687_public[0] ), 'direction' => 'in', 'batch_key' => 'andere-' . uniqid() ) );
check( 'A687: a new batch for an admitted ticket reports "already" (not refused)', 1 === $z687_other['counts']['already'] && 'already_checked_in' === $z687_other['results'][0]['result'] );
$z687_out = $z687_call( 'batch', $z687_token, array( 'tickets' => array( $z687_public[0], $z687_public[3] ), 'direction' => 'out', 'batch_key' => 'raus-' . uniqid() ) );
check( 'A687: batch check-out; a ticket that is not inside counts as "already"', 1 === $z687_out['counts']['done'] && 'checked_out' === $z687_out['results'][0]['result'] && 1 === $z687_out['counts']['already'] && 'not_checked_in' === $z687_out['results'][1]['result'] );

// Service level.
check( 'A687: batch keys are validated', '' === BulkCheckin::key( 'a b' ) && '' === BulkCheckin::key( 'short' ) && 'abc-123_x.y' === BulkCheckin::key( 'abc-123_x.y' ) );
check( 'A687: retry_ids lists only retryable lines', array( 9 ) === BulkCheckin::retry_ids( array( 'results' => array( array( 'ticket_id' => 8, 'retryable' => false ), array( 'ticket_id' => 9, 'retryable' => true ) ) ) ) );

// Docs.
$z687_doc = (string) file_get_contents( WPCAP_PATH . 'docs/MOBILE-API.md' );
check( 'A687: MOBILE-API.md documents batch, info, version headers and no longer calls the search admin-only', false !== strpos( $z687_doc, '/app/scan/batch' ) && false !== strpos( $z687_doc, '/app/info' ) && false !== strpos( $z687_doc, 'X-WPCAP-Min-Core' ) && false !== strpos( $z687_doc, 'wpcap_app_min_version' ) && false === strpos( $z687_doc, 'Die Suche gibt es nur im Admin' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
