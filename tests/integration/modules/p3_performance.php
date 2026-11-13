<?php
/**
 * Performance for large on-sales: capacity counters (sold_qty/held_qty), reconciliation, the
 * upgrade path, availability memo/object cache and the event listing without N+1 queries.
 *
 * Counters are compared with the old SUM queries (CapacityCounters::drift()) after every
 * operation; run.php additionally checks them after every test file.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\CapacityCounters;
use PassCaptain\Exchange\Service as Exchange;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Refunds;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r; } }

$pf_refund_settings = array( 'refund_approval_threshold' => Settings::get( 'refund_approval_threshold' ), 'require_2fa_for_refunds' => Settings::get( 'require_2fa_for_refunds' ) );
Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );

/** Event + occurrence (+ ticket types name => [capacity, price]); returns [occurrence_id, type ids by name]. */
function pf_occurrence( $occ_capacity, array $types, $hours = 240, $event = 0 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	if ( ! $event ) {
		$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Perf', 'slug' => 'pf' . uniqid(), 'description' => 'Beschreibung', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$event = (int) $wpdb->insert_id;
	}
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $hours * 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => $occ_capacity, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$ids = array();
	foreach ( $types as $name => list( $capacity, $price ) ) {
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'status' => 'active' ) );
		$ids[ $name ] = (int) $wpdb->insert_id;
	}
	return array( $occ, $ids );
}

function pf_buy( array $items, $email = 'pf@example.com' ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	return Orders::create( array( 'email' => $email, 'items' => $lines, 'idempotency_key' => 'pf-' . uniqid( '', true ) ) );
}

/** Counters of the given occurrences equal the SUM queries. */
function pf_consistent( $label, array $occ_ids ) {
	$drift = CapacityCounters::drift( $occ_ids );
	check( "counters = SUM after $label" . ( $drift ? ' ' . json_encode( $drift ) : '' ), ! $drift );
}

/** Availability via the old SUM path (counters disabled) for comparison. */
function pf_sum_availability( $type_id ) {
	$GLOBALS['wpcap_test_filters']['wpcap_capacity_counters_enabled'] = false;
	Orders::flush_availability_cache();
	$value = Orders::availability( $type_id );
	unset( $GLOBALS['wpcap_test_filters']['wpcap_capacity_counters_enabled'] );
	Orders::flush_availability_cache();
	return $value;
}

function pf_counter( $table, $id ) {
	global $wpdb;
	$key = 'ticket_types' === $table ? 'ticket_type_id' : 'occurrence_id';
	return $wpdb->get_row( $wpdb->prepare( "SELECT sold_qty,held_qty FROM {$wpdb->prefix}wpcap_$table WHERE $key=%d", $id ) );
}

echo "Performance: capacity counters follow every capacity change\n";
check( 'fresh install: counters are ready and used for decisions', 'ready' === CapacityCounters::state() && CapacityCounters::enabled() );
list( $occ, $t ) = pf_occurrence( 12, array( 'A' => array( 8, 2000 ), 'B' => array( 0, 1500 ), 'Free' => array( 5, 0 ) ) );
$A = $t['A']; $B = $t['B']; $F = $t['Free'];

$o1 = pf_buy( array( $A => 3, $B => 2 ) );
pf_consistent( 'create (pending, two types)', array( $occ ) );
check( 'create: holds count in held_qty of type and occurrence', 3 === (int) pf_counter( 'ticket_types', $A )->held_qty && 5 === (int) pf_counter( 'occurrences', $occ )->held_qty && 0 === (int) pf_counter( 'occurrences', $occ )->sold_qty );
$free = pf_buy( array( $F => 2 ) );
pf_consistent( 'free order (paid at once)', array( $occ ) );
check( 'free order counts as sold immediately', ! is_wp_error( $free ) && 2 === (int) pf_counter( 'ticket_types', $F )->sold_qty && 2 === (int) pf_counter( 'occurrences', $occ )->sold_qty );

$too_much = pf_buy( array( $A => 3, $B => 3 ) );
check( 'occurrence capacity is checked for the sum of all lines of the occurrence (7 > 5 left)', is_wp_error( $too_much ) && false !== strpos( $too_much->get_error_message(), 'Termin' ) );
pf_consistent( 'rejected order', array( $occ ) );

