<?php
/**
 * Service bookings, part G (PASS-FE-17 follow-up): the booking page's manage view (GET
 * /service-bookings/{id}, POST …/rebook with the booking token) also offers dearer slots with a
 * supplementary payment. Additive fields only: currency, supplement_options, open_supplement,
 * supplement_providers; rebook_options stays as it was (same price). The REST rebook accepts the
 * payment provider of the supplement.
 *
 * Included from run.php after zzzz_services_f_supplement.php (runs alone as well).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\Orders;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceBookingFlow;

svc_setup();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$_SERVER['REMOTE_ADDR'] = '203.0.113.79';
svc_supp_gateway();
$sg_providers_before = PassCaptain\Settings::get( 'enabled_providers' );
PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual', 'svc_supp' ) ) );

/** Booking token for a booking created by svc_paid() (only its hash is stored). */
function sg_token( $booking_id ) {
	global $wpdb;
	$token = hash( 'sha256', 'sg-token-' . $booking_id );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_bookings SET token_hash=%s WHERE booking_id=%d", hash( 'sha256', $token ), $booking_id ) );
	return $token;
}
function sg_req( $booking_id, array $body = array(), $method = 'POST' ) {
	$r = new WP_REST_Request( $method );
	$r->set_param( 'booking_id', $booking_id );
	$r->set_body( wp_json_encode( $body ) );
	return $r;
}
function sg_sorted_keys( array $a ) { $k = array_keys( $a ); sort( $k ); return $k; }
function sg_data( $res ) { return $res instanceof WP_REST_Response ? $res->get_data() : $res; }
function sg_view( $booking_id, $token ) {
	wpcap_test_reset_transients();
	$req = sg_req( $booking_id, array( 'token' => $token ), 'GET' );
	return true === ServiceBookingFlow::token_permission( $req ) ? sg_data( ServiceBookingFlow::rest_get( $req ) ) : null;
}

echo "Service bookings: booking page offers dearer slots with a supplementary payment\n";
list( $sg_event, $sg_type ) = svc_event( 3000 );
$sg_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$sg_type" );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $sg_occ, 'name' => 'Premium G', 'price_minor' => 5000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
$sg_dear_type = (int) $wpdb->insert_id;
$sg_res = svc_resource( $sg_event, array( 'ticket_type_id' => $sg_type, 'capacity' => 4, 'rules' => array( 'price_policy' => 'refund_difference' ) ) );
$sg_dear = svc_resource( $sg_event, array( 'ticket_type_id' => $sg_dear_type, 'capacity' => 4 ) );
$sg_src = svc_slot( $sg_res, 200 );
$sg_same = svc_slot( $sg_res, 205 );
$sg_dst = svc_slot( $sg_dear, 210, 60, 2 );
$sg_small = svc_slot( $sg_dear, 215, 60, 1 ); // dearer, but too small for 2 places
list( $gb, $go ) = svc_paid( $sg_src, $sg_type, 'flowsupp@svc.test', 2 );
$gtok = sg_token( $gb );
$v = sg_view( $gb, $gtok );
$sg_old_keys = array( 'booking_id', 'status', 'slot_id', 'resource_name', 'starts_at', 'ends_at', 'quantity', 'expires_at', 'rules', 'rebook_options' );
check( 'GET keeps its fields; rebook_options still lists only same-price targets (unchanged keys)', is_array( $v ) && array() === array_diff( $sg_old_keys, array_keys( $v ) ) && array( $sg_same ) === array_map( 'intval', array_column( $v['rebook_options'], 'slot_id' ) ) && array( 'available', 'ends_at', 'resource_name', 'slot_id', 'starts_at' ) === sg_sorted_keys( $v['rebook_options'][0] ) );
$sg_opt = $v['supplement_options'][0] ?? array();
check( 'supplement_options: the dearer slot with the difference for both places (2 × 20 €) in the booking currency; too small slots left out', 'EUR' === $v['currency'] && 1 === count( $v['supplement_options'] ) && $sg_dst === (int) ( $sg_opt['slot_id'] ?? 0 ) && 4000 === (int) ( $sg_opt['supplement_minor'] ?? 0 ) && 'EUR' === ( $sg_opt['currency'] ?? '' ) && 2 === (int) $sg_opt['available'] && null === $v['open_supplement'] );
check( 'supplement_providers lists the enabled payment providers with their titles', in_array( 'svc_supp', array_column( $v['supplement_providers'], 'id' ), true ) && '' !== (string) ( $v['supplement_providers'][0]['title'] ?? '' ) );

