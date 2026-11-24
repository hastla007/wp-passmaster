<?php
/**
 * PASS-FE-17: remaining scope of service bookings, checked against the acceptance points of the
 * visitor booking widget (A268, shortcode [wpcap_service_booking] / block pass-captain/service-booking):
 * strict CSP (no inline handlers or scripts in the rendered form or the script), versioned assets,
 * labels/aria-live, du-form strings, REST routes of the widget with permission callbacks and in the
 * public REST coverage list; dearer rebooking with a supplementary payment (X3) is wired to the
 * order portal; the no-show fee is only recorded (no charge without a decision). Runs alone
 * (helpers ex_* from lib/fixtures_modules.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\ServiceBooking;
use PassCaptain\ServiceBookings;

if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { $GLOBALS['wpcap_test_shortcodes'][ $tag ] = $cb; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return esc_attr( $u ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }

global $wpdb;
$fe17_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 1;
ServiceBookings::flush_schema_cache();

/** Open slot of $resource starting $hours from now (UTC, full hour). */
function fe17_slot( $resource, $hours, $capacity = 3 ) {
	global $wpdb;
	$start = (int) ( floor( ( time() + $hours * 3600 ) / 3600 ) * 3600 );
	$wpdb->insert( "{$wpdb->prefix}wpcap_service_slots", array( 'resource_id' => $resource, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + 3600 ), 'capacity' => $capacity, 'status' => 'open' ) );
	return (int) $wpdb->insert_id;
}

echo "PASS-FE-17: visitor booking widget (shortcode/block)\n";
$fe17_event = ex_event( 'FE-17 Segelschule' );
$fe17_occ = ex_occ( $fe17_event, 0, 60 * 24 );
$fe17_type = ex_type( $fe17_occ, 'Segelstunde', 3500 );
$fe17_free = ServiceBookings::create_resource( $fe17_event, array( 'name' => 'A Probestunde', 'capacity' => 3 ) );
$fe17_paid = ServiceBookings::create_resource( $fe17_event, array( 'name' => 'B Jolle', 'capacity' => 2, 'ticket_type_id' => $fe17_type ) );
fe17_slot( $fe17_free, 30 );
fe17_slot( $fe17_paid, 54, 2 );
$fe17_r = new ReflectionProperty( ServiceBooking::class, 'enqueued' );
$fe17_r->setValue( null, false );
$fe17_html = ServiceBooking::render_shortcode( array( 'event' => (string) $fe17_event ) );
check( 'FE-17: shortcode renders an interactive booking form and enqueues its assets', false !== strpos( $fe17_html, 'class="wpcap-sb-form"' ) && ServiceBooking::assets_enqueued() );
check( 'FE-17: strict CSP – no inline event handlers, javascript: URLs or inline scripts in the form', 0 === preg_match( '/<[a-z][^>]*\son[a-z]+\s*=/i', $fe17_html ) && false === stripos( $fe17_html, 'javascript:' ) && false === stripos( $fe17_html, '<script' ) );
preg_match_all( '/<(select|textarea)\b[^>]*\bid="([^"]+)"/', $fe17_html, $fe17_fields );
check( 'FE-17: every select/textarea has a label; live region present', count( $fe17_fields[2] ) === count( array_filter( $fe17_fields[2], static fn( $id ) => false !== strpos( $fe17_html, 'for="' . $id . '"' ) ) ) && false !== strpos( $fe17_html, 'aria-live="polite"' ) );
check( 'FE-17: resources as a native radio group (keyboard operable), week navigation as buttons', 2 === preg_match_all( '/type="radio" name="resource_id"/', $fe17_html ) && false !== strpos( $fe17_html, '<button type="button" class="wpcap-sb-next"' ) );

$fe17_src = (string) file_get_contents( WPCAP_PATH . 'src/Frontend/ServiceBooking.php' );
$fe17_js = (string) file_get_contents( WPCAP_PATH . 'assets/service-booking.js' );
check( 'FE-17: script registered from a file with the plugin version (cache busting), in the footer', 1 === preg_match( "/wp_register_script\( self::HANDLE, WPCAP_URL \. 'assets\/service-booking\.js', array\(\), WPCAP_VERSION, true \)/", $fe17_src ) && '' !== $fe17_js );
check( 'FE-17: script uses addEventListener only (no on*= markup, no eval / new Function / string timers)', 0 === preg_match( '/\son[a-z]+\s*=\s*[\'"]|\.on[a-z]+\s*=|\beval\s*\(|new Function\s*\(|setTimeout\s*\(\s*[\'"]|setAttribute\(\s*[\'"]on/i', $fe17_js ) && false !== strpos( $fe17_js, 'addEventListener(' ) );
$fe17_strings = $fe17_src . (string) file_get_contents( WPCAP_PATH . 'src/ServiceBookingFlow.php' ) . $fe17_js;
check( 'FE-17: German UI strings in du-form (no Sie/Ihnen/Ihr…)', 0 === preg_match( '/\b(Sie|Ihnen|Ihre[nmrs]?|Ihr)\b/', $fe17_strings ) );

