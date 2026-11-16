<?php
/**
 * Worker for tests/integration/modules/zz_a499.php: renders the events shortcode and the calendar
 * as a feed (`feed`), a REST response (`rest`) or the editor's block preview (`preview`).
 * Usage: child.php <mode> <event_id>. Prints one JSON object.
 */
// phpcs:ignoreFile
$mode = $argv[1] ?? 'feed';
if ( 'rest' === $mode || 'preview' === $mode ) { define( 'REST_REQUEST', true ); }
if ( 'preview' === $mode ) { $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/block-renderer/wp-pass-captain/events?context=edit'; }
$GLOBALS['mode'] = $mode;
$GLOBALS['a499_scripts'] = array();
require dirname( __DIR__, 2 ) . '/bootstrap.php';
function is_feed() { return 'feed' === $GLOBALS['mode']; }
function get_permalink() { return 'https://example.test/programm/'; }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) { $GLOBALS['a499_scripts'][] = $a[0]; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
$GLOBALS['wpcap_test_options']['wpcap_settings'] = array( 'enabled_providers' => array( 'manual' ), 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Testverein' );
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/feed/';
$event = (int) ( $argv[2] ?? 0 );
$out = array(
	'events' => PassCaptain\PublicEvents::render_shortcode( array( 'event' => $event ), '', 'wpcap_events' ),
	'calendar' => PassCaptain\Modules\Calendar::render_shortcode( array( 'view' => 'month', 'event' => $event ), '', 'wpcap_calendar' ),
	'scripts' => $GLOBALS['a499_scripts'],
);
echo json_encode( $out );
