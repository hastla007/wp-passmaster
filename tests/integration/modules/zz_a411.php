<?php
// phpcs:ignoreFile
/**
 * A411: the order list is a WP_List_Table – bulk action "Zahlung bestätigen" (bank transfer only),
 * sortable date/total (whitelisted ORDER BY), views by state, screen option for the page size, empty
 * state with "Filter zurücksetzen". Without wp-admin (this harness) a small stand-in of WP_List_Table is
 * used that calls the same hooks of the subclass as the core class.
 */

use PassCaptain\Admin\OrdersPage;
use PassCaptain\Admin\Queries;
use PassCaptain\Orders;

echo "A411: order list as WP_List_Table\n";
global $wpdb;
$p = $wpdb->prefix;
$z3l_root = dirname( __DIR__, 3 );

foreach ( array( 'esc_url' => 'return htmlspecialchars( (string) $a[0], ENT_QUOTES );', 'esc_attr__' => 'return esc_attr( $a[0] );', 'esc_attr_e' => 'echo esc_attr( $a[0] );', 'esc_html_e' => 'echo esc_html( $a[0] );', 'wp_nonce_url' => 'return $a[0] . "&_wpnonce=n";', 'wp_timezone_string' => 'return "Europe/Vienna";', 'selected' => 'return (string) $a[0] === (string) ( $a[1] ?? true ) ? " selected=\'selected\'" : "";', 'number_format_i18n' => 'return number_format( (float) $a[0] );' ) as $z3l_fn => $z3l_body ) {
	if ( ! function_exists( $z3l_fn ) ) { eval( "function $z3l_fn( ...\$a ) { $z3l_body }" ); }
}
// Stand-in with the parts of the core API the list tables use (shared with zz_a411_lists).
require_once dirname( __DIR__ ) . '/lib/list_table_standin.php';

wpcap_test_reset_transients();
$z3l_type = make_type( 50, 10, 1200 );
$z3l_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$z3l_type" );
$z3l_new = static function ( $qty, $mail ) use ( $z3l_type ) {
	return Orders::create( array( 'email' => $mail, 'items' => array( array( 'ticket_type_id' => $z3l_type, 'quantity' => $qty ) ), 'idempotency_key' => 'z3l-' . uniqid( '', true ) ) );
};
$z3l_a = $z3l_new( 1, 'z3l-a@example.com' );
$z3l_b = $z3l_new( 3, 'z3l-b@example.com' );
$z3l_c = $z3l_new( 2, 'z3l-c@example.com' );
check( 'A411 three pending orders for the test event', ! is_wp_error( $z3l_a ) && ! is_wp_error( $z3l_b ) && ! is_wp_error( $z3l_c ) );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='manual' WHERE order_id IN (" . (int) $z3l_a['order_id'] . ',' . (int) $z3l_b['order_id'] . ')' );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='stripe' WHERE order_id=" . (int) $z3l_c['order_id'] );

// Sorting: whitelisted column and direction only.
check( 'A411 ORDER BY whitelist: date/total, asc/desc, anything else falls back to newest first', 'ord.total_minor ASC, ord.order_id ASC' === Queries::order_sort_sql( array( 'orderby' => 'total', 'order' => 'asc' ) ) && 'ord.created_at DESC, ord.order_id DESC' === Queries::order_sort_sql( array( 'orderby' => 'total_minor; DROP TABLE x', 'order' => 'sideways' ) ) && 'ord.created_at DESC, ord.order_id DESC' === Queries::order_sort_sql( array() ) );
$z3l_totals = static function ( $order ) use ( $z3l_event ) { return array_map( static fn( $r ) => (int) $r->total_minor, Queries::search_orders( array( 'event_id' => $z3l_event ), null, 1, 20, array( 'orderby' => 'total', 'order' => $order ) )['rows'] ); };
check( 'A411 the list sorts by total in both directions', array( 1200, 2400, 3600 ) === $z3l_totals( 'asc' ) && array( 3600, 2400, 1200 ) === $z3l_totals( 'desc' ) );
$_GET = array( 'orderby' => 'total', 'order' => 'ASC' );
check( 'A411 list_sort() reads orderby/order from the request (whitelisted)', array( 'orderby' => 'total', 'order' => 'asc' ) === OrdersPage::list_sort() );
$_GET = array( 'orderby' => 'ord.customer_email' );
check( 'A411 unknown sort columns are ignored', array( 'orderby' => 'date', 'order' => 'desc' ) === OrdersPage::list_sort() );
$_GET = array();

