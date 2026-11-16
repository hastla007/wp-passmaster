<?php
// phpcs:ignoreFile
/**
 * A532: admin ticket actions (revoke, undo check-in, correct attendee) check every write and the
 * COMMIT: a failed step rolls back and is reported as an error – never "done" with nothing saved.
 * Database failures are injected (Test_WPDB fault rules).
 */
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;

echo "A532: admin ticket actions report only checked writes\n";
global $wpdb;
$p = $wpdb->prefix;
$a532_type = make_type( 10, 10, 0 );
$a532_r = Orders::create( array( 'email' => 'a532@example.com', 'items' => array( array( 'ticket_type_id' => $a532_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a532-' . uniqid() ) );
$a532_t = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a532_r['order_id'] ) );
$a532_status = static fn( $id ) => (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=%d", $id ) );
$a532_audits = static fn( $action, $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", $action, $id ) );

// Revoke: the UPDATE fails → "busy", not "only valid tickets", nothing audited.
wpcap_test_fault( "/UPDATE \\S*wpcap_tickets SET status='revoked'/", 'lock_timeout', array( 'rollback' => true ) );
$a532_rev = TicketsPage::revoke_ticket( array( 'ticket_id' => $a532_t[0], 'reason' => 'Test' ) );
wpcap_test_faults_clear();
check( 'A532: a failed revoke write is reported as a database error and rolled back', is_wp_error( $a532_rev ) && 'wpcap_db_busy' === $a532_rev->get_error_code() && 'valid' === $a532_status( $a532_t[0] ) && 0 === $a532_audits( 'ticket_revoked', $a532_t[0] ) );
wpcap_test_fault( '/^COMMIT/', 'lost' );
$a532_rev = TicketsPage::revoke_ticket( array( 'ticket_id' => $a532_t[0], 'reason' => 'Test' ) );
wpcap_test_faults_clear();
check( 'A532: a lost COMMIT of a revoke is an error, the ticket stays valid', is_wp_error( $a532_rev ) && 'valid' === $a532_status( $a532_t[0] ) && 0 === $a532_audits( 'ticket_revoked', $a532_t[0] ) );
$a532_rev = TicketsPage::revoke_ticket( array( 'ticket_id' => $a532_t[0], 'reason' => 'Test' ) );
check( 'A532: without a fault the revoke is done and audited', ! is_wp_error( $a532_rev ) && 'revoked' === $a532_status( $a532_t[0] ) && 1 === $a532_audits( 'ticket_revoked', $a532_t[0] ) );

// Undo check-in: the log row or the COMMIT fails → error, ticket stays checked in.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=%d", $a532_t[1] ) );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $a532_t[1], 'event_id' => 0, 'occurrence_day_id' => 0, 'user_id' => 1, 'result' => 'checked_in', 'device_id' => 0, 'source' => 'admin', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
wpcap_test_fault( "/INSERT INTO \\S*wpcap_checkins/", 'lock_timeout', array( 'rollback' => true ) );
$a532_undo = TicketsPage::undo_checkin( array( 'ticket_id' => $a532_t[1], 'reason' => 'Falsch gescannt' ) );
wpcap_test_faults_clear();
check( 'A532: a failed undo log write rolls back and reports an error', is_wp_error( $a532_undo ) && 'wpcap_db_busy' === $a532_undo->get_error_code() && 'checked_in' === $a532_status( $a532_t[1] ) && 0 === $a532_audits( 'checkin_undone', $a532_t[1] ) );
wpcap_test_fault( '/^COMMIT/', 'lost' );
$a532_undo = TicketsPage::undo_checkin( array( 'ticket_id' => $a532_t[1], 'reason' => 'Falsch gescannt' ) );
wpcap_test_faults_clear();
check( 'A532: a lost COMMIT of an undo is an error, the ticket stays checked in', is_wp_error( $a532_undo ) && 'checked_in' === $a532_status( $a532_t[1] ) && 0 === $a532_audits( 'checkin_undone', $a532_t[1] ) );
$a532_undo = TicketsPage::undo_checkin( array( 'ticket_id' => $a532_t[1], 'reason' => 'Falsch gescannt' ) );
check( 'A532: without a fault the check-in is undone', ! is_wp_error( $a532_undo ) && 'valid' === $a532_status( $a532_t[1] ) && 1 === $a532_audits( 'checkin_undone', $a532_t[1] ) );

// Correct attendee: a lost COMMIT must not report "corrected".
$a532_email = static fn() => (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a532_t[2] ) );
$a532_before = $a532_email();
wpcap_test_fault( '/^COMMIT/', 'lost' );
$a532_fix = TicketsPage::correct_attendee( array( 'ticket_id' => $a532_t[2], 'attendee_name' => 'Neue Person', 'attendee_email' => 'neu-a532@example.com', 'reason' => 'Tippfehler' ) );
wpcap_test_faults_clear();
check( 'A532: a lost COMMIT of an attendee correction is reported as failed, nothing changed or audited', is_wp_error( $a532_fix ) && 'wpcap_correction_failed' === $a532_fix->get_error_code() && $a532_before === $a532_email() && 0 === $a532_audits( 'record_corrected', $a532_t[2] ) );
$a532_fix = TicketsPage::correct_attendee( array( 'ticket_id' => $a532_t[2], 'attendee_name' => 'Neue Person', 'attendee_email' => 'neu-a532@example.com', 'reason' => 'Tippfehler' ) );
check( 'A532: without a fault the correction is saved', ! is_wp_error( $a532_fix ) && ! empty( $a532_fix['changed'] ) && 'neu-a532@example.com' === $a532_email() );
