<?php
/**
 * A268: public booking page for service time slots – shortcode [wpcap_service_booking] and block
 * pass-captain/service-booking: render states (no event, no resources/slots, sold out, free vs
 * paid, one resource), assets only where a form renders, POST forms, the week filter of the public
 * slot route (event timezone, DST), the manage link in the customer mail and the admin page.
 * Included from run.php (helpers ex_* from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\ServiceBooking;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceBookingFlow;
use PassCaptain\Admin\ServiceSlotsPage;

if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) { $GLOBALS['wpcap_test_shortcodes'][ $tag ] = $cb; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return esc_attr( $u ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) { function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-pass-captain-service-booking alignwide ' . esc_attr( $extra['class'] ?? '' ) . '"'; } }
if ( ! function_exists( 'get_post' ) ) { function get_post( $id = null ) { if ( null === $id ) { return $GLOBALS['wpcap_test_post'] ?? null; } return $GLOBALS['wpcap_test_posts'][ is_object( $id ) ? $id->ID : (int) $id ] ?? null; } } // WP: no argument = the current post.
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $post = 0 ) { $id = is_object( $post ) ? (int) $post->ID : (int) $post; return $id ? 'https://example.test/?page_id=' . $id : home_url( '/' ); } } // Same form as the stand-ins of other test files (whichever loads first).
if ( ! function_exists( 'get_the_ID' ) ) { function get_the_ID() { return (int) ( $GLOBALS['wpcap_test_the_id'] ?? 0 ); } }

global $wpdb;
$p = $wpdb->prefix;
$a268_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a268_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.68';
ServiceBookings::flush_schema_cache();

/** Slot of $resource starting $hours from now (UTC, rounded to the hour). */
function a268_slot( $resource, $hours, $capacity = 2, $status = 'open' ) {
	global $wpdb;
	$start = (int) ( floor( ( time() + $hours * 3600 ) / 3600 ) * 3600 );
	$wpdb->insert( "{$wpdb->prefix}wpcap_service_slots", array( 'resource_id' => $resource, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + 3600 ), 'capacity' => $capacity, 'status' => $status ) );
	return (int) $wpdb->insert_id;
}
/** Renders and returns [html, enqueued, config]. */
function a268_render( $event, $resource = 0 ) {
	$r = new ReflectionProperty( ServiceBooking::class, 'enqueued' );
	$r->setValue( null, false );
	$html = ServiceBooking::render_shortcode( array( 'event' => (string) $event, 'resource' => (string) $resource ) );
	$config = preg_match( '/data-wpcap-sb="([^"]*)"/', $html, $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ) : null;
	return array( $html, ServiceBooking::assets_enqueued(), $config );
}
function a268_data( $r ) { return $r instanceof WP_REST_Response ? $r->get_data() : $r; }

echo "A268: registration\n";
$GLOBALS['wpcap_test_shortcodes'] = $GLOBALS['wpcap_test_shortcodes'] ?? array();
ServiceBooking::register();
$a268_block = json_decode( (string) file_get_contents( WPCAP_PATH . 'src/blocks/service-booking/block.json' ), true );
$a268_plugin = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'shortcode wpcap_service_booking registered (Plugin::run → Frontend\ServiceBooking::register)', array( ServiceBooking::class, 'render_shortcode' ) === ( $GLOBALS['wpcap_test_shortcodes']['wpcap_service_booking'] ?? null ) && false !== strpos( $a268_plugin, 'Frontend\ServiceBooking::register()' ) );
check( 'block pass-captain/service-booking: apiVersion 3, server render, event/resource attributes', is_array( $a268_block ) && 3 === $a268_block['apiVersion'] && 'pass-captain/service-booking' === $a268_block['name'] && 'file:./render.php' === $a268_block['render'] && isset( $a268_block['attributes']['eventId'], $a268_block['attributes']['resourceId'] ) && 'pass-captain' === $a268_block['textdomain'] );
check( 'block renderer uses the block wrapper attributes', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/blocks/service-booking/render.php' ), 'render_block' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Frontend/ServiceBooking.php' ), 'get_block_wrapper_attributes(' ) );

