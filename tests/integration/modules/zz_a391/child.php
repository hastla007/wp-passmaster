<?php
/**
 * Worker for tests/integration/modules/zz_a391.php, in its own process: the style API and the
 * post functions are stubbed here. Prints one JSON object.
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
$GLOBALS['a391'] = array( 'styles' => array(), 'enqueued' => array() );
function wp_style_is( $h, $l = 'enqueued' ) { return 'registered' === $l ? isset( $GLOBALS['a391']['styles'][ $h ] ) : in_array( $h, $GLOBALS['a391']['enqueued'], true ); }
function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['a391']['styles'][ $h ] = $src; return true; }
function wp_enqueue_style( $h, $src = '' ) { $GLOBALS['a391']['enqueued'][] = $h; }
function is_singular() { return ! empty( $GLOBALS['a391']['singular'] ); }
function get_post() { return (object) array( 'post_content' => $GLOBALS['a391']['content'] ?? '' ); }
function has_shortcode( $content, $tag ) { return (bool) preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]]/', $content ); }

$out = array();
PassCaptain\PublicEvents::register_styles();
$out['registered'] = $GLOBALS['a391']['styles'];
$GLOBALS['a391']['singular'] = true;
$GLOBALS['a391']['content'] = "Text\n[wpcap_calendar view=\"list\"]\n[wp_pass_captain_events event=\"3\"]";
$out['page'] = PassCaptain\PublicEvents::enqueue_shortcode_styles();
$GLOBALS['a391']['enqueued'] = array();
$GLOBALS['a391']['content'] = 'Nur Text ohne Shortcode [gallery]';
$out['plain'] = PassCaptain\PublicEvents::enqueue_shortcode_styles();
$GLOBALS['a391']['content'] = '[wpcap_passes]';
$out['passes'] = PassCaptain\PublicEvents::enqueue_shortcode_styles();
$GLOBALS['a391']['enqueued'] = array();
$GLOBALS['a391']['singular'] = false;
$GLOBALS['a391']['content'] = '[wpcap_events]';
$out['archive'] = PassCaptain\PublicEvents::enqueue_shortcode_styles();
echo json_encode( $out );
