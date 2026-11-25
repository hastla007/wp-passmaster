<?php
/**
 * E11-1 (owner decision E-11, 25.11.2026): refunding tickets already used for admission stays allowed
 * (default refund_after_checkin = allow), but only for people with the approver capability
 * (Refunds::APPROVE_CAP) and only with a reason – in every path: admin-post refund form
 * (OrdersPage::refund), Refunds::request(), the second approval (tickets used after the request),
 * WooCommerce refunds and the staff cancellation of a service booking. The reason lands in the audit
 * log (refund_checkin_override) and in the order history. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\Admin\OrderHistory;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\ServiceBookings;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$e11_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$e11_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$e11_saved_settings = get_option( Settings::OPTION, array() );
PassCaptain\Security\Policy::register(); // Settings key refund_after_checkin (registered once per request).
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
Settings::update( array( 'refund_approval_threshold' => 0, 'refund_after_checkin' => 'allow' ) );

/** Latest audit row of an action for an order (details decoded), or null. */
function e11_audit( $action, $order_id, $refund_id = 0 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_type='order' AND object_id=%d ORDER BY audit_id DESC", $action, (int) $order_id ) );
	foreach ( $rows as $row ) {
		$details = (array) json_decode( (string) $row->details, true );
		if ( ! $refund_id || (int) ( $details['refund_id'] ?? 0 ) === (int) $refund_id ) {
			$row->data = $details;
			return $row;
		}
	}
	return null;
}
function e11_refunded( $ticket_id ) {
	global $wpdb;
	return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT refunded FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", (int) $ticket_id ) );
}
function e11_check_in( $ticket_id ) {
	global $wpdb;
	$wpdb->update( "{$wpdb->prefix}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => (int) $ticket_id ) );
}

echo "E11-1: refund after check-in only for approvers and only with a reason\n";
check( 'E11-1: default of refund_after_checkin stays "allow"', 'allow' === PassCaptain\Security\Policy::defaults( array() )['refund_after_checkin'] && 'allow' === Refunds::checkin_policy() );
$e11_type = ex_type( ex_occ( ex_event( 'E11 Einlass' ) ), 'E11', 2000 );
list( $e11_order, $e11_tickets ) = ex_buy( $e11_type, 2, 'e11@example.com' );
e11_check_in( $e11_tickets[0] );

// Admin-post refund form (OrdersPage::refund) without the exception reason.
$e11_form = array( 'order_id' => $e11_order, 'reason' => 'Kulanz', 'idempotency_key' => 'e11form' . md5( uniqid( '', true ) ), 'ticket_ids' => array( $e11_tickets[0] ) );
$e11_r = OrdersPage::refund( $e11_form );
check( 'E11-1: admin form without reason for the checked-in ticket is refused (wpcap_refund_used_tickets)', is_wp_error( $e11_r ) && 'wpcap_refund_used_tickets' === $e11_r->get_error_code() && ! e11_refunded( $e11_tickets[0] ) );
$e11_r = Refunds::request( $e11_order, array( $e11_tickets[0] ), 'Kulanz', 'e11-noreason-' . uniqid(), array( 'used_override' => '   ' ) );
check( 'E11-1: Refunds::request() with a blank reason is refused', is_wp_error( $e11_r ) && 'wpcap_refund_used_tickets' === $e11_r->get_error_code() );

// Without the approver capability (refund capability only).
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$e11_r = OrdersPage::refund( $e11_form + array( 'used_override' => 'Abbruch nach 20 Minuten' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'E11-1: without the approver capability the refund is refused even with a reason', is_wp_error( $e11_r ) && 'wpcap_refund_used_tickets' === $e11_r->get_error_code() && ! e11_refunded( $e11_tickets[0] ) && null === e11_audit( Refunds::CHECKIN_OVERRIDE_ACTION, $e11_order ) );

// With reason: request (second approval as before, A131), approval by another approver, refund done.
$e11_r = OrdersPage::refund( $e11_form + array( 'used_override' => 'Abbruch nach 20 Minuten' ) );
$e11_refund = is_array( $e11_r ) ? $e11_r['refund'] : array();
$e11_audit = $e11_refund ? e11_audit( Refunds::CHECKIN_OVERRIDE_ACTION, $e11_order, (int) $e11_refund['refund_id'] ) : null;
check( 'E11-1: with reason the request is created and audited with the reason (basis exception)', $e11_refund && 'pending_approval' === $e11_refund['status'] && $e11_audit && 'Abbruch nach 20 Minuten' === ( $e11_audit->data['reason'] ?? '' ) && 'exception' === ( $e11_audit->data['basis'] ?? '' ) && array( $e11_tickets[0] ) === ( $e11_audit->data['ticket_ids'] ?? array() ) && 1 === (int) $e11_audit->user_id );
$GLOBALS['wpcap_test_user_id'] = 2;
$e11_ok = OrdersPage::approve_refund( array( 'refund_id' => (int) $e11_refund['refund_id'], 'order_id' => $e11_order ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'E11-1: approval by a second approver needs no further reason (exception recorded) and refunds the ticket', is_array( $e11_ok ) && 'completed' === ( $e11_ok['refund']['status'] ?? '' ) && e11_refunded( $e11_tickets[0] ) && null !== e11_audit( 'refund_completed', $e11_order, (int) $e11_refund['refund_id'] ) );
$e11_history = array_values( array_filter( OrderHistory::entries( $e11_order ), static function ( $e ) { return Refunds::CHECKIN_OVERRIDE_ACTION === $e['label']; } ) );
check( 'E11-1: the order history shows the entry with the reason first', 1 === count( $e11_history ) && 0 === strpos( (string) $e11_history[0]['detail'], 'reason: Abbruch nach 20 Minuten' ) );

echo "E11-1: tickets used after the request need the approver's reason\n";
list( $e11_o2, $e11_t2 ) = ex_buy( $e11_type, 1, 'e11b@example.com' );
$e11_req = Refunds::request( $e11_o2, $e11_t2, 'Storno', 'e11-late-' . uniqid(), array( 'require_approval' => true ) );
check( 'E11-1: request for an unused ticket waits for approval without exception audit', is_array( $e11_req ) && 'pending_approval' === $e11_req['status'] && null === e11_audit( Refunds::CHECKIN_OVERRIDE_ACTION, $e11_o2 ) );
e11_check_in( $e11_t2[0] );
$GLOBALS['wpcap_test_user_id'] = 2;
check( 'E11-1: the pending refund now counts the ticket as used without exception', array( (int) $e11_t2[0] ) === Refunds::uncovered_used_tickets( Refunds::get( (int) $e11_req['refund_id'] ) ) );
$e11_r = OrdersPage::approve_refund( array( 'refund_id' => (int) $e11_req['refund_id'], 'order_id' => $e11_o2 ) );
check( 'E11-1: approval without reason is refused, refund stays pending', is_wp_error( $e11_r ) && 'wpcap_refund_used_tickets' === $e11_r->get_error_code() && 'pending_approval' === Refunds::get( (int) $e11_req['refund_id'] )['status'] && ! e11_refunded( $e11_t2[0] ) );
ob_start();
OrdersPage::render_refund_table( array( Refunds::get( (int) $e11_req['refund_id'] ) ), 'EUR', false );
$e11_html = (string) ob_get_clean();
check( 'E11-1: the approval form offers the required reason field', 1 === preg_match( '/<input type="text" id="wpcap-approve-override-\d+" name="used_override" required/', $e11_html ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$e11_r = Refunds::approve( (int) $e11_req['refund_id'], array( 'used_override' => 'Doppelbuchung' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'E11-1: approval without the approver capability is refused', is_wp_error( $e11_r ) && 'wpcap_refund_approver' === $e11_r->get_error_code() );
Settings::update( array( 'refund_after_checkin' => 'deny' ) );
$e11_r = Refunds::approve( (int) $e11_req['refund_id'], array( 'used_override' => 'Doppelbuchung' ) );
Settings::update( array( 'refund_after_checkin' => 'allow' ) );
check( 'E11-1: with refund_after_checkin = deny the approval is refused even with a reason', is_wp_error( $e11_r ) && 'wpcap_refund_checked_in' === $e11_r->get_error_code() );
$e11_r = OrdersPage::approve_refund( array( 'refund_id' => (int) $e11_req['refund_id'], 'order_id' => $e11_o2, 'used_override' => 'Doppelbuchung' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$e11_audit = e11_audit( Refunds::CHECKIN_OVERRIDE_ACTION, $e11_o2, (int) $e11_req['refund_id'] );
check( 'E11-1: approval with reason refunds, audits (basis approval) and adds the exception to the refund reason', is_array( $e11_r ) && 'completed' === ( $e11_r['refund']['status'] ?? '' ) && e11_refunded( $e11_t2[0] ) && $e11_audit && 'approval' === ( $e11_audit->data['basis'] ?? '' ) && 'Doppelbuchung' === ( $e11_audit->data['reason'] ?? '' ) && 2 === (int) $e11_audit->user_id && false !== strpos( (string) Refunds::get( (int) $e11_req['refund_id'] )['reason'], 'Ausnahme: Doppelbuchung' ) );

// System flow (cancelled date, allow_used) stays possible and is recorded as such.
list( $e11_o3, $e11_t3 ) = ex_buy( $e11_type, 1, 'e11c@example.com' );
e11_check_in( $e11_t3[0] );
$e11_sys = Refunds::request( $e11_o3, $e11_t3, 'Termin abgesagt', 'e11-sys-' . uniqid(), array( 'system' => true, 'allow_used' => true, 'require_approval' => true ) );
$GLOBALS['wpcap_test_user_id'] = 2;
$e11_sys_ok = is_array( $e11_sys ) ? Refunds::approve( (int) $e11_sys['refund_id'] ) : null;
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'E11-1: cancelled date (system, allow_used) is audited with basis allow_used and approved without extra reason', is_array( $e11_sys_ok ) && 'completed' === $e11_sys_ok['status'] && 'allow_used' === ( e11_audit( Refunds::CHECKIN_OVERRIDE_ACTION, $e11_o3, (int) $e11_sys['refund_id'] )->data['basis'] ?? '' ) );

echo "E11-1: WooCommerce refunds of checked-in tickets\n";
$e11_wtype = make_type( 10, 10, 1500 );
$e11_wc = woo_t_order( 7811, array( array( $e11_wtype, 2, '30.00' ) ), 'stripe' );
$e11_core = Woo::reserve( $e11_wc );
$e11_wc->status = 'processing';
Woo::sync_paid_order( 7811 );
$e11_wtickets = tickets( $e11_core );
e11_check_in( (int) $e11_wtickets[0]->ticket_id );
$e11_line = $e11_wc->items[0]->get_id();
$e11_guard = static function ( $refund, array $args ) {
	try { Woo::guard_refund( $refund, $args ); } catch ( Exception $e ) { return $e->getMessage(); }
	return '';
};
$e11_args = array( 'order_id' => 7811, 'line_items' => array( $e11_line => array( 'qty' => 2 ) ) );
check( 'E11-1: WooCommerce follows the stored setting (allow)', Woo::checked_in_refund_allowed( $e11_wc ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$e11_msg = $e11_guard( new Wpcap_Test_Woo_Refund( 7812 ), $e11_args + array( 'reason' => 'Abbruch' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'E11-1: WooCommerce refund of a checked-in ticket without approver capability is refused', false !== strpos( $e11_msg, 'Freigabeberechtigung' ) );
$e11_msg = $e11_guard( new Wpcap_Test_Woo_Refund( 7812 ), $e11_args + array( 'reason' => '' ) );
check( 'E11-1: WooCommerce refund of a checked-in ticket without reason is refused', false !== strpos( $e11_msg, 'Grund' ) );
check( 'E11-1: WooCommerce quantities served by unchecked tickets and amount-only refunds need no reason', '' === $e11_guard( new Wpcap_Test_Woo_Refund( 7812 ), array( 'order_id' => 7811, 'line_items' => array( $e11_line => array( 'qty' => 1 ) ) ) ) && '' === $e11_guard( new Wpcap_Test_Woo_Refund( 7812 ), array( 'order_id' => 7811, 'line_items' => array() ) ) );
$GLOBALS['wpcap_test_user_id'] = 0;
check( 'E11-1: WooCommerce refunds without a user (gateway webhook, cron) are not refused', '' === $e11_guard( new Wpcap_Test_Woo_Refund( 7812 ), $e11_args ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$e11_wrefund = woo_t_refund( 7813, $e11_wc, '30.00', array( array( 0, 2, '30.00' ) ) );
check( 'E11-1: WooCommerce refund with reason passes and keeps the reason on the refund', '' === $e11_guard( $e11_wrefund, $e11_args + array( 'reason' => 'Unwetter, Abbruch' ) ) && 'Unwetter, Abbruch' === $e11_wrefund->get_meta( Woo::CHECKIN_OVERRIDE_META, true ) );
Woo::sync_refund( 7811, 7813 );
Woo::sync_refund( 7811, 7813 );
$e11_waudit = e11_audit( Refunds::CHECKIN_OVERRIDE_ACTION, $e11_core );
check( 'E11-1: the synced WooCommerce refund is audited once with the reason and noted on the order', $e11_waudit && 'Unwetter, Abbruch' === ( $e11_waudit->data['reason'] ?? '' ) && 'woocommerce' === ( $e11_waudit->data['basis'] ?? '' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_id=%d", Refunds::CHECKIN_OVERRIDE_ACTION, $e11_core ) ) && 1 === count( array_filter( $e11_wc->notes, static fn( $n ) => false !== strpos( $n, 'mit Begründung: Unwetter, Abbruch' ) ) ) );

echo "E11-1: service booking cancelled by staff with a checked-in ticket\n";
$e11_saved_hooks = $GLOBALS['wpcap_test_hooks'];
ServiceBookings::flush_schema_cache();
PassCaptain\ServiceBookingFlow::register();
add_action( 'wpcap_order_paid', array( ServiceBookings::class, 'on_order_paid' ), 30, 1 );
add_action( 'wpcap_tickets_issued', array( ServiceBookings::class, 'on_order_paid' ), 30, 1 );
list( $e11_sevent, $e11_stype ) = svc_event( 4000 );
$e11_res = ServiceBookings::create_resource( $e11_sevent, array( 'name' => 'E11 Boot', 'capacity' => 2, 'ticket_type_id' => $e11_stype ) );
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $e11_res, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 4200 ), 'capacity' => 2, 'status' => 'open' ) );
$e11_slot = (int) $wpdb->insert_id;
$e11_booking = svc_book( $e11_slot, 'e11-svc@example.com' );
$e11_bid = is_array( $e11_booking ) ? (int) $e11_booking['booking_id'] : 0;
wpcap_test_reset_transients();
ServiceBookings::confirm( $e11_bid, array( 'provider' => 'manual' ) );
$e11_sorder = (int) ServiceBookings::booking( $e11_bid )->order_id;
Orders::confirm_manual_payment( $e11_sorder );
ServiceBookings::fill_tickets( $e11_bid );
$e11_stickets = array_map( 'absint', (array) json_decode( (string) ServiceBookings::get( $e11_bid )->ticket_ids, true ) );
if ( $e11_stickets ) { e11_check_in( $e11_stickets[0] ); }
$e11_r = ServiceBookings::cancel( $e11_bid, array( 'actor' => 'admin', 'refund' => true ) );
check( 'E11-1: staff cancellation with refund of a booking with a checked-in ticket is refused, nothing refunded', $e11_stickets && is_wp_error( $e11_r ) && 'wpcap_refund_used_tickets' === $e11_r->get_error_code() && 'confirmed' === ServiceBookings::booking( $e11_bid )->status && ! Refunds::for_order( $e11_sorder ) );
$e11_r = ServiceBookings::cancel( $e11_bid, array( 'actor' => 'admin', 'refund' => false, 'reason' => 'Ohne Erstattung' ) );
check( 'E11-1: cancelling the same booking without refund still works', is_array( $e11_r ) && 'cancelled' === ( $e11_r['status'] ?? '' ) && ! Refunds::for_order( $e11_sorder ) );
$GLOBALS['wpcap_test_hooks'] = $e11_saved_hooks;

update_option( Settings::OPTION, $e11_saved_settings );
if ( null === $e11_saved_user ) { unset( $GLOBALS['wpcap_test_user_id'] ); } else { $GLOBALS['wpcap_test_user_id'] = $e11_saved_user; }
$GLOBALS['wpcap_test_denied_caps'] = $e11_saved_denied;