echo "A268: states without a form\n";
list( $a268_h ) = a268_render( 0 );
list( $a268_h2, $a268_e2 ) = a268_render( 999999 );
check( 'no event / unknown event: notice, no script', false !== strpos( $a268_h, 'derzeit nicht buchbar' ) && false !== strpos( $a268_h2, 'derzeit nicht buchbar' ) && ! $a268_e2 && false === strpos( $a268_h2, '<form' ) );
$a268_draft = ex_event( 'A268 Entwurf' );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'draft' ), array( 'event_id' => $a268_draft ) );
ex_occ( $a268_draft );
$a268_draft_res = ServiceBookings::create_resource( $a268_draft, array( 'name' => 'Entwurfsboot', 'capacity' => 2 ) );
a268_slot( $a268_draft_res, 48 );
list( $a268_h, $a268_e ) = a268_render( $a268_draft );
check( 'unpublished event: not bookable', false !== strpos( $a268_h, 'derzeit nicht buchbar' ) && ! $a268_e );
$a268_empty = ex_event( 'A268 Ohne Ressourcen' );
ex_occ( $a268_empty );
list( $a268_h, $a268_e ) = a268_render( $a268_empty );
check( 'event without resources: "keine Termine buchbar", no script', false !== strpos( $a268_h, 'Derzeit sind keine Termine buchbar' ) && ! $a268_e );
$a268_noslot_res = ServiceBookings::create_resource( $a268_empty, array( 'name' => 'Leeres Boot', 'capacity' => 2 ) );
a268_slot( $a268_noslot_res, -48 ); // only a past slot
a268_slot( $a268_noslot_res, 72, 2, 'closed' );
list( $a268_h, $a268_e ) = a268_render( $a268_empty );
check( 'resource without open future slots: "keine Termine buchbar"', false !== strpos( $a268_h, 'Derzeit sind keine Termine buchbar' ) && ! $a268_e );

$a268_sold = ex_event( 'A268 Ausgebucht' );
ex_occ( $a268_sold );
$a268_sold_res = ServiceBookings::create_resource( $a268_sold, array( 'name' => 'Volles Boot', 'capacity' => 1 ) );
$a268_sold_slot = a268_slot( $a268_sold_res, 72, 1 );
$GLOBALS['wpcap_test_transients'] = array();
$a268_full = ServiceBookings::reserve( $a268_sold_slot, 'a268-full@example.com', 1, 0, 10, 'a268-full-' . uniqid() );
list( $a268_h, $a268_e ) = a268_render( $a268_sold );
check( 'sold out (open slots, no free place): "ausgebucht" notice, no form, no script', is_array( $a268_full ) && false !== strpos( $a268_h, 'ausgebucht' ) && false === strpos( $a268_h, '<form' ) && ! $a268_e );

