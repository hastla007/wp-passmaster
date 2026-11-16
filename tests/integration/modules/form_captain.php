<?php
/**
 * Form Captain adapter (PASS-FC-01..04): inactive without Form Captain, contract major check,
 * registration of the context purpose pass.event_registration, issue/submit check (published,
 * not cancelled, assigned form), accepted purposes only for assigned forms, event ⇄ form storage
 * with validation, event card output and the guarantee that a submission never books anything.
 * Included by run.php. Uses a stand-in for the Form Captain functions (form_captain/fake-contract.php);
 * signature and verification against the real Form Captain: tests/form-captain/.
 */

// phpcs:ignoreFile

use PassCaptain\Integrations\FormCaptain\Adapter;
use PassCaptain\Integrations\FormCaptain\EventForms;
use PassCaptain\Modules\FormCaptain;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; } }

global $wpdb;
$p = $wpdb->prefix;

/** Creates a published event with one scheduled date; returns the event ID. */
function fc_event( $status = 'published' ) {
	global $wpdb;
	$type = make_type( 10, 10, 0 );
	$event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=" . (int) $type );
	if ( 'published' !== $status ) { $wpdb->update( "{$wpdb->prefix}wpcap_events", array( 'status' => $status ), array( 'event_id' => $event ) ); }
	return $event;
}
function fc_card( $event_id ) {
	return '<article class="wpcap-event"><h2>E</h2><form class="wpcap-checkout-form"></form></article>';
}
function fc_counts() {
	global $wpdb;
	$p = $wpdb->prefix;
	return array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets" ) );
}

echo "Form Captain adapter: without Form Captain\n";
Adapter::reset();
FormCaptain::register();
check( 'module schema creates the event form table', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$p}wpcap_event_forms'" ) );
check( 'module registers the admin-post handler', isset( FormCaptain::admin_handlers()[ EventForms::ACTION ] ) );
check( 'purpose registration waits for form_captain_loaded_v1', ! empty( $GLOBALS['wpcap_test_hooks']['form_captain_loaded_v1'] ) );
do_action( 'form_captain_loaded_v1', '1.1.0' );
$ev = fc_event();
check( 'without Form Captain the adapter stays inactive', 'missing' === Adapter::state() && ! Adapter::ready() );
check( 'without Form Captain no form is rendered and the card is unchanged', fc_card( $ev ) === Adapter::append_to_card( fc_card( $ev ), (object) array( 'event_id' => $ev, 'occurrence_id' => 1 ), array() ) );
$r = EventForms::save( array( 'event_id' => $ev, 'form' => '7' ) );
check( 'assigning a form needs Form Captain', is_wp_error( $r ) && 'wpcap_form_captain_unavailable' === $r->get_error_code() );
check( 'accepted purposes untouched while inactive', array() === Adapter::accepted_purposes( array(), 7 ) );
ob_start(); EventForms::render_section( $wpdb->get_row( "SELECT * FROM {$p}wpcap_events WHERE event_id=$ev" ), true ); $html = ob_get_clean();
check( 'event screen shows nothing without Form Captain and without assignment', '' === $html );

require __DIR__ . '/form_captain/fake-contract.php';
$GLOBALS['fcfake']['forms'] = array(
	array( 'id' => 7, 'public_id' => 'a1b2c3d4e5f6g7h8', 'title' => 'Anfrage', 'status' => 'published', 'version' => 1, 'fields' => array() ),
	array( 'id' => 8, 'public_id' => 'zzzzzzzzzzzzzzz8', 'title' => 'Entwurf', 'status' => 'draft', 'version' => 0, 'fields' => array() ),
	array( 'id' => 9, 'public_id' => 'b1b2c3d4e5f6g7h9', 'title' => 'Andere', 'status' => 'published', 'version' => 1, 'fields' => array() ),
);

echo "Form Captain adapter: contract version\n";
$GLOBALS['fcfake']['version'] = '2.0.0';
Adapter::reset();
Adapter::on_loaded();
check( 'other contract major: nothing registered', 'incompatible' === Adapter::state() && ! $GLOBALS['fcfake']['registered'] );
ob_start(); EventForms::render_section( $wpdb->get_row( "SELECT * FROM {$p}wpcap_events WHERE event_id=$ev" ), true ); $html = ob_get_clean();
check( 'event screen reports the incompatible contract', false !== strpos( $html, 'notice-warning' ) && false === strpos( $html, '<form' ) );

