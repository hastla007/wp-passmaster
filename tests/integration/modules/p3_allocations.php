<?php
/**
 * Integration tests for the allocations module (holds/kills, seat holds, per-buyer limits).
 * Included from run.php. Also runnable as a concurrency worker:
 *   php p3_allocations.php --alloc-child <ticket_type_id> <email> <start_microtime>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Allocations\AdminPage as AllocAdmin;
use PassCaptain\Allocations\BuyerLimits;
use PassCaptain\Allocations\Holds;
use PassCaptain\Allocations\SeatHolds;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Modules\Allocations;
use PassCaptain\Modules\Seating;
use PassCaptain\Orders;
use PassCaptain\Seating\AdminPage as SeatingAdmin;
use PassCaptain\Seating\Layouts;
use PassCaptain\Seating\Reservations;

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--alloc-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	Allocations::register();
	$GLOBALS['wpcap_test_user_id'] = 0;
	$start = (float) $argv[4];
	while ( microtime( true ) < $start ) { usleep( 2000 ); }
	$r = Orders::create( array( 'email' => $argv[3], 'items' => array( array( 'ticket_type_id' => (int) $argv[2], 'quantity' => 2 ) ), 'idempotency_key' => 'alloc-child-' . getmypid() ) );
	echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_message() : 'OK';
	exit;
}

if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) { $GLOBALS['wpcap_test_enqueued'][] = $a[0]; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) { $GLOBALS['wpcap_test_enqueued'][] = $a[0]; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }

Allocations::register();
Seating::register();
global $wpdb;
$p = $wpdb->prefix;
$alloc_user_before = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_user_id'] = 0;

function alloc_buy( $type_id, $qty, $email = 'fan@example.com', array $internal = array(), array $extra = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array_merge( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $qty ) ), 'idempotency_key' => 'alloc-' . uniqid( '', true ) ), $extra ), $internal );
}
function alloc_occ( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type_id );
}
function alloc_add_type( $occ, $name, $capacity = 0, $price = 2500 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
/** Runs admin actions as user 1 (event access) while orders run anonymously (buyer limits by user). */
function alloc_admin( callable $fn ) {
	$GLOBALS['wpcap_test_user_id'] = 1;
	try { return $fn(); } finally { $GLOBALS['wpcap_test_user_id'] = 0; }
}
function alloc_err( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }

$increased = array();
add_action( 'wpcap_capacity_increased', static function ( $type_id, $occurrence_id ) use ( &$increased ) { $increased[] = array( (int) $type_id, (int) $occurrence_id ); }, 10, 2 );