echo "A268: free and paid resources\n";
$a268_event = ex_event( 'A268 Bootsschule' );
$a268_occ = ex_occ( $a268_event, 0, 60 * 24 );
$a268_type = ex_type( $a268_occ, 'Fahrstunde', 4000 );
$a268_free = ServiceBookings::create_resource( $a268_event, array( 'name' => 'A Schnuppersegeln', 'capacity' => 3, 'cancel_hours' => 24 ) );
$a268_paid = ServiceBookings::create_resource( $a268_event, array( 'name' => 'B Motorboot', 'capacity' => 4, 'ticket_type_id' => $a268_type ) );
$a268_fs1 = a268_slot( $a268_free, 30, 3 );
$a268_fs2 = a268_slot( $a268_free, 30 + 24 * 14, 3 ); // two weeks later
$a268_ps1 = a268_slot( $a268_paid, 50, 4 );
list( $a268_h, $a268_e, $a268_cfg ) = a268_render( $a268_event );
check( 'form rendered with method="post" (no PII in URLs) and script enqueued', $a268_e && 1 === preg_match( '/<form class="wpcap-sb-form" method="post"/', $a268_h ) && false === strpos( $a268_h, 'method="get"' ) );
check( 'both resources offered as radio buttons with price / "kostenlos"', 2 === preg_match_all( '/name="resource_id"/', $a268_h ) && false !== strpos( $a268_h, 'kostenlos' ) && false !== strpos( $a268_h, '40,00 € pro Platz' ) && false !== strpos( $a268_h, 'Kostenlos stornierbar bis 1 Tag vor Beginn' ) );
check( 'first resource preselected, its first week rendered with the slot and free places', is_array( $a268_cfg ) && $a268_free === $a268_cfg['resource'] && false !== strpos( $a268_h, 'value="' . $a268_fs1 . '"' ) && false === strpos( $a268_h, 'value="' . $a268_ps1 . '"' ) && false !== strpos( $a268_h, '3 Plätze frei' ) );
$a268_zone = new DateTimeZone( 'Europe/Vienna' );
$a268_res_cfg = array_column( $a268_cfg['resources'], null, 'id' );
check( 'config: event timezone, weeks per resource (first/last), price data, quantity limit', 'Europe/Vienna' === $a268_cfg['tz'] && $a268_res_cfg[ $a268_free ]['firstWeek'] === ServiceBooking::week_of( ServiceBookings::slot( $a268_fs1 )->starts_at, $a268_zone ) && $a268_res_cfg[ $a268_free ]['lastWeek'] === ServiceBooking::week_of( ServiceBookings::slot( $a268_fs2 )->starts_at, $a268_zone ) && false === $a268_res_cfg[ $a268_free ]['paid'] && true === $a268_res_cfg[ $a268_paid ]['paid'] && 4000 === $a268_res_cfg[ $a268_paid ]['priceMinor'] && 3 === $a268_res_cfg[ $a268_free ]['maxQty'] );
check( 'paid section (payment, invoice data) present but hidden while the free resource is selected; free submit label', 1 === preg_match( '/<div class="wpcap-sb-paid" hidden>/', $a268_h ) && false !== strpos( $a268_h, 'name="billing_enabled"' ) && false !== strpos( $a268_h, '>Termin verbindlich buchen</button>' ) && false !== strpos( $a268_h, 'data-label-paid="Zahlungspflichtig buchen"' ) );
check( 'week navigation: previous disabled in the current week, next enabled (later slots exist)', false !== strpos( $a268_h, 'class="wpcap-sb-prev" aria-controls="' ) && ( 1 === preg_match( '/wpcap-sb-prev"[^>]*aria-disabled="true"/', $a268_h ) ) === ( $a268_cfg['week'] <= $a268_cfg['todayWeek'] ) && 1 === preg_match( '/wpcap-sb-next"[^>]*aria-disabled="false"/', $a268_h ) );
check( 'accessible markup: labelled steps, legends, labels for every input, live region, noscript hint', false !== strpos( $a268_h, '<legend>Zeitfenster</legend>' ) && false !== strpos( $a268_h, 'role="status" aria-live="polite"' ) && false !== strpos( $a268_h, '<noscript>' ) && preg_match_all( '/<input (?![^>]*type="hidden")[^>]*id="([^"]+)"/', $a268_h, $a268_ids ) && count( $a268_ids[1] ) === count( array_filter( $a268_ids[1], static fn( $id ) => false !== strpos( $a268_h, 'for="' . $id . '"' ) ) ) );
check( 'no customer data or token in the rendered page', false === strpos( $a268_h, '@example.com' ) && false === strpos( $a268_h, 'token' ) );

list( $a268_h, $a268_e, $a268_cfg ) = a268_render( $a268_event, $a268_paid );
check( 'resource="ID": only that resource (hidden input), paid section visible, paid submit label', false === strpos( $a268_h, 'type="radio" name="resource_id"' ) && false !== strpos( $a268_h, 'name="resource_id" value="' . $a268_paid . '"' ) && 1 === preg_match( '/<div class="wpcap-sb-paid">/', $a268_h ) && false !== strpos( $a268_h, '>Zahlungspflichtig buchen</button>' ) && 1 === count( $a268_cfg['resources'] ) );
list( $a268_h ) = a268_render( $a268_event, $a268_noslot_res );
check( 'resource of another event: not offered', false !== strpos( $a268_h, 'Derzeit sind keine Termine buchbar' ) );

$a268_block_html = ServiceBooking::render_block( array( 'eventId' => $a268_event, 'resourceId' => 0 ) );
check( 'block: wrapper attributes on the root element (alignment class kept), same form', 1 === preg_match( '/^<div class="wp-block-pass-captain-service-booking alignwide wpcap-service-booking" id="wpcap-sb-\d+" data-wpcap-sb=/', $a268_block_html ) && false !== strpos( $a268_block_html, 'wpcap-sb-form' ) );

