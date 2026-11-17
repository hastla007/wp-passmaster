<?php
/**
 * A494: free text is limited by characters (Text::limit), never by bytes – a byte cut through a
 * multi-byte character left invalid UTF-8 that the database refused on every retry (refund reason,
 * portal cancellation request, rejection note, partner data).
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Text;

global $wpdb;
$p = $wpdb->prefix;

echo "A494: character limits instead of byte cuts\n";
$a494_text = str_repeat( 'Café – ok ', 30 ); // 300 characters, 2- and 3-byte characters at every boundary.
$a494_cut = Text::limit( $a494_text, 255 );
check( 'A494: Text::limit cuts characters and keeps valid UTF-8', 255 === mb_strlen( $a494_cut ) && mb_check_encoding( $a494_cut, 'UTF-8' ) && ! mb_check_encoding( substr( $a494_text, 0, 255 ), 'UTF-8' ) );
check( 'A494: Text::limit repairs invalid input, short text unchanged, arrays become ""', mb_check_encoding( Text::limit( "Caf\xC3", 10 ), 'UTF-8' ) && 'Grüße 😀' === Text::limit( 'Grüße 😀', 190 ) && '' === Text::limit( array( 'x' ), 10 ) && '' === Text::limit( 'abc', 0 ) );

$a494_saved = get_option( PassCaptain\Settings::OPTION, array() );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
$a494_type = make_type( 20, 10, 2500 );
$a494_paid = static function ( $key ) use ( $a494_type ) {
	global $wpdb;
	$o = Orders::create( array( 'email' => $key . '@example.com', 'items' => array( array( 'ticket_type_id' => $a494_type, 'quantity' => 1 ) ), 'idempotency_key' => $key ) );
	Orders::confirm_manual_payment( $o['order_id'] );
	return array( (int) $o['order_id'], (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", $o['order_id'] ) ) );
};

// Portal request: 200 characters (260 bytes) – the byte cut at 255 split an "é".
list( $a494_o, $a494_t ) = $a494_paid( 'a494-portal-' . uniqid() );
$a494_reason = mb_substr( str_repeat( 'Café – ok ', 20 ), 0, 200 );
$r = Refunds::request( $a494_o, array( $a494_t ), $a494_reason, 'portal|a494-' . uniqid() . '-0123456789', array( 'system' => true, 'require_approval' => true, 'source' => 'portal' ) );
$a494_stored = is_wp_error( $r ) ? '' : (string) $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$p}wpcap_refunds WHERE refund_id=%d", $r['refund_id'] ) );
check( 'A494: refund request with a long multi-byte reason is saved (not 503 db_busy)', ! is_wp_error( $r ) && 'pending_approval' === $r['status'] && trim( $a494_reason ) === $a494_stored );

// Reason with the admission exception appended: longer than the column, cut to 255 characters.
list( $a494_o2, $a494_t2 ) = $a494_paid( 'a494-long-' . uniqid() );
$r2 = Refunds::request( $a494_o2, array( $a494_t2 ), str_repeat( 'Ärger über Überbuchung ', 20 ), 'a494-long-' . uniqid(), array( 'require_approval' => true ) );
$a494_stored2 = is_wp_error( $r2 ) ? '' : (string) $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$p}wpcap_refunds WHERE refund_id=%d", $r2['refund_id'] ) );
check( 'A494: a reason longer than 255 characters is cut to 255 characters', ! is_wp_error( $r2 ) && 255 === mb_strlen( $a494_stored2 ) && mb_check_encoding( $a494_stored2, 'UTF-8' ) );

// Rejection note (error_message varchar(255)).
$GLOBALS['wpcap_test_user_id'] = 2;
$rej = Refunds::reject( $r2['refund_id'] ?? 0, str_repeat( 'Nicht möglich – ', 30 ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'A494: a long multi-byte rejection note is saved', ! is_wp_error( $rej ) && 'rejected' === $rej['status'] && 255 === mb_strlen( (string) $rej['error_message'] ) );

// Partner master data (varchar(190) / text columns).
$a494_partner = PassCaptain\Partners\Repository::save( array( 'name' => str_repeat( 'Müller & Söhne ', 20 ), 'contact_name' => str_repeat( 'Jürgen ', 40 ), 'legal_name' => str_repeat( 'Größe ', 50 ), 'currency' => 'EUR' ) );
$a494_row = is_wp_error( $a494_partner ) ? null : PassCaptain\Partners\Repository::get( is_object( $a494_partner ) ? $a494_partner->partner_id : $a494_partner );
check( 'A494: partner with long multi-byte name/contact/legal name is saved, cut to 190 characters', $a494_row && 190 === mb_strlen( (string) $a494_row->name ) && 190 === mb_strlen( (string) $a494_row->contact_name ) );

update_option( PassCaptain\Settings::OPTION, $a494_saved );