echo "Allocations: holds reduce public availability\n";
$type_a = make_type( 0, 10, 2500, 40 );
$occ = alloc_occ( $type_a );
$type_b = alloc_add_type( $occ, 'Ermäßigt', 0, 1500 );
$type_c = alloc_add_type( $occ, 'VIP', 8, 9000 );
check( 'without holds the occurrence capacity is public', 40 === Orders::availability( $type_a ) );
$artist = Holds::create( array( 'occurrence_id' => $occ, 'name' => 'Künstler', 'quantity' => 10 ) );
$press = Holds::create( array( 'occurrence_id' => $occ, 'ticket_type_id' => $type_c, 'name' => 'Presse', 'quantity' => 5, 'sources' => 'guestlist' ) );
$kill = Holds::create( array( 'occurrence_id' => $occ, 'name' => 'Technik-Sperre', 'kind' => 'kill', 'quantity' => 4 ) );
check( 'holds and kill are created', is_object( $artist ) && is_object( $press ) && is_object( $kill ) && 48 === (int) $artist->release_hours && 0 === (int) $kill->release_hours );
check( 'public availability = 40 - 10 - 5 - 4', 21 === Orders::availability( $type_a ) && 21 === Orders::availability( $type_b ) );
check( 'type-level hold also limits its type (8 - 5)', 3 === Orders::availability( $type_c ) );
$too_big = Holds::create( array( 'occurrence_id' => $occ, 'name' => 'Sponsor', 'quantity' => 22 ) );
check( 'a hold cannot exceed the free public capacity', is_wp_error( $too_big ) && false !== strpos( $too_big->get_error_message(), '21' ) );
$wrong_type = Holds::create( array( 'occurrence_id' => $occ, 'ticket_type_id' => make_type( 5 ), 'name' => 'X', 'quantity' => 1 ) );
check( 'ticket type of another occurrence is rejected', is_wp_error( $wrong_type ) );
$r1 = alloc_buy( $type_a, 10, 'a1@example.com' );
$r2 = alloc_buy( $type_b, 10, 'a2@example.com' );
check( 'public buyers can buy the 20 public places', ! is_wp_error( $r1 ) && ! is_wp_error( $r2 ) && 1 === Orders::availability( $type_a ) );
$r3 = alloc_buy( $type_a, 2, 'a3@example.com' );
check( 'native checkout cannot buy into holds', is_wp_error( $r3 ) && false !== strpos( $r3->get_error_message(), 'Plätze' ) );
$r3 = alloc_buy( $type_c, 2, 'a3@example.com' );
check( 'type hold blocks its type even with occurrence room left', is_wp_error( $r3 ) );
$waitlist = alloc_buy( $type_a, 2, 'wl@example.com', array( 'key_namespace' => 'waitlist', 'skip_rate_limit' => true, 'source' => 'waitlist' ) );
check( 'waitlist source cannot buy into holds', is_wp_error( $waitlist ) );
$woo_type = $type_a;
check( 'WooCommerce cart check sees the reduced availability', '' !== Woo::quantity_error( $woo_type, 2 ) && '' === Woo::quantity_error( $woo_type, 1 ) );
$wc = make_wc_order( 7101, $type_a, 2 );
$wc_core = Woo::reserve( $wc );
check( 'WooCommerce order creation (core create) respects holds', is_wp_error( $wc_core ) );

