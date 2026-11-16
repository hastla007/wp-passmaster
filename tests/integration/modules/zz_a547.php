<?php
/**
 * A547: undo of a mistaken check-in at the scanner – required reason everywhere (admin, app, web),
 * web scanner only for the own admission shortly after the scan, guarded by Checkin::undo_guard().
 * Included from run.php after admission.php and mobile.php (uses check(), adm_setup(), adm_in(), app_call()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;
use PassCaptain\Checkin;
use PassCaptain\Mobile\Devices;
use PassCaptain\ScannerDesk;

if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

function a547_tid( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a547_status( $code ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a547_public( $code ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a547_web_undo( array $params ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/scanner/undo' );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$res = ScannerDesk::rest_undo( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
function a547_res( $r ) { return is_wp_error( $r ) ? (string) ( $r->get_error_data()['result'] ?? $r->get_error_code() ) : (string) ( $r['result'] ?? '' ); }
/** Started occurrence (inside the default entry window). */
function a547_setup( $qty, $policy = null ) {
	global $wpdb;
	$s = adm_setup( 'UTC', null, null, $qty );
	$wpdb->update( "{$wpdb->prefix}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
	if ( $policy ) { Policies::save( $s['type'], $policy ); }
	return $s;
}

echo "A547: required reason for every undo\n";
$s = a547_setup( 6 );
adm_in( $s['codes'][0] );
$no_reason = TicketsPage::undo_checkin( array( 'ticket_id' => a547_tid( $s['codes'][0] ) ) );
check( 'A547: admin undo without reason is refused, the ticket stays checked in', is_wp_error( $no_reason ) && 'wpcap_undo_reason_required' === $no_reason->get_error_code() && 'checked_in' === a547_status( $s['codes'][0] ) );
$blank = TicketsPage::undo_checkin( array( 'ticket_id' => a547_tid( $s['codes'][0] ), 'reason' => '   ' ) );
check( 'A547: a blank reason counts as none', is_wp_error( $blank ) && 'checked_in' === a547_status( $s['codes'][0] ) );
$admin_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' );
check( 'A547: the admin form marks the reason as required', false !== strpos( $admin_src, 'name="reason" maxlength="255" required' ) && false === strpos( $admin_src, "Grund (optional)" ) );
$sm = a547_setup( 1, array( 'policy' => 'multi_entry', 'max_entries' => 3 ) );
adm_in( $sm['codes'][0] );
$multi = Engine::undo_last( Checkin::lookup( $sm['codes'][0], null, false ), '' );
check( 'A547: undo of a repeatable entry without reason is refused', is_wp_error( $multi ) && 'reason_required' === a547_res( $multi ) && 1 === Engine::entry_count( a547_tid( $sm['codes'][0] ) ) );

// App: reason required, ticket_public_id accepted (search results / admissions without code).
$GLOBALS['wpcap_test_user_id'] = 7;
$pair = Devices::create_pairing( array( $s['event'] ), 'A547 Gerät', 2 );
$GLOBALS['wpcap_test_user_id'] = 0;
$dev = Devices::pair( $pair['code'], 'A547', 'android', '1.1' );
$GLOBALS['wpcap_test_user_id'] = 1;
adm_in( $s['codes'][1] );
$app_none = app_call( 'undo', 'POST', $dev['token'], array( 'code' => $s['codes'][1], 'idempotency_key' => 'a547-none' /* merged: app undo needs a key (R2) */ ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A547: app undo without reason answers ok=false, result reason_required; nothing changes', false === ( $app_none['ok'] ?? null ) && 'reason_required' === ( $app_none['result'] ?? '' ) && 'checked_in' === a547_status( $s['codes'][1] ) );
$app_pub = app_call( 'undo', 'POST', $dev['token'], array( 'ticket_public_id' => a547_public( $s['codes'][1] ), 'reason' => 'Fehlscan', 'idempotency_key' => 'a547-pub' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A547: app undo by ticket_public_id with reason works', true === ( $app_pub['ok'] ?? null ) && 'undone' === ( $app_pub['result'] ?? '' ) && 'valid' === a547_status( $s['codes'][1] ) );

echo "A547: web scanner undo (own admission, shortly after the scan)\n";
adm_in( $s['codes'][2] );
$web_none = a547_web_undo( array( 'ticket_public_id' => a547_public( $s['codes'][2] ) ) );
check( 'A547: web undo without reason is a 400 with result reason_required', is_wp_error( $web_none ) && 400 === $web_none->get_error_data()['status'] && 'reason_required' === a547_res( $web_none ) && 'checked_in' === a547_status( $s['codes'][2] ) );
$web_ok = a547_web_undo( array( 'ticket_public_id' => a547_public( $s['codes'][2] ), 'reason' => 'Doppelt gescannt' ) );
$undo_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_checkins WHERE ticket_id=" . a547_tid( $s['codes'][2] ) . " AND result='undone'" );
$audit = $wpdb->get_row( "SELECT * FROM {$p}wpcap_audit_logs WHERE action='checkin_undone' AND object_id=" . a547_tid( $s['codes'][2] ) . ' ORDER BY audit_id DESC LIMIT 1' );
check( 'A547: web undo of the own fresh admission works, logged as source web with the reason', ! is_wp_error( $web_ok ) && 'undone' === ( $web_ok['result'] ?? '' ) && 'valid' === a547_status( $s['codes'][2] ) && $undo_row && 'web' === $undo_row->source && $audit && false !== strpos( (string) $audit->details, 'Doppelt gescannt' ) );
check( 'A547: the ticket can be scanned again afterwards', 'checked_in' === adm_in( $s['codes'][2] ) );

// Another person's admission.
$GLOBALS['wpcap_test_user_id'] = 8;
adm_in( $s['codes'][3] );
$GLOBALS['wpcap_test_user_id'] = 1;
$web_other = a547_web_undo( array( 'ticket_public_id' => a547_public( $s['codes'][3] ), 'reason' => 'x' ) );
check( 'A547: the web scanner cannot undo an admission of another person', is_wp_error( $web_other ) && 'undo_not_allowed' === a547_res( $web_other ) && 'checked_in' === a547_status( $s['codes'][3] ) );
// Too old.
adm_in( $s['codes'][4] );
$wpdb->query( "UPDATE {$p}wpcap_checkins SET created_at='" . gmdate( 'Y-m-d H:i:s', time() - ScannerDesk::undo_seconds() - 60 ) . "' WHERE ticket_id=" . a547_tid( $s['codes'][4] ) );
$web_old = a547_web_undo( array( 'ticket_public_id' => a547_public( $s['codes'][4] ), 'reason' => 'x' ) );
check( 'A547: the web scanner cannot undo an admission older than the undo window', is_wp_error( $web_old ) && 'undo_not_allowed' === a547_res( $web_old ) && 'checked_in' === a547_status( $s['codes'][4] ) );
check( 'A547: admin undo is not limited by the scanner window', ! is_wp_error( TicketsPage::undo_checkin( array( 'ticket_id' => a547_tid( $s['codes'][4] ), 'reason' => 'Einlassleitung' ) ) ) );

// Checkin::undo_guard() applies: refunded ticket, other area.
adm_in( $s['codes'][5] );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 1 ), array( 'ticket_id' => a547_tid( $s['codes'][5] ) ) );
$web_refunded = a547_web_undo( array( 'ticket_public_id' => a547_public( $s['codes'][5] ), 'reason' => 'x' ) );
check( 'A547: web undo of a refunded checked-in ticket is refused (revoked), it stays used', is_wp_error( $web_refunded ) && 'revoked' === a547_res( $web_refunded ) && 'checked_in' === a547_status( $s['codes'][5] ) );
$sa = a547_setup( 1 );
$nord = Areas::save( $sa['event'], 'A547 Nord' );
$sued = Areas::save( $sa['event'], 'A547 Süd' );
adm_in( $sa['codes'][0], null, array( 'area_id' => $nord ) );
$web_area = a547_web_undo( array( 'ticket_public_id' => a547_public( $sa['codes'][0] ), 'reason' => 'x', 'area_id' => $sued ) );
check( 'A547: web undo from another entrance area is refused by the undo guard', is_wp_error( $web_area ) && 'wrong_area' === a547_res( $web_area ) && 'checked_in' === a547_status( $sa['codes'][0] ) );
$web_area_ok = a547_web_undo( array( 'ticket_public_id' => a547_public( $sa['codes'][0] ), 'reason' => 'x', 'area_id' => $nord ) );
check( 'A547: web undo in the admission area works', ! is_wp_error( $web_area_ok ) && 'valid' === a547_status( $sa['codes'][0] ) );
$GLOBALS['wpcap_test_user_id'] = 9;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$web_scope = a547_web_undo( array( 'ticket_public_id' => a547_public( $s['codes'][3] ), 'reason' => 'x' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A547: staff without access to the event cannot undo (forbidden)', is_wp_error( $web_scope ) && 'forbidden' === a547_res( $web_scope ) );
$scanner_src = (string) file_get_contents( WPCAP_PATH . 'src/ScannerDesk.php' );
check( 'A547: the web undo route goes through the undo paths that call Checkin::undo_guard()', false !== strpos( $scanner_src, 'Engine::undo_last(' ) && false !== strpos( $scanner_src, 'TicketsPage::undo_checkin(' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' ), 'Checkin::undo_guard(' ) );
