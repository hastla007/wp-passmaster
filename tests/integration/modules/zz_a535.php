<?php
/**
 * A535: multi-day occurrences. One central definition of "started / running / over"
 * (Occurrence::effective_end() = COALESCE(ends_at, starts_at + default duration)) for online and
 * door sales, the public listing, the web scanner and app date lists. Day 2 and 3 of a festival stay
 * sellable (with a sales_end after the start) and scannable with a date check.
 * Included from run.php (uses check(), make_type(), app_call()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Checkin;
use PassCaptain\Mobile\Devices;
use PassCaptain\Occurrence;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); } }

echo "A535: multi-day occurrences (effective end, online/door sales, listing, scanner lists)\n";
Settings::update( array( 'require_terms' => 0, 'enabled_providers' => array( 'manual' ), 'occurrence_default_duration_hours' => 6 ) );
$a535_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
$GLOBALS['wpcap_test_user_id'] = 1;

// --- Central definition -------------------------------------------------------------------------
$a535_t = time();
$a535_row = (object) array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a535_t - 3600 ), 'ends_at' => null );
check( 'A535: without ends_at the effective end is start + default duration (6 h)', Occurrence::effective_end( $a535_row ) === gmdate( 'Y-m-d H:i:s', $a535_t - 3600 + 6 * 3600 ) && Occurrence::is_running( $a535_row ) && ! Occurrence::is_over( $a535_row ) );
Settings::update( array( 'occurrence_default_duration_hours' => 1 ) );
check( 'A535: the default duration is configurable (setting)', Occurrence::is_over( $a535_row, $a535_t + 60 ) && 3600 === Occurrence::default_duration() );
Settings::update( array( 'occurrence_default_duration_hours' => 6 ) );
$a535_row->ends_at = gmdate( 'Y-m-d H:i:s', $a535_t + 2 * DAY_IN_SECONDS );
check( 'A535: ends_at wins (multi-day occurrence runs until its end)', Occurrence::effective_end( $a535_row ) === $a535_row->ends_at && Occurrence::is_running( $a535_row, $a535_t + DAY_IN_SECONDS ) );
$a535_sql_end = $wpdb->get_var( $wpdb->prepare( 'SELECT ' . Occurrence::end_sql( 'x' ) . ' FROM (SELECT CAST(%s AS DATETIME) AS starts_at, CAST(NULL AS DATETIME) AS ends_at) x', gmdate( 'Y-m-d H:i:s', $a535_t ) ) );
check( 'A535: PHP and SQL use the same effective end', $a535_sql_end === gmdate( 'Y-m-d H:i:s', $a535_t + 6 * 3600 ) );

// --- Festival: started yesterday, ends in two days -----------------------------------------------
$a535_day2 = make_type( 50, 10, 4900 );
$a535_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a535_day2" );
$a535_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a535_occ" );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'A535 Festival' ), array( 'event_id' => $a535_event ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a535_t - 26 * 3600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $a535_t + 2 * DAY_IN_SECONDS ) ), array( 'occurrence_id' => $a535_occ ) );
// Day ticket for day 2: sales end tonight (after the start); the 3-day pass has no sales_end (= until the start).
$wpdb->update( "{$p}wpcap_ticket_types", array( 'name' => 'A535 Tag 2', 'sales_end' => gmdate( 'Y-m-d H:i:s', $a535_t + 8 * 3600 ) ), array( 'ticket_type_id' => $a535_day2 ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a535_occ, 'name' => 'A535 Festivalpass', 'price_minor' => 9900, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$a535_pass = (int) $wpdb->insert_id;
$a535_buy = static function ( $type, array $internal = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'a535@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'a535-' . uniqid( '', true ) ), $internal );
};
$a535_o2 = $a535_buy( $a535_day2 );
check( 'A535: a day ticket for day 2 is sold online after the start (sales_end after the start)', ! is_wp_error( $a535_o2 ) );
$a535_op = $a535_buy( $a535_pass );
check( 'A535: a ticket type without sales_end still ends at the start', is_wp_error( $a535_op ) && 'occurrence_unavailable' === ( $a535_op->get_error_data()['reason'] ?? '' ) );

// --- Listing -------------------------------------------------------------------------------------
Orders::flush_availability_cache();
$a535_html = PublicEvents::render_shortcode( array( 'occurrence' => $a535_occ ) );
check( 'A535: the running festival is listed with the ticket type still on sale only', false !== strpos( $a535_html, 'A535 Tag 2' ) && false === strpos( $a535_html, 'A535 Festivalpass' ) );
$a535_rest = PassCaptain\Rest\Controller::events( new WP_REST_Request( 'GET' ) );
$a535_rest = $a535_rest instanceof WP_REST_Response ? $a535_rest->get_data() : $a535_rest;
check( 'A535: the REST event list contains the running festival', false !== strpos( wp_json_encode( $a535_rest ), '"occurrence_id":' . $a535_occ ) || false !== strpos( wp_json_encode( $a535_rest ), '"occurrence_id":"' . $a535_occ . '"' ) );

// --- Scanner lists, check-in with date check on day 2 ---------------------------------------------
$a535_items = PublicEvents::scanner_occurrences();
check( 'A535: the web scanner still offers the festival on day 2 (started > 24 h ago)', (bool) array_filter( $a535_items, static fn( $i ) => (int) $i['id'] === $a535_occ ) );
$a535_pairing = Devices::create_pairing( array( $a535_event ), 'A535 Tor', 12 );
$a535_device = Devices::pair( $a535_pairing['code'], 'A535 Gerät', 'ios', '1.0.0' );
$a535_app = app_call( 'occurrences', 'GET', $a535_device['token'] ?? '' );
check( 'A535: the app still lists the festival on day 2', is_array( $a535_app ) && (bool) array_filter( (array) ( $a535_app['occurrences'] ?? array() ), static fn( $o ) => (int) $o['occurrence_id'] === $a535_occ ) );
Orders::confirm_external_payment( $a535_o2['order_id'], 'a535' );
$a535_code = ticket_code_for( $a535_o2['order_id'] )[0] ?? '';
$a535_scan = Checkin::perform( $a535_code, array( 'idempotency_key' => 'a535-scan', 'occurrence_id' => $a535_occ ) );
check( 'A535: the ticket is admitted on day 2 with the date check', ! is_wp_error( $a535_scan ) );

// --- Over: sales_end after the end, door sales ---------------------------------------------------
$a535_old = make_type( 10, 10, 1000 );
$a535_old_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a535_old" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a535_t - 3 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $a535_t - 3600 ) ), array( 'occurrence_id' => $a535_old_occ ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_end' => gmdate( 'Y-m-d H:i:s', $a535_t + DAY_IN_SECONDS ) ), array( 'ticket_type_id' => $a535_old ) );
$a535_late = $a535_buy( $a535_old );
check( 'A535: a sales_end after the end does not sell past the end', is_wp_error( $a535_late ) && 'occurrence_unavailable' === ( $a535_late->get_error_data()['reason'] ?? '' ) );

$a535_door = make_type( 10, 10, 0 );
$a535_door_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a535_door" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a535_t - 7 * 3600 ), 'ends_at' => null ), array( 'occurrence_id' => $a535_door_occ ) );
$a535_d1 = $a535_buy( $a535_door, array( 'allow_door_sales' => true, 'external' => true, 'source' => 'admin' ) );
Settings::update( array( 'occurrence_default_duration_hours' => 12 ) );
$a535_d2 = $a535_buy( $a535_door, array( 'allow_door_sales' => true, 'external' => true, 'source' => 'admin' ) );
Settings::update( array( 'occurrence_default_duration_hours' => 6 ) );
check( 'A535: door sales without ends_at follow the configurable default duration', is_wp_error( $a535_d1 ) && ! is_wp_error( $a535_d2 ) );

// --- Admin warning --------------------------------------------------------------------------------
$a535_saved = EventsPage::save_ticket_type( array( 'ticket_type_id' => $a535_day2, 'name' => 'A535 Tag 2', 'currency' => 'EUR', 'price' => '49', 'tax_rate' => '0', 'max_per_order' => 10, 'capacity' => 50, 'status' => 'active', 'sales_end' => wp_date( 'Y-m-d\TH:i', $a535_t + 5 * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Vienna' ) ) ) );
check( 'A535: saving a sales_end after the end warns', is_array( $a535_saved ) && ! empty( $a535_saved['warning'] ) );
$a535_ok = EventsPage::save_ticket_type( array( 'ticket_type_id' => $a535_day2, 'name' => 'A535 Tag 2', 'currency' => 'EUR', 'price' => '49', 'tax_rate' => '0', 'max_per_order' => 10, 'capacity' => 50, 'status' => 'active', 'sales_end' => wp_date( 'Y-m-d\TH:i', $a535_t + DAY_IN_SECONDS, new DateTimeZone( 'Europe/Vienna' ) ) ) );
check( 'A535: a sales_end within the occurrence saves without warning', is_array( $a535_ok ) && empty( $a535_ok['warning'] ) );

// Finished: the festival dates must not show up in later tests' listings.
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY), ends_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY) WHERE occurrence_id IN ($a535_occ,$a535_door_occ)" );
$GLOBALS['wpcap_test_user_id'] = $a535_user;
