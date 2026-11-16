<?php
/**
 * Creates (or reuses) the data the accessibility run needs in a disposable WordPress install and
 * prints the URLs as JSON: an event with a paid, a free (with participant field) and a sold-out
 * ticket type, a free order (ticket page + order portal link), a waitlist offer link and a service
 * booking page (A268: free and paid resource with slots).
 *
 * Usage: php tests/a11y/fixture.php /path/to/wordpress
 * NEVER run this against a production site: it creates orders, tickets and waitlist entries.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$root = rtrim( $argv[1] ?? '', '/' );
if ( ! $root || ! is_file( "$root/wp-load.php" ) ) {
	fwrite( STDERR, "Usage: php tests/a11y/fixture.php /path/to/wordpress\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define( 'WP_USE_THEMES', false );
require "$root/wp-load.php";

use PassCaptain\Orders;
use PassCaptain\Waitlists;

$mails = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 1, 2 );
// Fixture requests are not rate limited.
add_filter( 'wpcap_checkout_ip_limit', static fn() => PHP_INT_MAX );

global $wpdb;
$p = $wpdb->prefix;
// Repeated local runs submit checkouts from 127.0.0.1; reset the rate-limit counters (test install only).
$wpdb->query( "DELETE FROM {$p}wpcap_rate_limits" );
$slug = 'a11y-test-event';
$now = gmdate( 'Y-m-d H:i:s' );
$event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_events WHERE slug=%s", $slug ) );
if ( ! $event ) {
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Barrierefreiheit Testkonzert', 'slug' => $slug, 'description' => 'Konzert zum Prüfen der Barrierefreiheit.', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Großer Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	foreach ( array( array( 'Standard', 2500, 100 ), array( 'Freier Eintritt', 0, 100 ), array( 'Ausverkauft', 1000, 1 ) ) as list( $name, $price, $cap ) ) {
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $cap, 'max_per_order' => 10, 'status' => 'active' ) );
	}
	$free_type = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d AND name='Freier Eintritt'", $occ ) );
	$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $free_type, 'field_key' => 'diet', 'label' => 'Ernährungshinweis', 'field_type' => 'text', 'required' => 0, 'purpose' => 'Nur für das Catering', 'position' => 0, 'status' => 'active' ) );
}
$occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d", $event ) );
$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id", $occ ), OBJECT_K );
$by_name = array();
foreach ( $types as $id => $row ) { $by_name[ $row->name ] = (int) $id; }
if ( Orders::availability( $by_name['Ausverkauft'] ) > 0 ) {
	// Hold the only ticket for a year so the type renders as sold out.
	Orders::create( array( 'email' => 'soldout@example.com', 'items' => array( array( 'ticket_type_id' => $by_name['Ausverkauft'], 'quantity' => 1 ) ), 'idempotency_key' => 'a11y-soldout-' . bin2hex( random_bytes( 4 ) ), 'accept_terms' => true ), array( 'payment_provider' => 'manual', 'hold_minutes' => 60 * 24 * 365, 'skip_rate_limit' => true ) );
}

// Free order: tickets are issued immediately, the mail carries ticket codes and the portal link.
$order = Orders::create( array( 'email' => 'a11y-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $by_name['Freier Eintritt'], 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $by_name['Freier Eintritt'], 'name' => 'Alex Muster', 'answers' => array( 'diet' => 'vegan' ) ) ), 'idempotency_key' => 'a11y-' . bin2hex( random_bytes( 6 ) ), 'accept_terms' => true ), array( 'skip_rate_limit' => true ) );
if ( is_wp_error( $order ) ) { fwrite( STDERR, 'order: ' . $order->get_error_message() . "\n" ); exit( 1 ); }
$ticket_url = $portal_url = '';
foreach ( $mails as $mail ) {
	$body = is_array( $mail['message'] ?? null ) ? implode( "\n", $mail['message'] ) : (string) ( $mail['message'] ?? '' );
	if ( ! $ticket_url && preg_match( '#https?://[^\s"\'<>]*wpcap_ticket=[a-f0-9]{64}#', $body, $m ) ) { $ticket_url = html_entity_decode( $m[0] ); }
	if ( ! $portal_url && preg_match( '#https?://[^\s"\'<>]*wpcap_order=[a-f0-9]{32}[^\s"\'<>]*#', $body, $m ) ) { $portal_url = html_entity_decode( $m[0] ); }
}

// Waitlist: join the sold-out type and offer the place.
// The fixture joins directly (no double opt-in mail round trip, A564).
add_filter( 'wpcap_waitlist_double_opt_in', '__return_false' );
Waitlists::join( $event, $occ, $by_name['Ausverkauft'], 'a11y-wait-' . bin2hex( random_bytes( 3 ) ) . '@example.com' );
// Offers hold inventory: free one unit for the offer (the type stays sold out while it is held).
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET capacity=capacity+1 WHERE ticket_type_id=%d", $by_name['Ausverkauft'] ) );
$offer = Waitlists::offer_next( $event, $by_name['Ausverkauft'] );
$waitlist_url = '';
foreach ( $mails as $mail ) {
	if ( preg_match( '#https?://[^\s"\'<>]*wpcap_waitlist_accept=\d+[^\s"\'<>]*#', (string) ( $mail['message'] ?? '' ), $m ) ) { $waitlist_url = html_entity_decode( $m[0] ); }
}

// Service slots (A268): event with a free and a paid resource, slots over the next days.
$service_slug = 'a11y-service-event';
$service_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_events WHERE slug=%s", $service_slug ) );
if ( ! $service_event ) {
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Barrierefreiheit Bootsschule', 'slug' => $service_slug, 'description' => 'Fahrstunden zum Prüfen der Terminbuchung.', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$service_event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $service_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Hafen', 'capacity' => 0, 'status' => 'scheduled' ) );
	$service_occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $service_occ, 'name' => 'Fahrstunde', 'price_minor' => 4500, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 4, 'status' => 'active' ) );
	$service_type = (int) $wpdb->insert_id;
	wp_set_current_user( 1 );
	$free_res = \PassCaptain\ServiceBookings::create_resource( $service_event, array( 'name' => 'Schnuppersegeln', 'capacity' => 4, 'cancel_hours' => 24 ) );
	$paid_res = \PassCaptain\ServiceBookings::create_resource( $service_event, array( 'name' => 'Motorboot', 'capacity' => 2, 'ticket_type_id' => $service_type ) );
	wp_set_current_user( 0 );
}
// Keep a few future slots per resource (fixture reruns add new ones when the old ones have passed).
foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT resource_id FROM {$p}wpcap_resources WHERE event_id=%d", $service_event ) ) as $resource_id ) {
	$future = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_service_slots WHERE resource_id=%d AND starts_at>%s", $resource_id, gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ) );
	// Two weeks with slots, so the week navigation has somewhere to go.
	foreach ( array( 3, 4, 5, 10, 11, 12, 13, 14 ) as $day ) {
		if ( $future >= 6 ) { break; }
		$start = strtotime( gmdate( 'Y-m-d', time() + $day * DAY_IN_SECONDS ) . ' 08:00:00 UTC' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$p}wpcap_service_slots WHERE resource_id=%d AND starts_at=%s", $resource_id, gmdate( 'Y-m-d H:i:s', $start ) ) ) ) { continue; }
		$future++;
		$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => (int) $resource_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS ), 'capacity' => 4, 'status' => 'open' ) );
	}
}

$pages = $wpdb->get_results( "SELECT ID,post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND (post_content LIKE '%_events%' OR post_content LIKE '%_scanner%' OR post_content LIKE '%wpcap_service_booking%')" );
$events_url = $scanner_url = $service_url = '';
foreach ( $pages as $page ) {
	if ( ! $events_url && preg_match( '/\[(wp_pass_captain|wpcap)_events/', $page->post_content ) ) { $events_url = get_permalink( $page->ID ); }
	if ( ! $scanner_url && preg_match( '/\[(wp_pass_captain|wpcap)_scanner/', $page->post_content ) ) { $scanner_url = get_permalink( $page->ID ); }
	if ( ! $service_url && false !== strpos( $page->post_content, '[wpcap_service_booking event="' . $service_event . '"]' ) ) { $service_url = get_permalink( $page->ID ); }
}

// Fresh installs (CI): create the pages with the shortcodes once.
if ( ! $events_url ) { $events_url = get_permalink( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tickets', 'post_content' => '[wpcap_events]' ) ) ); }
if ( ! $service_url ) { $service_url = get_permalink( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Termine', 'post_content' => '[wpcap_service_booking event="' . $service_event . '"]' ) ) ); }
if ( ! $scanner_url ) { $scanner_url = get_permalink( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Scanner', 'post_content' => '[wpcap_scanner]' ) ) ); }
echo wp_json_encode( array(
	'event_id' => $event,
	'occurrence_id' => $occ,
	'ticket_types' => $by_name,
	'order_id' => (int) $order['order_id'],
	'events_url' => $events_url,
	'scanner_url' => $scanner_url,
	'service_event_id' => $service_event,
	'service_url' => $service_url,
	'ticket_url' => $ticket_url,
	'portal_url' => $portal_url,
	'waitlist_url' => $waitlist_url,
	'waitlist_offer' => is_wp_error( $offer ) ? $offer->get_error_message() : $offer,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
