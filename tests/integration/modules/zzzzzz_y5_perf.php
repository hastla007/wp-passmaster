<?php
/**
 * Y5 (audit panel 2026-11-15, PERF): query budgets and batching. Event page / GET /events / box
 * office lists without N+1 (PERF-1), Mollie method cache (PERF-2), occurrence cancellation in batches
 * (PERF-4), reminders without a SELECT per holder (PERF-3/QA-10) and the medium/low findings.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\DisplayBatch;
use PassCaptain\Orders;
use PassCaptain\PublicEvents;
use PassCaptain\Sales\PriceRules;

if ( ! defined( 'WPCAP_URL' ) ) { define( 'WPCAP_URL', 'https://example.test/wp-content/plugins/pass-captain/' ); }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( ...$a ) {} }
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) {} }
if ( ! function_exists( 'locate_template' ) ) { function locate_template( $t ) { return ''; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return true; } }

PassCaptain\Waitlists::register();
PassCaptain\Series\Pools::register();
PriceRules::register();

global $wpdb;
$p = $wpdb->prefix;

/**
 * N occurrences (each its own event) with M ticket types. Every occurrence has a translation, an
 * occurrence capacity on every second one, a paid order, an active hold, a waitlist offer with hold,
 * a participant field, a demand and an early-bird rule, and every occurrence is in one series with a pool.
 *
 * @return object[] listing rows (as PublicEvents::render_shortcode() selects them)
 */
