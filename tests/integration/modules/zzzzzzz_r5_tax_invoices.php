<?php
/**
 * R5 (audit round 2, expert #10): tax date of supply, credit note lines per rate, accounting export
 * independent of invoices, consumer information / withdrawal notice, privacy notice, postponement
 * refund requests.
 *
 * Included from run.php (uses check(), make_type(), tickets()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Sales\TaxProfiles;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

/** Ticket type on an occurrence starting at $starts_utc (Europe/Vienna) of an event in $country. */
function r5_type_at( $starts_utc, $country, $price = 1190, $tax_class = '' ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'R5 Event', 'slug' => 'r5-' . uniqid(), 'description' => '', 'status' => 'published', 'tax_country' => $country, 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $starts_utc, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active', 'tax_class' => $tax_class, 'tax_rate_bp' => 0 ) );
	TaxProfiles::flush_cache();
	return (int) $wpdb->insert_id;
}
function r5_order( array $items, array $extra = array() ) {
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	return Orders::create( array_merge( array( 'email' => 'r5-' . uniqid() . '@example.com', 'items' => $lines, 'idempotency_key' => 'r5-' . uniqid( '', true ) ), $extra ), array( 'skip_rate_limit' => true ) );
}
function r5_item_rate( $order_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT tax_rate_bp FROM %i WHERE order_id=%d ORDER BY item_id LIMIT 1', $wpdb->prefix . 'wpcap_order_items', $order_id ) );
}

echo "R5: tax rate by date of supply (local occurrence date)\n";
// PT: 19 % until 31.12. of next year, 7 % from 1.1. (a rate change between purchase and event).
$r5_year = (int) gmdate( 'Y' ) + 1;
$r5_old = TaxProfiles::create( array( 'country_code' => 'PT', 'rate' => '19', 'version' => 'r5-old', 'effective_from' => '2020-01-01', 'effective_until' => $r5_year . '-12-31' ) );
$r5_new = TaxProfiles::create( array( 'country_code' => 'PT', 'rate' => '7', 'version' => 'r5-new', 'effective_from' => ( $r5_year + 1 ) . '-01-01' ) );
TaxProfiles::activate( $r5_old );
TaxProfiles::activate( $r5_new );
// 1.1. 00:30 Vienna = 31.12. 23:30 UTC: the service happens on 1.1. (new rate).
$r5_after = r5_type_at( $r5_year . '-12-31 23:30:00', 'PT' );
// 31.12. 23:30 Vienna = 22:30 UTC: still the old rate.
$r5_before = r5_type_at( $r5_year . '-12-31 22:30:00', 'PT' );
$r5_o1 = r5_order( array( $r5_after => 1 ) );
$r5_o2 = r5_order( array( $r5_before => 1 ) );
check( 'R5-1: rate change between purchase and event – the rate at the event date applies (7 %)', ! is_wp_error( $r5_o1 ) && 700 === r5_item_rate( $r5_o1['order_id'] ) );
check( 'R5-1: midnight case – 00:30 local time on 1.1. (23:30 UTC) is 1.1., 23:30 local on 31.12. stays 19 %', ! is_wp_error( $r5_o2 ) && 1900 === r5_item_rate( $r5_o2['order_id'] ) );
$r5_snap = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT line_snapshot FROM %i WHERE order_id=%d', $p . 'wpcap_order_items', $r5_o1['order_id'] ) ), true );
check( 'R5-1: the line snapshot keeps the local date of supply', ( $r5_year + 1 ) . '-01-01' === ( $r5_snap['tax']['date'] ?? '' ) );
check( 'R5-1: TaxProfiles::local_date converts UTC to the occurrence time zone', '2027-01-01' === TaxProfiles::local_date( '2026-12-31 23:30:00', (object) array( 'timezone' => 'Europe/Vienna' ) ) && '' === TaxProfiles::local_date( '0000-00-00 00:00:00' ) );
$r5_filter = static function ( $date, $type, $occurrence, $order_date = '' ) { return $order_date; };
add_filter( 'wpcap_tax_profile_date', $r5_filter, 10, 4 );
$r5_o3 = r5_order( array( $r5_after => 1 ) );
remove_filter( 'wpcap_tax_profile_date', $r5_filter, 10 );
check( 'R5-1: filter wpcap_tax_profile_date can switch back to the (local) order date', ! is_wp_error( $r5_o3 ) && 1900 === r5_item_rate( $r5_o3['order_id'] ) );
