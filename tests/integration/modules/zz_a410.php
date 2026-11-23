<?php
// phpcs:ignoreFile
/**
 * A410: the waitlist form of a sold-out card names the real offer period (setting offer_hours)
 * instead of "innerhalb der genannten Frist"; its quantity limit follows the chosen type
 * (tests/js/checkout-x3.test.js).
 */
use PassCaptain\Frontend\EventCard;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A410: waitlist offer period on the card\n";
global $wpdb;
$p = $wpdb->prefix;
$a410_type = make_type( 0, 3, 1000 );
$a410_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a410_type" );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'capacity' => 1 ), array( 'ticket_type_id' => $a410_type ) );
$a410_sold = Orders::create( array( 'email' => 'a410@example.com', 'items' => array( array( 'ticket_type_id' => $a410_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a410-' . uniqid() ) );
Orders::flush_availability_cache();
$a410_waitlist = Settings::get( 'waitlist' );
$a410_render = static function () use ( $a410_occ ) { EventCard::reset_form_ids(); return PublicEvents::render_shortcode( array( 'occurrence' => $a410_occ ) ); };
Settings::update( array( 'waitlist' => array_merge( is_array( $a410_waitlist ) ? $a410_waitlist : array(), array( 'offer_hours' => 36 ) ) ) );
$a410_html = $a410_render();
check( 'A410 fixture: the date is sold out and the card shows the waitlist', ! is_wp_error( $a410_sold ) && false !== strpos( $a410_html, '<form class="wpcap-waitlist-form"' ) );
check( 'A410 the card names the offer period of the settings (36 hours)', false !== strpos( $a410_html, 'Du kannst es 36 Stunden lang annehmen, höchstens bis zum Beginn des Termins.' ) && false === strpos( $a410_html, 'genannten Frist' ) );
Settings::update( array( 'waitlist' => array_merge( is_array( $a410_waitlist ) ? $a410_waitlist : array(), array( 'offer_hours' => 1 ) ) ) );
check( 'A410 one hour in the singular', false !== strpos( $a410_render(), 'Du kannst es 1 Stunde lang annehmen' ) );
Settings::update( array( 'waitlist' => is_array( $a410_waitlist ) ? $a410_waitlist : array() ) );
check( 'A410 the quantity field starts with the limit of the selected (first sold-out) type', 1 === preg_match( '#id="wpcap-' . $a410_occ . '-waitlist-qty" name="quantity" type="number" min="1" max="3"#', $a410_html ) && 1 === preg_match( '#<option value="' . $a410_type . '" data-max="3" selected>#', $a410_html ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a410_done' ), array( 'occurrence_id' => $a410_occ ) );