check( 'confirm: paid', true === Orders::confirm_external_payment( $o1['order_id'], 'pf' ) );
pf_consistent( 'confirm (hold converted)', array( $occ ) );
check( 'confirm: units move from held to sold', 3 === (int) pf_counter( 'ticket_types', $A )->sold_qty && 0 === (int) pf_counter( 'ticket_types', $A )->held_qty && 7 === (int) pf_counter( 'occurrences', $occ )->sold_qty );

$o2 = pf_buy( array( $A => 2 ) );
expire_holds_of( $o2['order_id'] );
check( 'lapsed but uncleaned hold: still in held_qty, not in availability (= SUM path)', 2 === (int) pf_counter( 'ticket_types', $A )->held_qty && 5 === Orders::availability( $A ) && pf_sum_availability( $A ) === Orders::availability( $A ) );
$fill = pf_buy( array( $A => 5 ) );
check( 'capacity check ignores the lapsed hold (all 5 remaining units sellable)', ! is_wp_error( $fill ) );
pf_consistent( 'order next to a lapsed hold', array( $occ ) );
Orders::expire_holds();
pf_consistent( 'expire_holds', array( $occ ) );
check( 'expiry: held units leave the counters', 5 === (int) pf_counter( 'ticket_types', $A )->held_qty );

$late = Orders::confirm_external_payment( $o2['order_id'], 'pf-late' );
check( 'late payment without capacity: needs review, nothing oversold', is_wp_error( $late ) && 'paid_unallocated' === Orders::get( $o2['order_id'] )->payment_status );
pf_consistent( 'late payment (needs review)', array( $occ ) );
Orders::cancel_order( $fill['order_id'], 'pf' );
pf_consistent( 'cancel pending order', array( $occ ) );
$o3 = pf_buy( array( $A => 2 ) );
expire_holds_of( $o3['order_id'] );
Orders::expire_holds();
check( 'late payment with capacity left: recovered', true === Orders::confirm_external_payment( $o3['order_id'], 'pf-late-ok' ) && 'paid' === Orders::get( $o3['order_id'] )->payment_status );
pf_consistent( 'late payment recovery', array( $occ ) );

$ticket_ids = array_map( static fn( $x ) => (int) $x->ticket_id, tickets( $o1['order_id'], 'valid' ) );
$refund = Refunds::request( $o1['order_id'], array( $ticket_ids[0] ), 'Teilstorno', 'pf-refund-1' );
check( 'partial refund completes', ! is_wp_error( $refund ) && 'completed' === $refund['status'] );
pf_consistent( 'partial refund', array( $occ ) );
check( 'Woo-style revoke releases one unit', 1 === Orders::revoke_tickets( $o1['order_id'], $B, 1, 'pf' ) );
pf_consistent( 'revoke_tickets', array( $occ ) );
Orders::mark_refunded( $o3['order_id'], 'pf' );
pf_consistent( 'mark_refunded', array( $occ ) );
Orders::cancel_order( $o1['order_id'], 'pf' );
pf_consistent( 'cancel paid order', array( $occ ) );
check( 'availability equals the SUM path after all operations', pf_sum_availability( $A ) === Orders::availability( $A ) && pf_sum_availability( $B ) === Orders::availability( $B ) );

