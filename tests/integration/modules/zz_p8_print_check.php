<?php
/**
 * PASS-FE-07 (rest): automated print check of QR contrast, quiet zone, module size and print margins
 * (Printing\PrintCheck on a tracing PdfWriter) and start designs of the ticket PDF (setting
 * ticket_pdf.design, per event as override, an assigned ticket template keeps precedence).
 * Included from run.php (check(), make_type()).
 */

// phpcs:ignoreFile

use PassCaptain\EventOverrides;
use PassCaptain\Printing\Page as P8Page;
use PassCaptain\Printing\PdfWriter;
use PassCaptain\Settings;
use PassCaptain\TicketPdf;
use PassCaptain\Printing\PrintCheck;
use PassCaptain\Printing\Templates;
if ( ! class_exists( PassCaptain\QrCode::ENCODER ) ) {
	// Without `composer install` (local runs): the committed vendor-prefixed/ copy, as pass-captain.php loads it.
	spl_autoload_register( static function ( $class ) {
		foreach ( array( 'PassCaptain\\Vendor\\BaconQrCode\\' => 'vendor-prefixed/bacon/bacon-qr-code/src/', 'PassCaptain\\Vendor\\DASPRiD\\Enum\\' => 'vendor-prefixed/dasprid/enum/src/' ) as $ns => $dir ) {
			if ( 0 === strpos( $class, $ns ) ) {
				$file = WPCAP_PATH . $dir . str_replace( '\\', '/', substr( $class, strlen( $ns ) ) ) . '.php';
				if ( is_readable( $file ) ) { require_once $file; }
				return;
			}
		}
	} );
}

global $wpdb;
$p8_p = $wpdb->prefix;
$p8_saved = get_option( Settings::OPTION, array() );
$p8_overrides = get_option( EventOverrides::OPTION, array() );
$p8_qr = class_exists( PassCaptain\QrCode::ENCODER );
$p8_mm = PdfWriter::MM;

echo "PASS-FE-07: print check (QR contrast and quiet zone, module size, print margins)\n";
$p8_all_ok = true;
$p8_failed = array();
$p8_codes = 0;
foreach ( Templates::KINDS as $p8_kind ) {
	$p8_r = PrintCheck::check( $p8_kind );
	$p8_codes += count( $p8_r['qr'] );
	if ( $p8_r['problems'] ) { $p8_all_ok = false; $p8_failed[] = $p8_kind . ': ' . implode( ' ', $p8_r['problems'] ); }
}
foreach ( Templates::starters() as $p8_key => $p8_starter ) {
	$p8_r = PrintCheck::check( $p8_starter['kind'], $p8_starter['body'] );
	$p8_codes += count( $p8_r['qr'] );
	if ( $p8_r['problems'] ) { $p8_all_ok = false; $p8_failed[] = $p8_key . ': ' . implode( ' ', $p8_r['problems'] ); }
}
check( 'PASS-FE-07: every built-in layout and every starter template passes the print check' . ( $p8_failed ? ' (' . implode( ' | ', $p8_failed ) . ')' : '' ), $p8_all_ok );
check( 'PASS-FE-07: the check measured the QR codes (encoder present)', ! $p8_qr || $p8_codes >= count( Templates::KINDS ) );
$p8_ticket = PrintCheck::check( 'ticket' );
check( 'PASS-FE-07: ticket QR: black on white (21:1), quiet zone of at least 4 modules, modules of at least 0.4 mm', ! $p8_qr || ( 21.0 === (float) $p8_ticket['qr'][0]['contrast'] && $p8_ticket['qr'][0]['quiet_modules'] >= 4 && $p8_ticket['qr'][0]['module_mm'] >= 0.4 ) );
check( 'PASS-FE-07: ticket content keeps at least 4 mm off the page edge (footer included)', $p8_ticket['margin_mm'] >= 4.0 );
$p8_band = PrintCheck::check( 'wristband' );
check( 'PASS-FE-07: wristband QR keeps 4 modules to the band edge', ! $p8_qr || $p8_band['qr'][0]['quiet_modules'] >= 4 );
check( 'PASS-FE-07: QR codes are drawn with a quiet zone of 4 modules inside their box', 4 === Templates::QR_QUIET );
check( 'PASS-FE-07: label sheets 70x37 keep 4 mm off the sheet edge', 4.0 <= PrintCheck::check( 'label_3x8' )['margin_mm'] );

