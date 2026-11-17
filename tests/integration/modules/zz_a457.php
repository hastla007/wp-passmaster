<?php
/**
 * A457: after a change of the terms every cached checkout page was blocked: the order is refused with
 * 409 wpcap_terms_changed ("reload the page"), but the reload shows the cached page with the old
 * version again. Now the card marks its legal texts (data-legal, .wpcap-legal-text), the 409 names the
 * current version, and GET /checkout/options (no-store) delivers the texts as the card shows them, so
 * the checkout script swaps them in place and the buyer confirms the current version (JS:
 * tests/js/checkout-live.test.js). Saving the texts purges the listing pages (A446). Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\PageCache;
use PassCaptain\PublicEvents;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A457: changed terms on a cached checkout page are reloaded in place\n";
global $wpdb;
$p = $wpdb->prefix;
$a457_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'manual_instructions' => 'Bitte überweisen.', 'require_terms' => 1, 'terms_text' => 'AGB Fassung eins', 'consumer_info_text' => 'Infos eins' ) );
$a457_type = make_type( 10, 10, 2500 );
$a457_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$a457_type" );
Orders::flush_availability_cache();
$a457_card = PublicEvents::render_shortcode( array( 'event' => $a457_event ) );
preg_match( '/name="terms_version" value="([a-f0-9]+)"/', $a457_card, $a457_m );
$a457_v1 = (string) ( $a457_m[1] ?? '' );
check( 'A457: the card marks both legal texts for replacement and names the version', '' !== $a457_v1 && 1 === preg_match( '/<details class="wpcap-legal" data-legal="terms"><summary>Bedingungen<\/summary><div class="wpcap-legal-text"><p>AGB Fassung eins<\/p><\/div><\/details>/', $a457_card ) && 1 === preg_match( '/data-legal="consumer_info"><summary>Verbraucherinformationen<\/summary><div class="wpcap-legal-text"><p>Infos eins<\/p>/', $a457_card ) );

// The terms change; the cached page still names v1.
PageCache::flush();
$a457_purged = array();
$a457_cb = static function ( $event_id ) use ( &$a457_purged ) { $a457_purged[] = (int) $event_id; };
add_action( PageCache::ACTION, $a457_cb, 5, 1 );
Settings::update( array( 'terms_text' => 'AGB Fassung zwei' ) );
PageCache::flush();
remove_action( PageCache::ACTION, $a457_cb, 5 );
check( 'A457: saving the texts purges every listing page (event 0, A446)', in_array( 0, $a457_purged, true ) );
$a457_v2 = Settings::terms_version( PublicEvents::legal_context()['locale'] );
$a457_order = static function ( $version ) use ( $a457_type ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$_SERVER['REMOTE_ADDR'] = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
	return Orders::create( array( 'email' => 'a457@example.com', 'items' => array( array( 'ticket_type_id' => $a457_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a457-' . uniqid( '', true ), 'accept_terms' => true, 'terms_version' => $version, 'locale' => PublicEvents::legal_context()['locale'] ) );
};
$a457_refused = $a457_order( $a457_v1 );
check( 'A457: consent to the old version is refused with 409 and the current version in data', $a457_v1 !== $a457_v2 && is_wp_error( $a457_refused ) && 'wpcap_terms_changed' === $a457_refused->get_error_code() && 409 === ( $a457_refused->get_error_data()['status'] ?? 0 ) && $a457_v2 === ( $a457_refused->get_error_data()['terms_version'] ?? '' ) );

$a457_req = new WP_REST_Request( 'GET', '/pass-captain/v1/checkout/options' );
$a457_req->set_param( 'locale', PublicEvents::legal_context()['locale'] );
$a457_opts = Controller::checkout_options( $a457_req );
$a457_data = $a457_opts->get_data();
$a457_headers = method_exists( $a457_opts, 'get_headers' ) ? $a457_opts->get_headers() : array();
check( 'A457: /checkout/options delivers the current texts as the card shows them (terms_html, consumer_info_html)', $a457_v2 === $a457_data['terms_version'] && '<p>AGB Fassung zwei</p>' === $a457_data['terms_html'] && '<p>Infos eins</p>' === $a457_data['consumer_info_html'] );
check( 'A457: /checkout/options is never stored by a cache (no-store)', false !== strpos( (string) ( $a457_headers['Cache-Control'] ?? '' ), 'no-store' ) );
$a457_ok = $a457_order( $a457_v2 );
check( 'A457: confirming the reloaded version is accepted and stored on the order', ! is_wp_error( $a457_ok ) && $a457_v2 === (string) Orders::get( (int) $a457_ok['order_id'] )->terms_version );
check( 'A457: the live state names the current version, so an open card reloads the texts before ordering', $a457_v2 === PublicEvents::live_state( array() )['legal']['terms_version'] );

Settings::update( array( 'consumer_info_text' => '' ) );
Orders::flush_availability_cache();
$a457_card2 = PublicEvents::render_shortcode( array( 'event' => $a457_event ) );
check( 'A457: an empty text keeps its (hidden) container for a later reload', 1 === preg_match( '/data-legal="consumer_info" hidden><summary>/', $a457_card2 ) );

update_option( Settings::OPTION, $a457_saved );
$wpdb->query( "UPDATE {$p}wpcap_events SET status='cancelled' WHERE event_id=$a457_event" );
