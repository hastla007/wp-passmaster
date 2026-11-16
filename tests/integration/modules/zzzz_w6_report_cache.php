<?php
/**
 * Report cache invalidation (W6): the event report (REST GET /events/{id}/report) is cached for five
 * minutes; orders, payments, refunds, check-ins and event changes must invalidate the affected
 * event's cached ranges immediately, and only those.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Checkin;
use PassCaptain\Refunds;
use PassCaptain\Reports\Cache;
use PassCaptain\Rest\Controller;
use PassCaptain\Admin\EventsPage;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
Cache::register();

function w6rc_report( $event_id ) {
	$r = new WP_REST_Request( 'GET' );
	$r->set_param( 'event_id', $event_id );
	$r->set_param( 'from', gmdate( 'Y-m-d', time() - 86400 ) );
	$r->set_param( 'to', gmdate( 'Y-m-d', time() + 86400 ) );
	$res = Controller::event_report( $r );
	$data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
	return array( 'tickets' => (int) ( $data['sales_by_currency'][0]['tickets_sold'] ?? 0 ), 'gross' => (int) ( $data['sales_by_currency'][0]['gross_minor'] ?? 0 ), 'checkins' => (int) ( $data['checkins'] ?? -1 ) );
}
// Merged cache (A201): the REST report is stored by Cache::remember() under generation keys.
function w6rc_cached( $event_id ) {
	Cache::flush();
	return false !== get_transient( Cache::key( 'rest_event_report', $event_id, array( 'from' => gmdate( 'Y-m-d', time() - 86400 ), 'to' => gmdate( 'Y-m-d', time() + 86400 ), 'tz' => PassCaptain\Reports\Scope::site_timezone() ) ) );
}

echo "W6 report cache: invalidation rules\n";
$type = make_type( 0, 10, 1000 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
$other_type = make_type( 0, 10, 500 );
$other_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$other_type" );

$empty = w6rc_report( $event );
w6rc_report( $other_event );
check( 'report is computed and cached per event', 0 === $empty['tickets'] && w6rc_cached( $event ) && w6rc_cached( $other_event ) );

// Merged cache: the query filter sees every write to the report tables, also one that bypasses the
// plugin's API, and invalidates only the affected event.
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'idempotency_key' => hash( 'sha256', uniqid() ), 'customer_email' => 'raw@example.com', 'access_token_hash' => hash( 'sha256', 'x' ), 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'EUR', 'total_minor' => 1000, 'subtotal_minor' => 1000, 'price_snapshot' => '{}', 'payment_provider' => 'manual', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$raw_order = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $raw_order, 'ticket_type_id' => $type, 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000 ) );
PassCaptain\CapacityCounters::rebuild_occurrence( $occ ); // Plain SQL rows: counters follow the source.
check( 'a raw write to the report tables invalidates its event only (query filter), the fresh report counts it', ! w6rc_cached( $event ) && w6rc_cached( $other_event ) && 1 === w6rc_report( $event )['tickets'] );

$GLOBALS['wpcap_test_transients'] = array_filter( $GLOBALS['wpcap_test_transients'], static fn( $k ) => 0 === strpos( $k, 'wpcap_rep_' ) || 0 === strpos( $k, 'wpcap_rgen_' ), ARRAY_FILTER_USE_KEY ); // Reset rate limits, keep report cache and its generations.
$o = Orders::create( array( 'email' => 'w6rc@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'w6rc-' . uniqid() ), array( 'skip_rate_limit' => true ) );
// Its order lines are written (watched table): the event is invalidated, the figures stay without it.
check( 'a pending order is not counted (not revenue yet); other events keep their cache', w6rc_cached( $other_event ) && 1 === w6rc_report( $event )['tickets'] && w6rc_cached( $event ) );
Orders::confirm_manual_payment( $o['order_id'] );
check( 'payment invalidates the event report; other events keep their cache', ! w6rc_cached( $event ) && w6rc_cached( $other_event ) );
$r = w6rc_report( $event );
check( 'fresh report counts the paid order (and the raw row)', 3 === $r['tickets'] && 3000 === $r['gross'] );

$code = ticket_code_for( $o['order_id'] );
Checkin::perform( $code[0], array( 'idempotency_key' => 'w6rc-ci-' . uniqid() ) );
check( 'check-in invalidates and is counted', ! w6rc_cached( $event ) && 1 === w6rc_report( $event )['checkins'] );

// A checked-in ticket is only refunded by staff with a documented exception and a second approval
// (merged refund policy, A131 used_override).
$w6rc_ref = Refunds::request( $o['order_id'], array(), 'w6', 'w6rc-ref-' . uniqid(), array( 'used_override' => 'W6 Kulanz nach Einlass' ) );
if ( is_array( $w6rc_ref ) && ! empty( $w6rc_ref['refund_id'] ) ) {
	$GLOBALS['wpcap_test_user_id'] = 2;
	Refunds::approve( (int) $w6rc_ref['refund_id'] );
	$GLOBALS['wpcap_test_user_id'] = 1;
}
check( 'refund invalidates; fully refunded order leaves the report', ! w6rc_cached( $event ) && 1 === w6rc_report( $event )['tickets'] );

$o2 = Orders::create( array( 'email' => 'w6rc2@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w6rc2-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Orders::confirm_manual_payment( $o2['order_id'] );
w6rc_report( $event );
Orders::cancel_order( $o2['order_id'], 'w6' );
check( 'cancellation of a paid order invalidates', ! w6rc_cached( $event ) );

w6rc_report( $event );
w6rc_report( $other_event ); // Refund/cancellation writes may bump the site generation (merged cache: undeterminable rows).
EventsPage::save_ticket_type( array( 'occurrence_id' => $occ, 'name' => 'Neu', 'price' => '12,00', 'currency' => 'EUR', 'capacity' => '0', 'max_per_order' => '10', 'tax_rate' => '0', 'status' => 'active' ) );
$after_type = w6rc_cached( $event );
w6rc_report( $event );
EventsPage::update_event( array( 'event_id' => $event, 'name' => 'Umbenannt', 'status' => 'published' ) );
$after_event = w6rc_cached( $event );
w6rc_report( $event );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET location=%s WHERE occurrence_id=%d", 'W6 neu', $occ ) ); // Occurrence change (watched write).
check( 'event, ticket type and occurrence changes invalidate the event report', ! $after_type && ! $after_event && ! w6rc_cached( $event ) && w6rc_cached( $other_event ) );

$o3 = Orders::create( array( 'email' => 'w6rc3@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'w6rc3-' . uniqid() ), array( 'skip_rate_limit' => true ) );
Orders::confirm_manual_payment( $o3['order_id'] );
w6rc_report( $event );
Orders::revoke_tickets( $o3['order_id'], $type, 1, 'w6' ); // Revocation of a valid ticket writes the ticket row (watched).
check( 'ticket revocation invalidates', ! w6rc_cached( $event ) );
check( 'flush without cached report writes nothing (no index)', 0 === Cache::flush_event( $event ) );
