<?php
// phpcs:ignoreFile
/**
 * A303: the blocks are called pass-captain/events and pass-captain/calendar; the former names
 * wp-pass-captain/* stay registered as hidden aliases (same attributes and render callback) and are
 * converted in the editor. The shortcodes [wpcap_*] are the documented names, the former
 * [wp_pass_captain_*] tags still render and are reported as deprecated. Content detection (page cache,
 * calendar link target, setup checklist) knows both names.
 */

use PassCaptain\LegacyNames;
use PassCaptain\PageCache;

echo "A303: block and shortcode names without the wp- prefix\n";
$z3_root = dirname( __DIR__, 3 );

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( $path, $args = array() ) {
		if ( is_array( $args ) && $args ) { $GLOBALS['wpcap_test_block_types'][ $path ] = $args; return (object) array_merge( array( 'name' => $path ), $args ); }
		$meta = json_decode( (string) file_get_contents( rtrim( $path, '/' ) . '/block.json' ), true );
		return (object) array( 'name' => $meta['name'], 'editor_script_handles' => array(), 'view_script_handles' => array() );
	}
}
$z3_own_stub = ! function_exists( '_deprecated_function' );
if ( $z3_own_stub ) {
	function _deprecated_function( $function, $version, $replacement = '' ) { $GLOBALS['z3_deprecated'][] = array( $function, $version, $replacement ); }
}

foreach ( array( 'events', 'calendar' ) as $z3_dir ) {
	$z3_json = json_decode( (string) file_get_contents( $z3_root . '/src/blocks/' . $z3_dir . '/block.json' ), true );
	check( "A303 block.json of $z3_dir is named pass-captain/$z3_dir", "pass-captain/$z3_dir" === ( $z3_json['name'] ?? '' ) );
	$z3_js = (string) file_get_contents( $z3_root . '/src/blocks/' . $z3_dir . '/editor.js' );
	check( "A303 editor.js of $z3_dir registers and previews pass-captain/$z3_dir only", false === strpos( $z3_js, 'wp-pass-captain/' ) && 2 === substr_count( $z3_js, "'pass-captain/$z3_dir'" ) );
}
check( 'A303 every former block name maps to a current block.json name', array( 'wp-pass-captain/events' => 'pass-captain/events', 'wp-pass-captain/calendar' => 'pass-captain/calendar' ) === LegacyNames::BLOCKS );

// Alias registration: same attributes, supports (hidden in the inserter), styles and render callback.
$GLOBALS['wpcap_test_block_types'] = array();
$z3_render = static function ( $attributes ) { return 'rendered:' . (int) ( $attributes['event'] ?? 0 ); };
$z3_block = (object) array(
	'name' => 'pass-captain/events', 'api_version' => 3, 'title' => 'Pass Captain Events', 'category' => 'widgets', 'icon' => 'tickets-alt',
	'attributes' => array( 'event' => array( 'type' => 'integer', 'default' => 0 ) ),
	'supports' => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
	'style_handles' => array( 'wpcap-public' ), 'render_callback' => $z3_render,
);
$z3_alias = LegacyNames::register_block_alias( $z3_block );
$z3_args = $GLOBALS['wpcap_test_block_types']['wp-pass-captain/events'] ?? array();
check( 'A303 the former name wp-pass-captain/events is registered as alias', is_object( $z3_alias ) && 'wp-pass-captain/events' === $z3_alias->name );
check( 'A303 alias keeps attributes, alignment and styles', $z3_block->attributes === ( $z3_args['attributes'] ?? null ) && array( 'wide', 'full' ) === ( $z3_args['supports']['align'] ?? null ) && array( 'wpcap-public' ) === ( $z3_args['style_handles'] ?? null ) );
check( 'A303 alias is hidden in the inserter', false === ( $z3_args['supports']['inserter'] ?? null ) );
check( 'A303 alias renders through the same callback (stored content keeps working)', isset( $z3_args['render_callback'] ) && 'rendered:7' === call_user_func( $z3_args['render_callback'], array( 'event' => 7 ) ) );
check( 'A303 no alias for a block without a former name', false === LegacyNames::register_block_alias( (object) array( 'name' => 'pass-captain/service-booking' ) ) && false === LegacyNames::register_block_alias( false ) );
$z3_main = (string) file_get_contents( $z3_root . '/src/Plugin.php' ) . (string) file_get_contents( $z3_root . '/src/Modules/Calendar.php' );
check( 'A303 events and calendar registration register the alias', 2 === substr_count( $z3_main, 'LegacyNames::register_block_alias( $block )' ) );
check( 'A303 editor conversion script exists (assets/blocks/legacy-names.js)', is_file( $z3_root . '/assets/blocks/legacy-names.js' ) );

