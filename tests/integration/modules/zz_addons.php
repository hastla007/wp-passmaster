<?php
/**
 * Add-on products bound to a main ticket (TODO Experte #12 "Zusatzprodukte", core part; schema 3.18.5).
 *
 * A ticket type of kind "addon" is sold only with at least one main ticket of the same occurrence in the
 * same order (optional limit per ticket), produces no ticket (nothing to check in), uses no seats of the
 * occurrence, appears as its own line with its own tax rate on the invoice and in the order summary, is
 * refunded with the last main ticket only, and is counted apart from tickets in the report.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Installer;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Sales\Addons;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;

echo "Add-on products (Zusatzprodukte)\n";
global $wpdb;
$p = $wpdb->prefix;
$ad_saved = Settings::all();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'require_2fa_for_refunds' => 0, 'service_fee_ticket_minor' => 0, 'service_fee_order_minor' => 0 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

check( 'ADDON schema 3.18.5: ticket types have kind and addon_max_per_ticket', '3.18.5' === Installer::DB_VERSION && Addons::ready() && PassCaptain\Log::has_column( 'wpcap_ticket_types', 'addon_max_per_ticket' ) );

/** Ticket type (2500, 10 % VAT) plus an add-on (800, 20 % VAT) on the same occurrence. */
function ad_setup( $addon_capacity = 0, $max_per_ticket = 0, $occurrence_capacity = 0 ) {
	global $wpdb;
	$ticket = make_type( 0, 10, 2500, $occurrence_capacity );
	$wpdb->update( $wpdb->prefix . 'wpcap_ticket_types', array( 'tax_rate_bp' => 1000 ), array( 'ticket_type_id' => $ticket ) );
	$occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $ticket ) );
	$wpdb->insert( $wpdb->prefix . 'wpcap_ticket_types', array( 'occurrence_id' => $occ, 'name' => 'Parkplatz', 'price_minor' => 800, 'currency' => 'EUR', 'capacity' => $addon_capacity, 'max_per_order' => 10, 'status' => 'active', 'tax_rate_bp' => 2000, 'kind' => 'addon', 'addon_max_per_ticket' => $max_per_ticket, 'attendee_name' => 'off' ) );
	return array( $ticket, (int) $wpdb->insert_id, $occ );
}
function ad_order( array $items, $pay = true ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	$order = Orders::create( array( 'email' => 'ad-' . uniqid() . '@example.com', 'items' => $lines, 'idempotency_key' => 'ad-' . wp_generate_uuid4() ) );
	if ( $pay && is_array( $order ) ) { Orders::confirm_manual_payment( (int) $order['order_id'] ); }
	return $order;
}
function ad_reason( $result ) {
	return is_wp_error( $result ) ? (string) ( $result->get_error_data()['reason'] ?? $result->get_error_code() ) : 'ok';
}
function ad_tickets( $order_id, $type_id = 0 ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d" . ( $type_id ? ' AND ticket_type_id=' . (int) $type_id : '' ) . ' ORDER BY ticket_id', (int) $order_id ) ) );
}

