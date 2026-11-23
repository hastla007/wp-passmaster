<?php
/**
 * Minimal, dependency-free gettext PO/POT reader and writer shared by the
 * i18n build scripts (build/merge-po.php, build/check-i18n.php).
 *
 * An entry is an array with the keys:
 *   translator (string[]), extracted refs (string[]), flags (string[]),
 *   msgctxt (?string), msgid (string), msgid_plural (?string), msgstr (string[]),
 *   obsolete (bool).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

/**
 * Decode a PO quoted string body (without the surrounding quotes).
 */
function pc_i18n_unescape( string $s ): string {
	return (string) preg_replace_callback(
		'/\\\\(.)/s',
		static function ( array $m ): string {
			$map = array(
				'n'  => "\n",
				't'  => "\t",
				'r'  => "\r",
				'"'  => '"',
				'\\' => '\\',
			);
			return $map[ $m[1] ] ?? '\\' . $m[1];
		},
		$s
	);
}

/**
 * Encode a string for a PO quoted literal (without the surrounding quotes).
 */
function pc_i18n_escape( string $s ): string {
	return strtr(
		$s,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
			"\t" => '\\t',
			"\r" => '\\r',
		)
	);
}

/**
 * Lookup key of an entry: context + EOT + msgid (as in .mo files).
 */
function pc_i18n_key( array $entry ): string {
	return ( null !== $entry['msgctxt'] ? $entry['msgctxt'] . "\x04" : '' ) . $entry['msgid'];
}

/**
 * Parse a PO/POT file.
 *
 * @return array{header: ?array, entries: array<string, array>}
 */
function pc_i18n_parse( string $file ): array {
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		throw new RuntimeException( "Cannot read $file" );
	}

	$entries = array();
	$header  = null;
	$blank   = static function (): array {
		return array(
			'translator'   => array(),
			'extracted'    => array(),
			'refs'         => array(),
			'flags'        => array(),
			'msgctxt'      => null,
			'msgid'        => null,
			'msgid_plural' => null,
			'msgstr'       => array(),
			'obsolete'     => false,
		);
	};
	$cur     = $blank();
	$field   = null;
	$index   = 0;

	$flush = static function () use ( &$cur, &$entries, &$header, $blank, &$field ): void {
		if ( null !== $cur['msgid'] ) {
			if ( '' === $cur['msgid'] && null === $cur['msgctxt'] && ! $cur['obsolete'] ) {
				$header = $cur;
			} elseif ( ! $cur['obsolete'] ) {
				$entries[ pc_i18n_key( $cur ) ] = $cur;
			}
		}
		$cur   = $blank();
		$field = null;
	};

	foreach ( $lines as $raw ) {
		$line = rtrim( $raw, "\r" );
		if ( '' === trim( $line ) ) {
			$flush();
			continue;
		}
		if ( str_starts_with( $line, '#~' ) ) {
			// Obsolete entries are ignored entirely.
			$cur['obsolete'] = true;
			$line            = ltrim( substr( $line, 2 ) );
			if ( '' === $line ) {
				continue;
			}
		} elseif ( str_starts_with( $line, '#' ) ) {
			if ( null !== $field && null !== $cur['msgid'] ) {
				$flush();
			}
			$kind = substr( $line, 0, 2 );
			$text = ltrim( substr( $line, 2 ) );
			if ( '#.' === $kind ) {
				$cur['translator'][] = $text;
			} elseif ( '#:' === $kind ) {
				$cur['refs'][] = $text;
			} elseif ( '#,' === $kind ) {
				foreach ( explode( ',', $text ) as $flag ) {
					$flag = trim( $flag );
					if ( '' !== $flag ) {
						$cur['flags'][] = $flag;
					}
				}
			} else {
				$cur['extracted'][] = $line;
			}
			continue;
		}

		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"\s*$/s', $line, $m ) ) {
			$kw = $m[1];
			if ( 'msgctxt' === $kw || ( 'msgid' === $kw && null !== $cur['msgid'] && null !== $field && str_starts_with( $field, 'msgstr' ) ) ) {
				if ( null !== $cur['msgid'] ) {
					$flush();
				}
			}
			$value = pc_i18n_unescape( $m[3] );
			if ( str_starts_with( $kw, 'msgstr' ) ) {
				$index                   = '' !== ( $m[2] ?? '' ) ? (int) $m[2] : 0;
				$cur['msgstr'][ $index ] = $value;
				$field                   = 'msgstr';
			} else {
				$cur[ $kw ] = $value;
				$field      = $kw;
			}
			continue;
		}

		if ( preg_match( '/^"(.*)"\s*$/s', $line, $m ) && null !== $field ) {
			$value = pc_i18n_unescape( $m[1] );
			if ( 'msgstr' === $field ) {
				$cur['msgstr'][ $index ] .= $value;
			} else {
				$cur[ $field ] .= $value;
			}
			continue;
		}

		throw new RuntimeException( "Unparseable line in $file: $line" );
	}
	$flush();

	return array(
		'header'  => $header,
		'entries' => $entries,
	);
}

