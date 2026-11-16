<?php
/**
 * R7 (audit round 2, expert #12): event detail page with shareable URL and schema.org/Event JSON-LD
 * (Frontend\EventPage), rewrite flush only on change.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\EventPage as R7ePage;
use PassCaptain\Settings as R7eSettings;

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); } }

echo "R7 event page: URL, flush on change, JSON-LD\n";
$r7e_before = array( 'event_pages' => R7eSettings::get( 'event_pages' ), 'event_base' => R7eSettings::get( 'event_base' ) );
$r7e_perma = get_option( 'permalink_structure' );
update_option( 'permalink_structure', '/%postname%/' );
R7eSettings::update( array( 'event_pages' => 1, 'event_base' => 'event' ) );
check( 'default base "event", pretty URL /event/{slug}/', 'https://example.test/event/sommer-ab12/' === R7ePage::url( 'sommer-ab12' ) );
update_option( 'permalink_structure', '' );
check( 'plain permalinks fall back to ?wpcap_event=', false !== strpos( R7ePage::url( 'sommer-ab12' ), 'wpcap_event=sommer-ab12' ) );
update_option( 'permalink_structure', '/%postname%/' );
delete_option( R7ePage::FLUSH_OPTION );
check( 'base sanitiser: lower case, dashes; unchanged base schedules no flush', 'konzerte-2027' === R7ePage::sanitize_base( 'Konzerte 2027', 'konzerte-2027' ) && ! get_option( R7ePage::FLUSH_OPTION ) );
R7ePage::sanitize_base( 'shows', 'event' );
check( 'changed base schedules exactly one flush for the next init', 1 === (int) get_option( R7ePage::FLUSH_OPTION ) );
delete_option( R7ePage::FLUSH_OPTION );
R7ePage::sanitize_switch( 0, 1 );
check( 'switching event pages off schedules a flush too', 1 === (int) get_option( R7ePage::FLUSH_OPTION ) );
delete_option( R7ePage::FLUSH_OPTION );
R7eSettings::update( array( 'event_pages' => 0 ) );
check( 'switched off: no URL', '' === R7ePage::url( 'sommer-ab12' ) );
R7eSettings::update( array( 'event_pages' => 1 ) );

$r7e_type = make_type( 2, 10, 2490 );
$r7e_occ = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $r7e_type ) );
$r7e_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $r7e_occ ) );
$r7e_slug = 'r7e-' . strtolower( substr( md5( microtime() ), 0, 8 ) );
$wpdb->update( "{$p}wpcap_events", array( 'slug' => $r7e_slug, 'name' => 'Sommer </script> Fest', 'description' => '<p>Open Air</p>', 'image_url' => 'https://example.test/bild.jpg', 'category' => 'Konzert' ), array( 'event_id' => $r7e_event ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $r7e_occ, 'name' => 'Geheim', 'price_minor' => 100, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active', 'visibility' => 'hidden' ) );
$r7e_ev = R7ePage::event_by_slug( $r7e_slug );
check( 'published event is found by slug, unknown slug is not', $r7e_ev && (int) $r7e_ev->event_id === $r7e_event && null === R7ePage::event_by_slug( 'gibt-es-nicht-' . $r7e_slug ) );
$r7e_ld = $r7e_ev ? R7ePage::json_ld( $r7e_ev ) : array();
$r7e_item = $r7e_ld[0] ?? array();
check( 'JSON-LD: one schema.org Event with start (offset), status, place, image, category, URL', 'Event' === ( $r7e_item['@type'] ?? '' ) && preg_match( '/T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) ( $r7e_item['startDate'] ?? '' ) ) && 'https://schema.org/EventScheduled' === ( $r7e_item['eventStatus'] ?? '' ) && 'Wien' === ( $r7e_item['location']['name'] ?? '' ) && array( 'https://example.test/bild.jpg' ) === ( $r7e_item['image'] ?? null ) && 'Konzert' === ( $r7e_item['keywords'] ?? '' ) && 'https://example.test/event/' . $r7e_slug . '/' === ( $r7e_item['url'] ?? '' ) );
check( 'JSON-LD offers: only the public type, price as decimal, InStock', 1 === count( $r7e_item['offers'] ?? array() ) && '24.90' === (string) $r7e_item['offers'][0]['price'] && 'EUR' === $r7e_item['offers'][0]['priceCurrency'] && 'https://schema.org/InStock' === $r7e_item['offers'][0]['availability'] );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'capacity' => 1 ), array( 'ticket_type_id' => $r7e_type ) );
PassCaptain\Orders::create( array( 'email' => 'r7e@example.com', 'items' => array( array( 'ticket_type_id' => $r7e_type, 'quantity' => 1 ) ), 'idempotency_key' => 'r7e-' . $r7e_slug ), array( 'skip_rate_limit' => true ) );
$r7e_sold = R7ePage::json_ld( $r7e_ev );
check( 'sold out (held) → SoldOut', 'https://schema.org/SoldOut' === ( $r7e_sold[0]['offers'][0]['availability'] ?? '' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $r7e_occ ) );
$r7e_cancel = R7ePage::json_ld( $r7e_ev );
check( 'cancelled date → EventCancelled', 'https://schema.org/EventCancelled' === ( $r7e_cancel[0]['eventStatus'] ?? '' ) );
$r7e_json = wp_json_encode( $r7e_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
check( 'JSON-LD output cannot close the script element', false === strpos( $r7e_json, '</script>' ) );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'draft' ), array( 'event_id' => $r7e_event ) );
check( 'draft events have no page', null === R7ePage::event_by_slug( $r7e_slug ) );
R7eSettings::update( $r7e_before );
update_option( 'permalink_structure', $r7e_perma );
