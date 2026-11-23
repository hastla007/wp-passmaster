<?php
/**
 * A275: block editor texts are translatable. Every editor script that calls __() depends on wp-i18n
 * (otherwise wp.i18n is missing and the German source text shows), every block registration points
 * WordPress at the bundled JSON catalogues (wp_set_script_translations with the plugin's languages
 * directory), the build generates them (wp i18n make-json) and make-pot no longer skips block.json
 * (title/description/keywords). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$a275_root = dirname( __DIR__, 3 );

echo "A275: block editor translations\n";
if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( $path, $args = array() ) {
		// A303: a registration by name with arguments (alias of a former block name).
		if ( is_array( $args ) && $args ) { $GLOBALS['wpcap_test_block_types'][ $path ] = $args; return (object) array_merge( array( 'name' => $path ), $args ); }
		$meta = json_decode( (string) file_get_contents( rtrim( $path, '/' ) . '/block.json' ), true );
		$GLOBALS['a275_registered'][] = $meta['name'];
		return (object) array( 'name' => $meta['name'], 'editor_script_handles' => array( str_replace( '/', '-', $meta['name'] ) . '-editor-script' ), 'view_script_handles' => array() );
	}
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) { $GLOBALS['a275_translations'][ $handle ] = array( $domain, $path ); return true; }
}
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }

$GLOBALS['a275_translations'] = array();
$GLOBALS['a275_registered']   = array();
PassCaptain\Plugin::instance()->register_blocks();
PassCaptain\Modules\Calendar::register_block();
PassCaptain\Frontend\ServiceBooking::register_block();
$a275_lang = WPCAP_PATH . 'languages';
check( 'A275 all three blocks registered', 3 === count( $GLOBALS['a275_registered'] ) );
foreach ( $GLOBALS['a275_registered'] as $a275_n ) {
	$a275_h = str_replace( '/', '-', $a275_n );
	$a275_t = $GLOBALS['a275_translations'][ $a275_h . '-editor-script' ] ?? null;
	check( "A275 $a275_h: editor script translations from the plugin's languages directory", is_array( $a275_t ) && 'pass-captain' === $a275_t[0] && $a275_lang === $a275_t[1] );
}

foreach ( glob( $a275_root . '/src/blocks/*', GLOB_ONLYDIR ) as $a275_dir ) {
	$a275_name = basename( $a275_dir );
	$a275_meta = json_decode( (string) file_get_contents( $a275_dir . '/block.json' ), true );
	check( "A275 $a275_name/block.json declares the text domain", 'pass-captain' === ( $a275_meta['textdomain'] ?? '' ) );
	$a275_js = (string) @file_get_contents( $a275_dir . '/editor.js' );
	if ( preg_match( '/\b__\(/', $a275_js ) ) {
		$a275_asset = include $a275_dir . '/editor.asset.php';
		check( "A275 $a275_name/editor.js uses __() and depends on wp-i18n", in_array( 'wp-i18n', $a275_asset['dependencies'] ?? array(), true ) );
	}
}
$a275_ci = (string) file_get_contents( $a275_root . '/.github/workflows/ci.yml' );
check( 'A275 CI make-pot includes block.json strings (no --skip-block-json)', false !== strpos( $a275_ci, 'wp i18n make-pot' ) && false === strpos( $a275_ci, '--skip-block-json' ) );
check( 'A275 the release build generates the script translations (wp i18n make-json)', false !== strpos( (string) file_get_contents( $a275_root . '/build/release.sh' ), 'wp i18n make-json' ) );
check( 'A275 docs/TRANSLATIONS.md no longer skips block.json', false === strpos( (string) file_get_contents( $a275_root . '/docs/TRANSLATIONS.md' ), '--skip-block-json' ) );
