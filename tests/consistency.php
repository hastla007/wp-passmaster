<?php
/**
 * Release metadata consistency (CI): plugin header, WPCAP_VERSION, readme.txt, composer.json and the
 * changelogs must agree. Run: php tests/consistency.php (exit code 1 on a mismatch).
 */
$root = dirname( __DIR__ );
$main = (string) file_get_contents( $root . '/pass-captain.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );
$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$header = static function ( $text, $name ) { return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : ''; };
$failed = 0;
$check = static function ( $name, $ok ) use ( &$failed ) { echo ( $ok ? '  ok   ' : '  FAIL ' ) . $name . "\n"; if ( ! $ok ) { $failed++; } };

$version = $header( $main, 'Version' );
preg_match( "/define\(\s*'WPCAP_VERSION',\s*'([^']+)'\s*\)/", $main, $const );
$check( "plugin header Version ($version) = WPCAP_VERSION (" . ( $const[1] ?? '?' ) . ')', '' !== $version && $version === ( $const[1] ?? '' ) );
$check( 'readme Stable tag = plugin version', $header( $readme, 'Stable tag' ) === $version );
$check( 'Requires at least: header = readme', '' !== $header( $main, 'Requires at least' ) && $header( $main, 'Requires at least' ) === $header( $readme, 'Requires at least' ) );
$check( 'Requires PHP: header = readme', '' !== $header( $main, 'Requires PHP' ) && $header( $main, 'Requires PHP' ) === $header( $readme, 'Requires PHP' ) );
$check( 'composer require php matches Requires PHP', '>=' . $header( $main, 'Requires PHP' ) === ( $composer['require']['php'] ?? '' ) );
$check( 'composer platform php matches Requires PHP', 0 === strpos( (string) ( $composer['config']['platform']['php'] ?? '' ), $header( $main, 'Requires PHP' ) ) );
$check( "CHANGELOG.md has a section for $version", (bool) preg_match( '/^## ' . preg_quote( $version, '/' ) . '\b/m', $changelog ) );
$check( "readme.txt changelog has an entry for $version", false !== strpos( $readme, '= ' . $version . ' =' ) );
$check( 'Text Domain is pass-captain', 'pass-captain' === $header( $main, 'Text Domain' ) );
// Every released version in CHANGELOG.md also appears in the readme changelog.
preg_match_all( '/^## (\d+\.\d+\.\d+)/m', $changelog, $released );
$missing = array_filter( $released[1], static fn( $v ) => version_compare( $v, '1.1.0', '>=' ) && false === strpos( $readme, '= ' . $v . ' =' ) );
$check( 'readme changelog lists every release since 1.1.0' . ( $missing ? ' – missing ' . implode( ', ', $missing ) : '' ), ! $missing );
// Documentation links in README.md point to existing files.
preg_match_all( '/\]\((docs\/[^)#]+)\)/', (string) file_get_contents( $root . '/README.md' ), $links );
$broken = array_filter( array_unique( $links[1] ), static fn( $l ) => ! file_exists( $root . '/' . $l ) );
$check( 'README.md doc links resolve' . ( $broken ? ' – ' . implode( ', ', $broken ) : '' ), ! $broken );
echo $failed ? "$failed failed\n" : "all passed\n";
exit( $failed ? 1 : 0 );
