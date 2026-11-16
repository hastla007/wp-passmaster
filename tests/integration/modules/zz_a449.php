<?php
/**
 * A449: the blocks wp-pass-captain/events and wp-pass-captain/calendar put the block wrapper
 * attributes (block class wp-block-…, custom CSS class, alignment, spacing) on the root element of
 * the website output, declare these supports in block.json and keep their stored names
 * (docs/RENAME.md). The shortcodes keep their plain root classes. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Calendar;
use PassCaptain\PublicEvents;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
// Stand-in of the core function (whichever test file defines it first): block class, alignment, extra class.
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) { function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-pass-captain-service-booking alignwide ' . esc_attr( $extra['class'] ?? '' ) . '"'; } }

/** Output of a block's render.php for $attributes (as WP_Block::render() includes it). */
function a449_render( $block, array $attributes ) {
	ob_start();
	include WPCAP_PATH . 'src/blocks/' . $block . '/render.php';
	return (string) ob_get_clean();
}

global $wpdb;
$p = $wpdb->prefix;

echo "A449: block wrapper attributes on the website\n";
$a449_type = make_type( 10, 10, 1500 );
$a449_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a449_type" );
$a449_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a449_occ" );

$a449_html = a449_render( 'events', array( 'event' => $a449_event, 'occurrence' => 0, 'className' => 'is-style-x' ) );
$a449_wrap = get_block_wrapper_attributes( array( 'class' => 'wpcap-events' ) );
check( 'events block: root element carries get_block_wrapper_attributes() incl. the wpcap-events class', 0 === strpos( ltrim( $a449_html ), '<div ' . $a449_wrap . '>' ) );
check( 'events block: still renders the selected event card', false !== strpos( $a449_html, 'wpcap-checkout-form' ) && 1 === preg_match_all( '/<article [^>]*class="wpcap-event"/', $a449_html ) );
$a449_sc = PublicEvents::render_shortcode( array( 'event' => $a449_event ) );
check( 'events shortcode: plain root class without block wrapper', 0 === strpos( ltrim( $a449_sc ), '<div class="wpcap-events">' ) );

$a449_list = a449_render( 'calendar', array( 'view' => 'list', 'eventId' => $a449_event ) );
check( 'calendar block (list): root element carries the wrapper attributes', 0 === strpos( $a449_list, '<div ' . get_block_wrapper_attributes( array( 'class' => 'wpcap-calendar wpcap-calendar-list' ) ) . '>' ) && false !== strpos( $a449_list, 'wpcap-occ-' . $a449_occ ) );
$a449_month = a449_render( 'calendar', array( 'view' => 'month', 'eventId' => $a449_event ) );
check( 'calendar block (month): root element carries the wrapper attributes', 0 === strpos( $a449_month, '<div ' . get_block_wrapper_attributes( array( 'class' => 'wpcap-calendar wpcap-calendar-month' ) ) . '>' ) && false !== strpos( $a449_month, '<table class="wpcap-cal-grid">' ) );
check( 'calendar block: empty list keeps the wrapper', 0 === strpos( a449_render( 'calendar', array( 'view' => 'list', 'eventId' => 99999999 ) ), '<div ' . get_block_wrapper_attributes( array( 'class' => 'wpcap-calendar wpcap-calendar-list' ) ) . '><p>' ) );
check( 'calendar shortcode: plain root classes', 0 === strpos( Calendar::render_shortcode( array( 'view' => 'list', 'event' => $a449_event ) ), '<div class="wpcap-calendar wpcap-calendar-list">' ) );

echo "A449: supports in block.json\n";
foreach ( array( 'events' => 'wp-pass-captain/events', 'calendar' => 'wp-pass-captain/calendar' ) as $a449_dir => $a449_name ) {
	$a449_json = json_decode( (string) file_get_contents( WPCAP_PATH . 'src/blocks/' . $a449_dir . '/block.json' ), true );
	$a449_s = $a449_json['supports'] ?? array();
	check( "$a449_name: stored block name unchanged (docs/RENAME.md)", $a449_name === ( $a449_json['name'] ?? '' ) );
	check( "$a449_name: supports custom class, alignment and spacing, no HTML editing", false === ( $a449_s['html'] ?? null ) && false !== ( $a449_s['className'] ?? true ) && false !== ( $a449_s['customClassName'] ?? true ) && array( 'wide', 'full' ) === ( $a449_s['align'] ?? null ) && ! empty( $a449_s['spacing']['margin'] ) && ! empty( $a449_s['spacing']['padding'] ) );
	check( "$a449_name: render.php uses the block render callback", false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/blocks/' . $a449_dir . '/render.php' ), '::render_block( (array) $attributes )' ) );
}
