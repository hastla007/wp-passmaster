<?php
/**
 * A681: the WooCommerce checkouts (classic and blocks) refuse participant data they cannot store –
 * unknown or deactivated fields, ticket types outside the order or without participant data, surplus
 * entries and malformed input – instead of dropping it silently. Error paths of both checkouts.
 * Uses woo_t_order() from modules/woocommerce.php. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\Integrations\WooCommerce\Attendees;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;

global $wpdb;
$p = $wpdb->prefix;

echo "A681: WooCommerce refuses unknown or deactivated participant fields\n";
$x6w_type = make_type( 10 );
$x6w_plain = make_type( 10 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $x6w_type, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $x6w_type, 'field_key' => 'alt', 'label' => 'Altes Feld', 'field_type' => 'text', 'required' => 0, 'status' => 'inactive' ) );
$x6w_q = array( $x6w_type => 2, $x6w_plain => 1 );
$x6w_good = array( $x6w_type => array( array( 'name' => 'Ada', 'answers' => array( 'firma' => 'ACME' ) ), array( 'answers' => array( 'firma' => 'Globex' ) ) ) );
$x6w_try = static function ( $raw ) use ( $x6w_q ) { return Attendees::normalize( $raw, $x6w_q ); };
$x6w_reason = static function ( $r ) { return is_wp_error( $r ) ? ( $r->get_error_data()['reason'] ?? '' ) : ''; };

check( 'A681: offered fields of ordered ticket types pass', is_array( $x6w_try( $x6w_good ) ) && 2 === count( $x6w_try( $x6w_good ) ) );
$x6w_raw = $x6w_good;
$x6w_raw[ $x6w_type ][0]['answers']['evil'] = 'x';
$x6w_r = $x6w_try( $x6w_raw );
check( 'A681: an unknown field key is refused (wpcap_attendee_field_unknown, field_unknown) and named', is_wp_error( $x6w_r ) && 'wpcap_attendee_field_unknown' === $x6w_r->get_error_code() && 'field_unknown' === $x6w_reason( $x6w_r ) && 'evil' === ( $x6w_r->get_error_data()['field_key'] ?? '' ) && 0 === ( $x6w_r->get_error_data()['position'] ?? null ) );
$x6w_raw = $x6w_good;
$x6w_raw[ $x6w_type ][1]['answers']['alt'] = 'alt';
$x6w_r = $x6w_try( $x6w_raw );
check( 'A681: a deactivated field is refused with its own reason (field_inactive, "nicht mehr abgefragt")', is_wp_error( $x6w_r ) && 'field_inactive' === $x6w_reason( $x6w_r ) && 1 === ( $x6w_r->get_error_data()['position'] ?? null ) && false !== strpos( $x6w_r->get_error_message(), 'nicht mehr' ) );
$x6w_r = $x6w_try( $x6w_good + array( $x6w_plain => array( array( 'name' => 'Bo' ) ) ) );
check( 'A681: data for a ticket type without participant data is refused (unknown_ticket_type)', is_wp_error( $x6w_r ) && 'wpcap_attendees_invalid' === $x6w_r->get_error_code() && 'unknown_ticket_type' === $x6w_reason( $x6w_r ) && $x6w_plain === ( $x6w_r->get_error_data()['ticket_type_id'] ?? 0 ) );
$x6w_r = $x6w_try( $x6w_good + array( 999999 => array( array( 'name' => 'X' ) ) ) );
check( 'A681: data for a ticket type outside the order is refused', 'unknown_ticket_type' === $x6w_reason( $x6w_r ) );
$x6w_raw = $x6w_good;
$x6w_raw[ $x6w_type ][] = array( 'answers' => array( 'firma' => 'surplus' ) );
$x6w_r = $x6w_try( $x6w_raw );
check( 'A681: more entries than tickets are refused (surplus)', is_wp_error( $x6w_r ) && 'surplus' === $x6w_reason( $x6w_r ) );
check( 'A681: malformed input (broken JSON, a string per ticket type) is refused', 'malformed' === $x6w_reason( $x6w_try( '{"broken' ) ) && 'malformed' === $x6w_reason( $x6w_try( array( $x6w_type => 'x' ) ) ) && 'malformed' === $x6w_reason( $x6w_try( array( $x6w_type => array( 'x' ) ) ) ) );
check( 'A681: an empty block payload stays valid when nothing is required', array() === Attendees::normalize( '', array( $x6w_plain => 1 ) ) );

echo "A681: error paths of the checkout block and the classic checkout\n";
$x6w_wc = woo_t_order( 7681, array( array( $x6w_type, 2, '50.00' ) ) );
$x6w_wc->update_meta_data( Woo::ATTENDEE_META, array( array( 'stale' => 1 ) ) );
$x6w_bad = $x6w_good;
$x6w_bad[ $x6w_type ][0]['answers']['evil'] = 'x';
$x6w_thrown = '';
try {
	Attendees::save_store_api( $x6w_wc, array( 'extensions' => array( 'wpcap' => array( 'attendees' => wp_json_encode( $x6w_bad ) ) ) ) );
} catch ( \Exception $e ) {
	$x6w_thrown = $e->getMessage();
}
check( 'A681: the checkout block refuses the order with the message and keeps no partial data', '' !== $x6w_thrown && false !== strpos( $x6w_thrown, 'unbekanntes Feld' ) && '' === $x6w_wc->get_meta( Woo::ATTENDEE_META ) );
Attendees::save_store_api( $x6w_wc, array( 'extensions' => array( 'wpcap' => array( 'attendees' => wp_json_encode( $x6w_good ) ) ) ) );
$x6w_saved = $x6w_wc->get_meta( Woo::ATTENDEE_META );
check( 'A681: valid block data is stored on the order with offered keys only', is_array( $x6w_saved ) && 2 === count( $x6w_saved ) && array( 'firma' => 'ACME' ) === $x6w_saved[0]['answers'] );

// Classic checkout: the posted data names tickets that are not in the cart (cart changed in another tab).
$_POST[ Attendees::POST_KEY ] = $x6w_good;
$x6w_errors = new WP_Error();
Attendees::validate_classic( array(), $x6w_errors );
check( 'A681: the classic checkout reports participant data that does not belong to the cart as a checkout error', 'wpcap_attendees' === $x6w_errors->get_error_code() && false !== strpos( (string) $x6w_errors->get_error_message(), 'neu' ) );
unset( $_POST[ Attendees::POST_KEY ] );