echo "Allocations: trusted channels consume holds\n";
$g = Holds::create_order( (int) $artist->hold_id, array( 'email' => 'band@example.com', 'items' => array( array( 'ticket_type_id' => $type_a, 'quantity' => 3 ) ), 'idempotency_key' => 'band-1' ) );
$artist = Holds::get( $artist->hold_id );
check( 'admin order consumes the hold', ! is_wp_error( $g ) && 3 === (int) $artist->used && 7 === Holds::open_units( $artist ) );
check( 'public availability unchanged by hold consumption', 1 === Orders::availability( $type_a ) );
$usage = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Holds::table( 'usage' ) . ' WHERE order_id=%d', $g['order_id'] ) );
check( 'usage row records source and quantity', $usage && 'admin' === $usage->source && 3 === (int) $usage->quantity );
$pos_plain = alloc_buy( $type_a, 2, 'kasse@example.com', array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true, 'external' => true ) );
check( 'box office without allocation cannot use held places', is_wp_error( $pos_plain ) );
$pos = alloc_buy( $type_a, 2, 'kasse@example.com', array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true, 'external' => true, 'allocation_id' => (int) $artist->hold_id ) );
check( 'box office sells from the hold via allocation_id', ! is_wp_error( $pos ) && 5 === (int) Holds::get( $artist->hold_id )->used );
$over = Holds::create_order( (int) $artist->hold_id, array( 'email' => 'band@example.com', 'items' => array( array( 'ticket_type_id' => $type_a, 'quantity' => 6 ) ), 'idempotency_key' => 'band-over' ) );
check( 'exhausted hold names the remaining places', is_wp_error( $over ) && false !== strpos( $over->get_error_message(), 'Künstler' ) && false !== strpos( $over->get_error_message(), '5' ) );
$public_alloc = alloc_buy( $type_a, 1, 'evil@example.com', array( 'allocation_id' => (int) $artist->hold_id ) );
check( 'public source cannot use allocation_id', is_wp_error( $public_alloc ) && false !== strpos( $public_alloc->get_error_message(), 'Verkaufskanal' ) );
$kill_use = Holds::create_order( (int) $kill->hold_id, array( 'email' => 'x@example.com', 'items' => array( array( 'ticket_type_id' => $type_a, 'quantity' => 1 ) ), 'idempotency_key' => 'kill-1' ) );
check( 'a kill can never be consumed', is_wp_error( $kill_use ) );
$press_admin = Holds::create_order( (int) $press->hold_id, array( 'email' => 'p@example.com', 'items' => array( array( 'ticket_type_id' => $type_c, 'quantity' => 1 ) ), 'idempotency_key' => 'press-admin' ) );
check( 'source restriction: admin cannot use a guest-list-only hold', is_wp_error( $press_admin ) );
$press_wrong_type = alloc_buy( $type_a, 1, 'p@example.com', array( 'source' => 'guestlist', 'key_namespace' => 'guestlist', 'skip_rate_limit' => true, 'allocation_id' => (int) $press->hold_id ) );
check( 'type hold cannot be used for another ticket type', is_wp_error( $press_wrong_type ) );
$map = static function ( $hold_id, $source ) use ( $press ) { return 'guestlist' === $source ? (int) $press->hold_id : $hold_id; };
add_filter( 'wpcap_allocation_hold_for_source', $map, 10, 2 );
$guest = alloc_buy( $type_c, 2, 'gast@example.com', array( 'source' => 'guestlist', 'key_namespace' => 'guestlist', 'skip_rate_limit' => true ) );
remove_filter( 'wpcap_allocation_hold_for_source', $map, 10 );
check( 'guest list consumes the mapped hold (source → hold filter)', ! is_wp_error( $guest ) && 2 === (int) Holds::get( $press->hold_id )->used );
$report = Holds::report( $occ );
$artist_report = array_values( array_filter( $report['holds'], static fn( $e ) => (int) $e['hold']->hold_id === (int) $artist->hold_id ) )[0];
check( 'report shows usage per channel', array( 'admin' => 3, 'pos' => 2 ) === $artist_report['by_source'] && 5 === $artist_report['open'] && count( $report['orders'] ) >= 3 );

