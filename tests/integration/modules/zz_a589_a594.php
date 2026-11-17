<?php
/**
 * Package R1 – admission and ticket security beyond the normal path (A594):
 * A589 parallel retries with the same idempotency key, keys for offline uploads;
 * A590 undo of check-ins (refunded tickets, area and device binding, logging);
 * A591 guest list arrival without code rotation;
 * A592 client-chosen scan time, source and device scope of offline uploads;
 * A593 buyer self-service code rotations during admission;
 * A345 retryable server errors in offline uploads.
 * Included from run.php after admission.php and mobile.php (uses check(), make_type(), adm_*(), app_call()).
 */
// phpcs:ignoreFile
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Admission\Areas;
use PassCaptain\Admission\CodeRotationLock;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;
use PassCaptain\Admission\Rest as AdmissionRest;
use PassCaptain\Checkin;
use PassCaptain\GuestLists\Service as Gl;
use PassCaptain\Mobile\Devices;
use PassCaptain\Orders;
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$r1_settings = array_intersect_key( PassCaptain\Settings::all(), array_flip( array( 'default_entry_window', 'entry_opens_before_minutes', 'entry_closes_after_minutes', 'attendee_edit_hours' ) ) );
// attendee_edit_hours 0: the attendee change is only limited by the rotation lock under test.
PassCaptain\Settings::update( array( 'default_entry_window' => 1, 'entry_opens_before_minutes' => 120, 'entry_closes_after_minutes' => 0, 'attendee_edit_hours' => 0 ) );

