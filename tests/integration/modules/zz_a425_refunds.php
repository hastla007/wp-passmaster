<?php
/**
 * A425, owner decision 23.11.2026 (3): refunds of the service fee. Organizer cancellations (occurrence or
 * event cancelled, also through the workflow command event.cancel) always refund the fee (per-ticket fee
 * per ticket, per-order fee with the last ticket); refunds the buyer asked for follow the setting
 * service_fee_refund ("einbehalten" by default, or "mit erstatten"). The refund row records the fee given
 * back (fee_minor) or kept (fee_kept_minor); a kept fee is booked as kept (deducted_minor), so a later
 * refund never returns it, and the VAT of refunds and kept fees adds up to the order VAT.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Invoices;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;

echo "A425: refunds of the service fee\n";
global $wpdb;
$p = $wpdb->prefix;
$fr_saved = Settings::all();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'require_2fa_for_refunds' => 0, 'auto_refund_cancelled' => 1, 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200, 'service_fee_tax' => 'ticket' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
ServiceFee::register();

check( 'A425 refunds: schema has the refund fee columns', Refunds::fee_ready() );
check( 'A425 refunds: default for refunds on the buyer\'s wish is "einbehalten"', 'retain' === Settings::defaults()['service_fee_refund'] && 'retain' === ServiceFee::buyer_refund_rule() );

/** Paid order of $qty tickets (2500, 20 % VAT): 2 tickets = 5000 + 300 + 200 = 5500. */
function fr_order( $type, $qty = 2 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$order = Orders::create( array( 'email' => 'fr-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'fr-' . wp_generate_uuid4() ) );
	Orders::confirm_manual_payment( (int) $order['order_id'] );
	return (int) $order['order_id'];
}
function fr_type() {
	global $wpdb;
	$id = make_type( 100, 10, 2500 );
	$wpdb->update( $wpdb->prefix . 'wpcap_ticket_types', array( 'tax_rate_bp' => 2000 ), array( 'ticket_type_id' => $id ) );
	return $id;
}
function fr_tickets( $order_id ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $order_id ) ) );
}
function fr_refunds( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", (int) $order_id ) );
}
function fr_vat_adds_up( $order_id ) {
	$sum = 0;
	foreach ( fr_refunds( $order_id ) as $r ) { if ( 'completed' === $r->status ) { $sum += (int) $r->tax_minor + (int) $r->fee_kept_tax_minor; } }
	return $sum === (int) Orders::get( $order_id )->tax_minor;
}

$fr_type = fr_type();

// 1. Buyer's wish, default "einbehalten": the ticket goes back without its fee, the last one without the per-order fee.
$fr_a = fr_order( $fr_type );
$fr_a_t = fr_tickets( $fr_a );
Invoices::issue_for_order( $fr_a );
$fr_preview = Refunds::preview( $fr_a, array( $fr_a_t[0] ) );
check( 'A425 refunds: preview shows the kept fee (value 2650, kept 150, refund 2500)', is_array( $fr_preview ) && 2650 === (int) $fr_preview['value_minor'] && 150 === (int) $fr_preview['deducted_minor'] && 2500 === (int) $fr_preview['amount_minor'] && 150 === (int) $fr_preview['fee_kept_minor'] );
$fr_a1 = Refunds::request( $fr_a, array( $fr_a_t[0] ), 'Kann nicht kommen', 'fr-a1' );
$fr_a1_row = fr_refunds( $fr_a )[0];
check( 'A425 refunds: buyer refund keeps the per-ticket fee (2500 back, 150 kept)', is_array( $fr_a1 ) && 'completed' === $fr_a1['status'] && 2500 === (int) $fr_a1_row->amount_minor && 0 === (int) $fr_a1_row->fee_minor && 150 === (int) $fr_a1_row->fee_kept_minor && 25 === (int) $fr_a1_row->fee_kept_tax_minor && 150 === (int) $fr_a1_row->deducted_minor && Orders::gross_tax( 2500, 2000 ) === (int) $fr_a1_row->tax_minor );
$fr_cn = Invoices::credit_note_for_refund( (int) $fr_a1_row->refund_id );
$fr_cn_rates = is_array( $fr_cn ) ? (array) json_decode( (string) $fr_cn['rates'], true ) : array();
check( 'A425 refunds: the correction document covers the refunded ticket only (no fee in its split)', is_array( $fr_cn ) && 2500 === (int) $fr_cn['total_minor'] && 1 === count( $fr_cn_rates ) && 2500 === (int) $fr_cn_rates[0]['gross'] );
$fr_a2 = Refunds::request( $fr_a, array(), 'Rest', 'fr-a2' );
$fr_a2_row = fr_refunds( $fr_a )[1];
check( 'A425 refunds: the last ticket on the buyer\'s wish keeps its fee and the per-order fee (2500 back, 350 kept)', is_array( $fr_a2 ) && 2500 === (int) $fr_a2_row->amount_minor && 350 === (int) $fr_a2_row->fee_kept_minor && 5000 === (int) Orders::get( $fr_a )->refunded_minor && 'refunded' === Orders::get( $fr_a )->payment_status );
check( 'A425 refunds: refund VAT plus kept fee VAT equals the order VAT', fr_vat_adds_up( $fr_a ) );

