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

	/** @var bool Whether run() already registered the hooks in this request. */
	private static $started = false;

	/**
	 * Registers all hooks. Idempotent: during the takeover from the old copy (LegacyCopy) its bootstrap also
	 * calls run() in the same request; a second call must not register hooks and cron callbacks twice.
	 */
	public function run() {
		if ( self::$started ) { return; }
		self::$started = true;
		// A285: with HyperDB/LudicrousDB, transactions and locking reads must run on the primary.
		DbReplication::register();
		// MariaDB 11.6.2+: classic InnoDB snapshots for the plugin's transactions (DbSession).
		DbSession::classic_snapshots();
		// Schema upgrades never run inline on every request: admin requests check on admin_init,
		// cron/WP-CLI/frontend on init; frontend requests only defer to a cron event (see Installer).
		$upgrade_hook = 'admin' === Installer::context() ? 'admin_init' : 'init';
		add_action( $upgrade_hook, array( __CLASS__, 'maybe_upgrade' ), 0, 0 );
		add_action( Installer::UPGRADE_HOOK, array( __CLASS__, 'run_deferred_upgrade' ), 10, 0 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			// Runs a pending (also a failed, waiting) schema upgrade now (A466).
			\WP_CLI::add_command( 'pass-captain upgrade', static function () {
				Installer::allow_retry();
				$ran = self::maybe_upgrade( 'cli' );
				$error = get_option( 'wpcap_schema_error' );
				if ( is_array( $error ) ) { \WP_CLI::error( 'schema upgrade failed, missing: ' . implode( ', ', array_slice( (array) ( $error['missing'] ?? array() ), 0, 20 ) ) ); }
				\WP_CLI::success( $ran ? 'schema upgraded to ' . Installer::version() : 'schema is current (' . Installer::version() . ')' );
			} );
		}
		LegacySlug::register();
		NetworkInstall::register();
		// A362: role names are stored untranslated and shown through translate_user_role().
		add_filter( 'gettext_with_context', array( Installer::class, 'translate_role_name' ), 10, 4 );
		// Self-healing cron: recurring events lost (cron option cleaned up by another plugin, restore, the
		// old copy's uninstall.php) are re-scheduled on the next request; one cached option read.
		add_action( 'init', array( __CLASS__, 'ensure_events' ), 20, 0 );
		Settings::register();
		Secrets::register();
		Redact::register();
		Log::register();
		Security\Policy::register(); // W9 settings (before anything reads Settings::defaults()).
		Security\Vault::register();
		Security\AuditTrail::register();
		DeveloperKeys::register(); // Ends API keys and staff rows of removed users or lost roles.
		Security\StepUp::register();
		Security\SupportAccess::register();
		Security\Sessions::register();
		Ops\Network::register();
		Frontend\Language::register();
		Jobs\TimeBudget::register();
		Operations\Monitor::register();
		Operations\SiteHealth::register();
		Operations\Doctor::register();
		Orders::register(); // Job handler for ticket issuance retries and the delivery outbox.
		Refunds::register(); // Job handler for order status retries after refunds.
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_site_tables' ), 10, 2 );
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( DeveloperKeys::class, 'retry_after_header' ) );
		add_filter( 'rest_post_dispatch', array( Rest\Controller::class, 'cache_headers' ), 20, 3 );
		Rest\Credentials::register(); // X-WPCAP-Access-Token for buyer REST routes (CORS allow-list).
		Contracts::register();
		Admin\Menu::register();
		Admin\Notices::register();
		Admin\OrganizerTools::register(); // Freikarten/Gästeliste, Duplizieren, CSV-Import (event/occurrence screens).
		Frontend\EventPage::register(); // Event detail pages /{base}/{slug}/ with schema.org/Event JSON-LD.
		Sales\SelfService::register(); // Cancellation/refund requests in the order portal (event rule).
		add_action( 'admin_post_wpcap_accept_waitlist', array( Waitlists::class, 'handle_accept_request' ) );
		add_action( 'admin_post_nopriv_wpcap_accept_waitlist', array( Waitlists::class, 'handle_accept_request' ) );
		add_action( 'template_redirect', array( Waitlists::class, 'maybe_render_offer_page' ) );
		add_action( 'template_redirect', array( Waitlists::class, 'maybe_render_result_page' ) );
		add_action( 'admin_post_' . Waitlists::UNSUBSCRIBE_ACTION, array( Waitlists::class, 'handle_unsubscribe_request' ) );
		add_action( 'admin_post_nopriv_' . Waitlists::UNSUBSCRIBE_ACTION, array( Waitlists::class, 'handle_unsubscribe_request' ) );
		add_action( 'template_redirect', array( Waitlists::class, 'maybe_render_unsubscribe_page' ) );
		// A564: double opt-in confirmation (GET page with a POST button).
		add_action( 'admin_post_' . Waitlists::CONFIRM_ACTION, array( Waitlists::class, 'handle_confirm_request' ) );
		add_action( 'admin_post_nopriv_' . Waitlists::CONFIRM_ACTION, array( Waitlists::class, 'handle_confirm_request' ) );
		add_action( 'template_redirect', array( Waitlists::class, 'maybe_render_confirm_page' ) );
		Privacy::register();
		// A277/A278: role removal or deletion takes event teams, scanner devices and API keys back.
		UserAccess::register();
		Consents::register();
		ServiceBookingFlow::register();
		// Public booking page for service slots: [wpcap_service_booking] / block pass-captain/service-booking (A268).
		Frontend\ServiceBooking::register();
		$network_plugins = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		if ( is_multisite() && isset( $network_plugins[ plugin_basename( WPCAP_FILE ) ] ) ) {
			add_action( 'wp_initialize_site', static function ( $site ) {
				switch_to_blog( (int) $site->blog_id );
				self::activate_site();
				restore_current_blog();
			}, 200 );
		}
		add_action( 'wpcap_cleanup_reservations', array( Orders::class, 'expire_holds' ) );
		// Multisite: request caches belong to one site; switch_to_blog() must not reuse them (A182).
		add_action( 'switch_blog', array( Orders::class, 'site_switched' ), 10, 0 );
		foreach ( self::site_cache_flushers() as $flush ) { add_action( 'switch_blog', $flush, 10, 0 ); }
		add_filter( 'wpcap_process_job', array( Orders::class, 'handle_job' ), 10, 4 );
		CapacityCounters::register();
		// Report caches: generation keys bumped by every write to the report tables (A201).
		Reports\Cache::register();
		add_action( 'wpcap_cleanup_reservations', array( Privacy::class, 'process_deferred' ), 30, 0 );
		add_action( 'wpcap_cleanup_reservations', array( Refunds::class, 'repair_stuck' ), 40, 0 );
		// Reconciles refunds with an unknown outcome at the provider (A272).
		add_filter( 'wpcap_process_job', array( Refunds::class, 'handle_job' ), 10, 4 );
		// Paid orders whose issuance never ran get a retry job (A343).
		add_action( 'wpcap_cleanup_reservations', array( Orders::class, 'sweep_unissued' ), 45, 0 );
		add_action( 'wpcap_cleanup_reservations', array( RateLimit::class, 'purge' ), 50, 0 );
		// Finished reservations, jobs, seat holds and payment events (A444).
		add_action( 'wpcap_cleanup_reservations', array( Privacy\Retention::class, 'housekeeping' ), 60, 0 );
		// A626: temporary mail attachments, kept for mail queue plugins, are removed after their TTL.
		add_action( 'wpcap_cleanup_reservations', array( Mail\TempFiles::class, 'purge' ), 65, 0 );
		add_filter( 'cron_schedules', array( Communications::class, 'cron_schedule' ) );
		add_action( 'wpcap_process_messages', array( Communications::class, 'process_due' ) );
		add_action( 'wpcap_process_jobs', array( Jobs::class, 'process_due' ) );
		// Lapsed holds every 5 minutes too, so capacity and limited discount codes come back quickly.
		add_action( 'wpcap_process_jobs', array( Orders::class, 'process_expired_holds' ), 3, 0 );
		Ops\Heartbeat::register(); // Worker heartbeats for the system status page.
		if ( defined( 'WP_CLI' ) && WP_CLI ) { Ops\Cli::register(); }
		// A352: provider ↔ local payment state (lost webhooks, payments of lapsed orders).
		add_action( 'wpcap_process_jobs', array( Payments\Reconcile::class, 'cron' ), 30, 0 );
		add_action( 'init', array( $this, 'register_blocks' ) );
		Integrations\WooCommerce\OrderAdapter::register();
		Integrations\WooCommerce\CheckoutGate::register(); // Add-on gate (waiting room) for Woo cart, checkout and Store API.
		Sales\CheckoutGate::register(); // Retry-After header for refused checkouts (wp_pass_captain_checkout_allowed_v1).
		Brand::register(); // White label: product name in translated texts (wp_pass_captain_brand_name_v1).
		Terms::register(); // A690: configurable terms ("Gäste" instead of "Teilnehmende") in front-end texts and mails.
		Integrations\ConnectorEvents::register(); // Domain events → enabled connectors (job queue).
		Invoices::register();
		ServiceFee::register(); // A425: names service fee lines on invoices.
		Payments\Cases::register(); // Double payments / payments for closed orders / failed provider refunds.
		Payments\Reconcile::register(); // Void open PSP sessions on cancellation, poll stuck pending orders.
		Integrations\Vouchers\Checkout::register();
		Integrations\Vouchers\TicketService::register();
		Integrations\Vouchers\CheckinService::register();
		Integrations\AffiliateCaptain\Adapter::register();
		Notifications::register();
		Mail\Suppression::register();
		TicketCodes::register();
		CodeHistory::register();
		ScannerDesk::register();
		Payments\PayPalGateway::register();
		Payments\ReturnNotice::register();
		PageCache::register();
		Frontend\Availability::register(); // Purge hook wp_pass_captain_availability_changed_v1.
		Mobile\Api::register();
		HelpCode::register();
		OccurrenceCancellation::register();
		Occurrence::register(); // A562: lifecycle phases.
		// A673–A675: post-commit domain events into the outbox (independent of webhooks) and delivery to a
		// workflow provider of contract v1.
		Workflow\Events::register();
		Workflow\Outbox::register();
		// A667/A669: review participation contract v1 (experience_completed and corrections, same outbox).
		Review\Events::register();
		Modules::register_all();
		// A303: [wpcap_*] are the shortcodes; the former [wp_pass_captain_*] tags stay as deprecated aliases.
		add_shortcode( 'wpcap_events', array( PublicEvents::class, 'render_shortcode' ) );
		add_shortcode( 'wp_pass_captain_events', array( PublicEvents::class, 'render_shortcode' ) );
		add_shortcode( 'wpcap_scanner', array( PublicEvents::class, 'render_scanner_shortcode' ) );
		add_shortcode( 'wp_pass_captain_scanner', array( PublicEvents::class, 'render_scanner_shortcode' ) );
		add_action( 'template_redirect', array( PublicEvents::class, 'maybe_protect_scanner_page' ), 1, 0 );
		add_action( 'wp_enqueue_scripts', array( PublicEvents::class, 'register_assets' ) );
		add_action( 'save_post', array( PublicEvents::class, 'flush_page_cache' ), 10, 0 );
		add_action( 'deleted_post', array( PublicEvents::class, 'flush_page_cache' ), 10, 0 );
		// A391: stylesheets registered before the blocks (block.json "style"), shortcodes in the <head>.
		add_action( 'init', array( PublicEvents::class, 'register_styles' ), 5 );
		add_action( 'wp_enqueue_scripts', array( PublicEvents::class, 'enqueue_shortcode_styles' ), 20 );
		// A663: the core is ready – add-ons register on wpcap_loaded (public API, PublicApi::VERSION).
		PublicApi::loaded();
	}

	/** Runs a pending schema upgrade for the current request context; re-schedules events after it. */
	public static function maybe_upgrade( $context = null ) {
		// Provider secrets: plaintext from wpcap_settings into the encrypted store, re-encryption
		// after a key change (own option, independent of the schema version; behind the upgrade lock).
		// "pcv1:" values encrypted in place (Security\Vault) move first, so Secrets::migrate() never seals ciphertext.
		Settings::migrate_secrets_to_store();
		Secrets::maybe_upgrade( $context ?? Installer::context() );
		if ( Installer::maybe_upgrade( $context ) ) {
			self::schedule_events();
			Frontend\EventPage::schedule_flush(); // New or changed rewrite rules after an update (flushed once on init).
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

	/**
	 * Activation. Network-wide on a multisite network only the current site is installed right away;
	 * NetworkInstall migrates the uninstall opt-in of every other site (cheap, see
	 * Settings::migrate_uninstall_policy(); up to NetworkInstall::POLICY_BATCH sites per run) and installs
	 * the sites in background batches (A287). A large network therefore no longer times out in the
	 * activation request.
	 */
	public static function activate( $network_wide = false ) {
		self::activate_site();
		if ( is_multisite() && $network_wide ) {
			// A287: the other sites are installed lazily (their next admin/cron request) and in
			// background batches – never all inside the activation request (timeout on large networks).
			// The uninstall opt-in protection of sites with plugin data runs ahead of those batches
			// (NetworkInstall::sweep_policies()) and again in NetworkInstall::install_site().
			NetworkInstall::start();
		}
	}

	private static function activate_site() {
		// A512: behind the upgrade lock like every other schema run, so an activation never runs dbDelta
		// in parallel with an upgrade of the same site (cron, admin request, WP-CLI). It waits a little
		// (filter `wpcap_activation_lock_wait`, seconds, default 20); is the lock still taken, the
		// running upgrade finishes the schema and init (ensure_events) schedules the jobs afterwards.
		$installed = false;
		$ran = Installer::run_locked(
			static function () use ( &$installed ) {
				$installed = Installer::install();
			},
			max( 0, (int) apply_filters( 'wpcap_activation_lock_wait', 20 ) )
		);
		// A failed first install (missing tables/columns) schedules no recurring events: their handlers
		// would run against a schema that is not there (A466). The next admin request retries.
		if ( ! $ran || false === $installed ) { return false; }
		Frontend\EventPage::schedule_flush();
		self::schedule_events();
		Modules\Fiscalization::reschedule_retries(); // Deactivation removed the per-order retry events.
		return true;
	}

	/** init: re-schedules the recurring events when the main queue event is missing (see run()). */
	public static function ensure_events() {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( 'wpcap_process_jobs' ) ) { return; }
		if ( '' === (string) get_option( 'wpcap_db_version', '' ) ) { return; } // not installed on this site (yet)
		self::schedule_events();
		Modules\Fiscalization::reschedule_retries(); // Deactivation removed the per-order retry events.
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
		CapacityCounters::schedule();
	}

	/**
	 * Request caches holding data of the current site that are not keyed by table prefix: emptied on
	 * switch_blog, so switch_to_blog() never answers with the previous site's staff assignments,
	 * provider resources, discount codes, … (A517, completes A182; module caches flush through
	 * Orders::site_switched() → wpcap_availability_flushed). A new such cache belongs here.
	 *
	 * @return callable[]
	 */
	public static function site_cache_flushers() {
		return array(
			array( StaffAssignments::class, 'flush' ),
			array( ServiceProviders::class, 'flush' ),
			array( Campaigns::class, 'flush' ),
			array( HelpCode::class, 'flush' ),
			array( LegalVersions::class, 'flush' ),
			array( Series\Pricing::class, 'flush_cache' ),
			array( Partners\Repository::class, 'flush_terms' ),
			array( Exchange\Service::class, 'flush_cache' ),
		);
	}

	/**
	 * Every WP-Cron hook of the plugin (deactivation removes all of them; uninstall.php has its own copy
	 * of the list because it runs without the plugin classes).
	 */
	const CRON_HOOKS = array( 'wpcap_cleanup_reservations', 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_webhooks_dispatch', 'wpcap_workflow_dispatch', 'wpcap_process_fiscal_order', CapacityCounters::HOOK, Installer::UPGRADE_HOOK, Privacy\Retention::HOOK, Privacy::WALLET_PURGE_HOOK, Waitlists::HOOK, PageCache::SALES_HOOK, Mollie\MollieGateway::REFRESH_HOOK, NetworkInstall::HOOK );

	/**
	 * Every cron hook the plugin schedules (recurring jobs, one-off runs and TimeBudget continuation
	 * runs). Deactivation and uninstall remove all of them; a new scheduled hook belongs here
	 * (PERF-17/INT-7).
	 *
	 * @return string[]
	 */
	public static function cron_hooks() {
		return self::CRON_HOOKS;
	}

	public static function deactivate( $network_wide = false ) {
		$site_ids = is_multisite() && $network_wide ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
		foreach ( $site_ids as $site_id ) {
			if ( is_multisite() && $network_wide ) { switch_to_blog( (int) $site_id ); }
			// wp_unschedule_hook also removes continuation runs scheduled with arguments.
			foreach ( self::cron_hooks() as $hook ) {
				wp_unschedule_hook( $hook );
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( '', array(), Jobs\TimeBudget::AS_GROUP );
				as_unschedule_all_actions( '', array(), LegacySlug::LEGACY_AS_GROUP );
			}
			if ( is_multisite() && $network_wide ) { restore_current_blog(); }
		}
	}

	public function register_blocks() {
		PublicEvents::register_styles(); // block.json "style" (A391).
		$block = register_block_type( WPCAP_PATH . 'src/blocks/events' );
		Localization::block_translations( $block );
		LegacyNames::register_block_alias( $block ); // A303: former name wp-pass-captain/events.
		if ( $block && ! empty( $block->editor_script_handles ) && is_admin() ) {
			add_action( 'enqueue_block_editor_assets', static function () use ( $block ) {
				wp_add_inline_script( $block->editor_script_handles[0], 'window.WPCAP_BLOCK_EVENTS=' . wp_json_encode( PublicEvents::block_choices() ) . ';', 'before' );
			} );
		}
	}
}
