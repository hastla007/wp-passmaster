<?php
// phpcs:ignoreFile
/**
 * A103: bacon/bacon-qr-code and dasprid/enum are shipped namespace-prefixed (Strauss, vendor-prefixed/,
 * PassCaptain\Vendor\…), so another plugin with other versions of these libraries or of endroid/qr-code
 * (real case: endroid 4.x) cannot collide. The collision itself runs in fresh child processes where the
 * foreign classes are declared before the plugin's autoloader (zz_a103/child.php).
 */

echo "A103: prefixed QR libraries\n";
$a103_root  = rtrim( WPCAP_PATH, '/' ) . '/';
$a103_child = static function ( $mode ) use ( $a103_root ) {
	$raw = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a103/child.php' ) . ' ' . escapeshellarg( $a103_root ) . ' ' . escapeshellarg( $mode ) . ' 2>&1' );
	$out = json_decode( trim( $raw ), true );
	return is_array( $out ) ? $out : array( 'raw' => substr( $raw, 0, 400 ) );
};
$a103_expect = md5( (string) json_encode( PassCaptain\QrCode::matrix( 'PC1.a103-ticket-payload' ) ) );
$a103_own    = static fn( $file ) => '' !== (string) $file && 0 === strpos( (string) $file, str_replace( '\\', '/', $a103_root ) . 'vendor-prefixed/' );

// 1. Another plugin loaded endroid/qr-code 4.x and an incompatible BaconQrCode/DASPRiD first.
$a103_f = $a103_child( 'foreign' );
check( 'A103: child process with foreign Endroid\\QrCode\\QrCode, BaconQrCode and DASPRiD classes ran' . ( isset( $a103_f['raw'] ) ? ' – ' . $a103_f['raw'] : '' ), ! isset( $a103_f['raw'] ) && '' === ( $a103_f['error'] ?? 'x' ) );
check( 'A103: the ticket QR code renders although a different Endroid\\QrCode\\QrCode (4.x) and BaconQrCode are declared', ! empty( $a103_f['svg_ok'] ) && ( $a103_f['matrix_size'] ?? 0 ) > 20 );
check( 'A103: the matrix is identical to the one without foreign libraries', $a103_expect === ( $a103_f['matrix_md5'] ?? '' ) );
check( 'A103: encoder and enum come from the plugin\'s prefixed copy (vendor-prefixed/)', $a103_own( $a103_f['encoder_file'] ?? '' ) && $a103_own( $a103_f['enum_file'] ?? '' ) );
check( 'A103: the foreign classes stay untouched (no redeclaration, no fatal error)', ! empty( $a103_f['foreign_intact'] ) );
check( 'A103: the status page does not report a foreign encoder', '' === ( $a103_f['foreign'] ?? 'x' ) );

// 2. As in the release ZIP: the unprefixed packages cannot be loaded; nothing even asks for them.
$a103_i = $a103_child( 'isolated' );
check( 'A103: QR works with only the prefixed copy loadable' . ( isset( $a103_i['raw'] ) ? ' – ' . $a103_i['raw'] : '' ), ! empty( $a103_i['svg_ok'] ) && $a103_expect === ( $a103_i['matrix_md5'] ?? '' ) );
check( 'A103: no autoload request for unprefixed BaconQrCode, DASPRiD or Endroid classes at runtime', array() === ( $a103_i['requested'] ?? array( 'x' ) ) );

// 3. Source and package layout.
$a103_unprefixed = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $a103_root . 'src', FilesystemIterator::SKIP_DOTS ) ) as $a103_file ) {
	if ( 'php' !== $a103_file->getExtension() ) { continue; }
	foreach ( file( $a103_file->getPathname() ) as $a103_n => $a103_line ) {
		// Code references only (doc comments may name the packages); \\ in string literals counts too.
		if ( preg_match( '#(?<![\w\\\\])\\\\{0,2}(BaconQrCode|DASPRiD|Endroid)\\\\{1,2}[A-Z]#', $a103_line ) && ! preg_match( '#^\s*(\*|//)#', $a103_line ) ) {
			$a103_unprefixed[] = substr( $a103_file->getPathname(), strlen( $a103_root ) ) . ':' . ( $a103_n + 1 );
		}
	}
}
check( 'A103: src/ references no unprefixed BaconQrCode/DASPRiD/Endroid class' . ( $a103_unprefixed ? ' – ' . implode( ', ', array_slice( $a103_unprefixed, 0, 5 ) ) : '' ), array() === $a103_unprefixed );

$a103_vendor_ns = array();
$a103_php_files = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $a103_root . 'vendor-prefixed', FilesystemIterator::SKIP_DOTS ) ) as $a103_file ) {
	if ( 'php' !== $a103_file->getExtension() ) { continue; }
	++$a103_php_files;
	$a103_src = (string) file_get_contents( $a103_file->getPathname() );
	if ( ! preg_match( '/^namespace\s+PassCaptain\\\\Vendor\\\\(BaconQrCode|DASPRiD\\\\Enum)\b/m', $a103_src ) || preg_match( '/^use\s+(BaconQrCode|DASPRiD)\\\\/m', $a103_src ) ) {
		$a103_vendor_ns[] = substr( $a103_file->getPathname(), strlen( $a103_root ) );
	}
}
check( 'A103: every file in vendor-prefixed/ declares and imports only PassCaptain\\Vendor\\ namespaces' . ( $a103_vendor_ns ? ' – ' . implode( ', ', array_slice( $a103_vendor_ns, 0, 5 ) ) : '' ), $a103_php_files > 25 && array() === $a103_vendor_ns );
check( 'A103: licences of the prefixed packages are shipped', is_file( $a103_root . 'vendor-prefixed/bacon/bacon-qr-code/LICENSE' ) && is_file( $a103_root . 'vendor-prefixed/dasprid/enum/LICENSE' ) );

