<?php
/**
 * Plugin Name: WP Pass Captain
 * Description: Eigenständige Event-, Ticket- und Passverwaltung für WordPress.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: wp-pass-captain
 * Domain Path: /languages
 *
 * @package PassCaptain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPCAP_VERSION', '0.1.0' );
define( 'WPCAP_FILE', __FILE__ );
define( 'WPCAP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCAP_URL', plugin_dir_url( __FILE__ ) );

require_once WPCAP_PATH . 'src/Plugin.php';

register_activation_hook( WPCAP_FILE, array( PassCaptain\Plugin::class, 'activate' ) );
register_deactivation_hook( WPCAP_FILE, array( PassCaptain\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'wp-pass-captain',
			false,
			dirname( plugin_basename( WPCAP_FILE ) ) . '/languages'
		);

		PassCaptain\Plugin::instance()->run();
	}
);
