<?php
/**
 * Integration tests for festival/multi-date passes (module Passes). Included from run.php (uses
 * check(), make_type(), ticket_code_for()). Also runnable as a concurrency worker:
 *   php p3_passes.php --pass-child <ticket_type_id> <tag> <start_microtime>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admission\Engine;
use PassCaptain\Checkin;
use PassCaptain\Modules\Passes;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\OfflineScanner\Package;
use PassCaptain\Orders;
use PassCaptain\Passes\Admission as PassAdmission;
use PassCaptain\Passes\AdminPage as PassAdmin;
use PassCaptain\Passes\Cancellation as PassCancellation;
use PassCaptain\Passes\Definitions;
use PassCaptain\Passes\Display;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--pass-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	Passes::register();
	$GLOBALS['wpcap_test_user_id'] = 0;
	$start = (float) $argv[4];
	while ( microtime( true ) < $start ) { usleep( 1000 ); }
	$r = Orders::create( array( 'email' => 'pass-' . $argv[3] . '@example.com', 'items' => array( array( 'ticket_type_id' => (int) $argv[2], 'quantity' => 1 ) ), 'idempotency_key' => 'pass-child-' . $argv[3] . '-' . getmypid() ), array( 'skip_rate_limit' => true ) );
	echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_message() : 'OK';
	exit;
}

Passes::register();
global $wpdb;
$p = $wpdb->prefix;
$fp_user_before = $GLOBALS['wpcap_test_user_id'] ?? null;
$fp_auto_refund_before = Settings::get( 'auto_refund_cancelled' );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_filters']['wpcap_admission_day_cutoff_hour'] = 0;
Settings::update( array( 'auto_refund_cancelled' => 0, 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );

function fp_ts( $local, $tz = 'Europe/Vienna' ) { return ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp(); }
function fp_utc( $local, $tz = 'Europe/Vienna' ) { return gmdate( 'Y-m-d H:i:s', fp_ts( $local, $tz ) ); }
function fp_event( $name ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => $name, 'slug' => 'fp' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	return (int) $wpdb->insert_id;
}
/** Occurrence on a local day (14:00–23:00 Vienna). */
function fp_occ( $event, $day, $capacity = 0 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => fp_utc( "$day 14:00" ), 'ends_at' => fp_utc( "$day 23:00" ), 'timezone' => 'Europe/Vienna', 'location' => 'Festivalgelände', 'capacity' => $capacity, 'status' => 'scheduled' ) );
	return (int) $wpdb->insert_id;
}
function fp_type( $occ, $name = 'Tagesticket', $price = 0, $capacity = 0 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
function fp_pass( $anchor, array $occurrences, array $options = array(), $price = 0, $name = 'Festivalpass' ) {
	$r = Definitions::create( array_merge( array( 'occurrence_id' => $anchor, 'name' => $name, 'price_minor' => $price, 'occurrence_ids' => $occurrences ), $options ) );
	return is_wp_error( $r ) ? 0 : (int) $r;
}
function fp_buy( $type, $qty = 1, $email = null ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email ?: 'fp' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'fp-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
}
function fp_err( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function fp_now( $ts ) { if ( null === $ts ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] = (int) $ts; } }
function fp_res( $r ) { return is_wp_error( $r ) ? (string) ( $r->get_error_data()['result'] ?? $r->get_error_code() ) : (string) $r['status']; }
function fp_in( $code, $local, array $ctx = array() ) { fp_now( fp_ts( $local ) ); $r = Checkin::perform( $code, $ctx + array( 'idempotency_key' => 'fp-' . uniqid( '', true ) ) ); fp_now( null ); $GLOBALS['fp_last'] = $r; return fp_res( $r ); }
function fp_out( $code, $local, array $ctx = array() ) { fp_now( fp_ts( $local ) ); $r = Checkin::check_out( $code, $ctx + array( 'idempotency_key' => 'fp-' . uniqid( '', true ) ) ); fp_now( null ); return fp_res( $r ); }
function fp_status( $code ) { global $wpdb; return $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $code ) . "'" ); }
function fp_ticket_id( $code ) { global $wpdb; return (int) $wpdb->get_var( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $code ) . "'" ); }