echo "Allocations: returns, release and auto-release\n";
Orders::cancel_order( $g['order_id'], 'test' );
$artist = Holds::get( $artist->hold_id );
check( 'cancelled hold order returns its units to the hold', 2 === (int) $artist->used && 8 === Holds::open_units( $artist ) && 1 === Orders::availability( $type_a ) );
$pos_core = $pos['order_id'];
expire_holds_of( $pos_core );
check( 'lapsed hold order is not counted against the hold before cleanup', 10 === Holds::open_units( $artist ) );
Orders::expire_holds();
check( 'expiry job releases the usage', 0 === (int) Holds::get( $artist->hold_id )->used && 'released' === $wpdb->get_var( 'SELECT status FROM ' . Holds::table( 'usage' ) . " WHERE order_id=$pos_core" ) );
$increased = array();
$rel = Holds::release( (int) $artist->hold_id, 4 );
check( 'partial release returns places to public sale', ! is_wp_error( $rel ) && 4 === $rel['released'] && 6 === (int) Holds::get( $artist->hold_id )->quantity && 5 === Orders::availability( $type_a ) );
check( 'release fires wpcap_capacity_increased for every active type of the occurrence', array( array( $type_a, $occ ), array( $type_b, $occ ), array( $type_c, $occ ) ) === $increased );
$increased = array();
$rel = Holds::release( (int) $press->hold_id, null );
$press = Holds::get( $press->hold_id );
check( 'full release closes the hold, used units stay sold', ! is_wp_error( $rel ) && 'released' === $press->status && 2 === (int) $press->quantity && 3 === $rel['released'] );
check( 'type hold release fires wpcap_capacity_increased for its type only', array( array( $type_c, $occ ) ) === $increased );
check( 'released hold cannot be used any more', is_wp_error( alloc_buy( $type_c, 1, 'gast2@example.com', array( 'source' => 'guestlist', 'key_namespace' => 'guestlist', 'skip_rate_limit' => true, 'allocation_id' => (int) $press->hold_id ) ) ) );
Orders::cancel_order( $guest['order_id'], 'test' );
$press = Holds::get( $press->hold_id );
check( 'units returned to a released hold go to public sale', 0 === (int) $press->quantity && 0 === (int) $press->used );
check( 'kill still blocks after other releases', 4 === Holds::open_units( $kill ) );
$auto_type = make_type( 0, 10, 2500, 20 );
$auto_occ = alloc_occ( $auto_type );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 47 * HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $auto_occ ) );
$auto48 = Holds::create( array( 'occurrence_id' => $auto_occ, 'name' => 'Sponsor', 'quantity' => 5 ) );
$auto24 = Holds::create( array( 'occurrence_id' => $auto_occ, 'name' => 'Presse', 'quantity' => 3, 'release_hours' => 24 ) );
$auto_kill = Holds::create( array( 'occurrence_id' => $auto_occ, 'name' => 'Sperre', 'kind' => 'kill', 'quantity' => 2 ) );
check( 'before auto-release 10 places are held', 10 === Orders::availability( $auto_type ) );
$increased = array();
do_action( 'wpcap_cleanup_reservations' );
check( 'cron releases the hold within its 48 h window only', 'released' === Holds::get( $auto48->hold_id )->status && 'active' === Holds::get( $auto24->hold_id )->status && 'active' === Holds::get( $auto_kill->hold_id )->status );
check( 'auto-release restores public availability and notifies', 15 === Orders::availability( $auto_type ) && in_array( array( $auto_type, $auto_occ ), $increased, true ) );
check( 'cron is idempotent', 0 === Holds::auto_release_due() );
check( 'kill can be lifted manually', ! is_wp_error( Holds::release( (int) $auto_kill->hold_id ) ) && 17 === Orders::availability( $auto_type ) );

echo "Allocations: late payment recovery respects holds\n";
$late_type = make_type( 0, 10, 2500, 5 );
$late = alloc_buy( $late_type, 2, 'late@example.com' );
expire_holds_of( $late['order_id'] );
$block = Holds::create( array( 'occurrence_id' => alloc_occ( $late_type ), 'name' => 'Sponsor', 'quantity' => 4 ) );
$confirm = Orders::confirm_external_payment( $late['order_id'], 'late-ref' );
check( 'late payment cannot take held places (needs review)', is_object( $block ) && is_wp_error( $confirm ) && 'needs_review' === Orders::get( $late['order_id'] )->status );
$late_hold = Holds::create( array( 'occurrence_id' => alloc_occ( $late_type ), 'name' => 'Gäste', 'quantity' => 1 ) );
$late2_type = make_type( 0, 10, 2500, 5 );
$hold2 = Holds::create( array( 'occurrence_id' => alloc_occ( $late2_type ), 'name' => 'Gäste', 'quantity' => 5 ) );
$late2 = Holds::create_order( (int) $hold2->hold_id, array( 'email' => 'g@example.com', 'items' => array( array( 'ticket_type_id' => $late2_type, 'quantity' => 2 ) ), 'idempotency_key' => 'late2' ) );
expire_holds_of( $late2['order_id'] );
Orders::expire_holds();
check( 'expired hold order gave its units back', 0 === (int) Holds::get( $hold2->hold_id )->used );
$wpdb->update( "{$p}wpcap_orders", array( 'status' => 'pending_payment', 'payment_status' => 'pending' ), array( 'order_id' => $late2['order_id'] ) );
$confirm2 = Orders::confirm_external_payment( $late2['order_id'], 'late-ref-2' );
check( 'late payment re-consumes the hold', true === $confirm2 && 2 === (int) Holds::get( $hold2->hold_id )->used && 'active' === $wpdb->get_var( 'SELECT status FROM ' . Holds::table( 'usage' ) . ' WHERE order_id=' . (int) $late2['order_id'] ) );

