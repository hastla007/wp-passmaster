<?php
// phpcs:ignoreFile
/**
 * A599: an entered but invalid attendee e-mail is refused with 400 (wpcap_attendee_field_invalid,
 * field "attendees", ticket_type_id/position/index and field_key "__email" for the checkout script)
 * instead of being dropped silently – the ticket would have gone to the buyer only.
 */
use PassCaptain\Orders;

echo "A599: invalid attendee e-mail\n";
global $wpdb;
$a599_type = make_type( 10 );
$a599_order = static function ( $email, $key ) use ( $a599_type ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'buyer599@example.com', 'items' => array( array( 'ticket_type_id' => $a599_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a599-' . $key . '-' . uniqid(), 'attendees' => array(
		array( 'ticket_type_id' => $a599_type, 'name' => 'Erste', 'email' => 'erste@example.com' ),
		array( 'ticket_type_id' => $a599_type, 'name' => 'Zweite', 'email' => $email ),
	) ) );
};
$a599_bad = $a599_order( 'zweite(at)example', 'bad' );
$a599_data = is_wp_error( $a599_bad ) ? (array) $a599_bad->get_error_data() : array();
check( 'A599 an invalid attendee e-mail is refused', is_wp_error( $a599_bad ) && 'wpcap_attendee_field_invalid' === $a599_bad->get_error_code() && 400 === ( $a599_data['status'] ?? 0 ) );
check( 'A599 the error names the block and the field (position 1, index 1, field_key __email)', $a599_type === ( $a599_data['ticket_type_id'] ?? 0 ) && 1 === ( $a599_data['position'] ?? null ) && 1 === ( $a599_data['index'] ?? null ) && '__email' === ( $a599_data['field_key'] ?? '' ) && 'email_invalid' === ( $a599_data['reason'] ?? '' ) );
check( 'A599 the message names the field', is_wp_error( $a599_bad ) && false !== strpos( $a599_bad->get_error_message(), '„E-Mail der Teilnehmer:in“ ist keine gültige E-Mail-Adresse.' ) );
$a599_ok = $a599_order( '', 'empty' );
check( 'A599 an empty attendee e-mail stays optional', ! is_wp_error( $a599_ok ) );
$a599_valid = $a599_order( ' zweite@example.com ', 'valid' );
check( 'A599 a valid address (with spaces around it) is accepted', ! is_wp_error( $a599_valid ) );
$a599_ctrl = (string) file_get_contents( WPCAP_PATH . 'src/Rest/Controller.php' );
check( 'A599 the REST checkout maps the error to field "attendees"', false !== strpos( $a599_ctrl, "'wpcap_attendee_field_invalid' => 'attendees'" ) );
