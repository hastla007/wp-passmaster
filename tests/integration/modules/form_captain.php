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
require __DIR__ . '/form_captain/fake-interface.php';
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
check( 'no listener on Form Captain entry events; the form action exists only through the opt-in connector', empty( $GLOBALS['wpcap_test_hooks']['form_captain_entry_created_v1'] ) && ! $GLOBALS['fcfake']['actions'] );
check( 'entry event creates no order, reservation or ticket', $before === fc_counts() );

echo "Form Captain adapter: removing the assignment\n";
$r = EventForms::save( array( 'event_id' => $ev, 'form' => '' ) );
check( 'empty field removes the assignment', ! is_wp_error( $r ) && 0 === $r['form_id'] && null === EventForms::get( $ev ) );
$wpdb->query( "DELETE FROM {$p}wpcap_event_forms WHERE form_id=7" );
EventForms::flush_cache();
check( 'form no longer accepts the purpose once unassigned everywhere', array() === Adapter::accepted_purposes( array(), 7 ) );

echo "Form Captain adapter: explicit hand-over (PASS-FC-05)\n";
use PassCaptain\Integrations\FormCaptain\Connector;
use PassCaptain\Integrations\FormCaptain\EventRequests;
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $t ) { return addslashes( (string) $t ); } }

$connectors = apply_filters( 'form_captain_connectors_v1', array( 'other' => 'kept' ) );
check( 'connector registered through form_captain_connectors_v1 without replacing others', 'kept' === $connectors['other'] && $connectors['pass_captain'] instanceof Connector && 'pass_captain' === $connectors['pass_captain']->key() && '1.0' === $connectors['pass_captain']->contract() );
$connectors['pass_captain']->register_actions();
$action = Connector::definition();
check( 'form action pass_captain.event_registration registered by the connector', array( Connector::ACTION ) === $GLOBALS['fcfake']['actions'] && array( Connector::ACTION ) === $connectors['pass_captain']->capabilities() );
$sensitive = array_filter( $action['mapping'], static fn( $m ) => ! empty( $m['allow_sensitive'] ) );
check( 'explicit mapping of name, e-mail, phone, message; no sensitive field allowed', array( 'name', 'email', 'phone', 'message' ) === array_column( $action['mapping'], 'key' ) && ! $sensitive && '^1.0' === $action['contract'] && 'pass_captain' === $action['connector'] );
check( 'connector requirements ok while the purpose is registered', true === $connectors['pass_captain']->requirements()['ok'] );

$req_event = fc_event();
$ctx  = array( 'purpose' => Adapter::PURPOSE, 'ref_type' => 'event', 'ref_id' => (string) $req_event, 'verified' => true );
$job  = static fn( $entry, array $over = array() ) => array_merge( array( 'form_id' => 7, 'entry_id' => $entry, 'idempotency_key' => 'form-captain:1:' . $entry . ':pass_captain.event_registration:a_1', 'action_key' => Connector::ACTION, 'instance_id' => 'a_1', 'attempt' => 1, 'settings' => array(), 'values' => array( 'name' => 'Ada <b>Lovelace</b>', 'email' => 'ada@example.org', 'phone' => '+43 1 234', 'message' => "Zwei Plätze,\nbitte", 'event_id' => '999' ), 'context' => $ctx, 'test_mode' => false, 'unknown_key' => 'ignored' ), $over );
$before = fc_counts();
$test   = EventRequests::handle_job( $job( 0, array( 'test_mode' => true, 'context' => array() ) ) );
check( 'test mode: success without side effects', 'success' === $test['status'] && 'test_ok' === $test['code'] && 0 === EventRequests::count_for_event( $req_event ) );
$r1 = EventRequests::handle_job( $job( 501 ) );
$row = $r1['remote_id'] ?? '' ? EventRequests::get( (int) substr( $r1['remote_id'], strlen( 'pass-request-' ) ) ) : null;
check( 'delivery creates one request "to review" with sanitized mapped values', 'success' === $r1['status'] && 'created' === $r1['code'] && $row && 'review' === $row->status && 'Ada Lovelace' === $row->name && 'ada@example.org' === $row->email && 501 === (int) $row->entry_id );
check( 'event comes from the verified context, not from a form field', $row && $req_event === (int) $row->event_id );
$r2 = EventRequests::handle_job( $job( 501, array( 'attempt' => 2 ) ) );
check( 'repeated delivery returns the same request and stores nothing twice', 'success' === $r2['status'] && $r1['remote_id'] === $r2['remote_id'] && 1 === EventRequests::count_for_event( $req_event ) );
check( 'a request creates no order, reservation or ticket', $before === fc_counts() );
$bad = EventRequests::handle_job( $job( 502, array( 'context' => array() ) ) );
check( 'no verified context: fail context_missing', 'fail' === $bad['status'] && 'context_missing' === $bad['code'] );
$bad = EventRequests::handle_job( $job( 503, array( 'context' => array( 'verified' => false ) + $ctx ) ) );
check( 'unverified context: fail', 'fail' === $bad['status'] && 'context_missing' === $bad['code'] );
$bad = EventRequests::handle_job( $job( 504, array( 'context' => array( 'purpose' => 'other.purpose' ) + $ctx ) ) );
check( 'context of another purpose: fail', 'fail' === $bad['status'] );
$bad = EventRequests::handle_job( $job( 505, array( 'idempotency_key' => '' ) ) );
check( 'missing idempotency key: fail', 'fail' === $bad['status'] && 'missing_idempotency_key' === $bad['code'] );
$gone = fc_event();
Orders::cancel_event( $gone );
$bad = EventRequests::handle_job( $job( 506, array( 'context' => array( 'ref_id' => (string) $gone ) + $ctx ) ) );
check( 'cancelled event: fail event_cancelled, nothing stored', 'fail' === $bad['status'] && 'event_cancelled' === $bad['code'] && 0 === EventRequests::count_for_event( $gone ) );
$bad = EventRequests::handle_job( $job( 507, array( 'context' => array( 'ref_id' => '99999999' ) + $ctx ) ) );
check( 'unknown event: fail event_not_found', 'fail' === $bad['status'] && 'event_not_found' === $bad['code'] );

