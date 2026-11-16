<?php
// phpcs:ignoreFile
/**
 * A330: one PDF engine for ticket PDFs (mail, portal, admin) and print layouts, with an embedded
 * Unicode font (names in any script survive), the event's ticket print template applied to the
 * mail PDF, a footer per ticket (filter), the standard-font fallback and batch files.
 */
use PassCaptain\Orders;
use PassCaptain\Printing\BatchJob;
use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\Templates;
use PassCaptain\TicketPdf;

echo "A330: PDF engine with Unicode fonts and print templates\n";
global $wpdb;
$p = $wpdb->prefix;

/** Structure: header, xref offsets of every object in order, stream lengths, page count. */
function a330_pdf_ok( $pdf, $pages = null ) {
	if ( ! is_string( $pdf ) || ! str_starts_with( $pdf, '%PDF-1.4' ) || ! preg_match( '/startxref\n(\d+)\n%%EOF$/', $pdf, $m ) ) { return false; }
	$xref = substr( $pdf, (int) $m[1] );
	if ( 0 !== strpos( $xref, 'xref' ) || ! preg_match_all( '/^(\d{10}) 00000 n $/m', $xref, $offsets ) ) { return false; }
	foreach ( $offsets[1] as $i => $offset ) {
		if ( 0 !== strpos( substr( $pdf, (int) $offset ), ( $i + 1 ) . ' 0 obj' ) ) { return false; }
	}
	if ( ! preg_match_all( '/<< \/Length (\d+)(?: \/Length1 \d+ \/Filter \/FlateDecode)? >>\nstream\n/', $pdf, $streams, PREG_OFFSET_CAPTURE ) ) { return false; }
	foreach ( $streams[1] as $i => $len ) {
		if ( "\nendstream" !== substr( $pdf, $streams[0][ $i ][1] + strlen( $streams[0][ $i ][0] ) + (int) $len[0], 10 ) ) { return false; }
	}
	return null === $pages || $pages === preg_match_all( '#/Type /Page /Parent#', $pdf );
}

$a330_names = array( 'Łukasz Żółkiewski', 'Иван Петров', 'Αλέξανδρος Ηλιόπουλος', 'Şükrü Öztürk', 'Dvořák Jiří' );
$a330_entries = array();
foreach ( $a330_names as $i => $a330_name ) {
	$a330_entries[] = array( 'event' => 'Konzert in Łódź', 'ticket' => 'Standard', 'when' => '01.06.2027 20:00', 'location' => 'Filharmonia Łódzka', 'attendee' => $a330_name, 'token' => str_repeat( dechex( $i + 1 ), 64 ), 'help_code' => 'ABCD-EFG' . ( $i + 2 ) );
}
$a330_pdf = TicketPdf::render( $a330_entries, array( 'title' => 'Testsite', 'footer' => 'Nur einmal gültig.' ) );
$a330_plain = wpcap_pdf_plain( $a330_pdf );
check( 'A330: ticket PDF is valid, one page per ticket', a330_pdf_ok( $a330_pdf, 5 ) );
check( 'A330: the Unicode font is embedded as a subset (Type0/CIDFontType2, FontFile2, ToUnicode)', false !== strpos( $a330_pdf, '/Subtype /Type0' ) && false !== strpos( $a330_pdf, '/Subtype /CIDFontType2' ) && false !== strpos( $a330_pdf, '/FontFile2' ) && 1 === preg_match( '#/BaseFont /[A-Z]{6}\+DejaVuSans#', $a330_pdf ) && false === strpos( $a330_pdf, '/BaseFont /Helvetica /Encoding /WinAnsiEncoding' ) );
$a330_ok = true;
foreach ( $a330_names as $a330_name ) { $a330_ok = $a330_ok && false !== strpos( $a330_plain, '(' . $a330_name . ') Tj' ); }
check( 'A330: Polish, Cyrillic, Greek, Turkish and Czech names come out unchanged (no "?")', $a330_ok && false !== strpos( $a330_plain, '(Konzert in Łódź) Tj' ) && false === strpos( $a330_plain, 'Konzert in ?' ) );
check( 'A330: header, full code, help code with caption and footer on every page', 5 === substr_count( $a330_plain, '(Testsite) Tj' ) && 5 === substr_count( $a330_plain, '(Nur einmal gültig.) Tj' ) && false !== strpos( $a330_plain, '(' . str_repeat( '1', 32 ) . ') Tj' ) && false !== strpos( $a330_plain, '(ABCD-EFG2) Tj' ) && false !== strpos( $a330_plain, 'Hilfscode' ) );
preg_match_all( '#/Length1 (\d+)#', $a330_pdf, $a330_len1 );
check( 'A330: only the used glyphs are embedded (subset far smaller than the font file)', $a330_len1[1] && max( array_map( 'intval', $a330_len1[1] ) ) < filesize( WPCAP_PATH . 'assets/fonts/DejaVuSans.ttf' ) / 3 && strlen( $a330_pdf ) < 150000 );

