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
		// Schema upgrades never run inline on every request: admin requests check on admin_init,
		// cron/WP-CLI/frontend on init; frontend requests only defer to a cron event (see Installer).
		$upgrade_hook = 'admin' === Installer::context() ? 'admin_init' : 'init';
		add_action( $upgrade_hook, array( __CLASS__, 'maybe_upgrade' ), 0, 0 );
		add_action( Installer::UPGRADE_HOOK, array( __CLASS__, 'run_deferred_upgrade' ), 10, 0 );
		Settings::register();
		if ( is_admin() ) {
			( new Licensing\AdminPage() )->register();
		}
		Jobs\TimeBudget::register();
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_site_tables' ), 10, 2 );
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register_routes' ) );
		Admin\Menu::register();
		add_action( 'admin_post_wpcap_accept_waitlist', array( Waitlists::class, 'handle_accept_request' ) );
		add_action( 'admin_post_nopriv_wpcap_accept_waitlist', array( Waitlists::class, 'handle_accept_request' ) );
		add_action( 'template_redirect', array( Waitlists::class, 'maybe_render_offer_page' ) );
		Privacy::register();
		$network_plugins = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		if ( is_multisite() && isset( $network_plugins[ plugin_basename( WPCAP_FILE ) ] ) ) {
			add_action( 'wp_initialize_site', static function ( $site ) {
				switch_to_blog( (int) $site->blog_id );
				self::activate_site();
				restore_current_blog();
			}, 200 );
		}
		add_action( 'wpcap_cleanup_reservations', array( Orders::class, 'expire_holds' ) );
		add_action( 'wpcap_cleanup_reservations', array( Privacy::class, 'process_deferred' ), 30, 0 );
		add_filter( 'cron_schedules', array( Communications::class, 'cron_schedule' ) );
		add_action( 'wpcap_process_messages', array( Communications::class, 'process_due' ) );
		add_action( 'wpcap_process_jobs', array( Jobs::class, 'process_due' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		Integrations\WooCommerce\OrderAdapter::register();
		Invoices::register();
		Integrations\Vouchers\Checkout::register();
		Integrations\Vouchers\TicketService::register();
		Integrations\Vouchers\CheckinService::register();
		Integrations\AffiliateCaptain\Adapter::register();
		Notifications::register();
		Payments\PayPalGateway::register();
		Payments\ReturnNotice::register();
		Mobile\Api::register();
		OccurrenceCancellation::register();
		Modules::register_all();
		add_shortcode( 'wp_pass_captain_events', array( PublicEvents::class, 'render_shortcode' ) );
		add_shortcode( 'wp_pass_captain_scanner', array( PublicEvents::class, 'render_scanner_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( PublicEvents::class, 'register_assets' ) );
	}

	/** Runs a pending schema upgrade for the current request context; re-schedules events after it. */
	public static function maybe_upgrade( $context = null ) {
		if ( Installer::maybe_upgrade( $context ) ) {
			self::schedule_events();
			return true;
		}
		return false;
	}

	/** Cron event scheduled by a frontend request that noticed a pending upgrade. */
	public static function run_deferred_upgrade() { return self::maybe_upgrade( 'cron' ); }

	/**
	 * Multisite: adds this site's plugin tables to the list WordPress drops when a site is deleted
	 * (filter wpmu_drop_tables, applied by wp_uninitialize_site). Only tables with the deleted
	 * site's own prefix are added, so other sites' data is never affected.
	 *
	 * @param string[] $tables  Tables WordPress will drop.
	 * @param int      $site_id Site being deleted.
	 */
	public static function drop_site_tables( $tables, $site_id = 0 ) {
		global $wpdb;
		$tables = (array) $tables;
		$prefix = $site_id && method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix( (int) $site_id ) : $wpdb->prefix;
		// The main site shares the base prefix with every other site's tables: never touch it here.
		if ( '' === (string) $prefix || ( is_multisite() && function_exists( 'is_main_site' ) && is_main_site( (int) $site_id ) ) ) { return $tables; }
		$like = addcslashes( $prefix . 'wpcap_', '_%\\' ) . '%'; // Same as $wpdb->esc_like().
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
			if ( ! in_array( $table, $tables, true ) ) { $tables[] = $table; }
		}
		return $tables;
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
		Privacy\Retention::schedule();
	}

	public static function deactivate( $network_wide = false ) {
		$site_ids = is_multisite() && $network_wide ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
		foreach ( $site_ids as $site_id ) {
			if ( is_multisite() && $network_wide ) { switch_to_blog( (int) $site_id ); }
			// wp_unschedule_hook also removes continuation runs scheduled with arguments.
			foreach ( array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs', Installer::UPGRADE_HOOK, Privacy\Retention::HOOK, Privacy::WALLET_PURGE_HOOK ) as $hook ) {
				wp_unschedule_hook( $hook );
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( '', array(), 'wp-pass-captain' ); }
			if ( is_multisite() && $network_wide ) { restore_current_blog(); }
		}
	}

	public function register_blocks() {
		register_block_type( WPCAP_PATH . 'src/blocks/events' );
	}
}
