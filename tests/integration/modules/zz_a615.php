<?php
/**
 * A615: dashboard widget "Pass Captain: Überblick" with sales (today / 7 days), open payment confirmations and
 * reviews, and the next dates with sold/capacity.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Admin\DashboardWidget;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

echo "A615: dashboard overview widget\n";
global $wpdb;
$p = $wpdb->prefix;
unset( $GLOBALS['wpcap_test_transients']['wpcap_dashboard_widget'] );
$a615_before = DashboardWidget::data();
check( 'A615: figures have sales, open counts and upcoming dates', isset( $a615_before['sales']['today'], $a615_before['sales']['week'], $a615_before['pending_manual'], $a615_before['needs_review'] ) && is_array( $a615_before['upcoming'] ) );
$a615_type = make_type( 40, 10, 3000 );
$a615_paid = Orders::create( array( 'email' => 'a615-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a615_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a615-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
$a615_open = Orders::create( array( 'email' => 'a615b-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a615_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a615b-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'manual', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A615: fixture orders created', ! is_wp_error( $a615_paid ) && ! is_wp_error( $a615_open ) );
if ( ! is_wp_error( $a615_paid ) && ! is_wp_error( $a615_open ) ) {
	Orders::confirm_external_payment( (int) $a615_paid['order_id'], 'a615-ref' );
	$a615_cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT currency FROM {$p}wpcap_orders WHERE order_id=%d", (int) $a615_paid['order_id'] ) );
	$a615_cached = DashboardWidget::data();
	check( 'A615: the figures are cached', $a615_cached === $a615_before );
	unset( $GLOBALS['wpcap_test_transients']['wpcap_dashboard_widget'] );
	$a615_after = DashboardWidget::data();
	$a615_orders = static function ( $d, $k ) use ( $a615_cur ) { return (int) ( $d['sales'][ $k ][ $a615_cur ]['orders'] ?? 0 ); };
	check( 'A615: a payment booked now counts for today and the 7 days', $a615_orders( $a615_after, 'today' ) === $a615_orders( $a615_before, 'today' ) + 1 && $a615_orders( $a615_after, 'week' ) === $a615_orders( $a615_before, 'week' ) + 1 );
	check( 'A615: the manual order waits for a payment confirmation', $a615_after['pending_manual'] >= $a615_before['pending_manual'] + 1 );
	$a615_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a615_type ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s, status='scheduled' WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 60 ), $a615_occ ) );
	unset( $GLOBALS['wpcap_test_transients']['wpcap_dashboard_widget'] );
	$a615_up = DashboardWidget::data()['upcoming'];
	$a615_row = null;
	foreach ( $a615_up as $r ) { if ( $r['occurrence_id'] === $a615_occ ) { $a615_row = $r; } }
	check( 'A615: the next date is listed with sold and capacity', null !== $a615_row && $a615_row['capacity'] > 0 && $a615_row['sold'] >= 2 );
	ob_start();
	DashboardWidget::render();
	$a615_html = (string) ob_get_clean();
	check( 'A615: the widget shows sales, open items and dates', false !== strpos( $a615_html, 'Verkäufe' ) && false !== strpos( $a615_html, 'Nächste Termine' ) && false !== strpos( $a615_html, 'page=wpcap-orders' ) );
	$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
	ob_start();
	DashboardWidget::render();
	$a615_events_only = (string) ob_get_clean();
	check( 'A615: without the order capability no amounts are shown, only dates', false === strpos( $a615_events_only, 'Verkäufe' ) && false !== strpos( $a615_events_only, 'Nächste Termine' ) );
	$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders', 'wpcap_manage_events' );
	check( 'A615: hidden for users without order or event capability', ! DashboardWidget::visible() );
	$GLOBALS['wpcap_test_denied_caps'] = array();
}
unset( $GLOBALS['wpcap_test_transients']['wpcap_dashboard_widget'] );