// Rules on traced boxes (independent of the QR encoder).
$p8_page = static function ( array $boxes, $w = 595.28, $h = 841.89 ) { return array( array( 'w' => $w, 'h' => $h, 'boxes' => $boxes ) ); };
$p8_qrbox = static function ( $x, $y, $module, $color = array( 0, 0, 0 ) ) { return array( 'type' => 'qr', 'x' => $x, 'y' => $y, 'w' => 25 * $module, 'h' => 25 * $module, 'module' => $module, 'modules' => 25, 'color' => $color ); };
$p8_text = static function ( $x, $y, $w, $h = 10 ) { return array( 'type' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'size' => 10, 'color' => null ); };
$p8_m = 1.0 * $p8_mm;
$p8_clean = PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_qrbox( 200, 300, $p8_m ), $p8_text( 60, 200, 100 ) ) ) );
check( 'PASS-FE-07: a free-standing black QR code passes', array() === $p8_clean['problems'] && 21.0 === (float) $p8_clean['qr'][0]['contrast'] );
$p8_close = PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_qrbox( 200, 300, $p8_m ), $p8_text( 200, 300 - 2 * $p8_m - 10, 100 ) ) ) );
check( 'PASS-FE-07: text two modules below the code violates the quiet zone', 1 === count( $p8_close['problems'] ) && false !== strpos( $p8_close['problems'][0], 'Ruhezone' ) && abs( $p8_close['qr'][0]['quiet_modules'] - 2.0 ) < 0.01 );
$p8_logo = PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_qrbox( 200, 300, $p8_m ), array( 'type' => 'image', 'x' => 190, 'y' => 290, 'w' => 30, 'h' => 30 ) ) ) );
check( 'PASS-FE-07: a logo over the code (no contrast left) is reported', false !== strpos( implode( ' ', $p8_logo['problems'] ), 'Ruhezone' ) && 0.0 === (float) $p8_logo['qr'][0]['quiet_modules'] );
$p8_grey = PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_qrbox( 200, 300, $p8_m, array( 0.7, 0.7, 0.7 ) ) ) ) );
check( 'PASS-FE-07: light grey QR modules are reported (contrast)', false !== strpos( implode( ' ', $p8_grey['problems'] ), 'Kontrast' ) && $p8_grey['qr'][0]['contrast'] < 7 );
$p8_tiny = PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_qrbox( 200, 300, 0.3 * $p8_mm ) ) ) );
check( 'PASS-FE-07: modules under 0.4 mm are reported', false !== strpos( implode( ' ', $p8_tiny['problems'] ), '0,30 mm' ) );
$p8_edge = PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_text( 2 * $p8_mm, 400, 100 ) ) ) );
check( 'PASS-FE-07: text 2 mm off the page edge breaks the print margin of 4 mm', false !== strpos( implode( ' ', $p8_edge['problems'] ), 'Druckränder' ) && abs( $p8_edge['margin_mm'] - 2.0 ) < 0.01 );
check( 'PASS-FE-07: the code near the page edge has no quiet zone there', false !== strpos( implode( ' ', PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_qrbox( 5 * $p8_mm, 300, 2 * $p8_m ) ) ) )['problems'] ), 'Ruhezone' ) );
check( 'PASS-FE-07: wristbands allow 1 mm (thermal printers)', array() === PrintCheck::evaluate( 'wristband', $p8_page( array( $p8_text( 2 * $p8_mm, 30, 100 ) ), 708.66, 70.87 ) )['problems'] );
$p8_margin = static function ( $m, $kind ) { return 'ticket' === $kind ? 0.0 : $m; };
add_filter( 'wpcap_print_check_margin', $p8_margin, 10, 2 );
check( 'PASS-FE-07: filter wpcap_print_check_margin (edge-to-edge printer)', array() === PrintCheck::evaluate( 'ticket', $p8_page( array( $p8_text( 2 * $p8_mm, 400, 100 ) ) ) )['problems'] );
remove_filter( 'wpcap_print_check_margin', $p8_margin, 10 );
// Label sheet 2x7: a box crossing into the neighbouring label.
$p8_grid = Templates::grids()['label_2x7'];
$p8_cell_x = $p8_grid['left'] * $p8_mm;
$p8_cell_y = 297 * $p8_mm - $p8_grid['top'] * $p8_mm - $p8_grid['h'] * $p8_mm;
$p8_label = PrintCheck::evaluate( 'label_2x7', $p8_page( array( $p8_text( $p8_cell_x + 20 * $p8_mm, $p8_cell_y + 2, 80 * $p8_mm ) ) ) );
check( 'PASS-FE-07: content reaching the edge of its label is reported on label sheets', false !== strpos( implode( ' ', $p8_label['problems'] ), 'Etikett' ) );

