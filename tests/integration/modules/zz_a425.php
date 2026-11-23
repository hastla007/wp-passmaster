<?php
/**
 * A425: presale/service fee per ticket (setting or per ticket type) and per order, off by default.
 * Own order lines with the ticket line's VAT, shown in checkout, mails, invoices and reports, refunded
 * with the existing refund rules, never for trusted channels or free orders.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;

echo "A425: presale/service fee\n";
global $wpdb;
$p = $wpdb->prefix;
$a425_saved = Settings::all();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'require_2fa_for_refunds' => 0 ) );
ServiceFee::register();

check( 'A425: schema has wpcap_order_items.fee_kind and wpcap_ticket_types.service_fee_minor', ServiceFee::ready() );
check( 'A425: off by default (no fee per ticket or per order)', 0 === (int) PassCaptain\Settings::defaults()['service_fee_ticket_minor'] && 0 === (int) PassCaptain\Settings::defaults()['service_fee_order_minor'] );

function a425_type( $price = 2500, $tax_bp = 2000, $fee = 'unset' ) {
	global $wpdb;
	$id = make_type( 100, 10, $price );
	$data = array( 'tax_rate_bp' => $tax_bp );
	if ( 'unset' !== $fee ) { $data['service_fee_minor'] = $fee; }
	$wpdb->update( $wpdb->prefix . 'wpcap_ticket_types', $data, array( 'ticket_type_id' => $id ) );
	return $id;
}
function a425_order( array $items, array $internal = array(), array $extra = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	return Orders::create( array( 'email' => 'a425-' . uniqid() . '@example.com', 'items' => $lines, 'idempotency_key' => 'a425-' . uniqid() . '-' . wp_generate_uuid4() ) + $extra, $internal );
}
function a425_fee_lines( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d AND fee_kind<>'' ORDER BY item_id", (int) $order_id ) );
}

// Off: nothing changes.
$a425_type = a425_type();
$a425_off = a425_order( array( $a425_type => 2 ) );
check( 'A425: without a configured fee the order has no fee line and the plain total', ! is_wp_error( $a425_off ) && 5000 === (int) $a425_off['total_minor'] && array() === a425_fee_lines( $a425_off['order_id'] ) );

// On: 1,50 per ticket, 2,00 per order; 20 % VAT on the ticket.
Settings::update( array( 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200 ) );
$a425 = a425_order( array( $a425_type => 2 ) );
$a425_id = (int) $a425['order_id'];
$a425_row = Orders::get( $a425_id );
$a425_fees = a425_fee_lines( $a425_id );
$a425_ticket_line = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=%d AND fee_kind=''", $a425_id ) );
check( 'A425: total = tickets + 2 × per-ticket fee + per-order fee', 5500 === (int) $a425['total_minor'] && 5500 === (int) $a425_row->total_minor && 300 + 200 === (int) $a425['service_fee_minor'] );
check( 'A425: subtotal includes the fee (subtotal − discount = total)', 5500 === (int) $a425_row->subtotal_minor && 0 === (int) $a425_row->discount_minor );
check( 'A425: two fee lines (ticket, order) with quantity 0 (no admission, no inventory)', 2 === count( $a425_fees ) && 'ticket' === $a425_fees[0]->fee_kind && 'order' === $a425_fees[1]->fee_kind && 0 === (int) $a425_fees[0]->quantity && 300 === (int) $a425_fees[0]->line_total_minor && 150 === (int) $a425_fees[0]->unit_price_minor && 200 === (int) $a425_fees[1]->line_total_minor );
check( 'A425: fee lines carry the VAT rate of their ticket line', 2000 === (int) $a425_fees[0]->tax_rate_bp && 2000 === (int) $a425_fees[1]->tax_rate_bp && (int) $a425_ticket_line->tax_rate_bp === (int) $a425_fees[0]->tax_rate_bp && $a425_ticket_line->tax_source === $a425_fees[0]->tax_source );
check( 'A425: order VAT includes the fee VAT', Orders::gross_tax( 5000, 2000 ) + Orders::gross_tax( 300, 2000 ) + Orders::gross_tax( 200, 2000 ) === (int) $a425_row->tax_minor && 50 === (int) $a425_fees[0]->tax_minor && 33 === (int) $a425_fees[1]->tax_minor );
$a425_snap = json_decode( (string) $a425_row->price_snapshot, true );
check( 'A425: the fee is frozen in the price snapshot', 500 === (int) ( $a425_snap['service_fee']['total_minor'] ?? 0 ) && 300 === (int) $a425_snap['service_fee']['ticket_minor'] && 200 === (int) $a425_snap['service_fee']['order_minor'] && 500 === (int) ( ServiceFee::of_order( $a425_row )['total_minor'] ?? 0 ) );
$a425_held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE order_id=%d", $a425_id ) );
check( 'A425: the fee holds no capacity', 2 === $a425_held );

Orders::confirm_manual_payment( $a425_id );
$a425_tickets = tickets( $a425_id );
check( 'A425: only the two tickets are issued', 2 === count( $a425_tickets ) );
$a425_summary = Orders::summary_lines( $a425_id );
$a425_mail = PassCaptain\Mail\Blocks::order_items( $a425_summary );
$a425_mail_text = is_array( $a425_mail ) ? (string) ( $a425_mail['text'] ?? '' ) : (string) $a425_mail;
check( 'A425: order summary (mail, portal) lists the fee lines', 3 === count( $a425_summary ) && ! empty( $a425_summary[1]['fee'] ) && 'Servicegebühr (Standard)' === $a425_summary[1]['ticket'] && 2 === $a425_summary[1]['quantity'] && 300 === $a425_summary[1]['gross_minor'] && 'Servicegebühr je Bestellung' === $a425_summary[2]['ticket'] && 200 === $a425_summary[2]['gross_minor'] );
check( 'A425: the access-link mail block names the fee', false !== strpos( $a425_mail_text, '2 × Konzert — Servicegebühr (Standard)' ) && false !== strpos( $a425_mail_text, 'Servicegebühr je Bestellung' ) );
$a425_admin = PassCaptain\Admin\Queries::order_items( $a425_id );
check( 'A425: the admin order view names the fee lines', 'Servicegebühr je Bestellung' === $a425_admin[2]->name );

// Invoice: fee positions with their tickets count; lines add up to the invoice.
$a425_inv = Invoices::issue_for_order( $a425_id );
$a425_lines = is_array( $a425_inv ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoice_lines WHERE invoice_id=%d ORDER BY invoice_line_id", (int) $a425_inv['invoice_id'] ) ) : array();
$a425_invoice = is_array( $a425_inv ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_invoices WHERE invoice_id=%d", (int) $a425_inv['invoice_id'] ) ) : null;
check( 'A425: invoice has the fee positions', 3 === count( $a425_lines ) && false !== strpos( $a425_lines[1]->description, 'Servicegebühr (Standard)' ) && 2 === (int) $a425_lines[1]->quantity && 150 === (int) $a425_lines[1]->unit_minor && false !== strpos( $a425_lines[2]->description, 'Servicegebühr je Bestellung' ) && 1 === (int) $a425_lines[2]->quantity );
check( 'A425: invoice lines add up to the invoice total and VAT', $a425_invoice && 5500 === (int) $a425_invoice->total_minor && 5500 === array_sum( array_map( static fn( $l ) => (int) $l->total_minor, $a425_lines ) ) && (int) $a425_invoice->tax_minor === array_sum( array_map( static fn( $l ) => (int) $l->tax_minor, $a425_lines ) ) );

// Refunds: one ticket takes its per-ticket fee, the last one the rest (per-order fee).
$a425_r1 = Refunds::request( $a425_id, array( (int) $a425_tickets[0]->ticket_id ), 'Storno', 'a425-r1' );
$a425_r1_row = is_array( $a425_r1 ) ? Refunds::get( (int) $a425_r1['refund_id'] ) : null;
check( 'A425: a ticket refund includes its per-ticket fee (2500 + 150)', $a425_r1_row && 2650 === (int) $a425_r1_row['amount_minor'] && Orders::gross_tax( 2650, 2000 ) === (int) $a425_r1_row['tax_minor'] );
$a425_cn1 = Invoices::credit_note_for_refund( (int) $a425_r1['refund_id'] );
check( 'A425: the correction document matches the refund', is_array( $a425_cn1 ) && 2650 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT total_minor FROM {$p}wpcap_credit_notes WHERE refund_id=%d", (int) $a425_r1['refund_id'] ) ) );
$a425_r2 = Refunds::request( $a425_id, array(), 'Storno', 'a425-r2' );
$a425_r2_row = is_array( $a425_r2 ) ? Refunds::get( (int) $a425_r2['refund_id'] ) : null;
$a425_after = Orders::get( $a425_id );
check( 'A425: the last ticket takes the per-ticket and per-order fee; the order is fully refunded', $a425_r2_row && 2850 === (int) $a425_r2_row['amount_minor'] && 5500 === (int) $a425_after->refunded_minor );
check( 'A425: refund VAT adds up to the order VAT', (int) $a425_row->tax_minor === (int) $a425_r1_row['tax_minor'] + (int) $a425_r2_row['tax_minor'] );

// Per ticket type: own fee, explicitly none, free types.
$a425_own = a425_type( 3000, 700, 99 );
$a425_none = a425_type( 3000, 700, 0 );
$a425_free = a425_type( 0, 0 );
$a425_mix = a425_order( array( $a425_own => 1, $a425_none => 2, $a425_free => 1 ) );
$a425_mix_fees = a425_fee_lines( $a425_mix['order_id'] );
check( 'A425: per-type fee overrides the setting, 0 = no fee, free types carry none; one per-order fee', 9000 + 99 + 200 === (int) $a425_mix['total_minor'] && 2 === count( $a425_mix_fees ) && 99 === (int) $a425_mix_fees[0]->line_total_minor && $a425_own === (int) $a425_mix_fees[0]->ticket_type_id && 700 === (int) $a425_mix_fees[0]->tax_rate_bp );
$a425_free_order = a425_order( array( $a425_free => 2 ) );
check( 'A425: a free order carries no fee', 0 === (int) $a425_free_order['total_minor'] && array() === a425_fee_lines( $a425_free_order['order_id'] ) );

// Trusted channels never carry the fee.
$a425_admin_hold = a425_order( array( $a425_type => 1 ), array( 'source' => 'admin', 'key_namespace' => 'admin', 'skip_rate_limit' => true ) );
$a425_comp = a425_order( array( $a425_type => 1 ), array( 'comp' => true ) );
$a425_box = a425_order( array( $a425_type => 1 ), array( 'key_namespace' => 'pos', 'source' => 'boxoffice', 'skip_rate_limit' => true, 'allow_door_sales' => true ) );
check( 'A425: staff holds, comps and box office orders carry no fee', ! is_wp_error( $a425_admin_hold ) && 2500 === (int) $a425_admin_hold['total_minor'] && ! is_wp_error( $a425_comp ) && 0 === (int) $a425_comp['total_minor'] && ! is_wp_error( $a425_box ) && 2500 === (int) $a425_box['total_minor'] && array() === a425_fee_lines( $a425_admin_hold['order_id'] ) );

// Filter: per-order decision (e.g. per event).
$a425_filter = static function ( $fee ) { $fee['order_minor'] = 0; foreach ( $fee['ticket'] as $k => $v ) { $fee['ticket'][ $k ] = 10; } return $fee; };
add_filter( 'wpcap_service_fee', $a425_filter );
$a425_filtered = a425_order( array( $a425_type => 3 ) );
remove_filter( 'wpcap_service_fee', $a425_filter );
check( 'A425: wpcap_service_fee can change the fee per order', 7500 + 30 === (int) $a425_filtered['total_minor'] );

// Report: fees are part of gross revenue and listed separately.
$a425_today = \PassCaptain\Clock::today( \PassCaptain\Reports\Scope::site_timezone() );
Orders::confirm_manual_payment( (int) $a425_mix['order_id'] );
$a425_report = PassCaptain\Reports\Data::build( array( 'from' => $a425_today, 'to' => $a425_today ), null );
check( 'A425: the report lists service fees per currency', isset( $a425_report['service_fees']['EUR'] ) && (int) $a425_report['service_fees']['EUR']['gross'] >= 500 + 299 && (int) $a425_report['service_fees']['EUR']['orders'] >= 2 );
check( 'A425: the report reconciles with fee lines', array() === PassCaptain\Reports\Data::reconcile( $a425_report ) );

// Checkout display: data attributes and the estimate text.
$a425_tpl = (string) file_get_contents( WPCAP_PATH . 'templates/event-card.php' );
$a425_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'A425: event card exposes the fee (data-fee, data-order-fee, fee notes; zz_a425_display.php checks the rendered card)', false !== strpos( $a425_tpl, 'data-fee=' ) && false !== strpos( $a425_tpl, 'data-order-fee=' ) && false !== strpos( $a425_tpl, 'ServiceFee::included_note(' ) && false !== strpos( $a425_tpl, 'ServiceFee::order_note(' ) );
check( 'A425: checkout estimate adds the fee', false !== strpos( $a425_js, 'dataset.orderFee' ) && false !== strpos( $a425_js, 't.estimateFee' ) );
check( 'A425: per_ticket() follows the type, then the setting', 99 === ServiceFee::per_ticket( (object) array( 'price_minor' => 1, 'service_fee_minor' => 99 ) ) && 150 === ServiceFee::per_ticket( (object) array( 'price_minor' => 1, 'service_fee_minor' => null ) ) && 0 === ServiceFee::per_ticket( (object) array( 'price_minor' => 0, 'service_fee_minor' => null ) ) );

// Ticket type form: empty = setting, 0 = none, invalid refused.
$a425_tt = PassCaptain\Admin\Queries::ticket_type( $a425_type );
$a425_save = PassCaptain\Admin\EventsPage::save_ticket_type( array( 'ticket_type_id' => $a425_type, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '25,00', 'tax_rate' => '20', 'max_per_order' => 10, 'capacity' => 100, 'service_fee' => '1,25', 'confirm_sold_change' => 1 ) );
$a425_saved_fee = $wpdb->get_var( $wpdb->prepare( "SELECT service_fee_minor FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a425_type ) );
$a425_bad = PassCaptain\Admin\EventsPage::save_ticket_type( array( 'ticket_type_id' => $a425_type, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '25,00', 'tax_rate' => '20', 'max_per_order' => 10, 'capacity' => 100, 'service_fee' => 'abc', 'confirm_sold_change' => 1 ) );
PassCaptain\Admin\EventsPage::save_ticket_type( array( 'ticket_type_id' => $a425_type, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '25,00', 'tax_rate' => '20', 'max_per_order' => 10, 'capacity' => 100, 'service_fee' => '', 'confirm_sold_change' => 1 ) );
$a425_cleared = $wpdb->get_var( $wpdb->prepare( "SELECT service_fee_minor FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a425_type ) );
check( 'A425: the ticket type form stores an own fee and clears it back to the setting', ! is_wp_error( $a425_save ) && '125' === (string) $a425_saved_fee && is_wp_error( $a425_bad ) && null === $a425_cleared );

update_option( Settings::OPTION, $a425_saved );
Settings::flush();
