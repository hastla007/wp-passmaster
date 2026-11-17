<?php
/**
 * A308: two cards of the same occurrence on one page (second shortcode, widget, archive) get
 * their own id prefix: no duplicate ids, every label/aria-controls points into its own card, and
 * `wpcap_event_card_html` filters find the card's prefix (EventCard::form_id_in()).
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\EventCard;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A308: unique ids per rendered event card\n";
Settings::update( array( 'require_terms' => 1, 'terms_text' => 'AGB', 'consumer_info_text' => '' ) );
$a308_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A308 Konzert', 'slug' => 'a308-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a308_now, 'updated_at' => $a308_now ) );
$a308_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a308_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Saal', 'capacity' => 0, 'status' => 'scheduled' ) );
$a308_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a308_occ, 'name' => 'Standard', 'price_minor' => 2500, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
$a308_type = (int) $wpdb->insert_id;

Orders::flush_availability_cache();
// The calendar's card anchor (#wpcap-occ-{id}) goes on the first card only.
add_filter( 'wpcap_event_card_html', array( PassCaptain\Modules\Calendar::class, 'add_card_anchor' ), 5, 3 );
$a308_html = PublicEvents::render_shortcode( array( 'occurrence' => $a308_occ ) ) . PublicEvents::render_shortcode( array( 'occurrence' => $a308_occ ) );
remove_filter( 'wpcap_event_card_html', array( PassCaptain\Modules\Calendar::class, 'add_card_anchor' ), 5 );
check( 'A308 the calendar anchor exists once', 1 === substr_count( $a308_html, 'id="wpcap-occ-' . $a308_occ . '"' ) );
preg_match_all( '/\sid="([^"]+)"/', $a308_html, $a308_ids );
$a308_dupes = array_keys( array_filter( array_count_values( $a308_ids[1] ), static function ( $n ) { return $n > 1; } ) );
check( 'A308 two cards of one date: two checkout forms', 2 === substr_count( $a308_html, '<form class="wpcap-checkout-form"' ) );
check( 'A308 no id occurs twice on the page' . ( $a308_dupes ? ' (' . implode( ', ', array_slice( $a308_dupes, 0, 5 ) ) . ')' : '' ), count( $a308_ids[1] ) > 10 && ! $a308_dupes );
$a308_cards = explode( '<article', $a308_html );
array_shift( $a308_cards );
check( 'A308 the first card keeps wpcap-{occurrence}, the second gets wpcap-{occurrence}-i2', 'wpcap-' . $a308_occ === EventCard::form_id_in( $a308_cards[0] ) && 'wpcap-' . $a308_occ . '-i2' === EventCard::form_id_in( $a308_cards[1] ) );
// Every reference (label for, aria-controls, aria-describedby) resolves inside its own card.
$a308_dangling = array();
foreach ( $a308_cards as $a308_i => $a308_card ) {
	preg_match_all( '/\sid="([^"]+)"/', $a308_card, $a308_own );
	preg_match_all( '/\s(?:for|aria-controls|aria-describedby)="([^"]+)"/', $a308_card, $a308_refs );
	foreach ( $a308_refs[1] as $a308_ref ) {
		foreach ( preg_split( '/\s+/', $a308_ref ) as $a308_one ) {
			if ( ! in_array( $a308_one, $a308_own[1], true ) ) { $a308_dangling[] = $a308_i . ':' . $a308_one; }
		}
	}
}
check( 'A308 labels, billing toggle and hints point into their own card' . ( $a308_dangling ? ' (' . implode( ', ', array_slice( $a308_dangling, 0, 5 ) ) . ')' : '' ), ! $a308_dangling );
check( 'A308 the second card\'s billing toggle controls its own fields', false !== strpos( $a308_cards[1], 'aria-controls="wpcap-' . $a308_occ . '-i2-billing-fields"' ) && false !== strpos( $a308_cards[1], 'id="wpcap-' . $a308_occ . '-i2-billing-fields"' ) );
check( 'A308 the quantity field of the second card has its own id', false !== strpos( $a308_cards[1], 'id="wpcap-' . $a308_occ . '-i2-' . $a308_type . '"' ) );
check( 'A308 the form carries its occurrence for the checkout script', false !== strpos( $a308_cards[1], 'data-occurrence="' . $a308_occ . '"' ) );
check( 'A308 form_id_in() is empty without a checkout form', '' === EventCard::form_id_in( '<article class="wpcap-event"></article>' ) );

Settings::update( array( 'require_terms' => 0, 'terms_text' => '' ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a308_done' ), array( 'occurrence_id' => $a308_occ ) );
