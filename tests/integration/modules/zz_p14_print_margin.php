<?php
/**
 * Owner decision 25.11.2026: the print margin is a setting (print_margin_mm, whole millimetres 0–15,
 * default 4 mm) used by the print check, the ticket/badge PDF layouts, the invoice PDF footer and the
 * receipts. The register refuses values outside the limits instead of clamping them.
 * Included from run.php (check(), make_type()).
 */

// phpcs:ignoreFile

use PassCaptain\Printing\Page as P14Page;
use PassCaptain\Printing\PdfWriter;
use PassCaptain\Printing\PrintCheck;
use PassCaptain\Printing\Templates;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;
use PassCaptain\TicketPdf;

global $wpdb;
$p14_saved = get_option( Settings::OPTION, array() );
$p14_mm = PdfWriter::MM;

echo "Print margin setting (owner decision 25.11.2026)\n";
$p14_entry = SettingsSchema::get( 'print_margin_mm' );
check( 'print margin: default 4 mm in the defaults, the register and the reader', 4 === Settings::defaults()['print_margin_mm'] && 4 === Templates::margin_mm() && $p14_entry && 4 === $p14_entry['default'] );
check( 'print margin: register entry is a described whole number 0–15 on the print page', 'int' === $p14_entry['type'] && 0 === (int) $p14_entry['min'] && 15 === (int) $p14_entry['max'] && '' !== $p14_entry['label'] && '' !== $p14_entry['help'] && ! in_array( 'print_margin_mm', SettingsSchema::undescribed(), true ) );
check( 'print margin: print check uses 4 mm, wristbands 1 mm by default', 4.0 === PrintCheck::margin( 'ticket' ) && 4.0 === PrintCheck::margin( 'label_3x8' ) && 1.0 === PrintCheck::margin( 'wristband' ) );

// Validation: reported, never clamped.
$p14_range = SettingsSchema::validate( 'print_margin_mm', 16 );
$p14_neg = SettingsSchema::validate( 'print_margin_mm', '-1' );
$p14_frac = SettingsSchema::validate( 'print_margin_mm', '4.5' );
$p14_text = SettingsSchema::validate( 'print_margin_mm', 'vier' );
check( 'print margin: 16 and -1 are refused with the range in the message (no clamping)', is_wp_error( $p14_range ) && 'wpcap_setting_range' === $p14_range->get_error_code() && false !== strpos( $p14_range->get_error_message(), '15' ) && is_wp_error( $p14_neg ) && 'wpcap_setting_range' === $p14_neg->get_error_code() );
check( 'print margin: fractions and text are refused', is_wp_error( $p14_frac ) && is_wp_error( $p14_text ) && 'wpcap_setting_int' === $p14_text->get_error_code() );
check( 'print margin: limits 0 and 15 are accepted', 0 === SettingsSchema::validate( 'print_margin_mm', '0' ) && 15 === SettingsSchema::validate( 'print_margin_mm', 15 ) );

// Settings form (Druck & Listen).
$p14_bad = P14Page::save_settings( array( 'print_margin_mm' => '25', 'print_attach_invoice_pdf' => '1' ) );
check( 'print margin form: 25 mm is refused, nothing is saved (invoice option unchanged too)', is_wp_error( $p14_bad ) && 'print_margin_mm' === ( $p14_bad->get_error_data()['field'] ?? '' ) && 4 === Templates::margin_mm() && 0 === (int) Settings::get( 'print_attach_invoice_pdf' ) );
$p14_ok = P14Page::save_settings( array( 'print_margin_mm' => ' 8 ' ) );
check( 'print margin form: 8 mm is saved as a number and used', true === $p14_ok && 8 === Settings::get( 'print_margin_mm' ) && 8 === Templates::margin_mm() && 8.0 === PrintCheck::margin( 'ticket' ) && 1.0 === PrintCheck::margin( 'wristband' ) );
P14Page::save_settings( array( 'print_margin_mm' => '' ) );
check( 'print margin form: an empty field means the default', 4 === Settings::get( 'print_margin_mm' ) );
P14Page::save_settings( array( 'print_attach_invoice_pdf' => '1' ) );
check( 'print margin form: a post without the margin field keeps the margin', 4 === Settings::get( 'print_margin_mm' ) && 1 === (int) Settings::get( 'print_attach_invoice_pdf' ) );
Settings::update( array( 'print_attach_invoice_pdf' => 0 ) );
Settings::update( array( 'print_margin_mm' => 99 ) );
check( 'print margin: a stored value outside the limits (edited by hand) counts as the default, not as 15', 4 === Templates::margin_mm() );
Settings::update( array( 'print_margin_mm' => 0 ) );
check( 'print margin: 0 relaxes wristbands too (edge-to-edge printers)', 0.0 === PrintCheck::margin( 'ticket' ) && 0.0 === PrintCheck::margin( 'wristband' ) );