echo "A268: week filter of GET /events/{id}/service-slots\n";
// DST end in Vienna: 2026-10-25. The week from Monday 19 Oct starts 18 Oct 22:00 UTC and ends 25 Oct 23:00 UTC.
$a268_range = ServiceBooking::week_range( $a268_event, '2026-10-19' );
check( 'week range in the event timezone across the DST change', array( 'from' => '2026-10-18 22:00:00', 'to' => '2026-10-25 23:00:00' ) === $a268_range );
check( 'week must be a valid Monday', null === ServiceBooking::week_range( $a268_event, '2026-10-20' ) && null === ServiceBooking::week_range( $a268_event, '2026-02-30' ) && null === ServiceBooking::week_range( $a268_event, 'x' ) );
$a268_req = new WP_REST_Request( 'GET' );
$a268_req->set_param( 'event_id', $a268_event );
$a268_all = a268_data( PassCaptain\Rest\Controller::service_slots( $a268_req ) );
$a268_req->set_param( 'resource', $a268_free );
$a268_res_only = a268_data( PassCaptain\Rest\Controller::service_slots( $a268_req ) );
$a268_req->set_param( 'week', ServiceBooking::week_of( ServiceBookings::slot( $a268_fs2 )->starts_at, $a268_zone ) );
$a268_week = a268_data( PassCaptain\Rest\Controller::service_slots( $a268_req ) );
$a268_req->set_param( 'week', '2026-13-01' );
$a268_bad = PassCaptain\Rest\Controller::service_slots( $a268_req );
check( 'without parameters unchanged (all bookable slots of the event)', array( $a268_fs1, $a268_ps1, $a268_fs2 ) === array_column( $a268_all, 'slot_id' ) );
check( 'resource and week narrow the list; invalid week → 400', array( $a268_fs1, $a268_fs2 ) === array_column( $a268_res_only, 'slot_id' ) && array( $a268_fs2 ) === array_column( $a268_week, 'slot_id' ) && is_wp_error( $a268_bad ) && 400 === ( $a268_bad->get_error_data()['status'] ?? 0 ) );

