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
 * Up to 1.4 the plugin was called "WP Pass Captain" and was installed as "wp-pass-captain/wp-pass-captain.php"
 * (WordPress.org does not allow "WP" in plugin names). If that copy is still active, this copy takes over its
 * activation – on a multisite network including the network activation – without running the deactivation or
 * uninstall routine of the old copy, so data, settings and schedules stay as they are. Both copies use the same
 * tables, options and hooks.
 */
$wpcap_self = plugin_basename( __FILE__ );
if ( function_exists( 'get_option' ) ) {
	$wpcap_is_legacy = static function ( $basename ) use ( $wpcap_self ) {
		return is_string( $basename ) && $basename !== $wpcap_self && 'wp-pass-captain.php' === basename( $basename );
	};
	$wpcap_active = (array) get_option( 'active_plugins', array() );
	$wpcap_kept   = array_values( array_filter( $wpcap_active, static fn( $p ) => ! $wpcap_is_legacy( $p ) ) );
	if ( count( $wpcap_kept ) !== count( $wpcap_active ) ) {
		if ( ! in_array( $wpcap_self, $wpcap_kept, true ) ) {
			$wpcap_kept[] = $wpcap_self;
			sort( $wpcap_kept );
		}
		update_option( 'active_plugins', $wpcap_kept );
	}
	if ( is_multisite() ) {
		$wpcap_network = (array) get_site_option( 'active_sitewide_plugins', array() );
		$wpcap_changed = false;
		foreach ( array_keys( $wpcap_network ) as $wpcap_plugin ) {
			if ( $wpcap_is_legacy( $wpcap_plugin ) ) {
				// Network-activated old copy: this copy becomes network-activated in its place.
				$wpcap_network[ $wpcap_self ] = $wpcap_network[ $wpcap_self ] ?? $wpcap_network[ $wpcap_plugin ];
				unset( $wpcap_network[ $wpcap_plugin ] );
				$wpcap_changed = true;
			}
		}
		if ( $wpcap_changed ) {
			update_site_option( 'active_sitewide_plugins', $wpcap_network );
		}
	}
	// Deleting the old copy runs its uninstall.php: never remove data then, and re-create the schedules it clears.
	add_action(
		'pre_uninstall_plugin',
		static function ( $plugin ) use ( $wpcap_is_legacy ) {
			if ( ! $wpcap_is_legacy( $plugin ) ) { return; }
			add_filter(
				'option_wpcap_settings',
				static function ( $settings ) {
					if ( is_array( $settings ) ) { $settings['delete_data_on_uninstall'] = 0; }
					return $settings;
				}
			);
		}
	);
	unset( $wpcap_is_legacy, $wpcap_active, $wpcap_kept, $wpcap_network, $wpcap_changed, $wpcap_plugin );
}
unset( $wpcap_self );
// The old copy was already loaded in this request: it serves this request, this copy takes over from the next one.
if ( defined( 'WPCAP_VERSION' ) ) {
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
