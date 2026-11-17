<?php
// phpcs:ignoreFile
/**
 * A397: [wpcap_passes] loads the front-end stylesheet, links each pass to the ticket page of its
 * anchor date and uses the heading level of the event cards (filterable, heading="…").
 */
use PassCaptain\Passes\Definitions;
use PassCaptain\Passes\Display;
use PassCaptain\Settings;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A397: pass listing\n";
global $wpdb;
$p = $wpdb->prefix;
$a397_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A397 Festival', 'slug' => 'a397-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a397_now, 'updated_at' => $a397_now ) );
$a397_event = (int) $wpdb->insert_id;
$a397_occ = array();
foreach ( array( 20, 21 ) as $a397_d ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a397_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $a397_d * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wiese', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a397_occ[] = (int) $wpdb->insert_id;
}
$a397_pass = Definitions::create( array( 'occurrence_id' => $a397_occ[0], 'name' => 'A397 Pass', 'price_minor' => 4900, 'occurrence_ids' => $a397_occ ) );
check( 'A397 fixture: pass created', ! is_wp_error( $a397_pass ) && (int) $a397_pass > 0 );
$a397_calendar = Settings::get( 'calendar' );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/tickets/', 'attach_ics' => 1 ) ) );
$a397_html = Display::shortcode( array() );
$a397_card = preg_match( '#<article class="wpcap-pass">(?:(?!</article>).)*A397 Pass.*?</article>#s', $a397_html, $a397_m ) ? $a397_m[0] : '';
check( 'A397 the pass heading has the level of the event cards (h2)', false !== strpos( $a397_card, '<h2 class="wpcap-pass-title">A397 Pass</h2>' ) );
check( 'A397 the pass links to the ticket page of its anchor date', false !== strpos( $a397_card, 'class="wp-element-button wpcap-pass-buy" href="https://example.test/tickets/?wpcap_occurrence=' . $a397_occ[0] . '#wpcap-occ-' . $a397_occ[0] . '">„A397 Pass“ kaufen</a>' ) );
check( 'A397 heading="3" and the filter wpcap_passes_heading_level change the level', false !== strpos( Display::shortcode( array( 'heading' => '3' ) ), '<h3 class="wpcap-pass-title">A397 Pass</h3>' ) && ( function () {
	$GLOBALS['wpcap_test_filters']['wpcap_passes_heading_level'] = 4;
	$html = Display::shortcode( array() );
	unset( $GLOBALS['wpcap_test_filters']['wpcap_passes_heading_level'] );
	return false !== strpos( $html, '<h4 class="wpcap-pass-title">A397 Pass</h4>' );
} )() );
Settings::update( array( 'calendar' => is_array( $a397_calendar ) ? $a397_calendar : array( 'checkout_url' => '', 'attach_ics' => 1 ) ) );
check( 'A397 without a ticket page there is no dead link', false === strpos( Display::shortcode( array() ), 'wpcap-pass-buy' ) || '' !== (string) ( $a397_calendar['checkout_url'] ?? '' ) );
$a397_src = (string) file_get_contents( WPCAP_PATH . 'src/Passes/Display.php' );
$a397_css = (string) file_get_contents( WPCAP_PATH . 'assets/public.css' );
check( 'A397 the shortcode enqueues public.css, which styles the pass cards', false !== strpos( $a397_src, "wp_enqueue_style( 'wpcap-public' );" ) && false !== strpos( $a397_css, '.wpcap-pass{border:1px solid #dcdcde' ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'status' => 'inactive' ), array( 'ticket_type_id' => (int) $a397_pass ) );
