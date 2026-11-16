<?php
/**
 * A283/A508: personal pages are never stored by page caches or CDNs. Ticket page, order portal,
 * customer invoice (HTML/PDF), waitlist offer and unsubscribe pages set DONOTCACHEPAGE and send
 * nocache_headers() before any output – also in their 404/410 branches, which end the request with
 * exit (checked in a child process: the shutdown function reports what was set). The payment return
 * notice and the web scanner shortcode page are detected on template_redirect, before the theme
 * sends headers; their renderers set DONOTCACHEPAGE as well.
 *
 * Child mode: php zz_a283_a508.php --wpp-a283-child <scenario>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--wpp-a283-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	$GLOBALS['wpa283_nocache'] = 0;
	$GLOBALS['wpa283_contexts'] = array();
	$GLOBALS['wpa283_status'] = 0;
	$GLOBALS['wpa283_qv'] = array();
	function get_query_var( $k, $d = '' ) { return $GLOBALS['wpa283_qv'][ $k ] ?? $d; }
	function status_header( $code ) { $GLOBALS['wpa283_status'] = (int) $code; }
	function nocache_headers() { $GLOBALS['wpa283_nocache']++; }
	function bloginfo( $k = '' ) { echo 'UTF-8'; }
	function esc_url( $u ) { return (string) $u; }
	function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
	function wp_create_nonce( $a = -1 ) { return 'nonce'; }
	function esc_html_x( $t, $c, $d = null ) { return esc_html( $t ); }
	add_action( 'wpcap_page_no_store', static function ( $context ) { $GLOBALS['wpa283_contexts'][] = $context; } );
	register_shutdown_function( static function () {
		echo "\nWPA283:" . json_encode( array( 'donotcache' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'nocache' => $GLOBALS['wpa283_nocache'], 'contexts' => $GLOBALS['wpa283_contexts'], 'status' => $GLOBALS['wpa283_status'] ) ) . "\n";
	} );
	$hex = str_repeat( 'ab', 32 );
	switch ( $argv[2] ?? '' ) {
		case 'ticket_short':
			$GLOBALS['wpa283_qv']['wpcap_ticket'] = 'abc';
			PassCaptain\TicketPage::maybe_render();
			break;
		case 'ticket_unknown':
			$GLOBALS['wpa283_qv']['wpcap_ticket'] = $hex;
			PassCaptain\TicketPage::maybe_render();
			break;
		case 'portal_unknown':
			$GLOBALS['wpa283_qv'] = array( 'wpcap_order' => 'doesnotexist', 'wpcap_access' => $hex );
			PassCaptain\OrderPortal::maybe_render();
			break;
		case 'invoice_unknown':
			$_GET = array( 'wpcap_invoice' => 'doesnotexist', 'wpcap_access' => $hex );
			PassCaptain\Invoices::maybe_render_customer_invoice();
			break;
		case 'invoice_pdf_unknown':
			$_GET = array( 'wpcap_invoice_pdf' => 'doesnotexist', 'wpcap_access' => $hex );
			PassCaptain\Printing\InvoicePdf::maybe_customer_download();
			break;
		case 'waitlist_offer_unknown':
			$_GET = array( 'wpcap_waitlist_accept' => '987654321', 'token' => $hex );
			PassCaptain\Waitlists::maybe_render_offer_page();
			break;
		case 'waitlist_unsubscribe_unknown':
			$_GET = array( 'wpcap_waitlist_unsubscribe' => '987654321', 'token' => $hex );
			PassCaptain\Waitlists::maybe_render_unsubscribe_page();
			break;
	}
	echo 'NOEXIT';
	exit;
}

use PassCaptain\PageCache;

if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A283/A508: personal pages are never cached\n";

/** Runs one exit scenario in a child process; returns the shutdown report. */
function wpa283_child( $scenario ) {
	$cmd = 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --wpp-a283-child ' . escapeshellarg( $scenario ) . ' 2>&1';
	$out = (string) shell_exec( $cmd );
	if ( ! preg_match( '/WPA283:(\{.*\})/', $out, $m ) ) { return array( 'raw' => substr( $out, 0, 300 ) ); }
	return json_decode( $m[1], true ) + array( 'exited_early' => false === strpos( $out, 'NOEXIT' ) );
}

