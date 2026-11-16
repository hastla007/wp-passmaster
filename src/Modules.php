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
 * A new module must also be listed in Modules::MANIFEST with the methods it implements (PERF-14).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Modules {
	/**
	 * Bundled modules (class name in src/Modules/ => static methods it implements), PERF-14.
	 *
	 * A static list instead of glob() + class_exists() on every request: no directory scan, and a
	 * module file is only loaded when one of its listed methods is called (e.g. a module with only
	 * admin pages is never loaded on the frontend). The contract test zzzzzzz_z3_perf.php checks
	 * that every file in src/Modules/ is listed with exactly the methods it implements.
	 */
	const MANIFEST = array(
		'Admission' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'BoxOffice' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'Calendar' => array( 'admin_handlers', 'admin_pages', 'register', 'settings_defaults' ),
		'Connectors' => array( 'admin_handlers', 'admin_pages', 'register', 'schema' ),
		'Fiscalization' => array( 'admin_handlers', 'admin_pages', 'register', 'schema' ),
		'MailTemplates' => array( 'admin_handlers', 'admin_pages', 'register', 'settings_defaults' ),
		'Mollie' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'OfflineScanner' => array( 'admin_handlers', 'admin_pages', 'register', 'schema' ),
		'Partners' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'PrintTemplates' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'Reminders' => array( 'admin_handlers', 'admin_pages', 'register', 'settings_defaults' ),
		'Reports' => array( 'admin_handlers', 'admin_pages' ),
		'SalesRules' => array( 'admin_handlers', 'admin_pages', 'register', 'settings_defaults' ),
		'Seating' => array( 'admin_handlers', 'admin_pages', 'register', 'schema' ),
		'Series' => array( 'admin_handlers', 'admin_pages', 'register', 'schema' ),
		'Services' => array( 'register' ),
		'Sms' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'Wallet' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
		'Webhooks' => array( 'admin_handlers', 'admin_pages', 'register', 'schema', 'settings_defaults' ),
	);

	/** Bundled module classes (sorted, from MANIFEST; nothing is loaded to build the list). */
	public static function bundled() {
		$classes = array();
		foreach ( array_keys( self::MANIFEST ) as $name ) { $classes[] = __NAMESPACE__ . '\\Modules\\' . $name; }
		sort( $classes );
		return $classes;
	}

	/** Module classes in load order (the filter wpcap_modules runs on every call). */
	public static function classes() {
		return apply_filters( 'wpcap_modules', self::bundled() );
	}

	/**
	 * Whether $class implements $method. Bundled modules are answered from MANIFEST without loading
	 * the class; classes added through wpcap_modules are checked with method_exists().
	 */
	public static function provides( $module, $method ) {
		$prefix = __NAMESPACE__ . '\\Modules\\';
		if ( 0 === strpos( (string) $module, $prefix ) ) {
			$name = substr( (string) $module, strlen( $prefix ) );
			if ( isset( self::MANIFEST[ $name ] ) ) { return in_array( $method, self::MANIFEST[ $name ], true ) && class_exists( $module ); }
		}
		return method_exists( $module, $method );
	}

	private static function collect( $method, ...$args ) {
		$out = array();
		foreach ( self::classes() as $class ) {
			if ( self::provides( $class, $method ) ) {
				$out = array_merge( $out, (array) call_user_func_array( array( $class, $method ), $args ) );
			}
		}
		return $out;
	}

	public static function register_all() {
		foreach ( self::classes() as $class ) {
			if ( self::provides( $class, 'register' ) ) { $class::register(); }
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
			if ( self::provides( $class, 'admin_pages' ) ) {
				foreach ( (array) $class::admin_pages() as $page ) { $out[] = $page; }
			}
		}
		return $out;
	}

	/**
	 * Admin pages split for the menu: [visible pages, pages of modules hidden in "Funktionen"
	 * (Admin\Dashboard::module_hidden(); registered without menu entry, so links keep working)].
	 *
	 * @return array{0: array, 1: array}
	 */
	public static function menu_pages() {
		$visible = array();
		$hidden = array();
		foreach ( self::classes() as $class ) {
			if ( ! self::provides( $class, 'admin_pages' ) ) { continue; }
			$is_hidden = Admin\Dashboard::module_hidden( $class );
			foreach ( (array) $class::admin_pages() as $page ) {
				if ( $is_hidden ) { $hidden[] = $page; } else { $visible[] = $page; }
			}
		}
		return array( $visible, $hidden );
	}

	public static function admin_handlers() { return self::collect( 'admin_handlers' ); }
}