if ( class_exists( Exchange::class ) && function_exists( 'ex_buy' ) ) {
	echo "Performance: counters across exchanges\n";
	list( $xo1, $xt1 ) = pf_occurrence( 4, array( 'Std' => array( 0, 2500 ) ) );
	$x_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$xo1" );
	list( $xo2, $xt2 ) = pf_occurrence( 3, array( 'Std2' => array( 0, 2500 ), 'VIP' => array( 1, 4000 ) ), 264, $x_event );
	list( , $xids ) = ex_buy( $xt1['Std'], 3, 'pfx@example.com' );
	$even = Exchange::request( $xids[0], $xt2['Std2'], array( 'channel' => 'admin' ) );
	check( 'even exchange into another occurrence completes' . ( is_wp_error( $even ) ? ' (' . $even->get_error_code() . ': ' . $even->get_error_message() . ')' : '' ), ! is_wp_error( $even ) && 'completed' === $even['status'] );
	pf_consistent( 'exchange (unit moved between occurrences)', array( $xo1, $xo2 ) );
	check( 'exchange: sold unit moved from occurrence 1 to 2', 2 === (int) pf_counter( 'occurrences', $xo1 )->sold_qty && 1 === (int) pf_counter( 'occurrences', $xo2 )->sold_qty );
	$up = Exchange::request( $xids[1], $xt2['VIP'], array( 'channel' => 'admin' ) );
	check( 'upgrade waits for the supplement' . ( is_wp_error( $up ) ? ' (' . $up->get_error_message() . ')' : '' ), ! is_wp_error( $up ) && 'pending_payment' === $up['status'] );
	if ( is_wp_error( $up ) ) { $up = array( 'supplementary_order_id' => 0 ); }
	pf_consistent( 'exchange with supplementary hold', array( $xo1, $xo2 ) );
	Orders::confirm_external_payment( (int) $up['supplementary_order_id'], 'pf-supp' );
	pf_consistent( 'supplement paid (exchange finalised)', array( $xo1, $xo2 ) );
	check( 'upgrade: VIP sold once, source released', 1 === (int) pf_counter( 'ticket_types', $xt2['VIP'] )->sold_qty && 0 === Orders::availability( $xt2['VIP'] ) && 1 === (int) pf_counter( 'occurrences', $xo1 )->sold_qty );
}

