<?php
/**
 * A543: replaced ticket codes say "Code ersetzt" with reason and time instead of "Ticket nicht
 * gefunden"; revoked tickets name the reason category (never the staff free text).
 * Included from run.php after admission.php, mobile.php and offline_scanner.php
 * (uses check(), adm_setup(), adm_in(), adm_r(), app_call(), off_pair()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Rest as AdmissionRest;
use PassCaptain\Checkin;
use PassCaptain\CodeHistory;
use PassCaptain\Mobile\Devices;
use PassCaptain\OfflineScanner\Package;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
if ( ! in_array( array( CodeHistory::class, 'on_revoked' ), array_column( $GLOBALS['wpcap_test_hooks']['wpcap_ticket_revoked'][5] ?? array(), 0 ), true ) ) { CodeHistory::register(); }

function a543_tid( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a543_current_code( $ticket_id ) {
	// The newest mail with a ticket link for this ticket's current hash.
	global $wpdb;
	$hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket_id ) );
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( preg_match_all( '/([a-f0-9]{64})/', $mail['body'], $m ) ) {
			foreach ( $m[1] as $c ) { if ( hash( 'sha256', $c ) === $hash ) { return $c; } }
		}
	}
	return '';
}

echo "A543: replaced codes and revocation reasons at the scanner\n";
check( 'A543: schema 3.10.18 table wpcap_code_history exists', CodeHistory::ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.18', '>=' ) );

// Transfer: the old code says "Code ersetzt – übertragen" with the time; the new code admits.
$s = adm_setup( 'UTC', null, null, 3 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
$t0 = a543_tid( $s['codes'][0] );
$order_id = (int) $wpdb->get_var( "SELECT order_id FROM {$p}wpcap_tickets WHERE ticket_id=$t0" );
$access = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $access ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'order_id' => $order_id ) );
$public = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=$t0" );
$transfer = Orders::transfer_ticket( Orders::get( $order_id )->public_id, $access, $public, 'a543-neu@example.com' );
$old = Checkin::perform( $s['codes'][0], array( 'idempotency_key' => 'a543-old-' . uniqid() ) );
$data = is_wp_error( $old ) ? (array) $old->get_error_data() : array();
check( 'A543: transfer succeeded', ! is_wp_error( $transfer ) && 0 === a543_tid( $s['codes'][0] ) );
check( 'A543: the old code of a transferred ticket is refused as "replaced", not "unknown"', 'wpcap_ticket_replaced' === ( is_wp_error( $old ) ? $old->get_error_code() : '' ) && 'replaced' === ( $data['result'] ?? '' ) && 'transfer' === ( $data['replaced_reason'] ?? '' ) && ! empty( $data['replaced_at'] ) );
check( 'A543: the message names the transfer and asks for the current code; no internal ids leave the server', false !== strpos( $old->get_error_message(), 'übertragen' ) && false !== strpos( $old->get_error_message(), 'Code ersetzt' ) && ! isset( $data['ticket_id'] ) && ! isset( $data['event_id'] ) );
$preview = Checkin::preview( $s['codes'][0] );
check( 'A543: the read-only preview gives the same result', 'replaced' === adm_r( $preview ) );
$new_code = a543_current_code( $t0 );
check( 'A543: the new code of the transferred ticket is valid', '' !== $new_code && 'valid' === adm_r( Checkin::preview( $new_code ) ) );
check( 'A543: a random code is still "unknown"', 'unknown' === adm_r( Checkin::preview( str_repeat( 'ab', 32 ) ) ) );

// Reissue by staff.
$t1 = a543_tid( $s['codes'][1] );
Orders::reissue_ticket( $t1, array( 'notify' => false ) );
$re = Checkin::preview( $s['codes'][1] );
check( 'A543: the old code of a reissued ticket says "replaced" (reissue)', 'replaced' === adm_r( $re ) && 'reissue' === ( $re->get_error_data()['replaced_reason'] ?? '' ) && false !== strpos( $re->get_error_message(), 'neu ausgestellt' ) );

// Scope: staff without check-in access to the event learn nothing about the old code.
$GLOBALS['wpcap_test_user_id'] = 9;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$foreign = Checkin::preview( $s['codes'][1] );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A543: without access to the event an old code is just "unknown"', 'unknown' === adm_r( $foreign ) );

// Web scanner (admission routes) and app API pass the result on.
$client = AdmissionRest::client_error( Checkin::perform( $s['codes'][0], array( 'idempotency_key' => 'a543-web-' . uniqid() ) ) );
check( 'A543: web scanner error keeps result and reason of the replacement', 'replaced' === ( $client->get_error_data()['result'] ?? '' ) && 'transfer' === ( $client->get_error_data()['replaced_reason'] ?? '' ) );
$GLOBALS['wpcap_test_user_id'] = 7;
$a543_pair = Devices::create_pairing( array( $s['event'] ), 'A543 Gerät', 2 );
$GLOBALS['wpcap_test_user_id'] = 0;
$a543_dev = Devices::pair( $a543_pair['code'], 'A543', 'android', '1.1' );
$GLOBALS['wpcap_test_user_id'] = 1;
$app = app_call( 'check_in', 'POST', $a543_dev['token'], array( 'code' => $s['codes'][0], 'idempotency_key' => 'a543-app-' . uniqid() ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A543: app check-in answers result "replaced" with message and reason', false === ( $app['ok'] ?? null ) && 'replaced' === ( $app['result'] ?? '' ) && 'transfer' === ( $app['replaced_reason'] ?? '' ) && false !== strpos( (string) ( $app['message'] ?? '' ), 'Code ersetzt' ) );

// A rolled back rotation leaves no history.
$h2 = (string) $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=" . a543_tid( $s['codes'][2] ) );
$wpdb->query( 'START TRANSACTION' );
CodeHistory::replacing( a543_tid( $s['codes'][2] ), 'resend' );
$wpdb->query( 'ROLLBACK' );
check( 'A543: history rows are part of the rotation transaction', null === CodeHistory::find_replaced( $h2 ) );

echo "A543: revocation reasons\n";
$s = adm_setup( 'UTC', null, null, 3 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
$rv = TicketsPage::revoke_ticket( array( 'ticket_id' => a543_tid( $s['codes'][0] ), 'reason' => 'Betrug Max Mustermann' ) );
$rev = Checkin::perform( $s['codes'][0], array( 'idempotency_key' => 'a543-rev-' . uniqid() ) );
check( 'A543: a ticket revoked by staff says "vom Veranstalter widerrufen", never the free text', ! is_wp_error( $rv ) && 'revoked' === adm_r( $rev ) && false !== strpos( $rev->get_error_message(), 'vom Veranstalter widerrufen' ) && false === strpos( $rev->get_error_message(), 'Mustermann' ) && 'admin' === ( $rev->get_error_data()['revoked_reason'] ?? '' ) );
$tx = a543_tid( $s['codes'][1] );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => $tx ) );
Orders::fire_revoked( array( $tx ), 'exchange' );
$ex = Checkin::perform( $s['codes'][1], array( 'idempotency_key' => 'a543-ex-' . uniqid() ) );
check( 'A543: an exchanged ticket says it was exchanged (new ticket exists)', 'revoked' === adm_r( $ex ) && false !== strpos( $ex->get_error_message(), 'umgetauscht' ) && 'exchanged' === ( $ex->get_error_data()['revoked_reason'] ?? '' ) );
check( 'A543: reason categories of revocations', 'refunded' === CodeHistory::revoke_category( 'refund_12' ) && 'cancelled' === CodeHistory::revoke_category( 'occurrence_cancelled' ) && 'order_cancelled' === CodeHistory::revoke_category( 'order_cancelled' ) && 'admin' === CodeHistory::revoke_category( 'irgendwas' ) );

echo "A543: offline package marks replaced hashes\n";
if ( class_exists( Package::class ) && function_exists( 'off_pair' ) ) {
	PassCaptain\OfflineScanner\Rules::save( $s['event'], array( 'enabled' => 1, 'max_offline_hours' => 12, 'duplicate_policy' => 'review', 'attendee_names' => 0 ) );
	$pk_dev = off_pair( array( $s['event'] ), 'A543 Offline' );
	$occ_row = $wpdb->get_row( "SELECT o.*,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_occurrences o JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=" . (int) $s['occ'] );
	$full = Package::build( $pk_dev['row'], $occ_row );
	$t2 = a543_tid( $s['codes'][2] );
	$old_hash = hash( 'sha256', $s['codes'][2] );
	Orders::reissue_ticket( $t2, array( 'notify' => false ) );
	$GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = 0;
	$delta = json_decode( Package::build( $pk_dev['row'], $occ_row, $full['cursor'] )['payload'], true );
	$entry = array_values( array_filter( $delta['tickets'], static fn( $t ) => $t['h'] === $old_hash ) );
	check( 'A543: a delta marks the old hash of a reissued ticket as revoked with r=replaced', 1 === count( $entry ) && 'revoked' === $entry[0]['s'] && 'replaced' === ( $entry[0]['r'] ?? '' ) );
} else {
	check( 'A543: offline scanner module available for the package check', false );
}
