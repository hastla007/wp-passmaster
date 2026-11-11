<?php
/**
 * Integration tests for series price tiers and shared quota pools (P3). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Series;
use PassCaptain\Orders;
use PassCaptain\Series\Generator;
use PassCaptain\Series\Pools;
use PassCaptain\Series\Pricing;

Series::register();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_transients'] = array();

/** Series with occurrences $day_offsets days from today at 19:00 Vienna; types copied from a template. */
function sp_series( array $day_offsets, $type_capacity = 50, $price = 2500, array $extra_types = array(), $occ_capacity = 0 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$tpl_type = make_type( $type_capacity, 10, $price, $occ_capacity );
	$tpl_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$tpl_type" );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$tpl_occ" );
	foreach ( $extra_types as $name => $cfg ) {
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $tpl_occ, 'name' => $name, 'price_minor' => $cfg['price'], 'currency' => 'EUR', 'capacity' => $cfg['capacity'] ?? 0, 'max_per_order' => 10, 'status' => 'active' ) );
	}
	$dates = array_map( static fn( $d ) => gmdate( 'Y-m-d', time() + $d * 86400 ), $day_offsets );
	$r = Generator::create( array( 'event_id' => $event, 'template_occurrence_id' => $tpl_occ, 'freq' => 'dates', 'dates' => implode( "\n", $dates ), 'time' => '19:00', 'duration_minutes' => 60, 'timezone' => 'Europe/Vienna', 'name' => 'P3 ' . uniqid() ) );
	$types = array();
	foreach ( $r['created'] as $occ ) {
		foreach ( $wpdb->get_results( "SELECT ticket_type_id,name FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ . ' ORDER BY ticket_type_id' ) as $t ) { $types[ $occ ][ $t->name ] = (int) $t->ticket_type_id; }
	}
	return array( 'series_id' => (int) $r['series_id'], 'event_id' => $event, 'occurrences' => $r['created'], 'types' => $types );
}
function sp_buy( $type_id, $qty, $key, array $internal = array( 'skip_rate_limit' => true ), array $extra = array() ) {
	return Orders::create( array( 'email' => 'sp-' . preg_replace( '/[^a-z0-9]/', '', $key ) . '@example.com', 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $qty ) + ( $extra['item'] ?? array() ) ), 'idempotency_key' => 'sp-' . $key ) + ( $extra['payload'] ?? array() ), $internal );
}
function sp_rule( array $fields ) {
	return (object) ( $fields + array( 'rule_id' => 1, 'type_name' => '', 'priority' => 0, 'until_at' => null, 'hours_before' => 0, 'sold_pct' => 0, 'weekdays' => '', 'time_from' => '', 'time_to' => '', 'price_mode' => 'fixed', 'amount' => 1000 ) );
}

