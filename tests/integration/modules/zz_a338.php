<?php
/**
 * A338: manual check-in from the admin ticket list (same rules as a scan, scope, idempotency) and a
 * findable web scanner (admin page "Web-Scanner" with link or page creation).
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Menu;
use PassCaptain\Admin\ScannerPage;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id = 0 ) { return 'https://example.test/?page_id=' . (int) $id; } }
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $data, $wp_error = false ) { $GLOBALS['wpcap_test_inserted_posts'][] = $data; return 4711; } }

echo "A338: manual check-in from the admin, web scanner findable\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;

$a338_type = make_type( 10, 10, 0 );
$a338_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a338_type ) );
$a338_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a338_occ ) );
$a338_order = admin_paid_order( $a338_type, 3, 'a338@example.com' );
// The occurrence started ten minutes ago (admission window open).
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'occurrence_id' => $a338_occ ) );
$a338_t = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $a338_order ) );
$a338_status = static fn( $id ) => (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=%d", $id ) );
$a338_rows = static fn( $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=%d AND result='checked_in'", $id ) );

$a338_key = wp_generate_uuid4();
$a338_in = TicketsPage::checkin_ticket( array( 'ticket_id' => $a338_t[0], 'idempotency_key' => $a338_key ) );
$a338_row = $wpdb->get_row( $wpdb->prepare( "SELECT source,user_id FROM {$p}wpcap_checkins WHERE ticket_id=%d ORDER BY checkin_id DESC LIMIT 1", $a338_t[0] ) );
$a338_audit = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='ticket_checked_in' AND object_id=%d ORDER BY audit_id DESC LIMIT 1", $a338_t[0] ) );
check( 'manual check-in: ticket checked in, logged with source admin and the staff user', is_array( $a338_in ) && 'checked_in' === $a338_status( $a338_t[0] ) && $a338_row && 'admin' === $a338_row->source && 1 === (int) $a338_row->user_id );
check( 'manual check-in: audit marks it as manual', false !== strpos( (string) $a338_audit, '"via":"manual"' ) && false !== strpos( (string) $a338_audit, '"source":"admin"' ) );
$a338_again = TicketsPage::checkin_ticket( array( 'ticket_id' => $a338_t[0], 'idempotency_key' => $a338_key ) );
check( 'double submit of the same form: replayed, no second admission', is_array( $a338_again ) && ! empty( $a338_again['replayed'] ) && 1 === $a338_rows( $a338_t[0] ) );
$a338_used = TicketsPage::checkin_ticket( array( 'ticket_id' => $a338_t[0], 'idempotency_key' => wp_generate_uuid4() ) );
check( 'an already checked-in ticket is refused like at the scanner', is_wp_error( $a338_used ) && 409 === $a338_used->get_error_data()['status'] && false !== strpos( $a338_used->get_error_message(), 'bereits verwendet' ) && 1 === $a338_rows( $a338_t[0] ) );

// Same rules as a scan: a cancelled occurrence refuses admission.
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a338_occ ) );
$a338_cancel = TicketsPage::checkin_ticket( array( 'ticket_id' => $a338_t[1], 'idempotency_key' => wp_generate_uuid4() ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'scheduled' ), array( 'occurrence_id' => $a338_occ ) );
check( 'admission rules apply (cancelled occurrence refused, ticket stays valid)', is_wp_error( $a338_cancel ) && 'wpcap_ticket_cancelled_event' === $a338_cancel->get_error_code() && 'valid' === $a338_status( $a338_t[1] ) );

// Capability and event scope.
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
$a338_nocap = TicketsPage::checkin_ticket( array( 'ticket_id' => $a338_t[1] ) );
$a338_die = false;
$_POST = array( 'ticket_id' => $a338_t[1] );
try { TicketsPage::handle_checkin_ticket(); } catch ( Wpcap_Test_Die $e ) { $a338_die = true; } catch ( Wpcap_Test_Redirect $e ) { $a338_die = false; }
$_POST = array();
check( 'without wpcap_checkin: refused (403) before any lookup', is_wp_error( $a338_nocap ) && 403 === $a338_nocap->get_error_data()['status'] && $a338_die && 'valid' === $a338_status( $a338_t[1] ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$GLOBALS['wpcap_test_user_id'] = 93380;
$a338_foreign = TicketsPage::checkin_ticket( array( 'ticket_id' => $a338_t[1] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'staff not assigned to the event: refused (403)', is_wp_error( $a338_foreign ) && 403 === $a338_foreign->get_error_data()['status'] && 'valid' === $a338_status( $a338_t[1] ) );
check( 'admin-post action registered (permission matrix zz_a145 covers it)', isset( Menu::handlers()['wpcap_checkin_ticket'], Menu::handlers()[ ScannerPage::ACTION_CREATE ] ) );

// Ticket list offers the button and the way to the web scanner.
$_GET = array( 'page' => TicketsPage::SLUG, 's' => 'a338@example.com' );
ob_start();
try { TicketsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a338_html = ob_get_clean();
$_GET = array();
check( 'ticket list: "Einchecken" for valid tickets, link "Web-Scanner öffnen"', false !== strpos( $a338_html, 'value="wpcap_checkin_ticket"' ) && false !== strpos( $a338_html, 'Einchecken' ) && false !== strpos( $a338_html, 'page=' . ScannerPage::SLUG ) && false === strpos( $a338_html, 'EXCEPTION' ) );

// Web scanner page: menu entry in the admission area, link to the page, page creation.
check( 'menu: "Web-Scanner" in the admission area for wpcap_checkin', 'admission' === Menu::group_of( ScannerPage::SLUG ) && (bool) array_filter( Menu::entries(), static fn( $e ) => ScannerPage::SLUG === $e[0] && 'wpcap_checkin' === $e[3] ) );
$a338_pages = static function ( $pages ) { return array_merge( $pages, array( array( 'id' => 77, 'title' => 'Einlass', 'url' => 'https://example.test/einlass/' ) ) ); };
add_filter( 'wpcap_scanner_pages', $a338_pages );
ob_start();
ScannerPage::render();
$a338_scanner = ob_get_clean();
$a338_existing = ScannerPage::create_page();
remove_filter( 'wpcap_scanner_pages', $a338_pages );
check( 'scanner page: existing page linked as "Web-Scanner öffnen", no second page created', false !== strpos( $a338_scanner, 'https://example.test/einlass/' ) && false !== strpos( $a338_scanner, 'Web-Scanner öffnen' ) && is_array( $a338_existing ) && false === $a338_existing['created'] && 77 === $a338_existing['page_id'] );
ob_start();
ScannerPage::render();
$a338_none = ob_get_clean();
check( 'scanner page: without a page, creation is offered', false !== strpos( $a338_none, 'value="' . ScannerPage::ACTION_CREATE . '"' ) );
$GLOBALS['wpcap_test_inserted_posts'] = array();
$a338_created = ScannerPage::create_page();
check( 'scanner page: creates a published page with the shortcode', is_array( $a338_created ) && true === $a338_created['created'] && ( ! function_exists( 'wp_insert_post' ) || ! isset( $GLOBALS['wpcap_test_inserted_posts'][0] ) || ( '[wpcap_scanner]' === $GLOBALS['wpcap_test_inserted_posts'][0]['post_content'] && 'publish' === $GLOBALS['wpcap_test_inserted_posts'][0]['post_status'] ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( ScannerPage::PAGE_CAP );
$a338_denied = ScannerPage::create_page();
ob_start();
ScannerPage::render();
$a338_staff = ob_get_clean();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'scanner page: without publish_pages no page is created and no form is offered', is_wp_error( $a338_denied ) && false === strpos( $a338_staff, ScannerPage::ACTION_CREATE ) );