// Layouts follow the margin.
Settings::update( array( 'print_margin_mm' => 4 ) );
$p14_entry_ticket = array( 'event' => 'Randkonzert', 'ticket' => 'Standard', 'when' => 'x', 'occurrence_id' => 0, 'token' => 'P14TOKEN', 'starts_at' => '2027-05-01 18:00:00', 'timezone' => 'UTC' );
$p14_pdf4 = TicketPdf::render( array( $p14_entry_ticket ), array( 'lang' => 'de_DE', 'title' => 'Kulturverein', 'footer' => 'Nur einmal gültig.' ) );
Settings::update( array( 'print_margin_mm' => 15 ) );
$p14_failed = array();
foreach ( array( 'ticket', 'badge' ) as $p14_kind ) {
	$p14_r = PrintCheck::check( $p14_kind );
	if ( $p14_r['problems'] || $p14_r['margin_mm'] < 15.0 ) { $p14_failed[] = $p14_kind . ' ' . $p14_r['margin_mm'] . ': ' . implode( ' ', $p14_r['problems'] ); }
}
foreach ( Templates::starters() as $p14_key => $p14_starter ) {
	if ( 'ticket' !== $p14_starter['kind'] && 'badge' !== $p14_starter['kind'] ) { continue; }
	$p14_r = PrintCheck::check( $p14_starter['kind'], $p14_starter['body'] );
	if ( $p14_r['problems'] ) { $p14_failed[] = $p14_key . ': ' . implode( ' ', $p14_r['problems'] ); }
}
check( 'print margin 15 mm: ticket and badge layouts (header, footer, starters) keep 15 mm' . ( $p14_failed ? ' (' . implode( ' | ', $p14_failed ) . ')' : '' ), ! $p14_failed );
$p14_labels = PrintCheck::check( 'label_3x8' );
check( 'print margin 15 mm: label sheets keep their fixed grid and the print check reports it', false !== strpos( implode( ' ', $p14_labels['problems'] ), 'Druckränder' ) && false !== strpos( implode( ' ', $p14_labels['problems'] ), '15,0 mm' ) );
$p14_pdf15 = TicketPdf::render( array( $p14_entry_ticket ), array( 'lang' => 'de_DE', 'title' => 'Kulturverein', 'footer' => 'Nur einmal gültig.' ) );
check( 'print margin: the ticket PDF changes with the margin and stays a PDF', is_string( $p14_pdf15 ) && 0 === strpos( $p14_pdf15, '%PDF' ) && $p14_pdf4 !== $p14_pdf15 );
Settings::update( array( 'print_margin_mm' => 4 ) );
check( 'print margin: back at 4 mm the ticket PDF is the same as before the change to 15 mm', TicketPdf::render( array( $p14_entry_ticket ), array( 'lang' => 'de_DE', 'title' => 'Kulturverein', 'footer' => 'Nur einmal gültig.' ) ) === $p14_pdf4 );
$p14_ticket4 = PrintCheck::check( 'ticket' );
check( 'print margin: with 4 mm every built-in layout passes as before', array() === $p14_ticket4['problems'] && array() === PrintCheck::check( 'label_3x8' )['problems'] && array() === PrintCheck::check( 'wristband' )['problems'] );

// Receipts (box office, 80 mm): side padding = margin. Uses a booked sale of an earlier module, if any.
$p14_sale = (int) $wpdb->get_var( "SELECT sale_id FROM {$wpdb->prefix}wpcap_boxoffice_sales WHERE receipt_number<>'' ORDER BY sale_id DESC LIMIT 1" );
if ( $p14_sale && class_exists( PassCaptain\BoxOffice\Service::class ) ) {
	$p14_r4 = PassCaptain\BoxOffice\Service::receipt_html( $p14_sale );
	Settings::update( array( 'print_margin_mm' => 6 ) );
	$p14_r6 = PassCaptain\BoxOffice\Service::receipt_html( $p14_sale );
	Settings::update( array( 'print_margin_mm' => 4 ) );
	check( 'print margin: the box office receipt pads its sides with the margin (4 mm default, 6 mm set)', false !== strpos( $p14_r4, 'padding:3mm 4mm' ) && false !== strpos( $p14_r6, 'padding:3mm 6mm' ) );
} else {
	echo "  skip receipt check (no booked box office sale in this run)\n";
}

update_option( Settings::OPTION, $p14_saved );
Settings::flush();
