<?php
/**
 * A576: the checkout carries the unit prices the buyer was shown (`expected_prices`). If a price rose
 * in the meantime (early bird ended, demand tier, cached page), the order is refused with 409
 * wpcap_price_changed and the new prices – nothing is booked. A lower price is charged as is;
 * without `expected_prices` (API clients) nothing changes. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;

echo "A576: price snapshot at checkout\n";
$a576_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A576 Gala', 'slug' => 'a576-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a576_now, 'updated_at' => $a576_now ) );
$a576_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a576_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
$a576_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a576_occ, 'name' => 'Parkett', 'price_minor' => 3000, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$a576_type = (int) $wpdb->insert_id;

// The page showed 30,00 €; meanwhile the early-bird price ended and the type costs 35,00 €.
$a576_rise = static function ( $price, $type ) use ( $a576_type ) { return (int) $type->ticket_type_id === $a576_type ? 3500 : $price; };
add_filter( 'wpcap_ticket_unit_price', $a576_rise, 10, 3 );
Orders::flush_availability_cache();
$a576_before = Orders::availability( $a576_type );
$a576_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
$a576_err = Orders::create( array( 'email' => 'a576@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 2 ) ), 'expected_prices' => array( (string) $a576_type => 3000 ), 'idempotency_key' => 'a576-1' ) );
$a576_data = is_wp_error( $a576_err ) ? $a576_err->get_error_data() : array();
check( 'A576 a higher price than shown is refused with 409 wpcap_price_changed', is_wp_error( $a576_err ) && 'wpcap_price_changed' === $a576_err->get_error_code() && 409 === ( $a576_data['status'] ?? 0 ) && 'price_changed' === ( $a576_data['reason'] ?? '' ) );
check( 'A576 the error names the new price and carries it per ticket type', is_wp_error( $a576_err ) && false !== strpos( $a576_err->get_error_message(), '35,00' ) && 3500 === ( $a576_data['prices'][ $a576_type ]['unit_minor'] ?? 0 ) && 3000 === ( $a576_data['prices'][ $a576_type ]['shown_minor'] ?? 0 ) && 'items' === ( $a576_data['field'] ?? '' ) );
Orders::flush_availability_cache();
check( 'A576 nothing is booked: no order, no hold', $a576_orders === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) && $a576_before === Orders::availability( $a576_type ) );

$a576_ok = Orders::create( array( 'email' => 'a576@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 2 ) ), 'expected_prices' => array( $a576_type => 3500 ), 'idempotency_key' => 'a576-2' ) );
check( 'A576 with the current price the order is created at that price', ! is_wp_error( $a576_ok ) && 7000 === (int) $a576_ok['total_minor'] );
$a576_cheaper = Orders::create( array( 'email' => 'a576b@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 1 ) ), 'expected_prices' => array( $a576_type => 4000 ), 'idempotency_key' => 'a576-3' ) );
check( 'A576 a lower price than shown is charged as is', ! is_wp_error( $a576_cheaper ) && 3500 === (int) $a576_cheaper['total_minor'] );
$a576_plain = Orders::create( array( 'email' => 'a576c@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a576-4' ) );
check( 'A576 without expected_prices (API clients) nothing changes', ! is_wp_error( $a576_plain ) && 3500 === (int) $a576_plain['total_minor'] );
$a576_junk = Orders::create( array( 'email' => 'a576d@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 1 ) ), 'expected_prices' => array( $a576_type => '-1', 'x' => 5, $a576_type + 99 => 1 ), 'idempotency_key' => 'a576-5' ) );
check( 'A576 invalid entries are ignored', ! is_wp_error( $a576_junk ) );
// Trusted channels (box office, WooCommerce) set the price themselves: no check.
$a576_pos = Orders::create( array( 'email' => 'a576e@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 1 ) ), 'expected_prices' => array( $a576_type => 100 ), 'idempotency_key' => 'a576-6' ), array( 'external' => true, 'unit_prices' => array( $a576_type => 2000 ), 'currency' => 'EUR', 'source' => 'woocommerce', 'key_namespace' => 'woocommerce' ) );
check( 'A576 trusted channels with their own price are not checked', ! is_wp_error( $a576_pos ) );

// Public REST checkout passes expected_prices through.
if ( class_exists( 'WP_REST_Request' ) ) {
	$a576_req = new WP_REST_Request( 'POST' );
	$a576_req->set_body( wp_json_encode( array( 'email' => 'a576f@example.com', 'items' => array( array( 'ticket_type_id' => $a576_type, 'quantity' => 1 ) ), 'provider' => 'manual', 'expected_prices' => array( (string) $a576_type => 3000 ), 'idempotency_key' => 'a576-7' ) ) );
	$a576_rest = PassCaptain\Rest\Controller::checkout( $a576_req );
	check( 'A576 REST checkout: expected_prices reach the order (409 price_changed)', is_wp_error( $a576_rest ) && 'wpcap_price_changed' === $a576_rest->get_error_code() );
}
remove_filter( 'wpcap_ticket_unit_price', $a576_rise, 10 );
foreach ( array( $a576_ok, $a576_cheaper, $a576_plain, $a576_junk, $a576_pos ) as $a576_o ) {
	if ( ! is_wp_error( $a576_o ) ) { Orders::cancel_order( $a576_o['order_id'], 'a576_cleanup' ); }
}
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a576_done' ), array( 'occurrence_id' => $a576_occ ) );
