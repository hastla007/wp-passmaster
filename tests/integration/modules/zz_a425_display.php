<?php
/**
 * A425, owner decision 23.11.2026 (1): price display (DE PAngV § 3, AT PrAG, EU consumer rights). A
 * per-ticket service fee is part of every price a buyer sees before buying (event card, card header,
 * live state, 409 price messages, REST /events, JSON-LD) with "inkl. … Servicegebühr"; a per-order fee is
 * shown next to the prices as "zzgl. … Bearbeitungsgebühr je Bestellung". The price the checkout compares
 * (data-price, expected_price_minor) stays the ticket price.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Frontend\EventCard;
use PassCaptain\Frontend\Format;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A425: service fee in the shown prices\n";
global $wpdb;
$p = $wpdb->prefix;
$fd_saved = Settings::all();
Settings::update( array( 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200 ) );

$fd_type = make_type( 100, 10, 2500 );
$fd_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $fd_type ) );
$fd_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $fd_occ ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $fd_occ, 'name' => 'Freikarte', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 5, 'status' => 'active' ) );
$fd_free = (int) $wpdb->insert_id;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id SET e.name='FD Event',o.starts_at=%s WHERE o.occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 180 ), $fd_occ ) ); // First upcoming date (REST lists 100).
Orders::flush_availability_cache();
EventCard::reset_form_ids();

$fd_html = PublicEvents::render_shortcode( array( 'occurrence' => $fd_occ ) );
$fd_shown = Format::money( 2650, 'EUR' );
check( 'A425 display: the type price includes the per-ticket fee, with "inkl. … Servicegebühr"', false !== strpos( $fd_html, '<span class="wpcap-type-price">' . $fd_shown . '</span>' ) && false !== strpos( $fd_html, 'inkl. ' . Format::money( 150, 'EUR' ) . ' Servicegebühr' ) && false === strpos( $fd_html, 'zzgl. ' . Format::money( 150, 'EUR' ) ) );
check( 'A425 display: the per-order fee stands next to the price (header and ticket list)', false !== strpos( $fd_html, 'class="wpcap-fact-order-fee"' ) && 2 === substr_count( $fd_html, 'zzgl. ' . Format::money( 200, 'EUR' ) . ' Bearbeitungsgebühr je Bestellung' ) && false !== strpos( $fd_html, '<p class="wpcap-order-fee">' ) );
check( 'A425 display: the checkout still compares the ticket price (data-price) and adds the fee from data-fee', false !== strpos( $fd_html, 'data-price="2500" data-fee="150"' ) && false !== strpos( $fd_html, 'data-order-fee="200"' ) );
check( 'A425 display: a free type stays "kostenlos" without fee', false !== strpos( $fd_html, '<span class="wpcap-type-price">kostenlos</span>' ) && false === strpos( $fd_html, 'data-price="0" data-fee' ) );

$fd_live = (array) PublicEvents::live_state( array( $fd_occ ) )['occurrences'];
$fd_state = $fd_live[ $fd_occ ] ?? array();
check( 'A425 display: the live state shows the price incl. fee and keeps price_minor as the ticket price', $fd_shown === ( $fd_state['types'][ $fd_type ]['price'] ?? '' ) && 2500 === ( $fd_state['types'][ $fd_type ]['price_minor'] ?? null ) && 150 === ( $fd_state['types'][ $fd_type ]['service_fee_minor'] ?? null ) && 'kostenlos' === ( $fd_state['types'][ $fd_free ]['price'] ?? '' ) );

$fd_occ_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $fd_occ ) );
$fd_shown_state = Orders::shown_state( $fd_occ_row );
check( 'A425 display: the 409 state (A560) keeps prices as ticket prices and formats them incl. fee', 2500 === $fd_shown_state['prices'][ $fd_type ] && $fd_shown === $fd_shown_state['prices_formatted'][ $fd_type ] );

// A576: price above the shown one → the message names the new price incl. fee.
$fd_changed = Orders::create( array( 'email' => 'fd@example.com', 'items' => array( array( 'ticket_type_id' => $fd_type, 'quantity' => 1 ) ), 'expected_prices' => array( $fd_type => 2000 ), 'idempotency_key' => 'fd-' . wp_generate_uuid4() ) );
check( 'A425 display: price_changed names the new price incl. the per-ticket fee', is_wp_error( $fd_changed ) && $fd_shown === ( $fd_changed->get_error_data()['prices'][ $fd_type ]['formatted'] ?? '' ) && false !== strpos( $fd_changed->get_error_message(), $fd_shown ) );

$fd_rest = PassCaptain\Rest\Controller::events( new WP_REST_Request( 'GET', '/pass-captain/v1/events' ) );
$fd_rest = $fd_rest instanceof WP_REST_Response ? $fd_rest->get_data() : $fd_rest;
$fd_ticket = null;
foreach ( (array) $fd_rest as $fd_e ) {
	if ( (int) ( $fd_e['occurrence_id'] ?? 0 ) !== $fd_occ ) { continue; }
	foreach ( $fd_e['tickets'] as $fd_t ) { if ( $fd_type === $fd_t['ticket_type_id'] ) { $fd_ticket = $fd_t; } }
}
check( 'A425 display: REST /events adds service_fee_minor, display_price_minor and order_fee_minor', is_array( $fd_ticket ) && 2500 === $fd_ticket['price_minor'] && 150 === $fd_ticket['service_fee_minor'] && 2650 === $fd_ticket['display_price_minor'] && 200 === $fd_ticket['order_fee_minor'] );
check( 'A425 display: the public contract is 1.2', '1.2' === PassCaptain\Contracts::version( 'public' ) );

$fd_ld = PassCaptain\Frontend\EventPage::json_ld( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_events WHERE event_id=%d", $fd_event ) ) );
$fd_offer_prices = array();
foreach ( (array) ( $fd_ld[0]['offers'] ?? array() ) as $fd_offer ) { $fd_offer_prices[ $fd_offer['name'] ] = $fd_offer['price']; }
check( 'A425 display: JSON-LD offers carry the total price incl. the per-ticket fee', '26.50' === (string) ( $fd_offer_prices['Standard'] ?? '' ) && in_array( (string) ( $fd_offer_prices['Freikarte'] ?? '' ), array( '0', '0.00' ), true ) );

check( 'A425 display: price_from() and shown_unit() include the fee, free types stay free', $fd_shown === EventCard::price_from( array( (object) array( 'price_minor' => 2500, 'currency' => 'EUR', 'available' => null ) ) ) && 2650 === ServiceFee::shown_unit( (object) array( 'price_minor' => 2500 ) ) && 0 === ServiceFee::shown_unit( (object) array( 'price_minor' => 0 ) ) );

// Display filter (e.g. a site that charges a different fee per event through wpcap_service_fee).
$fd_filter = static function ( $fee ) { $fee['ticket_minor'] = 99; $fee['order_minor'] = 0; return $fee; };
add_filter( 'wpcap_service_fee_shown', $fd_filter );
EventCard::reset_form_ids();
$fd_filtered = PublicEvents::render_shortcode( array( 'occurrence' => $fd_occ ) );
remove_filter( 'wpcap_service_fee_shown', $fd_filter );
check( 'A425 display: wpcap_service_fee_shown changes the shown fees', false !== strpos( $fd_filtered, '<span class="wpcap-type-price">' . Format::money( 2599, 'EUR' ) . '</span>' ) && false === strpos( $fd_filtered, 'Bearbeitungsgebühr je Bestellung' ) );

// Without a fee nothing changes.
Settings::update( array( 'service_fee_ticket_minor' => 0, 'service_fee_order_minor' => 0 ) );
EventCard::reset_form_ids();
$fd_plain = PublicEvents::render_shortcode( array( 'occurrence' => $fd_occ ) );
check( 'A425 display: without a fee the card shows the plain ticket price and no fee note', false !== strpos( $fd_plain, '<span class="wpcap-type-price">' . Format::money( 2500, 'EUR' ) . '</span>' ) && false === strpos( $fd_plain, 'Servicegebühr' ) && false === strpos( $fd_plain, 'Bearbeitungsgebühr' ) );

$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='fd_done' WHERE occurrence_id=$fd_occ" );
update_option( Settings::OPTION, $fd_saved );
Settings::flush();
