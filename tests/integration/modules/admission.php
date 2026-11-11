<?php
/**
 * Admission module: policies, entry windows, check-out/presence, areas, conflicts, device status.
 * Included from run.php after mobile.php (uses check(), make_type(), ticket_code_for(), app_call()).
 */
// phpcs:ignoreFile
use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Conflicts;
use PassCaptain\Admission\ConflictsPage;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;
use PassCaptain\Admission\Rest;
use PassCaptain\Admission\RulesPage;
use PassCaptain\Checkin;
use PassCaptain\Mobile\Devices;
use PassCaptain\Orders;

PassCaptain\Modules\Admission::register();
global $wpdb;
$p = $wpdb->prefix;

function adm_ts( $local, $tz ) { return ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp(); }
function adm_now( $ts ) { if ( null === $ts ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] = (int) $ts; } }
/** Event with one occurrence (local times in $tz) and $qty free tickets. */
function adm_setup( $tz = 'Europe/Vienna', $start = null, $end = null, $qty = 1 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$type = make_type( 100, 50, 0 );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	$GLOBALS['wpcap_test_transients'] = array();
	$order = Orders::create( array( 'email' => 'adm' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'adm-' . uniqid( '', true ) ) );
	$codes = is_wp_error( $order ) ? array() : ticket_code_for( $order['order_id'] );
	$fields = array( 'timezone' => $tz );
	if ( $start ) { $fields['starts_at'] = gmdate( 'Y-m-d H:i:s', adm_ts( $start, $tz ) ); }
	if ( $end ) { $fields['ends_at'] = gmdate( 'Y-m-d H:i:s', adm_ts( $end, $tz ) ); }
	$wpdb->update( "{$p}wpcap_occurrences", $fields, array( 'occurrence_id' => $occ ) );
	return array( 'type' => $type, 'occ' => $occ, 'event' => $event, 'codes' => $codes );
}
function adm_r( $r ) { return is_wp_error( $r ) ? (string) ( $r->get_error_data()['result'] ?? $r->get_error_code() ) : (string) $r['status']; }
function adm_in( $code, $at = null, array $ctx = array() ) { adm_now( $at ); $r = Checkin::perform( $code, $ctx + array( 'idempotency_key' => 'adm-' . uniqid( '', true ) ) ); adm_now( null ); return adm_r( $r ); }
function adm_out( $code, $at = null, array $ctx = array() ) { adm_now( $at ); $r = Checkin::check_out( $code, $ctx + array( 'idempotency_key' => 'adm-' . uniqid( '', true ) ) ); adm_now( null ); return adm_r( $r ); }
function adm_ticket_status( $code ) { global $wpdb; return $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $code ) . "'" ); }

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "Admission: policy administration\n";
$s = adm_setup();
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'daily' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'saving a policy requires wpcap_manage_events', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] && null === Policies::for_type( $s['type'] ) );
$bad = array(
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'bogus' ) ),
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'multi_entry', 'max_entries' => '0' ) ),
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'multi_day', 'valid_dates' => '' ) ),
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'multi_day', 'valid_dates' => '2027-02-30' ) ),
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'daily', 'day_from' => '25:00' ) ),
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'daily', 'day_from' => '18:00', 'day_until' => '10:00' ) ),
	RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'single', 'opens_before_minutes' => 'abc' ) ),
);
check( 'invalid policies, dates, hours and windows are rejected', 7 === count( array_filter( $bad, 'is_wp_error' ) ) && null === Policies::for_type( $s['type'] ) );
$saved = RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'multi_day', 'valid_dates' => "2027-03-03, 2027-03-01..2027-03-02\n2027-03-01", 'opens_before_minutes' => '60', 'closes_after_minutes' => '' ) );
check( 'multi-day dates and ranges are expanded, sorted and deduplicated; window stored', ! is_wp_error( $saved ) && array( '2027-03-01', '2027-03-02', '2027-03-03' ) === $saved['policy']['valid_dates'] && 60 === $saved['policy']['opens_before_minutes'] && null === $saved['policy']['closes_after_minutes'] && $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='admission_policy_saved'" ) > 0 );
RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'default' ) );
check( 'policy "default" removes the explicit policy', null === Policies::for_type( $s['type'] ) );

