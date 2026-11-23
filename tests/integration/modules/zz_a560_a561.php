<?php
/**
 * A560: the public checkout is bound to the state the buyer saw (items[].occurrence_revision,
 * items[].expected_price_minor): a rescheduled date or a changed price is refused with 409 and the
 * current state instead of silently selling the new one.
 * A561: REST /events names times as ISO 8601 with offset and reports sales status, window and
 * availability per ticket type; the response is not cacheable.
 */

// phpcs:ignoreFile

use PassCaptain\Calendar\Revisions;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Rest\Controller;
use PassCaptain\Settings;

if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }

global $wpdb;
$p = $wpdb->prefix;
$a560_settings = array( 'enabled_providers' => Settings::get( 'enabled_providers' ), 'require_terms' => Settings::get( 'require_terms' ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0 ) );

function a560_occ_of( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}
function a560_buy( $type, array $shown, $key ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'a560@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) + $shown ), 'idempotency_key' => $key . '-' . uniqid( '', true ) ) );
}

echo "A560: checkout bound to the displayed occurrence state\n";
$a560_type = make_type( 50, 10, 2500 );
$a560_occ = a560_occ_of( $a560_type );
$a560_rev = Revisions::current( $a560_occ );
Orders::flush_availability_cache();
$a560_html = PublicEvents::render_shortcode( array( 'occurrence' => $a560_occ ) );
// Merged card: further attributes (data-waitlist) may stand between occurrence and revision on the form tag.
check( 'A560: the checkout form carries the occurrence and its revision', (bool) preg_match( '/<form[^>]*data-occurrence="' . $a560_occ . '"[^>]*data-revision="' . $a560_rev . '"/', $a560_html ) && false !== strpos( $a560_html, 'data-price="2500"' ) );
$a560_ok = a560_buy( $a560_type, array( 'occurrence_revision' => $a560_rev, 'expected_price_minor' => 2500 ), 'a560-ok' );
check( 'A560: unchanged state → order', is_array( $a560_ok ) && ! empty( $a560_ok['order_id'] ) );

