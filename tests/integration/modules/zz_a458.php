<?php
/**
 * A458: time-dependent prices (price tiers, early bird, evening box office) are recomputed at checkout;
 * a cached card kept showing the old price and the buyer only saw the difference at the payment
 * provider. Since A576 the checkout refuses a higher price than shown (409 wpcap_price_changed); now
 * the live state (GET /checkout/live, A456) carries the current price, base price and price label,
 * so the card shows the price that will be charged before the buyer orders (JS:
 * tests/js/checkout-live.test.js). Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }

echo "A458: the card shows the current time-dependent price before ordering\n";
global $wpdb;
$p = $wpdb->prefix;
$a458_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'manual_instructions' => 'Bitte überweisen.', 'require_terms' => 0 ) );
$a458_type = make_type( 20, 10, 2500 );
$a458_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a458_type" );
$a458_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a458_occ" );
// A price tier that starts "now": 25,00 € before, 29,00 € after (e.g. evening box office).
$GLOBALS['a458_phase'] = 'early';
$a458_price = static function ( $price, $type ) use ( $a458_type ) { return (int) $type->ticket_type_id === $a458_type && 'late' === $GLOBALS['a458_phase'] ? 2900 : $price; };
$a458_label = static function ( $label, $type ) use ( $a458_type ) { return (int) $type->ticket_type_id === $a458_type && 'late' === $GLOBALS['a458_phase'] ? 'Abendkasse' : $label; };
add_filter( 'wpcap_ticket_unit_price', $a458_price, 10, 2 );
add_filter( 'wpcap_ticket_price_label', $a458_label, 10, 2 );

Orders::flush_availability_cache();
$a458_cached = PublicEvents::render_shortcode( array( 'event' => $a458_event ) );
check( 'A458 setup: the cached card shows 25,00 € in a price span the script can update', 1 === preg_match( '/<span class="wpcap-type-price">25,00 €<\/span>/', $a458_cached ) && false !== strpos( $a458_cached, 'data-price="2500"' ) );

$GLOBALS['a458_phase'] = 'late';
Orders::flush_availability_cache();
$a458_s = (array) ( (array) PublicEvents::live_state( array( $a458_occ ) )['occurrences'] )[ $a458_occ ];
$a458_t = $a458_s['types'][ $a458_type ] ?? array();
check( 'A458: the live state carries the current unit price (minor units and formatted)', 2900 === ( $a458_t['price_minor'] ?? 0 ) && '29,00 €' === ( $a458_t['price'] ?? '' ) );
check( 'A458: … the regular price as base price and the tier label', '25,00 €' === ( $a458_t['base_price'] ?? '' ) && 'Abendkasse' === ( $a458_t['price_label'] ?? '' ) );
check( 'A458: … and the header price line', '29,00 €' === $a458_s['price'] );

// The checkout keeps protecting a buyer who still sends the cached price (A576), and accepts the live one.
$a458_order = static function ( $expected ) use ( $a458_type ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$_SERVER['REMOTE_ADDR'] = '198.18.' . random_int( 0, 250 ) . '.' . random_int( 1, 250 );
	return Orders::create( array( 'email' => 'a458@example.com', 'items' => array( array( 'ticket_type_id' => $a458_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a458-' . uniqid( '', true ), 'expected_prices' => array( $a458_type => $expected ) ) );
};
$a458_old = $a458_order( 2500 );
check( 'A458: the cached price is refused with 409 wpcap_price_changed (A576)', is_wp_error( $a458_old ) && 'wpcap_price_changed' === $a458_old->get_error_code() );
$a458_new = $a458_order( (int) $a458_t['price_minor'] );
check( 'A458: the price from the live state is accepted and charged', ! is_wp_error( $a458_new ) && 2900 === (int) $a458_new['total_minor'] );

remove_filter( 'wpcap_ticket_unit_price', $a458_price, 10 );
remove_filter( 'wpcap_ticket_price_label', $a458_label, 10 );
unset( $GLOBALS['a458_phase'] );
update_option( Settings::OPTION, $a458_saved );
$wpdb->query( "UPDATE {$p}wpcap_events SET status='cancelled' WHERE event_id=$a458_event" );