echo "Admission: single entry and entry window\n";
$s = adm_setup( 'Europe/Vienna', '2027-03-01 20:00', '2027-03-01 23:00', 2 );
check( 'default (no policy) admits once', 'checked_in' === adm_in( $s['codes'][0], adm_ts( '2027-03-01 19:00', 'Europe/Vienna' ) ) && 'already_checked_in' === adm_in( $s['codes'][0], adm_ts( '2027-03-01 19:05', 'Europe/Vienna' ) ) && 'checked_in' === adm_ticket_status( $s['codes'][0] ) );
Policies::save( $s['type'], array( 'policy' => 'single', 'opens_before_minutes' => '60', 'closes_after_minutes' => '0' ) );
$early = adm_in( $s['codes'][1], adm_ts( '2027-03-01 18:59', 'Europe/Vienna' ) );
$late = adm_in( $s['codes'][1], adm_ts( '2027-03-01 23:01', 'Europe/Vienna' ) );
check( 'entry before the window (1 h before start) and after the end is refused', 'outside_entry_window' === $early && 'outside_entry_window' === $late && 'valid' === adm_ticket_status( $s['codes'][1] ) );
check( 'entry exactly at window opening is admitted', 'checked_in' === adm_in( $s['codes'][1], adm_ts( '2027-03-01 19:00', 'Europe/Vienna' ) ) );
check( 'refused scans are logged with their result code', 1 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='outside_entry_window' AND event_id=" . $s['event'] ) );

echo "Admission: daily entry with day boundaries in the occurrence timezone\n";
$la = 'America/Los_Angeles';
$s = adm_setup( $la, '2027-03-01 18:00', '2027-03-03 23:00' );
Policies::save( $s['type'], array( 'policy' => 'daily' ) );
$c = $s['codes'][0];
$d1 = adm_in( $c, adm_ts( '2027-03-01 23:30', $la ) ); // already 2 March in UTC
$d1b = adm_in( $c, adm_ts( '2027-03-01 23:50', $la ) );
$d2 = adm_in( $c, adm_ts( '2027-03-02 00:30', $la ) );
$d4 = adm_in( $c, adm_ts( '2027-03-04 10:00', $la ) );
check( 'daily: once per local day, new day starts at local midnight', 'checked_in' === $d1 && 'daily_limit_reached' === $d1b && 'checked_in' === $d2 );
check( 'daily: days outside the occurrence are not valid; ticket stays valid', 'not_valid_today' === $d4 && 'valid' === adm_ticket_status( $c ) );
$days = $wpdb->get_col( "SELECT service_date FROM {$p}wpcap_admission_entries WHERE ticket_id=(SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $c ) . "') AND direction='in' ORDER BY entry_id" );
check( 'entries are stored per local service date', array( '2027-03-01', '2027-03-02' ) === $days );
$s = adm_setup( $la, '2027-03-01 18:00', '2027-03-03 23:00' );
Policies::save( $s['type'], array( 'policy' => 'daily' ) );
$wpdb->insert( "{$p}wpcap_occurrence_days", array( 'occurrence_id' => $s['occ'], 'service_date' => '2027-03-02', 'admission_policy' => 'daily', 'max_entries' => 1 ) );
check( 'daily: configured occurrence days restrict the valid days', 'not_valid_today' === adm_in( $s['codes'][0], adm_ts( '2027-03-01 20:00', $la ) ) && 'checked_in' === adm_in( $s['codes'][0], adm_ts( '2027-03-02 20:00', $la ) ) );
$s = adm_setup( $la, '2027-03-01 18:00', '2027-03-03 23:00' );
Policies::save( $s['type'], array( 'policy' => 'daily', 'day_from' => '10:00', 'day_until' => '22:00' ) );
check( 'daily entry hours (local time) are enforced', 'outside_entry_window' === adm_in( $s['codes'][0], adm_ts( '2027-03-02 09:59', $la ) ) && 'outside_entry_window' === adm_in( $s['codes'][0], adm_ts( '2027-03-02 22:00', $la ) ) && 'checked_in' === adm_in( $s['codes'][0], adm_ts( '2027-03-02 10:00', $la ) ) );

echo "Admission: multi-day ticket\n";
$s = adm_setup( $la, '2027-03-01 18:00', '2027-03-01 23:00' );
Policies::save( $s['type'], array( 'policy' => 'multi_day', 'valid_dates' => '2027-03-01, 2027-03-03' ) );
$c = $s['codes'][0];
check( 'multi-day: valid on chosen local dates, once per day', 'checked_in' === adm_in( $c, adm_ts( '2027-03-01 23:30', $la ) ) && 'daily_limit_reached' === adm_in( $c, adm_ts( '2027-03-01 23:45', $la ) ) );
check( 'multi-day: a day in between is refused even when UTC date matches a valid date', 'not_valid_today' === adm_in( $c, adm_ts( '2027-03-02 00:30', $la ) ) );
check( 'multi-day: later valid date beyond the occurrence end is accepted', 'checked_in' === adm_in( $c, adm_ts( '2027-03-03 12:00', $la ) ) );
adm_now( adm_ts( '2027-03-02 12:00', $la ) );
$preview = Checkin::preview( $c );
adm_now( null );
check( 'preview reports the policy result', ! is_wp_error( $preview ) && 'not_valid_today' === $preview['status'] );

echo "Admission: multiple entries\n";
$s = adm_setup( 'Europe/Vienna', '2027-03-01 10:00', '2027-03-10 18:00' );
Policies::save( $s['type'], array( 'policy' => 'multi_entry', 'max_entries' => 3 ) );
$c = $s['codes'][0];
$at = adm_ts( '2027-03-02 12:00', 'Europe/Vienna' );
$r = array( adm_in( $c, $at ), adm_in( $c, $at + 60 ), adm_in( $c, $at + 120 ), adm_in( $c, $at + 180 ) );
check( 'multi-entry: N entries, then entry_limit_reached', array( 'checked_in', 'checked_in', 'checked_in', 'entry_limit_reached' ) === $r && 'valid' === adm_ticket_status( $c ) );

echo "Admission: re-entry cycle and check-out\n";
$s = adm_setup( 'Europe/Vienna', '2027-03-01 10:00', '2027-03-01 23:00' );
Policies::save( $s['type'], array( 'policy' => 'reentry', 'max_entries' => 2 ) );
$c = $s['codes'][0];
$at = adm_ts( '2027-03-01 12:00', 'Europe/Vienna' );
$cycle = array( adm_out( $c, $at ), adm_in( $c, $at + 10 ), adm_in( $c, $at + 20 ), adm_out( $c, $at + 30 ), adm_out( $c, $at + 40 ), adm_in( $c, $at + 50 ), adm_out( $c, $at + 60 ), adm_in( $c, $at + 70 ) );
check( 're-entry: in → (in refused) → out → (out refused) → in → out → limit', array( 'not_checked_in', 'checked_in', 'already_inside', 'checked_out', 'not_checked_in', 'checked_in', 'checked_out', 'entry_limit_reached' ) === $cycle );
check( 'check-outs are logged separately as checked_out', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE result='checked_out' AND event_id=" . $s['event'] ) );
$s = adm_setup( 'Europe/Vienna', null, null, 3 );
$at = time();
adm_in( $s['codes'][0], $at );
adm_in( $s['codes'][1], $at );
$pres = Engine::presence( $s['occ'] );
adm_out( $s['codes'][0], $at );
$pres2 = Engine::presence( $s['occ'] );
check( 'presence counts check-ins minus check-outs per occurrence', 2 === $pres['present'] && 1 === $pres2['present'] );
check( 'single-entry ticket cannot re-enter after check-out', 'already_checked_in' === adm_in( $s['codes'][0], $at ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
$no_cap = adm_out( $s['codes'][1] );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'check-out requires wpcap_checkin with event scope', 'forbidden' === $no_cap && 1 === Engine::presence( $s['occ'] )['present'] );
$tid = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $s['codes'][1] ) . "'" );
PassCaptain\Admin\TicketsPage::undo_checkin( array( 'ticket_id' => $tid, 'reason' => 'test' ) );
check( 'undo in the ticket admin is reconciled: presence drops, check-out refused', 0 === Engine::presence( $s['occ'] )['present'] && 'not_checked_in' === adm_out( $s['codes'][1] ) && 'checked_in' === adm_in( $s['codes'][1] ) );
$replay_key = 'adm-replay-' . uniqid();
$o1 = Checkin::check_out( $s['codes'][1], array( 'idempotency_key' => $replay_key ) );
$o2 = Checkin::check_out( $s['codes'][1], array( 'idempotency_key' => $replay_key ) );
check( 'check-out is idempotent per key', 'checked_out' === adm_r( $o1 ) && ! empty( $o2['replayed'] ) );

echo "Admission: parallel check-ins of the same ticket\n";
function adm_parallel( $code, $n ) {
	$start = microtime( true ) + 0.6;
	$procs = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$procs[] = popen( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/admission/child.php' ) . ' ' . escapeshellarg( $code ) . ' ' . escapeshellarg( (string) $start ), 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	return $out;
}
$s = adm_setup( 'Europe/Vienna', null, null, 2 );
$par = adm_parallel( $s['codes'][0], 6 );
check( 'single entry: exactly one of 6 concurrent scans admits (' . implode( ',', $par ) . ')', 1 === count( array_keys( $par, 'OK', true ) ) && 5 === count( array_keys( $par, 'ERR:already_checked_in', true ) ) );
$s2 = adm_setup( 'UTC' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ) ), array( 'occurrence_id' => $s2['occ'] ) );
Policies::save( $s2['type'], array( 'policy' => 'multi_entry', 'max_entries' => 2 ) );
$par = adm_parallel( $s2['codes'][0], 6 );
check( 'multi-entry (2): exactly two of 6 concurrent scans admit (' . implode( ',', $par ) . ')', 2 === count( array_keys( $par, 'OK', true ) ) && 4 === count( array_keys( $par, 'ERR:entry_limit_reached', true ) ) && 2 === Engine::entry_count( (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $s2['codes'][0] ) . "'" ) ) );

