<?php
// phpcs:ignoreFile
/**
 * A602: on narrow screens the sum stands next to the order button (.wpcap-submit-bar, sticky at the
 * bottom, filled by checkout.js – tests/js/checkout-x3.test.js); single-line street fields use
 * autocomplete="address-line1" instead of the multi-line "street-address".
 */
use PassCaptain\Frontend\EventCard;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A602: sum at the order button, address autofill\n";
global $wpdb;
$a602_type = make_type( 10, 5, 1800 );
$a602_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=$a602_type" );
Orders::flush_availability_cache();
EventCard::reset_form_ids();
$a602_html = PublicEvents::render_shortcode( array( 'occurrence' => $a602_occ ) );
check( 'A602 the order button sits in .wpcap-submit-bar with a (visual) sum before it', 1 === preg_match( '#<div class="wpcap-submit-bar"><p class="wpcap-submit-total" aria-hidden="true"></p>\s*<button type="submit" class="wp-element-button wpcap-submit"#', $a602_html ) );
check( 'A602 the live estimate stays for screen readers', false !== strpos( $a602_html, '<p class="wpcap-estimate" aria-live="polite"></p>' ) );
check( 'A602 the billing street field uses address-line1', false !== strpos( $a602_html, 'name="billing_street" type="text" autocomplete="address-line1"' ) && false === strpos( $a602_html, 'street-address' ) );
$a602_css = (string) file_get_contents( WPCAP_PATH . 'assets/public.css' );
check( 'A602 on narrow screens the bar sticks to the bottom with its own text/background pair', false !== strpos( $a602_css, '@media (max-width:600px){.wpcap-submit-bar{position:sticky;bottom:0;' ) && false !== strpos( $a602_css, 'color:var(--wpcap-bar-fg);background:var(--wpcap-bar-bg)' ) );
$a602_street = array();
foreach ( array( 'src/OrderPortal.php', 'src/Billing.php', 'src/Frontend/ServiceBooking.php', 'templates/event-card.php' ) as $a602_file ) {
	if ( false !== strpos( (string) file_get_contents( WPCAP_PATH . $a602_file ), "'street-address'" ) ) { $a602_street[] = $a602_file; }
}
check( 'A602 no single-line street field uses street-address (portal, admin, service booking, card)' . ( $a602_street ? ' – ' . implode( ', ', $a602_street ) : '' ), ! $a602_street );
$wpdb->update( "{$wpdb->prefix}wpcap_occurrences", array( 'status' => 'a602_done' ), array( 'occurrence_id' => $a602_occ ) );
