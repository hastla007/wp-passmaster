<?php
/**
 * TODO L1355 (24.11.2026): Affiliate Captain is only meant for later aggregated quality reports;
 * review clicks and ratings never create a partner commission. Guard: the Affiliate Captain adapter
 * reports only order events (sale, refund, cancellation), the review participation events (check-in,
 * attendance confirmation, undo, no-show) never reach wpac_record_sale()/wpac_record_refund(), and the
 * review envelopes carry no partner data. Neither side references the other in code.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Integrations\AffiliateCaptain\Adapter as AffiliateAdapter;
use PassCaptain\Orders;
use PassCaptain\Review\Contract as ReviewContract;
use PassCaptain\Review\Events as ReviewEvents;
use PassCaptain\Suite\Outbox;

global $wpdb;
$p = $wpdb->prefix;
$rn_hooks_before = $GLOBALS['wpcap_test_hooks'];
$rn_filters_before = $GLOBALS['wpcap_test_filters'];
$rn_globals = array();
foreach ( array( 'wpac_test_version', 'wpac_test_sales', 'wpac_test_refunds', 'wpac_test_touch', 'wpac_test_fail', 'wpac_test_sale_fault', 'wpac_test_sale_response' ) as $rn_g ) { $rn_globals[ $rn_g ] = $GLOBALS[ $rn_g ] ?? null; }
$GLOBALS['wpac_test_version'] = '1.1.0';
$GLOBALS['wpac_test_sales'] = array();
$GLOBALS['wpac_test_refunds'] = array();
$GLOBALS['wpac_test_touch'] = null;
$GLOBALS['wpac_test_fail'] = '';
$GLOBALS['wpac_test_sale_fault'] = null;
$GLOBALS['wpac_test_sale_response'] = null;
if ( ! function_exists( 'wpac_contract_version' ) ) {
	// Same contract stubs as tests/integration/affiliate.php (that core file normally defines them).
	function wpac_contract_version() { return $GLOBALS['wpac_test_version']; }
	function wpac_record_sale( array $payload ) { $GLOBALS['wpac_test_sales'][] = $payload; return array( 'status' => 'created' ); }
	function wpac_record_refund( array $payload ) { $GLOBALS['wpac_test_refunds'][] = $payload; return array( 'status' => 'adjusted' ); }
	function wpac_resolve_partner_code( $code, array $context = array() ) { return 'NORD-1' === strtoupper( $code ) ? array( 'affiliate_id' => 7, 'code_id' => 3, 'code' => 'NORD-1' ) : null; }
	function wpac_current_touch() { return $GLOBALS['wpac_test_touch']; }
}
// Each adapter exactly once (the core file may already have registered the affiliate adapter).
$rn_registered = static function ( $hook, $callback ) {
	foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() as $cbs ) {
		foreach ( $cbs as $entry ) { if ( $entry[0] === $callback ) { return true; } }
	}
	return false;
};
if ( ! $rn_registered( 'wpcap_order_paid', array( AffiliateAdapter::class, 'on_paid' ) ) ) { AffiliateAdapter::register(); }
if ( ! $rn_registered( 'wpcap_ticket_checked_in', array( ReviewEvents::class, 'checked_in' ) ) ) { ReviewEvents::register(); }
unset( $GLOBALS['wpcap_test_filters'][ ReviewContract::FILTER ] );
$GLOBALS['wpcap_test_filters']['wpcap_affiliate_tracking_allowed'] = true;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "L1355: review participation never creates a partner commission\n";
check( 'L1355: the affiliate adapter is active for contract 1.x', AffiliateAdapter::available() );

// Static guard: which core hooks the adapter listens to, and no cross references.
$rn_root = dirname( __DIR__, 3 );
$rn_adapter = (string) file_get_contents( $rn_root . '/src/Integrations/AffiliateCaptain/Adapter.php' );
preg_match_all( "/add_(?:action|filter)\\(\\s*'([a-z0-9_]+)'/", $rn_adapter, $rn_m );
$rn_hooks = array_values( array_unique( $rn_m[1] ) );
sort( $rn_hooks );
$rn_expected = array( 'wp_enqueue_scripts', 'wpac_registered_integrations', 'wpcap_checkout_error_messages', 'wpcap_checkout_form_fields', 'wpcap_checkout_payload_keys', 'wpcap_order_cancelled', 'wpcap_order_creating', 'wpcap_order_paid', 'wpcap_order_refunded', 'wpcap_process_job', 'wpcap_refund_completed' );
check( 'L1355: the affiliate adapter listens only to checkout and order hooks (no check-in, attendance or review hook)', $rn_expected === $rn_hooks );
$rn_review_src = '';
foreach ( glob( $rn_root . '/src/Review/*.php' ) as $rn_file ) { $rn_review_src .= (string) file_get_contents( $rn_file ); }
check( 'L1355: the review code does not call Affiliate Captain', '' !== $rn_review_src && false === stripos( $rn_review_src, 'wpac_' ) && false === strpos( $rn_review_src, 'AffiliateCaptain\\' ) && false === stripos( $rn_adapter, 'Review\\' ) );

// Runtime: a referred, paid order; then the whole participation cycle.
$rn_type = make_type( 10, 10, 3570 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$rn_type" );
$rn_o = Orders::create( array( 'email' => 'rn-buyer@example.com', 'partner_code' => 'NORD-1', 'items' => array( array( 'ticket_type_id' => $rn_type, 'quantity' => 2 ) ), 'idempotency_key' => 'rn-' . uniqid() ) );
Orders::confirm_manual_payment( $rn_o['order_id'] );
$rn_sales = count( $GLOBALS['wpac_test_sales'] );
$rn_refunds = count( $GLOBALS['wpac_test_refunds'] );
check( 'L1355: the paid order is reported once as a sale (the only commission basis)', 1 === $rn_sales );

$rn_codes = ticket_code_for( $rn_o['order_id'] );
$rn_tickets = tickets( $rn_o['order_id'] );
$rn_pub = array_map( static fn( $t ) => (string) $t->public_id, $rn_tickets );
$rn_by_code = array();
foreach ( $rn_codes as $rn_c ) { $rn_by_code[ (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $rn_c ) ) ) ] = $rn_c; }
$rn_ci = Checkin::perform( $rn_by_code[ $rn_pub[0] ], array( 'idempotency_key' => 'rn-ci-' . uniqid(), 'source' => 'app' ) );
PassCaptain\Admin\TicketsPage::undo_checkin( array( 'ticket_id' => (int) $rn_tickets[0]->ticket_id, 'reason' => 'Fehlscan' ) );
Checkin::perform( $rn_by_code[ $rn_pub[0] ], array( 'idempotency_key' => 'rn-ci2-' . uniqid(), 'source' => 'app' ) );
do_action( 'wpcap_attendance_confirmed', (int) $rn_tickets[1]->ticket_id, array( 'method' => 'manual', 'source' => 'manual' ) );
$rn_t = Outbox::table();
$rn_rows = (array) $wpdb->get_results( "SELECT event_type,payload FROM $rn_t WHERE contract='review' AND subject_id IN ('" . implode( "','", array_map( static fn( $x ) => preg_replace( '/[^a-f0-9]/', '', $x ), $rn_pub ) ) . "') ORDER BY outbox_id" );
$rn_types = array_map( static fn( $r ) => (string) $r->event_type, $rn_rows );
check( 'L1355: check-in, undo and attendance confirmation produced review events', ! is_wp_error( $rn_ci ) && 2 <= count( array_keys( $rn_types, 'experience_completed', true ) ) && in_array( 'experience_excluded', $rn_types, true ) );
check( 'L1355: none of them reached Affiliate Captain (no further sale, no refund)', $rn_sales === count( $GLOBALS['wpac_test_sales'] ) && $rn_refunds === count( $GLOBALS['wpac_test_refunds'] ) );
$rn_json = strtolower( implode( "\n", array_map( static fn( $r ) => (string) $r->payload, $rn_rows ) ) );
check( 'L1355: review envelopes carry no partner code, referral or commission data', '' !== $rn_json && false === strpos( $rn_json, 'nord-1' ) && false === strpos( $rn_json, 'partner' ) && false === strpos( $rn_json, 'affiliate' ) && false === strpos( $rn_json, 'commission' ) && false === strpos( $rn_json, 'referral' ) );
$rn_snap = json_decode( (string) $wpdb->get_var( "SELECT price_snapshot FROM {$p}wpcap_orders WHERE order_id=" . (int) $rn_o['order_id'] ), true );
check( 'L1355: the order keeps its referral (commission stays tied to the sale)', 'NORD-1' === ( $rn_snap['affiliate_referral']['code'] ?? '' ) );

$GLOBALS['wpcap_test_hooks'] = $rn_hooks_before;
$GLOBALS['wpcap_test_filters'] = $rn_filters_before;
foreach ( $rn_globals as $rn_g => $rn_v ) { $GLOBALS[ $rn_g ] = $rn_v; }
