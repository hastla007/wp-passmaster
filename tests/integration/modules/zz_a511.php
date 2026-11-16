<?php
/**
 * A511: pass-captain.php checks PHP and WordPress versions itself before the autoloader and only
 * shows an admin notice when they are too old; Composer's platform check (fatal error on every
 * request) is switched off and the release build refuses to ship it. The main file runs in a child
 * process with a minimal WordPress (WordPress 6.3 to trigger the guard).
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile

echo "A511: own version guard instead of Composer's platform check\n";

$x2_a511_child = sys_get_temp_dir() . '/x2-a511-' . getmypid() . '.php';
file_put_contents( $x2_a511_child, '<?php
define( "ABSPATH", "/tmp/" );
$GLOBALS["wp_version"] = getenv( "X2_WP" );
$GLOBALS["hooks"] = array();
function __( $t, $d = null ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function get_option( $k, $d = false ) { return $d; }
function is_multisite() { return false; }
function current_user_can( $c ) { return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS["hooks"][ $h ][] = $cb; }
function plugin_dir_path( $f ) { return dirname( $f ) . "/"; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . "/" . basename( $f ); } // Legacy-copy takeover in the main file.
function get_site_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) { return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS["hooks"][ $h ][] = $cb; }
function plugin_dir_url( $f ) { return "https://example.test/"; }
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
require getenv( "X2_MAIN" );
echo defined( "WPCAP_VERSION" ) ? "LOADED|" : "SKIPPED|";
foreach ( $GLOBALS["hooks"]["admin_notices"] ?? array() as $cb ) { $cb(); }
' );
$x2_a511_run = static function ( $wp ) use ( $x2_a511_child ) {
	return (string) shell_exec( 'X2_WP=' . escapeshellarg( $wp ) . ' X2_MAIN=' . escapeshellarg( WPCAP_PATH . 'pass-captain.php' ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $x2_a511_child ) . ' 2>&1' );
};
$x2_a511_old = $x2_a511_run( '6.3' );
check( 'A511: on WordPress 6.3 the plugin is not loaded and shows an admin notice instead of failing' . ( 0 === strpos( $x2_a511_old, 'SKIPPED|' ) ? '' : ' – ' . substr( $x2_a511_old, 0, 200 ) ), 0 === strpos( $x2_a511_old, 'SKIPPED|<div class="notice notice-error"><p>Pass Captain benötigt WordPress 6.4 oder neuer (installiert: 6.3)' ) );
$x2_a511_new = $x2_a511_run( '6.8' );
check( 'A511: on a supported WordPress the plugin loads without a notice', 0 === strpos( $x2_a511_new, 'LOADED|' ) && false === strpos( $x2_a511_new, 'notice-error' ) );
@unlink( $x2_a511_child );

$x2_a511_main = (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' );
preg_match( '/Requires PHP:\s*([0-9.]+)/', $x2_a511_main, $x2_a511_php );
preg_match( '/Requires at least:\s*([0-9.]+)/', $x2_a511_main, $x2_a511_wp );
check( 'A511: the guard uses the versions of the plugin header', false !== strpos( $x2_a511_main, "version_compare( PHP_VERSION, '" . ( $x2_a511_php[1] ?? 'x' ) . "', '<' )" ) && false !== strpos( $x2_a511_main, "version_compare( (string) \$wp_version, '" . ( $x2_a511_wp[1] ?? 'x' ) . "', '<' )" ) );
check( 'A511: the guard runs before WPCAP_VERSION and the autoloader', strpos( $x2_a511_main, 'wpcap_requirements_error() ) {' ) < strpos( $x2_a511_main, "define( 'WPCAP_VERSION'" ) && strpos( $x2_a511_main, "define( 'WPCAP_VERSION'" ) < strpos( $x2_a511_main, 'vendor/autoload.php' ) );
$x2_a511_head = substr( $x2_a511_main, 0, (int) strpos( $x2_a511_main, "define( 'WPCAP_VERSION'" ) );
check( 'A511: the code before the guard uses no PHP 7.4+/8 syntax (arrow functions, match, nullsafe, named args)', ! preg_match( '/\bfn\s*\(|\bmatch\s*\(|\?->|\b(static|function)\s*\(\)\s*:\s*\w/', $x2_a511_head ) );
$x2_a511_composer = json_decode( (string) file_get_contents( WPCAP_PATH . 'composer.json' ), true );
check( 'A511: Composer platform check is off; the release build refuses vendor/composer/platform_check.php', false === ( $x2_a511_composer['config']['platform-check'] ?? null ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'build/release.sh' ), 'vendor/composer/platform_check.php' ) );
