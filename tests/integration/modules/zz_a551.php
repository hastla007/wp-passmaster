<?php
/**
 * A551: attendee errors say which attendee is meant. The server adds ticket_type_id, position,
 * index and field_key to the error data (kept by Controller::with_error_field()); the checkout form
 * marks that block's field and prefixes the legend (tests/js/checkout.test.js). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;

echo "A551: attendee errors name the attendee\n";

$a551_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A551 Lauf', 'slug' => 'a551-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a551_now, 'updated_at' => $a551_now ) );
$a551_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a551_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'capacity' => 0, 'status' => 'scheduled' ) );
$a551_occ = (int) $wpdb->insert_id;
$a551_types = array();
foreach ( array( 'Kinder', 'Erwachsene' ) as $a551_name ) {
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a551_occ, 'name' => $a551_name, 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
	$a551_types[ $a551_name ] = (int) $wpdb->insert_id;
}
$a551_adult = $a551_types['Erwachsene'];
$a551_child = $a551_types['Kinder'];
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a551_adult, 'field_key' => 'club', 'label' => 'Verein', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a551_adult, 'field_key' => 'mail2', 'label' => 'Notfall-E-Mail', 'field_type' => 'email', 'required' => 0, 'status' => 'active' ) );

$a551_lines = array( $a551_child => 1, $a551_adult => 3 );
// Form order: child first, then three adults; the third adult (list index 3) misses "Verein".
$a551_list = array(
	array( 'ticket_type_id' => $a551_child, 'name' => 'Kim', 'answers' => array() ),
	array( 'ticket_type_id' => $a551_adult, 'name' => 'A', 'answers' => array( 'club' => 'SV' ) ),
	array( 'ticket_type_id' => $a551_adult, 'name' => 'B', 'answers' => array( 'club' => 'SV' ) ),
	array( 'ticket_type_id' => $a551_adult, 'name' => 'C', 'answers' => array( 'club' => '' ) ),
);
$a551_err = Orders::validate_attendees( $a551_lines, $a551_list );
$a551_data = is_wp_error( $a551_err ) ? (array) $a551_err->get_error_data() : array();
check( 'A551 missing required field: error data names type, position, list index and field', is_wp_error( $a551_err ) && 'wpcap_attendee_field_required' === $a551_err->get_error_code() && $a551_adult === ( $a551_data['ticket_type_id'] ?? null ) && 2 === ( $a551_data['position'] ?? null ) && 3 === ( $a551_data['index'] ?? null ) && 'club' === ( $a551_data['field_key'] ?? null ) && 400 === ( $a551_data['status'] ?? null ) );

$a551_rest = Controller::with_error_field( $a551_err );
$a551_rest_data = (array) $a551_rest->get_error_data();
check( 'A551 REST error keeps the attendee data next to field=attendees', 'attendees' === ( $a551_rest_data['field'] ?? '' ) && 3 === ( $a551_rest_data['index'] ?? null ) && 'club' === ( $a551_rest_data['field_key'] ?? '' ) );

// Fewer entries than tickets: the missing attendee has no list index, only type and position.
$a551_short = Orders::validate_attendees( $a551_lines, array_slice( $a551_list, 0, 2 ) );
$a551_short_data = is_wp_error( $a551_short ) ? (array) $a551_short->get_error_data() : array();
check( 'A551 attendee not sent at all: position without index', is_wp_error( $a551_short ) && 1 === ( $a551_short_data['position'] ?? null ) && ! isset( $a551_short_data['index'] ) && $a551_adult === ( $a551_short_data['ticket_type_id'] ?? null ) );

$a551_list[3]['answers'] = array( 'club' => 'SV', 'mail2' => 'kein-mail' );
$a551_bad = Orders::validate_attendees( $a551_lines, $a551_list );
$a551_bad_data = is_wp_error( $a551_bad ) ? (array) $a551_bad->get_error_data() : array();
check( 'A551 invalid e-mail field: same attendee data', is_wp_error( $a551_bad ) && 'wpcap_attendee_field_invalid' === $a551_bad->get_error_code() && 3 === ( $a551_bad_data['index'] ?? null ) && 'mail2' === ( $a551_bad_data['field_key'] ?? null ) );

$a551_list[3]['answers'] = array( 'club' => 'SV' );
check( 'A551 valid data still passes', is_array( Orders::validate_attendees( $a551_lines, $a551_list ) ) );

$a551_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'A551 checkout form uses data.index / ticket_type_id + position / field_key and prefixes the legend', false !== strpos( $a551_js, 'Number.isInteger(info.index)' ) && false !== strpos( $a551_js, 'info.field_key' ) && false !== strpos( $a551_js, "t.attendeeError || '%1\$s: %2\$s'" ) );