// 2. Setting "mit erstatten".
Settings::update( array( 'service_fee_refund' => 'refund' ) );
$fr_b = fr_order( $fr_type );
$fr_b_t = fr_tickets( $fr_b );
Refunds::request( $fr_b, array( $fr_b_t[0] ), 'Kulanz', 'fr-b1' );
Refunds::request( $fr_b, array(), 'Rest', 'fr-b2' );
$fr_b_rows = fr_refunds( $fr_b );
check( 'A425 refunds: "mit erstatten" – ticket with its fee, the last one with the per-order fee', 2650 === (int) $fr_b_rows[0]->amount_minor && 150 === (int) $fr_b_rows[0]->fee_minor && 2850 === (int) $fr_b_rows[1]->amount_minor && 350 === (int) $fr_b_rows[1]->fee_minor && 5500 === (int) Orders::get( $fr_b )->refunded_minor && fr_vat_adds_up( $fr_b ) );
Settings::update( array( 'service_fee_refund' => 'retain' ) );

// 3. Staff decide per refund (admin form option), also against the setting.
$fr_c = fr_order( $fr_type );
$fr_c_t = fr_tickets( $fr_c );
$fr_c_opt = PassCaptain\Admin\OrdersPage::refund_options( array( 'service_fee' => 'refund' ), 'EUR' );
Refunds::request( $fr_c, array( $fr_c_t[0] ), 'Kulanz', 'fr-c1', $fr_c_opt );
check( 'A425 refunds: the refund option service_fee=refund overrides "einbehalten"', array( 'service_fee' => 'refund' ) === $fr_c_opt && 2650 === (int) fr_refunds( $fr_c )[0]->amount_minor );

// 4. Portal request (buyer's wish, waits for approval): the fee is kept; the portal says so.
$fr_d = fr_order( $fr_type );
$fr_d_t = fr_tickets( $fr_d );
$fr_d1 = Refunds::request( $fr_d, array( $fr_d_t[0] ), 'Portal', 'fr-d1', array( 'system' => true, 'require_approval' => true, 'source' => 'portal' ) );
check( 'A425 refunds: a portal request keeps the fee', is_array( $fr_d1 ) && 'pending_approval' === $fr_d1['status'] && 2500 === (int) $fr_d1['amount_minor'] && 150 === (int) $fr_d1['fee_kept_minor'] );
$fr_d_tpl = (string) file_get_contents( WPCAP_PATH . 'src/PortalSelfService.php' );
check( 'A425 refunds: the portal refund form names the kept fee', false !== strpos( $fr_d_tpl, 'Die Servicegebühr wird bei einer Stornierung auf deinen Wunsch nicht erstattet.' ) );

// 5. Occurrence cancelled by the organizer: always the full amount incl. both fees.
add_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10, 1 );
$fr_e_type = fr_type();
$fr_e = fr_order( $fr_e_type );
$fr_e_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $fr_e_type ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $fr_e_occ, 'reason' => 'Sturm', 'confirm' => '1' ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $fr_e_occ ) ); // Resume if the run left work.
$fr_e_rows = fr_refunds( $fr_e );
check( 'A425 refunds: occurrence cancellation refunds tickets, per-ticket and per-order fee (5500)', 1 === count( $fr_e_rows ) && 'completed' === $fr_e_rows[0]->status && 5500 === (int) $fr_e_rows[0]->amount_minor && 500 === (int) $fr_e_rows[0]->fee_minor && 0 === (int) $fr_e_rows[0]->fee_kept_minor && fr_vat_adds_up( $fr_e ) );