echo "Allocations: seat holds\n";
$seat_type = make_type( 0, 10, 3000, 0 );
$seat_occ = alloc_occ( $seat_type );
$seat_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$seat_occ" );
$GLOBALS['wpcap_test_user_id'] = 1;
$layout = SeatingAdmin::create( array( 'event_id' => $seat_event, 'name' => 'Saal' ) );
$seats_def = array();
foreach ( array( 1, 2, 3, 4, 5 ) as $n ) { $seats_def[] = array( 'key' => 'r' . $n, 'group' => 'g', 'row' => 'Reihe 1', 'label' => (string) $n, 'x' => 30 * $n, 'y' => 50, 'zone' => 'parkett', 'category' => 'kat1' ); }
$draft = array( 'width' => 600, 'height' => 300, 'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett', 'color' => '#2271b1' ) ), 'categories' => array( array( 'key' => 'kat1', 'name' => 'Kat 1', 'color' => '#00a32a' ) ), 'groups' => array( array( 'key' => 'g', 'type' => 'row', 'label' => '' ) ), 'seats' => $seats_def );
$pub = SeatingAdmin::save( array( 'layout_id' => $layout['layout_id'], 'draft' => json_encode( $draft ), 'do' => 'publish', 'upgrade_links' => '1' ) );
$link = SeatingAdmin::link( array( 'occurrence_id' => $seat_occ, 'version_id' => $pub['version_id'], 'zones' => array( $seat_type => array( 'parkett' ) ) ) );
$sid = array();
foreach ( array( 'r1', 'r2', 'r3', 'r4', 'r5' ) as $key ) { $sid[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seat_id FROM ' . Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s', $pub['version_id'], $key ) ); }
$seat_hold = Holds::create( array( 'occurrence_id' => $seat_occ, 'ticket_type_id' => $seat_type, 'name' => 'Presse', 'quantity' => 1 ) );
$added = AllocAdmin::seats( array( 'hold_id' => $seat_hold->hold_id, 'seats' => 'r1, r2', 'do' => 'add' ) );
$GLOBALS['wpcap_test_user_id'] = 0;
$status = array();
foreach ( Reservations::availability( $seat_occ )['seats'] as $s ) { $status[ $s['id'] ] = $s['status']; }
check( 'seats are blocked for the hold (hold grows to the seat count)', ! is_wp_error( $added ) && 2 === $added['blocked'] && 'taken' === $status[ $sid['r1'] ] && 'taken' === $status[ $sid['r2'] ] && 'free' === $status[ $sid['r3'] ] && 2 === (int) Holds::get( $seat_hold->hold_id )->quantity );
check( 'seat hold labels are listed', array( 'Reihe 1, Platz 1', 'Reihe 1, Platz 2' ) === SeatHolds::seat_labels( $seat_hold->hold_id ) );
$dup = SeatHolds::add( (int) $seat_hold->hold_id, array( $sid['r2'] ) );
check( 'an already blocked seat cannot be blocked twice', is_wp_error( $dup ) );
$pub_seat = alloc_buy( $seat_type, 1, 'seat@example.com', array(), array( 'seats' => array( $seat_type => array( $sid['r1'] ) ) ) );
check( 'public checkout cannot pick a held seat', is_wp_error( $pub_seat ) );
Reservations::release_stale( $seat_occ );
Reservations::cleanup();
check( 'Seating cleanup keeps hold seats blocked', 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Layouts::table( 'claims' ) . ' WHERE occurrence_id=%d AND order_id=0 AND active_key IS NOT NULL', $seat_occ ) ) );
$seat_guest = Holds::create_order( (int) $seat_hold->hold_id, array( 'email' => 'presse@example.com', 'items' => array( array( 'ticket_type_id' => $seat_type, 'quantity' => 1 ) ), 'idempotency_key' => 'seat-guest' ) );
$guest_seat = $wpdb->get_var( $wpdb->prepare( 'SELECT seat_key FROM ' . Layouts::table( 'claims' ) . ' WHERE order_id=%d AND active_key IS NOT NULL', $seat_guest['order_id'] ?? 0 ) );
check( 'trusted order from a seat hold gets a held seat', ! is_wp_error( $seat_guest ) && 'r1' === $guest_seat );
check( 'the other held seat stays blocked', 1 === count( SeatHolds::held( $seat_hold->hold_id ) ) && 'r2' === SeatHolds::held( $seat_hold->hold_id )[0]->seat_key );
$pub_seat = alloc_buy( $seat_type, 1, 'seat@example.com', array(), array( 'seats' => array( $seat_type => array( $sid['r3'] ) ) ) );
check( 'public can buy a free seat', ! is_wp_error( $pub_seat ) );
$rel = Holds::release( (int) $seat_hold->hold_id );
$status = array();
foreach ( Reservations::availability( $seat_occ )['seats'] as $s ) { $status[ $s['id'] ] = $s['status']; }
check( 'releasing the hold frees its remaining seat', ! is_wp_error( $rel ) && 1 === $rel['seats'] && 'free' === $status[ $sid['r2'] ] && 'taken' === $status[ $sid['r1'] ] );
$kill_seats = Holds::create( array( 'occurrence_id' => $seat_occ, 'name' => 'Kamera', 'kind' => 'kill', 'quantity' => 1 ) );
SeatHolds::add( (int) $kill_seats->hold_id, array( $sid['r4'], $sid['r5'] ) );
$removed = SeatHolds::remove( (int) $kill_seats->hold_id, array( $sid['r5'] ) );
check( 'removing a seat from a kill frees it and shrinks the kill', ! is_wp_error( $removed ) && 1 === $removed['released'] && 1 === (int) Holds::get( $kill_seats->hold_id )->quantity && 1 === count( SeatHolds::held( $kill_seats->hold_id ) ) );