echo "Series pricing: tier selection and boundaries (pure)\n";
$eb_in = Pricing::normalize( array( 'kind' => 'early_bird', 'until' => '2027-03-01T00:00', 'price_mode' => 'fixed', 'amount' => '19,90' ), 'Europe/Vienna' );
check( 'early-bird date is entered in local time and stored in UTC (Vienna 00:00 = 23:00 UTC)', '2027-02-28 23:00:00' === $eb_in['until_at'] && 1990 === $eb_in['amount'] && 20 === $eb_in['priority'] );
$eb_ny = Pricing::normalize( array( 'kind' => 'early_bird', 'until' => '2027-07-01T00:00', 'amount' => '10' ), 'America/New_York' );
check( 'early-bird in another timezone with DST (New York 00:00 EDT = 04:00 UTC)', '2027-07-01 04:00:00' === $eb_ny['until_at'] );
$facts = array( 'starts_at' => '2027-03-10 18:00:00', 'timezone' => 'Europe/Vienna', 'type_name' => 'Standard', 'sold_pct' => 0 );
$eb = sp_rule( array( 'kind' => 'early_bird', 'until_at' => $eb_in['until_at'], 'priority' => 20 ) );
check( 'early bird applies one second before the deadline', Pricing::matches( $eb, array( 'now' => '2027-02-28 22:59:59' ) + $facts ) );
check( 'early bird ends exactly at the deadline', ! Pricing::matches( $eb, array( 'now' => '2027-02-28 23:00:00' ) + $facts ) );
$lm = sp_rule( array( 'kind' => 'last_minute', 'hours_before' => 24, 'priority' => 40 ) );
check( 'last minute does not apply one second before the window', ! Pricing::matches( $lm, array( 'now' => '2027-03-09 17:59:59' ) + $facts ) );
check( 'last minute applies from exactly X hours before start', Pricing::matches( $lm, array( 'now' => '2027-03-09 18:00:00' ) + $facts ) );
$d50 = sp_rule( array( 'rule_id' => 3, 'kind' => 'demand', 'sold_pct' => 50, 'priority' => 30, 'amount' => 3000 ) );
$d80 = sp_rule( array( 'rule_id' => 4, 'kind' => 'demand', 'sold_pct' => 80, 'priority' => 30, 'amount' => 3500 ) );
check( 'demand step: 49.9 % sold does not match 50 %', ! Pricing::matches( $d50, array( 'now' => '2027-03-01 00:00:00', 'sold_pct' => 49.9 ) + $facts ) );
check( 'demand step: exactly 50 % matches', Pricing::matches( $d50, array( 'now' => '2027-03-01 00:00:00', 'sold_pct' => 50 ) + $facts ) );
check( 'demand steps: the highest reached step wins', 4 === (int) Pricing::select( array( $d50, $d80 ), array( 'now' => '2027-03-01 00:00:00', 'sold_pct' => 85 ) + $facts )->rule_id && 3 === (int) Pricing::select( array( $d50, $d80 ), array( 'now' => '2027-03-01 00:00:00', 'sold_pct' => 79 ) + $facts )->rule_id );
check( 'no demand without a capacity basis', null === Pricing::select( array( $d50 ), array( 'now' => '2027-03-01 00:00:00', 'sold_pct' => null ) + $facts ) );
$lazy_calls = 0;
Pricing::select( array( $eb ), array( 'now' => '2027-02-01 00:00:00', 'sold_pct' => static function () use ( &$lazy_calls ) { $lazy_calls++; return 0; } ) + $facts );
check( 'sold share is only computed when a demand rule exists', 0 === $lazy_calls );
check( 'last minute (priority 40) beats early bird (20) when both match', 'last_minute' === Pricing::select( array( $eb, $lm ), array( 'now' => '2027-02-28 20:00:00', 'starts_at' => '2027-03-01 18:00:00' ) + $facts )->kind );
// Friday 23:30 UTC = Saturday 00:30 in Vienna (UTC+1).
$late = array( 'starts_at' => '2027-03-05 23:30:00', 'now' => '2027-03-01 00:00:00' ) + $facts;
check( 'weekday uses the local start day (Sat 00:30 Vienna is Fri in UTC)', Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'weekdays' => '6' ) ), $late ) && ! Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'weekdays' => '5' ) ), $late ) );
check( 'time slot "from" is inclusive', Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'time_from' => '00:30', 'time_to' => '02:00' ) ), $late ) );
check( 'time slot "to" is exclusive, also when wrapping midnight', ! Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'time_from' => '18:00', 'time_to' => '00:30' ) ), $late ) && Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'time_from' => '18:00', 'time_to' => '00:31' ) ), $late ) );
$summer = array( 'starts_at' => '2027-03-28 17:00:00', 'now' => '2027-03-01 00:00:00' ) + $facts; // 19:00 CEST after the DST switch.
check( 'evening slot matches local 19:00 after the DST change (17:00 UTC)', Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'weekdays' => '7', 'time_from' => '19:00', 'time_to' => '20:00' ) ), $summer ) );
check( 'type-scoped rule only matches that ticket type (case-insensitive)', Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'weekdays' => '6', 'type_name' => 'student' ) ), array( 'type_name' => 'Student' ) + $late ) && ! Pricing::matches( sp_rule( array( 'kind' => 'weekday', 'weekdays' => '6', 'type_name' => 'Student' ) ), $late ) );
check( 'percentage rules round half up; fixed price; never negative', 2000 === Pricing::apply( 2500, sp_rule( array( 'price_mode' => 'percent', 'amount' => -2000 ) ) ) && 1154 === Pricing::apply( 999, sp_rule( array( 'price_mode' => 'percent', 'amount' => 1550 ) ) ) && 1000 === Pricing::apply( 2500, sp_rule( array() ) ) && 0 === Pricing::apply( 100, sp_rule( array( 'price_mode' => 'percent', 'amount' => -10000 ) ) ) );
$pct = Pricing::normalize( array( 'kind' => 'demand', 'sold_pct' => '80', 'price_mode' => 'percent', 'amount' => '−12,5' ), 'Europe/Vienna' );
check( 'percent input with a sign is parsed into basis points', ! is_wp_error( $pct ) && -1250 === $pct['amount'] && 30 === $pct['priority'] );
check( 'invalid rules are rejected', is_wp_error( Pricing::normalize( array( 'kind' => 'early_bird', 'until' => 'morgen', 'amount' => '10' ), 'Europe/Vienna' ) )
	&& is_wp_error( Pricing::normalize( array( 'kind' => 'last_minute', 'hours_before' => '0', 'amount' => '10' ), 'Europe/Vienna' ) )
	&& is_wp_error( Pricing::normalize( array( 'kind' => 'demand', 'sold_pct' => '120', 'amount' => '10' ), 'Europe/Vienna' ) )
	&& is_wp_error( Pricing::normalize( array( 'kind' => 'weekday', 'amount' => '10' ), 'Europe/Vienna' ) )
	&& is_wp_error( Pricing::normalize( array( 'kind' => 'weekday', 'weekdays' => array( 1 ), 'time_from' => '19:00', 'amount' => '10' ), 'Europe/Vienna' ) )
	&& is_wp_error( Pricing::normalize( array( 'kind' => 'weekday', 'weekdays' => array( 1 ), 'amount' => 'abc' ), 'Europe/Vienna' ) )
	&& is_wp_error( Pricing::normalize( array( 'kind' => 'bogus', 'amount' => '10' ), 'Europe/Vienna' ) ) );

