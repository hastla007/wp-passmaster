<?php
/**
 * A480 (membership pass: concurrent redemptions exceed uses_total), A481 (staff could issue unlimited
 * free membership passes: request + approval by a second person) and A482 (redemption ignored the
 * occurrence status, the day of the occurrence, capacity and admission statistics).
 *
 * Also a concurrency worker: php zz_a480_a481_a482.php --member-child <token> <ticket_type_id> <key>
 * (sleeps 3 s right before it locks the pass row, then redeems).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--member-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	$GLOBALS['wpcap_test_hooks']['query'][10][] = array( static function ( $sql ) {
		if ( false !== strpos( $sql, 'wpcap_member_passes WHERE token_hash' ) && empty( $GLOBALS['a480_slept'] ) ) { $GLOBALS['a480_slept'] = 1; sleep( 3 ); }
		return $sql;
	}, 1 );
	$r = PassCaptain\Memberships::redeem( $argv[2], (int) $argv[3], $argv[4] );
	echo is_wp_error( $r ) ? $r->get_error_code() : $r['status'];
	exit;
}

use PassCaptain\Memberships;

global $wpdb;
$p = $wpdb->prefix;
$a48_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a48_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

/** Event with one occurrence (running now unless $start_offset says otherwise) and one ticket type. */
function a48_type( $status = 'scheduled', $start_offset = -1800, $occ_capacity = 0, $type_capacity = 0 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Mitgliederabend', 'slug' => 'a48' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $start_offset ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + $start_offset + 3 * 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => $occ_capacity, 'status' => $status ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Eintritt', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => $type_capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( (int) $wpdb->insert_id, $occ, $event );
}
function a48_pass( $event, $total = 0, $per_day = 5, $email = null ) {
	$r = Memberships::issue( $email ?: 'm' . uniqid() . '@example.com', gmdate( 'Y-m-d H:i:s', time() - 86400 ), gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 ), array( array( 'event_id' => $event, 'ticket_type_id' => 0, 'uses_total' => $total, 'uses_per_day' => $per_day ) ) );
	return $r;
}
function a48_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : (string) $r['status']; }

echo "A480: concurrent redemptions respect uses_total\n";
check( 'A480/A482: schema 3.10.18 columns exist (pass_uses.occurrence_id, member_passes.requested_by)', Memberships::schema_ready() && version_compare( PassCaptain\Installer::DB_VERSION, '3.10.18', '>=' ) );
list( $a48_t, $a48_o, $a48_e ) = a48_type();
$a48_p = a48_pass( $a48_e, 1 );
$a48_proc = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --member-child ' . $a48_p['token'] . ' ' . $a48_t . ' keyB 2>&1', 'r' );
sleep( 1 );
$a48_a = Memberships::redeem( $a48_p['token'], $a48_t, 'keyA' );
$a48_b = trim( stream_get_contents( $a48_proc ) );
pclose( $a48_proc );
$a48_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_pass_uses WHERE pass_id=%d", $a48_p['pass_id'] ) );
check( "A480: uses_total=1 – two parallel scans book exactly one use (A: " . a48_code( $a48_a ) . ", B: $a48_b, rows $a48_rows)", 1 === $a48_rows && 'redeemed' === a48_code( $a48_a ) && 'wpcap_pass_quota' === $a48_b );
$a48_again = Memberships::redeem( $a48_p['token'], $a48_t, 'keyA' );
check( 'A480: the same idempotency key is answered as already redeemed', 'already_redeemed' === a48_code( $a48_again ) );

