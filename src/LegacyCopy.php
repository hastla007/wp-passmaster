<?php
/**
 * Takeover from the old copy (LegacySlug::LEGACY_BASENAME, up to 1.4) in the same request.
 *
 * WordPress loads the active plugins from a list it read before loading the first one. When this copy
 * takes over the activation (pass-captain.php removes the old entry from active_plugins), the old copy is
 * still loaded once in the same request – after this copy when "pass-captain/" sorts first. Its bootstrap
 * then defines WPCAP_VERSION, WPCAP_FILE, WPCAP_PATH and WPCAP_URL again (PHP warnings "Constant … already
 * defined") and registers a second plugins_loaded callback that starts the plugin again. Because its
 * constants already point to this copy, its require_once of the autoloader and functions.php are no-ops and
 * its classes are this copy's classes; Plugin::run() and TicketPage::register() are idempotent, so the second
 * start registers nothing twice. neutralize() silences exactly the four constant warnings while the old file
 * is loaded and restores the previous error handler afterwards (action plugin_loaded of the old file, at the
 * latest plugins_loaded).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LegacyCopy {
	/** Constants defined by both bootstraps. */
	const CONSTANTS = array( 'WPCAP_VERSION', 'WPCAP_FILE', 'WPCAP_PATH', 'WPCAP_URL' );

	/** @var callable|null Installed error handler (null when inactive). */
	private static $handler = null;

	/** @var callable|null Error handler that was active before neutralize(). */
	private static $previous = null;

	/** @var string[] Basenames of the old copy that are still loaded in this request. */
	private static $pending = array();

	/** @var int Warnings swallowed (tests). */
	public static $swallowed = 0;

	/**
	 * Prepares this request for the old copy loading after this one.
	 *
	 * @param string[] $legacy Basenames of the old copy that were active at the start of the request.
	 */
	public static function neutralize( array $legacy ) {
		$legacy = array_values( array_filter( array_map( 'strval', $legacy ) ) );
		if ( ! $legacy || null !== self::$handler ) { return; }
		self::$pending = $legacy;
		self::$handler = static function ( $errno, $errstr, $errfile = '', $errline = 0 ) {
			if ( null !== self::$handler && E_WARNING === $errno && preg_match( '/^Constant (' . implode( '|', self::CONSTANTS ) . ') already defined$/', (string) $errstr ) ) {
				++self::$swallowed;
				return true;
			}
			return is_callable( self::$previous ) ? (bool) call_user_func( self::$previous, $errno, $errstr, $errfile, $errline ) : false;
		};
		self::$previous = set_error_handler( self::$handler ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- removed again after the old file (restore()).
		add_action( 'plugin_loaded', array( __CLASS__, 'plugin_loaded' ), 0 );
		add_action( 'plugins_loaded', array( __CLASS__, 'restore' ), -1000 );
	}

	/** Action plugin_loaded: the old copy has been loaded. */
	public static function plugin_loaded( $plugin ) {
		foreach ( self::$pending as $basename ) {
			if ( '' !== $basename && substr( str_replace( '\\', '/', (string) $plugin ), -strlen( $basename ) - 1 ) === '/' . $basename ) {
				self::restore();
				return;
			}
		}
	}

	/** Removes the error handler (only when it is still the current one; otherwise it just stops filtering). */
	public static function restore() {
		if ( null === self::$handler ) { return; }
		$probe = static function () { return false; };
		$current = set_error_handler( $probe ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- only reads the current handler.
		restore_error_handler();
		if ( $current === self::$handler ) { restore_error_handler(); }
		self::$handler = null;
		self::$pending = array();
	}

	/** Whether the warning filter is active (tests). */
	public static function active() {
		return null !== self::$handler;
	}
}