// The date is moved after the page was rendered.
$a560_new_start = gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $a560_new_start ), array( 'occurrence_id' => $a560_occ ) );
$a560_new_rev = Revisions::bump( $a560_occ );
$a560_moved = a560_buy( $a560_type, array( 'occurrence_revision' => $a560_rev, 'expected_price_minor' => 2500 ), 'a560-moved' );
$a560_data = is_wp_error( $a560_moved ) ? (array) $a560_moved->get_error_data() : array();
check( 'A560: a rescheduled date is refused with 409 occurrence_changed', is_wp_error( $a560_moved ) && 409 === ( $a560_data['status'] ?? 0 ) && 'occurrence_changed' === ( $a560_data['reason'] ?? '' ) );
check( 'A560: the refusal names the new date and carries the current state', false !== strpos( $a560_moved->get_error_message(), 'geändert' ) && $a560_new_rev === ( $a560_data['state']['revision'] ?? -1 ) && (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string) ( $a560_data['state']['starts_at'] ?? '' ) ) && 2500 === ( $a560_data['state']['prices'][ $a560_type ] ?? 0 ) );
check( 'A560: the refusal holds no capacity', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id=$a560_type AND o.status NOT IN ('cancelled','expired')" ) );
$a560_confirmed = a560_buy( $a560_type, array( 'occurrence_revision' => $a560_new_rev, 'expected_price_minor' => 2500 ), 'a560-confirmed' );
check( 'A560: submitting again with the new state buys the new date', is_array( $a560_confirmed ) && ! empty( $a560_confirmed['order_id'] ) );

$wpdb->update( "{$p}wpcap_ticket_types", array( 'price_minor' => 3000 ), array( 'ticket_type_id' => $a560_type ) );
$a560_price = a560_buy( $a560_type, array( 'occurrence_revision' => $a560_new_rev, 'expected_price_minor' => 2500 ), 'a560-price' );
$a560_pdata = is_wp_error( $a560_price ) ? (array) $a560_price->get_error_data() : array();
check( 'A560: a changed price is refused with 409 price_changed and the new price', is_wp_error( $a560_price ) && 'price_changed' === ( $a560_pdata['reason'] ?? '' ) && 3000 === ( $a560_pdata['state']['prices'][ $a560_type ] ?? 0 ) && false !== strpos( $a560_price->get_error_message(), '30,00' ) );
check( 'A560: callers that send no shown state are not bound (API, adapters)', is_array( a560_buy( $a560_type, array(), 'a560-api' ) ) );
check( 'A560: garbage in the state fields is ignored, never used as a price', is_array( $a560_g = a560_buy( $a560_type, array( 'occurrence_revision' => 'x', 'expected_price_minor' => array( 1 ) ), 'a560-garbage' ) ) && 3000 === (int) $a560_g['total_minor'] );

$a560_req = new WP_REST_Request( 'POST', '/pass-captain/v1/checkout' );
$a560_req->set_body( wp_json_encode( array( 'email' => 'a560-rest@example.com', 'provider' => 'manual', 'items' => array( array( 'ticket_type_id' => $a560_type, 'quantity' => 1, 'occurrence_revision' => $a560_rev, 'expected_price_minor' => 3000 ) ), 'idempotency_key' => 'a560-rest-' . uniqid() ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$a560_rest = Controller::checkout( $a560_req );
check( 'A560: REST checkout passes the 409 with reason and state through', is_wp_error( $a560_rest ) && 'occurrence_changed' === ( $a560_rest->get_error_data()['reason'] ?? '' ) && isset( $a560_rest->get_error_data()['state']['label'] ) );

echo "A561: REST /events with offsets, sales status and availability\n";
$a561_type = make_type( 1, 1, 1500 );
$a561_occ = a560_occ_of( $a561_type );
// The list holds the 100 next dates: a date starting soon is listed whatever else the test database holds.
$a561_start = gmdate( 'Y-m-d H:i:00', time() + 1800 );
$a561_end = gmdate( 'Y-m-d H:i:00', time() + 1800 + 3 * HOUR_IN_SECONDS );
$a561_presale = gmdate( 'Y-m-d H:i:00', time() + 900 );
$a561_iso = static fn( $utc ) => ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i:sP' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $a561_start, 'ends_at' => $a561_end ), array( 'occurrence_id' => $a561_occ ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a561_occ, 'name' => 'Später', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 5, 'status' => 'active', 'sales_start' => $a561_presale ) );
$a561_later = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a561_occ, 'name' => 'Vorbei', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 5, 'status' => 'active', 'sales_end' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
$a561_over = (int) $wpdb->insert_id;
$GLOBALS['wpcap_test_transients'] = array();
Orders::create( array( 'email' => 'a561@example.com', 'items' => array( array( 'ticket_type_id' => $a561_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a561-' . uniqid() ) );
Orders::flush_availability_cache();
$a561_res = Controller::events( new WP_REST_Request( 'GET' ) );
$a561_list = $a561_res instanceof WP_REST_Response ? $a561_res->get_data() : $a561_res;
$a561_row = array_values( array_filter( (array) $a561_list, static fn( $r ) => (int) $r['occurrence_id'] === $a561_occ ) )[0] ?? array();
$a561_tickets = array_column( (array) ( $a561_row['tickets'] ?? array() ), null, 'ticket_type_id' );
check( 'A561: start/end as ISO 8601 with the offset of the occurrence timezone', $a561_iso( $a561_start ) === ( $a561_row['start'] ?? '' ) && $a561_iso( $a561_end ) === ( $a561_row['end'] ?? '' ) && (bool) preg_match( '/[+-]0[12]:00$/', (string) ( $a561_row['start'] ?? '' ) ) && $a561_start === ( $a561_row['starts_at'] ?? '' ) );
check( 'A561: phase and revision of the occurrence', 'upcoming' === ( $a561_row['phase'] ?? '' ) && is_int( $a561_row['revision'] ?? null ) );
check( 'A561: sold-out type → sold_out, available 0', 'sold_out' === ( $a561_tickets[ $a561_type ]['sales_status'] ?? '' ) && 0 === ( $a561_tickets[ $a561_type ]['available'] ?? -1 ) );
check( 'A561: presale ahead → not_started with its window in local time', 'not_started' === ( $a561_tickets[ $a561_later ]['sales_status'] ?? '' ) && $a561_iso( $a561_presale ) === ( $a561_tickets[ $a561_later ]['sales_start'] ?? '' ) && null === $a561_tickets[ $a561_later ]['available'] );
check( 'A561: sales end passed → ended; online_until is the start without sales_end', 'ended' === ( $a561_tickets[ $a561_over ]['sales_status'] ?? '' ) && $a561_iso( $a561_start ) === ( $a561_tickets[ $a561_later ]['online_until'] ?? '' ) );
check( 'A561: Controller::sales_status() – unlimited type on sale', 'on_sale' === Controller::sales_status( array( 'sales_start' => null, 'sales_end' => null ), (object) array( 'starts_at' => '2027-07-01 18:00:00', 'ends_at' => null ), null, time() ) );

Settings::update( $a560_settings );