// Content detection with both names.
$z3_old = '<!-- wp:wp-pass-captain/events {"event":5} /-->';
$z3_new = '<!-- wp:pass-captain/events {"event":6} /-->';
check( 'A303 content_has_block() finds the current and the former name', LegacyNames::content_has_block( $z3_old, LegacyNames::EVENTS_BLOCK ) && LegacyNames::content_has_block( $z3_new, LegacyNames::EVENTS_BLOCK ) && ! LegacyNames::content_has_block( '<!-- wp:pass-captain/events-x /-->', LegacyNames::EVENTS_BLOCK ) );
$z3_a = PageCache::analyse( $z3_new );
$z3_b = PageCache::analyse( '<!-- wp:wp-pass-captain/calendar {"eventId":8} /-->' );
$z3_c = PageCache::analyse( '<!-- wp:pass-captain/calendar /-->' );
check( 'A303 page cache index knows the current block names', is_array( $z3_a ) && array( 6 ) === $z3_a['events'] && is_array( $z3_c ) && 1 === $z3_c['all'] );
check( 'A303 page cache index still knows the former block names', is_array( $z3_b ) && array( 8 ) === $z3_b['events'] && is_array( PageCache::analyse( $z3_old ) ) );
$z3_gs = (string) file_get_contents( $z3_root . '/src/Admin/GettingStarted.php' );
check( 'A303 setup checklist finds sales pages with the current block names', false !== strpos( $z3_gs, "'<!-- wp:pass-captain/events'" ) && false !== strpos( $z3_gs, "'<!-- wp:pass-captain/calendar'" ) );

// Shortcodes: [wpcap_*] documented, the former tags deprecated.
$GLOBALS['z3_deprecated'] = array();
check( 'A303 a current shortcode is not reported', false === LegacyNames::shortcode_used( 'wpcap_events' ) && array() === $GLOBALS['z3_deprecated'] );
check( 'A303 a former shortcode is reported as deprecated with its replacement', true === LegacyNames::shortcode_used( 'wp_pass_captain_calendar' ) && ( ! $z3_own_stub || array( array( '[wp_pass_captain_calendar]', '1.5.0', '[wpcap_calendar]' ) ) === $GLOBALS['z3_deprecated'] ) );
check( 'A303 content_has_shortcode() finds the current and the former tag', LegacyNames::content_has_shortcode( 'x [wp_pass_captain_events] y', 'wpcap_events' ) && LegacyNames::content_has_shortcode( 'x [wpcap_events event="1"]', 'wpcap_events' ) );
$z3_sources = (string) file_get_contents( $z3_root . '/src/PublicEvents.php' ) . (string) file_get_contents( $z3_root . '/src/Modules/Calendar.php' );
check( 'A303 events, scanner and calendar shortcodes report a former tag', 3 === substr_count( $z3_sources, 'LegacyNames::shortcode_used( $tag )' ) );
$z3_ui = (string) file_get_contents( $z3_root . '/src/Admin/Dashboard.php' ) . $z3_sources;
check( 'A303 admin texts name the current shortcodes', false === strpos( $z3_ui, "'Shortcode: [wp_pass_captain_calendar" ) && false === strpos( $z3_ui, 'den Shortcode [wp_pass_captain_events]' ) );
