<?php
/**
 * Installs a disposable WordPress site with Pass Captain for the browser tests (a11y gate, E2E):
 * runs wp_install(), activates the plugin, creates the shortcode pages and an admin user.
 * Idempotent: an installed site is only re-checked (plugin active, pages present).
 *
 * Usage: php tests/support/wp-install.php /path/to/wordpress http://127.0.0.1:8890
 * NEVER run this against a production site.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$root = rtrim( $argv[1] ?? '', '/' );
$url  = rtrim( $argv[2] ?? '', '/' );
if ( ! $root || ! $url || ! is_file( "$root/wp-load.php" ) ) {
	fwrite( STDERR, "Usage: php tests/support/wp-install.php /path/to/wordpress http://host:port\n" );
	exit( 2 );
}
$host = parse_url( $url, PHP_URL_HOST ) . ( parse_url( $url, PHP_URL_PORT ) ? ':' . parse_url( $url, PHP_URL_PORT ) : '' );
$_SERVER['HTTP_HOST']   = $host;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_INSTALLING', true );
require "$root/wp-load.php";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$user = getenv( 'WPCAP_A11Y_USER' ) ?: 'admin';
$pass = getenv( 'WPCAP_A11Y_PASS' ) ?: 'admin';
if ( ! is_blog_installed() ) {
	$GLOBALS['wp_rewrite'] = new WP_Rewrite();
	wp_install( 'Pass Captain Test', $user, 'admin@example.com', false, '', $pass );
	echo "installed\n";
}
update_option( 'siteurl', $url );
update_option( 'home', $url );
update_option( 'permalink_structure', '' );
update_option( 'blog_public', 0 );
$u = get_user_by( 'login', $user );
if ( $u ) { wp_set_password( $pass, $u->ID ); }

$plugin = 'pass-captain/pass-captain.php';
if ( ! is_plugin_active( $plugin ) ) {
	$r = activate_plugin( $plugin );
	if ( is_wp_error( $r ) ) { fwrite( STDERR, 'activate: ' . $r->get_error_message() . "\n" ); exit( 1 ); }
	echo "plugin activated\n";
}

foreach ( array(
	'pc-events'   => array( 'Veranstaltungen', '[wp_pass_captain_events]' ),
	'pc-scanner'  => array( 'Scanner', '[wp_pass_captain_scanner]' ),
	'pc-calendar' => array( 'Kalender', '[wp_pass_captain_calendar]' ),
) as $slug => list( $title, $content ) ) {
	if ( ! get_page_by_path( $slug ) ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ) );
		echo "page $slug\n";
	}
}
echo "ok\n";
