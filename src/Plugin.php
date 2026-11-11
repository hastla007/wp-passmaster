<?php
/** Main WordPress integration point. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function run() {
		if ( Installer::maybe_upgrade() ) {
			self::schedule_events();
		}
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register_routes' ) );
		Admin\Menu::register();
		add_action( 'admin_post_wpcap_accept_waitlist', array( Waitlists::class, 'handle_accept_request' ) );
		add_action( 'admin_post_nopriv_wpcap_accept_waitlist', array( Waitlists::class, 'handle_accept_request' ) );
		add_action( 'template_redirect', array( Waitlists::class, 'maybe_render_offer_page' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Privacy::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Privacy::class, 'register_eraser' ) );
		$network_plugins = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		if ( is_multisite() && isset( $network_plugins[ plugin_basename( WPCAP_FILE ) ] ) ) {
			add_action( 'wp_initialize_site', static function ( $site ) {
				switch_to_blog( (int) $site->blog_id );
				self::activate_site();
				restore_current_blog();
			}, 200 );
		}
		add_action( 'wpcap_cleanup_reservations', array( Orders::class, 'expire_holds' ) );
		add_filter( 'cron_schedules', array( Communications::class, 'cron_schedule' ) );
		add_action( 'wpcap_process_messages', array( Communications::class, 'process_due' ) );
		add_action( 'wpcap_process_jobs', array( Jobs::class, 'process_due' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		Integrations\WooCommerce\OrderAdapter::register();
		Invoices::register();
		Integrations\Vouchers\Checkout::register();
		Notifications::register();
		Payments\PayPalGateway::register();
		Payments\ReturnNotice::register();
		add_shortcode( 'wp_pass_captain_events', array( PublicEvents::class, 'render_shortcode' ) );
		add_shortcode( 'wp_pass_captain_scanner', array( PublicEvents::class, 'render_scanner_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( PublicEvents::class, 'register_assets' ) );
	}

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	private static function activate_site() {
		Installer::install();
		self::schedule_events();
	}

	/** Schedules recurring jobs; also runs after plugin updates (activation hooks do not fire then). */
	public static function schedule_events() {
		add_filter( 'cron_schedules', array( Communications::class, 'cron_schedule' ) );
		if ( ! wp_next_scheduled( 'wpcap_cleanup_reservations' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wpcap_cleanup_reservations' );
		}
		if ( ! wp_next_scheduled( 'wpcap_process_messages' ) ) {
			wp_schedule_event( time() + 60, 'wpcap_five_minutes', 'wpcap_process_messages' );
		}
		if ( ! wp_next_scheduled( 'wpcap_process_jobs' ) ) {
			wp_schedule_event( time() + 90, 'wpcap_five_minutes', 'wpcap_process_jobs' );
		}
	}

	public static function deactivate( $network_wide = false ) {
		$site_ids = is_multisite() && $network_wide ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
		foreach ( $site_ids as $site_id ) {
			if ( is_multisite() && $network_wide ) { switch_to_blog( (int) $site_id ); }
			wp_clear_scheduled_hook( 'wpcap_cleanup_reservations' );
			wp_clear_scheduled_hook( 'wpcap_process_messages' );
			wp_clear_scheduled_hook( 'wpcap_process_jobs' );
			if ( is_multisite() && $network_wide ) { restore_current_blog(); }
		}
	}

	public function register_blocks() {
		register_block_type( WPCAP_PATH . 'src/blocks/events' );
	}
}
