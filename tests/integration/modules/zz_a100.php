<?php
/**
 * A100: compact event card (title, next date, location, price from, availability; long date and
 * ticket lists collapsible without JS), quantity stepper (progressive enhancement, limits, live
 * region), occurrence badges in card and calendar (Ausverkauft, Wenige Plätze, Abgesagt, Verschoben,
 * Einlass ab …; thresholds filterable) and the highlighted next bookable date.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\EventCard;
use PassCaptain\Modules\Calendar;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }

echo "A100: event card, stepper, badges, next date\n";
Settings::update( array( 'require_terms' => 0, 'terms_text' => '', 'consumer_info_text' => '' ) );
// Only this test's dates are listed.
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='a100_hs' WHERE status='scheduled' AND starts_at >= UTC_TIMESTAMP()" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='a100_hc' WHERE status='cancelled' AND starts_at >= UTC_TIMESTAMP()" );

$a100_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A100 Festival', 'slug' => 'a100-' . uniqid(), 'description' => 'Text', 'status' => 'published', 'created_at' => $a100_now, 'updated_at' => $a100_now ) );
$a100_event = (int) $wpdb->insert_id;
$a100_occ = static function ( $days, $status = 'scheduled', $doors = null ) use ( $wpdb, $p, $a100_event ) {
	$starts = gmdate( 'Y-m-d H:i:s', strtotime( gmdate( 'Y-m-d 18:00:00' ) . ' UTC' ) + $days * DAY_IN_SECONDS );
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a100_event, 'starts_at' => $starts, 'doors_open_at' => $doors ? gmdate( 'Y-m-d H:i:s', strtotime( $starts . ' UTC' ) - $doors * 60 ) : null, 'timezone' => 'Europe/Vienna', 'location' => 'Arena', 'capacity' => 0, 'status' => $status ) );
	return (int) $wpdb->insert_id;
};
$a100_type = static function ( $occ, $name, $capacity, $price ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
};
// A: sold out (earliest), B: next bookable with six types and doors time, C: three seats left and
// rescheduled, D: cancelled.
$occ_a = $a100_occ( 3 );
$type_a = $a100_type( $occ_a, 'Früh', 1, 1000 );
$occ_b = $a100_occ( 4, 'scheduled', 60 );
$types_b = array();
foreach ( array( 'Stehplatz' => 2900, 'Sitzplatz' => 3900, 'Ermäßigt' => 900, 'Loge' => 9900, 'Familie' => 7900, 'Backstage' => 14900 ) as $a100_name => $a100_price ) {
	$types_b[] = $a100_type( $occ_b, $a100_name, 0, $a100_price );
}
$occ_c = $a100_occ( 5 );
$type_c = $a100_type( $occ_c, 'Standard', 3, 2500 );
$occ_d = $a100_occ( 6, 'cancelled' );
$a100_type( $occ_d, 'Standard', 0, 2500 );
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'occurrence_rescheduled', 'object_type' => 'occurrence', 'object_id' => $occ_c, 'details' => '{}', 'created_at' => $a100_now ) );
$a100_hold = Orders::create( array( 'email' => 'a100@example.com', 'items' => array( array( 'ticket_type_id' => $type_a, 'quantity' => 1 ) ), 'idempotency_key' => 'a100-' . uniqid() ) );
check( 'A100 setup: the only ticket of date A is held', ! is_wp_error( $a100_hold ) );

$a100_render = static function ( array $atts = array() ) use ( $a100_event ) {
	Orders::flush_availability_cache();
	return PublicEvents::render_shortcode( $atts + array( 'event' => $a100_event ) );
};
$a100_card = static function ( $html, $occ ) {
	foreach ( explode( '<article', $html ) as $chunk ) {
		if ( false !== strpos( $chunk, 'id="wpcap-' . $occ . '"' ) ) { return '<article' . $chunk; }
	}
	return '';
};
$html = $a100_render();
$card_a = $a100_card( $html, $occ_a );
$card_b = $a100_card( $html, $occ_b );
$card_c = $a100_card( $html, $occ_c );
check( 'A100 cancelled date gets no card, the three bookable dates do', '' !== $card_a && '' !== $card_b && '' !== $card_c && '' === $a100_card( $html, $occ_d ) );

// 1. Compact card hierarchy.
$pos = static function ( $hay, $needle ) { $r = strpos( $hay, $needle ); return false === $r ? PHP_INT_MAX : $r; };
check( 'A100 card hierarchy: title, date, location, price from, availability – before the form', $pos( $card_b, 'class="wpcap-event-title">A100 Festival<' ) < $pos( $card_b, 'wpcap-fact-date' ) && $pos( $card_b, 'wpcap-fact-date' ) < $pos( $card_b, 'wpcap-fact-location' ) && $pos( $card_b, 'wpcap-fact-location' ) < $pos( $card_b, 'wpcap-fact-price' ) && $pos( $card_b, 'wpcap-fact-price' ) < $pos( $card_b, 'wpcap-fact-availability' ) && $pos( $card_b, 'wpcap-fact-availability' ) < $pos( $card_b, '<form' ) );
check( 'A100 facts are a labelled description list with a machine-readable date', false !== strpos( $card_b, '<dl class="wpcap-event-facts">' ) && false !== strpos( $card_b, '<dt class="wpcap-sr-only">Termin</dt>' ) && 1 === preg_match( '#<time datetime="\d{4}-\d\d-\d\dT\d\d:\d\d:00Z">#', $card_b ) );
check( 'A100 price from = lowest price ("ab 9,00 €"), equal prices without "ab"', false !== strpos( $card_b, '<dd>ab 9,00 €</dd>' ) && false !== strpos( $card_c, '<dd>25,00 €</dd>' ) );
check( 'A100 availability: unlimited, few seats with count, sold out', false !== strpos( $card_b, '<dd>Tickets verfügbar</dd>' ) && false !== strpos( $card_c, '<dd>Nur noch 3 Plätze frei</dd>' ) && false !== strpos( $card_a, '<dd>Ausverkauft</dd>' ) );
check( 'A100 doors time stays a fact on the card (no duplicate badge there)', false !== strpos( $card_b, '<div class="wpcap-fact-doors">' ) && false !== strpos( $card_b, 'Einlass ab 18:00' ) && false === strpos( substr( $card_b, 0, $pos( $card_b, '<dl' ) ), 'wpcap-badge-doors' ) );
check( 'A100 more than visible_types (4) ticket types: the rest collapses in <details> (no JS needed)', 1 === preg_match( '#<details class="wpcap-more-types"><summary>Weitere Ticketarten \(2\)</summary>#', $card_b ) && 6 === substr_count( $card_b, 'class="wpcap-qty"' ) && $pos( $card_b, 'data-type="' . $types_b[3] . '"' ) < $pos( $card_b, 'wpcap-more-types' ) && $pos( $card_b, 'wpcap-more-types' ) < $pos( $card_b, 'data-type="' . $types_b[4] . '"' ) );
check( 'A100 a single remaining type is never collapsed; one type: no <details>', false === strpos( $card_c, 'wpcap-more-types' ) );

// Other dates of the event, collapsible, with badges.
$dates_b = substr( $card_b, $pos( $card_b, '<details class="wpcap-dates">' ), $pos( $card_b, '</details>' ) - $pos( $card_b, '<details class="wpcap-dates">' ) );
check( 'A100 card lists the other dates of the event in <details> ("Weitere Termine (3)")', false !== strpos( $dates_b, '<summary>Weitere Termine (3)</summary>' ) && false !== strpos( $dates_b, 'href="#wpcap-occ-' . $occ_a . '"' ) && false !== strpos( $dates_b, 'href="#wpcap-occ-' . $occ_c . '"' ) && false === strpos( $dates_b, 'href="#wpcap-occ-' . $occ_b . '"' ) );
check( 'A100 cancelled date in the list: not linked, badge "Abgesagt"', false === strpos( $dates_b, '#wpcap-occ-' . $occ_d ) && 1 === preg_match( '#</time> <ul class="wpcap-badges"><li class="wpcap-badge wpcap-badge-cancelled">Abgesagt</li></ul></li>#', $dates_b ) );
check( 'A100 date list badges: sold out, rescheduled + few seats', 1 === preg_match( '#wpcap-occ-' . $occ_a . '".*?wpcap-badge-soldout">Ausverkauft<#s', $dates_b ) && 1 === preg_match( '#wpcap-occ-' . $occ_c . '".*?wpcap-badge-rescheduled">Verschoben</li><li class="wpcap-badge wpcap-badge-few_left">Wenige Plätze<#s', $dates_b ) );

// 3. Badges on the cards.
check( 'A100 card badges: A "Ausverkauft", C "Verschoben" + "Wenige Plätze"', false !== strpos( $card_a, 'wpcap-badge-soldout">Ausverkauft<' ) && false !== strpos( $card_c, 'wpcap-badge-rescheduled">Verschoben<' ) && false !== strpos( $card_c, 'wpcap-badge-few_left">Wenige Plätze<' ) && false === strpos( substr( $card_b, 0, $pos( $card_b, '</header>' ) ), 'wpcap-badge-few_left' ) );

// 4. Next date.
check( 'A100 next bookable date (B, A is sold out) is highlighted with "Nächster Termin"', false !== strpos( $card_b, '<header class="wpcap-event-header wpcap-is-next">' ) && false !== strpos( $card_b, 'wpcap-badge-next">Nächster Termin<' ) && false === strpos( $card_a, 'wpcap-is-next' ) && false === strpos( $card_c, 'wpcap-is-next' ) );
check( 'A100 other cards point to the next date in their list', 1 === preg_match( '#wpcap-occ-' . $occ_b . '".*?wpcap-badge-next">Nächster Termin<#s', $card_c ) );
$single = $a100_render( array( 'occurrence' => $occ_c ) );
check( 'A100 a single listed date: no "Nächster Termin", no date list (not even cancelled dates)', '' !== $single && false === strpos( $single, 'wpcap-badge-next' ) && false === strpos( $single, 'wpcap-dates' ) );
check( 'A100 the card article tag is unchanged (anchors, listing counters)', 3 === substr_count( $html, '<article id="wpcap-occ-' ) + substr_count( $html, '<article class="wpcap-event">' ) );

// Thresholds and badges are filterable.
$GLOBALS['wpcap_test_filters']['wpcap_event_card_thresholds'] = array( 'few_left' => 0, 'visible_types' => 10 );
$html_th = $a100_render();
unset( $GLOBALS['wpcap_test_filters']['wpcap_event_card_thresholds'] );
check( 'A100 wpcap_event_card_thresholds: few_left 0 hides "Wenige Plätze", visible_types 10 shows all types', false === strpos( $html_th, 'wpcap-badge-few_left' ) && false === strpos( $html_th, 'Nur noch 3' ) && false === strpos( $html_th, 'wpcap-more-types' ) );
$GLOBALS['wpcap_test_filters']['wpcap_event_card_thresholds'] = array( 'few_left' => 10, 'rescheduled_days' => 1 );
$wpdb->query( "UPDATE {$p}wpcap_audit_logs SET created_at=DATE_SUB(created_at, INTERVAL 3 DAY) WHERE action='occurrence_rescheduled' AND object_id=$occ_c" );
$html_old = $a100_render();
$wpdb->query( "UPDATE {$p}wpcap_audit_logs SET created_at=DATE_ADD(created_at, INTERVAL 3 DAY) WHERE action='occurrence_rescheduled' AND object_id=$occ_c" );
unset( $GLOBALS['wpcap_test_filters']['wpcap_event_card_thresholds'] );
check( 'A100 rescheduled_days: "Verschoben" disappears after the window', false === strpos( $html_old, 'wpcap-badge-rescheduled' ) );
add_filter( 'wpcap_occurrence_badges', $a100_badge = static function ( $badges, $occ, $available, $context ) { if ( 'card' === ( $context['view'] ?? '' ) ) { $badges[] = array( 'key' => 'Premiere!', 'label' => 'Premiere <b>' ); } return $badges; }, 10, 4 );
$html_badge = $a100_render();
remove_filter( 'wpcap_occurrence_badges', $a100_badge, 10 );
check( 'A100 wpcap_occurrence_badges adds badges (escaped, safe class name)', false !== strpos( $html_badge, '<li class="wpcap-badge wpcap-badge-premiere">Premiere &lt;b&gt;</li>' ) );

// 2. Stepper: limits on the server-rendered field, live region, JS enhancement.
check( 'A100 without JS: plain number fields with min/max, no stepper buttons in the HTML', false !== strpos( $card_c, 'type="number" min="0" max="3"' ) && false === strpos( $html, 'wpcap-step' ) );
check( 'A100 polite live region for quantity announcements', false !== strpos( $card_b, '<p class="wpcap-qty-live wpcap-sr-only" aria-live="polite" aria-atomic="true"></p>' ) );
$GLOBALS['wpcap_test_filters']['wpcap_event_card_quantity_max'] = 2;
$GLOBALS['wpcap_test_filters']['wpcap_event_card_max_total'] = 3;
$html_lim = $a100_render();
$GLOBALS['wpcap_test_filters']['wpcap_event_card_quantity_max'] = 50;
$html_up = $a100_render();
unset( $GLOBALS['wpcap_test_filters']['wpcap_event_card_quantity_max'], $GLOBALS['wpcap_test_filters']['wpcap_event_card_max_total'] );
check( 'A100 wpcap_event_card_quantity_max lowers the field maximum (never raises it)', false !== strpos( $a100_card( $html_lim, $occ_c ), 'max="2"' ) && false !== strpos( $a100_card( $html_up, $occ_c ), 'max="3"' ) );
check( 'A100 wpcap_event_card_max_total sets data-max-total for stepper and validation', false !== strpos( $a100_card( $html_lim, $occ_b ), '<fieldset class="wpcap-types" data-max-total="3">' ) && false === strpos( $html, 'data-max-total' ) );
if ( class_exists( 'PassCaptain\Allocations\BuyerLimits' ) && ! empty( $GLOBALS['wpcap_test_hooks']['wpcap_event_card_max_total'] ) ) {
	PassCaptain\Allocations\BuyerLimits::set( $a100_event, 0, 4 );
	PassCaptain\Allocations\BuyerLimits::set( $a100_event, $types_b[0], 1 );
	PassCaptain\Allocations\BuyerLimits::flush();
	$card_bl = $a100_card( $a100_render(), $occ_b );
	PassCaptain\Allocations\BuyerLimits::set( $a100_event, 0, 0 );
	PassCaptain\Allocations\BuyerLimits::set( $a100_event, $types_b[0], 0 );
	PassCaptain\Allocations\BuyerLimits::flush();
	check( 'A100 buyer limits feed the stepper: per event (data-max-total) and per type (max)', false !== strpos( $card_bl, 'data-max-total="4"' ) && 1 === preg_match( '#max="1"[^>]*data-type="' . $types_b[0] . '"#', $card_bl ) && 1 === preg_match( '#max="10"[^>]*data-type="' . $types_b[1] . '"#', $card_bl ) );
}
$js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'A100 checkout.js enhances .wpcap-qty with labelled −/+ buttons (aria-controls, aria-label)', false !== strpos( $js, "class: 'wpcap-step wpcap-step-down', 'aria-controls': id, 'aria-label': fill(t.stepDown" ) && false !== strpos( $js, "class: 'wpcap-step wpcap-step-up', 'aria-controls': id, 'aria-label': fill(t.stepUp" ) );
check( 'A100 stepper keeps focus at limits (aria-disabled, not disabled) and respects min/max/data-max-total', false !== strpos( $js, "setAttribute('aria-disabled'" ) && false === strpos( $js, 'step-up\').disabled' ) && false !== strpos( $js, 'dataset.maxTotal' ) && false !== strpos( $js, 'qtyBounds(input)' ) );
check( 'A100 stepper fires input and change (estimate, attendees, seat picker) and announces via .wpcap-qty-live', false !== strpos( $js, "dispatchEvent(new Event('input', { bubbles: true }))" ) && false !== strpos( $js, "dispatchEvent(new Event('change', { bubbles: true }))" ) && false !== strpos( $js, "querySelector('.wpcap-qty-live')" ) );
check( 'A100 errors inside a collapsed list open it', false !== strpos( $js, "closest('details:not([open])')" ) );
$a100_src = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'A100 stepper strings are translatable', false !== strpos( $a100_src, "'stepUp' => __( '%s: ein Ticket mehr', 'wp-pass-captain' )" ) && false !== strpos( $a100_src, "'qtyOne' => __(" ) && false !== strpos( $a100_src, "'errTotalOne' => __(" ) );

// Theme templates without $card keep working.
$a100_rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location,o.doors_open_at FROM {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id WHERE o.occurrence_id=%d", $occ_c ) );
$a100_listing = PublicEvents::listing_data( $a100_rows );
$event = $a100_rows[0];
$types = $a100_listing[ $occ_c ];
$providers = array();
$legal = array( 'require_terms' => false, 'terms_text' => '', 'consumer_info_text' => '' );
unset( $card );
ob_start();
include WPCAP_PATH . 'templates/event-card.php';
$a100_plain = ob_get_clean();
check( 'A100 template computes the card context itself when $card is missing', false !== strpos( $a100_plain, 'Nur noch 3 Plätze frei' ) && false !== strpos( $a100_plain, 'wpcap-badge-few_left' ) && false !== strpos( $a100_plain, '<dd>25,00 €</dd>' ) );
unset( $event, $types, $providers, $legal, $card );

// Unit helpers.
check( 'A100 availability text: singular/plural, unlimited, many seats', 'Nur noch 1 Platz frei' === EventCard::availability_text( 1 ) && 'Tickets verfügbar' === EventCard::availability_text( null ) && 'Tickets verfügbar' === EventCard::availability_text( 500 ) && 'Ausverkauft' === EventCard::availability_text( 0 ) );
check( 'A100 occurrence availability: sum of types, unlimited, capped by occurrence capacity', 5 === EventCard::occurrence_available( array( (object) array( 'available' => 2 ), (object) array( 'available' => 3 ) ) ) && null === EventCard::occurrence_available( array( (object) array( 'available' => null ) ) ) && 4 === EventCard::occurrence_available( array( (object) array( 'available' => 4 ), (object) array( 'available' => 4 ) ), (object) array( 'capacity' => 10, 'sold_qty' => 5, 'held_qty' => 1 ) ) );
check( 'A100 next date skips sold-out and cancelled dates and needs two dates of an event', array( 2 => true ) === EventCard::next_occurrences( array( (object) array( 'occurrence_id' => 1, 'event_id' => 9 ), (object) array( 'occurrence_id' => 5, 'event_id' => 9, 'status' => 'cancelled' ), (object) array( 'occurrence_id' => 2, 'event_id' => 9 ), (object) array( 'occurrence_id' => 3, 'event_id' => 8 ) ), array( 1 => 0, 2 => null, 3 => 5 ) ) );

// Calendar badges.
Calendar::register();
$cal = Calendar::render_list( $a100_event );
$cal_item = static function ( $html, $needle ) {
	foreach ( explode( '<li class="wpcap-cal-item', $html ) as $chunk ) { if ( false !== strpos( $chunk, $needle ) ) { return $chunk; } }
	return '';
};
$cal_a = $cal_item( $cal, '#wpcap-occ-' . $occ_a . '"' );
$cal_b = $cal_item( $cal, '#wpcap-occ-' . $occ_b . '"' );
$cal_c = $cal_item( $cal, '#wpcap-occ-' . $occ_c . '"' );
$cal_d = $cal_item( $cal, 'wpcap-badge-cancelled' );
check( 'A100 calendar list: A "Ausverkauft", B "Nächster Termin" + "Einlass ab 18:00", C "Verschoben" + "Wenige Plätze"', false !== strpos( $cal_a, 'wpcap-badge-soldout">Ausverkauft<' ) && false !== strpos( $cal_b, 'wpcap-badge-next">Nächster Termin<' ) && false !== strpos( $cal_b, 'wpcap-badge-doors">Einlass ab 18:00<' ) && false !== strpos( $cal_c, 'wpcap-badge-rescheduled">Verschoben<' ) && false !== strpos( $cal_c, 'wpcap-badge-few_left">Wenige Plätze<' ) );
check( 'A100 calendar list: cancelled date shown with "Abgesagt", without link or .ics', '' !== $cal_d && 0 === strpos( $cal_d, ' wpcap-cal-cancelled"' ) && false === strpos( $cal_d, '<a ' ) && false === strpos( $cal, 'id=' . $occ_d . '"' ) && false === strpos( $cal, 'wpcap-occ-' . $occ_d ) );
$GLOBALS['wpcap_test_filters']['wpcap_calendar_show_cancelled'] = false;
$cal_hidden = Calendar::render_list( $a100_event );
unset( $GLOBALS['wpcap_test_filters']['wpcap_calendar_show_cancelled'] );
check( 'A100 wpcap_calendar_show_cancelled false hides cancelled dates', false === strpos( $cal_hidden, 'Abgesagt' ) && 3 === substr_count( $cal_hidden, '<li class="wpcap-cal-item' ) );
$_GET['wpcap_month'] = gmdate( 'Y-m', strtotime( (string) $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ_b" ) . ' UTC' ) );
$grid = Calendar::render_month( $_GET['wpcap_month'], $a100_event );
unset( $_GET['wpcap_month'] );
check( 'A100 month grid shows the badges next to the linked entry', 1 === preg_match( '#href="[^"]*wpcap-occ-' . $occ_b . '"[^>]*>[^<]*</a><ul class="wpcap-badges"><li class="wpcap-badge wpcap-badge-next">Nächster Termin</li>#', $grid ) );
$css = (string) file_get_contents( WPCAP_PATH . 'assets/public.css' ) . (string) file_get_contents( WPCAP_PATH . 'assets/calendar/calendar.css' );
check( 'A100 styles for badges, stepper, next-date highlight and collapsible lists', false !== strpos( $css, '.wpcap-badge-cancelled' ) && false !== strpos( $css, '.wpcap-step[aria-disabled="true"]' ) && false !== strpos( $css, '.wpcap-event:has(.wpcap-is-next)' ) && false !== strpos( $css, '.wpcap-calendar .wpcap-badge-next' ) );

// Clean up.
if ( ! is_wp_error( $a100_hold ) ) { Orders::cancel_order( (int) $a100_hold['order_id'] ); }
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='a100_done' WHERE event_id=$a100_event" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled' WHERE status='a100_hs'" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE status='a100_hc'" );

