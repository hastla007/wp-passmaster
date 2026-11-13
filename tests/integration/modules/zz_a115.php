<?php
/**
 * A115: country tax profiles bound to orders (venue country, tax class, sale date), review workflow
 * ("Steuerprüfung", four-eyes), stored rate source per order line, documents/reports use the line rate.
 * Included from run.php (helpers from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;
use PassCaptain\Tax\Profiles;

echo "A115: country tax profiles and tax review\n";
global $wpdb;
$p = $wpdb->prefix;
$a115_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a115_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$a115_as = static function ( $user_id, array $denied = array( 'manage_options' ) ) {
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$GLOBALS['wpcap_test_denied_caps'] = $denied;
};
$a115_audit = static function ( $action, $id ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_type='tax_profile' AND object_id=%d", $action, $id ) );
};
$a115_line = static function ( $order_id ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=%d ORDER BY item_id LIMIT 1", $order_id ) );
};
$a115_buy = static function ( $type, $qty = 1, array $internal = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => 'a115@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'a115-' . uniqid( '', true ) ), $internal );
	if ( is_wp_error( $r ) ) { return 0; }
	if ( 'paid' !== $r['payment_status'] ) { Orders::confirm_external_payment( $r['order_id'], 'test' ); }
	return (int) $r['order_id'];
};
// Only profiles of this test count (other tests never create any, but keep the state clean).
$wpdb->query( "DELETE FROM {$p}wpcap_tax_profiles WHERE country_code IN ('AT','DE') AND tax_class IN ('culture','reduced')" );
Profiles::flush();
check( 'four-eyes for tax profiles is on by default', 1 === PassCaptain\Settings::defaults()['tax_profile_four_eyes'] );
Settings::update( array( 'seller_country' => 'DE', 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'tax_profile_four_eyes' => 1 ) );

check( 'schema 3.9.7: venue country, line tax source and review columns exist', version_compare( PassCaptain\Installer::DB_VERSION, '3.9.7', '>=' ) && Profiles::ready()
	&& null !== $wpdb->get_row( "SHOW COLUMNS FROM {$p}wpcap_occurrences LIKE 'venue_country'" )
	&& 4 === count( $wpdb->get_results( "SHOW COLUMNS FROM {$p}wpcap_order_items WHERE Field IN ('tax_source','tax_profile_id','tax_profile_version','tax_country')" ) )
	&& 3 === count( $wpdb->get_results( "SHOW COLUMNS FROM {$p}wpcap_tax_profiles WHERE Field IN ('created_by','reviewed_by','retired_at')" ) ) );

// Event in Austria, sold by a German seller; ticket type with own rate 7 % and tax class "culture".
$a115_ev = ex_event( 'A115 Wien' );
$a115_occ = ex_occ( $a115_ev );
$wpdb->update( "{$p}wpcap_occurrences", array( 'venue_country' => 'AT' ), array( 'occurrence_id' => $a115_occ ) );
$a115_type = ex_type( $a115_occ, 'Kultur', 11300, 0, 700 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'tax_class' => 'culture' ), array( 'ticket_type_id' => $a115_type ) );
$a115_plain = ex_type( $a115_occ, 'Ohne Klasse', 11300, 0, 700 );
// Occurrence without its own country → seller country.
$a115_occ_de = ex_occ( $a115_ev );
$a115_type_de = ex_type( $a115_occ_de, 'Kultur DE', 10700, 0, 700 );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'tax_class' => 'culture' ), array( 'ticket_type_id' => $a115_type_de ) );

check( 'venue country: own field, else the seller country', 'AT' === Profiles::venue_country( $a115_occ ) && 'DE' === Profiles::venue_country( $a115_occ_de ) );
Settings::update( array( 'seller_country' => 'at' ) );
check( 'venue country fallback follows the seller setting (normalised)', 'AT' === Profiles::venue_country( (object) array( 'venue_country' => '' ) ) );
Settings::update( array( 'seller_country' => 'DE' ) );

// No profile: behaviour unchanged (ticket type rate), but the line records source and country.
$a115_o0 = $a115_buy( $a115_type );
$a115_l0 = $a115_line( $a115_o0 );
check( 'without an active profile the ticket type rate applies (7 %), source and country stored', $a115_l0 && 700 === (int) $a115_l0->tax_rate_bp && Orders::gross_tax( 11300, 700 ) === (int) $a115_l0->tax_minor && 'ticket_type' === $a115_l0->tax_source && 'AT' === $a115_l0->tax_country && 0 === (int) $a115_l0->tax_profile_id );

// Rights: no finance capability → no drafts.
$a115_as( 7, array( 'manage_options', 'wpcap_view_finance' ) );
$a115_denied = Profiles::save_draft( array( 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1300, 'version' => 'x', 'effective_from' => '2020-01-01' ) );
check( 'without finance rights drafts are refused (403)', is_wp_error( $a115_denied ) && 403 === (int) $a115_denied->get_error_data()['status'] );

// Finance user 5 creates the draft.
$a115_as( 5 );
check( 'validation: bad country, rate over 100 %, bad dates refused', is_wp_error( Profiles::save_draft( array( 'country_code' => 'AUT', 'tax_class' => 'culture', 'rate_basis_points' => 1300, 'version' => 'v', 'effective_from' => '2020-01-01' ) ) )
	&& is_wp_error( Profiles::save_draft( array( 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 10001, 'version' => 'v', 'effective_from' => '2020-01-01' ) ) )
	&& is_wp_error( Profiles::save_draft( array( 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1300, 'version' => 'v', 'effective_from' => '2020-02-30' ) ) )
	&& is_wp_error( Profiles::save_draft( array( 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1300, 'version' => 'v', 'effective_from' => '2020-05-01', 'effective_until' => '2020-04-01' ) ) ) );
$a115_p1 = Profiles::save_draft( array( 'country_code' => 'at', 'tax_class' => 'culture', 'rate_basis_points' => 1200, 'version' => '2020-a', 'effective_from' => '2020-01-01' ) );
check( 'draft created with creator and audit entry', is_int( $a115_p1 ) && 'draft' === Profiles::get( $a115_p1 )->status && 'AT' === Profiles::get( $a115_p1 )->country_code && 5 === (int) Profiles::get( $a115_p1 )->created_by && 1 === $a115_audit( 'tax_profile_created', $a115_p1 ) );
$a115_edit = Profiles::save_draft( array( 'tax_profile_id' => $a115_p1, 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1300, 'version' => '2020-a', 'effective_from' => '2020-01-01' ) );
check( 'draft can be edited (audited with before/after)', $a115_p1 === $a115_edit && 1300 === (int) Profiles::get( $a115_p1 )->rate_basis_points && 1 === $a115_audit( 'tax_profile_updated', $a115_p1 ) );
$a115_o1 = $a115_buy( $a115_type );
check( 'a draft is never used for orders', 700 === (int) $a115_line( $a115_o1 )->tax_rate_bp );

// Four-eyes: creator may not activate; another finance user may.
$a115_self = Profiles::activate( $a115_p1 );
check( 'four-eyes: creator cannot activate own profile (403, audited)', is_wp_error( $a115_self ) && 'wpcap_tax_profile_four_eyes' === $a115_self->get_error_code() && 'draft' === Profiles::get( $a115_p1 )->status && 1 === $a115_audit( 'tax_profile_review_denied', $a115_p1 ) );
// A second user who edited the draft is blocked as well.
$a115_as( 8 );
Profiles::save_draft( array( 'tax_profile_id' => $a115_p1, 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1300, 'version' => '2020-a', 'effective_from' => '2020-01-01' ) );
check( 'four-eyes: last editor cannot activate either', is_wp_error( Profiles::activate( $a115_p1 ) ) && 8 === (int) Profiles::get( $a115_p1 )->updated_by );
$a115_as( 7, array( 'manage_options', 'wpcap_view_finance' ) );
check( 'review without finance rights refused', is_wp_error( Profiles::activate( $a115_p1 ) ) && 'draft' === Profiles::get( $a115_p1 )->status );
Settings::update( array( 'tax_profile_four_eyes' => 0 ) );
$a115_as( 5 );
check( 'four-eyes switched off: the creator would be allowed (check only)', false === Profiles::self_review_blocked( Profiles::get( $a115_p1 ) ) );
Settings::update( array( 'tax_profile_four_eyes' => 1 ) );
$a115_as( 1, array() );
check( 'administrators are exempt from the four-eyes rule (like guestlist_four_eyes)', false === Profiles::self_review_blocked( (object) array( 'created_by' => 1, 'updated_by' => 1 ) ) );
$a115_as( 6 );
$a115_ok = Profiles::activate( $a115_p1, 'BMF-Info geprüft' );
$a115_row = Profiles::get( $a115_p1 );
check( 'another finance user reviews and activates (reviewer, note, audit)', true === $a115_ok && 'active' === $a115_row->status && 6 === (int) $a115_row->reviewed_by && 'BMF-Info geprüft' === $a115_row->review_note && null !== $a115_row->reviewed_at && 1 === $a115_audit( 'tax_profile_activated', $a115_p1 ) );
check( 'an active profile cannot be edited (409)', is_wp_error( $e = Profiles::save_draft( array( 'tax_profile_id' => $a115_p1, 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1000, 'version' => 'x', 'effective_from' => '2020-01-01' ) ) ) && 409 === (int) $e->get_error_data()['status'] && 1300 === (int) Profiles::get( $a115_p1 )->rate_basis_points );
check( 'activating twice is refused', is_wp_error( Profiles::activate( $a115_p1 ) ) );

// Orders now use the profile rate for AT/culture.
$a115_o2 = $a115_buy( $a115_type, 2 );
$a115_l2 = $a115_line( $a115_o2 );
check( 'active profile: line uses 13 % with source, profile, version and country stored', 1300 === (int) $a115_l2->tax_rate_bp && 2600 === (int) $a115_l2->tax_minor && 'profile' === $a115_l2->tax_source && $a115_p1 === (int) $a115_l2->tax_profile_id && '2020-a' === $a115_l2->tax_profile_version && 'AT' === $a115_l2->tax_country && 2600 === (int) Orders::get( $a115_o2 )->tax_minor );
check( 'ticket type without tax class keeps its own rate', 700 === (int) $a115_line( $a115_buy( $a115_plain ) )->tax_rate_bp );
check( 'occurrence in the seller country (DE) has no DE profile → own rate', 700 === (int) $a115_line( $a115_buy( $a115_type_de ) )->tax_rate_bp );
check( 'earlier orders keep the rate they were sold with', 700 === (int) $a115_line( $a115_o0 )->tax_rate_bp );

// Channels: WooCommerce takes Woo's taxes (no profile); a fixed channel VAT is marked external.
$a115_woo = $a115_buy( $a115_type, 1, array( 'external' => true, 'source' => 'woocommerce', 'key_namespace' => 'woocommerce', 'unit_prices' => array( $a115_type => 11300 ), 'currency' => 'EUR' ) );
$a115_lw = $a115_line( $a115_woo );
check( 'WooCommerce orders ignore tax profiles (ticket type rate)', $a115_lw && 700 === (int) $a115_lw->tax_rate_bp && 'ticket_type' === $a115_lw->tax_source );
$a115_fixed = $a115_buy( $a115_type, 1, array( 'external' => true, 'source' => 'voucher', 'key_namespace' => 'a115v', 'unit_prices' => array( $a115_type => 0 ), 'line_totals' => array( $a115_type => 0 ), 'line_taxes' => array( $a115_type => 0 ) ) );
check( 'channel-fixed VAT (single-purpose voucher) is stored as source "external"', 'external' === $a115_line( $a115_fixed )->tax_source && 0 === (int) $a115_line( $a115_fixed )->tax_minor );
$a115_filter = static fn() => false;
add_filter( 'wpcap_tax_profiles_apply', $a115_filter );
check( 'filter wpcap_tax_profiles_apply can switch profiles off per channel', 700 === (int) $a115_line( $a115_buy( $a115_type ) )->tax_rate_bp );
remove_filter( 'wpcap_tax_profiles_apply', $a115_filter );
$a115_pos = $a115_buy( $a115_type, 1, array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true, 'allow_door_sales' => true ) );
check( 'box office sale uses the profile; its catalogue shows the resolved rate', 1300 === (int) $a115_line( $a115_pos )->tax_rate_bp && 1300 === (int) array_column( PassCaptain\BoxOffice\Service::ticket_types( $a115_occ ), 'tax_rate_bp', 'ticket_type_id' )[ $a115_type ] );
$a115_type_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a115_type ) );
check( 'partner API: net price list entries get the profile rate (100,00 net → 113,00)', 11300 === PassCaptain\Partners\Sales::gross_price( array( 'price_minor' => 10000, 'price_is_net' => true ), $a115_type_row ) );

// Validity window: a profile starting tomorrow does not apply today.
$a115_tomorrow = gmdate( 'Y-m-d', time() + 2 * 86400 );
$a115_as( 5 );
$a115_pr = Profiles::save_draft( array( 'country_code' => 'AT', 'tax_class' => 'reduced', 'rate_basis_points' => 500, 'version' => 'future', 'effective_from' => $a115_tomorrow ) );
$a115_as( 6 );
Profiles::activate( $a115_pr );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'tax_class' => 'reduced' ), array( 'ticket_type_id' => $a115_plain ) );
check( 'profile not yet valid on the sale date → ticket type rate', 'active' === Profiles::get( $a115_pr )->status && 700 === (int) $a115_line( $a115_buy( $a115_plain ) )->tax_rate_bp );
$a115_res = Profiles::resolve( $a115_type_row, null, gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ) );
check( 'resolve() for a later sale date picks the profile valid then', 'profile' === $a115_res['source'] );
$a115_res2 = Profiles::resolve( (object) array( 'tax_rate_bp' => 700, 'tax_class' => 'reduced', 'occurrence_id' => $a115_occ ), null, gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ) );
check( 'resolve(): future profile applies to sales from its start date', 500 === $a115_res2['rate_bp'] && 'future' === $a115_res2['version'] );

// Overlap: a second active profile for AT/culture in an overlapping period is rejected.
$a115_as( 5 );
$a115_p2 = Profiles::save_draft( array( 'country_code' => 'AT', 'tax_class' => 'culture', 'rate_basis_points' => 1000, 'version' => '2025-b', 'effective_from' => '2025-01-01', 'effective_until' => '2030-12-31' ) );
$a115_as( 6 );
$a115_ov = Profiles::activate( $a115_p2 );
check( 'overlapping active profile for the same country/class is rejected (409, audited)', is_wp_error( $a115_ov ) && 'wpcap_tax_profile_overlap' === $a115_ov->get_error_code() && false !== strpos( $a115_ov->get_error_message(), '2020-a' ) && 'draft' === Profiles::get( $a115_p2 )->status && 1 === $a115_audit( 'tax_profile_review_denied', $a115_p2 ) );
$a115_as( 5 );
$a115_p3 = Profiles::save_draft( array( 'country_code' => 'DE', 'tax_class' => 'culture', 'rate_basis_points' => 700, 'version' => 'de-1', 'effective_from' => '2020-01-01' ) );
$a115_as( 6 );
check( 'same class in another country does not overlap', true === Profiles::activate( $a115_p3 ) );
check( 'DE occurrence now uses the DE profile', 'profile' === $a115_line( $a115_buy( $a115_type_de ) )->tax_source );

// Documents and reports use the stored line rate.
$a115_inv = Invoices::issue_for_order( $a115_o2 );
$a115_inv_line = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d", (int) ( $a115_inv['invoice_id'] ?? 0 ) ) );
check( 'invoice line shows the profile rate (13 %) and the line VAT', $a115_inv_line && 13.0 === (float) $a115_inv_line->tax_rate && 2600 === (int) $a115_inv_line->tax_minor );

// Retire: new orders fall back, old documents stay reproducible.
$a115_as( 5 );
check( 'retire: active profile goes out of force (audited)', true === Profiles::retire( $a115_p1, 'Satzänderung' ) && 'retired' === Profiles::get( $a115_p1 )->status && 5 === (int) Profiles::get( $a115_p1 )->retired_by && 1 === $a115_audit( 'tax_profile_retired', $a115_p1 ) );
check( 'retired twice is refused', is_wp_error( Profiles::retire( $a115_p1 ) ) );
check( 'after retiring: new orders use the ticket type rate again', 700 === (int) $a115_line( $a115_buy( $a115_type ) )->tax_rate_bp );
$a115_as( 6 );
check( 'after retiring the old profile, the new version can be activated', true === Profiles::activate( $a115_p2 ) );
check( 'new version (10 %) applies to new orders', 1000 === (int) $a115_line( $a115_buy( $a115_type ) )->tax_rate_bp );
check( 'order sold under the retired profile keeps 13 % and its profile reference', 1300 === (int) $a115_line( $a115_o2 )->tax_rate_bp && $a115_p1 === (int) $a115_line( $a115_o2 )->tax_profile_id );

$a115_tix = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a115_o2, 'valid' ) );
$a115_r = Refunds::request( $a115_o2, array( $a115_tix[0] ), 'Teil', 'a115-r' );
$a115_cn = is_wp_error( $a115_r ) ? null : Invoices::credit_note_for_refund( $a115_r['refund_id'] );
$a115_cn_rates = $a115_cn ? Invoices::credit_rates( $a115_cn ) : array();
check( 'credit note after the profile change: per-rate row at the sold 13 %', array( array( 'rate_bp' => 1300, 'net' => 10000, 'tax' => 1300, 'gross' => 11300 ) ) === $a115_cn_rates );
$a115_rows = array_values( array_filter( Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d', time() + 86400 ) ), static fn( $r ) => is_array( $a115_cn ) && $r[1] === $a115_cn['credit_note_number'] ) );
check( 'accounting export: correction row with rate 13.00', 1 === count( $a115_rows ) && '13.00' === $a115_rows[0][8] && '-13.00' === $a115_rows[0][9] );
$a115_inv_rows = array_values( array_filter( Invoices::export_rows( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d', time() + 86400 ) ), static fn( $r ) => $r[1] === ( $a115_inv['invoice_number'] ?? '-' ) ) );
check( 'accounting export: invoice row with rate 13.00', 1 === count( $a115_inv_rows ) && '13.00' === $a115_inv_rows[0][8] );
$a115_report = PassCaptain\Reports\Data::build( array( 'from' => gmdate( 'Y-m-d', time() - 86400 ), 'to' => gmdate( 'Y-m-d', time() + 86400 ), 'event_id' => $a115_ev ), null );
check( 'report: VAT per rate from the stored lines (13 %: 26,00 + 13,00 box office, refund 13,00)', isset( $a115_report['rates'][1300], $a115_report['rates'][1000], $a115_report['rates'][700] ) && 3900 === (int) $a115_report['rates'][1300]['tax'] && 1300 === (int) $a115_report['rates'][1300]['refunded_tax'] );

// Order admin view label.
$a115_items = PassCaptain\Admin\Queries::order_items( $a115_o2 );
check( 'order view: rate source label names country, version and profile', 'Steuerprofil AT, Version 2020-a (#' . $a115_p1 . ')' === Profiles::source_label( $a115_items[0] ) && 'Satz der Ticketart (Leistungsort AT)' === Profiles::source_label( $a115_l0 ) && 'Satz der Ticketart (vor Einführung der Steuerprofile erfasst)' === Profiles::source_label( (object) array( 'tax_source' => '' ) ) );

// Admin forms: ticket type tax class and occurrence venue country.
$a115_as( 1, array() );
$a115_tt = PassCaptain\Admin\Queries::ticket_type( $a115_plain );
$a115_save = PassCaptain\Admin\EventsPage::save_ticket_type( array( 'ticket_type_id' => $a115_plain, 'name' => $a115_tt->name, 'currency' => 'EUR', 'price' => '113,00', 'tax_rate' => '7', 'max_per_order' => 10, 'tax_class' => 'Culture' ) );
check( 'ticket type form stores the tax class (sanitised)', is_array( $a115_save ) && 'culture' === $wpdb->get_var( $wpdb->prepare( "SELECT tax_class FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a115_plain ) ) );
$a115_oc = PassCaptain\Admin\Queries::occurrence( $a115_occ_de );
$a115_bad = PassCaptain\Admin\EventsPage::save_occurrence( array( 'occurrence_id' => $a115_occ_de, 'timezone' => 'Europe/Vienna', 'starts_at' => PassCaptain\Admin\Forms::utc_to_local( $a115_oc->starts_at, 'Europe/Vienna' ), 'location' => 'Wien', 'capacity' => 0, 'venue_country' => 'XYZ' ) );
$a115_good = PassCaptain\Admin\EventsPage::save_occurrence( array( 'occurrence_id' => $a115_occ_de, 'timezone' => 'Europe/Vienna', 'starts_at' => PassCaptain\Admin\Forms::utc_to_local( $a115_oc->starts_at, 'Europe/Vienna' ), 'location' => 'Wien', 'capacity' => 0, 'venue_country' => 'at' ) );
check( 'occurrence form validates and stores the venue country', is_wp_error( $a115_bad ) && is_array( $a115_good ) && 'AT' === $wpdb->get_var( $wpdb->prepare( "SELECT venue_country FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a115_occ_de ) ) );

// REST endpoint creates drafts only.
$a115_req = new WP_REST_Request( 'POST' );
$a115_req->set_body( wp_json_encode( array( 'country_code' => 'IT', 'tax_class' => 'culture', 'rate_basis_points' => 1000, 'version' => 'it-1', 'effective_from' => '2026-01-01', 'status' => 'active' ) ) );
$a115_rest = PassCaptain\Rest\Controller::save_tax_profile( $a115_req );
check( 'REST POST /tax-profiles creates a draft that requires review', $a115_rest instanceof WP_REST_Response && 'draft' === Profiles::get( $a115_rest->get_data()['tax_profile_id'] )->status && true === $a115_rest->get_data()['requires_review'] );
$a115_req2 = new WP_REST_Request( 'POST' );
$a115_req2->set_body( wp_json_encode( array( 'country_code' => 'ITA', 'rate_basis_points' => 1000, 'version' => 'it-1', 'effective_from' => '2026-01-01' ) ) );
check( 'REST validation error keeps its code', is_wp_error( $e = PassCaptain\Rest\Controller::save_tax_profile( $a115_req2 ) ) && 'wpcap_tax_profile_invalid' === $e->get_error_code() );

// Review page: registered in the finance area and renders.
check( 'menu: "Steuerprüfung" in the finance area with handlers', 'finance' === PassCaptain\Admin\Menu::group_of( PassCaptain\Admin\TaxPage::SLUG ) && isset( PassCaptain\Admin\Menu::handlers()['wpcap_tax_profile_activate'], PassCaptain\Admin\Menu::handlers()['wpcap_tax_profile_retire'], PassCaptain\Admin\Menu::handlers()['wpcap_tax_profile_save'] ) );
ob_start();
try { PassCaptain\Admin\TaxPage::render(); $a115_html = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $a115_html = 'EXCEPTION ' . $e->getMessage(); }
check( 'review page lists profiles, coverage and the draft form', false !== strpos( $a115_html, 'Steuerprüfung' ) && false !== strpos( $a115_html, '2020-a' ) && false !== strpos( $a115_html, 'Außer Kraft' ) && false !== strpos( $a115_html, 'Steuerklassen in Verwendung' ) && false !== strpos( $a115_html, 'Prüfen und aktivieren' ) );

$a115_docs = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' ) . (string) file_get_contents( WPCAP_PATH . 'docs/SALES-MODELS.md' );
check( 'documented: filter in HOOKS.md, tax profiles and WooCommerce scope in SALES-MODELS.md', false !== strpos( $a115_docs, '`wpcap_tax_profiles_apply`' ) && false !== strpos( $a115_docs, '## Länder-Steuerprofile' ) );

$wpdb->query( "DELETE FROM {$p}wpcap_tax_profiles" );
Profiles::flush();
$GLOBALS['wpcap_test_user_id'] = $a115_saved_user;
if ( null === $a115_saved_user ) { unset( $GLOBALS['wpcap_test_user_id'] ); }
$GLOBALS['wpcap_test_denied_caps'] = $a115_saved_denied;
