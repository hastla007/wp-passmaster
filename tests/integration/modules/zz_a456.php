<?php
/**
 * A456: availability and purchase state lived only in the (cacheable) card HTML: a cached card kept
 * showing "sold out" or "available", without the waitlist form when a type sold out later, with old
 * seats-left texts and payment methods. The card now always contains the complete order form, the
 * quantity field of every type, both notes and the waitlist form (hidden where they do not apply),
 * and GET /checkout/live (no-store, read-only) gives the current state the checkout script switches
 * them with. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A456: event cards from a page cache load their live purchase state\n";
global $wpdb;
$p = $wpdb->prefix;
$a456_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'manual_instructions' => 'Bitte überweisen.', 'require_terms' => 0 ) );
$a456_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A456 Konzert', 'slug' => 'a456-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a456_now, 'updated_at' => $a456_now ) );
$a456_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a456_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
$a456_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a456_occ, 'name' => 'Sitzplatz', 'price_minor' => 3000, 'currency' => 'EUR', 'capacity' => 1, 'max_per_order' => 4, 'status' => 'active' ) );
$a456_seat = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a456_occ, 'name' => 'Stehplatz', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 2, 'max_per_order' => 6, 'status' => 'active' ) );
$a456_stand = (int) $wpdb->insert_id;
$a456_render = static function () use ( $a456_event ) { Orders::flush_availability_cache(); return PublicEvents::render_shortcode( array( 'event' => $a456_event ) ); };
$a456_buy = static function ( $type, $qty ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => 'a456-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'a456-' . uniqid( '', true ) ) );
	if ( ! is_wp_error( $r ) ) { Orders::confirm_external_payment( (int) $r['order_id'], 'a456' ); }
	Orders::flush_availability_cache();
	return $r;
};

// The card as a page cache stores it: everything bookable.
$a456_cached = $a456_render();
check( 'A456: bookable card: order form visible, notes hidden', 1 === preg_match( '/<div class="wpcap-buy">/', $a456_cached ) && 1 === preg_match( '/class="wpcap-soldout-note" hidden>/', $a456_cached ) && 1 === preg_match( '/class="wpcap-no-payment" role="status" hidden>/', $a456_cached ) );
check( 'A456: the waitlist form is part of the card even while nothing is sold out (hidden)', 1 === preg_match( '/<form class="wpcap-waitlist-form"[^>]*data-occurrence="' . $a456_occ . '"[^>]* novalidate hidden>/', $a456_cached ) );
check( 'A456: every type has its quantity field, price span and (hidden) sold-out label', 2 === substr_count( $a456_cached, 'class="wpcap-qty-box">' ) && 2 === substr_count( $a456_cached, '<span class="wpcap-soldout" hidden>' ) && 2 === substr_count( $a456_cached, 'class="wpcap-type-price"' ) && false !== strpos( $a456_cached, 'data-type="' . $a456_seat . '"' ) );
check( 'A456: payment methods sit in a slot the script can rebuild', false !== strpos( $a456_cached, '<div class="wpcap-provider-slot">' ) );

$a456_live = static function () use ( $a456_occ ) { Orders::flush_availability_cache(); return PublicEvents::live_state( array( $a456_occ ) ); };
$a456_state = (array) $a456_live()['occurrences'];
$a456_s = $a456_state[ $a456_occ ] ?? array();
check( 'A456: live state of the bookable date: on sale, quantity limits, prices, payment methods, no waitlist', true === ( $a456_s['on_sale'] ?? null ) && 1 === $a456_s['types'][ $a456_seat ]['max'] && 2 === $a456_s['types'][ $a456_stand ]['max'] && 3000 === $a456_s['types'][ $a456_seat ]['price_minor'] && '30,00 €' === $a456_s['types'][ $a456_seat ]['price'] && array( 'manual' ) === array_column( $a456_s['providers'], 'id' ) && false === $a456_s['waitlist'] && false === $a456_s['all_soldout'] );
check( 'A456: header facts in the live state (seats left, price line, badges without "Nächster Termin")', 'Nur noch 3 Plätze frei' === $a456_s['availability'] && 'ab 20,00 €' === $a456_s['price'] && ! in_array( 'next', array_column( $a456_s['badges'], 'key' ), true ) && in_array( 'few_left', array_column( $a456_s['badges'], 'key' ), true ) );

// The seat type sells out after the page was cached.
$a456_buy( $a456_seat, 1 );
$a456_s = ( (array) $a456_live()['occurrences'] )[ $a456_occ ];
check( 'A456: after selling out a type, the live state closes it and offers its waitlist', 0 === $a456_s['types'][ $a456_seat ]['max'] && 0 === $a456_s['types'][ $a456_seat ]['available'] && true === $a456_s['waitlist'] && array( $a456_seat ) === $a456_s['waitlist_types'] && 'Nur noch 2 Plätze frei' === $a456_s['availability'] );
$a456_now_html = $a456_render();
check( 'A456: a fresh card shows the sold-out type with its (disabled) field kept for later', 1 === preg_match( '/data-type="' . $a456_seat . '">.*?<span class="wpcap-soldout">Ausverkauft<\/span>\s*<span class="wpcap-qty-box" hidden><input[^>]*max="0"[^>]* disabled>/s', $a456_now_html ) );
check( 'A456: waitlist form visible; only the sold-out type is selectable', 1 === preg_match( '/<form class="wpcap-waitlist-form"[^>]* novalidate>/', $a456_now_html ) && 1 === preg_match( '/<option value="' . $a456_seat . '" data-max="4" selected>/', $a456_now_html ) && 1 === preg_match( '/<option value="' . $a456_stand . '" data-max="6" disabled hidden>/', $a456_now_html ) );

// Everything sold out.
$a456_buy( $a456_stand, 2 );
$a456_s = ( (array) $a456_live()['occurrences'] )[ $a456_occ ];
check( 'A456: all types sold out → all_soldout, waitlist for both', true === $a456_s['all_soldout'] && array( $a456_seat, $a456_stand ) === $a456_s['waitlist_types'] && 'Ausverkauft' === $a456_s['availability'] );
$a456_sold_html = $a456_render();
check( 'A456: sold-out card still carries the (hidden) order form and shows the note', 1 === preg_match( '/<div class="wpcap-buy" hidden>/', $a456_sold_html ) && false !== strpos( $a456_sold_html, 'name="email"' ) && 1 === preg_match( '/class="wpcap-soldout-note">Dieser Termin ist ausverkauft/', $a456_sold_html ) );

// Not on sale any more / unknown dates.
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a456_occ ) );
$a456_gone = (array) PublicEvents::live_state( array( $a456_occ, 99999999, 'x' ) )['occurrences'];
check( 'A456: cancelled and unknown dates come back as not on sale (no other data)', array( 'on_sale' => false ) === $a456_gone[ $a456_occ ] && array( 'on_sale' => false ) === $a456_gone[99999999] && 2 === count( $a456_gone ) );
check( 'A456: at most 50 dates per request', PublicEvents::LIVE_MAX === count( (array) PublicEvents::live_state( range( 1, 80 ) )['occurrences'] ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'scheduled' ), array( 'occurrence_id' => $a456_occ ) );

// REST: no-store, ids as list, rate limit.
$_SERVER['REMOTE_ADDR'] = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
$a456_req = new WP_REST_Request( 'GET', '/pass-captain/v1/checkout/live' );
$a456_req->set_param( 'occurrences', $a456_occ . ',' . $a456_occ . ',abc' );
$a456_req->set_param( 'locale', 'de_DE' );
$a456_res = PublicEvents::rest_live( $a456_req );
$a456_data = is_wp_error( $a456_res ) ? array() : $a456_res->get_data();
$a456_headers = ( ! is_wp_error( $a456_res ) && method_exists( $a456_res, 'get_headers' ) ) ? $a456_res->get_headers() : array();
check( 'A456: GET /checkout/live answers the state with Cache-Control no-store', isset( ( (array) ( $a456_data['occurrences'] ?? array() ) )[ $a456_occ ] ) && 1 === count( (array) $a456_data['occurrences'] ) && false !== strpos( (string) ( $a456_headers['Cache-Control'] ?? '' ), 'no-store' ) && isset( $a456_data['legal']['terms_version'] ) );
$a456_limit = static function () { return 1; };
add_filter( 'wpcap_checkout_live_limit', $a456_limit );
$_SERVER['REMOTE_ADDR'] = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
PublicEvents::rest_live( $a456_req );
$a456_limited = PublicEvents::rest_live( $a456_req );
remove_filter( 'wpcap_checkout_live_limit', $a456_limit );
check( 'A456: rate-limited per client (wpcap_checkout_live_limit) with 429', is_wp_error( $a456_limited ) && 429 === ( $a456_limited->get_error_data()['status'] ?? 0 ) );
check( 'A456: the checkout script gets the live and options URLs', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' ), "'liveUrl' => esc_url_raw( rest_url( 'pass-captain/v1/checkout/live' ) )" ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' ), 'function refreshLive()' ) );

update_option( Settings::OPTION, $a456_saved );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a456_occ ) );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled' ), array( 'event_id' => $a456_event ) );
