<?php
/**
 * Optional feature modules (P2+). Each module is a class in src/Modules/ that may implement any of:
 *
 *   public static function register()                 – hooks (called on plugins_loaded)
 *   public static function schema( $prefix, $charset ) – array of CREATE TABLE statements (dbDelta format)
 *   public static function settings_defaults()        – array key => default (merged into Settings)
 *   public static function admin_pages()              – array of [slug, title, capability, callable]
 *   public static function admin_handlers()           – array admin-post action => callable
 *
 * Schema changes are detected by hashing all module statements, so adding a column upgrades the DB.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Modules {
	/** Bundled module classes, discovered once per request. */
	private static $bundled = null;

	/** Module classes in load order (the directory is scanned once per request; the filter runs on every call). */
	public static function classes() {
		if ( null === self::$bundled ) {
			$classes = array();
			foreach ( (array) glob( __DIR__ . '/Modules/*.php' ) as $file ) {
				$class = __NAMESPACE__ . '\\Modules\\' . basename( $file, '.php' );
				if ( class_exists( $class ) ) { $classes[] = $class; }
			}
			sort( $classes );
			self::$bundled = $classes;
		}
		return apply_filters( 'wpcap_modules', self::$bundled );
	}

	private static function collect( $method, ...$args ) {
		$out = array();
		foreach ( self::classes() as $class ) {
			if ( method_exists( $class, $method ) ) {
				$out = array_merge( $out, (array) call_user_func_array( array( $class, $method ), $args ) );
			}
		}
		return $out;
	}

	public static function register_all() {
		foreach ( self::classes() as $class ) {
			if ( method_exists( $class, 'register' ) ) { $class::register(); }
		}
	}

	public static function schema( $prefix, $charset ) { return self::collect( 'schema', $prefix, $charset ); }

	/** Hash of all module schema statements; part of the stored DB version. */
	public static function schema_version() {
		global $wpdb;
		return substr( md5( implode( "\n", self::schema( $wpdb->prefix, '' ) ) ), 0, 12 );
	}

	public static function settings_defaults() { return self::collect( 'settings_defaults' ); }

	/** Admin pages; arrays are kept as list items. */
	public static function admin_pages() {
		$out = array();
		foreach ( self::classes() as $class ) {
			if ( method_exists( $class, 'admin_pages' ) ) {
				foreach ( (array) $class::admin_pages() as $page ) { $out[] = $page; }
			}
		}
		return $out;
	}

	public static function admin_handlers() { return self::collect( 'admin_handlers' ); }
}
