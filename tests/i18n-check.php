<?php
/**
 * A107: translation consistency.
 *
 * For every languages/*.po file:
 * - no fuzzy entries (machine-matched suggestions must be reviewed) and no untranslated entries,
 * - printf placeholders (%s, %d, %1$s, %%) are the same as in the msgid (as a multiset; positional
 *   order may change), {placeholders} and HTML tags are kept, plural forms complete,
 * - the .po and the .pot have the same msgids, and the .mo is up to date (compiled from the .po).
 * - with --fresh-pot=<file> (a .pot just generated from the code with `wp i18n make-pot`): the committed
 *   .pot has exactly the same msgids, i.e. no source string was added or changed without updating it.
 * Usage: php tests/i18n-check.php [--allow-incomplete] [--fresh-pot=<file>]
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = dirname( __DIR__ );
$allow_incomplete = in_array( '--allow-incomplete', $argv, true );

function wpcap_po_parse( $file ) {
	$entries = array(); $cur = null; $field = null;
	$flush = static function () use ( &$entries, &$cur ) { if ( $cur && ( '' !== $cur['msgid'] || isset( $cur['msgstr'][0] ) ) ) { $entries[] = $cur; } };
	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( '' === trim( $line ) ) { $flush(); $cur = null; $field = null; continue; }
		if ( 0 === strpos( $line, '#~' ) ) { continue; }
		if ( ! $cur ) { $cur = array( 'flags' => '', 'msgctxt' => null, 'msgid' => '', 'msgid_plural' => null, 'msgstr' => array() ); }
		if ( 0 === strpos( $line, '#,' ) ) { $cur['flags'] .= $line; continue; }
		if ( '#' === ( $line[0] ?? '' ) ) { continue; }
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m ) ) {
			$field = array( $m[1], '' === $m[2] ? null : (int) $m[2] );
			$val = stripcslashes( $m[3] );
			if ( 'msgstr' === $m[1] ) { $cur['msgstr'][ $field[1] ?? 0 ] = $val; } else { $cur[ $m[1] ] = $val; }
			continue;
		}
		if ( preg_match( '/^"(.*)"$/', $line, $m ) && $field ) {
			$val = stripcslashes( $m[1] );
			if ( 'msgstr' === $field[0] ) { $cur['msgstr'][ $field[1] ?? 0 ] .= $val; } else { $cur[ $field[0] ] .= $val; }
		}
	}
	$flush();
	return $entries;
}
function wpcap_placeholders( $s ) {
	preg_match_all( '/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[sdfFuxXeEgGc]|%%/', $s, $p );
	$printf = array_map( static fn( $x ) => preg_replace( '/^%\d+\$/', '%', $x ), $p[0] );
	sort( $printf );
	preg_match_all( '/\{[a-z_][a-z0-9_]*\}/', $s, $b );
	$braces = $b[0]; sort( $braces );
	preg_match_all( '/<\/?(a|abbr|b|br|code|em|i|kbd|li|mark|ol|p|small|span|strong|sub|sup|u|ul)\b[^>]*>/i', $s, $h );
	$tags = array_map( 'strtolower', $h[1] ); sort( $tags );
	return array( 'printf' => $printf, 'braces' => $braces, 'tags' => $tags );
}
$fail = 0;
$pot = wpcap_po_parse( $root . '/languages/wp-pass-captain.pot' );
$pot_ids = array();
foreach ( $pot as $e ) { if ( '' !== $e['msgid'] ) { $pot_ids[ ( $e['msgctxt'] ?? '' ) . "\x04" . $e['msgid'] ] = true; } }
foreach ( $argv as $arg ) {
	if ( 0 !== strpos( $arg, '--fresh-pot=' ) ) { continue; }
	$fresh = array();
	foreach ( wpcap_po_parse( substr( $arg, 12 ) ) as $e ) { if ( '' !== $e['msgid'] ) { $fresh[ ( $e['msgctxt'] ?? '' ) . "\x04" . $e['msgid'] ] = true; } }
	$new_ids = array_diff_key( $fresh, $pot_ids );
	$gone_ids = array_diff_key( $pot_ids, $fresh );
	if ( $new_ids || $gone_ids ) {
		$fail = 1;
		echo 'FAIL languages/wp-pass-captain.pot is outdated: ' . count( $new_ids ) . ' new, ' . count( $gone_ids ) . " removed source strings (regenerate with wp i18n make-pot, then msgmerge)\n";
		foreach ( array_slice( array_keys( $new_ids ), 0, 10 ) as $k ) { echo '     + ' . str_replace( "\x04", ' | ', $k ) . "\n"; }
	} else {
		echo "ok   languages/wp-pass-captain.pot matches the source strings\n";
	}
}
foreach ( glob( $root . '/languages/wp-pass-captain-*.po' ) as $po ) {
	$locale = preg_replace( '/^.*wp-pass-captain-(.+)\.po$/', '$1', $po );
	$problems = array(); $fuzzy = 0; $untranslated = 0; $ids = array();
	foreach ( wpcap_po_parse( $po ) as $e ) {
		if ( '' === $e['msgid'] ) { continue; }
		$ids[ ( $e['msgctxt'] ?? '' ) . "\x04" . $e['msgid'] ] = true;
		if ( false !== strpos( $e['flags'], 'fuzzy' ) ) { ++$fuzzy; continue; }
		$strs = $e['msgstr'];
		if ( ! $strs || in_array( '', $strs, true ) ) { ++$untranslated; continue; }
		$want = wpcap_placeholders( $e['msgid'] );
		$want_plural = null !== $e['msgid_plural'] ? wpcap_placeholders( $e['msgid_plural'] ) : null;
		foreach ( $strs as $i => $str ) {
			$got = wpcap_placeholders( $str );
			// Only strings that go through sprintf (php-format) have placeholders; a "%" elsewhere is text.
			$ok_printf = false === strpos( $e['flags'], 'php-format' ) || $got['printf'] === $want['printf'] || ( $want_plural && $got['printf'] === $want_plural['printf'] )
				// Singular forms may drop a lone %d ("one ticket"), as gettext allows.
				|| ( $want_plural && array_values( array_diff( $want_plural['printf'], array( '%d' ) ) ) === $got['printf'] );
			if ( ! $ok_printf ) { $problems[] = "printf placeholders differ: \"{$e['msgid']}\" → \"$str\""; }
			if ( $got['braces'] !== $want['braces'] && ( ! $want_plural || $got['braces'] !== $want_plural['braces'] ) ) { $problems[] = "{placeholders} differ: \"{$e['msgid']}\" → \"$str\""; }
			if ( $got['tags'] !== $want['tags'] && ( ! $want_plural || $got['tags'] !== $want_plural['tags'] ) ) { $problems[] = "HTML tags differ: \"{$e['msgid']}\" → \"$str\""; }
		}
	}
	$missing = array_diff_key( $pot_ids, $ids );
	$extra = array_diff_key( $ids, $pot_ids );
	if ( $missing ) { $problems[] = count( $missing ) . ' msgids of the .pot are missing (run msgmerge)'; }
	if ( $extra ) { $problems[] = count( $extra ) . ' msgids are not in the .pot (obsolete, run msgmerge)'; }
	if ( ! $allow_incomplete && ( $fuzzy || $untranslated ) ) { $problems[] = "$fuzzy fuzzy and $untranslated untranslated entries"; }
	$mo = substr( $po, 0, -3 ) . '.mo';
	$tmp = tempnam( sys_get_temp_dir(), 'mo' );
	passthru( 'php ' . escapeshellarg( $root . '/build/compile-mo.php' ) . ' ' . escapeshellarg( $po ) . ' ' . escapeshellarg( $tmp ) . ' >/dev/null', $rc );
	if ( 0 !== $rc || ! is_file( $mo ) || file_get_contents( $tmp ) !== file_get_contents( $mo ) ) { $problems[] = basename( $mo ) . ' is not compiled from the current .po (php build/compile-mo.php)'; }
	unlink( $tmp );
	if ( $problems ) {
		$fail = 1;
		echo "FAIL $locale: " . count( $problems ) . " problem(s)\n";
		foreach ( array_slice( $problems, 0, 25 ) as $p ) { echo "     - $p\n"; }
	} else {
		echo "ok   $locale: complete, placeholders and tags consistent, .mo up to date\n";
	}
}
exit( $fail );