echo "PASS-FE-17: REST routes used by the widget\n";
$fe17_saved_routes = $GLOBALS['wpcap_test_routes'] ?? array();
$GLOBALS['wpcap_test_routes'] = array();
\PassCaptain\Rest\Controller::register_routes();
$fe17_routes = $GLOBALS['wpcap_test_routes'];
$GLOBALS['wpcap_test_routes'] = $fe17_saved_routes;
$fe17_used = array(
	'pass-captain/v1/events/(?P<event_id>\d+)/service-slots' => '__return_true',
	'pass-captain/v1/service-slots/(?P<slot_id>\d+)/bookings' => '__return_true',
	'pass-captain/v1/service-bookings/(?P<booking_id>\d+)' => 'token',
	'pass-captain/v1/service-bookings/(?P<booking_id>\d+)/confirm' => 'token',
	'pass-captain/v1/service-bookings/(?P<booking_id>\d+)/rebook' => 'token',
	'pass-captain/v1/service-bookings/(?P<booking_id>\d+)/cancel' => 'token',
);
$fe17_ok = true;
foreach ( $fe17_used as $fe17_route => $fe17_kind ) {
	$fe17_cb = $fe17_routes[ $fe17_route ]['permission_callback'] ?? null;
	$fe17_ok = $fe17_ok && ( 'token' === $fe17_kind ? array( \PassCaptain\ServiceBookingFlow::class, 'token_permission' ) === $fe17_cb : '__return_true' === $fe17_cb );
}
check( 'FE-17: widget routes registered with permission callbacks (public list routes, booking-token routes)', $fe17_ok );
check( 'FE-17: the script books through these routes', false !== strpos( $fe17_js, '/service-slots' ) && false !== strpos( $fe17_js, 'service-bookings/' ) && false !== strpos( $fe17_js, '/bookings' ) );
$fe17_channels = (string) file_get_contents( WPCAP_PATH . 'tests/integration/channels.php' );
check( 'FE-17: public widget routes are in the REST coverage list of channels.php', false !== strpos( $fe17_channels, "'GET pass-captain/v1/events/(?P<event_id>\\d+)/service-slots'" ) && false !== strpos( $fe17_channels, "'POST pass-captain/v1/service-slots/(?P<slot_id>\\d+)/bookings'" ) );
$fe17_view = new WP_REST_Request( 'GET', '/pass-captain/v1/service-bookings/1' );
$fe17_view->set_param( 'booking_id', 1 );
$fe17_view->set_param( 'token', str_repeat( '0', 64 ) );
$fe17_denied = \PassCaptain\ServiceBookingFlow::token_permission( $fe17_view );
check( 'FE-17: booking-token routes refuse a wrong token (403)', is_wp_error( $fe17_denied ) && 403 === ( $fe17_denied->get_error_data()['status'] ?? 0 ) );

echo "PASS-FE-17: dearer rebooking and no-show fee\n";
$fe17_core = (string) file_get_contents( WPCAP_PATH . 'src/ServiceBookings.php' );
$fe17_portal = (string) file_get_contents( WPCAP_PATH . 'src/Services/Portal.php' );
check( 'FE-17: dearer rebooking with supplementary payment exists (X3) and the order portal offers its payment', method_exists( ServiceBookings::class, 'complete_supplement' ) && method_exists( ServiceBookings::class, 'open_supplement' ) && false !== strpos( $fe17_portal, "'order_supplement_pay'" ) && is_file( WPCAP_PATH . 'tests/integration/modules/zzzz_services_f_supplement.php' ) );
$fe17_noshow = preg_match( '/function mark_no_show\(.*?\n\t}\n/s', $fe17_core, $fe17_m ) ? $fe17_m[0] : '';
check( 'FE-17: no-show fee is only recorded (no order, payment or charge in mark_no_show)', '' !== $fe17_noshow && false !== strpos( $fe17_noshow, 'fee_minor' ) && 0 === preg_match( '/Orders::|create_payment|Refunder::|charge/', $fe17_noshow ) );

$fe17_doc = (string) file_get_contents( WPCAP_PATH . 'docs/SERVICE-BOOKINGS.md' );
$fe17_open = (string) strstr( $fe17_doc, '## Offene Punkte' );
check( 'FE-17: docs – widget and dearer rebooking no longer listed as open; no-show fee and WooCommerce sale named as open', false !== strpos( $fe17_doc, '[wpcap_service_booking' ) && '' !== $fe17_open && false === strpos( $fe17_open, 'Buchungswidget' ) && false === strpos( $fe17_open, 'nicht umgesetzt (abgelehnt' ) && false !== strpos( $fe17_open, 'No-Show-Gebühr' ) && false !== strpos( $fe17_open, 'WooCommerce' ) );

$GLOBALS['wpcap_test_user_id'] = $fe17_saved_user;