$GLOBALS['fcfake']['version'] = '1.1.0';
Adapter::reset();
Adapter::on_loaded();
$def = $GLOBALS['fcfake']['purposes'][ Adapter::PURPOSE ] ?? array();
check( 'compatible contract: purpose pass.event_registration registered', Adapter::ready() && array( 'pass.event_registration' ) === $GLOBALS['fcfake']['registered'] );
check( 'purpose definition: owner pass, contract ^1.0, ref type event', 'pass' === $def['owner'] && '^1.0' === $def['contract'] && array( 'event' ) === $def['ref_types'] );
check( 'purpose definition: public_endpoint, reusable, TTL 12 h / 24 h', 'public_endpoint' === $def['issuance'] && false === $def['single_use'] && 43200 === $def['default_ttl'] && 86400 === $def['max_ttl'] );
check( 'purpose definition: issue and submit check, marks_verified', is_callable( $def['issue_check'] ) && is_callable( $def['submit_check'] ) && true === $def['marks_verified'] );
Adapter::on_loaded();
check( 'second loaded call does not register twice', 1 === count( $GLOBALS['fcfake']['registered'] ) );

echo "Form Captain adapter: event form assignment\n";
$r = EventForms::save( array( 'event_id' => $ev, 'form' => 'zzzzzzzzzzzzzzz8' ) );
check( 'draft form is rejected', is_wp_error( $r ) && 'wpcap_form_not_published' === $r->get_error_code() && null === EventForms::get( $ev ) );
$r = EventForms::save( array( 'event_id' => $ev, 'form' => '404' ) );
check( 'unknown form is rejected', is_wp_error( $r ) && 'wpcap_form_missing' === $r->get_error_code() );
$r = EventForms::save( array( 'event_id' => $ev, 'form' => 'a b<script>' ) );
check( 'malformed form reference is rejected', is_wp_error( $r ) && 'wpcap_form_invalid' === $r->get_error_code() );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$r = EventForms::save( array( 'event_id' => $ev, 'form' => '7' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'assignment needs the event capability', is_wp_error( $r ) && 'wpcap_admin_forbidden' === $r->get_error_code() );
$r = EventForms::save( array( 'event_id' => $ev, 'form' => 'a1b2c3d4e5f6g7h8' ) );
$stored = EventForms::get( $ev );
check( 'published form assigned by public ID, stored with numeric ID and title', ! is_wp_error( $r ) && 7 === $stored['form_id'] && 'Anfrage' === $stored['form_title'] && 'a1b2c3d4e5f6g7h8' === $stored['form_public_id'] );
check( 'assignment is audited', (bool) $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='event_form_assigned' AND object_id=$ev" ) );
$r = EventForms::save( array( 'event_id' => $ev, 'form' => '7' ) );
check( 'saving again by numeric ID keeps one row', ! is_wp_error( $r ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_event_forms WHERE event_id=$ev" ) );
ob_start(); EventForms::render_section( $wpdb->get_row( "SELECT * FROM {$p}wpcap_events WHERE event_id=$ev" ), true ); $html = ob_get_clean();
check( 'event screen shows the assigned form and the field', false !== strpos( $html, 'Anfrage' ) && false !== strpos( $html, 'name="form"' ) && false !== strpos( $html, EventForms::ACTION ) );

echo "Form Captain adapter: accepted purposes and reference check\n";
check( 'purpose accepted for the assigned form', array( 'other.purpose', Adapter::PURPOSE ) === Adapter::accepted_purposes( array( 'other.purpose' ), 7 ) );
check( 'purpose not added for a form without event', array() === Adapter::accepted_purposes( array(), 9 ) );
check( 'purpose not duplicated', array( Adapter::PURPOSE ) === Adapter::accepted_purposes( array( Adapter::PURPOSE ), 7 ) );
check( 'reference check: published event with its form', true === Adapter::check_reference( 'event', (string) $ev, 7 ) );
check( 'reference check: other form rejected', false === Adapter::check_reference( 'event', (string) $ev, 9 ) );
check( 'reference check: wrong ref type / non-numeric id rejected', ! Adapter::check_reference( 'order', (string) $ev, 7 ) && ! Adapter::check_reference( 'event', $ev . 'x', 7 ) && ! Adapter::check_reference( 'event', '0', 7 ) );
$draft = fc_event( 'draft' );
$wpdb->insert( "{$p}wpcap_event_forms", array( 'event_id' => $draft, 'form_id' => 7, 'form_public_id' => '', 'form_title' => '', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
EventForms::flush_cache();
check( 'reference check: draft event rejected', false === Adapter::check_reference( 'event', (string) $draft, 7 ) );
$signed = form_captain_sign_context_v1( Adapter::PURPOSE, 7, 'event', (string) $ev );
check( 'Form Captain signs a context for the assigned form', ! empty( $signed['success'] ) );
$signed = form_captain_sign_context_v1( Adapter::PURPOSE, 9, 'event', (string) $ev );
check( 'no context for a form without assignment', 'context_purpose_not_allowed' === $signed['code'] );

echo "Form Captain adapter: event page\n";
$GLOBALS['fcfake']['renders'] = array();
$event_row = (object) array( 'event_id' => $ev, 'occurrence_id' => 1 );
$card = Adapter::append_to_card( fc_card( $ev ), $event_row, array() );
$render = $GLOBALS['fcfake']['renders'][0] ?? array();
check( 'assigned form rendered inside the card after the checkout form', str_ends_with( $card, '</form></section></article>' ) && false !== strpos( $card, 'wpcap-checkout-form"></form><section class="wpcap-event-form"' ) && false !== strpos( $card, '<form class="fc-form"' ) );
check( 'render gets context_ref event:<id> for the public_endpoint route', array( 'purpose' => Adapter::PURPOSE, 'ref_type' => 'event', 'ref_id' => (string) $ev ) === ( $render['args']['context_ref'] ?? null ) && 7 === $render['form_id'] );
check( 'render gets a fallback context signed with the maximum TTL', 0 === strpos( (string) ( $render['args']['context'] ?? '' ), 'fakectx.' ) && 86400 === end( $GLOBALS['fcfake']['signs'] )['ttl'] );
$card2 = Adapter::append_to_card( fc_card( $ev ), (object) array( 'event_id' => $ev, 'occurrence_id' => 2 ), array() );
check( 'second date of the same event: form only once per page', fc_card( $ev ) === $card2 );
$plain = fc_event();
check( 'event without form: card unchanged', fc_card( $plain ) === Adapter::append_to_card( fc_card( $plain ), (object) array( 'event_id' => $plain, 'occurrence_id' => 3 ), array() ) );

echo "Form Captain adapter: cancelled event\n";
$cancel = fc_event();
check( 'assign form to event that is cancelled later', ! is_wp_error( EventForms::save( array( 'event_id' => $cancel, 'form' => '7' ) ) ) );
check( 'before cancellation the reference is valid', true === Adapter::check_reference( 'event', (string) $cancel, 7 ) );
Orders::cancel_event( $cancel );
check( 'cancelled event: issue/submit check rejects (Form Captain answers context_ref_rejected)', false === Adapter::check_reference( 'event', (string) $cancel, 7 ) );
$signed = form_captain_sign_context_v1( Adapter::PURPOSE, 7, 'event', (string) $cancel );
check( 'cancelled event: no context is signed', 'context_ref_rejected' === $signed['code'] );
check( 'cancelled event: no form on the event page', '' === Adapter::render_for_event( $cancel ) );
$r = EventForms::save( array( 'event_id' => $cancel, 'form' => '9' ) );
check( 'cancelled event: assignment can no longer be changed', is_wp_error( $r ) && 'wpcap_event_cancelled' === $r->get_error_code() );
$occ_only = fc_event();
EventForms::save( array( 'event_id' => $occ_only, 'form' => '7' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'event_id' => $occ_only ) );
check( 'event whose dates are all cancelled is rejected', false === Adapter::check_reference( 'event', (string) $occ_only, 7 ) );

echo "Form Captain adapter: a submission never books\n";
$before = fc_counts();
do_action( 'form_captain_entry_created_v1', array( 'contract' => '1.0.0', 'entry_id' => 1, 'form_id' => 7, 'context' => array( 'purpose' => Adapter::PURPOSE, 'ref_type' => 'event', 'ref_id' => (string) $ev, 'verified' => true ), 'values' => array() ) );
check( 'no listener on Form Captain entry events and no form action registered', empty( $GLOBALS['wpcap_test_hooks']['form_captain_entry_created_v1'] ) && ! $GLOBALS['fcfake']['actions'] );
check( 'entry event creates no order, reservation or ticket', $before === fc_counts() );

echo "Form Captain adapter: removing the assignment\n";
$r = EventForms::save( array( 'event_id' => $ev, 'form' => '' ) );
check( 'empty field removes the assignment', ! is_wp_error( $r ) && 0 === $r['form_id'] && null === EventForms::get( $ev ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_forms WHERE form_id=7" );
EventForms::flush_cache();
check( 'form no longer accepts the purpose once unassigned everywhere', array() === Adapter::accepted_purposes( array(), 7 ) );
