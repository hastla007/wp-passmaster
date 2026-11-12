<?php
/**
 * Plugin Name: WP Pass Captain
 * Plugin URI: https://github.com/hastla007/wp-passmaster
 * Description: Eigenständige Event-, Ticket- und Passverwaltung für WordPress.
 * Version: 1.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: hastla007
 * Author URI: https://github.com/hastla007
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 8.2
 * WC tested up to: 11.3
 * Text Domain: wp-pass-captain
 * Domain Path: /languages
 *
 * @package PassCaptain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPCAP_VERSION', '1.3.0' );
define( 'WPCAP_FILE', __FILE__ );
define( 'WPCAP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCAP_URL', plugin_dir_url( __FILE__ ) );

$wpcap_autoload = WPCAP_PATH . 'vendor/autoload.php';
if ( is_readable( $wpcap_autoload ) ) {
	require_once $wpcap_autoload;
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'PassCaptain\\';
			if ( 0 !== strpos( $class, $prefix ) ) { return; }
			$file = WPCAP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) { require_once $file; }
		}
	);
}

add_action( 'before_woocommerce_init', array( PassCaptain\Integrations\WooCommerce\OrderAdapter::class, 'declare_compatibility' ) );

register_activation_hook( WPCAP_FILE, array( PassCaptain\Plugin::class, 'activate' ) );
register_deactivation_hook( WPCAP_FILE, array( PassCaptain\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'wp-pass-captain', false, dirname( plugin_basename( WPCAP_FILE ) ) . '/languages' );
		PassCaptain\TicketPage::register();
		PassCaptain\Plugin::instance()->run();
	}
);
