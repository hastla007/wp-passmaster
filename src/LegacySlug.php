<?php
/**
 * Compatibility with installations from before the rename "WP Pass Captain" (folder, slug and text (legacy-slug)
 * domain wp-pass-captain) → "Pass Captain" (pass-captain), required for WordPress.org. (legacy-slug)
 *
 * - REST: the old namespace keeps working. Payment and SMS webhooks registered at the providers,
 *   Apple Wallet passes already on phones (web service URL is baked into the pass), paired scanner
 *   apps and partner integrations still call /wp-json/<old>/v1/…; such requests are routed to
 *   /pass-captain/v1/… and answered with "Deprecation" and a "Link" to the new URL.
 * - Signatures computed over the called URL (Twilio) are verified against the URL actually called.
 * - Admin: old page slugs (admin.php?page=<old>…) redirect to the new pages.
 * - Old plugin copy (folder wp-pass-captain, legacy-slug): taken over on activation (see pass-captain.php);
 *   while its folder still exists, "delete data on uninstall" is paused – deleting the old copy runs
 *   its uninstall.php against the same tables. Cron events it removes on uninstall are re-scheduled.
 * - Action Scheduler: follow-up runs were queued in the group <old slug>; pending ones are moved to the
 *   group pass-captain once (action_scheduler_init) and count as queued until then (no duplicates).
 * - Theme overrides in the old template folder (<theme>/<old>/event-card.php) are still used, with a
 *   deprecation note and an admin notice until the theme moves them to <theme>/pass-captain/.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class LegacySlug {
	const LEGACY_SLUG     = 'wp-pass-captain';
	const LEGACY_BASENAME = 'wp-pass-captain/wp-pass-captain.php'; // legacy-slug
	const NS              = 'pass-captain/v1';
	const LEGACY_NS       = 'wp-pass-captain/v1'; // legacy-slug
	/** Action Scheduler group of follow-up runs before the rename (Jobs\TimeBudget::continue_later()). */
	const LEGACY_AS_GROUP = self::LEGACY_SLUG;

	/** Option: the delete-data setting was switched off while the old copy exists (value: 1). */
	const PARKED_OPTION = 'wpcap_uninstall_delete_parked';
	/** Option: the old copy was taken over in the last request; finish activation now. */
	const TAKEOVER_OPTION = 'wpcap_legacy_takeover';
	/** Network option: an old copy was present at the last check (to notice its removal). */
	const SEEN_OPTION = 'wpcap_legacy_copy_seen';
	/** Option: pending Action Scheduler actions of the old group were moved (value: 1). */
	const AS_MIGRATED_OPTION = 'wpcap_legacy_as_group_migrated';
	/** Option: theme templates found only in the old folder (template name => path relative to the themes dir). */
	const THEME_TEMPLATE_OPTION = 'wpcap_legacy_theme_templates';
	/** User meta: the notice about old theme templates was dismissed (value: the list it was dismissed for). */
	const THEME_NOTICE_META = 'wpcap_legacy_theme_notice_dismissed';

	/** Whether the REST request being dispatched came in under the old namespace. */
	private static $legacy_request = false;

	/** @var \WeakMap|null Request objects that came in under the old namespace (nested requests stay apart). */
	private static $legacy_requests = null;

	public static function register() {
		self::guard_uninstall();
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'route_legacy_namespace' ), 0, 3 );
		// Inside dispatch (also internal rest_do_request() calls) and again for served HTTP answers (errors).
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'deprecation_headers_after_callbacks' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'deprecation_headers' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_admin_page' ), 0 );
		// An old slug is no registered page: WordPress refuses it (wp-admin/menu.php, 403) before admin_init runs.
		add_action( 'admin_page_access_denied', array( __CLASS__, 'redirect_legacy_admin_page' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'watch_legacy_copy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'theme_template_notice' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'migrate_action_scheduler_group' ) );
	}

	/** Whether a pending action for $hook/$args is still queued in the pre-rename Action Scheduler group. */
	public static function has_legacy_action( $hook, array $args = array() ) {
		return function_exists( 'as_has_scheduled_action' ) && (bool) as_has_scheduled_action( $hook, $args, self::LEGACY_AS_GROUP );
	}

	/**
	 * action_scheduler_init, once per site: pending follow-up runs of the old group are unscheduled and,
	 * where none is queued in the new group yet, queued there again (Jobs\TimeBudget::continue_later()).
	 * Without this, continue_later() would not see them and queue a second run of the same worker.
	 *
	 * @return int Number of hooks whose old-group actions were moved.
	 */
	public static function migrate_action_scheduler_group() {
		if ( get_option( self::AS_MIGRATED_OPTION ) || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) { return 0; }
		$moved = 0;
		$args  = array( 'continue' );
		foreach ( Jobs\TimeBudget::HOOKS as $hook ) {
			if ( ! as_has_scheduled_action( $hook, $args, self::LEGACY_AS_GROUP ) ) { continue; }
			as_unschedule_all_actions( $hook, $args, self::LEGACY_AS_GROUP );
			Jobs\TimeBudget::continue_later( $hook );
			++$moved;
		}
		update_option( self::AS_MIGRATED_OPTION, 1 );
		if ( $moved ) { Audit::record( 'legacy_action_scheduler_group_migrated', 'plugin', 0, array( 'hooks' => $moved ) ); }
		return $moved;
	}

	/** rest_pre_dispatch: /<old>/v1/… → /pass-captain/v1/… (the request object is matched afterwards). */
	public static function route_legacy_namespace( $result, $server = null, $request = null ) {
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) { return $result; }
		$mapped = self::map_route( (string) $request->get_route() );
		self::$legacy_request = null !== $mapped;
		if ( null !== $mapped ) {
			$request->set_route( $mapped );
			self::$legacy_requests = self::$legacy_requests ?? new \WeakMap();
			self::$legacy_requests[ $request ] = true;
		}
		return $result;
	}

	/** New route for a route in the old namespace, or null. */
	public static function map_route( $route ) {
		$old = '/' . self::LEGACY_NS;
		if ( $route === $old || 0 === strpos( $route, $old . '/' ) ) {
			return '/' . self::NS . substr( $route, strlen( $old ) );
		}
		return null;
	}

	/** rest_post_dispatch: marks answers to old-namespace requests as deprecated (RFC 8594/9745). */
	public static function deprecation_headers( $response, $server = null, $request = null ) {
		$legacy = is_object( $request ) && null !== self::$legacy_requests && isset( self::$legacy_requests[ $request ] );
		if ( $legacy && is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Deprecation', 'true' );
			if ( is_object( $request ) && method_exists( $request, 'get_route' ) && function_exists( 'rest_url' ) ) {
				$response->header( 'Link', '<' . esc_url_raw( rest_url( ltrim( (string) $request->get_route(), '/' ) ) ) . '>; rel="successor-version"' );
			}
		}
		return $response;
	}

	/** rest_request_after_callbacks: same headers on the handler's response (argument order differs). */
	public static function deprecation_headers_after_callbacks( $response, $handler = null, $request = null ) {
		return self::deprecation_headers( $response, null, $request );
	}

	/** Whether the current REST request used the old namespace. */
	public static function is_legacy_request() {
		return self::$legacy_request;
	}

	/** For signatures over the called URL: the old-namespace form when the caller used it. */
	public static function called_url( $url ) {
		if ( ! self::$legacy_request ) { return (string) $url; }
		return str_replace( '/' . self::NS . '/', '/' . self::LEGACY_NS . '/', (string) $url );
	}

	/** Resets the per-request flag (tests, long-running processes). */
	public static function reset() {
		self::$legacy_request = false;
		self::$legacy_requests = null;
	}

	/** admin.php?page=<old>[-…] → admin.php?page=pass-captain[-…] (bookmarks, links in old mails). */
	public static function redirect_legacy_admin_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect of a page slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$new  = self::map_admin_page( $page );
		if ( null === $new || wp_doing_ajax() ) { return; }
		wp_safe_redirect( add_query_arg( 'page', $new ) );
		\PassCaptain\Http::end();
	}

	/** New admin page slug for an old one, or null. */
	public static function map_admin_page( $page ) {
		$page = (string) $page;
		if ( $page === self::LEGACY_SLUG || 0 === strpos( $page, self::LEGACY_SLUG . '-' ) ) {
			return 'pass-captain' . substr( $page, strlen( self::LEGACY_SLUG ) );
		}
		return null;
	}

	/** Absolute path of the old plugin's main file (it may still be installed next to this one). */
	public static function legacy_file() {
		return ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) . '/' . self::LEGACY_BASENAME;
	}

	/** Whether the old copy is still installed (not necessarily active). */
	public static function legacy_installed() {
		$file = self::legacy_file();
		// Our own folder never has the old name; a symlinked development copy is no old release either.
		return '' !== $file && is_file( $file ) && realpath( $file ) !== realpath( (string) WPCAP_FILE );
	}

	/**
	 * Runs on every load of this plugin (admin, front end, cron, WP-CLI) – before anything can delete the
	 * old copy: while its folder exists, "delete data on uninstall" is paused on EVERY site of the network
	 * (the old uninstall.php loops over all sites and would drop their shared tables); once the folder is
	 * gone, the setting is restored on the sites where it was paused. Costs one file check per request.
	 */
	public static function guard_uninstall() {
		$present = self::legacy_installed();
		$seen    = (bool) self::network_get( self::SEEN_OPTION );
		if ( $present && ! $seen ) {
			self::for_each_site( array( __CLASS__, 'park_site' ) );
			self::network_set( self::SEEN_OPTION, 1 );
		} elseif ( ! $present && $seen ) {
			self::for_each_site( array( __CLASS__, 'restore_site' ) );
			self::network_delete( self::SEEN_OPTION );
		}
	}

	/**
	 * Pauses the delete-data setting of the current site for the old copy's uninstall.php (if it is still in
	 * the old key wpcap_settings['delete_data_on_uninstall']): the old key is set to 0 and the opt-in moves to
	 * Settings::UNINSTALL_POLICY, where this copy reads it (same result as Settings::migrate_uninstall_policy()).
	 */
	public static function park_site() {
		$settings = (array) get_option( 'wpcap_settings', array() );
		if ( empty( $settings['delete_data_on_uninstall'] ) ) { return; }
		$settings['delete_data_on_uninstall'] = 0;
		update_option( 'wpcap_settings', $settings );
		update_option( Settings::UNINSTALL_POLICY, 'delete', false );
		update_option( self::PARKED_OPTION, 1, false );
		$folder = dirname( self::LEGACY_BASENAME );
		$record = static function () use ( $folder ) {
			Audit::record( 'legacy_copy_uninstall_paused', 'plugin', 0, array( 'folder' => $folder ) );
		};
		// Runs at plugins_loaded: the audit entry reads translated setting defaults, so it waits for init.
		if ( did_action( 'init' ) ) { $record(); } else { add_action( 'init', $record, 1 ); }
	}

	/**
	 * After the old copy is gone: the opt-in that was paused here stays in Settings::UNINSTALL_POLICY (the old
	 * key in wpcap_settings stays 0, see Settings::UNINSTALL_POLICY); only the parked marker is removed.
	 */
	public static function restore_site() {
		if ( ! get_option( self::PARKED_OPTION ) ) { return; }
		update_option( Settings::UNINSTALL_POLICY, 'delete', false );
		delete_option( self::PARKED_OPTION );
	}

	/** Calls $callback on every site of the network (or just this site). */
	private static function for_each_site( callable $callback ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			$callback();
			return;
		}
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$callback();
			restore_current_blog();
		}
	}

	private static function network_get( $key ) {
		return function_exists( 'get_site_option' ) ? get_site_option( $key ) : get_option( $key );
	}

	private static function network_set( $key, $value ) {
		return function_exists( 'update_site_option' ) ? update_site_option( $key, $value ) : update_option( $key, $value, false );
	}

	private static function network_delete( $key ) {
		return function_exists( 'delete_site_option' ) ? delete_site_option( $key ) : delete_option( $key );
	}

	/** admin_init: finishes a takeover (cron of the new copy) and re-checks the old folder. */
	public static function watch_legacy_copy() {
		if ( get_option( self::TAKEOVER_OPTION ) ) {
			delete_option( self::TAKEOVER_OPTION );
			Plugin::schedule_events();
		}
		self::guard_uninstall();
	}

	/** Admin notice while the old copy is installed. */
	public static function notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! self::legacy_installed() ) { return; }
		$parked = (bool) get_option( self::PARKED_OPTION );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: folder name of the old plugin copy */
				__( 'Die frühere Version von Pass Captain (Ordner „%s“) ist noch installiert. Sie wurde deaktiviert und kann unter „Plugins“ gelöscht werden – Bestellungen, Tickets und Einstellungen bleiben erhalten, Pass Captain nutzt dieselben Daten weiter.', 'pass-captain' ),
				dirname( self::LEGACY_BASENAME )
			)
		);
		if ( $parked ) {
			echo ' ' . esc_html__( '„Beim Löschen des Plugins alle Daten entfernen“ ist so lange pausiert und wird danach automatisch wieder eingeschaltet.', 'pass-captain' );
		}
		echo '</p></div>';
	}

	/**
	 * Theme override in the folder used before the rename (<theme>/<old slug>/<name>), or ''. Still used so
	 * a customised event card does not silently fall back to the bundled one after the update; the first
	 * use is remembered for the admin notice and reported via _deprecated_file() (WP_DEBUG only).
	 */
	public static function legacy_theme_template( $name ) {
		$name     = basename( (string) $name );
		$template = function_exists( 'locate_template' ) ? (string) locate_template( self::LEGACY_SLUG . '/' . $name ) : '';
		if ( '' === $template ) { return ''; }
		$seen = (array) get_option( self::THEME_TEMPLATE_OPTION, array() );
		$path = self::theme_relative( $template );
		if ( ( $seen[ $name ] ?? null ) !== $path ) {
			$seen[ $name ] = $path;
			update_option( self::THEME_TEMPLATE_OPTION, $seen, false );
		}
		static $reported = array();
		if ( ! isset( $reported[ $name ] ) && function_exists( '_deprecated_file' ) ) {
			$reported[ $name ] = true;
			_deprecated_file( esc_html( $path ), '1.5.0', esc_html( 'pass-captain/' . $name ) );
		}
		return $template;
	}

	/** Template path relative to the themes directory (no server paths in notices). */
	private static function theme_relative( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		$root = function_exists( 'get_theme_root' ) ? str_replace( '\\', '/', (string) get_theme_root() ) : '';
		if ( '' !== $root && 0 === strpos( $file, rtrim( $root, '/' ) . '/' ) ) { return substr( $file, strlen( rtrim( $root, '/' ) ) + 1 ); }
		return basename( dirname( dirname( $file ) ) ) . '/' . basename( dirname( $file ) ) . '/' . basename( $file );
	}

	/**
	 * Old theme templates that are still in use: entries whose file still exists in the old folder and
	 * has no counterpart in the new one (moved or deleted templates drop out of the list).
	 *
	 * @return array template name => path relative to the themes directory.
	 */
	public static function legacy_theme_templates() {
		$seen = (array) get_option( self::THEME_TEMPLATE_OPTION, array() );
		if ( ! $seen || ! function_exists( 'locate_template' ) ) { return array(); }
		$active = array();
		foreach ( $seen as $name => $path ) {
			$name = basename( (string) $name );
			if ( '' === (string) locate_template( 'pass-captain/' . $name ) && '' !== (string) locate_template( self::LEGACY_SLUG . '/' . $name ) ) {
				$active[ $name ] = (string) $path;
			}
		}
		if ( $active !== $seen ) {
			if ( $active ) { update_option( self::THEME_TEMPLATE_OPTION, $active, false ); } else { delete_option( self::THEME_TEMPLATE_OPTION ); }
		}
		return $active;
	}

	/** Admin notice while the theme overrides templates only in the old folder (dismissible per user). */
	public static function theme_template_notice() {
		if ( ! current_user_can( 'switch_themes' ) ) { return; }
		$templates = self::legacy_theme_templates();
		if ( ! $templates ) { return; }
		$user_id = get_current_user_id();
		$key     = md5( (string) wp_json_encode( $templates ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified here.
		if ( isset( $_GET['wpcap_dismiss_theme_notice'] ) && $user_id && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::THEME_NOTICE_META ) ) {
			update_user_meta( $user_id, self::THEME_NOTICE_META, $key );
		}
		if ( $user_id && get_user_meta( $user_id, self::THEME_NOTICE_META, true ) === $key ) { return; }
		$dismiss = wp_nonce_url( add_query_arg( 'wpcap_dismiss_theme_notice', '1' ), self::THEME_NOTICE_META );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: theme template path(s) in the old folder, 2: new folder name */
				__( 'Dein Theme überschreibt Vorlagen von Pass Captain noch im früheren Ordner (%1$s). Sie werden weiter verwendet, bitte verschiebe sie aber in den Ordner „%2$s“ im Theme – die Unterstützung des alten Ordners entfällt in einer künftigen Version.', 'pass-captain' ),
				implode( ', ', $templates ),
				'pass-captain/'
			)
		);
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Hinweis für mich ausblenden', 'pass-captain' ) . '</a></p></div>';
	}
}
