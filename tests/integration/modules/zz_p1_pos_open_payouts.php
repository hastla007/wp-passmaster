<?php
/**
 * TODO L712 (24.11.2026): cash/card refunds of box office sales need an open shift for the payout.
 * The flow is A488 (back-office refund → open payout → a cashier pays it out in their open shift);
 * this module checks that the waiting payouts are visible on every tab of the box office (also
 * without an own open shift), only within the user's event scope, and that the notice disappears
 * once the payout is paid.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\BoxOffice\Registers;
use PassCaptain\BoxOffice\Service as Pos;
use PassCaptain\BoxOffice\ShiftPage;
use PassCaptain\BoxOffice\Shifts;
use PassCaptain\Modules\BoxOffice as BoxOfficeModule;
use PassCaptain\Refunds;
use PassCaptain\Settings;

BoxOfficeModule::register();
global $wpdb;
$p = $wpdb->prefix;
$op_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$op_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$op_get = $_GET;
$op_settings = array( 'seller_country' => Settings::get( 'seller_country' ), 'boxoffice_cash_confirmed' => Settings::get( 'boxoffice_cash_confirmed' ), 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'require_2fa_for_refunds' => Settings::get( 'require_2fa_for_refunds' ) );
Settings::update( array( 'seller_country' => 'AT', 'boxoffice_cash_confirmed' => 1, 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
$op_as = static function ( $user_id, array $denied = array( 'manage_options', 'wpcap_view_finance', 'wpcap_boxoffice_override' ) ) {
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$GLOBALS['wpcap_test_denied_caps'] = $denied;
};
$op_key = static function () { return 'p1op-' . bin2hex( random_bytes( 8 ) ); };
$op_notice = static function () {
	ob_start();
	ShiftPage::render_open_payouts_notice();
	return (string) ob_get_clean();
};
$op_page = static function ( array $get ) {
	$_GET = $get;
	ob_start();
	try { PassCaptain\BoxOffice\Page::render(); } catch ( Throwable $e ) { ob_end_clean(); return 'EXCEPTION ' . $e->getMessage(); }
	return (string) ob_get_clean();
};
$op_eur = static function () {
	$s = Shifts::open_payout_summary();
	return $s['EUR'] ?? array( 'count' => 0, 'amount_minor' => 0, 'oldest' => '' );
};

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$op_now = gmdate( 'Y-m-d H:i:s' );
$op_events = array();
foreach ( array( 'Kassen-Rückzahlung', 'Anderes Event' ) as $op_name ) {
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => $op_name, 'slug' => 'p1op' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $op_now, 'updated_at' => $op_now ) );
	$op_events[] = (int) $wpdb->insert_id;
}
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $op_events[0], 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$op_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $op_occ, 'name' => 'Abendkasse', 'price_minor' => 2380, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active', 'tax_rate_bp' => 1900 ) );
$op_type = (int) $wpdb->insert_id;
foreach ( array( 631, 632 ) as $op_uid ) { $wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $op_events[0], 'user_id' => $op_uid, 'capability' => 'wpcap_manage_orders' ) ); }
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $op_events[1], 'user_id' => 633, 'capability' => 'wpcap_manage_orders' ) );
$op_reg = Registers::save( array( 'name' => 'Kasse P1 Rückzahlung', 'blind_count' => 0 ) );

echo "L712: open box office payouts are visible on every tab of the box office\n";
$op_as( 631 );
$op_shift = Shifts::open( $op_reg->register_id, array( 'float_minor' => 0 ) );
$op_sale = Pos::sell( array( 'occurrence_id' => $op_occ, 'quantities' => array( $op_type => 1 ), 'method' => 'card', 'form_key' => $op_key() ) )['sale'];
$op_before = array();
foreach ( array( 631, 632, 633 ) as $op_uid ) { $op_as( $op_uid ); $op_before[ $op_uid ] = $op_eur(); }
$op_as( 632 ); // Back office without a shift of its own.
$op_refund = Refunds::request( (int) $op_sale->order_id, array(), 'Kulanz (Verwaltung)', 'p1op-' . uniqid() );
$op_payout = is_wp_error( $op_refund ) ? null : Shifts::payout_for_refund( (int) $op_refund['refund_id'] );
check( 'L712: a back-office refund of a card sale waits as an open payout', $op_payout && 'open' === $op_payout->status && 'card' === $op_payout->method );
$op_after = $op_eur();
check( 'L712: the summary counts it with its amount for a user in scope', $op_before[632]['count'] + 1 === $op_after['count'] && $op_before[632]['amount_minor'] + 2380 === $op_after['amount_minor'] && '' !== $op_after['oldest'] );
$op_html = $op_notice();
check( 'L712: without an own open shift the notice names the payouts and leads to opening a shift', false !== strpos( $op_html, 'Offene Kassen-Rückzahlungen:' ) && false !== strpos( $op_html, 'Du hast keine offene Schicht' ) && false !== strpos( $op_html, 'tab=sale' ) && false === strpos( $op_html, '#wpcap-open-payouts' ) );
foreach ( array( 'sale' => array(), 'shifts' => array( 'tab' => 'shifts' ) ) as $op_tab => $op_args ) {
	$op_screen = $op_page( array_merge( array( 'page' => 'wpcap-boxoffice' ), $op_args ) );
	check( "L712: the box office tab '$op_tab' shows the notice", false !== strpos( $op_screen, 'wpcap-pos-open-payouts' ) );
}
$op_as( 633 );
$op_other = $op_eur();
check( 'L712: staff of another event does not see the payout in the summary', $op_before[633]['count'] === $op_other['count'] && $op_before[633]['amount_minor'] === $op_other['amount_minor'] );
$op_as( 631 );
$op_html = $op_notice();
check( 'L712: a cashier with an open shift is led to the payout list of the shift tab', false !== strpos( $op_html, 'Offene Kassen-Rückzahlungen:' ) && false !== strpos( $op_html, '#wpcap-open-payouts' ) && false === strpos( $op_html, 'Du hast keine offene Schicht' ) );
$op_shift_screen = $op_page( array( 'page' => 'wpcap-boxoffice', 'tab' => 'shift' ) );
check( 'L712: the anchor of the payout list exists on the shift tab', false !== strpos( $op_shift_screen, 'id="wpcap-open-payouts"' ) && false !== strpos( $op_shift_screen, 'name="payout_id" value="' . (int) $op_payout->payout_id . '"' ) );
$op_paid = Shifts::pay_out( (int) $op_payout->payout_id, (int) $op_shift->session_id );
$op_final = $op_eur();
check( 'L712: after the payout the summary drops it again', ! is_wp_error( $op_paid ) && $op_before[631]['count'] === $op_final['count'] && $op_before[631]['amount_minor'] === $op_final['amount_minor'] );
if ( 0 === $op_final['count'] && ! Shifts::open_payout_summary() ) {
	check( 'L712: no notice without open payouts', '' === $op_notice() );
}

$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE event_id IN (" . implode( ',', $op_events ) . ')' );
Settings::update( $op_settings );
$_GET = $op_get;
$GLOBALS['wpcap_test_user_id'] = $op_user;
$GLOBALS['wpcap_test_denied_caps'] = $op_denied;