echo "Performance: reconciliation finds and repairs drift\n";
list( $rocc, $rt ) = pf_occurrence( 0, array( 'R' => array( 10, 1000 ) ) );
$r1 = pf_buy( array( $rt['R'] => 2 ) );
$r2 = pf_buy( array( $rt['R'] => 1 ) );
// A writer that forgot to sync (raw payment) and a corrupted counter.
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_status='paid',status='complete' WHERE order_id=" . (int) $r1['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET status='converted' WHERE order_id=" . (int) $r1['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET held_qty=held_qty+7 WHERE ticket_type_id=" . (int) $rt['R'] );
check( 'drift is detected', 2 <= count( CapacityCounters::drift( array( $rocc ) ) ) );
$drift_seen = array();
add_action( 'wpcap_capacity_drift', static function ( $report ) use ( &$drift_seen ) { $drift_seen[] = $report; } );
$GLOBALS['wpcap_test_mails'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$report = CapacityCounters::reconcile();
pf_consistent( 'reconcile', array( $rocc ) );
check( 'reconcile re-syncs the missed order and repairs the counter', in_array( (int) $r1['order_id'], $report['orders'], true ) && in_array( $rocc, $report['occurrences'], true ) && 2 === (int) pf_counter( 'ticket_types', $rt['R'] )->sold_qty && 1 === (int) pf_counter( 'ticket_types', $rt['R'] )->held_qty );
check( 'drift is reported (action, audit log, one admin mail)', 1 === count( $drift_seen ) && 1 === count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( $m['subject'], 'Kontingentzähler' ) ) ) && (bool) $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='capacity_counter_drift' LIMIT 1" ) );
$again = CapacityCounters::reconcile();
check( 'a second run finds nothing', 0 === $again['resynced_orders'] && 0 === $again['repaired_occurrences'] );
$GLOBALS['wpcap_test_mails'] = array();
$GLOBALS['wpcap_test_transients'] = array();

echo "Performance: upgrade rebuilds and verifies the counters\n";
$state_before = get_option( CapacityCounters::OPTION );
$wpdb->query( "UPDATE {$p}wpcap_order_items SET counted_qty=0" );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET counted_qty=0" );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET sold_qty=0,held_qty=0" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET sold_qty=0,held_qty=0" );
unset( $GLOBALS['wpcap_test_options'][ CapacityCounters::OPTION ] ); // Like an install interrupted after dbDelta.
check( 'columns without stored state: maintained, but decisions use the SUM queries', 'verifying' === CapacityCounters::state() && CapacityCounters::maintained() && ! CapacityCounters::enabled() );
list( $uocc, $ut ) = pf_occurrence( 0, array( 'U' => array( 3, 1000 ) ) );
$u_buy = pf_buy( array( $ut['U'] => 2 ) );
check( 'while verifying the SUM path still sells correctly', ! is_wp_error( $u_buy ) && 1 === Orders::availability( $ut['U'] ) && is_wp_error( pf_buy( array( $ut['U'] => 2 ) ) ) );
CapacityCounters::after_install();
check( 'upgrade: counters rebuilt from the source tables, state verifying', 'verifying' === CapacityCounters::state() && ! CapacityCounters::drift() );
$GLOBALS['wpcap_test_filters']['wpcap_capacity_verify_after'] = 0;
$verify = CapacityCounters::reconcile();
unset( $GLOBALS['wpcap_test_filters']['wpcap_capacity_verify_after'] );
check( 'a clean reconcile run after the grace period switches to the counters', 'ready' === $verify['state'] && CapacityCounters::enabled() );
update_option( CapacityCounters::OPTION, $state_before );

echo "Performance: availability memo and object cache\n";
list( $mocc, $mt ) = pf_occurrence( 0, array( 'M' => array( 6, 1000 ) ) );
$M = $mt['M'];
check( 'first read', 6 === Orders::availability( $M ) );
$before = $wpdb->num_queries;
$repeat = Orders::availability( $M ) + Orders::availability( $M ) + Orders::availability( $M );
check( 'repeated reads in one request hit the memo (no query)', 18 === $repeat && $before === $wpdb->num_queries );
$m1 = pf_buy( array( $M => 2 ) );
check( 'a write in the same request invalidates the memo', 4 === Orders::availability( $M ) );
expire_holds_of( $m1['order_id'] );
check( 'a raw SQL write also invalidates it (query filter)', 6 === Orders::availability( $M ) );
$pf_cache_defined = false;
if ( ! function_exists( 'wp_cache_get' ) ) {
	$pf_cache_defined = true;
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { $found = array_key_exists( "$group:$key", $GLOBALS['pf_cache'] ?? array() ); return $found ? $GLOBALS['pf_cache'][ "$group:$key" ] : false; }
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) { $GLOBALS['pf_cache'][ "$group:$key" ] = $value; $GLOBALS['pf_cache_ttl'][ "$group:$key" ] = $ttl; return true; }
}
$reset_request = static function () {
	$memo = new ReflectionProperty( Orders::class, 'availability_memo' );
	$memo->setAccessible( true );
	$memo->setValue( null, array() );
	$wrote = new ReflectionProperty( Orders::class, 'wrote' );
	$wrote->setAccessible( true );
	$wrote->setValue( null, false );
};
if ( $pf_cache_defined ) {
	$GLOBALS['pf_cache'] = array();
	$reset_request();
	Orders::availability( $M );
	$key = 'wpcap:availability:' . $p . $M;
	check( 'object cache stores the value with the default TTL (10 s)', array( 'v' => 6 ) === ( $GLOBALS['pf_cache'][ $key ] ?? null ) && 10 === $GLOBALS['pf_cache_ttl'][ $key ] );
	$GLOBALS['pf_cache'][ $key ] = array( 'v' => 3 ); // Another request cached an older value.
	$reset_request();
	check( 'a read-only request uses the short object cache', 3 === Orders::availability( $M ) );
	$reset_request();
	pf_buy( array( $M => 1 ) );
	check( 'after a write in this request the object cache is bypassed', 5 === Orders::availability( $M ) );
	$GLOBALS['wpcap_test_filters']['wpcap_availability_cache_ttl'] = 0;
	$GLOBALS['pf_cache'][ $key ] = array( 'v' => 1 );
	$reset_request();
	check( 'TTL 0 disables the object cache', 5 === Orders::availability( $M ) && array( 'v' => 1 ) === $GLOBALS['pf_cache'][ $key ] );
	unset( $GLOBALS['wpcap_test_filters']['wpcap_availability_cache_ttl'] );
	$GLOBALS['pf_cache'] = array();
	$GLOBALS['wpcap_test_filters']['wpcap_availability_cache_ttl'] = 0; // Later test files: no cross-request cache in one CLI process.
}