$expect = array(
	'ticket_short' => array( 'ticket', 404 ),
	'ticket_unknown' => array( 'ticket', 404 ),
	'portal_unknown' => array( 'portal', 404 ),
	'invoice_unknown' => array( 'invoice', 404 ),
	'invoice_pdf_unknown' => array( 'invoice_pdf', 404 ),
	'waitlist_offer_unknown' => array( 'waitlist_offer', 410 ),
	'waitlist_unsubscribe_unknown' => array( 'waitlist_unsubscribe', 410 ),
);
foreach ( $expect as $scenario => list( $context, $status ) ) {
	$r = wpa283_child( $scenario );
	check(
		"A283 $scenario: error answer ($status) sets DONOTCACHEPAGE and nocache_headers() before exit " . json_encode( $r ),
		! empty( $r['donotcache'] ) && ( $r['nocache'] ?? 0 ) >= 1 && in_array( $context, (array) ( $r['contexts'] ?? array() ), true ) && $status === (int) ( $r['status'] ?? 0 ) && ! empty( $r['exited_early'] )
	);
}

// Request detection on template_redirect (priority 0), before any theme output.
check( 'A283 capability parameters are recognised', 'ticket' === PageCache::no_store_context( array( 'wpcap_ticket' => 'x' ) )
	&& 'portal' === PageCache::no_store_context( array( 'wpcap_order' => 'x', 'wpcap_access' => 'y' ) )
	&& 'waitlist_offer' === PageCache::no_store_context( array( 'wpcap_waitlist_accept' => '3', 'token' => 'y' ) )
	&& 'waitlist_unsubscribe' === PageCache::no_store_context( array( 'wpcap_waitlist_unsubscribe' => '3' ) )
	&& 'payment_return' === PageCache::no_store_context( array( 'wpcap_payment' => 'success', 'order' => 'x' ) )
	&& 'payment_return' === PageCache::no_store_context( array( 'wpcap_paypal_return' => '1' ) )
	&& 'invoice_pdf' === PageCache::no_store_context( array( 'wpcap_invoice_pdf' => 'x' ) ) );
check( 'A283 ordinary pages stay cacheable', '' === PageCache::no_store_context( array( 'p' => '12' ), 'Hallo [wpcap_events]' ) && '' === PageCache::no_store_context( array( 'wpcap_payment' => array( 'x' ) ) ) );
check( 'A508 a post with the scanner shortcode is not cacheable', 'scanner' === PageCache::no_store_context( array(), '<p>Einlass</p>[wp_pass_captain_scanner]' ) && 'scanner' === PageCache::no_store_context( array(), '[wpcap_scanner]' ) );
/** Priority a callback is registered with (null = not registered). */
function wpa283_priority( $hook, $callback ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() as $priority => $callbacks ) {
		foreach ( $callbacks as $entry ) { if ( $callback === $entry[0] ) { return (int) $priority; } }
	}
	return null;
}
if ( null === wpa283_priority( 'template_redirect', array( PageCache::class, 'maybe_no_store_request' ) ) ) { PageCache::register(); }
$wpa283_prio = wpa283_priority( 'template_redirect', array( PageCache::class, 'maybe_no_store_request' ) );
check( 'A283 detection runs on template_redirect priority 0 and the plugin registers it', 0 === $wpa283_prio && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), 'PageCache::register();' ) );

$wpa283_contexts = array();
$wpa283_listener = static function ( $context ) use ( &$wpa283_contexts ) { $wpa283_contexts[] = $context; };
add_action( 'wpcap_page_no_store', $wpa283_listener );
$wpa283_get = $_GET;
$_GET = array( 'wpcap_payment' => 'pending', 'order' => 'abc' );
PageCache::maybe_no_store_request();
ob_start();
PassCaptain\Payments\ReturnNotice::render();
$wpa283_notice = ob_get_clean();
check( 'A283 payment return: no_store on template_redirect and in the notice', array( 'payment_return', 'payment_return' ) === $wpa283_contexts && false !== strpos( $wpa283_notice, 'wpcap-payment-notice' ) );
$_GET = $wpa283_get;

$wpa283_contexts = array();
$wpa283_user = $GLOBALS['wpcap_test_user_id'] ?? 1;
$GLOBALS['wpcap_test_user_id'] = 0;
$wpa283_html = PassCaptain\PublicEvents::render_scanner_shortcode();
$GLOBALS['wpcap_test_user_id'] = $wpa283_user;
check( 'A508 scanner shortcode marks the page as not cacheable (also the login notice)', array( 'scanner' ) === $wpa283_contexts && defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && false !== strpos( $wpa283_html, 'Anmeldung' ) );
remove_action( 'wpcap_page_no_store', $wpa283_listener );
