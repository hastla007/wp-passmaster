<?php
/**
 * A474: the ticket mail's attachments and added sections (PDF, invoice, wallet texts, .ics) are
 * built in the buyer's locale, and wallet passes are rebuilt in it later. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;

require_once __DIR__ . '/r7/wallet_fixture.php';
if ( ! function_exists( 'switch_to_locale' ) ) {
	function switch_to_locale( $locale ) { $GLOBALS['wpmt_locale_calls'][] = 'switch:' . $locale; $GLOBALS['wpmt_locale_stack'][] = $locale; return true; }
	function restore_previous_locale() { $GLOBALS['wpmt_locale_calls'][] = 'restore'; array_pop( $GLOBALS['wpmt_locale_stack'] ); return true; }
}
echo "A474: ticket mail attachments and wallet passes in the buyer's locale\n";
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpmt_locale_stack'] = array();
$a474_seen = array();
$a474_locale = static function () { return end( $GLOBALS['wpmt_locale_stack'] ) ?: 'request'; };
add_filter( 'wpcap_ticket_mail', $a474_f1 = static function ( $mail ) use ( &$a474_seen, $a474_locale ) { $a474_seen['ticket_mail'] = $a474_locale(); return $mail; }, 1 );
add_filter( 'wpcap_wallet_apple_pass_json', $a474_f2 = static function ( $json ) use ( &$a474_seen, $a474_locale ) { $a474_seen['pass'][] = $a474_locale(); return $json; } );
add_filter( 'wpcap_ticket_code_mail_section', $a474_f3 = static function ( $text ) use ( &$a474_seen, $a474_locale ) { $a474_seen['section'][] = $a474_locale(); return $text; }, 1 );
$a474 = wpr7_wallet_enable();
$a474_type = make_type( 10, 10, 0 );
http_reset();
$a474_r = Orders::create( array( 'email' => 'a474@example.com', 'locale' => 'en_US', 'items' => array( array( 'ticket_type_id' => $a474_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a474-1' ) );
check( 'A474 setup: order stored with the buyer locale', 'en_US' === $wpdb->get_var( 'SELECT locale FROM ' . $p . 'wpcap_orders WHERE order_id=' . (int) $a474_r['order_id'] ) );
check( 'A474: wpcap_ticket_mail (wallet, invoice, .ics, seats) runs in the buyer locale', 'en_US' === ( $a474_seen['ticket_mail'] ?? '' ) );
check( 'A474: the .pkpass of the ticket mail is built in the buyer locale', array( 'en_US' ) === array_unique( $a474_seen['pass'] ?? array() ) );
check( 'A474: locale restored after the mail', ! $GLOBALS['wpmt_locale_stack'] );
$a474_tid = (int) tickets( $a474_r['order_id'] )[0]->ticket_id;
Service::flush();
// The buyer saved the ticket to Google Wallet: only then is the object kept up to date (A566).
PassCaptain\WalletPasses\Store::mark_remote( 'google', PassCaptain\WalletPasses\Store::passes( $a474_tid, 'google' )[0]->serial_number );
$a474_seen['pass'] = array();
wpr7_served_pass( $a474_tid );
check( 'A474: pass rebuilt for a device (request without locale) in the buyer locale', array( 'en_US' ) === ( $a474_seen['pass'] ?? array() ) );
http_reset();
for ( $i = 0; $i < 4; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
$a474_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a474_type" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Vienna Hall' WHERE occurrence_id=$a474_occ" );
do_action( 'wpcap_occurrence_changed', $a474_occ, array( 'location' => array( 'Wien', 'Vienna Hall' ) ), 2 );
Service::flush();
$a474_obj = json_decode( array_values( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => 'PATCH' === $q['method'] ) )[0]['body'] ?? '{}', true );
$a474_class = json_decode( array_values( array_filter( http_requests( 'eventTicketClass/' ), static fn( $q ) => 'PATCH' === $q['method'] ) )[0]['body'] ?? '{}', true );
check( 'A474: Google texts carry the buyer language (en), not a fixed "de"', 'en' === ( $a474_obj['ticketType']['defaultValue']['language'] ?? '' ) && 'en' === ( $a474_class['eventName']['defaultValue']['language'] ?? '' ) );
http_reset();
for ( $i = 0; $i < 6; $i++ ) { http_push( array(), 200, 'walletobjects' ); }
Orders::resend_tickets( $a474_r['order_id'] );
Service::flush();
check( 'A474: wallet section of the resend mail is rendered in the order locale', array( 'en_US' ) === array_unique( $a474_seen['section'] ?? array() ) );
remove_filter( 'wpcap_ticket_mail', $a474_f1, 1 );
remove_filter( 'wpcap_wallet_apple_pass_json', $a474_f2 );
remove_filter( 'wpcap_ticket_code_mail_section', $a474_f3, 1 );
wpr7_wallet_disable();
