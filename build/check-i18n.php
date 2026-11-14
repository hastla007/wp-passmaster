<?php
/**
 * Translation completeness check for CI.
 *
 * Usage: php build/check-i18n.php [--no-mo]
 *
 * Fails (exit 1) when, for any languages/pass-captain-*.po:
 * - a msgid (+ context) of languages/pass-captain.pot is missing, untranslated or fuzzy,
 * - a translation's printf placeholders differ from the source (%s, %d, %1$s ...;
 *   positional and sequential forms are treated as equivalent, %% is ignored),
 * - a translation drops or alters a {token} placeholder of the source (mail templates),
 * - a plural entry does not provide every plural form of the locale,
 * - the .po still contains entries that are no longer in the .pot,
 * - the committed .mo is not the compiled form of the .po (skip with --no-mo).
 *
 * Regenerate/merge with build/merge-po.php and compile with build/compile-mo.php.
 */

require __DIR__ . '/i18n-po.php';

$root    = dirname( __DIR__ );
$pot      = $root . '/languages/pass-captain.pot';
$check_mo = ! in_array( '--no-mo', $argv, true );

/**
 * Sorted list of {token} placeholders.
 *
 * @return string[]
 */
function pc_i18n_tokens( string $s ): array {
	preg_match_all( '/\{[a-z0-9_]+\}/', $s, $m );
	$t = array_values( array_unique( $m[0] ) );
	sort( $t );
	return $t;
}

$template = pc_i18n_parse( $pot );
$files    = glob( $root . '/languages/pass-captain-*.po' ) ?: array();
if ( ! $files ) {
	fwrite( STDERR, "No .po files found.\n" );
	exit( 1 );
}

$errors = 0;
$report = static function ( string $file, string $msg ) use ( &$errors, $root ): void {
	++$errors;
	if ( $errors <= 200 ) {
		echo str_replace( $root . '/', '', $file ), ': ', $msg, "\n";
	}
};
$short  = static function ( string $s ): string {
	$s = pc_i18n_escape( $s );
	return mb_strlen( $s ) > 70 ? mb_substr( $s, 0, 67 ) . '...' : $s;
};

foreach ( $files as $file ) {
	$po       = pc_i18n_parse( $file );
	$nplurals = pc_i18n_nplurals( $po['header'] );
	$done     = 0;

	foreach ( $template['entries'] as $key => $src ) {
		$label = '"' . $short( $src['msgid'] ) . '"' . ( null !== $src['msgctxt'] ? ' [' . $src['msgctxt'] . ']' : '' );
		$tr    = $po['entries'][ $key ] ?? null;
		if ( null === $tr ) {
			$report( $file, "missing $label" );
			continue;
		}
		if ( in_array( 'fuzzy', $tr['flags'], true ) ) {
			$report( $file, "fuzzy $label" );
			continue;
		}

		$plural = null !== $src['msgid_plural'];
		$forms  = $plural ? $nplurals : 1;
		$ok     = true;
		for ( $i = 0; $i < $forms; $i++ ) {
			$value = $tr['msgstr'][ $i ] ?? '';
			if ( '' === $value ) {
				$report( $file, "untranslated $label" . ( $plural ? " (form $i)" : '' ) );
				$ok = false;
				continue;
			}
			// Form 0 may follow the singular or (for nplurals=1 / zero-like forms) the plural source.
			$sources = $plural ? ( 0 === $i ? array( $src['msgid'], $src['msgid_plural'] ) : array( $src['msgid_plural'] ) ) : array( $src['msgid'] );

			if ( in_array( 'php-format', $src['flags'], true ) ) {
				$match = false;
				foreach ( $sources as $s ) {
					$match = $match || pc_i18n_placeholders( $s ) === pc_i18n_placeholders( $value );
				}
				if ( ! $match ) {
					$report( $file, "placeholder mismatch $label => \"" . $short( $value ) . '"' );
					$ok = false;
				}
			}
			$match = false;
			foreach ( $sources as $s ) {
				$match = $match || ! array_diff( pc_i18n_tokens( $s ), pc_i18n_tokens( $value ) );
			}
			if ( ! $match ) {
				$report( $file, "{token} mismatch $label => \"" . $short( $value ) . '"' );
				$ok = false;
			}
		}
		if ( $ok ) {
			++$done;
		}
	}

	foreach ( array_diff_key( $po['entries'], $template['entries'] ) as $stale ) {
		$report( $file, 'stale entry not in .pot "' . $short( $stale['msgid'] ) . '" (run build/merge-po.php)' );
	}

	if ( $check_mo ) {
		$mo  = preg_replace( '/\.po$/', '.mo', $file );
		$tmp = tempnam( sys_get_temp_dir(), 'pcmo' );
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/compile-mo.php' ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1';
		exec( $cmd, $out, $code );
		if ( 0 !== $code ) {
			$report( $file, 'compile-mo.php failed: ' . implode( ' ', $out ) );
		} elseif ( ! is_file( $mo ) || hash_file( 'sha256', $mo ) !== hash_file( 'sha256', $tmp ) ) {
			$report( $file, 'compiled .mo is missing or outdated (run php build/compile-mo.php ' . basename( $file ) . ')' );
		}
		@unlink( $tmp );
	}

	printf( "%s: %d/%d translated and valid\n", basename( $file ), $done, count( $template['entries'] ) );
}

if ( $errors > 200 ) {
	echo '... ', $errors - 200, " more\n";
}
if ( $errors ) {
	echo "i18n check FAILED: $errors problem(s).\n";
	exit( 1 );
}
echo 'i18n check passed: ', count( $template['entries'] ), ' msgids, ', count( $files ), " locales.\n";
