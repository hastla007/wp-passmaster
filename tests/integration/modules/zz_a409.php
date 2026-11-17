<?php
// phpcs:ignoreFile
/**
 * A409: free tickets are no "Zahlungspflichtig bestellen" with "0,00 €": a card with only free
 * types says "Kostenlos anmelden" and "kostenlos" (type, header price, live state); a card with
 * paid types keeps the legally required "Zahlungspflichtig bestellen" (checkout.js switches with
 * the selection, tests/js/checkout-x3.test.js).
 */
use PassCaptain\Frontend\EventCard;
use PassCaptain\Frontend\Format;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A409: free tickets\n";
global $wpdb;
$p = $wpdb->prefix;
$a409_free = make_type( 20, 5, 0 );
$a409_free_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a409_free" );
$a409_paid = make_type( 20, 5, 1500 );
$a409_mixed_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a409_paid" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a409_mixed_occ, 'name' => 'Freikarte', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 5, 'status' => 'active' ) );
Orders::flush_availability_cache();
EventCard::reset_form_ids();
$a409_free_html = PublicEvents::render_shortcode( array( 'occurrence' => $a409_free_occ ) );
$a409_mixed_html = PublicEvents::render_shortcode( array( 'occurrence' => $a409_mixed_occ ) );
check( 'A409 only free types: the button says "Kostenlos anmelden"', 1 === preg_match( '#<button type="submit" class="wp-element-button wpcap-submit"[^>]*data-label-paid="Zahlungspflichtig bestellen" data-label-free="Kostenlos anmelden">Kostenlos anmelden</button>#', $a409_free_html ) );
check( 'A409 only free types: "kostenlos" next to the type and in the header, no "0,00 €"', false !== strpos( $a409_free_html, '<span class="wpcap-type-price">kostenlos</span>' ) && false !== strpos( $a409_free_html, '<div class="wpcap-fact-price"><dt class="wpcap-sr-only">Preis</dt><dd>kostenlos</dd>' ) && ! preg_match( '/(?<![\d.])0,00/', $a409_free_html ) );
check( 'A409 a card with paid types keeps "Zahlungspflichtig bestellen"', 1 === preg_match( '#data-label-free="Kostenlos anmelden">Zahlungspflichtig bestellen</button>#', $a409_mixed_html ) && false !== strpos( $a409_mixed_html, '<span class="wpcap-type-price">kostenlos</span>' ) );
$a409_live = PublicEvents::live_state( array( $a409_free_occ ) );
$a409_state = (array) $a409_live['occurrences'];
check( 'A409 the live state names the free type and the card price "kostenlos"', 'kostenlos' === ( $a409_state[ $a409_free_occ ]['types'][ $a409_free ]['price'] ?? '' ) && 'kostenlos' === ( $a409_state[ $a409_free_occ ]['price'] ?? '' ) && 0 === ( $a409_state[ $a409_free_occ ]['types'][ $a409_free ]['price_minor'] ?? null ) );
check( 'A409 Format::price(): "kostenlos" for 0, money otherwise; all_free() needs at least one type', 'kostenlos' === Format::price( 0, 'EUR' ) && Format::money( 1500, 'EUR' ) === Format::price( 1500, 'EUR' ) && ! Format::all_free( array() ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='a409_done' WHERE occurrence_id IN ($a409_free_occ,$a409_mixed_occ)" );