echo "Form Captain adapter: reviewing requests\n";
$request_id = (int) $row->request_id;
ob_start(); EventForms::render_section( $wpdb->get_row( "SELECT * FROM {$p}wpcap_events WHERE event_id=$req_event" ), true ); $html = ob_get_clean();
check( 'event screen lists the request with book and reject actions', false !== strpos( $html, 'Ada Lovelace' ) && false !== strpos( $html, EventRequests::ACTION_BOOK ) && false !== strpos( $html, EventRequests::ACTION_REJECT ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$r = EventRequests::book( array( 'request_id' => $request_id, 'ticket_type_id' => 1, 'quantity' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'booking needs the order capability', is_wp_error( $r ) && 'wpcap_admin_forbidden' === $r->get_error_code() );
$foreign = make_type( 5, 10, 0 );
$r = EventRequests::book( array( 'request_id' => $request_id, 'ticket_type_id' => $foreign, 'quantity' => 1 ) );
check( 'ticket type of another event is rejected', is_wp_error( $r ) && 'wpcap_request_ticket_type' === $r->get_error_code() && $before === fc_counts() );
$own_type = (int) $wpdb->get_var( "SELECT t.ticket_type_id FROM {$p}wpcap_ticket_types t INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE o.event_id=$req_event" );
$r = EventRequests::book( array( 'request_id' => $request_id, 'ticket_type_id' => $own_type, 'quantity' => 2 ) );
$booked = EventRequests::get( $request_id );
check( 'manual booking creates the order through Orders::create (free tickets issued)', ! is_wp_error( $r ) && 'booked' === $booked->status && (int) $booked->order_id === $r['order_id'] && 2 === count( tickets( $r['order_id'] ) ) && 'ada@example.org' === Orders::get( $r['order_id'] )->customer_email );
$again = EventRequests::book( array( 'request_id' => $request_id, 'ticket_type_id' => $own_type, 'quantity' => 2 ) );
check( 'a booked request cannot be booked twice', is_wp_error( $again ) && 'wpcap_request_decided' === $again->get_error_code() && 2 === count( tickets( $r['order_id'] ) ) );
$paid_event_type = make_type( 5, 10, 2500 );
$paid_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types t INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=$paid_event_type" );
$rp = EventRequests::handle_job( $job( 520, array( 'context' => array( 'ref_id' => (string) $paid_event ) + $ctx ) ) );
$paid_req = (int) substr( $rp['remote_id'], strlen( 'pass-request-' ) );
$r = EventRequests::book( array( 'request_id' => $paid_req, 'ticket_type_id' => $paid_event_type, 'quantity' => 1 ) );
check( 'paid ticket: order pending with manual payment hold, no ticket before payment', ! is_wp_error( $r ) && 'pending' === $r['payment_status'] && ! tickets( $r['order_id'] ) && 'manual' === Orders::get( $r['order_id'] )->payment_provider );
$rr = EventRequests::handle_job( $job( 530 ) );
$rej_id = (int) substr( $rr['remote_id'], strlen( 'pass-request-' ) );
$r = EventRequests::reject( array( 'request_id' => $rej_id ) );
check( 'reject marks the request rejected without any order', ! is_wp_error( $r ) && 'rejected' === EventRequests::get( $rej_id )->status && 0 === (int) EventRequests::get( $rej_id )->order_id );
$noemail = EventRequests::handle_job( $job( 540, array( 'values' => array( 'name' => 'Ohne Mail', 'email' => 'kaputt' ) ) ) );
$noemail_id = (int) substr( $noemail['remote_id'], strlen( 'pass-request-' ) );
$r = EventRequests::book( array( 'request_id' => $noemail_id, 'ticket_type_id' => $own_type, 'quantity' => 1 ) );
check( 'invalid e-mail is not stored and the request cannot be booked', '' === EventRequests::get( $noemail_id )->email && is_wp_error( $r ) && 'wpcap_request_email' === $r->get_error_code() );

echo "Form Captain adapter: entry deletion and consent withdrawal\n";
$open = EventRequests::handle_job( $job( 550 ) );
$open_id = (int) substr( $open['remote_id'], strlen( 'pass-request-' ) );
do_action( 'form_captain_entry_deleted_v1', 550, 7, 'erasure' );
check( 'entry deleted: open request removed', null === EventRequests::get( $open_id ) );
do_action( 'form_captain_consent_withdrawn_v1', 501, 7, 'admin' );
$booked = EventRequests::get( $request_id );
check( 'consent withdrawn: booked request keeps status and order, personal data removed', $booked && 'booked' === $booked->status && $booked->order_id && '' === $booked->name && '' === $booked->email && '' === $booked->message && null !== $booked->anonymized_at );
do_action( 'form_captain_consent_withdrawn_v1', 540, 7, 'admin' );
check( 'consent withdrawn: open request removed', null === EventRequests::get( $noemail_id ) );
do_action( 'form_captain_entry_deleted_v1', 530, 9, 'manual' );
check( 'deletion of another form\'s entry with the same ID changes nothing', null !== EventRequests::get( $rej_id ) && '' !== EventRequests::get( $rej_id )->name );
check( 'removal is audited without personal data', (bool) $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='event_request_removed'" ) && ! $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action LIKE 'event_request%' AND details LIKE '%ada@example.org%'" ) );
