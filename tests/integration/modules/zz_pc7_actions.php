<?php
/**
 * PC-7 (successor of A676): the five public write actions – wpcap_resend_ticket_mail(), wpcap_revoke_ticket(),
 * wpcap_cancel_event(), wpcap_reschedule_occurrence(), wpcap_confirm_attendance() – with capability of the
 * current user or an explicit user, strict options, optional idempotency key, audit and results without codes
 * or personal data; answers match tests/contracts/actions.json. No queue, no envelope. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Api\Actions;
use PassCaptain\Orders;
use PassCaptain\Suite\Outbox;

require_once dirname( __DIR__, 2 ) . '/contracts/shape.php';
require_once WPCAP_PATH . 'src/functions.php';

global $wpdb;
$p = $wpdb->prefix;
$x6k_hooks_before = $GLOBALS['wpcap_test_hooks'];
PassCaptain\Review\Events::register();
$x6k_key = static fn( $s ) => 'x6k-' . $s . '-' . substr( md5( uniqid( '', true ) ), 0, 10 );
$x6k_code = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok';
$x6k_fx = wpcap_contract_fixture( 'actions' );
$x6k_shape = static fn( $name, $r ) => is_array( $r ) && array() === wpcap_contract_violations( $x6k_fx['endpoints'][ $name ]['response'] ?? array( 'missing' => 'string' ), $r );

echo "PC-7: public write actions\n";
check( 'PC-7: exactly the five actions exist as public functions and in the fixture', array( 'resend_ticket_mail', 'revoke_ticket', 'cancel_event', 'reschedule_occurrence', 'confirm_attendance' ) === array_keys( Actions::ACTIONS ) && array_keys( Actions::ACTIONS ) === array_keys( (array) ( $x6k_fx['endpoints'] ?? array() ) ) && function_exists( 'wpcap_resend_ticket_mail' ) && function_exists( 'wpcap_revoke_ticket' ) && function_exists( 'wpcap_cancel_event' ) && function_exists( 'wpcap_reschedule_occurrence' ) && function_exists( 'wpcap_confirm_attendance' ) );
check( 'PC-7: the actions are listed as contract "actions" 1.0.0 and the idempotency table exists', '1.0.0' === PassCaptain\Contracts::version( 'actions' ) && Actions::ready() );
check( 'PC-7: no action for payment, refund or ticket issuance', ! array_filter( array_keys( Actions::ACTIONS ), static fn( $c ) => preg_match( '/refund|pay|issue|code/', $c ) ) );
check( 'PC-7: an invalid idempotency key is refused', 'wpcap_action_idempotency_key' === $x6k_code( wpcap_revoke_ticket( str_repeat( 'a', 32 ), 'x', array( 'idempotency_key' => 'short' ) ) ) );
$x6k_r = wpcap_revoke_ticket( str_repeat( 'a', 32 ), 'x', array( 'refund' => true ) );
check( 'PC-7: an unknown option is refused and named', 'wpcap_action_invalid' === $x6k_code( $x6k_r ) && 'refund' === ( $x6k_r->get_error_data()['arg'] ?? '' ) );
check( 'PC-7: a missing reason is refused', 'wpcap_action_invalid' === $x6k_code( wpcap_revoke_ticket( str_repeat( 'a', 32 ), '  ' ) ) );
check( 'PC-7: an unknown ticket is not found (404)', 'wpcap_action_not_found' === $x6k_code( wpcap_revoke_ticket( str_repeat( 'a', 32 ), 'x' ) ) );

$x6k_type = make_type( 10, 10, 2000 );
$x6k_o = Orders::create( array( 'email' => 'x6k@example.com', 'items' => array( array( 'ticket_type_id' => $x6k_type, 'quantity' => 3 ) ), 'idempotency_key' => 'x6k-' . uniqid() ) );
Orders::confirm_manual_payment( $x6k_o['order_id'] );
$x6k_t = tickets( $x6k_o['order_id'] );
$x6k_order = Orders::get( $x6k_o['order_id'] );

$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$x6k_forbidden = wpcap_revoke_ticket( $x6k_t[0]->public_id, 'Doppelt' );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'PC-7: without the capability of the current user nothing happens (403)', 'wpcap_action_forbidden' === $x6k_code( $x6k_forbidden ) && 403 === $x6k_forbidden->get_error_data()['status'] && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $x6k_t[0]->ticket_id ) );
$GLOBALS['wpcap_test_user_caps'][ 4711 ] = array( 'read' );
$x6k_other = wpcap_revoke_ticket( $x6k_t[0]->public_id, 'Doppelt', array( 'user_id' => 4711 ) );
unset( $GLOBALS['wpcap_test_user_caps'][ 4711 ] );
check( 'PC-7: an explicit user without the capability is refused, even if the current user has it', 'wpcap_action_forbidden' === $x6k_code( $x6k_other ) && 1 === get_current_user_id() );

$x6k_rk = $x6k_key( 'revoke' );
$x6k_rev = wpcap_revoke_ticket( $x6k_t[0]->public_id, 'Doppelt gebucht', array( 'idempotency_key' => $x6k_rk, 'user_id' => 4712 ) );
$x6k_rev2 = wpcap_revoke_ticket( (int) $x6k_t[0]->ticket_id, 'Doppelt gebucht', array( 'user_id' => 4712, 'idempotency_key' => $x6k_rk ) );
check( 'PC-7: wpcap_revoke_ticket() revokes through the admin service as the explicit user, who is switched back afterwards', is_array( $x6k_rev ) && false === $x6k_rev['replayed'] && 'revoked' === $x6k_rev['status'] && $x6k_shape( 'revoke_ticket', $x6k_rev ) && 'revoked' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $x6k_t[0]->ticket_id ) && 1 === get_current_user_id() );
check( 'PC-7: the same key returns the stored result without running again (internal or public id alike)', is_array( $x6k_rev2 ) && true === $x6k_rev2['replayed'] && $x6k_rev['ticket_id'] === $x6k_rev2['ticket_id'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Outbox::table() . " WHERE event_type='experience_excluded' AND subject_id=%s", $x6k_t[0]->public_id ) ) );
check( 'PC-7: the same key with other arguments is a conflict (409)', 'wpcap_action_idempotency_conflict' === $x6k_code( wpcap_revoke_ticket( $x6k_t[1]->public_id, 'Doppelt gebucht', array( 'idempotency_key' => $x6k_rk, 'user_id' => 4712 ) ) ) );
$x6k_fk = $x6k_key( 'r2' );
$x6k_again = wpcap_revoke_ticket( $x6k_t[0]->public_id, 'Nochmal', array( 'idempotency_key' => $x6k_fk ) );
$x6k_again2 = wpcap_revoke_ticket( $x6k_t[0]->public_id, 'Nochmal', array( 'idempotency_key' => $x6k_fk ) );
check( 'PC-7: a refused action (ticket already revoked) is stored as failed under its key and answered the same again', 'wpcap_ticket_not_valid' === $x6k_code( $x6k_again ) && 'wpcap_ticket_not_valid' === $x6k_code( $x6k_again2 ) && ! empty( $x6k_again2->get_error_data()['replayed'] ) );

$x6k_mails = count( $GLOBALS['wpcap_test_mails'] );
$x6k_mk = $x6k_key( 'mail' );
$x6k_mail = wpcap_resend_ticket_mail( $x6k_order->public_id, array( 'idempotency_key' => $x6k_mk ) );
$x6k_after = count( $GLOBALS['wpcap_test_mails'] );
wpcap_resend_ticket_mail( (int) $x6k_o['order_id'], array( 'idempotency_key' => $x6k_mk ) );
check( 'PC-7: wpcap_resend_ticket_mail() sends the ticket mail again (stored codes by default) – a replay sends nothing', is_array( $x6k_mail ) && 'sent' === $x6k_mail['status'] && 'kept' === $x6k_mail['codes'] && $x6k_shape( 'resend_ticket_mail', $x6k_mail ) && $x6k_after > $x6k_mails && count( $GLOBALS['wpcap_test_mails'] ) === $x6k_after );
$x6k_mail2 = wpcap_resend_ticket_mail( (int) $x6k_o['order_id'] );
check( 'PC-7: without a key the action simply runs again', is_array( $x6k_mail2 ) && false === $x6k_mail2['replayed'] && count( $GLOBALS['wpcap_test_mails'] ) > $x6k_after );

// Attendance: only once the date has begun; then experience_completed (method confirmed).
$x6k_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x6k_type" );
check( 'PC-7: wpcap_confirm_attendance() before the start is refused', 'wpcap_action_attendance_too_early' === $x6k_code( wpcap_confirm_attendance( $x6k_t[1]->public_id, 'list' ) ) );
check( 'PC-7: an unknown confirmation method is refused', 'wpcap_action_invalid' === $x6k_code( wpcap_confirm_attendance( $x6k_t[1]->public_id, 'guess' ) ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $x6k_occ ) );
$x6k_conf = wpcap_confirm_attendance( $x6k_t[1]->public_id, 'list' );
$x6k_conf2 = wpcap_confirm_attendance( (int) $x6k_t[1]->ticket_id, 'list' );
$x6k_rv = $wpdb->get_col( $wpdb->prepare( 'SELECT payload FROM ' . Outbox::table() . " WHERE event_type='experience_completed' AND subject_id=%s", $x6k_t[1]->public_id ) );
check( 'PC-7: wpcap_confirm_attendance() records the allowed confirmation once (review experience_completed, method confirmed)', is_array( $x6k_conf ) && 'confirmed' === $x6k_conf['status'] && $x6k_shape( 'confirm_attendance', $x6k_conf ) && is_array( $x6k_conf2 ) && 1 === count( $x6k_rv ) && 'confirmed' === json_decode( $x6k_rv[0], true )['data']['method'] );
check( 'PC-7: a revoked ticket cannot be confirmed', 'wpcap_action_attendance_not_allowed' === $x6k_code( wpcap_confirm_attendance( $x6k_t[0]->public_id, 'manual' ) ) );

// Reschedule through the regular occurrence form.
$x6k_new = gmdate( 'Y-m-d\TH:i:00\Z', strtotime( '+20 days 18:00' ) );
check( 'PC-7: wpcap_reschedule_occurrence() needs ISO 8601 with a time zone', 'wpcap_action_invalid' === $x6k_code( wpcap_reschedule_occurrence( $x6k_occ, '2027-05-01 19:30' ) ) );
$x6k_res = wpcap_reschedule_occurrence( $x6k_occ, $x6k_new, null, array( 'idempotency_key' => $x6k_key( 'resched' ) ) );
check( 'PC-7: wpcap_reschedule_occurrence() moves the date and reports the new start', is_array( $x6k_res ) && $x6k_new === $x6k_res['starts_at'] && $x6k_shape( 'reschedule_occurrence', $x6k_res ) && gmdate( 'Y-m-d H:i:s', strtotime( $x6k_new ) ) === $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$x6k_occ" ) );

// Event cancellation through the regular flow; again = continues/answers without a second cancellation.
$x6k_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$x6k_occ" );
$x6k_ck = $x6k_key( 'cancel' );
$x6k_cancel = wpcap_cancel_event( $x6k_event, 'Unwetter', array( 'idempotency_key' => $x6k_ck ) );
$x6k_cancel2 = wpcap_cancel_event( $x6k_event, 'Unwetter', array( 'idempotency_key' => $x6k_ck ) );
check( 'PC-7: wpcap_cancel_event() cancels the event through the regular flow; the same key replays', is_array( $x6k_cancel ) && 'cancelled' === $x6k_cancel['status'] && $x6k_shape( 'cancel_event', $x6k_cancel ) && 'cancelled' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_events WHERE event_id=$x6k_event" ) && is_array( $x6k_cancel2 ) && true === $x6k_cancel2['replayed'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='event_cancelled' AND object_id=$x6k_event" ) );

$x6k_blob = wp_json_encode( array( $x6k_rev, $x6k_mail, $x6k_conf, $x6k_res, $x6k_cancel ) );
check( 'PC-7: results contain no ticket codes and no personal data', ! preg_match( '/[a-f0-9]{64}/', $x6k_blob ) && false === strpos( $x6k_blob, '@' ) );
$x6k_audit = $wpdb->get_col( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='api_action' ORDER BY audit_id DESC LIMIT 40" );
check( 'PC-7: every action call is audited with action, user, key hash and result (no key in clear, no PII)', count( $x6k_audit ) >= 12 && ! array_filter( $x6k_audit, static fn( $d ) => false !== strpos( $d, 'x6k-' ) || false !== strpos( $d, '@' ) ) && (bool) array_filter( $x6k_audit, static fn( $d ) => false !== strpos( $d, '"result":"forbidden"' ) && false !== strpos( $d, '"user_id":4711' ) ) && (bool) array_filter( $x6k_audit, static fn( $d ) => false !== strpos( $d, '"action":"cancel_event"' ) && false !== strpos( $d, '"result":"ok"' ) ) );
$x6k_src = (string) file_get_contents( WPCAP_PATH . 'src/Api/Actions.php' );
check( 'PC-7: the actions have no queue and no envelope (direct service calls)', false === strpos( $x6k_src, 'Outbox' ) && false === strpos( $x6k_src, 'Jobs::' ) );
$GLOBALS['wpcap_test_hooks'] = $x6k_hooks_before;
