<?php
// phpcs:ignoreFile
/**
 * A504 (extends A308): no duplicate ids when a date appears in several listings of one page
 * (shortcode plus sidebar widget, archive, the_content twice): the card anchor #wpcap-occ-{id}, the
 * empty date anchors of grouped cards and presale cards share one registry per page
 * (EventCard::claim_anchor()), and a second seat picker of the same date gets its own id.
 * checkout.js adds a missing anchor in the browser (tests/js/checkout-x3.test.js).
 */
use PassCaptain\Frontend\EventCard;
use PassCaptain\Modules\Calendar;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Seating\AdminPage as A504SeatingAdmin;
use PassCaptain\Seating\Picker as A504Picker;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }

echo "A504: unique anchors and ids across listings\n";
global $wpdb;
$p = $wpdb->prefix;
$a504_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A504 Reihe', 'slug' => 'a504-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a504_now, 'updated_at' => $a504_now ) );
$a504_event = (int) $wpdb->insert_id;
$a504_occ = array();
foreach ( array( 30, 31 ) as $a504_d ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a504_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $a504_d * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a504_occ[] = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->insert_id, 'name' => 'Standard', 'price_minor' => 1200, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
}
// A third date on presale only.
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a504_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 40 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
$a504_presale = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a504_presale, 'name' => 'Standard', 'price_minor' => 1200, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active', 'sales_start' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
Orders::flush_availability_cache();
EventCard::reset_form_ids();
add_filter( 'wpcap_event_card_html', array( Calendar::class, 'add_card_anchor' ), 5, 3 );
// Main listing (one card per event, other dates as anchors), a sidebar with one card per date, the presale date twice.
$a504_html = PublicEvents::render_shortcode( array( 'event' => $a504_event ) )
	. PublicEvents::render_shortcode( array( 'event' => $a504_event, 'dates' => 'each' ) )
	. PublicEvents::render_shortcode( array( 'occurrence' => $a504_occ[1] ) )
	. PublicEvents::render_shortcode( array( 'occurrence' => $a504_presale ) )
	. PublicEvents::render_shortcode( array( 'occurrence' => $a504_presale ) );
remove_filter( 'wpcap_event_card_html', array( Calendar::class, 'add_card_anchor' ), 5 );
preg_match_all( '/\sid="([^"]+)"/', $a504_html, $a504_ids );
$a504_dupes = array_keys( array_filter( array_count_values( $a504_ids[1] ), static function ( $n ) { return $n > 1; } ) );
check( 'A504 no id occurs twice across the three listings' . ( $a504_dupes ? ' (' . implode( ', ', array_slice( $a504_dupes, 0, 5 ) ) . ')' : '' ), count( $a504_ids[1] ) > 20 && ! $a504_dupes );
foreach ( array_merge( $a504_occ, array( $a504_presale ) ) as $a504_one ) {
	check( "A504 the anchor of date $a504_one exists exactly once", 1 === substr_count( $a504_html, 'id="wpcap-occ-' . $a504_one . '"' ) );
}
check( 'A504 presale cards carry their date for the browser fallback', 3 <= substr_count( $a504_html, 'wpcap-event-presale' ) && false !== strpos( $a504_html, 'data-occurrence="' . $a504_presale . '"><header' ) );
EventCard::reset_form_ids();
check( 'A504 a new page starts with free anchors', EventCard::claim_anchor( 'wpcap-occ-' . $a504_occ[0] ) && ! EventCard::claim_anchor( 'wpcap-occ-' . $a504_occ[0] ) );

// Seat picker twice on one page.
$a504_layout = A504SeatingAdmin::create( array( 'event_id' => $a504_event, 'name' => 'A504 Saal' ) );
$a504_type = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $a504_occ[0] ) );
$a504_pub = A504SeatingAdmin::save( array( 'layout_id' => $a504_layout['layout_id'] ?? 0, 'draft' => json_encode( array( 'width' => 200, 'height' => 100, 'zones' => array( array( 'key' => 'p', 'name' => 'Parkett', 'color' => '#2271b1' ) ), 'categories' => array( array( 'key' => 'k', 'name' => 'Kat', 'color' => '#00a32a' ) ), 'groups' => array( array( 'key' => 'g', 'type' => 'row', 'label' => '' ) ), 'seats' => array( array( 'key' => 's1', 'group' => 'g', 'row' => 'A', 'label' => '1', 'x' => 20, 'y' => 20, 'zone' => 'p', 'category' => 'k' ) ) ) ), 'do' => 'publish' ) );
A504SeatingAdmin::link( array( 'occurrence_id' => $a504_occ[0], 'version_id' => $a504_pub['version_id'] ?? 0, 'zones' => array( $a504_type => array( 'p' ) ) ) );
ob_start();
A504Picker::render( (object) array( 'occurrence_id' => $a504_occ[0] ) );
A504Picker::render( (object) array( 'occurrence_id' => $a504_occ[0] ) );
$a504_pickers = (string) ob_get_clean();
check( 'A504 the second seat picker of a date has its own id', false !== strpos( $a504_pickers, 'id="wpcap-seats-' . $a504_occ[0] . '"' ) && false !== strpos( $a504_pickers, 'id="wpcap-seats-' . $a504_occ[0] . '-i2"' ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='a504_done' WHERE event_id=$a504_event" );
