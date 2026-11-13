<?php
/** Mobile app API, shared check-in service and occurrence cancellation. Included from run.php. */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { public function __construct( public $data = null, public $status = 200 ) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); } }
if ( ! function_exists( 'wp_set_current_user' ) ) { function wp_set_current_user( $id ) { $GLOBALS['wpcap_test_user_id'] = (int) $id; } }
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'Staff ' . $id ); } }
if ( ! function_exists( 'wp_get_current_user' ) ) { function wp_get_current_user() { return get_userdata( get_current_user_id() ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }

function app_request( $method, $token, array $params = array() ) {
	$r = new WP_REST_Request( $method );
	if ( $token ) { $r->set_header( 'Authorization', 'Bearer ' . $token ); }
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}
function app_call( $callback, $method, $token, array $params = array() ) {
	$req = app_request( $method, $token, $params );
	$auth = Api::authenticate( $req );
	if ( true !== $auth ) { return $auth; }
	$res = call_user_func( array( Api::class, $callback ), $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
function ticket_code_for( $order_id ) {
	// Ticket codes are only stored hashed; tests read the last ticket mail for the order.
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( str_starts_with( $mail['subject'], 'Deine Tickets' ) && preg_match_all( '/Ticket-Code: ([a-f0-9]{64})/', $mail['body'], $m ) && $m[1] ) {
			global $wpdb;
			$hashes = array_map( static fn( $c ) => hash( 'sha256', $c ), $m[1] );
			$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=" . (int) $order_id . " AND token_hash IN ('" . implode( "','", $hashes ) . "')" );
			if ( $found ) { return $m[1]; }
		}
	}
	return array();
}

echo "Mobile app API\n";
$GLOBALS['wpcap_test_user_id'] = 7;
$mt = make_type( 10, 10, 0 );
$m_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$mt" );
$m_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$mt" );
$other = make_type( 10, 10, 0 );
$mo = Orders::create( array( 'email' => 'app@example.com', 'items' => array( array( 'ticket_type_id' => $mt, 'quantity' => 3 ) ), 'idempotency_key' => 'app-1' ) );
$codes = ticket_code_for( $mo['order_id'] );
$oo = Orders::create( array( 'email' => 'other@example.com', 'items' => array( array( 'ticket_type_id' => $other, 'quantity' => 1 ) ), 'idempotency_key' => 'app-2' ) );
$other_codes = ticket_code_for( $oo['order_id'] );
check( 'test tickets and codes available', 3 === count( $codes ) && 1 === count( $other_codes ) );

check( 'pairing needs at least one event', is_wp_error( Devices::create_pairing( array() ) ) );
$pairing = Devices::create_pairing( array( $m_event ), 'Einlass Nord', 12 );
$qr = json_decode( $pairing['qr_payload'], true );
check( 'pairing QR carries API base and one-time code', 'wpcap-pair' === $qr['type'] && str_ends_with( $qr['api'], 'pass-captain/v1/app/' ) && 20 === strlen( $qr['code'] ) );
$GLOBALS['wpcap_test_user_id'] = 0;
$paired = Devices::pair( $pairing['code'], 'iPhone Nord', 'ios', '1.0.0' );
$again = Devices::pair( $pairing['code'], 'iPhone 2', 'ios', '1.0.0' );
check( 'pairing returns a device token once', ! is_wp_error( $paired ) && str_starts_with( $paired['token'], 'wpcapd_' ) && is_wp_error( $again ) );
$token = $paired['token'];
check( 'token hash only is stored', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_scanner_devices WHERE secret_hash=%s", substr( $token, -64 ) ) ) );

$noauth = app_call( 'me', 'GET', '' );
$badauth = app_call( 'me', 'GET', substr( $token, 0, -1 ) . ( '0' === substr( $token, -1 ) ? '1' : '0' ) );
check( 'missing or wrong token is rejected with 401', is_wp_error( $noauth ) && 401 === $noauth->get_error_data()['status'] && is_wp_error( $badauth ) );
$me = app_call( 'me', 'GET', $token );
check( 'me returns device, events and runs as the pairing user', array( $m_event ) === $me['event_ids'] && 7 === get_current_user_id() && 'Einlass Nord' !== $me['device']['label'] );

$occs = app_call( 'occurrences', 'GET', $token );
check( 'occurrence list is limited to the device events', 1 === count( $occs['occurrences'] ) && $m_occ === $occs['occurrences'][0]['occurrence_id'] );

$preview = app_call( 'validate', 'POST', $token, array( 'code' => $codes[0] ) );
check( 'validate previews without checking in', true === $preview['ok'] && 'valid' === $preview['result'] && ! isset( $preview['attendee_email'] ) );
$missing_key = app_call( 'check_in', 'POST', $token, array( 'code' => $codes[0] ) );
check( 'check-in requires an idempotency key', is_wp_error( $missing_key ) );
$first = app_call( 'check_in', 'POST', $token, array( 'code' => $codes[0], 'idempotency_key' => 'k-1' ) );
$retry = app_call( 'check_in', 'POST', $token, array( 'code' => $codes[0], 'idempotency_key' => 'k-1' ) );
$second = app_call( 'check_in', 'POST', $token, array( 'code' => $codes[0], 'idempotency_key' => 'k-2' ) );
check( 'check-in succeeds; network retry with same key replays success', true === $first['ok'] && 'checked_in' === $first['result'] && true === $retry['ok'] && ! empty( $retry['replayed'] ) );
check( 'second scan of a used ticket is a 200 with ok=false/result=already_checked_in', false === $second['ok'] && 'already_checked_in' === $second['result'] );
check( 'exactly one successful check-in row', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_in' AND source='app' AND device_id>0" ) );
$foreign = app_call( 'check_in', 'POST', $token, array( 'code' => $other_codes[0], 'idempotency_key' => 'k-3' ) );
check( 'ticket of another event is refused for this device', false === $foreign['ok'] && 'wrong_event' === $foreign['result'] );
$wrong_occ = app_call( 'check_in', 'POST', $token, array( 'code' => $codes[1], 'idempotency_key' => 'k-4', 'occurrence_id' => $m_occ + 999 ) );
check( 'wrong selected occurrence is reported', 'wrong_occurrence' === $wrong_occ['result'] );
$unknown = app_call( 'check_in', 'POST', $token, array( 'code' => str_repeat( 'a', 64 ), 'idempotency_key' => 'k-5' ) );
check( 'unknown code is reported as unknown', 'unknown' === $unknown['result'] );

$stats = app_call( 'stats', 'GET', $token, array( 'occurrence_id' => $m_occ ) );
check( 'stats count checked-in and remaining tickets', 3 === $stats['tickets_total'] && 1 === $stats['checked_in'] && 2 === $stats['remaining'] && 1 === $stats['checked_in_by_this_device'] );

$undo = app_call( 'undo', 'POST', $token, array( 'code' => $codes[0], 'reason' => 'Fehlscan' ) );
check( 'undo makes the ticket valid again', true === $undo['ok'] && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $codes[0] ) . "'" ) );

$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE token_hash='" . hash( 'sha256', $codes[2] ) . "'" );
$sync = app_call( 'sync', 'POST', $token, array( 'scans' => array(
	array( 'code' => $codes[1], 'idempotency_key' => 'off-1', 'scanned_at' => gmdate( 'c', time() - 600 ) ),
	array( 'code' => $codes[2], 'idempotency_key' => 'off-2', 'scanned_at' => gmdate( 'c', time() - 500 ) ),
	array( 'code' => $codes[1], 'idempotency_key' => '' ),
) ) );
$sync_again = app_call( 'sync', 'POST', $token, array( 'scans' => array( array( 'code' => $codes[1], 'idempotency_key' => 'off-1' ) ) ) );
check( 'offline sync admits, flags double admission as conflict, rejects missing keys', true === $sync['results'][0]['ok'] && true === $sync['results'][1]['conflict'] && 'invalid' === $sync['results'][2]['result'] );
check( 're-uploading the same offline scan is idempotent', true === $sync_again['results'][0]['ok'] && false === $sync_again['results'][0]['conflict'] );
check( 'offline scan keeps the device time', null !== $wpdb->get_var( "SELECT scanned_at FROM {$p}wpcap_checkins WHERE source='offline' AND result='checked_in' LIMIT 1" ) );

app_call( 'logout', 'POST', $token );
$after = app_call( 'me', 'GET', $token );
check( 'logout revokes the device token', is_wp_error( $after ) );
$p2 = Devices::create_pairing( array( $m_event ), '', 1 );
$d2 = Devices::pair( $p2['code'] );
$wpdb->query( "UPDATE {$p}wpcap_scanner_devices SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 1 ) . "' WHERE public_id='" . $d2['device_id'] . "'" );
check( 'expired device token is rejected', is_wp_error( app_call( 'me', 'GET', $d2['token'] ) ) );
$p3 = Devices::create_pairing( array( $m_event ) );
$wpdb->query( "UPDATE {$p}wpcap_device_pairings SET expires_at='" . gmdate( 'Y-m-d H:i:s', time() - 1 ) . "' ORDER BY pairing_id DESC LIMIT 1" );
check( 'expired pairing code cannot be used', is_wp_error( Devices::pair( $p3['code'] ) ) );

echo "Web scanner uses the shared check-in service\n";
$wo = Orders::create( array( 'email' => 'web@example.com', 'items' => array( array( 'ticket_type_id' => $mt, 'quantity' => 1 ) ), 'idempotency_key' => 'web-1' ) );
$wcode = ticket_code_for( $wo['order_id'] )[0];
$GLOBALS['wpcap_test_user_id'] = 1;
$web1 = PassCaptain\Rest\Controller::check_in( app_request( 'POST', '', array( 'token' => $wcode ) ) );
$web2 = PassCaptain\Rest\Controller::check_in( app_request( 'POST', '', array( 'token' => $wcode ) ) );
check( 'web check-in works once, second scan is a 409', 'checked_in' === $web1->get_data()['status'] && is_wp_error( $web2 ) && 409 === $web2->get_error_data()['status'] );

echo "Cancelling a single occurrence\n";
PassCaptain\Settings::update( array( 'auto_refund_cancelled' => 1, 'refund_approval_threshold' => 0 ) );
$ct = make_type( 10, 10, 2000 );
$c_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ct" );
$paid_o = Orders::create( array( 'email' => 'paid-c@example.com', 'items' => array( array( 'ticket_type_id' => $ct, 'quantity' => 2 ) ), 'idempotency_key' => 'c-1' ) );
Orders::confirm_manual_payment( $paid_o['order_id'] );
$open_o = Orders::create( array( 'email' => 'open-c@example.com', 'items' => array( array( 'ticket_type_id' => $ct, 'quantity' => 1 ) ), 'idempotency_key' => 'c-2' ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$c_occ" );
$summary = PassCaptain\OccurrenceCancellation::handle( $c_occ );
check( 'open orders are cancelled and holds released', 'cancelled' === Orders::get( $open_o['order_id'] )->status && 1 === $summary['cancelled_orders'] );
check( 'paid native orders are refunded automatically', 1 === $summary['refunds'] && 'refunded' === Orders::get( $paid_o['order_id'] )->status );
check( 'no valid tickets remain for the cancelled date', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets t JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=$c_occ AND t.status='valid'" ) );
$to = array_map( static fn( $m ) => $m['to'], array_slice( $GLOBALS['wpcap_test_mails'], $before ) );
check( 'ticket holders are informed', in_array( 'paid-c@example.com', $to, true ) && 1 === $summary['notified'] );
PassCaptain\Settings::update( array( 'auto_refund_cancelled' => 0 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