// Upgrade: existing types stay "ticket" (column defaults); simulated with a WordPress-like dbDelta (ADD COLUMN).
$ad_t0 = make_type( 0 );
$wpdb->query( "ALTER TABLE {$p}wpcap_ticket_types DROP COLUMN kind, DROP COLUMN addon_max_per_ticket" );
$ad_create = '';
foreach ( Installer::schema_statements() as $ad_sql ) { if ( false !== strpos( $ad_sql, "CREATE TABLE {$p}wpcap_ticket_types " ) ) { $ad_create = $ad_sql; } }
$ad_cols = array_map( 'strtolower', $wpdb->get_col( "SHOW COLUMNS FROM {$p}wpcap_ticket_types" ) );
foreach ( preg_split( '/\r?\n/', $ad_create ) as $ad_line ) {
	$ad_line = trim( rtrim( trim( $ad_line ), ',' ) );
	if ( preg_match( '/^([a-z_]+)\s+(varchar|smallint|bigint|int|tinyint|text|datetime|char|longtext)/', $ad_line, $ad_m ) && ! in_array( $ad_m[1], $ad_cols, true ) ) { $wpdb->query( "ALTER TABLE {$p}wpcap_ticket_types ADD COLUMN $ad_line" ); }
}
$ad_old = $wpdb->get_row( $wpdb->prepare( "SELECT kind,addon_max_per_ticket FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $ad_t0 ) );
$ad_any_addon = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_types WHERE kind<>'ticket'" );
check( 'ADDON upgrade: existing ticket types get kind "ticket" and no add-on limit', $ad_old && 'ticket' === $ad_old->kind && 0 === (int) $ad_old->addon_max_per_ticket && 0 === $ad_any_addon && 'ticket' === Addons::kind( (object) array() ) );

// Checkout rules.
list( $ad_ticket, $ad_addon, $ad_occ ) = ad_setup( 0, 1 );
list( $ad_other_ticket ) = ad_setup();
check( 'ADDON checkout: an add-on alone is refused (addon_requires_ticket)', 'addon_requires_ticket' === ad_reason( ad_order( array( $ad_addon => 1 ), false ) ) );
check( 'ADDON checkout: a ticket of another occurrence does not unlock the add-on', 'addon_requires_ticket' === ad_reason( ad_order( array( $ad_addon => 1, $ad_other_ticket => 1 ), false ) ) );
check( 'ADDON checkout: more add-ons than allowed per ticket are refused (addon_limit)', 'addon_limit' === ad_reason( ad_order( array( $ad_ticket => 2, $ad_addon => 3 ), false ) ) );
$ad_refused = ad_order( array( $ad_addon => 1 ), false );
check( 'ADDON checkout: the refusal names the rule in German', is_wp_error( $ad_refused ) && false !== strpos( $ad_refused->get_error_message(), 'Zusatzprodukte' ) && 409 === (int) $ad_refused->get_error_data()['status'] );
$ad_comp = Orders::create( array( 'email' => 'ad-comp@example.com', 'items' => array( array( 'ticket_type_id' => $ad_addon, 'quantity' => 1 ) ), 'idempotency_key' => 'ad-comp-1' ), array( 'comp' => true ) );
check( 'ADDON checkout: the rule also holds for trusted channels (comps)', 'addon_requires_ticket' === ad_reason( $ad_comp ) );
$ad_ok = ad_order( array( $ad_ticket => 2, $ad_addon => 2 ) );
check( 'ADDON checkout: two tickets with two add-ons (limit 1 per ticket) are accepted, total 2×2500 + 2×800', is_array( $ad_ok ) && 6600 === (int) $ad_ok['total_minor'] && 'paid' === Orders::get( (int) $ad_ok['order_id'] )->payment_status );
$ad_oid = (int) $ad_ok['order_id'];
check( 'ADDON unit rule: Addons::checkout_error() per occurrence, no limit with 0', '' === Addons::checkout_error( array( 1 => 1, 2 => 5 ), array( 1 => (object) array( 'occurrence_id' => 7, 'kind' => 'ticket' ), 2 => (object) array( 'occurrence_id' => 7, 'kind' => 'addon', 'addon_max_per_ticket' => 0 ) ) ) && 'addon_limit' === Addons::checkout_error( array( 1 => 1, 2 => 3 ), array( 1 => (object) array( 'occurrence_id' => 7 ), 2 => (object) array( 'occurrence_id' => 7, 'kind' => 'addon', 'addon_max_per_ticket' => 2 ) ) ) );

// No ticket, no check-in code; the line is listed in the order summary and on the invoice.
check( 'ADDON issuance: tickets only for the main tickets, none for the add-on', 2 === count( ad_tickets( $ad_oid, $ad_ticket ) ) && 0 === count( ad_tickets( $ad_oid, $ad_addon ) ) );
check( 'ADDON issuance: the order counts as complete (no missing tickets, no re-issue by the sweep)', 0 === Orders::missing_tickets( $ad_oid ) );
$ad_addon_codes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_type_id=%d", $ad_addon ) );
check( 'ADDON check-in: no ticket code exists for the add-on type (nothing can be scanned)', 0 === $ad_addon_codes );
$ad_summary = Orders::summary_lines( $ad_oid );
$ad_summary_addon = array_values( array_filter( $ad_summary, static fn( $l ) => false !== strpos( $l['ticket'], 'Parkplatz' ) ) );
check( 'ADDON summary (portal, mail): the add-on line is listed as "Parkplatz (Zusatzprodukt)" with its amount', 1 === count( $ad_summary_addon ) && 'Parkplatz (Zusatzprodukt)' === $ad_summary_addon[0]['ticket'] && 2 === $ad_summary_addon[0]['quantity'] && 1600 === $ad_summary_addon[0]['gross_minor'] );
$ad_inv = Invoices::issue_for_order( $ad_oid );
$ad_lines = is_array( $ad_inv ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d ORDER BY invoice_line_id", (int) $ad_inv['invoice_id'] ) ) : array();
$ad_inv_addon = array_values( array_filter( $ad_lines, static fn( $l ) => false !== strpos( $l->description, 'Parkplatz' ) ) );
check( 'ADDON invoice: own line with its own tax rate (20 %, VAT 267 of 1600) next to the ticket line (10 %)', 2 === count( $ad_lines ) && 1 === count( $ad_inv_addon ) && 20.0 === (float) $ad_inv_addon[0]->tax_rate && 1600 === (int) $ad_inv_addon[0]->total_minor && Orders::gross_tax( 1600, 2000 ) === (int) $ad_inv_addon[0]->tax_minor );
check( 'ADDON order VAT: ticket VAT at 10 % plus add-on VAT at 20 %', Orders::gross_tax( 5000, 1000 ) + Orders::gross_tax( 1600, 2000 ) === (int) Orders::get( $ad_oid )->tax_minor );
check( 'ADDON service fee: an add-on carries no per-ticket service fee', 0 === ServiceFee::per_ticket( (object) array( 'price_minor' => 800, 'kind' => 'addon', 'service_fee_minor' => 300 ) ) && 300 === ServiceFee::per_ticket( (object) array( 'price_minor' => 800, 'kind' => 'ticket', 'service_fee_minor' => 300 ) ) );

// Refunds: a partial refund of main tickets keeps the add-ons; the last main ticket takes them along.
$ad_t = ad_tickets( $ad_oid, $ad_ticket );
$ad_r1 = Refunds::request( $ad_oid, array( $ad_t[0] ), 'Kann nicht kommen', 'ad-r1' );
check( 'ADDON refund: the first of two tickets refunds only the ticket (2500), the order stays paid', is_array( $ad_r1 ) && 'completed' === $ad_r1['status'] && 2500 === (int) $ad_r1['amount_minor'] && 'paid' === Orders::get( $ad_oid )->payment_status );
$ad_preview = Refunds::preview( $ad_oid, array( $ad_t[1] ) );
check( 'ADDON refund: the preview of the last ticket includes both add-ons (2500 + 1600)', is_array( $ad_preview ) && 4100 === (int) $ad_preview['amount_minor'] );
$ad_r2 = Refunds::request( $ad_oid, array( $ad_t[1] ), 'Rest', 'ad-r2' );
check( 'ADDON refund: refunding the last main ticket refunds the add-ons too and closes the order', is_array( $ad_r2 ) && 4100 === (int) $ad_r2['amount_minor'] && 6600 === (int) Orders::get( $ad_oid )->refunded_minor && 'refunded' === Orders::get( $ad_oid )->payment_status );
$ad_vat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(tax_minor) FROM {$p}wpcap_refunds WHERE order_id=%d AND status='completed'", $ad_oid ) );
check( 'ADDON refund: refund VAT adds up to the order VAT (both rates)', $ad_vat === (int) Orders::get( $ad_oid )->tax_minor );
PassCaptain\CapacityCounters::flush_dirty();
check( 'ADDON refund: the add-on units are released (type counter back to 0)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT sold_qty FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $ad_addon ) ) );
$ad_amount = ad_order( array( $ad_ticket => 1, $ad_addon => 1 ) );
$ad_amount_r = Refunds::request_amount( (int) $ad_amount['order_id'], 800, 'Parkplatz nicht genutzt', 'ad-amount' );
check( 'ADDON refund: an add-on alone can be refunded by amount; the ticket stays valid', is_array( $ad_amount_r ) && 800 === (int) $ad_amount_r['amount_minor'] && 1 === count( array_filter( Orders::tickets_for_order( (int) $ad_amount['order_id'] ), static fn( $t ) => 'valid' === $t->status ) ) );

// Seats: an add-on uses no occurrence capacity, only its own.
list( $ad_cap_ticket, $ad_cap_addon, $ad_cap_occ ) = ad_setup( 3, 0, 2 );
$ad_cap = ad_order( array( $ad_cap_ticket => 2, $ad_cap_addon => 2 ) );
check( 'ADDON capacity: 2 tickets + 2 add-ons fit an occurrence of 2 seats', is_array( $ad_cap ) && 0 === Orders::availability( $ad_cap_ticket ) && 1 === Orders::availability( $ad_cap_addon ) );
Orders::flush_availability_cache();
check( 'ADDON capacity: the occurrence counter holds both, the admin "sold" shows the tickets only', 4 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT sold_qty FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $ad_cap_occ ) ) && 2 === PassCaptain\Admin\Queries::sold_for_occurrence( $ad_cap_occ ) );
list( $ad_cap2_ticket, $ad_cap2_addon ) = ad_setup( 1, 0, 3 );
ad_order( array( $ad_cap2_ticket => 1, $ad_cap2_addon => 1 ) );
check( 'ADDON capacity: the add-on capacity is its own (sold out after 1, tickets still available)', 'sold_out' === ad_reason( ad_order( array( $ad_cap2_ticket => 1, $ad_cap2_addon => 1 ), false ) ) && 2 === Orders::availability( $ad_cap2_ticket ) );
check( 'ADDON capacity: tickets of a date with sold add-ons can still be sold up to the seats', is_array( ad_order( array( $ad_cap2_ticket => 2 ) ) ) && 0 === Orders::availability( $ad_cap2_ticket ) );
$ad_drift = PassCaptain\CapacityCounters::drift( array( $ad_cap_occ ) );
check( 'ADDON capacity: the maintained counters stay consistent (no drift)', array() === $ad_drift );

// Report: add-ons counted apart from tickets.
$ad_report = PassCaptain\Reports\Data::build( array( 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'event_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $ad_cap_occ ) ) ), null );
check( 'ADDON report: 2 tickets and 2 add-ons counted separately, gross includes both', 2 === (int) $ad_report['total']['tickets'] && 2 === (int) $ad_report['total']['addons'] && 6600 === (int) $ad_report['total']['gross'] && array() === PassCaptain\Reports\Data::reconcile( $ad_report ) );

// Admin form: kind select, validation, lock after orders, attendee name off.
$ad_new = EventsPage::save_ticket_type( array( 'occurrence_id' => $ad_occ, 'name' => 'Merch-Shirt', 'currency' => 'EUR', 'price' => '20,00', 'tax_rate' => '20', 'max_per_order' => 5, 'capacity' => 0, 'kind' => 'addon', 'addon_max_per_ticket' => 2, 'attendee_name' => 'required' ) );
$ad_new_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d AND name='Merch-Shirt'", $ad_occ ) );
check( 'ADDON admin: a new add-on is saved with kind, limit and without attendee name', ! is_wp_error( $ad_new ) && $ad_new_row && 'addon' === $ad_new_row->kind && 2 === (int) $ad_new_row->addon_max_per_ticket && 'off' === $ad_new_row->attendee_name );
$ad_bad = EventsPage::save_ticket_type( array( 'occurrence_id' => $ad_occ, 'name' => 'X', 'currency' => 'EUR', 'price' => '1,00', 'tax_rate' => '20', 'max_per_order' => 5, 'capacity' => 0, 'kind' => 'bundle' ) );
$ad_bad_max = EventsPage::save_ticket_type( array( 'occurrence_id' => $ad_occ, 'name' => 'Y', 'currency' => 'EUR', 'price' => '1,00', 'tax_rate' => '20', 'max_per_order' => 5, 'capacity' => 0, 'kind' => 'addon', 'addon_max_per_ticket' => 101 ) );
check( 'ADDON admin: an unknown kind and a limit above 100 are refused (no silent clamp)', is_wp_error( $ad_bad ) && 'wpcap_type_kind' === $ad_bad->get_error_code() && is_wp_error( $ad_bad_max ) && 'wpcap_type_addon_max' === $ad_bad_max->get_error_code() );
$ad_lock = EventsPage::save_ticket_type( array( 'ticket_type_id' => $ad_ticket, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '25,00', 'tax_rate' => '10', 'max_per_order' => 10, 'capacity' => 0, 'kind' => 'addon' ) );
check( 'ADDON admin: the kind of a type with orders cannot change', is_wp_error( $ad_lock ) && 'wpcap_type_kind_locked' === $ad_lock->get_error_code() && 'ticket' === $wpdb->get_var( $wpdb->prepare( "SELECT kind FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $ad_ticket ) ) );
$ad_keep = EventsPage::save_ticket_type( array( 'ticket_type_id' => $ad_ticket, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '25,00', 'tax_rate' => '10', 'max_per_order' => 10, 'capacity' => 0 ) );
check( 'ADDON admin: a form without the kind field keeps the stored kind', ! is_wp_error( $ad_keep ) && 'ticket' === $wpdb->get_var( $wpdb->prepare( "SELECT kind FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $ad_ticket ) ) );

// No waitlist for add-ons, no exchange of a ticket into an add-on.
$GLOBALS['wpcap_test_transients'] = array();
$ad_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $ad_occ ) );
$ad_wl = PassCaptain\Waitlists::join( $ad_event, $ad_occ, $ad_addon, 'ad-wl@example.com', 1 );
check( 'ADDON waitlist: no waitlist sign-up for an add-on product', is_wp_error( $ad_wl ) && 'wpcap_waitlist_ticket' === $ad_wl->get_error_code() );
$ad_x_ticket = (object) array( 'ticket_id' => ad_tickets( (int) $ad_amount['order_id'], $ad_ticket )[0], 'payment_status' => 'paid', 'refunded' => 0, 'status' => 'valid', 'ticket_type_id' => $ad_ticket, 'currency' => 'EUR' );
$ad_x_target = (object) array( 'ticket_type_id' => $ad_addon, 'status' => 'active', 'occurrence_status' => 'scheduled', 'event_status' => 'published', 'currency' => 'EUR', 'kind' => 'addon' );
$ad_x = PassCaptain\Exchange\Service::validate( $ad_x_ticket, $ad_x_target, 'admin', array( 'enabled' => true ) );
check( 'ADDON exchange: a ticket cannot be exchanged into an add-on product', is_wp_error( $ad_x ) && 'wpcap_exchange_target_unavailable' === $ad_x->get_error_code() );

// Event card: add-ons in their own fieldset under the tickets; price "ab" from the tickets only.
list( $ad_c_ticket, $ad_c_addon, $ad_c_occ ) = ad_setup( 0, 1 );
$ad_c_types = array(
	(object) array( 'ticket_type_id' => $ad_c_ticket, 'occurrence_id' => $ad_c_occ, 'name' => 'Standard', 'price_minor' => 2500, 'currency' => 'EUR', 'available' => null, 'kind' => 'ticket' ),
	(object) array( 'ticket_type_id' => $ad_c_addon, 'occurrence_id' => $ad_c_occ, 'name' => 'Parkplatz', 'price_minor' => 800, 'currency' => 'EUR', 'available' => 0, 'kind' => 'addon' ),
);
check( 'ADDON card: price and availability come from the tickets (a sold-out add-on is no sold-out date)', PassCaptain\Frontend\Format::money( 2500, 'EUR' ) === PassCaptain\Frontend\EventCard::price_from( Addons::tickets( $ad_c_types ) ) && null === PassCaptain\Frontend\EventCard::occurrence_available( $ad_c_types ) );
$ad_tpl = (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' );
check( 'ADDON card template: own fieldset "Zusatzprodukte" with data-kind="addon" and no attendee fields', false !== strpos( $ad_tpl, "class=\"wpcap-addons\"" ) && false !== strpos( $ad_tpl, "esc_html_e( 'Zusatzprodukte', 'pass-captain' )" ) && false !== strpos( $ad_tpl, 'data-kind="addon"' ) );

Settings::update( $ad_saved );
