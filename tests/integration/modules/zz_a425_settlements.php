<?php
/**
 * A425, owner decision 23.11.2026 (4): the service fee is the operator's revenue. Partner settlements leave
 * it out of the organizer's sales and refunds (commission, payout) and show it separately; the setting
 * "Servicegebühr an Partner weitergeben" counts it as organizer revenue. The settlement sheet of a date
 * shows it separately and leaves it out of the deal basis; the sales report lists sold, refunded and
 * kept fees.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Partners\Repository as Partners;
use PassCaptain\Partners\Settlements;
use PassCaptain\Refunds;
use PassCaptain\Reports\Scope;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;

echo "A425: service fee in settlements and reports\n";
global $wpdb;
$p = $wpdb->prefix;
$fs_saved = Settings::all();
Settings::update( array( 'refund_approval_threshold' => 0, 'auto_invoice' => 0, 'require_2fa_for_refunds' => 0, 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200, 'service_fee_tax' => 'ticket', 'service_fee_refund' => 'refund' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
ServiceFee::register();
check( 'A425 settlements: by default the fee is not passed on to partners', 0 === (int) Settings::defaults()['service_fee_to_partner'] && ! ServiceFee::to_partner() );

$fs_month = gmdate( 'Y-m', strtotime( 'first day of -1 month' ) );
$fs_at = gmdate( 'Y-m-d H:i:s', strtotime( $fs_month . '-10 12:00:00 UTC' ) );
$fs_type = make_type( 50, 10, 2380 ); // 23,80 incl. 19 %.
$wpdb->update( "{$p}wpcap_ticket_types", array( 'tax_rate_bp' => 1900 ), array( 'ticket_type_id' => $fs_type ) );
$fs_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_ticket_types t ON t.occurrence_id=o.occurrence_id WHERE t.ticket_type_id=%d", $fs_type ) );
$fs_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $fs_type ) );
$fs_partner_id = Partners::save( array( 'name' => 'FS Veranstalter', 'currency' => 'EUR', 'commission_bp' => 1000, 'settlement_start' => $fs_month . '-01' ) );
Partners::set_events( $fs_partner_id, array( $fs_event ) );
$fs_partner = Partners::get( $fs_partner_id );

// Order: 2 × 23,80 + 2 × 1,50 + 2,00 = 52,60; one ticket refunded with its fee (owner setting "mit erstatten").
$GLOBALS['wpcap_test_transients'] = array();
$fs_order = Orders::create( array( 'email' => 'fs@example.com', 'items' => array( array( 'ticket_type_id' => $fs_type, 'quantity' => 2 ) ), 'idempotency_key' => 'fs-' . wp_generate_uuid4() ) );
Orders::confirm_manual_payment( (int) $fs_order['order_id'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET created_at=%s WHERE order_id=%d", $fs_at, (int) $fs_order['order_id'] ) );
$fs_ticket = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id LIMIT 1", (int) $fs_order['order_id'] ) );
$fs_refund = Refunds::request( (int) $fs_order['order_id'], array( $fs_ticket ), 'Kulanz', 'fs-r1' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_refunds SET updated_at=%s WHERE refund_id=%d", gmdate( 'Y-m-d H:i:s', strtotime( $fs_month . '-12 12:00:00 UTC' ) ), (int) $fs_refund['refund_id'] ) );
check( 'A425 settlements: fixture order 52,60, refund 23,80 + 1,50', 5260 === (int) $fs_order['total_minor'] && 2530 === (int) $fs_refund['amount_minor'] && 150 === (int) $fs_refund['fee_minor'] );

$fs_calc = Settlements::compute( $fs_partner, $fs_month );
$fs_t = $fs_calc['totals'];
check( 'A425 settlements: the organizer settles the tickets only (sales 47,60, refund 23,80, commission on the ticket net)', 4760 === $fs_t['sales_gross'] && 2380 === $fs_t['refunded'] && 2380 === $fs_t['gross'] && 200 === $fs_t['commission'] && 2 === count( $fs_calc['lines'] ) );
check( 'A425 settlements: the fee is shown separately (5,00 sold, 1,50 refunded, operator)', 500 === $fs_t['service_fee']['gross'] && 150 === $fs_t['service_fee']['refunded'] && false === $fs_t['service_fee']['to_partner'] );
$fs_note = Settlements::service_fee_note( $fs_t, static fn( $m ) => Scope::money( $m, 'EUR' ) );
check( 'A425 settlements: the statement names the fee as operator revenue', false !== strpos( $fs_note, 'Umsatz des Betreibers, nicht Teil dieser Abrechnung' ) && false !== strpos( $fs_note, Scope::money( 500, 'EUR' ) ) );

Settings::update( array( 'service_fee_to_partner' => 1 ) );
$fs_calc_on = Settlements::compute( $fs_partner, $fs_month );
$fs_on = $fs_calc_on['totals'];
check( 'A425 settlements: "an Partner weitergeben" counts the fee as organizer revenue', 5260 === $fs_on['sales_gross'] && 2530 === $fs_on['refunded'] && 2730 === $fs_on['gross'] && true === $fs_on['service_fee']['to_partner'] && false !== strpos( Settlements::service_fee_note( $fs_on, static fn( $m ) => Scope::money( $m, 'EUR' ) ), 'an den Veranstalter weitergegeben' ) );

// Settlement sheet of the date: fee separate, deal basis without it unless passed on.
$fs_sheet_on = PassCaptain\Reports\Settlement::build( $fs_occ, array( 'fixed' => 0, 'share_bp' => 10000 ) );
Settings::update( array( 'service_fee_to_partner' => 0 ) );
$fs_sheet = PassCaptain\Reports\Settlement::build( $fs_occ, array( 'fixed' => 0, 'share_bp' => 10000 ) );
$fs_fee_net = (int) $fs_sheet['service_fee']['revenue_net'];
check( 'A425 settlements: the settlement sheet shows the fee after refunds (3,50 gross)', 350 === (int) $fs_sheet['service_fee']['revenue'] && 500 === (int) $fs_sheet['service_fee']['gross'] && 150 === (int) $fs_sheet['service_fee']['refunded'] );
check( 'A425 settlements: the deal basis leaves the operator\'s fee out, passed on it is included', (int) $fs_sheet['total']['revenue_net'] - $fs_fee_net === (int) $fs_sheet['deal']['basis'] && (int) $fs_sheet_on['total']['revenue_net'] === (int) $fs_sheet_on['deal']['basis'] && $fs_fee_net > 0 );
$fs_html = PassCaptain\Reports\Settlement::html( $fs_sheet );
check( 'A425 settlements: the sheet names the fee and its attribution', false !== strpos( $fs_html, 'Servicegebühren nach Erstattungen' ) && false !== strpos( $fs_html, 'Umsatz des Betreibers (nicht Teil der Deal-Basis)' ) );

// Sales report: sold, refunded and kept fees per currency.
Settings::update( array( 'service_fee_refund' => 'retain' ) );
$GLOBALS['wpcap_test_transients'] = array();
$fs_kept_order = Orders::create( array( 'email' => 'fs2@example.com', 'items' => array( array( 'ticket_type_id' => $fs_type, 'quantity' => 1 ) ), 'idempotency_key' => 'fs-' . wp_generate_uuid4() ) );
Orders::confirm_manual_payment( (int) $fs_kept_order['order_id'] );
Refunds::request( (int) $fs_kept_order['order_id'], array(), 'Wunsch', 'fs-r2' );
$fs_today = PassCaptain\Clock::today( Scope::site_timezone() );
$fs_report = PassCaptain\Reports\Data::build( array( 'from' => $fs_today, 'to' => $fs_today ), null );
check( 'A425 settlements: the report lists refunded and kept service fees', (int) ( $fs_report['service_fees']['EUR']['kept'] ?? 0 ) >= 350 && array_key_exists( 'refunded', $fs_report['service_fees']['EUR'] ?? array() ) && array() === PassCaptain\Reports\Data::reconcile( $fs_report ) );

update_option( Settings::OPTION, $fs_saved );
Settings::flush();
