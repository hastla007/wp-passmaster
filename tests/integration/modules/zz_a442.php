<?php
/**
 * A442: the order portal reads the participant answers of all tickets in one query, not per ticket.
 * Included from run.php (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\OrderPortal;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
echo "A442: order portal loads answers in one query\n";

if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1 ) { return $u . '&_wpnonce=n'; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'nonce'; } }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }

$x2_a442_type = make_type( 10, 10, 1000 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $x2_a442_type, 'field_key' => 'shirt', 'label' => 'Shirtgröße', 'field_type' => 'text', 'required' => 0, 'position' => 1, 'status' => 'active' ) );
$x2_a442_field = (int) $wpdb->insert_id;
$x2_a442_order = Orders::create( array( 'email' => 'x2-a442@example.com', 'items' => array( array( 'ticket_type_id' => $x2_a442_type, 'quantity' => 3 ) ), 'idempotency_key' => 'x2-a442-' . uniqid() ) );
Orders::confirm_manual_payment( $x2_a442_order['order_id'] );
foreach ( Orders::tickets_for_order( $x2_a442_order['order_id'] ) as $x2_a442_i => $x2_a442_t ) {
	$wpdb->insert( "{$p}wpcap_participant_answers", array( 'ticket_id' => (int) $x2_a442_t->ticket_id, 'field_id' => $x2_a442_field, 'answer' => 'Größe-X2-' . $x2_a442_i, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
}
$GLOBALS['wpcap_test_query_log'] = array();
try {
	$x2_a442_html = (string) OrderPortal::render_page( Orders::get( $x2_a442_order['order_id'] ), str_repeat( 'a', 64 ) );
} catch ( Throwable $x2_a442_e ) {
	$x2_a442_html = 'EXCEPTION ' . $x2_a442_e->getMessage();
}
$x2_a442_answer_queries = count( array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $q ) => false !== strpos( $q, 'wpcap_participant_answers' ) ) );
unset( $GLOBALS['wpcap_test_query_log'] );
check( 'A442: one answers query for an order with three tickets' . ( 0 === strpos( $x2_a442_html, 'EXCEPTION' ) ? ' – ' . $x2_a442_html : '' ), 1 === $x2_a442_answer_queries );
check( 'A442: every ticket still shows its own answer', false !== strpos( $x2_a442_html, 'Größe-X2-0' ) && false !== strpos( $x2_a442_html, 'Größe-X2-1' ) && false !== strpos( $x2_a442_html, 'Größe-X2-2' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_participant_fields WHERE field_id=$x2_a442_field" );
