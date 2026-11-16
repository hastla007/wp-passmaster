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

	/** Installs or upgrades the current site (behind its upgrade lock; no-op when current). */
	public static function install_site() {
		return Plugin::maybe_upgrade( 'cron' );
	}

	private static function network_active() {
		if ( ! function_exists( 'get_site_option' ) ) { return false; }
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $plugins[ plugin_basename( WPCAP_FILE ) ] );
	}
}
