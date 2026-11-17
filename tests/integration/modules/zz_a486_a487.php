<?php
/**
 * A486: availability of a seated ticket type ignored the seat map (quota 10, 3 matching seats →
 * "10 available", the 4th buyer failed at checkout). A487: publishing a new version without a zone a
 * ticket type was mapped to moved the linked occurrences silently – the type became unsellable.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Seating;
use PassCaptain\Orders;
use PassCaptain\Seating\Layouts;

if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
Seating::register();
global $wpdb;
$p = $wpdb->prefix;

$a486_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Theater', 'slug' => 'a486' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a486_now, 'updated_at' => $a486_now ) );
$a486_ev = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a486_ev, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$a486_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a486_occ, 'name' => 'Parkett', 'price_minor' => 3000, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$a486_parkett = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a486_occ, 'name' => 'Loge', 'price_minor' => 5000, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
$a486_loge = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a486_occ, 'name' => 'Stehplatz ohne Plan', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$a486_free = (int) $wpdb->insert_id;
$a486_draft = array( 'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett' ), array( 'key' => 'loge', 'name' => 'Loge' ) ), 'categories' => array(), 'groups' => array( array( 'key' => 'r1', 'type' => 'row', 'label' => 'Reihe 1' ) ), 'seats' => array(
	array( 'key' => 'p1', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '1', 'x' => 10, 'y' => 10, 'zone' => 'parkett' ),
	array( 'key' => 'p2', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '2', 'x' => 40, 'y' => 10, 'zone' => 'parkett' ),
	array( 'key' => 'p3', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '3', 'x' => 70, 'y' => 10, 'zone' => 'parkett' ),
	array( 'key' => 'l1', 'group' => 'r1', 'row' => 'Loge', 'label' => '1', 'x' => 100, 'y' => 10, 'zone' => 'loge' ),
) );
$a486_layout = Layouts::create( $a486_ev, 'Saal' );
Layouts::save_draft( $a486_layout['layout_id'], $a486_draft );
$a486_v1 = Layouts::publish( $a486_layout['layout_id'] );

echo "A486: availability of seated ticket types follows the seat map\n";
$a486_link = Layouts::link_occurrence( $a486_occ, $a486_v1['version_id'], array( $a486_parkett => array( 'parkett' ), $a486_loge => array( 'loge' ) ) );
check( 'A486: linking warns that the quota (10) exceeds the 3 matching seats', ! is_wp_error( $a486_link ) && false !== strpos( (string) ( $a486_link['warning'] ?? '' ), 'Parkett' ) && false !== strpos( (string) $a486_link['warning'], '3' ) );
Orders::flush_availability_cache();
check( 'A486: availability = free matching seats (3, not the quota 10); unlimited Loge = 1 seat', 3 === Orders::availability( $a486_parkett ) && 1 === Orders::availability( $a486_loge ) );
check( 'A486: ticket types without a mapping keep their own quota', 50 === Orders::availability( $a486_free ) );
$GLOBALS['wpcap_test_transients'] = array();
$a486_o = Orders::create( array( 'email' => 'kasse@example.com', 'items' => array( array( 'ticket_type_id' => $a486_parkett, 'quantity' => 2 ) ), 'idempotency_key' => 'a486-' . uniqid() ), array( 'external' => true, 'source' => 'pos', 'key_namespace' => 'pos', 'skip_rate_limit' => true ) );
Orders::flush_availability_cache();
check( 'A486: held seats lower the availability (1 left)', ! is_wp_error( $a486_o ) && 1 === Orders::availability( $a486_parkett ) );

echo "A487: a new version without a mapped zone is not published silently\n";
$a487_draft = $a486_draft;
$a487_draft['zones'] = array( $a486_draft['zones'][0] );
$a487_draft['seats'][3]['zone'] = 'parkett';
Layouts::save_draft( $a486_layout['layout_id'], $a487_draft );
$a487_pub = Layouts::publish( $a486_layout['layout_id'], true );
check( 'A487: publishing with "move linked occurrences" is refused and names the ticket type', is_wp_error( $a487_pub ) && 'wpcap_seating_mapping_lost' === $a487_pub->get_error_code() && false !== strpos( $a487_pub->get_error_message(), 'Loge' ) );
check( 'A487: the occurrence stays on version 1 and no version 2 exists', (int) $a486_v1['version_id'] === (int) Layouts::link( $a486_occ )->version_id && 1 === count( Layouts::versions( $a486_layout['layout_id'] ) ) );
$a487_plain = Layouts::publish( $a486_layout['layout_id'], false );
check( 'A487: without moving the occurrences the version is published', ! is_wp_error( $a487_plain ) && 2 === (int) $a487_plain['version'] && (int) $a486_v1['version_id'] === (int) Layouts::link( $a486_occ )->version_id );
$a487_relink = Layouts::link_occurrence( $a486_occ, $a487_plain['version_id'], array( $a486_parkett => array( 'parkett' ), $a486_loge => array( 'parkett' ) ) );
check( 'A487: the occurrence can then be linked with a new mapping', ! is_wp_error( $a487_relink ) && (int) $a487_plain['version_id'] === (int) $a487_relink['version_id'] );
$a487_empty = Layouts::link_occurrence( $a486_occ, $a487_plain['version_id'], array( $a486_parkett => array( 'parkett' ), $a486_loge => array( 'loge' ) ) );
check( 'A487: mapping a zone that the version lacks is still refused at link time', is_wp_error( $a487_empty ) );
