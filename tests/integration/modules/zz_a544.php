<?php
/**
 * A544: manual search at the scanner (order number; attendee name/e-mail only as the Pii rules
 * allow), check-in of a result by its id (same rules as a scan, logged as manual), app search and
 * offline upload of search admissions by package hash.
 * Included from run.php after admission.php and mobile.php (uses check(), adm_setup(), app_call(), app_request()).
 */
// phpcs:ignoreFile
use PassCaptain\Frontend\Format;
use PassCaptain\Mobile\Devices;
use PassCaptain\ScannerDesk;

if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();

function a544_tid( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function a544_rest( $method, $callback, array $params ) {
	$req = new WP_REST_Request( $method, '/pass-captain/v1/scanner/x' );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	$res = call_user_func( array( ScannerDesk::class, $callback ), $req );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
function a544_ids( $r ) { return is_array( $r ) ? array_column( $r['results'], 'ticket_public_id' ) : array(); }
/** Door staff: check-in only (no order/event management), assigned to the event. */
function a544_as_door( $event, callable $fn ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_event_staff (event_id,user_id,capability) VALUES (%d,8,'wpcap_checkin')", $event ) );
	$GLOBALS['wpcap_test_user_id'] = 8;
	$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_orders', 'wpcap_manage_events', 'wpcap_view_finance', 'wpcap_view_reports', 'wpcap_service_bookings', 'wpcap_service_provider' );
	try { return $fn(); } finally { $GLOBALS['wpcap_test_denied_caps'] = array(); $GLOBALS['wpcap_test_user_id'] = 1; }
}

echo "A544: manual search at the scanner\n";
$s = adm_setup( 'UTC', null, null, 3 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
$t0 = a544_tid( $s['codes'][0] );
$t1 = a544_tid( $s['codes'][1] );
$pub0 = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=$t0" );
$order = $wpdb->get_row( "SELECT o.public_id,o.customer_email FROM {$p}wpcap_orders o JOIN {$p}wpcap_tickets t ON t.order_id=o.order_id WHERE t.ticket_id=$t0" );
$wpdb->insert( "{$p}wpcap_ticket_assignments", array( 'ticket_id' => $t0, 'buyer_email' => $order->customer_email, 'attendee_email' => 'anna.zyxwv@example.com', 'attendee_name' => 'Anna Zyxwv', 'status' => 'assigned', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$ref = Format::order_reference( $order->public_id );

PassCaptain\Settings::update( array( 'scanner_show_attendee' => 1 ) );
$by_name = ScannerDesk::search( 'Zyxwv', array( 'occurrence_id' => $s['occ'] ) );
check( 'A544: staff with full rights find a ticket by attendee name; e-mail shown masked', array( $pub0 ) === a544_ids( $by_name ) && 'Anna Zyxwv' === $by_name['results'][0]['attendee_name'] && false === strpos( $by_name['results'][0]['email'], 'zyxwv@' ) && $ref === $by_name['results'][0]['order_ref'] && 'valid' === $by_name['results'][0]['status'] );
PassCaptain\Settings::update( array( 'scanner_show_attendee' => 0 ) );
$masked = ScannerDesk::search( 'Zyxwv', array( 'occurrence_id' => $s['occ'] ) );
check( 'A544: without the scanner setting even full rights see the name only masked on the scan screen', array( $pub0 ) === a544_ids( $masked ) && 'A. Z.' === $masked['results'][0]['attendee_name'] );
check( 'A544: … and by e-mail', array( $pub0 ) === a544_ids( ScannerDesk::search( 'anna.zyxwv@', array( 'occurrence_id' => $s['occ'] ) ) ) );
$by_ref = ScannerDesk::search( $ref, array( 'occurrence_id' => $s['occ'] ) );
check( 'A544: the order number as on the confirmation ("AB12-CD34") finds all tickets of the order', 3 === count( a544_ids( $by_ref ) ) );
check( 'A544: too short terms are refused', is_wp_error( ScannerDesk::search( 'ab', array( 'occurrence_id' => $s['occ'] ) ) ) );
check( 'A544: without a chosen date the dates of the scanner list are searched', in_array( $pub0, a544_ids( ScannerDesk::search( 'Zyxwv', array() ) ), true ) );

// Door staff: only fields of their Pii profile (name only with "Teilnehmername im Scanner").
PassCaptain\Settings::update( array( 'scanner_show_attendee' => 0 ) );
$door_name = a544_as_door( $s['event'], static fn() => ScannerDesk::search( 'Zyxwv', array( 'occurrence_id' => $s['occ'] ) ) );
$door_mail = a544_as_door( $s['event'], static fn() => ScannerDesk::search( 'anna.zyxwv@', array( 'occurrence_id' => $s['occ'] ) ) );
$door_ref = a544_as_door( $s['event'], static fn() => ScannerDesk::search( $ref, array( 'occurrence_id' => $s['occ'] ) ) );
check( 'A544: door staff without the name setting cannot search names or e-mails (no probing, A119)', array() === a544_ids( $door_name ) && array() === a544_ids( $door_mail ) && array( 'order' ) === $door_name['fields'] );
check( 'A544: door staff find the order number; the name is shown masked', 3 === count( a544_ids( $door_ref ) ) && in_array( 'A. Z.', array_column( $door_ref['results'], 'attendee_name' ), true ) );
PassCaptain\Settings::update( array( 'scanner_show_attendee' => 1 ) );
$door_name2 = a544_as_door( $s['event'], static fn() => ScannerDesk::search( 'Zyxwv', array( 'occurrence_id' => $s['occ'] ) ) );
check( 'A544: with "Teilnehmername im Scanner" door staff can search the name', array( $pub0 ) === a544_ids( $door_name2 ) );
PassCaptain\Settings::update( array( 'scanner_show_attendee' => 0 ) );
$GLOBALS['wpcap_test_user_id'] = 9;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$foreign = ScannerDesk::search( $ref, array( 'occurrence_id' => $s['occ'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A544: staff without access to the event find nothing', array() === a544_ids( $foreign ) );

// Check-in of a result: same rules as a scan.
$web = a544_rest( 'POST', 'rest_check_in_ticket', array( 'ticket_public_id' => $pub0, 'occurrence_id' => $s['occ'], 'idempotency_key' => 'a544-1' ) );
$row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_checkins WHERE ticket_id=$t0 AND result='checked_in'" );
$audit = $wpdb->get_row( "SELECT * FROM {$p}wpcap_audit_logs WHERE action='ticket_checked_in' AND object_id=$t0 ORDER BY audit_id DESC LIMIT 1" );
check( 'A544: "Einchecken" from the search admits the ticket (source web, audit via manual)', is_array( $web ) && 'checked_in' === $web['status'] && $row && 'web' === $row->source && $audit && false !== strpos( (string) $audit->details, 'manual' ) );
check( 'A544: the same key is a replay, not a second admission', ! empty( a544_rest( 'POST', 'rest_check_in_ticket', array( 'ticket_public_id' => $pub0, 'occurrence_id' => $s['occ'], 'idempotency_key' => 'a544-1' ) )['replayed'] ) );
$again = a544_rest( 'POST', 'rest_check_in_ticket', array( 'ticket_public_id' => $pub0, 'occurrence_id' => $s['occ'], 'idempotency_key' => 'a544-2' ) );
check( 'A544: a second manual check-in is refused like a second scan', is_wp_error( $again ) && 'already_checked_in' === ( $again->get_error_data()['result'] ?? '' ) );
$other = adm_setup( 'UTC', null, null, 1 );
$wrong = a544_rest( 'POST', 'rest_check_in_ticket', array( 'ticket_public_id' => (string) $wpdb->get_var( 'SELECT public_id FROM ' . $p . 'wpcap_tickets WHERE ticket_id=' . a544_tid( $other['codes'][0] ) ), 'occurrence_id' => $s['occ'], 'idempotency_key' => 'a544-3' ) );
check( 'A544: a ticket of another date is refused for the chosen date', is_wp_error( $wrong ) && 'wrong_occurrence' === ( $wrong->get_error_data()['result'] ?? '' ) );
check( 'A544: unknown ticket ids are "unknown"', 'unknown' === ( a544_rest( 'POST', 'rest_check_in_ticket', array( 'ticket_public_id' => str_repeat( '0', 32 ), 'idempotency_key' => 'a544-4' ) )->get_error_data()['result'] ?? '' ) );
$searched = a544_rest( 'GET', 'rest_search', array( 'q' => 'Zyxwv', 'occurrence_id' => $s['occ'] ) );
check( 'A544: GET /scanner/search shows the new status', is_array( $searched ) && 'checked_in' === $searched['results'][0]['status'] );

// Rate limit.
$GLOBALS['wpcap_test_filters']['wpcap_scanner_search_limit'] = 1;
$GLOBALS['wpcap_test_transients'] = array();
ScannerDesk::search( $ref, array( 'occurrence_id' => $s['occ'] ) );
$limited = ScannerDesk::search( $ref, array( 'occurrence_id' => $s['occ'] ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_scanner_search_limit'] );
$GLOBALS['wpcap_test_transients'] = array();
check( 'A544: searches are rate-limited per person (429)', is_wp_error( $limited ) && 429 === $limited->get_error_data()['status'] );

echo "A544: search and manual check-in in the app API\n";
$GLOBALS['wpcap_test_user_id'] = 7;
$pair = Devices::create_pairing( array( $s['event'] ), 'A544 Gerät', 2 );
$GLOBALS['wpcap_test_user_id'] = 0;
$dev = Devices::pair( $pair['code'], 'A544', 'pwa', '1.0' );
$device_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT device_id FROM {$p}wpcap_scanner_devices WHERE public_id=%s", $dev['device_id'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$app_search = app_call( 'search', 'GET', $dev['token'], array( 'q' => $ref, 'occurrence_id' => $s['occ'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$pub1 = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=$t1" );
check( 'A544: /app/search finds the tickets of the order', true === ( $app_search['ok'] ?? null ) && in_array( $pub1, array_column( $app_search['results'] ?? array(), 'ticket_public_id' ), true ) );
$app_in = app_call( 'check_in', 'POST', $dev['token'], array( 'ticket_public_id' => $pub1, 'idempotency_key' => 'a544-app-1', 'occurrence_id' => $s['occ'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$app_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_checkins WHERE ticket_id=$t1 AND result='checked_in'" );
check( 'A544: /app/scan/check-in with ticket_public_id admits (device logged)', true === ( $app_in['ok'] ?? null ) && $app_row && $device_id === (int) $app_row->device_id && 'app' === $app_row->source );
$foreign_dev = app_call( 'check_in', 'POST', $dev['token'], array( 'ticket_public_id' => (string) $wpdb->get_var( 'SELECT public_id FROM ' . $p . 'wpcap_tickets WHERE ticket_id=' . a544_tid( $other['codes'][0] ) ), 'idempotency_key' => 'a544-app-2' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A544: the device restriction applies to manual check-ins (wrong_event)', false === ( $foreign_dev['ok'] ?? null ) && 'wrong_event' === ( $foreign_dev['result'] ?? '' ) );
// Offline admission from the PWA search: uploaded with the package hash instead of a code.
$t2 = a544_tid( $s['codes'][2] );
$sync = app_call( 'sync', 'POST', $dev['token'], array( 'scans' => array( array( 'code' => '', 'ticket_hash' => hash( 'sha256', $s['codes'][2] ), 'idempotency_key' => 'a544-off-1', 'scanned_at' => gmdate( 'c', time() - 60 ), 'direction' => 'in' ) ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$off_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_checkins WHERE ticket_id=$t2 AND result='checked_in'" );
check( 'A544: an offline search admission is booked by ticket_hash (source offline)', true === ( $sync['results'][0]['ok'] ?? null ) && $off_row && 'offline' === $off_row->source );
$sync2 = app_call( 'sync', 'POST', $dev['token'], array( 'scans' => array( array( 'code' => '', 'ticket_hash' => str_repeat( 'ab', 32 ), 'idempotency_key' => 'a544-off-2', 'direction' => 'in' ) ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A544: an unknown hash books nothing', false === ( $sync2['results'][0]['ok'] ?? null ) && 'unknown' === ( $sync2['results'][0]['result'] ?? '' ) );
$page = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'A544: the web scanner page has the search form and the URLs', false !== strpos( $page, 'wpcap-search-form' ) && false !== strpos( $page, "'searchUrl'" ) && false !== strpos( $page, "'checkInTicketUrl'" ) );
