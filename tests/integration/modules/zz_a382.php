<?php
// phpcs:ignoreFile
/**
 * A382: the plugin no longer loads its catalogue eagerly with load_plugin_textdomain() (Plugin Check,
 * WordPress.org language packs). Localization::register_textdomain() only registers languages/ as the
 * just-in-time fallback path; reload_textdomain() unloads reloadably and keeps that path.
 */

use PassCaptain\Localization;

echo "A382: text domain via just-in-time loading\n";
$a382_root = dirname( __DIR__, 3 );
$a382_main = (string) file_get_contents( $a382_root . '/pass-captain.php' );
$a382_calls = array();
$a382_files = array( $a382_root . '/pass-captain.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $a382_root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $a382_info ) {
	if ( 'php' === $a382_info->getExtension() ) { $a382_files[] = $a382_info->getPathname(); }
}
foreach ( $a382_files as $a382_file ) {
	if ( preg_match( '/(?<![\w>:])load_plugin_textdomain\s*\(/', (string) file_get_contents( $a382_file ) ) ) { $a382_calls[] = str_replace( $a382_root . '/', '', $a382_file ); }
}
check( 'A382 no load_plugin_textdomain() call in shipped code' . ( $a382_calls ? ' – ' . implode( ', ', $a382_calls ) : '' ), ! $a382_calls );
check( 'A382 the main file registers the bundled catalogue path on plugins_loaded', false !== strpos( $a382_main, 'PassCaptain\\Localization::register_textdomain()' ) );

$a382_saved = $GLOBALS['wp_textdomain_registry'] ?? null;
$GLOBALS['wp_textdomain_registry'] = new class() { public $paths = array(); public function set_custom_path( $d, $p ) { $this->paths[ $d ] = $p; } };
check( 'A382 register_textdomain() sets languages/ as custom path of pass-captain', true === Localization::register_textdomain() && array( 'pass-captain' => WPCAP_PATH . 'languages' ) === $GLOBALS['wp_textdomain_registry']->paths );
$GLOBALS['wp_textdomain_registry']->paths = array();
Localization::reload_textdomain();
check( 'A382 reload_textdomain() keeps the fallback path registered', isset( $GLOBALS['wp_textdomain_registry']->paths['pass-captain'] ) );
$GLOBALS['wp_textdomain_registry'] = null;
check( 'A382 without the registry (WordPress before 6.1) nothing is loaded and false is returned', false === Localization::register_textdomain() );
$GLOBALS['wp_textdomain_registry'] = $a382_saved;
