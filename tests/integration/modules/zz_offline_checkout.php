<?php
/**
 * Offline scanner PWA: undo only with the undo right, check-out offline.
 *
 * - The signed package carries the rights of the device's user (permissions.undo = wpcap_undo_checkin,
 *   permissions.check_out = role rule checkout_roles) and the check-out rules (checkout: staff_area_ids,
 *   reentry_after_checkout, max_reentries) plus ticket_types[].own_policy; /app/me reports
 *   permissions.check_out next to undo.
 * - Offline check-outs are uploaded through the existing batch sync (direction "out") and decided with
 *   the online rules: inside, role, area binding; refused check-outs are no conflicts; a repeated upload
 *   replays; re-entry after a synced check-out follows reentry_after_checkout.
 * - The PWA uses the check-out endpoint, hides undo without the right and lists every new text.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Policies;
use PassCaptain\Capabilities;
use PassCaptain\Checkin;
use PassCaptain\Mobile\Api;
use PassCaptain\Mobile\Devices;
use PassCaptain\OfflineScanner\AdminPage;
use PassCaptain\OfflineScanner\I18n;
use PassCaptain\OfflineScanner\Rest;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'wpw-test-salt-' . $scheme; } }
global $wpdb;
$p = $wpdb->prefix;
$zoc_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$zoc_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$zoc_saved_interval = $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = 0;
// checkout_roles / reentry_after_checkout / max_reentries are Policy settings (registered by Plugin::boot()).
PassCaptain\Security\Policy::register();
Settings::flush();
$GLOBALS['wpcap_test_user_id'] = 7;
$GLOBALS['wpcap_test_denied_caps'] = array();

/** Package payload for the device (REST callback after device authentication). */
function zoc_package( $token, $occurrence_id ) {
	$req = app_request( 'GET', $token, array( 'occurrence_id' => $occurrence_id, 'format' => 2 ) );
	$auth = Api::authenticate( $req );
	if ( true !== $auth ) { return $auth; }
	$res = Rest::package( $req );
	$data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
	return is_array( $data ) ? json_decode( $data['payload'], true ) : $data;
}
/** Offline upload through /app/scan/sync incl. the module's after-callback (offline rules). */
function zoc_sync( $token, array $scans ) {
	$req = app_request( 'POST', $token, array( 'scans' => $scans ) );
	$auth = Api::authenticate( $req );
	if ( true !== $auth ) { return $auth; }
	$req->route = '/pass-captain/v1/app/scan/sync';
	return Rest::after_callbacks( Api::sync( $req ), null, $req )->get_data()['results'];
}
function zoc_out( $code, $key, $area = 0 ) {
	return array( 'code' => $code, 'idempotency_key' => $key, 'scanned_at' => gmdate( 'c', time() - 60 ), 'direction' => 'out', 'area_id' => $area );
}
function zoc_inside( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT s.inside FROM {$wpdb->prefix}wpcap_admission_state s INNER JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=s.ticket_id WHERE t.token_hash=%s ORDER BY s.occurrence_id DESC LIMIT 1", hash( 'sha256', $code ) ) );
}

echo "Offline scanner: rights and check-out rules in the package\n";
$zoc = adm_setup( 'UTC', null, null, 6 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $zoc['occ'] ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $zoc['event'], 'user_id' => 7, 'capability' => 'wpcap_checkin' ) );
$zoc_area_n = (int) Areas::save( $zoc['event'], 'Nord' );
$zoc_area_s = (int) Areas::save( $zoc['event'], 'Süd' );
$zoc_rules = AdminPage::save_rules( array( 'event_id' => $zoc['event'], 'enabled' => '1', 'max_offline_hours' => '6', 'duplicate_policy' => 'review' ) );
$zoc_pair = Devices::create_pairing( array( $zoc['event'] ), 'Auslass PWA', 12 );
$zoc_dev = Devices::pair( $zoc_pair['code'], 'Auslass PWA', 'pwa', '1.0' );
$GLOBALS['wpcap_test_user_id'] = 7;
$zoc_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_scanner_devices WHERE public_id=%s", $zoc_dev['device_id'] ) );
$zoc_assign = AdminPage::assign( array( 'event_id' => $zoc['event'], 'device_id' => (int) $zoc_row->device_id, 'occurrence_id' => $zoc['occ'] ) );
check( 'ZOC: setup – event opted in, device paired and assigned, 6 tickets', ! is_wp_error( $zoc_rules ) && ! is_wp_error( $zoc_assign ) && ! empty( $zoc_dev['token'] ) && 6 === count( $zoc['codes'] ) );

