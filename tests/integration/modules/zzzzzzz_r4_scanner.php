<?php
/**
 * R4 (audit round 2, 2026-11-15, experts #6 and #3): admission & scanner.
 * - Idempotency keys are claimed atomically under the ticket lock (parallel retries count once,
 *   replays prefer the admission).
 * - Mobile API: database retries are HTTP 503 (apps fall back offline); undo needs a key.
 * - Undo window/roles and idempotent undo; "Undos pro Ticket" and cross-device double admissions
 *   in the conflict report; first_sync_wins closes only same-device/same-user duplicates.
 * - Backdated offline uploads are reviewed without a policy too; web uploads count for at most 1 h.
 * - Offline packages: compact format 2, per-type counters, gzip.
 * The PWA and web scanner parts are covered in tests/js.
 */
// phpcs:ignoreFile
use PassCaptain\Admission\Conflicts;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Checkin;
use PassCaptain\OfflineScanner\Package;
use PassCaptain\OfflineScanner\Rules;
use PassCaptain\OfflineScanner\Sync;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'], $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] );
PassCaptain\Security\Policy::register(); // undo_window_minutes / undo_roles are Policy settings
Settings::update( array( 'default_entry_window' => 0, 'scanner_show_attendee' => 0 ) );

/** Ticket with a known code in a paid order (status valid). */
function r4_ticket( $type, $code = null ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$code = $code ?: bin2hex( random_bytes( 32 ) );
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => str_replace( '-', '', wp_generate_uuid4() ), 'customer_email' => 'r4@example.com', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'EUR', 'total_minor' => 0, 'price_snapshot' => '[]', 'idempotency_key' => hash( 'sha256', uniqid( 'r4', true ) ), 'created_at' => $now, 'updated_at' => $now ) );
	$order = (int) $wpdb->insert_id;
	if ( ! $order || '' !== (string) $wpdb->last_error ) { echo '    (r4 order failed: ' . $wpdb->last_error . ")\n"; }
	$wpdb->insert( "{$p}wpcap_tickets", array( 'order_id' => $order, 'ticket_type_id' => (int) $type, 'public_id' => str_replace( '-', '', wp_generate_uuid4() ), 'token_hash' => hash( 'sha256', $code ), 'status' => 'valid', 'attendee_email' => 'r4@example.com', 'issued_at' => $now ) );
	return array( 'code' => $code, 'ticket_id' => (int) $wpdb->insert_id );
}
function r4_type_occ( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $type ) );
}
function r4_type_event( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $type ) );
}
function r4_count( $ticket_id, $result ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND result=%s', $wpdb->prefix . 'wpcap_checkins', $ticket_id, $result ) );
}
function r4_entries( $ticket_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ticket_id=%d AND direction='in' AND undone=0", $wpdb->prefix . 'wpcap_admission_entries', $ticket_id ) );
}
$r4_spawn = static function ( array $commands ) {
	$procs = array();
	foreach ( $commands as $cmd ) { $procs[] = popen( $cmd, 'r' ); }
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	return $out;
};
$r4_worker = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/r4_worker.php' );

