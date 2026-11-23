<?php
/**
 * A552/A680: participant field types select, multi-select, phone, date and number, help text,
 * order-level fields, required attendee name per ticket type, "same as buyer" and versioned field
 * definitions (answers store the version they were given under). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Orders;
use PassCaptain\ParticipantFields;

global $wpdb;
$p = $wpdb->prefix;

echo "A552/A680: participant field types, order-level fields, name per ticket, versions\n";

$x6f_type = make_type( 20, 10, 0 );
$x6f_save = static function ( array $in ) use ( $x6f_type ) { return EventsPage::save_participant_field( $in + array( 'ticket_type_id' => $x6f_type, 'retention_days' => 30 ) ); };

check( 'A680: the schema columns exist (options, help text, scope, version, answer version, name switch)', ParticipantFields::ready() && ParticipantFields::answers_versioned() && ParticipantFields::name_switch_ready() );
check( 'A680: the field editor offers select, multi-select, phone, date and number', ! array_diff( array( 'select', 'multiselect', 'tel', 'date', 'number' ), EventsPage::FIELD_TYPES ) && ! array_diff( EventsPage::FIELD_TYPES, array_keys( EventsPage::field_type_labels() ) ) );

$x6f_noopt = $x6f_save( array( 'field_key' => 'size', 'label' => 'Größe', 'field_type' => 'select', 'options' => '' ) );
check( 'A680: a select field without choices is refused', is_wp_error( $x6f_noopt ) && 'wpcap_field_options' === $x6f_noopt->get_error_code() );
$x6f_comma = $x6f_save( array( 'field_key' => 'food', 'label' => 'Essen', 'field_type' => 'multiselect', 'options' => "Vegetarisch, vegan\nFisch" ) );
check( 'A680: a multi-select choice with a comma is refused (answers are joined with ", ")', is_wp_error( $x6f_comma ) && 'wpcap_field_options' === $x6f_comma->get_error_code() );

$x6f_size = $x6f_save( array( 'field_key' => 'size', 'label' => 'T-Shirt', 'field_type' => 'select', 'options' => "S\nM\nL\nM", 'help_text' => 'Unisex-Schnitt', 'required' => '1', 'position' => 1 ) );
$x6f_food = $x6f_save( array( 'field_key' => 'food', 'label' => 'Essen', 'field_type' => 'multiselect', 'options' => "Vegetarisch\nFisch\nOhne Nüsse", 'position' => 2 ) );
$x6f_tel = $x6f_save( array( 'field_key' => 'phone', 'label' => 'Notfallnummer', 'field_type' => 'tel', 'position' => 3 ) );
$x6f_date = $x6f_save( array( 'field_key' => 'birth', 'label' => 'Geburtsdatum', 'field_type' => 'date', 'position' => 4 ) );
$x6f_num = $x6f_save( array( 'field_key' => 'km', 'label' => 'Anreise (km)', 'field_type' => 'number', 'position' => 5 ) );
$x6f_org = $x6f_save( array( 'field_key' => 'org', 'label' => 'Verein', 'field_type' => 'text', 'scope' => 'order', 'required' => '1', 'position' => 0 ) );
$x6f_ids = array_map( static fn( $r ) => is_array( $r ) ? $r['field_id'] : 0, array( $x6f_size, $x6f_food, $x6f_tel, $x6f_date, $x6f_num, $x6f_org ) );
check( 'A680: fields of every new type are saved', ! in_array( 0, $x6f_ids, true ) );
$x6f_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_participant_fields WHERE field_id=%d", $x6f_ids[0] ) );
check( 'A680: choices are stored unique as a JSON list, with help text, scope and version 1', array( 'S', 'M', 'L' ) === json_decode( $x6f_row->options, true ) && 'Unisex-Schnitt' === $x6f_row->help_text && 'ticket' === $x6f_row->scope && 1 === (int) $x6f_row->version );
check( 'A680: an order-level field is stored with scope "order"', 'order' === $wpdb->get_var( $wpdb->prepare( "SELECT scope FROM {$p}wpcap_participant_fields WHERE field_id=%d", $x6f_ids[5] ) ) );

// Public definition for the checkout: type, choices, help text and scope – never answers.
$x6f_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $x6f_type ) );
$x6f_meta = PassCaptain\PublicEvents::listing_data( $wpdb->get_results( $wpdb->prepare( "SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location,o.doors_open_at FROM {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id WHERE o.occurrence_id=%d", $x6f_occ ) ) );
$x6f_pub = array();
foreach ( (array) $x6f_meta as $x6f_list ) { foreach ( $x6f_list as $x6f_t ) { if ( (int) $x6f_t->ticket_type_id === $x6f_type ) { $x6f_pub = $x6f_t; } } }
$x6f_pub_size = $x6f_pub ? array_values( array_filter( $x6f_pub->fields, static fn( $f ) => 'size' === $f['key'] ) )[0] ?? array() : array();
check( 'A680: the checkout gets type, choices, help text and scope of a field', array( 'S', 'M', 'L' ) === ( $x6f_pub_size['options'] ?? null ) && 'select' === ( $x6f_pub_size['type'] ?? '' ) && 'Unisex-Schnitt' === ( $x6f_pub_size['help'] ?? '' ) && 'ticket' === ( $x6f_pub_size['scope'] ?? '' ) && $x6f_pub && false === $x6f_pub->name_required );

$x6f_lines = array( $x6f_type => 2 );
$x6f_ok = array(
	array( 'ticket_type_id' => $x6f_type, 'name' => 'Ada', 'answers' => array( 'org' => 'SV Linz', 'size' => 'M', 'food' => array( 'Ohne Nüsse', 'Vegetarisch' ), 'phone' => '+43 660 123456', 'birth' => '1990-02-28', 'km' => '12,5' ) ),
	array( 'ticket_type_id' => $x6f_type, 'name' => 'Bo', 'answers' => array( 'size' => 'L', 'food' => 'Fisch' ) ),
);
$x6f_valid = Orders::validate_attendees( $x6f_lines, $x6f_ok );
list( $f_size, $f_food, $f_tel, $f_date, $f_num, $f_org ) = $x6f_ids;
check( 'A680: valid answers of every type are normalised (multi-select in the order of the choices, decimal comma)', is_array( $x6f_valid ) && 'M' === $x6f_valid[0]['answers'][ $f_size ] && 'Vegetarisch, Ohne Nüsse' === $x6f_valid[0]['answers'][ $f_food ] && '+43 660 123456' === $x6f_valid[0]['answers'][ $f_tel ] && '1990-02-28' === $x6f_valid[0]['answers'][ $f_date ] && '12.5' === $x6f_valid[0]['answers'][ $f_num ] && 'Fisch' === $x6f_valid[1]['answers'][ $f_food ] );
check( 'A680: an order-level answer of the first ticket applies to every ticket of the type', is_array( $x6f_valid ) && 'SV Linz' === $x6f_valid[0]['answers'][ $f_org ] && 'SV Linz' === $x6f_valid[1]['answers'][ $f_org ] );

$x6f_bad = static function ( $key, $value, $i = 0 ) use ( $x6f_lines, $x6f_ok ) {
	$list = $x6f_ok;
	$list[ $i ]['answers'][ $key ] = $value;
	return Orders::validate_attendees( $x6f_lines, $list );
};
$x6f_cases = array( array( 'size', 'XXL' ), array( 'food', array( 'Fisch', 'Pizza' ) ), array( 'phone', 'ruf mich an' ), array( 'phone', '12' ), array( 'birth', '1990-02-30' ), array( 'birth', '28.02.1990' ), array( 'km', 'zwölf' ) );
$x6f_all_bad = true;
foreach ( $x6f_cases as $x6f_case ) {
	$x6f_r = $x6f_bad( $x6f_case[0], $x6f_case[1], 1 );
	$x6f_d = is_wp_error( $x6f_r ) ? (array) $x6f_r->get_error_data() : array();
	$x6f_all_bad = $x6f_all_bad && is_wp_error( $x6f_r ) && 'wpcap_attendee_field_invalid' === $x6f_r->get_error_code() && $x6f_case[0] === ( $x6f_d['field_key'] ?? '' ) && 1 === ( $x6f_d['position'] ?? null );
}
check( 'A680: a choice outside the list, an invalid phone number, date or number is refused (never stored as empty) and names attendee and field', $x6f_all_bad );
$x6f_req = $x6f_bad( 'size', '', 1 );
check( 'A680: a required select must be chosen for every ticket', is_wp_error( $x6f_req ) && 'wpcap_attendee_field_required' === $x6f_req->get_error_code() );
$x6f_noorg = $x6f_bad( 'org', '' );
check( 'A680: a required order-level field must be filled once (message says "für die Bestellung")', is_wp_error( $x6f_noorg ) && 'wpcap_attendee_field_required' === $x6f_noorg->get_error_code() && false !== strpos( $x6f_noorg->get_error_message(), 'Bestellung' ) && 0 === ( $x6f_noorg->get_error_data()['position'] ?? null ) );

echo "A552: attendee name per ticket and \"same as buyer\"\n";
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name_required' => 1 ), array( 'ticket_type_id' => $x6f_type ) );
$x6f_noname = $x6f_ok;
$x6f_noname[1]['name'] = '';
$x6f_r = Orders::validate_attendees( $x6f_lines, $x6f_noname );
check( 'A552: with "Name für jedes Ticket verlangen" a ticket without a name is refused (field_key __name)', is_wp_error( $x6f_r ) && 'wpcap_attendee_field_required' === $x6f_r->get_error_code() && '__name' === ( $x6f_r->get_error_data()['field_key'] ?? '' ) && 1 === ( $x6f_r->get_error_data()['position'] ?? null ) );
$x6f_plain = make_type( 5, 10, 0 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name_required' => 1 ), array( 'ticket_type_id' => $x6f_plain ) );
$x6f_r = Orders::validate_attendees( array( $x6f_plain => 2 ), array( array( 'ticket_type_id' => $x6f_plain, 'name' => 'Ada' ) ) );
check( 'A552: a ticket not sent at all is refused when names are required (even without any participant field)', is_wp_error( $x6f_r ) && '__name' === ( $x6f_r->get_error_data()['field_key'] ?? '' ) && 1 === ( $x6f_r->get_error_data()['position'] ?? null ) );
check( 'A552: corrections of answers alone (skip_name) and deferred waitlist data do not need the name', is_array( Orders::validate_attendees( $x6f_lines, $x6f_noname, array( 'skip_name' => true ) ) ) && is_array( Orders::validate_attendees( $x6f_lines, array(), array( 'defer_required' => true ) ) ) );
$x6f_same = $x6f_noname;
$x6f_same[1]['same_as_buyer'] = true;
$x6f_r = Orders::validate_attendees( $x6f_lines, $x6f_same, array( 'buyer' => array( 'email' => 'kaeuferin@example.com', 'name' => 'Karin Käufer' ) ) );
check( 'A552: "same as buyer" fills name and e-mail of that ticket from the buyer', is_array( $x6f_r ) && 'Karin Käufer' === $x6f_r[1]['name'] && 'kaeuferin@example.com' === $x6f_r[1]['email'] && '' === $x6f_r[0]['email'] );

$x6f_order = Orders::create( array( 'email' => 'kaeuferin@example.com', 'billing' => array( 'first_name' => 'Karin', 'last_name' => 'Käufer', 'street' => 'Weg 1', 'postcode' => '4020', 'city' => 'Linz', 'country' => 'AT' ), 'items' => array( array( 'ticket_type_id' => $x6f_type, 'quantity' => 2 ) ), 'attendees' => $x6f_same, 'idempotency_key' => 'x6f-' . uniqid() ) );
check( 'A552: the checkout takes "same as buyer" with the buyer address and billing name', is_array( $x6f_order ) && ! empty( $x6f_order['order_id'] ) );
if ( is_array( $x6f_order ) ) {
	Orders::confirm_manual_payment( $x6f_order['order_id'] );
	$x6f_tickets = tickets( $x6f_order['order_id'] );
	$x6f_names = $wpdb->get_col( $wpdb->prepare( "SELECT a.attendee_name FROM {$p}wpcap_ticket_assignments a INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=%d ORDER BY t.ticket_id", $x6f_order['order_id'] ) );
	check( 'A552: both tickets carry the attendee names (second from the buyer)', array( 'Ada', 'Karin Käufer' ) === $x6f_names );
	$x6f_ans = $wpdb->get_results( $wpdb->prepare( "SELECT a.ticket_id,a.field_id,a.answer,a.field_version FROM {$p}wpcap_participant_answers a INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=a.ticket_id WHERE t.order_id=%d AND a.field_id=%d", $x6f_order['order_id'], $f_org ) );
	check( 'A680: the order-level answer is stored on every ticket, with the field version', 2 === count( $x6f_ans ) && 'SV Linz' === $x6f_ans[0]->answer && 'SV Linz' === $x6f_ans[1]->answer && 1 === (int) $x6f_ans[0]->field_version );
}

echo "A680: versioned field definitions\n";
$x6f_audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='participant_field_updated'" );
$x6f_save( array( 'field_id' => $f_size, 'label' => 'T-Shirt', 'field_type' => 'select', 'options' => "S\nM\nL", 'help_text' => 'Neuer Hilfetext', 'required' => '1', 'purpose' => 'Bestellung der Shirts', 'position' => 1 ) );
check( 'A680: help text, purpose or position changes keep the version', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$p}wpcap_participant_fields WHERE field_id=%d", $f_size ) ) );
$x6f_save( array( 'field_id' => $f_size, 'label' => 'T-Shirt', 'field_type' => 'select', 'options' => "S\nM\nL\nXL", 'help_text' => 'Neuer Hilfetext', 'required' => '1', 'position' => 1 ) );
$x6f_save( array( 'field_id' => $f_size, 'label' => 'Shirtgröße', 'field_type' => 'select', 'options' => "S\nM\nL\nXL", 'required' => '1', 'position' => 1 ) );
check( 'A680: changed choices and a changed label each start a new version', 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$p}wpcap_participant_fields WHERE field_id=%d", $f_size ) ) );
$x6f_audit = json_decode( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='participant_field_updated' ORDER BY audit_id DESC LIMIT 1" ), true );
check( 'A680: the audit log keeps each definition with its version', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='participant_field_updated'" ) - $x6f_audit_before && 3 === ( $x6f_audit['version'] ?? 0 ) && 'Shirtgröße' === ( $x6f_audit['label'] ?? '' ) && array( 'S', 'M', 'L', 'XL' ) === ( $x6f_audit['options'] ?? null ) );
if ( is_array( $x6f_order ) ) {
	$x6f_tid = (int) tickets( $x6f_order['order_id'] )[0]->ticket_id;
	ParticipantFields::save_answer( $x6f_tid, $f_size, 'XL' );
	check( 'A680: an answer given later records the version it was given under', 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT field_version FROM {$p}wpcap_participant_answers WHERE ticket_id=%d AND field_id=%d", $x6f_tid, $f_size ) ) );
}
$x6f_consent_order = $x6f_save( array( 'field_key' => 'news', 'label' => 'Newsletter', 'field_type' => 'checkbox', 'consent' => '1', 'purpose' => 'Newsletter', 'scope' => 'order' ) );
check( 'A680: a consent cannot be an order-level field (consent is per ticket person)', ! PassCaptain\Consents::ready() || ( is_wp_error( $x6f_consent_order ) && 'wpcap_field_consent_scope' === $x6f_consent_order->get_error_code() ) );

$x6f_html = ParticipantFields::control_html( (object) array( 'field_type' => 'multiselect', 'options' => '["A","B"]', 'required' => 0 ), array( 'id' => 'x', 'name' => 'wpcap_answer[k]' ), 'B' );
$x6f_html2 = ParticipantFields::control_html( (object) array( 'field_type' => 'select', 'options' => '["A","<b>"]', 'required' => 1 ), array( 'id' => 'y', 'data-answer' => 'k' ), '<b>' );
check( 'A680: server-rendered forms (ticket page, portal, waitlist) get escaped controls for the new types', false !== strpos( $x6f_html, 'name="wpcap_answer[k][]"' ) && false !== strpos( $x6f_html, 'value="B" name="wpcap_answer[k][]" checked' ) && false === strpos( $x6f_html, 'value="A" name="wpcap_answer[k][]" checked' ) && false !== strpos( $x6f_html2, 'value="&lt;b&gt;" selected' ) && false !== strpos( $x6f_html2, ' required' ) && '' === ParticipantFields::control_html( (object) array( 'field_type' => 'text' ), array( 'name' => 'n' ) ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'attendee_name_required' => 0 ), array( 'ticket_type_id' => $x6f_type ) );
