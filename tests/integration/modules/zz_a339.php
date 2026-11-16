<?php
/**
 * A339: rebooking a ticket to another date no longer needs the hidden way via the order detail –
 * the exchange page has a ticket search and the ticket list offers "Umbuchen" per valid ticket.
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\TicketsPage;
use PassCaptain\Exchange\AdminPage as ExchangeAdmin;
use PassCaptain\Frontend\Format;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

echo "A339: ticket search on the exchange page and \"Umbuchen\" in the ticket list\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

$a339_type = make_type( 10, 10, 1500 );
$a339_order = admin_paid_order( $a339_type, 2, 'a339-umbuchen@example.com' );
$a339_ref = Format::order_reference( Orders::get( $a339_order )->public_id );
$a339_ids = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $a339_order ) );
$a339_found = array_map( static fn( $t ) => (int) $t->ticket_id, ExchangeAdmin::find_tickets( $a339_ref ) );
sort( $a339_found );
check( 'exchange search: short order number finds the order\'s valid tickets', $a339_ids === $a339_found );
check( 'exchange search: e-mail part works, empty search finds nothing', 2 === count( ExchangeAdmin::find_tickets( 'a339-umbuchen@' ) ) && array() === ExchangeAdmin::find_tickets( '' ) );
// Checked-in and refunded tickets are not offered.
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'checked_in' ), array( 'ticket_id' => $a339_ids[1] ) );
check( 'exchange search: only valid tickets', array( $a339_ids[0] ) === array_map( static fn( $t ) => (int) $t->ticket_id, ExchangeAdmin::find_tickets( $a339_ref ) ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => $a339_ids[1] ) );
// Staff without access to the event see nothing.
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$GLOBALS['wpcap_test_user_id'] = 93390;
$a339_foreign = ExchangeAdmin::find_tickets( $a339_ref );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$a339_nocap = ExchangeAdmin::find_tickets( $a339_ref );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'exchange search: scoped (foreign staff and users without the capability find nothing)', array() === $a339_foreign && array() === $a339_nocap );

// The page renders the search and links each hit to the exchange form.
$_GET = array( 'page' => ExchangeAdmin::SLUG, 'ticket_search' => $a339_ref );
ob_start();
try { ExchangeAdmin::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a339_html = ob_get_clean();
$_GET = array( 'page' => ExchangeAdmin::SLUG );
ob_start();
try { ExchangeAdmin::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a339_empty = ob_get_clean();
$_GET = array();
check( 'exchange page: search field and "Umbuchen" link per hit', false !== strpos( $a339_html, 'name="ticket_search"' ) && false !== strpos( $a339_html, 'ticket_id=' . $a339_ids[0] ) && false !== strpos( $a339_html, 'Umbuchen' ) && false === strpos( $a339_html, 'EXCEPTION' ) );
check( 'exchange page without search: search form and a hint', false !== strpos( $a339_empty, 'name="ticket_search"' ) && false !== strpos( $a339_empty, 'Ticketliste' ) && false === strpos( $a339_empty, 'EXCEPTION' ) );

// Ticket list: "Umbuchen" next to the other actions of a valid ticket.
$_GET = array( 'page' => TicketsPage::SLUG, 's' => $a339_ref );
ob_start();
try { TicketsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a339_list = ob_get_clean();
$_GET = array();
check( 'ticket list: "Umbuchen" links to the exchange form of the ticket', false !== strpos( $a339_list, esc_url( ExchangeAdmin::url( array( 'ticket_id' => $a339_ids[0] ) ) ) ) && false !== strpos( $a339_list, 'Umbuchen' ) );
