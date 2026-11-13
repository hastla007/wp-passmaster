<?php
/**
 * Integration tests for the seating module (editor versions, seat holds, assignment, release).
 * Included from run.php. Also runnable as a concurrency worker:
 *   php seating.php --seating-child <ticket_type_id> <seat_id>
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Seating;
use PassCaptain\Orders;
use PassCaptain\Seating\AdminPage as SeatingAdmin;
use PassCaptain\Seating\Layouts;
use PassCaptain\Seating\Reservations;

if ( PHP_SAPI === 'cli' && isset( $argv ) && in_array( '--seating-child', $argv, true ) && realpath( $argv[0] ) === __FILE__ ) {
	require_once dirname( __DIR__ ) . '/bootstrap.php';
	Seating::register();
	$r = Orders::create( array( 'email' => 'seat' . getmypid() . '@example.com', 'items' => array( array( 'ticket_type_id' => (int) $argv[2], 'quantity' => 1 ) ), 'seats' => array( (int) $argv[2] => array( (int) $argv[3] ) ), 'idempotency_key' => 'seat-child-' . getmypid() ) );
	echo is_wp_error( $r ) ? 'ERR' : 'OK';
	exit;
}

if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) { $GLOBALS['wpcap_test_enqueued'][] = $a[0]; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) { $GLOBALS['wpcap_test_enqueued'][] = $a[0]; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) { $GLOBALS['wpcap_test_routes'][ $ns . $route ] = $args; return true; } }
if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }

Seating::register();
global $wpdb;
$p = $wpdb->prefix;

function seating_order( array $items, $seats = null, $email = 'seat@example.com', array $internal = array() ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$payload = array( 'email' => $email, 'items' => $items, 'idempotency_key' => 'seat-' . uniqid( '', true ) );
	if ( null !== $seats ) { $payload['seats'] = $seats; }
	return Orders::create( $payload, $internal );
}
function seating_seat( $version_id, $key ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seat_id FROM ' . Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s', $version_id, $key ) );
}
function seating_status( $occurrence_id ) {
	$out = array();
	foreach ( Reservations::availability( $occurrence_id )['seats'] ?? array() as $seat ) { $out[ $seat['id'] ] = $seat['status']; }
	return $out;
}
function seating_draft() {
	$seats = array();
	foreach ( array( 1, 2, 3, 4 ) as $n ) { $seats[] = array( 'key' => 'a' . $n, 'group' => 'ga', 'row' => 'Reihe A', 'label' => (string) $n, 'x' => 40 + 30 * $n, 'y' => 60, 'zone' => 'parkett', 'category' => 'kat1', 'accessible' => 4 === $n ); }
	foreach ( array( 1, 2 ) as $n ) { $seats[] = array( 'key' => 'b' . $n, 'group' => 'gb', 'row' => 'Reihe B', 'label' => (string) $n, 'x' => 40 + 30 * $n, 'y' => 200, 'zone' => 'balkon', 'category' => 'kat1' ); }
	return array(
		'width' => 800, 'height' => 500,
		'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett', 'color' => '#2271b1' ), array( 'key' => 'balkon', 'name' => 'Balkon', 'color' => '#d63638' ), array( 'key' => 'steh', 'name' => 'Stehplätze', 'color' => '#00a32a' ) ),
		'categories' => array( array( 'key' => 'kat1', 'name' => 'Kategorie 1', 'color' => '#00a32a' ) ),
		'groups' => array( array( 'key' => 'ga', 'type' => 'row', 'label' => '' ), array( 'key' => 'gb', 'type' => 'row', 'label' => '' ), array( 'key' => 'st', 'type' => 'standing', 'label' => 'Stehbereich', 'x' => 400, 'y' => 300, 'w' => 200, 'h' => 100, 'capacity' => 3, 'zone' => 'steh' ) ),
		'seats' => $seats,
	);
}

echo "Seating: editor, versions and sold-seat protection\n";
$type_p = make_type( 20, 10, 3000 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type_p" );
$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Balkon', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active' ) );
$type_b = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Stehplatz', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active' ) );
$type_s = (int) $wpdb->insert_id;

$created = SeatingAdmin::create( array( 'event_id' => $event, 'name' => 'Großer Saal' ) );
check( 'layout is created with an empty draft', ! is_wp_error( $created ) && $created['layout_id'] > 0 );
$layout_id = $created['layout_id'];
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = SeatingAdmin::save( array( 'layout_id' => $layout_id, 'draft' => json_encode( seating_draft() ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'saving without capability is forbidden', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$bad = Layouts::save_draft( $layout_id, array( 'seats' => array( array( 'key' => 'x', 'label' => '1' ), array( 'key' => 'x', 'label' => '2' ) ) ) );
check( 'duplicate seat keys are rejected', is_wp_error( $bad ) && 'wpcap_seating_seat_key' === $bad->get_error_code() );
$saved = SeatingAdmin::save( array( 'layout_id' => $layout_id, 'draft' => json_encode( seating_draft() ), 'revision' => 0, 'do' => 'save' ) );
check( 'draft is saved with seats and a standing area (9 places)', ! is_wp_error( $saved ) && 9 === $saved['seat_count'] && 1 === $saved['revision'] );
$stale = Layouts::save_draft( $layout_id, seating_draft(), 5 );
check( 'stale editor revision is rejected', is_wp_error( $stale ) && 'wpcap_seating_conflict' === $stale->get_error_code() );
check( 'nothing is published by saving a draft', ! Layouts::versions( $layout_id ) );
$pub = SeatingAdmin::save( array( 'layout_id' => $layout_id, 'draft' => json_encode( seating_draft() ), 'do' => 'publish', 'upgrade_links' => '1' ) );
check( 'publishing creates version 1', ! is_wp_error( $pub ) && 1 === $pub['version'] && 9 === $pub['seat_count'] );
$v1 = $pub['version_id'];
$seat = array();
foreach ( array( 'a1', 'a2', 'a3', 'a4', 'b1', 'b2', 'st-1', 'st-2', 'st-3' ) as $key ) { $seat[ $key ] = seating_seat( $v1, $key ); }
$a1 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Layouts::table( 'seats' ) . ' WHERE seat_id=%d', $seat['a1'] ) );
check( 'seat snapshot has display label, zone and position', 'Reihe A, Platz 1' === $a1->label && 'parkett' === $a1->zone && 70.0 === (float) $a1->pos_x );
check( 'accessibility flag is stored', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_accessible FROM ' . Layouts::table( 'seats' ) . ' WHERE seat_id=%d', $seat['a4'] ) ) );

$bad_link = SeatingAdmin::link( array( 'occurrence_id' => $occ, 'version_id' => $v1, 'zones' => array( $type_p => array( 'nope' ) ) ) );
check( 'linking with an unknown zone is rejected', is_wp_error( $bad_link ) );
$link = SeatingAdmin::link( array( 'occurrence_id' => $occ, 'version_id' => $v1, 'zones' => array( $type_p => array( 'parkett' ), $type_b => array( 'balkon' ), $type_s => array( 'steh' ) ) ) );
check( 'occurrence is linked to version 1 with zone mapping', ! is_wp_error( $link ) && array( $type_p => array( 'parkett' ), $type_b => array( 'balkon' ), $type_s => array( 'steh' ) ) === Layouts::type_zones( $occ ) );

echo "Seating: availability endpoint\n";
$avail = Reservations::availability( $occ );
$json = json_encode( $avail );
check( 'availability lists all places as free', 9 === count( $avail['seats'] ) && ! in_array( 'taken', seating_status( $occ ), true ) );
$rest = PassCaptain\Seating\Rest::availability( new WP_REST_Request( 'GET', '/seating' ) );
check( 'endpoint returns 404 for occurrences without a seat map', is_wp_error( $rest ) && 404 === $rest->get_error_data()['status'] );
$req = new WP_REST_Request( 'GET', '/seating' );
$req->set_param( 'occurrence_id', $occ );
$rest = PassCaptain\Seating\Rest::availability( $req );
check( 'endpoint returns the seat map', ! is_wp_error( $rest ) && 9 === count( $rest->get_data()['seats'] ) );
ob_start();
PassCaptain\Seating\Picker::render( (object) array( 'occurrence_id' => $occ ), array() );
$html = ob_get_clean();
check( 'checkout renders the picker container for linked occurrences', false !== strpos( $html, 'wpcap-seating' ) && false !== strpos( $html, 'seating/occurrences/' . $occ ) );
check( 'payload key "seats" is allowed', in_array( 'seats', apply_filters( 'wpcap_checkout_payload_keys', array( 'email' ) ), true ) );

echo "Seating: atomic holds\n";
$orders_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 2 ) ), array( $type_p => array( $seat['a1'] ) ) );
check( 'quantity mismatch (2 tickets, 1 seat) is rejected', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Anzahl der gewählten Sitzplätze' ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 1 ) ) );
check( 'public checkout without seat selection is rejected', is_wp_error( $r ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 1 ) ), array( $type_p => array( $seat['b1'] ) ) );
check( 'zone mismatch (balcony seat for parquet ticket) is rejected', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Bereich' ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 1 ), array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), array( $type_p => array( $seat['a1'] ), $type_b => array( $seat['a2'] ) ) );
check( 'mixed order with one zone mismatch is rejected as a whole', is_wp_error( $r ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 1 ) ), array( $type_p => array( 999999 ) ) );
check( 'unknown seat id is rejected', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'nicht mehr verfügbar' ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 1 ) ), 'a1' );
check( 'malformed selection is rejected', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'ungültig' ) );
check( 'rejected checkouts leave no order and no claim behind', $orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders" ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Layouts::table( 'claims' ) ) );

$o1 = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 2 ) ), array( $type_p => array( $seat['a1'], $seat['a2'] ) ), 'one@example.com' );
check( 'order with matching seats holds them', ! is_wp_error( $o1 ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Layouts::table( 'claims' ) . " WHERE order_id=%d AND status='held'", $o1['order_id'] ) ) );
$st = seating_status( $occ );
check( 'held seats are shown as taken', 'taken' === $st[ $seat['a1'] ] && 'taken' === $st[ $seat['a2'] ] && 'free' === $st[ $seat['a3'] ] );
check( 'availability contains no personal or order data', false === strpos( json_encode( Reservations::availability( $occ ) ), 'example.com' ) && false === strpos( json_encode( Reservations::availability( $occ ) ), 'order' ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 1 ) ), array( $type_p => array( $seat['a1'] ) ), 'two@example.com' );
check( 'a held seat cannot be bought twice', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'nicht mehr verfügbar' ) );
$r = seating_order( array( array( 'ticket_type_id' => $type_p, 'quantity' => 2 ) ), array( array( 'ticket_type_id' => $type_p, 'seat_id' => $seat['a3'] ), array( 'ticket_type_id' => $type_p, 'seat_id' => $seat['a1'] ) ), 'two@example.com' );
check( 'partially unavailable selection holds nothing', is_wp_error( $r ) && 'free' === seating_status( $occ )[ $seat['a3'] ] );

echo "Seating: parallel checkouts for the same seat\n";
$procs = array();
for ( $i = 0; $i < 8; $i++ ) {
	$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --seating-child ' . $type_p . ' ' . $seat['a3'], 'r' );
}
$ok = 0;
foreach ( $procs as $proc ) { $ok += 'OK' === trim( stream_get_contents( $proc ) ) ? 1 : 0; pclose( $proc ); }
$claims_a3 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Layouts::table( 'claims' ) . ' WHERE occurrence_id=%d AND seat_key=%s AND active_key IS NOT NULL', $occ, 'a3' ) );
check( "exactly 1 of 8 parallel buyers gets seat A3 (got $ok, claims $claims_a3)", 1 === $ok && 1 === $claims_a3 );

echo "Seating: editor protection of held/sold seats\n";
$moved = seating_draft();
$moved['seats'][0]['x'] = 500;
$r = Layouts::save_draft( $layout_id, $moved );
check( 'moving a held seat is rejected', is_wp_error( $r ) && 'wpcap_seating_locked_seat' === $r->get_error_code() && in_array( 'a1', $r->get_error_data()['seat_keys'], true ) );
$deleted = seating_draft();
array_shift( $deleted['seats'] );
check( 'deleting a held seat is rejected', is_wp_error( Layouts::save_draft( $layout_id, $deleted ) ) );
$relabel = seating_draft();
$relabel['seats'][1]['label'] = '99';
check( 'relabelling a held seat is rejected', is_wp_error( Layouts::save_draft( $layout_id, $relabel ) ) );
$rezone = seating_draft();
$rezone['seats'][1]['zone'] = 'balkon';
check( 'rezoning a held seat is rejected', is_wp_error( Layouts::save_draft( $layout_id, $rezone ) ) );
$free_move = seating_draft();
$free_move['seats'][3]['x'] = 600; // a4 is free.
$free_move['seats'][] = array( 'key' => 'a5', 'group' => 'ga', 'row' => 'Reihe A', 'label' => '5', 'x' => 220, 'y' => 60, 'zone' => 'parkett', 'category' => 'kat1' );
$r = Layouts::save_draft( $layout_id, $free_move );
check( 'free seats can be moved and seats added', ! is_wp_error( $r ) );
$pub2 = Layouts::publish( $layout_id, true );
check( 'publishing creates version 2 and upgrades the linked occurrence', ! is_wp_error( $pub2 ) && 2 === $pub2['version'] && in_array( $occ, $pub2['upgraded'], true ) && (int) Layouts::link( $occ )->version_id === $pub2['version_id'] );
$v2 = $pub2['version_id'];
$v1_a4 = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT pos_x FROM ' . Layouts::table( 'seats' ) . ' WHERE seat_id=%d', $seat['a4'] ) );
$v2_a4 = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT pos_x FROM ' . Layouts::table( 'seats' ) . ' WHERE seat_id=%d', seating_seat( $v2, 'a4' ) ) );
check( 'version 1 seats are untouched (immutable snapshot)', 9 === count( Layouts::seats( $v1 ) ) && 160.0 === $v1_a4 && 600.0 === $v2_a4 && 10 === count( Layouts::seats( $v2 ) ) );
$st = seating_status( $occ );
check( 'held seats stay taken after the upgrade (matched by seat key)', 'taken' === $st[ seating_seat( $v2, 'a1' ) ] && 'free' === $st[ seating_seat( $v2, 'a5' ) ] );
$lone = Layouts::create( $event, 'Anderer Saal' );
$other = seating_draft();
array_shift( $other['seats'] );
Layouts::save_draft( $lone['layout_id'], $other );
$other_pub = Layouts::publish( $lone['layout_id'] );
$r = Layouts::link_occurrence( $occ, $other_pub['version_id'], array() );
check( 'relinking to a version without a held seat is rejected', is_wp_error( $r ) && 'wpcap_seating_locked_seat' === $r->get_error_code() );
check( 'unlinking is refused while seats are held', is_wp_error( Layouts::unlink_occurrence( $occ ) ) );

echo "Seating: payment, issuance and ticket mail\n";
$GLOBALS['wpcap_test_mails'] = array();
Orders::confirm_manual_payment( $o1['order_id'] );
$assigned = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Layouts::table( 'ticket_seats' ) . ' WHERE order_id=%d ORDER BY ticket_id', $o1['order_id'] ) );
$tickets = tickets( $o1['order_id'], 'valid' );
check( 'paid order: claims sold and each ticket gets one seat', 2 === count( $tickets ) && 2 === count( $assigned ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Layouts::table( 'claims' ) . " WHERE order_id=%d AND status<>'sold'", $o1['order_id'] ) ) );
check( 'seat assignment refers to the issued tickets', (int) $assigned[0]->ticket_id === (int) $tickets[0]->ticket_id && 'Reihe A, Platz 1' === $assigned[0]->label );
$mail = end( $GLOBALS['wpcap_test_mails'] );
check( 'filter wpcap_ticket_seat_label returns the assigned seat', 'Reihe A, Platz 2' === apply_filters( 'wpcap_ticket_seat_label', '', (int) $tickets[1]->ticket_id, array() ) );
check( 'ticket mail lists the seats', $mail && false !== strpos( $mail['body'], 'Deine Sitzplätze' ) && false !== strpos( $mail['body'], 'Reihe A, Platz 1' ) && false !== strpos( $mail['body'], 'Reihe A, Platz 2' ) );

echo "Seating: release on revoke, refund, cancel and expiry\n";
$revoked_id = (int) $tickets[0]->ticket_id;
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id=$revoked_id" );
do_action( 'wpcap_ticket_revoked', $revoked_id, 'test' );
check( 'revoked ticket frees its seat', 'free' === seating_status( $occ )[ seating_seat( $v2, 'a1' ) ] && 'released' === $wpdb->get_var( 'SELECT status FROM ' . Layouts::table( 'ticket_seats' ) . " WHERE ticket_id=$revoked_id" ) );
check( 'the other ticket keeps its seat', 'taken' === seating_status( $occ )[ seating_seat( $v2, 'a2' ) ] );
$refund_ticket = (int) $tickets[1]->ticket_id;
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked',refunded=1 WHERE ticket_id=$refund_ticket" );
do_action( 'wpcap_refund_completed', 1, $o1['order_id'] );
check( 'refunded ticket frees its seat', 'free' === seating_status( $occ )[ seating_seat( $v2, 'a2' ) ] );

$o2 = seating_order( array( array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), array( $type_b => array( seating_seat( $v2, 'b1' ) ) ), 'cancel@example.com' );
check( 'balcony seat held', ! is_wp_error( $o2 ) && 'taken' === seating_status( $occ )[ seating_seat( $v2, 'b1' ) ] );
Orders::cancel_order( $o2['order_id'], 'test' );
check( 'cancelled order releases its seat', 'free' === seating_status( $occ )[ seating_seat( $v2, 'b1' ) ] && 'order_cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT release_reason FROM ' . Layouts::table( 'claims' ) . ' WHERE order_id=%d', $o2['order_id'] ) ) );

$o3 = seating_order( array( array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), array( $type_b => array( seating_seat( $v2, 'b2' ) ) ), 'late@example.com' );
expire_holds_of( $o3['order_id'] );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Layouts::table( 'claims' ) . ' SET expires_at=%s WHERE order_id=%d', gmdate( 'Y-m-d H:i:s', time() - 60 ), $o3['order_id'] ) );
check( 'expired hold no longer blocks the seat (lazy)', 'free' === seating_status( $occ )[ seating_seat( $v2, 'b2' ) ] );
Orders::expire_holds();
check( 'expiry job releases the claim', 'expired' === $wpdb->get_var( $wpdb->prepare( 'SELECT release_reason FROM ' . Layouts::table( 'claims' ) . ' WHERE order_id=%d', $o3['order_id'] ) ) );
Orders::confirm_external_payment( $o3['order_id'], 'late' );
$late = tickets( $o3['order_id'], 'valid' );
$late_seat = $late ? $wpdb->get_var( 'SELECT seat_key FROM ' . Layouts::table( 'ticket_seats' ) . ' WHERE ticket_id=' . (int) $late[0]->ticket_id ) : null;
check( 'late payment re-claims the previous seat when still free', 1 === count( $late ) && 'b2' === $late_seat );

$o4 = seating_order( array( array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), array( $type_b => array( seating_seat( $v2, 'b1' ) ) ), 'stale@example.com' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $o4['order_id'] ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Layouts::table( 'claims' ) . ' SET expires_at=%s WHERE order_id=%d', gmdate( 'Y-m-d H:i:s', time() - 60 ), $o4['order_id'] ) );
$o5 = seating_order( array( array( 'ticket_type_id' => $type_b, 'quantity' => 1 ) ), array( $type_b => array( seating_seat( $v2, 'b1' ) ) ), 'next@example.com' );
check( 'a stale hold is taken over by the next buyer', ! is_wp_error( $o5 ) && 'stale' === $wpdb->get_var( $wpdb->prepare( 'SELECT release_reason FROM ' . Layouts::table( 'claims' ) . ' WHERE order_id=%d', $o4['order_id'] ) ) );

echo "Seating: trusted channels and standing areas\n";
$pos = seating_order( array( array( 'ticket_type_id' => $type_s, 'quantity' => 2 ) ), null, 'pos@example.com', array( 'key_namespace' => 'pos', 'skip_rate_limit' => true, 'source' => 'pos' ) );
check( 'POS order without selection gets best available standing spots', ! is_wp_error( $pos ) && array( 'st-1', 'st-2' ) === $wpdb->get_col( $wpdb->prepare( 'SELECT seat_key FROM ' . Layouts::table( 'claims' ) . ' WHERE order_id=%d ORDER BY seat_key', $pos['order_id'] ) ) );
$pos2 = seating_order( array( array( 'ticket_type_id' => $type_s, 'quantity' => 2 ) ), null, 'pos@example.com', array( 'key_namespace' => 'pos', 'skip_rate_limit' => true, 'source' => 'pos' ) );
check( 'POS order fails when not enough spots are left', is_wp_error( $pos2 ) && false !== strpos( $pos2->get_error_message(), 'keine passenden Sitzplätze' ) );
$shrink = $free_move;
$shrink['groups'][2]['capacity'] = 1;
check( 'standing area cannot shrink below held spots', is_wp_error( Layouts::save_draft( $layout_id, $shrink ) ) );
