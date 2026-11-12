<?php
/**
 * OfflineScanner module: opt-in, device assignment, signed packages (Ed25519), deltas incl.
 * revocations, expiry, key rotation with grace, sync conflict rules.
 * Included from run.php after mobile.php (uses check(), make_type(), ticket_code_for(), app_call()).
 */
// phpcs:ignoreFile
use PassCaptain\Mobile\Devices;
use PassCaptain\Mobile\Api;
use PassCaptain\Admission\Conflicts;
use PassCaptain\OfflineScanner\AdminPage;
use PassCaptain\OfflineScanner\Assignments;
use PassCaptain\OfflineScanner\Keys;
use PassCaptain\OfflineScanner\Package;
use PassCaptain\OfflineScanner\Rest;
use PassCaptain\OfflineScanner\Rules;
use PassCaptain\Orders;

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'wpw-test-salt-' . $scheme; } }
PassCaptain\Modules\OfflineScanner::register();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = 0;
$GLOBALS['wpcap_test_user_id'] = 7;
$GLOBALS['wpcap_test_denied_caps'] = array();

/** Calls an offline endpoint like the REST server (device auth first). */
function off_call( $callback, $token, array $params = array() ) {
	$req = app_request( 'GET', $token, $params );
	$auth = Api::authenticate( $req );
	if ( true !== $auth ) { return $auth; }
	$res = call_user_func( array( Rest::class, $callback ), $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
/** Uploads offline scans through /app/scan/sync incl. the module's after-callback. */
function off_sync( $token, array $scans ) {
	$req = app_request( 'POST', $token, array( 'scans' => $scans ) );
	$auth = Api::authenticate( $req );
	if ( true !== $auth ) { return $auth; }
	$req->route = '/wp-pass-captain/v1/app/scan/sync';
	$res = Rest::after_callbacks( Api::sync( $req ), null, $req );
	return $res->get_data();
}
function off_pair( $event_ids, $label ) {
	$GLOBALS['wpcap_test_user_id'] = 7;
	$pairing = Devices::create_pairing( $event_ids, $label, 12 );
	$paired = Devices::pair( $pairing['code'], $label, 'pwa', '1.0' );
	$GLOBALS['wpcap_test_user_id'] = 7;
	$paired['row'] = $GLOBALS['wpdb']->get_row( "SELECT * FROM {$GLOBALS['wpdb']->prefix}wpcap_scanner_devices WHERE public_id='" . $paired['device_id'] . "'" );
	return $paired;
}
function off_err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
function off_payload( $r ) { return is_array( $r ) ? json_decode( $r['payload'], true ) : null; }
function off_hashes( $payload, $status = null ) {
	$out = array();
	foreach ( $payload['tickets'] as $t ) { if ( null === $status || $t['s'] === $status ) { $out[] = $t['h']; } }
	sort( $out );
	return $out;
}

$ot = make_type( 20, 20, 0 );
$o_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ot" );
$o_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$o_occ" );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $o_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 8 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$o_occ2 = (int) $wpdb->insert_id;
$oo = Orders::create( array( 'email' => 'offline@example.com', 'items' => array( array( 'ticket_type_id' => $ot, 'quantity' => 5 ) ), 'idempotency_key' => 'offline-1' ) );
$oc = ticket_code_for( $oo['order_id'] );
$other_type = make_type( 5, 5, 0 );
$other_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$other_type" );
$dev_a = off_pair( array( $o_event ), 'Offline A' );
$dev_b = off_pair( array( $o_event ), 'Offline B' );
$dev_x = off_pair( array( $other_event ), 'Fremd' );

echo "Offline scanner: opt-in and rules\n";
check( 'test setup: 5 tickets, three paired devices', 5 === count( $oc ) && ! empty( $dev_a['token'] ) && ! empty( $dev_b['token'] ) && ! empty( $dev_x['token'] ) );
check( 'events are not offline-capable by default', false === Rules::get( $o_event )['enabled'] && 'review' === Rules::get( $o_event )['duplicate_policy'] );
$r = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
check( 'package without opt-in is refused (403 wpcap_offline_disabled)', 'wpcap_offline_disabled' === off_err( $r ) && 403 === $r->get_error_data()['status'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'saving offline rules requires wpcap_manage_events', 'wpcap_admin_forbidden' === off_err( $denied ) && ! Rules::enabled( $o_event ) );
$bad = array(
	AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '0', 'duplicate_policy' => 'review' ) ),
	AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '500', 'duplicate_policy' => 'review' ) ),
	AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '5', 'duplicate_policy' => 'last_wins' ) ),
);
check( 'invalid offline duration and unknown duplicate policy are rejected', 3 === count( array_filter( $bad, 'is_wp_error' ) ) && ! Rules::enabled( $o_event ) );
$saved = AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '6', 'duplicate_policy' => 'review', 'include_attendee' => '1' ) );
check( 'opt-in saved; attendee names flagged as ineffective while scanner_show_attendee is off', ! is_wp_error( $saved ) && true === $saved['rules']['enabled'] && 6 === $saved['rules']['max_offline_hours'] && ! empty( $saved['warning'] ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='offline_rules_saved' AND object_id=$o_event" ) );

echo "Offline scanner: device assignment\n";
$r = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
check( 'opted-in event but unassigned device is refused (403 wpcap_offline_not_assigned)', 'wpcap_offline_not_assigned' === off_err( $r ) );
check( 'device cannot be assigned to an event it is not paired for', 'wpcap_offline_device_event' === off_err( AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_x['row']->device_id ) ) ) );
check( 'occurrence of another event is rejected', 'wpcap_offline_occurrence' === off_err( AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_a['row']->device_id, 'occurrence_id' => $o_occ + 100000 ) ) ) );
AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_a['row']->device_id, 'occurrence_id' => $o_occ2 ) );
$r = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
check( 'assignment to another occurrence does not cover this one', 'wpcap_offline_not_assigned' === off_err( $r ) );
$as = AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_a['row']->device_id, 'occurrence_id' => $o_occ ) );
check( 'device assigned to the occurrence', ! is_wp_error( $as ) && $o_occ === $as['occurrence_id'] );
$list = off_call( 'occurrences', $dev_a['token'] );
check( 'occurrence list shows assigned occurrences with rules', 2 === count( $list['occurrences'] ) && 6 === $list['occurrences'][0]['rules']['max_offline_hours'] && $dev_a['device_id'] === $list['device_id'] );
check( 'unassigned paired device sees no offline occurrences', array() === off_call( 'occurrences', $dev_b['token'] )['occurrences'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
$r = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'device user without check-in permission gets no package', is_wp_error( $r ) );

echo "Offline scanner: signed full package\n";
$keys = off_call( 'keys', $dev_a['token'] )['keys'];
$full = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
$fp = off_payload( $full );
$pub = base64_decode( $keys[0]['public_key'] );
check( 'one active Ed25519 public key is published', 1 === count( $keys ) && 'active' === $keys[0]['status'] && 32 === strlen( $pub ) && $keys[0]['key_id'] === $full['key_id'] );
check( 'package signature verifies with sodium_crypto_sign_verify_detached', 'Ed25519' === $full['alg'] && sodium_crypto_sign_verify_detached( base64_decode( $full['signature'] ), $full['payload'], $pub ) );
$tampered = str_replace( '"s":"valid"', '"s":"checked_in"', $full['payload'] );
check( 'tampered payload fails verification', $tampered !== $full['payload'] && ! sodium_crypto_sign_verify_detached( base64_decode( $full['signature'] ), $tampered, $pub ) && null === Package::verify( array_merge( $full, array( 'payload' => $tampered ) ) ) );
$expected = array_map( static fn( $c ) => hash( 'sha256', $c ), $oc );
sort( $expected );
check( 'full package holds the sha256 hashes of all valid tickets, bound to device and occurrence', 'full' === $fp['kind'] && $expected === off_hashes( $fp, 'valid' ) && $dev_a['device_id'] === $fp['device_id'] && $o_occ === $fp['occurrence']['id'] && 1 === $fp['v'] && 'wpcap-offline-package' === $fp['format'] );
check( 'tickets carry type and areas via ticket_types; no codes, e-mails or names', $ot === $fp['tickets'][0]['t'] && ! isset( $fp['tickets'][0]['n'] ) && $ot === $fp['ticket_types'][0]['id'] && array() === $fp['ticket_types'][0]['areas'] && false === strpos( $full['payload'], $oc[0] ) && false === strpos( $full['payload'], 'offline@example.com' ) );
check( 'package expires after the maximum offline duration', 6 * 3600 === strtotime( $fp['expires_at'] ) - strtotime( $fp['issued_at'] ) && strtotime( $fp['expires_at'] ) > time() );
check( 'secret key never appears in responses; it is stored encrypted', false === strpos( wp_json_encode( array( $keys, $full ) ), 'secret' ) && 0 === strpos( get_option( Keys::OPTION )['keys'][ $full['key_id'] ]['secret'], 'v1:' ) );
check( 'last package fetch is recorded on the assignment', null !== $wpdb->get_var( "SELECT last_package_at FROM {$p}wpcap_offline_assignments WHERE device_id=" . (int) $dev_a['row']->device_id . " AND occurrence_id=$o_occ" ) );

echo "Offline scanner: attendee names only with both settings\n";
$tid0 = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $oc[0] ) . "'" );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $tid0, 'buyer_email' => 'offline@example.com', 'attendee_email' => 'anna@example.com', 'attendee_name' => 'Anna Muster', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$no_names = off_payload( off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) ) );
PassCaptain\Settings::update( array( 'scanner_show_attendee' => 1 ) );
$with_names = off_payload( off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) ) );
$named = array_values( array_filter( $with_names['tickets'], static fn( $t ) => isset( $t['n'] ) ) );
check( 'names are not shipped while scanner_show_attendee is off', false === $no_names['rules']['attendee_names'] && false === strpos( wp_json_encode( $no_names ), 'Anna' ) );
check( 'names shipped when the event allows it and scanner_show_attendee is on (no e-mail)', true === $with_names['rules']['attendee_names'] && 1 === count( $named ) && 'Anna Muster' === $named[0]['n'] && false === strpos( wp_json_encode( $with_names ), 'anna@example.com' ) );
AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '6', 'duplicate_policy' => 'review' ) );
PassCaptain\Settings::update( array( 'scanner_show_attendee' => 0 ) );

