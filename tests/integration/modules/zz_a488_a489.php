<?php
/**
 * A488: refunds of box office orders from the back office (order screen, approval, event
 * cancellation) were booked into a foreign open shift – that cashier's expected cash dropped
 * although nobody paid anything out. Now they wait as open payouts until a cashier pays them out.
 * A489: a shift could be counted/closed while a sale was confirming its payment: the tickets stayed
 * valid without receipt and Z report.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Registers;
use PassCaptain\BoxOffice\Service as Pos;
use PassCaptain\BoxOffice\Shifts;
use PassCaptain\BoxOffice\ZReport;
use PassCaptain\Modules\BoxOffice as BoxOfficeModule;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

BoxOfficeModule::register();
global $wpdb;
$p = $wpdb->prefix;
$bo_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$bo_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$bo_settings = array( 'seller_country' => Settings::get( 'seller_country' ), 'boxoffice_cash_confirmed' => Settings::get( 'boxoffice_cash_confirmed' ), 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'require_2fa_for_refunds' => Settings::get( 'require_2fa_for_refunds' ), 'auto_refund_cancelled' => Settings::get( 'auto_refund_cancelled' ) );
Settings::update( array( 'seller_country' => 'AT', 'boxoffice_cash_confirmed' => 1, 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
$bo_as = static function ( $user_id, array $denied = array( 'manage_options', 'wpcap_view_finance', 'wpcap_boxoffice_override' ) ) {
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$GLOBALS['wpcap_test_denied_caps'] = $denied;
};
$bo_key = static function () { return 'a488-' . bin2hex( random_bytes( 8 ) ); };

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$bo_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Kassenabend', 'slug' => 'a488' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $bo_now, 'updated_at' => $bo_now ) );
$bo_ev = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $bo_ev, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$bo_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $bo_occ, 'name' => 'Abendkasse', 'price_minor' => 1190, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active', 'tax_rate_bp' => 1900 ) );
$bo_type = (int) $wpdb->insert_id;
foreach ( array( 611, 612, 613 ) as $bo_uid ) { $wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $bo_ev, 'user_id' => $bo_uid, 'capability' => 'wpcap_manage_orders' ) ); }
$bo_reg = Registers::save( array( 'name' => 'Kasse A488', 'blind_count' => 0 ) );
$bo_reg2 = Registers::save( array( 'name' => 'Kasse A489', 'blind_count' => 0 ) );

echo "A488: back-office refunds of box office sales wait for the payout at a till\n";
$bo_as( 611 );
$bo_shift = Shifts::open( $bo_reg->register_id, array( 'float_minor' => 0 ) );
$bo_sale = Pos::sell( array( 'occurrence_id' => $bo_occ, 'quantities' => array( $bo_type => 1 ), 'method' => 'cash', 'form_key' => $bo_key() ) )['sale'];
$bo_expected = ZReport::compute( $bo_shift->session_id )['cash']['expected'];
$bo_as( 612 ); // Back office (support), no shift of its own.
$bo_refund = Refunds::request( (int) $bo_sale->order_id, array(), 'Kulanz (Support)', 'a488-' . uniqid() );
$bo_payout = is_wp_error( $bo_refund ) ? null : Shifts::payout_for_refund( (int) $bo_refund['refund_id'] );
$bo_mv = is_wp_error( $bo_refund ) ? null : $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE refund_id=" . (int) $bo_refund['refund_id'] );
check( 'A488: the refund completes as an open payout; nothing is booked into the open shift of another cashier', ! is_wp_error( $bo_refund ) && 'completed' === $bo_refund['status'] && $bo_payout && 'open' === $bo_payout->status && 1190 === (int) $bo_payout->amount_minor && null === $bo_mv && 1190 === $bo_expected && $bo_expected === ZReport::compute( $bo_shift->session_id )['cash']['expected'] );
check( 'A488: the open payout is listed for the shift currency', in_array( (int) $bo_payout->payout_id, array_map( static fn( $r ) => (int) $r->payout_id, Shifts::open_payouts( 'EUR' ) ), true ) );
$bo_as( 612 );
$bo_foreign = Shifts::pay_out( (int) $bo_payout->payout_id, (int) $bo_shift->session_id );
check( 'A488: only a cashier of the shift can pay it out', is_wp_error( $bo_foreign ) && 403 === $bo_foreign->get_error_data()['status'] );
$bo_as( 611 );
$bo_paid = Shifts::pay_out( (int) $bo_payout->payout_id, (int) $bo_shift->session_id );
check( 'A488: the cashier pays it out: negative cash movement with payout receipt in the own shift', ! is_wp_error( $bo_paid ) && (int) $bo_shift->session_id === (int) $bo_paid->shift_id && -1190 === (int) $bo_paid->amount_minor && '' !== (string) $bo_paid->receipt_number && 0 === ZReport::compute( $bo_shift->session_id )['cash']['expected'] && 'paid' === Shifts::payout( $bo_payout->payout_id )->status );
$bo_twice = Shifts::pay_out( (int) $bo_payout->payout_id, (int) $bo_shift->session_id );
check( 'A488: a payout is paid out only once', is_wp_error( $bo_twice ) && 'wpcap_pos_payout_done' === $bo_twice->get_error_code() );
$bo_sale2 = Pos::sell( array( 'occurrence_id' => $bo_occ, 'quantities' => array( $bo_type => 1 ), 'method' => 'cash', 'form_key' => $bo_key() ) )['sale'];
$bo_storno = Pos::storno( $bo_sale2->sale_id, 'Fehlbuchung' );
$bo_storno_mv = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_movements WHERE sale_id=" . (int) $bo_sale2->sale_id );
check( 'A488: a storno at the till is still paid out and booked immediately', ! is_wp_error( $bo_storno ) && 'voided' === $bo_storno->status && $bo_storno_mv && (int) $bo_shift->session_id === (int) $bo_storno_mv->shift_id );

echo "A489: no ticket without receipt when a shift is counted/closed during a sale\n";
$bo_as( 613 );
$bo_shift2 = Shifts::open( $bo_reg2->register_id, array( 'float_minor' => 0 ) );
// Another tab counts and closes the shift exactly while the sale confirms its payment.
$GLOBALS['a489_race'] = array( 'shift' => (int) $bo_shift2->session_id, 'count' => null, 'close' => null );
add_action( 'wpcap_before_payment_confirm', static function () {
	if ( empty( $GLOBALS['a489_race']['shift'] ) ) { return; }
	$shift = $GLOBALS['a489_race']['shift'];
	$GLOBALS['a489_race']['shift'] = 0;
	$GLOBALS['a489_race']['count'] = Shifts::submit_count( $shift, array( 'cash_minor' => 0 ) );
	$GLOBALS['a489_race']['close'] = Shifts::close( $shift, array( 'reason' => 'Test' ) );
} );
$bo_race = Pos::sell( array( 'occurrence_id' => $bo_occ, 'quantities' => array( $bo_type => 1 ), 'method' => 'cash', 'form_key' => $bo_key() ) );
$bo_race_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE session_id=" . (int) $bo_shift2->session_id . ' ORDER BY sale_id DESC LIMIT 1' );
$bo_valid = $bo_race_row && $bo_race_row->order_id ? count( array_filter( Orders::tickets_for_order( (int) $bo_race_row->order_id ), static fn( $t ) => 'valid' === $t->status ) ) : -1;
check( 'A489: counting/closing is refused while the sale is being booked', is_wp_error( $GLOBALS['a489_race']['count'] ) && 'wpcap_pos_sale_in_progress' === $GLOBALS['a489_race']['count']->get_error_code() && is_wp_error( $GLOBALS['a489_race']['close'] ) );
check( 'A489: the sale is booked with receipt; every valid ticket has a receipt', ! is_wp_error( $bo_race ) && 'completed' === $bo_race_row->status && '' !== (string) $bo_race_row->receipt_number && 1 === $bo_valid && 'open' === Shifts::get( $bo_shift2->session_id )->status );
// The shift starts counting after the sale passed its first check (simulated inside the order transaction).
$GLOBALS['a489_count'] = (int) $bo_shift2->session_id;
add_action( 'wpcap_order_creating', static function () {
	global $wpdb;
	if ( empty( $GLOBALS['a489_count'] ) ) { return; }
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_boxoffice_sessions SET status='counting' WHERE session_id=" . (int) $GLOBALS['a489_count'] );
	$GLOBALS['a489_count'] = 0;
}, 99, 0 );
$bo_late = Pos::sell( array( 'occurrence_id' => $bo_occ, 'quantities' => array( $bo_type => 1 ), 'method' => 'cash', 'form_key' => $bo_key() ) );
$bo_late_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_boxoffice_sales WHERE session_id=" . (int) $bo_shift2->session_id . ' ORDER BY sale_id DESC LIMIT 1' );
check( 'A489: a sale that meets a counting shift is cancelled before payment – no ticket is issued', is_wp_error( $bo_late ) && 'wpcap_pos_session_closed' === $bo_late->get_error_code() && 'failed' === $bo_late_row->status && 'cancelled' === Orders::get( $bo_late_row->order_id )->status && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $bo_late_row->order_id ) );
// An abandoned sale (request died before the payment confirmation): closing cancels its order.
$wpdb->query( "UPDATE {$p}wpcap_boxoffice_sessions SET status='open' WHERE session_id=" . (int) $bo_shift2->session_id );
$bo_akey = $bo_key();
$bo_aorder = Orders::create( array( 'email' => 'boxoffice+abandoned@invalid.example', 'items' => array( array( 'ticket_type_id' => $bo_type, 'quantity' => 1 ) ), 'idempotency_key' => $bo_akey ), array( 'external' => true, 'payment_provider' => 'pos_cash', 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true ) );
$wpdb->insert( "{$p}wpcap_boxoffice_sales", array( 'session_id' => (int) $bo_shift2->session_id, 'order_id' => (int) $bo_aorder['order_id'], 'form_key_hash' => hash( 'sha256', 'pos-sale|' . $bo_akey ), 'payment_method' => 'cash', 'currency' => 'EUR', 'occurrence_id' => $bo_occ, 'cashier_id' => 613, 'status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
$bo_closed = Pos::close_session( $bo_shift2->session_id, ZReport::compute( $bo_shift2->session_id )['cash']['expected'] );
check( 'A489: closing cancels the order of an abandoned unfinished sale', ! is_wp_error( $bo_closed ) && 'closed' === $bo_closed->status && 'cancelled' === Orders::get( $bo_aorder['order_id'] )->status );

$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE event_id=$bo_ev" );
Settings::update( $bo_settings );
$GLOBALS['wpcap_test_user_id'] = $bo_user;
$GLOBALS['wpcap_test_denied_caps'] = $bo_denied;