$pk = zoc_package( $zoc_dev['token'], $zoc['occ'] );
check( 'ZOC: package carries the rights of the device user (undo, check_out)', is_array( $pk ) && array( 'undo' => true, 'check_out' => true ) === ( $pk['permissions'] ?? null ) );
check( 'ZOC: package carries the check-out rules (defaults: no area binding, no re-entry)', array( 'staff_area_ids' => array(), 'reentry_after_checkout' => false, 'max_reentries' => 0 ) === ( $pk['checkout'] ?? null ) );
check( 'ZOC: ticket types say whether they have an own admission rule', false === ( $pk['ticket_types'][0]['own_policy'] ?? null ) );

$GLOBALS['wpcap_test_denied_caps'] = array( Capabilities::UNDO_CHECKIN );
$pk_no_undo = zoc_package( $zoc_dev['token'], $zoc['occ'] );
$me_no_undo = app_call( 'me', 'GET', $zoc_dev['token'] );
$GLOBALS['wpcap_test_user_id'] = 7;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'ZOC: without wpcap_undo_checkin the package and /app/me say undo = false (the PWA hides "Rückgängig")', false === ( $pk_no_undo['permissions']['undo'] ?? null ) && false === ( $me_no_undo['permissions']['undo'] ?? null ) && true === ( $me_no_undo['permissions']['check_out'] ?? null ) );

Settings::update( array( 'checkout_roles' => 'wpcap_box_office', 'reentry_after_checkout' => 1, 'max_reentries' => 2 ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] = array( 'wpcap_checkin_staff' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
Areas::add_staff( $zoc_area_n, 7 );
$pk_role = zoc_package( $zoc_dev['token'], $zoc['occ'] );
$me_role = app_call( 'me', 'GET', $zoc_dev['token'] );
$GLOBALS['wpcap_test_user_id'] = 7;
$allowed_role = Checkin::checkout_allowed();
check( 'ZOC: checkout_roles without a matching role – package and /app/me say check_out = false', false === ( $pk_role['permissions']['check_out'] ?? null ) && false === ( $me_role['permissions']['check_out'] ?? null ) && false === $allowed_role );
check( 'ZOC: the user\'s area binding and the re-entry settings are shipped', array( $zoc_area_n ) === ( $pk_role['checkout']['staff_area_ids'] ?? null ) && true === $pk_role['checkout']['reentry_after_checkout'] && 2 === $pk_role['checkout']['max_reentries'] );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] = array( 'wpcap_box_office' );
$pk_role_ok = zoc_package( $zoc_dev['token'], $zoc['occ'] );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: with a listed role check_out = true (same rule as Checkin::check_out())', true === ( $pk_role_ok['permissions']['check_out'] ?? null ) && Checkin::checkout_allowed() );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'ZOC: administrators are never bound to areas in the package', array() === zoc_package( $zoc_dev['token'], $zoc['occ'] )['checkout']['staff_area_ids'] );
$GLOBALS['wpcap_test_user_id'] = 7;
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] );
Policies::save( $zoc['type'], array( 'policy' => 'reentry', 'max_entries' => 0 ) );
$pk_policy = zoc_package( $zoc_dev['token'], $zoc['occ'] );
$GLOBALS['wpcap_test_user_id'] = 7;
$wpdb->delete( "{$p}wpcap_admission_policies", array( 'ticket_type_id' => $zoc['type'] ) );
check( 'ZOC: a type with own admission rule is flagged (re-entry setting does not apply to it)', true === ( $pk_policy['ticket_types'][0]['own_policy'] ?? null ) && 'reentry' === $pk_policy['ticket_types'][0]['policy'] );

echo "Offline scanner: offline check-outs through the batch sync\n";
$c = $zoc['codes'];
// Two guests were admitted on the device (online), one never entered.
$ctx = array( 'device_id' => (int) $zoc_row->device_id, 'event_ids' => array( $zoc['event'] ), 'source' => 'app' );
adm_in( $c[0], null, $ctx );
adm_in( $c[1], null, $ctx );
$res = zoc_sync( $zoc_dev['token'], array( zoc_out( $c[0], 'zoc-out-1' ), zoc_out( $c[2], 'zoc-out-2' ) ) );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: an offline check-out of a guest inside is booked (direction out, no conflict)', true === ( $res[0]['ok'] ?? null ) && 'checked_out' === $res[0]['result'] && 'out' === $res[0]['direction'] && false === $res[0]['conflict'] && 0 === zoc_inside( $c[0] ) );
check( 'ZOC: the server decides a queued check-out of a ticket that is not inside: refused, no conflict', false === ( $res[1]['ok'] ?? null ) && 'not_checked_in' === $res[1]['result'] && false === $res[1]['conflict'] && empty( $res[1]['conflict_id'] ) );
$again = zoc_sync( $zoc_dev['token'], array( zoc_out( $c[0], 'zoc-out-1' ) ) );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: uploading the same check-out again is answered from the first booking (idempotency key)', true === ( $again[0]['ok'] ?? null ) && ! empty( $again[0]['replayed'] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=(SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash=%s) AND result='checked_out'", hash( 'sha256', $c[0] ) ) ) );