echo "Allocations: per-buyer limits\n";
$lim_a = make_type( 0, 10, 2500, 100 );
$lim_occ = alloc_occ( $lim_a );
$lim_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$lim_occ" );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $lim_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 100, 'status' => 'scheduled' ) );
$lim_occ2 = (int) $wpdb->insert_id;
$lim_b = alloc_add_type( $lim_occ2, 'Zweiter Termin' );
$lim_free = alloc_add_type( $lim_occ, 'Gratis', 0, 0 );
check( 'limits are saved via the admin action', true === alloc_admin( static fn() => AllocAdmin::limits( array( 'event_id' => $lim_event, 'limit_event' => 4, 'limit_type' => array( $lim_free => 1 ) ) ) ) && array( 0 => 4, $lim_free => 1 ) === BuyerLimits::for_event( $lim_event ) );
$o1 = alloc_buy( $lim_a, 3, 'Max.Muster@Example.com' );
check( 'first order within the limit', ! is_wp_error( $o1 ) );
$o2 = alloc_buy( $lim_b, 2, 'max.muster@example.com' );
check( 'second order over the event limit (other occurrence, other case) is rejected with the German message', is_wp_error( $o2 ) && 'Pro Person sind höchstens 4 Tickets für dieses Event möglich; du hast bereits 3.' === $o2->get_error_message() );
check( 'nothing was reserved for the rejected order', 97 === Orders::availability( $lim_a ) && 100 === Orders::availability( $lim_b ) );
check( 'remaining quantity is allowed', ! is_wp_error( alloc_buy( $lim_b, 1, 'max.muster@example.com' ) ) );
check( 'buyer count across orders is 4', 4 === BuyerLimits::count_for( $lim_event, 'MAX.MUSTER@example.com' ) );
$f1 = alloc_buy( $lim_free, 1, 'free@example.com' );
$f2 = alloc_buy( $lim_free, 1, 'free@example.com' );
check( 'per-type limit counts paid tickets', ! is_wp_error( $f1 ) && 'complete' === $f1['status'] && is_wp_error( $f2 ) && false !== strpos( $f2->get_error_message(), 'Ticketart „Gratis“' ) && false !== strpos( $f2->get_error_message(), 'bereits 1' ) );
expire_holds_of( $o1['order_id'] );
check( 'expired holds no longer count', ! is_wp_error( alloc_buy( $lim_a, 2, 'max.muster@example.com' ) ) );
$c1 = alloc_buy( $lim_a, 4, 'cancel@example.com' );
Orders::cancel_order( $c1['order_id'], 'test' );
check( 'cancelled orders no longer count', ! is_wp_error( alloc_buy( $lim_a, 4, 'cancel@example.com' ) ) );
$pos_lim = alloc_buy( $lim_a, 6, 'cancel@example.com', array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true, 'external' => true ) );
$admin_lim = alloc_buy( $lim_a, 6, 'cancel@example.com', array( 'key_namespace' => 'admin', 'source' => 'admin', 'skip_rate_limit' => true ) );
check( 'box office and admin are exempt', ! is_wp_error( $pos_lim ) && ! is_wp_error( $admin_lim ) );
$unexempt = static function () { return array(); };
add_filter( 'wpcap_allocation_limit_exempt_sources', $unexempt );
$pos_lim = alloc_buy( $lim_a, 1, 'cancel@example.com', array( 'key_namespace' => 'pos', 'source' => 'pos', 'skip_rate_limit' => true, 'external' => true ) );
remove_filter( 'wpcap_allocation_limit_exempt_sources', $unexempt );
check( 'exemptions are configurable by filter', is_wp_error( $pos_lim ) );
$gmail = static function () { return true; };
add_filter( 'wpcap_allocation_normalize_gmail', $gmail );
check( 'gmail normalisation (dots, plus tag, googlemail)', BuyerLimits::normalize_email( 'Max.Muster+tickets@googlemail.com' ) === 'maxmuster@gmail.com' );
$gm1 = alloc_buy( $lim_a, 4, 'jane.doe@gmail.com' );
$gm2 = alloc_buy( $lim_a, 1, 'janedoe+2@gmail.com' );
remove_filter( 'wpcap_allocation_normalize_gmail', $gmail );
check( 'gmail aliases count as one buyer when enabled', ! is_wp_error( $gm1 ) && is_wp_error( $gm2 ) );
check( 'without the filter aliases are separate buyers', ! is_wp_error( alloc_buy( $lim_a, 1, 'janedoe+3@gmail.com' ) ) );
$GLOBALS['wpcap_test_user_id'] = 77;
$u1 = alloc_buy( $lim_a, 3, 'user-a@example.com' );
$u2 = alloc_buy( $lim_a, 2, 'user-b@example.com' );
$GLOBALS['wpcap_test_user_id'] = 0;
check( 'logged-in user is limited across e-mail addresses', ! is_wp_error( $u1 ) && is_wp_error( $u2 ) );
$wc1 = make_wc_order( 7102, $lim_a, 3 );
$wc1->email = 'woo-limit@example.com';
$wc2 = make_wc_order( 7103, $lim_b, 2 );
$wc2->email = 'woo-limit@example.com';
$w1 = Woo::reserve( $wc1 );
$w2 = Woo::reserve( $wc2 );
check( 'WooCommerce checkout is limited through core order creation', is_int( $w1 ) && is_wp_error( $w2 ) && false !== strpos( $w2->get_error_message(), 'höchstens 4' ) );
check( 'WooCommerce retry of the same order is not blocked by its previous attempt', is_int( Woo::reserve( $wc1 ) ) );
ob_start();
BuyerLimits::render_notice( (object) array( 'event_id' => $lim_event, 'occurrence_id' => $lim_occ ), array( (object) array( 'ticket_type_id' => $lim_free, 'name' => 'Gratis' ) ) );
$notice = ob_get_clean();
check( 'event card shows the limit', false !== strpos( $notice, 'Max. 4 Tickets pro Person' ) && false !== strpos( $notice, 'Gratis: max. 1 pro Person' ) );

