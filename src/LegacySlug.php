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
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LegacySlug {
	const LEGACY_SLUG     = 'wp-pass-captain';
	const LEGACY_BASENAME = 'wp-pass-captain/wp-pass-captain.php'; // legacy-slug
	const NS              = 'pass-captain/v1';
	const LEGACY_NS       = 'wp-pass-captain/v1'; // legacy-slug

	/** Option: the delete-data setting was switched off while the old copy exists (value: 1). */
	const PARKED_OPTION = 'wpcap_uninstall_delete_parked';
	/** Option: the old copy was taken over in the last request; finish activation now. */
	const TAKEOVER_OPTION = 'wpcap_legacy_takeover';
	/** Network option: an old copy was present at the last check (to notice its removal). */
	const SEEN_OPTION = 'wpcap_legacy_copy_seen';

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
		add_action( 'admin_init', array( __CLASS__, 'watch_legacy_copy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
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
		exit;
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

	/** Pauses the delete-data setting of the current site (if set). */
	public static function park_site() {
		$settings = (array) get_option( 'wpcap_settings', array() );
		if ( empty( $settings['delete_data_on_uninstall'] ) ) { return; }
		$settings['delete_data_on_uninstall'] = 0;
		update_option( 'wpcap_settings', $settings );
		update_option( self::PARKED_OPTION, 1, false );
		Audit::record( 'legacy_copy_uninstall_paused', 'plugin', 0, array( 'folder' => dirname( self::LEGACY_BASENAME ) ) );
	}

	/** Restores the delete-data setting of the current site if it was paused here. */
	public static function restore_site() {
		if ( ! get_option( self::PARKED_OPTION ) ) { return; }
		$settings = (array) get_option( 'wpcap_settings', array() );
		$settings['delete_data_on_uninstall'] = 1;
		update_option( 'wpcap_settings', $settings );
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
}
