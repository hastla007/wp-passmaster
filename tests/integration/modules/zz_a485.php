<?php
/**
 * A485: automatic seat assignment (box office, WooCommerce, adapters) handed out wheelchair spaces
 * first, and there were no companion seats. Now wheelchair spaces and companion seats are skipped by
 * automatic assignment, and a companion seat is only sold together with a wheelchair space.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Seating;
use PassCaptain\Orders;
use PassCaptain\Seating\Layouts;
use PassCaptain\Seating\Reservations;

if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { return true; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
Seating::register();
global $wpdb;
$p = $wpdb->prefix;

/** Event with a seat map (zone parkett: w1 wheelchair, c1 companion, s3, s4) and $occurrences linked occurrences. */
function a485_setup( $occurrences = 1 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Saalkonzert', 'slug' => 'a485' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$layout = Layouts::create( $event, 'Saal' );
	Layouts::save_draft( $layout['layout_id'], array( 'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett' ) ), 'categories' => array(), 'groups' => array( array( 'key' => 'r1', 'type' => 'row', 'label' => 'Reihe 1' ) ), 'seats' => array(
		array( 'key' => 'w1', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '1', 'x' => 10, 'y' => 10, 'zone' => 'parkett', 'accessible' => true ),
		array( 'key' => 'c1', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '2', 'x' => 40, 'y' => 10, 'zone' => 'parkett', 'companion' => true ),
		array( 'key' => 's3', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '3', 'x' => 70, 'y' => 10, 'zone' => 'parkett' ),
		array( 'key' => 's4', 'group' => 'r1', 'row' => 'Reihe 1', 'label' => '4', 'x' => 100, 'y' => 10, 'zone' => 'parkett' ),
	) ) );
	$published = Layouts::publish( $layout['layout_id'] );
	$out = array( 'event' => $event, 'version' => (int) $published['version_id'], 'types' => array(), 'occurrences' => array() );
	for ( $i = 0; $i < $occurrences; $i++ ) {
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + ( 7 + $i ) * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Parkett', 'price_minor' => 3000, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
		$type = (int) $wpdb->insert_id;
		Layouts::link_occurrence( $occ, $out['version'], array( $type => array( 'parkett' ) ) );
		$out['types'][] = $type;
		$out['occurrences'][] = $occ;
	}
	return $out;
}
function a485_pos( $type, $qty ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'kasse' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'a485-' . uniqid( '', true ) ), array( 'external' => true, 'source' => 'pos', 'key_namespace' => 'pos', 'skip_rate_limit' => true ) );
}
function a485_public( $type, array $seat_ids ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'gast' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => count( $seat_ids ) ) ), 'seats' => array( $type => $seat_ids ), 'idempotency_key' => 'a485-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
}
function a485_keys( $order ) {
	global $wpdb;
	return is_wp_error( $order ) ? array() : $wpdb->get_col( $wpdb->prepare( 'SELECT seat_key FROM ' . Layouts::table( 'claims' ) . ' WHERE order_id=%d AND active_key IS NOT NULL ORDER BY seat_key', $order['order_id'] ) );
}
function a485_seat( $version, $key ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seat_id FROM ' . Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s', $version, $key ) );
}

echo "A485: wheelchair spaces and companion seats\n";
$a485 = a485_setup( 3 );
check( 'A485: schema 3.10.18 column seats.is_companion exists and the flag is stored', Layouts::companion_ready() && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_companion FROM ' . Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s', $a485['version'], 'c1' ) ) );
$a485_o1 = a485_pos( $a485['types'][0], 2 );
check( 'A485: automatic assignment skips the wheelchair space and the companion seat', array( 's3', 's4' ) === a485_keys( $a485_o1 ) );
$a485_o2 = a485_pos( $a485['types'][0], 1 );
check( 'A485: with only special seats left, automatic assignment reports "no seats" instead of using them', is_wp_error( $a485_o2 ) );
$GLOBALS['wpcap_test_filters']['wpcap_seating_auto_assign_accessible'] = true;
$a485_o3 = a485_pos( $a485['types'][1], 3 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_seating_auto_assign_accessible'] );
check( 'A485: the filter allows them, but only after all other seats', array( 's3', 's4', 'w1' ) === a485_keys( $a485_o3 ) );
$a485_alone = a485_public( $a485['types'][2], array( a485_seat( $a485['version'], 'c1' ) ) );
check( 'A485: a companion seat alone is refused', is_wp_error( $a485_alone ) && false !== strpos( $a485_alone->get_error_message(), 'Begleitpl' ) );
$a485_pair = a485_public( $a485['types'][2], array( a485_seat( $a485['version'], 'w1' ), a485_seat( $a485['version'], 'c1' ) ) );
check( 'A485: wheelchair space plus companion seat can be booked together', array( 'c1', 'w1' ) === a485_keys( $a485_pair ) );
$a485_map = Reservations::availability( $a485['occurrences'][2] );
$a485_c = array_values( array_filter( $a485_map['seats'], static fn( $s ) => 'c1' === $s['seat'] || 'Reihe 1, Platz 2' === $s['label'] ) );
check( 'A485: the public seat map marks companion seats', $a485_c && true === $a485_c[0]['companion'] && true !== $a485_c[0]['accessible'] );
check( 'A485: a seat cannot be wheelchair space and companion seat at once', false === Layouts::normalize( array( 'zones' => array(), 'seats' => array( array( 'key' => 'x', 'label' => '1', 'accessible' => true, 'companion' => true ) ) ) )['seats'][0]['companion'] );