echo "A268: booking through the REST routes and the manage link\n";
$GLOBALS['wpcap_test_posts'] = array(
	501 => (object) array( 'ID' => 501, 'post_status' => 'publish', 'post_password' => '', 'post_content' => '<!-- wp:pass-captain/service-booking {"eventId":' . $a268_event . '} /-->' ),
	502 => (object) array( 'ID' => 502, 'post_status' => 'publish', 'post_password' => '', 'post_content' => 'Keine Buchung hier' ),
	503 => (object) array( 'ID' => 503, 'post_status' => 'draft', 'post_password' => '', 'post_content' => '[wpcap_service_booking event="' . $a268_event . '"]' ),
);
$GLOBALS['wpcap_test_transients'] = array();
$a268_book = new WP_REST_Request( 'POST' );
$a268_book->set_param( 'slot_id', $a268_fs1 );
$a268_book->set_body( wp_json_encode( array( 'email' => 'a268-kunde@example.com', 'quantity' => 2, 'idempotency_key' => 'a268-' . uniqid() ) ) );
$a268_held = a268_data( PassCaptain\Rest\Controller::book_service_slot( $a268_book ) );
$a268_token = (string) ( $a268_held['token'] ?? '' );
$a268_bid = (int) ( $a268_held['booking_id'] ?? 0 );
check( 'reservation via POST /service-slots/{id}/bookings returns booking id and token', $a268_bid && 64 === strlen( $a268_token ) );
check( 'manage link only for a published page with the booking form', 'https://example.test/?page_id=501#wpcap-booking=' . $a268_bid . '-' . $a268_token === ServiceBookingFlow::manage_link( 501, $a268_bid, $a268_token ) && '' === ServiceBookingFlow::manage_link( 502, $a268_bid, $a268_token ) && '' === ServiceBookingFlow::manage_link( 503, $a268_bid, $a268_token ) && '' === ServiceBookingFlow::manage_link( 501, $a268_bid, 'abc' ) );
$GLOBALS['wpcap_test_mails'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$a268_conf = new WP_REST_Request( 'POST' );
$a268_conf->set_param( 'booking_id', $a268_bid );
$a268_conf->set_body( wp_json_encode( array( 'token' => $a268_token, 'page_id' => 501 ) ) );
$a268_ok = true === ServiceBookingFlow::token_permission( $a268_conf );
$a268_done = a268_data( ServiceBookingFlow::rest_confirm( $a268_conf ) );
$a268_customer = null;
$a268_leak = false;
foreach ( $GLOBALS['wpcap_test_mails'] as $a268_mail ) {
	if ( false !== strpos( (string) $a268_mail['to'], 'a268-kunde@' ) ) { $a268_customer = $a268_mail; } elseif ( false !== strpos( $a268_mail['subject'] . $a268_mail['body'], $a268_token ) ) { $a268_leak = true; }
}
check( 'free resource confirmed at once via POST /service-bookings/{id}/confirm', $a268_ok && 'confirmed' === ( $a268_done['status'] ?? '' ) && 'confirmed' === ServiceBookings::booking( $a268_bid )->status );
// Merged design: the confirmation is the service_booking_confirmed mail template (Mail\Types), its {manage_link} is the page link.
check( 'customer mail carries the manage link (token in the fragment); team mail never does', $a268_customer && false !== strpos( $a268_customer['body'], 'Buchungslink' ) && false !== strpos( $a268_customer['body'], ' https://example.test/?page_id=501#wpcap-booking=' . $a268_bid . '-' . $a268_token ) && false === strpos( $a268_customer['body'], 'token=' ) && ! $a268_leak );
$a268_get = new WP_REST_Request( 'GET' );
$a268_get->set_param( 'booking_id', $a268_bid );
$a268_get->set_body( wp_json_encode( array( 'token' => $a268_token ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$a268_view = a268_data( ServiceBookingFlow::rest_get( $a268_get ) );
check( 'manage view: token in the JSON body is accepted (no token in a URL needed)', true === ServiceBookingFlow::token_permission( $a268_get ) && 'confirmed' === $a268_view['status'] && 2 === $a268_view['quantity'] && 24 === $a268_view['rules']['cancel_hours'] && in_array( $a268_fs2, array_column( $a268_view['rebook_options'], 'slot_id' ), true ) );
list( $a268_h ) = a268_render( $a268_event, $a268_free );
check( 'after the booking the page shows the remaining place', false !== strpos( $a268_h, '1 Platz frei' ) );

echo "A268: admin page\n";
$a268_src = (string) file_get_contents( WPCAP_PATH . 'src/Admin/ServiceSlotsPage.php' );
check( 'misleading "öffentliche Buchungsschnittstelle" text replaced', false === strpos( $a268_src, 'über die öffentliche Buchungsschnittstelle' ) && false !== strpos( $a268_src, '[wpcap_service_booking]' ) );
ob_start();
ServiceSlotsPage::render_public_page( $a268_event );
$a268_admin = ob_get_clean();
check( 'admin: shortcode of the event, hint when no page shows it yet', false !== strpos( $a268_admin, 'value="[wpcap_service_booking event=&quot;' . $a268_event . '&quot;]"' ) && false !== strpos( $a268_admin, 'Noch keine veröffentlichte Seite' ) && false === strpos( $a268_admin, 'Auf der Website ansehen' ) );
$a268_filter = static function ( $url, $event_id ) use ( $a268_event ) { return $event_id === $a268_event ? 'https://example.test/termine/' : $url; };
add_filter( 'wpcap_service_booking_page_url', $a268_filter, 10, 2 );
ob_start();
ServiceSlotsPage::render_public_page( $a268_event );
$a268_admin = ob_get_clean();
remove_filter( 'wpcap_service_booking_page_url', $a268_filter, 10 );
check( 'admin: "Auf der Website ansehen" links to the page', false !== strpos( $a268_admin, 'href="https://example.test/termine/"' ) && false !== strpos( $a268_admin, 'Auf der Website ansehen' ) );
check( 'page detection: shortcode and block markup with the event id', 12 === ServiceBooking::content_event( 'x [wpcap_service_booking event="12" resource="3"] y' ) && 7 === ServiceBooking::content_event( "[wpcap_service_booking event='7']" ) && 9 === ServiceBooking::content_event( '<!-- wp:pass-captain/service-booking {"resourceId":2,"eventId":9} /-->' ) && 0 === ServiceBooking::content_event( '[wpcap_events event="5"]' ) && ServiceBooking::content_has_form( '<!-- wp:pass-captain/service-booking /-->' ) && ! ServiceBooking::content_has_form( '[wpcap_events]' ) );

$GLOBALS['wpcap_test_posts'] = array();
$GLOBALS['wpcap_test_user_id'] = $a268_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $a268_saved_denied;
