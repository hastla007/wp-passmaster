<?php
// phpcs:ignoreFile
/**
 * A618: ticket PDFs are compact: content streams FlateDecode-compressed (filter wpcap_pdf_compress)
 * and the QR code drawn as one rectangle per horizontal run of dark modules, filled once.
 */

use PassCaptain\TicketPdf;

echo "A618: compact ticket PDF\n";
if ( ! class_exists( '\\PassCaptain\\Vendor\\BaconQrCode\\Encoder\\Encoder' ) && is_readable( WPCAP_PATH . 'vendor/autoload.php' ) ) { require_once WPCAP_PATH . 'vendor/autoload.php'; }
$a618_ticket = array( 'event' => 'A618 Konzert', 'ticket' => 'Stehplatz', 'when' => '24.12.2026 20:00', 'location' => 'Wien', 'token' => str_repeat( 'ab', 32 ), 'help_code' => 'ABCD2345', 'ticket_id' => 1 );
$a618_ten = array_fill( 0, 10, $a618_ticket );
$a618_plain = TicketPdf::render( $a618_ten, array( 'title' => 'T' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_pdf_compress'] );
$a618_packed = TicketPdf::render( $a618_ten, array( 'title' => 'T' ) );
$GLOBALS['wpcap_test_filters']['wpcap_pdf_compress'] = false;
check( 'A618: the page content is FlateDecode-compressed by default', false !== strpos( $a618_packed, '/Filter /FlateDecode >>' ) && false === strpos( $a618_plain, " /Filter /FlateDecode >>\nstream\nq" ) );
check( 'A618: ten tickets compressed take at most half the size (' . strlen( $a618_packed ) . ' vs ' . strlen( $a618_plain ) . ' bytes)', strlen( $a618_packed ) * 2 <= strlen( $a618_plain ) );
check( 'A618: the inflated content still prints the ticket', false !== strpos( wpcap_pdf_plain( $a618_packed ), 'A618 Konzert' ) );
$a618_matrix = TicketPdf::qr_matrix( $a618_ticket['token'] );
if ( $a618_matrix ) {
	$a618_dark = array_sum( array_map( static fn( $r ) => count( array_filter( $r ) ), $a618_matrix ) );
	$a618_rects = preg_match_all( '/ re\n/', $a618_plain ) / 10;
	check( 'A618: the QR code uses clearly fewer rectangles than dark modules (' . $a618_rects . ' vs ' . $a618_dark . ')', $a618_rects > 0 && $a618_rects < 0.7 * $a618_dark && false === strpos( $a618_plain, ' re f' . "\n" . '0' ) );
}
check( 'A618: the xref offsets stay valid (startxref points at "xref")', (bool) preg_match( '/startxref\n(\d+)\n%%EOF/', $a618_packed, $a618_m ) && 'xref' === substr( $a618_packed, (int) $a618_m[1], 4 ) );
