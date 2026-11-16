<?php
/**
 * A340: the admin refund form shows the amount (live, from the same calculation as the refund) and
 * how the money goes back, names both in the confirmation, and "Alle offenen Tickets" visibly
 * replaces the single selection instead of silently overriding it (server refuses both at once).
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=n'; } }

echo "A340: refund form with amount preview and route; \"Alle\" replaces the selection visibly\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$a340_threshold = Settings::get( 'refund_approval_threshold' );

$a340_type = make_type( 10, 10, 1000 );
$a340_order_id = admin_paid_order( $a340_type, 3, 'a340@example.com' );
$a340_order = Orders::get( $a340_order_id );
$a340_tickets = Orders::tickets_for_order( $a340_order_id );
$a340_ids = array_map( static fn( $t ) => (int) $t->ticket_id, $a340_tickets );

$a340_prev = OrdersPage::refund_preview( $a340_order, $a340_tickets );
check( 'preview: amount per ticket and for all open tickets (same calculation as the refund)', array_fill_keys( $a340_ids, 1000 ) === $a340_prev['per_ticket'] && 3000 === $a340_prev['all'] && $a340_ids === $a340_prev['all_ids'] );
check( 'preview: route of a manual payment says it is not paid out automatically', false !== strpos( $a340_prev['route'], 'Nicht automatisch' ) && $a340_prev['route'] === OrdersPage::refund_route( $a340_order ) );
$a340_calc = Refunds::preview( $a340_order, array( $a340_ids[0], $a340_ids[1] ) );
check( 'Refunds::preview equals the calculation of a two-ticket refund and changes nothing', is_array( $a340_calc ) && 2000 === $a340_calc['amount_minor'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $a340_order_id ) ) );

// "Alle" together with single tickets is refused instead of silently refunding everything.
$a340_amb = OrdersPage::refund( array( 'order_id' => $a340_order_id, 'refund_all' => '1', 'ticket_ids' => array( $a340_ids[0] ), 'reason' => 'Test', 'idempotency_key' => wp_generate_uuid4() ) );
check( '"Alle" plus single tickets: refused as ambiguous, nothing refunded', is_wp_error( $a340_amb ) && 'wpcap_refund_ambiguous' === $a340_amb->get_error_code() && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=%d", $a340_order_id ) ) );

// A pending refund of one ticket (second approval): the preview of "all" is the rest, flagged for approval.
Settings::update( array( 'refund_approval_threshold' => 500 ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Refunds::APPROVE_CAP );
$a340_pending = OrdersPage::refund( array( 'order_id' => $a340_order_id, 'ticket_ids' => array( $a340_ids[0] ), 'reason' => 'Test', 'idempotency_key' => wp_generate_uuid4() ) );
$a340_rest = Refunds::preview( $a340_order, array() );
check( 'pending refund: "all" covers the remaining tickets only and needs approval above the threshold', is_array( $a340_pending ) && 'pending_approval' === $a340_pending['refund']['status'] && 2000 === $a340_rest['amount_minor'] && array( $a340_ids[1], $a340_ids[2] ) === array_map( 'intval', $a340_rest['ticket_ids'] ) && true === $a340_rest['needs_approval'] );
check( 'success message names the amount', false !== strpos( OrdersPage::refund_message( $a340_pending['refund'] ), Forms::money( 1000, 'EUR' ) ) );

// The form: amounts on the checkboxes, total of "all", route, script for the live sum and the lock.
$_GET = array( 'page' => OrdersPage::SLUG, 'order_id' => $a340_order_id );
ob_start();
try { OrdersPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a340_html = ob_get_clean();
$_GET = array();
check( 'form: each refundable ticket carries its amount, "all" shows the remaining amount', 2 === substr_count( $a340_html, 'data-amount="1000"' ) && false !== strpos( $a340_html, 'Restbetrag der Bestellung: ' . Forms::money( 2000, 'EUR' ) ) && false === strpos( $a340_html, 'EXCEPTION' ) );
check( 'form: route, live amount region and the lock/confirmation script', false !== strpos( $a340_html, 'id="wpcap-refund-route"' ) && false !== strpos( $a340_html, 'Nicht automatisch' ) && false !== strpos( $a340_html, 'id="wpcap-refund-amount" aria-live="polite"' ) && false !== strpos( $a340_html, 'b.disabled=true' ) && false !== strpos( $a340_html, 'window.confirm(fmt(D.confirm' ) && false !== strpos( $a340_html, 'Ersetzt die Einzelauswahl' ) );

// Clean up.
$GLOBALS['wpcap_test_denied_caps'] = array();
Refunds::reject( (int) $a340_pending['refund']['refund_id'], 'Test' );
Settings::update( array( 'refund_approval_threshold' => $a340_threshold ) );