// The event's ticket template (Druck & Listen) applies to the ticket PDF of mails.
$a330_type = make_type( 40, 20, 0 );
$a330_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a330_type" );
$a330_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a330_occ" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events SET name=%s WHERE event_id=%d", 'Вечер в Łodzi', $a330_event ) );
$a330_tpl = Templates::save_template( 0, 'A330 Ticket', 'ticket', "*20|{event}\n12|Eintritt für {attendee_name}\n10|Kategorie {ticket_category}\n{qr}\n*14|{help_code}", $a330_event );
$a330_assigned = Templates::assign( $a330_event, 'ticket', $a330_tpl );
$a330_captured = array();
$a330_capture = static function ( $mail ) use ( &$a330_captured ) {
	foreach ( (array) ( $mail['attachments'] ?? array() ) as $file ) {
		if ( '.pdf' === substr( (string) $file, -4 ) && is_readable( $file ) ) { $a330_captured[] = (string) file_get_contents( $file ); }
	}
	return $mail;
};
add_filter( 'wpcap_ticket_mail', $a330_capture, 999 );
$a330_order = Orders::create( array( 'email' => 'a330@example.com', 'items' => array( array( 'ticket_type_id' => $a330_type, 'quantity' => 1 ) ), 'attendees' => array( array( 'ticket_type_id' => $a330_type, 'name' => 'Ольга Żak' ) ), 'idempotency_key' => 'a330-' . uniqid() ) );
remove_filter( 'wpcap_ticket_mail', $a330_capture, 999 );
$a330_mail_pdf = wpcap_pdf_plain( $a330_captured[0] ?? '' );
check( 'A330 setup: template assigned, free order issued', ! is_wp_error( $a330_tpl ) && true === $a330_assigned && ! is_wp_error( $a330_order ) && 1 === count( $a330_captured ) );
check( 'A330: the mail PDF uses the event\'s ticket template', false !== strpos( $a330_mail_pdf, '(Kategorie Standard) Tj' ) && false !== strpos( $a330_mail_pdf, '(Вечер в Łodzi) Tj' ) && a330_pdf_ok( $a330_captured[0] ?? '', 1 ) );
check( 'A330: the attendee name from the checkout is on the mail PDF', false !== strpos( $a330_mail_pdf, '(Eintritt für Ольга Żak) Tj' ) );
check( 'A330: the mail PDF keeps header and the default footer', false !== strpos( $a330_mail_pdf, '(Testsite) Tj' ) && false !== strpos( $a330_mail_pdf, 'Dieses Ticket ist nur einmal gültig' ) );

