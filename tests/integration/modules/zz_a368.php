<?php
/**
 * A368: the web scanner's entrance areas are only loaded on pages that enqueue the scanner script,
 * not on every front-end page a check-in user opens; the footer hook covers classic themes.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Admission\Rest as AdmissionRest;

echo "A368: entrance areas only for pages with the scanner\n";

// Same default as zz_phase1_privacy.php (wpcap-scanner counts as registered/enqueued), overridable here.
if ( ! function_exists( 'wp_script_is' ) ) { function wp_script_is( $h, $l = 'enqueued' ) { return $GLOBALS['x2_a368_scripts'][ $h ][ $l ] ?? ( 'wpcap-scanner' === $h ); } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( ...$a ) { return true; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }

$x2_a368_area_queries = static function ( callable $run ) {
	$GLOBALS['wpcap_test_query_log'] = array();
	$run();
	$n = count( array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $q ) => false !== strpos( $q, 'wpcap_admission_areas' ) ) );
	unset( $GLOBALS['wpcap_test_query_log'] );
	return $n;
};
$x2_a368_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 1;
$x2_a368_scanner_enqueued = function_exists( 'wp_script_is' ) && ( new ReflectionFunction( 'wp_script_is' ) )->getFileName() === __FILE__;

AdmissionRest::reset_scanner_localized();
$GLOBALS['x2_a368_scripts']['wpcap-scanner']['enqueued'] = false;
$x2_a368_plain = $x2_a368_area_queries( static function () { AdmissionRest::localize_scanner(); } );
check( 'A368: a front-end page without the scanner does not query entrance areas', 0 === $x2_a368_plain );

if ( $x2_a368_scanner_enqueued ) {
	// Classic theme: the shortcode enqueues the script after wp_enqueue_scripts; the footer hook adds the data once.
	$GLOBALS['x2_a368_scripts']['wpcap-scanner']['enqueued'] = true;
	$x2_a368_footer = $x2_a368_area_queries( static function () { AdmissionRest::localize_scanner(); AdmissionRest::localize_scanner(); } );
	check( 'A368: with the scanner enqueued the areas are loaded exactly once', 1 === $x2_a368_footer );
}
$x2_a368_src = file_get_contents( WPCAP_PATH . 'src/Modules/Admission.php' );
check( 'A368: localize_scanner also runs before the footer scripts are printed', false !== strpos( $x2_a368_src, "add_action( 'wp_print_footer_scripts', array( Rest::class, 'localize_scanner' ), 1 )" ) );

unset( $GLOBALS['x2_a368_scripts'] );
AdmissionRest::reset_scanner_localized();
if ( null === $x2_a368_user ) { unset( $GLOBALS['wpcap_test_user_id'] ); } else { $GLOBALS['wpcap_test_user_id'] = $x2_a368_user; }
