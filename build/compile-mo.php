<?php
/**
 * Dependency-free .po -> .mo compiler.
 *
 * Usage: php build/compile-mo.php languages/pass-captain-en_US.po languages/pass-captain-en_US.mo
 *
 * - Supports multi-line strings, escapes (\" \\ \n \t \r \0 octal/hex), msgctxt and plural forms.
 * - Skips fuzzy entries (except the header), obsolete (#~) entries and untranslated entries.
 * - Writes a little-endian GNU .mo file (magic 0x950412de) with originals sorted bytewise.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( $argc < 2 || $argc > 3 ) {
	fwrite( STDERR, "Usage: php build/compile-mo.php <input.po> [output.mo]\n" );
	exit( 2 );
}

$input  = $argv[1];
$output = $argc === 3 ? $argv[2] : preg_replace( '/\.po$/', '', $input ) . '.mo';

if ( ! is_readable( $input ) ) {
	fwrite( STDERR, "Cannot read $input\n" );
	exit( 1 );
}

/**
 * Decode a PO quoted string body (without the surrounding quotes).
 */
function pc_po_unescape( $s ) {
	return preg_replace_callback(
		'/\\\\(x[0-9A-Fa-f]{1,2}|[0-7]{1,3}|.)/s',
		static function ( $m ) {
			$c = $m[1];
			$map = array(
				'n'  => "\n",
				't'  => "\t",
				'r'  => "\r",
				'a'  => "\x07",
				'b'  => "\x08",
				'f'  => "\x0c",
				'v'  => "\x0b",
				'"'  => '"',
				'\\' => '\\',
				'?'  => '?',
				"'"  => "'",
			);
			if ( isset( $map[ $c ] ) ) {
				return $map[ $c ];
			}
			if ( 'x' === $c[0] && strlen( $c ) > 1 ) {
				return chr( hexdec( substr( $c, 1 ) ) );
			}
			if ( ctype_digit( $c ) ) {
				return chr( octdec( $c ) & 0xff );
			}
			return '\\' . $c;
		},
		$s
	);
}

/**
 * Parse a .po file into a list of entries.
 *
 * @return array<int, array{ctxt: ?string, id: ?string, plural: ?string, str: array<int,string>, fuzzy: bool}>
 */
function pc_po_parse( $file ) {
	$lines   = preg_split( '/\r\n|\r|\n/', file_get_contents( $file ) );
	$entries = array();
	$entry   = null;
	$field   = null;
	$fuzzy   = false;
	$lineno  = 0;

	$new_entry = static function () use ( &$fuzzy ) {
		return array( 'ctxt' => null, 'id' => null, 'plural' => null, 'str' => array(), 'fuzzy' => $fuzzy );
	};
	$flush = static function () use ( &$entry, &$entries, &$field, &$fuzzy ) {
		if ( null !== $entry && null !== $entry['id'] ) {
			$entries[] = $entry;
		}
		$entry = null;
		$field = null;
		$fuzzy = false;
	};

	foreach ( $lines as $raw ) {
		++$lineno;
		$line = trim( $raw );

		if ( '' === $line ) {
			$flush();
			continue;
		}
		if ( 0 === strpos( $line, '#~' ) ) {
			// Obsolete entry: ignore entirely.
			continue;
		}
		if ( '#' === $line[0] ) {
			// A comment after strings starts a new entry.
			if ( null !== $entry && null !== $field ) {
				$flush();
			}
			if ( 0 === strpos( $line, '#,' ) && preg_match( '/\bfuzzy\b/', $line ) ) {
				$fuzzy = true;
			}
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/s', $line, $m ) ) {
			$kw = $m[1];
			// msgctxt, or msgid without preceding msgctxt, after msgstr starts a new entry.
			if ( null !== $entry && ( 'msgctxt' === $kw || ( 'msgid' === $kw && 'ctxt' !== $field ) ) && null !== $field ) {
				$flush();
			}
			if ( null === $entry ) {
				$entry = $new_entry();
			}
			$value = pc_po_unescape( $m[3] );
			if ( 'msgctxt' === $kw ) {
				$entry['ctxt'] = $value;
				$field         = 'ctxt';
			} elseif ( 'msgid' === $kw ) {
				$entry['id'] = $value;
				$field       = 'id';
			} elseif ( 'msgid_plural' === $kw ) {
				$entry['plural'] = $value;
				$field           = 'plural';
			} else {
				$idx                  = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
				$entry['str'][ $idx ] = $value;
				$field                = 'str' . $idx;
			}
			continue;
		}
		if ( preg_match( '/^"(.*)"$/s', $line, $m ) && null !== $entry && null !== $field ) {
			$value = pc_po_unescape( $m[1] );
			if ( 0 === strpos( $field, 'str' ) ) {
				$entry['str'][ (int) substr( $field, 3 ) ] .= $value;
			} else {
				$entry[ $field ] .= $value;
			}
			continue;
		}
		fwrite( STDERR, "Warning: unparsable line $lineno in $file: $raw\n" );
	}
	$flush();

	return $entries;
}

$entries = pc_po_parse( $input );
$table   = array();
$skipped = 0;

foreach ( $entries as $e ) {
	$is_header = ( '' === $e['id'] && null === $e['ctxt'] );
	if ( $e['fuzzy'] && ! $is_header ) {
		++$skipped;
		continue;
	}
	ksort( $e['str'] );
	$strs = array_values( $e['str'] );
	if ( ! $strs || '' === implode( '', $strs ) ) {
		if ( ! $is_header ) {
			++$skipped;
		}
		continue;
	}
	if ( null !== $e['plural'] && in_array( '', $strs, true ) ) {
		// Incomplete plural translation.
		++$skipped;
		continue;
	}
	$key = ( null !== $e['ctxt'] ? $e['ctxt'] . "\x04" : '' ) . $e['id'];
	if ( null !== $e['plural'] ) {
		$key .= "\0" . $e['plural'];
	}
	$table[ $key ] = implode( "\0", $strs );
}

ksort( $table, SORT_STRING );

$count       = count( $table );
$header_size = 28;
$orig_table  = $header_size;
$trans_table = $orig_table + 8 * $count;
$data_start  = $trans_table + 8 * $count; // No hash table.

$orig_index  = '';
$trans_index = '';
$data        = '';
$offset      = $data_start;

foreach ( array_keys( $table ) as $orig ) {
	$orig        = (string) $orig;
	$orig_index .= pack( 'VV', strlen( $orig ), $offset );
	$data       .= $orig . "\0";
	$offset     += strlen( $orig ) + 1;
}
foreach ( $table as $trans ) {
	$trans_index .= pack( 'VV', strlen( $trans ), $offset );
	$data        .= $trans . "\0";
	$offset      += strlen( $trans ) + 1;
}

$mo = pack( 'VVVVVVV', 0x950412de, 0, $count, $orig_table, $trans_table, 0, $data_start )
	. $orig_index . $trans_index . $data;

if ( false === file_put_contents( $output, $mo ) ) {
	fwrite( STDERR, "Cannot write $output\n" );
	exit( 1 );
}

$has_header = isset( $table[''] ) ? 1 : 0;
printf( "Wrote %s: %d strings (%d translations + %d header), %d skipped.\n", $output, $count, $count - $has_header, $has_header, $skipped );