echo "Allocations: per-buyer limit is race-free\n";
$other = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$lock_key = BuyerLimits::keys( 'locked@example.com' )[0];
$other->query( 'START TRANSACTION' );
$other->query( 'INSERT INTO ' . Holds::table( 'buyers' ) . " (event_id,buyer_key,holdings,updated_at) VALUES ($lim_event,'$lock_key','{}',NOW()) ON DUPLICATE KEY UPDATE updated_at=NOW()" );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=1' );
$blocked = alloc_buy( $lim_a, 1, 'locked@example.com' );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=50' );
$other->query( 'ROLLBACK' );
check( 'order waits for the buyer row lock held by another connection', is_wp_error( $blocked ) && ! empty( $blocked->get_error_data()['retryable'] ) );
check( 'after the other transaction ends the order goes through', ! is_wp_error( alloc_buy( $lim_a, 1, 'locked@example.com' ) ) );
$other->close();
alloc_admin( static fn() => AllocAdmin::limits( array( 'event_id' => $lim_event, 'limit_event' => 3 ) ) );
$start = sprintf( '%.3f', microtime( true ) + 1.5 );
$procs = array();
foreach ( array( $lim_a, $lim_b, $lim_a, $lim_b ) as $type_id ) {
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --alloc-child ' . (int) $type_id . ' race@example.com ' . $start . ' 2>&1', 'r' );
}
$ok = 0;
foreach ( $procs as $proc ) { $ok += 'OK' === trim( stream_get_contents( $proc ) ) ? 1 : 0; pclose( $proc ); }
check( "parallel orders of one buyer on two occurrences: exactly one of 4 (2 tickets each, limit 3) succeeds (got $ok)", 1 === $ok && 2 === BuyerLimits::count_for( $lim_event, 'race@example.com' ) );