// Tracing in the PDF writer records what was drawn, without changing the PDF.
$p8_w = ( new PdfWriter( false ) )->trace();
$p8_w->add_page( 300, 300 )->text( 10, 20, 12, 'Hallo' );
$p8_w->rect( 1, 2, 3, 4, false );
$p8_t = $p8_w->traced();
check( 'PASS-FE-07: PdfWriter::trace() records page size and boxes', 1 === count( $p8_t ) && 300.0 === $p8_t[0]['w'] && 'text' === $p8_t[0]['boxes'][0]['type'] && 'frame' === $p8_t[0]['boxes'][1]['type'] && $p8_t[0]['boxes'][0]['w'] > 0 );
check( 'PASS-FE-07: without trace() nothing is recorded', array() === ( new PdfWriter( false ) )->add_page( 100, 100 )->text( 1, 1, 10, 'x' )->traced() );

// Warning after saving a template (never refused).
$p8_wide = static function () { return 30.0; };
add_filter( 'wpcap_print_check_margin', $p8_wide );
$p8_tid = Templates::save_template( 0, 'P8 Druckprüfung', 'ticket', "*20|{event}\n{qr}" );
$p8_res = P8Page::with_print_check( $p8_tid );
check( 'PASS-FE-07: a saved template with findings gets a warning, the template stays saved', is_int( $p8_tid ) && $p8_tid > 0 && is_array( $p8_res ) && $p8_tid === $p8_res['template_id'] && 0 === strpos( $p8_res['warning'], 'Druckprüfung:' ) && false !== strpos( $p8_res['warning'], 'Druckränder' ) );
remove_filter( 'wpcap_print_check_margin', $p8_wide );
check( 'PASS-FE-07: without findings the result stays the template id', $p8_tid === P8Page::with_print_check( $p8_tid ) );
check( 'PASS-FE-07: errors pass through unchanged', is_wp_error( P8Page::with_print_check( new WP_Error( 'x', 'y' ) ) ) );

