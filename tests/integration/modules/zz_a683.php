<?php
/**
 * A683: self-service per participant field – a field can be locked for buyers/holders or have its
 * own deadline (hours before the start). The ticket page and the order portal show such fields read
 * only, and a changed value is refused on the server; admin corrections stay possible.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Orders;
use PassCaptain\ParticipantFields;
use PassCaptain\TicketAssignments;
use PassCaptain\TicketPage;

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }

echo "A683: locked fields and deadlines per field in the self-service\n";
check( 'A683: the schema has self_service and edit_hours per field', ParticipantFields::locks_ready() );
$x6l_type = make_type( 10, 10, 0 );
// Starts in 30 hours: the general deadline (24 h) still allows changes, a 48-hour field deadline does not.
$x6l_start = gmdate( 'Y-m-d H:i:s', time() + 30 * HOUR_IN_SECONDS );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id SET o.starts_at=%s WHERE tt.ticket_type_id=%d", $x6l_start, $x6l_type ) );
$x6l_save = static function ( array $in ) use ( $x6l_type ) { return EventsPage::save_participant_field( $in + array( 'ticket_type_id' => $x6l_type, 'field_type' => 'text', 'retention_days' => 30 ) ); };
$x6l_too_long = $x6l_save( array( 'field_key' => 'x', 'label' => 'X', 'edit_hours' => 9000 ) );
check( 'A683: a field deadline above one year is refused', is_wp_error( $x6l_too_long ) && 'wpcap_field_edit_hours' === $x6l_too_long->get_error_code() );
$x6l_shirt = $x6l_save( array( 'field_key' => 'shirt', 'label' => 'T-Shirt' ) );
$x6l_club = $x6l_save( array( 'field_key' => 'club', 'label' => 'Verein', 'self_service' => 'locked', 'required' => '1' ) );
$x6l_diet = $x6l_save( array( 'field_key' => 'diet', 'label' => 'Essen', 'edit_hours' => 48 ) );
$x6l_row = $wpdb->get_row( $wpdb->prepare( "SELECT self_service,edit_hours FROM {$p}wpcap_participant_fields WHERE field_id=%d", $x6l_diet['field_id'] ?? 0 ) );
check( 'A683: lock and deadline are stored per field', 'locked' === $wpdb->get_var( $wpdb->prepare( "SELECT self_service FROM {$p}wpcap_participant_fields WHERE field_id=%d", $x6l_club['field_id'] ?? 0 ) ) && $x6l_row && 'edit' === $x6l_row->self_service && 48 === (int) $x6l_row->edit_hours );
check( 'A683: locked_keys() names the locked field and the field past its own deadline', array( 'club', 'diet' ) === array_values( array_intersect( array( 'shirt', 'club', 'diet' ), ParticipantFields::locked_keys( $x6l_type, $x6l_start ) ) ) && array( 'club' ) === ParticipantFields::locked_keys( $x6l_type, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );

$x6l_mails = count( $GLOBALS['wpcap_test_mails'] );
$x6l_o = Orders::create( array( 'email' => 'x6l@example.com', 'items' => array( array( 'ticket_type_id' => $x6l_type, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $x6l_type, 'name' => 'Ada', 'answers' => array( 'shirt' => 'M', 'club' => 'SV Linz', 'diet' => 'vegan' ) ) ), 'idempotency_key' => 'x6l-' . uniqid() ) );
$x6l_code = '';
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $x6l_mails ) as $x6l_m ) { if ( 'x6l@example.com' === $x6l_m['to'] && preg_match( '/wpcap_ticket=([a-f0-9]{64})/', $x6l_m['body'], $x6l_mm ) ) { $x6l_code = $x6l_mm[1]; } }
$x6l_ticket = tickets( $x6l_o['order_id'] )[0];
$x6l_answer = static function ( $field ) use ( $wpdb, $p, $x6l_ticket ) { return (string) $wpdb->get_var( $wpdb->prepare( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=%d AND field_id=%d", $x6l_ticket->ticket_id, $field['field_id'] ) ); };
check( 'A683 fixture: free ticket issued, holder code known, answers stored', 64 === strlen( $x6l_code ) && 'SV Linz' === $x6l_answer( $x6l_club ) );

$x6l_r = TicketAssignments::update_by_holder( $x6l_code, 'Ada', array( 'shirt' => 'M', 'club' => 'FC Wels', 'diet' => 'vegan' ) );
check( 'A683: a changed locked field is refused (403, field named) and nothing changes', is_wp_error( $x6l_r ) && 'wpcap_attendee_field_locked' === $x6l_r->get_error_code() && 'club' === ( $x6l_r->get_error_data()['field_key'] ?? '' ) && 403 === ( $x6l_r->get_error_data()['status'] ?? 0 ) && 'SV Linz' === $x6l_answer( $x6l_club ) );
$x6l_r = TicketAssignments::update_by_holder( $x6l_code, 'Ada', array( 'shirt' => 'M', 'diet' => 'Fisch' ) );
check( 'A683: a field past its own deadline is refused although the form is still open', is_wp_error( $x6l_r ) && 'diet' === ( $x6l_r->get_error_data()['field_key'] ?? '' ) && 'vegan' === $x6l_answer( $x6l_diet ) );
$x6l_r = TicketAssignments::update_by_holder( $x6l_code, 'Ada', array( 'shirt' => 'L', 'club' => 'SV Linz' ) );
check( 'A683: the other fields can still be changed; a locked field sent unchanged (or not at all) stays as stored', is_array( $x6l_r ) && 'saved' === $x6l_r['status'] && 'L' === $x6l_answer( $x6l_shirt ) && 'SV Linz' === $x6l_answer( $x6l_club ) && 'vegan' === $x6l_answer( $x6l_diet ) );

$x6l_html = TicketPage::attendee_html( (object) array( 'ticket_id' => (int) $x6l_ticket->ticket_id, 'ticket_type_id' => $x6l_type, 'status' => 'valid', 'starts_at' => $x6l_start ) );
check( 'A683: the ticket page shows locked fields read only (no input) and the others as inputs', false !== strpos( $x6l_html, 'wpcap_answer[shirt]' ) && false === strpos( $x6l_html, 'wpcap_answer[club]' ) && false === strpos( $x6l_html, 'wpcap_answer[diet]' ) && false !== strpos( $x6l_html, 'SV Linz' ) && false !== strpos( $x6l_html, '(nicht mehr änderbar)' ) );

// The team can still correct locked fields in the admin (edits as before, no self-service rules).
$x6l_admin = PassCaptain\Admin\EventsPage::save_participant_field( array( 'field_id' => $x6l_club['field_id'], 'label' => 'Verein', 'field_type' => 'text', 'self_service' => 'edit', 'required' => '1', 'retention_days' => 30 ) );
check( 'A683: unlocking in the admin makes the field changeable again', is_array( $x6l_admin ) && ! in_array( 'club', ParticipantFields::locked_keys( $x6l_type, $x6l_start ), true ) );
