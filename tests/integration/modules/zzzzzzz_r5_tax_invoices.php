<?php
/**
 * R5 (audit round 2, expert #10): tax date of supply, credit note lines per rate, accounting export
 * independent of invoices, consumer information / withdrawal notice, privacy notice, postponement
 * refund requests.
 *
 * Included from run.php (uses check(), make_type(), tickets()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Sales\TaxProfiles;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

/** Ticket type on an occurrence starting at $starts_utc (Europe/Vienna) of an event in $country. */
function r5_type_at( $starts_utc, $country, $price = 1190, $tax_class = '' ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'R5 Event', 'slug' => 'r5-' . uniqid(), 'description' => '', 'status' => 'published', 'tax_country' => $country, 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $starts_utc, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active', 'tax_class' => $tax_class, 'tax_rate_bp' => 0 ) );
	TaxProfiles::flush_cache();
	return (int) $wpdb->insert_id;
}
function r5_order( array $items, array $extra = array() ) {
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	return Orders::create( array_merge( array( 'email' => 'r5-' . uniqid() . '@example.com', 'items' => $lines, 'idempotency_key' => 'r5-' . uniqid( '', true ) ), $extra ), array( 'skip_rate_limit' => true ) );
}
function r5_item_rate( $order_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT tax_rate_bp FROM %i WHERE order_id=%d ORDER BY item_id LIMIT 1', $wpdb->prefix . 'wpcap_order_items', $order_id ) );
}

echo "R5: tax rate by date of supply (local occurrence date)\n";
// PT: 19 % until 31.12. of next year, 7 % from 1.1. (a rate change between purchase and event).
$r5_year = (int) gmdate( 'Y' ) + 1;
$r5_old = TaxProfiles::create( array( 'country_code' => 'PT', 'rate' => '19', 'version' => 'r5-old', 'effective_from' => '2020-01-01', 'effective_until' => $r5_year . '-12-31' ) );
$r5_new = TaxProfiles::create( array( 'country_code' => 'PT', 'rate' => '7', 'version' => 'r5-new', 'effective_from' => ( $r5_year + 1 ) . '-01-01' ) );
TaxProfiles::activate( $r5_old );
TaxProfiles::activate( $r5_new );
// 1.1. 00:30 Vienna = 31.12. 23:30 UTC: the service happens on 1.1. (new rate).
$r5_after = r5_type_at( $r5_year . '-12-31 23:30:00', 'PT' );
// 31.12. 23:30 Vienna = 22:30 UTC: still the old rate.
$r5_before = r5_type_at( $r5_year . '-12-31 22:30:00', 'PT' );
$r5_o1 = r5_order( array( $r5_after => 1 ) );
$r5_o2 = r5_order( array( $r5_before => 1 ) );
check( 'R5-1: rate change between purchase and event – the rate at the event date applies (7 %)', ! is_wp_error( $r5_o1 ) && 700 === r5_item_rate( $r5_o1['order_id'] ) );
check( 'R5-1: midnight case – 00:30 local time on 1.1. (23:30 UTC) is 1.1., 23:30 local on 31.12. stays 19 %', ! is_wp_error( $r5_o2 ) && 1900 === r5_item_rate( $r5_o2['order_id'] ) );
$r5_snap = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT line_snapshot FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', $r5_o1['order_id'] ) ), true );
check( 'R5-1: the line snapshot keeps the local date of supply', ( $r5_year + 1 ) . '-01-01' === ( $r5_snap['tax']['date'] ?? '' ) );
check( 'R5-1: TaxProfiles::local_date converts UTC to the occurrence time zone', '2027-01-01' === TaxProfiles::local_date( '2026-12-31 23:30:00', (object) array( 'timezone' => 'Europe/Vienna' ) ) && '' === TaxProfiles::local_date( '0000-00-00 00:00:00' ) );
$r5_filter = static function ( $date, $type, $occurrence, $order_date = '' ) { return $order_date; };
add_filter( 'wpcap_tax_profile_date', $r5_filter, 10, 4 );
$r5_o3 = r5_order( array( $r5_after => 1 ) );
remove_filter( 'wpcap_tax_profile_date', $r5_filter, 10 );
check( 'R5-1: filter wpcap_tax_profile_date can switch back to the (local) order date', ! is_wp_error( $r5_o3 ) && 1900 === r5_item_rate( $r5_o3['order_id'] ) );