echo "Allocations: admin access\n";
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = AllocAdmin::create( array( 'occurrence_id' => $occ, 'name' => 'X', 'quantity' => 1 ) );
$denied_release = AllocAdmin::release( array( 'hold_id' => $artist->hold_id, 'all' => 1 ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'admin actions require the event capability', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] && is_wp_error( $denied_release ) );
$created = AllocAdmin::create( array( 'occurrence_id' => $occ, 'name' => 'Sponsor', 'quantity' => 1, 'sources' => array( 'guestlist', 'bogus' ), 'release_hours' => '' ) );
check( 'admin create keeps only trusted sources', is_object( $created ) && 'guestlist' === $created->sources );
$upd = AllocAdmin::update( array( 'hold_id' => $created->hold_id, 'add' => 1, 'release_hours' => 12 ) );
check( 'admin update enlarges the hold and sets auto-release', is_object( $upd ) && 2 === (int) $upd->quantity && 12 === (int) $upd->release_hours );

// The seating tests (later in the suite) expect an empty claims table.
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Layouts::table( 'ticket_seats' ) . ' WHERE occurrence_id=%d', $seat_occ ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Layouts::table( 'claims' ) . ' WHERE occurrence_id=%d', $seat_occ ) );

$GLOBALS['wpcap_test_user_id'] = $alloc_user_before;
if ( null === $alloc_user_before ) { unset( $GLOBALS['wpcap_test_user_id'] ); }