echo "Performance: event listing without N+1 queries\n";
$hidden = array_map( 'intval', $wpdb->get_col( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE status='scheduled'" ) );
if ( $hidden ) { $wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='pf_hidden' WHERE occurrence_id IN (" . implode( ',', $hidden ) . ')' ); }
$listing_occ = array();
$make_listing = static function ( $n ) use ( $wpdb, $p, &$listing_occ ) {
	for ( $i = 0; $i < $n; $i++ ) {
		list( $o, $ids ) = pf_occurrence( 50, array( 'Normal' => array( 20, 2500 ), 'Ermäßigt' => array( 0, 1500 ) ), 300 + count( $listing_occ ) );
		$listing_occ[] = $o;
		$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$o" );
		$wpdb->insert( "{$p}wpcap_event_translations", array( 'event_id' => $event, 'locale' => 'de_DE', 'name' => 'Übersetzt ' . $o, 'description' => 'Text', 'location' => 'Ort ' . $o, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $ids['Normal'], 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
		$GLOBALS['wpcap_test_transients'] = array();
		Orders::create( array( 'email' => 'list@example.com', 'items' => array( array( 'ticket_type_id' => $ids['Normal'], 'quantity' => 1 + $i % 3 ) ), 'attendees' => array_fill( 0, 1 + $i % 3, array( 'ticket_type_id' => $ids['Normal'], 'answers' => array( 'firma' => 'X' ) ) ), 'idempotency_key' => 'pf-list-' . uniqid( '', true ) ) );
	}
};
// Module filters (pools, allocations, passes, price tiers) and card extensions (seat picker, buyer
// limits, pass badge) run per ticket type/card; the core listing is measured without them, then
// the module overhead is reported.
$module_hooks = array();
foreach ( array( 'wpcap_ticket_availability', 'wpcap_ticket_unit_price', 'wpcap_ticket_price_label', 'wpcap_checkout_form_fields', 'wpcap_event_card_html', 'wpcap_event_card_quantity_max', 'wpcap_event_card_max_total' ) as $hook ) {
	$module_hooks[ $hook ] = $GLOBALS['wpcap_test_hooks'][ $hook ] ?? null;
	unset( $GLOBALS['wpcap_test_hooks'][ $hook ] );
}
$count = static function ( callable $fn ) use ( $wpdb ) {
	Orders::flush_availability_cache();
	PassCaptain\Campaigns::flush(); // Per-request cache of active discount codes (one query per listing).
	$before = $wpdb->num_queries;
	$out = $fn();
	return array( $wpdb->num_queries - $before, $out );
};
$make_listing( 3 );
list( $q3, $html3 ) = $count( array( PublicEvents::class, 'render_shortcode' ) );
$make_listing( 9 );
list( $q12, $html12 ) = $count( array( PublicEvents::class, 'render_shortcode' ) );
check( "listing: query count independent of the number of dates (3 dates: $q3, 12 dates: $q12)", $q3 === $q12 && $q12 <= 9 );
check( 'listing shows all 12 dates with translation, fields and availability', 12 === substr_count( $html12, '<article class="wpcap-event">' ) && false !== strpos( $html12, 'Übersetzt ' . $listing_occ[11] ) && false !== strpos( $html12, '&quot;key&quot;:&quot;firma&quot;' ) && false !== strpos( $html12, 'max="10"' ) );
$req = class_exists( 'WP_REST_Request' ) ? new WP_REST_Request( 'GET' ) : null;
if ( $req ) {
	list( $r3, $rest ) = $count( static fn() => PassCaptain\Rest\Controller::events( $req ) );
	$rest = is_object( $rest ) && method_exists( $rest, 'get_data' ) ? $rest->get_data() : $rest;
	check( "REST events: constant queries ($r3) and translated output", $r3 <= 4 && 12 === count( (array) $rest ) && 'Übersetzt ' . $listing_occ[0] === $rest[0]['name'] && 2 === count( $rest[0]['tickets'] ) && 2500 === $rest[0]['tickets'][0]['price_minor'] );
}
foreach ( $module_hooks as $hook => $callbacks ) { if ( null !== $callbacks ) { $GLOBALS['wpcap_test_hooks'][ $hook ] = $callbacks; } }
list( $qm, $html_m ) = $count( array( PublicEvents::class, 'render_shortcode' ) );
check( "listing with all module filters: bounded queries ($qm for 12 dates, core $q12) – audit 3", $qm <= $q12 + 6 );
check( 'module filters keep the listing (same ticket inputs and availability)', substr_count( $html_m, 'class="wpcap-qty"' ) === substr_count( $html12, 'class="wpcap-qty"' ) && substr_count( $html_m, 'max="10"' ) === substr_count( $html12, 'max="10"' ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='pf_listed' WHERE occurrence_id IN (" . implode( ',', $listing_occ ) . ')' );
if ( $hidden ) { $wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled' WHERE occurrence_id IN (" . implode( ',', $hidden ) . ')' ); }

Settings::update( $pf_refund_settings );