echo "Series pricing: server-side prices in checkout\n";
$GLOBALS['wpcap_test_transients'] = array();
$s1 = sp_series( array( 10, 11 ) );
$occ_a = $s1['occurrences'][0];
$type_a = $s1['types'][ $occ_a ]['Standard'];
$until_local = ( new DateTimeImmutable( '@' . ( time() + 86400 ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
$rule_id = Pricing::create( $s1['series_id'], array( 'kind' => 'early_bird', 'label' => 'Frühbucher', 'until' => $until_local, 'price_mode' => 'fixed', 'amount' => '19,00' ) );
check( 'rule is stored for the series', is_int( $rule_id ) && $rule_id > 0 );
check( 'effective price and label for display', 1900 === Orders::unit_price( $type_a ) && 'Frühbucher' === Orders::price_label( (object) array( 'occurrence_id' => $occ_a, 'name' => 'Standard', 'ticket_type_id' => $type_a, 'capacity' => 50, 'price_minor' => 2500 ) ) );
$order = sp_buy( $type_a, 2, 'eb-1', array(), array( 'payload' => array( 'unit_prices' => array( $type_a => 1 ), 'external' => true, 'price_minor' => 1, 'line_totals' => array( $type_a => 1 ) ), 'item' => array( 'price_minor' => 1, 'unit_price_minor' => 1 ) ) );
$item = $wpdb->get_row( "SELECT * FROM {$p}wpcap_order_items WHERE order_id=" . (int) ( $order['order_id'] ?? 0 ) );
check( 'public order is charged the tier price; client price fields are ignored', ! is_wp_error( $order ) && 3800 === (int) $order['total_minor'] && 1900 === (int) $item->unit_price_minor && 'pending' === $order['payment_status'] );
check( 'price snapshot records base and applied unit price', array( 'base_minor' => 2500, 'unit_minor' => 1900 ) === ( json_decode( Orders::get( $order['order_id'] )->price_snapshot, true )['unit_price_adjustments'][ $type_a ] ?? null ) );
$woo = sp_buy( $type_a, 1, 'eb-woo', array( 'external' => true, 'key_namespace' => 'woocommerce', 'unit_prices' => array( $type_a => 3100 ), 'line_totals' => array( $type_a => 3100 ) ) );
check( 'trusted unit_prices override (WooCommerce) takes precedence over tiers', ! is_wp_error( $woo ) && 3100 === (int) $woo['total_minor'] );
$pos = sp_buy( $type_a, 1, 'eb-pos', array( 'external' => true, 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true ) );
check( 'box office sale without price override charges the tier price', ! is_wp_error( $pos ) && 1900 === (int) $pos['total_minor'] );
Pricing::delete( $rule_id );
check( 'deleted rule no longer applies', 2500 === Orders::unit_price( $type_a ) );
$past_local = ( new DateTimeImmutable( '@' . ( time() - 60 ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
Pricing::create( $s1['series_id'], array( 'kind' => 'early_bird', 'until' => $past_local, 'amount' => '5' ) );
check( 'expired early bird falls back to the stored price', 2500 === Orders::unit_price( $type_a ) );

$s2 = sp_series( array( 3, 20 ), 10 );
$soon = $s2['types'][ $s2['occurrences'][0] ]['Standard'];
$later = $s2['types'][ $s2['occurrences'][1] ]['Standard'];
Pricing::create( $s2['series_id'], array( 'kind' => 'last_minute', 'hours_before' => '120', 'price_mode' => 'percent', 'amount' => '-50' ) );
Pricing::create( $s2['series_id'], array( 'kind' => 'demand', 'sold_pct' => '50', 'price_mode' => 'percent', 'amount' => '20' ) );
check( 'last minute applies only to the occurrence within the window', 1250 === Orders::unit_price( $soon ) && 2500 === Orders::unit_price( $later ) );
$paid = sp_buy( $later, 4, 'demand-1' );
check( 'demand step not reached at 40 %', 2500 === Orders::unit_price( $later ) );
$held = sp_buy( $later, 1, 'demand-2' );
check( 'active holds count towards demand: 50 % reached → +20 %', 3000 === Orders::unit_price( $later ) );
$next = sp_buy( $later, 1, 'demand-3' );
check( 'the next order is charged the demand price', ! is_wp_error( $next ) && 3000 === (int) $next['total_minor'] );
expire_holds_of( $held['order_id'] );
expire_holds_of( $next['order_id'] );
check( 'expired holds lower the sold share again', 2500 === Orders::unit_price( $later ) );

$s3 = sp_series( array( 5 ), 50, 2500, array( 'Student' => array( 'price' => 1500 ) ) );
$occ3 = $s3['occurrences'][0];
$occ3_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ3" );
$local_day = (int) ( new DateTimeImmutable( $occ3_row->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'N' );
Pricing::create( $s3['series_id'], array( 'kind' => 'weekday', 'weekdays' => array( $local_day ), 'time_from' => '18:00', 'time_to' => '20:00', 'type_name' => 'Student', 'amount' => '9,00' ) );
check( 'weekday/time-slot price (local 19:00) for the scoped type only', 900 === Orders::unit_price( $s3['types'][ $occ3 ]['Student'] ) && 2500 === Orders::unit_price( $s3['types'][ $occ3 ]['Standard'] ) );

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
try {
	$html = PassCaptain\PublicEvents::render_shortcode();
	$pos_card = strpos( $html, 'id="wpcap-' . $occ3 . '-' . $s3['types'][ $occ3 ]['Student'] . '"' );
	check( 'event page shows the effective price, label, regular price and data-price', false !== $pos_card && false !== strpos( $html, 'Student — 9,00 EUR' ) && false !== strpos( $html, 'statt 15,00 EUR' ) && false !== strpos( $html, 'data-price="900"' ) );
} catch ( \Throwable $e ) {
	check( 'event page renders (' . $e->getMessage() . ')', false );
}

echo "Series pools: shared quota across occurrences\n";
$GLOBALS['wpcap_test_transients'] = array();
$released = array();
add_action( 'wpcap_capacity_released', static function ( $order_id, $reason ) use ( &$released ) { $released[] = $reason; }, 10, 2 );
$s4 = sp_series( array( 6, 7, 8 ), 50, 2500, array( 'Student' => array( 'price' => 1200 ) ) );
list( $o1, $o2, $o3 ) = $s4['occurrences'];
$pool = Pools::create( $s4['series_id'], array( 'name' => 'Gesamt', 'capacity' => '5' ) );
$student_pool = Pools::create( $s4['series_id'], array( 'name' => 'Studierende', 'type_name' => 'Student', 'capacity' => '2' ) );
check( 'pools are stored', is_int( $pool ) && is_int( $student_pool ) && is_wp_error( Pools::create( $s4['series_id'], array( 'capacity' => '0' ) ) ) );
check( 'availability reflects the pool (type has 50, pool 5)', 5 === Orders::availability( $s4['types'][ $o1 ]['Standard'] ) && 2 === Orders::availability( $s4['types'][ $o1 ]['Student'] ) );
$a = sp_buy( $s4['types'][ $o1 ]['Standard'], 2, 'pool-a' );
$b = sp_buy( $s4['types'][ $o2 ]['Student'], 2, 'pool-b' );
check( 'orders on different dates draw from the same pool', ! is_wp_error( $a ) && ! is_wp_error( $b ) && 1 === Orders::availability( $s4['types'][ $o3 ]['Standard'] ) && 0 === Orders::availability( $s4['types'][ $o3 ]['Student'] ) );
$c = sp_buy( $s4['types'][ $o3 ]['Standard'], 2, 'pool-c' );
check( 'order exceeding the pool is rejected with a clear message', is_wp_error( $c ) && false !== strpos( $c->get_error_message(), 'Gesamt' ) && false !== strpos( $c->get_error_message(), '1' ) );
$d = sp_buy( $s4['types'][ $o3 ]['Student'], 1, 'pool-d' );
check( 'type-scoped pool (200 students across the series) is enforced', is_wp_error( $d ) && false !== strpos( $d->get_error_message(), 'Studierende' ) );
$template_type = (int) $wpdb->get_var( "SELECT tt.ticket_type_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=" . (int) $s4['event_id'] . " AND o.occurrence_id NOT IN ($o1,$o2,$o3) AND tt.name='Standard'" );
check( 'occurrences outside the series are not limited by its pools', null === Orders::availability( $template_type ) || 50 === Orders::availability( $template_type ) );
$e = sp_buy( $s4['types'][ $o3 ]['Standard'], 1, 'pool-e' );
check( 'last pool unit can be sold; pool then sold out', ! is_wp_error( $e ) && 0 === Orders::availability( $s4['types'][ $o2 ]['Standard'] ) );

echo "Series pools: release on expiry, cancellation and refunds\n";
expire_holds_of( $a['order_id'] );
Orders::expire_holds();
check( 'expired hold releases pool capacity', 2 === Orders::availability( $s4['types'][ $o2 ]['Standard'] ) && in_array( 'expired', $released, true ) );
Orders::cancel_order( $e['order_id'], 'test' );
check( 'cancelled order releases pool capacity', 3 === Orders::availability( $s4['types'][ $o2 ]['Standard'] ) && in_array( 'cancelled', $released, true ) );
Orders::confirm_manual_payment( $b['order_id'] );
check( 'paid student order keeps its pool units', 'paid' === Orders::get( $b['order_id'] )->payment_status && 0 === Orders::availability( $s4['types'][ $o1 ]['Student'] ) );
Orders::revoke_tickets( $b['order_id'], $s4['types'][ $o2 ]['Student'], 1, 'refund_test' );
check( 'partially refunded/revoked ticket releases one pool unit', 1 === Orders::availability( $s4['types'][ $o1 ]['Student'] ) && in_array( 'tickets_revoked', $released, true ) );
Orders::mark_refunded( $b['order_id'], 'test' );
check( 'full refund releases the remaining pool units', 2 === Orders::availability( $s4['types'][ $o1 ]['Student'] ) && 5 === Orders::availability( $s4['types'][ $o1 ]['Standard'] ) && in_array( 'refunded', $released, true ) );

echo "Series pools: late payment recovery re-checks the pool\n";
$s5 = sp_series( array( 9, 10 ) );
list( $r1, $r2 ) = $s5['occurrences'];
Pools::create( $s5['series_id'], array( 'name' => 'Klein', 'capacity' => '2' ) );
$late = sp_buy( $s5['types'][ $r1 ]['Standard'], 1, 'late-1' );
expire_holds_of( $late['order_id'] );
$fill = sp_buy( $s5['types'][ $r2 ]['Standard'], 2, 'late-fill' );
$confirm = Orders::confirm_manual_payment( $late['order_id'] );
check( 'late payment without pool capacity is flagged, not overbooked', is_wp_error( $confirm ) && 'needs_review' === Orders::get( $late['order_id'] )->status && ! tickets( $late['order_id'] ) && in_array( 'payment_unallocated', $released, true ) );
Orders::cancel_order( $fill['order_id'] );
$late2b = sp_buy( $s5['types'][ $r1 ]['Standard'], 1, 'late-3' );
expire_holds_of( $late2b['order_id'] );
check( 'late payment with free pool capacity is confirmed', true === Orders::confirm_manual_payment( $late2b['order_id'] ) && 1 === count( tickets( $late2b['order_id'] ) ) );
Pools::update_capacity( (int) $wpdb->get_var( "SELECT pool_id FROM {$p}wpcap_series_pools WHERE series_id=" . (int) $s5['series_id'] ), 1 );
check( 'pool reduced below usage: nothing more is sold, availability 0', 0 === Orders::availability( $s5['types'][ $r2 ]['Standard'] ) && is_wp_error( sp_buy( $s5['types'][ $r2 ]['Standard'], 1, 'late-4' ) ) );

echo "Series pools: concurrency\n";
$s6 = sp_series( array( 12, 13 ) );
list( $c1, $c2 ) = $s6['occurrences'];
Pools::create( $s6['series_id'], array( 'name' => 'Parallel', 'capacity' => '3' ) );
$child = __DIR__ . '/series/pool_child.php';
$procs = array();
for ( $i = 0; $i < 12; $i++ ) {
	$type = $s6['types'][ 0 === $i % 2 ? $c1 : $c2 ]['Standard'];
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' ' . (int) $type . ' 1 par' . $i, 'r' );
}
$ok = 0;
foreach ( $procs as $proc ) { $ok += 0 === strpos( trim( stream_get_contents( $proc ) ), 'OK' ) ? 1 : 0; pclose( $proc ); }
$held = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN (" . (int) $s6['types'][ $c1 ]['Standard'] . ',' . (int) $s6['types'][ $c2 ]['Standard'] . ") AND status='active'" );
check( "exactly 3 of 12 parallel buyers on two dates get pool units (got $ok, held $held)", 3 === $ok && 3 === $held );

// Second connection holds the pool row lock and commits a hold for date 2 while a checkout for date 1
// waits on that lock: the waiting checkout must see the committed hold (no stale snapshot).
$s7 = sp_series( array( 14, 15 ) );
list( $l1, $l2 ) = $s7['occurrences'];
$pool7 = Pools::create( $s7['series_id'], array( 'name' => 'Sperre', 'capacity' => '3' ) );
$conn = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$conn->query( 'START TRANSACTION' );
$conn->query( "SELECT pool_id FROM {$p}wpcap_series_pools WHERE pool_id=" . (int) $pool7 . ' FOR UPDATE' );
$conn->query( "INSERT INTO {$p}wpcap_reservations (order_id,ticket_type_id,quantity,expires_at,status) VALUES (999999," . (int) $s7['types'][ $l2 ]['Standard'] . ",2,'" . gmdate( 'Y-m-d H:i:s', time() + 600 ) . "','active')" );
$proc = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' ' . (int) $s7['types'][ $l1 ]['Standard'] . ' 2 lock', 'r' );
usleep( 1500000 );
$conn->query( 'COMMIT' );
$out = trim( stream_get_contents( $proc ) );
pclose( $proc );
$waited = (float) substr( strrchr( $out, ' ' ), 1 );
check( "checkout waiting on the pool lock sees the hold committed meanwhile (\"$out\")", 0 === strpos( $out, 'ERR:' ) && false !== strpos( $out, 'Sperre' ) && $waited >= 1.0 );
$conn->query( "DELETE FROM {$p}wpcap_reservations WHERE order_id=999999" );
$conn->close();
$ok_after = sp_buy( $s7['types'][ $l1 ]['Standard'], 2, 'lock-after' );
check( 'after the competing hold is gone the same order succeeds', ! is_wp_error( $ok_after ) );

echo "Series pools: WooCommerce cart checks see pools\n";
if ( class_exists( 'PassCaptain\\Integrations\\WooCommerce\\OrderAdapter' ) ) {
	$s8 = sp_series( array( 16, 17 ) );
	list( $w1, $w2 ) = $s8['occurrences'];
	Pools::create( $s8['series_id'], array( 'name' => 'Woo', 'capacity' => '2' ) );
	sp_buy( $s8['types'][ $w1 ]['Standard'], 2, 'woo-fill' );
	check( 'Woo cart validation rejects quantities beyond the pool', '' !== PassCaptain\Integrations\WooCommerce\OrderAdapter::quantity_error( $s8['types'][ $w2 ]['Standard'], 1 ) );
	$wc = make_wc_order( 8801, $s8['types'][ $w2 ]['Standard'], 1, '99.00' );
	$threw = false;
	try { PassCaptain\Integrations\WooCommerce\OrderAdapter::reserve_classic( 8801, array(), $wc ); } catch ( Exception $ex ) { $threw = true; }
	check( 'Woo checkout (own price) is still blocked by the pool via the core capacity hook', $threw );
}
