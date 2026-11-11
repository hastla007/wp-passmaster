<?php
/**
 * Main plugin bootstrap.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's initial WordPress integration points.
 */
final class Plugin {
	/**
	 * The shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Return the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Handle plugin activation.
	 *
	 * Database migrations and capabilities will be added in the next foundation step.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( 'wpcap_version', false ) ) {
			add_option( 'wpcap_version', WPCAP_VERSION );
		}
	}

	/**
	 * Handle plugin deactivation.
	 *
	 * Deactivation intentionally keeps plugin data intact.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No scheduled tasks or temporary state exist yet.
	}

	/**
	 * Register the top-level admin page.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'WP Pass Captain', 'wp-pass-captain' ),
			__( 'Pass Captain', 'wp-pass-captain' ),
			'manage_options',
			'wp-pass-captain',
			array( $this, 'render_admin_page' ),
			'dashicons-tickets-alt',
			58
		);
	}

	/**
	 * Render the initial admin landing page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Pass Captain', 'wp-pass-captain' ); ?></h1>
			<p><?php echo esc_html__( 'Das Plugin-Grundgerüst ist aktiv. Die Event- und Ticketverwaltung wird in den nächsten Umsetzungsschritten ergänzt.', 'wp-pass-captain' ); ?></p>
		</div>
		<?php
	}
}
