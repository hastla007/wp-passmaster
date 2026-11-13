<?php
/**
 * Plugin Name: Pass Captain
 * Plugin URI: https://github.com/hastla007/wp-passmaster
 * Description: Eigenständige Event-, Ticket- und Passverwaltung für WordPress.
 * Version: 1.4.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: hastla007
 * Author URI: https://github.com/hastla007
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 8.2
 * WC tested up to: 11.3
 * Text Domain: pass-captain
 * Domain Path: /languages
 *
 * @package PassCaptain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * Until version 1.4 the plugin was called "WP Pass Captain" and lived in the folder wp-pass-captain (legacy-slug)
 * (renamed for WordPress.org). Both copies share classes, constants and data, so they must never run
 * together: when the old copy is loaded or active in this request, this copy stays passive, switches
 * the old one off for all following requests and finishes its own activation on the next admin
 * request (src/LegacySlug.php). Data is not touched – both versions use the same tables.
 */
if ( ! function_exists( 'wpcap_take_over_legacy_copy' ) ) {
	/** Returns true when the old copy is loaded or active and this copy must stay passive now. */
	function wpcap_take_over_legacy_copy() {
		$legacy = 'wp-pass-captain/wp-pass-captain.php'; // legacy-slug
		$active = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		$in_site = in_array( $legacy, $active, true );
		$in_network = isset( $network[ $legacy ] );
		if ( ! defined( 'WPCAP_VERSION' ) && ! $in_site && ! $in_network ) {
			return false;
		}
		if ( $in_site ) {
			update_option( 'active_plugins', array_values( array_diff( $active, array( $legacy ) ) ) );
		}
		if ( $in_network ) {
			unset( $network[ $legacy ] );
			update_site_option( 'active_sitewide_plugins', $network );
		}
		update_option( 'wpcap_legacy_takeover', 1, false );
		return true;
	}
}
if ( wpcap_take_over_legacy_copy() ) {
	return;
}

define( 'WPCAP_VERSION', '1.4.0' );
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
		load_plugin_textdomain( 'pass-captain', false, dirname( plugin_basename( WPCAP_FILE ) ) . '/languages' );
		PassCaptain\TicketPage::register();
		PassCaptain\Plugin::instance()->run();
	}
);