// No supplement without an enabled provider, with price_policy same_price and for free bookings.
PassCaptain\Settings::update( array( 'enabled_providers' => array() ) );
$v_none = sg_view( $gb, $gtok );
PassCaptain\Settings::update( array( 'enabled_providers' => array( 'manual', 'svc_supp' ) ) );
$sg_plain = svc_resource( $sg_event, array( 'ticket_type_id' => $sg_type, 'capacity' => 2 ) );
list( $pb ) = svc_paid( svc_slot( $sg_plain, 220 ), $sg_type, 'flowplain@svc.test', 1 );
$v_plain = sg_view( $pb, sg_token( $pb ) );
$sg_free_res = svc_resource( $sg_event, array( 'capacity' => 2 ) );
$sg_free = svc_book( svc_slot( $sg_free_res, 225 ), 'flowfree@svc.test', 1 );
$v_free = is_array( $sg_free ) ? sg_view( (int) $sg_free['booking_id'], (string) $sg_free['token'] ) : null;
check( 'no dearer offers without an enabled payment provider, with price_policy same_price or without a paid order', is_array( $v_none ) && array() === $v_none['supplement_options'] && array() === $v_none['supplement_providers'] && is_array( $v_plain ) && array() === $v_plain['supplement_options'] && is_array( $v_free ) && array() === $v_free['supplement_options'] );

// Rebooking to the dearer slot from the booking page.
wpcap_test_reset_transients();
$bad = ServiceBookingFlow::rest_rebook( sg_req( $pb, array( 'token' => sg_token( $pb ), 'slot_id' => $sg_dst, 'provider' => 'nope' ) ) );
check( 'REST rebook to a dearer slot is still refused where no supplement is possible', is_wp_error( $bad ) && 'wpcap_rebook_price_higher' === $bad->get_error_code() );
wpcap_test_reset_transients();
$r = sg_data( ServiceBookingFlow::rest_rebook( sg_req( $gb, array( 'token' => $gtok, 'slot_id' => $sg_dst, 'provider' => 'svc_supp' ) ) ) );
$supp = $wpdb->get_row( "SELECT * FROM {$p}wpcap_service_bookings WHERE supplement_for=$gb ORDER BY booking_id DESC LIMIT 1" );
$supp_order = $supp ? Orders::get( (int) $supp->order_id ) : null;
check( 'REST rebook with provider starts the supplementary payment (hosted page) for the chosen provider; the booking stays until paid', is_array( $r ) && 4000 === (int) ( $r['supplement']['amount_minor'] ?? 0 ) && str_starts_with( (string) ( $r['supplement']['payment']['redirect_url'] ?? '' ), 'https://pay.example.test/' ) && $supp_order && 'svc_supp' === $supp_order->payment_provider && $sg_src === (int) ServiceBookings::get( $gb )->slot_id && 'confirmed' === svc_status( $gb ) );
$v_open = sg_view( $gb, $gtok );
check( 'GET then shows the open supplement (target, amount, deadline) and offers no further dearer slots', is_array( $v_open ) && $sg_dst === (int) ( $v_open['open_supplement']['slot_id'] ?? 0 ) && 4000 === (int) $v_open['open_supplement']['amount_minor'] && 'EUR' === $v_open['open_supplement']['currency'] && (string) $supp->expires_at === $v_open['open_supplement']['expires_at'] && '' !== $v_open['open_supplement']['resource_name'] && array() === $v_open['supplement_options'] );
wpcap_test_reset_transients();
$again = sg_data( ServiceBookingFlow::rest_rebook( sg_req( $gb, array( 'token' => $gtok, 'slot_id' => $sg_dst ) ) ) );
check( 'the pay button (same target again) reuses the open supplement and restarts its payment, no second order', is_array( $again ) && ! empty( $again['reused'] ) && (int) $again['supplement']['order_id'] === (int) $supp->order_id && '' !== (string) ( $again['supplement']['payment']['redirect_url'] ?? '' ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_service_bookings WHERE supplement_for=$gb" ) );
wpcap_test_reset_transients();
$other = ServiceBookingFlow::rest_rebook( sg_req( $gb, array( 'token' => $gtok, 'slot_id' => $sg_same ) ) );
check( 'while the supplement is open another rebooking from the booking page is refused', is_wp_error( $other ) && 'wpcap_rebook_supplement_open' === $other->get_error_code() );
Orders::confirm_external_payment( (int) $supp->order_id, 'sg-pay-1' );
$v_paid = sg_view( $gb, $gtok );
check( 'after payment the booking page shows the moved booking and no open supplement', is_array( $v_paid ) && $sg_dst === (int) $v_paid['slot_id'] && null === $v_paid['open_supplement'] && 'confirmed' === $v_paid['status'] );

// Provider choice: an unknown provider is refused, nothing is held.
$sg_src2 = svc_slot( $sg_res, 230 );
$sg_dst2 = svc_slot( $sg_dear, 235, 60, 1 );
list( $ub ) = svc_paid( $sg_src2, $sg_type, 'flowprov@svc.test', 1 );
wpcap_test_reset_transients();
$unknown = ServiceBookingFlow::rest_rebook( sg_req( $ub, array( 'token' => sg_token( $ub ), 'slot_id' => $sg_dst2, 'provider' => 'nope' ) ) );
check( 'REST rebook with an unknown provider is refused and holds no place', is_wp_error( $unknown ) && 'wpcap_provider_unavailable' === $unknown->get_error_code() && 1 === svc_free( $sg_dst2 ) );

PassCaptain\Settings::update( array( 'enabled_providers' => $sg_providers_before ) );