// 6. Buyer refunded one ticket (fee kept), then the organizer cancels: the rest goes back with the per-order fee.
$fr_f_type = fr_type();
$fr_f = fr_order( $fr_f_type );
$fr_f_t = fr_tickets( $fr_f );
Refunds::request( $fr_f, array( $fr_f_t[0] ), 'Kann nicht', 'fr-f1' );
$fr_f_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $fr_f_type ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $fr_f_occ, 'reason' => 'Sturm', 'confirm' => '1' ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $fr_f_occ ) );
$fr_f_rows = fr_refunds( $fr_f );
check( 'A425 refunds: after a buyer refund the cancellation refunds the rest incl. its fee and the per-order fee (2850), the kept fee stays kept', 2 === count( $fr_f_rows ) && 2500 === (int) $fr_f_rows[0]->amount_minor && 2850 === (int) $fr_f_rows[1]->amount_minor && 350 === (int) $fr_f_rows[1]->fee_minor && 5350 === (int) Orders::get( $fr_f )->refunded_minor && fr_vat_adds_up( $fr_f ) );

// 7. A ticket revoked by a cancellation without automatic refund and refunded later by staff: the fee goes back
// (the cancellation is the cause), whatever the setting says.
Settings::update( array( 'auto_refund_cancelled' => 0 ) );
$fr_g_type = fr_type();
$fr_g = fr_order( $fr_g_type, 1 );
$fr_g_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $fr_g_type ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $fr_g_occ, 'reason' => 'Sturm', 'confirm' => '1' ) );
EventsPage::cancel_occurrence( array( 'occurrence_id' => $fr_g_occ ) );
$fr_g1 = Refunds::request( $fr_g, array(), 'Absage', 'fr-g1' );
check( 'A425 refunds: a later staff refund of a cancelled date refunds the fee (2500 + 150 + 200)', is_array( $fr_g1 ) && 2850 === (int) $fr_g1['amount_minor'] && 350 === (int) $fr_g1['fee_minor'] );
Settings::update( array( 'auto_refund_cancelled' => 1 ) );

// 8. Public action wpcap_cancel_event() (whole event, PC-7).
require_once WPCAP_PATH . 'src/functions.php';
$fr_h_type = fr_type();
$fr_h = fr_order( $fr_h_type );
$fr_h_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types t ON t.occurrence_id=o.occurrence_id WHERE t.ticket_type_id=%d", $fr_h_type ) );
$fr_h_cmd = wpcap_cancel_event( $fr_h_event, 'Unwetter', array( 'idempotency_key' => 'fr-cancel-' . substr( md5( uniqid( '', true ) ), 0, 12 ) ) );
if ( 'cancelled' !== $wpdb->get_var( "SELECT status FROM {$p}wpcap_events WHERE event_id=$fr_h_event" ) || ! fr_refunds( $fr_h ) ) {
	Orders::resume_event_cancellation( $fr_h_event );
}
PassCaptain\Jobs::process_due( 50 );
$fr_h_rows = fr_refunds( $fr_h );
check( 'A425 refunds: wpcap_cancel_event() refunds the whole order incl. both fees', is_array( $fr_h_cmd ) && 'cancelled' === $fr_h_cmd['status'] && 1 === count( $fr_h_rows ) && 5500 === (int) $fr_h_rows[0]->amount_minor && 500 === (int) $fr_h_rows[0]->fee_minor );

// 9. Filter.
$fr_filter = static function () { return 'refund'; };
add_filter( 'wpcap_service_fee_refund_rule', $fr_filter );
$fr_i = fr_order( $fr_type );
$fr_i1 = Refunds::request( $fr_i, array( fr_tickets( $fr_i )[0] ), 'Filter', 'fr-i1' );
remove_filter( 'wpcap_service_fee_refund_rule', $fr_filter );
check( 'A425 refunds: wpcap_service_fee_refund_rule decides per ticket', is_array( $fr_i1 ) && 2650 === (int) $fr_i1['amount_minor'] );

remove_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10 );
update_option( Settings::OPTION, $fr_saved );
Settings::flush();