/**
 * Format one keyword + string the way WP-CLI / gettext do (split after "\n").
 */
function pc_i18n_format_string( string $kw, string $s ): string {
	$parts = preg_split( '/(?<=\n)/', $s, -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $parts || count( $parts ) <= 1 ) {
		return $kw . ' "' . pc_i18n_escape( $s ) . "\"\n";
	}
	$out = $kw . " \"\"\n";
	foreach ( $parts as $part ) {
		$out .= '"' . pc_i18n_escape( $part ) . "\"\n";
	}
	return $out;
}

/**
 * Serialise one entry.
 */
function pc_i18n_format_entry( array $e, int $nplurals ): string {
	$out = '';
	foreach ( $e['extracted'] as $c ) {
		$out .= $c . "\n";
	}
	foreach ( $e['translator'] as $c ) {
		$out .= '#. ' . $c . "\n";
	}
	foreach ( $e['refs'] as $c ) {
		$out .= '#: ' . $c . "\n";
	}
	if ( $e['flags'] ) {
		$out .= '#, ' . implode( ', ', array_unique( $e['flags'] ) ) . "\n";
	}
	if ( null !== $e['msgctxt'] ) {
		$out .= pc_i18n_format_string( 'msgctxt', $e['msgctxt'] );
	}
	$out .= pc_i18n_format_string( 'msgid', $e['msgid'] );
	if ( null !== $e['msgid_plural'] ) {
		$out .= pc_i18n_format_string( 'msgid_plural', $e['msgid_plural'] );
		for ( $i = 0; $i < $nplurals; $i++ ) {
			$out .= pc_i18n_format_string( "msgstr[$i]", $e['msgstr'][ $i ] ?? '' );
		}
	} else {
		$out .= pc_i18n_format_string( 'msgstr', $e['msgstr'][0] ?? '' );
	}
	return $out;
}

/**
 * Number of plural forms declared in a header entry (defaults to 2).
 */
function pc_i18n_nplurals( ?array $header ): int {
	if ( $header && preg_match( '/nplurals\s*=\s*(\d+)/', $header['msgstr'][0] ?? '', $m ) ) {
		return max( 1, (int) $m[1] );
	}
	return 2;
}

/**
 * Write a full PO file.
 *
 * @param array<int, string> $preamble Comment lines printed before the header.
 */
function pc_i18n_write( string $file, array $header, array $entries, array $preamble = array() ): void {
	$nplurals = pc_i18n_nplurals( $header );
	$out      = '';
	foreach ( $preamble as $line ) {
		$out .= $line . "\n";
	}
	$out .= pc_i18n_format_entry( $header, $nplurals );
	foreach ( $entries as $e ) {
		$out .= "\n" . pc_i18n_format_entry( $e, $nplurals );
	}
	file_put_contents( $file, $out );
}

/**
 * Placeholders of a printf-style string, normalised for comparison.
 *
 * Positional (%1$s) and sequential (%s) placeholders are both mapped to
 * "position:type" so a translation may reorder arguments using %n$ syntax.
 *
 * @return string[] Sorted list.
 */
function pc_i18n_placeholders( string $s ): array {
	$s = str_replace( '%%', '', $s );
	preg_match_all( '/%(?:(\d+)\$)?[-+ 0#\']*(?:\d+)?(?:\.\d+)?([bcdeEfFgGosuxX])/', $s, $m, PREG_SET_ORDER );
	$out = array();
	$seq = 0;
	foreach ( $m as $p ) {
		$pos   = '' !== $p[1] ? (int) $p[1] : ++$seq;
		$out[] = $pos . ':' . $p[2];
	}
	$out = array_values( array_unique( $out ) );
	sort( $out );
	return $out;
}
