<?php
// phpcs:ignoreFile
/**
 * A342: the event listing shows one card (one checkout form) per event; the other dates switch the
 * card with ?wpcap_occurrence=; calendar and .ics links carry that parameter, so they always find
 * a rendered card (also beyond the listing limit); dates="each" / perDate keeps one card per date.
 */
use PassCaptain\Frontend\EventCard;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }

echo "A342: one card per event, date choice, calendar links\n";
global $wpdb;
$p = $wpdb->prefix;
$a342_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A342 Lesung', 'slug' => 'a342-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a342_now, 'updated_at' => $a342_now ) );
$a342_event = (int) $wpdb->insert_id;
$a342_occ = array();
foreach ( array( 3, 10, 17 ) as $a342_days ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a342_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $a342_days * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a342_occ[] = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->insert_id, 'name' => 'Standard', 'price_minor' => 1500, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
}
$a342_saved_get = $_GET;
$a342_saved_uri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/programm/';
$a342_render = static function ( array $atts = array() ) use ( $a342_event ) {
	Orders::flush_availability_cache();
	EventCard::reset_form_ids();
	return PublicEvents::render_shortcode( $atts + array( 'event' => $a342_event ) );
};
add_filter( 'wpcap_event_card_html', array( PassCaptain\Modules\Calendar::class, 'add_card_anchor' ), 5, 3 );

$_GET = array();
$a342_html = $a342_render();
check( 'A342: one checkout form for an event with three dates', 1 === substr_count( $a342_html, '<form class="wpcap-checkout-form"' ) && false !== strpos( $a342_html, 'id="wpcap-' . $a342_occ[0] . '"' ) );
check( 'A342: the other dates link to the same page with ?wpcap_occurrence= and the card anchor', false !== strpos( $a342_html, 'href="/programm/?wpcap_occurrence=' . $a342_occ[1] . '#wpcap-occ-' . $a342_occ[1] . '"' ) && false !== strpos( $a342_html, 'href="/programm/?wpcap_occurrence=' . $a342_occ[2] . '#wpcap-occ-' . $a342_occ[2] . '"' ) );
check( 'A342: anchors of all listed dates exist on the page (old #wpcap-occ- links land on the card)', 1 === substr_count( $a342_html, 'id="wpcap-occ-' . $a342_occ[0] . '"' ) && 1 === substr_count( $a342_html, 'id="wpcap-occ-' . $a342_occ[1] . '"' ) && 1 === substr_count( $a342_html, 'id="wpcap-occ-' . $a342_occ[2] . '"' ) );

$_GET = array( 'wpcap_occurrence' => (string) $a342_occ[2] );
$a342_sel = $a342_render();
check( 'A342: ?wpcap_occurrence= selects the date shown on the card', 1 === substr_count( $a342_sel, '<form class="wpcap-checkout-form"' ) && false !== strpos( $a342_sel, 'data-occurrence="' . $a342_occ[2] . '"' ) && 1 === substr_count( $a342_sel, '<article id="wpcap-occ-' . $a342_occ[2] . '"' ) );
check( 'A342: the selected date is not offered again, the first one is', false === strpos( $a342_sel, 'wpcap_occurrence=' . $a342_occ[2] . '#' ) && false !== strpos( $a342_sel, 'wpcap_occurrence=' . $a342_occ[0] . '#' ) );
$a342_limited = $a342_render( array( 'limit' => 1 ) );
check( 'A342: a requested date beyond the listing limit still gets its card', false !== strpos( $a342_limited, 'data-occurrence="' . $a342_occ[2] . '"' ) );
$_GET = array( 'wpcap_occurrence' => '999999999' );
check( 'A342: an unknown requested date falls back to the first bookable date', false !== strpos( $a342_render(), 'data-occurrence="' . $a342_occ[0] . '"' ) );

$_GET = array();
$a342_each = $a342_render( array( 'dates' => 'each' ) );
check( 'A342: dates="each" keeps one card per date', 3 === substr_count( $a342_each, '<form class="wpcap-checkout-form"' ) && false !== strpos( $a342_each, 'href="#wpcap-occ-' . $a342_occ[1] . '"' ) && false === strpos( $a342_each, 'wpcap-occ-anchor' ) );
EventCard::reset_form_ids();
$a342_block = PublicEvents::render_block( array( 'event' => $a342_event, 'perDate' => true ) );
check( 'A342: block attribute perDate = one card per date', 3 === substr_count( $a342_block, '<form class="wpcap-checkout-form"' ) );
$a342_json = json_decode( (string) file_get_contents( WPCAP_PATH . 'src/blocks/events/block.json' ), true );
check( 'A342: block.json declares perDate (boolean, default false)', 'boolean' === ( $a342_json['attributes']['perDate']['type'] ?? '' ) && false === ( $a342_json['attributes']['perDate']['default'] ?? null ) );

// Calendar and .ics links carry the parameter.
$a342_cal = (array) Settings::get( 'calendar' );
Settings::update( array( 'calendar' => array( 'checkout_url' => 'https://example.test/karten/', 'attach_ics' => 1 ) ) );
check( 'A342: checkout link of calendar/.ics selects the date', 'https://example.test/karten/?wpcap_occurrence=' . $a342_occ[1] . '#wpcap-occ-' . $a342_occ[1] === PassCaptain\Calendar\Ics::checkout_url( $a342_occ[1] ) && false !== strpos( str_replace( "\r\n ", '', (string) PassCaptain\Calendar\Ics::occurrence_calendar( $a342_occ[1] ) ), 'URL:https://example.test/karten/?wpcap_occurrence=' . $a342_occ[1] . '#wpcap-occ-' . $a342_occ[1] ) );
Settings::update( array( 'calendar' => array( 'checkout_url' => '', 'attach_ics' => 1 ) ) );
$a342_list = PassCaptain\Modules\Calendar::render_list( $a342_event );
check( 'A342: calendar without a checkout page links the same page with the parameter', false !== strpos( $a342_list, 'href="/programm/?wpcap_occurrence=' . $a342_occ[2] . '#wpcap-occ-' . $a342_occ[2] . '"' ) );
Settings::update( array( 'calendar' => $a342_cal ) );

// Grouping keeps the order of the events' first dates.
$a342_rows = array( (object) array( 'event_id' => 2, 'occurrence_id' => 21 ), (object) array( 'event_id' => 1, 'occurrence_id' => 11 ), (object) array( 'event_id' => 2, 'occurrence_id' => 22 ), (object) array( 'event_id' => 1, 'occurrence_id' => 12 ) );
$a342_pick = PublicEvents::card_rows( $a342_rows, array( 22 => array( 1 ), 11 => array( 1 ), 12 => array( 1 ) ), array( 21 => '2030-01-01 00:00:00' ), 12 );
check( 'A342: per event the requested, else the first bookable date; event order kept', array( 22, 12 ) === array_map( static function ( $r ) { return $r->occurrence_id; }, $a342_pick ) );

remove_filter( 'wpcap_event_card_html', array( PassCaptain\Modules\Calendar::class, 'add_card_anchor' ), 5 );
$_GET = $a342_saved_get;
if ( null !== $a342_saved_uri ) { $_SERVER['REQUEST_URI'] = $a342_saved_uri; } else { unset( $_SERVER['REQUEST_URI'] ); }
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE event_id=$a342_event" );
EventCard::reset_form_ids();
