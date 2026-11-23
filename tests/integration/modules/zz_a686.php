<?php
/**
 * A686: print – attendee field mapping, more label formats, sort orders, preview.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Printing\BatchJob;
use PassCaptain\Printing\Page as PrintPage;
use PassCaptain\Printing\Templates;
use PassCaptain\Reports\Scope;
use PassCaptain\TicketPdf;

echo "A686: print field mapping, label formats, sorting, preview\n";
PassCaptain\Modules\PrintTemplates::register();
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
global $wpdb;
$p = $wpdb->prefix;

// Fixture: two ticket types, a company field and a consent field.
$z686_type_b = make_type( 20, 10, 0 );
$z686_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $z686_type_b ) );
$z686_event = Scope::occurrence_event( $z686_occ );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'name' => 'Basis' ), array( 'ticket_type_id' => $z686_type_b ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $z686_occ, 'name' => 'Abend', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active' ) );
$z686_type_a = (int) $wpdb->insert_id;
foreach ( array( $z686_type_a, $z686_type_b ) as $z686_t ) {
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $z686_t, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $z686_t, 'field_key' => 'foto_ok', 'label' => 'Fotos', 'field_type' => 'consent', 'required' => 0, 'status' => 'active' ) );
}
wpcap_test_reset_transients();
$z686_o1 = Orders::create( array( 'email' => 'z686-1@example.com', 'items' => array( array( 'ticket_type_id' => $z686_type_b, 'quantity' => 1 ) ), 'idempotency_key' => 'z686-' . uniqid(), 'attendees' => array(
	array( 'ticket_type_id' => $z686_type_b, 'name' => 'Anna Basis', 'answers' => array( 'firma' => 'ACME GmbH', 'foto_ok' => '1' ) ),
) ), array( 'skip_rate_limit' => true ) );
$z686_o2 = Orders::create( array( 'email' => 'z686-2@example.com', 'items' => array( array( 'ticket_type_id' => $z686_type_a, 'quantity' => 1 ) ), 'idempotency_key' => 'z686-' . uniqid(), 'attendees' => array(
	array( 'ticket_type_id' => $z686_type_a, 'name' => 'Zora Abend', 'answers' => array( 'firma' => 'Zeta AG' ) ),
) ), array( 'skip_rate_limit' => true ) );
check( 'A686: fixture orders', ! is_wp_error( $z686_o1 ) && ! is_wp_error( $z686_o2 ) );
$z686_t1 = tickets( $z686_o1['order_id'] )[0];
$z686_t2 = tickets( $z686_o2['order_id'] )[0];

// Field mapping.
check( 'A686: {field:key} prints the answer, unknown fields stay empty', 'ACME GmbH – VIP' === Templates::fill( '{field:firma} – {ticket_category} {field:nope}', array( 'ticket_category' => 'VIP', 'fields' => array( 'firma' => 'ACME GmbH' ) ) ) );
$z686_answers = Templates::field_answers( array( (int) $z686_t1->ticket_id, (int) $z686_t2->ticket_id ) );
check( 'A686: answers per ticket, consents never', 'ACME GmbH' === ( $z686_answers[ (int) $z686_t1->ticket_id ]['firma'] ?? '' ) && 'Zeta AG' === ( $z686_answers[ (int) $z686_t2->ticket_id ]['firma'] ?? '' ) && ! isset( $z686_answers[ (int) $z686_t1->ticket_id ]['foto_ok'] ) );
check( 'A686: field keys of the event are offered (without consents)', array( 'firma' => 'Firma' ) === Templates::field_keys( $z686_event ) );
$z686_tpl = Templates::save_template( 0, 'Badge mit Firma', 'badge', "*18|{attendee_name}\n12|{field:firma}" );
Templates::assign( $z686_event, 'badge', $z686_tpl );
$z686_plain = wpcap_test_pdf_plain( Templates::batch_pdf( $z686_occ, 'badge' ) );
check( 'A686: batch print fills participant fields', false !== strpos( $z686_plain, '(ACME GmbH) Tj' ) && false !== strpos( $z686_plain, '(Zeta AG) Tj' ) );
$z686_ttpl = Templates::save_template( 0, 'Ticket mit Firma', 'ticket', "*18|{event}\n12|{field:firma}\n{qr}" );
Templates::assign( $z686_event, 'ticket', $z686_ttpl );
$z686_mail = wpcap_test_pdf_plain( TicketPdf::render( array( array( 'ticket_id' => (int) $z686_t1->ticket_id, 'occurrence_id' => $z686_occ, 'event' => 'Fest', 'ticket' => 'Basis', 'token' => str_repeat( 'b', 64 ) ) ) ) );
check( 'A686: ticket PDFs of the mail fill participant fields of the event template', false !== strpos( $z686_mail, '(ACME GmbH) Tj' ) );

// Sorting.
$z686_ids = static function ( $rows ) { return array_map( static function ( $r ) { return (int) $r->ticket_id; }, $rows ); };
$z686_by_name = $z686_ids( Templates::ticket_refs_for_occurrence( $z686_occ, array( 'valid' ), 'name' ) );
$z686_by_type = $z686_ids( Templates::ticket_refs_for_occurrence( $z686_occ, array( 'valid' ), 'type' ) );
$z686_by_ticket = $z686_ids( Templates::ticket_refs_for_occurrence( $z686_occ, array( 'valid' ), 'ticket' ) );
check( 'A686: sort by name (Anna before Zora), by ticket type (Abend before Basis), by ticket number', array( (int) $z686_t1->ticket_id, (int) $z686_t2->ticket_id ) === $z686_by_name && array( (int) $z686_t2->ticket_id, (int) $z686_t1->ticket_id ) === $z686_by_type && $z686_by_ticket === array_values( array_unique( $z686_by_ticket ) ) && min( $z686_by_ticket ) === $z686_by_ticket[0] );
check( 'A686: unknown sort keys fall back to the name order', Templates::sort_sql( "x'; DROP" ) === Templates::sort_sql( 'name' ) );
$z686_sorted_pdf = wpcap_test_pdf_plain( Templates::batch_pdf( $z686_occ, 'badge', 'type' ) );
check( 'A686: the batch PDF follows the chosen order', strpos( $z686_sorted_pdf, '(Zora Abend) Tj' ) < strpos( $z686_sorted_pdf, '(Anna Basis) Tj' ) );
$z686_req = PrintPage::batch_request( $z686_occ, 'badge', false, array( 'sort' => 'type' ) );
check( 'A686: the print form passes the sort order', is_array( $z686_req ) && isset( $z686_req['pdf'] ) && strpos( wpcap_test_pdf_plain( $z686_req['pdf'] ), '(Zora Abend) Tj' ) < strpos( wpcap_test_pdf_plain( $z686_req['pdf'] ), '(Anna Basis) Tj' ) );

// Label formats.
$z686_item = Templates::sample_item();
$z686_l24 = Templates::render( 'label_3x8', array_fill( 0, 25, $z686_item ) );
$z686_l14 = Templates::render( 'label_2x7', array_fill( 0, 14, $z686_item ) );
$z686_b4 = Templates::render( 'badge_4up', array_fill( 0, 5, $z686_item ) );
$z686_pages = static function ( $pdf ) { return preg_match_all( '#/Type /Page /Parent#', $pdf ); };
check( 'A686: 24 labels per A4 sheet without frames (die-cut)', 2 === $z686_pages( $z686_l24 ) && 0 === substr_count( wpcap_test_pdf_plain( $z686_l24 ), ' re S' ) && 25 === substr_count( wpcap_test_pdf_plain( $z686_l24 ), '(Erika Musterfrau) Tj' ) && false !== strpos( $z686_l24, '/MediaBox [0 0 595.28 841.89]' ) );
check( 'A686: 14 labels per A4 sheet', 1 === $z686_pages( $z686_l14 ) && 14 === substr_count( wpcap_test_pdf_plain( $z686_l14 ), '(Erika Musterfrau) Tj' ) );
check( 'A686: four A6 badges per A4 sheet with cut frames', 2 === $z686_pages( $z686_b4 ) && 5 === substr_count( wpcap_test_pdf_plain( $z686_b4 ), ' re S' ) );
check( 'A686: name tags keep 10 per sheet with frames', 10 === Templates::per_page( 'nametag' ) && 24 === Templates::per_page( 'label_3x8' ) && 1 === Templates::per_page( 'ticket' ) );
check( 'A686: background blocks end with full label sheets', 0 === BatchJob::block_size( 'label_3x8' ) % 24 && 0 === BatchJob::block_size( 'label_2x7' ) % 14 && 0 === BatchJob::block_size( 'nametag' ) % 10 && BatchJob::block_size() === BatchJob::block_size( 'ticket' ) );
check( 'A686: new layouts have labels and default bodies', 7 === count( Templates::kind_labels() ) && false !== strpos( Templates::default_body( 'label_2x7' ), '{attendee_name}' ) );

// Preview.
$z686_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", (int) $z686_t1->ticket_id ) );
$z686_prev = Templates::preview_pdf( 'label_3x8', "*10|{attendee_name}\n8|{field:firma}\n{qr}" );
$z686_prev_plain = wpcap_test_pdf_plain( $z686_prev );
check( 'A686: preview fills a whole sheet with sample data and shows field keys', 1 === $z686_pages( $z686_prev ) && 24 === substr_count( $z686_prev_plain, '(Erika Musterfrau) Tj' ) && false === strpos( $z686_prev_plain, 'ACME' ) );
check( 'A686: preview changes no ticket code', $z686_hash === (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", (int) $z686_t1->ticket_id ) ) );
check( 'A686: sample fields name their key', '‹firma›' === ( Templates::sample_item( '{field:Firma}' )['fields']['firma'] ?? '' ) );
$z686_tpl_row = Templates::get_template( $z686_tpl );
check( 'A686: event managers may preview global templates', PrintPage::can_preview( $z686_tpl_row ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
check( 'A686: without event management no preview', ! PrintPage::can_preview( $z686_tpl_row ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A686: preview link is nonce protected and the handler registered', false !== strpos( PrintPage::preview_url( 5 ), 'action=wpcap_print_preview' ) && false !== strpos( PrintPage::preview_url( 5 ), '_wpnonce=' ) && isset( PassCaptain\Modules\PrintTemplates::admin_handlers()['wpcap_print_preview'] ) );
Templates::assign( $z686_event, 'badge', 0 );
Templates::assign( $z686_event, 'ticket', 0 );
