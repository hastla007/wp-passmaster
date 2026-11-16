<?php
/**
 * A292: "alles bereit" needs a published sales page (events shortcode/block); the checklist can
 * create a draft page, links "Auf Website ansehen" and names web scanner and offline PWA; the
 * devices page lists both ways to scan without the app.
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\DevicesPage;
use PassCaptain\Admin\GettingStarted;
use PassCaptain\Admin\Menu;

require_once __DIR__ . '/zz_r6_stubs.inc';
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $post, $wp_error = false ) {
		global $wpdb;
		$GLOBALS['wpcap_test_inserted_posts'][] = $post;
		if ( empty( $wpdb->posts ) ) { return 4711; } // No posts table in this test file's context (e.g. zz_a338).
		$wpdb->insert( $wpdb->posts, array( 'post_title' => $post['post_title'], 'post_content' => $post['post_content'], 'post_type' => $post['post_type'], 'post_status' => $post['post_status'] ) );
		return (int) $wpdb->insert_id;
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $id, $context = 'display' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; } }

global $wpdb;
$p = $wpdb->prefix;
echo "A292: sales page step, view link, web scanner and PWA\n";
$a292_by = static function ( array $items ) { $o = array(); foreach ( $items as $i ) { $o[ $i['key'] ] = $i; } return $o; };
$a292_all = array( 'payment_manual' => true, 'legal_texts' => true, 'require_terms' => true, 'seller' => true, 'events' => 1, 'upcoming_occurrences' => 1, 'ticket_types' => 1, 'published_events' => 1, 'devices' => 1, 'paid_orders' => 1 );

$a292_items = $a292_by( GettingStarted::items( $a292_all ) );
check( 'everything else done but no sales page: step "Verkaufsseite anlegen" open', isset( $a292_items['sales_page'] ) && ! $a292_items['sales_page']['done'] && false !== strpos( $a292_items['sales_page']['description'], '[wpcap_events]' ) );
$GLOBALS['p1u_user_meta'][1][ GettingStarted::META ] = 0;
r6_posts_tables();
$GLOBALS['wpcap_test_transients'] = array();
ob_start(); GettingStarted::render(); $a292_html = ob_get_clean();
check( 'open sales page step offers the draft button and the shortcode', false !== strpos( $a292_html, 'value="' . GettingStarted::PAGE_ACTION . '"' ) && false !== strpos( $a292_html, 'Verkaufsseite als Entwurf anlegen' ) && false !== strpos( $a292_html, '[wpcap_events]' ) );
check( 'create-page handler is registered', isset( Menu::handlers()[ GettingStarted::PAGE_ACTION ] ) );

// Detection: only published pages/posts with the shortcode or block count.
$wpdb->insert( "{$p}posts", array( 'post_title' => 'Entwurf', 'post_content' => '[wpcap_events]', 'post_type' => 'page', 'post_status' => 'draft' ) );
$wpdb->insert( "{$p}posts", array( 'post_title' => 'Über uns', 'post_content' => 'wpcap_events ohne Klammer', 'post_type' => 'page', 'post_status' => 'publish' ) );
check( 'drafts and pages without the shortcode do not count', 0 === GettingStarted::frontend_pages()['sales_page_id'] );
$wpdb->insert( "{$p}posts", array( 'post_title' => 'Programm', 'post_content' => '<!-- wp:wp-pass-captain/events {"event":3} /-->', 'post_type' => 'page', 'post_status' => 'publish' ) );
$a292_block = (int) $wpdb->insert_id;
check( 'result is cached (ten minutes)', 0 === GettingStarted::frontend_pages()['sales_page_id'] );
$GLOBALS['wpcap_test_transients'] = array();
check( 'a published page with the events block counts', $a292_block === GettingStarted::frontend_pages()['sales_page_id'] );
$wpdb->insert( "{$p}posts", array( 'post_title' => 'Einlass', 'post_content' => '[wpcap_scanner]', 'post_type' => 'page', 'post_status' => 'publish' ) );
$a292_scanner = (int) $wpdb->insert_id;
$GLOBALS['wpcap_test_transients'] = array();
$a292_state = GettingStarted::state();
check( 'state() carries sales and scanner page', $a292_block === $a292_state['sales_page_id'] && $a292_scanner === $a292_state['scanner_page_id'] );
$a292_items = $a292_by( GettingStarted::items( array_merge( $a292_all, array( 'devices' => 0, 'sales_page_id' => $a292_block, 'scanner_page_id' => $a292_scanner ) ) ) );
check( 'sales page step done with "Auf Website ansehen" link; a scanner page completes the admission step', $a292_items['sales_page']['done'] && 'https://example.test/?page_id=' . $a292_block === $a292_items['sales_page']['view_url'] && $a292_items['scanner']['done'] && false !== strpos( $a292_items['scanner']['description'], '[wpcap_scanner]' ) );

// "alles bereit" only with everything done, then with a link to the sales page.
add_filter( 'wpcap_getting_started_items', $a292_f = static function ( $items ) { foreach ( $items as &$i ) { if ( 'sales_page' !== $i['key'] ) { $i['done'] = true; } } return $items; } );
$GLOBALS['wpcap_test_transients'] = array( GettingStarted::PAGES_CACHE => array( 'sales_page_id' => 0, 'scanner_page_id' => 0 ) );
ob_start(); GettingStarted::render(); $a292_missing = ob_get_clean();
$GLOBALS['wpcap_test_transients'] = array( GettingStarted::PAGES_CACHE => array( 'sales_page_id' => $a292_block, 'scanner_page_id' => 0 ) );
ob_start(); GettingStarted::render(); $a292_ready = ob_get_clean();
remove_filter( 'wpcap_getting_started_items', $a292_f );
check( 'no "alles bereit" without a sales page', false === strpos( $a292_missing, 'alles bereit' ) );
check( '"alles bereit" links the sales page; done step links "Auf Website ansehen"', false !== strpos( $a292_ready, 'alles bereit' ) && false !== strpos( $a292_ready, 'Verkaufsseite ansehen' ) && false !== strpos( $a292_ready, 'Auf Website ansehen' ) && false === strpos( $a292_ready, GettingStarted::PAGE_ACTION ) );

// Creating the draft page.
$GLOBALS['wpcap_test_denied_caps'] = array( 'publish_pages' );
check( 'creating the page needs publish_pages', is_wp_error( GettingStarted::create_sales_page() ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$_POST = array( 'action' => GettingStarted::PAGE_ACTION, '_wpnonce' => 'n' );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$a292_redirect = '';
try { GettingStarted::handle_create_sales_page(); } catch ( Wpcap_Test_Redirect $e ) { $a292_redirect = $e->getMessage(); }
$_POST = array();
$a292_page = $wpdb->get_row( "SELECT * FROM {$p}posts ORDER BY ID DESC LIMIT 1" );
check( 'handler creates a draft page "Tickets" with the shortcode and opens the editor', in_array( GettingStarted::PAGE_ACTION, $GLOBALS['wpcap_test_nonce_checks'], true ) && 'page' === $a292_page->post_type && 'draft' === $a292_page->post_status && false !== strpos( $a292_page->post_content, '[wpcap_events]' ) && false !== strpos( $a292_redirect, 'post=' . $a292_page->ID . '&action=edit' ) && false === get_transient( GettingStarted::PAGES_CACHE ) );

// Devices page: both ways to scan without the app.
$GLOBALS['wpcap_test_transients'] = array();
ob_start(); DevicesPage::render_web_scanners(); $a292_dev = ob_get_clean();
check( 'devices page names the web scanner shortcode, links its page and the offline PWA', false !== strpos( $a292_dev, '[wpcap_scanner]' ) && false !== strpos( $a292_dev, '?page_id=' . $a292_scanner ) && false !== strpos( $a292_dev, 'assets/offline-scanner/index.html' ) );

r6_posts_tables( false );
$GLOBALS['wpcap_test_transients'] = array();
check( 'without posts table nothing is detected', array( 'sales_page_id' => 0, 'scanner_page_id' => 0 ) === GettingStarted::frontend_pages() );
