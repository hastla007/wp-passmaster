<?php
/**
 * A455: block validation and render tests for all blocks under src/blocks/ (events, calendar,
 * service booking): block.json contract (apiVersion 3 for the iframe editor, attribute types and
 * defaults, existing render/editor files, registration in PHP), stored block names (docs/RENAME.md),
 * and rendering outside a post (widget areas, template parts: no global post, no request URL,
 * empty attributes). Editor-side tests: tests/js/blocks.test.js. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) { function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-pass-captain-service-booking alignwide ' . esc_attr( $extra['class'] ?? '' ) . '"'; } }

/** Output of a block's render.php for $attributes. */
function a455_render( $dir, array $attributes ) {
	ob_start();
	include WPCAP_PATH . 'src/blocks/' . $dir . '/render.php';
	return (string) ob_get_clean();
}

echo "A455: block.json validation\n";
$a455_names = array( 'events' => 'pass-captain/events', 'calendar' => 'pass-captain/calendar', 'service-booking' => 'pass-captain/service-booking' );
$a455_dirs = array_map( 'basename', (array) glob( WPCAP_PATH . 'src/blocks/*', GLOB_ONLYDIR ) );
sort( $a455_dirs );
$a455_known = array_keys( $a455_names );
sort( $a455_known );
check( 'every block directory is covered by this test', $a455_known === $a455_dirs );
$a455_sources = '';
foreach ( (array) glob( WPCAP_PATH . 'src/{,*/,*/*/}*.php', GLOB_BRACE ) as $a455_file ) { $a455_sources .= (string) file_get_contents( $a455_file ); }
$a455_types = array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' );
foreach ( $a455_names as $a455_dir => $a455_name ) {
	$a455_path = WPCAP_PATH . 'src/blocks/' . $a455_dir . '/';
	$a455_json = json_decode( (string) file_get_contents( $a455_path . 'block.json' ), true );
	check( "$a455_name: block.json is valid JSON with the stored name", is_array( $a455_json ) && $a455_name === ( $a455_json['name'] ?? '' ) );
	if ( ! is_array( $a455_json ) ) { continue; }
	check( "$a455_name: apiVersion 3 (the editor is only iframed when all blocks are v3)", 3 === ( $a455_json['apiVersion'] ?? 0 ) );
	check( "$a455_name: title, category, icon, textdomain pass-captain", '' !== (string) ( $a455_json['title'] ?? '' ) && '' !== (string) ( $a455_json['category'] ?? '' ) && '' !== (string) ( $a455_json['icon'] ?? '' ) && 'pass-captain' === ( $a455_json['textdomain'] ?? '' ) );
	check( "$a455_name: dynamic block (render file and editor script exist, asset file next to it)", 'file:./render.php' === ( $a455_json['render'] ?? '' ) && is_file( $a455_path . 'render.php' ) && 'file:./editor.js' === ( $a455_json['editorScript'] ?? '' ) && is_file( $a455_path . 'editor.js' ) && is_file( $a455_path . 'editor.asset.php' ) );
	check( "$a455_name: render.php has the ABSPATH guard", false !== strpos( (string) file_get_contents( $a455_path . 'render.php' ), "if ( ! defined( 'ABSPATH' ) ) { exit; }" ) );
	check( "$a455_name: no HTML editing of the server output", false === ( $a455_json['supports']['html'] ?? null ) );
	$a455_bad = array();
	foreach ( (array) ( $a455_json['attributes'] ?? array() ) as $a455_attr => $a455_def ) {
		$a455_type = $a455_def['type'] ?? '';
		$a455_default = $a455_def['default'] ?? null;
		$a455_ok = in_array( $a455_type, $a455_types, true );
		if ( $a455_ok && array_key_exists( 'default', $a455_def ) ) {
			$a455_ok = ( 'integer' === $a455_type && is_int( $a455_default ) ) || ( 'number' === $a455_type && ( is_int( $a455_default ) || is_float( $a455_default ) ) ) || ( 'string' === $a455_type && is_string( $a455_default ) ) || ( 'boolean' === $a455_type && is_bool( $a455_default ) ) || in_array( $a455_type, array( 'object', 'array', 'null' ), true );
		}
		if ( $a455_ok && isset( $a455_def['enum'] ) ) { $a455_ok = in_array( $a455_default, $a455_def['enum'], true ); }
		if ( ! $a455_ok ) { $a455_bad[] = $a455_attr; }
	}
	check( "$a455_name: attribute types valid, defaults match type and enum" . ( $a455_bad ? ' – ' . implode( ', ', $a455_bad ) : '' ), ! empty( $a455_json['attributes'] ) && ! $a455_bad );
	check( "$a455_name: registered from PHP via register_block_type( WPCAP_PATH . 'src/blocks/$a455_dir' )", false !== strpos( $a455_sources, "register_block_type( WPCAP_PATH . 'src/blocks/$a455_dir' )" ) );
}

echo "A455: rendering in widget areas and template parts\n";
global $wpdb;
$p = $wpdb->prefix;
$a455_saved_uri = $_SERVER['REQUEST_URI'] ?? null;
$a455_saved_post = $GLOBALS['post'] ?? null;
unset( $_SERVER['REQUEST_URI'], $GLOBALS['post'] );
$a455_type = make_type( 10, 10, 1200 );
$a455_events = a455_render( 'events', array() );
// Merged layout "auto": more than auto_list_threshold() cards (earlier test files leave events behind)
// switch to the compact list inside the same wrapper (class wpcap-events-list added), else event cards.
$a455_open = (string) strtok( ltrim( $a455_events ), '>' ) . '>';
$a455_wrapper = '<div ' . get_block_wrapper_attributes( array( 'class' => 'wpcap-events' ) ) . '>';
check( 'events block without attributes (widget): lists upcoming events inside the wrapper', ( $a455_wrapper === $a455_open || $a455_open === str_replace( 'class="', 'class="wpcap-events-list ', $a455_wrapper ) ) && 1 === preg_match( '/<article [^>]*class="wpcap-event(-summary)?[ "]/', $a455_events ) );
$a455_cal = a455_render( 'calendar', array() );
check( 'calendar block without attributes: month view with navigation, no global post needed', false !== strpos( $a455_cal, 'wpcap-calendar-month' ) && 1 === preg_match( '/<a href="[^"]*wpcap_month=\d{4}-\d{2}#wpcap-cal-nav-\d+" rel="next">/', $a455_cal ) );
check( 'calendar block with an unknown view falls back to the month view', false !== strpos( a455_render( 'calendar', array( 'view' => 'grid', 'eventId' => 0 ) ), '<table class="wpcap-cal-grid">' ) );
check( 'events block with a stale event ID: empty state, no error', false !== strpos( a455_render( 'events', array( 'event' => 99999999, 'occurrence' => 0 ) ), 'wpcap-events-empty' ) );
check( 'service booking block without an event: notice inside the wrapper', false !== strpos( a455_render( 'service-booking', array() ), 'wpcap-sb-notice' ) );
if ( null !== $a455_saved_uri ) { $_SERVER['REQUEST_URI'] = $a455_saved_uri; }
if ( null !== $a455_saved_post ) { $GLOBALS['post'] = $a455_saved_post; }
