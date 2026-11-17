<?php
/**
 * Worklist item A281: help-code lookups check the user and client limits first; the site-wide budget
 * is only consumed by unknown codes of requests within both, so one scanner account cannot block the
 * help-code entry of the whole site. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\HelpCode;
use PassCaptain\Orders;

$q1h_saved_user = $GLOBALS['wpcap_test_user_id'] ?? 1;
$GLOBALS['wpcap_test_transients'] = array();
HelpCode::flush();

echo "A281: help-code site budget\n";
$q1h_order = Orders::create( array( 'email' => 'a281@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 10, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'a281-' . uniqid() ) );
$q1h_ticket = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $q1h_order['order_id'] . ' LIMIT 1' );
$q1h_alpha = HelpCode::ALPHABET;
$q1h_code = '';
for ( $i = 0; $i < HelpCode::LENGTH; $i++ ) { $q1h_code .= $q1h_alpha[ random_int( 0, 31 ) ]; }
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET help_hash=%s,help_n=0 WHERE ticket_id=%d", HelpCode::lookup_hash( $q1h_code ), $q1h_ticket ) );
$q1h_unknown = array();
for ( $i = 0; $i < 40; $i++ ) { $q1h_unknown[] = 'ZZZZ' . strtr( sprintf( '%04d', $i ), '01', 'AB' ); } // Alphabet has no 0/1.
$q1h_resolve = static function ( $code ) { HelpCode::flush(); return HelpCode::resolve( $code ); };

$GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] = array( 'user' => 3, 'client' => 1000, 'site' => 5, 'window' => 600 );
check( 'A281 setup: the help code resolves to the ticket', $q1h_ticket > 0 && $q1h_ticket === $q1h_resolve( $q1h_code ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_user_id'] = 28101;
$q1h_flood = array();
foreach ( array_slice( $q1h_unknown, 0, 30 ) as $code ) { $q1h_flood[] = $q1h_resolve( $code ); }
check( 'A281 a flooding account is limited after its user limit', 0 === $q1h_flood[0] && is_wp_error( $q1h_flood[3] ) && is_wp_error( end( $q1h_flood ) ) );
$GLOBALS['wpcap_test_user_id'] = 28102;
check( 'A281 another scanner account still resolves codes (site budget not used up by refused requests)', $q1h_ticket === $q1h_resolve( $q1h_code ) && 0 === $q1h_resolve( $q1h_unknown[31] ) );
$GLOBALS['wpcap_test_user_id'] = 28103;
check( 'A281 found codes do not consume the site budget', $q1h_ticket === $q1h_resolve( $q1h_code ) && $q1h_ticket === $q1h_resolve( $q1h_code . ' ' ) && 0 === $q1h_resolve( $q1h_unknown[32] ) );
$GLOBALS['wpcap_test_user_id'] = 28104;
$q1h_site = $q1h_resolve( $q1h_unknown[33] );
check( 'A281 unknown codes of several accounts still reach the site-wide limit (3 + 1 + 1 = 5)', is_wp_error( $q1h_site ) && 'wpcap_help_code_rate_limited' === $q1h_site->get_error_code() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_help_code_rate_limits'] );

$GLOBALS['wpcap_test_user_id'] = $q1h_saved_user;
$GLOBALS['wpcap_test_transients'] = array();
HelpCode::flush();