echo "A482: redemption is bound to a scheduled occurrence of today\n";
$a48_bound = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,ticket_type_id FROM {$p}wpcap_pass_uses WHERE pass_id=%d", $a48_p['pass_id'] ) );
check( 'A482: the use records occurrence and ticket type', $a48_bound && $a48_o === (int) $a48_bound->occurrence_id && $a48_t === (int) $a48_bound->ticket_type_id );
list( $a48_ct, , $a48_ce ) = a48_type( 'cancelled' );
$a48_cp = a48_pass( $a48_ce );
check( 'A482: a cancelled occurrence is refused', 'wpcap_pass_occurrence' === a48_code( Memberships::redeem( $a48_cp['token'], $a48_ct, 'c1' ) ) );
list( $a48_ft, , $a48_fe ) = a48_type( 'scheduled', 7 * 86400 );
$a48_fp = a48_pass( $a48_fe );
check( 'A482: an occurrence next week cannot be redeemed today', 'wpcap_pass_not_today' === a48_code( Memberships::redeem( $a48_fp['token'], $a48_ft, 'f1' ) ) );
list( $a48_kt, $a48_ko, $a48_ke ) = a48_type( 'scheduled', -1800, 1 );
$a48_k1 = a48_pass( $a48_ke );
$a48_k2 = a48_pass( $a48_ke );
$a48_kr1 = Memberships::redeem( $a48_k1['token'], $a48_kt, 'k1' );
$a48_kr2 = Memberships::redeem( $a48_k2['token'], $a48_kt, 'k2' );
check( 'A482: a full occurrence (capacity 1, one member inside) admits no further member', 'redeemed' === a48_code( $a48_kr1 ) && 'wpcap_pass_occurrence_full' === a48_code( $a48_kr2 ) );
list( $a48_qt, , $a48_qe ) = a48_type();
$a48_q1 = a48_pass( $a48_qe );
$a48_q2 = a48_pass( $a48_qe );
$GLOBALS['wpcap_test_filters']['wpcap_member_pass_occurrence_quota'] = 1;
$a48_qr1 = Memberships::redeem( $a48_q1['token'], $a48_qt, 'q1' );
$a48_qr2 = Memberships::redeem( $a48_q2['token'], $a48_qt, 'q2' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_member_pass_occurrence_quota'] );
check( 'A482: the optional member quota per occurrence is enforced', 'redeemed' === a48_code( $a48_qr1 ) && 'wpcap_pass_occurrence_full' === a48_code( $a48_qr2 ) );
$a48_stats = PassCaptain\Reports\Data::occurrence_stats( array( $a48_ko ), '1970-01-01 00:00:00', '1970-01-01 00:00:00', null );
check( 'A482: member entries appear in the admission statistics', 1 === (int) ( $a48_stats[ $a48_ko ]['member_entries'] ?? -1 ) );

echo "A481: membership passes need approval by a second person\n";
$GLOBALS['wpcap_test_user_id'] = 7;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a48_e, 'user_id' => 7, 'capability' => 'wpcap_manage_orders' ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a48_e, 'user_id' => 8, 'capability' => 'wpcap_approve_comps' ) );
$a48_mails = count( $GLOBALS['wpcap_test_mails'] );
$a48_req = Memberships::issue( 'antrag@example.com', gmdate( 'Y-m-d H:i:s', time() - 60 ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), array( array( 'event_id' => $a48_e, 'uses_per_day' => 1 ) ) );
$a48_row = is_wp_error( $a48_req ) ? null : Memberships::get( $a48_req['pass_id'] );
check( 'A481: a request by box office/support staff stays pending, without code or mail', ! is_wp_error( $a48_req ) && 'pending' === $a48_req['status'] && empty( $a48_req['token'] ) && $a48_row && 'pending' === $a48_row->status && 7 === (int) $a48_row->requested_by && count( $GLOBALS['wpcap_test_mails'] ) === $a48_mails );
$a48_self = Memberships::approve( $a48_req['pass_id'] );
check( 'A481: the requester cannot approve the own request (four-eyes)', is_wp_error( $a48_self ) && 'wpcap_pass_four_eyes' === $a48_self->get_error_code() );
$GLOBALS['wpcap_test_user_id'] = 8;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_approve_comps' );
$a48_nocap = Memberships::approve( $a48_req['pass_id'] );
check( 'A481: approving needs wpcap_approve_comps', is_wp_error( $a48_nocap ) && 403 === $a48_nocap->get_error_data()['status'] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$a48_ok = Memberships::approve( $a48_req['pass_id'] );
$a48_row = Memberships::get( $a48_req['pass_id'] );
$a48_last = end( $GLOBALS['wpcap_test_mails'] );
check( 'A481: a second person with wpcap_approve_comps approves; the code is mailed only now', ! is_wp_error( $a48_ok ) && 'active' === $a48_row->status && 8 === (int) $a48_row->approved_by && hash( 'sha256', $a48_ok['token'] ) === $a48_row->token_hash && $a48_last && 'antrag@example.com' === $a48_last['to'] && false !== strpos( $a48_last['body'] . $a48_last['html'], $a48_ok['token'] ) );
check( 'A481: an approved request cannot be approved twice', is_wp_error( Memberships::approve( $a48_req['pass_id'] ) ) );
$a48_listed = array_map( static function ( $r ) { return (int) $r->pass_id; }, Memberships::list_passes() );
check( 'A481: the admin list shows passes in scope (incl. the approved one)', in_array( (int) $a48_req['pass_id'], $a48_listed, true ) && ! in_array( (int) $a48_cp['pass_id'], $a48_listed, true ) );
$a48_rev = PassCaptain\Admin\MembershipsPage::decide( array( 'pass_id' => $a48_req['pass_id'], 'do' => 'revoke' ) );
check( 'A481: the admin list revokes a pass', true === $a48_rev && 'revoked' === Memberships::get( $a48_req['pass_id'] )->status );
$GLOBALS['wpcap_test_user_id'] = 7;
$a48_req2 = Memberships::issue( 'antrag2@example.com', gmdate( 'Y-m-d H:i:s', time() - 60 ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), array( array( 'event_id' => $a48_e ) ) );
$GLOBALS['wpcap_test_user_id'] = 8;
check( 'A481: an approver rejects a request', true === Memberships::reject( $a48_req2['pass_id'] ) && 'rejected' === Memberships::get( $a48_req2['pass_id'] )->status );
$GLOBALS['wpcap_test_user_id'] = 7;
$a48_global = Memberships::in_scope( array( 0 ), Memberships::REQUEST_CAP );
check( 'A481: passes for all events stay with the administration', false === $a48_global );
if ( class_exists( 'WP_REST_Request' ) ) {
	$a48_rq = new WP_REST_Request( 'POST', '/pass-captain/v1/member-passes' );
	$a48_rq->set_body( json_encode( array( 'email' => 'rest@example.com', 'valid_from' => gmdate( 'Y-m-d H:i:s' ), 'valid_until' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'rules' => array( array( 'event_id' => $a48_e ) ) ) ) );
	$a48_rr = PassCaptain\Rest\Controller::issue_member_pass( $a48_rq );
	check( 'A481: REST answers 202 "pending" for a request without administrator rights', ! is_wp_error( $a48_rr ) && 202 === $a48_rr->get_status() && 'pending' === $a48_rr->get_data()['status'] );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_event_staff WHERE event_id=%d AND user_id IN (7,8)", $a48_e ) );
$GLOBALS['wpcap_test_user_id'] = $a48_user;
$GLOBALS['wpcap_test_denied_caps'] = $a48_denied;
