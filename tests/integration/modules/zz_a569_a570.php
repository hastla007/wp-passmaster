<?php
/**
 * A569: a change of the attendee name to another person counts as a holder change (limit
 * wpcap_max_holder_changes), every name change is counted on the ticket (limit
 * wpcap_max_attendee_name_changes) – counters on wpcap_tickets survive an audit-log purge.
 * A570: after a transfer the current holder sets name and answers on the ticket page.
 * Included from run.php (uses check(), make_type(), tickets()).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\TicketAssignments;
use PassCaptain\TicketPage;

echo "A569/A570: personalisation limits and attendee data of the holder\n";
global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$a569_settings = array_intersect_key( PassCaptain\Settings::all(), array_flip( array( 'attendee_edit_hours' ) ) );
PassCaptain\Settings::update( array( 'attendee_edit_hours' => 24 ) );
PassCaptain\ServiceBookings::flush_schema_cache();

$a569_type = make_type( 20, 10, 0 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a569_type, 'field_key' => 'company', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$a569_field = (int) $wpdb->insert_id;
$a569_o = Orders::create( array( 'email' => 'a569@example.com', 'items' => array( array( 'ticket_type_id' => $a569_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a569-' . uniqid() ) );
$a569_t = tickets( $a569_o['order_id'] );
$a569_token = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $a569_token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $a569_o['order_id'] ) );
$a569_public = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=" . (int) $a569_o['order_id'] );
$a569_assign = static function ( $ticket, $name, $email = '' ) use ( $a569_public, $a569_token ) { return TicketAssignments::assign( $a569_public, $a569_token, $ticket->public_id, $name, $email ); };
$a569_err = static function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; };
$a569_hash = static function ( $ticket_id ) { global $wpdb; return (string) $wpdb->get_var( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id ); };

check( 'A569: schema 3.10.18 counters wpcap_tickets.holder_changes/name_changes exist', TicketAssignments::counters_ready() );
check( 'A569: correction rule (first name, spelling, typo = correction; other name = other person)', TicketAssignments::is_name_correction( '', 'Anna' ) && TicketAssignments::is_name_correction( 'Anna Beispiel', 'beispiel  anna' ) && TicketAssignments::is_name_correction( 'Anna Beispiel', 'Anna Beispeil' ) && TicketAssignments::is_name_correction( 'Jörg Müller', 'Jörg Mueller' ) && TicketAssignments::is_name_correction( 'Carol', 'Carol Weber' ) && ! TicketAssignments::is_name_correction( 'Anna Beispiel', 'Bernd Käufer' ) && ! TicketAssignments::is_name_correction( 'Anna Beispiel', 'Anna Schmidt' ) );

$t0 = $a569_t[0];
$h0 = $a569_hash( $t0->ticket_id );
$r = array(
	$a569_err( $a569_assign( $t0, 'Anna Beispiel' ) ),  // first name: free
	$a569_err( $a569_assign( $t0, 'Anna Beispeil' ) ),  // typo: correction (name change 1)
	$a569_err( $a569_assign( $t0, 'Bernd Käufer' ) ),   // other person (holder change 1, name change 2)
);
check( 'A569: first name, correction and one change of person are accepted (code unchanged)', array( 'ok', 'ok', 'ok' ) === $r && $h0 === $a569_hash( $t0->ticket_id ) );
check( 'A569: a second change of person by name only is refused', 'wpcap_assignment_limit' === $a569_err( $a569_assign( $t0, 'Clara Dritte' ) ) && 'Bernd Käufer' === (string) $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=" . (int) $t0->ticket_id ) );
check( 'A569: corrections stay possible, but every name change is counted (limit 3)', 'ok' === $a569_err( $a569_assign( $t0, 'Bernd Kaufer' ) ) && 'wpcap_assignment_name_limit' === $a569_err( $a569_assign( $t0, 'Bernd Kaeufer' ) ) );
$a569_counts = $wpdb->get_row( "SELECT holder_changes,name_changes FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $t0->ticket_id );
check( 'A569: counters on the ticket (1 holder change, 3 name changes) and audit entries', 1 === (int) $a569_counts->holder_changes && 3 === (int) $a569_counts->name_changes && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='ticket_attendee_renamed' AND object_id=" . (int) $t0->ticket_id ) );
$wpdb->query( "DELETE FROM {$p}wpcap_audit_logs WHERE object_type='ticket' AND object_id=" . (int) $t0->ticket_id . " AND action IN ('ticket_holder_changed','ticket_attendee_renamed')" );
$GLOBALS['wpcap_test_filters']['wpcap_max_attendee_name_changes'] = 10; // isolate the holder limit
$a569_purged = $a569_err( $a569_assign( $t0, 'Clara Dritte' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_max_attendee_name_changes'] );
check( 'A569: an audit-log purge does not reset the limits', 1 === TicketAssignments::holder_changes( (int) $t0->ticket_id ) && 3 === TicketAssignments::name_changes( (int) $t0->ticket_id ) && 'wpcap_assignment_limit' === $a569_purged );
check( 'A569: the name-based holder change also blocks a transfer', is_wp_error( Orders::transfer_ticket( $a569_public, $a569_token, $t0->public_id, 'friend@example.com' ) ) );
// E-mail change counts once even when the name changes in the same step.
$t1 = $a569_t[1];
$a569_assign( $t1, 'Dora Erst' );
check( 'A569: e-mail change with a new name = one holder change', 'ok' === $a569_err( $a569_assign( $t1, 'Emil Zweit', 'emil@example.com' ) ) && 1 === TicketAssignments::holder_changes( (int) $t1->ticket_id ) );
// Upgrade: holder changes from the audit log are carried over.
$wpdb->query( "UPDATE {$p}wpcap_tickets SET holder_changes=0 WHERE ticket_id=" . (int) $t1->ticket_id );
PassCaptain\Installer::backfill_holder_changes( '3.10.17' );
check( 'A569: upgrade carries audit-logged holder changes over to the counter', 1 === (int) $wpdb->get_var( "SELECT holder_changes FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $t1->ticket_id ) && false === PassCaptain\Installer::backfill_holder_changes( '3.10.18' ) );

// --- A570: the recipient of a transfer personalises the ticket on the ticket page ---
$t2 = $a569_t[2];
$a569_tr = Orders::transfer_ticket( $a569_public, $a569_token, $t2->public_id, 'rita@example.com' );
$a569_code = '';
foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
	if ( 'rita@example.com' === $mail['to'] && preg_match( '/wpcap_ticket=([a-f0-9]{64})/', $mail['body'], $m ) ) { $a569_code = $m[1]; break; }
}
check( 'A570 fixture: transferred, recipient got the code', ! is_wp_error( $a569_tr ) && 64 === strlen( $a569_code ) && hash( 'sha256', $a569_code ) === $a569_hash( $t2->ticket_id ) );
check( 'A570: the buyer can no longer personalise the transferred ticket', 'wpcap_assignment_transferred' === $a569_err( $a569_assign( $t2, 'Käufer Selbst' ) ) );
$a569_row = (object) array( 'ticket_id' => (int) $t2->ticket_id, 'ticket_type_id' => $a569_type, 'status' => 'valid', 'starts_at' => (string) $wpdb->get_var( "SELECT o.starts_at FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id WHERE tt.ticket_type_id=$a569_type" ) );
$a569_html = TicketPage::attendee_html( $a569_row );
check( 'A570: the ticket page offers the attendee form to the holder (name, fields, nonce)', false !== strpos( $a569_html, 'name="attendee_name"' ) && false !== strpos( $a569_html, 'wpcap_answer[company]' ) && false !== strpos( $a569_html, 'wpcap_attendee_op' ) );
$a569_h2 = $a569_hash( $t2->ticket_id );
check( 'A570: a stale form (wrong nonce) is refused', is_wp_error( TicketPage::handle_attendee( $a569_row, $a569_code, array( '_wpnonce' => 'x', 'attendee_name' => 'Rita' ) ) ) );
$a569_saved = TicketPage::handle_attendee( $a569_row, $a569_code, array( '_wpnonce' => 'n', 'attendee_name' => 'Rita Empfängerin', 'wpcap_answer' => array( 'company' => 'Rita GmbH' ) ) );
check( 'A570: the holder saves name and answers; the code stays', 'saved' === $a569_saved && 'Rita Empfängerin' === (string) $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=" . (int) $t2->ticket_id ) && 'Rita GmbH' === (string) $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . (int) $t2->ticket_id . " AND field_id=$a569_field" ) && $a569_h2 === $a569_hash( $t2->ticket_id ) );
check( 'A570: unchanged data is reported as unchanged', 'unchanged' === TicketPage::handle_attendee( $a569_row, $a569_code, array( '_wpnonce' => 'n', 'attendee_name' => 'Rita Empfängerin', 'wpcap_answer' => array( 'company' => 'Rita GmbH' ) ) ) );
check( 'A570: a correction is accepted, another person is not (the transfer was the holder change)', 'saved' === TicketPage::handle_attendee( $a569_row, $a569_code, array( '_wpnonce' => 'n', 'attendee_name' => 'Rita Empfaengerin' ) ) && 'wpcap_assignment_limit' === $a569_err( TicketAssignments::update_by_holder( $a569_code, 'Otto Weiterverkauf' ) ) );
check( 'A570: the old code of the buyer cannot be used', 'wpcap_assignment_not_allowed' === $a569_err( TicketAssignments::update_by_holder( str_repeat( 'a', 64 ), 'X Y' ) ) );
// Deadline: attendee_edit_hours before the start.
$a569_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a569_type" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'occurrence_id' => $a569_occ ) );
$a569_row->starts_at = gmdate( 'Y-m-d H:i:s', time() + 3600 );
check( 'A570: after the deadline no form and no change', '' === TicketPage::attendee_html( $a569_row ) && 'wpcap_assignment_not_allowed' === $a569_err( TicketAssignments::update_by_holder( $a569_code, 'Rita Empfängerin' ) ) );
check( 'A570: a refused change shows its reason on the ticket page', false !== strpos( TicketPage::attendee_html( $a569_row, 'wpcap_assignment_not_allowed' ), 'nicht mehr geändert' ) );

PassCaptain\Settings::update( $a569_settings );