echo "Offline scanner: delta updates incl. revocations\n";
$base = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
$cursor = $base['cursor'];
$none = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ, 'since' => $cursor ) );
check( 'delta without changes is empty and keeps the cursor', 'delta' === $none['kind'] && array() === off_payload( $none )['tickets'] && $cursor === $none['cursor'] && $cursor === off_payload( $none )['since'] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE token_hash='" . hash( 'sha256', $oc[1] ) . "'" );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE token_hash='" . hash( 'sha256', $oc[2] ) . "'" );
$delta = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ, 'since' => $cursor ) );
$dp = off_payload( $delta );
check( 'delta is signed and verifies', null !== Package::verify( $delta ) && 'delta' === $dp['kind'] && $cursor !== $delta['cursor'] );
check( 'delta contains the revoked hash and the status change only', array( hash( 'sha256', $oc[1] ) ) === off_hashes( $dp, 'revoked' ) && array( hash( 'sha256', $oc[2] ) ) === off_hashes( $dp, 'checked_in' ) && 2 === count( $dp['tickets'] ) );
$full2 = off_payload( off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) ) );
check( 'full package omits revoked tickets', ! in_array( hash( 'sha256', $oc[1] ), off_hashes( $full2 ), true ) && 4 === count( $full2['tickets'] ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET token_hash='" . str_repeat( 'b', 64 ) . "' WHERE token_hash='" . hash( 'sha256', $oc[3] ) . "'" );
$d2 = off_payload( off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ, 'since' => $delta['cursor'] ) ) );
check( 'reissued code: old hash revoked, new hash valid in the next delta', in_array( hash( 'sha256', $oc[3] ), off_hashes( $d2, 'revoked' ), true ) && in_array( str_repeat( 'b', 64 ), off_hashes( $d2, 'valid' ), true ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET token_hash='" . hash( 'sha256', $oc[3] ) . "' WHERE token_hash='" . str_repeat( 'b', 64 ) . "'" );
$foreign = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ, 'since' => '0123456789abcdef.1' ) );
$future = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ, 'since' => explode( '.', $cursor )[0] . '.99999' ) );
check( 'unknown epoch or future cursor falls back to a full package', 'full' === $foreign['kind'] && 'full' === $future['kind'] );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$o_occ" );
$cur = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) )['cursor'];
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled' WHERE occurrence_id=$o_occ" );
$back = off_payload( off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ, 'since' => $cur ) ) );
check( 'cancelled occurrence revokes all hashes; un-cancelling re-validates them via delta', 4 === count( off_hashes( $back ) ) && 3 === count( off_hashes( $back, 'valid' ) ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='valid' WHERE token_hash IN ('" . hash( 'sha256', $oc[1] ) . "','" . hash( 'sha256', $oc[2] ) . "')" );

echo "Offline scanner: key rotation with grace period\n";
$old_pkg = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
$old_kid = $old_pkg['key_id'];
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'key rotation requires manage_options', 'wpcap_admin_forbidden' === off_err( AdminPage::rotate_key() ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$rot = AdminPage::rotate_key();
$keys2 = off_call( 'keys', $dev_a['token'] )['keys'];
$by_id = array_column( $keys2, null, 'key_id' );
check( 'rotation activates a new key and keeps the old public key until its packages expire', $rot['key_id'] !== $old_kid && 'active' === $by_id[ $rot['key_id'] ]['status'] && 'retired' === $by_id[ $old_kid ]['status'] && strtotime( $by_id[ $old_kid ]['valid_until'] ) >= strtotime( off_payload( $old_pkg )['expires_at'] ) );
check( 'old secret is wiped on rotation', '' === get_option( Keys::OPTION )['keys'][ $old_kid ]['secret'] );
$new_pkg = off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) );
check( 'old packages still verify during grace; new packages use the new key', null !== Package::verify( $old_pkg ) && $rot['key_id'] === $new_pkg['key_id'] && null !== Package::verify( $new_pkg ) && ! sodium_crypto_sign_verify_detached( base64_decode( $new_pkg['signature'] ), $new_pkg['payload'], base64_decode( $by_id[ $old_kid ]['public_key'] ) ) );
$opt = get_option( Keys::OPTION );
$opt['keys'][ $old_kid ]['valid_until'] = time() - 1;
update_option( Keys::OPTION, $opt );
check( 'after the grace period the old key is no longer published and its packages fail', ! in_array( $old_kid, array_column( Keys::public_keys(), 'key_id' ), true ) && null === Package::verify( $old_pkg ) );
$pair_req = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/app/pair' );
$pair_res = Rest::after_callbacks( new WP_REST_Response( array( 'token' => 'x', 'device_id' => 'y' ), 201 ), null, $pair_req );
check( 'pairing response delivers the trusted public keys', $rot['key_id'] === $pair_res->get_data()['offline']['keys'][0]['key_id'] && 1 === $pair_res->get_data()['offline']['package_format'] );

echo "Offline scanner: revoked devices and unassignment\n";
AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_b['row']->device_id ) );
check( 'event-wide assignment covers every occurrence', 'full' === off_call( 'package', $dev_b['token'], array( 'occurrence_id' => $o_occ2 ) )['kind'] );
AdminPage::unassign( array( 'event_id' => $o_event, 'device_id' => $dev_a['row']->device_id, 'occurrence_id' => $o_occ ) );
check( 'unassigned device loses access', 'wpcap_offline_not_assigned' === off_err( off_call( 'package', $dev_a['token'], array( 'occurrence_id' => $o_occ ) ) ) );
AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_a['row']->device_id, 'occurrence_id' => $o_occ ) );
$dev_c = off_pair( array( $o_event ), 'Offline C' );
AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_c['row']->device_id ) );
Devices::revoke( (int) $dev_c['row']->device_id );
check( 'revoked device gets 401 and cannot be assigned again', 401 === off_call( 'package', $dev_c['token'], array( 'occurrence_id' => $o_occ ) )->get_error_data()['status'] && 'wpcap_offline_device' === off_err( AdminPage::assign( array( 'event_id' => $o_event, 'device_id' => $dev_c['row']->device_id ) ) ) );
AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '0', 'max_offline_hours' => '6', 'duplicate_policy' => 'review' ) );
check( 'disabling the opt-in stops packages for assigned devices', 'wpcap_offline_disabled' === off_err( off_call( 'package', $dev_b['token'], array( 'occurrence_id' => $o_occ ) ) ) && array() === off_call( 'occurrences', $dev_b['token'] )['occurrences'] );

