<?php
/**
 * A307: the payment return confirmation also appears in themes without wp_body_open (the_content
 * fallback, or in front of the event list), exactly once per request, and offers a safe way to the
 * order portal: the access link is never shown on the page; the keyed return URL lets the buyer have a
 * new link sent to the order's e-mail address (rate-limited). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Payments\ReturnNotice;

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'in_the_loop' ) ) { function in_the_loop() { return ! empty( $GLOBALS['a307_in_loop'] ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'is_main_query' ) ) { function is_main_query() { return true; } }

echo "A307: payment return notice without wp_body_open, safe portal link\n";
$a307_get = $_GET;
$a307_type = make_type( 10, 10, 1500 );
$a307 = Orders::create( array( 'email' => 'anna.a307@example.com', 'items' => array( array( 'ticket_type_id' => $a307_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a307-' . uniqid() ) );
$a307_key = ReturnNotice::key( $a307['public_id'] );
$_GET = array( 'wpcap_payment' => 'pending', 'order' => $a307['public_id'], 'wpcap_rk' => $a307_key );

ReturnNotice::reset();
$GLOBALS['a307_in_loop'] = true;
$a307_content = ReturnNotice::prepend_to_content( '<p>Seiteninhalt</p>' );
check( 'A307 the_content fallback: notice in front of the page content', 0 === strpos( $a307_content, '<div class="wpcap-payment-notice"' ) && false !== strpos( $a307_content, '<p>Seiteninhalt</p>' ) );
check( 'A307 only once per request (second content filter, wp_body_open afterwards)', '<p>x</p>' === ReturnNotice::prepend_to_content( '<p>x</p>' ) && ( static function () { ob_start(); ReturnNotice::render(); return '' === ob_get_clean(); } )() );
ReturnNotice::reset();
ob_start(); ReturnNotice::render(); $a307_body = (string) ob_get_clean();
check( 'A307 with wp_body_open the content filter adds nothing', false !== strpos( $a307_body, 'wpcap-payment-notice' ) && '<p>y</p>' === ReturnNotice::prepend_to_content( '<p>y</p>' ) );
ReturnNotice::reset();
$GLOBALS['a307_in_loop'] = false;
check( 'A307 not outside the main loop (widgets, excerpts)', '<p>z</p>' === ReturnNotice::prepend_to_content( '<p>z</p>' ) );
$a307_list = PassCaptain\PublicEvents::render_shortcode( array( 'event' => 999999 ) );
check( 'A307 fallback in front of the event list ([wpcap_events] / block)', false !== strpos( $a307_list, 'wpcap-payment-notice' ) );
check( 'A307 the filter is registered', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Payments/ReturnNotice.php' ), "add_filter( 'the_content', array( __CLASS__, 'prepend_to_content' ), 5 )" ) );

// Portal link.
$a307_html = ReturnNotice::html( 'pending', $a307['public_id'], $a307_key );
check( 'A307 the confirmation explains the order portal and offers to resend the link', false !== strpos( $a307_html, 'value="portal_link"' ) && false !== strpos( $a307_html, 'name="wpcap_rk" value="' . $a307_key . '"' ) );
check( 'A307 the access token itself is never on the page', false === strpos( $a307_html, 'wpcap_access' ) );
check( 'A307 without the key: no portal form', false === strpos( ReturnNotice::html( 'pending', $a307['public_id'], str_repeat( '0', 32 ) ), 'portal_link' ) );
$a307_mails = count( $GLOBALS['wpcap_test_mails'] );
$a307_hash = $wpdb->get_var( "SELECT access_token_hash FROM {$p}wpcap_orders WHERE order_id=" . (int) $a307['order_id'] );
$a307_target = ReturnNotice::perform_action( array( 'order' => $a307['public_id'], 'wpcap_rk' => $a307_key, 'decision' => 'portal_link', 'state' => 'pending', 'back' => 'https://example.test/konzert/' ) );
$a307_mail = $GLOBALS['wpcap_test_mails'][ $a307_mails ] ?? array();
check( 'A307 "resend" mails a new access link to the order address and returns to the page', count( $GLOBALS['wpcap_test_mails'] ) === $a307_mails + 1 && 'anna.a307@example.com' === ( $a307_mail['to'] ?? '' ) && false !== strpos( (string) ( $a307_mail['body'] ?? '' ), 'wpcap_access' ) && 0 === strpos( $a307_target, 'https://example.test/konzert/?wpcap_payment=pending&order=' . $a307['public_id'] ) && false !== strpos( $a307_target, 'wpcap_portal=sent' ) );
check( 'A307 the previous link is replaced (rotated)', $a307_hash !== $wpdb->get_var( "SELECT access_token_hash FROM {$p}wpcap_orders WHERE order_id=" . (int) $a307['order_id'] ) );
check( 'A307 the page then confirms the masked address', false !== strpos( ReturnNotice::html( 'pending', $a307['public_id'], $a307_key, 'sent' ), 'a***@example.com' ) );
ReturnNotice::perform_action( array( 'order' => $a307['public_id'], 'wpcap_rk' => $a307_key, 'decision' => 'portal_link', 'state' => 'pending', 'back' => 'https://example.test/' ) );
$a307_third = ReturnNotice::perform_action( array( 'order' => $a307['public_id'], 'wpcap_rk' => $a307_key, 'decision' => 'portal_link', 'state' => 'pending', 'back' => 'https://example.test/' ) );
check( 'A307 resending is rate-limited per order', false !== strpos( $a307_third, 'wpcap_portal=limited' ) && count( $GLOBALS['wpcap_test_mails'] ) === $a307_mails + 2 );
$a307_wrong = ReturnNotice::perform_action( array( 'order' => $a307['public_id'], 'wpcap_rk' => str_repeat( 'a', 32 ), 'decision' => 'portal_link', 'state' => 'pending', 'back' => 'https://example.test/' ) );
check( 'A307 a wrong key sends nothing', false === strpos( $a307_wrong, 'wpcap_portal' ) && count( $GLOBALS['wpcap_test_mails'] ) === $a307_mails + 2 );

$_GET = $a307_get;
ReturnNotice::reset();
unset( $GLOBALS['a307_in_loop'] );
Orders::cancel_order( $a307['order_id'], 'a307_cleanup' );
