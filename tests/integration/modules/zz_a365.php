<?php
/**
 * A365: the web scanner REST answers say nothing about tickets of foreign events (scope is checked
 * before cancellation/refund/entry window) and refused scans carry only the client fields.
 * Uses check(), adm_setup() (modules/admission.php) and ticket_code_for() (mobile.php).
 */
// phpcs:ignoreFile
use PassCaptain\Checkin;
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;
echo "A365: scanner REST without data of foreign events\n";
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$x1_own = adm_setup( 'UTC', null, null, 1 );
$x1_foreign = adm_setup( 'UTC', null, null, 2 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), array( 'occurrence_id' => $x1_own['occ'] ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $x1_foreign['occ'] ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $x1_own['event'], 'user_id' => 365, 'capability' => 'wpcap_checkin' ) );

function x1_a365_call( $method, $token ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/checkin' );
	$req->set_param( 'token', $token );
	$req->set_param( 'idempotency_key', 'x1-a365-' . uniqid( '', true ) );
	return Controller::$method( $req );
}

$GLOBALS['wpcap_test_user_id'] = 365;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$x1_in = x1_a365_call( 'check_in', $x1_foreign['codes'][0] );
$x1_pre = x1_a365_call( 'validate_ticket', $x1_foreign['codes'][1] );
$x1_direct = Checkin::perform( $x1_foreign['codes'][0], array( 'source' => 'web' ) );
$x1_ok = x1_a365_call( 'check_in', $x1_own['codes'][0] );
$x1_again = x1_a365_call( 'check_in', $x1_own['codes'][0] );
$GLOBALS['wpcap_test_denied_caps'] = array();
$x1_admin = Checkin::perform( $x1_foreign['codes'][0], array( 'source' => 'web' ) );
$GLOBALS['wpcap_test_user_id'] = 1;

$x1_res = static function ( $r ) { return is_wp_error( $r ) ? (string) ( $r->get_error_data()['result'] ?? '' ) : ''; };
check( 'A365: check-in of a cancelled foreign event says "forbidden", not "cancelled_event"', 'forbidden' === $x1_res( $x1_in ) && 403 === (int) $x1_in->get_error_data()['status'] );
check( 'A365: the preview of a foreign ticket says "forbidden" as well', 'forbidden' === $x1_res( $x1_pre ) );
check( 'A365: Checkin::perform() checks the scope before the occurrence state', 'forbidden' === $x1_res( $x1_direct ) );
check( 'A365: the foreign refusal carries no event name or ids', is_wp_error( $x1_in ) && ! array_intersect_key( (array) $x1_in->get_error_data(), array_flip( array( 'event', 'ticket', 'event_id', 'ticket_id', 'occurrence_id' ) ) ) );
check( 'A365: an administrator still gets the real reason', 'cancelled_event' === $x1_res( $x1_admin ) );
check( 'A365: the own event is admitted', ! is_wp_error( $x1_ok ) );
$x1_allowed = array_merge( array( 'status', 'result', 'ticket_status', 'event', 'ticket', 'retryable' ), Checkin::CLIENT_ERROR_KEYS );
check( 'A365: a refused web scan carries only the client fields (no checkin_id)', is_wp_error( $x1_again ) && 'already_checked_in' === $x1_res( $x1_again ) && ! array_diff( array_keys( (array) $x1_again->get_error_data() ), $x1_allowed ) );