/** Event with $qty free tickets whose occurrence started an hour ago (inside the default entry window). */
function r1_setup( $qty = 1, $policy = null ) {
	global $wpdb;
	$s = adm_setup( 'UTC', null, null, $qty );
	$wpdb->update( "{$wpdb->prefix}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
	if ( $policy ) { Policies::save( $s['type'], $policy ); }
	return $s;
}
function r1_tid( $code ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
}
function r1_ticket( $tid ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $tid ) );
}
function r1_conflicts( $tid ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_admission_conflicts WHERE ticket_id=%d ORDER BY conflict_id", $tid ) );
}
function r1_err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
/** $n parallel check-ins of one ticket, all with the same idempotency key. */
function r1_parallel( $code, $key, $n ) {
	$start = microtime( true ) + 0.6;
	$procs = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$procs[] = popen( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a589/child.php' ) . ' ' . escapeshellarg( $code ) . ' ' . escapeshellarg( (string) $start ) . ' ' . escapeshellarg( $key ), 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	sort( $out );
	return $out;
}
function r1_data( $res ) { return $res instanceof WP_REST_Response ? (array) $res->get_data() : array(); }
function r1_json_request( array $body ) {
	$req = new WP_REST_Request( 'POST' );
	$req->set_body( wp_json_encode( $body ) );
	return $req;
}

echo "A589: parallel retries with the same idempotency key\n";
for ( $run = 1; $run <= 2; $run++ ) {
	$s = r1_setup( 1, array( 'policy' => 'multi_entry', 'max_entries' => 3 ) );
	$out = r1_parallel( $s['codes'][0], 'app:r1-multi-' . $run . '-' . uniqid(), 4 );
	$tid = r1_tid( $s['codes'][0] );
	check( "A589 run $run: multi-entry ticket, 4 parallel requests with one key consume one entry (" . implode( ',', $out ) . ')', 1 === Engine::entry_count( $tid ) && array( 'OK', 'REPLAY', 'REPLAY', 'REPLAY' ) === $out );
	$s = r1_setup( 1 );
	$out = r1_parallel( $s['codes'][0], 'app:r1-single-' . $run . '-' . uniqid(), 4 );
	$tid = r1_tid( $s['codes'][0] );
	check( "A589 run $run: single ticket, the retries get the first answer instead of \"already used\" (" . implode( ',', $out ) . ')', array( 'OK', 'REPLAY', 'REPLAY', 'REPLAY' ) === $out && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=$tid" ) );
}

$s = r1_setup( 3 );
$scans = array( array( 'token' => $s['codes'][0], 'idempotency_key' => 'r1-q-1' ), array( 'token' => $s['codes'][1], 'idempotency_key' => 'r1-q-2' ) );
$first = r1_data( Controller::offline_sync( r1_json_request( array( 'scans' => $scans ) ) ) );
$again = r1_data( Controller::offline_sync( r1_json_request( array( 'scans' => $scans ) ) ) );
check( 'A589: legacy web offline sync takes scans with keys; a repeated upload is replayed, no conflict', 'checked_in' === ( $first['results'][0]['status'] ?? '' ) && 'r1-q-1' === ( $first['results'][0]['idempotency_key'] ?? '' ) && ! empty( $again['results'][0]['replayed'] ) && ! empty( $again['results'][1]['replayed'] ) && ! r1_conflicts( r1_tid( $s['codes'][0] ) ) && ! r1_conflicts( r1_tid( $s['codes'][1] ) ) );
$bare1 = r1_data( Controller::offline_sync( r1_json_request( array( 'tokens' => array( $s['codes'][2] ) ) ) ) );
$bare2 = r1_data( Controller::offline_sync( r1_json_request( array( 'tokens' => array( $s['codes'][2] ) ) ) ) );
check( 'A589: old form without keys: a repeated upload of the same code is no double-admission conflict', 'checked_in' === ( $bare1['results'][0]['status'] ?? '' ) && ! empty( $bare2['results'][0]['replayed'] ) && ! r1_conflicts( r1_tid( $s['codes'][2] ) ) );
$s = r1_setup( 1 );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'scans', array( array( 'token' => $s['codes'][0], 'scanned_at' => gmdate( 'c', time() - 60 ) ) ) );
$nokey = r1_data( AdmissionRest::offline_sync( $req ) );
check( 'A589: admission offline sync refuses a scan without idempotency key (nothing booked)', 'invalid' === ( $nokey['results'][0]['result'] ?? '' ) && 'valid' === r1_ticket( r1_tid( $s['codes'][0] ) )->status );

echo "A592: scan time, source and device scope are not taken from the client\n";
$s = r1_setup( 1 );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'token', $s['codes'][0] );
$req->set_param( 'source', 'offline' );
$req->set_param( 'idempotency_key', 'r1-src-1' );
Controller::check_in( $req );
check( 'A592: the web scanner check-in ignores a client "source" (logged as web)', 'web' === $wpdb->get_var( "SELECT source FROM {$p}wpcap_checkins WHERE ticket_id=" . r1_tid( $s['codes'][0] ) . " AND result='checked_in'" ) );
$s = r1_setup( 1 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 6 * 3600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * 3600 ) ), array( 'occurrence_id' => $s['occ'] ) );
check( 'A592: the entry window of the occurrence is closed now', 'outside_entry_window' === adm_r( Checkin::preview( $s['codes'][0] ) ) );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'scans', array( array( 'token' => $s['codes'][0], 'idempotency_key' => 'r1-back-1', 'scanned_at' => gmdate( 'c', time() - 5 * 3600 ) ) ) );
$back = r1_data( AdmissionRest::offline_sync( $req ) );
$conf = r1_conflicts( r1_tid( $s['codes'][0] ) );
// Merged (R4): a web scanner upload older than 1 h is evaluated at upload time, so a scan claimed 5 h ago
// into a window that is closed now is not admitted; it is still always recorded for review (A592).
// Paired-device uploads inside the window are admitted and reviewed as backdated_admission (r4_scanner).
check( 'A592: backdated web upload (5 h) into a closed window (no policy) is not admitted but always recorded for review', 'conflict' === ( $back['results'][0]['status'] ?? '' ) && 'outside_entry_window' === ( $back['results'][0]['result'] ?? '' ) && empty( $back['results'][0]['admitted'] ) && 'valid' === r1_ticket( r1_tid( $s['codes'][0] ) )->status && 1 === count( $conf ) && 'open' === $conf[0]->status );
$s = r1_setup( 1 );
$nodev = Checkin::perform( $s['codes'][0], array( 'device_id' => 999, 'event_ids' => array(), 'idempotency_key' => 'r1-dev-1', 'source' => 'app' ) );
check( 'A592: a device without events scans nothing (empty event list fails closed)', 'wrong_event' === adm_r( $nodev ) && 'valid' === r1_ticket( r1_tid( $s['codes'][0] ) )->status );
$s = r1_setup( 3 );
$full_area = Areas::save( $s['event'], 'R1 Klein', 0, 1 );
check( 'setup: first guest inside the area of capacity 1', 'checked_in' === adm_in( $s['codes'][0], null, array( 'area_id' => $full_area ) ) );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'scans', array( array( 'token' => $s['codes'][1], 'idempotency_key' => 'r1-cap-1', 'scanned_at' => gmdate( 'c', time() - 60 ), 'area_id' => $full_area ) ) );
$cap_web = r1_data( AdmissionRest::offline_sync( $req ) );
check( 'A592: web scanner offline upload into a full area is refused like an online scan (conflict for review)', 'area_full' === ( $cap_web['results'][0]['result'] ?? '' ) && 'valid' === r1_ticket( r1_tid( $s['codes'][1] ) )->status && 1 === count( r1_conflicts( r1_tid( $s['codes'][1] ) ) ) );
$cap_dev = Checkin::perform( $s['codes'][2], array( 'source' => 'offline', 'device_id' => 4242, 'event_ids' => array( $s['event'] ), 'area_id' => $full_area, 'idempotency_key' => 'app:r1-cap-2', 'scanned_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
$cap_conf = r1_conflicts( r1_tid( $s['codes'][2] ) );
check( 'A592: offline upload of a paired device into a full area is admitted (person let in) but recorded for review', 'checked_in' === adm_r( $cap_dev ) && 'area_full' === ( $cap_dev['review'] ?? '' ) && 1 === count( $cap_conf ) && 'area_full' === $cap_conf[0]->result );

echo "A590: undo of check-ins\n";
$s = r1_setup( 3 );
$tid = r1_tid( $s['codes'][0] );
adm_in( $s['codes'][0] );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 1 ), array( 'ticket_id' => $tid ) ); // state after refunding a checked-in ticket
$undo = TicketsPage::undo_checkin( array( 'ticket_id' => $tid, 'reason' => 'Fehlscan' ) );
check( 'A590: undo of a refunded checked-in ticket is refused; it stays used', 'wpcap_undo_refunded' === r1_err( $undo ) && 'checked_in' === r1_ticket( $tid )->status && 'revoked' === adm_in( $s['codes'][0] ) );
$tid2 = r1_tid( $s['codes'][1] );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 1 ), array( 'ticket_id' => $tid2 ) );
check( 'A590: a valid ticket flagged refunded never admits (check-in and preview)', 'revoked' === adm_in( $s['codes'][1] ) && 'revoked' === adm_r( Checkin::preview( $s['codes'][1] ) ) && 'valid' === r1_ticket( $tid2 )->status );
$s2 = r1_setup( 1 );
$r1_cancelled = (int) r1_ticket( r1_tid( $s2['codes'][0] ) )->order_id;
$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'cancelled', 'payment_status' => 'cancelled' ), array( 'order_id' => $r1_cancelled ) );
check( 'A590: a ticket of a cancelled order never admits, even if it was left valid', 'revoked' === adm_in( $s2['codes'][0] ) );
$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'paid', 'payment_status' => 'paid' ), array( 'order_id' => $r1_cancelled ) );
$s3 = r1_setup( 1, array( 'policy' => 'multi_entry', 'max_entries' => 3 ) );
adm_in( $s3['codes'][0] );
$wpdb->update( "{$p}wpcap_tickets", array( 'refunded' => 1 ), array( 'ticket_id' => r1_tid( $s3['codes'][0] ) ) );
$undo_multi = Engine::undo_last( Checkin::lookup( $s3['codes'][0], null, false ), 'x' );
check( 'A590: undo of an entry of a refunded repeatable ticket is refused', 'wpcap_undo_refunded' === r1_err( $undo_multi ) && 1 === Engine::entry_count( r1_tid( $s3['codes'][0] ) ) );