// Re-entry after the synced check-out (setting reentry_after_checkout, max 2).
$re = zoc_sync( $zoc_dev['token'], array( array( 'code' => $c[0], 'idempotency_key' => 'zoc-re-1', 'scanned_at' => gmdate( 'c', time() - 30 ), 'direction' => 'in' ) ) );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: an offline re-entry after the check-out is admitted without conflict (reentry_after_checkout)', true === ( $re[0]['ok'] ?? null ) && 'checked_in' === $re[0]['result'] && false === $re[0]['conflict'] && 1 === zoc_inside( $c[0] ) );

// Role and area rules apply to uploaded check-outs as online.
Settings::update( array( 'checkout_roles' => 'wpcap_box_office' ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] = array( 'wpcap_checkin_staff' );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$role = zoc_sync( $zoc_dev['token'], array( zoc_out( $c[1], 'zoc-out-role', $zoc_area_n ) ) );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: an uploaded check-out of a role without the right is refused (forbidden), no conflict, still inside', false === ( $role[0]['ok'] ?? null ) && 'forbidden' === $role[0]['result'] && 'wpcap_checkout_forbidden' === ( $role[0]['code'] ?? '' ) && false === $role[0]['conflict'] && 1 === zoc_inside( $c[1] ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] = array( 'wpcap_box_office' );
$area = zoc_sync( $zoc_dev['token'], array( zoc_out( $c[1], 'zoc-out-area', $zoc_area_s ) ) );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: an uploaded check-out through an area the user is not assigned to is refused (wrong_area)', false === ( $area[0]['ok'] ?? null ) && 'wrong_area' === $area[0]['result'] && 1 === zoc_inside( $c[1] ) );
$ok = zoc_sync( $zoc_dev['token'], array( zoc_out( $c[1], 'zoc-out-area-ok', $zoc_area_n ) ) );
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'ZOC: through the user\'s own area the check-out is booked', true === ( $ok[0]['ok'] ?? null ) && 'checked_out' === $ok[0]['result'] && 0 === zoc_inside( $c[1] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] );
Settings::update( array( 'checkout_roles' => '', 'reentry_after_checkout' => 0, 'max_reentries' => 0 ) );
$wpdb->delete( "{$p}wpcap_admission_area_staff", array( 'area_id' => $zoc_area_n, 'user_id' => 7 ) );

echo "Offline scanner: PWA and documentation\n";
$app = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' );
$html = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/index.html' );
check( 'ZOC: the PWA checks out online via /app/scan/check-out and queues offline check-outs with direction "out"', false !== strpos( $app, "'scan/check-out'" ) && false !== strpos( $app, "direction: 'out'" ) && false !== strpos( $app, 'function decideCheckout(' ) );
check( 'ZOC: the PWA offers undo only with the right and reads the rights from /app/me', false !== strpos( $app, "if (!canUndo()) { state.undo = null; renderUndo(); return; }" ) && false !== strpos( $app, "api('GET', 'me')" ) && false !== strpos( $app, "'wpcap_undo_capability'" ) );
check( 'ZOC: the direction switch is in the app shell and hidden by default', false !== strpos( $html, 'id="mode-wrap"' ) && false !== strpos( $html, 'id="mode-out"' ) && 1 === preg_match( '/id="mode-wrap"[^>]*hidden/', $html ) );
preg_match_all( "/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/", $app, $m );
$missing = array_diff( array_unique( $m[1] ), array_keys( I18n::strings() ) );
check( 'ZOC: every t() text of the PWA is in the server translation table', array() === array_values( $missing ) );
$doc = (string) file_get_contents( WPCAP_PATH . 'docs/OFFLINE-SCANNER.md' );
$mob = (string) file_get_contents( WPCAP_PATH . 'docs/MOBILE-API.md' );
check( 'ZOC: offline check-out, the package fields and the undo right are documented', false !== strpos( $doc, 'Check-out (Auslass) offline' ) && false !== strpos( $doc, '"permissions"' ) && false !== strpos( $doc, 'staff_area_ids' ) && false !== strpos( $mob, 'permissions.check_out' ) );

$GLOBALS['wpcap_test_user_id'] = $zoc_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $zoc_saved_denied;
if ( null === $zoc_saved_interval ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = $zoc_saved_interval; }
