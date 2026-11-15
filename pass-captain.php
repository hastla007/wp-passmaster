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

// Own PHP version check (the Composer platform check is not shipped): a clear notice instead of a fatal error.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			/* translators: %s: PHP version of the server */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Pass Captain benötigt PHP 8.2 oder neuer; dieser Server verwendet PHP %s. Das Plugin ist bis zu einem PHP-Update inaktiv.', 'pass-captain' ), PHP_VERSION ) ) . '</p></div>';
		}
	);
	return;
}

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
	$wpcap_kept   = array_values(
		array_filter(
			$wpcap_active,
			static function ( $p ) use ( $wpcap_is_legacy ) {
				return ! $wpcap_is_legacy( $p );
			}
		)
	);
	if ( count( $wpcap_kept ) !== count( $wpcap_active ) ) {
		if ( ! in_array( $wpcap_self, $wpcap_kept, true ) ) {
			$wpcap_kept[] = $wpcap_self;
			sort( $wpcap_kept );
		}
		update_option( 'active_plugins', $wpcap_kept );
		// Taking over while this copy is being activated: activate_plugin() appends it again after loading it. A
		// second entry would make the next deactivation fail ("Could not deactivate"), so keep only one.
		add_filter(
			'pre_update_option_active_plugins',
			static function ( $value ) {
				return is_array( $value ) ? array_values( array_unique( $value ) ) : $value;
			}
		);
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
	/*
	 * Deleting the old copy runs its uninstall.php, which reads wpcap_settings['delete_data_on_uninstall'] on every
	 * site of a network. Since 1.5 the opt-in lives in wpcap_uninstall_policy (Settings::migrate_uninstall_policy()
	 * resets the old key to 0 per site), so the old copy finds nothing to act on even where this guard is not loaded
	 * (e.g. network admin while this copy is only active on some sites). The guard stays as a second line; the
	 * schedules the old uninstall.php clears are re-created by Plugin::run() on the next request.
	 */
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
	// Re-activating the old copy next to this one would load both (constant warnings, duplicate hooks): the
	// plugin list offers no "Activate" link for it, and an activation request is refused before it is loaded.
	$wpcap_legacy_links = static function ( $actions, $plugin_file ) use ( $wpcap_is_legacy ) {
		if ( ! $wpcap_is_legacy( $plugin_file ) || ! is_array( $actions ) ) { return $actions; }
		unset( $actions['activate'], $actions['network_activate'] );
		$actions['wpcap_legacy'] = esc_html__( 'Ersetzt durch „Pass Captain“ – bitte löschen', 'pass-captain' );
		return $actions;
	};
	add_filter( 'plugin_action_links', $wpcap_legacy_links, 10, 2 );
	add_filter( 'network_admin_plugin_action_links', $wpcap_legacy_links, 10, 2 );
	add_action(
		'check_admin_referer',
		static function ( $action, $result = false ) use ( $wpcap_is_legacy ) {
			if ( ! $result || ! is_string( $action ) ) { return; }
			$plugins = array();
			if ( 0 === strpos( $action, 'activate-plugin_' ) ) {
				$plugins[] = substr( $action, strlen( 'activate-plugin_' ) );
			} elseif ( 'bulk-plugins' === $action ) {
				// Only the bulk action "activate-selected" (deleting or deactivating the old copy stays possible).
				// phpcs:disable WordPress.Security.NonceVerification -- the nonce was just verified (check_admin_referer).
				$bulk = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
				if ( ( '' === $bulk || '-1' === $bulk ) && isset( $_REQUEST['action2'] ) ) {
					$bulk = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
				}
				if ( 'activate-selected' === $bulk ) {
					$plugins = isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['checked'] ) ) : array();
				}
				// phpcs:enable WordPress.Security.NonceVerification
			}
			foreach ( $plugins as $plugin ) {
				if ( $wpcap_is_legacy( $plugin ) ) {
					wp_die(
						esc_html__( 'Die alte Kopie „WP Pass Captain“ kann nicht aktiviert werden: „Pass Captain“ hat sie ersetzt und nutzt dieselben Daten. Die alte Kopie kann gefahrlos gelöscht werden.', 'pass-captain' ),
						esc_html__( 'Aktivierung abgelehnt', 'pass-captain' ),
						array( 'response' => 409, 'back_link' => true )
					);
				}
			}
		},
		1,
		2
	);
	unset( $wpcap_legacy_links, $wpcap_is_legacy, $wpcap_active, $wpcap_kept, $wpcap_network, $wpcap_changed, $wpcap_plugin );
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

require_once WPCAP_PATH . 'src/functions.php'; // Global wrappers of the public add-on API (wpcap_get_invoice() …).

add_action( 'before_woocommerce_init', array( PassCaptain\Integrations\WooCommerce\OrderAdapter::class, 'declare_compatibility' ) );

register_activation_hook( WPCAP_FILE, array( PassCaptain\Plugin::class, 'activate' ) );
register_deactivation_hook( WPCAP_FILE, array( PassCaptain\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		// Kept on purpose: loads the translations bundled in /languages (de_DE source strings plus
		// en_US, es_ES, fr_FR, it_IT, nl_NL). Language packs from translate.wordpress.org still take
		// precedence when installed in WP_LANG_DIR/plugins.
		load_plugin_textdomain( 'pass-captain', false, dirname( plugin_basename( WPCAP_FILE ) ) . '/languages' );
		PassCaptain\TicketPage::register();
		PassCaptain\Plugin::instance()->run();
	}
);
