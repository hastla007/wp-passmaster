<?php
// phpcs:ignoreFile
/**
 * A391: the stylesheets are registered before the blocks and named in block.json, so WordPress
 * loads them in the <head> and in the editor canvas (styled preview); a post with one of the
 * shortcodes gets its stylesheet on wp_enqueue_scripts, not only when the shortcode renders.
 * The style API runs stubbed in a child process.
 */

echo "A391: stylesheets in the head and in the editor\n";
$a391_raw = (string) shell_exec( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a391/child.php' ) . ' 2>&1' );
$a391 = json_decode( trim( $a391_raw ), true );
check( 'A391 child process ran' . ( is_array( $a391 ) ? '' : ' – ' . substr( $a391_raw, 0, 300 ) ), is_array( $a391 ) );
$a391 = is_array( $a391 ) ? $a391 : array();
check( 'A391 register_styles() registers wpcap-public and wpcap-calendar', isset( $a391['registered']['wpcap-public'], $a391['registered']['wpcap-calendar'] ) && false !== strpos( (string) $a391['registered']['wpcap-calendar'], 'assets/calendar/calendar.css' ) );
check( 'A391 a page with calendar and events shortcodes gets both stylesheets in the head', array( 'wpcap-calendar', 'wpcap-public' ) == ( function ( $v ) { sort( $v ); return $v; } )( (array) ( $a391['page'] ?? array() ) ) );
check( 'A391 no stylesheet without a plugin shortcode, [wpcap_passes] gets public.css', array() === ( $a391['plain'] ?? null ) && array( 'wpcap-public' ) === ( $a391['passes'] ?? null ) );
check( 'A391 archives and other non-singular views keep the enqueue at render time', array() === ( $a391['archive'] ?? null ) );

$a391_json = static function ( $dir ) { return json_decode( (string) file_get_contents( WPCAP_PATH . 'src/blocks/' . $dir . '/block.json' ), true ); };
check( 'A391 the events block names wpcap-public, the calendar block wpcap-calendar as "style"', 'wpcap-public' === ( $a391_json( 'events' )['style'] ?? '' ) && 'wpcap-calendar' === ( $a391_json( 'calendar' )['style'] ?? '' ) );
check( 'A391 the service booking preview is styled in the editor (editorStyle)', 'wpcap-public' === ( $a391_json( 'service-booking' )['editorStyle'] ?? '' ) );
$a391_plugin = (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' );
check( 'A391 the styles are registered on init before the blocks', false !== strpos( $a391_plugin, "add_action( 'init', array( PublicEvents::class, 'register_styles' ), 5 );" ) && false !== strpos( $a391_plugin, "add_action( 'wp_enqueue_scripts', array( PublicEvents::class, 'enqueue_shortcode_styles' ), 20 );" ) );