function y5_listing( $n, $m, $tag ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$series_id = 900000 + random_int( 1, 99999 );
	$wpdb->insert( "{$p}wpcap_series_pools", array( 'series_id' => $series_id, 'name' => 'Pool ' . $tag, 'type_name' => 'Typ 0', 'capacity' => 7 * $n, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$rows = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( "{$p}wpcap_events", array( 'name' => "Y5 $tag $i", 'slug' => 'y5' . uniqid(), 'description' => 'Original', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$event = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_event_translations", array( 'event_id' => $event, 'locale' => 'de_DE', 'name' => "Übersetzt $tag $i", 'description' => 'Beschreibung', 'location' => 0 === $i % 3 ? '' : 'Saal ' . $i, 'updated_at' => $now ) );
		$starts = gmdate( 'Y-m-d H:i:s', time() + ( 30 + $i ) * 86400 );
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $starts, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0 === $i % 2 ? 9 : 0, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_series_occurrences", array( 'series_id' => $series_id, 'occurrence_id' => $occ, 'starts_at' => $starts, 'created_at' => $now ) );
		$wpdb->insert( "{$p}wpcap_price_rules", array( 'event_id' => $event, 'kind' => 'demand', 'label' => 'Gefragt', 'sold_pct' => 20, 'priority' => 30, 'price_mode' => 'fixed', 'amount' => 3100, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( "{$p}wpcap_price_rules", array( 'event_id' => $event, 'kind' => 'early_bird', 'type_name' => 'Typ 1', 'until_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'priority' => 20, 'price_mode' => 'percent', 'amount' => 10, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		for ( $j = 0; $j < $m; $j++ ) {
			$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Typ ' . $j, 'price_minor' => 2000 + 100 * $j, 'currency' => 'EUR', 'capacity' => 1 === $j % 2 ? 0 : 6, 'max_per_order' => 10, 'status' => 'active' ) );
			$type = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $type, 'field_key' => 'name', 'label' => 'Name', 'field_type' => 'text', 'required' => 1, 'position' => 1 ) );
			if ( 0 === $j ) {
				$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => substr( md5( uniqid( '', true ) ), 0, 32 ), 'idempotency_key' => 'y5-' . uniqid( '', true ), 'customer_email' => 'y5@example.com', 'price_snapshot' => '{}', 'status' => 'completed', 'payment_status' => 'paid', 'currency' => 'EUR', 'total_minor' => 4000, 'created_at' => $now, 'updated_at' => $now ) );
				$order = (int) $wpdb->insert_id;
				$wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $order, 'ticket_type_id' => $type, 'quantity' => 2, 'unit_price_minor' => 2000, 'line_total_minor' => 4000 ) );
				$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => substr( md5( uniqid( '', true ) ), 0, 32 ), 'idempotency_key' => 'y5h-' . uniqid( '', true ), 'customer_email' => 'y5h@example.com', 'price_snapshot' => '{}', 'status' => 'pending_payment', 'payment_status' => 'pending', 'currency' => 'EUR', 'total_minor' => 2000, 'created_at' => $now, 'updated_at' => $now ) );
				$hold_order = (int) $wpdb->insert_id;
				$wpdb->insert( "{$p}wpcap_reservations", array( 'order_id' => $hold_order, 'ticket_type_id' => $type, 'quantity' => 1, 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
			}
			if ( 2 === $j ) {
				$wpdb->insert( "{$p}wpcap_waitlist", array( 'event_id' => $event, 'occurrence_id' => $occ, 'ticket_type_id' => $type, 'email_hash' => hash( 'sha256', "y5$type" ), 'email' => "y5w$type@example.com", 'position' => 1, 'status' => 'offered', 'offer_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'hold_quantity' => 1, 'created_at' => $now, 'updated_at' => $now ) );
			}
		}
		PassCaptain\CapacityCounters::rebuild_occurrence( $occ ); // Plain SQL fixture: the capacity counters (merged line) follow the rows.
		$rows[] = (object) array( 'event_id' => $event, 'name' => "Y5 $tag $i", 'description' => 'Original', 'occurrence_id' => $occ, 'starts_at' => $starts, 'timezone' => 'Europe/Vienna', 'location' => 'Wien' );
	}
	return $rows;
}

/** Renders the cards and captures the prepared types per card; returns [queries, cards]. */
function y5_render( array $rows ) {
	global $wpdb;
	PriceRules::flush_cache();
	$cards = array();
	$capture = static function ( $html, $event, $types ) use ( &$cards ) { $cards[ (int) $event->occurrence_id ] = array( 'event' => clone $event, 'types' => $types ); return $html; };
	add_filter( 'wp_pass_captain_event_card_html', $capture, 99, 3 );
	$before = $wpdb->num_queries;
	$html = PublicEvents::render_cards( array_map( static fn( $r ) => clone $r, $rows ) );
	$queries = $wpdb->num_queries - $before;
	remove_filter( 'wp_pass_captain_event_card_html', $capture, 99 );
	return array( $queries, $cards, $html );
}

echo "Y5 PERF-1: event page without N+1 (query budget for N occurrences x M ticket types)\n";
$y5_small = y5_listing( 3, 3, 'S' );
$y5_large = y5_listing( 18, 3, 'L' );
$y5_wide = y5_listing( 3, 9, 'W' );
list( $y5_q_small, $y5_cards_small ) = y5_render( $y5_small );
list( $y5_q_large, $y5_cards_large, $y5_html ) = y5_render( $y5_large );
list( $y5_q_wide ) = y5_render( $y5_wide );
check( "event page renders all cards (3 / 18)", 3 === count( $y5_cards_small ) && 18 === count( $y5_cards_large ) );
check( "query count does not grow with occurrences: 3x3 = $y5_q_small, 18x3 = $y5_q_large queries", $y5_q_large <= $y5_q_small + 2 );
check( "query count does not grow with ticket types: 3x3 = $y5_q_small, 3x9 = $y5_q_wide queries", $y5_q_wide <= $y5_q_small + 2 );
check( "absolute budget: 54 ticket types in at most 30 queries ($y5_q_large)", $y5_q_large <= 30 );

// Same values as the per-type (unbatched) code paths: capacity, holds, waitlist holds, pools, rules, translations.
$y5_same = true;
$y5_diff = '';
$y5_seen = array( 'sold_out' => false, 'rule' => false, 'pool' => false, 'translated' => false );
foreach ( $y5_large as $row ) {
	$card = $y5_cards_large[ (int) $row->occurrence_id ] ?? null;
	if ( ! $card ) { $y5_same = false; $y5_diff = 'missing card'; break; }
	$tr = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d', $p . 'wpcap_event_translations', $row->event_id ) );
	if ( $card['event']->name !== $tr->name || $card['event']->location !== ( $tr->location ?: 'Wien' ) ) { $y5_same = false; $y5_diff = 'translation'; }
	$y5_seen['translated'] = true;
	$occurrence = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', $row->occurrence_id ) );
	foreach ( $card['types'] as $type ) {
		$raw = $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $type->ticket_type_id ) );
		PriceRules::flush_cache();
		$expect = array(
			'available' => Orders::availability( $type->ticket_type_id ),
			'price' => Orders::unit_price( clone $raw, $occurrence, 1 ),
			'label' => Orders::price_label( clone $raw, $occurrence ),
			'explanation' => PriceRules::explanation_for( clone $raw, $occurrence ),
		);
		$got = array( 'available' => $type->available, 'price' => $type->price_minor, 'label' => $type->price_label, 'explanation' => $type->price_explanation );
		if ( $expect !== $got ) { $y5_same = false; $y5_diff = wp_json_encode( array( $type->ticket_type_id, $expect, $got ) ); }
		if ( 1 !== count( $type->fields ) || 'name' !== $type->fields[0]['key'] ) { $y5_same = false; $y5_diff = 'fields'; }
		if ( 0 === $type->available ) { $y5_seen['sold_out'] = true; }
		if ( '' !== $type->price_label ) { $y5_seen['rule'] = true; }
	}
}
$y5_seen['pool'] = null !== PassCaptain\Series\Pools::remaining_for_type( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE occurrence_id=%d AND name=%s', $p . 'wpcap_ticket_types', $y5_large[0]->occurrence_id, 'Typ 0' ) ) );
check( 'batched values equal the per-type paths (availability, unit price, label, explanation, fields, translation)' . ( $y5_diff ? ' – ' . $y5_diff : '' ), $y5_same );
check( 'fixture exercises rules, pools and translations', $y5_seen['rule'] && $y5_seen['pool'] && $y5_seen['translated'] );
check( 'no display batch is left active after rendering', ! DisplayBatch::active() );

echo "Y5 PERF-1: GET /events and box office ticket list\n";
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { public function __construct( public $data = null, public $status = 200 ) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } }
}
if ( ! function_exists( 'rest_ensure_response' ) ) { function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); } }
$y5_req = new WP_REST_Request( 'GET', '/wp-pass-captain/v1/events' );
PriceRules::flush_cache();
$y5_before = $wpdb->num_queries;
$y5_events = PassCaptain\Rest\Controller::events( $y5_req )->get_data();
$y5_q_rest = $wpdb->num_queries - $y5_before;
$y5_ticket_count = array_sum( array_map( static fn( $e ) => count( $e['tickets'] ), $y5_events ) );
check( "GET /events: " . count( $y5_events ) . " occurrences with $y5_ticket_count ticket types in $y5_q_rest queries (budget 25)", count( $y5_events ) >= 24 && $y5_q_rest <= 25 );
$y5_rest_ok = true;
foreach ( $y5_events as $e ) {
	foreach ( $e['tickets'] as $t ) {
		PriceRules::flush_cache();
		$raw = (object) $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,sales_start,sales_end FROM %i WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $t['ticket_type_id'] ), ARRAY_A );
		if ( Orders::unit_price( clone $raw ) !== $t['price_minor'] || Orders::price_label( clone $raw ) !== $t['price_label'] ) { $y5_rest_ok = false; }
	}
}
check( 'GET /events prices and labels equal the per-type computation', $y5_rest_ok );
$y5_occ = (int) $y5_wide[0]->occurrence_id;
$y5_before = $wpdb->num_queries;
$y5_bo = PassCaptain\BoxOffice\Service::ticket_types( $y5_occ );
$y5_q_bo = $wpdb->num_queries - $y5_before;
$y5_bo_ok = 9 === count( $y5_bo );
foreach ( $y5_bo as $t ) { $y5_bo_ok = $y5_bo_ok && Orders::availability( $t['ticket_type_id'] ) === $t['available']; }
check( "box office list: 9 types with the same availability in $y5_q_bo queries (budget 30)", $y5_bo_ok && $y5_q_bo <= 30 );

