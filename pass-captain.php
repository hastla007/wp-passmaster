<?php
/**
 * Plugin Name: Pass Captain
 * Plugin URI: https://github.com/hastla007/wp-passmaster
 * Description: Eigenständige Event-, Ticket- und Passverwaltung für WordPress.
 * Version: 1.5.0
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
 * A511: own PHP/WordPress version guard before the autoloader. Below the minimum the plugin does not
 * load (no fatal error that takes the site down) and shows an admin notice instead. Keep this block
 * parseable by old PHP versions (no 8.x syntax) and the values equal to the header above.
 */
if ( ! function_exists( 'wpcap_requirements_error' ) ) {
	/** Returns why this site cannot run the plugin ('' when it can). */
	function wpcap_requirements_error() {
		global $wp_version;
		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			/* translators: 1: required PHP version, 2: installed PHP version */
			return sprintf( __( 'Pass Captain benötigt PHP %1$s oder neuer (installiert: %2$s) und wurde nicht geladen. Bitte PHP beim Hosting aktualisieren.', 'pass-captain' ), '8.2', PHP_VERSION );
		}
		if ( isset( $wp_version ) && version_compare( (string) $wp_version, '6.4', '<' ) ) {
			/* translators: 1: required WordPress version, 2: installed WordPress version */
			return sprintf( __( 'Pass Captain benötigt WordPress %1$s oder neuer (installiert: %2$s) und wurde nicht geladen. Bitte WordPress aktualisieren.', 'pass-captain' ), '6.4', (string) $wp_version );
		}
		return '';
	}
}
if ( '' !== wpcap_requirements_error() ) {
	$wpcap_requirements_notice = function () {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( wpcap_requirements_error() ) . '</p></div>';
		}
	};
	add_action( 'admin_notices', $wpcap_requirements_notice );
	add_action( 'network_admin_notices', $wpcap_requirements_notice );
	return;
}

/*
 * Up to 1.4 the plugin was called "WP Pass Captain" and was installed as "wp-pass-captain/wp-pass-captain.php"
 * (legacy-slug; WordPress.org does not allow "WP" in plugin names). If that copy is still active, this copy takes
 * over its activation – on a multisite network including the network activation – without running the
 * deactivation or uninstall routine of the old copy, so data, settings and schedules stay as they are. Both
 * copies use the same tables, options and hooks. src/LegacySlug.php finishes the takeover on the next admin
 * request (wpcap_legacy_takeover) and keeps old REST/admin URLs working.
 */
$wpcap_self     = plugin_basename( __FILE__ );
$wpcap_takeover = array(); // Old copies that WordPress still loads in this request (see PassCaptain\LegacyCopy).
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
		$wpcap_takeover = array_values( array_diff( $wpcap_active, $wpcap_kept ) );
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
				$wpcap_takeover[] = $wpcap_plugin;
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
	if ( $wpcap_takeover ) {
		update_option( 'wpcap_legacy_takeover', 1, false ); // LegacySlug::watch_legacy_copy() re-schedules events.
	}
	unset( $wpcap_legacy_links, $wpcap_is_legacy, $wpcap_active, $wpcap_kept, $wpcap_network, $wpcap_changed, $wpcap_plugin );
}
unset( $wpcap_self );
// The old copy was already loaded in this request: it serves this request, this copy takes over from the next one.
if ( defined( 'WPCAP_VERSION' ) ) {
	unset( $wpcap_takeover );
	return;
}

define( 'WPCAP_VERSION', '1.5.0' );
/*
 * Version of the extension contract for add-ons (docs/EXTENSIONS.md, "Versionen der Erweiterungspunkte"):
 * major = an extension point was removed or changed incompatibly, minor = extension points were added.
 * 1.0.0 = plugin 1.4.0, 1.1.0 = plugin 1.5.0.
 */
define( 'WPCAP_CONTRACT_VERSION', '1.1.0' );
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

// This copy loaded first and took over: the old copy is still loaded later in this request. Its second bootstrap
// is made harmless (no "Constant already defined" warnings; Plugin::run() is idempotent).
if ( $wpcap_takeover ) {
	PassCaptain\LegacyCopy::neutralize( $wpcap_takeover );
}
unset( $wpcap_takeover );

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