$a103_composer = json_decode( (string) file_get_contents( $a103_root . 'composer.json' ), true );
$a103_lock     = json_decode( (string) file_get_contents( $a103_root . 'composer.lock' ), true );
check( 'A103: the unprefixed packages are dev-only (composer install --no-dev puts none of them into the ZIP)', ! isset( $a103_composer['require']['bacon/bacon-qr-code'] ) && ! isset( $a103_composer['require']['endroid/qr-code'] ) && isset( $a103_composer['require-dev']['bacon/bacon-qr-code'] ) && ! in_array( 'bacon/bacon-qr-code', array_column( $a103_lock['packages'] ?? array(), 'name' ), true ) && ! in_array( 'dasprid/enum', array_column( $a103_lock['packages'] ?? array(), 'name' ), true ) );
check( 'A103: the plugin autoloader maps vendor-prefixed/ (classmap) and Strauss is configured with the PassCaptain\\Vendor\\ prefix', in_array( 'vendor-prefixed/', $a103_composer['autoload']['classmap'] ?? array(), true ) && 'PassCaptain\\Vendor\\' === ( $a103_composer['extra']['strauss']['namespace_prefix'] ?? '' ) && 'vendor-prefixed' === ( $a103_composer['extra']['strauss']['target_directory'] ?? '' ) );

// The committed copy matches composer.lock (version and commit of every prefixed package).
$a103_manifest = json_decode( (string) file_get_contents( $a103_root . 'vendor-prefixed/manifest.json' ), true );
$a103_locked   = array();
foreach ( array_merge( $a103_lock['packages'] ?? array(), $a103_lock['packages-dev'] ?? array() ) as $a103_p ) { $a103_locked[ $a103_p['name'] ] = array( $a103_p['version'], $a103_p['dist']['reference'] ?? ( $a103_p['source']['reference'] ?? '' ) ); }
$a103_stale = array();
foreach ( (array) ( $a103_manifest['packages'] ?? array() ) as $a103_p ) {
	if ( ( $a103_locked[ $a103_p['name'] ] ?? null ) !== array( $a103_p['version'], $a103_p['reference'] ) ) { $a103_stale[] = $a103_p['name']; }
}
check( 'A103: vendor-prefixed/manifest.json lists bacon/bacon-qr-code and dasprid/enum at the composer.lock versions' . ( $a103_stale ? ' – stale: ' . implode( ', ', $a103_stale ) . ' (run bash build/prefix-vendor.sh)' : '' ), array( 'bacon/bacon-qr-code', 'dasprid/enum' ) === array_column( (array) ( $a103_manifest['packages'] ?? array() ), 'name' ) && array() === $a103_stale );

// Build and CI wiring.
$a103_distignore = (string) file_get_contents( $a103_root . '.distignore' );
$a103_release    = (string) file_get_contents( $a103_root . 'build/release.sh' );
$a103_ci         = (string) file_get_contents( $a103_root . '.github/workflows/ci.yml' );
$a103_install    = (string) file_get_contents( $a103_root . 'build/ci-install-test.sh' );
check( 'A103: .distignore keeps vendor-prefixed/ in the ZIP', false === strpos( $a103_distignore, 'vendor-prefixed' ) );
check( 'A103: the release build installs without dev packages, scripts and plugins (no Strauss at release time)', false !== strpos( $a103_release, '--no-dev' ) && false !== strpos( $a103_release, '--no-scripts --no-plugins' ) );
check( 'A103: CI regenerates vendor-prefixed/ and fails on drift', false !== strpos( $a103_ci, 'bash build/prefix-vendor.sh --check' ) );
check( 'A103: the ZIP install test rejects unprefixed QR libraries in the package', false !== strpos( $a103_install, 'vendor/(bacon|dasprid|endroid)/' ) && false !== strpos( $a103_install, 'vendor-prefixed/bacon/bacon-qr-code/src/Encoder/Encoder.php' ) );
check( 'A103: Strauss PHAR is pinned by version and SHA-256', (bool) preg_match( '/STRAUSS_SHA256="[0-9a-f]{64}"/', (string) file_get_contents( $a103_root . 'build/prefix-vendor.sh' ) ) );
check( 'A103: without a Composer autoloader the fallback in pass-captain.php loads the prefixed classes from vendor-prefixed/', false !== strpos( (string) file_get_contents( $a103_root . 'pass-captain.php' ), "'PassCaptain\\\\Vendor\\\\BaconQrCode\\\\' => 'vendor-prefixed/bacon/bacon-qr-code/src/'" ) && is_file( $a103_root . 'vendor-prefixed/bacon/bacon-qr-code/src/Encoder/Encoder.php' ) && is_file( $a103_root . 'vendor-prefixed/dasprid/enum/src/AbstractEnum.php' ) );