echo "Y5 PERF-2: Mollie methods – one cached list per currency/country, negative cache, short timeout\n";
PassCaptain\Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Yz5', 10 ), 'mollie' => array( 'methods' => array( 'ideal', 'paysafecard', 'creditcard' ), 'billing_country' => 'AT' ) ) );
wpcap_test_reset_transients();
$GLOBALS['wpcap_test_transient_ttl'] = array();
http_reset();
http_push( array( '_embedded' => array( 'methods' => array(
	array( 'id' => 'ideal', 'description' => 'iDEAL', 'minimumAmount' => array( 'value' => '0.01', 'currency' => 'EUR' ), 'maximumAmount' => array( 'value' => '50000.00', 'currency' => 'EUR' ) ),
	array( 'id' => 'paysafecard', 'description' => 'paysafecard', 'minimumAmount' => array( 'value' => '35.00', 'currency' => 'EUR' ), 'maximumAmount' => array( 'value' => '1000.00', 'currency' => 'EUR' ) ),
	array( 'id' => 'creditcard', 'description' => 'Karte' ),
) ) ), 200, '/v2/methods' );
// Merged line (A282): page rendering never calls Mollie (remote=false schedules a background load);
// the checkout/refresh path asks with remote=true.
$y5_m1 = PassCaptain\Mollie\MollieGateway::selectable_methods( 2500, 'EUR', true );
$y5_m2 = PassCaptain\Mollie\MollieGateway::selectable_methods( 4000, 'EUR', true );
$y5_m3 = PassCaptain\Mollie\MollieGateway::selectable_methods( 150000, 'EUR', true );
$y5_mreq = http_requests( '/v2/methods' );
parse_str( (string) parse_url( $y5_mreq[0]['url'] ?? '', PHP_URL_QUERY ), $y5_mq );
check( 'three different amounts need one API call (cache per currency/country)', 1 === count( $y5_mreq ) && ! isset( $y5_mq['amount'] ) && 'AT' === ( $y5_mq['billingCountry'] ?? '' ) );
// Klarna is no longer selectable in the merged line (A351 capture methods); paysafecard carries the limits here.
check( 'limits are applied locally (method from 35 €, up to 1,000 €; no limits = allowed)', array( 'ideal', 'creditcard' ) === array_keys( $y5_m1 ) && array( 'ideal', 'paysafecard', 'creditcard' ) === array_keys( $y5_m2 ) && array( 'ideal', 'creditcard' ) === array_keys( $y5_m3 ) );
// Merged line (A282): the lookup never runs while a page renders (background job), so a 5 s timeout is short enough.
check( 'the methods lookup uses a short timeout (at most 5 seconds, background only)', ( $y5_mreq[0]['timeout'] ?? 99 ) <= 5 && PassCaptain\Mollie\MollieGateway::METHODS_TIMEOUT <= 5 );
http_reset();
wpcap_test_reset_transients();
http_push( array( 'detail' => 'down' ), 503, '/v2/methods' );
$y5_e1 = PassCaptain\Mollie\MollieGateway::available_methods( '25.00', 'EUR' );
$y5_e2 = PassCaptain\Mollie\MollieGateway::available_methods( '30.00', 'EUR' );
$y5_neg_ttl = 0;
foreach ( $GLOBALS['wpcap_test_transients'] as $k => $v ) { if ( 0 === strpos( $k, 'wpcap_mollie_catalog_' ) && isset( $v['error'] ) ) { $y5_neg_ttl = $GLOBALS['wpcap_test_transient_ttl'][ $k ] ?? 0; } }
check( 'a failed lookup is cached for 5 minutes (second page view makes no API call)', is_wp_error( $y5_e1 ) && is_wp_error( $y5_e2 ) && 1 === count( http_requests( '/v2/methods' ) ) && 300 === $y5_neg_ttl );
check( 'checkout selector stays empty (no error) while Mollie is down', array() === PassCaptain\Mollie\MollieGateway::selectable_methods( 2500, 'EUR' ) && 1 === count( http_requests( '/v2/methods' ) ) );
http_reset();
http_push( array( '_embedded' => array( 'methods' => array( array( 'id' => 'creditcard', 'description' => 'Karte' ) ) ) ), 200, '/v2/methods' );
PassCaptain\Mollie\MollieGateway::available_methods( '25.00', 'CHF' );
parse_str( (string) parse_url( http_requests( '/v2/methods' )[0]['url'] ?? '', PHP_URL_QUERY ), $y5_mq );
check( 'other currencies: Mollie filters by amount (limits are only given in EUR)', '25.00' === ( $y5_mq['amount']['value'] ?? '' ) && 'CHF' === ( $y5_mq['amount']['currency'] ?? '' ) );
http_reset();
wpcap_test_reset_transients();

