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
PassCaptain\Settings::update( array( 'mollie_api_key' => 'test_' . str_repeat( 'Yz5', 10 ), 'mollie' => array( 'methods' => array( 'ideal', 'klarna', 'creditcard' ), 'billing_country' => 'AT' ) ) );
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_transient_ttl'] = array();
http_reset();
http_push( array( '_embedded' => array( 'methods' => array(
	array( 'id' => 'ideal', 'description' => 'iDEAL', 'minimumAmount' => array( 'value' => '0.01', 'currency' => 'EUR' ), 'maximumAmount' => array( 'value' => '50000.00', 'currency' => 'EUR' ) ),
	array( 'id' => 'klarna', 'description' => 'Klarna', 'minimumAmount' => array( 'value' => '35.00', 'currency' => 'EUR' ), 'maximumAmount' => array( 'value' => '1000.00', 'currency' => 'EUR' ) ),
	array( 'id' => 'creditcard', 'description' => 'Karte' ),
) ) ), 200, '/v2/methods' );
$y5_m1 = PassCaptain\Mollie\MollieGateway::selectable_methods( 2500, 'EUR' );
$y5_m2 = PassCaptain\Mollie\MollieGateway::selectable_methods( 4000, 'EUR' );
$y5_m3 = PassCaptain\Mollie\MollieGateway::selectable_methods( 150000, 'EUR' );
$y5_mreq = http_requests( '/v2/methods' );
parse_str( (string) parse_url( $y5_mreq[0]['url'] ?? '', PHP_URL_QUERY ), $y5_mq );
check( 'three different amounts need one API call (cache per currency/country)', 1 === count( $y5_mreq ) && ! isset( $y5_mq['amount'] ) && 'AT' === ( $y5_mq['billingCountry'] ?? '' ) );
check( 'limits are applied locally (Klarna from 35 €, up to 1,000 €; no limits = allowed)', array( 'ideal', 'creditcard' ) === array_keys( $y5_m1 ) && array( 'ideal', 'klarna', 'creditcard' ) === array_keys( $y5_m2 ) && array( 'ideal', 'creditcard' ) === array_keys( $y5_m3 ) );
check( 'the methods lookup uses a timeout of at most 3 seconds', ( $y5_mreq[0]['timeout'] ?? 99 ) <= 3 );
http_reset();
$GLOBALS['wpcap_test_transients'] = array();
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
$GLOBALS['wpcap_test_transients'] = array();

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
PassCaptain\OccurrenceCancellation::$inline_seconds = 0; // Simulates a request that has no time left: everything stays queued.
$y5c_mails = count( $GLOBALS['wpcap_test_mails'] );
$y5c_before = $wpdb->num_queries;
$y5c_sum = PassCaptain\OccurrenceCancellation::handle( $y5c_occ );
$y5c_q = $wpdb->num_queries - $y5c_before;
PassCaptain\OccurrenceCancellation::$inline_seconds = 20;
$y5c_valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($y5c_in) AND status='valid'" );
check( "50 tickets revoked with bulk UPDATEs before any refund ($y5c_q queries incl. the open order)", 50 === $y5c_sum['revoked'] && 0 === $y5c_valid && $y5c_q <= 60 );
check( 'no refund, mail or hook ran yet; batches are queued (hooks, 2 refund batches, mails)', 0 === $y5c_sum['refunds'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id IN ($y5c_in)" ) && $y5c_mails === count( $GLOBALS['wpcap_test_mails'] ) && array() === $y5c_hook_states && 4 === $y5c_sum['queued_jobs'] );
check( 'open order of the occurrence is cancelled inline', 'cancelled' === Orders::get( $y5c_open['order_id'] )->payment_status && 1 === $y5c_sum['cancelled_orders'] );
$y5c_progress = PassCaptain\OccurrenceCancellation::progress( $y5c_occ );
check( 'admin progress: 4 batches queued', 4 === $y5c_progress['queued'] && 0 === $y5c_progress['complete'] );
check( 'invariant after the abort: no valid ticket with refunded money', $y5c_invariant() );
$y5c_run = $y5c_sum['run'];
// Worker picks up the hooks and the first refund batch, then "crashes".
PassCaptain\Jobs::run_now( array( "occ-cancel-$y5c_occ-$y5c_run-hooks-0", "occ-cancel-$y5c_occ-$y5c_run-refunds-0" ), time() + 60 );
$y5c_refunded_orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT order_id) FROM {$p}wpcap_refunds WHERE status='completed' AND order_id IN ($y5c_in)" );
check( 'hooks fire per ticket only after the revoke was persisted (50 x status revoked: ' . count( $y5c_hook_states ) . ' ' . implode( ',', array_unique( $y5c_hook_states ) ) . ')', 50 === count( $y5c_hook_states ) && array( 'revoked' ) === array_values( array_unique( $y5c_hook_states ) ) );
check( "first refund batch: 20 orders refunded ($y5c_refunded_orders), invariant holds mid-way", 20 === $y5c_refunded_orders && $y5c_invariant() );
PassCaptain\Jobs::run_now( array( "occ-cancel-$y5c_occ-$y5c_run-refunds-1", "occ-cancel-$y5c_occ-$y5c_run-notify-0" ), time() + 60 );
$y5c_refunded_orders = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT order_id) FROM {$p}wpcap_refunds WHERE status='completed' AND order_id IN ($y5c_in)" );
$y5c_to = array_unique( array_map( static fn( $m ) => $m['to'], array_slice( $GLOBALS['wpcap_test_mails'], $y5c_mails ) ) );
$y5c_flagged = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($y5c_in) AND status='revoked' AND refunded=1" );
check( 'remaining batches: all 25 orders refunded, all 50 tickets revoked and flagged refunded', 25 === $y5c_refunded_orders && 50 === $y5c_flagged );
check( 'holders informed by the mail batch (25 recipients)', count( array_filter( $y5c_to, static fn( $t ) => str_starts_with( $t, 'y5c' ) && 'y5c-open@example.com' !== $t ) ) >= 25 );
check( 'progress: all batches complete', 4 === PassCaptain\OccurrenceCancellation::progress( $y5c_occ )['complete'] );
remove_action( 'wpcap_ticket_revoked', $y5c_hook, 1 );
// Orders::revoke_ids(): one statement per chunk, only still valid tickets reported.
$y5c_type2 = make_type( 50, 10, 0 );
$y5c_free = Orders::create( array( 'email' => 'y5c-free@example.com', 'items' => array( array( 'ticket_type_id' => $y5c_type2, 'quantity' => 6 ) ), 'idempotency_key' => 'y5c-free-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$y5c_ids = array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $y5c_free['order_id'] ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=" . $y5c_ids[0] );
$y5c_before = $wpdb->num_queries;
$y5c_changed = Orders::revoke_ids( array_merge( $y5c_ids, array( $y5c_ids[1], 0 ) ) );
$y5c_q = $wpdb->num_queries - $y5c_before;
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