/* ------------------------------------------------------------------------------------------ */
echo "R4: schema\n";
$r4_claim_idx = array_map( static fn( $r ) => $r->Key_name . ':' . $r->Column_name, (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_checkin_claims" ) );
check( 'wpcap_checkin_claims with primary key (user_id,key_hash,ticket_id), DB version >= 3.17.3', in_array( 'PRIMARY:user_id', $r4_claim_idx, true ) && in_array( 'PRIMARY:key_hash', $r4_claim_idx, true ) && in_array( 'PRIMARY:ticket_id', $r4_claim_idx, true ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.17.3', '>=' ) );

/* ------------------------------------------------------------------------------------------ */
echo "R4: idempotency keys are atomic (parallel processes, same key)\n";
$r4_multi_type = make_type( 50, 10, 0 );
Policies::save( $r4_multi_type, array( 'policy' => 'multi_entry', 'max_entries' => 5 ) );
$r4_m = r4_ticket( $r4_multi_type );
$r4_start = microtime( true ) + 1.5;
$r4_cmds = array();
for ( $i = 0; $i < 6; $i++ ) { $r4_cmds[] = $r4_worker . ' checkin ' . $r4_start . ' ' . $r4_m['code'] . ' r4-multi-key app'; }
$r4_out = $r4_spawn( $r4_cmds );
check( 'multi_entry(5): 6 parallel requests with one key count exactly 1 entry, all answered OK [' . implode( ',', $r4_out ) . ']', 6 === count( array_filter( $r4_out, static fn( $o ) => 0 === strpos( $o, 'OK' ) ) ) && 1 === r4_entries( $r4_m['ticket_id'] ) && 1 === r4_count( $r4_m['ticket_id'], 'checked_in' ) );
check( 'the key is claimed once (claims table)', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_id=%d', $p . 'wpcap_checkin_claims', $r4_m['ticket_id'] ) ) );

$r4_single_type = make_type( 50, 10, 0 );
$r4_s = r4_ticket( $r4_single_type );
$r4_start = microtime( true ) + 1.5;
$r4_cmds = array();
for ( $i = 0; $i < 6; $i++ ) { $r4_cmds[] = $r4_worker . ' checkin ' . $r4_start . ' ' . $r4_s['code'] . ' r4-single-key app'; }
$r4_out = $r4_spawn( $r4_cmds );
check( 'single ticket: a parallel retry with the same key gets the original OK, never "already used" [' . implode( ',', $r4_out ) . ']', 6 === count( array_filter( $r4_out, static fn( $o ) => 0 === strpos( $o, 'OK' ) ) ) && 1 === r4_count( $r4_s['ticket_id'], 'checked_in' ) && 0 === r4_count( $r4_s['ticket_id'], 'already_checked_in' ) );

$r4_o = r4_ticket( $r4_single_type );
$r4_start = microtime( true ) + 1.5;
$r4_cmds = array();
for ( $i = 0; $i < 4; $i++ ) { $r4_cmds[] = $r4_worker . ' checkin ' . $r4_start . ' ' . $r4_o['code'] . ' r4-offline-key offline'; }
$r4_out = $r4_spawn( $r4_cmds );
$r4_conf = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_id=%d', $p . 'wpcap_admission_conflicts', $r4_o['ticket_id'] ) );
check( 'offline upload repeated in parallel: one admission, no false conflict [' . implode( ',', $r4_out ) . ']', 4 === count( array_filter( $r4_out, static fn( $o ) => 0 === strpos( $o, 'OK' ) ) ) && 1 === r4_count( $r4_o['ticket_id'], 'checked_in' ) && 0 === $r4_conf );

// Different keys stay independent: one admission, the others are refused.
$r4_d = r4_ticket( $r4_single_type );
$r4_start = microtime( true ) + 1.5;
$r4_cmds = array();
for ( $i = 0; $i < 4; $i++ ) { $r4_cmds[] = $r4_worker . ' checkin ' . $r4_start . ' ' . $r4_d['code'] . ' r4-diff-' . $i . ' app'; }
$r4_out = $r4_spawn( $r4_cmds );
check( 'different keys: exactly one admission, three "already used" [' . implode( ',', $r4_out ) . ']', 1 === count( array_filter( $r4_out, static fn( $o ) => 'OK' === $o ) ) && 3 === count( array_filter( $r4_out, static fn( $o ) => 'ERR:already_checked_in' === $o ) ) );

// Replays prefer the admission over later rejections logged under the same key (rows of the old race).
$r4_r = r4_ticket( $r4_single_type );
$r4_first = Checkin::perform( $r4_r['code'], array( 'source' => 'app', 'idempotency_key' => 'r4-replay' ) );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $r4_r['ticket_id'], 'event_id' => r4_type_event( $r4_single_type ), 'user_id' => 1, 'result' => 'already_checked_in', 'idempotency_key' => hash( 'sha256', 'r4-replay' ), 'source' => 'app', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$r4_again = Checkin::perform( $r4_r['code'], array( 'source' => 'app', 'idempotency_key' => 'r4-replay' ) );
check( 'replay answers with the admission although a newer rejection carries the same key', is_array( $r4_first ) && is_array( $r4_again ) && ! empty( $r4_again['replayed'] ) && 'checked_in' === $r4_again['status'] );

$wpdb->insert( "{$p}wpcap_checkin_claims", array( 'user_id' => 1, 'key_hash' => hash( 'sha256', 'r4-old' ), 'ticket_id' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ) );
$r4_step = new ReflectionMethod( PassCaptain\Privacy\Retention::class, 'step_checkin_claims' );
$r4_step->setAccessible( true );
$r4_purged = (int) $r4_step->invoke( null, time(), microtime( true ) + 10 );
check( 'old claims are purged after 7 days (retention step checkin_claims), fresh ones stay', $r4_purged >= 1 && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE key_hash=%s', $p . 'wpcap_checkin_claims', hash( 'sha256', 'r4-old' ) ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ticket_id=%d', $p . 'wpcap_checkin_claims', $r4_m['ticket_id'] ) ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Privacy/Retention.php' ), "'rate_limits', 'checkin_claims' )" ) );

/* ------------------------------------------------------------------------------------------ */
echo "R4: mobile API – database retry is HTTP 503, undo needs a key\n";
$r4_scan_response = new ReflectionMethod( PassCaptain\Mobile\Api::class, 'scan_response' );
$r4_scan_response->setAccessible( true );
$r4_retry = $r4_scan_response->invoke( null, new WP_Error( 'wpcap_checkin_retry', 'retry', array( 'status' => 503, 'retryable' => true, 'result' => 'retry' ) ) );
check( 'retryable scan outcome becomes an HTTP 503 error (apps fall back offline), not 200 ok:false', is_wp_error( $r4_retry ) && 503 === $r4_retry->get_error_data()['status'] && 'retry' === $r4_retry->get_error_data()['result'] );
$r4_used = $r4_scan_response->invoke( null, new WP_Error( 'wpcap_ticket_not_valid', 'used', array( 'status' => 409, 'result' => 'already_checked_in' ) ) );
check( 'a normal rejection stays HTTP 200 ok:false', $r4_used instanceof WP_REST_Response && false === $r4_used->get_data()['ok'] );
$r4_undo_req = new WP_REST_Request( 'POST' );
$r4_undo_req->set_param( 'code', $r4_s['code'] );
$r4_undo = PassCaptain\Mobile\Api::undo( $r4_undo_req );
check( '/app/scan/undo without idempotency_key: 400', is_wp_error( $r4_undo ) && 'wpcap_idempotency_required' === $r4_undo->get_error_code() && 400 === $r4_undo->get_error_data()['status'] );

/* ------------------------------------------------------------------------------------------ */
echo "R4: undo window, roles and idempotency\n";
// Idempotent undo (admin): a retried request removes one entry only.
$r4_u = r4_ticket( $r4_multi_type );
Checkin::perform( $r4_u['code'], array( 'source' => 'web', 'idempotency_key' => 'r4-u-1' ) );
Checkin::perform( $r4_u['code'], array( 'source' => 'web', 'idempotency_key' => 'r4-u-2' ) );
$r4_found = Checkin::lookup( $r4_u['code'] );
$r4_undo1 = Engine::undo_last( $r4_found, 'test', array( 'idempotency_key' => 'r4-undo-a' ) );
$r4_undo2 = Engine::undo_last( $r4_found, 'test', array( 'idempotency_key' => 'r4-undo-a' ) );
check( 'multi-entry undo with the same key twice: one entry removed, the retry is a replay', is_array( $r4_undo1 ) && empty( $r4_undo1['replayed'] ) && ! empty( $r4_undo2['replayed'] ) && 1 === r4_entries( $r4_u['ticket_id'] ) && 1 === r4_count( $r4_u['ticket_id'], 'undone' ) );
$r4_single_u = r4_ticket( $r4_single_type );
Checkin::perform( $r4_single_u['code'], array( 'source' => 'web', 'idempotency_key' => 'r4-su' ) );
$r4_tu1 = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_single_u['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-tu' ) );
$r4_tu2 = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_single_u['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-tu' ) );
check( 'ticket screen undo with the same key twice: replayed instead of "not checked in"', is_array( $r4_tu1 ) && 'valid' === $r4_tu1['status'] && is_array( $r4_tu2 ) && ! empty( $r4_tu2['replayed'] ) && 1 === r4_count( $r4_single_u['ticket_id'], 'undone' ) );
$r4_tp_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/TicketsPage.php' );
check( 'the undo form carries its own idempotency key and the handler requires one', false !== strpos( $r4_tp_src, "Forms::hidden( 'idempotency_key', wp_generate_uuid4() )" ) && false !== strpos( $r4_tp_src, "'wpcap_idempotency_required'" ) );

// Plain check-in staff (user 41): no manage_orders, assigned to the event for wpcap_checkin only.
$r4_event = r4_type_event( $r4_single_type );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $r4_event, 'user_id' => 41, 'capability' => 'wpcap_checkin' ) );
$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => 'manage_options' !== $c || 1 === (int) $u;
$r4_staff = static function () { $GLOBALS['wpcap_test_user_id'] = 41; $GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_orders' ); };
$r4_admin = static function () { $GLOBALS['wpcap_test_user_id'] = 1; $GLOBALS['wpcap_test_denied_caps'] = array(); };
$r4_own = r4_ticket( $r4_single_type );
$r4_other = r4_ticket( $r4_single_type );
$r4_staff();
$r4_own_in = Checkin::perform( $r4_own['code'], array( 'source' => 'web', 'idempotency_key' => 'r4-own' ) );
$r4_admin();
Checkin::perform( $r4_other['code'], array( 'source' => 'web', 'idempotency_key' => 'r4-other' ) );
$r4_staff();
$r4_own_undo = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_own['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-own-undo' ) );
$r4_other_undo = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_other['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-other-undo' ) );
check( 'check-in staff may undo an own scan within the window (default 5 min)', is_array( $r4_own_in ) && is_array( $r4_own_undo ) && 'valid' === $r4_own_undo['status'] );
check( 'check-in staff may not undo a scan of someone else', is_wp_error( $r4_other_undo ) && 'wpcap_undo_forbidden' === $r4_other_undo->get_error_code() && 403 === $r4_other_undo->get_error_data()['status'] && 'checked_in' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE ticket_id=%d', $p . 'wpcap_tickets', $r4_other['ticket_id'] ) ) );
Checkin::perform( $r4_own['code'], array( 'source' => 'web', 'idempotency_key' => 'r4-own-2' ) );
$wpdb->query( $wpdb->prepare( "UPDATE %i SET created_at=%s WHERE ticket_id=%d AND result='checked_in'", $p . 'wpcap_checkins', gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ), $r4_own['ticket_id'] ) );
$r4_late = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_own['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-own-late' ) );
check( 'an own scan older than the window cannot be undone by staff', is_wp_error( $r4_late ) && 'wpcap_undo_forbidden' === $r4_late->get_error_code() );
Settings::update( array( 'undo_window_minutes' => 30 ) );
$r4_wide = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_own['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-own-wide' ) );
check( 'setting undo_window_minutes widens the window (default 5, Policy setting)', is_array( $r4_wide ) && 5 === PassCaptain\Security\Policy::DEFAULTS['undo_window_minutes'] && '' === PassCaptain\Security\Policy::DEFAULTS['undo_roles'] );
Settings::update( array( 'undo_window_minutes' => 5, 'undo_roles' => 'wpcap_event_manager' ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] = array( 'wpcap_event_manager' );
$r4_role = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_other['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-role' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_user_roles'] );
check( 'a role from undo_roles may undo any admission of its events', is_array( $r4_role ) && 'valid' === $r4_role['status'] );
// Same device (app) counts as own scan for another account.
$r4_dev = r4_ticket( $r4_single_type );
$r4_admin();
Checkin::perform( $r4_dev['code'], array( 'source' => 'app', 'idempotency_key' => 'r4-dev', 'device_id' => 77 ) );
$r4_staff();
$r4_dev_other = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_dev['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-dev-x', 'device_id' => 78 ) );
$r4_dev_same = TicketsPage::undo_checkin( array( 'ticket_id' => $r4_dev['ticket_id'], 'reason' => 'Fehlscan', 'idempotency_key' => 'r4-dev-y', 'device_id' => 77 ) );
check( 'staff undo on the device that scanned: allowed; on another device: refused', is_wp_error( $r4_dev_other ) && is_array( $r4_dev_same ) );
Settings::update( array( 'undo_roles' => '' ) );
$r4_admin();
unset( $GLOBALS['wpcap_test_user_can'] );

/* ------------------------------------------------------------------------------------------ */
echo "R4: first_sync_wins closes only same-device duplicates; conflict report\n";
$r4_off_type = make_type( 50, 10, 0 );
$r4_off_event = r4_type_event( $r4_off_type );
Rules::save( $r4_off_event, array( 'enabled' => 1, 'max_offline_hours' => 12, 'duplicate_policy' => 'first_sync_wins' ) );
$r4_dev_row = (object) array( 'device_id' => 12, 'public_id' => str_repeat( 'b', 32 ) );
$r4_cross = r4_ticket( $r4_off_type );
$r4_same = r4_ticket( $r4_off_type );
Checkin::perform( $r4_cross['code'], array( 'source' => 'offline', 'idempotency_key' => 'r4-c1', 'device_id' => 11 ) );
Checkin::perform( $r4_same['code'], array( 'source' => 'offline', 'idempotency_key' => 'r4-s1', 'device_id' => 12 ) );
$r4_c2 = Checkin::perform( $r4_cross['code'], array( 'source' => 'offline', 'idempotency_key' => 'r4-c2', 'device_id' => 12 ) );
$r4_s2 = Checkin::perform( $r4_same['code'], array( 'source' => 'offline', 'idempotency_key' => 'r4-s2', 'device_id' => 12 ) );
$r4_rows = array();
foreach ( array( $r4_c2, $r4_s2 ) as $r4_x ) {
	$r4_rows[] = array( 'ok' => false, 'result' => 'already_checked_in', 'conflict' => true, 'double_admission' => true, 'conflict_id' => (int) $r4_x->get_error_data()['conflict_id'] );
}
$r4_applied = Sync::apply( $r4_rows, array( array( 'scanned_at' => gmdate( 'c' ) ), array( 'scanned_at' => gmdate( 'c' ) ) ), $r4_dev_row );
check( 'first_sync_wins: the same code admitted on another device stays open (cross_device)', 'open' === $r4_applied[0]['conflict_status'] && true === $r4_applied[0]['cross_device'] && false === $r4_applied[0]['auto_resolved'] );
check( 'first_sync_wins: a double scan on the same device is closed automatically', 'resolved' === $r4_applied[1]['conflict_status'] && true === $r4_applied[1]['auto_resolved'] && false === $r4_applied[1]['cross_device'] );
check( 'resolution "duplicate_admission" is offered', array_key_exists( 'duplicate_admission', Conflicts::resolutions() ) );
$r4_resolved = Conflicts::resolve( $r4_rows[0]['conflict_id'], 'duplicate_admission', '' );
$r4_report = Conflicts::report( null, $r4_off_event );
check( 'report counts cross-device double admissions and confirmed ones', is_array( $r4_resolved ) && 1 === $r4_report['cross_device_total'] && 0 === $r4_report['cross_device_open'] && 1 === $r4_report['duplicate_admission'] );
$r4_report_all = Conflicts::report( null, 0, 50 );
$r4_undo_counts = array();
foreach ( $r4_report_all['undos'] as $r4_row ) { $r4_undo_counts[ (int) $r4_row->ticket_id ] = (int) $r4_row->undos; }
check( 'report lists undos per ticket', 1 === ( $r4_undo_counts[ $r4_u['ticket_id'] ] ?? 0 ) && ( $r4_undo_counts[ $r4_own['ticket_id'] ] ?? 0 ) >= 2 );
check( 'report respects the event scope', array() === Conflicts::report( array(), 0 )['undos'] && 0 === Conflicts::report( array(), 0 )['cross_device_total'] );

/* ------------------------------------------------------------------------------------------ */
echo "R4: backdated uploads without policy, web upload maximum age\n";
$r4_bd_type = make_type( 50, 10, 0 );
$r4_bd_occ = r4_type_occ( $r4_bd_type );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET starts_at=%s, ends_at=%s WHERE occurrence_id=%d', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ), $r4_bd_occ ) );
Settings::update( array( 'default_entry_window' => 1, 'entry_opens_before_minutes' => 60, 'entry_closes_after_minutes' => 0 ) );
$r4_bd = r4_ticket( $r4_bd_type );
$r4_bd_res = Checkin::perform( $r4_bd['code'], array( 'source' => 'offline', 'idempotency_key' => 'r4-bd', 'scanned_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ) );
check( 'ticket type without policy: offline scan claimed inside the window but uploaded after it is admitted and reviewed (backdated_admission)', is_array( $r4_bd_res ) && 'checked_in' === $r4_bd_res['status'] && 'backdated_admission' === ( $r4_bd_res['review'] ?? '' ) && ! empty( $r4_bd_res['conflict_id'] ) );
$r4_web = r4_ticket( $r4_bd_type );
$r4_req = new WP_REST_Request( 'POST' );
$r4_req->set_param( 'scans', array( array( 'token' => $r4_web['code'], 'idempotency_key' => 'r4-web-old', 'scanned_at' => gmdate( 'c', time() - 3 * HOUR_IN_SECONDS ) ) ) );
$r4_web_res = PassCaptain\Admission\Rest::offline_sync( $r4_req )->get_data()['results'][0];
check( 'web scanner upload older than 1 h is evaluated at upload time (outside the window: conflict)', 'conflict' === $r4_web_res['status'] && 'outside_entry_window' === $r4_web_res['result'] && 'valid' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE ticket_id=%d', $p . 'wpcap_tickets', $r4_web['ticket_id'] ) ) );
$r4_web2 = r4_ticket( $r4_bd_type );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET ends_at=%s WHERE occurrence_id=%d', $p . 'wpcap_occurrences', gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ), $r4_bd_occ ) );
$r4_req2 = new WP_REST_Request( 'POST' );
$r4_req2->set_param( 'scans', array( array( 'token' => $r4_web2['code'], 'idempotency_key' => 'r4-web-new', 'scanned_at' => gmdate( 'c', time() - 30 * MINUTE_IN_SECONDS ) ) ) );
$r4_web2_res = PassCaptain\Admission\Rest::offline_sync( $r4_req2 )->get_data()['results'][0];
check( 'web scanner upload within the hour keeps its device time (admitted, reviewed as backdated)', ! empty( $r4_web2_res['admitted'] ) && 'backdated_admission' === ( $r4_web2_res['review'] ?? '' ) && 'checked_in' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE ticket_id=%d', $p . 'wpcap_tickets', $r4_web2['ticket_id'] ) ) );
Settings::update( array( 'default_entry_window' => 0 ) );

/* ------------------------------------------------------------------------------------------ */
echo "R4: offline package format 2, counters, gzip\n";
if ( ! defined( 'WPCAP_OFFLINE_SIGNING_KEY' ) ) { define( 'WPCAP_OFFLINE_SIGNING_KEY', 'r4-signing-base' ); }
$r4_pk_type = make_type( 50, 10, 0 );
Policies::save( $r4_pk_type, array( 'policy' => 'daily' ) );
$r4_pk_type2 = $wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => r4_type_occ( $r4_pk_type ), 'name' => 'Zehnerkarte', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) ) ? (int) $wpdb->insert_id : 0;
Policies::save( $r4_pk_type2, array( 'policy' => 'multi_entry', 'max_entries' => 10 ) );
$r4_pk_event = r4_type_event( $r4_pk_type );
Rules::save( $r4_pk_event, array( 'enabled' => 1, 'max_offline_hours' => 12, 'duplicate_policy' => 'review' ) );
$r4_pk = array( r4_ticket( $r4_pk_type ), r4_ticket( $r4_pk_type ), r4_ticket( $r4_pk_type2 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $r4_pk[1]['ticket_id'] ) );
$r4_occ_row = $wpdb->get_row( $wpdb->prepare( 'SELECT o.*,e.name AS event_name,e.status AS event_status FROM %i o JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', r4_type_occ( $r4_pk_type ) ) );
$r4_device = (object) array( 'device_id' => 1, 'public_id' => str_repeat( 'c', 32 ) );
$GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = 0;
$r4_v1 = Package::build( $r4_device, $r4_occ_row, null );
$r4_v2 = Package::build( $r4_device, $r4_occ_row, null, 2 );
$r4_p1 = Package::verify( $r4_v1 );
$r4_p2 = Package::verify( $r4_v2 );
$r4_pre = static fn( $code ) => substr( hash( 'sha256', $code ), 0, 32 );
$r4_rows_by = array();
foreach ( (array) ( $r4_p2['rows'] ?? array() ) as $r4_row ) { $r4_rows_by[ $r4_row[0] . ':' . $r4_row[1] ] = $r4_row[2]; }
check( 'default format stays v1 with full hashes (existing clients)', is_array( $r4_p1 ) && 1 === $r4_p1['v'] && 3 === count( $r4_p1['tickets'] ) && ! isset( $r4_p1['rows'] ) );
check( 'format 2: signed compact rows of 32-hex prefixes per type and status digit', is_array( $r4_p2 ) && 2 === $r4_p2['v'] && 32 === $r4_p2['hash_prefix'] && ! isset( $r4_p2['tickets'] ) && 3 === $r4_v2['count'] && 2 === $r4_v2['format']
	&& $r4_pre( $r4_pk[0]['code'] ) === ( $r4_rows_by[ $r4_pk_type . ':1' ] ?? '' ) && $r4_pre( $r4_pk[1]['code'] ) === ( $r4_rows_by[ $r4_pk_type . ':2' ] ?? '' ) && $r4_pre( $r4_pk[2]['code'] ) === ( $r4_rows_by[ $r4_pk_type2 . ':1' ] ?? '' ) );
check( 'format 2 is smaller than format 1', strlen( $r4_v2['payload'] ) < strlen( $r4_v1['payload'] ) );
$r4_types = array();
foreach ( $r4_p2['ticket_types'] as $r4_tt ) { $r4_types[ $r4_tt['id'] ] = $r4_tt; }
check( 'ticket types carry policy, max_entries and valid_days; package carries the day cutoff hour', 'daily' === $r4_types[ $r4_pk_type ]['policy'] && is_array( $r4_types[ $r4_pk_type ]['valid_days'] ) && 1 <= count( $r4_types[ $r4_pk_type ]['valid_days'] ) && 10 === $r4_types[ $r4_pk_type2 ]['max_entries'] && null === $r4_types[ $r4_pk_type2 ]['valid_days'] && array_key_exists( 'day_cutoff_hour', $r4_p2 ) );
$r4_wpdb_rev = $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => $r4_pk[0]['ticket_id'] ) );
$r4_delta = Package::verify( Package::build( $r4_device, $r4_occ_row, $r4_v2['cursor'], 2 ) );
check( 'compact delta since the full cursor lists the revocation (status 0, type 0)', is_array( $r4_delta ) && 'delta' === $r4_delta['kind'] && array( array( 0, 0, $r4_pre( $r4_pk[0]['code'] ) ) ) === $r4_delta['rows'] );

// gzip on the wire.
$r4_req = new WP_REST_Request( 'GET', '/' . PassCaptain\Mobile\Api::NS . '/app/offline/package' );
$r4_req->set_header( 'Accept-Encoding', 'gzip, deflate, br' );
$r4_big = $r4_v1 + array( 'pad' => str_repeat( 'x', 4096 ) );
ob_start();
$r4_served = PassCaptain\OfflineScanner\Rest::serve_gzip( false, new WP_REST_Response( $r4_big ), $r4_req );
$r4_body = (string) ob_get_clean();
check( 'package response is sent gzip-compressed when the client accepts gzip (body inflates to the exact JSON)', true === $r4_served && json_encode( $r4_big ) === gzdecode( $r4_body ) && strlen( $r4_body ) < strlen( json_encode( $r4_big ) ) );
$r4_plain = new WP_REST_Request( 'GET', '/' . PassCaptain\Mobile\Api::NS . '/app/offline/package' );
ob_start();
$r4_served_plain = PassCaptain\OfflineScanner\Rest::serve_gzip( false, new WP_REST_Response( $r4_big ), $r4_plain );
$r4_none = (string) ob_get_clean();
$r4_q0 = new WP_REST_Request( 'GET', '/' . PassCaptain\Mobile\Api::NS . '/app/offline/package' );
$r4_q0->set_header( 'Accept-Encoding', 'gzip;q=0, br' );
$r4_other_route = new WP_REST_Request( 'GET', '/' . PassCaptain\Mobile\Api::NS . '/app/offline/keys' );
$r4_other_route->set_header( 'Accept-Encoding', 'gzip' );
check( 'no gzip without Accept-Encoding, with gzip;q=0 or for other routes', false === $r4_served_plain && '' === $r4_none && false === PassCaptain\OfflineScanner\Rest::serve_gzip( false, new WP_REST_Response( $r4_big ), $r4_q0 ) && false === PassCaptain\OfflineScanner\Rest::serve_gzip( false, new WP_REST_Response( $r4_big ), $r4_other_route ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] );