echo "Y5 PERF-4: occurrence cancellation – bulk revoke first, refunds/mails/hooks as job batches\n";
PassCaptain\OccurrenceCancellation::register();
PassCaptain\Settings::update( array( 'auto_refund_cancelled' => 1, 'refund_approval_threshold' => 0, 'require_terms' => 0, 'refund_after_checkin' => 'allow' ) );
$y5c_type = make_type( 300, 10, 1000 );
$y5c_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$y5c_type" );
$y5c_orders = array();
for ( $i = 0; $i < 25; $i++ ) {
	$r = Orders::create( array( 'email' => "y5c$i@example.com", 'items' => array( array( 'ticket_type_id' => $y5c_type, 'quantity' => 2 ) ), 'idempotency_key' => 'y5c-' . $i . '-' . uniqid() ), array( 'skip_rate_limit' => true ) );
	Orders::confirm_manual_payment( $r['order_id'] );
	$y5c_orders[] = (int) $r['order_id'];
}
$y5c_open = Orders::create( array( 'email' => 'y5c-open@example.com', 'items' => array( array( 'ticket_type_id' => $y5c_type, 'quantity' => 1 ) ), 'idempotency_key' => 'y5c-open-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$y5c_in = implode( ',', $y5c_orders );
$y5c_invariant = static function () use ( $wpdb, $p, $y5c_occ ) {
	// Never: a valid ticket whose money was refunded.
	return 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets t JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=$y5c_occ AND t.status='valid' AND t.refunded=1" );
};
$y5c_hook_states = array();
$y5c_hook = static function ( $ticket_id ) use ( &$y5c_hook_states, $wpdb, $p ) { $y5c_hook_states[] = (string) $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id ); };
add_action( 'wpcap_ticket_revoked', $y5c_hook, 1, 1 );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$y5c_occ" );
// Merged line: the cancellation is a resumable run (A428, BulkRuns: orders → refund plan → batched
// revoke → refunds as idempotent jobs → queued mails); abort/resume is covered by zz_a428.php. Here:
// the PERF goals – revocation in bulk statements, refunds once per order, every holder mailed once.
$y5c_mails = count( $GLOBALS['wpcap_test_mails'] );
$y5c_revoke_sql = 0;
$y5c_spy = static function ( $sql ) use ( &$y5c_revoke_sql, $p ) { if ( preg_match( "/^\\s*UPDATE\\s+`?{$p}wpcap_tickets`?\\s+SET\\s+status='revoked'/i", $sql ) ) { ++$y5c_revoke_sql; } return $sql; };
add_filter( 'query', $y5c_spy );
$y5c_sum = PassCaptain\OccurrenceCancellation::handle( $y5c_occ );
remove_filter( 'query', $y5c_spy );
$y5c_valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($y5c_in) AND status='valid'" );
check( "50 tickets revoked with bulk UPDATEs ($y5c_revoke_sql statements, no statement per ticket)", 50 === $y5c_sum['revoked'] && 0 === $y5c_valid && $y5c_revoke_sql >= 1 && $y5c_revoke_sql <= 5 );
check( 'open order of the occurrence is cancelled inline', 'cancelled' === Orders::get( $y5c_open['order_id'] )->payment_status && 1 === $y5c_sum['cancelled_orders'] );
check( 'hooks fire per ticket only after the revoke was persisted (50 x status revoked: ' . count( $y5c_hook_states ) . ' ' . implode( ',', array_unique( $y5c_hook_states ) ) . ')', 50 === count( $y5c_hook_states ) && array( 'revoked' ) === array_values( array_unique( $y5c_hook_states ) ) );
check( 'invariant after the run: no valid ticket with refunded money', $y5c_invariant() );
$y5c_refunded_orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT order_id) FROM {$p}wpcap_refunds WHERE status='completed' AND order_id IN ($y5c_in)" );
$y5c_refund_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id IN ($y5c_in)" );
$y5c_to = array_unique( array_map( static fn( $m ) => $m['to'], array_slice( $GLOBALS['wpcap_test_mails'], $y5c_mails ) ) );
$y5c_flagged = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($y5c_in) AND status='revoked' AND refunded=1" );
check( 'all 25 orders refunded exactly once, all 50 tickets revoked and flagged refunded', 25 === $y5c_refunded_orders && 25 === $y5c_refund_rows && 50 === $y5c_flagged && 25 === $y5c_sum['refunds'] );
check( 'holders informed through the mail queue (25 recipients)', count( array_filter( $y5c_to, static fn( $t ) => str_starts_with( $t, 'y5c' ) && 'y5c-open@example.com' !== $t ) ) >= 25 && 25 <= $y5c_sum['mails_total'] );
$y5c_run = PassCaptain\BulkRuns::occurrence_run( $y5c_occ );
check( 'admin progress: the run reports completion and its counters', $y5c_run && 'complete' === $y5c_run->status && ! empty( $y5c_sum['complete'] ) && 'done' === $y5c_sum['phase'] );
remove_action( 'wpcap_ticket_revoked', $y5c_hook, 1 );
// Orders::revoke_ids(): one statement per chunk, only still valid tickets reported.
$y5c_type2 = make_type( 50, 10, 0 );
$y5c_free = Orders::create( array( 'email' => 'y5c-free@example.com', 'items' => array( array( 'ticket_type_id' => $y5c_type2, 'quantity' => 6 ) ), 'idempotency_key' => 'y5c-free-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$y5c_ids = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $y5c_free['order_id'] ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=" . $y5c_ids[0] );
// Statements of revoke_ids() itself; the report cache (A201, when registered) adds one id → event lookup for the whole statement.
$GLOBALS['wpcap_test_query_log'] = array();
$y5c_changed = Orders::revoke_ids( array_merge( $y5c_ids, array( $y5c_ids[1], 0 ) ) );
$y5c_q = count( array_filter( $GLOBALS['wpcap_test_query_log'], static fn( $sql ) => 0 !== strpos( ltrim( $sql ), 'SELECT t.ticket_id,o.event_id FROM' ) ) );
unset( $GLOBALS['wpcap_test_query_log'] );
sort( $y5c_changed );
check( "revoke_ids: 5 valid of 6 tickets revoked in $y5c_q statements (checked-in one untouched)", array_slice( $y5c_ids, 1 ) === $y5c_changed && 2 === $y5c_q && 'checked_in' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . $y5c_ids[0] ) );
check( 'revoke_ids: second call changes nothing', array() === Orders::revoke_ids( $y5c_ids ) );
PassCaptain\Settings::update( array( 'auto_refund_cancelled' => 0 ) );

echo "Y5 PERF-3 / QA-10: reminders without a SELECT per holder, all occurrences of the window, time budget\n";
PassCaptain\Modules\Reminders::register();
/** Paid order with one valid ticket of $type for $email (direct inserts, fast fixture). */
function y5_paid_ticket( $type, $email ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => substr( md5( uniqid( '', true ) ), 0, 32 ), 'idempotency_key' => 'y5r-' . uniqid( '', true ), 'customer_email' => $email, 'price_snapshot' => '{}', 'status' => 'completed', 'payment_status' => 'paid', 'currency' => 'EUR', 'created_at' => $now, 'updated_at' => $now ) );
	$order = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_tickets", array( 'public_id' => substr( md5( uniqid( 't', true ) ), 0, 32 ), 'order_id' => $order, 'ticket_type_id' => $type, 'token_hash' => hash( 'sha256', uniqid( 'tok', true ) ), 'status' => 'valid', 'issued_at' => $now ) );
	return $order;
}
function y5_occ_type( $starts_ts ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Y5R', 'slug' => 'y5r' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', $starts_ts ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( $occ, (int) $wpdb->insert_id );
}
PassCaptain\Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 2, 700 ), 'subject' => 'Erinnerung {event}', 'body' => 'Bald: {event}', 'sms' => 0, 'sms_body' => '' ) ) );
// 205 occurrences start within the next minutes (more than the old LIMIT 200), the target in 650 h.
for ( $i = 0; $i < 205; $i++ ) { y5_occ_type( time() + 120 + $i ); }
list( $y5r_occ, $y5r_type ) = y5_occ_type( time() + 650 * 3600 );
y5_paid_ticket( $y5r_type, 'y5r-first@example.com' );
$y5r_count = static function () use ( $wpdb, $p, $y5r_occ ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s", '%"occurrence_id":' . $y5r_occ . ',%' ) ); };
PassCaptain\Modules\Reminders::schedule_due();
check( 'QA-10: the 700 h stage of an occurrence behind 200+ earlier ones is queued', 1 === $y5r_count() );
$y5r_before = $wpdb->num_queries;
check( 'second run queues nothing (dedupe)', 0 === PassCaptain\Modules\Reminders::schedule_due() );
$y5r_q1 = $wpdb->num_queries - $y5r_before;
for ( $i = 0; $i < 30; $i++ ) { y5_paid_ticket( $y5r_type, "y5r-h$i@example.com" ); }
$y5r_new = PassCaptain\Modules\Reminders::schedule_due();
$y5r_before = $wpdb->num_queries;
PassCaptain\Modules\Reminders::schedule_due();
$y5r_q2 = $wpdb->num_queries - $y5r_before;
check( "30 new holders queued once; a run with 31 holders needs no extra query per holder ($y5r_q1 -> $y5r_q2)", 30 === $y5r_new && 31 === $y5r_count() && $y5r_q2 <= $y5r_q1 + 1 );
// Time budget: an exhausted budget stops before the first occurrence and remembers the position.
$y5r_tb = new ReflectionProperty( PassCaptain\Jobs\TimeBudget::class, 'deadline' );
$y5r_tb->setAccessible( true );
$y5r_tb->setValue( null, microtime( true ) - 1 );
list( $y5r_occ2, $y5r_type2 ) = y5_occ_type( time() + 3600 );
y5_paid_ticket( $y5r_type2, 'y5r-budget@example.com' );
$y5r_stopped = PassCaptain\Modules\Reminders::schedule_due();
$y5r_cursor = get_option( PassCaptain\Modules\Reminders::CURSOR_OPTION, false );
$y5r_tb->setValue( null, null );
check( 'exhausted time budget: nothing scheduled, cursor stored for the next run', 0 === $y5r_stopped && false !== $y5r_cursor );
$y5r_resumed = PassCaptain\Modules\Reminders::schedule_due();
check( 'next run continues and clears the cursor', 1 === $y5r_resumed && false === get_option( PassCaptain\Modules\Reminders::CURSOR_OPTION, false ) );
PassCaptain\Settings::update( array( 'reminders' => array( 'enabled' => 0, 'offsets' => array( 24 ) ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id SET o.status='cancelled' WHERE e.name=%s", 'Y5R' ) );

echo "Y5 PERF-10 / QA-12 / QA-7: report without N+1, voucher refunds, page cache, period limit, streamed exports\n";
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
/** Paid order with $n tickets (direct inserts) and one completed refund per ticket; returns [order, type, refund ids]. */
function y5_refunded_order( $n, $refund_minor = 1000, $voucher_snapshot = null ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$type = make_type( 0, 50, 1000 );
	$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => substr( md5( uniqid( '', true ) ), 0, 32 ), 'idempotency_key' => 'y5rep-' . uniqid( '', true ), 'customer_email' => 'y5rep@example.com', 'price_snapshot' => '{}', 'status' => 'completed', 'payment_status' => 'paid', 'currency' => 'EUR', 'total_minor' => 1000 * $n, 'subtotal_minor' => 1000 * $n, 'voucher_snapshot' => $voucher_snapshot, 'created_at' => $now, 'updated_at' => $now ) );
	$order = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $order, 'ticket_type_id' => $type, 'quantity' => $n, 'unit_price_minor' => 1000, 'line_total_minor' => 1000 * $n ) );
	$refunds = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$wpdb->insert( "{$p}wpcap_tickets", array( 'public_id' => substr( md5( uniqid( 't', true ) ), 0, 32 ), 'order_id' => $order, 'ticket_type_id' => $type, 'token_hash' => hash( 'sha256', uniqid( 'tok', true ) ), 'status' => 'revoked', 'refunded' => 1, 'issued_at' => $now ) );
		$ticket = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $order, 'idempotency_key' => hash( 'sha256', uniqid( 'r', true ) ), 'currency' => 'EUR', 'amount_minor' => $refund_minor, 'tax_minor' => 0, 'ticket_ids' => wp_json_encode( array( $ticket ) ), 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now ) );
		$refunds[] = (int) $wpdb->insert_id;
	}
	PassCaptain\CapacityCounters::sync_order_now( $order ); // Plain SQL fixture: counters follow the rows (merged line).
	return array( $order, $type, $refunds );
}
$y5p_today = gmdate( 'Y-m-d' );
y5_refunded_order( 3 );
$y5p_before = $wpdb->num_queries;
PassCaptain\Reports\Data::build( array( 'from' => $y5p_today, 'to' => $y5p_today ), null );
$y5p_q1 = $wpdb->num_queries - $y5p_before;
y5_refunded_order( 30 );
$y5p_before = $wpdb->num_queries;
PassCaptain\Reports\Data::build( array( 'from' => $y5p_today, 'to' => $y5p_today ), null );
$y5p_q2 = $wpdb->num_queries - $y5p_before;
check( "report: 30 more refunds need no query per refund ($y5p_q1 -> $y5p_q2)", $y5p_q2 <= $y5p_q1 + 2 );
// Multi-purpose voucher order: 20 EUR paid with the voucher, 10 EUR cash; the refund pays out 10 EUR cash
// but the credit note (and the report) take back the full 30 EUR.
list( $y5v_order, $y5v_type, $y5v_refunds ) = y5_refunded_order( 1, 1000 );
$wpdb->update( "{$p}wpcap_orders", array( 'voucher_snapshot' => wp_json_encode( array( 'status' => 'committed', 'purpose' => 'multi', 'refund_plans' => array( (string) $y5v_refunds[0] => array( 'purpose' => 'multi', 'cash_minor' => 1000, 'voucher_minor' => 2000, 'tax_minor' => 0 ) ) ) ) ), array( 'order_id' => $y5v_order ) );
$y5v_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$y5v_type" );
$y5v_report = PassCaptain\Reports\Data::build( array( 'from' => $y5p_today, 'to' => $y5p_today, 'event_id' => $y5v_event ), null );
check( 'refund of a multi-purpose voucher order counts cash + voucher (credit note amounts)', 3000 === (int) $y5v_report['total']['refunded'] && 1 === (int) $y5v_report['total']['refunded_tickets'] );
// QA-12: period limit.
$y5p_long = PassCaptain\Reports\Data::build( array( 'from' => '2020-01-01', 'to' => '2026-11-15' ), null );
$y5p_short = PassCaptain\Reports\Data::build( array( 'from' => '2026-01-01', 'to' => '2026-11-15' ), null );
check( 'report period is limited to 366 days (with flag for the notice)', ! empty( $y5p_long['range_clamped'] ) && '2025-11-15' === $y5p_long['from'] && '2026-11-15' === $y5p_long['to'] && empty( $y5p_short['range_clamped'] ) && '2026-01-01' === $y5p_short['from'] );
// Admin page through Reports\Cache, invalidated with any event.
PassCaptain\Reports\Cache::register();
wpcap_test_reset_transients();
$y5p_filters = array( 'from' => $y5p_today, 'to' => $y5p_today, 'event_id' => 0, 'occurrence_id' => 0, 'compare' => '' );
PassCaptain\Reports\Page::cached_report( $y5p_filters, null );
$y5p_before = $wpdb->num_queries;
$y5p_cached = PassCaptain\Reports\Page::cached_report( $y5p_filters, null );
$y5p_q = $wpdb->num_queries - $y5p_before;
check( "report page: second view from the cache ($y5p_q queries)", 0 === $y5p_q && is_array( $y5p_cached ) );
PassCaptain\Reports\Cache::flush_event( $y5v_event );
$y5p_before = $wpdb->num_queries;
PassCaptain\Reports\Page::cached_report( $y5p_filters, null );
check( 'report page cache is dropped when any event changes', $wpdb->num_queries - $y5p_before > 0 );
wpcap_test_reset_transients();
// QA-7 / streaming: audit export without the 50,000 row cap, delivered from a stream.
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'y5_bulk', 'object_type' => 'test', 'object_id' => 0, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
for ( $i = 0; $i < 16; $i++ ) {
	$wpdb->query( "INSERT INTO {$p}wpcap_audit_logs (user_id,action,object_type,object_id,details,created_at) SELECT user_id,action,object_type,object_id,details,created_at FROM {$p}wpcap_audit_logs WHERE action='y5_bulk'" );
}
$y5a_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='y5_bulk'" );
$y5a_lines = 0;
$y5a_header = '';
$y5a_capture = static function ( $pre, $body ) use ( &$y5a_lines, &$y5a_header ) {
	if ( ! is_resource( $body ) ) { return $pre; }
	$y5a_header = (string) fgets( $body );
	while ( false !== fgets( $body ) ) { ++$y5a_lines; }
	return true;
};
add_filter( 'wpcap_pre_send_download', $y5a_capture, 5, 2 );
$y5a_mem = memory_get_usage();
$y5a_export = PassCaptain\Admin\AuditLogPage::export( PassCaptain\Admin\AuditLogPage::filters( array( 'audit_action' => 'y5_bulk' ) ), true );
PassCaptain\Reports\Scope::send_csv( $y5a_export['rows'], $y5a_export['filename'] );
$y5a_grow = memory_get_usage() - $y5a_mem;
remove_filter( 'wpcap_pre_send_download', $y5a_capture, 5 );
check( "audit export streams all $y5a_total rows (> 50,000, no cap) with a header and BOM", $y5a_total > 50000 && $y5a_total === $y5a_lines && 0 === strpos( $y5a_header, "\xEF\xBB\xBFaudit_id" ) );
check( 'streaming keeps memory flat (< 8 MB growth for ~5 MB of CSV)', $y5a_grow < 8 * 1024 * 1024 );
$wpdb->query( "DELETE FROM {$p}wpcap_audit_logs WHERE action='y5_bulk'" );
$y5f_rows = iterator_to_array( PassCaptain\Fiscalization\ArchiveExporter::rows( '2000-01-01 00:00:00', '2100-01-01 00:00:00' ), false );
check( 'fiscal archive export rows come from a paged generator (header first)', 'document_id' === ( $y5f_rows[0][0] ?? '' ) );

