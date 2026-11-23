<?php
/**
 * A337: paid orders without tickets (`needs_review`) are visible in the admin: counter on the
 * "Bestellungen" menu entry, admin notice with a link to the filtered list (scoped to the user's
 * events), link to the order in the alert mail.
 * Included from run.php (uses check(), make_type(), expire_holds_of()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Menu;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Admin\Queries;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }

echo "A337: paid orders without tickets are counted and announced in the admin\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

// A late payment for an order whose occurrence was cancelled meanwhile: flagged needs_review.
$a337_type = make_type( 5, 5, 1000 );
$a337_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a337_type ) );
$a337_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a337_occ ) );
$GLOBALS['wpcap_test_transients'] = array();
$a337_r = Orders::create( array( 'email' => 'a337@example.com', 'items' => array( array( 'ticket_type_id' => $a337_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a337-' . uniqid( '', true ) ), array( 'hold_minutes' => 31 ) );
expire_holds_of( $a337_r['order_id'] );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a337_occ ) );
$GLOBALS['wpcap_test_mails'] = array();
$a337_pay = Orders::confirm_external_payment( $a337_r['order_id'], 'test:a337' );
$a337_order = Orders::get( $a337_r['order_id'] );
check( 'precondition: late payment flagged needs_review', is_wp_error( $a337_pay ) && 'needs_review' === $a337_order->status );
$a337_mail = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( (string) $m['subject'], 'ohne Tickets' ) ) );
check( 'alert mail links to the order in the admin', $a337_mail && false !== strpos( (string) $a337_mail[0]['body'], OrdersPage::order_url( $a337_order->order_id ) ) );

$a337_all = Queries::needs_review_count( null );
check( 'count of orders that need a decision (all events)', $a337_all >= 1 && $a337_all === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE status='needs_review'" ) );
check( 'count respects the scope: own event 1, other events 0', 1 === Queries::needs_review_count( array( $a337_event ) ) && 0 === Queries::needs_review_count( array( $a337_event + 100000 ) ) );

// Menu entry "Bestellungen" carries the counter.
$a337_entries = Menu::entries();
$a337_orders = array_values( array_filter( $a337_entries, static fn( $e ) => OrdersPage::SLUG === $e[0] ) );
check( 'menu: "Bestellungen" shows the counter with an accessible label', $a337_orders && false !== strpos( $a337_orders[0][2], 'awaiting-mod' ) && false !== strpos( $a337_orders[0][2], '>' . $a337_all . '<' ) && false !== strpos( $a337_orders[0][2], 'geklärt werden' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( OrdersPage::CAP );
$a337_entries = Menu::entries();
$a337_orders = array_values( array_filter( $a337_entries, static fn( $e ) => OrdersPage::SLUG === $e[0] ) );
check( 'menu: no counter without the orders capability', $a337_orders && false === strpos( $a337_orders[0][2], 'awaiting-mod' ) );

// Admin notice with link to the filtered list.
$a337_notice = static function ( array $get ) {
	$_GET = $get;
	ob_start();
	Menu::needs_review_notice();
	$_GET = array();
	return (string) ob_get_clean();
};
check( 'notice: hidden without the orders capability', '' === $a337_notice( array( 'page' => 'wpcap-tickets' ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$a337_html = $a337_notice( array( 'page' => 'wpcap-tickets' ) );
check( 'notice: plugin pages show the number and link to the filtered order list', false !== strpos( $a337_html, 'ohne Tickets' ) && false !== strpos( $a337_html, esc_url( Menu::needs_review_url() ) ) && false !== strpos( Menu::needs_review_url(), 'status=needs_review' ) );
check( 'notice: not repeated on the filtered list, not on foreign admin pages', '' === $a337_notice( array( 'page' => OrdersPage::SLUG, 'status' => 'needs_review' ) ) && '' === $a337_notice( array( 'page' => 'other-plugin' ) ) );
check( 'notice: registered on admin_notices', false !== strpos( file_get_contents( WPCAP_PATH . 'src/Admin/Menu.php' ), "add_action( 'admin_notices', array( self::class, 'needs_review_notice' ) );" ) );

// The filtered list finds it.
$a337_rows = Queries::search_orders( array( 'status' => 'needs_review' ), null, 1, 100 )['rows'];
check( 'filtered order list contains the order', in_array( (int) $a337_order->order_id, array_map( static fn( $o ) => (int) $o->order_id, $a337_rows ), true ) );

// Clean up: settle the order so later tests do not see an open review.
$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'refunded', 'payment_status' => 'refunded' ), array( 'order_id' => (int) $a337_order->order_id ) );