// Area binding: staff assigned to "Nord" may only undo admissions through Nord.
$s = r1_setup( 4 );
$nord = Areas::save( $s['event'], 'R1 Nord' );
$sued = Areas::save( $s['event'], 'R1 Süd' );
Areas::add_staff( $nord, 8 );
$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES (%d,8,'wpcap_checkin')", $s['event'] ) );
adm_in( $s['codes'][0], null, array( 'area_id' => $sued ) );
adm_in( $s['codes'][1], null, array( 'area_id' => $nord ) );
$GLOBALS['wpcap_test_user_id'] = 8;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
// Merged: plain staff may only undo own scans of the last minutes (R4 undo window); a role from
// undo_roles may undo admissions of others – the area binding (A590) still applies to it.
PassCaptain\Security\Policy::register(); // undo_roles is a Policy setting (registered by Plugin::boot()).
PassCaptain\Settings::update( array( 'undo_roles' => 'wpcap_checkin_staff' ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] = array( 'wpcap_checkin_staff' );
$undo_other = TicketsPage::undo_checkin( array( 'ticket_id' => r1_tid( $s['codes'][0] ), 'reason' => 'x' ) );
$undo_own = TicketsPage::undo_checkin( array( 'ticket_id' => r1_tid( $s['codes'][1] ), 'reason' => 'Fehlscan' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] );
PassCaptain\Settings::update( array( 'undo_roles' => '' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$undo_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_checkins WHERE ticket_id=" . r1_tid( $s['codes'][1] ) . " AND result='undone'" );
$audit = $wpdb->get_row( "SELECT * FROM {$p}wpcap_audit_logs WHERE action='checkin_undone' AND object_id=" . r1_tid( $s['codes'][1] ) . ' ORDER BY audit_id DESC LIMIT 1' );
$audit_ctx = $audit ? (array) json_decode( (string) $audit->details, true ) : array();
check( 'A590: area staff cannot undo an admission of another area (ticket stays used)', 'wpcap_undo_wrong_area' === r1_err( $undo_other ) && 'checked_in' === r1_ticket( r1_tid( $s['codes'][0] ) )->status );
check( 'A590: area staff can undo an admission of their own area; logged with source and area', 'ok' === r1_err( $undo_own ) && 'valid' === r1_ticket( r1_tid( $s['codes'][1] ) )->status && $undo_row && 'admin' === $undo_row->source && $nord === (int) ( $audit_ctx['area_id'] ?? 0 ) );

// App undo: a device bound to Nord only undoes admissions through Nord, logged with the device.
$GLOBALS['wpcap_test_user_id'] = 7;
$pair = Devices::create_pairing( array( $s['event'] ), 'R1 Nord-Gerät', 2, $nord );
$GLOBALS['wpcap_test_user_id'] = 0;
$dev = Devices::pair( $pair['code'], 'R1 Nord', 'android', '1.1' );
$r1_token = $dev['token'];
$r1_device = (int) $wpdb->get_var( $wpdb->prepare( "SELECT device_id FROM {$p}wpcap_scanner_devices WHERE public_id=%s", $dev['device_id'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
adm_in( $s['codes'][2], null, array( 'area_id' => $sued ) );
adm_in( $s['codes'][3], null, array( 'area_id' => $nord ) );
$app_other = app_call( 'undo', 'POST', $r1_token, array( 'code' => $s['codes'][2], 'reason' => 'x', 'idempotency_key' => 'a590-other' /* merged: app undo needs a key (R2) */ ) );
$app_own = app_call( 'undo', 'POST', $r1_token, array( 'code' => $s['codes'][3], 'reason' => 'Fehlscan', 'idempotency_key' => 'a590-own' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$app_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_checkins WHERE ticket_id=" . r1_tid( $s['codes'][3] ) . " AND result='undone'" );
check( 'A590: app undo of an admission through another area is refused (wrong_area)', false === ( $app_other['ok'] ?? null ) && 'wrong_area' === ( $app_other['result'] ?? '' ) && 'checked_in' === r1_ticket( r1_tid( $s['codes'][2] ) )->status );
check( 'A590: app undo in the device area works and is logged with device and source', true === ( $app_own['ok'] ?? null ) && $app_row && $r1_device === (int) $app_row->device_id && 'app' === $app_row->source );

echo "A591: guest list arrival keeps the ticket codes\n";
// Registered once only (p3_guestlists.php usually did): a second registration doubles its hooks.
$r1_gl_registered = false;
foreach ( (array) ( $GLOBALS['wpcap_test_hooks']['wpcap_order_creating'] ?? array() ) as $r1_cbs ) {
	foreach ( $r1_cbs as $r1_cb ) { $r1_gl_registered = $r1_gl_registered || ( is_array( $r1_cb[0] ) && Gl::class === $r1_cb[0][0] ); }
}
if ( ! $r1_gl_registered ) { PassCaptain\Modules\GuestLists::register(); }
$s = adm_setup( 'UTC', null, null, 0 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * 86400 ), 'ends_at' => null ), array( 'occurrence_id' => $s['occ'] ) );
$gl_list = Gl::save_list( array( 'event_id' => $s['event'], 'occurrence_id' => $s['occ'], 'name' => 'R1 Presse', 'reason' => 'press' ) );
$gl_entry = Gl::add_entry( $gl_list->list_id, array( 'name' => 'R1 Gast', 'email' => 'r1gast@example.com', 'quantity' => 1, 'ticket_type_id' => $s['type'], 'approve' => true ) );
$gl_issued = Gl::issue( $gl_entry->entry_id );
$gl_tid = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) ( $gl_issued->order_id ?? 0 ) );
$gl_hash = (string) r1_ticket( $gl_tid )->token_hash;
$gl_early = Gl::arrive( $gl_entry->entry_id );
check( 'A591: arrival before the entry window is refused and the mailed code stays valid', is_wp_error( $gl_early ) && $gl_tid > 0 && $gl_hash === (string) r1_ticket( $gl_tid )->token_hash && 'valid' === r1_ticket( $gl_tid )->status );
// Started up to 10 minutes ago, but never on the previous UTC day: right after midnight a day ticket of
// yesterday's occurrence is (correctly) outside its entry window.
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - min( 600, time() % DAY_IN_SECONDS ) ) ), array( 'occurrence_id' => $s['occ'] ) );
$gl_other_area = Areas::save( (int) r1_setup( 1 )['event'], 'R1 Fremd' );
$gl_wrong = Gl::arrive( $gl_entry->entry_id, 0, $gl_other_area );
check( 'A591: arrival through an area of another event is refused, code unchanged', is_wp_error( $gl_wrong ) && $gl_hash === (string) r1_ticket( $gl_tid )->token_hash && 'valid' === r1_ticket( $gl_tid )->status );
$gl_ok = Gl::arrive( $gl_entry->entry_id );
check( 'A591: arrival checks the ticket in by id without a new code', is_array( $gl_ok ) && 1 === $gl_ok['checked_in'] && 'checked_in' === r1_ticket( $gl_tid )->status && $gl_hash === (string) r1_ticket( $gl_tid )->token_hash && 'guestlist' === $wpdb->get_var( "SELECT source FROM {$p}wpcap_checkins WHERE ticket_id=$gl_tid AND result='checked_in'" ) );
check( 'A591: perform() never accepts an internal ticket reference from a context', 'unknown' === adm_r( Checkin::perform( '', array( 'ticket_ref' => $gl_tid, 'idempotency_key' => 'r1-ref' ) ) ) );

echo "A593: buyer self-service rotations during admission\n";
$s = adm_setup( 'UTC', null, null, 2 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
$t0 = r1_tid( $s['codes'][0] );
$t1 = r1_tid( $s['codes'][1] );
$order_id = (int) r1_ticket( $t0 )->order_id;
$order_pub = Orders::get( $order_id )->public_id;
$access = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $access ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'order_id' => $order_id ) );
check( 'A593: a week before the event self-service rotations are allowed', '' === CodeRotationLock::reason( $t0 ) && true === Orders::resend_tickets( $order_id, array( 'self_service' => true, 'skip_transferred' => true ) ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'occurrence_id' => $s['occ'] ) );
$h0 = (string) r1_ticket( $t0 )->token_hash;
$h1 = (string) r1_ticket( $t1 )->token_hash;
$resend = Orders::resend_tickets( $order_id, array( 'self_service' => true, 'skip_transferred' => true ) );
$transfer = Orders::transfer_ticket( $order_pub, $access, r1_ticket( $t0 )->public_id, 'r1-neu@example.com' );
$assign = PassCaptain\TicketAssignments::assign( $order_pub, $access, r1_ticket( $t1 )->public_id, 'R1 Neu', 'r1-assign@example.com' );
$pdf = PassCaptain\PortalSelfService::pdf( $order_pub, $access, r1_ticket( $t0 )->public_id );
check( 'A593: from the start of admission (start minus entry_opens_before_minutes) the buyer cannot rotate codes', 'admission_open' === CodeRotationLock::reason( $t0 ) && 'wpcap_ticket_rotation_locked' === r1_err( $resend ) && 'wpcap_ticket_rotation_locked' === r1_err( $transfer ) && 'wpcap_ticket_rotation_locked' === r1_err( $assign ) && 'wpcap_ticket_rotation_locked' === r1_err( $pdf ) && $h0 === (string) r1_ticket( $t0 )->token_hash && $h1 === (string) r1_ticket( $t1 )->token_hash );
check( 'A593: staff resend is not limited by the lock', true === Orders::resend_tickets( $order_id ) && $h0 !== (string) r1_ticket( $t0 )->token_hash );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) ), array( 'occurrence_id' => $s['occ'] ) );
$wpdb->insert( "{$p}wpcap_offline_entries", array( 'occurrence_id' => $s['occ'], 'token_hash' => (string) r1_ticket( $t1 )->token_hash, 'ticket_id' => $t1, 'ticket_type_id' => $s['type'], 'status' => 'valid', 'attendee_name' => '', 'fingerprint' => md5( 'r1' ), 'version' => 1 ) );
$wpdb->insert( "{$p}wpcap_offline_assignments", array( 'device_id' => 4243, 'event_id' => $s['event'], 'occurrence_id' => 0, 'assigned_by' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'last_package_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
$h1 = (string) r1_ticket( $t1 )->token_hash;
$transfer_pkg = Orders::transfer_ticket( $order_pub, $access, r1_ticket( $t1 )->public_id, 'r1-neu2@example.com' );
check( 'A593: a code shipped in a recent offline package is not rotated by the buyer', 'offline_package' === CodeRotationLock::reason( $t1 ) && '' === CodeRotationLock::reason( $t0 ) && 'wpcap_ticket_rotation_locked' === r1_err( $transfer_pkg ) && $h1 === (string) r1_ticket( $t1 )->token_hash );
$wpdb->update( "{$p}wpcap_offline_assignments", array( 'last_package_at' => gmdate( 'Y-m-d H:i:s', time() - 80 * 3600 ) ), array( 'device_id' => 4243, 'event_id' => $s['event'] ) );
check( 'A593: an offline package older than any offline duration no longer locks', '' === CodeRotationLock::reason( $t1 ) );

echo "A345: retryable server errors in offline uploads\n";
$s = r1_setup( 1 );
$GLOBALS['wpcap_test_user_id'] = 7;
$pair = Devices::create_pairing( array( $s['event'] ), 'R1 Sync', 2 );
$GLOBALS['wpcap_test_user_id'] = 0;
$sync_dev = Devices::pair( $pair['code'], 'R1 Sync', 'android', '1.1' );
$scan = array( 'code' => $s['codes'][0], 'idempotency_key' => 'r1-sync-1', 'scanned_at' => gmdate( 'c', time() - 120 ) );
$wpdb->query( "RENAME TABLE {$p}wpcap_admission_entries TO {$p}wpcap_admission_entries_r1off" );
$broken = app_call( 'sync', 'POST', $sync_dev['token'], array( 'scans' => array( $scan ) ) );
$wpdb->query( "RENAME TABLE {$p}wpcap_admission_entries_r1off TO {$p}wpcap_admission_entries" );
$GLOBALS['wpcap_test_user_id'] = 1;
$s_tid = r1_tid( $s['codes'][0] );
check( 'A345: a scan the server could not book comes back retryable (not synchronised, no conflict)', true === ( $broken['results'][0]['retryable'] ?? null ) && false === $broken['results'][0]['ok'] && 'retry' === $broken['results'][0]['result'] && false === $broken['results'][0]['conflict'] && 'valid' === r1_ticket( $s_tid )->status && ! r1_conflicts( $s_tid ) );
$GLOBALS['wpcap_test_user_id'] = 0;
$retried = app_call( 'sync', 'POST', $sync_dev['token'], array( 'scans' => array( $scan ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A345: the repeated upload books the admission', true === ( $retried['results'][0]['ok'] ?? null ) && 'checked_in' === r1_ticket( $s_tid )->status );
$s = r1_setup( 1 );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'scans', array( array( 'token' => $s['codes'][0], 'idempotency_key' => 'r1-web-retry', 'scanned_at' => gmdate( 'c', time() - 60 ) ) ) );
$wpdb->query( "RENAME TABLE {$p}wpcap_admission_entries TO {$p}wpcap_admission_entries_r1off" );
$web_broken = r1_data( AdmissionRest::offline_sync( $req ) );
$legacy_broken = r1_data( Controller::offline_sync( r1_json_request( array( 'scans' => array( array( 'token' => $s['codes'][0], 'idempotency_key' => 'r1-legacy-retry' ) ) ) ) ) );
$wpdb->query( "RENAME TABLE {$p}wpcap_admission_entries_r1off TO {$p}wpcap_admission_entries" );
check( 'A345: web scanner offline sync (both routes) marks such scans retryable', true === ( $web_broken['results'][0]['retryable'] ?? null ) && true === ( $legacy_broken['results'][0]['retryable'] ?? null ) && 'valid' === r1_ticket( r1_tid( $s['codes'][0] ) )->status );

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
PassCaptain\Settings::update( $r1_settings );