// Bulk action "Zahlung bestätigen".
$z3l_paid_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE payment_status='paid'" );
$z3l_bulk = OrdersPage::bulk_confirm_payments( array( $z3l_a['order_id'], (string) $z3l_b['order_id'], $z3l_c['order_id'], 0, 99999999, $z3l_a['order_id'] ) );
check( 'A411 bulk: open bank-transfer orders are confirmed (each once)', array( (int) $z3l_a['order_id'], (int) $z3l_b['order_id'] ) === $z3l_bulk['confirmed'] && 'paid' === Orders::get( $z3l_a['order_id'] )->payment_status && 'paid' === Orders::get( $z3l_b['order_id'] )->payment_status );
check( 'A411 bulk: other providers and unknown ids are skipped, nothing else changes', in_array( (int) $z3l_c['order_id'], $z3l_bulk['skipped'], true ) && in_array( 99999999, $z3l_bulk['skipped'], true ) && 'pending' === Orders::get( $z3l_c['order_id'] )->payment_status && $z3l_paid_before + 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE payment_status='paid'" ) );
$z3l_again = OrdersPage::bulk_confirm_payments( array( $z3l_a['order_id'] ) );
check( 'A411 bulk: an already paid order is skipped on a second run', array() === $z3l_again['confirmed'] && array( (int) $z3l_a['order_id'] ) === $z3l_again['skipped'] );
$z3l_msgs = OrdersPage::bulk_messages( $z3l_bulk );
check( 'A411 bulk: notices name confirmed and skipped orders', 'success' === $z3l_msgs[0][0] && false !== strpos( $z3l_msgs[0][1], '2 Bestellungen' ) && 'warning' === $z3l_msgs[1][0] && 'warning' === OrdersPage::bulk_messages( array() )[0][0] );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='manual' WHERE order_id=" . (int) $z3l_c['order_id'] );
$z3l_denied = OrdersPage::bulk_confirm_payments( array( $z3l_c['order_id'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'A411 bulk: without access to the order the single-action check refuses it', isset( $z3l_denied['failed'][ (int) $z3l_c['order_id'] ] ) && 'pending' === Orders::get( $z3l_c['order_id'] )->payment_status );
check( 'A411 bulk: at most BULK_LIMIT orders per request', 100 === OrdersPage::BULK_LIMIT );

// Views, empty state, screen option.
$z3l_views = OrdersPage::list_views( array( 'status' => '', 'payment_status' => 'pending', 'search' => 'x', 'event_id' => 5 ) );
check( 'A411 views: all, open, paid, to clarify, cancelled; the active one is marked; search and event are kept', array( 'all', 'pending', 'paid', 'needs_review', 'cancelled' ) === array_keys( $z3l_views ) && $z3l_views['pending']['current'] && ! $z3l_views['all']['current'] && false !== strpos( $z3l_views['paid']['url'], 'payment_status=paid' ) && false !== strpos( $z3l_views['paid']['url'], 'event_id=5' ) && false === strpos( $z3l_views['needs_review']['url'], 'payment_status' ) );
check( 'A411 empty state: "Filter zurücksetzen" only with active filters', false !== strpos( OrdersPage::empty_state( array( 'search' => 'nobody' ) ), 'Filter zurücksetzen' ) && false === strpos( OrdersPage::empty_state( array( 'status' => '', 'payment_status' => '', 'search' => '', 'event_id' => 0 ) ), 'Filter zurücksetzen' ) );
check( 'A411 screen option: page size stored between 1 and 200, other options untouched', 200 === OrdersPage::save_per_page( false, OrdersPage::PER_PAGE_OPTION, '5000' ) && 1 === OrdersPage::save_per_page( false, OrdersPage::PER_PAGE_OPTION, '0' ) && 'keep' === OrdersPage::save_per_page( 'keep', 'other_option', '5' ) );
$z3l_menu = (string) file_get_contents( $z3l_root . '/src/Admin/Menu.php' );
check( 'A411 the orders page hooks screen option and bulk action on load-{hook}', false !== strpos( $z3l_menu, "add_action( 'load-' . \$hook, array( OrdersPage::class, 'load_screen' ) )" ) && false !== strpos( $z3l_menu, "'set_screen_option_' . OrdersPage::PER_PAGE_OPTION" ) );

// Rendering through the list table.
$GLOBALS['z3l_user_options'] = array( OrdersPage::PER_PAGE_OPTION => 2 );
$_GET = array( 'page' => OrdersPage::SLUG, 'event_id' => (string) $z3l_event );
ob_start();
try { OrdersPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$z3l_html = (string) ob_get_clean();
check( 'A411 list: checkbox per order, bulk action, sortable date/total', false === strpos( $z3l_html, 'EXCEPTION' ) && 2 === substr_count( $z3l_html, 'name="order[]"' ) && false !== strpos( $z3l_html, 'value="' . OrdersPage::BULK_CONFIRM . '"' ) && false !== strpos( $z3l_html, 'column-date sortable' ) && false !== strpos( $z3l_html, 'column-total sortable' ) );
check( 'A411 list: page size from the screen option, total of the filter', false !== strpos( $z3l_html, '<span class="pages">3/2</span>' ) );
check( 'A411 list: views and the bulk form posting back to the filtered list', false !== strpos( $z3l_html, 'class="subsubsub"' ) && false !== strpos( $z3l_html, 'Zahlung offen' ) && (bool) preg_match( '/<form method="post" action="[^"]*page=wpcap-orders[^"]*event_id=' . $z3l_event . '/', $z3l_html ) );
$_GET = array( 'page' => OrdersPage::SLUG, 's' => 'z3l-nobody-' . uniqid() );
ob_start();
try { OrdersPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$z3l_empty = (string) ob_get_clean();
check( 'A411 list: empty result offers "Filter zurücksetzen"', false !== strpos( $z3l_empty, 'Keine Bestellungen gefunden.' ) && substr_count( $z3l_empty, 'Filter zurücksetzen' ) >= 1 );
$_GET = array();
unset( $GLOBALS['z3l_user_options'] );