echo "Y5 PERF-5…17: assets, indexes, retention, admin queries, autoload, cron hooks\n";
// PERF-5: the attendee stylesheet is only registered on init and enqueued with the checkout block.
if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
	eval( 'namespace Automattic\WooCommerce\Blocks\Integrations; interface IntegrationInterface { public function get_name(); public function initialize(); public function get_script_handles(); public function get_editor_script_handles(); public function get_script_data(); }' );
}
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( ...$a ) { return true; } }
if ( ! function_exists( 'wp_register_style' ) ) { function wp_register_style( ...$a ) { return true; } }
$y5b_init = new ReflectionMethod( PassCaptain\Integrations\WooCommerce\BlocksIntegration::class, 'initialize' );
$y5b_src = implode( '', array_slice( file( $y5b_init->getFileName() ), $y5b_init->getStartLine() - 1, $y5b_init->getEndLine() - $y5b_init->getStartLine() + 1 ) );
( new PassCaptain\Integrations\WooCommerce\BlocksIntegration() )->initialize();
check( 'PERF-5: initialize() only registers the CSS; it is enqueued with the checkout block assets', false === strpos( $y5b_src, 'wp_enqueue_style' ) && ! empty( $GLOBALS['wpcap_test_hooks']['woocommerce_blocks_enqueue_checkout_block_scripts_after'] ) );

