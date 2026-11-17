<?php
/**
 * Worker for tests/integration/modules/zz_a503.php: shortcode_atts() as in WordPress (with the
 * shortcode_atts_{$shortcode} filter), defined before the bootstrap's stub. Prints one JSON object.
 */
// phpcs:ignoreFile
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default; }
	if ( $shortcode ) { $out = apply_filters( "shortcode_atts_{$shortcode}", $out, $pairs, $atts, $shortcode ); }
	return $out;
}
require dirname( __DIR__, 2 ) . '/bootstrap.php';

$seen = array();
foreach ( array( 'wpcap_events', 'wp_pass_captain_events', 'wpcap_calendar', 'wp_pass_captain_calendar' ) as $tag ) {
	add_filter( 'shortcode_atts_' . $tag, static function ( $out ) use ( $tag, &$seen ) { $seen[] = $tag; return $out; } );
}
$pairs = array( 'event' => 0 );
PassCaptain\PublicEvents::shortcode_atts( $pairs, array( 'event' => '3' ), 'wpcap_events', 'wp_pass_captain_events' );
$out = array( 'short' => $seen );
$seen = array();
PassCaptain\PublicEvents::shortcode_atts( $pairs, array(), 'wp_pass_captain_events', 'wp_pass_captain_events' );
$out['long'] = $seen;
$seen = array();
PassCaptain\PublicEvents::shortcode_atts( $pairs, array(), '', 'wp_pass_captain_calendar' );
$out['none'] = $seen;
$seen = array();
PassCaptain\PublicEvents::shortcode_atts( $pairs, array(), 'wpcap_calendar', 'wp_pass_captain_calendar' );
$out['calendar'] = $seen;
echo json_encode( $out );
