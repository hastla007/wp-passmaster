<?php
/**
 * Scanner: attendee name and first-admission details (time, entrance, staff) on "already used";
 * undo and pass redemption as capabilities of their own (wpcap_undo_checkin, wpcap_redeem_passes),
 * granted once to every role that held wpcap_checkin.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Areas;
use PassCaptain\Capabilities;
use PassCaptain\Checkin;
use PassCaptain\Installer;
use PassCaptain\Mobile\Devices;
use PassCaptain\ScannerDesk;
use PassCaptain\Settings;

if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
global $wpdb;
$p = $wpdb->prefix;
$suc_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$suc_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$suc_saved_show = (int) Settings::get( 'scanner_show_attendee' );

function suc_tid( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function suc_status( $code ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function suc_setup( $qty ) {
	global $wpdb;
	$s = adm_setup( 'UTC', null, null, $qty );
	$wpdb->update( "{$wpdb->prefix}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
	return $s;
}

echo "Scanner: attendee name and first admission on 'already used'\n";
$s = suc_setup( 3 );
$area = (int) Areas::save( $s['event'], 'Südeingang' );
$tid = suc_tid( $s['codes'][0] );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $tid, 'buyer_email' => 'suc@example.com', 'attendee_name' => 'Alex Muster', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
Settings::update( array( 'scanner_show_attendee' => 0 ) );
$first = Checkin::perform( $s['codes'][0], array( 'idempotency_key' => 'suc-1', 'area_id' => $area ) );
check( 'SUC: fixture admission succeeds', ! is_wp_error( $first ) && 'checked_in' === ( $first['status'] ?? '' ) );
check( 'SUC: default (setting off) keeps the name out of an admission, as before', ! is_wp_error( $first ) && ! isset( $first['attendee_name'] ) );
$again = Checkin::perform( $s['codes'][0], array( 'idempotency_key' => 'suc-2', 'area_id' => $area ) );
$again_data = is_wp_error( $again ) ? (array) Checkin::client_error( $again )->get_error_data() : array();
check( 'SUC: double scan names the entrance of the first admission', 'already_checked_in' === ( $again_data['result'] ?? '' ) && 'Südeingang' === ( $again_data['first_checked_in_area'] ?? '' ) );
check( 'SUC: double scan names time and staff of the first admission', ! empty( $again_data['first_checked_in_at'] ) && 'Staff 1' === ( $again_data['first_checked_in_staff'] ?? '' ) && '' !== (string) ( $again_data['first_checked_in_by'] ?? '' ) );
check( 'SUC: setting off – no attendee name in the refusal', ! isset( $again_data['attendee_name'] ) );
Settings::update( array( 'scanner_show_attendee' => 1 ) );
$again2 = Checkin::perform( $s['codes'][0], array( 'idempotency_key' => 'suc-3', 'area_id' => $area ) );
$again2_data = is_wp_error( $again2 ) ? (array) Checkin::client_error( $again2 )->get_error_data() : array();
check( 'SUC: setting on – the refusal reaches the client with the attendee name', 'Alex Muster' === ( $again2_data['attendee_name'] ?? '' ) && ! isset( $again2_data['ticket_id'] ) );
Settings::update( array( 'scanner_show_attendee' => 0 ) );
check( 'SUC: the new fields are client keys of refusals (web scanner and app)', ! array_diff( array( 'first_checked_in_area', 'first_checked_in_staff', 'attendee_name' ), Checkin::CLIENT_ERROR_KEYS ) );
$js = (string) file_get_contents( WPCAP_PATH . 'assets/scanner.js' );
check( 'SUC: web scanner shows entrance, staff and attendee name of a refusal', false !== strpos( $js, 'data.first_checked_in_area' ) && false !== strpos( $js, 'data.first_checked_in_staff' ) && false !== strpos( $js, 'info.attendee_name ? [info.attendee_name]' ) );
$pe = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'SUC: the scanner strings are translatable', false !== strpos( $pe, "'atEntrance' => __( 'Eingang: %s', 'pass-captain' )" ) && false !== strpos( $pe, "'staffName' => __( 'Personal: %s', 'pass-captain' )" ) );

echo "Scanner: undo needs its own capability\n";
adm_in( $s['codes'][1] );
$GLOBALS['wpcap_test_denied_caps'] = array( Capabilities::UNDO_CHECKIN );
$admin_undo = TicketsPage::undo_checkin( array( 'ticket_id' => suc_tid( $s['codes'][1] ), 'reason' => 'Fehlscan', 'idempotency_key' => 'suc-u1' ) );
check( 'SUC: admin undo without wpcap_undo_checkin is refused (403), ticket stays checked in', is_wp_error( $admin_undo ) && 'wpcap_undo_capability' === $admin_undo->get_error_code() && 403 === (int) ( $admin_undo->get_error_data()['status'] ?? 0 ) && 'checked_in' === suc_status( $s['codes'][1] ) );
$req = new WP_REST_Request( 'POST', '/pass-captain/v1/scanner/undo' );
$req->set_param( 'ticket_public_id', (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=%d", suc_tid( $s['codes'][1] ) ) ) );
$req->set_param( 'reason', 'Fehlscan' );
$web = ScannerDesk::rest_undo( $req );
$web = $web instanceof WP_REST_Response ? $web->get_data() : $web;
$web_result = is_wp_error( $web ) ? (string) ( $web->get_error_data()['result'] ?? '' ) : (string) ( $web['result'] ?? '' );
check( 'SUC: web scanner undo without the capability answers forbidden', 'forbidden' === $web_result && 'checked_in' === suc_status( $s['codes'][1] ) );
$no_cap_no_reason = ScannerDesk::undo( array( 'code' => $s['codes'][1] ), array() );
check( 'SUC: the capability is checked before the ticket and the reason', is_wp_error( $no_cap_no_reason ) && 'wpcap_undo_capability' === $no_cap_no_reason->get_error_code() );
$GLOBALS['wpcap_test_denied_caps'] = array();
$ok = TicketsPage::undo_checkin( array( 'ticket_id' => suc_tid( $s['codes'][1] ), 'reason' => 'Fehlscan', 'idempotency_key' => 'suc-u2' ) );
check( 'SUC: with the capability (and a reason) the undo works as before', ! is_wp_error( $ok ) && 'valid' === suc_status( $s['codes'][1] ) );
$no_reason = TicketsPage::undo_checkin( array( 'ticket_id' => suc_tid( $s['codes'][0] ), 'idempotency_key' => 'suc-u3' ) );
check( 'SUC: a reason stays required', is_wp_error( $no_reason ) && 'wpcap_undo_reason_required' === $no_reason->get_error_code() );
$tp = (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' );
$handler = substr( $tp, (int) strpos( $tp, 'function handle_undo_checkin' ), 600 );
check( 'SUC: the admin handler checks wpcap_undo_checkin before the nonce', false !== strpos( $handler, 'Capabilities::UNDO_CHECKIN' ) && strpos( $handler, 'Capabilities::UNDO_CHECKIN' ) < strpos( $handler, 'Forms::verify' ) );
check( 'SUC: the ticket list offers the undo form only with the capability', false !== strpos( $tp, "\$can_checkin && current_user_can( \\PassCaptain\\Capabilities::UNDO_CHECKIN )" ) );

echo "Scanner: app permissions and pass redemption\n";
$GLOBALS['wpcap_test_user_id'] = 7;
$pair = Devices::create_pairing( array( $s['event'] ), 'SUC Gerät', 2 );
$GLOBALS['wpcap_test_user_id'] = 0;
$dev = Devices::pair( $pair['code'], 'SUC', 'android', '1.1' );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array( Capabilities::UNDO_CHECKIN );
$me = app_call( 'me', 'GET', $dev['token'] );
check( 'SUC: /app/me reports the permissions of the device user (additive field)', is_array( $me ) && true === ( $me['permissions']['check_in'] ?? null ) && false === ( $me['permissions']['undo'] ?? null ) && true === ( $me['permissions']['redeem_passes'] ?? null ) && true === ( $me['features']['undo'] ?? null ) );
adm_in( $s['codes'][2] );
$app = app_call( 'undo', 'POST', $dev['token'], array( 'code' => $s['codes'][2], 'reason' => 'Fehlscan', 'idempotency_key' => 'suc-app' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'SUC: app undo without the capability: ok=false, result forbidden, nothing changes', false === ( $app['ok'] ?? null ) && 'forbidden' === ( $app['result'] ?? '' ) && 'checked_in' === suc_status( $s['codes'][2] ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Capabilities::REDEEM_PASSES );
$rr = new WP_REST_Request( 'POST', '/pass-captain/v1/passes/redeem' );
$rr->set_param( 'ticket_type_id', $s['type'] );
$rr->set_param( 'token', str_repeat( 'a', 64 ) );
$rr->set_param( 'idempotency_key', 'suc-redeem' );
$redeem = PassCaptain\Rest\Controller::redeem_pass( $rr );
check( 'SUC: pass redemption needs wpcap_redeem_passes', is_wp_error( $redeem ) && 'wpcap_pass_redeem_capability' === $redeem->get_error_code() && 403 === (int) ( $redeem->get_error_data()['status'] ?? 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "Scanner: capabilities granted once to every role with wpcap_checkin\n";
$roles = Installer::role_definitions();
$checkin_roles = array_keys( array_filter( $roles, static fn( $d ) => in_array( 'wpcap_checkin', $d[1], true ) ) );
$all_have = true;
foreach ( $checkin_roles as $slug ) { $all_have = $all_have && ! array_diff( Capabilities::SPLIT_FROM_CHECKIN, $roles[ $slug ][1] ); }
check( 'SUC: every plugin role with wpcap_checkin also has undo and redeem (existing behaviour kept)', $all_have && count( $checkin_roles ) >= 4 );
check( 'SUC: the new capabilities are known plugin capabilities (removed on uninstall, given to administrators)', ! array_diff( Capabilities::SPLIT_FROM_CHECKIN, Capabilities::all() ) && ! array_diff( Capabilities::SPLIT_FROM_CHECKIN, Installer::CAPABILITIES ) );
if ( ! class_exists( 'Suc_Role' ) ) {
	class Suc_Role { public $capabilities; public function __construct( array $caps ) { $this->capabilities = $caps; } public function add_cap( $cap, $grant = true ) { $this->capabilities[ $cap ] = $grant; } }
	class Suc_Roles { public $roles = array(); public $objects = array(); public function get_role( $slug ) { return $this->objects[ $slug ] ?? null; } }
}
$fake = new Suc_Roles();
$fake->objects = array( 'administrator' => new Suc_Role( array( 'manage_options' => true, 'wpcap_checkin' => true ) ), 'custom_door' => new Suc_Role( array( 'read' => true, 'wpcap_checkin' => true ) ), 'editor' => new Suc_Role( array( 'read' => true ) ) );
$fake->roles = array_fill_keys( array_keys( $fake->objects ), array() );
$saved_flag = get_option( Installer::SPLIT_CAPS_OPTION );
delete_option( Installer::SPLIT_CAPS_OPTION );
Installer::grant_split_capabilities( $fake );
check( 'SUC: migration grants undo and redeem to administrators and custom roles with wpcap_checkin', ! empty( $fake->objects['custom_door']->capabilities['wpcap_undo_checkin'] ) && ! empty( $fake->objects['custom_door']->capabilities['wpcap_redeem_passes'] ) && ! empty( $fake->objects['administrator']->capabilities['wpcap_undo_checkin'] ) );
check( 'SUC: roles without wpcap_checkin get nothing', ! isset( $fake->objects['editor']->capabilities['wpcap_undo_checkin'] ) );
unset( $fake->objects['custom_door']->capabilities['wpcap_undo_checkin'] );
Installer::grant_split_capabilities( $fake );
check( 'SUC: the migration runs once; a later removal by the operator stays', ! isset( $fake->objects['custom_door']->capabilities['wpcap_undo_checkin'] ) );
if ( false === $saved_flag ) { delete_option( Installer::SPLIT_CAPS_OPTION ); } else { update_option( Installer::SPLIT_CAPS_OPTION, $saved_flag ); }
$sec = (string) file_get_contents( WPCAP_PATH . 'docs/SECURITY.md' );
$mob = (string) file_get_contents( WPCAP_PATH . 'docs/MOBILE-API.md' );
check( 'SUC: the split and the one-time grant are documented', false !== strpos( $sec, 'wpcap_split_checkin_caps' ) && false !== strpos( $sec, '`wpcap_undo_checkin`' ) && false !== strpos( $mob, 'wpcap_undo_capability' ) && false !== strpos( $mob, 'first_checked_in_area' ) );

Settings::update( array( 'scanner_show_attendee' => $suc_saved_show ) );
$GLOBALS['wpcap_test_user_id'] = $suc_saved_user ?? 1;
$GLOBALS['wpcap_test_denied_caps'] = $suc_saved_denied;
