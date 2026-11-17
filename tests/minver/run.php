<?php
/**
 * MINVER-1 without a database: php tests/minver/run.php
 *
 * The loader guard of pass-captain.php compares the running PHP and WordPress with the minimums. Local and CI
 * runs use any PHP, so this test checks the guard with injected versions: it evaluates the function
 * wpcap_requirements_error() from the plugin file with a replaced PHP version and a set $wp_version, and the
 * Site Health database test with injected server strings. The integration suite repeats the source checks
 * (tests/integration/modules/zz_minver.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }

$root   = dirname( __DIR__, 2 );
$main   = (string) file_get_contents( $root . '/pass-captain.php' );
$passes = 0;
$fails  = 0;
function check( $name, $ok ) {
	global $passes, $fails;
	if ( $ok ) { $passes++; echo "  ok   $name\n"; } else { $fails++; echo "  FAIL $name\n"; }
}

// The guard function as written in pass-captain.php, renamed, with PHP_VERSION replaced by a parameter.
if ( ! preg_match( '/\tfunction wpcap_requirements_error\(\) \{.*?\n\t\}\n/s', $main, $m ) ) {
	echo "FAIL wpcap_requirements_error() not found in pass-captain.php\n";
	exit( 1 );
}
$code = str_replace( array( 'function wpcap_requirements_error() {', 'PHP_VERSION' ), array( 'function wpcap_test_requirements_error( $wpcap_test_php ) {', '$wpcap_test_php' ), $m[0] );
eval( $code ); // phpcs:ignore Squiz.PHP.Eval -- the plugin's own code, read from the repository.

$guard = static function ( $php, $wp ) {
	$GLOBALS['wp_version'] = $wp;
	return wpcap_test_requirements_error( $php );
};

echo "MINVER-1: loader guard (injected versions)\n";
check( 'PHP 8.4 is refused with the required and the installed version', false !== strpos( $guard( '8.4.14', '7.1' ), 'PHP 8.5 oder neuer (installiert: 8.4.14)' ) );
check( 'PHP 8.5 and 8.6 load', '' === $guard( '8.5.0', '7.1' ) && '' === $guard( '8.6.0', '7.1' ) );
check( 'WordPress 6.9 is refused, WordPress 7.0 loads', false !== strpos( $guard( '8.5.0', '6.9.4' ), 'WordPress 7.0 oder neuer (installiert: 6.9.4)' ) && '' === $guard( '8.5.0', '7.0' ) );
check( 'the guard returns before the autoloader and the plugin code are loaded', strpos( $main, "if ( '' !== wpcap_requirements_error() )" ) < strpos( $main, "vendor/autoload.php" ) && strpos( $main, "if ( '' !== wpcap_requirements_error() )" ) < strpos( $main, 'register_activation_hook' ) );
preg_match( '/^ \* Requires PHP:\s*(\S+)$/m', $main, $php_header );
preg_match( '/^ \* Requires at least:\s*(\S+)$/m', $main, $wp_header );
preg_match( '/^ \* WC requires at least:\s*(\S+)$/m', $main, $wc_header );
check( 'the header declares PHP 8.5, WordPress 7.0 and WooCommerce 11.2.2 (the declared versions, not the running PHP); WooCommerce 11.2.0 and 11.2.1 are below it', '8.5' === ( $php_header[1] ?? '' ) && '7.0' === ( $wp_header[1] ?? '' ) && '11.2.2' === ( $wc_header[1] ?? '' ) && version_compare( '11.2.1', $wc_header[1], '<' ) && version_compare( '11.2.0', $wc_header[1], '<' ) );

echo "MINVER-1: database server warning (injected server strings)\n";
require $root . '/src/Operations/SiteHealth.php';
$status = static fn( $info ) => PassCaptain\Operations\SiteHealth::test_db_server( $info )['status'];
check( 'MySQL 9.7 and MariaDB 12.3 are supported', 'good' === $status( '9.7.0' ) && 'good' === $status( '12.3.1-MariaDB-ubu2404' ) && 'good' === $status( '5.5.5-12.3.1-MariaDB' ) );
check( 'MySQL 8.4 and MariaDB 11.4 / 10.11 only get a warning (recommended, never critical)', 'recommended' === $status( '8.4.3' ) && 'recommended' === $status( '11.4.5-MariaDB' ) && 'recommended' === $status( '5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1' ) );
check( 'an unreadable server version blocks nothing', 'good' === $status( '' ) );

echo "\n$passes passed, $fails failed\n";
exit( $fails ? 1 : 0 );