echo "Offline scanner: sync conflicts across devices\n";
$sync_plain = off_sync( $dev_a['token'], array( array( 'code' => $oc[4], 'idempotency_key' => 'pl-a', 'scanned_at' => gmdate( 'c', time() - 120 ) ) ) );
check( 'without opt-in the sync result is unchanged', true === $sync_plain['results'][0]['ok'] && ! isset( $sync_plain['results'][0]['offline_rules'] ) );
AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '6', 'duplicate_policy' => 'review' ) );
$a1 = off_sync( $dev_a['token'], array( array( 'code' => $oc[1], 'idempotency_key' => 'rv-a', 'scanned_at' => gmdate( 'c', time() - 300 ) ) ) );
$b1 = off_sync( $dev_b['token'], array( array( 'code' => $oc[1], 'idempotency_key' => 'rv-b', 'scanned_at' => gmdate( 'c', time() - 200 ) ) ) );
$rb = $b1['results'][0];
check( 'review: first upload admits, the second device\'s admission becomes an open conflict', true === $a1['results'][0]['ok'] && 'review' === $a1['results'][0]['offline_rules'] && false === $rb['ok'] && true === $rb['double_admission'] && 'open' === $rb['conflict_status'] && false === $rb['auto_resolved'] && 'open' === Conflicts::get( $rb['conflict_id'] )->status );
AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '6', 'duplicate_policy' => 'first_sync_wins' ) );
$a2 = off_sync( $dev_a['token'], array( array( 'code' => $oc[2], 'idempotency_key' => 'fs-a', 'scanned_at' => gmdate( 'c', time() - 300 ) ) ) );
$b2 = off_sync( $dev_b['token'], array( array( 'code' => $oc[2], 'idempotency_key' => 'fs-b', 'scanned_at' => gmdate( 'c', time() - 400 ) ) ) );
$rb2 = $b2['results'][0];
$conf = Conflicts::get( $rb2['conflict_id'] );
check( 'first-sync-wins: later duplicate is recorded and resolved automatically as duplicate scan', true === $a2['results'][0]['ok'] && true === $rb2['conflict'] && true === $rb2['auto_resolved'] && 'resolved' === $conf->status && 'duplicate_scan' === $conf->resolution && 0 === (int) $conf->resolved_by && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='admission_conflict_auto_resolved' AND object_id=" . (int) $conf->conflict_id ) );
$b2r = off_sync( $dev_b['token'], array( array( 'code' => $oc[2], 'idempotency_key' => 'fs-b', 'scanned_at' => gmdate( 'c', time() - 400 ) ) ) );
check( 're-upload is idempotent (same conflict, still resolved)', $rb2['conflict_id'] === $b2r['results'][0]['conflict_id'] && true === $b2r['results'][0]['auto_resolved'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_in' AND ticket_id=(SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $oc[2] ) . "')" ) );
AdminPage::save_rules( array( 'event_id' => $o_event, 'enabled' => '1', 'max_offline_hours' => '1', 'duplicate_policy' => 'first_sync_wins' ) );
$old = off_sync( $dev_a['token'], array( array( 'code' => $oc[3], 'idempotency_key' => 'exp-a', 'scanned_at' => gmdate( 'c', time() - 2 * 3600 ) ) ) );
$ro = $old['results'][0];
$oconf = $ro['conflict_id'] ?? 0 ? Conflicts::get( $ro['conflict_id'] ) : null;
check( 'scan older than the maximum offline duration is admitted but flagged for review', true === $ro['ok'] && true === $ro['offline_expired'] && true === $ro['conflict'] && $oconf && 'offline_expired' === $oconf->result && 'open' === $oconf->status && false === $ro['auto_resolved'] );
$old2 = off_sync( $dev_a['token'], array( array( 'code' => $oc[3], 'idempotency_key' => 'exp-a', 'scanned_at' => gmdate( 'c', time() - 2 * 3600 ) ) ) );
check( 'expired-scan conflict is recorded once per scan', $ro['conflict_id'] === $old2['results'][0]['conflict_id'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_admission_conflicts WHERE result='offline_expired'" ) );
check( 'conflict page labels the new result code', 'Offline-Dauer überschritten (Offline-Scanner)' === PassCaptain\Admission\ConflictsPage::result_label( 'offline_expired' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] );
$GLOBALS['wpcap_test_user_id'] = 1;
