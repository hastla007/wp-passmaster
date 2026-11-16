<?php
/**
 * A495 (Sicherheitsaudit #5): the shared CSV writer quotes per RFC 4180 (no backslash escape), so a
 * cell with `\"` cannot close its field early and smuggle a formula cell past csv_cell(); the
 * accounting/DATEV export uses the same writer.
 */

// phpcs:ignoreFile

use PassCaptain\Reports\Scope;

echo "A495: CSV cells cannot break out of their field\n";
/** Cells of each CSV line as a spreadsheet program reads them (RFC 4180, no escape character). */
function a495_cells( $csv ) {
	$csv = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv );
	return array_map( static fn( $line ) => str_getcsv( $line, ';', '"', '' ), array_values( array_filter( preg_split( '/\r?\n/', $csv ), 'strlen' ) ) );
}
$a495_evil = array(
	'Max \";=HYPERLINK(""https://evil.example"");\"',
	'Erika \";=2+3;\"',
	'x\\',
	'a\\"b',
);
$a495_rows = array( array( 'Name', 'E-Mail' ) );
foreach ( $a495_evil as $i => $cell ) { $a495_rows[] = array( $cell, "p$i@example.com" ); }
$a495_rows[] = array( '=1+1', 'x@example.com' );
$a495_parsed = a495_cells( Scope::csv( $a495_rows ) );
$a495_ok = count( $a495_parsed ) === count( $a495_rows );
foreach ( $a495_parsed as $i => $cells ) {
	$a495_ok = $a495_ok && 2 === count( $cells ) && Scope::csv_cell( $a495_rows[ $i ][0] ) === $cells[0] && $a495_rows[ $i ][1] === $cells[1];
}
check( 'A495: Scope::csv – backslash-quote cells stay one cell each (no extra formula cell)', $a495_ok );
check( 'A495: Scope::csv – formula cells keep their apostrophe guard', "'=1+1" === ( end( $a495_parsed )[0] ?? '' ) );

$a495_export = array( PassCaptain\Invoices::EXPORT_COLUMNS, array( 'Rechnung', 'R-1', '2026-11-16', 'abc', '', '=HYPERLINK("https://evil.example")', 1, '10.00', '20.00', '1.67', '-12.00', 'EUR', 'manual', 'Eigenhaendler', 'Größe \";=1+1;\"' ) );
$a495_csv = PassCaptain\Invoices::export_csv( $a495_export );
$a495_cells = a495_cells( $a495_csv )[1] ?? array();
check( 'A495: accounting export uses the shared writer: BOM, formula guard, RFC 4180 quoting, numbers kept', 0 === strpos( $a495_csv, "\xEF\xBB\xBF" ) && 15 === count( $a495_cells ) && "'=HYPERLINK(\"https://evil.example\")" === $a495_cells[5] && '-12.00' === $a495_cells[10] && 'Größe \";=1+1;\"' === $a495_cells[14] );
$a495_datev = PassCaptain\Invoices::export_csv( array( array( 'Umsatz', 'Buchungstext' ), array( '12,50', 'Größe =1' ) ), true );
check( 'A495: DATEV export: Windows-1252 without BOM through the same writer', 0 !== strpos( $a495_datev, "\xEF\xBB\xBF" ) && false !== strpos( $a495_datev, "Gr\xF6\xDFe =1" ) && false !== strpos( $a495_datev, '12,50;' ) );
