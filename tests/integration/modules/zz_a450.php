<?php
/**
 * A450: the order and waitlist forms of the event card are POST forms (action "#") whose submit
 * button stays disabled until assets/checkout.js has attached its handlers, so without JavaScript
 * the e-mail address and attendee data never end up in the URL (GET). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\PublicEvents;

if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }

global $wpdb;
$p = $wpdb->prefix;

/** Opening tags of all <form> elements in $html. */
function a450_forms( $html ) {
	preg_match_all( '/<form\b[^>]*>/', $html, $m );
	return $m[0];
}

echo "A450: order form without JavaScript\n";
$a450_type = make_type( 10, 10, 1500 );
$a450_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types t INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=$a450_type" );
$a450_html = PublicEvents::render_shortcode( array( 'event' => $a450_event ) );
$a450_forms = array_values( array_filter( a450_forms( $a450_html ), static fn( $f ) => false !== strpos( $f, 'class="wpcap-checkout-form"' ) ) ); // The card also carries the (hidden) waitlist form since A456.
check( 'order form: method="post" action="#"', 1 === count( $a450_forms ) && false !== strpos( $a450_forms[0], 'class="wpcap-checkout-form"' ) && false !== strpos( $a450_forms[0], 'method="post"' ) && false !== strpos( $a450_forms[0], 'action="#"' ) );
check( 'order form: submit button disabled until the script enables it', 1 === preg_match( '/<button type="submit" class="wp-element-button wpcap-submit" disabled data-wpcap-requires-js>/', $a450_html ) );
check( 'order form: noscript hint', false !== strpos( $a450_html, '<noscript><p class="wpcap-noscript">Zum Bestellen wird JavaScript benötigt.' ) );

echo "A450: waitlist form without JavaScript\n";
$a450_sold = make_type( 1, 10, 0 );
$a450_r = Orders::create( array( 'email' => 'a450@example.com', 'items' => array( array( 'ticket_type_id' => $a450_sold, 'quantity' => 1 ) ), 'idempotency_key' => 'a450-sold' ) );
$a450_sold_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types t INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=$a450_sold" );
$a450_wl_html = PublicEvents::render_shortcode( array( 'event' => $a450_sold_event ) );
$a450_wl = array_values( array_filter( a450_forms( $a450_wl_html ), static fn( $f ) => false !== strpos( $f, 'wpcap-waitlist-form' ) ) );
check( 'sold-out card renders the waitlist form', ! is_wp_error( $a450_r ) && 1 === count( $a450_wl ) );
check( 'waitlist form: method="post" action="#"', isset( $a450_wl[0] ) && false !== strpos( $a450_wl[0], 'method="post"' ) && false !== strpos( $a450_wl[0], 'action="#"' ) );
check( 'waitlist form: submit disabled until the script enables it, noscript hint', 1 === preg_match( '/<button type="submit" class="wp-element-button" disabled data-wpcap-requires-js>Auf die Warteliste setzen<\/button>/', $a450_wl_html ) && false !== strpos( $a450_wl_html, 'Für die Warteliste wird JavaScript benötigt.' ) );
check( 'no form of the event card falls back to GET', ! array_filter( array_merge( a450_forms( $a450_html ), a450_forms( $a450_wl_html ) ), static fn( $f ) => false === strpos( $f, 'method="post"' ) ) );

$a450_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'checkout.js enables [data-wpcap-requires-js] buttons after attaching its submit handlers', false !== strpos( $a450_js, "'.wpcap-checkout-form [data-wpcap-requires-js], .wpcap-waitlist-form [data-wpcap-requires-js]'" ) && strrpos( $a450_js, "addEventListener('submit'" ) < strpos( $a450_js, '[data-wpcap-requires-js]' ) );
