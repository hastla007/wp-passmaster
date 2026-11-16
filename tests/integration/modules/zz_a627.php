<?php
// phpcs:ignoreFile
/**
 * A627: the portal download "all" renders at most pdf_max_tickets() tickets (default 50, filter
 * wpcap_portal_pdf_max_tickets) per PDF; larger orders get one download per part ("all-2", …).
 */

use PassCaptain\Orders;
use PassCaptain\PortalSelfService;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'nonce'; } }
echo "A627: portal PDF in parts\n";
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_filters']['wpcap_portal_pdf_max_tickets'] = 2;
$a627_type = make_type( 10, 10, 0 );
$a627 = Orders::create( array( 'email' => 'x4-a627@example.com', 'items' => array( array( 'ticket_type_id' => $a627_type, 'quantity' => 5 ) ), 'idempotency_key' => 'x4-a627-' . uniqid() ) );
$a627_token = bin2hex( random_bytes( 32 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET access_token_hash=%s,access_expires_at=%s WHERE order_id=%d", hash( 'sha256', $a627_token ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), $a627['order_id'] ) );
$a627_public = (string) $wpdb->get_var( 'SELECT public_id FROM ' . $p . 'wpcap_orders WHERE order_id=' . (int) $a627['order_id'] );
$GLOBALS['wpcap_test_transients'] = array();
$a627_1 = PortalSelfService::pdf( $a627_public, $a627_token, 'all' );
$a627_3 = PortalSelfService::pdf( $a627_public, $a627_token, 'all-3' );
$a627_4 = PortalSelfService::pdf( $a627_public, $a627_token, 'all-4' );
check( 'A627: "all" holds at most the maximum of tickets', is_array( $a627_1 ) && 2 === $a627_1['tickets'] && 2 === preg_match_all( '#/Type /Page /Parent#', $a627_1['content'] ) );
check( 'A627: the last part holds the rest and has its own file name', is_array( $a627_3 ) && 1 === $a627_3['tickets'] && str_ends_with( $a627_3['filename'], '-3.pdf' ) );
check( 'A627: a part beyond the tickets is refused', is_wp_error( $a627_4 ) && 'wpcap_ticket_pdf_none' === $a627_4->get_error_code() );
$a627_html = PortalSelfService::portal_html( Orders::get( (int) $a627['order_id'] ), $a627_token, PortalSelfService::tickets( Orders::get( (int) $a627['order_id'] ) ) );
check( 'A627: the portal offers one download per part', 3 === substr_count( $a627_html, 'name="wpcap_portal_pdf"' ) && false !== strpos( $a627_html, 'value="all-3"' ) && false !== strpos( $a627_html, 'Tickets 5–5 von 5 als PDF herunterladen' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_portal_pdf_max_tickets'] );
check( 'A627: default maximum is 50', 50 === PortalSelfService::pdf_max_tickets() );