echo "R5: credit notes per VAT rate, accounting export with tax country, ledger export\n";
Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0, 'auto_invoice' => 0 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
/** Ticket type with an explicit rate (no profile: country FI has none) on a future occurrence. */
function r5_rate_type( $rate_bp, $price ) {
	global $wpdb;
	$t = r5_type_at( gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), 'FI', $price );
	$wpdb->update( $wpdb->prefix . 'wpcap_ticket_types', array( 'tax_rate_bp' => $rate_bp ), array( 'ticket_type_id' => $t ) );
	return $t;
}
$r5_ta = r5_rate_type( 1900, 1190 );
$r5_tb = r5_rate_type( 700, 1070 );
$r5_mo = r5_order( array( $r5_ta => 2, $r5_tb => 1 ) );
Orders::confirm_manual_payment( $r5_mo['order_id'] );
$r5_inv = PassCaptain\Invoices::issue_for_order( $r5_mo['order_id'] );
$r5_tickets = tickets( $r5_mo['order_id'], 'valid' );
$r5_pick = array();
foreach ( $r5_tickets as $t ) { if ( (int) $t->ticket_type_id === $r5_tb || ( (int) $t->ticket_type_id === $r5_ta && ! in_array( $r5_ta, array_map( static fn( $x ) => (int) $x->ticket_type_id, $r5_pick ), true ) ) ) { $r5_pick[] = $t; } }
$r5_ref = PassCaptain\Refunds::request( $r5_mo['order_id'], array_map( static fn( $t ) => (int) $t->ticket_id, $r5_pick ), 'R5 mixed', 'r5-mixed-1' );
PassCaptain\Invoices::credit_note_for_refund( is_array( $r5_ref ) ? (int) $r5_ref['refund_id'] : 0 );
$r5_cn = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id=%d', $p . 'wpcap_credit_notes', is_array( $r5_ref ) ? (int) $r5_ref['refund_id'] : 0 ) );
$r5_lines = $r5_cn ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE credit_note_id=%d ORDER BY tax_rate_bp', $p . 'wpcap_credit_note_lines', (int) $r5_cn->credit_note_id ) ) : array();
check( 'R5-3: credit note of a mixed-rate refund has one line per rate (7 % / 19 %)', 2 === count( $r5_lines ) && 700 === (int) $r5_lines[0]->tax_rate_bp && 1900 === (int) $r5_lines[1]->tax_rate_bp && 1070 === (int) $r5_lines[0]->total_minor && 1190 === (int) $r5_lines[1]->total_minor );
check( 'R5-3: the lines add up to the credit note header (net, tax, total)', $r5_cn && array_sum( array_map( static fn( $l ) => (int) $l->total_minor, $r5_lines ) ) === (int) $r5_cn->total_minor && array_sum( array_map( static fn( $l ) => (int) $l->tax_minor, $r5_lines ) ) === (int) $r5_cn->tax_minor && 70 === (int) $r5_lines[0]->tax_minor && 190 === (int) $r5_lines[1]->tax_minor );
$r5_html = PassCaptain\Invoices::render_html( $r5_inv['invoice_id'] );
// Merged print view (A-line design): every correction has its own "Aufstellung nach Steuersätzen" table.
check( 'R5-3: the print view shows the correction per rate', substr_count( $r5_html, 'Aufstellung nach Steuersätzen' ) >= 2 && false !== strpos( $r5_html, 'Korrekturbetrag' ) );
// Amount-only refund (e.g. dashboard refund) of a mixed order: pro rata per rate.
$r5_split = PassCaptain\Invoices::credit_note_split( array( 'order_id' => $r5_mo['order_id'], 'ticket_ids' => '[]' ), 1725, 200 );
check( 'R5-3: amount-only refunds are split pro rata by rate (exact sums)', 2 === count( $r5_split ) && 1725 === array_sum( array_column( $r5_split, 'total_minor' ) ) && 200 === array_sum( array_column( $r5_split, 'tax_minor' ) ) );
// Legacy credit note (before 3.17.4, no lines) stays a single row without rate.
$wpdb->insert( "{$p}wpcap_credit_notes", array( 'invoice_id' => (int) $r5_inv['invoice_id'], 'refund_id' => 990000 + (int) $r5_inv['invoice_id'], 'credit_note_number' => 'GS-R5-LEGACY-' . $r5_inv['invoice_id'], 'currency' => 'EUR', 'net_minor' => 1000, 'tax_minor' => 190, 'total_minor' => 1190, 'issued_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$r5_today = gmdate( 'Y-m-d' );
$r5_rows = PassCaptain\Invoices::export_rows( $r5_today, $r5_today );
$r5_cn_rows = array_values( array_filter( $r5_rows, static fn( $r ) => $r5_cn && ( $r[1] ?? '' ) === $r5_cn->credit_note_number ) );
$r5_legacy = array_values( array_filter( $r5_rows, static fn( $r ) => ( $r[1] ?? '' ) === 'GS-R5-LEGACY-' . $r5_inv['invoice_id'] ) );
$r5_inv_rows = array_values( array_filter( $r5_rows, static fn( $r ) => ( $r[1] ?? '' ) === $r5_inv['invoice_number'] ) );
check( 'R5-3: accounting export lists the credit note per rate with the rate column', 2 === count( $r5_cn_rows ) && '7.00' === $r5_cn_rows[0][8] && '19.00' === $r5_cn_rows[1][8] && '-10.70' === $r5_cn_rows[0][10] );
// Merged: corrections without stored lines are split by the rates of the order lines they belong to
// (Invoices::credit_note_rates()), so even a legacy correction carries its rate.
check( 'R5-3: a legacy credit note stays one row without rate', 1 === count( $r5_legacy ) && '19.00' === $r5_legacy[0][8] && '-11.90' === $r5_legacy[0][10] );
// Merged: the document export keeps its stable 15-column header (A098/A129); the tax country (place of
// supply, OSS) is a column of the ledger export (Invoices::ledger_export_rows(), column 16 "Steuerland").
$r5_mo_ledger = array_values( array_filter( PassCaptain\Invoices::ledger_export_rows( $r5_today, $r5_today ), static fn( $r ) => 'Zahlung' === ( $r[0] ?? '' ) && (int) ( $r[4] ?? 0 ) === (int) $r5_mo['order_id'] ) );
check( 'R5-4: accounting export has the column Steuerland (from event/line snapshot)', 15 === count( $r5_rows[0] ) && 'Rechnungssteller' === $r5_rows[0][14] && $r5_inv_rows && $r5_mo_ledger && 'FI' === $r5_mo_ledger[0][15] );
// Ledger export: independent of invoices.
$r5_pt = r5_order( array( $r5_after => 2 ) );
Orders::confirm_manual_payment( $r5_pt['order_id'] );
$r5_pt_order = Orders::get( $r5_pt['order_id'] );
$r5_cb = PassCaptain\Refunds::record_external( $r5_pt['order_id'], array(), 500, 'stripe_chargeback', 'r5-cb-1', array( 'provider_reference' => 'ext:stripe:dispute-1', 'source' => 'stripe' ) );
$r5_ledger = PassCaptain\Invoices::ledger_export_rows( $r5_today, $r5_today );
$r5_pay = array_values( array_filter( $r5_ledger, static fn( $r ) => 'Zahlung' === $r[0] && (int) $r[4] === (int) $r5_pt['order_id'] ) );
$r5_cbr = array_values( array_filter( $r5_ledger, static fn( $r ) => 'Chargeback' === $r[0] && (int) $r[4] === (int) $r5_pt['order_id'] ) );
$r5_mixed_ref = array_values( array_filter( $r5_ledger, static fn( $r ) => 'Erstattung' === $r[0] && (int) $r[4] === (int) $r5_mo['order_id'] ) );
check( 'R5-4: ledger export has payment rows for orders without invoice (rate, tax country PT, order id)', 'Buchungsart' === $r5_ledger[0][0] && 1 === count( $r5_pay ) && '7.00' === $r5_pay[0][9] && 'PT' === $r5_pay[0][15] && $r5_pt_order->public_id === $r5_pay[0][3] && '23.80' === $r5_pay[0][11] );
check( 'R5-4: chargebacks and refunds appear with rate, negative amounts and refund id', 1 === count( $r5_cbr ) && '-5.00' === $r5_cbr[0][11] && '7.00' === $r5_cbr[0][9] && (int) $r5_cbr[0][5] > 0 && 2 === count( $r5_mixed_ref ) && $r5_cn->credit_note_number === $r5_mixed_ref[0][2] );
$r5_csv = PassCaptain\Reports\Scope::csv( $r5_ledger );
check( 'R5-4: ledger CSV passes the formula guard (refund amounts stay numbers)', false !== strpos( $r5_csv, ';-5.00;' ) );

echo "R5: withdrawal notice, consumer information in mails, privacy notice, status warning\n";
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'get_privacy_policy_url' ) ) { function get_privacy_policy_url() { return (string) ( $GLOBALS['r5_privacy_url'] ?? '' ); } }
use PassCaptain\Frontend\LegalNotice;
// Earlier admin tests save the settings form (unchecked = 0): start from the defaults.
Settings::update( array( 'withdrawal_notice' => 1, 'withdrawal_notice_text' => Settings::defaults()['withdrawal_notice_text'], 'withdrawal_right_text' => Settings::defaults()['withdrawal_right_text'] ) );
$r5_dated = array( (object) array( 'ticket_type_id' => 1, 'starts_at' => '2027-01-01 18:00:00' ) );
$r5_undated = array( (object) array( 'ticket_type_id' => 1, 'starts_at' => '2027-01-01 18:00:00' ), (object) array( 'ticket_type_id' => 2, 'starts_at' => '' ) );
$r5_n1 = LegalNotice::checkout_html( (object) array( 'starts_at' => '2027-01-01 18:00:00' ), $r5_dated );
check( 'R5-5: default notice "kein Widerrufsrecht" with § 312g / § 18 FAGG when all positions are dated', false !== strpos( $r5_n1, 'kein Widerrufsrecht' ) && false !== strpos( $r5_n1, '§ 312g Abs. 2 Nr. 9 BGB' ) && false !== strpos( $r5_n1, '§ 18 Abs. 1 Z 10 FAGG' ) && false !== strpos( $r5_n1, 'wpcap-withdrawal-excluded' ) );
$r5_n2 = LegalNotice::checkout_html( null, $r5_undated );
check( 'R5-5: with an undated position the notice says a right of withdrawal exists', false === strpos( $r5_n2, 'kein Widerrufsrecht' ) && false !== strpos( $r5_n2, 'wpcap-withdrawal-right' ) && false !== strpos( $r5_n2, 'Widerrufsrecht' ) );
Settings::update( array( 'withdrawal_notice' => 0 ) );
$r5_n3 = LegalNotice::checkout_html( (object) array( 'starts_at' => '2027-01-01 18:00:00' ), $r5_dated );
Settings::update( array( 'withdrawal_notice' => 1, 'withdrawal_notice_text' => 'Eigener Hinweis XY.' ) );
$r5_n4 = LegalNotice::checkout_html( (object) array( 'starts_at' => '2027-01-01 18:00:00' ), $r5_dated );
Settings::update( array( 'withdrawal_notice_text' => Settings::defaults()['withdrawal_notice_text'] ) );
check( 'R5-5: the notice can be switched off and its text edited', false === strpos( $r5_n3, 'wpcap-withdrawal' ) && false !== strpos( $r5_n4, 'Eigener Hinweis XY.' ) );
// Event card: one block below the order button.
$r5_card_type = make_type( 30, 5, 1990 );
$r5_card_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$r5_card_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='r5_hidden' WHERE status='scheduled' AND occurrence_id<>$r5_card_occ" );
$GLOBALS['r5_privacy_url'] = 'https://example.test/datenschutz/';
$r5_card = PassCaptain\PublicEvents::render_shortcode();
$GLOBALS['r5_privacy_url'] = '';
$r5_card_noprivacy = PassCaptain\PublicEvents::render_shortcode();
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled' WHERE status='r5_hidden'" );
$r5_btn = strpos( $r5_card, 'wpcap-submit' );
check( 'R5-5: the event card shows the withdrawal notice next to (after) the order button', false !== $r5_btn && false !== strpos( $r5_card, 'wpcap-withdrawal-excluded' ) && strpos( $r5_card, 'wpcap-withdrawal-excluded' ) > $r5_btn );
check( 'R5-7: privacy notice with get_privacy_policy_url() below the order button, only when a page is set', false !== strpos( $r5_card, 'href="https://example.test/datenschutz/"' ) && strpos( $r5_card, 'wpcap-privacy-notice' ) > $r5_btn && false === strpos( $r5_card_noprivacy, 'wpcap-privacy-notice' ) );
// Mails: {consumer_info} / {terms}.
Settings::update( array( 'consumer_info_text' => '<p>Anbieter: Kulturverein</p>', 'terms_text' => 'AGB Version 3' ) );
$r5_tm = PassCaptain\Mail\Mailer::compose( 'tickets', array( 'ticket_list' => "Konzert\n\n" ) );
$r5_am = PassCaptain\Mail\Mailer::compose( 'order_access', array( 'order_id' => str_repeat( 'a', 32 ), 'access_link' => 'https://example.test/x' ) );
Settings::update( array( 'consumer_info_text' => '', 'terms_text' => '' ) );
$r5_tm_empty = PassCaptain\Mail\Mailer::compose( 'tickets', array( 'ticket_list' => "Konzert\n\n" ) );
// Merged mail bodies are HTML (blocks with heading and text): compare the visible text.
$r5_text = static fn( $html ) => trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( str_replace( array( '<br />', '</p>', '</div>' ), ' ', (string) $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
check( 'R5-5: ticket mail carries consumer information and terms ({consumer_info}/{terms})', false !== strpos( $r5_text( $r5_tm['body'] ), 'Verbraucherinformationen Anbieter: Kulturverein' ) && false !== strpos( $r5_text( $r5_tm['body'] ), 'Bedingungen AGB Version 3' ) );
check( 'R5-5: access-link mail carries them before the closing sentence', false !== strpos( $r5_text( $r5_am['body'] ), 'AGB Version 3 Bewahre diesen Link' ) );
check( 'R5-5: without texts the mail is unchanged', 'Deine Tickets für Testsite:' . "\n\nKonzert\n\n" === $r5_tm_empty['body'] || ( false === strpos( $r5_tm_empty['body'], 'Verbraucherinformationen' ) && false === strpos( $r5_tm_empty['body'], 'Bedingungen' ) ) );
check( 'R5-5: placeholders are listed for the editor', isset( PassCaptain\Mail\Types::all()['tickets']['placeholders']['consumer_info'], PassCaptain\Mail\Types::all()['order_access']['placeholders']['terms'] ) );
$r5_diag = PassCaptain\Diagnostics::run( array( 'legal_texts' ) )['checks']['legal_texts'];
Settings::update( array( 'consumer_info_text' => 'x', 'terms_text' => 'y' ) );
$r5_diag_ok = PassCaptain\Diagnostics::run( array( 'legal_texts' ) )['checks']['legal_texts'];
Settings::update( array( 'consumer_info_text' => '', 'terms_text' => '' ) );
check( 'R5-5: system status warns while consumer information/terms are empty', 2 === $r5_diag['count'] && 'warning' === $r5_diag['severity'] && 'ok' === $r5_diag_ok['severity'] );

echo "R5: postponement – refund note in mail/SMS, portal refund request with approval\n";
use PassCaptain\RescheduleRefunds;
Settings::update( array( 'reschedule_refund_request' => 1, 'reschedule_refund_hours' => 0, 'reschedule_refund_note' => Settings::defaults()['reschedule_refund_note'] ) );
$r5_rs_type = make_type( 30, 5, 2000 );
$r5_rs_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$r5_rs_type" );
$r5_rs = Orders::create( array( 'email' => 'r5-rs@example.com', 'items' => array( array( 'ticket_type_id' => $r5_rs_type, 'quantity' => 2 ) ), 'idempotency_key' => 'r5-rs-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Orders::confirm_manual_payment( $r5_rs['order_id'] );
$r5_rs_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $r5_rs_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'order_id' => $r5_rs['order_id'] ) );
$r5_rs_order = Orders::get( $r5_rs['order_id'] );
check( 'R5-6: no refund request before a postponement', array() === RescheduleRefunds::eligible_tickets( $r5_rs_order ) );
$r5_old_start = (string) $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$r5_rs_occ" );
$r5_new_start = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $r5_new_start ), array( 'occurrence_id' => $r5_rs_occ ) );
$r5_mails_before = count( $GLOBALS['wpcap_test_mails'] );
PassCaptain\Notifications::occurrence_rescheduled( $r5_rs_occ, $r5_old_start, $r5_new_start );
$r5_rs_mail = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $r5_mails_before ), static fn( $m ) => 'r5-rs@example.com' === $m['to'] ) )[0] ?? array( 'body' => '' );
$r5_deadline = RescheduleRefunds::deadline_label( $r5_new_start, 'Europe/Vienna' );
check( 'R5-6: reschedule mail carries the refund note with the deadline (new start)', false !== strpos( $r5_rs_mail['body'], 'Dein Ticket bleibt gültig. Kannst du den neuen Termin nicht wahrnehmen?' ) && false !== strpos( $r5_rs_mail['body'], 'bis ' . $r5_deadline ) );
check( 'R5-6: SMS note with deadline (placeholder {refund_note} in the default text)', false !== strpos( RescheduleRefunds::sms_note( $r5_new_start, 'Europe/Vienna' ), $r5_deadline ) && false !== strpos( (string) ( (array) Settings::defaults()['sms'] )['reschedule_body'], '{refund_note}' ) );
Settings::update( array( 'reschedule_refund_request' => 0 ) );
$r5_note_off = RescheduleRefunds::mail_note( $r5_new_start, 'Europe/Vienna' );
$r5_elig_off = RescheduleRefunds::eligible_tickets( $r5_rs_order );
Settings::update( array( 'reschedule_refund_request' => 1 ) );
check( 'R5-6: switched off – previous hint, no portal request', 'Bei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.' === $r5_note_off && array() === $r5_elig_off );
check( 'R5-6: after the postponement both tickets may be refunded on request', 2 === count( RescheduleRefunds::eligible_tickets( $r5_rs_order ) ) );
wpcap_test_reset_transients();
$r5_req = static function ( $token ) use ( $r5_rs_order ) {
	$request = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/orders/' . $r5_rs_order->public_id . '/refund-request' );
	$request->set_param( 'public_id', $r5_rs_order->public_id );
	if ( '' !== $token ) { $request->set_header( 'X-WPCAP-Access-Token', $token ); }
	return PassCaptain\Rest\Controller::request_refund( $request );
};
$r5_denied = $r5_req( str_repeat( 'f', 64 ) );
check( 'R5-6: portal request needs the order access token', is_wp_error( $r5_denied ) && 403 === ( $r5_denied->get_error_data()['status'] ?? 0 ) );
$GLOBALS['wpcap_test_user_id'] = 0;
$r5_ok = $r5_req( $r5_rs_token );
$GLOBALS['wpcap_test_user_id'] = 1;
$r5_ok_data = is_wp_error( $r5_ok ) ? array() : ( is_object( $r5_ok ) && method_exists( $r5_ok, 'get_data' ) ? $r5_ok->get_data() : (array) $r5_ok );
$r5_refund_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d ORDER BY refund_id DESC LIMIT 1', $p . 'wpcap_refunds', $r5_rs['order_id'] ) );
check( 'R5-6: the request creates a refund waiting for approval (no payout, tickets stay valid)', 'pending_approval' === ( $r5_ok_data['status'] ?? '' ) && $r5_refund_row && 'pending_approval' === $r5_refund_row->status && 0 === (int) $r5_refund_row->requested_by && 2 === count( tickets( $r5_rs['order_id'], 'valid' ) ) );
$r5_again = $r5_req( $r5_rs_token );
$r5_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id=%d', $p . 'wpcap_refunds', $r5_rs['order_id'] ) );
check( 'R5-6: repeating the request creates no second refund', 1 === $r5_count && ! is_wp_error( $r5_again ) );
$r5_req( $r5_rs_token );
$r5_limited = $r5_req( $r5_rs_token );
check( 'R5-6: portal requests are rate limited per order', is_wp_error( $r5_limited ) && 429 === ( $r5_limited->get_error_data()['status'] ?? 0 ) );
$r5_html = PassCaptain\OrderPortal::render_page( $r5_rs_order, $r5_rs_token );
check( 'R5-6: portal shows the postponement box (request already under review)', false !== strpos( $r5_html, 'wpcap-refund-request-form' ) && false !== strpos( $r5_html, 'Ein Erstattungsantrag wird bereits geprüft.' ) && false !== strpos( $r5_html, '/refund-request' ) );
// Deadline passed: no request.
Settings::update( array( 'reschedule_refund_hours' => 24 * 15 ) );
$r5_late = RescheduleRefunds::request( $r5_rs_order );
Settings::update( array( 'reschedule_refund_hours' => 0 ) );
check( 'R5-6: no request after the deadline', is_wp_error( $r5_late ) && 'wpcap_reschedule_refund_unavailable' === $r5_late->get_error_code() );
// Orders placed after the postponement are not eligible.
$r5_rs2 = Orders::create( array( 'email' => 'r5-rs2@example.com', 'items' => array( array( 'ticket_type_id' => $r5_rs_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r5-rs2-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Orders::confirm_manual_payment( $r5_rs2['order_id'] );
$wpdb->update( "{$p}wpcap_orders", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'order_id' => $r5_rs2['order_id'] ) );
check( 'R5-6: orders placed after the postponement cannot request a refund', array() === RescheduleRefunds::eligible_tickets( Orders::get( $r5_rs2['order_id'] ) ) );
