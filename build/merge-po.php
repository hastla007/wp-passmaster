<?php
/**
 * Merge languages/pass-captain.pot into one or more .po files (msgmerge replacement).
 *
 * Usage: php build/merge-po.php languages/pass-captain.pot languages/pass-captain-fr_FR.po [...]
 *
 * - Entry order, references, translator comments and flags come from the .pot.
 * - Existing translations are kept for every msgid (+ context) still in the .pot.
 * - Strings no longer in the .pot are dropped (they stay in git history).
 * - The .po header is kept; POT-Creation-Date is taken from the .pot.
 * - Prints the number of untranslated entries per file.
 */

require __DIR__ . '/i18n-po.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php build/merge-po.php <file.pot> <file.po> [...]\n" );
	exit( 2 );
}

$pot = pc_i18n_parse( $argv[1] );
if ( null === $pot['header'] ) {
	fwrite( STDERR, "No header in {$argv[1]}\n" );
	exit( 1 );
}
preg_match( '/POT-Creation-Date: ([^\n]*)/', $pot['header']['msgstr'][0], $pot_date );

foreach ( array_slice( $argv, 2 ) as $po_file ) {
	$po     = pc_i18n_parse( $po_file );
	$header = $po['header'] ?? $pot['header'];
	if ( $pot_date ) {
		$header['msgstr'][0] = (string) preg_replace( '/POT-Creation-Date: [^\n]*/', 'POT-Creation-Date: ' . $pot_date[1], $header['msgstr'][0] );
	}
	$nplurals = pc_i18n_nplurals( $header );

	$merged       = array();
	$untranslated = 0;
	$kept         = 0;
	foreach ( $pot['entries'] as $key => $entry ) {
		$old = $po['entries'][ $key ] ?? null;
		if ( $old && null !== $entry['msgid_plural'] && null === $old['msgid_plural'] ) {
			$old = null; // Singular became plural: needs a fresh translation.
		}
		$entry['msgstr'] = $old ? $old['msgstr'] : array();
		if ( null !== $entry['msgid_plural'] ) {
			$entry['msgstr'] = array_slice( array_pad( $entry['msgstr'], $nplurals, '' ), 0, $nplurals );
		} else {
			$entry['msgstr'] = array( $entry['msgstr'][0] ?? '' );
		}
		$done = '' !== implode( '', $entry['msgstr'] ) && ! in_array( '', $entry['msgstr'], true );
		$done ? ++$kept : ++$untranslated;
		$merged[ $key ] = $entry;
	}
	$dropped = count( array_diff_key( $po['entries'], $pot['entries'] ) );

	pc_i18n_write( $po_file, $header, $merged );
	printf( "%s: %d entries, %d translated, %d untranslated, %d dropped\n", $po_file, count( $merged ), $kept, $untranslated, $dropped );
}
