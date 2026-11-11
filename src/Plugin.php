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
		Installer::maybe_upgrade();
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_wpcap_create_event', array( Admin::class, 'create_event' ) );
		add_action( 'admin_post_wpcap_assign_staff', array( Admin::class, 'assign_staff' ) );
		add_action( 'admin_post_wpcap_confirm_payment', array( Admin::class, 'confirm_payment' ) );
		add_action( 'admin_post_wpcap_resend_tickets', array( Admin::class, 'resend_tickets' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Privacy::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Privacy::class, 'register_eraser' ) );
		add_action( 'wpcap_cleanup_reservations', array( Orders::class, 'expire_holds' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_shortcode( 'wp_pass_captain_events', array( PublicEvents::class, 'render_shortcode' ) );
		add_shortcode( 'wp_pass_captain_scanner', array( PublicEvents::class, 'render_scanner_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( PublicEvents::class, 'register_assets' ) );
	}

	public static function activate() {
		Installer::install();
		if ( ! wp_next_scheduled( 'wpcap_cleanup_reservations' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wpcap_cleanup_reservations' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'wpcap_cleanup_reservations' );
	}

	public function register_blocks() {
		register_block_type( WPCAP_PATH . 'src/blocks/events' );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'WP Pass Captain', 'wp-pass-captain' ),
			__( 'Pass Captain', 'wp-pass-captain' ),
			'wpcap_manage_events',
			'wp-pass-captain',
			array( Admin::class, 'render_page' ),
			'dashicons-tickets-alt',
			58
		);
	}
}