// Footer per ticket type (or pass) through the filter.
$a330_footer = static function ( $footer, $ticket ) use ( $a330_type ) { return (int) ( $ticket['ticket_type_id'] ?? 0 ) === $a330_type ? 'Nur mit Studierendenausweis' : $footer; };
add_filter( 'wpcap_ticket_pdf_footer', $a330_footer, 10, 2 );
$a330_two = wpcap_pdf_plain( TicketPdf::render( array( array( 'event' => 'E', 'ticket' => 'Ermäßigt', 'when' => 'x', 'token' => str_repeat( 'a', 64 ), 'ticket_type_id' => $a330_type, 'occurrence_id' => $a330_occ ), array( 'event' => 'E', 'ticket' => 'Standard', 'when' => 'x', 'token' => str_repeat( 'b', 64 ), 'ticket_type_id' => 0 ) ), array( 'title' => 'T', 'footer' => 'Standardfußzeile' ) ) );
remove_filter( 'wpcap_ticket_pdf_footer', $a330_footer, 10 );
check( 'A330: wpcap_ticket_pdf_footer sets the footer per ticket type', 1 === substr_count( $a330_two, '(Nur mit Studierendenausweis) Tj' ) && 1 === substr_count( $a330_two, '(Standardfußzeile) Tj' ) );

// Print layouts and invoices share the engine.
$a330_badge = Templates::render( 'badge', array( array( 'attendee_name' => 'Ζωή Κωνσταντίνου', 'event' => 'Kongress', 'ticket_category' => 'VIP', 'date' => '01.01.2027', 'qr' => '' ) ) );
check( 'A330: print layouts (badge) print Greek names too', a330_pdf_ok( $a330_badge, 1 ) && false !== strpos( wpcap_pdf_plain( $a330_badge ), '(Ζωή Κωνσταντίνου) Tj' ) );
check( 'A330: Unicode document title in the info dictionary (UTF-16BE)', 1 === preg_match( '#/Title <FEFF[0-9A-F]+>#', ( new PdfWriter() )->output( 'Bilety – Łódź' ) ) );

// Batch print job (blocks of 10 in several requests): glyphs of every block end up in the font subset.
$a330_order2 = Orders::create( array( 'email' => 'a330b@example.com', 'items' => array( array( 'ticket_type_id' => $a330_type, 'quantity' => 11 ) ), 'idempotency_key' => 'a330b-' . uniqid() ) );
$a330_more = array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a330_order2['order_id'] . ' ORDER BY ticket_id' ) );
// Print order is by attendee name: ten Latin names (first block), then Greek and Cyrillic (second block).
$a330_batch_names = array( 'Aaron Abel', 'Abby Adams', 'Ada Aigner', 'Adam Albers', 'Adele Amon', 'Adrian Arndt', 'Agnes Asch', 'Aiko Auer', 'Alba Axt', 'Alma Aue', 'Ωμέγα Ζωή' );
foreach ( $a330_batch_names as $i => $a330_n ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,'a330b@example.com','',%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_name=VALUES(attendee_name)", $a330_more[ $i ], $a330_n, gmdate( 'Y-m-d H:i:s' ) ) );
}
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,'a330@example.com','',%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_name=VALUES(attendee_name)", (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a330_order['order_id'] ), 'Юлия Ёлкина', gmdate( 'Y-m-d H:i:s' ) ) );
$a330_inline = static fn() => 0.0;
$a330_block = static fn() => 10;
add_filter( 'wpcap_print_batch_block', $a330_block );
add_filter( 'wpcap_print_batch_inline_budget', $a330_inline );
$a330_batch = PassCaptain\Printing\Page::batch_request( $a330_occ, 'ticket', true );
$a330_bid = (int) ( $a330_batch['batch'] ?? 0 );
$a330_first = BatchJob::get( $a330_bid );
$a330_glyph_lines = (string) @file_get_contents( BatchJob::path( $a330_first, 'glyphs' ) );
for ( $i = 0; $i < 10 && 'rendering' === BatchJob::get( $a330_bid )->status; $i++ ) { BatchJob::step( $a330_bid ); }
$a330_b = BatchJob::get( $a330_bid );
$a330_bpdf = (string) @file_get_contents( BatchJob::path( $a330_b, 'pdf' ) );
$a330_bplain = wpcap_pdf_plain( $a330_bpdf );
check( 'A330 setup: the batch runs in two blocks (first block written by the request)', 'rendering' === $a330_first->status && 10 === (int) $a330_first->done && 0 === strpos( $a330_glyph_lines, "mode\tunicode\n" ) );
check( 'A330: batch file rendered block by block embeds the glyphs of all blocks', 'ready' === $a330_b->status && a330_pdf_ok( $a330_bpdf, 12 ) && false !== strpos( $a330_bpdf, '/FontFile2' ) && false !== strpos( $a330_bplain, '(Eintritt für Aaron Abel) Tj' ) && false !== strpos( $a330_bplain, '(Eintritt für Ωμέγα Ζωή) Tj' ) && false !== strpos( $a330_bplain, '(Eintritt für Юлия Ёлкина) Tj' ) && 0 === preg_match( '/\([^)]*\?[^)]*\) Tj/', $a330_bplain ) && ! file_exists( BatchJob::path( $a330_b, 'glyphs' ) ) );
BatchJob::cancel( $a330_bid );
// A batch begun before the update (pages in standard fonts, no glyph file) is finished in standard fonts.
$a330_nofont = static fn() => array( 'regular' => '/nonexistent/font.ttf', 'bold' => '' );
add_filter( 'wpcap_pdf_fonts', $a330_nofont );
PdfWriter::reset_fonts();
$a330_legacy = PassCaptain\Printing\Page::batch_request( $a330_occ, 'ticket', true );
remove_filter( 'wpcap_pdf_fonts', $a330_nofont );
PdfWriter::reset_fonts();
$a330_lid = (int) ( $a330_legacy['batch'] ?? 0 );
$a330_l = BatchJob::get( $a330_lid );
wp_delete_file( BatchJob::path( $a330_l, 'glyphs' ) );
for ( $i = 0; $i < 10 && 'rendering' === BatchJob::get( $a330_lid )->status; $i++ ) { BatchJob::step( $a330_lid ); }
$a330_lpdf = (string) @file_get_contents( BatchJob::path( BatchJob::get( $a330_lid ), 'pdf' ) );
check( 'A330: a batch begun before the update (no glyph file) ends as a valid standard-font PDF', 'rendering' === $a330_l->status && 'ready' === BatchJob::get( $a330_lid )->status && a330_pdf_ok( $a330_lpdf, 12 ) && false !== strpos( $a330_lpdf, '/BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding' ) && false === strpos( $a330_lpdf, '/FontFile2' ) && 0 === preg_match( '/<[0-9A-F]+> Tj/', $a330_lpdf ) );
BatchJob::cancel( $a330_lid );
remove_filter( 'wpcap_print_batch_inline_budget', $a330_inline );
remove_filter( 'wpcap_print_batch_block', $a330_block );