echo "Admission: entrance areas (web)\n";
$s = adm_setup( 'Europe/Vienna', null, null, 4 );
$other = adm_setup();
$nord = RulesPage::save_area( array( 'event_id' => $s['event'], 'name' => 'Eingang Nord' ) )['area_id'];
$vip = RulesPage::save_area( array( 'event_id' => $s['event'], 'name' => 'VIP' ) )['area_id'];
$foreign_area = RulesPage::save_area( array( 'event_id' => $other['event'], 'name' => 'Fremd' ) )['area_id'];
check( 'areas need a name', is_wp_error( RulesPage::save_area( array( 'event_id' => $s['event'], 'name' => '' ) ) ) );
RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'default', 'area_ids' => array( $vip, $foreign_area ) ) );
check( 'type restriction keeps only areas of the same event', array( $vip ) === Areas::type_area_ids( $s['type'] ) );
check( 'restricted type: wrong area and no area are refused, VIP admits', 'wrong_area' === adm_in( $s['codes'][0], null, array( 'area_id' => $nord ) ) && 'wrong_area' === adm_in( $s['codes'][0] ) && 'wrong_area' === adm_in( $s['codes'][0], null, array( 'area_id' => $foreign_area ) ) && 'checked_in' === adm_in( $s['codes'][0], null, array( 'area_id' => $vip ) ) );
RulesPage::set_area_status( array( 'area_id' => $vip, 'status' => 'archived' ) );
check( 'archived area is refused', 'wrong_area' === adm_in( $s['codes'][1], null, array( 'area_id' => $vip ) ) );
RulesPage::set_area_status( array( 'area_id' => $vip, 'status' => 'active' ) );
RulesPage::save_policy( array( 'ticket_type_id' => $s['type'], 'policy' => 'default', 'area_ids' => array() ) );
$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 8, 'nordstaff', 'nord@example.com' );
$added = RulesPage::add_staff( array( 'area_id' => $nord, 'user' => 'nord@example.com' ) );
check( 'staff can be assigned to an area; unknown users rejected', ! is_wp_error( $added ) && array( 8 ) === Areas::staff( $nord ) && is_wp_error( RulesPage::add_staff( array( 'area_id' => $nord, 'user' => 'nobody' ) ) ) );
$GLOBALS['wpcap_test_user_id'] = 8;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'token', $s['codes'][1] );
$req->set_param( 'area_id', $vip );
$web_wrong = Rest::check_in( $req );
$req->set_param( 'area_id', $nord );
$web_ok = Rest::check_in( $req );
$out_req = new WP_REST_Request( 'POST' );
$out_req->set_param( 'token', $s['codes'][1] );
$out_req->set_param( 'area_id', $vip );
$web_out_wrong = Rest::check_out( $out_req );
$out_req->set_param( 'area_id', $nord );
$web_out = Rest::check_out( $out_req );
$selectable = array_map( static fn( $a ) => (int) $a->area_id, Areas::selectable( array( $s['event'] ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'web: staff assigned to Nord cannot scan at VIP, can at Nord', is_wp_error( $web_wrong ) && 'wrong_area' === $web_wrong->get_error_data()['result'] && ! is_wp_error( $web_ok ) && 'checked_in' === $web_ok->get_data()['status'] );
check( 'web: check-out endpoint applies staff area rule', is_wp_error( $web_out_wrong ) && ! is_wp_error( $web_out ) && 'checked_out' === $web_out->get_data()['status'] );
check( 'scanner area list is limited to the staff areas', array( $nord ) === $selectable );
check( 'admins are not restricted by area staff assignments', 'checked_in' === adm_in( $s['codes'][2], null, array( 'area_id' => $vip ) ) );
$movement = Engine::movement( $s['occ'] );
check( 'entries and check-outs are counted per area', 2 === ( $movement['by_area'][ $vip ]['entries'] ?? 0 ) && 1 === ( $movement['by_area'][ $nord ]['entries'] ?? 0 ) && 1 === ( $movement['by_area'][ $nord ]['checked_out'] ?? 0 ) );

echo "Admission: areas, check-out and stats in the app API\n";
$GLOBALS['wpcap_test_user_id'] = 7;
check( 'pairing refuses an area of another event', is_wp_error( Devices::create_pairing( array( $s['event'] ), 'X', 1, $foreign_area ) ) );
$pair = Devices::create_pairing( array( $s['event'] ), 'VIP-Gerät', 2, $vip );
$GLOBALS['wpcap_test_user_id'] = 0;
$dev = Devices::pair( $pair['code'], 'VIP', 'android', '1.1' );
$tok = $dev['token'];
$me = app_call( 'me', 'GET', $tok );
check( 'paired device is bound to the selected area; /app/me returns it', $vip === ( $dev['area']['id'] ?? 0 ) && $vip === ( $me['area']['id'] ?? 0 ) && 'VIP' === $me['area']['name'] && true === $me['features']['check_out'] );
RulesPage::save_policy( array( 'ticket_type_id' => $other['type'], 'policy' => 'default' ) );
$nord_type = make_type( 10, 10, 0 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $s['occ'] ), array( 'ticket_type_id' => $nord_type ) );
$GLOBALS['wpcap_test_transients'] = array();
$no = Orders::create( array( 'email' => 'nordonly@example.com', 'items' => array( array( 'ticket_type_id' => $nord_type, 'quantity' => 1 ) ), 'idempotency_key' => 'adm-nord-' . uniqid() ) );
$nord_code = ticket_code_for( $no['order_id'] )[0];
Areas::set_type_areas( $nord_type, $s['event'], array( $nord ) );
$app_wrong = app_call( 'check_in', 'POST', $tok, array( 'code' => $nord_code, 'idempotency_key' => 'adm-a1' ) );
$app_ok = app_call( 'check_in', 'POST', $tok, array( 'code' => $s['codes'][3], 'idempotency_key' => 'adm-a2' ) );
$app_out = app_call( 'check_out', 'POST', $tok, array( 'code' => $s['codes'][3], 'idempotency_key' => 'adm-a3' ) );
$app_out2 = app_call( 'check_out', 'POST', $tok, array( 'code' => $s['codes'][3], 'idempotency_key' => 'adm-a4' ) );
$app_out_nokey = app_call( 'check_out', 'POST', $tok, array( 'code' => $s['codes'][3] ) );
check( 'app: ticket restricted to another area gives wrong_area', false === $app_wrong['ok'] && 'wrong_area' === $app_wrong['result'] );
check( 'app: check-in and check-out in the device area; second check-out not_checked_in', true === $app_ok['ok'] && true === $app_out['ok'] && 'checked_out' === $app_out['result'] && false === $app_out2['ok'] && 'not_checked_in' === $app_out2['result'] && is_wp_error( $app_out_nokey ) );
$occs = app_call( 'occurrences', 'GET', $tok );
$stats = app_call( 'stats', 'GET', $tok, array( 'occurrence_id' => $s['occ'] ) );
$vip_stats = array_values( array_filter( $stats['by_area'], static fn( $a ) => $vip === $a['area_id'] ) )[0] ?? array();
check( 'app: occurrences list areas; stats report presence and per-area counts', 2 === count( $occs['occurrences'][0]['areas'] ) && isset( $stats['present'] ) && $vip === $stats['device_area_id'] && ( $vip_stats['entries'] ?? 0 ) >= 2 && 1 === ( $vip_stats['checked_out'] ?? 0 ) );

echo "Admission: offline conflicts\n";
$c_used = $s['codes'][2]; // checked in at VIP by admin above
$sync = app_call( 'sync', 'POST', $tok, array( 'scans' => array(
	array( 'code' => $c_used, 'idempotency_key' => 'adm-off-1', 'scanned_at' => gmdate( 'c', time() - 120 ) ),
	array( 'code' => $nord_code, 'idempotency_key' => 'adm-off-2', 'scanned_at' => gmdate( 'c', time() - 100 ) ),
) ) );
$sync_again = app_call( 'sync', 'POST', $tok, array( 'scans' => array( array( 'code' => $c_used, 'idempotency_key' => 'adm-off-1', 'scanned_at' => gmdate( 'c', time() - 120 ) ) ) ) );
$tid_used = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $c_used ) . "'" );
$conf = $wpdb->get_row( "SELECT * FROM {$p}wpcap_admission_conflicts WHERE ticket_id=$tid_used" );
$device_id = (int) $wpdb->get_var( "SELECT device_id FROM {$p}wpcap_scanner_devices WHERE public_id='" . $dev['device_id'] . "'" );
check( 'sync: double admission is flagged and recorded with both scans', true === $sync['results'][0]['conflict'] && true === $sync['results'][0]['double_admission'] && $conf && 'open' === $conf->status && 'already_checked_in' === $conf->result && $device_id === (int) $conf->device_id && $conf->first_checkin_id > 0 && null !== $conf->scanned_at && null !== $conf->first_at );
check( 'sync: wrong-area offline admission is recorded as conflict too', true === $sync['results'][1]['conflict'] && 'wrong_area' === $sync['results'][1]['result'] && false === $sync['results'][1]['double_admission'] );
check( 're-upload does not create a second conflict', true === $sync_again['results'][0]['conflict'] && $sync_again['results'][0]['conflict_id'] === (int) $conf->conflict_id && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_admission_conflicts WHERE ticket_id=$tid_used" ) );
$status = Areas::device_status( array( $device_id ) )[ $device_id ];
check( 'device status shows area, last sync and open conflicts', $vip === $status['area_id'] && null !== $status['last_sync_at'] && 1 === $status['last_sync_scans'] && 2 === $status['open_conflicts'] );
$GLOBALS['wpcap_test_user_id'] = 1;
$wreq = new WP_REST_Request( 'POST' );
$wreq->set_param( 'scans', array( array( 'token' => $c_used, 'idempotency_key' => 'adm-web-1', 'scanned_at' => gmdate( 'c', time() - 60 ), 'area_id' => $vip ), array( 'token' => $s['codes'][1], 'idempotency_key' => 'adm-web-2', 'direction' => 'out', 'area_id' => $nord ) ) );
$wsync = Rest::offline_sync( $wreq )->get_data();
Rest::offline_sync( $wreq );
check( 'web offline sync records conflicts idempotently; offline check-out is no conflict', 'conflict' === $wsync['results'][0]['status'] && ! empty( $wsync['results'][0]['conflict_id'] ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_admission_conflicts WHERE ticket_id=$tid_used" ) && 'conflict' === $wsync['results'][1]['status'] && 'not_checked_in' === $wsync['results'][1]['result'] && empty( $wsync['results'][1]['conflict_id'] ) );
$open_before = Conflicts::open_count_for_current_user();
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$r_denied = ConflictsPage::resolve( array( 'conflict_id' => $conf->conflict_id, 'resolution' => 'accepted' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$r_bad = ConflictsPage::resolve( array( 'conflict_id' => $conf->conflict_id, 'resolution' => 'nope' ) );
$r_other = ConflictsPage::resolve( array( 'conflict_id' => $conf->conflict_id, 'resolution' => 'other', 'note' => '' ) );
$r_ok = ConflictsPage::resolve( array( 'conflict_id' => $conf->conflict_id, 'resolution' => 'duplicate_scan', 'note' => 'Gleiche Person, zweimal gescannt' ) );
$r_again = ConflictsPage::resolve( array( 'conflict_id' => $conf->conflict_id, 'resolution' => 'accepted' ) );
$resolved = Conflicts::get( $conf->conflict_id );
check( 'resolving requires wpcap_manage_events and a valid resolution (note for "other")', is_wp_error( $r_denied ) && 403 === $r_denied->get_error_data()['status'] && is_wp_error( $r_bad ) && is_wp_error( $r_other ) );
check( 'conflict is resolved once with note, user and audit entry', ! is_wp_error( $r_ok ) && 'resolved' === $resolved->status && 'duplicate_scan' === $resolved->resolution && 1 === (int) $resolved->resolved_by && is_wp_error( $r_again ) && $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='admission_conflict_resolved' AND object_id=" . (int) $conf->conflict_id ) );
check( 'open conflict counter (menu badge) decreases', Conflicts::open_count_for_current_user() === $open_before - 1 && $open_before >= 1 );
$listing = Conflicts::search( array( 'status' => 'open' ), null );
check( 'conflict list contains device and ticket details', $listing['total'] >= 1 && isset( $listing['rows'][0]->ticket_public_id ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