echo "PASS-FE-07: start designs of the ticket PDF\n";
$p8_type = make_type( 20, 10, 0 );
$p8_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p8_p}wpcap_ticket_types WHERE ticket_type_id=$p8_type" );
$p8_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p8_p}wpcap_occurrences WHERE occurrence_id=$p8_occ" );
$p8_designs = Templates::ticket_designs();
check( 'PASS-FE-07: designs are the built-in layout and the ticket starters only', array( '', 'ticket_clear', 'ticket_large', 'ticket_accent' ) === array_map( 'strval', array_keys( $p8_designs ) ) );
check( 'PASS-FE-07: the register offers the designs as a choice', 'choice' === PassCaptain\SettingsSchema::get( 'ticket_pdf' )['fields']['design']['type'] );
Settings::update( array( 'ticket_pdf' => array( 'design' => '' ) + (array) Settings::get( 'ticket_pdf', array() ) ) );
check( 'PASS-FE-07: default – the built-in ticket layout, as before', Templates::default_body( 'ticket' ) === Templates::body_for_event( $p8_event, 'ticket' ) && Templates::default_body( 'ticket' ) === Templates::body_for_event( 0, 'ticket' ) );
$p8_bad = P8Page::save_ticket_pdf( array( 'event_id' => 0, 'logo' => '', 'footer' => '', 'design' => 'badge_large', 'legal_links' => '1' ) );
check( 'PASS-FE-07: a design that is no ticket starter is refused', is_wp_error( $p8_bad ) && '' === (string) ( Settings::get( 'ticket_pdf' )['design'] ?? '' ) );
$p8_ok = P8Page::save_ticket_pdf( array( 'event_id' => 0, 'logo' => '', 'footer' => '', 'design' => 'ticket_large', 'legal_links' => '1' ) );
$p8_large = Templates::starters()['ticket_large']['body'];
check( 'PASS-FE-07: global design saved and used for every event without an own template', ! is_wp_error( $p8_ok ) && 'ticket_large' === Settings::get( 'ticket_pdf' )['design'] && $p8_large === Templates::body_for_event( $p8_event, 'ticket' ) && $p8_large === Templates::body_for_event( 0, 'ticket' ) );
$p8_entry = array( 'event' => 'Großdruck-Konzert', 'ticket' => 'Standard', 'when' => 'x', 'occurrence_id' => $p8_occ, 'token' => 'P8TOKEN', 'starts_at' => '2027-05-01 18:00:00', 'timezone' => 'UTC' );
$p8_pdf = wpcap_test_pdf_plain( TicketPdf::render( array( $p8_entry ), array( 'lang' => 'de_DE' ) ) );
check( 'PASS-FE-07: the ticket PDF uses the design (event name in 30 pt)', false !== strpos( $p8_pdf, '30.00 Tf' ) );
check( 'PASS-FE-07: other layouts are not affected by the ticket design', Templates::default_body( 'badge' ) === Templates::body_for_event( $p8_event, 'badge' ) );
$p8_ev = P8Page::save_ticket_pdf( array( 'event_id' => $p8_event, 'logo' => '', 'footer' => '', 'design' => 'ticket_clear' ) );
check( 'PASS-FE-07: event override of the design wins over the global one', ! is_wp_error( $p8_ev ) && array( 'design' => 'ticket_clear' ) === EventOverrides::get( $p8_event, 'ticket_pdf' ) && Templates::starters()['ticket_clear']['body'] === Templates::body_for_event( $p8_event, 'ticket' ) && $p8_large === Templates::body_for_event( 0, 'ticket' ) );
$p8_pdf2 = wpcap_test_pdf_plain( TicketPdf::render( array( $p8_entry ), array( 'lang' => 'de_DE' ) ) );
check( 'PASS-FE-07: the event ticket PDF follows its own design (24 pt, not 30 pt)', false !== strpos( $p8_pdf2, '24.00 Tf' ) && false === strpos( $p8_pdf2, '30.00 Tf' ) );
P8Page::save_ticket_pdf( array( 'event_id' => $p8_event, 'logo' => '', 'footer' => '', 'design' => '' ) );
check( 'PASS-FE-07: an empty event design means the global design', $p8_large === Templates::body_for_event( $p8_event, 'ticket' ) );
$p8_own = Templates::save_template( 0, 'P8 eigenes Ticket', 'ticket', "*19|{event}\n{qr}" );
Templates::assign( $p8_event, 'ticket', $p8_own );
check( 'PASS-FE-07: a ticket template assigned to the event keeps precedence over the design', "*19|{event}\n{qr}" === Templates::body_for_event( $p8_event, 'ticket' ) );
Templates::assign( $p8_event, 'ticket', 0 );
Settings::update( array( 'ticket_pdf' => array( 'design' => 'gibt_es_nicht' ) + (array) Settings::get( 'ticket_pdf', array() ) ) );
check( 'PASS-FE-07: an unknown stored design falls back to the built-in layout', '' === TicketPdf::settings_for_event( $p8_event )['design'] && Templates::default_body( 'ticket' ) === Templates::body_for_event( $p8_event, 'ticket' ) );

update_option( Settings::OPTION, $p8_saved );
update_option( EventOverrides::OPTION, $p8_overrides );
Settings::flush();
