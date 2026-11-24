<?php
/**
 * Box office receipt (TODO L914, L902):
 * - VAT per rate on the receipt is computed from the gross after the order discount (net = gross after
 *   discount − VAT), with the invoice allocation (stored line discounts, else the price snapshot split);
 *   lines as paid, so a later partial refund does not change the reprint.
 * - Agent model (Vermittler): the organizer issues the receipt (seller block), with "Vermittelt durch …"
 *   and the organizer's small business note; platform receipts carry the platform's small business note.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\BoxOffice\Service as P2Pos;
use PassCaptain\Partners\Repository as P2Partners;
use PassCaptain\Settings;

PassCaptain\Modules\BoxOffice::register();
if ( ! function_exists( 'nl2br' ) ) { function nl2br( $s ) { return str_replace( "\n", "<br>\n", (string) $s ); } }

global $wpdb;
$p = $wpdb->prefix;
$p2_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 7202;
$p2_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_denied_caps'] = array();
$p2_saved = array();
foreach ( array( 'boxoffice_terminals', 'boxoffice_cash_confirmed', 'seller_country', 'seller_name', 'seller_address', 'seller_vat_id', 'seller_small_business', 'seller_small_business_note' ) as $p2_key ) { $p2_saved[ $p2_key ] = Settings::get( $p2_key ); }
Settings::update( array( 'boxoffice_terminals' => '', 'boxoffice_cash_confirmed' => 1, 'seller_country' => 'DE', 'seller_name' => 'P2 Plattform GmbH', 'seller_address' => "Plattformweg 1\n10115 Berlin", 'seller_vat_id' => 'DE123456789', 'seller_small_business' => 0 ) );

$p2_type_a = make_type( 0, 10, 1190 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900, name='Erwachsene' WHERE ticket_type_id=$p2_type_a" );
$p2_occ = wpb_occurrence_of( $p2_type_a );
$p2_type_b = wpb_add_type( $p2_occ, 'Ermäßigt', 2140, 700, 0 );
$p2_session = P2Pos::open_session( 0, 'EUR' );
check( 'setup: open box office session', ! is_wp_error( $p2_session ) && is_object( $p2_session ) );

function p2_sell( array $quantities ) {
	$r = P2Pos::sell( array( 'occurrence_id' => $GLOBALS['p2_occ'], 'quantities' => $quantities, 'method' => 'cash', 'form_key' => wpb_key() ) );
	return is_wp_error( $r ) ? null : $r['sale'];
}

echo "Box office receipt: VAT after discount (L914)\n";
$p2_sale = p2_sell( array( $p2_type_a => 2, $p2_type_b => 1 ) );
check( 'setup: sale 2 × 11,90 (19 %) + 1 × 21,40 (7 %) = 45,20', $p2_sale && 4520 === (int) $p2_sale->total_minor );
$p2_items = $p2_sale ? $wpdb->get_results( $wpdb->prepare( "SELECT item_id,ticket_type_id FROM {$p}wpcap_order_items WHERE order_id=%d ORDER BY item_id", $p2_sale->order_id ) ) : array();
// Discount 4,50 on the order: line A −2,38 → 21,42 (VAT 3,42), line B −2,12 → 19,28 (VAT 1,26).
foreach ( $p2_items as $p2_item ) {
	$p2_a = (int) $p2_item->ticket_type_id === $p2_type_a;
	$wpdb->update( "{$p}wpcap_order_items", array( 'discount_minor' => $p2_a ? 238 : 212, 'tax_minor' => $p2_a ? 342 : 126 ), array( 'item_id' => $p2_item->item_id ) );
}
if ( $p2_sale ) {
	$wpdb->update( "{$p}wpcap_orders", array( 'discount_minor' => 450, 'total_minor' => 4070, 'tax_minor' => 468 ), array( 'order_id' => $p2_sale->order_id ) );
	$wpdb->update( "{$p}wpcap_boxoffice_sales", array( 'total_minor' => 4070, 'tax_minor' => 468 ), array( 'sale_id' => $p2_sale->sale_id ) );
}
$p2_r = $p2_sale ? P2Pos::receipt( $p2_sale->sale_id ) : null;
check( 'L914: net per rate = gross after discount − VAT (19 %: 18,00 / 3,42; 7 %: 18,02 / 1,26)', $p2_r && 1800 === $p2_r['vat'][1900]['net'] && 342 === $p2_r['vat'][1900]['tax'] && 1802 === $p2_r['vat'][700]['net'] && 126 === $p2_r['vat'][700]['tax'] );
check( 'L914: gross per rate adds up to the receipt total (40,70)', $p2_r && 4070 === $p2_r['vat'][1900]['gross'] + $p2_r['vat'][700]['gross'] && 4070 === $p2_r['total_minor'] && 450 === $p2_r['discount_minor'] );
check( 'L914: lines still show the price before discount, the discount is its own line', $p2_r && 2380 === $p2_r['items'][0]['total_minor'] && str_contains( P2Pos::receipt_html( $p2_sale->sale_id ), 'Netto 18,00' ) );

// Orders that carry the split only in their price snapshot (line_discounts), as on the invoice.
if ( $p2_sale ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET discount_minor=0 WHERE order_id=%d", $p2_sale->order_id ) );
	$wpdb->update( "{$p}wpcap_orders", array( 'price_snapshot' => wp_json_encode( array( 'line_discounts' => array( $p2_type_a => 238, $p2_type_b => 212 ) ) ) ), array( 'order_id' => $p2_sale->order_id ) );
}
$p2_r = $p2_sale ? P2Pos::receipt( $p2_sale->sale_id ) : null;
check( 'L914: without stored line discounts the snapshot split is used (same as the invoice)', $p2_r && 1800 === $p2_r['vat'][1900]['net'] && 1802 === $p2_r['vat'][700]['net'] );
$p2_after = $p2_sale ? P2Pos::lines_after_discount( PassCaptain\Orders::get( $p2_sale->order_id ), array( (object) array( 'ticket_type_id' => $p2_type_a, 'line_total_minor' => 2380, 'discount_minor' => 0 ), (object) array( 'ticket_type_id' => $p2_type_b, 'line_total_minor' => 2140, 'discount_minor' => 0 ) ) ) : array();
check( 'L914: lines_after_discount() follows the snapshot split', array( 2142, 1928 ) === $p2_after );

// A later partial refund lowers the live line; the receipt keeps the lines as paid.
if ( PassCaptain\OrderLines::ready() && $p2_items ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET orig_quantity=quantity,orig_line_total_minor=line_total_minor,orig_tax_minor=tax_minor,quantity=1,line_total_minor=1190,tax_minor=171 WHERE item_id=%d", $p2_items[0]->item_id ) );
	$p2_r = P2Pos::receipt( $p2_sale->sale_id );
	check( 'L914: the reprint uses the lines as paid (2 × 11,90, VAT 3,42) after a partial refund', 2 === $p2_r['items'][0]['quantity'] && 2380 === $p2_r['items'][0]['total_minor'] && 342 === $p2_r['vat'][1900]['tax'] && 1800 === $p2_r['vat'][1900]['net'] );
	// Only the line values were simulated (no ticket was refunded): restore them for the capacity invariant.
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET quantity=orig_quantity,line_total_minor=orig_line_total_minor,tax_minor=orig_tax_minor,orig_quantity=NULL,orig_line_total_minor=NULL,orig_tax_minor=NULL WHERE item_id=%d", $p2_items[0]->item_id ) );
}

echo "Box office receipt: issuer and notes (L902)\n";
$p2_plain = p2_sell( array( $p2_type_a => 1 ) );
$p2_r = $p2_plain ? P2Pos::receipt( $p2_plain->sale_id ) : null;
check( 'L902: platform sale – platform is the issuer, no notes', $p2_r && 'P2 Plattform GmbH' === $p2_r['seller']['name'] && 'own_name' === $p2_r['sales_model'] && array() === $p2_r['seller_notes'] );
Settings::update( array( 'seller_small_business' => 1, 'seller_small_business_note' => '' ) );
$p2_r = $p2_plain ? P2Pos::receipt( $p2_plain->sale_id ) : null;
$p2_html = $p2_plain ? P2Pos::receipt_html( $p2_plain->sale_id ) : '';
check( 'L902: platform as small business – the receipt carries the small business note', $p2_r && 1 === count( $p2_r['seller_notes'] ) && str_contains( $p2_r['seller_notes'][0], '19' ) && str_contains( $p2_html, esc_html( $p2_r['seller_notes'][0] ) ) );
Settings::update( array( 'seller_small_business' => 0 ) );

$p2_partner = P2Partners::save( array( 'name' => 'P2 Veranstalter', 'legal_name' => 'P2 Veranstaltungen e. K.', 'currency' => 'EUR', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 0, 'refund_policy' => 'reverse_all', 'billing_address' => "Bühnenweg 3\n80331 München", 'vat_id' => '', 'tax_number' => '143/123/45678', 'sales_model' => 'agent', 'small_business' => 1, 'small_business_note' => 'Kein Ausweis der Umsatzsteuer (Kleinunternehmer, § 19 UStG).' ) );
check( 'setup: agent organizer (small business)', ! is_wp_error( $p2_partner ) && $p2_partner > 0 );
$p2_agent_event = make_type( 0, 10, 1500 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=0 WHERE ticket_type_id=$p2_agent_event" );
$p2_agent_occ = wpb_occurrence_of( $p2_agent_event );
$p2_event_id = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=" . (int) $p2_agent_occ );
P2Partners::set_events( $p2_partner, array( $p2_event_id ) );
$p2_agent_sale = P2Pos::sell( array( 'occurrence_id' => $p2_agent_occ, 'quantities' => array( $p2_agent_event => 1 ), 'method' => 'cash', 'form_key' => wpb_key() ) );
$p2_agent_sale = is_wp_error( $p2_agent_sale ) ? null : $p2_agent_sale['sale'];
$p2_r = $p2_agent_sale ? P2Pos::receipt( $p2_agent_sale->sale_id ) : null;
check( 'L902: agent model – the organizer is the issuer (legal name, address, tax number)', $p2_r && 'agent' === $p2_r['sales_model'] && 'P2 Veranstaltungen e. K.' === $p2_r['seller']['name'] && str_contains( $p2_r['seller']['address'], 'München' ) && '143/123/45678' === $p2_r['seller']['tax_number'] );
$p2_notes = $p2_r ? implode( "\n", $p2_r['seller_notes'] ) : '';
check( 'L902: agent model – notes name the platform as agent and carry the organizer small business note', str_contains( $p2_notes, 'Vermittelt durch P2 Plattform GmbH' ) && str_contains( $p2_notes, '§ 19 UStG' ) && str_contains( $p2_notes, 'im Namen und für Rechnung des Veranstalters' ) );
$p2_html = $p2_agent_sale ? P2Pos::receipt_html( $p2_agent_sale->sale_id ) : '';
check( 'L902: receipt print shows the organizer, its tax number and the notes, not the platform as issuer', str_contains( $p2_html, '<strong>P2 Veranstaltungen e. K.</strong>' ) && str_contains( $p2_html, 'Steuernummer: 143/123/45678' ) && str_contains( $p2_html, 'Vermittelt durch P2 Plattform GmbH' ) && ! str_contains( $p2_html, '<strong>P2 Plattform GmbH</strong>' ) );

P2Partners::set_events( $p2_partner, array() );
if ( ! is_wp_error( $p2_session ) && is_object( $p2_session ) ) { P2Pos::close_session( $p2_session->session_id, PassCaptain\BoxOffice\ZReport::compute( $p2_session->session_id )['cash']['expected'] ); }
Settings::update( $p2_saved );
$GLOBALS['wpcap_test_user_id'] = $p2_user;
$GLOBALS['wpcap_test_denied_caps'] = $p2_denied;