// PERF-6 / PERF-8: indexes.
$y5_index = static function ( $table, $key ) use ( $wpdb, $p ) { return (bool) $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $p . $table, $key ) ); };
check( 'PERF-6/8: KEY status_expires on reservations, KEY created_checkin on check-ins, DB version >= 3.13.0', $y5_index( 'wpcap_reservations', 'status_expires' ) && $y5_index( 'wpcap_checkins', 'created_checkin' ) && version_compare( PassCaptain\Installer::DB_VERSION, '3.13.0', '>=' ) );

// PERF-6: retention of finished holds and jobs.
$y5h_old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
$y5h_paid = y5_paid_ticket( make_type( 0, 10, 1000 ), 'y5h-paid@example.com' );
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => substr( md5( uniqid( '', true ) ), 0, 32 ), 'idempotency_key' => 'y5h-' . uniqid( '', true ), 'customer_email' => 'y5h-pending@example.com', 'price_snapshot' => '{}', 'status' => 'pending_payment', 'payment_status' => 'pending', 'currency' => 'EUR', 'created_at' => $y5h_old, 'updated_at' => $y5h_old ) );
$y5h_pending = (int) $wpdb->insert_id;
$y5h_ids = array();
foreach ( array( array( $y5h_paid, 'converted', $y5h_old ), array( $y5h_paid, 'released', $y5h_old ), array( $y5h_pending, 'expired', $y5h_old ), array( $y5h_paid, 'converted', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ) as $r ) {
	$wpdb->insert( "{$p}wpcap_reservations", array( 'order_id' => $r[0], 'ticket_type_id' => 1, 'quantity' => 1, 'status' => $r[1], 'expires_at' => $r[2] ) );
	$y5h_ids[] = (int) $wpdb->insert_id;
}
foreach ( array( array( 'complete', $y5h_old ), array( 'dead_letter', $y5h_old ), array( 'queued', $y5h_old ), array( 'complete', gmdate( 'Y-m-d H:i:s' ) ) ) as $i => $j ) {
	$wpdb->insert( "{$p}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', 'y5h-job-' . $i . uniqid() ), 'job_type' => 'y5_none', 'payload' => '{}', 'run_after' => $j[1], 'status' => $j[0], 'created_at' => $j[1], 'updated_at' => $j[1] ) );
}
// Merged line: finished holds and jobs are cleaned up by the hourly housekeeping (Retention::housekeeping()), not the daily retention run.
$y5h_counts = PassCaptain\Privacy\Retention::housekeeping();
$y5h_left = array_map( 'intval', $wpdb->get_col( "SELECT reservation_id FROM {$p}wpcap_reservations WHERE reservation_id IN (" . implode( ',', $y5h_ids ) . ')' ) );
$y5h_jobs = $wpdb->get_col( "SELECT status FROM {$p}wpcap_jobs WHERE job_type='y5_none' ORDER BY job_id" );
check( 'PERF-6: old finished holds deleted; holds of a pending order and recent ones kept', array( $y5h_ids[2], $y5h_ids[3] ) === $y5h_left && $y5h_counts['reservations'] >= 2 );
check( 'PERF-6: completed jobs deleted after 30 days; dead letters, queued and recent jobs kept', array( 'dead_letter', 'queued', 'complete' ) === $y5h_jobs && $y5h_counts['jobs'] >= 1 );
$wpdb->query( "DELETE FROM {$p}wpcap_jobs WHERE job_type='y5_none'" );

// PERF-8: check-in results without a DISTINCT scan per page view.
wpcap_test_reset_transients();
PassCaptain\Admin\Queries::checkin_results();
$y5_before = $wpdb->num_queries;
$y5_results = PassCaptain\Admin\Queries::checkin_results();
check( 'PERF-8: check-in result filter options without a query on repeat (cached), core results included', $y5_before === $wpdb->num_queries && in_array( 'checked_in', $y5_results, true ) && in_array( 'undone', $y5_results, true ) && in_array( 'already_checked_in', $y5_results, true ) ); // Merged: fixed list (A433); "wrong_event" is never logged.

// PERF-9: badge as COUNT, conflicts once per request, policy text only on privacy screens.
check( 'PERF-9: refund badge uses COUNT and matches the list', count( PassCaptain\Admin\Queries::pending_refunds( null ) ) === PassCaptain\Admin\Queries::pending_refunds_count( null ) );
if ( ! function_exists( 'add_menu_page' ) ) { function add_menu_page( ...$a ) { return 'x'; } }
if ( ! function_exists( 'add_submenu_page' ) ) { function add_submenu_page( ...$a ) { return 'x'; } }
$wpdb->wpcap_log = array();
PassCaptain\Admin\Menu::add_menus();
$y5_conflict_queries = count( array_filter( $wpdb->wpcap_log, static fn( $q ) => false !== strpos( $q, 'wpcap_admission_conflicts' ) ) );
$wpdb->wpcap_log = null;
check( "PERF-9: building the admin menu counts open conflicts at most once ($y5_conflict_queries)", $y5_conflict_queries <= 1 );
if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { function wp_add_privacy_policy_content( $name, $html ) { $GLOBALS['p1p_policy'][ $name ] = $html; } }
unset( $GLOBALS['p1p_policy'] );
$GLOBALS['pagenow'] = 'index.php';
$y5_before = $wpdb->num_queries;
PassCaptain\Privacy\PolicyText::add();
$y5_q = $wpdb->num_queries - $y5_before;
$y5_skipped = empty( $GLOBALS['p1p_policy'] );
$GLOBALS['pagenow'] = 'privacy-policy-guide.php';
PassCaptain\Privacy\PolicyText::add();
unset( $GLOBALS['pagenow'] );
check( 'PERF-9: policy text is not built on other admin screens, only on the privacy guide', $y5_skipped && 0 === $y5_q && ! empty( $GLOBALS['p1p_policy'] ) );

// PERF-11: autoloaded settings; no provider lookup in register_assets.
if ( ! function_exists( 'wp_set_option_autoload' ) ) { function wp_set_option_autoload( $option, $autoload ) { $GLOBALS['y5_autoload'][ $option ] = $autoload; return true; } }
$GLOBALS['y5_autoload'] = array();
PassCaptain\Settings::ensure_autoload();
$y5_assets = new ReflectionMethod( PassCaptain\PublicEvents::class, 'register_assets' );
$y5_assets_src = implode( '', array_slice( file( $y5_assets->getFileName() ), $y5_assets->getStartLine() - 1, $y5_assets->getEndLine() - $y5_assets->getStartLine() + 1 ) );
check( 'PERF-11: wpcap_settings is switched to autoload on upgrade', true === ( $GLOBALS['y5_autoload']['wpcap_settings'] ?? null ) );
check( 'PERF-11: register_assets no longer queries the payment providers (dead code removed)', false === strpos( $y5_assets_src, 'Registry::enabled' ) );

// PERF-13: scanner areas only on pages with the scanner shortcode.
$y5_flag = new ReflectionProperty( PassCaptain\PublicEvents::class, 'scanner_rendered' );
$y5_flag->setAccessible( true );
$y5_flag->setValue( null, false );
$y5_before = $wpdb->num_queries;
PassCaptain\Admission\Rest::localize_scanner();
check( 'PERF-13: no admission area query on pages without the scanner', $y5_before === $wpdb->num_queries );

// PERF-17 / INT-7: deactivation removes every scheduled hook.
$y5_hooks = PassCaptain\Plugin::cron_hooks();
check( 'PERF-17: deactivate() also unschedules wpcap_webhooks_dispatch and wpcap_process_fiscal_order', in_array( 'wpcap_webhooks_dispatch', $y5_hooks, true ) && in_array( 'wpcap_process_fiscal_order', $y5_hooks, true ) && in_array( 'wpcap_process_jobs', $y5_hooks, true ) );