/* ------------------------------------------------------------------------------------------------ */
echo "Passes: definition and validation\n";
$ev = fp_event( 'Sommerfestival' );
$d1 = fp_occ( $ev, '2027-08-01', 3 );
$d2 = fp_occ( $ev, '2027-08-02', 3 );
$d3 = fp_occ( $ev, '2027-08-03', 3 );
$t1 = fp_type( $d1 );
$t2 = fp_type( $d2 );
$t3 = fp_type( $d3 );
$bad = array(
	Definitions::create( array( 'occurrence_id' => $d1, 'name' => 'X', 'occurrence_ids' => array() ) ),
	Definitions::create( array( 'occurrence_id' => $d1, 'name' => 'X', 'occurrence_ids' => array( $d1, $d2 ), 'max_occurrences' => 3 ) ),
	Definitions::create( array( 'occurrence_id' => $d1, 'name' => '', 'occurrence_ids' => array( $d1 ) ) ),
	Definitions::create( array( 'occurrence_id' => $d1, 'name' => 'X', 'occurrence_ids' => array( $d1, 999999 ) ) ),
	Definitions::create( array( 'occurrence_id' => $d1, 'name' => 'X', 'price_minor' => 1000, 'refund_per_day_minor' => 2000, 'occurrence_ids' => array( $d1 ) ) ),
);
$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_types WHERE name='X'" );
check( 'invalid pass definitions are rejected without leaving a ticket type', 5 === count( array_filter( $bad, 'is_wp_error' ) ) && 0 === $orphans );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = PassAdmin::create( array( 'anchor_id' => $d1, 'name' => 'Y', 'price' => '10', 'occurrence_ids' => array( $d1 ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'creating a pass requires wpcap_manage_events', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$pass = PassAdmin::create( array( 'anchor_id' => $d1, 'name' => 'Festivalpass', 'price' => '0', 'occurrence_ids' => array( $d3, $d1, $d2 ), 'entry_mode' => 'once', 'max_occurrences' => '3' ) );
$config = Definitions::get( $pass );
check( 'admin page creates the pass type on its anchor occurrence with sorted links (N = M stored as 0)', is_int( $pass ) && $pass > 0 && array( $d1, $d2, $d3 ) === $config->occurrence_ids && 0 === $config->max_occurrences && $d1 === (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pass" ) );
check( 'gate rows exist for all linked occurrences', 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Definitions::table( 'gates' ) . " WHERE occurrence_id IN ($d1,$d2,$d3)" ) );
check( 'pass policy is the repeatable policy "pass"', 'pass' === PassCaptain\Admission\Policies::for_type( $pass )['policy'] && null === PassCaptain\Admission\Policies::for_type( $t1 ) );

echo "Passes: capacity is consumed in every linked occurrence\n";
check( 'before sales: 3 places per day, 3 passes', 3 === Orders::availability( $pass ) && 3 === Orders::availability( $t2 ) );
$o_pass = fp_buy( $pass );
check( 'a pass is bought', ! is_wp_error( $o_pass ) );
check( 'one pass takes one place on each day (incl. non-anchor days)', 2 === Orders::availability( $t1 ) && 2 === Orders::availability( $t2 ) && 2 === Orders::availability( $t3 ) && 2 === Orders::availability( $pass ) );
$o_day3 = fp_buy( $t3, 2 );
check( 'day 3 sells its remaining 2 places', ! is_wp_error( $o_day3 ) && 0 === Orders::availability( $t3 ) );
check( 'pass availability is 0 when one included day is sold out', 0 === Orders::availability( $pass ) );
$blocked = fp_buy( $pass );
check( 'a sold-out day blocks the pass (message names the day)', is_wp_error( $blocked ) && false !== strpos( fp_err( $blocked ), '03.08.2027' ) );
$more3 = fp_buy( $t3 );
check( 'day 3 single ticket is refused (pass counted there)', is_wp_error( $more3 ) && false !== strpos( fp_err( $more3 ), 'Plätze' ) );
$o_day2 = fp_buy( $t2, 2 );
check( 'other days keep selling their remaining places', ! is_wp_error( $o_day2 ) && 0 === Orders::availability( $t2 ) && 2 === Orders::availability( $t1 ) );
$sold_before = Definitions::sold_units( $pass );
$extra = Definitions::save( $pass, array( 'occurrence_ids' => array( $d1, $d2, $d3, fp_occ( $ev, '2027-08-04', 1 ) ) ) );
$full4 = fp_occ( $ev, '2027-08-05', 1 );
fp_buy( fp_type( $full4 ) );
$extra_full = Definitions::save( $pass, array( 'occurrence_ids' => array( $d1, $d2, $d3, $full4 ) ) );
check( 'adding a full date to a sold pass is refused; a date with room is accepted', ! is_wp_error( $extra ) && 4 === count( $extra->occurrence_ids ) && is_wp_error( $extra_full ) && 1 === $sold_before );
Definitions::save( $pass, array( 'occurrence_ids' => array( $d1, $d2, $d3 ) ) );
check( 'removing a sold pass is refused', is_wp_error( Definitions::remove( $pass ) ) );

echo "Passes: per-occurrence pass quota and full refund\n";
$ev_q = fp_event( 'Quotenfestival' );
$q1 = fp_occ( $ev_q, '2027-09-01', 10 );
$q2 = fp_occ( $ev_q, '2027-09-02', 10 );
$tq2 = fp_type( $q2 );
$pass_q = fp_pass( $q1, array( $q1, $q2 ), array(), 5000 );
Definitions::set_quota( $q2, 2 );
check( 'pass availability respects the pass quota', 2 === Orders::availability( $pass_q ) && 10 === Orders::availability( $tq2 ) );
$oq = fp_buy( $pass_q, 2, 'quota@example.com' );
Orders::confirm_manual_payment( $oq['order_id'] );
$oq_over = fp_buy( $pass_q );
check( 'quota exhausted: further passes refused, single tickets unaffected', is_wp_error( $oq_over ) && false !== strpos( fp_err( $oq_over ), 'Pass-Kontingent' ) && 8 === Orders::availability( $tq2 ) );
$q_tickets = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $oq['order_id'] ) );
$refund = Refunds::request( $oq['order_id'], $q_tickets, 'Storno', 'fp-full-refund' );
check( 'full refund of the pass order completes', ! is_wp_error( $refund ) && 'completed' === $refund['status'] && 10000 === (int) $refund['amount_minor'] );
check( 'full refund releases capacity in all linked occurrences and the quota', 10 === Orders::availability( $tq2 ) && 2 === Orders::availability( $pass_q ) && 0 === PassCaptain\Passes\Capacity::pass_used( $q1, 0, gmdate( 'Y-m-d H:i:s' ), false ) );
Definitions::set_quota( $q2, 0 );

echo "Passes: concurrency (pass vs single-day ticket on the last place, passes with different anchors)\n";
$ev_c = fp_event( 'Rennen' );
$c1 = fp_occ( $ev_c, '2027-10-01', 5 );
$c2 = fp_occ( $ev_c, '2027-10-02', 1 );
$tc2 = fp_type( $c2 );
$pass_c = fp_pass( $c1, array( $c1, $c2 ) );
$pass_c2 = fp_pass( $c2, array( $c1, $c2 ), array(), 0, 'Pass mit anderem Anker' );
function fp_race( array $type_ids ) {
	$start = microtime( true ) + 0.6;
	$procs = array();
	foreach ( $type_ids as $i => $type_id ) {
		$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --pass-child ' . (int) $type_id . ' r' . $i . uniqid() . ' ' . $start . ' 2>&1', 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( stream_get_contents( $proc ) ); pclose( $proc ); }
	return $out;
}
$ok_total = 0;
$busy = 0;
$rounds_ok = true;
for ( $round = 0; $round < 3; $round++ ) {
	// Reset: remove previous sales of the race occurrences.
	$wpdb->query( "DELETE r FROM {$p}wpcap_reservations r WHERE r.ticket_type_id IN ($tc2,$pass_c,$pass_c2)" );
	$wpdb->query( "DELETE oi FROM {$p}wpcap_order_items oi WHERE oi.ticket_type_id IN ($tc2,$pass_c,$pass_c2)" );
	$results = fp_race( array( $pass_c, $tc2, $pass_c2, $tc2, $pass_c ) );
	$ok = count( array_filter( $results, static fn( $r ) => 'OK' === $r ) );
	$busy += count( array_filter( $results, static fn( $r ) => false !== strpos( $r, 'viel los' ) ) );
	$used = PassCaptain\Passes\Capacity::core_used( $c2, 0, gmdate( 'Y-m-d H:i:s' ) ) + PassCaptain\Passes\Capacity::pass_used( $c2, 0, gmdate( 'Y-m-d H:i:s' ), true );
	if ( 1 !== $ok || 1 !== $used ) { $rounds_ok = false; echo '    round ' . $round . ': ' . implode( ' | ', $results ) . "\n"; }
	$ok_total += $ok;
}
check( "exactly one of five parallel buyers gets the last place in each round (got $ok_total in 3 rounds)", $rounds_ok && 3 === $ok_total );
check( "no deadlock/lock timeout between passes with different anchors and single tickets ($busy busy)", 0 === $busy );

echo "Passes: admission at each linked occurrence\n";
$ev_a = fp_event( 'Festival A' );
$ev_b = fp_event( 'Festival B' );
$a1 = fp_occ( $ev_a, '2027-08-01' );
$a2 = fp_occ( $ev_a, '2027-08-02' );
$a3 = fp_occ( $ev_a, '2027-08-03' );
$b4 = fp_occ( $ev_b, '2027-08-04' );
$ta2 = fp_type( $a2 );
$pass_a = fp_pass( $a1, array( $a1, $a2, $b4 ) );
$oa = fp_buy( $pass_a, 1, 'admit@example.com' );
$code = ticket_code_for( $oa['order_id'] )[0] ?? '';
check( 'test setup: pass ticket code', 64 === strlen( $code ) );
check( 'day 1: admitted and bound to day 1', 'checked_in' === fp_in( $code, '2027-08-01 18:00' ) && $a1 === (int) $GLOBALS['fp_last']['occurrence_id'] );
check( 'day 1 again (entry mode once): refused, ticket stays valid', 'daily_limit_reached' === fp_in( $code, '2027-08-01 19:00' ) && 'valid' === fp_status( $code ) );
check( 'day 2 with a device of event A: admitted at day 2', 'checked_in' === fp_in( $code, '2027-08-02 15:00', array( 'event_ids' => array( $ev_a ) ) ) && $a2 === (int) $GLOBALS['fp_last']['occurrence_id'] );
check( 'day 3 (not included): not valid today', 'not_valid_today' === fp_in( $code, '2027-08-03 18:00' ) );
check( 'explicit scanner occurrence not included: wrong occurrence', 'wrong_occurrence' === fp_in( $code, '2027-08-03 18:00', array( 'occurrence_id' => $a3 ) ) );
check( 'device of event A on day 4 (event B): refused', in_array( fp_in( $code, '2027-08-04 18:00', array( 'event_ids' => array( $ev_a ) ) ), array( 'not_valid_today', 'out_of_window' ), true ) );
check( 'day 4 at the other festival event: admitted', 'checked_in' === fp_in( $code, '2027-08-04 18:00', array( 'event_ids' => array( $ev_b ) ) ) && $ev_b === (int) $GLOBALS['fp_last']['event_id'] );
fp_now( fp_ts( '2027-08-04 20:00' ) );
$pv = Checkin::preview( $code, array( 'occurrence_id' => $b4 ) );
fp_now( null );
check( 'preview at day 4 after entry shows the block', 'daily_limit_reached' === fp_res( $pv ) );
$single = fp_buy( $ta2, 1 );
$single_code = ticket_code_for( $single['order_id'] )[0] ?? '';
check( 'single-day tickets keep their behaviour', 'checked_in' === fp_in( $single_code, '2027-08-02 15:00', array( 'occurrence_id' => $a2 ) ) && 'checked_in' === fp_status( $single_code ) && 'wrong_occurrence' === fp_in( $single_code, '2027-08-02 15:00', array( 'occurrence_id' => $a1 ) ) );
$usage = PassAdmission::usage( $pass_a );
check( 'usage report: entries per day', 3 === count( $usage ) && '2027-08-01' === $usage[0]['service_date'] && 1 === $usage[0]['tickets'] && $a1 === $usage[0]['occurrence_id'] );

echo "Passes: re-entry per occurrence\n";
$pass_r = fp_pass( $a1, array( $a1, $a2 ), array( 'entry_mode' => 'reentry' ), 0, 'Wiedereinlass-Pass' );
$or = fp_buy( $pass_r, 1, 'reentry@example.com' );
$rcode = ticket_code_for( $or['order_id'] )[0] ?? '';
check( 'day 1 entry', 'checked_in' === fp_in( $rcode, '2027-08-01 15:00' ) );
check( 'second entry while inside is refused', 'already_inside' === fp_in( $rcode, '2027-08-01 16:00' ) );
check( 'check-out, then re-entry', 'checked_out' === fp_out( $rcode, '2027-08-01 17:00' ) && 'checked_in' === fp_in( $rcode, '2027-08-01 18:00' ) );
check( 'next day: admitted although never checked out on day 1', 'checked_in' === fp_in( $rcode, '2027-08-02 15:00' ) );
$presence_a2 = Engine::presence( $a2 )['present'];
check( 'presence counts the pass at the occurrence of its last entry', $presence_a2 >= 1 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_admission_state WHERE ticket_id=" . fp_ticket_id( $rcode ) . " AND occurrence_id<>$a2" ) );

echo "Passes: N of M\n";
$pass_n = fp_pass( $a1, array( $a1, $a2, $a3, $b4 ), array( 'max_occurrences' => 2 ), 0, '2 aus 4' );
$on = fp_buy( $pass_n, 1, 'nofm@example.com' );
$ncode = ticket_code_for( $on['order_id'] )[0] ?? '';
check( 'first chosen day admitted', 'checked_in' === fp_in( $ncode, '2027-08-01 15:00' ) );
check( 'second chosen day admitted', 'checked_in' === fp_in( $ncode, '2027-08-03 15:00' ) );
check( 'a third day is refused (entry limit)', 'entry_limit_reached' === fp_in( $ncode, '2027-08-04 15:00', array( 'event_ids' => array( $ev_b ) ) ) );
check( 'N-of-M pass still reserves capacity in all M dates', 4 === count( Definitions::occurrence_ids( $pass_n ) ) && false !== strpos( Display::valid_for( $pass_n ), '2 aus 4' ) );

echo "Passes: offline packages\n";
$GLOBALS['wpcap_test_filters']['wpcap_offline_refresh_interval'] = 0;
$hash = static fn( $c ) => hash( 'sha256', $c );
$entries = static function ( $occ ) use ( $wpdb, $p ) {
	Package::refresh( $occ, false, true );
	$out = array();
	foreach ( $wpdb->get_results( "SELECT token_hash,status,ticket_type_id FROM {$p}wpcap_offline_entries WHERE occurrence_id=$occ" ) as $row ) { $out[ $row->token_hash ] = $row; }
	return $out;
};
$e_b4 = $entries( $b4 );
$e_a2 = $entries( $a2 );
$e_a3 = $entries( $a3 );
check( 'pass tickets are shipped in the package of every linked occurrence (also of another event)', isset( $e_b4[ $hash( $code ) ], $e_a2[ $hash( $code ) ], $e_a2[ $hash( $rcode ) ] ) && (int) $e_b4[ $hash( $code ) ]->ticket_type_id === $pass_a );
check( 'not in the package of a date the pass does not include', ! isset( $e_a3[ $hash( $code ) ] ) && isset( $e_a3[ $hash( $ncode ) ] ) );
check( 'shipped as used where entered once; re-entry pass stays valid', 'checked_in' === $e_b4[ $hash( $code ) ]->status && 'valid' === $e_a2[ $hash( $rcode ) ]->status );
check( 'N-of-M pass with its limit used is shipped as used at further dates', 'checked_in' === $e_b4[ $hash( $ncode ) ]->status );
$listing = fp_occ( $ev_a, '2027-07-15' );
$pass_l = fp_pass( $listing, array( $a2, $a3 ), array(), 0, 'Pass mit Verkaufstermin' );
$ol = fp_buy( $pass_l, 1 );
$lcode = ticket_code_for( $ol['order_id'] )[0] ?? '';
$e_listing = $entries( $listing );
$e_a3 = $entries( $a3 );
check( 'a pass is not shipped for an anchor (sales) occurrence it does not include', ! isset( $e_listing[ $hash( $lcode ) ] ) && isset( $e_a3[ $hash( $lcode ) ] ) && 'valid' === $e_a3[ $hash( $lcode ) ]->status );
if ( function_exists( 'wp_salt' ) ) {
	$occ_row = $wpdb->get_row( "SELECT o.*,e.name AS event_name,e.status AS event_status FROM {$p}wpcap_occurrences o JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=$a3" );
	$pkg = Package::build( (object) array( 'device_id' => 0, 'public_id' => 'fp-device' ), $occ_row );
	$payload = json_decode( $pkg['payload'], true );
	$desc = array_values( array_filter( $payload['ticket_types'], static fn( $t ) => $t['id'] === $pass_l ) );
	check( 'signed package lists the pass type (policy pass) and its ticket', 1 === count( $desc ) && 'pass' === $desc[0]['policy'] && false === $desc[0]['multi'] && in_array( $hash( $lcode ), array_column( $payload['tickets'], 'h' ), true ) );
}
check( 'pass sold on a separate sales date admits on its dates only', 'checked_in' === fp_in( $lcode, '2027-08-02 15:00' ) && 'wrong_occurrence' === fp_in( $lcode, '2027-07-15 15:00', array( 'occurrence_id' => $listing ) ) );

echo "Passes: display\n";
$label = apply_filters( 'wpcap_ticket_seat_label', '', fp_ticket_id( $code ), array() );
check( 'ticket label (PDF, portal, wallet, ticket page) shows the included dates', false !== strpos( $label, 'Gültig für: Festival A 01.08.2027, Festival A 02.08.2027, Festival B 04.08.2027' ) );
check( 'seat labels of other modules are kept', 0 === strpos( Display::seat_label( 'Reihe 1', fp_ticket_id( $code ) ), 'Reihe 1 · Gültig für:' ) && 'Reihe 1' === Display::seat_label( 'Reihe 1', fp_ticket_id( $single_code ) ) );
$mail_found = false;
foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
	if ( str_starts_with( $mail['subject'], 'Deine Tickets' ) && false !== strpos( $mail['body'], $lcode ) ) { $mail_found = false !== strpos( $mail['body'], 'Gültig für: 02.08.2027, 03.08.2027' ); break; }
}
check( 'ticket mail lists the included dates', $mail_found );
check( 'resend/transfer mails get the dates as extra section', false !== strpos( apply_filters( 'wpcap_ticket_code_mail_section', '', fp_ticket_id( $lcode ) ), 'Gültig für:' ) );
$card = '<fieldset class="wpcap-types"><label for="wpcap-' . $a1 . '-' . $pass_a . '">Festivalpass — 0,00 €</label><input></fieldset>';
$card = Display::event_card( $card, (object) array( 'occurrence_id' => $a1 ), array( (object) array( 'ticket_type_id' => $pass_a ) ) );
check( 'event card shows the dates next to the pass', false !== strpos( $card, '<small class="wpcap-pass-dates">Gültig für:' ) );
$card2 = Display::event_card( '<fieldset class="wpcap-types"></fieldset>', (object) array( 'occurrence_id' => $a3 ), array() );
check( 'cards of included dates mention the pass and where it is sold', false !== strpos( $card2, 'wpcap-pass-hint' ) && false !== strpos( $card2, 'Pass mit Verkaufstermin' ) );
if ( ! class_exists( 'PassCaptain\\Frontend\\Format' ) || ! function_exists( 'wp_date' ) ) { check( 'listing skipped', true ); } else {
	$listing_html = Display::shortcode();
	check( 'pass listing shows passes with their dates', false !== strpos( $listing_html, 'Pass mit Verkaufstermin' ) && false !== strpos( $listing_html, 'Gültig für: 02.08.2027, 03.08.2027' ) );
}

echo "Passes: cancellation of one linked occurrence\n";
$ev_x = fp_event( 'Absagefestival' );
$x1 = fp_occ( $ev_x, '2027-11-01', 10 );
$x2 = fp_occ( $ev_x, '2027-11-02', 10 );
$x3 = fp_occ( $ev_x, '2027-11-03', 10 );
$tx3 = fp_type( $x3 );
$pass_x = fp_pass( $x1, array( $x1, $x2, $x3 ), array( 'refund_per_day_minor' => 2000 ), 9000, 'Absagepass' );
$ox = fp_buy( $pass_x, 2, 'cancel@example.com' );
Orders::confirm_manual_payment( $ox['order_id'] );
$xcodes = ticket_code_for( $ox['order_id'] );
$cancel = static function ( $occ ) use ( $wpdb, $p ) {
	$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $occ ) );
	add_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10, 1 );
	do_action( 'wpcap_occurrence_cancelled', $occ, 0 );
	remove_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10 );
};
$mails_before = count( $GLOBALS['wpcap_test_mails'] );
$cancel( $x2 );
$order_x = Orders::get( $ox['order_id'] );
check( 'cancelling an included day keeps the pass valid', 2 === count( $xcodes ) && 'valid' === fp_status( $xcodes[0] ) && 'valid' === fp_status( $xcodes[1] ) );
check( 'partial refund per day via Refunds API (2 passes × 20 €)', 4000 === (int) $order_x->refunded_minor && 1 === (int) PassCancellation::last_summary( $x2 )['day_refunds'] );
check( 'pass holders are informed', count( $GLOBALS['wpcap_test_mails'] ) > $mails_before );
$cancel( $x2 );
check( 'repeated cancellation handling does not refund twice', 4000 === (int) Orders::get( $ox['order_id'] )->refunded_minor );
check( 'the cancelled day no longer counts for availability; others still do', 8 === Orders::availability( $tx3 ) && 8 === Orders::availability( $pass_x ) );
$cancel( $x1 );
$new_anchor = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pass_x" );
check( 'cancelling the anchor moves the pass to the next date instead of revoking it', $x3 === $new_anchor && 'valid' === fp_status( $xcodes[0] ) && 8000 === (int) Orders::get( $ox['order_id'] )->refunded_minor );
check( 'capacity stays consistent after re-anchoring', 8 === Orders::availability( $tx3 ) );
check( 'pass still admits on the remaining day; cancelled day is refused', 'checked_in' === fp_in( $xcodes[0], '2027-11-03 15:00' ) && 'cancelled_event' === fp_in( $xcodes[1], '2027-11-02 15:00', array( 'occurrence_id' => $x2 ) ) );
$pass_v = fp_pass( $x3, array( $x3, fp_occ( $ev_x, '2027-11-04' ) ), array( 'on_cancel' => 'revoke' ), 0, 'Widerrufspass' );
$ov = fp_buy( $pass_v, 1 );
$vcode = ticket_code_for( $ov['order_id'] )[0] ?? '';
$x4 = Definitions::occurrence_ids( $pass_v )[1];
$cancel( $x4 );
check( 'on_cancel = revoke: the whole pass is revoked', 'revoked' === fp_status( $vcode ) );
$o_full = fp_buy( $pass_x, 1, 'full@example.com' );
Orders::confirm_manual_payment( $o_full['order_id'] );
$before_full = Orders::availability( $tx3 );
$full = Refunds::request( $o_full['order_id'], array(), 'Storno', 'fp-full-2' );
check( 'whole pass refund releases its place', ! is_wp_error( $full ) && 'completed' === $full['status'] && $before_full + 1 === Orders::availability( $tx3 ) );

echo "Passes: admin page rendering\n";
// Same stubs as partners.php (guarded; whichever file loads first defines them).
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button>' . esc_html( $t ) . '</button>'; } }
if ( function_exists( 'wp_die' ) ) {
	$_GET = array( 'ticket_type_id' => $pass_a );
	ob_start(); PassAdmin::render(); $html = ob_get_clean();
	$_GET = array();
	ob_start(); PassAdmin::render(); $overview = ob_get_clean();
	check( 'pass page shows dates, quota form and usage per day', false !== strpos( $html, 'wpcap_pass_quota' ) && false !== strpos( $html, '2027-08-01: 1 / 1' ) && false !== strpos( $overview, 'Festivalpass' ) && false !== strpos( $overview, 'wpcap_pass_create' ) );
} else {
	check( 'admin page rendering skipped (no admin stubs)', true );
}

unset( $GLOBALS['wpcap_test_filters']['wpcap_admission_day_cutoff_hour'] );
Settings::update( array( 'auto_refund_cancelled' => $fp_auto_refund_before ) );
$GLOBALS['wpcap_test_user_id'] = $fp_user_before;
