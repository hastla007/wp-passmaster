<?php
/**
 * A286: the offline scanner PWA is served from the site's own origin (home_url), not from the asset
 * URL – with plugin assets on a CDN domain the app could neither reach its same-origin API nor
 * register its service worker. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\OfflineScanner\Pwa as A286Pwa;
use PassCaptain\OfflineScanner\AdminPage as A286Admin;

echo "A286: offline scanner PWA on the site's origin\n";
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'get_user_locale' ) ) { function get_user_locale( $u = 0 ) { return 'de_DE'; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }

$a286_saved = $GLOBALS['wpcap_test_options']['permalink_structure'] ?? null;
$GLOBALS['wpcap_test_options']['permalink_structure'] = '/%postname%/';
// Assets on a CDN: the app URL must still be on the site (home_url) origin.
$a286_url = A286Admin::app_url();
check( 'A286 the app link is on the site origin, not on the asset/CDN URL', 0 === strpos( $a286_url, 'https://example.test/wpcap-offline-scanner/app/index.html?api=' ) && false === strpos( $a286_url, 'wp-content' ) );
check( 'A286 the API base passed to the app is on the same origin', false !== strpos( $a286_url, rawurlencode( 'https://example.test/wp-json/pass-captain/v1/app/' ) ) );
$GLOBALS['wpcap_test_options']['permalink_structure'] = '';
check( 'A286 plain permalinks use the index.php path (no rewrite rules needed)', 'https://example.test/index.php/wpcap-offline-scanner/app/' === A286Pwa::base_url() );
if ( null === $a286_saved ) { unset( $GLOBALS['wpcap_test_options']['permalink_structure'] ); } else { $GLOBALS['wpcap_test_options']['permalink_structure'] = $a286_saved; }

$a286_root = WPCAP_PATH;
check( 'A286 index.html and the root of the app map to the shell', $a286_root . 'assets/offline-scanner/index.html' === A286Pwa::resolve( '/wpcap-offline-scanner/app/', '/' ) && $a286_root . 'assets/offline-scanner/index.html' === A286Pwa::resolve( '/wpcap-offline-scanner/app/index.html?api=x&lang=de', '/' ) );
check( 'A286 service worker, scripts and vendor files keep their relative paths', $a286_root . 'assets/offline-scanner/sw.js' === A286Pwa::resolve( '/wpcap-offline-scanner/app/sw.js', '/' ) && $a286_root . 'assets/vendor/tweetnacl/nacl-fast.min.js' === A286Pwa::resolve( '/wpcap-offline-scanner/vendor/tweetnacl/nacl-fast.min.js', '/' ) && $a286_root . 'assets/vendor/jsqr/jsQR.js' === A286Pwa::resolve( '/wpcap-offline-scanner/vendor/jsqr/jsQR.js', '/' ) );
check( 'A286 subdirectory installs and the index.php form are recognised', $a286_root . 'assets/offline-scanner/app.js' === A286Pwa::resolve( '/blog/wpcap-offline-scanner/app/app.js', '/blog/' ) && $a286_root . 'assets/offline-scanner/app.js' === A286Pwa::resolve( '/blog/index.php/wpcap-offline-scanner/app/app.js', '/blog' ) );
check( 'A286 other requests are left alone', null === A286Pwa::resolve( '/shop/', '/' ) && null === A286Pwa::resolve( '/other/wpcap-offline-scanner/app/', '/blog/' ) );
check( 'A286 only the listed shell files are served (no traversal, no PHP)', '' === A286Pwa::resolve( '/wpcap-offline-scanner/app/../../../wp-config.php', '/' ) && '' === A286Pwa::resolve( '/wpcap-offline-scanner/app/%2e%2e/pass-captain.php', '/' ) && '' === A286Pwa::resolve( '/wpcap-offline-scanner/app/unknown.js', '/' ) );
$a286_missing = array_filter( A286Pwa::FILES, static fn( $f ) => ! is_readable( WPCAP_PATH . $f ) );
check( 'A286 every route file exists', ! $a286_missing );
$a286_sw = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/sw.js' );
preg_match( "/var SHELL = \\[(.*?)\\];/s", $a286_sw, $a286_m );
preg_match_all( "/'([^']+)'/", $a286_m[1] ?? '', $a286_shell );
$a286_unserved = array_filter( $a286_shell[1], static function ( $rel ) {
	// Resolve the path relative to the scope /wpcap-offline-scanner/app/ like the browser does.
	$parts = array( 'wpcap-offline-scanner', 'app' );
	foreach ( explode( '/', $rel ) as $seg ) {
		if ( '..' === $seg ) { array_pop( $parts ); } elseif ( '.' !== $seg && '' !== $seg ) { $parts[] = $seg; }
	}
	$path = '/' . implode( '/', $parts ) . ( './' === $rel ? '/' : '' );
	return ! A286Pwa::resolve( $path, '/' );
} );
check( 'A286 every file the service worker caches is served by the route' . ( $a286_unserved ? ' – missing ' . implode( ', ', $a286_unserved ) : '' ), $a286_shell[1] && ! $a286_unserved );
$a286_h = A286Pwa::headers( WPCAP_PATH . 'assets/offline-scanner/sw.js' );
check( 'A286 scripts are served as JavaScript with revalidation and nosniff', 0 === strpos( $a286_h['Content-Type'], 'text/javascript' ) && 'no-cache' === $a286_h['Cache-Control'] && 'nosniff' === $a286_h['X-Content-Type-Options'] && 0 === strpos( A286Pwa::headers( 'x/manifest.json' )['Content-Type'], 'application/manifest+json' ) );
check( 'A286 the module registers the route', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Modules/OfflineScanner.php' ), 'Pwa::register();' ) );
