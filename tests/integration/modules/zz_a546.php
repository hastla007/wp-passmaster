<?php
/**
 * A546: check-in counter (admitted / total) for the web scanner (GET /scanner/stats) and the apps
 * (`admitted` in /app/occurrences/{id}/stats). The PWA and web scanner display is tested in
 * tests/js/scanner.test.js and tests/js/offline-scanner.test.js.
 * Included from run.php after admission.php and mobile.php (uses check(), adm_setup(), adm_in(), app_call()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Policies;
use PassCaptain\Mobile\Devices;
use PassCaptain\ScannerDesk;

if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

function a546_tid( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a546_stats( $occ ) {
	$req = new WP_REST_Request( 'GET', '/pass-captain/v1/scanner/stats' );
	$req->set_param( 'occurrence_id', $occ );
	$res = ScannerDesk::rest_stats( $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}

echo "A546: check-in counter\n";
$s = adm_setup( 'UTC', null, null, 5 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
$c0 = ScannerDesk::counts( $s['occ'] );
check( 'A546: a fresh date counts 0 of 5', is_array( $c0 ) && 0 === $c0['admitted'] && 5 === $c0['total'] && 5 === $c0['remaining'] );
adm_in( $s['codes'][0] );
adm_in( $s['codes'][1] );
$c1 = ScannerDesk::counts( $s['occ'] );
check( 'A546: two admissions count 2 of 5', 2 === $c1['admitted'] && 5 === $c1['total'] );
TicketsPage::undo_checkin( array( 'ticket_id' => a546_tid( $s['codes'][1] ), 'reason' => 'Fehlscan' ) );
check( 'A546: an undone admission no longer counts', 1 === ScannerDesk::counts( $s['occ'] )['admitted'] );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => a546_tid( $s['codes'][2] ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 1 ), array( 'ticket_id' => a546_tid( $s['codes'][3] ) ) );
check( 'A546: revoked and refunded tickets are not part of the total', 3 === ScannerDesk::counts( $s['occ'] )['total'] && 1 === ScannerDesk::counts( $s['occ'] )['admitted'] );

// Repeatable tickets keep status valid but count as admitted (once, however often they entered).
$m = adm_setup( 'UTC', null, null, 2 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $m['occ'] ) );
Policies::save( $m['type'], array( 'policy' => 'multi_entry', 'max_entries' => 3 ) );
adm_in( $m['codes'][0] );
adm_in( $m['codes'][0] );
$cm = ScannerDesk::counts( $m['occ'] );
check( 'A546: a multi-entry ticket admitted twice counts once (status stays valid)', 1 === $cm['admitted'] && 2 === $cm['total'] );

// Web route: scope.
$web = a546_stats( $s['occ'] );
check( 'A546: GET /scanner/stats answers admitted and total of the date', is_array( $web ) && 1 === $web['admitted'] && 3 === $web['total'] && ! empty( $web['server_time'] ) );
$GLOBALS['wpcap_test_user_id'] = 9;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$foreign = a546_stats( $s['occ'] );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A546: staff without access to the event get 403, no figures', is_wp_error( $foreign ) && 403 === $foreign->get_error_data()['status'] );
check( 'A546: no counter without a date', is_wp_error( a546_stats( 0 ) ) );

// App stats carry the same counter.
$GLOBALS['wpcap_test_user_id'] = 7;
$pair = Devices::create_pairing( array( $m['event'] ), 'A546 Gerät', 2 );
$GLOBALS['wpcap_test_user_id'] = 0;
$dev = Devices::pair( $pair['code'], 'A546', 'pwa', '1.0' );
$GLOBALS['wpcap_test_user_id'] = 1;
$req = app_request( 'GET', $dev['token'] );
$req['occurrence_id'] = $m['occ'];
$app = PassCaptain\Mobile\Api::authenticate( $req ) === true ? PassCaptain\Mobile\Api::stats( $req )->get_data() : array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A546: /app/occurrences/{id}/stats adds admitted and admission_total (repeatable tickets included)', 1 === ( $app['admitted'] ?? null ) && 2 === ( $app['admission_total'] ?? null ) && 0 === ( $app['checked_in'] ?? null ) );
$page = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'A546: the scanner page has the counter line and the stats URL', false !== strpos( $page, '<p class="wpcap-scan-counter" hidden></p>' ) && false !== strpos( $page, "'statsUrl' => esc_url_raw( rest_url( 'pass-captain/v1/scanner/stats' ) )" ) );
