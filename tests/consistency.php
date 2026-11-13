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
// A269: readme.txt documents every external service; every remote host in src/ is named there.
$services = (string) ( preg_match( '/^== External services ==\n(.*?)^== (?!External)/ms', $readme, $m ) ? $m[1] : '' );
$check( 'readme.txt has an "== External services ==" section', '' !== $services );
$local_hosts = array( 'developer.wordpress.org', 'example.com', 'github.com', 'www.googleapis.com' ); // Doc links, samples, OAuth scope identifier.
$hosts = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	preg_match_all( '#https://([a-z0-9.-]+\.[a-z]{2,})#', (string) file_get_contents( (string) $file ), $found );
	$hosts = array_merge( $hosts, $found[1] );
}
$undocumented = array_filter( array_unique( $hosts ), static fn( $h ) => ! in_array( $h, $local_hosts, true ) && false === strpos( $services, $h ) );
$check( 'every remote host in src/ is listed under External services' . ( $undocumented ? ' – missing ' . implode( ', ', $undocumented ) : '' ), ! $undocumented );
foreach ( array( 'Stripe', 'PayPal', 'Mollie', 'Twilio', 'SMS-HTTP', 'Apple', 'Google Wallet', 'fiskaltrust', 'efsta', 'Webhooks', 'Affiliate Captain', 'Voucher Captain' ) as $service ) {
	$check( "External services names $service", false !== stripos( $services, $service ) );
}
$check( 'every External services entry links terms/privacy or names the operator-chosen recipient', ! array_filter( preg_split( '/^= /m', $services ), static fn( $part ) => '' !== trim( $part ) && 0 !== strpos( $part, 'Pass Captain' ) && 0 !== strpos( $part, 'Keine externen' ) && false === strpos( $part, 'https://' ) && false === strpos( $part, 'Betreiber' ) ) );
// A270: tweetnacl ships with its readable source and a source reference that is not Markdown.
$nacl = $root . '/assets/vendor/tweetnacl/';
$nacl_source = (string) @file_get_contents( $nacl . 'SOURCE.txt' );
$check( 'tweetnacl: nacl-fast.js (source) ships next to nacl-fast.min.js', is_file( $nacl . 'nacl-fast.js' ) && is_file( $nacl . 'nacl-fast.min.js' ) );
$check( 'tweetnacl: SOURCE.txt names version, URL, license and the checksums of both files', false !== strpos( $nacl_source, '1.0.3' ) && false !== strpos( $nacl_source, 'https://github.com/dchest/tweetnacl-js' ) && false !== strpos( $nacl_source, 'Unlicense' ) && is_file( $nacl . 'nacl-fast.js' ) && false !== strpos( $nacl_source, hash_file( 'sha256', $nacl . 'nacl-fast.js' ) ) && false !== strpos( $nacl_source, hash_file( 'sha256', $nacl . 'nacl-fast.min.js' ) ) );
$check( 'readme.txt points to the tweetnacl source reference', false !== strpos( $readme, 'assets/vendor/tweetnacl/SOURCE.txt' ) );
echo $failed ? "$failed failed\n" : "all passed\n";
exit( $failed ? 1 : 0 );
