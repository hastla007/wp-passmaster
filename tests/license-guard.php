<?php
/**
 * Guard (CI, A661): the core plugin contains no licence or updater code. Decision of 13.11.2026
 * (docs/FREE-PREMIUM.md): the core stays free; licence checks, entitlements and an own updater live
 * only in the separate add-on pass-captain-pro, which alone talks to WP License Captain.
 *
 * Scans only files that end up in the ZIP (tests/shipped-files.php: .distignore, runtime Composer
 * packages only; tests/, docs/ and development dependencies are not scanned) and fails on
 *   - the licence client (LicenseClient) or the licence server route wplc/v1,
 *   - a plugin header "Update URI" (updates from a server other than WordPress.org),
 *   - update hooks of an own updater: pre_set_site_transient_update_plugins,
 *     site_transient_update_plugins, plugins_api, plugins_api_result, update_plugins_{hostname},
 *   - entitlement queries (allows_entitlement).
 *
 * Run: php tests/license-guard.php [--self-test] (exit code 1 on a finding).
 */

// phpcs:ignoreFile

require __DIR__ . '/shipped-files.php';

/** Rule name => regex. Hook names count as soon as they appear as a string (add_filter, constants, …). */
function wpcap_license_guard_rules() {
	return array(
		'licence client (LicenseClient)'      => '/\bLicenseClient\b/',
		'licence server route (wplc/v1)'      => '#\bwplc/v1\b#i',
		'plugin header "Update URI"'          => '/^[ \t\/*#@]*Update URI\s*:/mi',
		'own updater hook (update transient)' => '/[\'"](?:pre_set_)?site_transient_update_plugins[\'"]/',
		'own updater hook (plugins_api)'      => '/[\'"]plugins_api(?:_result)?[\'"]/',
		'own updater hook (update_plugins_*)' => '/[\'"]update_plugins_[a-z0-9.\-{}$]+[\'"]/i',
		'entitlement query (allows_entitlement)' => '/\ballows_entitlement\b/',
	);
}

/** Findings "path:line: rule" in the shipped files below $root. */
function wpcap_license_guard_scan( $root, $use_git = true ) {
	$text = array( 'php', 'js', 'mjs', 'json', 'txt', 'css', 'html', 'xml', 'pot', 'po' );
	$findings = array();
	foreach ( wpcap_shipped_files( $root, $use_git ) as $file ) {
		if ( ! in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), $text, true ) ) { continue; }
		$content = (string) @file_get_contents( $root . '/' . $file );
		foreach ( wpcap_license_guard_rules() as $rule => $regex ) {
			if ( preg_match_all( $regex, $content, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $hit ) { $findings[] = $file . ':' . ( substr_count( substr( $content, 0, $hit[1] ), "\n" ) + 1 ) . ': ' . $rule; }
			}
		}
	}
	return $findings;
}

/** Self-test: every rule fires in a shipped file; tests/, docs/, Markdown and dev packages are not scanned. */
function wpcap_license_guard_self_test( $root ) {
	$dir = sys_get_temp_dir() . '/wpcap-license-guard-' . getmypid();
	$put = static function ( $rel, $content ) use ( $dir ) {
		@mkdir( dirname( $dir . '/' . $rel ), 0777, true );
		file_put_contents( $dir . '/' . $rel, $content );
	};
	@mkdir( $dir, 0777, true );
	copy( $root . '/.distignore', $dir . '/.distignore' );
	$put( 'pass-captain.php', "<?php\n/**\n * Plugin Name: Pass Captain\n * Update URI: https://shop.example/pass-captain\n */\n" );
	$put( 'src/Licensing.php', "<?php\nnew LicenseClient();\n\$u = rest_url( 'wplc/v1/activate' );\nadd_filter( 'pre_set_site_transient_update_plugins', 'x' );\nadd_filter( 'plugins_api', 'x', 10, 3 );\nadd_filter( \"update_plugins_shop.example\", 'x' );\nif ( allows_entitlement( 'pass.reports' ) ) {}\n" );
	$put( 'assets/updater.js', "fetch('/wp-json/wplc/v1/updates/check');\n" );
	$put( 'src/Clean.php', "<?php\n// Licence: GPL-2.0-or-later. Talks about plugins and updates, but no updater.\n\$x = 'update_plugins';\n" );
	// Not shipped: tests, docs, Markdown anywhere, development dependencies, test folders of runtime packages.
	$put( 'tests/fixture.php', "<?php new LicenseClient();\n" );
	$put( 'docs/ADDON.md', "LicenseClient, wplc/v1, allows_entitlement\n" );
	$put( 'src/NOTES.md', "Update URI: https://shop.example\n" );
	$put( 'vendor/acme/devtool/src/X.php', "<?php new LicenseClient();\n" );
	$put( 'vendor/acme/runtime/tests/X.php', "<?php new LicenseClient();\n" );
	$put( 'vendor/acme/runtime/src/Y.php', "<?php // allows_entitlement\n" );
	$put( 'composer.lock', json_encode( array( 'packages' => array( array( 'name' => 'acme/runtime' ) ), 'packages-dev' => array( array( 'name' => 'acme/devtool' ) ) ) ) );
	$found = wpcap_license_guard_scan( $dir, false );
	$failed = 0;
	$expect = static function ( $name, $ok ) use ( &$failed ) { echo ( $ok ? '  ok   ' : '  FAIL ' ) . "self-test: $name\n"; if ( ! $ok ) { $failed++; } };
	$has = static fn( $needle ) => (bool) array_filter( $found, static fn( $f ) => false !== strpos( $f, $needle ) );
	foreach ( array_keys( wpcap_license_guard_rules() ) as $rule ) { $expect( "rule fires: $rule", $has( $rule ) ); }
	$expect( 'header found in the main file', $has( 'pass-captain.php:4: plugin header' ) );
	$expect( 'JavaScript is scanned', $has( 'assets/updater.js:1:' ) );
	$expect( 'runtime Composer package is scanned', $has( 'vendor/acme/runtime/src/Y.php' ) );
	$expect( 'clean file has no finding', ! $has( 'src/Clean.php' ) );
	$expect( 'tests/, docs/, Markdown, dev packages and package tests are not scanned', ! $has( 'tests/' ) && ! $has( 'docs/' ) && ! $has( '.md' ) && ! $has( 'devtool' ) && ! $has( 'runtime/tests' ) );
	$expect( 'exactly the planted findings', 9 === count( $found ) );
	// Clean up.
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
	rmdir( $dir );
	if ( $failed ) { echo implode( "\n", $found ), "\n"; }
	return $failed;
}

$root   = dirname( __DIR__ );
$failed = 0;
if ( in_array( '--self-test', $argv, true ) ) { $failed += wpcap_license_guard_self_test( $root ); }
$findings = wpcap_license_guard_scan( $root );
$files    = count( wpcap_shipped_files( $root ) );
foreach ( $findings as $finding ) { echo "  FAIL licence/updater code in the core plugin: $finding\n"; }
echo ( $findings ? '' : "  ok   no licence or updater code in the $files shipped files\n" );
$failed += count( $findings );
if ( $findings ) { echo "Licence checks, entitlements and updates belong in the add-on pass-captain-pro (docs/FREE-PREMIUM.md).\n"; }
echo $failed ? "$failed failed\n" : "all passed\n";
exit( $failed ? 1 : 0 );
