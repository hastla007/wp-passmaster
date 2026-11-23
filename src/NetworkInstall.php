<?php
/**
 * Network activation without a timeout (A287).
 *
 * Activating the plugin network-wide used to create the full schema (about 100 tables) for every site
 * inside the activation request, which timed out on larger networks and left sites half installed.
 * Now only the current site is installed right away; all other sites are installed
 *  - lazily by their own next admin, cron or WP-CLI request (Plugin::maybe_upgrade(), as after an update),
 *  - and in the background in batches by the single cron event `wpcap_network_install` of the site that
 *    activated the plugin (progress in the network option `wpcap_network_install`, resumable).
 * Each site is installed behind its own upgrade lock, so the lazy path and a batch never collide.
 *
 * The uninstall opt-in protection (INT-1: the opt-in moves out of wpcap_settings, where the old copy's
 * uninstall.php would read it) does not wait for a site's install batch: every run first sweeps up to
 * POLICY_BATCH sites with only that cheap migration (options of sites that have plugin data), so all sites
 * of a large network are protected after a few runs, not only once the slow install batches reach them.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NetworkInstall {
	const HOOK = 'wpcap_network_install';
	const OPTION = 'wpcap_network_install';
	/** Seconds per run (stops before the next site once exceeded). */
	const BUDGET = 15;
	/** Sites per run at most. */
	const BATCH = 25;
	/** Sites per run whose uninstall opt-in is migrated ahead of their install (filter wpcap_network_install_policy_batch). */
	const POLICY_BATCH = 500;

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 0 );
	}

	/** Network activation: queue the other sites (the current one is installed by the caller). */
	public static function start() {
		update_site_option( self::OPTION, array( 'offset' => 0, 'done' => 0, 'started_at' => time() ) );
		self::schedule( 0 );
	}

	/** Pending state, or null when no network installation runs. */
	public static function state() {
		$state = get_site_option( self::OPTION );
		return is_array( $state ) ? $state : null;
	}

	private static function schedule( $delay ) {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::HOOK );
		}
	}

	/**
	 * Cron handler: installs the next sites, then re-schedules itself until every site was visited.
	 *
	 * @param callable|null $install Installs the current (switched-to) site; default Plugin::maybe_upgrade('cron').
	 * @return int Sites visited in this run.
	 */
	public static function run( $install = null ) {
		$state = self::state();
		if ( null === $state ) { return 0; }
		if ( ! is_multisite() || ! self::network_active() ) {
			delete_site_option( self::OPTION );
			return 0;
		}
		$install  = is_callable( $install ) ? $install : array( __CLASS__, 'install_site' );
		$budget   = max( 1, (int) apply_filters( 'wpcap_network_install_budget', self::BUDGET ) );
		$batch    = max( 1, (int) apply_filters( 'wpcap_network_install_batch', self::BATCH ) );
		$deadline = microtime( true ) + $budget;
		$state    = self::sweep_policies( $state, $deadline );
		$ids      = get_sites( array( 'fields' => 'ids', 'number' => $batch, 'offset' => (int) $state['offset'], 'orderby' => 'id', 'order' => 'ASC' ) );
		$visited  = 0;
		foreach ( (array) $ids as $site_id ) {
			if ( $visited && microtime( true ) >= $deadline ) { break; }
			// Progress is saved before the site, so a site that fails hard is not retried in a loop
			// (its own next request installs it lazily).
			$state['offset'] = (int) $state['offset'] + 1;
			update_site_option( self::OPTION, $state );
			switch_to_blog( (int) $site_id );
			try {
				call_user_func( $install, (int) $site_id );
			} finally {
				restore_current_blog();
			}
			++$visited;
			$state['done'] = (int) ( $state['done'] ?? 0 ) + 1;
		}
		if ( count( (array) $ids ) < $batch && $visited === count( (array) $ids ) ) {
			delete_site_option( self::OPTION );
			return $visited;
		}
		update_site_option( self::OPTION, $state );
		self::schedule( 5 );
		return $visited;
	}

	/**
	 * Uninstall opt-in migration (Settings::migrate_uninstall_policy()) for the next sites ahead of their
	 * install; at least one site per run, then until the time budget is used. Progress in $state
	 * (policy_offset, policies_done), saved before each site like the install loop.
	 */
	private static function sweep_policies( array $state, $deadline ) {
		if ( ! empty( $state['policies_done'] ) ) { return $state; }
		$batch = max( 1, (int) apply_filters( 'wpcap_network_install_policy_batch', self::POLICY_BATCH ) );
		$ids = (array) get_sites( array( 'fields' => 'ids', 'number' => $batch, 'offset' => (int) ( $state['policy_offset'] ?? 0 ), 'orderby' => 'id', 'order' => 'ASC' ) );
		$swept = 0;
		foreach ( $ids as $site_id ) {
			if ( $swept && microtime( true ) >= $deadline ) { break; }
			$state['policy_offset'] = (int) ( $state['policy_offset'] ?? 0 ) + 1;
			update_site_option( self::OPTION, $state );
			switch_to_blog( (int) $site_id );
			try {
				if ( get_option( 'wpcap_db_version' ) ) { Settings::migrate_uninstall_policy(); }
			} finally {
				restore_current_blog();
			}
			++$swept;
		}
		if ( count( $ids ) < $batch && count( $ids ) === $swept ) {
			$state['policies_done'] = 1;
			update_site_option( self::OPTION, $state );
		}
		return $state;
	}

	/** Installs or upgrades the current site (behind its upgrade lock; no-op when current). */
	public static function install_site() {
		// Sites that already have plugin data get the uninstall opt-in protection first (INT-1, cheap).
		if ( get_option( 'wpcap_db_version' ) ) { Settings::migrate_uninstall_policy(); }
		return Plugin::maybe_upgrade( 'cron' );
	}

	private static function network_active() {
		if ( ! function_exists( 'get_site_option' ) ) { return false; }
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $plugins[ plugin_basename( WPCAP_FILE ) ] );
	}
}