// Documented fallback: no usable font file → standard fonts, transliteration, "?" for the rest.
$a330_nofont = static fn() => array( 'regular' => '/nonexistent/font.ttf', 'bold' => '' );
add_filter( 'wpcap_pdf_fonts', $a330_nofont );
PdfWriter::reset_fonts();
$a330_fb = TicketPdf::render( array( array( 'event' => 'Łódź Café', 'ticket' => 'Иван', 'when' => 'x', 'token' => str_repeat( 'c', 64 ) ) ), array( 'title' => 'T' ) );
remove_filter( 'wpcap_pdf_fonts', $a330_nofont );
PdfWriter::reset_fonts();
check( 'A330 fallback: without a font file the PDF uses Helvetica (WinAnsi), transliterates Polish (ł → l, ź → z), keeps ó/é, "?" for Cyrillic', a330_pdf_ok( $a330_fb, 1 ) && false !== strpos( $a330_fb, '/BaseFont /Helvetica /Encoding /WinAnsiEncoding' ) && false !== strpos( $a330_fb, '(L' . "\xF3" . 'dz Caf' . "\xE9" . ') Tj' ) && false !== strpos( $a330_fb, '(????) Tj' ) && false === strpos( $a330_fb, '/FontFile2' ) );
check( 'A330: the bundled font is back afterwards', PdfWriter::unicode_available() );
// An OpenType/CFF or broken file is rejected (fallback instead of a corrupt PDF).
check( 'A330: non-TrueType files are rejected', null === PassCaptain\Printing\TrueTypeFont::load( __FILE__ ) );
