<?php
/**
 * SET-PC-P2 (a): checkout from start to end with changed settings at their edges – 0, empty, maximum and
 * contradictory values – for holds, quantity limits, buyer limits, service fee, number ranges and the
 * minimum age. Every check goes through the real checkout (Orders::create() or the public REST checkout).
 *
 * Regression (p16, 25.11.2026): the quantity selectors of the event card, the live state and the
 * availability snapshot offered the ticket type's max_per_order even when the setting max_line_quantity
 * was lower; the checkout then refused the order with "Eine Ticketposition ist ungültig".
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\AdmissionChecks;
use PassCaptain\DocumentNumbers;
use PassCaptain\Frontend\Availability;
use PassCaptain\Frontend\EventCard;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Rest\Controller;
use PassCaptain\Sales\BuyerLimits;
use PassCaptain\ServiceFee;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;

echo "SET-PC-P2 (a): checkout with settings at their edges\n";
global $wpdb;
$p = $wpdb->prefix;
$pl_option = get_option( Settings::OPTION, array() );
$pl_number_options = array( DocumentNumbers::SKIPS_OPTION => get_option( DocumentNumbers::SKIPS_OPTION, null ), DocumentNumbers::PATTERNS_OPTION => get_option( DocumentNumbers::PATTERNS_OPTION, null ) );
$pl_remote = $_SERVER['REMOTE_ADDR'] ?? null;
// Neutral start: no fee, no age check, no billing threshold, default limits (earlier modules may have changed them).
Settings::update( array( 'service_fee_ticket_minor' => 0, 'service_fee_order_minor' => 0, 'min_age_checkbox' => 0, 'default_min_age' => 0, 'billing_required_threshold_minor' => 0, 'require_terms' => 0, 'max_line_quantity' => 100, 'hold_minutes' => 10, 'manual_hold_hours' => 72, 'manual_cutoff_hours' => 0, 'manual_hold_max_percent' => 50, 'manual_hold_max_event_tickets' => 0, 'manual_open_orders_per_email' => 2, 'enabled_providers' => array( 'manual' ), 'bank_iban' => 'AT611904300234573201', 'bank_recipient' => 'Testverein' ) );
Settings::flush();

$pl_n = 0;
function pl_order( array $items, array $extra = array(), array $internal = array() ) {
	global $pl_n;
	$GLOBALS['wpcap_test_transients'] = array();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( ++$pl_n % 250 + 1 );
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	$email = $extra['email'] ?? 'pl-' . uniqid() . '@example.com';
	unset( $extra['email'] );
	return Orders::create( array( 'email' => $email, 'items' => $lines, 'idempotency_key' => 'pl-' . uniqid() . '-' . wp_generate_uuid4() ) + $extra, $internal );
}
function pl_rest( array $items, $email, array $extra = array() ) {
	global $pl_n;
	$GLOBALS['wpcap_test_transients'] = array();
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( ++$pl_n % 250 + 1 );
	$lines = array();
	foreach ( $items as $type => $qty ) { $lines[] = array( 'ticket_type_id' => $type, 'quantity' => $qty ); }
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( array( 'email' => $email, 'items' => $lines, 'provider' => 'manual', 'idempotency_key' => 'pl-rest-' . uniqid() . '-' . wp_generate_uuid4() ) + $extra ) );
	$res = Controller::checkout( $r );
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
}
function pl_reason( $result ) {
	if ( ! is_wp_error( $result ) ) { return 'ok'; }
	$data = $result->get_error_data();
	return is_array( $data ) && isset( $data['reason'] ) && 'checkout_failed' !== $data['reason'] ? (string) $data['reason'] : (string) $result->get_error_code();
}
function pl_hold_seconds( $order_id ) {
	global $wpdb;
	$until = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active'", (int) $order_id ) );
	return $until ? strtotime( $until . ' UTC' ) - PassCaptain\Clock::now() : 0;
}
function pl_event_of( $type_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types t INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=%d", (int) $type_id ) );
}
function pl_start_in( $type_id, $seconds ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_ticket_types t ON t.occurrence_id=o.occurrence_id SET o.starts_at=%s WHERE t.ticket_type_id=%d", gmdate( 'Y-m-d H:i:s', PassCaptain\Clock::now() + (int) $seconds ), (int) $type_id ) );
}
$pl_set = static function ( array $values ) {
	Settings::update( $values );
	Settings::flush();
};
$pl_raw = static function ( array $values ) {
	// Values that never pass the settings screen (old installs, direct option writes): the code must stay safe.
	$raw = get_option( Settings::OPTION, array() );
	update_option( Settings::OPTION, array_merge( is_array( $raw ) ? $raw : array(), $values ) );
	Settings::flush();
};

// ---------------------------------------------------------------------------------------------------
// 1. Hold of the checkout (hold_minutes 1..120).
// ---------------------------------------------------------------------------------------------------
check( 'P2a hold: the register refuses 0, empty, 121 and accepts 1 and 120', is_wp_error( SettingsSchema::validate( 'hold_minutes', 0 ) ) && is_wp_error( SettingsSchema::validate( 'hold_minutes', '' ) ) && is_wp_error( SettingsSchema::validate( 'hold_minutes', 121 ) ) && 1 === SettingsSchema::validate( 'hold_minutes', '1' ) && 120 === SettingsSchema::validate( 'hold_minutes', 120 ) );
$pl_type = make_type( 100, 10, 2500 );
$pl_set( array( 'hold_minutes' => 1 ) );
$pl_o = pl_order( array( $pl_type => 1 ) );
$pl_s = is_wp_error( $pl_o ) ? 0 : pl_hold_seconds( $pl_o['order_id'] );
check( 'P2a hold: minimum 1 minute holds the tickets for one minute', ! is_wp_error( $pl_o ) && 1 === (int) $pl_o['hold_minutes'] && $pl_s > 0 && $pl_s <= 60 );
$pl_set( array( 'hold_minutes' => 120 ) );
$pl_o = pl_order( array( $pl_type => 1 ) );
$pl_s = is_wp_error( $pl_o ) ? 0 : pl_hold_seconds( $pl_o['order_id'] );
check( 'P2a hold: maximum 120 minutes holds the tickets for two hours', ! is_wp_error( $pl_o ) && 120 === (int) $pl_o['hold_minutes'] && $pl_s > 7000 && $pl_s <= 7200 );
foreach ( array( 0, '', 'abc', -5 ) as $pl_bad ) {
	$pl_raw( array( 'hold_minutes' => $pl_bad ) );
	$pl_o = pl_order( array( $pl_type => 1 ) );
	$pl_s = is_wp_error( $pl_o ) ? 0 : pl_hold_seconds( $pl_o['order_id'] );
	check( 'P2a hold: a stored value ' . var_export( $pl_bad, true ) . ' never creates an expired or endless hold', ! is_wp_error( $pl_o ) && (int) $pl_o['hold_minutes'] >= 1 && $pl_s > 0 && $pl_s <= max( 1, abs( (int) $pl_bad ) ) * 60 );
}
$pl_set( array( 'hold_minutes' => 10 ) );
$pl_free = make_type( 100, 10, 0 );
$pl_o = pl_order( array( $pl_free => 2 ) );
check( 'P2a hold: a free order is complete at once and keeps no hold', ! is_wp_error( $pl_o ) && 'complete' === $pl_o['status'] && 0 === (int) $pl_o['hold_minutes'] );

// Bank transfer holds: manual_hold_hours 1..720, manual_cutoff_hours 0..720.
$pl_mtype = make_type( 1000, 10, 2500 );
pl_start_in( $pl_mtype, 7 * DAY_IN_SECONDS );
$pl_set( array( 'manual_hold_hours' => 720 ) );
$pl_s = Orders::manual_hold_minutes( array( $pl_mtype ) );
check( 'P2a bank transfer: maximum hold (720 h) ends with the start of the date (7 days)', null !== $pl_s && $pl_s <= 7 * 24 * 60 && $pl_s > 7 * 24 * 60 - 5 );
$pl_set( array( 'manual_hold_hours' => 1 ) );
check( 'P2a bank transfer: minimum hold is one hour', 60 === Orders::manual_hold_minutes( array( $pl_mtype ) ) );
$pl_raw( array( 'manual_hold_hours' => 0 ) );
check( 'P2a bank transfer: a stored 0 still holds for one hour (never an expired hold)', 60 === Orders::manual_hold_minutes( array( $pl_mtype ) ) );
$pl_set( array( 'manual_hold_hours' => 72, 'manual_cutoff_hours' => 720 ) );
$pl_r = pl_rest( array( $pl_mtype => 1 ), 'pl-cutoff@example.com' );
check( 'P2a bank transfer: contradictory cutoff (720 h before a date in 7 days) refuses bank transfer with a clear error', is_wp_error( $pl_r ) && 'wpcap_manual_too_late' === $pl_r->get_error_code() && 'provider' === ( $pl_r->get_error_data()['field'] ?? '' ) );
$pl_set( array( 'manual_cutoff_hours' => 0 ) );
pl_start_in( $pl_mtype, 30 * MINUTE_IN_SECONDS );
$pl_r = pl_rest( array( $pl_mtype => 1 ), 'pl-soon@example.com' );
check( 'P2a bank transfer: cutoff 0 sells until the start and the hold ends before it', ! is_wp_error( $pl_r ) && (int) ( $pl_r['hold_minutes'] ?? 0 ) > 0 && (int) $pl_r['hold_minutes'] <= 30 );
pl_start_in( $pl_mtype, 7 * DAY_IN_SECONDS );

// Open bank transfer orders per e-mail (1..50).
$pl_set( array( 'manual_open_orders_per_email' => 1 ) );
$pl_r1 = pl_rest( array( $pl_mtype => 1 ), 'pl-open@example.com' );
$pl_r2 = pl_rest( array( $pl_mtype => 1 ), 'pl-open@example.com' );
$pl_r3 = pl_rest( array( $pl_mtype => 1 ), 'pl-open-other@example.com' );
check( 'P2a bank transfer: with 1 open order per e-mail the second order of the address is refused, another address buys', ! is_wp_error( $pl_r1 ) && is_wp_error( $pl_r2 ) && 'wpcap_manual_open_orders' === $pl_r2->get_error_code() && ! is_wp_error( $pl_r3 ) );
$pl_raw( array( 'manual_open_orders_per_email' => 0 ) );
check( 'P2a bank transfer: a stored 0 open orders still allows one (clamped to 1..50)', 1 === Controller::manual_open_orders_limit() );
$pl_raw( array( 'manual_open_orders_per_email' => 9999 ) );
check( 'P2a bank transfer: a stored 9999 is clamped to 50', 50 === Controller::manual_open_orders_limit() );
$pl_set( array( 'manual_open_orders_per_email' => 50 ) );

// Share of unpaid bank transfers (0..100 %) and absolute bound per event (0..1,000,000).
$pl_htype = make_type( 100, 100, 2500 );
$pl_manual = array( 'payment_provider' => 'manual' );
$pl_set( array( 'manual_hold_max_percent' => 1 ) );
$pl_a = pl_order( array( $pl_htype => 10 ), array(), $pl_manual );
$pl_b = pl_order( array( $pl_htype => 1 ), array(), $pl_manual );
check( 'P2a bank transfer: 1 % of 100 still allows the floor of 10 unpaid tickets, the 11th is refused', ! is_wp_error( $pl_a ) && 'manual_hold_limit' === pl_reason( $pl_b ) );
$pl_set( array( 'manual_hold_max_percent' => 100 ) );
$pl_b = pl_order( array( $pl_htype => 50 ), array(), $pl_manual );
check( 'P2a bank transfer: 100 % means no bound (like 0)', ! is_wp_error( $pl_b ) && null === Orders::manual_hold_cap( 100 ) );
$pl_set( array( 'manual_hold_max_percent' => 0 ) );
check( 'P2a bank transfer: 0 % means no bound', null === Orders::manual_hold_cap( 100 ) );
$pl_etype = make_type( 0, 10, 2500 );
$pl_set( array( 'manual_hold_max_event_tickets' => 1 ) );
$pl_a = pl_order( array( $pl_etype => 1 ), array(), $pl_manual );
$pl_b = pl_order( array( $pl_etype => 1 ), array(), $pl_manual );
$pl_c = pl_order( array( $pl_etype => 1 ), array(), array( 'payment_provider' => 'stripe' ) );
check( 'P2a bank transfer: an event bound of 1 refuses the second unpaid transfer, other payment methods still sell', ! is_wp_error( $pl_a ) && 'manual_event_hold_limit' === pl_reason( $pl_b ) && ! is_wp_error( $pl_c ) );
$pl_set( array( 'manual_hold_max_event_tickets' => 1000000 ) );
$pl_b = pl_order( array( $pl_etype => 10 ), array(), $pl_manual );
check( 'P2a bank transfer: the maximum event bound sells normally', ! is_wp_error( $pl_b ) );
$pl_set( array( 'manual_hold_max_event_tickets' => 0, 'manual_hold_max_percent' => 50 ) );

// ---------------------------------------------------------------------------------------------------
// 2. Quantity limits: max_line_quantity (1..1000) against the ticket type's max_per_order (1..100).
// ---------------------------------------------------------------------------------------------------
check( 'P2a quantity: the register refuses 0, empty and 1001', is_wp_error( SettingsSchema::validate( 'max_line_quantity', 0 ) ) && is_wp_error( SettingsSchema::validate( 'max_line_quantity', '' ) ) && is_wp_error( SettingsSchema::validate( 'max_line_quantity', 1001 ) ) );
$pl_qtype = make_type( 0, 100, 1000 );
$pl_set( array( 'max_line_quantity' => 1 ) );
$pl_a = pl_order( array( $pl_qtype => 1 ) );
$pl_b = pl_order( array( $pl_qtype => 2 ) );
$pl_c = Orders::create( array( 'email' => 'pl-split@example.com', 'items' => array( array( 'ticket_type_id' => $pl_qtype, 'quantity' => 1 ), array( 'ticket_type_id' => $pl_qtype, 'quantity' => 1 ) ), 'idempotency_key' => 'pl-split-' . wp_generate_uuid4() ) );
check( 'P2a quantity: line cap 1 sells one ticket and refuses two, also when split over two lines of the same type', ! is_wp_error( $pl_a ) && 'wpcap_invalid_item' === pl_reason( $pl_b ) && 'wpcap_invalid_item' === pl_reason( $pl_c ) );
$pl_set( array( 'max_line_quantity' => 1000 ) );
$pl_ten = make_type( 0, 10, 1000 );
$pl_a = pl_order( array( $pl_qtype => 100 ) );
$pl_b = pl_order( array( $pl_ten => 11 ) );
check( 'P2a quantity: line cap 1000 lets the ticket type limit decide (100 sold, 11 > 10 refused)', ! is_wp_error( $pl_a ) && 'quantity_limit' === pl_reason( $pl_b ) );
$pl_raw( array( 'max_line_quantity' => 0 ) );
check( 'P2a quantity: a stored 0 is clamped to 1', 1 === Orders::max_line_quantity() );
$pl_raw( array( 'max_line_quantity' => 5000 ) );
check( 'P2a quantity: a stored 5000 is clamped to 1000', 1000 === Orders::max_line_quantity() );
// Contradictory: line cap 3 below max_per_order 10 – the selectors must not offer more than the checkout takes.
$pl_set( array( 'max_line_quantity' => 3 ) );
$pl_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $pl_ten ) );
$pl_row->available = null;
$pl_card_unlimited = EventCard::quantity_max( $pl_row );
$pl_row->available = 2;
$pl_card_few = EventCard::quantity_max( $pl_row );
Orders::flush_availability_cache();
$pl_snap = Availability::snapshot( array( $pl_ten ) );
$pl_b = pl_order( array( $pl_ten => 4 ) );
$pl_a = pl_order( array( $pl_ten => 3 ) );
check( 'P2a quantity (regression p16): the event card offers at most the line cap (3 of max 10), seats left still lower it', 3 === $pl_card_unlimited && 2 === $pl_card_few );
check( 'P2a quantity (regression p16): the live availability snapshot offers at most the line cap', 3 === (int) ( $pl_snap[ $pl_ten ]['max'] ?? -1 ) && 'available' === ( $pl_snap[ $pl_ten ]['status'] ?? '' ) );
check( 'P2a quantity: the checkout takes exactly what the selector offers (3 ok, 4 refused)', ! is_wp_error( $pl_a ) && 'wpcap_invalid_item' === pl_reason( $pl_b ) );
$pl_set( array( 'max_line_quantity' => 100 ) );
check( 'P2a quantity: with the default line cap the card offers the ticket type limit again', 10 === EventCard::quantity_max( (object) array( 'max_per_order' => 10, 'available' => null ) ) );

// ---------------------------------------------------------------------------------------------------
// 3. Buyer limits (1..10000 per person) against max_per_order.
// ---------------------------------------------------------------------------------------------------
$pl_btype = make_type( 0, 10, 1000 );
$pl_bevent = pl_event_of( $pl_btype );
check( 'P2a buyer limit: 0 and 10001 are refused when the limit is created', is_wp_error( BuyerLimits::create( $pl_bevent, array( 'max_quantity' => 0, 'match_by' => 'email' ) ) ) && is_wp_error( BuyerLimits::create( $pl_bevent, array( 'max_quantity' => 10001, 'match_by' => 'email' ) ) ) && is_wp_error( BuyerLimits::create( $pl_bevent, array( 'max_quantity' => '', 'match_by' => 'email' ) ) ) );
$pl_limit = BuyerLimits::create( $pl_bevent, array( 'max_quantity' => 1, 'match_by' => 'email' ) );
$pl_a = pl_order( array( $pl_btype => 2 ), array( 'email' => 'pl-limit@example.com' ) );
$pl_b = pl_order( array( $pl_btype => 1 ), array( 'email' => 'pl-limit@example.com' ) );
$pl_c = pl_order( array( $pl_btype => 1 ), array( 'email' => 'PL-Limit@example.com' ) );
$pl_d = pl_order( array( $pl_btype => 1 ), array( 'email' => 'pl-limit-other@example.com' ) );
check( 'P2a buyer limit: limit 1 refuses 2 tickets in one order (with a message naming the limit)', is_int( $pl_limit ) && is_wp_error( $pl_a ) && false !== strpos( $pl_a->get_error_message(), 'höchstens 1' ) );
check( 'P2a buyer limit: limit 1 sells one ticket; the held ticket counts for a second order of the same address (any case)', ! is_wp_error( $pl_b ) && is_wp_error( $pl_c ) && false !== strpos( $pl_c->get_error_message(), 'bereits erreicht' ) );
check( 'P2a buyer limit: another address is not affected', ! is_wp_error( $pl_d ) );
expire_holds_of( $pl_b['order_id'] );
$pl_c = pl_order( array( $pl_btype => 1 ), array( 'email' => 'pl-limit@example.com' ) );
check( 'P2a buyer limit: an expired hold no longer counts', ! is_wp_error( $pl_c ) );
$wpdb->update( $p . 'wpcap_buyer_limits', array( 'status' => 'deleted' ), array( 'limit_id' => $pl_limit ) );
$pl_limit = BuyerLimits::create( $pl_bevent, array( 'max_quantity' => 10000, 'match_by' => 'both' ) );
$pl_a = pl_order( array( $pl_btype => 10 ), array( 'email' => 'pl-limit-max@example.com' ) );
$pl_b = pl_order( array( $pl_btype => 11 ), array( 'email' => 'pl-limit-max@example.com' ) );
check( 'P2a buyer limit: maximum limit 10000 leaves max_per_order in charge (10 ok, 11 refused as quantity limit)', is_int( $pl_limit ) && ! is_wp_error( $pl_a ) && 'quantity_limit' === pl_reason( $pl_b ) );
// Contradictory: a type limit of 1 inside an event limit of 3.
$wpdb->update( $p . 'wpcap_buyer_limits', array( 'status' => 'deleted' ), array( 'limit_id' => $pl_limit ) );
$pl_btype2 = make_type( 0, 10, 1000 );
$wpdb->update( $p . 'wpcap_ticket_types', array( 'occurrence_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $pl_btype ) ) ), array( 'ticket_type_id' => $pl_btype2 ) );
$pl_l1 = BuyerLimits::create( $pl_bevent, array( 'max_quantity' => 3, 'match_by' => 'email' ) );
$pl_l2 = BuyerLimits::create( $pl_bevent, array( 'max_quantity' => 1, 'ticket_type_id' => $pl_btype2, 'match_by' => 'email' ) );
$pl_a = pl_order( array( $pl_btype => 2, $pl_btype2 => 2 ), array( 'email' => 'pl-nested@example.com' ) );
$pl_b = pl_order( array( $pl_btype => 2, $pl_btype2 => 1 ), array( 'email' => 'pl-nested@example.com' ) );
$pl_c = pl_order( array( $pl_btype => 1 ), array( 'email' => 'pl-nested@example.com' ) );
check( 'P2a buyer limit: nested limits – the stricter one wins in each scope (2+2 refused, 2+1 sold, then the event limit of 3 is full)', is_int( $pl_l1 ) && is_int( $pl_l2 ) && is_wp_error( $pl_a ) && ! is_wp_error( $pl_b ) && is_wp_error( $pl_c ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_buyer_limits SET status='deleted' WHERE event_id=%d", $pl_bevent ) );

// ---------------------------------------------------------------------------------------------------
// 4. Service fee: 0, empty, very high, per type 0, free orders, billing threshold.
// ---------------------------------------------------------------------------------------------------
check( 'P2a fee: empty form input means 0, negative input is refused', 0 === SettingsSchema::validate( 'service_fee_ticket_minor', '' ) && 0 === SettingsSchema::validate( 'service_fee_order_minor', '  ' ) && is_wp_error( SettingsSchema::validate( 'service_fee_ticket_minor', '-1' ) ) && is_wp_error( SettingsSchema::validate( 'service_fee_order_minor', -5 ) ) );
if ( ServiceFee::ready() ) {
	$pl_ftype = make_type( 0, 10, 2500 );
	$pl_set( array( 'service_fee_ticket_minor' => 0, 'service_fee_order_minor' => 0 ) );
	$pl_a = pl_order( array( $pl_ftype => 2 ) );
	check( 'P2a fee: 0 and 0 = no fee line, plain total', ! is_wp_error( $pl_a ) && 5000 === (int) $pl_a['total_minor'] && 0 === (int) $pl_a['service_fee_minor'] );
	$pl_set( array( 'service_fee_ticket_minor' => 150, 'service_fee_order_minor' => 200 ) );
	$pl_zero = make_type( 0, 10, 2500 );
	$wpdb->update( $p . 'wpcap_ticket_types', array( 'service_fee_minor' => 0 ), array( 'ticket_type_id' => $pl_zero ) );
	$pl_a = pl_order( array( $pl_zero => 2 ) );
	check( 'P2a fee: a ticket type with its own fee 0 carries no per-ticket fee, the per-order fee stays', ! is_wp_error( $pl_a ) && 5200 === (int) $pl_a['total_minor'] && 200 === (int) $pl_a['service_fee_minor'] );
	$pl_a = pl_order( array( $pl_free => 3 ) );
	check( 'P2a fee: a free order carries no fee at all (not even the per-order fee)', ! is_wp_error( $pl_a ) && 0 === (int) $pl_a['total_minor'] && 'complete' === $pl_a['status'] );
	$pl_a = pl_order( array( $pl_ftype => 1, $pl_free => 1 ) );
	check( 'P2a fee: free lines in a paid order carry no per-ticket fee, the per-order fee is charged once', ! is_wp_error( $pl_a ) && 2500 + 150 + 200 === (int) $pl_a['total_minor'] );
	// Very high fee (money has no upper bound): totals stay exact in the bigint columns.
	$pl_set( array( 'service_fee_ticket_minor' => 99999999, 'service_fee_order_minor' => 99999999 ) );
	$pl_a = pl_order( array( $pl_ftype => 10 ) );
	$pl_row = is_wp_error( $pl_a ) ? null : Orders::get( (int) $pl_a['order_id'] );
	$pl_sum = is_wp_error( $pl_a ) ? -1 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(line_total_minor) FROM {$p}wpcap_order_items WHERE order_id=%d", (int) $pl_a['order_id'] ) );
	check( 'P2a fee: a very high fee (999,999.99 per ticket and per order) is stored exactly', $pl_row && 25000 + 10 * 99999999 + 99999999 === (int) $pl_row->total_minor && (int) $pl_row->total_minor === $pl_sum );
	// Contradictory: the fee lifts the order over the billing threshold.
	$pl_set( array( 'service_fee_ticket_minor' => 1000, 'service_fee_order_minor' => 0, 'billing_required_threshold_minor' => 25000 ) );
	$pl_bill = make_type( 0, 10, 12000 );
	$pl_a = pl_order( array( $pl_bill => 2 ) );
	check( 'P2a fee: 2 × 120,00 below the threshold of 250,00, but the fee lifts it to 260,00: billing data is required', 'wpcap_billing_required' === pl_reason( $pl_a ) );
	$pl_set( array( 'billing_required_threshold_minor' => 0 ) );
	$pl_a = pl_order( array( $pl_bill => 2 ) );
	check( 'P2a fee: billing threshold 0 never asks for billing data', ! is_wp_error( $pl_a ) && 26000 === (int) $pl_a['total_minor'] );
	$pl_a = pl_order( array( $pl_bill => 2 ), array(), array( 'source' => 'box_office', 'skip_rate_limit' => true ) );
	check( 'P2a fee: a trusted channel never carries the fee, whatever the setting', ! is_wp_error( $pl_a ) && 24000 === (int) $pl_a['total_minor'] );
	$pl_set( array( 'service_fee_ticket_minor' => 0, 'service_fee_order_minor' => 0 ) );
}

// ---------------------------------------------------------------------------------------------------
// 5. Number ranges: digits 1..10, start 0..999999999, pattern without year, yearly switch.
//    Reserved inside a transaction that is rolled back, so later modules keep their counters.
// ---------------------------------------------------------------------------------------------------
$pl_year = 2091;
$pl_number = static function ( array $settings ) use ( $pl_set, $pl_year ) {
	global $wpdb;
	$pl_set( $settings );
	$wpdb->query( 'START TRANSACTION' );
	// Counters of this year left by earlier modules are cleared inside the rolled-back transaction only.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE year IN (0,%d)', $wpdb->prefix . 'wpcap_invoice_sequences', $pl_year ) );
	$first = Invoices::next_number( $pl_year, true );
	$second = Invoices::next_number( $pl_year, true );
	$wpdb->query( 'ROLLBACK' );
	return array( $first, $second );
};
check( 'P2a numbers: the register refuses 0 and 11 digits, a start above 999999999 and a pattern without {number}', is_wp_error( SettingsSchema::validate( 'invoice_number_digits', 0 ) ) && is_wp_error( SettingsSchema::validate( 'invoice_number_digits', 11 ) ) && is_wp_error( SettingsSchema::validate( 'invoice_number_start', 1000000000 ) ) && is_wp_error( DocumentNumbers::sanitize_pattern( '{prefix}-{year}' ) ) && DocumentNumbers::DEFAULT_PATTERN === DocumentNumbers::sanitize_pattern( '' ) );
list( $pl_n1, $pl_n2 ) = $pl_number( array( 'invoice_prefix' => 'WPC', 'invoice_number_pattern' => '{prefix}-{year}-{number}', 'invoice_number_digits' => 1, 'invoice_number_start' => 0, 'invoice_number_yearly' => 1 ) );
check( 'P2a numbers: 1 digit and start 0 begin with 1 and run on', 'WPC-2091-1' === $pl_n1 && 'WPC-2091-2' === $pl_n2 );
list( $pl_n1, $pl_n2 ) = $pl_number( array( 'invoice_prefix' => 'ABCDEFGHIJ', 'invoice_number_pattern' => '{prefix}-{year}-INVOICE.N-{number}', 'invoice_number_digits' => 10, 'invoice_number_start' => 999999999 ) );
check( 'P2a numbers: a literal text of 12 characters is allowed, 13 are refused', '{prefix}-{year}-INVOICE.N-{number}' === DocumentNumbers::sanitize_pattern( '{prefix}-{year}-INVOICE.N-{number}' ) && is_wp_error( DocumentNumbers::sanitize_pattern( '{prefix}-{year}-INVOICE.NO-{number}' ) ) );
check( 'P2a numbers: longest prefix, longest literal text, 10 digits and the highest start fit the 40 characters and run past the start', 'ABCDEFGHIJ-2091-INVOICE.N-0999999999' === $pl_n1 && 'ABCDEFGHIJ-2091-INVOICE.N-1000000000' === $pl_n2 && strlen( (string) $pl_n2 ) <= 40 );
list( $pl_n1, $pl_n2 ) = $pl_number( array( 'invoice_prefix' => 'WPC', 'invoice_number_pattern' => '{number}', 'invoice_number_digits' => 6, 'invoice_number_start' => 0, 'invoice_number_yearly' => 1 ) );
check( 'P2a numbers: contradictory "yearly" with a pattern without year counts continuously (no repeated numbers)', false === DocumentNumbers::config( 'invoice' )['yearly'] && is_string( $pl_n1 ) && preg_match( '/^\d{6,}$/', $pl_n1 ) && (int) $pl_n2 === (int) $pl_n1 + 1 );
$pl_raw( array( 'invoice_number_digits' => 0, 'invoice_number_pattern' => '{year}', 'invoice_number_start' => -7 ) );
$pl_cfg = DocumentNumbers::config( 'invoice' );
check( 'P2a numbers: invalid stored values fall back to the defaults (pattern, 6 digits, start 0)', DocumentNumbers::DEFAULT_PATTERN === $pl_cfg['pattern'] && 6 === $pl_cfg['digits'] && 0 === $pl_cfg['start'] );
$pl_set( array( 'invoice_number_pattern' => '{prefix}-{year}-{number}', 'invoice_number_digits' => 6, 'invoice_number_start' => 0, 'invoice_number_yearly' => 1, 'invoice_prefix' => 'WPC' ) );
$pl_last = DocumentNumbers::last_issued( 'invoice' );
check( 'P2a numbers: a start number at or below the last issued number is refused, 0 is always allowed', 0 === DocumentNumbers::sanitize_start( 'invoice', 0, 5 ) && ( 0 === $pl_last || is_wp_error( DocumentNumbers::sanitize_start( 'invoice', $pl_last, 0 ) ) ) && 999999999 === DocumentNumbers::sanitize_start( 'invoice', 999999999, 0 ) );
foreach ( $pl_number_options as $pl_key => $pl_value ) {
	if ( null === $pl_value ) { delete_option( $pl_key ); } else { update_option( $pl_key, $pl_value ); }
}

// ---------------------------------------------------------------------------------------------------
// 6. Minimum age: default_min_age 0..99, min_age_checkbox on/off, mixed lines.
// ---------------------------------------------------------------------------------------------------
check( 'P2a age: the register refuses -1, 100 and empty for the default minimum age', is_wp_error( SettingsSchema::validate( 'default_min_age', -1 ) ) && is_wp_error( SettingsSchema::validate( 'default_min_age', 100 ) ) && is_wp_error( SettingsSchema::validate( 'default_min_age', '' ) ) && 99 === SettingsSchema::validate( 'default_min_age', '99' ) );
if ( AdmissionChecks::ready() ) {
	$pl_t16 = make_type( 0, 10, 1000 );
	$pl_t18 = make_type( 0, 10, 1000 );
	$pl_t99 = make_type( 0, 10, 1000 );
	$pl_t0 = make_type( 0, 10, 1000 );
	$wpdb->update( $p . 'wpcap_ticket_types', array( 'min_age' => 16 ), array( 'ticket_type_id' => $pl_t16 ) );
	$wpdb->update( $p . 'wpcap_ticket_types', array( 'min_age' => 18 ), array( 'ticket_type_id' => $pl_t18 ) );
	$wpdb->update( $p . 'wpcap_ticket_types', array( 'min_age' => 99 ), array( 'ticket_type_id' => $pl_t99 ) );
	$wpdb->update( $p . 'wpcap_ticket_types', array( 'occurrence_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $pl_t16 ) ) ), array( 'ticket_type_id' => $pl_t18 ) );
	$pl_set( array( 'min_age_checkbox' => 0 ) );
	$pl_a = pl_order( array( $pl_t18 => 1 ) );
	check( 'P2a age: checkbox off – a ticket type with minimum age sells without confirmation', ! is_wp_error( $pl_a ) );
	$pl_set( array( 'min_age_checkbox' => 1, 'min_age_notice_text' => '' ) );
	$pl_a = pl_order( array( $pl_t16 => 1, $pl_t18 => 1 ) );
	check( 'P2a age: checkbox on – mixed lines ask for the highest minimum age (18)', 'wpcap_age_confirmation_required' === pl_reason( $pl_a ) && 18 === (int) ( $pl_a->get_error_data()['min_age'] ?? 0 ) && false !== strpos( $pl_a->get_error_message(), '18' ) );
	foreach ( array( false, 0, '', 'no' ) as $pl_no ) {
		$pl_a = pl_order( array( $pl_t18 => 1 ), array( 'confirm_age' => $pl_no ) );
		if ( 'wpcap_age_confirmation_required' !== pl_reason( $pl_a ) ) { break; }
	}
	check( 'P2a age: false, 0, empty and "no" are no confirmation', 'wpcap_age_confirmation_required' === pl_reason( $pl_a ) );
	$pl_a = pl_order( array( $pl_t16 => 1, $pl_t18 => 1 ), array( 'confirm_age' => true ) );
	check( 'P2a age: with the confirmation the order goes through', ! is_wp_error( $pl_a ) );
	$pl_a = pl_order( array( $pl_t99 => 1 ) );
	check( 'P2a age: the highest minimum age (99) is asked for and named', 'wpcap_age_confirmation_required' === pl_reason( $pl_a ) && 99 === (int) ( $pl_a->get_error_data()['min_age'] ?? 0 ) );
	$pl_a = pl_order( array( $pl_t0 => 1 ) );
	check( 'P2a age: contradictory – checkbox on but no minimum age on the ticket type: no confirmation asked', ! is_wp_error( $pl_a ) );
	$pl_a = pl_order( array( $pl_t18 => 1 ), array(), array( 'comp' => true ) );
	check( 'P2a age: guest lists (trusted channel) are not asked', ! is_wp_error( $pl_a ) );
	check( 'P2a age: an empty label text uses the built-in text with the age, an own text keeps {min_age}', false !== strpos( Settings::min_age_notice( 18 ), '18' ) && 'Ab 99!' === ( static function () use ( $pl_set ) { $pl_set( array( 'min_age_notice_text' => 'Ab {min_age}!' ) ); return Settings::min_age_notice( 99 ); } )() );
	// default_min_age only pre-fills new ticket types; existing types keep their value.
	$pl_set( array( 'default_min_age' => 99, 'min_age_checkbox' => 1 ) );
	$pl_a = pl_order( array( $pl_t0 => 1 ) );
	check( 'P2a age: a default minimum age of 99 changes no existing ticket type (no confirmation for a type without age)', ! is_wp_error( $pl_a ) && 0 === AdmissionChecks::max_min_age( array( $pl_t0 ) ) );
	$pl_set( array( 'default_min_age' => 0, 'min_age_checkbox' => 0, 'min_age_notice_text' => '' ) );
}

// Restore.
update_option( Settings::OPTION, $pl_option );
Settings::flush();
if ( null === $pl_remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $pl_remote; }
$GLOBALS['wpcap_test_transients'] = array();
