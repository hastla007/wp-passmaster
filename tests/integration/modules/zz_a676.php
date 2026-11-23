<?php
/**
 * A676: workflow commands v1 – resend mail, revoke ticket, cancel event, reschedule date, confirm an
 * allowed attendance – with capability, allowlist of commands and arguments, idempotency key and
 * audit; no payment, refund or code output. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Workflow\Commands;
use PassCaptain\Suite\Outbox;

global $wpdb;
$p = $wpdb->prefix;
$x6k_hooks_before = $GLOBALS['wpcap_test_hooks'];
PassCaptain\Review\Events::register();
$x6k_key = static fn( $s ) => 'x6k-' . $s . '-' . substr( md5( uniqid( '', true ) ), 0, 10 );
$x6k_code = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok';

echo "A676: workflow commands v1\n";
check( 'A676: the command table exists and the allowlist names exactly the five commands', Commands::ready() && array( 'mail.resend', 'ticket.revoke', 'event.cancel', 'occurrence.reschedule', 'attendance.confirm' ) === array_keys( Commands::COMMANDS ) );
check( 'A676: no command for payment, refund or ticket issuance', ! array_filter( array_keys( Commands::COMMANDS ), static fn( $c ) => preg_match( '/refund|pay|issue|code/', $c ) ) );
check( 'A676: an unknown command is refused', 'wpcap_workflow_command_unknown' === $x6k_code( Commands::execute( 'order.refund', array( 'order_id' => 'x' ), $x6k_key( 'u' ) ) ) );
check( 'A676: a command without a valid idempotency key is refused', 'wpcap_workflow_idempotency_key' === $x6k_code( Commands::execute( 'ticket.revoke', array( 'ticket_id' => 'x', 'reason' => 'y' ), 'short' ) ) );
$x6k_r = Commands::execute( 'ticket.revoke', array( 'ticket_id' => str_repeat( 'a', 32 ), 'reason' => 'x', 'refund' => true ), $x6k_key( 'a' ) );
check( 'A676: an argument outside the allowlist is refused and named', 'wpcap_workflow_command_arg_not_allowed' === $x6k_code( $x6k_r ) && 'refund' === ( $x6k_r->get_error_data()['arg'] ?? '' ) );
check( 'A676: a missing required argument is refused', 'wpcap_workflow_command_invalid' === $x6k_code( Commands::execute( 'ticket.revoke', array( 'ticket_id' => str_repeat( 'a', 32 ) ), $x6k_key( 'm' ) ) ) );

$x6k_type = make_type( 10, 10, 2000 );
$x6k_o = Orders::create( array( 'email' => 'x6k@example.com', 'items' => array( array( 'ticket_type_id' => $x6k_type, 'quantity' => 3 ) ), 'idempotency_key' => 'x6k-' . uniqid() ) );
Orders::confirm_manual_payment( $x6k_o['order_id'] );
$x6k_t = tickets( $x6k_o['order_id'] );
$x6k_order = Orders::get( $x6k_o['order_id'] );

$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$x6k_forbidden = Commands::execute( 'ticket.revoke', array( 'ticket_id' => $x6k_t[0]->public_id, 'reason' => 'Doppelt' ), $x6k_key( 'f' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A676: without the command capability nothing happens (403)', 'wpcap_workflow_forbidden' === $x6k_code( $x6k_forbidden ) && 403 === $x6k_forbidden->get_error_data()['status'] && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $x6k_t[0]->ticket_id ) );

$x6k_rk = $x6k_key( 'revoke' );
$x6k_rev = Commands::execute( 'ticket.revoke', array( 'ticket_id' => $x6k_t[0]->public_id, 'reason' => 'Doppelt gebucht' ), $x6k_rk );
$x6k_rev2 = Commands::execute( 'ticket.revoke', array( 'reason' => 'Doppelt gebucht', 'ticket_id' => $x6k_t[0]->public_id ), $x6k_rk );
check( 'A676: ticket.revoke revokes through the admin service', is_array( $x6k_rev ) && false === $x6k_rev['replayed'] && 'revoked' === $x6k_rev['result']['status'] && 'revoked' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $x6k_t[0]->ticket_id ) );
check( 'A676: the same key returns the stored result without running again (argument order does not matter)', is_array( $x6k_rev2 ) && true === $x6k_rev2['replayed'] && $x6k_rev['result'] === $x6k_rev2['result'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Outbox::table() . " WHERE event_type='experience_excluded' AND subject_id=%s", $x6k_t[0]->public_id ) ) );
check( 'A676: the same key with other arguments is a conflict (409)', 'wpcap_workflow_idempotency_conflict' === $x6k_code( Commands::execute( 'ticket.revoke', array( 'ticket_id' => $x6k_t[1]->public_id, 'reason' => 'Doppelt gebucht' ), $x6k_rk ) ) );
$x6k_fk = $x6k_key( 'r2' );
$x6k_again = Commands::execute( 'ticket.revoke', array( 'ticket_id' => $x6k_t[0]->public_id, 'reason' => 'Nochmal' ), $x6k_fk );
$x6k_again2 = Commands::execute( 'ticket.revoke', array( 'ticket_id' => $x6k_t[0]->public_id, 'reason' => 'Nochmal' ), $x6k_fk );
check( 'A676: a refused command (ticket already revoked) is stored as failed under its key and answered the same again', 'wpcap_ticket_not_valid' === $x6k_code( $x6k_again ) && 'wpcap_ticket_not_valid' === $x6k_code( $x6k_again2 ) && ! empty( $x6k_again2->get_error_data()['replayed'] ) );

$x6k_mails = count( $GLOBALS['wpcap_test_mails'] );
$x6k_mk = $x6k_key( 'mail' );
$x6k_mail = Commands::execute( 'mail.resend', array( 'order_id' => $x6k_order->public_id ), $x6k_mk );
$x6k_after = count( $GLOBALS['wpcap_test_mails'] );
Commands::execute( 'mail.resend', array( 'order_id' => $x6k_order->public_id ), $x6k_mk );
check( 'A676: mail.resend sends the ticket mail again (stored codes by default) – a replay sends nothing', is_array( $x6k_mail ) && 'sent' === $x6k_mail['result']['status'] && 'kept' === $x6k_mail['result']['codes'] && $x6k_after > $x6k_mails && count( $GLOBALS['wpcap_test_mails'] ) === $x6k_after );

// attendance.confirm: only once the date has begun; then experience_completed (method confirmed).
$x6k_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x6k_type" );
check( 'A676: attendance.confirm before the start is refused', 'wpcap_workflow_attendance_too_early' === $x6k_code( Commands::execute( 'attendance.confirm', array( 'ticket_id' => $x6k_t[1]->public_id, 'method' => 'list' ), $x6k_key( 'early' ) ) ) );
check( 'A676: an unknown confirmation method is refused', 'wpcap_workflow_command_invalid' === $x6k_code( Commands::execute( 'attendance.confirm', array( 'ticket_id' => $x6k_t[1]->public_id, 'method' => 'guess' ), $x6k_key( 'meth' ) ) ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $x6k_occ ) );
$x6k_conf = Commands::execute( 'attendance.confirm', array( 'ticket_id' => $x6k_t[1]->public_id, 'method' => 'list' ), $x6k_key( 'conf' ) );
$x6k_rv = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . Outbox::table() . " WHERE event_type='experience_completed' AND subject_id=%s", $x6k_t[1]->public_id ) );
check( 'A676: attendance.confirm records the allowed confirmation (review experience_completed with method confirmed)', is_array( $x6k_conf ) && 'confirmed' === $x6k_conf['result']['status'] && $x6k_rv && 'confirmed' === json_decode( $x6k_rv, true )['data']['method'] );
check( 'A676: a revoked ticket cannot be confirmed', 'wpcap_workflow_attendance_not_allowed' === $x6k_code( Commands::execute( 'attendance.confirm', array( 'ticket_id' => $x6k_t[0]->public_id, 'method' => 'manual' ), $x6k_key( 'revc' ) ) ) );

// occurrence.reschedule through the regular occurrence form.
$x6k_new = gmdate( 'Y-m-d\TH:i:00\Z', strtotime( '+20 days 18:00' ) );
check( 'A676: occurrence.reschedule needs ISO 8601 with a time zone', 'wpcap_workflow_command_invalid' === $x6k_code( Commands::execute( 'occurrence.reschedule', array( 'occurrence_id' => $x6k_occ, 'starts_at' => '2027-05-01 19:30' ), $x6k_key( 'tz' ) ) ) );
$x6k_res = Commands::execute( 'occurrence.reschedule', array( 'occurrence_id' => $x6k_occ, 'starts_at' => $x6k_new ), $x6k_key( 'resched' ) );
check( 'A676: occurrence.reschedule moves the date (and reports the new start)', is_array( $x6k_res ) && $x6k_new === $x6k_res['result']['starts_at'] && gmdate( 'Y-m-d H:i:s', strtotime( $x6k_new ) ) === $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$x6k_occ" ) );

// event.cancel through the regular cancellation.
$x6k_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$x6k_occ" );
$x6k_cancel = Commands::execute( 'event.cancel', array( 'event_id' => $x6k_event, 'reason' => 'Unwetter' ), $x6k_key( 'cancel' ) );
check( 'A676: event.cancel cancels the event through the regular flow', is_array( $x6k_cancel ) && 'cancelled' === $x6k_cancel['result']['status'] && 'cancelled' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_events WHERE event_id=$x6k_event" ) );

$x6k_blob = wp_json_encode( array( $x6k_rev, $x6k_mail, $x6k_conf, $x6k_res, $x6k_cancel ) );
check( 'A676: results contain no ticket codes and no personal data', ! preg_match( '/[a-f0-9]{64}/', $x6k_blob ) && false === strpos( $x6k_blob, '@' ) );
$x6k_audit = $wpdb->get_col( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='workflow_command' ORDER BY audit_id DESC LIMIT 30" );
check( 'A676: every executed command is audited with command, key hash and result (no key in clear, no PII)', count( $x6k_audit ) >= 8 && ! array_filter( $x6k_audit, static fn( $d ) => false !== strpos( $d, 'x6k-' ) || false !== strpos( $d, '@' ) ) && (bool) array_filter( $x6k_audit, static fn( $d ) => false !== strpos( $d, '"result":"forbidden"' ) ) );
$GLOBALS['wpcap_test_hooks'] = $x6k_hooks_before;
